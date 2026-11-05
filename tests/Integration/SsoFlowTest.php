<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\AuthorizeEndpoint;
use PV\WpmsSso\ClientFlow;
use PV\WpmsSso\JtiTable;
use PV\WpmsSso\KeyStore;
use PV\WpmsSso\SessionEndpoint;
use PV\WpmsSso\Settings;
use PV\WpmsSso\StateStore;
use PV\WpmsSso\Ticket;
use PV\WpmsSso\TicketSigner;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration flow tests against the multisite test install.
 *
 * Test installs are subdirectory multisites: every blog shares the host
 * "localhost", so host checks pass and blog paths differentiate sites.
 */
final class SsoFlowTest extends WP_UnitTestCase {

	private int $superAdminUserId;
	private int $plainUserId;
	private int $clientBlogId;

	public function setUp(): void {
		parent::setUp();

		$this->superAdminUserId = self::factory()->user->create( [ 'user_login' => 'sso_super_admin' ] );
		grant_super_admin( $this->superAdminUserId );
		$this->plainUserId = self::factory()->user->create( [ 'user_login' => 'sso_plain_user' ] );

		$this->clientBlogId = self::factory()->blog->create( [ 'path' => '/client/' ] );
		add_user_to_blog( $this->clientBlogId, $this->superAdminUserId, 'administrator' );

		Settings::save(
			get_current_network_id(),
			[
				'enabled'     => true,
				'idp_blog_id' => 0, // root site of the network = the IdP.
				'scope'       => Settings::SCOPE_SUPER_ADMINS,
			]
		);
	}

	public function tearDown(): void {
		revoke_super_admin( $this->superAdminUserId );
		wp_delete_site( $this->clientBlogId );
		parent::tearDown();
	}

	private function becomeIdpWithUser( int $userId ): void {
		switch_to_blog( get_main_site_id( get_current_network_id() ) );
		wp_set_current_user( $userId );
	}

	public function testAuthorizeMintsTicketForSuperAdmin(): void {
		$this->becomeIdpWithUser( $this->superAdminUserId );

		$response = AuthorizeEndpoint::handleRequest( $this->authorizeRequest( 'flow-state-1' ) );

		self::assertSame( 302, $response->get_status() );
		$location = (string) $response->get_headers()['Location'];
		self::assertStringContainsString( 'pv_wpms_sso=callback', $location );
		self::assertStringContainsString( 'state=flow-state-1', $location );

		$ticket = $this->ticketFromCallbackLocation( $location );
		self::assertSame( $this->superAdminUserId, $ticket->userId );
		self::assertSame( $this->clientBlogId, $ticket->audienceBlogId );
		self::assertSame( get_main_site_id( get_current_network_id() ), $ticket->issuerBlogId );
		self::assertSame( 'flow-state-1', $ticket->state );

		restore_current_blog();
	}

	public function testAuthorizeSendsAnonymousUserToIdpLogin(): void {
		$this->becomeIdpWithUser( 0 );

		$response = AuthorizeEndpoint::handleRequest( $this->authorizeRequest( 'flow-state-2' ) );

		self::assertSame( 302, $response->get_status() );
		$location = (string) $response->get_headers()['Location'];
		self::assertStringContainsString( '/wp-login.php', $location );

		// Permalink-agnostic: decode the redirect_to chain and expect the authorize URL back.
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $locationArguments );
		$redirectTo = rawurldecode( rawurldecode( (string) ( $locationArguments['redirect_to'] ?? '' ) ) );
		self::assertStringContainsString( 'pv-wpms-sso/v1/authorize', $redirectTo );
		self::assertStringContainsString( 'client_blog_id=' . $this->clientBlogId, $redirectTo );
		self::assertStringContainsString( 'state=flow-state-2', $redirectTo );

		restore_current_blog();
	}

	public function testAuthorizeDeniesUserOutsideScope(): void {
		$this->becomeIdpWithUser( $this->plainUserId );

		$response = AuthorizeEndpoint::handleRequest( $this->authorizeRequest( 'flow-state-3' ) );

		self::assertSame( 302, $response->get_status() );
		self::assertStringContainsString(
			'error=' . AuthorizeEndpoint::ERROR_ACCESS_DENIED,
			(string) $response->get_headers()['Location']
		);

		restore_current_blog();
	}

	public function testAuthorizeRejectsBlogFromUnconfiguredNetwork(): void {
		$this->becomeIdpWithUser( $this->superAdminUserId );

		// Point the network's IdP at the client blog itself: site 1 is no longer trusted.
		Settings::save( get_current_network_id(), [ 'idp_blog_id' => $this->clientBlogId ] );

		$response = AuthorizeEndpoint::handleRequest( $this->authorizeRequest( 'flow-state-4' ) );

		self::assertStringContainsString(
			'error=' . AuthorizeEndpoint::ERROR_UNTRUSTED_CLIENT,
			(string) $response->get_headers()['Location']
		);

		restore_current_blog();
	}

	public function testSessionProbeRecognizesTrustedOriginWithSession(): void {
		$this->becomeIdpWithUser( $this->superAdminUserId );

		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/session' );
		$request->set_header( 'Origin', 'http://' . WP_TESTS_DOMAIN );

		$response = SessionEndpoint::handleRequest( $request );
		$data     = (array) $response->get_data();

		self::assertTrue( (bool) $data['authenticated'] );
		self::assertSame( 'sso_super_admin', $data['display_name'] );

		restore_current_blog();
	}

	public function testSessionProbeStaysFalseForUnknownOrigin(): void {
		$this->becomeIdpWithUser( $this->superAdminUserId );

		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/session' );
		$request->set_header( 'Origin', 'https://not-a-registered-site.example.net' );

		$response = SessionEndpoint::handleRequest( $request );
		$data     = (array) $response->get_data();

		self::assertFalse( (bool) $data['authenticated'] );

		restore_current_blog();
	}

	public function testVerifyAndConsumeTicketRoundTripAndReplay(): void {
		switch_to_blog( $this->clientBlogId );

		$issuedAt = time();
		$ticket   = new Ticket(
			issuerBlogId: get_main_site_id( get_current_network_id() ),
			audienceBlogId: $this->clientBlogId,
			userId: $this->superAdminUserId,
			userLogin: 'sso_super_admin',
			userEmail: 'sso_super_admin@example.org',
			displayName: 'sso_super_admin',
			avatarUrl: '',
			state: 'replay-state',
			ticketId: 'replay-ticket-id',
			issuedAt: $issuedAt,
			expiresAt: $issuedAt + TicketSigner::TICKET_TTL_SECONDS,
		);
		$token = TicketSigner::issue( $ticket, KeyStore::getForBlog( $ticket->issuerBlogId ) );

		$verified = ClientFlow::verifyAndConsumeTicket( $token, 'replay-state' );
		self::assertInstanceOf( Ticket::class, $verified );
		self::assertSame( $this->superAdminUserId, $verified->userId );

		$replayed = ClientFlow::verifyAndConsumeTicket( $token, 'replay-state' );
		self::assertInstanceOf( WP_Error::class, $replayed );
		self::assertSame( ClientFlow::ERROR_REPLAYED_TICKET, $replayed->get_error_code() );

		restore_current_blog();
	}

	public function testVerifyAndConsumeTicketRejectsForeignAudience(): void {
		switch_to_blog( $this->clientBlogId );

		$issuedAt = time();
		$ticket   = new Ticket(
			issuerBlogId: get_main_site_id( get_current_network_id() ),
			audienceBlogId: $this->clientBlogId + 100,
			userId: $this->superAdminUserId,
			userLogin: 'sso_super_admin',
			userEmail: 'sso_super_admin@example.org',
			displayName: 'sso_super_admin',
			avatarUrl: '',
			state: 'audience-state',
			ticketId: 'audience-ticket-id',
			issuedAt: $issuedAt,
			expiresAt: $issuedAt + TicketSigner::TICKET_TTL_SECONDS,
		);
		$token = TicketSigner::issue( $ticket, KeyStore::getForBlog( $ticket->issuerBlogId ) );

		$rejected = ClientFlow::verifyAndConsumeTicket( $token, 'audience-state' );
		self::assertInstanceOf( WP_Error::class, $rejected );
		self::assertSame( ClientFlow::ERROR_INVALID_TICKET, $rejected->get_error_code() );

		restore_current_blog();
	}

	public function testStateStoreRoundTripAndSingleUse(): void {
		switch_to_blog( $this->clientBlogId );

		$created = StateStore::create( '/wp-admin/post.php' );

		self::assertSame(
			'/wp-admin/post.php',
			StateStore::consume( $created['state'], $created['state'] )
		);
		self::assertNull( StateStore::consume( $created['state'], $created['state'] ) );
		self::assertNull( StateStore::consume( 'not-a-state', 'not-a-state' ) );

		$mismatch = StateStore::create( '' );
		self::assertNull( StateStore::consume( $mismatch['state'], 'different-cookie-value' ) );

		restore_current_blog();
	}

	public function testJtiTableClaimIsSingleUse(): void {
		$claimed = JtiTable::claimTicketId( 'integration-jti-1', time() + 60 );
		$again   = JtiTable::claimTicketId( 'integration-jti-1', time() + 60 );

		self::assertTrue( $claimed );
		self::assertFalse( $again );
	}

	private function authorizeRequest( string $state ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/authorize' );
		$request->set_param( 'client_blog_id', $this->clientBlogId );
		$request->set_param( 'state', $state );

		return $request;
	}

	private function ticketFromCallbackLocation( string $location ): Ticket {
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $queryArguments );
		$token = $queryArguments['ticket'] ?? '';
		self::assertIsString( $token );
		self::assertNotSame( '', $token );

		$idpBlogId = get_main_site_id( get_current_network_id() );

		return TicketSigner::verify( $token, KeyStore::getForBlog( $idpBlogId ), time() );
	}
}

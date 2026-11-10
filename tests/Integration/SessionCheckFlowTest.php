<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\AuthorizeEndpoint;
use PV\WpmsSso\ClientFlow;
use PV\WpmsSso\LoginScreen;
use PV\WpmsSso\SessionCheck;
use PV\WpmsSso\Settings;
use PV\WpmsSso\Tests\Support\JourneyFixture;
use PV\WpmsSso\Ticket;
use WP_REST_Request;

/** @group SSO-010 */
final class SessionCheckFlowTest extends JourneyFixture {

	public function tearDown(): void {
		unset( $_GET[ SessionCheck::RESULT_PARAM ] );
		parent::tearDown();
	}

	/** @return array<string, string> query arguments of the redirect back to the client */
	private function silentAuthorize( string $person, string $destination ): array {
		$this->enter( 'Hub', '' === $person ? 0 : $this->users[ $person ] );
		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/authorize' );
		$request->set_param( 'client_blog_id', $this->sites[ $destination ] );
		$request->set_param( 'state', 'check-state' );
		$request->set_param( 'prompt', 'none' );
		$location = AuthorizeEndpoint::handleRequest( $request )->get_headers()['Location'];
		self::assertStringStartsWith( get_site_url( $this->sites[ $destination ] ) . '/wp-login.php?', $location );
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $arguments );
		return $arguments;
	}

	/** @group SSO-012 */
	public function testSilentAuthorizeNeverAsksAnAnonymousVisitorToSignIn(): void {
		$arguments = $this->silentAuthorize( '', 'Southmountain' );
		self::assertSame( 'no_session', $arguments['error'] );
		self::assertSame( 'callback', $arguments['pv_wpms_sso'] );
		self::assertArrayNotHasKey( 'ticket', $arguments );
	}

	/** @group SSO-011 */
	public function testSilentAuthorizeDoesNotExplainARefusal(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Casey'], 'editor' );
		$arguments = $this->silentAuthorize( 'Casey', 'Southmountain' );
		self::assertSame( 'no_session', $arguments['error'] );
		self::assertArrayNotHasKey( 'ticket', $arguments );
	}

	public function testSilentAuthorizeIssuesARedeemableTicketForASessionInScope(): void {
		add_user_to_blog( $this->sites['Southmountain'], $this->users['Alex'], 'editor' );
		$arguments = $this->silentAuthorize( 'Alex', 'Southmountain' );
		self::assertArrayNotHasKey( 'error', $arguments );
		$this->enter( 'Southmountain' );
		$ticket = ClientFlow::verifyAndConsumeTicket( $arguments['ticket'], 'check-state' );
		self::assertInstanceOf( Ticket::class, $ticket );
		self::assertSame( $this->users['Alex'], $ticket->userId );
	}

	public function testCheckUrlAsksTheDesignatedIdentitySiteWithoutPrompt(): void {
		$this->enter( 'Ridgeview' );
		$url = ClientFlow::authorizeUrl( 'check-state', true );
		self::assertStringStartsWith( get_site_url( $this->sites['Hub'] ), $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $arguments );
		self::assertSame( 'none', $arguments['prompt'] );
		self::assertSame( (string) $this->sites['Ridgeview'], $arguments['client_blog_id'] );
		self::assertStringNotContainsString( 'prompt', ClientFlow::authorizeUrl( 'check-state' ) );
	}

	public function testCheckRunsOnClientSitesOnlyAndCanBeTurnedOff(): void {
		$this->enter( 'Hub' );
		self::assertFalse( SessionCheck::enabledForThisSite() );
		$this->enter( 'Southmountain' );
		self::assertTrue( SessionCheck::enabledForThisSite() );
		add_filter( SessionCheck::ENABLED_FILTER, '__return_false' );
		self::assertFalse( SessionCheck::enabledForThisSite() );
		remove_filter( SessionCheck::ENABLED_FILTER, '__return_false' );
		Settings::save( get_current_network_id(), [ 'enabled' => false ] );
		self::assertFalse( SessionCheck::enabledForThisSite() );
	}

	public function testAutomaticSignInIsANetworkSettingThatDefaultsOn(): void {
		$this->enter( 'Southmountain' );
		self::assertTrue( Settings::autoSignIn() );
		Settings::save( get_current_network_id(), [ 'auto_sign_in' => false ] );
		self::assertFalse( Settings::autoSignIn() );
		self::assertTrue( Settings::autoSignIn( (int) get_site( $this->sites['Ridgeview'] )->site_id ) );
	}

	public function testReturnUrlCarriesResultDestinationAndLogoutNotice(): void {
		$this->enter( 'Southmountain' );
		$destination = admin_url( 'edit.php?post_type=page&paged=2' );
		$url = SessionCheck::loginUrl( [ 'redirect_to' => $destination, 'auto_sign_in' => true, 'after_logout' => true ], SessionCheck::RESULT_NONE );
		self::assertStringStartsWith( wp_login_url(), $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $arguments );
		self::assertSame( [ 'pv_wpms_sso_checked' => 'none', 'redirect_to' => $destination, 'loggedout' => 'true' ], $arguments );
		self::assertFalse( SessionCheck::requestIsPlainLoginForm( 'GET', $arguments ) );
	}

	private function renderedLoginScreen( string $checkResult ): string {
		$this->enter( 'Southmountain' );
		if ( '' !== $checkResult ) {
			$_GET[ SessionCheck::RESULT_PARAM ] = $checkResult;
		}
		ob_start();
		LoginScreen::render();
		return (string) ob_get_clean();
	}

	/** @group SSO-011 */
	public function testLoginScreenHasNoNetworkButtonWithoutAVouchedSession(): void {
		self::assertStringNotContainsString( 'pv-wpms-sso-button', $this->renderedLoginScreen( SessionCheck::RESULT_NONE ) );
		self::assertStringNotContainsString( 'pv-wpms-sso-button', $this->renderedLoginScreen( str_repeat( 'a', 32 ) ) );
	}

	public function testLoginScreenOffersOneClickForAVouchedSession(): void {
		$this->enter( 'Southmountain' );
		$token = SessionCheck::storeHint( new Ticket(
			issuerBlogId: $this->sites['Hub'], audienceBlogId: $this->sites['Southmountain'], userId: $this->users['Alex'],
			userLogin: 'alex', userEmail: 'alex@example.test', displayName: 'Alex <b>Rivera</b>',
			avatarUrl: 'https://avatars.example.test/alex.png', state: 'check-state', ticketId: 'jti', issuedAt: time(), expiresAt: time() + 60,
		) );
		$html = $this->renderedLoginScreen( $token );
		self::assertStringContainsString( 'id="pv-wpms-sso-button"', $html );
		self::assertStringContainsString( 'Continue as Alex &lt;b&gt;Rivera&lt;/b&gt;', $html );
		self::assertStringContainsString( 'src="https://avatars.example.test/alex.png"', $html );
		self::assertStringContainsString( 'pv_wpms_sso=start', $html );
		self::assertStringNotContainsString( 'data-probe-url', $html );
	}

	public function testDisablingTheCheckRestoresTheAlwaysVisibleButton(): void {
		add_filter( SessionCheck::ENABLED_FILTER, '__return_false' );
		$html = $this->renderedLoginScreen( '' );
		self::assertStringContainsString( 'id="pv-wpms-sso-button"', $html );
		self::assertStringContainsString( 'data-probe-url', $html );
	}
}

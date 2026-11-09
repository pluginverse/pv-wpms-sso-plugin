<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\KeyStore;
use PV\WpmsSso\SamlMetadata;
use PV\WpmsSso\SamlProvider;
use PV\WpmsSso\SamlSettings;
use PV\WpmsSso\Settings;
use PV\WpmsSso\TicketSigner;
use PV\WpmsSso\UserMapping;
use OneLogin\Saml2\Utils as SamlUtils;
use WP_REST_Response;
use WP_UnitTestCase;

use function base64_decode;
use function base64_encode;
use function get_current_network_id;
use function get_main_site_id;
use function get_transient;
use function gzinflate;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;
use function parse_url;
use function preg_match;
use function preg_replace;
use function restore_current_blog;
use function switch_to_blog;
use function time;
use const OPENSSL_KEYTYPE_RSA;

/**
 * SAML flow against the multisite test install. A throwaway RSA keypair acts
 * as the IdP; responses are signed with OneLogin\Saml2\Utils::addSign, so the
 * full XML-DSig validation path runs for real.
 */
final class SamlFlowTest extends WP_UnitTestCase {

	private const IDP_ENTITY_ID = 'https://app.onelogin.com/saml/metadata/123456';
	private const IDP_SSO_URL   = 'https://example.onelogin.com/trust/saml2/http-redirect/sso/123456';
	private const CLIENT_STATE  = 'saml-client-state-1';

	private static string $trustedPrivateKeyPem;
	private static string $trustedCertificatePem;
	private static string $trustedCertificateBody;
	private static string $untrustedPrivateKeyPem;
	private static string $untrustedCertificatePem;

	private int $superAdminUserId;
	private int $clientBlogId;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		[ self::$trustedPrivateKeyPem, self::$trustedCertificatePem, self::$trustedCertificateBody ]   = self::generateKeyPair();
		[ self::$untrustedPrivateKeyPem, self::$untrustedCertificatePem ]                                = self::generateKeyPair();
	}

	public function setUp(): void {
		parent::setUp();

		$this->superAdminUserId = self::factory()->user->create( [ 'user_login' => 'saml_super_admin' ] );
		grant_super_admin( $this->superAdminUserId );
		$this->clientBlogId = self::factory()->blog->create( [ 'path' => '/saml-client/' ] );

		Settings::save(
			get_current_network_id(),
			[ 'enabled' => true, 'idp_blog_id' => 0, 'scope' => Settings::SCOPE_NETWORK_USERS ]
		);
		SamlSettings::save(
			get_current_network_id(),
			[
				'enabled'        => true,
				'idp_entity_id'  => self::IDP_ENTITY_ID,
				'idp_sso_url'    => self::IDP_SSO_URL,
				'idp_x509cert'   => self::$trustedCertificateBody,
				'auto_provision' => true,
				'default_role'   => 'editor',
			]
		);
	}

	public function tearDown(): void {
		revoke_super_admin( $this->superAdminUserId );
		wp_delete_site( $this->clientBlogId );

		parent::tearDown();
	}

	private function becomeIdpSite(): void {
		switch_to_blog( get_main_site_id( get_current_network_id() ) );
	}

	public function testMetadataIsServedOnTheIdentitySite(): void {
		$this->becomeIdpSite();

		$result = SamlMetadata::handleRequest();

		self::assertInstanceOf( WP_REST_Response::class, $result );
		self::assertSame( 200, $result->get_status() );

		$metadataXml = (string) $result->get_data();
		self::assertStringContainsString( 'EntityDescriptor', $metadataXml );
		self::assertStringContainsString( SamlProvider::acsUrl(), $metadataXml );
		self::assertStringContainsString( 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', $metadataXml );

		restore_current_blog();
	}

	public function testStartBuildsASignedAuthnRequestRedirect(): void {
		$this->becomeIdpSite();

		$redirectUrl = SamlProvider::startRedirectUrl( $this->clientBlogId, self::CLIENT_STATE );

		self::assertStringContainsString( 'example.onelogin.com', $redirectUrl );
		self::assertStringContainsString( 'SAMLRequest=', $redirectUrl );
		self::assertStringContainsString( 'RelayState=', $redirectUrl );
		self::assertStringContainsString( 'client_blog_id=' . $this->clientBlogId, rawurldecode( $redirectUrl ) );

		$requestId = $this->storedRequestIdFromStartUrl( $redirectUrl );
		self::assertNotSame( '', $requestId );
		self::assertIsArray( get_transient( 'pv_wpms_sso_saml_req_' . $requestId ) );

		restore_current_blog();
	}

	/** @group SAML-001 */
	public function testAcsRoundTripIssuesTicketToTheClientSite(): void {
		$this->becomeIdpSite();

		$requestId = $this->startRequestForClient();
		$samlResponse = $this->buildSignedResponse( $requestId, 'brand.new.user@example.org' );

		$result = SamlProvider::processSamlResponse( $samlResponse );

		self::assertGreaterThan( 0, $result['user_id'] );
		$provisionedUser = get_userdata( $result['user_id'] );
		self::assertNotFalse( $provisionedUser );
		self::assertSame( 'brand.new.user@example.org', $provisionedUser->user_email );

		self::assertStringContainsString( 'pv_wpms_sso=callback', $result['redirect_url'] );
		self::assertStringContainsString( 'state=' . self::CLIENT_STATE, $result['redirect_url'] );

		// The ticket must verify and be audience-bound to the client blog.
		parse_str( (string) parse_url( $result['redirect_url'], PHP_URL_QUERY ), $callbackArguments );
		$ticket = TicketSigner::verify(
			(string) $callbackArguments['ticket'],
			KeyStore::getForBlog( get_main_site_id( get_current_network_id() ) ),
			time()
		);
		self::assertSame( $this->clientBlogId, $ticket->audienceBlogId );
		self::assertSame( $result['user_id'], $ticket->userId );
		self::assertSame( self::CLIENT_STATE, $ticket->state );

		restore_current_blog();
	}

	public function testAcsRejectsReplayedResponse(): void {
		$this->becomeIdpSite();

		$samlResponse = $this->buildSignedResponse( $this->startRequestForClient(), 'replay.user@example.org' );

		$first  = SamlProvider::processSamlResponse( $samlResponse );
		$second = SamlProvider::processSamlResponse( $samlResponse );

		self::assertGreaterThan( 0, $first['user_id'] );
		self::assertSame( 0, $second['user_id'] );
		self::assertStringContainsString( SamlProvider::ERROR_INVALID_RESPONSE, $second['redirect_url'] );

		restore_current_blog();
	}

	/** @group SAML-001 */
	public function testAcsCallbackPermitsOnlyItsRegisteredMappedSite(): void {
		update_blog_details( $this->clientBlogId, [ 'domain' => 'mapped-client.test' ] );
		update_blog_option( $this->clientBlogId, 'siteurl', 'https://mapped-client.test' );
		$this->becomeIdpSite();
		$result = SamlProvider::processSamlResponse( $this->buildSignedResponse( $this->startRequestForClient(), 'mapped.member@example.org' ) );
		self::assertSame( $this->clientBlogId, $result['client_blog_id'] );
		self::assertSame( 'blocked', wp_validate_redirect( $result['redirect_url'], 'blocked' ) );
		self::assertTrue( \PV\WpmsSso\RedirectGuard::allowSiteRedirect( $result['redirect_url'], $result['client_blog_id'] ) );
		self::assertSame( $result['redirect_url'], wp_validate_redirect( $result['redirect_url'], 'blocked' ) );
		self::assertFalse( \PV\WpmsSso\RedirectGuard::allowSiteRedirect( 'https://attacker.test/', $result['client_blog_id'] ) );
		self::assertSame( 'blocked', wp_validate_redirect( 'https://attacker.test/', 'blocked' ) );
		restore_current_blog();
	}

	public function testAcsRejectsResponseToUnknownRequest(): void {
		$this->becomeIdpSite();

		$samlResponse = $this->buildSignedResponse( '_never_issued_request', 'unknown.request@example.org' );
		$result       = SamlProvider::processSamlResponse( $samlResponse );

		self::assertSame( 0, $result['user_id'] );
		self::assertStringContainsString( SamlProvider::ERROR_INVALID_RESPONSE, $result['redirect_url'] );

		restore_current_blog();
	}

	public function testAcsRejectsWrongSigningKey(): void {
		$this->becomeIdpSite();

		$requestId = $this->startRequestForClient();
		$samlResponse = $this->buildSignedResponse(
			$requestId,
			'wrong.key@example.org',
			self::$untrustedPrivateKeyPem,
			self::$untrustedCertificatePem
		);

		$result = SamlProvider::processSamlResponse( $samlResponse );

		self::assertSame( 0, $result['user_id'] );
		self::assertStringContainsString( SamlProvider::ERROR_INVALID_RESPONSE, $result['redirect_url'] );

		restore_current_blog();
	}

	public function testAcsRejectsForeignAudience(): void {
		$this->becomeIdpSite();

		$samlResponse = $this->buildSignedResponse(
			$this->startRequestForClient(),
			'foreign.audience@example.org',
			self::$trustedPrivateKeyPem,
			self::$trustedCertificatePem,
			'https://somebody-else.example.net/sp'
		);

		$result = SamlProvider::processSamlResponse( $samlResponse );

		self::assertSame( 0, $result['user_id'] );
		self::assertStringContainsString( SamlProvider::ERROR_INVALID_RESPONSE, $result['redirect_url'] );

		restore_current_blog();
	}

	/** @group SAML-003 */
	public function testAcsRefusesUnknownUserWithoutProvisioning(): void {
		$this->becomeIdpSite();
		SamlSettings::save( get_current_network_id(), [ 'auto_provision' => false ] );

		$samlResponse = $this->buildSignedResponse( $this->startRequestForClient(), 'not.provisioned@example.org' );
		$result       = SamlProvider::processSamlResponse( $samlResponse );

		self::assertSame( 0, $result['user_id'] );
		self::assertStringContainsString( SamlProvider::ERROR_UNKNOWN_USER, $result['redirect_url'] );

		restore_current_blog();
	}

	public function testEmailMappingPrefersNameIdThenAttributes(): void {
		self::assertSame(
			'nameid@example.org',
			UserMapping::emailAddressFromAssertion( 'nameid@example.org', [] )
		);
		self::assertSame(
			'attribute@example.org',
			UserMapping::emailAddressFromAssertion( 'opaque-name-id-value', [ 'mail' => [ 'attribute@example.org' ] ] )
		);
		self::assertSame(
			'',
			UserMapping::emailAddressFromAssertion( 'opaque-name-id-value', [ 'phone' => [ '+15550100' ] ] )
		);
	}

	private static function generateKeyPair(): array {
        return \PV\WpmsSso\Tests\Support\SignedSamlResponse::generateKeyPair();
    }

	/** Start the flow for the client blog and return the issued request ID. */
	private function startRequestForClient(): string {
		$redirectUrl = SamlProvider::startRedirectUrl( $this->clientBlogId, self::CLIENT_STATE );

		return $this->storedRequestIdFromStartUrl( $redirectUrl );
	}

	private function storedRequestIdFromStartUrl( string $redirectUrl ): string {
		parse_str( (string) parse_url( $redirectUrl, PHP_URL_QUERY ), $redirectArguments );

		$deflatedRequest = base64_decode( (string) ( $redirectArguments['SAMLRequest'] ?? '' ), true );
		$requestXml      = false === $deflatedRequest ? '' : gzinflate( $deflatedRequest );

		self::assertIsString( $requestXml );
		self::assertNotFalse( $requestXml );

		$matched = preg_match( '/ID="([^"]+)"/', $requestXml, $matches );

		return 1 === $matched ? $matches[1] : '';
	}

	/**
	 * A realistic signed SAMLResponse answering our request, message-signed
	 * (wantMessagesSigned) with the given key.
	 */
	private function buildSignedResponse( string $requestId, string $emailAddress, ?string $signingKeyPem = null, ?string $signingCertificatePem = null, ?string $audienceOverride = null ): string {
        return \PV\WpmsSso\Tests\Support\SignedSamlResponse::build( $requestId, $emailAddress, $signingKeyPem ?? self::$trustedPrivateKeyPem, $signingCertificatePem ?? self::$trustedCertificatePem, $audienceOverride );
    }
}

<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_query_arg;
use function delete_transient;
use function do_action;
use function get_current_blog_id;
use function get_current_network_id;
use function get_current_user_id;
use function get_dashboard_url;
use function get_site_url;
use function get_transient;
use function http_build_query;
use function is_array;
use function is_int;
use function is_ssl;
use function is_string;
use function set_transient;
use function time;
use function wp_login_url;
use function wp_redirect;
use function wp_safe_redirect;
use function wp_set_auth_cookie;
use function wp_set_current_user;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Response as Saml2Response;
use OneLogin\Saml2\Settings as Saml2Settings;
use OneLogin\Saml2\Utils as SamlUtils;
use OneLogin\Saml2\ValidationError as SamlValidationError;

/**
 * The SAML SP half, running on the identity site only (decision 0005):
 * builds the AuthnRequest for the configured IdP (OneLogin, Okta, ...) and
 * consumes the POSTed SAML Response at the ACS. After a valid assertion the
 * user has a session on the identity site, and the existing ticket hand-off
 * (TicketIssuer) carries them to the client site they came from.
 *
 * Replay closure: every AuthnRequest ID is stored in a 10-minute transient;
 * the ACS accepts a response only when its InResponseTo resolves to a stored,
 * unexpired request, which is then consumed.
 */
final class SamlProvider {

	public const ACS_ACTION = 'pv_wpms_sso_saml_acs';

	private const REQUEST_TRANSIENT_PREFIX = 'pv_wpms_sso_saml_req_';
	private const REQUEST_TTL_SECONDS     = 600;

	public const ERROR_INVALID_RESPONSE = 'saml_invalid_response';
	public const ERROR_UNKNOWN_USER     = 'saml_unknown_user';

	/**
	 * The php-saml SP settings for the identity site of the current network.
	 *
	 * @return array{strict: bool, debug: bool, sp: array{entityId: string, assertionConsumerService: array{url: string, binding: string}, NameIDFormat: string}, idp: array{entityId: string, singleSignOnService: array{url: string, binding: string}, x509cert: string}, security: array{wantAssertionsSigned: bool, signatureAlgorithm: string}}
	 */
	public static function spSettingsArray(): array {
		$idpSettings = SamlSettings::forNetwork( get_current_network_id() );

		return [
			'strict' => true,
			'debug'  => false,
			'sp'     => [
				'entityId'                    => rest_url( AuthorizeEndpoint::REST_NAMESPACE . SamlMetadata::ROUTE ),
				'assertionConsumerService'    => [
					'url'     => self::acsUrl(),
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
				],
				'NameIDFormat'                => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
			],
			'idp'    => [
				'entityId'            => $idpSettings['idp_entity_id'],
				'singleSignOnService' => [
					'url'     => $idpSettings['idp_sso_url'],
					'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
				],
				'x509cert'            => $idpSettings['idp_x509cert'],
			],
			'security' => [
				'wantMessagesSigned'   => true,
				'wantAssertionsSigned' => false,
				'signatureAlgorithm'   => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
			],
		];
	}

	public static function acsUrl(): string {
		return add_query_arg( 'action', self::ACS_ACTION, wp_login_url() );
	}

	/**
	 * SP-initiated flow: remember the request (and what to do afterwards) and
	 * build the IdP redirect URL carrying the deflated AuthnRequest. The
	 * wrapper sends it.
	 *
	 * @param int|null $clientBlogId the originating site, when started from a client
	 */
	public static function startRedirectUrl( ?int $clientBlogId, string $state ): string {
		if ( null !== $clientBlogId && 0 === TrustPolicy::trustedClientNetworkId( $clientBlogId ) ) {
			return self::loginErrorUrl( wp_login_url(), AuthorizeEndpoint::ERROR_UNTRUSTED_CLIENT );
		}

		try {
			$settings     = new Saml2Settings( self::spSettingsArray() );
			$authnRequest = new AuthnRequest( $settings );
		} catch ( SamlError $exception ) {
			return self::loginErrorUrl( wp_login_url(), self::ERROR_INVALID_RESPONSE );
		}

		set_transient(
			self::REQUEST_TRANSIENT_PREFIX . $authnRequest->getId(),
			[
				'client_blog_id' => $clientBlogId,
				'state'          => $state,
				'expires_at'     => time() + self::REQUEST_TTL_SECONDS,
			],
			self::REQUEST_TTL_SECONDS
		);

		$redirectParameters = [ 'SAMLRequest' => $authnRequest->getRequest() ];
		if ( null !== $clientBlogId ) {
			$redirectParameters['RelayState'] = http_build_query(
				[
					'client_blog_id' => $clientBlogId,
					'state'          => $state,
				]
			);
		}

		$idpSsoUrl = $settings->getIdPSSOUrl();
		if ( ! is_string( $idpSsoUrl ) || '' === $idpSsoUrl ) {
			return self::loginErrorUrl( wp_login_url(), self::ERROR_INVALID_RESPONSE );
		}

		// $stay = true: the URL is returned, WordPress sends the redirect.
		return SamlUtils::redirect( $idpSsoUrl, $redirectParameters, true );
	}

	/** @param int|null $clientBlogId the originating site, when started from a client */
	public static function start( ?int $clientBlogId, string $state ): never {
		wp_redirect( self::startRedirectUrl( $clientBlogId, $state ) );
		exit;
	}

	/**
	 * ACS core: validate the POSTed response, map the user, and compute where
	 * to go next — the client callback with a fresh ticket (client start), or
	 * the dashboard (IdP-local start). The wrapper sets the cookies and sends
	 * the redirect.
	 *
	 * @return array{user_id: int, redirect_url: string} user_id 0 with an
	 *         error-carrying redirect_url on any failure.
	 */
	public static function processSamlResponse( string $base64Response ): array {
		try {
			$settings = new Saml2Settings( self::spSettingsArray() );
			$response = new Saml2Response( $settings, $base64Response );
		} catch ( SamlError | SamlValidationError $exception ) {
			return [ 'user_id' => 0, 'redirect_url' => self::loginErrorUrl( wp_login_url(), self::ERROR_INVALID_RESPONSE ) ];
		}

		// Replay closure: the response must answer a request we issued.
		$documentElement = $response->getXMLDocument()->documentElement;
		$inResponseTo    = $documentElement instanceof \DOMElement ? $documentElement->getAttribute( 'InResponseTo' ) : '';
		$requestKey      = self::REQUEST_TRANSIENT_PREFIX . $inResponseTo;
		$requestPayload  = get_transient( $requestKey );

		if ( '' === $inResponseTo || ! is_array( $requestPayload ) ) {
			return [ 'user_id' => 0, 'redirect_url' => self::loginErrorUrl( wp_login_url(), self::ERROR_INVALID_RESPONSE ) ];
		}
		delete_transient( $requestKey );

		if ( ! $response->isValid( $inResponseTo ) ) {
			/** Keep the reason visible to admins in the debug log, never to the browser. */
			do_action( 'pv_wpms_sso/saml_validation_failed', $response->getError( false ), get_current_user_id() );

			return [ 'user_id' => 0, 'redirect_url' => self::loginErrorUrl( wp_login_url(), self::ERROR_INVALID_RESPONSE ) ];
		}

		$emailAddress = UserMapping::emailAddressFromAssertion(
			$response->getNameId(),
			$response->getAttributes()
		);
		if ( '' === $emailAddress ) {
			return [ 'user_id' => 0, 'redirect_url' => self::loginErrorUrl( wp_login_url(), self::ERROR_UNKNOWN_USER ) ];
		}

		$userId = UserMapping::findExistingUserIdByEmail( $emailAddress );
		if ( 0 === $userId ) {
			$samlSettings = SamlSettings::forNetwork( get_current_network_id() );
			$userId       = $samlSettings['auto_provision']
				? UserMapping::provisionUser( $emailAddress, $response->getAttributes(), $samlSettings['default_role'] )
				: 0;
		}

		if ( 0 === $userId ) {
			return [ 'user_id' => 0, 'redirect_url' => self::loginErrorUrl( wp_login_url(), self::ERROR_UNKNOWN_USER ) ];
		}

		/** Fires after a successful SAML login on the identity site. */
		do_action( 'pv_wpms_sso/saml_login', $userId, get_current_blog_id() );

		$storedClientBlogId = is_int( $requestPayload['client_blog_id'] ?? null ) ? $requestPayload['client_blog_id'] : null;
		$storedState        = is_string( $requestPayload['state'] ?? null ) ? $requestPayload['state'] : '';

		if ( null !== $storedClientBlogId && $storedClientBlogId > 0 ) {
			$clientNetworkId = TrustPolicy::trustedClientNetworkId( $storedClientBlogId );
			if ( 0 === $clientNetworkId || ! TrustPolicy::userPassesClientScope( $userId, $storedClientBlogId, $clientNetworkId ) ) {
				return [
					'user_id'      => 0,
					'redirect_url' => self::loginErrorUrl(
						self::clientLoginUrl( $storedClientBlogId ),
						AuthorizeEndpoint::ERROR_ACCESS_DENIED
					),
				];
			}

			$callbackUrl = TicketIssuer::issueTicketCallbackUrl( $userId, $storedClientBlogId, $storedState );
			if ( '' !== $callbackUrl ) {
				return [ 'user_id' => $userId, 'redirect_url' => $callbackUrl ];
			}
		}

		return [ 'user_id' => $userId, 'redirect_url' => get_dashboard_url( $userId ) ];
	}

	/**
	 * ACS (POST target): validate the response, set the identity site's
	 * cookies, and redirect — to the originating client via ticket, or to
	 * this site's dashboard when the flow started here.
	 */
	public static function consumeAcs(): never {
		$postedResponse = isset( $_POST['SAMLResponse'] ) && is_string( $_POST['SAMLResponse'] )
			? $_POST['SAMLResponse']
			: '';

		$result = self::processSamlResponse( $postedResponse );

		if ( $result['user_id'] > 0 ) {
			wp_set_current_user( $result['user_id'] );
			wp_set_auth_cookie( $result['user_id'], true, is_ssl() );
		}

		wp_safe_redirect( $result['redirect_url'] );
		exit;
	}

	private static function clientLoginUrl( int $clientBlogId ): string {
		return get_site_url( $clientBlogId ) . '/wp-login.php';
	}

	private static function loginErrorUrl( string $loginUrl, string $errorCode ): string {
		return add_query_arg( [ LoginScreen::ERROR_PARAM => $errorCode ], $loginUrl );
	}
}

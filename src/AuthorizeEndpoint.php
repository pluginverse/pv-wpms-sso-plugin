<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_query_arg;
use function bin2hex;
use function get_current_user_id;
use function rawurlencode;
use function register_rest_route;
use function rest_url;
use function wp_login_url;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The IdP half of the flow (decision 0002): GET /wp-json/pv-wpms-sso/v1/authorize
 *
 * Trust rule: the request is honored only when the client blog's network
 * designates the current site as its IdP (TrustPolicy). Anonymous users are
 * sent to the IdP login with this URL in redirect_to, so credentials and
 * two-factor happen exactly once, here.
 */
final class AuthorizeEndpoint {

	public const REST_NAMESPACE = 'pv-wpms-sso/v1';
	public const ROUTE          = '/authorize';

	public const ERROR_UNTRUSTED_CLIENT = 'untrusted_client';
	public const ERROR_ACCESS_DENIED    = 'access_denied';
	public const ERROR_SERVER_ERROR     = 'server_error';
	public const ERROR_NO_SESSION       = 'no_session';

	public const PROMPT_NONE = 'none';

	public static function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handleRequest' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'client_blog_id' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
					'state'          => [
						'type'     => 'string',
						'required' => true,
					],
					'prompt'         => [
						'type' => 'string',
					],
				],
			]
		);
	}

	public static function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$clientBlogId = self::intParam( $request, 'client_blog_id' );
		$state        = self::stringParam( $request, 'state' );

		if ( 0 === TrustPolicy::trustedClientNetworkId( $clientBlogId ) ) {
			return self::redirectToClientLogin( $clientBlogId, $state, self::ERROR_UNTRUSTED_CLIENT );
		}

		// prompt=none (decision 0012): answer from the existing session only.
		// Never show a login here, and do not say why there is no ticket.
		$silent = self::PROMPT_NONE === self::stringParam( $request, 'prompt' );

		$userId = TrustPolicy::currentSessionUserId();
		if ( 0 === $userId ) {
			return $silent
				? self::redirectToClientLogin( $clientBlogId, $state, self::ERROR_NO_SESSION )
				: self::redirectToIdpLogin( $clientBlogId, $state );
		}

		$clientNetworkId = TrustPolicy::trustedClientNetworkId( $clientBlogId );
		if ( ! TrustPolicy::userPassesClientScope( $userId, $clientBlogId, $clientNetworkId ) ) {
			return self::redirectToClientLogin( $clientBlogId, $state, $silent ? self::ERROR_NO_SESSION : self::ERROR_ACCESS_DENIED );
		}

		$callbackUrl = TicketIssuer::issueTicketCallbackUrl( $userId, $clientBlogId, $state );
		if ( '' === $callbackUrl ) {
			return self::redirectToClientLogin( $clientBlogId, $state, self::ERROR_SERVER_ERROR );
		}

		return self::redirectResponse( $callbackUrl );
	}

	/**
	 * Not signed in yet: prompt at the IdP (credentials + two-factor, once) and
	 * come straight back here afterwards. wp-login.php honors redirect_to.
	 */
	private static function redirectToIdpLogin( int $clientBlogId, string $state ): WP_REST_Response {
		$authorizeUrl = add_query_arg(
			[
				'client_blog_id' => $clientBlogId,
				'state'          => $state,
			],
			rest_url( self::REST_NAMESPACE . self::ROUTE )
		);
		$idpLoginUrl = add_query_arg(
			[ 'redirect_to' => rawurlencode( $authorizeUrl ) ],
			wp_login_url()
		);

		return self::redirectResponse( $idpLoginUrl );
	}

	private static function redirectToClientLogin( int $clientBlogId, string $state, string $errorCode ): WP_REST_Response {
		return self::redirectResponse(
			add_query_arg(
				[
					ClientFlow::QUERY_PARAM => ClientFlow::ACTION_CALLBACK,
					'state'                 => $state,
					'error'                 => $errorCode,
				],
				self::clientLoginUrl( $clientBlogId )
			)
		);
	}

	private static function clientLoginUrl( int $clientBlogId ): string {
		return get_site_url( $clientBlogId ) . '/wp-login.php';
	}

	private static function intParam( WP_REST_Request $request, string $paramName ): int {
		$value = $request->get_param( $paramName );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	private static function stringParam( WP_REST_Request $request, string $paramName ): string {
		$value = $request->get_param( $paramName );

		return is_string( $value ) ? $value : '';
	}

	private static function redirectResponse( string $location ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $location );

		return $response;
	}
}

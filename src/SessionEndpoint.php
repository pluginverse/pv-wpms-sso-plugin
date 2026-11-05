<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function get_avatar_url;
use function get_current_user_id;
use function get_network;
use function get_sites;
use function get_userdata;
use function in_array;
use function is_array;
use function parse_url;
use function register_rest_route;
use function strtolower;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The session probe (IdP half): GET /wp-json/pv-wpms-sso/v1/session
 *
 * Lets a client site's login screen ask "does this browser already have a
 * validated session here, and may it SSO?" Credentialed cross-origin fetch;
 * data (display name, avatar) is only included for origins that are registered
 * sites of the install whose network designates this site as IdP. WordPress
 * core already sends credentialed CORS headers for REST GET requests, so this
 * origin allowlist is the actual data gate.
 */
final class SessionEndpoint {

	public const ROUTE = '/session';

	public static function registerRoutes(): void {
		register_rest_route(
			AuthorizeEndpoint::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handleRequest' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( [ 'authenticated' => false ] );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Vary', 'Origin' );

		$originHeader = (string) $request->get_header( 'Origin' );
		if ( '' === $originHeader ) {
			return $response;
		}

		$clientBlogId = self::blogIdForOrigin( $originHeader );
		if ( 0 === $clientBlogId ) {
			return $response;
		}

		$clientNetworkId = TrustPolicy::trustedClientNetworkId( $clientBlogId );
		if ( 0 === $clientNetworkId ) {
			return $response;
		}

		$userId = get_current_user_id();
		if ( 0 === $userId || ! TrustPolicy::userPassesClientScope( $userId, $clientBlogId, $clientNetworkId ) ) {
			return $response;
		}

		$user         = get_userdata( $userId );
		$avatarUrl    = false === $user ? false : get_avatar_url( $user->ID );
		$clientNetwork = get_network( $clientNetworkId );

		$response->set_data(
			[
				'authenticated' => true,
				'display_name'  => false === $user ? '' : $user->display_name,
				'avatar_url'    => is_string( $avatarUrl ) ? $avatarUrl : '',
				'network_name'  => null === $clientNetwork ? '' : (string) $clientNetwork->site_name,
			]
		);

		return $response;
	}

	/**
	 * Resolve an Origin header to a registered blog by host. Subdirectory
	 * installs share a host (and its cookies), so host resolution is enough
	 * for the domain-mapped deployments this plugin exists for.
	 */
	private static function blogIdForOrigin( string $originHeader ): int {
		$originParts = parse_url( $originHeader );
		if ( ! is_array( $originParts ) || ! isset( $originParts['host'] ) ) {
			return 0;
		}

		if ( ! isset( $originParts['scheme'] ) || ! in_array( strtolower( (string) $originParts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return 0;
		}

		$siteIds = get_sites(
			[
				'domain' => strtolower( (string) $originParts['host'] ),
				'number' => 1,
				'fields' => 'ids',
			]
		);

		$siteId = $siteIds[0] ?? false;

		return false === $siteId ? 0 : (int) $siteId;
	}
}

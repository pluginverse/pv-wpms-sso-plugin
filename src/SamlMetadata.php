<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function get_current_blog_id;
use function register_rest_route;
use OneLogin\Saml2\Metadata as Saml2Metadata;
use WP_Error;
use WP_REST_Response;

/**
 * SP metadata for the identity site: GET /wp-json/pv-wpms-sso/v1/saml/metadata.
 * Served only on the network's identity site (that is the SP); the network
 * settings screen links it for copy-pasting into the IdP application.
 */
final class SamlMetadata {

	public const ROUTE = '/saml/metadata';

	public static function registerRoutes(): void {
		if ( ! \PV\WpmsSso\saml_library_available() ) {
			return;
		}

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

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handleRequest() {
		if ( get_current_blog_id() !== ClientFlow::idpBlogIdForThisSite() ) {
			return new WP_Error(
				'pv_wpms_sso_sp_not_here',
				__( 'SAML metadata is served on the identity site of this network.', 'pv-wpms-sso' ),
				[ 'status' => 404 ]
			);
		}

		$spSettings = SamlProvider::spSettingsArray()['sp'];

		$response = new WP_REST_Response(
			Saml2Metadata::builder( $spSettings ),
			200
		);
		$response->header( 'Content-Type', 'application/samlmetadata+xml' );

		return $response;
	}
}

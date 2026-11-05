<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function defined;
use function get_current_network_id;
use function get_site_url;
use function sprintf;
use WP_CLI;

/**
 * WP-CLI commands: `wp pv-wpms-sso status` and `wp pv-wpms-sso rotate-key`.
 * Registered from the main plugin file when WP_CLI is defined.
 */
final class Commands {

	/**
	 * Show the SSO configuration of a network.
	 *
	 * ## OPTIONS
	 *
	 * [--network_id=<id>]
	 * : Network to inspect. Default: the current network.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-wpms-sso status
	 *     wp pv-wpms-sso status --network_id=2
	 *
	 * @param array<int, string> $args       positional arguments (unused)
	 * @param array<string, string> $assocArgs flags
	 */
	public function status( array $args, array $assocArgs ): void {
		$networkId = isset( $assocArgs['network_id'] ) && is_numeric( $assocArgs['network_id'] )
			? (int) $assocArgs['network_id']
			: get_current_network_id();

		$settings  = Settings::forNetwork( $networkId );
		$idpBlogId = Settings::resolveIdpBlogId( $networkId );
		$keyFingerprint = KeyStore::fingerprint( KeyStore::getForBlog( $idpBlogId ) );

		WP_CLI::log( sprintf( 'Network %d', $networkId ) );
		WP_CLI::log( sprintf( '  enabled: %s', $settings['enabled'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( '  scope: %s', $settings['scope'] ) );
		WP_CLI::log( sprintf( '  button label: %s', '' !== $settings['button_label'] ? $settings['button_label'] : '(default: "Sign in with {network name}")' ) );
		WP_CLI::log( sprintf( '  identity site: blog %d (%s)', $idpBlogId, get_site_url( $idpBlogId ) ) );
		WP_CLI::log( sprintf( '  signing key: %s%s', $keyFingerprint, defined( KeyStore::CONSTANT_NAME ) ? ' (from wp-config constant)' : '' ) );

		$idpSite      = get_blog_details( $idpBlogId );
		$idpNetworkId = $idpSite instanceof \WP_Site ? (int) $idpSite->site_id : $networkId;
		$samlSettings = \PV\WpmsSso\SamlSettings::forNetwork( $idpNetworkId );

		WP_CLI::log( sprintf( '  saml: %s%s', $samlSettings['enabled'] ? 'enabled' : 'disabled', \PV\WpmsSso\SamlSettings::isConfigured( $idpNetworkId ) ? '' : ' (incomplete configuration)' ) );
		if ( '' !== $samlSettings['idp_entity_id'] ) {
			WP_CLI::log( sprintf( '    idp entity: %s', $samlSettings['idp_entity_id'] ) );
		}
		WP_CLI::log( sprintf( '    auto-provision: %s', $samlSettings['auto_provision'] ? 'on (' . $samlSettings['default_role'] . ')' : 'off' ) );
	}

	/**
	 * Rotate the ticket signing key of an identity site.
	 *
	 * ## OPTIONS
	 *
	 * [--idp_blog_id=<id>]
	 * : Blog ID of the identity site. Default: the current network's resolved identity site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pv-wpms-sso rotate-key
	 *     wp pv-wpms-sso rotate-key --idp_blog_id=1
	 *
	 * @param array<int, string> $args       positional arguments (unused)
	 * @param array<string, string> $assocArgs flags
	 */
	public function rotate_key( array $args, array $assocArgs ): void {
		$idpBlogId = isset( $assocArgs['idp_blog_id'] ) && is_numeric( $assocArgs['idp_blog_id'] )
			? (int) $assocArgs['idp_blog_id']
			: Settings::resolveIdpBlogId( get_current_network_id() );

		if ( defined( KeyStore::CONSTANT_NAME ) ) {
			WP_CLI::error( 'PV_WPMS_SSO_KEY is defined in wp-config; rotate the key there instead.' );
		}

		$newKey    = KeyStore::rotateForBlog( $idpBlogId );
		$newKeyFingerprint = KeyStore::fingerprint( $newKey );

		WP_CLI::success( sprintf( 'Rotated the signing key for blog %d. New fingerprint: %s', $idpBlogId, $newKeyFingerprint ) );
	}
}

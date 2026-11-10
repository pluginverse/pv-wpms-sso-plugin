<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_option;
use function defined;
use function constant;
use function get_blog_option;
use function restore_current_blog;
use function switch_to_blog;
use function update_option;

/**
 * The HMAC signing key for SSO tickets, stored as an autoload-off option on the
 * IdP site itself, so every network in the same database can read the same key.
 *
 * A PV_WPMS_SSO_KEY constant in wp-config overrides the stored key.
 */
final class KeyStore {

	public const OPTION_NAME  = 'pv_wpms_sso_key';
	public const CONSTANT_NAME = 'PV_WPMS_SSO_KEY';
	private const KEY_HEX_LENGTH = 64;

	public static function getForBlog( int $idpBlogId ): string {
		$configuredKey = self::configuredKey();
		if ( null !== $configuredKey ) {
			return $configuredKey;
		}

		$storedKey = get_blog_option( $idpBlogId, self::OPTION_NAME, '' );
		if ( is_string( $storedKey ) && self::KEY_HEX_LENGTH === strlen( $storedKey ) ) {
			return $storedKey;
		}

		return self::rotateForBlog( $idpBlogId );
	}

	/**
	 * Generate and store a fresh key. Rotation invalidates every outstanding
	 * ticket (at most 60 seconds of them) for all networks trusting this site.
	 */
	public static function rotateForBlog( int $idpBlogId ): string {
		$configuredKey = self::configuredKey();
		if ( null !== $configuredKey ) {
			_doing_it_wrong(
				__METHOD__,
				'PV_WPMS_SSO_KEY is defined in wp-config; rotating the stored key would have no effect. Change the constant instead.',
				'0.1.0'
			);

			return $configuredKey;
		}

		$newKey = self::generate();

		switch_to_blog( $idpBlogId );
		if ( ! add_option( self::OPTION_NAME, $newKey, '', false ) ) {
			update_option( self::OPTION_NAME, $newKey );
		}
		restore_current_blog();

		return $newKey;
	}

	public static function fingerprint( string $signingKey ): string {
		return substr( hash( 'sha256', 'pv-wpms-sso:fingerprint:' . $signingKey ), 0, 12 );
	}

	private static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	private static function configuredKey(): ?string {
		if ( ! defined( self::CONSTANT_NAME ) ) {
			return null;
		}

		$constantKey = (string) constant( self::CONSTANT_NAME );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $constantKey ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( self::CONSTANT_NAME . ' must be 64 hexadecimal characters (bin2hex(random_bytes(32))).' ),
				'0.1.0'
			);

			return null;
		}

		return $constantKey;
	}
}

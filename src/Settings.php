<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function get_blog_details;
use function get_current_network_id;
use function get_main_site_id;
use function get_network_option;
use function update_network_option;
use WP_Site;

/**
 * Per-network SSO configuration, stored as the network option "pv_wpms_sso".
 *
 * One settings row per network; a network pointing its idp_blog_id at a site of
 * another network (same database) is what makes cross-network SSO work.
 */
final class Settings {

	public const OPTION_NAME = 'pv_wpms_sso';

	public const SCOPE_SUPER_ADMINS  = 'super_admins';
	public const SCOPE_SITE_MEMBERS  = 'site_members';
	public const SCOPE_NETWORK_USERS = 'network_users';

	private const SCOPES = [
		self::SCOPE_SUPER_ADMINS,
		self::SCOPE_SITE_MEMBERS,
		self::SCOPE_NETWORK_USERS,
	];

	/**
	 * @var array{enabled: bool, idp_blog_id: int, scope: string, button_label: string}
	 */
	private const DEFAULTS = [
		'enabled'       => true,
		'idp_blog_id'   => 0,
		'scope'         => self::SCOPE_SUPER_ADMINS,
		'button_label'  => '',
	];

	/**
	 * Pure: merges raw stored input with defaults and validation. No WordPress calls.
	 *
	 * @param array<string, mixed> $rawSettings
	 * @return array{enabled: bool, idp_blog_id: int, scope: string, button_label: string}
	 */
	public static function mergeWithDefaults( array $rawSettings ): array {
		$scope = isset( $rawSettings['scope'] ) && is_string( $rawSettings['scope'] )
			? $rawSettings['scope']
			: '';

		return array_merge( self::DEFAULTS, [
			'enabled'       => (bool) ( $rawSettings['enabled'] ?? self::DEFAULTS['enabled'] ),
			'idp_blog_id'   => self::intSetting( $rawSettings, 'idp_blog_id' ),
			'scope'         => in_array( $scope, self::SCOPES, true ) ? $scope : self::DEFAULTS['scope'],
			'button_label'  => isset( $rawSettings['button_label'] ) && is_string( $rawSettings['button_label'] )
				? trim( $rawSettings['button_label'] )
				: self::DEFAULTS['button_label'],
		] );
	}

	/** @param array<string, mixed> $rawSettings */
	private static function intSetting( array $rawSettings, string $settingName ): int {
		$value = $rawSettings[ $settingName ] ?? 0;

		return max( 0, is_numeric( $value ) ? (int) $value : 0 );
	}

	/**
	 * @return array{enabled: bool, idp_blog_id: int, scope: string, button_label: string}
	 */
	public static function forNetwork( int $networkId ): array {
		$storedSettings = get_network_option( $networkId, self::OPTION_NAME, [] );

		/** @var array<string, mixed> $rawSettings */
		$rawSettings = is_array( $storedSettings ) ? $storedSettings : [];

		return self::mergeWithDefaults( $rawSettings );
	}

	public static function isEnabled( ?int $networkId = null ): bool {
		return self::forNetwork( $networkId ?? get_current_network_id() )['enabled'];
	}

	public static function scope( ?int $networkId = null ): string {
		return self::forNetwork( $networkId ?? get_current_network_id() )['scope'];
	}

	/**
	 * Resolve the blog ID of the identity site for a network: the configured
	 * designated site when it exists and is usable, otherwise the network's
	 * main site (idp_blog_id = 0 means "root site of this network").
	 */
	public static function resolveIdpBlogId( int $networkId ): int {
		$configuredIdpBlogId = self::forNetwork( $networkId )['idp_blog_id'];

		if ( $configuredIdpBlogId > 0 ) {
			$site = get_blog_details( $configuredIdpBlogId );
			if ( $site instanceof WP_Site && self::siteIsUsable( $site ) ) {
				return (int) $site->blog_id;
			}
		}

		return (int) get_main_site_id( $networkId );
	}

	/**
	 * @param array<string, mixed> $settings partial or complete settings;
	 *                                       merged over the stored values
	 */
	public static function save( int $networkId, array $settings ): bool {
		/** @var array<string, mixed> $currentSettings */
		$currentSettings = self::forNetwork( $networkId );

		return update_network_option( $networkId, self::OPTION_NAME, self::mergeWithDefaults( array_merge( $currentSettings, $settings ) ) );
	}

	private static function siteIsUsable( WP_Site $site ): bool {
		return '1' !== (string) $site->deleted
			&& '1' !== (string) $site->archived
			&& '1' !== (string) $site->spam;
	}
}

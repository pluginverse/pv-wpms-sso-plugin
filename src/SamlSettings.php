<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function get_current_network_id;
use function get_network_option;
use function preg_replace;
use function trim;
use function update_network_option;

/**
 * Per-network external SSO (SAML) configuration, stored as the network
 * option "pv_wpms_sso_saml". One IdP per network in v1 (decision 0005).
 *
 * The SAML flow only runs on the network's identity site; these settings
 * describe the IdP application (OneLogin, Okta, ...).
 */
final class SamlSettings {

	public const OPTION_NAME = 'pv_wpms_sso_saml';

	public const DEFAULT_ROLE = 'subscriber';

	/**
	 * @var array{enabled: bool, idp_entity_id: string, idp_sso_url: string, idp_x509cert: string, button_label: string, auto_provision: bool, default_role: string}
	 */
	private const DEFAULTS = [
		'enabled'        => false,
		'idp_entity_id'  => '',
		'idp_sso_url'    => '',
		'idp_x509cert'   => '',
		'button_label'   => '',
		'auto_provision' => false,
		'default_role'   => self::DEFAULT_ROLE,
	];

	/**
	 * Pure: merges raw stored input with defaults and normalization. No WordPress calls.
	 *
	 * @param array<string, mixed> $rawSettings
	 * @return array{enabled: bool, idp_entity_id: string, idp_sso_url: string, idp_x509cert: string, button_label: string, auto_provision: bool, default_role: string}
	 */
	public static function mergeWithDefaults( array $rawSettings ): array {
		return array_merge( self::DEFAULTS, [
			'enabled'        => (bool) ( $rawSettings['enabled'] ?? self::DEFAULTS['enabled'] ),
			'idp_entity_id'  => self::textSetting( $rawSettings, 'idp_entity_id' ),
			'idp_sso_url'    => self::textSetting( $rawSettings, 'idp_sso_url' ),
			'idp_x509cert'   => self::certSetting( $rawSettings ),
			'button_label'   => self::textSetting( $rawSettings, 'button_label' ),
			'auto_provision' => (bool) ( $rawSettings['auto_provision'] ?? self::DEFAULTS['auto_provision'] ),
			'default_role'   => '' !== self::textSetting( $rawSettings, 'default_role' )
				? self::textSetting( $rawSettings, 'default_role' )
				: self::DEFAULT_ROLE,
		] );
	}

	/**
	 * @return array{enabled: bool, idp_entity_id: string, idp_sso_url: string, idp_x509cert: string, button_label: string, auto_provision: bool, default_role: string}
	 */
	public static function forNetwork( int $networkId ): array {
		$storedSettings = get_network_option( $networkId, self::OPTION_NAME, [] );

		/** @var array<string, mixed> $rawSettings */
		$rawSettings = is_array( $storedSettings ) ? $storedSettings : [];

		return self::mergeWithDefaults( $rawSettings );
	}

	/** @param array<string, mixed> $settings */
	public static function save( int $networkId, array $settings ): bool {
		return update_network_option( $networkId, self::OPTION_NAME, self::mergeWithDefaults( $settings ) );
	}

	public static function isEnabled( ?int $networkId = null ): bool {
		return self::forNetwork( $networkId ?? get_current_network_id() )['enabled'];
	}

	/**
	 * Complete enough to run the flow: enabled plus the three IdP application
	 * values (entity ID, SSO redirect URL, signing certificate).
	 */
	public static function isConfigured( ?int $networkId = null ): bool {
		$settings = self::forNetwork( $networkId ?? get_current_network_id() );

		return $settings['enabled']
			&& '' !== $settings['idp_entity_id']
			&& '' !== $settings['idp_sso_url']
			&& '' !== $settings['idp_x509cert'];
	}

	/** @param array<string, mixed> $rawSettings */
	private static function textSetting( array $rawSettings, string $settingName ): string {
		$value = $rawSettings[ $settingName ] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/** @param array<string, mixed> $rawSettings */
	private static function certSetting( array $rawSettings ): string {
		$certificate = self::textSetting( $rawSettings, 'idp_x509cert' );

		// Collapse to the base64 body: IdP dashboards hand out PEM, bare base64,
		// or base64 with line breaks.
		$collapsed = (string) preg_replace( '/-----[^-]+-----|\s+/', '', $certificate );

		return '' === $collapsed ? '' : $collapsed;
	}
}

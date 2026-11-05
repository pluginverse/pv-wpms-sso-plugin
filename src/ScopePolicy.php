<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function __;

/**
 * Pure scope evaluation (decision 0004). The WordPress facts (super admin,
 * site membership, user existence) are gathered at the call site so this class
 * stays unit-testable and the network context of each check stays explicit.
 */
final class ScopePolicy {

	/**
	 * @param array{is_super_admin: bool, is_site_member: bool, user_exists: bool} $facts
	 */
	public static function userPassesScope( string $scope, array $facts ): bool {
		return match ( $scope ) {
			Settings::SCOPE_SUPER_ADMINS => $facts['is_super_admin'],
			Settings::SCOPE_SITE_MEMBERS => $facts['is_super_admin'] || $facts['is_site_member'],
			Settings::SCOPE_NETWORK_USERS => $facts['user_exists'],
			default => false,
		};
	}

	/**
	 * @return array<string, string> scope value => human label
	 */
	public static function scopeChoices(): array {
		return [
			Settings::SCOPE_SUPER_ADMINS  => __( 'Super admins only', 'pv-wpms-sso' ),
			Settings::SCOPE_SITE_MEMBERS  => __( 'Members of the destination site (and super admins)', 'pv-wpms-sso' ),
			Settings::SCOPE_NETWORK_USERS => __( 'Any user of this network', 'pv-wpms-sso' ),
		];
	}
}

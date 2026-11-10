<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function apply_filters;
use function get_blog_details;
use function get_current_blog_id;
use function get_userdata;
use function is_super_admin;
use function is_user_member_of_blog;
use WP_Site;

/**
 * The trust rule shared by every IdP-side decision (decision 0003/0004):
 * a blog is a trusted SSO client only when its own network designates the
 * current site as IdP, and a user passes that network's scope.
 */
final class TrustPolicy {

	public const USER_MAY_SSO_FILTER = 'pv_wpms_sso/user_may_sso';

	/**
	 * SSO GETs are top-level redirects or an origin-gated probe, not WordPress
	 * REST mutations. Core clears nonce-less REST auth; validate the actual
	 * login cookie here without authenticating unrelated REST requests.
	 */
	public static function currentSessionUserId(): int {
		return get_current_user_id() ?: (int) wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * @return int the client blog's network ID, 0 when the blog is unknown or
	 *             its network does not designate this site as its IdP.
	 */
	public static function trustedClientNetworkId( int $clientBlogId ): int {
		$clientSite = get_blog_details( $clientBlogId );
		if ( ! $clientSite instanceof WP_Site || $clientSite->archived || $clientSite->deleted || $clientSite->spam ) {
			return 0;
		}

		$clientNetworkId = (int) $clientSite->site_id;

		if ( ! Settings::isEnabled( $clientNetworkId ) ) {
			return 0;
		}

		if ( Settings::resolveIdpBlogId( $clientNetworkId ) !== get_current_blog_id() ) {
			return 0;
		}

		return $clientNetworkId;
	}

	/**
	 * Scope check against a client network's settings. Super-admincy is
	 * evaluated in the current (IdP) network context — that is where the
	 * user's session lives (decision 0004).
	 */
	public static function userPassesClientScope( int $userId, int $clientBlogId, int $clientNetworkId ): bool {
		$facts = [
			'is_super_admin' => is_super_admin( $userId ),
			'is_site_member' => is_user_member_of_blog( $userId, $clientBlogId ),
			'user_exists'    => (bool) get_userdata( $userId ),
		];

		return (bool) apply_filters(
			self::USER_MAY_SSO_FILTER,
			ScopePolicy::userPassesScope( Settings::scope( $clientNetworkId ), $facts ),
			$userId,
			$clientBlogId
		);
	}
}

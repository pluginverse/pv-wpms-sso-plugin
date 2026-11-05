<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function absint;
use function add_query_arg;
use function apply_filters;
use function array_filter;
use function array_values;
use function get_current_blog_id;
use function get_site_url;
use function restore_current_blog;
use function sanitize_key;
use function switch_to_blog;
use function wp_login_url;
use function wp_redirect;
use function wp_unslash;

/**
 * The SAML login flow's wp-login.php router (login_init):
 *
 * - pv_wpms_sso=saml_start on a client site — create the client state and
 *   hop to the identity site's SAML entry, carrying client_blog_id + state.
 * - action=pv_wpms_sso_saml_start on the identity site — start the SP flow.
 * - action=pv_wpms_sso_saml_acs on the identity site — the IdP's POST target.
 *
 * Every branch no-ops unless the bundled SAML library is present and the
 * identity site's network has external SSO configured.
 */
final class SamlFlow {

	public const START_PARAM_VALUE = 'saml_start';
	public const START_ACTION      = 'pv_wpms_sso_saml_start';

	public static function handleRequest(): void {
		if ( ! \PV\WpmsSso\saml_library_available() ) {
			return;
		}

		$action = sanitize_key( self::queryStringParam( 'action' ) );

		if ( SamlProvider::ACS_ACTION === $action ) {
			if ( self::onIdentitySite() && self::samlConfigured() ) {
				SamlProvider::consumeAcs();
			}

			return;
		}

		if ( self::START_ACTION === $action ) {
			if ( self::onIdentitySite() && self::samlConfigured() ) {
				SamlProvider::start(
					self::clientBlogIdParam(),
					self::queryStringParam( 'state' ),
				);
			}

			return;
		}

		if ( self::START_PARAM_VALUE === self::queryStringParam( ClientFlow::QUERY_PARAM ) ) {
			if ( Settings::isEnabled() && self::samlButtonAvailableForThisSite() ) {
				self::startAtClientSite();
			}
		}
	}

	public static function onIdentitySite(): bool {
		return get_current_blog_id() === ClientFlow::idpBlogIdForThisSite();
	}

	private static function samlConfigured(): bool {
		return SamlSettings::isConfigured();
	}

	private static function clientBlogIdParam(): ?int {
		$clientBlogId = absint( self::queryStringParam( 'client_blog_id' ) );

		return $clientBlogId > 0 ? $clientBlogId : null;
	}

	/**
	 * Client site: same binding as the network SSO start — a state stored on
	 * the client, mirrored in its cookie — then hop to the identity site.
	 */
	private static function startAtClientSite(): never {
		$requestedRedirectTo = self::queryStringParam( 'redirect_to' );
		$allowedRedirectTo   = RedirectGuard::destinationAllowed( $requestedRedirectTo, self::currentSiteUrl(), self::extraAllowedHosts() )
			? $requestedRedirectTo
			: '';

		$createdState = ClientFlow::beginState( $allowedRedirectTo );
		$clientBlogId = get_current_blog_id();
		$idpBlogId    = ClientFlow::idpBlogIdForThisSite();

		switch_to_blog( $idpBlogId );
		$identityEntryUrl = add_query_arg(
			[
				'action'         => self::START_ACTION,
				'client_blog_id' => $clientBlogId,
				'state'          => $createdState['state'],
			],
			wp_login_url()
		);
		restore_current_blog();

		wp_redirect( $identityEntryUrl );
		exit;
	}

	/**
	 * The button shows when the identity site's network has SAML configured;
	 * on client sites the network SSO toggle gates every SSO button.
	 */
	public static function samlButtonAvailableForThisSite(): bool {
		if ( ! \PV\WpmsSso\saml_library_available() || ! Settings::isEnabled() ) {
			return false;
		}

		$idpBlogId     = ClientFlow::idpBlogIdForThisSite();
		$idpSite       = get_blog_details( $idpBlogId );
		$idpNetworkId  = $idpSite instanceof \WP_Site ? (int) $idpSite->site_id : 0;

		return $idpNetworkId > 0 && SamlSettings::isConfigured( $idpNetworkId );
	}

	/** The SAML start URL for the button, correct on both client and IdP sites. */
	public static function startUrl(): string {
		if ( self::onIdentitySite() ) {
			return add_query_arg( 'action', self::START_ACTION, wp_login_url() );
		}

		return add_query_arg(
			[
				ClientFlow::QUERY_PARAM => self::START_PARAM_VALUE,
				'redirect_to'           => self::queryStringParam( 'redirect_to' ),
			],
			wp_login_url()
		);
	}

	private static function currentSiteUrl(): string {
		return get_site_url( get_current_blog_id() );
	}

	/**
	 * @return array<int, string>
	 */
	private static function extraAllowedHosts(): array {
		$extraHosts = apply_filters( RedirectGuard::ALLOWED_HOSTS_FILTER, [] );

		return array_values( array_filter( $extraHosts, 'is_string' ) );
	}

	private static function queryStringParam( string $paramName ): string {
		$value = isset( $_GET[ $paramName ] ) ? wp_unslash( $_GET[ $paramName ] ) : '';

		return is_string( $value ) ? $value : '';
	}
}

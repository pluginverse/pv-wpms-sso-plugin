<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_query_arg;
use function apply_filters;
use function array_filter;
use function array_values;
use function do_action;
use function get_blog_details;
use function get_current_blog_id;
use function get_current_network_id;
use function get_site_url;
use function get_userdata;
use function hash_equals;
use function is_ssl;
use function is_super_admin;
use function is_user_member_of_blog;
use function is_wp_error;
use function restore_current_blog;
use function sanitize_key;
use function switch_to_blog;
use function time;
use function wp_login_url;
use function wp_redirect;
use function wp_safe_redirect;
use function wp_set_auth_cookie;
use function wp_set_current_user;
use function wp_unslash;
use WP_Error;
use WP_Site;

/**
 * The client half of the flow, on wp-login.php:
 * ?pv_wpms_sso=start    — create state, redirect to the IdP's authorize URL.
 * ?pv_wpms_sso=callback — redeem the ticket, set the site's auth cookies.
 */
final class ClientFlow {

	public const QUERY_PARAM     = 'pv_wpms_sso';
	public const ACTION_START    = 'start';
	public const ACTION_CALLBACK = 'callback';

	public const ERROR_INVALID_STATE   = 'invalid_state';
	public const ERROR_INVALID_TICKET  = 'invalid_ticket';
	public const ERROR_REPLAYED_TICKET = 'replayed_ticket';
	public const ERROR_NOT_ALLOWED     = 'not_allowed';

	public static function handleRequest(): void {
		$action = sanitize_key( self::queryStringParam( self::QUERY_PARAM ) );

		if ( ! in_array( $action, [ self::ACTION_START, self::ACTION_CALLBACK ], true ) ) {
			return;
		}

		if ( ! self::ssoAvailableForThisSite() ) {
			return;
		}

		if ( self::ACTION_START === $action ) {
			self::start();
		}

		self::callback();
	}

	public static function idpBlogIdForThisSite(): int {
		return Settings::resolveIdpBlogId( get_current_network_id() );
	}

	/**
	 * SSO is offered on this site when its network enabled it and the site is
	 * not the IdP itself (logging in here already IS the identity session).
	 */
	public static function ssoAvailableForThisSite(): bool {
		return Settings::isEnabled()
			&& self::idpBlogIdForThisSite() !== get_current_blog_id();
	}

	private static function start(): never {
		$requestedRedirectTo = self::queryStringParam( 'redirect_to' );

		$allowedRedirectTo = RedirectGuard::destinationAllowed( $requestedRedirectTo, self::currentSiteUrl(), self::extraAllowedHosts() )
			? $requestedRedirectTo
			: '';

		$createdState = self::beginState( $allowedRedirectTo );

		wp_redirect( self::authorizeUrl( $createdState['state'] ) );
		exit;
	}

	/**
	 * Create the client-side state binding (transient + HttpOnly cookie).
	 * Shared by the network-SSO start and the SAML start.
	 *
	 * @param string $redirectTo validated final destination ('' for default)
	 * @return array{state: string, expires_at: int}
	 */
	public static function beginState( string $redirectTo ): array {
		$createdState = StateStore::create( $redirectTo );

		setcookie(
			StateStore::STATE_COOKIE_NAME,
			$createdState['state'],
			[
				'expires'  => $createdState['expires_at'],
				'path'     => self::currentSiteCookiePath(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		return $createdState;
	}

	private static function callback(): never {
		$errorCode = sanitize_key( self::queryStringParam( 'error' ) );

		if ( '' !== $errorCode ) {
			self::redirectToLoginWithError( $errorCode );
		}

		$stateFromQuery = self::queryStringParam( 'state' );
		$ticketToken    = self::queryStringParam( 'ticket' );
		$stateFromCookie = self::cookieStringParam( StateStore::STATE_COOKIE_NAME );

		$redirectTo = StateStore::consume( $stateFromQuery, $stateFromCookie );
		if ( null === $redirectTo ) {
			self::redirectToLoginWithError( self::ERROR_INVALID_STATE );
		}

		$ticket = self::verifyAndConsumeTicket( $ticketToken, $stateFromQuery );
		if ( is_wp_error( $ticket ) ) {
			$errorCodeFromTicket = $ticket->get_error_code();
			self::redirectToLoginWithError( is_string( $errorCodeFromTicket ) ? $errorCodeFromTicket : self::ERROR_INVALID_TICKET );
		}

		wp_set_current_user( $ticket->userId );
		wp_set_auth_cookie( $ticket->userId, true, is_ssl() );

		/** Fires after a successful SSO login, before the final redirect. */
		do_action( 'pv_wpms_sso/sso_login', $ticket->userId, get_current_blog_id(), $ticket );

		$destination = '' !== $redirectTo && RedirectGuard::destinationAllowed( $redirectTo, self::currentSiteUrl(), self::extraAllowedHosts() )
			? $redirectTo
			: self::currentSiteUrl() . '/wp-admin/';

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Signature, expiry, issuer/audience binding, state binding, and the
	 * single-use claim — everything redeemable without a browser.
	 *
	 * @return Ticket|WP_Error
	 */
	public static function verifyAndConsumeTicket( string $ticketToken, string $state ) {
		$idpBlogId  = self::idpBlogIdForThisSite();
		$signingKey = KeyStore::getForBlog( $idpBlogId );

		try {
			$ticket = TicketSigner::verify( $ticketToken, $signingKey, time() );
		} catch ( InvalidTicketException $exception ) {
			return new WP_Error( self::ERROR_INVALID_TICKET, $exception->getMessage() );
		}

		if ( $ticket->issuerBlogId !== $idpBlogId || $ticket->audienceBlogId !== get_current_blog_id() ) {
			return new WP_Error( self::ERROR_INVALID_TICKET, 'Ticket is not for this IdP/site pair.' );
		}

		if ( ! hash_equals( $state, $ticket->state ) ) {
			return new WP_Error( self::ERROR_INVALID_STATE, 'Ticket state does not match the request state.' );
		}

		$user = get_userdata( $ticket->userId );
		if ( false === $user || ! hash_equals( $user->user_login, $ticket->userLogin ) ) {
			return new WP_Error( self::ERROR_INVALID_TICKET, 'Ticket user does not exist or was renamed.' );
		}

		if ( ! JtiTable::claimTicketId( $ticket->ticketId, $ticket->expiresAt ) ) {
			return new WP_Error( self::ERROR_REPLAYED_TICKET, 'Ticket was already used.' );
		}

		if ( ! self::userMayRedeemHere( $ticket->userId ) ) {
			return new WP_Error( self::ERROR_NOT_ALLOWED, __( 'Destination access was refused.', 'pv-wpms-sso' ) );
		}

		return $ticket;
	}

	/**
	 * Client-side redemption policy (decision 0004): the destination network's
	 * scope, evaluated with WordPress's own access model (super admin of this
	 * network, or member of this site).
	 */
	private static function userMayRedeemHere( int $userId ): bool {
		$facts = [
			'is_super_admin' => is_super_admin( $userId ),
			'is_site_member' => is_user_member_of_blog( $userId, get_current_blog_id() ),
			'user_exists'    => true,
		];

		return (bool) apply_filters(
			TrustPolicy::USER_MAY_SSO_FILTER,
			ScopePolicy::userPassesScope( Settings::scope(), $facts ),
			$userId,
			get_current_blog_id()
		);
	}

	private static function redirectToLoginWithError( string $errorCode ): never {
		wp_safe_redirect(
			add_query_arg(
				[ LoginScreen::ERROR_PARAM => $errorCode ],
				wp_login_url()
			)
		);
		exit;
	}

	private static function authorizeUrl( string $state ): string {
		$clientBlogId = get_current_blog_id();
		$idpBlogId    = self::idpBlogIdForThisSite();

		switch_to_blog( $idpBlogId );
		$authorizeUrl = add_query_arg(
			[
				'client_blog_id' => $clientBlogId,
				'state'          => $state,
			],
			rest_url( AuthorizeEndpoint::REST_NAMESPACE . AuthorizeEndpoint::ROUTE )
		);
		restore_current_blog();

		return $authorizeUrl;
	}

	private static function currentSiteUrl(): string {
		return get_site_url( get_current_blog_id() );
	}

	private static function currentSiteCookiePath(): string {
		$currentSite = get_blog_details( get_current_blog_id() );

		return $currentSite instanceof WP_Site ? (string) $currentSite->path : '/';
	}

	private static function queryStringParam( string $paramName ): string {
		$value = isset( $_GET[ $paramName ] ) ? wp_unslash( $_GET[ $paramName ] ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Extra redirect hosts for this site, via the
	 * pv_wpms_sso/allowed_redirect_hosts filter (empty by default).
	 *
	 * @return array<int, string>
	 */
	private static function extraAllowedHosts(): array {
		/** Filter documenting the accepted shape: list of lowercase "host" or "host:port" values. */
		$extraHosts = apply_filters( RedirectGuard::ALLOWED_HOSTS_FILTER, [] );

		return array_values( array_filter( $extraHosts, 'is_string' ) );
	}

	private static function cookieStringParam( string $cookieName ): string {
		$value = isset( $_COOKIE[ $cookieName ] ) ? wp_unslash( $_COOKIE[ $cookieName ] ) : '';

		return is_string( $value ) ? $value : '';
	}
}

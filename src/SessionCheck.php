<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function add_query_arg;
use function apply_filters;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function get_current_blog_id;
use function get_site_url;
use function get_transient;
use function hash;
use function hash_equals;
use function is_ssl;
use function is_user_logged_in;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rawurlencode;
use function set_transient;
use function strtr;
use function time;
use function wp_login_url;
use function wp_redirect;

/**
 * Decision 0012: before a client site's login page renders for an anonymous
 * visitor, ask the identity site once through a top-level round trip
 * (authorize with prompt=none). A session in scope comes back as a ticket;
 * anything else comes back quietly and the page shows no network button.
 *
 * State lives in an HttpOnly cookie, not a transient: anonymous traffic to
 * wp-login.php must not write to the database.
 */
final class SessionCheck {

	public const COOKIE_NAME    = 'pv_wpms_sso_check';
	public const RESULT_PARAM   = 'pv_wpms_sso_checked';
	public const RESULT_NONE    = 'none';
	public const ENABLED_FILTER = 'pv_wpms_sso/check_session_on_login_page';
	public const HINT_TRANSIENT_PREFIX = 'pv_wpms_sso_hint_';

	private const COOKIE_TTL_SECONDS = 120;
	private const HINT_TTL_SECONDS   = 600;
	private const MAX_REDIRECT_LENGTH = 1500;

	public static function enabledForThisSite(): bool {
		return ClientFlow::ssoAvailableForThisSite()
			&& (bool) apply_filters( self::ENABLED_FILTER, true, get_current_blog_id() );
	}

	/** login_init, after the flow handlers: they exit when the request is theirs. */
	public static function handleRequest(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		/** @var array<string, mixed> $query */
		$query = $_GET;
		if ( is_user_logged_in() || ! self::requestIsPlainLoginForm( $method, $query ) || ! self::enabledForThisSite() ) {
			return;
		}

		$requestedRedirectTo = isset( $query['redirect_to'] ) && is_string( $query['redirect_to'] ) ? wp_unslash( $query['redirect_to'] ) : '';
		$redirectTo = strlen( $requestedRedirectTo ) <= self::MAX_REDIRECT_LENGTH
			&& RedirectGuard::destinationAllowed( $requestedRedirectTo, get_site_url( get_current_blog_id() ), ClientFlow::extraAllowedHosts() )
			? $requestedRedirectTo
			: '';
		$afterLogout = isset( $query['loggedout'] );
		$autoSignIn  = Settings::autoSignIn() && ! $afterLogout && ! isset( $query['reauth'] );

		$state = bin2hex( random_bytes( 32 ) );
		setcookie(
			self::COOKIE_NAME,
			self::encodeCookie( $state, $redirectTo, $autoSignIn, $afterLogout ),
			[
				'expires'  => time() + self::COOKIE_TTL_SECONDS,
				'path'     => ClientFlow::currentSiteCookiePath(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		nocache_headers();
		wp_redirect( ClientFlow::authorizeUrl( $state, true ) );
		exit;
	}

	/**
	 * Pure: only an anonymous GET of the default login form is checked. Flow
	 * callbacks, results, errors, other wp-login actions, and the login iframe
	 * are left alone, which is also what prevents a redirect loop.
	 *
	 * @param array<string, mixed> $query
	 */
	public static function requestIsPlainLoginForm( string $method, array $query ): bool {
		if ( 'GET' !== strtoupper( $method ) ) {
			return false;
		}
		$action = $query['action'] ?? '';
		if ( '' !== $action && 'login' !== $action ) {
			return false;
		}
		foreach ( [ ClientFlow::QUERY_PARAM, self::RESULT_PARAM, LoginScreen::ERROR_PARAM, 'interim-login', 'customize-login', 'checkemail', 'key' ] as $paramName ) {
			if ( isset( $query[ $paramName ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function encodeCookie( string $state, string $redirectTo, bool $autoSignIn, bool $afterLogout ): string {
		$json = json_encode( [ 's' => $state, 'r' => $redirectTo, 'a' => $autoSignIn, 'l' => $afterLogout ] );

		return rtrim( strtr( base64_encode( is_string( $json ) ? $json : '' ), '+/', '-_' ), '=' );
	}

	/**
	 * The pending check this callback belongs to, or null when the callback is
	 * an ordinary button flow (or the cookie is absent, malformed, or for
	 * another state).
	 *
	 * @return array{redirect_to: string, auto_sign_in: bool, after_logout: bool}|null
	 */
	public static function pending( string $cookieValue, string $stateFromQuery ): ?array {
		if ( '' === $cookieValue || '' === $stateFromQuery ) {
			return null;
		}
		$json    = base64_decode( strtr( $cookieValue, '-_', '+/' ), true );
		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $decoded ) || ! isset( $decoded['s'], $decoded['r'], $decoded['a'], $decoded['l'] )
			|| ! is_string( $decoded['s'] ) || ! is_string( $decoded['r'] ) || ! is_bool( $decoded['a'] ) || ! is_bool( $decoded['l'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $decoded['s'] ) || ! hash_equals( $decoded['s'], $stateFromQuery ) ) {
			return null;
		}

		return [ 'redirect_to' => $decoded['r'], 'auto_sign_in' => $decoded['a'], 'after_logout' => $decoded['l'] ];
	}

	public static function clearCookie(): void {
		setcookie(
			self::COOKIE_NAME,
			'',
			[
				'expires'  => time() - 3600,
				'path'     => ClientFlow::currentSiteCookiePath(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/** Remember who the identity site vouched for, for the "Continue as" button. */
	public static function storeHint( Ticket $ticket ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::HINT_TRANSIENT_PREFIX . hash( 'sha256', $token ),
			[ 'display_name' => $ticket->displayName, 'avatar_url' => $ticket->avatarUrl ],
			self::HINT_TTL_SECONDS
		);

		return $token;
	}

	/** @return array{display_name: string, avatar_url: string}|null */
	public static function hint( string $token ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return null;
		}
		$stored = get_transient( self::HINT_TRANSIENT_PREFIX . hash( 'sha256', $token ) );
		if ( ! is_array( $stored ) || ! isset( $stored['display_name'], $stored['avatar_url'] )
			|| ! is_string( $stored['display_name'] ) || ! is_string( $stored['avatar_url'] ) ) {
			return null;
		}

		return [ 'display_name' => $stored['display_name'], 'avatar_url' => $stored['avatar_url'] ];
	}

	/**
	 * The login form to show after a check, carrying its result so the form
	 * is not checked again.
	 *
	 * @param array{redirect_to: string, auto_sign_in: bool, after_logout: bool} $pending
	 */
	public static function loginUrl( array $pending, string $result ): string {
		$arguments = [ self::RESULT_PARAM => $result ];
		if ( '' !== $pending['redirect_to'] ) {
			$arguments['redirect_to'] = rawurlencode( $pending['redirect_to'] );
		}
		if ( $pending['after_logout'] ) {
			$arguments['loggedout'] = 'true';
		}

		return add_query_arg( $arguments, wp_login_url() );
	}
}

<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use WP_Error;
use WP_User;

/** Proves a completed source login to the hub; never grants site membership. */
final class LocalSessionBridge {

	public const TRANSIENT_PREFIX = 'pv_wpms_sso_bridge_';
	public const STATE_COOKIE = 'pv_wpms_sso_bridge_state';
	private const LIFETIME_SECONDS = 600;

	/** @param WP_User|WP_Error $user */
	public static function filterLoginRedirect( string $redirectTo, string $requestedRedirectTo, $user ): string {
		if ( ! $user instanceof WP_User || ! ClientFlow::ssoAvailableForThisSite() ) {
			return $redirectTo;
		}
		$sourceBlogId = get_current_blog_id();
		if ( ! RedirectGuard::destinationAllowed( $redirectTo, get_site_url( $sourceBlogId ) ) ) {
			return $redirectTo;
		}
		$hubBlogId = ClientFlow::idpBlogIdForThisSite();
		$url = self::loginUrl( $hubBlogId, [
			'pv_wpms_sso' => 'bridge_start',
			'source_blog_id' => $sourceBlogId,
			'user_id' => $user->ID,
			'return_to' => $redirectTo,
		] );
		RedirectGuard::allowSiteRedirect( $url, $hubBlogId );
		return $url;
	}

	/** @return array{state:string,expires_at:int,redirect_to:string}|WP_Error */
	public static function begin( int $sourceBlogId, int $expectedUserId, string $returnTo ): array|WP_Error {
		if ( $sourceBlogId === get_current_blog_id() || 0 === TrustPolicy::trustedClientNetworkId( $sourceBlogId )
			|| ! get_userdata( $expectedUserId ) || ! RedirectGuard::destinationAllowed( $returnTo, get_site_url( $sourceBlogId ) ) ) {
			return self::error();
		}
		$state = bin2hex( random_bytes( 32 ) );
		$expiresAt = time() + self::LIFETIME_SECONDS;
		$returnTo = '' === $returnTo ? get_site_url( $sourceBlogId ) . '/wp-admin/' : $returnTo;
		$sourceUrl = get_site_url( $sourceBlogId );
		if ( str_starts_with( $returnTo, '//' ) ) {
			$returnTo = wp_parse_url( $sourceUrl, PHP_URL_SCHEME ) . ':' . $returnTo;
		} elseif ( str_starts_with( $returnTo, '/' ) ) {
			$port = wp_parse_url( $sourceUrl, PHP_URL_PORT );
			$returnTo = wp_parse_url( $sourceUrl, PHP_URL_SCHEME ) . '://' . wp_parse_url( $sourceUrl, PHP_URL_HOST )
				. ( null !== $port ? ':' . $port : '' ) . $returnTo;
		} elseif ( ! RedirectGuard::urlMatchesSiteHost( $returnTo, $sourceUrl ) ) {
			return self::error();
		}
		set_transient( self::stateKey( $state ), [
			'source_blog_id' => $sourceBlogId,
			'user_id' => $expectedUserId,
			'return_to' => $returnTo,
			'expires_at' => $expiresAt,
		], self::LIFETIME_SECONDS );
		return [ 'state' => $state, 'expires_at' => $expiresAt, 'redirect_to' => self::loginUrl( $sourceBlogId, [
			'pv_wpms_sso' => 'bridge_prove', 'hub_blog_id' => get_current_blog_id(), 'state' => $state,
		] ) ];
	}

	/** @return string|WP_Error signed proof; requires a real post-login browser cookie */
	public static function prove( int $hubBlogId, string $state ): string|WP_Error {
		$sourceBlogId = get_current_blog_id();
		if ( ! Settings::isEnabled() || $hubBlogId !== ClientFlow::idpBlogIdForThisSite() || $sourceBlogId === $hubBlogId ) {
			return self::error();
		}
		switch_to_blog( $hubBlogId );
		$transaction = self::transaction( $state );
		restore_current_blog();
		$cookieUserId = wp_validate_auth_cookie( '', 'logged_in' );
		if ( null === $transaction || $transaction['source_blog_id'] !== $sourceBlogId || ! $cookieUserId
			|| $transaction['user_id'] !== $cookieUserId || get_current_user_id() !== $cookieUserId ) {
			return self::error();
		}
		$user = get_userdata( $cookieUserId );
		if ( ! $user instanceof WP_User ) {
			return self::error();
		}
		$ticket = new Ticket(
			issuerBlogId: $sourceBlogId, audienceBlogId: $hubBlogId, userId: $user->ID,
			userLogin: $user->user_login, userEmail: $user->user_email, displayName: $user->display_name,
			avatarUrl: '', state: $state, ticketId: bin2hex( random_bytes( 16 ) ),
			issuedAt: time(), expiresAt: time() + TicketSigner::TICKET_TTL_SECONDS,
		);
		return TicketSigner::issue( $ticket, self::proofKey( $hubBlogId ) );
	}

	/** @return array{user_id:int,redirect_to:string}|WP_Error */
	public static function complete( string $state, string $cookieState, string $proof ): array|WP_Error {
		if ( '' === $state || ! hash_equals( $state, $cookieState ) ) {
			return self::error();
		}
		$transaction = self::transaction( $state );
		if ( null === $transaction || 0 === TrustPolicy::trustedClientNetworkId( $transaction['source_blog_id'] ) ) {
			return self::error();
		}
		try {
			$ticket = TicketSigner::verify( $proof, self::proofKey( get_current_blog_id() ), time() );
		} catch ( InvalidTicketException | \JsonException $exception ) {
			return self::error();
		}
		$user = get_userdata( $ticket->userId );
		if ( $ticket->issuerBlogId !== $transaction['source_blog_id'] || $ticket->audienceBlogId !== get_current_blog_id()
			|| $ticket->userId !== $transaction['user_id'] || ! hash_equals( $state, $ticket->state )
			|| ! $user instanceof WP_User || ! hash_equals( $user->user_login, $ticket->userLogin )
			|| ! RedirectGuard::destinationAllowed( $transaction['return_to'], get_site_url( $transaction['source_blog_id'] ) ) ) {
			return self::error();
		}
		$hubUserId = get_current_user_id();
		if ( 0 !== $hubUserId && $hubUserId !== $ticket->userId ) {
			return new WP_Error( 'bridge_account_conflict', __( 'A different account is signed into the identity site. Sign out there before trying SSO again. Your local sign-in is still active.', 'pv-wpms-sso' ) );
		}
		// Claim the transaction atomically, not just the proof: concurrent proofs for
		// the same browser transaction must not both establish sessions.
		if ( ! JtiTable::claimTicketId( 'local-session:' . $state, $transaction['expires_at'] ) ) {
			return self::error();
		}
		delete_transient( self::stateKey( $state ) );
		return [ 'user_id' => $ticket->userId, 'redirect_to' => $transaction['return_to'] ];
	}

	public static function handleRequest(): void {
		$action = self::query( 'pv_wpms_sso' );
		if ( ! in_array( $action, [ 'bridge_start', 'bridge_prove', 'bridge_complete' ], true ) ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		if ( 'bridge_start' === $action ) {
			$sourceBlogId = (int) self::query( 'source_blog_id' );
			$result = self::begin( $sourceBlogId, (int) self::query( 'user_id' ), self::query( 'return_to' ) );
			if ( ! is_wp_error( $result ) ) {
				self::stateCookie( $result['state'], $result['expires_at'] );
				self::redirect( $result['redirect_to'], $sourceBlogId );
			}
		} elseif ( 'bridge_prove' === $action ) {
			$hubBlogId = (int) self::query( 'hub_blog_id' );
			$result = self::prove( $hubBlogId, self::query( 'state' ) );
			if ( ! is_wp_error( $result ) ) {
				self::redirect( self::loginUrl( $hubBlogId, [ 'pv_wpms_sso' => 'bridge_complete', 'state' => self::query( 'state' ), 'proof' => $result ] ), $hubBlogId );
			}
		} else {
			$cookie = $_COOKIE[ self::STATE_COOKIE ] ?? '';
			$transaction = self::transaction( self::query( 'state' ) );
			$result = self::complete( self::query( 'state' ), is_string( $cookie ) ? wp_unslash( $cookie ) : '', self::query( 'proof' ) );
			if ( ! is_wp_error( $result ) && null !== $transaction ) {
				self::stateCookie( '', time() - 3600 );
				wp_set_current_user( $result['user_id'] );
				wp_set_auth_cookie( $result['user_id'], true, is_ssl() );
				self::redirect( $result['redirect_to'], $transaction['source_blog_id'] );
			}
		}
		$error = is_wp_error( $result ) ? $result : self::error();
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'Single sign-on setup failed', 'pv-wpms-sso' ), [ 'response' => 403 ] );
	}

	/** @param array<string,int|string> $arguments */
	private static function loginUrl( int $blogId, array $arguments ): string {
		return add_query_arg( $arguments, get_site_url( $blogId ) . '/wp-login.php' );
	}

	/** @return array{source_blog_id:int,user_id:int,return_to:string,expires_at:int}|null */
	private static function transaction( string $state ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state ) ) {
			return null;
		}
		$stored = get_transient( self::stateKey( $state ) );
		if ( ! is_array( $stored ) || ! isset( $stored['source_blog_id'], $stored['user_id'], $stored['return_to'], $stored['expires_at'] )
			|| ! is_int( $stored['source_blog_id'] ) || ! is_int( $stored['user_id'] ) || ! is_string( $stored['return_to'] )
			|| ! is_int( $stored['expires_at'] ) || $stored['expires_at'] <= time() ) {
			return null;
		}
		return [ 'source_blog_id' => $stored['source_blog_id'], 'user_id' => $stored['user_id'], 'return_to' => $stored['return_to'], 'expires_at' => $stored['expires_at'] ];
	}

	private static function stateKey( string $state ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $state );
	}

	private static function proofKey( int $hubBlogId ): string {
		return hash_hmac( 'sha256', 'pv-wpms-sso/local-session/v1', KeyStore::getForBlog( $hubBlogId ) );
	}

	private static function error(): WP_Error {
		return new WP_Error( 'bridge_failed', __( 'Single sign-on setup could not be completed. Your local sign-in is still active. Return to your original site, or sign in at the identity site to use SSO.', 'pv-wpms-sso' ) );
	}

	private static function query( string $name ): string {
		$value = $_GET[ $name ] ?? '';
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	private static function stateCookie( string $value, int $expiresAt ): void {
		setcookie( self::STATE_COOKIE, $value, [ 'expires' => $expiresAt, 'path' => get_site()->path ?? '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	private static function redirect( string $url, int $blogId ): never {
		if ( ! RedirectGuard::destinationAllowed( $url, get_site_url( $blogId ) ) ) {
			wp_die( esc_html__( 'Invalid SSO destination.', 'pv-wpms-sso' ) );
		}
		RedirectGuard::allowSiteRedirect( $url, $blogId );
		wp_safe_redirect( $url );
		exit;
	}
}

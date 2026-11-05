<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function bin2hex;
use function hash;
use function hash_equals;
use function random_bytes;
use function set_transient;
use function get_transient;
use function delete_transient;
use function time;

/**
 * Client-side state binding: one random value, mirrored in a transient and an
 * HttpOnly cookie, must match when the user comes back from the IdP. That
 * closes login CSRF: an attacker cannot complete the callback for a state the
 * victim's browser does not hold.
 */
final class StateStore {

	public const STATE_COOKIE_NAME  = 'pv_wpms_sso_state';
	public const STATE_TTL_SECONDS  = 600;
	public const TRANSIENT_PREFIX   = 'pv_wpms_sso_state_';

	/**
	 * @return array{state: string, expires_at: int}
	 */
	public static function create( string $redirectTo ): array {
		$state     = bin2hex( random_bytes( 32 ) );
		$expiresAt = time() + self::STATE_TTL_SECONDS;

		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $state ),
			[
				'redirect_to' => $redirectTo,
				'expires_at'  => $expiresAt,
			],
			self::STATE_TTL_SECONDS
		);

		return [ 'state' => $state, 'expires_at' => $expiresAt ];
	}

	/**
	 * Validate and consume: query state and cookie state must match, the
	 * transient must exist and be unexpired.
	 *
	 * @return string|null the validated redirect target ('' when none stored), null on failure
	 */
	public static function consume( string $stateFromQuery, string $stateFromCookie ): ?string {
		if ( '' === $stateFromQuery || '' === $stateFromCookie ) {
			return null;
		}

		if ( ! hash_equals( $stateFromQuery, $stateFromCookie ) ) {
			return null;
		}

		$transientKey  = self::TRANSIENT_PREFIX . hash( 'sha256', $stateFromQuery );
		$storedPayload = get_transient( $transientKey );
		if ( ! is_array( $storedPayload ) ) {
			return null;
		}

		delete_transient( $transientKey );

		$expiresAt = $storedPayload['expires_at'] ?? 0;
		$redirectTo = $storedPayload['redirect_to'] ?? '';
		if ( ! is_int( $expiresAt ) || $expiresAt < time() ) {
			return null;
		}

		return is_string( $redirectTo ) ? $redirectTo : '';
	}
}

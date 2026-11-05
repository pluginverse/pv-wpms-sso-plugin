<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function hash_equals;
use function hash_hmac;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Issues and verifies HMAC-SHA256 signed SSO tickets.
 *
 * Token shape: base64url(json payload) + "." + base64url(hmac_sha256(key, payload)).
 * Signature and expiry are checked here; issuer, audience, state, and single-use
 * (jti) are the redeeming side's job, because they need request context.
 */
final class TicketSigner {

	public const TICKET_TTL_SECONDS = 60;
	private const CLOCK_SKEW_SECONDS = 90;

	public static function issue( Ticket $ticket, string $signingKey ): string {
		$payload   = self::base64UrlEncode( json_encode( $ticket->toClaims(), JSON_THROW_ON_ERROR ) );
		$signature = self::base64UrlEncode( hash_hmac( 'sha256', $payload, $signingKey, true ) );

		return $payload . '.' . $signature;
	}

	/**
	 * @throws InvalidTicketException when the token is malformed, the signature
	 * does not match the key, or it is expired / not yet valid at $now.
	 */
	public static function verify( string $token, string $signingKey, int $now ): Ticket {
		$tokenParts = explode( '.', $token );
		if ( 2 !== count( $tokenParts ) ) {
			throw InvalidTicketException::forReason( InvalidTicketException::REASON_MALFORMED, 'expected two dot-separated parts' );
		}

		[ $payloadPart, $signaturePart ] = $tokenParts;

		$expectedSignature = hash_hmac( 'sha256', $payloadPart, $signingKey, true );
		if ( ! hash_equals( $expectedSignature, self::base64UrlDecode( $signaturePart ) ) ) {
			throw InvalidTicketException::forReason( InvalidTicketException::REASON_SIGNATURE );
		}

		$decodedClaims = json_decode( self::base64UrlDecode( $payloadPart ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decodedClaims ) ) {
			throw InvalidTicketException::forReason( InvalidTicketException::REASON_MALFORMED, 'payload is not a JSON object' );
		}

		/** @var array<string, mixed> $claims */
		$claims = $decodedClaims;
		$ticket = Ticket::fromClaims( $claims );

		if ( $ticket->issuedAt > $now + self::CLOCK_SKEW_SECONDS ) {
			throw InvalidTicketException::forReason( InvalidTicketException::REASON_NOT_YET_VALID );
		}

		if ( $ticket->expiresAt < $now ) {
			throw InvalidTicketException::forReason( InvalidTicketException::REASON_EXPIRED );
		}

		return $ticket;
	}

	private static function base64UrlEncode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), [ '+' => '-', '/' => '_', '=' => '' ] ), '=' );
	}

	private static function base64UrlDecode( string $encoded ): string {
		$decoded = base64_decode( strtr( $encoded, [ '-' => '+', '_' => '/' ] ), true );

		return is_string( $decoded ) ? $decoded : '';
	}
}

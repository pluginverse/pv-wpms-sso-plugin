<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\InvalidTicketException;
use PV\WpmsSso\Ticket;
use PV\WpmsSso\TicketSigner;

final class TicketSignerTest extends TestCase {

	private const SIGNING_KEY = '6a1f2c0d9b8e4f7a3c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f60718';

	private function buildTicket( ?int $issuedAt = null, ?int $expiresAt = null ): Ticket {
		$issuedAt ??= 1_760_000_000;

		return new Ticket(
			issuerBlogId: 1,
			audienceBlogId: 5,
			userId: 42,
			userLogin: 'admin',
			userEmail: 'admin@example.org',
			displayName: 'Network Admin',
			avatarUrl: 'https://example.org/avatar.jpg',
			state: 'client-state-value',
			ticketId: 'ticket-identifier-001',
			issuedAt: $issuedAt,
			expiresAt: $expiresAt ?? ( $issuedAt + TicketSigner::TICKET_TTL_SECONDS ),
		);
	}

	public function testRoundTripReturnsIdenticalClaims(): void {
		$ticket = $this->buildTicket();
		$token  = TicketSigner::issue( $ticket, self::SIGNING_KEY );

		$verified = TicketSigner::verify( $token, self::SIGNING_KEY, $ticket->issuedAt + 10 );

		$this->assertSame( $ticket->toClaims(), $verified->toClaims() );
	}

	public function testTamperedPayloadFailsSignature(): void {
		$ticket = $this->buildTicket();
		$token  = TicketSigner::issue( $ticket, self::SIGNING_KEY );

		[ $payloadPart, $signaturePart ] = explode( '.', $token );
		$forgedClaimsJson               = base64_decode( strtr( $payloadPart, [ '-' => '+', '_' => '/' ] ), true );
		self::assertIsString( $forgedClaimsJson );

		$claims = json_decode( $forgedClaimsJson, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $claims );
		$claims['sub'] = 1; // escalate to user 1

		$forgedPayload = rtrim( strtr( base64_encode( (string) json_encode( $claims, JSON_THROW_ON_ERROR ) ), [ '+' => '-', '/' => '_', '=' => '' ] ), '=' );
		$forgedToken   = $forgedPayload . '.' . $signaturePart;

		$this->expectException( InvalidTicketException::class );

		TicketSigner::verify( $forgedToken, self::SIGNING_KEY, $ticket->issuedAt + 1 );
	}

	public function testWrongKeyFailsSignature(): void {
		$ticket = $this->buildTicket();
		$token  = TicketSigner::issue( $ticket, self::SIGNING_KEY );

		$this->expectException( InvalidTicketException::class );
		$this->expectExceptionMessage( InvalidTicketException::REASON_SIGNATURE );

		TicketSigner::verify( $token, str_repeat( 'ab', 32 ), $ticket->issuedAt + 1 );
	}

	public function testExpiredTicketIsRejected(): void {
		$ticket = $this->buildTicket( issuedAt: 1_000_000, expiresAt: 1_000_060 );
		$token  = TicketSigner::issue( $ticket, self::SIGNING_KEY );

		$this->expectException( InvalidTicketException::class );
		$this->expectExceptionMessage( InvalidTicketException::REASON_EXPIRED );

		TicketSigner::verify( $token, self::SIGNING_KEY, now: 1_000_061 );
	}

	public function testTicketIssuedFarInTheFutureIsRejected(): void {
		$now    = 1_000_000;
		$ticket = $this->buildTicket( issuedAt: $now + 500, expiresAt: $now + 560 );
		$token  = TicketSigner::issue( $ticket, self::SIGNING_KEY );

		$this->expectException( InvalidTicketException::class );
		$this->expectExceptionMessage( InvalidTicketException::REASON_NOT_YET_VALID );

		TicketSigner::verify( $token, self::SIGNING_KEY, now: $now );
	}

	public function testGarbageTokenIsMalformed(): void {
		$this->expectException( InvalidTicketException::class );

		TicketSigner::verify( 'not-a-ticket', self::SIGNING_KEY, 1_000_000 );
	}

	public function testNonObjectPayloadIsMalformed(): void {
		$payload   = self::base64UrlEncode( '"just a string"' );
		$signature = self::base64UrlEncode( hash_hmac( 'sha256', $payload, self::SIGNING_KEY, true ) );

		$this->expectException( InvalidTicketException::class );
		$this->expectExceptionMessage( InvalidTicketException::REASON_MALFORMED );

		TicketSigner::verify( $payload . '.' . $signature, self::SIGNING_KEY, 1_000_000 );
	}

	public function testMissingClaimIsMalformed(): void {
		$claims    = $this->buildTicket()->toClaims();
		unset( $claims['exp'] );
		$payload   = self::base64UrlEncode( (string) json_encode( $claims, JSON_THROW_ON_ERROR ) );
		$signature = self::base64UrlEncode( hash_hmac( 'sha256', $payload, self::SIGNING_KEY, true ) );

		$this->expectException( InvalidTicketException::class );
		$this->expectExceptionMessage( 'missing claim "exp"' );

		TicketSigner::verify( $payload . '.' . $signature, self::SIGNING_KEY, 1_000_000 );
	}

	public function testExceptionExposesItsReason(): void {
		$exception = InvalidTicketException::forReason( InvalidTicketException::REASON_SIGNATURE, 'detail here' );

		self::assertSame( InvalidTicketException::REASON_SIGNATURE, $exception->reason );
		self::assertSame( 'Invalid SSO ticket (signature): detail here', $exception->getMessage() );
	}

	private static function base64UrlEncode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), [ '+' => '-', '/' => '_', '=' => '' ] ), '=' );
	}
}

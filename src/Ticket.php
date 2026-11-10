<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function is_numeric;
use function is_scalar;

/**
 * Immutable SSO ticket. Wire claims are set by the IdP (iss) for one
 * destination site (aud) and one client state value.
 */
final class Ticket {

	public function __construct(
		public readonly int $issuerBlogId,
		public readonly int $audienceBlogId,
		public readonly int $userId,
		public readonly string $userLogin,
		public readonly string $userEmail,
		public readonly string $displayName,
		public readonly string $avatarUrl,
		public readonly string $state,
		public readonly string $ticketId,
		public readonly int $issuedAt,
		public readonly int $expiresAt,
	) {
	}

	/**
	 * @return array<string, int|string>
	 */
	public function toClaims(): array {
		return [
			'iss'     => $this->issuerBlogId,
			'aud'     => $this->audienceBlogId,
			'sub'     => $this->userId,
			'login'   => $this->userLogin,
			'email'   => $this->userEmail,
			'display' => $this->displayName,
			'avatar'  => $this->avatarUrl,
			'state'   => $this->state,
			'jti'     => $this->ticketId,
			'iat'     => $this->issuedAt,
			'exp'     => $this->expiresAt,
		];
	}

	/**
	 * @param array<string, mixed> $claims
	 * @throws InvalidTicketException when any claim is missing or the wrong type.
	 */
	public static function fromClaims( array $claims ): self {
		$requiredIntClaims  = [ 'iss', 'aud', 'sub', 'iat', 'exp' ];
		$requiredTextClaims = [ 'login', 'email', 'display', 'avatar', 'state', 'jti' ];

		foreach ( [ ...$requiredIntClaims, ...$requiredTextClaims ] as $claimName ) {
			if ( ! array_key_exists( $claimName, $claims ) ) {
				throw InvalidTicketException::forReason( InvalidTicketException::REASON_MALFORMED, 'missing claim "' . $claimName . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is handled at its output boundary.
			}
		}

		return new self(
			issuerBlogId: self::intClaim( $claims, 'iss' ),
			audienceBlogId: self::intClaim( $claims, 'aud' ),
			userId: self::intClaim( $claims, 'sub' ),
			userLogin: self::textClaim( $claims, 'login' ),
			userEmail: self::textClaim( $claims, 'email' ),
			displayName: self::textClaim( $claims, 'display' ),
			avatarUrl: self::textClaim( $claims, 'avatar' ),
			state: self::textClaim( $claims, 'state' ),
			ticketId: self::textClaim( $claims, 'jti' ),
			issuedAt: self::intClaim( $claims, 'iat' ),
			expiresAt: self::intClaim( $claims, 'exp' ),
		);
	}

	/** @param array<string, mixed> $claims */
	private static function intClaim( array $claims, string $claimName ): int {
		$value = $claims[ $claimName ];

		return is_numeric( $value )
			? (int) $value
			: throw InvalidTicketException::forReason( InvalidTicketException::REASON_MALFORMED, 'claim "' . $claimName . '" is not an integer' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is handled at its output boundary.
	}

	/** @param array<string, mixed> $claims */
	private static function textClaim( array $claims, string $claimName ): string {
		$value = $claims[ $claimName ];

		return is_scalar( $value )
			? (string) $value
			: throw InvalidTicketException::forReason( InvalidTicketException::REASON_MALFORMED, 'claim "' . $claimName . '" is not a string' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is handled at its output boundary.
	}
}

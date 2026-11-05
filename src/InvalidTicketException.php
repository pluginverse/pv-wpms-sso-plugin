<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

final class InvalidTicketException extends \RuntimeException {

	public const REASON_MALFORMED     = 'malformed';
	public const REASON_SIGNATURE     = 'signature';
	public const REASON_EXPIRED       = 'expired';
	public const REASON_NOT_YET_VALID = 'not_yet_valid';

	public function __construct(
		public readonly string $reason,
		string $message = '',
	) {
		parent::__construct( '' !== $message ? $message : 'Invalid SSO ticket (' . $reason . ')' );
	}

	public static function forReason( string $reason, string $detail = '' ): self {
		return new self(
			$reason,
			'' !== $detail
				? sprintf( 'Invalid SSO ticket (%s): %s', $reason, $detail )
				: ''
		);
	}
}

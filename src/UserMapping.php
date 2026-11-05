<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function get_user_by;
use function sanitize_user;
use function str_contains;
use function username_exists;
use function wp_generate_password;
use function wp_insert_user;

/**
 * Maps a validated SAML assertion to a WordPress user (decision 0007):
 * exact email match first; provisioning only when the network opted in.
 */
final class UserMapping {

	private const EMAIL_ATTRIBUTE_NAMES = [
		'email',
		'mail',
		'user.email',
		'urn:oid:0.9.2342.19200300.100.1.3',
	];

	private const DISPLAY_NAME_ATTRIBUTE_NAMES = [ 'displayname' ];
	private const FIRST_NAME_ATTRIBUTE_NAMES   = [ 'firstname', 'givenname', 'urn:oid:2.5.4.42' ];
	private const LAST_NAME_ATTRIBUTE_NAMES    = [ 'lastname', 'surname', 'sn', 'urn:oid:2.5.4.4' ];

	/**
	 * @param array<string, array<int, string>> $attributes
	 */
	public static function emailAddressFromAssertion( ?string $nameId, array $attributes ): string {
		if ( null !== $nameId && str_contains( $nameId, '@' ) ) {
			return trim( $nameId );
		}

		foreach ( self::EMAIL_ATTRIBUTE_NAMES as $attributeName ) {
			$candidate = self::firstAttributeValue( $attributes, $attributeName );
			if ( '' !== $candidate && str_contains( $candidate, '@' ) ) {
				return $candidate;
			}
		}

		return '';
	}

	public static function findExistingUserIdByEmail( string $emailAddress ): int {
		$existingUser = get_user_by( 'email', $emailAddress );

		return false === $existingUser ? 0 : (int) $existingUser->ID;
	}

	/**
	 * Provision a network user from the assertion. The IdP's assertion counts
	 * as email verification; the local password is random and uncommunicated,
	 * so SSO remains the only way in.
	 *
	 * @param array<string, array<int, string>> $attributes
	 * @return int user ID, 0 on failure
	 */
	public static function provisionUser( string $emailAddress, array $attributes, string $defaultRole ): int {
		[ $localPart ] = explode( '@', $emailAddress, 2 );
		$baseUsername  = sanitize_user( $localPart, true );

		if ( '' === $baseUsername ) {
			return 0;
		}

		$username = $baseUsername;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$username = $baseUsername . '-' . $suffix;
			$suffix++;
		}

		$userId = wp_insert_user(
			[
				'user_login'   => $username,
				'user_email'   => $emailAddress,
				'user_pass'    => wp_generate_password( 64 ),
				'display_name' => self::displayNameFromAttributes( $attributes, $baseUsername ),
				'first_name'   => self::firstAttributeValue( $attributes, ...self::FIRST_NAME_ATTRIBUTE_NAMES ),
				'last_name'    => self::firstAttributeValue( $attributes, ...self::LAST_NAME_ATTRIBUTE_NAMES ),
				'role'         => $defaultRole,
			]
		);

		return is_int( $userId ) ? $userId : 0;
	}

	/**
	 * @param array<string, array<int, string>> $attributes
	 */
	private static function displayNameFromAttributes( array $attributes, string $fallback ): string {
		foreach ( self::DISPLAY_NAME_ATTRIBUTE_NAMES as $attributeName ) {
			$displayName = self::firstAttributeValue( $attributes, $attributeName );
			if ( '' !== $displayName ) {
				return $displayName;
			}
		}

		$firstName = self::firstAttributeValue( $attributes, ...self::FIRST_NAME_ATTRIBUTE_NAMES );
		$lastName  = self::firstAttributeValue( $attributes, ...self::LAST_NAME_ATTRIBUTE_NAMES );

		if ( '' !== $firstName && '' !== $lastName ) {
			return $firstName . ' ' . $lastName;
		}

		return $fallback;
	}

	/** @param array<string, array<int, string>> $attributes */
	private static function firstAttributeValue( array $attributes, string ...$attributeNames ): string {
		foreach ( $attributeNames as $attributeName ) {
			$values = $attributes[ strtolower( $attributeName ) ] ?? $attributes[ $attributeName ] ?? null;
			$value  = is_array( $values ) ? reset( $values ) : null;

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}
}

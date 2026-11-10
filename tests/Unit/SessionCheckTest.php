<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\SessionCheck;

/** @group SSO-010 */
final class SessionCheckTest extends TestCase {

	/**
	 * @dataProvider loginRequests
	 * @param array<string, mixed> $query
	 */
	public function testOnlyAnAnonymousGetOfTheLoginFormIsChecked( string $method, array $query, bool $checked ): void {
		self::assertSame( $checked, SessionCheck::requestIsPlainLoginForm( $method, $query ) );
	}

	/** @return array<string, array{string, array<string, mixed>, bool}> */
	public static function loginRequests(): array {
		return [
			'plain form'               => [ 'GET', [], true ],
			'with destination'         => [ 'GET', [ 'redirect_to' => 'https://site.test/wp-admin/' ], true ],
			'explicit login action'    => [ 'get', [ 'action' => 'login' ], true ],
			'after logout'             => [ 'GET', [ 'loggedout' => 'true' ], true ],
			'forced re-authentication' => [ 'GET', [ 'reauth' => '1' ], true ],
			'credentials submitted'    => [ 'POST', [], false ],
			'lost password'            => [ 'GET', [ 'action' => 'lostpassword' ], false ],
			'logout confirmation'      => [ 'GET', [ 'action' => 'logout' ], false ],
			'already checked'          => [ 'GET', [ 'pv_wpms_sso_checked' => 'none' ], false ],
			'flow callback'            => [ 'GET', [ 'pv_wpms_sso' => 'callback' ], false ],
			'refused flow notice'      => [ 'GET', [ 'pv_wpms_sso_error' => 'access_denied' ], false ],
			'session-expired iframe'   => [ 'GET', [ 'interim-login' => '1' ], false ],
			'array action'             => [ 'GET', [ 'action' => [ 'login' ] ], false ],
		];
	}

	public function testCookieRoundTripIsBoundToItsState(): void {
		$state  = str_repeat( 'ab', 32 );
		$cookie = SessionCheck::encodeCookie( $state, '/wp-admin/edit.php?post_type=page&paged=2', true, false );

		self::assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $cookie );
		self::assertSame(
			[ 'redirect_to' => '/wp-admin/edit.php?post_type=page&paged=2', 'auto_sign_in' => true, 'after_logout' => false ],
			SessionCheck::pending( $cookie, $state )
		);
		self::assertNull( SessionCheck::pending( $cookie, str_repeat( 'cd', 32 ) ) );
		self::assertNull( SessionCheck::pending( '', $state ) );
		self::assertNull( SessionCheck::pending( $cookie, '' ) );
	}

	/** @dataProvider malformedCookies */
	public function testMalformedCookieIsNotAPendingCheck( string $cookie ): void {
		self::assertNull( SessionCheck::pending( $cookie, str_repeat( 'ab', 32 ) ) );
	}

	/** @return array<string, array{string}> */
	public static function malformedCookies(): array {
		$state  = str_repeat( 'ab', 32 );
		$encode = static fn ( array $payload ): string => rtrim( strtr( base64_encode( (string) json_encode( $payload ) ), '+/', '-_' ), '=' );

		return [
			'not base64'       => [ '%%%' ],
			'not json'         => [ 'bm90LWpzb24' ],
			'missing mode'     => [ $encode( [ 's' => $state, 'r' => '' ] ) ],
			'mode not boolean' => [ $encode( [ 's' => $state, 'r' => '', 'a' => 'yes', 'l' => false ] ) ],
			'array redirect'   => [ $encode( [ 's' => $state, 'r' => [], 'a' => true, 'l' => false ] ) ],
			'short state'      => [ $encode( [ 's' => 'abab', 'r' => '', 'a' => true, 'l' => false ] ) ],
		];
	}
}

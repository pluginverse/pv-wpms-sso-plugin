<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\RedirectGuard;

final class RedirectGuardTest extends TestCase {

	public function testSameHostWithHttpsIsAllowed(): void {
		self::assertTrue(
			RedirectGuard::destinationAllowed( 'https://shop.example.net/wp-admin/', 'https://shop.example.net' )
		);
	}

	public function testSameHostDifferentPortIsRejected(): void {
		self::assertFalse(
			RedirectGuard::destinationAllowed( 'https://shop.example.net:8443/wp-admin/', 'https://shop.example.net' )
		);
	}

	public function testDifferentHostIsRejected(): void {
		self::assertFalse(
			RedirectGuard::destinationAllowed( 'https://evil.example.net/wp-admin/', 'https://shop.example.net' )
		);
	}

	public function testProtocolRelativeForeignHostIsRejected(): void {
		self::assertFalse(
			RedirectGuard::destinationAllowed( '//evil.example.net/x', 'https://shop.example.net' )
		);
	}

	public function testJavascriptSchemeIsRejected(): void {
		self::assertFalse(
			RedirectGuard::destinationAllowed( 'javascript:alert(1)', 'https://shop.example.net' )
		);
	}

	public function testRelativePathIsAllowed(): void {
		self::assertTrue(
			RedirectGuard::destinationAllowed( '/wp-admin/edit.php', 'https://shop.example.net' )
		);
	}

	public function testExtraAllowedHostFromFilterListIsAllowed(): void {
		self::assertTrue(
			RedirectGuard::destinationAllowed( 'https://cdn.example.net/launch', 'https://shop.example.net', [ 'cdn.example.net' ] )
		);
	}

	public function testGarbageIsRejected(): void {
		self::assertFalse( RedirectGuard::destinationAllowed( 'https://shop.example.net', '' ) );
		self::assertFalse( RedirectGuard::destinationAllowed( '::::', 'https://shop.example.net' ) );
	}

	public function testUrlMatchesSiteHostPositive(): void {
		self::assertTrue(
			RedirectGuard::urlMatchesSiteHost( 'http://localhost/foo/wp-login.php', 'http://localhost/foo' )
		);
	}

	public function testUrlMatchesSiteHostRejectsDifferentHost(): void {
		self::assertFalse(
			RedirectGuard::urlMatchesSiteHost( 'http://evil.example.net/wp-login.php', 'http://localhost' )
		);
	}

	public function testUrlMatchesSiteHostRejectsNonHttpScheme(): void {
		self::assertFalse(
			RedirectGuard::urlMatchesSiteHost( 'javascript://localhost/x', 'http://localhost' )
		);
	}

	public function testUrlMatchesSiteHostRejectsGarbage(): void {
		self::assertFalse(
			RedirectGuard::urlMatchesSiteHost( 'not a url', 'http://localhost' )
		);
		self::assertFalse(
			RedirectGuard::urlMatchesSiteHost( 'http://localhost', 'not a url' )
		);
	}
}

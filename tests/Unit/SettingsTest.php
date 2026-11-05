<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\Settings;

final class SettingsTest extends TestCase {

	public function testDefaultsWhenNothingStored(): void {
		self::assertSame(
			[
				'enabled'       => true,
				'idp_blog_id'   => 0,
				'scope'         => Settings::SCOPE_SUPER_ADMINS,
				'button_label'  => '',
			],
			Settings::mergeWithDefaults( [] )
		);
	}

	public function testValidValuesAreKept(): void {
		self::assertSame(
			[
				'enabled'       => false,
				'idp_blog_id'   => 7,
				'scope'         => Settings::SCOPE_SITE_MEMBERS,
				'button_label'  => 'Sign in with Acme',
			],
			Settings::mergeWithDefaults( [
				'enabled'       => false,
				'idp_blog_id'   => 7,
				'scope'         => Settings::SCOPE_SITE_MEMBERS,
				'button_label'  => ' Sign in with Acme ',
			] )
		);
	}

	public function testUnknownScopeFallsBackToSuperAdmins(): void {
		self::assertSame(
			Settings::SCOPE_SUPER_ADMINS,
			Settings::mergeWithDefaults( [ 'scope' => 'administrators-of-everything' ] )['scope']
		);
	}

	public function testNonNumericScopeFallsBackToSuperAdmins(): void {
		self::assertSame(
			Settings::SCOPE_SUPER_ADMINS,
			Settings::mergeWithDefaults( [ 'scope' => 42 ] )['scope']
		);
	}

	public function testNegativeIdpBlogIdBecomesZero(): void {
		self::assertSame(
			0,
			Settings::mergeWithDefaults( [ 'idp_blog_id' => -3 ] )['idp_blog_id']
		);
	}

	public function testEnabledIsCoercedToBool(): void {
		self::assertFalse( Settings::mergeWithDefaults( [ 'enabled' => 0 ] )['enabled'] );
		self::assertTrue( Settings::mergeWithDefaults( [ 'enabled' => 'yes' ] )['enabled'] );
	}
}

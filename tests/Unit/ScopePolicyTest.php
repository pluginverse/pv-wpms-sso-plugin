<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\ScopePolicy;
use PV\WpmsSso\Settings;

final class ScopePolicyTest extends TestCase {

	private const FACTS_SUPER_ADMIN   = [ 'is_super_admin' => true, 'is_site_member' => false, 'user_exists' => true ];
	private const FACTS_SITE_MEMBER   = [ 'is_super_admin' => false, 'is_site_member' => true, 'user_exists' => true ];
	private const FACTS_PLAIN_USER    = [ 'is_super_admin' => false, 'is_site_member' => false, 'user_exists' => true ];

	public function testSuperAdminsScopeAllowsOnlySuperAdmins(): void {
		self::assertTrue( ScopePolicy::userPassesScope( Settings::SCOPE_SUPER_ADMINS, self::FACTS_SUPER_ADMIN ) );
		self::assertFalse( ScopePolicy::userPassesScope( Settings::SCOPE_SUPER_ADMINS, self::FACTS_SITE_MEMBER ) );
		self::assertFalse( ScopePolicy::userPassesScope( Settings::SCOPE_SUPER_ADMINS, self::FACTS_PLAIN_USER ) );
	}

	/**
	 * @group SAML-002
	 * @group SSO-002
	 * @group SSO-004
	 */
	public function testSiteMembersScopeAllowsMembersAndSuperAdmins(): void {
		self::assertTrue( ScopePolicy::userPassesScope( Settings::SCOPE_SITE_MEMBERS, self::FACTS_SUPER_ADMIN ) );
		self::assertTrue( ScopePolicy::userPassesScope( Settings::SCOPE_SITE_MEMBERS, self::FACTS_SITE_MEMBER ) );
		self::assertFalse( ScopePolicy::userPassesScope( Settings::SCOPE_SITE_MEMBERS, self::FACTS_PLAIN_USER ) );
	}

	public function testNetworkUsersScopeAllowsAnyExistingUser(): void {
		self::assertTrue( ScopePolicy::userPassesScope( Settings::SCOPE_NETWORK_USERS, self::FACTS_PLAIN_USER ) );
		self::assertFalse( ScopePolicy::userPassesScope(
			Settings::SCOPE_NETWORK_USERS,
			[ 'is_super_admin' => false, 'is_site_member' => false, 'user_exists' => false ]
		) );
	}

	public function testUnknownScopeDeniesEveryone(): void {
		self::assertFalse( ScopePolicy::userPassesScope( 'administrators-of-everything', self::FACTS_SUPER_ADMIN ) );
	}
}

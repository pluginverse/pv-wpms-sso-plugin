<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\LocalSessionBridge;
use PV\WpmsSso\Settings;
use PV\WpmsSso\TicketSigner;
use PV\WpmsSso\KeyStore;
use PV\WpmsSso\Tests\Support\JourneyFixture;
use WP_Error;

/** @group SSO-001 */
final class LocalSessionBridgeTest extends JourneyFixture {

	private function beginFlow(): array {
		$this->enter( 'Hub' );
		return LocalSessionBridge::begin( $this->sites['Northcoast'], $this->users['Alex'], get_site_url( $this->sites['Northcoast'] ) . '/wp-admin/edit.php' );
	}

	private function proveFlow( array $transaction ): string {
		$this->enter( 'Northcoast', $this->users['Alex'] );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->users['Alex'], time() + 3600, 'logged_in' );
		$proof = LocalSessionBridge::prove( $this->sites['Hub'], $transaction['state'] );
		self::assertIsString( $proof );
		return $proof;
	}

	public function testCompletedLocalLoginEstablishesMatchingIdentityAndReturnsToOriginalPath(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub' );
		$result = LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof );
		self::assertIsArray( $result );
		self::assertSame( $this->users['Alex'], $result['user_id'] );
		self::assertSame( get_site_url( $this->sites['Northcoast'] ) . '/wp-admin/edit.php', $result['redirect_to'] );
		self::assertFalse( is_user_member_of_blog( $this->users['Alex'], $this->sites['Hub'] ) );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}

	/** @group SSO-005 */
	public function testCurrentUserWithoutValidLoginCookieCannotProveSession(): void {
		$transaction = $this->beginFlow();
		$this->enter( 'Northcoast', $this->users['Alex'] );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::prove( $this->sites['Hub'], $transaction['state'] ) );
	}

	public function testCookieFromAnotherAccountCannotSatisfyExpectedUser(): void {
		$transaction = $this->beginFlow();
		$this->enter( 'Northcoast', $this->users['Morgan'] );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->users['Morgan'], time() + 3600, 'logged_in' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::prove( $this->sites['Hub'], $transaction['state'] ) );
	}

	public function testCookieMismatchDoesNotConsumeLegitimateTransaction(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], 'wrong-cookie', $proof ) );
		self::assertIsArray( LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}

	/** @group SSO-006 */
	public function testDifferentHubIdentityIsNotReplaced(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub', $this->users['Morgan'] );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
		self::assertSame( $this->users['Morgan'], get_current_user_id() );
	}

	public function testMatchingHubIdentityCanContinue(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub', $this->users['Alex'] );
		self::assertIsArray( LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}

	public function testRemovedSourceTrustInvalidatesPreviouslyIssuedProof(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub' );
		Settings::save( get_current_network_id(), [ 'enabled' => false ] );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}

	public function testProofCannotBeUsedForAnotherTransaction(): void {
		$first = $this->beginFlow();
		$second = $this->beginFlow();
		$proof = $this->proveFlow( $first );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $second['state'], $second['state'], $proof ) );
	}

	public function testOrdinaryTicketSigningKeyCannotAuthenticateLocalProof(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$derivedKey = hash_hmac( 'sha256', 'pv-wpms-sso/local-session/v1', KeyStore::getForBlog( $this->sites['Hub'] ) );
		$claims = TicketSigner::verify( $proof, $derivedKey, time() );
		$ordinaryToken = TicketSigner::issue( $claims, KeyStore::getForBlog( $this->sites['Hub'] ) );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $ordinaryToken ) );
	}

	public function testExpiredTransactionCannotBeProved(): void {
		$transaction = $this->beginFlow();
		$key = LocalSessionBridge::TRANSIENT_PREFIX . hash( 'sha256', $transaction['state'] );
		$stored = get_transient( $key );
		$stored['expires_at'] = time() - 1;
		set_transient( $key, $stored, 600 );
		$this->enter( 'Northcoast', $this->users['Alex'] );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->users['Alex'], time() + 3600, 'logged_in' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::prove( $this->sites['Hub'], $transaction['state'] ) );
	}

	public function testForeignReturnUrlIsRejected(): void {
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::begin( $this->sites['Northcoast'], $this->users['Alex'], 'https://attacker.test/' ) );
	}

	public function testRootRelativeSubdirectoryReturnPathIsNotDuplicated(): void {
		update_blog_details( $this->sites['Northcoast'], [ 'path' => '/northcoast/' ] );
		update_blog_option( $this->sites['Northcoast'], 'siteurl', 'https://northcoast.test:9443/northcoast' );
		$this->enter( 'Hub' );
		$transaction = LocalSessionBridge::begin( $this->sites['Northcoast'], $this->users['Alex'], '/northcoast/wp-admin/edit.php' );
		self::assertIsArray( $transaction );
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub' );
		$result = LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof );
		self::assertIsArray( $result );
		self::assertSame( get_site_url( $this->sites['Northcoast'] ) . '/wp-admin/edit.php', $result['redirect_to'] );
	}

	public function testAtomicClaimRejectsReplayEvenWhenAnotherRequestReadStateBeforeDeletion(): void {
		$transaction = $this->beginFlow();
		$key = LocalSessionBridge::TRANSIENT_PREFIX . hash( 'sha256', $transaction['state'] );
		$stored = get_transient( $key );
		$proof = $this->proveFlow( $transaction );
		$this->enter( 'Hub' );
		self::assertIsArray( LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
		set_transient( $key, $stored, 600 );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}

	/** @dataProvider invalidProofClaims */
	public function testInvalidProofClaimsCannotEstablishHubSession( string $claimName, int|string $value ): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		$key = hash_hmac( 'sha256', 'pv-wpms-sso/local-session/v1', KeyStore::getForBlog( $this->sites['Hub'] ) );
		$claims = TicketSigner::verify( $proof, $key, time() )->toClaims();
		$claims[ $claimName ] = $value;
		$forged = TicketSigner::issue( \PV\WpmsSso\Ticket::fromClaims( $claims ), $key );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $forged ) );
	}

	public static function invalidProofClaims(): array {
		return [
			'wrong source' => [ 'iss', 999999 ],
			'wrong audience' => [ 'aud', 999999 ],
			'wrong user' => [ 'sub', 999999 ],
			'changed login' => [ 'login', 'renamed-user' ],
			'wrong state' => [ 'state', 'another-state' ],
			'expired proof' => [ 'exp', 1 ],
			'future proof' => [ 'iat', PHP_INT_MAX ],
		];
	}

	/** @return array<string, string> query arguments of the hub hand-off URL */
	private function handOffArguments( string $person, string $redirectTo ): array {
		$this->enter( 'Northcoast', $this->users[ $person ] );
		$url = LocalSessionBridge::filterLoginRedirect( $redirectTo, $redirectTo, get_userdata( $this->users[ $person ] ) );
		self::assertStringStartsWith( get_site_url( $this->sites['Hub'] ) . '/wp-login.php?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $arguments );
		return $arguments;
	}

	public function testLoginRedirectKeepsEveryQueryArgumentOfTheReturnUrl(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Alex'], 'editor' );
		add_user_to_blog( $this->sites['Southmountain'], $this->users['Alex'], 'editor' );
		$this->enter( 'Northcoast' );
		$returnTo = admin_url( 'edit.php?post_type=page&paged=2&user_id=999999&pv_wpms_sso=start' );
		$arguments = $this->handOffArguments( 'Alex', $returnTo );
		self::assertSame( $returnTo, $arguments['return_to'] );
		self::assertSame( (string) $this->users['Alex'], $arguments['user_id'] );
		self::assertSame( 'bridge_start', $arguments['pv_wpms_sso'] );
	}

	public function testDefaultLandingKeepsCoreRuleForMembersWhoCannotEdit(): void {
		foreach ( [ 'Northcoast', 'Southmountain' ] as $site ) {
			add_user_to_blog( $this->sites[ $site ], $this->users['Casey'], 'subscriber' );
			add_user_to_blog( $this->sites[ $site ], $this->users['Alex'], 'editor' );
		}
		$this->enter( 'Northcoast' );
		self::assertSame( admin_url( 'profile.php' ), $this->handOffArguments( 'Casey', admin_url() )['return_to'] );
		self::assertSame( admin_url(), $this->handOffArguments( 'Alex', admin_url() )['return_to'] );
	}

	public function testAccountWithoutAnySiteKeepsCoreLandingOnAnotherHost(): void {
		$this->enter( 'Northcoast', $this->users['Riley'] );
		self::assertSame( admin_url(), LocalSessionBridge::filterLoginRedirect( admin_url(), '', get_userdata( $this->users['Riley'] ) ) );
	}

	public function testUnexpectedLoginRedirectValuesPassThrough(): void {
		$this->enter( 'Northcoast', $this->users['Alex'] );
		self::assertNull( LocalSessionBridge::filterLoginRedirect( null, null, get_userdata( $this->users['Alex'] ) ) );
		self::assertSame( admin_url(), LocalSessionBridge::filterLoginRedirect( admin_url(), '', new WP_Error( 'failed' ) ) );
	}

	private function redirectAfterLogin( string $person ): string {
		$this->enter( 'Northcoast', $this->users[ $person ] );
		return LocalSessionBridge::filterLoginRedirect( admin_url(), '', get_userdata( $this->users[ $person ] ) );
	}

	/** @group SSO-009 */
	public function testSiteLocalMemberIsNotSentToTheIdentitySite(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Casey'], 'editor' );
		$this->enter( 'Northcoast' );
		self::assertSame( admin_url(), $this->redirectAfterLogin( 'Casey' ) );
	}

	/** @group SSO-009 */
	public function testMemberOfSeveralSitesIsHandedOffUnlessScopeIsSuperAdminsOnly(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Riley'], 'editor' );
		add_user_to_blog( $this->sites['Ridgeview'], $this->users['Riley'], 'editor' );
		self::assertStringContainsString( 'bridge_start', $this->redirectAfterLogin( 'Riley' ) );
		Settings::save( get_current_network_id(), [ 'scope' => Settings::SCOPE_SUPER_ADMINS ] );
		self::assertStringNotContainsString( 'bridge_start', $this->redirectAfterLogin( 'Riley' ) );
	}

	/** @group SSO-009 */
	public function testSuperAdminIsHandedOffWithoutAnyMembership(): void {
		Settings::save( get_current_network_id(), [ 'scope' => Settings::SCOPE_SUPER_ADMINS ] );
		grant_super_admin( $this->users['Morgan'] );
		self::assertStringContainsString( 'bridge_start', $this->redirectAfterLogin( 'Morgan' ) );
	}

	/** @group SSO-009 */
	public function testSuperAdminOfAnotherNetworkUsingTheSameHubIsHandedOff(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Morgan'], 'editor' );
		$mountainNetworkId = (int) get_site( $this->sites['Ridgeview'] )->site_id;
		update_network_option( $mountainNetworkId, 'site_admins', [ get_userdata( $this->users['Morgan'] )->user_login ] );
		self::assertStringContainsString( 'bridge_start', $this->redirectAfterLogin( 'Morgan' ) );
		Settings::save( $mountainNetworkId, [ 'enabled' => false ] );
		self::assertStringNotContainsString( 'bridge_start', $this->redirectAfterLogin( 'Morgan' ) );
	}

	/** @group SSO-009 */
	public function testPlatformFilterDecidesHandOff(): void {
		add_user_to_blog( $this->sites['Northcoast'], $this->users['Casey'], 'editor' );
		add_filter( LocalSessionBridge::HAND_OFF_FILTER, '__return_true' );
		self::assertStringContainsString( 'bridge_start', $this->redirectAfterLogin( 'Casey' ) );
		remove_filter( LocalSessionBridge::HAND_OFF_FILTER, '__return_true' );
		add_user_to_blog( $this->sites['Southmountain'], $this->users['Casey'], 'editor' );
		add_filter( LocalSessionBridge::HAND_OFF_FILTER, '__return_false' );
		self::assertStringNotContainsString( 'bridge_start', $this->redirectAfterLogin( 'Casey' ) );
	}

	public function testArchivedSourceCannotEstablishHubSession(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		update_blog_details( $this->sites['Northcoast'], [ 'archived' => 1 ] );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}
}

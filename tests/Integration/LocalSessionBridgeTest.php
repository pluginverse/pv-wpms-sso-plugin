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

	public function testArchivedSourceCannotEstablishHubSession(): void {
		$transaction = $this->beginFlow();
		$proof = $this->proveFlow( $transaction );
		update_blog_details( $this->sites['Northcoast'], [ 'archived' => 1 ] );
		$this->enter( 'Hub' );
		self::assertInstanceOf( WP_Error::class, LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof ) );
	}
}

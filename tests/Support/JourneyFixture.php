<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Support;

use PV\WpmsSso\Settings;
use WP_UnitTestCase;

/** WordPress owns transaction cleanup; fixtures use actual network records. */
class JourneyFixture extends WP_UnitTestCase {

	/** @var array<string, int> */
	public array $sites = [];
	/** @var array<string, int> */
	public array $users = [];
	private \WP_Network $originalNetwork;

	public function setUp(): void {
		parent::setUp();
		$this->originalNetwork = get_network();
		$this->sites['Hub'] = get_current_blog_id();
		foreach ( [ 'Northcoast', 'Southmountain', 'CoastExtra' ] as $name ) {
			$this->sites[ $name ] = self::factory()->blog->create( [ 'domain' => strtolower( $name ) . '.test', 'path' => '/' ] );
		}
		$mountainNetworkId = self::factory()->network->create( [ 'domain' => 'ridgeview.test', 'path' => '/' ] );
		$this->sites['Ridgeview'] = self::factory()->blog->create( [ 'domain' => 'ridgeview.test', 'path' => '/', 'site_id' => $mountainNetworkId ] );
		foreach ( [ get_current_network_id(), $mountainNetworkId ] as $networkId ) {
			Settings::save( $networkId, [ 'enabled' => true, 'idp_blog_id' => $this->sites['Hub'], 'scope' => Settings::SCOPE_SITE_MEMBERS ] );
		}
		foreach ( [ 'Alex', 'Casey', 'Riley', 'Morgan' ] as $name ) {
			$this->users[ $name ] = self::factory()->user->create( [ 'user_login' => $name, 'user_email' => strtolower( $name ) . '@example.test', 'role' => '' ] );
			remove_user_from_blog( $this->users[ $name ], $this->sites['Hub'] );
		}
	}

	public function enter( string $siteName, int $userId = 0 ): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		switch_to_blog( $this->sites[ $siteName ] );
		$GLOBALS['current_site'] = get_network( (int) get_site()->site_id );
		wp_set_current_user( $userId );
	}

	public function tearDown(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$GLOBALS['current_site'] = $this->originalNetwork;
		parent::tearDown();
	}
}

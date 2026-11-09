<?php

declare( strict_types=1 );

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use PV\WpmsSso\Tests\Behavior\JourneyContext;

require_once __DIR__ . '/tests/bootstrap.php';
if ( ! class_exists( 'WP_UnitTestCase' ) ) {
    throw new RuntimeException( 'Run composer test-bdd inside the disposable wp-env test environment.' );
}

return ( new Config() )->withProfile(
    ( new Profile( 'default', [ 'testers' => [ 'strict' => true ] ] ) )->withSuite(
        ( new Suite( 'journeys' ) )->withPaths( __DIR__ . '/tests/Behavior/features' )->withContexts( JourneyContext::class )
    )
);

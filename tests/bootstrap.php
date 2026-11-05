<?php
/**
 * PHPUnit bootstrap: composer autoload always; the WordPress test stack only
 * when available (wp-env or an installed wp-phpunit). The unit suite runs
 * without WordPress on a bare host.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$wpPhpUnitDir = getenv( 'WP_PHPUNIT__DIR' ) ?: '';
// Composer's wp-phpunit autoload file exports WP_PHPUNIT__DIR on every host,
// so the database env vars are what actually identify the wp-env test container.
$inWordPressTestContainer = '' !== $wpPhpUnitDir && (bool) getenv( 'WORDPRESS_DB_HOST' );
if ( ! $inWordPressTestContainer ) {
	// Unit-only mode: the WordPress test stack runs only under wp-env
	// (`composer test`), which provides the database environment.
	return;
}

$polyfillsPath = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $polyfillsPath ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfillsPath );
}

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $wpPhpUnitDir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function () {
	require_once __DIR__ . '/../pv-wpms-sso.php';
} );

// SSO is multisite-only; run integration tests in multisite mode.
define( 'WP_TESTS_MULTISITE', true );

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-config.php' );

// Start up the WP testing environment.
require $wpPhpUnitDir . '/includes/bootstrap.php';

<?php

// This script creates fixtures only in the isolated browser-test environment.
if ( ! defined( 'PV_WPMS_SSO_BROWSER_TESTS' ) || ! PV_WPMS_SSO_BROWSER_TESTS || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Browser fixtures require the isolated local test environment.' );
}

use PV\WpmsSso\Settings;
use PV\WpmsSso\SamlSettings;
use PV\WpmsSso\Tests\Support\SignedSamlResponse;

global $wpdb;
// A fresh second network uses modern uploads, not WordPress's legacy default.
remove_filter( 'default_site_option_ms_files_rewriting', '__return_true' );
$coastNetworkId = get_current_network_id();
$hubBlogId = get_main_site_id();
$hubSite = get_site( $hubBlogId );
// Keep the original localhost network root available for repeatable WP-CLI seeding.
$sites = [];
foreach ( [ 'auth', 'northcoast', 'southmountain', 'coastextra', 'onelogin' ] as $name ) {
	$existing = get_sites( [ 'domain' => $name . '.test:9443', 'number' => 1 ] );
	$sites[ $name ] = $existing ? (int) $existing[0]->blog_id : wpmu_create_blog( $name . '.test:9443', '/', ucfirst( $name ), 1, [], $coastNetworkId );
}
$hubBlogId = $sites['auth'];
$mountain = get_networks( [ 'domain' => 'ridgeview.test:9443', 'number' => 1 ] );
if ( ! $mountain ) {
	$wpdb->insert( $wpdb->site, [ 'domain' => 'ridgeview.test:9443', 'path' => '/' ] );
	$mountainNetworkId = (int) $wpdb->insert_id;
	clean_network_cache( $mountainNetworkId );
	update_network_option( $mountainNetworkId, 'site_name', 'Mountain' );
} else {
	$mountainNetworkId = (int) $mountain[0]->id;
}
update_network_option( $mountainNetworkId, 'ms_files_rewriting', 0 );
update_network_option( $mountainNetworkId, 'siteurl', 'https://ridgeview.test:9443' );
$ridge = get_sites( [ 'domain' => 'ridgeview.test:9443', 'number' => 1 ] );
$sites['ridgeview'] = $ridge ? (int) $ridge[0]->blog_id : wpmu_create_blog( 'ridgeview.test:9443', '/', 'Ridgeview', 1, [], $mountainNetworkId );
foreach ( $sites as $name => $blogId ) {
	if ( is_wp_error( $blogId ) ) {
		throw new RuntimeException( $blogId->get_error_message() );
	}
	update_blog_option( $blogId, 'siteurl', 'https://' . $name . '.test:9443' );
	update_blog_option( $blogId, 'home', 'https://' . $name . '.test:9443' );
	update_blog_option( $blogId, 'permalink_structure', '' );
}
foreach ( [ $coastNetworkId, $mountainNetworkId ] as $networkId ) {
	Settings::save( $networkId, [ 'enabled' => true, 'idp_blog_id' => $hubBlogId, 'scope' => Settings::SCOPE_SITE_MEMBERS ] );
	update_network_option( $networkId, 'active_sitewide_plugins', [ basename( dirname( __DIR__, 2 ) ) . '/pv-wpms-sso.php' => time() ] );
}
foreach ( [ 'alex' => [ 'northcoast', 'southmountain' ], 'casey' => [ 'northcoast' ], 'riley' => [ 'northcoast', 'ridgeview' ], 'morgan' => [ 'auth' ] ] as $name => $memberships ) {
	$user = get_user_by( 'login', $name );
	$userId = $user ? $user->ID : wp_insert_user( [ 'user_login' => $name, 'user_email' => $name . '@example.test', 'user_pass' => 'browser-test-password', 'role' => '' ] );
	if ( is_wp_error( $userId ) ) {
		throw new RuntimeException( $userId->get_error_message() );
	}
	// wp_insert_user leaves an empty membership on the root site; site-local means one site.
	foreach ( [ get_main_site_id(), ...array_values( $sites ) ] as $blogId ) {
		remove_user_from_blog( $userId, $blogId );
	}
	foreach ( $memberships as $siteName ) {
		add_user_to_blog( $sites[ $siteName ], $userId, 'editor' );
	}
}
[ $privateKey, $certificate, $certificateBody ] = SignedSamlResponse::generateKeyPair();
update_network_option( $coastNetworkId, 'pv_browser_idp', [ 'hub' => $hubBlogId, 'key' => $privateKey, 'certificate' => $certificate ] );
SamlSettings::save( $coastNetworkId, [ 'enabled' => true, 'idp_entity_id' => 'https://onelogin.test:9443/metadata', 'idp_sso_url' => 'https://onelogin.test:9443/wp-login.php?browser_saml=1', 'idp_x509cert' => $certificateBody, 'auto_provision' => false ] );
echo "Browser fixtures ready: two networks, separate HTTPS origins, membership-limited users.\n";

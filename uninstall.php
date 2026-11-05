<?php
/**
 * Uninstall cleanup: removes every trace the plugin leaves in the database —
 * per-network settings, the IdP signing key and client state transients in
 * every site's options table, and the global single-use ticket registry.
 *
 * Network activation means the whole install, so cleanup walks networks and
 * option tables directly instead of touching only the current site.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Per-network settings.
$networks = get_networks();
if ( is_array( $networks ) ) {
	foreach ( $networks as $network ) {
		delete_network_option( (int) $network->id, 'pv_wpms_sso' );
	}
}

// Per-blog options: signing keys and client state transients.
$optionTables = (array) $wpdb->get_col(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%\_options' )
);
foreach ( $optionTables as $optionTable ) {
	$optionTable = sanitize_key( (string) $optionTable );
	$wpdb->query(
		"DELETE FROM `{$optionTable}`
		 WHERE option_name = 'pv_wpms_sso_key'
		    OR option_name LIKE '\_transient\_pv\_wpms\_sso\_state\_%'
		    OR option_name LIKE '\_transient\_timeout\_pv\_wpms\_sso\_state\_%'"
	);
}

// Global single-use ticket registry.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->base_prefix}pv_wpms_sso_jti`" );

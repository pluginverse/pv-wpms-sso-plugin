<?php
/**
 * Plugin Name: PV WPMS SSO
 * Description: Plugin Verse WordPress Multisite Single Sign On — one identity site, one login, signed single-use tickets into every site of a domain-mapped multisite or same-database multi-network install.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Plugin Verse
 * Author URI: https://github.com/pluginverse
 * License: GPL v3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: pv-wpms-sso
 * Network: true
 *
 * PV WPMS SSO — Plugin Verse WordPress Multisite Single Sign On
 * Copyright (C) 2026 Plugin Verse
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Additional Permission Under GNU GPL version 3 section 7:
 *
 * The name "Plugin Verse" and its associated branding elements are
 * protected by copyright/trademark law and may not be used in
 * association with derivative works that are not owned by Plugin Verse
 * without express written permission.
 */

namespace PV\WpmsSso;

use function add_action;
use function register_activation_hook;
use function spl_autoload_register;

if ( PHP_VERSION_ID < 80200 ) {
	_doing_it_wrong( __FILE__, 'PV WPMS SSO requires PHP 8.2 or higher.', '0.1.0' );

	return;
}

function class_autoloader( string $class ): void {
	if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
		return;
	}

	static $classMap = [
		AuthorizeEndpoint::class => __DIR__ . '/src/AuthorizeEndpoint.php',
		ClientFlow::class => __DIR__ . '/src/ClientFlow.php',
		Commands::class => __DIR__ . '/src/Commands.php',
		InvalidTicketException::class => __DIR__ . '/src/InvalidTicketException.php',
		JtiTable::class => __DIR__ . '/src/JtiTable.php',
		KeyStore::class => __DIR__ . '/src/KeyStore.php',
		LoginScreen::class => __DIR__ . '/src/LoginScreen.php',
		RedirectGuard::class => __DIR__ . '/src/RedirectGuard.php',
		SamlFlow::class => __DIR__ . '/src/SamlFlow.php',
		SamlMetadata::class => __DIR__ . '/src/SamlMetadata.php',
		SamlProvider::class => __DIR__ . '/src/SamlProvider.php',
		SamlSettings::class => __DIR__ . '/src/SamlSettings.php',
		ScopePolicy::class => __DIR__ . '/src/ScopePolicy.php',
		SessionEndpoint::class => __DIR__ . '/src/SessionEndpoint.php',
		Settings::class => __DIR__ . '/src/Settings.php',
		SettingsPage::class => __DIR__ . '/src/SettingsPage.php',
		StateStore::class => __DIR__ . '/src/StateStore.php',
		Ticket::class => __DIR__ . '/src/Ticket.php',
		TicketIssuer::class => __DIR__ . '/src/TicketIssuer.php',
		TicketSigner::class => __DIR__ . '/src/TicketSigner.php',
		TrustPolicy::class => __DIR__ . '/src/TrustPolicy.php',
		UserMapping::class => __DIR__ . '/src/UserMapping.php',
	];

	if ( isset( $classMap[ $class ] ) ) {
		require_once $classMap[ $class ];
	}
}

spl_autoload_register( '\PV\WpmsSso\class_autoloader' );

/*
 * Composer runtime autoloader (onelogin/php-saml for external SSO).
 * The production build ships vendor/; a source checkout without it keeps
 * working — SAML features no-op via saml_library_available().
 */
$pv_wpms_sso_composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $pv_wpms_sso_composer_autoload ) ) {
	require_once $pv_wpms_sso_composer_autoload;
}

function saml_library_available(): bool {
	return class_exists( '\OneLogin\Saml2\Settings' );
}

function load_plugin_translations(): void {
	load_plugin_textdomain( 'pv-wpms-sso', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', '\PV\WpmsSso\load_plugin_translations' );

register_activation_hook( __FILE__, [ JtiTable::class, 'createTable' ] );

add_action( 'rest_api_init', [ AuthorizeEndpoint::class, 'registerRoutes' ] );
add_action( 'rest_api_init', [ SessionEndpoint::class, 'registerRoutes' ] );
add_action( 'rest_api_init', [ SamlMetadata::class, 'registerRoutes' ] );
add_action( 'login_init', [ ClientFlow::class, 'handleRequest' ] );
add_action( 'login_init', [ SamlFlow::class, 'handleRequest' ] );
add_action( 'login_footer', [ LoginScreen::class, 'render' ] );
add_filter( 'login_message', [ LoginScreen::class, 'filterLoginMessage' ] );
add_action( 'network_admin_menu', [ SettingsPage::class, 'register' ] );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'pv-wpms-sso', '\PV\WpmsSso\Commands' );
}

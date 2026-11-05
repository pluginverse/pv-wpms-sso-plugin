<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\KeyStore;
use PV\WpmsSso\SamlSettings;
use PV\WpmsSso\Settings;
use WP_UnitTestCase;

final class KeyStoreAndSettingsTest extends WP_UnitTestCase {

	public function testKeyIsGeneratedOnceAndStablePerIdpBlog(): void {
		$mainSiteId = get_main_site_id( get_current_network_id() );

		$firstRead  = KeyStore::getForBlog( $mainSiteId );
		$secondRead = KeyStore::getForBlog( $mainSiteId );

		self::assertSame( 64, strlen( $firstRead ) );
		self::assertSame( $firstRead, $secondRead );
	}

	public function testRotationChangesTheKey(): void {
		$mainSiteId = get_main_site_id( get_current_network_id() );

		$originalKey = KeyStore::getForBlog( $mainSiteId );
		$newKey      = KeyStore::rotateForBlog( $mainSiteId );

		self::assertNotSame( $originalKey, $newKey );
		self::assertSame( $newKey, KeyStore::getForBlog( $mainSiteId ) );
	}

	public function testFingerprintIsStableAndShort(): void {
		$key = str_repeat( 'ab', 32 );

		self::assertSame( KeyStore::fingerprint( $key ), KeyStore::fingerprint( $key ) );
		self::assertSame( 12, strlen( KeyStore::fingerprint( $key ) ) );
	}

	public function testSettingsRoundTripAndInvalidIdpFallsBackToMainSite(): void {
		$networkId = get_current_network_id();

		Settings::save(
			$networkId,
			[
				'enabled'       => false,
				'idp_blog_id'   => 999999, // does not exist
				'scope'         => Settings::SCOPE_SITE_MEMBERS,
				'button_label'  => 'Sign in with Acme',
			]
		);

		$settings = Settings::forNetwork( $networkId );

		self::assertFalse( $settings['enabled'] );
		self::assertSame( Settings::SCOPE_SITE_MEMBERS, $settings['scope'] );
		self::assertSame( 'Sign in with Acme', $settings['button_label'] );

		// An unusable designated site falls back to the network's main site.
		self::assertSame( get_main_site_id( $networkId ), Settings::resolveIdpBlogId( $networkId ) );

		Settings::save( $networkId, [ 'enabled' => true, 'idp_blog_id' => 0 ] );
	}

	public function testPartialSaveKeepsStoredValues(): void {
		$networkId = get_current_network_id();

		Settings::save( $networkId, [ 'scope' => Settings::SCOPE_SITE_MEMBERS, 'button_label' => 'Keep me' ] );
		Settings::save( $networkId, [ 'scope' => Settings::SCOPE_NETWORK_USERS ] );

		$settings = Settings::forNetwork( $networkId );
		self::assertSame( Settings::SCOPE_NETWORK_USERS, $settings['scope'] );
		self::assertSame( 'Keep me', $settings['button_label'] );

		Settings::save( $networkId, [ 'scope' => Settings::SCOPE_SUPER_ADMINS, 'button_label' => '' ] );
	}

	public function testPartialSamlSaveKeepsStoredValues(): void {
		$networkId = get_current_network_id();

		SamlSettings::save( $networkId, [
			'enabled'       => true,
			'idp_entity_id' => 'https://idp.example.org/metadata',
			'idp_sso_url'   => 'https://idp.example.org/sso',
			'idp_x509cert'  => 'AAABBBCCC==',
		] );
		SamlSettings::save( $networkId, [ 'auto_provision' => false ] );

		$samlSettings = SamlSettings::forNetwork( $networkId );
		self::assertSame( 'https://idp.example.org/metadata', $samlSettings['idp_entity_id'] );
		self::assertSame( 'https://idp.example.org/sso', $samlSettings['idp_sso_url'] );
		self::assertSame( 'AAABBBCCC==', $samlSettings['idp_x509cert'] );
		self::assertFalse( $samlSettings['auto_provision'] );

		SamlSettings::save( $networkId, [ 'enabled' => false ] );
	}
}

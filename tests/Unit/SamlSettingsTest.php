<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PV\WpmsSso\SamlSettings;

final class SamlSettingsTest extends TestCase {

	public function testDefaultsWhenNothingStored(): void {
		self::assertSame(
			[
				'enabled'        => false,
				'idp_entity_id'  => '',
				'idp_sso_url'    => '',
				'idp_x509cert'   => '',
				'button_label'   => '',
				'auto_provision' => false,
				'default_role'   => 'subscriber',
			],
			SamlSettings::mergeWithDefaults( [] )
		);
	}

	public function testValidValuesAreKept(): void {
		self::assertSame(
			[
				'enabled'        => true,
				'idp_entity_id'  => 'https://app.onelogin.com/saml/metadata/123',
				'idp_sso_url'    => 'https://example.onelogin.com/trust/saml2/http-redirect/sso/123',
				'idp_x509cert'   => 'MIIC8DCCAdigAwIBAgIQ==',
				'button_label'   => 'Sign in with OneLogin',
				'auto_provision' => true,
				'default_role'   => 'editor',
			],
			SamlSettings::mergeWithDefaults( [
				'enabled'        => true,
				'idp_entity_id'  => ' https://app.onelogin.com/saml/metadata/123 ',
				'idp_sso_url'    => 'https://example.onelogin.com/trust/saml2/http-redirect/sso/123',
				'idp_x509cert'   => "-----BEGIN CERTIFICATE-----\nMIIC8DCCAdigAwIBAgIQ==\n-----END CERTIFICATE-----",
				'button_label'   => 'Sign in with OneLogin',
				'auto_provision' => true,
				'default_role'   => 'editor',
			] )
		);
	}

	public function testCertificateWithLineBreaksIsCollapsed(): void {
		self::assertSame(
			'AAABBBCCCddd==',
			SamlSettings::mergeWithDefaults( [ 'idp_x509cert' => "AAA\nBBB\r\nCCC\rddd==" ] )['idp_x509cert']
		);
	}

	public function testEmptyRoleFallsBackToSubscriber(): void {
		self::assertSame( 'subscriber', SamlSettings::mergeWithDefaults( [ 'default_role' => '' ] )['default_role'] );
	}

	public function testNonStringValuesAreIgnored(): void {
		$merged = SamlSettings::mergeWithDefaults( [
			'idp_entity_id' => 123,
			'button_label'  => [],
		] );

		self::assertSame( '', $merged['idp_entity_id'] );
		self::assertSame( '', $merged['button_label'] );
	}
}

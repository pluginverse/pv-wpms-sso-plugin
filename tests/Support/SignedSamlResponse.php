<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Support;

use PV\WpmsSso\SamlProvider;
use OneLogin\Saml2\Utils as SamlUtils;

/** Test-only signed responses; the real php-saml validator verifies them. */
final class SignedSamlResponse {
	public static function generateKeyPair(): array {
		$privateKey = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		openssl_pkey_export( $privateKey, $privateKeyPem );

		$certificateRequest = openssl_csr_new( [ 'commonName' => 'pv-wpms-sso-test-idp' ], $privateKey );
		$certificate        = openssl_csr_sign( $certificateRequest, null, $privateKey, 1 );
		openssl_x509_export( $certificate, $certificatePem );

		$certificateBody = (string) preg_replace( '/-----[^-]+-----|\s+/', '', $certificatePem );

		return [ $privateKeyPem, $certificatePem, $certificateBody ];
	}

	public static function build(
		string $requestId,
		string $emailAddress,
		string $signingKeyPem,
		string $signingCertificatePem,
		?string $audienceOverride = null
	): string {
		$spSettings      = SamlProvider::spSettingsArray();
		$acsUrl          = $spSettings['sp']['assertionConsumerService']['url'];
		$audience        = $audienceOverride ?? $spSettings['sp']['entityId'];
		$now             = time();
		$notBefore       = gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 );
		$notOnOrAfter    = gmdate( 'Y-m-d\TH:i:s\Z', $now + 300 );

		$responseXml = <<<XML
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="_resp_{$requestId}" Version="2.0" IssueInstant="{$notBefore}" Destination="{$acsUrl}" InResponseTo="{$requestId}">
  <saml:Issuer>{$spSettings['idp']['entityId']}</saml:Issuer>
  <samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>
  <saml:Assertion ID="_assertion_{$requestId}" Version="2.0" IssueInstant="{$notBefore}">
    <saml:Issuer>{$spSettings['idp']['entityId']}</saml:Issuer>
    <saml:Subject>
      <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">{$emailAddress}</saml:NameID>
      <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
        <saml:SubjectConfirmationData InResponseTo="{$requestId}" NotOnOrAfter="{$notOnOrAfter}" Recipient="{$acsUrl}"/>
      </saml:SubjectConfirmation>
    </saml:Subject>
    <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}">
      <saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction>
    </saml:Conditions>
    <saml:AuthnStatement AuthnInstant="{$notBefore}" SessionIndex="_session_{$requestId}">
      <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>
    </saml:AuthnStatement>
    <saml:AttributeStatement xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
      <saml:Attribute Name="mail"><saml:AttributeValue xsi:type="xs:string">{$emailAddress}</saml:AttributeValue></saml:Attribute>
      <saml:Attribute Name="displayName"><saml:AttributeValue xsi:type="xs:string">Provisioned User</saml:AttributeValue></saml:Attribute>
    </saml:AttributeStatement>
  </saml:Assertion>
</samlp:Response>
XML;

		$signedXml = SamlUtils::addSign(
			$responseXml,
			$signingKeyPem,
			$signingCertificatePem
		);

		return base64_encode( $signedXml );
	}
}

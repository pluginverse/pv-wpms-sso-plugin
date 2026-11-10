<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Behavior;

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Step\Then;
use PHPUnit\Framework\Assert;
use PV\WpmsSso\AuthorizeEndpoint;
use PV\WpmsSso\ClientFlow;
use PV\WpmsSso\LocalSessionBridge;
use PV\WpmsSso\Tests\Support\JourneyFixture;
use WP_Error;
use WP_REST_Request;

final class JourneyContext implements Context {

	private JourneyFixture $fixture;
	private int $hubUserId = 0;
	private string $callbackUrl = '';
	private string $samlPrivateKey;
	private string $samlCertificate;
	/** @var array{user_id:int,redirect_to:string}|WP_Error */
	private array|WP_Error $bridgeResult;

	#[BeforeScenario]
	public function prepare(): void {
		$this->fixture = new JourneyFixture();
		$this->fixture->setUp();
	}

	#[AfterScenario]
	public function cleanup(): void {
		$this->fixture->tearDown();
	}

	#[Given('both networks trust the same identity site')]
	public function sharedHub(): void {
		Assert::assertNotSame( get_site( $this->fixture->sites['Northcoast'] )->site_id, get_site( $this->fixture->sites['Ridgeview'] )->site_id );
	}

	#[Given(':person has roles on :first and :second')]
	public function memberships( string $person, string $first, string $second ): void {
		$this->oneMembership( $person, $first );
		$this->oneMembership( $person, $second );
	}

	#[Given(':person has a role only on :site')]
	public function oneMembership( string $person, string $site ): void {
		add_user_to_blog( $this->fixture->sites[ $site ], $this->fixture->users[ $person ], 'editor' );
	}

	#[Given(':person has a hub session')]
	#[Given(':person already has a hub session')]
	public function hubSession( string $person ): void {
		$this->hubUserId = $this->fixture->users[ $person ];
	}

	#[Given(':person completed local sign-in on Northcoast')]
	#[When(':person completes local sign-in on Northcoast')]
	public function localLogin( string $person ): void {
		$this->bridge( $person, true );
	}

	#[When(':person signs in locally on Northcoast')]
	public function localLoginWithoutHandOff( string $person ): void {
		$userId = $this->fixture->users[ $person ];
		$this->fixture->enter( 'Northcoast', $userId );
		$this->callbackUrl = (string) apply_filters( 'login_redirect', admin_url(), '', get_userdata( $userId ) );
	}

	#[Then(':person stays on :site')]
	public function staysOnSite( string $person, string $site ): void {
		Assert::assertSame( get_site_url( $this->fixture->sites[ $site ] ) . '/wp-admin/', $this->callbackUrl );
	}

	#[Given(':person has not completed the second factor on Northcoast')]
	public function unfinishedLogin( string $person ): void {
		$this->fixture->enter( 'Northcoast', 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	#[When(':person tries to establish the hub session')]
	public function tryUnfinishedLogin( string $person ): void {
		$this->bridge( $person, false );
	}

	private function bridge( string $person, bool $completed ): void {
		$userId = $this->fixture->users[ $person ];
		$this->fixture->enter( 'Northcoast', $completed ? $userId : 0 );
		if ( $completed ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $userId, time() + 3600, 'logged_in' );
			$redirect = apply_filters( 'login_redirect', get_site_url() . '/wp-admin/', '', get_userdata( $userId ) );
			Assert::assertStringContainsString( 'bridge_start', $redirect, 'A completed local login must start the hub hand-off.' );
		}
		$this->fixture->enter( 'Hub', $this->hubUserId );
		$transaction = LocalSessionBridge::begin( $this->fixture->sites['Northcoast'], $userId, get_site_url( $this->fixture->sites['Northcoast'] ) . '/wp-admin/' );
		Assert::assertIsArray( $transaction );
		$this->fixture->enter( 'Northcoast', $completed ? $userId : 0 );
		$proof = LocalSessionBridge::prove( $this->fixture->sites['Hub'], $transaction['state'] );
		if ( is_wp_error( $proof ) ) {
			$this->bridgeResult = $proof;
			return;
		}
		$this->fixture->enter( 'Hub', $this->hubUserId );
		$this->bridgeResult = LocalSessionBridge::complete( $transaction['state'], $transaction['state'], $proof );
		if ( is_array( $this->bridgeResult ) ) {
			$this->hubUserId = $this->bridgeResult['user_id'];
		}
	}

	#[When(':person requests single sign-on to :site')]
	#[Given(':person has started single sign-on to :site')]
	public function requestSso( string $person, string $site ): void {
		$this->fixture->enter( 'Hub', $this->hubUserId );
		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/authorize' );
		$request->set_param( 'client_blog_id', $this->fixture->sites[ $site ] );
		$request->set_param( 'state', 'bdd-state' );
		$this->callbackUrl = AuthorizeEndpoint::handleRequest( $request )->get_headers()['Location'];
	}


    #[Given('OneLogin is configured without automatic provisioning')]
    public function configureOneLogin(): void {
        $this->fixture->enter( 'Hub' );
        [ $this->samlPrivateKey, $this->samlCertificate, $certificateBody ] = \PV\WpmsSso\Tests\Support\SignedSamlResponse::generateKeyPair();
        \PV\WpmsSso\SamlSettings::save( get_current_network_id(), [ 'enabled' => true, 'idp_entity_id' => 'https://idp.example.test/metadata', 'idp_sso_url' => 'https://idp.example.test/sso', 'idp_x509cert' => $certificateBody, 'auto_provision' => false ] );
    }

    #[When(':person requests OneLogin sign-in to :site')]
    public function oneLogin( string $person, string $site ): void {
        $this->fixture->enter( 'Hub' );
        $start = \PV\WpmsSso\SamlProvider::startRedirectUrl( $this->fixture->sites[ $site ], 'bdd-state' );
        parse_str( (string) parse_url( $start, PHP_URL_QUERY ), $arguments );
        $xml = gzinflate( base64_decode( $arguments['SAMLRequest'] ) );
        Assert::assertSame( 1, preg_match( '/ID="([^"]+)"/', $xml, $matches ) );
        $signedResponse = \PV\WpmsSso\Tests\Support\SignedSamlResponse::build( $matches[1], strtolower( $person ) . '@example.test', $this->samlPrivateKey, $this->samlCertificate );
        $result = \PV\WpmsSso\SamlProvider::processSamlResponse( $signedResponse );
        $this->callbackUrl = $result['redirect_url'];
    }

    #[Then('the unknown account is refused')]
    public function unknownAccount(): void {
        Assert::assertStringContainsString( 'saml_unknown_user', $this->callbackUrl );
        Assert::assertFalse( get_user_by( 'email', 'unknown@example.test' ) );
    }

	#[Then(':person receives access to :site')]
	public function permitted( string $person, string $site ): void {
		parse_str( (string) parse_url( $this->callbackUrl, PHP_URL_QUERY ), $arguments );
		Assert::assertArrayHasKey( 'ticket', $arguments, $this->callbackUrl );
		$this->fixture->enter( $site );
		$ticket = ClientFlow::verifyAndConsumeTicket( $arguments['ticket'], 'bdd-state' );
		Assert::assertNotInstanceOf( WP_Error::class, $ticket );
		Assert::assertSame( $this->fixture->users[ $person ], $ticket->userId );
	}

	#[Then(':site refuses access')]
	public function denied( string $site ): void {
		Assert::assertStringContainsString( 'error=access_denied', $this->callbackUrl );
		Assert::assertStringStartsWith( get_site_url( $this->fixture->sites[ $site ] ), $this->callbackUrl );
	}

	#[When(':person loses their :site role before returning')]
	public function revoke( string $person, string $site ): void {
		remove_user_from_blog( $this->fixture->users[ $person ], $this->fixture->sites[ $site ] );
	}

	#[Then(':site refuses the issued sign-in')]
	public function revoked( string $site ): void {
		parse_str( (string) parse_url( $this->callbackUrl, PHP_URL_QUERY ), $arguments );
		Assert::assertArrayHasKey( 'ticket', $arguments );
		$this->fixture->enter( $site );
		$ticket = ClientFlow::verifyAndConsumeTicket( $arguments['ticket'], 'bdd-state' );
		Assert::assertInstanceOf( WP_Error::class, $ticket );
		Assert::assertSame( ClientFlow::ERROR_NOT_ALLOWED, $ticket->get_error_code() );
	}

	#[Then('the hub session is refused')]
	public function refusedHub(): void {
		Assert::assertInstanceOf( WP_Error::class, $this->bridgeResult );
	}

	#[Then(':person keeps the hub session')]
	public function sameIdentity( string $person ): void {
		Assert::assertSame( $this->fixture->users[ $person ], $this->hubUserId );
	}
}

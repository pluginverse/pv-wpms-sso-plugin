<?php

declare( strict_types=1 );

namespace PV\WpmsSso\Tests\Integration;

use PV\WpmsSso\AuthorizeEndpoint;
use PV\WpmsSso\ClientFlow;
use PV\WpmsSso\Tests\Support\JourneyFixture;
use WP_REST_Request;
use WP_Error;

final class MembershipJourneyTest extends JourneyFixture {

	/**
	 * @group SSO-002
	 * @group SSO-003
	 * @group SSO-004
	 * @group SSO-007
	 * @dataProvider membershipExamples
	 */
	public function testOnlyExistingDestinationMembersCanCompleteSso( string $person, array $memberships, string $destination, bool $permitted ): void {
		foreach ( $memberships as $site ) {
			add_user_to_blog( $this->sites[ $site ], $this->users[ $person ], 'editor' );
		}
		$this->enter( 'Hub', $this->users[ $person ] );
		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/authorize' );
		$request->set_param( 'client_blog_id', $this->sites[ $destination ] );
		$request->set_param( 'state', 'membership-state' );
		$location = AuthorizeEndpoint::handleRequest( $request )->get_headers()['Location'];
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $arguments );
		if ( ! $permitted ) {
			self::assertSame( 'access_denied', $arguments['error'] );
			self::assertArrayNotHasKey( 'ticket', $arguments );
			return;
		}
		$this->enter( $destination );
		$ticket = ClientFlow::verifyAndConsumeTicket( $arguments['ticket'], 'membership-state' );
		self::assertNotInstanceOf( WP_Error::class, $ticket );
		self::assertSame( $this->users[ $person ], $ticket->userId );
	}

	public static function membershipExamples(): array {
		return [
			'site local stays local' => [ 'Casey', [ 'Northcoast' ], 'Southmountain', false ],
			'network local member' => [ 'Alex', [ 'Northcoast', 'Southmountain' ], 'Southmountain', true ],
			'network local unassigned site' => [ 'Alex', [ 'Northcoast', 'Southmountain' ], 'CoastExtra', false ],
			'network local cannot cross networks' => [ 'Alex', [ 'Northcoast', 'Southmountain' ], 'Ridgeview', false ],
			'cross network member' => [ 'Riley', [ 'Northcoast', 'Ridgeview' ], 'Ridgeview', true ],
		];
	}

	/** @group SSO-008 */
	public function testRoleRemovedAfterMintIsRejectedAtRedemption(): void {
		add_user_to_blog( $this->sites['Ridgeview'], $this->users['Riley'], 'editor' );
		$this->enter( 'Hub', $this->users['Riley'] );
		$request = new WP_REST_Request( 'GET', '/pv-wpms-sso/v1/authorize' );
		$request->set_param( 'client_blog_id', $this->sites['Ridgeview'] );
		$request->set_param( 'state', 'revoked-state' );
		$location = AuthorizeEndpoint::handleRequest( $request )->get_headers()['Location'];
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $arguments );
		remove_user_from_blog( $this->users['Riley'], $this->sites['Ridgeview'] );
		$this->enter( 'Ridgeview' );
		$result = ClientFlow::verifyAndConsumeTicket( $arguments['ticket'], 'revoked-state' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( ClientFlow::ERROR_NOT_ALLOWED, $result->get_error_code() );
	}
}

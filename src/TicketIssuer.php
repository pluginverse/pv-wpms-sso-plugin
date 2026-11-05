<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function bin2hex;
use function get_avatar_url;
use function get_current_blog_id;
use function get_userdata;
use function random_bytes;
use function time;

/**
 * Mints a signed single-use ticket for a user and returns the client
 * callback URL carrying it. Shared by the network-SSO authorize endpoint
 * and the SAML ACS (decision 0005: everything after identity confirmation
 * is the same ticket hand-off).
 */
final class TicketIssuer {

	public static function issueTicketCallbackUrl( int $userId, int $clientBlogId, string $state ): string {
		$user = get_userdata( $userId );
		if ( false === $user ) {
			return '';
		}

		$avatarUrl = get_avatar_url( $user->ID );
		$issuedAt  = time();

		$ticket = new Ticket(
			issuerBlogId: get_current_blog_id(),
			audienceBlogId: $clientBlogId,
			userId: $userId,
			userLogin: $user->user_login,
			userEmail: $user->user_email,
			displayName: $user->display_name,
			avatarUrl: is_string( $avatarUrl ) ? $avatarUrl : '',
			state: $state,
			ticketId: bin2hex( random_bytes( 16 ) ),
			issuedAt: $issuedAt,
			expiresAt: $issuedAt + TicketSigner::TICKET_TTL_SECONDS,
		);

		$signingKey = KeyStore::getForBlog( get_current_blog_id() );
		$token      = TicketSigner::issue( $ticket, $signingKey );

		return add_query_arg(
			[
				ClientFlow::QUERY_PARAM => ClientFlow::ACTION_CALLBACK,
				'state'                 => $state,
				'ticket'                => $token,
			],
			get_site_url( $clientBlogId ) . '/wp-login.php'
		);
	}
}

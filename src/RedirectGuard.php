<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function array_values;
use function in_array;
use function is_array;
use function parse_url; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure and unit-tested without WordPress; PHP 8.2 parses consistently.
use function str_starts_with;
use function strtolower;

/**
 * Redirect-target validation: SSO must never become an open redirect.
 *
 * A destination is allowed when it has no host (site-relative path) or its
 * host matches the site URL of the site the user is logging into. Additional
 * hosts only through the pv_wpms_sso/allowed_redirect_hosts filter.
 */
final class RedirectGuard {

	public const ALLOWED_HOSTS_FILTER = 'pv_wpms_sso/allowed_redirect_hosts';
	private static string $registeredRedirectHost = '';

	/** Authorize one generated cross-domain hop to a registered site's URL. */
	public static function allowSiteRedirect( string $destinationUrl, int $destinationBlogId ): bool {
		if ( ! self::urlMatchesSiteHost( $destinationUrl, get_site_url( $destinationBlogId ) ) ) {
			return false;
		}
		self::$registeredRedirectHost = (string) wp_parse_url( $destinationUrl, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', [ self::class, 'allowRegisteredRedirectHost' ] );
		return true;
	}

	/**
	 * @param array<string> $hosts
	 * @return array<string>
	 */
	public static function allowRegisteredRedirectHost( array $hosts ): array {
		if ( '' !== self::$registeredRedirectHost ) {
			$hosts[] = self::$registeredRedirectHost;
		}
		return $hosts;
	}

	/**
	 * Pure check: is $destinationUrl a safe post-login target for a site whose
	 * canonical URL is $siteUrl? Host (and port) must match; scheme http/https;
	 * host-relative paths are allowed.
	 *
	 * @param array<int, string> $extraAllowedHosts lowercase "host" or "host:port" entries
	 */
	public static function destinationAllowed( string $destinationUrl, string $siteUrl, array $extraAllowedHosts = [] ): bool {
		$destinationParts = parse_url( $destinationUrl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure and unit-tested without WordPress; PHP 8.2 parses consistently.

		if ( ! is_array( $destinationParts ) ) {
			return false;
		}

		// Only clean site-relative paths ("", "/wp-admin/...") stay on the site
		// by construction; anything else without a host ("::::", "?x", "x/y")
		// is not a redirect target we are willing to issue.
		if ( ! isset( $destinationParts['host'] ) ) {
			$path = $destinationParts['path'] ?? '';

			return ! isset( $destinationParts['scheme'] )
				&& ( '' === $path || str_starts_with( $path, '/' ) );
		}

		if ( isset( $destinationParts['scheme'] ) && ! in_array( strtolower( $destinationParts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		$destinationHost = strtolower( (string) $destinationParts['host'] );
		if ( isset( $destinationParts['port'] ) ) {
			$destinationHost .= ':' . (int) $destinationParts['port'];
		}

		$allowedHosts = self::siteHosts( $siteUrl, $extraAllowedHosts );

		return in_array( $destinationHost, $allowedHosts, true );
	}

	/**
	 * Is $candidateUrl on the same host (and port) as $siteUrl, with an
	 * http/https scheme? Used to validate IdP-built callback URLs and probe
	 * origins against the registered site they claim to be.
	 */
	public static function urlMatchesSiteHost( string $candidateUrl, string $siteUrl ): bool {
		$candidateParts = parse_url( $candidateUrl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure and unit-tested without WordPress; PHP 8.2 parses consistently.
		$siteParts      = parse_url( $siteUrl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure and unit-tested without WordPress; PHP 8.2 parses consistently.

		if ( ! is_array( $candidateParts ) || ! is_array( $siteParts ) ) {
			return false;
		}

		if ( ! isset( $candidateParts['host'], $siteParts['host'] ) ) {
			return false;
		}

		if ( isset( $candidateParts['scheme'] ) && ! in_array( strtolower( $candidateParts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		$candidateHost = strtolower( (string) $candidateParts['host'] );
		$siteHost      = strtolower( (string) $siteParts['host'] );

		if ( isset( $candidateParts['port'] ) ) {
			$candidateHost .= ':' . (int) $candidateParts['port'];
		}
		if ( isset( $siteParts['port'] ) ) {
			$siteHost .= ':' . (int) $siteParts['port'];
		}

		return $candidateHost === $siteHost;
	}

	/**
	 * @param array<int, string> $extraAllowedHosts
	 * @return array<int, string>
	 */
	private static function siteHosts( string $siteUrl, array $extraAllowedHosts ): array {
		$siteParts = parse_url( $siteUrl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure and unit-tested without WordPress; PHP 8.2 parses consistently.
		$hosts     = [];

		if ( is_array( $siteParts ) && isset( $siteParts['host'] ) ) {
			$host = strtolower( (string) $siteParts['host'] );
			if ( isset( $siteParts['port'] ) ) {
				$host .= ':' . (int) $siteParts['port'];
			}
			$hosts[] = $host;
		}

		foreach ( $extraAllowedHosts as $extraHost ) {
			$hosts[] = strtolower( $extraHost );
		}

		return array_values( array_unique( $hosts ) );
	}
}

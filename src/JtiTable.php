<?php

declare( strict_types=1 );

namespace PV\WpmsSso;

use function hash;
use function time;
use wpdb;

/**
 * Global single-use registry for ticket IDs (jti claims).
 *
 * One table shared by every network of the install; the unique primary key
 * makes claiming atomic, which is what actually blocks replay.
 */
final class JtiTable {

	public const TABLE_SUFFIX = 'pv_wpms_sso_jti';
	private const PURGE_GRACE_SECONDS = 3600;

	private static bool $tableVerified = false;

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Creates the table on activation (any network: one table serves the install).
	 * dbDelta is idempotent, so this doubles as an upgrader.
	 */
	public static function createTable(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::tableName();
		$charsetCollate  = $wpdb->get_charset_collate();
		$createStatement = "CREATE TABLE {$table} (
			jti_hash varchar(64) NOT NULL,
			expires_at bigint unsigned NOT NULL,
			PRIMARY KEY  (jti_hash),
			KEY expires_at (expires_at)
		) {$charsetCollate};";

		dbDelta( $createStatement );
		self::$tableVerified = true;
	}

	/**
	 * Claim a ticket ID exactly once. Deletes long-expired rows opportunistically.
	 *
	 * @return bool true when this call claimed the ID first, false when it was
	 *              already claimed (replay) or the insert failed.
	 */
	public static function claimTicketId( string $ticketId, int $expiresAt, ?int $now = null ): bool {
		global $wpdb;

		self::ensureTableExists();

		$now         = $now ?? time();
		$purgeBefore = $now - self::PURGE_GRACE_SECONDS;

		$deleteSql = $wpdb->prepare( 'DELETE FROM ' . self::tableName() . ' WHERE expires_at < %d', $purgeBefore );
		if ( is_string( $deleteSql ) ) {
			$wpdb->query( $deleteSql );
		}

		$insertSql = $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::tableName() . ' (jti_hash, expires_at) VALUES (%s, %d)',
			hash( 'sha256', $ticketId ),
			$expiresAt
		);

		return is_string( $insertSql ) && 1 === (int) $wpdb->query( $insertSql );
	}

	/** @return int number of rows removed */
	public static function purgeExpired( int $olderThanTimestamp ): int {
		global $wpdb;

		self::ensureTableExists();

		$deleteSql = $wpdb->prepare( 'DELETE FROM ' . self::tableName() . ' WHERE expires_at < %d', $olderThanTimestamp );

		return is_string( $deleteSql ) ? (int) $wpdb->query( $deleteSql ) : 0;
	}

	public static function ensureTableExists(): void {
		if ( self::$tableVerified ) {
			return;
		}

		global $wpdb;

		$lookupSql   = $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tableName() );
		$knownTables = is_string( $lookupSql )
			? (array) $wpdb->get_col( $lookupSql )
			: [];
		$exists      = in_array( self::tableName(), $knownTables, true );

		if ( $exists ) {
			self::$tableVerified = true;

			return;
		}

		self::createTable();
	}
}

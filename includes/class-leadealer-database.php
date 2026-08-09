<?php
/**
 * Database installation and maintenance.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin tables.
 */
final class Leadealer_Database {

	/**
	 * Database schema version.
	 *
	 * @var string
	 */
	public const VERSION = '6';

	/**
	 * Install or upgrade tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$entries_table   = self::entries_table();
		$events_table    = self::events_table();
		$pow_table       = self::pow_table();
		$rate_table      = self::rate_table();
		$uploads_table   = self::uploads_table();

		$entries_sql = "CREATE TABLE {$entries_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			submission_uuid char(36) NOT NULL,
			idempotency_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'received',
			payload longtext NOT NULL,
			retain_payload tinyint(1) unsigned NOT NULL DEFAULT 1,
			mail_to varchar(320) NOT NULL DEFAULT '',
			mail_subject text NULL,
			mail_body longtext NULL,
			mail_headers longtext NULL,
			attachment_manifest longtext NULL,
			mail_status varchar(20) NOT NULL DEFAULT 'pending',
			mail_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			mail_error text NULL,
			next_mail_attempt_at datetime NULL DEFAULT NULL,
			mail_last_attempt_at datetime NULL DEFAULT NULL,
			mail_handed_off_at datetime NULL DEFAULT NULL,
			mail_locked_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY form_submission (form_id, submission_uuid),
			KEY form_created (form_id, created_at),
			KEY mail_status (mail_status),
			KEY delivery_due (mail_status, next_mail_attempt_at)
		) {$charset_collate};";

		$events_sql = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			event_type varchar(40) NOT NULL,
			event_status varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_created (entry_id, created_at),
			KEY event_type (event_type)
		) {$charset_collate};";

		$pow_sql = "CREATE TABLE {$pow_table} (
			token_hash char(64) NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$rate_sql = "CREATE TABLE {$rate_table} (
			bucket_hash char(64) NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 1,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (bucket_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$uploads_sql = "CREATE TABLE {$uploads_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			field_id varchar(191) NOT NULL DEFAULT '',
			entry_id bigint(20) unsigned NULL DEFAULT NULL,
			reservation_hash char(64) NOT NULL DEFAULT '',
			reserved_at datetime NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			storage_filename varchar(191) NOT NULL DEFAULT '',
			original_extension varchar(10) NOT NULL DEFAULT '',
			mime_type varchar(100) NOT NULL DEFAULT '',
			byte_size int(10) unsigned NOT NULL DEFAULT 0,
			width smallint(5) unsigned NOT NULL DEFAULT 0,
			height smallint(5) unsigned NOT NULL DEFAULT 0,
			client_ip_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			claimed_at datetime NULL DEFAULT NULL,
			expires_at datetime NOT NULL,
			deleted_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY status_expires (status, expires_at),
			KEY reservation_hash (reservation_hash),
			KEY entry_id (entry_id),
			KEY form_created (form_id, created_at)
		) {$charset_collate};";

		dbDelta( $entries_sql );
		dbDelta( $events_sql );
		dbDelta( $pow_sql );
		dbDelta( $rate_sql );
		dbDelta( $uploads_sql );

		update_option( 'leadealer_db_version', self::VERSION, false );
	}

	/**
	 * Remove expired security rows.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;

		$now        = time();
		$pow_table  = self::pow_table();
		$rate_table = self::rate_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pow_table} WHERE expires_at < %d", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal dedicated table cleanup; nothing to cache for a write.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rate_table} WHERE expires_at < %d", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal dedicated table cleanup; nothing to cache for a write.
	}

	/**
	 * Get entries table name.
	 *
	 * @return string
	 */
	public static function entries_table() {
		global $wpdb;

		return $wpdb->prefix . 'leadealer_entries';
	}

	/**
	 * Get Lead Vault event table name.
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'leadealer_events';
	}

	/**
	 * Get Proof of Work replay table name.
	 *
	 * @return string
	 */
	public static function pow_table() {
		global $wpdb;

		return $wpdb->prefix . 'leadealer_pow_used';
	}

	/**
	 * Get rate limit table name.
	 *
	 * @return string
	 */
	public static function rate_table() {
		global $wpdb;

		return $wpdb->prefix . 'leadealer_rate_limits';
	}

	/**
	 * Get uploaded-photo table name.
	 *
	 * @return string
	 */
	public static function uploads_table() {
		global $wpdb;

		return $wpdb->prefix . 'leadealer_uploads';
	}
}

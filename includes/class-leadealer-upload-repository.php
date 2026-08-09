<?php
/**
 * Uploaded photo persistence and hardened on-disk storage.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the lifecycle of re-encoded photo uploads attached to Lead Vault entries.
 */
final class Leadealer_Upload_Repository {

	/** @var int Seconds an unclaimed upload token remains valid. */
	public const TOKEN_TTL = 1800;

	/** @var int Seconds a reserved upload remains recoverable after a partial submission. */
	public const RESERVATION_TTL = DAY_IN_SECONDS;

	/** @var int Maximum accepted upload size in bytes, before re-encoding. */
	public const MAX_UPLOAD_BYTES = 10485760; // 10 MB.

	/** @var int Maximum re-encoded output dimension in pixels. */
	public const MAX_OUTPUT_DIMENSION = 2000;

	/** @var int Maximum accepted input dimension in pixels. */
	public const MAX_INPUT_DIMENSION = 12000;

	/** @var int Maximum total decoded input pixels. */
	public const MAX_INPUT_PIXELS = 25000000;

	/** @var int Grace period for asynchronous mail transports to read attachments. */
	public const MAIL_HANDOFF_FILE_GRACE = DAY_IN_SECONDS;

	/** @var string[] */
	public const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp' );

	/** @var string[] */
	public const ALLOWED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Resolve the effective upload limit enforced by both Leadealer and PHP.
	 *
	 * @return int Maximum accepted bytes.
	 */
	public static function max_upload_bytes() {
		$wp_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : self::MAX_UPLOAD_BYTES;
		return $wp_limit > 0 ? min( self::MAX_UPLOAD_BYTES, $wp_limit ) : self::MAX_UPLOAD_BYTES;
	}

	/**
	 * Create a pending upload row and return its signed claim token.
	 *
	 * @param int    $form_id   Form ID.
	 * @param string $field_id  Field ID.
	 * @param string $filename  Server-generated stored filename.
	 * @param string $extension Re-encoded extension.
	 * @param string $mime_type Re-encoded MIME type.
	 * @param int    $byte_size Re-encoded file size.
	 * @param int    $width     Re-encoded width.
	 * @param int    $height    Re-encoded height.
	 * @return array{id:int,token:string}|WP_Error
	 */
	public function create_pending( $form_id, $field_id, $filename, $extension, $mime_type, $byte_size, $width, $height ) {
		global $wpdb;

		$form_id   = absint( $form_id );
		$field_id  = sanitize_key( $field_id );
		$filename  = is_string( $filename ) ? $filename : '';
		$extension = is_string( $extension ) ? strtolower( $extension ) : '';
		$mime_type = is_string( $mime_type ) ? strtolower( trim( $mime_type ) ) : '';
		$byte_size = absint( $byte_size );
		$width     = absint( $width );
		$height    = absint( $height );

		if (
			$form_id <= 0 ||
			'' === $field_id ||
			! self::valid_storage_filename( $filename ) ||
			$extension !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ||
			! self::extension_matches_mime( $extension, $mime_type ) ||
			$byte_size <= 0 || $byte_size > self::max_upload_bytes() ||
			$width <= 0 || $height <= 0 ||
			$width > self::MAX_OUTPUT_DIMENSION || $height > self::MAX_OUTPUT_DIMENSION
		) {
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		$verification_row = (object) array(
			'storage_filename' => $filename,
			'mime_type'        => $mime_type,
			'byte_size'        => $byte_size,
			'width'            => $width,
			'height'           => $height,
		);
		if ( false === $this->verify_stored_file( $verification_row ) ) {
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		try {
			$secret_hex = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'upload_entropy_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );
		$table      = Leadealer_Database::uploads_table();
		$inserted   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table write.
			$table,
			array(
				'token_hash'         => hash( 'sha256', $secret_hex ),
				'form_id'            => $form_id,
				'field_id'           => $field_id,
				'status'             => 'pending',
				'storage_filename'   => $filename,
				'original_extension' => $extension,
				'mime_type'          => $mime_type,
				'byte_size'          => $byte_size,
				'width'              => $width,
				'height'             => $height,
				'client_ip_hash'     => $this->hash_client_ip(),
				'created_at'         => $now,
				'expires_at'         => $expires_at,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		$upload_id = (int) $wpdb->insert_id;
		$payload   = array(
			'v'     => 1,
			'form'  => $form_id,
			'field' => $field_id,
			'sec'   => $secret_hex,
			'exp'   => time() + self::TOKEN_TTL,
		);
		$json      = wp_json_encode( $payload );
		if ( false === $json ) {
			$this->soft_delete_by_id( $upload_id, 'orphaned' );
			return new WP_Error( 'upload_encoding_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ) );
		}

		$body = $this->base64url_encode( $json );
		return array(
			'id'    => $upload_id,
			'token' => $body . '.' . hash_hmac( 'sha256', $body, $this->get_secret() ),
		);
	}

	/**
	 * Delete newly-created pending uploads when the encompassing submission fails.
	 *
	 * Only rows that are still pending, unclaimed, and owned by no Lead Vault
	 * entry can be removed here. This makes cleanup safe even after a token was
	 * reserved during sanitize_submission().
	 *
	 * @param int[] $upload_ids Upload row IDs created by the current request.
	 * @return void
	 */
	public function discard_pending_uploads( $upload_ids ) {
		global $wpdb;

		$upload_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $upload_ids ) ) ) );
		if ( empty( $upload_ids ) ) {
			return;
		}

		$table = Leadealer_Database::uploads_table();
		foreach ( $upload_ids as $upload_id ) {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Request-scoped cleanup after a failed submission.
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d AND status = 'pending' AND entry_id IS NULL AND deleted_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
					$upload_id
				)
			);
			if ( $row ) {
				$this->soft_delete_row( $row, 'orphaned' );
			}
		}
	}


	/**
	 * Verify and atomically reserve a signed upload token for one submission UUID.
	 *
	 * @param string $token         Signed upload token.
	 * @param int    $form_id       Form ID.
	 * @param string $field_id      File field ID.
	 * @param string $submission_id Submission UUID.
	 * @return int|WP_Error
	 */
	public function consume_upload_token( $token, $form_id, $field_id, $submission_id ) {
		global $wpdb;

		if ( ! is_string( $token ) || strlen( $token ) > 2048 || ! $this->valid_submission_id( $submission_id ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}

		list( $body, $signature ) = $parts;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,1536}$/', $body ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $body, $this->get_secret() ), $signature ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}

		$payload = json_decode( $this->base64url_decode( $body ), true );
		if ( ! is_array( $payload ) || 1 !== (int) ( isset( $payload['v'] ) ? $payload['v'] : 0 ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}
		foreach ( array( 'form', 'field', 'sec', 'exp' ) as $key ) {
			if ( ! array_key_exists( $key, $payload ) || ! is_scalar( $payload[ $key ] ) ) {
				return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
			}
		}

		$form_id  = absint( $form_id );
		$field_id = sanitize_key( $field_id );
		if ( $form_id !== absint( $payload['form'] ) || $field_id !== sanitize_key( (string) $payload['field'] ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo does not match this form. Please attach it again.', 'leadealer' ) );
		}
		if ( ! is_string( $payload['sec'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload['sec'] ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}

		$expires = (int) $payload['exp'];
		if ( $expires <= 0 || time() > $expires || $expires > time() + self::TOKEN_TTL + 60 ) {
			return new WP_Error( 'expired_upload_token', __( 'The attached photo has expired. Please attach it again.', 'leadealer' ) );
		}

		$table              = Leadealer_Database::uploads_table();
		$token_hash         = hash( 'sha256', $payload['sec'] );
		$reservation_hash   = $this->reservation_hash( $submission_id );
		$now                = current_time( 'mysql', true );
		$reservation_expiry = gmdate( 'Y-m-d H:i:s', time() + self::RESERVATION_TTL );
		$result             = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic reservation prevents replay across submissions.
			$wpdb->prepare(
				"UPDATE {$table} SET reservation_hash = %s, reserved_at = COALESCE(reserved_at, %s), expires_at = CASE WHEN expires_at < %s THEN %s ELSE expires_at END WHERE token_hash = %s AND form_id = %d AND field_id = %s AND status = 'pending' AND deleted_at IS NULL AND expires_at > %s AND (reservation_hash = '' OR reservation_hash = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
				$reservation_hash,
				$now,
				$reservation_expiry,
				$reservation_expiry,
				$token_hash,
				$form_id,
				$field_id,
				$now,
				$reservation_hash
			)
		);
		if ( false === $result ) {
			return new WP_Error( 'upload_reservation_failed', __( 'The attached photo could not be verified. Please attach it again.', 'leadealer' ) );
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate verification of atomic reservation.
			$wpdb->prepare(
				"SELECT id, reservation_hash FROM {$table} WHERE token_hash = %s AND form_id = %d AND field_id = %s AND status = 'pending' AND deleted_at IS NULL AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
				$token_hash,
				$form_id,
				$field_id,
				$now
			)
		);
		if ( ! $row || ! is_string( $row->reservation_hash ) || ! hash_equals( $reservation_hash, $row->reservation_hash ) ) {
			return new WP_Error( 'invalid_upload_token', __( 'The attached photo could not be found. Please attach it again.', 'leadealer' ) );
		}

		return (int) $row->id;
	}

	/**
	 * Atomically bind a reserved pending upload to its Lead Vault entry.
	 *
	 * @param int    $upload_id     Upload row ID.
	 * @param int    $entry_id      Entry ID.
	 * @param int    $form_id       Form ID.
	 * @param string $field_id      File field ID.
	 * @param string $submission_id Submission UUID.
	 * @return bool
	 */
	public function claim( $upload_id, $entry_id, $form_id, $field_id, $submission_id ) {
		global $wpdb;

		if ( ! $this->valid_submission_id( $submission_id ) ) {
			return false;
		}

		$table  = Leadealer_Database::uploads_table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim.
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'claimed', entry_id = %d, claimed_at = %s WHERE id = %d AND form_id = %d AND field_id = %s AND reservation_hash = %s AND status = 'pending' AND deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
				absint( $entry_id ),
				current_time( 'mysql', true ),
				absint( $upload_id ),
				absint( $form_id ),
				sanitize_key( $field_id ),
				$this->reservation_hash( $submission_id )
			)
		);
		return 1 === (int) $result;
	}

	/**
	 * Recover reservations belonging to an already-created entry after a partial request failure.
	 *
	 * @param int    $entry_id      Entry ID.
	 * @param int    $form_id       Form ID.
	 * @param string $submission_id Submission UUID.
	 * @return int Number of rows claimed.
	 */
	public function claim_reserved_for_entry( $entry_id, $form_id, $submission_id ) {
		global $wpdb;

		if ( absint( $entry_id ) <= 0 || absint( $form_id ) <= 0 || ! $this->valid_submission_id( $submission_id ) ) {
			return 0;
		}

		$table  = Leadealer_Database::uploads_table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Crash-recovery claim of only this submission's reservations.
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'claimed', entry_id = %d, claimed_at = %s WHERE form_id = %d AND reservation_hash = %s AND status = 'pending' AND deleted_at IS NULL AND expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
				absint( $entry_id ),
				current_time( 'mysql', true ),
				absint( $form_id ),
				$this->reservation_hash( $submission_id ),
				current_time( 'mysql', true )
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/** @param int $entry_id Entry ID. @return array<int,object> */
	public function get_by_entry( $entry_id ) {
		global $wpdb;
		$table = Leadealer_Database::uploads_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table lookup.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d AND status = 'claimed' AND deleted_at IS NULL ORDER BY id ASC", absint( $entry_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
		);
	}

	/**
	 * Count available claimed attachments for a set of Lead Vault entries.
	 *
	 * @param int[] $entry_ids Entry IDs.
	 * @return array<int,int> Entry ID => attachment count.
	 */
	public function count_by_entries( $entry_ids ) {
		global $wpdb;

		$entry_ids = array_values( array_filter( array_map( 'absint', (array) $entry_ids ) ) );
		if ( empty( $entry_ids ) ) {
			return array();
		}

		$table        = Leadealer_Database::uploads_table();
		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate for the admin Lead Vault list.
			$wpdb->prepare( "SELECT entry_id, COUNT(*) AS total FROM {$table} WHERE entry_id IN ({$placeholders}) AND status = 'claimed' AND deleted_at IS NULL GROUP BY entry_id", $entry_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table and generated %d list.
		);
		$counts       = array();

		foreach ( $rows as $row ) {
			$counts[ absint( $row->entry_id ) ] = absint( $row->total );
		}

		return $counts;
	}

	/** @param int $entry_id Entry ID. @param string $field_id Field ID. @return object|null */
	public function get_by_entry_and_field( $entry_id, $field_id ) {
		global $wpdb;
		$table = Leadealer_Database::uploads_table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table lookup.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d AND field_id = %s AND status = 'claimed' AND deleted_at IS NULL LIMIT 1", absint( $entry_id ), sanitize_key( $field_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
		);
	}

	/**
	 * Resolve an upload row's path while enforcing its storage-directory boundary.
	 *
	 * @param object $row Upload row.
	 * @return string Absolute verified path, or empty string.
	 */
	public function get_file_path( $row ) {
		if ( ! is_object( $row ) || empty( $row->storage_filename ) ) {
			return '';
		}
		$filename = (string) $row->storage_filename;
		if ( ! self::valid_storage_filename( $filename ) || basename( $filename ) !== $filename ) {
			return '';
		}

		foreach ( self::candidate_storage_dirs() as $dir ) {
			$path = trailingslashit( $dir ) . $filename;
			if ( is_link( $path ) || ! is_file( $path ) ) {
				continue;
			}
			$real_dir  = realpath( $dir );
			$real_path = realpath( $path );
			if ( false !== $real_dir && false !== $real_path && dirname( $real_path ) === $real_dir ) {
				return $real_path;
			}
		}
		return '';
	}

	/**
	 * Re-verify an already stored attachment immediately before reading or mailing it.
	 *
	 * @param object $row Upload row.
	 * @return array{path:string,mime:string,extension:string,byte_size:int,width:int,height:int}|false
	 */
	public function verify_stored_file( $row ) {
		$path = $this->get_file_path( $row );
		if ( '' === $path || is_link( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$filename  = basename( $path );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$size      = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Explicit false handling below.
		if ( false === $size || $size <= 0 || $size > self::max_upload_bytes() ) {
			return false;
		}
		if ( isset( $row->byte_size ) && (int) $row->byte_size > 0 && (int) $row->byte_size !== (int) $size ) {
			return false;
		}
		if ( ! function_exists( 'finfo_open' ) ) {
			return false;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return false;
		}
		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		$mime = is_string( $mime ) ? strtolower( $mime ) : '';
		if ( ! self::extension_matches_mime( $extension, $mime ) ) {
			return false;
		}
		if ( isset( $row->mime_type ) && '' !== (string) $row->mime_type && strtolower( (string) $row->mime_type ) !== $mime ) {
			return false;
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Explicit validation follows.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}
		$width  = (int) $info[0];
		$height = (int) $info[1];
		if ( $width <= 0 || $height <= 0 || $width > self::MAX_OUTPUT_DIMENSION || $height > self::MAX_OUTPUT_DIMENSION ) {
			return false;
		}
		$expected_type = 'image/png' === $mime ? IMAGETYPE_PNG : ( 'image/webp' === $mime ? IMAGETYPE_WEBP : IMAGETYPE_JPEG );
		if ( (int) $info[2] !== $expected_type ) {
			return false;
		}
		if ( isset( $row->width ) && (int) $row->width > 0 && (int) $row->width !== $width ) {
			return false;
		}
		if ( isset( $row->height ) && (int) $row->height > 0 && (int) $row->height !== $height ) {
			return false;
		}

		return array(
			'path'      => $path,
			'mime'      => $mime,
			'extension' => $extension,
			'byte_size' => (int) $size,
			'width'     => $width,
			'height'    => $height,
		);
	}

	/** @param int $entry_id Entry ID. @return void */
	public function soft_delete_for_entry( $entry_id ) {
		$this->soft_delete_for_entries( array( absint( $entry_id ) ) );
	}

	/** @param array<int,int> $entry_ids Entry IDs. @return void */
	public function soft_delete_for_entries( $entry_ids ) {
		global $wpdb;
		$entry_ids = array_values( array_filter( array_map( 'absint', (array) $entry_ids ) ) );
		if ( empty( $entry_ids ) ) {
			return;
		}
		$table        = Leadealer_Database::uploads_table();
		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table lookup immediately before deletion.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id IN ({$placeholders}) AND status = 'claimed' AND deleted_at IS NULL", $entry_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Internal table and generated %d list.
		);
		foreach ( $rows as $row ) {
			$this->soft_delete_row( $row );
		}
	}

	/** @return void */
	public function cleanup_handed_off_unretained() {
		global $wpdb;
		$uploads_table = Leadealer_Database::uploads_table();
		$entries_table = Leadealer_Database::entries_table();
		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - self::MAIL_HANDOFF_FILE_GRACE );
		$rows          = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup.
			$wpdb->prepare( "SELECT u.* FROM {$uploads_table} u INNER JOIN {$entries_table} e ON e.id = u.entry_id WHERE u.status = 'claimed' AND u.deleted_at IS NULL AND e.retain_payload = 0 AND e.mail_status = 'handed_off' AND e.mail_handed_off_at IS NOT NULL AND e.mail_handed_off_at < %s LIMIT 200", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names only.
		);
		foreach ( $rows as $row ) {
			$this->soft_delete_row( $row );
		}
	}

	/** @return void */
	public function cleanup_unclaimed() {
		global $wpdb;
		$table = Leadealer_Database::uploads_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND expires_at < %s LIMIT 200", current_time( 'mysql', true ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
		);
		foreach ( $rows as $row ) {
			$this->soft_delete_row( $row, 'orphaned' );
		}
	}

	/**
	 * Get the secret-derived hardened storage directory.
	 *
	 * @return string Absolute path.
	 */
	public static function storage_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! is_array( $upload_dir ) || ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) || ! is_string( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base_dir = wp_normalize_path( $upload_dir['basedir'] );
		if ( '' === $base_dir || ! self::is_absolute_path( $base_dir ) ) {
			return '';
		}

		$suffix = substr( hash_hmac( 'sha256', 'leadealer-private-upload-directory', self::plugin_secret() ), 0, 24 );
		$dir    = trailingslashit( $base_dir ) . 'leadealer-private-' . $suffix;
		self::harden_storage_dir( $dir );

		return is_dir( $dir ) && ! is_link( $dir ) ? $dir : '';
	}

	/**
	 * Delete one upload by database ID after a failed internal operation.
	 *
	 * @param int    $upload_id Upload row ID.
	 * @param string $status    New status.
	 * @return void
	 */
	private function soft_delete_by_id( $upload_id, $status ) {
		global $wpdb;
		$table = Leadealer_Database::uploads_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal cleanup lookup.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $upload_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name only.
		);
		if ( $row ) {
			$this->soft_delete_row( $row, $status );
		}
	}

	/** @param object $row Upload row. @param string $new_status Optional status. @return void */
	private function soft_delete_row( $row, $new_status = '' ) {
		global $wpdb;
		$path = $this->get_file_path( $row );
		if ( '' !== $path && is_file( $path ) && ! is_link( $path ) ) {
			wp_delete_file( $path );
		}
		$table  = Leadealer_Database::uploads_table();
		$data   = array( 'storage_filename' => '', 'deleted_at' => current_time( 'mysql', true ) );
		$format = array( '%s', '%s' );
		if ( '' !== $new_status ) {
			$data['status'] = sanitize_key( $new_status );
			$format[]       = '%s';
		}
		$wpdb->update( $table, $data, array( 'id' => absint( $row->id ) ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table write.
	}

	/** @return string[] */
	private static function candidate_storage_dirs() {
		$upload_dir = wp_upload_dir();
		$dirs       = array();
		$current    = self::storage_dir();
		if ( '' !== $current ) {
			$dirs[] = $current;
		}

		if ( is_array( $upload_dir ) && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] ) ) {
			$base_dir = wp_normalize_path( $upload_dir['basedir'] );
			if ( self::is_absolute_path( $base_dir ) ) {
				$legacy = trailingslashit( $base_dir ) . 'leadealer-attachments';
				if ( file_exists( $legacy ) ) {
					$dirs[] = $legacy;
				}
			}
		}
		$dirs = array_values( array_unique( array_filter( $dirs ) ) );

		/*
		 * The second directory is retained only so installations upgraded from
		 * 0.7.0 can still deliver or erase already-stored attachments. Harden it
		 * whenever it exists so legacy files receive the same web-server denial
		 * markers as the secret-derived 0.7.1+ directory. Do not create an empty
		 * legacy directory on new installations.
		 */
		foreach ( $dirs as $dir ) {
			if ( file_exists( $dir ) ) {
				self::harden_storage_dir( $dir );
			}
		}

		return $dirs;
	}

	/**
	 * Check whether a normalized path is absolute on Unix or Windows.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute_path( $path ) {
		return is_string( $path ) && ( 1 === preg_match( '#^/#', $path ) || 1 === preg_match( '#^[A-Za-z]:/#', $path ) );
	}

	/**
	 * Create access-denial marker files in a plugin-owned directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function harden_storage_dir( $dir ) {
		if ( ! is_string( $dir ) || '' === $dir ) {
			return;
		}
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}

		$guards = array(
			'.htaccess' => "Options -Indexes\nRequire all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
		);
		foreach ( $guards as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed plugin-owned access-control marker files; failure is non-fatal because filenames and directory are unguessable and reads remain gated by PHP.
			}
		}
	}

	/** @param string $filename Filename. @return bool */
	private static function valid_storage_filename( $filename ) {
		if ( ! is_string( $filename ) || basename( $filename ) !== $filename ) {
			return false;
		}
		// 48 hex chars are 0.7.1+ names; 32 alphanumeric chars preserve secure access to legacy 0.7.0 attachments.
		return 1 === preg_match( '/^(?:[a-f0-9]{48}|[A-Za-z0-9]{32})\.(?:jpg|jpeg|png|webp)$/', $filename );
	}

	/** @param string $extension Extension. @param string $mime MIME. @return bool */
	private static function extension_matches_mime( $extension, $mime ) {
		$extension = strtolower( (string) $extension );
		$mime      = strtolower( (string) $mime );
		if ( 'image/jpeg' === $mime ) {
			return in_array( $extension, array( 'jpg', 'jpeg' ), true );
		}
		if ( 'image/png' === $mime ) {
			return 'png' === $extension;
		}
		return 'image/webp' === $mime && 'webp' === $extension;
	}

	/** @param string $submission_id UUID candidate. @return bool */
	private function valid_submission_id( $submission_id ) {
		return is_string( $submission_id ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submission_id );
	}

	/** @param string $submission_id UUID. @return string */
	private function reservation_hash( $submission_id ) {
		return hash_hmac( 'sha256', strtolower( $submission_id ), $this->get_secret() );
	}

	/** @return string */
	private static function plugin_secret() {
		$secret = get_option( 'leadealer_secret' );
		if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
			return $secret;
		}
		return wp_salt( 'auth' );
	}

	/** @return string */
	private function hash_client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $remote_addr, $this->get_secret() );
	}

	/** @return string */
	private function get_secret() {
		return self::plugin_secret();
	}

	/** @param string $value Value. @return string */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signed data encoding, not code obfuscation.
	}

	/** @param string $value Value. @return string */
	private function base64url_decode( $value ) {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Signed data decoding, not executable code.
		return false === $decoded ? '' : $decoded;
	}
}

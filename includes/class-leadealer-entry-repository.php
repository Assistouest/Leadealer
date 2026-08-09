<?php
/**
 * Lead Vault persistence.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles durable form entries and their delivery ledger.
 */
final class Leadealer_Entry_Repository {

	/**
	 * Upload repository.
	 *
	 * @var Leadealer_Upload_Repository
	 */
	private $uploads;

	/**
	 * Constructor.
	 *
	 * @param Leadealer_Upload_Repository $uploads Upload repository.
	 */
	public function __construct( $uploads ) {
		$this->uploads = $uploads;
	}

	/**
	 * Create a Lead Vault entry idempotently.
	 *
	 * The notification envelope is frozen at reception time so retries do not
	 * depend on a later form edit or on the form still existing.
	 *
	 * @param int    $form_id         Form ID.
	 * @param string $uuid            Submission UUID.
	 * @param string $idempotency_key Browser-held idempotency secret.
	 * @param array  $payload         Sanitized submitted values.
	 * @param array  $notification    Frozen notification envelope.
	 * @param bool   $retain          Whether submitted values should remain after handoff.
	 * @param array  $attachments     Frozen expected attachment manifest.
	 * @return array|WP_Error Result containing ID, creation flag, and mail status.
	 */
	public function create( $form_id, $uuid, $idempotency_key, $payload, $notification, $retain = true, $attachments = array() ) {
		global $wpdb;

		$table            = Leadealer_Database::entries_table();
		$now              = current_time( 'mysql', true );
		$idempotency_hash = hash( 'sha256', (string) $idempotency_key );
		$json             = wp_json_encode( $payload );
		$headers_json     = wp_json_encode( isset( $notification['headers'] ) ? $notification['headers'] : array() );
		$attachments_json = wp_json_encode( $this->normalize_attachment_manifest( $attachments ) );

		if ( false === $json || false === $headers_json || false === $attachments_json ) {
			return new WP_Error( 'entry_encoding_failed', __( 'The message could not be stored.', 'leadealer' ) );
		}

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent insert into a dedicated Lead Vault table; nothing to cache for a write.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (form_id, submission_uuid, idempotency_hash, status, payload, retain_payload, mail_to, mail_subject, mail_body, mail_headers, attachment_manifest, mail_status, mail_attempts, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				absint( $form_id ),
				$uuid,
				$idempotency_hash,
				'received',
				$json,
				$retain ? 1 : 0,
				isset( $notification['to'] ) ? sanitize_email( $notification['to'] ) : '',
				isset( $notification['subject'] ) ? (string) $notification['subject'] : '',
				isset( $notification['body'] ) ? (string) $notification['body'] : '',
				$headers_json,
				$attachments_json,
				'pending',
				0,
				$now,
				$now
			)
		);

		if ( 1 === (int) $inserted ) {
			$entry_id = (int) $wpdb->insert_id;
			$this->add_event(
				$entry_id,
				'lead_secured',
				'success',
				__( 'Proof of Work and server validation passed; lead stored safely before mail delivery.', 'leadealer' )
			);
			$this->add_event(
				$entry_id,
				'mail_queued',
				'info',
				__( 'Notification snapshot created and queued for delivery.', 'leadealer' )
			);

			return array(
				'id'          => $entry_id,
				'created'     => true,
				'mail_status' => 'pending',
			);
		}

		$existing = $this->find_by_submission( $form_id, $uuid );
		if ( $existing ) {
			if ( ! $this->matches_idempotency_key( $existing, $idempotency_key ) ) {
				return new WP_Error( 'entry_idempotency_conflict', __( 'This submission cannot be resumed. Please reload the form and try again.', 'leadealer' ) );
			}

			return array(
				'id'          => (int) $existing->id,
				'created'     => false,
				'mail_status' => sanitize_key( $existing->mail_status ),
			);
		}

		return new WP_Error( 'entry_insert_failed', __( 'The message could not be stored.', 'leadealer' ) );
	}

	/**
	 * Normalize the frozen attachment manifest stored with a lead.
	 *
	 * @param mixed $attachments Raw manifest.
	 * @return array<int,array{field_id:string,upload_id:int}>
	 */
	private function normalize_attachment_manifest( $attachments ) {
		$clean = array();

		if ( ! is_array( $attachments ) ) {
			return $clean;
		}

		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$field_id = isset( $attachment['field_id'] ) ? sanitize_key( $attachment['field_id'] ) : '';
			$upload_id = isset( $attachment['upload_id'] ) ? absint( $attachment['upload_id'] ) : 0;
			if ( '' === $field_id || $upload_id <= 0 ) {
				continue;
			}

			$clean[ $upload_id ] = array(
				'field_id'  => $field_id,
				'upload_id' => $upload_id,
			);
		}

		return array_values( $clean );
	}

	/**
	 * Find an existing idempotent submission.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $uuid    Submission UUID.
	 * @return object|null
	 */
	public function find_by_submission( $form_id, $uuid ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate idempotency lookup on the dedicated Lead Vault table.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE form_id = %d AND submission_uuid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				absint( $form_id ),
				$uuid
			)
		);
	}

	/**
	 * Verify that a duplicate request knows the original idempotency secret.
	 *
	 * @param object $entry           Lead Vault entry.
	 * @param string $idempotency_key Browser-held idempotency secret.
	 * @return bool
	 */
	public function matches_idempotency_key( $entry, $idempotency_key ) {
		if ( ! is_object( $entry ) || empty( $entry->idempotency_hash ) || ! is_string( $idempotency_key ) ) {
			return false;
		}

		$expected = (string) $entry->idempotency_hash;
		$actual   = hash( 'sha256', $idempotency_key );

		return 64 === strlen( $expected ) && hash_equals( $expected, $actual );
	}

	/**
	 * Atomically claim an entry for one delivery attempt.
	 *
	 * @param int $entry_id     Entry ID.
	 * @param int $max_attempts Maximum attempts.
	 * @param int $stale_after  Seconds before a processing lock may be reclaimed.
	 * @return bool|WP_Error True when claimed, false when another process owns or completed it.
	 */
	public function claim_for_mail( $entry_id, $max_attempts, $stale_after ) {
		global $wpdb;

		$table        = Leadealer_Database::entries_table();
		$now          = current_time( 'mysql', true );
		$stale_before = gmdate( 'Y-m-d H:i:s', time() - absint( $stale_after ) );
		$result       = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim requires a dedicated table write; nothing to cache.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_status = 'processing', mail_attempts = mail_attempts + 1, mail_error = '', next_mail_attempt_at = NULL, mail_last_attempt_at = %s, mail_locked_at = %s, updated_at = %s WHERE id = %d AND mail_attempts < %d AND (mail_status = 'pending' OR (mail_status = 'retrying' AND (next_mail_attempt_at IS NULL OR next_mail_attempt_at <= %s)) OR (mail_status = 'processing' AND mail_locked_at < %s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$now,
				$now,
				$now,
				absint( $entry_id ),
				absint( $max_attempts ),
				$now,
				$stale_before
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'mail_claim_failed', __( 'The stored lead could not be prepared for delivery.', 'leadealer' ) );
		}

		return 1 === (int) $result;
	}

	/**
	 * Mark an entry as waiting for another delivery attempt.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $error    Error message.
	 * @param string $next_at  Next UTC attempt datetime.
	 * @return void
	 */
	public function mark_mail_retrying( $entry_id, $error, $next_at ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated Lead Vault table write.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_status = 'retrying', mail_error = %s, next_mail_attempt_at = %s, mail_locked_at = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$error,
				$next_at,
				current_time( 'mysql', true ),
				absint( $entry_id )
			)
		);
	}

	/**
	 * Mark an entry as handed off to the WordPress mail transport.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public function mark_mail_handed_off( $entry_id ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();
		$now   = current_time( 'mysql', true );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated Lead Vault table write.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_status = 'handed_off', mail_error = '', next_mail_attempt_at = NULL, mail_handed_off_at = %s, mail_locked_at = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$now,
				$now,
				absint( $entry_id )
			)
		);
	}

	/**
	 * Move an entry to the dead-letter state.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $error    Last delivery error.
	 * @return void
	 */
	public function mark_mail_dead( $entry_id, $error ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated Lead Vault table write.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_status = 'dead', mail_error = %s, next_mail_attempt_at = NULL, mail_locked_at = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$error,
				current_time( 'mysql', true ),
				absint( $entry_id )
			)
		);
	}

	/**
	 * Reset a failed delivery for a new manual delivery cycle.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public function reset_mail_delivery( $entry_id ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated Lead Vault table write.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_status = 'pending', mail_attempts = 0, mail_error = '', next_mail_attempt_at = NULL, mail_handed_off_at = NULL, mail_locked_at = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				current_time( 'mysql', true ),
				absint( $entry_id )
			)
		);
	}

	/**
	 * Replace the frozen notification snapshot.
	 *
	 * @param int   $entry_id     Entry ID.
	 * @param array $notification Notification envelope.
	 * @return bool
	 */
	public function update_mail_snapshot( $entry_id, $notification ) {
		global $wpdb;

		$headers_json = wp_json_encode( isset( $notification['headers'] ) ? $notification['headers'] : array() );
		if ( false === $headers_json ) {
			return false;
		}

		$table  = Leadealer_Database::entries_table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated Lead Vault table write.
			$wpdb->prepare(
				"UPDATE {$table} SET mail_to = %s, mail_subject = %s, mail_body = %s, mail_headers = %s, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				sanitize_email( isset( $notification['to'] ) ? $notification['to'] : '' ),
				isset( $notification['subject'] ) ? (string) $notification['subject'] : '',
				isset( $notification['body'] ) ? (string) $notification['body'] : '',
				$headers_json,
				current_time( 'mysql', true ),
				absint( $entry_id )
			)
		);

		return false !== $result;
	}

	/**
	 * Add one Lead Vault timeline event.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $type     Event type.
	 * @param string $status   Event status.
	 * @param string $message  Human-readable message.
	 * @param array  $context  Optional non-sensitive context.
	 * @return void
	 */
	public function add_event( $entry_id, $type, $status, $message, $context = array() ) {
		global $wpdb;

		$table        = Leadealer_Database::events_table();
		$context_json = empty( $context ) ? '' : wp_json_encode( $context );
		if ( false === $context_json ) {
			$context_json = '';
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only delivery ledger in a dedicated plugin table.
			$table,
			array(
				'entry_id'     => absint( $entry_id ),
				'event_type'   => sanitize_key( $type ),
				'event_status' => sanitize_key( $status ),
				'message'      => sanitize_textarea_field( $message ),
				'context'      => $context_json,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get the timeline for one entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $limit    Maximum events.
	 * @return array<int,object>
	 */
	public function get_events( $entry_id, $limit = 100 ) {
		global $wpdb;

		$table = Leadealer_Database::events_table();
		$limit = min( 200, max( 1, absint( $limit ) ) );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only Lead Vault timeline lookup.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE entry_id = %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				absint( $entry_id ),
				$limit
			)
		);
	}

	/**
	 * Get entries that are due for delivery or stale recovery.
	 *
	 * @param int $limit        Maximum rows.
	 * @param int $max_attempts Maximum automatic attempts.
	 * @param int $stale_after  Processing lock lifetime in seconds.
	 * @return array<int,object>
	 */
	public function get_due_for_delivery( $limit, $max_attempts, $stale_after ) {
		global $wpdb;

		$table        = Leadealer_Database::entries_table();
		$limit        = min( 50, max( 1, absint( $limit ) ) );
		$now          = current_time( 'mysql', true );
		$stale_before = gmdate( 'Y-m-d H:i:s', time() - absint( $stale_after ) );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery worker scans the dedicated delivery ledger.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE mail_attempts < %d AND ((mail_status IN ('pending', 'retrying') AND (next_mail_attempt_at IS NULL OR next_mail_attempt_at <= %s)) OR (mail_status = 'processing' AND mail_locked_at < %s)) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				absint( $max_attempts ),
				$now,
				$stale_before,
				$limit
			)
		);
	}

	/**
	 * Count leads whose mail notification requires administrator attention.
	 *
	 * @return int
	 */
	public function get_dead_count() {
		global $wpdb;

		$table = Leadealer_Database::entries_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small admin reliability counter on a dedicated table.
			"SELECT COUNT(*) FROM {$table} WHERE mail_status = 'dead'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally and no user input enters the query.
		);
	}



	/**
	 * Get the newest event that requires administrator delivery attention.
	 *
	 * Event IDs keep dismissal correct when the same lead is manually retried and
	 * later enters dead-letter again.
	 *
	 * @return int
	 */
	public function get_latest_attention_event_id() {
		global $wpdb;

		$table = Leadealer_Database::events_table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Small admin reliability lookup on a dedicated table.
			"SELECT MAX(id) FROM {$table} WHERE event_type IN ('mail_dead_letter', 'mail_configuration_error')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and event types are internal constants; no user input enters the query.
		);
	}

	/**
	 * Get Lead Vault delivery counters.
	 *
	 * @return array<string,int>
	 */
	public function get_delivery_counts() {
		global $wpdb;

		$table = Leadealer_Database::entries_table();
		$rows  = $wpdb->get_results( "SELECT mail_status, COUNT(*) AS total FROM {$table} GROUP BY mail_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate dashboard read on a dedicated table without user input.
		$data  = array(
			'total'      => 0,
			'handed_off' => 0,
			'recovering' => 0,
			'dead'       => 0,
			'recovered'  => 0,
		);

		foreach ( $rows as $row ) {
			$count          = absint( $row->total );
			$data['total'] += $count;
			if ( 'handed_off' === $row->mail_status ) {
				$data['handed_off'] = $count;
			} elseif ( 'dead' === $row->mail_status ) {
				$data['dead'] = $count;
			} elseif ( in_array( $row->mail_status, array( 'pending', 'processing', 'retrying' ), true ) ) {
				$data['recovering'] += $count;
			}
		}

		$data['recovered'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mail_status = 'handed_off' AND mail_attempts > 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate dashboard read on a dedicated table without user input.
		return $data;
	}

	/**
	 * Build the shared WHERE clause for get_latest()/count_latest().
	 *
	 * @param string $filter  Delivery filter.
	 * @param int    $form_id Restrict to one form, or 0 for every form.
	 * @return array{sql:string,args:array<int,int>}
	 */
	private function latest_where_clause( $filter, $form_id ) {
		$conditions = array();
		$args       = array();

		switch ( sanitize_key( $filter ) ) {
			case 'issues':
				$conditions[] = "mail_status IN ('retrying', 'dead')";
				break;
			case 'dead':
				$conditions[] = "mail_status = 'dead'";
				break;
			case 'recovering':
				$conditions[] = "mail_status IN ('pending', 'processing', 'retrying')";
				break;
			case 'handed_off':
				$conditions[] = "mail_status = 'handed_off'";
				break;
		}

		if ( absint( $form_id ) > 0 ) {
			$conditions[] = 'form_id = %d';
			$args[]       = absint( $form_id );
		}

		$sql = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Get latest entries, optionally filtered by delivery state and/or form,
	 * one page at a time — every entry remains reachable regardless of
	 * volume by paging through with $offset.
	 *
	 * @param int    $limit   Maximum rows.
	 * @param string $filter  Delivery filter.
	 * @param int    $offset  Row offset for pagination.
	 * @param int    $form_id Restrict to one form, or 0 for every form.
	 * @return array<int,object>
	 */
	public function get_latest( $limit = 50, $filter = '', $offset = 0, $form_id = 0 ) {
		global $wpdb;

		$table  = Leadealer_Database::entries_table();
		$limit  = min( 100, max( 1, absint( $limit ) ) );
		$clause = $this->latest_where_clause( $filter, $form_id );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin Lead Vault listing from a dedicated table.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholder count is dynamic (0 or 1 form_id placeholder from latest_where_clause() plus the 2 static ones below) and matches array_merge()'s argument count at runtime.
				"SELECT * FROM {$table}{$clause['sql']} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and WHERE clause are internal allowlisted SQL fragments.
				array_merge( $clause['args'], array( $limit, absint( $offset ) ) )
			)
		);
	}

	/**
	 * Count entries matching the same filter/form scope as get_latest(), for
	 * pagination controls.
	 *
	 * @param string $filter  Delivery filter.
	 * @param int    $form_id Restrict to one form, or 0 for every form.
	 * @return int
	 */
	public function count_latest( $filter = '', $form_id = 0 ) {
		global $wpdb;

		$table  = Leadealer_Database::entries_table();
		$clause = $this->latest_where_clause( $filter, $form_id );

		if ( empty( $clause['args'] ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$clause['sql']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name and WHERE clause are internal allowlisted SQL fragments; no user input enters unprepared.
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin Lead Vault pagination count on a dedicated table.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}{$clause['sql']}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and WHERE clause are internal allowlisted SQL fragments; the single %d placeholder (form_id) comes from latest_where_clause() and matches $clause['args'] at runtime, this branch is only reached when args is non-empty.
				$clause['args']
			)
		);
	}

	/**
	 * Get one entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	public function get( $entry_id ) {
		global $wpdb;

		$table = Leadealer_Database::entries_table();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table lookup.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				absint( $entry_id )
			)
		);
	}

	/**
	 * Find entries containing a given email address.
	 *
	 * @param string $email  Email address.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @return array<int,object>
	 */
	public function find_by_email( $email, $offset = 0, $limit = 50 ) {
		global $wpdb;

		$table  = Leadealer_Database::entries_table();
		$needle = '%"' . $wpdb->esc_like( strtolower( $email ) ) . '"%';

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy operation on dedicated table.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE LOWER(payload) LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$needle,
				min( 100, absint( $limit ) ),
				absint( $offset )
			)
		);
	}

	/**
	 * Delete one entry and its timeline.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool
	 */
	public function delete( $entry_id ) {
		global $wpdb;

		$entry_id     = absint( $entry_id );
		$events_table = Leadealer_Database::events_table();
		$entry_table  = Leadealer_Database::entries_table();

		$this->uploads->soft_delete_for_entry( $entry_id );
		$wpdb->delete( $events_table, array( 'entry_id' => $entry_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin timeline table; nothing to cache for a write.
		return false !== $wpdb->delete( $entry_table, array( 'id' => $entry_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated plugin table; nothing to cache for a write.
	}

	/**
	 * Redact submitted and frozen notification content after a successful handoff.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public function redact_sensitive_data( $entry_id ) {
		global $wpdb;

		$table  = Leadealer_Database::entries_table();
		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy redaction in a dedicated plugin table.
			$wpdb->prepare(
				"UPDATE {$table} SET payload = %s, mail_to = '', mail_subject = '', mail_body = '', mail_headers = '', attachment_manifest = '', updated_at = %s WHERE id = %d AND mail_status = 'handed_off' AND retain_payload = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				'{}',
				current_time( 'mysql', true ),
				absint( $entry_id )
			)
		);

		// Do not delete claimed attachment files here. wp_mail() can be
		// short-circuited by an asynchronous SMTP/queue transport that accepts
		// the local path now but reads it later. Scheduled cleanup removes these
		// files after Leadealer_Upload_Repository::MAIL_HANDOFF_FILE_GRACE.
		unset( $result );
	}

	/**
	 * Remove entries older than configured retention with their timelines.
	 *
	 * @return void
	 */
	public function cleanup_expired_entries() {
		global $wpdb;

		$days = absint( get_option( 'leadealer_retention_days', 90 ) );
		if ( 0 === $days ) {
			return;
		}

		$entries_table = Leadealer_Database::entries_table();
		$events_table  = Leadealer_Database::events_table();
		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		/*
		 * Never age out an unresolved lead. Retention applies only after WordPress
		 * accepted the notification for transport; pending, retrying, processing,
		 * and dead-letter leads remain recoverable.
		 */
		$expiring_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup of a dedicated plugin table.
			$wpdb->prepare(
				"SELECT id FROM {$entries_table} WHERE created_at < %s AND mail_status = 'handed_off'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$cutoff
			)
		);
		if ( ! empty( $expiring_ids ) ) {
			$this->uploads->soft_delete_for_entries( array_map( 'absint', $expiring_ids ) );
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup of dedicated plugin tables.
			$wpdb->prepare(
				"DELETE e FROM {$events_table} e INNER JOIN {$entries_table} l ON e.entry_id = l.id WHERE l.created_at < %s AND l.mail_status = 'handed_off'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are generated internally.
				$cutoff
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup of dedicated plugin table.
			$wpdb->prepare(
				"DELETE FROM {$entries_table} WHERE created_at < %s AND mail_status = 'handed_off'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$cutoff
			)
		);
	}
}

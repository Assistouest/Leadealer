<?php
/**
 * Native WordPress mail integration and resilient delivery queue.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends frozen Lead Vault notifications exclusively through wp_mail().
 */
final class Leadealer_Mailer {

	/**
	 * Maximum automatic delivery attempts per cycle.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 6;

	/**
	 * Seconds before a processing lock may be recovered.
	 *
	 * @var int
	 */
	public const PROCESSING_STALE_AFTER = 1800;

	/**
	 * Seconds before the crash-recovery watchdog runs.
	 *
	 * @var int
	 */
	public const WATCHDOG_DELAY = 2100;

	/**
	 * Entry repository.
	 *
	 * @var Leadealer_Entry_Repository
	 */
	private $entries;

	/**
	 * Form repository.
	 *
	 * @var Leadealer_Form_Repository
	 */
	private $forms;

	/**
	 * HTML email template renderer.
	 *
	 * @var Leadealer_Mail_Template
	 */
	private $template;

	/**
	 * Last mail error captured from wp_mail_failed.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Upload repository.
	 *
	 * @var Leadealer_Upload_Repository
	 */
	private $uploads;

	/**
	 * Attachments expected for the wp_mail() call currently in progress.
	 *
	 * @var array<string,string>
	 */
	private $active_attachments = array();

	/**
	 * Attachment preparation error captured at the final PHPMailer stage.
	 *
	 * @var string
	 */
	private $attachment_prepare_error = '';

	/**
	 * Whether the final PHPMailer inspection ran for the active mail.
	 *
	 * @var bool
	 */
	private $attachment_hook_seen = false;

	/**
	 * Constructor.
	 *
	 * @param Leadealer_Entry_Repository  $entries  Entry repository.
	 * @param Leadealer_Form_Repository   $forms    Form repository.
	 * @param Leadealer_Mail_Template     $template HTML email template renderer.
	 * @param Leadealer_Upload_Repository $uploads  Upload repository.
	 */
	public function __construct( $entries, $forms, $template, $uploads ) {
		$this->entries  = $entries;
		$this->forms    = $forms;
		$this->template = $template;
		$this->uploads  = $uploads;
	}

	/**
	 * Register queue, recovery, and admin retry hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'leadealer_retry_mail', array( $this, 'retry_entry' ) );
		add_action( 'leadealer_mail_watchdog', array( $this, 'retry_entry' ) );
		add_action( 'leadealer_delivery_recovery', array( $this, 'recover_due_entries' ) );
		add_action( 'admin_post_leadealer_retry_mail', array( $this, 'handle_manual_retry' ) );
	}

	/**
	 * Build a frozen notification envelope from the current form and values.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Sanitized values.
	 * @return array<string,mixed>
	 */
	public function prepare_notification( $form, $values ) {
		$settings  = $form['settings'];
		$recipient = sanitize_email( $settings['recipient'] );
		$subject   = $this->replace_tokens( $settings['subject'], $form, $values );
		$subject   = trim( str_replace( array( "\r", "\n" ), '', wp_strip_all_tags( $subject ) ) );
		$subject   = wp_html_excerpt( $subject, 500, '' );

		if ( '' === $subject ) {
			// A blank Subject header is a strong spam signal on many mail
			// transports: wp_mail() still returns true, so delivery silently
			// fails downstream with nothing surfaced to the admin. Never hand
			// wp_mail() an empty subject, whatever produced the blank value.
			$subject = trim( wp_strip_all_tags( $this->replace_tokens( __( 'New message from {{form:title}}', 'leadealer' ), $form, $values ) ) );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = $this->find_reply_to( $form, $values );

		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		return array(
			'to'      => $recipient,
			'subject' => $subject,
			'body'    => $this->template->build( $form, $values ),
			'headers' => $headers,
			'error'   => is_email( $recipient ) ? '' : __( 'Invalid recipient email address.', 'leadealer' ),
		);
	}

	/**
	 * Send one frozen Lead Vault notification.
	 *
	 * The entry is atomically claimed before wp_mail() runs. A watchdog is
	 * scheduled before entering the transport so a fatal error or timeout after
	 * the claim cannot leave the lead permanently stuck in processing.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool True when handed off or already completed.
	 */
	public function send_entry( $entry_id ) {
		$entry_id = absint( $entry_id );
		$entry    = $this->entries->get( $entry_id );
		if ( ! $entry ) {
			return false;
		}

		if ( 'handed_off' === $entry->mail_status ) {
			return true;
		}

		if ( (int) $entry->mail_attempts >= self::MAX_ATTEMPTS ) {
			if ( 'dead' !== $entry->mail_status ) {
				$this->move_to_dead_letter( $entry_id, __( 'Maximum automatic delivery attempts reached.', 'leadealer' ) );
			}
			return false;
		}

		$claimed = $this->entries->claim_for_mail( $entry_id, self::MAX_ATTEMPTS, self::PROCESSING_STALE_AFTER );
		if ( is_wp_error( $claimed ) ) {
			$this->entries->add_event( $entry_id, 'mail_claim_failed', 'error', $claimed->get_error_message() );
			return false;
		}
		if ( ! $claimed ) {
			return 'handed_off' === $entry->mail_status;
		}

		$entry = $this->entries->get( $entry_id );
		if ( ! $entry ) {
			return false;
		}

		$this->last_error = '';
		$this->entries->add_event(
			$entry_id,
			'mail_attempt_started',
			'info',
			sprintf(
				/* translators: %d: Mail attempt number. */
				__( 'Mail delivery attempt %d started.', 'leadealer' ),
				(int) $entry->mail_attempts
			)
		);
		$this->schedule_watchdog( $entry_id );

		$recipient = sanitize_email( $entry->mail_to );
		if ( ! is_email( $recipient ) ) {
			$this->clear_watchdog( $entry_id );
			$this->move_to_dead_letter( $entry_id, __( 'The stored recipient email address is invalid. Update the form notification and retry manually.', 'leadealer' ) );
			return false;
		}

		$headers   = json_decode( (string) $entry->mail_headers, true );
		$headers   = $this->sanitize_stored_headers( is_array( $headers ) ? $headers : array() );
		$headers[] = 'X-Leadealer-Lead-ID: ' . absint( $entry->id );
		$headers[] = 'X-Leadealer-Submission-ID: ' . sanitize_text_field( $entry->submission_uuid );

		$this->uploads->claim_reserved_for_entry( $entry_id, (int) $entry->form_id, (string) $entry->submission_uuid );
		$attachments = $this->resolve_attachments( $entry );
		if ( is_wp_error( $attachments ) ) {
			$this->clear_watchdog( $entry_id );
			$this->handle_failed_attempt( $entry_id, $attachments->get_error_message() );
			return false;
		}

		$subject = wp_html_excerpt( trim( str_replace( array( "\r", "\n" ), '', wp_strip_all_tags( (string) $entry->mail_subject ) ) ), 500, '' );
		if ( '' === $subject ) {
			$subject = __( 'New form submission', 'leadealer' );
		}

		if ( ! empty( $attachments ) ) {
			$this->entries->add_event(
				$entry_id,
				'attachments_prepared',
				'info',
				sprintf(
					/* translators: %d: Number of verified email attachments. */
					_n( '%d verified photo prepared for email delivery.', '%d verified photos prepared for email delivery.', count( $attachments ), 'leadealer' ),
					count( $attachments )
				)
			);
		}

		$this->active_attachments       = $attachments;
		$this->attachment_prepare_error = '';
		$this->attachment_hook_seen     = false;

		add_action( 'wp_mail_failed', array( $this, 'capture_failure' ) );
		if ( ! empty( $attachments ) ) {
			add_filter( 'wp_mail', array( $this, 'enforce_wp_mail_arguments' ), PHP_INT_MAX );
			add_action( 'phpmailer_init', array( $this, 'enforce_active_attachments' ), PHP_INT_MAX );
		}

		$sent = false;
		try {
			$sent = wp_mail( $recipient, $subject, (string) $entry->mail_body, $headers, $attachments );
		} catch ( Throwable $exception ) {
			$this->last_error = $this->sanitize_mail_error( $exception->getMessage() );
		} finally {
			if ( ! empty( $attachments ) ) {
				remove_filter( 'wp_mail', array( $this, 'enforce_wp_mail_arguments' ), PHP_INT_MAX );
				remove_action( 'phpmailer_init', array( $this, 'enforce_active_attachments' ), PHP_INT_MAX );
			}
			remove_action( 'wp_mail_failed', array( $this, 'capture_failure' ) );
		}

		if ( '' !== $this->attachment_prepare_error ) {
			$sent             = false;
			$this->last_error = $this->attachment_prepare_error;
		}

		if ( $sent && ! empty( $attachments ) && ! $this->attachment_hook_seen ) {
			$this->entries->add_event(
				$entry_id,
				'attachment_transport_uninspected',
				'warning',
				__( 'The mail transport accepted the notification before WordPress initialized PHPMailer. Leadealer supplied the verified attachment paths, but the final transport attachment list could not be inspected.', 'leadealer' )
			);
		}

		$this->active_attachments = array();
		$this->clear_watchdog( $entry_id );

		if ( $sent ) {
			$this->clear_retry( $entry_id );
			$this->entries->mark_mail_handed_off( $entry_id );
			$this->entries->add_event(
				$entry_id,
				'mail_handed_off',
				'success',
				__( 'Notification handed off successfully to the WordPress mail transport.', 'leadealer' )
			);
			if ( empty( $entry->retain_payload ) ) {
				$this->entries->redact_sensitive_data( $entry_id );
				$this->entries->add_event(
					$entry_id,
					'lead_redacted',
					'info',
					__( 'Submitted field values were removed after successful mail handoff according to the form storage setting.', 'leadealer' )
				);
			}
			return true;
		}

		$error = $this->last_error ? $this->last_error : __( 'wp_mail() returned false.', 'leadealer' );
		$this->handle_failed_attempt( $entry_id, $error );
		return false;
	}

	/**
	 * Retry an entry from WP-Cron or the crash watchdog.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public function retry_entry( $entry_id ) {
		$this->send_entry( absint( $entry_id ) );
	}

	/**
	 * Scan for due, lost, or stale deliveries and recover them.
	 *
	 * This recurring worker is intentionally redundant with single retry events.
	 * If a single event is lost, the ledger remains authoritative and this scan
	 * will still recover the lead on a later cron run.
	 *
	 * @return void
	 */
	public function recover_due_entries() {
		$entries = $this->entries->get_due_for_delivery( 10, self::MAX_ATTEMPTS, self::PROCESSING_STALE_AFTER );
		foreach ( $entries as $entry ) {
			$this->send_entry( (int) $entry->id );
		}
	}

	/**
	 * Handle a nonce-protected manual retry from Lead Vault.
	 *
	 * A manual retry begins a new bounded delivery cycle. When possible, the
	 * notification snapshot is refreshed from the current form settings first.
	 *
	 * @return void
	 */
	public function handle_manual_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to retry this message.', 'leadealer' ) );
		}

		check_admin_referer( 'leadealer_retry_mail' );
		$entry_id = isset( $_POST['entry'] ) ? absint( wp_unslash( $_POST['entry'] ) ) : 0;

		if ( $entry_id ) {
			$this->clear_retry( $entry_id );
			$this->clear_watchdog( $entry_id );
			$this->refresh_snapshot_from_current_form( $entry_id );
			$this->entries->reset_mail_delivery( $entry_id );
			$this->entries->add_event( $entry_id, 'manual_retry', 'info', __( 'A new delivery cycle was started manually by an administrator.', 'leadealer' ) );
			$this->send_entry( $entry_id );
		}

		$redirect = add_query_arg(
			array(
				'page'  => 'leadealer-vault',
				'entry' => $entry_id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Capture a wp_mail() failure.
	 *
	 * @param WP_Error $error Mail error.
	 * @return void
	 */
	public function capture_failure( $error ) {
		if ( is_wp_error( $error ) ) {
			$this->last_error = $this->sanitize_mail_error( $error->get_error_message() );
		}
	}

	/**
	 * Resolve claimed photo uploads for an entry into wp_mail() attachment paths.
	 *
	 * Resolved fresh on every send/retry rather than frozen at submission time,
	 * so a file attached hours before a delayed retry is still found correctly.
	 *
	 * @param object $entry Lead Vault entry.
	 * @return array<string,string>|WP_Error Friendly filename => absolute path, or error.
	 */
	private function resolve_attachments( $entry ) {
		if ( ! is_object( $entry ) || empty( $entry->id ) ) {
			return new WP_Error( 'attachment_entry_missing', __( 'The stored lead could not be prepared for delivery.', 'leadealer' ) );
		}

		$entry_id        = absint( $entry->id );
		$rows            = $this->uploads->get_by_entry( $entry_id );
		$expected_ids    = $this->expected_attachment_ids( $entry );
		$frozen_manifest = isset( $entry->attachment_manifest ) ? json_decode( (string) $entry->attachment_manifest, true ) : null;
		$has_manifest    = is_array( $frozen_manifest );
		$rows_by_id      = array();

		foreach ( $rows as $row ) {
			$rows_by_id[ absint( $row->id ) ] = $row;
		}

		foreach ( $expected_ids as $upload_id ) {
			if ( ! isset( $rows_by_id[ $upload_id ] ) ) {
				$this->entries->add_event(
					$entry_id,
					'attachment_missing_at_send',
					'error',
					__( 'A photo selected by the visitor is not linked to this lead, so the notification was not sent without it.', 'leadealer' )
				);

				return new WP_Error(
					'attachment_missing_at_send',
					__( 'A selected photo is missing from the stored lead. The email was not sent without its attachment.', 'leadealer' )
				);
			}
		}

		$rows_to_send = $rows;
		if ( $has_manifest ) {
			$rows_to_send = array();
			foreach ( $expected_ids as $upload_id ) {
				if ( isset( $rows_by_id[ $upload_id ] ) ) {
					$rows_to_send[] = $rows_by_id[ $upload_id ];
				}
			}
		}

		$attachments = array();
		foreach ( $rows_to_send as $index => $row ) {
			$verified = $this->uploads->verify_stored_file( $row );
			if ( false === $verified ) {
				$this->entries->add_event(
					$entry_id,
					'attachment_missing_at_send',
					'error',
					__( 'A stored photo failed its integrity checks, so the notification was not sent without it.', 'leadealer' )
				);

				return new WP_Error(
					'attachment_integrity_failed',
					__( 'A stored photo could not be verified. The email was not sent without its attachment.', 'leadealer' )
				);
			}

			$friendly_name                 = sprintf( 'photo-%d.%s', $index + 1, $verified['extension'] );
			$attachments[ $friendly_name ] = $verified['path'];
		}

		return $attachments;
	}

	/**
	 * Recover upload IDs that the frozen notification says must accompany mail.
	 *
	 * New leads use the immutable attachment manifest stored with the entry. The
	 * current form schema is consulted only for backward compatibility with leads
	 * created before database schema version 6.
	 *
	 * @param object $entry Lead Vault entry.
	 * @return int[]
	 */
	private function expected_attachment_ids( $entry ) {
		$ids      = array();
		$manifest = isset( $entry->attachment_manifest ) ? json_decode( (string) $entry->attachment_manifest, true ) : null;

		if ( is_array( $manifest ) ) {
			foreach ( $manifest as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}
				$upload_id = isset( $attachment['upload_id'] ) ? absint( $attachment['upload_id'] ) : 0;
				if ( $upload_id > 0 ) {
					$ids[] = $upload_id;
				}
			}

			return array_values( array_unique( $ids ) );
		}

		/*
		 * Backward compatibility for leads created before schema version 6. Those
		 * rows have no frozen attachment manifest, so use the current form schema
		 * only as a best-effort recovery source.
		 */
		$payload = json_decode( (string) $entry->payload, true );
		$form    = $this->forms->get( (int) $entry->form_id );

		if ( ! is_array( $payload ) || ! $form || empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $ids;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) || 'file' !== ( isset( $field['type'] ) ? $field['type'] : '' ) ) {
				continue;
			}
			if ( ! empty( $payload[ $field['id'] ] ) ) {
				$upload_id = absint( $payload[ $field['id'] ] );
				if ( $upload_id > 0 ) {
					$ids[] = $upload_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Reassert verified Leadealer attachments after every normal wp_mail filter.
	 *
	 * WordPress passes the filtered argument array to pre_wp_mail before PHPMailer
	 * is initialized. Running at the last practical wp_mail priority means queued
	 * or asynchronous transports that short-circuit core still receive Leadealer's
	 * verified paths even if an earlier integration rebuilt the attachment list.
	 *
	 * @param mixed $atts Filtered wp_mail arguments.
	 * @return mixed Filtered arguments with verified Leadealer attachments restored.
	 */
	public function enforce_wp_mail_arguments( $atts ) {
		if ( empty( $this->active_attachments ) || ! is_array( $atts ) ) {
			return $atts;
		}

		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();
		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		} elseif ( ! is_array( $attachments ) ) {
			$attachments = array();
		}

		$present = array();
		foreach ( $attachments as $attachment ) {
			if ( ! is_string( $attachment ) || '' === trim( $attachment ) ) {
				continue;
			}
			$real = realpath( $attachment );
			if ( false !== $real ) {
				$present[ wp_normalize_path( $real ) ] = true;
			}
		}

		foreach ( $this->active_attachments as $friendly_name => $path ) {
			$real = realpath( $path );
			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				$this->attachment_prepare_error = __( 'A verified photo became unavailable immediately before mail delivery.', 'leadealer' );
				break;
			}

			$normalized = wp_normalize_path( $real );
			if ( ! isset( $present[ $normalized ] ) ) {
				$attachments[ sanitize_file_name( $friendly_name ) ] = $real;
				$present[ $normalized ] = true;
			}
		}

		$atts['attachments'] = $attachments;
		return $atts;
	}

	/**
	 * Enforce Leadealer attachments at the final PHPMailer initialization stage.
	 *
	 * Some SMTP/delivery plugins filter wp_mail() arguments. Leadealer therefore
	 * re-checks the PHPMailer attachment collection immediately before sending and
	 * restores any verified path that was dropped. If PHPMailer cannot attach an
	 * expected file, recipients are cleared deliberately so WordPress fails closed
	 * rather than sending a misleading attachment-less notification.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Active PHPMailer instance.
	 * @return void
	 */
	public function enforce_active_attachments( $phpmailer ) {
		$this->attachment_hook_seen = true;

		if ( empty( $this->active_attachments ) || ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'addAttachment' ) ) {
			return;
		}

		$present = array();
		if ( method_exists( $phpmailer, 'getAttachments' ) ) {
			foreach ( (array) $phpmailer->getAttachments() as $attachment ) {
				if ( ! is_array( $attachment ) || empty( $attachment[0] ) || ! is_string( $attachment[0] ) ) {
					continue;
				}
				$real = realpath( $attachment[0] );
				if ( false !== $real ) {
					$present[ wp_normalize_path( $real ) ] = true;
				}
			}
		}

		foreach ( $this->active_attachments as $friendly_name => $path ) {
			$real = realpath( $path );
			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				$this->attachment_prepare_error = __( 'A verified photo became unavailable immediately before mail delivery.', 'leadealer' );
				break;
			}

			$normalized = wp_normalize_path( $real );
			if ( isset( $present[ $normalized ] ) ) {
				continue;
			}

			try {
				$phpmailer->addAttachment( $real, sanitize_file_name( $friendly_name ) );
				$present[ $normalized ] = true;
			} catch ( Exception $exception ) {
				$this->attachment_prepare_error = __( 'The mail transport refused a verified photo attachment.', 'leadealer' );
				break;
			}
		}

		if ( '' !== $this->attachment_prepare_error && method_exists( $phpmailer, 'clearAllRecipients' ) ) {
			$phpmailer->clearAllRecipients();
		}
	}

	/**
	 * Refresh a frozen snapshot from current form settings before manual retry.
	 *
	 * The existing snapshot remains untouched if the form or submitted payload
	 * is no longer available.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function refresh_snapshot_from_current_form( $entry_id ) {
		$entry = $this->entries->get( $entry_id );
		if ( ! $entry ) {
			return;
		}

		$form   = $this->forms->get( (int) $entry->form_id );
		$values = json_decode( (string) $entry->payload, true );
		if ( ! $form || ! is_array( $values ) || empty( $values ) ) {
			return;
		}

		$notification = $this->prepare_notification( $form, $values );
		if ( ! empty( $notification['error'] ) ) {
			$this->entries->add_event( $entry_id, 'snapshot_refresh_failed', 'warning', $notification['error'] );
			return;
		}

		if ( $this->entries->update_mail_snapshot( $entry_id, $notification ) ) {
			$this->entries->add_event( $entry_id, 'snapshot_refreshed', 'info', __( 'Notification snapshot refreshed from the current form settings.', 'leadealer' ) );
		}
	}

	/**
	 * Handle a failed wp_mail() attempt and schedule recovery.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $error    Mail error.
	 * @return void
	 */
	private function handle_failed_attempt( $entry_id, $error ) {
		$error = sanitize_textarea_field( $error );
		$entry = $this->entries->get( $entry_id );
		if ( ! $entry ) {
			return;
		}

		$this->entries->add_event(
			$entry_id,
			'mail_attempt_failed',
			'error',
			$error,
			array( 'attempt' => (int) $entry->mail_attempts )
		);

		if ( (int) $entry->mail_attempts >= self::MAX_ATTEMPTS ) {
			$this->move_to_dead_letter( $entry_id, $error );
			return;
		}

		$delays  = array( 60, 300, 900, 3600, 21600 );
		$attempt = max( 1, (int) $entry->mail_attempts );
		$delay   = isset( $delays[ $attempt - 1 ] ) ? $delays[ $attempt - 1 ] : 21600;
		$next_at = gmdate( 'Y-m-d H:i:s', time() + $delay );
		$this->entries->mark_mail_retrying( $entry_id, $error, $next_at );

		$args      = array( absint( $entry_id ) );
		$scheduled = true;
		if ( ! wp_next_scheduled( 'leadealer_retry_mail', $args ) ) {
			$scheduled = wp_schedule_single_event( time() + $delay, 'leadealer_retry_mail', $args, true );
		}

		$context = array(
			'attempt'       => (int) $entry->mail_attempts,
			'delay_seconds' => $delay,
		);
		if ( is_wp_error( $scheduled ) ) {
			$context['cron_error'] = sanitize_text_field( $scheduled->get_error_message() );
		} elseif ( false === $scheduled ) {
			$context['cron_error'] = 'schedule_failed';
		}

		$this->entries->add_event(
			$entry_id,
			'mail_retry_scheduled',
			'warning',
			sprintf(
				/* translators: %d: Number of seconds before the next delivery attempt. */
				__( 'Automatic recovery scheduled in %d seconds.', 'leadealer' ),
				$delay
			),
			$context
		);
	}

	/**
	 * Move a lead to the dead-letter queue.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $error    Last error.
	 * @return void
	 */
	private function move_to_dead_letter( $entry_id, $error ) {
		$error = sanitize_textarea_field( $error );
		$this->clear_watchdog( $entry_id );
		$this->clear_retry( $entry_id );
		$this->entries->mark_mail_dead( $entry_id, $error );
		$this->entries->add_event(
			$entry_id,
			'mail_dead_letter',
			'error',
			__( 'Automatic delivery stopped. The lead is safe in Lead Vault and requires administrator attention.', 'leadealer' )
		);
	}

	/**
	 * Schedule a watchdog before entering the mail transport.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function schedule_watchdog( $entry_id ) {
		$args = array( absint( $entry_id ) );
		if ( wp_next_scheduled( 'leadealer_mail_watchdog', $args ) ) {
			return;
		}

		$scheduled = wp_schedule_single_event( time() + self::WATCHDOG_DELAY, 'leadealer_mail_watchdog', $args, true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			$message = is_wp_error( $scheduled )
				? $scheduled->get_error_message()
				: __( 'WordPress could not schedule the crash-recovery watchdog.', 'leadealer' );
			$this->entries->add_event(
				$entry_id,
				'watchdog_schedule_failed',
				'warning',
				$message,
				array( 'fallback' => 'recovery_worker' )
			);
		}
	}

	/**
	 * Clear a pending watchdog after a normal mail return path.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function clear_watchdog( $entry_id ) {
		if ( ! $entry_id ) {
			return;
		}

		$args      = array( absint( $entry_id ) );
		$timestamp = wp_next_scheduled( 'leadealer_mail_watchdog', $args );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'leadealer_mail_watchdog', $args );
		}
	}

	/**
	 * Clear a pending single retry after a successful or terminal path.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function clear_retry( $entry_id ) {
		$args      = array( absint( $entry_id ) );
		$timestamp = wp_next_scheduled( 'leadealer_retry_mail', $args );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'leadealer_retry_mail', $args );
		}
	}

	/**
	 * Rebuild a frozen header list from an allowlist.
	 *
	 * Lead Vault data is treated as untrusted at use time as well as at write
	 * time. The HTML content type is enforced by the plugin; the only persisted
	 * user-derived header allowed back into wp_mail() is a syntactically valid
	 * Reply-To address. Cc, Bcc and arbitrary X-* headers are never restored
	 * from storage, which prevents header injection even if a database row is
	 * modified outside Leadealer.
	 *
	 * @param mixed $headers Stored header list.
	 * @return string[]
	 */
	private function sanitize_stored_headers( $headers ) {
		$clean = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! is_array( $headers ) ) {
			return $clean;
		}

		foreach ( $headers as $header ) {
			if ( ! is_scalar( $header ) ) {
				continue;
			}

			$header = trim( (string) $header );
			if ( '' === $header || strlen( $header ) > 1000 || false !== strpos( $header, "\r" ) || false !== strpos( $header, "\n" ) ) {
				continue;
			}

			$separator = strpos( $header, ':' );
			if ( false === $separator ) {
				continue;
			}

			$name  = strtolower( trim( substr( $header, 0, $separator ) ) );
			$value = trim( substr( $header, $separator + 1 ) );
			if ( 'reply-to' !== $name ) {
				continue;
			}

			$email = sanitize_email( $value );
			if ( is_email( $email ) ) {
				$clean[] = 'Reply-To: ' . $email;
				break;
			}
		}

		return $clean;
	}

	/**
	 * Bound and redact a transport error before it is persisted in Lead Vault.
	 *
	 * Mailer errors are administrator-only, but they can contain absolute local
	 * filesystem paths or other noisy implementation detail. Keep enough text
	 * to diagnose delivery while removing path disclosure and control bytes.
	 *
	 * @param mixed $message Raw mail transport error.
	 * @return string
	 */
	private function sanitize_mail_error( $message ) {
		$message = is_scalar( $message ) ? (string) $message : '';
		$message = str_replace( array( "\0", "\r" ), '', $message );

		$paths = array();
		foreach ( array( 'ABSPATH', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR' ) as $constant ) {
			if ( defined( $constant ) ) {
				$value = constant( $constant );
				if ( is_string( $value ) && '' !== $value ) {
					$paths[] = wp_normalize_path( $value );
					$paths[] = $value;
				}
			}
		}

		foreach ( array_unique( array_filter( $paths ) ) as $path ) {
			$message = str_replace( $path, '[redacted-path]/', $message );
		}

		$message = sanitize_textarea_field( $message );
		$message = wp_html_excerpt( $message, 1000, '' );

		return '' !== trim( $message ) ? $message : __( 'The stored lead could not be prepared for delivery.', 'leadealer' );
	}

	/**
	 * Find first valid email field for Reply-To.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Sanitized values.
	 * @return string
	 */
	private function find_reply_to( $form, $values ) {
		foreach ( $form['fields'] as $field ) {
			if ( 'email' !== $field['type'] ) {
				continue;
			}

			$value = isset( $values[ $field['id'] ] ) ? sanitize_email( $values[ $field['id'] ] ) : '';
			if ( is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Replace supported subject tokens.
	 *
	 * @param string $text   Text template.
	 * @param array  $form   Form definition.
	 * @param array  $values Sanitized values.
	 * @return string
	 */
	private function replace_tokens( $text, $form, $values ) {
		$text = str_replace( '{{form:title}}', $form['title'], $text );

		foreach ( $values as $field_id => $value ) {
			$text = str_replace( '{{field:' . $field_id . '}}', $value, $text );
		}

		return $text;
	}
}

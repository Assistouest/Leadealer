<?php
/**
 * Public REST endpoints.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles challenge and submission endpoints.
 */
final class Leadealer_REST_Controller {

	/**
	 * Form repository.
	 *
	 * @var Leadealer_Form_Repository
	 */
	private $forms;

	/**
	 * Entry repository.
	 *
	 * @var Leadealer_Entry_Repository
	 */
	private $entries;

	/**
	 * Security service.
	 *
	 * @var Leadealer_Security
	 */
	private $security;

	/**
	 * Mailer.
	 *
	 * @var Leadealer_Mailer
	 */
	private $mailer;

	/**
	 * Upload repository.
	 *
	 * @var Leadealer_Upload_Repository
	 */
	private $uploads;

	/**
	 * Image processor.
	 *
	 * @var Leadealer_Image_Processor
	 */
	private $image_processor;

	/**
	 * Constructor.
	 *
	 * @param Leadealer_Form_Repository   $forms           Form repository.
	 * @param Leadealer_Entry_Repository  $entries         Entry repository.
	 * @param Leadealer_Security          $security        Security service.
	 * @param Leadealer_Mailer            $mailer          Mailer.
	 * @param Leadealer_Upload_Repository $uploads         Upload repository.
	 * @param Leadealer_Image_Processor   $image_processor Image processor.
	 */
	public function __construct( $forms, $entries, $security, $mailer, $uploads, $image_processor ) {
		$this->forms           = $forms;
		$this->entries         = $entries;
		$this->security        = $security;
		$this->mailer          = $mailer;
		$this->uploads         = $uploads;
		$this->image_processor = $image_processor;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'leadealer/v1',
			'/challenge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'challenge' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'leadealer/v1',
			'/submit/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return absint( $value ) > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			'leadealer/v1',
			'/upload/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return absint( $value ) > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * Force no-store headers on every response from this plugin namespace.
	 *
	 * @param WP_HTTP_Response $response REST response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  REST request.
	 * @return WP_HTTP_Response
	 */
	public function disable_rest_caching( $response, $server, $request ) {
		unset( $server );

		if ( 0 !== strpos( $request->get_route(), '/leadealer/v1/' ) ) {
			return $response;
		}

		if ( method_exists( $response, 'header' ) ) {
			$this->set_no_store_headers( $response );
		}

		return $response;
	}

	/**
	 * Issue a challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function challenge( $request ) {
		$params = $this->decode_json_request( $request, 4096, 4, array( 'form_id', 'action' ) );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$form_id = isset( $params['form_id'] ) && is_scalar( $params['form_id'] ) ? absint( $params['form_id'] ) : 0;
		$action  = isset( $params['action'] ) && is_scalar( $params['action'] ) ? (string) $params['action'] : 'submit';
		$action  = in_array( $action, array( 'submit', 'upload' ), true ) ? $action : 'submit';
		$form    = $this->forms->get( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'leadealer' ), array( 'status' => 404 ) );
		}

		$challenge_limit = (int) apply_filters( 'leadealer_challenge_rate_limit', 300, $form_id );
		if ( $challenge_limit > 0 && ! $this->security->rate_limit( 'challenge', $form_id, $challenge_limit, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please try again shortly.', 'leadealer' ),
				array( 'status' => 429 )
			);
		}

		$challenge = $this->security->create_challenge( $form_id, $action );
		if ( is_wp_error( $challenge ) ) {
			$challenge->add_data( array( 'status' => 503 ) );
			return $challenge;
		}

		$response = rest_ensure_response( $challenge );
		$this->set_no_store_headers( $response );

		return $response;
	}

	/**
	 * Process a public submission.
	 *
	 * Once the Lead Vault insert succeeds, mail delivery can never turn the public
	 * submission back into an error. Delivery is a recoverable side effect; the
	 * stored lead is the source of truth.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( $request ) {
		$form_id = absint( $request['id'] );
		$form    = $this->forms->get( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'leadealer' ), array( 'status' => 404 ) );
		}


		$content_type = strtolower( trim( (string) $request->get_header( 'content-type' ) ) );
		$is_multipart = 0 === strpos( $content_type, 'multipart/form-data' );

		if ( $is_multipart ) {
			$params = $this->decode_multipart_submit_request( $request );
		} else {
			$params = $this->decode_json_request(
				$request,
				262144,
				8,
				array( 'submission_id', 'idempotency_key', 'pow_token', 'pow_nonce', 'honeypot', 'fields', 'file_fields' )
			);
		}
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$honeypot_values = isset( $params['honeypot'] ) ? $params['honeypot'] : array();
		$field_values    = isset( $params['fields'] ) ? $params['fields'] : array();
		$file_fields     = isset( $params['file_fields'] ) ? $params['file_fields'] : array();
		if ( ! is_array( $honeypot_values ) || count( $honeypot_values ) > 4 || ! is_array( $field_values ) || count( $field_values ) > 120 || ! is_array( $file_fields ) || count( $file_fields ) > 10 ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$normalized_file_fields = array();
		foreach ( $file_fields as $file_field ) {
			if ( ! is_scalar( $file_field ) ) {
				return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
			}
			$raw_file_field = (string) $file_field;
			$clean_file_field = sanitize_key( $raw_file_field );
			if ( '' === $clean_file_field || $clean_file_field !== $raw_file_field || isset( $normalized_file_fields[ $clean_file_field ] ) ) {
				return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
			}
			$normalized_file_fields[ $clean_file_field ] = true;
		}
		$file_fields = array_keys( $normalized_file_fields );

		if ( ! $is_multipart && ! empty( $file_fields ) ) {
			return new WP_Error( 'invalid_upload', __( 'The selected photo was not transmitted with the form. Please try again.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$honeypot_name = $this->security->get_honeypot_name( $form_id );
		foreach ( array_keys( $honeypot_values ) as $honeypot_key ) {
			if ( ! is_string( $honeypot_key ) || $honeypot_key !== $honeypot_name ) {
				return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
			}
		}

		if ( isset( $honeypot_values[ $honeypot_name ] ) && ! is_scalar( $honeypot_values[ $honeypot_name ] ) ) {
			return $this->success_response( $form );
		}
		$honeypot = isset( $honeypot_values[ $honeypot_name ] ) ? (string) $honeypot_values[ $honeypot_name ] : '';
		if ( strlen( $honeypot ) > 1000 || '' !== trim( $honeypot ) ) {
			return $this->success_response( $form );
		}

		$uuid            = isset( $params['submission_id'] ) && is_scalar( $params['submission_id'] ) ? sanitize_text_field( (string) $params['submission_id'] ) : '';
		$idempotency_key = isset( $params['idempotency_key'] ) && is_scalar( $params['idempotency_key'] ) ? (string) $params['idempotency_key'] : '';
		if ( ! $this->security->valid_uuid( $uuid ) || ! $this->security->valid_idempotency_key( $idempotency_key ) ) {
			return new WP_Error( 'invalid_submission_id', __( 'Invalid submission identifier.', 'leadealer' ), array( 'status' => 400 ) );
		}

		/*
		 * A duplicate request may bypass Proof of Work replay validation only when
		 * it proves knowledge of the 256-bit secret generated by the original
		 * browser. The public response never exposes the Lead Vault row ID.
		 */
		$existing = $this->entries->find_by_submission( $form_id, $uuid );
		if ( $existing ) {
			if ( ! $this->entries->matches_idempotency_key( $existing, $idempotency_key ) ) {
				return new WP_Error( 'submission_conflict', __( 'This submission cannot be resumed. Please reload the form and try again.', 'leadealer' ), array( 'status' => 409 ) );
			}

			return $this->success_response( $form );
		}

		$token = isset( $params['pow_token'] ) && is_scalar( $params['pow_token'] ) ? (string) $params['pow_token'] : '';
		$nonce = isset( $params['pow_nonce'] ) && is_scalar( $params['pow_nonce'] ) ? $params['pow_nonce'] : '';
		$proof = $this->security->verify_proof( $form_id, $token, $nonce, 'submit' );

		if ( is_wp_error( $proof ) ) {
			$proof->add_data( array( 'status' => 403 ) );
			return $proof;
		}

		if ( ! $this->security->consume_token( $token, (int) $proof['exp'] ) ) {
			return new WP_Error(
				'proof_replayed',
				__( 'This anti-spam proof has already been used. Please try again.', 'leadealer' ),
				array( 'status' => 409 )
			);
		}


		/*
		 * Count only requests that proved possession of a fresh, valid Proof of
		 * Work token. Malformed unauthenticated traffic must not be able to burn
		 * the shared rate-limit budget for legitimate visitors behind the same IP.
		 */
		$submit_limit = (int) apply_filters( 'leadealer_submit_rate_limit', 60, $form_id );
		if ( $submit_limit > 0 && ! $this->security->rate_limit( 'submit', $form_id, $submit_limit, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many submissions. Please try again later.', 'leadealer' ),
				array( 'status' => 429 )
			);
		}

		$created_upload_ids = array();
		if ( $is_multipart ) {
			$direct_result = $this->process_direct_submission_files( $request, $form, $field_values, $file_fields );
			if ( is_wp_error( $direct_result ) ) {
				return $direct_result;
			}
			$field_values       = $direct_result['fields'];
			$created_upload_ids = $direct_result['upload_ids'];
		}

		$values = $this->forms->sanitize_submission( $form, $field_values, $uuid );
		if ( is_wp_error( $values ) ) {
			$this->uploads->discard_pending_uploads( $created_upload_ids );
			$values->add_data( array( 'status' => 400 ) );
			return $values;
		}

		/*
		 * A direct file can become inapplicable if server-side conditional logic
		 * hides its field. Never leave that file pending: only upload IDs that
		 * survived schema sanitization are allowed to continue toward the Vault.
		 */
		if ( ! empty( $created_upload_ids ) ) {
			$used_upload_ids = array();
			foreach ( $form['fields'] as $field ) {
				if ( is_array( $field ) && isset( $field['id'], $field['type'] ) && 'file' === $field['type'] && ! empty( $values[ $field['id'] ] ) ) {
					$used_upload_ids[] = absint( $values[ $field['id'] ] );
				}
			}
			$this->uploads->discard_pending_uploads( array_diff( $created_upload_ids, $used_upload_ids ) );
		}

		$notification        = $this->mailer->prepare_notification( $form, $values );
		$attachment_manifest = $this->build_attachment_manifest( $form, $values );
		$entry_result        = $this->entries->create(
			$form_id,
			$uuid,
			$idempotency_key,
			$values,
			$notification,
			! empty( $form['settings']['save_entries'] ),
			$attachment_manifest
		);
		if ( is_wp_error( $entry_result ) ) {
			$this->uploads->discard_pending_uploads( $created_upload_ids );
			return new WP_Error( 'storage_failed', __( 'Your request could not be stored safely. Please try again.', 'leadealer' ), array( 'status' => 500 ) );
		}

		$this->claim_uploads_and_send( $form, $values, $notification, $entry_result, $uuid );

		return $this->success_response( $form );
	}

	/**
	 * Freeze which uploaded files belong to the notification before the lead is stored.
	 *
	 * The manifest is deliberately separate from the mutable form schema. A later
	 * form edit or deletion must never make an old retry forget that its email was
	 * supposed to contain a photo.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Sanitized submitted values.
	 * @return array<int,array{field_id:string,upload_id:int}>
	 */
	private function build_attachment_manifest( $form, $values ) {
		$manifest = array();

		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) || 'file' !== $field['type'] || empty( $field['id'] ) ) {
				continue;
			}

			$upload_id = isset( $values[ $field['id'] ] ) ? absint( $values[ $field['id'] ] ) : 0;
			if ( $upload_id <= 0 ) {
				continue;
			}

			$manifest[] = array(
				'field_id'  => sanitize_key( $field['id'] ),
				'upload_id' => $upload_id,
			);
		}

		return $manifest;
	}

	/**
	 * Claim any uploaded files for this submission and make the immediate
	 * send attempt, but only for the request that actually created the
	 * entry.
	 *
	 * A duplicate/retried request for the same submission_uuid can reach
	 * this point too: create()'s INSERT IGNORE only lets one request
	 * actually insert the row ($entry_result['created'] === true), while a
	 * racing sibling request resolves the same entry via
	 * find_by_submission() ($entry_result['created'] === false). Only the
	 * request that actually created the entry may claim uploads and make
	 * the immediate send attempt — otherwise a losing request could send
	 * the notification before its sibling claims the photo, leaving the
	 * mail permanently attachment-less even though the upload was claimed
	 * moments later.
	 *
	 * @param array $form          Form definition.
	 * @param array $values        Sanitized submitted values.
	 * @param array $notification  Notification envelope from prepare_notification().
	 * @param array  $entry_result Result from Leadealer_Entry_Repository::create().
	 * @param string $submission_id Browser-generated submission UUID bound to reserved uploads.
	 * @return void
	 */
	private function claim_uploads_and_send( $form, $values, $notification, $entry_result, $submission_id ) {
		if ( ! $entry_result['created'] ) {
			return;
		}

		$entry_id = (int) $entry_result['id'];

		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['type'] ) || 'file' !== $field['type'] ) {
				continue;
			}

			/*
			 * A conditionally hidden file field is dropped from $values entirely.
			 * For a visible optional field, retain an explicit diagnostic when no
			 * photo was supplied.
			 */
			if ( ! array_key_exists( $field['id'], $values ) ) {
				continue;
			}

			if ( empty( $values[ $field['id'] ] ) ) {
				$this->entries->add_event(
					$entry_id,
					'attachment_not_submitted',
					'info',
					__( 'This lead was submitted without a photo for an optional photo field that was visible to the visitor.', 'leadealer' )
				);
				continue;
			}

			$this->uploads->claim( (int) $values[ $field['id'] ], $entry_id, (int) $form['id'], $field['id'], $submission_id );
		}

		/*
		 * Recover a reservation if a claim was interrupted, then verify the final
		 * database state. A lead that declares an attachment must never proceed to
		 * mail delivery until that exact upload is durably owned by the Vault row.
		 */
		$this->uploads->claim_reserved_for_entry( $entry_id, (int) $form['id'], $submission_id );
		$attachment_claim_failed = false;
		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'], $field['type'] ) || 'file' !== $field['type'] || empty( $values[ $field['id'] ] ) ) {
				continue;
			}

			$claimed_row = $this->uploads->get_by_entry_and_field( $entry_id, $field['id'] );
			if ( ! $claimed_row || (int) $claimed_row->id !== (int) $values[ $field['id'] ] ) {
				$attachment_claim_failed = true;
				$this->entries->add_event(
					$entry_id,
					'attachment_claim_failed',
					'error',
					__( 'The attached photo could not be linked safely to this lead. Email delivery was blocked so the message cannot be sent without its photo.', 'leadealer' )
				);
			}
		}

		if ( $attachment_claim_failed ) {
			$this->entries->mark_mail_dead(
				$entry_id,
				__( 'Email delivery was blocked because an expected photo could not be linked safely to the Lead Vault entry.', 'leadealer' )
			);
			return;
		}

		if ( ! empty( $notification['error'] ) ) {
			$this->entries->mark_mail_dead( $entry_id, $notification['error'] );
			$this->entries->add_event(
				$entry_id,
				'mail_configuration_error',
				'error',
				$notification['error']
			);
			return;
		}

		/*
		 * Make one immediate attempt for installations where WP-Cron is disabled.
		 * Failure remains invisible to the visitor because the lead is already
		 * durable and the retry/recovery workers own subsequent delivery.
		 */
		$this->mailer->send_entry( $entry_id );
	}

	/**
	 * Accept, validate, and re-encode a single image upload for a file field.
	 *
	 * Legacy compatibility endpoint for front-end JavaScript cached before
	 * Leadealer 0.7.3. New clients carry the image atomically on /submit.
	 * This route remains equally strict: client type, extension and content are
	 * untrusted, and the response exposes only an opaque signed token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		$form_id = absint( $request['id'] );
		$form    = $this->forms->get( $form_id );

		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'leadealer' ), array( 'status' => 404 ) );
		}

		$content_type = strtolower( trim( (string) $request->get_header( 'content-type' ) ) );
		if ( '' === $content_type || 0 !== strpos( $content_type, 'multipart/form-data' ) ) {
			return new WP_Error( 'unsupported_media_type', __( 'The upload must be sent as a file, not JSON.', 'leadealer' ), array( 'status' => 415 ) );
		}

		$content_length   = $request->get_header( 'content-length' );
		$max_request_size = Leadealer_Upload_Repository::max_upload_bytes() + 262144; // Allow bounded multipart overhead.
		if ( is_scalar( $content_length ) && ctype_digit( (string) $content_length ) && (int) $content_length > $max_request_size ) {
			return new WP_Error( 'upload_too_large', sprintf( /* translators: %s: Maximum upload size. */ __( 'The photo is too large. Maximum size is %s.', 'leadealer' ), size_format( Leadealer_Upload_Repository::max_upload_bytes() ) ), array( 'status' => 413 ) );
		}

		$field_param = $request->get_param( 'field_id' );
		if ( ! is_scalar( $field_param ) ) {
			return new WP_Error( 'invalid_upload_field', __( 'This form does not accept a file for that field.', 'leadealer' ), array( 'status' => 400 ) );
		}
		$field_id     = sanitize_key( (string) $field_param );
		$target_field = null;
		foreach ( $form['fields'] as $field ) {
			if ( $field_id === $field['id'] && 'file' === $field['type'] ) {
				$target_field = $field;
				break;
			}
		}
		if ( null === $target_field ) {
			return new WP_Error( 'invalid_upload_field', __( 'This form does not accept a file for that field.', 'leadealer' ), array( 'status' => 400 ) );
		}


		$token_param = $request->get_param( 'pow_token' );
		$nonce       = $request->get_param( 'pow_nonce' );
		if ( ! is_scalar( $token_param ) || ! is_scalar( $nonce ) ) {
			return new WP_Error( 'invalid_proof', __( 'The anti-spam proof is invalid.', 'leadealer' ), array( 'status' => 403 ) );
		}
		$token = (string) $token_param;
		$proof = $this->security->verify_proof( $form_id, $token, $nonce, 'upload' );
		if ( is_wp_error( $proof ) ) {
			$proof->add_data( array( 'status' => 403 ) );
			return $proof;
		}
		if ( ! $this->security->consume_token( $token, (int) $proof['exp'] ) ) {
			return new WP_Error( 'proof_replayed', __( 'This anti-spam proof has already been used. Please try again.', 'leadealer' ), array( 'status' => 409 ) );
		}


		/* Invalid unauthenticated requests must not exhaust the upload quota. */
		$upload_limit = (int) apply_filters( 'leadealer_upload_rate_limit', 20, $form_id );
		if ( $upload_limit > 0 && ! $this->security->rate_limit( 'upload', $form_id, $upload_limit, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many uploads. Please try again shortly.', 'leadealer' ), array( 'status' => 429 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files ) || count( $files ) > 1 || ! isset( $files['attachment'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Please attach exactly one photo.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$stored = $this->store_uploaded_file( $files['attachment'], $form_id, $field_id );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$response = rest_ensure_response(
			array(
				'token'     => $stored['token'],
				'width'     => $stored['width'],
				'height'    => $stored['height'],
				'byte_size' => $stored['byte_size'],
			)
		);
		$this->set_no_store_headers( $response );

		return $response;
	}

	/**
	 * Decode the payload portion of an atomic multipart submission.
	 *
	 * Only one scalar JSON body field named "payload" is accepted. Route and
	 * query parameters are intentionally ignored so merged REST parameters can
	 * never override security-sensitive values from the multipart body.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array|WP_Error
	 */
	private function decode_multipart_submit_request( $request ) {
		$content_length   = $request->get_header( 'content-length' );
		$max_request_size = Leadealer_Upload_Repository::max_upload_bytes() + 262144;
		if ( is_scalar( $content_length ) && ctype_digit( (string) $content_length ) && (int) $content_length > $max_request_size ) {
			return new WP_Error(
				'upload_too_large',
				sprintf(
					/* translators: %s: Maximum upload size, e.g. "10 MB". */
					__( 'The photo is too large. Maximum size is %s.', 'leadealer' ),
					size_format( Leadealer_Upload_Repository::max_upload_bytes() )
				),
				array( 'status' => 413 )
			);
		}

		$body_params = $request->get_body_params();
		if ( ! is_array( $body_params ) || 1 !== count( $body_params ) || ! array_key_exists( 'payload', $body_params ) || ! is_scalar( $body_params['payload'] ) ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$raw_payload = (string) $body_params['payload'];
		if ( '' === $raw_payload || strlen( $raw_payload ) > 262144 ) {
			return new WP_Error( 'request_too_large', __( 'The request is too large.', 'leadealer' ), array( 'status' => 413 ) );
		}

		$params = json_decode( $raw_payload, true, 8 );
		if ( ! is_array( $params ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$allowed_keys = array( 'submission_id', 'idempotency_key', 'pow_token', 'pow_nonce', 'honeypot', 'fields', 'file_fields' );
		foreach ( array_keys( $params ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed_keys, true ) ) {
				return new WP_Error( 'unexpected_request_data', __( 'The request contains unexpected data.', 'leadealer' ), array( 'status' => 400 ) );
			}
		}

		return $params;
	}

	/**
	 * Store files carried atomically by the same request as the lead.
	 *
	 * @param WP_REST_Request $request      REST request.
	 * @param array           $form         Form definition.
	 * @param array           $field_values Raw submitted field values.
	 * @param string[]        $file_fields  File field IDs declared by the browser.
	 * @return array{fields:array,upload_ids:int[]}|WP_Error
	 */
	private function process_direct_submission_files( $request, $form, $field_values, $file_fields ) {
		$files = $request->get_file_params();
		$files = is_array( $files ) ? $files : array();

		if ( empty( $file_fields ) ) {
			if ( ! empty( $files ) ) {
				return new WP_Error( 'unexpected_upload', __( 'The request contains an unexpected photo.', 'leadealer' ), array( 'status' => 400 ) );
			}
			return array(
				'fields'     => $field_values,
				'upload_ids' => array(),
			);
		}

		if ( count( $files ) !== count( $file_fields ) ) {
			return new WP_Error( 'invalid_upload', __( 'A selected photo was not transmitted completely. Please attach it again.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$upload_limit = (int) apply_filters( 'leadealer_upload_rate_limit', 20, (int) $form['id'] );
		if ( $upload_limit > 0 && ! $this->security->rate_limit( 'upload', (int) $form['id'], $upload_limit, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many uploads. Please try again shortly.', 'leadealer' ), array( 'status' => 429 ) );
		}

		$file_schema = array();
		foreach ( $form['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['id'], $field['type'] ) && 'file' === $field['type'] ) {
				$file_schema[ $field['id'] ] = true;
			}
		}

		$expected_part_names = array();
		foreach ( $file_fields as $field_id ) {
			if ( ! isset( $file_schema[ $field_id ] ) ) {
				return new WP_Error( 'invalid_upload_field', __( 'This form does not accept a file for that field.', 'leadealer' ), array( 'status' => 400 ) );
			}
			$expected_part_names[ 'leadealer_file_' . $field_id ] = $field_id;
		}

		foreach ( array_keys( $files ) as $part_name ) {
			if ( ! is_string( $part_name ) || ! isset( $expected_part_names[ $part_name ] ) ) {
				return new WP_Error( 'unexpected_upload', __( 'The request contains an unexpected photo.', 'leadealer' ), array( 'status' => 400 ) );
			}
		}

		$created_ids = array();
		foreach ( $expected_part_names as $part_name => $field_id ) {
			if ( ! array_key_exists( $part_name, $files ) ) {
				$this->uploads->discard_pending_uploads( $created_ids );
				return new WP_Error( 'invalid_upload', __( 'A selected photo was not transmitted completely. Please attach it again.', 'leadealer' ), array( 'status' => 400 ) );
			}

			$stored = $this->store_uploaded_file( $files[ $part_name ], (int) $form['id'], $field_id );
			if ( is_wp_error( $stored ) ) {
				$this->uploads->discard_pending_uploads( $created_ids );
				return $stored;
			}

			$created_ids[]             = (int) $stored['id'];
			$field_values[ $field_id ] = $stored['token'];
		}

		return array(
			'fields'     => $field_values,
			'upload_ids' => $created_ids,
		);
	}

	/**
	 * Validate, decode, re-encode and persist one temporary HTTP upload.
	 *
	 * @param mixed  $file     One element from WP_REST_Request::get_file_params().
	 * @param int    $form_id  Form ID.
	 * @param string $field_id File field ID.
	 * @return array{id:int,token:string,width:int,height:int,byte_size:int}|WP_Error
	 */
	private function store_uploaded_file( $file, $form_id, $field_id ) {
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['size'], $file['name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Please attach exactly one photo.', 'leadealer' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'error', 'tmp_name', 'size', 'name' ) as $file_key ) {
			if ( ! is_scalar( $file[ $file_key ] ) ) {
				return new WP_Error( 'invalid_upload', __( 'Please attach exactly one photo.', 'leadealer' ), array( 'status' => 400 ) );
			}
		}
		if ( ! is_string( $file['tmp_name'] ) || '' === $file['tmp_name'] || ! is_string( $file['name'] ) || strlen( $file['name'] ) > 255 ) {
			return new WP_Error( 'invalid_upload', __( 'Please attach exactly one photo.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$max_upload_bytes = Leadealer_Upload_Repository::max_upload_bytes();
		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new WP_Error(
				'upload_too_large',
				sprintf(
					/* translators: %s: Maximum upload size, e.g. "10 MB". */
					__( 'The photo is too large. Maximum size is %s.', 'leadealer' ),
					size_format( $max_upload_bytes )
				),
				array( 'status' => 413 )
			);
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Please attach exactly one photo.', 'leadealer' ), array( 'status' => 400 ) );
		}

		$actual_size = @filesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled below.
		if ( false === $actual_size || $actual_size <= 0 || $actual_size > $max_upload_bytes ) {
			return new WP_Error(
				'upload_too_large',
				sprintf(
					/* translators: %s: Maximum upload size, e.g. "10 MB". */
					__( 'The photo is too large. Maximum size is %s.', 'leadealer' ),
					size_format( $max_upload_bytes )
				),
				array( 'status' => 413 )
			);
		}

		$processed = $this->image_processor->process( $file['tmp_name'], (string) $file['name'] );
		if ( is_wp_error( $processed ) ) {
			$processed->add_data( array( 'status' => 422 ) );
			return $processed;
		}

		if ( empty( $processed['path'] ) || ! is_file( $processed['path'] ) || (int) $processed['byte_size'] <= 0 || (int) $processed['byte_size'] > $max_upload_bytes ) {
			if ( ! empty( $processed['path'] ) && is_file( $processed['path'] ) ) {
				wp_delete_file( $processed['path'] );
			}
			return new WP_Error(
				'upload_too_large',
				sprintf(
					/* translators: %s: Maximum upload size, e.g. "10 MB". */
					__( 'The photo is too large. Maximum size is %s.', 'leadealer' ),
					size_format( $max_upload_bytes )
				),
				array( 'status' => 413 )
			);
		}

		$target_dir = Leadealer_Upload_Repository::storage_dir();
		if ( '' === $target_dir || ! is_dir( $target_dir ) || ! is_writable( $target_dir ) || is_link( $target_dir ) ) {
			wp_delete_file( $processed['path'] );
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ), array( 'status' => 500 ) );
		}

		try {
			$filename = bin2hex( random_bytes( 24 ) ) . '.' . $processed['extension'];
		} catch ( Exception $exception ) {
			wp_delete_file( $processed['path'] );
			return new WP_Error( 'upload_entropy_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ), array( 'status' => 500 ) );
		}

		$target_path = trailingslashit( $target_dir ) . $filename;
		$moved       = @rename( $processed['path'], $target_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Cross-filesystem temp dir falls back to copy().
		if ( ! $moved ) {
			$moved = @copy( $processed['path'], $target_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPress.PHP.NoSilencedErrors.Discouraged -- Cross-filesystem fallback.
			wp_delete_file( $processed['path'] );
		}
		if ( ! $moved || ! is_file( $target_path ) || is_link( $target_path ) ) {
			if ( is_file( $target_path ) || is_link( $target_path ) ) {
				wp_delete_file( $target_path );
			}
			return new WP_Error( 'upload_store_failed', __( 'The photo could not be stored. Please try again.', 'leadealer' ), array( 'status' => 500 ) );
		}

		@chmod( $target_path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort least-privilege permissions.

		$created = $this->uploads->create_pending(
			absint( $form_id ),
			sanitize_key( $field_id ),
			$filename,
			$processed['extension'],
			$processed['mime'],
			$processed['byte_size'],
			$processed['width'],
			$processed['height']
		);
		if ( is_wp_error( $created ) ) {
			wp_delete_file( $target_path );
			$created->add_data( array( 'status' => 500 ) );
			return $created;
		}

		return array(
			'id'        => (int) $created['id'],
			'token'     => $created['token'],
			'width'     => (int) $processed['width'],
			'height'    => (int) $processed['height'],
			'byte_size' => (int) $processed['byte_size'],
		);
	}

	/**
	 * Decode and constrain a JSON-only public request.
	 *
	 * @param WP_REST_Request $request      REST request.
	 * @param int             $max_bytes    Maximum raw body size.
	 * @param int             $max_depth    Maximum JSON nesting depth.
	 * @param array           $allowed_keys Allowed top-level keys.
	 * @return array|WP_Error
	 */
	private function decode_json_request( $request, $max_bytes, $max_depth, $allowed_keys ) {
		$content_type = strtolower( trim( (string) $request->get_header( 'content-type' ) ) );
		if ( '' === $content_type || 0 !== strpos( $content_type, 'application/json' ) ) {
			return new WP_Error( 'unsupported_media_type', __( 'The request must use JSON.', 'leadealer' ), array( 'status' => 415 ) );
		}

		$content_length = $request->get_header( 'content-length' );
		if ( is_scalar( $content_length ) && ctype_digit( (string) $content_length ) && (int) $content_length > $max_bytes ) {
			return new WP_Error( 'request_too_large', __( 'The request is too large.', 'leadealer' ), array( 'status' => 413 ) );
		}

		$raw_body = $request->get_body();
		if ( ! is_string( $raw_body ) || strlen( $raw_body ) > $max_bytes ) {
			return new WP_Error( 'request_too_large', __( 'The request is too large.', 'leadealer' ), array( 'status' => 413 ) );
		}

		$params = json_decode( $raw_body, true, max( 2, absint( $max_depth ) ) );
		if ( ! is_array( $params ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request body.', 'leadealer' ), array( 'status' => 400 ) );
		}

		foreach ( array_keys( $params ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed_keys, true ) ) {
				return new WP_Error( 'unexpected_request_data', __( 'The request contains unexpected data.', 'leadealer' ), array( 'status' => 400 ) );
			}
		}

		return $params;
	}

	/**
	 * Build a public success response without exposing internal database IDs.
	 *
	 * @param array $form Form definition.
	 * @return WP_REST_Response
	 */
	private function success_response( $form ) {
		$response = rest_ensure_response(
			array(
				'success' => true,
				'message' => $form['settings']['success_message'],
			)
		);
		$this->set_no_store_headers( $response );

		return $response;
	}

	/**
	 * Prevent browser, reverse proxy, and CDN caching of dynamic responses.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @return void
	 */
	private function set_no_store_headers( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
	}
}

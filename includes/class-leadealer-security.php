<?php
/**
 * Proof of Work and abuse controls.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security service for public forms.
 */
final class Leadealer_Security {

	/**
	 * Create a signed, stateless challenge.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $action  Challenge action scope ('submit' or 'upload').
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_challenge( $form_id, $action = 'submit' ) {
		$action             = in_array( $action, array( 'submit', 'upload' ), true ) ? $action : 'submit';
		$now                = time();
		$default_difficulty = 'upload' === $action ? 14 : 17;
		$difficulty         = (int) apply_filters( 'leadealer_pow_difficulty', (int) get_option( 'leadealer_pow_difficulty', $default_difficulty ), absint( $form_id ), $action );
		$difficulty         = max( 12, min( 22, $difficulty ) );
		try {
			$random = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'challenge_entropy_failed', __( 'Anti-spam verification is temporarily unavailable.', 'leadealer' ) );
		}

		$payload = array(
			'v'    => 1,
			'form' => absint( $form_id ),
			'act'  => $action,
			'iat'  => $now,
			'exp'  => $now + ( 10 * MINUTE_IN_SECONDS ),
			'diff' => $difficulty,
			'rnd'  => $random,
		);
		$json    = wp_json_encode( $payload );
		if ( false === $json ) {
			return new WP_Error( 'challenge_encoding_failed', __( 'Anti-spam verification is temporarily unavailable.', 'leadealer' ) );
		}

		$body      = $this->base64url_encode( $json );
		$signature = hash_hmac( 'sha256', $body, $this->get_secret() );

		return array(
			'token'      => $body . '.' . $signature,
			'difficulty' => $difficulty,
			'expires'    => $payload['exp'],
		);
	}

	/**
	 * Verify a challenge and Proof of Work nonce.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $token   Signed challenge.
	 * @param mixed  $nonce   Proof nonce.
	 * @param string $action  Expected challenge action scope ('submit' or 'upload').
	 * @return array|WP_Error
	 */
	public function verify_proof( $form_id, $token, $nonce, $action = 'submit' ) {
		if ( ! is_string( $token ) || strlen( $token ) > 2048 || ! is_scalar( $nonce ) || ! preg_match( '/^\d{1,10}$/', (string) $nonce ) ) {
			return new WP_Error( 'invalid_proof', __( 'The anti-spam proof is invalid.', 'leadealer' ) );
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return new WP_Error( 'invalid_proof', __( 'The anti-spam proof is invalid.', 'leadealer' ) );
		}

		list( $body, $signature ) = $parts;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,1536}$/', $body ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'invalid_proof', __( 'The anti-spam proof is invalid.', 'leadealer' ) );
		}

		$expected_signature = hash_hmac( 'sha256', $body, $this->get_secret() );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new WP_Error( 'invalid_signature', __( 'The anti-spam proof could not be verified.', 'leadealer' ) );
		}

		$json    = $this->base64url_decode( $body );
		$payload = json_decode( $json, true );
		$now     = time();

		if ( ! is_array( $payload ) || 1 !== (int) ( isset( $payload['v'] ) ? $payload['v'] : 0 ) ) {
			return new WP_Error( 'invalid_challenge', __( 'The anti-spam challenge is invalid.', 'leadealer' ) );
		}

		$required = array( 'form', 'act', 'iat', 'exp', 'diff', 'rnd' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $payload ) || ! is_scalar( $payload[ $key ] ) ) {
				return new WP_Error( 'invalid_challenge', __( 'The anti-spam challenge is invalid.', 'leadealer' ) );
			}
		}
		if ( ! is_string( $payload['rnd'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $payload['rnd'] ) ) {
			return new WP_Error( 'invalid_challenge', __( 'The anti-spam challenge is invalid.', 'leadealer' ) );
		}

		$action = in_array( $action, array( 'submit', 'upload' ), true ) ? $action : 'submit';
		if ( $action !== (string) $payload['act'] ) {
			return new WP_Error( 'wrong_challenge_action', __( 'This anti-spam proof cannot be used for this action.', 'leadealer' ) );
		}

		if ( absint( $form_id ) !== absint( $payload['form'] ) ) {
			return new WP_Error( 'wrong_form', __( 'The anti-spam challenge does not match this form.', 'leadealer' ) );
		}

		$issued_at  = (int) $payload['iat'];
		$expires_at = (int) $payload['exp'];
		if ( $issued_at <= 0 || $expires_at <= $issued_at || ( $expires_at - $issued_at ) > ( 15 * MINUTE_IN_SECONDS ) || $now > $expires_at || $issued_at > $now + 30 ) {
			return new WP_Error( 'expired_challenge', __( 'The anti-spam challenge has expired. Please try again.', 'leadealer' ) );
		}

		$difficulty = max( 12, min( 22, (int) $payload['diff'] ) );
		$nonce      = (string) absint( $nonce );
		$hash       = hash( 'sha256', $token . ':' . $nonce );

		if ( ! $this->hash_meets_difficulty( $hash, $difficulty ) ) {
			return new WP_Error( 'insufficient_proof', __( 'The anti-spam proof is incomplete.', 'leadealer' ) );
		}

		return $payload;
	}

	/**
	 * Atomically consume a challenge token.
	 *
	 * @param string $token      Signed challenge.
	 * @param int    $expires_at Challenge expiry timestamp.
	 * @return bool
	 */
	public function consume_token( $token, $expires_at ) {
		global $wpdb;

		$table      = Leadealer_Database::pow_table();
		$token_hash = hash( 'sha256', $token );
		$result     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic replay protection requires dedicated table access; nothing to cache for a write.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (token_hash, expires_at) VALUES (%s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$token_hash,
				absint( $expires_at )
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Apply an atomic fixed-window rate limit.
	 *
	 * @param string $action    Rate limit action name.
	 * @param int    $form_id   Form ID.
	 * @param int    $limit     Allowed requests.
	 * @param int    $window    Window in seconds.
	 * @return bool True when allowed.
	 */
	public function rate_limit( $action, $form_id, $limit, $window ) {
		global $wpdb;

		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		$window_start = (int) ( floor( time() / $window ) * $window );
		$bucket       = implode( '|', array( $action, absint( $form_id ), $window_start, $ip ) );
		$bucket_hash  = hash_hmac( 'sha256', $bucket, $this->get_secret() );
		$expires_at   = $window_start + $window + 60;
		$table        = Leadealer_Database::rate_table();

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic rate limiting requires dedicated table access; nothing to cache for a write.
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket_hash, hits, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1, expires_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$bucket_hash,
				$expires_at,
				$expires_at
			)
		);

		if ( false === $updated ) {
			return false;
		}

		$hits = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current atomic counter must be read directly.
			$wpdb->prepare(
				"SELECT hits FROM {$table} WHERE bucket_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
				$bucket_hash
			)
		);

		if ( null === $hits || false === $hits ) {
			return false;
		}

		return (int) $hits <= absint( $limit );
	}

	/**
	 * Get deterministic honeypot field name for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	public function get_honeypot_name( $form_id ) {
		return 'pf_' . substr( hash_hmac( 'sha256', 'honeypot|' . absint( $form_id ), $this->get_secret() ), 0, 12 );
	}

	/**
	 * Verify a UUID v4-like submission identifier.
	 *
	 * @param mixed $uuid UUID candidate.
	 * @return bool
	 */
	public function valid_uuid( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid );
	}

	/**
	 * Validate a browser-held idempotency secret.
	 *
	 * A 32-byte base64url token is 43 characters without padding.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public function valid_idempotency_key( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $value );
	}

	/**
	 * Check whether a hexadecimal SHA-256 digest has enough leading zero bits.
	 *
	 * @param string $hash       Hex digest.
	 * @param int    $difficulty Required leading zero bits.
	 * @return bool
	 */
	private function hash_meets_difficulty( $hash, $difficulty ) {
		$full_nibbles = (int) floor( $difficulty / 4 );
		$remaining    = $difficulty % 4;

		if ( str_repeat( '0', $full_nibbles ) !== substr( $hash, 0, $full_nibbles ) ) {
			return false;
		}

		if ( 0 === $remaining ) {
			return true;
		}

		$next_nibble = hexdec( $hash[ $full_nibbles ] );
		$max_value   = ( 1 << ( 4 - $remaining ) ) - 1;

		return $next_nibble <= $max_value;
	}

	/**
	 * Get plugin secret.
	 *
	 * @return string
	 */
	private function get_secret() {
		$secret = get_option( 'leadealer_secret' );

		if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
			return $secret;
		}

		return wp_salt( 'auth' );
	}

	/**
	 * Get client IP address from the direct connection.
	 *
	 * Reverse proxy support can be added explicitly with the filter after a
	 * trusted proxy has normalized the value.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip          = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
		$filtered    = apply_filters( 'leadealer_client_ip', $ip );

		if ( ! is_string( $filtered ) || strlen( $filtered ) > 45 || ! filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $filtered;
	}

	/**
	 * Base64 URL encode a string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding signed JSON, not obfuscating executable code.
	}

	/**
	 * Base64 URL decode a string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_decode( $value ) {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding signed JSON, not executable code.

		return false === $decoded ? '' : $decoded;
	}
}

<?php
/**
 * WordPress privacy tools integration.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports and erases stored entries by email address.
 */
final class Leadealer_Privacy {

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
	 * Constructor.
	 *
	 * @param Leadealer_Entry_Repository $entries Entry repository.
	 * @param Leadealer_Form_Repository  $forms   Form repository.
	 */
	public function __construct( $entries, $forms ) {
		$this->entries = $entries;
		$this->forms   = $forms;
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['leadealer'] = array(
			'exporter_friendly_name' => __( 'Leadealer entries', 'leadealer' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['leadealer'] = array(
			'eraser_friendly_name' => __( 'Leadealer entries', 'leadealer' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export matching entries.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function export( $email_address, $page = 1 ) {
		$page   = max( 1, absint( $page ) );
		$limit  = 50;
		$rows   = $this->entries->find_by_email( sanitize_email( $email_address ), ( $page - 1 ) * $limit, $limit );
		$export = array();

		foreach ( $rows as $row ) {
			$payload        = json_decode( $row->payload, true );
			$data           = array();
			$form           = $this->forms->get( (int) $row->form_id );
			$file_field_ids = array();
			if ( $form ) {
				foreach ( $form['fields'] as $field ) {
					if ( 'file' === $field['type'] ) {
						$file_field_ids[ $field['id'] ] = true;
					}
				}
			}

			if ( is_array( $payload ) ) {
				foreach ( $payload as $key => $value ) {
					$is_file = isset( $file_field_ids[ $key ] ) && '' !== trim( (string) $value );
					$data[]  = array(
						'name'  => sanitize_key( $key ),
						'value' => $is_file
							? __( 'An uploaded photo (not included in this export; contact the site owner if a copy is required).', 'leadealer' )
							: (string) $value,
					);
				}
			}

			$export[] = array(
				'group_id'    => 'leadealer',
				'group_label' => __( 'Leadealer entries', 'leadealer' ),
				'item_id'     => 'entry-' . absint( $row->id ),
				'data'        => $data,
			);
		}

		return array(
			'data' => $export,
			'done' => count( $rows ) < $limit,
		);
	}

	/**
	 * Erase matching entries.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function erase( $email_address, $page = 1 ) {
		unset( $page );
		$rows          = $this->entries->find_by_email( sanitize_email( $email_address ), 0, 50 );
		$items_removed = false;

		foreach ( $rows as $row ) {
			$items_removed = $this->entries->delete( (int) $row->id ) || $items_removed;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < 50,
		);
	}

	/**
	 * Add suggested privacy policy content.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'When a visitor submits a Leadealer form, the site stores the accepted lead before attempting email delivery. The Lead Vault can retain submitted fields, a frozen notification snapshot needed for retry recovery, the form identifier, a random submission identifier, a one-way hash used to recognize safe browser retries, mail delivery status, delivery timeline events, and timestamps. After successful mail handoff, submitted values can be retained or removed according to the form storage setting. The plugin uses a short-lived cryptographic Proof of Work challenge and a temporary HMAC-derived network identifier for abuse prevention. The raw IP address is not stored by Leadealer.', 'leadealer' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When lead storage is enabled, accepted photo attachments are kept in Leadealer private storage with the Lead Vault entry until that lead is erased or removed by the configured retention cleanup. When lead storage is disabled, claimed photo attachments can remain for up to 24 hours after WordPress accepts the email so asynchronous mail transports can still read them, then scheduled cleanup removes the files.', 'leadealer' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Leadealer', 'leadealer' ), wp_kses_post( $content ) );
	}
}

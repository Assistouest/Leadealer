<?php
/**
 * Responsive HTML email template renderer.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds schema-driven, transport-neutral HTML notifications.
 */
final class Leadealer_Mail_Template {

	/**
	 * Build the HTML email body for a validated submission.
	 *
	 * Only fields present in the sanitized submission are rendered. This means
	 * conditionally hidden fields stay out of the message automatically.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Sanitized submitted values.
	 * @return string
	 */
	public function build( $form, $values ) {
		$title     = isset( $form['title'] ) && '' !== trim( (string) $form['title'] ) ? (string) $form['title'] : __( 'Form submission', 'leadealer' );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );
		$rows      = $this->build_rows( isset( $form['fields'] ) ? $form['fields'] : array(), $values );
		$received  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$lang      = str_replace( '_', '-', determine_locale() );
		$direction = is_rtl() ? 'rtl' : 'ltr';

		if ( '' === $rows ) {
			$rows = $this->build_empty_row();
		}

		$heading = __( 'New form submission', 'leadealer' );
		$source  = sprintf(
			/* translators: 1: Form title. 2: Site name. */
			__( '%1$s on %2$s', 'leadealer' ),
			$title,
			$site_name
		);
		$footer = sprintf(
			/* translators: %s: Local date and time when the form was received. */
			__( 'Received on %s', 'leadealer' ),
			$received
		);

		return '<!doctype html>'
			. '<html lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $direction ) . '"><head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<meta name="color-scheme" content="light">'
			. '<meta name="supported-color-schemes" content="light">'
			. '<title>' . esc_html( $heading ) . '</title>'
			. '<style type="text/css">'
			. '@media only screen and (max-width:620px){.leadealer-email-shell{width:100%!important}.leadealer-email-pad{padding-left:20px!important;padding-right:20px!important}.leadealer-email-outer{padding:16px 8px!important}}'
			. '</style>'
			. '</head>'
			. '<body style="margin:0;padding:0;background-color:#f6f7f7;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f6f7f7" style="width:100%;margin:0;padding:0;background-color:#f6f7f7;">'
			. '<tr><td class="leadealer-email-outer" align="center" style="padding:32px 12px;">'
			. '<table role="presentation" class="leadealer-email-shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;border-collapse:separate;background-color:#ffffff;border:1px solid #dcdcde;border-radius:8px;">'
			. '<tr><td class="leadealer-email-pad" style="padding:26px 28px 22px;border-bottom:3px solid #2271b1;">'
			. '<h1 style="margin:0 0 8px;color:#1d2327;font-size:22px;line-height:1.3;font-weight:600;">' . esc_html( $heading ) . '</h1>'
			. '<p style="margin:0;color:#646970;font-size:14px;line-height:1.5;">' . esc_html( $source ) . '</p>'
			. '</td></tr>'
			. '<tr><td class="leadealer-email-pad" style="padding:8px 28px 16px;">' . $rows . '</td></tr>'
			. '<tr><td class="leadealer-email-pad" style="padding:16px 28px 20px;background-color:#f6f7f7;border-top:1px solid #dcdcde;color:#646970;font-size:12px;line-height:1.5;">'
			. esc_html( $footer ) . '<br>'
			. '<a href="' . esc_url( $site_url ) . '" style="color:#2271b1;text-decoration:underline;">' . esc_html( $site_name ) . '</a>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	/**
	 * Build a sample message for the exact saved schema.
	 *
	 * @param array $form Form definition.
	 * @return string
	 */
	public function build_preview( $form ) {
		$values = array();
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		foreach ( $fields as $field ) {
			if ( empty( $field['id'] ) ) {
				continue;
			}

			$values[ $field['id'] ] = $this->preview_value( $field );
		}

		return $this->build( $form, $values );
	}

	/**
	 * Build all field rows in schema order.
	 *
	 * @param array $fields Field schema.
	 * @param array $values Submitted values.
	 * @return string
	 */
	private function build_rows( $fields, $values ) {
		$rows = '';

		if ( ! is_array( $fields ) || ! is_array( $values ) ) {
			return $rows;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['id'] ) || ! array_key_exists( $field['id'], $values ) ) {
				continue;
			}

			$label = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? (string) $field['label'] : __( 'Field', 'leadealer' );
			$value = $this->render_value( $field, $values[ $field['id'] ] );

			$rows .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">'
				. '<tr><td style="padding:15px 0 5px;color:#646970;font-size:12px;line-height:1.4;font-weight:600;">' . esc_html( $label ) . '</td></tr>'
				. '<tr><td style="padding:0 0 15px;border-bottom:1px solid #e2e4e7;color:#1d2327;font-size:16px;line-height:1.55;word-break:break-word;">' . $value . '</td></tr>'
				. '</table>';
		}

		return $rows;
	}

	/**
	 * Render one field value for email clients.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Sanitized value.
	 * @return string
	 */
	private function render_value( $field, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';

		if ( in_array( $type, array( 'checkbox', 'consent' ), true ) ) {
			return '1' === $value ? esc_html__( 'Yes', 'leadealer' ) : esc_html__( 'No', 'leadealer' );
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$value = $this->choice_label( isset( $field['options'] ) ? $field['options'] : array(), $value );
		}

		if ( 'file' === $type ) {
			return '' !== trim( $value )
				? esc_html__( 'See the attached photo.', 'leadealer' )
				: '<span style="color:#8c8f94;">&mdash;</span>';
		}

		if ( '' === trim( $value ) ) {
			return '<span style="color:#8c8f94;">&mdash;</span>';
		}

		switch ( $type ) {
			case 'email':
				$email = sanitize_email( $value );
				if ( is_email( $email ) ) {
					return '<a href="mailto:' . esc_attr( $email ) . '" style="color:#2271b1;text-decoration:underline;">' . esc_html( $email ) . '</a>';
				}
				break;

			case 'tel':
				$tel = preg_replace( '/[^0-9+]/', '', $value );
				if ( is_string( $tel ) && '' !== $tel ) {
					return '<a href="tel:' . esc_attr( $tel ) . '" style="color:#2271b1;text-decoration:underline;">' . esc_html( $value ) . '</a>';
				}
				break;

			case 'url':
				$url = esc_url( $value );
				if ( '' !== $url ) {
					return '<a href="' . $url . '" style="color:#2271b1;text-decoration:underline;word-break:break-all;">' . esc_html( $value ) . '</a>';
				}
				break;

			case 'textarea':
				return nl2br( esc_html( $value ) );
		}

		return esc_html( $value );
	}

	/**
	 * Resolve either a stable option value or an already-sanitized label.
	 *
	 * @param array  $options Field options.
	 * @param string $value   Stored value or label.
	 * @return string
	 */
	private function choice_label( $options, $value ) {
		if ( ! is_array( $options ) ) {
			return $value;
		}

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['label'], $option['value'] ) ) {
				continue;
			}

			if ( $value === (string) $option['value'] || $value === (string) $option['label'] ) {
				return (string) $option['label'];
			}
		}

		return $value;
	}

	/**
	 * Build a useful sample value for the admin email preview.
	 *
	 * @param array $field Field definition.
	 * @return string
	 */
	private function preview_value( $field ) {
		$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';

		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! empty( $field['options'][0]['label'] ) ) {
			return (string) $field['options'][0]['label'];
		}

		switch ( $type ) {
			case 'email':
				return 'alex@example.com';
			case 'tel':
				return '+33612345678';
			case 'textarea':
				return __( 'The message entered by the visitor will appear here.', 'leadealer' );
			case 'number':
				return '123';
			case 'url':
				return home_url( '/' );
			case 'checkbox':
			case 'consent':
				return '1';
			case 'file':
				return '1';
			case 'text':
			default:
				if ( ! empty( $field['placeholder'] ) ) {
					return (string) $field['placeholder'];
				}
				return __( 'Example value', 'leadealer' );
		}
	}

	/**
	 * Build a fallback row when no submitted field is available.
	 *
	 * @return string
	 */
	private function build_empty_row() {
		return '<p style="margin:18px 0;color:#646970;font-size:14px;line-height:1.5;">' . esc_html__( 'No submitted field is available for this notification.', 'leadealer' ) . '</p>';
	}
}

<?php
/**
 * Form storage and validation.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles persisted form definitions.
 */
final class Leadealer_Form_Repository {

	/**
	 * Allowed field types.
	 *
	 * @var string[]
	 */
	private $allowed_types = array( 'text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'consent', 'number', 'url', 'file' );

	/**
	 * Default international dialing prefix.
	 *
	 * @var string
	 */
	private $default_phone_country_code = '+33';

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
	 * Get a published form definition.
	 *
	 * @param int $form_id Form post ID.
	 * @return array|null
	 */
	public function get( $form_id ) {
		$form_id = absint( $form_id );
		$post    = get_post( $form_id );

		if ( ! $post || 'leadealer_form' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$schema   = $this->normalize_schema( get_post_meta( $form_id, '_leadealer_form_schema', true ) );
		$settings = $this->normalize_settings( get_post_meta( $form_id, '_leadealer_form_settings', true ) );

		return array(
			'id'          => $form_id,
			'title'       => $this->normalize_display_text( get_the_title( $form_id ) ),
			'description' => $this->normalize_display_textarea( get_post_meta( $form_id, '_leadealer_form_description', true ) ),
			'fields'      => $schema,
			'settings'    => $settings,
		);
	}

	/**
	 * Get all forms for admin selectors.
	 *
	 * @return array<int,array{id:int,title:string}>
	 */
	public function get_all_for_select() {
		$data = array();
		$page = 1;

		do {
			$posts = get_posts(
				array(
					'post_type'      => 'leadealer_form',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 100,
					'paged'          => $page,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			foreach ( $posts as $post ) {
				$data[] = array(
					'id'    => (int) $post->ID,
					'title' => get_the_title( $post ),
				);
			}

			$post_count = count( $posts );
			++$page;
		} while ( 100 === $post_count );

		return $data;
	}

	/**
	 * Normalize a schema from storage or request data.
	 *
	 * Accepts stored arrays or JSON input and returns the canonical field schema.
	 *
	 * @param mixed $schema Raw schema.
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize_schema( $schema ) {
		if ( is_string( $schema ) ) {
			$decoded = json_decode( $schema, true );
			$schema  = is_array( $decoded ) ? $decoded : array();
		}

		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			$schema = $schema['fields'];
		}

		if ( ! is_array( $schema ) ) {
			return array();
		}

		$normalized = array();
		$seen_ids   = array();

		foreach ( array_slice( $schema, 0, 100 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			if ( ! in_array( $type, $this->allowed_types, true ) ) {
				continue;
			}

			$id = isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '';
			if ( ! $id || isset( $seen_ids[ $id ] ) ) {
				$id = strtolower( sanitize_key( 'field_' . wp_generate_password( 8, false, false ) ) );
			}
			$seen_ids[ $id ] = true;

			$options = $this->normalize_options( isset( $field['options'] ) ? $field['options'] : array() );
			$default = isset( $field['default'] ) ? $this->normalize_display_text( $field['default'] ) : '';
			if ( 'file' === $type ) {
				$default = '';
			} elseif ( in_array( $type, array( 'select', 'radio' ), true ) ) {
				$default = $this->normalize_choice_value( $default, $options );
			}

			$layout  = isset( $field['layout'] ) && is_array( $field['layout'] ) ? $field['layout'] : array();
			$desktop = $this->normalize_span( isset( $layout['desktop'] ) ? $layout['desktop'] : 12 );
			$tablet  = $this->normalize_span( isset( $layout['tablet'] ) ? $layout['tablet'] : $desktop );
			$mobile  = $this->normalize_span( isset( $layout['mobile'] ) ? $layout['mobile'] : 12 );

			$appearance = isset( $field['appearance'] ) ? sanitize_key( $field['appearance'] ) : 'default';
			if ( 'textarea' !== $type || ! in_array( $appearance, array( 'default', 'message' ), true ) ) {
				$appearance = 'default';
			}

			$normalized[] = array(
				'id'               => $id,
				'type'             => $type,
				'appearance'       => $appearance,
				'label'            => isset( $field['label'] ) ? $this->normalize_display_text( $field['label'] ) : __( 'Field', 'leadealer' ),
				'description'      => isset( $field['description'] ) ? $this->normalize_display_textarea( $field['description'] ) : '',
				'placeholder'      => isset( $field['placeholder'] ) ? $this->normalize_display_text( $field['placeholder'] ) : '',
				'default'          => $default,
				'autocomplete'     => isset( $field['autocomplete'] ) ? sanitize_key( $field['autocomplete'] ) : '',
				'required'         => ! empty( $field['required'] ),
				'options'          => $options,
				'layout'           => array(
					'desktop' => $desktop,
					'tablet'  => $tablet,
					'mobile'  => $mobile,
				),
				'_conditional_raw' => isset( $field['conditional'] ) ? $field['conditional'] : array(),
			);
		}

		$field_map = array();
		foreach ( $normalized as $field ) {
			$field_map[ $field['id'] ] = $field;
		}

		foreach ( $normalized as $index => $field ) {
			$normalized[ $index ]['conditional'] = $this->normalize_conditional_logic(
				$field['_conditional_raw'],
				$field['id'],
				$field_map
			);
			unset( $normalized[ $index ]['_conditional_raw'] );
		}

		$normalized = $this->repair_legacy_it_services_template( $normalized );
		$normalized = $this->repair_it_services_template_copy( $normalized );
		$normalized = $this->repair_it_services_template_add_photo_field( $normalized );
		$normalized = $this->repair_it_services_template_reorder_photo_field( $normalized );

		return $normalized;
	}

	/**
	 * Repair the exact legacy built-in IT services template schema.
	 *
	 * Leadealer 0.4.1 added the required message field to the starter template,
	 * but forms created from the earlier six-field template keep their saved
	 * schema. Only the exact legacy field-ID sequence is repaired here, so
	 * unrelated or customized forms are never modified.
	 *
	 * @param array<int,array<string,mixed>> $schema Normalized schema.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_legacy_it_services_template( $schema ) {
		$legacy_ids = array(
			'full_name',
			'email',
			'phone',
			'service_requested',
			'service_address',
			'consent',
		);
		$field_ids  = array();
		foreach ( $schema as $field ) {
			$field_ids[] = isset( $field['id'] ) ? $field['id'] : '';
		}

		if ( $field_ids !== $legacy_ids ) {
			return $schema;
		}

		$message = array(
			'id'           => 'message',
			'type'         => 'textarea',
			'appearance'   => 'message',
			'label'        => __( 'How can we help?', 'leadealer' ),
			'description'  => '',
			'placeholder'  => __( 'Describe your request, the issue you are experiencing, or your project…', 'leadealer' ),
			'default'      => '',
			'autocomplete' => '',
			'required'     => true,
			'options'      => array(),
			'layout'       => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'conditional'  => array(
				'enabled' => false,
				'match'   => 'all',
				'rules'   => array(),
			),
		);

		array_splice( $schema, 5, 0, array( $message ) );

		return $schema;
	}


	/**
	 * Refresh customer-facing copy for an untouched IT services starter template.
	 *
	 * Stable technical option values remain unchanged so conditional logic keeps
	 * working. Customized service labels or choices are deliberately left alone.
	 *
	 * @param array<int,array<string,mixed>> $schema Normalized schema.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_it_services_template_copy( $schema ) {
		foreach ( $schema as $index => $field ) {
			if ( 'service_requested' !== $field['id'] || 'select' !== $field['type'] ) {
				continue;
			}

			$values         = array_map(
				static function ( $option ) {
					return isset( $option['value'] ) ? $option['value'] : '';
				},
				$field['options']
			);
			$starter_values = array(
				'home-computer-support',
				'computer-repair',
				'wordpress-website-creation',
				'contact-or-quote',
			);

			if ( $values !== $starter_values ) {
				return $schema;
			}

			$old_labels = array( 'Service requested', 'La prestation souhaitée' );
			if ( ! in_array( $field['label'], $old_labels, true ) ) {
				return $schema;
			}

			$old_option_labels = array(
				array( 'Home computer support', 'Un dépannage informatique à domicile' ),
				array( 'Computer repair', 'La réparation de mon ordinateur' ),
				array( 'WordPress website creation', 'La création d’un site WordPress' ),
				array( 'Contact me or request a quote', 'Prendre contact ou demander un devis' ),
			);
			foreach ( $field['options'] as $option_index => $option ) {
				if ( ! in_array( $option['label'], $old_option_labels[ $option_index ], true ) ) {
					return $schema;
				}
			}

			$schema[ $index ]['label']               = __( 'What do you need?', 'leadealer' );
			$schema[ $index ]['options'][0]['label'] = __( 'Home computer support', 'leadealer' );
			$schema[ $index ]['options'][1]['label'] = __( 'Computer repair', 'leadealer' );
			$schema[ $index ]['options'][2]['label'] = __( 'WordPress website creation', 'leadealer' );
			$schema[ $index ]['options'][3]['label'] = __( 'Quote request or another question', 'leadealer' );

			return $schema;
		}

		return $schema;
	}

	/**
	 * Add the optional "attach a photo" field to already-saved IT services
	 * template instances that predate it.
	 *
	 * Only the exact current 7-field sequence is repaired, so a form that has
	 * since been customized (fields added, removed, or reordered) is left
	 * untouched. This mirrors repair_legacy_it_services_template()'s
	 * read-time-only convention: the repair is not written back to postmeta
	 * until the form is next saved in the builder.
	 *
	 * @param array<int,array<string,mixed>> $schema Normalized schema.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_it_services_template_add_photo_field( $schema ) {
		$expected_ids = array( 'full_name', 'email', 'phone', 'service_requested', 'service_address', 'message', 'consent' );
		$field_ids    = array();
		foreach ( $schema as $field ) {
			$field_ids[] = isset( $field['id'] ) ? $field['id'] : '';
		}

		if ( $field_ids !== $expected_ids ) {
			return $schema;
		}

		array_splice( $schema, 5, 0, array( $this->get_it_services_photo_field_definition() ) );

		return $schema;
	}

	/**
	 * Move the "attach a photo" field to after the service address field on
	 * already-saved IT services template instances that still have it in the
	 * earlier position (before the address).
	 *
	 * Only the exact old-order 8-field sequence is repaired, so a form that
	 * has since been customized (fields added, removed, or reordered) is left
	 * untouched. Read-time-only, same convention as the other repairs in this
	 * class: not written back to postmeta until the form is next saved.
	 *
	 * @param array<int,array<string,mixed>> $schema Normalized schema.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_it_services_template_reorder_photo_field( $schema ) {
		$old_order_ids = array( 'full_name', 'email', 'phone', 'service_requested', 'photo', 'service_address', 'message', 'consent' );
		$field_ids     = array();
		foreach ( $schema as $field ) {
			$field_ids[] = isset( $field['id'] ) ? $field['id'] : '';
		}

		if ( $field_ids !== $old_order_ids ) {
			return $schema;
		}

		$photo = $schema[4];
		array_splice( $schema, 4, 1 );
		array_splice( $schema, 5, 0, array( $photo ) );

		return $schema;
	}

	/**
	 * Shared "attach a photo of the error" field definition.
	 *
	 * Used both by the IT services starter template for new forms and by
	 * repair_it_services_template_add_photo_field() for already-saved
	 * instances of it, so the two definitions cannot drift apart.
	 *
	 * @return array<string,mixed>
	 */
	public function get_it_services_photo_field_definition() {
		return array(
			'id'           => 'photo',
			'type'         => 'file',
			'label'        => __( 'Attach a photo of the error', 'leadealer' ),
			'description'  => __( 'A screenshot or photo can help us understand the issue faster.', 'leadealer' ),
			'placeholder'  => '',
			'default'      => '',
			'autocomplete' => '',
			'required'     => false,
			'options'      => array(),
			'layout'       => array(
				'desktop' => 12,
				'tablet'  => 12,
				'mobile'  => 12,
			),
			'conditional'  => array(
				'enabled' => true,
				'match'   => 'any',
				'rules'   => array(
					array(
						'field'    => 'service_requested',
						'operator' => 'equals',
						'value'    => 'home-computer-support',
					),
					array(
						'field'    => 'service_requested',
						'operator' => 'equals',
						'value'    => 'computer-repair',
					),
				),
			),
		);
	}

	/**
	 * Normalize choice options while preserving stable internal values.
	 *
	 * String options are accepted as a shorthand and normalized to label/value
	 * pairs. Internal values stay stable when visible labels are renamed.
	 *
	 * @param mixed $raw_options Raw option list.
	 * @return array<int,array{label:string,value:string}>
	 */
	private function normalize_options( $raw_options ) {
		if ( ! is_array( $raw_options ) ) {
			return array();
		}

		$options = array();
		$used    = array();

		foreach ( array_slice( $raw_options, 0, 100 ) as $index => $option ) {
			$label     = '';
			$raw_value = '';

			if ( is_array( $option ) ) {
				$label     = isset( $option['label'] ) ? $this->normalize_display_text( $option['label'] ) : '';
				$raw_value = isset( $option['value'] ) ? (string) $option['value'] : '';
			} else {
				$label = $this->normalize_display_text( $option );
			}

			if ( '' === $label ) {
				continue;
			}

			$value = sanitize_title( '' !== $raw_value ? $raw_value : $label );
			if ( '' === $value ) {
				$value = 'option-' . ( absint( $index ) + 1 );
			}

			$base   = $value;
			$suffix = 2;
			while ( isset( $used[ $value ] ) ) {
				$value = $base . '-' . $suffix;
				++$suffix;
			}

			$used[ $value ] = true;
			$options[]      = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $options;
	}

	/**
	 * Convert a choice default or visible label to its stable internal value.
	 *
	 * @param string $value   Raw default value.
	 * @param array  $options Normalized options.
	 * @return string
	 */
	private function normalize_choice_value( $value, $options ) {
		foreach ( $options as $option ) {
			if ( $value === $option['value'] || $value === $option['label'] ) {
				return $option['value'];
			}
		}

		return '';
	}

	/**
	 * Normalize conditional display rules.
	 *
	 * @param mixed  $conditional Raw conditional configuration.
	 * @param string $target_id   Field receiving the condition.
	 * @param array  $field_map   Fields indexed by ID.
	 * @return array<string,mixed>
	 */
	private function normalize_conditional_logic( $conditional, $target_id, $field_map ) {
		$normalized = array(
			'enabled' => false,
			'match'   => 'all',
			'rules'   => array(),
		);

		if ( ! is_array( $conditional ) || empty( $conditional['enabled'] ) ) {
			return $normalized;
		}

		$normalized['match'] = isset( $conditional['match'] ) && 'any' === $conditional['match'] ? 'any' : 'all';
		$rules               = isset( $conditional['rules'] ) && is_array( $conditional['rules'] ) ? $conditional['rules'] : array();
		$operators           = array( 'equals', 'not_equals', 'is_empty', 'is_not_empty', 'contains', 'not_contains' );

		foreach ( array_slice( $rules, 0, 10 ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$source_id = isset( $rule['field'] ) ? sanitize_key( $rule['field'] ) : '';
			$operator  = isset( $rule['operator'] ) ? sanitize_key( $rule['operator'] ) : 'equals';
			if ( ! isset( $field_map[ $source_id ] ) || $source_id === $target_id || ! in_array( $operator, $operators, true ) ) {
				continue;
			}

			$value  = isset( $rule['value'] ) ? $this->normalize_display_text( $rule['value'] ) : '';
			$source = $field_map[ $source_id ];

			if ( in_array( $source['type'], array( 'select', 'radio' ), true ) && ! in_array( $operator, array( 'is_empty', 'is_not_empty' ), true ) ) {
				$value = $this->normalize_choice_value( $value, $source['options'] );
				if ( '' === $value ) {
					continue;
				}
			}

			if ( in_array( $source['type'], array( 'checkbox', 'consent' ), true ) ) {
				$value = '1' === $value ? '1' : '';
			}

			$normalized['rules'][] = array(
				'field'    => $source_id,
				'operator' => $operator,
				'value'    => $value,
			);
		}

		$normalized['enabled'] = ! empty( $normalized['rules'] );
		return $normalized;
	}

	/**
	 * Restrict a responsive field span to the 12-column grid.
	 *
	 * @param mixed $value Raw span.
	 * @return int
	 */
	private function normalize_span( $value ) {
		$value = absint( $value );
		return in_array( $value, array( 3, 4, 6, 8, 9, 12 ), true ) ? $value : 12;
	}

	/**
	 * Normalize form settings.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string,mixed>
	 */
	public function normalize_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$recipient = isset( $settings['recipient'] ) ? sanitize_email( $settings['recipient'] ) : '';
		if ( ! is_email( $recipient ) ) {
			$recipient = sanitize_email( get_option( 'admin_email' ) );
		}

		return array(
			'recipient'       => $recipient,
			'subject'         => isset( $settings['subject'] ) && '' !== trim( (string) $settings['subject'] ) ? sanitize_text_field( $settings['subject'] ) : __( 'New message from {{form:title}}', 'leadealer' ),
			'success_message' => isset( $settings['success_message'] ) && '' !== trim( (string) $settings['success_message'] ) ? sanitize_text_field( $settings['success_message'] ) : __( 'Your request has been received.', 'leadealer' ),
			'submit_label'    => isset( $settings['submit_label'] ) && '' !== trim( (string) $settings['submit_label'] ) ? sanitize_text_field( $settings['submit_label'] ) : __( 'Send', 'leadealer' ),
			'show_header'     => ! empty( $settings['show_header'] ),
			'save_entries'    => ! isset( $settings['save_entries'] ) || ! empty( $settings['save_entries'] ),
		);
	}

	/**
	 * Sanitize submitted values against a form schema.
	 *
	 * @param array $form   Form definition.
	 * @param mixed  $values        Submitted values.
	 * @param string $submission_id Browser-generated UUID used to reserve file tokens.
	 * @return array|WP_Error
	 */
	public function sanitize_submission( $form, $values, $submission_id = '' ) {
		if ( ! is_array( $values ) ) {
			return new WP_Error( 'invalid_fields', __( 'Invalid form data.', 'leadealer' ) );
		}

		$allowed_input_keys = array();
		foreach ( $form['fields'] as $field ) {
			$allowed_input_keys[ $field['id'] ] = true;
			if ( 'tel' === $field['type'] ) {
				$allowed_input_keys[ $field['id'] . '__country' ] = true;
			}
		}
		foreach ( array_keys( $values ) as $submitted_key ) {
			if ( ! is_string( $submitted_key ) || strlen( $submitted_key ) > 191 || ! isset( $allowed_input_keys[ $submitted_key ] ) ) {
				return new WP_Error( 'unexpected_field', __( 'The form contains unexpected data. Please reload and try again.', 'leadealer' ) );
			}
		}

		$condition_values = $this->normalize_condition_values( $form, $values );
		if ( is_wp_error( $condition_values ) ) {
			return $condition_values;
		}

		$clean      = array();
		$visibility = $this->get_field_visibility( $form, $condition_values );

		foreach ( $form['fields'] as $field ) {
			$id = $field['id'];
			if ( isset( $visibility[ $id ] ) && ! $visibility[ $id ] ) {
				continue;
			}

			$type     = $field['type'];
			$raw      = isset( $values[ $id ] ) ? (string) $values[ $id ] : '';
			$required = ! empty( $field['required'] );

			switch ( $type ) {
				case 'email':
					$raw_email = trim( $raw );
					if ( '' !== $raw_email && ! is_email( $raw_email ) ) {
						return new WP_Error( 'invalid_email', sprintf( /* translators: %s: Field label. */ __( '%s must contain a valid email address.', 'leadealer' ), $field['label'] ) );
					}
					$value = '' === $raw_email ? '' : sanitize_email( $raw_email );
					break;

				case 'url':
					$raw_url = trim( $raw );
					$value   = '' === $raw_url ? '' : esc_url_raw( $raw_url, array( 'http', 'https' ) );
					$parts   = '' !== $value ? wp_parse_url( $value ) : array();
					if ( '' !== $raw_url && ( '' === $value || ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
						return new WP_Error( 'invalid_url', sprintf( /* translators: %s: Field label. */ __( '%s must contain a valid URL.', 'leadealer' ), $field['label'] ) );
					}
					break;

				case 'number':
					$value = sanitize_text_field( $raw );
					if ( '' !== $value && ! is_numeric( $value ) ) {
						return new WP_Error( 'invalid_number', sprintf( /* translators: %s: Field label. */ __( '%s must contain a number.', 'leadealer' ), $field['label'] ) );
					}
					break;

				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;

				case 'select':
				case 'radio':
					$value = sanitize_text_field( $raw );
					$label = $this->get_choice_label( $field['options'], $value );
					if ( '' !== $value && null === $label ) {
						return new WP_Error( 'invalid_choice', __( 'An invalid choice was submitted.', 'leadealer' ) );
					}
					$value = null === $label ? '' : $label;
					break;

				case 'checkbox':
				case 'consent':
					$value = $this->canonical_checkbox_value( $raw );
					break;

				case 'file':
					if ( '' === $raw ) {
						$value = '';
						break;
					}
					$value = $this->uploads->consume_upload_token( $raw, (int) $form['id'], $id, $submission_id );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
					$value = (string) $value;
					break;

				case 'tel':
					$country_code = isset( $values[ $id . '__country' ] ) ? (string) $values[ $id . '__country' ] : $this->default_phone_country_code;
					$value        = $this->normalize_phone_number( $raw, $country_code );
					if ( is_wp_error( $value ) ) {
						return $value;
					}
					break;

				case 'text':
				default:
					$value = sanitize_text_field( $raw );
					break;
			}

			if ( $required && '' === $value ) {
				return new WP_Error( 'required_field', sprintf( /* translators: %s: Field label. */ __( '%s is required.', 'leadealer' ), $field['label'] ) );
			}

			$clean[ $id ] = $value;
		}

		return $clean;
	}


	/**
	 * Validate every submitted scalar and create canonical values for conditions.
	 *
	 * Hidden fields are validated too. This prevents a crafted REST request from
	 * using alternate representations (for example boolean-like checkbox values)
	 * to make conditional visibility disagree with the value later persisted.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Raw submitted values.
	 * @return array|WP_Error
	 */
	private function normalize_condition_values( $form, $values ) {
		$normalized = array();

		foreach ( $form['fields'] as $field ) {
			$id  = $field['id'];
			$raw = isset( $values[ $id ] ) ? $values[ $id ] : '';
			if ( ! is_scalar( $raw ) && null !== $raw ) {
				return new WP_Error( 'invalid_field', __( 'One or more fields are invalid.', 'leadealer' ) );
			}

			$raw   = (string) $raw;
			$limit = $this->raw_value_limit( $field['type'] );
			if ( strlen( $raw ) > $limit ) {
				return new WP_Error( 'field_too_long', __( 'A field is too long.', 'leadealer' ) );
			}

			switch ( $field['type'] ) {
				case 'checkbox':
				case 'consent':
					$normalized[ $id ] = $this->canonical_checkbox_value( $raw );
					break;
				case 'textarea':
					$normalized[ $id ] = sanitize_textarea_field( $raw );
					break;
				case 'email':
					$normalized[ $id ] = trim( sanitize_email( $raw ) );
					break;
				case 'url':
					$normalized[ $id ] = trim( esc_url_raw( $raw, array( 'http', 'https' ) ) );
					break;
				default:
					$normalized[ $id ] = sanitize_text_field( $raw );
					break;
			}

			if ( 'tel' === $field['type'] ) {
				$country_key = $id . '__country';
				$country_raw = isset( $values[ $country_key ] ) ? $values[ $country_key ] : $this->default_phone_country_code;
				if ( ! is_scalar( $country_raw ) || strlen( (string) $country_raw ) > 16 ) {
					return new WP_Error( 'invalid_field', __( 'One or more fields are invalid.', 'leadealer' ) );
				}
			}
		}

		return $normalized;
	}

	/**
	 * Return the maximum raw byte length accepted for a field type.
	 *
	 * @param string $type Field type.
	 * @return int
	 */
	private function raw_value_limit( $type ) {
		switch ( $type ) {
			case 'textarea':
				return 20000;
			case 'file':
				return 2048;
			case 'url':
				return 2048;
			case 'email':
				return 320;
			case 'tel':
				return 80;
			case 'select':
			case 'radio':
				return 191;
			case 'checkbox':
			case 'consent':
				return 16;
			case 'number':
				return 100;
			case 'text':
			default:
				return 500;
		}
	}

	/**
	 * Normalize the accepted checkbox truthy spellings to one canonical value.
	 *
	 * @param string $raw Raw submitted value.
	 * @return string
	 */
	private function canonical_checkbox_value( $raw ) {
		return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '';
	}

	/**
	 * Evaluate which fields are active for a submitted value set.
	 *
	 * Conditions are evaluated recursively so nested conditional fields work.
	 * Cycles are treated as hidden instead of risking infinite recursion.
	 *
	 * @param array $form   Form definition.
	 * @param array $values Raw submitted values.
	 * @return array<string,bool>
	 */
	public function get_field_visibility( $form, $values ) {
		$field_map = array();
		$cache     = array();
		$stack     = array();

		foreach ( $form['fields'] as $field ) {
			$field_map[ $field['id'] ] = $field;
		}

		$evaluate = function ( $field_id ) use ( &$evaluate, &$field_map, &$cache, &$stack, $values ) {
			if ( isset( $cache[ $field_id ] ) ) {
				return $cache[ $field_id ];
			}
			if ( isset( $stack[ $field_id ] ) || ! isset( $field_map[ $field_id ] ) ) {
				return false;
			}

			$field       = $field_map[ $field_id ];
			$conditional = isset( $field['conditional'] ) && is_array( $field['conditional'] ) ? $field['conditional'] : array();
			if ( empty( $conditional['enabled'] ) || empty( $conditional['rules'] ) ) {
				$cache[ $field_id ] = true;
				return true;
			}

			$stack[ $field_id ] = true;
			$results            = array();

			foreach ( $conditional['rules'] as $rule ) {
				$source_id  = $rule['field'];
				$source_raw = '';

				if ( $evaluate( $source_id ) && isset( $values[ $source_id ] ) && ! is_array( $values[ $source_id ] ) && ! is_object( $values[ $source_id ] ) ) {
					$source_raw = (string) $values[ $source_id ];
				}

				$results[] = $this->condition_matches( $source_raw, $rule['operator'], isset( $rule['value'] ) ? (string) $rule['value'] : '' );
			}

			unset( $stack[ $field_id ] );
			$visible            = 'any' === $conditional['match'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
			$cache[ $field_id ] = $visible;
			return $visible;
		};

		foreach ( array_keys( $field_map ) as $field_id ) {
			$cache[ $field_id ] = (bool) $evaluate( $field_id );
		}

		return $cache;
	}

	/**
	 * Compare one conditional rule.
	 *
	 * @param string $actual   Submitted source value.
	 * @param string $operator Rule operator.
	 * @param string $expected Rule comparison value.
	 * @return bool
	 */
	private function condition_matches( $actual, $operator, $expected ) {
		$actual = trim( (string) $actual );

		switch ( $operator ) {
			case 'not_equals':
				return $actual !== $expected;
			case 'is_empty':
				return '' === $actual;
			case 'is_not_empty':
				return '' !== $actual;
			case 'contains':
				return '' !== $expected && false !== stripos( $actual, $expected );
			case 'not_contains':
				return '' === $expected || false === stripos( $actual, $expected );
			case 'equals':
			default:
				return $actual === $expected;
		}
	}

	/**
	 * Resolve a stored choice value to its human-readable label.
	 *
	 * @param array  $options Normalized options.
	 * @param string $value   Submitted internal value.
	 * @return string|null
	 */
	private function get_choice_label( $options, $value ) {
		if ( '' === $value ) {
			return '';
		}

		foreach ( $options as $option ) {
			if ( $value === $option['value'] ) {
				return $option['label'];
			}
		}

		return null;
	}

	/**
	 * Return supported international dialing prefixes with a representative flag.
	 *
	 * A few calling codes are shared by several territories. In those cases the
	 * selector uses one representative flag while the submitted value remains the
	 * unambiguous calling code.
	 *
	 * @return array<string,string> Calling code => flag emoji.
	 */
	public function get_phone_country_options() {
		return array(
			'+33'  => '🇫🇷',
			'+1'   => '🇺🇸',
			'+7'   => '🇷🇺',
			'+20'  => '🇪🇬',
			'+27'  => '🇿🇦',
			'+30'  => '🇬🇷',
			'+31'  => '🇳🇱',
			'+32'  => '🇧🇪',
			'+34'  => '🇪🇸',
			'+36'  => '🇭🇺',
			'+39'  => '🇮🇹',
			'+40'  => '🇷🇴',
			'+41'  => '🇨🇭',
			'+43'  => '🇦🇹',
			'+44'  => '🇬🇧',
			'+45'  => '🇩🇰',
			'+46'  => '🇸🇪',
			'+47'  => '🇳🇴',
			'+48'  => '🇵🇱',
			'+49'  => '🇩🇪',
			'+51'  => '🇵🇪',
			'+52'  => '🇲🇽',
			'+53'  => '🇨🇺',
			'+54'  => '🇦🇷',
			'+55'  => '🇧🇷',
			'+56'  => '🇨🇱',
			'+57'  => '🇨🇴',
			'+58'  => '🇻🇪',
			'+60'  => '🇲🇾',
			'+61'  => '🇦🇺',
			'+62'  => '🇮🇩',
			'+63'  => '🇵🇭',
			'+64'  => '🇳🇿',
			'+65'  => '🇸🇬',
			'+66'  => '🇹🇭',
			'+81'  => '🇯🇵',
			'+82'  => '🇰🇷',
			'+84'  => '🇻🇳',
			'+86'  => '🇨🇳',
			'+90'  => '🇹🇷',
			'+91'  => '🇮🇳',
			'+92'  => '🇵🇰',
			'+93'  => '🇦🇫',
			'+94'  => '🇱🇰',
			'+95'  => '🇲🇲',
			'+98'  => '🇮🇷',
			'+211' => '🇸🇸',
			'+212' => '🇲🇦',
			'+213' => '🇩🇿',
			'+216' => '🇹🇳',
			'+218' => '🇱🇾',
			'+220' => '🇬🇲',
			'+221' => '🇸🇳',
			'+222' => '🇲🇷',
			'+223' => '🇲🇱',
			'+224' => '🇬🇳',
			'+225' => '🇨🇮',
			'+226' => '🇧🇫',
			'+227' => '🇳🇪',
			'+228' => '🇹🇬',
			'+229' => '🇧🇯',
			'+230' => '🇲🇺',
			'+231' => '🇱🇷',
			'+232' => '🇸🇱',
			'+233' => '🇬🇭',
			'+234' => '🇳🇬',
			'+235' => '🇹🇩',
			'+236' => '🇨🇫',
			'+237' => '🇨🇲',
			'+238' => '🇨🇻',
			'+239' => '🇸🇹',
			'+240' => '🇬🇶',
			'+241' => '🇬🇦',
			'+242' => '🇨🇬',
			'+243' => '🇨🇩',
			'+244' => '🇦🇴',
			'+245' => '🇬🇼',
			'+246' => '🇮🇴',
			'+248' => '🇸🇨',
			'+249' => '🇸🇩',
			'+250' => '🇷🇼',
			'+251' => '🇪🇹',
			'+252' => '🇸🇴',
			'+253' => '🇩🇯',
			'+254' => '🇰🇪',
			'+255' => '🇹🇿',
			'+256' => '🇺🇬',
			'+257' => '🇧🇮',
			'+258' => '🇲🇿',
			'+260' => '🇿🇲',
			'+261' => '🇲🇬',
			'+262' => '🇷🇪',
			'+263' => '🇿🇼',
			'+264' => '🇳🇦',
			'+265' => '🇲🇼',
			'+266' => '🇱🇸',
			'+267' => '🇧🇼',
			'+268' => '🇸🇿',
			'+269' => '🇰🇲',
			'+290' => '🇸🇭',
			'+291' => '🇪🇷',
			'+297' => '🇦🇼',
			'+298' => '🇫🇴',
			'+299' => '🇬🇱',
			'+350' => '🇬🇮',
			'+351' => '🇵🇹',
			'+352' => '🇱🇺',
			'+353' => '🇮🇪',
			'+354' => '🇮🇸',
			'+355' => '🇦🇱',
			'+356' => '🇲🇹',
			'+357' => '🇨🇾',
			'+358' => '🇫🇮',
			'+359' => '🇧🇬',
			'+370' => '🇱🇹',
			'+371' => '🇱🇻',
			'+372' => '🇪🇪',
			'+373' => '🇲🇩',
			'+374' => '🇦🇲',
			'+375' => '🇧🇾',
			'+376' => '🇦🇩',
			'+377' => '🇲🇨',
			'+378' => '🇸🇲',
			'+380' => '🇺🇦',
			'+381' => '🇷🇸',
			'+382' => '🇲🇪',
			'+383' => '🇽🇰',
			'+385' => '🇭🇷',
			'+386' => '🇸🇮',
			'+387' => '🇧🇦',
			'+389' => '🇲🇰',
			'+420' => '🇨🇿',
			'+421' => '🇸🇰',
			'+423' => '🇱🇮',
			'+500' => '🇫🇰',
			'+501' => '🇧🇿',
			'+502' => '🇬🇹',
			'+503' => '🇸🇻',
			'+504' => '🇭🇳',
			'+505' => '🇳🇮',
			'+506' => '🇨🇷',
			'+507' => '🇵🇦',
			'+508' => '🇵🇲',
			'+509' => '🇭🇹',
			'+590' => '🇬🇵',
			'+591' => '🇧🇴',
			'+592' => '🇬🇾',
			'+593' => '🇪🇨',
			'+594' => '🇬🇫',
			'+595' => '🇵🇾',
			'+596' => '🇲🇶',
			'+597' => '🇸🇷',
			'+598' => '🇺🇾',
			'+599' => '🇨🇼',
			'+670' => '🇹🇱',
			'+672' => '🇳🇫',
			'+673' => '🇧🇳',
			'+674' => '🇳🇷',
			'+675' => '🇵🇬',
			'+676' => '🇹🇴',
			'+677' => '🇸🇧',
			'+678' => '🇻🇺',
			'+679' => '🇫🇯',
			'+680' => '🇵🇼',
			'+681' => '🇼🇫',
			'+682' => '🇨🇰',
			'+683' => '🇳🇺',
			'+685' => '🇼🇸',
			'+686' => '🇰🇮',
			'+687' => '🇳🇨',
			'+688' => '🇹🇻',
			'+689' => '🇵🇫',
			'+690' => '🇹🇰',
			'+691' => '🇫🇲',
			'+692' => '🇲🇭',
			'+850' => '🇰🇵',
			'+852' => '🇭🇰',
			'+853' => '🇲🇴',
			'+855' => '🇰🇭',
			'+856' => '🇱🇦',
			'+880' => '🇧🇩',
			'+886' => '🇹🇼',
			'+960' => '🇲🇻',
			'+961' => '🇱🇧',
			'+962' => '🇯🇴',
			'+963' => '🇸🇾',
			'+964' => '🇮🇶',
			'+965' => '🇰🇼',
			'+966' => '🇸🇦',
			'+967' => '🇾🇪',
			'+968' => '🇴🇲',
			'+970' => '🇵🇸',
			'+971' => '🇦🇪',
			'+972' => '🇮🇱',
			'+973' => '🇧🇭',
			'+974' => '🇶🇦',
			'+975' => '🇧🇹',
			'+976' => '🇲🇳',
			'+977' => '🇳🇵',
			'+992' => '🇹🇯',
			'+993' => '🇹🇲',
			'+994' => '🇦🇿',
			'+995' => '🇬🇪',
			'+996' => '🇰🇬',
			'+998' => '🇺🇿',
		);
	}

	/**
	 * Return supported international dialing prefixes.
	 *
	 * @return string[]
	 */
	public function get_phone_country_codes() {
		return array_keys( $this->get_phone_country_options() );
	}

	/**
	 * Normalize a phone number to E.164-style international notation.
	 *
	 * A number already starting with + or 00 is treated as international.
	 * Otherwise the visitor-selected calling prefix is prepended. For France,
	 * both 695725540 and 06 95 72 55 40 therefore become +33695725540.
	 *
	 * @param string $raw          Visitor-entered number.
	 * @param string $country_code Visitor-selected calling prefix.
	 * @return string|WP_Error
	 */
	private function normalize_phone_number( $raw, $country_code ) {
		$raw = trim( sanitize_text_field( $raw ) );
		if ( '' === $raw ) {
			return '';
		}

		$country_code = preg_replace( '/[^+0-9]/', '', (string) $country_code );
		if ( ! in_array( $country_code, $this->get_phone_country_codes(), true ) ) {
			$country_code = $this->default_phone_country_code;
		}

		if ( ! preg_match( '/^\+?[^+]*$/', $raw ) ) {
			return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'leadealer' ) );
		}

		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits ) {
			return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'leadealer' ) );
		}

		if ( 0 === strpos( $raw, '+' ) ) {
			$international = '+' . $digits;
		} elseif ( 0 === strpos( $digits, '00' ) ) {
			$international = '+' . substr( $digits, 2 );
		} else {
			$country_digits = substr( $country_code, 1 );

			if ( '+33' === $country_code ) {
				if ( 0 === strpos( $digits, '33' ) && 11 === strlen( $digits ) ) {
					$digits = substr( $digits, 2 );
				}

				if ( 0 === strpos( $digits, '0' ) ) {
					$digits = substr( $digits, 1 );
				}

				if ( 9 !== strlen( $digits ) || ! preg_match( '/^[1-9][0-9]{8}$/', $digits ) ) {
					return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'leadealer' ) );
				}
			} else {
				if ( 0 === strpos( $digits, $country_digits ) && strlen( $digits ) > strlen( $country_digits ) + 6 ) {
					$digits = substr( $digits, strlen( $country_digits ) );
				}

				if ( '+39' !== $country_code && 0 === strpos( $digits, '0' ) ) {
					$digits = substr( $digits, 1 );
				}
			}

			$international = '+' . $country_digits . $digits;
		}

		if ( 0 === strpos( $international, '+33' ) ) {
			$french_national = substr( $international, 3 );
			if ( 0 === strpos( $french_national, '0' ) ) {
				$french_national = substr( $french_national, 1 );
			}
			if ( ! preg_match( '/^[1-9][0-9]{8}$/', $french_national ) ) {
				return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'leadealer' ) );
			}
			$international = '+33' . $french_national;
		}

		$international_digits = substr( $international, 1 );
		if ( ! preg_match( '/^[1-9][0-9]{6,14}$/', $international_digits ) ) {
			return new WP_Error( 'invalid_phone', __( 'Please enter a valid phone number.', 'leadealer' ) );
		}

		return $international;
	}

	/**
	 * Sanitize display text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_display_text( $value ) {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize display textarea content.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_display_textarea( $value ) {
		return sanitize_textarea_field( (string) $value );
	}
}

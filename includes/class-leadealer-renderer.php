<?php
/**
 * Front-end form rendering.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders cache-safe forms.
 */
final class Leadealer_Renderer {

	/**
	 * Form repository.
	 *
	 * @var Leadealer_Form_Repository
	 */
	private $forms;

	/**
	 * Security service.
	 *
	 * @var Leadealer_Security
	 */
	private $security;

	/**
	 * Number of forms rendered in the current request.
	 *
	 * @var int
	 */
	private $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * @param Leadealer_Form_Repository $forms    Form repository.
	 * @param Leadealer_Security        $security Security service.
	 */
	public function __construct( $forms, $security ) {
		$this->forms    = $forms;
		$this->security = $security;
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'leadealer_form', array( $this, 'shortcode' ) );
	}

	/**
	 * Register dynamic Gutenberg block.
	 *
	 * @return void
	 */
	public function register_block() {
		wp_register_script(
			'leadealer-block',
			LEADEALER_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			LEADEALER_VERSION,
			true
		);

		wp_set_script_translations( 'leadealer-block', 'leadealer', LEADEALER_DIR . 'languages' );

		$block_forms = current_user_can( 'manage_options' ) ? $this->forms->get_all_for_select() : array();

		wp_localize_script(
			'leadealer-block',
			'LeadealerBlock',
			array( 'forms' => $block_forms )
		);

		register_block_type(
			'leadealer/form',
			array(
				'api_version'     => 3,
				'editor_script'   => 'leadealer-block',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'leadealer_form' );

		return $this->render( absint( $atts['id'] ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render( isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0 );
	}

	/**
	 * Render a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	public function render( $form_id ) {
		$form = $this->forms->get( $form_id );
		if ( ! $form ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Leadealer: form not found or not published.', 'leadealer' ) . '</p>' : '';
		}

		$this->enqueue_assets();
		$this->instance_count += 1;
		$instance              = $this->instance_count;
		$honeypot              = $this->security->get_honeypot_name( $form_id );
		$fields                = '';
		$has_upload_field      = false;

		foreach ( $form['fields'] as $field ) {
			if ( 'file' === $field['type'] ) {
				$has_upload_field = true;
			}
			$fields .= $this->render_field( $field, $form_id, $instance );
		}

		if ( $has_upload_field ) {
			$this->enqueue_upload_assets();
		}

		$header = '';
		if ( ! empty( $form['settings']['show_header'] ) ) {
			$description = '';
			if ( '' !== $form['description'] ) {
				$description = '<p class="leadealer-form__description">' . nl2br( esc_html( $form['description'] ) ) . '</p>';
			}
			$header = '<div class="leadealer-form__header"><h2 class="leadealer-form__title">' . esc_html( $form['title'] ) . '</h2>' . $description . '</div>';
		}

		$challenge_url = rest_url( 'leadealer/v1/challenge' );
		$submit_url    = rest_url( 'leadealer/v1/submit/' . $form_id );

		return $header . sprintf(
			'<form class="leadealer-form" method="post" action="" data-form-id="%1$d" data-challenge-url="%2$s" data-submit-url="%3$s" data-honeypot-name="%6$s" novalidate>
				<div class="leadealer-form__grid">%4$s</div>
				<div class="leadealer-form__trap" aria-hidden="true"><label>%5$s<input type="text" name="%6$s" tabindex="-1" autocomplete="off"></label></div>
				<div class="leadealer-form__status" role="status" aria-live="polite"></div>
				<button class="leadealer-form__submit" type="submit" disabled>%7$s</button>
				<noscript><p>%8$s</p></noscript>
			</form>',
			absint( $form_id ),
			esc_url( $challenge_url ),
			esc_url( $submit_url ),
			$fields,
			esc_html__( 'Leave this field empty', 'leadealer' ),
			esc_attr( $honeypot ),
			esc_html( $form['settings']['submit_label'] ),
			esc_html__( 'JavaScript is required to send this protected form.', 'leadealer' )
		);
	}

	/**
	 * Enqueue front-end assets.
	 *
	 * @return void
	 */
	private function enqueue_assets() {
		wp_enqueue_style( 'leadealer', LEADEALER_URL . 'assets/css/frontend.css', array(), LEADEALER_VERSION );
		wp_enqueue_script( 'leadealer', LEADEALER_URL . 'assets/js/frontend.js', array(), LEADEALER_VERSION, true );
		wp_localize_script(
			'leadealer',
			'Leadealer',
			array(
				'verification'          => __( 'Verification…', 'leadealer' ),
				'waitingForUpload'      => __( 'Finishing photo preparation…', 'leadealer' ),
				'challengeError'        => __( 'Unable to start anti-spam verification.', 'leadealer' ),
				'proofError'            => __( 'Proof of Work could not be completed.', 'leadealer' ),
				'sendError'             => __( 'Your request could not be submitted.', 'leadealer' ),
				'defaultSuccess'        => __( 'Your request has been received.', 'leadealer' ),
				'invalidPhone'          => __( 'Please enter a valid phone number.', 'leadealer' ),
				'secureBrowserRequired' => __( 'Your browser cannot create a secure submission token. Please update it and try again.', 'leadealer' ),
				'phoneCountryCode'      => __( 'Country calling code', 'leadealer' ),
			)
		);
	}

	/**
	 * Localize upload-related strings. HEIC conversion uses only the browser's native decoder.
	 *
	 * Only initialized on pages whose form actually has a photo field.
	 *
	 * @return void
	 */
	private function enqueue_upload_assets() {
		wp_localize_script(
			'leadealer',
			'LeadealerUpload',
			array(
				'maxUploadBytes' => Leadealer_Upload_Repository::max_upload_bytes(),
				'strings'        => array(
					'preparing'         => __( 'Preparing photo…', 'leadealer' ),
					'ready'             => __( 'Photo ready to send.', 'leadealer' ),
					'uploading'         => __( 'Uploading…', 'leadealer' ),
					'converting'        => __( 'Converting photo…', 'leadealer' ),
					'remove'            => __( 'Remove', 'leadealer' ),
					'replace'           => __( 'Replace photo', 'leadealer' ),
					'uploadError'       => __( 'The photo could not be prepared or transmitted. Please try again.', 'leadealer' ),
					'uploadTooLarge'    => sprintf(
						/* translators: %s: Maximum upload size, e.g. "10 MB". */
						__( 'The photo is too large. Maximum size is %s.', 'leadealer' ),
						size_format( Leadealer_Upload_Repository::max_upload_bytes() )
					),
					'uploadUnsupported' => __( 'This file type is not supported. Please use JPEG, PNG, WEBP, or HEIC.', 'leadealer' ),
					'conversionError'   => __( 'This photo could not be converted. Please try a different photo or export it as JPEG.', 'leadealer' ),
				),
			)
		);
	}

	/**
	 * Render one schema field.
	 *
	 * @param array $field    Field definition.
	 * @param int   $form_id  Form ID.
	 * @param int   $instance Render instance number.
	 * @return string
	 */
	private function render_field( $field, $form_id, $instance ) {
		$id           = $field['id'];
		$input_id     = 'leadealer-' . absint( $form_id ) . '-' . absint( $instance ) . '-' . $id;
		$required     = ! empty( $field['required'] );
		$required_a   = $required ? ' required aria-required="true"' : '';
		$placeholder  = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$default      = isset( $field['default'] ) ? (string) $field['default'] : '';
		$autocomplete = '' !== $field['autocomplete'] ? ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '"' : '';
		$layout       = isset( $field['layout'] ) && is_array( $field['layout'] ) ? $field['layout'] : array();
		$desktop      = isset( $layout['desktop'] ) ? absint( $layout['desktop'] ) : 12;
		$tablet       = isset( $layout['tablet'] ) ? absint( $layout['tablet'] ) : 12;
		$mobile       = isset( $layout['mobile'] ) ? absint( $layout['mobile'] ) : 12;
		$appearance   = isset( $field['appearance'] ) ? sanitize_key( $field['appearance'] ) : 'default';
		$classes      = sprintf( 'leadealer-form__field leadealer-d-%1$d leadealer-t-%2$d leadealer-m-%3$d', $desktop, $tablet, $mobile );
		if ( 'textarea' === $field['type'] && 'message' === $appearance ) {
			$classes .= ' leadealer-form__field--message';
		}
		$description    = '';
		$describedby    = '';
		$conditional    = isset( $field['conditional'] ) && is_array( $field['conditional'] ) ? $field['conditional'] : array();
		$is_conditional = ! empty( $conditional['enabled'] ) && ! empty( $conditional['rules'] );
		$disabled_a     = $is_conditional ? ' disabled' : '';
		$wrapper_a      = ' data-leadealer-field-id="' . esc_attr( $id ) . '"';

		if ( $is_conditional ) {
			$conditional_json = wp_json_encode( $conditional, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $conditional_json ) ) {
				$conditional_json = '{}';
			}
			$wrapper_a .= ' data-leadealer-conditional="' . esc_attr( $conditional_json ) . '" hidden aria-hidden="true"';
		}

		if ( '' !== $field['description'] ) {
			$description_id = $input_id . '-description';
			$description    = '<p class="leadealer-form__help" id="' . esc_attr( $description_id ) . '">' . esc_html( $field['description'] ) . '</p>';
			$describedby    = ' aria-describedby="' . esc_attr( $description_id ) . '"';
		}

		$label = '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $field['label'] ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
		$input = '';

		switch ( $field['type'] ) {
			case 'textarea':
				$textarea = '<textarea id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $id ) . '" maxlength="20000"' . $placeholder . $required_a . $autocomplete . $describedby . $disabled_a . '>' . esc_textarea( $default ) . '</textarea>';
				$input    = 'message' === $appearance ? '<div class="leadealer-form__message-bubble">' . $textarea . '</div>' : $textarea;
				break;

			case 'select':
				$options = '<option value="">' . esc_html__( 'Choose…', 'leadealer' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					$options .= '<option value="' . esc_attr( $option['value'] ) . '"' . selected( $default, $option['value'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
				}
				$input = '<select id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $id ) . '"' . $required_a . $autocomplete . $describedby . $disabled_a . '>' . $options . '</select>';
				break;

			case 'radio':
				$options = '';
				foreach ( $field['options'] as $index => $option ) {
					$radio_id = $input_id . '-' . absint( $index );
					$options .= '<label class="leadealer-form__choice" for="' . esc_attr( $radio_id ) . '"><input id="' . esc_attr( $radio_id ) . '" type="radio" name="' . esc_attr( $id ) . '" value="' . esc_attr( $option['value'] ) . '"' . checked( $default, $option['value'], false ) . ( 0 === $index ? $required_a . $describedby : '' ) . $disabled_a . '> <span>' . esc_html( $option['label'] ) . '</span></label>';
				}
				return '<fieldset class="' . esc_attr( $classes ) . '"' . $wrapper_a . '><legend>' . esc_html( $field['label'] ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</legend><div class="leadealer-form__choices">' . $options . '</div>' . $description . '</fieldset>';

			case 'checkbox':
				$checked = '1' === $default ? ' checked' : '';
				return '<div class="' . esc_attr( $classes . ' leadealer-form__field--check' ) . '"' . $wrapper_a . '><label><input type="checkbox" name="' . esc_attr( $id ) . '" value="1"' . $checked . $required_a . $describedby . $disabled_a . '> <span>' . esc_html( $field['label'] ) . ( $required ? ' *' : '' ) . '</span></label>' . $description . '</div>';

			case 'consent':
				$checked         = '1' === $default ? ' checked' : '';
				$privacy_id      = $input_id . '-privacy';
				$privacy_notice  = $this->render_consent_privacy_notice( $privacy_id );
				$describedby_ids = array();
				if ( '' !== $description ) {
					$describedby_ids[] = $description_id;
				}
				if ( '' !== $privacy_notice ) {
					$describedby_ids[] = $privacy_id;
				}
				$consent_describedby = $describedby_ids ? ' aria-describedby="' . esc_attr( implode( ' ', $describedby_ids ) ) . '"' : '';

				return '<div class="' . esc_attr( $classes . ' leadealer-form__field--check leadealer-form__field--consent' ) . '"' . $wrapper_a . '><label><input type="checkbox" name="' . esc_attr( $id ) . '" value="1"' . $checked . $required_a . $consent_describedby . $disabled_a . '> <span>' . esc_html( $field['label'] ) . ( $required ? ' *' : '' ) . '</span></label>' . $description . $privacy_notice . '</div>';

			case 'tel':
				$country_options = '';
				$phone_countries = $this->forms->get_phone_country_options();
				$selected_flag   = isset( $phone_countries['+33'] ) ? $phone_countries['+33'] : '🇫🇷';
				foreach ( $phone_countries as $country_code => $flag ) {
					$country_options .= '<option value="' . esc_attr( $country_code ) . '" data-flag="' . esc_attr( $flag ) . '"' . selected( '+33', $country_code, false ) . '>' . esc_html( $flag . ' ' . $country_code ) . '</option>';
				}

				$input  = '<div class="leadealer-form__phone">';
				$input .= '<span class="leadealer-form__phone-country-wrap"><span class="leadealer-form__phone-country-flag" aria-hidden="true">' . esc_html( $selected_flag ) . '</span>';
				$input .= '<select class="leadealer-form__phone-country" name="' . esc_attr( $id . '__country' ) . '" aria-label="' . esc_attr__( 'Country calling code', 'leadealer' ) . '" autocomplete="tel-country-code"' . $disabled_a . '>';
				$input .= $country_options . '</select></span>';
				$input .= '<input class="leadealer-form__phone-number" id="' . esc_attr( $input_id ) . '" type="tel" name="' . esc_attr( $id ) . '" maxlength="40" inputmode="tel" autocomplete="tel-national" value="' . esc_attr( $default ) . '"' . $placeholder . $required_a . $describedby . $disabled_a . '>';
				$input .= '</div>';
				break;

			case 'file':
				$upload_url = rest_url( 'leadealer/v1/upload/' . absint( $form_id ) );
				$hint       = sprintf(
					/* translators: %s: Maximum upload size, e.g. "10 MB". */
					__( 'JPEG, PNG, WEBP, or HEIC. Maximum %s.', 'leadealer' ),
					size_format( Leadealer_Upload_Repository::max_upload_bytes() )
				);
				$input  = '<div class="leadealer-form__upload" data-leadealer-upload-field data-leadealer-upload-url="' . esc_url( $upload_url ) . '" data-leadealer-upload-field-id="' . esc_attr( $id ) . '">';
				$input .= '<input class="leadealer-form__upload-input" id="' . esc_attr( $input_id ) . '" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif"' . $describedby . $disabled_a . '>';
				// The visible file input never carries `required`: browsers block submission
				// on an empty `required` file input even though the token travels via the
				// hidden field below, which sanitize_submission() already validates server-side.
				$input      .= '<input type="hidden" name="' . esc_attr( $id ) . '" value="' . esc_attr( $default ) . '" class="leadealer-form__upload-token">';
				$input      .= '<button type="button" class="leadealer-form__upload-remove" hidden>' . esc_html__( 'Remove', 'leadealer' ) . '</button>';
				$input      .= '<p class="leadealer-form__upload-status" role="status" aria-live="polite"></p>';
				$input      .= '</div>';
				$description = '' !== $description ? $description : '<p class="leadealer-form__help">' . esc_html( $hint ) . '</p>';
				break;

			case 'number':
			case 'url':
			case 'email':
			case 'text':
			default:
				$type  = in_array( $field['type'], array( 'number', 'url', 'email', 'text' ), true ) ? $field['type'] : 'text';
				$input = '<input id="' . esc_attr( $input_id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $id ) . '" maxlength="500" value="' . esc_attr( $default ) . '"' . $placeholder . $required_a . $autocomplete . $describedby . $disabled_a . '>';
				break;
		}

		return '<div class="' . esc_attr( $classes ) . '"' . $wrapper_a . '>' . $label . $input . $description . '</div>';
	}


	/**
	 * Render the short privacy notice automatically attached to consent fields.
	 *
	 * The link follows the Privacy Policy page configured in WordPress Settings >
	 * Privacy. When no published policy page is configured, the purpose notice is
	 * still displayed without inventing a URL.
	 *
	 * @param string $notice_id DOM ID used by aria-describedby.
	 * @return string
	 */
	private function render_consent_privacy_notice( $notice_id ) {
		$privacy_url = get_privacy_policy_url();

		if ( '' === $privacy_url ) {
			return '<p class="leadealer-form__consent-legal" id="' . esc_attr( $notice_id ) . '">' . esc_html__( 'Your data is used only to handle your request.', 'leadealer' ) . '</p>';
		}

		$privacy_link = '<a href="' . esc_url( $privacy_url ) . '" rel="privacy-policy">' . esc_html__( 'Privacy Policy', 'leadealer' ) . '</a>';
		$notice       = sprintf(
			/* translators: %s: linked Privacy Policy label. */
			__( 'Your data is used only to handle your request. Your rights are explained in our %s.', 'leadealer' ),
			$privacy_link
		);

		return '<p class="leadealer-form__consent-legal" id="' . esc_attr( $notice_id ) . '">' . wp_kses(
			$notice,
			array(
				'a' => array(
					'href' => true,
					'rel'  => true,
				),
			)
		) . '</p>';
	}
}

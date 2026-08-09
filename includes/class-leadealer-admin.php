<?php
/**
 * Admin builder and entry views.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin integration.
 */
final class Leadealer_Admin {

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
	 * Built-in templates.
	 *
	 * @var Leadealer_Templates
	 */
	private $templates;


	/**
	 * HTML email template renderer.
	 *
	 * @var Leadealer_Mail_Template
	 */
	private $mail_template;

	/**
	 * Upload repository.
	 *
	 * @var Leadealer_Upload_Repository
	 */
	private $uploads;

	/**
	 * Constructor.
	 *
	 * @param Leadealer_Form_Repository   $forms         Form repository.
	 * @param Leadealer_Entry_Repository  $entries       Entry repository.
	 * @param Leadealer_Templates         $templates     Built-in templates.
	 * @param Leadealer_Mail_Template     $mail_template HTML email template renderer.
	 * @param Leadealer_Upload_Repository $uploads       Upload repository.
	 */
	public function __construct( $forms, $entries, $templates, $mail_template, $uploads ) {
		$this->forms         = $forms;
		$this->entries       = $entries;
		$this->templates     = $templates;
		$this->mail_template = $mail_template;
		$this->uploads       = $uploads;
	}

	/**
	 * Register top-level and submenu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Leadealer', 'leadealer' ),
			__( 'Forms', 'leadealer' ),
			'manage_options',
			'leadealer',
			array( $this, 'redirect_to_forms' ),
			'dashicons-feedback',
			58
		);

		$dead_count = $this->entries->get_dead_count();
		$vault_menu = __( 'Lead Vault', 'leadealer' );
		if ( $dead_count ) {
			$vault_menu .= ' <span class="update-plugins count-' . absint( $dead_count ) . '"><span class="plugin-count">' . esc_html( number_format_i18n( $dead_count ) ) . '</span></span>';
		}

		add_submenu_page( 'leadealer', __( 'Lead Vault', 'leadealer' ), $vault_menu, 'manage_options', 'leadealer-vault', array( $this, 'render_entries_page' ) );
		add_submenu_page( 'leadealer', __( 'Settings', 'leadealer' ), __( 'Settings', 'leadealer' ), 'manage_options', 'leadealer-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Redirect top-level placeholder to forms list.
	 *
	 * @return void
	 */
	public function redirect_to_forms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'leadealer' ) );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=leadealer_form' ) );
		exit;
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'leadealer_settings',
			'leadealer_pow_difficulty',
			array(
				'type'              => 'integer',
				'default'           => 17,
				'sanitize_callback' => function ( $value ) {
					return max( 12, min( 22, absint( $value ) ) );
				},
			)
		);

		register_setting(
			'leadealer_settings',
			'leadealer_retention_days',
			array(
				'type'              => 'integer',
				'default'           => 90,
				'sanitize_callback' => function ( $value ) {
					return min( 3650, absint( $value ) );
				},
			)
		);

		register_setting(
			'leadealer_settings',
			'leadealer_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => function ( $value ) {
					return ! empty( $value ) ? 1 : 0;
				},
			)
		);
	}

	/**
	 * Register form edit meta boxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box( 'leadealer-builder', __( 'Form Builder', 'leadealer' ), array( $this, 'render_builder_meta_box' ), 'leadealer_form', 'normal', 'high' );
		add_meta_box( 'leadealer-mail', __( 'Notification', 'leadealer' ), array( $this, 'render_mail_meta_box' ), 'leadealer_form', 'normal', 'default' );
		add_meta_box( 'leadealer-embed', __( 'Embed', 'leadealer' ), array( $this, 'render_embed_meta_box' ), 'leadealer_form', 'side', 'high' );
	}

	/**
	 * Enqueue admin builder assets on form screens.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		if ( in_array( $hook_suffix, array( 'leadealer_page_leadealer-vault', 'leadealer_page_leadealer-settings' ), true ) ) {
			wp_enqueue_style( 'leadealer-admin', LEADEALER_URL . 'assets/css/admin.css', array(), LEADEALER_VERSION );
			if ( 'leadealer_page_leadealer-settings' === $hook_suffix ) {
				$this->enqueue_notice_script();
			}
			return;
		}

		if ( 'leadealer_form' === $screen->post_type && 'edit.php' === $hook_suffix ) {
			$this->enqueue_notice_script();
			return;
		}

		if ( 'leadealer_form' !== $screen->post_type || ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'leadealer-admin', LEADEALER_URL . 'assets/css/admin.css', array(), LEADEALER_VERSION );
		wp_enqueue_script( 'leadealer-admin', LEADEALER_URL . 'assets/js/admin.js', array(), LEADEALER_VERSION, true );
		$this->enqueue_notice_script();
		wp_localize_script(
			'leadealer-admin',
			'LeadealerAdmin',
			array(
				'labels'               => array(
					'text'     => __( 'Text', 'leadealer' ),
					'email'    => __( 'Email', 'leadealer' ),
					'tel'      => __( 'Phone', 'leadealer' ),
					'textarea' => __( 'Long text', 'leadealer' ),
					'select'   => __( 'Dropdown', 'leadealer' ),
					'radio'    => __( 'Radio buttons', 'leadealer' ),
					'checkbox' => __( 'Checkbox', 'leadealer' ),
					'consent'  => __( 'Consent', 'leadealer' ),
					'number'   => __( 'Number', 'leadealer' ),
					'url'      => __( 'URL', 'leadealer' ),
					'file'     => __( 'Photo upload', 'leadealer' ),
					'field'    => __( 'Field', 'leadealer' ),
				),
				'fieldLabel'           => __( 'Label', 'leadealer' ),
				'description'          => __( 'Help text', 'leadealer' ),
				'fieldId'              => __( 'Field ID', 'leadealer' ),
				'placeholder'          => __( 'Placeholder', 'leadealer' ),
				'defaultValue'         => __( 'Default value', 'leadealer' ),
				'autocomplete'         => __( 'Autocomplete', 'leadealer' ),
				'appearance'           => __( 'Appearance', 'leadealer' ),
				'appearanceStandard'   => __( 'Standard field', 'leadealer' ),
				'appearanceMessage'    => __( 'Message bubble', 'leadealer' ),
				'messageAppearanceTip' => __( 'A conversational style inspired by messaging apps. The field remains a native accessible textarea.', 'leadealer' ),
				'required'             => __( 'Required field', 'leadealer' ),
				'options'              => __( 'Choices, one per line', 'leadealer' ),
				'remove'               => __( 'Delete', 'leadealer' ),
				'duplicate'            => __( 'Duplicate', 'leadealer' ),
				'emptyTitle'           => __( 'Start your form', 'leadealer' ),
				'emptyText'            => __( 'Drag a field here or choose one on the left.', 'leadealer' ),
				'fieldSettings'        => __( 'Field settings', 'leadealer' ),
				'formSettings'         => __( 'Form settings', 'leadealer' ),
				'layout'               => __( 'Width', 'leadealer' ),
				'desktop'              => __( 'Desktop', 'leadealer' ),
				'tablet'               => __( 'Tablet', 'leadealer' ),
				'mobile'               => __( 'Mobile', 'leadealer' ),
				'advanced'             => __( 'Advanced', 'leadealer' ),
				'defaultOptionOne'     => __( 'Option 1', 'leadealer' ),
				'defaultOptionTwo'     => __( 'Option 2', 'leadealer' ),
				'conditionalDisplay'   => __( 'Conditional display', 'leadealer' ),
				'showConditionally'    => __( 'Show this field conditionally', 'leadealer' ),
				'showWhen'             => __( 'Show when', 'leadealer' ),
				'allRules'             => __( 'All rules match', 'leadealer' ),
				'anyRule'              => __( 'Any rule matches', 'leadealer' ),
				'addRule'              => __( 'Add rule', 'leadealer' ),
				'removeRule'           => __( 'Remove rule', 'leadealer' ),
				'operatorEquals'       => __( 'is equal to', 'leadealer' ),
				'operatorNotEquals'    => __( 'is not equal to', 'leadealer' ),
				'operatorEmpty'        => __( 'is empty', 'leadealer' ),
				'operatorNotEmpty'     => __( 'is not empty', 'leadealer' ),
				'operatorContains'     => __( 'contains', 'leadealer' ),
				'operatorNotContains'  => __( 'does not contain', 'leadealer' ),
				'checked'              => __( 'Checked', 'leadealer' ),
				'notChecked'           => __( 'Not checked', 'leadealer' ),
				'noValueNeeded'        => __( 'No value needed', 'leadealer' ),
				'noSourceFields'       => __( 'Add another field first', 'leadealer' ),
				'addSourceFirst'       => __( 'Add another field before creating a condition.', 'leadealer' ),
				'unavailableChoice'    => __( 'Unavailable choice', 'leadealer' ),
				'missingField'         => __( 'Missing field', 'leadealer' ),
				'stableChoices'        => __( 'Internal values stay stable when you rename a choice.', 'leadealer' ),
				'mobileTip'            => __( 'Mobile defaults to one column. Change it only when the fields stay comfortable to use.', 'leadealer' ),
				'choose'               => __( 'Choose…', 'leadealer' ),
				'dragToMove'           => __( 'Drag to move. Use the arrow keys to reorder.', 'leadealer' ),
				'dropHere'             => __( 'Drop field here', 'leadealer' ),
				'close'                => __( 'Close', 'leadealer' ),
				'copySuffix'           => __( 'copy', 'leadealer' ),
				'autocompleteExample'  => __( 'name, email, organization…', 'leadealer' ),
				'consentDefaultLabel'  => __( 'I agree that my data may be used to respond to my request.', 'leadealer' ),
				'consentPrivacyNote'   => get_privacy_policy_url()
					? __( 'A short privacy notice and a link to the WordPress Privacy Policy page are added automatically on the front end.', 'leadealer' )
					: __( 'No WordPress Privacy Policy page is configured. The short notice will be shown without a link until you configure one in Settings > Privacy.', 'leadealer' ),
				'templateConfirm'      => __( 'Replace the current fields with this template? You can undo this action.', 'leadealer' ),
				'templates'            => $this->templates->get_all( $this->forms ),
			)
		);
	}

	/**
	 * Render builder meta box.
	 *
	 * @param WP_Post $post Form post.
	 * @return void
	 */
	public function render_builder_meta_box( $post ) {
		wp_nonce_field( 'leadealer_save_form', 'leadealer_nonce' );
		$schema      = $this->forms->normalize_schema( get_post_meta( $post->ID, '_leadealer_form_schema', true ) );
		$raw         = get_post_meta( $post->ID, '_leadealer_form_settings', true );
		$raw         = is_array( $raw ) ? $raw : array();
		$settings    = $this->forms->normalize_settings( $raw );
		$description = get_post_meta( $post->ID, '_leadealer_form_description', true );
		$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $schema_json ) ) {
			$schema_json = '[]';
		}

		$field_types = array(
			'text'     => array( __( 'Text', 'leadealer' ), 'editor-textcolor' ),
			'email'    => array( __( 'Email', 'leadealer' ), 'email' ),
			'tel'      => array( __( 'Phone', 'leadealer' ), 'phone' ),
			'textarea' => array( __( 'Long text', 'leadealer' ), 'editor-alignleft' ),
			'number'   => array( __( 'Number', 'leadealer' ), 'editor-ol' ),
			'url'      => array( __( 'URL', 'leadealer' ), 'admin-links' ),
			'select'   => array( __( 'Dropdown', 'leadealer' ), 'arrow-down-alt2' ),
			'radio'    => array( __( 'Radio buttons', 'leadealer' ), 'marker' ),
			'checkbox' => array( __( 'Checkbox', 'leadealer' ), 'yes-alt' ),
			'consent'  => array( __( 'Consent', 'leadealer' ), 'privacy' ),
			'file'     => array( __( 'Photo upload', 'leadealer' ), 'camera' ),
		);
		?>
		<div class="leadealer-builder" data-schema="<?php echo esc_attr( $schema_json ); ?>">
			<div class="leadealer-builder__topbar">
				<div>
					<strong><?php esc_html_e( 'Visual form builder', 'leadealer' ); ?></strong>
					<span><?php esc_html_e( 'Build the layout directly. No column shortcode is needed.', 'leadealer' ); ?></span>
				</div>
				<div class="leadealer-builder__devices" role="group" aria-label="<?php esc_attr_e( 'Preview size', 'leadealer' ); ?>">
					<button type="button" class="leadealer-builder__device is-active" data-device="desktop"><span class="dashicons dashicons-desktop"></span><span><?php esc_html_e( 'Desktop', 'leadealer' ); ?></span></button>
					<button type="button" class="leadealer-builder__device" data-device="tablet"><span class="dashicons dashicons-tablet"></span><span><?php esc_html_e( 'Tablet', 'leadealer' ); ?></span></button>
					<button type="button" class="leadealer-builder__device" data-device="mobile"><span class="dashicons dashicons-smartphone"></span><span><?php esc_html_e( 'Mobile', 'leadealer' ); ?></span></button>
				</div>
				<div class="leadealer-builder__history">
					<button type="button" class="button" data-builder-action="undo" disabled aria-label="<?php esc_attr_e( 'Undo', 'leadealer' ); ?>">↶</button>
					<button type="button" class="button" data-builder-action="redo" disabled aria-label="<?php esc_attr_e( 'Redo', 'leadealer' ); ?>">↷</button>
				</div>
			</div>

			<div class="leadealer-builder__workspace">
				<aside class="leadealer-builder__palette">
					<h3><?php esc_html_e( 'Templates', 'leadealer' ); ?></h3>
					<p><?php esc_html_e( 'Start with a ready-made form', 'leadealer' ); ?></p>
					<div class="leadealer-builder__templates">
						<?php foreach ( $this->templates->get_all( $this->forms ) as $template ) : ?>
							<button type="button" class="leadealer-builder__template" data-template="<?php echo esc_attr( $template['id'] ); ?>"><span class="dashicons dashicons-index-card"></span><span><strong><?php echo esc_html( $template['name'] ); ?></strong><small><?php echo esc_html( $template['description'] ); ?></small></span></button>
						<?php endforeach; ?>
					</div>
					<div class="leadealer-builder__palette-separator"></div>
					<h3><?php esc_html_e( 'Fields', 'leadealer' ); ?></h3>
					<p><?php esc_html_e( 'Click or drag to add', 'leadealer' ); ?></p>
					<div class="leadealer-builder__field-types">
						<?php foreach ( $field_types as $type => $field_type ) : ?>
							<button type="button" class="leadealer-builder__add" data-type="<?php echo esc_attr( $type ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $field_type[1] ); ?>"></span><span><?php echo esc_html( $field_type[0] ); ?></span></button>
						<?php endforeach; ?>
					</div>
				</aside>

				<div class="leadealer-builder__stage">
					<div class="leadealer-builder__viewport" data-device="desktop">
						<div class="leadealer-builder__canvas" aria-live="polite"></div>
					</div>
				</div>

				<aside class="leadealer-builder__inspector">
					<div class="leadealer-builder__form-settings">
						<h3><?php esc_html_e( 'Form settings', 'leadealer' ); ?></h3>
						<label class="leadealer-control"><span><?php esc_html_e( 'Description', 'leadealer' ); ?></span><textarea id="leadealer-form-description" name="leadealer_form_description" rows="4" maxlength="2000"><?php echo esc_textarea( $description ); ?></textarea></label>
						<label class="leadealer-toggle"><input type="checkbox" name="leadealer_form_show_header" value="1" <?php checked( $settings['show_header'] ); ?>><span><?php esc_html_e( 'Show title and description above the form', 'leadealer' ); ?></span></label>
						<label class="leadealer-control"><span><?php esc_html_e( 'Submit button', 'leadealer' ); ?></span><input type="text" name="leadealer_form_submit_label" value="<?php echo esc_attr( isset( $raw['submit_label'] ) ? $raw['submit_label'] : '' ); ?>" placeholder="<?php echo esc_attr( $settings['submit_label'] ); ?>" maxlength="120"></label>
						<p class="leadealer-builder__tip"><?php esc_html_e( 'Select a field in the preview to edit it. Drag fields to reorder them.', 'leadealer' ); ?></p>
					</div>
					<div class="leadealer-builder__field-settings" hidden></div>
				</aside>
			</div>

			<textarea class="leadealer-builder__schema" name="leadealer_form_schema" hidden><?php echo esc_textarea( $schema_json ); ?></textarea>
		</div>
		<p class="description"><?php esc_html_e( 'The front end is rendered server-side and remains safe with full-page caches. Layout is stored with the form and becomes one column automatically on small screens unless you choose otherwise.', 'leadealer' ); ?></p>
		<?php
	}


	/**
	 * Render notification settings and the exact saved-template preview.
	 *
	 * @param WP_Post $post Form post.
	 * @return void
	 */
	public function render_mail_meta_box( $post ) {
		$raw      = get_post_meta( $post->ID, '_leadealer_form_settings', true );
		$raw      = is_array( $raw ) ? $raw : array();
		$settings = $this->forms->normalize_settings( $raw );
		$schema   = $this->forms->normalize_schema( get_post_meta( $post->ID, '_leadealer_form_schema', true ) );
		$title    = get_the_title( $post );
		$form     = array(
			'id'          => (int) $post->ID,
			'title'       => '' !== trim( (string) $title ) ? $title : __( 'Form title', 'leadealer' ),
			'description' => '',
			'fields'      => $schema,
			'settings'    => $settings,
		);
		$preview  = $this->mail_template->build_preview( $form );
		?>
		<div class="leadealer-mail-settings">
			<div class="leadealer-mail-settings__grid">
				<label class="leadealer-mail-setting" for="leadealer-form-recipient">
					<span class="leadealer-mail-setting__label"><?php esc_html_e( 'Recipient', 'leadealer' ); ?></span>
					<input class="regular-text" id="leadealer-form-recipient" type="email" name="leadealer_form_recipient" value="<?php echo esc_attr( $settings['recipient'] ); ?>" required>
				</label>
				<label class="leadealer-mail-setting" for="leadealer-form-subject">
					<span class="leadealer-mail-setting__label"><?php esc_html_e( 'Subject', 'leadealer' ); ?></span>
					<input class="regular-text" id="leadealer-form-subject" type="text" name="leadealer_form_subject" value="<?php echo esc_attr( isset( $raw['subject'] ) ? $raw['subject'] : '' ); ?>" placeholder="<?php echo esc_attr( $settings['subject'] ); ?>">
					<span class="description"><?php esc_html_e( 'Supports {{form:title}} and {{field:field_id}} tokens. Leave blank to use the default text in each visitor\'s language.', 'leadealer' ); ?></span>
				</label>
				<label class="leadealer-mail-setting" for="leadealer-form-success">
					<span class="leadealer-mail-setting__label"><?php esc_html_e( 'Success message', 'leadealer' ); ?></span>
					<input class="regular-text" id="leadealer-form-success" type="text" name="leadealer_form_success_message" value="<?php echo esc_attr( isset( $raw['success_message'] ) ? $raw['success_message'] : '' ); ?>" placeholder="<?php echo esc_attr( $settings['success_message'] ); ?>">
				</label>
			</div>

			<div class="leadealer-mail-reliability">
				<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Lead Vault protects the submission before email delivery.', 'leadealer' ); ?></strong>
					<p><?php esc_html_e( 'The message below is frozen when the lead is received. SMTP or mail failures are retried without losing the submitted data.', 'leadealer' ); ?></p>
				</div>
			</div>

			<label class="leadealer-mail-storage"><input type="checkbox" name="leadealer_form_save_entries" value="1" <?php checked( $settings['save_entries'] ); ?>> <?php esc_html_e( 'Keep the complete lead and email backup in Lead Vault after successful mail handoff', 'leadealer' ); ?></label>
			<p class="description"><?php esc_html_e( 'Recommended for delivery protection: the Vault remains a readable copy of the notification and its photos even if the inbox message is lost or filtered.', 'leadealer' ); ?></p>

			<details class="leadealer-mail-preview">
				<summary><?php esc_html_e( 'Preview automatic email', 'leadealer' ); ?></summary>
				<p class="description"><?php esc_html_e( 'This preview uses the last saved form schema. Fields follow the builder order; conditionally hidden fields are omitted from real notifications.', 'leadealer' ); ?></p>
				<iframe class="leadealer-mail-preview__frame" title="<?php esc_attr_e( 'Email notification preview', 'leadealer' ); ?>" sandbox srcdoc="<?php echo esc_attr( $preview ); ?>"></iframe>
			</details>
		</div>
		<?php
	}

	/**
	 * Render shortcode help.
	 *
	 * @param WP_Post $post Form post.
	 * @return void
	 */
	public function render_embed_meta_box( $post ) {
		if ( ! $post->ID ) {
			return;
		}
		?>
		<p><strong><?php esc_html_e( 'Shortcode', 'leadealer' ); ?></strong></p>
		<p><code>[leadealer_form id="<?php echo esc_attr( (string) $post->ID ); ?>"]</code></p>
		<p class="description"><?php esc_html_e( 'The form uses the responsive layout configured in the builder.', 'leadealer' ); ?></p>
		<p><?php esc_html_e( 'You can also insert the “Leadealer Form” block in the block editor.', 'leadealer' ); ?></p>
		<?php
	}

	/**
	 * Persist form schema and settings.
	 *
	 * @param int     $post_id Form post ID.
	 * @param WP_Post $post    Form post.
	 * @return void
	 */
	public function save_form( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['leadealer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['leadealer_nonce'] ) ), 'leadealer_save_form' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || 'leadealer_form' !== $post->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded first; every supported field is normalized immediately below.
		$raw_schema  = isset( $_POST['leadealer_form_schema'] ) ? wp_unslash( $_POST['leadealer_form_schema'] ) : '[]';
		$raw_schema  = is_string( $raw_schema ) && strlen( $raw_schema ) <= 262144 ? $raw_schema : '[]';
		$description = isset( $_POST['leadealer_form_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['leadealer_form_description'] ) ) : '';
		$description = wp_html_excerpt( $description, 2000, '' );
		$schema      = json_decode( $raw_schema, true );
		$schema      = $this->forms->normalize_schema( $schema );

		/*
		 * Store the sanitized input as submitted, without routing it through
		 * normalize_settings(). That method fills blank fields with a
		 * translated default (e.g. __( 'Send', 'leadealer' )); resolving
		 * it here would freeze that string, in whichever language wp-admin
		 * happened to be in at save time, into post meta forever. Leaving
		 * blank fields blank lets normalize_settings() resolve the default in
		 * each visitor's own language every time the form is rendered.
		 */
		$settings = array(
			'recipient'       => isset( $_POST['leadealer_form_recipient'] ) ? sanitize_email( wp_unslash( $_POST['leadealer_form_recipient'] ) ) : '',
			'subject'         => isset( $_POST['leadealer_form_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['leadealer_form_subject'] ) ) : '',
			'success_message' => isset( $_POST['leadealer_form_success_message'] ) ? sanitize_text_field( wp_unslash( $_POST['leadealer_form_success_message'] ) ) : '',
			'submit_label'    => isset( $_POST['leadealer_form_submit_label'] ) ? sanitize_text_field( wp_unslash( $_POST['leadealer_form_submit_label'] ) ) : '',
			'show_header'     => isset( $_POST['leadealer_form_show_header'] ),
			'save_entries'    => isset( $_POST['leadealer_form_save_entries'] ),
		);

		update_post_meta( $post_id, '_leadealer_form_description', $description );
		update_post_meta( $post_id, '_leadealer_form_schema', $schema );
		update_post_meta( $post_id, '_leadealer_form_settings', $settings );

		$this->purge_form_render_caches( $post_id );
	}

	/**
	 * Purge caches that may contain server-rendered shortcode HTML.
	 *
	 * A form can be embedded on any page, so the plugin cannot reliably map a
	 * form post ID back to every cached URL. Form edits are rare enough that a
	 * page-cache purge is the safest way to avoid serving a stale schema.
	 *
	 * @param int $post_id Saved form ID.
	 * @return void
	 */
	private function purge_form_render_caches( $post_id ) {
		clean_post_cache( $post_id );

		/**
		 * Fires after Leadealer saves a form and before optional cache purges.
		 *
		 * @param int $post_id Saved form ID.
		 */
		do_action( 'leadealer_form_saved', $post_id );

		if ( apply_filters( 'leadealer_purge_page_cache_on_form_save', true, $post_id ) ) {
			// LiteSpeed Cache for WordPress public API. No listener means no-op.
			do_action( 'litespeed_purge_all' );

			// WP Rocket public API.
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
		}
	}

	/**
	 * Customize forms list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function form_columns( $columns ) {
		$columns['leadealer_shortcode'] = __( 'Shortcode', 'leadealer' );

		return $columns;
	}

	/**
	 * Render custom form list column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Form ID.
	 * @return void
	 */
	public function form_column_content( $column, $post_id ) {
		if ( 'leadealer_shortcode' === $column ) {
			echo '<code>[leadealer_form id="' . esc_attr( (string) absint( $post_id ) ) . '"]</code>';
		}
	}



	/**
	 * Surface a restrained delivery notice only on Leadealer screens.
	 *
	 * Dismissal is remembered per user until a newer dead-letter event appears.
	 * The Lead Vault menu badge remains the passive source of truth.
	 *
	 * @return void
	 */
	public function render_delivery_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'leadealer_page_leadealer-vault' === $screen->id ) {
			return;
		}

		$is_form_screen = 'leadealer_form' === $screen->post_type;
		$is_plugin_page = in_array( $screen->id, array( 'leadealer_page_leadealer-settings' ), true );
		if ( ! $is_form_screen && ! $is_plugin_page ) {
			return;
		}

		$dead_count = $this->entries->get_dead_count();
		$latest_id  = $this->entries->get_latest_attention_event_id();
		$dismissed  = absint( get_user_meta( get_current_user_id(), 'leadealer_dismissed_delivery_notice', true ) );

		if ( ! $dead_count || ! $latest_id || $latest_id <= $dismissed ) {
			return;
		}

		$url        = add_query_arg(
			array(
				'page'     => 'leadealer-vault',
				'delivery' => 'dead',
			),
			admin_url( 'admin.php' )
		);
		$count_text = sprintf(
			/* translators: %d: Number of notifications whose automatic delivery exhausted all attempts. */
			_n( '%d notification could not be handed off. The lead is safe in Lead Vault.', '%d notifications could not be handed off. The leads are safe in Lead Vault.', absint( $dead_count ), 'leadealer' ),
			absint( $dead_count )
		);
		$message = '<strong>' . esc_html__( 'Email delivery needs attention.', 'leadealer' ) . '</strong> '
			. esc_html( $count_text ) . ' '
			. '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review delivery', 'leadealer' ) . '</a>';

		wp_admin_notice(
			$message,
			array(
				'type'               => 'error',
				'dismissible'        => true,
				'id'                 => 'leadealer-delivery-notice',
				'additional_classes' => array( 'leadealer-delivery-notice' ),
				'attributes'         => array( 'data-dead-id' => (string) $latest_id ),
			)
		);
	}

	/**
	 * Remember a user's dismissal until a newer dead-letter lead exists.
	 *
	 * @return void
	 */
	public function dismiss_delivery_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to dismiss this notice.', 'leadealer' ) ), 403 );
		}

		check_ajax_referer( 'leadealer_dismiss_delivery_notice' );
		$requested = isset( $_POST['dead_id'] ) ? absint( wp_unslash( $_POST['dead_id'] ) ) : 0;
		$latest    = $this->entries->get_latest_attention_event_id();
		$dead_id   = min( $requested, $latest );

		if ( $dead_id ) {
			update_user_meta( get_current_user_id(), 'leadealer_dismissed_delivery_notice', $dead_id );
		}

		wp_send_json_success();
	}

	/**
	 * Enqueue the tiny core-notice dismissal helper on Leadealer screens.
	 *
	 * @return void
	 */
	private function enqueue_notice_script() {
		wp_enqueue_script( 'leadealer-admin-notices', LEADEALER_URL . 'assets/js/admin-notices.js', array(), LEADEALER_VERSION, true );
		wp_localize_script(
			'leadealer-admin-notices',
			'LeadealerNotices',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'leadealer_dismiss_delivery_notice' ),
			)
		);
	}

	/**
	 * Render entries page.
	 *
	 * @return void
	 */
	public function render_entries_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'leadealer' ) );
		}

		$view_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$filter  = isset( $_GET['delivery'] ) ? sanitize_key( wp_unslash( $_GET['delivery'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering.
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filtering.
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin pagination.
		?>
		<div class="wrap leadealer-vault">
			<h1><?php esc_html_e( 'Lead Vault', 'leadealer' ); ?></h1>
			<?php if ( $view_id ) : ?>
				<?php $this->render_entry_detail( $view_id ); ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'A lead appears here as soon as it passes Proof of Work and server-side validation. Email delivery is tracked separately and can recover without losing the submission.', 'leadealer' ); ?></p>
				<?php $this->render_vault_summary(); ?>
				<?php $this->render_vault_filters( $filter, $form_id ); ?>
				<?php $this->render_entries_table( $filter, $form_id, $paged ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Lead Vault reliability counters.
	 *
	 * @return void
	 */
	private function render_vault_summary() {
		$counts = $this->entries->get_delivery_counts();
		$cards  = array(
			array( __( 'Leads secured', 'leadealer' ), $counts['total'], __( 'Stored before mail delivery', 'leadealer' ) ),
			array( __( 'Mail handed off', 'leadealer' ), $counts['handed_off'], __( 'Accepted by wp_mail()', 'leadealer' ) ),
			array( __( 'Recovering', 'leadealer' ), $counts['recovering'], __( 'Queued or waiting for retry', 'leadealer' ) ),
			array( __( 'Needs attention', 'leadealer' ), $counts['dead'], __( 'Dead-letter leads', 'leadealer' ) ),
			array( __( 'Recovered', 'leadealer' ), $counts['recovered'], __( 'Succeeded after more than one attempt', 'leadealer' ) ),
		);
		?>
		<div class="leadealer-vault__cards">
			<?php foreach ( $cards as $card ) : ?>
				<div class="leadealer-vault__card"><strong><?php echo esc_html( (string) absint( $card[1] ) ); ?></strong><span><?php echo esc_html( $card[0] ); ?></span><small><?php echo esc_html( $card[2] ); ?></small></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render delivery filters plus a per-form filter.
	 *
	 * @param string $active  Active delivery filter.
	 * @param int    $form_id Active form filter, or 0 for every form.
	 * @return void
	 */
	private function render_vault_filters( $active, $form_id = 0 ) {
		$filters = array(
			''           => __( 'All leads', 'leadealer' ),
			'recovering' => __( 'Recovering', 'leadealer' ),
			'issues'     => __( 'Delivery issues', 'leadealer' ),
			'dead'       => __( 'Dead letter', 'leadealer' ),
			'handed_off' => __( 'Mail handed off', 'leadealer' ),
		);
		?>
		<nav class="nav-tab-wrapper leadealer-vault__tabs" aria-label="<?php esc_attr_e( 'Lead Vault filters', 'leadealer' ); ?>">
			<?php foreach ( $filters as $key => $label ) : ?>
				<?php
				$url = add_query_arg( 'page', 'leadealer-vault', admin_url( 'admin.php' ) );
				if ( '' !== $key ) {
					$url = add_query_arg( 'delivery', $key, $url );
				}
				if ( $form_id ) {
					$url = add_query_arg( 'form_id', $form_id, $url );
				}
				$class = $key === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
				?>
				<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="leadealer-vault__form-filter">
			<input type="hidden" name="page" value="leadealer-vault">
			<?php if ( '' !== $active ) : ?>
				<input type="hidden" name="delivery" value="<?php echo esc_attr( $active ); ?>">
			<?php endif; ?>
			<label for="leadealer-vault-form-filter"><?php esc_html_e( 'Form', 'leadealer' ); ?></label>
			<select id="leadealer-vault-form-filter" name="form_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e( 'All forms', 'leadealer' ); ?></option>
				<?php foreach ( $this->forms->get_all_for_select() as $form ) : ?>
					<option value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php selected( $form_id, $form['id'] ); ?>><?php echo esc_html( $form['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'leadealer' ); ?></button></noscript>
		</form>
		<?php
	}

	/**
	 * Render latest Lead Vault table, one page at a time so every lead stays
	 * reachable regardless of how many accumulate.
	 *
	 * @param string $filter  Delivery filter.
	 * @param int    $form_id Restrict to one form, or 0 for every form.
	 * @param int    $paged   1-indexed page number.
	 * @return void
	 */
	private function render_entries_table( $filter = '', $form_id = 0, $paged = 1 ) {
		$per_page  = 50;
		$paged     = max( 1, absint( $paged ) );
		$total     = $this->entries->count_latest( $filter, $form_id );
		$entries   = $this->entries->get_latest( $per_page, $filter, ( $paged - 1 ) * $per_page, $form_id );
		$last_page = max( 1, (int) ceil( $total / $per_page ) );
		$entry_ids = array();

		foreach ( $entries as $entry ) {
			$entry_ids[] = absint( $entry->id );
		}
		$attachment_counts = $this->uploads->count_by_entries( $entry_ids );
		?>
		<table class="widefat striped leadealer-vault__table leadealer-vault__mailbox">
			<thead><tr><th><?php esc_html_e( 'Received', 'leadealer' ); ?></th><th><?php esc_html_e( 'Message', 'leadealer' ); ?></th><th><?php esc_html_e( 'Recipient', 'leadealer' ); ?></th><th><?php esc_html_e( 'Attachments', 'leadealer' ); ?></th><th><?php esc_html_e( 'Mail delivery', 'leadealer' ); ?></th><th><?php esc_html_e( 'Attempts', 'leadealer' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $entries ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No leads match this view.', 'leadealer' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $entry ) : ?>
				<?php
				$entry_url = add_query_arg(
					array(
						'page'  => 'leadealer-vault',
						'entry' => absint( $entry->id ),
					),
					admin_url( 'admin.php' )
				);
				$subject             = trim( (string) $entry->mail_subject );
				$subject             = '' !== $subject ? $subject : sprintf( /* translators: %d: Lead ID. */ __( 'Lead #%d', 'leadealer' ), absint( $entry->id ) );
				$attachment_count    = isset( $attachment_counts[ absint( $entry->id ) ] ) ? absint( $attachment_counts[ absint( $entry->id ) ] ) : 0;
				$attachment_manifest = isset( $entry->attachment_manifest ) ? json_decode( (string) $entry->attachment_manifest, true ) : null;
				$expected_count      = is_array( $attachment_manifest ) ? count( $attachment_manifest ) : $attachment_count;
				?>
				<tr>
					<td><a href="<?php echo esc_url( $entry_url ); ?>">#<?php echo esc_html( (string) $entry->id ); ?></a><br><span class="leadealer-vault__muted"><?php echo esc_html( get_date_from_gmt( $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></span></td>
					<td><a class="leadealer-vault__mail-subject" href="<?php echo esc_url( $entry_url ); ?>"><?php echo esc_html( $subject ); ?></a><br><span class="leadealer-vault__muted"><?php echo esc_html( get_the_title( (int) $entry->form_id ) ); ?></span></td>
					<td><?php echo '' !== trim( (string) $entry->mail_to ) ? esc_html( $entry->mail_to ) : '<span class="leadealer-vault__muted">&mdash;</span>'; ?></td>
					<td>
						<?php if ( $expected_count || $attachment_count ) : ?>
							<span class="dashicons dashicons-paperclip" aria-hidden="true"></span>
							<?php if ( $expected_count > $attachment_count ) : ?>
								<strong class="leadealer-vault__attachment-warning" title="<?php echo esc_attr__( 'One or more expected photos are unavailable.', 'leadealer' ); ?>"><?php echo esc_html( $attachment_count . ' / ' . $expected_count ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( (string) $attachment_count ); ?>
							<?php endif; ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><span class="leadealer-status <?php echo esc_attr( $this->delivery_status_class( $entry->mail_status ) ); ?>"><?php echo esc_html( $this->delivery_status_label( $entry->mail_status ) ); ?></span></td>
					<td><?php echo esc_html( (string) absint( $entry->mail_attempts ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $last_page > 1 ) : ?>
			<p class="leadealer-vault__pagination">
				<?php
				printf(
					/* translators: 1: Current page. 2: Total pages. 3: Total leads matching this view. */
					esc_html__( 'Page %1$d of %2$d (%3$s leads)', 'leadealer' ),
					absint( $paged ),
					absint( $last_page ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
				<?php
				$pagination_args = array(
					'page'     => 'leadealer-vault',
					'delivery' => $filter,
				);
				if ( $form_id ) {
					$pagination_args['form_id'] = $form_id;
				}
				?>
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Previous', 'leadealer' ); ?></a>
				<?php endif; ?>
				<?php if ( $paged < $last_page ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Next', 'leadealer' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render one Lead Vault entry with its delivery timeline.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private function render_entry_detail( $entry_id ) {
		$entry = $this->entries->get( $entry_id );
		if ( ! $entry ) {
			echo '<p>' . esc_html__( 'Lead not found.', 'leadealer' ) . '</p>';
			return;
		}

		$payload = json_decode( $entry->payload, true );
		$payload = is_array( $payload ) ? $payload : array();
		$events  = $this->entries->get_events( $entry_id, 200 );
		$form    = $this->forms->get( (int) $entry->form_id );
		$labels  = array();
		$types   = array();
		if ( $form ) {
			foreach ( $form['fields'] as $field ) {
				$labels[ $field['id'] ] = $field['label'];
				$types[ $field['id'] ]  = $field['type'];
			}
		}
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadealer-vault' ) ); ?>">&larr; <?php esc_html_e( 'Back to Lead Vault', 'leadealer' ); ?></a></p>
		<div class="leadealer-vault__detail-head">
			<div><h2><?php echo esc_html( sprintf( /* translators: %d: Lead ID. */ __( 'Lead #%d', 'leadealer' ), $entry->id ) ); ?></h2><p><?php echo esc_html( get_the_title( (int) $entry->form_id ) ); ?></p></div>
			<div><span class="leadealer-status leadealer-status--success"><?php esc_html_e( 'Lead secured', 'leadealer' ); ?></span> <span class="leadealer-status <?php echo esc_attr( $this->delivery_status_class( $entry->mail_status ) ); ?>"><?php echo esc_html( $this->delivery_status_label( $entry->mail_status ) ); ?></span></div>
		</div>
		<?php $this->render_sent_mail_panel( $entry ); ?>
		<div class="leadealer-vault__detail-grid">
			<div>
				<h3><?php esc_html_e( 'Submitted data', 'leadealer' ); ?></h3>
				<table class="widefat striped"><tbody>
				<?php
				if ( empty( $payload ) ) :
					?>
					<tr><td><?php esc_html_e( 'Submitted values were redacted after successful mail handoff.', 'leadealer' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $payload as $key => $value ) : ?>
					<tr>
						<th style="width:220px"><?php echo esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ); ?></th>
						<?php if ( isset( $types[ $key ] ) && 'file' === $types[ $key ] ) : ?>
							<td><?php echo wp_kses_post( $this->render_attachment_cell( $entry, $key, $value ) ); ?></td>
						<?php else : ?>
							<td><?php echo wp_kses_post( nl2br( esc_html( (string) $value ) ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				<tr><th><?php esc_html_e( 'Mail status', 'leadealer' ); ?></th><td><?php echo esc_html( $this->delivery_status_label( $entry->mail_status ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Mail attempts', 'leadealer' ); ?></th><td><?php echo esc_html( (string) absint( $entry->mail_attempts ) ); ?> / <?php echo esc_html( (string) Leadealer_Mailer::MAX_ATTEMPTS ); ?></td></tr>
				<?php
				if ( $entry->next_mail_attempt_at ) :
					?>
					<tr><th><?php esc_html_e( 'Next recovery', 'leadealer' ); ?></th><td><?php echo esc_html( get_date_from_gmt( $entry->next_mail_attempt_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr><?php endif; ?>
				<?php
				if ( $entry->mail_error ) :
					?>
					<tr><th><?php esc_html_e( 'Last mail error', 'leadealer' ); ?></th><td><?php echo esc_html( $entry->mail_error ); ?></td></tr><?php endif; ?>
				</tbody></table>
				<?php if ( 'handed_off' !== $entry->mail_status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="leadealer-vault__retry-form">
						<input type="hidden" name="action" value="leadealer_retry_mail">
						<input type="hidden" name="entry" value="<?php echo esc_attr( (string) absint( $entry->id ) ); ?>">
						<?php wp_nonce_field( 'leadealer_retry_mail' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Start a new delivery cycle', 'leadealer' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<div>
				<h3><?php esc_html_e( 'Reliability timeline', 'leadealer' ); ?></h3>
				<ol class="leadealer-timeline">
				<?php foreach ( $events as $event ) : ?>
					<li class="leadealer-timeline__item leadealer-timeline__item--<?php echo esc_attr( sanitize_html_class( $event->event_status ) ); ?>"><span class="leadealer-timeline__dot"></span><div><strong><?php echo esc_html( $event->message ); ?></strong><time><?php echo esc_html( get_date_from_gmt( $event->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></time></div></li>
				<?php endforeach; ?>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a "what was actually sent" panel showing the frozen
	 * mail_subject/mail_body snapshot, so an admin can always tell apart
	 * "nothing was ever sent", "it was sent but later redacted", and "here is
	 * exactly what went out" — rather than only ever seeing the re-derived
	 * submitted-values table.
	 *
	 * @param object $entry Lead Vault entry.
	 * @return void
	 */
	private function render_sent_mail_panel( $entry ) {
		$headers     = json_decode( (string) $entry->mail_headers, true );
		$headers     = is_array( $headers ) ? $headers : array();
		$reply_to                = '';
		$attachments             = $this->uploads->get_by_entry( (int) $entry->id );
		$raw_attachment_manifest = isset( $entry->attachment_manifest ) ? json_decode( (string) $entry->attachment_manifest, true ) : null;
		$has_attachment_manifest = is_array( $raw_attachment_manifest );
		$attachment_manifest     = $has_attachment_manifest ? $raw_attachment_manifest : array();
		$rows_by_id              = array();
		$display_attachments     = array();

		foreach ( $attachments as $row ) {
			$rows_by_id[ absint( $row->id ) ] = $row;
		}

		if ( $has_attachment_manifest ) {
			foreach ( $attachment_manifest as $expected ) {
				$expected_id = is_array( $expected ) && isset( $expected['upload_id'] ) ? absint( $expected['upload_id'] ) : 0;
				if ( $expected_id > 0 && isset( $rows_by_id[ $expected_id ] ) ) {
					$display_attachments[] = $rows_by_id[ $expected_id ];
				}
			}
		} else {
			$display_attachments = $attachments;
		}

		foreach ( $headers as $header ) {
			if ( ! is_scalar( $header ) ) {
				continue;
			}
			$header = trim( (string) $header );
			if ( 0 === stripos( $header, 'Reply-To:' ) ) {
				$candidate = sanitize_email( trim( substr( $header, strlen( 'Reply-To:' ) ) ) );
				if ( is_email( $candidate ) ) {
					$reply_to = $candidate;
				}
				break;
			}
		}
		?>
		<section class="leadealer-vault__sent-mail" aria-labelledby="leadealer-vault-mail-heading">
			<div class="leadealer-vault__sent-mail-head">
				<div>
					<h3 id="leadealer-vault-mail-heading"><?php esc_html_e( 'Email backup', 'leadealer' ); ?></h3>
					<p><?php esc_html_e( 'This is the frozen notification kept by Lead Vault independently from inbox delivery.', 'leadealer' ); ?></p>
				</div>
				<span class="leadealer-status <?php echo esc_attr( $this->delivery_status_class( $entry->mail_status ) ); ?>"><?php echo esc_html( $this->delivery_status_label( $entry->mail_status ) ); ?></span>
			</div>

			<?php if ( '' === trim( (string) $entry->mail_body ) ) : ?>
				<?php if ( 'handed_off' === $entry->mail_status ) : ?>
					<p class="description"><?php esc_html_e( 'This lead was handed off successfully, but its notification snapshot was removed because this form was configured not to retain completed leads.', 'leadealer' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No notification snapshot is available for this lead.', 'leadealer' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<div class="leadealer-vault__mail-envelope">
					<div><span><?php esc_html_e( 'To', 'leadealer' ); ?></span><strong><?php echo esc_html( $entry->mail_to ); ?></strong></div>
					<?php if ( $reply_to ) : ?><div><span><?php esc_html_e( 'Reply-To', 'leadealer' ); ?></span><strong><?php echo esc_html( $reply_to ); ?></strong></div><?php endif; ?>
					<div><span><?php esc_html_e( 'Subject', 'leadealer' ); ?></span><strong><?php echo esc_html( $entry->mail_subject ); ?></strong></div>
					<div><span><?php esc_html_e( 'Received', 'leadealer' ); ?></span><strong><?php echo esc_html( get_date_from_gmt( $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></strong></div>
				</div>

				<iframe class="leadealer-vault__sent-mail-frame" title="<?php esc_attr_e( 'Stored email notification', 'leadealer' ); ?>" sandbox srcdoc="<?php echo esc_attr( $entry->mail_body ); ?>"></iframe>

				<?php if ( ! empty( $display_attachments ) || ! empty( $attachment_manifest ) ) : ?>
					<div class="leadealer-vault__mail-attachments">
						<h4><?php esc_html_e( 'Attachments', 'leadealer' ); ?></h4>
						<div class="leadealer-vault__mail-attachment-grid">
						<?php foreach ( $display_attachments as $index => $row ) : ?>
							<?php
							$verified = $this->uploads->verify_stored_file( $row );
							if ( false === $verified ) :
								?>
								<div class="leadealer-vault__mail-attachment leadealer-vault__mail-attachment--missing">
									<span class="dashicons dashicons-warning" aria-hidden="true"></span>
									<span><strong><?php esc_html_e( 'Photo unavailable', 'leadealer' ); ?></strong><small><?php esc_html_e( 'The stored file failed its integrity check.', 'leadealer' ); ?></small></span>
								</div>
								<?php
								continue;
							endif;
							$url = add_query_arg(
								array(
									'action'   => 'leadealer_view_attachment',
									'entry'    => absint( $entry->id ),
									'field'    => rawurlencode( sanitize_key( $row->field_id ) ),
									'_wpnonce' => wp_create_nonce( 'leadealer_view_attachment_' . absint( $entry->id ) ),
								),
								admin_url( 'admin-post.php' )
							);
							$filename = sprintf( 'photo-%d.%s', $index + 1, $verified['extension'] );
							?>
							<a class="leadealer-vault__mail-attachment" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
								<img src="<?php echo esc_url( $url ); ?>" alt="">
								<span><strong><?php echo esc_html( $filename ); ?></strong><small><?php echo esc_html( size_format( $verified['byte_size'] ) ); ?></small></span>
							</a>
						<?php endforeach; ?>

						<?php foreach ( $attachment_manifest as $expected ) : ?>
							<?php
							$expected_id = is_array( $expected ) && isset( $expected['upload_id'] ) ? absint( $expected['upload_id'] ) : 0;
							if ( $expected_id <= 0 || isset( $rows_by_id[ $expected_id ] ) ) {
								continue;
							}
							?>
							<div class="leadealer-vault__mail-attachment leadealer-vault__mail-attachment--missing">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<span><strong><?php esc_html_e( 'Expected photo missing', 'leadealer' ); ?></strong><small><?php esc_html_e( 'Lead Vault recorded a photo for this email, but the stored attachment is unavailable. Delivery is blocked until the issue is resolved.', 'leadealer' ); ?></small></span>
							</div>
						<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render a thumbnail cell for a claimed photo upload.
	 *
	 * The image is never linked by a public URL: the thumbnail source is the
	 * authenticated admin-post attachment viewer, gated by manage_options and
	 * a per-entry nonce.
	 *
	 * @param object $entry Lead Vault entry.
	 * @param string $field_id Field ID.
	 * @param mixed  $value    Stored payload value for this field.
	 * @return string
	 */
	private function render_attachment_cell( $entry, $field_id, $value ) {
		if ( '' === trim( (string) $value ) ) {
			return '<span>&mdash;</span>';
		}

		$row = $this->uploads->get_by_entry_and_field( (int) $entry->id, $field_id );
		if ( ! $row ) {
			return '<em>' . esc_html__( 'This attachment is no longer available.', 'leadealer' ) . '</em>';
		}

		$url = add_query_arg(
			array(
				'action'   => 'leadealer_view_attachment',
				'entry'    => absint( $entry->id ),
				'field'    => rawurlencode( $field_id ),
				'_wpnonce' => wp_create_nonce( 'leadealer_view_attachment_' . absint( $entry->id ) ),
			),
			admin_url( 'admin-post.php' )
		);

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="" style="max-width:220px;max-height:220px;border-radius:4px;display:block;"></a>';
	}

	/**
	 * Stream a claimed photo upload to an authenticated administrator.
	 *
	 * Never a public/static URL: every request requires manage_options and a
	 * nonce scoped to the specific Lead Vault entry.
	 *
	 * @return void
	 */
	public function handle_view_attachment() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this attachment.', 'leadealer' ) );
		}

		$entry_id = isset( $_GET['entry'] ) ? absint( wp_unslash( $_GET['entry'] ) ) : 0;
		check_admin_referer( 'leadealer_view_attachment_' . $entry_id );

		$field_id = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
		$row      = $this->uploads->get_by_entry_and_field( $entry_id, $field_id );
		$verified = $row ? $this->uploads->verify_stored_file( $row ) : false;

		if ( ! $row || false === $verified ) {
			wp_die( esc_html__( 'This attachment is no longer available.', 'leadealer' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $verified['mime'] );
		header( 'Content-Length: ' . absint( $verified['byte_size'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Content-Disposition: inline; filename="photo.' . $verified['extension'] . '"' );
		readfile( $verified['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Authenticated, capability- and nonce-gated stream of a verified plugin-managed attachment.
		exit;
	}

	/**
	 * Get a human-readable delivery state.
	 *
	 * @param string $status Raw delivery status.
	 * @return string
	 */
	private function delivery_status_label( $status ) {
		switch ( sanitize_key( $status ) ) {
			case 'pending':
				return __( 'Queued', 'leadealer' );
			case 'processing':
				return __( 'Sending', 'leadealer' );
			case 'retrying':
				return __( 'Recovery scheduled', 'leadealer' );
			case 'handed_off':
				return __( 'Handed off', 'leadealer' );
			case 'dead':
				return __( 'Needs attention', 'leadealer' );
			default:
				return __( 'Unknown', 'leadealer' );
		}
	}

	/**
	 * Get CSS class for a delivery state.
	 *
	 * @param string $status Raw delivery status.
	 * @return string
	 */
	private function delivery_status_class( $status ) {
		switch ( sanitize_key( $status ) ) {
			case 'handed_off':
				return 'leadealer-status--success';
			case 'dead':
				return 'leadealer-status--error';
			case 'retrying':
			case 'processing':
				return 'leadealer-status--warning';
			default:
				return 'leadealer-status--neutral';
		}
	}

	/**
	 * Render global settings.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'leadealer' ) );
		}

		$next_recovery = wp_next_scheduled( 'leadealer_delivery_recovery' );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Leadealer Settings', 'leadealer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'leadealer_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="leadealer-pow-difficulty"><?php esc_html_e( 'Proof of Work difficulty', 'leadealer' ); ?></label></th><td><input id="leadealer-pow-difficulty" name="leadealer_pow_difficulty" type="number" min="12" max="22" value="<?php echo esc_attr( (string) get_option( 'leadealer_pow_difficulty', 17 ) ); ?>"><p class="description"><?php esc_html_e( '17 is the recommended default. Higher values increase browser work exponentially.', 'leadealer' ); ?></p></td></tr>
					<tr><th scope="row"><label for="leadealer-retention"><?php esc_html_e( 'Lead Vault retention', 'leadealer' ); ?></label></th><td><input id="leadealer-retention" name="leadealer_retention_days" type="number" min="0" max="3650" value="<?php echo esc_attr( (string) get_option( 'leadealer_retention_days', 90 ) ); ?>"> <?php esc_html_e( 'days', 'leadealer' ); ?><p class="description"><?php esc_html_e( 'Use 0 to keep handed-off leads indefinitely. Unresolved deliveries are never removed by age.', 'leadealer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Recovery worker', 'leadealer' ); ?></th><td>
						<?php
						if ( $next_recovery ) :
							?>
							<strong><?php esc_html_e( 'Scheduled', 'leadealer' ); ?></strong> — <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_recovery ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
							<?php
else :
	?>
							<strong><?php esc_html_e( 'Not scheduled', 'leadealer' ); ?></strong><?php endif; ?>
						<p class="description"><?php esc_html_e( 'Leadealer scans queued and stale deliveries every fifteen minutes in addition to individual retry events and the crash watchdog.', 'leadealer' ); ?></p>
						<?php
						if ( $cron_disabled ) :
							?>
							<p class="notice notice-warning inline"><strong><?php esc_html_e( 'WP-Cron is disabled.', 'leadealer' ); ?></strong> <?php esc_html_e( 'For automatic recovery, make sure a real system cron calls wp-cron.php regularly.', 'leadealer' ); ?></p><?php endif; ?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'leadealer' ); ?></th><td><label><input name="leadealer_delete_data_on_uninstall" type="checkbox" value="1" <?php checked( get_option( 'leadealer_delete_data_on_uninstall', 0 ) ); ?>> <?php esc_html_e( 'Delete forms, Lead Vault entries, delivery timelines, security tables, and plugin options when the plugin is deleted.', 'leadealer' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

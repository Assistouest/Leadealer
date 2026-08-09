<?php
/**
 * Main plugin bootstrap.
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the plugin components.
 */
final class Leadealer_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Leadealer_Plugin|null
	 */
	private static $instance = null;

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
	 * Upload repository.
	 *
	 * @var Leadealer_Upload_Repository
	 */
	private $uploads;

	/**
	 * Security service.
	 *
	 * @var Leadealer_Security
	 */
	private $security;

	/**
	 * Get the singleton instance.
	 *
	 * @return Leadealer_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}

	/**
	 * Boot plugin services.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'ensure_secret' ), 0 );
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		$this->uploads  = new Leadealer_Upload_Repository();
		$this->forms    = new Leadealer_Form_Repository( $this->uploads );
		$this->entries  = new Leadealer_Entry_Repository( $this->uploads );
		$this->security = new Leadealer_Security();
		$templates      = new Leadealer_Templates();

		$mail_template   = new Leadealer_Mail_Template();
		$mailer          = new Leadealer_Mailer( $this->entries, $this->forms, $mail_template, $this->uploads );
		$renderer        = new Leadealer_Renderer( $this->forms, $this->security );
		$image_processor = new Leadealer_Image_Processor();
		$rest            = new Leadealer_REST_Controller( $this->forms, $this->entries, $this->security, $mailer, $this->uploads, $image_processor );
		$admin           = new Leadealer_Admin( $this->forms, $this->entries, $templates, $mail_template, $this->uploads );
		$privacy         = new Leadealer_Privacy( $this->entries, $this->forms );

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'init', array( $this, 'maybe_upgrade_database' ), 1 );
		add_action( 'init', array( $this, 'ensure_schedules' ), 2 );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $renderer, 'register_shortcode' ) );
		add_action( 'init', array( $renderer, 'register_block' ) );
		$mailer->register_hooks();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $rest, 'disable_rest_caching' ), 10, 3 );
		add_action( 'admin_init', array( $admin, 'register_settings' ) );
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'add_meta_boxes_leadealer_form', array( $admin, 'register_meta_boxes' ) );
		add_action( 'save_post_leadealer_form', array( $admin, 'save_form' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $admin, 'render_delivery_notice' ) );
		add_action( 'wp_ajax_leadealer_dismiss_delivery_notice', array( $admin, 'dismiss_delivery_notice' ) );
		add_action( 'admin_post_leadealer_view_attachment', array( $admin, 'handle_view_attachment' ) );
		add_filter( 'manage_leadealer_form_posts_columns', array( $admin, 'form_columns' ) );
		add_action( 'manage_leadealer_form_posts_custom_column', array( $admin, 'form_column_content' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( $privacy, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $privacy, 'register_eraser' ) );
		add_action( 'admin_init', array( $privacy, 'add_privacy_policy_content' ) );
		add_action( 'leadealer_daily_cleanup', array( $this, 'cleanup' ) );

		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_new_site' ), 20, 1 );
		}
	}

	/**
	 * Register the bundled translation directory with WordPress.
	 *
	 * WordPress 6.7+ hands the actual loading to the just-in-time translation
	 * system, so this runs on init instead of plugins_loaded.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'leadealer',
			false,
			dirname( plugin_basename( LEADEALER_FILE ) ) . '/languages'
		);
	}

	/**
	 * Ensure this site has a persistent cryptographic secret before any public token is issued.
	 *
	 * @return void
	 */
	public function ensure_secret() {
		self::ensure_site_secret();
	}

	/**
	 * Create the per-site secret with autoload disabled.
	 *
	 * @return void
	 */
	private static function ensure_site_secret() {
		$existing = get_option( 'leadealer_secret' );
		if ( is_string( $existing ) && strlen( $existing ) >= 32 ) {
			return;
		}

		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			$secret = hash( 'sha256', wp_salt( 'auth' ) . '|' . microtime( true ) . '|' . wp_rand() );
		}

		if ( false === get_option( 'leadealer_secret', false ) ) {
			add_option( 'leadealer_secret', $secret, '', false );
		} else {
			update_option( 'leadealer_secret', $secret, false );
		}
	}

	/**
	 * Install database updates when the stored schema version is stale.
	 *
	 * @return void
	 */
	public function maybe_upgrade_database() {
		if ( Leadealer_Database::VERSION !== get_option( 'leadealer_db_version' ) ) {
			Leadealer_Database::install();
		}
	}

	/**
	 * Register the internal form post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Forms', 'leadealer' ),
			'singular_name'      => __( 'Form', 'leadealer' ),
			'add_new'            => __( 'Add New', 'leadealer' ),
			'add_new_item'       => __( 'Add New Form', 'leadealer' ),
			'edit_item'          => __( 'Edit Form', 'leadealer' ),
			'new_item'           => __( 'New Form', 'leadealer' ),
			'view_item'          => __( 'View Form', 'leadealer' ),
			'search_items'       => __( 'Search Forms', 'leadealer' ),
			'not_found'          => __( 'No forms found.', 'leadealer' ),
			'not_found_in_trash' => __( 'No forms found in Trash.', 'leadealer' ),
		);

		register_post_type(
			'leadealer_form',
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'leadealer',
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'revisions' ),
				'capability_type'     => 'post',
				'capabilities'        => array(
					'edit_post'              => 'manage_options',
					'read_post'              => 'manage_options',
					'delete_post'            => 'manage_options',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'create_posts'           => 'manage_options',
				),
				'map_meta_cap'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}


	/**
	 * Register the recovery worker interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		$schedules['leadealer_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every fifteen minutes (Leadealer)', 'leadealer' ),
		);

		return $schedules;
	}

	/**
	 * Ensure recurring reliability jobs remain scheduled.
	 *
	 * @return void
	 */
	public function ensure_schedules() {
		if ( ! wp_next_scheduled( 'leadealer_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'leadealer_daily_cleanup' );
		}

		if ( ! wp_next_scheduled( 'leadealer_delivery_recovery' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'leadealer_fifteen_minutes', 'leadealer_delivery_recovery' );
		}
	}

	/**
	 * Run scheduled cleanup.
	 *
	 * @return void
	 */
	public function cleanup() {
		Leadealer_Database::cleanup();
		$this->entries->cleanup_expired_entries();
		$this->uploads->cleanup_unclaimed();
		$this->uploads->cleanup_handed_off_unretained();
	}


	/**
	 * Initialize Leadealer for a site created after network activation.
	 *
	 * The hook can also fire while Leadealer is active on only one site, so the
	 * network-activation check is mandatory before touching another site's data.
	 *
	 * @param WP_Site $new_site Newly initialized site object.
	 * @return void
	 */
	public static function initialize_new_site( $new_site ) {
		if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( LEADEALER_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Plugin activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @param bool $network_wide Whether the plugin is network-deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	/**
	 * Activate the plugin for the current site.
	 *
	 * @return void
	 */
	private static function activate_site() {
		self::ensure_site_secret();
		Leadealer_Database::install();
		Leadealer_Upload_Repository::storage_dir();

		$instance = self::instance();
		add_filter( 'cron_schedules', array( $instance, 'register_cron_schedules' ) );
		$instance->ensure_schedules();
		remove_filter( 'cron_schedules', array( $instance, 'register_cron_schedules' ) );
	}

	/**
	 * Deactivate scheduled jobs for the current site without deleting data.
	 *
	 * @return void
	 */
	private static function deactivate_site() {
		wp_clear_scheduled_hook( 'leadealer_daily_cleanup' );
		wp_clear_scheduled_hook( 'leadealer_delivery_recovery' );
		wp_clear_scheduled_hook( 'leadealer_retry_mail' );
		wp_clear_scheduled_hook( 'leadealer_mail_watchdog' );
	}
}

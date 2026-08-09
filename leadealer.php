<?php
/**
 * Plugin Name:       Leadealer
 * Description:       Reliable lead-focused form builder with Lead Vault, conditional logic, cache-safe Proof of Work anti-spam, and native wp_mail() delivery.
 * Version:           0.7.3
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Adrien Piron
 * Author URI:        https://assistouest.fr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leadealer
 * Domain Path:       /languages
 *
 * @package Leadealer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEADEALER_VERSION', '0.7.3' );
define( 'LEADEALER_FILE', __FILE__ );
define( 'LEADEALER_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEADEALER_URL', plugin_dir_url( __FILE__ ) );

require_once LEADEALER_DIR . 'includes/class-leadealer-database.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-form-repository.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-templates.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-entry-repository.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-upload-repository.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-image-processor.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-security.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-mail-template.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-mailer.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-renderer.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-rest-controller.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-admin.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-privacy.php';
require_once LEADEALER_DIR . 'includes/class-leadealer-plugin.php';

register_activation_hook( __FILE__, array( 'Leadealer_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Leadealer_Plugin', 'deactivate' ) );

Leadealer_Plugin::instance()->boot();

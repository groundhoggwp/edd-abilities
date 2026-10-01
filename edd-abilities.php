<?php
/**
 * Plugin Name:       Abilities for Easy Digital Downloads
 * Description:       Exposes Easy Digital Downloads (and the Software Licensing, Recurring Payments and Git Download Updater add-ons) to AI agents through the WordPress Abilities API / MCP.
 * Version:           0.1.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  easy-digital-downloads
 * Author:            Groundhogg
 * License:           GPL-3.0-or-later
 * Text Domain:       edd-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EDD_ABILITIES_VERSION', '0.1.1' );
define( 'EDD_ABILITIES__FILE__', __FILE__ );
define( 'EDD_ABILITIES_PATH', plugin_dir_path( __FILE__ ) );

require_once EDD_ABILITIES_PATH . 'includes/autoloader.php';

\EDD_Abilities\Autoloader::register( 'EDD_Abilities', EDD_ABILITIES_PATH . 'includes/' );

// EDD boots on plugins_loaded, so wait until it has had the chance to.
add_action( 'plugins_loaded', function () {

	if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'Easy_Digital_Downloads' ) ) {
		return;
	}

	\EDD_Abilities\Plugin::instance();
}, 20 );

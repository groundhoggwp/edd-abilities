<?php
/**
 * Plugin Name:       Abilities for Easy Digital Downloads
 * Description:       Exposes Easy Digital Downloads (and the Software Licensing, Recurring Payments and Git Download Updater add-ons) to AI agents through the WordPress Abilities API / MCP.
 * Version:           0.3.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Groundhogg
 * License:           GPL-3.0-or-later
 * Text Domain:       edd-abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EDD_ABILITIES_VERSION', '0.3.1' );
define( 'EDD_ABILITIES__FILE__', __FILE__ );
define( 'EDD_ABILITIES_PATH', plugin_dir_path( __FILE__ ) );

require_once EDD_ABILITIES_PATH . 'includes/autoloader.php';

\EDD_Abilities\Autoloader::register( 'EDD_Abilities', EDD_ABILITIES_PATH . 'includes/' );

// Deliberately no "Requires Plugins: easy-digital-downloads" header. WordPress matches that by slug,
// and EDD Pro installs as easy-digital-downloads-pro (not on wordpress.org), so the dependency could
// never be satisfied and WordPress would show a "missing required plugin" notice. Instead we check
// for EDD's main class below, which both the free and Pro builds define.
//
// EDD boots on plugins_loaded, so wait until it has had the chance to.
add_action( 'plugins_loaded', function () {

	$missing = [];

	if ( ! function_exists( 'wp_register_ability' ) ) {
		$missing[] = __( 'the WordPress Abilities API (WordPress 6.9 or newer)', 'edd-abilities' );
	}

	if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
		$missing[] = __( 'Easy Digital Downloads', 'edd-abilities' );
	}

	if ( $missing ) {

		add_action( 'admin_notices', function () use ( $missing ) {

			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: a list of what is missing, e.g. "Easy Digital Downloads" */
						__( 'Abilities for Easy Digital Downloads is active but has not registered any abilities. It requires %s.', 'edd-abilities' ),
						implode( ' ' . __( 'and', 'edd-abilities' ) . ' ', $missing )
					)
				)
			);
		} );

		return;
	}

	\EDD_Abilities\Plugin::instance();
}, 20 );

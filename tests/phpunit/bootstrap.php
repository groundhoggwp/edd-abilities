<?php
/**
 * PHPUnit bootstrap file for Abilities for Easy Digital Downloads.
 *
 * Loads Easy Digital Downloads first, then this plugin, creates EDD's tables, and loads a small
 * base test case. Tests run against a real EDD, not mocks, so they exercise the same Abilities API
 * path an MCP client would (schema validation, permission callback, output validation).
 *
 * Environment variables:
 *  - WP_TESTS_DIR: the WordPress test library (defaults to the system temp dir)
 *  - EDD_DIR:      the Easy Digital Downloads plugin folder (defaults to a sibling `easy-digital-downloads`)
 *  - EDD_SL_DIR / EDD_RECURRING_DIR: optional Software Licensing / Recurring Payments folders
 *                  (default to siblings). When absent, their tests are skipped.
 *
 * @package EDD_Abilities
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you set WP_TESTS_DIR?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

$_edd_dir = getenv( 'EDD_DIR' );

if ( ! $_edd_dir ) {
	$_edd_dir = dirname( __DIR__, 3 ) . '/easy-digital-downloads';
}

$_edd_dir = rtrim( $_edd_dir, '/\\' );

if ( ! file_exists( $_edd_dir . '/easy-digital-downloads.php' ) ) {
	echo "Could not find Easy Digital Downloads at $_edd_dir, set EDD_DIR." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Optional add-ons. Each is loaded only when found; tests for an add-on skip themselves when it isn't.
$_addons = [
	'software-licensing' => [
		'dir'  => getenv( 'EDD_SL_DIR' ) ? getenv( 'EDD_SL_DIR' ) : dirname( __DIR__, 3 ) . '/edd-software-licensing',
		'file' => 'edd-software-licenses.php',
	],
	'recurring'          => [
		'dir'  => getenv( 'EDD_RECURRING_DIR' ) ? getenv( 'EDD_RECURRING_DIR' ) : dirname( __DIR__, 3 ) . '/edd-recurring',
		'file' => 'edd-recurring.php',
	],
];

foreach ( $_addons as $_slug => $_addon ) {

	$_addons[ $_slug ]['path'] = file_exists( rtrim( $_addon['dir'], '/\\' ) . '/' . $_addon['file'] )
		? rtrim( $_addon['dir'], '/\\' ) . '/' . $_addon['file']
		: null;

	echo sprintf( "%s: %s\n", $_slug, $_addons[ $_slug ]['path'] ? $_addons[ $_slug ]['path'] : 'not found, its tests will be skipped' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

$_server_defaults = [
	'SERVER_PROTOCOL' => 'HTTP/1.1',
	'SERVER_NAME'     => '',
	'PHP_SELF'        => '/index.php',
];

foreach ( $_server_defaults as $_key => $_value ) {
	$_SERVER[ $_key ] = $_value;
}

define( 'EDD_USE_PHP_SESSIONS', false );
define( 'EDD_DOING_TESTS', true );

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load EDD, then this plugin. EDD has to be first so the class check in the main file passes.
 */
$_manually_load_edd_and_plugin = function () use ( $_edd_dir, $_addons ) {
	require $_edd_dir . '/easy-digital-downloads.php';

	foreach ( $_addons as $_addon ) {
		if ( $_addon['path'] ) {
			require $_addon['path'];
		}
	}

	require dirname( __DIR__, 2 ) . '/edd-abilities.php';
};

tests_add_filter( 'muplugins_loaded', $_manually_load_edd_and_plugin );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Create EDD's tables (and their meta tables) once, up front, the same way EDD's own suite does.
// Software Licensing and Recurring register their tables as EDD components too, so this covers them.
foreach ( EDD()->components as $_component ) {

	foreach ( [ 'table', 'meta' ] as $_interface ) {

		$_table = $_component->get_interface( $_interface );

		if ( $_table instanceof \EDD\Database\Table ) {

			if ( $_table->exists() ) {
				$_table->uninstall();
			}

			$_table->install();
		}
	}
}

// The Abilities API initialises its registries lazily, on first access. Do that now, before the test
// lib snapshots the hook state, otherwise it restores $wp_actions between tests and did_action() lies
// about whether the init hooks already fired.
wp_get_ability_categories();
wp_get_abilities();

// Never make real HTTP requests from a test.
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'no_reqs_in_unit_tests', 'HTTP requests are disabled for unit tests.' );
} );

require __DIR__ . '/framework/class-edd-abilities-test-case.php';
require __DIR__ . '/framework/class-edd-abilities-addon-test-cases.php';

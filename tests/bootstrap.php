<?php
ini_set( 'display_errors', 'on' );
error_reporting( E_ALL );

$_wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? : '/tmp/wordpress-tests-lib';
$_gf_plugin_dir = getenv( 'GF_PLUGIN_DIR' ) ? : '/tmp/gravityview';
$_gv_revisions_tests_dir = __DIR__;

// Load test function so tests_add_filter() is available.
require_once $_wp_tests_dir . '/includes/functions.php';

// Load and install the plugins.
tests_add_filter( 'muplugins_loaded', function() use ( $_gf_plugin_dir, $_gv_revisions_tests_dir ) {
	require_once $_gf_plugin_dir . '/gravityforms.php';

	// Set up Gravity Forms database.
	if ( function_exists( 'gf_upgrade' ) ) {
		gf_upgrade()->maybe_upgrade();
	} else {
		GFForms::setup( true );
	}

	if ( ! getenv( 'WP_SKIP_INSTALL' ) ) {
		// Clean up the GF Database when we're done.
		register_shutdown_function( function() {
			RGFormsModel::drop_tables();
		} );
	}

	// Include test harnesses.
	require_once $_gv_revisions_tests_dir . '/includes.php';
} );

// Load the WP testing environment.
require_once $_wp_tests_dir . '/includes/bootstrap.php';

// Load the GF testing enviornment.
require_once $_gf_plugin_dir . '/tests/gravityforms-factory.php';

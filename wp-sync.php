<?php
/**
 * Plugin Name: WP Sync (change puller)
 * Description: Pull content your client adds or edits on the live site into your local copy without overwriting your own work. Tools → WP Sync, or `wp sync` in the terminal. Install on BOTH live and local as a must-use plugin.
 * Version: 0.2.0
 * Author: Alex M Web Design
 * Requires PHP: 7.4
 *
 * Install: copy this file AND the wp-sync/ folder into wp-content/mu-plugins/.
 */

defined( 'ABSPATH' ) || exit;

$wpsync_dir = __DIR__ . '/wp-sync/includes/';

foreach ( array( 'config', 'ids', 'logger', 'refs', 'exporter', 'rest', 'client', 'importer', 'backup', 'puller' ) as $wpsync_file ) {
	require_once $wpsync_dir . 'class-' . $wpsync_file . '.php';
}

WPSync_Logger::init();
WPSync_Rest::init();
add_action( 'init', array( 'WPSync_Config', 'maybe_install' ), 1 );

if ( is_admin() ) {
	require_once $wpsync_dir . 'class-admin.php';
	WPSync_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once $wpsync_dir . 'class-cli.php';
	WP_CLI::add_command( 'sync', 'WPSync_CLI' );
}

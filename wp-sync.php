<?php
/**
 * Plugin Name:       WP Sync (change puller)
 * Plugin URI:        https://github.com/alexmciver/WP-Sync-Tool
 * Description:       Pull content your client adds or edits on the live site into your local copy without overwriting your own work. Tools → WP Sync, or `wp sync` in the terminal. Install and activate on BOTH live and local.
 * Version:           0.5.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Alex McIver (Alex M Web Design)
 * Author URI:        https://alexmwebdesign.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/alexmciver/WP-Sync-Tool
 *
 * Install: upload the zip via Plugins → Add New → Upload and activate it, on both sites.
 * Or, as a must-use plugin: copy this file AND the wp-sync/ folder into wp-content/mu-plugins/.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WPSYNC_FILE' ) ) {
	return; // Already loaded (e.g. installed both as a plugin and a must-use plugin).
}
define( 'WPSYNC_FILE', __FILE__ );

$wpsync_dir = __DIR__ . '/wp-sync/includes/';

foreach ( array( 'config', 'ids', 'logger', 'refs', 'exporter', 'rest', 'client', 'importer', 'backup', 'puller', 'updater', 'full' ) as $wpsync_file ) {
	require_once $wpsync_dir . 'class-' . $wpsync_file . '.php';
}

WPSync_Logger::init();
WPSync_Rest::init();
WPSync_Updater::init();
add_action( 'init', array( 'WPSync_Config', 'maybe_install' ), 1 );
add_action( 'init', array( 'WPSync_Config', 'schedule' ), 20 );
add_action( 'wpsync_daily', array( 'WPSync_Config', 'daily' ) );
register_activation_hook( __FILE__, array( 'WPSync_Config', 'install' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=wpsync' ) ) . '">Open WP Sync</a>' );
	return $links;
} );

if ( is_admin() ) {
	require_once $wpsync_dir . 'class-admin.php';
	WPSync_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once $wpsync_dir . 'class-cli.php';
	WP_CLI::add_command( 'sync', 'WPSync_CLI' );
}

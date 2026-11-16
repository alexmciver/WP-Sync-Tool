<?php
/**
 * Plugin Name:       LivePull
 * Plugin URI:        https://github.com/alexmciver/WP-Sync-Tool
 * Description:       Pull the content your client adds or edits on the live site into your local copy, without overwriting your own work. Install on both sites, then go to Tools → LivePull (or run `wp livepull` in the terminal).
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Alex McIver
 * Author URI:        https://alexmwebdesign.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       livepull
 * Update URI:        https://github.com/alexmciver/WP-Sync-Tool
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'LIVEPULL_FILE' ) ) {
	return; // Already loaded (e.g. installed both as a plugin and a must-use plugin).
}
define( 'LIVEPULL_FILE', __FILE__ );

$livepull_dir = __DIR__ . '/includes/';

// Settings in wp-config.php written for the plugin's earlier name (WP Sync) keep working.
foreach ( array( 'SECRET', 'REMOTE', 'REMOTE_SECRET', 'REMOTE_BASIC_AUTH', 'ALLOW_FULL_COPY', 'BACKUP_DIR', 'DEFAULT_AUTHOR', 'EXCLUDE_POST_TYPES', 'EXCLUDE_TAXONOMIES', 'LOG_DAYS', 'PAGE_SIZE', 'PULL_MENUS', 'PULL_OPTIONS', 'SSL_VERIFY', 'AUTO_UPDATE', 'DISABLE_UPDATER' ) as $livepull_const ) {
	if ( defined( 'WPSYNC_' . $livepull_const ) && ! defined( 'LIVEPULL_' . $livepull_const ) ) {
		define( 'LIVEPULL_' . $livepull_const, constant( 'WPSYNC_' . $livepull_const ) );
	}
}

require_once $livepull_dir . 'class-migrate.php';
if ( LivePull_Migrate::old_plugin_active() ) {
	// The old "WP Sync" plugin is still switched on: don't run both, just say what to do.
	add_action( 'admin_notices', array( 'LivePull_Migrate', 'notice' ) );
	return;
}
LivePull_Migrate::maybe_run();

foreach ( array( 'config', 'ids', 'logger', 'refs', 'exporter', 'rest', 'client', 'importer', 'backup', 'puller', 'full' ) as $livepull_file ) {
	require_once $livepull_dir . 'class-' . $livepull_file . '.php';
}
// Self-updates from GitHub: only in the GitHub download (the WordPress.org version is updated by WordPress.org).
if ( file_exists( $livepull_dir . 'class-updater.php' ) ) {
	require_once $livepull_dir . 'class-updater.php';
	LivePull_Updater::init();
}

LivePull_Logger::init();
LivePull_Rest::init();
add_action( 'init', array( 'LivePull_Config', 'maybe_install' ), 1 );
add_action( 'init', array( 'LivePull_Config', 'schedule' ), 20 );
add_action( 'livepull_daily', array( 'LivePull_Config', 'daily' ) );
register_activation_hook( __FILE__, array( 'LivePull_Config', 'install' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=livepull' ) ) . '">Open LivePull</a>' );
	return $links;
} );

if ( is_admin() ) {
	require_once $livepull_dir . 'class-admin.php';
	LivePull_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once $livepull_dir . 'class-cli.php';
	WP_CLI::add_command( 'livepull', 'LivePull_CLI' );
}

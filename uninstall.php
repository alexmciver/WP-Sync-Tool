<?php
/**
 * Runs when WP Sync is deleted from the Plugins screen: removes its settings, its change log
 * and its database backups (which contain full copies of the site's data).
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpsync_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wpsync\\_%' OR option_name LIKE '\\_transient\\_wpsync\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpsync\\_%' OR option_name LIKE '\\_site\\_transient\\_wpsync\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_wpsync\\_%'" );
foreach ( $wpsync_options as $wpsync_option ) {
	delete_option( $wpsync_option );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpsync_log" );
foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wpsynctmp_' ) . '%' ) ) as $wpsync_t ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpsync_t}`" );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wpsync_id' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s", '_wpsync_id' ) );
wp_clear_scheduled_hook( 'wpsync_daily' );

// Backups: wherever WP Sync kept them.
$wpsync_site = sanitize_file_name( basename( untrailingslashit( ABSPATH ) ) ) . '-' . substr( md5( ABSPATH . '|' . DB_NAME . '|' . $wpdb->prefix ), 0, 10 );
$wpsync_dirs = array( WP_CONTENT_DIR . '/wpsync-backups', dirname( untrailingslashit( ABSPATH ) ) . '/wpsync-backups/' . $wpsync_site );
if ( defined( 'WPSYNC_BACKUP_DIR' ) && WPSYNC_BACKUP_DIR ) {
	$wpsync_dirs[] = untrailingslashit( WPSYNC_BACKUP_DIR );
}
foreach ( array_unique( $wpsync_dirs ) as $wpsync_dir ) {
	if ( ! is_dir( $wpsync_dir ) ) {
		continue;
	}
	foreach ( (array) glob( $wpsync_dir . '/pre-*' ) as $wpsync_f ) {
		@unlink( $wpsync_f ); // phpcs:ignore
	}
	foreach ( array( '.htaccess', 'web.config', 'index.php', 'index.html' ) as $wpsync_f ) {
		@unlink( $wpsync_dir . '/' . $wpsync_f ); // phpcs:ignore
	}
	@rmdir( $wpsync_dir ); // phpcs:ignore
}

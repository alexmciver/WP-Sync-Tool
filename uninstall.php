<?php
/**
 * Runs when LivePull is deleted from the Plugins screen: removes its settings, its change log
 * and its database backups (which contain full copies of the site's data).
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

global $wpdb;

$livepull_options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'livepull\\_%' OR option_name LIKE '\\_transient\\_livepull\\_%' OR option_name LIKE '\\_transient\\_timeout\\_livepull\\_%' OR option_name LIKE '\\_site\\_transient\\_livepull\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_livepull\\_%'" );
foreach ( $livepull_options as $livepull_option ) {
	delete_option( $livepull_option );
}
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'livepull_log' ) );
foreach ( array( 'lpulltmp_', 'lpullold_', 'lprst_' ) as $livepull_mark ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $livepull_mark ) . '%' ) ) as $livepull_t ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $livepull_t ) );
	}
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_livepull_id' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s", '_livepull_id' ) );
wp_clear_scheduled_hook( 'livepull_daily' );

// Backups: wherever LivePull kept them.
$livepull_site = sanitize_file_name( basename( untrailingslashit( ABSPATH ) ) ) . '-' . substr( md5( ABSPATH . '|' . DB_NAME . '|' . $wpdb->prefix ), 0, 10 );
$livepull_dirs = array_merge( array( WP_CONTENT_DIR . '/livepull-backups', dirname( untrailingslashit( ABSPATH ) ) . '/livepull-backups/' . $livepull_site ), (array) glob( WP_CONTENT_DIR . '/livepull-backups-*', GLOB_ONLYDIR ), (array) glob( WP_CONTENT_DIR . '/livepull-stage-*', GLOB_ONLYDIR ) );
if ( defined( 'LIVEPULL_BACKUP_DIR' ) && LIVEPULL_BACKUP_DIR ) {
	$livepull_dirs[] = untrailingslashit( LIVEPULL_BACKUP_DIR );
}
foreach ( array_unique( $livepull_dirs ) as $livepull_dir ) {
	if ( ! is_dir( $livepull_dir ) ) {
		continue;
	}
	foreach ( (array) glob( $livepull_dir . '/pre-*' ) as $livepull_f ) {
		@unlink( $livepull_f ); // phpcs:ignore
	}
	foreach ( array( '.htaccess', 'web.config', 'index.php', 'index.html' ) as $livepull_f ) {
		@unlink( $livepull_dir . '/' . $livepull_f ); // phpcs:ignore
	}
	@rmdir( $livepull_dir ); // phpcs:ignore
}

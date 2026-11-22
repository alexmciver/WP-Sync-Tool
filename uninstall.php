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

$livepull_like    = array_map(
	function ( $p ) use ( $wpdb ) {
		return $wpdb->esc_like( $p ) . '%';
	},
	array( 'livepull_', '_transient_livepull_', '_transient_timeout_livepull_', '_site_transient_livepull_', '_site_transient_timeout_livepull_' )
);
$livepull_options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $livepull_like[0], $livepull_like[1], $livepull_like[2], $livepull_like[3], $livepull_like[4] ) );
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
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key = %s", '_livepull_id' ) );
wp_clear_scheduled_hook( 'livepull_daily' );
wp_clear_scheduled_hook( 'livepull_scheduled_pull' );

// LivePull's own folder in uploads: backups, Copy everything's staging, temporary lists.
$livepull_uploads = wp_get_upload_dir();
$livepull_dirs    = (array) glob( untrailingslashit( $livepull_uploads['basedir'] ) . '/livepull-*', GLOB_ONLYDIR );
if ( defined( 'LIVEPULL_BACKUP_DIR' ) && LIVEPULL_BACKUP_DIR && is_dir( LIVEPULL_BACKUP_DIR ) ) {
	foreach ( (array) glob( untrailingslashit( LIVEPULL_BACKUP_DIR ) . '/pre-*.lpb*' ) as $livepull_f ) {
		wp_delete_file( $livepull_f );
	}
}
foreach ( $livepull_dirs as $livepull_dir ) {
	if ( ! preg_match( '#/livepull-[a-z0-9]{24}$#', wp_normalize_path( $livepull_dir ) ) ) {
		continue; // Only LivePull's own folder.
	}
	$livepull_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $livepull_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $livepull_it as $livepull_f ) {
		$livepull_f->isDir() ? @rmdir( $livepull_f->getPathname() ) : wp_delete_file( $livepull_f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	@rmdir( $livepull_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

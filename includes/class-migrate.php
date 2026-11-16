<?php
/**
 * LivePull was called "WP Sync" before version 1.0. This moves an existing WP Sync install's
 * settings, change history, content links and backups over to LivePull's names, once, so
 * nothing has to be set up again and live and local keep recognising each other.
 *
 * Everything is moved (not copied), so deleting the old WP Sync plugin afterwards – which
 * runs its own clean-up – finds nothing of LivePull's to remove.
 *
 * @package LivePull
 */

defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

class LivePull_Migrate {

	/** Is the old WP Sync plugin still switched on? */
	public static function old_plugin_active() {
		if ( class_exists( 'WPSync_Config', false ) ) {
			return true;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( '/wp-sync.php' === substr( (string) $file, -12 ) ) {
				return true;
			}
		}
		return false;
	}

	public static function notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>LivePull is the new name for WP Sync.</strong> Deactivate the old <em>WP Sync</em> plugin on the <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a> screen. LivePull will then take over its settings, history and backups automatically, and you can delete WP Sync.</p></div>';
	}

	public static function maybe_run() {
		if ( get_option( 'livepull_site_key' ) || ! get_option( 'wpsync_site_key' ) ) {
			return; // Already LivePull, or never had WP Sync.
		}
		global $wpdb;

		// Settings and state: wpsync_* → livepull_*.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpsync_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off rename of this plugin's own options.
		foreach ( $names as $old ) {
			$new = 'livepull_' . substr( $old, 7 );
			if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		foreach ( array( '_transient_wpsync_', '_transient_timeout_wpsync_', '_site_transient_wpsync_', '_site_transient_timeout_wpsync_' ) as $like ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $like ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_clear_scheduled_hook( 'wpsync_daily' );

		// Change history table (keeps its row numbers, so local sites carry on where they left off).
		$old_table = $wpdb->prefix . 'wpsync_log';
		$new_table = $wpdb->prefix . 'livepull_log';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) !== $new_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_table, $new_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// The IDs that link the same item on live and local.
		$wpdb->update( $wpdb->postmeta, array( 'meta_key' => '_livepull_id' ), array( 'meta_key' => '_wpsync_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$wpdb->update( $wpdb->termmeta, array( 'meta_key' => '_livepull_id' ), array( 'meta_key' => '_wpsync_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery

		// Backups folders.
		self::move_backups();

		update_option( 'livepull_migrated_from_wpsync', time(), false );
		wp_cache_flush();
	}

	private static function move_backups() {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return;
		}
		$pairs = array(
			array( dirname( untrailingslashit( ABSPATH ) ) . '/wpsync-backups', dirname( untrailingslashit( ABSPATH ) ) . '/livepull-backups' ),
			array( WP_CONTENT_DIR . '/wpsync-backups', WP_CONTENT_DIR . '/livepull-backups' ),
		);
		foreach ( $pairs as $pair ) {
			if ( $wp_filesystem->is_dir( $pair[0] ) && ! $wp_filesystem->exists( $pair[1] ) ) {
				$wp_filesystem->move( $pair[0], $pair[1] );
			}
		}
	}
}

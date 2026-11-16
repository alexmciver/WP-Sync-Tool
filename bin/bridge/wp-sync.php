<?php
/**
 * Plugin Name: WP Sync (now LivePull)
 * Description: WP Sync has been renamed LivePull. This placeholder only shows how to switch; your settings and history are kept and LivePull takes them over.
 * Version:     1.0.0
 * Author:      Alex McIver
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>WP Sync is now called LivePull.</strong> Install LivePull (search for it under Plugins → Add New, or download livepull.zip from <a href="https://github.com/alexmciver/WP-Sync-Tool/releases/latest" target="_blank" rel="noopener">GitHub</a>), deactivate this plugin, then activate LivePull. LivePull takes over your connection, history and backups automatically. You can then delete this plugin.</p></div>';
} );

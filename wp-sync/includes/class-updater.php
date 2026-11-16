<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases, so WP Sync updates like any other plugin
 * (Plugins screen, Dashboard → Updates, and WordPress auto-updates).
 *
 * Each release on github.com/alexmciver/WP-Sync-Tool carries a wp-sync.zip asset,
 * built automatically by the repo's GitHub Action when the version number changes.
 *
 * wp-config options:
 *   WPSYNC_DISABLE_AUTO_UPDATE  true = show updates but install them manually.
 *   WPSYNC_DISABLE_UPDATER      true = never contact GitHub.
 */
final class WPSync_Updater {

	const REPO      = 'alexmciver/WP-Sync-Tool';
	const SLUG      = 'wp-sync';
	const CACHE     = 'wpsync_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( defined( 'WPSYNC_DISABLE_UPDATER' ) && WPSYNC_DISABLE_UPDATER ) {
			return;
		}
		// Must-use plugins can't be updated by WordPress.
		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( WPSYNC_FILE ), wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 2 );
	}

	public static function basename() {
		return plugin_basename( WPSYNC_FILE );
	}

	/** Latest release from GitHub (cached). @return array|null */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-Sync-Updater' ),
			)
		);
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
				$package = '';
				foreach ( (array) ( isset( $json['assets'] ) ? $json['assets'] : array() ) as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'wp-sync.zip' === $asset['name'] ) {
						$package = $asset['browser_download_url'];
					}
				}
				$data = array(
					'version'   => ltrim( $json['tag_name'], 'vV' ),
					'package'   => $package ? $package : ( isset( $json['zipball_url'] ) ? $json['zipball_url'] : '' ),
					'url'       => isset( $json['html_url'] ) ? $json['html_url'] : 'https://github.com/' . self::REPO,
					'notes'     => isset( $json['body'] ) ? (string) $json['body'] : '',
					'published' => isset( $json['published_at'] ) ? $json['published_at'] : '',
				);
			}
		}
		// Cache failures briefly too, so a GitHub outage doesn't slow every admin page.
		set_site_transient( self::CACHE, $data ? $data : array(), $data ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $data;
	}

	private static function item( $r = null ) {
		$item = (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $r ? $r['version'] : WPSync_Config::VERSION,
			'url'           => 'https://github.com/' . self::REPO,
			'package'       => $r ? $r['package'] : '',
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
		return $item;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$force = isset( $_GET['force-check'] ); // phpcs:ignore -- Dashboard → Updates → "Check again".
		$r     = self::release( $force );
		$base  = self::basename();
		if ( $r && $r['package'] && version_compare( $r['version'], WPSync_Config::VERSION, '>' ) ) {
			$transient->response[ $base ] = self::item( $r );
			unset( $transient->no_update[ $base ] );
		} else {
			// Listing it under no_update is what makes the "Enable auto-updates" link appear.
			$transient->no_update[ $base ] = self::item();
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}

	/** The "View details" popup. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		return (object) array(
			'name'          => 'WP Sync (change puller)',
			'slug'          => self::SLUG,
			'version'       => $r ? $r['version'] : WPSync_Config::VERSION,
			'author'        => '<a href="https://alexmwebdesign.co.uk">Alex M Web Design</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires_php'  => '7.4',
			'last_updated'  => $r ? $r['published'] : '',
			'download_link' => $r ? $r['package'] : '',
			'sections'      => array(
				'description' => '<p>Pull content your client adds or edits on the live site into your local copy without overwriting your own work.</p>',
				'changelog'   => $r && $r['notes'] ? wpautop( esc_html( $r['notes'] ) ) : '<p>See the releases on GitHub.</p>',
			),
		);
	}

	/** Make sure the unzipped folder is always wp-content/plugins/wp-sync/ (GitHub zipballs use repo-hash names). */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) || '.' === dirname( self::basename() ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'wpsync_update_folder', 'Could not prepare the WP Sync update folder.' );
	}

	/** Auto-update by default (can be turned off with WPSYNC_DISABLE_AUTO_UPDATE). */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin ) {
			return ! ( defined( 'WPSYNC_DISABLE_AUTO_UPDATE' ) && WPSYNC_DISABLE_AUTO_UPDATE );
		}
		return $update;
	}

	public static function clear( $upgrader, $options ) {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_site_transient( self::CACHE );
		}
	}
}

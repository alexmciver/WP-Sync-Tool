<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases, so LivePull updates like any other plugin
 * (Plugins screen, Dashboard → Updates, and WordPress auto-updates).
 *
 * Each release on github.com/alexmciver/WP-Sync-Tool carries a livepull.zip asset,
 * built automatically by the repo's GitHub Action when the version number changes.
 *
 * By default an update is announced (Tools → LivePull, Plugins, Dashboard → Updates) and
 * installed when you click "Update now". WordPress's own "Enable auto-updates" link on the
 * Plugins screen still works if you want it hands-off for a particular site.
 *
 * wp-config options:
 *   LIVEPULL_AUTO_UPDATE      true = always install updates automatically.
 *   LIVEPULL_DISABLE_UPDATER  true = never contact GitHub.
 */
final class LivePull_Updater {

	const REPO      = 'alexmciver/WP-Sync-Tool';
	const SLUG      = 'livepull';
	const CACHE     = 'livepull_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( defined( 'LIVEPULL_DISABLE_UPDATER' ) && LIVEPULL_DISABLE_UPDATER ) {
			return;
		}
		// Must-use plugins can't be updated by WordPress.
		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( LIVEPULL_FILE ), wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'livepull_admin_title_actions', array( __CLASS__, 'title_action' ) );
		add_action( 'livepull_admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 2 );
	}

	public static function basename() {
		return plugin_basename( LIVEPULL_FILE );
	}

	/** Latest release from GitHub (cached). @return array|null */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$errors = array();
		$data   = self::from_api( $errors );
		if ( ! $data ) {
			$data = self::from_redirect( $errors ); // Fallback: no API, no rate limit.
		}
		update_option( 'livepull_update_error', $data ? '' : implode( ' / ', $errors ), false );
		// Cache failures briefly too, so a GitHub outage doesn't slow every admin page.
		set_site_transient( self::CACHE, $data ? $data : array(), $data ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	/** Why the last check failed ('' if it worked). */
	public static function last_error() {
		return (string) get_option( 'livepull_update_error', '' );
	}

	private static function from_api( array &$errors ) {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'LivePull-Updater' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$errors[] = 'GitHub API: ' . $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) || empty( $json['tag_name'] ) ) {
			$errors[] = 'GitHub API: HTTP ' . $code . ( is_array( $json ) && ! empty( $json['message'] ) ? ' ' . $json['message'] : '' );
			return null;
		}
		$package = '';
		foreach ( (array) ( isset( $json['assets'] ) ? $json['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'livepull.zip' === $asset['name'] ) {
				$package = $asset['browser_download_url'];
			}
		}
		if ( ! $package ) {
			$errors[] = 'GitHub API: latest release has no livepull.zip';
			return null;
		}
		return array(
			'version'   => ltrim( $json['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $json['html_url'] ) ? $json['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'notes'     => isset( $json['body'] ) ? (string) $json['body'] : '',
			'published' => isset( $json['published_at'] ) ? $json['published_at'] : '',
		);
	}

	/** github.com/<repo>/releases/latest redirects to …/releases/tag/vX.Y.Z. */
	private static function from_redirect( array &$errors ) {
		$res = wp_remote_head( 'https://github.com/' . self::REPO . '/releases/latest', array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'LivePull-Updater' ) ) );
		if ( is_wp_error( $res ) ) {
			$errors[] = 'github.com: ' . $res->get_error_message();
			return null;
		}
		$loc = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( ! preg_match( '#/releases/tag/(v?[0-9][0-9A-Za-z.\-]*)$#', $loc, $m ) ) {
			$errors[] = 'github.com: HTTP ' . wp_remote_retrieve_response_code( $res ) . ', no release found';
			return null;
		}
		$tag = $m[1];
		return array(
			'version'   => ltrim( $tag, 'vV' ),
			'package'   => 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode( $tag ) . '/livepull.zip',
			'url'       => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $tag ),
			'notes'     => '',
			'published' => '',
		);
	}

	private static function item( $r = null ) {
		$item = (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => self::SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $r ? $r['version'] : LivePull_Config::VERSION,
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
		if ( $r && ! self::trusted_package( $r['package'] ) ) {
			$r = null;
		}
		if ( $r && $r['package'] && version_compare( $r['version'], LivePull_Config::VERSION, '>' ) ) {
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
			'name'          => 'LivePull (change puller)',
			'slug'          => self::SLUG,
			'version'       => $r ? $r['version'] : LivePull_Config::VERSION,
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

	/** Make sure the unzipped folder is always wp-content/plugins/livepull/ (GitHub zipballs use repo-hash names). */
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
		return new WP_Error( 'livepull_update_folder', 'Could not prepare the LivePull update folder.' );
	}

	/** Manual by default; LIVEPULL_AUTO_UPDATE forces automatic. Otherwise WordPress's per-plugin toggle decides. */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin && defined( 'LIVEPULL_AUTO_UPDATE' ) && LIVEPULL_AUTO_UPDATE ) {
			return true;
		}
		return $update;
	}

	/** Only ever install packages served by GitHub over HTTPS. */
	public static function trusted_package( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return 'https' === wp_parse_url( (string) $url, PHP_URL_SCHEME )
			&& in_array( $host, array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' ), true );
	}

	/** Newer release info if one exists, else null. */
	public static function available( $force = false ) {
		if ( ! has_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) ) ) {
			return null; // Must-use install or updater disabled.
		}
		$r = self::release( $force );
		return ( $r && $r['package'] && self::trusted_package( $r['package'] ) && version_compare( $r['version'], LivePull_Config::VERSION, '>' ) ) ? $r : null;
	}

	/** Link that runs WordPress's normal plugin updater for LivePull. */
	public static function update_url() {
		$base = self::basename();
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $base ) ), 'upgrade-plugin_' . $base );
	}

	/** Force a fresh check (the "Check for updates" link). */
	public static function check_now() {
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		$r = self::release( true );
		wp_update_plugins();
		return $r;
	}

	public static function clear( $upgrader, $options ) {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_site_transient( self::CACHE );
		}
	}

	/** "Check for updates" link next to the LivePull screen title. */
	public static function title_action() {
		if ( has_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) ) ) {
			printf( '<a class="page-title-action" href="%s">Check for updates</a>', esc_url( wp_nonce_url( LivePull_Admin::url( array( 'livepull_check' => 1 ) ), 'livepull_check' ) ) );
		}
	}

	/** Result of "Check for updates", and the "Update now" box when a new version exists. */
	public static function admin_notices() {
		if ( isset( $_GET['livepull_check'] ) && check_admin_referer( 'livepull_check' ) ) {
			if ( ! self::check_now() ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( 'Couldn\'t check GitHub for updates: ' . self::last_error() . '. You can always download the latest livepull.zip from github.com/alexmciver/WP-Sync-Tool/releases and upload it.' ) . '</p></div>';
			} elseif ( ! self::available() ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'LivePull is up to date (version ' . LivePull_Config::VERSION . ').' ) . '</p></div>';
			}
		}
		$update = self::available();
		if ( $update ) {
			?>
			<div class="notice notice-info livepull-update">
				<p><strong>LivePull <?php echo esc_html( $update['version'] ); ?> is available</strong> (you have <?php echo esc_html( LivePull_Config::VERSION ); ?>).
				<a href="<?php echo esc_url( $update['url'] ); ?>" target="_blank" rel="noopener">What's new</a></p>
				<p><a class="button button-primary" href="<?php echo esc_url( self::update_url() ); ?>">Update now</a>
				<span class="description">&nbsp;Update both your live and local sites so they stay on the same version.</span></p>
			</div>
			<?php
		}
	}
}

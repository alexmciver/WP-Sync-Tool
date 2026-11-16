<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Settings, exclusions and install routine.
 *
 * wp-config.php constants:
 *   LIVEPULL_SECRET              (both sites) shared key, 32+ characters.
 *   LIVEPULL_REMOTE              (local only) live site URL, e.g. https://example.co.uk
 *   LIVEPULL_REMOTE_BASIC_AUTH   (optional)   "user:pass" if live/staging is behind basic auth.
 *   LIVEPULL_EXCLUDE_POST_TYPES  (optional)   comma list of extra post types to never sync.
 *   LIVEPULL_EXCLUDE_TAXONOMIES  (optional)   comma list of extra taxonomies to never sync.
 *   LIVEPULL_DEFAULT_AUTHOR      (optional)   local user login for posts whose author doesn't exist locally.
 */
final class LivePull_Config {

	const VERSION  = '1.1.0';
	const META_KEY = '_livepull_id';

	/** Constants in wp-config.php win; otherwise the values saved on Tools → LivePull. */
	public static function secret() {
		return defined( 'LIVEPULL_SECRET' ) ? (string) LIVEPULL_SECRET : (string) get_option( 'livepull_secret', '' );
	}

	public static function remote() {
		$url = defined( 'LIVEPULL_REMOTE' ) ? (string) LIVEPULL_REMOTE : (string) get_option( 'livepull_remote', '' );
		return untrailingslashit( trim( $url ) );
	}

	/**
	 * Key used when pulling from live. A key pasted on Tools → LivePull wins, so an old
	 * LIVEPULL_SECRET line in wp-config.php can't silently override it.
	 */
	public static function remote_secret() {
		return self::remote_secret_source()[0];
	}

	/** @return array{0:string,1:string} [ key, where it came from ] */
	public static function remote_secret_source() {
		if ( defined( 'LIVEPULL_REMOTE_SECRET' ) ) {
			return array( (string) LIVEPULL_REMOTE_SECRET, 'LIVEPULL_REMOTE_SECRET in wp-config.php' );
		}
		$pasted = trim( (string) get_option( 'livepull_remote_secret', '' ) );
		if ( '' !== $pasted ) {
			return array( $pasted, 'the key pasted on Tools → LivePull' );
		}
		if ( defined( 'LIVEPULL_SECRET' ) ) {
			return array( (string) LIVEPULL_SECRET, 'LIVEPULL_SECRET in wp-config.php' );
		}
		return array( (string) get_option( 'livepull_secret', '' ), "this site's own key" );
	}

	/** "…a1b2" so keys can be compared on screen without showing them. */
	public static function key_hint( $key ) {
		$key = (string) $key;
		return strlen( $key ) >= 8 ? '…' . substr( $key, -4 ) : '(none)';
	}

	public static function page_size() {
		return defined( 'LIVEPULL_PAGE_SIZE' ) ? max( 1, min( 200, (int) LIVEPULL_PAGE_SIZE ) ) : 50;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'livepull_log';
	}

	/**
	 * Post types that are never synced. These are either site structure/config
	 * (owned by the developer, deployed separately) or data that should never be
	 * copied around (orders).
	 */
	public static function excluded_post_types() {
		$defaults = array(
			// Core internals and structure.
			'revision', 'customize_changeset', 'oembed_cache', 'user_request',
			'custom_css', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
			'wp_font_family', 'wp_font_face',
			// ACF definitions (keep these in acf-json + Git).
			'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
			// Divi library / Theme Builder.
			'et_pb_layout', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_theme_builder',
			// Elementor templates and kit.
			'elementor_library', 'elementor_snippet', 'elementor_font', 'elementor_icons',
			// Beaver Builder / Bricks templates.
			'fl-builder-template', 'bricks_template',
			// WooCommerce orders and subscriptions (never copy customer orders).
			'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription',
			// Form definitions (config, not content).
			'wpcf7_contact_form', 'wpforms',
			// Background job queues.
			'scheduled-action',
		);
		if ( ! self::pull_menus() ) {
			$defaults[] = 'nav_menu_item';
		}
		$extra = defined( 'LIVEPULL_EXCLUDE_POST_TYPES' ) ? array_map( 'trim', explode( ',', LIVEPULL_EXCLUDE_POST_TYPES ) ) : array();
		return array_values( array_unique( array_filter( apply_filters( 'livepull_excluded_post_types', array_merge( $defaults, $extra ) ) ) ) );
	}

	public static function excluded_taxonomies() {
		$defaults = array(
			'link_category', 'wp_theme', 'wp_template_part_area',
			// Divi library.
			'layout_category', 'layout_type', 'scope', 'module_width', 'layout_pack', 'layout_tag',
			// Elementor library.
			'elementor_library_type', 'elementor_library_category',
			// Action Scheduler.
			'action-group',
		);
		if ( ! self::pull_menus() ) {
			$defaults[] = 'nav_menu';
		}
		$extra = defined( 'LIVEPULL_EXCLUDE_TAXONOMIES' ) ? array_map( 'trim', explode( ',', LIVEPULL_EXCLUDE_TAXONOMIES ) ) : array();
		return array_values( array_unique( array_filter( apply_filters( 'livepull_excluded_taxonomies', array_merge( $defaults, $extra ) ) ) ) );
	}

	/** Meta keys that are never copied (editor locks, our own ID, ping queues). */
	public static function ignored_meta_keys() {
		return apply_filters(
			'livepull_ignored_meta_keys',
			array( self::META_KEY, '_edit_lock', '_edit_last', '_encloseme', '_pingme' )
		);
	}

	/** Menus are pulled unless LIVEPULL_PULL_MENUS is false. */
	public static function pull_menus() {
		return ! defined( 'LIVEPULL_PULL_MENUS' ) || LIVEPULL_PULL_MENUS;
	}

	/**
	 * Settings pulled from live: [ option name or pattern => true (whole value) | [ sub-keys ] ].
	 * Only things clients typically change themselves; theme/plugin settings stay yours.
	 * Extra options: define( 'LIVEPULL_PULL_OPTIONS', 'option_a,option_b' ) or the livepull_pulled_options filter.
	 */
	public static function pulled_options() {
		$o = array(
			'blogname'        => true,
			'blogdescription' => true,
			'show_on_front'   => true,
			'page_on_front'   => true,
			'page_for_posts'  => true,
			'sticky_posts'    => true,
			'site_icon'       => true,
			'theme_mods_*'    => array( 'nav_menu_locations', 'custom_logo' ), // Menu locations + logo only.
		);
		if ( ! self::pull_menus() ) {
			$o['theme_mods_*'] = array( 'custom_logo' );
		}
		if ( defined( 'LIVEPULL_PULL_OPTIONS' ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) LIVEPULL_PULL_OPTIONS ) ) ) as $n ) {
				$o[ $n ] = true;
			}
		}
		return apply_filters( 'livepull_pulled_options', $o );
	}

	/** @return true|array|null how an option is pulled (whole / sub-keys), or null if it isn't. */
	/**
	 * Settings that are never pulled, whatever the configuration or filters say: they control
	 * the site's address, who can log in, which code runs, or LivePull's own connection.
	 */
	public static function option_forbidden( $name ) {
		global $wpdb;
		$name = strtolower( (string) $name );
		$deny = array( 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'users_can_register',
			'default_role', 'admin_email', 'new_admin_email', 'template', 'stylesheet', 'cron', 'upload_path', 'upload_url_path',
			'uninstall_plugins', 'auto_update_plugins', 'auto_update_themes', 'secret_key', 'nonce_key', 'nonce_salt', 'auth_key', 'auth_salt',
			'logged_in_key', 'logged_in_salt', 'secure_auth_key', 'secure_auth_salt', 'db_version', 'initial_db_version',
			strtolower( $wpdb->prefix . 'user_roles' ) );
		return in_array( $name, $deny, true ) || 0 === strpos( $name, 'livepull_' ) || 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' );
	}

	/** May a pull write this setting? Only the configured pulled settings, never forbidden ones. */
	public static function option_allowed( $name ) {
		return ! self::option_forbidden( $name ) && null !== self::option_spec( $name );
	}

	public static function option_spec( $name ) {
		foreach ( self::pulled_options() as $pattern => $spec ) {
			if ( $pattern === $name || ( false !== strpos( $pattern, '*' ) && fnmatch( $pattern, $name ) ) ) {
				return $spec;
			}
		}
		return null;
	}

	/** Which options (or their sub-keys) hold post/term IDs. */
	public static function option_refs( $name ) {
		$refs = array(
			'page_on_front'  => 'post',
			'page_for_posts' => 'post',
			'sticky_posts'   => 'post',
			'site_icon'      => 'post',
		);
		if ( 0 === strpos( $name, 'theme_mods_' ) ) {
			return array( 'nav_menu_locations' => 'term', 'custom_logo' => 'post' );
		}
		$r = isset( $refs[ $name ] ) ? $refs[ $name ] : null;
		return apply_filters( 'livepull_option_refs', $r, $name );
	}

	public static function option_sid( $name ) {
		return md5( 'option|' . $name );
	}

	/** Every pulled option name that exists on this site. */
	public static function pulled_option_names() {
		global $wpdb;
		$names = array();
		foreach ( self::pulled_options() as $pattern => $spec ) {
			if ( false === strpos( $pattern, '*' ) ) {
				$names[] = $pattern;
				continue;
			}
			$like  = implode( '%', array_map( array( $wpdb, 'esc_like' ), explode( '*', $pattern ) ) );
			$names = array_merge( $names, (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Is this meta key one LivePull should ignore? Exact keys plus caches that page builders
	 * and plugins rewrite whenever a page is viewed (they're per-site and rebuilt
	 * automatically, and treating them as edits would cause false conflicts).
	 */
	public static function is_ignored_meta( $key ) {
		static $exact = null;
		if ( null === $exact || has_filter( 'livepull_ignored_meta_keys' ) ) {
			$exact = array_flip( self::ignored_meta_keys() );
		}
		$key = (string) $key;
		if ( isset( $exact[ $key ] ) ) {
			return true;
		}
		$patterns = apply_filters( 'livepull_ignored_meta_patterns', array(
			'/^_oembed_/',                                     // WordPress oEmbed cache
			'/^_elementor_(css|page_assets|element_cache|screenshot|controls_usage|migrations_state)/', // Elementor caches
			'/^_uag_(page_assets|css_file_name|js_file_name)/', '/^_uagb_previous_block_counts$/', // Spectra
			'/^_kad_post_(css|assets)/', '/^_kadence_blocks_(css|assets)/', // Kadence
			'/^_generateblocks_(dynamic_css|css)/', '/^generateblocks_css/', // GenerateBlocks
			'/^_et_dynamic_cached_/', '/^_et_builder_dynamic_assets/', '/^_et_pb_.*_cache/', '/^_et_builder_module_features_cache/', '/^et_enqueued_post_fonts$/', '/^_et_pb_ab_/', // Divi
			'/^_fl_builder_.*_cache/',                         // Beaver Builder
			'/^_brizy_.*(cache|compiled)/',                    // Brizy
			'/^_siteorigin_panels_(css|cache)/',               // SiteOrigin
			'/^_wpsl_/', '/^_transient_/',
		) );
		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** Direct filesystem access (LivePull only touches files on this server: uploads and its backups). */
	public static function fs() {
		static $fs = null;
		if ( null === $fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$fs = new WP_Filesystem_Direct( null );
		}
		return $fs;
	}

	public static function post_type_ok( $type ) {
		return $type && ! in_array( $type, self::excluded_post_types(), true );
	}

	public static function taxonomy_ok( $tax ) {
		return $tax && ! in_array( $tax, self::excluded_taxonomies(), true );
	}

	/** Live sites: tidy the change log daily (local sites keep theirs for conflict checks). */
	public static function schedule() {
		$next = wp_next_scheduled( 'livepull_daily' );
		if ( '' === self::remote() && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'livepull_daily' );
		} elseif ( '' !== self::remote() && $next ) {
			wp_clear_scheduled_hook( 'livepull_daily' );
		}
	}

	public static function daily() {
		global $wpdb;
		if ( '' !== self::remote() ) {
			return;
		}
		$days = defined( 'LIVEPULL_LOG_DAYS' ) ? max( 7, (int) LIVEPULL_LOG_DAYS ) : 180;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	public static function maybe_install() {
		if ( get_option( 'livepull_version' ) !== self::VERSION ) {
			self::install();
		}
		self::detect_clone();
	}

	/** Random per-site key. Two sites sharing a key = one is a database copy of the other. */
	public static function site_key() {
		$key = get_option( 'livepull_site_key' );
		if ( ! $key ) {
			$key = md5( wp_generate_uuid4() );
			update_option( 'livepull_site_key', $key, true );
		}
		return $key;
	}

	/**
	 * Detect that this database was copied from another site (its URL changed since we last
	 * saw it). Everything already in the copied log happened on the source site, so it must not
	 * count as "changed locally", and the copy already contains it, so pulling starts after it.
	 * Stored as a hash so search-replace during a migration can't rewrite it.
	 */
	/**
	 * This site's address, normalised: home_url() switches between http:// and https://
	 * with the current request, which must not look like "this site moved".
	 */
	public static function fingerprint() {
		$home = strtolower( (string) get_option( 'home' ) );
		$home = preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $home ) );
		return md5( $home );
	}

	public static function detect_clone() {
		$fp     = self::fingerprint();
		$stored = get_option( 'livepull_fingerprint' );
		if ( $stored === $fp ) {
			return false;
		}
		update_option( 'livepull_fingerprint', $fp, true );
		if ( ! $stored ) {
			return false; // First install.
		}
		// Fingerprints from versions before 0.4.2 used home_url() as-is (with http/https).
		$raw = untrailingslashit( (string) get_option( 'home' ) );
		$alt = preg_replace( '#^http(s?)://#', '', $raw );
		foreach ( array( $raw, 'http://' . $alt, 'https://' . $alt, 'http://www.' . $alt, 'https://www.' . $alt ) as $variant ) {
			if ( md5( $variant ) === $stored ) {
				return false;
			}
		}
		self::mark_as_clone();
		return true;
	}

	public static function mark_as_clone() {
		global $wpdb;
		$floor = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', self::table() ) );
		update_option( 'livepull_clone_floor', $floor, false );
		update_option( 'livepull_cursor', $floor, false );
		update_option( 'livepull_site_key', md5( wp_generate_uuid4() ), true );
		update_option( 'livepull_clone_detected', gmdate( 'Y-m-d H:i:s' ), false );
		delete_option( 'livepull_conflicts' );
		delete_option( 'livepull_remote_home' );
	}

	public static function install( $backfill = true ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(10) NOT NULL,
				sync_id char(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(10) NOT NULL,
				source varchar(10) NOT NULL DEFAULT 'local',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY sync_id (sync_id),
				KEY source_id (source, id)
			) {$charset};"
		);
		self::site_key();
		if ( ! get_option( 'livepull_installed' ) ) {
			global $wpdb;
			$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i', $table ) );
			add_option( 'livepull_installed', $first ? $first : gmdate( 'Y-m-d H:i:s' ), '', false );
		}
		if ( ! get_option( 'livepull_fingerprint' ) ) {
			update_option( 'livepull_fingerprint', self::fingerprint(), true );
		}
		if ( $backfill ) {
			LivePull_Ids::backfill();
		}
		update_option( 'livepull_version', self::VERSION, true );
	}
}

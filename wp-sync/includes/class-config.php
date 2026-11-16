<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings, exclusions and install routine.
 *
 * wp-config.php constants:
 *   WPSYNC_SECRET              (both sites) shared key, 32+ characters.
 *   WPSYNC_REMOTE              (local only) live site URL, e.g. https://example.co.uk
 *   WPSYNC_REMOTE_BASIC_AUTH   (optional)   "user:pass" if live/staging is behind basic auth.
 *   WPSYNC_EXCLUDE_POST_TYPES  (optional)   comma list of extra post types to never sync.
 *   WPSYNC_EXCLUDE_TAXONOMIES  (optional)   comma list of extra taxonomies to never sync.
 *   WPSYNC_DEFAULT_AUTHOR      (optional)   local user login for posts whose author doesn't exist locally.
 */
final class WPSync_Config {

	const VERSION  = '0.4.1';
	const META_KEY = '_wpsync_id';

	/** Constants in wp-config.php win; otherwise the values saved on Tools → WP Sync. */
	public static function secret() {
		return defined( 'WPSYNC_SECRET' ) ? (string) WPSYNC_SECRET : (string) get_option( 'wpsync_secret', '' );
	}

	public static function remote() {
		$url = defined( 'WPSYNC_REMOTE' ) ? (string) WPSYNC_REMOTE : (string) get_option( 'wpsync_remote', '' );
		return untrailingslashit( trim( $url ) );
	}

	/**
	 * Key used when pulling from live. A key pasted on Tools → WP Sync wins, so an old
	 * WPSYNC_SECRET line in wp-config.php can't silently override it.
	 */
	public static function remote_secret() {
		return self::remote_secret_source()[0];
	}

	/** @return array{0:string,1:string} [ key, where it came from ] */
	public static function remote_secret_source() {
		if ( defined( 'WPSYNC_REMOTE_SECRET' ) ) {
			return array( (string) WPSYNC_REMOTE_SECRET, 'WPSYNC_REMOTE_SECRET in wp-config.php' );
		}
		$pasted = trim( (string) get_option( 'wpsync_remote_secret', '' ) );
		if ( '' !== $pasted ) {
			return array( $pasted, 'the key pasted on Tools → WP Sync' );
		}
		if ( defined( 'WPSYNC_SECRET' ) ) {
			return array( (string) WPSYNC_SECRET, 'WPSYNC_SECRET in wp-config.php' );
		}
		return array( (string) get_option( 'wpsync_secret', '' ), "this site's own key" );
	}

	/** "…a1b2" so keys can be compared on screen without showing them. */
	public static function key_hint( $key ) {
		$key = (string) $key;
		return strlen( $key ) >= 8 ? '…' . substr( $key, -4 ) : '(none)';
	}

	public static function page_size() {
		return defined( 'WPSYNC_PAGE_SIZE' ) ? max( 1, min( 200, (int) WPSYNC_PAGE_SIZE ) ) : 50;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpsync_log';
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
		$extra = defined( 'WPSYNC_EXCLUDE_POST_TYPES' ) ? array_map( 'trim', explode( ',', WPSYNC_EXCLUDE_POST_TYPES ) ) : array();
		return array_values( array_unique( array_filter( apply_filters( 'wpsync_excluded_post_types', array_merge( $defaults, $extra ) ) ) ) );
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
		$extra = defined( 'WPSYNC_EXCLUDE_TAXONOMIES' ) ? array_map( 'trim', explode( ',', WPSYNC_EXCLUDE_TAXONOMIES ) ) : array();
		return array_values( array_unique( array_filter( apply_filters( 'wpsync_excluded_taxonomies', array_merge( $defaults, $extra ) ) ) ) );
	}

	/** Meta keys that are never copied (editor locks, our own ID, ping queues). */
	public static function ignored_meta_keys() {
		return apply_filters(
			'wpsync_ignored_meta_keys',
			array( self::META_KEY, '_edit_lock', '_edit_last', '_encloseme', '_pingme' )
		);
	}

	/** Menus are pulled unless WPSYNC_PULL_MENUS is false. */
	public static function pull_menus() {
		return ! defined( 'WPSYNC_PULL_MENUS' ) || WPSYNC_PULL_MENUS;
	}

	/**
	 * Settings pulled from live: [ option name or pattern => true (whole value) | [ sub-keys ] ].
	 * Only things clients typically change themselves; theme/plugin settings stay yours.
	 * Extra options: define( 'WPSYNC_PULL_OPTIONS', 'option_a,option_b' ) or the wpsync_pulled_options filter.
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
		if ( defined( 'WPSYNC_PULL_OPTIONS' ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) WPSYNC_PULL_OPTIONS ) ) ) as $n ) {
				$o[ $n ] = true;
			}
		}
		return apply_filters( 'wpsync_pulled_options', $o );
	}

	/** @return true|array|null how an option is pulled (whole / sub-keys), or null if it isn't. */
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
		return apply_filters( 'wpsync_option_refs', $r, $name );
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

	public static function post_type_ok( $type ) {
		return $type && ! in_array( $type, self::excluded_post_types(), true );
	}

	public static function taxonomy_ok( $tax ) {
		return $tax && ! in_array( $tax, self::excluded_taxonomies(), true );
	}

	public static function maybe_install() {
		if ( get_option( 'wpsync_version' ) !== self::VERSION ) {
			self::install();
		}
		self::detect_clone();
	}

	/** Random per-site key. Two sites sharing a key = one is a database copy of the other. */
	public static function site_key() {
		$key = get_option( 'wpsync_site_key' );
		if ( ! $key ) {
			$key = md5( wp_generate_uuid4() );
			update_option( 'wpsync_site_key', $key, true );
		}
		return $key;
	}

	/**
	 * Detect that this database was copied from another site (its URL changed since we last
	 * saw it). Everything already in the copied log happened on the source site, so it must not
	 * count as "changed locally", and the copy already contains it, so pulling starts after it.
	 * Stored as a hash so search-replace during a migration can't rewrite it.
	 */
	public static function detect_clone() {
		$fp     = md5( untrailingslashit( home_url() ) );
		$stored = get_option( 'wpsync_fingerprint' );
		if ( $stored === $fp ) {
			return false;
		}
		update_option( 'wpsync_fingerprint', $fp, true );
		if ( ! $stored ) {
			return false; // First install.
		}
		self::mark_as_clone();
		return true;
	}

	public static function mark_as_clone() {
		global $wpdb;
		$floor = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::table() );
		update_option( 'wpsync_clone_floor', $floor, false );
		update_option( 'wpsync_cursor', $floor, false );
		update_option( 'wpsync_site_key', md5( wp_generate_uuid4() ), true );
		update_option( 'wpsync_clone_detected', gmdate( 'Y-m-d H:i:s' ), false );
		delete_option( 'wpsync_conflicts' );
		delete_option( 'wpsync_remote_home' );
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
		if ( ! get_option( 'wpsync_installed' ) ) {
			global $wpdb;
			$first = $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . $table );
			add_option( 'wpsync_installed', $first ? $first : gmdate( 'Y-m-d H:i:s' ), '', false );
		}
		if ( ! get_option( 'wpsync_fingerprint' ) ) {
			update_option( 'wpsync_fingerprint', md5( untrailingslashit( home_url() ) ), true );
		}
		if ( $backfill ) {
			WPSync_Ids::backfill();
		}
		update_option( 'wpsync_version', self::VERSION, true );
	}
}

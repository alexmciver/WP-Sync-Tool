<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Read-only endpoints on live, protected by the shared LIVEPULL_SECRET.
 *
 * Called via ?rest_route=/livepull/v1/... so they work with any permalink setting.
 */
final class LivePull_Rest {

	const NS = 'livepull/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache' ), 10, 3 );
		// Security plugins that switch the REST API off for visitors: let our routes through
		// when the request carries the right key (they are still checked again by authorise()).
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_keyed' ), 999 );
	}

	/**
	 * Is this request for one of LivePull's own routes? Looks only at the route itself (the
	 * path after the REST prefix, or the rest_route parameter), never the query string, so a
	 * key can't be used to get past another plugin's REST lock on other routes.
	 */
	private static function is_ours() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check; the request is authenticated by its key.
		if ( isset( $_GET['rest_route'] ) ) {
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
			$prefix = trailingslashit( (string) wp_parse_url( home_url( '/' . rest_get_url_prefix() . '/' ), PHP_URL_PATH ) ); // e.g. /wp-json/ or /sub/wp-json/
			$route  = 0 === strpos( $path, $prefix ) ? '/' . substr( $path, strlen( $prefix ) ) : '';
		}
		return 0 === strpos( '/' . ltrim( $route, '/' ), '/livepull/v1/' );
	}

	public static function allow_keyed( $result ) {
		if ( ! is_wp_error( $result ) || ! self::is_ours() ) {
			return $result;
		}
		$secret = LivePull_Config::secret();
		$given  = isset( $_SERVER['HTTP_X_LIVEPULL_KEY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LIVEPULL_KEY'] ) ) : '';
		return ( strlen( $secret ) >= 32 && '' !== $given && hash_equals( $secret, $given ) ) ? true : $result;
	}

	public static function no_cache( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS ) && $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
		}
		return $response;
	}

	public static function routes() {
		$auth = array( __CLASS__, 'authorise' );

		register_rest_route( self::NS, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $auth,
		) );

		register_rest_route( self::NS, '/changes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'changes' ),
			'permission_callback' => $auth,
			'args'                => array(
				'after' => array( 'type' => 'integer', 'default' => 0 ),
				'limit' => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		register_rest_route( self::NS, '/scan', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'scan' ),
			'permission_callback' => $auth,
			'args'                => array(
				'since' => array( 'type' => 'string', 'required' => true ),
				'after' => array( 'type' => 'integer', 'default' => 0 ),
				'limit' => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		$full = function ( $cb ) {
			return function ( WP_REST_Request $r ) use ( $cb ) {
				if ( ! LivePull_Full::allowed() ) {
					return new WP_Error( 'livepull_full_off', 'Full copies are switched off on this site.', array( 'status' => 403 ) );
				}
				return call_user_func( $cb, $r );
			};
		};
		register_rest_route( self::NS, '/db-tables', array( 'methods' => 'GET', 'callback' => $full( array( 'LivePull_Full', 'rest_tables' ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/db-rows', array( 'methods' => 'GET', 'callback' => $full( array( 'LivePull_Full', 'rest_rows' ) ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/files', array( 'methods' => 'GET', 'callback' => $full( array( 'LivePull_Full', 'rest_files' ) ), 'permission_callback' => $auth ) );

		register_rest_route( self::NS, '/object', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'object' ),
			'permission_callback' => $auth,
			'args'                => array(
				'type' => array( 'type' => 'string', 'enum' => array( 'post', 'term', 'option' ), 'required' => true ),
				'id'   => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	public static function authorise( WP_REST_Request $request ) {
		$secret = LivePull_Config::secret();
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error( 'livepull_not_configured', 'LIVEPULL_SECRET is missing or shorter than 32 characters on this site.', array( 'status' => 503 ) );
		}
		$given = (string) $request->get_header( 'x-livepull-key' );
		if ( '' === $given || ! hash_equals( $secret, $given ) ) {
			return new WP_Error( 'livepull_forbidden', 'Invalid sync key.', array( 'status' => 403 ) );
		}
		LivePull_Config::maybe_install();
		return true;
	}

	private static function limit( WP_REST_Request $r ) {
		return max( 1, min( 200, (int) $r['limit'] ) );
	}

	public static function status() {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		LivePull_Ids::maybe_backfill();
		return array(
			'version'        => LivePull_Config::VERSION,
			'site_key'       => LivePull_Config::site_key(),
			'home'           => home_url(),
			'siteurl'        => site_url(),
			'upload_baseurl' => $uploads['baseurl'],
			'last_id'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', LivePull_Config::table() ) ),
			'min_id'         => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i', LivePull_Config::table() ) ),
			'recording_since' => (string) get_option( 'livepull_installed', '' ),
			'full_copy'       => LivePull_Full::allowed(),
			'wp_version'      => get_bloginfo( 'version' ),
			'abspath'         => LivePull_Full::allowed() ? ABSPATH : '',
		);
	}

	/** Changes recorded in the log after a cursor. */
	public static function changes( WP_REST_Request $r ) {
		global $wpdb;
		$after = max( 0, (int) $r['after'] );
		$limit = self::limit( $r );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_type, sync_id, object_id, action FROM %i
				 WHERE id > %d AND source = 'local' AND action IN ('upsert','delete')
				 ORDER BY id ASC LIMIT %d",
				LivePull_Config::table(),
				$after,
				$limit + 1
			)
		);
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$last_id  = $rows ? (int) end( $rows )->id : $after;

		// Latest action per object wins within the page.
		$latest = array();
		foreach ( $rows as $row ) {
			$latest[ $row->object_type . ':' . $row->sync_id ] = $row;
		}

		$items = array();
		foreach ( $latest as $row ) {
			$item = self::payload_for_row( $row );
			if ( $item ) {
				$items[] = $item;
			}
		}

		return array( 'items' => $items, 'last_id' => $last_id, 'has_more' => $has_more );
	}

	/** sync ID => option name, for every pulled option on this site. */
	private static function option_map() {
		$map = array();
		foreach ( LivePull_Config::pulled_option_names() as $n ) {
			$map[ LivePull_Config::option_sid( $n ) ] = $n;
		}
		return $map;
	}

	private static function payload_for_row( $row ) {
		if ( 'option' === $row->object_type ) {
			$map = self::option_map();
			if ( isset( $map[ $row->sync_id ] ) ) {
				return LivePull_Exporter::option( $map[ $row->sync_id ] );
			}
			return 'delete' === $row->action ? LivePull_Exporter::tombstone( 'option', $row->sync_id ) : null;
		}
		if ( 'delete' === $row->action ) {
			// Only a tombstone if it really is gone (it may have been restored since).
			$map = LivePull_Ids::map( $row->object_type );
			if ( isset( $map[ $row->sync_id ] ) ) {
				$row->action = 'upsert';
				$row->object_id = $map[ $row->sync_id ];
			} else {
				return LivePull_Exporter::tombstone( $row->object_type, $row->sync_id );
			}
		}
		// Look the object up by sync ID in case its row ID is stale. If it no longer exists,
		// it was deleted (whatever the log says last), so send a deletion.
		$id = (int) $row->object_id;
		if ( 'post' === $row->object_type ) {
			if ( LivePull_Ids::for_post( $id, false ) !== $row->sync_id ) {
				$map = LivePull_Ids::map( 'post' );
				$id  = isset( $map[ $row->sync_id ] ) ? $map[ $row->sync_id ] : 0;
			}
			return ( $id && get_post( $id ) ) ? LivePull_Exporter::post( $id ) : LivePull_Exporter::tombstone( 'post', $row->sync_id );
		}
		if ( LivePull_Ids::for_term( $id, false ) !== $row->sync_id ) {
			$map = LivePull_Ids::map( 'term' );
			$id  = isset( $map[ $row->sync_id ] ) ? $map[ $row->sync_id ] : 0;
		}
		$term = $id ? get_term( $id ) : null;
		return ( $term && ! is_wp_error( $term ) ) ? LivePull_Exporter::term( $id ) : LivePull_Exporter::tombstone( 'term', $row->sync_id );
	}

	/**
	 * Catch-up mode for sites where the plugin was installed on live AFTER the local copy
	 * was taken: everything modified since a date (posts), plus all terms on the first page.
	 */
	public static function scan( WP_REST_Request $r ) {
		global $wpdb;
		$since = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['since'] ) ? strtotime( (string) $r['since'] . ' 00:00:00 UTC' ) : false;
		if ( false === $since ) {
			return new WP_Error( 'livepull_bad_date', 'Use YYYY-MM-DD for since.', array( 'status' => 400 ) );
		}
		$after = max( 0, (int) $r['after'] );
		$limit = self::limit( $r );
		$types = get_post_types();
		$types = array_values( array_filter( $types, array( 'LivePull_Config', 'post_type_ok' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is one %s placeholder per post type.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ID > %d AND post_modified_gmt >= %s AND post_status <> 'auto-draft' AND post_type IN ($in)
				 ORDER BY ID ASC LIMIT %d",
				array_merge( array( $after, gmdate( 'Y-m-d H:i:s', $since ) ), $types, array( $limit + 1 ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_more = count( $ids ) > $limit;
		$ids      = array_slice( $ids, 0, $limit );

		$items = array();
		if ( 0 === $after ) {
			foreach ( LivePull_Config::pulled_option_names() as $n ) {
				$item = LivePull_Exporter::option( $n );
				if ( $item && empty( $item['deleted'] ) ) {
					$items[] = $item;
				}
			}
			$taxes = array_values( array_filter( get_taxonomies(), array( 'LivePull_Config', 'taxonomy_ok' ) ) );
			$terms = get_terms( array( 'taxonomy' => $taxes, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $tid ) {
					$item = LivePull_Exporter::term( $tid );
					if ( $item ) {
						$items[] = $item;
					}
				}
			}
		}
		foreach ( $ids as $id ) {
			$item = LivePull_Exporter::post( $id );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return array(
			'items'    => $items,
			'last_id'  => $ids ? (int) end( $ids ) : $after,
			'has_more' => $has_more,
		);
	}

	public static function object( WP_REST_Request $r ) {
		$type = $r['type'];
		if ( 'option' === $type ) {
			$map  = self::option_map();
			$item = isset( $map[ (string) $r['id'] ] ) ? LivePull_Exporter::option( $map[ (string) $r['id'] ] ) : null;
			return $item ? $item : new WP_Error( 'livepull_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$map  = LivePull_Ids::map( $type );
		$sid  = (string) $r['id'];
		if ( ! isset( $map[ $sid ] ) ) {
			return new WP_Error( 'livepull_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$item = 'post' === $type ? LivePull_Exporter::post( $map[ $sid ] ) : LivePull_Exporter::term( $map[ $sid ] );
		if ( ! $item ) {
			return new WP_Error( 'livepull_excluded', 'This object type is not synced.', array( 'status' => 404 ) );
		}
		return $item;
	}
}

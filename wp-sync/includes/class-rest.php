<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only endpoints on live, protected by the shared WPSYNC_SECRET.
 *
 * Called via ?rest_route=/wpsync/v1/... so they work with any permalink setting.
 */
final class WPSync_Rest {

	const NS = 'wpsync/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
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

		register_rest_route( self::NS, '/object', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'object' ),
			'permission_callback' => $auth,
			'args'                => array(
				'type' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'required' => true ),
				'id'   => array( 'type' => 'string', 'required' => true ),
			),
		) );
	}

	public static function authorise( WP_REST_Request $request ) {
		$secret = WPSync_Config::secret();
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error( 'wpsync_not_configured', 'WPSYNC_SECRET is missing or shorter than 32 characters on this site.', array( 'status' => 503 ) );
		}
		$given = (string) $request->get_header( 'x-wpsync-key' );
		if ( '' === $given || ! hash_equals( $secret, $given ) ) {
			return new WP_Error( 'wpsync_forbidden', 'Invalid sync key.', array( 'status' => 403 ) );
		}
		WPSync_Config::maybe_install();
		return true;
	}

	private static function limit( WP_REST_Request $r ) {
		return max( 1, min( 200, (int) $r['limit'] ) );
	}

	public static function status() {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		WPSync_Ids::backfill();
		return array(
			'version'        => WPSync_Config::VERSION,
			'site_key'       => WPSync_Config::site_key(),
			'home'           => home_url(),
			'siteurl'        => site_url(),
			'upload_baseurl' => $uploads['baseurl'],
			'last_id'        => (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . WPSync_Config::table() ),
		);
	}

	/** Changes recorded in the log after a cursor. */
	public static function changes( WP_REST_Request $r ) {
		global $wpdb;
		$after = max( 0, (int) $r['after'] );
		$limit = self::limit( $r );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, object_type, sync_id, object_id, action FROM ' . WPSync_Config::table() . "
				 WHERE id > %d AND source = 'local' AND action IN ('upsert','delete')
				 ORDER BY id ASC LIMIT %d",
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

	private static function payload_for_row( $row ) {
		if ( 'delete' === $row->action ) {
			// Only a tombstone if it really is gone (it may have been restored since).
			$map = WPSync_Ids::map( $row->object_type );
			if ( isset( $map[ $row->sync_id ] ) ) {
				$row->action = 'upsert';
				$row->object_id = $map[ $row->sync_id ];
			} else {
				return WPSync_Exporter::tombstone( $row->object_type, $row->sync_id );
			}
		}
		// Look the object up by sync ID in case its row ID is stale.
		$id = (int) $row->object_id;
		if ( 'post' === $row->object_type ) {
			if ( WPSync_Ids::for_post( $id, false ) !== $row->sync_id ) {
				$map = WPSync_Ids::map( 'post' );
				$id  = isset( $map[ $row->sync_id ] ) ? $map[ $row->sync_id ] : 0;
			}
			return $id ? WPSync_Exporter::post( $id ) : null;
		}
		if ( WPSync_Ids::for_term( $id, false ) !== $row->sync_id ) {
			$map = WPSync_Ids::map( 'term' );
			$id  = isset( $map[ $row->sync_id ] ) ? $map[ $row->sync_id ] : 0;
		}
		return $id ? WPSync_Exporter::term( $id ) : null;
	}

	/**
	 * Catch-up mode for sites where the plugin was installed on live AFTER the local copy
	 * was taken: everything modified since a date (posts), plus all terms on the first page.
	 */
	public static function scan( WP_REST_Request $r ) {
		global $wpdb;
		$since = strtotime( (string) $r['since'] . ' UTC' );
		if ( ! $since ) {
			return new WP_Error( 'wpsync_bad_date', 'Use YYYY-MM-DD for since.', array( 'status' => 400 ) );
		}
		$after = max( 0, (int) $r['after'] );
		$limit = self::limit( $r );
		$types = get_post_types();
		$types = array_values( array_filter( $types, array( 'WPSync_Config', 'post_type_ok' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ID > %d AND post_modified_gmt >= %s AND post_status <> 'auto-draft' AND post_type IN ($in)
				 ORDER BY ID ASC LIMIT %d",
				array_merge( array( $after, gmdate( 'Y-m-d H:i:s', $since ) ), $types, array( $limit + 1 ) )
			)
		);
		$has_more = count( $ids ) > $limit;
		$ids      = array_slice( $ids, 0, $limit );

		$items = array();
		if ( 0 === $after ) {
			$taxes = array_values( array_filter( get_taxonomies(), array( 'WPSync_Config', 'taxonomy_ok' ) ) );
			$terms = get_terms( array( 'taxonomy' => $taxes, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $tid ) {
					$item = WPSync_Exporter::term( $tid );
					if ( $item ) {
						$items[] = $item;
					}
				}
			}
		}
		foreach ( $ids as $id ) {
			$item = WPSync_Exporter::post( $id );
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
		$map  = WPSync_Ids::map( $type );
		$sid  = (string) $r['id'];
		if ( ! isset( $map[ $sid ] ) ) {
			return new WP_Error( 'wpsync_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$item = 'post' === $type ? WPSync_Exporter::post( $map[ $sid ] ) : WPSync_Exporter::term( $map[ $sid ] );
		if ( ! $item ) {
			return new WP_Error( 'wpsync_excluded', 'This object type is not synced.', array( 'status' => 404 ) );
		}
		return $item;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Records every content change to the wpsync_log table.
 *
 * On live, the log is the list of things to pull. On local, it records what the
 * developer has changed, so the importer can spot conflicts instead of overwriting.
 *
 * Rows only say "this object changed"; the importer always fetches the object's
 * current state, so we don't need to log every individual field change.
 */
final class WPSync_Logger {

	private static $suspended = 0;
	private static $seen      = array();
	private static $deleting  = array(); // Objects being deleted: their meta clean-up isn't an "edit".

	public static function init() {
		// Make sure pre-existing content is stamped from its ORIGINAL data before an edit changes it.
		add_action( 'pre_post_update', array( __CLASS__, 'stamp_post' ), 1 );
		add_action( 'edit_terms', array( __CLASS__, 'stamp_term' ), 1 );

		add_action( 'save_post', array( __CLASS__, 'touch_post' ), 999 );
		add_action( 'add_attachment', array( __CLASS__, 'touch_post' ), 999 );
		add_action( 'edit_attachment', array( __CLASS__, 'touch_post' ), 999 );
		add_action( 'set_object_terms', array( __CLASS__, 'touch_post' ), 999 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_post' ), 1 );

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'post_meta_changed' ), 999, 3 );
		}

		add_action( 'created_term', array( __CLASS__, 'touch_term' ), 999 );
		add_action( 'edited_term', array( __CLASS__, 'touch_term' ), 999 );
		add_action( 'pre_delete_term', array( __CLASS__, 'delete_term' ), 1 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'term_meta_changed' ), 999, 3 );
		}

		// Pulled settings (site title, homepage, menu locations…).
		add_action( 'added_option', array( __CLASS__, 'option_added' ), 999, 2 );
		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 999, 3 );
		add_action( 'deleted_option', array( __CLASS__, 'option_deleted' ), 999 );

		// Duplicate Post / similar plugins copy all meta, including our ID. Give copies their own.
		add_action( 'added_post_meta', array( __CLASS__, 'dedupe_post_id' ), 1, 4 );
		add_filter( 'duplicate_post_excludelist_filter', array( __CLASS__, 'duplicate_post_exclude' ) );
	}

	public static function suspend() {
		self::$suspended++;
	}

	public static function resume() {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	public static function is_suspended() {
		return self::$suspended > 0;
	}

	/* ---------------------------------------------------------------- Posts */

	public static function stamp_post( $post_id ) {
		if ( ! self::$suspended ) {
			WPSync_Ids::for_post( $post_id );
		}
	}

	private static function post_loggable( $post ) {
		return $post
			&& 'auto-draft' !== $post->post_status
			&& WPSync_Config::post_type_ok( $post->post_type );
	}

	public static function touch_post( $post_id ) {
		if ( self::$suspended || isset( self::$deleting[ 'post:' . (int) $post_id ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! self::post_loggable( $post ) ) {
			return;
		}
		$sid = WPSync_Ids::for_post( $post->ID );
		self::write( 'post', $sid, $post->ID, 'upsert' );
	}

	public static function delete_post( $post_id ) {
		if ( self::$suspended ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! self::post_loggable( $post ) ) {
			return;
		}
		$sid = WPSync_Ids::for_post( $post->ID, false ) ?: WPSync_Ids::post_formula( $post->ID, $post->post_type, $post->post_date );
		self::$deleting[ 'post:' . (int) $post->ID ] = true;
		self::write( 'post', $sid, $post->ID, 'delete' );
	}

	public static function post_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( self::$suspended || in_array( $meta_key, WPSync_Config::ignored_meta_keys(), true ) ) {
			return;
		}
		self::touch_post( $object_id );
	}

	public static function dedupe_post_id( $meta_id, $object_id, $meta_key, $value ) {
		if ( WPSync_Config::META_KEY !== $meta_key || self::$suspended ) {
			return;
		}
		global $wpdb;
		$other = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 1",
				WPSync_Config::META_KEY,
				$value,
				$object_id
			)
		);
		if ( $other ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => WPSync_Ids::fresh_id( 'post', $object_id, $value ) ), array( 'meta_id' => $meta_id ) );
			wp_cache_delete( $object_id, 'post_meta' );
		}
	}

	public static function duplicate_post_exclude( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = WPSync_Config::META_KEY;
		return $list;
	}

	/* -------------------------------------------------------------- Options */

	public static function option_added( $name, $value ) {
		if ( ! self::$suspended && null !== WPSync_Config::option_spec( $name ) ) {
			self::write( 'option', WPSync_Config::option_sid( $name ), 0, 'upsert' );
		}
	}

	public static function option_updated( $name, $old, $new ) {
		if ( self::$suspended ) {
			return;
		}
		$spec = WPSync_Config::option_spec( $name );
		if ( null === $spec ) {
			return;
		}
		if ( is_array( $spec ) ) {
			// Only log when one of the pulled sub-keys changed (e.g. menu locations, not every Customizer tweak).
			$changed = false;
			foreach ( $spec as $k ) {
				$a = is_array( $old ) && isset( $old[ $k ] ) ? $old[ $k ] : null;
				$b = is_array( $new ) && isset( $new[ $k ] ) ? $new[ $k ] : null;
				if ( $a != $b ) { // phpcs:ignore -- loose on purpose ("5" vs 5).
					$changed = true;
				}
			}
			if ( ! $changed ) {
				return;
			}
		}
		self::write( 'option', WPSync_Config::option_sid( $name ), 0, 'upsert' );
	}

	public static function option_deleted( $name ) {
		if ( ! self::$suspended && true === WPSync_Config::option_spec( $name ) ) {
			self::write( 'option', WPSync_Config::option_sid( $name ), 0, 'delete' );
		}
	}

	/* ---------------------------------------------------------------- Terms */

	public static function stamp_term( $term_id ) {
		if ( ! self::$suspended ) {
			WPSync_Ids::for_term( $term_id );
		}
	}

	public static function touch_term( $term_id ) {
		if ( self::$suspended || isset( self::$deleting[ 'term:' . (int) $term_id ] ) ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) || ! WPSync_Config::taxonomy_ok( $term->taxonomy ) ) {
			return;
		}
		self::write( 'term', WPSync_Ids::for_term( $term->term_id ), $term->term_id, 'upsert' );
	}

	public static function delete_term( $term_id ) {
		if ( self::$suspended ) {
			return;
		}
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) || ! WPSync_Config::taxonomy_ok( $term->taxonomy ) ) {
			return;
		}
		$sid = WPSync_Ids::for_term( $term->term_id, false ) ?: WPSync_Ids::term_formula( $term->term_id, $term->taxonomy, $term->slug );
		self::$deleting[ 'term:' . (int) $term->term_id ] = true;
		self::write( 'term', $sid, $term->term_id, 'delete' );
	}

	public static function term_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( self::$suspended || in_array( $meta_key, WPSync_Config::ignored_meta_keys(), true ) ) {
			return;
		}
		self::touch_term( $object_id );
	}

	/* ---------------------------------------------------------------- Storage */

	private static function write( $type, $sid, $object_id, $action ) {
		if ( ! $sid ) {
			return;
		}
		// One "upsert" row per object per request is plenty.
		$key = $type . ':' . $sid . ':' . $action;
		if ( 'upsert' === $action && isset( self::$seen[ $key ] ) ) {
			return;
		}
		self::$seen[ $key ] = true;
		self::insert( $type, $sid, $object_id, $action, 'local' );
	}

	/** Also used by the importer to record "synced" markers. */
	public static function insert( $type, $sid, $object_id, $action, $source ) {
		global $wpdb;
		if ( get_option( 'wpsync_version' ) !== WPSync_Config::VERSION ) {
			WPSync_Config::install();
		}
		$wpdb->insert(
			WPSync_Config::table(),
			array(
				'object_type' => $type,
				'sync_id'     => $sid,
				'object_id'   => (int) $object_id,
				'action'      => $action,
				'source'      => $source,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}

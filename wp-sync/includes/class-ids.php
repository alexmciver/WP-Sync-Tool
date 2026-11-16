<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stable cross-site IDs.
 *
 * Database IDs clash between sites (post 512 locally is not post 512 on live), so every
 * post, attachment and term gets a 32-character sync ID stored as meta.
 *
 * For content that existed when the database was cloned, the ID is derived from data
 * that is identical on both copies (original ID + type + creation date), so live and
 * local independently arrive at the same value without talking to each other.
 * Content created later gets its own value, which is unique in practice because it
 * includes the creation timestamp.
 */
final class WPSync_Ids {

	public static function post_formula( $id, $type, $date ) {
		return md5( 'post|' . (int) $id . '|' . $type . '|' . $date );
	}

	public static function term_formula( $id, $taxonomy, $slug ) {
		return md5( 'term|' . (int) $id . '|' . $taxonomy . '|' . $slug );
	}

	public static function for_post( $post_id, $create = true ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return null;
		}
		$sid = get_post_meta( $post_id, WPSync_Config::META_KEY, true );
		if ( $sid ) {
			return $sid;
		}
		if ( ! $create ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$sid = self::post_formula( $post->ID, $post->post_type, $post->post_date );
		add_post_meta( $post_id, WPSync_Config::META_KEY, $sid, true );
		return get_post_meta( $post_id, WPSync_Config::META_KEY, true ) ?: $sid;
	}

	public static function for_term( $term_id, $create = true ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return null;
		}
		$sid = get_term_meta( $term_id, WPSync_Config::META_KEY, true );
		if ( $sid ) {
			return $sid;
		}
		if ( ! $create ) {
			return null;
		}
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$sid = self::term_formula( $term->term_id, $term->taxonomy, $term->slug );
		add_term_meta( $term_id, WPSync_Config::META_KEY, $sid, true );
		return get_term_meta( $term_id, WPSync_Config::META_KEY, true ) ?: $sid;
	}

	/**
	 * Stamp every post and term that has no sync ID yet, in bulk SQL, and fix any
	 * duplicates (e.g. from "Duplicate Post" plugins copying all meta).
	 *
	 * @return array{posts:int,terms:int,dupes:int}
	 */
	public static function backfill() {
		global $wpdb;
		$key = WPSync_Config::META_KEY;

		$posts = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 SELECT p.ID, %s, MD5(CONCAT('post|', p.ID, '|', p.post_type, '|', p.post_date))
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE m.meta_id IS NULL AND p.post_type <> 'revision' AND p.post_status <> 'auto-draft'",
				$key,
				$key
			)
		);

		$terms = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value)
				 SELECT t.term_id, %s, MD5(CONCAT('term|', t.term_id, '|', MIN(tt.taxonomy), '|', t.slug))
				 FROM {$wpdb->terms} t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 LEFT JOIN {$wpdb->termmeta} m ON m.term_id = t.term_id AND m.meta_key = %s
				 WHERE m.meta_id IS NULL
				 GROUP BY t.term_id, t.slug",
				$key,
				$key
			)
		);

		// Remove IDs left behind by deleted objects (older versions could leave these).
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.ID IS NULL", $key ) );
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id WHERE m.meta_key = %s AND t.term_id IS NULL", $key ) );

		$dupes = self::fix_duplicates( 'post' ) + self::fix_duplicates( 'term' );

		if ( $posts || $terms || $dupes ) {
			wp_cache_flush();
		}
		return array( 'posts' => $posts, 'terms' => $terms, 'dupes' => $dupes );
	}

	private static function fix_duplicates( $kind ) {
		global $wpdb;
		$table = 'post' === $kind ? $wpdb->postmeta : $wpdb->termmeta;
		$col   = 'post' === $kind ? 'post_id' : 'term_id';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, GROUP_CONCAT(meta_id ORDER BY {$col} ASC) AS meta_ids, GROUP_CONCAT({$col} ORDER BY {$col} ASC) AS object_ids
				 FROM {$table} WHERE meta_key = %s GROUP BY meta_value HAVING COUNT(*) > 1",
				WPSync_Config::META_KEY
			)
		);
		$fixed = 0;
		foreach ( $rows as $row ) {
			$meta_ids   = array_map( 'intval', explode( ',', $row->meta_ids ) );
			$object_ids = array_map( 'intval', explode( ',', $row->object_ids ) );
			// The oldest object keeps the ID; the copies get fresh ones.
			for ( $i = 1, $n = count( $meta_ids ); $i < $n; $i++ ) {
				$wpdb->update( $table, array( 'meta_value' => self::fresh_id( $kind, $object_ids[ $i ], $row->meta_value ) ), array( 'meta_id' => $meta_ids[ $i ] ) );
				$fixed++;
			}
		}
		return $fixed;
	}

	/** A new ID for an object whose current ID is shared with another object. */
	public static function fresh_id( $kind, $object_id, $current ) {
		if ( 'post' === $kind ) {
			$p   = get_post( $object_id );
			$sid = $p ? self::post_formula( $p->ID, $p->post_type, $p->post_date ) : '';
		} else {
			$t   = get_term( $object_id );
			$sid = ( $t && ! is_wp_error( $t ) ) ? self::term_formula( $t->term_id, $t->taxonomy, $t->slug ) : '';
		}
		if ( ! $sid || $sid === $current ) {
			$sid = md5( wp_generate_uuid4() );
		}
		return $sid;
	}

	/** Backfill at most every 10 minutes (it scans every post and term). */
	public static function maybe_backfill() {
		if ( get_transient( 'wpsync_backfilled' ) ) {
			return;
		}
		self::backfill();
		set_transient( 'wpsync_backfilled', 1, 10 * MINUTE_IN_SECONDS );
	}

	/** @return array<string,int> sync ID => local object ID */
	public static function map( $kind ) {
		global $wpdb;
		if ( 'post' === $kind ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS sid, post_id AS id FROM {$wpdb->postmeta} WHERE meta_key = %s", WPSync_Config::META_KEY ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS sid, term_id AS id FROM {$wpdb->termmeta} WHERE meta_key = %s", WPSync_Config::META_KEY ) );
		}
		$map = array();
		foreach ( $rows as $r ) {
			$map[ $r->sid ] = (int) $r->id;
		}
		return $map;
	}
}

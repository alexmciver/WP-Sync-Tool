<?php
defined( 'ABSPATH' ) || exit;

/**
 * Applies payloads from live to this (local) site.
 *
 * Rules:
 *  - New on live            → created locally.
 *  - Changed on live only   → updated locally.
 *  - Changed on live AND changed locally since it was last pulled → CONFLICT: left alone and
 *    reported, unless --theirs / `wp sync take` is used.
 *  - Deleted on live        → deleted locally (unless changed locally → conflict).
 *
 * Runs in two passes: first every object is created/updated (so everything has a local ID),
 * then every ID reference (parents, featured images, galleries, terms, ACF fields, blocks…)
 * is rewritten to local IDs. Referenced objects missing locally are fetched on demand.
 */
final class WPSync_Importer {

	private $client;
	private $opts;
	private $maps          = array( 'post' => array(), 'term' => array() );
	private $local_changed = array();
	private $handled       = array();
	private $ref_queue     = array();
	private $fetching      = array();
	private $url_from      = array();
	private $url_to        = array();
	private $authors       = array();

	public $report = array(
		'created'   => array(),
		'updated'   => array(),
		'deleted'   => array(),
		'conflicts' => array(),
		'skipped'   => array(),
		'errors'    => array(),
		'files'     => 0,
		'downloads' => array(), // Queued media files when 'defer_files' is on.
	);

	/**
	 * @param array $remote  Response of the live /status endpoint.
	 * @param array $opts    dry_run, theirs, redownload, url_replace, force (array of "type:sync_id").
	 */
	public function __construct( WPSync_Client $client, array $remote, array $opts ) {
		$this->client = $client;
		$this->opts   = wp_parse_args( $opts, array( 'dry_run' => false, 'theirs' => false, 'redownload' => false, 'url_replace' => true, 'force' => array(), 'defer_files' => false ) );

		if ( $this->opts['url_replace'] ) {
			$this->build_url_map( $remote );
		}
		$this->maps['post']    = WPSync_Ids::map( 'post' );
		$this->maps['term']    = WPSync_Ids::map( 'term' );
		$this->local_changed   = self::locally_changed();
	}

	/* ================================================================ State */

	/**
	 * Objects edited on THIS site since they were last pulled from live.
	 *
	 * @return array<string,string> "type:sync_id" => last local action (upsert|delete)
	 */
	public static function locally_changed() {
		global $wpdb;
		$table  = WPSync_Config::table();
		$synced = array();
		foreach ( $wpdb->get_results( "SELECT object_type, sync_id, MAX(id) AS last FROM {$table} WHERE source = 'sync' GROUP BY object_type, sync_id" ) as $r ) {
			$synced[ $r->object_type . ':' . $r->sync_id ] = (int) $r->last;
		}
		$changed = array();
		$floor   = (int) get_option( 'wpsync_clone_floor', 0 ); // Rows up to here were copied from live.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, sync_id, action FROM {$table} WHERE source = 'local' AND id > %d ORDER BY id ASC", $floor ) ) as $r ) {
			$key = $r->object_type . ':' . $r->sync_id;
			if ( (int) $r->id > ( isset( $synced[ $key ] ) ? $synced[ $key ] : 0 ) ) {
				$changed[ $key ] = $r->action;
			} else {
				unset( $changed[ $key ] );
			}
		}
		return $changed;
	}

	private function build_url_map( array $remote ) {
		$uploads = wp_get_upload_dir();
		$pairs   = array();
		if ( ! empty( $remote['upload_baseurl'] ) ) {
			$pairs[ untrailingslashit( $remote['upload_baseurl'] ) ] = untrailingslashit( $uploads['baseurl'] );
		}
		foreach ( array( 'home', 'siteurl' ) as $k ) {
			if ( empty( $remote[ $k ] ) ) {
				continue;
			}
			$remote_url = untrailingslashit( $remote[ $k ] );
			$local_url  = untrailingslashit( 'home' === $k ? home_url() : site_url() );
			$host       = preg_replace( '#^https?://#', '', $remote_url );
			$pairs[ 'https://' . $host ] = $local_url;
			$pairs[ 'http://' . $host ]  = $local_url;
		}
		// Longest first so upload paths win over the bare domain.
		uksort( $pairs, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		foreach ( $pairs as $from => $to ) {
			if ( $from === $to ) {
				continue;
			}
			$this->url_from[] = $from;
			$this->url_to[]   = $to;
			// JSON-escaped form used by blocks and page builders.
			$this->url_from[] = str_replace( '/', '\/', $from );
			$this->url_to[]   = str_replace( '/', '\/', $to );
		}
	}

	/** Replace live URLs with local ones inside strings / arrays (never inside raw serialized strings). */
	private function urls( $value ) {
		if ( ! $this->url_from ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return str_replace( $this->url_from, $this->url_to, $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->urls( $v );
			}
		}
		return $value;
	}

	/* ================================================================== Run */

	public function run( array $items ) {
		// Terms first, then media, then everything else; deletions last.
		$items = self::sort_items( $items );

		WPSync_Logger::suspend();
		kses_remove_filters();
		remove_action( 'post_updated', 'wp_save_post_revision', 10 );
		// Never let a pull trigger outgoing side effects from the local site.
		remove_action( 'publish_post', '_publish_post_hook', 5 ); // pingbacks/enclosures
		add_filter( 'pre_wp_mail', '__return_false', 999 );
		add_filter( 'woocommerce_webhook_should_deliver', '__return_false', 999 );
		wp_defer_term_counting( true );

		try {
			foreach ( $items as $item ) {
				$this->handle( $item );
			}
			// The queue can grow while we go (fetched dependencies), so no foreach.
			for ( $i = 0; $i < count( $this->ref_queue ); $i++ ) {
				list( $item, $id ) = $this->ref_queue[ $i ];
				$this->apply_refs( $item, $id );
			}
		} finally {
			wp_defer_term_counting( false );
			add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
			add_action( 'publish_post', '_publish_post_hook', 5, 1 );
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
			remove_filter( 'woocommerce_webhook_should_deliver', '__return_false', 999 );
			WPSync_Logger::resume();
		}
		return $this->report;
	}

	/** Terms first, then media, then everything else; deletions last. */
	public static function sort_items( array $items ) {
		$i = 0;
		foreach ( $items as &$item ) {
			$item['__order'] = $i++;
		}
		unset( $item );
		usort( $items, function ( $a, $b ) {
			$r = self::rank( $a ) - self::rank( $b );
			return $r ? $r : $a['__order'] - $b['__order'];
		} );
		foreach ( $items as &$item ) {
			unset( $item['__order'] );
		}
		return $items;
	}

	private static function rank( $item ) {
		if ( ! empty( $item['deleted'] ) ) {
			return 9;
		}
		if ( 'term' === $item['type'] ) {
			return 0;
		}
		if ( 'option' === $item['type'] ) {
			return 3; // Settings last: they point at pages/menus pulled in the same batch.
		}
		return ( isset( $item['post']['post_type'] ) && 'attachment' === $item['post']['post_type'] ) ? 1 : 2;
	}

	private function label( $item, $local_id = 0 ) {
		if ( 'option' === $item['type'] ) {
			$name = isset( $item['option']['name'] ) ? $item['option']['name'] : self::option_name( $item['sync_id'] );
			$nice = array( 'blogname' => 'Site title', 'blogdescription' => 'Tagline', 'show_on_front' => 'Homepage displays', 'page_on_front' => 'Homepage', 'page_for_posts' => 'Posts page', 'sticky_posts' => 'Sticky posts', 'site_icon' => 'Site icon' );
			if ( 0 === strpos( (string) $name, 'theme_mods_' ) ) {
				return 'setting "Menu locations / logo"';
			}
			return 'setting "' . ( isset( $nice[ $name ] ) ? $nice[ $name ] : $name ) . '"';
		}
		if ( 'term' === $item['type'] ) {
			$t = isset( $item['term'] ) ? $item['term'] : array();
			return sprintf( '%s "%s"%s', isset( $t['taxonomy'] ) ? $t['taxonomy'] : 'term', isset( $t['name'] ) ? $t['name'] : $item['sync_id'], $local_id ? " (local #{$local_id})" : '' );
		}
		$p = isset( $item['post'] ) ? $item['post'] : array();
		if ( ! $p && $local_id ) {
			$post = get_post( $local_id );
			$p    = $post ? array( 'post_type' => $post->post_type, 'post_title' => $post->post_title ) : array();
		}
		return sprintf( '%s "%s"%s', isset( $p['post_type'] ) ? $p['post_type'] : 'post', isset( $p['post_title'] ) && '' !== $p['post_title'] ? $p['post_title'] : '(no title)', $local_id ? " (local #{$local_id})" : '' );
	}

	public static function option_name( $sid ) {
		foreach ( WPSync_Config::pulled_option_names() as $n ) {
			if ( WPSync_Config::option_sid( $n ) === $sid ) {
				return $n;
			}
		}
		return '';
	}

	/** Settings: applied in the refs pass so page/menu IDs inside them are already local. */
	private function handle_option( array $item, $key ) {
		$forced   = $this->opts['theirs'] || in_array( $key, (array) $this->opts['force'], true );
		$conflict = isset( $this->local_changed[ $key ] ) && ! $forced;
		$this->handled[ $key ] = 0;
		if ( $conflict ) {
			$this->conflict( $key, $item, 0, ! empty( $item['deleted'] ) ? 'removed on live, but you changed it locally' : 'changed on live AND changed locally' );
			return 0;
		}
		if ( ! empty( $item['deleted'] ) ) {
			$name = self::option_name( $item['sync_id'] );
			if ( $name && true === WPSync_Config::option_spec( $name ) && false !== get_option( $name, false ) ) {
				if ( ! $this->opts['dry_run'] ) {
					delete_option( $name );
					$this->mark_synced( 'option', $item['sync_id'], 0 );
				}
				$this->report['deleted'][] = $this->label( $item );
			}
			return 0;
		}
		if ( ! $this->opts['dry_run'] && $this->option_differs( $item ) ) {
			$this->ref_queue[] = array( $item, 0 );
			$this->mark_synced( 'option', $item['sync_id'], 0 );
			$this->report['updated'][] = $this->label( $item );
		} elseif ( $this->opts['dry_run'] && $this->option_differs( $item ) ) {
			$this->report['updated'][] = $this->label( $item );
		}
		return 0;
	}

	/** Skip settings that already match (ignoring ID differences, which are rewritten later). */
	private function option_differs( array $item ) {
		$o   = $item['option'];
		$new = $this->urls( WPSync_Payload::unserialize( $o['value'] ) );
		$cur = get_option( $o['name'], null );
		if ( 'keys' === $o['mode'] ) {
			$cur = is_array( $cur ) ? array_intersect_key( $cur, (array) $new ) : array();
		}
		return maybe_serialize( $cur ) !== maybe_serialize( $new ) || WPSync_Config::option_refs( $o['name'] );
	}

	private function apply_option( array $item, $cb ) {
		$o     = $item['option'];
		$value = $this->urls( WPSync_Payload::unserialize( $o['value'] ) );
		$refs  = WPSync_Config::option_refs( $o['name'] );
		if ( is_array( $refs ) && is_array( $value ) ) {
			foreach ( $refs as $k => $kind ) {
				if ( isset( $value[ $k ] ) ) {
					$value[ $k ] = WPSync_Refs::map_value( $value[ $k ], function ( $id ) use ( $cb, $kind ) {
						return $cb( $kind, $id );
					} );
				}
			}
		} elseif ( is_string( $refs ) ) {
			$value = WPSync_Refs::map_value( $value, function ( $id ) use ( $cb, $refs ) {
				return $cb( $refs, $id );
			} );
		}
		if ( 'keys' === $o['mode'] ) {
			$cur = get_option( $o['name'], array() );
			$cur = is_array( $cur ) ? $cur : array();
			foreach ( (array) $value as $k => $v ) {
				$cur[ $k ] = $v;
			}
			$value = $cur;
		}
		update_option( $o['name'], $value );
	}

	/** @return int local ID (0 if not created) */
	private function handle( array $item ) {
		$type = $item['type'];
		$sid  = $item['sync_id'];
		$key  = $type . ':' . $sid;
		if ( isset( $this->handled[ $key ] ) ) {
			return $this->handled[ $key ];
		}
		if ( 'option' === $type ) {
			return $this->handle_option( $item, $key );
		}
		$local   = isset( $this->maps[ $type ][ $sid ] ) ? $this->maps[ $type ][ $sid ] : 0;
		$matched = false;
		if ( ! $local && empty( $item['deleted'] ) ) {
			// Not linked yet: is it the same page/file/term as one already here (sites built or
			// migrated separately, so their IDs differ)? Link it instead of creating a duplicate.
			$local = $this->find_match( $item );
			if ( $local ) {
				$matched = true;
				$this->relink( $type, $local, $sid );
			}
		}
		$forced   = $this->opts['theirs'] || in_array( $key, (array) $this->opts['force'], true );
		$conflict = isset( $this->local_changed[ $key ] ) && ! $forced;

		if ( ! empty( $item['deleted'] ) ) {
			$this->handled[ $key ] = $local;
			if ( ! $local ) {
				return 0;
			}
			if ( $conflict ) {
				$this->conflict( $key, $item, $local, 'deleted on live, but you changed it locally' );
				return $local;
			}
			$label = $this->label( $item, $local );
			if ( ! $this->opts['dry_run'] ) {
				if ( 'post' === $type ) {
					wp_delete_post( $local, true );
				} else {
					$term = get_term( $local );
					if ( $term && ! is_wp_error( $term ) ) {
						wp_delete_term( $local, $term->taxonomy );
					}
				}
				unset( $this->maps[ $type ][ $sid ] );
				$this->mark_synced( $type, $sid, $local );
			}
			$this->report['deleted'][] = $label;
			$this->handled[ $key ]     = 0;
			return 0;
		}

		if ( $conflict ) {
			$reason = 'delete' === $this->local_changed[ $key ] || ! $local
				? 'changed on live, but you deleted it locally'
				: 'changed on live AND changed locally';
			$this->conflict( $key, $item, $local, $reason );
			$this->handled[ $key ] = $local;
			return $local;
		}

		if ( $this->opts['dry_run'] ) {
			$this->report[ $local ? 'updated' : 'created' ][] = $this->label( $item, $local ) . ( $matched ? ' – matched to the existing local copy' : '' );
			$this->handled[ $key ] = $local;
			return $local;
		}

		$id = 'term' === $type ? $this->upsert_term( $item, $local ) : $this->upsert_post( $item, $local );
		$this->handled[ $key ] = $id;
		if ( $id ) {
			$this->maps[ $type ][ $sid ] = $id;
			$this->ref_queue[]           = array( $item, $id );
			$this->mark_synced( $type, $sid, $id );
			$this->report[ $local ? 'updated' : 'created' ][] = $this->label( $item, $id ) . ( $matched ? ' – matched to the existing local copy' : '' );
		}
		return $id;
	}

	/**
	 * Find a local object that is the same thing as this live one even though their sync
	 * IDs differ: same post type + slug, same media file, same term slug, or (for posts
	 * without a slug) same title + date. Only objects that have never been linked to live
	 * are considered, so two genuinely different live items can't both claim one local item.
	 *
	 * @return int local ID or 0
	 */
	private function find_match( array $item ) {
		global $wpdb;
		// Local IDs already claimed in this run, for this object type only (post #2 and term #2 are unrelated).
		$taken = array();
		foreach ( $this->handled as $k => $id ) {
			if ( $id && 0 === strpos( $k, $item['type'] . ':' ) ) {
				$taken[] = (int) $id;
			}
		}
		$cands = array();

		if ( 'term' === $item['type'] ) {
			$t = $item['term'];
			if ( taxonomy_exists( $t['taxonomy'] ) ) {
				$existing = get_term_by( 'slug', $t['slug'], $t['taxonomy'] );
				if ( $existing ) {
					$cands[] = (int) $existing->term_id;
				}
			}
		} else {
			$p = $item['post'];
			if ( 'nav_menu_item' === $p['post_type'] ) {
				return 0; // Menu item "slugs" are just numbers; matching them would be a guess.
			}
			if ( 'attachment' === $p['post_type'] && ! empty( $item['meta']['_wp_attached_file'][0] ) ) {
				$cands = $wpdb->get_col( $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
					 WHERE p.post_type = 'attachment' AND m.meta_value = %s ORDER BY p.ID LIMIT 5",
					$item['meta']['_wp_attached_file'][0]
				) );
			} elseif ( '' !== (string) $p['post_name'] ) {
				$cands = $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID LIMIT 5",
					$p['post_type'],
					$p['post_name']
				) );
			} elseif ( '' !== (string) $p['post_title'] ) {
				$cands = $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_date = %s AND post_status <> 'auto-draft' ORDER BY ID LIMIT 5",
					$p['post_type'],
					$p['post_title'],
					$p['post_date']
				) );
			}
		}

		$eligible = array();
		foreach ( array_map( 'intval', $cands ) as $id ) {
			if ( in_array( $id, $taken, true ) ) {
				continue;
			}
			$csid = 'term' === $item['type'] ? WPSync_Ids::for_term( $id ) : WPSync_Ids::for_post( $id );
			// Already linked to a (different) live item by an earlier pull? Then it isn't this one.
			$linked = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WPSync_Config::table() . " WHERE source = 'sync' AND object_type = %s AND sync_id = %s",
				$item['type'],
				$csid
			) );
			if ( ! $linked ) {
				$eligible[] = $id;
			}
		}
		// Ambiguous (e.g. two child pages called "team" under different parents): don't guess.
		return 1 === count( $eligible ) ? $eligible[0] : 0;
	}

	/** Point a local object at a live sync ID, carrying its local-change history across. */
	private function relink( $type, $local, $new_sid ) {
		global $wpdb;
		$old_sid = 'term' === $type ? WPSync_Ids::for_term( $local ) : WPSync_Ids::for_post( $local );
		if ( ! $old_sid || $old_sid === $new_sid ) {
			return;
		}
		$old_key = $type . ':' . $old_sid;
		$new_key = $type . ':' . $new_sid;
		if ( isset( $this->local_changed[ $old_key ] ) ) {
			$this->local_changed[ $new_key ] = $this->local_changed[ $old_key ];
			unset( $this->local_changed[ $old_key ] );
		}
		unset( $this->maps[ $type ][ $old_sid ] );
		$this->maps[ $type ][ $new_sid ] = $local;
		if ( $this->opts['dry_run'] ) {
			return;
		}
		if ( 'term' === $type ) {
			update_term_meta( $local, WPSync_Config::META_KEY, $new_sid );
		} else {
			update_post_meta( $local, WPSync_Config::META_KEY, $new_sid );
		}
		$wpdb->update( WPSync_Config::table(), array( 'sync_id' => $new_sid ), array( 'object_type' => $type, 'sync_id' => $old_sid ) );
	}

	private function conflict( $key, $item, $local, $reason ) {
		$this->report['conflicts'][ $key ] = array(
			'key'      => $key,
			'label'    => $this->label( $item, $local ),
			'local_id' => $local,
			'reason'   => $reason,
		);
	}

	private function mark_synced( $type, $sid, $id ) {
		WPSync_Logger::insert( $type, $sid, $id, 'synced', 'sync' );
		unset( $this->local_changed[ $type . ':' . $sid ] );
	}

	/* ========================================================= References */

	/** Local ID for a remote ID, using the payload's idmap. Fetches missing objects from live. */
	private function resolve_remote_id( $kind, $remote_id, array $idmap ) {
		$remote_id = (int) $remote_id;
		$sid       = isset( $idmap[ $kind ][ $remote_id ] ) ? $idmap[ $kind ][ $remote_id ] : ( isset( $idmap[ $kind ][ (string) $remote_id ] ) ? $idmap[ $kind ][ (string) $remote_id ] : null );
		if ( ! $sid ) {
			return $remote_id; // Not a live object we know about: leave untouched.
		}
		$local = $this->resolve_sid( $kind, $sid );
		return $local ? $local : $remote_id;
	}

	private function resolve_sid( $kind, $sid ) {
		$key = $kind . ':' . $sid;
		if ( ! empty( $this->handled[ $key ] ) ) {
			return $this->handled[ $key ];
		}
		if ( isset( $this->maps[ $kind ][ $sid ] ) ) {
			return $this->maps[ $kind ][ $sid ];
		}
		if ( $this->opts['dry_run'] || isset( $this->fetching[ $key ] ) ) {
			return 0;
		}
		$this->fetching[ $key ] = true;
		$obj = $this->client->get( 'object', array( 'type' => $kind, 'id' => $sid ) );
		if ( is_wp_error( $obj ) ) {
			$this->report['skipped'][] = "Referenced {$kind} {$sid} could not be fetched: " . $obj->get_error_message();
			return 0;
		}
		return $this->handle( $obj );
	}

	private function apply_refs( array $item, $id ) {
		global $wpdb;
		$idmap = isset( $item['idmap'] ) && is_array( $item['idmap'] ) ? $item['idmap'] : array();
		$self  = $this;
		$cb    = function ( $kind, $remote_id ) use ( $self, $idmap ) {
			return $self->resolve_remote_id_public( $kind, $remote_id, $idmap );
		};
		if ( 'option' === $item['type'] ) {
			$this->apply_option( $item, $cb );
			return;
		}
		$meta     = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		$ref_keys = isset( $item['ref_keys'] ) && is_array( $item['ref_keys'] ) ? $item['ref_keys'] : array();
		$kind     = $item['type'];

		// ID-holding meta (featured image, galleries, ACF image/relationship fields, ...).
		foreach ( $ref_keys as $key => $ref_kind ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$values = array();
			foreach ( (array) $meta[ $key ] as $raw ) {
				$values[] = WPSync_Refs::map_value( WPSync_Payload::unserialize( $raw ), function ( $rid ) use ( $cb, $ref_kind ) {
					return $cb( $ref_kind, $rid );
				} );
			}
			$this->write_meta( $kind, $id, $key, $values );
		}

		if ( 'term' === $kind ) {
			$t      = $item['term'];
			$parent = $t['parent'] ? (int) $cb( 'term', $t['parent'] ) : 0;
			$desc   = WPSync_Refs::walk_content( $this->urls( $t['description'] ), $cb, 'term_description' );
			$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => $parent, 'description' => $desc ), array( 'term_id' => $id, 'taxonomy' => $t['taxonomy'] ) );
			clean_term_cache( $id, $t['taxonomy'] );
			return;
		}

		$p = $item['post'];

		// Page-builder data stored in meta (Elementor etc.).
		foreach ( WPSync_Refs::content_meta_keys() as $ck ) {
			if ( ! isset( $meta[ $ck ] ) ) {
				continue;
			}
			$values = array();
			foreach ( (array) $meta[ $ck ] as $raw ) {
				$values[] = WPSync_Refs::walk_content( $this->urls( WPSync_Payload::unserialize( $raw ) ), $cb, $ck );
			}
			$this->write_meta( 'post', $id, $ck, $values );
		}

		// Parent, content and excerpt, written directly so no hooks/kses touch them.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_parent'  => $p['post_parent'] ? (int) $cb( 'post', $p['post_parent'] ) : 0,
				'post_content' => WPSync_Refs::walk_content( $this->urls( $p['post_content'] ), $cb, 'post_content' ),
				'post_excerpt' => WPSync_Refs::walk_content( $this->urls( $p['post_excerpt'] ), $cb, 'post_excerpt' ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		// Terms.
		if ( ! empty( $item['terms'] ) && is_array( $item['terms'] ) ) {
			foreach ( $item['terms'] as $tax => $sids ) {
				if ( ! taxonomy_exists( $tax ) ) {
					continue;
				}
				$ids = array();
				foreach ( (array) $sids as $tsid ) {
					$tid = $this->resolve_sid( 'term', $tsid );
					if ( $tid ) {
						$ids[] = (int) $tid;
					}
				}
				wp_set_object_terms( $id, $ids, $tax, false );
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) && in_array( $p['post_type'], array( 'product', 'product_variation' ), true ) ) {
			wc_delete_product_transients( $id );
		}
	}

	/** @internal Closure access for PHP 7.4. */
	public function resolve_remote_id_public( $kind, $remote_id, $idmap ) {
		return $this->resolve_remote_id( $kind, $remote_id, $idmap );
	}

	/* ============================================================== Writes */

	private function upsert_post( array $item, $local ) {
		global $wpdb;
		$p    = $item['post'];
		$data = array(
			'post_type'      => $p['post_type'],
			'post_status'    => $p['post_status'],
			'post_title'     => $this->urls( $p['post_title'] ),
			'post_content'   => $this->urls( $p['post_content'] ), // IDs rewritten in the refs pass.
			'post_excerpt'   => $this->urls( $p['post_excerpt'] ),
			'post_name'      => $p['post_name'],
			'post_date'      => $p['post_date'],
			'post_date_gmt'  => $p['post_date_gmt'],
			'comment_status' => $p['comment_status'],
			'ping_status'    => $p['ping_status'],
			'post_password'  => $p['post_password'],
			'menu_order'     => (int) $p['menu_order'],
			'post_mime_type' => $p['post_mime_type'],
			'post_author'    => $this->author( $p['author_login'] ),
			'post_parent'    => $local ? (int) wp_get_post_parent_id( $local ) : 0,
		);
		if ( $local ) {
			$data['ID'] = $local;
		}
		if ( 'attachment' === $p['post_type'] && ! empty( $item['meta']['_wp_attached_file'][0] ) ) {
			$uploads      = wp_get_upload_dir();
			$data['guid'] = $uploads['baseurl'] . '/' . $item['meta']['_wp_attached_file'][0];
		}

		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			$this->report['errors'][] = $this->label( $item ) . ': ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'insert failed' );
			return 0;
		}

		// Keep live's modified dates rather than "now".
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $p['post_modified'], 'post_modified_gmt' => $p['post_modified_gmt'] ), array( 'ID' => $id ) );
		if ( 'attachment' === $p['post_type'] && isset( $data['guid'] ) ) {
			$wpdb->update( $wpdb->posts, array( 'guid' => $data['guid'] ), array( 'ID' => $id ) );
		}

		$skip = array_merge( array_keys( (array) $item['ref_keys'] ), WPSync_Refs::content_meta_keys() );
		$this->sync_meta( 'post', $id, (array) $item['meta'], $skip );
		update_post_meta( $id, WPSync_Config::META_KEY, $item['sync_id'] );
		clean_post_cache( $id );

		if ( ! empty( $item['files'] ) ) {
			$this->download_files( $item['files'] );
		}
		return (int) $id;
	}

	private function upsert_term( array $item, $local ) {
		$t = $item['term'];
		if ( ! taxonomy_exists( $t['taxonomy'] ) ) {
			$this->report['skipped'][] = $this->label( $item ) . ': taxonomy is not registered on this site';
			return 0;
		}
		if ( ! $local ) {
			// Same slug in the same taxonomy is the same term; adopt it rather than create "-2".
			$existing = get_term_by( 'slug', $t['slug'], $t['taxonomy'] );
			if ( $existing ) {
				$local = (int) $existing->term_id;
			}
		}
		$args = wp_slash( array( 'name' => $t['name'], 'slug' => $t['slug'], 'description' => $this->urls( $t['description'] ) ) );
		$res  = $local ? wp_update_term( $local, $t['taxonomy'], $args ) : wp_insert_term( wp_slash( $t['name'] ), $t['taxonomy'], $args );
		if ( is_wp_error( $res ) ) {
			$existing_id = $res->get_error_data( 'term_exists' );
			if ( ! $existing_id ) {
				$this->report['errors'][] = $this->label( $item ) . ': ' . $res->get_error_message();
				return 0;
			}
			$id = (int) $existing_id;
		} else {
			$id = (int) $res['term_id'];
		}
		$this->sync_meta( 'term', $id, (array) $item['meta'], array_keys( (array) $item['ref_keys'] ) );
		update_term_meta( $id, WPSync_Config::META_KEY, $item['sync_id'] );
		return $id;
	}

	/** Make this object's meta match live's (except keys handled in the refs pass). */
	private function sync_meta( $kind, $id, array $remote, array $skip ) {
		$ignored = WPSync_Config::ignored_meta_keys();
		$local   = 'post' === $kind ? (array) get_post_meta( $id ) : (array) get_term_meta( $id );

		foreach ( $local as $key => $raws ) {
			if ( ! isset( $remote[ $key ] ) && ! in_array( $key, $ignored, true ) && ! in_array( $key, $skip, true ) ) {
				'post' === $kind ? delete_post_meta( $id, $key ) : delete_term_meta( $id, $key );
			}
		}
		foreach ( $remote as $key => $raws ) {
			if ( in_array( $key, $ignored, true ) || in_array( $key, $skip, true ) ) {
				continue;
			}
			$values = array();
			foreach ( (array) $raws as $raw ) {
				$values[] = $this->urls( WPSync_Payload::unserialize( $raw ) );
			}
			// Skip identical values to avoid needless writes.
			if ( isset( $local[ $key ] ) && array_map( 'maybe_serialize', $values ) === array_values( $local[ $key ] ) ) {
				continue;
			}
			$this->write_meta( $kind, $id, $key, $values );
		}
	}

	private function write_meta( $kind, $id, $key, array $values ) {
		if ( 'post' === $kind ) {
			delete_post_meta( $id, $key );
			foreach ( $values as $v ) {
				add_post_meta( $id, $key, wp_slash( $v ) );
			}
		} else {
			delete_term_meta( $id, $key );
			foreach ( $values as $v ) {
				add_term_meta( $id, $key, wp_slash( $v ) );
			}
		}
	}

	/**
	 * Local destination for a media file, or null if the path is unsafe (never write
	 * outside the uploads folder).
	 */
	public static function safe_dest( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path || false !== strpos( $path, '..' ) || '/' === $path[0] || preg_match( '#^[a-z]:#i', $path ) || false !== strpos( $path, "\0" ) ) {
			return null;
		}
		$uploads = wp_get_upload_dir();
		return wp_normalize_path( $uploads['basedir'] ) . '/' . $path;
	}

	private function download_files( array $files ) {
		foreach ( $files as $f ) {
			$path = isset( $f['path'] ) ? (string) $f['path'] : '';
			$url  = isset( $f['url'] ) ? (string) $f['url'] : '';
			$dest = self::safe_dest( $path );
			if ( ! $dest || '' === $url ) {
				$this->report['skipped'][] = 'Unsafe file path skipped: ' . $path;
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			if ( file_exists( $dest ) && ! $this->opts['redownload'] ) {
				continue;
			}
			if ( $this->opts['defer_files'] ) {
				$this->report['downloads'][] = array( 'url' => $url, 'path' => $path );
				continue;
			}
			$res = $this->client->download( $url, $dest );
			if ( is_wp_error( $res ) ) {
				$this->report['errors'][] = 'Media download failed: ' . $res->get_error_message();
			} else {
				$this->report['files']++;
			}
		}
	}

	private function author( $login ) {
		if ( isset( $this->authors[ $login ] ) ) {
			return $this->authors[ $login ];
		}
		$user = $login ? get_user_by( 'login', $login ) : false;
		if ( ! $user && defined( 'WPSYNC_DEFAULT_AUTHOR' ) ) {
			$user = get_user_by( 'login', WPSYNC_DEFAULT_AUTHOR );
		}
		if ( ! $user ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
			$user   = $admins ? $admins[0] : false;
		}
		return $this->authors[ $login ] = $user ? (int) $user->ID : 0;
	}
}

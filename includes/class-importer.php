<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Applies payloads from live to this (local) site.
 *
 * Rules:
 *  - New on live            → created locally.
 *  - Changed on live only   → updated locally.
 *  - Changed on live AND changed locally since it was last pulled → CONFLICT: left alone and
 *    reported, unless --theirs / `wp livepull take` is used.
 *  - Deleted on live        → deleted locally (unless changed locally → conflict).
 *
 * Runs in two passes: first every object is created/updated (so everything has a local ID),
 * then every ID reference (parents, featured images, galleries, terms, ACF fields, blocks…)
 * is rewritten to local IDs. Referenced objects missing locally are fetched on demand.
 */
final class LivePull_Importer {

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
	public function __construct( LivePull_Client $client, array $remote, array $opts ) {
		$this->client = $client;
		$this->opts   = wp_parse_args( $opts, array( 'dry_run' => false, 'theirs' => false, 'redownload' => false, 'url_replace' => true, 'force' => array(), 'defer_files' => false ) );

		if ( $this->opts['url_replace'] ) {
			$this->build_url_map( $remote );
		}
		// Shared by every importer in this request: a pull imports a few items at a time, and
		// rebuilding these lookups for each handful is slow on big sites.
		if ( null === self::$shared ) {
			self::$shared = array(
				'maps'    => array( 'post' => LivePull_Ids::map( 'post' ), 'term' => LivePull_Ids::map( 'term' ), 'comment' => LivePull_Ids::map( 'comment' ) ),
				'changed' => self::locally_changed(),
			);
		}
		$this->maps          = &self::$shared['maps'];
		$this->local_changed = &self::$shared['changed'];
	}

	private static $shared = null;

	/** Forget the shared lookups (start of a pull, after a restore or copy). */
	public static function reset_cache() {
		self::$shared = null;
	}

	/* ================================================================ State */

	/**
	 * Objects edited on THIS site since they were last pulled from live.
	 *
	 * @return array<string,string> "type:sync_id" => last local action (upsert|delete)
	 */
	public static function locally_changed() {
		global $wpdb;
		$table  = LivePull_Config::table();
		$synced = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT object_type, sync_id, MAX(id) AS last FROM %i WHERE source = 'sync' GROUP BY object_type, sync_id", $table ) ) as $r ) {
			$synced[ $r->object_type . ':' . $r->sync_id ] = (int) $r->last;
		}
		$changed = array();
		$floor   = (int) get_option( 'livepull_clone_floor', 0 ); // Rows up to here were copied from live.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, sync_id, action FROM %i WHERE source = 'local' AND id > %d ORDER BY id ASC", $table, $floor ) ) as $r ) {
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

	/** Live's value with live addresses switched to this site's, for comparing. */
	public function localise( $value ) {
		return $this->urls( $value );
	}

	/** Replace live URLs with local ones inside strings / arrays (never inside raw serialized strings). */
	private function urls( $value ) {
		if ( ! $this->url_from ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return LivePull_Serial::text( $value, $this->url_from, $this->url_to );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = $this->urls( $v );
			}
		} elseif ( $value instanceof stdClass ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = $this->urls( $v );
			}
		}
		return $value;
	}

	/* ================================================================== Run */

	public function run( array $items ) {
		// Terms first, then media, then everything else; deletions last.
		$items = self::sort_items( $items );

		LivePull_Logger::suspend();
		// Pulled HTML (content, excerpts, descriptions, builder data, custom fields, options)
		// follows WordPress's own rule: kept as is only for users WordPress lets post
		// unfiltered HTML, otherwise run through wp_kses_post(). See self::html().
		remove_action( 'post_updated', 'wp_save_post_revision', 10 );
		// Never let a pull trigger outgoing side effects from the local site.
		remove_action( 'publish_post', '_publish_post_hook', 5 ); // pingbacks/enclosures
		add_filter( 'pre_wp_mail', '__return_false', 999 );
		add_filter( 'woocommerce_webhook_should_deliver', '__return_false', 999 );
		wp_defer_term_counting( true );

		try {
			foreach ( $items as $item ) {
				self::$stripped = false;
				$this->handle( $item );
				$this->note_stripped( $item );
			}
			// The queue can grow while we go (fetched dependencies), so no foreach.
			for ( $i = 0; $i < count( $this->ref_queue ); $i++ ) {
				list( $item, $id ) = $this->ref_queue[ $i ];
				self::$stripped = false;
				$this->apply_refs( $item, $id );
				$this->note_stripped( $item );
			}
			foreach ( $this->ref_queue as $done ) {
				if ( in_array( $done[0]['type'], array( 'post', 'term' ), true ) ) {
					self::refresh_plugin_data( $done[0]['type'], (int) $done[1] );
					/** Fires after LivePull has fully written a pulled post or term. */
					do_action( 'livepull_imported_' . $done[0]['type'], (int) $done[1], $done[0] );
				}
			}
		} finally {
			wp_defer_term_counting( false );
			add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
			add_action( 'publish_post', '_publish_post_hook', 5, 1 );
			remove_filter( 'pre_wp_mail', '__return_false', 999 );
			remove_filter( 'woocommerce_webhook_should_deliver', '__return_false', 999 );
			LivePull_Logger::resume();
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
			return 4; // Settings last: they point at pages/menus pulled in the same batch.
		}
		if ( 'comment' === $item['type'] ) {
			return 3; // After the posts they belong to.
		}
		return ( isset( $item['post']['post_type'] ) && 'attachment' === $item['post']['post_type'] ) ? 1 : 2;
	}

	/**
	 * Key, readable label and kind ("page", "category", "setting"…) of an incoming item, for
	 * previews and the held-back list.
	 */
	public static function describe( array $item ) {
		global $wpdb;
		$key  = ( isset( $item['type'] ) ? $item['type'] : '' ) . ':' . ( isset( $item['sync_id'] ) ? $item['sync_id'] : '' );
		$kind = 'setting';
		$id   = 0;
		if ( 'comment' === $item['type'] ) {
			$map = LivePull_Ids::map( 'comment' );
			$id  = isset( $map[ $item['sync_id'] ] ) ? $map[ $item['sync_id'] ] : 0;
			return array( 'key' => $key, 'label' => self::label( $item, $id ), 'kind' => 'comment', 'group' => __( 'Comments', 'livepull' ) );
		}
		if ( 'post' === $item['type'] ) {
			$kind = isset( $item['post']['post_type'] ) ? $item['post']['post_type'] : '';
			if ( '' === $kind ) {
				$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", LivePull_Config::META_KEY, $item['sync_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
				$kind = $id ? (string) get_post_type( $id ) : 'post';
			}
		} elseif ( 'term' === $item['type'] ) {
			$kind = isset( $item['term']['taxonomy'] ) ? $item['term']['taxonomy'] : '';
			if ( '' === $kind ) {
				$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", LivePull_Config::META_KEY, $item['sync_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
				$t    = $id ? get_term( $id ) : null;
				$kind = ( $t && ! is_wp_error( $t ) ) ? $t->taxonomy : 'term';
			}
		}
		$obj = get_post_type_object( $kind );
		$tax = $obj ? null : get_taxonomy( $kind );
		return array(
			'key'   => $key,
			'label' => self::label( $item, $id ),
			'kind'  => $kind,
			'group' => $obj ? $obj->labels->name : ( $tax ? $tax->labels->name : ( 'setting' === $kind ? 'Settings' : $kind ) ),
		);
	}

	/** A setting as people know it: "Site title", not "blogname". */
	public static function setting_label( $name ) {
		$nice = array( 'blogname' => 'Site title', 'blogdescription' => 'Tagline', 'show_on_front' => 'Homepage displays', 'page_on_front' => 'Homepage', 'page_for_posts' => 'Posts page', 'sticky_posts' => 'Sticky posts', 'site_icon' => 'Site icon' );
		if ( 0 === strpos( (string) $name, 'theme_mods_' ) ) {
			return 'setting "Menu locations / logo"';
		}
		return 'setting "' . ( isset( $nice[ $name ] ) ? $nice[ $name ] : $name ) . '"';
	}

	private static function label( $item, $local_id = 0 ) {
		if ( 'comment' === $item['type'] ) {
			if ( isset( $item['comment'] ) ) {
				return sprintf( 'comment by %s on "%s"', $item['comment']['comment_author'] ? $item['comment']['comment_author'] : 'someone', isset( $item['post_title'] ) ? $item['post_title'] : '' );
			}
			$c = $local_id ? get_comment( $local_id ) : null;
			return $c ? sprintf( 'comment by %s on "%s"', $c->comment_author, get_the_title( $c->comment_post_ID ) ) : 'comment';
		}
		if ( 'option' === $item['type'] ) {
			return self::setting_label( isset( $item['option']['name'] ) ? $item['option']['name'] : self::option_name( $item['sync_id'] ) );
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

	/**
	 * Why an incoming item must not be applied here, or '' if it's fine. Live is trusted to
	 * send content, never settings or kinds of content outside what LivePull pulls: this
	 * stops a compromised or spoofed live site from changing anything else on this site.
	 */
	public static function refused( array $item ) {
		$type = isset( $item['type'] ) ? $item['type'] : '';
		if ( ! in_array( $type, array( 'post', 'term', 'option', 'comment' ), true ) || empty( $item['sync_id'] ) || ! is_string( $item['sync_id'] ) ) {
			return 'Ignored an item of an unknown kind sent by live.';
		}
		if ( 'comment' === $type ) {
			return LivePull_Config::group_on( 'comments' ) ? '' : 'Ignored a comment: comments aren\'t pulled on this site.';
		}
		if ( ! empty( $item['deleted'] ) ) {
			return ''; // Deletions only ever touch items already linked here.
		}
		if ( 'post' === $type ) {
			$pt = isset( $item['post']['post_type'] ) ? (string) $item['post']['post_type'] : '';
			return LivePull_Config::post_type_ok( $pt ) ? '' : sprintf( 'Ignored content of type "%s": that type isn\'t pulled.', $pt );
		}
		if ( 'term' === $type ) {
			$tax = isset( $item['term']['taxonomy'] ) ? (string) $item['term']['taxonomy'] : '';
			return LivePull_Config::taxonomy_ok( $tax ) ? '' : sprintf( 'Ignored a term in "%s": that taxonomy isn\'t pulled.', $tax );
		}
		$name = isset( $item['option']['name'] ) ? (string) $item['option']['name'] : '';
		if ( '' === $name || ! LivePull_Config::option_allowed( $name ) || LivePull_Config::option_sid( $name ) !== $item['sync_id'] ) {
			return sprintf( 'Ignored the setting "%s": LivePull doesn\'t pull that setting.', $name );
		}
		$g = LivePull_Config::group_of( 'option', $name );
		if ( '' !== $g && ! LivePull_Config::group_on( $g ) ) {
			return sprintf( 'Ignored the setting "%s": that kind of setting is switched off on this site.', $name );
		}
		return '';
	}

	public static function option_name( $sid ) {
		foreach ( LivePull_Config::pulled_option_names() as $n ) {
			if ( LivePull_Config::option_sid( $n ) === $sid ) {
				return $n;
			}
		}
		return '';
	}

	/** Comments and reviews: linked to the local copy of their post (fetched if needed). */
	private function handle_comment( array $item, $key ) {
		$sid   = $item['sync_id'];
		$local = isset( $this->maps['comment'][ $sid ] ) ? (int) $this->maps['comment'][ $sid ] : 0;
		$conflict = isset( $this->local_changed[ $key ] ) && ! ( $this->opts['theirs'] || in_array( $key, (array) $this->opts['force'], true ) );
		$this->handled[ $key ] = $local;
		if ( ! empty( $item['deleted'] ) ) {
			if ( ! $local ) {
				return 0;
			}
			if ( $conflict ) {
				$this->conflict( $key, $item, $local, 'deleted on live, but you changed it locally' );
				return $local;
			}
			if ( ! $this->opts['dry_run'] ) {
				wp_delete_comment( $local, true );
				unset( $this->maps['comment'][ $sid ] );
				$this->mark_synced( 'comment', $sid, $local );
			}
			$this->report['deleted'][] = self::label( $item, $local );
			return 0;
		}
		if ( $conflict ) {
			$this->conflict( $key, $item, $local, 'changed on live AND changed locally' );
			return $local;
		}
		if ( ! isset( $item['comment'] ) || ! is_array( $item['comment'] ) ) {
			$this->report['skipped'][] = 'Ignored a comment that live sent in an unexpected shape.';
			return 0;
		}
		$item['comment'] = wp_parse_args( $item['comment'], array( 'comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => '', 'comment_date' => '', 'comment_date_gmt' => '', 'comment_content' => '', 'comment_approved' => '0', 'comment_type' => 'comment', 'comment_karma' => 0, 'user_login' => '', 'user_email' => '' ) );
		$item['meta']    = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		// Only ordinary comment kinds: never order notes, logs or anything a plugin uses internally.
		$ctype = (string) $item['comment']['comment_type'];
		if ( ! in_array( $ctype, array( '', 'comment', 'review', 'pingback', 'trackback' ), true ) ) {
			$this->report['skipped'][] = sprintf( 'Ignored a comment of type "%s": only comments and reviews are pulled.', sanitize_key( $ctype ) );
			return 0;
		}
		if ( $this->opts['dry_run'] ) {
			$this->report[ $local ? 'updated' : 'created' ][] = self::label( $item, $local );
			return $local;
		}
		$post = ! empty( $item['post_sid'] ) ? (int) $this->resolve_sid( 'post', $item['post_sid'] ) : 0;
		if ( ! $post ) {
			$this->report['skipped'][] = self::label( $item ) . ': its post isn\'t on this site';
			return 0;
		}
		$c      = $item['comment'];
		$parent = ! empty( $item['parent_sid'] ) ? (int) $this->resolve_sid( 'comment', $item['parent_sid'] ) : 0;
		// Linked to a local user only if the login AND email match (users differ between sites).
		$user   = ! empty( $c['user_login'] ) ? get_user_by( 'login', (string) $c['user_login'] ) : false;
		if ( $user && strtolower( (string) $user->user_email ) !== strtolower( (string) ( '' !== $c['user_email'] ? $c['user_email'] : $c['comment_author_email'] ) ) ) {
			$user = false;
		}
		$data   = array(
			'comment_post_ID'      => $post,
			'comment_parent'       => $parent,
			'user_id'              => $user ? (int) $user->ID : 0,
			'comment_author'       => sanitize_text_field( (string) $c['comment_author'] ),
			'comment_author_email' => sanitize_email( (string) $c['comment_author_email'] ),
			'comment_author_url'   => esc_url_raw( (string) $c['comment_author_url'] ),
			'comment_date'         => $c['comment_date'],
			'comment_date_gmt'     => $c['comment_date_gmt'],
			'comment_content'      => wp_kses_post( (string) $this->urls( (string) $c['comment_content'] ) ),
			'comment_approved'     => in_array( $c['comment_approved'], array( '0', '1' ), true ) ? $c['comment_approved'] : '0',
			'comment_type'         => '' === $ctype ? 'comment' : $ctype,
			'comment_karma'        => (int) $c['comment_karma'],
		);
		if ( $local && get_comment( $local ) ) {
			$data['comment_ID'] = $local;
			wp_update_comment( wp_slash( $data ) );
			$id = $local;
		} else {
			$id = (int) wp_insert_comment( wp_slash( $data ) );
			if ( $id ) {
				update_comment_meta( $id, LivePull_Config::META_KEY, $sid );
			}
		}
		if ( ! $id ) {
			$this->report['errors'][] = 'Could not save ' . self::label( $item );
			return 0;
		}
		foreach ( (array) $item['meta'] as $mk => $vals ) {
			if ( LivePull_Config::is_ignored_meta( $mk ) || LivePull_Config::is_private_comment_meta( $mk ) ) {
				continue;
			}
			delete_comment_meta( $id, $mk );
			foreach ( (array) $vals as $v ) {
				add_comment_meta( $id, $mk, LivePull_Payload::unserialize( $v ) );
			}
		}
		if ( function_exists( 'wc_get_product' ) && 'product' === get_post_type( $post ) && class_exists( 'WC_Comments' ) ) {
			WC_Comments::clear_transients( $post ); // Review counts and average rating.
		}
		$this->maps['comment'][ $sid ] = $id;
		$this->handled[ $key ]         = $id;
		$this->mark_synced( 'comment', $sid, $id );
		$this->report[ $local ? 'updated' : 'created' ][] = self::label( $item, $id );
		return $id;
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
			if ( $name && LivePull_Config::option_allowed( $name ) && true === LivePull_Config::option_spec( $name ) && false !== get_option( $name, false ) ) {
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
		$o   = self::option_as_allowed( $item['option'] );
		$new = $this->urls( LivePull_Payload::unserialize( $o['value'] ) );
		$cur = get_option( $o['name'], null );
		if ( 'keys' === $o['mode'] ) {
			$cur = is_array( $cur ) ? array_intersect_key( $cur, (array) $new ) : array();
		}
		return maybe_serialize( $cur ) !== maybe_serialize( $new ) || LivePull_Config::option_refs( $o['name'] );
	}

	/**
	 * The option as this site allows it: whole-value options as sent; "sub-key" options
	 * (e.g. the theme's menu locations and logo) reduced to the allowed keys only.
	 */
	private static function option_as_allowed( array $o ) {
		$spec = LivePull_Config::option_spec( $o['name'] );
		if ( is_array( $spec ) ) {
			$value      = LivePull_Payload::unserialize( $o['value'] );
			$value      = is_array( $value ) ? array_intersect_key( $value, array_flip( $spec ) ) : array();
			$o['mode']  = 'keys';
			$o['value'] = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same format the payload uses.
		} else {
			$o['mode'] = 'whole';
		}
		return $o;
	}

	private function apply_option( array $item, $cb ) {
		if ( self::refused( $item ) ) {
			return;
		}
		$o     = self::option_as_allowed( $item['option'] );
		$value = $this->urls( LivePull_Payload::unserialize( $o['value'] ) );
		$refs  = LivePull_Config::option_refs( $o['name'] );
		if ( ! $refs && 0 === strpos( $o['name'], 'options_' ) && isset( $item['option']['refs'] ) && in_array( $item['option']['refs'], array( 'post', 'term' ), true ) ) {
			// An ACF option whose field details arrive in this same pull: live knows what it holds.
			$refs = $item['option']['refs'];
		}
		if ( is_array( $refs ) && is_array( $value ) ) {
			foreach ( $refs as $k => $kind ) {
				if ( isset( $value[ $k ] ) ) {
					$value[ $k ] = LivePull_Refs::map_value( $value[ $k ], function ( $id ) use ( $cb, $kind ) {
						return $cb( $kind, $id );
					} );
				}
			}
		} elseif ( is_string( $refs ) ) {
			$value = LivePull_Refs::map_value( $value, function ( $id ) use ( $cb, $refs ) {
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
		update_option( $o['name'], self::clean( $value ) );
	}

	/** @return int local ID (0 if not created) */
	private function handle( array $item ) {
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		$sid  = isset( $item['sync_id'] ) ? (string) $item['sync_id'] : '';
		$key  = $type . ':' . $sid;
		if ( isset( $this->handled[ $key ] ) ) {
			return $this->handled[ $key ];
		}
		// Only accept what normal pulls are meant to bring over, whatever live sends.
		$refused = self::refused( $item );
		if ( $refused ) {
			$this->handled[ $key ]       = 0;
			$this->report['skipped'][] = $refused;
			return 0;
		}
		if ( 'option' === $type ) {
			return $this->handle_option( $item, $key );
		}
		if ( 'comment' === $type ) {
			return $this->handle_comment( $item, $key );
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
			$csid = 'term' === $item['type'] ? LivePull_Ids::for_term( $id ) : LivePull_Ids::for_post( $id );
			// Already linked to a (different) live item by an earlier pull? Then it isn't this one.
			$linked = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE source = 'sync' AND object_type = %s AND sync_id = %s",
				LivePull_Config::table(),
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
		$old_sid = 'term' === $type ? LivePull_Ids::for_term( $local ) : LivePull_Ids::for_post( $local );
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
			update_term_meta( $local, LivePull_Config::META_KEY, $new_sid );
		} else {
			update_post_meta( $local, LivePull_Config::META_KEY, $new_sid );
		}
		$wpdb->update( LivePull_Config::table(), array( 'sync_id' => $new_sid ), array( 'object_type' => $type, 'sync_id' => $old_sid ) );
	}

	private function conflict( $key, $item, $local, $reason ) {
		if ( LivePull_Puller::is_kept( $key ) ) {
			return; // "Keep mine" was already chosen: leave it as it is, without asking again.
		}
		$this->report['conflicts'][ $key ] = array(
			'key'      => $key,
			'label'    => $this->label( $item, $local ),
			'local_id' => $local,
			'reason'   => $reason,
		);
	}

	private function mark_synced( $type, $sid, $id ) {
		LivePull_Logger::insert( $type, $sid, $id, 'synced', 'sync' );
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

	/**
	 * Some plugins keep their own copy of a post's data in separate tables, built when
	 * the post is saved. LivePull writes meta directly, so drop those copies and let the
	 * plugin rebuild them from the pulled data.
	 */
	public static function refresh_plugin_data( $type, $id ) {
		global $wpdb;
		if ( defined( 'WPSEO_VERSION' ) ) { // Yoast SEO indexables.
			$table = $wpdb->prefix . 'yoast_indexable';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->delete( $table, array( 'object_id' => $id, 'object_type' => $type ) );
			}
		}
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
				$values[] = LivePull_Refs::map_value( LivePull_Payload::unserialize( $raw ), function ( $rid ) use ( $cb, $ref_kind ) {
					return $cb( $ref_kind, $rid );
				} );
			}
			$this->write_meta( $kind, $id, $key, $values );
		}

		if ( 'term' === $kind ) {
			$t      = $item['term'];
			$parent = $t['parent'] ? (int) $cb( 'term', $t['parent'] ) : 0;
			$dkind  = LivePull_Refs::description_kind( $t['taxonomy'] );
			if ( $dkind ) {
				// Polylang translation group: a serialized map of language => object ID.
				$map  = LivePull_Payload::unserialize( $t['description'] );
				$desc = is_array( $map ) ? maybe_serialize( LivePull_Refs::map_value( $map, function ( $rid ) use ( $cb, $dkind ) {
					return $cb( $dkind, $rid );
				} ) ) : $t['description'];
			} else {
				$desc = self::html( LivePull_Refs::walk_content( $this->urls( $t['description'] ), $cb, 'term_description' ) );
			}
			$wpdb->update( $wpdb->term_taxonomy, array( 'parent' => $parent, 'description' => $desc ), array( 'term_id' => $id, 'taxonomy' => $t['taxonomy'] ) );
			clean_term_cache( $id, $t['taxonomy'] );
			return;
		}

		$p = $item['post'];

		// Page-builder data stored in meta (Elementor etc.).
		foreach ( LivePull_Refs::content_meta_keys() as $ck ) {
			if ( ! isset( $meta[ $ck ] ) ) {
				continue;
			}
			$values = array();
			foreach ( (array) $meta[ $ck ] as $raw ) {
				// URLs first (serialization-safe, on the raw value), then IDs.
				$raw      = $this->url_from ? LivePull_Serial::replace( (string) $raw, $this->url_from, $this->url_to ) : (string) $raw;
				$values[] = LivePull_Refs::walk_value( LivePull_Payload::unserialize( $raw ), $cb, $ck );
			}
			$this->write_meta( 'post', $id, $ck, $values );
		}

		// Parent, content and excerpt, written once their IDs are mapped. The final HTML goes
		// through wp_kses_post() (see self::html()).
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_parent'  => $p['post_parent'] ? (int) $cb( 'post', $p['post_parent'] ) : 0,
				'post_content' => self::html( LivePull_Refs::walk_content( $this->urls( $p['post_content'] ), $cb, 'post_content' ) ),
				'post_excerpt' => self::html( LivePull_Refs::walk_content( $this->urls( $p['post_excerpt'] ), $cb, 'post_excerpt' ) ),
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

	/** Set when html() removed code from the item being imported. */
	private static $stripped = false;

	/** Code that kses removes: scripts, frames, embeds, styles, event handlers, javascript: links. */
	const CODE_RE = '/<\s*(script|iframe|object|embed|style|form|meta|link|base)\b|\son[a-z]+\s*=|javascript\s*:/i';

	/**
	 * True when WordPress lets the person running the pull save unfiltered HTML (single-site
	 * administrators, unless DISALLOW_UNFILTERED_HTML is set; super admins on multisite).
	 * A pull is the same as that person saving the content themselves, so the same rule applies.
	 */
	public static function trusted() {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Pulled HTML follows WordPress's rule for the person running the pull: kept as is for users
	 * allowed unfiltered HTML, otherwise run through wp_kses_post() so scripts, frames, event
	 * handlers and javascript: links are removed. JSON (page-builder data, form definitions) is
	 * filtered string by string so its structure stays valid. Values are stored slash-free here.
	 */
	public static function html( $html ) {
		$html = (string) $html;
		if ( '' === $html || self::trusted() || ( false === strpos( $html, '<' ) && false === stripos( $html, 'u003c' ) && false === stripos( $html, 'javascript' ) && false === stripos( $html, ' on' ) ) ) {
			return $html;
		}
		$t = ltrim( $html );
		if ( '' !== $t && ( '{' === $t[0] || '[' === $t[0] ) ) {
			$data = json_decode( $html, true );
			if ( is_array( $data ) ) {
				$clean = self::clean( $data );
				return $clean === $data ? $html : wp_json_encode( $clean );
			}
		}
		if ( false === strpos( $html, '<' ) && ! preg_match( self::CODE_RE, $html ) ) {
			return $html;
		}
		$out = wp_kses_post( $html );
		if ( $out !== $html && preg_match( self::CODE_RE, $html ) ) {
			self::$stripped = true;
		}
		return $out;
	}

	/** Every string inside a value (arrays and plain objects, any depth) through html(). */
	public static function clean( $value ) {
		if ( self::trusted() ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return self::html( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::clean( $v );
			}
			return $value;
		}
		if ( $value instanceof stdClass ) {
			$out = clone $value;
			foreach ( get_object_vars( $out ) as $k => $v ) {
				$out->$k = self::clean( $v );
			}
			return $out;
		}
		return $value;
	}

	/** A stored (possibly serialized) meta value with any code removed. */
	public static function clean_raw( $raw ) {
		if ( self::trusted() || false === strpos( $raw, '<' ) && false === stripos( $raw, 'u003c' ) && false === stripos( $raw, 'javascript' ) && false === stripos( $raw, ' on' ) ) {
			return $raw;
		}
		$value = LivePull_Payload::unserialize( $raw );
		if ( is_string( $value ) ) {
			return self::html( $value );
		}
		if ( self::has_inert_object( $value ) ) {
			// Objects of other classes can't be rebuilt safely; keep only their plain text.
			self::$stripped = self::$stripped || (bool) preg_match( self::CODE_RE, $raw );
			return preg_match( self::CODE_RE, $raw ) ? maybe_serialize( '' ) : $raw;
		}
		$clean = self::clean( $value );
		return $clean == $value ? $raw : maybe_serialize( $clean ); // phpcs:ignore Universal.Operators.StrictComparisons -- stdClass copies compare by value.
	}

	private static function has_inert_object( $value ) {
		if ( is_object( $value ) && ! ( $value instanceof stdClass ) ) {
			return true;
		}
		if ( is_array( $value ) || $value instanceof stdClass ) {
			foreach ( (array) $value as $v ) {
				if ( self::has_inert_object( $v ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Tell the person pulling when code was taken out of an item. */
	private function note_stripped( array $item ) {
		if ( self::$stripped ) {
			$note = sprintf( '%s: scripts or other code in it were removed, because your account can\'t save unfiltered HTML on this site. Pull as an administrator to bring them across.', self::label( $item ) );
			if ( ! in_array( $note, $this->report['skipped'], true ) ) {
				$this->report['skipped'][] = $note;
			}
			self::$stripped = false;
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
			'post_title'     => self::html( $this->urls( $p['post_title'] ) ),
			'post_content'   => self::html( $this->urls( $p['post_content'] ) ), // IDs rewritten in the refs pass.
			'post_excerpt'   => self::html( $this->urls( $p['post_excerpt'] ) ),
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

		$skip = array_merge( array_keys( (array) $item['ref_keys'] ), LivePull_Refs::content_meta_keys() );
		$this->sync_meta( 'post', $id, (array) $item['meta'], $skip );
		update_post_meta( $id, LivePull_Config::META_KEY, $item['sync_id'] );
		clean_post_cache( $id );

		if ( ! empty( $item['files'] ) ) {
			$this->download_files( $item['files'] );
		}
		return (int) $id;
	}

	private function upsert_term( array $item, $local ) {
		$t = $item['term'];
		if ( ! taxonomy_exists( $t['taxonomy'] ) && ! empty( $item['wc_attribute'] ) && function_exists( 'wc_create_attribute' ) ) {
			// A WooCommerce attribute added on live (e.g. "Colour"): create it here too.
			if ( ! wc_attribute_taxonomy_id_by_name( $t['taxonomy'] ) ) {
				$res = wc_create_attribute( $item['wc_attribute'] );
				if ( is_wp_error( $res ) ) {
					$this->report['errors'][] = 'Could not create product attribute ' . $item['wc_attribute']['name'] . ': ' . $res->get_error_message();
				}
			}
			register_taxonomy( $t['taxonomy'], array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
		}
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
		$args = wp_slash( array( 'name' => self::html( $t['name'] ), 'slug' => $t['slug'], 'description' => self::html( $this->urls( $t['description'] ) ) ) );
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
		update_term_meta( $id, LivePull_Config::META_KEY, $item['sync_id'] );
		return $id;
	}

	/**
	 * Make this object's meta match live's (except keys handled in the refs pass).
	 * Values are copied byte for byte – only URLs are rewritten, safely inside serialized
	 * data – so plugin objects and formats survive untouched.
	 */
	private function sync_meta( $kind, $id, array $remote, array $skip ) {
		global $wpdb;
		$ignored = LivePull_Config::ignored_meta_keys();
		list( $table, $col ) = 'post' === $kind ? array( $wpdb->postmeta, 'post_id' ) : array( $wpdb->termmeta, 'term_id' );
		$local = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE %i = %d ORDER BY meta_id', $table, $col, $id ) ) as $r ) {
			$local[ $r->meta_key ][] = $r->meta_value;
		}
		foreach ( array_keys( $local ) as $key ) {
			if ( ! isset( $remote[ $key ] ) && ! LivePull_Config::is_ignored_meta( $key ) && ! in_array( $key, $skip, true ) ) {
				$wpdb->delete( $table, array( $col => $id, 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- writes one object's own meta rows (indexed by object ID).
			}
		}
		foreach ( $remote as $key => $raws ) {
			if ( LivePull_Config::is_ignored_meta( $key ) || in_array( (string) $key, $skip, true ) ) {
				continue;
			}
			$new = array();
			foreach ( (array) $raws as $raw ) {
				$new[] = $this->url_from ? LivePull_Serial::replace( (string) $raw, $this->url_from, $this->url_to ) : (string) $raw;
			}
			if ( isset( $local[ $key ] ) && $new === $local[ $key ] ) {
				continue; // Already identical.
			}
			$this->write_raw_meta( $kind, $id, (string) $key, $new );
		}
		'post' === $kind ? wp_cache_delete( $id, 'post_meta' ) : wp_cache_delete( $id, 'term_meta' );
	}

	/** Replace a meta key's values with raw (already serialized where needed) strings. */
	private function write_raw_meta( $kind, $id, $key, array $raws ) {
		global $wpdb;
		list( $table, $col ) = 'post' === $kind ? array( $wpdb->postmeta, 'post_id' ) : array( $wpdb->termmeta, 'term_id' );
		$wpdb->delete( $table, array( $col => $id, 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- writes one object's own meta rows (indexed by object ID).
		foreach ( $raws as $raw ) {
			$raw = self::clean_raw( (string) $raw ); // Builder data and custom fields: no code either.
			$wpdb->insert( $table, array( $col => $id, 'meta_key' => $key, 'meta_value' => $raw ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- writes one object's own meta rows (indexed by object ID).
		}
		'post' === $kind ? wp_cache_delete( $id, 'post_meta' ) : wp_cache_delete( $id, 'term_meta' );
	}

	/** Write PHP values (from the refs pass) without WordPress unslashing/recursing into them. */
	private function write_meta( $kind, $id, $key, array $values ) {
		$this->write_raw_meta( $kind, $id, $key, array_map( 'maybe_serialize', $values ) );
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
		// Never write anything a web server could execute or obey, whatever live sends.
		$base = strtolower( basename( $path ) );
		if ( preg_match( '/\.(php\d?|phtml|pht|phar|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml|exe|dll|so)$/', $base )
			|| in_array( $base, array( '.htaccess', '.user.ini', 'php.ini', 'web.config', '.htpasswd' ), true )
			|| preg_match( '/\.php\d?\./', $base ) ) {
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
			$alt = isset( $f['alt'] ) && preg_match( '#^https?://#i', (string) $f['alt'] ) ? (string) $f['alt'] : '';
			if ( $this->opts['defer_files'] ) {
				$this->report['downloads'][] = array( 'url' => $url, 'path' => $path, 'alt' => $alt );
				continue;
			}
			$res = $this->client->download( $url, $dest );
			if ( is_wp_error( $res ) && $alt ) {
				$res = $this->client->download( $alt, $dest );
			}
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
		if ( ! $user && defined( 'LIVEPULL_DEFAULT_AUTHOR' ) ) {
			$user = get_user_by( 'login', LIVEPULL_DEFAULT_AUTHOR );
		}
		if ( ! $user ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
			$user   = $admins ? $admins[0] : false;
		}
		return $this->authors[ $login ] = $user ? (int) $user->ID : 0;
	}
}

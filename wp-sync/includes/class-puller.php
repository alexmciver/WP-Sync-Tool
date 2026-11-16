<?php
defined( 'ABSPATH' ) || exit;

/**
 * The pull workflow, shared by the WP admin screen and WP-CLI.
 *
 * A pull is a series of small steps (one page of changes each) so it never hits PHP
 * time limits from the browser, and can resume where it stopped: the cursor is saved
 * after every page.
 */
final class WPSync_Puller {

	const LOCK = 'wpsync_lock';

	public static function client() {
		return new WPSync_Client( WPSync_Config::remote(), WPSync_Config::remote_secret() );
	}

	public static function empty_report() {
		return array( 'created' => array(), 'updated' => array(), 'deleted' => array(), 'conflicts' => array(), 'skipped' => array(), 'errors' => array(), 'files' => 0 );
	}

	public static function merge_report( array $into, array $r ) {
		foreach ( array( 'created', 'updated', 'deleted', 'skipped', 'errors' ) as $k ) {
			$into[ $k ] = array_merge( $into[ $k ], $r[ $k ] );
		}
		$into['conflicts'] = array_merge( $into['conflicts'], $r['conflicts'] );
		$into['files']    += $r['files'];
		return $into;
	}

	/**
	 * Check configuration and reach live.
	 *
	 * @param array $notices Filled with informational messages.
	 * @return array|WP_Error Live status.
	 */
	public static function connect( &$notices = array(), $allow_remote_change = false ) {
		$notices = array();
		if ( is_multisite() ) {
			return new WP_Error( 'wpsync_multisite', 'Multisite networks are not supported yet.' );
		}
		if ( ! WPSync_Config::remote() ) {
			return new WP_Error( 'wpsync_no_remote', 'Add the live site URL and key first.' );
		}
		if ( strlen( WPSync_Config::remote_secret() ) < 32 ) {
			return new WP_Error( 'wpsync_no_secret', 'The key must be at least 32 characters and match the one on the live site.' );
		}
		$remote = self::client()->get( 'status' );
		if ( is_wp_error( $remote ) ) {
			$hint = '';
			$code = (int) ( is_array( $remote->get_error_data() ) && isset( $remote->get_error_data()['status'] ) ? $remote->get_error_data()['status'] : 0 );
			if ( in_array( $code, array( 401, 403 ), true ) && 'Invalid sync key.' !== $remote->get_error_message() ) {
				$hint = ' A firewall or security plugin on live may be blocking the request (allow ?rest_route=/wpsync/v1/).';
			} elseif ( 404 === $code ) {
				$hint = ' Is WP Sync installed in wp-content/mu-plugins on the live site?';
			}
			return new WP_Error( 'wpsync_unreachable', 'Could not reach live: ' . $remote->get_error_message() . $hint );
		}

		$host = function ( $u ) {
			return strtolower( preg_replace( '#^www\.#', '', (string) wp_parse_url( $u, PHP_URL_HOST ) ) . ':' . (string) wp_parse_url( $u, PHP_URL_PORT ) . (string) wp_parse_url( untrailingslashit( $u ), PHP_URL_PATH ) );
		};
		if ( $host( $remote['home'] ) === $host( home_url() ) ) {
			return new WP_Error( 'wpsync_self', 'The live site URL points at this same site. Only pull into a local or staging copy.' );
		}

		if ( isset( $remote['site_key'] ) && $remote['site_key'] === WPSync_Config::site_key() ) {
			WPSync_Config::mark_as_clone();
		}
		if ( $detected = get_option( 'wpsync_clone_detected' ) ) {
			$notices[] = "This database is a fresh copy of live (detected {$detected} UTC). Pulls start from the moment it was copied.";
			delete_option( 'wpsync_clone_detected' );
		}
		if ( version_compare( $remote['version'], WPSync_Config::VERSION, '!=' ) ) {
			$notices[] = "WP Sync versions differ (live {$remote['version']}, this site " . WPSync_Config::VERSION . '). Keep them the same.';
		}
		$stored = get_option( 'wpsync_remote_home' );
		if ( $stored && $stored !== $remote['home'] && ! $allow_remote_change ) {
			return new WP_Error( 'wpsync_remote_changed', "Last pull was from {$stored}, but the live URL is now {$remote['home']}. Use catch-up mode (pick a date) to start fresh." );
		}
		if ( ! get_option( 'wpsync_cursor' ) && ! get_option( 'wpsync_clone_floor' ) ) {
			$notices[] = 'First pull: this brings everything changed on live since WP Sync was installed there. If your local copy is older than that, use catch-up mode with the date you made the copy.';
		}
		return $remote;
	}

	/**
	 * @param array $opts dry_run, theirs, since (Y-m-d), from (int), skip_backup, redownload, url_replace, force
	 * @return array|WP_Error state
	 */
	public static function start( array $opts ) {
		$opts  = wp_parse_args( $opts, array( 'dry_run' => false, 'theirs' => false, 'since' => '', 'from' => null, 'skip_backup' => false, 'redownload' => false, 'url_replace' => true, 'force' => false ) );
		$since = trim( (string) $opts['since'] );
		if ( '' !== $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			return new WP_Error( 'wpsync_bad_date', 'Catch-up date must be YYYY-MM-DD.' );
		}

		$notices = array();
		$remote  = self::connect( $notices, '' !== $since || null !== $opts['from'] );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( ! $opts['dry_run'] ) {
			$lock = get_transient( self::LOCK );
			if ( $lock && ! $opts['force'] ) {
				return new WP_Error( 'wpsync_locked', 'Another pull is already running (started ' . human_time_diff( (int) $lock ) . ' ago). It unlocks automatically after 15 minutes of inactivity.' );
			}
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}

		WPSync_Ids::backfill();

		return array(
			'remote'  => $remote,
			'mode'    => '' !== $since ? 'scan' : 'changes',
			'since'   => $since,
			'after'   => '' !== $since ? 0 : ( null !== $opts['from'] ? (int) $opts['from'] : (int) get_option( 'wpsync_cursor', 0 ) ),
			'opts'    => $opts,
			'backup'  => ( ! $opts['dry_run'] && ! $opts['skip_backup'] ),
			'backup_file' => '',
			'page'    => 0,
			'done'    => false,
			'report'  => self::empty_report(),
			'notices' => $notices,
		);
	}

	/** Process one page of changes. @return array|WP_Error updated state */
	public static function step( array $state ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$dry    = (bool) $state['opts']['dry_run'];
		$params = array( 'after' => $state['after'], 'limit' => WPSync_Config::page_size() );
		if ( 'scan' === $state['mode'] ) {
			$params['since'] = $state['since'];
		}
		$res = self::client()->get( $state['mode'], $params );
		if ( is_wp_error( $res ) ) {
			self::unlock( $state );
			return new WP_Error( 'wpsync_fetch', 'Fetching changes from live failed: ' . $res->get_error_message() . ( $state['page'] ? ' Progress so far is saved; run the pull again to continue.' : '' ) );
		}

		if ( ! empty( $res['items'] ) ) {
			if ( $state['backup'] ) {
				$file = WPSync_Backup::create();
				if ( is_wp_error( $file ) ) {
					self::unlock( $state );
					return new WP_Error( 'wpsync_backup', 'Backup failed, so nothing was changed: ' . $file->get_error_message() );
				}
				$state['backup']      = false;
				$state['backup_file'] = $file;
			}
			$importer = new WPSync_Importer( self::client(), $state['remote'], array(
				'dry_run'     => $dry,
				'theirs'      => (bool) $state['opts']['theirs'],
				'redownload'  => (bool) $state['opts']['redownload'],
				'url_replace' => (bool) $state['opts']['url_replace'],
			) );
			$report          = $importer->run( $res['items'] );
			$state['report'] = self::merge_report( $state['report'], $report );
			if ( ! $dry ) {
				self::store_conflicts( $report );
			}
		}

		$state['after'] = (int) $res['last_id'];
		$state['page']++;
		if ( ! $dry && 'changes' === $state['mode'] ) {
			self::save_cursor( $state['after'], $state['remote'] );
		}
		if ( ! $dry ) {
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}
		if ( empty( $res['has_more'] ) ) {
			$state['done'] = true;
			self::finish( $state );
		}
		return $state;
	}

	private static function finish( array $state ) {
		if ( ! $state['opts']['dry_run'] ) {
			if ( 'scan' === $state['mode'] ) {
				self::save_cursor( (int) $state['remote']['last_id'], $state['remote'] );
			}
			update_option( 'wpsync_last_pull', gmdate( 'Y-m-d H:i:s' ), false );
			self::after_pull( $state['report'] );
		}
		self::unlock( $state );
	}

	private static function unlock( array $state ) {
		if ( empty( $state['opts']['dry_run'] ) ) {
			delete_transient( self::LOCK );
		}
	}

	/** Clear caches so pulled content shows straight away. */
	public static function after_pull( array $report ) {
		wp_cache_flush();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
		}
		if ( function_exists( 'wc_update_product_lookup_tables' ) && ( $report['created'] || $report['updated'] ) ) {
			wc_update_product_lookup_tables();
		}
		do_action( 'wpsync_after_pull', $report );
	}

	public static function save_cursor( $cursor, array $remote ) {
		update_option( 'wpsync_cursor', (int) $cursor, false );
		update_option( 'wpsync_remote_home', $remote['home'], false );
	}

	/* ============================================================ Conflicts */

	public static function conflicts() {
		return (array) get_option( 'wpsync_conflicts', array() );
	}

	public static function store_conflicts( array $report ) {
		$stored  = self::conflicts();
		$changed = WPSync_Importer::locally_changed();
		foreach ( array_keys( $stored ) as $key ) {
			if ( ! isset( $changed[ $key ] ) ) {
				unset( $stored[ $key ] ); // Pulled since, so no longer in conflict.
			}
		}
		foreach ( $report['conflicts'] as $key => $c ) {
			$c['when']      = gmdate( 'Y-m-d H:i' );
			$stored[ $key ] = $c;
		}
		update_option( 'wpsync_conflicts', $stored, false );
	}

	public static function forget_conflict( $key ) {
		$stored = self::conflicts();
		unset( $stored[ $key ] );
		update_option( 'wpsync_conflicts', $stored, false );
	}

	/** Overwrite one local item with live's version. @return array|WP_Error report */
	public static function take( $key ) {
		list( $kind, $sid ) = array_pad( explode( ':', (string) $key, 2 ), 2, '' );
		if ( ! in_array( $kind, array( 'post', 'term' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return new WP_Error( 'wpsync_bad_key', 'Unknown item.' );
		}
		$notices = array();
		$remote  = self::connect( $notices, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		$obj = self::client()->get( 'object', array( 'type' => $kind, 'id' => $sid ) );
		if ( is_wp_error( $obj ) ) {
			return new WP_Error( 'wpsync_gone', 'Could not fetch it from live (' . $obj->get_error_message() . '). If it was deleted on live, delete it here too, then choose "Keep mine" to dismiss.' );
		}
		$file = WPSync_Backup::create();
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$importer = new WPSync_Importer( self::client(), $remote, array( 'force' => array( $key ) ) );
		$report   = $importer->run( array( $obj ) );
		self::forget_conflict( $key );
		self::store_conflicts( $report );
		self::after_pull( $report );
		return $report;
	}

	public static function keep( $key ) {
		self::forget_conflict( $key );
	}

	/** Turn a local ID or sync ID into "type:sync_id". @return string|WP_Error */
	public static function key_from( $arg, $kind ) {
		if ( preg_match( '/^[a-f0-9]{32}$/', $arg ) ) {
			return $kind . ':' . $arg;
		}
		$sid = 'post' === $kind ? WPSync_Ids::for_post( (int) $arg, false ) : WPSync_Ids::for_term( (int) $arg, false );
		return $sid ? $kind . ':' . $sid : new WP_Error( 'wpsync_unknown', "No synced {$kind} with ID {$arg} on this site." );
	}

	/** Rows describing everything changed on this site since it was last pulled. */
	public static function local_changes() {
		$pm   = WPSync_Ids::map( 'post' );
		$tm   = WPSync_Ids::map( 'term' );
		$rows = array();
		foreach ( WPSync_Importer::locally_changed() as $key => $action ) {
			list( $type, $sid ) = explode( ':', $key, 2 );
			if ( 'post' === $type ) {
				$id   = isset( $pm[ $sid ] ) ? $pm[ $sid ] : 0;
				$post = $id ? get_post( $id ) : null;
				$name = $post ? sprintf( '%s "%s"', $post->post_type, '' !== $post->post_title ? $post->post_title : '(no title)' ) : '(deleted)';
				$edit = $post ? get_edit_post_link( $id, 'raw' ) : '';
			} else {
				$id   = isset( $tm[ $sid ] ) ? $tm[ $sid ] : 0;
				$term = $id ? get_term( $id ) : null;
				$ok   = $term && ! is_wp_error( $term );
				$name = $ok ? sprintf( '%s "%s"', $term->taxonomy, $term->name ) : '(deleted)';
				$edit = $ok ? get_edit_term_link( $id, $term->taxonomy ) : '';
			}
			$rows[] = array( 'id' => $id, 'item' => $name, 'action' => 'delete' === $action ? 'deleted' : 'edited', 'edit' => (string) $edit );
		}
		return $rows;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * The pull workflow, shared by the WP admin screen and WP-CLI.
 *
 * A pull is a series of small steps (one page of changes each) so it never hits PHP
 * time limits from the browser, and can resume where it stopped: the cursor is saved
 * after every page.
 */
final class LivePull_Puller {

	const LOCK = 'livepull_lock';

	public static function client() {
		return new LivePull_Client( LivePull_Config::remote(), LivePull_Config::remote_secret() );
	}

	/** http:// to a public host (local dev domains and private networks are fine). */
	public static function insecure_remote() {
		$url = LivePull_Config::remote();
		if ( 'http' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || preg_match( '/\.(local|test|localhost|lan|internal|ddev\.site|lndo\.site)$/', $host ) ) {
			return false;
		}
		return ! preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host );
	}

	public static function empty_report() {
		return array( 'created' => array(), 'updated' => array(), 'deleted' => array(), 'conflicts' => array(), 'skipped' => array(), 'errors' => array(), 'held' => array(), 'files' => 0 );
	}

	public static function merge_report( array $into, array $r ) {
		unset( $r['downloads'] );
		foreach ( array( 'created', 'updated', 'deleted', 'skipped', 'errors', 'held' ) as $k ) {
			if ( ! isset( $r[ $k ] ) ) {
				continue;
			}
			if ( ! isset( $into[ $k ] ) ) {
				$into[ $k ] = array();
			}
			$into[ $k ] = array_merge( $into[ $k ], $r[ $k ] );
		}
		$into['conflicts'] = array_merge( $into['conflicts'], $r['conflicts'] );
		$into['files']    += isset( $r['files'] ) ? $r['files'] : 0;
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
			return new WP_Error( 'livepull_multisite', 'Multisite networks are not supported yet.' );
		}
		if ( ! LivePull_Config::remote() ) {
			return new WP_Error( 'livepull_no_remote', 'Add the live site URL and key first.' );
		}
		if ( strlen( LivePull_Config::remote_secret() ) < 32 ) {
			return new WP_Error( 'livepull_no_secret', 'The key must be at least 32 characters and match the one on the live site.' );
		}
		if ( self::insecure_remote() ) {
			$notices[] = 'The live site URL starts with http://, so the LivePull key and your content travel unencrypted. Use https:// if the live site supports it.';
		}
		$remote = self::client()->get( 'status' );
		if ( is_wp_error( $remote ) ) {
			$hint = '';
			if ( 'Invalid sync key.' === $remote->get_error_message() ) {
				list( $k, $from ) = LivePull_Config::remote_secret_source();
				return new WP_Error( 'livepull_bad_key', sprintf(
					'Live rejected the key. This site is using the key ending %s, from %s. On live, open Tools → LivePull, click Show, and check it ends the same. If not, copy it and paste it under Connection here.',
					LivePull_Config::key_hint( $k ),
					$from
				) );
			}
			$code = (int) ( is_array( $remote->get_error_data() ) && isset( $remote->get_error_data()['status'] ) ? $remote->get_error_data()['status'] : 0 );
			if ( in_array( $code, array( 401, 403 ), true ) && 'Invalid sync key.' !== $remote->get_error_message() ) {
				$hint = ' A firewall or security plugin on live may be blocking the request (allow ?rest_route=/livepull/v1/).';
			} elseif ( 404 === $code && false === strpos( $remote->get_error_message(), 'mu-plugins' ) ) {
				$hint = ' Is LivePull installed in wp-content/mu-plugins on the live site?';
			}
			return new WP_Error( 'livepull_unreachable', 'Could not reach live: ' . $remote->get_error_message() . $hint );
		}

		$host = function ( $u ) {
			return strtolower( preg_replace( '#^www\.#', '', (string) wp_parse_url( $u, PHP_URL_HOST ) ) . ':' . (string) wp_parse_url( $u, PHP_URL_PORT ) . (string) wp_parse_url( untrailingslashit( $u ), PHP_URL_PATH ) );
		};
		if ( $host( $remote['home'] ) === $host( home_url() ) ) {
			return new WP_Error( 'livepull_self', 'The live site URL points at this same site. Only pull into a local or staging copy.' );
		}

		if ( isset( $remote['site_key'] ) && $remote['site_key'] === LivePull_Config::site_key() ) {
			LivePull_Config::mark_as_clone();
		}
		if ( $detected = get_option( 'livepull_clone_detected' ) ) {
			$notices[] = "This database is a fresh copy of live (detected {$detected} UTC). Pulls start from the moment it was copied.";
			delete_option( 'livepull_clone_detected' );
		}
		if ( version_compare( $remote['version'], LivePull_Config::VERSION, '!=' ) ) {
			$notices[] = "LivePull versions differ (live {$remote['version']}, this site " . LivePull_Config::VERSION . '). Keep them the same.';
		}
		$stored = get_option( 'livepull_remote_home' );
		if ( $stored && $stored !== $remote['home'] && ! $allow_remote_change ) {
			return new WP_Error( 'livepull_remote_changed', "Last pull was from {$stored}, but the live URL is now {$remote['home']}. Use catch-up mode (pick a date) to start fresh." );
		}
		if ( ! get_option( 'livepull_full_synced' ) && ! get_option( 'livepull_clone_floor' ) && ! get_option( 'livepull_last_pull' ) ) {
			$notices[] = 'First time? A normal pull only brings changes made on live since LivePull was installed there. To bring across everything that already differs, tick "Pull all content from live" under More options.';
		}
		return $remote;
	}

	/**
	 * @param array $opts dry_run, theirs, since (Y-m-d), from (int), skip_backup, redownload, url_replace, force,
	 *                    exclude (item keys to hold back this time), include_held (held keys to pull now),
	 *                    source ('manual', 'cli' or 'scheduled', for the history)
	 * @return array|WP_Error state
	 */
	public static function start( array $opts ) {
		$opts  = wp_parse_args( $opts, array( 'dry_run' => false, 'theirs' => false, 'since' => '', 'from' => null, 'skip_backup' => false, 'redownload' => false, 'url_replace' => true, 'force' => false, 'exclude' => array(), 'include_held' => array(), 'source' => 'manual' ) );
		$opts['exclude']      = array_values( array_filter( array_map( 'strval', (array) $opts['exclude'] ) ) );
		$opts['include_held'] = array_values( array_filter( array_map( 'strval', (array) $opts['include_held'] ) ) );
		$since = trim( (string) $opts['since'] );
		if ( '' !== $since && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
			return new WP_Error( 'livepull_bad_date', 'Catch-up date must be YYYY-MM-DD.' );
		}

		$notices = array();
		$remote  = self::connect( $notices, '' !== $since || null !== $opts['from'] );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( ! $opts['dry_run'] ) {
			$lock = get_transient( self::LOCK );
			if ( $lock && ! $opts['force'] ) {
				return new WP_Error( 'livepull_locked', 'Another pull is already running (started ' . human_time_diff( (int) $lock ) . ' ago). It unlocks automatically after 15 minutes of inactivity.' );
			}
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}

		LivePull_Ids::maybe_backfill();
		LivePull_Importer::reset_cache();

		// Safety net: our position in live's change list is beyond live's newest change
		// (live restored from a backup, or a mistaken copy detection). Rather than say
		// "nothing to pull", check everything changed on live since the last pull.
		$cursor_now = (int) get_option( 'livepull_cursor', 0 );
		$behind     = $cursor_now > 0 && ! empty( $remote['min_id'] ) && $cursor_now < (int) $remote['min_id'] - 1;
		if ( '' === $since && null === $opts['from'] && ( $cursor_now > (int) $remote['last_id'] || $behind ) ) {
			$last  = get_option( 'livepull_last_pull' );
			$since = $last ? gmdate( 'Y-m-d', strtotime( $last . ' UTC' ) - DAY_IN_SECONDS ) : '1970-01-01';
			$notices[] = $behind
				? "It's been a while since the last pull and live has tidied away its older change history, so this pull checks everything changed on live since {$since}."
				: "This site's place in live's change list was out of step, so this pull checks everything changed on live since {$since} instead.";
		}

		// Held-back items: a preview lists them all (so they can be picked); a pull fetches the
		// ones picked this time.
		$held       = self::held();
		$held_queue = $opts['dry_run'] ? array_keys( $held ) : array_values( array_intersect( $opts['include_held'], array_keys( $held ) ) );

		return array(
			'held_queue' => $held_queue,
			'preview'    => array(),
			'remote'  => $remote,
			'mode'    => '' !== $since ? 'scan' : 'changes',
			'since'   => $since,
			'after'   => '' !== $since ? 0 : ( null !== $opts['from'] ? (int) $opts['from'] : (int) get_option( 'livepull_cursor', 0 ) ),
			'opts'    => $opts,
			'backup'  => ( ! $opts['dry_run'] && ! $opts['skip_backup'] ),
			'backup_file' => '',
			'page'    => 0,
			'done'    => false,
			'report'  => self::empty_report(),
			'notices' => $notices,
		);
	}

	/** Seconds of work per browser request before reporting progress. */
	const BUDGET = 8;

	/**
	 * Do up to ~15 seconds of work: fetch a page of changes, import it a few items at a
	 * time, then download its media files. The cursor only moves once a page is fully
	 * done (items + files), so an interrupted pull simply repeats that page next time.
	 *
	 * @param float $budget Seconds to work before returning (0 = until finished, for WP-CLI).
	 * @return array|WP_Error updated state
	 */
	public static function step( array $state, $budget = self::BUDGET ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$t0  = microtime( true );
		$dry = (bool) $state['opts']['dry_run'];
		$state += array( 'held_queue' => array(), 'preview' => array(), 'pending' => array(), 'downloads' => array(), 'dl_total' => 0, 'dl_done' => 0, 'page_open' => false, 'page_last_id' => 0, 'page_has_more' => false, 'activity' => '' );
		$out = function () use ( $t0, $budget ) {
			return $budget > 0 && ( microtime( true ) - $t0 ) >= $budget;
		};

		while ( ! $state['done'] ) {
			// 1. Media files for the current page.
			if ( $state['downloads'] ) {
				$client = self::client();
				while ( $state['downloads'] && ! $out() ) {
					$f    = array_shift( $state['downloads'] );
					$dest = LivePull_Importer::safe_dest( $f['path'] );
					$state['activity'] = sprintf( 'Downloading media %d of %d', $state['dl_done'] + 1, $state['dl_total'] );
					if ( $dest && ( ! file_exists( $dest ) || $state['opts']['redownload'] ) ) {
						$res = $client->download( $f['url'], $dest );
						if ( is_wp_error( $res ) && ! empty( $f['alt'] ) ) {
							$res = $client->download( $f['alt'], $dest );
						}
						if ( is_wp_error( $res ) ) {
							$state['report']['errors'][] = 'Media download failed: ' . $res->get_error_message();
						} else {
							$state['report']['files']++;
						}
					}
					$state['dl_done']++;
				}
				if ( $out() ) {
					break;
				}
				continue;
			}

			// 2. Import the current page a few items at a time.
			if ( $state['pending'] ) {
				$chunk = self::filter_chunk( $state, array_splice( $state['pending'], 0, $dry ? 1 : 10 ) );
				if ( ! $chunk ) {
					continue;
				}
				if ( ! $dry && $state['backup'] ) {
					$backup = self::ensure_backup( $state );
					if ( is_wp_error( $backup ) ) {
						return $backup;
					}
				}
				$importer = new LivePull_Importer( self::client(), $state['remote'], array(
					'dry_run'     => $dry,
					'theirs'      => (bool) $state['opts']['theirs'],
					'redownload'  => (bool) $state['opts']['redownload'],
					'url_replace' => (bool) $state['opts']['url_replace'],
					'defer_files' => true,
				) );
				$report = $importer->run( $chunk );
				if ( $dry ) {
					self::note_preview( $state, $chunk[0], $report );
				} else {
					self::release_held( $chunk );
				}
				$queued          = $report['downloads'];
				$state['report'] = self::merge_report( $state['report'], $report );
				$state['downloads'] = array_merge( $state['downloads'], $queued );
				$state['dl_total'] += count( $queued );
				$state['activity']  = sprintf( 'Importing batch %d: %d left in this batch', $state['page'], count( $state['pending'] ) );
				if ( ! $dry ) {
					self::store_conflicts( $report );
				}
				if ( $out() ) {
					break;
				}
				continue;
			}

			// 3. Current page fully done: move the cursor.
			if ( $state['page_open'] ) {
				$state['page_open'] = false;
				$state['after']     = $state['page_last_id'];
				if ( ! $dry && 'changes' === $state['mode'] ) {
					self::save_cursor( $state['after'], $state['remote'] );
				}
				if ( ! $state['page_has_more'] ) {
					$state['done'] = true;
					break;
				}
				if ( $out() ) {
					break;
				}
			}

			// 3b. Held-back items picked for this pull (or all of them, for a preview).
			if ( $state['held_queue'] ) {
				$client = self::client();
				while ( $state['held_queue'] && ! $out() ) {
					$key = array_shift( $state['held_queue'] );
					list( $type, $sid ) = array_pad( explode( ':', $key, 2 ), 2, '' );
					$state['activity'] = 'Fetching items held back earlier…';
					$obj = $client->get( 'object', array( 'type' => $type, 'id' => $sid ) );
					if ( is_wp_error( $obj ) ) {
						if ( 'livepull_not_found' === $obj->get_error_code() || false !== strpos( $obj->get_error_message(), 'Not found' ) ) {
							$obj = array( 'type' => $type, 'sync_id' => $sid, 'deleted' => true ); // Gone from live since.
						} else {
							$state['report']['errors'][] = 'Could not fetch held-back item: ' . $obj->get_error_message();
							continue;
						}
					}
					$obj['_held']       = true;
					$state['pending'][] = $obj;
				}
				if ( $state['pending'] ) {
					$state['pending'] = LivePull_Importer::sort_items( $state['pending'] );
				}
				if ( $out() ) {
					break;
				}
				continue;
			}

			// 4. Fetch the next page.
			$state['activity'] = 'Asking live for changes…';
			$params = array( 'after' => $state['after'], 'limit' => LivePull_Config::page_size() );
			if ( 'scan' === $state['mode'] ) {
				$params['since'] = $state['since'];
				if ( LivePull_Config::group_on( 'comments' ) ) {
					$params['comments'] = 1;
				}
			}
			$res = self::client()->get( $state['mode'], $params );
			if ( is_wp_error( $res ) ) {
				self::unlock( $state );
				return new WP_Error( 'livepull_fetch', 'Fetching changes from live failed: ' . $res->get_error_message() . ( $state['page'] ? ' Progress so far is saved; pull again to continue.' : '' ) );
			}
			$state['page']++;
			$state['page_open']     = true;
			$state['page_last_id']  = (int) $res['last_id'];
			$state['page_has_more'] = ! empty( $res['has_more'] );
			if ( ! empty( $res['items'] ) ) {
				$state['pending'] = LivePull_Importer::sort_items( $res['items'] );
			}
			if ( $out() ) {
				break;
			}
		}

		if ( ! $dry ) {
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}
		if ( $state['done'] ) {
			$state['activity'] = 'Finished';
			self::finish( $state );
		}
		return $state;
	}

	/** Back up once, before the first change. @return true|WP_Error */
	private static function ensure_backup( array &$state ) {
		$state['activity'] = 'Backing up the local database…';
		$file              = LivePull_Backup::create();
		if ( is_wp_error( $file ) ) {
			self::unlock( $state );
			return new WP_Error( 'livepull_backup', 'Backup failed, so nothing was changed: ' . $file->get_error_message() );
		}
		$state['backup']      = false;
		$state['backup_file'] = $file;
		return true;
	}

	/* ============================================================ Held back */

	const HELD = 'livepull_held';

	/** Items skipped on purpose, waiting to be pulled later: key => label, group, time. */
	public static function held() {
		$h = get_option( self::HELD, array() );
		return is_array( $h ) ? $h : array();
	}

	/** Remove items from the held-back list without pulling them ("forget"). */
	public static function forget_held( array $keys ) {
		$h = self::held();
		foreach ( $keys as $k ) {
			unset( $h[ $k ] );
		}
		update_option( self::HELD, $h, false );
	}

	/**
	 * Drop items this pull must not apply, holding them back for later: anything unticked in
	 * the preview, and anything already held unless it was picked this time.
	 */
	private static function filter_chunk( array &$state, array $chunk ) {
		// Optional kinds of content switched off on this site: skipped quietly.
		$chunk = array_values( array_filter( $chunk, array( 'LivePull_Config', 'wanted' ) ) );
		if ( $state['opts']['dry_run'] ) {
			return $chunk; // A preview shows everything else, held-back items included.
		}
		$held    = self::held();
		$exclude = array_flip( $state['opts']['exclude'] );
		$include = array_flip( $state['opts']['include_held'] );
		$keep    = array();
		$changed = false;
		foreach ( $chunk as $item ) {
			$key = ( isset( $item['type'] ) ? $item['type'] : '' ) . ':' . ( isset( $item['sync_id'] ) ? $item['sync_id'] : '' );
			if ( isset( $exclude[ $key ] ) || ( isset( $held[ $key ] ) && ! isset( $include[ $key ] ) ) ) {
				$d            = LivePull_Importer::describe( $item );
				$held[ $key ] = array( 'label' => $d['label'], 'group' => $d['group'], 'time' => time() );
				$changed      = true;
				$state['report']['held'][] = $d['label'];
				continue;
			}
			$keep[] = $item;
		}
		if ( $changed ) {
			update_option( self::HELD, $held, false );
		}
		return $keep;
	}

	/** Pulled now: no longer held back. */
	private static function release_held( array $chunk ) {
		$held = self::held();
		$n    = count( $held );
		foreach ( $chunk as $item ) {
			unset( $held[ $item['type'] . ':' . $item['sync_id'] ] );
		}
		if ( count( $held ) !== $n ) {
			update_option( self::HELD, $held, false );
		}
	}

	/** Preview: what this one item would do, for the pick list. */
	private static function note_preview( array &$state, array $item, array $report ) {
		$action = $report['conflicts'] ? 'conflict' : ( $report['created'] ? 'create' : ( $report['updated'] ? 'update' : ( $report['deleted'] ? 'delete' : '' ) ) );
		if ( '' === $action ) {
			return; // Nothing would change.
		}
		$d               = LivePull_Importer::describe( $item );
		$d['action']     = $action;
		$d['held']       = ! empty( $item['_held'] ) || isset( self::held()[ $d['key'] ] );
		$state['preview'][ $d['key'] ] = $d;
	}

	/** Stop a pull (admin Cancel / Unlock). Progress up to the last finished batch is kept. */
	public static function cancel() {
		delete_transient( self::LOCK );
	}

	private static function finish( array $state ) {
		if ( ! $state['opts']['dry_run'] ) {
			if ( 'scan' === $state['mode'] ) {
				self::save_cursor( (int) $state['remote']['last_id'], $state['remote'] );
			}
			update_option( 'livepull_last_pull', gmdate( 'Y-m-d H:i:s' ), false );
			$r = $state['report'];
			update_option( 'livepull_last_summary', array(
				'created'   => count( $r['created'] ),
				'updated'   => count( $r['updated'] ),
				'deleted'   => count( $r['deleted'] ),
				'conflicts' => count( $r['conflicts'] ),
				'files'     => (int) $r['files'],
				'errors'    => count( $r['errors'] ),
			), false );
			if ( 'scan' === $state['mode'] && $state['since'] <= '2000-01-01' ) {
				update_option( 'livepull_full_synced', gmdate( 'Y-m-d H:i:s' ), false );
			}
			LivePull_History::add( 'pull', $state['report'], array( 'source' => $state['opts']['source'], 'backup' => $state['backup_file'] ) );
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
		do_action( 'livepull_after_pull', $report );
	}

	public static function save_cursor( $cursor, array $remote ) {
		update_option( 'livepull_cursor', (int) $cursor, false );
		update_option( 'livepull_remote_home', $remote['home'], false );
	}

	/* ============================================================ Conflicts */

	public static function conflicts() {
		return (array) get_option( 'livepull_conflicts', array() );
	}

	public static function store_conflicts( array $report ) {
		$stored  = self::conflicts();
		$changed = LivePull_Importer::locally_changed();
		foreach ( array_keys( $stored ) as $key ) {
			if ( ! isset( $changed[ $key ] ) ) {
				unset( $stored[ $key ] ); // Pulled since, so no longer in conflict.
			}
		}
		foreach ( $report['conflicts'] as $key => $c ) {
			$c['when']      = gmdate( 'Y-m-d H:i' );
			$stored[ $key ] = $c;
		}
		update_option( 'livepull_conflicts', $stored, false );
	}

	public static function forget_conflict( $key ) {
		$stored = self::conflicts();
		unset( $stored[ $key ] );
		update_option( 'livepull_conflicts', $stored, false );
	}

	/** Overwrite one local item with live's version. @return array|WP_Error report */
	public static function take( $key ) {
		list( $kind, $sid ) = array_pad( explode( ':', (string) $key, 2 ), 2, '' );
		if ( ! in_array( $kind, array( 'post', 'term', 'option', 'comment' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return new WP_Error( 'livepull_bad_key', 'Unknown item.' );
		}
		$notices = array();
		$remote  = self::connect( $notices, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		$obj = self::client()->get( 'object', array( 'type' => $kind, 'id' => $sid ) );
		if ( is_wp_error( $obj ) ) {
			return new WP_Error( 'livepull_gone', 'Could not fetch it from live (' . $obj->get_error_message() . '). If it was deleted on live, delete it here too, then choose "Keep mine" to dismiss.' );
		}
		$file = LivePull_Backup::create();
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		LivePull_Importer::reset_cache();
		$importer = new LivePull_Importer( self::client(), $remote, array( 'force' => array( $key ) ) );
		$report   = $importer->run( array( $obj ) );
		self::forget_conflict( $key );
		self::store_conflicts( $report );
		self::after_pull( $report );
		return $report;
	}

	/**
	 * Live's version and this site's version of one item, field by field, for the side-by-side
	 * view of a conflict. Live's addresses are switched to this site's first, so only real
	 * differences show.
	 *
	 * @return array|WP_Error list of array( label, live, local ) rows.
	 */
	public static function compare( $key ) {
		list( $kind, $sid ) = array_pad( explode( ':', (string) $key, 2 ), 2, '' );
		if ( ! in_array( $kind, array( 'post', 'term', 'option', 'comment' ), true ) || ! preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
			return new WP_Error( 'livepull_bad_key', 'Unknown item.' );
		}
		$notices = array();
		$remote  = self::connect( $notices, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		$obj = self::client()->get( 'object', array( 'type' => $kind, 'id' => $sid ) );
		if ( is_wp_error( $obj ) ) {
			return new WP_Error( 'livepull_gone', 'It no longer exists on live, so there is nothing to compare. Choose "Keep mine" to keep this site\'s version.' );
		}
		$imp  = new LivePull_Importer( self::client(), $remote, array( 'dry_run' => true ) );
		$loc  = function ( $v ) use ( $imp ) {
			return $imp->localise( $v );
		};
		$rows = array();
		$add  = function ( $label, $live, $local ) use ( &$rows ) {
			$rows[] = array( 'label' => $label, 'live' => (string) $live, 'local' => (string) $local );
		};
		if ( 'post' === $kind ) {
			$map  = LivePull_Ids::map( 'post' );
			$post = isset( $map[ $sid ] ) ? get_post( $map[ $sid ] ) : null;
			$p    = $obj['post'];
			$add( __( 'Title', 'livepull' ), $p['post_title'], $post ? $post->post_title : '' );
			$add( __( 'Status', 'livepull' ), $p['post_status'], $post ? $post->post_status : '' );
			$add( __( 'Slug', 'livepull' ), $p['post_name'], $post ? $post->post_name : '' );
			$add( __( 'Date', 'livepull' ), $p['post_date'], $post ? $post->post_date : '' );
			$add( __( 'Last edited', 'livepull' ), $p['post_modified'], $post ? $post->post_modified : '' );
			$add( __( 'Excerpt', 'livepull' ), $loc( $p['post_excerpt'] ), $post ? $post->post_excerpt : '' );
			$add( __( 'Content', 'livepull' ), $loc( $p['post_content'] ), $post ? $post->post_content : '' );
			$local_meta = $post ? get_post_meta( $post->ID ) : array();
			$shown      = 0;
			foreach ( (array) $obj['meta'] as $mk => $vals ) {
				if ( LivePull_Config::is_ignored_meta( $mk ) || 0 === strpos( $mk, '_edit_' ) || $shown >= 20 ) {
					continue;
				}
				$live_v  = implode( "\n", array_map( $loc, array_map( 'strval', (array) $vals ) ) );
				$local_v = isset( $local_meta[ $mk ] ) ? implode( "\n", array_map( 'strval', $local_meta[ $mk ] ) ) : '';
				if ( $live_v !== $local_v ) {
					/* translators: %s: custom field name */
					$add( sprintf( __( 'Custom field: %s', 'livepull' ), $mk ), $live_v, $local_v );
					$shown++;
				}
			}
		} elseif ( 'term' === $kind ) {
			$map  = LivePull_Ids::map( 'term' );
			$term = isset( $map[ $sid ] ) ? get_term( $map[ $sid ] ) : null;
			$term = ( $term && ! is_wp_error( $term ) ) ? $term : null;
			$t    = $obj['term'];
			$add( __( 'Name', 'livepull' ), $t['name'], $term ? $term->name : '' );
			$add( __( 'Slug', 'livepull' ), $t['slug'], $term ? $term->slug : '' );
			$add( __( 'Description', 'livepull' ), $loc( $t['description'] ), $term ? $term->description : '' );
		} elseif ( 'comment' === $kind ) {
			$map = LivePull_Ids::map( 'comment' );
			$c   = isset( $map[ $sid ] ) ? get_comment( $map[ $sid ] ) : null;
			$o   = $obj['comment'];
			$add( __( 'Name', 'livepull' ), $o['comment_author'], $c ? $c->comment_author : '' );
			$add( __( 'Email', 'livepull' ), $o['comment_author_email'], $c ? $c->comment_author_email : '' );
			$add( __( 'Approved', 'livepull' ), $o['comment_approved'], $c ? $c->comment_approved : '' );
			$add( __( 'Comment', 'livepull' ), $loc( $o['comment_content'] ), $c ? $c->comment_content : '' );
		} else {
			$name = $obj['option']['name'];
			$live = $loc( LivePull_Payload::unserialize( $obj['option']['value'] ) );
			$add( $name, is_scalar( $live ) ? $live : wp_json_encode( $live, JSON_PRETTY_PRINT ), is_scalar( get_option( $name ) ) ? get_option( $name ) : wp_json_encode( get_option( $name ), JSON_PRETTY_PRINT ) );
		}
		return $rows;
	}

	public static function keep( $key ) {
		self::forget_conflict( $key );
	}

	/** Turn a local ID or sync ID into "type:sync_id". @return string|WP_Error */
	public static function key_from( $arg, $kind ) {
		if ( preg_match( '/^[a-f0-9]{32}$/', $arg ) ) {
			return $kind . ':' . $arg;
		}
		$sid = 'post' === $kind ? LivePull_Ids::for_post( (int) $arg, false ) : LivePull_Ids::for_term( (int) $arg, false );
		return $sid ? $kind . ':' . $sid : new WP_Error( 'livepull_unknown', "No synced {$kind} with ID {$arg} on this site." );
	}

	/** Rows describing everything changed on this site since it was last pulled. */
	public static function local_changes() {
		$pm   = LivePull_Ids::map( 'post' );
		$tm   = LivePull_Ids::map( 'term' );
		$rows = array();
		foreach ( LivePull_Importer::locally_changed() as $key => $action ) {
			list( $type, $sid ) = explode( ':', $key, 2 );
			if ( 'option' === $type ) {
				$n      = LivePull_Importer::option_name( $sid );
				$rows[] = array( 'id' => 0, 'item' => 0 === strpos( $n, 'theme_mods_' ) ? 'setting "Menu locations / logo"' : 'setting "' . $n . '"', 'action' => 'edited', 'edit' => '' );
				continue;
			}
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

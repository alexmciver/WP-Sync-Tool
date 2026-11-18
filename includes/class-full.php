<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Search-replace inside values that may be PHP-serialized (any depth, including serialized
 * strings inside serialized data), recalculating string lengths so nothing gets corrupted.
 * Never unserializes, so no plugin classes are needed and nothing is executed.
 */
final class LivePull_Serial {

	public static function replace( $value, array $from, array $to ) {
		if ( ! is_string( $value ) || '' === $value || ! $from ) {
			return $value;
		}
		if ( is_serialized( $value ) ) {
			$p   = 0;
			$out = '';
			if ( self::walk( $value, $p, $out, $from, $to ) && strlen( $value ) === $p ) {
				return $out;
			}
			return $value; // Unparseable serialized data: leave it alone rather than corrupt it.
		}
		return self::text( $value, $from, $to );
	}

	/**
	 * Replace URLs/paths in plain text, only where each one ends: "https://site.co" changes in
	 * "https://site.co/page" or "https://site.co" but not in "https://site.co.uk" or
	 * "https://site.co:8080". One pass, longest match first, so nothing is replaced twice.
	 */
	public static function text( $value, array $from, array $to ) {
		if ( ! is_string( $value ) || '' === $value || ! $from ) {
			return $value;
		}
		static $cache = array();
		$key = md5( implode( "\0", $from ) );
		if ( ! isset( $cache[ $key ] ) ) {
			$sorted = $from;
			usort( $sorted, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );
			$cache[ $key ] = '~(' . implode( '|', array_map( function ( $f ) {
				return preg_quote( $f, '~' );
			}, $sorted ) ) . ')(?![A-Za-z0-9_-]|\.[A-Za-z0-9]|:\d)~';
		}
		$map = array_combine( $from, $to );
		$out = preg_replace_callback( $cache[ $key ], function ( $m ) use ( $map ) {
			return $map[ $m[1] ];
		}, $value );
		return null === $out ? $value : $out;
	}

	private static function walk( $s, &$p, &$out, $from, $to ) {
		$t = isset( $s[ $p ] ) ? $s[ $p ] : '';
		switch ( $t ) {
			case 'N':
				if ( 'N;' !== substr( $s, $p, 2 ) ) {
					return false;
				}
				$out .= 'N;';
				$p   += 2;
				return true;
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $p );
				if ( false === $end ) {
					return false;
				}
				$out .= substr( $s, $p, $end - $p + 1 );
				$p    = $end + 1;
				return true;
			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $p ) ) {
					return false;
				}
				$len   = (int) $m[1];
				$start = $p + strlen( $m[0] );
				if ( '";' !== substr( $s, $start + $len, 2 ) ) {
					return false;
				}
				$new  = self::replace( substr( $s, $start, $len ), $from, $to );
				$out .= 's:' . strlen( $new ) . ':"' . $new . '";';
				$p    = $start + $len + 2;
				return true;
			case 'a':
			case 'O':
				$re = 'a' === $t ? '/\Ga:(\d+):\{/' : '/\GO:\d+:"[^"]*":(\d+):\{/';
				if ( ! preg_match( $re, $s, $m, 0, $p ) ) {
					return false;
				}
				$out .= $m[0];
				$p   += strlen( $m[0] );
				for ( $i = 0, $n = (int) $m[1] * 2; $i < $n; $i++ ) {
					if ( ! self::walk( $s, $p, $out, $from, $to ) ) {
						return false;
					}
				}
				if ( '}' !== ( isset( $s[ $p ] ) ? $s[ $p ] : '' ) ) {
					return false;
				}
				$out .= '}';
				$p++;
				return true;
			case 'C': // Custom-serialized object: opaque payload, copied as-is.
				if ( ! preg_match( '/\GC:\d+:"[^"]*":(\d+):\{/', $s, $m, 0, $p ) ) {
					return false;
				}
				$total = strlen( $m[0] ) + (int) $m[1] + 1;
				$out  .= substr( $s, $p, $total );
				$p    += $total;
				return true;
		}
		return false;
	}
}

/**
 * "Copy everything from live": a full copy of live's database and uploads into this site.
 *
 * Live side: read-only endpoints listing tables, rows and upload files (key-protected,
 * can be switched off on live's LivePull screen).
 *
 * Local side: a resumable, time-budgeted job.
 *   1. Back up this whole database.
 *   2. Load every live table into temporary tables (URLs, paths and table prefix rewritten).
 *   3. Download missing/changed files from live's uploads folder.
 *   4. Swap the temporary tables in at once, then restore this site's URL, its LivePull
 *      connection and "discourage search engines", and mark the database as a fresh copy.
 * Nothing on this site changes until step 4, so stopping part-way is harmless.
 */
final class LivePull_Full {

	const TMP = 'lpulltmp_';
	const OLD = 'lpullold_';

	/* ============================================================== Live side */

	/**
	 * Full copies hand over the whole database (users, settings, API keys in plugin settings),
	 * so they're off unless switched on – for an hour, or always – on live's LivePull screen.
	 */
	public static function allowed() {
		if ( defined( 'LIVEPULL_ALLOW_FULL_COPY' ) ) {
			return (bool) LIVEPULL_ALLOW_FULL_COPY;
		}
		$v = get_option( 'livepull_allow_full_copy', 'no' );
		if ( 'always' === $v || 'yes' === $v ) {
			return true;
		}
		return is_numeric( $v ) && (int) $v > time();
	}

	/** 'off' | 'always' | seconds left. */
	public static function permission() {
		if ( defined( 'LIVEPULL_ALLOW_FULL_COPY' ) ) {
			return LIVEPULL_ALLOW_FULL_COPY ? 'always' : 'off';
		}
		$v = get_option( 'livepull_allow_full_copy', 'no' );
		if ( 'always' === $v || 'yes' === $v ) {
			return 'always';
		}
		return ( is_numeric( $v ) && (int) $v > time() ) ? (int) $v - time() : 'off';
	}

	/** Table names (without prefix) that are never worth copying (logs, sessions, caches). */
	private static function skipped_tables() {
		return apply_filters( 'livepull_full_copy_skip_tables', array(
			'woocommerce_sessions', 'actionscheduler_logs', 'redirection_404', 'redirection_logs',
			'wfhits', 'wflogins', 'wflivetraffichuman', 'wfblockediplog', 'wfcrawlers', 'wffilechanges', 'wfnotifications',
			'wfstatus', 'wftrafficrates', 'wfknownfilelist', 'wffilemods', 'wfsnipcache', 'wfreversecache',
			'wsal_occurrences', 'wsal_metadata', 'simple_history', 'simple_history_contexts', 'litespeed_url', 'litespeed_url_file',
		) );
	}

	public static function rest_tables() {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$skip   = self::skipped_tables();
		$out    = array();
		foreach ( $tables as $full ) {
			$name = substr( $full, strlen( $wpdb->prefix ) );
			if ( in_array( $name, $skip, true ) || 0 === strpos( $name, self::TMP ) || 0 === strpos( $name, self::OLD ) ) {
				continue;
			}
			if ( is_multisite() && preg_match( '/^\d+_/', $name ) ) {
				continue;
			}
			$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $full ), ARRAY_N );
			if ( ! $create || false === stripos( $create[1], 'CREATE TABLE' ) ) {
				continue; // Views etc.
			}
			$keys = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $full ) );
			$pk   = array();
			foreach ( $keys as $k ) {
				$pk[ (int) $k->Seq_in_index ] = $k->Column_name;
			}
			ksort( $pk );
			$out[] = array(
				'name'   => $name,
				'create' => $create[1],
				'rows'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $full ) ),
				'pk'     => array_values( $pk ),
			);
		}
		return array( 'prefix' => $wpdb->prefix, 'tables' => $out );
	}

	/**
	 * Rows of one table. Single-column numeric primary keys page by key (fast on big
	 * tables); others page by offset. Stops at ~2 MB so responses stay small.
	 */
	public static function rest_rows( WP_REST_Request $r ) {
		global $wpdb;
		$name  = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $r['table'] );
		$full  = $wpdb->prefix . $name;
		$limit = max( 1, min( 2000, (int) $r['limit'] ) );
		if ( '' === $name || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) !== $full ) {
			return new WP_Error( 'livepull_no_table', 'Unknown table.', array( 'status' => 404 ) );
		}
		$pk     = (string) $r['pk'];
		$cursor = (string) $r['cursor'];
		$keyset = '' !== $pk && preg_match( '/^[A-Za-z0-9_]+$/', $pk );
		if ( $keyset ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $full, $pk, '' === $cursor ? '-1' : $cursor, $pk, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $full, (int) $cursor, $limit ), ARRAY_A );
		}
		$out      = array();
		$bytes    = 0;
		$last     = null;
		$consumed = 0;
		foreach ( $rows as $row ) {
			$consumed++;
			// Logged-in sessions stay on live: copying them would let a copy of the database
			// be used to hijack live logins.
			if ( 'usermeta' === $name && isset( $row['meta_key'] ) && 'session_tokens' === $row['meta_key'] ) {
				$last = $row;
				continue;
			}
			$enc = array();
			foreach ( $row as $col => $v ) {
				if ( null !== $v && ! preg_match( '//u', $v ) ) {
					$v = array( 'b64' => base64_encode( $v ) ); // phpcs:ignore -- binary-safe transport.
				}
				$enc[ $col ] = $v;
				$bytes      += is_string( $v ) ? strlen( $v ) : 64;
			}
			$out[] = $enc;
			$last  = $row;
			if ( $bytes > 2 * MB_IN_BYTES ) {
				break;
			}
		}
		$next     = $keyset ? ( $last ? (string) $last[ $pk ] : $cursor ) : (string) ( (int) $cursor + $consumed );
		return array(
			'rows'   => $out,
			'cursor' => $next,
			'done'   => count( $rows ) < $limit && $consumed === count( $rows ),
		);
	}

	/** Folders inside uploads that are caches or backups, not content. */
	private static function skipped_dirs() {
		return apply_filters( 'livepull_full_copy_skip_dirs', array( 'et-cache', 'cache', 'elementor/css', 'livepull-backups', 'backup', 'backups', 'ai1wm-backups', 'updraft', 'wp-staging', 'bb-plugin/cache', 'wc-logs', 'wpforms/cache', 'litespeed', 'sucuri', 'wflogs' ) );
	}

	public static function rest_files( WP_REST_Request $r ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		$offset  = max( 0, (int) $r['offset'] );
		$limit   = max( 1, min( 5000, (int) $r['limit'] ) );
		if ( ! is_dir( $base ) ) {
			return array( 'files' => array(), 'next' => $offset, 'done' => true, 'baseurl' => $uploads['baseurl'] );
		}
		// The listing is written to a temporary file on the first page and read page by page
		// after that, so even a huge media library never has to fit in memory.
		// Each copy has its own listing (named by the token it sends), so two copies at once
		// never share one, and a listing is never rebuilt part-way (that could change its order).
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $r['token'] );
		$index = trailingslashit( get_temp_dir() ) . 'livepull-files-' . md5( $base . '|' . LivePull_Config::secret() . '|' . $token ) . '.txt';
		if ( 0 === $offset ) {
			foreach ( (array) glob( trailingslashit( get_temp_dir() ) . 'livepull-files-*' ) as $old ) {
				if ( filemtime( $old ) < time() - DAY_IN_SECONDS ) {
					wp_delete_file( $old ); // Listings from copies that never finished.
				}
			}
			$total = self::write_file_index( $base, $index );
			if ( is_wp_error( $total ) ) {
				return $total;
			}
		} elseif ( ! is_readable( $index ) ) {
			return new WP_Error( 'livepull_listing_gone', 'The list of uploads on live has expired. Start the listing again.', array( 'status' => 410 ) );
		}
		// Plain line-by-line reading (SplFileObject::seek() returns the wrong line before PHP 8.0.1).
		$fh    = fopen( $index, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$total = (int) trim( (string) fgets( $fh ) ); // First line: how many files.
		for ( $i = 0; $i < $offset && false !== fgets( $fh ); $i++ ) {
			// Skip to this page.
		}
		$files = array();
		while ( count( $files ) < $limit ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$rel = rtrim( $line, "\n" );
			if ( '' === $rel ) {
				continue;
			}
			$files[] = array(
				'path' => $rel,
				'size' => (int) @filesize( $base . '/' . $rel ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deleted since listing: size 0.
				'url'  => $uploads['baseurl'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ),
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$next = $offset + count( $files );
		$done = $next >= $total || ! $files; // An empty page always ends the listing.
		if ( $done ) {
			wp_delete_file( $index );
		}
		return array( 'files' => $files, 'next' => $next, 'done' => $done, 'total' => $total );
	}

	/** Write every uploads file path (one per line, count first) to $index. @return int|WP_Error */
	private static function write_file_index( $base, $index ) {
		$skip = self::skipped_dirs();
		$tmp  = $index . '.part';
		$out  = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return new WP_Error( 'livepull_files', 'Could not write a temporary file listing on live.', array( 'status' => 500 ) );
		}
		$count = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) ), '/' );
			foreach ( $skip as $d ) {
				if ( 0 === strpos( $rel, $d . '/' ) ) {
					continue 2;
				}
			}
			if ( preg_match( '/\.(log|php\d?|phtml|phar|sql)(\.gz)?$/i', $rel ) || false !== strpos( $rel, "\n" ) ) {
				continue; // Never list code, logs or database dumps.
			}
			fwrite( $out, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$count++;
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// Count goes first: write it, then the list.
		$final = fopen( $index, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$in    = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $final, $count . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		stream_copy_to_stream( $in, $final );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		wp_delete_file( $tmp );
		return $count;
	}

	/* ============================================================= Local side */

	const STATE = 'livepull_full_state';

	private static function client() {
		return LivePull_Puller::client();
	}

	/** @return array|WP_Error state */
	public static function start( array $opts ) {
		global $wpdb;
		$opts    = wp_parse_args( $opts, array( 'files' => true, 'backup' => true ) );
		$notices = array();
		$remote  = LivePull_Puller::connect( $notices, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		if ( empty( $remote['full_copy'] ) ) {
			return new WP_Error( 'livepull_full_off', 'Copy everything is switched off on the live site (it shares the whole database, so it\'s off by default). On live, open Tools → LivePull → "Let other sites pull from this one" and click "Allow for 1 hour", then try again here.' );
		}
		$list = self::client()->get( 'db-tables' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		if ( ! self::resume_placing( 0 ) ) {
			return new WP_Error( 'livepull_placing', 'Files from the last copy are still being moved into place. Try again in a moment.' );
		}
		$lock = LivePull_Puller::lock_acquire( 'copy' );
		if ( '' === $lock ) {
			return new WP_Error( 'livepull_locked', 'A pull is running. Wait for it to finish, or cancel it.' );
		}
		self::clear_cancel();
		self::drop_temp_tables();
		self::remove_stages();

		// Settings that belong to THIS site and must survive the copy.
		$keep = array();
		foreach ( array( 'siteurl', 'home', 'blog_public', 'livepull_remote', 'livepull_remote_secret', 'livepull_secret', 'livepull_remote_url_style', 'livepull_history', 'livepull_schedule', 'livepull_groups', 'livepull_role' ) as $o ) {
			$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o ) );
			if ( null !== $v ) {
				$keep[ $o ] = $v;
			}
		}

		$tables = array();
		foreach ( $list['tables'] as $t ) {
			$t['done_rows'] = 0;
			$tables[]       = $t;
		}

		return array(
			'lock'     => $lock,
			'remote'   => $remote,
			'rprefix'  => $list['prefix'],
			'tables'   => $tables,
			'ti'       => 0,
			'cursor'   => '',
			'created'  => false,
			'keep'     => $keep,
			'opts'     => $opts,
			'phase'    => $opts['backup'] ? 'backup' : 'tables',
			'files_offset' => 0,
			'files_total'  => 0,
			'files_done'   => 0,
			'files_got'    => 0,
			'queue'        => array(),
			// Downloaded files wait here until the database has switched over, so cancelling
			// or a failure leaves this site's uploads untouched too.
			'stage'        => self::stage_dir(),
			'staged'       => 0,
			'rows_total'   => array_sum( wp_list_pluck( $tables, 'rows' ) ),
			'rows_done'    => 0,
			'errors'       => array(),
			'backup_file'  => '',
			'activity'     => 'Starting…',
			'done'         => false,
			'notices'      => $notices,
			'pairs'        => self::replacement_pairs( $remote ),
		);
	}

	/** Live URLs and server paths → this site's. Longest first. */
	private static function replacement_pairs( array $remote ) {
		$pairs = array();
		foreach ( array( 'home' => home_url(), 'siteurl' => site_url() ) as $k => $local ) {
			if ( empty( $remote[ $k ] ) ) {
				continue;
			}
			$host  = preg_replace( '#^https?://#', '', untrailingslashit( $remote[ $k ] ) );
			$local = untrailingslashit( $local );
			foreach ( array( 'https://', 'http://', '//' ) as $scheme ) {
				$pairs[ $scheme . $host ] = '//' === $scheme ? preg_replace( '#^https?:#', '', $local ) : $local;
			}
		}
		if ( ! empty( $remote['abspath'] ) && untrailingslashit( $remote['abspath'] ) !== untrailingslashit( ABSPATH ) ) {
			$pairs[ untrailingslashit( $remote['abspath'] ) ] = untrailingslashit( ABSPATH );
		}
		$out = array();
		foreach ( $pairs as $f => $t ) {
			if ( $f !== $t ) {
				$out[ $f ] = $t;
				$out[ str_replace( '/', '\/', $f ) ] = str_replace( '/', '\/', $t ); // JSON-escaped.
			}
		}
		uksort( $out, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		return array( array_keys( $out ), array_values( $out ) );
	}

	/** @return array|WP_Error */
	public static function step( array $state, $budget = 8 ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$t0  = microtime( true );
		$out = function () use ( $t0, $budget ) {
			return $budget > 0 && ( microtime( true ) - $t0 ) >= $budget;
		};
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );

		while ( ! $state['done'] ) {
			// Once the database has switched over there's no going back: whatever state this
			// request was handed (even a stale "swap"), carry on moving the files into place.
			if ( 'place' === $state['phase'] || self::placing() ) {
				$state['phase']    = 'place';
				$state['activity'] = sprintf( 'Moving %d downloaded files into the uploads folder…', $state['staged'] );
				// Moves are renames on the same disk, so allow a little longer than a normal step:
				// after the switch the person may be logged out and the next request may not come.
				// If it does run out of time, any admin page load finishes the job (resume_placing()).
				$place_out = function () use ( $t0, $budget ) {
					return $budget > 0 && ( microtime( true ) - $t0 ) >= max( $budget, 20 );
				};
				if ( self::place_files( $state, $place_out ) ) {
					$state['done']     = true;
					$state['activity'] = 'Finished';
					LivePull_Puller::lock_release( isset( $state['lock'] ) ? $state['lock'] : '' );
					LivePull_History::add( 'copy', array( 'errors' => $state['errors'], 'files' => (int) $state['files_got'] ), array( 'backup' => isset( $state['backup_file'] ) ? $state['backup_file'] : '', 'note' => sprintf( '%d tables copied from live', count( $state['tables'] ) ) ) );
				}
				break;
			}
			if ( self::cancelled() ) {
				self::clear_cancel();
				self::abort();
				return new WP_Error( 'livepull_cancelled', 'Cancelled. Nothing on this site was changed.' );
			}
			if ( 'backup' === $state['phase'] ) {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					$state['activity'] = 'Backing up this site\'s whole database…';
					$file              = LivePull_Backup::create( true );
					if ( is_wp_error( $file ) ) {
						self::abort();
						return new WP_Error( 'livepull_backup', 'Backup failed, so nothing was changed: ' . $file->get_error_message() );
					}
					$state['backup_file'] = $file;
					$state['phase']       = 'tables';
				} else {
					// In the browser: a few thousand rows per request, so big databases can't time out.
					if ( empty( $state['bk'] ) ) {
						$state['bk'] = LivePull_Backup::chunk_start( true );
					}
					$state['activity'] = sprintf( 'Backing up this site\'s database (table %d of %d)…', min( $state['bk']['ti'] + 1, count( $state['bk']['tables'] ) ), count( $state['bk']['tables'] ) );
					$r = LivePull_Backup::chunk_step( $state['bk'], $out );
					if ( is_wp_error( $r ) ) {
						self::abort();
						return new WP_Error( 'livepull_backup', 'Backup failed, so nothing was changed: ' . $r->get_error_message() );
					}
					if ( true === $r ) {
						$state['backup_file'] = $state['bk']['file'];
						unset( $state['bk'] );
						$state['phase'] = 'tables';
					}
				}
			} elseif ( 'tables' === $state['phase'] ) {
				if ( $state['ti'] >= count( $state['tables'] ) ) {
					$state['phase'] = $state['opts']['files'] ? 'files' : 'swap';
					continue;
				}
				$res = self::copy_table_chunk( $state );
				if ( is_wp_error( $res ) ) {
					self::abort();
					return $res;
				}
				$state = $res;
			} elseif ( 'files' === $state['phase'] ) {
				$res = self::files_chunk( $state, $out );
				if ( is_wp_error( $res ) ) {
					self::abort();
					return $res;
				}
				$state = $res;
			} elseif ( 'swap' === $state['phase'] ) {
				$state['activity'] = 'Switching this site over to the copy…';
				$res = self::swap( $state );
				if ( is_wp_error( $res ) ) {
					self::abort();
					return $res;
				}
				update_option( self::PLACING, $state['stage'], false ); // Already there; just in case live has no options table.
				$state['phase'] = 'place';
				continue;
			}
			if ( $out() ) {
				break;
			}
		}
		// Until the switch-over, stop if the lock was taken away (someone pressed Stop/Unlock,
		// or it was abandoned and another run claimed it). After it, the lock went with the old
		// database and only moving files into place is left.
		if ( ! $state['done'] && in_array( $state['phase'], array( 'backup', 'tables', 'files', 'swap' ), true )
			&& ! LivePull_Puller::lock_renew( isset( $state['lock'] ) ? $state['lock'] : '' ) ) {
			self::abort();
			return new WP_Error( 'livepull_locked', 'Copy everything was stopped because another pull took over. Nothing on this site was changed.' );
		}
		return $state;
	}

	/** Ask a running copy to stop (it checks between tables and right before switching over). */
	public static function request_cancel() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'livepull_full_cancel' ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'livepull_full_cancel', 'option_value' => (string) time(), 'autoload' => 'no' ) );
	}

	/** Read straight from the database: the flag is set by a different request. */
	private static function cancelled() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'livepull_full_cancel' ) );
	}

	private static function clear_cancel() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'livepull_full_cancel' ) );
	}

	/** Stop: drop temporary tables and downloaded files. This site is unchanged. */
	public static function abort() {
		self::drop_temp_tables();
		if ( ! self::placing() ) { // Never after the switch: those files belong to the new database.
			self::remove_stages();
		}
		LivePull_Puller::lock_release_source( 'copy' );
	}

	const PLACING = 'livepull_full_placing';

	/** The staging folder still being moved into uploads after a switch-over, or ''. */
	public static function placing() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PLACING ) );
		return $v ? (string) $v : '';
	}

	/**
	 * Finish moving files after a copy whose request ended early (time limit, closed tab, logged
	 * out by the switch). Runs on admin page loads and before any new copy. @return bool done.
	 */
	public static function resume_placing( $seconds = 20 ) {
		$stage = self::placing();
		if ( ! $stage ) {
			return true;
		}
		$t0    = microtime( true );
		$state = array( 'stage' => $stage, 'errors' => array() );
		$done  = self::place_files( $state, function () use ( $t0, $seconds ) {
			return $seconds > 0 && ( microtime( true ) - $t0 ) >= $seconds;
		} );
		if ( $state['errors'] ) {
			update_option( 'livepull_full_place_errors', $state['errors'], false );
		}
		return $done;
	}

	/** Access-rule files written into each staging folder (never moved into uploads). */
	const STAGE_GUARDS = array( '.htaccess', 'web.config', 'index.php', 'index.html' );

	/**
	 * A new, unguessable staging folder next to uploads (same disk, so moving is instant), with
	 * the same deny rules as the backups folder.
	 */
	private static function stage_dir() {
		$uploads = wp_get_upload_dir();
		$dir     = wp_normalize_path( dirname( $uploads['basedir'] ) ) . '/livepull-stage-' . wp_generate_password( 24, false );
		if ( wp_mkdir_p( $dir ) ) {
			LivePull_Backup::protect( $dir );
		}
		return $dir;
	}

	/** Delete abandoned staging folders (never the one still being moved into place). */
	private static function remove_stages() {
		$uploads = wp_get_upload_dir();
		$keep    = self::placing();
		foreach ( (array) glob( wp_normalize_path( dirname( $uploads['basedir'] ) ) . '/livepull-stage-*', GLOB_ONLYDIR ) as $dir ) {
			if ( wp_normalize_path( $dir ) !== $keep ) {
				LivePull_Config::fs()->delete( $dir, true );
			}
		}
	}

	/** Where a staged copy of an uploads file goes. */
	private static function staged_path( array $state, $dest ) {
		$uploads = wp_get_upload_dir();
		return $state['stage'] . '/' . ltrim( substr( $dest, strlen( wp_normalize_path( $uploads['basedir'] ) ) ), '/' );
	}

	/**
	 * Move staged files into uploads, resumably: each moved file leaves the staging folder, so
	 * a later call carries on where this one stopped.
	 *
	 * @return bool true when finished (false: out of time, call again).
	 */
	private static function place_files( array &$state, callable $out ) {
		$fs    = LivePull_Config::fs();
		$stage = $state['stage'];
		if ( ! is_dir( $stage ) ) {
			delete_option( self::PLACING );
			return true;
		}
		$uploads = wp_normalize_path( wp_get_upload_dir()['basedir'] );
		$failed  = 0;
		$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $out() ) {
				return false;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $stage ) ), '/' );
			if ( in_array( $rel, self::STAGE_GUARDS, true ) ) {
				continue; // The staging folder's own access rules, not uploads.
			}
			$dest = LivePull_Importer::safe_dest( $rel );
			if ( ! $dest || 0 !== strpos( $dest, $uploads . '/' ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			if ( ! is_dir( dirname( $dest ) ) || ! $fs->move( $file->getPathname(), $dest, true ) ) {
				$failed++;
				if ( count( $state['errors'] ) < 50 ) {
					$state['errors'][] = 'Could not move ' . $rel . ' into the uploads folder.';
				}
			}
		}
		if ( $failed ) {
			// Keep what couldn't be moved, under a name the clean-up never deletes, and say where.
			$kept = str_replace( '/livepull-stage-', '/livepull-unplaced-', $stage );
			$fs->move( $stage, $kept, false );
			$state['errors'][] = sprintf( '%d file(s) could not be moved into the uploads folder. They are kept in %s: copy them into uploads by hand.', $failed, $kept );
		} else {
			$fs->delete( $stage, true );
		}
		delete_option( self::PLACING );
		return true;
	}

	/**
	 * Live's CREATE TABLE statement is run here (only its name swapped), so check it is just a
	 * table definition: one statement, our temporary name, an ordinary storage engine, and no
	 * options that write files elsewhere, connect to other servers or copy other tables.
	 *
	 * @return string Reason it was refused, or '' if it's fine.
	 */
	private static function unsafe_create( $sql, $tmp ) {
		if ( 0 !== strpos( $sql, 'CREATE TABLE `' . $tmp . '` (' ) ) {
			return 'not a plain CREATE TABLE';
		}
		// Look only at the SQL itself: blank out quoted text (defaults, comments) and `names`.
		$bare = preg_replace( array( "/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.)*"/s', '/`[^`]*`/' ), array( "''", '""', '``' ), $sql );
		if ( false !== strpos( $bare, ';' ) ) {
			return 'more than one statement';
		}
		if ( preg_match( '/\b(DATA|INDEX)\s+DIRECTORY\b|\bCONNECTION\s*=|\bUNION\s*=|\bSELECT\b/i', $bare ) ) {
			return 'options that reach outside the table';
		}
		if ( preg_match_all( '/\bENGINE\s*=\s*([A-Za-z]+)/i', $bare, $m ) ) {
			foreach ( $m[1] as $engine ) {
				if ( ! in_array( strtolower( $engine ), array( 'innodb', 'myisam', 'aria', 'memory', 'heap' ), true ) ) {
					return 'storage engine ' . $engine;
				}
			}
		}
		return '';
	}

	private static function tmp_name( $name ) {
		global $wpdb;
		return $wpdb->prefix . self::TMP . $name;
	}

	private static function drop_temp_tables() {
		global $wpdb;
		foreach ( array( self::TMP, self::OLD ) as $mark ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $mark ) . '%' ) ) as $t ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $t ) );
			}
		}
	}

	private static function copy_table_chunk( array $state ) {
		global $wpdb;
		$t   = $state['tables'][ $state['ti'] ];
		$tmp = self::tmp_name( $t['name'] );
		if ( strlen( $tmp ) > 64 ) {
			$state['errors'][] = 'Skipped table ' . $t['name'] . ' (name too long for a temporary copy).';
			$state['ti']++;
			$state['cursor']  = '';
			$state['created'] = false;
			return $state;
		}

		if ( ! $state['created'] ) {
			$sql = preg_replace( '/^CREATE TABLE `[^`]+`/i', 'CREATE TABLE `' . $tmp . '`', $t['create'], 1 );
			$sql = preg_replace( '/\sAUTO_INCREMENT=\d+/i', '', $sql );
			$why = self::unsafe_create( $sql, $tmp );
			if ( $why ) {
				return new WP_Error( 'livepull_create', sprintf( 'Live sent an unexpected definition for table %s (%s), so nothing was changed.', $t['name'], $why ) );
			}
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tmp ) );
			// $sql is live's own CREATE TABLE statement (from SHOW CREATE TABLE) with the name swapped for our temporary table.
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				// e.g. MySQL 8 collations on MariaDB: fall back to a universally available one.
				$sql2 = preg_replace( '/utf8mb4_0900_[a-z_]+/i', 'utf8mb4_unicode_ci', $sql );
				$sql2 = preg_replace( '/utf8mb4_uca1400_[a-z_]+/i', 'utf8mb4_unicode_ci', $sql2 );
				if ( false === $wpdb->query( $sql2 ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					return new WP_Error( 'livepull_create', 'Could not create a copy of table ' . $t['name'] . ': ' . $wpdb->last_error );
				}
			}
			$state['created'] = true;
		}

		$pk     = 1 === count( $t['pk'] ) ? $t['pk'][0] : '';
		$state['activity'] = sprintf( 'Copying table %s (%d of %d)', $t['name'], $state['ti'] + 1, count( $state['tables'] ) );
		$res = self::client()->get( 'db-rows', array( 'table' => $t['name'], 'pk' => $pk, 'cursor' => $state['cursor'], 'limit' => 1000 ) );
		if ( is_wp_error( $res ) && $pk ) {
			// Non-numeric key that can't be paged by key: retry by offset.
			$t['pk'] = array();
			$state['tables'][ $state['ti'] ] = $t;
			$res = self::client()->get( 'db-rows', array( 'table' => $t['name'], 'pk' => '', 'cursor' => '0', 'limit' => 1000 ) );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'livepull_rows', 'Copying ' . $t['name'] . ' failed: ' . $res->get_error_message() );
		}

		if ( $res['rows'] ) {
			$err = self::insert_rows( $tmp, $t['name'], $res['rows'], $state );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
			$state['rows_done']                         += count( $res['rows'] );
			$state['tables'][ $state['ti'] ]['done_rows'] += count( $res['rows'] );
		}
		$state['cursor'] = (string) $res['cursor'];
		if ( ! empty( $res['done'] ) ) {
			$state['ti']++;
			$state['cursor']  = '';
			$state['created'] = false;
		}
		return $state;
	}

	private static function insert_rows( $tmp, $name, array $rows, array $state ) {
		global $wpdb;
		list( $from, $to ) = $state['pairs'];
		$lp  = $wpdb->prefix;
		$rp  = $state['rprefix'];
		$fix_prefix = ( $lp !== $rp );
		$esc = function ( $v ) use ( $wpdb ) {
			if ( null === $v ) {
				return 'NULL';
			}
			return "'" . ( $wpdb->dbh instanceof mysqli ? mysqli_real_escape_string( $wpdb->dbh, $v ) : addslashes( $v ) ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- multi-row INSERT of copied rows; every value is escaped here.
		};
		$cols   = array_keys( $rows[0] );
		$head   = 'INSERT INTO `' . $tmp . '` (`' . implode( '`,`', $cols ) . '`) VALUES ';
		$values = array();
		$size   = 0;
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $col => $v ) {
				if ( is_array( $v ) && isset( $v['b64'] ) ) {
					$v = base64_decode( $v['b64'] ); // phpcs:ignore
				} elseif ( is_string( $v ) ) {
					$v = LivePull_Serial::replace( $v, $from, $to );
				}
				// Table prefix lives inside some keys: wp_user_roles, wp_capabilities, wp_user_level…
				if ( $fix_prefix && is_string( $v ) && 0 === strpos( $v, $rp ) &&
					( ( 'options' === $name && 'option_name' === $col ) || ( 'usermeta' === $name && 'meta_key' === $col ) ) ) {
					$v = $lp . substr( $v, strlen( $rp ) );
				}
				$vals[] = $esc( $v );
			}
			$line     = '(' . implode( ',', $vals ) . ')';
			$values[] = $line;
			$size    += strlen( $line );
			if ( $size > 900000 ) {
				if ( false === $wpdb->query( $head . implode( ',', $values ) ) ) { // phpcs:ignore
					return new WP_Error( 'livepull_insert', 'Writing ' . $name . ' failed: ' . $wpdb->last_error );
				}
				$values = array();
				$size   = 0;
			}
		}
		if ( $values && false === $wpdb->query( $head . implode( ',', $values ) ) ) { // phpcs:ignore
			return new WP_Error( 'livepull_insert', 'Writing ' . $name . ' failed: ' . $wpdb->last_error );
		}
		return true;
	}

	private static function files_chunk( array $state, callable $out ) {
		$client = self::client();
		while ( ! $out() ) {
			if ( $state['queue'] ) {
				$f    = array_shift( $state['queue'] );
				$dest = LivePull_Importer::safe_dest( $f['path'] );
				$state['files_done']++;
				$state['activity'] = sprintf( 'Downloading uploads: %d of %d files checked', $state['files_done'], $state['files_total'] );
				if ( ! $dest ) {
					continue;
				}
				if ( file_exists( $dest ) && filesize( $dest ) === (int) $f['size'] ) {
					continue; // Already here.
				}
				$staged = self::staged_path( $state, $dest );
				if ( file_exists( $staged ) && filesize( $staged ) === (int) $f['size'] ) {
					continue; // Already downloaded by this copy.
				}
				$res = $client->download( $f['url'], $staged );
				if ( is_wp_error( $res ) ) {
					if ( count( $state['errors'] ) < 50 ) {
						$state['errors'][] = 'Could not download ' . $f['path'] . ': ' . $res->get_error_message();
					}
				} else {
					$state['files_got']++;
					$state['staged']++;
				}
				continue;
			}
			if ( $state['files_offset'] < 0 ) {
				$state['phase'] = 'swap';
				return $state;
			}
			$state['activity'] = 'Listing live\'s uploads folder…';
			if ( empty( $state['files_token'] ) ) {
				$state['files_token'] = wp_generate_password( 20, false );
			}
			$res = $client->get( 'files', array( 'offset' => $state['files_offset'], 'limit' => 2000, 'token' => $state['files_token'] ) );
			// The client reports live's "list expired" answer as HTTP 410.
			$gone = is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'livepull_listing_gone', 'livepull_http_410' ), true );
			if ( $gone && empty( $state['files_relisted'] ) ) {
				// Live lost its list part-way: list again from the start (once). Files already
				// downloaded are skipped, so nothing is copied twice.
				$state['files_relisted'] = true;
				$state['files_token']    = wp_generate_password( 20, false );
				$state['files_offset']   = 0;
				$state['files_done']     = 0;
				continue;
			}
			if ( is_wp_error( $res ) ) {
				$state['errors'][]   = 'Could not list live\'s uploads: ' . $res->get_error_message() . ' (database copied; media skipped)';
				$state['files_offset'] = -1;
				continue;
			}
			$state['queue']        = $res['files'];
			$state['files_total']  = isset( $res['total'] ) ? (int) $res['total'] : $state['files_total'];
			// Stop on "done", an empty page, or a page that doesn't move forward: never ask twice.
			$stuck                 = empty( $res['files'] ) || (int) $res['next'] <= (int) $state['files_offset'];
			$state['files_offset'] = ( ! empty( $res['done'] ) || $stuck ) ? -1 : (int) $res['next'];
		}
		return $state;
	}

	private static function swap( array &$state ) {
		global $wpdb;
		$renames = array();
		$drops   = array();
		foreach ( $state['tables'] as $t ) {
			$tmp  = self::tmp_name( $t['name'] );
			$live = $wpdb->prefix . $t['name'];
			$old  = $wpdb->prefix . self::OLD . $t['name'];
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tmp ) ) ) !== $tmp ) {
				continue;
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) ) ) === $live ) {
				$renames[] = "`{$live}` TO `{$old}`";
				$drops[]   = $old;
			}
			$renames[] = "`{$tmp}` TO `{$live}`";
		}
		if ( ! $renames ) {
			return new WP_Error( 'livepull_swap', 'Nothing was copied.' );
		}
		if ( self::cancelled() ) {
			return new WP_Error( 'livepull_cancelled', 'Cancelled. Nothing on this site was changed.' );
		}
		// Note "files still to move" inside the copied options table, so it is part of the
		// database the instant it switches in: a request that dies right after the switch can
		// always be resumed, and nothing will mistake the staged files for leftovers.
		$tmp_options = self::tmp_name( 'options' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tmp_options ) ) ) === $tmp_options ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $tmp_options, self::PLACING ) );
			$wpdb->insert( $tmp_options, array( 'option_name' => self::PLACING, 'option_value' => $state['stage'], 'autoload' => 'no' ) );
		}
		// One statement, so every table switches at the same instant.
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names built above from this site's own table list, each backtick-quoted.
			return new WP_Error( 'livepull_swap', 'Switching tables failed: ' . $wpdb->last_error . ' This site was left as it was.' );
		}
		foreach ( $drops as $d ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $d ) );
		}

		// Put back what belongs to this site.
		foreach ( $state['keep'] as $name => $value ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => in_array( $name, array( 'siteurl', 'home', 'blog_public' ), true ) ? 'yes' : 'no' ) );
		}
		if ( ! isset( $state['keep']['livepull_secret'] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'livepull_secret' ) ); // Don't keep live's key here.
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		// Keep LivePull switched on if it's a normal plugin here.
		if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( LIVEPULL_FILE ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
			$active = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) );
			$active = is_array( $active ) ? $active : array();
			$base   = plugin_basename( LIVEPULL_FILE );
			if ( ! in_array( $base, $active, true ) ) {
				$active[] = $base;
				$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( array_values( $active ) ) ), array( 'option_name' => 'active_plugins' ) );
			}
		}

		wp_cache_flush();

		// Live may run a different WordPress version: bring the copied database in line with
		// this site's WordPress (exactly what "Update WordPress Database" would do).
		global $wp_db_version;
		$copied_db = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'db_version'" );
		if ( $copied_db && (int) $wp_db_version !== $copied_db ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			wp_upgrade();
			wp_cache_flush();
		}
		if ( ! empty( $state['remote']['wp_version'] ) && version_compare( $state['remote']['wp_version'], get_bloginfo( 'version' ), '>' ) ) {
			$state['errors'][] = sprintf( 'Live runs WordPress %s but this site runs %s. Update WordPress here (Dashboard → Updates) so they match.', $state['remote']['wp_version'], get_bloginfo( 'version' ) );
		}

		// This database is now a fresh copy of live: pulls continue from this exact point.
		LivePull_Config::mark_as_clone();
		delete_option( 'livepull_clone_detected' );
		update_option( 'livepull_fingerprint', LivePull_Config::fingerprint(), true );
		update_option( 'livepull_remote_home', $state['remote']['home'], false );
		update_option( 'livepull_last_pull', gmdate( 'Y-m-d H:i:s' ), false );
		update_option( 'livepull_full_synced', gmdate( 'Y-m-d H:i:s' ), false );
		update_option( 'livepull_last_full_copy', gmdate( 'Y-m-d H:i:s' ), false );
		LivePull_Puller::lock_release_source( 'copy' ); // Live's options table may have brought one along.
		wp_cache_flush();
		return true;
	}
}

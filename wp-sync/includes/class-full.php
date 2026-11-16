<?php
defined( 'ABSPATH' ) || exit;

/**
 * Search-replace inside values that may be PHP-serialized (any depth, including serialized
 * strings inside serialized data), recalculating string lengths so nothing gets corrupted.
 * Never unserializes, so no plugin classes are needed and nothing is executed.
 */
final class WPSync_Serial {

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
		return str_replace( $from, $to, $value );
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
 * can be switched off on live's WP Sync screen).
 *
 * Local side: a resumable, time-budgeted job.
 *   1. Back up this whole database.
 *   2. Load every live table into temporary tables (URLs, paths and table prefix rewritten).
 *   3. Download missing/changed files from live's uploads folder.
 *   4. Swap the temporary tables in at once, then restore this site's URL, its WP Sync
 *      connection and "discourage search engines", and mark the database as a fresh copy.
 * Nothing on this site changes until step 4, so stopping part-way is harmless.
 */
final class WPSync_Full {

	const TMP = 'wpsynctmp_';
	const OLD = 'wpsyncold_';

	/* ============================================================== Live side */

	public static function allowed() {
		if ( defined( 'WPSYNC_ALLOW_FULL_COPY' ) ) {
			return (bool) WPSYNC_ALLOW_FULL_COPY;
		}
		return 'no' !== get_option( 'wpsync_allow_full_copy', 'yes' );
	}

	/** Table names (without prefix) that are never worth copying (logs, sessions, caches). */
	private static function skipped_tables() {
		return apply_filters( 'wpsync_full_copy_skip_tables', array(
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
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$full}`", ARRAY_N );
			if ( ! $create || false === stripos( $create[1], 'CREATE TABLE' ) ) {
				continue; // Views etc.
			}
			$keys = $wpdb->get_results( "SHOW KEYS FROM `{$full}` WHERE Key_name = 'PRIMARY'" );
			$pk   = array();
			foreach ( $keys as $k ) {
				$pk[ (int) $k->Seq_in_index ] = $k->Column_name;
			}
			ksort( $pk );
			$out[] = array(
				'name'   => $name,
				'create' => $create[1],
				'rows'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" ),
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
			return new WP_Error( 'wpsync_no_table', 'Unknown table.', array( 'status' => 404 ) );
		}
		$pk     = (string) $r['pk'];
		$cursor = (string) $r['cursor'];
		$keyset = '' !== $pk && preg_match( '/^[A-Za-z0-9_]+$/', $pk );
		if ( $keyset ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$full}` WHERE `{$pk}` > %s ORDER BY `{$pk}` ASC LIMIT %d", '' === $cursor ? '-1' : $cursor, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$full}` LIMIT %d, %d", (int) $cursor, $limit ), ARRAY_A );
		}
		$out   = array();
		$bytes = 0;
		$last  = null;
		foreach ( $rows as $row ) {
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
		$next = $keyset ? ( $last ? (string) $last[ $pk ] : $cursor ) : (string) ( (int) $cursor + count( $out ) );
		return array(
			'rows'   => $out,
			'cursor' => $next,
			'done'   => count( $out ) < $limit && count( $out ) === count( $rows ),
		);
	}

	/** Folders inside uploads that are caches or backups, not content. */
	private static function skipped_dirs() {
		return apply_filters( 'wpsync_full_copy_skip_dirs', array( 'et-cache', 'cache', 'elementor/css', 'wpsync-backups', 'backup', 'backups', 'ai1wm-backups', 'updraft', 'wp-staging', 'bb-plugin/cache', 'wc-logs', 'wpforms/cache', 'litespeed', 'sucuri', 'wflogs' ) );
	}

	public static function rest_files( WP_REST_Request $r ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		$offset  = max( 0, (int) $r['offset'] );
		$limit   = max( 1, min( 5000, (int) $r['limit'] ) );
		if ( ! is_dir( $base ) ) {
			return array( 'files' => array(), 'next' => $offset, 'done' => true, 'baseurl' => $uploads['baseurl'] );
		}
		$skip  = self::skipped_dirs();
		$list  = array();
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
			if ( preg_match( '/\.(log|php\d?|phtml|phar|sql)(\.gz)?$/i', $rel ) ) {
				continue; // Never list code, logs or database dumps.
			}
			$list[] = $rel;
		}
		sort( $list );
		$slice = array_slice( $list, $offset, $limit );
		$files = array();
		foreach ( $slice as $rel ) {
			$files[] = array(
				'path' => $rel,
				'size' => (int) filesize( $base . '/' . $rel ),
				'url'  => $uploads['baseurl'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ),
			);
		}
		return array( 'files' => $files, 'next' => $offset + count( $slice ), 'done' => $offset + count( $slice ) >= count( $list ), 'total' => count( $list ) );
	}

	/* ============================================================= Local side */

	const STATE = 'wpsync_full_state';

	private static function client() {
		return WPSync_Puller::client();
	}

	/** @return array|WP_Error state */
	public static function start( array $opts ) {
		global $wpdb;
		$opts    = wp_parse_args( $opts, array( 'files' => true, 'backup' => true ) );
		$notices = array();
		$remote  = WPSync_Puller::connect( $notices, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		if ( empty( $remote['full_copy'] ) ) {
			return new WP_Error( 'wpsync_full_off', 'The live site has full copies switched off (Tools → WP Sync on live → "Allow full copies"), or its WP Sync is older than 0.4.0 – update it first.' );
		}
		if ( get_transient( WPSync_Puller::LOCK ) ) {
			return new WP_Error( 'wpsync_locked', 'A pull is running. Wait for it to finish, or cancel it.' );
		}
		$list = self::client()->get( 'db-tables' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		set_transient( WPSync_Puller::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		self::clear_cancel();
		self::drop_temp_tables();

		// Settings that belong to THIS site and must survive the copy.
		$keep = array();
		foreach ( array( 'siteurl', 'home', 'blog_public', 'wpsync_remote', 'wpsync_remote_secret', 'wpsync_secret', 'wpsync_remote_url_style' ) as $o ) {
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
			if ( self::cancelled() ) {
				self::clear_cancel();
				self::abort();
				return new WP_Error( 'wpsync_cancelled', 'Cancelled. Nothing on this site was changed.' );
			}
			if ( 'backup' === $state['phase'] ) {
				$state['activity'] = 'Backing up this site\'s whole database…';
				$file              = WPSync_Backup::create( true );
				if ( is_wp_error( $file ) ) {
					self::abort();
					return new WP_Error( 'wpsync_backup', 'Backup failed, so nothing was changed: ' . $file->get_error_message() );
				}
				$state['backup_file'] = $file;
				$state['phase']       = 'tables';
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
				$state['done']     = true;
				$state['activity'] = 'Finished';
				break;
			}
			if ( $out() ) {
				break;
			}
		}
		if ( ! $state['done'] ) {
			set_transient( WPSync_Puller::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}
		return $state;
	}

	/** Ask a running copy to stop (it checks between tables and right before switching over). */
	public static function request_cancel() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'wpsync_full_cancel' ) );
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpsync_full_cancel', 'option_value' => (string) time(), 'autoload' => 'no' ) );
	}

	/** Read straight from the database: the flag is set by a different request. */
	private static function cancelled() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpsync_full_cancel' ) );
	}

	private static function clear_cancel() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'wpsync_full_cancel' ) );
	}

	/** Stop: drop temporary tables. This site is unchanged. */
	public static function abort() {
		self::drop_temp_tables();
		delete_transient( WPSync_Puller::LOCK );
	}

	private static function tmp_name( $name ) {
		global $wpdb;
		return $wpdb->prefix . self::TMP . $name;
	}

	private static function drop_temp_tables() {
		global $wpdb;
		foreach ( array( self::TMP, self::OLD ) as $mark ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $mark ) . '%' ) ) as $t ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
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
			$wpdb->query( "DROP TABLE IF EXISTS `{$tmp}`" );
			if ( false === $wpdb->query( $sql ) ) {
				// e.g. MySQL 8 collations on MariaDB: fall back to a universally available one.
				$sql2 = preg_replace( '/utf8mb4_0900_[a-z_]+/i', 'utf8mb4_unicode_ci', $sql );
				$sql2 = preg_replace( '/utf8mb4_uca1400_[a-z_]+/i', 'utf8mb4_unicode_ci', $sql2 );
				if ( false === $wpdb->query( $sql2 ) ) {
					return new WP_Error( 'wpsync_create', 'Could not create a copy of table ' . $t['name'] . ': ' . $wpdb->last_error );
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
			return new WP_Error( 'wpsync_rows', 'Copying ' . $t['name'] . ' failed: ' . $res->get_error_message() );
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
			return "'" . ( $wpdb->dbh instanceof mysqli ? mysqli_real_escape_string( $wpdb->dbh, $v ) : addslashes( $v ) ) . "'";
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
					$v = WPSync_Serial::replace( $v, $from, $to );
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
					return new WP_Error( 'wpsync_insert', 'Writing ' . $name . ' failed: ' . $wpdb->last_error );
				}
				$values = array();
				$size   = 0;
			}
		}
		if ( $values && false === $wpdb->query( $head . implode( ',', $values ) ) ) { // phpcs:ignore
			return new WP_Error( 'wpsync_insert', 'Writing ' . $name . ' failed: ' . $wpdb->last_error );
		}
		return true;
	}

	private static function files_chunk( array $state, callable $out ) {
		$client = self::client();
		while ( ! $out() ) {
			if ( $state['queue'] ) {
				$f    = array_shift( $state['queue'] );
				$dest = WPSync_Importer::safe_dest( $f['path'] );
				$state['files_done']++;
				$state['activity'] = sprintf( 'Downloading uploads: %d of %d files checked', $state['files_done'], $state['files_total'] );
				if ( ! $dest ) {
					continue;
				}
				if ( file_exists( $dest ) && filesize( $dest ) === (int) $f['size'] ) {
					continue; // Already here.
				}
				$res = $client->download( $f['url'], $dest );
				if ( is_wp_error( $res ) ) {
					if ( count( $state['errors'] ) < 50 ) {
						$state['errors'][] = 'Could not download ' . $f['path'] . ': ' . $res->get_error_message();
					}
				} else {
					$state['files_got']++;
				}
				continue;
			}
			if ( $state['files_offset'] < 0 ) {
				$state['phase'] = 'swap';
				return $state;
			}
			$state['activity'] = 'Listing live\'s uploads folder…';
			$res = $client->get( 'files', array( 'offset' => $state['files_offset'], 'limit' => 2000 ) );
			if ( is_wp_error( $res ) ) {
				$state['errors'][]   = 'Could not list live\'s uploads: ' . $res->get_error_message() . ' (database copied; media skipped)';
				$state['files_offset'] = -1;
				continue;
			}
			$state['queue']        = $res['files'];
			$state['files_total']  = isset( $res['total'] ) ? (int) $res['total'] : $state['files_total'];
			$state['files_offset'] = ! empty( $res['done'] ) ? -1 : (int) $res['next'];
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
			return new WP_Error( 'wpsync_swap', 'Nothing was copied.' );
		}
		if ( self::cancelled() ) {
			return new WP_Error( 'wpsync_cancelled', 'Cancelled. Nothing on this site was changed.' );
		}
		// One statement, so every table switches at the same instant.
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			return new WP_Error( 'wpsync_swap', 'Switching tables failed: ' . $wpdb->last_error . ' This site was left as it was.' );
		}
		foreach ( $drops as $d ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$d}`" );
		}

		// Put back what belongs to this site.
		foreach ( $state['keep'] as $name => $value ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => in_array( $name, array( 'siteurl', 'home', 'blog_public' ), true ) ? 'yes' : 'no' ) );
		}
		if ( ! isset( $state['keep']['wpsync_secret'] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'wpsync_secret' ) ); // Don't keep live's key here.
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		// Keep WP Sync switched on if it's a normal plugin here.
		if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( wp_normalize_path( WPSYNC_FILE ), wp_normalize_path( WP_PLUGIN_DIR ) ) ) {
			$active = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) );
			$active = is_array( $active ) ? $active : array();
			$base   = plugin_basename( WPSYNC_FILE );
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
		WPSync_Config::mark_as_clone();
		delete_option( 'wpsync_clone_detected' );
		update_option( 'wpsync_fingerprint', WPSync_Config::fingerprint(), true );
		update_option( 'wpsync_remote_home', $state['remote']['home'], false );
		update_option( 'wpsync_last_pull', gmdate( 'Y-m-d H:i:s' ), false );
		update_option( 'wpsync_full_synced', gmdate( 'Y-m-d H:i:s' ), false );
		update_option( 'wpsync_last_full_copy', gmdate( 'Y-m-d H:i:s' ), false );
		delete_transient( WPSync_Puller::LOCK );
		wp_cache_flush();
		return true;
	}
}

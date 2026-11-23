<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Database backup before a pull.
 *
 * Each backup holds the tables a pull can change (or every table, before Copy everything) as
 * data: each table's structure (see LivePull_Schema) followed by its rows, one JSON line at a
 * time. Restoring rebuilds the tables from that data with prepared queries only.
 */
final class LivePull_Backup {

	const KEEP      = 10;
	const KEEP_COPY = 3;

	/** Format marker on the first data line. */
	const FORMAT = 'livepull-backup-1';

	/**
	 * Where backups live: a protected LivePull folder in uploads (deny rules for Apache,
	 * LiteSpeed and IIS, an index file, and an unguessable folder and file name for servers
	 * that ignore deny rules). Backups are data files (gzip-compressed JSON lines), never
	 * code. Can be moved with define( 'LIVEPULL_BACKUP_DIR', '/path' ).
	 */
	public static function dir() {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		if ( defined( 'LIVEPULL_BACKUP_DIR' ) && LIVEPULL_BACKUP_DIR ) {
			$custom = untrailingslashit( LIVEPULL_BACKUP_DIR );
			if ( ( is_dir( $custom ) || wp_mkdir_p( $custom ) ) && wp_is_writable( $custom ) ) {
				self::protect( $custom );
				$dir = $custom;
				return $dir;
			}
		}
		$dir = LivePull_Config::work_dir( 'backups' );
		return $dir;
	}

	/** Are backups in the uploads folder (rather than a folder chosen in wp-config.php)? */
	public static function in_web_root() {
		return ! ( defined( 'LIVEPULL_BACKUP_DIR' ) && LIVEPULL_BACKUP_DIR && wp_normalize_path( self::dir() ) === wp_normalize_path( untrailingslashit( LIVEPULL_BACKUP_DIR ) ) );
	}

	/** Deny web access (Apache, LiteSpeed, IIS); harmless where the folder isn't web-reachable. */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.html' => '',
		);
		// 1.3.0/1.3.1 also wrote an index.php here; LivePull writes no PHP files any more.
		if ( is_file( $dir . '/index.php' ) && "<?php // Silence.\n" === @file_get_contents( $dir . '/index.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $dir . '/index.php' );
		}
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
	}

	private static function token() {
		return bin2hex( random_bytes( 8 ) );
	}

	private static function new_name( $full ) {
		$base = ( $full ? 'pre-copy-' : 'pre-pull-' ) . gmdate( 'Ymd-His' ) . '-' . self::token() . '.lpb';
		return function_exists( 'gzopen' ) ? $base . '.gz' : $base;
	}

	/** Backup file names. ".lpb.php" files are from 1.3.0/1.3.1: still listed and restorable. */
	const NAME_RE = '/^pre-(pull|copy)-(\d{8})-(\d{6})-([a-f0-9]{16})\.lpb(\.gz|\.php)?$/';

	/** @return string|WP_Error Backup file path. */
	public static function create( $full = false, $keep = '' ) {
		$dir = self::dir();

		// Don't fill the disk: need room for about twice the last backup (it's written, then
		// an old one is removed), plus a margin.
		$last = self::all();
		$need = ( $last ? 2 * (int) $last[0]['size'] : 0 ) + 50 * MB_IN_BYTES;
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		if ( false !== $free && $free < $need ) {
			return new WP_Error( 'livepull_disk', sprintf( 'Not enough free disk space for a backup (%1$s free, about %2$s needed), so nothing was changed. Free up space or delete old backups in %3$s.', size_format( $free ), size_format( $need ), $dir ) );
		}

		$st = self::chunk_start( $full );
		do {
			$done = self::chunk_step( $st, '__return_false', false );
			if ( is_wp_error( $done ) ) {
				wp_delete_file( $st['file'] );
				return $done;
			}
		} while ( ! $done );
		self::prune( $keep );
		return $st['file'];
	}

	private static function tables( $full = false ) {
		global $wpdb;
		if ( $full ) {
			$all = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
			return array_values( array_filter( $all, function ( $t ) use ( $wpdb ) {
				$n = substr( $t, strlen( $wpdb->prefix ) );
				return 0 !== strpos( $n, 'lpulltmp_' ) && 0 !== strpos( $n, 'lpullold_' ) && 0 !== strpos( $n, 'lprst_' );
			} ) );
		}
		// Pulls never create or change users, so pull backups leave out users and password hashes.
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->comments, $wpdb->commentmeta, $wpdb->options, LivePull_Config::table() );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$tables[] = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$tables[] = $wpdb->prefix . 'wc_product_meta_lookup';
		}
		return apply_filters( 'livepull_backup_tables', $tables );
	}

	/* ------------------------------------------------ Step by step (large databases) */

	/** Start a backup that can be written a few thousand rows at a time across requests. */
	public static function chunk_start( $full = true ) {
		return array(
			'file'   => self::dir() . '/' . self::new_name( $full ),
			'tables' => self::tables( $full ),
			'full'   => (bool) $full,
			'ti'     => 0,
			'offset' => 0,
			'begun'  => false,
		);
	}

	/**
	 * Write until $out() says time's up. @return bool|WP_Error true when finished.
	 * Each call appends to the file (gzip files may hold several members; every reader handles that).
	 */
	public static function chunk_step( array &$st, callable $out, $prune = true ) {
		global $wpdb;
		$gz = '.gz' === substr( $st['file'], -3 );
		$fh = $gz ? gzopen( $st['file'], 'ab6' ) : fopen( $st['file'], 'ab' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'livepull_backup', 'Could not write backup file in ' . dirname( $st['file'] ) );
		}
		$write = function ( $data ) use ( $fh, $gz ) {
			$line = wp_json_encode( $data ) . "\n";
			$gz ? gzwrite( $fh, $line ) : fwrite( $fh, $line ); // phpcs:ignore
		};
		if ( ! $st['begun'] ) {
			$write( array( 'format' => self::FORMAT, 'time' => time(), 'full' => $st['full'], 'prefix' => $wpdb->prefix ) );
			$st['begun'] = true;
		}
		$done  = false;
		$error = null;
		while ( ! $out() ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				$done = true;
				break;
			}
			$table = $st['tables'][ $st['ti'] ];
			if ( 0 === $st['offset'] ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
					$st['ti']++;
					continue;
				}
				$desc = LivePull_Schema::describe( $table );
				if ( is_wp_error( $desc ) ) {
					$error = $desc;
					break;
				}
				$write( array( 'table' => $table, 'schema' => $desc ) );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, 500', $table, $st['offset'] ), ARRAY_N );
			if ( $rows ) {
				foreach ( $rows as &$row ) {
					$row = array_map( array( 'LivePull_Schema', 'pack' ), $row );
				}
				unset( $row );
				$write( array( 'rows' => $rows ) );
			}
			if ( count( (array) $rows ) < 500 ) {
				$st['ti']++;
				$st['offset'] = 0;
			} else {
				$st['offset'] += 500;
			}
		}
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
		if ( $error ) {
			return $error;
		}
		if ( $done && $prune ) {
			self::prune();
		}
		return $done;
	}

	/**
	 * Keep the newest backups of each kind separately: KEEP before pulls, KEEP_COPY before
	 * full copies. Frequent (e.g. hourly scheduled) pulls then never push out the backup
	 * taken before the last Copy everything.
	 */
	private static function prune( $keep = '' ) {
		foreach ( array( 'pull' => self::KEEP, 'copy' => self::KEEP_COPY ) as $kind => $max ) {
			$all = array_values( array_filter( self::all(), function ( $b ) use ( $keep, $kind ) {
				return $kind === $b['kind'] && ( ! $keep || wp_normalize_path( $b['file'] ) !== wp_normalize_path( $keep ) );
			} ) );
			$protected = $keep && 0 === strpos( basename( $keep ), 'pre-' . $kind . '-' );
			foreach ( array_slice( $all, $protected ? $max - 1 : $max ) as $b ) {
				wp_delete_file( $b['file'] );
			}
		}
	}

	/** Backups, newest first. @return array[] { file, name, time, size } */
	public static function all() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/pre-*' ) as $f ) {
			if ( ! preg_match( self::NAME_RE, basename( $f ), $m ) ) {
				continue;
			}
			$out[] = array(
				'file' => $f,
				'name' => basename( $f ),
				'kind' => $m[1], // pull | copy
				'time' => strtotime( $m[2] . 'T' . $m[3] . 'Z' ),
				'size' => (int) filesize( $f ),
			);
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );
		return $out;
	}

	/** Resolve a backup file name safely (no paths). @return string|null */
	public static function find( $name ) {
		$name = basename( (string) $name );
		if ( ! preg_match( self::NAME_RE, $name ) ) {
			return null;
		}
		$file = self::dir() . '/' . $name;
		return file_exists( $file ) ? $file : null;
	}

	const RESTORE = 'lprst_';

	/**
	 * Put the database back to how it was in a backup (undo a pull).
	 *
	 * All or nothing: the backup is loaded into temporary copies of its tables, and only when
	 * every table has loaded are they switched in, all at the same instant. If anything fails,
	 * the temporary copies are dropped and this site is exactly as it was.
	 * Reads line by line, so large backups don't need much memory.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( $file ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		$gz = '.gz' === substr( $file, -3 );
		$fh = $gz ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'livepull_restore', 'Could not open ' . basename( $file ) );
		}
		$history = LivePull_History::all();
		self::drop_restore_tables();
		$tables = array();
		$cols   = array();
		$tmp    = '';
		$error  = '';
		$first  = true;
		while ( true ) {
			$line = $gz ? gzgets( $fh ) : fgets( $fh );
			if ( false === $line ) {
				break;
			}
			if ( '' === trim( $line ) || 0 === strpos( $line, '<?php' ) ) { // First line of 1.3.0/1.3.1 backups.
				continue;
			}
			$data = json_decode( $line, true );
			if ( $first ) {
				$first = false;
				if ( ! is_array( $data ) || ! isset( $data['format'] ) || self::FORMAT !== $data['format'] ) {
					$error = 'this isn\'t a LivePull backup this version can read';
					break;
				}
				continue;
			}
			if ( ! is_array( $data ) ) {
				$error = 'the backup file is damaged';
				break;
			}
			if ( isset( $data['table'], $data['schema'] ) && is_string( $data['table'] ) && is_array( $data['schema'] ) ) {
				$real = $data['table'];
				if ( 0 !== strpos( $real, $wpdb->prefix ) || 0 === strpos( $real, $wpdb->prefix . self::RESTORE ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $real ) ) {
					$error = 'the backup names a table that doesn\'t belong to this site';
					break;
				}
				$tmp  = self::restore_name( $real );
				$made = LivePull_Schema::create( $tmp, $data['schema'] );
				if ( is_wp_error( $made ) ) {
					$error = 'table ' . $real . ': ' . $made->get_error_message();
					break;
				}
				$tables[ $real ] = $tmp;
				$cols            = wp_list_pluck( $data['schema']['columns'], 'name' );
				continue;
			}
			if ( isset( $data['rows'] ) && is_array( $data['rows'] ) && '' !== $tmp ) {
				$rows = array();
				foreach ( $data['rows'] as $row ) {
					$rows[] = array_map( array( 'LivePull_Schema', 'unpack' ), (array) $row );
				}
				$ok = LivePull_Schema::insert( $tmp, $cols, $rows );
				if ( is_wp_error( $ok ) ) {
					$error = $ok->get_error_message();
					break;
				}
			}
		}
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
		if ( ! $error && ! $tables ) {
			$error = 'the file contains no tables';
		}

		if ( ! $error ) {
			// Switch every restored table in at once; keep the current ones until that worked.
			$renames = array();
			$olds    = array();
			foreach ( $tables as $real => $new ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $real ) ) ) === $real ) {
					$old              = self::restore_name( $real, 'old' );
					$renames[ $real ] = $old;
					$olds[]           = $old;
				}
			}
			foreach ( $tables as $real => $new ) {
				$renames[ $new ] = $real;
			}
			$ok = LivePull_Schema::rename( $renames );
			if ( is_wp_error( $ok ) ) {
				$error = $ok->get_error_message();
			} else {
				foreach ( $olds as $old ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old ) );
				}
			}
		}
		if ( $error ) {
			self::drop_restore_tables();
		}
		wp_cache_flush();
		if ( $error ) {
			return new WP_Error( 'livepull_restore', 'Restore stopped, so nothing was changed: ' . $error );
		}
		// Backups are taken at the start of a pull, so they contain that pull's lock. Nothing
		// restored is running: clear it.
		delete_transient( 'livepull_lock' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", LivePull_Puller::LOCK ) );
		wp_cache_delete( LivePull_Puller::LOCK, 'options' );
		// The history describes this site's past, so it isn't wound back with the database.
		update_option( LivePull_History::OPTION, $history, false );
		LivePull_History::add( 'restore', array(), array( 'note' => basename( $file ) ) );
		return true;
	}

	/** Temporary name for a table being restored (or for the table it replaces). */
	private static function restore_name( $table, $kind = 'new' ) {
		global $wpdb;
		$rest = substr( $table, strlen( $wpdb->prefix ) );
		$name = $wpdb->prefix . self::RESTORE . ( 'old' === $kind ? 'o_' : 'n_' ) . $rest;
		return strlen( $name ) <= 64 ? $name : $wpdb->prefix . self::RESTORE . ( 'old' === $kind ? 'o_' : 'n_' ) . substr( md5( $rest ), 0, 20 );
	}

	private static function drop_restore_tables() {
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . self::RESTORE ) . '%' ) ) as $t ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $t ) );
		}
	}

	public static function latest() {
		$all = self::all();
		return $all ? $all[0]['file'] : null;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Database backup before a pull.
 *
 * In the terminal it uses `wp db export` (full database). From WP admin it writes a
 * gzipped SQL dump of the tables a pull can change, in PHP, so it works on any host.
 * Restore with `wp db import <file>` or phpMyAdmin.
 */
final class LivePull_Backup {

	const KEEP = 10;

	/**
	 * Where backups live. Outside the public web folder when possible (e.g. the folder above
	 * public_html / app/public), so they can never be downloaded even on servers that ignore
	 * .htaccess (nginx). Otherwise wp-content/livepull-backups-<random>: deny rules for
	 * Apache/LiteSpeed/IIS, and on nginx an unguessable folder and file names.
	 * Override with define( 'LIVEPULL_BACKUP_DIR', '/absolute/path' ).
	 */
	public static function dir() {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		$candidates = array();
		if ( defined( 'LIVEPULL_BACKUP_DIR' ) && LIVEPULL_BACKUP_DIR ) {
			$candidates[] = untrailingslashit( LIVEPULL_BACKUP_DIR );
		}
		$above = dirname( untrailingslashit( ABSPATH ) );
		if ( $above && $above !== untrailingslashit( ABSPATH ) && @is_writable( $above ) ) { // phpcs:ignore
			// One sub-folder per site: several sites can share the folder above them.
			$candidates[] = $above . '/livepull-backups/' . self::site_folder();
		}
		$candidates[] = self::content_fallback();

		foreach ( $candidates as $c ) {
			if ( ( is_dir( $c ) || wp_mkdir_p( $c ) ) && wp_is_writable( $c ) ) {
				$dir = $c;
				break;
			}
		}
		if ( null === $dir ) {
			$dir = self::content_fallback();
		}
		self::protect( $dir );
		if ( basename( dirname( $dir ) ) === 'livepull-backups' ) {
			self::protect( dirname( $dir ) );
		}
		self::migrate_old( $dir );
		return $dir;
	}

	/** wp-content/livepull-backups-<random>: the random part is created once per site. */
	private static function content_fallback() {
		$suffix = get_option( 'livepull_backup_suffix' );
		if ( ! $suffix || ! preg_match( '/^[a-z0-9]{24}$/', $suffix ) ) {
			$suffix = strtolower( wp_generate_password( 24, false ) );
			update_option( 'livepull_backup_suffix', $suffix, false );
		}
		return WP_CONTENT_DIR . '/livepull-backups-' . $suffix;
	}

	/** Are backups inside the website's own folder (so only deny rules / random names protect them)? */
	public static function in_web_root() {
		return 0 === strpos( wp_normalize_path( self::dir() ), wp_normalize_path( untrailingslashit( ABSPATH ) ) . '/' );
	}

	/** Deny web access (Apache, LiteSpeed, IIS); harmless where the folder isn't web-reachable. */
	private static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php // Silence.\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
	}

	/** Backups made by versions before 0.5.0: move here and give them unguessable names. */
	private static function migrate_old( $dir ) {
		$old = WP_CONTENT_DIR . '/livepull-backups';
		if ( $old !== $dir && is_dir( $old ) ) {
			foreach ( (array) glob( $old . '/pre-*' ) as $f ) {
				if ( preg_match( self::NAME_RE, basename( $f ) ) ) {
					LivePull_Config::fs()->move( $f, $dir . '/' . basename( $f ), false );
				}
			}
			if ( ! glob( $old . '/pre-*' ) ) {
				LivePull_Config::fs()->delete( $old, true );
			}
		}
		foreach ( array_unique( array( $old, $dir ) ) as $from ) {
			foreach ( (array) glob( $from . '/pre-pull-*' ) as $f ) {
				if ( preg_match( '/^pre-(pull|copy)-(\d{8})-(\d{6})\.(sql(?:\.gz)?)$/', basename( $f ), $m ) ) {
					@rename( $f, $dir . '/pre-' . $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . self::token() . '.' . $m[4] ); // phpcs:ignore
				}
			}
		}
	}

	public static function site_folder() {
		return sanitize_file_name( basename( untrailingslashit( ABSPATH ) ) ) . '-' . substr( md5( ABSPATH . '|' . DB_NAME . '|' . $GLOBALS['wpdb']->prefix ), 0, 10 );
	}

	private static function token() {
		return bin2hex( random_bytes( 8 ) );
	}

	private static function new_name( $full, $gz ) {
		return ( $full ? 'pre-copy-' : 'pre-pull-' ) . gmdate( 'Ymd-His' ) . '-' . self::token() . '.sql' . ( $gz ? '.gz' : '' );
	}

	const NAME_RE = '/^pre-(pull|copy)-(\d{8})-(\d{6})-([a-f0-9]{16})\.sql(\.gz)?$/';

	/** @return string|WP_Error Backup file path. */
	public static function create( $full = false, $keep = '' ) {
		$dir = self::dir();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			$file   = $dir . '/' . self::new_name( $full, false );
			$tables = $full ? '' : ' --tables=' . escapeshellarg( implode( ',', self::existing( self::tables( false ) ) ) );
			$res    = WP_CLI::runcommand( 'db export ' . escapeshellarg( $file ) . $tables . ' --quiet', array( 'return' => 'all', 'exit_error' => false, 'launch' => true ) );
			if ( 0 === $res->return_code ) {
				self::prune( $keep );
				return $file;
			}
			// Fall through to the PHP dump (e.g. mysqldump missing).
		}

		$file = $dir . '/' . self::new_name( $full, function_exists( 'gzopen' ) );
		$res  = self::php_dump( $file, $full );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::prune( $keep );
		return $file;
	}

	private static function existing( array $tables ) {
		global $wpdb;
		return array_values( array_filter( $tables, function ( $t ) use ( $wpdb ) {
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t;
		} ) );
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
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->options, LivePull_Config::table() );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$tables[] = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$tables[] = $wpdb->prefix . 'wc_product_meta_lookup';
		}
		return apply_filters( 'livepull_backup_tables', $tables );
	}

	private static function php_dump( $file, $full = false ) {
		global $wpdb;
		$gz = '.gz' === substr( $file, -3 );
		$fh = $gz ? gzopen( $file, 'wb6' ) : fopen( $file, 'wb' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'livepull_backup', 'Could not write backup file in ' . dirname( $file ) );
		}
		$write = function ( $s ) use ( $fh, $gz ) {
			$gz ? gzwrite( $fh, $s ) : fwrite( $fh, $s ); // phpcs:ignore
		};
		$escape = function ( $v ) use ( $wpdb ) {
			if ( null === $v ) {
				return 'NULL';
			}
			if ( $wpdb->dbh instanceof mysqli ) {
				// Backups are SQL files replayed by restore() straight to MySQL, so values need the
				// connection's native escaping ($wpdb's escaping adds placeholders meant for $wpdb->query).
				return "'" . mysqli_real_escape_string( $wpdb->dbh, $v ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
			}
			return "'" . addslashes( $v ) . "'";
		};

		$write( "-- LivePull backup " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		foreach ( self::tables( $full ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			$write( "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			$offset = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, 500', $table, $offset ), ARRAY_N );
				if ( $rows ) {
					$values = array();
					foreach ( $rows as $row ) {
						$values[] = '(' . implode( ',', array_map( $escape, $row ) ) . ')';
					}
					$write( "INSERT INTO `{$table}` VALUES\n" . implode( ",\n", $values ) . ";\n" );
				}
				$offset += 500;
			} while ( count( $rows ) === 500 );
			$write( "\n" );
		}
		$write( "SET FOREIGN_KEY_CHECKS=1;\n" );
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
		return true;
	}

	/* ------------------------------------------------ Step-by-step (large databases) */

	/** Start a backup that's written a few thousand rows at a time across requests. */
	public static function chunk_start( $full = true ) {
		$gz = function_exists( 'gzopen' );
		return array(
			'file'   => self::dir() . '/' . self::new_name( $full, $gz ),
			'gz'     => $gz,
			'tables' => self::tables( $full ),
			'ti'     => 0,
			'offset' => 0,
			'begun'  => false,
		);
	}

	/**
	 * Write until $out() says time's up. @return bool|WP_Error true when finished.
	 * Each call appends to the file (gzip files may hold several members; every reader handles that).
	 */
	public static function chunk_step( array &$st, callable $out ) {
		global $wpdb;
		$fh = $st['gz'] ? gzopen( $st['file'], 'ab6' ) : fopen( $st['file'], 'ab' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'livepull_backup', 'Could not write backup file in ' . dirname( $st['file'] ) );
		}
		$gz    = $st['gz'];
		$write = function ( $s ) use ( $fh, $gz ) {
			$gz ? gzwrite( $fh, $s ) : fwrite( $fh, $s ); // phpcs:ignore
		};
		$esc = function ( $v ) use ( $wpdb ) {
			if ( null === $v ) {
				return 'NULL';
			}
			return "'" . ( $wpdb->dbh instanceof mysqli ? mysqli_real_escape_string( $wpdb->dbh, $v ) : addslashes( $v ) ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- see dump(): native escaping for SQL files.
		};
		if ( ! $st['begun'] ) {
			$write( '-- LivePull backup ' . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
			$st['begun'] = true;
		}
		$done = false;
		while ( ! $out() ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				$write( "SET FOREIGN_KEY_CHECKS=1;\n" );
				$done = true;
				break;
			}
			$table = $st['tables'][ $st['ti'] ];
			if ( 0 === $st['offset'] ) {
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( ! $create ) {
					$st['ti']++;
					continue;
				}
				$write( "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, 1000', $table, $st['offset'] ), ARRAY_N );
			if ( $rows ) {
				$values = array();
				foreach ( $rows as $row ) {
					$values[] = '(' . implode( ',', array_map( $esc, $row ) ) . ')';
				}
				$write( "INSERT INTO `{$table}` VALUES\n" . implode( ",\n", $values ) . ";\n" );
			}
			if ( count( $rows ) < 1000 ) {
				$st['ti']++;
				$st['offset'] = 0;
				$write( "\n" );
			} else {
				$st['offset'] += 1000;
			}
		}
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
		if ( $done ) {
			self::prune();
		}
		return $done;
	}

	/** Keep the newest backups, plus $keep (the backup about to be restored). */
	private static function prune( $keep = '' ) {
		$all = array_values( array_filter( self::all(), function ( $b ) use ( $keep ) {
			return ! $keep || wp_normalize_path( $b['file'] ) !== wp_normalize_path( $keep );
		} ) );
		foreach ( array_slice( $all, $keep ? self::KEEP - 1 : self::KEEP ) as $b ) {
			wp_delete_file( $b['file'] );
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
	 * every statement has succeeded are they switched in, all at the same instant. If anything
	 * fails, the temporary copies are dropped and this site is exactly as it was.
	 * Reads statement by statement, so large backups don't need much memory.
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
		$run = function ( $sql ) use ( $wpdb ) {
			// Restoring replays LivePull's own SQL file exactly. $wpdb->query() would refuse rows
			// it can't validate against the table's character set.
			if ( $wpdb->dbh instanceof mysqli ) {
				return mysqli_query( $wpdb->dbh, $sql ) ? true : mysqli_error( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.RestrictedFunctions.mysql_mysqli_error
			}
			return false === $wpdb->query( $sql ) ? $wpdb->last_error : true; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		self::drop_restore_tables();
		$run( 'SET FOREIGN_KEY_CHECKS=0' );
		$tables = array();
		$tag    = substr( md5( (string) microtime( true ) ), 0, 4 );
		$buf    = '';
		$error  = '';
		while ( true ) {
			$line = $gz ? gzgets( $fh ) : fgets( $fh );
			if ( false === $line ) {
				break;
			}
			if ( '' === $buf && ( '' === trim( $line ) || 0 === strpos( $line, '--' ) ) ) {
				continue;
			}
			$buf .= $line;
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$ok = $run( self::to_restore_tables( $buf, $tables, $tag ) );
				if ( true !== $ok ) {
					$error = $ok;
					break;
				}
				$buf = '';
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
			foreach ( $tables as $real => $tmp ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $real ) ) ) === $real ) {
					$old       = self::restore_name( $real, 'old' );
					$renames[] = '`' . $real . '` TO `' . $old . '`';
					$olds[]    = $old;
				}
				$renames[] = '`' . $tmp . '` TO `' . $real . '`';
			}
			$ok = $run( 'RENAME TABLE ' . implode( ', ', $renames ) );
			if ( true !== $ok ) {
				$error = $ok;
			} else {
				foreach ( $olds as $old ) {
					$run( 'DROP TABLE IF EXISTS `' . $old . '`' );
				}
			}
		}
		if ( $error ) {
			self::drop_restore_tables();
		}
		$run( 'SET FOREIGN_KEY_CHECKS=1' );
		wp_cache_flush();
		if ( $error ) {
			return new WP_Error( 'livepull_restore', 'Restore stopped with a database error, so nothing was changed: ' . $error );
		}
		// Backups are taken at the start of a pull, so they contain that pull's lock.
		delete_transient( 'livepull_lock' );
		return true;
	}

	/** Temporary name for a table being restored (or for the table it replaces). */
	private static function restore_name( $table, $kind = 'new' ) {
		global $wpdb;
		$rest = substr( $table, strlen( $wpdb->prefix ) );
		$name = $wpdb->prefix . self::RESTORE . ( 'old' === $kind ? 'o_' : 'n_' ) . $rest;
		return strlen( $name ) <= 64 ? $name : $wpdb->prefix . self::RESTORE . ( 'old' === $kind ? 'o_' : 'n_' ) . substr( md5( $rest ), 0, 20 );
	}

	/** Point a backup statement at the temporary copy of its table. */
	private static function to_restore_tables( $sql, array &$tables, $tag ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$map    = function ( $name ) use ( &$tables, $prefix ) {
			if ( 0 !== strpos( $name, $prefix ) || 0 === strpos( $name, $prefix . self::RESTORE ) ) {
				return $name;
			}
			if ( ! isset( $tables[ $name ] ) ) {
				$tables[ $name ] = self::restore_name( $name );
			}
			return $tables[ $name ];
		};
		$sql = preg_replace_callback(
			'/^(\s*(?:\/\*!\d+\s+)?(?:DROP TABLE IF EXISTS|DROP TABLE|CREATE TABLE IF NOT EXISTS|CREATE TABLE|INSERT INTO|INSERT IGNORE INTO|REPLACE INTO|LOCK TABLES|ALTER TABLE)\s+)`([^`]+)`/i',
			function ( $m ) use ( $map ) {
				return $m[1] . '`' . $map( $m[2] ) . '`';
			},
			$sql,
			1
		);
		if ( preg_match( '/^\s*CREATE TABLE/i', $sql ) ) {
			// Foreign keys: point at the temporary tables, and give constraints unique names.
			$sql = preg_replace_callback( '/REFERENCES\s+`([^`]+)`/i', function ( $m ) use ( $map ) {
				return 'REFERENCES `' . $map( $m[1] ) . '`';
			}, $sql );
			$sql = preg_replace_callback( '/CONSTRAINT\s+`([^`]+)`/i', function ( $m ) use ( $tag ) {
				return 'CONSTRAINT `' . substr( $m[1], 0, 56 ) . '_r' . $tag . '`';
			}, $sql );
		}
		return $sql;
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

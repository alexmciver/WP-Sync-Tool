<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database backup before a pull.
 *
 * In the terminal it uses `wp db export` (full database). From WP admin it writes a
 * gzipped SQL dump of the tables a pull can change, in PHP, so it works on any host.
 * Restore with `wp db import <file>` or phpMyAdmin.
 */
final class WPSync_Backup {

	const KEEP = 10;

	/**
	 * Where backups live. Outside the public web folder when possible (e.g. the folder above
	 * public_html / app/public), so they can never be downloaded even on servers that ignore
	 * .htaccess (nginx). Otherwise wp-content/wpsync-backups with deny rules and random names.
	 * Override with define( 'WPSYNC_BACKUP_DIR', '/absolute/path' ).
	 */
	public static function dir() {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		$candidates = array();
		if ( defined( 'WPSYNC_BACKUP_DIR' ) && WPSYNC_BACKUP_DIR ) {
			$candidates[] = untrailingslashit( WPSYNC_BACKUP_DIR );
		}
		$above = dirname( untrailingslashit( ABSPATH ) );
		if ( $above && $above !== untrailingslashit( ABSPATH ) && @is_writable( $above ) ) { // phpcs:ignore
			// One sub-folder per site: several sites can share the folder above them.
			$candidates[] = $above . '/wpsync-backups/' . self::site_folder();
		}
		$candidates[] = WP_CONTENT_DIR . '/wpsync-backups';

		foreach ( $candidates as $c ) {
			if ( ( is_dir( $c ) || wp_mkdir_p( $c ) ) && is_writable( $c ) ) {
				$dir = $c;
				break;
			}
		}
		if ( null === $dir ) {
			$dir = WP_CONTENT_DIR . '/wpsync-backups';
		}
		self::protect( $dir );
		if ( basename( dirname( $dir ) ) === 'wpsync-backups' ) {
			self::protect( dirname( $dir ) );
		}
		self::migrate_old( $dir );
		return $dir;
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
		$old = WP_CONTENT_DIR . '/wpsync-backups';
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
	public static function create( $full = false ) {
		$dir = self::dir();

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			$file = $dir . '/' . self::new_name( $full, false );
			$res  = WP_CLI::runcommand( 'db export ' . escapeshellarg( $file ) . ' --quiet', array( 'return' => 'all', 'exit_error' => false, 'launch' => true ) );
			if ( 0 === $res->return_code ) {
				self::prune();
				return $file;
			}
			// Fall through to the PHP dump (e.g. mysqldump missing).
		}

		$file = $dir . '/' . self::new_name( $full, function_exists( 'gzopen' ) );
		$res  = self::php_dump( $file, $full );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::prune();
		return $file;
	}

	private static function tables( $full = false ) {
		global $wpdb;
		if ( $full ) {
			$all = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
			return array_values( array_filter( $all, function ( $t ) use ( $wpdb ) {
				$n = substr( $t, strlen( $wpdb->prefix ) );
				return 0 !== strpos( $n, 'wpsynctmp_' ) && 0 !== strpos( $n, 'wpsyncold_' );
			} ) );
		}
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->users, $wpdb->usermeta, $wpdb->options, WPSync_Config::table() );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$tables[] = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$tables[] = $wpdb->prefix . 'wc_product_meta_lookup';
		}
		return apply_filters( 'wpsync_backup_tables', $tables );
	}

	private static function php_dump( $file, $full = false ) {
		global $wpdb;
		$gz = '.gz' === substr( $file, -3 );
		$fh = $gz ? gzopen( $file, 'wb6' ) : fopen( $file, 'wb' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'wpsync_backup', 'Could not write backup file in ' . dirname( $file ) );
		}
		$write = function ( $s ) use ( $fh, $gz ) {
			$gz ? gzwrite( $fh, $s ) : fwrite( $fh, $s ); // phpcs:ignore
		};
		$escape = function ( $v ) use ( $wpdb ) {
			if ( null === $v ) {
				return 'NULL';
			}
			if ( $wpdb->dbh instanceof mysqli ) {
				return "'" . mysqli_real_escape_string( $wpdb->dbh, $v ) . "'";
			}
			return "'" . addslashes( $v ) . "'";
		};

		$write( "-- WP Sync backup " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		foreach ( self::tables( $full ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			$write( "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			$offset = 0;
			do {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$offset}, 500", ARRAY_N );
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
			return new WP_Error( 'wpsync_backup', 'Could not write backup file in ' . dirname( $st['file'] ) );
		}
		$gz    = $st['gz'];
		$write = function ( $s ) use ( $fh, $gz ) {
			$gz ? gzwrite( $fh, $s ) : fwrite( $fh, $s ); // phpcs:ignore
		};
		$esc = function ( $v ) use ( $wpdb ) {
			if ( null === $v ) {
				return 'NULL';
			}
			return "'" . ( $wpdb->dbh instanceof mysqli ? mysqli_real_escape_string( $wpdb->dbh, $v ) : addslashes( $v ) ) . "'";
		};
		if ( ! $st['begun'] ) {
			$write( '-- WP Sync backup ' . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
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
				$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
				if ( ! $create ) {
					$st['ti']++;
					continue;
				}
				$write( "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT {$st['offset']}, 1000", ARRAY_N );
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

	private static function prune() {
		$all = self::all();
		foreach ( array_slice( $all, self::KEEP ) as $b ) {
			@unlink( $b['file'] ); // phpcs:ignore
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

	/**
	 * Put the database back to how it was in a backup (undo a pull).
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
			return new WP_Error( 'wpsync_restore', 'Could not open ' . basename( $file ) );
		}
		$run = function ( $sql ) use ( $wpdb ) {
			if ( $wpdb->dbh instanceof mysqli ) {
				return mysqli_query( $wpdb->dbh, $sql ) ? true : mysqli_error( $wpdb->dbh );
			}
			return false === $wpdb->query( $sql ) ? $wpdb->last_error : true; // phpcs:ignore
		};
		$run( 'SET FOREIGN_KEY_CHECKS=0' );
		$buf = '';
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
				$ok = $run( $buf );
				if ( true !== $ok ) {
					$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
					return new WP_Error( 'wpsync_restore', 'Restore stopped with a database error: ' . $ok . '. The database may be partly restored; try again, or import the file with your usual tool.' );
				}
				$buf = '';
			}
		}
		$gz ? gzclose( $fh ) : fclose( $fh ); // phpcs:ignore
		$run( 'SET FOREIGN_KEY_CHECKS=1' );
		wp_cache_flush();
		// Backups are taken at the start of a pull, so they contain that pull's lock.
		delete_transient( 'wpsync_lock' );
		return true;
	}

	public static function latest() {
		$all = self::all();
		return $all ? $all[0]['file'] : null;
	}
}

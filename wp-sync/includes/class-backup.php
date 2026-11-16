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

	public static function dir() {
		$dir = WP_CONTENT_DIR . '/wpsync-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** @return string|WP_Error Backup file path. */
	public static function create() {
		$dir   = self::dir();
		$stamp = gmdate( 'Ymd-His' );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			$file = $dir . '/pre-pull-' . $stamp . '.sql';
			$res  = WP_CLI::runcommand( 'db export ' . escapeshellarg( $file ) . ' --quiet', array( 'return' => 'all', 'exit_error' => false, 'launch' => true ) );
			if ( 0 === $res->return_code ) {
				self::prune();
				return $file;
			}
			// Fall through to the PHP dump (e.g. mysqldump missing).
		}

		$file = $dir . '/pre-pull-' . $stamp . '.sql' . ( function_exists( 'gzopen' ) ? '.gz' : '' );
		$res  = self::php_dump( $file );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::prune();
		return $file;
	}

	private static function tables() {
		global $wpdb;
		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->users, $wpdb->usermeta, $wpdb->options, WPSync_Config::table() );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$tables[] = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$tables[] = $wpdb->prefix . 'wc_product_meta_lookup';
		}
		return apply_filters( 'wpsync_backup_tables', $tables );
	}

	private static function php_dump( $file ) {
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

		$write( "-- WP Sync pre-pull backup " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
		foreach ( self::tables() as $table ) {
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

	private static function prune() {
		$files = glob( self::dir() . '/pre-pull-*' );
		if ( ! $files || count( $files ) <= self::KEEP ) {
			return;
		}
		sort( $files );
		foreach ( array_slice( $files, 0, count( $files ) - self::KEEP ) as $f ) {
			@unlink( $f ); // phpcs:ignore
		}
	}

	/** Backups, newest first. @return array[] { file, name, time, size } */
	public static function all() {
		$out = array();
		foreach ( (array) glob( self::dir() . '/pre-pull-*' ) as $f ) {
			if ( ! preg_match( '/^pre-pull-(\d{8})-(\d{6})\.sql(\.gz)?$/', basename( $f ), $m ) ) {
				continue;
			}
			$out[] = array(
				'file' => $f,
				'name' => basename( $f ),
				'time' => strtotime( $m[1] . 'T' . $m[2] . 'Z' ),
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
		if ( ! preg_match( '/^pre-pull-\d{8}-\d{6}\.sql(\.gz)?$/', $name ) ) {
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
		$files = glob( self::dir() . '/pre-pull-*' );
		if ( ! $files ) {
			return null;
		}
		sort( $files );
		return end( $files );
	}
}

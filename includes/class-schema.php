<?php
defined( 'ABSPATH' ) || exit;

// LivePull copies tables (Copy everything) and puts them back (restoring a backup), so it reads
// and writes tables directly. Every statement below goes through $wpdb->prepare(): table,
// column and index names as %i, values as %s/%d. Query text is only ever built from fixed
// strings and placeholders.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Tables as data, and back.
 *
 * describe() turns a table's structure into a plain array (columns, types, defaults, indexes).
 * create() builds the table again from that array, with every name and value passed through
 * placeholders and every type checked against a fixed list. No SQL text from another site or
 * from a file is ever run.
 */
final class LivePull_Schema {

	/** Column types LivePull can recreate, and how their arguments are written. */
	const TYPES = array(
		'tinyint'    => 'int',
		'smallint'   => 'int',
		'mediumint'  => 'int',
		'int'        => 'int',
		'integer'    => 'int',
		'bigint'     => 'int',
		'bit'        => 'int',
		'decimal'    => 'int',
		'numeric'    => 'int',
		'float'      => 'int',
		'double'     => 'int',
		'real'       => 'int',
		'date'       => 'none',
		'datetime'   => 'int',
		'timestamp'  => 'int',
		'time'       => 'int',
		'year'       => 'int',
		'char'       => 'int',
		'varchar'    => 'int',
		'binary'     => 'int',
		'varbinary'  => 'int',
		'tinyblob'   => 'none',
		'blob'       => 'int',
		'mediumblob' => 'none',
		'longblob'   => 'none',
		'tinytext'   => 'none',
		'text'       => 'int',
		'mediumtext' => 'none',
		'longtext'   => 'none',
		'json'       => 'none',
		'enum'       => 'list',
		'set'        => 'list',
		'uuid'       => 'none',
		'inet4'      => 'none',
		'inet6'      => 'none',
		'geometry'   => 'none',
		'point'      => 'none',
		'linestring' => 'none',
		'polygon'    => 'none',
	);

	const ENGINES = array( 'innodb' => 'InnoDB', 'myisam' => 'MyISAM', 'aria' => 'Aria' );

	/** A table's structure as plain data. @return array|WP_Error */
	public static function describe( $table ) {
		global $wpdb;
		$cols = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		if ( ! $cols ) {
			return new WP_Error( 'livepull_schema', 'Could not read the structure of table ' . $table . '.' );
		}
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		$out    = array(
			'engine'  => $status && ! empty( $status['Engine'] ) ? (string) $status['Engine'] : 'InnoDB',
			'columns' => array(),
			'keys'    => array(),
		);
		foreach ( $cols as $c ) {
			$out['columns'][] = array(
				'name'      => (string) $c['Field'],
				'type'      => strtolower( (string) $c['Type'] ),
				'collation' => null === $c['Collation'] ? '' : (string) $c['Collation'],
				'null'      => 'YES' === $c['Null'],
				'default'   => $c['Default'],
				'extra'     => strtolower( (string) $c['Extra'] ),
			);
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ) as $k ) {
			$name = (string) $k['Key_name'];
			if ( ! isset( $out['keys'][ $name ] ) ) {
				$out['keys'][ $name ] = array(
					'name'   => $name,
					'unique' => '0' === (string) $k['Non_unique'],
					'type'   => strtoupper( (string) $k['Index_type'] ),
					'cols'   => array(),
				);
			}
			$out['keys'][ $name ]['cols'][ (int) $k['Seq_in_index'] ] = array(
				'name' => (string) $k['Column_name'],
				'sub'  => null === $k['Sub_part'] ? 0 : (int) $k['Sub_part'],
			);
		}
		foreach ( $out['keys'] as &$key ) {
			ksort( $key['cols'] );
			$key['cols'] = array_values( $key['cols'] );
		}
		unset( $key );
		$out['keys'] = array_values( $out['keys'] );
		return $out;
	}

	/** The primary key's columns, in order. */
	public static function primary_key( array $desc ) {
		foreach ( $desc['keys'] as $k ) {
			if ( 'PRIMARY' === $k['name'] ) {
				return wp_list_pluck( $k['cols'], 'name' );
			}
		}
		return array();
	}

	/** Collations this server has (to fall back when another server's isn't available). */
	private static function collations() {
		global $wpdb;
		static $have = null;
		if ( null === $have ) {
			$have = array_flip( array_map( 'strtolower', (array) $wpdb->get_col( 'SELECT COLLATION_NAME FROM information_schema.COLLATIONS' ) ) );
		}
		return $have;
	}

	/** A collation for a text column here: the same one if this server has it, else a close one. */
	private static function collation( $name ) {
		$name = strtolower( (string) $name );
		if ( '' === $name || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return '';
		}
		$have = self::collations();
		if ( isset( $have[ $name ] ) ) {
			return $name;
		}
		$charset = strtok( $name, '_' );
		foreach ( array( $charset . '_unicode_ci', $charset . '_general_ci' ) as $alt ) {
			if ( isset( $have[ $alt ] ) ) {
				return $alt;
			}
		}
		return ''; // The table default.
	}

	/**
	 * One column's type as query text (fixed words and placeholders only) plus its arguments.
	 *
	 * @return array|WP_Error array( sql, args )
	 */
	private static function type_sql( $type ) {
		if ( ! preg_match( '/^([a-z0-9]+)(?:\((.*)\))?((?:\s+(?:unsigned|zerofill|signed))*)$/s', trim( $type ), $m ) || ! isset( self::TYPES[ $m[1] ] ) ) {
			return new WP_Error( 'livepull_schema', sprintf( 'a column type LivePull can\'t recreate (%s)', $type ) );
		}
		$base  = $m[1];
		$words = array( 'tinyint' => 'TINYINT', 'smallint' => 'SMALLINT', 'mediumint' => 'MEDIUMINT', 'int' => 'INT', 'integer' => 'INT', 'bigint' => 'BIGINT', 'bit' => 'BIT', 'decimal' => 'DECIMAL', 'numeric' => 'DECIMAL', 'float' => 'FLOAT', 'double' => 'DOUBLE', 'real' => 'DOUBLE', 'date' => 'DATE', 'datetime' => 'DATETIME', 'timestamp' => 'TIMESTAMP', 'time' => 'TIME', 'year' => 'YEAR', 'char' => 'CHAR', 'varchar' => 'VARCHAR', 'binary' => 'BINARY', 'varbinary' => 'VARBINARY', 'tinyblob' => 'TINYBLOB', 'blob' => 'BLOB', 'mediumblob' => 'MEDIUMBLOB', 'longblob' => 'LONGBLOB', 'tinytext' => 'TINYTEXT', 'text' => 'TEXT', 'mediumtext' => 'MEDIUMTEXT', 'longtext' => 'LONGTEXT', 'json' => 'LONGTEXT', 'enum' => 'ENUM', 'set' => 'SET', 'uuid' => 'CHAR(36)', 'inet4' => 'VARCHAR(15)', 'inet6' => 'VARCHAR(39)', 'geometry' => 'GEOMETRY', 'point' => 'POINT', 'linestring' => 'LINESTRING', 'polygon' => 'POLYGON' );
		$sql   = $words[ $base ];
		$args  = array();
		$inner = isset( $m[2] ) ? $m[2] : '';
		if ( '' !== $inner ) {
			if ( 'int' === self::TYPES[ $base ] ) {
				if ( ! preg_match( '/^\s*(\d{1,5})\s*(?:,\s*(\d{1,3})\s*)?$/', $inner, $n ) ) {
					return new WP_Error( 'livepull_schema', sprintf( 'a column size LivePull can\'t recreate (%s)', $type ) );
				}
				$sql   .= isset( $n[2] ) ? '(%d,%d)' : '(%d)';
				$args[] = (int) $n[1];
				if ( isset( $n[2] ) ) {
					$args[] = (int) $n[2];
				}
			} elseif ( 'list' === self::TYPES[ $base ] ) {
				// enum('a','b''c'): each value becomes a %s placeholder.
				if ( ! preg_match_all( "/'((?:[^']|'')*)'/s", $inner, $vals ) || ! $vals[1] ) {
					return new WP_Error( 'livepull_schema', sprintf( 'a list of values LivePull can\'t read (%s)', $type ) );
				}
				$sql .= '(' . implode( ',', array_fill( 0, count( $vals[1] ), '%s' ) ) . ')';
				foreach ( $vals[1] as $v ) {
					$args[] = str_replace( "''", "'", $v );
				}
			}
		}
		$mods = isset( $m[3] ) ? $m[3] : '';
		if ( false !== strpos( $mods, 'unsigned' ) ) {
			$sql .= ' UNSIGNED';
		}
		if ( false !== strpos( $mods, 'zerofill' ) ) {
			$sql .= ' ZEROFILL';
		}
		return array( $sql, $args );
	}

	/**
	 * Create table $name from a describe() array.
	 *
	 * @return true|WP_Error
	 */
	public static function create( $name, array $desc ) {
		global $wpdb;
		if ( empty( $desc['columns'] ) || ! is_array( $desc['columns'] ) ) {
			return new WP_Error( 'livepull_schema', 'no columns' );
		}
		$parts = array();
		$args  = array( $name );
		$cols  = array();
		foreach ( $desc['columns'] as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['name'], $c['type'] ) || ! is_string( $c['name'] ) || '' === $c['name'] ) {
				return new WP_Error( 'livepull_schema', 'a column without a name' );
			}
			$extra = isset( $c['extra'] ) ? strtolower( (string) $c['extra'] ) : '';
			if ( preg_match( '/\b(virtual|stored|persistent)\b/', $extra ) ) {
				return new WP_Error( 'livepull_schema', sprintf( 'a calculated column (%s), which LivePull can\'t copy', $c['name'] ) );
			}
			$type = self::type_sql( (string) $c['type'] );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$sql   = '%i ' . $type[0];
			$args[] = $c['name'];
			$args   = array_merge( $args, $type[1] );
			$coll   = self::collation( isset( $c['collation'] ) ? $c['collation'] : '' );
			if ( '' !== $coll && preg_match( '/char|text|enum|set/', (string) $c['type'] ) ) {
				$sql   .= ' COLLATE %s';
				$args[] = $coll;
			}
			$sql .= empty( $c['null'] ) ? ' NOT NULL' : ' NULL';
			$def  = array_key_exists( 'default', $c ) ? $c['default'] : null;
			if ( false !== strpos( $extra, 'auto_increment' ) ) {
				$sql .= ' AUTO_INCREMENT';
			} elseif ( null !== $def && preg_match( '/^current_timestamp(\(\d?\))?$/i', (string) $def ) ) {
				$sql .= ' DEFAULT CURRENT_TIMESTAMP';
			} elseif ( null !== $def && false === strpos( $extra, 'default_generated' ) && ! preg_match( '/blob|text|json|geometry|point|linestring|polygon/', (string) $c['type'] ) ) {
				$sql   .= ' DEFAULT %s';
				$args[] = (string) $def;
			} elseif ( null === $def && ! empty( $c['null'] ) ) {
				$sql .= ' DEFAULT NULL';
			}
			if ( preg_match( '/on update current_timestamp/', $extra ) ) {
				$sql .= ' ON UPDATE CURRENT_TIMESTAMP';
			}
			$parts[] = $sql;
			$cols[]  = $c['name'];
		}
		foreach ( (array) ( isset( $desc['keys'] ) ? $desc['keys'] : array() ) as $k ) {
			if ( ! is_array( $k ) || empty( $k['cols'] ) || ! isset( $k['name'] ) ) {
				continue;
			}
			$list = array();
			foreach ( $k['cols'] as $kc ) {
				if ( ! isset( $kc['name'] ) || ! in_array( $kc['name'], $cols, true ) ) {
					continue 2; // An index on a column we don't have: leave it out.
				}
				$list[] = empty( $kc['sub'] ) ? '%i' : '%i(%d)';
				$args[] = $kc['name'];
				if ( ! empty( $kc['sub'] ) ) {
					$args[] = (int) $kc['sub'];
				}
			}
			$type = isset( $k['type'] ) ? strtoupper( (string) $k['type'] ) : 'BTREE';
			if ( 'PRIMARY' === $k['name'] ) {
				$parts[] = 'PRIMARY KEY (' . implode( ',', $list ) . ')';
				continue;
			}
			$word = 'FULLTEXT' === $type ? 'FULLTEXT KEY' : ( 'SPATIAL' === $type ? 'SPATIAL KEY' : ( empty( $k['unique'] ) ? 'KEY' : 'UNIQUE KEY' ) );
			// The index name goes before its columns: put it in the right place in $args.
			$pos = count( $args ) - count( $k['cols'] ) - count( array_filter( wp_list_pluck( $k['cols'], 'sub' ) ) );
			array_splice( $args, $pos, 0, array( (string) $k['name'] ) );
			$parts[] = $word . ' %i (' . implode( ',', $list ) . ')';
		}
		$engine = isset( $desc['engine'] ) ? strtolower( (string) $desc['engine'] ) : 'innodb';
		$engine = isset( self::ENGINES[ $engine ] ) ? self::ENGINES[ $engine ] : 'InnoDB';
		// Query text: fixed words and placeholders only (the charset clause is WordPress's own).
		$query = 'CREATE TABLE %i (' . implode( ', ', $parts ) . ') ENGINE=' . $engine . ' ' . $wpdb->get_charset_collate();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) );
		// $query holds only fixed words and placeholders; every name and value is in $args.
		if ( false === $wpdb->query( $wpdb->prepare( $query, $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return new WP_Error( 'livepull_schema', $wpdb->last_error ? $wpdb->last_error : 'the database refused the table' );
		}
		return true;
	}

	/**
	 * Add rows to $table. Each value is a %s placeholder (NULL stays NULL), sent in batches.
	 *
	 * @param string[] $cols Column names.
	 * @param array[]  $rows Lists of values in column order.
	 * @return true|WP_Error
	 */
	public static function insert( $table, array $cols, array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return true;
		}
		$head  = 'INSERT INTO %i (' . implode( ',', array_fill( 0, count( $cols ), '%i' ) ) . ') VALUES ';
		$hargs = array_merge( array( $table ), array_values( $cols ) );
		$tuples = array();
		$args   = array();
		$size   = 0;
		$flush  = function () use ( &$tuples, &$args, &$size, $head, $hargs, $wpdb, $table ) {
			if ( ! $tuples ) {
				return true;
			}
			// $head and $tuples are placeholders only (%i, %s, NULL); every name and value is in the arguments.
			$ok = $wpdb->query( $wpdb->prepare( $head . implode( ',', $tuples ), array_merge( $hargs, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$tuples = array();
			$args   = array();
			$size   = 0;
			return false === $ok ? new WP_Error( 'livepull_insert', 'Writing ' . $table . ' failed: ' . $wpdb->last_error ) : true;
		};
		foreach ( $rows as $row ) {
			$marks = array();
			foreach ( array_values( $row ) as $v ) {
				if ( null === $v ) {
					$marks[] = 'NULL';
				} else {
					$marks[] = '%s';
					$args[]  = (string) $v;
					$size   += strlen( (string) $v );
				}
			}
			$tuples[] = '(' . implode( ',', $marks ) . ')';
			if ( $size > 800000 || count( $tuples ) >= 500 ) {
				$ok = $flush();
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		}
		return $flush();
	}

	/**
	 * Switch tables in one statement: array( from => to, … ), all at the same instant.
	 *
	 * @return true|WP_Error
	 */
	public static function rename( array $pairs ) {
		global $wpdb;
		if ( ! $pairs ) {
			return true;
		}
		$args = array();
		foreach ( $pairs as $from => $to ) {
			$args[] = $from;
			$args[] = $to;
		}
		$query = 'RENAME TABLE ' . implode( ', ', array_fill( 0, count( $pairs ), '%i TO %i' ) );
		// $query is "RENAME TABLE %i TO %i, …": placeholders only; the table names are in $args.
		if ( false === $wpdb->query( $wpdb->prepare( $query, $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return new WP_Error( 'livepull_rename', $wpdb->last_error );
		}
		return true;
	}

	/** Values that can't travel as text (binary data) are wrapped as base64. */
	public static function pack( $v ) {
		if ( null === $v || preg_match( '//u', (string) $v ) ) {
			return $v;
		}
		return array( 'b64' => base64_encode( (string) $v ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary column values.
	}

	public static function unpack( $v ) {
		if ( is_array( $v ) && isset( $v['b64'] ) ) {
			return base64_decode( (string) $v['b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary column values.
		}
		return null === $v ? null : ( is_scalar( $v ) ? (string) $v : null );
	}
}

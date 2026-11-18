<?php
/**
 * Pull history: a short record of every pull, copy and restore on this site, so you can see
 * what came across when, and what was skipped or held back.
 *
 * @package LivePull
 */

defined( 'ABSPATH' ) || exit;

class LivePull_History {

	const OPTION = 'livepull_history';
	const KEEP   = 30;
	const ITEMS  = 100; // Names kept per list, per entry.

	/**
	 * Record an event.
	 *
	 * @param string $kind   pull, copy or restore.
	 * @param array  $report created/updated/deleted/conflicts/skipped/errors/held lists, files.
	 * @param array  $extra  source (manual, cli, scheduled), backup, note.
	 */
	public static function add( $kind, array $report = array(), array $extra = array() ) {
		$list  = self::all();
		$entry = array(
			'time'   => time(),
			'kind'   => $kind,
			'source' => isset( $extra['source'] ) ? $extra['source'] : ( defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'manual' ),
			'user'   => get_current_user_id() ? wp_get_current_user()->user_login : '',
			'backup' => isset( $extra['backup'] ) ? basename( (string) $extra['backup'] ) : '',
			'note'   => isset( $extra['note'] ) ? (string) $extra['note'] : '',
			'files'  => isset( $report['files'] ) ? (int) $report['files'] : 0,
		);
		foreach ( array( 'created', 'updated', 'deleted', 'conflicts', 'skipped', 'errors', 'held' ) as $k ) {
			$items = isset( $report[ $k ] ) ? array_values( (array) $report[ $k ] ) : array();
			if ( 'conflicts' === $k ) {
				$items = array_map( function ( $c ) {
					return is_array( $c ) && isset( $c['label'] ) ? $c['label'] . ( isset( $c['reason'] ) ? ' – ' . $c['reason'] : '' ) : (string) $c;
				}, $items );
			}
			$entry[ $k . '_count' ] = count( $items );
			$entry[ $k ]            = array_slice( array_map( 'strval', $items ), 0, self::ITEMS );
		}
		array_unshift( $list, $entry );
		update_option( self::OPTION, array_slice( $list, 0, self::KEEP ), false );
	}

	public static function all() {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) ? $h : array();
	}

	/** One line, e.g. "3 new, 5 updated, 1 deleted, 2 conflicts". */
	public static function summary( array $e ) {
		$parts = array();
		$c = function ( $k ) use ( $e ) {
			return isset( $e[ $k . '_count' ] ) ? (int) $e[ $k . '_count' ] : 0;
		};
		if ( $c( 'created' ) ) {
			/* translators: %d: number of new items */
			$parts[] = sprintf( __( '%d new', 'livepull' ), $c( 'created' ) );
		}
		if ( $c( 'updated' ) ) {
			/* translators: %d: number of updated items */
			$parts[] = sprintf( __( '%d updated', 'livepull' ), $c( 'updated' ) );
		}
		if ( $c( 'deleted' ) ) {
			/* translators: %d: number of deleted items */
			$parts[] = sprintf( __( '%d deleted', 'livepull' ), $c( 'deleted' ) );
		}
		if ( $c( 'conflicts' ) ) {
			/* translators: %d: number of conflicts */
			$parts[] = sprintf( _n( '%d conflict', '%d conflicts', $c( 'conflicts' ), 'livepull' ), $c( 'conflicts' ) );
		}
		if ( $c( 'held' ) ) {
			/* translators: %d: number of items held back */
			$parts[] = sprintf( __( '%d held back', 'livepull' ), $c( 'held' ) );
		}
		if ( $c( 'skipped' ) ) {
			/* translators: %d: number of skipped items */
			$parts[] = sprintf( __( '%d skipped', 'livepull' ), $c( 'skipped' ) );
		}
		if ( $c( 'errors' ) ) {
			/* translators: %d: number of errors */
			$parts[] = sprintf( _n( '%d error', '%d errors', $c( 'errors' ), 'livepull' ), $c( 'errors' ) );
		}
		if ( ! empty( $e['files'] ) ) {
			/* translators: %d: number of files */
			$parts[] = sprintf( _n( '%d media file', '%d media files', (int) $e['files'], 'livepull' ), (int) $e['files'] );
		}
		if ( ! $parts && isset( $e['kind'] ) && 'copy' === $e['kind'] ) {
			return __( 'This site became a copy of live', 'livepull' );
		}
		if ( ! $parts && isset( $e['kind'] ) && 'restore' === $e['kind'] ) {
			return __( 'Put back as it was', 'livepull' );
		}
		return $parts ? implode( ', ', $parts ) : __( 'nothing changed', 'livepull' );
	}
}

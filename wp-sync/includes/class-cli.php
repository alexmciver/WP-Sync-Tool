<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pull content changes from the live site into this local copy.
 * Same engine as Tools → WP Sync in WP admin.
 *
 * ## EXAMPLES
 *
 *     wp sync status
 *     wp sync pull --dry-run
 *     wp sync pull
 *     wp sync conflicts
 *     wp sync take 512
 */
class WPSync_CLI {

	/**
	 * Create the log table and give all existing content a sync ID. Safe to re-run.
	 *
	 * @subcommand init
	 */
	public function init_( $args, $assoc ) {
		WPSync_Config::install( false );
		$r = WPSync_Ids::backfill();
		WP_CLI::success( sprintf( 'Ready. Newly stamped: %d posts, %d terms. Duplicates fixed: %d.', $r['posts'], $r['terms'], $r['dupes'] ) );
	}

	/**
	 * Connect this site to live (alternative to wp-config constants or the admin screen).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Live site URL.
	 *
	 * <key>
	 * : The key shown on the live site's Tools → WP Sync screen.
	 */
	public function connect( $args, $assoc ) {
		if ( defined( 'WPSYNC_REMOTE' ) ) {
			WP_CLI::error( 'WPSYNC_REMOTE is set in wp-config.php; change it there.' );
		}
		update_option( 'wpsync_remote', untrailingslashit( esc_url_raw( $args[0] ) ), false );
		update_option( 'wpsync_remote_secret', trim( $args[1] ), false );
		$this->status( array(), array() );
	}

	/**
	 * Check the connection to live and show what's pending.
	 */
	public function status( $args, $assoc ) {
		$notices = array();
		$remote  = WPSync_Puller::connect( $notices );
		if ( is_wp_error( $remote ) ) {
			WP_CLI::error( $remote->get_error_message() );
		}
		foreach ( $notices as $n ) {
			WP_CLI::log( WP_CLI::colorize( '%B' . $n . '%n' ) );
		}
		$cursor = (int) get_option( 'wpsync_cursor', 0 );
		$last   = get_option( 'wpsync_last_pull' );
		WP_CLI::log( 'Live site:        ' . $remote['home'] . ' (WP Sync ' . $remote['version'] . ')' );
		WP_CLI::log( 'This site:        ' . home_url() );
		WP_CLI::log( 'Last pull:        ' . ( $last ? $last . ' UTC' : 'never' ) );
		WP_CLI::log( 'Changes waiting on live:           ' . max( 0, (int) $remote['last_id'] - $cursor ) );
		WP_CLI::log( 'Changed here since last pulled:    ' . count( WPSync_Importer::locally_changed() ) );
		WP_CLI::log( 'Unresolved conflicts:              ' . count( WPSync_Puller::conflicts() ) );
	}

	/**
	 * Pull new and changed content from live.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would happen without changing anything.
	 *
	 * [--theirs]
	 * : On conflict, take live's version (overwrites your local edits to those items).
	 *
	 * [--since=<date>]
	 * : Catch-up mode (YYYY-MM-DD). Pulls everything modified on live since this date, for when
	 * your local copy was made before WP Sync was installed on live.
	 *
	 * [--from=<log-id>]
	 * : Start from this live log ID instead of where the last pull finished (0 = from the beginning).
	 *
	 * [--redownload-media]
	 * : Download media files even if they already exist locally.
	 *
	 * [--no-url-replace]
	 * : Keep live URLs in content instead of rewriting them to this site's URL.
	 *
	 * [--skip-backup]
	 * : Don't back up the local database before pulling.
	 *
	 * [--force]
	 * : Ignore the "another pull is running" lock.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sync pull --dry-run
	 *     wp sync pull
	 *     wp sync pull --since=2026-10-01
	 */
	public function pull( $args, $assoc ) {
		$flag  = function ( $k, $d = false ) use ( $assoc ) {
			return \WP_CLI\Utils\get_flag_value( $assoc, $k, $d );
		};
		$state = WPSync_Puller::start( array(
			'dry_run'     => $flag( 'dry-run' ),
			'theirs'      => $flag( 'theirs' ),
			'since'       => isset( $assoc['since'] ) ? $assoc['since'] : '',
			'from'        => isset( $assoc['from'] ) ? (int) $assoc['from'] : null,
			'skip_backup' => $flag( 'skip-backup' ),
			'redownload'  => $flag( 'redownload-media' ),
			'url_replace' => $flag( 'url-replace', true ),
			'force'       => $flag( 'force' ),
		) );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}
		foreach ( $state['notices'] as $n ) {
			WP_CLI::log( WP_CLI::colorize( '%B' . $n . '%n' ) );
		}
		$shown_backup = false;
		do {
			$state = WPSync_Puller::step( $state, 5 );
			if ( is_wp_error( $state ) ) {
				WP_CLI::error( $state->get_error_message() );
			}
			if ( ! $shown_backup && $state['backup_file'] ) {
				WP_CLI::log( 'Backup: ' . $state['backup_file'] );
				$shown_backup = true;
			}
			$r = $state['report'];
			WP_CLI::log( sprintf( '%s · %d new, %d updated, %d deleted, %d conflicts, %d files', $state['activity'], count( $r['created'] ), count( $r['updated'] ), count( $r['deleted'] ), count( $r['conflicts'] ), $r['files'] ) );
		} while ( ! $state['done'] );

		$this->print_report( $state['report'], $state['opts']['dry_run'] );
	}

	/**
	 * List items changed on live AND locally, which pulls left alone.
	 */
	public function conflicts( $args, $assoc ) {
		$conflicts = WPSync_Puller::conflicts();
		if ( ! $conflicts ) {
			WP_CLI::success( 'No unresolved conflicts.' );
			return;
		}
		$rows = array();
		foreach ( $conflicts as $key => $c ) {
			$term   = 0 === strpos( $key, 'term:' );
			$ref    = $c['local_id'] ? $c['local_id'] : substr( $key, strpos( $key, ':' ) + 1 );
			$rows[] = array(
				'item'              => $c['label'],
				'reason'            => $c['reason'],
				'take live version' => 'wp sync take ' . $ref . ( $term ? ' --term' : '' ),
				'keep yours'        => 'wp sync keep ' . $ref . ( $term ? ' --term' : '' ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'reason', 'take live version', 'keep yours' ) );
	}

	/**
	 * Overwrite one local item with live's current version.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Local post/term ID, or a 32-character sync ID.
	 *
	 * [--term]
	 * : The ID is a term, not a post.
	 */
	public function take( $args, $assoc ) {
		$key = WPSync_Puller::key_from( $args[0], \WP_CLI\Utils\get_flag_value( $assoc, 'term', false ) ? 'term' : 'post' );
		if ( is_wp_error( $key ) ) {
			WP_CLI::error( $key->get_error_message() );
		}
		$report = WPSync_Puller::take( $key );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		$this->print_report( $report, false );
	}

	/**
	 * Keep your local version of a conflicted item and dismiss the conflict.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Local post/term ID, or a 32-character sync ID.
	 *
	 * [--term]
	 * : The ID is a term, not a post.
	 */
	public function keep( $args, $assoc ) {
		$key = WPSync_Puller::key_from( $args[0], \WP_CLI\Utils\get_flag_value( $assoc, 'term', false ) ? 'term' : 'post' );
		if ( is_wp_error( $key ) ) {
			WP_CLI::error( $key->get_error_message() );
		}
		WPSync_Puller::keep( $key );
		WP_CLI::success( 'Keeping your local version. If live edits it again you will be warned again.' );
	}

	/**
	 * List content changed on this site since it was last pulled from live.
	 *
	 * @subcommand local-changes
	 */
	public function local_changes( $args, $assoc ) {
		$rows = WPSync_Puller::local_changes();
		if ( ! $rows ) {
			WP_CLI::success( 'Nothing changed locally since the last pull.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'item', 'action' ) );
	}

	/**
	 * Undo a pull: restore a backup taken before it.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Backup file name (see `wp sync backups`). Defaults to the most recent.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function restore( $args, $assoc ) {
		$all = WPSync_Backup::all();
		if ( ! $all ) {
			WP_CLI::error( 'No backups found in wp-content/wpsync-backups/.' );
		}
		$file = isset( $args[0] ) ? WPSync_Backup::find( $args[0] ) : $all[0]['file'];
		if ( ! $file ) {
			WP_CLI::error( 'Backup not found. Run `wp sync backups` to list them.' );
		}
		WP_CLI::confirm( 'Restore ' . basename( $file ) . '? Everything pulled or edited since then will be undone.', $assoc );
		$now = WPSync_Backup::create();
		if ( ! is_wp_error( $now ) ) {
			WP_CLI::log( 'Current state backed up first: ' . basename( $now ) );
		}
		$res = WPSync_Backup::restore( $file );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( 'Restored ' . basename( $file ) . '.' );
	}

	/**
	 * List backups taken before pulls.
	 */
	public function backups( $args, $assoc ) {
		$rows = array();
		foreach ( WPSync_Backup::all() as $b ) {
			$rows[] = array( 'file' => $b['name'], 'taken' => wp_date( 'Y-m-d H:i:s', $b['time'] ), 'size' => size_format( $b['size'] ) );
		}
		$rows ? \WP_CLI\Utils\format_items( 'table', $rows, array( 'file', 'taken', 'size' ) ) : WP_CLI::log( 'No backups yet.' );
	}

	/**
	 * Show this site's key, for connecting a local copy to it.
	 *
	 * @subcommand key
	 */
	public function key_( $args, $assoc ) {
		$secret = WPSync_Config::secret();
		if ( strlen( $secret ) < 32 ) {
			if ( defined( 'WPSYNC_SECRET' ) ) {
				WP_CLI::error( 'WPSYNC_SECRET in wp-config.php is shorter than 32 characters.' );
			}
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'wpsync_secret', $secret, false );
			WP_CLI::log( 'Generated a new key.' );
		}
		WP_CLI::log( 'URL: ' . home_url() );
		WP_CLI::log( 'Key: ' . $secret );
		WP_CLI::log( 'On your local site run:  wp sync connect ' . home_url() . ' ' . $secret );
	}

	/**
	 * Delete old log rows. Mainly useful on live.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Keep this many days of history.
	 * ---
	 * default: 180
	 * ---
	 */
	public function prune( $args, $assoc ) {
		global $wpdb;
		$days = max( 1, (int) $assoc['days'] );
		$n    = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WPSync_Config::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		WP_CLI::success( "Deleted {$n} log rows older than {$days} days." );
	}

	private function print_report( array $r, $dry ) {
		foreach ( array( 'created' => 'create', 'updated' => 'update', 'deleted' => 'delete' ) as $k => $label ) {
			if ( $r[ $k ] ) {
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( '%G' . ( $dry ? 'Would ' . $label : ucfirst( $k ) ) . ' (' . count( $r[ $k ] ) . ')%n' ) );
				foreach ( $r[ $k ] as $line ) {
					WP_CLI::log( '  ' . $line );
				}
			}
		}
		if ( $r['conflicts'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%YConflicts – left as they are locally (' . count( $r['conflicts'] ) . ')%n' ) );
			foreach ( $r['conflicts'] as $c ) {
				WP_CLI::log( '  ' . $c['label'] . ' – ' . $c['reason'] );
			}
			WP_CLI::log( '  Resolve with: wp sync conflicts' );
		}
		foreach ( array( 'skipped' => '%Y', 'errors' => '%R' ) as $k => $colour ) {
			if ( $r[ $k ] ) {
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( $colour . ucfirst( $k ) . ' (' . count( $r[ $k ] ) . ')%n' ) );
				foreach ( $r[ $k ] as $line ) {
					WP_CLI::log( '  ' . $line );
				}
			}
		}
		WP_CLI::log( '' );
		$summary = sprintf( '%s%d created, %d updated, %d deleted, %d conflicts, %d media files downloaded.', $dry ? 'Dry run: ' : '', count( $r['created'] ), count( $r['updated'] ), count( $r['deleted'] ), count( $r['conflicts'] ), $r['files'] );
		$r['errors'] ? WP_CLI::warning( $summary ) : WP_CLI::success( $summary );
	}
}

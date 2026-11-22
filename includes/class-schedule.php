<?php
/**
 * Scheduled pulls: keep a staging (or always-on local) site up to date with live
 * automatically. Each run backs up first, leaves conflicts and held-back items for you to
 * decide, and can email a short summary.
 *
 * WordPress runs scheduled tasks when the site gets visits (or from a server cron job), so
 * this suits staging sites that are always online. A local site that is switched off simply
 * catches up on the next visit.
 *
 * @package LivePull
 */

defined( 'ABSPATH' ) || exit;

class LivePull_Schedule {

	const OPTION = 'livepull_schedule';
	const HOOK   = 'livepull_scheduled_pull';

	/** Allowed frequencies (WordPress's own schedule names). */
	public static function choices() {
		return array(
			'off'        => __( 'Off', 'livepull' ),
			'hourly'     => __( 'Every hour', 'livepull' ),
			'twicedaily' => __( 'Twice a day', 'livepull' ),
			'daily'      => __( 'Once a day', 'livepull' ),
			'weekly'     => __( 'Once a week', 'livepull' ),
		);
	}

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	/**
	 * Keep the scheduled event in place. Copy everything and restoring a backup replace
	 * WordPress's list of scheduled events, and a schedule saved before connecting to live
	 * had nothing to run yet: put it back whenever it should exist but doesn't.
	 */
	public static function ensure() {
		$s = self::settings();
		if ( 'off' !== $s['every'] && LivePull_Config::remote() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $s['every'], self::HOOK );
		}
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), array( 'every' => 'off', 'email' => true, 'last_run' => 0, 'last_result' => '', 'user' => 0 ) );
		if ( ! isset( self::choices()[ $s['every'] ] ) ) {
			$s['every'] = 'off';
		}
		return $s;
	}

	/** Save the frequency/email choice and (re)schedule. */
	public static function save( $every, $email ) {
		$s          = self::settings();
		$s['every'] = isset( self::choices()[ $every ] ) ? $every : 'off';
		$s['email'] = (bool) $email;
		// Scheduled pulls run with no one logged in: they run as the administrator who set the
		// schedule, so WordPress filters content exactly as it would for them.
		if ( current_user_can( 'manage_options' ) ) {
			$s['user'] = get_current_user_id();
		}
		update_option( self::OPTION, $s, false );
		wp_clear_scheduled_hook( self::HOOK );
		if ( 'off' !== $s['every'] && LivePull_Config::remote() ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, $s['every'], self::HOOK );
		}
		return $s;
	}

	/** When the next scheduled pull is due (timestamp), or 0. */
	public static function next() {
		return (int) wp_next_scheduled( self::HOOK );
	}

	/**
	 * One scheduled pull. Never forces past a running pull, never overrides your local edits
	 * and never pulls held-back items.
	 *
	 * @return array|WP_Error the pull's report.
	 */
	public static function run() {
		$s = self::settings();
		if ( 'off' === $s['every'] || ! LivePull_Config::remote() ) {
			return new WP_Error( 'livepull_schedule_off', 'Scheduled pulls are off.' );
		}
		if ( ! get_current_user_id() && ! empty( $s['user'] ) && user_can( (int) $s['user'], 'manage_options' ) ) {
			wp_set_current_user( (int) $s['user'] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			// Without an administrator, WordPress would filter pulled HTML (page-builder markup
			// could be stripped), so don't pull at all.
			$msg              = __( 'Scheduled pulls need to know which administrator they run as. Open Tools → LivePull → Settings and press Save under "Pull automatically".', 'livepull' );
			$failed_before    = ! empty( $s['failing'] );
			$s['last_run']    = time();
			$s['last_result'] = $msg;
			$s['failing']     = true;
			update_option( self::OPTION, $s, false );
			if ( $s['email'] && ! $failed_before ) {
				self::email( null, new WP_Error( 'livepull_schedule_user', $msg ), $msg );
			}
			return new WP_Error( 'livepull_schedule_user', $msg );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		$state = LivePull_Puller::start( array( 'source' => 'scheduled' ) );
		$i     = 0;
		while ( ! is_wp_error( $state ) && ! $state['done'] && $i++ < 500 ) {
			$state = LivePull_Puller::step( $state, 20 );
		}
		$more = false;
		if ( ! is_wp_error( $state ) && ! $state['done'] ) {
			// Ran out of steps (a very big catch-up). That's progress, not a failure: what came
			// across is saved and recorded, and the next run carries on from there.
			LivePull_Puller::cancel( $state['lock'] );
			$more = true;
			LivePull_History::add( 'pull', $state['report'], array( 'source' => 'scheduled', 'backup' => isset( $state['backup_file'] ) ? $state['backup_file'] : '', 'note' => __( 'More to do: the next scheduled pull carries on.', 'livepull' ) ) );
		}
		if ( is_wp_error( $state ) && 'livepull_locked' === $state->get_error_code() ) {
			return $state; // Another pull is running: skip quietly and keep the last result as it was.
		}
		$report = is_wp_error( $state ) ? null : $state['report'];
		if ( is_wp_error( $state ) ) {
			$result = $state->get_error_message();
			LivePull_History::add( 'pull', array( 'errors' => array( $result ) ), array( 'source' => 'scheduled' ) );
		} else {
			$result = LivePull_History::summary( array(
				'created_count'   => count( $report['created'] ),
				'updated_count'   => count( $report['updated'] ),
				'deleted_count'   => count( $report['deleted'] ),
				'conflicts_count' => count( $report['conflicts'] ),
				'held_count'      => count( $report['held'] ),
				'errors_count'    => count( $report['errors'] ),
				'files'           => $report['files'],
			) );
			if ( $more ) {
				$result .= ' ' . __( '(more to do: the next run carries on)', 'livepull' );
			}
		}
		$failed_before    = ! empty( $s['failing'] );
		$s['last_run']    = time();
		$s['last_result'] = $result;
		$s['failing']     = is_wp_error( $state );
		update_option( self::OPTION, $s, false );

		// A failure is emailed once, not every run while live stays unreachable.
		if ( $s['email'] && ! ( is_wp_error( $state ) && $failed_before ) ) {
			self::email( $report, is_wp_error( $state ) ? $state : null, $result );
		}
		return is_wp_error( $state ) ? $state : $report;
	}

	/** Email the site admin when something came across, needs a decision, or went wrong. */
	private static function email( $report, $error, $summary ) {
		if ( $error && 'livepull_locked' === $error->get_error_code() ) {
			return;
		}
		$changes = $report ? count( $report['created'] ) + count( $report['updated'] ) + count( $report['deleted'] ) : 0;
		$needs   = $report ? count( $report['conflicts'] ) + count( $report['errors'] ) : 0;
		if ( ! $error && ! $changes && ! $needs ) {
			return; // Nothing new: no email.
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $error ) {
			/* translators: %s: site address */
			$subject = sprintf( __( '[%s] Scheduled LivePull pull failed', 'livepull' ), $site );
			$body    = $error->get_error_message() . "\n\n" . __( 'You won\'t get another email about this until a scheduled pull works again.', 'livepull' );
		} else {
			/* translators: 1: site address, 2: summary */
			$subject = sprintf( __( '[%1$s] LivePull pulled from live: %2$s', 'livepull' ), $site, $summary );
			$body    = '';
			foreach ( array( 'created' => __( 'New', 'livepull' ), 'updated' => __( 'Updated', 'livepull' ), 'deleted' => __( 'Deleted', 'livepull' ), 'errors' => __( 'Errors', 'livepull' ) ) as $k => $title ) {
				if ( $report[ $k ] ) {
					$body .= $title . ":\n- " . implode( "\n- ", array_slice( $report[ $k ], 0, 30 ) ) . "\n\n";
				}
			}
			if ( $report['conflicts'] ) {
				/* translators: %d: number of conflicts */
				$body .= sprintf( __( '%d item(s) were changed both on live and here, so they were left as they are. Choose what to keep:', 'livepull' ), count( $report['conflicts'] ) ) . "\n";
			}
		}
		$body .= "\n" . admin_url( 'tools.php?page=livepull' ) . "\n\n" . __( 'You get this email because scheduled pulls are on in Tools → LivePull.', 'livepull' );
		wp_mail( get_option( 'admin_email' ), $subject, LivePull_Config::scrub( $body ) );
	}
}

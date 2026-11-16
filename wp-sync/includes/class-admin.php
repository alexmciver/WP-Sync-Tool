<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tools → WP Sync screen.
 *
 * Live site:  shows the URL + key to paste into the local site.
 * Local site: connect to live, preview, pull, resolve conflicts, see local changes.
 */
final class WPSync_Admin {

	const SLUG = 'wpsync';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wpsync_save', array( __CLASS__, 'save' ) );
		foreach ( array( 'status', 'pull', 'resolve', 'local_changes', 'cancel' ) as $a ) {
			add_action( 'wp_ajax_wpsync_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function menu() {
		$hook = add_management_page( 'WP Sync', 'WP Sync', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'noscript_note' ) );
		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	public static function assets() {
		// Works whether installed as a normal plugin or a must-use plugin.
		$base = plugins_url( 'wp-sync/assets/', WPSYNC_FILE );
		wp_enqueue_style( 'wpsync-admin', $base . 'admin.css', array(), WPSync_Config::VERSION );
		wp_enqueue_script( 'wpsync-admin', $base . 'admin.js', array(), WPSync_Config::VERSION, true );
		wp_localize_script( 'wpsync-admin', 'WPSYNC', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wpsync' ),
			'configured' => (bool) WPSync_Config::remote(),
		) );
	}

	/** If the script fails to load, say so instead of spinning forever. */
	public static function noscript_note() {
		echo '<script>window.addEventListener("load",function(){if(!window.WPSYNC_LOADED){var s=document.getElementById("wpsync-status");if(s&&s.classList.contains("is-loading")){s.className="wpsync-status is-error";s.textContent="The WP Sync script did not load (check the plugin files are complete, and that no optimisation plugin is blocking wp-sync/assets/admin.js).";}}});</script>';
	}

	/* ================================================================ Save */

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wpsync_save' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = 'saved';

		if ( 'connect' === $do && ! defined( 'WPSYNC_REMOTE' ) ) {
			$url = isset( $_POST['remote'] ) ? esc_url_raw( trim( wp_unslash( $_POST['remote'] ) ) ) : '';
			$key = isset( $_POST['remote_key'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['remote_key'] ) ) : '';
			update_option( 'wpsync_remote', untrailingslashit( $url ), false );
			if ( '' !== $key && ! defined( 'WPSYNC_REMOTE_SECRET' ) ) {
				update_option( 'wpsync_remote_secret', $key, false );
			}
			$msg = 'connected';
		} elseif ( 'disconnect' === $do && ! defined( 'WPSYNC_REMOTE' ) ) {
			delete_option( 'wpsync_remote' );
			delete_option( 'wpsync_remote_secret' );
			$msg = 'disconnected';
		} elseif ( 'generate' === $do && ! defined( 'WPSYNC_SECRET' ) ) {
			update_option( 'wpsync_secret', bin2hex( random_bytes( 32 ) ), false );
			$msg = 'key';
		}
		wp_safe_redirect( self::url( array( 'msg' => $msg ) ) );
		exit;
	}

	/* ============================================================== Render */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$configured = (bool) WPSync_Config::remote();
		$msgs       = array(
			'connected'    => 'Connection saved. Checking live…',
			'disconnected' => 'Disconnected from live.',
			'key'          => 'New key generated. Copy it into your local site.',
		);
		$msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		?>
		<div class="wrap wpsync">
			<h1>WP Sync</h1>
			<p class="wpsync-lead">Pull content your client adds or edits on the live site into this copy, without overwriting your own work.</p>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning"><p>Multisite networks are not supported yet.</p></div>
			<?php endif; ?>

			<?php
			if ( $configured ) {
				self::render_pull();
				self::render_source( true );
			} else {
				self::render_choose();
			}
			?>
		</div>
		<?php
	}

	/** First visit: explain the two roles. */
	private static function render_choose() {
		?>
		<div class="wpsync-grid">
			<div class="wpsync-card">
				<h2>Is this your local or staging copy?</h2>
				<p>Connect it to the live site, then pull changes with one click.</p>
				<?php self::connect_form(); ?>
			</div>
			<?php self::render_source( false ); ?>
		</div>
		<?php
	}

	private static function connect_form() {
		$remote_const = defined( 'WPSYNC_REMOTE' );
		$key_const    = defined( 'WPSYNC_REMOTE_SECRET' );
		list( $cur_key, $cur_from ) = WPSync_Config::remote_secret_source();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpsync-form">
			<?php wp_nonce_field( 'wpsync_save' ); ?>
			<input type="hidden" name="action" value="wpsync_save">
			<input type="hidden" name="do" value="connect">
			<p>
				<label for="wpsync-remote"><strong>Live site URL</strong></label><br>
				<input type="url" id="wpsync-remote" name="remote" class="regular-text" placeholder="https://www.example.co.uk"
					value="<?php echo esc_attr( WPSync_Config::remote() ); ?>" <?php disabled( $remote_const ); ?> required>
				<?php if ( $remote_const ) : ?><br><span class="description">Set in wp-config.php (WPSYNC_REMOTE).</span><?php endif; ?>
			</p>
			<p>
				<label for="wpsync-key"><strong>Live site's WP Sync key</strong></label><br>
				<input type="password" id="wpsync-key" name="remote_key" class="regular-text code" autocomplete="off"
					placeholder="<?php echo esc_attr( $cur_key ? 'Paste a new key to replace it' : 'Paste from Tools → WP Sync on the live site' ); ?>" <?php disabled( $key_const ); ?>>
				<?php if ( $cur_key ) : ?><br><span class="description">Currently using the key ending <code><?php echo esc_html( WPSync_Config::key_hint( $cur_key ) ); ?></code> (<?php echo esc_html( $cur_from ); ?>).</span><?php endif; ?>
			</p>
			<?php if ( ! $remote_const || ! $key_const ) : ?>
				<p><button class="button button-primary"><?php echo WPSync_Config::remote() ? 'Save' : 'Save and connect'; ?></button></p>
			<?php endif; ?>
		</form>
		<?php
	}

	/** The pull screen (this site pulls from live). */
	private static function render_pull() {
		$conflicts = WPSync_Puller::conflicts();
		?>
		<div class="wpsync-card wpsync-main">
			<div class="wpsync-head">
				<h2>Pull from live</h2>
				<span class="wpsync-remote"><?php echo esc_html( WPSync_Config::remote() ); ?></span>
			</div>

			<div id="wpsync-status" class="wpsync-status is-loading"><span class="spinner is-active"></span> Checking live site…</div>
			<div id="wpsync-notices"></div>

			<div class="wpsync-actions">
				<button type="button" class="button button-hero" id="wpsync-preview" disabled>Preview changes</button>
				<button type="button" class="button button-primary button-hero" id="wpsync-pull" disabled>Pull changes</button>
				<button type="button" class="button-link wpsync-danger" id="wpsync-cancel" hidden>Cancel</button>
			</div>

			<details class="wpsync-advanced">
				<summary>More options</summary>
				<p>
					<label><strong>Catch-up from date</strong><br>
					<input type="date" id="wpsync-since"></label><br>
					<span class="description">Use once if your local copy was made before WP Sync was installed on live: pulls everything changed on live since that date.</span>
				</p>
				<p>
					<label><input type="checkbox" id="wpsync-theirs"> Let live win all conflicts</label><br>
					<span class="description">Overwrites your local edits wherever live also changed the same item.</span>
				</p>
				<p>
					<label><input type="checkbox" id="wpsync-redownload"> Re-download media files that already exist</label>
				</p>
			</details>

			<div id="wpsync-progress" class="wpsync-progress" hidden>
				<div class="wpsync-bar"><span></span></div>
				<p class="wpsync-progress-text"></p>
			</div>
			<div id="wpsync-result"></div>
		</div>

		<div class="wpsync-card">
			<h2>Conflicts <span class="count" id="wpsync-conflict-count">(<?php echo (int) count( $conflicts ); ?>)</span></h2>
			<p class="description">Items changed on live <em>and</em> here since they were last pulled. They were left as they are here until you choose.</p>
			<div id="wpsync-conflicts"><?php self::conflicts_table( $conflicts ); ?></div>
		</div>

		<div class="wpsync-card">
			<details id="wpsync-local-details">
				<summary><h2>Your local changes</h2></summary>
				<p class="description">Everything edited on this site since it was last pulled. If live changes any of these, you'll get a conflict instead of an overwrite.</p>
				<div id="wpsync-local"><p><span class="spinner is-active"></span></p></div>
			</details>
		</div>

		<div class="wpsync-card">
			<details id="wpsync-connection">
				<summary><h2>Connection</h2></summary>
				<?php self::connect_form(); ?>
				<?php if ( ! defined( 'WPSYNC_REMOTE' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Disconnect from the live site?');">
					<?php wp_nonce_field( 'wpsync_save' ); ?>
					<input type="hidden" name="action" value="wpsync_save">
					<input type="hidden" name="do" value="disconnect">
					<button class="button-link wpsync-danger">Disconnect</button>
				</form>
				<?php endif; ?>
				<?php $latest = WPSync_Backup::latest(); ?>
				<p class="description">Backups are saved before every pull in <code>wp-content/wpsync-backups/</code><?php echo $latest ? ' (latest: <code>' . esc_html( basename( $latest ) ) . '</code>)' : ''; ?>. Restore with <code>wp db import</code> or phpMyAdmin.</p>
				<p class="description">Terminal: <code>wp sync status</code>, <code>wp sync pull --dry-run</code>, <code>wp sync pull</code>.</p>
			</details>
		</div>
		<?php
	}

	public static function conflicts_table( array $conflicts ) {
		if ( ! $conflicts ) {
			echo '<p class="wpsync-empty">No conflicts.</p>';
			return;
		}
		echo '<table class="widefat striped wpsync-table"><thead><tr><th>Item</th><th>What happened</th><th></th></tr></thead><tbody>';
		foreach ( $conflicts as $key => $c ) {
			$edit = '';
			if ( ! empty( $c['local_id'] ) ) {
				$edit = 0 === strpos( $key, 'term:' ) ? get_edit_term_link( (int) $c['local_id'] ) : get_edit_post_link( (int) $c['local_id'], 'raw' );
			}
			printf(
				'<tr data-key="%1$s"><td>%2$s</td><td>%3$s</td><td class="wpsync-row-actions"><button type="button" class="button wpsync-take">Use live version</button> <button type="button" class="button-link wpsync-keep">Keep mine</button></td></tr>',
				esc_attr( $key ),
				$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $c['label'] ) . '</a>' : esc_html( $c['label'] ),
				esc_html( ucfirst( $c['reason'] ) )
			);
		}
		echo '</tbody></table>';
	}

	/** "Let other sites pull from this one" (shown on live, and folded away on local). */
	private static function render_source( $collapsed ) {
		$secret = WPSync_Config::secret();
		$const  = defined( 'WPSYNC_SECRET' );
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WPSync_Config::table() . " WHERE source = 'local'" );
		?>
		<div class="wpsync-card">
			<?php if ( $collapsed ) : ?><details><summary><h2>Let other sites pull from this one</h2></summary><?php else : ?>
			<h2>Is this the live site?</h2>
			<p>Nothing else to set up here. Copy these two values into <strong>Tools → WP Sync</strong> on your local site.</p>
			<?php endif; ?>

			<p><label><strong>Site URL</strong></label><br>
				<span class="wpsync-copy"><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( home_url() ); ?>"><button type="button" class="button wpsync-copy-btn">Copy</button></span></p>

			<p><label><strong>WP Sync key</strong></label><br>
			<?php if ( strlen( $secret ) >= 32 ) : ?>
				<span class="wpsync-copy"><input type="password" readonly class="regular-text code wpsync-secret" value="<?php echo esc_attr( $secret ); ?>"><button type="button" class="button wpsync-reveal">Show</button><button type="button" class="button wpsync-copy-btn">Copy</button></span>
				<br><span class="description">Ends in <code><?php echo esc_html( WPSync_Config::key_hint( $secret ) ); ?></code><?php echo $const ? ' · set in wp-config.php (WPSYNC_SECRET)' : ''; ?>.</span>
			<?php else : ?>
				<span class="description">No key yet.</span>
			<?php endif; ?>
			</p>

			<?php if ( ! $const ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo strlen( $secret ) >= 32 ? 'onsubmit="return confirm(\'Generate a new key? Local sites using the old key will stop connecting until you paste the new one in.\');"' : ''; ?>>
				<?php wp_nonce_field( 'wpsync_save' ); ?>
				<input type="hidden" name="action" value="wpsync_save">
				<input type="hidden" name="do" value="generate">
				<button class="button <?php echo strlen( $secret ) >= 32 ? '' : 'button-primary'; ?>"><?php echo strlen( $secret ) >= 32 ? 'Generate a new key' : 'Generate key'; ?></button>
			</form>
			<?php endif; ?>

			<p class="description">Changes recorded on this site: <?php echo (int) $count; ?>. Pulling never writes anything to this site; the key only allows reading content.</p>
			<?php if ( $collapsed ) : ?></details><?php endif; ?>
		</div>
		<?php
	}

	/* ================================================================ AJAX */

	private static function guard() {
		check_ajax_referer( 'wpsync' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
	}

	public static function ajax_status() {
		self::guard();
		$notices = array();
		$remote  = WPSync_Puller::connect( $notices );
		if ( is_wp_error( $remote ) ) {
			wp_send_json_error( array( 'message' => $remote->get_error_message(), 'code' => $remote->get_error_code() ) );
		}
		$cursor = (int) get_option( 'wpsync_cursor', 0 );
		$last   = get_option( 'wpsync_last_pull' );
		wp_send_json_success( array(
			'live'       => $remote['home'],
			'waiting'    => max( 0, (int) $remote['last_id'] - $cursor ),
			'last_pull'  => $last ? human_time_diff( strtotime( $last . ' UTC' ) ) . ' ago' : 'never',
			'local'      => count( WPSync_Importer::locally_changed() ),
			'conflicts'  => count( WPSync_Puller::conflicts() ),
			'notices'    => $notices,
		) );
	}

	public static function ajax_pull() {
		self::guard();
		$user = get_current_user_id();
		$tkey = 'wpsync_run_' . $user;
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		if ( 'start' === $step ) {
			delete_transient( $tkey );
			$state = WPSync_Puller::start( array(
				'dry_run'    => ! empty( $_POST['dry'] ),
				'theirs'     => ! empty( $_POST['theirs'] ),
				'redownload' => ! empty( $_POST['redownload'] ),
				'since'      => isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '',
			) );
			if ( is_wp_error( $state ) ) {
				wp_send_json_error( array( 'message' => $state->get_error_message(), 'code' => $state->get_error_code() ) );
			}
		} else {
			$state = get_transient( $tkey );
			if ( ! $state ) {
				wp_send_json_error( array( 'message' => 'This pull session expired. Start it again; progress so far is saved.' ) );
			}
		}

		$state = WPSync_Puller::step( $state );
		if ( is_wp_error( $state ) ) {
			delete_transient( $tkey );
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}

		if ( $state['done'] ) {
			delete_transient( $tkey );
		} else {
			set_transient( $tkey, $state, HOUR_IN_SECONDS );
		}

		$r = $state['report'];
		wp_send_json_success( array(
			'done'      => $state['done'],
			'dry'       => (bool) $state['opts']['dry_run'],
			'page'      => $state['page'],
			'after'     => $state['after'],
			'last_id'   => (int) $state['remote']['last_id'],
			'mode'      => $state['mode'],
			'notices'   => 1 === $state['page'] ? $state['notices'] : array(),
			'backup'    => $state['backup_file'] ? basename( $state['backup_file'] ) : '',
			'report'    => array(
				'created'   => $r['created'],
				'updated'   => $r['updated'],
				'deleted'   => $r['deleted'],
				'conflicts' => array_values( $r['conflicts'] ),
				'skipped'   => $r['skipped'],
				'errors'    => $r['errors'],
				'files'     => $r['files'],
			),
			'activity'  => $state['activity'],
			'dl_total'  => (int) $state['dl_total'],
			'dl_done'   => (int) $state['dl_done'],
			'conflicts_html' => $state['done'] ? self::capture( array( __CLASS__, 'conflicts_table' ), WPSync_Puller::conflicts() ) : '',
			'conflict_count' => count( WPSync_Puller::conflicts() ),
		) );
	}

	public static function ajax_cancel() {
		self::guard();
		delete_transient( 'wpsync_run_' . get_current_user_id() );
		WPSync_Puller::cancel();
		wp_send_json_success( array( 'message' => 'Stopped. Everything up to the last finished batch is kept; pull again to continue.' ) );
	}

	public static function ajax_resolve() {
		self::guard();
		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( 'keep' === $mode ) {
			WPSync_Puller::keep( $key );
			$message = 'Kept your version.';
		} else {
			$r = WPSync_Puller::take( $key );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$message = $r['errors'] ? 'Updated with warnings: ' . implode( '; ', $r['errors'] ) : 'Replaced with the live version.';
		}
		wp_send_json_success( array( 'message' => $message, 'conflict_count' => count( WPSync_Puller::conflicts() ) ) );
	}

	public static function ajax_local_changes() {
		self::guard();
		$rows = WPSync_Puller::local_changes();
		ob_start();
		if ( ! $rows ) {
			echo '<p class="wpsync-empty">Nothing changed here since the last pull.</p>';
		} else {
			echo '<table class="widefat striped wpsync-table"><thead><tr><th>Item</th><th>Change</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				printf(
					'<tr><td>%s</td><td>%s</td></tr>',
					$row['edit'] ? '<a href="' . esc_url( $row['edit'] ) . '">' . esc_html( $row['item'] ) . '</a>' : esc_html( $row['item'] ),
					esc_html( $row['action'] )
				);
			}
			echo '</tbody></table>';
		}
		wp_send_json_success( array( 'html' => ob_get_clean(), 'count' => count( $rows ) ) );
	}

	private static function capture( $cb, $arg ) {
		ob_start();
		call_user_func( $cb, $arg );
		return ob_get_clean();
	}
}

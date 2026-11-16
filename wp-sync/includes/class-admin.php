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
		foreach ( array( 'status', 'pull', 'resolve', 'local_changes', 'cancel', 'restore', 'full' ) as $a ) {
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
			$url  = isset( $_POST['remote'] ) ? esc_url_raw( trim( wp_unslash( $_POST['remote'] ) ) ) : '';
			$key  = isset( $_POST['remote_key'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['remote_key'] ) ) : '';
			$code = isset( $_POST['code'] ) ? self::parse_code( wp_unslash( $_POST['code'] ) ) : null;
			if ( $code ) {
				list( $url, $key ) = $code;
			} elseif ( ! empty( $_POST['code'] ) ) {
				wp_safe_redirect( self::url( array( 'msg' => 'badcode' ) ) );
				exit;
			}
			if ( '' === $url ) {
				wp_safe_redirect( self::url( array( 'msg' => 'nourl' ) ) );
				exit;
			}
			update_option( 'wpsync_remote', untrailingslashit( $url ), false );
			if ( '' !== $key && ! defined( 'WPSYNC_REMOTE_SECRET' ) ) {
				update_option( 'wpsync_remote_secret', $key, false );
			}
			$msg = 'connected';
		} elseif ( 'disconnect' === $do && ! defined( 'WPSYNC_REMOTE' ) ) {
			delete_option( 'wpsync_remote' );
			delete_option( 'wpsync_remote_secret' );
			$msg = 'disconnected';
		} elseif ( 'fullcopy' === $do && ! defined( 'WPSYNC_ALLOW_FULL_COPY' ) ) {
			$allow = isset( $_POST['allow'] ) ? sanitize_key( wp_unslash( $_POST['allow'] ) ) : 'off';
			update_option( 'wpsync_allow_full_copy', 'hour' === $allow ? (string) ( time() + HOUR_IN_SECONDS ) : ( 'always' === $allow ? 'always' : 'no' ), false );
			$msg = 'saved';
		} elseif ( 'generate' === $do && ! defined( 'WPSYNC_SECRET' ) ) {
			update_option( 'wpsync_secret', bin2hex( random_bytes( 32 ) ), false );
			$msg = 'key';
		}
		wp_safe_redirect( self::url( array( 'msg' => $msg ) ) );
		exit;
	}

	/** A connection code carries the live URL and key in one paste: "wpsync1:" + base64(JSON). */
	public static function make_code() {
		return 'wpsync1:' . base64_encode( wp_json_encode( array( 'u' => home_url(), 'k' => WPSync_Config::secret() ) ) ); // phpcs:ignore
	}

	/** @return array{0:string,1:string}|null [ url, key ] */
	public static function parse_code( $code ) {
		$code = trim( (string) $code );
		if ( 0 !== strpos( $code, 'wpsync1:' ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( substr( $code, 8 ), true ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['k'] ) || strlen( $data['k'] ) < 32 ) {
			return null;
		}
		$url = esc_url_raw( $data['u'] );
		return $url ? array( untrailingslashit( $url ), preg_replace( '/\s+/', '', $data['k'] ) ) : null;
	}

	/* ============================================================== Render */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$configured = (bool) WPSync_Config::remote();
		if ( isset( $_GET['wpsync_check'] ) && check_admin_referer( 'wpsync_check' ) ) {
			$found = WPSync_Updater::check_now();
			if ( ! $found ) {
				$msg = 'checkfailed';
			} else {
				$msg = WPSync_Updater::available() ? '' : 'uptodate';
			}
		}
		$msgs       = array(
			'uptodate'     => 'WP Sync is up to date (version ' . WPSync_Config::VERSION . ').',
			'checkfailed'  => 'Couldn\'t check GitHub for updates: ' . WPSync_Updater::last_error() . '. You can always download the latest wp-sync.zip from github.com/alexmciver/WP-Sync-Tool/releases and upload it.',
			'connected'    => 'Connection saved. Checking live…',
			'disconnected' => 'Disconnected from live.',
			'key'          => 'New key generated. Copy it into your local site.',
			'restored'     => 'Backup restored. You may need to log in again if you were logged out.',
			'saved'        => 'Saved.',
			'badcode'      => 'That connection code isn\'t valid. Copy it again from Tools → WP Sync on the live site (use the Copy button).',
			'nourl'        => 'Paste the connection code from the live site, or fill in its URL and key.',
		);
		if ( ! isset( $msg ) ) {
			$msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ( isset( $_GET['restored'] ) ? 'restored' : '' );
		}
		$update = WPSync_Updater::available();
		?>
		<div class="wrap wpsync">
			<h1>WP Sync <span class="wpsync-version">v<?php echo esc_html( WPSync_Config::VERSION ); ?></span>
				<?php if ( has_filter( 'pre_set_site_transient_update_plugins', array( 'WPSync_Updater', 'inject' ) ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( self::url( array( 'wpsync_check' => 1 ) ), 'wpsync_check' ) ); ?>">Check for updates</a>
				<?php endif; ?>
			</h1>
			<p class="wpsync-lead">Pull content your client adds or edits on the live site into this copy, without overwriting your own work.</p>
			<?php if ( $update ) : ?>
				<div class="notice notice-info wpsync-update">
					<p><strong>WP Sync <?php echo esc_html( $update['version'] ); ?> is available</strong> (you have <?php echo esc_html( WPSync_Config::VERSION ); ?>).
					<a href="<?php echo esc_url( $update['url'] ); ?>" target="_blank" rel="noopener">What's new</a></p>
					<p><a class="button button-primary" href="<?php echo esc_url( WPSync_Updater::update_url() ); ?>">Update now</a>
					<span class="description">&nbsp;Update both your live and local sites so they stay on the same version.</span></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo in_array( $msg, array( 'checkfailed', 'badcode', 'nourl' ), true ) ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$copies = array();
			foreach ( get_plugins() as $file => $data ) {
				if ( isset( $data['Name'] ) && 0 === strpos( $data['Name'], 'WP Sync' ) ) {
					$copies[ $file ] = $data['Version'];
				}
			}
			if ( count( $copies ) > 1 ) :
				?>
				<div class="notice notice-warning"><p><strong>WP Sync is installed more than once</strong> (<?php echo esc_html( implode( ', ', array_map( function ( $f, $v ) { return dirname( $f ) . ' v' . $v; }, array_keys( $copies ), $copies ) ) ); ?>). Only the copy in <code><?php echo esc_html( dirname( plugin_basename( WPSYNC_FILE ) ) ); ?></code> is running. Delete the others on the <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Plugins</a> screen (deactivate first) so updates go to the right one.</p></div>
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
			<?php if ( ! $remote_const && ! $key_const ) : ?>
			<p>
				<label for="wpsync-code"><strong>Connection code</strong></label><br>
				<input type="text" id="wpsync-code" name="code" class="large-text code" autocomplete="off" placeholder="wpsync1:… – copy it from Tools → WP Sync on the live site">
				<br><span class="description">Paste this and you're done. Or fill in the two fields below instead.</span>
			</p>
			<?php endif; ?>
			<p>
				<label for="wpsync-remote"><strong>Live site URL</strong></label><br>
				<input type="url" id="wpsync-remote" name="remote" class="regular-text" placeholder="https://www.example.co.uk"
					value="<?php echo esc_attr( WPSync_Config::remote() ); ?>" <?php disabled( $remote_const ); ?>>
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

			<p id="wpsync-recording" class="description" hidden></p>
			<div id="wpsync-status" class="wpsync-status is-loading"><span class="spinner is-active"></span> Checking live site…</div>
			<p id="wpsync-last-summary" class="description" hidden></p>
			<div id="wpsync-notices"></div>

			<div class="wpsync-actions">
				<button type="button" class="button button-hero" id="wpsync-preview" disabled>Preview changes</button>
				<button type="button" class="button button-primary button-hero" id="wpsync-pull" disabled>Pull changes</button>
				<button type="button" class="button-link wpsync-danger" id="wpsync-cancel" hidden>Cancel</button>
			</div>

			<details class="wpsync-advanced wpsync-what">
				<summary>What gets pulled?</summary>
				<div class="wpsync-what-grid">
					<div><strong>Every pull</strong>
						<ul class="wpsync-bullets">
							<li>Posts, pages and custom post types (incl. WooCommerce products)</li>
							<li>Media library + files</li>
							<li>Categories, tags and other taxonomies</li>
							<li>Custom fields (ACF etc.) and page-builder content</li>
							<li>Menus and menu locations<?php echo WPSync_Config::pull_menus() ? '' : ' <em>(switched off on this site)</em>'; ?></li>
							<li>Site title, tagline, homepage, posts page, sticky posts, site icon, logo</li>
						</ul>
					</div>
					<div><strong>Only with <em>Copy everything from live</em></strong>
						<ul class="wpsync-bullets">
							<li>Theme, Customizer and Divi theme options</li>
							<li>Divi Theme Builder &amp; Library, Elementor templates</li>
							<li>Widgets, forms and other plugin settings</li>
							<li>Users</li>
						</ul>
						<strong>Never</strong>
						<ul class="wpsync-bullets"><li>WooCommerce orders and customers' sessions</li><li>Theme and plugin files (keep those in Git)</li></ul>
					</div>
				</div>
			</details>

			<details class="wpsync-advanced">
				<summary>More options</summary>
				<p>
					<label><input type="checkbox" id="wpsync-all"> <strong>Pull all content from live</strong> (first sync)</label><br>
					<span class="description">Compares every post, page, media item and category on live, not just changes recorded since WP Sync was installed. Your local edits are still kept as conflicts.</span>
				</p>
				<p>
					<label><strong>Or catch up from a date</strong><br>
					<input type="date" id="wpsync-since"></label><br>
					<span class="description">Pulls everything changed on live since that date, e.g. the day you made your local copy.</span>
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
			<details id="wpsync-full-card">
				<summary><h2>Copy everything from live</h2></summary>
				<p>Replaces this site's <strong>whole database</strong> with a copy of live: posts, pages, menus, settings, Divi Theme Builder and Library, widgets, users and plugin data. Live URLs are switched to <code><?php echo esc_html( home_url() ); ?></code>. Use it to start fresh, or when this copy has drifted too far from live.</p>
				<ul class="wpsync-bullets">
					<li>Your current database is backed up first (restore it any time under <em>Undo a pull or copy</em>).</li>
					<li>Nothing here changes until the very end, so you can cancel safely while it runs.</li>
					<li>Kept as they are here: this site's address, its WP Sync connection and "Discourage search engines".</li>
					<li>Theme and plugin <em>files</em> aren't copied – keep those in Git.</li>
					<li>Afterwards, log in with your <strong>live site's</strong> username and password.</li>
				</ul>
				<p><label><input type="checkbox" id="wpsync-full-files" checked> Also copy the uploads folder (images and other files; only missing or changed files are downloaded)</label></p>
				<p><button type="button" class="button" id="wpsync-full-start">Copy everything from live…</button>
				<button type="button" class="button-link wpsync-danger" id="wpsync-full-cancel" hidden>Cancel</button></p>
				<div id="wpsync-full-progress" class="wpsync-progress" hidden>
					<div class="wpsync-bar"><span></span></div>
					<p class="wpsync-progress-text"></p>
				</div>
				<div id="wpsync-full-result"></div>
			</details>
		</div>

		<div class="wpsync-card">
			<h2>Conflicts <span class="count" id="wpsync-conflict-count">(<?php echo (int) count( $conflicts ); ?>)</span></h2>
			<p class="description">Items changed on live <em>and</em> here since they were last pulled. They were left as they are here until you choose.</p>
			<div id="wpsync-conflicts"><?php self::conflicts_table( $conflicts ); ?></div>
		</div>

		<?php $backups = WPSync_Backup::all(); ?>
		<div class="wpsync-card">
			<details id="wpsync-backups" <?php echo isset( $_GET['restored'] ) ? 'open' : ''; ?>>
				<summary><h2>Undo a pull or copy</h2></summary>
				<p class="description">A backup is taken before every pull and every full copy. Restoring one puts this site's posts, pages, media library, categories, users and settings back exactly as they were at that moment (media files that were downloaded stay in the uploads folder).</p>
				<?php if ( ! $backups ) : ?>
					<p class="wpsync-empty">No backups yet.</p>
				<?php else : ?>
				<table class="widefat striped wpsync-table"><thead><tr><th>Backup taken</th><th>Size</th><th></th></tr></thead><tbody>
				<?php foreach ( array_slice( $backups, 0, 10 ) as $b ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'D j M Y, H:i:s', $b['time'] ) ); ?> <span class="description">(<?php echo esc_html( human_time_diff( $b['time'] ) ); ?> ago, before a <?php echo 'copy' === $b['kind'] ? 'full copy' : 'pull'; ?>)</span></td>
						<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
						<td class="wpsync-row-actions"><button type="button" class="button wpsync-restore" data-file="<?php echo esc_attr( $b['name'] ); ?>">Restore</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php endif; ?>
			</details>
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
			<p>Nothing else to set up here. Copy the connection code into <strong>Tools → WP Sync</strong> on your local site.</p>
			<?php endif; ?>

			<?php if ( strlen( $secret ) >= 32 ) : ?>
			<p><label><strong>Connection code</strong></label><br>
				<span class="wpsync-copy"><input type="password" readonly class="large-text code" value="<?php echo esc_attr( self::make_code() ); ?>"><button type="button" class="button wpsync-reveal">Show</button><button type="button" class="button button-primary wpsync-copy-btn">Copy</button></span>
				<br><span class="description">Contains this site's address and key. Treat it like a password.</span></p>
			<details class="wpsync-sub"><summary>Or copy the address and key separately</summary>
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
			<?php if ( strlen( $secret ) >= 32 ) : ?></details><?php endif; ?>

			<?php if ( ! $const ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo strlen( $secret ) >= 32 ? 'onsubmit="return confirm(\'Generate a new key? Local sites using the old key will stop connecting until you paste the new one in.\');"' : ''; ?>>
				<?php wp_nonce_field( 'wpsync_save' ); ?>
				<input type="hidden" name="action" value="wpsync_save">
				<input type="hidden" name="do" value="generate">
				<button class="button <?php echo strlen( $secret ) >= 32 ? '' : 'button-primary'; ?>"><?php echo strlen( $secret ) >= 32 ? 'Generate a new key' : 'Generate key'; ?></button>
			</form>
			<?php endif; ?>

			<?php $perm = WPSync_Full::permission(); ?>
			<div class="wpsync-inline-form">
				<strong>Copy everything from this site:</strong>
				<?php if ( 'always' === $perm ) : ?>
					<span class="wpsync-pill is-on">Allowed</span>
				<?php elseif ( 'off' === $perm ) : ?>
					<span class="wpsync-pill">Off</span>
				<?php else : ?>
					<span class="wpsync-pill is-on">Allowed for <?php echo esc_html( human_time_diff( time(), time() + (int) $perm ) ); ?></span>
				<?php endif; ?>
				<br><span class="description">Lets a site with the key download this site's whole database (including users and plugin settings) and uploads. Normal pulls don't need this.</span>
				<?php if ( ! defined( 'WPSYNC_ALLOW_FULL_COPY' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpsync-perm-buttons">
					<?php wp_nonce_field( 'wpsync_save' ); ?>
					<input type="hidden" name="action" value="wpsync_save">
					<input type="hidden" name="do" value="fullcopy">
					<button class="button <?php echo 'off' === $perm ? 'button-primary' : ''; ?>" name="allow" value="hour">Allow for 1 hour</button>
					<button class="button" name="allow" value="always">Always allow</button>
					<?php if ( 'off' !== $perm ) : ?><button class="button-link wpsync-danger" name="allow" value="off">Switch off</button><?php endif; ?>
				</form>
				<?php endif; ?>
			</div>
			<?php self::recent_changes(); ?>
			<p class="description">Changes recorded on this site: <?php echo (int) $count; ?>. Pulling never writes anything to this site; the key only allows reading content.</p>
			<?php if ( $collapsed ) : ?></details><?php endif; ?>
		</div>
		<?php
	}

	/** The last changes this site recorded: lets you check an edit was picked up. */
	private static function recent_changes() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT object_type, sync_id, object_id, action, created_at FROM ' . WPSync_Config::table() . " WHERE source = 'local' ORDER BY id DESC LIMIT 15" );
		echo '<details class="wpsync-recent"><summary><strong>Recent changes recorded on this site</strong></summary>';
		if ( ! $rows ) {
			echo '<p class="wpsync-empty">None yet. Edit something and it will appear here.</p></details>';
			return;
		}
		echo '<table class="widefat striped wpsync-table"><thead><tr><th>When</th><th>Item</th><th>Change</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			if ( 'option' === $r->object_type ) {
				$n    = WPSync_Importer::option_name( $r->sync_id );
				$item = 0 === strpos( $n, 'theme_mods_' ) ? 'Setting: menu locations / logo' : 'Setting: ' . $n;
			} elseif ( 'term' === $r->object_type ) {
				$t    = get_term( (int) $r->object_id );
				$item = ( $t && ! is_wp_error( $t ) ) ? $t->taxonomy . ' "' . $t->name . '"' : 'term #' . $r->object_id;
			} else {
				$p    = get_post( (int) $r->object_id );
				$item = $p ? ( 'nav_menu_item' === $p->post_type ? 'menu item' : $p->post_type ) . ' "' . ( '' !== $p->post_title ? $p->post_title : '#' . $p->ID ) . '"' : 'item #' . $r->object_id . ' (deleted)';
			}
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( human_time_diff( strtotime( $r->created_at . ' UTC' ) ) . ' ago' ), esc_html( $item ), esc_html( 'delete' === $r->action ? 'deleted' : 'added/edited' ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">Not seeing an edit here? Then WP Sync doesn\'t track that kind of change (for example Divi Theme Builder, theme/Customizer options, widgets, or plugin settings) – use <em>Copy everything from live</em> on your local site to bring those across.</p>';
		echo '</details>';
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
			'recording_since' => ! empty( $remote['recording_since'] ) ? wp_date( 'j M Y, H:i', strtotime( $remote['recording_since'] . ' UTC' ) ) : '',
			'never_pulled'    => ! get_option( 'wpsync_last_pull' ) || ! get_option( 'wpsync_full_synced' ),
			'last_summary'    => get_option( 'wpsync_last_summary', null ),
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
				'since'      => ! empty( $_POST['all'] ) ? '1970-01-01' : ( isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '' ),
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

	public static function ajax_full() {
		self::guard();
		$tkey = 'wpsync_full_' . get_current_user_id();
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'cancel' === $step ) {
			delete_transient( $tkey );
			WPSync_Full::request_cancel(); // The running step stops itself and cleans up.
			wp_send_json_success( array( 'message' => 'Cancelled. Nothing on this site was changed.' ) );
		}
		if ( 'start' === $step ) {
			delete_transient( $tkey );
			$state = WPSync_Full::start( array( 'files' => ! empty( $_POST['files'] ) ) );
		} else {
			$state = get_transient( $tkey );
			if ( ! $state ) {
				wp_send_json_error( array( 'message' => 'This copy session expired. Start again (nothing was changed).' ) );
			}
		}
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message(), 'code' => $state->get_error_code() ) );
		}
		$login = wp_login_url( self::url() );
		$state = WPSync_Full::step( $state );
		if ( is_wp_error( $state ) ) {
			delete_transient( $tkey );
			wp_send_json_error( array( 'message' => $state->get_error_message(), 'code' => $state->get_error_code() ) );
		}
		if ( ! $state['done'] ) {
			set_transient( $tkey, $state, HOUR_IN_SECONDS );
		}
		$pct = 0;
		if ( 'tables' === $state['phase'] && $state['rows_total'] ) {
			$pct = 5 + 70 * min( 1, $state['rows_done'] / $state['rows_total'] );
		} elseif ( 'files' === $state['phase'] ) {
			$pct = 75 + 23 * ( $state['files_total'] ? min( 1, $state['files_done'] / $state['files_total'] ) : 0 );
		} elseif ( $state['done'] ) {
			$pct = 100;
		}
		wp_send_json_success( array(
			'done'      => $state['done'],
			'activity'  => $state['activity'],
			'pct'       => round( $pct ),
			'tables'    => count( $state['tables'] ),
			'rows'      => $state['rows_done'],
			'files_got' => $state['files_got'],
			'errors'    => array_slice( $state['errors'], 0, 20 ),
			'backup'    => $state['backup_file'] ? basename( $state['backup_file'] ) : '',
			'notices'   => ! empty( $state['notices'] ) && 'start' === $step ? $state['notices'] : array(),
			'login'     => $login,
		) );
	}

	public static function ajax_restore() {
		self::guard();
		$file = WPSync_Backup::find( isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '' );
		if ( ! $file ) {
			wp_send_json_error( array( 'message' => 'Backup not found.' ) );
		}
		if ( get_transient( WPSync_Puller::LOCK ) ) {
			wp_send_json_error( array( 'message' => 'A pull is running. Cancel it first.' ) );
		}
		// Safety net: back up the current state first, so a restore can itself be undone.
		$now = WPSync_Backup::create();
		if ( is_wp_error( $now ) ) {
			wp_send_json_error( array( 'message' => 'Could not back up the current state first: ' . $now->get_error_message() ) );
		}
		$res = WPSync_Backup::restore( $file );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array(
			'message'  => 'Restored. This site is back to how it was before that pull.',
			'redirect' => self::url( array( 'restored' => 1 ) ),
		) );
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

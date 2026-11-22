<?php
defined( 'ABSPATH' ) || exit;

// LivePull's job is moving content between databases, so it reads and writes tables directly
// (always through $wpdb with prepared values). Results are never cached on purpose: each read
// must see the database as it is right now.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * Tools → LivePull screen.
 *
 * Live site:  shows the URL + key to paste into the local site.
 * Local site: connect to live, preview, pull, resolve conflicts, see local changes.
 */
final class LivePull_Admin {

	const SLUG = 'livepull';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// Finish a "Copy everything" whose last request ended while moving files into place.
		add_action( 'admin_init', array( __CLASS__, 'resume_copy' ) );
		add_action( 'admin_post_livepull_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_livepull_schedule', array( __CLASS__, 'save_schedule' ) );
		foreach ( array( 'status', 'pull', 'resolve', 'local_changes', 'cancel', 'restore', 'full', 'forget_held', 'compare', 'health', 'diagnostics' ) as $a ) {
			add_action( 'wp_ajax_livepull_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/** Echo translated text that contains simple formatting (bold, italics, code, links). */
	private static function kses_e( $html ) {
		echo wp_kses( $html, array( 'strong' => array(), 'em' => array(), 'code' => array(), 'br' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) );
	}

	public static function resume_copy() {
		if ( current_user_can( self::CAP ) && LivePull_Full::placing() ) {
			LivePull_Full::resume_placing();
		}
	}

	public static function menu() {
		$hook = add_management_page( 'LivePull', 'LivePull', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	public static function assets() {
		// Works whether installed as a normal plugin or a must-use plugin.
		$base = plugins_url( 'assets/', LIVEPULL_FILE );
		wp_enqueue_style( 'livepull-admin', $base . 'admin.css', array(), LivePull_Config::VERSION );
		wp_enqueue_script( 'livepull-admin', $base . 'admin.js', array( 'wp-i18n' ), LivePull_Config::VERSION, true );
		wp_set_script_translations( 'livepull-admin', 'livepull' );
		wp_add_inline_script( 'livepull-admin', self::noscript_note(), 'before' );
		wp_localize_script( 'livepull-admin', 'LIVEPULL', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'livepull' ),
			'configured' => (bool) LivePull_Config::remote(),
		) );
	}

	/** If the script fails to load, say so instead of spinning forever (printed before it, so it runs either way). */
	private static function noscript_note() {
		return 'window.addEventListener("load",function(){if(!window.LIVEPULL_LOADED){var s=document.getElementById("livepull-status");if(s&&s.classList.contains("is-loading")){s.className="livepull-statusline is-error";var t=s.querySelector(".livepull-status-text")||s;t.textContent=' . wp_json_encode( __( 'The LivePull script did not load (check the plugin files are complete, and that no optimisation plugin is blocking assets/admin.js).', 'livepull' ) ) . ';}}});';
	}

	/* ================================================================ Save */

	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'livepull' ) );
		}
		check_admin_referer( 'livepull_save' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = 'saved';

		if ( 'connect' === $do && ! defined( 'LIVEPULL_REMOTE' ) ) {
			$url  = isset( $_POST['remote'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['remote'] ) ) ) ) : '';
			$key  = isset( $_POST['remote_key'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['remote_key'] ) ) ) : '';
			$code = isset( $_POST['code'] ) ? self::parse_code( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : null;
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
			update_option( 'livepull_remote', untrailingslashit( $url ), false );
			update_option( 'livepull_role', 'local', false );
			if ( '' !== $key && ! defined( 'LIVEPULL_REMOTE_SECRET' ) ) {
				update_option( 'livepull_remote_secret', $key, false );
			}
			$msg = 'connected';
		} elseif ( 'disconnect' === $do && ! defined( 'LIVEPULL_REMOTE' ) ) {
			delete_option( 'livepull_remote' );
			delete_option( 'livepull_remote_secret' );
			$msg = 'disconnected';
		} elseif ( 'fullcopy' === $do && ! defined( 'LIVEPULL_ALLOW_FULL_COPY' ) ) {
			$allow = isset( $_POST['allow'] ) ? sanitize_key( wp_unslash( $_POST['allow'] ) ) : 'off';
			update_option( 'livepull_allow_full_copy', 'hour' === $allow ? (string) ( time() + HOUR_IN_SECONDS ) : ( 'always' === $allow ? 'always' : 'no' ), false );
			$msg = 'saved';
		} elseif ( 'role' === $do ) {
			$role = isset( $_POST['role'] ) && 'live' === $_POST['role'] ? 'live' : 'local';
			update_option( 'livepull_role', $role, false );
			if ( 'live' === $role && strlen( LivePull_Config::secret() ) < 32 && ! defined( 'LIVEPULL_SECRET' ) ) {
				update_option( 'livepull_secret', bin2hex( random_bytes( 32 ) ), false );
			}
			$msg = '';
		} elseif ( 'groups' === $do ) {
			$on = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['groups'] ) ) : array();
			LivePull_Config::save_groups( $on );
			wp_safe_redirect( self::url( array( 'tab' => 'settings', 'msg' => 'groups' ) ) );
			exit;
		} elseif ( 'generate' === $do && ! defined( 'LIVEPULL_SECRET' ) ) {
			update_option( 'livepull_secret', bin2hex( random_bytes( 32 ) ), false );
			$msg = 'key';
		}
		wp_safe_redirect( self::url( $msg ? array( 'msg' => $msg ) : array() ) );
		exit;
	}

	/** A connection code carries the live URL and key in one paste: "livepull1:" + base64(JSON). */
	public static function make_code() {
		return 'livepull1:' . base64_encode( wp_json_encode( array( 'u' => home_url(), 'k' => LivePull_Config::secret() ) ) ); // phpcs:ignore
	}

	/** @return array{0:string,1:string}|null [ url, key ] */
	public static function parse_code( $code ) {
		$code = trim( (string) $code );
		// "wpsync1:" codes come from the plugin's earlier name (WP Sync).
		if ( ! preg_match( '/^(?:livepull1|wpsync1):(.+)$/s', $code, $m ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( $m[1], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the connection code the user pasted.
		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['k'] ) || ! is_string( $data['u'] ) || ! is_string( $data['k'] ) || strlen( $data['k'] ) < 32 ) {
			return null;
		}
		$url = esc_url_raw( $data['u'] );
		return $url ? array( untrailingslashit( $url ), preg_replace( '/\s+/', '', $data['k'] ) ) : null;
	}

	/* ============================================================== Render */

	/** Which tab to open first (from the address, e.g. after saving settings). */
	private static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which tab is open.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pull';
		return in_array( $tab, array( 'pull', 'conflicts', 'history', 'copy', 'backups', 'settings' ), true ) ? $tab : 'pull';
	}

	/** "live", "local" or "" (not chosen yet). A site connected to live is always local. */
	public static function role() {
		if ( LivePull_Config::remote() ) {
			return 'local';
		}
		$r = get_option( 'livepull_role', '' );
		return in_array( $r, array( 'live', 'local' ), true ) ? $r : '';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$role = self::role();
		$msgs = array(
			'connected'    => __( 'Connected. Checking live…', 'livepull' ),
			'disconnected' => __( 'Disconnected from live.', 'livepull' ),
			'key'          => __( 'New key made. Copy the new connection code into your local site.', 'livepull' ),
			'restored'     => __( 'Backup restored. You may need to log in again if you were logged out.', 'livepull' ),
			'saved'        => __( 'Saved.', 'livepull' ),
			'schedule'     => __( 'Schedule saved.', 'livepull' ),
			'groups'       => __( 'Saved. From now on, pulls follow these choices. Anything you just switched on that already exists on live comes across with More options → "Compare all content with live" on your next pull.', 'livepull' ),
			'badcode'      => __( 'That connection code isn\'t valid. Copy it again from Tools → LivePull on the live site (use the Copy button).', 'livepull' ),
			'nourl'        => __( 'Paste the connection code from the live site, or fill in its URL and key.', 'livepull' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which fixed message to show after a redirect.
		$msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ( isset( $_GET['restored'] ) ? 'restored' : '' );
		?>
		<div class="wrap livepull">
			<h1><?php esc_html_e( 'LivePull', 'livepull' ); ?> <span class="livepull-version">v<?php echo esc_html( LivePull_Config::VERSION ); ?></span>
				<?php do_action( 'livepull_admin_title_actions' ); ?>
			</h1>
			<?php do_action( 'livepull_admin_notices' ); ?>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo in_array( $msg, array( 'badcode', 'nourl' ), true ) ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$copies = array();
			foreach ( get_plugins() as $file => $data ) {
				if ( isset( $data['Name'] ) && 0 === strpos( $data['Name'], 'LivePull' ) ) {
					$copies[ $file ] = $data['Version'];
				}
			}
			if ( count( $copies ) > 1 ) :
				?>
				<div class="notice notice-warning"><p><?php self::kses_e( sprintf( /* translators: 1: list of copies, 2: folder name, 3: Plugins screen URL */ __( '<strong>LivePull is installed more than once</strong> (%1$s). Only the copy in <code>%2$s</code> is running. Delete the others on the <a href="%3$s">Plugins</a> screen (deactivate first) so updates go to the right one.', 'livepull' ), esc_html( implode( ', ', array_map( function ( $f, $v ) { return dirname( $f ) . ' v' . $v; }, array_keys( $copies ), $copies ) ) ), esc_html( dirname( plugin_basename( LIVEPULL_FILE ) ) ), esc_url( admin_url( 'plugins.php' ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php
			$place_errors = get_option( 'livepull_full_place_errors' );
			if ( $place_errors ) :
				delete_option( 'livepull_full_place_errors' );
				?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Copy everything finished, with problems moving some files:', 'livepull' ); ?></strong> <?php echo esc_html( implode( ' ', array_slice( (array) $place_errors, -3 ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Multisite networks are not supported yet.', 'livepull' ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( 'local' === $role && LivePull_Config::remote() ) {
				self::render_local();
			} elseif ( 'local' === $role ) {
				self::render_connect();
			} elseif ( 'live' === $role ) {
				self::render_live();
			} else {
				self::render_choose();
			}
			?>
		</div>
		<?php
	}

	/** A small form that switches which kind of site this is. */
	private static function role_button( $role, $label, $class = 'button-link' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="livepull-inline">
			<?php wp_nonce_field( 'livepull_save' ); ?>
			<input type="hidden" name="action" value="livepull_save">
			<input type="hidden" name="do" value="role">
			<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>">
			<button class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/** First visit: one question. */
	private static function render_choose() {
		?>
		<p class="livepull-lead"><?php esc_html_e( 'LivePull brings the changes your client makes on their live site into your local or staging copy, without overwriting your own work.', 'livepull' ); ?></p>
		<h2 class="livepull-question"><?php esc_html_e( 'Which site is this?', 'livepull' ); ?></h2>
		<div class="livepull-grid livepull-choices">
			<div class="livepull-card livepull-choice">
				<h3><?php esc_html_e( 'The live site', 'livepull' ); ?></h3>
				<p><?php esc_html_e( 'The real website your client edits. LivePull quietly notes what changes here so your copy can fetch it. Your content and settings here are never changed.', 'livepull' ); ?></p>
				<?php self::role_button( 'live', __( 'This is the live site', 'livepull' ), 'button button-primary button-hero' ); ?>
			</div>
			<div class="livepull-card livepull-choice">
				<h3><?php esc_html_e( 'My local or staging copy', 'livepull' ); ?></h3>
				<p><?php esc_html_e( 'Where you develop. You\'ll connect it to the live site once, then pull changes whenever you like.', 'livepull' ); ?></p>
				<?php self::role_button( 'local', __( 'This is my copy', 'livepull' ), 'button button-primary button-hero' ); ?>
			</div>
		</div>
		<?php
	}

	/** Local copy, not connected yet: three steps. */
	private static function render_connect() {
		?>
		<div class="livepull-card livepull-setup">
			<h2><?php esc_html_e( 'Connect this copy to the live site', 'livepull' ); ?></h2>
			<ol class="livepull-steps">
				<li><?php self::kses_e( __( 'Install and activate LivePull on the <strong>live</strong> site.', 'livepull' ) ); ?></li>
				<li><?php self::kses_e( __( 'There, open <strong>Tools → LivePull</strong>, choose <em>This is the live site</em> and press <strong>Copy</strong> next to the connection code.', 'livepull' ) ); ?></li>
				<li><?php esc_html_e( 'Paste it here:', 'livepull' ); ?></li>
			</ol>
			<?php self::connect_form(); ?>
			<p class="livepull-switch"><?php self::role_button( 'live', __( 'Wrong one? This is actually the live site', 'livepull' ) ); ?></p>
		</div>
		<?php
	}

	/** Live site: only what's needed here. */
	private static function render_live() {
		global $wpdb;
		$secret = LivePull_Config::secret();
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = 'local'", LivePull_Config::table() ) );
		?>
		<div class="livepull-card livepull-setup">
			<h2><?php esc_html_e( 'This is the live site', 'livepull' ); ?></h2>
			<p class="livepull-statusline is-ok"><span class="livepull-dot"></span> <?php echo esc_html( sprintf( /* translators: %d: number of changes */ _n( 'Noting changes as they happen: %d recorded so far.', 'Noting changes as they happen: %d recorded so far.', $count, 'livepull' ), $count ) ); ?></p>
			<?php if ( strlen( $secret ) >= 32 ) : ?>
				<p><?php self::kses_e( __( 'Copy this code into <strong>Tools → LivePull</strong> on your local or staging copy. That\'s all.', 'livepull' ) ); ?></p>
				<p class="livepull-copy livepull-copy-big"><input type="password" readonly class="large-text code" value="<?php echo esc_attr( self::make_code() ); ?>" aria-label="<?php esc_attr_e( 'Connection code', 'livepull' ); ?>"><button type="button" class="button livepull-reveal"><?php esc_html_e( 'Show', 'livepull' ); ?></button><button type="button" class="button button-primary livepull-copy-btn"><?php esc_html_e( 'Copy', 'livepull' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'It contains this site\'s address and a key that only lets a site read content. Treat it like a password.', 'livepull' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'First, make a key for this site.', 'livepull' ); ?></p>
			<?php endif; ?>
			<p class="livepull-switch"><?php self::role_button( 'local', __( 'Wrong one? This is my local copy', 'livepull' ) ); ?></p>
		</div>
		<?php
		self::render_source( true, __( 'Key, full copies and recent changes', 'livepull' ) );
	}

	/** Local copy connected to live: status line, then tabs. */
	private static function render_local() {
		$tab       = self::current_tab();
		$conflicts = LivePull_Puller::conflicts();
		$history   = LivePull_History::all();
		$tabs      = array(
			'pull'      => __( 'Pull', 'livepull' ),
			'conflicts' => __( 'Conflicts', 'livepull' ),
			'history'   => __( 'History', 'livepull' ),
			'copy'      => __( 'Copy everything', 'livepull' ),
			'backups'   => __( 'Backups', 'livepull' ),
			'settings'  => __( 'Settings', 'livepull' ),
		);
		$host = preg_replace( '#^https?://#', '', LivePull_Config::remote() );
		?>
		<div class="livepull-topline">
			<p id="livepull-status" class="livepull-statusline is-loading"><span class="livepull-dot"></span>
				<span class="livepull-status-text"><?php echo esc_html( sprintf( /* translators: %s: live site's address */ __( 'Checking %s…', 'livepull' ), $host ) ); ?></span>
				<button type="button" class="button button-small livepull-fixit" hidden><?php esc_html_e( 'Fix it', 'livepull' ); ?></button>
			</p>
			<div id="livepull-health" class="livepull-health" hidden></div>
		</div>

		<nav class="nav-tab-wrapper livepull-tabs" role="tablist">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a href="<?php echo esc_url( self::url( array( 'tab' => $id ) ) ); ?>" class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $id ); ?>" role="tab" aria-selected="<?php echo $tab === $id ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?><?php if ( 'conflicts' === $id ) : ?> <span class="livepull-badge" id="livepull-conflict-count" <?php echo $conflicts ? '' : 'hidden'; ?>><?php echo (int) count( $conflicts ); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>

		<?php // ---------------------------------------------------------------- Pull ?>
		<section class="livepull-panel" data-panel="pull" <?php echo 'pull' === $tab ? '' : 'hidden'; ?>>
			<?php if ( ! $history && ! get_option( 'livepull_last_pull' ) ) : ?>
				<div class="livepull-firstrun">
					<strong><?php esc_html_e( 'You\'re connected. Here\'s how it works:', 'livepull' ); ?></strong>
					<ol>
						<li><?php self::kses_e( __( '<strong>Check for changes</strong> shows everything that changed on live, with a tick box for each.', 'livepull' ) ); ?></li>
						<li><?php self::kses_e( __( 'Untick anything you don\'t want yet, then <strong>Pull</strong>. A backup is taken first, so you can always undo.', 'livepull' ) ); ?></li>
						<li><?php self::kses_e( __( 'If something changed on live <em>and</em> here, your version is kept and it\'s listed under <strong>Conflicts</strong> for you to decide.', 'livepull' ) ); ?></li>
					</ol>
					<p id="livepull-recording" class="description" hidden></p>
				</div>
			<?php else : ?>
				<p id="livepull-recording" class="description" hidden></p>
			<?php endif; ?>
			<div id="livepull-notices"></div>

			<div class="livepull-actions">
				<button type="button" class="button button-primary button-hero" id="livepull-preview" disabled><?php esc_html_e( 'Check for changes', 'livepull' ); ?></button>
				<button type="button" class="button-link" id="livepull-pull" disabled><?php esc_html_e( 'or pull everything now without checking', 'livepull' ); ?></button>
				<button type="button" class="button-link livepull-danger" id="livepull-cancel" hidden><?php esc_html_e( 'Stop', 'livepull' ); ?></button>
			</div>

			<div id="livepull-progress" class="livepull-progress" hidden>
				<div class="livepull-bar"><span></span></div>
				<p class="livepull-progress-text"></p>
			</div>
			<div id="livepull-result"></div>

			<details class="livepull-advanced">
				<summary><?php esc_html_e( 'More options', 'livepull' ); ?></summary>
				<p>
					<label><input type="checkbox" id="livepull-all"> <?php self::kses_e( __( '<strong>Compare all content with live</strong> (first sync)', 'livepull' ) ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Looks at every post, page, image and category on live, not just changes noted since LivePull was installed there. Your local edits are still kept.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><strong><?php esc_html_e( 'Or catch up from a date', 'livepull' ); ?></strong><br>
					<input type="date" id="livepull-since"></label><br>
					<span class="description"><?php esc_html_e( 'Everything changed on live since that date, for example the day you made this copy.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" id="livepull-theirs"> <?php esc_html_e( 'Let live win every conflict', 'livepull' ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Overwrites your local edits wherever live changed the same thing.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" id="livepull-redownload"> <?php esc_html_e( 'Download media files again even if they\'re already here', 'livepull' ); ?></label>
				</p>
			</details>

			<details class="livepull-advanced livepull-what">
				<summary><?php esc_html_e( 'What does a pull bring across?', 'livepull' ); ?></summary>
				<?php self::what_gets_pulled(); ?>
			</details>
		</section>

		<?php // ----------------------------------------------------------- Conflicts ?>
		<section class="livepull-panel" data-panel="conflicts" <?php echo 'conflicts' === $tab ? '' : 'hidden'; ?>>
			<p class="description"><?php self::kses_e( __( 'These changed on live <em>and</em> here since the last pull. Your version stays until you choose. <strong>Compare</strong> shows the differences side by side.', 'livepull' ) ); ?></p>
			<div id="livepull-conflicts"><?php self::conflicts_table( $conflicts ); ?></div>
			<h3><?php esc_html_e( 'Changed here since the last pull', 'livepull' ); ?></h3>
			<p class="description"><?php esc_html_e( 'If live changes any of these too, you\'ll get a conflict instead of an overwrite.', 'livepull' ); ?></p>
			<div id="livepull-local" data-lazy="1"><p><span class="spinner is-active"></span></p></div>
		</section>

		<?php // ------------------------------------------------------------- History ?>
		<section class="livepull-panel" data-panel="history" <?php echo 'history' === $tab ? '' : 'hidden'; ?>>
			<div id="livepull-history"><?php self::history_table( $history ); ?></div>
		</section>

		<?php // ----------------------------------------------------- Copy everything ?>
		<section class="livepull-panel" data-panel="copy" <?php echo 'copy' === $tab ? '' : 'hidden'; ?>>
			<h2><?php esc_html_e( 'Copy everything from live', 'livepull' ); ?></h2>
			<p><?php self::kses_e( sprintf( /* translators: %s: this site's address */ __( 'Replaces this site\'s <strong>whole database</strong> with a copy of live: posts, pages, menus, settings, Divi Theme Builder and Library, widgets, users and plugin data. Live URLs are switched to <code>%s</code>. Use it to start fresh, or when this copy has drifted too far from live.', 'livepull' ), esc_html( home_url() ) ) ); ?></p>
				<ul class="livepull-bullets">
					<li><?php self::kses_e( __( 'Your current database is backed up first (restore it any time from the <em>Backups</em> tab).', 'livepull' ) ); ?></li>
					<li><?php esc_html_e( 'Nothing here changes until the very end, so you can cancel safely while it runs.', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Kept as they are here: this site\'s address, its LivePull connection and "Discourage search engines".', 'livepull' ); ?></li>
					<li><?php self::kses_e( __( 'Theme and plugin <em>files</em> aren\'t copied – keep those in Git.', 'livepull' ) ); ?></li>
					<li><?php self::kses_e( __( 'Afterwards, log in with your <strong>live site\'s</strong> username and password.', 'livepull' ) ); ?></li>
				</ul>
				<p><label><input type="checkbox" id="livepull-full-files" checked> <?php esc_html_e( 'Also copy the uploads folder (images and other files; only missing or changed files are downloaded)', 'livepull' ); ?></label></p>
				<p><button type="button" class="button" id="livepull-full-start"><?php esc_html_e( 'Copy everything from live…', 'livepull' ); ?></button>
				<button type="button" class="button-link livepull-danger" id="livepull-full-cancel" hidden><?php esc_html_e( 'Cancel', 'livepull' ); ?></button></p>
				<div id="livepull-full-progress" class="livepull-progress" hidden>
					<div class="livepull-bar"><span></span></div>
					<p class="livepull-progress-text"></p>
				</div>
				<div id="livepull-full-result"></div>
		</section>

		<?php // ------------------------------------------------------------- Backups ?>
		<section class="livepull-panel" data-panel="backups" <?php echo 'backups' === $tab ? '' : 'hidden'; ?>>
			<?php self::backups_table(); ?>
		</section>

		<?php // ------------------------------------------------------------ Settings ?>
		<section class="livepull-panel" data-panel="settings" <?php echo 'settings' === $tab ? '' : 'hidden'; ?>>
			<?php self::render_settings(); ?>
		</section>
		<?php
	}

	private static function what_gets_pulled() {
		$groups = LivePull_Config::groups();
		$defs   = LivePull_Config::optional_groups();
		?>
		<div class="livepull-what-grid">
			<div><strong><?php esc_html_e( 'Every pull', 'livepull' ); ?></strong>
				<ul class="livepull-bullets">
					<li><?php esc_html_e( 'Posts, pages and custom post types (including WooCommerce products)', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Media library and its files', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Categories, tags and other taxonomies', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Custom fields (ACF etc.) and page-builder content', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Menus and menu locations', 'livepull' ); ?><?php echo LivePull_Config::pull_menus() ? '' : ' <em>' . esc_html__( '(switched off on this site)', 'livepull' ) . '</em>'; ?></li>
					<li><?php esc_html_e( 'Site title, tagline, homepage, posts page, sticky posts, site icon, logo', 'livepull' ); ?></li>
					<?php foreach ( $groups as $g => $on ) : ?>
						<?php if ( $on ) : ?><li><?php echo esc_html( $defs[ $g ]['label'] ); ?></li><?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<div>
				<?php if ( in_array( false, $groups, true ) ) : ?>
				<strong><?php esc_html_e( 'Switched off (Settings tab)', 'livepull' ); ?></strong>
				<ul class="livepull-bullets">
					<?php foreach ( $groups as $g => $on ) : ?>
						<?php if ( ! $on ) : ?><li><?php echo esc_html( $defs[ $g ]['label'] ); ?></li><?php endif; ?>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<strong><?php esc_html_e( 'Only with Copy everything', 'livepull' ); ?></strong>
				<ul class="livepull-bullets">
					<li><?php esc_html_e( 'Theme, Customizer and Divi theme options', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Widgets and other plugin settings', 'livepull' ); ?></li>
					<li><?php esc_html_e( 'Users', 'livepull' ); ?></li>
				</ul>
				<strong><?php esc_html_e( 'Never', 'livepull' ); ?></strong>
				<ul class="livepull-bullets"><li><?php esc_html_e( 'WooCommerce orders and customers\' sessions', 'livepull' ); ?></li><li><?php esc_html_e( 'Theme and plugin files (keep those in Git)', 'livepull' ); ?></li></ul>
			</div>
		</div>
		<?php
	}

	public static function history_table( array $history ) {
		if ( ! $history ) {
			echo '<p class="livepull-empty">' . esc_html__( 'Nothing yet. Every pull, scheduled pull, full copy and restore will be listed here.', 'livepull' ) . '</p>';
			return;
		}
		$kinds   = array(
			'pull'    => __( 'Pull', 'livepull' ),
			'copy'    => __( 'Copy everything', 'livepull' ),
			'restore' => __( 'Restored a backup', 'livepull' ),
		);
		$sources = array(
			'scheduled' => __( 'scheduled', 'livepull' ),
			'cli'       => __( 'from the terminal', 'livepull' ),
		);
		$lists   = array(
			'created'   => __( 'New', 'livepull' ),
			'updated'   => __( 'Updated', 'livepull' ),
			'deleted'   => __( 'Deleted', 'livepull' ),
			'conflicts' => __( 'Conflicts', 'livepull' ),
			'held'      => __( 'Held back', 'livepull' ),
			'skipped'   => __( 'Skipped', 'livepull' ),
			'errors'    => __( 'Errors', 'livepull' ),
		);
		echo '<table class="widefat striped livepull-table livepull-history"><thead><tr><th>' . esc_html__( 'When', 'livepull' ) . '</th><th>' . esc_html__( 'What', 'livepull' ) . '</th><th>' . esc_html__( 'Result', 'livepull' ) . '</th></tr></thead><tbody>';
		foreach ( $history as $e ) {
			$what = isset( $kinds[ $e['kind'] ] ) ? $kinds[ $e['kind'] ] : $e['kind'];
			if ( isset( $sources[ $e['source'] ] ) ) {
				$what .= ' (' . $sources[ $e['source'] ] . ')';
			}
			$summary = LivePull_History::summary( $e );
			$details = '';
			foreach ( $lists as $k => $label ) {
				if ( ! empty( $e[ $k ] ) ) {
					$more     = $e[ $k . '_count' ] > count( $e[ $k ] ) ? '<li>' . esc_html( sprintf( /* translators: %d: number of further items */ __( '…and %d more', 'livepull' ), $e[ $k . '_count' ] - count( $e[ $k ] ) ) ) . '</li>' : '';
					$details .= '<p><strong>' . esc_html( $label ) . '</strong></p><ul class="livepull-bullets"><li>' . implode( '</li><li>', array_map( 'esc_html', $e[ $k ] ) ) . '</li>' . $more . '</ul>';
				}
			}
			printf(
				'<tr><td>%1$s<br><span class="description">%2$s</span></td><td>%3$s%4$s</td><td>%5$s</td></tr>',
				esc_html( wp_date( 'D j M, H:i', $e['time'] ) ),
				esc_html( sprintf( /* translators: %s: time span, e.g. "5 mins" */ __( '%s ago', 'livepull' ), human_time_diff( $e['time'] ) ) ) . ( $e['user'] ? ' · ' . esc_html( $e['user'] ) : '' ),
				esc_html( $what ),
				$e['note'] ? '<br><span class="description">' . esc_html( $e['note'] ) . '</span>' : '',
				$details ? '<details><summary>' . esc_html( $summary ) . '</summary>' . $details . '</details>' : esc_html( $summary ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above.
			);
		}
		echo '</tbody></table>';
	}

	private static function backups_table() {
		$backups = LivePull_Backup::all();
		$latest  = LivePull_Backup::latest();
		?>
		<p class="description"><?php esc_html_e( 'A backup is taken before every pull and every full copy. Restoring one puts this site\'s posts, pages, media library, categories and settings back exactly as they were (and its users too, for a backup taken before a full copy). Downloaded media files stay in the uploads folder.', 'livepull' ); ?></p>
		<?php if ( ! $backups ) : ?>
			<p class="livepull-empty"><?php esc_html_e( 'No backups yet.', 'livepull' ); ?></p>
		<?php else : ?>
		<table class="widefat striped livepull-table"><thead><tr><th><?php esc_html_e( 'Backup taken', 'livepull' ); ?></th><th><?php esc_html_e( 'Size', 'livepull' ); ?></th><th></th></tr></thead><tbody>
		<?php foreach ( array_slice( $backups, 0, 10 ) as $b ) : ?>
			<tr>
				<td><?php echo esc_html( wp_date( 'D j M Y, H:i:s', $b['time'] ) ); ?> <span class="description">(<?php echo esc_html( sprintf( 'copy' === $b['kind'] ? /* translators: %s: time span, e.g. "5 mins" */ __( '%s ago, before a full copy', 'livepull' ) : /* translators: %s: time span, e.g. "5 mins" */ __( '%s ago, before a pull', 'livepull' ), human_time_diff( $b['time'] ) ) ); ?>)</span></td>
				<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
				<td class="livepull-row-actions"><button type="button" class="button livepull-restore" data-file="<?php echo esc_attr( $b['name'] ); ?>"><?php esc_html_e( 'Restore', 'livepull' ); ?></button></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
		<details class="livepull-advanced">
			<summary><?php esc_html_e( 'Where backups are kept', 'livepull' ); ?></summary>
			<p class="description"><?php self::kses_e( sprintf( /* translators: 1: backups folder, 2: latest backup note (may be empty) */ __( 'In <code>%1$s</code>%2$s. You can also restore one from the terminal with <code>wp livepull restore</code>.', 'livepull' ), esc_html( LivePull_Backup::dir() ), $latest ? ' (' . sprintf( /* translators: %s: file name */ __( 'latest: <code>%s</code>', 'livepull' ), esc_html( basename( $latest ) ) ) . ')' : '' ) ); ?></p>
			<?php if ( LivePull_Backup::in_web_root() ) : ?>
				<p class="description"><?php self::kses_e( __( 'Backups are kept in a protected LivePull folder in uploads: it has an unguessable name and deny rules, and each backup file stops if it is ever requested over the web, so it can\'t be downloaded. To keep them somewhere else, add <code>define( \'LIVEPULL_BACKUP_DIR\', \'/a/folder/of/your/choice\' );</code> to wp-config.php.', 'livepull' ) ); ?></p>
			<?php endif; ?>
		</details>
		<?php
	}

	private static function render_settings() {
		$sched  = LivePull_Schedule::settings();
		$next   = LivePull_Schedule::next();
		$groups = LivePull_Config::groups();
		$defs   = LivePull_Config::optional_groups();
		?>
		<div class="livepull-settings">
			<div class="livepull-card">
				<h2><?php esc_html_e( 'What to pull', 'livepull' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Content, media, menus and the main site settings always come across. These are up to you:', 'livepull' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'livepull_save' ); ?>
					<input type="hidden" name="action" value="livepull_save">
					<input type="hidden" name="do" value="groups">
					<?php foreach ( $defs as $g => $def ) : ?>
						<p><label><input type="checkbox" name="groups[<?php echo esc_attr( $g ); ?>]" value="1" <?php checked( $groups[ $g ] ); ?>> <?php echo esc_html( $def['label'] ); ?></label></p>
					<?php endforeach; ?>
					<p><button class="button"><?php esc_html_e( 'Save', 'livepull' ); ?></button></p>
				</form>
			</div>

			<div class="livepull-card">
				<h2><?php esc_html_e( 'Pull automatically', 'livepull' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'livepull_schedule' ); ?>
					<input type="hidden" name="action" value="livepull_schedule">
					<p><label for="livepull-every"><strong><?php esc_html_e( 'How often', 'livepull' ); ?></strong></label><br>
						<select id="livepull-every" name="every">
							<?php foreach ( LivePull_Schedule::choices() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $sched['every'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></p>
					<p><label><input type="checkbox" name="email" value="1" <?php checked( $sched['email'] ); ?>> <?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'Email %s when something came across or needs a decision', 'livepull' ), get_option( 'admin_email' ) ) ); ?></label></p>
					<p><button class="button"><?php esc_html_e( 'Save', 'livepull' ); ?></button></p>
				</form>
				<p class="description">
					<?php esc_html_e( 'Scheduled pulls work like Pull: a backup first, your edits kept, conflicts listed for you.', 'livepull' ); ?>
					<?php if ( 'off' !== $sched['every'] && ! $next ) : ?>
						<br><strong><?php esc_html_e( 'On, but not scheduled yet: it will be put back the next time this site loads a page. If this stays, press Save again.', 'livepull' ); ?></strong>
					<?php endif; ?>
					<?php if ( 'off' !== $sched['every'] && $next ) : ?>
						<br><?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Next one: %s.', 'livepull' ), wp_date( 'D j M, H:i', $next ) ) ); ?>
					<?php endif; ?>
					<?php if ( $sched['last_run'] ) : ?>
						<br><?php echo esc_html( sprintf( /* translators: 1: time span, 2: result */ __( 'Last one: %1$s ago (%2$s).', 'livepull' ), human_time_diff( (int) $sched['last_run'] ), $sched['last_result'] ) ); ?>
					<?php endif; ?>
					<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
						<br><strong><?php esc_html_e( 'WordPress\'s scheduler is switched off on this site (DISABLE_WP_CRON), so scheduled pulls only run if a server cron job calls wp-cron.php.', 'livepull' ); ?></strong>
					<?php endif; ?>
				</p>
			</div>

			<div class="livepull-card">
				<h2><?php esc_html_e( 'Connection', 'livepull' ); ?></h2>
				<p><button type="button" class="button livepull-run-health"><?php esc_html_e( 'Check the connection', 'livepull' ); ?></button></p>
				<div class="livepull-health-here"></div>
				<details id="livepull-connection" class="livepull-advanced">
					<summary><?php esc_html_e( 'Change the live site or key', 'livepull' ); ?></summary>
					<?php self::connect_form(); ?>
					<?php if ( ! defined( 'LIVEPULL_REMOTE' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Disconnect from the live site?', 'livepull' ) ) ); ?>);">
						<?php wp_nonce_field( 'livepull_save' ); ?>
						<input type="hidden" name="action" value="livepull_save">
						<input type="hidden" name="do" value="disconnect">
						<button class="button-link livepull-danger"><?php esc_html_e( 'Disconnect', 'livepull' ); ?></button>
					</form>
					<?php endif; ?>
				</details>
			</div>

			<div class="livepull-card">
				<h2><?php esc_html_e( 'Something not working?', 'livepull' ); ?></h2>
				<p><?php esc_html_e( 'Get the details that help fix it (your site set-up and recent pulls; no passwords or keys) to send with your report.', 'livepull' ); ?></p>
				<p><button type="button" class="button" id="livepull-report"><?php esc_html_e( 'Report a problem', 'livepull' ); ?></button></p>
				<div id="livepull-report-box" hidden>
					<textarea readonly class="large-text code livepull-diagnostics" rows="12"></textarea>
					<p><button type="button" class="button livepull-copy-text"><?php esc_html_e( 'Copy the details', 'livepull' ); ?></button>
					<a class="button button-primary" id="livepull-issue" href="#" target="_blank" rel="noopener"><?php echo esc_html( LivePull_Health::from_github() ? __( 'Open a GitHub issue', 'livepull' ) : __( 'Open the support forum', 'livepull' ) ); ?></a></p>
					<?php
					if ( LivePull_Health::from_github() ) {
						/* translators: %s: live site's address */
						$report_note = __( 'The issue opens with these details filled in. They include your live site\'s address (%s) and its plugins. GitHub issues are public, so remove anything you\'d rather not share, then add what you did and what happened.', 'livepull' );
					} else {
						/* translators: %s: live site's address */
						$report_note = __( 'Paste the details into a new topic. They include your live site\'s address (%s) and its plugins. The forum is public, so remove anything you\'d rather not share, then add what you did and what happened.', 'livepull' );
					}
					?>
					<p class="description"><?php echo esc_html( sprintf( $report_note, (string) wp_parse_url( LivePull_Config::remote(), PHP_URL_HOST ) ) ); ?></p>
				</div>
				<p class="description"><?php self::kses_e( __( 'From the terminal: <code>wp livepull doctor</code>, <code>wp livepull report</code>, <code>wp livepull pull --dry-run</code>.', 'livepull' ) ); ?></p>
			</div>
		</div>
		<?php
		self::render_source( true );
	}

	private static function connect_form() {
		$remote_const = defined( 'LIVEPULL_REMOTE' );
		$key_const    = defined( 'LIVEPULL_REMOTE_SECRET' );
		list( $cur_key, $cur_from ) = LivePull_Config::remote_secret_source();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="livepull-form">
			<?php wp_nonce_field( 'livepull_save' ); ?>
			<input type="hidden" name="action" value="livepull_save">
			<input type="hidden" name="do" value="connect">
			<?php if ( ! $remote_const && ! $key_const ) : ?>
			<p>
				<label for="livepull-code"><strong><?php esc_html_e( 'Connection code', 'livepull' ); ?></strong></label><br>
				<input type="text" id="livepull-code" name="code" class="large-text code" autocomplete="off" placeholder="<?php esc_attr_e( 'livepull1:… – copy it from Tools → LivePull on the live site', 'livepull' ); ?>">
				<br><span class="description"><?php esc_html_e( 'Paste this and you\'re done. Or fill in the two fields below instead.', 'livepull' ); ?></span>
			</p>
			<?php endif; ?>
			<p>
				<label for="livepull-remote"><strong><?php esc_html_e( 'Live site URL', 'livepull' ); ?></strong></label><br>
				<input type="url" id="livepull-remote" name="remote" class="regular-text" placeholder="https://www.example.co.uk"
					value="<?php echo esc_attr( LivePull_Config::remote() ); ?>" <?php disabled( $remote_const ); ?>>
				<?php if ( $remote_const ) : ?><br><span class="description"><?php esc_html_e( 'Set in wp-config.php (LIVEPULL_REMOTE).', 'livepull' ); ?></span><?php endif; ?>
			</p>
			<p>
				<label for="livepull-key"><strong><?php esc_html_e( 'Live site\'s LivePull key', 'livepull' ); ?></strong></label><br>
				<input type="password" id="livepull-key" name="remote_key" class="regular-text code" autocomplete="off"
					placeholder="<?php echo esc_attr( $cur_key ? __( 'Paste a new key to replace it', 'livepull' ) : __( 'Paste from Tools → LivePull on the live site', 'livepull' ) ); ?>" <?php disabled( $key_const ); ?>>
				<?php if ( $cur_key ) : ?><br><span class="description"><?php esc_html_e( 'Currently using the key ending', 'livepull' ); ?> <code><?php echo esc_html( LivePull_Config::key_hint( $cur_key ) ); ?></code> (<?php echo esc_html( $cur_from ); ?>).</span><?php endif; ?>
			</p>
			<?php if ( ! $remote_const || ! $key_const ) : ?>
				<p><button class="button button-primary"><?php echo esc_html( LivePull_Config::remote() ? __( 'Save', 'livepull' ) : __( 'Save and connect', 'livepull' ) ); ?></button></p>
			<?php endif; ?>
		</form>
		<?php
	}

	public static function conflicts_table( array $conflicts ) {
		if ( ! $conflicts ) {
			echo '<p class="livepull-empty">No conflicts.</p>';
			return;
		}
		printf( '<table class="widefat striped livepull-table"><thead><tr><th>%s</th><th>%s</th><th></th></tr></thead><tbody>', esc_html__( 'Item', 'livepull' ), esc_html__( 'What happened', 'livepull' ) );
		foreach ( $conflicts as $key => $c ) {
			$edit = '';
			if ( ! empty( $c['local_id'] ) ) {
				if ( 0 === strpos( $key, 'term:' ) ) {
					$edit = get_edit_term_link( (int) $c['local_id'] );
				} elseif ( 0 === strpos( $key, 'comment:' ) ) {
					$edit = get_edit_comment_link( (int) $c['local_id'] );
				} elseif ( 0 === strpos( $key, 'post:' ) ) {
					$edit = get_edit_post_link( (int) $c['local_id'], 'raw' );
				}
			}
			printf(
				'<tr data-key="%1$s"><td>%2$s</td><td>%3$s</td><td class="livepull-row-actions"><button type="button" class="button-link livepull-compare-btn">%6$s</button> <button type="button" class="button livepull-take">%4$s</button> <button type="button" class="button-link livepull-keep">%5$s</button></td></tr>',
				esc_attr( $key ),
				$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $c['label'] ) . '</a>' : esc_html( $c['label'] ),
				esc_html( ucfirst( $c['reason'] ) ),
				esc_html__( 'Use live version', 'livepull' ),
				esc_html__( 'Keep mine', 'livepull' ),
				esc_html__( 'Compare', 'livepull' )
			);
		}
		echo '</tbody></table>';
	}

	/** "Let other sites pull from this one" (shown on live, and folded away on local). */
	private static function render_source( $collapsed, $title = '' ) {
		$secret = LivePull_Config::secret();
		$const  = defined( 'LIVEPULL_SECRET' );
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = 'local'", LivePull_Config::table() ) );
		?>
		<div class="livepull-card">
			<?php if ( $collapsed ) : ?><details><summary><h2><?php echo esc_html( $title ? $title : __( 'Let other sites pull from this one', 'livepull' ) ); ?></h2></summary><?php else : ?>
			<h2><?php esc_html_e( 'Is this the live site?', 'livepull' ); ?></h2>
			<p><?php self::kses_e( __( 'Nothing else to set up here. Copy the connection code into <strong>Tools → LivePull</strong> on your local site.', 'livepull' ) ); ?></p>
			<?php endif; ?>

			<?php if ( strlen( $secret ) >= 32 ) : ?>
			<p><label><strong><?php esc_html_e( 'Connection code', 'livepull' ); ?></strong></label><br>
				<span class="livepull-copy"><input type="password" readonly class="large-text code" value="<?php echo esc_attr( self::make_code() ); ?>"><button type="button" class="button livepull-reveal"><?php esc_html_e( 'Show', 'livepull' ); ?></button><button type="button" class="button button-primary livepull-copy-btn"><?php esc_html_e( 'Copy', 'livepull' ); ?></button></span>
				<br><span class="description"><?php esc_html_e( 'Contains this site\'s address and key. Treat it like a password.', 'livepull' ); ?></span></p>
			<details class="livepull-sub"><summary><?php esc_html_e( 'Or copy the address and key separately', 'livepull' ); ?></summary>
			<?php endif; ?>

			<p><label><strong><?php esc_html_e( 'Site URL', 'livepull' ); ?></strong></label><br>
				<span class="livepull-copy"><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( home_url() ); ?>"><button type="button" class="button livepull-copy-btn"><?php esc_html_e( 'Copy', 'livepull' ); ?></button></span></p>

			<p><label><strong><?php esc_html_e( 'LivePull key', 'livepull' ); ?></strong></label><br>
			<?php if ( strlen( $secret ) >= 32 ) : ?>
				<span class="livepull-copy"><input type="password" readonly class="regular-text code livepull-secret" value="<?php echo esc_attr( $secret ); ?>"><button type="button" class="button livepull-reveal"><?php esc_html_e( 'Show', 'livepull' ); ?></button><button type="button" class="button livepull-copy-btn"><?php esc_html_e( 'Copy', 'livepull' ); ?></button></span>
				<br><span class="description"><?php esc_html_e( 'Ends in', 'livepull' ); ?> <code><?php echo esc_html( LivePull_Config::key_hint( $secret ) ); ?></code><?php echo $const ? ' · set in wp-config.php (LIVEPULL_SECRET)' : ''; ?>.</span>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'No key yet.', 'livepull' ); ?></span>
			<?php endif; ?>
			</p>
			<?php if ( strlen( $secret ) >= 32 ) : ?></details><?php endif; ?>

			<?php if ( ! $const ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo strlen( $secret ) >= 32 ? 'onsubmit="return confirm(\'Generate a new key? Local sites using the old key will stop connecting until you paste the new one in.\');"' : ''; ?>>
				<?php wp_nonce_field( 'livepull_save' ); ?>
				<input type="hidden" name="action" value="livepull_save">
				<input type="hidden" name="do" value="generate">
				<button class="button <?php echo strlen( $secret ) >= 32 ? '' : 'button-primary'; ?>"><?php echo esc_html( strlen( $secret ) >= 32 ? __( 'Generate a new key', 'livepull' ) : __( 'Generate key', 'livepull' ) ); ?></button>
			</form>
			<?php endif; ?>

			<?php $perm = LivePull_Full::permission(); ?>
			<div class="livepull-inline-form">
				<strong><?php esc_html_e( 'Copy everything from this site:', 'livepull' ); ?></strong>
				<?php if ( 'always' === $perm ) : ?>
					<span class="livepull-pill is-on"><?php esc_html_e( 'Allowed', 'livepull' ); ?></span>
				<?php elseif ( 'off' === $perm ) : ?>
					<span class="livepull-pill"><?php esc_html_e( 'Off', 'livepull' ); ?></span>
				<?php else : ?>
					<span class="livepull-pill is-on"><?php esc_html_e( 'Allowed for', 'livepull' ); ?> <?php echo esc_html( human_time_diff( time(), time() + (int) $perm ) ); ?></span>
				<?php endif; ?>
				<br><span class="description"><?php esc_html_e( 'Lets a site with the key download this site\'s whole database (including users and plugin settings) and uploads. Normal pulls don\'t need this.', 'livepull' ); ?></span>
				<?php if ( ! defined( 'LIVEPULL_ALLOW_FULL_COPY' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="livepull-perm-buttons">
					<?php wp_nonce_field( 'livepull_save' ); ?>
					<input type="hidden" name="action" value="livepull_save">
					<input type="hidden" name="do" value="fullcopy">
					<button class="button <?php echo 'off' === $perm ? 'button-primary' : ''; ?>" name="allow" value="hour"><?php esc_html_e( 'Allow for 1 hour', 'livepull' ); ?></button>
					<button class="button" name="allow" value="always"><?php esc_html_e( 'Always allow', 'livepull' ); ?></button>
					<?php if ( 'off' !== $perm ) : ?><button class="button-link livepull-danger" name="allow" value="off"><?php esc_html_e( 'Switch off', 'livepull' ); ?></button><?php endif; ?>
				</form>
				<?php endif; ?>
			</div>
			<?php self::recent_changes(); ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of changes */ __( 'Changes recorded on this site: %d. Pulling never changes this site\'s content or settings; the key only allows reading.', 'livepull' ), (int) $count ) ); ?></p>
			<?php if ( $collapsed ) : ?></details><?php endif; ?>
		</div>
		<?php
	}

	/** The last changes this site recorded: lets you check an edit was picked up. */
	private static function recent_changes() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_type, sync_id, object_id, action, created_at FROM %i WHERE source = 'local' ORDER BY id DESC LIMIT 15", LivePull_Config::table() ) );
		echo '<details class="livepull-recent"><summary><strong>' . esc_html__( 'Recent changes recorded on this site', 'livepull' ) . '</strong></summary>';
		if ( ! $rows ) {
			echo '<p class="livepull-empty">' . esc_html__( 'None yet. Edit something and it will appear here.', 'livepull' ) . '</p></details>';
			return;
		}
		printf( '<table class="widefat striped livepull-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>', esc_html__( 'When', 'livepull' ), esc_html__( 'Item', 'livepull' ), esc_html__( 'Change', 'livepull' ) );
		foreach ( $rows as $r ) {
			if ( 'option' === $r->object_type ) {
				$n    = LivePull_Importer::option_name( $r->sync_id );
				$item = 0 === strpos( $n, 'theme_mods_' ) ? __( 'Setting: menu locations / logo', 'livepull' ) : sprintf( /* translators: %s: option name */ __( 'Setting: %s', 'livepull' ), $n );
			} elseif ( 'comment' === $r->object_type ) {
				$cm   = get_comment( (int) $r->object_id );
				$item = $cm ? sprintf( /* translators: 1: commenter, 2: post title */ __( 'comment by %1$s on "%2$s"', 'livepull' ), $cm->comment_author, get_the_title( $cm->comment_post_ID ) ) : sprintf( /* translators: %d: comment ID */ __( 'comment #%d (deleted)', 'livepull' ), $r->object_id );
			} elseif ( 'term' === $r->object_type ) {
				$t    = get_term( (int) $r->object_id );
				$item = ( $t && ! is_wp_error( $t ) ) ? $t->taxonomy . ' "' . $t->name . '"' : sprintf( /* translators: %d: term ID */ __( 'term #%d', 'livepull' ), $r->object_id );
			} else {
				$p    = get_post( (int) $r->object_id );
				$item = $p ? ( 'nav_menu_item' === $p->post_type ? __( 'menu item', 'livepull' ) : $p->post_type ) . ' "' . ( '' !== $p->post_title ? $p->post_title : '#' . $p->ID ) . '"' : sprintf( /* translators: %d: post ID */ __( 'item #%d (deleted)', 'livepull' ), $r->object_id );
			}
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( sprintf( /* translators: %s: time span, e.g. "5 mins" */ __( '%s ago', 'livepull' ), human_time_diff( strtotime( $r->created_at . ' UTC' ) ) ) ), esc_html( $item ), esc_html( 'delete' === $r->action ? __( 'deleted', 'livepull' ) : __( 'added/edited', 'livepull' ) ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">';
		self::kses_e( __( 'Not seeing an edit here? Then LivePull doesn\'t track that kind of change (for example theme/Customizer options, widgets, or plugin settings; templates, forms and comments only when the local site has them switched on) – use <em>Copy everything from live</em> on your local site to bring those across.', 'livepull' ) );
		echo '</p>';
		echo '</details>';
	}

	/* ================================================================ AJAX */

	/** Every AJAX handler calls check_ajax_referer() first, then this capability check. */
	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'livepull' ) ), 403 );
		}
	}

	public static function ajax_status() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$notices = array();
		$remote  = LivePull_Puller::connect( $notices );
		if ( is_wp_error( $remote ) ) {
			wp_send_json_error( array( 'message' => $remote->get_error_message(), 'code' => $remote->get_error_code() ) );
		}
		$cursor = (int) get_option( 'livepull_cursor', 0 );
		$last   = get_option( 'livepull_last_pull' );
		wp_send_json_success( array(
			'live'       => $remote['home'],
			'host'       => preg_replace( '#^https?://#', '', LivePull_Config::remote() ),
			'never'      => ! $last,
			'held'       => count( LivePull_Puller::held() ),
			'waiting'    => max( 0, (int) $remote['last_id'] - $cursor ),
			/* translators: %s: time span, e.g. "5 mins" */
			'last_pull'  => $last ? sprintf( __( '%s ago', 'livepull' ), human_time_diff( strtotime( $last . ' UTC' ) ) ) : '',
			'local'      => count( LivePull_Importer::locally_changed() ),
			'conflicts'  => count( LivePull_Puller::conflicts() ),
			'notices'    => $notices,
			'recording_since' => ! empty( $remote['recording_since'] ) ? wp_date( 'j M Y, H:i', strtotime( $remote['recording_since'] . ' UTC' ) ) : '',
			'never_pulled'    => ! get_option( 'livepull_last_pull' ) || ! get_option( 'livepull_full_synced' ),
			'last_summary'    => get_option( 'livepull_last_summary', null ),
		) );
	}

	public static function ajax_pull() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$user = get_current_user_id();
		$tkey = 'livepull_run_' . $user;
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		if ( 'start' === $step ) {
			delete_transient( $tkey );
			$state = LivePull_Puller::start( array(
				'dry_run'      => ! empty( $_POST['dry'] ),
				'theirs'       => ! empty( $_POST['theirs'] ),
				'redownload'   => ! empty( $_POST['redownload'] ),
				'since'        => ! empty( $_POST['all'] ) ? '1970-01-01' : ( isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '' ),
				'exclude'      => self::posted_keys( 'exclude' ),
				'include_held' => self::posted_keys( 'include_held' ),
			) );
			if ( is_wp_error( $state ) ) {
				wp_send_json_error( array( 'message' => $state->get_error_message(), 'code' => $state->get_error_code() ) );
			}
		} else {
			$state = get_transient( $tkey );
			if ( ! $state ) {
				wp_send_json_error( array( 'message' => __( 'This pull session expired. Start it again; progress so far is saved.', 'livepull' ) ) );
			}
		}

		$state = LivePull_Puller::step( $state );
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
				'held'      => isset( $r['held'] ) ? $r['held'] : array(),
				'files'     => $r['files'],
			),
			'preview'   => $state['done'] && $state['opts']['dry_run'] ? array_values( $state['preview'] ) : array(),
			'held_count' => count( LivePull_Puller::held() ),
			'activity'  => $state['activity'],
			'dl_total'  => (int) $state['dl_total'],
			'dl_done'   => (int) $state['dl_done'],
			'conflicts_html' => $state['done'] ? self::capture( array( __CLASS__, 'conflicts_table' ), LivePull_Puller::conflicts() ) : '',
			'conflict_count' => count( LivePull_Puller::conflicts() ),
			'history_html'   => $state['done'] && ! $state['opts']['dry_run'] ? self::capture( array( __CLASS__, 'history_table' ), LivePull_History::all() ) : '',
		) );
	}

	/** Item keys ("post:abc123…") posted as a JSON list. */
	private static function posted_keys( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- called from ajax_pull() after check_ajax_referer().
		$raw  = isset( $_POST[ $field ] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ), true ) : array();
		$keys = array();
		foreach ( (array) $raw as $k ) {
			if ( is_string( $k ) && preg_match( '/^(post|term|option|comment):[a-f0-9]{32}$/', $k ) ) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	/** Side-by-side view of one conflict. */
	public static function ajax_compare() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$rows = LivePull_Puller::compare( $key );
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}
		$html  = '<div class="livepull-compare">';
		$same  = array();
		foreach ( $rows as $r ) {
			if ( $r['live'] === $r['local'] ) {
				$same[] = $r['label'];
				continue;
			}
			$diff  = wp_text_diff( $r['local'], $r['live'], array( 'title_left' => __( 'This site', 'livepull' ), 'title_right' => __( 'Live', 'livepull' ), 'show_split_view' => true ) );
			$html .= '<h4>' . esc_html( $r['label'] ) . '</h4>' . ( $diff ? $diff : '<p class="livepull-compare-same">' . esc_html__( 'Only spacing differs.', 'livepull' ) . '</p>' );
		}
		if ( $same ) {
			/* translators: %s: list of field names */
			$html .= '<p class="livepull-compare-same">' . esc_html( sprintf( __( 'The same in both: %s.', 'livepull' ), implode( ', ', $same ) ) ) . '</p>';
		}
		$html .= '</div>';
		wp_send_json_success( array( 'html' => wp_kses_post( $html ) ) );
	}

	/** Connection check: each step with what's wrong and how to fix it. */
	public static function ajax_health() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$checks = LivePull_Health::run();
		$fails  = count( array_filter( $checks, function ( $c ) {
			return 'fail' === $c['status'];
		} ) );
		wp_send_json_success( array( 'html' => self::checks_html( $checks ), 'fails' => $fails ) );
	}

	private static function checks_html( array $checks ) {
		$icons = array( 'ok' => '✓', 'warn' => '!', 'fail' => '✕' );
		$html  = '<ul class="livepull-checks">';
		foreach ( $checks as $c ) {
			$html .= '<li class="is-' . esc_attr( $c['status'] ) . '"><strong>' . esc_html( $icons[ $c['status'] ] . ' ' . $c['title'] ) . '</strong>';
			if ( $c['detail'] ) {
				$html .= ' <span class="description">' . esc_html( $c['detail'] ) . '</span>';
			}
			if ( $c['fix'] ) {
				$html .= '<p class="livepull-fix"><strong>' . esc_html__( 'How to fix:', 'livepull' ) . '</strong> ' . esc_html( $c['fix'] ) . '</p>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/** "Report a problem": the details to paste, and a pre-filled GitHub issue link. */
	public static function ajax_diagnostics() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$text = LivePull_Health::diagnostics();
		wp_send_json_success( array( 'text' => $text, 'issue_url' => LivePull_Health::issue_url( $text ) ) );
	}

	public static function save_schedule() {
		check_admin_referer( 'livepull_schedule' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'livepull' ) );
		}
		LivePull_Schedule::save(
			isset( $_POST['every'] ) ? sanitize_key( wp_unslash( $_POST['every'] ) ) : 'off',
			! empty( $_POST['email'] )
		);
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'msg' => 'schedule' ) ) );
		exit;
	}

	/** Remove items from the held-back list without pulling them. */
	public static function ajax_forget_held() {
		check_ajax_referer( 'livepull' );
		self::guard();
		LivePull_Puller::forget_held( self::posted_keys( 'keys' ) );
		wp_send_json_success( array( 'held_count' => count( LivePull_Puller::held() ) ) );
	}

	public static function ajax_cancel() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$mine = get_transient( 'livepull_run_' . get_current_user_id() );
		$res  = LivePull_Puller::cancel( is_array( $mine ) && ! empty( $mine['lock'] ) ? $mine['lock'] : '' );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message(), 'code' => $res->get_error_code() ) );
		}
		delete_transient( 'livepull_run_' . get_current_user_id() );
		wp_send_json_success( array( 'message' => __( 'Stopped. Everything up to the last finished batch is kept; pull again to continue.', 'livepull' ) ) );
	}

	public static function ajax_full() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$tkey = 'livepull_full_' . get_current_user_id();
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'cancel' === $step ) {
			delete_transient( $tkey );
			LivePull_Full::request_cancel(); // The running step stops itself and cleans up.
			wp_send_json_success( array( 'message' => __( 'Cancelled. Nothing on this site was changed.', 'livepull' ) ) );
		}
		if ( 'start' === $step ) {
			delete_transient( $tkey );
			$state = LivePull_Full::start( array( 'files' => ! empty( $_POST['files'] ) ) );
		} else {
			$state = get_transient( $tkey );
			if ( ! $state ) {
				wp_send_json_error( array( 'message' => __( 'This copy session expired. Start again (nothing was changed).', 'livepull' ) ) );
			}
		}
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message(), 'code' => $state->get_error_code() ) );
		}
		$login = wp_login_url( self::url() );
		$state = LivePull_Full::step( $state );
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
		check_ajax_referer( 'livepull' );
		self::guard();
		$file = LivePull_Backup::find( isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '' );
		if ( ! $file ) {
			wp_send_json_error( array( 'message' => __( 'Backup not found.', 'livepull' ) ) );
		}
		$lock = LivePull_Puller::lock_acquire( 'restore' );
		if ( '' === $lock ) {
			wp_send_json_error( array( 'message' => __( 'A pull or copy is running. Wait for it to finish, or stop it first.', 'livepull' ) ) );
		}
		// Safety net: back up the current state first, so a restore can itself be undone.
		$now = LivePull_Backup::create( 0 === strpos( basename( $file ), 'pre-copy-' ), $file );
		if ( is_wp_error( $now ) ) {
			LivePull_Puller::lock_release( $lock );
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error message */ __( 'Could not back up the current state first: %s', 'livepull' ), $now->get_error_message() ) ) );
		}
		$res = LivePull_Backup::restore( $file ); // Clears the lock when it finishes.
		if ( is_wp_error( $res ) ) {
			LivePull_Puller::lock_release( $lock );
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array(
			'message'  => __( 'Restored. This site is back to how it was before that pull.', 'livepull' ),
			'redirect' => self::url( array( 'restored' => 1 ) ),
		) );
	}

	public static function ajax_resolve() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( 'keep' === $mode ) {
			LivePull_Puller::keep( $key );
			$message = __( 'Kept your version.', 'livepull' );
		} else {
			$r = LivePull_Puller::take( $key );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$message = $r['errors'] ? __( 'Updated with warnings: ', 'livepull' ) . implode( '; ', $r['errors'] ) : __( 'Replaced with the live version.', 'livepull' );
		}
		wp_send_json_success( array( 'message' => $message, 'conflict_count' => count( LivePull_Puller::conflicts() ) ) );
	}

	public static function ajax_local_changes() {
		check_ajax_referer( 'livepull' );
		self::guard();
		$rows = LivePull_Puller::local_changes();
		if ( ! $rows ) {
			$html = '<p class="livepull-empty">' . esc_html__( 'Nothing changed here since the last pull.', 'livepull' ) . '</p>';
		} else {
			$html = '<table class="widefat striped livepull-table"><thead><tr><th>' . esc_html__( 'Item', 'livepull' ) . '</th><th>' . esc_html__( 'Change', 'livepull' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$html .= sprintf(
					'<tr><td>%s</td><td>%s</td></tr>',
					$row['edit'] ? '<a href="' . esc_url( $row['edit'] ) . '">' . esc_html( $row['item'] ) . '</a>' : esc_html( $row['item'] ),
					esc_html( $row['action'] )
				);
			}
			$html .= '</tbody></table>';
		}
		wp_send_json_success( array( 'html' => $html, 'count' => count( $rows ) ) );
	}

	/** HTML from one of the screen's own render functions (for AJAX replies). */
	private static function capture( $cb, $arg ) {
		ob_start();
		try {
			call_user_func( $cb, $arg );
		} finally {
			$html = (string) ob_get_clean(); // Always closed, even if rendering fails.
		}
		return $html;
	}
}

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
		foreach ( array( 'status', 'pull', 'resolve', 'local_changes', 'cancel', 'restore', 'full' ) as $a ) {
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
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'noscript_note' ) );
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
		wp_localize_script( 'livepull-admin', 'LIVEPULL', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'livepull' ),
			'configured' => (bool) LivePull_Config::remote(),
		) );
	}

	/** If the script fails to load, say so instead of spinning forever. */
	public static function noscript_note() {
		echo '<script>window.addEventListener("load",function(){if(!window.LIVEPULL_LOADED){var s=document.getElementById("livepull-status");if(s&&s.classList.contains("is-loading")){s.className="livepull-status is-error";s.textContent="The LivePull script did not load (check the plugin files are complete, and that no optimisation plugin is blocking assets/admin.js).";}}});</script>';
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
		} elseif ( 'generate' === $do && ! defined( 'LIVEPULL_SECRET' ) ) {
			update_option( 'livepull_secret', bin2hex( random_bytes( 32 ) ), false );
			$msg = 'key';
		}
		wp_safe_redirect( self::url( array( 'msg' => $msg ) ) );
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
		$configured = (bool) LivePull_Config::remote();
		$msgs       = array(
			'connected'    => __( 'Connection saved. Checking live…', 'livepull' ),
			'disconnected' => __( 'Disconnected from live.', 'livepull' ),
			'key'          => __( 'New key generated. Copy it into your local site.', 'livepull' ),
			'restored'     => __( 'Backup restored. You may need to log in again if you were logged out.', 'livepull' ),
			'saved'        => __( 'Saved.', 'livepull' ),
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
			<p class="livepull-lead"><?php esc_html_e( 'Pull content your client adds or edits on the live site into this copy, without overwriting your own work.', 'livepull' ); ?></p>
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
		<div class="livepull-grid">
			<div class="livepull-card">
				<h2><?php esc_html_e( 'Is this your local or staging copy?', 'livepull' ); ?></h2>
				<p><?php esc_html_e( 'Connect it to the live site, then pull changes with one click.', 'livepull' ); ?></p>
				<?php self::connect_form(); ?>
			</div>
			<?php self::render_source( false ); ?>
		</div>
		<?php
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

	/** The pull screen (this site pulls from live). */
	private static function render_pull() {
		$conflicts = LivePull_Puller::conflicts();
		?>
		<div class="livepull-card livepull-main">
			<div class="livepull-head">
				<h2><?php esc_html_e( 'Pull from live', 'livepull' ); ?></h2>
				<span class="livepull-remote"><?php echo esc_html( LivePull_Config::remote() ); ?></span>
			</div>

			<p id="livepull-recording" class="description" hidden></p>
			<div id="livepull-status" class="livepull-status is-loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Checking live site…', 'livepull' ); ?></div>
			<p id="livepull-last-summary" class="description" hidden></p>
			<div id="livepull-notices"></div>

			<div class="livepull-actions">
				<button type="button" class="button button-hero" id="livepull-preview" disabled><?php esc_html_e( 'Preview changes', 'livepull' ); ?></button>
				<button type="button" class="button button-primary button-hero" id="livepull-pull" disabled><?php esc_html_e( 'Pull changes', 'livepull' ); ?></button>
				<button type="button" class="button-link livepull-danger" id="livepull-cancel" hidden><?php esc_html_e( 'Cancel', 'livepull' ); ?></button>
			</div>

			<details class="livepull-advanced livepull-what">
				<summary><?php esc_html_e( 'What gets pulled?', 'livepull' ); ?></summary>
				<div class="livepull-what-grid">
					<div><strong><?php esc_html_e( 'Every pull', 'livepull' ); ?></strong>
						<ul class="livepull-bullets">
							<li><?php esc_html_e( 'Posts, pages and custom post types (incl. WooCommerce products)', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Media library + files', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Categories, tags and other taxonomies', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Custom fields (ACF etc.) and page-builder content', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Menus and menu locations', 'livepull' ); ?><?php echo LivePull_Config::pull_menus() ? '' : ' <em>' . esc_html__( '(switched off on this site)', 'livepull' ) . '</em>'; ?></li>
							<li><?php esc_html_e( 'Site title, tagline, homepage, posts page, sticky posts, site icon, logo', 'livepull' ); ?></li>
						</ul>
					</div>
					<div><strong><?php self::kses_e( __( 'Only with <em>Copy everything from live</em>', 'livepull' ) ); ?></strong>
						<ul class="livepull-bullets">
							<li><?php esc_html_e( 'Theme, Customizer and Divi theme options', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Divi Theme Builder & Library, Elementor templates', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Widgets, forms and other plugin settings', 'livepull' ); ?></li>
							<li><?php esc_html_e( 'Users', 'livepull' ); ?></li>
						</ul>
						<strong><?php esc_html_e( 'Never', 'livepull' ); ?></strong>
						<ul class="livepull-bullets"><li><?php esc_html_e( 'WooCommerce orders and customers\' sessions', 'livepull' ); ?></li><li><?php esc_html_e( 'Theme and plugin files (keep those in Git)', 'livepull' ); ?></li></ul>
					</div>
				</div>
			</details>

			<details class="livepull-advanced">
				<summary><?php esc_html_e( 'More options', 'livepull' ); ?></summary>
				<p>
					<label><input type="checkbox" id="livepull-all"> <?php self::kses_e( __( '<strong>Pull all content from live</strong> (first sync)', 'livepull' ) ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Compares every post, page, media item and category on live, not just changes recorded since LivePull was installed. Your local edits are still kept as conflicts.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><strong><?php esc_html_e( 'Or catch up from a date', 'livepull' ); ?></strong><br>
					<input type="date" id="livepull-since"></label><br>
					<span class="description"><?php esc_html_e( 'Pulls everything changed on live since that date, e.g. the day you made your local copy.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" id="livepull-theirs"> <?php esc_html_e( 'Let live win all conflicts', 'livepull' ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Overwrites your local edits wherever live also changed the same item.', 'livepull' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" id="livepull-redownload"> <?php esc_html_e( 'Re-download media files that already exist', 'livepull' ); ?></label>
				</p>
			</details>

			<div id="livepull-progress" class="livepull-progress" hidden>
				<div class="livepull-bar"><span></span></div>
				<p class="livepull-progress-text"></p>
			</div>
			<div id="livepull-result"></div>
		</div>

		<div class="livepull-card">
			<details id="livepull-full-card">
				<summary><h2><?php esc_html_e( 'Copy everything from live', 'livepull' ); ?></h2></summary>
				<p><?php self::kses_e( sprintf( /* translators: %s: this site's address */ __( 'Replaces this site\'s <strong>whole database</strong> with a copy of live: posts, pages, menus, settings, Divi Theme Builder and Library, widgets, users and plugin data. Live URLs are switched to <code>%s</code>. Use it to start fresh, or when this copy has drifted too far from live.', 'livepull' ), esc_html( home_url() ) ) ); ?></p>
				<ul class="livepull-bullets">
					<li><?php self::kses_e( __( 'Your current database is backed up first (restore it any time under <em>Undo a pull or copy</em>).', 'livepull' ) ); ?></li>
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
			</details>
		</div>

		<div class="livepull-card">
			<h2><?php esc_html_e( 'Conflicts', 'livepull' ); ?> <span class="count" id="livepull-conflict-count">(<?php echo (int) count( $conflicts ); ?>)</span></h2>
			<p class="description"><?php self::kses_e( __( 'Items changed on live <em>and</em> here since they were last pulled. They were left as they are here until you choose.', 'livepull' ) ); ?></p>
			<div id="livepull-conflicts"><?php self::conflicts_table( $conflicts ); ?></div>
		</div>

		<?php $backups = LivePull_Backup::all(); ?>
		<div class="livepull-card">
			<details id="livepull-backups" <?php echo isset( $_GET['restored'] ) ? 'open' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only opens a panel after a redirect. ?>>
				<summary><h2><?php esc_html_e( 'Undo a pull or copy', 'livepull' ); ?></h2></summary>
				<p class="description"><?php esc_html_e( 'A backup is taken before every pull and every full copy. Restoring one puts this site\'s posts, pages, media library, categories and settings back exactly as they were at that moment (and its users too, for a backup taken before a full copy). Media files that were downloaded stay in the uploads folder.', 'livepull' ); ?></p>
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
			</details>
		</div>

		<div class="livepull-card">
			<details id="livepull-local-details">
				<summary><h2><?php esc_html_e( 'Your local changes', 'livepull' ); ?></h2></summary>
				<p class="description"><?php esc_html_e( 'Everything edited on this site since it was last pulled. If live changes any of these, you\'ll get a conflict instead of an overwrite.', 'livepull' ); ?></p>
				<div id="livepull-local"><p><span class="spinner is-active"></span></p></div>
			</details>
		</div>

		<div class="livepull-card">
			<details id="livepull-connection">
				<summary><h2><?php esc_html_e( 'Connection', 'livepull' ); ?></h2></summary>
				<?php self::connect_form(); ?>
				<?php if ( ! defined( 'LIVEPULL_REMOTE' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Disconnect from the live site?', 'livepull' ) ) ); ?>);">
					<?php wp_nonce_field( 'livepull_save' ); ?>
					<input type="hidden" name="action" value="livepull_save">
					<input type="hidden" name="do" value="disconnect">
					<button class="button-link livepull-danger"><?php esc_html_e( 'Disconnect', 'livepull' ); ?></button>
				</form>
				<?php endif; ?>
				<?php $latest = LivePull_Backup::latest(); ?>
				<p class="description"><?php self::kses_e( sprintf( /* translators: 1: backups folder, 2: latest backup note (may be empty) */ __( 'Backups are saved before every pull in <code>%1$s</code>%2$s. Restore with <code>wp db import</code> or phpMyAdmin.', 'livepull' ), esc_html( LivePull_Backup::dir() ), $latest ? ' (' . sprintf( /* translators: %s: file name */ __( 'latest: <code>%s</code>', 'livepull' ), esc_html( basename( $latest ) ) ) . ')' : '' ) ); ?></p>
				<?php if ( LivePull_Backup::in_web_root() ) : ?>
					<p class="description"><?php self::kses_e( __( '<strong>Backups are inside the website\'s own folder</strong> because the folder above it can\'t be written to. They are saved as files that can\'t be downloaded over the web, with unguessable names. To keep them completely out of the website, add <code>define( \'LIVEPULL_BACKUP_DIR\', \'/a/folder/outside/the/website\' );</code> to wp-config.php.', 'livepull' ) ); ?></p>
				<?php endif; ?>
				<p class="description"><?php self::kses_e( __( 'Terminal: <code>wp livepull status</code>, <code>wp livepull pull --dry-run</code>, <code>wp livepull pull</code>.', 'livepull' ) ); ?></p>
			</details>
		</div>
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
				$edit = 0 === strpos( $key, 'term:' ) ? get_edit_term_link( (int) $c['local_id'] ) : get_edit_post_link( (int) $c['local_id'], 'raw' );
			}
			printf(
				'<tr data-key="%1$s"><td>%2$s</td><td>%3$s</td><td class="livepull-row-actions"><button type="button" class="button livepull-take">%4$s</button> <button type="button" class="button-link livepull-keep">%5$s</button></td></tr>',
				esc_attr( $key ),
				$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $c['label'] ) . '</a>' : esc_html( $c['label'] ),
				esc_html( ucfirst( $c['reason'] ) ),
				esc_html__( 'Use live version', 'livepull' ),
				esc_html__( 'Keep mine', 'livepull' )
			);
		}
		echo '</tbody></table>';
	}

	/** "Let other sites pull from this one" (shown on live, and folded away on local). */
	private static function render_source( $collapsed ) {
		$secret = LivePull_Config::secret();
		$const  = defined( 'LIVEPULL_SECRET' );
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = 'local'", LivePull_Config::table() ) );
		?>
		<div class="livepull-card">
			<?php if ( $collapsed ) : ?><details><summary><h2><?php esc_html_e( 'Let other sites pull from this one', 'livepull' ); ?></h2></summary><?php else : ?>
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
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of changes */ __( 'Changes recorded on this site: %d. Pulling never writes anything to this site; the key only allows reading content.', 'livepull' ), (int) $count ) ); ?></p>
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
		self::kses_e( __( 'Not seeing an edit here? Then LivePull doesn\'t track that kind of change (for example Divi Theme Builder, theme/Customizer options, widgets, or plugin settings) – use <em>Copy everything from live</em> on your local site to bring those across.', 'livepull' ) );
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
			'waiting'    => max( 0, (int) $remote['last_id'] - $cursor ),
			'last_pull'  => $last ? human_time_diff( strtotime( $last . ' UTC' ) ) . ' ago' : 'never',
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
				'files'     => $r['files'],
			),
			'activity'  => $state['activity'],
			'dl_total'  => (int) $state['dl_total'],
			'dl_done'   => (int) $state['dl_done'],
			'conflicts_html' => $state['done'] ? self::capture( array( __CLASS__, 'conflicts_table' ), LivePull_Puller::conflicts() ) : '',
			'conflict_count' => count( LivePull_Puller::conflicts() ),
		) );
	}

	public static function ajax_cancel() {
		check_ajax_referer( 'livepull' );
		self::guard();
		delete_transient( 'livepull_run_' . get_current_user_id() );
		LivePull_Puller::cancel();
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
		if ( get_transient( LivePull_Puller::LOCK ) ) {
			wp_send_json_error( array( 'message' => __( 'A pull is running. Cancel it first.', 'livepull' ) ) );
		}
		// Safety net: back up the current state first, so a restore can itself be undone.
		$now = LivePull_Backup::create( 0 === strpos( basename( $file ), 'pre-copy-' ), $file );
		if ( is_wp_error( $now ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error message */ __( 'Could not back up the current state first: %s', 'livepull' ), $now->get_error_message() ) ) );
		}
		$res = LivePull_Backup::restore( $file );
		if ( is_wp_error( $res ) ) {
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
		ob_start();
		if ( ! $rows ) {
			echo '<p class="livepull-empty">Nothing changed here since the last pull.</p>';
		} else {
			echo '<table class="widefat striped livepull-table"><thead><tr><th>Item</th><th>Change</th></tr></thead><tbody>';
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

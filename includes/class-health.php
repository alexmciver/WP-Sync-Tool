<?php
/**
 * Connection check and "Report a problem" details.
 *
 * The check walks through everything between this site and live in order (settings, reaching
 * the site, its REST API, the key, versions, caching) and, for anything wrong, says what is
 * causing it and exactly how to fix it. The report gathers the details a bug report needs.
 *
 * @package LivePull
 */

defined( 'ABSPATH' ) || exit;

class LivePull_Health {

	/**
	 * Run every check. @return array List of array( status: ok|warn|fail, title, detail, fix ).
	 */
	/** The staging password header (LIVEPULL_REMOTE_BASIC_AUTH), as the client sends it. */
	private static function basic_auth() {
		return defined( 'LIVEPULL_REMOTE_BASIC_AUTH' ) && LIVEPULL_REMOTE_BASIC_AUTH ? array( 'Authorization' => 'Basic ' . base64_encode( LIVEPULL_REMOTE_BASIC_AUTH ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
	}

	/**
	 * GET that follows up to 3 redirects itself, sending the staging password only to the
	 * live site's own host (never to wherever a redirect points).
	 */
	private static function fetch( $url, array $args, $remote ) {
		$host = strtolower( (string) wp_parse_url( $remote, PHP_URL_HOST ) );
		$args['redirection'] = 0;
		for ( $i = 0; $i < 4; $i++ ) {
			$a = $args;
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host ) {
				$a['headers'] = ( isset( $a['headers'] ) ? $a['headers'] : array() ) + self::basic_auth();
			}
			$res  = wp_remote_get( $url, $a );
			$code = (int) wp_remote_retrieve_response_code( $res );
			$next = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $code < 300 || $code > 399 || '' === $next ) {
				return $res;
			}
			$url = 0 === strpos( $next, '/' ) ? preg_replace( '#^(https?://[^/]+).*$#i', '$1', $url ) . $next : $next;
		}
		return $res;
	}

	public static function run() {
		$checks = array();
		$add    = function ( $status, $title, $detail = '', $fix = '' ) use ( &$checks ) {
			$checks[] = compact( 'status', 'title', 'detail', 'fix' );
		};

		self::local_checks( $add );

		$remote = LivePull_Config::remote();
		$secret = LivePull_Config::remote_secret();
		if ( ! $remote || ! $secret ) {
			$add( 'warn', __( 'Not connected to a live site', 'livepull' ), '', __( 'This is fine on the live site itself. On your local or staging site, paste the connection code from Tools → LivePull on live.', 'livepull' ) );
			return $checks;
		}

		// 1. Can we reach the live site at all?
		$t0   = microtime( true );
		$home = self::fetch( $remote . '/', array( 'timeout' => 20, 'sslverify' => self::sslverify() ), $remote );
		$ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $home ) ) {
			list( $title, $fix ) = self::network_fix( $home->get_error_message(), $remote );
			$add( 'fail', $title, $home->get_error_message(), $fix );
			return $checks;
		}
		$code = (int) wp_remote_retrieve_response_code( $home );
		if ( 401 === $code ) {
			$add( 'fail', __( 'The live site asks for a username and password', 'livepull' ), 'HTTP 401', ( self::basic_auth() ? __( 'The username and password in LIVEPULL_REMOTE_BASIC_AUTH in wp-config.php weren\'t accepted. Check them with whoever set up the password protection.', 'livepull' ) : __( 'It is behind password protection (common on staging sites). Add define( \'LIVEPULL_REMOTE_BASIC_AUTH\', \'username:password\' ); to wp-config.php on this site.', 'livepull' ) ) );
			return $checks;
		}
		/* translators: 1: HTTP status, 2: milliseconds */
		$add( $code >= 500 ? 'warn' : 'ok', __( 'The live site can be reached', 'livepull' ), sprintf( __( 'HTTP %1$d in %2$d ms', 'livepull' ), $code, $ms ), $code >= 500 ? __( 'The live site is returning a server error. Check it loads in a browser.', 'livepull' ) : '' );
		$via = self::behind( $home );
		if ( $via ) {
			/* translators: %s: service name, e.g. Cloudflare */
			$add( 'ok', sprintf( __( 'Live is behind %s', 'livepull' ), $via ), '', '' );
		}

		// 2. Is the REST API there, and is LivePull on it?
		$rest = self::rest_probe( $remote );
		if ( is_wp_error( $rest ) ) {
			$add( 'fail', __( 'Live\'s REST API can\'t be reached', 'livepull' ), $rest->get_error_message(), self::blocked_fix( $rest->get_error_data(), $via ) );
			return $checks;
		}
		if ( ! $rest['livepull'] ) {
			$add( 'fail', __( 'LivePull isn\'t running on the live site', 'livepull' ), __( 'WordPress answered, but LivePull\'s routes aren\'t there.', 'livepull' ), __( 'Install and activate LivePull on the live site. If it is active, a plugin that hides or restricts the REST API (for example "Disable REST API", Perfmatters, Solid Security or WP Hide) is hiding it: allow the /livepull/v1/ routes in that plugin\'s settings.', 'livepull' ) );
			return $checks;
		}
		$add( 'ok', __( 'Live\'s REST API is reachable and LivePull is installed there', 'livepull' ), 'plain' === $rest['style'] ? __( 'Using ?rest_route= addresses (live has plain permalinks)', 'livepull' ) : '' );

		// 3. Does the key work, and do versions match?
		$client = new LivePull_Client( $remote, $secret );
		$status = $client->get( 'status' );
		if ( is_wp_error( $status ) ) {
			$msg = $status->get_error_message();
			if ( false !== stripos( $msg, 'key' ) ) {
				$add( 'fail', __( 'Live doesn\'t accept the key', 'livepull' ), $msg, __( 'The key was changed on live, or a different one was pasted. Copy the connection code again from Tools → LivePull on live and paste it here.', 'livepull' ) );
			} else {
				$add( 'fail', __( 'Live refused LivePull\'s request', 'livepull' ), $msg, self::blocked_fix( array( 'body' => $msg ), $via ) );
			}
			return $checks;
		}
		$add( 'ok', __( 'The key works', 'livepull' ) );
		if ( version_compare( (string) $status['version'], LivePull_Config::VERSION, '!=' ) ) {
			/* translators: 1: live version, 2: this site's version */
			$add( 'warn', __( 'LivePull versions differ', 'livepull' ), sprintf( __( 'Live has %1$s, this site has %2$s.', 'livepull' ), $status['version'], LivePull_Config::VERSION ), __( 'Update LivePull on both sites so they match.', 'livepull' ) );
		} else {
			$add( 'ok', __( 'Both sites run the same LivePull version', 'livepull' ), LivePull_Config::VERSION );
		}

		// 4. Is something caching LivePull's answers?
		$cache = self::cache_probe( $remote, $secret );
		if ( $cache ) {
			$add( 'warn', __( 'A cache may be storing LivePull\'s answers on live', 'livepull' ), $cache, __( 'Exclude /wp-json/livepull/ and URLs containing rest_route=/livepull/ from caching on live (host cache, cache plugin, Cloudflare Cache Rules). Otherwise new changes may not show up straight away.', 'livepull' ) );
		}

		// 5. Media downloads failing earlier?
		$history = LivePull_History::all();
		foreach ( array_slice( $history, 0, 3 ) as $h ) {
			foreach ( (array) $h['errors'] as $e ) {
				if ( false !== strpos( $e, 'Media download failed' ) ) {
					$add( 'warn', __( 'Some media files failed to download in a recent pull', 'livepull' ), $e, __( 'Live may block downloads from other sites (hotlink protection in Cloudflare or the host\'s control panel). Allow this site, or switch hotlink protection off while pulling. Then pull again with "Re-download media files" ticked under More options.', 'livepull' ) );
					break 2;
				}
			}
		}
		return $checks;
	}

	/** Problems on this site itself. */
	private static function local_checks( $add ) {
		$uploads = wp_get_upload_dir();
		if ( ! wp_is_writable( $uploads['basedir'] ) ) {
			$add( 'fail', __( 'This site\'s uploads folder can\'t be written to', 'livepull' ), $uploads['basedir'], __( 'Media can\'t be downloaded until the web server can write to the uploads folder. Check its permissions.', 'livepull' ) );
		}
		$backups = LivePull_Backup::dir();
		if ( ! wp_is_writable( $backups ) ) {
			$add( 'fail', __( 'Backups can\'t be saved', 'livepull' ), $backups, __( 'Pulls stop if the backup fails. Make this folder writable, or set LIVEPULL_BACKUP_DIR in wp-config.php to a writable folder.', 'livepull' ) );
		}
		$mem = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $mem > 0 && $mem < 128 * MB_IN_BYTES ) {
			/* translators: %s: memory limit */
			$add( 'warn', __( 'PHP memory is low', 'livepull' ), sprintf( __( 'memory_limit is %s', 'livepull' ), ini_get( 'memory_limit' ) ), __( 'Big pages and backups may fail. Raise memory_limit to at least 256M.', 'livepull' ) );
		}
		$sched = LivePull_Schedule::settings();
		if ( 'off' !== $sched['every'] && ! LivePull_Schedule::next() ) {
			$add( 'warn', __( 'Scheduled pulls are on but not scheduled', 'livepull' ), '', __( 'Open Tools → LivePull → Settings and press Save under "Pull automatically".', 'livepull' ) );
		}
		if ( 'off' !== $sched['every'] && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$add( 'warn', __( 'Scheduled pulls rely on a server cron job here', 'livepull' ), 'DISABLE_WP_CRON', __( 'WordPress\'s own scheduler is switched off on this site, so make sure a server cron job runs wp-cron.php (or `wp cron event run --due-now`) regularly, or scheduled pulls won\'t run.', 'livepull' ) );
		}
		$lock = LivePull_Puller::lock_info();
		if ( $lock && time() - (int) $lock['time'] > LivePull_Puller::LOCK_QUIET ) {
			/* translators: %s: time span */
			$add( 'warn', __( 'A pull looks stuck', 'livepull' ), sprintf( __( 'Nothing has happened for %s.', 'livepull' ), human_time_diff( (int) $lock['time'] ) ), __( 'If nothing is running (for example the page was closed mid-pull), press Pull again and choose "Unlock and pull again". It also unlocks by itself after 15 minutes.', 'livepull' ) );
		}
	}

	/** Friendly name for what's in front of live, from response headers. */
	private static function behind( $res ) {
		$h = wp_remote_retrieve_headers( $res );
		if ( $h['cf-ray'] ) {
			return 'Cloudflare';
		}
		if ( $h['x-sucuri-id'] || $h['x-sucuri-cache'] ) {
			return 'Sucuri';
		}
		if ( false !== stripos( (string) $h['server'], 'litespeed' ) || $h['x-litespeed-cache'] ) {
			return 'LiteSpeed';
		}
		if ( $h['x-kinsta-cache'] ) {
			return 'Kinsta';
		}
		if ( $h['wpe-backend'] || $h['x-cacheable'] ) {
			return 'WP Engine';
		}
		return '';
	}

	/** Find the REST API on live (pretty URLs first, then ?rest_route=). */
	private static function rest_probe( $remote ) {
		$last = null;
		foreach ( array( 'pretty' => $remote . '/wp-json/', 'plain' => $remote . '/?rest_route=/' ) as $style => $url ) {
			$res = self::fetch( add_query_arg( '_livepull', (string) microtime( true ), $url ), array( 'timeout' => 20, 'sslverify' => self::sslverify(), 'headers' => array( 'Accept' => 'application/json' ) ), $remote );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $body ) && isset( $body['namespaces'] ) ) {
				return array( 'style' => $style, 'livepull' => in_array( 'livepull/v1', (array) $body['namespaces'], true ) );
			}
			$last = new WP_Error(
				'livepull_rest',
				/* translators: %d: HTTP status */
				sprintf( __( 'Live returned HTTP %d and a web page instead of the REST API.', 'livepull' ), (int) wp_remote_retrieve_response_code( $res ) ),
				array( 'body' => substr( wp_remote_retrieve_body( $res ), 0, 4000 ), 'code' => (int) wp_remote_retrieve_response_code( $res ) )
			);
		}
		return $last;
	}

	/** Ask for status twice: identical answers or cache-hit headers mean something caches them. */
	private static function cache_probe( $remote, $secret ) {
		$url  = $remote . '/wp-json/livepull/v1/status';
		$args = array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => self::sslverify(), 'headers' => array( 'X-LivePull-Key' => $secret, 'Accept' => 'application/json' ) + self::basic_auth() );
		$res  = wp_remote_get( $url, $args ); // Deliberately without a cache-buster.
		if ( is_wp_error( $res ) ) {
			return '';
		}
		foreach ( array( 'cf-cache-status', 'x-cache', 'x-litespeed-cache', 'x-proxy-cache', 'x-cache-status', 'x-kinsta-cache' ) as $h ) {
			$v = (string) wp_remote_retrieve_header( $res, $h );
			if ( preg_match( '/\bhit\b/i', $v ) ) {
				return $h . ': ' . $v;
			}
		}
		return '';
	}

	/** Fix for a blocked REST request, based on what answered. */
	private static function blocked_fix( $data, $via ) {
		$body = is_array( $data ) && isset( $data['body'] ) ? (string) $data['body'] : '';
		$code = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : 0;
		if ( false !== stripos( $body, 'Just a moment' ) || false !== stripos( $body, 'cf-chl' ) || false !== stripos( $body, 'challenge-platform' ) ) {
			return __( 'Cloudflare is showing a browser challenge (Bot Fight Mode or a WAF rule). In Cloudflare → Security → WAF, add a custom rule: if URI Path contains "/wp-json/livepull/" (or the query contains "rest_route=/livepull/") then Skip all remaining custom rules and Bot Fight Mode.', 'livepull' );
		}
		if ( false !== stripos( $body, 'Wordfence' ) ) {
			return __( 'Wordfence on live blocked the request. In Wordfence → Firewall → All Firewall Options, allowlist this site\'s IP address, or allowlist the URL /wp-json/livepull/.', 'livepull' );
		}
		if ( false !== stripos( $body, 'sucuri' ) ) {
			return __( 'The Sucuri firewall blocked the request. In the Sucuri dashboard, allowlist the path /wp-json/livepull/ or this site\'s IP address.', 'livepull' );
		}
		if ( false !== stripos( $body, 'iThemes' ) || false !== stripos( $body, 'Solid Security' ) ) {
			return __( 'Solid Security (iThemes) on live restricts the REST API. In Security → Settings → Advanced → WordPress Tweaks, set REST API to "Default Access", or allow the /livepull/v1/ routes.', 'livepull' );
		}
		if ( 'Cloudflare' === $via ) {
			return __( 'Cloudflare may be caching or blocking it. Add a Cache Rule to bypass cache for URI Path containing "/wp-json/", and make sure no WAF rule blocks /wp-json/livepull/.', 'livepull' );
		}
		if ( 403 === $code ) {
			return __( 'A security plugin or the host\'s firewall on live blocked the request (HTTP 403). Allow requests to /wp-json/livepull/ in it.', 'livepull' );
		}
		return __( 'A page cache, firewall or plugin on live answered instead of WordPress. Exclude /wp-json/ and URLs containing rest_route= from caching and firewall rules on live.', 'livepull' );
	}

	/** @return array( title, fix ) for a network-level error. */
	private static function network_fix( $msg, $remote ) {
		if ( preg_match( '/cURL error 6\b|resolve host|getaddrinfo/i', $msg ) ) {
			return array( __( 'The live site\'s address can\'t be found', 'livepull' ), sprintf( /* translators: %s: URL */ __( 'Check the address is right (%s). If you\'re offline or behind a VPN, reconnect and try again.', 'livepull' ), $remote ) );
		}
		if ( preg_match( '/cURL error (60|35|51|58)\b|SSL/i', $msg ) ) {
			return array( __( 'The live site\'s security certificate wasn\'t accepted', 'livepull' ), __( 'If live has a valid certificate, update this server\'s certificate bundle. If live is another local site with a self-signed certificate, add define( \'LIVEPULL_SSL_VERIFY\', false ); to wp-config.php on this site (only for local sites).', 'livepull' ) );
		}
		if ( preg_match( '/cURL error 28\b|timed out/i', $msg ) ) {
			return array( __( 'The live site took too long to answer', 'livepull' ), __( 'Live may be slow or a firewall may be dropping the connection. Try again, and check live loads in a browser.', 'livepull' ) );
		}
		if ( preg_match( '/cURL error 7\b|refused/i', $msg ) ) {
			return array( __( 'The live site refused the connection', 'livepull' ), __( 'Check the address and that the site is up. If live is on your own network, make sure this site can reach it.', 'livepull' ) );
		}
		return array( __( 'The live site can\'t be reached', 'livepull' ), __( 'Check the address and that it loads in a browser.', 'livepull' ) );
	}

	private static function sslverify() {
		return ! ( defined( 'LIVEPULL_SSL_VERIFY' ) && ! LIVEPULL_SSL_VERIFY );
	}

	/* ============================================================ Report a problem */

	/** Plain-text details for a bug report. No keys or passwords. */
	public static function diagnostics( ?array $checks = null ) {
		global $wpdb, $wp_version;
		$lines   = array();
		$lines[] = '### Environment';
		$lines[] = 'LivePull: ' . LivePull_Config::VERSION . ( class_exists( 'LivePull_Updater' ) ? ' (GitHub download)' : ' (WordPress.org)' );
		$lines[] = 'This site is: ' . ( LivePull_Config::remote() ? 'pulling from ' . wp_parse_url( LivePull_Config::remote(), PHP_URL_HOST ) : 'a live/source site (not connected to another)' );
		$lines[] = 'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' );
		$lines[] = 'PHP: ' . PHP_VERSION . ' · memory_limit ' . ini_get( 'memory_limit' ) . ' · max_execution_time ' . ini_get( 'max_execution_time' );
		$lines[] = 'Database: ' . $wpdb->db_server_info();
		$lines[] = 'Web server: ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown' );
		$lines[] = 'Host: ' . self::host();
		$lines[] = 'Permalinks: ' . ( get_option( 'permalink_structure' ) ? 'pretty' : 'plain' );
		$theme   = wp_get_theme();
		$lines[] = 'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' );
		$builders = self::builders();
		$lines[]  = 'Builders and key plugins: ' . ( $builders ? implode( ', ', $builders ) : 'none detected' );
		$lines[]  = 'Backups: ' . ( LivePull_Backup::in_web_root() ? 'inside the web root' : 'outside the web root' ) . ', ' . count( LivePull_Backup::all() ) . ' kept';

		$lines[] = '';
		$lines[] = '### Last pulls';
		foreach ( array_slice( LivePull_History::all(), 0, 3 ) as $h ) {
			$lines[] = '- ' . gmdate( 'Y-m-d H:i', $h['time'] ) . ' UTC, ' . $h['kind'] . ' (' . $h['source'] . '): ' . LivePull_History::summary( $h );
			foreach ( array_slice( array_merge( (array) $h['errors'], (array) $h['skipped'] ), 0, 5 ) as $e ) {
				$lines[] = '  - ' . $e;
			}
		}
		$lines[] = 'Open conflicts: ' . count( LivePull_Puller::conflicts() ) . ' · Held back: ' . count( LivePull_Puller::held() );

		if ( null === $checks ) {
			$checks = self::run();
		}
		$lines[] = '';
		$lines[] = '### Connection check';
		foreach ( $checks as $c ) {
			$lines[] = '- [' . strtoupper( $c['status'] ) . '] ' . $c['title'] . ( $c['detail'] ? ' (' . wp_strip_all_tags( $c['detail'] ) . ')' : '' );
		}

		$lines[] = '';
		$lines[] = '### Active plugins';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $f ) {
			$lines[] = '- ' . ( isset( $all[ $f ] ) ? $all[ $f ]['Name'] . ' ' . $all[ $f ]['Version'] : $f );
		}
		return LivePull_Config::scrub( implode( "\n", $lines ) );
	}

	/** A pre-filled "new issue" link on GitHub (long reports are cut; the full text is copied). */
	/** True for the GitHub download (it has the updater); false for the WordPress.org copy. */
	public static function from_github() {
		return class_exists( 'LivePull_Updater' );
	}

	public static function issue_url( $text ) {
		if ( ! self::from_github() ) {
			return 'https://wordpress.org/support/plugin/livepull/#new-topic-0';
		}
		$body = "**What happened?**\n\n\n**What did you expect?**\n\n\n**Steps to reproduce**\n1. \n\n" . "<details><summary>Site details (from LivePull)</summary>\n\n" . $text . "\n</details>";
		if ( strlen( $body ) > 6000 ) {
			$body = substr( $body, 0, 5800 ) . "\n…(cut: paste the full details from LivePull)\n</details>";
		}
		return add_query_arg( array( 'labels' => 'bug', 'title' => rawurlencode( 'Bug: ' ), 'body' => rawurlencode( $body ) ), 'https://github.com/alexmciver/WP-Sync-Tool/issues/new' );
	}

	private static function host() {
		$abspath = wp_normalize_path( ABSPATH );
		$checks  = array(
			'WP Engine'          => defined( 'WPE_APIKEY' ) || getenv( 'IS_WPE' ),
			'Kinsta'             => defined( 'KINSTAMU_VERSION' ) || getenv( 'KINSTA_CACHE_ZONE' ),
			'Cloudways'          => false !== strpos( $abspath, '/home/master/applications/' ),
			'SiteGround'         => false !== strpos( $abspath, '/home/customer/www/' ),
			'GoDaddy'            => defined( 'GD_SYSTEM_PLUGIN_DIR' ),
			'Pantheon'           => (bool) getenv( 'PANTHEON_ENVIRONMENT' ),
			'Flywheel'           => defined( 'FLYWHEEL_CONFIG_DIR' ),
			'WordPress.com'      => defined( 'WPCOMSH_VERSION' ),
			'Local (by Flywheel)' => false !== strpos( $abspath, '/Local Sites/' ) || false !== stripos( $abspath, '\\Local Sites\\' ),
			'Pressable'          => defined( 'IS_PRESSABLE' ),
		);
		foreach ( $checks as $name => $yes ) {
			if ( $yes ) {
				return $name;
			}
		}
		return 'unknown';
	}

	private static function builders() {
		$found = array();
		$known = array(
			'Elementor'       => 'ELEMENTOR_VERSION',
			'Divi'            => 'ET_BUILDER_VERSION',
			'Beaver Builder'  => 'FL_BUILDER_VERSION',
			'WPBakery'        => 'WPB_VC_VERSION',
			'Bricks'          => 'BRICKS_VERSION',
			'Oxygen'          => 'CT_VERSION',
			'Brizy'           => 'BRIZY_VERSION',
			'SiteOrigin'      => 'SITEORIGIN_PANELS_VERSION',
			'Spectra'         => 'UAGB_VER',
			'Kadence Blocks'  => 'KADENCE_BLOCKS_VERSION',
			'GenerateBlocks'  => 'GENERATEBLOCKS_VERSION',
			'WooCommerce'     => 'WC_VERSION',
			'ACF'             => 'ACF_VERSION',
			'Yoast SEO'       => 'WPSEO_VERSION',
			'Rank Math'       => 'RANK_MATH_VERSION',
			'Polylang'        => 'POLYLANG_VERSION',
			'WPML'            => 'ICL_SITEPRESS_VERSION',
			'WP Offload Media' => 'AS3CF_VERSION',
		);
		foreach ( $known as $name => $const ) {
			if ( defined( $const ) ) {
				$found[] = $name . ' ' . constant( $const );
			}
		}
		if ( class_exists( 'GFForms' ) ) {
			$found[] = 'Gravity Forms ' . GFForms::$version;
		}
		return $found;
	}
}

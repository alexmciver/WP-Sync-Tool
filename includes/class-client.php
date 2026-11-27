<?php
defined( 'ABSPATH' ) || exit;

/** Talks to the live site's endpoints. */
final class LivePull_Client {

	private $base;
	private $secret;
	/** Address url_allowed() approved for the last non-live host, so the request uses exactly that. */
	private $pinned = null;

	public function __construct( $base, $secret ) {
		$this->base   = untrailingslashit( $base );
		$this->secret = $secret;
	}

	private function headers() {
		$h = array(
			'X-LivePull-Key'  => $this->secret,
			'Accept'        => 'application/json',
			'Cache-Control' => 'no-cache, no-store',
			'Pragma'        => 'no-cache',
		);
		if ( defined( 'LIVEPULL_REMOTE_BASIC_AUTH' ) && LIVEPULL_REMOTE_BASIC_AUTH ) {
			$h['Authorization'] = 'Basic ' . base64_encode( LIVEPULL_REMOTE_BASIC_AUTH ); // phpcs:ignore
		}
		return $h;
	}

	/**
	 * The two ways to reach the REST API. /wp-json/ is tried first because page caches
	 * almost always skip it; ?rest_route= works even without pretty permalinks.
	 */
	private function urls( $route, array $params ) {
		$params['_livepull'] = (string) microtime( true ); // Defeat caches that ignore headers.
		$pretty = add_query_arg( $params, $this->base . '/wp-json/livepull/v1/' . ltrim( $route, '/' ) );
		$plain  = add_query_arg( array_merge( array( 'rest_route' => '/livepull/v1/' . ltrim( $route, '/' ) ), $params ), $this->base . '/' );
		$order  = get_option( 'livepull_remote_url_style' ) === 'plain' ? array( 'plain' => $plain, 'pretty' => $pretty ) : array( 'pretty' => $pretty, 'plain' => $plain );
		return $order;
	}

	/** @return array|WP_Error */
	public function get( $route, array $params = array() ) {
		$last_error = null;
		foreach ( $this->urls( $route, $params ) as $style => $url ) {
			$res = $this->fetch( $url, array( 'headers' => $this->headers(), 'timeout' => 60, 'sslverify' => self::sslverify() ), 'api' );
			if ( is_wp_error( $res ) ) {
				// Network-level failure: the other URL style won't help.
				return new WP_Error( $res->get_error_code(), $res->get_error_message() . ' (' . $this->base . ')' );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$raw  = wp_remote_retrieve_body( $res );
			$body = json_decode( $raw, true );

			if ( ! is_array( $body ) ) {
				// HTML or empty: a cache, firewall or theme answered instead of WordPress's API.
				$snippet    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( substr( $raw, 0, 3000 ) ) ) );
				$last_error = new WP_Error(
					'livepull_not_json',
					sprintf(
						'The live site returned a web page instead of data (HTTP %d%s). This is usually a page cache or firewall answering instead of WordPress. Exclude /wp-json/ and URLs containing rest_route= from caching on live (host cache, cache plugin, Cloudflare "Cache Everything" rules).',
						$code,
						$snippet ? ': "' . substr( $snippet, 0, 80 ) . '…"' : ''
					),
					array( 'status' => $code, 'url' => $url )
				);
				continue;
			}
			if ( 404 === $code && isset( $body['code'] ) && 'rest_no_route' === $body['code'] ) {
				$last_error = new WP_Error( 'livepull_http_404', 'WordPress on live answered, but LivePull isn\'t running there. Install and activate LivePull on the live site too (if live still has the older "WP Sync" plugin, replace it with LivePull).', array( 'status' => 404 ) );
				continue;
			}
			if ( 200 !== $code ) {
				$msg = isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
				// Keep LivePull's own reason from live (e.g. "not found", "list expired") so callers can act on it.
				$err = isset( $body['code'] ) && is_string( $body['code'] ) && preg_match( '/^livepull_[a-z0-9_]+$/', $body['code'] ) ? $body['code'] : 'livepull_http_' . $code;
				return new WP_Error( $err, $msg, array( 'status' => $code, 'url' => $url ) );
			}
			update_option( 'livepull_remote_url_style', $style, false );
			return $body;
		}
		return $last_error;
	}

	/**
	 * Media may come from the live site itself or a public CDN, but never from a private or
	 * internal address (a compromised or spoofed live site must not be able to make this
	 * server fetch things from its own network).
	 */
	private function url_allowed( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host         = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$this->pinned = null;
		if ( $host && $host === strtolower( (string) wp_parse_url( $this->base, PHP_URL_HOST ) ) ) {
			return true;
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		// Also refuse link-local / reserved ranges WordPress's check lets through
		// (e.g. 169.254.169.254, which hands out credentials on cloud servers).
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$this->pinned = array( $host, $ip ); // Connect to the address that was checked, not a fresh lookup.
		}
		return true;
	}

	/** @return true|WP_Error */
	public function download( $url, $dest ) {
		if ( ! $this->url_allowed( $url ) ) {
			return new WP_Error( 'livepull_url', 'Refused to download from ' . wp_parse_url( $url, PHP_URL_HOST ) . ' (not the live site or a public address).' );
		}
		wp_mkdir_p( dirname( $dest ) );
		$tmp = $dest . '.livepull-part';
		$res = $this->fetch( $url, array( 'timeout' => 60, 'stream' => true, 'filename' => $tmp, 'sslverify' => self::sslverify() ), 'download' );
		if ( is_wp_error( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'livepull_download', 'HTTP ' . $code . ' for ' . LivePull_Config::scrub( $url ) );
		}
		return LivePull_Config::fs()->move( $tmp, $dest, true ) ? true : new WP_Error( 'livepull_download', 'Could not write ' . $dest );
	}

	/**
	 * GET with redirects followed one hop at a time, so every hop is checked:
	 * - 'api': only to the live site itself (http→https and www/non-www allowed), so the
	 *   key is never sent anywhere else.
	 * - 'download': every hop must pass url_allowed(); login details only go to the live host.
	 *
	 * @return array|WP_Error
	 */
	private function fetch( $url, array $args, $purpose ) {
		$args['redirection'] = 0;
		$api_headers         = isset( $args['headers'] ) ? $args['headers'] : array();
		for ( $hop = 0; $hop <= 5; $hop++ ) {
			if ( 'api' === $purpose ) {
				if ( 'https' === strtolower( (string) wp_parse_url( $this->base, PHP_URL_SCHEME ) ) && 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
					return new WP_Error( 'livepull_redirect', 'The live site redirected from https to plain http. LivePull won\'t send its key over an unencrypted connection, so it stopped. Check the live site\'s https setup.' );
				}
				if ( ! $this->same_site( $url ) ) {
					return new WP_Error( 'livepull_redirect', sprintf( 'The live site redirected to a different address (%s). LivePull only sends its key to the live site, so it stopped. Check the live site\'s address in the connection settings.', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
				}
				$args['headers'] = $api_headers;
			} else {
				if ( ! $this->url_allowed( $url ) ) {
					return new WP_Error( 'livepull_url', 'Refused to download from ' . wp_parse_url( $url, PHP_URL_HOST ) . ' (not the live site or a public address).' );
				}
				$args['headers'] = $this->download_headers( $url );
			}
			$pin = null;
			if ( 'download' === $purpose && $this->pinned ) {
				list( $host, $ip ) = $this->pinned;
				$port = wp_parse_url( $url, PHP_URL_PORT );
				$port = $port ? $port : ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 );
				$pin  = function ( $handle ) use ( $host, $port, $ip ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the checked address (DNS rebinding).
				};
				add_action( 'http_api_curl', $pin );
			}
			$res = wp_remote_get( $url, $args );
			if ( $pin ) {
				remove_action( 'http_api_curl', $pin );
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code     = (int) wp_remote_retrieve_response_code( $res );
			$location = wp_remote_retrieve_header( $res, 'location' );
			if ( $code < 300 || $code > 399 || ! $location ) {
				return $res;
			}
			if ( is_array( $location ) ) {
				$location = end( $location );
			}
			$url = WP_Http::make_absolute_url( $location, $url );
		}
		return new WP_Error( 'livepull_redirect', 'Too many redirects from ' . wp_parse_url( $url, PHP_URL_HOST ) . '.' );
	}

	/**
	 * Same site as live: same host, ignoring a leading "www.". http may move up to https but
	 * never down: if live's address is https, a redirect to http is refused, so the key and any
	 * basic-auth details are never sent unencrypted.
	 */
	private function same_site( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' === strtolower( (string) wp_parse_url( $this->base, PHP_URL_SCHEME ) ) && 'https' !== $scheme ) {
			return false;
		}
		$strip  = function ( $h ) {
			$h = strtolower( (string) $h );
			return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
		};
		$a = $strip( wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $a
			&& $a === $strip( wp_parse_url( $this->base, PHP_URL_HOST ) )
			&& wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $this->base, PHP_URL_PORT );
	}

	private static function sslverify() {
		return defined( 'LIVEPULL_SSL_VERIFY' ) ? (bool) LIVEPULL_SSL_VERIFY : true;
	}

	private function download_headers( $url ) {
		// Only send basic auth to the live host itself, never to a CDN.
		// Never over plain http when live's address is https.
		if ( defined( 'LIVEPULL_REMOTE_BASIC_AUTH' ) && LIVEPULL_REMOTE_BASIC_AUTH
			&& wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $this->base, PHP_URL_HOST )
			&& ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || 'https' !== strtolower( (string) wp_parse_url( $this->base, PHP_URL_SCHEME ) ) ) ) {
			return array( 'Authorization' => 'Basic ' . base64_encode( LIVEPULL_REMOTE_BASIC_AUTH ) ); // phpcs:ignore
		}
		return array();
	}
}

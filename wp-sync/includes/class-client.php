<?php
defined( 'ABSPATH' ) || exit;

/** Talks to the live site's endpoints. */
final class WPSync_Client {

	private $base;
	private $secret;

	public function __construct( $base, $secret ) {
		$this->base   = untrailingslashit( $base );
		$this->secret = $secret;
	}

	private function headers() {
		$h = array(
			'X-WPSync-Key'  => $this->secret,
			'Accept'        => 'application/json',
			'Cache-Control' => 'no-cache, no-store',
			'Pragma'        => 'no-cache',
		);
		if ( defined( 'WPSYNC_REMOTE_BASIC_AUTH' ) && WPSYNC_REMOTE_BASIC_AUTH ) {
			$h['Authorization'] = 'Basic ' . base64_encode( WPSYNC_REMOTE_BASIC_AUTH ); // phpcs:ignore
		}
		return $h;
	}

	/**
	 * The two ways to reach the REST API. /wp-json/ is tried first because page caches
	 * almost always skip it; ?rest_route= works even without pretty permalinks.
	 */
	private function urls( $route, array $params ) {
		$params['_wpsync'] = (string) microtime( true ); // Defeat caches that ignore headers.
		$pretty = add_query_arg( $params, $this->base . '/wp-json/wpsync/v1/' . ltrim( $route, '/' ) );
		$plain  = add_query_arg( array_merge( array( 'rest_route' => '/wpsync/v1/' . ltrim( $route, '/' ) ), $params ), $this->base . '/' );
		$order  = get_option( 'wpsync_remote_url_style' ) === 'plain' ? array( 'plain' => $plain, 'pretty' => $pretty ) : array( 'pretty' => $pretty, 'plain' => $plain );
		return $order;
	}

	/** @return array|WP_Error */
	public function get( $route, array $params = array() ) {
		$last_error = null;
		foreach ( $this->urls( $route, $params ) as $style => $url ) {
			$res = wp_remote_get( $url, array( 'headers' => $this->headers(), 'timeout' => 60, 'sslverify' => self::sslverify(), 'redirection' => 3 ) );
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
					'wpsync_not_json',
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
				$last_error = new WP_Error( 'wpsync_http_404', 'WordPress on live answered, but WP Sync isn\'t running there. Check wp-sync.php and the wp-sync/ folder are both in wp-content/mu-plugins/ on live.', array( 'status' => 404 ) );
				continue;
			}
			if ( 200 !== $code ) {
				$msg = isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
				return new WP_Error( 'wpsync_http_' . $code, $msg, array( 'status' => $code, 'url' => $url ) );
			}
			update_option( 'wpsync_remote_url_style', $style, false );
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
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
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
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/** @return true|WP_Error */
	public function download( $url, $dest ) {
		if ( ! $this->url_allowed( $url ) ) {
			return new WP_Error( 'wpsync_url', 'Refused to download from ' . wp_parse_url( $url, PHP_URL_HOST ) . ' (not the live site or a public address).' );
		}
		wp_mkdir_p( dirname( $dest ) );
		$tmp = $dest . '.wpsync-part';
		$res = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 3, 'stream' => true, 'filename' => $tmp, 'headers' => $this->download_headers( $url ), 'sslverify' => self::sslverify() ) );
		if ( is_wp_error( $res ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'wpsync_download', 'HTTP ' . $code . ' for ' . $url );
		}
		return rename( $tmp, $dest ) ? true : new WP_Error( 'wpsync_download', 'Could not write ' . $dest );
	}

	private static function sslverify() {
		return defined( 'WPSYNC_SSL_VERIFY' ) ? (bool) WPSYNC_SSL_VERIFY : true;
	}

	private function download_headers( $url ) {
		// Only send basic auth to the live host itself, never to a CDN.
		if ( defined( 'WPSYNC_REMOTE_BASIC_AUTH' ) && WPSYNC_REMOTE_BASIC_AUTH
			&& wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $this->base, PHP_URL_HOST ) ) {
			return array( 'Authorization' => 'Basic ' . base64_encode( WPSYNC_REMOTE_BASIC_AUTH ) ); // phpcs:ignore
		}
		return array();
	}
}

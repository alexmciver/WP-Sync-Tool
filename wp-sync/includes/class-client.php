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
		$h = array( 'X-WPSync-Key' => $this->secret, 'Accept' => 'application/json' );
		if ( defined( 'WPSYNC_REMOTE_BASIC_AUTH' ) && WPSYNC_REMOTE_BASIC_AUTH ) {
			$h['Authorization'] = 'Basic ' . base64_encode( WPSYNC_REMOTE_BASIC_AUTH ); // phpcs:ignore
		}
		return $h;
	}

	/** @return array|WP_Error */
	public function get( $route, array $params = array() ) {
		$url = add_query_arg(
			array_merge( array( 'rest_route' => '/wpsync/v1/' . ltrim( $route, '/' ) ), $params ),
			$this->base . '/'
		);
		$res = wp_remote_get( $url, array( 'headers' => $this->headers(), 'timeout' => 120, 'sslverify' => self::sslverify() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;
			return new WP_Error( 'wpsync_http_' . $code, $msg, array( 'status' => $code, 'url' => $url ) );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'wpsync_bad_json', 'Live site did not return JSON. Is the plugin installed there, and is the REST API reachable?' );
		}
		return $body;
	}

	/** @return true|WP_Error */
	public function download( $url, $dest ) {
		wp_mkdir_p( dirname( $dest ) );
		$tmp = $dest . '.wpsync-part';
		$res = wp_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp, 'headers' => $this->download_headers( $url ), 'sslverify' => self::sslverify() ) );
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

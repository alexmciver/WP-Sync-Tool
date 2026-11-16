<?php
/**
 * Helpers for tests/scenarios/*.php (run with `wp eval-file <scenario> <phase>`).
 * Phases: create (live) → verify (local) → local_change (local) → change (live)
 *         → verify_change (local) → verify_full (local, after a full copy).
 */
if ( defined( 'WPSYNC_TEST_LIB' ) ) {
	return;
}
define( 'WPSYNC_TEST_LIB', 1 );

const T_LIVE  = 'localhost:8101';
const T_LOCAL = 'localhost:8102';

function t_ok( $cond, $msg ) {
	echo ( $cond ? 'PASS' : 'FAIL' ) . ': ' . $msg . "\n";
	return (bool) $cond;
}

function t_eq( $got, $want, $msg ) {
	$ok = ( $got == $want ); // phpcs:ignore -- loose on purpose ("12" == 12).
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $msg . ( $ok ? '' : ' (expected ' . wp_json_encode( $want ) . ', got ' . wp_json_encode( $got ) . ')' ) . "\n";
	return $ok;
}

function t_save( $name, array $data ) {
	file_put_contents( getenv( 'WPSYNC_TEST_STATE' ) . "/{$name}.json", wp_json_encode( $data ) );
}

function t_load( $name ) {
	$f = getenv( 'WPSYNC_TEST_STATE' ) . "/{$name}.json";
	return file_exists( $f ) ? json_decode( file_get_contents( $f ), true ) : array();
}

/** Sync ID of a live object (call on live). */
function t_sid( $id, $type = 'post' ) {
	return 'post' === $type ? WPSync_Ids::for_post( $id ) : WPSync_Ids::for_term( $id );
}

/** Local ID for a live sync ID (call on local). */
function t_local( $sid, $type = 'post' ) {
	$m = WPSync_Ids::map( $type );
	return isset( $m[ $sid ] ) ? (int) $m[ $sid ] : 0;
}

/** Map every live ID in $ids (sid list) to local IDs. */
function t_locals( array $sids, $type = 'post' ) {
	return array_map( function ( $s ) use ( $type ) {
		return t_local( $s, $type );
	}, $sids );
}

/** A real image in the media library (all sizes generated). */
function t_image( $title, $w = 900, $h = 600 ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	ob_start();
	imagejpeg( $im, null, 85 );
	$bits = ob_get_clean();
	$up   = wp_upload_bits( sanitize_title( $title ) . '-' . wp_rand( 1000, 9999 ) . '.jpg', null, $bits );
	$id   = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $up['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
	return $id;
}

function t_url( $id ) {
	return wp_get_attachment_url( $id );
}

/** Live URLs must be gone, local ones present. */
function t_urls_local( $text, $msg ) {
	$text = is_string( $text ) ? $text : wp_json_encode( $text );
	$text = str_replace( '\/', '/', $text );
	return t_ok( false === strpos( $text, T_LIVE ), $msg . ': no live URLs left' );
}

/** Fetch a local page over HTTP. @return array{code:int, body:string} */
function t_fetch( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 3 ) );
	if ( is_wp_error( $r ) ) {
		return array( 'code' => 0, 'body' => $r->get_error_message() );
	}
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'body' => wp_remote_retrieve_body( $r ) );
}

/** The page renders on local and shows local images, no live URLs, no PHP errors. */
function t_renders( $post_id, $what ) {
	$r = t_fetch( get_permalink( $post_id ) );
	t_ok( 200 === $r['code'], "$what renders (HTTP 200)" );
	t_ok( false === stripos( $r['body'], 'Fatal error' ) && false === stripos( $r['body'], 'Warning</b>' ), "$what renders without PHP errors" );
	t_ok( false === strpos( $r['body'], T_LIVE . '/wp-content/uploads' ), "$what shows no images from live" );
	return $r['body'];
}

function t_plugin_active( $file ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( $file );
}

/** Skip a scenario when its plugin isn't installed. */
function t_needs( $file, $name ) {
	if ( t_plugin_active( $file ) ) {
		return true;
	}
	echo "SKIP: $name not installed\n";
	return false;
}

/** Decode builder meta that may be slashed JSON, JSON or serialized. */
function t_meta( $post_id, $key ) {
	$v = get_post_meta( $post_id, $key, true );
	if ( is_string( $v ) ) {
		$d = json_decode( $v, true );
		if ( null !== $d ) {
			return $d;
		}
	}
	return $v;
}

/** Collect every value under keys named $key anywhere in a structure. */
function t_find( $data, $key, &$out = array() ) {
	if ( is_object( $data ) ) {
		$data = get_object_vars( $data );
	}
	if ( is_array( $data ) ) {
		foreach ( $data as $k => $v ) {
			if ( $k === $key ) {
				$out[] = $v;
			}
			t_find( $v, $key, $out );
		}
	}
	return $out;
}

<?php
// Conflicts: edited on both sides → left alone and reported; resolving with live's version works.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$p = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Conflict page', 'post_content' => 'original' ) );
	$q = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Local-only edit page', 'post_content' => 'original' ) );
	t_save( 'conflict', array( 'p' => t_sid( $p ), 'q' => t_sid( $q ) ) );
}

if ( 'local_change' === $phase ) {
	$s = t_load( 'conflict' );
	wp_update_post( array( 'ID' => t_local( $s['p'] ), 'post_content' => 'edited locally' ) );
	wp_update_post( array( 'ID' => t_local( $s['q'] ), 'post_content' => 'edited locally only' ) );
}

if ( 'change' === $phase ) {
	$s = t_load( 'conflict' );
	wp_update_post( array( 'ID' => t_local( $s['p'] ), 'post_content' => 'edited on live' ) );
}

if ( 'verify_change' === $phase ) {
	$s = t_load( 'conflict' );
	$p = t_local( $s['p'] );
	t_eq( get_post_field( 'post_content', $p ), 'edited locally', 'edited on both sides: local version kept' );
	t_ok( isset( LivePull_Puller::conflicts()[ 'post:' . $s['p'] ] ), 'reported as a conflict' );
	t_eq( get_post_field( 'post_content', t_local( $s['q'] ) ), 'edited locally only', 'local-only edit untouched' );
	$r = LivePull_Puller::take( 'post:' . $s['p'] );
	t_ok( ! is_wp_error( $r ), 'resolving with live\'s version succeeds' );
	t_eq( get_post_field( 'post_content', $p ), 'edited on live', 'live version applied' );
	t_ok( ! isset( LivePull_Puller::conflicts()[ 'post:' . $s['p'] ] ), 'conflict cleared' );
}

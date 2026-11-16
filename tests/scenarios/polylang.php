<?php
// Polylang: an English page and its French translation stay linked to each other.
require getenv( 'WPSYNC_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
if ( ! t_needs( 'polylang/polylang.php', 'Polylang' ) ) {
	return;
}

if ( 'create' === $phase ) {
	if ( ! PLL()->model->get_language( 'en' ) ) {
		PLL()->model->add_language( array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_GB', 'rtl' => 0, 'term_group' => 0 ) );
	}
	if ( ! PLL()->model->get_language( 'fr' ) ) {
		PLL()->model->add_language( array( 'name' => 'Français', 'slug' => 'fr', 'locale' => 'fr_FR', 'rtl' => 0, 'term_group' => 1 ) );
	}
	$en = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Polylang hello' ) );
	$fr = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Polylang bonjour' ) );
	pll_set_post_language( $en, 'en' );
	pll_set_post_language( $fr, 'fr' );
	pll_save_post_translations( array( 'en' => $en, 'fr' => $fr ) );
	t_save( 'pll', array( 'en' => t_sid( $en ), 'fr' => t_sid( $fr ) ) );
}

if ( 'verify' === $phase ) {
	$s  = t_load( 'pll' );
	$en = t_local( $s['en'] ); $fr = t_local( $s['fr'] );
	t_ok( $en && $fr, 'both language versions pulled' );
	if ( function_exists( 'pll_get_post_language' ) && $en && $fr ) {
		t_eq( pll_get_post_language( $en ), 'en', 'English page keeps its language' );
		t_eq( pll_get_post_language( $fr ), 'fr', 'French page keeps its language' );
		t_eq( (int) pll_get_post( $en, 'fr' ), $fr, 'English page links to the local French translation' );
		t_eq( (int) pll_get_post( $fr, 'en' ), $en, 'French page links to the local English translation' );
	}
}

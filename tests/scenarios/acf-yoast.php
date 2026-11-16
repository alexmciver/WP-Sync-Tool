<?php
// ACF fields (image, relationship, taxonomy, link) and Yoast SEO (titles, primary category, social image).
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$img  = t_image( 'ACF hero' ); $og = t_image( 'Yoast social' );
	$rel  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'ACF related page' ) );
	$c1   = wp_insert_term( 'ACF cat one', 'category' ); $c2 = wp_insert_term( 'ACF cat two', 'category' );
	$post = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'ACF and Yoast post', 'post_category' => array( $c1['term_id'], $c2['term_id'] ) ) );
	if ( function_exists( 'acf_add_local_field_group' ) ) {
		// Same field group exists on both sites (normally registered by the theme / acf-json).
		update_post_meta( $post, 'hero', $img );            update_post_meta( $post, '_hero', 'field_t_hero' );
		update_post_meta( $post, 'related', array( (string) $rel ) ); update_post_meta( $post, '_related', 'field_t_related' );
		update_post_meta( $post, 'topics', array( $c2['term_id'] ) ); update_post_meta( $post, '_topics', 'field_t_topics' );
	}
	update_post_meta( $post, '_yoast_wpseo_title', 'Custom SEO title' );
	update_post_meta( $post, '_yoast_wpseo_metadesc', 'Custom description with ' . home_url( '/' ) );
	update_post_meta( $post, '_yoast_wpseo_primary_category', $c2['term_id'] );
	update_post_meta( $post, '_yoast_wpseo_opengraph-image', t_url( $og ) );
	update_post_meta( $post, '_yoast_wpseo_opengraph-image-id', $og );
	clean_post_cache( $post );
	$t = wp_remote_get( get_permalink( $post ) );
	preg_match( '#<title>(.*?)</title>#s', wp_remote_retrieve_body( $t ), $tm );
	t_save( 'acf', array( 'img' => t_sid( $img ), 'og' => t_sid( $og ), 'rel' => t_sid( $rel ), 'c2' => t_sid( $c2['term_id'], 'term' ), 'post' => t_sid( $post ), 'live_id' => $post,
		'og_id_kept' => (bool) get_post_meta( $post, '_yoast_wpseo_opengraph-image-id', true ), 'live_title' => isset( $tm[1] ) ? trim( $tm[1] ) : '' ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'acf' );
	$post = t_local( $s['post'] );
	t_ok( $post > 0, 'post pulled' );
	if ( function_exists( 'acf_add_local_field_group' ) ) {
		t_eq( (int) get_post_meta( $post, 'hero', true ), t_local( $s['img'] ), 'ACF image field points at local image' );
		t_eq( array_map( 'intval', (array) get_post_meta( $post, 'related', true ) ), array( t_local( $s['rel'] ) ), 'ACF relationship points at local page' );
		t_eq( array_map( 'intval', (array) get_post_meta( $post, 'topics', true ) ), array( t_local( $s['c2'], 'term' ) ), 'ACF taxonomy field points at local category' );
	}
	t_eq( get_post_meta( $post, '_yoast_wpseo_title', true ), 'Custom SEO title', 'Yoast SEO title' );
	t_urls_local( get_post_meta( $post, '_yoast_wpseo_metadesc', true ), 'Yoast description' );
	t_eq( (int) get_post_meta( $post, '_yoast_wpseo_primary_category', true ), t_local( $s['c2'], 'term' ), 'Yoast primary category points at local category' );
	if ( ! empty( $s['og_id_kept'] ) ) {
		t_eq( (int) get_post_meta( $post, '_yoast_wpseo_opengraph-image-id', true ), t_local( $s['og'] ), 'Yoast social image ID points at local image' );
	}
	t_eq( get_post_meta( $post, '_yoast_wpseo_opengraph-image', true ), t_url( t_local( $s['og'] ) ), 'Yoast social image URL is local' );
	$html = t_renders( $post, 'ACF/Yoast post' );
	preg_match( '#<title>(.*?)</title>#s', $html, $tm );
	$live = wp_remote_retrieve_body( wp_remote_get( 'http://' . T_LIVE . '/?p=' . $s['live_id'] ) );
	preg_match( '#<title>(.*?)</title>#s', $live, $lm );
	t_eq( isset( $tm[1] ) ? trim( $tm[1] ) : '', isset( $lm[1] ) ? trim( $lm[1] ) : 'live', 'page <title> matches live' );
}

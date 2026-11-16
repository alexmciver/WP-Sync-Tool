<?php
// Divi 4 (shortcodes) and Divi 5 (blocks) content formats, Divi meta and Theme Builder records.
// Divi is a paid theme, so this checks the stored format arrives intact and re-pointed; it
// doesn't render it. (Install Divi in the test sites to check rendering too.)
require getenv( 'WPSYNC_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$a = t_image( 'Divi A' ); $b = t_image( 'Divi B' ); $c = t_image( 'Divi C' );
	$contact = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Divi contact' ) );
	$d4 = '[et_pb_section fb_built="1" background_image="' . t_url( $c ) . '" _builder_version="4.27.4"][et_pb_row _builder_version="4.27.4"][et_pb_column type="4_4"]'
		. '[et_pb_image src="' . t_url( $a ) . '" title_text="Divi A" _builder_version="4.27.4"][/et_pb_image]'
		. '[et_pb_gallery gallery_ids="' . $a . ',' . $b . '" fullwidth="off" _builder_version="4.27.4"][/et_pb_gallery]'
		. '[et_pb_text _builder_version="4.27.4"]<p>Visit <a href="' . get_permalink( $contact ) . '">contact</a></p>[/et_pb_text]'
		. '[et_pb_button button_url="' . get_permalink( $contact ) . '" button_text="Contact" _builder_version="4.27.4"][/et_pb_button]'
		. '[/et_pb_column][/et_pb_row][/et_pb_section]';
	$p4 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Divi 4 page', 'post_content' => $d4 ) );
	update_post_meta( $p4, '_et_pb_use_builder', 'on' );
	update_post_meta( $p4, '_et_pb_page_layout', 'et_full_width_page' );
	update_post_meta( $p4, '_et_pb_built_for_post_type', 'page' );
	update_post_meta( $p4, '_et_builder_version', 'VB|Divi|4.27.4' );
	$d5 = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"image":{"url":"' . t_url( $c ) . '"}}}}}}} --><!-- wp:divi/row --><!-- wp:divi/column -->'
		. '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"' . t_url( $b ) . '","id":"' . $b . '","alt":"B"}}}}} /-->'
		. '<!-- wp:divi/gallery {"image":{"advanced":{"galleryIds":{"desktop":{"value":"' . $a . ',' . $c . '"}}}}} /-->'
		. '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
	$p5 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Divi 5 page', 'post_content' => wp_slash( $d5 ) ) );
	update_post_meta( $p5, '_et_pb_use_builder', 'on' );
	// Theme Builder header: developer-owned, so not pulled by normal pulls.
	$tb = wp_insert_post( array( 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Divi header', 'post_content' => '[et_pb_section][/et_pb_section]' ) );
	t_save( 'divi', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'p4' => t_sid( $p4 ), 'p5' => t_sid( $p5 ), 'tb' => t_sid( $tb ), 'contact' => t_sid( $contact ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'divi' );
	list( $a, $b, $c, $p4, $p5, $contact ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['c'] ), t_local( $s['p4'] ), t_local( $s['p5'] ), t_local( $s['contact'] ) );
	t_ok( $p4 && $p5 && $a && $b && $c, 'Divi pages and images pulled' );
	$d4 = get_post_field( 'post_content', $p4 );
	t_ok( false !== strpos( $d4, 'gallery_ids="' . $a . ',' . $b . '"' ), 'Divi 4 gallery IDs point at local images' );
	t_ok( false !== strpos( $d4, 'src="' . t_url( $a ) . '"' ), 'Divi 4 image module uses local URL' );
	t_ok( false !== strpos( $d4, 'background_image="' . t_url( $c ) . '"' ), 'Divi 4 section background uses local URL' );
	t_ok( false !== strpos( $d4, 'button_url="' . get_permalink( $contact ) . '"' ), 'Divi 4 button links to local page' );
	t_urls_local( $d4, 'Divi 4 content' );
	t_ok( 0 === substr_count( $d4, '[et_pb_' ) - substr_count( $d4, '[/et_pb_' ), 'Divi 4 shortcodes intact (balanced)' );
	t_eq( get_post_meta( $p4, '_et_pb_use_builder', true ), 'on', 'Divi builder flag kept' );
	t_eq( get_post_meta( $p4, '_et_pb_page_layout', true ), 'et_full_width_page', 'Divi page layout kept' );
	$d5 = get_post_field( 'post_content', $p5 );
	t_ok( false !== strpos( $d5, '"id":"' . $b . '"' ), 'Divi 5 image module points at local image' );
	t_ok( false !== strpos( $d5, '"value":"' . $a . ',' . $c . '"' ), 'Divi 5 gallery IDs point at local images' );
	t_urls_local( $d5, 'Divi 5 content' );
	t_ok( is_array( parse_blocks( $d5 ) ) && count( parse_blocks( $d5 ) ) > 0, 'Divi 5 blocks still parse' );
	t_eq( t_local( $s['tb'] ), 0, 'Divi Theme Builder layout NOT pulled by a normal pull (developer-owned)' );
}

if ( 'verify_full' === $phase ) {
	$s = t_load( 'divi' );
	t_ok( t_local( $s['tb'] ) > 0, 'Divi Theme Builder layout copied by Copy everything' );
}

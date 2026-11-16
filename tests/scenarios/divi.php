<?php
// Divi 4 (shortcodes) and Divi 5 (blocks) content, Divi page settings, Theme Builder and
// Theme Options. Without Divi installed this checks the stored data arrives intact and
// re-pointed. With Divi active (LIVEPULL_TEST_THEME=Divi.zip) it also checks the pages
// actually render on local, that live edits come through after local pages have been
// viewed (Divi writes caches on view), and that the Theme Builder header shows after
// Copy everything.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
$divi  = 'Divi' === get_template() && function_exists( 'et_setup_theme' );

if ( 'create' === $phase ) {
	$a = t_image( 'Divi A' ); $b = t_image( 'Divi B' ); $c = t_image( 'Divi C' );
	$contact = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Divi contact' ) );
	$d4 = '[et_pb_section fb_built="1" background_image="' . t_url( $c ) . '" _builder_version="4.27.4"][et_pb_row _builder_version="4.27.4"][et_pb_column type="4_4" _builder_version="4.27.4"]'
		. '[et_pb_image src="' . t_url( $a ) . '" title_text="Divi A" _builder_version="4.27.4"][/et_pb_image]'
		. '[et_pb_gallery gallery_ids="' . $a . ',' . $b . '" fullwidth="off" _builder_version="4.27.4"][/et_pb_gallery]'
		. '[et_pb_text _builder_version="4.27.4"]<p>Visit <a href="' . get_permalink( $contact ) . '">contact</a> DIVI-TEXT-ORIGINAL</p>[/et_pb_text]'
		. '[et_pb_button button_url="' . get_permalink( $contact ) . '" button_text="Contact us" _builder_version="4.27.4"][/et_pb_button]'
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

	// A Divi Library layout and a global Theme Builder header (developer-owned: Copy everything only).
	$lib = wp_insert_post( array( 'post_type' => 'et_pb_layout', 'post_status' => 'publish', 'post_title' => 'Divi library layout', 'post_content' => '[et_pb_section][/et_pb_section]' ) );
	$hdr = wp_insert_post( array( 'post_type' => 'et_header_layout', 'post_status' => 'publish', 'post_title' => 'Divi header',
		'post_content' => '[et_pb_section _builder_version="4.27.4"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>DIVI-TB-HEADER</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' ) );
	update_post_meta( $hdr, '_et_pb_use_builder', 'on' );
	$tb_live = false;
	if ( $divi && function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ) {
		$tb  = et_theme_builder_get_theme_builder_post_id( true, true );
		$tpl = wp_insert_post( array( 'post_type' => 'et_template', 'post_status' => 'publish', 'post_title' => 'Default Website Template' ) );
		foreach ( array( '_et_default' => '1', '_et_enabled' => '1', '_et_header_layout_id' => $hdr, '_et_header_layout_enabled' => '1',
			'_et_body_layout_id' => '0', '_et_body_layout_enabled' => '1', '_et_footer_layout_id' => '0', '_et_footer_layout_enabled' => '1' ) as $k => $v ) {
			update_post_meta( $tpl, $k, $v );
		}
		add_post_meta( $tb, '_et_template', $tpl );
		// Theme Options: a developer setting, also Copy everything only.
		$opts = get_option( 'et_divi', array() );
		$opts['accent_color'] = '#ab1234';
		update_option( 'et_divi', $opts );
		$r = t_fetch( get_permalink( $contact ) );
		$tb_live = false !== strpos( $r['body'], 'DIVI-TB-HEADER' );
		t_ok( $tb_live, 'test setup: Theme Builder header shows on live' );
	}
	t_save( 'divi', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'p4' => t_sid( $p4 ), 'p5' => t_sid( $p5 ),
		'tb' => t_sid( $hdr ), 'lib' => t_sid( $lib ), 'contact' => t_sid( $contact ), 'tb_live' => $tb_live ) );
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
	t_eq( t_local( $s['lib'] ), 0, 'Divi Library layout NOT pulled by a normal pull (developer-owned)' );

	if ( $divi ) {
		$html = t_renders( $p4, 'Divi 4 page' );
		t_ok( false !== strpos( $html, 'et_pb_section' ) && false !== strpos( $html, 'et_pb_image' ), 'Divi builds the page from its modules' );
		t_ok( false === strpos( $html, '[et_pb_' ), 'no raw Divi shortcodes on the page' );
		t_ok( false !== strpos( $html, 'DIVI-TEXT-ORIGINAL' ), 'Divi text module shows' );
		t_ok( false !== strpos( $html, t_url( $a ) ), 'Divi image module shows the local image' );
		t_ok( false !== strpos( $html, 'et_pb_gallery' ) && false !== strpos( $html, wp_get_attachment_url( $b ) ), 'Divi gallery shows the local images' );
		t_ok( false !== strpos( $html, 'href="' . get_permalink( $contact ) . '"' ), 'Divi button links to the local page' );
		t_ok( false !== strpos( $html, 'et_full_width_page' ), 'Divi full-width layout applied' );
		t_ok( false === strpos( $html, 'DIVI-TB-HEADER' ), 'live Theme Builder header not applied by a normal pull' );
		t_renders( $contact, 'Divi plain page' );
		// View it again (Divi writes its caches on view) so the change phase proves views aren't edits.
		t_fetch( get_permalink( $p4 ) );
	}
}

if ( 'change' === $phase ) {
	$s  = t_load( 'divi' );
	$p4 = t_local( $s['p4'] );
	wp_update_post( array( 'ID' => $p4, 'post_content' => str_replace( 'DIVI-TEXT-ORIGINAL', 'DIVI-TEXT-EDITED-ON-LIVE', get_post_field( 'post_content', $p4 ) ) ) );
}

if ( 'verify_change' === $phase ) {
	$s  = t_load( 'divi' );
	$p4 = t_local( $s['p4'] );
	t_ok( false !== strpos( get_post_field( 'post_content', $p4 ), 'DIVI-TEXT-EDITED-ON-LIVE' ), 'Divi edit on live came through (viewing on local is not an edit)' );
	if ( $divi ) {
		$html = t_renders( $p4, 'Divi 4 page after edit' );
		t_ok( false !== strpos( $html, 'DIVI-TEXT-EDITED-ON-LIVE' ), 'edited Divi page shows the new text (cache cleared)' );
	}
}

if ( 'verify_full' === $phase ) {
	$s = t_load( 'divi' );
	t_ok( t_local( $s['tb'] ) > 0, 'Divi Theme Builder layout copied by Copy everything' );
	t_ok( t_local( $s['lib'] ) > 0, 'Divi Library layout copied by Copy everything' );
	if ( $divi ) {
		$opts = get_option( 'et_divi', array() );
		t_eq( isset( $opts['accent_color'] ) ? $opts['accent_color'] : '', '#ab1234', 'Divi Theme Options copied' );
		$html = t_renders( t_local( $s['p4'] ), 'Divi 4 page after full copy' );
		t_ok( false !== strpos( $html, 'DIVI-TEXT-EDITED-ON-LIVE' ), 'Divi page content after full copy' );
		if ( ! empty( $s['tb_live'] ) ) {
			$c = t_fetch( get_permalink( t_local( $s['contact'] ) ) );
			t_ok( false !== strpos( $c['body'], 'DIVI-TB-HEADER' ), 'Divi Theme Builder header shows on local after full copy' );
		}
	}
}

<?php
// Elementor: _elementor_data JSON (image, gallery, section background, links) + page settings.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
if ( ! t_needs( 'elementor/elementor.php', 'Elementor' ) ) {
	return;
}

if ( 'create' === $phase ) {
	$a = t_image( 'Elementor A' ); $b = t_image( 'Elementor B' ); $c = t_image( 'Elementor C' ); $d = t_image( 'Elementor D' );
	$target = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor target' ) );
	$data = array(
		array( 'id' => 'sec1', 'elType' => 'section', 'settings' => array( 'background_background' => 'classic', 'background_image' => array( 'url' => t_url( $c ), 'id' => $c, 'size' => '' ) ), 'elements' => array(
			array( 'id' => 'col1', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'elements' => array(
				array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => t_url( $a ), 'id' => $a, 'size' => '', 'alt' => 'A', 'source' => 'library' ) ), 'elements' => array() ),
				array( 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'image-gallery', 'settings' => array( 'wp_gallery' => array( array( 'id' => $b, 'url' => t_url( $b ) ), array( 'id' => $c, 'url' => t_url( $c ) ) ) ), 'elements' => array() ),
				array( 'id' => 'w3', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>See <a href="' . get_permalink( $target ) . '">target</a> <img class="wp-image-' . $a . '" src="' . t_url( $a ) . '"></p>' ), 'elements' => array() ),
				array( 'id' => 'w4', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Go', 'link' => array( 'url' => get_permalink( $target ), 'is_external' => '', 'nofollow' => '' ) ), 'elements' => array() ),
			) ),
		) ),
	);
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor page', 'post_content' => 'fallback' ) );
	update_post_meta( $page, '_elementor_edit_mode', 'builder' );
	update_post_meta( $page, '_elementor_template_type', 'wp-page' );
	update_post_meta( $page, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
	update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	update_post_meta( $page, '_elementor_page_settings', array( 'background_background' => 'classic', 'background_image' => array( 'url' => t_url( $d ), 'id' => $d ) ) );
	t_save( 'elementor', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'd' => t_sid( $d ), 'page' => t_sid( $page ), 'target' => t_sid( $target ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'elementor' );
	list( $a, $b, $c, $d, $page, $target ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['c'] ), t_local( $s['d'] ), t_local( $s['page'] ), t_local( $s['target'] ) );
	t_ok( $page && $a && $b && $c && $d, 'Elementor page and images pulled' );
	$raw  = get_post_meta( $page, '_elementor_data', true );
	$data = json_decode( $raw, true );
	t_ok( is_array( $data ), 'Elementor data is still valid JSON' );
	$w = $data[0]['elements'][0]['elements'];
	t_eq( (int) $w[0]['settings']['image']['id'], $a, 'image widget points at local image' );
	t_eq( $w[0]['settings']['image']['url'], t_url( $a ), 'image widget uses local URL' );
	t_eq( array_map( 'intval', wp_list_pluck( $w[1]['settings']['wp_gallery'], 'id' ) ), array( $b, $c ), 'gallery widget points at local images' );
	t_eq( (int) $data[0]['settings']['background_image']['id'], $c, 'section background points at local image' );
	t_ok( false !== strpos( $w[2]['settings']['editor'], 'wp-image-' . $a ), 'text editor image class points at local image' );
	t_eq( $w[3]['settings']['link']['url'], get_permalink( $target ), 'button links to local page' );
	t_urls_local( $raw, 'Elementor data' );
	$ps = get_post_meta( $page, '_elementor_page_settings', true );
	t_eq( (int) ( is_array( $ps ) ? $ps['background_image']['id'] : 0 ), $d, 'page settings background points at local image' );
	t_urls_local( $ps, 'Elementor page settings' );
	$html = t_renders( $page, 'Elementor page' );
	t_ok( false !== strpos( $html, 'elementor-widget-image' ), 'Elementor renders the image widget' );
	t_ok( false !== strpos( $html, T_LOCAL . '/wp-content/uploads' ), 'Elementor page shows local images' );
}

if ( 'change' === $phase ) {
	$s    = t_load( 'elementor' );
	$page = t_local( $s['page'] );
	$data = json_decode( get_post_meta( $page, '_elementor_data', true ), true );
	$data[0]['elements'][0]['elements'][] = array( 'id' => 'w9', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Added on live' ), 'elements' => array() );
	update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
}

if ( 'verify_change' === $phase ) {
	$s = t_load( 'elementor' );
	t_ok( false !== strpos( get_post_meta( t_local( $s['page'] ), '_elementor_data', true ), 'Added on live' ), 'Elementor edit on live came through' );
}

if ( 'verify_full' === $phase ) {
	$s = t_load( 'elementor' );
	$html = t_renders( t_local( $s['page'] ), 'Elementor page after full copy' );
	t_ok( false !== strpos( $html, 'elementor-widget-image' ), 'Elementor renders after full copy' );
}

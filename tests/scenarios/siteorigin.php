<?php
// SiteOrigin Page Builder: panels_data with SiteOrigin image widget + WordPress media image widget.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
if ( ! t_needs( 'siteorigin-panels/siteorigin-panels.php', 'SiteOrigin Page Builder' ) ) {
	return;
}

if ( 'create' === $phase ) {
	$a = t_image( 'SO A' ); $b = t_image( 'SO B' );
	$panels = array(
		'widgets'    => array(
			array( 'image' => $a, 'image_fallback' => '', 'size' => 'full', 'title' => 'SO image', 'url' => '', 'panels_info' => array( 'class' => 'SiteOrigin_Widget_Image_Widget', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => 'so-w1', 'style' => array() ) ),
			array( 'attachment_id' => $b, 'url' => t_url( $b ), 'size' => 'full', 'title' => '', 'panels_info' => array( 'class' => 'WP_Widget_Media_Image', 'grid' => 0, 'cell' => 0, 'id' => 1, 'widget_id' => 'so-w2', 'style' => array() ) ),
		),
		'grids'      => array( array( 'cells' => 1, 'style' => array( 'background_image_attachment' => $b ) ) ),
		'grid_cells' => array( array( 'grid' => 0, 'index' => 0, 'weight' => 1, 'style' => array() ) ),
	);
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'SiteOrigin page', 'post_content' => '' ) );
	update_post_meta( $page, 'panels_data', $panels );
	t_save( 'so', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'page' => t_sid( $page ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'so' );
	list( $a, $b, $page ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['page'] ) );
	t_ok( $page && $a && $b, 'SiteOrigin page and images pulled' );
	$p = get_post_meta( $page, 'panels_data', true );
	t_eq( (int) $p['widgets'][0]['image'], $a, 'SiteOrigin image widget points at local image' );
	t_eq( (int) $p['widgets'][1]['attachment_id'], $b, 'media image widget points at local image' );
	t_eq( $p['widgets'][1]['url'], t_url( $b ), 'media image widget uses local URL' );
	t_eq( (int) $p['grids'][0]['style']['background_image_attachment'], $b, 'row background points at local image' );
	$html = t_renders( $page, 'SiteOrigin page' );
	t_ok( false !== strpos( $html, 'panel-layout' ), 'SiteOrigin renders the layout' );
}

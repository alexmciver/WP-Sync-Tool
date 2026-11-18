<?php
// Menus (nested, page/category/custom links), menu locations, homepage and site title.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Menu home' ) );
	$svc  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Menu services' ) );
	$cat  = wp_insert_term( 'Menu category', 'category' );
	$menu = wp_create_nav_menu( 'Test main menu' );
	$i1 = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $svc, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-title' => 'Our services' ) );
	$i2 = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id' => $cat['term_id'], 'menu-item-object' => 'category', 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $i1 ) );
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'custom', 'menu-item-url' => home_url( '/contact/#form' ), 'menu-item-title' => 'Contact form', 'menu-item-status' => 'publish' ) );
	set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu, 'primary-menu' => $menu ) ); // 'primary-menu' is Divi's header menu
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home );
	update_option( 'blogname', 'Live site title' );
	t_save( 'menus', array( 'home' => t_sid( $home ), 'svc' => t_sid( $svc ), 'cat' => t_sid( $cat['term_id'], 'term' ), 'menu' => t_sid( $menu, 'term' ), 'i1' => t_sid( $i1 ), 'i2' => t_sid( $i2 ) ) );
}

if ( 'verify' === $phase ) {
	$s    = t_load( 'menus' );
	$menu = t_local( $s['menu'], 'term' );
	t_ok( $menu > 0, 'menu pulled' );
	$items = $menu ? wp_get_nav_menu_items( $menu ) : array();
	t_eq( count( $items ), 3, 'all 3 menu items pulled' );
	$by = array();
	foreach ( (array) $items as $it ) {
		$by[ $it->title ] = $it;
	}
	t_eq( isset( $by['Our services'] ) ? (int) $by['Our services']->object_id : 0, t_local( $s['svc'] ), 'menu item links to local page' );
	t_eq( isset( $by['Menu category'] ) ? (int) $by['Menu category']->object_id : 0, t_local( $s['cat'], 'term' ), 'menu item links to local category' );
	t_eq( isset( $by['Menu category'] ) ? (int) $by['Menu category']->menu_item_parent : 0, t_local( $s['i1'] ), 'nested item keeps its parent' );
	t_eq( isset( $by['Contact form'] ) ? $by['Contact form']->url : '', home_url( '/contact/#form' ), 'custom link uses local address' );
	$loc = get_theme_mod( 'nav_menu_locations' );
	t_eq( isset( $loc['primary'] ) ? (int) $loc['primary'] : 0, $menu, 'menu assigned to its location' );
	t_eq( (int) get_option( 'page_on_front' ), t_local( $s['home'] ), 'homepage setting points at local page' );
	t_eq( get_option( 'blogname' ), 'Live site title', 'site title' );
	$r = t_fetch( home_url( '/' ) );
	if ( false !== strpos( $r['body'], 'DIVI-TB-HEADER' ) ) {
		// Divi Theme Builder header pulled (templates on): it replaces the theme header, as on live.
		t_ok( 200 === $r['code'], 'front page renders with the Theme Builder header, as on live' );
	} else {
		t_ok( 200 === $r['code'] && false !== strpos( $r['body'], 'Our services' ), 'front page renders with the menu' );
	}
}

if ( 'change' === $phase ) {
	$s = t_load( 'menus' );
	wp_delete_post( t_local( $s['i2'] ), true ); // Remove the nested category item.
	wp_update_post( array( 'ID' => t_local( $s['i1'] ), 'post_title' => 'What we do' ) );
}

if ( 'verify_change' === $phase ) {
	$s     = t_load( 'menus' );
	$items = wp_get_nav_menu_items( t_local( $s['menu'], 'term' ) );
	$titles = wp_list_pluck( (array) $items, 'title' );
	t_ok( in_array( 'What we do', $titles, true ), 'menu item rename on live came through' );
	t_ok( ! in_array( 'Menu category', $titles, true ), 'menu item removal on live came through' );
}

if ( 'verify_full' === $phase ) {
	$r = t_fetch( home_url( '/' ) );
	t_ok( 200 === $r['code'], 'front page renders after full copy' );
}

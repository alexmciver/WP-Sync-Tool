<?php
// Beaver Builder: _fl_builder_data / _fl_builder_draft (serialized stdClass nodes) with photo modules.
require getenv( 'WPSYNC_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
if ( ! t_needs( 'beaver-builder-lite-version/fl-builder.php', 'Beaver Builder' ) ) {
	return;
}

function t_bb_layout( $photo, $bg, $link ) {
	$row = (object) array( 'node' => 'row1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => (object) array( 'width' => 'fixed', 'bg_type' => 'photo', 'bg_image' => $bg, 'bg_image_src' => wp_get_attachment_url( $bg ) ) );
	$grp = (object) array( 'node' => 'grp1', 'type' => 'column-group', 'parent' => 'row1', 'position' => 0, 'settings' => null );
	$col = (object) array( 'node' => 'col1', 'type' => 'column', 'parent' => 'grp1', 'position' => 0, 'settings' => (object) array( 'size' => 100 ) );
	$mod = (object) array( 'node' => 'mod1', 'type' => 'module', 'parent' => 'col1', 'position' => 0, 'settings' => (object) array( 'type' => 'photo', 'photo_source' => 'library', 'photo' => $photo, 'photo_src' => wp_get_attachment_url( $photo ), 'link_type' => 'url', 'link_url' => $link ) );
	$txt = (object) array( 'node' => 'mod2', 'type' => 'module', 'parent' => 'col1', 'position' => 1, 'settings' => (object) array( 'type' => 'rich-text', 'text' => '<p>Hello <a href="' . $link . '">link</a></p>' ) );
	return array( 'row1' => $row, 'grp1' => $grp, 'col1' => $col, 'mod1' => $mod, 'mod2' => $txt );
}

if ( 'create' === $phase ) {
	$a = t_image( 'BB photo' ); $b = t_image( 'BB background' );
	$target = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'BB target' ) );
	$page   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Beaver Builder page', 'post_content' => '<p>BB fallback</p>' ) );
	$layout = t_bb_layout( $a, $b, get_permalink( $target ) );
	update_post_meta( $page, '_fl_builder_enabled', true );
	update_post_meta( $page, '_fl_builder_data', $layout );
	update_post_meta( $page, '_fl_builder_draft', $layout );
	t_save( 'bb', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'page' => t_sid( $page ), 'target' => t_sid( $target ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'bb' );
	list( $a, $b, $page, $target ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['page'] ), t_local( $s['target'] ) );
	t_ok( $page && $a && $b, 'BB page and images pulled' );
	foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
		$data = get_post_meta( $page, $key, true );
		t_ok( is_array( $data ) && isset( $data['mod1'] ) && is_object( $data['mod1'] ) && 'stdClass' === get_class( $data['mod1'] ), "$key: layout objects intact" );
		if ( ! is_array( $data ) || ! isset( $data['mod1'] ) || ! is_object( $data['mod1'] ) ) {
			continue;
		}
		t_eq( (int) $data['mod1']->settings->photo, $a, "$key: photo module points at local image" );
		t_eq( $data['mod1']->settings->photo_src, t_url( $a ), "$key: photo module uses local URL" );
		t_eq( (int) $data['row1']->settings->bg_image, $b, "$key: row background points at local image" );
		t_eq( $data['mod1']->settings->link_url, get_permalink( $target ), "$key: photo link points at local page" );
		t_urls_local( serialize( $data ), $key );
	}
	$html = t_renders( $page, 'Beaver Builder page' );
	t_ok( false !== strpos( $html, 'fl-builder-content' ), 'Beaver Builder renders the layout' );
}

if ( 'verify_full' === $phase ) {
	$s = t_load( 'bb' );
	$data = get_post_meta( t_local( $s['page'] ), '_fl_builder_data', true );
	t_ok( is_array( $data ) && isset( $data['mod1'] ) && is_object( $data['mod1'] ), 'BB layout intact after full copy (serialized objects survived URL rewriting)' );
	t_renders( t_local( $s['page'] ), 'Beaver Builder page after full copy' );
}

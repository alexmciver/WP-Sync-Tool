<?php
// WooCommerce: simple product (gallery, categories, upsell), variable product with a NEW global attribute, variations.
require getenv( 'WPSYNC_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';
if ( ! t_needs( 'woocommerce/woocommerce.php', 'WooCommerce' ) ) {
	return;
}

if ( 'create' === $phase ) {
	$a = t_image( 'Woo main' ); $b = t_image( 'Woo gallery 1' ); $c = t_image( 'Woo gallery 2' );
	$cat = wp_insert_term( 'Test hoodies', 'product_cat' );
	update_term_meta( $cat['term_id'], 'thumbnail_id', $b );
	$other = new WC_Product_Simple();
	$other->set_name( 'Woo upsell product' ); $other->set_regular_price( '5' ); $other->save();
	$p = new WC_Product_Simple();
	$p->set_name( 'Woo simple product' ); $p->set_regular_price( '19.99' ); $p->set_sku( 'WS-1' );
	$p->set_image_id( $a ); $p->set_gallery_image_ids( array( $b, $c ) ); $p->set_category_ids( array( $cat['term_id'] ) );
	$p->set_upsell_ids( array( $other->get_id() ) ); $p->set_manage_stock( true ); $p->set_stock_quantity( 7 );
	$p->save();
	// A global attribute created on live AFTER local was copied: local doesn't have its taxonomy.
	$attr_id = wc_create_attribute( array( 'name' => 'Colour', 'slug' => 'colour', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
	register_taxonomy( 'pa_colour', array( 'product' ), array( 'hierarchical' => false ) );
	$red  = wp_insert_term( 'Red', 'pa_colour' );
	$blue = wp_insert_term( 'Blue', 'pa_colour' );
	$attr = new WC_Product_Attribute();
	$attr->set_id( $attr_id ); $attr->set_name( 'pa_colour' ); $attr->set_options( array( $red['term_id'], $blue['term_id'] ) );
	$attr->set_visible( true ); $attr->set_variation( true );
	$v = new WC_Product_Variable();
	$v->set_name( 'Woo variable product' ); $v->set_attributes( array( $attr ) ); $v->save();
	$ids = array();
	foreach ( array( 'red' => '25', 'blue' => '26' ) as $slug => $price ) {
		$var = new WC_Product_Variation();
		$var->set_parent_id( $v->get_id() ); $var->set_attributes( array( 'pa_colour' => $slug ) ); $var->set_regular_price( $price ); $var->save();
		$ids[] = t_sid( $var->get_id() );
	}
	WC_Product_Variable::sync( $v->get_id() );
	t_save( 'woo', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'p' => t_sid( $p->get_id() ), 'other' => t_sid( $other->get_id() ), 'v' => t_sid( $v->get_id() ), 'vars' => $ids, 'cat' => t_sid( $cat['term_id'], 'term' ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'woo' );
	$p = wc_get_product( t_local( $s['p'] ) );
	t_ok( $p && $p->get_id(), 'simple product pulled' );
	if ( $p ) {
		t_eq( $p->get_image_id(), t_local( $s['a'] ), 'product image points at local image' );
		t_eq( $p->get_gallery_image_ids(), t_locals( array( $s['b'], $s['c'] ) ), 'product gallery points at local images' );
		t_eq( $p->get_upsell_ids(), array( t_local( $s['other'] ) ), 'upsell points at local product' );
		t_eq( $p->get_category_ids(), array( t_local( $s['cat'], 'term' ) ), 'product category assigned' );
		t_eq( $p->get_price(), '19.99', 'price' );
		t_eq( $p->get_stock_quantity(), 7, 'stock' );
		t_eq( (int) get_term_meta( t_local( $s['cat'], 'term' ), 'thumbnail_id', true ), t_local( $s['b'] ), 'category image points at local image' );
		t_ok( (bool) wc_get_product_id_by_sku( 'WS-1' ), 'SKU lookup works (lookup tables updated)' );
	}
	t_ok( taxonomy_exists( 'pa_colour' ) || wc_attribute_taxonomy_id_by_name( 'pa_colour' ), 'new global attribute (Colour) created locally' );
	$v = wc_get_product( t_local( $s['v'] ) );
	t_ok( $v && $v->is_type( 'variable' ), 'variable product pulled' );
	if ( $v ) {
		$children = $v->get_children();
		t_eq( count( $children ), 2, 'both variations pulled and attached to the product' );
		t_eq( array_map( 'intval', $children ), t_locals( $s['vars'] ), 'variations are the right ones' );
		$terms = wc_get_product_terms( $v->get_id(), 'pa_colour', array( 'fields' => 'names' ) );
		sort( $terms );
		t_eq( $terms, array( 'Blue', 'Red' ), 'attribute terms assigned' );
		t_renders( $v->get_id(), 'variable product page' );
	}
	if ( $p ) {
		t_renders( $p->get_id(), 'simple product page' );
	}
}

if ( 'change' === $phase ) {
	$s = t_load( 'woo' );
	$p = wc_get_product( t_local( $s['p'] ) );
	$p->set_stock_quantity( 3 ); $p->set_regular_price( '17.50' ); $p->save();
}

if ( 'verify_change' === $phase ) {
	$s = t_load( 'woo' );
	$p = wc_get_product( t_local( $s['p'] ) );
	t_eq( $p ? $p->get_stock_quantity() : null, 3, 'stock change on live came through' );
	t_eq( $p ? $p->get_price() : null, '17.50', 'price change on live came through' );
}

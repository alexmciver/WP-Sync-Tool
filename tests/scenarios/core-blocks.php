<?php
// Gutenberg core blocks, classic editor, [gallery], reusable blocks, featured image, terms, links.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$a = t_image( 'Core A' ); $b = t_image( 'Core B' ); $c = t_image( 'Core C' );
	$cat  = wp_insert_term( 'Core news', 'category' );
	$tag  = wp_insert_term( 'core-tag', 'post_tag' );
	$sync = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Core pattern', 'post_content' => '<!-- wp:paragraph --><p>Synced pattern</p><!-- /wp:paragraph -->' ) );
	$about = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Core about' ) );
	$html  = '<!-- wp:image {"id":' . $a . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . t_url( $a ) . '" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->'
		. '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":' . $b . '} --><figure class="wp-block-image"><img src="' . t_url( $b ) . '" class="wp-image-' . $b . '"/></figure><!-- /wp:image --><!-- wp:image {"id":' . $c . '} --><figure class="wp-block-image"><img src="' . t_url( $c ) . '" class="wp-image-' . $c . '"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'
		. '<!-- wp:cover {"url":"' . t_url( $b ) . '","id":' . $b . ',"dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-' . $b . '" src="' . t_url( $b ) . '"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
		. '<!-- wp:media-text {"mediaId":' . $c . ',"mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="' . t_url( $c ) . '" class="wp-image-' . $c . ' size-full"/></figure><div class="wp-block-media-text__content"></div></div><!-- /wp:media-text -->'
		. '<!-- wp:block {"ref":' . $sync . '} /-->'
		. '<!-- wp:paragraph --><p>Read <a href="' . get_permalink( $about ) . '">about us</a>.</p><!-- /wp:paragraph -->';
	$post = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Core blocks post', 'post_content' => $html, 'post_category' => array( $cat['term_id'] ), 'tags_input' => array( 'core-tag' ) ) );
	set_post_thumbnail( $post, $a );
	$classic = wp_insert_post( array( 'post_status' => 'publish', 'post_title' => 'Classic editor post', 'post_content' => '<p><img class="alignnone size-medium wp-image-' . $a . '" src="' . t_url( $a ) . '" /></p>[gallery ids="' . $b . ',' . $c . '"]' ) );
	t_save( 'core', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'post' => t_sid( $post ), 'classic' => t_sid( $classic ), 'sync' => t_sid( $sync ), 'about' => t_sid( $about ), 'cat' => t_sid( $cat['term_id'], 'term' ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'core' );
	list( $a, $b, $c, $post, $classic, $sync, $cat ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['c'] ), t_local( $s['post'] ), t_local( $s['classic'] ), t_local( $s['sync'] ), t_local( $s['cat'], 'term' ) );
	t_ok( $a && $b && $c && $post && $classic && $sync, 'all items pulled' );
	$html = get_post_field( 'post_content', $post );
	t_ok( false !== strpos( $html, '"id":' . $a . ',' ), 'image block points at local image' );
	t_ok( false !== strpos( $html, 'wp-image-' . $a ), 'image class points at local image' );
	t_ok( false !== strpos( $html, '<!-- wp:image {"id":' . $b . '}' ) && false !== strpos( $html, '<!-- wp:image {"id":' . $c . '}' ), 'gallery images point at local images' );
	t_ok( false !== strpos( $html, '"id":' . $b . ',"dimRatio"' ), 'cover block points at local image' );
	t_ok( false !== strpos( $html, '"mediaId":' . $c ), 'media & text points at local image' );
	t_ok( false !== strpos( $html, '"ref":' . $sync ), 'synced pattern reference points at local pattern' );
	t_urls_local( $html, 'core post content' );
	t_eq( (int) get_post_thumbnail_id( $post ), $a, 'featured image' );
	t_ok( has_term( $cat, 'category', $post ), 'category assigned' );
	t_ok( has_term( 'core-tag', 'post_tag', $post ), 'tag assigned' );
	$classic_html = get_post_field( 'post_content', $classic );
	t_ok( false !== strpos( $classic_html, '[gallery ids="' . $b . ',' . $c . '"]' ), '[gallery] ids point at local images' );
	t_ok( false !== strpos( $classic_html, 'wp-image-' . $a ), 'classic image class points at local image' );
	foreach ( array( $a, $b, $c ) as $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$dir  = dirname( get_attached_file( $id ) );
		$all  = file_exists( get_attached_file( $id ) );
		foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $sz ) {
			$all = $all && file_exists( $dir . '/' . $sz['file'] );
		}
		t_ok( $all, "image #$id and all its sizes downloaded" );
	}
	t_renders( $post, 'core blocks post' );
}

if ( 'change' === $phase ) {
	$s    = t_load( 'core' );
	$post = t_local( $s['post'] ); // On live, local() looks up live's own map.
	wp_update_post( array( 'ID' => $post, 'post_title' => 'Core blocks post (edited on live)' ) );
	wp_delete_post( t_local( $s['classic'] ), true );
}

if ( 'verify_change' === $phase ) {
	$s = t_load( 'core' );
	t_eq( get_the_title( t_local( $s['post'] ) ), 'Core blocks post (edited on live)', 'edit on live came through' );
	t_eq( t_local( $s['classic'] ), 0, 'deletion on live came through' );
}

if ( 'verify_full' === $phase ) {
	$s = t_load( 'core' );
	$post = t_local( $s['post'] );
	t_ok( $post > 0, 'post exists after full copy' );
	if ( $post ) {
		t_renders( $post, 'core post after full copy' );
	}
}

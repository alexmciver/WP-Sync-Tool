<?php
// Block-based builders: Spectra (uagb), Kadence Blocks, GenerateBlocks – media IDs inside their block attributes.
require getenv( 'WPSYNC_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$a = t_image( 'Blocks A' ); $b = t_image( 'Blocks B' ); $c = t_image( 'Blocks C' );
	$U = function ( $id ) { return t_url( $id ); };
	$html =
		// Spectra
		'<!-- wp:uagb/container {"block_id":"sp1","backgroundImageDesktop":{"id":' . $b . ',"url":"' . $U( $b ) . '"}} --><div class="wp-block-uagb-container uagb-block-sp1">'
		. '<!-- wp:uagb/image {"block_id":"sp2","url":"' . $U( $a ) . '","id":' . $a . ',"sizeSlug":"full"} --><div class="wp-block-uagb-image uagb-block-sp2"><figure><img src="' . $U( $a ) . '" class="uag-image-' . $a . '"/></figure></div><!-- /wp:uagb/image -->'
		. '</div><!-- /wp:uagb/container -->'
		// Kadence
		. '<!-- wp:kadence/rowlayout {"uniqueID":"_kr1","bgImg":"' . $U( $c ) . '","bgImgID":' . $c . '} --><!-- wp:kadence/column {"uniqueID":"_kc1"} --><div class="wp-block-kadence-column">'
		. '<!-- wp:kadence/advancedgallery {"uniqueID":"_kg1","ids":[' . $a . ',' . $b . '],"images":[{"url":"' . $U( $a ) . '","thumbUrl":"' . $U( $a ) . '","lightUrl":"' . $U( $a ) . '","id":' . $a . ',"width":900,"height":600},{"url":"' . $U( $b ) . '","thumbUrl":"' . $U( $b ) . '","lightUrl":"' . $U( $b ) . '","id":' . $b . ',"width":900,"height":600}]} /-->'
		. '</div><!-- /wp:kadence/column --><!-- /wp:kadence/rowlayout -->'
		// GenerateBlocks
		. '<!-- wp:generateblocks/container {"uniqueId":"gc1","bgImage":{"id":' . $c . ',"image":{"url":"' . $U( $c ) . '"}}} --><div class="gb-container gb-container-gc1">'
		. '<!-- wp:generateblocks/image {"uniqueId":"gi1","mediaId":' . $b . ',"mediaUrl":"' . $U( $b ) . '"} --><figure class="gb-block-image"><img class="gb-image gb-image-gi1" src="' . $U( $b ) . '"/></figure><!-- /wp:generateblocks/image -->'
		. '</div><!-- /wp:generateblocks/container -->';
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Block builders page', 'post_content' => wp_slash( $html ) ) );
	t_save( 'blocks', array( 'a' => t_sid( $a ), 'b' => t_sid( $b ), 'c' => t_sid( $c ), 'page' => t_sid( $page ) ) );
}

if ( 'verify' === $phase ) {
	$s = t_load( 'blocks' );
	list( $a, $b, $c, $page ) = array( t_local( $s['a'] ), t_local( $s['b'] ), t_local( $s['c'] ), t_local( $s['page'] ) );
	t_ok( $page && $a && $b && $c, 'page and images pulled' );
	$blocks = parse_blocks( get_post_field( 'post_content', $page ) );
	$by = array();
	$walk = function ( $list ) use ( &$walk, &$by ) {
		foreach ( $list as $bl ) {
			if ( $bl['blockName'] ) {
				$by[ $bl['blockName'] ] = $bl['attrs'];
			}
			$walk( $bl['innerBlocks'] );
		}
	};
	$walk( $blocks );
	t_eq( (int) $by['uagb/image']['id'], $a, 'Spectra image points at local image' );
	t_eq( $by['uagb/image']['url'], t_url( $a ), 'Spectra image uses local URL' );
	t_eq( (int) $by['uagb/container']['backgroundImageDesktop']['id'], $b, 'Spectra container background points at local image' );
	t_eq( (int) $by['kadence/rowlayout']['bgImgID'], $c, 'Kadence row background points at local image' );
	t_eq( array_map( 'intval', $by['kadence/advancedgallery']['ids'] ), array( $a, $b ), 'Kadence gallery IDs point at local images' );
	t_eq( array_map( 'intval', wp_list_pluck( $by['kadence/advancedgallery']['images'], 'id' ) ), array( $a, $b ), 'Kadence gallery image list points at local images' );
	t_eq( (int) $by['generateblocks/image']['mediaId'], $b, 'GenerateBlocks image points at local image' );
	t_eq( (int) $by['generateblocks/container']['bgImage']['id'], $c, 'GenerateBlocks container background points at local image' );
	t_urls_local( get_post_field( 'post_content', $page ), 'block builder content' );
	t_renders( $page, 'block builders page' );
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Finds and rewrites places where content refers to other content by database ID
 * (featured images, galleries, ACF image fields, Gutenberg image blocks, ...).
 *
 * Because IDs differ between sites, the exporter sends a map of every referenced
 * remote ID to its sync ID, and the importer rewrites each reference to the local ID.
 *
 * Works on any site out of the box for core WordPress, Gutenberg, the [gallery]
 * shortcode, WooCommerce, ACF, Divi galleries and Elementor. Anything else can be
 * added with the filters documented below.
 */
final class WPSync_Refs {

	/**
	 * Meta keys whose values hold IDs. Returns [ meta_key => 'post'|'term' ].
	 *
	 * @param array  $meta        Raw meta array as returned by get_post_meta( $id ) / get_term_meta( $id ).
	 * @param string $object_type 'post' or 'term'.
	 */
	public static function meta_ref_keys( array $meta, $object_type, $object_id ) {
		$keys = array();

		if ( 'post' === $object_type ) {
			$known = array(
				'_thumbnail_id'          => 'post', // Featured image (core).
				'_product_image_gallery' => 'post', // WooCommerce gallery (csv).
				'_upsell_ids'            => 'post', // WooCommerce.
				'_crosssell_ids'         => 'post',
				'_children'              => 'post', // WooCommerce grouped products.
				'_wp_page_template_id'   => 'post',
			);
		} else {
			$known = array(
				'thumbnail_id' => 'post', // WooCommerce category image.
			);
		}
		foreach ( $known as $k => $kind ) {
			if ( isset( $meta[ $k ] ) ) {
				$keys[ $k ] = $kind;
			}
		}

		// ACF: every value "foo" has a sibling "_foo" holding its field key. Works for
		// repeaters/flexible content too (e.g. "rows_0_image" + "_rows_0_image").
		if ( function_exists( 'acf_get_field' ) ) {
			foreach ( $meta as $k => $values ) {
				if ( '' === $k || '_' === $k[0] || ! isset( $meta[ '_' . $k ][0] ) ) {
					continue;
				}
				$field_key = $meta[ '_' . $k ][0];
				if ( ! is_string( $field_key ) || 0 !== strpos( $field_key, 'field_' ) ) {
					continue;
				}
				$kind = self::acf_ref_kind( $field_key );
				if ( $kind ) {
					$keys[ $k ] = $kind;
				}
			}
		}

		/**
		 * Add your own ID-holding meta keys.
		 *
		 * add_filter( 'wpsync_meta_ref_keys', function ( $keys, $meta, $object_type ) {
		 *     if ( 'post' === $object_type && isset( $meta['hero_image_id'] ) ) {
		 *         $keys['hero_image_id'] = 'post';
		 *     }
		 *     return $keys;
		 * }, 10, 3 );
		 */
		return apply_filters( 'wpsync_meta_ref_keys', $keys, $meta, $object_type, $object_id );
	}

	private static function acf_ref_kind( $field_key ) {
		static $cache = array();
		if ( ! array_key_exists( $field_key, $cache ) ) {
			$field = acf_get_field( $field_key );
			$type  = is_array( $field ) && isset( $field['type'] ) ? $field['type'] : '';
			$map   = array(
				'image'        => 'post',
				'file'         => 'post',
				'gallery'      => 'post',
				'relationship' => 'post',
				'post_object'  => 'post',
				'page_link'    => 'post',
				'taxonomy'     => 'term',
			);
			$cache[ $field_key ] = isset( $map[ $type ] ) ? $map[ $type ] : null;
		}
		return $cache[ $field_key ];
	}

	/** Meta keys that hold page-builder content with embedded IDs (rewritten like post_content). */
	public static function content_meta_keys() {
		return apply_filters( 'wpsync_content_meta_keys', array( '_elementor_data' ) );
	}

	/* -------------------------------------------------------------- Values */

	/** Numeric IDs inside a meta value: single ID, "1,2,3", or an array (any depth). */
	public static function ids_in_value( $value ) {
		$ids = array();
		self::map_value( $value, function ( $id ) use ( &$ids ) {
			$ids[] = $id;
			return $id;
		} );
		return $ids;
	}

	/**
	 * Rewrite IDs inside a meta value, keeping its shape.
	 *
	 * @param callable $map function( int $id ): int
	 */
	public static function map_value( $value, callable $map ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $map( $value ) : $value;
		}
		if ( is_string( $value ) ) {
			if ( preg_match( '/^\d+$/', $value ) ) {
				return (int) $value > 0 ? (string) $map( (int) $value ) : $value;
			}
			if ( preg_match( '/^\d+(\s*,\s*\d+)+$/', $value ) ) {
				$parts = array_map( 'trim', explode( ',', $value ) );
				return implode( ',', array_map( function ( $p ) use ( $map ) {
					return (string) $map( (int) $p );
				}, $parts ) );
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::map_value( $v, $map );
			}
		}
		return $value;
	}

	/* ------------------------------------------------------------- Content */

	/**
	 * Walk every ID reference inside a block of content (post_content or builder data)
	 * and let $cb replace it. $cb receives ( 'post'|'term', int $id ) and returns the new ID.
	 *
	 * @param string $field 'post_content', 'post_excerpt' or a meta key.
	 */
	public static function walk_content( $content, callable $cb, $field = 'post_content' ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( '_elementor_data' === $field ) {
			$content = self::walk_elementor( $content, $cb );
		} else {
			$content = self::walk_blocks( $content, $cb );

			// Classic editor / block HTML: <img class="wp-image-123">.
			$content = preg_replace_callback(
				'/\bwp-image-(\d+)\b/',
				function ( $m ) use ( $cb ) {
					return 'wp-image-' . (int) $cb( 'post', (int) $m[1] );
				},
				$content
			);

			// [gallery ids="1,2,3"], [gallery include="..."], Divi [et_pb_gallery gallery_ids="..."].
			$content = preg_replace_callback(
				'/(\[(?:gallery|et_pb_gallery)\b[^\]]*?\b(?:ids|include|exclude|gallery_ids)=["\'])([\d,\s]+)(["\'])/',
				function ( $m ) use ( $cb ) {
					$ids = array_filter( array_map( 'trim', explode( ',', $m[2] ) ), 'strlen' );
					$ids = array_map( function ( $id ) use ( $cb ) {
						return (int) $cb( 'post', (int) $id );
					}, $ids );
					return $m[1] . implode( ',', $ids ) . $m[3];
				},
				$content
			);
		}

		/**
		 * Rewrite your own ID formats.
		 *
		 * add_filter( 'wpsync_walk_content', function ( $content, $cb, $field ) {
		 *     return preg_replace_callback( '/\[my_banner id="(\d+)"/', function ( $m ) use ( $cb ) {
		 *         return '[my_banner id="' . $cb( 'post', (int) $m[1] ) . '"';
		 *     }, $content );
		 * }, 10, 3 );
		 */
		return apply_filters( 'wpsync_walk_content', $content, $cb, $field );
	}

	/** Gutenberg block comment attributes. */
	private static function walk_blocks( $content, callable $cb ) {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}
		$attr_map = apply_filters(
			'wpsync_block_id_attributes',
			array(
				'image'      => array( 'id' => 'post' ),
				'cover'      => array( 'id' => 'post' ),
				'video'      => array( 'id' => 'post' ),
				'audio'      => array( 'id' => 'post' ),
				'file'       => array( 'id' => 'post' ),
				'media-text' => array( 'mediaId' => 'post' ),
				'gallery'    => array( 'ids' => 'post' ),
				'block'      => array( 'ref' => 'post' ), // Synced pattern / reusable block.
				'site-logo'  => array(),
			)
		);

		return preg_replace_callback(
			'/<!--\s+wp:([a-z0-9\/-]+)\s+(\{.*?\})\s+(\/)?-->/s',
			function ( $m ) use ( $cb, $attr_map ) {
				$name = preg_replace( '#^core/#', '', $m[1] );
				if ( empty( $attr_map[ $name ] ) ) {
					return $m[0];
				}
				$attrs = json_decode( $m[2], true );
				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}
				$changed = false;
				foreach ( $attr_map[ $name ] as $attr => $kind ) {
					if ( ! isset( $attrs[ $attr ] ) ) {
						continue;
					}
					$new = self::map_value( $attrs[ $attr ], function ( $id ) use ( $cb, $kind ) {
						return (int) $cb( $kind, $id );
					} );
					if ( $new !== $attrs[ $attr ] ) {
						$attrs[ $attr ] = $new;
						$changed        = true;
					}
				}
				if ( ! $changed ) {
					return $m[0];
				}
				return '<!-- wp:' . $m[1] . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( isset( $m[3] ) ? $m[3] : '' ) . '-->';
			},
			$content
		);
	}

	/** Elementor stores media as {"url": "...", "id": 123} objects inside a JSON tree. */
	private static function walk_elementor( $json, callable $cb ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return $json;
		}
		$changed = false;
		$walk    = function ( &$node ) use ( &$walk, $cb, &$changed ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['url'], $node['id'] ) && is_numeric( $node['id'] ) && (int) $node['id'] > 0 ) {
				$new = (int) $cb( 'post', (int) $node['id'] );
				if ( $new !== (int) $node['id'] ) {
					$node['id'] = is_string( $node['id'] ) ? (string) $new : $new;
					$changed    = true;
				}
			}
			foreach ( $node as &$child ) {
				$walk( $child );
			}
		};
		$walk( $data );
		return $changed ? wp_json_encode( $data ) : $json;
	}
}

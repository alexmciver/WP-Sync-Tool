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
		if ( 'post' === $object_type ) {
			$known['_yoast_wpseo_opengraph-image-id'] = 'post';
			$known['_yoast_wpseo_twitter-image-id']   = 'post';
			$known['rank_math_primary_category']      = 'term';
			$known['_rank_math_facebook_image_id']    = 'post';
		}
		foreach ( $known as $k => $kind ) {
			if ( isset( $meta[ $k ] ) ) {
				$keys[ $k ] = $kind;
			}
		}
		// Yoast's primary term per taxonomy: _yoast_wpseo_primary_category, _primary_product_cat, …
		foreach ( array_keys( $meta ) as $k ) {
			if ( 0 === strpos( (string) $k, '_yoast_wpseo_primary_' ) ) {
				$keys[ $k ] = 'term';
			}
		}

		// Menu items: parent item, and the page/post/category they link to.
		if ( 'post' === $object_type && isset( $meta['_menu_item_type'][0] ) ) {
			$keys['_menu_item_menu_item_parent'] = 'post';
			if ( 'post_type' === $meta['_menu_item_type'][0] ) {
				$keys['_menu_item_object_id'] = 'post';
			} elseif ( 'taxonomy' === $meta['_menu_item_type'][0] ) {
				$keys['_menu_item_object_id'] = 'term';
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
		return apply_filters( 'wpsync_content_meta_keys', array(
			'_elementor_data', '_elementor_page_settings',      // Elementor
			'_fl_builder_data', '_fl_builder_draft',            // Beaver Builder
			'panels_data',                                       // SiteOrigin Page Builder
			'_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2', // Bricks
			'ct_builder_json',                                   // Oxygen
			'_brizy_data',                                       // Brizy (when stored as JSON)
		) );
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

		if ( in_array( $field, self::content_meta_keys(), true ) ) {
			$content = self::walk_value( $content, $cb, $field );
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
				$name    = preg_replace( '#^core/#', '', $m[1] );
				$is_core = false === strpos( $m[1], '/' ) || 0 === strpos( $m[1], 'core/' );
				$attrs   = json_decode( $m[2], true );
				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}
				if ( ! $is_core ) {
					// Third-party blocks (Spectra, Kadence, GenerateBlocks, Divi 5, ACF blocks…): generic rules.
					$changed = false;
					$attrs   = self::walk_structure( $attrs, $cb, $m[1], $changed );
					return $changed ? '<!-- wp:' . $m[1] . ' ' . serialize_block_attributes( $attrs ) . ' ' . ( isset( $m[3] ) ? $m[3] : '' ) . '-->' : $m[0];
				}
				if ( empty( $attr_map[ $name ] ) ) {
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

	/**
	 * A builder's stored data: JSON text, serialized array/objects (already unserialized), or
	 * HTML. Returns the same shape with IDs re-pointed.
	 */
	public static function walk_value( $value, callable $cb, $field = '' ) {
		if ( is_string( $value ) ) {
			$t = ltrim( $value );
			if ( '' !== $t && ( '[' === $t[0] || '{' === $t[0] ) ) {
				$data = json_decode( $value, true );
				if ( is_array( $data ) ) {
					$changed = false;
					$data    = self::walk_structure( $data, $cb, $field, $changed );
					return $changed ? wp_json_encode( $data ) : $value;
				}
			}
			return self::walk_strings( $value, $cb );
		}
		if ( is_array( $value ) || $value instanceof stdClass ) {
			$changed = false;
			return self::walk_structure( $value, $cb, $field, $changed );
		}
		return $value;
	}

	/** HTML inside builder data: <img class="wp-image-123">, [gallery ids="…"]. */
	private static function walk_strings( $s, callable $cb ) {
		if ( false === strpos( $s, 'wp-image-' ) && false === strpos( $s, '[gallery' ) && false === strpos( $s, '<!-- wp:' ) ) {
			return $s;
		}
		return self::walk_content( $s, $cb, 'html' );
	}

	/** Does this look like a media file URL? */
	private static function is_media_url( $u ) {
		return is_string( $u ) && '' !== $u && ( false !== strpos( $u, '/uploads/' ) || preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg|pdf|docx?|xlsx?|pptx?|zip)(\?|#|$)/i', $u ) );
	}

	/**
	 * Generic rules for media IDs stored by page builders, applied anywhere in a structure:
	 *  - an "id" next to a media "url"/"src" (Elementor, Spectra, Kadence, Divi 5, Bricks…)
	 *    or next to "image": {"url"} (GenerateBlocks)
	 *  - "fooID"/"fooId"/"foo_id" next to "foo"/"fooUrl"/"foo_url" holding a media URL (Kadence, GenerateBlocks)
	 *  - "foo" next to "foo_src" (Beaver Builder)
	 *  - ID lists: "ids", "gallery_ids", "galleryIds", "images_ids" (Kadence, Divi 5…)
	 *  - SiteOrigin widget/row image keys inside panels_data
	 * Media IDs are always attachments ('post').
	 */
	public static function walk_structure( $node, callable $cb, $field, &$changed ) {
		$is_obj = $node instanceof stdClass;
		if ( ! $is_obj && ! is_array( $node ) ) {
			return $node;
		}
		$arr = $is_obj ? get_object_vars( $node ) : $node;
		$map = function ( $v ) use ( $cb, &$changed ) {
			$new = self::map_value( $v, function ( $id ) use ( $cb ) {
				return (int) $cb( 'post', (int) $id );
			} );
			if ( $new !== $v ) {
				$changed = true;
			}
			return $new;
		};
		$siteorigin = ( 'panels_data' === $field );
		foreach ( $arr as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$numeric = ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^\d+$/', $v ) ) ) && (int) $v > 0;
			$url_of  = function ( $key ) use ( $arr ) {
				return isset( $arr[ $key ] ) && is_string( $arr[ $key ] ) ? $arr[ $key ] : '';
			};
			if ( $numeric ) {
				$hit = false;
				if ( 'id' === $k ) {
					$img = isset( $arr['image'] ) ? ( is_array( $arr['image'] ) ? $arr['image'] : ( $arr['image'] instanceof stdClass ? get_object_vars( $arr['image'] ) : array() ) ) : array();
					$hit = self::is_media_url( $url_of( 'url' ) ) || self::is_media_url( $url_of( 'src' ) ) || ( isset( $img['url'] ) && self::is_media_url( $img['url'] ) );
				} elseif ( preg_match( '/^(.+?)(ID|Id|_id)$/', $k, $mm ) ) {
					$base = $mm[1];
					$hit  = self::is_media_url( $url_of( $base ) ) || self::is_media_url( $url_of( $base . 'Url' ) ) || self::is_media_url( $url_of( $base . 'URL' ) ) || self::is_media_url( $url_of( $base . '_url' ) ) || ( 'attachment' === $base && self::is_media_url( $url_of( 'url' ) ) );
				}
				if ( ! $hit && self::is_media_url( $url_of( $k . '_src' ) ) ) {
					$hit = true; // Beaver Builder: photo + photo_src.
				}
				if ( ! $hit && $siteorigin && in_array( $k, array( 'image', 'attachment_id', 'image_id', 'background_image_attachment', 'image_fallback_id', 'icon_image' ), true ) ) {
					$hit = true;
				}
				if ( $hit ) {
					$arr[ $k ] = $map( $v );
					continue;
				}
			}
			if ( in_array( $k, array( 'ids', 'gallery_ids', 'galleryIds', 'images_ids', 'imageIds', 'image_ids' ), true ) && ( is_array( $v ) || $v instanceof stdClass || ( is_string( $v ) && preg_match( '/^\d+(\s*,\s*\d+)*$/', $v ) ) ) ) {
				$arr[ $k ] = $map( $v instanceof stdClass ? get_object_vars( $v ) : $v );
				if ( $v instanceof stdClass ) {
					$arr[ $k ] = (object) $arr[ $k ];
				}
				continue;
			}
			if ( is_array( $v ) || $v instanceof stdClass ) {
				$arr[ $k ] = self::walk_structure( $v, $cb, $field, $changed );
			} elseif ( is_string( $v ) && ( false !== strpos( $v, 'wp-image-' ) || false !== strpos( $v, '[gallery' ) ) ) {
				$new = self::walk_strings( $v, $cb );
				if ( $new !== $v ) {
					$arr[ $k ] = $new;
					$changed   = true;
				}
			}
		}
		// Numeric-keyed children (lists).
		foreach ( $arr as $k => $v ) {
			if ( is_int( $k ) && ( is_array( $v ) || $v instanceof stdClass ) ) {
				$arr[ $k ] = self::walk_structure( $v, $cb, $field, $changed );
			}
		}
		if ( $is_obj ) {
			foreach ( $arr as $k => $v ) {
				$node->$k = $v;
			}
			return $node;
		}
		return $arr;
	}

	/** Polylang keeps translation groups as serialized ID maps in term descriptions. */
	public static function description_kind( $taxonomy ) {
		$kinds = apply_filters( 'wpsync_description_id_maps', array( 'post_translations' => 'post', 'term_translations' => 'term' ) );
		return isset( $kinds[ $taxonomy ] ) ? $kinds[ $taxonomy ] : null;
	}
}

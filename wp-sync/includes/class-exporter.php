<?php
defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON payload describing one post or term's current state on this site.
 */
final class WPSync_Exporter {

	/** @var array{post:array<int,string>,term:array<int,string>} remote ID => sync ID */
	private $idmap = array( 'post' => array(), 'term' => array() );

	public static function post( $post_id ) {
		$e = new self();
		return $e->build_post( (int) $post_id );
	}

	public static function term( $term_id ) {
		$e = new self();
		return $e->build_term( (int) $term_id );
	}

	/** A pulled setting. Name-based, so it needs no ID mapping of its own. */
	public static function option( $name ) {
		$spec = WPSync_Config::option_spec( $name );
		if ( null === $spec ) {
			return null;
		}
		global $wpdb;
		$exists = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! $exists ) {
			return true === $spec ? self::tombstone( 'option', WPSync_Config::option_sid( $name ) ) : null;
		}
		$value = get_option( $name );
		if ( is_array( $spec ) ) {
			$sub = array();
			foreach ( $spec as $k ) {
				if ( is_array( $value ) && array_key_exists( $k, $value ) ) {
					$sub[ $k ] = $value[ $k ];
				}
			}
			$value = $sub;
		}
		$e    = new self();
		$refs = WPSync_Config::option_refs( $name );
		if ( $refs ) {
			if ( is_array( $refs ) ) {
				foreach ( $refs as $k => $kind ) {
					if ( is_array( $value ) && isset( $value[ $k ] ) ) {
						foreach ( WPSync_Refs::ids_in_value( $value[ $k ] ) as $id ) {
							$e->remember_public( $kind, $id );
						}
					}
				}
			} else {
				foreach ( WPSync_Refs::ids_in_value( $value ) as $id ) {
					$e->remember_public( $refs, $id );
				}
			}
		}
		return array(
			'type'    => 'option',
			'sync_id' => WPSync_Config::option_sid( $name ),
			'option'  => array( 'name' => $name, 'mode' => true === $spec ? 'whole' : 'keys', 'value' => maybe_serialize( $value ) ),
			'idmap'   => $e->idmap_public(),
		);
	}

	/** @internal */
	public function idmap_public() {
		return $this->idmap;
	}

	public static function tombstone( $type, $sid ) {
		return array( 'type' => $type, 'sync_id' => $sid, 'deleted' => true );
	}

	private function remember( $kind, $id ) {
		$id = (int) $id;
		if ( $id <= 0 || isset( $this->idmap[ $kind ][ $id ] ) ) {
			return $id;
		}
		$sid = 'post' === $kind ? WPSync_Ids::for_post( $id ) : WPSync_Ids::for_term( $id );
		if ( $sid ) {
			$this->idmap[ $kind ][ $id ] = $sid;
		}
		return $id;
	}

	private function strip_meta( array $meta ) {
		foreach ( array_keys( $meta ) as $k ) {
			if ( WPSync_Config::is_ignored_meta( $k ) ) {
				unset( $meta[ $k ] );
			}
		}
		return $meta;
	}

	/** Record every ID referenced by ref meta keys. */
	private function collect_meta_refs( array $meta, array $ref_keys ) {
		foreach ( $ref_keys as $key => $kind ) {
			if ( empty( $meta[ $key ] ) ) {
				continue;
			}
			foreach ( $meta[ $key ] as $raw ) {
				foreach ( WPSync_Refs::ids_in_value( WPSync_Payload::unserialize( $raw ) ) as $id ) {
					$this->remember( $kind, $id );
				}
			}
		}
	}

	private function collect_content_refs( $content, $field ) {
		$self = $this;
		WPSync_Refs::walk_content(
			$content,
			function ( $kind, $id ) use ( $self ) {
				return $self->remember_public( $kind, $id );
			},
			$field
		);
	}

	/** @internal Closure access for PHP 7.4. */
	public function remember_public( $kind, $id ) {
		return $this->remember( $kind, $id );
	}

	private function build_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'auto-draft' === $post->post_status || ! WPSync_Config::post_type_ok( $post->post_type ) ) {
			return null;
		}
		$sid      = WPSync_Ids::for_post( $post->ID );
		$meta     = $this->strip_meta( (array) get_post_meta( $post->ID ) );
		$ref_keys = WPSync_Refs::meta_ref_keys( $meta, 'post', $post->ID );

		$this->collect_meta_refs( $meta, $ref_keys );
		$this->collect_content_refs( $post->post_content, 'post_content' );
		$this->collect_content_refs( $post->post_excerpt, 'post_excerpt' );
		foreach ( WPSync_Refs::content_meta_keys() as $ck ) {
			if ( isset( $meta[ $ck ] ) ) {
				foreach ( (array) $meta[ $ck ] as $raw ) {
					$self = $this;
					WPSync_Refs::walk_value( WPSync_Payload::unserialize( $raw ), function ( $kind, $id ) use ( $self ) {
						return $self->remember_public( $kind, $id );
					}, $ck );
				}
			}
		}
		if ( $post->post_parent ) {
			$this->remember( 'post', $post->post_parent );
		}

		// Terms, by taxonomy, as sync IDs.
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			if ( ! WPSync_Config::taxonomy_ok( $tax ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids', 'orderby' => 'term_order' ) );
			if ( is_wp_error( $ids ) ) {
				continue;
			}
			$terms[ $tax ] = array();
			foreach ( $ids as $tid ) {
				$tsid = WPSync_Ids::for_term( $tid );
				if ( $tsid ) {
					$terms[ $tax ][] = $tsid;
				}
			}
		}

		$author = $post->post_author ? get_userdata( $post->post_author ) : false;

		$payload = array(
			'type'    => 'post',
			'sync_id' => $sid,
			'remote_id' => $post->ID,
			'post'    => array(
				'post_type'         => $post->post_type,
				'post_status'       => $post->post_status,
				'post_title'        => $post->post_title,
				'post_content'      => $post->post_content,
				'post_excerpt'      => $post->post_excerpt,
				'post_name'         => $post->post_name,
				'post_date'         => $post->post_date,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
				'comment_status'    => $post->comment_status,
				'ping_status'       => $post->ping_status,
				'post_password'     => $post->post_password,
				'menu_order'        => (int) $post->menu_order,
				'post_mime_type'    => $post->post_mime_type,
				'post_parent'       => (int) $post->post_parent,
				'author_login'      => $author ? $author->user_login : '',
			),
			'meta'     => $meta,
			'ref_keys' => $ref_keys,
			'terms'    => $terms,
		);

		if ( 'attachment' === $post->post_type ) {
			$payload['files'] = $this->attachment_files( $post->ID, $meta );
		}

		$payload['idmap'] = $this->idmap;
		return $payload;
	}

	/** Every file belonging to an attachment: original, sizes, pre-scaled original. */
	private function attachment_files( $post_id, array $meta ) {
		$file = isset( $meta['_wp_attached_file'][0] ) ? $meta['_wp_attached_file'][0] : '';
		$url  = wp_get_attachment_url( $post_id );
		if ( ! $file || ! $url ) {
			return array();
		}
		$files    = array( array( 'path' => $file, 'url' => $url ) );
		$dir      = dirname( $file );
		$dir      = ( '.' === $dir ) ? '' : $dir . '/';
		$url_dir  = trailingslashit( dirname( $url ) );
		$metadata = isset( $meta['_wp_attachment_metadata'][0] ) ? WPSync_Payload::unserialize( $meta['_wp_attachment_metadata'][0] ) : array();
		$extra    = array();
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$extra[] = $size['file'];
					}
				}
			}
			if ( ! empty( $metadata['original_image'] ) ) {
				$extra[] = $metadata['original_image'];
			}
		}
		foreach ( array_unique( $extra ) as $name ) {
			$files[] = array( 'path' => $dir . $name, 'url' => $url_dir . rawurlencode( $name ) );
		}
		return $files;
	}

	private function build_term( $term_id ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) || ! WPSync_Config::taxonomy_ok( $term->taxonomy ) ) {
			return null;
		}
		$sid      = WPSync_Ids::for_term( $term->term_id );
		$meta     = $this->strip_meta( (array) get_term_meta( $term->term_id ) );
		$ref_keys = WPSync_Refs::meta_ref_keys( $meta, 'term', $term->term_id );
		$this->collect_meta_refs( $meta, $ref_keys );
		$desc_kind = WPSync_Refs::description_kind( $term->taxonomy );
		if ( $desc_kind ) {
			foreach ( WPSync_Refs::ids_in_value( WPSync_Payload::unserialize( $term->description ) ) as $rid ) {
				$this->remember( $desc_kind, $rid );
			}
		} else {
			$this->collect_content_refs( $term->description, 'term_description' );
		}
		$wc_attr = null;
		if ( 0 === strpos( $term->taxonomy, 'pa_' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
			$a = wc_get_attribute( wc_attribute_taxonomy_id_by_name( $term->taxonomy ) );
			if ( $a ) {
				$wc_attr = array( 'name' => $a->name, 'slug' => substr( $a->slug, 3 ), 'type' => $a->type, 'order_by' => $a->order_by, 'has_archives' => (bool) $a->has_archives );
			}
		}
		if ( $term->parent ) {
			$this->remember( 'term', $term->parent );
		}
		return array(
			'type'      => 'term',
			'sync_id'   => $sid,
			'remote_id' => $term->term_id,
			'term'      => array(
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
			),
			'meta'      => $meta,
			'ref_keys'  => $ref_keys,
			'wc_attribute' => $wc_attr,
			'idmap'     => $this->idmap,
		);
	}
}

/**
 * Helpers shared by exporter and importer.
 */
final class WPSync_Payload {

	/**
	 * Unserialize without ever instantiating classes from remote data (object injection safe).
	 * Objects come back as __PHP_Incomplete_Class and re-serialize unchanged.
	 */
	public static function unserialize( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}
		// Plain stdClass objects are safe (no methods); anything else stays an inert placeholder.
		$value = @unserialize( trim( $raw ), array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore
		return ( false === $value && 'b:0;' !== trim( $raw ) ) ? $raw : $value;
	}
}

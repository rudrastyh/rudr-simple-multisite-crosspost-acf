<?php
/*
 * Plugin name: Simple Multisite Crossposting – ACF
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Provides better compatibility with ACF, ACF PRO and SCF.
 * Version: 1.7
 * Plugin URI: https://rudrastyh.com/support/acf-compatibility
 * Network: true
 */

class Rudr_SMC_ACF {

	function __construct() {

		add_filter( 'rudr_pre_crosspost_meta', array( $this, 'process_fields' ), 10, 3 );
		add_filter( 'rudr_pre_crosspost_termmeta', array( $this, 'process_fields' ), 10, 3 );
		add_filter( 'rudr_pre_crosspost_content', array( $this, 'process_acf_blocks' ), 10, 2 );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
	}

	public function activate() {

		// deactivate outdated add-ons
		deactivate_plugins(
			array(
				'rudr-simple-crossposting-attachments/rudr-simple-crossposting-attachments.php', // deactivate image processing via add-on
				'rudr-simple-crossposting-relationship/rudr-simple-crossposting-relationship.php', // deactivate relationships fields via add-on
				'rudr-simple-multisite-crosspost-acf-blocks/rudr-simple-multisite-crosspost-acf-blocks.php', // deactivate acf blocks add-on
			),
			true,
			true
		);

	}


	public function process_fields( $meta_value, $meta_key, $object_id ) {

		// if no ACF
		if( ! function_exists( 'acf_get_field' ) ) {
			return $meta_value;
		}

		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		// we can not just use acf_get_field( $meta_key ) because it won't work for nested repeater fields
		if( 'rudr_pre_crosspost_termmeta' == current_filter() ) {
			$field_key = get_term_meta( $object_id, "_{$meta_key}", true );
		} else {
			$field_key = get_post_meta( $object_id, "_{$meta_key}", true );
		}

		$field = acf_get_field( $field_key );

		switch_to_blog( $new_blog_id );

		// not an ACF field specifically
		if( ! $field ) {
			return $meta_value;
		}

		return $this->process_field_by_type( $meta_value, $field );

	}

	public function process_field_by_type( $meta_value, $field ) {

		switch( $field[ 'type' ] ) {
			case 'image':
			case 'gallery':
			case 'file': {
				$meta_value = $this->process_attachment_field( $meta_value );
				break;
			}
			case 'relationship':
			case 'page_link' :
			case 'post_object': {
				$meta_value = $this->process_relationships_field( $meta_value );
				break;
			}
			case 'taxonomy' : {
				$meta_value = $this->process_taxonomy_relationships_field( $meta_value );
				break;
			}
		}

		return $meta_value;

	}

	private function process_attachment_field( $meta_value ) {

		$meta_value = maybe_unserialize( $meta_value );
		// let's make it array anyway for easier processing
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

		$new_blog_id = get_current_blog_id();
		restore_current_blog();
		$attachments_data = array();
		foreach( $ids as $id ) {
			$attachments_data[] = Rudr_Simple_Multisite_Crosspost::prepare_attachment_data( $id );
		}
		switch_to_blog( $new_blog_id );
		$attachment_ids = array();
		foreach( $attachments_data as $attachment_data ) {
			$upload = Rudr_Simple_Multisite_Crosspost::maybe_copy_image( $attachment_data );
			if( isset( $upload[ 'id' ] ) && $upload[ 'id' ] ) {
				$attachment_ids[] = $upload[ 'id' ];
			}
		}

		return is_array( $meta_value ) ? maybe_serialize( $attachment_ids ) : ( $attachment_ids ? reset( $attachment_ids ) : 0 );

	}


	private function process_relationships_field( $meta_value ) {

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );
		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		$crossposted_ids = array();
		$crossposted_skus = array(); // we will process it after switching to a new blog
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( function_exists( 'wc_get_product_id_by_sku' ) && 'product' === $post_type && 'sku' === Rudr_Simple_Multisite_Woo_Crosspost::connection_type() ) {
				$crossposted_skus[] = get_post_meta( $id, '_sku', true );
			} else {
				if( $new_id = Rudr_Simple_Multisite_Crosspost::is_crossposted( $id, $new_blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		switch_to_blog( $new_blog_id );

		// do we have some crossposted SKUs here? let's check if there are some in a new blog
		if( $crossposted_skus ) {
			foreach( $crossposted_skus as $crossposted_sku ) {
				if( $new_id = Rudr_Simple_Multisite_Woo_Crosspost::maybe_is_crossposted_product__sku( array( 'sku' => $crossposted_sku ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}

		return is_array( $meta_value ) ? maybe_serialize( $crossposted_ids ) : ( $crossposted_ids ? reset( $crossposted_ids ) : 0 );

	}


	private function process_taxonomy_relationships_field( $meta_value ) {
		// can be either int or a serialized array
		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );
		$new_blog_id = get_current_blog_id();
		restore_current_blog();

		$terms_data = array();
		foreach( $ids as $id ) {
			$term = get_term( $id );
			if( ! $term ) {
				continue;
			}
			$terms_data[] = array( 'id' => $id, 'slug' => $term->slug, 'taxonomy' => $term->taxonomy );
		}

		switch_to_blog( $new_blog_id );

		$crossposted_term_ids = array();
		foreach( $terms_data as $term_data ) {
			$crossposted_term = get_term_by( 'slug', $term_data[ 'slug' ], $term_data[ 'taxonomy' ] );
			if( $crossposted_term ) {
				$crossposted_term_ids[] = $crossposted_term->term_id;
			}
		}

		return is_array( $meta_value ) ? maybe_serialize( $crossposted_term_ids ) : ( $crossposted_term_ids ? reset( $crossposted_term_ids ) : 0 );

	}


	public function process_acf_blocks( $content, $new_blog_id ) {

		// no blocks, especially no acf ones
		if( ! has_blocks( $content ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		//file_put_contents( __DIR__ . '/log.txt' , print_r( $blocks, true ) );

		// let's do the shit
		foreach( $blocks as &$block ) {
			$block = $this->process_acf_block( $block, $new_blog_id );
		}

		//file_put_contents( __DIR__ . '/log.txt' , print_r( $blocks, true ) );

		$processed_content = '';
		foreach( $blocks as $processed_block ) {
			if( $processed_rendered_block = $this->render_acf_block( $processed_block ) ) {
				$processed_content .= "{$processed_rendered_block}\n\n";
			}
		}

		//file_put_contents( __DIR__ . '/log.txt' , $processed_content );

		return $processed_content;
	}




	public function process_acf_block( $block, $new_blog_id ) {

		// first – process inner blocks
		if( $block[ 'innerBlocks' ] ) {
			foreach( $block[ 'innerBlocks' ] as &$innerBlock ) {
				$innerBlock = $this->process_acf_block( $innerBlock, $new_blog_id );
			}
		}

		// second – once the block itself non acf, we do nothing
		if( ! acf_has_block_type( $block[ 'blockName' ] ) ) {
			return $block;
		}

		// skip the block if it has empty data
		if( empty( $block[ 'attrs' ][ 'data' ] ) || ! $block[ 'attrs' ][ 'data' ] ) {
			return $block;
		}

		// now we are going to work with fields!
		$fields = array();
		foreach( $block[ 'attrs' ][ 'data' ] as $key => $value ) {
			// modify
			if( 0 !== strpos( $key, '_' ) ) {
				$field_key = $block[ 'attrs' ][ 'data' ][ '_'.$key ];

				switch_to_blog( $new_blog_id );
				$fields[ $key ] = apply_filters(
					'rudr_pre_crosspost_acf_block_value',
					$this->process_field_by_type( $value, acf_get_field( $field_key ) ),
					$field_key,
					$new_blog_id
				);
				$fields[ '_'.$key ] = $field_key;
				restore_current_blog();

				if( is_array( $fields[ $key ] ) ) {
					continue;
				}

				$fields[ $key ] = str_replace(
					array( "\r" . PHP_EOL, PHP_EOL ),
					'\r\n',
					$fields[ $key ]
					//addslashes( wp_kses( stripslashes( $value ), 'post' ) )
				);

				preg_match_all( "/u([0-9a-f]{3,4})/i", json_encode( strip_tags( $fields[ $key ] ) ), $matches );

				$notencoded = array(
					'<',
					'>',
					'"',
					"\t",
				);
				$encoded = array(
					'\u003c',
					'\u003e',
					'\u0022',
					'',
				);

				if( isset( $matches[1] ) && $matches[1] && is_array( $matches[1] ) ) {
					foreach( $matches[1] as $match ) { // 00c4
						$encoded[] = "\u$match";
						$notencoded[] = json_decode( '"\u'.$match.'"' );
					}
				}
//file_put_contents( __DIR__ . '/log.txt' , print_r( $matches, true ) );
				$fields[ $key ] = str_replace( $notencoded, $encoded, $fields[ $key ] );

				/*$fields[ $key ] = str_replace(
					array(
						'<',
						'>',
						'"',
						"\t",
						'’',
						'é',
						' ', //nbsp
						'×',
						'ë',
						'€',
						'‘',
						'ñ',
						'í',
						'á',
						'ó',
						'ú',
						'Í',
						'”',
						'°',
						'–',
						'®',
						'™',
						'″',
					),
					array(
						'\u003c',
						'\u003e',
						'\u0022',
						'',
						'\u2019',
						'\u00e9',
						'\u00a0',
						'\u00d7',
						'\u00eb',
						'\u20ac',
						'\u2018',
						'\u00f1',
						'\u00ed',
						'\u00e1',
						'\u00f3',
						'\u00fa',
						'\u00cd',
						'\u201d',
						'\u00b0',
						'\u2013',
						'\u00ae',
						'\u2122',
						'\u2033',
					),
					$fields[ $key ]
				);*/
			}
		}

		$block[ 'attrs' ][ 'data' ] = $fields;

		return $block;

	}

	public function render_acf_block( $processed_block ) {

		if( empty( $processed_block[ 'blockName' ] ) ){
			return false;
		}

		$processed_rendered_block = '';
		// block name
		$processed_rendered_block .= "<!-- wp:{$processed_block[ 'blockName' ]}";
		// data
		if( $processed_block[ 'attrs' ] ) {
			$processed_rendered_block .= ' ' . wp_unslash( wp_json_encode( $processed_block[ 'attrs' ] ) );
		}

		if( ! $processed_block[ 'innerHTML' ] && ! $processed_block[ 'innerBlocks' ] ) {
			$processed_rendered_block .= " /-->";
		} else {
			// ok now we have either html or innerblocks or both
			// but we are going to use innerContent to populate that
			$innerBlockIndex = 0;
			$processed_rendered_block .= " -->";
			foreach( $processed_block[ 'innerContent' ] as $piece ) {
				if( isset( $piece ) && $piece ) {
					$processed_rendered_block .= $piece; // innerHTML
				} else {
					if( $processed_inner_block = $this->render_acf_block( $processed_block[ 'innerBlocks' ][$innerBlockIndex] ) ) {
						$processed_rendered_block .= $processed_inner_block;
					}
					$innerBlockIndex++;
				}
			}
			$processed_rendered_block .= "<!-- /wp:{$processed_block[ 'blockName' ]} -->";
		}

		return $processed_rendered_block;

	}



}


new Rudr_SMC_ACF;

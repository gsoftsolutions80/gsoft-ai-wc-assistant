<?php
namespace GSoft\AIAssistant;

defined( 'ABSPATH' ) || exit;

class ProductSerializer {

	/** @return array<string,mixed> Shape expected by POST /api/v1/catalog/sync. */
	public static function serialize( \WC_Product $product ): array {
		$id       = $product->get_id();
		$modified = $product->get_date_modified();
		$image    = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';
		$url      = (string) get_permalink( $id );

		$categories = array();
		foreach ( (array) get_the_terms( $id, 'product_cat' ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$categories[] = array(
					'id'        => $term->term_id,
					'name'      => $term->name,
					'parent_id' => $term->parent,
				);
			}
		}

		$tags = array();
		foreach ( (array) get_the_terms( $id, 'product_tag' ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$tags[] = $term->name;
			}
		}

		$data = array(
			'wc_product_id'     => $id,
			'sku'               => self::cut( (string) $product->get_sku(), 255 ),
			'name'              => self::cut( wp_strip_all_tags( $product->get_name() ), 500 ) ?? '#' . $id,
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'price'             => self::number( $product->get_price() ),
			'regular_price'     => self::number( $product->get_regular_price() ),
			'sale_price'        => self::number( $product->get_sale_price() ),
			'currency'          => strtoupper( substr( get_woocommerce_currency(), 0, 3 ) ),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'categories'        => $categories,
			'tags'              => $tags,
			'attributes'        => self::attributes( $product ),
			'brand'             => self::brand( $product ),
			'image_url'         => $image && strlen( $image ) <= 2000 ? $image : null,
			'url'               => strlen( $url ) <= 2000 ? $url : null,
			'short_description' => self::cut( wp_strip_all_tags( $product->get_short_description() ), 2000 ),
			'description'       => self::cut( wp_strip_all_tags( $product->get_description() ), 5000 ),
			'wc_updated_at'     => $modified ? $modified->getTimestamp() : null,
		);

		return (array) apply_filters( 'gsai_product_payload', $data, $product );
	}

	private static function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$name = $attribute->get_name();
			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) );
				$label = wc_attribute_label( $name );
			} else {
				$terms = $attribute->get_options();
				$label = $name;
			}
			$values = array_values( array_filter( array_map( 'strval', (array) $terms ), 'strlen' ) );
			if ( $values ) {
				$out[ $label ] = $values;
			}
		}
		return $out;
	}

	private static function brand( \WC_Product $product ): ?string {
		$brand = null;
		foreach ( array( 'product_brand', 'pa_brand', 'pa_marca' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$names = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );
				if ( ! empty( $names ) && ! is_wp_error( $names ) ) {
					$brand = (string) $names[0];
					break;
				}
			}
		}
		$brand = apply_filters( 'gsai_product_brand', $brand, $product );
		return $brand ? self::cut( (string) $brand, 255 ) : null;
	}

	private static function number( $value ): ?float {
		return '' === $value || null === $value || ! is_numeric( $value ) ? null : (float) $value;
	}

	private static function cut( string $value, int $max ): ?string {
		$value = trim( $value );
		return '' === $value ? null : mb_substr( $value, 0, $max );
	}
}

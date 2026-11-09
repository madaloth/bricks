<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Helpers {
	/**
	 * Get the contextual cart name, including selections on an "Any" variation.
	 *
	 * WooCommerce 11.2 adds this cart API; retain the product-name fallback for
	 * older supported versions. V2 title-only output uses get_title() instead
	 * because its separate item-data element already displays every attribute.
	 *
	 * @see https://github.com/woocommerce/woocommerce/pull/67527
	 * @since 2.4.3
	 *
	 * @param array       $cart_item Cart item data.
	 * @param \WC_Product $product   Product after the cart-item product filter.
	 * @return string
	 */
	public static function get_cart_item_product_name( $cart_item, $product ) {
		return WC()->cart && is_callable( [ WC()->cart, 'get_item_product_name' ] )
			? WC()->cart->get_item_product_name( $cart_item, $product )
			: $product->get_name();
	}

	/**
	 * Get the localized failed-order message for fallback and Builder previews.
	 *
	 * @since 2.4.3
	 * @return string
	 */
	public static function get_default_order_failed_text() {
		return esc_html__( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' );
	}

	/**
	 * Get the extension-filtered failed-order message with Woo's safe fallback.
	 *
	 * WooCommerce 11.2 introduces this filter. Keep permitted HTML from extensions
	 * while rejecting unsafe markup and falling back for non-string results.
	 * Synthetic Builder previews use the default helper directly because filter
	 * callbacks may require a real WC_Order as their second argument.
	 *
	 * @see https://github.com/woocommerce/woocommerce/pull/68221
	 * @since 2.4.3
	 *
	 * @param \WC_Order $order Failed order.
	 * @return string Sanitized HTML.
	 */
	public static function get_order_failed_text( $order ) {
		$default = self::get_default_order_failed_text();
		$text    = apply_filters( 'woocommerce_thankyou_order_failed_text', $default, $order );

		return wp_kses_post( is_string( $text ) ? $text : $default );
	}

	/**
	 * Get normalized cart item data for the modular cart and checkout elements.
	 *
	 * Unlike WooCommerce's classic formatter, variation attributes are not
	 * omitted when their values are part of the variation title. The modular
	 * product title intentionally excludes those attributes.
	 *
	 * @since 2.4
	 *
	 * @param array $cart_item Cart item data.
	 * @return array
	 */
	public static function get_cart_item_data( $cart_item ) {
		$item_data = [];
		$product   = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if (
			$product instanceof \WC_Product &&
			$product->is_type( 'variation' ) &&
			! empty( $cart_item['variation'] ) &&
			is_array( $cart_item['variation'] )
		) {
			foreach ( $cart_item['variation'] as $name => $value ) {
				if ( ! is_scalar( $value ) || (string) $value === '' ) {
					continue;
				}

				$value    = (string) $value;
				$taxonomy = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );

				if ( taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'slug', $value, $taxonomy );

					if ( ! is_wp_error( $term ) && $term && $term->name ) {
						$value = $term->name;
					}

					$label = wc_attribute_label( $taxonomy );
				} else {
					$value = apply_filters( 'woocommerce_variation_option_name', $value, null, $taxonomy, $product );
					$label = wc_attribute_label( str_replace( 'attribute_', '', $name ), $product );
				}

				if ( ! is_scalar( $value ) || (string) $value === '' ) {
					continue;
				}

				// Custom attributes can contain percent-encoded spaces/apostrophes. Decode
				// for display without applying classic name-based attribute suppression.
				$value = rawurldecode( (string) $value );

				$item_data[] = [
					'key'   => $label,
					'value' => $value,
					'slug'  => preg_replace( '/^attribute_/', '', $name ),
				];
			}
		}

		/**
		 * Keep extension-provided cart data compatible with WooCommerce's
		 * classic cart implementation.
		 */
		$item_data = apply_filters( 'woocommerce_get_item_data', $item_data, $cart_item );

		if ( ! is_array( $item_data ) ) {
			return [];
		}

		$normalized_data = [];

		foreach ( $item_data as $data ) {
			if (
				! is_array( $data ) ||
				! empty( $data['hidden'] ) ||
				! empty( $data['__experimental_woocommerce_blocks_hidden'] )
			) {
				continue;
			}

			$key     = ! empty( $data['key'] ) ? $data['key'] : ( $data['name'] ?? '' );
			$value   = array_key_exists( 'value', $data ) ? $data['value'] : '';
			$display = ! empty( $data['display'] ) ? $data['display'] : $value;
			$entry   = self::normalize_item_data_entry( $key, $value, $display );

			if ( $entry === null ) {
				continue;
			}

			// Attribute identity must not depend on its translated display label.
			$identifier    = $data['slug'] ?? $key;
			$identifier    = self::is_item_data_value_renderable( $identifier ) && (string) $identifier !== '' ? (string) $identifier : (string) $key;
			$identifier    = wp_strip_all_tags( rawurldecode( $identifier ) );
			$entry['slug'] = sanitize_title( $identifier );

			// Non-Latin custom slugs and label-only extension data may lose all or
			// part of their identity when converted to an ASCII modifier class.
			if ( $entry['slug'] === '' || sanitize_html_class( $entry['slug'] ) !== $entry['slug'] ) {
				$entry['slug'] = 'item-' . md5( $identifier );
			}

			$normalized_data[] = $entry;
		}

		return $normalized_data;
	}

	/**
	 * Get normalized metadata for a persisted WooCommerce order item.
	 *
	 * Including all formatted metadata is intentional: the modular order item
	 * title can exclude variation attributes, so those attributes must remain
	 * available to the dedicated data element. WooCommerce's formatted metadata
	 * API also preserves its official display-key, display-value, and final-data
	 * filters for extensions.
	 *
	 * @since 2.4
	 *
	 * @param mixed $item WooCommerce order item product.
	 * @return array
	 */
	public static function get_order_item_data( $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return [];
		}

		$formatted_meta = $item->get_formatted_meta_data( '_', true );

		if ( ! is_array( $formatted_meta ) ) {
			return [];
		}

		$normalized_data = [];

		foreach ( $formatted_meta as $meta ) {
			if ( ! is_object( $meta ) ) {
				continue;
			}

			$key     = isset( $meta->display_key ) ? $meta->display_key : ( $meta->key ?? '' );
			$value   = isset( $meta->value ) ? $meta->value : '';
			$display = isset( $meta->display_value ) ? $meta->display_value : $value;
			$entry   = self::normalize_item_data_entry( $key, $value, $display );

			if ( $entry === null ) {
				continue;
			}

			$normalized_data[] = $entry;
		}

		return $normalized_data;
	}

	/**
	 * Normalize a cart or order item data entry.
	 *
	 * Both WooCommerce sources can be modified by extensions, so this shared
	 * boundary keeps their renderability and fallback behavior identical.
	 *
	 * @since 2.4
	 *
	 * @param mixed $key     Item data key.
	 * @param mixed $value   Raw item data value.
	 * @param mixed $display Formatted item data value.
	 * @return array|null
	 */
	private static function normalize_item_data_entry( $key, $value, $display ) {
		if ( ! self::is_item_data_value_renderable( $key ) || ! self::is_item_data_value_renderable( $display ) ) {
			return null;
		}

		if ( ! self::is_item_data_value_renderable( $value ) ) {
			$value = $display;
		}

		$key     = (string) $key;
		$value   = (string) $value;
		$display = (string) $display;

		if ( $key === '' || $display === '' ) {
			return null;
		}

		return [
			'key'     => $key,
			'value'   => $value,
			'display' => $display,
			'slug'    => sanitize_title( wp_strip_all_tags( $key ) ),
		];
	}

	/**
	 * Check whether an item data value can be safely converted to a string.
	 *
	 * Extensions can pass arrays or other non-renderable values through the
	 * WooCommerce cart and order item metadata filters.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Item data value.
	 * @return boolean
	 */
	private static function is_item_data_value_renderable( $value ) {
		return is_scalar( $value ) || ( is_object( $value ) && method_exists( $value, '__toString' ) );
	}

	/**
	 * Product query controls (products, related products, upsells)
	 *
	 * @param array $args Arguments to merge (e.g. control 'group').
	 */
	public static function get_product_query_controls( $args = false ) {
		$query_controls = [
			'posts_per_page'          => [
				'tab'   => 'content',
				'label' => esc_html__( 'Products per page', 'bricks' ),
				'type'  => 'number',
				'min'   => -1,
				'step'  => 1,
			],

			'orderby'                 => [
				'tab'         => 'content',
				'label'       => esc_html__( 'Order by', 'bricks' ),
				'type'        => 'select',
				'options'     => [
					'price'      => esc_html__( 'Price', 'bricks' ),
					'popularity' => esc_html__( 'Popularity', 'bricks' ),
					'rating'     => esc_html__( 'Rating', 'bricks' ),
					'name'       => esc_html__( 'Name', 'bricks' ),
					'rand'       => esc_html__( 'Random', 'bricks' ),
					'date'       => esc_html__( 'Published date', 'bricks' ),
					'modified'   => esc_html__( 'Modified date', 'bricks' ),
					'menu_order' => esc_html__( 'Menu order', 'bricks' ),
					'id'         => esc_html__( 'Product ID', 'bricks' ),
					'_default'   => esc_html__( 'Default', 'bricks' ), // Use WP default order (@since 1.12)
				],
				'inline'      => true,
				'placeholder' => esc_html__( 'Date', 'bricks' ),
			],

			'order'                   => [
				'tab'         => 'content',
				'label'       => esc_html_x( 'Order', 'query sorting', 'bricks' ),
				'type'        => 'select',
				'options'     => [
					'ASC'  => esc_html__( 'Ascending', 'bricks' ),
					'DESC' => esc_html__( 'Descending', 'bricks' ),
				],
				'inline'      => true,
				'placeholder' => esc_html__( 'Descending', 'bricks' ),
			],

			'productType'             => [
				'tab'         => 'content',
				'label'       => esc_html__( 'Product type', 'bricks' ),
				'type'        => 'select',
				'options'     => wc_get_product_types(),
				'multiple'    => true,
				'placeholder' => esc_html__( 'Select product type', 'bricks' ),
			],

			'include'                 => [
				'tab'         => 'content',
				'label'       => esc_html__( 'Include', 'bricks' ),
				'type'        => 'select',
				'optionsAjax' => [
					'action'   => 'bricks_get_posts',
					'postType' => 'product',
				],
				'multiple'    => true,
				'searchable'  => true,
				'placeholder' => esc_html__( 'Select products', 'bricks' ),
			],

			'exclude'                 => [
				'tab'         => 'content',
				'label'       => esc_html__( 'Exclude', 'bricks' ),
				'type'        => 'select',
				'optionsAjax' => [
					'action'   => 'bricks_get_posts',
					'postType' => 'product',
				],
				'multiple'    => true,
				'searchable'  => true,
				'placeholder' => esc_html__( 'Select products', 'bricks' ),
			],

			'categories'              => [
				'tab'      => 'content',
				'label'    => esc_html__( 'Product categories', 'bricks' ),
				'type'     => 'select',
				'options'  => Woocommerce::$product_categories,
				'multiple' => true,
			],

			'tags'                    => [
				'tab'      => 'content',
				'label'    => esc_html__( 'Product tags', 'bricks' ),
				'type'     => 'select',
				'options'  => Woocommerce::$product_tags,
				'multiple' => true,
			],

			'onSale'                  => [
				'tab'   => 'content',
				'label' => esc_html__( 'On sale', 'bricks' ),
				'type'  => 'checkbox',
			],

			'featured'                => [
				'tab'   => 'content',
				'label' => esc_html__( 'Featured', 'bricks' ),
				'type'  => 'checkbox',
			],

			'hideOutOfStock'          => [
				'tab'   => 'content',
				'label' => esc_html__( 'Hide out of stock', 'bricks' ),
				'type'  => 'checkbox',
			],

			'is_archive_main_query'   => [
				'tab'    => 'content',
				'label'  => esc_html__( 'Is main query', 'bricks' ),
				'type'   => 'checkbox',
				'inline' => true,
			],

			'woo_disable_query_merge' => [
				'tab'    => 'content',
				'label'  => esc_html__( 'Disable query merge', 'bricks' ),
				'type'   => 'checkbox',
				'inline' => true,
			],
		];

		// Display info if builderQueryMaxResults is set (@since 1.11)
		$builder_query_max_results = Builder::get_query_max_results();
		if ( $builder_query_max_results ) {
			$query_controls = array_merge(
				[
					'queryMaxResultsInfo' => [
						'tab'     => 'content',
						'type'    => 'info',
						'content' => Builder::get_query_max_results_info(),
					],
				],
				$query_controls
			);
		}

		if ( is_array( $args ) ) {
			foreach ( $query_controls as $key => $control ) {
				$query_controls[ $key ] = array_merge( $query_controls[ $key ], $args );
			}
		}

		return $query_controls;
	}

	/**
	 * Default order by control options
	 */
	public static function get_default_orderby_control_options() {
		// NOTE: Undocumented
		$options = apply_filters(
			'bricks/woocommerce/products_orderby_options',
			[
				'menu_order' => esc_html__( 'Default sorting', 'bricks' ),
				'popularity' => esc_html__( 'Sort by popularity', 'bricks' ),
				'rating'     => esc_html__( 'Sort by average rating', 'bricks' ),
				'date'       => esc_html__( 'Sort by latest', 'bricks' ),
				'price'      => esc_html__( 'Sort by price: low to high', 'bricks' ),
				'price-desc' => esc_html__( 'Sort by price: high to low', 'bricks' ),
			]
		);

		return $options;
	}

	public static function get_filters_list( $flat = true ) {
		$options['other'] = [
			'reset'  => [
				'name'  => 'reset',
				'group' => 'other',
				'label' => esc_html__( 'Reset filters', 'bricks' ),
			],
			'price'  => [
				'name'  => 'price',
				'group' => 'other',
				'label' => esc_html__( 'Product price', 'bricks' ),
			],
			'rating' => [
				'name'  => 'rating',
				'group' => 'other',
				'label' => esc_html__( 'Product rating', 'bricks' ),
			],
			'stock'  => [
				'name'  => 'stock',
				'group' => 'other',
				'label' => esc_html__( 'Product stock', 'bricks' ),
			],
			'search' => [
				'name'  => 'search',
				'group' => 'other',
				'label' => esc_html__( 'Product search', 'bricks' ),
			],
		];

		// Taxonomies
		$taxonomies = get_object_taxonomies( 'product', 'objects' );

		foreach ( $taxonomies as $name => $taxonomy ) {
			$group = strpos( $name, 'pa_' ) === 0 ? 'attribute' : 'taxonomy';

			$options[ $group ][ $name ] = [
				'name'  => $name,
				'group' => $group,
				'label' => $taxonomy->label,
				'query' => 'taxonomy',
			];
		}

		if ( $flat ) {
			$options_flat = [];

			foreach ( $options as $group => $list ) {
				$options_flat = array_merge( $options_flat, $list );
			}

			return $options_flat;
		}

		return $options;
	}

	/**
	 * Is product archive page
	 *
	 * @return boolean
	 */
	public static function is_archive_product() {
		$is_default_product_archive = is_tax( 'product_cat' ) || is_tax( 'product_tag' ) || is_post_type_archive( 'product' );

		if ( $is_default_product_archive ) {
			return $is_default_product_archive;
		}

		// Check for product archive of a custom taxonomy (since 1.5)
		$queried_object = get_queried_object();

		if ( is_a( $queried_object, 'WP_Term' ) ) {
			$taxonomy = get_taxonomy( $queried_object->taxonomy );

			return isset( $taxonomy->object_type ) && in_array( 'product', $taxonomy->object_type );
		}

		return false;
	}

	/**
	 * True when request/context filters must be skipped.
	 *
	 * @since 2.3
	 */
	private static function should_skip_request_filters( $settings, $bricks_query_var, $options ) {
		$skip_request_filters = ! empty( $options['skip_request_filters'] );

		return $skip_request_filters ||
			isset( $settings['woo_disable_query_merge'] ) ||
			isset( $settings['disable_query_merge'] ) ||
			isset( $bricks_query_var['woo_disable_query_merge'] ) ||
			isset( $bricks_query_var['disable_query_merge'] );
	}

	/**
	 * Calculate the filters query args based on the URL parameters and element settings
	 * DO NOT early return!
	 * WooCommerce query
	 *
	 * https://github.com/woocommerce/woocommerce/wiki/wc_get_products-and-WC_Product_Query
	 * https://docs.woocommerce.com/wc-apidocs/class-WC_Product.html
	 *
	 * @since 1.5
	 *
	 * @param array  $settings The element settings.
	 * @param array  $bricks_query_var Generated query_vars from Bricks_Query.
	 * @param string $element_name The element name.
	 * @param array  $options Additional options for query args generation. (@since 2.3)
	 * @return array
	 */
	public static function filters_query_args( $settings, $bricks_query_var = [], $element_name = '', $options = [] ) {
		// STEP: Convert Query Loop settings into Products settings (e.g. posts_per_page, orderby, order ...)
		if ( isset( $settings['query'] ) ) {
			$settings = wp_parse_args( $settings, $settings['query'] );
		}

		// Determine if request filters should be skipped based on settings, query vars or options (#86c4urb2r; @since 2.3)
		$skip_request_filters = self::should_skip_request_filters( $settings, $bricks_query_var, $options );

		// Flag if the element is a Products element
		$is_products_element = $element_name === 'woocommerce-products';

		// STEP: Calculate the product query args
		$product_args = [];
		$tax_query    = [];

		// Check if loading a Bricks template, set preview
		if ( get_post_type() === BRICKS_DB_TEMPLATE_SLUG ) {
			$post_id      = get_the_ID();
			$preview_type = Helpers::get_template_setting( 'templatePreviewType', $post_id );

			if ( $preview_type == 'archive-term' ) {
				$preview_term = Helpers::get_template_setting( 'templatePreviewTerm', $post_id );

				if ( ! empty( $preview_term ) ) {
					$preview_term     = explode( '::', $preview_term );
					$preview_taxonomy = isset( $preview_term[0] ) ? $preview_term[0] : '';
					$preview_term_id  = isset( $preview_term[1] ) ? intval( $preview_term[1] ) : '';

					if ( $preview_taxonomy && $preview_term_id ) {
						$tax_query[ $preview_taxonomy ] = [
							'taxonomy' => $preview_taxonomy,
							'field'    => 'term_id',
							'terms'    => $preview_term_id,
						];
					}
				}
			}
		}

		if ( $skip_request_filters ) {
			// Skip WooCommerce request/context meta queries. Bricks query vars are preserved later. (#86canmfa5; @since 2.4)
			$product_args['meta_query'] = [];
		} else {
			// Appends meta queries from filter 'woocommerce_product_query_meta_query'
			$product_args['meta_query'] = WC()->query->get_meta_query();
		}

		// Get the product visibility terms, will be used in the query in multiple places
		$product_visibility_terms = wc_get_product_visibility_term_ids();

		// Settings: Exclude Out of Stock
		if ( isset( $settings['hideOutOfStock'] ) ) {
			$product_visibility_not_in[] = $product_visibility_terms['outofstock'];

			if ( ! empty( $product_visibility_not_in ) ) {
				$tax_query['product_visibility_not_in'] = [
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => $product_visibility_not_in,
					'operator' => 'NOT IN',
				];
			}
		}

		// Settings: Product type (tax_query)
		if ( ! empty( $settings['productType'] ) ) {
			$tax_query['product_type'] = [
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $settings['productType'],
			];
		}

		// Settings: Product category (tax_query)
		if ( ! empty( $settings['categories'] ) ) {
			$tax_query['product_cat'] = [
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $settings['categories'],
			];
		}

		// Settings: Product tag (tax_query)
		if ( ! empty( $settings['tags'] ) ) {
			$tax_query['product_tag'] = [
				'taxonomy' => 'product_tag',
				'field'    => 'term_id',
				'terms'    => $settings['tags'],
			];
		}

		// Collect all post__in arrays to intersect later (#86c6d80ba; @since 2.2)
		$post_in_collections = [];

		// Settings: Include products
		if ( ! empty( $settings['include'] ) ) {
			// Save to collection for later intersection
			$post_in_collections['queryInclude'] = $settings['include'];
		}

		// Query Loop (since 1.5)
		elseif ( ! empty( $settings['post__in'] ) ) {
			// Save to collection for later intersection
			$post_in_collections['queryInclude'] = $settings['post__in'];
		}

		// Settings: Exclude products
		if ( ! empty( $settings['exclude'] ) ) {
			$product_args['post__not_in'] = $settings['exclude'];
		}

		// Query Loop (since 1.5)
		elseif ( ! empty( $settings['post__not_in'] ) ) {
			$product_args['post__not_in'] = $settings['post__not_in'];
		}

		// @since 1.8 - Consider exclude current post
		if ( isset( $settings['exclude_current_post'] ) ) {
			if ( is_single() || is_page() ) {
				$product_args['post__not_in'][] = get_the_ID();
			}
		}

		// Show only products featured (tax_query)
		if ( isset( $settings['featured'] ) ) {
			$visibility_term_ids = wc_get_product_visibility_term_ids();

			$tax_query['product_visibility'] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => [ $visibility_term_ids['featured'] ],
			];
		}

		// Show only products on sale (post__in)
		if ( isset( $settings['onSale'] ) ) {
			// Default to an empty array for this collection
			$post_in_collections['onSale'] = [];
			$on_sale_ids                   = wc_get_product_ids_on_sale();

			if ( ! empty( $on_sale_ids ) ) {
				$post_in_collections['onSale'] = $on_sale_ids;
			}
		}

		// Upsell (@since 1.10)
		if ( isset( $settings['upSells'] ) ) {
			// Default to an empty array for this collection
			$post_in_collections['upSells'] = [];

			// Get the current product
			$product_id = Database::$page_data['preview_or_post_id'] ?? get_the_ID();
			$product    = wc_get_product( $product_id );

			// Ensure it's a product
			if ( is_a( $product, 'WC_Product' ) ) {
				$upsell_ids = $product->get_upsell_ids();

				if ( ! empty( $upsell_ids ) ) {
					$post_in_collections['upSells'] = $upsell_ids;
				}
			}
		}

		// Cart Cross-sell (@since 1.10)
		if ( isset( $settings['cartCrossSells'] ) ) {
			// Default to an empty array for this collection
			$post_in_collections['cartCrossSells'] = [];

			// Cross-sells should only get from cart data
			if ( function_exists( 'WC' ) && WC()->cart ) {
				// Get cross-sell products
				$cross_sell_ids = WC()->cart->get_cross_sells();

				if ( ! empty( $cross_sell_ids ) ) {
					$post_in_collections['cartCrossSells'] = $cross_sell_ids;
				}
			}
		}

		// Product Cross-sell (@since 1.11.1)
		if ( isset( $settings['crossSells'] ) ) {
			// Default to an empty array for this collection
			$post_in_collections['crossSells'] = [];
			// Get the current product
			$product_id = Database::$page_data['preview_or_post_id'] ?? get_the_ID();
			$product    = wc_get_product( $product_id );

			if ( is_a( $product, 'WC_Product' ) ) {
				// Get cross-sell products
				$cross_sell_ids = $product->get_cross_sell_ids();

				if ( ! empty( $cross_sell_ids ) ) {
					$post_in_collections['crossSells'] = $cross_sell_ids;
				}
			}
		}

		// Related Products (@since 1.10)
		if ( isset( $settings['relatedProducts'] ) ) {
			// Default to an empty array for this collection
			$post_in_collections['relatedProducts'] = [];
			// Get the current product
			$product_id = Database::$page_data['preview_or_post_id'] ?? get_the_ID();
			$product    = wc_get_product( $product_id );

			// Ensure it's a product
			if ( is_a( $product, 'WC_Product' ) ) {
				// Get related products
				$limit       = isset( $settings['posts_per_page'] ) ? intval( $settings['posts_per_page'] ) : 5;
				$related_ids = self::get_related_product_ids( $product, $limit, isset( $settings['hideOutOfStock'] ) );

				if ( ! empty( $related_ids ) ) {
					$post_in_collections['relatedProducts'] = $related_ids;
				}
			}
		}

		// Intersect all post__in collections (AND operation) (@since 2.2)
		if ( ! empty( $post_in_collections ) ) {
			// Check if any collection is empty (no results available)
			$has_empty_collection = false;
			$posts_in_ids         = [];
			foreach ( $post_in_collections as $collection ) {
				if ( empty( $collection ) ) {
					$has_empty_collection = true;
					break;
				}
				// WooCommerce preserves keys when removing in-cart cross-sells, so the first value may not use key 0. (#86cb1pxt8; @since 2.3.11)
				if ( count( $collection ) === 1 && intval( reset( $collection ) ) === 0 ) {
					$has_empty_collection = true;
					break;
				}
			}

			// If any collection is empty, no products should match
			if ( $has_empty_collection ) {
				$posts_in_ids = [ 0 ]; // Use 0 instead of 999999 to ensure no products
			} else {
				// Get the first collection without removing it
				$intersected_ids = reset( $post_in_collections );

				// Skip the first element and intersect with remaining collections
				$remaining_collections = array_slice( $post_in_collections, 1 );

				foreach ( $remaining_collections as $collection ) {
					$intersected_ids = array_intersect( $intersected_ids, $collection );
				}

				if ( ! empty( $intersected_ids ) ) {
					$posts_in_ids = array_values( $intersected_ids );
				} else {
					// Intersection resulted in no common products
					$posts_in_ids = [ 0 ];
				}
			}

			$product_args['post__in'] = $posts_in_ids;
		}

		// Ensure post_type is set to product and product_variation for cross-sells and upsells (@since 1.10)
		if ( isset( $settings['crossSells'] ) || isset( $settings['upSells'] ) || isset( $settings['cartCrossSells'] ) ) {
			if ( ! isset( $product_args['post_type'] ) ) {
				$product_args['post_type'] = [ 'product', 'product_variation' ];
			}

			elseif ( is_array( $product_args['post_type'] ) ) {
				if ( ! in_array( 'product_variation', $product_args['post_type'] ) ) {
					$product_args['post_type'][] = 'product_variation';
				}
				if ( ! in_array( 'product', $product_args['post_type'] ) ) {
					$product_args['post_type'][] = 'product';
				}
			}

			else {
				$selected_post_type = $product_args['post_type'];
				if ( $selected_post_type !== 'product' && $selected_post_type !== 'product_variation' ) {
					$product_args['post_type'] = [ 'product', 'product_variation' ];
				} else {
					$product_args['post_type'] = [ $selected_post_type, 'product_variation', 'product' ];
				}
			}
		}

		// Posts per page
		if ( $is_products_element ) {
			// Retrieve the posts per page from the Products element settings (@since 1.11.1.1)
			$product_args['posts_per_page'] = isset( $settings['posts_per_page'] ) ? intval( $settings['posts_per_page'] ) : get_option( 'posts_per_page' );
		} else {
			// Retrieve the posts per page from the Query Loop settings
			$product_args['posts_per_page'] = isset( $bricks_query_var['posts_per_page'] ) ? intval( $bricks_query_var['posts_per_page'] ) : get_option( 'posts_per_page' );
		}

		$woo_sort_url = ''; // Sorting parameter by Products orderby element

		$variation_attribute_query_var = $bricks_query_var;

		// STEP: Prepare a separate set of query vars for variation attribute filters and stock logic (#86c70262a; @since 2.4)
		if ( ! empty( $tax_query ) ) {
			// Ensure variation-aware matching sees the same template/settings tax constraints.
			$variation_attribute_tax_query = [];

			if ( ! empty( $variation_attribute_query_var['tax_query'] ) && is_array( $variation_attribute_query_var['tax_query'] ) ) {
				$variation_attribute_tax_query = $variation_attribute_query_var['tax_query'];
			}

			foreach ( $tax_query as $tax_filter ) {
				if ( ! is_array( $tax_filter ) || empty( $tax_filter['taxonomy'] ) ) {
					continue;
				}

				$variation_attribute_tax_query[] = $tax_filter;
			}

			if ( ! empty( $variation_attribute_tax_query ) ) {
				$variation_attribute_tax_query['relation']  = 'AND';
				$variation_attribute_query_var['tax_query'] = $variation_attribute_tax_query;
			}
		}

		$variation_attribute_filters = self::get_selected_attribute_filters( $variation_attribute_query_var, ! $skip_request_filters );
		$variation_stock_mode        = '';
		$variation_aware_stock_query = self::use_variation_aware_attribute_stock_logic() && ! empty( $variation_attribute_filters );

		// Related to Filter, only run these if not skipped, because they are based on URL parameters and should not apply when skip_request_filters is true (#86c4urb2r; @since 2.3)
		if ( ! $skip_request_filters ) {
			// Filter: Orderby
			if ( isset( $_GET['orderby'] ) ) {
				$woo_sort_url = sanitize_text_field( wp_unslash( $_GET['orderby'] ) );
			}

			// Filter: Rating (tax_query)
			if ( isset( $_GET['b_rating'] ) ) {
				$rating_filter = array_filter( array_map( 'absint', (array) wp_unslash( $_GET['b_rating'] ) ) );

				// Include products rated with equal or higher ratings
				if ( count( $rating_filter ) == 1 ) {
					$rating_filter = range( $rating_filter[0], 5 );
				}

				$rating_terms = [];

				foreach ( range( 1, 5 ) as $key ) {
					if ( in_array( $key, $rating_filter, true ) && isset( $product_visibility_terms[ 'rated-' . $key ] ) ) {
						$rating_terms[] = $product_visibility_terms[ 'rated-' . $key ];
					}
				}

				if ( ! empty( $rating_terms ) ) {
					$tax_query['product_visibility_rating'] = [
						'taxonomy'      => 'product_visibility',
						'field'         => 'term_taxonomy_id',
						'terms'         => $rating_terms,
						'operator'      => 'IN',
						'rating_filter' => true,
					];
				}
			}

			// Filter: Stock (meta_query)
			$stock_filter = self::get_request_stock_filters();

			if ( ! empty( $stock_filter ) ) {
				$filter = $stock_filter;

				$stock_defaults = wc_get_product_stock_status_options();
				$default_filter = array_intersect( $filter, array_keys( $stock_defaults ) );

				if ( ! empty( $default_filter ) ) {
					if ( count( $default_filter ) === 1 ) {
						$variation_stock_mode = reset( $default_filter );
					} elseif ( count( array_intersect( $default_filter, [ 'instock', 'onbackorder' ] ) ) === count( $default_filter ) ) {
						$variation_stock_mode = 'available';
					}

					$skip_parent_stock_meta_query = $variation_aware_stock_query && $variation_stock_mode;

					// Only apply stock status meta query when not using variation-aware stock logic, or when the stock mode is 'instock' or 'onbackorder' (since 2.4)
					if ( ! $skip_parent_stock_meta_query ) {
						$product_args['meta_query'][] = [
							'key'     => '_stock_status',
							'value'   => $default_filter,
							'compare' => 'IN',
						];
					}
				} elseif ( in_array( 'lowstock', $filter, true ) ) {
					$low_amount                             = absint( max( get_option( 'woocommerce_notify_low_stock_amount' ), 2 ) );
					$product_args['meta_query']['relation'] = 'AND';
					$product_args['meta_query'][]           = [
						'key'     => '_stock',
						'type'    => 'numeric',
						'value'   => $low_amount,
						'compare' => '<=',
					];
					$product_args['meta_query'][]           = [
						'key'     => '_stock_status',
						'value'   => 'instock',
						'compare' => '=',
					];
				}
			}

			// Filter: Search (s)
			if ( ( ! empty( $_GET['b_search'] ) || ! empty( $_GET['s'] ) ) ) {
				$product_args['s'] = ! empty( $_GET['b_search'] ) ? sanitize_text_field( $_GET['b_search'] ) : sanitize_text_field( $_GET['s'] );
			}

			// Filter: Products Pagination (paged)
			if ( ! empty( $_GET['product-page'] ) && ! isset( $settings['woo_disable_query_merge'] ) ) {
				$product_args['paged'] = sanitize_text_field( $_GET['product-page'] );
			}

			// Filter by price (meta query)
			$product_args = self::set_price_query_args( $product_args );
		}

		// Determine variation stock mode based on settings and filters (#86c70262a; @since 2.4)
		if ( isset( $settings['hideOutOfStock'] ) && ! $variation_stock_mode ) {
			$variation_stock_mode = 'available';
		}

		// Apply variation-aware attribute stock filter if needed (#86c70262a; @since 2.4)
		if ( ! empty( $variation_attribute_filters ) && $variation_stock_mode ) {
			$product_args = self::maybe_apply_variation_aware_attribute_stock_filter(
				$product_args,
				$variation_attribute_filters,
				$variation_stock_mode
			);
		}

		// Tax Query logic Start
		// Merge tax_query with the filters
		if ( ! $skip_request_filters ) {
			// Get the possible filters list
			$filters = self::get_filters_list();

			foreach ( $filters as $name => $filter ) {
				if ( ! isset( $_GET[ 'b_' . $name ] ) || ! isset( $filter['query'] ) || 'taxonomy' != $filter['query'] ) {
					continue;
				}

				$value = wp_unslash( $_GET[ 'b_' . $name ] );

				if ( ! empty( $value ) ) {
					$terms = array_filter( array_map( 'absint', (array) $value ) );

					if ( array_key_exists( $name, $tax_query ) ) {
						$terms = array_intersect( $terms, (array) $tax_query[ $name ]['terms'] );
					}

					$tax_query[ $name ] = [
						'taxonomy' => $name,
						'field'    => 'term_id',
						'terms'    => $terms,
					];
				}
			}
		}

		// Add tax_query to the main query args
		if ( ! empty( $tax_query ) ) {
			$tax_query = array_values( $tax_query );

			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
		}

		if ( $skip_request_filters ) {
			// Keep only element/settings-driven tax_query (#86c4urb2r; @since 2.3)
			if ( ! empty( $tax_query ) && is_array( $tax_query ) && ! isset( $tax_query['relation'] ) ) {
				$tax_query['relation'] = 'AND';
			}

			if ( ! empty( $tax_query ) ) {
				$product_args['tax_query'] = $tax_query;
			}
		} else {
			// Use WooCommerce helper to add necessary tax_query (#86ca28jp7; @since 2.3.7)
			$product_args['tax_query'] = WC()->query->get_tax_query( $tax_query, false );
		}

		unset( $tax_query );
		// Tax Query logic End

		// Order & Orderby
		// Has sorting parameter, directly use the orderby and order generated by WooCommerce
		if ( $woo_sort_url !== '' ) {
			// Using the sorting parameter from Products orderby element
			if ( $woo_sort_url == 'price' ) {
				$woo_sort_url = 'price-asc';
			}

			if ( $woo_sort_url == 'date' ) {
				$woo_sort_url = 'date-desc';
			}

			$orderby_value = explode( '-', $woo_sort_url ); // e.g. orderby=price-desc
			$wc_order_by   = esc_attr( $orderby_value[0] );
			$wc_order      = strtoupper( isset( $orderby_value[1] ) && ! empty( $orderby_value[1] ) ? $orderby_value[1] : '' );

			// @see: WC_Shortcode_Products::parse_query_args() [woocommerce/includes/shortcodes/class-wc-shortcode-products.php]
			// price, popularity, and rating will add posts_clauses to the query
			$ordering_args           = WC()->query->get_catalog_ordering_args( $wc_order_by, $wc_order );
			$product_args['orderby'] = $ordering_args['orderby'];
			$product_args['order']   = $ordering_args['order'];

			// Ordering by meta key
			if ( $ordering_args['meta_key'] ) {
				$product_args['meta_key'] = $ordering_args['meta_key'];
			}

		}

		// Use the orderby and order from the element settings
		else {

			// Check if this query loop has order settings (not products element)
			$query_loop_has_order_settings = ! $is_products_element && isset( $bricks_query_var['orderby'] );

			// If this is products element or the query loop without order settings, use WooCommerce default order settings
			if ( $is_products_element || ! $query_loop_has_order_settings ) {
				$products_element_orderby = $is_products_element && ! empty( $settings['orderby'] ) && is_string( $settings['orderby'] ) ? $settings['orderby'] : 'date';
				$products_element_order   = $is_products_element && ! empty( $settings['order'] ) && is_string( $settings['order'] ) ? $settings['order'] : 'DESC';

				// orderby already unset if this is query loop with order settings, check if brx_default_orderby is set (@since 1.12)
				$query_loop_use_wp_default = ! $is_products_element && isset( $bricks_query_var['brx_default_orderby'] );

				// Default Woo Sorting (@since 1.12)
				if ( $products_element_orderby === '_default' || $query_loop_use_wp_default ) {
					$products_element_orderby = '';
					$products_element_order   = '';
				}

				$orderby_value = explode( '-', $products_element_orderby ); // e.g. orderby=price-desc
				$wc_order_by   = esc_attr( $orderby_value[0] );
				$wc_order      = strtoupper( isset( $orderby_value[1] ) && ! empty( $orderby_value[1] ) ? $orderby_value[1] : $products_element_order );

				// @see: WC_Shortcode_Products::parse_query_args() [woocommerce/includes/shortcodes/class-wc-shortcode-products.php]
				// price, popularity, and rating will add posts_clauses to the query
				$ordering_args = WC()->query->get_catalog_ordering_args( $wc_order_by, $wc_order );

				if ( isset( $ordering_args['orderby'] ) ) {
					$product_args['orderby'] = $ordering_args['orderby'];
				}

				if ( isset( $ordering_args['order'] ) ) {
					$product_args['order'] = $ordering_args['order'];
				}

				// Ordering by meta key
				if ( isset( $ordering_args['meta_key'] ) ) {
					$product_args['meta_key'] = $ordering_args['meta_key'];
				}
			}

			// Query loop with user defined orderby and order settings
			else {

				// Query loop with user defined orderby and order settings
				$product_args['orderby'] = $bricks_query_var['orderby'] ?? 'date'; // Default orderby
				$product_args['order']   = $bricks_query_var['order'] ?? 'DESC'; // Default order

				// Ordering by meta key
				if ( isset( $bricks_query_var['meta_key'] ) ) {
					$product_args['meta_key'] = $bricks_query_var['meta_key'];
				}
			}
		}

		return $product_args;
	}

	/**
	 * Get related product IDs for a products query.
	 *
	 * @since 2.3.7
	 *
	 * @param \WC_Product $product Product object.
	 * @param int         $limit Number of related products to return.
	 * @param bool        $hide_out_of_stock Whether out-of-stock products should be hidden.
	 *
	 * @return array
	 */
	private static function get_related_product_ids( $product, $limit, $hide_out_of_stock = false ) {
		$product_id  = $product->get_id();
		$exclude_ids = $product->get_upsell_ids();

		if ( ! $hide_out_of_stock ) {
			return wc_get_related_products( $product_id, $limit, $exclude_ids );
		}

		$hide_out_of_stock_filter = static function() {
			return 'yes';
		};

		// WooCommerce excludes out-of-stock products before slicing related IDs only via its global option.
		add_filter( 'pre_option_woocommerce_hide_out_of_stock_items', $hide_out_of_stock_filter, PHP_INT_MAX, 0 );

		try {
			return wc_get_related_products(
				$product_id,
				$limit,
				$exclude_ids,
				[
					'bricks_hide_out_of_stock' => true,
				]
			);
		} finally {
			remove_filter( 'pre_option_woocommerce_hide_out_of_stock_items', $hide_out_of_stock_filter, PHP_INT_MAX );
		}
	}

	/**
	 * Set query args for price filter
	 *
	 * @param array $args
	 * @return array
	 */
	public static function set_price_query_args( $args ) {
		if ( ! isset( $_GET['max_price'] ) && ! isset( $_GET['min_price'] ) ) {
			return $args;
		}

		$value_min = isset( $_GET['min_price'] ) ? floatval( wp_unslash( $_GET['min_price'] ) ) : 0;
		$value_max = isset( $_GET['max_price'] ) ? floatval( wp_unslash( $_GET['max_price'] ) ) : PHP_INT_MAX;

		if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ) && ! wc_prices_include_tax() ) {
			$tax_class = apply_filters( 'woocommerce_price_filter_widget_tax_class', '' );
			$tax_rates = WC_Tax::get_rates( $tax_class );

			if ( $tax_rates ) {
				$value_min -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $value_min, $tax_rates ) );
				$value_max -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $value_max, $tax_rates ) );
			}
		}

		$args['meta_query'][] = [
			'key'     => '_price',
			'value'   => [ $value_min, $value_max ],
			'compare' => 'BETWEEN',
			'type'    => 'NUMERIC',
		];

		return $args;
	}

	/**
	 * Gets the first element from a flat list that contains a products query (Products element or Query Loop builder set to products)
	 *
	 * @since 1.5
	 *
	 * @param array $data
	 * @return array|boolean
	 */
	public static function get_products_element( $data = [] ) {
		// Get data from Database::$active_templates instead of $post_id (@since 1.9.5)
		$data = ! empty( $data ) ? $data : Database::get_data( Database::$active_templates['content'], 'content' );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		foreach ( $data as $element ) {
			if (
				$element['name'] === 'woocommerce-products' ||
				(
					isset( $element['settings']['hasLoop'] ) &&
					! empty( $element['settings']['query']['post_type'] ) &&
					in_array( 'product', $element['settings']['query']['post_type'] ) &&
					( empty( $element['settings']['query']['objectType'] ) || $element['settings']['query']['objectType'] == 'post' )
				)
			 ) {
				return $element;
			}
		}

		return false;
	}

	/**
	 * Get the products query based on a Products element present in the content of a page
	 *
	 * @param string $post_id
	 * @return WP_Query|boolean false if products element not found
	 */
	public static function get_products_element_query( $post_id ) {
		$cache_key = "get_products_element_query_$post_id";

		$query = wp_cache_get( $cache_key, 'bricks' );

		if ( $query !== false ) {
			return $query;
		}

		$query_element = self::get_products_element();

		if ( ! $query_element ) {
			return false;
		}

		// Force the post type to feed the Bricks Query class
		if ( empty( $query_element['settings']['query'] ) ) {
			$query_element['settings']['query'] = [
				'post_type'           => [ 'product' ],
				'ignore_sticky_posts' => 1
			];
		}
		// Query
		$query_object = new Query( $query_element );

		$query = $query_object->query_result;

		// Infinite loop if query is empty result inside builder (@since 1.8.2) (#862k16hwz)
		$query_object->destroy();

		wp_cache_set( $cache_key, $query, 'bricks', MINUTE_IN_SECONDS );

		return $query;
	}

	/**
	 * Helper function to set the cart variables for better builder preview
	 *
	 * @return void
	 */
	public static function maybe_init_cart_context() {
		if ( is_cart() ) {
			return;
		}

		wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );

		// Check cart items are valid
		do_action( 'woocommerce_check_cart_items' );

		// Calculate totals
		if ( WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Maybe add products to the cart if cart is empty for better builder preview
	 *
	 * @since 1.5
	 *
	 * @return void
	 */
	public static function maybe_populate_cart_contents() {
		// Avoid Fatal error if WC()->cart is not defined (@since 2.0)
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		if ( WC()->cart->is_empty() && ( bricks_is_builder() || bricks_is_builder_call() ) ) {
			$products = wc_get_products( [ 'limit' => 5 ] );

			if ( $products ) {
				foreach ( $products as $product ) {
					if ( $product->is_purchasable() ) {
						WC()->cart->add_to_cart( $product->get_id() );
					}
				}
			}
		}
	}

	/**
	 * Maybe load the cart - render using WP REST API
	 *
	 * @since 1.5
	 */
	public static function maybe_load_cart() {
		if ( bricks_is_builder_call() && is_null( WC()->cart ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Prepare a populated cart with calculated totals for builder previews.
	 *
	 * Cart-backed tags and query loops can render independently of the Cart v2
	 * root. Keep their preview setup lazy and request-scoped so isolated renders
	 * have complete data without recalculating totals for every tag.
	 * (#86cb6j2b0)
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public static function maybe_prepare_builder_cart_preview() {
		static $is_prepared = false;

		if (
			$is_prepared ||
			! function_exists( 'WC' ) ||
			( ! bricks_is_builder() && ! bricks_is_builder_call() )
		) {
			return;
		}

		self::maybe_load_cart();

		$woocommerce = WC();

		if ( ! $woocommerce || ! $woocommerce->cart ) {
			return;
		}

		// Mark this before population because add-to-cart and totals hooks can render dynamic data recursively.
		$is_prepared = true;

		self::maybe_populate_cart_contents();
		self::maybe_init_cart_context();
	}

	/**
	 * Add or remove actions in the repeated_wc_template_hooks
	 *
	 * Used in {do_action} which the action is inside the repeated_wc_template_hooks hooks
	 * To avoid duplicate ouput which already exists in Bricks elements
	 *
	 * @since 1.7
	 *
	 * @param string $template required (ex: 'content-single-product', 'content-product').
	 * @param string $action remove, add.
	 * @param string $hook optional.
	 *
	 * @return void
	 */
	public static function execute_actions_in_wc_template( $template = '', $action = 'remove', $hook = '' ) {
		if ( ! $template ) {
			return;
		}

		$hooks = self::repeated_wc_template_hooks( $template );

		// No supported hooks found
		if ( ( empty( $hooks ) || ! is_array( $hooks ) ) && ! in_array( $hook, array_keys( $hooks ) ) ) {
			return;
		}

		$do = $action === 'remove' ? 'remove_action' : 'add_action';

		$target_hook = $hooks;

		// Remove or add a specific action
		if ( $hook != '' ) {
			// Check if the hook exists
			$target_hook = array_filter(
				$hooks,
				function( $key ) use ( $hook ) {
					return $key === $hook;
				},
				ARRAY_FILTER_USE_KEY
			);

			if ( empty( $target_hook ) ) {
				return;
			}
		}

		// Remove or add the actions
		foreach ( $target_hook as $hook_name => $hook_details ) {
			$actions = $hook_details['actions'];

			foreach ( $actions as $action_data ) {
				$callback = $action_data['callback'];
				$priority = $action_data['priority'];

				// Resolve dynamic callback references (e.g. WC checkout object methods).
				if ( isset( $action_data['callback_ref'] ) ) {
					$callback = self::resolve_repeated_hook_callback_reference( $action_data['callback_ref'], $callback );
				}

				if ( empty( $callback ) ) {
					continue;
				}

				$do( $hook_name, $callback, $priority );
			}
		}
	}

	/**
	 * Resolve callback references used in repeated Woo template hooks.
	 *
	 * @since 2.4
	 *
	 * @param string $callback_ref
	 * @param string $callback
	 * @return callable|null
	 */
	public static function resolve_repeated_hook_callback_reference( $callback_ref = '', $callback = '' ) {
		if ( empty( $callback_ref ) || empty( $callback ) ) {
			return null;
		}

		switch ( $callback_ref ) {
			case 'checkout_instance':
				if ( function_exists( 'WC' ) && WC() && WC()->checkout() ) {
					return [ WC()->checkout(), $callback ];
				}

				return null;
		}

		return null;
	}

	/**
	 * All woo template hooks that might be causing duplicated ouput when using together with Bricks WooCommerce elements
	 *
	 * @see woocommerce/includes/wc-template-hooks.php
	 *
	 * @since 1.7
	 *
	 * @param string $template
	 *
	 * @return array
	 */
	public static function repeated_wc_template_hooks( $template = '' ) {
		// @see woocommerce/templates/content-product.php
		$hooks['content-product'] = [
			'woocommerce_before_shop_loop_item'       => [
				'label'   => esc_html__( 'Before shop loop item', 'bricks' ),
				'actions' => [
					[
						// Not needed
						'callback' => 'woocommerce_template_loop_product_link_open',
						'priority' => 10
					],
				],
			],
			'woocommerce_before_shop_loop_item_title' => [
				'label'   => esc_html__( 'Before shop loop item title', 'bricks' ),
				'actions' => [
					[
						// Not needed, can set at Bricks settings
						'callback' => 'woocommerce_show_product_loop_sale_flash',
						'priority' => 10
					],
					[
						// Should use {featured_image}
						'callback' => 'woocommerce_template_loop_product_thumbnail',
						'priority' => 10
					],
				],
			],
			'woocommerce_shop_loop_item_title'        => [
				'label'   => esc_html__( 'Shop loop item title', 'bricks' ),
				'actions' => [
					[
						// Should use Product title element.
						'callback' => 'woocommerce_template_loop_product_title',
						'priority' => 10
					],
				],
			],
			'woocommerce_after_shop_loop_item_title'  => [
				'label'   => esc_html__( 'After shop loop item title', 'bricks' ),
				'actions' => [
					[
						// Should use {woo_product_rating}
						'callback' => 'woocommerce_template_loop_rating',
						'priority' => 5
					],
					[
						// Should use Product price element.
						'callback' => 'woocommerce_template_loop_price',
						'priority' => 10
					],
				],
			],
			'woocommerce_after_shop_loop_item'        => [
				'label'   => esc_html__( 'After shop loop item', 'bricks' ),
				'actions' => [
					[
						// Not needed
						'callback' => 'woocommerce_template_loop_product_link_close',
						'priority' => 5
					],
					[
						// Should use Add to cart element.
						'callback' => 'woocommerce_template_loop_add_to_cart',
						'priority' => 10
					],
				],
			],
		];

		// @see woocommerce/templates/content-single-product.php
		$hooks['content-single-product'] = [
			'woocommerce_before_single_product'         => [
				'label'   => esc_html__( 'Before single product', 'bricks' ),
				'actions' => [
				// [
				// Notices should be handled by the new WooCommerce notice element.
				// 'callback' => 'woocommerce_output_all_notices',
				// 'priority' => 10
				// ],
				],
			],
			'woocommerce_before_single_product_summary' => [
				'label'   => esc_html__( 'Before single product summary', 'bricks' ),
				'actions' => [
					[
						// Not needed, can use {woo_product_on_sale}
						'callback' => 'woocommerce_show_product_sale_flash',
						'priority' => 10
					],
					[
						// Should use Product gallery element, or {featured_image}
						'callback' => 'woocommerce_show_product_images',
						'priority' => 20
					],
				],
			],
			'woocommerce_single_product_summary'        => [
				'label'   => esc_html__( 'Single product summary', 'bricks' ),
				'actions' => [
					[
						// Should use Product title element
						'callback' => 'woocommerce_template_single_title',
						'priority' => 5
					],
					[
						// Should use Product rating element
						'callback' => 'woocommerce_template_single_rating',
						'priority' => 10
					],
					[
						// Should use Product price element
						'callback' => 'woocommerce_template_single_price',
						'priority' => 10
					],
					[
						// Should use Product short description element
						'callback' => 'woocommerce_template_single_excerpt',
						'priority' => 20
					],
					[
						// Should use Add to cart element
						'callback' => 'woocommerce_template_single_add_to_cart',
						'priority' => 30
					],
					[
						// Should use Product meta element
						'callback' => 'woocommerce_template_single_meta',
						'priority' => 40
					],
					[
						// Can use {do_action:woocommerce_share} in anywhere
						'callback' => 'woocommerce_template_single_sharing',
						'priority' => 50
					],
				],
			],
			'woocommerce_after_single_product_summary'  => [
				'label'   => esc_html__( 'After single product summary', 'bricks' ),
				'actions' => [
					[
						// Should use Products tabs element
						'callback' => 'woocommerce_output_product_data_tabs',
						'priority' => 10
					],
					[
						// Should use Product up/cross-sells element
						'callback' => 'woocommerce_upsell_display',
						'priority' => 15
					],
					[
						// Should use Related products element
						'callback' => 'woocommerce_output_related_products',
						'priority' => 20
					],
				],
			],
		];

		// @see woocommerce/templates/archive-product.php
		$hooks['archive-product'] = [
			'woocommerce_before_main_content' => [
				'label'   => esc_html__( 'Before main content', 'bricks' ),
				'actions' => [
					[
						// This will wrap the content, not necessary as user should design by layout elements
						'callback' => 'woocommerce_output_content_wrapper',
						'priority' => 10
					],
					[
						// Should use Breadcumbs element
						'callback' => 'woocommerce_breadcrumb',
						'priority' => 20
					],
				],
			],
			'woocommerce_archive_description' => [
				'label'   => esc_html__( 'Archive description', 'bricks' ),
				'actions' => [
					[
						// Should use Products archive description element
						'callback' => 'woocommerce_taxonomy_archive_description',
						'priority' => 10
					],
					[
						// Should use Products archive description element
						'callback' => 'woocommerce_product_archive_description',
						'priority' => 10
					],
				],
			],
			'woocommerce_before_shop_loop'    => [
				'label'   => esc_html__( 'Before shop loop', 'bricks' ),
				'actions' => [
					// [
					// Notices should be handled by the new WooCommerce notice element.
					// 'callback' => 'woocommerce_output_all_notices',
					// 'priority' => 10
					// ],
					[
						// Should use Products total results element
						'callback' => 'woocommerce_result_count',
						'priority' => 20
					],
					[
						// Should use Products orderby element
						'callback' => 'woocommerce_catalog_ordering',
						'priority' => 30
					],
				],
			],
			'woocommerce_after_shop_loop'     => [
				'label'   => esc_html__( 'After shop loop', 'bricks' ),
				'actions' => [
					[
						// Should use Products pagination element
						'callback' => 'woocommerce_pagination',
						'priority' => 10
					],
				],
			],
			'woocommerce_after_main_content'  => [
				'label'   => esc_html__( 'After main content', 'bricks' ),
				'actions' => [
					[
						// This will wrap the content, not necessary as user should design by layout elements
						'callback' => 'woocommerce_output_content_wrapper_end',
						'priority' => 10
					],
				],
			],
		];

		// @see woocommerce/templates/cart/cart.php
		$hooks['cart'] = [
			'woocommerce_before_cart'         => [
				'label'   => esc_html__( 'Before cart', 'bricks' ),
				'actions' => [
					// [
					// Notices should be handled by the new WooCommerce notice element.
					// 'callback' => 'woocommerce_output_all_notices',
					// 'priority' => 10
					// ],
				],
			],
			'woocommerce_proceed_to_checkout' => [
				'label'   => esc_html__( 'Proceed to checkout', 'bricks' ),
				'actions' => [
					[
						// Use {woo_url:checkout} in Button element, no need to keep the default button.
						'callback' => 'woocommerce_button_proceed_to_checkout',
						'priority' => 20
					],
				],
			],
		];

		// @see woocommerce/templates/cart/cart-empty.php
		$hooks['cart-empty'] = [
			'woocommerce_cart_is_empty' => [
				'label'   => esc_html__( 'Cart is empty', 'bricks' ),
				'actions' => [
					// [
					// Notices should be handled by the new WooCommerce notice element.
					// 'callback' => 'woocommerce_output_all_notices',
					// 'priority' => 5
					// ],
					[
						// Empty messages should be freely customized by the user with any element.
						'callback' => 'wc_empty_cart_message',
						'priority' => 10
					],
				],
			],
		];

		// @see woocommerce/templates/myaccount/downloads.php
		$hooks['account-downloads'] = [
			'woocommerce_available_downloads' => [
				'label'   => esc_html__( 'Available downloads', 'bricks' ),
				'actions' => [
					[
						// Keep the hook for 3rd-party callbacks, but avoid duplicate default downloads table output.
						'callback' => 'woocommerce_order_downloads_table',
						'priority' => 10,
					],
				],
			],
		];

		// @see woocommerce/includes/class-wc-checkout.php
		$hooks['checkout'] = [
			'woocommerce_checkout_billing'  => [
				'label'   => esc_html__( 'Checkout billing', 'bricks' ),
				'actions' => [
					[
						// Keep the hook for 3rd-party callbacks, but avoid duplicate default billing form output.
						'callback_ref' => 'checkout_instance',
						'callback'     => 'checkout_form_billing',
						'priority'     => 10,
					],
				],
			],
			'woocommerce_checkout_shipping' => [
				'label'   => esc_html__( 'Checkout shipping', 'bricks' ),
				'actions' => [
					[
						// Keep the hook for 3rd-party callbacks, but avoid duplicate default shipping form output.
						'callback_ref' => 'checkout_instance',
						'callback'     => 'checkout_form_shipping',
						'priority'     => 10,
					],
				],
			],
		];

		$hooks['thankyou'] = [
			'woocommerce_thankyou'                        => [
				'label'   => esc_html__( 'Thank you', 'bricks' ),
				'actions' => [
					[
						'callback' => 'woocommerce_order_details_table',
						'priority' => 10,
					],
				],
			],
			'woocommerce_order_details_after_order_table' => [
				'label'   => esc_html__( 'After order details table', 'bricks' ),
				'actions' => [
					[
						'callback' => 'woocommerce_order_again_button',
						'priority' => 10,
					],
				],
			],
		];

		if ( ! empty( $template ) ) {
			return isset( $hooks[ $template ] ) ? $hooks[ $template ] : [];
		}

		return $hooks;
	}

	/**
	 * Find the template hooks array by using the action name.
	 *
	 * @since 1.7
	 *
	 * @param string $action
	 *
	 * @return array
	 */
	public static function get_repeated_wc_template_hooks_by_action( $action = '' ) {
		if ( empty( $action ) ) {
			return [];
		}

		$repeated_hooks = self::repeated_wc_template_hooks();

		$template = array_filter(
			$repeated_hooks,
			function ( $hooks ) use ( $action ) {
				return in_array( $action, array_keys( $hooks ) );
			}
		);

		return $template;
	}

	/**
	 * Bricks helper function to render the product rating.
	 * single-product/rating.php
	 *
	 * @param WC_Product $product Product instance.
	 * @param array      $params  Keys: show_empty_stars, hide_reviews_link, wrapper, reviews_link_text_single, reviews_link_text_plural.
	 * @param bool       $render  Render (echo) or return.
	 *
	 * @since 1.8
	 */
	public static function render_product_rating( $product = null, $params = [], $render = true ) {
		if ( ! is_a( $product, 'WC_Product' ) || ! wc_review_ratings_enabled() ) {
			return;
		}

		$show_empty_stars  = isset( $params['show_empty_stars'] ) ? $params['show_empty_stars'] : true;
		$hide_reviews_link = isset( $params['hide_reviews_link'] ) ? $params['hide_reviews_link'] : true;
		$wrapper           = isset( $params['wrapper'] ) ? $params['wrapper'] : true;

		$rating_count = $product->get_rating_count();
		$review_count = $product->get_review_count();
		$average      = $product->get_average_rating();

		$html = '';

		if ( $rating_count > 0 || $show_empty_stars ) {
			// Add filter to show empty star
			if ( $show_empty_stars ) {
				add_filter( 'woocommerce_product_get_rating_html', [ '\Bricks\Woocommerce_Helpers', 'show_empty_stars' ], 10, 3 );
			}

			// Populate rating html
			$html .= $wrapper ? '<div class="woocommerce-product-rating">' : '';
			$html .= wc_get_rating_html( $average, $rating_count );

			// Populate review link
			if ( comments_open() && ! $hide_reviews_link ) {

				// Use custom text if provided, otherwise use WooCommerce defaults
				$text_single = ! empty( $params['reviews_link_text_single'] )
					? bricks_render_dynamic_data( $params['reviews_link_text_single'] )
					: esc_html__( '%s customer review', 'woocommerce' );

				$text_plural = ! empty( $params['reviews_link_text_plural'] )
					? bricks_render_dynamic_data( $params['reviews_link_text_plural'] )
					: esc_html__( '%s customer reviews', 'woocommerce' );

				$html .= '<a href="#reviews" class="woocommerce-review-link" rel="nofollow">';
				// translators: %s: review count
				$html .= sprintf( _n( $text_single, $text_plural, $review_count, 'woocommerce' ), '<span class="count">' . esc_html( $review_count ) . '</span>' );
				$html .= '</a>';
			}

			$html .= $wrapper ? '</div>' : '';

			// Remove filter
			if ( $show_empty_stars ) {
				remove_filter( 'woocommerce_product_get_rating_html', [ '\Bricks\Woocommerce_Helpers', 'show_empty_stars' ], 10, 3 );
			}
		}

		// Render or return (for dynamic data tag {woo_product_rating})
		if ( $render ) {
			echo $html;
		} else {
			return $html;
		}
	}

	/**
	 * Hooked to woocommerce_product_get_rating_html
	 *
	 * @since 1.8
	 */
	public static function show_empty_stars( $html, $rating, $count ) {
		// translators: %s: rating
		$label = sprintf( __( 'Rated %s out of 5', 'woocommerce' ), $rating );
		$html  = '<div class="star-rating" role="img" aria-label="' . esc_attr( $label ) . '">' . wc_get_star_rating_html( $rating, $count ) . '</div>';

		return $html;
	}

	/**
	 * Get product stock amount value
	 *
	 * Previously in get_stock_html(), but refactored into a separate function for reusability and readability.
	 * Bare in mind if the product is not managed stock, the value will be 0 even stock status is instock.
	 *
	 * @param \WC_Product $product
	 * @return int
	 *
	 * @since 1.6.1
	 * @since 1.11.1: Moved here from provider-woo.php.
	 */
	public static function get_stock_amount( $product ) {
		$stock_amount = $product->get_stock_quantity();

		if ( $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();

			foreach ( $variations as $variation ) {
				if ( empty( $variation['is_in_stock'] ) ) {
					continue;
				}

				$variation_obj = new \WC_Product_variation( $variation['variation_id'] );

				$stock_amount += $variation_obj->get_stock_quantity();
			}
		}

		return (int) $stock_amount; // Possible to return negative value when using backorders (#861n84vua)
	}

	/**
	 * Similar function to wc_format_stock_for_display but adapted to be possible to use the stock sum up of the product variations
	 *
	 * @since 1.5.7
	 * @since 1.11.1: Moved here from provider-woo.php.
	 */
	public static function format_stock_for_display( $product, $stock_amount ) {
		$display = __( 'In stock', 'woocommerce' );

		switch ( get_option( 'woocommerce_stock_format' ) ) {
			case 'low_amount':
				if ( $stock_amount <= wc_get_low_stock_amount( $product ) ) {
					/* translators: %s: stock amount */
					$display = sprintf( __( 'Only %s left in stock', 'woocommerce' ), wc_format_stock_quantity_for_display( $stock_amount, $product ) );
				}
				break;
			case '':
				/* translators: %s: stock amount */
				$display = sprintf( __( '%s in stock', 'woocommerce' ), wc_format_stock_quantity_for_display( $stock_amount, $product ) );
				break;
		}

		if ( $product->backorders_allowed() && $product->backorders_require_notification() ) {
			$display .= ' ' . __( '(can be backordered)', 'woocommerce' );
		}

		return $display;
	}

	/**
	 * When enabled, product IDs are constrained by variation-level stock/attributes
	 * instead of parent-level stock status only.
	 *
	 * @return bool
	 * @since 2.4
	 */
	public static function use_variation_aware_attribute_stock_logic() {
		return Database::get_setting( 'woocommerceVariationAwareAttributeStockFilter', false );
	}

	/**
	 * Intersect current product query with variation-aware matched product IDs.
	 *
	 * @param array  $product_args Existing WP_Query args.
	 * @param array  $attribute_filters Format: [ 'pa_color' => [1,2], ... ].
	 * @param string $stock_mode One of: available|instock|outofstock|onbackorder.
	 *
	 * @return array
	 * @since 2.4
	 */
	public static function maybe_apply_variation_aware_attribute_stock_filter( $product_args, $attribute_filters, $stock_mode = 'available' ) {
		if ( ! self::use_variation_aware_attribute_stock_logic() || empty( $attribute_filters ) || ! $stock_mode ) {
			return $product_args;
		}

		$product_ids = self::get_variation_aware_attribute_stock_product_ids( $attribute_filters, $stock_mode );
		$product_ids = ! empty( $product_ids ) ? array_values( array_unique( array_map( 'intval', $product_ids ) ) ) : [ 0 ];

		if ( ! empty( $product_args['post__in'] ) ) {
			$product_ids = array_values( array_intersect( array_map( 'intval', (array) $product_args['post__in'] ), $product_ids ) );
			$product_ids = ! empty( $product_ids ) ? $product_ids : [ 0 ];
		}

		$product_args['post__in'] = $product_ids;

		return $product_args;
	}

	/**
	 * Extract selected product-attribute filters from URL and/or pre-built query vars.
	 *
	 * Only product attributes (`pa_*`) are collected.
	 *
	 * @param array $bricks_query_var Query vars from Bricks query pipeline.
	 * @param bool  $include_request_filters Whether to include filters from the current URL request
	 *
	 * @return array
	 * @since 2.4
	 */
	public static function get_selected_attribute_filters( $bricks_query_var = [], $include_request_filters = true ) {
		$attribute_filters = [];

		if ( $include_request_filters ) {
			// Bricks URL params: b_pa_color, b_pa_size, ...
			foreach ( self::get_filters_list() as $name => $filter ) {
				if ( strpos( $name, 'pa_' ) !== 0 || empty( $_GET[ 'b_' . $name ] ) ) {
					continue;
				}

				$term_ids = self::normalize_attribute_term_ids( $name, wp_unslash( $_GET[ 'b_' . $name ] ) );

				if ( ! empty( $term_ids ) ) {
					$attribute_filters[ $name ] = $term_ids;
				}
			}

			// WooCommerce widget params: filter_color=blue,red (or filter_pa_color=blue)
			foreach ( $_GET as $key => $value ) {
				if ( strpos( $key, 'filter_' ) !== 0 || $key === 'filter_stock_status' ) {
						continue;
				}

					$raw_attribute = sanitize_key( substr( $key, 7 ) );
				if ( ! $raw_attribute ) {
						continue;
				}

					$taxonomy = strpos( $raw_attribute, 'pa_' ) === 0
							? $raw_attribute
							: wc_attribute_taxonomy_name( $raw_attribute );

				if ( strpos( $taxonomy, 'pa_' ) !== 0 || ! taxonomy_exists( $taxonomy ) ) {
						continue;
				}

					$raw_values = wp_unslash( $value );
					$raw_values = is_array( $raw_values ) ? $raw_values : explode( ',', (string) $raw_values );
					$term_ids   = self::normalize_attribute_term_ids( $taxonomy, $raw_values );

				if ( empty( $term_ids ) ) {
						continue;
				}

				if ( isset( $attribute_filters[ $taxonomy ] ) ) {
						$attribute_filters[ $taxonomy ] = array_values(
							array_unique( array_merge( $attribute_filters[ $taxonomy ], $term_ids ) )
						);
				} else {
						$attribute_filters[ $taxonomy ] = $term_ids;
				}
			}
		}

		if ( empty( $bricks_query_var['tax_query'] ) || ! is_array( $bricks_query_var['tax_query'] ) ) {
			return $attribute_filters;
		}

		foreach ( $bricks_query_var['tax_query'] as $tax_query ) {
			if ( ! is_array( $tax_query ) || empty( $tax_query['taxonomy'] ) || strpos( $tax_query['taxonomy'], 'pa_' ) !== 0 ) {
				continue;
			}

			$taxonomy = $tax_query['taxonomy'];
			$terms    = $tax_query['terms'] ?? [];
			$field    = $tax_query['field'] ?? 'term_id';
			$operator = strtoupper( $tax_query['operator'] ?? 'IN' );

			// Variation-aware attribute stock lookup supports inclusive term constraints only.
			if ( ! in_array( $operator, [ 'IN', 'AND' ], true ) ) {
				continue;
			}

			if ( $field === 'slug' ) {
				$term_ids = self::normalize_attribute_term_ids( $taxonomy, $terms );
			} else {
				$term_ids = array_filter( array_map( 'absint', (array) $terms ) );
			}

			if ( empty( $term_ids ) ) {
				continue;
			}

			// A strict AND with multiple terms in the same taxonomy cannot be represented safely here.
			if ( $operator === 'AND' && count( $term_ids ) > 1 ) {
				return [];
			}

			if ( isset( $attribute_filters[ $taxonomy ] ) ) {
				// Keep taxonomy constraints consistent with final tax_query behavior: same taxonomy narrows by intersection.
				$intersected = array_values( array_intersect( $attribute_filters[ $taxonomy ], $term_ids ) );

				$attribute_filters[ $taxonomy ] = ! empty( $intersected ) ? $intersected : [ 0 ];
			} else {
				$attribute_filters[ $taxonomy ] = $term_ids;
			}
		}

		return $attribute_filters;
	}

	/**
	 * Read stock filters from Bricks and WooCommerce URL formats.
	 *
	 * Supports:
	 * - b_stock=instock
	 * - filter_stock_status=instock,outofstock
	 *
	 * @return string[]
	 */
	private static function get_request_stock_filters() {
		$values = [];

		if ( isset( $_GET['b_stock'] ) ) {
				$raw    = wp_unslash( $_GET['b_stock'] );
				$values = array_merge( $values, is_array( $raw ) ? $raw : explode( ',', (string) $raw ) );
		}

		if ( isset( $_GET['filter_stock_status'] ) ) {
				$raw    = wp_unslash( $_GET['filter_stock_status'] );
				$values = array_merge( $values, is_array( $raw ) ? $raw : explode( ',', (string) $raw ) );
		}

		$values = array_filter( array_map( 'sanitize_text_field', $values ) );

		return array_values( array_unique( $values ) );
	}

	/**
	 * Normalize attribute filter values into term IDs.
	 *
	 * Supports numeric IDs and slugs.
	 *
	 * @param string       $taxonomy Attribute taxonomy, e.g. pa_color.
	 * @param array|string $values   Term IDs/slugs.
	 *
	 * @return int[]
	 * @since 2.4
	 */
	public static function normalize_attribute_term_ids( $taxonomy, $values ) {
		$values   = (array) $values;
		$term_ids = [];

		foreach ( $values as $value ) {
			if ( is_numeric( $value ) ) {
				$term_ids[] = absint( $value );
				continue;
			}

			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );

			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( array_filter( $term_ids ) ) );
	}

	 /**
	  * Resolve product IDs matching selected attributes + stock mode.
	  *
	  * Resolution order:
	  * 1) WooCommerce lookup table (fast path, when available/applicable)
	  * 2) SQL fallback against variations + postmeta (slow path)
	  *
	  * @param array  $attribute_filters Attribute filters indexed by taxonomy.
	  * @param string $stock_mode Stock mode.
	  *
	  * @return int[]
	  * @since 2.4
	  */
	public static function get_variation_aware_attribute_stock_product_ids( $attribute_filters, $stock_mode = 'available' ) {
		static $cache = [];

		$cache_key = md5( wp_json_encode( [ $attribute_filters, $stock_mode ] ) );

		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$attribute_filters = array_filter(
			$attribute_filters,
			function( $term_ids, $taxonomy ) {
				return strpos( $taxonomy, 'pa_' ) === 0 && ! empty( $term_ids );
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $attribute_filters ) ) {
			$cache[ $cache_key ] = [];
			return [];
		}

		$product_ids = self::get_lookup_attribute_stock_product_ids( $attribute_filters, $stock_mode );

		if ( $product_ids === null || empty( $product_ids ) ) {
			$product_ids = self::get_fallback_attribute_stock_product_ids( $attribute_filters, $stock_mode );
		}

		$cache[ $cache_key ] = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

		return $cache[ $cache_key ];
	}

	/**
	 * Check whether WooCommerce attributes lookup table exists.
	 *
	 * Cached in static memory for current request to avoid repeated SHOW TABLES calls.
	 *
	 * @return bool
	 * @since 2.4
	 */
	private static function wc_lookup_table_exists() {
		static $exists = null;

		if ( $exists !== null ) {
			return $exists;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_product_attributes_lookup';
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		return $exists;
	}

	/**
	 * Fast path: query WooCommerce lookup table for variation-aware stock+attribute matches.
	 *
	 * Note:
	 * - Currently used for stock_mode: available|outofstock.
	 * - For instock/onbackorder, caller falls back to variation meta SQL.
	 *
	 * @param array  $attribute_filters Attribute filters indexed by taxonomy.
	 * @param string $stock_mode Stock mode.
	 *
	 * @return int[]|null Null means lookup path not applicable/unavailable.
	 * @since 2.4
	 */
	private static function get_lookup_attribute_stock_product_ids( $attribute_filters, $stock_mode ) {
		if ( ! in_array( $stock_mode, [ 'available', 'outofstock' ], true ) ) {
			return null;
		}

		if ( ! self::wc_lookup_table_exists() ) {
			return null;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . 'wc_product_attributes_lookup';

		$where_clauses = [];
		$params        = [];
		$in_stock_flag = $stock_mode === 'available' ? 1 : 0;

		foreach ( $attribute_filters as $taxonomy => $term_ids ) {
			if ( empty( $term_ids ) ) {
					continue;
			}

			$placeholders    = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$where_clauses[] = "( lookup.taxonomy = %s AND lookup.term_id IN ({$placeholders}) )";
			$params[]        = $taxonomy;
			$params          = array_merge( $params, array_map( 'absint', $term_ids ) );
		}

		if ( empty( $where_clauses ) ) {
			return null;
		}

		$resolved_product_id = 'CASE
			WHEN variation.ID IS NOT NULL AND variation.post_parent > 0 THEN variation.post_parent
			ELSE lookup.product_or_parent_id
		END';

		$sql = "
			SELECT DISTINCT {$resolved_product_id} AS product_id
			FROM {$table_name} AS lookup
			LEFT JOIN {$wpdb->posts} AS variation
					ON variation.ID = lookup.product_id
					AND variation.post_type = 'product_variation'
			WHERE lookup.in_stock = %d
			AND (" . implode( ' OR ', $where_clauses ) . ")
			GROUP BY {$resolved_product_id}, lookup.product_id
			HAVING COUNT(DISTINCT lookup.taxonomy) = %d
		";

		array_unshift( $params, $in_stock_flag );
		$params[] = count( $where_clauses );

		$product_ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

			// Only keep real product IDs.
		if ( ! empty( $product_ids ) ) {
			$product_ids = get_posts(
				[
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'post__in'         => $product_ids,
					'orderby'          => 'post__in',
					'suppress_filters' => true,
				]
			);
		}

		return $product_ids;
	}

	/**
	 * Slow path fallback:
	 * - Variable products matched via variation attributes + variation stock status.
	 * - Non-variable products matched via standard tax/meta WP_Query.
	 *
	 * @param array  $attribute_filters Attribute filters indexed by taxonomy.
	 * @param string $stock_mode Stock mode.
	 *
	 * @return int[]
	 * @since 2.4
	 */
	private static function get_fallback_attribute_stock_product_ids( $attribute_filters, $stock_mode ) {
		$variable_ids     = self::get_fallback_variable_attribute_stock_product_ids( $attribute_filters, $stock_mode );
		$non_variable_ids = self::get_fallback_non_variable_attribute_stock_product_ids( $attribute_filters, $stock_mode );

		return array_merge( $variable_ids, $non_variable_ids );
	}

	 /**
	  * Fallback SQL for variable products:
	  * Find parent product IDs where at least one variation matches all selected attributes
	  * and has a stock status matching $stock_mode.
	  *
	  * @param array  $attribute_filters Attribute filters indexed by taxonomy.
	  * @param string $stock_mode Stock mode.
	  *
	  * @return int[]
	  * @since 2.4
	  */
	private static function get_fallback_variable_attribute_stock_product_ids( $attribute_filters, $stock_mode ) {
		global $wpdb;

		$stock_statuses = self::get_stock_statuses_for_mode( $stock_mode );

		if ( empty( $stock_statuses ) ) {
			return [];
		}

		$posts_table    = $wpdb->posts;
		$postmeta_table = $wpdb->postmeta;
		$joins          = [];
		$where          = [
			"variation.post_type = 'product_variation'",
			"variation.post_status = 'publish'",
			'variation.post_parent > 0',
			"parent.post_type = 'product'",
			"parent.post_status = 'publish'",
			"stock_meta.meta_key = '_stock_status'",
		];

		$join_params  = [];
		$where_params = [];

		$stock_placeholders = implode( ',', array_fill( 0, count( $stock_statuses ), '%s' ) );
		$where[]            = "stock_meta.meta_value IN ({$stock_placeholders})";
		$where_params       = array_merge( $where_params, $stock_statuses );

		$index = 0;

		foreach ( $attribute_filters as $taxonomy => $term_ids ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'include'    => $term_ids,
				]
			);

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				return [];
			}

			$term_slugs   = wp_list_pluck( $terms, 'slug' );
			$meta_key     = 'attribute_' . $taxonomy;
			$alias        = 'attr_meta_' . $index;
			$placeholders = implode( ',', array_fill( 0, count( $term_slugs ), '%s' ) );

			$joins[] = "LEFT JOIN {$postmeta_table} AS {$alias} ON {$alias}.post_id = variation.ID AND {$alias}.meta_key = %s";
			$where[] = "( {$alias}.meta_value IN ({$placeholders}) OR {$alias}.meta_value = '' )";

			$join_params[] = $meta_key;
			$where_params  = array_merge( $where_params, $term_slugs );

			$index++;
		}

		$sql = "
            SELECT DISTINCT variation.post_parent
            FROM {$posts_table} AS variation
            INNER JOIN {$posts_table} AS parent ON parent.ID = variation.post_parent
            INNER JOIN {$postmeta_table} AS stock_meta ON stock_meta.post_id = variation.ID
            " . implode( ' ', $joins ) . '
            WHERE ' . implode( ' AND ', $where );

		$params = array_merge( $join_params, $where_params );

		return $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Fallback WP_Query for non-variable products.
	 *
	 * @param array  $attribute_filters Attribute filters indexed by taxonomy.
	 * @param string $stock_mode Stock mode.
	 *
	 * @return int[]
	 * @since 2.4
	 */
	private static function get_fallback_non_variable_attribute_stock_product_ids( $attribute_filters, $stock_mode ) {
		$stock_statuses = self::get_stock_statuses_for_mode( $stock_mode );

		if ( empty( $stock_statuses ) ) {
			return [];
		}

		$tax_query = [
			'relation' => 'AND',
		];

		foreach ( $attribute_filters as $taxonomy => $term_ids ) {
			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $term_ids,
			];
		}

		$tax_query[] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => [ 'variable' ],
			'operator' => 'NOT IN',
		];

		$query = new \WP_Query(
			[
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'cache_results'    => false,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'tax_query'        => $tax_query,
				'meta_query'       => [
					[
						'key'     => '_stock_status',
						'value'   => $stock_statuses,
						'compare' => 'IN',
					],
				],
			]
		);

		return $query->posts;
	}

	/**
	 * Map internal stock mode to WooCommerce _stock_status values.
	 *
	 * @param string $stock_mode Stock mode.
	 *
	 * @return string[]
	 * @since 2.4
	 */
	private static function get_stock_statuses_for_mode( $stock_mode ) {
		switch ( $stock_mode ) {
			case 'available':
				return [ 'instock', 'onbackorder' ];

			case 'instock':
				return [ 'instock' ];

			case 'outofstock':
				return [ 'outofstock' ];

			case 'onbackorder':
				return [ 'onbackorder' ];
		}

		return [];
	}
}

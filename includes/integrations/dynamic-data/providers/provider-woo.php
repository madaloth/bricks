<?php
namespace Bricks\Integrations\Dynamic_Data\Providers;

use Bricks\Woocommerce_Helpers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Provider_Woo extends Base {
	/**
	 * Cart aggregate tags that need complete preview totals outside a cart root.
	 *
	 * Item-level tags are excluded because their cart-backed query loop prepares
	 * the preview before establishing the loop object. (#86cb6j2b0)
	 *
	 * @var array
	 */
	private const CART_PREVIEW_RENDER_TAGS = [
		'cart_items_count'                 => true,
		'cart_order_subtotal'              => true,
		'cart_order_total'                 => true,
		'cart_applied_coupon_total_amount' => true,
		'cart_applied_fee_total_amount'    => true,
		'cart_shipping_method'             => true,
		'cart_shipping_total_amount'       => true,
	];

	public static function load_me() {
		return class_exists( 'woocommerce' );
	}

	public function register_tags() {
		$tags = $this->get_tags_config();

		foreach ( $tags as $key => $tag ) {
			$this->tags[ $key ] = [
				'name'     => '{' . $key . '}',
				'label'    => $tag['label'],
				'group'    => $tag['group'],
				'provider' => $this->name,
			];

			if ( ! empty( $tag['render'] ) ) {
				$this->tags[ $key ]['render'] = $tag['render'];
			}
		}
	}

	public function get_tags_config() {
		$tags = [
			// Product
			'woo_product_type'                     => [
				'label' => esc_html__( 'Product type', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_price'                    => [
				'label' => esc_html__( 'Product price', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_regular_price'            => [
				'label' => esc_html__( 'Product regular price', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_sale_price'               => [
				'label' => esc_html__( 'Product sale price', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_excerpt'                  => [
				'label' => esc_html__( 'Product short description', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_stock'                    => [
				'label' => esc_html__( 'Product stock', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_sku'                      => [
				'label' => esc_html__( 'Product SKU', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_gtin'                     => [
				'label' => esc_html__( 'Product GTIN', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_rating'                   => [
				'label' => esc_html__( 'Product rating', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_on_sale'                  => [
				'label' => esc_html__( 'Product on sale', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_badge_new'                => [
				'label' => esc_html__( 'Product badge new', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_add_to_cart'                      => [
				'label' => esc_html__( 'Add to cart', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_cat_image'                => [
				'label' => esc_html__( 'Product category image', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			// Featured image & product gallery images (@since 1.11)
			'woo_product_images'                   => [
				'label' => esc_html__( 'Product images', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			// Product gallery images only, not including featured image (@since 1.11)
			'woo_product_gallery_images'           => [
				'label' => esc_html__( 'Product gallery images', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],
			'woo_product_stock_status'             => [
				'label' => esc_html__( 'Product stock status', 'bricks' ),
				'group' => esc_html__( 'Product', 'bricks' ),
			],

			// Cart
			'woo_cart_product_name'                => [
				'label' => esc_html__( 'Cart product name', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_product_title'               => [
				'label' => esc_html__( 'Cart product title', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_remove_link'                 => [
				'label' => esc_html__( 'Cart remove product', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_quantity'                    => [
				'label' => esc_html__( 'Cart product quantity', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_item_price'                  => [
				'label' => esc_html__( 'Cart item price', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_item_save'                   => [
				'label' => esc_html__( 'Cart item savings', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_subtotal'                    => [
				'label' => esc_html__( 'Cart product subtotal', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_update'                      => [
				'label' => esc_html__( 'Cart update', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_items_count'                 => [
				'label' => esc_html__( 'Cart items count', 'bricks' ),
				'group' => 'WooCommerce',
			],
			// Cart order total (@since 2.4)
			'woo_cart_order_subtotal'              => [
				'label' => esc_html__( 'Cart order subtotal', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_order_total'                 => [
				'label' => esc_html__( 'Cart order total', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_coupon'              => [
				'label' => esc_html__( 'Cart applied coupon', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_coupon_amount'       => [
				'label' => esc_html__( 'Cart applied coupon amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_coupon_total_amount' => [
				'label' => esc_html__( 'Cart applied coupon total amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_fee'                 => [
				'label' => esc_html__( 'Cart applied fee', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_fee_amount'          => [
				'label' => esc_html__( 'Cart applied fee amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_fee_total_amount'    => [
				'label' => esc_html__( 'Cart applied fee total amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_shipping_method'             => [
				'label' => esc_html__( 'Cart shipping method', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_shipping_total_amount'       => [
				'label' => esc_html__( 'Cart shipping total amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_free_shipping_min_amount'         => [
				'label' => esc_html__( 'Free shipping minimum amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_free_shipping_remaining'          => [
				'label' => esc_html__( 'Free shipping remaining amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_free_shipping_progress'           => [
				'label' => esc_html__( 'Free shipping progress', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_tax_label'           => [
				'label' => esc_html__( 'Cart applied tax label', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_cart_applied_tax_amount'          => [
				'label' => esc_html__( 'Cart applied tax amount', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_checkout_current_step_number'     => [
				'label' => esc_html__( 'Checkout current step number', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_url'                              => [
				'label' => esc_html__( 'WooCommerce URL', 'bricks' ) . ' (' . esc_html__( 'add key after', 'bricks' ) . ' ":")',
				'group' => 'WooCommerce',
			],

			// Account addresses (@since 2.4)
			'woo_account_addresses_description'    => [
				'label' => esc_html__( 'Account addresses description', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address_type'             => [
				'label' => esc_html__( 'Account address type', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address_title'            => [
				'label' => esc_html__( 'Account address title', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address'                  => [
				'label' => esc_html__( 'Account address', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address_has_address'      => [
				'label' => esc_html__( 'Account address has address', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address_edit_url'         => [
				'label' => esc_html__( 'Account address edit URL', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_address_action_label'     => [
				'label' => esc_html__( 'Account address action label', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_edit_address_title'       => [
				'label' => esc_html__( 'Account edit address title', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_edit_address_type'        => [
				'label' => esc_html__( 'Account edit address type', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_account_edit_address_type_label'  => [
				'label' => esc_html__( 'Account edit address type label', 'bricks' ),
				'group' => 'WooCommerce',
			],

			// Checkout Order
			'woo_order_id'                         => [
				'label' => esc_html__( 'Order id', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_number'                     => [
				'label' => esc_html__( 'Order number', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_date'                       => [
				'label' => esc_html__( 'Order date', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_failed_text'                => [
				'label' => esc_html__( 'Order failed text', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_status'                     => [
				'label' => esc_html__( 'Order status', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_total'                      => [
				'label' => esc_html__( 'Order total', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_payment_title'              => [
				'label' => esc_html__( 'Order payment method', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_email'                      => [
				'label' => esc_html__( 'Order email', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_checkout_payment_url'       => [
				'label' => esc_html__( 'Order checkout payment URL', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_again_url'                  => [
				'label' => esc_html__( 'Order again URL', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_user_id'                    => [
				'label' => esc_html__( 'Order user ID', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_billing_address'            => [
				'label' => esc_html__( 'Order billing address', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_billing_phone'              => [
				'label' => esc_html__( 'Order billing phone', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_shipping_address'           => [
				'label' => esc_html__( 'Order shipping address', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_shipping_phone'             => [
				'label' => esc_html__( 'Order shipping phone', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_name'                  => [
				'label' => esc_html__( 'Order item name', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_title'                 => [
				'label' => esc_html__( 'Order item title', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_quantity'              => [
				'label' => esc_html__( 'Order item quantity', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_price'                 => [
				'label' => esc_html__( 'Order item price', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_line_subtotal'         => [
				'label' => esc_html__( 'Order item line subtotal', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_meta'                  => [
				'label' => esc_html__( 'Order item meta', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_total_label'                => [
				'label' => esc_html__( 'Order total label', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_total_value'                => [
				'label' => esc_html__( 'Order total value', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_view_url'                   => [
				'label' => esc_html__( 'Order view URL', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_view_aria_label'            => [
				'label' => esc_html__( 'Order view aria label', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_item_count'                 => [
				'label' => esc_html__( 'Order item count', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_total_with_item_count'      => [
				'label' => esc_html__( 'Order total with item count', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_action_url'                 => [
				'label' => esc_html__( 'Order action URL', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_action_name'                => [
				'label' => esc_html__( 'Order action name', 'bricks' ),
				'group' => 'WooCommerce',
			],
			'woo_order_action_aria_label'          => [
				'label' => esc_html__( 'Order action aria label', 'bricks' ),
				'group' => 'WooCommerce',
			],
			// Woo Phase 3
			// NOTE: Not in use
			// 'woo_my_account_endpoint'  => [
			// 'label' => esc_html__( 'My account endpoint', 'bricks' ),
			// 'group' => 'WooCommerce',
			// ],
		];

		// Order downloads (@since 2.4)
		$tags = array_merge(
			$tags,
			[
				'woo_order_download_product'        => [
					'label' => esc_html__( 'Order download product', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_download_name'           => [
					'label' => esc_html__( 'Order download name', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_download_url'            => [
					'label' => esc_html__( 'Order download URL', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_downloads_remaining'     => [
					'label' => esc_html__( 'Order downloads remaining', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_download_access_expires' => [
					'label' => esc_html__( 'Order download access expires', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_customer_note_date'      => [
					'label' => esc_html__( 'Order customer note date', 'bricks' ),
					'group' => 'WooCommerce',
				],
				'woo_order_customer_note_comment'   => [
					'label' => esc_html__( 'Order customer note comment', 'bricks' ),
					'group' => 'WooCommerce',
				],
			]
		);

		if ( \Bricks\Woocommerce::use_advanced_modular_elements() && \Bricks\Woocommerce::is_order_withdrawal_enabled() ) {
			$tags['woo_order_withdrawal_value']  = [
				'label' => esc_html__( 'Order withdrawal submitted value', 'bricks' ),
				'group' => 'WooCommerce',
			];
			$tags['woo_order_withdrawal_screen'] = [
				'label' => esc_html__( 'Order withdrawal screen', 'bricks' ),
				'group' => 'WooCommerce',
			];
		}

		if ( class_exists( '\Bricks\Woocommerce' ) && ! \Bricks\Woocommerce::use_advanced_modular_elements() ) {
			// Keep legacy Woo tags visible, but hide v2-only tags from picker/search while the beta is dormant.
			foreach ( \Bricks\Woocommerce::get_advanced_modular_dynamic_tags() as $tag_name ) {
				unset( $tags[ $tag_name ] );
			}
		}

		return $tags;
	}

	/**
	 * Get cart item prices adjusted for the cart tax display mode. (#86caxp0b4)
	 *
	 * @since 2.4
	 *
	 * @param array        $cart_item Cart item data.
	 * @param string|false $cart_item_key Cart item key.
	 * @return array
	 */
	private function get_cart_item_price_data( $cart_item, $cart_item_key = false ) {
		if ( ! is_array( $cart_item ) || is_null( WC()->cart ) || empty( $cart_item['data'] ) ) {
			return [];
		}

		$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

		if ( ! ( $product instanceof \WC_Product ) || $product->get_price() === '' ) {
			return [];
		}

		$raw_regular_price = $product->get_regular_price();
		$price_function    = WC()->cart->display_prices_including_tax() ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
		$current_price     = $price_function( $product );
		$regular_price     = $raw_regular_price === ''
			? ''
			: $price_function(
				$product,
				[
					'price' => $raw_regular_price,
				]
			);

		return [
			'product'       => $product,
			'regular_price' => $regular_price,
			'current_price' => $current_price,
		];
	}

	/**
	 * Get cart item product savings.
	 *
	 * Coupon and order-level discounts are intentionally excluded. (#86caxp0b4)
	 *
	 * @since 2.4
	 *
	 * @param array        $cart_item Cart item data.
	 * @param string|false $cart_item_key Cart item key.
	 * @return array
	 */
	private function get_cart_item_savings_data( $cart_item, $cart_item_key = false ) {
		$price_data = $this->get_cart_item_price_data( $cart_item, $cart_item_key );

		if ( empty( $price_data ) ) {
			return [];
		}

		$regular_price = (float) $price_data['regular_price'];
		$current_price = (float) $price_data['current_price'];
		$quantity      = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;

		if ( $regular_price <= 0 || $regular_price <= $current_price || $quantity <= 0 ) {
			return [];
		}

		return [
			'amount'     => ( $regular_price - $current_price ) * $quantity,
			'percentage' => ( $regular_price - $current_price ) / $regular_price * 100,
		];
	}

	/**
	 * Get a cart item remove URL without leaking the loop product into the cart URL.
	 *
	 * WooCommerce can use global $post to preserve the URL of the current cart page. Cart query
	 * loops temporarily replace that global with the product, which would make the remove URL point
	 * to the product permalink. Use the queried page only while WooCommerce builds and filters the
	 * URL, then restore the product context for the remaining dynamic tags.
	 *
	 * @since 2.4
	 *
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	private function get_cart_remove_url( $cart_item_key ) {
		global $post;

		$loop_post    = $post;
		$queried_post = get_queried_object();

		try {
			if ( $queried_post instanceof \WP_Post ) {
				$post = $queried_post;
			}

			return wc_get_cart_remove_url( $cart_item_key );
		} finally {
			$post = $loop_post;
		}
	}

	/**
	 * Get the best matching free shipping method progress data for the cart.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_free_shipping_progress_data() {
		static $data = null;

		if ( $data !== null ) {
			return $data;
		}

		$progress_data = [
			'has_method'     => false,
			'min_amount'     => 0,
			'current_amount' => 0,
			'remaining'      => 0,
			'progress'       => 0,
		];

		if ( ! function_exists( 'WC' ) || ! WC() || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return $progress_data;
		}

		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			Woocommerce_Helpers::maybe_prepare_builder_cart_preview();
		} else {
			// Header templates can need calculated shipping data before a cart or checkout template renders.
			Woocommerce_Helpers::maybe_init_cart_context();
		}

		if ( ! WC()->cart || ! WC()->shipping() ) {
			return $progress_data;
		}

		// Header templates can render before the Cart shortcode prepares shipping packages.
		if ( is_cart() && empty( WC()->shipping()->get_packages() ) ) {
			wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
			WC()->cart->calculate_totals();
		}

		$packages = WC()->shipping()->get_packages();

		if ( empty( $packages ) && method_exists( WC()->cart, 'get_shipping_packages' ) ) {
			$packages = WC()->cart->get_shipping_packages();
		}

		if ( empty( $packages ) || ! is_array( $packages ) ) {
			return $progress_data;
		}

		foreach ( $packages as $package ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

			if ( ! $zone || ! method_exists( $zone, 'get_shipping_methods' ) ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( ! is_object( $method ) || ( $method->id ?? '' ) !== 'free_shipping' ) {
					continue;
				}

				$requires = method_exists( $method, 'get_option' )
					? $method->get_option( 'requires' )
					: ( $method->requires ?? '' );

				if ( ! in_array( $requires, [ 'min_amount', 'either', 'both' ], true ) ) {
					continue;
				}

				$min_amount = method_exists( $method, 'get_option' )
					? $method->get_option( 'min_amount', 0 )
					: ( $method->min_amount ?? 0 );
				$min_amount = (float) wc_format_decimal( $min_amount );

				if ( $min_amount <= 0 ) {
					continue;
				}

				$current_amount = $this->get_free_shipping_current_amount( $method );
				$is_available   = method_exists( $method, 'is_available' ) && $method->is_available( $package );
				$remaining      = $is_available ? 0 : max( $min_amount - $current_amount, 0 );
				$progress       = $is_available ? 100 : min(
					100,
					max( 0, round( ( $current_amount / $min_amount ) * 100, 2 ) )
				);

				if (
					! $progress_data['has_method'] ||
					$remaining < $progress_data['remaining'] ||
					( $remaining === $progress_data['remaining'] && $min_amount < $progress_data['min_amount'] )
				) {
					$progress_data = [
						'has_method'     => true,
						'min_amount'     => $min_amount,
						'current_amount' => $current_amount,
						'remaining'      => $remaining,
						'progress'       => $progress,
					];
				}
			}
		}

		if ( $progress_data['has_method'] ) {
			$data = $progress_data;
		}

		return $progress_data;
	}

	/**
	 * Get the cart amount WooCommerce uses for a free shipping minimum.
	 *
	 * @since 2.4
	 *
	 * @param object $method Free shipping method instance.
	 * @return float
	 */
	private function get_free_shipping_current_amount( $method ) {
		$total            = (float) WC()->cart->get_displayed_subtotal();
		$ignore_discounts = method_exists( $method, 'get_option' )
			? $method->get_option( 'ignore_discounts' )
			: ( $method->ignore_discounts ?? 'no' );

		if ( $ignore_discounts === 'no' ) {
			$total -= (float) WC()->cart->get_discount_total();

			if ( WC()->cart->display_prices_including_tax() ) {
				$total -= (float) WC()->cart->get_discount_tax();
			}
		}

		return round( max( 0, $total ), wc_get_price_decimals() );
	}

	/**
	 * Remove WooCommerce's outer strong wrapper from the cart order total.
	 *
	 * The total can contain nested strong tags added through WooCommerce filters,
	 * so the matching closing tag must be located instead of replacing every tag.
	 *
	 * @since 2.4 (#86cb4uqu9)
	 *
	 * @param string $value Cart order total HTML.
	 * @return string
	 */
	private function unwrap_cart_order_total( $value ) {
		if ( strpos( $value, '<strong>' ) !== 0 ) {
			return $value;
		}

		preg_match_all( '/<\/?strong\b[^>]*>/i', $value, $strong_tags, PREG_OFFSET_CAPTURE );

		$depth              = 0;
		$opening_tag_length = strlen( $strong_tags[0][0][0] );

		foreach ( $strong_tags[0] as $strong_tag ) {
			$tag       = $strong_tag[0];
			$tag_start = $strong_tag[1];

			if ( stripos( $tag, '</strong' ) === 0 ) {
				--$depth;

				if ( $depth === 0 ) {
					return substr( $value, $opening_tag_length, $tag_start - $opening_tag_length ) . substr( $value, $tag_start + strlen( $tag ) );
				}
			} else {
				++$depth;
			}
		}

		return $value;
	}

	/**
	 * Main function to render the tag value for WooCommerce provider
	 *
	 * @param [type] $tag
	 * @param [type] $post
	 * @param [type] $args
	 * @param [type] $context
	 */
	public function get_tag_value( $tag, $post, $args, $context ) {
		if (
			class_exists( '\Bricks\Woocommerce' ) &&
			! \Bricks\Woocommerce::use_advanced_modular_elements() &&
			\Bricks\Woocommerce::is_advanced_modular_dynamic_tag( $tag )
		) {
			// Saved content can keep beta tags, but they should not resolve while the beta is off.
			return '';
		}

		$post_id                = isset( $post->ID ) ? $post->ID : '';
		$product                = $post_id ? wc_get_product( $post_id ) : false;
		$active_loop_id         = \Bricks\Query::is_any_looping();
		$active_loop_query_type = $active_loop_id ? \Bricks\Query::get_query_object_type( $active_loop_id ) : false;
		$active_loop_object     = $active_loop_id ? \Bricks\Query::get_loop_object( $active_loop_id ) : false;

		if (
			$active_loop_id &&
			in_array( $active_loop_query_type, [ 'wooOrderItems', 'wooOrderDownloads', 'wooAccountDownloads' ], true )
		) {
			$loop_object = $active_loop_object;
			$item        = $loop_object['item'] ?? null;

			if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
				$product = $item->get_product();
			} elseif ( ! empty( $loop_object['product_id'] ) ) {
				$product = wc_get_product( $loop_object['product_id'] );
			}
		}

		// STEP: Check for filter args
		$filters = $this->get_filters_from_args( $args );

		// STEP: Get the value
		$value = '';

		$render = isset( $this->tags[ $tag ]['render'] ) ? $this->tags[ $tag ]['render'] : str_replace( 'woo_', '', $tag );

		// Isolated tag renders cannot rely on the Cart v2 root having prepared the preview first. (#86cb6j2b0)
		if ( isset( self::CART_PREVIEW_RENDER_TAGS[ $render ] ) ) {
			Woocommerce_Helpers::maybe_prepare_builder_cart_preview();
		}

		switch ( $render ) {
			case 'order_withdrawal_screen':
				$value = \Bricks\Woocommerce_Order_Withdrawal::get_context()['screen'] ?? '';
				break;

			case 'order_withdrawal_value':
				$value = esc_html( \Bricks\Woocommerce_Order_Withdrawal::get_value( $filters['meta_key'] ?? '' ) );
				// Review text preserves customer-entered line breaks after escaping the submitted value.
				if ( ( $filters['meta_key'] ?? '' ) === 'additional_details' && $context === 'text' ) {
					$value = nl2br( $value );
				}
				break;

			case 'url':
				// Default to 'shop' page if no key is provided, e.g.: {woo_url} will return the shop page URL
				$url_key = isset( $filters['meta_key'] ) ? $filters['meta_key'] : 'shop';
				$value   = $this->get_woo_url( $url_key );

				// A new-tab target only applies to rendered anchor markup, not a link-control URL.
				if ( $context === 'text' && ! empty( $filters['newTab'] ) ) {
					$filters['link'] = true;
				}
				break;

			case 'product_type':
				$value = $product ? $product->get_type() : '';
				break;

			case 'product_price':
				$loop_object_type = $active_loop_query_type;

				// Is inside of a cart loop (@since 1.5.3)
				if ( $loop_object_type === 'wooCart' ) {
					$loop_object   = $active_loop_object;
					$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
					$_product      = isset( $loop_object['data'] ) ? apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $cart_item_key ) : $product;

					if ( ! ( $_product instanceof \WC_Product ) ) {
						break;
					}

					$value = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $loop_object, $cart_item_key );
				}

				// default
				else {

					// Support ':value' filter to get the price value as a simple string (e.g.: 65.3, 2.5, 5 )
					if ( isset( $filters['value'] ) ) {
						$value = $product ? $product->get_price() : '';
					} else {
						$value = $product ? $product->get_price_html() : '';
					}

				}
				break;

			/**
			 *  Regular price - By default, output as html via wc_price.
			 *  Not for variable products
			 *  Use :plain filter to get the value without html (included symbol)
			 *  Use :value filter to get the value as a simple string (e.g.: 65.3, 2.5, 5 )
			 *
			 *  @since 1.8.4
			 */
			case 'product_regular_price':
				$value = $product ? $product->get_regular_price() : '';

				// default
				if ( ! isset( $filters['value'] ) ) {
					$value = $value ? wc_price( $value ) : '';
				}
				break;

			/**
			 *  Sale price - By default, output as html via wc_price.
			 *  Not for variable products, if the product has no sale price, empty string will be returned
			 *  Use :plain filter to get the value without html (included symbol)
			 *  Use :value filter to get the value as a simple string (e.g.: 65.3, 2.5, 5 )
			 *
			 *  @since 1.8.4
			 */
			case 'product_sale_price':
				$value = $product ? $product->get_sale_price() : '';

				// default
				if ( ! isset( $filters['value'] ) ) {
					$value = $value ? wc_price( $value ) : '';
				}
				break;

			case 'product_excerpt':
				// Product excerpt should keep HTML tags in dynamic data (@since 1.6)
				$keep_html = true;

				// @since 1.6.2 - To prevent the content from being trimmed again in format_value_for_text()
				$filters['trimmed'] = true;

				$value = \Bricks\Helpers::get_the_excerpt( $post, ! empty( $filters['num_words'] ) ? $filters['num_words'] : 55, null, $keep_html );
				$value = apply_filters( 'woocommerce_short_description', $value );
				break;

			case 'product_stock':
				if ( isset( $filters['value'] ) ) {
					// Return stock value only if value filter is set
					$value = $product ? Woocommerce_Helpers::get_stock_amount( $product ) : 0;
				} else {
					$value = $product ? $this->get_stock_html( $product ) : '';
				}
				break;

			case 'product_sku':
				$value = '';

				$loop_object_type = \Bricks\Query::is_looping() ? \Bricks\Query::get_query_object_type() : false;
				$sku_product      = $product;

				if ( $loop_object_type === 'wooCart' ) {
					// Support using dynamic tag in the custom cart loop (@since 2.4)
					$loop_object = \Bricks\Query::get_loop_object();
					$_product    = isset( $loop_object['data'] ) ? $loop_object['data'] : $product;

					if ( $_product && is_a( $_product, 'WC_Product' ) ) {
						$sku_product = $_product;
					}
				}

				if ( $sku_product && is_a( $sku_product, 'WC_Product' ) && wc_product_sku_enabled() && $sku_product->get_sku() ) {
					$value = $sku_product->get_sku();
				}

				// Wrap with class "sku" so Woo and Bricks can update variable product SKUs (#86cag8x2f; @since 2.3.9).
				// Apply filter ':value' to output as plain text (#37der3x) as ':raw' is not working.
				if ( ! isset( $filters['value'] ) ) {
					$product_id = 0;

					if ( $sku_product && is_a( $sku_product, 'WC_Product' ) ) {
						$product_id = $sku_product->get_id();
					}

					$value = sprintf(
						'<span class="sku brx-woo-product-sku" data-brx-woo-product-id="%s">%s</span>',
						esc_attr( $product_id ),
						esc_html( $value )
					);
				}
				break;

			// GTIN field introduced in WooCommerce 9.1.0. Usually used together with order information. (@since 2.4)
			case 'product_gtin':
				$value = '';

				// Check if get_global_unique_id method exists to prevent fatal error in older Woo versions
				if ( method_exists( 'WC_Product', 'get_global_unique_id' ) ) {
					$loop_object_type = \Bricks\Query::is_looping() ? \Bricks\Query::get_query_object_type() : false;

					if ( $loop_object_type === 'wooCart' ) {
						// Support using dynamic tag in the custom cart loop
						$loop_object = \Bricks\Query::get_loop_object();
						$_product    = isset( $loop_object['data'] ) ? $loop_object['data'] : $product;
						if ( $_product && is_a( $_product, 'WC_Product' ) && $_product->get_global_unique_id() ) {
							$value = $_product->get_global_unique_id();
						}
					} else {
						if ( $product && is_a( $product, 'WC_Product' ) && $product->get_global_unique_id() ) {
							$value = $product->get_global_unique_id();
						}
					}

					// Follow SKU logic to wrap with class "gtin" and apply ':value' filter to output as plain text
					if ( ! isset( $filters['value'] ) && $value ) {
						$value = "<span class=\"gtin\">{$value}</span>";
					}
				}

				break;

			case 'product_rating':
				if ( $product && wc_review_ratings_enabled() ) {
					if ( isset( $filters['value'] ) ) {
						$average = $product->get_average_rating();

						// Support ':value' filter to get the rating value as a simple string (e.g.: 0, 2.50, 5.00)
						$value = $average;
					} else {
						/**
						 * Use Brick's render_product_rating()
						 *
						 * Support ':format' filter to show empty stars even if the product has no rating
						 *
						 * @since 1.8
						 */
						$params = [
							'wrapper'           => false,
							'hide_reviews_link' => true,
							'show_empty_stars'  => isset( $filters['format'] ),
						];
						$value  = \Bricks\Woocommerce_Helpers::render_product_rating( $product, $params, false );
					}
				}
				break;

			case 'product_on_sale':
				$value = $product && $product->is_on_sale() ? apply_filters( 'woocommerce_sale_flash', '<span class="badge onsale">' . esc_html__( 'Sale!', 'bricks' ) . '</span>', $post, $product ) : '';
				break;

			case 'product_badge_new':
				$value = \Bricks\Woocommerce::badge_new();
				break;

			case 'add_to_cart':
				/**
				 * Skip sanitize for add to cart button
				 *
				 * As user might add more HTML tags via woocommerce_loop_add_to_cart_link filter (only affects text context).
				 *
				 * @since 1.6.2
				 */
				$filters['skip_sanitize'] = true;

				$value = $this->get_add_to_cart_value( $product, $filters, $context );
				break;

			case 'product_cat_image':
				$filters['object_type'] = 'media';
				$filters['image']       = 'true';

				// Loop
				if ( $active_loop_id && \Bricks\Query::get_loop_object_type( $active_loop_id ) == 'term' ) {
					$term_id = \Bricks\Query::get_loop_object_id( $active_loop_id );
				}

				// Template preview
				elseif ( \Bricks\Helpers::is_bricks_template( $post_id ) ) {
					$template_preview_type = \Bricks\Helpers::get_template_setting( 'templatePreviewType', $post_id );

					if ( 'archive-term' === $template_preview_type ) {
						$template_preview_term          = \Bricks\Helpers::get_template_setting( 'templatePreviewTerm', $post_id );
						$template_preview_term_id_parts = ! empty( $template_preview_term ) ? explode( '::', $template_preview_term ) : '';

						$term_id = isset( $template_preview_term_id_parts[1] ) ? $template_preview_term_id_parts[1] : '';
					}
				}

				// Product Cat archive
				elseif ( is_tax( 'product_cat' ) ) {
					$queried_object = get_queried_object();
					$term_id        = isset( $queried_object->term_id ) ? $queried_object->term_id : '';
				}

				// Single product
				elseif ( is_singular( 'product' ) ) {
					$terms   = wp_get_post_terms( $post_id, 'product_cat' );
					$term_id = isset( $terms[0]->term_id ) ? $terms[0]->term_id : 0;
				}

				$value = ! empty( $term_id ) ? get_term_meta( $term_id, 'thumbnail_id', true ) : '';
				break;

			// Product gallery images (@since 1.11)
			case 'product_images':
			case 'product_gallery_images':
				// Support ':value' filter to get the gallery image ids as a simple string (e.g.: 1, 2, 3)
				if ( ! isset( $filters['value'] ) ) {
					$filters['object_type'] = 'media';
					$filters['image']       = 'true';
					$filters['separator']   = '';
				}

				// Get the product gallery images
				$gallery_ids = $product ? $product->get_gallery_image_ids() : [];

				// Prepend the featured image to the gallery images
				if ( $render === 'product_images' && $product && $product->get_image_id() ) {
					array_unshift( $gallery_ids, $product->get_image_id() );
				}

				$value = ! empty( $gallery_ids ) ? $gallery_ids : '';
				break;

			// Expected result: 'instock', 'outofstock', 'onbackorder' (@since 1.6.1)
			case 'product_stock_status':
				$value = $product ? $product->get_stock_status() : '';
				break;

			// Cart (@since 1.5.3)
			// @see /wp-content/plugins/woocommerce/templates/cart/cart.php
			case 'cart_product_name':
			case 'cart_product_title':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCart' ) {
					$filters['skip_sanitize'] = true;

					$loop_object   = $active_loop_object;
					$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
					$_product      = isset( $loop_object['data'] ) ? apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $cart_item_key ) : $product;

					if ( ! ( $_product instanceof \WC_Product ) ) {
						break;
					}

					// V2's title tag leaves attributes to its item-data element. The legacy
					// full-name tag includes contextual selections and classic metadata.
					$is_title_only = $render === 'cart_product_title';
					$product_name  = $is_title_only ? $_product->get_title() : Woocommerce_Helpers::get_cart_item_product_name( $loop_object, $_product );
					$_product_name = apply_filters( 'woocommerce_cart_item_name', $product_name, $loop_object, $cart_item_key );

					$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $loop_object ) : '', $loop_object, $cart_item_key );

					if ( ! $product_permalink ) {
						$value = wp_kses_post( $_product_name . '&nbsp;' );
					} else {
						$value = wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $product_name ), $loop_object, $cart_item_key ) );
					}

					// Preserve the complete legacy cart product name output for saved layouts.
					if ( ! $is_title_only ) {
						ob_start();
						do_action( 'woocommerce_after_cart_item_name', $loop_object, $cart_item_key );
						$value .= ob_get_clean();

						// Compare attributes with the unfiltered name; extension markup must
						// not change Woo's suppression of values already present in that name.
						$value .= wc_get_formatted_cart_item_data( $loop_object, false, $product_name );

						// Backorder notification.
						if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $loop_object['quantity'] ) ) {
							$value .= wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $_product->get_id() ) );
						}
					}
				}

				break;

			case 'cart_remove_link':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				// Is inside of a cart loop
				if ( $loop_object_type === 'wooCart' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;

					$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
					$_product      = isset( $loop_object['data'] ) ? apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $cart_item_key ) : $product;

					if ( ! ( $_product instanceof \WC_Product ) || ! $cart_item_key ) {
						break;
					}

					// @since 1.8.1 - WooCommerce 7.8 compatibility
					$_product_name = apply_filters( 'woocommerce_cart_item_name', Woocommerce_Helpers::get_cart_item_product_name( $loop_object, $_product ), $loop_object, $cart_item_key );
					$product_id    = apply_filters( 'woocommerce_cart_item_product_id', isset( $loop_object['product_id'] ) ? $loop_object['product_id'] : $_product->get_id(), $loop_object, $cart_item_key );

					if ( isset( $filters['url'] ) ) {
						// Support ':url' filter to get the remove URL as a simple string
						$value = $cart_item_key ? $this->get_cart_remove_url( $cart_item_key ) : '';
					} else {
						$value = $_product && $cart_item_key ? apply_filters(
							'woocommerce_cart_item_remove_link',
							sprintf(
								// translators: %s Product name.
								'<a role="button" href="%s" class="remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">&times;</a>',
								esc_url( $this->get_cart_remove_url( $cart_item_key ) ),
								// translators: %s Product name.
								esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $_product_name ) ) ),
								esc_attr( $product_id ),
								esc_attr( $_product->get_sku() )
							),
							$cart_item_key
						) : '';
					}
				}

				break;

			case 'cart_quantity':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				// Is inside of a cart loop
				if ( $loop_object_type === 'wooCart' ) {
					$filters['skip_sanitize'] = true;

					$loop_object   = $active_loop_object;
					$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
					$_product      = isset( $loop_object['data'] ) ? apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $cart_item_key ) : $product;

					if ( ! ( $_product instanceof \WC_Product ) ) {
						break;
					}

					// @since 1.8.1 - WooCommerce 7.8 compatibility
					$_product_name = apply_filters( 'woocommerce_cart_item_name', Woocommerce_Helpers::get_cart_item_product_name( $loop_object, $_product ), $loop_object, $cart_item_key );

					if ( $_product->is_sold_individually() ) {
						$product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
					} else {
						$product_quantity = woocommerce_quantity_input(
							[
								'input_name'   => "cart[{$cart_item_key}][qty]",
								'input_value'  => $loop_object['quantity'],
								'max_value'    => $_product->get_max_purchase_quantity(),
								'min_value'    => '0',
								'product_name' => $_product_name,
							],
							$_product,
							false
						);
					}

					if ( ! isset( $filters['value'] ) ) {
						$value = apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $loop_object );
						$value = "<div class=\"product-quantity brx-woo-cart-quantity-update\">{$value}</div>";
					} else {
						// Support ':value' filter to get the quantity value as a simple string (e.g.: 1, 2, 3) For checkout page (@since 2.4)
						$value = apply_filters( 'woocommerce_cart_item_quantity', $loop_object['quantity'], $cart_item_key, $loop_object );
					}
				}
				break;

			/**
			 * Cart item unit price and savings.
			 *
			 * Uses the cart item product so variations and pricing extensions are
			 * reflected, and follows the cart tax display setting. (#86caxp0b4)
			 *
			 * @since 2.4
			 */
			case 'cart_item_price':
			case 'cart_item_save':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type !== 'wooCart' || is_null( WC()->cart ) ) {
					break;
				}

				$loop_object   = $active_loop_object;
				$cart_item_key = isset( $loop_object['key'] ) ? $loop_object['key'] : false;

				if ( $render === 'cart_item_price' ) {
					$price_data = $this->get_cart_item_price_data( $loop_object, $cart_item_key );

					if ( empty( $price_data ) ) {
						break;
					}

					$regular_price = $price_data['regular_price'];
					$current_price = $price_data['current_price'];
					$_product      = $price_data['product'];

					if ( isset( $filters['value'] ) ) {
						$value = wc_format_decimal( $current_price, wc_get_price_decimals(), true );
						break;
					}

					$filters['skip_sanitize'] = true;
					$value                    = $regular_price !== '' && (float) $regular_price > (float) $current_price
						? wc_format_sale_price( $regular_price, $current_price )
						: wc_price( $current_price );
					$value                    = apply_filters( 'woocommerce_cart_product_price', $value, $_product );
					$value                    = apply_filters( 'woocommerce_cart_item_price', $value, $loop_object, $cart_item_key );
					break;
				}

				$savings_data = $this->get_cart_item_savings_data( $loop_object, $cart_item_key );

				if ( empty( $savings_data ) ) {
					break;
				}

				if ( isset( $filters['meta_key'] ) && $filters['meta_key'] === 'percentage' ) {
					$value = wc_format_decimal( $savings_data['percentage'], 0, true ); // (#86cb8veq7; @since 2.4)
				} elseif ( isset( $filters['value'] ) ) {
					$value = wc_format_decimal( $savings_data['amount'], wc_get_price_decimals(), true );
				} else {
					$filters['skip_sanitize'] = true;
					$value                    = wc_price( $savings_data['amount'] );
				}
				break;

			case 'cart_subtotal':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCart' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;
					$cart_item_key            = isset( $loop_object['key'] ) ? $loop_object['key'] : false;
					$_product                 = isset( $loop_object['data'] ) ? apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $cart_item_key ) : $product;

					if ( ! ( $_product instanceof \WC_Product ) ) {
						break;
					}

					$value = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $loop_object['quantity'] ), $loop_object, $cart_item_key );
				}
				break;

			case 'cart_order_subtotal':
				ob_start();
				wc_cart_totals_subtotal_html();
				$value = ob_get_clean();
				break;

			case 'cart_order_total':
				ob_start();
				wc_cart_totals_order_total_html();
				$value = $this->unwrap_cart_order_total( ob_get_clean() );
				break;

			case 'cart_applied_coupon':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCartCoupons' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;
					$coupon_code              = is_a( $loop_object, 'WC_Coupon' ) ? $loop_object->get_code() : '';

					if ( $coupon_code ) {
						if ( isset( $filters['value'] ) ) {
							// Support ':value' filter to get the coupon code as a simple string (e.g.: SUMMER21)
							$value = $coupon_code;
						} else {
							$value = wc_cart_totals_coupon_label( $coupon_code, false );
						}
					}
				}
				break;

			case 'cart_applied_coupon_amount':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCartCoupons' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;
					$coupon_code              = is_a( $loop_object, 'WC_Coupon' ) ? $loop_object->get_code() : '';

					if ( $coupon_code ) {
						$amount               = WC()->cart->get_coupon_discount_amount( $coupon_code, WC()->cart->display_cart_ex_tax );
						$discount_amount_html = wc_price( $amount );
						$value                = $discount_amount_html;
					}
				}
				break;

			case 'cart_applied_coupon_total_amount':
				$value = '';

				$applied_coupons = WC()->cart->get_applied_coupons();

				if ( $applied_coupons ) {
					$total_discount = 0;

					foreach ( $applied_coupons as $coupon_code ) {
						$total_discount += WC()->cart->get_coupon_discount_amount( $coupon_code, WC()->cart->display_cart_ex_tax );
					}

					if ( isset( $filters['value'] ) ) {
						// Support ':value' filter to get the total discount amount as a simple string (e.g.: 10.00)
						$value = $total_discount;
					} else {
						$value = wc_price( $total_discount );
					}
				}

				break;

			case 'cart_applied_fee':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCartFees' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;
					// Fee is a stdClass object
					$fee_name = is_object( $loop_object ) && isset( $loop_object->name ) ? $loop_object->name : '';

					if ( $fee_name ) {
						$value = esc_html( $fee_name );
					}
				}

				break;

			case 'cart_applied_fee_amount':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCartFees' ) {
					$filters['skip_sanitize'] = true;
					$loop_object              = $active_loop_object;
					// Fee is a stdClass object, ensure has total
					$fee = is_object( $loop_object ) && isset( $loop_object->total ) ? $loop_object : null;

					if ( $fee ) {
						$fee_amount = WC()->cart->display_prices_including_tax()
							? (float) $fee->total + (float) $fee->tax
							: (float) $fee->total;

						if ( isset( $filters['value'] ) ) {
							$value = $fee_amount;
						} else {
							ob_start();
							wc_cart_totals_fee_html( $fee );
							$value = ob_get_clean();
						}
					}
				}

				break;

			case 'cart_applied_fee_total_amount':
				$value = '';

				$total_fee = 0;

				foreach ( WC()->cart->get_fees() as $fee ) {
					$total_fee += WC()->cart->display_prices_including_tax()
						? (float) $fee->total + (float) $fee->tax
						: (float) $fee->total;
				}

				if ( isset( $filters['value'] ) ) {
					// Support ':value' filter to get the total fee amount as a simple string (e.g.: 10.00)
					$value = $total_fee;
				} else {
					$value = wc_price( $total_fee );
				}

				break;

			case 'cart_shipping_method':
				$value = '';

				$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
				$packages       = WC()->shipping()->get_packages();

				if ( $chosen_methods && $packages ) {
					$labels = [];

					foreach ( $chosen_methods as $package_index => $chosen_rate_id ) {
						if ( empty( $packages[ $package_index ]['rates'][ $chosen_rate_id ] ) ) {
							continue;
						}

						$rate = $packages[ $package_index ]['rates'][ $chosen_rate_id ];

						if ( is_object( $rate ) && method_exists( $rate, 'get_label' ) ) {
							$labels[] = $rate->get_label();
						}
					}

					if ( $labels ) {
						$value = implode( ', ', $labels );
					}
				}

				break;

			case 'cart_shipping_total_amount':
				$value          = 0;
				$chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [];
				$packages       = WC()->shipping()->get_packages();
				$total_shipping = 0;

				foreach ( $chosen_methods as $package_index => $chosen_rate_id ) {
					$rate = $packages[ $package_index ]['rates'][ $chosen_rate_id ] ?? null;

					if ( $rate ) {
						$shipping_amount = (float) $rate->get_cost();

						if ( WC()->cart->display_prices_including_tax() && method_exists( $rate, 'get_taxes' ) ) {
							$shipping_amount += array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) );
						}

						$total_shipping += $shipping_amount;
					}
				}

				if ( isset( $filters['value'] ) ) {
					// Support ':value' filter to get the shipping total amount as a simple string (e.g.: 0, 5.99)
					$value = $total_shipping;
				} elseif ( $total_shipping > 0 ) {
					$value = wc_price( $total_shipping );
				} else {
					$value = esc_html__( 'Free', 'woocommerce' );
				}
				break;

			case 'free_shipping_min_amount':
			case 'free_shipping_remaining':
			case 'free_shipping_progress':
				$free_shipping_data = $this->get_free_shipping_progress_data();

				if ( ! empty( $free_shipping_data['has_method'] ) ) {
					if ( $render === 'free_shipping_progress' ) {
						$value = isset( $filters['value'] )
							? $free_shipping_data['progress']
							: $free_shipping_data['progress'] . '%';
					} else {
						$amount = $render === 'free_shipping_remaining'
							? $free_shipping_data['remaining']
							: $free_shipping_data['min_amount'];
						$value  = isset( $filters['value'] ) ? $amount : wc_price( $amount );
					}
				}

				break;

			case 'cart_applied_tax_label':
			case 'cart_applied_tax_amount':
				$value = '';

				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooCartTaxes' ) {
					$loop_object = $active_loop_object;
					// Looping object is an array with 'label' and 'amount' keys ('code' only if itemized taxes enabled)

					$tax_label  = isset( $loop_object['label'] ) ? $loop_object['label'] : '';
					$tax_amount = isset( $loop_object['amount'] ) ? $loop_object['amount'] : 0;

					$itemized = get_option( 'woocommerce_tax_total_display' ) === 'itemized';

					if ( $render === 'cart_applied_tax_label' ) {
						$value = $tax_label;
					} else {
						$value = $tax_amount;

						if ( ! $itemized && ! isset( $filters['value'] ) ) {
							// wc_cart_totals_taxes_total_html()
							$value = apply_filters( 'woocommerce_cart_totals_taxes_total_html', wc_price( $tax_amount ) );
						}
					}
				}

				break;

			case 'checkout_current_step_number':
				$filters['skip_sanitize'] = true;
				// Always render a placeholder. Frontend JS resolves the final value only when the
				// tag is inside a checkout step or checkout step nav item.
				$value = '<span data-brx-woo-checkout-current-step-number="true">#</span>';
				break;

			case 'cart_update':
				$filters['skip_sanitize'] = true;

				$value = '<button type="submit" class="button" name="update_cart" value="' . esc_attr__( 'Update cart', 'woocommerce' ) . '">' . esc_html__( 'Update cart', 'woocommerce' ) . '</button>';

				// @see https://developer.wordpress.org/reference/functions/wp_nonce_field/
				$value .= wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce', true, false );
				break;

			case 'cart_items_count':
				$value = is_object( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
				break;

			// Account addresses (@since 2.4)
			case 'account_addresses_description':
				$value = \Bricks\Woocommerce::get_account_addresses_description();
				break;

			case 'account_address_type':
			case 'account_address_title':
			case 'account_address':
			case 'account_address_has_address':
			case 'account_address_edit_url':
			case 'account_address_action_label':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooAccountAddresses' && is_array( $active_loop_object ) ) {
					switch ( $render ) {
						case 'account_address_type':
							$value = $active_loop_object['type'] ?? '';
							break;

						case 'account_address_title':
							$value = $active_loop_object['title'] ?? '';
							break;

						case 'account_address':
							$value = ! empty( $active_loop_object['formatted_address'] )
								? $active_loop_object['formatted_address']
								: esc_html__( 'You have not set up this type of address yet.', 'woocommerce' );
							break;

						case 'account_address_has_address':
							$value = ! empty( $active_loop_object['has_address'] ) ? '1' : '0';
							break;

						case 'account_address_edit_url':
							$value = $active_loop_object['edit_url'] ?? '';
							break;

						case 'account_address_action_label':
							$value = $active_loop_object['action_label'] ?? '';
							break;
					}
				}
				break;

			case 'account_edit_address_title':
				$value = \Bricks\Woocommerce::get_account_edit_address_title();
				break;

			case 'account_edit_address_type':
				$value = \Bricks\Woocommerce::get_account_edit_address_type();
				break;

			case 'account_edit_address_type_label':
				$value = \Bricks\Woocommerce::get_account_edit_address_type_label();
				break;

			// Checkout order
			case 'order_id':
				$order = $this->get_order();
				$value = $order ? $order->get_id() : '';
				break;

			case 'order_number':
				$order = $this->get_order();
				$value = $order ? $order->get_order_number() : '';
				break;

			case 'order_date':
				$filters['object_type'] = 'date';

				$order = $this->get_order();
				$value = $order ? wc_format_datetime( $order->get_date_created(), 'U' ) : '';
				break;

			case 'order_failed_text':
				$order = $this->get_order();

				if ( $order && $order->has_status( 'failed' ) ) {
					$value = Woocommerce_Helpers::get_order_failed_text( $order );
				} elseif ( bricks_is_builder() || bricks_is_builder_call() ) {
					// Keep the message editable without a failed preview order. The Woo filter
					// expects a real failed order, so synthetic previews use the default directly.
					$value = Woocommerce_Helpers::get_default_order_failed_text();
				}

				// The helper already sanitizes HTML; preserve allowed extension markup.
				$filters['skip_sanitize'] = true;
				break;

			case 'order_status':
				$order = $this->get_order();

				if ( is_a( $order, 'WC_Order' ) ) {
					$status = $order->get_status();
					$value  = isset( $filters['value'] ) ? $status : wc_get_order_status_name( $status );
				}
				break;

			case 'order_total':
				$order = $this->get_order();
				$value = $order ? $order->get_formatted_order_total() : '';
				break;

			case 'order_payment_title':
				$order = $this->get_order();
				$value = $order ? $order->get_payment_method_title() : '';
				break;

			case 'order_email':
				$order = $this->get_order();
				$value = $order ? $order->get_billing_email() : '';
				break;

			case 'order_checkout_payment_url':
				$order = $this->get_order();
				$value = $order ? $order->get_checkout_payment_url() : '';
				break;

			case 'order_again_url':
				$order = $this->get_order();
				$value = '';

				if ( is_a( $order, 'WC_Order' ) ) {
					$statuses_for_reordering = apply_filters( 'woocommerce_valid_order_statuses_for_order_again', [ \Automattic\WooCommerce\Enums\OrderStatus::COMPLETED ] );

					if ( $order->has_status( $statuses_for_reordering ) && is_user_logged_in() ) {
						$value = wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' );
					}
				}
				break;

			case 'order_user_id':
				$order = $this->get_order();
				$value = $order ? $order->get_user_id() : '';
				break;

			case 'order_billing_address':
				$order = $this->get_order();
				$value = '';

				if ( is_a( $order, 'WC_Order' ) ) {
					$filters['skip_sanitize'] = true;
					$value                    = wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) );
				}
				break;

			case 'order_billing_phone':
				$order = $this->get_order();
				$value = $order ? $order->get_billing_phone() : '';
				break;

			case 'order_shipping_address':
				$order = $this->get_order();
				$value = '';

				if ( is_a( $order, 'WC_Order' ) ) {
					$filters['skip_sanitize'] = true;
					$value                    = wp_kses_post( $order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) );
				}
				break;

			case 'order_shipping_phone':
				$order = $this->get_order();
				$value = $order ? $order->get_shipping_phone() : '';
				break;

			case 'order_item_name':
			case 'order_item_title':
				$loop_object_type = $active_loop_query_type;
				$value            = '';

				if ( $loop_object_type === 'wooOrderItems' ) {
					$loop_object = $active_loop_object;
					$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : null;
					$order       = isset( $loop_object['order'] ) ? $loop_object['order'] : null;

					if ( is_a( $item, 'WC_Order_Item_Product' ) && is_a( $order, 'WC_Order' ) ) {
						$product       = $item->get_product();
						$is_visible    = $product && $product->is_visible();
						$is_title_only = $render === 'order_item_title';

						/*
						 * Historical order items do not persist a separate parent
						 * product title. Keep their saved item name as a usable
						 * fallback when the original product no longer exists.
						 */
						$item_name = $is_title_only && $product ? $product->get_title() : $item->get_name();

						if ( isset( $filters['value'] ) ) {
							$value = $item_name;
						} else {
							$filters['skip_sanitize'] = true;

							$product_permalink = apply_filters( 'woocommerce_order_item_permalink', $is_visible ? $product->get_permalink( $item ) : '', $item, $order );

							$value = wp_kses_post( apply_filters( 'woocommerce_order_item_name', $product_permalink ? sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $item_name ) : $item_name, $item, $is_visible ) );
						}
					}
				}
				break;

			case 'order_item_quantity':
				$loop_object_type = $active_loop_query_type;
				$value            = '';

				if ( $loop_object_type === 'wooOrderItems' ) {
					$loop_object = $active_loop_object;
					$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : null;
					$order       = isset( $loop_object['order'] ) ? $loop_object['order'] : null;

					if ( is_a( $item, 'WC_Order_Item_Product' ) && is_a( $order, 'WC_Order' ) ) {
						$qty          = $item->get_quantity();
						$refunded_qty = $order->get_qty_refunded_for_item( $item->get_id() );
						$net_qty      = $qty + $refunded_qty;

						if ( isset( $filters['value'] ) ) {
							$value = $net_qty;
						} else {
							if ( $refunded_qty ) {
								$qty_display = '<del>' . esc_html( $qty ) . '</del> <ins>' . esc_html( $net_qty ) . '</ins>';
							} else {
								$qty_display = esc_html( $qty );
							}

							$filters['skip_sanitize'] = true;
							$value                    = apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', $qty_display ) . '</strong>', $item );
						}
					}
				}
				break;

			case 'order_item_price':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderItems' ) {
					$loop_object = $active_loop_object;
					$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : null;
					$order       = isset( $loop_object['order'] ) ? $loop_object['order'] : null;

					if ( is_a( $item, 'WC_Order_Item_Product' ) && is_a( $order, 'WC_Order' ) ) {
						$quantity    = max( 1, absint( $item->get_quantity() ) );
						$include_tax = get_option( 'woocommerce_tax_display_cart' ) !== 'excl';
						$unit_price  = $order->get_line_total( $item, $include_tax ) / $quantity;

						if ( isset( $filters['value'] ) ) {
							$value = wc_format_decimal( $unit_price, wc_get_price_decimals() );
						} else {
							$filters['skip_sanitize'] = true;
							$value                    = wc_price(
								$unit_price,
								[
									'currency' => $order->get_currency(),
								]
							);
						}
					}
				}
				break;

			case 'order_item_line_subtotal':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderItems' ) {
					$loop_object = $active_loop_object;
					$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : null;
					$order       = isset( $loop_object['order'] ) ? $loop_object['order'] : null;

					if ( is_a( $item, 'WC_Order_Item_Product' ) && is_a( $order, 'WC_Order' ) ) {
						$filters['skip_sanitize'] = true;
						$value                    = $order->get_formatted_line_subtotal( $item );
					}
				}
				break;

			case 'order_item_meta':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderItems' ) {
					$loop_object = $active_loop_object;
					$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : null;
					$order       = isset( $loop_object['order'] ) ? $loop_object['order'] : null;

					if ( is_a( $item, 'WC_Order_Item_Product' ) && is_a( $order, 'WC_Order' ) ) {
						$filters['skip_sanitize'] = true;
						$item_id                  = $item->get_id();
						ob_start();
						do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );
						wc_display_item_meta( $item );
						do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
						$value = ob_get_clean();
					}
				}
				break;

			case 'order_total_label':
				$loop_object_type = $active_loop_query_type;
				$value            = '';
				if ( $loop_object_type === 'wooOrderTotals' ) {
					$loop_object = $active_loop_object;
					$value       = isset( $loop_object['label'] ) ? $loop_object['label'] : '';
				}
				break;

			case 'order_total_value':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderTotals' ) {
					$loop_object = $active_loop_object;

					if ( isset( $filters['value'] ) ) {
						$value = isset( $loop_object['value'] ) ? wp_strip_all_tags( $loop_object['value'] ) : '';
					} else {
						$filters['skip_sanitize'] = true;
						$value                    = isset( $loop_object['value'] ) ? $loop_object['value'] : '';
					}
				}
				break;

			case 'order_view_url':
				$order = $this->get_order();
				$value = $order ? $order->get_view_order_url() : '';
				break;

			case 'order_view_aria_label':
				$order = $this->get_order();
				/* translators: %s: Order number. */
				$value = $order ? sprintf( __( 'View order number %s', 'woocommerce' ), $order->get_order_number() ) : '';
				break;

			case 'order_item_count':
				$order = $this->get_order();
				$value = $order ? $order->get_item_count() - $order->get_item_count_refunded() : '';
				break;

			case 'order_total_with_item_count':
				$order = $this->get_order();

				if ( is_a( $order, 'WC_Order' ) ) {
					$item_count               = $order->get_item_count() - $order->get_item_count_refunded();
					$filters['skip_sanitize'] = true;
					/* translators: 1: formatted order total 2: total order items */
					$value = wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
				}
				break;

			case 'order_action_url':
				$action = $this->get_order_action();
				$value  = $action['url'] ?? '';
				break;

			case 'order_action_name':
				$action = $this->get_order_action();
				$value  = $action['name'] ?? '';
				break;

			case 'order_action_aria_label':
				$action = $this->get_order_action();
				$order  = $this->get_order();

				if ( ! empty( $action['aria-label'] ) ) {
					$value = $action['aria-label'];
				} elseif ( is_a( $order, 'WC_Order' ) && ! empty( $action['name'] ) ) {
					/* translators: %1$s Action name, %2$s Order number. */
					$value = sprintf( __( '%1$s order number %2$s', 'woocommerce' ), $action['name'], $order->get_order_number() );
				}
				break;

			case 'order_download_product':
				$loop_object_type = $active_loop_query_type;

				if (
					in_array( $loop_object_type, [ 'wooOrderDownloads', 'wooAccountDownloads' ], true )
				) {
					$download = $active_loop_object;

					if ( isset( $filters['value'] ) ) {
						$value = $download['product_name'] ?? '';
					} elseif ( ! empty( $download['product_url'] ) ) {
						$filters['skip_sanitize'] = true;
						$value                    = '<a href="' . esc_url( $download['product_url'] ) . '">' . esc_html( $download['product_name'] ?? '' ) . '</a>';
					} else {
						$value = $download['product_name'] ?? '';
					}
				}
				break;

			case 'order_download_name':
				$loop_object_type = $active_loop_query_type;

				if (
					in_array( $loop_object_type, [ 'wooOrderDownloads', 'wooAccountDownloads' ], true )
				) {
					$download = $active_loop_object;
					$value    = $download['download_name'] ?? '';
				}
				break;

			case 'order_download_url':
				$loop_object_type = $active_loop_query_type;

				if (
					in_array( $loop_object_type, [ 'wooOrderDownloads', 'wooAccountDownloads' ], true )
				) {
					$download = $active_loop_object;
					$value    = $download['download_url'] ?? '';
				}
				break;

			case 'order_downloads_remaining':
				$loop_object_type = $active_loop_query_type;

				if (
					in_array( $loop_object_type, [ 'wooOrderDownloads', 'wooAccountDownloads' ], true )
				) {
					$download            = $active_loop_object;
					$downloads_remaining = $download['downloads_remaining'] ?? '';
					$value               = is_numeric( $downloads_remaining ) ? $downloads_remaining : esc_html__( '&infin;', 'woocommerce' );
				}
				break;

			case 'order_download_access_expires':
				$loop_object_type = $active_loop_query_type;

				if (
					in_array( $loop_object_type, [ 'wooOrderDownloads', 'wooAccountDownloads' ], true )
				) {
					$download       = $active_loop_object;
					$access_expires = $download['access_expires'] ?? '';

					if ( ! empty( $access_expires ) ) {
						if ( isset( $filters['value'] ) ) {
							$value = strtotime( $access_expires );
						} else {
							$filters['skip_sanitize'] = true;
							$value                    = '<time datetime="' . esc_attr( date( 'Y-m-d', strtotime( $access_expires ) ) ) . '" title="' . esc_attr( strtotime( $access_expires ) ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $access_expires ) ) ) . '</time>';
						}
					} else {
						$value = esc_html__( 'Never', 'woocommerce' );
					}
				}
				break;

			case 'order_customer_note_date':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderCustomerNotes' ) {
					$note = $active_loop_object;

					if ( is_a( $note, 'WP_Comment' ) && ! empty( $note->comment_date ) ) {
						$filters['object_type'] = 'date';
						$value                  = strtotime( $note->comment_date );
					}
				}
				break;

			case 'order_customer_note_comment':
				$loop_object_type = $active_loop_query_type;

				if ( $loop_object_type === 'wooOrderCustomerNotes' ) {
					$note = $active_loop_object;

					if ( is_a( $note, 'WP_Comment' ) ) {
						$filters['skip_sanitize'] = true;
						$value                    = wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) );
					}
				}
				break;

			/**
			 * Woo Phase 3 - default return endpoint Label, support :url
			 *
			 * Endpoints: dashboard, orders, downloads, edit-address, edit-account, customer-logout
			 *
			 * NOTE: Not in use!
			 */
			// case 'my_account_endpoint':
			// $filters['skip_sanitize'] = true;
			// $is_url = isset( $filters['url'] ) ? $filters['url'] : false;
			// $endpoint_from_user = isset( $filters['meta_key'] ) ? $filters['meta_key'] : false;
			// $endpoint = false;

			// if ( $endpoint_from_user ) {
			// User entered account endpoint such as {woo_my_account_endpoint:dashboard}
			// $endpoints = wc_get_account_menu_items();

			// Search the endpoint from the array key
			// $find_endpoint = array_filter( $endpoints, function( $key ) use ( $endpoint_from_user ) {
			// return $key === $endpoint_from_user;
			// }, ARRAY_FILTER_USE_KEY );

			// Once found, pick the endpoint as array with key and value
			// if ( count( $find_endpoint ) === 1 ) {
			// $endpoint['endpoint'] = $endpoint_from_user;
			// $endpoint['label']    = array_values( $find_endpoint )[0];
			// }
			// }

			// if ( ! $endpoint ) {
			// return '';
			// }

			// $value = $is_url ? esc_url( wc_get_account_endpoint_url( $endpoint['endpoint'] ) ) : esc_html( $endpoint['label'] );
			// break;
		}

		// STEP: Apply context (text, link, image, media)
		$value = $this->format_value_for_context( $value, $tag, $post_id, $filters, $context );

		return $value;
	}

	/**
	 * Get a common WooCommerce URL by key.
	 *
	 * @since 2.4
	 *
	 * @param string $url_key URL key.
	 *
	 * @return string
	 */
	public function get_woo_url( $url_key = 'shop' ) {
		$url_key = is_string( $url_key ) ? sanitize_key( $url_key ) : '';
		$url_key = $url_key ? $url_key : 'shop';

		switch ( $url_key ) {
			case 'shop':
				return wc_get_page_permalink( 'shop' );

			case 'cart':
				return wc_get_cart_url();

			case 'checkout':
				return wc_get_checkout_url();

			case 'account':
			case 'myaccount':
				return wc_get_page_permalink( 'myaccount' );

			case 'dashboard':
				return wc_get_account_endpoint_url( 'dashboard' );

			case 'addresses':
				return wc_get_account_endpoint_url( 'edit-address' );

			case 'orders':
			case 'downloads':
			case 'edit-address':
			case 'edit-account':
			case 'payment-methods':
			case 'add-payment-method':
			case 'lost-password':
			case 'customer-logout':
				return wc_get_account_endpoint_url( $url_key );

			case 'terms':
				$page_id = function_exists( 'wc_terms_and_conditions_page_id' ) ? wc_terms_and_conditions_page_id() : wc_get_page_id( 'terms' );
				$page_id = absint( $page_id );

				return $page_id ? (string) get_permalink( $page_id ) : '';

			case 'privacy':
				$page_id = function_exists( 'wc_privacy_policy_page_id' ) ? wc_privacy_policy_page_id() : get_option( 'wp_page_for_privacy_policy' );
				$page_id = absint( $page_id );

				return $page_id ? (string) get_permalink( $page_id ) : '';
		}

		$woocommerce       = function_exists( 'WC' ) ? WC() : false;
		$account_endpoints = $woocommerce && $woocommerce->query ? $woocommerce->query->get_query_vars() : [];

		return isset( $account_endpoints[ $url_key ] ) ? wc_get_account_endpoint_url( $url_key ) : '';
	}

	/**
	 * Get current order from query loop or context.
	 *
	 * @since 2.4
	 */
	public function get_order() {
		$any_loop_id = \Bricks\Query::is_any_looping();
		if (
			$any_loop_id &&
			in_array( \Bricks\Query::get_query_object_type( $any_loop_id ), [ 'wooAccountOrders', 'wooAccountOrderActions', 'wooOrderDownloads', 'wooAccountDownloads' ], true )
		) {
			$loop_object = \Bricks\Query::get_loop_object( $any_loop_id );
			$order       = is_array( $loop_object ) && isset( $loop_object['order'] ) ? $loop_object['order'] : false;

			if ( is_a( $order, 'WC_Order' ) ) {
				return $order;
			}
		}

		// Keep Woo order context resolution in sync with query loops and conditions. (@since 2.4)
		$contextual_checkout_order = class_exists( '\Bricks\Woocommerce' ) ? \Bricks\Woocommerce::get_contextual_order( 'thankyou' ) : false;

		if ( is_a( $contextual_checkout_order, 'WC_Order' ) ) {
			return $contextual_checkout_order;
		}

		$order_id  = 0;
		$order     = false;
		$order_key = false;

		// Order pay
		if ( ! empty( get_query_var( 'order-pay' ) ) ) {
			$order_id  = absint( get_query_var( 'order-pay' ) );
			$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		}

		// Order received
		elseif ( ! empty( get_query_var( 'order-received' ) ) ) {
			$order_id = absint( get_query_var( 'order-received' ) );

			$order_id  = apply_filters( 'woocommerce_thankyou_order_id', $order_id );
			$order_key = apply_filters( 'woocommerce_thankyou_order_key', empty( $_GET['key'] ) ? '' : wc_clean( wp_unslash( $_GET['key'] ) ) );
		}

		// View order (my-account) (@since 1.9.6)
		elseif ( ! empty( get_query_var( 'view-order' ) ) ) {
			if ( class_exists( '\Bricks\Woocommerce' ) ) {
				// The contextual resolver already rejected invalid or unauthorized view-order requests.
				return false;
			}

			$order_id = absint( get_query_var( 'view-order' ) );
		}

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );

			// 'view-order' endpoint already checks the order key, so we don't need to check it again (@since 1.9.6)
			// wc_clean() preserves arrays; reject malformed key[] requests/filter
			// results instead of passing them to hash_equals() and causing a TypeError.
			if ( ! is_wc_endpoint_url( 'view-order' ) && ( ! $order || ! is_string( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) ) {
				$order = false;
			}
		}

		return $order;
	}

	/**
	 * Get current account order action from query loop.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_order_action() {
		$active_loop_id = \Bricks\Query::is_any_looping();

		if ( ! $active_loop_id || \Bricks\Query::get_query_object_type( $active_loop_id ) !== 'wooAccountOrderActions' ) {
			return [];
		}

		$loop_object = \Bricks\Query::get_loop_object( $active_loop_id );

		if ( ! is_array( $loop_object ) || ! isset( $loop_object['action'] ) || ! is_array( $loop_object['action'] ) ) {
			return [];
		}

		return $loop_object['action'];
	}

	/**
	 * Same function as in WooCommerce wc_get_stock_html() but with the last resort calculation for variable products when the stock is managed at the variation level
	 *
	 * @since 1.5.7
	 */
	public function get_stock_html( $product ) {
		$html         = '';
		$availability = $product->get_availability();

		// Get all the product variations and sum up the stocks if needed - stock is managed in the variation level (@since 1.5.7)
		if ( empty( $availability['availability'] ) && $product->is_type( 'variable' ) ) {
			$stock_amount = Woocommerce_Helpers::get_stock_amount( $product );

			$availability['availability'] = Woocommerce_Helpers::format_stock_for_display( $product, $stock_amount );
		}

		if ( ! empty( $availability['availability'] ) ) {
			ob_start();

			wc_get_template(
				'single-product/stock.php',
				[
					'product'      => $product,
					'class'        => $availability['class'],
					'availability' => $availability['availability'],
				]
			);

			$html = ob_get_clean();
		}

		return apply_filters( 'woocommerce_get_stock_html', $html, $product );
	}

	/**
	 * Get the "Add to cart" button html
	 *
	 * @param WP_Product $product
	 * @param array      $filters
	 */
	public function get_add_to_cart_value( $product, $filters, $context ) {
		if ( ! $product ) {
			return '';
		}

		if ( $context == 'link' ) {
			return $product->add_to_cart_url();
		}

		$button_args = [];

		// @see woocommerce_template_loop_add_to_cart()
		$defaults = [
			'quantity'   => 1,
			'class'      => implode(
				' ',
				array_filter(
					[
						'button',
						'product_type_' . $product->get_type(),
						$product->is_purchasable() && $product->is_in_stock() ? 'add_to_cart_button' : '',
						$product->supports( 'ajax_add_to_cart' ) && $product->is_purchasable() && $product->is_in_stock() ? 'ajax_add_to_cart' : '',
					]
				)
			),
			'attributes' => [
				'data-product_id'  => $product->get_id(),
				'data-product_sku' => $product->get_sku(),
				'aria-label'       => $product->add_to_cart_description(),
				'rel'              => 'nofollow',
			],
		];

		$button_args = apply_filters( 'woocommerce_loop_add_to_cart_args', wp_parse_args( $button_args, $defaults ), $product );

		if ( isset( $button_args['attributes']['aria-label'] ) ) {
			$button_args['attributes']['aria-label'] = wp_strip_all_tags( $button_args['attributes']['aria-label'] );
		}

		return apply_filters(
			'woocommerce_loop_add_to_cart_link',
			sprintf(
				'<a href="%s" data-quantity="%s" class="%s" %s>%s</a>',
				esc_url( $product->add_to_cart_url() ),
				esc_attr( isset( $button_args['quantity'] ) ? $button_args['quantity'] : 1 ),
				esc_attr( isset( $button_args['class'] ) ? $button_args['class'] : 'button' ),
				isset( $button_args['attributes'] ) ? wc_implode_html_attributes( $button_args['attributes'] ) : '',
				esc_html( $product->add_to_cart_text() )
			),
			$product,
			$button_args
		);
	}

	/**
	 * {woo_product_images} and {woo_product_gallery_images} are supported in query loops post__in parameter
	 *
	 * @since 2.2
	 */
	public function get_query_supported_tags() {
		$supported_tags = [];

		foreach ( $this->tags as $tag ) {
			if ( in_array( $tag['name'], [ '{woo_product_images}', '{woo_product_gallery_images}' ], true ) ) {
				$supported_tags[] = [
					'name'     => $tag['name'],
					'type'     => 'media',
					'label'    => $tag['label'],
					'params'   => [
						'post__in',
					],
					'provider' => $tag['provider'],
				];
			}
		}

		return $supported_tags;
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/woocommerce-item-data.php';

/**
 * WooCommerce cart item data element.
 *
 * @since 2.4
 */
class Woocommerce_Cart_Item_Data extends Woocommerce_Item_Data {
	public $category = 'woocommerce_cart';
	public $name     = 'woocommerce-cart-item-data';
	public $icon     = 'ti-list';

	/**
	 * Get element label.
	 *
	 * @since 2.4
	 */
	public function get_label() {
		return esc_html__( 'Cart item data', 'bricks' );
	}

	/**
	 * Get searchable keywords.
	 *
	 * @since 2.4
	 */
	public function get_keywords() {
		return [ 'woocommerce', 'cart', 'variation', 'attribute', 'metadata' ];
	}

	/**
	 * Get the supported query loop type.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_item_data_query_type() {
		return 'wooCart';
	}

	/**
	 * Get the internal markup class prefix.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_item_data_class_prefix() {
		return 'brx-cart-item-data';
	}

	/**
	 * Get normalized cart item data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $loop_object Active cart query loop object.
	 * @return array
	 */
	protected function get_item_data( $loop_object ) {
		return is_array( $loop_object ) ? Woocommerce_Helpers::get_cart_item_data( $loop_object ) : [];
	}

	/**
	 * Get the unsupported-loop builder placeholder.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_query_loop_placeholder() {
		return esc_html__( 'Use this element inside a WooCommerce cart contents query loop.', 'bricks' );
	}

	/**
	 * Get the empty-data builder placeholder.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_empty_item_data_placeholder() {
		return esc_html__( 'No cart item data found.', 'bricks' );
	}
}

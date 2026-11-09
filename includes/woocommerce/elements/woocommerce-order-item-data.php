<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/woocommerce-item-data.php';

/**
 * WooCommerce order item data element.
 *
 * This structured element intentionally does not run the legacy complete-output
 * meta hooks. Their arbitrary markup cannot be mapped reliably to the element's
 * label and value controls; {woo_order_item_meta} remains available for that
 * compatibility path.
 *
 * @since 2.4
 */
class Woocommerce_Order_Item_Data extends Woocommerce_Item_Data {
	// Order item loops also run in Pay, Thank you, and receipt states, not only My account.
	public $category = 'woocommerce';
	public $name     = 'woocommerce-order-item-data';
	public $icon     = 'ti-list';

	/**
	 * Get element label.
	 *
	 * @since 2.4
	 */
	public function get_label() {
		return esc_html__( 'Order item data', 'bricks' );
	}

	/**
	 * Get searchable keywords.
	 *
	 * @since 2.4
	 */
	public function get_keywords() {
		return [ 'woocommerce', 'order', 'variation', 'attribute', 'metadata' ];
	}

	/**
	 * Get the supported query loop type.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_item_data_query_type() {
		return 'wooOrderItems';
	}

	/**
	 * Get the internal markup class prefix.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_item_data_class_prefix() {
		return 'brx-order-item-data';
	}

	/**
	 * Get normalized order item data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $loop_object Active order items query loop object.
	 * @return array
	 */
	protected function get_item_data( $loop_object ) {
		$item = is_array( $loop_object ) && isset( $loop_object['item'] ) ? $loop_object['item'] : null;

		return Woocommerce_Helpers::get_order_item_data( $item );
	}

	/**
	 * Get the unsupported-loop builder placeholder.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_query_loop_placeholder() {
		return esc_html__( 'Use this element inside a WooCommerce order items query loop.', 'bricks' );
	}

	/**
	 * Get the empty-data builder placeholder.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	protected function get_empty_item_data_placeholder() {
		return esc_html__( 'No order item data found.', 'bricks' );
	}
}

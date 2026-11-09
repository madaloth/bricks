<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Checkout order summary wrapper for v2 checkout.
 *
 * Nestable wrapper that guarantees the fragment target ID
 * (#bricks-woo-checkout-order-summary) so WooCommerce AJAX
 * order-review updates can replace the content reliably.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Order_Summary extends Woo_Element {
	public $category      = 'woocommerce_checkout';
	public $name          = 'woocommerce-checkout-order-summary';
	public $icon          = 'ti-receipt';
	public $vue_component = 'bricks-nestable';
	public $nestable      = true;

	/**
	 * Only available inside Checkout v2 state-checkout.
	 */
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Checkout order summary', 'bricks' );
	}

	public function get_keywords() {
		return [ 'checkout', 'order', 'summary', 'review', 'woocommerce', 'fragment' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'This wrapper element is required to ensure the order summary content can be replaced by WooCommerce AJAX updates when changes are made to the cart on the checkout page. Do not remove this wrapper if you want to keep AJAX updates working.', 'bricks' ),
		];
	}

	/**
	 * Render wrapper with guaranteed fragment target ID.
	 *
	 * WooCommerce needs the fixed ID to replace the order-review fragment after checkout changes.
	 * Bricks element styles normally target #brxe-{id}, which cannot coexist with that fragment ID.
	 * Keep the unique Bricks class so generated styles and custom CSS retain a stable runtime target.
	 *
	 * (#86cavrwb8)
	 *
	 * @since 2.4
	 */
	public function render() {
		// Assets::uses_element_class_selector() and the builder selector helper both target this class.
		$this->set_attribute( '_root', 'class', "brxe-{$this->uid}" );

		// Force and guarantee the fragment target ID on the wrapper element, so WooCommerce AJAX updates can reliably find and replace the content.
		$this->set_attribute( '_root', 'id', 'bricks-woo-checkout-order-summary' );
		$this->attributes['_root']['id'] = 'bricks-woo-checkout-order-summary';

		echo "<div {$this->render_attributes( '_root' )}>";
		echo Frontend::render_children( $this );
		echo '</div>';
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Checkout billing address wrapper for Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Billing_Address extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-billing-address';
	public $icon            = 'ti-location-pin';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout billing address', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'billing', 'address', 'fields', 'wrapper' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper around checkout billing address fields so WooCommerce address field scripts can target the billing field group reliably.', 'bricks' ),
		];
	}

	/**
	 * Render wrapper with WooCommerce billing field wrapper class.
	 *
	 * @since 2.4
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'woocommerce-billing-fields__field-wrapper' );

		echo "<div {$this->render_attributes( '_root' )}>";
		echo Frontend::render_children( $this );
		echo '</div>';
	}
}

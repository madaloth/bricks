<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Checkout shipping address wrapper for Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Shipping_Address extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-shipping-address';
	public $icon            = 'ti-truck';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout shipping address', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'shipping', 'address', 'fields', 'wrapper' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper around checkout shipping address fields so WooCommerce can show or hide the field group when "Ship to a different address?" changes.', 'bricks' ),
		];
	}

	/**
	 * Render wrapper with WooCommerce shipping address classes.
	 *
	 * @since 2.4
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'shipping_address' );
		$this->set_attribute( '_root', 'class', 'woocommerce-shipping-fields__field-wrapper' );

		echo "<div {$this->render_attributes( '_root' )}>";
		echo Frontend::render_children( $this );
		echo '</div>';
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Internal Checkout v2 state: Pay for order.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_V2_State_Pay extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-v2-state-pay';
	public $icon            = 'ti-credit-card';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Pay', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	public function get_keywords() {
		return [ 'pay', 'order', 'woocommerce', 'state', 'internal' ];
	}

	public function set_controls() {
		parent::set_controls();

		$this->controls['previewOrderId'] = [
			'type'        => 'number',
			'label'       => esc_html__( 'Preview order ID', 'bricks' ),
			'description' => esc_html__( 'Empty value will fallback to the most recent order for preview context. Orders the current user cannot view will not be used.', 'bricks' ),
			'rerender'    => true,
		];

		$this->controls['generatePredefinedElementsSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Insert a structure', 'bricks' ),
		];

		$this->controls['generatePredefinedElements'] = [
			'description' => $this->get_v2_generate_predefined_elements_description(),
			'type'        => 'select',
			'options'     => [
				'complete-pay-block' => esc_html__( 'Complete pay block', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Choose structure', 'bricks' ) . '...',
		];

		$this->controls['generatePredefinedElementsApply'] = [
			'type'               => 'button',
			'label'              => esc_html__( 'Generate', 'bricks' ),
			'action'             => 'generatePredefinedElements',
			'generatorOperation' => 'generate',
			'generatorType'      => 'woocommerce',
			'generatorPage'      => 'checkout',
			'generatorArea'      => 'state-pay',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
		];
	}

	/**
	 * Render state children only.
	 *
	 * @since 2.4
	 */
	public function render() {
		$has_preview_context = false;

		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			$preview_order_id = ! empty( $this->settings['previewOrderId'] ) ? absint( $this->settings['previewOrderId'] ) : 0;
			Woocommerce::push_checkout_v2_state_preview_context( 'pay', [ 'orderId' => $preview_order_id ] );
			$has_preview_context = true;
		}

		try {
			echo Frontend::render_children( $this );
		} finally {
			if ( $has_preview_context ) {
				Woocommerce::pop_checkout_v2_state_preview_context( 'pay' );
			}
		}
	}
}

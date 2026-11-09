<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Checkout_Place_Order extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-place-order';
	public $icon            = 'ti-hand-point-up';
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	public function get_label() {
		return esc_html__( 'Checkout place order', 'bricks' );
	}

	public function set_controls() {
		$this->controls['buttonText'] = [
			'label'       => esc_html__( 'Text', 'bricks' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Place order', 'woocommerce' ),
		];

		$button_controls = $this->generate_standard_controls( 'button', '#place_order', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$this->controls  = array_merge( $this->controls, $button_controls );

		$this->controls['buttonWidth'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Width', 'bricks' ),
			'type'        => 'number',
			'units'       => true,
			'css'         => [
				[
					'property' => 'width',
					'selector' => '#place_order',
				],
			],
			'placeholder' => '100%',
		];

		$this->controls['buttonTypography'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => '#place_order',
				],
			],
		];
	}

	public function render() {
		$is_pay_order = $this->is_pay_order_context();

		if ( $is_pay_order ) {
			$this->render_pay_order_button();
			return;
		}

		Woocommerce_Helpers::maybe_load_cart();
		Woocommerce_Helpers::maybe_init_cart_context();
		Woocommerce_Helpers::maybe_populate_cart_contents();

		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$order_button_text = isset( $this->settings['buttonText'] )
			? $this->render_dynamic_data( $this->settings['buttonText'] )
			: apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) );

		echo "<div {$this->render_attributes( '_root' )}>";

		echo apply_filters(
			'woocommerce_order_button_html',
			'<button type="submit" class="button alt' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>'
		);

		wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' );

		echo '</div>';
	}

	/**
	 * Render the pay-for-order submit controls for the order-pay endpoint.
	 *
	 * @since 2.4
	 */
	private function render_pay_order_button() {
		$order_button_text = isset( $this->settings['buttonText'] )
			? $this->render_dynamic_data( $this->settings['buttonText'] )
			: apply_filters( 'woocommerce_pay_order_button_text', __( 'Pay for order', 'woocommerce' ) );

		echo "<div {$this->render_attributes( '_root' )}>";

		echo '<input type="hidden" name="woocommerce_pay" value="1" />';

		echo apply_filters(
			'woocommerce_pay_order_button_html',
			'<button type="submit" class="button alt' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>'
		);

		wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' );

		echo '</div>';
	}

	/**
	 * Check whether this element is rendering inside the pay-for-order context.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private function is_pay_order_context() {
		if ( Woocommerce::has_checkout_v2_state_preview_context( 'pay' ) ) {
			return true;
		}

		if ( get_query_var( 'order-pay', false ) ) {
			return true;
		}

		if ( Helpers::is_bricks_template( get_the_ID() ) && Templates::get_template_type( get_the_ID() ) === 'wc_form_pay' ) {
			return true;
		}

		$element = $this->element;

		while ( ! empty( $element['parent'] ) ) {
			$parent_id = $element['parent'];
			$parent    = Frontend::$elements[ $parent_id ] ?? false;

			if ( ! is_array( $parent ) ) {
				break;
			}

			if ( ( $parent['name'] ?? '' ) === 'woocommerce-checkout-v2-state-pay' ) {
				return true;
			}

			$element = $parent;
		}

		return false;
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Internal Checkout v2 state: Checkout form.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_V2_State_Checkout extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-v2-state-checkout';
	public $icon            = 'ti-layout';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Checkout', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	public function get_description() {
		return esc_html__( 'Placeholder element for the content of this state, template, or endpoint. No wrapper renders on the frontend.', 'bricks' );
	}

	public function get_keywords() {
		return [ 'checkout', 'woocommerce', 'state', 'internal' ];
	}

	public function set_control_groups() {
		$this->set_woo_checkout_form_field_common_style_control_groups();
	}

	public function set_controls() {
		parent::set_controls();

		$checkout_fields = bricks_is_builder() && function_exists( 'WC' ) && WC() && WC()->checkout() ? WC()->checkout()->get_checkout_fields() : [];

		$billing_fields = [];
		if ( ! empty( $checkout_fields['billing'] ) && is_array( $checkout_fields['billing'] ) ) {
			foreach ( $checkout_fields['billing'] as $key => $field ) {
				if ( isset( $field['label'] ) ) {
					$billing_fields[ $key ] = $field['label'];
				}
			}
		}

		$shipping_fields = [];
		if ( ! empty( $checkout_fields['shipping'] ) && is_array( $checkout_fields['shipping'] ) ) {
			foreach ( $checkout_fields['shipping'] as $key => $field ) {
				if ( isset( $field['label'] ) ) {
					$shipping_fields[ $key ] = $field['label'];
				}
			}
		}

		$this->controls['removeFieldsSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Remove unwanted fields', 'bricks' ),
			'step'  => 1,
		];

		$this->controls['removeBillingFields'] = [
			'label'    => esc_html__( 'Billing', 'bricks' ),
			'type'     => 'select',
			'options'  => $billing_fields,
			'multiple' => true,
		];

		$this->controls['removeShippingFields'] = [
			'label'    => esc_html__( 'Shipping', 'bricks' ),
			'type'     => 'select',
			'options'  => $shipping_fields,
			'multiple' => true,
		];

		$this->controls['generatePredefinedElementsSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Insert a structure', 'bricks' ),
			'step'  => 2,
		];

		$this->controls['generatePredefinedElements'] = [
			'description' => esc_html__( 'Appended to the current structure - never overwrites.', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'complete-checkout-block'  => esc_html__( 'Complete checkout', 'bricks' ),
				'checkout-fields-billing'  => esc_html__( 'Billing fields', 'bricks' ),
				'checkout-fields-shipping' => esc_html__( 'Shipping fields', 'bricks' ),
				'payment-options'          => esc_html__( 'Payment options', 'bricks' ),
				'additional-fields'        => esc_html__( 'Additional fields', 'bricks' ),
				'order-summary'            => esc_html__( 'Order summary', 'bricks' ),
				'place-order'              => esc_html__( 'Place order', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Choose structure', 'bricks' ) . '...',
		];

		$this->controls['generatePredefinedElementsMultistep'] = [
			'label'       => esc_html__( 'Generate as multistep', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Generate a ready-made multistep checkout structure with step wrappers and navigation.', 'bricks' ),
			'required'    => [ 'generatePredefinedElements', '=', 'complete-checkout-block' ],
		];

		$this->controls['generatePredefinedElementsApply'] = [
			'type'               => 'button',
			'label'              => esc_html__( 'Generate', 'bricks' ),
			'action'             => 'generatePredefinedElements',
			'generatorOperation' => 'generate',
			'generatorType'      => 'woocommerce',
			'generatorPage'      => 'checkout',
			'generatorArea'      => 'state-checkout',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
			'targetElement'      => 'woocommerce-form-field',
		];

		$this->controls['checkoutFieldSyncSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Sync checkout fields', 'bricks' ),
			'step'  => 3,
		];

		$this->controls['checkCheckoutFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Check checkout fields', 'bricks' ),
			'description'   => esc_html__( 'Check the current WooCommerce field registry for missing, invalid, or duplicate fields.', 'bricks' ),
			'action'        => 'checkCheckoutFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'checkout',
			'generatorArea' => 'state-checkout',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['generateMissingCheckoutFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Generate missing fields', 'bricks' ),
			'action'        => 'generateMissingCheckoutFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'checkout',
			'generatorArea' => 'state-checkout',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['removeInvalidCheckoutFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Remove invalid fields', 'bricks' ),
			'action'        => 'removeInvalidCheckoutFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'checkout',
			'generatorArea' => 'state-checkout',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['removeDuplicateCheckoutFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Remove duplicate fields', 'bricks' ),
			'action'        => 'removeDuplicateCheckoutFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'checkout',
			'generatorArea' => 'state-checkout',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['styleSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Style', 'bricks' ),
			'step'  => 4,
		];

		$this->controls['floatingLabelStyle'] = [
			'label'       => esc_html__( 'Floating label', 'bricks' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Keep WooCommerce form-field labels visible for this to work properly. Fields added by third-party plugins may use different markup and might not support this style.', 'bricks' ),
		];

		$this->controls = array_merge(
			$this->controls,
			$this->get_woo_checkout_form_field_common_style_controls(
				[
					'field_key'          => 'commonFieldStyles',
					'group'              => 'commonFieldStyles',
					'scope'              => '.brxe-woocommerce-form-field', // Keep common styles easy to override.
					'absolute_selectors' => true,
					'group_by_separator' => true,
				]
			)
		);
	}

	/**
	 * Render state children only.
	 *
	 * @since 2.4
	 */
	public function render() {
		echo Frontend::render_children( $this );
	}
}

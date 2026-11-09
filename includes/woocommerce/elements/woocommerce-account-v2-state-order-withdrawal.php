<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Public withdrawal content state for Account page v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_V2_State_Order_Withdrawal extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-v2-state-order-withdrawal';
	public $icon            = 'ti-receipt';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	/**
	 * Get the internal state label.
	 *
	 * @return string
	 *
	 * @since 2.4
	 */
	public function get_label() {
		return esc_html__( 'Order withdrawal', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	/**
	 * Share field styling controls with the other account form states.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_control_groups() {
		$this->set_woo_checkout_form_field_common_style_control_groups();
	}

	/**
	 * Offer withdrawal structures through the shared Woo generator.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_controls() {
		parent::set_controls();

		$this->controls['generatePredefinedElementsSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Insert a structure', 'bricks' ),
		];

		$this->controls['generatePredefinedElements'] = [
			'description' => $this->get_v2_generate_predefined_elements_description(),
			'type'        => 'select',
			'options'     => [
				'complete-order-withdrawal-block' => esc_html__( 'Complete order withdrawal block', 'bricks' ),
			],
			'placeholder' => esc_html__( 'Choose structure', 'bricks' ) . '...',
		];

		$this->controls['generatePredefinedElementsApply'] = [
			'type'               => 'button',
			'label'              => esc_html__( 'Generate', 'bricks' ),
			'action'             => 'generatePredefinedElements',
			'generatorOperation' => 'generate',
			'generatorType'      => 'woocommerce',
			'generatorPage'      => 'myaccount',
			'generatorArea'      => 'state-order-withdrawal',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
		];

		$this->controls['styleSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Styles', 'bricks' ),
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
					'scope'              => '.brxe-woocommerce-form-field',
					'absolute_selectors' => true,
					'group_by_separator' => true,
				]
			)
		);
	}

	/**
	 * Render the configured state content while withdrawal is available.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Woocommerce::use_advanced_modular_elements() || ! Woocommerce::is_order_withdrawal_enabled() ) {
			return;
		}

		echo Frontend::render_children( $this );
	}
}

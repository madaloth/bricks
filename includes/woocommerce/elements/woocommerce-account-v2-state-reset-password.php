<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_V2_State_Reset_Password extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-v2-state-reset-password';
	public $icon            = 'ti-key';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Reset password', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	public function get_keywords() {
		return [ 'account', 'reset password', 'woocommerce', 'state', 'internal' ];
	}

	public function set_control_groups() {
		$this->set_woo_checkout_form_field_common_style_control_groups();
	}

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
				'complete-reset-password-block' => esc_html__( 'Complete reset password block', 'bricks' ),
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
			'generatorArea'      => 'state-reset-password',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
			'targetElement'      => 'woocommerce-form-field',
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

	public function render() {
		echo Frontend::render_children( $this );
	}
}

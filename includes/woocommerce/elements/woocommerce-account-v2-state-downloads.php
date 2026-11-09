<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_V2_State_Downloads extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-v2-state-downloads';
	public $icon            = 'ti-download';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Downloads', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	public function get_keywords() {
		return [ 'account', 'downloads', 'woocommerce', 'state', 'internal' ];
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
				'complete-downloads-block' => esc_html__( 'Complete downloads block', 'bricks' ),
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
			'generatorArea'      => 'state-downloads',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
		];
	}

	public function render() {
		echo Frontend::render_children( $this );
	}
}

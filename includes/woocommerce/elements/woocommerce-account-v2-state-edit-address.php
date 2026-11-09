<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_V2_State_Edit_Address extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-v2-state-edit-address';
	public $icon            = 'ti-pencil';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Edit address', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')';
	}

	public function get_keywords() {
		return [ 'account', 'edit address', 'woocommerce', 'state', 'internal' ];
	}

	public function set_control_groups() {
		$this->set_woo_checkout_form_field_common_style_control_groups();
	}

	public function set_controls() {
		parent::set_controls();

		$this->controls['previewAddressType'] = [
			'label'       => esc_html__( 'Preview address type', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'billing'  => esc_html__( 'Billing address', 'woocommerce' ),
				'shipping' => esc_html__( 'Shipping address', 'woocommerce' ),
			],
			'placeholder' => esc_html__( 'Billing address', 'woocommerce' ),
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
				'complete-edit-address-block' => esc_html__( 'Complete edit address block', 'bricks' ),
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
			'generatorArea'      => 'state-edit-address',
			'sourceControl'      => 'generatePredefinedElements',
			'required'           => [ 'generatePredefinedElements', '!=', '' ],
			'targetElement'      => 'woocommerce-form-field',
		];

		$this->controls['accountEditAddressFieldSyncSeparator'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Sync address fields', 'bricks' ),
		];

		$this->controls['accountEditAddressFieldSyncGuide'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Check account address fields against the current WooCommerce billing and shipping address setup. Missing fields can be generated directly into this Edit address state. Invalid fields can be removed safely.', 'bricks' ),
		];

		$this->controls['checkAccountEditAddressFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Check address fields', 'bricks' ),
			'action'        => 'checkAccountEditAddressFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'myaccount',
			'generatorArea' => 'state-edit-address',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['generateMissingAccountEditAddressFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Generate missing fields', 'bricks' ),
			'action'        => 'generateMissingAccountEditAddressFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'myaccount',
			'generatorArea' => 'state-edit-address',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['removeInvalidAccountEditAddressFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Remove invalid fields', 'bricks' ),
			'action'        => 'removeInvalidAccountEditAddressFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'myaccount',
			'generatorArea' => 'state-edit-address',
			'targetElement' => 'woocommerce-form-field',
		];

		$this->controls['removeDuplicateAccountEditAddressFields'] = [
			'type'          => 'button',
			'label'         => esc_html__( 'Remove duplicate fields', 'bricks' ),
			'action'        => 'removeDuplicateAccountEditAddressFields',
			'generatorType' => 'woocommerce',
			'generatorPage' => 'myaccount',
			'generatorArea' => 'state-edit-address',
			'targetElement' => 'woocommerce-form-field',
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
					'field_key'               => 'commonFieldStyles',
					'group'                   => 'commonFieldStyles',
					'scope'                   => '.brxe-woocommerce-form-field',
					'absolute_selectors'      => true,
					'select2_required_values' => [ 'billing_country', 'shipping_country', 'billing_state', 'shipping_state', 'country', 'state' ],
					'group_by_separator'      => true,
				]
			)
		);
	}

	public function render() {
		$has_preview_context = false;

		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			// Frontend address type comes from the endpoint; this context exists only so builder children can preview billing/shipping.
			$preview_address_type = ! empty( $this->settings['previewAddressType'] ) ? sanitize_key( $this->settings['previewAddressType'] ) : 'billing';
			Woocommerce::push_account_v2_state_preview_context( 'edit-address', [ 'addressType' => $preview_address_type ] );
			$has_preview_context = true;
		}

		try {
			echo Frontend::render_children( $this );
		} finally {
			if ( $has_preview_context ) {
				Woocommerce::pop_account_v2_state_preview_context( 'edit-address' );
			}
		}
	}
}

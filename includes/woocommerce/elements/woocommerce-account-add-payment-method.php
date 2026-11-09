<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_Add_Payment_Method extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-add-payment-method';
	public $icon            = 'ti-plus';
	public $panel_condition = [
		[ 'templateType', '=', 'wc_account_add_payment_method' ],
		[ 'wooPage', '=', 'myaccount' ]
	];

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Add payment method', 'bricks' );
	}

	public function set_controls() {
		// WRAPPER
		$this->controls['wrapperSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Wrapper', 'bricks' ),
		];

		$wrapper_controls = $this->generate_standard_controls( 'wrapper', 'ul.woocommerce-PaymentMethods' );
		$this->controls   = array_merge( $this->controls, $wrapper_controls );

		// PAYMENT METHODS LIST
		$this->controls['listSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Payment Methods List', 'bricks' ) . ' (ul)',
		];

		$list_controls  = $this->generate_standard_controls( 'list', 'ul.woocommerce-PaymentMethods' );
		$this->controls = array_merge( $this->controls, $list_controls );

		// PAYMENT METHOD ITEM
		$this->controls['itemSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Payment Method Item', 'bricks' ) . ' (li)',
		];

		$item_controls  = $this->generate_standard_controls( 'item', 'ul.woocommerce-PaymentMethods li.woocommerce-PaymentMethod' );
		$this->controls = array_merge( $this->controls, $item_controls );

		// PAYMENT METHOD LABEL
		$this->controls['labelSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Label', 'bricks' ),
		];

		$label_controls = $this->generate_standard_controls( 'label', 'ul.woocommerce-PaymentMethods li.woocommerce-PaymentMethod label' );
		$this->controls = array_merge( $this->controls, $label_controls );

		// CUSTOM RADIO (CHECKED)
		$this->controls['radioCheckedSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Custom Radio (Checked)', 'bricks' ),
		];

		$this->controls['radioCheckedColor'] = [
			'label' => esc_html__( 'Color', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'property' => 'background-color',
					'selector' => 'ul.woocommerce-PaymentMethods .woocommerce-PaymentMethod input.input-radio:checked + label::before',
				],
				[
					'property' => 'border-color',
					'selector' => 'ul.woocommerce-PaymentMethods .woocommerce-PaymentMethod input.input-radio:checked + label::before',
				],
			],
		];

		// PAYMENT BOX (DESCRIPTION/FIELDS)
		$this->controls['paymentBoxSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Payment Box', 'bricks' ),
		];

		$payment_box_controls = $this->generate_standard_controls( 'paymentBox', 'ul.woocommerce-PaymentMethods .woocommerce-PaymentBox' );
		$this->controls       = array_merge( $this->controls, $payment_box_controls );

		// BUTTON
		$this->controls['buttonSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Add Payment Method Button', 'bricks' ),
		];

		$button_controls = $this->generate_standard_controls( 'button', '.woocommerce-Payment .form-row .woocommerce-Button' );
		$this->controls  = array_merge( $this->controls, $button_controls );
	}

	public function render() {
		// Get woo template
		ob_start();

		wc_get_template( 'myaccount/form-add-payment-method.php' );

		$woo_template = ob_get_clean();

		// Render Woo template
		echo "<div {$this->render_attributes( '_root' )}>{$woo_template}</div>";
	}
}

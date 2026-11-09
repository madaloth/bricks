<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Woocommerce_Account_Payment_Methods extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-payment-methods';
	public $icon            = 'ti-credit-card';
	public $panel_condition = [ [ 'templateType', '=', 'wc_account_payment_methods' ], [ 'wooPage', '=', 'myaccount' ] ];

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Payment methods', 'bricks' );
	}

	public function set_control_groups() {
		$this->control_groups['table'] = [
			'title' => esc_html__( 'Table', 'bricks' ),
		];

		$this->control_groups['button'] = [
			'title' => esc_html__( 'Button', 'bricks' ),
		];
	}

	public function set_controls() {
		// TABLE
		$table_controls = $this->generate_standard_controls( 'table', '.woocommerce-MyAccount-paymentMethods' );
		// unset( $table_controls['tableMargin'] );
		unset( $table_controls['tablePadding'] );

		$table_controls = $this->controls_grouping( $table_controls, 'table' );
		$this->controls = array_merge( $this->controls, $table_controls );

		// HEAD
		$this->controls['theadSep'] = [
			'group' => 'table',
			'type'  => 'separator',
			'label' => esc_html__( 'Head', 'bricks' ),
		];

		$thead_controls = $this->generate_standard_controls( 'thead', '.woocommerce-MyAccount-paymentMethods thead th' );
		unset( $thead_controls['theadMargin'] );
		unset( $thead_controls['theadBoxShadow'] );

		$thead_controls = $this->controls_grouping( $thead_controls, 'table' );
		$this->controls = array_merge( $this->controls, $thead_controls );

		// BODY
		$this->controls['tbodySep'] = [
			'group' => 'table',
			'type'  => 'separator',
			'label' => esc_html__( 'Body', 'bricks' ),
		];

		$tbody_controls = $this->generate_standard_controls( 'tbody', '.woocommerce-MyAccount-paymentMethods tbody td' );
		unset( $tbody_controls['tbodyMargin'] );
		unset( $tbody_controls['tbodyBoxShadow'] );

		$tbody_controls = $this->controls_grouping( $tbody_controls, 'table' );
		$this->controls = array_merge( $this->controls, $tbody_controls );

		// DELETE BUTTON (In each row)
		$this->controls['deleteButtonSep'] = [
			'group' => 'button',
			'type'  => 'separator',
			'label' => esc_html__( 'Delete button', 'bricks' ),
		];

		$delete_button_controls = $this->generate_standard_controls( 'deleteButton', '.woocommerce-MyAccount-paymentMethods tbody td .button.delete' );
		unset( $delete_button_controls['deleteButtonMargin'] );

		$delete_button_controls = $this->controls_grouping( $delete_button_controls, 'button' );
		$this->controls         = array_merge( $this->controls, $delete_button_controls );

		// MAKE DEFAULT BUTTON (In each row)
		$this->controls['makeDefaultButtonSep'] = [
			'group' => 'button',
			'type'  => 'separator',
			'label' => esc_html__( 'Make default button', 'bricks' ),
		];

		$make_default_button_controls = $this->generate_standard_controls( 'makeDefaultButton', '.woocommerce-MyAccount-paymentMethods tbody td .button.default' );
		unset( $make_default_button_controls['makeDefaultButtonMargin'] );

		$make_default_button_controls = $this->controls_grouping( $make_default_button_controls, 'button' );
		$this->controls               = array_merge( $this->controls, $make_default_button_controls );

		// ADD PAYMENT METHOD BUTTON (Below the table)
		$this->controls['addButtonSep'] = [
			'group' => 'button',
			'type'  => 'separator',
			'label' => esc_html__( 'Add payment method', 'bricks' ),
		];

		$add_button_controls = $this->generate_standard_controls( 'addButton', '> a.button' );
		$add_button_controls = $this->controls_grouping( $add_button_controls, 'button' );

		$this->controls = array_merge( $this->controls, $add_button_controls );
	}

	public function render() {
		// Get woo template
		ob_start();

		wc_get_template( 'myaccount/payment-methods.php' );

		$woo_template = ob_get_clean();
		// Render Woo template
		echo "<div {$this->render_attributes( '_root' )}>{$woo_template}</div>";
	}

}

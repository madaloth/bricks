<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Native POST wrapper for withdrawal entry and review actions.
 *
 * @since 2.4
 */
class Woocommerce_Account_Order_Withdrawal_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-order-withdrawal-form';
	public $icon            = 'ti-receipt';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'wooPage', '=', 'myaccount' ];

	/**
	 * Get the element label.
	 *
	 * @return string
	 *
	 * @since 2.4
	 */
	public function get_label() {
		return esc_html__( 'Account order withdrawal form', 'bricks' );
	}

	/**
	 * Explain the wrapper's role in the native withdrawal flow.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 order withdrawal state for the details form and review actions. It supplies the security fields and submitted details required by WooCommerce. The confirmation screen does not need a form wrapper.', 'bricks' ),
		];
	}

	/**
	 * Render native security/action data around editable children.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function render() {
		$context = Woocommerce_Order_Withdrawal::get_context();
		if ( ! $context || ( ! ( bricks_is_builder() || bricks_is_builder_call() ) && ! in_array( $context['screen'] ?? '', [ 'form', 'review' ], true ) ) ) {
			return;
		}

		$preview = bricks_is_builder() || bricks_is_builder_call();
		$this->set_attribute( '_root', 'class', 'woocommerce-OrderWithdrawalForm' );
		if ( ! $preview ) {
			$this->set_attribute( '_root', 'method', 'post' );
			$this->set_attribute( '_root', 'novalidate', '' );
			$this->set_attribute( '_root', 'action', esc_url( $context['form_action_url'] ?? '' ) );
		}

		// The preview deliberately has no form, nonce, or native action controls.
		$tag = $preview ? 'div' : 'form';
		echo "<{$tag} {$this->render_attributes( '_root' )}>";

		if ( ! $preview ) {
			wp_nonce_field( $context['nonce_action'], $context['nonce_field'] );
			if ( $context['screen'] === 'review' ) {
				foreach ( $context['hidden_fields'] ?? [] as $field ) {
					echo '<input type="hidden" name="' . esc_attr( $field['name'] ?? '' ) . '" value="' . esc_attr( $field['value'] ?? '' ) . '">';
				}
			}
		}

		echo Frontend::render_children( $this );
		echo "</{$tag}>";
	}
}

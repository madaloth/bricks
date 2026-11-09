<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Checkout account fields wrapper for Checkout v2.
 *
 * Mirrors the account-creation lifecycle from WooCommerce's checkout billing
 * template while keeping the credential fields editable as Bricks elements.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_Account_Fields extends Woo_Element {
	public $category        = 'woocommerce_checkout';
	public $name            = 'woocommerce-checkout-account-fields';
	public $icon            = 'ti-user';
	public $vue_component   = 'bricks-nestable';
	public $nestable        = true;
	public $panel_condition = [ [ 'wooPage', '=', 'checkout' ] ];

	/**
	 * Get the element label.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Checkout account fields', 'bricks' );
	}

	/**
	 * Get element search keywords.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'account', 'registration', 'fields', 'wrapper' ];
	}

	/**
	 * Set element controls.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Contains the optional Create account checkbox and checkout account credential fields.', 'bricks' ),
		];
	}

	/**
	 * Render one child element.
	 *
	 * @since 2.4
	 *
	 * @param string $child_id Child element ID.
	 *
	 * @return void
	 */
	private function render_child( $child_id ) {
		$child = Frontend::$elements[ $child_id ] ?? false;

		if ( $child ) {
			echo Frontend::render_element( $child ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Check whether a child is the generated Create account checkbox.
	 *
	 * @since 2.4
	 *
	 * @param array $child Child element data.
	 *
	 * @return bool
	 */
	private function is_create_account_checkbox( $child ) {
		return is_array( $child ) &&
			( $child['name'] ?? '' ) === 'form-checkbox' &&
			( $child['settings']['wooFields'] ?? '' ) === 'createaccount';
	}

	/**
	 * Render the WooCommerce checkout registration lifecycle.
	 *
	 * Field generation and the Checkout field audit own which account credentials
	 * belong in the structure. This wrapper owns only their runtime lifecycle:
	 * guest visibility, the optional checkbox, and registration hooks.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'woocommerce-account-fields' );

		$is_builder = bricks_is_builder() || bricks_is_builder_call() || bricks_is_builder_iframe();

		// Always expose the saved structure in the builder so users can edit fields
		// that may be inactive under the current preview settings. (#86cb33dre; @since 2.4)
		if ( $is_builder ) {
			echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		Woocommerce_Helpers::maybe_load_cart();
		Woocommerce_Helpers::maybe_init_cart_context();

		$checkout = function_exists( 'WC' ) && WC() ? WC()->checkout() : false;

		if ( ! is_a( $checkout, 'WC_Checkout' ) || ! $checkout->is_registration_enabled() ) {
			return;
		}

		$checkbox_children = [];
		$field_children    = [];
		$children          = ! empty( $this->element['children'] ) && is_array( $this->element['children'] ) ? $this->element['children'] : [];

		foreach ( $children as $child_id ) {
			$child = Frontend::$elements[ $child_id ] ?? false;

			if ( $this->is_create_account_checkbox( $child ) ) {
				$checkbox_children[] = $child_id;
			} else {
				$field_children[] = $child_id;
			}
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $checkout->is_registration_required() ) {
			foreach ( $checkbox_children as $child_id ) {
				$this->render_child( $child_id );
			}
		}

		do_action( 'woocommerce_before_checkout_registration_form', $checkout );

		if ( ! empty( $field_children ) ) {
			echo '<div class="create-account">';

			foreach ( $field_children as $child_id ) {
				$this->render_child( $child_id );
			}

			echo '<div class="clear"></div>';
			echo '</div>';
		}

		do_action( 'woocommerce_after_checkout_registration_form', $checkout );

		echo '</div>';
	}
}

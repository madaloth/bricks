<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Shared WooCommerce form submit controls for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Form_Submit extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-form-submit';
	public $icon            = 'ti-save';
	public $panel_condition = [ 'wooPage', '=', 'myaccount' ];

	public function get_label() {
		return esc_html__( 'WooCommerce form submit', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'submit', 'button', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Only use this element inside the correct form type. For example, "Edit account" form type for the "Edit account form". Use the "Generate predefined elements" control to automatically generate the correct submit button for each form type.', 'bricks' ),
		];

		$this->controls['formType'] = [
			'label'       => esc_html__( 'Form type', 'bricks' ),
			'type'        => 'select',
			'options'     => [
				'login'             => esc_html__( 'Login', 'bricks' ),
				'register'          => esc_html__( 'Register', 'bricks' ),
				'lostPassword'      => esc_html__( 'Lost password', 'bricks' ),
				'resetPassword'     => esc_html__( 'Reset password', 'bricks' ),
				'editAccount'       => esc_html__( 'Edit account', 'bricks' ),
				'editAddress'       => esc_html__( 'Edit address', 'bricks' ),
				'withdrawalReview'  => esc_html__( 'Withdrawal: Continue to review', 'bricks' ),
				'withdrawalConfirm' => esc_html__( 'Withdrawal: Confirm', 'bricks' ),
				'withdrawalEdit'    => esc_html__( 'Withdrawal: Edit details', 'bricks' ),
			],
			'inline'      => true,
			'placeholder' => esc_html__( 'Login', 'bricks' ),
			'rerender'    => true,
		];

		if ( ! Woocommerce::is_order_withdrawal_enabled() ) {
			foreach ( [ 'withdrawalReview', 'withdrawalConfirm', 'withdrawalEdit' ] as $type ) {
				unset( $this->controls['formType']['options'][ $type ] );
			}
		}

		$this->controls['buttonText'] = [
			'label' => esc_html__( 'Text', 'bricks' ),
			'type'  => 'text',
		];

		// Builder withdrawal previews use type=button so editing cannot submit the native flow.
		$button_controls = $this->generate_standard_controls( 'button', 'button', [ 'margin', 'padding', 'background-color', 'border', 'box-shadow' ] );
		$this->controls  = array_merge( $this->controls, $button_controls );

		$this->controls['buttonTypography'] = [
			'tab'   => 'content',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'property' => 'font',
					'selector' => 'button',
				],
			],
		];
	}

	/**
	 * Render the WooCommerce submit controls for the selected form type.
	 *
	 * @since 2.4
	 */
	public function render() {
		$form_type   = $this->get_form_type();
		$button_text = $this->get_button_text( $form_type );

		$withdrawal_actions = [
			'withdrawalReview'  => 'review',
			'withdrawalConfirm' => 'confirm',
			'withdrawalEdit'    => 'edit',
		];
		if ( isset( $withdrawal_actions[ $form_type ] ) ) {
			$context = Woocommerce_Order_Withdrawal::get_context();
			$action  = $withdrawal_actions[ $form_type ];
			$screen  = $action === 'review' ? 'form' : 'review';
			if ( ! $context || ( ! ( bricks_is_builder() || bricks_is_builder_call() ) && ( $context['screen'] ?? '' ) !== $screen ) ) {
				return;
			}
			echo '<div ' . $this->render_attributes( '_root' ) . '>';
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				echo '<button type="button" class="button">' . esc_html( $button_text ) . '</button>';
			} else {
				echo '<button type="submit" class="woocommerce-Button button" name="' . esc_attr( $context['action_field'] ) . '" value="' . esc_attr( $context[ 'action_' . $action ] ) . '">' . esc_html( $button_text ) . '</button>';
			}
			echo '</div>';
			return;
		}

		$this->set_attribute( '_root', 'class', 'woocommerce-form-row' );
		$this->set_attribute( '_root', 'class', 'form-row' );

		echo "<p {$this->render_attributes( '_root' )}>";

		switch ( $form_type ) {
			case 'register':
				$this->render_register_submit( $button_text );
				break;

			case 'lostPassword':
				$this->render_lost_password_submit( $button_text );
				break;

			case 'resetPassword':
				$this->render_reset_password_submit( $button_text );
				break;

			case 'editAccount':
				$this->render_edit_account_submit( $button_text );
				break;

			case 'editAddress':
				$this->render_edit_address_submit( $button_text );
				break;

			case 'login':
			default:
				$this->render_login_submit( $button_text );
				break;
		}

		echo '</p>';
	}

	/**
	 * Get the selected form type.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private function get_form_type() {
		$form_type  = $this->settings['formType'] ?? 'login';
		$form_types = [ 'withdrawalReview', 'withdrawalConfirm', 'withdrawalEdit', 'login', 'register', 'lostPassword', 'resetPassword', 'editAccount', 'editAddress' ];
		$form_type  = in_array( $form_type, $form_types, true ) ? $form_type : 'login';

		return $form_type;
	}

	/**
	 * Get the submit button text.
	 *
	 * @since 2.4
	 *
	 * @param string $form_type Form type.
	 * @return string
	 */
	private function get_button_text( $form_type ) {
		if ( isset( $this->settings['buttonText'] ) && $this->settings['buttonText'] !== '' ) {
			return $this->render_dynamic_data( $this->settings['buttonText'] );
		}

		$labels = [
			'withdrawalReview'  => esc_html__( 'Continue to review', 'bricks' ),
			'withdrawalConfirm' => esc_html__( 'Confirm withdrawal', 'bricks' ),
			'withdrawalEdit'    => esc_html__( 'Edit details', 'bricks' ),
			'login'             => esc_html__( 'Log in', 'woocommerce' ),
			'register'          => esc_html__( 'Register', 'woocommerce' ),
			'lostPassword'      => esc_html__( 'Reset password', 'woocommerce' ),
			'resetPassword'     => esc_html__( 'Save', 'woocommerce' ),
			'editAccount'       => esc_html__( 'Save changes', 'woocommerce' ),
			'editAddress'       => esc_html__( 'Save address', 'woocommerce' ),
		];

		return $labels[ $form_type ] ?? $labels['login'];
	}

	/**
	 * Get the active WordPress button class provided by WooCommerce.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private function get_wp_button_class() {
		$wp_button_class = wc_wp_theme_get_element_class_name( 'button' );

		return $wp_button_class ? ' ' . $wp_button_class : '';
	}

	/**
	 * Render login submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_login_submit( $button_text ) {
		wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' );
		echo '<button type="submit" class="woocommerce-button button woocommerce-form-login__submit' . esc_attr( $this->get_wp_button_class() ) . '" name="login" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
	}

	/**
	 * Render register submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_register_submit( $button_text ) {
		wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' );
		echo '<button type="submit" class="woocommerce-Button woocommerce-button button' . esc_attr( $this->get_wp_button_class() ) . ' woocommerce-form-register__submit" name="register" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
	}

	/**
	 * Render lost password submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_lost_password_submit( $button_text ) {
		echo '<input type="hidden" name="wc_reset_password" value="true" />';
		echo '<button type="submit" class="woocommerce-Button button' . esc_attr( $this->get_wp_button_class() ) . '" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
		wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' );
	}

	/**
	 * Render reset password submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_reset_password_submit( $button_text ) {
		$args = Woocommerce::get_reset_password_args();

		echo '<input type="hidden" name="reset_key" value="' . esc_attr( $args['key'] ?? '' ) . '" />';
		echo '<input type="hidden" name="reset_login" value="' . esc_attr( $args['login'] ?? '' ) . '" />';
		echo '<input type="hidden" name="wc_reset_password" value="true" />';
		echo '<button type="submit" class="woocommerce-Button button' . esc_attr( $this->get_wp_button_class() ) . '" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
		wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' );
	}

	/**
	 * Render edit account submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_edit_account_submit( $button_text ) {
		// These names are Woo's save-handler contract; changing them would break native edit-account submission.
		wp_nonce_field( 'save_account_details', 'save-account-details-nonce' );
		echo '<button type="submit" class="woocommerce-Button button' . esc_attr( $this->get_wp_button_class() ) . '" name="save_account_details" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
		echo '<input type="hidden" name="action" value="save_account_details" />';
	}

	/**
	 * Render edit address submit controls.
	 *
	 * @since 2.4
	 *
	 * @param string $button_text Button text.
	 * @return void
	 */
	private function render_edit_address_submit( $button_text ) {
		// These names are Woo's save-handler contract; changing them would break native edit-address submission.
		echo '<button type="submit" class="button' . esc_attr( $this->get_wp_button_class() ) . '" name="save_address" value="' . esc_attr( $button_text ) . '">' . esc_html( $button_text ) . '</button>';
		wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' );
		echo '<input type="hidden" name="action" value="edit_address" />';
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Account reset password form wrapper for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Reset_Password_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-reset-password-form';
	public $icon            = 'ti-key';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Account reset password form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'reset password', 'password', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 reset password state so WooCommerce can save the new password.', 'bricks' ),
		];
	}

	/**
	 * Enqueue Woo scripts required by reset password submissions and password fields.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'woocommerce' );
		wp_enqueue_script( 'wc-password-strength-meter' );
		wp_enqueue_script( 'wc-lost-password' );
	}

	/**
	 * Render the account reset password form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'woocommerce-ResetPassword' );
		$this->set_attribute( '_root', 'class', 'lost_reset_password' );
		$this->set_attribute( '_root', 'method', 'post' );

		echo "<form {$this->render_attributes( '_root' )}>";
		echo Frontend::render_children( $this );
		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-reset-password-form">
			<form class="brxe-woocommerce-account-reset-password-form woocommerce-ResetPassword lost_reset_password" method="post">
				<bricks-element-children
					:element="element"
					:parentComponent="component || parentComponent"
					:instanceId="instanceId"
					:loopId="loopId" />
			</form>
		</script>
		<?php
	}
}

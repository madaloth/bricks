<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce login form wrapper for Account v2 and Checkout v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Login_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-login-form';
	public $icon            = 'ti-lock';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'WooCommerce login form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'checkout', 'login', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 login state or Checkout v2 login-required state so WooCommerce can process login submissions.', 'bricks' ),
		];
	}

	/**
	 * Enqueue Woo scripts required by password visibility controls.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'woocommerce' );
	}

	/**
	 * Render the account login form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'woocommerce-form' );
		$this->set_attribute( '_root', 'class', 'woocommerce-form-login' );
		$this->set_attribute( '_root', 'class', 'login' );
		$this->set_attribute( '_root', 'method', 'post' );
		$this->set_attribute( '_root', 'novalidate', 'novalidate' );

		if ( Woocommerce::get_checkout_v2_login_form_arg( 'hidden', false ) ) {
			$this->set_attribute( '_root', 'style', 'display:none;' );
		}

		$message  = Woocommerce::get_checkout_v2_login_form_arg( 'message', '' );
		$redirect = Woocommerce::get_checkout_v2_login_form_arg( 'redirect', '' );

		echo "<form {$this->render_attributes( '_root' )}>";

		if ( $message ) {
			echo wp_kses_post( wpautop( wptexturize( $message ) ) );
		}

		echo Frontend::render_children( $this );

		if ( $redirect ) {
			echo '<input type="hidden" name="redirect" value="' . esc_url( $redirect ) . '" />';
		}

		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-login-form">
			<form class="brxe-woocommerce-account-login-form woocommerce-form woocommerce-form-login login" method="post" novalidate>
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

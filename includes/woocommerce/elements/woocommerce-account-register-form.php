<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Account register form wrapper for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Register_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-register-form';
	public $icon            = 'fas fa-user-plus';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Account register form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'register', 'registration', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 login state so WooCommerce can process registration submissions.', 'bricks' ),
		];
	}

	/**
	 * Enqueue Woo scripts required by registration password fields.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'woocommerce' );

		if ( get_option( 'woocommerce_registration_generate_password' ) === 'no' ) {
			wp_enqueue_script( 'wc-password-strength-meter' );
		}
	}

	/**
	 * Render the account register form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		if ( get_option( 'woocommerce_enable_myaccount_registration' ) !== 'yes' ) {
			if ( bricks_is_builder() || bricks_is_builder_call() ) {
				return $this->render_element_placeholder(
					[
						'title' => '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=account' ) ) . '" target="_blank">' . esc_html__( 'Account creation on "My account" page is not enabled.', 'bricks' ) . '</a>',
					]
				);
			}

			return;
		}

		$this->set_attribute( '_root', 'class', 'woocommerce-form' );
		$this->set_attribute( '_root', 'class', 'woocommerce-form-register' );
		$this->set_attribute( '_root', 'class', 'register' );
		$this->set_attribute( '_root', 'method', 'post' );

		ob_start();
		do_action( 'woocommerce_register_form_tag' );
		$additional_tags = trim( ob_get_clean() );

		echo '<form ' . $this->render_attributes( '_root' ) . ( $additional_tags ? ' ' . $additional_tags : '' ) . '>';
		echo Frontend::render_children( $this );
		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-register-form">
			<form class="brxe-woocommerce-account-register-form woocommerce-form woocommerce-form-register register" method="post">
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

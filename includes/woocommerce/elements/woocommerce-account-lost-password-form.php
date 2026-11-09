<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Account lost password form wrapper for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Lost_Password_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-lost-password-form';
	public $icon            = 'ti-help-alt';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Account lost password form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'lost password', 'password', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 lost password state so WooCommerce can process password reset requests.', 'bricks' ),
		];
	}

	/**
	 * Enqueue Woo scripts required by lost password submissions.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wc-lost-password' );
	}

	/**
	 * Render the account lost password form wrapper.
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
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-lost-password-form">
			<form class="brxe-woocommerce-account-lost-password-form woocommerce-ResetPassword lost_reset_password" method="post">
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

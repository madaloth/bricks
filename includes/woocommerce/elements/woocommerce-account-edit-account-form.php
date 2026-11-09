<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Account edit account form wrapper for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Edit_Account_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-edit-account-form';
	public $icon            = 'ti-layout';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Account edit account form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'edit account', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 edit account state so WooCommerce can save account details and password changes.', 'bricks' ),
		];
	}

	/**
	 * Enqueue Woo scripts required by the native edit-account password fields.
	 *
	 * @since 2.4
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wc-password-strength-meter' );
	}

	/**
	 * Render the account edit account form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		$this->set_attribute( '_root', 'class', 'woocommerce-EditAccountForm' );
		$this->set_attribute( '_root', 'class', 'edit-account' );
		$this->set_attribute( '_root', 'action', '' );
		$this->set_attribute( '_root', 'method', 'post' );

		ob_start();
		do_action( 'woocommerce_edit_account_form_tag' );
		$additional_tags = trim( ob_get_clean() );

		echo '<form ' . $this->render_attributes( '_root' ) . ( $additional_tags ? ' ' . $additional_tags : '' ) . '>';
		echo Frontend::render_children( $this );
		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-edit-account-form">
			<form class="brxe-woocommerce-account-edit-account-form woocommerce-EditAccountForm edit-account" action="" method="post">
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

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Account edit address form wrapper for Account v2.
 *
 * @since 2.4
 */
class Woocommerce_Account_Edit_Address_Form extends Woo_Element {
	public $category        = 'woocommerce_account';
	public $name            = 'woocommerce-account-edit-address-form';
	public $icon            = 'ti-layout';
	public $tag             = 'form';
	public $nestable        = true;
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Account edit address form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'account', 'address', 'edit address', 'form', 'woocommerce' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Account v2 edit address state so WooCommerce can save billing and shipping address fields.', 'bricks' ),
		];
	}

	/**
	 * Render the account edit address form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		// Match Woo's native account edit-address template so Woo handles validation and saving server-side.
		$this->set_attribute( '_root', 'method', 'post' );
		$this->set_attribute( '_root', 'novalidate', 'novalidate' );

		echo "<form {$this->render_attributes( '_root' )}>";
		echo Frontend::render_children( $this );
		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-edit-address-form">
			<form class="brxe-woocommerce-account-edit-address-form" method="post" novalidate>
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

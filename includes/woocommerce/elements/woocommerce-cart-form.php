<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Cart form wrapper for Cart v2.
 *
 * @since 2.4
 */
class Woocommerce_Cart_Form extends Woo_Element {
	public $category = 'woocommerce_cart';
	public $name     = 'woocommerce-cart-form';
	public $icon     = 'ti-layout';
	public $tag      = 'form';
	public $nestable = true;

	/**
	 * Only available inside Cart v2 state-cart.
	 */
	public $panel_condition = [ 'templateType', '=', '__never__' ];

	public function get_label() {
		return esc_html__( 'Cart form', 'bricks' );
	}

	public function get_keywords() {
		return [ 'cart', 'form', 'woocommerce', 'wrapper' ];
	}

	public function set_controls() {
		$this->controls['info'] = [
			'type'    => 'info',
			'content' => esc_html__( 'Use this wrapper inside the Cart v2 filled cart state to submit cart updates to WooCommerce.', 'bricks' ),
		];
	}

	/**
	 * Render the WooCommerce cart form wrapper.
	 *
	 * @since 2.4
	 */
	public function render() {
		Woocommerce_Helpers::maybe_init_cart_context();

		$this->set_attribute( '_root', 'class', 'woocommerce-cart-form' );
		$this->set_attribute( '_root', 'action', esc_url( wc_get_cart_url() ) );
		$this->set_attribute( '_root', 'method', 'post' );

		echo "<form {$this->render_attributes( '_root' )}>";
		echo '<div class="woocommerce-cart-form__contents">'; // Without this, frontend JS won't work
		echo Frontend::render_children( $this );
		echo '</div>';
		wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' );
		echo '</form>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-cart-form">
			<form
				class="brxe-woocommerce-cart-form woocommerce-cart-form"
				action="<?php echo esc_url( wc_get_cart_url() ); ?>"
				method="post">
				<div class="woocommerce-cart-form__contents">
					<bricks-element-children
						:element="element"
						:parentComponent="component || parentComponent"
						:instanceId="instanceId"
						:loopId="loopId" />
				</div>
			</form>
		</script>
		<?php
	}
}

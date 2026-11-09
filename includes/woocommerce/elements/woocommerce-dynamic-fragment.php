<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Bricks\Element_Container' ) ) {
	require_once BRICKS_PATH . 'includes/elements/container.php';
}

/**
 * WooCommerce dynamic fragment wrapper.
 *
 * Re-renders its mounted subtree after WooCommerce cart changes.
 *
 * @since 2.4
 */
class Woocommerce_Dynamic_Fragment extends Element_Container {
	public $category      = 'woocommerce_cart';
	public $name          = 'woocommerce-dynamic-fragment';
	public $icon          = 'ti-reload';
	public $vue_component = 'bricks-nestable';
	public $nestable      = true;

	public function get_label() {
		return esc_html__( 'Dynamic fragment', 'bricks' );
	}

	public function get_keywords() {
		return [ 'woocommerce', 'cart', 'fragment', 'refresh', 'dynamic', 'mini cart' ];
	}

	/**
	 * Treat the fragment as a layout element to match its Container controls.
	 *
	 * @since 2.4
	 *
	 * @return boolean
	 */
	public function is_layout_element() {
		return true;
	}

	/**
	 * Support applying the inherited Container link control to the fragment root.
	 *
	 * @since 2.4 #86cau9n3u
	 *
	 * @return boolean
	 */
	protected function supports_root_link() {
		return true;
	}

	public function set_controls() {
		parent::set_controls();

		$this->controls = array_merge(
			[
				'dynamicFragmentInfo'                 => [
					'tab'     => 'content',
					'type'    => 'info',
					'content' => esc_html__( 'Re-renders this element and its children after WooCommerce cart changes. Use it for cart-dependent dynamic data such as free shipping progress, cart totals, or custom mini cart content. Query loop instances are not supported yet.', 'bricks' ),
				],

				'generatePredefinedElementsSeparator' => [
					'type'  => 'separator',
					'label' => esc_html__( 'Insert a structure', 'bricks' ),
				],

				'generatePredefinedElements'          => [
					'description' => esc_html__( 'Appended to the current structure, never overwrites.', 'bricks' ),
					'type'        => 'select',
					'options'     => [
						'mini-cart'              => esc_html__( 'Mini cart', 'bricks' ),
						'free-shipping-progress' => esc_html__( 'Free shipping progress', 'bricks' ),
					],
					'placeholder' => esc_html__( 'Choose structure', 'bricks' ) . '...',
				],

				'generatePredefinedElementsApply'     => [
					'type'               => 'button',
					'label'              => esc_html__( 'Generate', 'bricks' ),
					'action'             => 'generatePredefinedElements',
					'generatorOperation' => 'generate',
					'generatorType'      => 'woocommerce',
					'generatorPage'      => 'dynamic-fragment',
					'generatorArea'      => 'content',
					'sourceControl'      => 'generatePredefinedElements',
					'required'           => [ 'generatePredefinedElements', '!=', '' ],
				],

				'predefinedElementsEndingSeparator'   => [
					'type' => 'separator',
				],
			],
			$this->controls
		);

		$this->controls['linkInfo']['content'] = esc_html__( 'Do not place links, buttons, forms, or other interactive elements inside a linked dynamic fragment.', 'bricks' );
	}

	/**
	 * Add fragment metadata needed for frontend refresh requests.
	 *
	 * @since 2.4
	 *
	 * @return boolean
	 */
	private function add_dynamic_fragment_attributes() {
		if ( ! Woocommerce::use_advanced_modular_elements() || ! $this->is_frontend || bricks_is_builder_call() || Query::is_any_looping() ) {
			return false;
		}

		if ( ! empty( $this->element['cid'] ) || ! empty( $this->element['parentComponent'] ) ) {
			return false;
		}

		$context        = Frontend::get_render_context();
		$source_post_id = absint( $context['source_post_id'] ?? 0 );
		$source_area    = sanitize_key( $context['source_area'] ?? 'content' );

		if ( ! $source_post_id || ! in_array( $source_area, [ 'header', 'content', 'footer' ], true ) ) {
			return false;
		}

		$this->set_attribute( '_root', 'data-brx-woo-fragment', 'true' );
		// Use UID for the mounted DOM target, but keep the stored element ID for server-side subtree lookup.
		$this->set_attribute( '_root', 'data-brx-woo-fragment-id', $this->uid );
		$this->set_attribute( '_root', 'data-brx-woo-fragment-root-id', $this->id );
		$this->set_attribute( '_root', 'data-brx-woo-fragment-source', $source_post_id );
		$this->set_attribute( '_root', 'data-brx-woo-fragment-area', $source_area );
		$this->set_attribute(
			'_root',
			'data-brx-woo-fragment-cart-hash',
			WC()->cart && ! WC()->cart->is_empty() ? WC()->cart->get_cart_hash() : ''
		);
		$this->set_attribute(
			'_root',
			'data-brx-woo-fragment-token',
			Woocommerce::get_dynamic_fragment_token(
				$source_post_id,
				$source_area,
				$this->uid,
				$this->id
			)
		);

		return true;
	}

	/**
	 * Render an empty shell if the fragment wrapper's own conditions fail.
	 *
	 * Keeps the refresh target mounted so it can reappear on the next cart refresh.
	 *
	 * @since 2.4
	 */
	public function render_woo_dynamic_fragment_shell() {
		if ( ! $this->add_dynamic_fragment_attributes() ) {
			return;
		}

		$this->set_attribute( '_root', 'hidden' );
		$this->set_attribute( '_root', 'data-brx-woo-fragment-empty', 'true' );
		$this->set_attribute( '_root', 'data-brx-woo-fragment-conditions', 'false' );

		echo "<{$this->tag} {$this->render_attributes( '_root' )}></{$this->tag}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render fragment wrapper and children.
	 *
	 * @since 2.4
	 */
	public function render() {
		if ( ! Woocommerce::use_advanced_modular_elements() ) {
			return;
		}

		$this->add_dynamic_fragment_attributes();

		echo "<{$this->tag} {$this->render_attributes( '_root' )}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</{$this->tag}>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-dynamic-fragment">
			<div class="brxe-woocommerce-dynamic-fragment">
				<bricks-element-children :element="element" />
			</div>
		</script>
		<?php
	}
}

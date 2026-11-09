<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce Cart v2 parent element with internal state blocks.
 *
 * @since 2.4
 */
class Woocommerce_Cart_V2 extends Woo_Element {
	public $category                         = 'woocommerce_cart';
	public $name                             = 'woocommerce-cart-v2';
	public $icon                             = 'ti-shopping-cart-full';
	public $nestable                         = true;
	public $panel_condition                  = [ 'wooPage', '=', 'cart' ];
	protected static $is_shortcode_rendering = false;

	/**
	 * Set state classes shared by the builder and frontend root.
	 *
	 * The parent element's #brxe-{id} root must remain present on every render path because
	 * layout controls and custom CSS target it. Centralizing these classes keeps the runtime
	 * cart state and builder preview on the same root contract. WooCommerce's
	 * `wc-empty-cart-message` class must remain on the inner empty-state content: Its cart script
	 * treats every matching node as AJAX replacement markup, so adding it here corrupts the DOM
	 * when both the parent and its descendant are returned.
	 *
	 * @since 2.4
	 *
	 * @param string $state Active state.
	 * @return void
	 */
	private function set_root_state_attributes( $state ) {
		$this->set_attribute( '_root', 'class', 'woocommerce' );
		$this->set_attribute( '_root', 'class', "brx-wc-cart-v2--{$state}" );

		if ( $state === 'empty' ) {
			$this->set_attribute( '_root', 'class', 'cart-empty' );
		}
	}

	public function get_label() {
		return esc_html__( 'Cart', 'bricks' ) . ' (v2)';
	}

	public function get_keywords() {
		return [ 'woocommerce', 'cart', 'empty cart', 'v2' ];
	}

	public function set_controls() {
		$this->controls['previewMode'] = [
			'label'   => esc_html_x( 'State', 'WooCommerce template state', 'bricks' ),
			'type'    => 'radio',
			'options' => [
				'cart'  => [
					'title'       => esc_html__( 'Filled cart', 'bricks' ),
					'description' => esc_html__( 'Actual/sample cart.', 'bricks' ),
				],
				'empty' => [
					'title'       => esc_html__( 'Empty cart', 'bricks' ),
					'description' => esc_html__( 'No items. Shows empty cart.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Provide default internal state children.
	 *
	 * @since 2.4
	 */
	public function get_nestable_children() {
		return [
			[
				'name'      => 'woocommerce-cart-v2-state-cart',
				'label'     => esc_html__( 'Filled cart', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'cartV2StateRole' => 'cart',
					'_hidden'         => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-cart-v2-state-empty',
				'label'     => esc_html__( 'Empty cart', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'cartV2StateRole' => 'empty',
					'_hidden'         => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
		];
	}

	/**
	 * Render only the active state block (cart or empty).
	 *
	 * @since 2.4
	 */
	public function render() {
		if ( ! Woocommerce::use_advanced_modular_elements() ) {
			// Registration already gates this beta element; this prevents any
			// direct/stale render path from falling back to native Woo output.
			return;
		}

		$settings     = $this->settings;
		$preview_mode = $settings['previewMode'] ?? 'cart';
		$children     = ! empty( $this->element['children'] ) && is_array( $this->element['children'] ) ? $this->element['children'] : [];
		$is_builder   = bricks_is_builder() || bricks_is_builder_call();

		if ( ! $is_builder ) {
			if ( self::$is_shortcode_rendering ) {
				return;
			}

			Woocommerce_Helpers::maybe_load_cart();
			Woocommerce_Helpers::maybe_init_cart_context();

			// Resolve the state before opening the root so frontend classes match the active Woo cart state.
			$woocommerce  = isset( $GLOBALS['woocommerce'] ) ? $GLOBALS['woocommerce'] : null;
			$is_empty     = $woocommerce && isset( $woocommerce->cart ) ? $woocommerce->cart->is_empty() : false;
			$active_state = $is_empty ? 'empty' : 'cart';

			$this->set_root_state_attributes( $active_state );

			// Keep the Bricks root outside Woo's shortcode output. The shortcode still owns the
			// cart lifecycle, while this wrapper remains the target for parent element styles.
			echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			self::$is_shortcode_rendering = true;

			try {
				echo do_shortcode( '[woocommerce_cart]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} finally {
				self::$is_shortcode_rendering = false;
			}

			echo '</div>';
			return;
		}

		$active_state  = 'cart';
		$state_by_name = [
			'woocommerce-cart-v2-state-cart'  => false,
			'woocommerce-cart-v2-state-empty' => false,
		];

		// Preserve the deliberately empty preview while sharing one preparation pass for populated states. (#86cb6j2b0)
		if ( $preview_mode !== 'empty' ) {
			Woocommerce_Helpers::maybe_prepare_builder_cart_preview();
		} else {
			Woocommerce_Helpers::maybe_load_cart();
			Woocommerce_Helpers::maybe_init_cart_context();
		}

		foreach ( $children as $child_id ) {
			$child = Frontend::$elements[ $child_id ] ?? false;
			if ( ! $child ) {
				continue;
			}

			$child_name = $child['name'] ?? '';
			if ( isset( $state_by_name[ $child_name ] ) ) {
				$state_by_name[ $child_name ] = $child;
			}
		}

		// Builder preview mode overrides runtime cart state while editing.
		if ( $preview_mode === 'empty' ) {
			$active_state = 'empty';
		} elseif ( $preview_mode === 'cart' ) {
			$active_state = 'cart';
		} else {
			$woocommerce  = isset( $GLOBALS['woocommerce'] ) ? $GLOBALS['woocommerce'] : null;
			$is_empty     = ( $woocommerce && isset( $woocommerce->cart ) ) ? $woocommerce->cart->is_empty() : false;
			$active_state = $is_empty ? 'empty' : 'cart';
		}

		$active_element_name = $active_state === 'empty' ? 'woocommerce-cart-v2-state-empty' : 'woocommerce-cart-v2-state-cart';
		$active_element      = $state_by_name[ $active_element_name ];

		$this->set_root_state_attributes( $active_state );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $active_element ) {
			echo Frontend::render_element( $active_element ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-cart-v2">
			<div
				:class="[
					'brxe-woocommerce-cart-v2',
					'woocommerce',
					'brx-wc-cart-v2--' + ( element.settings.previewMode || 'cart' ),
					( element.settings.previewMode || 'cart' ) === 'empty' ? 'cart-empty' : null
				]">
				<bricks-element-children
					:element="element"
					:parentComponent="component || parentComponent"
					:instanceId="instanceId"
					:loopId="loopId" />
			</div>
		</script>
		<?php
	}
}

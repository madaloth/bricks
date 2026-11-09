<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce Checkout v2 parent element with internal state blocks.
 *
 * @since 2.4
 */
class Woocommerce_Checkout_V2 extends Woo_Element {
	public $category                         = 'woocommerce_checkout';
	public $name                             = 'woocommerce-checkout-v2';
	public $icon                             = 'ti-shopping-cart';
	public $nestable                         = true;
	public $panel_condition                  = [ 'wooPage', '=', 'checkout' ];
	protected static $is_shortcode_rendering = false;

	/**
	 * Check whether the active checkout builder state has material style enabled.
	 *
	 * @param array|false $active_element Active state element data.
	 *
	 * @return bool
	 */
	private function has_floating_label_style( $active_element ) {
		return ! empty( $active_element['settings']['floatingLabelStyle'] );
	}

	/**
	 * Set state classes shared by the builder and frontend root.
	 *
	 * The parent element's #brxe-{id} root must remain present on every render path because
	 * layout controls and custom CSS target it. Centralizing the state classes also prevents
	 * the shortcode-based frontend markup from drifting from the builder preview.
	 *
	 * @since 2.4
	 *
	 * @param string      $state          Active state.
	 * @param array|false $active_element Active state element data.
	 * @return void
	 */
	private function set_root_state_attributes( $state, $active_element = false ) {
		$this->set_attribute( '_root', 'class', 'woocommerce' );
		$this->set_attribute( '_root', 'class', 'woocommerce-checkout' );
		$this->set_attribute( '_root', 'class', "brx-wc-checkout-v2--{$state}" );

		if ( $this->has_floating_label_style( $active_element ) ) {
			$this->set_attribute( '_root', 'class', 'bricks-floating-label' );
		}
	}

	public function get_label() {
		return esc_html__( 'Checkout', 'bricks' ) . ' (v2)';
	}

	public function get_keywords() {
		return [ 'woocommerce', 'checkout', 'pay', 'thankyou', 'receipt', 'login', 'v2' ];
	}

	public function set_controls() {
		$this->controls['previewMode'] = [
			'label'   => esc_html_x( 'State', 'WooCommerce template state', 'bricks' ),
			'type'    => 'radio',
			'options' => [
				'checkout' => [ 'title' => esc_html__( 'Checkout', 'bricks' ) ],
				'login'    => [ 'title' => esc_html__( 'Login required', 'bricks' ) ],
				'thankyou' => [ 'title' => esc_html__( 'Thank you', 'bricks' ) ],
				'pay'      => [ 'title' => esc_html__( 'Pay', 'bricks' ) ],
				'receipt'  => [ 'title' => esc_html__( 'Order receipt', 'bricks' ) ],
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
				'name'      => 'woocommerce-checkout-v2-state-checkout',
				'label'     => esc_html__( 'Checkout', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'checkoutV2StateRole' => 'checkout',
					'_hidden'             => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-checkout-v2-state-login',
				'label'     => esc_html__( 'Login required', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'checkoutV2StateRole' => 'login',
					'_hidden'             => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-checkout-v2-state-thankyou',
				'label'     => esc_html__( 'Thank you', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'checkoutV2StateRole' => 'thankyou',
					'_hidden'             => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-checkout-v2-state-pay',
				'label'     => esc_html__( 'Pay', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'checkoutV2StateRole' => 'pay',
					'_hidden'             => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-checkout-v2-state-receipt',
				'label'     => esc_html__( 'Order receipt', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'checkoutV2StateRole' => 'receipt',
					'_hidden'             => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
		];
	}

	/**
	 * Resolve frontend runtime state.
	 *
	 * @since 2.4
	 */
	protected function get_runtime_state() {
		$state = Woocommerce::get_checkout_v2_current_state();

		return $state !== false ? $state : 'checkout';
	}

	/**
	 * Render only the active state block.
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
		$preview_mode = $settings['previewMode'] ?? 'checkout';
		$children     = ! empty( $this->element['children'] ) && is_array( $this->element['children'] ) ? $this->element['children'] : [];
		$is_builder   = bricks_is_builder() || bricks_is_builder_call();

		if ( ! $is_builder ) {
			if ( self::$is_shortcode_rendering ) {
				return;
			}

			$active_state          = $this->get_runtime_state();
			$active_state_settings = Woocommerce::get_checkout_v2_state_settings( $active_state );
			$active_element        = [
				'settings' => is_array( $active_state_settings ) ? $active_state_settings : [],
			];

			$this->set_root_state_attributes( $active_state, $active_element );

			// Keep the Bricks root outside Woo's shortcode output. The shortcode still owns the
			// checkout form lifecycle, while this wrapper remains the target for parent element styles.
			echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$login_required_args = Woocommerce::get_checkout_v2_login_required_args();
			if ( $login_required_args && Woocommerce::render_checkout_v2_login_required_state( $login_required_args ) ) {
				// The custom login-required renderer replaces the shortcode content, not the Bricks root.
				echo '</div>';
				return;
			}

			self::$is_shortcode_rendering = true;

			try {
				echo do_shortcode( '[woocommerce_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} finally {
				self::$is_shortcode_rendering = false;
			}

			echo '</div>';
			return;
		}

		$active_state  = 'checkout';
		$state_by_name = [
			'woocommerce-checkout-v2-state-checkout' => false,
			'woocommerce-checkout-v2-state-login'    => false,
			'woocommerce-checkout-v2-state-pay'      => false,
			'woocommerce-checkout-v2-state-thankyou' => false,
			'woocommerce-checkout-v2-state-receipt'  => false,
		];

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

		if ( $is_builder ) {
			$preview_to_state = [
				'checkout' => 'checkout',
				'login'    => 'login',
				'pay'      => 'pay',
				'thankyou' => 'thankyou',
				'receipt'  => 'receipt',
			];

			$active_state = $preview_to_state[ $preview_mode ] ?? 'checkout';
		} else {
			$active_state = $this->get_runtime_state();
		}

		$state_to_element_name = [
			'checkout' => 'woocommerce-checkout-v2-state-checkout',
			'login'    => 'woocommerce-checkout-v2-state-login',
			'pay'      => 'woocommerce-checkout-v2-state-pay',
			'thankyou' => 'woocommerce-checkout-v2-state-thankyou',
			'receipt'  => 'woocommerce-checkout-v2-state-receipt',
		];

		$active_element_name = $state_to_element_name[ $active_state ] ?? 'woocommerce-checkout-v2-state-checkout';
		$active_element      = $state_by_name[ $active_element_name ];

		$this->set_root_state_attributes( $active_state, $active_element );

		echo '<div ' . $this->render_attributes( '_root' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $active_element ) {
			echo Frontend::render_element( $active_element ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo Frontend::render_children( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</div>';
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-checkout-v2">
			<div
				:class="[
					'brxe-woocommerce-checkout-v2',
					'woocommerce',
					'woocommerce-checkout',
					`brx-wc-checkout-v2--${settings.previewMode || 'checkout'}`,
					(() => {
						const activeStateName = ({
							checkout: 'woocommerce-checkout-v2-state-checkout',
							login: 'woocommerce-checkout-v2-state-login',
							pay: 'woocommerce-checkout-v2-state-pay',
							thankyou: 'woocommerce-checkout-v2-state-thankyou',
							receipt: 'woocommerce-checkout-v2-state-receipt'
						})[settings.previewMode || 'checkout'] || 'woocommerce-checkout-v2-state-checkout'

						return (element.children || [])
							.map((childId) => $_getDynamicElementById(childId))
							.find((child) => child?.name === activeStateName)
							?.settings?.floatingLabelStyle ? 'bricks-floating-label' : null
					})()
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

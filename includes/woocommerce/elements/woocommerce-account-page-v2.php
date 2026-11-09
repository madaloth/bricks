<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce Account v2 parent element with internal endpoint state blocks.
 *
 * @since 2.4
 */
class Woocommerce_Account_Page_V2 extends Woo_Element {
	public $category                         = 'woocommerce_account';
	public $name                             = 'woocommerce-account-page-v2';
	public $icon                             = 'ti-user';
	public $nestable                         = true;
	public $panel_condition                  = [ 'wooPage', '=', 'myaccount' ];
	protected static $is_shortcode_rendering = false;

	public function get_label() {
		return esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Page', 'bricks' ) . ' v2';
	}

	public function get_keywords() {
		return [ 'woocommerce', 'account', 'my account', 'v2' ];
	}

	/**
	 * Check whether the active Account builder state has floating labels enabled.
	 *
	 * @since 2.4
	 *
	 * @param string      $state          Active state key.
	 * @param array|false $active_element Active state element data.
	 *
	 * @return bool
	 */
	private function has_floating_label_style( $state, $active_element ) {
		return in_array( $state, [ 'edit-address', 'edit-account', 'login', 'lost-password', 'reset-password', 'order-withdrawal' ], true ) && ! empty( $active_element['settings']['floatingLabelStyle'] );
	}

	public function set_control_groups() {
		$this->control_groups['navigation'] = [
			'title'    => esc_html__( 'Navigation', 'bricks' ),
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->control_groups['content'] = [
			'title' => esc_html__( 'Content', 'bricks' ),
		];
	}

	public function set_controls() {
		$this->controls['previewMode'] = [
			'label'   => esc_html_x( 'State', 'WooCommerce template state', 'bricks' ),
			'type'    => 'radio',
			'options' => [
				'dashboard'                  => [ 'title' => esc_html__( 'Dashboard', 'bricks' ) ],
				'orders'                     => [ 'title' => esc_html__( 'Orders', 'bricks' ) ],
				'view-order'                 => [ 'title' => esc_html__( 'View order', 'bricks' ) ],
				'downloads'                  => [ 'title' => esc_html__( 'Downloads', 'bricks' ) ],
				'addresses'                  => [ 'title' => esc_html__( 'Addresses', 'bricks' ) ],
				'edit-address'               => [ 'title' => esc_html__( 'Edit address', 'bricks' ) ],
				'edit-account'               => [ 'title' => esc_html__( 'Edit account', 'bricks' ) ],
				'payment-methods'            => [ 'title' => esc_html__( 'Payment methods', 'bricks' ) ],
				'add-payment-method'         => [ 'title' => esc_html__( 'Add payment method', 'bricks' ) ],
				'login'                      => [ 'title' => esc_html__( 'Login', 'bricks' ) ],
				'lost-password'              => [ 'title' => esc_html__( 'Lost password', 'bricks' ) ],
				'lost-password-confirmation' => [ 'title' => esc_html__( 'Lost password', 'bricks' ) . ' (' . esc_html__( 'Confirmation', 'bricks' ) . ')' ],
				'reset-password'             => [ 'title' => esc_html__( 'Reset password', 'bricks' ) ],
			],
		];

		if ( Woocommerce::is_order_withdrawal_enabled() ) {
			$this->controls['previewMode']['options']['order-withdrawal'] = [ 'title' => esc_html__( 'Order withdrawal', 'bricks' ) ];
		}

		$this->controls['addMissingStates'] = [
			'type'   => 'button',
			'label'  => esc_html__( 'Add missing states', 'bricks' ),
			'action' => 'addMissingStates',
		];

		$this->controls['styleSep'] = [
			'type'  => 'separator',
			'label' => esc_html__( 'Style', 'bricks' ),
		];

		// WRAPPER
		$this->controls['direction'] = [
			'label'    => esc_html__( 'Direction', 'bricks' ),
			'type'     => 'direction',
			'inline'   => true,
			'rerender' => false,
			'css'      => [
				[
					'selector' => '.woocommerce:not(#brx-content)',
					'property' => 'flex-direction',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['gap'] = [
			'label'    => esc_html__( 'Gap', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'selector' => '.woocommerce:not(#brx-content)',
					'property' => 'gap',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['disableNav'] = [
			'label' => esc_html__( 'Disable navigation', 'bricks' ),
			'type'  => 'checkbox',
		];

		// NAVIGATION
		$this->controls['navDirection'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Direction', 'bricks' ),
			'type'     => 'direction',
			'inline'   => true,
			'rerender' => false,
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation ul',
					'property' => 'flex-direction',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navAlignItems'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Align items', 'bricks' ),
			'type'     => 'align-items',
			'inline'   => true,
			'exclude'  => [ 'stretch' ],
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation ul',
					'property' => 'align-items',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navJustifyContent'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Justify content', 'bricks' ),
			'type'     => 'justify-content',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation ul',
					'property' => 'justify-content',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navGap'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Gap', 'bricks' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation ul',
					'property' => 'gap',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navBorder'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Border', 'bricks' ),
			'type'     => 'border',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation',
					'property' => 'border',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navBoxShadow'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Box shadow', 'bricks' ),
			'type'     => 'box-shadow',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation',
					'property' => 'box-shadow',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		// NAV ITEM
		$this->controls['navItemSep'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Item', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navBackground'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation',
					'property' => 'background-color',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemPadding'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Item padding', 'bricks' ),
			'type'     => 'spacing',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation a',
					'property' => 'padding',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBackground'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation a',
					'property' => 'background-color',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBorder'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Border', 'bricks' ),
			'type'     => 'border',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation a',
					'property' => 'border',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBoxShadow'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Box shadow', 'bricks' ),
			'type'     => 'box-shadow',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation a',
					'property' => 'box-shadow',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemTypography'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation a',
					'property' => 'font',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		// ACTIVE
		$this->controls['navItemActiveSep'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Active', 'bricks' ),
			'type'     => 'separator',
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBackgroundActive'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Active background', 'bricks' ),
			'type'     => 'color',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation .is-active a',
					'property' => 'background-color',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBorderActive'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Border', 'bricks' ),
			'type'     => 'border',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation .is-active a',
					'property' => 'border',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemBoxShadowActive'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Box shadow', 'bricks' ),
			'type'     => 'box-shadow',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation .is-active a',
					'property' => 'box-shadow',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		$this->controls['navItemTypographyActive'] = [
			'group'    => 'navigation',
			'label'    => esc_html__( 'Typography', 'bricks' ),
			'type'     => 'typography',
			'css'      => [
				[
					'selector' => '.woocommerce-MyAccount-navigation .is-active a',
					'property' => 'font',
				],
			],
			'required' => [ 'disableNav', '!=', true ],
		];

		// CONTENT
		$this->controls['contentPadding'] = [
			'group' => 'content',
			'label' => esc_html__( 'Padding', 'bricks' ),
			'type'  => 'spacing',
			'css'   => [
				[
					'selector' => '.woocommerce-MyAccount-content',
					'property' => 'padding',
				],
			],
		];

		$this->controls['contentBackground'] = [
			'group' => 'content',
			'label' => esc_html__( 'Background', 'bricks' ),
			'type'  => 'color',
			'css'   => [
				[
					'selector' => '.woocommerce-MyAccount-content',
					'property' => 'background-color',
				],
			],
		];

		$this->controls['contentBorder'] = [
			'group' => 'content',
			'label' => esc_html__( 'Border', 'bricks' ),
			'type'  => 'border',
			'css'   => [
				[
					'selector' => '.woocommerce-MyAccount-content',
					'property' => 'border',
				],
			],
		];

		$this->controls['contentBoxShadow'] = [
			'group' => 'content',
			'label' => esc_html__( 'Box shadow', 'bricks' ),
			'type'  => 'box-shadow',
			'css'   => [
				[
					'selector' => '.woocommerce-MyAccount-content',
					'property' => 'box-shadow',
				],
			],
		];

		$this->controls['contentTypography'] = [
			'group' => 'content',
			'label' => esc_html__( 'Typography', 'bricks' ),
			'type'  => 'typography',
			'css'   => [
				[
					'selector' => '.woocommerce-MyAccount-content',
					'property' => 'font',
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
		$children = [
			[
				'name'      => 'woocommerce-account-v2-state-dashboard',
				'label'     => esc_html__( 'Dashboard', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'dashboard',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-orders',
				'label'     => esc_html__( 'Orders', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'orders',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-view-order',
				'label'     => esc_html__( 'View order', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'view-order',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-downloads',
				'label'     => esc_html__( 'Downloads', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'downloads',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-addresses',
				'label'     => esc_html__( 'Addresses', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'addresses',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-edit-address',
				'label'     => esc_html__( 'Edit address', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'edit-address',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-edit-account',
				'label'     => esc_html__( 'Edit account', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'edit-account',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-payment-methods',
				'label'     => esc_html__( 'Payment methods', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'payment-methods',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-add-payment-method',
				'label'     => esc_html__( 'Add payment method', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'add-payment-method',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-login',
				'label'     => esc_html__( 'Login', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'login',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-lost-password',
				'label'     => esc_html__( 'Lost password', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'lost-password',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-lost-password-confirmation',
				'label'     => esc_html__( 'Lost password confirmation', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'lost-password-confirmation',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
			[
				'name'      => 'woocommerce-account-v2-state-reset-password',
				'label'     => esc_html__( 'Reset password', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'reset-password',
					'_hidden'            => [
						'_cssClasses' => 'bricks-woo-state',
					],
				],
				'children'  => [],
			],
		];

		if ( Woocommerce::is_order_withdrawal_enabled() ) {
			$children[] = [
				'name'      => 'woocommerce-account-v2-state-order-withdrawal',
				'label'     => esc_html__( 'Order withdrawal', 'bricks' ) . ' (' . esc_html_x( 'State', 'WooCommerce template state', 'bricks' ) . ')',
				'deletable' => false,
				'cloneable' => false,
				'settings'  => [
					'accountV2StateRole' => 'order-withdrawal',
					'_hidden'            => [ '_cssClasses' => 'bricks-woo-state' ],
				],
				'children'  => [],
			];
		}

		return $children;
	}

	/**
	 * Render only the active account state.
	 *
	 * @since 2.4
	 */
	public function render() {
		if ( ! Woocommerce::use_advanced_modular_elements() ) {
			// Registration already gates this beta element; this prevents any
			// direct/stale render path from falling back to native Woo output.
			return;
		}

		global $wp;
		global $wp_query;

		$settings     = $this->settings;
		$preview_mode = $settings['previewMode'] ?? 'dashboard';
		$children     = ! empty( $this->element['children'] ) && is_array( $this->element['children'] ) ? $this->element['children'] : [];
		$is_builder   = bricks_is_builder() || bricks_is_builder_call();

		/**
		 * Set global in_the_loop()
		 *
		 * Some plugins might rely on the `in_the_loop` check.
		 */
		$wp_query->in_the_loop = true;

		// STEP: Disable WooCommerce account navigation.
		$this->maybe_disable_navigation();

		// Actual Frontend rendering.
		if ( ! $is_builder ) {
			// STEP: Lost/reset password form (Bricks template)
			if ( isset( $wp->query_vars['lost-password'] ) ) {
				// Reuse the validated state prepared for notices/state selection; a
				// second native preparation would consume a single-use bridge again.
				$password_state = Woocommerce_Password_Reset::get_state();

				if ( $password_state === 'reset-password' ) {
					if ( $this->render_state_data( 'reset-password' ) ) {
						return;
					}

					// Fall back to Woo's reset form with the same prepared credentials.
					wc_get_template( 'myaccount/form-reset-password.php', Woocommerce::get_reset_password_args() );
					return;
				}

				// Lost password confirmation
				if ( $password_state === 'lost-password-confirmation' ) {
					if ( $this->render_state_data( 'lost-password-confirmation' ) ) {
						return;
					}

					// Fallback: Get 'wc_account_form_lost_password_confirmation' Woo template
					wc_get_template( 'myaccount/lost-password-confirmation.php' );
					return;
				}

				// Lost password form
				if ( $this->render_state_data( 'lost-password' ) ) {
					return;
				}

				// Fallback: Get 'wc_account_form_lost_password' Woo template
				wc_get_template( 'myaccount/form-lost-password.php' );
				return;
			}

			$is_order_withdrawal = Woocommerce::is_order_withdrawal_request();

			if ( $is_order_withdrawal && ! self::$is_shortcode_rendering && Woocommerce::get_account_v2_state_render_data( 'order-withdrawal' ) ) {
				self::$is_shortcode_rendering = true;
				try {
					echo Woocommerce_Order_Withdrawal::render(
						function( $notices ) {
							$this->render_state_data( 'order-withdrawal', $notices );
						}
					);
				} finally {
					self::$is_shortcode_rendering = false;
				}
				return;
			}

			// Withdrawal is public and must reach Woo's complete shortcode flow.
			if ( ! is_user_logged_in() && ! $is_order_withdrawal ) {
				if ( $this->render_state_data( 'login' ) ) {
					return;
				}

				// STEP: Fallback: Get 'wc_account_form_login' Woo template
				wc_get_template( 'myaccount/form-login.php' );
				return;
			}

			if ( self::$is_shortcode_rendering ) {
				return;
			}

			self::$is_shortcode_rendering = true;

			// STEP: Native account or public withdrawal flow.
			try {
				// The shortcode path renders Woo content internally, so derive the active state here to scope wrappers/styles correctly.
				$active_state          = $is_order_withdrawal ? 'order-withdrawal' : Woocommerce::get_account_v2_current_state();
				$active_state          = $active_state ? $active_state : 'dashboard';
				$active_state_settings = $is_order_withdrawal ? [] : Woocommerce::get_account_v2_state_settings( $active_state );
				$active_state_element  = [
					'settings' => is_array( $active_state_settings ) ? $active_state_settings : [],
				];

				$this->set_attribute( '_root', 'class', "brx-wc-account-page-v2--{$active_state}" );

				if ( ! $is_order_withdrawal && $this->has_floating_label_style( $active_state, $active_state_element ) ) {
					$this->set_attribute( '_root', 'class', 'bricks-floating-label' );
				}

				echo '<div ' . $this->render_attributes( '_root' ) . '>';
				echo do_shortcode( '[woocommerce_my_account]' );
				echo '</div>';
			} finally {
				self::$is_shortcode_rendering = false;
			}

			return;
		}

		// Builder Rendering
		$state_by_name = array_fill_keys( array_values( $this->get_state_to_element_name_map() ), false );

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

		$preview_to_state      = $this->get_preview_to_state_map();
		$state_to_element_name = $this->get_state_to_element_name_map();

		$active_state         = $preview_to_state[ $preview_mode ] ?? 'dashboard';
		$active_name          = $state_to_element_name[ $active_state ] ?? 'woocommerce-account-v2-state-dashboard';
		$active_state_element = $state_by_name[ $active_name ];

		$content = $active_state_element ? Frontend::render_element( $active_state_element ) : Frontend::render_children( $this );

		$this->render_state_layout( $active_state, $content, $active_state_element );
	}

	/**
	 * Render one Account V2 state from the current account page data.
	 *
	 * @since 2.4
	 *
	 * @param string $state State to render.
	 * @param string $before_content Prepared notices to render inside the state wrapper (@since 2.4).
	 * @return boolean
	 */
	private function render_state_data( $state, $before_content = '' ) {
		$state_data = Woocommerce::get_account_v2_state_render_data( $state );

		if ( ! $state_data ) {
			return false;
		}

		$state_element = $state_data[0] ?? false;

		$this->render_state_layout( $state, $before_content . Frontend::render_data( $state_data ), $state_element );

		return true;
	}

	/**
	 * Return preview mode to state map.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_preview_to_state_map() {
		$groups = Woocommerce::get_builder_state_group_definitions();

		return $groups['woocommerce-account-page-v2']['previewToState'] ?? [];
	}

	/**
	 * Return state key to element name map.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_state_to_element_name_map() {
		$groups = Woocommerce::get_builder_state_group_definitions();

		return $groups['woocommerce-account-page-v2']['states'] ?? [];
	}

	/**
	 * Render shared Account V2 layout around provided content.
	 *
	 * @since 2.4
	 *
	 * @param string      $state          Active state key.
	 * @param string      $content        Rendered state content.
	 * @param array|false $active_element Active state element data.
	 * @return void
	 */
	private function render_state_layout( $state, $content, $active_element = false ) {
		$show_navigation     = $this->should_show_navigation( $state );
		$has_floating_labels = $this->has_floating_label_style( $state, $active_element );
		$woocommerce_classes = $state === 'order-withdrawal' ? [ 'woocommerce-order-withdrawal-content' ] : [];

		$this->set_attribute( '_root', 'class', "brx-wc-account-page-v2--{$state}" );

		if ( $has_floating_labels ) {
			// Apply to both wrappers because common field styles can target either the Bricks root or Woo's inner container.
			$this->set_attribute( '_root', 'class', 'bricks-floating-label' );
			$woocommerce_classes[] = 'bricks-floating-label';
		}

		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		// Keep Woo classes on an inner wrapper so navigation and state content receive the same inherited Woo styles.
		echo '<div class="' . esc_attr( implode( ' ', $woocommerce_classes ) ) . '">';

		if ( $show_navigation ) {
			echo '<nav class="woocommerce-MyAccount-navigation">';
			woocommerce_account_navigation();
			echo '</nav>';
			echo '<div class="woocommerce-MyAccount-content">';
		}

		echo $content;

		if ( $show_navigation ) {
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Check whether current state should render account navigation.
	 *
	 * @since 2.4
	 *
	 * @param string $state
	 * @return boolean
	 */
	private function should_show_navigation( $state ) {
		$logged_out_states = [ 'login', 'lost-password', 'lost-password-confirmation', 'reset-password', 'order-withdrawal' ];
		$is_nav_disabled   = ! empty( $this->settings['disableNav'] );

		return ! $is_nav_disabled && ! in_array( $state, $logged_out_states, true );
	}

	/**
	 * Maybe disable WooCommerce account navigation.
	 *
	 * @since 2.4
	 */
	private function maybe_disable_navigation() {
		$disable_navigation = $this->settings['disableNav'] ?? false;

		if ( $disable_navigation ) {
			remove_action( 'woocommerce_account_navigation', 'woocommerce_account_navigation' );
		}
	}

	public static function render_builder() { ?>
		<script type="text/x-template" id="tmpl-bricks-element-woocommerce-account-page-v2">
			<div
				:class="[
					'brxe-woocommerce-account-page-v2',
					'brx-wc-account-page-v2--' + (settings.previewMode || 'dashboard'),
					(['edit-address', 'edit-account', 'login', 'lost-password', 'reset-password', 'order-withdrawal'].includes(settings.previewMode || 'dashboard') && (element.children || [])
						.map((childId) => $_getDynamicElementById(childId))
						.find((child) => child?.settings?.accountV2StateRole === (settings.previewMode || 'dashboard') || child?.name === ({
							'edit-address': 'woocommerce-account-v2-state-edit-address',
							'edit-account': 'woocommerce-account-v2-state-edit-account',
							login: 'woocommerce-account-v2-state-login',
							'lost-password': 'woocommerce-account-v2-state-lost-password',
							'order-withdrawal': 'woocommerce-account-v2-state-order-withdrawal',
							'reset-password': 'woocommerce-account-v2-state-reset-password'
						}[settings.previewMode || 'dashboard']))
						?.settings?.floatingLabelStyle) ? 'bricks-floating-label' : null
				]">
				<div
					:class="[
						'woocommerce',
						(['edit-address', 'edit-account', 'login', 'lost-password', 'reset-password', 'order-withdrawal'].includes(settings.previewMode || 'dashboard') && (element.children || [])
							.map((childId) => $_getDynamicElementById(childId))
							.find((child) => child?.settings?.accountV2StateRole === (settings.previewMode || 'dashboard') || child?.name === ({
								'edit-address': 'woocommerce-account-v2-state-edit-address',
								'edit-account': 'woocommerce-account-v2-state-edit-account',
								login: 'woocommerce-account-v2-state-login',
								'lost-password': 'woocommerce-account-v2-state-lost-password',
								'order-withdrawal': 'woocommerce-account-v2-state-order-withdrawal',
								'reset-password': 'woocommerce-account-v2-state-reset-password'
							}[settings.previewMode || 'dashboard']))
							?.settings?.floatingLabelStyle) ? 'bricks-floating-label' : null
					]">
					<template v-if="!settings.disableNav && !['login', 'lost-password', 'lost-password-confirmation', 'reset-password', 'order-withdrawal'].includes(settings.previewMode || 'dashboard')">
						<nav class="woocommerce-MyAccount-navigation" v-woo-account-preview="settings.previewMode">
							<?php woocommerce_account_navigation(); ?>
						</nav>

						<div class="woocommerce-MyAccount-content">
							<bricks-element-children
								:element="element"
								:parentComponent="component || parentComponent"
								:instanceId="instanceId"
								:loopId="loopId" />
						</div>
					</template>

					<bricks-element-children
						v-else
						:element="element"
						:parentComponent="component || parentComponent"
						:instanceId="instanceId"
						:loopId="loopId" />
				</div>
			</div>
		</script>
		<?php
	}
}

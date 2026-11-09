<?php
/**
 * WooCommerce abilities
 *
 * Setup helpers for WooCommerce pages/templates and an allow-listed WooCommerce
 * settings surface. The write path plans first, then applies only explicit page,
 * setting, and wizard operations with precondition checks.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooCommerce {
	const AREAS = [ 'shop', 'single_product', 'cart', 'checkout', 'my_account' ];

	const CLASSIC_PRESETS = [
		'shop'           => 'shop-v1',
		'single_product' => 'single-product-v1',
		'cart'           => 'cart-v1',
		'checkout'       => 'checkout-v1',
		'my_account'     => 'my-account-v1',
	];

	const ADVANCED_PRESETS = [
		'cart'       => 'cart-v2',
		'checkout'   => 'checkout-v2',
		'my_account' => 'my-account-v2',
	];

	const PAGE_MAP = [
		'shop'      => [
			'option'    => 'woocommerce_shop_page_id',
			'slug'      => 'shop',
			'shortcode' => '',
		],
		'cart'      => [
			'option'    => 'woocommerce_cart_page_id',
			'slug'      => 'cart',
			'shortcode' => 'woocommerce_cart',
		],
		'checkout'  => [
			'option'    => 'woocommerce_checkout_page_id',
			'slug'      => 'checkout',
			'shortcode' => 'woocommerce_checkout',
		],
		'myaccount' => [
			'option'    => 'woocommerce_myaccount_page_id',
			'slug'      => 'my-account',
			'shortcode' => 'woocommerce_my_account',
		],
	];

	const WC_SETTINGS = [
		'shopPageId'                   => [
			'option' => 'woocommerce_shop_page_id',
			'type'   => 'page_id'
		],
		'cartPageId'                   => [
			'option' => 'woocommerce_cart_page_id',
			'type'   => 'page_id'
		],
		'checkoutPageId'               => [
			'option' => 'woocommerce_checkout_page_id',
			'type'   => 'page_id'
		],
		'myAccountPageId'              => [
			'option' => 'woocommerce_myaccount_page_id',
			'type'   => 'page_id'
		],
		'enableCoupons'                => [
			'option' => 'woocommerce_enable_coupons',
			'type'   => 'bool_yes_no'
		],
		'enableGuestCheckout'          => [
			'option' => 'woocommerce_enable_guest_checkout',
			'type'   => 'bool_yes_no'
		],
		'enableCheckoutLoginReminder'  => [
			'option' => 'woocommerce_enable_checkout_login_reminder',
			'type'   => 'bool_yes_no'
		],
		'enableSignupAndLoginCheckout' => [
			'option' => 'woocommerce_enable_signup_and_login_from_checkout',
			'type'   => 'bool_yes_no'
		],
		'enableSignupFromCheckout'     => [
			'option' => 'woocommerce_enable_signup_from_checkout',
			'type'   => 'bool_yes_no'
		],
		'enableMyAccountRegistration'  => [
			'option' => 'woocommerce_enable_myaccount_registration',
			'type'   => 'bool_yes_no'
		],
		'enableAjaxAddToCartArchives'  => [
			'option' => 'woocommerce_enable_ajax_add_to_cart',
			'type'   => 'bool_yes_no'
		],
		'redirectToCartAfterAddToCart' => [
			'option' => 'woocommerce_cart_redirect_after_add',
			'type'   => 'bool_yes_no'
		],
		'forceSecureCheckout'          => [
			'option' => 'woocommerce_force_ssl_checkout',
			'type'   => 'bool_yes_no'
		],
	];

	/**
	 * Permission: read Woo setup/settings state.
	 *
	 * @since 2.4
	 */
	public static function read_permission( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Permission: mutate Woo setup/settings state.
	 *
	 * @since 2.4
	 */
	public static function write_permission( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Permission: run setup operations that may create/edit pages.
	 *
	 * @since 2.4
	 */
	public static function setup_permission( $input ) {
		$permission = self::write_permission( $input );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		if ( ! current_user_can( 'edit_pages' ) ) {
			return Error::forbidden_builder_permission( 'edit_pages' );
		}

		return true;
	}

	/**
	 * Input schema for get-woo-setup-status.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function get_woo_setup_status_schema() {
			return [
				'type'       => 'object',
				'properties' => [
					'areas' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
						],
						'description' => __( 'Woo areas to inspect. Defaults to all: shop, single_product, cart, checkout, my_account.', 'bricks' ),
					],
					'mode'  => [
						'type'        => 'string',
						'description' => __( 'Preset status to report. active follows current Bricks settings, classic reports v1, advanced reports v2 where available, all includes per-preset status when available.', 'bricks' ),
					],
				],
			];
	}

	/**
	 * Output schema for get-woo-setup-status.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function get_woo_setup_status_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'woocommerceActive'   => [ 'type' => 'boolean' ],
				'bricksWooSettings'   => [ 'type' => 'object' ],
				'woocommerceSettings' => [ 'type' => 'object' ],
				'areas'               => [ 'type' => 'object' ],
				'notes'               => [ 'type' => 'array' ],
			],
		];
	}

	/**
	 * Callback: get setup status.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_woo_setup_status( $input ) {
		Manager::flush_options_cache();

		$areas = self::normalize_areas( $input['areas'] ?? [] );
		$mode  = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'active';

		if ( is_wp_error( $areas ) ) {
			return $areas;
		}

		if ( ! in_array( $mode, [ 'active', 'classic', 'advanced', 'all' ], true ) ) {
			return Error::invalid_param( 'mode', 'active, classic, advanced, or all', $mode );
		}

		if ( ! self::woocommerce_active() ) {
			return [
				'woocommerceActive'   => false,
				'bricksWooSettings'   => self::bricks_woo_settings_snapshot(),
				'woocommerceSettings' => self::read_wc_settings(),
				'areas'               => [],
				'notes'               => [ 'WooCommerce is not active, or the Bricks WooCommerce builder integration is disabled.' ],
			];
		}

		$wizard = self::wizard();

		if ( ! $wizard ) {
			return Error::conflict(
				'woo_setup_wizard_unavailable',
				[
					'message' => 'The Bricks WooCommerce setup wizard is unavailable in this request.',
				]
			);
		}

		$status = [];

		foreach ( $areas as $area ) {
			$status[ $area ] = self::build_area_status( $wizard, $area, $mode );
		}

		return [
			'woocommerceActive'   => true,
			'bricksWooSettings'   => self::bricks_woo_settings_snapshot(),
			'woocommerceSettings' => self::read_wc_settings(),
			'areas'               => $status,
			'notes'               => [
				'Advanced modular WooCommerce elements are experimental and remain opt-in.',
				'Use bricks/plan-woo-setup before bricks/run-woo-setup to inspect page creation, assignment, overwrite, and template-drafting operations.',
			],
		];
	}

	/**
	 * Input schema for plan-woo-setup.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function plan_woo_setup_schema() {
		return [
			'type'       => 'object',
			'properties' => self::setup_schema_properties( false ),
		];
	}

	/**
	 * Output schema for plan-woo-setup.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function plan_woo_setup_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'planId'               => [ 'type' => 'string' ],
				'woocommerceActive'    => [ 'type' => 'boolean' ],
				'mode'                 => [ 'type' => 'string' ],
				'scope'                => [ 'type' => 'string' ],
				'selectedPresets'      => [ 'type' => 'object' ],
				'pagePlan'             => [ 'type' => 'object' ],
				'operations'           => [ 'type' => 'array' ],
				'destructiveActions'   => [ 'type' => 'array' ],
				'blockers'             => [ 'type' => 'array' ],
				'warnings'             => [ 'type' => 'array' ],
				'requiresConfirmation' => [ 'type' => 'boolean' ],
				'canRunWithoutChanges' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: plan Woo setup without writing.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function plan_woo_setup( $input ) {
		$setup = self::normalize_setup_input( $input );

		if ( is_wp_error( $setup ) ) {
			return $setup;
		}

		return self::build_setup_plan( $setup );
	}

	/**
	 * Input schema for run-woo-setup.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function run_woo_setup_schema() {
		$properties = self::setup_schema_properties( true );

		$properties['planId']                       = [
			'type'        => 'string',
			'description' => __( 'Optional planId from bricks/plan-woo-setup. If supplied, run aborts when the current site state no longer matches that plan.', 'bricks' ),
		];
		$properties['confirmDestructiveActions']    = [
			'type'        => 'boolean',
			'description' => __( 'Required when the plan will overwrite existing page content or draft existing published templates.', 'bricks' ),
		];
		$properties['overwriteExistingPageContent'] = [
			'type'        => 'boolean',
			'description' => __( 'Required, together with confirmDestructiveActions, when the plan would replace non-empty, block-based, or existing Bricks page content.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * Output schema for run-woo-setup.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function run_woo_setup_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'planId'            => [ 'type' => 'string' ],
				'appliedOperations' => [ 'type' => 'array' ],
				'createdPages'      => [ 'type' => 'array' ],
				'assignedPages'     => [ 'type' => 'array' ],
				'setupResults'      => [ 'type' => 'object' ],
				'finalStatus'       => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: run Woo setup.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function run_woo_setup( $input ) {
		$setup = self::normalize_setup_input( $input );

		if ( is_wp_error( $setup ) ) {
			return $setup;
		}

		$plan = self::build_setup_plan( $setup );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! empty( $input['planId'] ) && $input['planId'] !== $plan['planId'] ) {
			return Error::conflict(
				'woo_setup_plan_changed',
				[
					'message'        => 'Woo setup plan changed since it was inspected. Run bricks/plan-woo-setup again and review the new plan before applying.',
					'providedPlanId' => $input['planId'],
					'currentPlanId'  => $plan['planId'],
				]
			);
		}

		if ( ! empty( $plan['blockers'] ) ) {
			return Error::conflict(
				'woo_setup_blocked',
				[
					'message'  => 'Woo setup cannot run until the blockers in the plan are resolved.',
					'blockers' => $plan['blockers'],
				]
			);
		}

		$confirm_destructive  = ! empty( $input['confirmDestructiveActions'] );
		$allow_page_overwrite = ! empty( $input['overwriteExistingPageContent'] );

		if ( ! empty( $plan['destructiveActions'] ) && ! $confirm_destructive ) {
			return Error::conflict(
				'woo_setup_confirmation_required',
				[
					'message'            => 'This Woo setup plan has destructive actions. Re-run with confirmDestructiveActions=true after user confirmation.',
					'destructiveActions' => $plan['destructiveActions'],
				]
			);
		}

		if ( self::plan_has_page_overwrite( $plan ) && ! $allow_page_overwrite ) {
			return Error::conflict(
				'woo_setup_page_overwrite_required',
				[
					'message'            => 'This Woo setup plan replaces existing page content. Re-run with overwriteExistingPageContent=true after user confirmation.',
					'destructiveActions' => $plan['destructiveActions'],
				]
			);
		}

		$applied              = [];
		$created_pages        = [];
		$assigned_pages       = [];
		$refresh_woo_elements = false;

		foreach ( $plan['operations'] as $operation ) {
			if ( ( $operation['type'] ?? '' ) !== 'set_bricks_setting' ) {
				continue;
			}

			$result = Settings::set_global_settings( [ 'settings' => [ $operation['key'] => $operation['value'] ] ] );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$applied[] = $operation;

			if ( in_array( $operation['key'], [ 'woocommerceUseAdvancedModularElements', 'woocommerceUseBricksWooNotice', 'woocommerceUseBricksWooCheckoutCoupon', 'woocommerceUseBricksWooCheckoutLogin' ], true ) ) {
				$refresh_woo_elements = true;
			}
		}

		if ( $refresh_woo_elements ) {
			self::refresh_woocommerce_element_registry();
		}

		foreach ( $plan['pagePlan'] as $wc_page => $page_plan ) {
			$action = $page_plan['action'] ?? '';

			if ( $action === 'create_page' ) {
				$page_id = self::create_wc_page( $wc_page );

				if ( is_wp_error( $page_id ) ) {
					return $page_id;
				}

				self::assign_wc_page( $wc_page, $page_id );

				$created_pages[]  = [
					'wcPage' => $wc_page,
					'pageId' => $page_id,
				];
				$assigned_pages[] = [
					'wcPage' => $wc_page,
					'pageId' => $page_id,
				];
				continue;
			}

			if ( $action === 'reuse_existing_page' && ! empty( $page_plan['page']['id'] ) ) {
				$page_id = (int) $page_plan['page']['id'];
				self::assign_wc_page( $wc_page, $page_id );
				$assigned_pages[] = [
					'wcPage' => $wc_page,
					'pageId' => $page_id,
				];
			}
		}

		$wizard        = self::wizard();
		$setup_results = [];

		foreach ( $plan['selectedPresets'] as $area => $preset_id ) {
			$result = $wizard->run_setup(
				$area,
				$preset_id,
				$confirm_destructive && $allow_page_overwrite,
				$setup['scope'],
				$setup['templateType'],
				$setup['templateMode']
			);

			if ( is_wp_error( $result ) ) {
				return self::wrap_wizard_error( $result, $area );
			}

			$setup_results[ $area ] = $result;
			$applied[]              = [
				'type'     => 'run_area_setup',
				'area'     => $area,
				'presetId' => $preset_id,
				'scope'    => $setup['scope'],
			];
		}

		$final_status = self::get_woo_setup_status(
			[
				'areas' => $setup['areas'],
				'mode'  => $setup['mode'] === 'advanced' ? 'advanced' : 'active',
			]
		);

		return [
			'planId'            => $plan['planId'],
			'appliedOperations' => $applied,
			'createdPages'      => $created_pages,
			'assignedPages'     => $assigned_pages,
			'setupResults'      => $setup_results,
			'finalStatus'       => $final_status,
		];
	}

	/**
	 * Input schema for get-woo-setup-options.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function get_woocommerce_settings_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'keys' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional friendly WooCommerce setting keys to return.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for get-woo-setup-options.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function get_woocommerce_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [ 'type' => 'object' ],
				'missing'  => [ 'type' => 'array' ],
			],
		];
	}

	/**
	 * Callback: read allow-listed WooCommerce options.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_woocommerce_settings( $input ) {
		$requested = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_map( 'strval', $input['keys'] ) ) : [];
		$current   = self::read_wc_settings();
		$settings  = [];
		$missing   = [];

		foreach ( $requested ? $requested : array_keys( self::WC_SETTINGS ) as $key ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$missing[] = $key;
				continue;
			}

			$settings[ $key ] = $current[ $key ];
		}

		return [
			'settings' => $settings,
			'missing'  => $missing,
		];
	}

	/**
	 * Input schema for set-woo-setup-options.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function set_woocommerce_settings_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'Map of friendly WooCommerce setting keys to values. Page IDs must reference existing non-trashed pages, or 0/null/empty string to clear the assignment. Boolean settings are stored as WooCommerce yes/no options.', 'bricks' ),
					'additionalProperties' => true,
				],
			],
			'required'   => [ 'settings' ],
		];
	}

	/**
	 * Output schema for set-woo-setup-options.
	 *
	 * @since 2.4
	 * @return array
	 */
	public static function set_woocommerce_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'updated'        => [ 'type' => 'array' ],
				'unchanged'      => [ 'type' => 'array' ],
				'beforeSnapshot' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: partial-merge WooCommerce settings.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_woocommerce_settings( $input ) {
		if ( ! isset( $input['settings'] ) || ! is_array( $input['settings'] ) || empty( $input['settings'] ) ) {
			return Error::missing_param( 'settings', [ 'settings' ] );
		}

		$current         = self::read_wc_settings();
		$updated         = [];
		$unchanged       = [];
		$before_snapshot = [];

		foreach ( $input['settings'] as $key => $value ) {
			if ( ! isset( self::WC_SETTINGS[ $key ] ) ) {
				return Error::setting_unknown(
					$key,
					array_keys( self::WC_SETTINGS ),
					'Call `bricks/get-woo-setup-options` without keys to see accepted WooCommerce core setup option keys.'
				);
			}

			$meta       = self::WC_SETTINGS[ $key ];
			$normalized = self::normalize_wc_setting_value( $key, $value, $meta );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$before_snapshot[ $key ] = $current[ $key ] ?? null;

			if ( ( $current[ $key ] ?? null ) === $normalized ) {
				$unchanged[] = $key;
				continue;
			}

			update_option( $meta['option'], $meta['type'] === 'bool_yes_no' ? ( $normalized ? 'yes' : 'no' ) : $normalized );
			$updated[] = $key;
		}

		Manager::flush_options_cache();

		return [
			'updated'        => $updated,
			'unchanged'      => $unchanged,
			'beforeSnapshot' => $before_snapshot,
		];
	}

	/**
	 * Shared setup schema properties.
	 *
	 * @since 2.4
	 * @param bool $write Whether properties are for the write schema.
	 * @return array
	 */
	private static function setup_schema_properties( $write ) {
		return [
			'areas'                         => [
				'type'        => 'array',
				'items'       => [
					'type' => 'string',
				],
				'description' => __( 'Woo areas to set up. Defaults to all: shop, single_product, cart, checkout, my_account.', 'bricks' ),
			],
			'mode'                          => [
				'type'        => 'string',
				'description' => __( 'classic uses v1 templates/pages. advanced uses v2 modular pages where available and classic for shop/single product. active follows current Bricks settings. Defaults to classic because v2 is experimental.', 'bricks' ),
			],
			'presetOverrides'               => [
				'type'                 => 'object',
				'description'          => __( 'Optional area => preset ID overrides, for example { "checkout": "checkout-v2-multistep" }.', 'bricks' ),
				'additionalProperties' => true,
			],
			'scope'                         => [
				'type'        => 'string',
				'description' => __( 'Setup scope. Defaults to all.', 'bricks' ),
			],
			'templateType'                  => [
				'type'        => 'string',
				'description' => __( 'When scope=template, optional specific template type to generate for each selected preset.', 'bricks' ),
			],
			'createMissingPages'            => [
				'type'        => 'boolean',
				'description' => __( 'Create missing WooCommerce pages when no safe existing matching page can be reused. Defaults to true.', 'bricks' ),
			],
			'reuseExistingPages'            => [
				'type'        => 'boolean',
				'description' => __( 'When a Woo page option is empty, reuse a matching existing page only if it is empty or shortcode-only. Defaults to true.', 'bricks' ),
			],
			'enableAdvancedModularElements' => [
				'type'        => 'boolean',
				'description' => __( 'Allow the run to enable Bricks advanced modular WooCommerce elements when advanced presets are selected. The feature is experimental; agents should get user confirmation first.', 'bricks' ),
			],
			'templateMode'                  => [
				'type'        => 'string',
				'enum'        => [ 'reuse', 'replace', 'duplicate' ],
				'description' => __( 'How to handle existing published templates for each area. `reuse` (default) returns the existing template id without creating a new one. `replace` drafts the existing template then creates a fresh one. `duplicate` creates a numbered copy (legacy behavior).', 'bricks' ),
			],
		];
	}

	/**
	 * Normalize setup input.
	 *
	 * @since 2.4
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function normalize_setup_input( $input ) {
		$areas = self::normalize_areas( $input['areas'] ?? [] );

		if ( is_wp_error( $areas ) ) {
			return $areas;
		}

		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'classic';
		if ( ! in_array( $mode, [ 'classic', 'advanced', 'active' ], true ) ) {
			return Error::invalid_param( 'mode', 'classic, advanced, or active', $mode );
		}

		$scope = isset( $input['scope'] ) ? sanitize_key( $input['scope'] ) : 'all';
		if ( ! in_array( $scope, [ 'all', 'page', 'template' ], true ) ) {
			return Error::invalid_param( 'scope', 'all, page, or template', $scope );
		}

		$template_mode = isset( $input['templateMode'] ) ? sanitize_key( $input['templateMode'] ) : 'reuse';
		if ( ! in_array( $template_mode, [ 'reuse', 'replace', 'duplicate' ], true ) ) {
			return Error::invalid_param( 'templateMode', 'reuse, replace, or duplicate', $template_mode );
		}

		$overrides = [];
		if ( isset( $input['presetOverrides'] ) ) {
			if ( ! is_array( $input['presetOverrides'] ) ) {
				return Error::invalid_param( 'presetOverrides', 'object', $input['presetOverrides'] );
			}

			foreach ( $input['presetOverrides'] as $area => $preset_id ) {
				$area = self::normalize_area( $area );

				if ( ! in_array( $area, self::AREAS, true ) ) {
					return Error::invalid_param( 'presetOverrides', 'known setup areas', $area );
				}

				$overrides[ $area ] = sanitize_key( (string) $preset_id );
			}
		}

		return [
			'areas'                         => $areas,
			'mode'                          => $mode,
			'scope'                         => $scope,
			'templateType'                  => isset( $input['templateType'] ) ? sanitize_key( $input['templateType'] ) : '',
			'presetOverrides'               => $overrides,
			'createMissingPages'            => ! array_key_exists( 'createMissingPages', $input ) || (bool) $input['createMissingPages'],
			'reuseExistingPages'            => ! array_key_exists( 'reuseExistingPages', $input ) || (bool) $input['reuseExistingPages'],
			'enableAdvancedModularElements' => ! empty( $input['enableAdvancedModularElements'] ),
			'templateMode'                  => $template_mode,
		];
	}

	/**
	 * Build a setup plan.
	 *
	 * @since 2.4
	 * @param array $setup Normalized setup input.
	 * @return array|\WP_Error
	 */
	private static function build_setup_plan( array $setup ) {
		Manager::flush_options_cache();

		$operations          = [];
		$destructive_actions = [];
		$blockers            = [];
		$warnings            = [];
		$page_plan           = [];
		$selected_presets    = [];

		if ( ! self::woocommerce_active() ) {
			$blockers[] = [
				'code'    => 'woocommerce_inactive',
				'message' => 'WooCommerce is not active, or the Bricks WooCommerce builder integration is disabled.',
			];
		}

		$wizard = self::wizard();

		foreach ( $setup['areas'] as $area ) {
			$preset_id = self::select_preset_id( $area, $setup['mode'], $setup['presetOverrides'], $wizard );
			$preset    = $wizard ? $wizard->get_setup_preset( $area, $preset_id, true ) : null;

			if ( ! $preset ) {
				$blockers[] = [
					'code'     => 'preset_not_found',
					'area'     => $area,
					'presetId' => $preset_id,
					'message'  => 'No WooCommerce setup preset exists for this area/mode.',
				];
				continue;
			}

			$preset_id                  = $preset['id'] ?? $preset_id;
			$selected_presets[ $area ]  = $preset_id;
			$requires_advanced          = ! empty( $preset['requiresAdvancedModularElements'] );
			$advanced_currently_enabled = \Bricks\Woocommerce::use_advanced_modular_elements();

			if ( $requires_advanced && ! $advanced_currently_enabled ) {
				$warnings[] = [
					'code'    => 'advanced_modular_experimental',
					'area'    => $area,
					'message' => 'Advanced modular WooCommerce elements are experimental and must be explicitly enabled before v2 setup can run.',
				];

				if ( $setup['enableAdvancedModularElements'] && ! self::has_setting_operation( $operations, 'woocommerceUseAdvancedModularElements' ) ) {
					$operations[] = [
						'type'  => 'set_bricks_setting',
						'key'   => 'woocommerceUseAdvancedModularElements',
						'value' => true,
						'note'  => 'Enables experimental Cart v2, Checkout v2, and Account Page v2 element registration for this setup run.',
					];
				} else {
					$blockers[] = [
						'code'     => 'advanced_modular_disabled',
						'area'     => $area,
						'presetId' => $preset_id,
						'message'  => 'This preset requires Bricks advanced modular WooCommerce elements. Re-run with enableAdvancedModularElements=true after user confirmation, or choose classic mode.',
					];
				}
			}

			foreach ( self::required_bricks_settings_for_preset( $preset ) as $setting_key => $setting_note ) {
				if ( ! self::setting_truthy_in_snapshot( $setting_key ) && ! self::has_setting_operation( $operations, $setting_key ) ) {
					$operations[] = [
						'type'  => 'set_bricks_setting',
						'key'   => $setting_key,
						'value' => true,
						'note'  => $setting_note,
					];
					$warnings[]   = [
						'code'    => 'bricks_woo_setting_required',
						'area'    => $area,
						'key'     => $setting_key,
						'message' => $setting_note,
					];
				}
			}

			$wc_page = $preset['page']['wcPage'] ?? '';
			if ( $setup['scope'] !== 'template' && $wc_page && ( $area === 'shop' || ! empty( $preset['requiresPageEdit'] ) ) ) {
				$page_plan[ $wc_page ] = self::plan_wc_page( $wc_page, $setup, ! empty( $preset['requiresPageEdit'] ) );

				if ( ! empty( $page_plan[ $wc_page ]['operation'] ) ) {
					$operations[] = $page_plan[ $wc_page ]['operation'];
				}

				if ( ! empty( $page_plan[ $wc_page ]['destructiveAction'] ) ) {
					$destructive_actions[] = $page_plan[ $wc_page ]['destructiveAction'];
				}

				if ( ! empty( $page_plan[ $wc_page ]['blocker'] ) ) {
					$blockers[] = $page_plan[ $wc_page ]['blocker'];
				}
			}

			if ( $setup['scope'] !== 'page' ) {
				$template_action = self::plan_template_drafting( $area, $preset );

				if ( ! empty( $template_action ) ) {
					$operations[]          = $template_action['operation'];
					$destructive_actions[] = $template_action['destructiveAction'];
				}
			}

			$operations[] = [
				'type'     => 'run_area_setup',
				'area'     => $area,
				'presetId' => $preset_id,
				'scope'    => $setup['scope'],
			];
		}

		$plan_fingerprint = [
			'setup'              => $setup,
			'selectedPresets'    => $selected_presets,
			'pagePlan'           => $page_plan,
			'operations'         => $operations,
			'destructiveActions' => $destructive_actions,
			'blockers'           => $blockers,
		];

		$plan_id = md5( wp_json_encode( $plan_fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return [
			'planId'               => $plan_id,
			'woocommerceActive'    => empty( $blockers ) || ! self::has_blocker( $blockers, 'woocommerce_inactive' ),
			'mode'                 => $setup['mode'],
			'scope'                => $setup['scope'],
			'selectedPresets'      => $selected_presets,
			'pagePlan'             => $page_plan,
			'operations'           => $operations,
			'destructiveActions'   => $destructive_actions,
			'blockers'             => $blockers,
			'warnings'             => $warnings,
			'requiresConfirmation' => ! empty( $destructive_actions ),
			'canRunWithoutChanges' => empty( $operations ),
		];
	}

	/**
	 * Build status for one setup area.
	 *
	 * @since 2.4
	 * @param \Bricks\Woo_Setup_Wizard $wizard Wizard instance.
	 * @param string                   $area   Setup area.
	 * @param string                   $mode   Status mode.
	 * @return array
	 */
	private static function build_area_status( $wizard, $area, $mode ) {
		$preset_id = '';

		if ( $mode === 'classic' ) {
			$preset_id = self::CLASSIC_PRESETS[ $area ] ?? '';
		} elseif ( $mode === 'advanced' ) {
			$preset_id = self::ADVANCED_PRESETS[ $area ] ?? ( self::CLASSIC_PRESETS[ $area ] ?? '' );
		}

		$status = $wizard->get_area_status( $area, $preset_id );
		$page   = self::status_page_for_area( $area, $status );

		$row = [
			'area'           => $area,
			'selectedPreset' => $status['selected_preset_key'] ?? ( $status['preset_key'] ?? null ),
			'status'         => $status,
			'page'           => $page,
			'presets'        => $wizard->get_setup_preset_options( $area ),
		];

		if ( $mode === 'all' ) {
			$row['presetStatuses'] = [];
			foreach ( $row['presets'] as $preset ) {
				if ( ! empty( $preset['disabled'] ) ) {
					$row['presetStatuses'][ $preset['id'] ] = [
						'available' => false,
						'reason'    => $preset['disabled_reason'] ?? '',
					];
					continue;
				}

				$row['presetStatuses'][ $preset['id'] ] = [
					'available' => true,
					'status'    => $wizard->get_area_status( $area, $preset['id'] ),
				];
			}
		}

		return $row;
	}

	/**
	 * Plan Woo page assignment/creation.
	 *
	 * @since 2.4
	 * @param string $wc_page            WooCommerce page key.
	 * @param array  $setup              Normalized setup input.
	 * @param bool   $will_replace_page  Whether the preset writes Bricks content to the page.
	 * @return array
	 */
	private static function plan_wc_page( $wc_page, array $setup, $will_replace_page ) {
		$page_id = self::wc_page_id( $wc_page );
		$page    = $page_id ? get_post( $page_id ) : null;
		$plan    = [
			'wcPage'          => $wc_page,
			'action'          => 'none',
			'willReplacePage' => (bool) $will_replace_page,
			'page'            => $page ? self::page_summary( $page, $wc_page ) : null,
		];

		if ( $page && $page->post_status !== 'trash' ) {
			if ( $will_replace_page ) {
				$destructive = self::page_replace_risk( $page, $wc_page );

				if ( $destructive ) {
					$plan['destructiveAction'] = $destructive;
				}
			}

			return $plan;
		}

		if ( $page && $page->post_status === 'trash' ) {
			$plan['blocker'] = [
				'code'    => 'assigned_page_in_trash',
				'wcPage'  => $wc_page,
				'pageId'  => (int) $page->ID,
				'message' => 'The assigned WooCommerce page is in the trash. Restore it or assign a different page before setup.',
			];

			return $plan;
		}

		$candidate = $setup['reuseExistingPages'] ? self::find_safe_existing_wc_page( $wc_page ) : null;

		if ( $candidate ) {
			$plan['action']    = 'reuse_existing_page';
			$plan['page']      = self::page_summary( $candidate, $wc_page );
			$plan['operation'] = [
				'type'   => 'assign_woocommerce_page',
				'action' => 'reuse_existing_page',
				'wcPage' => $wc_page,
				'pageId' => (int) $candidate->ID,
			];

			return $plan;
		}

		if ( $setup['createMissingPages'] ) {
			$plan['action']    = 'create_page';
			$plan['operation'] = [
				'type'   => 'assign_woocommerce_page',
				'action' => 'create_page',
				'wcPage' => $wc_page,
				'title'  => self::wc_page_title( $wc_page ),
				'slug'   => self::PAGE_MAP[ $wc_page ]['slug'] ?? $wc_page,
			];

			return $plan;
		}

		$plan['blocker'] = [
			'code'    => 'missing_woocommerce_page',
			'wcPage'  => $wc_page,
			'message' => 'No WooCommerce page is assigned and createMissingPages is false.',
		];

		return $plan;
	}

	/**
	 * Detect template drafting side effects.
	 *
	 * @since 2.4
	 * @param string $area   Setup area.
	 * @param array  $preset Setup preset.
	 * @return array|null
	 */
	private static function plan_template_drafting( $area, array $preset ) {
		if ( empty( $preset['shouldDraftExisting'] ) || empty( $preset['templates'] ) || ! is_array( $preset['templates'] ) ) {
			return null;
		}

		$template_types = array_values(
			array_unique(
				array_filter(
					array_map(
						static function( $template ) {
							return is_array( $template ) && ! empty( $template['type'] ) ? $template['type'] : '';
						},
						$preset['templates']
					)
				)
			)
		);

		$published = [];

		foreach ( $template_types as $type ) {
			foreach ( \Bricks\Templates::get_templates_by_type( $type ) as $template_id ) {
				$post = get_post( $template_id );

				if ( $post && $post->post_status === 'publish' ) {
					$published[] = [
						'id'    => (int) $post->ID,
						'title' => $post->post_title,
						'type'  => $type,
					];
				}
			}
		}

		if ( empty( $published ) ) {
			return null;
		}

		return [
			'operation'         => [
				'type'      => 'draft_existing_templates',
				'area'      => $area,
				'presetId'  => $preset['id'] ?? '',
				'templates' => $published,
			],
			'destructiveAction' => [
				'type'      => 'draft_existing_templates',
				'area'      => $area,
				'message'   => 'Existing published WooCommerce templates matching this preset will be moved to draft before new templates are created.',
				'templates' => $published,
			],
		];
	}

	/**
	 * Choose a preset for an area/mode.
	 *
	 * @since 2.4
	 * @param string                   $area      Setup area.
	 * @param string                   $mode      Setup mode.
	 * @param array                    $overrides Area=>preset overrides.
	 * @param \Bricks\Woo_Setup_Wizard $wizard    Wizard instance.
	 * @return string
	 */
	private static function select_preset_id( $area, $mode, array $overrides, $wizard ) {
		if ( ! empty( $overrides[ $area ] ) ) {
			return $overrides[ $area ];
		}

		if ( $mode === 'advanced' && ! empty( self::ADVANCED_PRESETS[ $area ] ) ) {
			return self::ADVANCED_PRESETS[ $area ];
		}

		if ( $mode === 'active' && $wizard ) {
			$preset = $wizard->get_setup_preset( $area );

			if ( is_array( $preset ) && ! empty( $preset['id'] ) ) {
				return $preset['id'];
			}
		}

		return self::CLASSIC_PRESETS[ $area ] ?? '';
	}

	/**
	 * Normalize setup areas.
	 *
	 * @since 2.4
	 * @param mixed $areas Area input.
	 * @return string[]|\WP_Error
	 */
	private static function normalize_areas( $areas ) {
		if ( empty( $areas ) ) {
			return self::AREAS;
		}

		if ( is_string( $areas ) ) {
			$areas = [ $areas ];
		}

		if ( ! is_array( $areas ) ) {
			return Error::invalid_param( 'areas', 'array of setup area strings', $areas );
		}

		$normalized = [];

		foreach ( $areas as $area ) {
			$area = self::normalize_area( $area );

			if ( ! in_array( $area, self::AREAS, true ) ) {
				return Error::invalid_param( 'areas', 'one of: ' . implode( ', ', self::AREAS ), $area );
			}

			$normalized[] = $area;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize common area aliases.
	 *
	 * @since 2.4
	 * @param mixed $area Area input.
	 * @return string
	 */
	private static function normalize_area( $area ) {
		$area = sanitize_key( (string) $area );
		$area = str_replace( '-', '_', $area );

		if ( $area === 'myaccount' ) {
			return 'my_account';
		}

		if ( $area === 'single_product_template' ) {
			return 'single_product';
		}

		return $area;
	}

	/**
	 * Get the existing wizard instance.
	 *
	 * @since 2.4
	 * @return \Bricks\Woo_Setup_Wizard|null
	 */
	private static function wizard() {
		if ( isset( \Bricks\Theme::$instance->woo_setup_wizard ) && \Bricks\Theme::$instance->woo_setup_wizard instanceof \Bricks\Woo_Setup_Wizard ) {
			return \Bricks\Theme::$instance->woo_setup_wizard;
		}

		if ( class_exists( '\Bricks\Woo_Setup_Wizard' ) ) {
			return new \Bricks\Woo_Setup_Wizard();
		}

		return null;
	}

	/**
	 * Is WooCommerce usable through Bricks?
	 *
	 * @since 2.4
	 * @return bool
	 */
	private static function woocommerce_active() {
		return class_exists( '\woocommerce' ) && class_exists( '\Bricks\Woocommerce' ) && \Bricks\Woocommerce::is_woocommerce_active();
	}

	/**
	 * Read Bricks Woo settings useful to agents.
	 *
	 * @since 2.4
	 * @return array
	 */
	private static function bricks_woo_settings_snapshot() {
		Manager::flush_options_cache();

		$result = Settings::get_global_settings(
			[
				'keys' => [
					'woocommerceDisableBuilder',
					'woocommerceUseAdvancedModularElements',
					'woocommerceUseBricksWooNotice',
					'woocommerceUseBricksWooCheckoutCoupon',
					'woocommerceUseBricksWooCheckoutLogin',
					'woocommerceEnableAjaxAddToCart',
				],
			]
		);

		return is_array( $result ) ? ( $result['settings'] ?? [] ) : [];
	}

	/**
	 * Whether a Bricks global setting is truthy in the current snapshot.
	 *
	 * @since 2.4
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function setting_truthy_in_snapshot( $key ) {
		$settings = self::bricks_woo_settings_snapshot();

		return ! empty( $settings[ $key ] );
	}

	/**
	 * Bricks Woo element settings required by a setup preset.
	 *
	 * The setup presets intentionally insert Bricks-specific notice/coupon/login
	 * elements. Enabling the matching setting before the save keeps the element
	 * registered in the current request and renderable on the frontend.
	 *
	 * @since 2.4
	 * @param array $preset Setup preset.
	 * @return array Setting key => reason.
	 */
	private static function required_bricks_settings_for_preset( array $preset ) {
		$required = [];
		$area     = sanitize_key( $preset['area'] ?? '' );
		$encoded  = wp_json_encode( $preset );

		// Match the wizard: advanced checkout references state presets containing coupon/login. (#123pt1p0yz8; @since 2.4.5)
		$is_advanced_checkout = $area === 'checkout' && ( $preset['mode'] ?? '' ) === 'advanced';

		if ( in_array( $area, [ 'shop', 'cart', 'checkout', 'my_account' ], true ) || ( $preset['mode'] ?? '' ) === 'advanced' ) {
			$required['woocommerceUseBricksWooNotice'] = 'This setup inserts Bricks WooCommerce Notice elements, so the Bricks notice element setting must be enabled.';
		}

		if ( $is_advanced_checkout || ( is_string( $encoded ) && strpos( $encoded, '"name":"woocommerce-checkout-coupon"' ) !== false ) ) {
			$required['woocommerceUseBricksWooCheckoutCoupon'] = 'This setup inserts the Bricks Checkout Coupon element, so the Bricks checkout coupon setting must be enabled.';
		}

		if ( $is_advanced_checkout || ( is_string( $encoded ) && strpos( $encoded, '"name":"woocommerce-checkout-login"' ) !== false ) ) {
			$required['woocommerceUseBricksWooCheckoutLogin'] = 'This setup inserts the Bricks Checkout Login element, so the Bricks checkout login setting must be enabled.';
		}

		return $required;
	}

	/**
	 * Whether an operation list already includes a Bricks setting write.
	 *
	 * @since 2.4
	 * @param array  $operations Operations.
	 * @param string $key        Setting key.
	 * @return bool
	 */
	private static function has_setting_operation( array $operations, $key ) {
		foreach ( $operations as $operation ) {
			if ( ( $operation['type'] ?? '' ) === 'set_bricks_setting' && ( $operation['key'] ?? '' ) === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh Woo element registration after enabling Bricks Woo element gates.
	 *
	 * @since 2.4
	 * @return void
	 */
	private static function refresh_woocommerce_element_registry() {
		if ( isset( \Bricks\Theme::$instance->woocommerce ) && \Bricks\Theme::$instance->woocommerce instanceof \Bricks\Woocommerce ) {
			\Bricks\Theme::$instance->woocommerce->init_elements();
		}
	}

	/**
	 * Read allow-listed WooCommerce settings.
	 *
	 * @since 2.4
	 * @return array
	 */
	private static function read_wc_settings() {
		$settings = [];

		foreach ( self::WC_SETTINGS as $key => $meta ) {
			$value = get_option( $meta['option'], null );

			if ( $meta['type'] === 'bool_yes_no' ) {
				$settings[ $key ] = $value === 'yes';
			} elseif ( $meta['type'] === 'page_id' ) {
				$settings[ $key ] = absint( $value );
			} else {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Normalize one WooCommerce settings value.
	 *
	 * @since 2.4
	 * @param string $key   Friendly setting key.
	 * @param mixed  $value Incoming value.
	 * @param array  $meta  Registry row.
	 * @return mixed|\WP_Error
	 */
	private static function normalize_wc_setting_value( $key, $value, array $meta ) {
		if ( $meta['type'] === 'bool_yes_no' ) {
			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( in_array( $value, [ 1, '1', 'yes', 'true' ], true ) ) {
				return true;
			}

			if ( in_array( $value, [ 0, '0', 'no', 'false' ], true ) ) {
				return false;
			}

			return Error::invalid_param( $key, 'boolean', $value );
		}

		if ( $meta['type'] === 'page_id' ) {
			if ( $value === null || $value === '' ) {
				return 0;
			}

			if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
				return Error::invalid_param( $key, 'page ID integer', $value );
			}

			$page_id = (int) $value;

			if ( $page_id === 0 ) {
				return 0;
			}

			$post = get_post( $page_id );

			if ( ! $post || $post->post_type !== 'page' || $post->post_status === 'trash' ) {
				return Error::invalid_param( $key, 'existing non-trashed page ID, or 0 to clear', $value );
			}

			return $page_id;
		}

		return $value;
	}

	/**
	 * Get the localized title for a WooCommerce page.
	 *
	 * @since 2.4
	 *
	 * @param string $wc_page WooCommerce page key.
	 * @return string
	 */
	private static function wc_page_title( $wc_page ) {
		$titles = [
			'shop'      => __( 'Shop', 'bricks' ),
			'cart'      => __( 'Cart', 'bricks' ),
			'checkout'  => __( 'Checkout', 'bricks' ),
			'myaccount' => __( 'My account', 'bricks' ),
		];

		return $titles[ $wc_page ] ?? $wc_page;
	}

	/**
	 * Get WooCommerce page ID.
	 *
	 * @since 2.4
	 * @param string $wc_page WooCommerce page key.
	 * @return int
	 */
	private static function wc_page_id( $wc_page ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return 0;
		}

		$page_id = wc_get_page_id( $wc_page );

		return $page_id > 0 ? (int) $page_id : 0;
	}

	/**
	 * Assign a WooCommerce page option.
	 *
	 * @since 2.4
	 * @param string $wc_page WooCommerce page key.
	 * @param int    $page_id Page ID.
	 * @return void
	 */
	private static function assign_wc_page( $wc_page, $page_id ) {
		if ( empty( self::PAGE_MAP[ $wc_page ]['option'] ) ) {
			return;
		}

		update_option( self::PAGE_MAP[ $wc_page ]['option'], (int) $page_id );
	}

	/**
	 * Create a missing WooCommerce page.
	 *
	 * @since 2.4
	 * @param string $wc_page WooCommerce page key.
	 * @return int|\WP_Error
	 */
	private static function create_wc_page( $wc_page ) {
		if ( empty( self::PAGE_MAP[ $wc_page ] ) ) {
			return Error::invalid_param( 'wcPage', 'known WooCommerce page key', $wc_page );
		}

		$meta    = self::PAGE_MAP[ $wc_page ];
		$content = ! empty( $meta['shortcode'] ) ? '[' . $meta['shortcode'] . ']' : '';
		$page_id = wp_insert_post(
			[
				'post_title'   => self::wc_page_title( $wc_page ),
				'post_name'    => $meta['slug'],
				'post_type'    => 'page',
				'post_status'  => current_user_can( 'publish_pages' ) ? 'publish' : 'draft',
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		if ( ! $page_id ) {
			return Error::conflict(
				'woo_page_create_failed',
				[
					'message' => sprintf( 'Failed to create WooCommerce page for %s.', $wc_page ),
					'wcPage'  => $wc_page,
				]
			);
		}

		return (int) $page_id;
	}

	/**
	 * Find an existing page safe to reuse for a missing Woo assignment.
	 *
	 * @since 2.4
	 * @param string $wc_page WooCommerce page key.
	 * @return \WP_Post|null
	 */
	private static function find_safe_existing_wc_page( $wc_page ) {
		if ( empty( self::PAGE_MAP[ $wc_page ] ) ) {
			return null;
		}

		$meta       = self::PAGE_MAP[ $wc_page ];
		$candidates = [];
		$by_path    = get_page_by_path( $meta['slug'], OBJECT, 'page' );

		if ( $by_path instanceof \WP_Post ) {
			$candidates[] = $by_path;
		}

		$query = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'title'          => self::wc_page_title( $wc_page ),
				'posts_per_page' => 5,
			]
		);

		foreach ( $query as $candidate ) {
			$candidates[] = $candidate;
		}

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof \WP_Post || $candidate->post_status === 'trash' ) {
				continue;
			}

			if ( self::page_is_safe_to_reuse( $candidate, $wc_page ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether an unassigned page can be reused without data loss.
	 *
	 * @since 2.4
	 * @param \WP_Post $post    Page post.
	 * @param string   $wc_page WooCommerce page key.
	 * @return bool
	 */
	private static function page_is_safe_to_reuse( \WP_Post $post, $wc_page ) {
		$state = self::page_content_state( $post, $wc_page );

		return empty( $state['hasBricksData'] ) && in_array( $state['contentState'], [ 'empty', 'shortcode_only' ], true );
	}

	/**
	 * Detect whether replacing a page needs explicit confirmation.
	 *
	 * @since 2.4
	 * @param \WP_Post $post    Page post.
	 * @param string   $wc_page WooCommerce page key.
	 * @return array|null
	 */
	private static function page_replace_risk( \WP_Post $post, $wc_page ) {
		$state   = self::page_content_state( $post, $wc_page );
		$reasons = [];

		if ( ! empty( $state['hasBricksData'] ) ) {
			$reasons[] = 'existing_bricks_data';
		}

		if ( ! empty( $state['hasWooBlocks'] ) ) {
			$reasons[] = 'woocommerce_blocks';
		}

		if ( $state['contentState'] === 'non_empty' ) {
			$reasons[] = 'non_empty_post_content';
		}

		if ( empty( $reasons ) ) {
			return null;
		}

		return [
			'type'    => 'replace_page_content',
			'wcPage'  => $wc_page,
			'pageId'  => (int) $post->ID,
			'title'   => $post->post_title,
			'reasons' => $reasons,
			'message' => 'Woo setup will replace existing page content with Bricks WooCommerce elements.',
		];
	}

	/**
	 * Summarize page content state.
	 *
	 * @since 2.4
	 * @param \WP_Post $post    Page post.
	 * @param string   $wc_page WooCommerce page key.
	 * @return array
	 */
	private static function page_content_state( \WP_Post $post, $wc_page ) {
		$content       = trim( (string) $post->post_content );
		$shortcode     = self::PAGE_MAP[ $wc_page ]['shortcode'] ?? '';
		$content_state = 'non_empty';

		if ( $content === '' ) {
			$content_state = 'empty';
		} elseif ( $shortcode && preg_match( '/^\s*\[' . preg_quote( $shortcode, '/' ) . '(?:\s+[^\]]*)?\]\s*$/', $content ) ) {
			$content_state = 'shortcode_only';
		}

		return [
			'contentState'  => $content_state,
			'hasWooBlocks'  => strpos( $content, '<!-- wp:woocommerce/' ) !== false,
			'hasBricksData' => ! empty( get_post_meta( $post->ID, BRICKS_DB_PAGE_CONTENT, true ) ),
			'editorMode'    => get_post_meta( $post->ID, BRICKS_DB_EDITOR_MODE, true ),
		];
	}

	/**
	 * Page summary for API output.
	 *
	 * @since 2.4
	 * @param \WP_Post $post    Page post.
	 * @param string   $wc_page WooCommerce page key.
	 * @return array
	 */
	private static function page_summary( \WP_Post $post, $wc_page ) {
		return array_merge(
			[
				'id'             => (int) $post->ID,
				'title'          => $post->post_title,
				'slug'           => $post->post_name,
				'status'         => $post->post_status,
				'url'            => get_permalink( $post->ID ),
				'adminEditLink'  => get_edit_post_link( $post->ID, 'url' ),
				'bricksEditLink' => \Bricks\Helpers::get_builder_edit_link( $post->ID ),
			],
			self::page_content_state( $post, $wc_page )
		);
	}

	/**
	 * Map setup area status to the page key.
	 *
	 * @since 2.4
	 * @param string $area   Setup area.
	 * @param array  $status Wizard area status.
	 * @return array|null
	 */
	private static function status_page_for_area( $area, array $status ) {
		$page_id = ! empty( $status['page_id'] ) ? (int) $status['page_id'] : 0;

		if ( ! $page_id ) {
			return null;
		}

		$wc_page = $area === 'my_account' ? 'myaccount' : $area;
		$post    = get_post( $page_id );

		return $post instanceof \WP_Post ? self::page_summary( $post, $wc_page ) : null;
	}

	/**
	 * Does plan include page overwrites?
	 *
	 * @since 2.4
	 * @param array $plan Setup plan.
	 * @return bool
	 */
	private static function plan_has_page_overwrite( array $plan ) {
		foreach ( $plan['destructiveActions'] ?? [] as $action ) {
			if ( ( $action['type'] ?? '' ) === 'replace_page_content' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does a blocker list contain a code?
	 *
	 * @since 2.4
	 * @param array  $blockers Blocker rows.
	 * @param string $code     Blocker code.
	 * @return bool
	 */
	private static function has_blocker( array $blockers, $code ) {
		foreach ( $blockers as $blocker ) {
			if ( ( $blocker['code'] ?? '' ) === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Wrap wizard WP_Error values in ability error conventions.
	 *
	 * @since 2.4
	 * @param \WP_Error $error Wizard error.
	 * @param string    $area  Setup area.
	 * @return \WP_Error
	 */
	private static function wrap_wizard_error( \WP_Error $error, $area ) {
		return Error::conflict(
			'woo_setup_' . sanitize_key( $error->get_error_code() ),
			[
				'message' => $error->get_error_message(),
				'area'    => $area,
				'code'    => $error->get_error_code(),
			]
		);
	}
}

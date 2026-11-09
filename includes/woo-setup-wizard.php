<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce setup wizard: status detection, 1-click setup flows, and preset management.
 *
 * @since 2.4
 */
class Woo_Setup_Wizard {

	/**
	 * Check if WooCommerce is active
	 *
	 * @var bool
	 */
	private $is_woocommerce_active = false;

	/**
	 * JSON preset registry for 1-click setup.
	 *
	 * @var array
	 */
	private static $presets = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->is_woocommerce_active = Woocommerce::is_woocommerce_active();

		if ( ! $this->is_woocommerce_active ) {
			return;
		}

		// Register AJAX handlers
		add_action( 'wp_ajax_bricks_woo_setup_wizard_get_status', [ $this, 'ajax_get_status' ] );
		add_action( 'wp_ajax_bricks_woo_setup_wizard_run_setup', [ $this, 'ajax_run_setup' ] );
		add_action( 'wp_ajax_bricks_woo_setup_wizard_trash_template', [ $this, 'ajax_trash_template' ] );

		$this->init_preset_registry();
	}

	/**
	 * AJAX: Get status for all WooCommerce areas
	 *
	 * @return void (JSON response)
	 */
	public function ajax_get_status() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to perform this action.', 'bricks' ) );
		}

		$areas            = [ 'shop', 'single_product', 'cart', 'checkout', 'my_account' ];
		$status           = [];
		$selected_presets = $this->sanitize_selected_presets( $_POST['presets'] ?? [] );

		foreach ( $areas as $area ) {
			$status[ $area ] = $this->get_area_status( $area, $selected_presets[ $area ] ?? '' );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Get comprehensive status for a single WooCommerce area
	 *
	 * @param string $area WooCommerce area.
	 * @return array {
	 *   'page_id'            => int|false,
	 *   'page_title'         => string,
	 *   'page_url'           => string,
	 *   'page_edited_in_bricks' => bool,
	 *   'templates'          => array,
	 *   'status'             => 'ready'|'partial'|'not_configured',
	 *   'page_warnings'      => string[],
	 *   'template_warnings'  => string[],
	 *   'preset_key'         => string|null,
	 *   'requires_page_edit' => bool,
	 *   'confirm_description' => string,
	 *   'setup_notice'       => string,
	 * }
	 */
	public function get_area_status( $area, $preset_id = '' ) {
		$area   = sanitize_key( $area );
		$preset = $this->get_preset( $area, $preset_id );

		if ( $preset && ( $preset['mode'] ?? '' ) === 'advanced' ) {
			$result = $this->detect_advanced_status( $area, $preset );
		} else {
			switch ( $area ) {
				case 'shop':
					$result = $this->detect_shop_status();
					break;
				case 'single_product':
					$result = $this->detect_single_product_status();
					break;
				case 'cart':
					$result = $this->detect_cart_status();
					break;
				case 'checkout':
					$result = $this->detect_checkout_status();
					break;
				case 'my_account':
					$result = $this->detect_my_account_status();
					break;
				default:
					$result = $this->default_status();
			}
		}

		// Attach preset-specific UI strings and data.
		$preset_options                = $this->get_available_preset_options( $area );
		$allow_preset_selection        = $this->supports_preset_selection( $area ) && count( $preset_options ) > 1;
		$advanced_modular_notice       = '';
		$advanced_modular_settings_url = '';

		if (
			$allow_preset_selection &&
			! Woocommerce::use_advanced_modular_elements() &&
			$this->preset_options_include_advanced( $preset_options )
		) {
			$advanced_modular_notice       = __( 'Advanced (v2) setup types require advanced modular elements.', 'bricks' );
			$advanced_modular_settings_url = admin_url( 'admin.php?page=bricks-settings#tab-woocommerce' );
		}

		$result['confirm_description']           = is_array( $preset ) ? $this->get_preset_confirm_description( $area, $preset ) : '';
		$result['setup_notice']                  = is_array( $preset ) ? $this->get_preset_setup_notice( $area, $preset ) : '';
		$result['preset_key']                    = is_array( $preset ) ? ( $preset['id'] ?? null ) : null;
		$result['selected_preset_key']           = is_array( $preset ) ? ( $preset['id'] ?? null ) : null;
		$result['presets']                       = $preset_options;
		$result['allow_preset_selection']        = $allow_preset_selection;
		$result['advanced_modular_notice']       = $advanced_modular_notice;
		$result['advanced_modular_settings_url'] = $advanced_modular_settings_url;
		$result['requires_page_edit']            = is_array( $preset ) ? ! empty( $preset['requiresPageEdit'] ) : false;
		$result['should_draft_existing']         = is_array( $preset ) ? ! empty( $preset['shouldDraftExisting'] ) : true;
		$result['page_setup_confirm']            = is_array( $preset ) ? $this->get_preset_page_setup_confirm( $area, $preset ) : '';

		return $result;
	}

	/**
	 * Get supported setup areas.
	 *
	 * Shared by the admin wizard and MCP abilities so both surfaces use the same
	 * area names.
	 *
	 * @since 2.4
	 * @return string[]
	 */
	public function get_setup_areas() {
		return [ 'shop', 'single_product', 'cart', 'checkout', 'my_account' ];
	}

	/**
	 * Get the preset selected for an area.
	 *
	 * @since 2.4
	 * @param string $area                WooCommerce setup area.
	 * @param string $preset_id           Optional preset ID. Empty selects the active preset.
	 * @param bool   $include_unavailable Whether unavailable presets can be returned.
	 * @return array|null
	 */
	public function get_setup_preset( $area, $preset_id = '', $include_unavailable = false ) {
		return $this->get_preset( $area, $preset_id, $include_unavailable );
	}

	/**
	 * Get the sanitized preset options for an area.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce setup area.
	 * @return array
	 */
	public function get_setup_preset_options( $area ) {
		return $this->get_available_preset_options( $area );
	}

	/**
	 * Run setup for a selected area and preset.
	 *
	 * @since 2.4
	 * @param string $area          WooCommerce setup area.
	 * @param string $preset_id     Optional preset ID. Empty selects the active preset.
	 * @param bool   $force         Whether confirmed page-content replacement is allowed.
	 * @param string $scope         Setup scope: all, page, or template.
	 * @param string $template_type Optional template type when scope=template.
	 * @return array|\WP_Error
	 */
	public function run_setup( $area, $preset_id = '', $force = false, $scope = 'all', $template_type = '', $template_mode = 'duplicate' ) {
		$area          = sanitize_key( $area );
		$preset_id     = sanitize_key( $preset_id );
		$scope         = sanitize_key( $scope );
		$template_type = sanitize_key( $template_type );
		$template_mode = sanitize_key( $template_mode );
		$preset        = $this->get_preset( $area, $preset_id );

		if ( ! $preset ) {
			return new \WP_Error( 'no_setup_preset', __( 'No setup preset found for this area.', 'bricks' ) );
		}

		if ( ! in_array( $scope, [ 'all', 'page', 'template' ], true ) ) {
			$scope = 'all';
		}

		if ( ! in_array( $template_mode, [ 'reuse', 'replace', 'duplicate' ], true ) ) {
			$template_mode = 'duplicate';
		}

		return $this->run_setup_with_preset( $preset, (bool) $force, $scope, $template_type, $template_mode );
	}

	/**
	 * Detect status for the v2 page-only setup flow.
	 *
	 * @since 2.4
	 * @param string $area   WooCommerce area.
	 * @param array  $preset JSON preset.
	 * @return array
	 */
	private function detect_advanced_status( $area, $preset ) {
		$wc_page = $preset['page']['wcPage'] ?? '';
		$page_id = $wc_page ? $this->get_wc_page_id( $wc_page ) : false;

		if ( ! $page_id ) {
			return $this->default_status(
				$area,
				'not_configured',
				[
					__( 'WooCommerce page is not assigned in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status === 'trash' ) {
			return $this->default_status(
				$area,
				'not_configured',
				[
					__( 'WooCommerce page has been deleted. Please assign a new page in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page_elements          = get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
		$page_edited_in_bricks  = (bool) $page_elements;
		$editor_mode            = get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true );
		$wordpress_mode         = ( $editor_mode === 'wordpress' );
		$has_woocommerce_blocks = $this->has_woocommerce_blocks( $page );
		$parent_element         = $preset['page']['advancedParent'] ?? '';
		$has_parent_element     = $parent_element ? $this->elements_contain( $page_elements, $parent_element ) : false;
		$missing_states         = $this->get_missing_advanced_states( $page_elements, $preset );
		$empty_states           = $this->get_empty_advanced_states( $page_elements, $preset );

		$status = ( $page_edited_in_bricks && $has_parent_element && empty( $missing_states ) && empty( $empty_states ) && ! $wordpress_mode && ! $has_woocommerce_blocks ) ? 'ready' : 'not_configured';

		$page_warnings = [];

		if ( $wordpress_mode ) {
			$page_warnings[] = __( 'This page is set to Rendered with WordPress. Run 1-click setup or switch the page to Bricks editor mode manually.', 'bricks' );
		}

		if ( $has_woocommerce_blocks ) {
			$page_warnings[] = __( 'This page contains WooCommerce Gutenberg blocks which are not compatible with Bricks.', 'bricks' );
		}

		if ( ! $has_parent_element ) {
			$page_warnings[] = __( 'Required WooCommerce v2 element is missing from this page.', 'bricks' );
		}

		if ( ! empty( $missing_states ) ) {
			$page_warnings[] = __( 'Required WooCommerce v2 state elements are missing from this page.', 'bricks' );
		}

		if ( ! empty( $empty_states ) ) {
			$page_warnings[] = __( 'Required WooCommerce v2 state elements have no content.', 'bricks' );
		}

		return [
			'area'                  => $area,
			'page_id'               => $page_id,
			'page_title'            => $page->post_title,
			'page_url'              => get_permalink( $page_id ),
			'page_edited_in_bricks' => $page_edited_in_bricks,
			'page_bricks_edit_link' => $page_edited_in_bricks ? Helpers::get_builder_edit_link( $page_id ) : false,
			'page_admin_edit_link'  => get_edit_post_link( $page_id, 'url' ),
			'templates'             => [],
			'status'                => $status,
			'page_warnings'         => $page_warnings,
			'template_warnings'     => [],
		];
	}

	/**
	 * Detect Cart page status
	 *
	 * @return array
	 */
	private function detect_cart_status() {
		$page_id = $this->get_wc_page_id( 'cart' );

		if ( ! $page_id ) {
			return $this->default_status(
				'cart',
				'not_configured',
				[
					__( 'Cart page is not assigned in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status === 'trash' ) {
			return $this->default_status(
				'cart',
				'not_configured',
				[
					__( 'Cart page has been deleted. Please assign a new page in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page_edited_in_bricks  = (bool) get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
		$editor_mode            = get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true );
		$wordpress_mode         = ( $editor_mode === 'wordpress' );
		$has_woocommerce_blocks = $this->has_woocommerce_blocks( $page, 'cart' );
		$shortcode_in_raw       = $this->has_raw_shortcode( $page, 'woocommerce_cart' );
		$shortcode_in_bricks    = false;

		if ( $page_edited_in_bricks ) {
			$shortcode_in_bricks = $this->shortcode_in_elements( $page_id, 'woocommerce_cart' );
		}

		$has_shortcode = $shortcode_in_raw || $shortcode_in_bricks;

		// Templates: always include all expected types, found or not
		$templates = $this->build_area_templates(
			$this->get_area_template_types( [ 'wc_cart', 'wc_cart_empty' ] )
		);

		$all_published = $this->all_template_types_published( $templates );
		$no_templates  = $this->is_no_templates( $templates );

		// Determine status
		$status = 'partial';

		// Ready: page + shortcode + all templates published + no incompatible blocks
		if ( $has_shortcode && $all_published && ! $has_woocommerce_blocks && ! $wordpress_mode ) {
			$status = 'ready';
		}

		// Not configured: any page-level prerequisite missing (overrides partial)
		if ( ! $has_shortcode || $has_woocommerce_blocks || $wordpress_mode ) {
			$status = 'not_configured';
		}

		// Populate warnings
		$page_warnings     = [];
		$template_warnings = [];

		if ( $wordpress_mode ) {
			$page_warnings[] = __( 'Cart page is set to Rendered with WordPress. Bricks templates will not apply until this is fixed. Run 1-click setup or switch the page to Bricks editor mode manually.', 'bricks' );
		}

		if ( $has_woocommerce_blocks ) {
			$page_warnings[] = __( 'This page contains WooCommerce Gutenberg blocks which are not compatible with Bricks.', 'bricks' );
		}

		if ( ! $has_shortcode ) {
			$page_warnings[] = __( 'Required shortcode is missing from this page.', 'bricks' );
		}

		if ( ! $no_templates && ! $all_published ) {
			$template_warnings[] = __( 'Some templates are missing or in draft status.', 'bricks' );
		}

		if ( $no_templates ) {
			$template_warnings[] = __( 'No Bricks templates found for this area.', 'bricks' );
		}

		return [
			'area'                  => 'cart',
			'page_id'               => $page_id,
			'page_title'            => $page->post_title,
			'page_url'              => get_permalink( $page_id ),
			'page_edited_in_bricks' => $page_edited_in_bricks,
			'page_bricks_edit_link' => $page_edited_in_bricks ? Helpers::get_builder_edit_link( $page_id ) : false,
			'page_admin_edit_link'  => get_edit_post_link( $page_id, 'url' ),
			'templates'             => $templates,
			'status'                => $status,
			'page_warnings'         => $page_warnings,
			'template_warnings'     => $template_warnings,
		];
	}

	/**
	 * Detect Checkout page status
	 *
	 * @return array
	 */
	private function detect_checkout_status() {
		$page_id = $this->get_wc_page_id( 'checkout' );

		if ( ! $page_id ) {
			return $this->default_status(
				'checkout',
				'not_configured',
				[
					__( 'Checkout page is not assigned in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status === 'trash' ) {
			return $this->default_status(
				'checkout',
				'not_configured',
				[
					__( 'Checkout page has been deleted. Please assign a new page in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page_edited_in_bricks  = (bool) get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
		$editor_mode            = get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true );
		$wordpress_mode         = ( $editor_mode === 'wordpress' );
		$has_woocommerce_blocks = $this->has_woocommerce_blocks( $page, 'checkout' );
		$shortcode_in_raw       = $this->has_raw_shortcode( $page, 'woocommerce_checkout' );
		$shortcode_in_bricks    = false;

		if ( $page_edited_in_bricks ) {
			$shortcode_in_bricks = $this->shortcode_in_elements( $page_id, 'woocommerce_checkout' );
		}

		$has_shortcode = $shortcode_in_raw || $shortcode_in_bricks;

		// Templates: always include all expected types, found or not
		$templates = $this->build_area_templates(
			$this->get_area_template_types( [ 'wc_form_checkout', 'wc_thankyou', 'wc_form_pay', 'wc_order_receipt' ] )
		);

		$all_published = $this->all_template_types_published( $templates );
		$no_templates  = $this->is_no_templates( $templates );

		// Determine status
		$status = 'partial';

		// Ready: page + shortcode + all templates published + no incompatible blocks
		if ( $has_shortcode && $all_published && ! $has_woocommerce_blocks && ! $wordpress_mode ) {
			$status = 'ready';
		}

		// Not configured: any page-level prerequisite missing (overrides partial)
		if ( ! $has_shortcode || $has_woocommerce_blocks || $wordpress_mode ) {
			$status = 'not_configured';
		}

		// Populate warnings
		$page_warnings     = [];
		$template_warnings = [];

		if ( $wordpress_mode ) {
			$page_warnings[] = __( 'Checkout page', 'bricks' ) . ' ' . __( ' is set to Rendered with WordPress. Bricks templates will not apply until this is fixed. Run 1-click setup or switch the page to Bricks editor mode manually.', 'bricks' );
		}

		if ( $has_woocommerce_blocks ) {
			$page_warnings[] = __( 'This page contains WooCommerce Gutenberg blocks which are not compatible with Bricks.', 'bricks' );
		}

		if ( ! $has_shortcode ) {
			$page_warnings[] = __( 'Required shortcode is missing from this page.', 'bricks' );
		}

		if ( ! $no_templates && ! $all_published ) {
			$template_warnings[] = __( 'Some templates are missing or in draft status.', 'bricks' );
		}

		if ( $no_templates ) {
			$template_warnings[] = __( 'No Bricks templates found for this area.', 'bricks' );
		}

		return [
			'area'                  => 'checkout',
			'page_id'               => $page_id,
			'page_title'            => $page->post_title,
			'page_url'              => get_permalink( $page_id ),
			'page_edited_in_bricks' => $page_edited_in_bricks,
			'page_bricks_edit_link' => $page_edited_in_bricks ? Helpers::get_builder_edit_link( $page_id ) : false,
			'page_admin_edit_link'  => get_edit_post_link( $page_id, 'url' ),
			'templates'             => $templates,
			'status'                => $status,
			'page_warnings'         => $page_warnings,
			'template_warnings'     => $template_warnings,
		];
	}

	/**
	 * Detect My Account page status
	 * Critical: Must have Bricks content AND Account Page element
	 *
	 * @return array
	 */
	private function detect_my_account_status() {
		$page_id = $this->get_wc_page_id( 'myaccount' );

		if ( ! $page_id ) {
			return $this->default_status(
				'my_account',
				'not_configured',
				[
					__( 'My Account page is not assigned in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status === 'trash' ) {
			return $this->default_status(
				'my_account',
				'not_configured',
				[
					__( 'My Account page has been deleted. Please assign a new page in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page_edited_in_bricks    = (bool) get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
		$editor_mode              = get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true );
		$wordpress_mode           = ( $editor_mode === 'wordpress' );
		$has_account_page_element = false;

		if ( $page_edited_in_bricks ) {
			$has_account_page_element = $this->elements_contain(
				get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true ),
				'woocommerce-account-page'
			);
		}

		// Templates: always include all expected types, found or not
		$templates = $this->build_area_templates(
			$this->get_area_template_types(
				[
					'wc_account_dashboard',
					'wc_account_orders',
					'wc_account_view_order',
					'wc_account_downloads',
					'wc_account_addresses',
					'wc_account_form_edit_address',
					'wc_account_form_edit_account',
					'wc_account_payment_methods',
					'wc_account_add_payment_method',
					'wc_account_form_login',
					'wc_account_form_lost_password',
					'wc_account_form_lost_password_confirmation',
					'wc_account_reset_password',
				]
			)
		);

		$all_published = $this->all_template_types_published( $templates );
		$no_templates  = $this->is_no_templates( $templates );

		// Determine status
		$status = 'partial';

		// Ready: page edited in Bricks + Account Page element present + all templates published
		if ( $page_edited_in_bricks && $has_account_page_element && $all_published && ! $wordpress_mode ) {
			$status = 'ready';
		}

		// Not configured: any page-level prerequisite missing (overrides partial)
		if ( ! $page_edited_in_bricks || ! $has_account_page_element || $wordpress_mode ) {
			$status = 'not_configured';
		}

		// Populate warnings
		$page_warnings     = [];
		$template_warnings = [];

		if ( $wordpress_mode ) {
			$page_warnings[] = __( 'My Account page', 'bricks' ) . ' ' . __( 'is set to Rendered with WordPress. Bricks templates will not apply until this is fixed. Run 1-click setup or switch the page to Bricks editor mode manually.', 'bricks' );
		}

		if ( ! $page_edited_in_bricks ) {
			$page_warnings[] = __( 'My Account page must be edited with Bricks to enable endpoint templates.', 'bricks' );
		} elseif ( ! $has_account_page_element ) {
			$page_warnings[] = __( 'The Account - Page element is missing from your My Account page. Endpoint templates will not render without it.', 'bricks' );
		}

		if ( ! $no_templates && ! $all_published ) {
			$template_warnings[] = __( 'Some templates are missing or in draft status.', 'bricks' );
		}

		if ( $no_templates ) {
			$template_warnings[] = __( 'No Bricks templates found for this area.', 'bricks' );
		}

		return [
			'area'                  => 'my_account',
			'page_id'               => $page_id,
			'page_title'            => $page->post_title,
			'page_url'              => get_permalink( $page_id ),
			'page_edited_in_bricks' => $page_edited_in_bricks,
			'page_bricks_edit_link' => $page_edited_in_bricks ? Helpers::get_builder_edit_link( $page_id ) : false,
			'page_admin_edit_link'  => get_edit_post_link( $page_id, 'url' ),
			'templates'             => $templates,
			'status'                => $status,
			'page_warnings'         => $page_warnings,
			'template_warnings'     => $template_warnings,
		];
	}

	/**
	 * Detect Shop page status
	 *
	 * @return array
	 */
	private function detect_shop_status() {
		$page_id = $this->get_wc_page_id( 'shop' );

		if ( ! $page_id ) {
			return $this->default_status(
				'shop',
				'not_configured',
				[
					__( 'Shop page is not assigned in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page = get_post( $page_id );

		if ( ! $page || $page->post_status === 'trash' ) {
			return $this->default_status(
				'shop',
				'not_configured',
				[
					__( 'Shop page has been deleted. Please assign a new page in WooCommerce Settings.', 'bricks' ),
				]
			);
		}

		$page_edited_in_bricks = (bool) get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );

		// Check if the shop page is Rendered with WordPress
		$editor_mode    = get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true );
		$wordpress_mode = ( $editor_mode === 'wordpress' );

		// Slot 1: dedicated WooCommerce Product Archive template type.
		$wc_templates = $this->build_area_templates(
			$this->get_area_template_types( [ 'wc_archive' ] )
		);

		// Slot 2: generic Archive templates that explicitly target shop/product archives via conditions.
		$archive_targeting = $this->find_archive_templates_for_shop( $page_id );
		$all_templates     = array_merge( $wc_templates, [ $archive_targeting ] );

		$has_wc_template            = $this->template_type_found( $wc_templates, 'wc_archive' );
		$wc_template_published      = $this->template_type_published( $wc_templates, 'wc_archive' );
		$has_archive_template       = ! empty( $archive_targeting['templates'] );
		$archive_template_published = ! empty( $archive_targeting['published'] );

		$has_templates     = $has_wc_template || $has_archive_template;
		$has_any_published = $wc_template_published || $archive_template_published;

		// Determine status
		$status = 'partial';
		if ( $has_any_published && ! $wordpress_mode ) {
			$status = 'ready';
		}
		if ( $wordpress_mode || ! $has_templates ) {
			$status = 'not_configured';
		}

		// Populate warnings
		$page_warnings     = [];
		$template_warnings = [];

		if ( $wordpress_mode ) {
			$page_warnings[] = __( 'Shop page', 'bricks' ) . ' ' . __( 'is set to Rendered with WordPress. Bricks templates will not apply until this is fixed. Run 1-click setup or switch the page to Bricks editor mode manually.', 'bricks' );
		}

		if ( ! $has_templates ) {
			$template_warnings[] = __( 'No Bricks templates found for this area.', 'bricks' );
		} elseif ( ! $has_any_published ) {
			$template_warnings[] = __( 'Some templates are missing or in draft status.', 'bricks' );
		}

		return [
			'area'                  => 'shop',
			'page_id'               => $page_id,
			'page_title'            => $page->post_title,
			'page_url'              => get_permalink( $page_id ),
			'page_edited_in_bricks' => $page_edited_in_bricks,
			'page_bricks_edit_link' => $page_edited_in_bricks ? Helpers::get_builder_edit_link( $page_id ) : false,
			'page_admin_edit_link'  => get_edit_post_link( $page_id, 'url' ),
			'templates'             => $all_templates,
			'status'                => $status,
			'page_warnings'         => $page_warnings,
			'template_warnings'     => $template_warnings,
		];
	}

	/**
	 * Detect Single Product page status
	 *
	 * @return array
	 */
	private function detect_single_product_status() {
		$wc_templates     = $this->build_area_templates( $this->get_area_template_types( [ 'wc_product' ] ) );
		$single_targeting = $this->find_single_templates_for_products();
		$all_templates    = array_merge( $wc_templates, [ $single_targeting ] );

		$has_wc_template           = $this->template_type_found( $wc_templates, 'wc_product' );
		$wc_template_published     = $this->template_type_published( $wc_templates, 'wc_product' );
		$has_single_template       = ! empty( $single_targeting['templates'] );
		$single_template_published = ! empty( $single_targeting['published'] );

		$has_templates     = $has_wc_template || $has_single_template;
		$has_any_published = $wc_template_published || $single_template_published;

		// Determine status
		$status = 'partial';
		if ( $has_any_published ) {
			$status = 'ready';
		}
		if ( ! $has_templates ) {
			$status = 'not_configured';
		}

		// Populate warnings
		$template_warnings = [];

		if ( ! $has_templates ) {
			$template_warnings[] = __( 'No Bricks templates found for this area.', 'bricks' );
		} elseif ( ! $has_any_published ) {
			$template_warnings[] = __( 'Some templates are missing or in draft status.', 'bricks' );
		}

		return [
			'area'              => 'single_product',
			'page_id'           => false,
			'page_title'        => __( 'Single Product Page', 'bricks' ),
			'page_url'          => false,
			'templates'         => $all_templates,
			'status'            => $status,
			'page_warnings'     => [],
			'template_warnings' => $template_warnings,
		];
	}

	/**
	 * Find all Archive-type templates that have conditions explicitly targeting shop/product archives.
	 *
	 * Detects four scenarios:
	 *   1. Individual condition whose IDs include the shop page.
	 *   2. archiveType > any condition targeting all archives.
	 *   3. archiveType > postType condition targeting all CPT archives or product archives.
	 *   4. archiveType > term condition targeting all term archives or product_cat/product_tag taxonomies.
	 *
	 * @since 2.4
	 * @param int $shop_page_id WooCommerce shop page ID.
	 * @return array Template slot compatible with build_area_templates() output.
	 */
	private function find_archive_templates_for_shop( $shop_page_id ) {
		$archive_ids = get_posts(
			[
				'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page' => -1,
				'post_status'    => [ 'draft', 'publish' ],
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin setup status filters by Bricks template type.
				'meta_query'     => [
					[
						'key'   => BRICKS_DB_TEMPLATE_TYPE,
						'value' => 'archive',
					],
				],
			]
		);

		$matching = [];

		foreach ( $archive_ids as $id ) {
			$conditions   = Helpers::get_template_setting( 'templateConditions', $id );
			$targets_shop = false;

			if ( empty( $conditions ) ) {
				continue; // No conditions = generic default template; do not count as Woo-specific readiness.
			}

			foreach ( $conditions as $condition ) {
				if ( isset( $condition['exclude'] ) ) {
					continue; // Skip exclusion rules.
				}

				$main = $condition['main'] ?? '';

				// Scenario 1: Individual condition whose IDs include the shop page.
				if (
					$main === 'ids' &&
					! empty( $condition['ids'] ) &&
					in_array( $shop_page_id, array_map( 'intval', $condition['ids'] ), true )
				) {
					$targets_shop = true;
					break;
				}

				if ( $main !== 'archiveType' ) {
					continue;
				}

				$archive_types = (array) ( $condition['archiveType'] ?? [] );

				// Scenario 2: archiveType > any targeting all archives.
				if ( in_array( 'any', $archive_types, true ) ) {
					$targets_shop = true;
					break;
				}

				// Scenario 3: archiveType > postType targeting all CPT archives or the product post type archive.
				if (
					in_array( 'postType', $archive_types, true ) &&
					(
						empty( $condition['archivePostTypes'] ) ||
						in_array( 'product', (array) $condition['archivePostTypes'], true )
					)
				) {
					$targets_shop = true;
					break;
				}

				// Scenario 4: archiveType > term targeting all term archives or product_cat/product_tag.
				if ( in_array( 'term', $archive_types, true ) ) {
					if ( empty( $condition['archiveTerms'] ) ) {
						$targets_shop = true;
						break;
					}

					foreach ( (array) $condition['archiveTerms'] as $archive_term ) {
						$taxonomy = explode( '::', $archive_term )[0] ?? '';
						if ( in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) {
							$targets_shop = true;
							break 2;
						}
					}
				}
			}

			if ( $targets_shop ) {
				$template   = get_post( $id );
				$matching[] = [
					'id'              => $id,
					'title'           => $template->post_title,
					'status'          => $template->post_status,
					'edit_link'       => Helpers::get_builder_edit_link( $id ),
					'admin_edit_link' => get_edit_post_link( $id, 'url' ),
					'preview_link'    => get_permalink( $id ),
				];
			}
		}

		return [
			'type'           => 'archive',
			'type_label'     => __( 'Archive template with conditions', 'bricks' ),
			'found'          => ! empty( $matching ),
			'has_preset_def' => false,
			'published'      => ! empty(
				array_filter( $matching, fn( $t ) => $t['status'] === 'publish' )
			),
			'templates'      => $matching,
		];
	}

	/**
	 * Find all Single-type templates that have conditions targeting products.
	 *
	 * Covers users who set up a generic Single template with broad, product
	 * post-type, or product ID conditions instead of using the dedicated wc_product
	 * template type.
	 *
	 * @since 2.4
	 * @return array Single template slot compatible with build_area_templates() output.
	 */
	private function find_single_templates_for_products() {
		$single_ids = get_posts(
			[
				'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page' => -1,
				'post_status'    => [ 'draft', 'publish' ],
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin setup status filters by Bricks template type.
				'meta_query'     => [
					[
						'key'   => BRICKS_DB_TEMPLATE_TYPE,
						'value' => 'content',
					],
				],
			]
		);

		$matching = [];

		foreach ( $single_ids as $id ) {
			$conditions      = Helpers::get_template_setting( 'templateConditions', $id );
			$targets_product = false;

			if ( empty( $conditions ) ) {
				continue; // No conditions = generic default template; do not count as Woo-specific readiness.
			}

			foreach ( $conditions as $condition ) {
				if ( isset( $condition['exclude'] ) ) {
					continue; // Skip exclusion rules.
				}

				// Scenario 1: Entire website condition also applies to product singles.
				if ( ( $condition['main'] ?? '' ) === 'any' ) {
					$targets_product = true;
					break;
				}

				// Scenario 2: postType condition containing 'product'.
				if (
					( $condition['main'] ?? '' ) === 'postType' &&
					! empty( $condition['postType'] ) &&
					in_array( 'product', (array) $condition['postType'], true )
				) {
					$targets_product = true;
					break;
				}

				// Scenario 3: specific post IDs where the post is a 'product'.
				if ( ( $condition['main'] ?? '' ) === 'ids' && ! empty( $condition['ids'] ) ) {
					foreach ( $condition['ids'] as $cond_post_id ) {
						if ( get_post_type( $cond_post_id ) === 'product' ) {
							$targets_product = true;
							break 2;
						}
					}
				}
			}

			if ( $targets_product ) {
				$template   = get_post( $id );
				$matching[] = [
					'id'              => $id,
					'title'           => $template->post_title,
					'status'          => $template->post_status,
					'edit_link'       => Helpers::get_builder_edit_link( $id ),
					'admin_edit_link' => get_edit_post_link( $id, 'url' ),
					'preview_link'    => get_permalink( $id ),
				];
			}
		}

		return [
			'type'           => 'single',
			'type_label'     => __( 'Single template with conditions', 'bricks' ),
			'found'          => ! empty( $matching ),
			'has_preset_def' => false,
			'published'      => ! empty(
				array_filter( $matching, fn( $t ) => $t['status'] === 'publish' )
			),
			'templates'      => $matching,
		];
	}

	/**
	 * Get WooCommerce page ID
	 *
	 * @param string $page WooCommerce page key.
	 * @return int|false
	 */
	private function get_wc_page_id( $page ) {
		$page_id = wc_get_page_id( $page );
		return ( $page_id > 0 ) ? $page_id : false;
	}

	/**
	 * Check if post_content contains WooCommerce-specific Gutenberg blocks.
	 * Uses block comment markers to avoid false positives from shortcode or classic blocks.
	 *
	 * @param WP_Post $post       Post object.
	 * @param string  $block_name WooCommerce block name.
	 * @return bool
	 */
	private function has_woocommerce_blocks( $post, $block_name = '' ) {
		if ( ! $post || ! isset( $post->post_content ) ) {
			return false;
		}

		$needle = $block_name ? '<!-- wp:woocommerce/' . $block_name : '<!-- wp:woocommerce/';

		return strpos( $post->post_content, $needle ) !== false;
	}

	/**
	 * Check if raw post_content contains a shortcode
	 *
	 * @param WP_Post $post      Post object.
	 * @param string  $shortcode Shortcode name.
	 * @return bool
	 */
	private function has_raw_shortcode( $post, $shortcode ) {
		if ( ! $post || ! isset( $post->post_content ) ) {
			return false;
		}
		return strpos( $post->post_content, '[' . $shortcode . ']' ) !== false;
	}

	/**
	 * Check if a shortcode appears in Bricks elements array as a Shortcode element
	 *
	 * @param int    $page_id   WordPress page ID.
	 * @param string $shortcode Shortcode name.
	 * @return bool
	 */
	private function shortcode_in_elements( $page_id, $shortcode ) {
		$elements = get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! is_array( $elements ) ) {
			return false;
		}

		return $this->elements_contain_shortcode( $elements, $shortcode );
	}

	/**
	 * Recursively check if elements array contains a specific shortcode in any Shortcode element
	 *
	 * @param array  $elements
	 * @param string $shortcode
	 * @return bool
	 */
	private function elements_contain_shortcode( $elements, $shortcode ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Check if this is a Shortcode element
			if ( isset( $element['name'] ) && $element['name'] === 'shortcode' ) {
				if (
					isset( $element['settings']['shortcode'] ) &&
					strpos( $element['settings']['shortcode'], '[' . $shortcode . ']' ) !== false
				) {
					return true;
				}
			}

			// Check children
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				if ( $this->elements_contain_shortcode( $element['children'], $shortcode ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively check if elements contain an element by name
	 *
	 * @param array  $elements     Bricks elements.
	 * @param string $element_name Element name.
	 * @return bool
	 */
	private function elements_contain( $elements, $element_name ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['name'] ) && $element_name === $element['name'] ) {
				return true;
			}

			// Recurse into children
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				if ( $this->elements_contain( $element['children'], $element_name ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Keep setup generation and readiness checks aligned for optional states.
	 *
	 * @since 2.4
	 *
	 * @param string $state_name State element name.
	 * @return bool
	 */
	private function is_advanced_state_available( $state_name ) {
		return $state_name !== 'woocommerce-account-v2-state-order-withdrawal' || Woocommerce::is_order_withdrawal_enabled();
	}

	/**
	 * Get missing v2 state element names for an advanced setup preset.
	 *
	 * @since 2.4
	 * @param array $elements Bricks elements.
	 * @param array $preset   Setup preset.
	 * @return array
	 */
	private function get_missing_advanced_states( $elements, $preset ) {
		if ( ! is_array( $elements ) ) {
			return [];
		}

		$missing = [];

		foreach ( $preset['page']['states'] ?? [] as $state ) {
			$state_name = $state['name'] ?? '';

			if ( ! $this->is_advanced_state_available( $state_name ) ) {
				continue;
			}

			if ( $state_name && ! $this->elements_contain( $elements, $state_name ) ) {
				$missing[] = $state_name;
			}
		}

		return $missing;
	}

	/**
	 * Get v2 state element names that exist but have no child content.
	 *
	 * @since 2.4
	 * @param array $elements Bricks elements.
	 * @param array $preset   Setup preset.
	 * @return array
	 */
	private function get_empty_advanced_states( $elements, $preset ) {
		if ( ! is_array( $elements ) ) {
			return [];
		}

		$by_id = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$by_id[ $element['id'] ] = $element;
			}
		}

		$empty = [];

		foreach ( $preset['page']['states'] ?? [] as $state ) {
			$state_name = $state['name'] ?? '';

			if ( ! $this->is_advanced_state_available( $state_name ) ) {
				continue;
			}

			$state_element = $state_name ? $this->find_element_by_name( $elements, $state_name ) : false;

			if ( ! $state_element ) {
				continue;
			}

			$children          = ! empty( $state_element['children'] ) && is_array( $state_element['children'] ) ? $state_element['children'] : [];
			$has_child_content = false;

			foreach ( $children as $child_id ) {
				if ( is_array( $child_id ) && ! empty( $child_id['id'] ) ) {
					$child_id = $child_id['id'];
				}

				if ( ( is_string( $child_id ) || is_int( $child_id ) ) && isset( $by_id[ $child_id ] ) ) {
					$has_child_content = true;
					break;
				}
			}

			if ( ! $has_child_content ) {
				$empty[] = $state_name;
			}
		}

		return $empty;
	}

	/**
	 * Find the first element by name.
	 *
	 * @since 2.4
	 * @param array  $elements     Bricks elements.
	 * @param string $element_name Element name.
	 * @return array|false
	 */
	private function find_element_by_name( $elements, $element_name ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['name'] ) && $element_name === $element['name'] ) {
				return $element;
			}

			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$child = $this->find_element_by_name( $element['children'], $element_name );

				if ( $child ) {
					return $child;
				}
			}
		}

		return false;
	}

	/**
	 * Get human-readable labels for template types relevant to an area.
	 * Labels are sourced from Woocommerce::get_woo_templates() — the single source of truth.
	 * Adding a new template type only requires updating get_woo_templates().
	 *
	 * @since 2.4
	 * @param string[] $keys Template type keys.
	 * @return array
	 */
	private function get_area_template_types( array $keys ) {
		$all    = Woocommerce::get_woo_templates();
		$result = [];

		foreach ( $keys as $key ) {
			$result[ $key ] = $all[ $key ] ?? $key;
		}

		return $result;
	}

	/**
	 * Build area templates array.
	 * Always returns a slot for every expected type, with found/not-found flag.
	 *
	 * @param array $expected_types Template type labels keyed by template type.
	 * @return array
	 */
	private function build_area_templates( $expected_types ) {
		$result = [];

		foreach ( $expected_types as $type => $type_label ) {
			// Get draft or published templates of this type
			$template_ids = get_posts(
				[
					'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
					'posts_per_page' => -1,
					'post_status'    => [ 'draft', 'publish' ],
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin setup status filters by Bricks template type.
					'meta_query'     => [
						[
							'key'   => BRICKS_DB_TEMPLATE_TYPE,
							'value' => $type,
						],
					],
				]
			);

			$found_templates = [];

			foreach ( $template_ids as $template_id ) {
				$template = get_post( $template_id );

				$found_templates[] = [
					'id'              => $template_id,
					'title'           => $template->post_title,
					'status'          => $template->post_status,
					'edit_link'       => Helpers::get_builder_edit_link( $template_id ),
					'admin_edit_link' => get_edit_post_link( $template_id, 'url' ),
					'preview_link'    => get_permalink( $template_id ),
				];
			}

			$result[] = [
				'type'           => $type,
				'type_label'     => $type_label,
				'found'          => ! empty( $found_templates ),
				'has_preset_def' => true,
				// True only when at least one template in this slot has post_status === 'publish'.
				// Drafts do not count as fulfilling the requirement.
				'published'      => ! empty(
					array_filter( $found_templates, fn( $t ) => $t['status'] === 'publish' )
				),
				'templates'      => $found_templates,
			];
		}

		return $result;
	}

	/**
	 * Check if a specific template type slot is found in a built_area_templates result
	 *
	 * @param array  $templates Result of build_area_templates().
	 * @param string $type      Template type.
	 * @return bool
	 */
	private function template_type_found( $templates, $type ) {
		foreach ( $templates as $slot ) {
			if ( $slot['type'] === $type ) {
				return $slot['found'];
			}
		}
		return false;
	}

	/**
	 * Check if ALL template type slots have at least one published template.
	 * Draft-only slots do NOT count as fulfilled.
	 *
	 * @param array $templates Result of build_area_templates().
	 * @return bool
	 */
	private function all_template_types_published( $templates ) {
		foreach ( $templates as $slot ) {
			if ( ! $slot['published'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if ALL template type slots have NO templates found at all.
	 *
	 * @param array $templates Result of build_area_templates().
	 * @return bool
	 */
	private function is_no_templates( $templates ) {
		foreach ( $templates as $slot ) {
			if ( $slot['found'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if a specific template type slot has at least one published template.
	 *
	 * @param array  $templates Result of build_area_templates().
	 * @param string $type      Template type.
	 * @return bool
	 */
	private function template_type_published( $templates, $type ) {
		foreach ( $templates as $slot ) {
			if ( $slot['type'] === $type ) {
				return $slot['published'];
			}
		}
		return false;
	}

	// 1-Click Setup

	/**
	 * Initialise the preset registry.
	 * Built-in presets are registered first; external code may extend via the filter.
	 *
	 * @since 2.4
	 */
	private function init_preset_registry() {
		foreach ( $this->load_json_presets() as $preset ) {
			$area = $preset['area'] ?? '';
			$id   = $preset['id'] ?? '';

			if ( ! $area || ! $id ) {
				continue;
			}

			self::$presets[ $area ][ $id ] = $preset;
		}

		/**
		 * Filter: bricks/woo_setup_wizard/register_presets
		 * Allow external code to register or override 1-click setup JSON presets.
		 *
		 * @since 2.4
		 * @param array $presets [ area => [ preset_id => preset_array ], ... ]
		 */
		self::$presets = apply_filters( 'bricks/woo_setup_wizard/register_presets', self::$presets );
	}

	/**
	 * Recursively load setup presets from JSON files.
	 *
	 * @since 2.4
	 * @return array
	 */
	private function load_json_presets() {
		$presets = [];
		$dir     = BRICKS_PATH . 'includes/woo-setup-wizard/presets';

		if ( ! is_dir( $dir ) ) {
			return $presets;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getExtension() !== 'json' ) {
				continue;
			}

			if ( strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'raw' . DIRECTORY_SEPARATOR ) !== false ) {
				continue;
			}

			$raw = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! $raw ) {
				continue;
			}

			$preset = json_decode( $raw, true );

			if ( ! is_array( $preset ) || empty( $preset['id'] ) || empty( $preset['area'] ) || empty( $preset['mode'] ) ) {
				continue;
			}

			$preset['id']   = sanitize_key( $preset['id'] );
			$preset['area'] = sanitize_key( $preset['area'] );
			$preset['mode'] = sanitize_key( $preset['mode'] );

			$presets[] = $preset;
		}

		return $presets;
	}

	/**
	 * Get generated translation strings for Woo Setup Wizard presets.
	 *
	 * @since 2.4
	 * @return array
	 */
	private function get_preset_translation_map() {
		$file = BRICKS_PATH . 'includes/woo-setup-wizard/presets/translation-strings.php';

		if ( ! file_exists( $file ) ) {
			return [];
		}

		$map = include $file;

		if ( ! is_array( $map ) ) {
			return [];
		}

		return $map;
	}

	/**
	 * Apply translations to raw preset element strings.
	 *
	 * @since 2.4
	 * @param array $elements Elements.
	 * @param array $map      Translation map.
	 * @return array
	 */
	private function translate_preset_elements( $elements, $map ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$element = $this->translate_preset_element_strings( $element, $map );
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Apply translations to known user-facing element strings.
	 *
	 * @since 2.4
	 * @param array $element Element.
	 * @param array $map     Translation map.
	 * @return array
	 */
	private function translate_preset_element_strings( $element, $map ) {
		if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = $this->translate_preset_settings_strings( $element['settings'], $map );
		}

		if ( ! empty( $element['label'] ) && is_string( $element['label'] ) ) {
			$element['label'] = $this->translate_preset_string( $element['label'], $map );
		}

		return $element;
	}

	/**
	 * Apply translations to known user-facing settings strings.
	 *
	 * @since 2.4
	 * @param array $settings Element settings.
	 * @param array $map      Translation map.
	 * @return array
	 */
	private function translate_preset_settings_strings( $settings, $map ) {
		if ( isset( $settings['text'] ) && is_string( $settings['text'] ) ) {
			$settings['text'] = $this->translate_preset_string( $settings['text'], $map );
		}

		if ( isset( $settings['buttonText'] ) && is_string( $settings['buttonText'] ) ) {
			$settings['buttonText'] = $this->translate_preset_string( $settings['buttonText'], $map );
		}

		foreach ( [ 'label', 'placeholder', 'stepLabel', 'title', 'ariaLabel' ] as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = $this->translate_preset_string( $settings[ $key ], $map );
			}
		}

		if ( ! empty( $settings['bars'] ) && is_array( $settings['bars'] ) ) {
			foreach ( $settings['bars'] as &$bar ) {
				if ( isset( $bar['title'] ) && is_string( $bar['title'] ) ) {
					$bar['title'] = $this->translate_preset_string( $bar['title'], $map );
				}
			}
			unset( $bar );
		}

		if ( ! empty( $settings['link'] ) && is_array( $settings['link'] ) ) {
			foreach ( [ 'title', 'ariaLabel' ] as $key ) {
				if ( isset( $settings['link'][ $key ] ) && is_string( $settings['link'][ $key ] ) ) {
					$settings['link'][ $key ] = $this->translate_preset_string( $settings['link'][ $key ], $map );
				}
			}
		}

		if ( ! empty( $settings['_attributes'] ) && is_array( $settings['_attributes'] ) ) {
			foreach ( $settings['_attributes'] as &$attribute ) {
				$attribute_name = isset( $attribute['name'] ) ? strtolower( (string) $attribute['name'] ) : '';

				if (
					in_array( $attribute_name, [ 'title', 'aria-label' ], true ) &&
					isset( $attribute['value'] ) &&
					is_string( $attribute['value'] )
				) {
					$attribute['value'] = $this->translate_preset_string( $attribute['value'], $map );
				}
			}
			unset( $attribute );
		}

		return $settings;
	}

	/**
	 * Translate a preset string when it exists in the generated map.
	 *
	 * @since 2.4
	 * @param string $value Source string.
	 * @param array  $map   Translation map.
	 * @return string
	 */
	private function translate_preset_string( $value, $map ) {
		return isset( $map[ $value ] ) ? $map[ $value ] : $value;
	}

	/**
	 * Get the active setup preset for an area.
	 *
	 * The advanced modular setting unlocks selectable v2 presets for supported
	 * page areas. Classic presets remain available as a fallback.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return array|null
	 */
	private function get_active_preset( $area ) {
		$area = sanitize_key( $area );

		if ( Woocommerce::use_advanced_modular_elements() ) {
			foreach ( self::$presets[ $area ] ?? [] as $preset ) {
				if ( ( $preset['mode'] ?? '' ) === 'advanced' && ( $preset['key'] ?? '' ) === 'v2' ) {
					return $preset;
				}
			}

			foreach ( self::$presets[ $area ] ?? [] as $preset ) {
				if ( ( $preset['mode'] ?? '' ) === 'advanced' ) {
					return $preset;
				}
			}
		}

		foreach ( self::$presets[ $area ] ?? [] as $preset ) {
			if ( ( $preset['mode'] ?? '' ) === 'classic' ) {
				return $preset;
			}
		}

		return null;
	}

	/**
	 * Get setup presets available to the current site mode.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return array
	 */
	private function get_available_presets( $area ) {
		$area    = sanitize_key( $area );
		$presets = [];

		foreach ( self::$presets[ $area ] ?? [] as $preset ) {
			if ( $this->is_preset_available( $preset ) ) {
				$presets[] = $preset;
			}
		}

		$this->sort_presets( $presets );

		return $presets;
	}

	/**
	 * Get sanitized preset options for the admin UI.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return array
	 */
	private function get_available_preset_options( $area ) {
		$area    = sanitize_key( $area );
		$presets = $this->supports_preset_selection( $area )
			? array_values( self::$presets[ $area ] ?? [] )
			: $this->get_available_presets( $area );

		$this->sort_presets( $presets );

		return array_map(
			function( $preset ) use ( $area ) {
				$disabled        = ! $this->is_preset_available( $preset );
				$disabled_reason = '';

				if ( $disabled && ( $preset['mode'] ?? '' ) === 'advanced' ) {
					$disabled_reason = __( 'Enable advanced modular elements to use this setup type.', 'bricks' );
				}

				return [
					'id'              => $preset['id'] ?? '',
					'label'           => $this->get_preset_label( $area, $preset ),
					'mode'            => $preset['mode'] ?? '',
					'disabled'        => $disabled,
					'disabled_reason' => $disabled_reason,
				];
			},
			$presets
		);
	}

	/**
	 * Sort setup presets so the current site mode is shown first.
	 *
	 * @since 2.4
	 * @param array $presets Presets passed by reference.
	 * @return void
	 */
	private function sort_presets( &$presets ) {
		usort(
			$presets,
			function( $a, $b ) {
				if ( ( $a['mode'] ?? '' ) === ( $b['mode'] ?? '' ) ) {
					return strcmp(
						$this->get_preset_label( $a['area'] ?? '', $a ),
						$this->get_preset_label( $b['area'] ?? '', $b )
					);
				}

				if ( Woocommerce::use_advanced_modular_elements() ) {
					return ( $a['mode'] ?? '' ) === 'advanced' ? -1 : 1;
				}

				return ( $a['mode'] ?? '' ) === 'classic' ? -1 : 1;
			}
		);
	}

	/**
	 * Check whether an area should show the setup type selector.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return bool
	 */
	private function supports_preset_selection( $area ) {
		return in_array( sanitize_key( $area ), [ 'cart', 'checkout', 'my_account' ], true );
	}

	/**
	 * Check if preset options include an advanced setup type.
	 *
	 * @since 2.4
	 * @param array $preset_options Sanitized preset options.
	 * @return bool
	 */
	private function preset_options_include_advanced( $preset_options ) {
		foreach ( $preset_options as $preset_option ) {
			if ( ( $preset_option['mode'] ?? '' ) === 'advanced' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the display label for a setup preset.
	 *
	 * @since 2.4
	 * @param string $area   WooCommerce area.
	 * @param array  $preset Setup preset.
	 * @return string
	 */
	private function get_preset_label( $area, $preset ) {
		if ( ( $preset['key'] ?? '' ) === 'v2-multistep' ) {
			return __( 'Advanced multistep (v2)', 'bricks' );
		}

		if ( ! empty( $preset['label'] ) ) {
			return $preset['label'];
		}

		$key = $preset['key'] ?? '';

		if ( $key === 'v1' ) {
			return __( 'Standard (v1)', 'bricks' );
		}

		if ( $key === 'v2' ) {
			return __( 'Advanced (v2)', 'bricks' );
		}

		return $preset['id'] ?? sanitize_key( $area );
	}

	/**
	 * Get the localized WooCommerce area label.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return string
	 */
	private function get_area_label( $area ) {
		$labels = [
			'cart'           => __( 'Cart', 'bricks' ),
			'checkout'       => __( 'Checkout', 'bricks' ),
			'my_account'     => __( 'My account', 'bricks' ),
			'shop'           => __( 'Shop', 'bricks' ),
			'single_product' => __( 'Single product', 'bricks' ),
		];

		return $labels[ $area ] ?? sanitize_key( $area );
	}

	/**
	 * Get the localized label for the v2 parent element used by an advanced preset.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce area.
	 * @return string
	 */
	private function get_advanced_preset_label( $area ) {
		$labels = [
			'cart'       => __( 'Cart v2', 'bricks' ),
			'checkout'   => __( 'Checkout v2', 'bricks' ),
			'my_account' => __( 'Account Page v2', 'bricks' ),
		];

		return $labels[ $area ] ?? $this->get_area_label( $area );
	}

	/**
	 * Get the main confirmation text for a setup preset.
	 *
	 * @since 2.4
	 * @param string $area   WooCommerce area.
	 * @param array  $preset Setup preset.
	 * @return string
	 */
	private function get_preset_confirm_description( $area, $preset ) {
		$area_label = $this->get_area_label( $area );

		if ( ( $preset['mode'] ?? '' ) === 'advanced' ) {
			return sprintf(
				/* translators: 1: WooCommerce area label, 2: v2 parent element label */
				__( 'This will configure the %1$s page with %2$s. Existing page content will be replaced. Traditional WooCommerce templates will not be modified. Continue?', 'bricks' ),
				$area_label,
				$this->get_advanced_preset_label( $area )
			);
		}

		if ( $area === 'single_product' ) {
			return __( 'This will create Bricks templates for Single Product. Existing templates for this area will not be drafted. Continue?', 'bricks' );
		}

		return sprintf(
			/* translators: %s: WooCommerce area label */
			__( 'This will create Bricks templates and configure the %s page. Existing templates for this area will be drafted. Existing page content will be modified. Continue?', 'bricks' ),
			$area_label
		);
	}

	/**
	 * Get the short setup notice shown before running a preset.
	 *
	 * @since 2.4
	 * @param string $area   WooCommerce area.
	 * @param array  $preset Setup preset.
	 * @return string
	 */
	private function get_preset_setup_notice( $area, $preset ) {
		if ( ( $preset['mode'] ?? '' ) !== 'advanced' ) {
			return '';
		}

		return sprintf(
			/* translators: 1: v2 parent element label, 2: WooCommerce area label */
			__( 'Advanced setup uses %1$s and does not require traditional %2$s templates.', 'bricks' ),
			$this->get_advanced_preset_label( $area ),
			$this->get_area_label( $area )
		);
	}

	/**
	 * Get the confirmation text for page-only setup.
	 *
	 * @since 2.4
	 * @param string $area   WooCommerce area.
	 * @param array  $preset Setup preset.
	 * @return string
	 */
	private function get_preset_page_setup_confirm( $area, $preset ) {
		$area_label = $this->get_area_label( $area );

		if ( ( $preset['mode'] ?? '' ) === 'advanced' ) {
			return sprintf(
				/* translators: 1: WooCommerce area label, 2: v2 parent element label */
				__( 'This will configure the %1$s page with %2$s. Existing content will be replaced.', 'bricks' ),
				$area_label,
				$this->get_advanced_preset_label( $area )
			);
		}

		return sprintf(
			/* translators: %s: WooCommerce area label */
			__( 'This will configure the %s page with Bricks elements. Existing content will be replaced.', 'bricks' ),
			$area_label
		);
	}

	/**
	 * Check whether a setup preset can be selected in the current site mode.
	 *
	 * @since 2.4
	 * @param array $preset Preset.
	 * @return bool
	 */
	private function is_preset_available( $preset ) {
		if ( ! is_array( $preset ) ) {
			return false;
		}

		if ( ( $preset['mode'] ?? '' ) !== 'advanced' ) {
			return true;
		}

		return Woocommerce::use_advanced_modular_elements() && in_array(
			$preset['area'] ?? '',
			[ 'cart', 'checkout', 'my_account' ],
			true
		);
	}

	/**
	 * Get a specific preset, or fall back to the active preset.
	 *
	 * @since 2.4
	 * @param string $area                WooCommerce area.
	 * @param string $preset_id           Preset ID.
	 * @param bool   $include_unavailable Whether unavailable presets can be returned.
	 * @return array|null
	 */
	private function get_preset( $area, $preset_id = '', $include_unavailable = false ) {
		$area      = sanitize_key( $area );
		$preset_id = sanitize_key( $preset_id );

		if (
			$preset_id &&
			! empty( self::$presets[ $area ][ $preset_id ] ) &&
			( $include_unavailable || $this->is_preset_available( self::$presets[ $area ][ $preset_id ] ) )
		) {
			return self::$presets[ $area ][ $preset_id ];
		}

		return $this->get_active_preset( $area );
	}

	/**
	 * Sanitize selected preset IDs from the admin request.
	 *
	 * @since 2.4
	 * @param mixed $value Selected presets array or JSON string.
	 * @return array
	 */
	private function sanitize_selected_presets( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( wp_unslash( $value ), true );
			$value   = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$selected = [];

		foreach ( $value as $area => $preset_id ) {
			$selected[ sanitize_key( $area ) ] = sanitize_key( $preset_id );
		}

		return $selected;
	}

	/**
	 * AJAX: Run 1-click setup for a WooCommerce area
	 *
	 * @since 2.4
	 * @return void (JSON response)
	 */
	public function ajax_run_setup() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to perform this action.', 'bricks' ) );
		}

		$area      = sanitize_key( $_POST['area'] ?? '' );
		$preset_id = sanitize_key( $_POST['preset'] ?? '' );
		$preset    = $this->get_preset( $area, $preset_id );

		if ( ! $preset ) {
			wp_send_json_error( __( 'No setup preset found for this area.', 'bricks' ) );
			return;
		}

		$force         = ! empty( $_POST['force'] ) && sanitize_key( $_POST['force'] ) === 'true';
		$scope         = sanitize_key( $_POST['scope'] ?? 'all' );
		$template_type = sanitize_key( $_POST['template_type'] ?? '' );

		if ( ! in_array( $scope, [ 'all', 'page', 'template' ], true ) ) {
			$scope = 'all';
		}

		$result = $this->run_setup_with_preset( $preset, $force, $scope, $template_type );

		if ( is_wp_error( $result ) ) {
			if ( $result->get_error_code() === 'page_has_bricks_data' ) {
				wp_send_json_error(
					[
						'code'    => 'page_has_bricks_data',
						'message' => $result->get_error_message(),
					]
				);
			} else {
				wp_send_json_error( $result->get_error_message() );
			}
			return;
		}

		wp_send_json_success(
			[
				'message'     => $result['message'],
				'area_status' => $this->get_area_status( $area, $preset['id'] ?? '' ),
			]
		);
	}

	/**
	 * AJAX: Move a Bricks template to trash
	 *
	 * @since 2.4
	 * @return void (JSON response)
	 */
	public function ajax_trash_template() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		$template_id = (int) ( $_POST['template_id'] ?? 0 );

		if ( ! $template_id || get_post_type( $template_id ) !== BRICKS_DB_TEMPLATE_SLUG ) {
			wp_send_json_error( __( 'Invalid template.', 'bricks' ) );
		}

		if ( ! current_user_can( 'delete_post', $template_id ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to perform this action.', 'bricks' ) );
		}

		$result = wp_trash_post( $template_id );

		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to trash template.', 'bricks' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Template moved to trash.', 'bricks' ) ] );
	}

	/**
	 * Execute the 1-click setup flow for the given preset, with optional scope control.
	 *
	 * Flow:
	 * 1. Validate the WooCommerce page is assigned.
	 * 2. Apply page edit step (clears Gutenberg blocks, writes Bricks elements).
	 * 3. Draft all published templates matching the preset’s required types.
	 * 4. Create new templates from the preset definitions.
	 *
	 * @since 2.4
	 * @param array  $preset        JSON preset.
	 * @param bool   $force         Whether confirmed page-content replacement is allowed.
	 * @param string $scope         Setup scope: all, page, or template.
	 * @param string $template_type Optional template type when scope=template.
	 * @return array|\WP_Error
	 */
	private function run_setup_with_preset( $preset, $force = false, $scope = 'all', $template_type = '', $template_mode = 'duplicate' ) {
		$area = sanitize_key( $preset['area'] ?? '' );

		if ( ! empty( $preset['requiresAdvancedModularElements'] ) && ! Woocommerce::use_advanced_modular_elements() ) {
			return new \WP_Error(
				'advanced_modular_disabled',
				__( 'WooCommerce advanced elements are disabled. Enable them in Bricks settings before running this setup.', 'bricks' )
			);
		}

		$required_settings = $this->enable_required_element_settings_for_preset( $preset );

		if ( is_wp_error( $required_settings ) ) {
			return $required_settings;
		}

		$page_id = false;

		// Resolve page ID and run the page guard only when the page edit step will run.
		if ( $scope !== 'template' && ! empty( $preset['requiresPageEdit'] ) ) {
			$wc_slug = $preset['page']['wcPage'] ?? '';
			$page_id = $wc_slug ? $this->get_wc_page_id( $wc_slug ) : false;

			if ( ! $page_id ) {
				return new \WP_Error(
					'no_page',
					sprintf(
						/* translators: %s: WooCommerce area name */
						__( 'No WooCommerce page assigned for %s. Check WooCommerce Settings > Advanced.', 'bricks' ),
						$area
					)
				);
			}

			// Guard: if the page already has Bricks data and the caller hasn't confirmed the overwrite
			if ( ! $force ) {
				$existing = get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
				if ( ! empty( $existing ) ) {
					$page_title = get_the_title( $page_id );
					return new \WP_Error(
						'page_has_bricks_data',
						sprintf(
							/* translators: %s: WooCommerce page title (e.g. "Cart", "Checkout") */
							__( 'The %s page already has Bricks data. Running setup will replace it.', 'bricks' ),
							$page_title
						)
					);
				}
			}
		}

		$log               = [];
		$page_revision_id  = null;
		$created_templates = [];
		$reused_templates  = [];
		$drafted_templates = 0;

		// STEP 1: Page edit — configure editor mode and write Bricks elements (scope: all or page)
		if ( $scope !== 'template' && ! empty( $preset['requiresPageEdit'] ) && $page_id ) {
			$page_elements = $this->get_preset_page_elements( $preset );

			if ( is_wp_error( $page_elements ) ) {
				return $page_elements;
			}

			$page_edit_result = $this->apply_page_edit_step( $page_id, $page_elements );

			if ( is_wp_error( $page_edit_result ) ) {
				return $page_edit_result;
			}

			if ( is_array( $page_edit_result ) && array_key_exists( 'revisionId', $page_edit_result ) ) {
				$page_revision_id = $page_edit_result['revisionId'];
			}

			$log[] = __( 'Page configured.', 'bricks' );
		}

		// STEP 2 & 3: Draft existing templates then create new ones (scope: all or template)
		if ( $scope !== 'page' ) {
			$template_defs = ! empty( $preset['templates'] ) && is_array( $preset['templates'] ) ? $preset['templates'] : [];

			// For template scope, filter to the requested type only.
			if ( $scope === 'template' && ! empty( $template_type ) ) {
				$template_defs = array_values(
					array_filter( $template_defs, fn( $def ) => $def['type'] === $template_type )
				);
			}

			if ( ! empty( $template_defs ) ) {
				// `reuse` mode: if a published template already exists for this type,
				// return its id without creating a new one (idempotent default for
				// MCP callers).
				if ( $template_mode === 'reuse' ) {
					$reuse_remaining = [];

					foreach ( $template_defs as $tpl_def ) {
						$existing_published = get_posts(
							[
								'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
								'post_status' => 'publish',
								'fields'      => 'ids',
								'numberposts' => 1,
								// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Setup checks for an existing published template of this type.
								'meta_query'  => [
									[
										'key'   => BRICKS_DB_TEMPLATE_TYPE,
										'value' => $tpl_def['type'],
									],
								],
							]
						);

						if ( ! empty( $existing_published ) ) {
							$existing_id        = (int) $existing_published[0];
							$reused_templates[] = [
								'id'    => $existing_id,
								'title' => get_the_title( $existing_id ),
								'type'  => $tpl_def['type'] ?? '',
							];

							$log[] = sprintf(
								/* translators: %s: template title */
								__( '"%s" template reused (already exists).', 'bricks' ),
								get_the_title( $existing_id )
							);

							continue;
						}

						$reuse_remaining[] = $tpl_def;
					}

					$template_defs = $reuse_remaining;
				}

				$drafted = 0;

				// `replace` mode: always draft existing published templates before
				// creating new ones, even if the preset would not normally do so.
				$should_draft = ! empty( $preset['shouldDraftExisting'] ) || $template_mode === 'replace';

				if ( $should_draft && ! empty( $template_defs ) ) {
					$drafted = $this->draft_existing_templates( array_column( $template_defs, 'type' ) );

					if ( is_wp_error( $drafted ) ) {
						return $drafted;
					}
				}

				$drafted_templates += (int) $drafted;

				if ( $drafted > 0 ) {
					$log[] = sprintf(
						/* translators: %d: number of templates */
						_n( '%d existing template drafted.', '%d existing templates drafted.', $drafted, 'bricks' ),
						$drafted
					);
				}

				foreach ( $template_defs as $tpl_def ) {
					$tpl_def['title'] = $tpl_def['title'] ?? $this->get_template_title( $tpl_def );

					// Only `duplicate` mode appends "(2)", "(3)" suffixes. `replace`
					// drafted any existing ones so the new title is free; `reuse`
					// already short-circuited and never reaches this loop body.
					if ( $template_mode === 'duplicate' ) {
						// Count existing templates of this specific type to derive a numeric suffix:
						// first-time setup > no suffix; first re-run > (2); second re-run > (3), etc.
						$existing_count = count(
							get_posts(
								[
									'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
									'post_status' => 'any',
									'fields'      => 'ids',
									'numberposts' => -1,
									// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Setup needs a type count for template title suffixes.
									'meta_query'  => [
										[
											'key'   => BRICKS_DB_TEMPLATE_TYPE,
											'value' => $tpl_def['type'],
										],
									],
								]
							)
						);
						$title_suffix   = $existing_count > 0 ? ' (' . ( $existing_count + 1 ) . ')' : '';

						if ( $title_suffix ) {
							$tpl_def['title'] .= $title_suffix;
						}
					}

					$id = $this->create_template_from_def( $tpl_def );

					if ( is_wp_error( $id ) ) {
						return $id;
					}

					$created_templates[] = [
						'id'    => (int) $id,
						'title' => $tpl_def['title'],
						'type'  => $tpl_def['type'] ?? '',
					];

					$log[] = sprintf(
						/* translators: %s: template title */
						__( '"%s" template created.', 'bricks' ),
						$tpl_def['title']
					);
				}
			}
		}

		// STEP 4: For the shop area, ensure the shop page is not locked to WordPress editor mode.
		// Templates won't render on the shop/archive pages if BRICKS_DB_EDITOR_MODE is 'wordpress'.
		if ( $scope !== 'template' && $area === 'shop' ) {
			$shop_page_id = $this->get_wc_page_id( 'shop' );
			if ( $shop_page_id && get_post_meta( $shop_page_id, BRICKS_DB_EDITOR_MODE, true ) === 'wordpress' ) {
				delete_post_meta( $shop_page_id, BRICKS_DB_EDITOR_MODE );
				$log[] = __( 'Shop page editor mode reset to allow Bricks templates.', 'bricks' );
			}
		}

		return [
			'message'          => implode( ' ', $log ),
			'pageId'           => $page_id ? (int) $page_id : null,
			'pageRevisionId'   => $page_revision_id,
			'draftedTemplates' => $drafted_templates,
			'createdTemplates' => $created_templates,
			'reusedTemplates'  => $reused_templates,
			'reusedExisting'   => ! empty( $reused_templates ),
		];
	}

	/**
	 * Apply the standardised page edit step:
	 * clears post_content (removes Gutenberg blocks) and writes Bricks elements.
	 *
	 * @since 2.4
	 * @param int   $page_id  WordPress page ID.
	 * @param array $elements Bricks elements array.
	 * @return array|\WP_Error
	 */
	private function apply_page_edit_step( $page_id, $elements ) {
		// Preserve trusted preset elements even when their types are unavailable at runtime. (#86catbj04; @since 2.4)
		$preset_element_types = $this->get_preset_element_types( $elements );
		$save_result          = \Bricks\Abilities\Save_Pipeline::execute( $page_id, $elements, 'content', $preset_element_types );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$updated_page = wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => '',
			],
			true
		);

		if ( is_wp_error( $updated_page ) ) {
			return $updated_page;
		}

		$saved_elements = get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
		if ( empty( $saved_elements ) && ! empty( $elements ) ) {
			return new \WP_Error(
				'page_content_update_failed',
				__( 'Failed to update Bricks page content.', 'bricks' )
			);
		}

		// Switch the page to Bricks editor mode so templates and elements are rendered by Bricks.
		update_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, 'bricks' );

		if ( get_post_meta( $page_id, BRICKS_DB_EDITOR_MODE, true ) !== 'bricks' ) {
			return new \WP_Error(
				'page_editor_mode_update_failed',
				__( 'Failed to switch the WooCommerce page to Bricks editor mode.', 'bricks' )
			);
		}

		// Generate external CSS file if enabled (save_post hook fired too early, before meta was written)
		$elements_for_css = is_array( $save_result ) && ! empty( $save_result['elements'] ) ? $save_result['elements'] : $elements;

		if ( Database::get_setting( 'cssLoading' ) === 'file' && ! empty( $elements_for_css ) ) {
			$area = Templates::get_template_type( $page_id );
			$area = $area ? $area : 'content';
			Assets_Files::generate_post_css_file( $page_id, $area, $elements_for_css );
		}

		return $save_result;
	}

	/**
	 * Get element types declared by the trusted setup preset.
	 *
	 * Setup presets are server-side data, not caller-authored element trees. Allow
	 * every declared type through runtime availability validation so elements
	 * disabled through Elements Manager, filters, plugins, or child themes remain
	 * in the saved page data. The builder handles unavailable elements when editing.
	 *
	 * (#86catbj04; @since 2.4)
	 *
	 * @param array $elements Preset elements about to be saved.
	 * @return string[] Preset element type names.
	 */
	private function get_preset_element_types( $elements ) {
		$element_types = [];

		foreach ( $elements as $element ) {
			$name = is_array( $element ) && is_string( $element['name'] ?? null ) ? $element['name'] : '';

			if ( ! $name ) {
				continue;
			}

			$element_types[ $name ] = true;
		}

		return array_keys( $element_types );
	}

	/**
	 * Enable Bricks Woo element gates required by the setup preset.
	 *
	 * Setup presets add Bricks-specific Woo elements. Turning on the matching
	 * setting before the save keeps those elements registered and renderable in
	 * the same request.
	 *
	 * @since 2.4
	 * @param array $preset Setup preset.
	 * @return true|\WP_Error
	 */
	private function enable_required_element_settings_for_preset( $preset ) {
		$required = $this->get_required_element_settings_for_preset( $preset );

		if ( empty( $required ) ) {
			return true;
		}

		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$changed = false;

		foreach ( $required as $setting_key ) {
			if ( empty( $settings[ $setting_key ] ) ) {
				$settings[ $setting_key ] = true;
				$changed                  = true;
			}
		}

		if ( ! $changed ) {
			return true;
		}

		$updated = update_option( BRICKS_DB_GLOBAL_SETTINGS, $settings );

		if ( ! $updated ) {
			return new \WP_Error(
				'woo_setup_settings_update_failed',
				__( 'Failed to enable the Bricks WooCommerce element settings required by this setup.', 'bricks' )
			);
		}

		Database::$global_settings         = $settings;
		Database::$global_data['settings'] = $settings;

		if ( isset( Theme::$instance->woocommerce ) && Theme::$instance->woocommerce instanceof Woocommerce ) {
			Theme::$instance->woocommerce->init_elements();
		}

		return true;
	}

	/**
	 * Get Bricks Woo settings needed by the preset.
	 *
	 * @since 2.4
	 * @param array $preset Setup preset.
	 * @return string[]
	 */
	private function get_required_element_settings_for_preset( $preset ) {
		$required = [];
		$area     = sanitize_key( $preset['area'] ?? '' );

		// Advanced checkout includes coupon/login in referenced state presets, not this JSON. (#123pt1p0yz8; @since 2.4.5)
		$is_advanced_checkout = $area === 'checkout' && ( $preset['mode'] ?? '' ) === 'advanced';

		if ( in_array( $area, [ 'shop', 'cart', 'checkout', 'my_account' ], true ) || ( $preset['mode'] ?? '' ) === 'advanced' ) {
			$required[] = 'woocommerceUseBricksWooNotice';
		}

		if ( $is_advanced_checkout || $this->preset_contains_element( $preset, 'woocommerce-checkout-coupon' ) ) {
			$required[] = 'woocommerceUseBricksWooCheckoutCoupon';
		}

		if ( $is_advanced_checkout || $this->preset_contains_element( $preset, 'woocommerce-checkout-login' ) ) {
			$required[] = 'woocommerceUseBricksWooCheckoutLogin';
		}

		return array_values( array_unique( $required ) );
	}

	/**
	 * Check whether preset JSON contains an element name.
	 *
	 * @since 2.4
	 * @param array  $preset       Setup preset.
	 * @param string $element_name Element name.
	 * @return bool
	 */
	private function preset_contains_element( $preset, $element_name ) {
		$encoded = wp_json_encode( $preset );

		return is_string( $encoded ) && strpos( $encoded, '"name":"' . $element_name . '"' ) !== false;
	}

	/**
	 * Get page elements from a JSON preset.
	 *
	 * @since 2.4
	 * @param array $preset JSON preset.
	 * @return array|\WP_Error
	 */
	private function get_preset_page_elements( $preset ) {
		$content = $preset['page']['content'] ?? [];

		if ( is_array( $content ) && ! empty( $content ) ) {
			$content = $this->translate_preset_elements( $content, $this->get_preset_translation_map() );

			return $this->assign_runtime_ids_flat( $content );
		}

		if ( ( $preset['mode'] ?? '' ) === 'advanced' ) {
			return $this->get_advanced_preset_page_elements( $preset );
		}

		return [];
	}

	/**
	 * Assemble v2 page elements from setup metadata and predefined element presets.
	 *
	 * @since 2.4
	 * @param array $preset Setup preset.
	 * @return array|\WP_Error
	 */
	private function get_advanced_preset_page_elements( $preset ) {
		$page           = ! empty( $preset['page'] ) && is_array( $preset['page'] ) ? $preset['page'] : [];
		$parent_element = sanitize_key( $page['advancedParent'] ?? '' );
		$states         = ! empty( $page['states'] ) && is_array( $page['states'] ) ? $page['states'] : [];

		if ( ! $parent_element || empty( $states ) ) {
			return new \WP_Error(
				'invalid_advanced_setup_preset',
				__( 'Advanced setup preset is missing page structure metadata.', 'bricks' )
			);
		}

		$translation_map = $this->get_preset_translation_map();
		$section_id      = 'woo-setup-section';
		$container_id    = 'woo-setup-container';
		$parent_id       = 'woo-setup-parent';

		$parent_children    = [];
		$elements           = [];
		$area               = sanitize_key( $preset['area'] ?? '' );
		$section_settings   = [];
		$container_settings = [];
		$parent_settings    = [];

		if ( ! empty( $page['sectionSettings'] ) && is_array( $page['sectionSettings'] ) ) {
			$section_settings = $page['sectionSettings'];
		}

		if ( ! empty( $page['containerSettings'] ) && is_array( $page['containerSettings'] ) ) {
			$container_settings = $page['containerSettings'];
		}

		if ( ! empty( $page['parentSettings'] ) && is_array( $page['parentSettings'] ) ) {
			$parent_settings = $page['parentSettings'];
		}

		$elements[] = [
			'id'       => $section_id,
			'name'     => 'section',
			'parent'   => 0,
			'children' => [ $container_id ],
			'settings' => $section_settings,
		];

		$elements[] = [
			'id'       => $container_id,
			'name'     => 'container',
			'parent'   => $section_id,
			'children' => [ $parent_id ],
			'settings' => $container_settings,
		];

		$parent_element_index = count( $elements );

		$elements[] = [
			'id'       => $parent_id,
			'name'     => $parent_element,
			'parent'   => $container_id,
			'children' => [],
			'settings' => $parent_settings,
		];

		foreach ( $states as $state_index => $state ) {
			if ( ! is_array( $state ) ) {
				continue;
			}

			$state_name      = sanitize_key( $state['name'] ?? '' );
			$state_preset_id = sanitize_key( $state['preset'] ?? '' );

			if ( ! $state_name || ! $state_preset_id ) {
				return new \WP_Error(
					'invalid_advanced_setup_state',
					__( 'Advanced setup preset is missing state preset metadata.', 'bricks' )
				);
			}

			// Build-time presets include optional states; availability is decided on the store.
			if ( ! $this->is_advanced_state_available( $state_name ) ) {
				continue;
			}

			$state_id       = 'woo-setup-state-' . $state_index;
			$state_settings = [];
			$state_children = $this->generate_advanced_state_children( $area, $state_name, $state_preset_id, $state );

			if ( ! empty( $state['settings'] ) && is_array( $state['settings'] ) ) {
				$state_settings = $state['settings'];
			}

			$flattened_state = is_wp_error( $state_children )
				? $state_children
				: $this->flatten_generated_children( $state_children, $state_id );

			if ( is_wp_error( $flattened_state ) ) {
				return $flattened_state;
			}

			$state_element = [
				'id'       => $state_id,
				'name'     => $state_name,
				'parent'   => $parent_id,
				'children' => $flattened_state['root_ids'],
				'settings' => $state_settings,
			];

			foreach ( [ 'label', 'cloneable', 'deletable' ] as $key ) {
				if ( array_key_exists( $key, $state ) ) {
					$state_element[ $key ] = $state[ $key ];
				}
			}

			$elements[]        = $this->translate_preset_element_strings( $state_element, $translation_map );
			$elements          = array_merge( $elements, $flattened_state['elements'] );
			$parent_children[] = $state_id;
		}

		$elements[ $parent_element_index ]['children'] = $parent_children;

		return $this->assign_runtime_ids_flat( $elements );
	}

	/**
	 * Generate nested children for one advanced state from a predefined element preset.
	 *
	 * @since 2.4
	 * @param string $area            WooCommerce setup area.
	 * @param string $state_name      State element name.
	 * @param string $state_preset_id Predefined preset ID.
	 * @param array  $state           State metadata.
	 * @return array|\WP_Error
	 */
	private function generate_advanced_state_children( $area, $state_name, $state_preset_id, $state ) {
		if ( ! class_exists( '\Bricks\Woocommerce_Predefined_Elements' ) ) {
			require_once BRICKS_PATH . 'includes/woocommerce/predefined-elements.php';
		}

		$provider       = new Woocommerce_Predefined_Elements();
		$generator_page = $this->get_advanced_state_generator_page( $area );
		$generator_area = $this->get_advanced_state_generator_area( $state_name );
		$state_settings = ! empty( $state['settings'] ) && is_array( $state['settings'] ) ? $state['settings'] : [];
		$is_multistep   = ! empty( $state['multistep'] ) || ! empty( $state_settings['generatePredefinedElementsMultistep'] );

		if ( ! $provider->supports( 'woocommerce', $generator_page, $generator_area ) ) {
			return new \WP_Error(
				'unsupported_advanced_state_preset',
				__( 'Advanced setup state preset is not supported.', 'bricks' )
			);
		}

		$checkout_fields = [];

		if ( $generator_page === 'checkout' ) {
			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->checkout() ) {
				return new \WP_Error(
					'checkout_unavailable',
					__( 'WooCommerce checkout is not available.', 'bricks' )
				);
			}

			$checkout_fields = WC()->checkout()->get_checkout_fields();
		}

		$generated = $provider->generate(
			'generate',
			$state_preset_id,
			[],
			[
				'generator_type'  => 'woocommerce',
				'generator_page'  => $generator_page,
				'generator_area'  => $generator_area,
				'checkout_fields' => $checkout_fields,
				'multistep'       => $is_multistep,
			]
		);

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		if ( ! empty( $generated['children'] ) && is_array( $generated['children'] ) ) {
			return $generated['children'];
		}

		return [];
	}

	/**
	 * Get predefined-element generator page for a setup area.
	 *
	 * @since 2.4
	 * @param string $area WooCommerce setup area.
	 * @return string
	 */
	private function get_advanced_state_generator_page( $area ) {
		if ( $area === 'my_account' ) {
			return 'myaccount';
		}

		return $area;
	}

	/**
	 * Get predefined-element generator area from a v2 state element name.
	 *
	 * @since 2.4
	 * @param string $state_name State element name.
	 * @return string
	 */
	private function get_advanced_state_generator_area( $state_name ) {
		$state_name = sanitize_key( $state_name );
		$prefixes   = [
			'woocommerce-cart-v2-state-'     => 'state-',
			'woocommerce-checkout-v2-state-' => 'state-',
			'woocommerce-account-v2-state-'  => 'state-',
		];

		foreach ( $prefixes as $prefix => $replacement ) {
			if ( strpos( $state_name, $prefix ) === 0 ) {
				return $replacement . substr( $state_name, strlen( $prefix ) );
			}
		}

		return '';
	}

	/**
	 * Convert nested predefined children to flat Bricks page-content elements.
	 *
	 * @since 2.4
	 * @param array  $children  Nested predefined children.
	 * @param string $parent_id Parent element ID.
	 * @return array
	 */
	private function flatten_generated_children( $children, $parent_id ) {
		$flat     = [];
		$root_ids = [];

		if ( ! is_array( $children ) ) {
			return [
				'elements' => $flat,
				'root_ids' => $root_ids,
			];
		}

		foreach ( $children as $child ) {
			$child_id = $this->flatten_generated_child( $child, $parent_id, $flat );

			if ( $child_id ) {
				$root_ids[] = $child_id;
			}
		}

		return [
			'elements' => $flat,
			'root_ids' => $root_ids,
		];
	}

	/**
	 * Flatten one nested predefined child.
	 *
	 * @since 2.4
	 * @param array  $element   Nested element.
	 * @param string $parent_id Parent element ID.
	 * @param array  $flat      Flat output accumulator.
	 * @return string
	 */
	private function flatten_generated_child( $element, $parent_id, &$flat ) {
		if ( ! is_array( $element ) || empty( $element['name'] ) ) {
			return '';
		}

		$nested_children = [];
		$element_id      = ! empty( $element['id'] )
			? sanitize_key( $element['id'] )
			: Helpers::generate_random_id( false );
		$flat_index      = count( $flat );

		if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
			$nested_children = $element['children'];
		}

		$element['id']       = $element_id;
		$element['parent']   = $parent_id;
		$element['children'] = [];
		$flat[]              = $element;

		foreach ( $nested_children as $child ) {
			$child_id = $this->flatten_generated_child( $child, $element_id, $flat );

			if ( $child_id ) {
				$flat[ $flat_index ]['children'][] = $child_id;
			}
		}

		return $element_id;
	}

	/**
	 * Get the template title for a generated template.
	 *
	 * @since 2.4
	 * @param array $tpl_def Template definition.
	 * @return string
	 */
	private function get_template_title( $tpl_def ) {
		$template_type = $tpl_def['type'] ?? '';
		$labels        = Woocommerce::get_woo_templates();
		$label         = $labels[ $template_type ] ?? sanitize_key( $template_type );

		return "[BricksWooWizard] $label";
	}

	/**
	 * Create a published Bricks template from a preset template definition.
	 *
	 * @since 2.4
	 * @param array $tpl_def Template definition.
	 * @return int|\WP_Error Inserted post ID or WP_Error on failure
	 */
	private function create_template_from_def( $tpl_def ) {
		$post_status = current_user_can( 'publish_posts' ) ? 'publish' : 'pending';

		$template_id = wp_insert_post(
			[
				'post_title'  => $tpl_def['title'],
				'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
				'post_status' => $post_status,
			]
		);

		if ( is_wp_error( $template_id ) || ! $template_id ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to create template.', 'bricks' ) );
		}

		$content  = $tpl_def['content'] ?? ( $tpl_def['elements'] ?? [] );
		$content  = is_array( $content ) ? $this->translate_preset_elements( $content, $this->get_preset_translation_map() ) : [];
		$elements = $this->assign_runtime_ids_flat( $content );
		$settings = $this->prepare_template_settings( $tpl_def );

		update_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, $tpl_def['type'] );
		// update_post_meta() unslashes values. Preserve Bricks strings like date formats containing literal backslashes.
		update_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, wp_slash( $elements ) );

		// Save template settings (e.g. populate content) if provided by the preset.
		if ( ! empty( $settings ) ) {
			Helpers::set_template_settings( $template_id, $settings );
		}

		// Generate external CSS file if enabled (save_post hook fired too early, before meta was written)
		if ( Database::get_setting( 'cssLoading' ) === 'file' && ! empty( $elements ) ) {
			Assets_Files::generate_post_css_file( $template_id, $tpl_def['type'], $elements );
		}

		return $template_id;
	}

	/**
	 * Prepare template settings with runtime data.
	 *
	 * @since 2.4
	 * @param array $tpl_def Template definition.
	 * @return array
	 */
	private function prepare_template_settings( $tpl_def ) {
		$settings = ! empty( $tpl_def['settings'] ) && is_array( $tpl_def['settings'] ) ? $tpl_def['settings'] : [];

		if ( ( $tpl_def['type'] ?? '' ) === 'wc_archive' ) {
			$shop_page_id = $this->get_wc_page_id( 'shop' );
			$conditions   = [
				[
					'main'                        => 'archiveType',
					'archiveType'                 => [ 'term' ],
					'archiveTerms'                => [ 'product_cat::all', 'product_tag::all' ],
					'archiveTermsIncludeChildren' => true,
				],
			];

			if ( $shop_page_id ) {
				$conditions[] = [
					'main' => 'ids',
					'ids'  => [ $shop_page_id ],
				];
			}

			$settings['templateConditions'] = $conditions;

			if ( $shop_page_id ) {
				$settings['templatePreviewType']   = 'single';
				$settings['templatePreviewPostId'] = (string) $shop_page_id;
			}
		}

		if ( ( $tpl_def['type'] ?? '' ) === 'wc_product' ) {
			// Ensure the generated template applies when default templates are disabled (#86caq8xgq).
			$settings['templateConditions'] = [
				[
					'main'     => 'postType',
					'postType' => [ 'product' ],
				],
			];

			$product_id = $this->find_sample_product_id();

			if ( $product_id ) {
				$settings['templatePreviewType']   = 'single';
				$settings['templatePreviewPostId'] = (string) $product_id;
			}
		}

		return $settings;
	}

	/**
	 * Assign fresh IDs to flat Bricks elements from JSON.
	 *
	 * @since 2.4
	 * @param array $elements Flat element array.
	 * @return array
	 */
	private function assign_runtime_ids_flat( $elements ) {
		if ( ! is_array( $elements ) ) {
			return [];
		}

		$id_map = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$id_map[ $element['id'] ] = Helpers::generate_random_id( false );
			}
		}

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$old_id = $element['id'] ?? '';

			if ( $old_id && isset( $id_map[ $old_id ] ) ) {
				$element['id'] = $id_map[ $old_id ];
			}

			if ( ! empty( $element['parent'] ) && isset( $id_map[ $element['parent'] ] ) ) {
				$element['parent'] = $id_map[ $element['parent'] ];
			}

			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as &$child_id ) {
					if ( isset( $id_map[ $child_id ] ) ) {
						$child_id = $id_map[ $child_id ];
					}
				}
				unset( $child_id );
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = $this->replace_runtime_value_recursive( $element['settings'], $id_map );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Replace old element ID references inside settings.
	 *
	 * @since 2.4
	 * @param mixed  $value       Setting value.
	 * @param array  $id_map      Old ID => runtime ID.
	 * @param string $setting_key Current setting key.
	 * @return mixed
	 */
	private function replace_runtime_value_recursive( $value, $id_map, $setting_key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child_value ) {
				$value[ $key ] = $this->replace_runtime_value_recursive( $child_value, $id_map, $key );
			}

			return $value;
		}

		if ( is_string( $value ) ) {
			if ( in_array( $setting_key, [ 'queryId', 'id' ], true ) && isset( $id_map[ $value ] ) ) {
				return $id_map[ $value ];
			}

			$value = preg_replace_callback(
				'/\{query_results_count:([^}]+)\}/',
				function( $matches ) use ( $id_map ) {
					$source_id = sanitize_key( $matches[1] );

					return isset( $id_map[ $source_id ] ) ? '{query_results_count:' . $id_map[ $source_id ] . '}' : $matches[0];
				},
				$value
			);

			if ( strpos( $value, '#brxe-' ) !== false ) {
				foreach ( $id_map as $source_id => $runtime_id ) {
					$value = str_replace( '#brxe-' . $source_id, '#brxe-' . $runtime_id, $value );
				}
			}

			return $value;
		}

		return $value;
	}

	/**
	 * Find a sample product for single product template preview.
	 *
	 * @since 2.4
	 * @return int
	 */
	private function find_sample_product_id() {
		$ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);

		return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Draft all published templates matching any of the given template types.
	 *
	 * @since 2.4
	 * @param string[] $types Template type keys.
	 * @return int|\WP_Error Number of templates set to draft or WP_Error on failure.
	 */
	private function draft_existing_templates( $types ) {
		$count = 0;

		foreach ( $types as $type ) {
			foreach ( Templates::get_templates_by_type( $type ) as $id ) {
				$post = get_post( $id );

				if ( $post && $post->post_status === 'publish' ) {
					$updated_template = wp_update_post(
						[
							'ID'          => $id,
							'post_status' => 'draft',
						],
						true
					);

					if ( is_wp_error( $updated_template ) ) {
						return $updated_template;
					}

					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Default status structure
	 *
	 * @param string $area
	 * @param string $status
	 * @param array  $page_warnings
	 * @param array  $template_warnings
	 * @return array
	 */
	private function default_status( $area = '', $status = 'not_configured', $page_warnings = [], $template_warnings = [] ) {
		return [
			'area'              => $area,
			'page_id'           => false,
			'page_title'        => '',
			'page_url'          => false,
			'templates'         => [],
			'status'            => $status,
			'page_warnings'     => $page_warnings,
			'template_warnings' => $template_warnings,
		];
	}
}

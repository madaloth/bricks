<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/woocommerce/order-withdrawal.php';
require_once __DIR__ . '/woocommerce/password-reset.php';

class Woocommerce {
	public static $product_categories = [];
	public static $product_tags       = [];
	public static $is_active          = false;
	public static $checkout_notices   = '';

	// Store preview contexts in builder for checkout v2 states, e.g. order ID (@since 2.4)
	public static $checkout_v2_preview_contexts = [];
	// Store login form arguments while rendering the Checkout v2 login-required state (@since 2.4)
	public static $checkout_v2_login_form_args = [];
	// Store preview contexts in builder for account v2 states, e.g. order ID (@since 2.4)
	public static $account_v2_preview_contexts = [];
	// Store Account orders query pagination metadata for Woo query loops (@since 2.4)
	private static $account_orders_query_meta = [];
	// Store request-level header/footer visibility overrides from the active Woo v2 state. (@since 2.4)
	private $v2_state_template_visibility = [];
	// Woo clears notices when printing them, but generated fields can render later and still need the same field-error metadata. (@since 2.4)
	private static $woo_notice_field_errors = null;
	// Store the IDs of the notices with field errors to be able to re-add the field error classes after WooCommerce clears the notices (e.g. when printing them in checkout/myaccount) (@since 2.4)
	private static $woo_notice_field_error_ids = null;
	// Include cart-dependent fragments only when a marked Checkout V2 request changed cart contents. (@since 2.4)
	private static $checkout_cart_updated = false;
	// Confirm a marked Checkout V2 removal to the frontend after Woo completes its checkout refresh. (@since 2.4)
	private static $checkout_cart_item_removed = false;

	/**
	 * Password reset notice prepared before the My Account shortcode renders.
	 *
	 * @since 2.3.12 #86cb6uj1x
	 * @var string
	 */
	private static $prepared_password_reset_notice = '';

	/**
	 * Resolve custom thumbnail sizes for a selected variation gallery.
	 *
	 * WooCommerce renders variation gallery HTML outside the Product Gallery element's
	 * image-size filters. This public, read-only endpoint accepts only registered sizes
	 * and published, available variations; it never accepts attachment IDs.
	 *
	 * @since 2.4.2
	 * @return void
	 */
	public function variation_thumbnail_sizes() {
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$size         = isset( $_POST['size'] ) && is_string( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '';

		if ( ! in_array( $size, array_merge( get_intermediate_image_sizes(), [ 'full' ] ), true ) ) {
			wp_send_json_error( null, 400 );
		}

		$variation = wc_get_product( $variation_id );
		$parent    = $variation && $variation->is_type( 'variation' ) ? wc_get_product( $variation->get_parent_id() ) : false;

		if ( ! $parent || ! $parent->is_type( 'variable' ) || ( $parent->get_status() !== 'publish' && ! current_user_can( 'read_post', $parent->get_id() ) ) || $variation->get_status() !== 'publish' || post_password_required( $parent->get_id() ) ) {
			wp_send_json_error( null, 404 );
		}

		// Match WC_Product_Variable::get_available_variations(), including its visibility filter. (#86cbj9ut2; @since 2.4.2)
		if ( ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $variation->is_in_stock() ) || ( apply_filters( 'woocommerce_hide_invisible_variations', true, $parent->get_id(), $variation ) && ! $variation->variation_is_visible() ) ) {
			wp_send_json_error( null, 404 );
		}

		$gallery          = new Product_Gallery( [ 'settings' => [ 'thumbnailImageSize' => $size ] ] );
		$gallery->product = $parent;

		wp_send_json_success( $gallery->get_variation_thumbnail_sizes( $variation ) );
	}

	public function __construct() {
		self::$is_active = self::is_woocommerce_active();

		if ( ! self::$is_active ) {
			// Make sure the WooCommerce templates are not loaded, in case CPT "product" is used
			add_filter( 'template_include', [ $this, 'no_woo_template_include' ], 1001 );

			return;
		}

		// Init WooCommerce Query Filters integrations
		Integrations\Query_Filters\WooCommerce::get_instance();

		// Remove Woo Asset Controller hook in the builder (@since 1.12)
		if ( bricks_is_builder() ) {
			add_action( 'init', [ $this, 'remove_woo_resource_hints' ], 15 );
		}

		add_filter( 'woocommerce_show_admin_notice', [ $this, 'show_admin_notice' ], 10, 2 );

		add_action( 'admin_notices', [ $this, 'admin_notice_outdated_template_files' ] );

		add_action( 'after_setup_theme', [ $this, 'add_theme_support' ] );

		add_action( 'init', [ $this, 'set_products_terms' ] );

		add_action( 'init', [ $this, 'init_elements' ] );

		add_action( 'init', [ $this, 'init_theme_styles' ], 9 );

		add_action( 'wp', [ $this, 'maybe_set_template_preview_content' ], 9 );
		add_action( 'wp', [ $this, 'set_v2_state_template_visibility' ], 11 );

		add_filter( 'bricks/database/is_template_disabled', [ $this, 'filter_v2_state_template_disabled' ], 10, 2 );

		// Disable default bricks title for WooCommerce pages if template is active (@since 1.8)
		add_filter( 'bricks/default_page_title', [ $this, 'default_page_title' ], 10, 2 );

		add_filter( 'bricks/element/maybe_set_aria_current_page', [ $this, 'maybe_set_aria_current_page' ], 10, 2 );

		add_filter( 'bricks/builder/supported_post_types', [ $this, 'bypass_builder_post_type_check' ], 10, 2 );

		// On the builder hook to set the panel elements first element category
		add_filter( 'bricks/builder/first_element_category', [ $this, 'set_first_element_category' ], 10, 3 );

		// Builder/Database: set the post id used to localize the builder data -> is_shop() page
		add_filter( 'bricks/builder/data_post_id', [ $this, 'maybe_set_post_id' ], 10, 1 );

		// Add Template Types to control options
		add_filter( 'bricks/setup/control_options', [ $this, 'add_template_types' ] );

		// Remove the template conditions for the Cart & Checkout template parts
		add_filter( 'builder/settings/template/controls_data', [ $this, 'remove_template_conditions' ], 9 );

		// During the active_templates search set proper content_type
		add_filter( 'bricks/database/content_type', [ $this, 'set_content_type' ], 10, 2 );

		// Preserve the product archive context when resolving templates from the shop page ID.
		add_filter( 'bricks/database/archive_post_type', [ $this, 'set_archive_post_type' ], 10, 2 );

		// Remove default WooCommerce styles
		add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );

		// Add WooCommerce specific link selectors to allow Theme Styles link styles to apply to WooCommerce elements (@since 1.5.7)
		add_filter( 'bricks/link_css_selectors', [ $this, 'link_css_selectors' ], 10, 1 );

		// Enqueue Bricks WooCommerce custom styles
		add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ], 10 );

		// Is RTL: enqueue RTL CSS file after main CSS file (@since 2.0)
		if ( is_rtl() ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts_rtl' ], 15 );
		}

		// add_action( 'wp_enqueue_scripts', [ $this, 'unload_photoswipe5_lightbox_assets' ] );

		// Product archive hooks
		add_action( 'bricks/archive_product/before', [ $this, 'setup_query' ], 10, 2 );
		add_action( 'bricks/archive_product/after', [ $this, 'reset_query' ], 10, 2 );

		// Capture after session hydration, before Woo's cart form handler at priority 20.
		add_action( 'wp_loaded', [ $this, 'track_cart_contents_request' ], 19 );

		// Mini cart fragments
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'update_mini_cart' ], 10, 1 );

		// Breadcrumb separator
		add_filter( 'woocommerce_breadcrumb_defaults', [ $this, 'breadcrumb_separator' ] );
		add_filter( 'woocommerce_get_breadcrumb', [ $this, 'add_breadcrumbs_from_filters' ], 10, 2 );

		/**
		 * Quantity input field: Add plus/minus buttons
		 *
		 * @since 1.7 - Render button after the input in order to hide it if input[type="hidden"]
		 */
		add_action( 'woocommerce_after_quantity_input_field', [ $this, 'quantity_input_field_add_minus_button' ] );
		add_action( 'woocommerce_after_quantity_input_field', [ $this, 'quantity_input_field_add_plus_button' ] );

		// Product tabs: Remove panel titles
		add_filter( 'woocommerce_product_description_heading', '__return_false' );
		add_filter( 'woocommerce_product_additional_information_heading', '__return_false' );
		add_filter( 'woocommerce_reviews_title', '__return_false' );

		// On Sale HTML
		add_filter( 'woocommerce_sale_flash', [ $this, 'badge_sale' ], 10, 3 );

		// Single product
		add_action( 'woocommerce_before_shop_loop_item_title', [ $this, 'badge_new' ], 9 );
		add_filter( 'woocommerce_product_review_comment_form_args', [ $this, 'product_review_comment_form_args' ] );

		// Query loop: using the query loop builder for products
		add_filter( 'bricks/posts/merge_query', [ $this, 'maybe_merge_query' ], 10, 2 );
		add_filter( 'bricks/posts/query_vars', [ $this, 'set_products_query_vars' ], 10, 4 );

		// Query: Add Woo Cart contents
		add_filter( 'bricks/setup/control_options', [ $this, 'add_control_options' ], 10, 1 );
		add_filter( 'bricks/query/run', [ $this, 'run_woo_query' ], 10, 2 );
		add_filter( 'bricks/query/result_count', [ $this, 'set_woo_query_result_count' ], 10, 2 );
		add_filter( 'bricks/query/result_max_num_pages', [ $this, 'set_woo_query_result_max_num_pages' ], 10, 2 );
		// Woo Phase 3
		// add_filter( 'bricks/query/run', [ $this, 'run_my_acc_menu_items' ], 10, 2 );
		add_filter( 'bricks/query/loop_object', [ $this, 'set_loop_object' ], 10, 3 );

		// TODO: Needed?
		add_filter( 'bricks/query/loop_object_id', [ $this, 'set_loop_object_id' ], 10, 3 );
		add_filter( 'bricks/query/loop_object_type', [ $this, 'set_loop_object_type' ], 10, 3 );

		add_filter( 'post_class', [ $this, 'post_class' ], 10, 3 );

		// Checkout: Make sure the fields removed by the user inside the builder are not required during the checkout process (@since 1.5.7)
		add_filter( 'woocommerce_checkout_fields', [ $this, 'woocommerce_checkout_fields' ], 99, 1 );
		add_filter( 'woocommerce_checkout_redirect_empty_cart', [ $this, 'disable_empty_cart_checkout_redirect_in_builder' ], 10, 1 );

		// Maybe remove ajax_add_to_cart class to avoid native AJAX add to cart (#86c993p6a @since 2.3.3)
		add_filter( 'woocommerce_loop_add_to_cart_args', [ $this, 'maybe_remove_native_ajax_class' ], 10, 2 );

		// @since 1.6.1 - AJAX Add to cart
		if ( self::enabled_ajax_add_to_cart() ) {
			add_action( 'wc_ajax_bricks_add_to_cart', [ $this, 'add_to_cart' ] );
			add_action( 'wc_ajax_nopriv_bricks_add_to_cart', [ $this, 'add_to_cart' ] );
			add_filter( 'woocommerce_loop_add_to_cart_args', [ $this, 'overwrite_native_ajax_add_to_cart' ], 10, 2 );
		}

		add_action( 'wc_ajax_bricks_update_cart_item_quantity', [ $this, 'update_cart_item_quantity' ] );
		add_action( 'wc_ajax_nopriv_bricks_update_cart_item_quantity', [ $this, 'update_cart_item_quantity' ] );

		if ( self::use_advanced_modular_elements() ) {
			add_action( 'woocommerce_checkout_update_order_review', [ $this, 'update_checkout_cart_items' ], 1 );
		}

		// WC_AJAX dispatches wc_ajax_* for guests and logged-in shoppers alike. (#86cbj9ut2; @since 2.4.2)
		add_action( 'wc_ajax_bricks_variation_thumbnail_sizes', [ $this, 'variation_thumbnail_sizes' ] );

		add_action( 'wc_ajax_bricks_get_woo_dynamic_fragments', [ $this, 'get_woo_dynamic_fragments' ] );
		add_action( 'wc_ajax_nopriv_bricks_get_woo_dynamic_fragments', [ $this, 'get_woo_dynamic_fragments' ] );
		add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'add_woo_dynamic_fragments_to_response' ] );

		// @since 1.7 - Remove / Restore Woo native hook actions when using {do_action}
		add_filter( 'bricks/dynamic_data/do_action_context', [ $this, 'resolve_woo_do_action_context' ], 10, 4 );
		add_action( 'bricks/dynamic_data/before_do_action', [ $this, 'maybe_remove_woo_hook_actions' ], 10, 4 );
		add_action( 'bricks/dynamic_data/after_do_action', [ $this, 'maybe_restore_woo_hook_actions' ], 10, 5 );

		// @since 1.8.1 - Bricks WooCommerce Notice
		self::maybe_remove_native_woocommerce_notices_hooks();

		// Woo Phase 3 - Add body classes ('woo' or 'bricks')
		add_filter( 'body_class', [ $this, 'maybe_set_body_class' ], 10, 1 );

		// Woo Phase 3 - Add class when previewing a Woo template
		add_filter( 'bricks/content/attributes', [ $this, 'template_preview_main_classes' ], 10, 2 );

		// Woo Phase 3 - My account endpoints: Render Bricks template in account content areas (e.g. Oders, Downloads, Addresses, etc.)
		add_action( 'woocommerce_account_content', [ $this, 'add_my_account_content' ], 1 );

		// Woo Phase 3 - Set account navigation active class in builder
		add_filter( 'woocommerce_account_menu_item_classes', [ $this, 'woocommerce_account_menu_item_classes' ], 10, 2 );

		// @since 1.9 - Add quantity input field looping products
		if ( self::use_quantity_in_loop() ) {
			add_action( 'woocommerce_loop_add_to_cart_link', [ $this, 'add_quantity_input_field' ], 10, 2 );
		}

		// @since 2.2 - Set sync option in woocommerce.js or the 1st occurence of thumbnail slider always used (#86c4vhehz)
		// @since 1.9 - Sync Woocommerce product flexslider with Bricks thumbnail slider
		// add_filter( 'woocommerce_single_product_carousel_options', [ $this, 'single_product_carousel_options' ] );

		add_filter( 'bricks/builder/dynamic_wrapper', [ $this, 'builder_dynamic_wrapper' ] );

		add_action( 'template_redirect', [ $this, 'template_redirect' ] );

		// Initialize variation swatches (@since 2.0)
		new \Bricks\Woocommerce\Product_Variation_Swatches();

		// Search criteris (@since 2.2)
		add_filter( 'bricks/combined_search/post_ids', [ $this, 'maybe_include_product_parent_ids' ], 10, 6 );

		// Builder: Generate predefined Woo elements (@since 2.4)
		add_action( 'wp_ajax_bricks_generate_predefined_elements', [ $this, 'generate_predefined_elements' ] );

		// Update order review fragments in checkout when using checkout v2 (@since 2.4)
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'update_order_review_fragments' ], 10 );
	}

	/**
	 * Dont't show WooCommerce outdated template files admin notice
	 *
	 * Show custom Bricks message instead.
	 *
	 * @since 1.9.8
	 */
	public function show_admin_notice( $true, $notice ) {
		if ( $notice === 'template_files' ) {
			return false;
		}

		return $true;
	}

	/**
	 * Show custom message for outdated WooCommerce template files
	 *
	 * @since 1.9.8
	 */
	public function admin_notice_outdated_template_files() {
		// Check: Has WooCommerce outdated template files
		ob_start();
		\WC_Admin_Notices::template_file_check_notice();
		$outdated_template_files = ob_get_clean();

		// Show custom message for outdated WooCommerce template files
		$notices = \WC_Admin_Notices::get_notices();

		if ( is_array( $notices ) && in_array( 'template_files', $notices ) ) {
			$theme = wp_get_theme();
			?>
		<div class="notice notice-info">
			<p>
				<?php /* translators: %s: theme name */ ?>
				<strong><?php printf( __( 'Your theme (%s) contains outdated copies of some WooCommerce template files.', 'bricks' ), esc_html( $theme['Name'] ) ); ?></strong>
			</p>

			<p>
				<?php /* translators: %1$s: theme name */ ?>
				<?php printf( __( 'Notice from %1$s: But don\'t worry. %1$s regularly updates all WooCommerce template files with each new release. If you are on the latest version and see this message, any necessary compatibility enhancements will be included in the next update.', 'bricks' ), esc_html( $theme['Name'] ) ); ?>
			</p>

			<p>
				<a class="button-primary" href="https://woocommerce.com/document/template-structure/" target="_blank"><?php esc_html_e( 'Learn more about templates', 'woocommerce' ); ?></a>
				<a class="button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status' ) ); ?>" target="_blank"><?php esc_html_e( 'View affected templates', 'woocommerce' ); ?></a>
			</p>

			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wc-hide-notice', 'template_files' ), 'woocommerce_hide_notices_nonce', '_wc_notice_nonce' ) ); ?>"><?php esc_html_e( 'Dismiss', 'woocommerce' ); ?></a>
		</div>
			<?php
		}
	}

	/**
	 * Generate predefined WooCommerce elements for builder state blocks.
	 *
	 * @since 2.4
	 */
	public function generate_predefined_elements() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		if ( ! self::use_advanced_modular_elements() ) {
			// Generation writes v2-only element trees, so block it before loading presets.
			wp_send_json_error( esc_html__( 'WooCommerce advanced elements are disabled. Enable them in the settings to generate predefined elements.', 'bricks' ) );
		}

		if ( ! class_exists( '\Bricks\Woocommerce_Predefined_Elements' ) ) {
			require_once BRICKS_PATH . 'includes/woocommerce/predefined-elements.php';
		}

		$operation      = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : 'generate';
		$generator_type = isset( $_POST['generatorType'] ) ? sanitize_key( $_POST['generatorType'] ) : '';
		$generator_page = isset( $_POST['generatorPage'] ) ? sanitize_key( $_POST['generatorPage'] ) : '';
		$generator_area = isset( $_POST['generatorArea'] ) ? sanitize_key( $_POST['generatorArea'] ) : '';
		$target_element = isset( $_POST['targetElement'] ) ? sanitize_key( $_POST['targetElement'] ) : '';
		$preset         = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : '';
		$existing_keys  = isset( $_POST['existingFieldKeys'] ) && is_array( $_POST['existingFieldKeys'] ) ? array_map( 'sanitize_text_field', $_POST['existingFieldKeys'] ) : [];
		$field_elements = [];
		if ( isset( $_POST['fieldElements'] ) && is_array( $_POST['fieldElements'] ) ) {
			foreach ( $_POST['fieldElements'] as $field_element ) {
				if ( ! is_array( $field_element ) ) {
					continue;
				}

				$field_elements[] = [
					'id'          => isset( $field_element['id'] ) ? sanitize_text_field( $field_element['id'] ) : '',
					'fieldKey'    => isset( $field_element['fieldKey'] ) ? sanitize_text_field( $field_element['fieldKey'] ) : '',
					'fieldSource' => isset( $field_element['fieldSource'] ) ? sanitize_text_field( $field_element['fieldSource'] ) : '',
					'label'       => isset( $field_element['label'] ) ? sanitize_text_field( $field_element['label'] ) : '',
				];
			}
		}

		// Account fields must be inserted into their lifecycle wrapper instead of
		// becoming direct Checkout state children. (#86cb33dre; @since 2.4)
		$remove_billing_fields     = isset( $_POST['removeBillingFields'] ) && is_array( $_POST['removeBillingFields'] ) ? array_map( 'sanitize_text_field', $_POST['removeBillingFields'] ) : [];
		$remove_shipping_fields    = isset( $_POST['removeShippingFields'] ) && is_array( $_POST['removeShippingFields'] ) ? array_map( 'sanitize_text_field', $_POST['removeShippingFields'] ) : [];
		$account_fields_wrapper_id = isset( $_POST['accountFieldsWrapperId'] ) ? sanitize_text_field( $_POST['accountFieldsWrapperId'] ) : '';
		$multistep                 = ! empty( $_POST['multistep'] );
		// Not implemented yet. Reserved for potential future use
		$refresh_remote = ! empty( $_POST['refreshRemote'] );

		$provider = new Woocommerce_Predefined_Elements();

		if ( ! $provider->supports( $generator_type, $generator_page, $generator_area ) ) {
			wp_send_json_error( esc_html__( 'Generator target not implemented yet.', 'bricks' ) );
		}

		// Account edit-address field maintenance uses the address registry, so avoid booting Checkout on non-checkout pages.
		$field_maintenance_operations            = [ 'audit-fields', 'generate-missing-fields', 'remove-invalid-fields', 'remove-duplicate-fields' ];
		$checkout_field_operations               = array_merge( [ 'sync-missing' ], $field_maintenance_operations );
		$is_account_edit_address_field_operation = $generator_page === 'myaccount' && $generator_area === 'state-edit-address' && in_array( $operation, $field_maintenance_operations, true );
		$needs_checkout_fields                   = $generator_page === 'checkout' || ( in_array( $operation, $checkout_field_operations, true ) && ! $is_account_edit_address_field_operation );

		if ( $needs_checkout_fields && ( ! function_exists( 'WC' ) || ! WC() || ! WC()->checkout() ) ) {
			wp_send_json_error( esc_html__( 'WooCommerce checkout is not available.', 'bricks' ) );
		}

		$checkout_fields = $needs_checkout_fields ? WC()->checkout()->get_checkout_fields() : [];
		$generated       = $provider->generate(
			$operation,
			$preset,
			$existing_keys,
			[
				'generator_type'            => $generator_type,
				'generator_page'            => $generator_page,
				'generator_area'            => $generator_area,
				'target_element'            => $target_element,
				'checkout_fields'           => $checkout_fields,
				'refresh_remote'            => $refresh_remote,
				'field_elements'            => $field_elements,
				'remove_billing_fields'     => $remove_billing_fields,
				'remove_shipping_fields'    => $remove_shipping_fields,
				'account_fields_wrapper_id' => $account_fields_wrapper_id,
				'multistep'                 => $multistep,
			]
		);

		if ( is_wp_error( $generated ) ) {
			wp_send_json_error( $generated->get_error_message() );
		}

		wp_send_json_success( $generated );
	}

	/**
	 * My account endpoint template preview: Redirect to actual my account endpoint
	 *
	 * To render entire my account area (navigation + content)
	 *
	 * @since 1.9
	 */
	public function template_redirect() {
		// Return: Not frontend nor a Bricks template
		if ( ! bricks_is_frontend() || ! is_singular( BRICKS_DB_TEMPLATE_SLUG ) ) {
			return;
		}

		$template_type = Templates::get_template_type();

		switch ( $template_type ) {
			case 'wc_account_dashboard':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'dashboard' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_orders':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'orders' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_view_order':
				// Get 'previewOrderId' from Bricks template data
				$elements = get_post_meta( get_the_ID(), BRICKS_DB_PAGE_CONTENT, true );
				$order_id = '';

				if ( is_array( $elements ) ) {
					foreach ( $elements as $element ) {
						if ( $element['name'] === 'woocommerce-account-view-order' && ! empty( $element['settings']['previewOrderId'] ) ) {
							$order_id = $element['settings']['previewOrderId'];
							break;
						}
					}
				}

				// No previewOrderId set: Get last order from WooCommerce
				if ( ! $order_id ) {
					$orders = wc_get_orders( [ 'limit' => 1 ] );

					if ( isset( $orders[0] ) ) {
						$order_id = $orders[0]->get_id();
					}
				}

				$redirect_url = wc_get_account_endpoint_url( 'view-order' );

				// Order ID found: Redirect to order view
				if ( $order_id ) {
					$redirect_url .= "/$order_id\/";
					$redirect_url  = add_query_arg( 'bricks_preview', time(), $redirect_url );
					wp_safe_redirect( $redirect_url, 301 );
				}

				break;

			case 'wc_account_downloads':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'downloads' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_addresses':
			case 'wc_account_form_edit_address':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'edit-address' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_form_edit_account':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'edit-account' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_payment_methods':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'payment-methods' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;

			case 'wc_account_add_payment_method':
				$redirect_url = add_query_arg( 'bricks_preview', time(), wc_get_account_endpoint_url( 'add-payment-method' ) );
				wp_safe_redirect( $redirect_url, 301 );
				break;
		}
	}   /**
		 * Woo Phase 3: Get #brx-content HTML as rendered on the frontend
		 *
		 * To render complete my account (navigation + content)
		 * and move dynamic drag & drop area into my account content div.
		 *
		 * @since 1.9
		 */
	public function builder_dynamic_wrapper( $dynamic_area = [] ) {
		$template_type = Templates::get_template_type();

		if ( in_array(
			$template_type,
			[
				'wc_account_dashboard',
				'wc_account_orders',
				'wc_account_view_order',
				'wc_account_downloads',
				'wc_account_payment_methods',
				'wc_account_add_payment_method',
				'wc_account_addresses',
				'wc_account_form_edit_address',
				'wc_account_form_edit_account',
			]
		)
		) {
			// STEP: Get My account page Bricks data
			$my_account_page_id = wc_get_page_id( 'myaccount' );
			$elements           = Helpers::render_with_bricks( $my_account_page_id ) ? get_post_meta( $my_account_page_id, BRICKS_DB_PAGE_CONTENT, true ) : false;

			if ( is_array( $elements ) && ! empty( $elements ) ) {
				ob_start();
				Frontend::render_content( $elements );
				$html = ob_get_clean();

				if ( $html ) {
					// Generate my account page CSS
					$css = Templates::generate_inline_css( $my_account_page_id, $elements );

					// My Account page classes are only discovered while building this dynamic wrapper.
					// Include them in its CSS payload (#86c6cn54a; @since 2.3.11).
					$css .= Assets::generate_global_classes( 'global_classes_woocommerce_account' );
					$css .= Assets::$inline_css_dynamic_data;

					$dynamic_area = [
						'css'      => $css,
						'html'     => $html,
						'selector' => '.woocommerce-MyAccount-content',
					];
				}
			}

			/**
			 * STEP: Fallback: Use default WooCommerce my account shortcode
			 *
			 * Manually add main#brx-content as not available in TheDynamicArea.vue
			 */
			else {
				ob_start();
				echo '<main id="brx-content" class="wordpress" style="margin: 0 auto">';
				echo do_shortcode( '[woocommerce_my_account]' );
				echo '</main>';
				$html = ob_get_clean();

				$dynamic_area = [
					'css'      => '',
					'html'     => $html,
					'selector' => '.woocommerce-MyAccount-content',
				];
			}
		}

		return $dynamic_area;
	}

	/**
	 * Woo Phase 3 - Check if current page is my account dashboard page
	 *
	 * @see includes/wc-template-functions.php woocommerce_account_content()
	 */
	public static function is_wc_account_dashboard() {
		global $wp;

		if ( ! empty( $wp->query_vars ) ) {
			foreach ( $wp->query_vars as $key => $value ) {
				if ( $key === 'pagename' ) {
					continue;
				}

				if ( has_action( 'woocommerce_account_' . $key . '_endpoint' ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * My account: Render Bricks template data if available
	 *
	 * @since 1.9
	 */
	public function add_my_account_content() {
		$template_data         = null;
		$account_v2_state_data = self::get_account_v2_state_render_data();

		if ( $account_v2_state_data ) {
			if (
				! ( bricks_is_builder() || bricks_is_builder_call() ) &&
				self::get_account_v2_current_state() === 'view-order' &&
				! self::get_contextual_account_v2_order( 'view-order' )
			) {
				// Let WooCommerce render its native invalid-order notice.
				return;
			}

			echo Frontend::render_data( $account_v2_state_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			remove_action( 'woocommerce_account_content', 'woocommerce_account_content' );

			return;
		}

		// Orders
		if ( is_wc_endpoint_url( 'orders' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_orders' );
		}

		// Downloads
		elseif ( is_wc_endpoint_url( 'downloads' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_downloads' );
		}

		// Payment methods (@since 2.2)
		elseif ( is_wc_endpoint_url( 'payment-methods' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_payment_methods' );
		}

		// Add payment method (@since 2.2)
		elseif ( is_wc_endpoint_url( 'add-payment-method' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_add_payment_method' );
		}

		// Edit account
		elseif ( is_wc_endpoint_url( 'edit-account' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_form_edit_account' );
		}

		// View order
		elseif ( is_wc_endpoint_url( 'view-order' ) ) {
			$template_data = self::get_template_data_by_type( 'wc_account_view_order' );
		}

		// Addresses
		elseif ( is_wc_endpoint_url( 'edit-address' ) ) {
			global $wp;

			// View addresses
			if ( empty( $wp->query_vars['edit-address'] ) ) {
				$template_data = self::get_template_data_by_type( 'wc_account_addresses' );
			}

			// Edit address form requested (billing or shipping)
			else {
				$template_data = self::get_template_data_by_type( 'wc_account_form_edit_address' );
			}
		}

		// Dashboard
		elseif ( self::is_wc_account_dashboard() ) {
			$template_data = self::get_template_data_by_type( 'wc_account_dashboard' );
		}

		// Render Bricks template data & remove default WooCommerce content (@since 1.10)
		if ( $template_data ) {
			echo $template_data;

			remove_action( 'woocommerce_account_content', 'woocommerce_account_content' );
		}
	}

	/**
	 * Woo Phase 3 - Set account navigation active class in builder
	 *
	 * @since 1.9
	 */
	public function woocommerce_account_menu_item_classes( $classes, $endpoint ) {
		if ( ! bricks_is_builder_iframe() ) {
			return $classes;
		}

		$template_type = Templates::get_template_type();

		// Endpoint: Orders
		if ( in_array( $template_type, [ 'wc_account_orders', 'wc_account_view_order' ] ) ) {
			if ( $endpoint === 'orders' ) {
				$classes[] = 'is-active';
			} else {
				// Filter out 'is-active' class
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		// Endpoint: Downloads
		if ( $template_type === 'wc_account_downloads' ) {
			if ( $endpoint === 'downloads' ) {
				$classes[] = 'is-active';
			} else {
				// Filter out 'is-active' class
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		// Endpoint: Payment methods
		if ( $template_type === 'wc_account_payment_methods' ) {
			if ( $endpoint === 'payment-methods' ) {
				$classes[] = 'is-active';
			} else {
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		// Endpoint: Add payment method
		if ( $template_type === 'wc_account_add_payment_method' ) {
			if ( $endpoint === 'add-payment-method' ) {
				$classes[] = 'is-active';
			} else {
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		// Endpoint: Addresses
		if ( in_array( $template_type, [ 'wc_account_addresses', 'wc_account_form_edit_address' ] ) ) {
			if ( $endpoint === 'edit-address' ) {
				$classes[] = 'is-active';
			} else {
				// Filter out 'is-active' class
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		// Endpoint: Edit account
		if ( $template_type === 'wc_account_form_edit_account' ) {
			if ( $endpoint === 'edit-account' ) {
				$classes[] = 'is-active';
			} else {
				// Filter out 'is-active' class
				$classes = array_filter(
					$classes,
					function( $class ) {
						return $class !== 'is-active';
					}
				);
			}
		}

		return $classes;
	}

	/**
	 * Sync Woocommerce product flexslider with Bricks thumbnail slider
	 *
	 * @since 1.9
	 */
	public function single_product_carousel_options( $options ) {
		$options['sync'] = '.brx-product-gallery-thumbnail-slider';

		return $options;
	}

	/**
	 * Remove checkout fields selected in the classic template or Checkout v2 state.
	 *
	 * @since 1.5.7
	 */
	public function woocommerce_checkout_fields( $fields ) {
		if ( ! is_checkout() ) {
			return $fields;
		}

		foreach ( self::get_removed_checkout_fields() as $section => $field_keys ) {
			foreach ( $field_keys as $field_key ) {
				unset( $fields[ $section ][ $field_key ] );
			}
		}

		return $fields;
	}

	/**
	 * Get the field removal lists shared by WooCommerce processing and modular rendering.
	 *
	 * @since 2.4.5
	 *
	 * @return array Billing and shipping field keys removed by Bricks settings.
	 */
	public static function get_removed_checkout_fields() {
		$elements = [];

		// Legacy checkout template setup: read controls from wc_form_checkout template.
		$templates = Templates::get_templates_by_type( 'wc_form_checkout' );
		if ( ! empty( $templates[0] ) ) {
			$template_elements = get_post_meta( $templates[0], BRICKS_DB_PAGE_CONTENT, true );
			if ( is_array( $template_elements ) ) {
				$elements = $template_elements;
			}
		}

		$customer_details_settings = [];

		// Get settings of legacy "Checkout customer details" element.
		foreach ( $elements as $element ) {
			if ( $element['name'] === 'woocommerce-checkout-customer-details' && ! empty( $element['settings'] ) ) {
				$customer_details_settings = $element['settings'];
			}
		}

		$removed_fields = [
			'billing'  => [],
			'shipping' => [],
		];

		// A retained classic template still affects the registry on v2 checkouts.
		$settings_sources = [ $customer_details_settings, self::get_checkout_v2_state_settings( 'checkout' ) ];
		foreach ( $settings_sources as $settings ) {
			foreach ( [
				'billing'  => 'removeBillingFields',
				'shipping' => 'removeShippingFields'
			] as $section => $control_key ) {
				if ( is_array( $settings[ $control_key ] ?? null ) ) {
					$removed_fields[ $section ] = array_merge( $removed_fields[ $section ], $settings[ $control_key ] );
				}
			}
		}

		return $removed_fields;
	}

	/**
	 * Disable WooCommerce checkout empty-cart redirect inside the Bricks builder.
	 *
	 * Allows opening `checkout/?bricks=run` even after placing an order (cart emptied).
	 *
	 * @since 2.4
	 *
	 * @param bool $redirect_empty_cart Whether WooCommerce should redirect checkout to cart.
	 * @return bool
	 */
	public function disable_empty_cart_checkout_redirect_in_builder( $redirect_empty_cart ) {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			return false;
		}

		return $redirect_empty_cart;
	}

	/**
	 * Cart or checkout build with Bricks: Remove 'wordpress' post class to avoid auto-containing Bricks content
	 *
	 * @since 1.5.5
	 */
	public function post_class( $classes, $class, $post_id ) {
		$remove_wordpress_class = false;

		// Cart page
		if ( is_cart() ) {
			$count = is_object( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;

			if (
				( $count && self::get_template_data_by_type( 'wc_cart', false ) ) || // Cart has items & Bricks template
				( ! $count && self::get_template_data_by_type( 'wc_cart_empty', false ) ) // Empty cart & Bricks template
			) {
				$remove_wordpress_class = true;
			}
		}

		// Checkout page
		if ( is_checkout() ) {
			// Order pay
			if ( get_query_var( 'order-pay' ) ) {
				if ( self::get_template_data_by_type( 'wc_form_pay', false ) ) {
					$remove_wordpress_class = true;
				}
			}

			// Order receipt (= thank you page)
			if ( get_query_var( 'order-received' ) ) {
				if ( self::get_template_data_by_type( 'wc_thankyou', false ) ) {
					$remove_wordpress_class = true;
				}
			}

			// Checkout page
			elseif ( self::get_template_data_by_type( 'wc_form_checkout', false ) ) {
				$remove_wordpress_class = true;
			}
		}

		// STEP: Remove 'wordpress' post class to avoid auto-containing Bricks content
		if ( $remove_wordpress_class ) {
			$index = array_search( 'wordpress', $classes );

			if ( isset( $classes[ $index ] ) ) {
				unset( $classes[ $index ] );
			}
		}

		return $classes;
	}

	/**
	 * If WooCommerce is not used, make sure the single and archive Woo templates are not used
	 *
	 * @since 1.5.1
	 *
	 * @param string $template
	 * @return string
	 */
	public function no_woo_template_include( $template ) {
		if ( empty( $template ) ) {
			return $template;
		}

		if ( strpos( $template, '/bricks/archive-product.php' ) ) {
			return get_query_template( 'archive', [ 'archive.php' ] );
		}

		if ( strpos( $template, '/bricks/single-product.php' ) ) {
			return get_query_template( 'single', [ 'single.php' ] );
		}

		return $template;
	}

	/**
	 * Sale badge HTML
	 *
	 * Show text or percentage.
	 */
	public function badge_sale( $html, $post, $product ) {
		$badge_type = Database::get_setting( 'woocommerceBadgeSale', false );

		// Type: ''
		if ( ! $badge_type ) {
			return;
		}

		// Type: text
		elseif ( $badge_type === 'text' ) {
			return '<span class="badge onsale">' . esc_html__( 'Sale', 'bricks' ) . '</span>';
		}

		// Type: percentage
		if ( $product->is_type( 'variable' ) ) {
			$percentages = [];

			// Get all variation prices
			$prices = $product->get_variation_prices();

			foreach ( $prices['price'] as $key => $price ) {
				if ( $prices['regular_price'][ $key ] !== $price ) {
					$percentages[] = round( 100 - ( floatval( $prices['sale_price'][ $key ] ) / floatval( $prices['regular_price'][ $key ] ) * 100 ) );
				}
			}

			// If unable to get any percentage, return the default HTML (@since 1.9.7)
			if ( empty( $percentages ) ) {
				return $html;
			}

			// Use highest discountvalue
			$percentage = max( $percentages ) . '%';
		} elseif ( $product->is_type( 'grouped' ) ) {
			$percentages = [];

			$children = $product->get_children();

			foreach ( $children as $child ) {
				$child_product = wc_get_product( $child );

				// Skip if child product not found or invalid
				if ( ! is_a( $child_product, 'WC_Product' ) ) {
					continue;
				}

				$regular_price = (float) $child_product->get_regular_price();
				$sale_price    = (float) $child_product->get_sale_price();

				// Skip if regular price is zero to avoid division by zero
				if ( $regular_price == 0 ) {
					continue;
				}

				if ( $sale_price != 0 || ! empty( $sale_price ) ) {
					$percentages[] = round( 100 - ( $sale_price / $regular_price * 100 ) );
				}
			}

			// If unable to get any percentage, return the default HTML (@since 1.9.7)
			if ( empty( $percentages ) ) {
				return $html;
			}

			// Use highest value
			$percentage = max( $percentages ) . '%';
		} else {
			$regular_price = (float) $product->get_regular_price();
			$sale_price    = (float) $product->get_sale_price();

			// Return: Don't devide by zero (@since 1.10)
			if ( $regular_price == 0 ) {
				return $html;
			}

			if ( $sale_price != 0 || ! empty( $sale_price ) ) {
				$percentage = round( 100 - ( $sale_price / $regular_price * 100 ) ) . '%';
			} else {
				return $html;
			}
		}

		return '<span class="badge onsale">-' . $percentage . '</span>';
	}

	public static function badge_new() {
		global $product;

		/**
		 * Avoid error if using on non product loop
		 *
		 * Replicate {do_action:woocommerce_before_shop_loop_item_title} on basic text element in non product loop.
		 *
		 * @since 1.9.4
		 */
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$newness_in_days = Database::get_setting( 'woocommerceBadgeNew', false );

		if ( ! $newness_in_days ) {
			return;
		}

		$newness_timestamp = time() - ( 60 * 60 * 24 * $newness_in_days );
		$created           = strtotime( $product->get_date_created() );
		$is_new            = $newness_timestamp < $created; // Created less than {$newness_in_days} days ago

		if ( $is_new ) {
			$html = '<span class="badge new">' . esc_html__( 'New', 'bricks' ) . '</span>';
			// Echo or return based on the current filter, used in provider-woo and woo products element (@since 1.11.1)
			if ( current_filter() === 'woocommerce_before_shop_loop_item_title' ) {
				echo $html;
			} else {
				return $html;
			}
		}
	}

	/**
	 * Product review submit button: Add 'button' class to apply Woo button styles
	 */
	public function product_review_comment_form_args( $comment_form ) {
		$comment_form['class_submit'] = 'button';

		return $comment_form;
	}

	/**
	 * WooCommerce support sets WC_Template_Loader::$theme_support = true
	 */
	public function add_theme_support() {
		add_theme_support(
			'woocommerce',
			[
				'product_grid' => [
					'default_columns' => 4,
					'default_rows'    => 3,
					'min_columns'     => 1,
					'max_columns'     => 6,
					'min_rows'        => 1,
				],
			]
		);

		add_theme_support( 'wc-product-gallery-slider' );

		// Disable/enable product gallery zoom
		if ( Database::get_setting( 'woocommerceDisableProductGalleryZoom', false ) ) {
			remove_theme_support( 'wc-product-gallery-zoom' );
		} else {
			add_theme_support( 'wc-product-gallery-zoom' );
		}

		// Disable/enable product gallery lightbox (always disabled in builder)
		$disable_product_gallery_lightbox = Database::get_setting( 'woocommerceDisableProductGalleryLightbox', false );

		if ( $disable_product_gallery_lightbox || bricks_is_builder() ) {
			remove_theme_support( 'wc-product-gallery-lightbox' );
		} else {
			add_theme_support( 'wc-product-gallery-lightbox' );
		}
	}

	/**
	 * Get products terms (categories, tags) for in-builder product query controls
	 */
	public function set_products_terms() {
		if ( bricks_is_builder() ) {
			self::$product_categories = self::get_products_terms( 'product_cat' );
			self::$product_tags       = self::get_products_terms( 'product_tag' );
		}
	}

	/**
	 * Get terms for a given product taxonomy
	 */
	public static function get_products_terms( $taxonomy = null ) {
		if ( empty( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);

		$tags = [];

		foreach ( $terms as $term ) {
			$tags[ $term->term_id ] = $term->name;
		}

		return $tags;
	}

	/**
	 * Check if WooCommerce plugin is active
	 *
	 * @return boolean
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'woocommerce' ) && ! Database::get_setting( 'woocommerceDisableBuilder', false );
	}

	/**
	 * Whether WooCommerce exposes its feature-gated withdrawal endpoint.
	 *
	 * @since 2.4
	 *
	 * @return boolean
	 */
	public static function is_order_withdrawal_enabled() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$query       = $woocommerce->query ?? null;

		if ( ! is_callable( [ $query, 'get_query_vars' ] ) ) {
			return false;
		}

		// Woo registers this canonical key only while the feature is enabled.
		$query_vars = $query->get_query_vars();

		return isset( $query_vars['order-withdrawal'] );
	}

	/**
	 * Whether the current account request is for public order withdrawal.
	 *
	 * @since 2.4
	 *
	 * @return boolean
	 */
	public static function is_order_withdrawal_request() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! self::is_order_withdrawal_enabled() ) {
			return false;
		}

		$query = WC()->query;

		return is_callable( [ $query, 'get_current_endpoint' ] ) && $query->get_current_endpoint() === 'order-withdrawal';
	}

	/**
	 * Check if WooCommerce advanced modular elements beta is enabled.
	 *
	 * All Cart v2, Checkout v2, and Account page v2 entry points should use
	 * this helper instead of reading the setting directly. When the beta becomes
	 * the default, this helper/default is the only behavior switch we need to
	 * change.
	 *
	 * @since 2.4
	 *
	 * @return boolean
	 */
	public static function use_advanced_modular_elements() {
		return self::is_woocommerce_active() && Database::get_setting( 'woocommerceUseAdvancedModularElements', false );
	}

	/**
	 * Get advanced modular parent element names.
	 *
	 * Parent elements share the same beta gate as their state/support children.
	 * When the beta is off they are not registered in any context.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_parent_elements() {
		return [
			'woocommerce-cart-v2',
			'woocommerce-checkout-v2',
			'woocommerce-account-page-v2',
		];
	}

	/**
	 * Get advanced modular internal state element names.
	 *
	 * These state roots are mandatory/protected children of the v2 parent
	 * elements. Keep the list centralized so builder state definitions, element
	 * manager protection, and frontend hard-off behavior cannot drift apart.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_state_elements() {
		return [
			'woocommerce-cart-v2-state-cart',
			'woocommerce-cart-v2-state-empty',
			'woocommerce-checkout-v2-state-checkout',
			'woocommerce-checkout-v2-state-login',
			'woocommerce-checkout-v2-state-pay',
			'woocommerce-checkout-v2-state-thankyou',
			'woocommerce-checkout-v2-state-receipt',
			'woocommerce-account-v2-state-dashboard',
			'woocommerce-account-v2-state-orders',
			'woocommerce-account-v2-state-view-order',
			'woocommerce-account-v2-state-downloads',
			'woocommerce-account-v2-state-addresses',
			'woocommerce-account-v2-state-edit-address',
			'woocommerce-account-v2-state-edit-account',
			'woocommerce-account-v2-state-payment-methods',
			'woocommerce-account-v2-state-add-payment-method',
			'woocommerce-account-v2-state-login',
			'woocommerce-account-v2-state-lost-password',
			'woocommerce-account-v2-state-lost-password-confirmation',
			'woocommerce-account-v2-state-reset-password',
			'woocommerce-account-v2-state-order-withdrawal',
		];
	}

	/**
	 * Get advanced modular supporting element names.
	 *
	 * Supporting elements are useful only inside the modular Woo flows, so they
	 * share the same registration gate as the v2 parent/state elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_support_elements() {
		return [
			'form-checkbox',
			'woocommerce-dynamic-fragment',
			'woocommerce-cart-form',
			'woocommerce-cart-item-data',
			'woocommerce-order-item-data',
			'woocommerce-cart-quantity',
			'woocommerce-checkout-steps-nav',
			'woocommerce-checkout-step-nav-item',
			'woocommerce-checkout-step',
			'woocommerce-shipping-options',
			'woocommerce-payment-options',
			'woocommerce-form-field',
			'woocommerce-form-submit',
			'woocommerce-checkout-account-fields',
			'woocommerce-checkout-billing-address',
			'woocommerce-checkout-shipping-address',
			'woocommerce-checkout-order-summary',
			'woocommerce-checkout-place-order',
			'woocommerce-account-login-form',
			'woocommerce-account-register-form',
			'woocommerce-account-lost-password-form',
			'woocommerce-account-order-withdrawal-form',
			'woocommerce-account-reset-password-form',
			'woocommerce-account-orders-pagination',
			'woocommerce-account-edit-address-form',
			'woocommerce-account-edit-account-form',
		];
	}

	/**
	 * Content regions whose generated prose receives contextual spacing.
	 *
	 * WooCommerce classes on page wrappers also enable its assets, so they cannot
	 * identify content regions. Keep this list limited to rendered prose wrappers.
	 *
	 * @since 2.4.3
	 *
	 * @return string
	 */
	public static function get_contextual_spacing_content_roots() {
		return ':is(.brxe-product-content, .brxe-product-short-description, .brxe-woocommerce-products-archive-description, .woocommerce-Tabs-panel--description, .woocommerce-product-details__short-description, :where(body.woocommerce) .term-description)';
	}

	/**
	 * Keep automatic contextual spacing out of managed control internals.
	 *
	 * The exclusion has zero specificity, leaving configured content spacing
	 * stronger than fallback spacing. User-defined targets remain independent.
	 *
	 * @since 2.4.3
	 *
	 * @return string
	 */
	public static function get_contextual_spacing_managed_exclusion() {
		$elements = array_merge(
			self::get_advanced_modular_support_elements(),
			[
				'woocommerce-breadcrumbs',
				'filter-checkbox',
				'filter-radio',
			]
		);

		$selectors = array_map(
			static function ( $name ) {
				return '.brxe-' . $name . ' *';
			},
			$elements
		);

		return ':not(:where(' . implode( ', ', $selectors ) . '))';
	}

	/**
	 * Get advanced modular query loop object types.
	 *
	 * The legacy wooCart query stays available because it predates the v2 beta.
	 * These object types power the v2 checkout/order/account presets, so they
	 * should not appear or run when the beta is off.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_query_types() {
		return [
			'wooCartCoupons',
			'wooCartFees',
			'wooCartTaxes',
			'wooOrderItems',
			'wooOrderTotals',
			'wooOrderDownloads',
			'wooOrderCustomerNotes',
			'wooAccountOrders',
			'wooAccountOrderActions',
			'wooAccountDownloads',
			'wooAccountAddresses',
		];
	}

	/**
	 * Get advanced modular dynamic data tag names without braces.
	 *
	 * Product and legacy cart tags remain available. These tags are tied to v2
	 * state previews, v2 order/account loops, or generated v2 presets, so hide
	 * and no-op them while the beta is disabled.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_dynamic_tags() {
		return [
			'woo_order_withdrawal_screen',
			'woo_order_withdrawal_value',
			'woo_cart_product_title',
			'woo_cart_items_count',
			'woo_cart_item_price',
			'woo_cart_item_save',
			'woo_cart_order_subtotal',
			'woo_cart_order_total',
			'woo_cart_applied_coupon',
			'woo_cart_applied_coupon_amount',
			'woo_cart_applied_coupon_total_amount',
			'woo_cart_applied_fee',
			'woo_cart_applied_fee_amount',
			'woo_cart_applied_fee_total_amount',
			'woo_cart_shipping_method',
			'woo_cart_shipping_total_amount',
			'woo_free_shipping_min_amount',
			'woo_free_shipping_remaining',
			'woo_free_shipping_progress',
			'woo_cart_applied_tax_label',
			'woo_cart_applied_tax_amount',
			'woo_checkout_current_step_number',
			'woo_account_addresses_description',
			'woo_account_address_type',
			'woo_account_address_title',
			'woo_account_address',
			'woo_account_address_has_address',
			'woo_account_address_edit_url',
			'woo_account_address_action_label',
			'woo_account_edit_address_title',
			'woo_account_edit_address_type',
			'woo_account_edit_address_type_label',
			'woo_order_id',
			'woo_order_number',
			'woo_order_date',
			'woo_order_status',
			'woo_order_failed_text',
			'woo_order_total',
			'woo_order_payment_title',
			'woo_order_email',
			'woo_order_checkout_payment_url',
			'woo_order_again_url',
			'woo_order_user_id',
			'woo_order_billing_address',
			'woo_order_billing_phone',
			'woo_order_shipping_address',
			'woo_order_shipping_phone',
			'woo_order_item_name',
			'woo_order_item_title',
			'woo_order_item_quantity',
			'woo_order_item_price',
			'woo_order_item_line_subtotal',
			'woo_order_item_meta',
			'woo_order_total_label',
			'woo_order_total_value',
			'woo_order_view_url',
			'woo_order_view_aria_label',
			'woo_order_item_count',
			'woo_order_total_with_item_count',
			'woo_order_action_url',
			'woo_order_action_name',
			'woo_order_action_aria_label',
			'woo_order_download_product',
			'woo_order_download_name',
			'woo_order_download_url',
			'woo_order_downloads_remaining',
			'woo_order_download_access_expires',
			'woo_order_customer_note_date',
			'woo_order_customer_note_comment',
		];
	}

	/**
	 * Get all advanced modular element names.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_advanced_modular_elements() {
		return array_values(
			array_unique(
				array_merge(
					self::get_advanced_modular_parent_elements(),
					self::get_advanced_modular_state_elements(),
					self::get_advanced_modular_support_elements()
				)
			)
		);
	}

	/**
	 * Check if element is an advanced modular parent element.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return boolean
	 */
	public static function is_advanced_modular_parent_element( $element_name ) {
		return in_array( $element_name, self::get_advanced_modular_parent_elements(), true );
	}

	/**
	 * Check if element is an advanced modular state element.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return boolean
	 */
	public static function is_advanced_modular_state_element( $element_name ) {
		return in_array( $element_name, self::get_advanced_modular_state_elements(), true );
	}

	/**
	 * Check if element is an advanced modular support element.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return boolean
	 */
	public static function is_advanced_modular_support_element( $element_name ) {
		return in_array( $element_name, self::get_advanced_modular_support_elements(), true );
	}

	/**
	 * Register a gated advanced modular support element on demand.
	 *
	 * Some support elements live in the global element folder while the v2 Woo
	 * parent/state elements live in the Woo element folder.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return boolean
	 */
	public static function register_advanced_modular_support_element( $element_name ) {
		if (
			! self::use_advanced_modular_elements() ||
			! self::is_advanced_modular_support_element( $element_name ) ||
			isset( Elements::$elements[ $element_name ] )
		) {
			return false;
		}

		$class_suffix = str_replace( '-', '_', $element_name );
		$class_suffix = ucwords( $class_suffix, '_' );
		$element_file = BRICKS_PATH . "includes/elements/$element_name.php";
		$class_name   = "Bricks\\Element_$class_suffix";

		if ( ! is_readable( $element_file ) ) {
			$element_file = BRICKS_PATH . "includes/woocommerce/elements/$element_name.php";
			$class_name   = "Bricks\\$class_suffix";
		}

		if ( ! is_readable( $element_file ) ) {
			return false;
		}

		Elements::register_element( $element_file, $element_name, $class_name );

		if ( ! in_array( $element_name, Elements::$native, true ) ) {
			Elements::$native[] = $element_name;
		}

		return true;
	}

	/**
	 * Check if element belongs to the advanced modular beta.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return boolean
	 */
	public static function is_advanced_modular_element( $element_name ) {
		return in_array( $element_name, self::get_advanced_modular_elements(), true );
	}

	/**
	 * Check if query loop object type belongs to the advanced modular beta.
	 *
	 * @since 2.4
	 *
	 * @param string $query_type Query object type.
	 * @return boolean
	 */
	public static function is_advanced_modular_query_type( $query_type ) {
		return in_array( $query_type, self::get_advanced_modular_query_types(), true );
	}

	/**
	 * Check if dynamic data tag belongs to the advanced modular beta.
	 *
	 * @since 2.4
	 *
	 * @param string $tag Dynamic tag name with or without braces/filters.
	 * @return boolean
	 */
	public static function is_advanced_modular_dynamic_tag( $tag ) {
		$tag_name = trim( (string) $tag, '{}' );
		$tag_name = explode( ':', $tag_name )[0];

		return in_array( $tag_name, self::get_advanced_modular_dynamic_tags(), true );
	}

	/**
	 * Determine if currently landed on WC api endpoint
	 *
	 * @see woocommerce/includes/class-woocommerce.php api_request_url()
	 * @since 2.0
	 */
	public static function is_wc_api_endpoint() {
		// For better performance, cache it as the request wouldn't change for every single load
		static $is_wc_api = null;

		if ( null !== $is_wc_api ) {
			return $is_wc_api;
		}

		if ( ! empty( $_GET['wc-api'] ) ) {
			$is_wc_api = true;
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( strpos( $request_uri, '/wc-api/' ) !== false ) {
			$is_wc_api = true;
			return true;
		}

		$is_wc_api = false;
		return false;
	}

	/**
	 * Init WooCommerce theme styles
	 */
	public function init_theme_styles() {
		$file = BRICKS_PATH . 'includes/woocommerce/theme-styles.php';

		if ( is_readable( $file ) ) {
			require_once $file;

			new Woocommerce_Theme_Styles();
		}
	}

	/**
	 * Init WooCommerce elements
	 */
	public function init_elements() {
		// Load WooCommerce helpers
		$helpers_file = BRICKS_PATH . 'includes/woocommerce/helpers.php';

		if ( is_readable( $helpers_file ) ) {
			require_once $helpers_file;
		}

		// Load woo element base class (Woo Phase 3)
		$woo_element_base = BRICKS_PATH . 'includes/woocommerce/elements/base.php';
		if ( is_readable( $woo_element_base ) ) {
			require_once $woo_element_base;
		}

		$woo_elements = [
			'product-title',
			'product-gallery',
			'product-short-description',
			'product-price',
			'product-stock',
			'product-meta',
			'product-rating',
			'product-content',
			'product-add-to-cart',
			'product-related',
			'product-reviews',
			'product-additional-information',
			'product-tabs',
			'product-upsells',

			'woocommerce-breadcrumbs',
			'woocommerce-mini-cart',

			'woocommerce-cart-collaterals',
			'woocommerce-cart-coupon',
			'woocommerce-cart-items',

			'woocommerce-checkout-coupon',
			'woocommerce-checkout-login',
			'woocommerce-checkout-order-review',
			'woocommerce-checkout-thankyou',
			'woocommerce-checkout-order-table',
			'woocommerce-checkout-order-payment',
			'woocommerce-checkout-customer-details',

			'woocommerce-products',
			'woocommerce-products-pagination',
			'woocommerce-products-orderby',
			'woocommerce-products-total-results',
			'woocommerce-products-filter',
			'woocommerce-products-archive-description',

			'woocommerce-notice',
			// 'woocommerce-template-hook', // NOTE: Not in use as action hooks can be added via the 'do_action' DD tag (@since 1.7)

			// Woo Phase 3
			'woocommerce-account-page',
			'woocommerce-account-form-login',
			'woocommerce-account-form-register',
			'woocommerce-account-form-lost-password',
			'woocommerce-account-form-reset-password',

			'woocommerce-account-orders',
			'woocommerce-account-downloads',
			'woocommerce-account-addresses',
			'woocommerce-account-view-order',

			'woocommerce-account-form-edit-address',
			'woocommerce-account-form-edit-account',

			'woocommerce-account-payment-methods', // (@since 2.2)
			'woocommerce-account-add-payment-method', // (@since 2.2)

			// Advanced modular elements (@since 2.4)
			'woocommerce-dynamic-fragment',
			'woocommerce-cart-form',
			'woocommerce-cart-item-data',
			'woocommerce-order-item-data',
			'woocommerce-cart-quantity',
			'woocommerce-cart-v2',
			'woocommerce-cart-v2-state-cart',
			'woocommerce-cart-v2-state-empty',
			'woocommerce-checkout-steps-nav',
			'woocommerce-checkout-step-nav-item',
			'woocommerce-checkout-step',
			'woocommerce-shipping-options',
			'woocommerce-payment-options',
			'woocommerce-form-field',
			'woocommerce-form-submit',
			'woocommerce-checkout-account-fields',
			'woocommerce-checkout-billing-address',
			'woocommerce-checkout-shipping-address',
			'woocommerce-checkout-v2',
			'woocommerce-checkout-v2-state-checkout',
			'woocommerce-checkout-v2-state-login',
			'woocommerce-checkout-v2-state-pay',
			'woocommerce-checkout-v2-state-thankyou',
			'woocommerce-checkout-v2-state-receipt',
			'woocommerce-checkout-order-summary',
			'woocommerce-checkout-place-order',
			'woocommerce-account-page-v2',
			'woocommerce-account-v2-state-dashboard',
			'woocommerce-account-v2-state-orders',
			'woocommerce-account-v2-state-view-order',
			'woocommerce-account-v2-state-downloads',
			'woocommerce-account-v2-state-addresses',
			'woocommerce-account-v2-state-edit-address',
			'woocommerce-account-v2-state-edit-account',
			'woocommerce-account-v2-state-payment-methods',
			'woocommerce-account-v2-state-add-payment-method',
			'woocommerce-account-v2-state-login',
			'woocommerce-account-v2-state-lost-password',
			'woocommerce-account-v2-state-lost-password-confirmation',
			'woocommerce-account-v2-state-reset-password',
			'woocommerce-account-v2-state-order-withdrawal',
			'woocommerce-account-login-form',
			'woocommerce-account-register-form',
			'woocommerce-account-lost-password-form',
			'woocommerce-account-order-withdrawal-form',
			'woocommerce-account-reset-password-form',
			'woocommerce-account-orders-pagination',
			'woocommerce-account-edit-address-form',
			'woocommerce-account-edit-account-form',
		];

		self::register_advanced_modular_support_element( 'form-checkbox' );

		foreach ( $woo_elements as $element_name ) {
			if (
				! self::use_advanced_modular_elements() &&
				self::is_advanced_modular_element( $element_name )
			) {
				// A disabled beta should behave like an unavailable element family,
				// regardless of whether the request is builder, admin, AJAX, or frontend.
				continue;
			}

			if ( in_array( $element_name, [ 'woocommerce-account-v2-state-order-withdrawal', 'woocommerce-account-order-withdrawal-form' ], true ) && ! self::is_order_withdrawal_enabled() ) {
				continue;
			}

			// Only register woocommerce-notice if user activated it
			if ( $element_name === 'woocommerce-notice' && ! self::use_bricks_woo_notice_element() ) {
				continue;
			}

			// Only register checkout-coupon if user activated it
			if ( $element_name === 'woocommerce-checkout-coupon' && ! self::use_bricks_woo_checkout_coupon_element() ) {
				continue;
			}

			// Only register checkout-login if user activated it
			if ( $element_name === 'woocommerce-checkout-login' && ! self::use_bricks_woo_checkout_login_element() ) {
				continue;
			}

			$woo_element_file = BRICKS_PATH . "includes/woocommerce/elements/$element_name.php";

			// Get the class name from the element name
			$class_name = str_replace( '-', '_', $element_name );
			$class_name = ucwords( $class_name, '_' );
			$class_name = "Bricks\\$class_name";

			if ( is_readable( $woo_element_file ) ) {
				Elements::register_element( $woo_element_file, $element_name, $class_name );

				// Add element name to self::$native element names array (@since 2.0)
				Elements::$native[] = $element_name;
			}
		}
	}

	public function quantity_input_field_add_minus_button() {
		$html  = '<span class="action minus">';
		$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="12" x2="18" y2="12"></line></svg>';
		$html .= '</span>';

		echo $html;
	}

	public function quantity_input_field_add_plus_button() {
		$html  = '<span class="action plus">';
		$html .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="6" x2="12" y2="18"></line><line x1="6" y1="12" x2="18" y2="12"></line></svg>';
		$html .= '</span>';

		echo $html;
	}

	public function breadcrumb_separator( $defaults ) {
		$defaults['delimiter'] = '<span>/</span>';

		return $defaults;
	}

	/**
	 * Add search breadbrumb in the product archive if using Bricks search filter
	 *
	 * @param array         $crumbs
	 * @param WC_Breadcrumb $crumbs_obj
	 * @return array
	 */
	public function add_breadcrumbs_from_filters( $crumbs, $crumbs_obj ) {

		if ( ! empty( $_GET['b_search'] ) && Woocommerce_Helpers::is_archive_product() ) {
			$crumbs[] = [
				// translators: %s: search term
				sprintf( __( 'Search results for &ldquo;%s&rdquo;', 'woocommerce' ), wp_strip_all_tags( $_GET['b_search'] ) ),
				remove_query_arg( 'paged' )
			];
		}

		return $crumbs;
	}

	/**
	 * Bypass Builder post type check because page set to WooCommerce Shop fails
	 *
	 * @return boolean
	 */
	public function bypass_builder_post_type_check( $supported_post_types, $current_post_type ) {
		if ( in_array( 'page', $supported_post_types ) && ( is_post_type_archive( 'product' ) || is_page( wc_get_page_id( 'shop' ) ) ) ) {
			$supported_post_types[] = 'product';
		}

		return $supported_post_types;
	}

	/**
	 * Builder: Set single product template & populate content (if needed)
	 */
	public function maybe_set_template_preview_content() {
		$post_id = get_the_ID();

		$template_type       = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, true );
		$template_preview_id = Helpers::get_template_preview_post_id( $post_id );

		if (
			strpos( $template_type, 'wc_' ) !== false ||
			wc_get_page_id( 'shop' ) == $template_preview_id ) {
			// Necessary to add 'woocommerce' to body_class for styling
			add_filter(
				'is_woocommerce',
				function() {
					return true;
				}
			);
		}

		// Remove 'woocommerce body class in builder panel
		if ( bricks_is_builder_main() ) {
			add_filter(
				'is_woocommerce',
				function() {
					return false;
				}
			);
		}

		// Form checkout template
		if (
			$template_type === 'wc_form_checkout' ||
			$template_type === 'wc_form_pay' ||
			$template_type === 'wc_cart'
		) {
			add_filter( 'body_class', [ $this, 'add_body_class' ], 9, 1 );
		}

		// Return: Not in builder nor template
		if ( ! bricks_is_builder() || ! Helpers::is_bricks_template( $post_id ) ) {
			return;
		}

		// Get the last product and save it as preview ID
		if ( $template_type === 'wc_product' ) {
			// Check the saved selection here so changing preview modes does not trigger automatic product selection.
			$template_preview_post_id = Helpers::get_template_setting( 'templatePreviewPostId', $post_id );

			if ( $template_preview_post_id ) {
				return;
			}

			$products = wc_get_products(
				[
					'limit'   => 1,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
				]
			);

			if ( isset( $products[0] ) ) {
				Helpers::set_template_setting( $post_id, 'templatePreviewPostId', $products[0] );
				Helpers::set_template_setting( $post_id, 'templatePreviewType', 'single' );
				Helpers::set_template_setting( $post_id, 'templatePreviewAutoContent', 1 ); // This setting will be used to trigger a notification
			}
		}

		// TODO: Replace this logic by a generic template preview CPT Archive > CPT = products
		// elseif ( $template_type === 'wc_archive' ) {
		// $template_preview_type = Helpers::get_template_setting( 'templatePreviewType', $post_id );

		// if ( $template_preview_type ) {
		// return;
		// }

		// Helpers::set_template_setting( $post_id, 'templatePreviewType', 'archive-product' );
		// }
	}

	/**
	 * Cart/Checkout/Account page: Return no title if rendered via Bricks template
	 *
	 * @since 1.8
	 */
	public function default_page_title( $post_title, $post_id ) {
		// Only amend the title for these pages
		if ( is_cart() || is_checkout() || is_account_page() ) {
			// Improvement: Check active templates for current WC endpoint and decide the default page title. (#86c3gdaz2) (@since 2.0)
			$wc_templates = self::get_active_templates_for_current_endpoint();

			// As long as there is a active Bricks template, return no title
			if ( ! empty( $wc_templates ) ) {
				return '';
			}
		}

		return $post_title;
	}

	/**
	 * Set aria-current="page" for WooCommerce
	 *
	 * @since 1.8
	 */
	public function maybe_set_aria_current_page( $set, $url ) {
		// WooCommerce shop page
		if ( is_shop() ) {
			$set = $url === get_permalink( wc_get_page_id( 'shop' ) );
		}

		// WooCommerce my account page (@since Woo Phase 3)
		if ( is_account_page() ) {

			/**
			 * Based on the $url of the link, we need to know which endpoint is currently active
			 * Then use the slugs of the endpoints to check if the $url contains the required paths
			 * Bear in mind that the $url might be a relative url, a full url, a url with query string, url with hash, etc.
			 */
			$wc_endpoints          = WC()->query->get_query_vars(); // array keys are the endpoints, values are the slug
			$current_endpoint      = WC()->query->get_current_endpoint();
			$current_endpoint_slug = isset( $wc_endpoints[ $current_endpoint ] ) ? $wc_endpoints[ $current_endpoint ] : '';

			$my_account_page_id   = wc_get_page_id( 'myaccount' );
			$my_account_page_slug = get_post_field( 'post_name', $my_account_page_id );

			// STEP: Get required paths in array format
			// My account page slug is always required
			$required_paths = [ $my_account_page_slug ];

			if ( ! empty( $current_endpoint_slug ) ) {
				// Add current endpoint slug if not empty
				$required_paths[] = $current_endpoint_slug;
			}

			// STEP: Get the path in array format
			$url_path = parse_url( $url, PHP_URL_PATH ); // Ex: /my-account/orders/, /subfolder/my-account/view-order/123/, /subfolder/xxx/my-account

			if ( $url_path ) {
				// Convert to array
				$url_path = explode( '/', $url_path ); // Ex: [ '', 'my-account', 'view-order', '123', '' ]

				// Remove empty items
				$url_path = array_filter( $url_path ); // Ex: [ 'my-account', 'view-order', '123' ]

				// Default, Set true if the URL contains the required paths
				$set = count( array_intersect( $required_paths, $url_path ) ) === count( $required_paths );

				if ( $current_endpoint === 'view-order' ) {
					// In view-order endpoint, should set true if the URL contains the orders endpoint slug as well (child endpoint)
					$set = $set || in_array( $wc_endpoints['orders'], $url_path );
				}

				if ( $current_endpoint === '' ) {
					// In dashboard endpoint, $my_account_page_slug must be the last item in $url_path (yourwebsite/subfolder/xxx/my-account/)
					$set = end( $url_path ) === $my_account_page_slug;
				}
			}
		}

		return $set;
	}

	public static function get_wc_endpoint_from_url( $url ) {
		// Get the base URL of the site.
		$site_url = get_site_url();

		// Check if the provided URL belongs to the current site.
		if ( strpos( $url, $site_url ) === false ) {
			return false;
		}

		// Get the path from the provided URL.
		$parsed_url = wp_parse_url( $url );
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

		// Remove the trailing slash from the path.
		$path = untrailingslashit( $path );

		// Get the registered WooCommerce endpoints.
		$endpoints = WC()->query->get_query_vars();

		// Iterate through the endpoints and find the match.
		foreach ( $endpoints as $endpoint => $value ) {
			$endpoint_slug = untrailingslashit( wc_get_endpoint_url( $endpoint ) );

			// Check if the path matches the endpoint.
			if ( $path === $endpoint_slug ) {
				return $endpoint;
			}
		}

		return false;
	}

	/**
	 * Builder: Add body classes to Woo templates
	 *
	 * @param array $classes
	 */
	public function add_body_class( $classes ) {
		if ( get_post_type() !== BRICKS_DB_TEMPLATE_SLUG ) {
			return $classes;
		}

		if ( Templates::get_template_type() === 'wc_form_checkout' ) {
			$classes[] = 'woocommerce-checkout';
			$classes[] = 'woocommerce-page';
		} elseif ( Templates::get_template_type() === 'wc_form_pay' ) {
			$classes[] = 'woocommerce-checkout';
		} elseif ( Templates::get_template_type() === 'wc_cart' ) {
			$classes[] = 'woocommerce-cart';
			$classes[] = 'woocommerce-page';
		}

		return $classes;
	}

	/**
	 * Resolve current Woo core page context for builder panel conditions.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id
	 *
	 * @return string|false
	 */
	public static function get_builder_woo_page( $post_id ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		$page_map = [
			'cart'      => wc_get_page_id( 'cart' ),
			'checkout'  => wc_get_page_id( 'checkout' ),
			'myaccount' => wc_get_page_id( 'myaccount' ),
		];

		foreach ( $page_map as $woo_page => $woo_page_id ) {
			if ( is_numeric( $woo_page_id ) && (int) $woo_page_id > 0 && (int) $post_id === (int) $woo_page_id ) {
				return $woo_page;
			}
		}

		return false;
	}

	/**
	 * On the builder, move up WooCommerce specific elements
	 *
	 * @since 1.2.1
	 *
	 * @param string $category
	 * @param int    $post_id
	 * @param string $post_type
	 *
	 * @return string
	 */
	public function set_first_element_category( $category, $post_id, $post_type ) {
		$cart_page_id      = wc_get_page_id( 'cart' );
		$checkout_page_id  = wc_get_page_id( 'checkout' );
		$myaccount_page_id = wc_get_page_id( 'myaccount' );

		if ( BRICKS_DB_TEMPLATE_SLUG === $post_type ) {
			$template_type = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, true );

			if ( $template_type == 'wc_product' ) {
				return 'woocommerce_product';
			}

			if ( in_array( $template_type, [ 'wc_form_checkout', 'wc_form_pay' ], true ) ) {
				return 'woocommerce_checkout';
			}

			if ( $template_type === 'wc_cart' ) {
				return 'woocommerce_cart';
			}

			if ( strpos( $template_type, 'wc_account_' ) === 0 ) {
				return 'woocommerce_account';
			}

			if ( strpos( $template_type, 'wc_' ) !== false ) {
				return 'woocommerce';
			}
		} elseif ( is_post_type_archive( 'product' ) || $post_id == wc_get_page_id( 'shop' ) ) {
			return 'woocommerce';
		} elseif ( (int) $post_id === (int) $checkout_page_id ) {
			// Checkout page (@since 2.4)
			return 'woocommerce_checkout';
		} elseif ( (int) $post_id === (int) $cart_page_id ) {
			// Cart page (@since 2.4)
			return 'woocommerce_cart';
		} elseif ( (int) $post_id === (int) $myaccount_page_id ) {
			// My account page (@since 2.4)
			return 'woocommerce_account';
		} elseif (
			in_array(
				(int) $post_id,
				array_filter(
					[
						$cart_page_id,
						$checkout_page_id,
						$myaccount_page_id,
					],
					function( $page_id ) {
						return is_numeric( $page_id ) && (int) $page_id > 0;
					}
				),
				true
			)
		) {
			return 'woocommerce';
		} elseif ( 'product' == $post_type ) {
			return 'woocommerce_product';
		}

		return $category;
	}

	/**
	 * Page marked as shop - is_shop() - has a global $post_id set to the first product (like is_home)
	 *
	 * In builder or when setting the active templates we need to replace the active post id by the page id
	 *
	 * @param int $post_id
	 */
	public function maybe_set_post_id( $post_id ) {
		// If launching bricks builder on page defined as shop
		if ( is_shop() && ! Helpers::is_bricks_template( $post_id ) ) {
			$page_id = wc_get_page_id( 'shop' );

			$post_id = ! empty( $page_id ) ? $page_id : $post_id;
		}

		return $post_id;
	}

	/**
	 * Add WooCommerce element link selectors to allow Theme Styles for the links
	 *
	 * @since 1.5.7
	 */
	public function link_css_selectors( $selectors ) {
		$selectors[] = '.brxe-product-content a';
		$selectors[] = '.brxe-product-short-description a';
		$selectors[] = '.brxe-product-tabs .woocommerce-Tabs-panel a';

		return $selectors;
	}

	/**
	 * NOTE: Not in use as we renamed the 'PhotoSwipe' class to 'Photoswipe5' to avoid conflicts with WooCommerce Photoswipe 4
	 */
	public function unload_photoswipe5_lightbox_assets() {
		// Remove Bricks lightbox (as Photoswipe 5 conflicts with Photoswipe 4, the latter which is used by WooCommerce)
		if ( is_product() && current_theme_supports( 'wc-product-gallery-lightbox' ) ) {
			wp_deregister_script( 'bricks-photoswipe' );
			wp_deregister_script( 'bricks-photoswipe-lightbox' );
			wp_deregister_style( 'bricks-photoswipe' );
		}
	}

	/**
	 * Remove WooCommerce scripts on non-WooCommerce pages
	 *
	 * @since 1.2.1
	 */
	public function wp_enqueue_scripts() {
		if ( bricks_is_builder_iframe() ) {
			// Required for product gallery & tabs
			wp_enqueue_script( 'wc-single-product' );
		}

		if ( ! bricks_is_builder_main() ) {
			wp_enqueue_script( 'bricks-woocommerce', BRICKS_URL_ASSETS . 'js/integrations/woocommerce.min.js', [ 'bricks-scripts' ], filemtime( BRICKS_PATH_ASSETS . 'js/integrations/woocommerce.min.js' ), true );
			if ( ! Database::get_setting( 'disableBricksCascadeLayer' ) ) {
				wp_enqueue_style( 'bricks-woocommerce', BRICKS_URL_ASSETS . 'css/integrations/woocommerce-layer.min.css', [ 'bricks-frontend' ], filemtime( BRICKS_PATH_ASSETS . 'css/integrations/woocommerce-layer.min.css' ) );
			} else {
				wp_enqueue_style( 'bricks-woocommerce', BRICKS_URL_ASSETS . 'css/integrations/woocommerce.min.css', [ 'bricks-frontend' ], filemtime( BRICKS_PATH_ASSETS . 'css/integrations/woocommerce.min.css' ) );
			}
		}

		// Bricks WooCommerce settings for frontend
		wp_localize_script(
			'bricks-scripts',
			'bricksWooCommerce',
			[
				'ajaxAddToCartEnabled' => self::enabled_ajax_add_to_cart(),
				'ajaxAddingText'       => self::global_ajax_adding_text(),
				'ajaxAddedText'        => self::global_ajax_added_text(),
				'addedToCartNotices'   => '',
				'showNotice'           => self::global_ajax_show_notice(),
				'scrollToNotice'       => self::global_ajax_scroll_to_notice(),
				'resetTextAfter'       => self::global_ajax_reset_text_after(),
				'useQtyInLoop'         => self::use_quantity_in_loop(),
				'errorAction'          => self::global_ajax_error_action(),
				'errorScrollToNotice'  => self::global_ajax_error_scroll_to_notice(),
				'useVariationSwatches' => Database::get_setting( 'woocommerceUseVariationSwatches' ),
			]
		);
	}

	/**
	 * Enqueue WooCommerce scripts and styles for LTR pages
	 *
	 * It will be enqueued after the Bricks WooCommerce assets.
	 *
	 * @since 2.0
	 */
	public function wp_enqueue_scripts_rtl() {
		if ( ! Database::get_setting( 'disableBricksCascadeLayer' ) ) {
			wp_enqueue_style( 'bricks-woocommerce-rtl', BRICKS_URL_ASSETS . 'css/integrations/woocommerce-rtl-layer.min.css', [ 'bricks-frontend' ], filemtime( BRICKS_PATH_ASSETS . 'css/integrations/woocommerce-rtl-layer.min.css' ) );
		} else {
			wp_enqueue_style( 'bricks-woocommerce-rtl', BRICKS_URL_ASSETS . 'css/integrations/woocommerce-rtl.min.css', [ 'bricks-frontend' ], filemtime( BRICKS_PATH_ASSETS . 'css/integrations/woocommerce-rtl.min.css' ) );
		}
	}


	/**
	 * Before Bricks searchs for the right template, set the content_type if needed
	 *
	 * @param string $content_type
	 * @param int    $post_id
	 */
	public static function set_content_type( $content_type, $post_id ) {
		// If using /?s=abc&post_type=product, will change the $active_template to unexpected template (@since 1.9.1)
		if ( is_search() ) {
			return $content_type;
		}

		// These will only kick in if user has defaultTemplatesDisabled = false
		if ( is_product() ) {
			$content_type = 'wc_product';
		} elseif ( is_shop() ) {
			$content_type = 'content';
		} elseif ( Woocommerce_Helpers::is_archive_product() ) {
			$content_type = 'wc_archive';
		}

		return $content_type;
	}

	/**
	 * Set the archive post type represented by the WooCommerce shop page.
	 *
	 * The main frontend query identifies the shop as a product archive. Builder, REST, and admin
	 * requests only have the shop page ID, so template conditions need this explicit context.
	 *
	 * @since 2.4
	 *
	 * @param string $post_type Archive post type for the current context.
	 * @param int    $post_id   Current post ID.
	 * @return string
	 */
	public static function set_archive_post_type( $post_type, $post_id ) {
		if ( ! $post_id ) {
			return $post_type;
		}

		$shop_page_id = (int) wc_get_page_id( 'shop' );

		if ( $shop_page_id > 0 && (int) $post_id === $shop_page_id ) {
			return 'product';
		}

		return $post_type;
	}

	/**
	 * All WooCommerce templates in Bricks
	 *
	 * @since 1.11.1
	 * @return array
	 */
	public static function get_woo_templates() {
		$templates = [
			// Product archive & single product templates
			'wc_archive'                                 => esc_html__( 'Product archive', 'bricks' ),
			'wc_product'                                 => esc_html__( 'Single product', 'bricks' ),

			// Cart & checkout templates
			'wc_cart'                                    => esc_html__( 'Cart', 'bricks' ),
			'wc_cart_empty'                              => esc_html__( 'Empty cart', 'bricks' ),
			'wc_form_checkout'                           => esc_html__( 'Checkout', 'bricks' ),
			'wc_form_pay'                                => esc_html__( 'Pay', 'bricks' ),
			'wc_thankyou'                                => esc_html__( 'Thank you', 'bricks' ),
			'wc_order_receipt'                           => esc_html__( 'Order receipt', 'bricks' ),

			// Woo Phase 3
			'wc_account_form_login'                      => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Login', 'bricks' ),
			'wc_account_form_lost_password'              => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Lost password', 'bricks' ),
			'wc_account_form_lost_password_confirmation' => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Lost password', 'bricks' ) . ' (' . esc_html__( 'Confirmation', 'bricks' ) . ')',
			'wc_account_reset_password'                  => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Reset password', 'bricks' ),
			'wc_account_dashboard'                       => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Dashboard', 'bricks' ),
			'wc_account_orders'                          => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Orders', 'bricks' ),
			'wc_account_view_order'                      => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'View order', 'bricks' ),
			'wc_account_downloads'                       => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Downloads', 'bricks' ),
			'wc_account_addresses'                       => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Addresses', 'bricks' ),
			'wc_account_form_edit_address'               => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Edit address', 'bricks' ),
			'wc_account_form_edit_account'               => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Edit account', 'bricks' ),
			'wc_account_payment_methods'                 => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Payment methods', 'bricks' ), // (@since 2.2)
			'wc_account_add_payment_method'              => esc_html__( 'Account', 'bricks' ) . ' - ' . esc_html__( 'Add payment method', 'bricks' ), // (@since 2.2)
		];

		return $templates;
	}

	/**
	 * Add template types to control options
	 *
	 * @param array $control_options
	 * @return array
	 *
	 * @since 1.4
	 */
	public function add_template_types( $control_options ) {
		$template_types = $control_options['templateTypes'];

		$woo_templates = self::get_woo_templates();

		// Add Prefix 'WooCommerce - ' to WooCommerce templates
		$woo_templates = array_map(
			function( $value ) {
				return 'WooCommerce - ' . $value;
			},
			$woo_templates
		);

		// Merge WooCommerce templates with existing template types
		$template_types = array_merge( $template_types, $woo_templates );

		$control_options['templateTypes'] = $template_types;

		return $control_options;
	}

	/**
	 * Remove "Template Conditions" & "Populate Content" panel controls for WooCommerce Cart & Checkout template parts
	 *
	 * @param array $settings
	 * @return array
	 *
	 * @since 1.4
	 */
	public function remove_template_conditions( $settings ) {
		// Get all WooCommerce templates
		$excluded_templates = self::get_woo_templates();

		// 'wc_archive' & 'wc_product' need conditions
		unset( $excluded_templates['wc_archive'] );
		unset( $excluded_templates['wc_product'] );

		// Get the array keys
		$excluded_templates = array_keys( $excluded_templates );

		if ( isset( $settings['controlGroups']['template-preview'] ) ) {
			$settings['controlGroups']['template-preview']['required'] = [ 'templateType', '!=', $excluded_templates, 'templateType' ];
		}

		if ( isset( $settings['controls']['templateConditionsInfo'] ) ) {
			$settings['controls']['templateConditionsInfo']['required'] = [ 'templateType', '!=', $excluded_templates, 'templateType' ];
		}

		if ( isset( $settings['controls']['templateConditions'] ) ) {
			$settings['controls']['templateConditions']['required'] = [ 'templateType', '!=', $excluded_templates, 'templateType' ];
		}

		$settings['controls'][] = [
			'group'    => 'template-conditions',
			'type'     => 'info',
			'content'  => esc_html__( 'This template type is automatically rendered on the correct page.', 'bricks' ),
			'required' => [ 'templateType', '=', $excluded_templates, 'templateType' ],
		];

		return $settings;
	}

	/**
	 * Get template data by template type
	 *
	 * For woocommerce templates inside Bricks theme.
	 *
	 * Return template data rendered via Bricks template shortcode.
	 *
	 * @since 1.8: Return template ID if render is false (to not trigger any hooks when we are not rendering the template)
	 * Example: do_shortcode will be execute in post_class filter, which will trigger the do_shortcode action,
	 * and causing wc_print_notices to be executed in post_class filter before the actual template is rendered.
	 * Resulted actual template rendering empty notices. (wc_print_notices() will erase the notices after it is executed)
	 *
	 * @see /includes/woocommerce/cart/cart.php (wc_cart), etc.
	 *
	 * @since 1.4
	 */
	public static function get_template_data_by_type( $type = '', $render = true ) {
		// Do not check for Database::get_setting( 'defaultTemplatesDisabled' )
		$template_ids = Templates::get_templates_by_type( $type );
		$template_id  = $template_ids[0] ?? false;

		// No template found
		if ( ! $template_id ) {
			return false;
		}

		// Return template id if render is false
		if ( ! $render ) {
			return $template_id;
		}

		$output = '';

		/**
		 * Add page settings custom CSS to return with rendered template
		 *
		 * For Woo cart, checkout, account pages, etc.
		 *
		 * @since 1.9.6
		 */
		$template_page_settings = get_post_meta( $template_id, BRICKS_DB_PAGE_SETTINGS, true );
		if ( $template_page_settings ) {
			$page_settings_controls = Settings::get_controls_data( 'page' );
			$page_settings_css      = Assets::generate_inline_css_from_element(
				[ 'settings' => $template_page_settings ],
				$page_settings_controls['controls'],
				'page'
			);

			// Add style to template output
			if ( $page_settings_css ) {
				$output .= "<style>$page_settings_css</style>";
			}
		}

		// Render template output
		$output .= do_shortcode( "[bricks_template id=\"$template_id\"]" );

		return $output;
	}

	/**
	 * Get builder state-group definitions for stateful Woo v2 elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_builder_state_group_definitions() {
		$definitions = [];

		// Empty definitions are the builder-side hard-off switch. Without them
		// Vue skips v2 preview filtering and treats saved states as dormant data.
		if ( self::use_advanced_modular_elements() ) {
			$definitions = [
				'woocommerce-cart-v2'         => [
					'previewSettingKey' => 'previewMode',
					'defaultState'      => 'cart',
					'previewToState'    => [
						'cart'  => 'cart',
						'empty' => 'empty',
					],
					'states'            => [
						'cart'  => 'woocommerce-cart-v2-state-cart',
						'empty' => 'woocommerce-cart-v2-state-empty',
					],
				],
				'woocommerce-checkout-v2'     => [
					'previewSettingKey' => 'previewMode',
					'defaultState'      => 'checkout',
					'previewToState'    => [
						'normal'   => 'checkout',
						'checkout' => 'checkout',
						'login'    => 'login',
						'pay'      => 'pay',
						'thankyou' => 'thankyou',
						'receipt'  => 'receipt',
					],
					'states'            => [
						'checkout' => 'woocommerce-checkout-v2-state-checkout',
						'login'    => 'woocommerce-checkout-v2-state-login',
						'pay'      => 'woocommerce-checkout-v2-state-pay',
						'thankyou' => 'woocommerce-checkout-v2-state-thankyou',
						'receipt'  => 'woocommerce-checkout-v2-state-receipt',
					],
				],
				'woocommerce-account-page-v2' => [
					'previewSettingKey' => 'previewMode',
					'defaultState'      => 'dashboard',
					'previewToState'    => [
						'dashboard'                  => 'dashboard',
						'orders'                     => 'orders',
						'view-order'                 => 'view-order',
						'downloads'                  => 'downloads',
						'addresses'                  => 'addresses',
						'edit-address'               => 'edit-address',
						'edit-account'               => 'edit-account',
						'payment-methods'            => 'payment-methods',
						'add-payment-method'         => 'add-payment-method',
						'login'                      => 'login',
						'lost-password'              => 'lost-password',
						'lost-password-confirmation' => 'lost-password-confirmation',
						'reset-password'             => 'reset-password',
					],
					'states'            => [
						'dashboard'                  => 'woocommerce-account-v2-state-dashboard',
						'orders'                     => 'woocommerce-account-v2-state-orders',
						'view-order'                 => 'woocommerce-account-v2-state-view-order',
						'downloads'                  => 'woocommerce-account-v2-state-downloads',
						'addresses'                  => 'woocommerce-account-v2-state-addresses',
						'edit-address'               => 'woocommerce-account-v2-state-edit-address',
						'edit-account'               => 'woocommerce-account-v2-state-edit-account',
						'payment-methods'            => 'woocommerce-account-v2-state-payment-methods',
						'add-payment-method'         => 'woocommerce-account-v2-state-add-payment-method',
						'login'                      => 'woocommerce-account-v2-state-login',
						'lost-password'              => 'woocommerce-account-v2-state-lost-password',
						'lost-password-confirmation' => 'woocommerce-account-v2-state-lost-password-confirmation',
						'reset-password'             => 'woocommerce-account-v2-state-reset-password',
						'order-withdrawal'           => 'woocommerce-account-v2-state-order-withdrawal',
					],
				],
			];
		}

		// Keep saved state names recognizable when disabled; expose only enabled states for preview and repair.
		if ( isset( $definitions['woocommerce-account-page-v2'] ) && self::is_order_withdrawal_enabled() ) {
			$definitions['woocommerce-account-page-v2']['previewToState']['order-withdrawal'] = 'order-withdrawal';
		}

		return apply_filters( 'bricks/builder/state_group_definitions', $definitions );
	}

	/**
	 * Get Cart page Bricks data if it contains a Cart v2 element.
	 *
	 * @return array|false
	 */
	public static function get_cart_page_data_with_cart_v2() {
		if ( ! self::use_advanced_modular_elements() ) {
			// Do not let saved Cart v2 content take over the cart page while off.
			return false;
		}

		$cart_page_id = wc_get_page_id( 'cart' );

		if ( ! $cart_page_id || $cart_page_id < 1 ) {
			return false;
		}

		$cart_page_data = get_post_meta( $cart_page_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! is_array( $cart_page_data ) || ! count( $cart_page_data ) ) {
			return false;
		}

		if ( ! self::has_cart_v2_element( $cart_page_data ) ) {
			return false;
		}

		return $cart_page_data;
	}

	/**
	 * Check if a Bricks data array contains Cart v2 element.
	 *
	 * @param array $elements
	 * @return boolean
	 */
	public static function has_cart_v2_element( $elements ) {
		if ( ! is_array( $elements ) || ! count( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-cart-v2' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get Checkout page Bricks data if it contains a Checkout v2 element.
	 *
	 * @since 2.4
	 *
	 * @return array|false
	 */
	public static function get_checkout_page_data_with_checkout_v2() {
		if ( ! self::use_advanced_modular_elements() ) {
			// Do not let saved Checkout v2 content take over checkout endpoints while off.
			return false;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );

		if ( ! $checkout_page_id || $checkout_page_id < 1 ) {
			return false;
		}

		$checkout_page_data = get_post_meta( $checkout_page_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! is_array( $checkout_page_data ) || ! count( $checkout_page_data ) ) {
			return false;
		}

		if ( ! self::has_checkout_v2_element( $checkout_page_data ) ) {
			return false;
		}

		return $checkout_page_data;
	}

	/**
	 * Check if a Bricks data array contains Checkout v2 element.
	 *
	 * @since 2.4
	 *
	 * @param array $elements
	 * @return boolean
	 */
	public static function has_checkout_v2_element( $elements ) {
		if ( ! is_array( $elements ) || ! count( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-checkout-v2' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the current Checkout v2 endpoint state.
	 *
	 * @since 2.4
	 *
	 * @return string|false
	 */
	public static function get_checkout_v2_current_state() {
		if ( ! self::is_woocommerce_active() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		if ( self::get_checkout_v2_login_required_args() ) {
			return 'login';
		}

		$order_pay_id = absint( get_query_var( 'order-pay', false ) );
		if ( $order_pay_id > 0 ) {
			$order = wc_get_order( $order_pay_id );

			return $order && ! $order->needs_payment() ? 'receipt' : 'pay';
		}

		if ( absint( get_query_var( 'order-received', false ) ) > 0 ) {
			return 'thankyou';
		}

		return 'checkout';
	}

	/**
	 * Resolve header/footer visibility overrides from the active Woo v2 state.
	 *
	 * Runs after Bricks initializes the current page data and before templates and
	 * their assets are rendered.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_v2_state_template_visibility() {
		$this->v2_state_template_visibility = [];

		if (
			bricks_is_builder() ||
			bricks_is_builder_call() ||
			! self::use_advanced_modular_elements()
		) {
			return;
		}

		$state_settings = false;

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$state = self::get_checkout_v2_current_state();

			if ( $state ) {
				$state_settings = self::get_checkout_v2_state_settings( $state );
			}
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$state = self::get_account_v2_current_state();

			if ( $state ) {
				$state_settings = self::get_account_v2_state_settings( $state );
			}
		}

		if ( ! is_array( $state_settings ) ) {
			return;
		}

		foreach ( [ 'header', 'footer' ] as $template_type ) {
			$setting_key = "{$template_type}Visibility";
			$visibility  = $state_settings[ $setting_key ] ?? 'inherit';

			if ( in_array( $visibility, [ 'enable', 'disable' ], true ) ) {
				$this->v2_state_template_visibility[ $template_type ] = $visibility;
			}
		}
	}

	/**
	 * Apply the active Woo v2 state's header/footer visibility override.
	 *
	 * @since 2.4
	 *
	 * @param bool   $is_disabled  Whether the parent page disables the template.
	 * @param string $template_type Template type, such as header or footer.
	 * @return bool
	 */
	public function filter_v2_state_template_disabled( $is_disabled, $template_type ) {
		$visibility = $this->v2_state_template_visibility[ $template_type ] ?? 'inherit';

		if ( $visibility === 'enable' ) {
			return false;
		}

		if ( $visibility === 'disable' ) {
			return true;
		}

		return $is_disabled;
	}

	/**
	 * Get Account page Bricks data if it contains an Account v2 element.
	 *
	 * @since 2.4
	 *
	 * @return array|false
	 */
	public static function get_account_page_data_with_account_v2() {
		if ( ! self::use_advanced_modular_elements() ) {
			// Do not let saved Account page v2 content take over account endpoints while off.
			return false;
		}

		$account_page_id = wc_get_page_id( 'myaccount' );

		if ( ! $account_page_id || $account_page_id < 1 ) {
			return false;
		}

		$account_page_data = get_post_meta( $account_page_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! is_array( $account_page_data ) || ! count( $account_page_data ) ) {
			return false;
		}

		if ( ! self::has_account_v2_element( $account_page_data ) ) {
			return false;
		}

		return $account_page_data;
	}

	/**
	 * Check if a Bricks data array contains Account v2 element.
	 *
	 * @since 2.4
	 *
	 * @param array $elements
	 * @return boolean
	 */
	public static function has_account_v2_element( $elements ) {
		if ( ! is_array( $elements ) || ! count( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-account-page-v2' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve current account endpoint state for Account v2.
	 *
	 * @since 2.4
	 *
	 * @return string|false
	 */
	public static function get_account_v2_current_state() {
		if ( ! is_account_page() || ! self::is_woocommerce_active() ) {
			return false;
		}

		$endpoint = WC()->query->get_current_endpoint();

		switch ( $endpoint ) {
			case 'order-withdrawal':
				return self::is_order_withdrawal_request() ? 'order-withdrawal' : false;

			case 'orders':
				return 'orders';

			case 'view-order':
				return 'view-order';

			case 'downloads':
				return 'downloads';

			case 'payment-methods':
				return 'payment-methods';

			case 'add-payment-method':
				return 'add-payment-method';

			case 'edit-account':
				return 'edit-account';

			case 'edit-address':
				global $wp;

				if ( empty( $wp->query_vars['edit-address'] ) ) {
					return 'addresses';
				}

				return 'edit-address';

			case 'lost-password':
				// Resolve real V2 requests through Woo's validation. Builder state selection
				// below remains available without consuming credentials from preview URLs.
				if ( ! bricks_is_builder() && ! bricks_is_builder_call() && self::get_account_page_data_with_account_v2() ) {
					return Woocommerce_Password_Reset::get_state();
				}

				if (
					isset( $_GET['reset-link-sent'] ) ||
					( isset( $_GET['wc-reset-password'] ) && $_GET['wc-reset-password'] === 'reset-link-sent' )
				) {
					return 'lost-password-confirmation';
				}

				if (
					isset( $_GET['show-reset-form'] ) ||
					( isset( $_GET['key'] ) && isset( $_GET['login'] ) )
				) {
					return 'reset-password';
				}

				return 'lost-password';

			case '':
				// WooCommerce only reports endpoints registered through its query API. Preserve custom WordPress endpoints
				// handled by an account endpoint action.
				if ( ! self::is_wc_account_dashboard() ) {
					return false;
				}

				return is_user_logged_in() ? 'dashboard' : 'login';

			default:
				return false; // Maybe custom endpoint, return empty to not break the render and let users handle it with custom code if needed.
		}
	}

	/**
	 * Get Account v2 state element settings from the account page.
	 *
	 * @since 2.4
	 *
	 * @param string $state Account state key.
	 * @return array|false
	 */
	public static function get_account_v2_state_settings( $state ) {
		$account_page_data = self::get_account_page_data_with_account_v2();

		if ( ! $account_page_data ) {
			return false;
		}

		$state_groups          = self::get_builder_state_group_definitions();
		$state_to_element_name = $state_groups['woocommerce-account-page-v2']['states'] ?? [];
		$state_element_name    = $state_to_element_name[ $state ] ?? false;

		if ( ! $state_element_name ) {
			return false;
		}

		foreach ( $account_page_data as $element ) {
			if ( ( $element['name'] ?? '' ) === $state_element_name && ! empty( $element['settings'] ) ) {
				return $element['settings'];
			}
		}

		return false;
	}

	/**
	 * Collect an element subtree IDs recursively.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements_by_id
	 * @param string $element_id
	 * @param array  $collected_ids
	 * @return void
	 */
	private static function collect_v2_state_subtree_ids( $elements_by_id, $element_id, &$collected_ids ) {
		if ( ! isset( $elements_by_id[ $element_id ] ) ) {
			return;
		}

		$collected_ids[] = $element_id;

		$children = $elements_by_id[ $element_id ]['children'] ?? [];
		if ( ! is_array( $children ) || ! count( $children ) ) {
			return;
		}

		foreach ( $children as $child_id ) {
			self::collect_v2_state_subtree_ids( $elements_by_id, $child_id, $collected_ids );
		}
	}

	/**
	 * Get render data for one specific v2 state child from page data.
	 *
	 * @since 2.4
	 *
	 * @param array  $page_data
	 * @param string $parent_element_name
	 * @param string $state_element_name
	 * @return array|false
	 */
	public static function get_v2_state_render_data( $page_data, $parent_element_name, $state_element_name ) {
		if ( ! is_array( $page_data ) || ! count( $page_data ) ) {
			return false;
		}

		$elements_by_id = [];
		$parent_element = false;

		foreach ( $page_data as $element ) {
			if ( ! isset( $element['id'] ) ) {
				continue;
			}

			$elements_by_id[ $element['id'] ] = $element;

			if ( ! $parent_element && isset( $element['name'] ) && $element['name'] === $parent_element_name ) {
				$parent_element = $element;
			}
		}

		if ( ! $parent_element || empty( $parent_element['children'] ) || ! is_array( $parent_element['children'] ) ) {
			return false;
		}

		$state_element_id = false;

		foreach ( $parent_element['children'] as $child_id ) {
			$child = $elements_by_id[ $child_id ] ?? false;
			if ( ! $child ) {
				continue;
			}

			if ( isset( $child['name'] ) && $child['name'] === $state_element_name ) {
				$state_element_id = $child_id;
				break;
			}
		}

		if ( ! $state_element_id ) {
			return false;
		}

		$subtree_ids = [];
		self::collect_v2_state_subtree_ids( $elements_by_id, $state_element_id, $subtree_ids );

		if ( ! count( $subtree_ids ) ) {
			return false;
		}

		$render_data = [];
		foreach ( $subtree_ids as $subtree_id ) {
			if ( ! isset( $elements_by_id[ $subtree_id ] ) ) {
				continue;
			}

			$element = $elements_by_id[ $subtree_id ];

			// Make state root element renderable as top-level root.
			if ( $subtree_id === $state_element_id ) {
				unset( $element['parent'] );
			}

			$render_data[] = $element;
		}

		return count( $render_data ) ? $render_data : false;
	}

	/**
	 * Get element data with its descendants from a flat elements array.
	 *
	 * @since 2.4
	 */
	public static function extract_element_data_from_root( $element, $full_elements ) {
		$element_id = $element['id'] ?? false;

		if ( ! $element_id ) {
			return false;
		}

		$indexed_elements = [];

		// STEP: Build the flat list index
		foreach ( $full_elements as $el ) {
			$indexed_elements[ $el['id'] ] = $el;
		}

		// Remove the parent to make it renderable as a top-level root, and collect its descendants.
		$element['parent'] = 0;

		$data = [ $element ];

		$children = $element['children'] ?? [];

		while ( ! empty( $children ) ) {
			$child_id = array_shift( $children );

			if ( ! isset( $indexed_elements[ $child_id ] ) ) {
				continue;
			}

			$child_element = $indexed_elements[ $child_id ];

			$data[] = $child_element;

			if ( isset( $child_element['children'] ) && is_array( $child_element['children'] ) && count( $child_element['children'] ) ) {
				$children = array_merge( $children, $child_element['children'] );
			}
		}

		return $data;
	}

	/**
	 * Get Cart v2 state render data from cart page.
	 *
	 * @since 2.4
	 *
	 * @param string $state cart|empty.
	 * @return array|false
	 */
	public static function get_cart_v2_state_render_data( $state ) {
		$cart_page_data = self::get_cart_page_data_with_cart_v2();

		if ( ! $cart_page_data ) {
			return false;
		}

		$state_groups = self::get_builder_state_group_definitions();

		$state_to_element_name = $state_groups['woocommerce-cart-v2']['states'] ?? [];

		$state_element_name = $state_to_element_name[ $state ] ?? false;
		if ( ! $state_element_name ) {
			return false;
		}

		return self::get_v2_state_render_data( $cart_page_data, 'woocommerce-cart-v2', $state_element_name );
	}

	/**
	 * Get Checkout v2 state render data from checkout page.
	 *
	 * @since 2.4
	 *
	 * @param string $state checkout|login|pay|thankyou|receipt.
	 * @return array|false
	 */
	public static function get_checkout_v2_state_render_data( $state ) {
		$checkout_page_data = self::get_checkout_page_data_with_checkout_v2();

		if ( ! $checkout_page_data ) {
			return false;
		}

		$state_groups = self::get_builder_state_group_definitions();

		$state_to_element_name = $state_groups['woocommerce-checkout-v2']['states'] ?? [];

		$state_element_name = $state_to_element_name[ $state ] ?? false;
		if ( ! $state_element_name ) {
			return false;
		}

		return self::get_v2_state_render_data( $checkout_page_data, 'woocommerce-checkout-v2', $state_element_name );
	}

	/**
	 * Get Checkout v2 state element settings from the checkout page.
	 *
	 * @since 2.4
	 *
	 * @param string $state checkout|login|pay|thankyou|receipt.
	 * @return array|false
	 */
	public static function get_checkout_v2_state_settings( $state ) {
		$checkout_page_data = self::get_checkout_page_data_with_checkout_v2();

		if ( ! $checkout_page_data ) {
			return false;
		}

		$state_groups          = self::get_builder_state_group_definitions();
		$state_to_element_name = $state_groups['woocommerce-checkout-v2']['states'] ?? [];
		$state_element_name    = $state_to_element_name[ $state ] ?? false;

		if ( ! $state_element_name ) {
			return false;
		}

		foreach ( $checkout_page_data as $element ) {
			if ( ( $element['name'] ?? '' ) === $state_element_name && ! empty( $element['settings'] ) ) {
				return $element['settings'];
			}
		}

		return false;
	}

	/**
	 * Push login form arguments for the current Checkout v2 login-required render.
	 *
	 * @since 2.4
	 *
	 * @param array $args Login form arguments.
	 * @return void
	 */
	public static function push_checkout_v2_login_form_args( $args = [] ) {
		$args = is_array( $args ) ? $args : [];
		$args = wp_parse_args(
			$args,
			[
				'message'  => '',
				'notice'   => '',
				'redirect' => '',
				'hidden'   => false,
				'source'   => '',
			]
		);

		$args['message']  = is_scalar( $args['message'] ) ? (string) $args['message'] : '';
		$args['notice']   = is_scalar( $args['notice'] ) ? (string) $args['notice'] : '';
		$args['redirect'] = is_scalar( $args['redirect'] ) ? esc_url_raw( (string) $args['redirect'] ) : '';
		$args['hidden']   = ! empty( $args['hidden'] );
		$args['source']   = sanitize_key( (string) $args['source'] );

		self::$checkout_v2_login_form_args[] = $args;
	}

	/**
	 * Pop login form arguments after rendering the Checkout v2 login-required state.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public static function pop_checkout_v2_login_form_args() {
		if ( empty( self::$checkout_v2_login_form_args ) || ! is_array( self::$checkout_v2_login_form_args ) ) {
			return;
		}

		array_pop( self::$checkout_v2_login_form_args );
	}

	/**
	 * Get the active Checkout v2 login form arguments.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_checkout_v2_login_form_args() {
		if ( empty( self::$checkout_v2_login_form_args ) || ! is_array( self::$checkout_v2_login_form_args ) ) {
			return [];
		}

		$args = end( self::$checkout_v2_login_form_args );

		return is_array( $args ) ? $args : [];
	}

	/**
	 * Get a value from the active Checkout v2 login form arguments.
	 *
	 * @since 2.4
	 *
	 * @param string $key     Argument key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_checkout_v2_login_form_arg( $key = '', $default = null ) {
		$args = self::get_checkout_v2_login_form_args();

		return is_array( $args ) && array_key_exists( $key, $args ) ? $args[ $key ] : $default;
	}

	/**
	 * Resolve whether the current checkout endpoint should render the Checkout v2 login-required state.
	 *
	 * @since 2.4
	 * @see https://app.clickup.com/t/86cb33dqz
	 *
	 * @return array|false
	 */
	public static function get_checkout_v2_login_required_args() {
		if (
			is_user_logged_in() ||
			! function_exists( 'is_checkout' ) ||
			! is_checkout()
		) {
			return false;
		}

		$order_pay_id = absint( get_query_var( 'order-pay', false ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce validates the order key before the login state is rendered.
		if ( $order_pay_id > 0 && isset( $_GET['pay_for_order'], $_GET['key'] ) ) {
			$order = self::get_order_pay_contextual_order();

			if ( is_a( $order, 'WC_Order' ) && ! current_user_can( 'pay_for_order', $order_pay_id ) ) {
				return [
					'notice'   => esc_html__( 'Please log in to your account below to continue to the payment form.', 'woocommerce' ),
					'redirect' => $order->get_checkout_payment_url(),
					'hidden'   => false,
					'source'   => 'order-pay',
				];
			}
		}

		$order_received_id = absint( get_query_var( 'order-received', false ) );

		if ( $order_received_id > 0 ) {
			$order = self::get_order_received_contextual_order();

			if ( is_a( $order, 'WC_Order' ) ) {
				$verify_known_shoppers = apply_filters( 'woocommerce_order_received_verify_known_shoppers', true );
				$order_customer_id     = $order->get_customer_id();

				if ( $verify_known_shoppers && $order_customer_id && get_current_user_id() !== $order_customer_id ) {
					return [
						'notice'   => esc_html__( 'Please log in to your account to view this order.', 'woocommerce' ),
						'redirect' => $order->get_checkout_order_received_url(),
						'hidden'   => false,
						'source'   => 'order-received',
					];
				}
			}
		}

		$checkout         = function_exists( 'WC' ) ? WC()->checkout() : false;
		$is_main_checkout = ! is_wc_endpoint_url();

		// Endpoint authorization is resolved above so its specific messages and return URLs take precedence.
		// Mirror WooCommerce's form-checkout.php guard here before its shortcode bypasses the editable v2 state.
		if (
			$is_main_checkout &&
			is_a( $checkout, 'WC_Checkout' ) &&
			! $checkout->is_registration_enabled() &&
			$checkout->is_registration_required()
		) {
			return [
				'notice'   => esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) ),
				'redirect' => wc_get_checkout_url(),
				'hidden'   => false,
				'source'   => 'checkout',
			];
		}

		return false;
	}

	/**
	 * Render the Checkout v2 login-required state.
	 *
	 * @since 2.4
	 *
	 * @param array|false $args Login form arguments. Auto-resolved when omitted.
	 * @return bool
	 */
	public static function render_checkout_v2_login_required_state( $args = false ) {
		if ( ! self::use_advanced_modular_elements() ) {
			return false;
		}

		if ( $args === false ) {
			$args = self::get_checkout_v2_login_required_args();
		}

		if ( ! is_array( $args ) ) {
			return false;
		}

		$state_data = self::get_checkout_v2_state_render_data( 'login' );

		// State root without descendants means no custom content was configured for this state.
		if ( ! is_array( $state_data ) || count( $state_data ) <= 1 ) {
			return false;
		}

		$state_settings = self::get_checkout_v2_state_settings( 'login' );
		$classes        = [ 'woocommerce', 'woocommerce-checkout', 'brx-wc-checkout-v2--login' ];

		if ( is_array( $state_settings ) && ! empty( $state_settings['floatingLabelStyle'] ) ) {
			$classes[] = 'bricks-floating-label';
		}

		self::push_checkout_v2_login_form_args( $args );
		$added_notice = false;

		try {
			$notice = self::get_checkout_v2_login_form_arg( 'notice', '' );

			if ( $notice && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $notice, 'notice', [ 'brx_checkout_v2_login_required' => true ] );
				$added_notice = true;
			}

			echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			echo Frontend::render_data( $state_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		} finally {
			if ( $added_notice && function_exists( 'wc_get_notices' ) && function_exists( 'wc_set_notices' ) ) {
				$notices = wc_get_notices();

				if ( ! empty( $notices['notice'] ) && is_array( $notices['notice'] ) ) {
					$notices['notice'] = array_values(
						array_filter(
							$notices['notice'],
							function( $notice_data ) {
								return empty( $notice_data['data']['brx_checkout_v2_login_required'] );
							}
						)
					);

					wc_set_notices( $notices );
				}
			}

			self::pop_checkout_v2_login_form_args();
		}

		return true;
	}

	/**
	 * Push Checkout v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state   State key.
	 * @param array  $context Preview context.
	 * @return void
	 */
	public static function push_checkout_v2_state_preview_context( $state = 'thankyou', $context = [] ) {
		$state   = sanitize_key( $state ?: 'thankyou' );
		$context = is_array( $context ) ? $context : [];

		if ( isset( $context['orderId'] ) ) {
			$context['orderId'] = absint( $context['orderId'] );
		}

		if ( empty( self::$checkout_v2_preview_contexts[ $state ] ) || ! is_array( self::$checkout_v2_preview_contexts[ $state ] ) ) {
			self::$checkout_v2_preview_contexts[ $state ] = [];
		}

		self::$checkout_v2_preview_contexts[ $state ][] = $context;
	}

	/**
	 * Pop Checkout v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return void
	 */
	public static function pop_checkout_v2_state_preview_context( $state = 'thankyou' ) {
		$state = sanitize_key( $state ?: 'thankyou' );

		if ( empty( self::$checkout_v2_preview_contexts[ $state ] ) || ! is_array( self::$checkout_v2_preview_contexts[ $state ] ) ) {
			return;
		}

		array_pop( self::$checkout_v2_preview_contexts[ $state ] );

		if ( empty( self::$checkout_v2_preview_contexts[ $state ] ) ) {
			unset( self::$checkout_v2_preview_contexts[ $state ] );
		}
	}

	/**
	 * Check whether Checkout v2 preview context exists for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return bool
	 */
	public static function has_checkout_v2_state_preview_context( $state = 'thankyou' ) {
		$state = sanitize_key( $state ?: 'thankyou' );

		return ! empty( self::$checkout_v2_preview_contexts[ $state ] ) && is_array( self::$checkout_v2_preview_contexts[ $state ] );
	}

	/**
	 * Get the active Checkout v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return array
	 */
	public static function get_checkout_v2_state_preview_context( $state = 'thankyou' ) {
		$state = sanitize_key( $state ?: 'thankyou' );

		if ( empty( self::$checkout_v2_preview_contexts[ $state ] ) || ! is_array( self::$checkout_v2_preview_contexts[ $state ] ) ) {
			return [];
		}

		$current_context = end( self::$checkout_v2_preview_contexts[ $state ] );

		return is_array( $current_context ) ? $current_context : [];
	}

	/**
	 * Get a value from the active Checkout v2 preview context.
	 *
	 * @since 2.4
	 *
	 * @param string $state   State key.
	 * @param string $key     Context key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_checkout_v2_state_preview_context_value( $state = 'thankyou', $key = '', $default = null ) {
		$context = self::get_checkout_v2_state_preview_context( $state );

		return is_array( $context ) && array_key_exists( $key, $context ) ? $context[ $key ] : $default;
	}

	/**
	 * Get Checkout v2 preview order in builder context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return \WC_Order|false
	 */
	public static function get_checkout_v2_state_preview_order( $state = 'thankyou' ) {
		if ( ! ( bricks_is_builder() || bricks_is_builder_call() ) ) {
			return false;
		}

		$state  = sanitize_key( $state ?: 'thankyou' );
		$states = [ $state ];

		if ( in_array( $state, [ 'thankyou', 'pay', 'receipt' ], true ) ) {
			$states = array_values( array_unique( array_merge( $states, [ 'thankyou', 'pay', 'receipt' ] ) ) );
		}

		foreach ( $states as $preview_state ) {
			if ( ! self::has_checkout_v2_state_preview_context( $preview_state ) ) {
				continue;
			}

			$order            = false;
			$preview_order_id = absint( self::get_checkout_v2_state_preview_context_value( $preview_state, 'orderId', 0 ) );

			if ( $preview_order_id > 0 ) {
				$order = wc_get_order( $preview_order_id );

				if ( ! self::current_user_can_view_order( $order ) ) {
					return false;
				}
			}

			// Fallback to any order if specific preview order is not set or found, to allow rendering the state with actual order data in builder.
			if ( ! is_a( $order, 'WC_Order' ) ) {
				$orders = wc_get_orders(
					[
						'limit' => 1,
					]
				);

				$order = $orders ? $orders[0] : false;
			}

			if ( self::current_user_can_view_order( $order ) ) {
				return $order;
			}
		}

		return false;
	}

	/**
	 * Resolve order from Checkout v2 preview context or request context.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return \WC_Order|false
	 */
	public static function get_contextual_checkout_v2_order( $state = 'thankyou' ) {
		$state = sanitize_key( $state ?: 'thankyou' );
		$order = self::get_checkout_v2_state_preview_order( $state );

		if ( is_a( $order, 'WC_Order' ) ) {
			return $order;
		}

		if ( $state === 'thankyou' ) {
			$order = self::get_order_received_contextual_order();

			if ( is_a( $order, 'WC_Order' ) ) {
				return $order;
			}

			return self::get_order_pay_contextual_order();
		}

		if ( ! in_array( $state, [ 'pay', 'receipt' ], true ) ) {
			return false;
		}

		return self::get_order_pay_contextual_order();
	}

	/**
	 * Push Account v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state   State key.
	 * @param array  $context Preview context.
	 * @return void
	 */
	public static function push_account_v2_state_preview_context( $state = 'view-order', $context = [] ) {
		$state   = sanitize_key( $state ?: 'view-order' );
		$context = is_array( $context ) ? $context : [];

		if ( isset( $context['orderId'] ) ) {
			$context['orderId'] = absint( $context['orderId'] );
		}

		// Keep builder preview contexts limited to Woo's native endpoint types so descendants can trust the value.
		if ( isset( $context['addressType'] ) ) {
			$address_type           = sanitize_key( $context['addressType'] );
			$context['addressType'] = in_array( $address_type, [ 'billing', 'shipping' ], true ) ? $address_type : 'billing';
		}

		if ( empty( self::$account_v2_preview_contexts[ $state ] ) || ! is_array( self::$account_v2_preview_contexts[ $state ] ) ) {
			self::$account_v2_preview_contexts[ $state ] = [];
		}

		self::$account_v2_preview_contexts[ $state ][] = $context;
	}

	/**
	 * Pop Account v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return void
	 */
	public static function pop_account_v2_state_preview_context( $state = 'view-order' ) {
		$state = sanitize_key( $state ?: 'view-order' );

		if ( empty( self::$account_v2_preview_contexts[ $state ] ) || ! is_array( self::$account_v2_preview_contexts[ $state ] ) ) {
			return;
		}

		array_pop( self::$account_v2_preview_contexts[ $state ] );

		if ( empty( self::$account_v2_preview_contexts[ $state ] ) ) {
			unset( self::$account_v2_preview_contexts[ $state ] );
		}
	}

	/**
	 * Check whether Account v2 preview context exists for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return bool
	 */
	public static function has_account_v2_state_preview_context( $state = 'view-order' ) {
		$state = sanitize_key( $state ?: 'view-order' );

		return ! empty( self::$account_v2_preview_contexts[ $state ] ) && is_array( self::$account_v2_preview_contexts[ $state ] );
	}

	/**
	 * Get the active Account v2 preview context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return array
	 */
	public static function get_account_v2_state_preview_context( $state = 'view-order' ) {
		$state = sanitize_key( $state ?: 'view-order' );

		if ( empty( self::$account_v2_preview_contexts[ $state ] ) || ! is_array( self::$account_v2_preview_contexts[ $state ] ) ) {
			return [];
		}

		$current_context = end( self::$account_v2_preview_contexts[ $state ] );

		return is_array( $current_context ) ? $current_context : [];
	}

	/**
	 * Get a value from the active Account v2 preview context.
	 *
	 * @since 2.4
	 *
	 * @param string $state   State key.
	 * @param string $key     Context key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_account_v2_state_preview_context_value( $state = 'view-order', $key = '', $default = null ) {
		$context = self::get_account_v2_state_preview_context( $state );

		return is_array( $context ) && array_key_exists( $key, $context ) ? $context[ $key ] : $default;
	}

	/**
	 * Get Account v2 preview order in builder context for a specific state.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return \WC_Order|false
	 */
	public static function get_account_v2_state_preview_order( $state = 'view-order' ) {
		if ( ! ( bricks_is_builder() || bricks_is_builder_call() ) ) {
			return false;
		}

		$state = sanitize_key( $state ?: 'view-order' );

		if ( ! self::has_account_v2_state_preview_context( $state ) ) {
			return false;
		}

		$order            = false;
		$preview_order_id = absint( self::get_account_v2_state_preview_context_value( $state, 'orderId', 0 ) );

		if ( $preview_order_id > 0 ) {
			$order = wc_get_order( $preview_order_id );

			if ( ! self::current_user_can_view_order( $order ) ) {
				return false;
			}
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$orders = wc_get_orders(
				[
					'limit' => 1,
				]
			);

			$order = $orders ? $orders[0] : false;
		}

		return self::current_user_can_view_order( $order ) ? $order : false;
	}

	/**
	 * Check whether the current user can view an order.
	 *
	 * @since 2.4
	 *
	 * @param mixed $order Order instance.
	 * @return bool
	 */
	private static function current_user_can_view_order( $order ) {
		return is_a( $order, 'WC_Order' ) && current_user_can( 'view_order', $order->get_id() );
	}

	/**
	 * Resolve order from Account v2 preview context or request context.
	 *
	 * @since 2.4
	 *
	 * @param string $state State key.
	 * @return \WC_Order|false
	 */
	public static function get_contextual_account_v2_order( $state = 'view-order' ) {
		$state = sanitize_key( $state ?: 'view-order' );
		$order = self::get_account_v2_state_preview_order( $state );

		if ( is_a( $order, 'WC_Order' ) ) {
			return $order;
		}

		if ( $state !== 'view-order' ) {
			return false;
		}

		return self::get_view_order_contextual_order();
	}

	/**
	 * Resolve order from Account v2 view-order context first, then Checkout v2 order context.
	 *
	 * @since 2.4
	 *
	 * @param string $checkout_state Checkout state fallback key.
	 * @return \WC_Order|false
	 */
	public static function get_contextual_order( $checkout_state = 'thankyou' ) {
		$order = self::get_contextual_account_v2_order( 'view-order' );

		if ( is_a( $order, 'WC_Order' ) ) {
			return $order;
		}

		return self::get_contextual_checkout_v2_order( $checkout_state );
	}

	/**
	 * Resolve order from the order-received endpoint.
	 *
	 * wc_clean() preserves arrays, so a key[] query or non-string filter result
	 * must be rejected before hash_equals() can compare the order's secret key.
	 *
	 * @since 2.4
	 *
	 * @return \WC_Order|false
	 */
	private static function get_order_received_contextual_order() {
		$order_id = get_query_var( 'order-received', false );
		$order_id = apply_filters( 'woocommerce_thankyou_order_id', absint( $order_id ) );

		if ( $order_id < 1 ) {
			return false;
		}

		$order_key = apply_filters( 'woocommerce_thankyou_order_key', empty( $_GET['key'] ) ? '' : wc_clean( wp_unslash( $_GET['key'] ) ) );
		$order     = wc_get_order( $order_id );

		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		if ( ! is_string( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return false;
		}

		return $order;
	}

	/**
	 * Resolve order from the order-pay endpoint.
	 *
	 * Reject array-valued key[] requests before hash_equals(); sanitizing the
	 * query value does not guarantee that it is a string.
	 *
	 * @since 2.4
	 *
	 * @return \WC_Order|false
	 */
	private static function get_order_pay_contextual_order() {
		$order_id = get_query_var( 'order-pay', false );

		if ( $order_id < 1 ) {
			return false;
		}

		$order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		$order     = wc_get_order( absint( $order_id ) );

		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		if ( ! is_string( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return false;
		}

		return $order;
	}

	/**
	 * Resolve order from the my-account view-order endpoint.
	 *
	 * @since 2.4
	 *
	 * @return \WC_Order|false
	 */
	private static function get_view_order_contextual_order() {
		$order_id = get_query_var( 'view-order', false );

		if ( $order_id < 1 ) {
			return false;
		}

		$order = wc_get_order( absint( $order_id ) );

		return self::current_user_can_view_order( $order ) ? $order : false;
	}

	/**
	 * Check whether the current Checkout v2 order context is the pay state.
	 *
	 * @since 2.4
	 *
	 * @param \WC_Order|false $order Order object.
	 * @param Query|false     $query Query object.
	 * @return bool
	 */
	private static function is_checkout_v2_pay_state_context( $order = false, $query = false ) {
		if ( self::has_checkout_v2_state_preview_context( 'pay' ) ) {
			return true;
		}

		$query_element_id = $query instanceof Query ? ( $query->element_id ?? '' ) : '';
		$query_element_id = $query_element_id ? explode( '-', $query_element_id )[0] : '';
		$element          = $query_element_id && ! empty( Frontend::$elements[ $query_element_id ] )
			? Frontend::$elements[ $query_element_id ]
			: false;

		while ( is_array( $element ) && ! empty( $element['parent'] ) ) {
			$parent = Frontend::$elements[ $element['parent'] ] ?? false;

			if ( ! is_array( $parent ) ) {
				break;
			}

			if ( ( $parent['name'] ?? '' ) === 'woocommerce-checkout-v2-state-pay' ) {
				return true;
			}

			$element = $parent;
		}

		return get_query_var( 'order-pay', false ) && is_a( $order, 'WC_Order' ) && $order->needs_payment();
	}

	/**
	 * Resolve thank-you order from Checkout v2 preview context or request context.
	 *
	 * @since 2.4
	 *
	 * @return \WC_Order|false
	 */
	public static function get_contextual_thankyou_order() {
		return self::get_contextual_order( 'thankyou' );
	}

	/**
	 * Get current Account orders page.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	public static function get_account_orders_current_page() {
		global $wp;

		return isset( $wp->query_vars['orders'] ) && ! empty( $wp->query_vars['orders'] ) ? max( 1, absint( $wp->query_vars['orders'] ) ) : 1;
	}

	/**
	 * Get Account orders query args.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_account_orders_query_args() {
		return apply_filters(
			'woocommerce_my_account_my_orders_query',
			[
				'customer' => get_current_user_id(),
				'page'     => self::get_account_orders_current_page(),
				'paginate' => true,
			]
		);
	}

	/**
	 * Get Account addresses description.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function get_account_addresses_description() {
		return apply_filters(
			'woocommerce_my_account_my_address_description',
			esc_html__( 'The following addresses will be used on the checkout page by default.', 'woocommerce' )
		);
	}

	/**
	 * Get Account addresses.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_account_addresses() {
		$customer_id = get_current_user_id();

		if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
			$addresses = apply_filters(
				'woocommerce_my_account_get_addresses',
				[
					'billing'  => __( 'Billing address', 'woocommerce' ),
					'shipping' => __( 'Shipping address', 'woocommerce' ),
				],
				$customer_id
			);
		} else {
			$addresses = apply_filters(
				'woocommerce_my_account_get_addresses',
				[
					'billing' => __( 'Billing address', 'woocommerce' ),
				],
				$customer_id
			);
		}

		if ( ! is_array( $addresses ) || empty( $addresses ) ) {
			return [];
		}

		$normalized_addresses = [];

		foreach ( $addresses as $address_type => $address_title ) {
			$address_type = is_scalar( $address_type ) ? sanitize_key( $address_type ) : '';

			if ( ! $address_type ) {
				continue;
			}

			$address_title     = is_scalar( $address_title ) ? wp_strip_all_tags( (string) $address_title ) : '';
			$formatted_address = wc_get_account_formatted_address( $address_type );
			$has_address       = ! empty( $formatted_address );

			$normalized_addresses[ $address_type ] = [
				'type'              => $address_type,
				'name'              => $address_type,
				'title'             => $address_title,
				'formatted_address' => $has_address ? wp_kses_post( $formatted_address ) : '',
				'has_address'       => $has_address,
				'edit_url'          => wc_get_endpoint_url( 'edit-address', $address_type ),
				'action_label'      => sprintf(
					/* translators: %s: Address title */
					$has_address ? esc_html__( 'Edit %s', 'woocommerce' ) : esc_html__( 'Add %s', 'woocommerce' ),
					$address_title
				),
			];
		}

		return $normalized_addresses;
	}

	/**
	 * Resolve Account edit-address address type.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function get_account_edit_address_type() {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			$preview_address_type = sanitize_key( (string) self::get_account_v2_state_preview_context_value( 'edit-address', 'addressType', '' ) );

			if ( in_array( $preview_address_type, [ 'billing', 'shipping' ], true ) ) {
				return $preview_address_type;
			}
		}

		global $wp;

		if ( isset( $wp->query_vars['edit-address'] ) && $wp->query_vars['edit-address'] !== '' ) {
			$query_address_type = wc_edit_address_i18n( sanitize_title( $wp->query_vars['edit-address'] ), true );

			if ( in_array( $query_address_type, [ 'billing', 'shipping' ], true ) ) {
				return $query_address_type;
			}
		}

		return 'billing';
	}

	/**
	 * Get Account edit-address type label.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function get_account_edit_address_type_label() {
		$address_type = self::get_account_edit_address_type();

		return $address_type === 'billing'
			? esc_html__( 'Billing address', 'woocommerce' )
			: esc_html__( 'Shipping address', 'woocommerce' );
	}

	/**
	 * Get Account edit-address title.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function get_account_edit_address_title() {
		$address_type = self::get_account_edit_address_type();
		$page_title   = self::get_account_edit_address_type_label();

		return apply_filters( 'woocommerce_my_account_edit_address_title', $page_title, $address_type );
	}

	/**
	 * Get field-targeted WooCommerce error notices.
	 *
	 * @since 2.4
	 *
	 * @param array|null $notices Optional WooCommerce notices.
	 * @return array
	 */
	public static function get_woo_notice_field_errors( $notices = null ) {
		if ( $notices === null ) {
			if ( ! function_exists( 'wc_get_notices' ) ) {
				return is_array( self::$woo_notice_field_errors ) ? self::$woo_notice_field_errors : [];
			}

			$notices = wc_get_notices( 'error' );

			// Bricks notice elements may already have printed notices, which clears Woo's live notice store.
			if ( empty( $notices ) && is_array( self::$woo_notice_field_errors ) ) {
				return self::$woo_notice_field_errors;
			}
		} elseif ( isset( $notices['error'] ) && is_array( $notices['error'] ) ) {
			$notices = $notices['error'];
		}

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			self::$woo_notice_field_errors = [];
			return [];
		}

		$field_errors = [];

		foreach ( $notices as $notice ) {
			$field_id = ! empty( $notice['data']['id'] ) ? sanitize_text_field( (string) $notice['data']['id'] ) : '';

			if ( $field_id === '' ) {
				continue;
			}

			if ( empty( $field_errors[ $field_id ] ) ) {
				$field_errors[ $field_id ] = [
					'id'       => $field_id,
					'messages' => [],
				];
			}

			if ( isset( $notice['notice'] ) ) {
				$field_errors[ $field_id ]['messages'][] = wp_strip_all_tags( (string) $notice['notice'] );
			}
		}

		self::$woo_notice_field_errors = $field_errors;

		return self::$woo_notice_field_errors;
	}

	/**
	 * Get field-targeted WooCommerce error notice IDs in notice order.
	 *
	 * @since 2.4
	 *
	 * @param array|null $notices Optional WooCommerce notices.
	 * @return string[]
	 */
	public static function get_woo_notice_field_error_ids( $notices = null ) {
		if ( $notices === null ) {
			if ( ! function_exists( 'wc_get_notices' ) ) {
				return is_array( self::$woo_notice_field_error_ids ) ? self::$woo_notice_field_error_ids : [];
			}

			$notices = wc_get_notices( 'error' );

			// Preserve notice order for markup enhancement after wc_print_notices() has consumed the live notices.
			if ( empty( $notices ) && is_array( self::$woo_notice_field_error_ids ) ) {
				return self::$woo_notice_field_error_ids;
			}
		} elseif ( isset( $notices['error'] ) && is_array( $notices['error'] ) ) {
			$notices = $notices['error'];
		}

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			self::$woo_notice_field_error_ids = [];
			return [];
		}

		$field_ids = [];

		foreach ( $notices as $notice ) {
			$field_ids[] = ! empty( $notice['data']['id'] ) ? sanitize_text_field( (string) $notice['data']['id'] ) : '';
		}

		self::$woo_notice_field_error_ids = $field_ids;

		return self::$woo_notice_field_error_ids;
	}

	/**
	 * Get Account edit-address fields for the current address type.
	 *
	 * Mirrors WC_Shortcode_My_Account::edit_address() so generated field elements
	 * use the same field registry and values as the native template.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_account_edit_address_fields() {
		return self::get_account_edit_address_fields_by_type( self::get_account_edit_address_type() );
	}

	/**
	 * Get Account edit-address fields for an explicit Woo address type.
	 *
	 * Used internally for generation and current-context rendering.
	 *
	 * @since 2.4
	 *
	 * @param string $address_type Address type.
	 * @return array
	 */
	private static function get_account_edit_address_fields_by_type( $address_type ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->countries ) {
			return [];
		}

		$customer_id  = get_current_user_id();
		$current_user = wp_get_current_user();
		$customer     = false;
		$address_type = sanitize_key( (string) $address_type );

		if ( ! in_array( $address_type, [ 'billing', 'shipping' ], true ) ) {
			$address_type = self::get_account_edit_address_type();
		}

		if ( ! empty( WC()->customer ) && is_a( WC()->customer, 'WC_Customer' ) ) {
			$customer = WC()->customer;
		}

		if ( $customer_id > 0 && class_exists( 'WC_Customer' ) && ( ! $customer || intval( $customer->get_id() ) !== $customer_id ) ) {
			$customer = new \WC_Customer( $customer_id );
		}

		$country_getter = 'get_' . $address_type . '_country';
		$country        = $customer && is_callable( [ $customer, $country_getter ] ) ? $customer->{$country_getter}() : '';

		if ( ( $country === '' || $country === null ) && $customer_id > 0 ) {
			$country = get_user_meta( $customer_id, $address_type . '_country', true );
		}

		if ( ! $country ) {
			$country = WC()->countries->get_base_country();
		}

		if ( $address_type === 'billing' ) {
			$allowed_countries = WC()->countries->get_allowed_countries();

			if ( ! array_key_exists( $country, $allowed_countries ) ) {
				$country = current( array_keys( $allowed_countries ) );
			}
		}

		if ( $address_type === 'shipping' ) {
			$allowed_countries = WC()->countries->get_shipping_countries();

			if ( ! array_key_exists( $country, $allowed_countries ) ) {
				$country = current( array_keys( $allowed_countries ) );
			}
		}

		$address = WC()->countries->get_address_fields( $country, $address_type . '_' );

		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );

		foreach ( $address as $key => $field ) {
			$value        = '';
			$field_getter = 'get_' . $key;

			if ( $customer && is_callable( [ $customer, $field_getter ] ) ) {
				$value = $customer->{$field_getter}();
			}

			if ( ( $value === '' || $value === null ) && $customer_id > 0 ) {
				$value = get_user_meta( $customer_id, $key, true );
			}

			if ( $value === '' || $value === null ) {
				switch ( $key ) {
					case 'billing_email':
					case 'shipping_email':
						$value = $current_user->user_email;
						break;
				}
			}

			$address[ $key ]['value'] = apply_filters( 'woocommerce_my_account_edit_address_field_value', $value, $key, $address_type );
		}

		return apply_filters( 'woocommerce_address_to_edit', $address, $address_type );
	}

	/**
	 * Get Account edit-address fields for generated elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_account_edit_address_generation_fields() {
		$billing_fields  = self::get_account_edit_address_fields_by_type( 'billing' );
		$shipping_fields = self::get_account_edit_address_fields_by_type( 'shipping' );
		$fields          = [];

		// Sync works from the billing/shipping union so custom fields added to either address can still be generated once.
		foreach ( [ $billing_fields, $shipping_fields ] as $address_fields ) {
			foreach ( $address_fields as $field_key => $field_config ) {
				$base_key = self::normalize_account_edit_address_field_key( $field_key );

				if ( ! $base_key || isset( $fields[ $base_key ] ) || ! is_array( $field_config ) ) {
					continue;
				}

				$fields[ $base_key ] = $field_config;
			}
		}

		uasort(
			$fields,
			function( $field_a, $field_b ) {
				$priority_a = isset( $field_a['priority'] ) ? intval( $field_a['priority'] ) : PHP_INT_MAX;
				$priority_b = isset( $field_b['priority'] ) ? intval( $field_b['priority'] ) : PHP_INT_MAX;

				return $priority_a <=> $priority_b;
			}
		);

		return $fields;
	}

	/**
	 * Normalize Account edit-address field key to an unprefixed key.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @return string
	 */
	public static function normalize_account_edit_address_field_key( $field_key ) {
		$field_key = sanitize_text_field( (string) $field_key );

		foreach ( [ 'billing_', 'shipping_' ] as $prefix ) {
			if ( strpos( $field_key, $prefix ) === 0 ) {
				return substr( $field_key, strlen( $prefix ) );
			}
		}

		return $field_key;
	}

	/**
	 * Get Account edit-address field data for rendering.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @return array|false
	 */
	public static function get_account_edit_address_field_data( $field_key ) {
		$address_type = self::get_account_edit_address_type();
		$base_key     = self::normalize_account_edit_address_field_key( $field_key );
		$actual_key   = $address_type . '_' . $base_key;
		$fields       = self::get_account_edit_address_fields_by_type( $address_type );

		// The builder stores normalized keys, but Woo save/validation expects runtime-prefixed keys.
		if ( empty( $fields[ $actual_key ] ) || ! is_array( $fields[ $actual_key ] ) ) {
			return false;
		}

		return [
			'key'     => $actual_key,
			'baseKey' => $base_key,
			'field'   => $fields[ $actual_key ],
			'value'   => wc_get_post_data_by_key( $actual_key, $fields[ $actual_key ]['value'] ?? '' ),
		];
	}

	/**
	 * Get Account downloads.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_account_downloads() {
		$woocommerce = function_exists( 'WC' ) ? WC() : false;

		if ( ! $woocommerce || empty( $woocommerce->customer ) || ! is_a( $woocommerce->customer, 'WC_Customer' ) ) {
			return [];
		}

		$downloads = $woocommerce->customer->get_downloadable_products();

		if ( ! is_array( $downloads ) || empty( $downloads ) ) {
			return [];
		}

		foreach ( $downloads as $key => $download ) {
			$order_id = ! empty( $download['order_id'] ) ? absint( $download['order_id'] ) : 0;

			if ( $order_id < 1 ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( is_a( $order, 'WC_Order' ) ) {
				$downloads[ $key ]['order'] = $order;
			}
		}

		return $downloads;
	}

	/**
	 * Check whether the current Account downloads context has downloads.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public static function has_account_downloads() {
		return (bool) self::get_account_downloads();
	}

	/**
	 * Get Account v2 state render data from my account page.
	 *
	 * @since 2.4
	 *
	 * @param string|false $state
	 * @return array|false
	 */
	public static function get_account_v2_state_render_data( $state = false ) {
		$account_page_data = self::get_account_page_data_with_account_v2();

		if ( ! $account_page_data ) {
			return false;
		}

		if ( ! $state ) {
			$state = self::get_account_v2_current_state();
		}

		if ( ! $state ) {
			return false;
		}

		$state_groups = self::get_builder_state_group_definitions();

		$state_to_element_name = $state_groups['woocommerce-account-page-v2']['states'] ?? [];

		$state_element_name = $state_to_element_name[ $state ] ?? false;
		if ( ! $state_element_name ) {
			return false;
		}

		$state_data = self::get_v2_state_render_data( $account_page_data, 'woocommerce-account-page-v2', $state_element_name );

		// State root without descendants means no custom content was configured for this state.
		if ( ! is_array( $state_data ) || count( $state_data ) <= 1 ) {
			return false;
		}

		return $state_data;
	}

	/**
	 * Set Bricks render context for WooCommerce AJAX endpoint render.
	 *
	 * @since 2.4
	 *
	 * @param string $wc_page Woo page key (cart, checkout, myaccount).
	 * @return int Context post ID or 0 if not resolved.
	 */
	private static function maybe_set_wc_ajax_render_context( $wc_page = '' ) {
		if ( ! bricks_is_ajax_call() ) {
			return 0;
		}

		$post_id = isset( $_REQUEST['bricks_post_id'] ) ? absint( $_REQUEST['bricks_post_id'] ) : 0;

		// Support custom AJAX payloads that pass postId.
		if ( ! $post_id && isset( $_REQUEST['postId'] ) ) {
			$post_id = absint( $_REQUEST['postId'] );
		}

		// Fallback to Woo page ID for native Woo AJAX requests.
		if ( ! $post_id && $wc_page ) {
			$post_id = absint( wc_get_page_id( $wc_page ) );
		}

		/**
		 * Allow integrations to override the AJAX render context post ID.
		 *
		 * @since 2.4
		 */
		$post_id = absint( apply_filters( 'bricks/woocommerce/ajax_render_post_id', $post_id, $wc_page ) );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return 0;
		}

		// Re-init and set correct active templates for this AJAX request.
		Database::init_active_templates();
		Database::set_active_templates( $post_id );
		Database::set_page_data( $post_id );

		// Set global $post for dynamic data calls that rely on get_the_ID().
		$current_post = get_post( $post_id );

		if ( $current_post ) {
			global $post;
			$post = $current_post;
			setup_postdata( $post );
		}

		return $post_id;
	}

	/**
	 * Confirm item changes within one AJAX request, including native cart redirects.
	 *
	 * The request ID is correlation data only; Woo still owns all mutation authorization.
	 * Comparing item keys and quantities excludes coupon, fee, shipping, and address changes.
	 * Session hydration runs first so cached markup and stock cleanup cannot announce a change.
	 *
	 * @since 2.4 #86cbf8r7t
	 * @return void
	 */
	public function track_cart_contents_request() {
		$request_id = isset( $_SERVER['HTTP_X_BRICKS_CART_REQUEST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BRICKS_CART_REQUEST'] ) ) : '';

		if ( ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/', $request_id ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$redirect_key = 'brx_cart_change_' . hash( 'sha256', WC()->session->get_customer_id() . ':' . $request_id );
		$baseline     = get_transient( $redirect_key );

		if ( $baseline !== false ) {
			delete_transient( $redirect_key );
		} else {
			$baseline = self::get_cart_contents_signature();
		}

		/**
		 * Recompute against the original baseline whenever Woo persists cart state.
		 * Replacing the header also clears an earlier confirmation if a change is reverted.
		 *
		 * @return void
		 */
		$send_confirmation = static function () use ( $baseline ) {
			if ( ! headers_sent() ) {
				header( 'X-Bricks-Cart-Contents-Changed: ' . ( $baseline !== self::get_cart_contents_signature() ? '1' : '0' ) );
			}
		};

		// AJAX endpoints report after cart persistence; redirected cart HTML reports on wp.
		add_action( 'woocommerce_cart_updated', $send_confirmation, PHP_INT_MAX );
		add_action( 'wp', $send_confirmation, PHP_INT_MAX );

		// XHR follows Woo's cart-form redirects. Carry only this request's baseline across them;
		// a short-lived transient avoids adding notification flags to Woo's shared cart session.
		// Its expiry only cleans up interrupted redirects; it is not a notification delay.
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $redirect_key, $baseline ) {
				set_transient( $redirect_key, $baseline, MINUTE_IN_SECONDS );
				return $location;
			}
		);
	}

	/**
	 * Get an order-independent signature of cart items, excluding totals and customer data.
	 *
	 * @since 2.4
	 * @return string Item identity and quantity signature.
	 */
	public static function get_cart_contents_signature() {
		$items = [];

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$items[ $key ] = (float) $item['quantity'];
		}

		ksort( $items );

		return hash( 'sha256', wp_json_encode( $items ) );
	}

	/**
	 * Retrieve different parts of the checkout page defined in v2 elements
	 *
	 * @since 2.4
	 */
	public function update_order_review_fragments( $fragments ) {
		if ( self::$checkout_cart_updated ) {
			/*
			 * A marked Checkout V2 request can change the cart before Woo builds this response.
			 * Merge Woo's regular cart fragments now so mini carts and extension-provided
			 * fragments receive the same final cart state without a second AJAX request.
			 */
			$fragments = array_merge( self::get_refreshed_fragments(), $fragments );

			/*
			 * update_order_review does not return Woo's cart hash at the response root. Replace
			 * the nameless frontend marker with the authoritative hash and removal status so the
			 * standard removed_from_cart event remains useful to Woo and third-party listeners.
			 */
			$fragments['[data-brx-woo-checkout-cart-hash]'] = sprintf(
				'<input type="hidden" data-brx-woo-checkout-cart-hash value="%1$s" data-cart-item-removed="%2$s">',
				esc_attr( WC()->cart->get_cart_hash() ),
				self::$checkout_cart_item_removed ? '1' : '0'
			);

			self::$checkout_cart_updated      = false;
			self::$checkout_cart_item_removed = false;
		}

		// Native Woo AJAX (update_order_review) has no reliable Bricks page context by default.
		self::maybe_set_wc_ajax_render_context( 'checkout' );

		$checkout_state_data = self::get_checkout_v2_state_render_data( 'checkout' );

		// Find element where _cssId = 'bricks-woo-checkout-order-summary' and render it as the order review fragment
		if ( is_array( $checkout_state_data ) && count( $checkout_state_data ) ) {
			$checkout_order_root      = null;
			$shipping_options_element = null;
			$payment_options_element  = null;
			foreach ( $checkout_state_data as $element ) {
				// Break early if both elements are found
				if ( $checkout_order_root && $shipping_options_element && $payment_options_element ) {
					break;
				}

				// Search for checkout order summary node (by element name, guaranteed by the wrapper element)
				if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-checkout-order-summary' && is_null( $checkout_order_root ) ) {
					$checkout_order_root = $element;
				}

				// Search for shipping options node
				if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-shipping-options' && is_null( $shipping_options_element ) ) {
					$shipping_options_element = $element;
				}

				// Search for payment options node
				if ( isset( $element['name'] ) && $element['name'] === 'woocommerce-payment-options' && is_null( $payment_options_element ) ) {
					$payment_options_element = $element;
				}
			}

			if ( $checkout_order_root ) {
				$checkout_order_summary_elements = self::extract_element_data_from_root( $checkout_order_root, $checkout_state_data );

				if ( $checkout_order_summary_elements ) {
					$fragments['#bricks-woo-checkout-order-summary'] = \Bricks\Frontend::render_data( $checkout_order_summary_elements );
				}
			}

			if ( $shipping_options_element ) {
				// Unset parent to make it renderable as a top-level root, and collect its descendants.
				$shipping_options_element['parent']              = 0;
				$fragments['.brxe-woocommerce-shipping-options'] = \Bricks\Frontend::render_data( [ $shipping_options_element ] );
			}

			if ( $payment_options_element ) {
				// Unset parent to make it renderable as a top-level root, and collect its descendants.
				$payment_options_element['parent']              = 0;
				$fragments['.brxe-woocommerce-payment-options'] = \Bricks\Frontend::render_data( [ $payment_options_element ] );
			}

		}

		return $fragments;
	}

	/**
	 * Add Archive Product content type
	 *
	 * Note: Not in use
	 *
	 * @param array $types
	 */
	public function add_content_types( $types ) {
		$types['archive-product'] = esc_html__( 'Archive (products)', 'bricks' );

		return $types;
	}

	/**
	 * Setup the products query loop in the products archive, including is_shop page (frontend only)
	 *
	 * @param array  $data Elements list.
	 * @param string $post_id Post ID.
	 */
	public function setup_query( $data, $post_id ) {
		$query_element = Woocommerce_Helpers::get_products_element( $data );

		// No query element to merge, proceed with regular WooCommerce loop
		if ( ! $query_element ) {
			wc_setup_loop();

			return;
		}

		// Force the post type to feed the Bricks Query class
		if ( empty( $query_element['settings']['query'] ) ) {
			$query_element['settings']['query'] = [
				'post_type'           => [ 'product' ],
				'ignore_sticky_posts' => 1
			];
		}

		// Set is_archive_main_query inside query key so the Query class understands (@since 2.2)
		if ( isset( $query_element['settings']['is_archive_main_query'] ) ) {
			$query_element['settings']['query']['is_archive_main_query'] = true;
		}

		// Use new woo_disable_query_merge to avoid complicated query merging issue (@since 2.2)
		if ( isset( $query_element['settings']['woo_disable_query_merge'] ) ) {
			$query_element['settings']['query']['woo_disable_query_merge'] = true;
		}

		// Query
		$query_object = new Query( $query_element );

		$query = $query_object->query_result;

		// Merged archive queries can use a separate WP_Query instance.
		$merge_with_global_query = Woocommerce_Helpers::is_archive_product() && apply_filters( 'bricks/posts/merge_query', true, $query_element['id'] );

		// Destroy query to explicitly remove it from the global store
		$query_object->destroy();

		// Remove ordering query arguments which may have been added by 'get_catalog_ordering_args'
		WC()->query->remove_ordering_args();

		$columns = isset( $query_element['settings']['columns'] ) ? $query_element['settings']['columns'] : 4;

		wc_setup_loop(
			[
				'columns'      => $columns,
				'name'         => 'bricks-products',
				'is_shortcode' => ! $merge_with_global_query,
				'is_search'    => false,
				'is_paginated' => true,
				'total'        => (int) $query->found_posts,
				'total_pages'  => (int) $query->max_num_pages,
				'per_page'     => (int) $query->get( 'posts_per_page' ),
				'current_page' => (int) max( 1, $query->get( 'paged', 1 ) ),
			]
		);
	}

	public function reset_query( $sections, $post_id ) {
		wc_reset_loop();
	}

	/**
	 * Update the mini-cart fragments
	 *
	 * @param array $fragments
	 */
	public function update_mini_cart( $fragments ) {
		if ( ! is_object( WC()->cart ) ) {
			return;
		}

		// Cart Count
		$count = WC()->cart->get_cart_contents_count();

		$fragments['span.cart-count'] = '<span class="cart-count ' . ( $count == 0 ? 'hide' : 'show' ) . '">' . $count . '</span>';

		// Cart Subtotal
		$subtotal = WC()->cart->get_cart_subtotal();

		if ( $subtotal ) {
			$fragments['span.cart-subtotal'] = '<span class="cart-subtotal">' . $subtotal . '</span>';
		}

		return $fragments;
	}

	/**
	 * Check if the query loop is on Woo products, and if yes, check if we should merge the main query
	 *
	 * @since 1.5
	 *
	 * @param boolean $merge
	 * @param string  $element_id
	 * @return boolean
	 */
	public function maybe_merge_query( $merge, $element_id ) {
		$query = Query::get_query_for_element_id( $element_id );

		// Shouldn't merge if 'disable_query_merge' or 'woo_disable_query_merge' is set (@since 2.3)
		if ( isset( $query->query_vars ) && is_array( $query->query_vars ) && $this->is_query_merge_disabled( $query->query_vars ) ) {
			return false;
		}

		if ( ! isset( $query->query_vars['post_type'] ) ) {
			return $merge;
		}

		if ( is_array( $query->query_vars['post_type'] ) && ! in_array( 'product', $query->query_vars['post_type'] ) ) {
			return $merge;
		}

		return Woocommerce_Helpers::is_archive_product();
	}

	/**
	 * Add products query vars to the query loop
	 *
	 * @since 1.5
	 *
	 * @param array  $query_vars
	 * @param array  $settings
	 * @param string $element_id
	 * @return boolean
	 */
	public function set_products_query_vars( $query_vars, $settings, $element_id, $element_name ) {
		if ( ! isset( $query_vars['post_type'] ) ) {
			return $query_vars;
		}

		// Convert post_type to array if it is a string (@since 1.9.6)
		if ( is_string( $query_vars['post_type'] ) ) {
			$query_vars['post_type'] = [ $query_vars['post_type'] ];
		}

		if ( is_array( $query_vars['post_type'] ) && ! in_array( 'product', $query_vars['post_type'] ) ) {
			return $query_vars;
		}

		/**
		 * Do not modify the query_vars if "FiboSearch - AJAX Search for WooCommerce" is active
		 *
		 * @since 1.9.1
		 */
		if ( is_search() && function_exists( 'dgoraAsfwFs' ) ) {
			return $query_vars;
		}

		/**
		 * Do not modify the query vars if 'disable_query_merge' is set
		 *
		 * @since 1.9.2
		 */
		// if ( isset( $query_vars['disable_query_merge'] ) ) {
		// return $query_vars;
		// }

		// Instead of early return, pass the flag to filters_query_args to populate correct query_vars (#86c4urb2r; @since 2.3)
		$disable_query_merge = $this->is_query_merge_disabled( $query_vars, $settings );

		$new_query_vars = $query_vars;

		$filter_args = Woocommerce_Helpers::filters_query_args(
			$settings,
			$query_vars,
			$element_name,
			[
				'skip_request_filters' => $disable_query_merge, // New flag to skip query mergin for request related logic (#86c4urb2r; @since 2.3)
			]
		);

		// Override the query settings by the filters (orderby, filters)
		foreach ( $filter_args as $key => $filter_value ) {
			// Preserve Bricks constraints for the dedicated merge below.
			// Request-filter inclusion is handled upstream and does not change this merge. (#86canmfa5; @since 2.4)
			if (
				in_array( $key, [ 'meta_query', 'tax_query' ], true ) &&
				! empty( $query_vars[ $key ] ) ) {
				continue;
			}

			$new_query_vars[ $key ] = $filter_value;
		}

		// STEP: Merge meta or/and tax query (if has conflicts)
		foreach ( [ 'meta_query', 'tax_query' ] as $type ) {
			if ( empty( $query_vars[ $type ] ) || empty( $filter_args[ $type ] ) ) {
				continue;
			}

			$relation_query_vars  = isset( $query_vars[ $type ]['relation'] ) ? $query_vars[ $type ]['relation'] : false;
			$relation_filter_args = isset( $filter_args[ $type ]['relation'] ) ? $filter_args[ $type ]['relation'] : false;

			// Both meta query sources have the relation key set and they are different
			if ( $relation_query_vars && $relation_filter_args && $relation_query_vars != $relation_filter_args ) {
				$new_query_vars[ $type ] = [
					'relation' => 'AND',
					0          => $query_vars[ $type ],
					1          => $filter_args[ $type ]
				];
			}

			// Relations are equal or not set
			else {
				$relation = $relation_query_vars ? $relation_query_vars : ( $relation_filter_args ? $relation_filter_args : false );

				unset( $query_vars[ $type ]['relation'] );
				unset( $filter_args[ $type ]['relation'] );

				$new_query_vars[ $type ] = array_merge( $query_vars[ $type ], $filter_args[ $type ] );

				if ( $relation ) {
					$new_query_vars[ $type ]['relation'] = $relation;
				}
			}
		}

		return $new_query_vars;
	}

	/**
	 * Determine if the query merge should be disabled based on query vars or settings
	 *
	 * @since 2.3
	 */
	public function is_query_merge_disabled( $query_vars = [], $settings = [] ) {
		return isset( $query_vars['woo_disable_query_merge'] ) || isset( $query_vars['disable_query_merge'] ) || isset( $settings['woo_disable_query_merge'] ) || isset( $settings['disable_query_merge'] );
	}

	/**
	 * Add WooCommerce query loop options to the builder.
	 *
	 * Only expose v2-specific object types when the beta is enabled. Saved loops
	 * using those types are still handled in run_woo_query().
	 *
	 * @param array $control_options
	 * @return array
	 */
	public function add_control_options( $control_options ) {
		$control_options['queryTypes']['wooCart'] = esc_html__( 'Cart contents', 'bricks' );

		if ( ! self::use_advanced_modular_elements() ) {
			return $control_options;
		}

		// Beta-only query types for Cart/Checkout/Account v2 generated layouts.
		$control_options['queryTypes']['wooCartCoupons']         = esc_html__( 'Checkout', 'bricks' ) . ' : ' . esc_html__( 'Applied coupons', 'bricks' );
		$control_options['queryTypes']['wooCartFees']            = esc_html__( 'Checkout', 'bricks' ) . ' : ' . esc_html__( 'Applied fees', 'bricks' );
		$control_options['queryTypes']['wooCartTaxes']           = esc_html__( 'Checkout', 'bricks' ) . ' : ' . esc_html__( 'Applied taxes', 'bricks' );
		$control_options['queryTypes']['wooOrderItems']          = esc_html_x( 'Order', 'WooCommerce order', 'bricks' ) . ' : ' . esc_html__( 'Order items', 'bricks' );
		$control_options['queryTypes']['wooOrderTotals']         = esc_html_x( 'Order', 'WooCommerce order', 'bricks' ) . ' : ' . esc_html__( 'Order totals', 'bricks' );
		$control_options['queryTypes']['wooOrderDownloads']      = esc_html_x( 'Order', 'WooCommerce order', 'bricks' ) . ' : ' . esc_html__( 'Order downloads', 'bricks' );
		$control_options['queryTypes']['wooOrderCustomerNotes']  = esc_html_x( 'Order', 'WooCommerce order', 'bricks' ) . ' : ' . esc_html__( 'Customer notes', 'bricks' );
		$control_options['queryTypes']['wooAccountOrders']       = esc_html__( 'Account', 'bricks' ) . ' : ' . esc_html__( 'Orders', 'bricks' );
		$control_options['queryTypes']['wooAccountOrderActions'] = esc_html__( 'Account', 'bricks' ) . ' : ' . esc_html__( 'Order actions', 'bricks' );
		$control_options['queryTypes']['wooAccountDownloads']    = esc_html__( 'Account', 'bricks' ) . ' : ' . esc_html__( 'Downloads', 'bricks' );
		$control_options['queryTypes']['wooAccountAddresses']    = esc_html__( 'Account', 'bricks' ) . ' : ' . esc_html__( 'Addresses', 'bricks' );

		return $control_options;
	}

	/**
	 * Return WooCommerce query loop results.
	 *
	 * Legacy cart contents stay available. V2-only query types are accepted but
	 * return no rows while the beta is disabled, which preserves saved settings
	 * without running account/order/checkout-specific logic.
	 *
	 * @param array $results
	 * @param Query $query
	 * @return array
	 */
	public function run_woo_query( $results, $query ) {
		$supported_queries = array_merge(
			[
				'wooCart',
			],
			self::get_advanced_modular_query_types()
		);

		if ( ! in_array( $query->object_type, $supported_queries, true ) ) {
			return $results;
		}

		// Saved beta query loops should stay in the data but produce no rows while disabled.
		if ( ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query->object_type ) ) {
			return [];
		}

		// Query elements can render in isolation, before any Cart v2 root initializes the preview. (#86cb6j2b0)
		if ( in_array( $query->object_type, [ 'wooCart', 'wooCartCoupons', 'wooCartFees', 'wooCartTaxes' ], true ) ) {
			Woocommerce_Helpers::maybe_prepare_builder_cart_preview();
		}

		switch ( $query->object_type ) {
			case 'wooCart':
				// Avoid Uncaught Error: Call to a member function get_cart() on null
				if ( is_null( WC()->cart ) ) {
					return [];
				}

				$cart_items       = WC()->cart->get_cart();
				$final_cart_items = [];

				// Support woocommerce_cart_item_visible hook (@since 2.0; @see woocommerce/templates/cart/cart.php)
				foreach ( $cart_items as $cart_item_key => $cart_item ) {
					$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
					$visible  = apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key );

					if ( $_product instanceof \WC_Product && $_product->exists() && $cart_item['quantity'] > 0 && $visible ) {
						$final_cart_items[ $cart_item_key ] = $cart_item;
					}
				}

				return $final_cart_items;

			case 'wooCartCoupons':
				if ( is_null( WC()->cart ) ) {
					return [];
				}

				return WC()->cart->get_coupons();

			case 'wooCartFees':
				if ( is_null( WC()->cart ) ) {
					return [];
				}

				return WC()->cart->get_fees();

			case 'wooCartTaxes':
				if ( is_null( WC()->cart ) ) {
					return [];
				}

				// No need to return taxes if prices are displayed including tax, as they are already included in the product price and not listed separately in the cart. @see woocommerce/templates/checkout/review-order.php
				if ( ! ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) ) {
					return [];
				}

				$results = [];

				if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
					foreach ( WC()->cart->get_tax_totals() as $code => $tax ) {
						$results[] = [
							'label'  => esc_html( $tax->label ),
							'amount' => wp_kses_post( $tax->formatted_amount ),
							'code'   => $code,
						];
					}
				} else {
					// Only return 1 row
					$results[] = [
						'label'  => esc_html( WC()->countries->tax_or_vat() ),
						'amount' => WC()->cart->get_taxes_total(),
					];
				}

				return $results;

			case 'wooOrderItems':
				// The child query has not entered render() yet, so is_any_looping() resolves the
				// active Account orders loop. get_parent_loop_id() would return false here.
				// (#86cavgmzk; @since 2.4)
				$parent_loop_id     = Query::is_any_looping();
				$parent_loop_object = $parent_loop_id && Query::get_query_object_type( $parent_loop_id ) === 'wooAccountOrders' ? Query::get_loop_object( $parent_loop_id ) : [];

				// Preserve the existing state-based context for standalone order item loops.
				$order = is_array( $parent_loop_object ) && isset( $parent_loop_object['order'] ) ? $parent_loop_object['order'] : self::get_contextual_order( 'thankyou' );

				if ( ! is_a( $order, 'WC_Order' ) ) {
					return [];
				}

				$order_items        = $order->get_items( apply_filters( 'woocommerce_purchase_order_item_types', 'line_item' ) );
				$visible_line_items = [];

				foreach ( $order_items as $item_id => $item ) {
					// Exclude invisible items (following the same logic as WooCommerce templates) (@see woocommerce/templates/order/order-details-item.php)
					if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
						continue;
					}

					$visible_line_items[ $item_id ] = [
						'item'  => $item,
						'order' => $order,
					];
				}

				return $visible_line_items;

			case 'wooOrderTotals':
				$order = self::get_contextual_order( 'thankyou' );

				if ( ! is_a( $order, 'WC_Order' ) ) {
					return [];
				}

				$item_totals   = $order->get_order_item_totals();
				$customer_note = $order->get_customer_note();
				$actions       = self::is_checkout_v2_pay_state_context( $order, $query ) ? [] : array_filter(
					wc_get_account_orders_actions( $order ),
					function ( $key ) {
						return 'view' !== $key;
					},
					ARRAY_FILTER_USE_KEY
				);

				$normalized_totals = [];

				if ( is_array( $item_totals ) && ! empty( $item_totals ) ) {
					foreach ( $item_totals as $key => $total ) {
						$normalized_totals[] = [
							'key'   => sanitize_key( $key ),
							'label' => $total['label'] ?? '',
							'value' => $total['value'] ?? '',
						];
					}
				}

				// Include order actions
				if ( is_array( $actions ) && ! empty( $actions ) ) {
					$wp_button_class = wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';
					foreach ( $actions as $key => $action ) {
						if ( empty( $action['aria-label'] ) ) {
							// Generate the aria-label based on the action name.
							/* translators: %1$s Action name, %2$s Order number. */
							$action_aria_label = sprintf( __( '%1$s order number %2$s', 'woocommerce' ), $action['name'], $order->get_order_number() );
						} else {
							$action_aria_label = $action['aria-label'];
						}

						$normalized_totals[] = [
							'key'    => 'action_' . sanitize_key( $key ),
							'label'  => '', // No label for actions, as the button text is self-explanatory. {woo_order_total_label} just output empty string for action rows.
							'value'  => '<a href="' . esc_url( $action['url'] ) . '" class="woocommerce-button' . esc_attr( $wp_button_class ) . ' button ' . sanitize_html_class( $key ) . ' order-actions-button " aria-label="' . esc_attr( $action_aria_label ) . '">' . esc_html( $action['name'] ) . '</a>',
							'action' => $action
						];

						unset( $action_aria_label );
					}
				}

				// Include order customer notes
				if ( $customer_note ) {
					$customer_note       = wc_wptexturize_order_note( $customer_note );
					$normalized_totals[] = [
						'key'   => 'brx_customer_note',
						'label' => esc_html__( 'Note:', 'woocommerce' ),
						'value' => wp_kses( nl2br( $customer_note ), [ 'br' => [] ] )
					];
				}

				return $normalized_totals;

			case 'wooOrderDownloads':
				$order = self::get_contextual_order( 'thankyou' );

				if ( ! is_a( $order, 'WC_Order' ) ) {
					return [];
				}

				$show_downloads = apply_filters( 'woocommerce_order_downloads_table_show_downloads', ( $order->has_downloadable_item() && $order->is_download_permitted() ), $order );

				if ( ! $show_downloads ) {
					return [];
				}

				$downloads = $order->get_downloadable_items();

				if ( ! is_array( $downloads ) || empty( $downloads ) ) {
					return [];
				}

				foreach ( $downloads as $key => $download ) {
					$downloads[ $key ]['order'] = $order;
				}

				return $downloads;

			case 'wooOrderCustomerNotes':
				$order = self::get_contextual_order( 'thankyou' );

				if ( ! is_a( $order, 'WC_Order' ) ) {
					return [];
				}

				return $order->get_customer_order_notes();

			case 'wooAccountOrders':
				$customer_orders = wc_get_orders( self::get_account_orders_query_args() );

				$total         = is_object( $customer_orders ) && isset( $customer_orders->total ) ? absint( $customer_orders->total ) : 0;
				$max_num_pages = is_object( $customer_orders ) && isset( $customer_orders->max_num_pages ) ? absint( $customer_orders->max_num_pages ) : 1;
				$orders        = is_object( $customer_orders ) && isset( $customer_orders->orders ) && is_array( $customer_orders->orders ) ? $customer_orders->orders : [];

				self::$account_orders_query_meta[ $query->element_id ] = [
					'total'         => $total,
					'max_num_pages' => max( 1, $max_num_pages ),
				];

				$normalized_orders = [];

				foreach ( $orders as $order_key => $customer_order ) {
					$order = is_a( $customer_order, 'WC_Order' ) ? $customer_order : wc_get_order( $customer_order );

					if ( is_a( $order, 'WC_Order' ) ) {
						$normalized_orders[ $order_key ] = [
							'order' => $order,
						];
					}
				}

				return $normalized_orders;

			// Only use inside wooAccountOrders loop context.
			case 'wooAccountOrderActions':
				$any_loop_id = Query::is_any_looping();

				if ( ! $any_loop_id ) {
					return [];
				}

				$parent_loop_object = Query::get_query_object_type( $any_loop_id ) === 'wooAccountOrders' ? Query::get_loop_object( $any_loop_id ) : [];
				$order              = is_array( $parent_loop_object ) && isset( $parent_loop_object['order'] ) ? $parent_loop_object['order'] : false;

				if ( ! is_a( $order, 'WC_Order' ) ) {
					return [];
				}

				$actions = wc_get_account_orders_actions( $order );

				if ( ! is_array( $actions ) || empty( $actions ) ) {
					return [];
				}

				$normalized_actions = [];

				foreach ( $actions as $key => $action ) {
					$normalized_actions[ $key ] = [
						'key'    => $key,
						'action' => $action,
						'order'  => $order,
					];
				}

				return $normalized_actions;

			case 'wooAccountDownloads':
				return self::get_account_downloads();

			case 'wooAccountAddresses':
				return self::get_account_addresses();

			default:
				return [];
		}

	}

	/**
	 * Set Woo query result count.
	 *
	 * @since 2.4
	 *
	 * @param int   $count Query result count.
	 * @param Query $query Query object.
	 * @return int
	 */
	public function set_woo_query_result_count( $count, $query ) {
		if ( $query instanceof Query && ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query->object_type ) ) {
			return 0;
		}

		if ( ! $query instanceof Query || $query->object_type !== 'wooAccountOrders' ) {
			return $count;
		}

		return isset( self::$account_orders_query_meta[ $query->element_id ]['total'] ) ? absint( self::$account_orders_query_meta[ $query->element_id ]['total'] ) : $count;
	}

	/**
	 * Set Woo query result max pages.
	 *
	 * @since 2.4
	 *
	 * @param int   $max_num_pages Query result max pages.
	 * @param Query $query Query object.
	 * @return int
	 */
	public function set_woo_query_result_max_num_pages( $max_num_pages, $query ) {
		if ( $query instanceof Query && ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query->object_type ) ) {
			return 1;
		}

		if ( ! $query instanceof Query || $query->object_type !== 'wooAccountOrders' ) {
			return $max_num_pages;
		}

		return isset( self::$account_orders_query_meta[ $query->element_id ]['max_num_pages'] ) ? max( 1, absint( self::$account_orders_query_meta[ $query->element_id ]['max_num_pages'] ) ) : $max_num_pages;
	}

	/**
	 * Sets the loop object (to WP_Post) in each query loop iteration
	 *
	 * @param array  $loop_object
	 * @param string $loop_key
	 * @param Query  $query
	 * @return array
	 */
	public function set_loop_object( $loop_object, $loop_key, $query ) {
		if ( $query instanceof Query && ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query->object_type ) ) {
			return $loop_object;
		}

		if ( $query->object_type !== 'wooCart' ) {
			return $loop_object;
		}

		// @see woocommerce/templates/cart/cart.php
		$_product   = apply_filters( 'woocommerce_cart_item_product', $loop_object['data'], $loop_object, $loop_key );
		$product_id = apply_filters( 'woocommerce_cart_item_product_id', $loop_object['product_id'], $loop_object, $loop_key );

		global $post;

		$post = get_post( $product_id );

		setup_postdata( $post );

		return $loop_object;
	}

	/**
	 * Returns the loop object id (for the cart query)
	 *
	 * @since 1.5.3
	 */
	public function set_loop_object_id( $object_id, $object, $query_id ) {
		$query_object_type = Query::get_query_object_type( $query_id );

		if ( ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query_object_type ) ) {
			return $object_id;
		}

		if ( $query_object_type === 'wooAccountOrders' || $query_object_type === 'wooAccountOrderActions' ) {
			$order = is_array( $object ) && isset( $object['order'] ) ? $object['order'] : false;

			return is_a( $order, 'WC_Order' ) ? $order->get_id() : $object_id;
		}

		if ( $query_object_type === 'wooAccountAddresses' ) {
			return is_array( $object ) && ! empty( $object['type'] ) ? $object['type'] : $object_id;
		}

		if ( $query_object_type !== 'wooCart' ) {
			return $object_id;
		}

		return get_the_ID();
	}

	/**
	 * Returns the loop object type (for the cart query)
	 *
	 * @since 1.5.3
	 */
	public function set_loop_object_type( $object_type, $object, $query_id ) {
		$query_object_type = Query::get_query_object_type( $query_id );

		if ( ! self::use_advanced_modular_elements() && self::is_advanced_modular_query_type( $query_object_type ) ) {
			return $object_type;
		}

		if ( $query_object_type === 'wooAccountOrders' || $query_object_type === 'wooAccountOrderActions' ) {
			return 'woo_order';
		}

		if ( $query_object_type === 'wooAccountAddresses' ) {
			return 'woo_account_address';
		}

		if ( $query_object_type !== 'wooCart' ) {
			return $object_type;
		}

		return 'post';
	}

	/**
	 * Check if user enabled single ajax add to cart
	 *
	 * @return bool
	 * @since 1.6.1
	 */
	public static function enabled_ajax_add_to_cart() {
		return Database::get_setting( 'woocommerceEnableAjaxAddToCart', false );
	}

	/**
	 * Get global AJAX show notice setting
	 *
	 * @return string
	 * @since 1.9
	 */
	public static function global_ajax_show_notice() {
		return Database::get_setting( 'woocommerceAjaxShowNotice', false ) ? 'yes' : 'no';
	}

	/**
	 * Get global AJAX scroll to notice setting
	 *
	 * @return string
	 * @since 1.9
	 */
	public static function global_ajax_scroll_to_notice() {
		return Database::get_setting( 'woocommerceAjaxScrollToNotice', false ) ? 'yes' : 'no';
	}

	/**
	 * Get global AJAX reset text after setting
	 *
	 * @return int
	 * @since 1.9
	 */
	public static function global_ajax_reset_text_after() {
		$reset_after = absint( Database::get_setting( 'woocommerceAjaxResetTextAfter', 3 ) );
		return max( $reset_after, 1 );
	}

	/**
	 * Get global AJAX adding text setting
	 *
	 * @return string
	 * @since 1.9.2
	 */
	public static function global_ajax_adding_text() {
		return Database::get_setting( 'woocommerceAjaxAddingText', esc_html__( 'Adding', 'bricks' ) );
	}

	/**
	 * Get global AJAX added text setting
	 *
	 * @return string
	 * @since 1.9.2
	 */
	public static function global_ajax_added_text() {
		return Database::get_setting( 'woocommerceAjaxAddedText', esc_html__( 'Added', 'bricks' ) );
	}

	/**
	 * Get global AJAX error action setting
	 *
	 * - Redirect to product page (default)
	 * - Show notice
	 *
	 * @return string
	 * @since 1.11
	 */
	public static function global_ajax_error_action() {
		return Database::get_setting( 'woocommerceAjaxErrorAction', 'redirect' );
	}

	/**
	 * Get global AJAX error scroll to notice setting
	 *
	 * @return string
	 * @since 1.11
	 */
	public static function global_ajax_error_scroll_to_notice() {
		return Database::get_setting( 'woocommerceAjaxErrorScrollToNotice', false );
	}

	/**
	 * Get nonce action for a Woo dynamic fragment target.
	 *
	 * @since 2.4
	 *
	 * @param int    $source_post_id Source post or template ID.
	 * @param string $source_area    Bricks data area: header, content, footer.
	 * @param string $element_id     Mounted DOM element ID.
	 * @param string $root_id        Stored root element ID.
	 * @return string
	 */
	public static function get_dynamic_fragment_nonce_action( $source_post_id, $source_area, $element_id, $root_id ) {
		return 'bricks_woo_dynamic_fragment_' .
			absint( $source_post_id ) . '_' .
			sanitize_key( $source_area ) . '_' .
			sanitize_key( $element_id ) . '_' .
			sanitize_key( $root_id );
	}

	/**
	 * Get a cache-stable signed token for a Woo dynamic fragment target.
	 *
	 * WordPress nonces expire while full-page caches can retain otherwise valid frontend markup.
	 * This target-bound signature remains valid until the site's salts change, allowing cached
	 * fragments to reconcile with the current cart without opening arbitrary render targets.
	 *
	 * @since 2.4
	 *
	 * @param int    $source_post_id Source post or template ID.
	 * @param string $source_area    Bricks data area: header, content, footer.
	 * @param string $element_id     Mounted DOM element ID.
	 * @param string $root_id        Stored root element ID.
	 * @return string
	 */
	public static function get_dynamic_fragment_token( $source_post_id, $source_area, $element_id, $root_id ) {
		return wp_hash(
			self::get_dynamic_fragment_nonce_action(
				$source_post_id,
				$source_area,
				$element_id,
				$root_id
			),
			'nonce'
		);
	}

	/**
	 * AJAX endpoint: Re-render mounted Bricks regions that opted into Woo cart refreshes.
	 *
	 * @since 2.4
	 */
	public function get_woo_dynamic_fragments() {
		$targets   = self::get_woo_dynamic_fragment_targets_from_request();
		$fragments = self::render_woo_dynamic_fragments( $targets );

		wp_send_json_success(
			[
				'fragments' => $fragments,
			]
		);
	}

	/**
	 * Include requested Bricks dynamic fragments in a native Woo cart response.
	 *
	 * @since 2.4
	 *
	 * @param array $fragments Native WooCommerce cart fragments.
	 * @return array
	 */
	public function add_woo_dynamic_fragments_to_response( $fragments ) {
		/*
		 * This filter also runs for unrelated Woo fragment responses. The explicit marker keeps
		 * it inert unless Bricks requested mounted dynamic regions, and each target still has
		 * its own signed token verified by get_woo_dynamic_fragment_targets_from_request().
		 */
		$refresh_dynamic_fragments = isset( $_POST['bricks_woo_refresh_dynamic_fragments'] ) && wc_string_to_bool( sanitize_text_field( wp_unslash( $_POST['bricks_woo_refresh_dynamic_fragments'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $refresh_dynamic_fragments || ! is_array( $fragments ) ) {
			return $fragments;
		}

		$targets = self::get_woo_dynamic_fragment_targets_from_request();

		if ( ! $targets ) {
			return $fragments;
		}

		return array_merge( $fragments, self::render_woo_dynamic_fragments( $targets ) );
	}

	/**
	 * Render mounted Bricks regions that depend on Woo cart dynamic data.
	 *
	 * @since 2.4
	 *
	 * @param array $targets Mounted fragment targets from the current page.
	 * @return array
	 */
	private static function render_woo_dynamic_fragments( $targets ) {
		if ( ! self::use_advanced_modular_elements() || empty( $targets ) ) {
			return [];
		}

		self::maybe_set_wc_ajax_render_context( '' );

		/**
		 * Filter the Woo dynamic fragment targets before render.
		 *
		 * @since 2.4
		 *
		 * @param array $targets Mounted fragment targets from the current page.
		 */
		$targets = apply_filters( 'bricks/woocommerce/dynamic_fragments/targets', $targets );

		if ( ! is_array( $targets ) ) {
			$targets = [];
		}

		$fragments = [];

		foreach ( $targets as $target ) {
			if ( ! self::is_valid_woo_dynamic_fragment_target( $target ) ) {
				continue;
			}

			$html = self::render_woo_dynamic_fragment_target( $target );

			if ( $html === false ) {
				continue;
			}

			$fragments[ self::get_woo_dynamic_fragment_selector( $target ) ] = $html;
		}

		/**
		 * Filter rendered Woo dynamic fragments before sending the AJAX response.
		 *
		 * @since 2.4
		 *
		 * @param array $fragments Selector-keyed fragments.
		 * @param array $targets   Mounted fragment targets from the current page.
		 */
		$fragments = apply_filters( 'bricks/woocommerce/dynamic_fragments/fragments', $fragments, $targets );

		return is_array( $fragments ) ? $fragments : [];
	}

	/**
	 * Parse mounted Woo dynamic fragment targets from the AJAX request.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_woo_dynamic_fragment_targets_from_request() {
		$raw_targets = isset( $_POST['fragments'] ) ? wp_unslash( $_POST['fragments'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( is_string( $raw_targets ) ) {
			$raw_targets = json_decode( $raw_targets, true );
		}

		if ( ! is_array( $raw_targets ) ) {
			return [];
		}

		$targets = [];

		foreach ( $raw_targets as $raw_target ) {
			if ( ! is_array( $raw_target ) ) {
				continue;
			}

			$element_id     = isset( $raw_target['id'] ) ? sanitize_key( $raw_target['id'] ) : '';
			$root_id        = isset( $raw_target['rootId'] ) ? sanitize_key( $raw_target['rootId'] ) : $element_id;
			$source_post_id = isset( $raw_target['source'] ) ? absint( $raw_target['source'] ) : 0;
			$source_area    = isset( $raw_target['area'] ) ? sanitize_key( $raw_target['area'] ) : 'content';
			$token          = isset( $raw_target['token'] ) ? sanitize_text_field( wp_unslash( $raw_target['token'] ) ) : '';

			if ( ! $element_id || ! $root_id || ! $source_post_id || ! in_array( $source_area, [ 'header', 'content', 'footer' ], true ) || ! $token ) {
				continue;
			}

			$target_key = "{$source_post_id}:{$source_area}:{$element_id}";

			$targets[ $target_key ] = [
				'id'      => $element_id,
				'root_id' => $root_id,
				'source'  => $source_post_id,
				'area'    => $source_area,
				'token'   => $token,
			];
		}

		return array_values( $targets );
	}

	/**
	 * Validate a Woo dynamic fragment target.
	 *
	 * @since 2.4
	 *
	 * @param array $target Fragment target.
	 * @return boolean
	 */
	private static function is_valid_woo_dynamic_fragment_target( $target ) {
		if ( ! is_array( $target ) ) {
			return false;
		}

		if ( empty( $target['id'] ) || empty( $target['root_id'] ) || empty( $target['source'] ) || empty( $target['area'] ) || empty( $target['token'] ) ) {
			return false;
		}

		$source_post = get_post( absint( $target['source'] ) );

		if ( ! $source_post || post_password_required( $source_post ) ) {
			return false;
		}

		// Cached signatures authenticate the target, not continued access to its current content.
		if ( ! is_post_publicly_viewable( $source_post ) ) {
			$capability = $source_post->post_status === 'private' ? 'read_post' : 'edit_post';

			if ( ! current_user_can( $capability, $source_post->ID ) ) {
				return false;
			}
		}

		if ( in_array( $source_post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return false;
		}

		return hash_equals(
			self::get_dynamic_fragment_token(
				$target['source'],
				$target['area'],
				$target['id'],
				$target['root_id']
			),
			$target['token']
		);
	}

	/**
	 * Build the selector used by the frontend fragment replacer.
	 *
	 * @since 2.4
	 *
	 * @param array $target Fragment target.
	 * @return string
	 */
	private static function get_woo_dynamic_fragment_selector( $target ) {
		return sprintf(
			'[data-brx-woo-fragment="true"][data-brx-woo-fragment-id="%1$s"][data-brx-woo-fragment-source="%2$d"][data-brx-woo-fragment-area="%3$s"]',
			esc_attr( $target['id'] ),
			absint( $target['source'] ),
			esc_attr( $target['area'] )
		);
	}

	/**
	 * Render one Woo dynamic fragment target.
	 *
	 * @since 2.4
	 *
	 * @param array $target Fragment target.
	 * @return string|false
	 */
	private static function render_woo_dynamic_fragment_target( $target ) {
		$source_elements = Database::get_data( absint( $target['source'] ), $target['area'] );

		if ( empty( $source_elements ) || ! is_array( $source_elements ) ) {
			return false;
		}

		$root_element = false;

		foreach ( $source_elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $target['root_id'] ) {
				$root_element = $element;
				break;
			}
		}

		if ( ! $root_element ) {
			return false;
		}

		if ( ( $root_element['name'] ?? '' ) !== 'woocommerce-dynamic-fragment' ) {
			return false;
		}

		$render_data = self::extract_element_data_from_root( $root_element, $source_elements );

		if ( ! $render_data ) {
			return false;
		}

		$html = Frontend::render_data( $render_data, $target['area'], $target['source'] );

		/**
		 * Filter one rendered Woo dynamic fragment.
		 *
		 * @since 2.4
		 *
		 * @param string $html        Rendered fragment HTML.
		 * @param array  $target      Fragment target.
		 * @param array  $render_data Bricks render data used for the fragment.
		 */
		return apply_filters( 'bricks/woocommerce/dynamic_fragments/html', $html, $target, $render_data );
	}

	/**
	 * AJAX Add to cart
	 * Support product types: simple, variable, grouped
	 *
	 * @since 1.6.1
	 *
	 * @see woocommerce/includes/class-wc-ajax.php add_to_cart()
	 */
	public function add_to_cart() {
		ob_start();

		$product_id = isset( $_POST['product_id'] ) ? apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_POST['product_id'] ) ) : 0;

		if ( ! $product_id ) {
			return;
		}

		$product_status = get_post_status( $product_id );
		$product_type   = isset( $_POST['product_type'] ) ? sanitize_title( wp_unslash( $_POST['product_type'] ) ) : 'simple';
		$quantity       = isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1;
		$variation_id   = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$variation      = isset( $_POST['variation'] ) ? (array) $_POST['variation'] : [];
		$products       = isset( $_POST['products'] ) ? (array) $_POST['products'] : [];

		switch ( $product_type ) {
			case 'grouped':
				// No products added
				if ( count( $products ) < 1 ) {
					return;
				}

				$passed = [];
				foreach ( $products as $id => $quantity ) {
					if ( $quantity > 0 ) {
						$each_passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $id, $quantity );
						if ( $each_passed_validation && false !== WC()->cart->add_to_cart( $id, $quantity ) && 'publish' === $product_status ) {
							do_action( 'woocommerce_ajax_added_to_cart', $id );
							$passed[ $id ] = $quantity;
						}
					}
				}

				// Overall passed validation for grouped products
				$passed_validation = count( $passed ) === count( $products );

				// When using Bricks AJAX add to cart, we always generate the notices
				if ( $passed_validation ) {
					foreach ( $passed as $id => $quantity ) {
						wc_add_to_cart_message( [ $id => $quantity ], true );
					}
				}
				break;

			default:
			case 'variable':
			case 'simple':
				$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation );

				if ( $passed_validation && false !== WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation ) && 'publish' === $product_status ) {
					do_action( 'woocommerce_ajax_added_to_cart', $product_id );

					// When using Bricks AJAX add to cart, we always generate the notices
					wc_add_to_cart_message( [ $product_id => $quantity ], true );
				} else {
					$passed_validation = false;
				}
				break;
		}

		// Return error
		if ( ! $passed_validation ) {
			// If there was an error adding to the cart
			$data = [
				'error' => true,
			];

			if ( self::global_ajax_error_action() === 'redirect' ) {
				// Redirect to the product page (Default WooCommerce behavior)
				$data['product_url'] = apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $product_id ), $product_id );
			} else {
				// Print and return error message (@since 1.11)
				$data['notices'] = wc_print_notices( true );
			}

			// Send error json
			wp_send_json( $data );
		}

		/**
		 * No error, return fragments and cart hash (default)
		 * Notices only print if we are not redirecting to the cart page
		 */
		$response = [
			'fragments' => self::get_refreshed_fragments(),
			'cart_hash' => WC()->cart->get_cart_hash(),
			'notices'   => get_option( 'woocommerce_cart_redirect_after_add' ) !== 'yes' ? wc_print_notices( true ) : '',
		];

		wp_send_json( $response );
	}

	/**
	 * Update Checkout V2 cart items before WooCommerce recalculates shipping and totals.
	 *
	 * The checkout request already contains the Cart quantity element inputs in its serialized
	 * post data. Processing quantities and explicit removals here keeps cart contents, shipping,
	 * fees, payment availability, and returned fragments in one native update_order_review request.
	 *
	 * The two Bricks fields used below are intentionally absent from Checkout V1. They are added
	 * by the Checkout V2 script only for a user-initiated cart change, which prevents ordinary
	 * Woo or third-party update_order_review requests from mutating cart quantities.
	 *
	 * @since 2.4
	 *
	 * @param string $post_data Serialized checkout form data.
	 *
	 * @return void
	 */
	public function update_checkout_cart_items( $post_data ) {
		if ( ! self::use_advanced_modular_elements() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() || ! is_string( $post_data ) ) {
			return;
		}

		$checkout_data = [];
		wp_parse_str( $post_data, $checkout_data );

		if ( ( $checkout_data['bricks_update_checkout_cart_quantities'] ?? '' ) !== '1' ) {
			return;
		}

		$cart_nonce = $checkout_data['woocommerce-cart-nonce'] ?? '';

		// The checkout endpoint verifies its own nonce, but this marker enables a cart mutation.
		if ( ! is_string( $cart_nonce ) || ! wp_verify_nonce( sanitize_text_field( $cart_nonce ), 'woocommerce-cart' ) ) {
			return;
		}

		$cart_totals = $checkout_data['cart'] ?? [];

		if ( ! is_array( $cart_totals ) ) {
			return;
		}

		$cart_updated = false;
		$remove_key   = isset( $checkout_data['bricks_remove_checkout_cart_item'] ) && is_string( $checkout_data['bricks_remove_checkout_cart_item'] )
			? sanitize_text_field( $checkout_data['bricks_remove_checkout_cart_item'] )
			: '';

		if ( $remove_key !== '' && WC()->cart->get_cart_item( $remove_key ) && WC()->cart->remove_cart_item( $remove_key ) ) {
			/*
			 * Match WC_AJAX::remove_from_cart instead of treating removal as a quantity update.
			 * Extensions listening for a user-requested removal therefore receive the same
			 * server-side lifecycle hook they receive from Woo's native mini-cart endpoint.
			 */
			do_action( 'internal_woocommerce_cart_item_removed_from_user_request', $remove_key, WC()->cart );

			$cart_updated = true;

			self::$checkout_cart_updated      = true;
			self::$checkout_cart_item_removed = true;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			// Never fall back to quantity-zero semantics for an explicitly requested removal.
			if ( $cart_item_key === $remove_key ) {
				continue;
			}

			if ( ! isset( $cart_totals[ $cart_item_key ]['qty'] ) ) {
				continue;
			}

			$quantity = $this->parse_cart_item_quantity( $cart_totals[ $cart_item_key ]['qty'], $cart_item_key );

			if ( $quantity === null ) {
				continue;
			}

			if ( $this->apply_cart_item_quantity( $cart_item_key, $cart_item, $quantity ) === true ) {
				$cart_updated                = true;
				self::$checkout_cart_updated = true;
			}
		}

		$cart_contents_changed = $cart_updated;

		// Match the native cart form lifecycle once for the combined removal and quantity update.
		$cart_updated = apply_filters( 'woocommerce_update_cart_action_cart_updated', $cart_updated );

		/*
		 * Some extensions update other cart data inside the native filter and signal that by
		 * returning true. Include their fragments in this response even when Bricks did not
		 * directly change an item's quantity or removal state.
		 */
		if ( $cart_updated ) {
			$cart_contents_changed = true;

			self::$checkout_cart_updated = true;
		}

		// Woo checks for an empty cart before this hook, so request a reload if the update removed the final item.
		if ( $cart_contents_changed && WC()->cart->is_empty() && WC()->session ) {
			WC()->session->set( 'reload_checkout', true );
		}
	}

	/**
	 * Parse a posted cart item quantity without treating empty or malformed input as zero.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $raw_quantity Posted quantity value.
	 * @param string $cart_item_key WooCommerce cart item key.
	 *
	 * @return int|float|string|null Parsed quantity, or null when the input should not be applied.
	 */
	private function parse_cart_item_quantity( $raw_quantity, $cart_item_key ) {
		if ( ! is_scalar( $raw_quantity ) ) {
			return null;
		}

		$raw_quantity       = trim( (string) $raw_quantity );
		$sanitized_quantity = preg_replace( '/[^0-9\.]/', '', $raw_quantity );

		// Zero is a valid removal request; only empty or malformed values are ignored.
		if ( $raw_quantity === '' || $sanitized_quantity === '' || ! is_numeric( $sanitized_quantity ) ) {
			return null;
		}

		$quantity = apply_filters(
			'woocommerce_stock_amount_cart_item',
			wc_stock_amount( $sanitized_quantity ),
			$cart_item_key
		);

		return is_numeric( $quantity ) ? $quantity : null;
	}

	/**
	 * Validate and apply one cart item quantity using WooCommerce's cart update lifecycle.
	 *
	 * @since 2.4
	 *
	 * @param string           $cart_item_key WooCommerce cart item key.
	 * @param array            $cart_item     WooCommerce cart item data.
	 * @param int|float|string $quantity      Parsed quantity to apply.
	 *
	 * @return bool|null True when updated, false when validation failed, or null when unchanged.
	 */
	private function apply_cart_item_quantity( $cart_item_key, $cart_item, $quantity ) {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		$max_quantity = $product->get_max_purchase_quantity();

		/*
		 * AJAX updates bypass the browser's input constraints. Normalize to Woo's filtered
		 * maximum before the validation filter so extensions inspect the exact quantity that
		 * will be persisted, while retaining their ability to reject that quantity.
		 */
		if ( $max_quantity > 0 && $quantity > $max_quantity ) {
			$quantity = $max_quantity;
		}

		$old_quantity = $cart_item['quantity'];

		if ( $quantity === $old_quantity ) {
			return null;
		}

		$passed_validation = apply_filters( 'woocommerce_update_cart_validation', true, $cart_item_key, $cart_item, $quantity );

		if ( $product->is_sold_individually() && $quantity > 1 ) {
			wc_add_notice(
				sprintf(
					/* translators: %s Product title. */
					__( 'You can only have 1 %s in your cart.', 'woocommerce' ),
					$product->get_name()
				),
				'error'
			);

			$passed_validation = false;
		}

		if ( ! $passed_validation ) {
			return false;
		}

		WC()->cart->set_quantity( $cart_item_key, $quantity, false );

		do_action( 'internal_woocommerce_cart_item_updated_from_user_request', $cart_item_key, $quantity, $old_quantity, WC()->cart );

		return true;
	}

	/**
	 * Update a cart item quantity via Woo AJAX.
	 *
	 * Used by cart quantity controls rendered outside the native cart form, such as
	 * checkout order summary cart loops.
	 *
	 * @since 2.4
	 */
	public function update_cart_item_quantity() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error(
				[
					'notices' => esc_html__( 'Cart is not available.', 'bricks' ),
				]
			);
		}

		if ( ! check_ajax_referer( 'woocommerce-cart', 'security', false ) ) {
			wp_send_json_error(
				[
					'notices' => esc_html__( 'Invalid cart request.', 'bricks' ),
				]
			);
		}

		$cart_item_key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
		$raw_quantity  = isset( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$quantity      = $this->parse_cart_item_quantity( $raw_quantity, $cart_item_key );
		$cart_item     = $cart_item_key ? WC()->cart->get_cart_item( $cart_item_key ) : false;

		if ( ! $cart_item ) {
			wp_send_json_error(
				[
					'notices' => esc_html__( 'Cart item not found.', 'bricks' ),
				]
			);
		}

		if ( $quantity === null ) {
			wp_send_json_error(
				[
					'notices' => esc_html__( 'Invalid cart request.', 'bricks' ),
				]
			);
		}

		$cart_update_result = $this->apply_cart_item_quantity( $cart_item_key, $cart_item, $quantity );

		if ( $cart_update_result === false ) {
			wp_send_json_error(
				[
					'notices'  => wc_print_notices( true ),
					'quantity' => $cart_item['quantity'],
				]
			);
		}

		$cart_updated = $cart_update_result === true;
		$cart_updated = apply_filters( 'woocommerce_update_cart_action_cart_updated', $cart_updated );

		if ( $cart_updated ) {
			WC()->cart->calculate_totals();
		}

		$notices           = wc_print_notices( true );
		$refresh_fragments = isset( $_POST['refresh_fragments'] ) && wc_string_to_bool( sanitize_text_field( wp_unslash( $_POST['refresh_fragments'] ) ) );
		$fragments         = [];
		$dynamic_fragments = [];
		$updated_cart_item = WC()->cart->get_cart_item( $cart_item_key );
		$applied_quantity  = $updated_cart_item ? $updated_cart_item['quantity'] : 0;

		if ( $refresh_fragments ) {
			$fragments         = self::get_refreshed_fragments();
			$dynamic_fragments = self::render_woo_dynamic_fragments( self::get_woo_dynamic_fragment_targets_from_request() );
		}

		wp_send_json_success(
			[
				'cart_hash'         => WC()->cart->get_cart_hash(),
				'is_empty'          => WC()->cart->is_empty(),
				'quantity'          => $applied_quantity,
				'notices'           => $notices,
				'fragments'         => $fragments,
				'dynamic_fragments' => $dynamic_fragments,
			]
		);
	}

	/**
	 * Same as WC_AJAX::get_refreshed_fragments() but without the cart_hash and cart_url fragments
	 *
	 * @since 1.8.4
	 */
	public static function get_refreshed_fragments() {
		ob_start();

		woocommerce_mini_cart();

		$mini_cart = ob_get_clean();

		return apply_filters(
			'woocommerce_add_to_cart_fragments',
			[
				'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
			]
		);
	}

	/**
	 * Remove .ajax_add_to_cart if get_option( 'woocommerce_enable_ajax_add_to_cart' ) is not checked.
	 * To avoid native AJAX add to cart firing because product-add-to-cart element needs to enqueue wc-add-to-cart.js for AJAX Woo Quick View
	 * #86c993p6a
	 *
	 * @since 2.3.3
	 */
	public function maybe_remove_native_ajax_class( $args, $product ) {
		// Only change loop/archive add to cart buttons when "Enable AJAX add to cart buttons on archives" is disabled.
		if ( get_option( 'woocommerce_enable_ajax_add_to_cart' ) === 'yes' ) {
			return $args;
		}

		if ( empty( $args['class'] ) ) {
			return $args;
		}

		$classes = is_array( $args['class'] )
			? $args['class']
			: preg_split( '/\s+/', (string) $args['class'], -1, PREG_SPLIT_NO_EMPTY );

		$classes = array_values(
			array_filter(
				$classes,
				static fn( $class ) => $class !== 'ajax_add_to_cart'
			)
		);

		$args['class'] = implode( ' ', $classes );

		return $args;
	}

	/**
	 * Take over the native WooCommerce AJAX add to cart button
	 *
	 * @since 1.8.5
	 */
	public function overwrite_native_ajax_add_to_cart( $args, $product ) {
		/**
		 * Must be purchasable, in stock, supports ajax_add_to_cart (#86c1rp5u8)
		 *
		 * @since 1.10: Support variation product: which is using direct link for add to cart button inside loop
		 */
		if (
			! $product->is_purchasable() ||
			! $product->is_in_stock() ||
			! $product->supports( 'ajax_add_to_cart' ) || // @since 1.12
			( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) )
		) {
			return $args;
		}

		// Disable native ajax_add_to_cart class if enabled in WooCommerce settings
		$args['class'] = str_replace( 'ajax_add_to_cart', '', $args['class'] );

		// Add brx_ajax_add_to_cart class
		$args['class'] .= ' brx_ajax_add_to_cart';

		// Add product type attribute
		$args['attributes']['data-product_type'] = $product->get_type();

		return $args;
	}

	/**
	 * Check if use bricks woo notice element
	 *
	 * @since 1.8.1
	 * @return bool
	 */
	public static function use_bricks_woo_notice_element() {
		return Database::get_setting( 'woocommerceUseBricksWooNotice', false );
	}

	/**
	 * Prepare the password reset notice before the first Bricks Notice element renders.
	 *
	 * WooCommerce normally adds this notice while rendering the My Account shortcode. A Notice
	 * element placed before the Account element would otherwise render too early to display it.
	 *
	 * @since 2.3.12 #86cb6uj1x
	 * @return void
	 */
	public static function maybe_prepare_password_reset_notice() {
		// Invalid reset credentials can queue errors as well as success notices.
		// Prepare before rendering notices even when the Bricks Notice setting is off.
		Woocommerce_Password_Reset::maybe_prepare();

		if (
			self::$prepared_password_reset_notice ||
			! self::use_bricks_woo_notice_element() ||
			! function_exists( 'is_account_page' ) ||
			! is_account_page() ||
			empty( $_GET['password-reset'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			! function_exists( 'wc_add_notice' ) ||
			! function_exists( 'wc_has_notice' ) ||
			! WC()->session
		) {
			return;
		}

		$message = __( 'Your password has been reset successfully.', 'woocommerce' );

		if ( ! wc_has_notice( $message ) ) {
			wc_add_notice( $message );
		}

		self::$prepared_password_reset_notice = $message;

		// WooCommerce queues the notice before account content callbacks, so only an earlier Notice element needs this guard.
		if ( ! did_action( 'woocommerce_account_content' ) ) {
			add_filter( 'woocommerce_add_message', [ __CLASS__, 'maybe_suppress_prepared_password_reset_notice' ], 1 );
		}
	}

	/**
	 * Suppress WooCommerce's later copy of a password reset notice prepared by Bricks.
	 *
	 * The query argument remains in the URL to preserve native WooCommerce behaviour. Without
	 * this guard, the shortcode would queue the same notice again after Bricks already printed it.
	 *
	 * @since 2.3.12 #86cb6uj1x
	 *
	 * @param string $message Success notice message.
	 * @return string
	 */
	public static function maybe_suppress_prepared_password_reset_notice( $message ) {
		if ( self::$prepared_password_reset_notice && $message === self::$prepared_password_reset_notice ) {
			remove_filter( 'woocommerce_add_message', [ __CLASS__, __FUNCTION__ ], 1 );

			// Keep the request marker so a later Notice element does not prepare the message again.
			return '';
		}

		return $message;
	}

	/**
	 * Remove all native woocommerce notices hooks if use Bricks woo notice element
	 *
	 * So user can control the location of notices via the Bricks woo notice element.
	 *
	 * @since 1.8.1
	 * @since 1.11.1: Included logic for Woo Checkout Coupon & Login Element
	 * @see woocommerce/includes/wc-template-hooks.php Notices
	 */
	public static function maybe_remove_native_woocommerce_notices_hooks() {
		// Woo Notice Element is active
		if ( self::use_bricks_woo_notice_element() ) {
			// cart-empty.php
			remove_action( 'woocommerce_cart_is_empty', 'woocommerce_output_all_notices', 5 );

			remove_action( 'woocommerce_shortcode_before_product_cat_loop', 'woocommerce_output_all_notices', 10 );

			// archive-product.php
			remove_action( 'woocommerce_before_shop_loop', 'woocommerce_output_all_notices', 10 );
			remove_action( 'woocommerce_before_single_product', 'woocommerce_output_all_notices', 10 );

			// cart.php
			remove_action( 'woocommerce_before_cart', 'woocommerce_output_all_notices', 10 );

			// This hook is fired when using the [woocommerce_checkout] shortcode
			remove_action( 'woocommerce_before_checkout_form_cart_notices', 'woocommerce_output_all_notices', 10 );

			// Capture the notices before checkout form cart validation and print them in the Bricks Woo Notice element to avoid empty notices after cart validation (#86c2ftbvk; @since 2.3.5)
			add_action( 'woocommerce_before_checkout_form_cart_notices', [ __CLASS__, 'capture_checkout_notices' ], 10 );

			// form-checkout.php
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_output_all_notices', 10 );

			// inside shortcode [woocommerce_checkout] order_pay
			remove_action( 'before_woocommerce_pay', 'woocommerce_output_all_notices', 10 );

			// my-account.php
			remove_action( 'woocommerce_account_content', 'woocommerce_output_all_notices', 5 );

			// myaccount/form-login.php
			remove_action( 'woocommerce_before_customer_login_form', 'woocommerce_output_all_notices', 10 );

			// myaccount/form-lost-password.php
			remove_action( 'woocommerce_before_lost_password_form', 'woocommerce_output_all_notices', 10 );

			// myaccount/form-reset-password.php
			remove_action( 'woocommerce_before_reset_password_form', 'woocommerce_output_all_notices', 10 );
		}

		// Woo Checkout Coupon Element is active
		if ( self::use_bricks_woo_checkout_coupon_element() ) {
			// form-checkout.php
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}

		// Woo Checkout Login Element is active
		if ( self::use_bricks_woo_checkout_login_element() ) {
			// form-checkout.php
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
		}
	}

	/**
	 * Resolve WooCommerce arguments for dynamic data {do_action} tags.
	 *
	 * @since 2.4
	 *
	 * @param array    $action_context Action context.
	 * @param array    $filters        Dynamic data filters.
	 * @param string   $context        Dynamic data context.
	 * @param \WP_Post $post           Current post.
	 * @return array
	 */
	public function resolve_woo_do_action_context( $action_context, $filters, $context, $post ) {
		if ( ! is_array( $action_context ) || empty( $action_context['requested_action'] ) ) {
			return $action_context;
		}

		$requested_action = sanitize_text_field( $action_context['requested_action'] );

		$unsupported_actions = [
			'woocommerce_order_item_meta_start',
			'woocommerce_order_item_meta_end',
		];

		// Unsupported Woo hooks: Use {woo_order_item_meta} and Bricks download tags instead.
		if ( in_array( $requested_action, $unsupported_actions, true ) || strpos( $requested_action, 'woocommerce_account_downloads_column_' ) === 0 ) {
			$action_context['skip'] = true;
			return $action_context;
		}

		// Match the checkout argument passed by Woo's form-checkout, form-billing, and form-shipping templates.
		$checkout_actions = [
			'woocommerce_before_checkout_form',
			'woocommerce_after_checkout_form',
			'woocommerce_before_checkout_billing_form',
			'woocommerce_after_checkout_billing_form',
			'woocommerce_before_checkout_shipping_form',
			'woocommerce_after_checkout_shipping_form',
			'woocommerce_before_checkout_registration_form',
			'woocommerce_after_checkout_registration_form',
			'woocommerce_before_order_notes',
			'woocommerce_after_order_notes',
		];

		if ( in_array( $requested_action, $checkout_actions, true ) ) {
			if ( ! empty( $action_context['skip'] ) ) {
				return $action_context;
			}

			$woocommerce = function_exists( 'WC' ) ? WC() : null;
			$checkout    = is_object( $woocommerce ) && is_callable( [ $woocommerce, 'checkout' ] ) ? $woocommerce->checkout() : null;

			// Avoid invoking callbacks that require WC_Checkout when its context is unavailable.
			if ( ! is_a( $checkout, 'WC_Checkout' ) ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['args'] = [ $checkout ];
			return $action_context;
		}

		if ( in_array( $requested_action, [ 'woocommerce_before_account_orders', 'woocommerce_after_account_orders' ], true ) ) {
			$customer_orders = wc_get_orders( self::get_account_orders_query_args() );
			$has_orders      = is_object( $customer_orders ) && isset( $customer_orders->total ) ? $customer_orders->total > 0 : false;

			$action_context['args'] = [ $has_orders ];
			return $action_context;
		}

		if ( in_array( $requested_action, [ 'woocommerce_before_account_downloads', 'woocommerce_after_account_downloads' ], true ) ) {
			$action_context['args'] = [ self::has_account_downloads() ];
			return $action_context;
		}

		if ( $requested_action === 'woocommerce_available_downloads' ) {
			$action_context['args'] = [ self::get_account_downloads() ];
			return $action_context;
		}

		if ( in_array( $requested_action, [ 'woocommerce_before_edit_address_form', 'woocommerce_after_edit_address_form' ], true ) ) {
			// Woo exposes address-specific hooks for the native edit-address template.
			$address_type             = self::get_account_edit_address_type();
			$action_context['action'] = $requested_action . '_' . $address_type;
			return $action_context;
		}

		$active_loop_id = Query::is_any_looping();

		if ( $requested_action === 'woocommerce_my_account_after_my_address' ) {
			$loop_object  = $active_loop_id && Query::get_query_object_type( $active_loop_id ) === 'wooAccountAddresses' ? Query::get_loop_object( $active_loop_id ) : [];
			$address_type = is_array( $loop_object ) && ! empty( $loop_object['type'] ) ? sanitize_key( $loop_object['type'] ) : '';

			if ( ! $address_type ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['args'] = [ $address_type ];
			return $action_context;
		}

		if ( strpos( $requested_action, 'woocommerce_my_account_my_orders_column_' ) === 0 && $active_loop_id && Query::get_query_object_type( $active_loop_id ) === 'wooAccountOrders' ) {
			$loop_object = Query::get_loop_object( $active_loop_id );
			$order       = is_array( $loop_object ) && isset( $loop_object['order'] ) ? $loop_object['order'] : false;

			if ( ! is_a( $order, 'WC_Order' ) ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['args'] = [ $order ];
			return $action_context;
		}

		$order_id_actions = [
			'woocommerce_before_thankyou',
			'woocommerce_thankyou',
		];

		// Bricks-only aliases: These hooks do not exist in WooCommerce.
		// Resolve to payment method-specific Woo hooks with the order ID argument.
		$payment_method_order_id_actions = [
			'woocommerce_thankyou_payment_method' => 'woocommerce_thankyou',
			'woocommerce_receipt_payment_method'  => 'woocommerce_receipt',
		];

		$order_actions = [
			'woocommerce_order_details_before_order_table',
			'woocommerce_order_details_before_order_table_items',
			'woocommerce_order_details_after_order_table_items',
			'woocommerce_order_details_after_order_table',
			'woocommerce_after_order_details',
			'woocommerce_order_details_after_customer_details',
		];

		// Bricks-only aliases: These hooks do not exist in WooCommerce.
		// Resolve to 'woocommerce_order_details_after_customer_address' with the address type argument.
		$customer_address_actions = [
			'woocommerce_order_details_after_customer_billing_address'  => 'billing',
			'woocommerce_order_details_after_customer_shipping_address' => 'shipping',
		];

		$needs_order = in_array( $requested_action, $order_id_actions, true ) || isset( $payment_method_order_id_actions[ $requested_action ] ) || in_array( $requested_action, $order_actions, true ) || isset( $customer_address_actions[ $requested_action ] );
		$order       = $needs_order ? self::get_contextual_order( 'thankyou' ) : false;
		$order_id    = is_a( $order, 'WC_Order' ) ? $order->get_id() : 0;

		if ( in_array( $requested_action, $order_id_actions, true ) ) {
			if ( ! $order_id ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['args'] = [ $order_id ];
			return $action_context;
		}

		if ( isset( $payment_method_order_id_actions[ $requested_action ] ) ) {
			if ( ! is_a( $order, 'WC_Order' ) || ! $order->get_payment_method() ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['action'] = $payment_method_order_id_actions[ $requested_action ] . '_' . sanitize_key( $order->get_payment_method() );
			$action_context['args']   = [ $order_id ];
			return $action_context;
		}

		if ( in_array( $requested_action, $order_actions, true ) ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['args'] = [ $order ];
			return $action_context;
		}

		if ( isset( $customer_address_actions[ $requested_action ] ) ) {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				$action_context['skip'] = true;
				return $action_context;
			}

			$action_context['action'] = 'woocommerce_order_details_after_customer_address';
			$action_context['args']   = [ $customer_address_actions[ $requested_action ], $order ];
			return $action_context;
		}

		return $action_context;
	}

	/**
	 * Capture notices shown before checkout cart validation.
	 *
	 * WooCommerce uses this hook to print and clear existing notices before it checks
	 * the cart for checkout-blocking errors.
	 *
	 * @since 2.3.5
	 */
	public static function capture_checkout_notices() {
		if (
			! bricks_is_frontend() ||
			bricks_is_builder_call() ||
			! is_checkout() ||
			! function_exists( 'wc_print_notices' )
		) {
			return;
		}

		$notices = wc_print_notices( true );

		if ( $notices ) {
			self::$checkout_notices .= $notices;
		}
	}

	/**
	 * Get buffered checkout notices and clear the buffer.
	 *
	 * @since 2.3.5
	 *
	 * @return string
	 */
	public static function get_checkout_notices() {
		$notices                = self::$checkout_notices;
		self::$checkout_notices = '';

		return $notices;
	}

	/**
	 * Remove WooCommerce hook actions to avoid duplicate content
	 *
	 * @since 1.7
	 *
	 * @param string   $action
	 * @param array    $filters
	 * @param string   $context
	 * @param \WP_Post $post
	 */
	public function maybe_remove_woo_hook_actions( $action, $filters, $context, $post ) {
		$template = Woocommerce_Helpers::get_repeated_wc_template_hooks_by_action( $action );

		// STEP: Exit if not supported template
		if ( empty( $template ) ) {
			return;
		}

		$template_name = array_keys( $template )[0];

		// STEP: Remove native woo hook actions
		Woocommerce_Helpers::execute_actions_in_wc_template( $template_name, 'remove', $action );
	}

	/**
	 * Restore WooCommerce hooks
	 *
	 * @since 1.7
	 *
	 * @param string   $action
	 * @param array    $filters
	 * @param string   $context
	 * @param \WP_Post $post
	 * @param mixed    $value
	 */
	public function maybe_restore_woo_hook_actions( $action, $filters, $context, $post, $value ) {
		$template = Woocommerce_Helpers::get_repeated_wc_template_hooks_by_action( $action );

		// STEP: Exit if not supported template
		if ( empty( $template ) ) {
			return;
		}

		$template = array_keys( $template )[0];

		// STEP: Restore native woo hook actions
		Woocommerce_Helpers::execute_actions_in_wc_template( $template, 'add', $action );
	}

	/**
	 * Add bricks-woo-{template} body class to the body
	 * Add woocommerce body classes for templates (builder or preview)
	 *
	 * Woo Phase 3
	 */
	public function maybe_set_body_class( $classes ) {
		/**
		 * When editing or previewing Woo templates, the woo body classes are not added
		 *
		 * So we need to add them manually.
		 */
		$post_id = get_the_ID();

		// Add single-product class for single product template preview (@since 2.2)
		if ( bricks_is_builder() ) {
			$template_conditions = Helpers::get_template_setting( 'templateConditions', $post_id );

			// Check if "main" is "postType" and "postType" is "product"
			if ( is_array( $template_conditions ) ) {
				foreach ( $template_conditions as $condition ) {
					if ( isset( $condition['main'] ) && $condition['main'] === 'postType' ) {
						if ( ! empty( $condition['postType'] ) && is_array( $condition['postType'] ) && in_array( 'product', $condition['postType'], true ) ) {
							$classes[] = 'single-product';
							break;
						}
					}
				}
			}
		}

		if ( Helpers::is_bricks_template( $post_id ) ) {
			$type      = Templates::get_template_type( $post_id );
			$classes[] = "bricks-woo-$type";

			switch ( $type ) {
				case 'wc_cart':
				case 'wc_cart_empty':
					$classes[] = 'woocommerce-cart';
					break;

				case 'wc_form_checkout':
				case 'wc_form_pay':
				case 'wc_thankyou':
				case 'wc_order_receipt':
					$classes[] = 'woocommerce-checkout';
					break;

				case 'wc_account_dashboard':
				case 'wc_account_orders':
				case 'wc_account_view_order':
				case 'wc_account_downloads':
				case 'wc_account_addresses':
				case 'wc_account_form_edit_address':
				case 'wc_account_form_edit_account':
				case 'wc_account_form_login':
				case 'wc_account_form_lost_password':
				case 'wc_account_form_lost_password_confirmation':
				case 'wc_account_reset_password':
				case 'wc_account_payment_methods':
				case 'wc_account_add_payment_method':
					$classes[] = 'woocommerce-account';
					break;
			}
		}

		return $classes;
	}

	/**
	 * Add .woocommerce class to the main tag (#brx-content) when previewing woo templates in frontend OR if the current page is my account page
	 *
	 * Otherwise not all Woo CSS & JS is applied. In builder, we add this class inside TheDynamicArea.vue
	 *
	 * Woo Phase 3
	 */
	public function template_preview_main_classes( $attributes ) {
		$post_id         = get_the_ID();
		$is_account_page = is_account_page();

		if ( ! Helpers::is_bricks_template( $post_id ) && ! $is_account_page ) {
			return $attributes;
		}

		$template_type = Templates::get_template_type( get_the_ID() );

		if ( strpos( $template_type, 'wc_' ) === false && ! $is_account_page ) {
			return $attributes;
		}

		// NOTE: Adds 20px padding to .woocommerce-cart .woocommerce (on tablet portrait), when previewing the template in frontend.
		$attributes['class'][] = 'woocommerce';

		return $attributes;
	}

	/**
	 * Check if use quantity in loop
	 *
	 * @return bool
	 * @since 1.9
	 */
	public static function use_quantity_in_loop() {
		return Database::get_setting( 'woocommerceUseQtyInLoop', false );
	}

	/**
	 * Add quantity input field to loop
	 *
	 * Support simple products and fully specified individual variations.
	 *
	 * @since 1.9
	 * @since 2.4 Support individual variations in product loops (#86cbh40aq).
	 *
	 * @param string      $html    Filtered WooCommerce loop add-to-cart markup.
	 * @param \WC_Product $product Current loop product.
	 * @return string Original markup or markup wrapped with a quantity input.
	 */
	public function add_quantity_input_field( $html, $product ) {
		$is_variation = $product->is_type( 'variation' );

		if ( $is_variation ) {
			// WooCommerce stores "Any …" attributes as empty strings. A variation ID alone
			// cannot resolve these selections, and a loop quantity field supplies no attributes.
			// Preserve the existing button; this enhancement does not add a variation selector.
			if ( in_array( '', $product->get_variation_attributes(), true ) ) {
				return $html;
			}

			// Preserve extension-owned variation controls to avoid nested forms or duplicate inputs.
			// Keep this guard variation-only so existing simple-product behavior stays unchanged.
			if ( preg_match( '/<form\b|<input\b/i', $html ) ) {
				return $html;
			}
		}

		if ( ( $product->is_type( 'simple' ) || $is_variation ) && $product->is_purchasable() && $product->is_in_stock() ) {
			$form_action = $product->add_to_cart_url();

			// Keep filtered labels, icons and attributes, but use native form submission for Woo's link.
			// Leave extension-owned markup alone when it is not a single outer anchor.
			if (
				class_exists( 'WP_HTML_Tag_Processor' ) &&
				preg_match( '/^\s*<a\b.*<\/a>\s*$/is', $html ) &&
				preg_match_all( '/<\/?a\b/i', $html ) === 2
			) {
				$button = new \WP_HTML_Tag_Processor( $html );
				$href   = $button->next_tag( 'a' ) ? $button->get_attribute( 'href' ) : null;

				if ( is_string( $href ) ) {
					// Filters can route submissions elsewhere or add required query parameters.
					$form_action = $href;
					$button->remove_attribute( 'href' );
					$button->remove_attribute( 'role' );
					$button->remove_attribute( 'rel' );
					$button->set_attribute( 'type', 'submit' );
					$html = preg_replace(
						[ '/^(\s*)<a\b/i', '/<\/a>(\s*)$/i' ],
						[ '$1<button', '</button>$1' ],
						$button->get_updated_html()
					);
				}
			}

			// WooCommerce renders a hidden quantity when min/max are both 1 (sold individually).
			$quantity_args = [
				'min_value' => 1,
				'max_value' => $product->get_max_purchase_quantity(),
			];

			$new_html  = '<form action="' . esc_url( $form_action ) . '" class="cart brx-loop-product-form" method="post" enctype="multipart/form-data">';
			$new_html .= woocommerce_quantity_input( $quantity_args, $product, false );
			$new_html .= $html;
			$new_html .= '</form>';

			return $new_html;
		}

		return $html;
	}

	/**
	 * Get Woo-validated credentials for the password-reset template.
	 *
	 * Shared by V1 account fallbacks and the reset-password element used in V2.
	 * Pass these directly to wc_get_template(), which extracts key/login into
	 * template variables; nesting them under another args key loses credentials.
	 *
	 * @see Woocommerce_Password_Reset::get_args()
	 * @since 1.9
	 * @since 2.4.3 Credentials follow Woo's native preparation instead of cookie parsing.
	 * @return array Validated key and login, or empty strings in Builder previews.
	 */
	public static function get_reset_password_args() {
		return Woocommerce_Password_Reset::get_args();
	}

	/**
	 * @since 1.11.1
	 */
	public static function use_bricks_woo_checkout_coupon_element() {
		return Database::get_setting( 'woocommerceUseBricksWooCheckoutCoupon', false );
	}

	/**
	 * @since 1.11.1
	 */
	public static function use_bricks_woo_checkout_login_element() {
		return Database::get_setting( 'woocommerceUseBricksWooCheckoutLogin', false );
	}

	/**
	 * Get active WooCommerce templates for current page
	 * Use by admin top bar
	 *
	 * @return array Array of template IDs indexed by template type
	 * @since 1.12
	 */
	public static function get_active_templates_for_current_page() {
		if ( ! self::is_woocommerce_active() ) {
			return [];
		}

		$post_id          = get_the_ID();
		$active_templates = [];

		$wc_pages = [
			'cart'     => [
				'page_id'   => wc_get_page_id( 'cart' ),
				'templates' => [
					'wc_cart'       => false,
					'wc_cart_empty' => false,
				],
			],
			'checkout' => [
				'page_id'   => wc_get_page_id( 'checkout' ),
				'templates' => [
					'wc_form_checkout' => false,
					'wc_form_pay'      => false,
					'wc_thankyou'      => false,
					'wc_order_receipt' => false,
				],
			],
			'account'  => [
				'page_id'   => wc_get_page_id( 'myaccount' ),
				'templates' => [
					'wc_account_dashboard'          => false,
					'wc_account_orders'             => false,
					'wc_account_view_order'         => false,
					'wc_account_downloads'          => false,
					'wc_account_addresses'          => false,
					'wc_account_form_edit_address'  => false,
					'wc_account_form_edit_account'  => false,
					'wc_account_form_login'         => false,
					'wc_account_form_lost_password' => false,
					'wc_account_form_lost_password_confirmation' => false,
					'wc_account_reset_password'     => false,
					'wc_account_payment_methods'    => false,
					'wc_account_add_payment_method' => false,
				],
			],
		];

		// Detect WooCommerce templates
		foreach ( $wc_pages as $wc_page => $wc ) {
			// Skip if current page is not same as WooCommerce page
			if ( $post_id !== $wc['page_id'] ) {
				continue;
			}

			// Get template IDs
			foreach ( $wc['templates'] as $template => $temp_id ) {
				$template_id = self::get_template_data_by_type( $template, false );
				if ( $template_id ) {
					$active_templates[ $template ] = $template_id;
				}
			}
		}

		return $active_templates;
	}

	/**
	 * Get active WooCommerce templates for current endpoint
	 *
	 * Remove unrelated arrays generated from get_active_templates_for_current_page() based on the current endpoint
	 *
	 * @since 1.12
	 */
	public static function get_active_templates_for_current_endpoint() {
		$wc_templates = self::get_active_templates_for_current_page();
		$target_key   = '';

		// Cart page
		if ( is_cart() ) {
			if ( WC()->cart->is_empty() ) {
				// Just need wc_cart_empty
				$target_key = 'wc_cart_empty';
			} else {
				// Just need wc_cart
				$target_key = 'wc_cart';
			}
		}

		// Checkout page
		if ( is_checkout() ) {
			$target_key = '';
			// Order pay
			if ( get_query_var( 'order-pay' ) ) {
				// Just need wc_form_pay
				$target_key = 'wc_form_pay';
			}

			// Order receipt (= thank you page)
			elseif ( get_query_var( 'order-received' ) ) {
				// Just need wc_thankyou
				$target_key = 'wc_thankyou';
			}

			// Checkout page
			else {
				// Just need wc_form_checkout
				$target_key = 'wc_form_checkout';
			}
		}

		// Account page
		if ( is_account_page() ) {
			// Get current endpoint
			$endpoint = WC()->query->get_current_endpoint();

			switch ( $endpoint ) {
				case 'orders':
					$target_key = 'wc_account_orders';

					break;
				case 'view-order':
					$target_key = 'wc_account_view_order';

					break;
				case 'downloads':
					$target_key = 'wc_account_downloads';

					break;

				case 'payment-methods':
					$target_key = 'wc_account_payment_methods';

					break;

				case 'add-payment-method':
					$target_key = 'wc_account_add_payment_method';

					break;
				case 'edit-address':
					global $wp;
					$is_edit_address = isset( $wp->query_vars['edit-address'] ) ? true : false;
					if ( $is_edit_address ) {
						$target_key = 'wc_account_form_edit_address';
					} else {
						$target_key = 'wc_account_addresses';
					}

					break;
				case 'edit-account':
					$target_key = 'wc_account_form_edit_account';

					break;
				case 'lost-password':
					if ( ! empty( $_GET['reset-link-sent'] ) ) {
						// Lost password confirmation
						$target_key = 'wc_account_form_lost_password_confirmation';
					} elseif ( ! empty( $_GET['show-reset-form'] ) ) {
						// Reset password form
						$target_key = 'wc_account_reset_password';
					} else {
						// Lost password form
						$target_key = 'wc_account_form_lost_password';
					}

					break;
				case '':
					if ( is_user_logged_in() ) {
						// Dashboard
						$target_key = 'wc_account_dashboard';
					} else {
						// Login form
						$target_key = 'wc_account_form_login';
					}

					break;
			}
		}

		// Remove unrelated arrays
		if ( ! empty( $target_key ) ) {
			$wc_templates = array_filter(
				$wc_templates,
				function( $key ) use ( $target_key ) {
					return $key === $target_key;
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		return $wc_templates;
	}

	/**
	 * Remove WooCommerce resource hints that could cause PHP fatal errors (preg_match use on boolean) (#86c1r48gg)
	 * We deregister wp-polyfill in the builder and register it as false
	 *
	 * @see src/Blocks/AssetsController.php get_absolute_url() (WooCommerce plugin)
	 * @since 1.12
	 */
	public function remove_woo_resource_hints() {
		// Access the WooCommerce Blocks' Asset API instance.
		global $wp_filter;

		// Iterate through all `init` hooks to find the one tied to `AssetsController`.
		if ( isset( $wp_filter['init'] ) ) {
			foreach ( $wp_filter['init']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback_key => $callback ) {
					if (
						is_array( $callback['function'] ) &&
						is_object( $callback['function'][0] ) &&
						get_class( $callback['function'][0] ) === 'Automattic\WooCommerce\Blocks\AssetsController'
					) {
						// Use the found instance to remove the filter.
						remove_filter( 'wp_resource_hints', [ $callback['function'][0], 'add_resource_hints' ], 10 );
					}
				}
			}
		}
	}

	/**
	 * When searching by meta fields that might belong to product variations (sku, gtin, etc), include the parent product IDs as well
	 *
	 * @since 2.2
	 */
	public function maybe_include_product_parent_ids( $post_ids, $search_fields, $meta_fields, $search_term, $filter_id, $query_id ) {
		if ( ! is_array( $meta_fields ) || empty( $meta_fields ) || empty( $post_ids ) ) {
			return $post_ids;
		}

		// meta fields that might need to include parent product IDs (sku, gtin, anything else?)
		$wc_meta_fields = [ '_sku', '_global_unique_id', '_variation_description' ];
		$found          = false;

		// Check if any of the meta fields are in the wc_meta_fields
		foreach ( $meta_fields as $meta_field_array ) {
			$meta_key = is_array( $meta_field_array ) && isset( $meta_field_array['metaKey'] ) ? $meta_field_array['metaKey'] : '';

			if ( in_array( $meta_key, $wc_meta_fields, true ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return $post_ids;
		}

		global $wpdb;

		// Maybe some of the post_ids are product variations, we need to get the parent product IDs as well
		$product_parent_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND ID IN (" . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')',
				$post_ids
			)
		);

		$product_parent_ids = array_map( 'intval', $product_parent_ids );
		$post_ids           = array_unique( array_merge( $post_ids, $product_parent_ids ) );

		return $post_ids;
	}

}

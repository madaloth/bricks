<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Templates {
	const MAX_REMOTE_IMAGE_BYTES = 10485760;

	public static $template_images = [];

	// All template IDs used on requested URL (@since 1.8.1)
	public static $rendered_template_ids_on_page = [];

	// All generated inline CSS identifiers (@since 1.9.1)
	public static $generated_inline_identifier = [];

	// Flag to avoid assigning templates to hooks multiple times (@since 2.0.2)
	private static $assigned_templates_to_hooks = false;

	public function __construct() {
		add_filter( 'init', [ $this, 'register_post_type' ] );

		// Run on 'wp' and priority 20 to ensure set_active_templates && set_page_data in database.php ran first (@since 1.9.2)
		add_action( 'wp', [ $this, 'assign_templates_to_hooks' ], 20 );
		// 'wp' is not run in custom API endpoints, follow database.php 'rest_api_init' as well (@since 2.0)
		add_action( 'rest_api_init', [ $this, 'assign_templates_to_hooks' ], 20 );

		add_shortcode( 'bricks_template', [ $this, 'render_shortcode' ] );

		// Builder
		add_action( 'wp_ajax_bricks_create_template', [ $this, 'create_template' ] );
		add_action( 'wp_ajax_bricks_save_template', [ $this, 'save_template' ] );
		add_action( 'wp_ajax_bricks_delete_template', [ $this, 'delete_template' ] );

		// Admin & builder
		add_action( 'wp_ajax_bricks_import_template', [ $this, 'import_template' ] );
		add_action( 'wp_ajax_bricks_export_template', [ $this, 'export_template' ] );

		add_action( 'wp_ajax_bricks_convert_template', [ $this, 'convert_template' ] );

		add_action( 'save_post', [ $this, 'flush_templates_cache' ] );

		add_filter( 'wp_sitemaps_post_types', [ $this, 'remove_templates_from_wp_sitemap' ] );

		add_filter( 'wp_sitemaps_taxonomies', [ $this, 'remove_template_taxonomies_from_wp_sitemap' ] );
	}

	/**
	 * Register custom post types
	 *
	 * post_type: bricks_template
	 * taxonomies: template_tag, template_bundle
	 *
	 * @since 1.0
	 */
	public function register_post_type() {
		// Register post type: bricks_template
		register_post_type(
			BRICKS_DB_TEMPLATE_SLUG,
			[
				'labels'              => [
					'name'               => esc_html__( 'My templates', 'bricks' ),
					'singular_name'      => esc_html__( 'Template', 'bricks' ),
					'add_new'            => esc_html__( 'Add New', 'bricks' ),
					'add_new_item'       => esc_html__( 'Add New Template', 'bricks' ),
					'edit_item'          => esc_html__( 'Edit template', 'bricks' ),
					'new_item'           => esc_html__( 'New Template', 'bricks' ),
					'view_item'          => esc_html__( 'View Template', 'bricks' ),
					'view_items'         => esc_html__( 'View Templates', 'bricks' ),
					'search_items'       => esc_html__( 'Search Templates', 'bricks' ),
					'not_found'          => esc_html__( 'No Templates found', 'bricks' ),
					'not_found_in_trash' => esc_html__( 'No Template found in Trash', 'bricks' ),
					'all_items'          => esc_html__( 'All Templates', 'bricks' ),
					'menu_name'          => esc_html__( 'My templates', 'bricks' ),
				],
				'public'              => true,
				'rewrite'             => [ 'slug' => 'template' ],
				/**
				 * Exclude Bricks templates from search resuls on the frontend if Bricks setting "Public Templates" is not enabled
				 *
				 * @since 1.9.3
				 */
				'exclude_from_search' => ! bricks_is_builder() && ! Database::get_setting( 'publicTemplates', false ),
				'hierarchical'        => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => true,
				'capability_type'     => 'post',
				'supports'            => [
					'author',
					'revisions',
					'thumbnail',
					'title',
				],
				'taxonomies'          => [ BRICKS_DB_TEMPLATE_TAX_TAG ],
			]
		);

		// Register template taxomony: 'template_tag'
		register_taxonomy(
			BRICKS_DB_TEMPLATE_TAX_TAG,
			BRICKS_DB_TEMPLATE_SLUG,
			[
				'labels' => [
					'name'          => esc_html__( 'Template Tags', 'bricks' ),
					'singular_name' => esc_html__( 'Template tag', 'bricks' ),
					'all_items'     => esc_html__( 'All Template Tags', 'bricks' ),
					'edit_item'     => esc_html__( 'Edit Template Tag', 'bricks' ),
					'view_item'     => esc_html__( 'View Template Tag', 'bricks' ),
					'update_item'   => esc_html__( 'Update Template Tag', 'bricks' ),
					'add_new_item'  => esc_html__( 'Add New Template Tag', 'bricks' ),
					'new_item_name' => esc_html__( 'New Template Name', 'bricks' ),
					'search_items'  => esc_html__( 'Search Template Tags', 'bricks' ),
					'not_found'     => esc_html__( 'No Template Tag found', 'bricks' ),
					'name'          => esc_html__( 'Template tag', 'bricks' ),
				],
			]
		);

		// Register template taxomony: 'template_bundle'
		register_taxonomy(
			BRICKS_DB_TEMPLATE_TAX_BUNDLE,
			BRICKS_DB_TEMPLATE_SLUG,
			[
				'labels' => [
					'name'          => esc_html__( 'Template Bundles', 'bricks' ),
					'singular_name' => esc_html__( 'Template bundle', 'bricks' ),
					'all_items'     => esc_html__( 'All Template Bundles', 'bricks' ),
					'edit_item'     => esc_html__( 'Edit Template Bundle', 'bricks' ),
					'view_item'     => esc_html__( 'View Template Bundle', 'bricks' ),
					'update_item'   => esc_html__( 'Update Template Bundle', 'bricks' ),
					'add_new_item'  => esc_html__( 'Add New Template Bundle', 'bricks' ),
					'new_item_name' => esc_html__( 'New Template Name', 'bricks' ),
					'search_items'  => esc_html__( 'Search Template Bundles', 'bricks' ),
					'not_found'     => esc_html__( 'No Template Bundle found', 'bricks' ),
					'name'          => esc_html__( 'Template bundle', 'bricks' ),
				],
			]
		);
	}

	/**
	 * Render shortcode: [bricks_template]
	 */
	public function render_shortcode( $attributes = [] ) {
		$template_id = ! empty( $attributes['id'] ) ? intval( $attributes['id'] ) : false;

		if ( ! $template_id ) {
			return;
		}

		// Ensure template post_status is 'publish' (@since 2.0)
		if ( get_post_status( $template_id ) !== 'publish' ) {
			return;
		}

		$original_post_id = ! empty( Database::$page_data['original_post_id'] ) ? Database::$page_data['original_post_id'] : '';

		$original_page_type        = Database::$page_data['current_page_type'] ?? '';
		$is_original_template_post = $original_page_type === 'post' && get_post_type( $original_post_id ) === BRICKS_DB_TEMPLATE_SLUG;

		// post_id at this stage could be template preview post ID (populated content)
		$post_id = get_the_ID();

		/**
		 * Avoid loops: Shortcode rendering inside of itself
		 * Ensure $original_post_id is a bricks template when use for comparison, it might be a term ID (#862k7jcn7)
		 * Instead of Helpers::is_bricks_template which will be affected by query loop,
		 * we check if the $original_post_id (could be post ID or term ID) is a bricks template (#86c8t4t3q; @since 2.3.1)
		 */
		// Prefer the active source over the viewed template (e.g. a header referencing the section being edited).
		$render_context    = Frontend::get_render_context();
		$is_self_reference = ! empty( Frontend::$render_context_stack )
			? $template_id === $render_context['source_post_id']
			: ( $template_id == $post_id || ( $is_original_template_post && $template_id == $original_post_id ) );

		if ( $is_self_reference ) {
			return Helpers::get_element_placeholder(
				[
					'title' => esc_html__( 'Not allowed: Infinite template loop.', 'bricks' ),
				]
			);
		}

		// Track template source area so Woo dynamic fragments can re-render this subtree later (@since 2.4).
		$source_area = 'content';
		$elements    = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );

		// Check if header or footer template (@since 1.11)
		if ( empty( $elements ) ) {
			$template_type = self::get_template_type( $template_id );

			if ( $template_type === 'header' ) {
				$source_area = 'header';
				$elements    = get_post_meta( $template_id, BRICKS_DB_PAGE_HEADER, true );
			} elseif ( $template_type === 'footer' ) {
				$source_area = 'footer';
				$elements    = get_post_meta( $template_id, BRICKS_DB_PAGE_FOOTER, true );
			}
		}

		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return Helpers::get_element_placeholder(
				[
					'title' => esc_html__( 'Your selected template is empty.', 'bricks' ),
				]
			);
		}

		$html = '';

		/**
		 * STEP: Generate template CSS (builder, inline styles or external files)
		 *
		 * Non-loop templates only as in-loop CSS is generated in assets.php line 2535
		 */

		// Collect all rendered template IDs for requested URL (@since 1.8.1)
		if ( ! in_array( $template_id, self::$rendered_template_ids_on_page ) ) {
			self::$rendered_template_ids_on_page[] = $template_id;
		}

		// Check for icon fonts and global elements
		Assets::enqueue_setting_specific_scripts( $elements );

		$looping_query_id = Query::is_any_looping();

		$template_inline_css = self::generate_inline_css( $template_id, $elements );

		// To store popup settings CSS (@since 1.12)
		$popup_setting_css = '';
		/**
		 * Template type: Popup
		 *
		 * Generate CSS for popup template settings when popup added via Templates element.
		 *
		 * Non-loop, No condition.
		 *
		 * @since 1.11.1
		 */
		if (
			self::get_template_type( $template_id ) === 'popup' &&
			! in_array( $template_id, Database::$active_templates['popup'] ) &&
			! Query::is_looping()
		) {
			$popup_setting_css = self::generate_popup_setting_css( $template_id );
			if ( $popup_setting_css ) {
				$template_inline_css .= $popup_setting_css;
			}
		}

		// STEP: Builder (append template CSS as inline <style> to element HTML)
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			// Collect css and elements related to this template for component rendering builder call (@since 2.0)
			if ( ! isset( Builder::$templates_data[ $template_id ] ) ) {
				Builder::$templates_data[ $template_id ] = [
					'css'      => '',
					'elements' => []
				];
			}

			Builder::$templates_data[ $template_id ]['css']      = $template_inline_css;
			Builder::$templates_data[ $template_id ]['elements'] = $elements;

			// Use 'data-template-id' to get template ID in builder to generate global classes CSS of Template element (@since 1.8.2)
			$template_inline_css .= Assets::$inline_css_dynamic_data;
			$html                .= "<style data-template-id=\"{$template_id}\" id=\"bricks-inline-css-template-{$template_id}\">{$template_inline_css}</style>";
		}

		// STEP: CSS loading method: External files
		elseif ( Database::get_setting( 'cssLoading' ) === 'file' ) {
			$template_css_file_dir = Assets::$css_dir . "/post-$template_id.min.css";
			$template_css_file_url = Assets::$css_url . "/post-$template_id.min.css";

			// Make sure the template CSS file is not already enqueued (@since 1.12)
			if ( file_exists( $template_css_file_dir ) && ! wp_style_is( "bricks-post-$template_id" ) ) {
				wp_enqueue_style( "bricks-post-$template_id", $template_css_file_url, [], filemtime( $template_css_file_dir ) );
			}

			// Template shortcodes inside loops need loop-adjusted class selectors, which the static template CSS file cannot provide.
			if (
				$looping_query_id ||
				$popup_setting_css !== ''
			) {
				Assets::$inline_css_dynamic_data .= $template_inline_css;
			}
		}

		// STEP: CSS loading method: Inline styles (default)
		else {
			// Get dynamic data styles to add as inline CSS on the frontend (@since 1.8.2)
			Assets::$inline_css_dynamic_data .= $template_inline_css;
		}

		// STEP: Avoid infinite template loops
		static $rendered_shortcode_template_ids = [];

		if ( ! in_array( $template_id, $rendered_shortcode_template_ids ) ) {
			// Add template ID to avoid infinite loops (reset below after template has been rendered)
			$rendered_shortcode_template_ids[] = $template_id;

			// Store the current main render_data self::$elements
			$store_elements = Frontend::$elements;

			// Pass source metadata for Woo dynamic fragment refreshes inside template elements (@since 2.4).
			$html .= Frontend::render_data( $elements, $source_area, $template_id );

			// Reset the main render_data self::$elements
			Frontend::$elements = $store_elements;

			// Reset template ID by removing last template ID from the array
			array_pop( $rendered_shortcode_template_ids );
		}

		/**
		 * Build looping popup HTML (render in footer)
		 *
		 * @since 1.7.1
		 */
		if ( self::get_template_type( $template_id ) === 'popup' ) {
			Popups::build_looping_popup_html( $template_id );

			return;
		}

		return $html;
	}

	/**
	 * Generate the inline CSS for template rendered in shortcode element
	 */
	public static function generate_inline_css( $template_id, $elements ) {
		if ( empty( $template_id ) ) {
			return;
		}

		// Return: Template has not been published (@since 1.7.1)
		if ( $template_id && get_post_status( $template_id ) !== 'publish' ) {
			return;
		}

		$inline_css = '';

		$previous_template_inline_css = Assets::$inline_css[ "template_$template_id" ] ?? '';

		Assets::generate_css_from_elements( $elements, "template_$template_id" );

		// Check as template_{id} is not set when using inline CSS loading method (see template.php line 77)
		$template_inline_css = Assets::$inline_css[ "template_$template_id" ] ?? '';

		if ( $template_inline_css ) {

			$looping_query_id = Query::is_any_looping();

			if ( $looping_query_id ) {
				// Later posts can add new Post Content rules. Emit only the CSS appended by this call,
				// as earlier rules have already been emitted and the generator deduplicates shared styles.
				$template_inline_css = substr( $template_inline_css, strlen( $previous_template_inline_css ) );

				$unique_loop_id = [
					$template_id,
					Query::get_query_element_id( $looping_query_id ),
					Query::get_loop_object_type( $looping_query_id ),
				];
			}

			// Unique identifier for inline template inside query loop (@since 1.9.1)
			$generated_inline_identifier = $looping_query_id ? implode( ':', $unique_loop_id ) : $template_id;

			/**
			 * Add template inline CSS, if:
			 * 1. Non-loop template that has not been added already
			 * 2. In-loop template with newly generated CSS, including post-specific Post Content styles
			 *
			 * @since 1.8.2
			 */
			if (
				( ! Query::is_looping() && ! in_array( $template_id, Assets::$page_settings_post_ids ) ) ||
				( $looping_query_id && $template_inline_css !== '' )
			) {
				$inline_css .= "\n/* TEMPLATE SHORTCODE CSS (ID: {$template_id}) */\n";
				$inline_css .= $template_inline_css;
				// Add generated inline identifier to avoid duplicate inline CSS (@since 1.9.1)
				if ( ! in_array( $generated_inline_identifier, self::$generated_inline_identifier ) ) {
					self::$generated_inline_identifier[] = $generated_inline_identifier;
				}
			}
		}

		// Add page settings of this template
		if ( ! in_array( $template_id, Assets::$page_settings_post_ids ) ) {
			Assets::$page_settings_post_ids[] = $template_id;
		}

		/**
		 * Builder: Generate global classes & page settings CSS of Template element
		 *
		 * Frontend: Global classes in template added in wp_footer via enqueue_footer_inline_css
		 *
		 * @since 1.8.2
		 */

		// Moved to enqueue_footer_inline_css in frontend.php (@since 1.9.8)
		// $global_classes_css = Assets::generate_global_classes();

		if ( bricks_is_builder_call() ) {
			$page_css = Assets::generate_inline_css_page_settings();

			if ( $page_css ) {
				$inline_css .= "\n/* PAGE CSS */\n" . $page_css;
			}
		}

		// Webfonts
		Assets::load_webfonts( $inline_css );

		return $inline_css;
	}

	/**
	 * Keep the timestamp of the latest change in the templates post type to force the cache flush
	 *
	 * @param int $post_id Post ID.
	 */
	public function flush_templates_cache( $post_id ) {
		if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
			wp_cache_set( 'last_changed', microtime(), 'bricks_' . BRICKS_DB_TEMPLATE_SLUG );
		}
	}

	/**
	 * Check if remote site can get templates
	 *
	 * @see Api::get_templates()
	 * @return array Array with 'error' key on error. Array with 'site', 'password', 'licenseKey' on success.
	 *
	 * @since 1.0
	 */
	public static function can_get_templates( $parameters ) {
		// STEP: Admin setting 'myTemplatesAccess' blocked
		if ( ! Database::get_setting( 'myTemplatesAccess' ) ) {
			return [
				'error' => [
					'code'    => 'my_templates_access_disabled',
					'message' => esc_html__( 'The site you are requesting templates from has access to their templates disabled.', 'bricks' ),
				],
			];
		}

		$site_url = ! empty( $parameters['site'] ) ? esc_url( $parameters['site'] ) : false;

		// STEP: Check 'site' provided (mandatory)
		if ( ! $site_url ) {
			return [
				'error' => [
					'code'    => 'no_site_url',
					'message' => esc_html__( 'Sorry, but no site URL has been provided.', 'bricks' ),
				],
			];
		}

		// STEP: Admin setting 'myTemplatesWhitelist' lists requesting 'site'
		$my_templates_whitelist_urls = Database::get_setting( 'myTemplatesWhitelist', [] );

		if ( $my_templates_whitelist_urls ) {
			$my_templates_whitelist_urls = array_map( 'trim', explode( "\n", $my_templates_whitelist_urls ) );

			$my_templates_whitelist_urls = array_map( 'trailingslashit', $my_templates_whitelist_urls );

			$site_url = trailingslashit( $site_url );

			if ( ! in_array( $site_url, $my_templates_whitelist_urls ) ) {
				return [
					'error' => [
						'code'    => 'not_whitelisted',
						// translators: %1$s: site URL, %2$s: current site URL
						'message' => sprintf( esc_html__( 'Your website (%1$s) has no permission to access templates from %2$s', 'bricks' ), $site_url, get_site_url() ),
					],
				];
			}
		}

		// STEP: Admin setting 'myTemplatesPassword'
		$my_templates_password = Database::get_setting( 'myTemplatesPassword' );
		$password              = isset( $parameters['password'] ) ? sanitize_text_field( $parameters['password'] ) : false;

		if ( $my_templates_password ) {
			if ( ! $password ) {
				return [
					'error' => [
						'code'    => 'remote_templates_password_required',
						'message' => esc_html__( 'The site you are requesting templates from requires a remote templates password.', 'bricks' ),
					],
				];
			}

			if ( $password !== $my_templates_password ) {
				return [
					'error' => [
						'code'    => 'remote_templates_password_incorrect',
						'message' => esc_html__( 'Your remote templates password is incorrect.', 'bricks' ),
					],
				];
			}
		}

		// STEP: ALl checks pass

		// Pass 'site' for 'bricks/get_templates' filter check
		$templates_args = [ 'site' => $site_url ];

		// Pass license key if provided
		if ( isset( $parameters['licenseKey'] ) ) {
			$templates_args['licenseKey'] = sanitize_text_field( $parameters['licenseKey'] );
		}

		// Pass templates password if provided
		if ( isset( $password ) ) {
			$templates_args['password'] = $password;
		}

		// Success: Return template_args
		return $templates_args;
	}

	/**
	 * Create template
	 *
	 * @since 1.0
	 */
	public static function get_remote_template_settings( $template_source = '' ) {
		$all_remote_templates = [];

		// Group: Bricks
		$all_remote_templates[] = [
			'url'  => 'bricksTemplatesGroupTitle',
			'name' => 'Bricks',
		];

		// Bricks: Wireframes
		$all_remote_templates[] = [
			'id'   => 'wireframes',
			'name' => esc_html__( 'Wireframes', 'bricks' ),
			'info' => esc_html__( 'Wireframes provide basic structural templates that serve as a starting point for designing a layout. They are based on classes and variables.', 'bricks' ) . ' (' . Helpers::article_link( 'builder/features/wireframe-templates/', esc_html__( 'Learn more', 'bricks' ) ) . ')',
		];

		// Bricks: Design sets (Karlson, Auron, Velora, etc.)
		$all_remote_templates[] = [
			'id'   => 'design-sets',
			'name' => esc_html__( 'Design sets', 'bricks' ),
		];

		// Get community templates
		// NOTE: No longer in use (@since 2.2)
		// $all_remote_templates[] = [
		// 'id'   => 'community-templates',
		// 'name' => esc_html__( 'Community templates', 'bricks' ),
		// ];

		// Get single remote template (Bricks > Settings > Templates) @pre 1.9.4
		$single_remote_template_url      = Database::get_setting( 'remoteTemplatesUrl' );
		$single_remote_template_password = Database::get_setting( 'remoteTemplatesPassword' );

		if ( $single_remote_template_url ) {
			$all_remote_templates[] = [
				'url'      => $single_remote_template_url,
				'password' => $single_remote_template_password,
			];
		}

		// Get remote templates (Bricks > Settings > Templates) @since 1.9.4
		$remote_templates = Database::get_setting( 'remoteTemplates' ) ?? false;

		if ( is_array( $remote_templates ) && ! empty( $remote_templates ) ) {
			// Group: Remote templates
			$all_remote_templates[] = [
				'name' => esc_html__( 'Remote templates', 'bricks' ),
				'url'  => 'remoteTemplatesGroupTitle',
			];

			foreach ( $remote_templates as $remote_template ) {
				if ( ! empty( $remote_template['url'] ) ) {
					// Add remote template to all remote templates
					$all_remote_templates[] = [
						'name'     => $remote_template['name'] ?? '',
						'url'      => $remote_template['url'] ?? '',
						'password' => $remote_template['password'] ?? '',
					];
				}
			}
		}

		// Return specific template by source (url, id)
		if ( $template_source ) {
			$template = null;
			foreach ( $all_remote_templates as $remote_template ) {
				if ( ! empty( $remote_template['url'] ) && $remote_template['url'] === $template_source || ! empty( $remote_template['id'] ) && $remote_template['id'] === $template_source ) {
					$template = $remote_template;
					break;
				}
			}

			if ( $template ) {
				return $template;
			}
		}

		return $all_remote_templates;
	}

	/**
	 * Builder templates: Get all remote templates data (templates, authors, bundles, tags)
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_remote_templates_data() {
		$template_source   = $_POST['templateSource'] ?? '';
		$template_settings = self::get_remote_template_settings( $template_source );

		$request_url = Api::get_endpoint( 'get-templates-data', $template_source );

		// Is a Bricks template request
		$template_source_id = $template_settings['id'] ?? ''; // wireframes, design-sets, community-templates, etc. (@since 2.0)
		if ( $template_source_id ) {
			$request_url = Api::get_endpoint( 'get-templates-data', BRICKS_REMOTE_URL );

			$request_url = add_query_arg( [ 'source' => $template_source_id ], $request_url );

			// Send license key for verification (no active license key, no templates)
			$request_url = add_query_arg( [ 'licenseKey' => License::$license_key ], $request_url );
		}

		// Add site URL to the request URL
		$request_url = add_query_arg( [ 'site' => get_site_url() ], $request_url );

		// Add password if provided
		if ( ! empty( $template_settings['password'] ) ) {
			$request_url = add_query_arg( [ 'password' => urlencode( $template_settings['password'] ) ], $request_url );
		}

		$request_url = add_query_arg( [ 'time' => time() ], $request_url );

		$response = Helpers::remote_get( $request_url );

		// Return error to show in builder templates manager
		if ( is_wp_error( $response ) ) {
			return [
				'error'       => $response->get_error_message(),
				'request_url' => $request_url,
			];
		}

		$remote_templates = json_decode( wp_remote_retrieve_body( $response ), true );
		$remote_templates = apply_filters( 'bricks/get_remote_templates_data', $remote_templates );

		if ( ! empty( $remote_templates['error']['message'] ) ) {
			return [
				'error'       => $remote_templates['error']['message'],
				'request_url' => $request_url,
			];
		}

		return $remote_templates;
	}

	/**
	 * Get templates query based on custom args
	 *
	 * @since 1.0
	 *
	 * @param array $custom_args
	 * @return WP_Query
	 */
	public static function get_templates_query( $custom_args = [] ) {
		$last_changed = wp_cache_get_last_changed( 'bricks_' . BRICKS_DB_TEMPLATE_SLUG );
		$cache_key    = md5( 'get_templates_query_' . $last_changed . wp_json_encode( $custom_args ) );

		// Undocumented (@since 1.9.9)
		$cache_key = apply_filters( 'bricks/get_templates_query/cache_key', $cache_key );

		$query = wp_cache_get( $cache_key, 'bricks' );

		if ( $query === false ) {
			$default_args = [
				'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			];

			// Undocumented (@since 1.9.9)
			$merged_args = apply_filters( 'bricks/get_templates/query_vars', wp_parse_args( $custom_args, $default_args ) );

			$query = new \WP_Query( $merged_args );

			// Undocumented: Allows integrations to restore query context after templates are queried. (#86c9vt6tw; @since 2.3.6)
			do_action( 'bricks/get_templates/query_after', $query, $merged_args );

			wp_cache_set( $cache_key, $query, 'bricks', DAY_IN_SECONDS );
		}

		return $query;
	}

	/**
	 * Get all the template IDs of a specific type
	 */
	public static function get_templates_by_type( $template_type = '' ) {
		$query_args = [
			'meta_query' => [
				[
					'key'   => BRICKS_DB_TEMPLATE_TYPE,
					'value' => $template_type,
				],
			],
			'fields'     => 'ids',
		];

		// Infobox query arguments
		$q_infobox = [
			[
				'key'     => BRICKS_DB_TEMPLATE_TYPE,
				'value'   => 'popup',
				'compare' => '=',
			],
			[
				'key'     => BRICKS_DB_TEMPLATE_SETTINGS,
				'value'   => 's:14:"popupIsInfoBox";b:1;',
				'compare' => 'LIKE',
			],
		];

		// Popup query arguments
		$q_popup = [
			[
				'key'     => BRICKS_DB_TEMPLATE_TYPE,
				'value'   => 'popup',
				'compare' => '=',
			],
			[
				'relation' => 'OR',
				[
					'key'     => BRICKS_DB_TEMPLATE_SETTINGS,
					'value'   => 's:14:"popupIsInfoBox";b:1;',
					'compare' => 'NOT LIKE',
				],
				[
					'key'     => BRICKS_DB_TEMPLATE_SETTINGS,
					'compare' => 'NOT EXISTS',
				],
			],
		];

		// Handle specific template types infobox and popup (string)
		if ( is_string( $template_type ) ) {
			if ( $template_type === 'infobox' ) {
				$query_args = [
					'meta_query'    => $q_infobox,
					'fields'        => 'ids',
					'no_found_rows' => true,
				];
			} elseif ( $template_type === 'popup' ) {
				$query_args = [
					'meta_query'    => $q_popup,
					'fields'        => 'ids',
					'no_found_rows' => true,
				];
			}
		}

		// Handle array of template types (includes/elements/template.php)
		if ( is_array( $template_type ) ) {
			$contains_infobox = in_array( 'infobox', $template_type );
			$contains_popup   = in_array( 'popup', $template_type );

			// Amend meta query if 'infobox' or 'popup' is in the array, but not both
			if ( ( $contains_infobox || $contains_popup ) && ! ( $contains_infobox && $contains_popup ) ) {
				// Remove 'infobox' and 'popup' from array
				$template_type = array_diff( $template_type, [ 'popup', 'infobox' ] );

				$query_args = [
					'meta_query'    => [
						'relation' => 'OR',
					],
					'fields'        => 'ids',
					'no_found_rows' => true,
				];

				// Add remaining template types to the query
				if ( count( $template_type ) > 0 ) {
					$query_args['meta_query'][] = [
						'key'     => BRICKS_DB_TEMPLATE_TYPE,
						'value'   => $template_type,
						'compare' => 'IN',
					];
				}

				// Add popup query if 'popup' is in the array
				if ( $contains_popup ) {
					$query_args['meta_query'][] = $q_popup;
				}

				// Add infobox query if 'infobox' is in the array
				if ( $contains_infobox ) {
					$query_args['meta_query'][] = $q_infobox;
				}
			}
		}

		// Execute the query
		$query = self::get_templates_query( $query_args );

		// Return results for normal queries
		if ( empty( $query_args['no_found_rows'] ) ) {
			return ! empty( $query->found_posts ) ? $query->posts : [];
		}

		// Return results based on 'no_found_rows' flag
		return $query->posts;
	}

	/**
	 * Get my templates
	 *
	 * @since 1.0
	 */
	public static function get_templates( $custom_args = [] ) {
		// Get excluded templates setting (@since 1.12.2)
		$excluded_templates = Database::get_setting( 'excludedTemplates', [] );

		// If this is a remote request, modify the query to exclude templates (@since 1.12.2)
		if ( ! empty( $custom_args['remote_request'] ) && ! empty( $excluded_templates ) ) {
			if ( ! isset( $custom_args['post__not_in'] ) ) {
				$custom_args['post__not_in'] = [];
			}

			$custom_args['post__not_in'] = array_merge( $custom_args['post__not_in'], $excluded_templates );
		}

		$templates_query = self::get_templates_query( $custom_args );

		$templates = [];

		if ( $templates_query->have_posts() ) {
			foreach ( $templates_query->get_posts() as $template ) {
				$template_id = $template->ID;

				// Template bundles
				$template_bundles = wp_get_object_terms( $template_id, BRICKS_DB_TEMPLATE_TAX_BUNDLE, [ 'fields' => 'slugs' ] );
				$bundles          = [];

				if ( $template_bundles ) {
					foreach ( $template_bundles as $bundle ) {
						$bundles[] = $bundle;
					}
				}

				// Template tags
				$template_tags = wp_get_object_terms( $template_id, BRICKS_DB_TEMPLATE_TAX_TAG, [ 'fields' => 'slugs' ] );
				$tags          = [];

				if ( $template_tags ) {
					foreach ( $template_tags as $tag ) {
						$tags[] = $tag;
					}
				}

				$author_name = get_the_author_meta( 'display_name', $template->post_author );

				// Check if my template thumbnail exists locally in WP root 'template-screenshots' folder
				$template_thumbnail_path = ABSPATH . trailingslashit( 'template-screenshots' ) . $template->post_name . '.jpg';
				$template_thumbnail      = null;

				if ( has_post_thumbnail( $template_id ) ) {
					$template_thumbnail = get_the_post_thumbnail_url( $template_id, 'bricks_medium' );
				} elseif ( Database::get_setting( 'generateTemplateScreenshots', false ) ) {
					// Define the base directory and URL for the custom screenshots
					$wp_upload_dir = wp_upload_dir();
					$custom_dir    = $wp_upload_dir['basedir'] . '/' . BRICKS_TEMPLATE_SCREENSHOTS_DIR . '/';
					$custom_url    = $wp_upload_dir['baseurl'] . '/' . BRICKS_TEMPLATE_SCREENSHOTS_DIR . '/';

					// Get all files for this template ID
					$all_files = glob( $custom_dir . "template-screenshot-$template_id-*" );

					$latest_file = null;
					$latest_time = 0;

					foreach ( $all_files as $file ) {
						// Check if the file is of a valid type
						$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
						if ( in_array( $extension, [ 'webp', 'png' ] ) ) {
							// Extract the timestamp from the filename
							if ( preg_match( '/-(\d+)\.' . $extension . '$/', $file, $matches ) ) {
								$file_time = intval( $matches[1] );
								if ( $file_time > $latest_time ) {
									$latest_time = $file_time;
									$latest_file = $file;
								}
							}
						}
					}

					if ( $latest_file ) {
						$filename           = basename( $latest_file );
						$template_thumbnail = $custom_url . $filename;
					} elseif ( file_exists( $template_thumbnail_path ) ) {
						$template_thumbnail = get_site_url( null, '/' ) . trailingslashit( 'template-screenshots' ) . $template->post_name . '.jpg';
					}
				}

				$template_data = [
					'id'             => $template_id,
					'name'           => $template->post_name,
					'title'          => $template->post_title,
					'date'           => $template->post_date,
					'date_formatted' => date( get_option( 'date_format' ), strtotime( $template->post_date ) ),
					'author'         => [
						'name'   => $author_name,
						'avatar' => get_avatar_url( $template->post_author, [ 'size' => 60 ] ),
						'url'    => get_the_author_meta( 'user_url', $template->post_author ),
					],
					'permalink'      => get_permalink( $template_id ),
					'thumbnail'      => $template_thumbnail,
					'bundles'        => $bundles,
					'tags'           => $tags,
					'type'           => self::get_template_type( $template_id ),
				];

				$template_elements = [];
				$area              = false;

				if ( is_array( get_post_meta( $template_id, BRICKS_DB_PAGE_HEADER, true ) ) ) {
					$template_elements       = get_post_meta( $template_id, BRICKS_DB_PAGE_HEADER, true );
					$template_data['header'] = $template_elements;
					$area                    = 'header';
				}

				if ( is_array( get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true ) ) ) {
					$template_elements        = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );
					$template_data['content'] = $template_elements;
					$area                     = 'content';
				}

				if ( is_array( get_post_meta( $template_id, BRICKS_DB_PAGE_FOOTER, true ) ) ) {
					$template_elements       = get_post_meta( $template_id, BRICKS_DB_PAGE_FOOTER, true );
					$template_data['footer'] = $template_elements;
					$area                    = 'footer';
				}

				// Remove 'signature' from remote template element settings
				if ( $area && ! isset( $custom_args['remove_code_signature'] ) ) {
					foreach ( $template_elements as $index => $template_element ) {
						if ( ! empty( $template_element['name'] ) && in_array( $template_element['name'], [ 'code', 'svg' ] ) && isset( $template_element['settings']['signature'] ) ) {
							unset( $template_elements[ $index ]['settings']['signature'] );
						}

						if ( isset( $template_element['settings']['query']['signature'] ) ) {
							unset( $template_elements[ $index ]['settings']['query']['signature'] );
						}
					}

					$template_data[ $area ] = $template_elements;
				}

				$template_page_settings = get_post_meta( $template_id, BRICKS_DB_PAGE_SETTINGS, true );

				if ( $template_page_settings ) {
					$template_data['pageSettings'] = $template_page_settings;
				}

				$templates[] = $template_data;
			}
		}

		// Filter templates
		$templates = apply_filters( 'bricks/get_templates', $templates, $custom_args );

		return $templates;
	}

	/**
	 * Get template authors
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_template_authors() {
		$template_ids = get_posts(
			[
				'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		$template_authors = [];

		foreach ( $template_ids as $template_id ) {
			$template_author_id = get_post_field( 'post_author', $template_id );
			$template_author    = get_the_author_meta( 'display_name', $template_author_id );

			if ( ! in_array( $template_author, $template_authors ) ) {
				$template_authors[] = $template_author;
			}
		}

		// Filter template authors
		$template_authors = apply_filters( 'bricks/get_template_authors', $template_authors );

		return $template_authors;
	}

	/**
	 * Get template bundles
	 *
	 * @since 1.0
	 */
	public static function get_template_bundles() {
		$terms = get_terms(
			[
				'taxonomy' => BRICKS_DB_TEMPLATE_TAX_BUNDLE,
			]
		);

		if ( ! is_array( $terms ) ) {
			return false;
		}

		$template_bundles = [];

		foreach ( $terms as $term ) {
			$term_obj                        = get_term( $term );
			$template_bundles[ $term->slug ] = $term->name;
		}

		// Filter template bundles
		$template_bundles = apply_filters( 'bricks/get_template_bundles', $template_bundles );

		return $template_bundles;
	}

	/**
	 * Get template tags
	 *
	 * @since 1.0
	 */
	public static function get_template_tags() {
		$terms = get_terms( [ 'taxonomy' => BRICKS_DB_TEMPLATE_TAX_TAG ] );

		if ( ! is_array( $terms ) ) {
			return false;
		}

		$template_tags = [];

		foreach ( $terms as $term ) {
			$term_obj                     = get_term( $term );
			$template_tags[ $term->slug ] = $term->name;
		}

		// Filter template bundles
		$template_tags = apply_filters( 'bricks/get_template_tags', $template_tags );

		return $template_tags;
	}

	/**
	 * Get template type via post_meta
	 *
	 * @param int $post_id
	 *
	 * @since 1.0
	 */
	public static function get_template_type( $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$template_type = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, true );

		if ( isset( $template_type ) ) {
			return $template_type;
		}

		// Fallback: Check for content type if no template type post meta found
		if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
			// Check for header template
			$header_template = get_post_meta( $post_id, BRICKS_DB_PAGE_HEADER, true );

			if ( is_array( $header_template ) ) {
				return 'header';
			}

			// Check for content template
			$content_template = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

			if ( is_array( $content_template ) ) {
				return 'content';
			}

			// Check for footer template
			$footer_template = get_post_meta( $post_id, BRICKS_DB_PAGE_FOOTER, true );

			if ( is_array( $footer_template ) ) {
				return 'footer';
			}
		} else {
			// Post type other than bricks_template
			return 'content';
		}

		return;
	}

	/**
	 * Get template by ID
	 *
	 * @since 1.0
	 */
	public static function get_template_by_id( $template_id, $custom_args = [] ) {
		$template_args = [
			'p'           => $template_id,
			'post_status' => 'any',
		];

		$template = self::get_templates( $template_args );

		// Check if template match found
		if ( count( $template ) === 1 ) {
			$template = $template[0];
		} else {
			$template = false;
		}

		return $template;
	}

	/**
	 * Builder: Create template
	 *
	 * @since 1.0
	 */
	public function create_template() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'create_templates' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		$template_data = $_POST['templateData'] ?? [];

		// Insert new template into db
		$insert_post_data = [
			'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
			'post_title'  => ! empty( $template_data['templateTitle'] ) ? esc_html( $template_data['templateTitle'] ) : esc_html__( '(no title)', 'bricks' ),
			'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
		];

		$insert_post_data['tax_input'] = [];

		// Save template bundle term
		if ( isset( $template_data['templateBundle'] ) ) {
			$insert_post_data['tax_input'][ BRICKS_DB_TEMPLATE_TAX_BUNDLE ] = $template_data['templateBundle'];
		}

		// Save template tags
		if ( isset( $template_data['templateTags'] ) ) {
			$insert_post_data['tax_input'][ BRICKS_DB_TEMPLATE_TAX_TAG ] = $template_data['templateTags'];
		}

		$template_id = wp_insert_post( $insert_post_data );

		// Save template type in post meta
		if ( isset( $template_data['templateType'] ) ) {
			update_post_meta(
				$template_id,
				BRICKS_DB_TEMPLATE_TYPE,
				$template_data['templateType']
			);

			// STEP: Handle password protection template population
			if ( $template_data['templateType'] === 'password_protection' ) {
				Password_Protection::populate_template( $template_id );
			}
		}

		$my_templates = self::get_templates(
			[
				'post_status' => 'any',
			]
		);

		wp_send_json_success( $my_templates );
	}

	/**
	 * Builder: Save template
	 *
	 * @since 1.0
	 */
	public function save_template() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		$template_data = Ajax::decode( $_POST['templateData'] ?? [] );

		// Insert new template into database
		$insert_post_data = [
			'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
			'post_title'  => $template_data['templateTitle'] ?? esc_html__( '(no title)', 'bricks' ),
			'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
		];

		$insert_post_data['tax_input'] = [];

		// Save template bundle term
		if ( isset( $template_data['templateBundle'] ) ) {
			$insert_post_data['tax_input'][ BRICKS_DB_TEMPLATE_TAX_BUNDLE ] = $template_data['templateBundle'];
		}

		// Save template tags
		if ( isset( $template_data['templateTags'] ) ) {
			$insert_post_data['tax_input'][ BRICKS_DB_TEMPLATE_TAX_TAG ] = $template_data['templateTags'];
		}

		$template_id = wp_insert_post( $insert_post_data );

		switch ( $template_data['templateType'] ) {
			case 'header':
				$meta_key          = BRICKS_DB_PAGE_HEADER;
				$template_elements = $template_data['header'];
				break;

			case 'footer':
				$meta_key          = BRICKS_DB_PAGE_FOOTER;
				$template_elements = $template_data['footer'];
				break;

			default:
				$meta_key          = BRICKS_DB_PAGE_CONTENT;
				$template_elements = $template_data['content'];
				break;
		}

		// STEP: Generate element IDs (@since 1.9.8)
		$template_elements = Helpers::generate_new_element_ids( $template_elements );

		// Save data
		update_post_meta( $template_id, $meta_key, $template_elements );

		// Save template type
		update_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, $template_data['templateType'] );

		// The post insertion hook runs before the template elements are stored.
		if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
			$area = in_array( $template_data['templateType'], [ 'header', 'footer' ], true ) ? $template_data['templateType'] : 'content';
			Assets_Files::generate_post_css_file( $template_id, $area, wp_unslash( $template_elements ) );
		}

		// Fetch all templates
		$my_templates = self::get_templates(
			[
				'post_status' => 'any',
			]
		);

		wp_send_json_success(
			[
				'templateId'  => $template_id,
				'myTemplates' => $my_templates,
			]
		);
	}

	/**
	 * Builder: Move template to trash
	 *
	 * @since 1.0
	 */
	public function delete_template() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'delete_templates' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		$template_id = ! empty( $_POST['templateId'] ) ? intval( $_POST['templateId'] ) : false;

		// Double-check if user is allowed to delete template
		if ( ! Capabilities::current_user_can_use_builder( $template_id ) ) {
			$my_templates = self::get_templates(
				[
					'post_status' => 'any',
				]
			);

			wp_send_json_success( $my_templates );
		}

		if ( $template_id ) {
			wp_trash_post( $template_id );
		}

		$my_templates = self::get_templates( [ 'post_status' => 'any' ] );

		wp_send_json_success( $my_templates );
	}

	/**
	 * Admin & builder: Import template
	 *
	 * @since 1.0
	 */
	public function import_template() {
		if ( isset( $_POST['builder'] ) ) {
			Ajax::verify_nonce( 'bricks-nonce-builder' );
		} else {
			Ajax::verify_nonce( 'bricks-nonce-admin' );
		}

		if ( ! Builder_Permissions::user_has_permission( 'import_export_templates' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		// Builer only: Import images (@since 1.10.2)
		$import_images = isset( $_POST['importImages'] ) && $_POST['importImages'] == 'true';

		/**
		 * Builder: Get global classes via 'globalClasses'
		 *
		 * @since 1.7.1 - json_decode instead of Ajax::decode to not run wp_slash
		 */
		$global_classes = ! empty( $_POST['globalClasses'] ) ? json_decode( $_POST['globalClasses'] ) : false;

		// Fallback: Get global classes from database
		if ( ! is_array( $global_classes ) || ( is_array( $global_classes ) && ! count( $global_classes ) ) ) {
			$global_classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		}

		$import_color_palette = null;
		if ( isset( $_POST['colorPalette'] ) && Builder_Permissions::user_has_permission( 'edit_color_palettes' ) ) {
			$decoded_color_palette = Ajax::decode( $_POST['colorPalette'], false );
			$import_color_palette  = is_array( $decoded_color_palette ) ? $decoded_color_palette : null;
		}

		$import_global_variables            = null;
		$import_global_variables_categories = null;

		if ( isset( $_POST['globalVariables'] ) && Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			$decoded_global_variables = Ajax::decode( $_POST['globalVariables'], false );

			$import_global_variables = is_array( $decoded_global_variables ) ? $decoded_global_variables : null;

			if ( isset( $_POST['globalVariablesCategories'] ) ) {
				$decoded_global_variables_categories = Ajax::decode( $_POST['globalVariablesCategories'], false );
				$import_global_variables_categories  = is_array( $decoded_global_variables_categories ) ? $decoded_global_variables_categories : null;
			}
		}

		// Load WP_WP_Filesystem for temp file URL access
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';

			WP_Filesystem();
		}

		$templates = [];

		$is_zip_file = isset( $_FILES['files']['name'][0] ) ? pathinfo( $_FILES['files']['name'][0], PATHINFO_EXTENSION ) === 'zip' : false;

		if ( $is_zip_file ) {
			// Check if ZipArchive PHP extension exists
			if ( ! class_exists( '\ZipArchive' ) ) {
				wp_send_json_error( [ 'error' => 'Error: ZipArchive PHP extension does not exist.' ] );
			}

			$zip = new \ZipArchive();

			$wp_upload_dir = wp_upload_dir();

			$temp_path = trailingslashit( $wp_upload_dir['basedir'] ) . BRICKS_TEMP_DIR;

			// Create temp path if it doesn't exist
			wp_mkdir_p( $temp_path );

			if ( isset( $_FILES['files']['tmp_name'][0] ) ) {
				$transfer_templates = Unified_Global_Transfer::extract_templates_from_zip_file( $_FILES['files']['tmp_name'][0] );

				if ( is_array( $transfer_templates ) ) {
					$templates = $transfer_templates;
				} elseif ( is_wp_error( $transfer_templates ) && $transfer_templates->get_error_code() !== 'missing_manifest' ) {
					wp_send_json_error( [ 'error' => $transfer_templates->get_error_message() ] );
				}

				$zip->open( $_FILES['files']['tmp_name'][0] );
			}

			if ( ! count( $templates ) ) {
				// Extract JSON files to temp directory
				$zip->extractTo( $temp_path );
			}

			$zip->close();

			if ( ! count( $templates ) ) {
				// Get all extracted JSON files (exclude '.' system files and reset array with array_values)
				$file_names = array_values( preg_grep( '/^([^.])/', scandir( $temp_path ) ) );

				foreach ( $file_names as $file_name ) {
					$templates[] = json_decode( $wp_filesystem->get_contents( trailingslashit( $temp_path ) . $file_name ), true );

					// Remove JSON file
					unlink( trailingslashit( $temp_path ) . $file_name );
				}
			}

			// Remove temp directory
			rmdir( $temp_path );
		} else {
			// Import single JSON file
			$files = $_FILES['files']['tmp_name'] ?? [];

			foreach ( $files as $file ) {
				$templates[] = json_decode( $wp_filesystem->get_contents( $file ), true );
			}
		}

		$templates = array_values( array_filter( $templates, 'is_array' ) );

		if ( ! count( $templates ) ) {
			wp_send_json_error( [ 'error' => esc_html__( 'No valid templates found.', 'bricks' ) ] );
		}

		// Capture current time to be used as post_date (@since 1.10.2)
		$time_now           = current_time( 'mysql' );
		$time_offset        = 0;
		$all_pseudo_classes = [];

		foreach ( $templates as $template_data ) {
			// Reset template instance data for new template insert
			self::$template_images = [];

			// Ensure each post has at least 1 second difference (@since 1.10.2)
			$post_date = date( 'Y-m-d H:i:s', strtotime( $time_now ) - $time_offset );

			$insert_post_data = [
				'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
				'post_title'  => ! empty( $template_data['title'] ) ? $template_data['title'] : esc_html__( '(no title)', 'bricks' ),
				'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
				'post_date'   => $post_date, // (@since 1.10.2)
			];

			// Template tags (terms)
			if ( ! empty( $template_data['tags'] ) && is_array( $template_data['tags'] ) ) {
				$insert_post_data['tax_input'] = [ BRICKS_DB_TEMPLATE_TAX_TAG => $template_data['tags'] ];
			}

			// Template bundles (terms)
			if ( ! empty( $template_data['bundles'] ) && is_array( $template_data['bundles'] ) ) {
				$insert_post_data['tax_input'] = [ BRICKS_DB_TEMPLATE_TAX_BUNDLE => $template_data['bundles'] ];
			}

			$new_template_id = wp_insert_post( $insert_post_data );

			if ( is_wp_error( $new_template_id ) || ! $new_template_id ) {
				wp_send_json_error( [ 'error' => esc_html__( 'Template could not be imported.', 'bricks' ) ] );
			}

			$area     = 'content';
			$meta_key = BRICKS_DB_PAGE_CONTENT;
			$elements = [];
			$time_offset++; // Increase time offset for next post (@since 1.10.2)

			if ( ! empty( $template_data['templateType'] ) ) {
				update_post_meta( $new_template_id, BRICKS_DB_TEMPLATE_TYPE, $template_data['templateType'] );
			}

			if ( ! empty( $template_data['header'] ) ) {
				$area     = 'header';
				$meta_key = BRICKS_DB_PAGE_HEADER;
			} elseif ( ! empty( $template_data['footer'] ) ) {
				$area     = 'footer';
				$meta_key = BRICKS_DB_PAGE_FOOTER;
			}

			if ( ! empty( $template_data[ $area ] ) ) {
				$elements = $template_data[ $area ];
			}

			if ( isset( $template_data['pageSettings'] ) ) {
				update_post_meta( $new_template_id, BRICKS_DB_PAGE_SETTINGS, $template_data['pageSettings'] );
			}

			// Add template settings (@since 1.8.1)
			$template_settings = $template_data['templateSettings'] ?? false;
			if ( is_array( $template_settings ) ) {
				// Remove template preview post ID if it's not a single post (@since 1.10.2)
				$template_preview_post_id = $template_settings['templatePreviewPostId'] ?? 0;
				if ( $template_preview_post_id && ! is_single( $template_preview_post_id ) ) {
					unset( $template_settings['templatePreviewPostId'] );
				}

				// Remove templatePreviewTerm if term ID doesn't exist (@since 1.10.2)
				$template_preview_term = $template_settings['templatePreviewTerm'] ?? 0;
				if ( $template_preview_term ) {
					$preview_term     = $template_preview_term ? explode( '::', $template_preview_term ) : [];
					$preview_taxonomy = isset( $preview_term[0] ) ? $preview_term[0] : '';
					$preview_term_id  = isset( $preview_term[1] ) ? intval( $preview_term[1] ) : '';
					if ( ! term_exists( $preview_term_id, $preview_taxonomy ) ) {
						unset( $template_settings['templatePreviewTerm'] );
					}
				}

				// Remove templatePreviewAuthor if author ID doesn't exist (@since 1.10.2)
				$template_preview_author = $template_settings['templatePreviewAuthor'] ?? 0;
				if ( $template_preview_author && ! get_user_by( 'id', $template_preview_author ) ) {
					unset( $template_settings['templatePreviewAuthor'] );
				}

				// Remove templatePreviewPostType if post type doesn't exist (@since 1.10.2)
				$template_preview_post_type = $template_settings['templatePreviewPostType'] ?? '';
				if ( $template_preview_post_type && ! post_type_exists( $template_preview_post_type ) ) {
					unset( $template_settings['templatePreviewPostType'] );
				}

				Helpers::set_template_settings( $new_template_id, $template_settings );
			}

			// STEP: Add global classes used in template to global classes in this database
			$template_global_classes         = ! empty( $template_data['global_classes'] ) ? $template_data['global_classes'] : [];
			$map_classes                     = []; // @see PopupTemplates.vue (@since 1.5.1)
			$maybe_pseudo_class_setting_keys = [];

			foreach ( $template_global_classes as $template_class ) {
				// STEP: Add template setting keys to create missing pseudo class from (@since 1.7.1)
				if ( ! empty( $template_class['settings'] ) ) {
					$maybe_pseudo_class_setting_keys = array_merge( $maybe_pseudo_class_setting_keys, array_keys( $template_class['settings'] ) );
				}

				// Skip: Class with same unique 'id' exists locally
				$class_index = array_search( $template_class['id'], array_column( $global_classes, 'id' ) );

				if ( $class_index !== false ) {
					continue;
				}

				// Add to map_classes, then skip (global class with this 'name' already exists in this installation)
				$class_index = array_search( $template_class['name'], array_column( $global_classes, 'name' ) );

				if ( $class_index !== false ) {
					$map_classes[ $template_class['id'] ] = $global_classes[ $class_index ]['id'];

					continue;
				}

				// Update global classes in database
				$global_classes[] = $template_class;
			}

			// Replace imported class IDs with local class IDs in template elements and embedded components.
			if ( count( $map_classes ) ) {
				self::remap_global_class_ids_in_template_elements( $elements, $map_classes );
				self::remap_global_class_ids_in_template_components( $template_data, $map_classes );
			}

			self::import_components_from_template_data( $template_data );

			// STEP: Update global classes in db
			$global_classes_response = Helpers::save_global_classes_in_db( $global_classes );

			// STEP: Save final template elements
			$elements = Helpers::sanitize_bricks_data( $elements );

			// Add back slashes to element settings (needed for '_content' HTML entities, and Custom CSS)
			foreach ( $elements as $index => $element ) {
				$element_settings = ! empty( $element['settings'] ) ? $element['settings'] : [];
				$element_name     = ! empty( $element['name'] ) ? $element['name'] : '';

				// STEP: Import element images & update template data with local image data. (@since 1.10.2) Should run even if importImages is untick (@since 1.12.2)
				if ( count( $element_settings ) ) {

					// Handle image-gallery element (@since 1.12.2)
					if ( $element_name === 'image-gallery' ) {
						// The images are stored in the 'images' setting key
						$images = $element_settings['items']['images'] ?? [];
						$size   = $element_settings['items']['size'] ?? 'full';

						if ( count( $images ) ) {
							foreach ( $images as $image_index => $image ) {
								// import_image requires size to be set
								$image['size'] = $size;

								$new_image = self::import_image( $image, $import_images );

								if ( is_array( $new_image ) && ! isset( $new_image['error'] ) ) {
									// Directly update the image in the element settings as str_replace doesn't work here
									$elements[ $index ]['settings']['items']['images'][ $image_index ] = $new_image;
								}
							}
						}
					}

					// Handle Image and SVG element (single image) (@since 1.12.2)
					else {
						self::import_images( $element_settings, $import_images );
					}
				}

				foreach ( $element_settings as $setting_key => $setting_value ) {
					if ( is_string( $setting_value ) ) {
						$elements[ $index ]['settings'][ $setting_key ] = addslashes( $setting_value );
					}
				}
			}

			// STEP: Generate element IDs (@since 1.9.8)
			$elements = Helpers::generate_new_element_ids( $elements );

			// STEP: Replace remote image data with imported/existing image data (@since 1.10.2)
			if ( count( self::$template_images ) ) {
				$elements_encoded = wp_json_encode( $elements );

				foreach ( self::$template_images as $template_image ) {
					$elements_encoded = str_replace(
						wp_json_encode( $template_image['old'] ),
						wp_json_encode( $template_image['new'] ),
						$elements_encoded
					);
				}

				$elements = json_decode( $elements_encoded, true );
			}

			update_post_meta( $new_template_id, $meta_key, $elements );

			// STEP: Generate CSS file for imported template
			if ( Database::get_setting( 'cssLoading' ) === 'file' && $elements ) {
				$template_css_file_name = Assets_Files::generate_post_css_file( $new_template_id, $area, $elements );
			}

			// STEP: Add pseudo elements & classes used in the template to the database (@since 1.7.1)

			// Get latest pseudo classes from builder. The WP admin import form does not submit pseudoClasses,
			// so fall back to the existing site value instead of replacing it with only pseudos found in the import.
			$default_pseudo_classes = [
				':hover',
				':active',
				':focus',
			];

			$pseudo_classes = ! empty( $_POST['pseudoClasses'] )
				? Ajax::decode( $_POST['pseudoClasses'], false )
				: ( Database::$global_data['pseudoClasses'] ?? $default_pseudo_classes );

			// Ensure malformed saved data cannot overwrite the default pseudo classes during import.
			if ( ! is_array( $pseudo_classes ) ) {
				$pseudo_classes = $default_pseudo_classes;
			}

			// Preserve pseudo classes discovered in previously imported templates.
			$pseudo_classes = array_unique( array_merge( $pseudo_classes, $all_pseudo_classes ) );

			// Add element setting keys to create missing pseudo class from
			foreach ( $elements as $element ) {
				if ( ! empty( $element['settings'] ) ) {
					$maybe_pseudo_class_setting_keys = array_merge( $maybe_pseudo_class_setting_keys, array_keys( $element['settings'] ) );
				}
			}

			$all_pseudo_classes = self::template_import_create_missing_pseudo_classes( $pseudo_classes, $maybe_pseudo_class_setting_keys );
			$all_pseudo_classes = array_unique( $all_pseudo_classes );

			// Update pseudo classes db entry (if we got more items than before)
			if ( count( $all_pseudo_classes ) > count( $pseudo_classes ) ) {
				update_option( BRICKS_DB_PSEUDO_CLASSES, $all_pseudo_classes );
			}
		}

		if ( is_array( $import_color_palette ) ) {
			if ( count( $import_color_palette ) ) {
				update_option( BRICKS_DB_COLOR_PALETTE, $import_color_palette );
			} else {
				delete_option( BRICKS_DB_COLOR_PALETTE );
			}

			Ajax::generate_color_palette_css_file( $import_color_palette );
		}

		if ( is_array( $import_global_variables ) ) {
			Helpers::save_global_variables_in_db( $import_global_variables );

			if ( is_array( $import_global_variables_categories ) ) {
				if ( count( $import_global_variables_categories ) ) {
					update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $import_global_variables_categories, false );
				} else {
					delete_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES );
				}
			}

			Ajax::generate_style_manager_css_file();
		}

		if ( isset( $_POST['themeStyles'] ) && Builder_Permissions::user_has_permission( 'access_theme_styles' ) ) {
			$incoming_styles = Ajax::decode( $_POST['themeStyles'], false );
			$theme_styles    = (array) get_option( BRICKS_DB_THEME_STYLES, [] );

			foreach ( (array) $incoming_styles as $id => $style ) {
				if ( ! isset( $theme_styles[ $id ] ) && is_array( $style ) && is_array( $style['settings'] ?? null ) ) {
					unset( $style['settings']['conditions'] );
					$theme_styles[ $id ] = $style;
				}
			}

			update_option( BRICKS_DB_THEME_STYLES, $theme_styles );
		}

		$my_templates = self::get_templates(
			[
				'post_status' => 'any',
			]
		);

		wp_send_json_success(
			[
				'my_templates'   => $my_templates,
				'global_classes' => $global_classes,
				'pseudo_classes' => $all_pseudo_classes,
			]
		);
	}

	/**
	 * STEP: Check global class setting key for occurence of pseudo element to create pseudo element in local installtion
	 *
	 * @since 1.7.1
	 */
	public static function template_import_create_missing_pseudo_classes( $pseudo_classes, $setting_keys = [] ) {
		// Get valid pseudo elements & pseudo classes from Helper method (@since 1.12)
		$valid_pseudo_elements = Helpers::get_valid_pseudo_elements();
		$valid_pseudo_classes  = Helpers::get_valid_pseudo_classes();

		// Merge pseudo elements & pseudo classes, as we need to check for both anyway (@since 1.12)
		$allowed_pseudo = array_merge( $valid_pseudo_classes, $valid_pseudo_elements );

		// Loop over all settings keys to find pseudo classes & pseudo elements
		// Format is <settingKey>:<breakpoint>:<pseudoClass>, and only the settingKey is mandatory
		foreach ( $setting_keys as $setting_key ) {
			$parts = explode( ':', $setting_key, 2 );

			if ( count( $parts ) > 1 ) {
				$secondPart = $parts[1];

				// Check if second part starts with allowed_pseudo
				if ( self::is_pseudo_class( ':' . $secondPart, $allowed_pseudo ) ) {
					$pseudo_classes[] = ':' . $secondPart;
				}

				// If it's not, it might be a breakpoint, so try to split it again
				else {
					$parts = explode( ':', $secondPart, 2 );

					if ( count( $parts ) > 1 ) {
						$secondPart = $parts[1];

						// Check if second part starts with allowed_pseudo
						// If it's not, then it's not a pseudo class/element, or not allowed one.
						if ( self::is_pseudo_class( ':' . $secondPart, $allowed_pseudo ) ) {
							$pseudo_classes[] = ':' . $secondPart;
						}
					}
				}
			}
		}

		// Distinct pseudo classes
		$pseudo_classes = array_unique( $pseudo_classes );

		// Convert to array
		$pseudo_classes = array_values( $pseudo_classes );

		return $pseudo_classes;
	}

	/**
	 * Check if input is a pseudo class/element
	 *
	 * Use this one to distinguish between pseudo classes/elements and breakpoints
	 *
	 * @since 1.12
	 */
	private static function is_pseudo_class( $possible_pseudo, $allowed_pseudo_classes ) {
		foreach ( $allowed_pseudo_classes as $allowed ) {
			// Check if pseudo class is at the beginning of the string
			if ( strpos( $possible_pseudo, $allowed ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Export template as JSON file
	 *
	 * @param int $template_id Provided if bulk action export.
	 * @see: admin.php:export_templates()
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function export_template( $template_id = 0 ) {
		// No template_id passed: Admin or builder call
		if ( ! $template_id ) {
			if ( isset( $_GET['builder'] ) ) {
				Ajax::verify_nonce( 'bricks-nonce-builder' );
			} else {
				Ajax::verify_nonce( 'bricks-nonce-admin' );
			}

			$template_id = isset( $_GET['templateId'] ) ? intval( $_GET['templateId'] ) : 0;
		}

		if ( isset( $_GET['builder'] ) && ! Builder_Permissions::user_has_permission( 'import_export_templates' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		if ( ! $template_id ) {
			wp_send_json_error( 'export_template:error: no templateId provided' );
		}

		$template_data = self::get_template_by_id( $template_id );

		if ( $template_data === false ) {
			if ( bricks_is_ajax_call() ) {
				wp_send_json_error( 'export_template:error: template not found' );
			}

			return [];
		}

		$template_type = get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true );

		$template_data['templateType'] = $template_type;

		/**
		 * STEP: Add global CSS classes used in template to template data (so it can later be imported as well)
		 *
		 * @since 1.4
		 */
		if ( $template_type === 'header' || $template_type === 'footer' ) {
			$template_elements = isset( $template_data[ $template_type ] ) ? $template_data[ $template_type ] : [];
		} else {
			$template_elements = isset( $template_data['content'] ) ? $template_data['content'] : [];
		}

		/**
		 * STEP: Add template settings to template data
		 *
		 * NOTE: Should we remove 'templatePreview...' settings too?
		 *
		 * @since 1.8.1
		 */
		$template_settings = Helpers::get_template_settings( $template_id );

		// Remove template conditions
		if ( isset( $template_settings['templateConditions'] ) ) {
			unset( $template_settings['templateConditions'] );
		}

		// Save as templateSettings, to be imported later
		if ( is_array( $template_settings ) && ! empty( $template_settings ) ) {
			$template_data['templateSettings'] = $template_settings;
		}

		$template_components = self::get_components_for_template_elements( $template_elements );

		if ( count( $template_components ) ) {
			$template_data['components'] = $template_components;
		}

		$template_classes = self::collect_global_class_ids_for_template_export( $template_elements, $template_components );

		// Add class definition to template data
		$global_classes        = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$global_classes_to_add = [];

		foreach ( $global_classes as $global_class ) {
			if ( in_array( $global_class['id'], $template_classes ) ) {
				$global_classes_to_add[] = $global_class;
			}
		}

		if ( count( $global_classes_to_add ) ) {
			// Add category metadata before adding to template data (@since 1.12.2)
			$global_classes_to_add           = Helpers::add_category_metadata_to_classes( $global_classes_to_add );
			$template_data['global_classes'] = $global_classes_to_add;
		}

		// Add all global variables to template data (@since 1.9.8)
		$global_variables = ! Database::get_setting( 'disableVariablesManager', false ) ? get_option( BRICKS_DB_GLOBAL_VARIABLES, [] ) : [];
		if ( count( $global_variables ) ) {
			$template_data['globalVariables'] = $global_variables;
		}

		// Add all global variables categories to template data (@since 1.9.8)
		$global_variables_categories = ! Database::get_setting( 'disableVariablesManager', false ) ? get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] ) : [];
		if ( count( $global_variables_categories ) ) {
			$template_data['globalVariablesCategories'] = $global_variables_categories;
		}

		// Add color palettes used by the exported template data (@since 2.4)
		$used_color_ids = self::get_template_used_color_ids( $template_data );

		if ( count( $used_color_ids ) ) {
			$color_palettes = get_option( BRICKS_DB_COLOR_PALETTE, [] );

			if ( is_array( $color_palettes ) && count( $color_palettes ) ) {
				$template_color_palettes = array_values(
					array_filter(
						$color_palettes,
						function( $palette ) use ( $used_color_ids ) {
							if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
								return false;
							}

							foreach ( $palette['colors'] as $color ) {
								if ( ! empty( $color['id'] ) && in_array( $color['id'], $used_color_ids, true ) ) {
									return true;
								}
							}

							return false;
						}
					)
				);

				if ( count( $template_color_palettes ) ) {
					$template_data['colorPalette'] = $template_color_palettes;
				}
			}
		}

		// Add style manager settings to template data (@since 2.4)
		$style_manager        = get_option( BRICKS_DB_STYLE_MANAGER, [] );
		$style_manager_export = [];

		if ( ! empty( $style_manager ) && is_array( $style_manager ) ) {
			if ( isset( $style_manager['htmlFontSize'] ) ) {
				$style_manager_export['htmlFontSize'] = $style_manager['htmlFontSize'];
			}

			if ( isset( $style_manager['minScreenWidth'] ) ) {
				$style_manager_export['minScreenWidth'] = $style_manager['minScreenWidth'];
			}

			if ( isset( $style_manager['maxScreenWidth'] ) ) {
				$style_manager_export['maxScreenWidth'] = $style_manager['maxScreenWidth'];
			}
		}

		if ( count( $style_manager_export ) ) {
			$template_data['styleManager'] = $style_manager_export;
		}

		// Lowercase
		$file_name = ! empty( $template_data['title'] ) ? strtolower( $template_data['title'] ) : 'no-title';

		// Make alphanumeric (removes all other characters)
		$file_name = preg_replace( '/[^a-z0-9_\s-]/', '', $file_name );

		// Clean up multiple dashes or whitespaces
		$file_name = preg_replace( '/[\s-]+/', ' ', $file_name );

		// Convert whitespaces and underscore to dashes
		$file_name = preg_replace( '/[\s_]/', '-', $file_name );

		// Final file name
		$file_name = 'template-' . $file_name . '-' . date( 'Y-m-d' ) . '.json';

		if ( bricks_is_ajax_call() ) {
			// Download individual template
			header( 'Content-Type:application/json; charset=utf-8' );
			header( "Content-Disposition: attachment; filename=$file_name" );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Expires: 0' );
			header( 'Pragma: public' );

			// Disable zlib compression to avoid empty template (#31nepuc @since 1.6)
			ini_set( 'zlib.output_compression', '0' );

			@ob_end_flush();

			echo wp_json_encode( $template_data );
			die;
		} else {
			// Bulk action: Export
			return [
				'name'    => $file_name,
				'content' => wp_json_encode( $template_data ),
			];
		}
	}

	/**
	 * Get component definitions referenced by template elements.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Template elements.
	 * @return array
	 */
	public static function get_components_for_template_elements( $elements ) {
		$components       = [];
		$all_components   = get_option( BRICKS_DB_COMPONENTS, [] );
		$all_components   = is_array( $all_components ) ? $all_components : [];
		$component_lookup = [];
		$component_ids    = self::collect_component_ids_from_elements( $elements );
		$seen_ids         = [];

		foreach ( $all_components as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$component_lookup[ (string) $component['id'] ] = $component;
			}
		}

		while ( ! empty( $component_ids ) ) {
			$component_id = array_shift( $component_ids );

			if ( isset( $seen_ids[ $component_id ] ) || empty( $component_lookup[ $component_id ] ) ) {
				continue;
			}

			$seen_ids[ $component_id ] = true;
			$component                 = $component_lookup[ $component_id ];
			$components[]              = $component;
			$nested_component_ids      = self::collect_component_ids_from_elements( $component['elements'] ?? [] );

			foreach ( $nested_component_ids as $nested_component_id ) {
				if ( ! isset( $seen_ids[ $nested_component_id ] ) ) {
					$component_ids[] = $nested_component_id;
				}
			}

			$component_ids = array_values( array_unique( $component_ids ) );
		}

		return self::unique_components_by_id( $components );
	}

	/**
	 * Import component definitions embedded in template data.
	 *
	 * @since 2.4
	 *
	 * @param array $template_data Template data.
	 * @return array
	 */
	public static function import_components_from_template_data( &$template_data ) {
		if ( ! is_array( $template_data ) || ! is_array( $template_data['components'] ?? null ) ) {
			return [];
		}

		$components                  = Components::upgrade_components( $template_data['components'], true );
		$components                  = self::unique_components_by_id( $components );
		$template_data['components'] = $components;

		if ( empty( $components ) ) {
			return [];
		}

		$local   = get_option( BRICKS_DB_COMPONENTS, [] );
		$local   = is_array( $local ) ? $local : [];
		$updated = self::upsert_components( $local, $components, 'skip' );

		if ( self::normalize_for_compare( $local ) !== self::normalize_for_compare( $updated ) ) {
			update_option( BRICKS_DB_COMPONENTS, array_values( $updated ) );
			Database::$global_data['components'] = array_values( $updated );
		}

		return $components;
	}

	/**
	 * Remap global class IDs in embedded template component definitions.
	 *
	 * @since 2.4
	 *
	 * @param array $template_data Template data.
	 * @param array $class_id_map  Old-to-new class ID map.
	 */
	public static function remap_global_class_ids_in_template_components( &$template_data, $class_id_map ) {
		if ( ! is_array( $template_data ) || empty( $class_id_map ) || ! is_array( $template_data['components'] ?? null ) ) {
			return;
		}

		self::remap_global_class_ids_in_components( $template_data['components'], $class_id_map );
	}

	/**
	 * Remap global class IDs in template elements.
	 *
	 * @since 2.4
	 *
	 * @param array $elements      Template elements.
	 * @param array $class_id_map  Old-to-new class ID map.
	 */
	public static function remap_global_class_ids_in_template_elements( &$elements, $class_id_map ) {
		if ( empty( $class_id_map ) || ! is_array( $elements ) ) {
			return;
		}

		self::remap_global_class_ids_in_elements( $elements, $class_id_map, [], true );
	}

	/**
	 * Collect component IDs from element data.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element rows.
	 * @return array
	 */
	private static function collect_component_ids_from_elements( $elements ) {
		$ids = [];

		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['cid'] ) ) {
				$ids[] = (string) $element['cid'];
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, self::collect_component_ids_from_elements( $element['elements'] ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Remove duplicate component rows by ID.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return array
	 */
	private static function unique_components_by_id( $components ) {
		$unique = [];

		foreach ( (array) $components as $component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( ! $component_id ) {
				continue;
			}

			$unique[ $component_id ] = $component;
		}

		return array_values( $unique );
	}

	/**
	 * Merge component rows into local components.
	 *
	 * @since 2.4
	 *
	 * @param array  $local         Local component rows.
	 * @param array  $components    Incoming component rows.
	 * @param string $conflict_mode Conflict mode.
	 * @return array
	 */
	private static function upsert_components( $local, $components, $conflict_mode ) {
		foreach ( (array) $components as $component ) {
			if ( empty( $component ) || ! is_array( $component ) ) {
				continue;
			}

			$existing_index = self::find_component_index_by_id( $local, $component['id'] ?? '' );

			if ( $existing_index !== null ) {
				if ( $conflict_mode === 'replace' ) {
					$local[ $existing_index ] = $component;
				}

				continue;
			}

			$local[] = $component;
		}

		return array_values( $local );
	}

	/**
	 * Find a component row index by ID.
	 *
	 * @since 2.4
	 *
	 * @param array  $components   Component rows.
	 * @param string $component_id Component ID.
	 * @return int|null
	 */
	private static function find_component_index_by_id( $components, $component_id ) {
		foreach ( (array) $components as $index => $component ) {
			if ( (string) ( $component['id'] ?? '' ) === (string) $component_id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Collect global class IDs needed by a template export.
	 *
	 * @since 2.4
	 *
	 * @param array $elements   Template elements.
	 * @param array $components Referenced component rows.
	 * @return array
	 */
	private static function collect_global_class_ids_for_template_export( $elements, $components = [] ) {
		$class_ids = self::collect_global_class_ids_from_elements( $elements );

		foreach ( $components as $component ) {
			$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_elements( $component['elements'] ?? [] ) );
		}

		$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_properties( $components ) );
		$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_instances( $elements, $components ) );

		return array_values( array_unique( array_filter( array_map( 'strval', $class_ids ) ) ) );
	}

	/**
	 * Collect global class IDs from elements.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element rows.
	 * @return array
	 */
	private static function collect_global_class_ids_from_elements( $elements ) {
		$class_ids = [];

		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings']['_cssGlobalClasses'] ) ) {
				$class_ids = array_merge( $class_ids, self::normalize_id_list( $element['settings']['_cssGlobalClasses'] ) );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_elements( $element['elements'] ) );
			}
		}

		return $class_ids;
	}

	/**
	 * Collect class IDs from component property definitions.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return array
	 */
	private static function collect_global_class_ids_from_component_properties( $components ) {
		$class_ids = [];

		foreach ( (array) $components as $component ) {
			foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
				if ( ( $property['type'] ?? '' ) !== 'class' ) {
					continue;
				}

				$class_ids = array_merge( $class_ids, self::normalize_id_list( $property['default'] ?? [] ) );

				foreach ( (array) ( $property['options'] ?? [] ) as $option ) {
					$class_ids = array_merge( $class_ids, self::normalize_id_list( $option['value'] ?? [] ) );
				}
			}
		}

		return $class_ids;
	}

	/**
	 * Collect class IDs from component instance property values.
	 *
	 * @since 2.4
	 *
	 * @param array $elements   Template elements.
	 * @param array $components Referenced component rows.
	 * @return array
	 */
	private static function collect_global_class_ids_from_component_instances( $elements, $components ) {
		$class_ids        = [];
		$component_lookup = [];

		foreach ( (array) $components as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$component_lookup[ (string) $component['id'] ] = $component;
			}
		}

		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['cid'] ) && ! empty( $element['properties'] ) ) {
				$component = $component_lookup[ (string) $element['cid'] ] ?? null;

				if ( $component ) {
					foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
						if ( ( $property['type'] ?? '' ) !== 'class' || empty( $property['id'] ) ) {
							continue;
						}

						$property_value = $element['properties'][ $property['id'] ] ?? null;

						$class_ids = array_merge( $class_ids, self::normalize_id_list( $property_value ) );

						foreach ( (array) ( $property['options'] ?? [] ) as $option ) {
							if ( in_array( (string) ( $option['id'] ?? '' ), self::normalize_id_list( $property_value ), true ) ) {
								$class_ids = array_merge( $class_ids, self::normalize_id_list( $option['value'] ?? [] ) );
							}
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$class_ids = array_merge( $class_ids, self::collect_global_class_ids_from_component_instances( $element['elements'], $components ) );
			}
		}

		return $class_ids;
	}

	/**
	 * Normalize a scalar or array ID value.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value ID value.
	 * @return array
	 */
	private static function normalize_id_list( $value ) {
		if ( empty( $value ) ) {
			return [];
		}

		return array_map( 'strval', is_array( $value ) ? array_values( $value ) : [ $value ] );
	}

	/**
	 * Remap class IDs inside component definitions.
	 *
	 * @since 2.4
	 *
	 * @param array $components   Component rows.
	 * @param array $class_id_map Old-to-new class ID map.
	 */
	private static function remap_global_class_ids_in_components( &$components, $class_id_map ) {
		if ( ! is_array( $components ) || empty( $class_id_map ) ) {
			return;
		}

		foreach ( $components as &$component ) {
			if ( ! empty( $component['properties'] ) && is_array( $component['properties'] ) ) {
				self::remap_global_class_ids_in_component_properties( $component['properties'], $class_id_map );
			}
		}

		unset( $component );

		$component_property_lookup = self::build_component_class_property_lookup( $components );

		foreach ( $components as &$component ) {
			if ( ! empty( $component['elements'] ) && is_array( $component['elements'] ) ) {
				self::remap_global_class_ids_in_elements( $component['elements'], $class_id_map, $component_property_lookup );
			}
		}

		unset( $component );
	}

	/**
	 * Remap class IDs inside component property definitions.
	 *
	 * @since 2.4
	 *
	 * @param array $properties   Component properties.
	 * @param array $class_id_map Old-to-new class ID map.
	 */
	private static function remap_global_class_ids_in_component_properties( &$properties, $class_id_map ) {
		foreach ( $properties as &$property ) {
			if ( ( $property['type'] ?? '' ) !== 'class' ) {
				continue;
			}

			foreach ( [ 'default', 'value' ] as $property_key ) {
				if ( isset( $property[ $property_key ] ) ) {
					$property[ $property_key ] = self::remap_global_class_id_value( $property[ $property_key ], $class_id_map );
				}
			}

			if ( ! empty( $property['options'] ) && is_array( $property['options'] ) ) {
				foreach ( $property['options'] as &$option ) {
					if ( isset( $option['value'] ) ) {
						$option['value'] = self::remap_global_class_id_value( $option['value'], $class_id_map );
					}
				}

				unset( $option );
			}
		}

		unset( $property );
	}

	/**
	 * Remap class IDs inside element rows.
	 *
	 * @since 2.4
	 *
	 * @param array $elements                  Element rows.
	 * @param array $class_id_map              Old-to-new class ID map.
	 * @param array $component_property_lookup Component class property lookup.
	 * @param bool  $remap_all_properties      Whether to remap all instance property values.
	 */
	private static function remap_global_class_ids_in_elements( &$elements, $class_id_map, $component_property_lookup = [], $remap_all_properties = false ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				self::remap_global_class_ids_in_settings( $element['settings'], $class_id_map );
			}

			$component_id       = (string) ( $element['cid'] ?? '' );
			$class_property_ids = $component_property_lookup[ $component_id ] ?? [];

			if ( $remap_all_properties && ! empty( $element['properties'] ) && is_array( $element['properties'] ) ) {
				$element['properties'] = self::remap_global_class_id_value( $element['properties'], $class_id_map );
			} elseif ( ! empty( $class_property_ids ) && ! empty( $element['properties'] ) && is_array( $element['properties'] ) ) {
				foreach ( $class_property_ids as $property_id ) {
					if ( isset( $element['properties'][ $property_id ] ) ) {
						$element['properties'][ $property_id ] = self::remap_global_class_id_value( $element['properties'][ $property_id ], $class_id_map );
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::remap_global_class_ids_in_elements( $element['elements'], $class_id_map, $component_property_lookup, $remap_all_properties );
			}
		}

		unset( $element );
	}

	/**
	 * Remap class IDs in element settings.
	 *
	 * @since 2.4
	 *
	 * @param array $settings     Element settings.
	 * @param array $class_id_map Old-to-new class ID map.
	 */
	private static function remap_global_class_ids_in_settings( &$settings, $class_id_map ) {
		foreach ( [ '_cssGlobalClasses', '_cssGlobalClassesProps' ] as $setting_key ) {
			if ( isset( $settings[ $setting_key ] ) ) {
				$settings[ $setting_key ] = self::remap_global_class_id_value( $settings[ $setting_key ], $class_id_map );
			}
		}
	}

	/**
	 * Remap class ID values recursively.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value        Value.
	 * @param array $class_id_map Old-to-new class ID map.
	 * @return mixed
	 */
	private static function remap_global_class_id_value( $value, $class_id_map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::remap_global_class_id_value( $item, $class_id_map );
			}

			return $value;
		}

		$value_key = (string) $value;

		return isset( $class_id_map[ $value_key ] ) ? $class_id_map[ $value_key ] : $value;
	}

	/**
	 * Build component class-property lookup.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return array
	 */
	private static function build_component_class_property_lookup( $components ) {
		$lookup = [];

		foreach ( (array) $components as $component ) {
			$component_id = (string) ( $component['id'] ?? '' );

			if ( ! $component_id ) {
				continue;
			}

			$lookup[ $component_id ] = [];

			foreach ( (array) ( $component['properties'] ?? [] ) as $property ) {
				if ( ( $property['type'] ?? '' ) === 'class' && ! empty( $property['id'] ) ) {
					$lookup[ $component_id ][] = (string) $property['id'];
				}
			}
		}

		return $lookup;
	}

	/**
	 * Normalize data for comparison.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function normalize_for_compare( $value ) {
		return wp_json_encode( $value );
	}

	/**
	 * Get all color IDs used in template export data.
	 *
	 * @param array $template_data Template export data.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_template_used_color_ids( $template_data ) {
		$color_ids = [];

		self::collect_template_used_color_ids( $template_data, $color_ids );

		return array_values( array_unique( $color_ids ) );
	}

	/**
	 * Recursively collect color IDs from Bricks color setting arrays.
	 *
	 * @param mixed $data      Data to inspect.
	 * @param array $color_ids Color IDs.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public static function collect_template_used_color_ids( $data, &$color_ids ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		$is_color = ! empty( $data['id'] ) && (
			isset( $data['hex'] ) ||
			isset( $data['rgb'] ) ||
			isset( $data['hsl'] ) ||
			isset( $data['raw'] ) ||
			isset( $data['light'] ) ||
			isset( $data['dark'] )
		);

		if ( $is_color ) {
			$color_ids[] = $data['id'];

			return;
		}

		foreach ( $data as $value ) {
			self::collect_template_used_color_ids( $value, $color_ids );
		}
	}

	/**
	 * Check if setting value has image/svg properties
	 *
	 * @since 1.3.2
	 */
	public static function is_image( $setting ) {
		if ( ! is_array( $setting ) || empty( $setting['url'] ) ) {
			return false;
		}

		return (
			isset( $setting['id'] ) &&
			isset( $setting['size'] ) &&
			isset( $setting['full'] )
		) || self::is_svg_url( $setting['url'] );
	}

	/**
	 * Check if a URL points to an SVG file.
	 *
	 * @since 2.3.10
	 */
	public static function is_svg_url( $url ) {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );

		return $url_path && strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) ) === 'svg';
	}

	/**
	 * Check if an image setting uses an external SVG URL.
	 *
	 * @since 2.3.10
	 */
	public static function is_external_svg_image( $image ) {
		return (
			is_array( $image ) &&
			! empty( $image['external'] ) &&
			! empty( $image['url'] ) &&
			self::is_svg_url( $image['url'] )
		);
	}

	/**
	 * Recursive function: Import remote element images from template data
	 *
	 * @since 1.3.2
	 */
	public static function import_images( $settings, $import_images ) {
		foreach ( $settings as $key => $value ) {
			if ( self::is_image( $value ) ) {
				self::import_image( $value, $import_images );
			} elseif ( is_array( $value ) ) {
				self::import_images( $value, $import_images );
			}
		}
	}

	/**
	 * Import one remote image into the media library.
	 *
	 * @since 2.4
	 *
	 * @param array $image            Remote image data.
	 * @param bool  $import_images    Whether to download the image.
	 * @param array $download_options Optional response limits.
	 * @return array
	 */
	public static function import_image( $image, $import_images, $download_options = [] ) {
		if ( ! $image ) {
			return [ 'error' => 'No image provided.' ];
		}

		if ( ! isset( $image['url'] ) ) {
			return [ 'error' => 'No image URL provided.' ];
		}

		// Check if SVG (SVG has no 'full' and 'size' attributes)
		$is_svg = self::is_svg_url( $image['url'] );

		// Preserve external SVG URLs instead of replacing/importing them.
		if ( self::is_external_svg_image( $image ) ) {
			return $image;
		}

		// STEP: No image import requested: Return placeholder image
		if ( ! $import_images ) {
			// Add to instance property to replace templateData before returning it to Vue
			$placeholder_image = [
				'url'           => Builder::get_template_placeholder_image( $is_svg ),
				'full'          => Builder::get_template_placeholder_image( $is_svg ),
				'isPlaceholder' => true,
			];

			// Add placeholder image path. For SVG elements (@since 1.12.2)
			if ( $is_svg ) {
				$placeholder_image['path'] = Builder::get_template_placeholder_image( $is_svg, 'path' );
			}

			self::$template_images[] = [
				'old' => $image,
				'new' => $placeholder_image,
			];

			return $placeholder_image;
		}

		// Not allowed to upload SVG: Remove 'file' value
		elseif ( $import_images && $is_svg && ! Capabilities::current_user_can_upload_svg() && ! empty( $image['url'] ) ) {
			// Use placeholder SVG image instead. (@since 1.12.2)
			$svg_blank = [
				'url'  => Builder::get_template_placeholder_image( true ),
				'full' => Builder::get_template_placeholder_image( true ),
				'path' => Builder::get_template_placeholder_image( true, 'path' ),
			];

			self::$template_images[] = [
				'old' => $image,
				'new' => $svg_blank,
			];
		}

		if ( ! isset( $image['id'] ) ) {
			return [ 'error' => 'No image ID provided (i.e. it is a placeholder image).' ];
		}

		if ( ! $is_svg && ! isset( $image['full'] ) ) {
			return [ 'error' => 'No full URL provided.' ];
		}

		if ( ! $is_svg && ! isset( $image['size'] ) ) {
			return [ 'error' => 'No image size provided.' ];
		}

		$image_size     = $is_svg ? 'full' : $image['size'];
		$image_full_url = $is_svg ? $image['url'] : $image['full'];
		$filename       = basename( $image_full_url );

		// Check if image has been downloaded before (by post meta '_bricks_image_origin_url' against image 'full' URL)
		global $wpdb;

		// Return existing image ID if match found in db
		$existing_image_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_bricks_image_origin_url' AND meta_value = %s", $image_full_url ) );

		// Check for existing image ID by URL
		if ( ! $existing_image_id ) {
			$existing_image_id = attachment_url_to_postid( $image_full_url );
		}

		if ( $existing_image_id ) {
			$new_image_full_url = wp_get_attachment_image_url( $existing_image_id, 'full' );
		}

		// Return existing image as an object
		if ( $existing_image_id && $new_image_full_url ) {
			$existing_image = [
				'id'       => $existing_image_id,
				'filename' => $filename,
				'size'     => $image_size,
				'full'     => $new_image_full_url,
				'url'      => wp_get_attachment_image_url( $existing_image_id, $image_size ),
			];

			// Add to instance property to replace templateData before returning it to Vue
			self::$template_images[] = [
				'old' => $image,
				'new' => $existing_image,
			];

			return $existing_image;
		}

		// Image not found in db: Download new image, then return new image object
		$default_max_bytes  = min( self::MAX_REMOTE_IMAGE_BYTES, max( 1, (int) wp_max_upload_size() ) );
		$max_bytes          = max( 1, (int) ( $download_options['maxBytes'] ?? $default_max_bytes ) );
		$allowed_mime_types = array_key_exists( 'allowedMimeTypes', $download_options )
			? array_values( array_filter( array_map( [ self::class, 'normalize_image_mime_type' ], (array) $download_options['allowedMimeTypes'] ) ) )
			: self::allowed_remote_image_mime_types();
		$request_args       = [];

		if ( $max_bytes ) {
			$request_args['limit_response_size'] = $max_bytes + 1;
		}

		$remote_image = Helpers::remote_get( $image_full_url, $request_args );

		if ( is_wp_error( $remote_image ) ) {
			if ( $is_svg ) {
				return self::reject_svg_image_import( $image, $remote_image->get_error_message() );
			}
			return [ 'error' => $remote_image->get_error_message() ];
		}

		$status = (int) wp_remote_retrieve_response_code( $remote_image );

		if ( $status < 200 || $status >= 300 ) {
			if ( $is_svg ) {
				return self::reject_svg_image_import( $image, "Remote image request failed with HTTP status {$status}." );
			}
			return [ 'error' => "Remote image request failed with HTTP status {$status}." ];
		}

		$body       = wp_remote_retrieve_body( $remote_image );
		$validation = self::validate_remote_image_download( $body, $is_svg, $max_bytes, $allowed_mime_types );

		if ( ! empty( $validation['error'] ) ) {
			if ( $is_svg ) {
				return self::reject_svg_image_import( $image, $validation['error'] );
			}
			return $validation;
		}

		$type = $validation['mime'];

		// Direct byte uploads bypass the upload/sideload sanitization hooks.
		if ( $is_svg ) {
			$body = Svg::sanitize_markup( $body );
			if ( $body === false ) {
				return self::reject_svg_image_import( $image );
			}
		}

		$mirror = wp_upload_bits( $filename, null, $body );

		$new_attachment = [
			'post_title'     => $filename,
			'post_mime_type' => $type,
		];

		if ( ! empty( $mirror['error'] ) || empty( $mirror['file'] ) ) {
			if ( $is_svg ) {
				return self::reject_svg_image_import( $image );
			}

			return [
				'error'  => 'Error: wp_upload_bits failed (no "file" passed)',
				'mirror' => $mirror,
			];
		}

		if ( $is_svg && ! Svg::file_matches_contents( $mirror['file'], $body ) ) {
			wp_delete_file( $mirror['file'] );
			return self::reject_svg_image_import( $image );
		}

		$new_attachment_id = wp_insert_attachment( $new_attachment, $mirror['file'] );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$new_attachment_metadata = wp_generate_attachment_metadata( $new_attachment_id, $mirror['file'] );

		wp_update_attachment_metadata( $new_attachment_id, $new_attachment_metadata );

		update_post_meta( $new_attachment_id, '_bricks_image_origin_url', $image_full_url );

		$new_image = [
			'id'       => $new_attachment_id,
			'filename' => $filename,
			'size'     => $image_size,
			'full'     => wp_get_attachment_image_url( $new_attachment_id, 'full' ),
			'url'      => wp_get_attachment_image_url( $new_attachment_id, $image_size ),
		];

		// Add to instance property to replace templateData before returning it to Vue
		self::$template_images[] = [
			'old' => $image,
			'new' => $new_image,
		];

		return $new_image;
	}

	/**
	 * Keep rejected SVG imports from retaining the original remote render source.
	 *
	 * @since 2.4.4
	 * @param array  $image Original image settings.
	 * @param string $error Existing import error, if available.
	 * @return array Import error.
	 */
	private static function reject_svg_image_import( $image, $error = '' ) {
		self::$template_images[] = [
			'old' => $image,
			'new' => [
				'url'  => Builder::get_template_placeholder_image( true ),
				'full' => Builder::get_template_placeholder_image( true ),
				'path' => Builder::get_template_placeholder_image( true, 'path' ),
			],
		];

		return [ 'error' => $error ? $error : __( 'File not uploaded due to a sanitization error. Please verify the SVG file or get in touch.', 'bricks' ) ];
	}

	/**
	 * Validate a bounded remote image response before writing it to uploads.
	 *
	 * The response Content-Type cannot be trusted to describe the downloaded bytes consistently, so validation relies on the actual content.
	 *
	 * @since 2.4
	 *
	 * @param string     $body               Response body.
	 * @param bool       $is_svg             Whether the source URL identifies an SVG.
	 * @param int        $max_bytes          Maximum allowed body size.
	 * @param array|null $allowed_mime_types Allowed image MIME types, or null to skip the allow-list.
	 * @return array Validation result containing mime or error.
	 */
	private static function validate_remote_image_download( $body, $is_svg, $max_bytes, $allowed_mime_types ) {
		if ( $max_bytes && strlen( $body ) > $max_bytes ) {
			return [ 'error' => 'Remote image exceeds the maximum allowed size.' ];
		}

		if ( $is_svg ) {
			$detected_mime = 'image/svg+xml';

			if ( ! preg_match( '/<svg(?:\s|>)/i', $body ) ) {
				return [ 'error' => 'Remote SVG content is invalid.' ];
			}

			if ( is_array( $allowed_mime_types ) && ! in_array( $detected_mime, $allowed_mime_types, true ) ) {
				return [ 'error' => 'Remote image MIME type is not allowed.' ];
			}

			return [ 'mime' => $detected_mime ];
		}

		$image_info    = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid remote bytes are an expected validation failure.
		$detected_mime = self::normalize_image_mime_type( is_array( $image_info ) ? ( $image_info['mime'] ?? '' ) : '' );

		if ( ! $detected_mime || strpos( $detected_mime, 'image/' ) !== 0 ) {
			return [ 'error' => 'Remote image content is invalid.' ];
		}

		if ( is_array( $allowed_mime_types ) && ! in_array( $detected_mime, $allowed_mime_types, true ) ) {
			return [ 'error' => 'Remote image MIME type is not allowed.' ];
		}

		return [ 'mime' => $detected_mime ];
	}

	/**
	 * Normalize an image MIME type for comparison.
	 *
	 * @since 2.4
	 *
	 * @param string $mime_type MIME type value.
	 * @return string
	 */
	private static function normalize_image_mime_type( $mime_type ) {
		$parts     = explode( ';', (string) $mime_type, 2 );
		$mime_type = strtolower( trim( $parts[0] ) );

		if ( $mime_type === 'image/jpg' ) {
			return 'image/jpeg';
		}

		if ( $mime_type === 'image/x-png' ) {
			return 'image/png';
		}

		return $mime_type;
	}

	/**
	 * Get image MIME types the current user may upload.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function allowed_remote_image_mime_types() {
		return array_values(
			array_unique(
				array_filter(
					array_map( [ self::class, 'normalize_image_mime_type' ], array_values( (array) get_allowed_mime_types() ) ),
					function( $mime_type ) {
						return strpos( (string) $mime_type, 'image/' ) === 0;
					}
				)
			)
		);
	}

	/**
	 * Builder: Convert template data to new container layout structure
	 *
	 * @since 1.2
	 *
	 * @return void
	 */
	public function convert_template() {
		Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'edit_templates' ) && ! Builder_Permissions::user_has_permission( 'insert_templates' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		$elements      = ! empty( $_POST['templateData'] ) ? Ajax::decode( $_POST['templateData'], false ) : [];
		$import_images = isset( $_POST['importImages'] ) && $_POST['importImages'] == 'true';

		// Reset template instance data for new template insert
		self::$template_images = [];

		foreach ( $elements as $index => $element ) {
			if ( empty( $element['settings'] ) ) {
				$elements[ $index ]['settings'] = [];
			}

			// STEP: Import element images & update template data with local image data
			else {
				self::import_images( $element['settings'], $import_images );
			}

			if ( ! isset( $element['children'] ) ) {
				$elements[ $index ]['children'] = [];
			}
		}

		// STEP: Replace remote image data with imported/existing image data
		if ( count( self::$template_images ) ) {
			$elements_encoded = wp_json_encode( $elements );

			foreach ( self::$template_images as $template_image ) {
				$elements_encoded = str_replace(
					wp_json_encode( $template_image['old'] ),
					wp_json_encode( $template_image['new'] ),
					$elements_encoded
				);
			}

			$elements = json_decode( $elements_encoded, true );
		}

		// STEP: Generate new element IDs (@since 1.9.9)
		$elements = Helpers::generate_new_element_ids( $elements );

		wp_send_json_success( [ 'elements' => $elements ] );
	}

	/**
	 * Get the Templates list for the Template element (for the moment only Section and Content/Single template types)
	 */
	public static function get_templates_list( $template_types = '', $exclude_template_id = '' ) {
		$templates = self::get_templates_by_type( $template_types );

		$list = [];

		foreach ( $templates as $template_id ) {
			if ( $exclude_template_id == $template_id ) {
				continue;
			}

			$list[ $template_id ] = get_the_title( $template_id );
		}

		return $list;
	}

	/**
	 * Get IDs of all templates
	 *
	 * @see admin.php get_converter_items()
	 * @see files.php get_css_files_list()
	 *
	 * @param array $custom_args array Custom get_posts() arguments (@since 1.8; @see get_css_files_list).
	 *
	 * @since 1.4
	 */
	public static function get_all_template_ids( $custom_args = [] ) {
		$args = array_merge(
			[
				'post_type'              => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page'         => -1,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					'relation' => 'OR',
					[
						'key'     => BRICKS_DB_PAGE_HEADER,
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => BRICKS_DB_PAGE_CONTENT,
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => BRICKS_DB_PAGE_FOOTER,
						'value'   => '',
						'compare' => '!=',
					],
				],
			],
			$custom_args
		);

		return get_posts( $args );
	}

	/**
	 * Remove templates from /wp-sitemap.xml if not set to "Public templates" in Bricks settings
	 *
	 * @since 1.4
	 */
	public function remove_templates_from_wp_sitemap( $post_types ) {
		if ( ! isset( Database::$global_settings['publicTemplates'] ) ) {
			unset( $post_types[ BRICKS_DB_TEMPLATE_SLUG ] );
		}

		return $post_types;
	}

	/**
	 * Remove template taxonomies from /wp-sitemap.xml if not set to "Public templates" in Bricks settings
	 *
	 * @since 1.8
	 */
	public function remove_template_taxonomies_from_wp_sitemap( $taxonomies ) {
		if ( ! isset( Database::$global_settings['publicTemplates'] ) ) {
			unset( $taxonomies[ BRICKS_DB_TEMPLATE_TAX_TAG ] );
			unset( $taxonomies[ BRICKS_DB_TEMPLATE_TAX_BUNDLE ] );
		}

		return $taxonomies;
	}

	/**
	 * Frontend: Assign templates to hooks
	 *
	 * @since 1.9.1
	 */
	public function assign_templates_to_hooks() {
		// Return: In builder
		if ( bricks_is_builder() ) {
			return;
		}

		// Avoid assigning template to hooks multiple times (@since 2.0.2)
		if ( ! self::$assigned_templates_to_hooks ) {
			self::$assigned_templates_to_hooks = true;
		} else {
			return; // Already assigned templates to hooks
		}

		// Get all available templates once in case Database::set_active_templates not triggered (#86c417v88)
		$all_templates = Database::get_all_templates_by_type();
		// STEP: Get all section templates - improve performance (@since 2.0)
		$section_templates = $all_templates['section'] ?? [];

		// Return: No section templates found
		if ( empty( $section_templates ) ) {
			return;
		}

		// STEP: Loop over all section templates with 'Assign to hook' setting
		foreach ( $section_templates as $template_id ) {
			$template_settings = Helpers::get_template_settings( $template_id );

			// Skip: Previewing the template itself
			if ( $template_id == get_the_ID() ) {
				continue;
			}

			$template_conditions = $template_settings['templateConditions'] ?? [];

			if ( empty( $template_conditions ) ) {
				continue;
			}

			// We need to group each condition by hookName and hookPriority
			$arranged_conditions = [];
			// STEP: rearrange conditions, group by hookName and hookPriority
			foreach ( $template_conditions as $condition ) {
				$hook_name     = $condition['hookName'] ?? false;
				$hook_priority = $condition['hookPriority'] ?? 10;

				// If hook name is not set, we skip this condition
				if ( ! $hook_name ) {
					continue;
				}

				$key = $hook_name . '|' . $hook_priority;

				if ( ! isset( $arranged_conditions[ $key ] ) ) {
					$arranged_conditions[ $key ] = [];
				}

				// Backward compatibility: If $condition['main'] === 'hook', set it as 'any' (run in entire website)
				$condition['main']             = $condition['main'] === 'hook' ? 'any' : $condition['main'];
				$arranged_conditions[ $key ][] = $condition;
			}

			// STEP: Decide if we need to add the template to the hook
			foreach ( $arranged_conditions as $key => $conditions ) {
				$hook_name     = explode( '|', $key )[0];
				$hook_priority = explode( '|', $key )[1];

				$run_hook = self::run_template_on_hook( $conditions );

				if ( ! $run_hook ) {
					continue;
				}

				// Mini cart contents arrive as fragments, so collect their styles during the initial page request.
				if ( in_array(
					$hook_name,
					[
						'woocommerce_before_mini_cart',
						'woocommerce_before_mini_cart_contents',
						'woocommerce_mini_cart_contents',
						'woocommerce_widget_shopping_cart_total',
						'woocommerce_widget_shopping_cart_before_buttons',
						'woocommerce_widget_shopping_cart_buttons',
						'woocommerce_widget_shopping_cart_after_buttons',
						'woocommerce_after_mini_cart',
					],
					true
				) ) {
					add_action(
						'wp_enqueue_scripts',
						function() use ( $template_id ) {
							$elements = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );
							$css      = self::generate_inline_css( $template_id, $elements );

							// External files supply ID styles; CSS generation still collects the template's global classes.
							if ( Database::get_setting( 'cssLoading' ) !== 'file' ) {
								Assets::$inline_css_dynamic_data .= $css;
							}
						}
					);
				}

				if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
					// Enqueue template styles early to avoid FOUC & CLS (@since 1.12)
					$template_css_file_dir = Assets::$css_dir . "/post-$template_id.min.css";
					$template_css_file_url = Assets::$css_url . "/post-$template_id.min.css";

					if ( file_exists( $template_css_file_dir ) ) {
						wp_enqueue_style( "bricks-post-$template_id", $template_css_file_url, [], filemtime( $template_css_file_dir ) );
					}
				}

				// STEP: Add template to hook
				add_action(
					$hook_name,
					function() use ( $template_id ) {
						// Use [bricks_template] shortcode to render the template content (included styles)
						echo do_shortcode( "[bricks_template id='$template_id' on_hook='1']" );
					},
					$hook_priority
				);
			}
		}

	}

	/**
	 * Check if template should be run on hook
	 *
	 * @since 1.9.2
	 *
	 * @param array $arranged_conditions
	 *
	 * @return bool
	 */
	public static function run_template_on_hook( $arranged_conditions = [] ) {
		if ( empty( $arranged_conditions ) ) {
			return false;
		}

		$preview_type = '';
		$post_id      = Database::$page_data['post_id'];
		$page_type    = Database::$page_data['current_page_type'] ?? '';
		$content_type = Database::$active_templates['content_type'] ?? '';

		// Check if currently previewing a template
		if ( is_singular( BRICKS_DB_TEMPLATE_SLUG ) && isset( Database::$page_data['preview_or_post_id'] ) ) {
			$preview_type = Helpers::get_template_setting( 'templatePreviewType', Database::$page_data['preview_or_post_id'] );
			$post_id      = Database::$page_data['preview_or_post_id'];
		}

		$is_post_context = $page_type === 'post';

		// Post-oriented hook conditions must not evaluate archive/search object IDs as post IDs.
		if ( in_array( $content_type, [ 'archive', 'search', 'error', 'wc_archive' ], true ) ) {
			$is_post_context = false;
		}

		$post_type = $is_post_context ? get_post_type( $post_id ) : ''; // Considered template preview as well

		$results = [
			'include' => [],
			'exclude' => [],
		];

		// STEP: Loop over all template conditions: If they are met, store results in $results
		foreach ( $arranged_conditions as $condition ) {
			if ( ! isset( $condition['main'] ) ) {
				continue;
			}

			if ( \Bricks\Integrations\Wpml\Wpml::$is_active && has_filter( 'wpml_object_id' ) ) {
				$translated_conditions = \Bricks\Integrations\Wpml\Wpml::translate_template_conditions( [ $condition ] );
				$condition             = $translated_conditions[0] ?? $condition;
			}

			// Reset condition met
			$condition_met = false;

			/**
			 * Possible values:
			 * any, frontpage, postType, archiveType, search, error, terms, ids
			 */
			$condition_type = $condition['main'];

			switch ( $condition_type ) {
				// Entire website
				case 'any':
					$condition_met = true;
					break;

				// Check for front page
				case 'frontpage':
					if ( bricks_is_ajax_call() || bricks_is_rest_call() ) {
						$front_page_id = get_option( 'page_on_front' );

						// WPML: Translate front page ID to current language (@since 2.3.12)
						if ( \Bricks\Integrations\Wpml\Wpml::$is_active && has_filter( 'wpml_object_id' ) && $front_page_id ) {
							$front_page_id = apply_filters( 'wpml_object_id', $front_page_id, 'page', true );
						}

						$is_front_page = absint( $post_id ) == absint( $front_page_id );
					} else {
						$is_front_page = is_front_page();
					}

					if ( $is_front_page ) {
						$condition_met = true;
					}
					break;

				// Check for a specific post type
				case 'postType':
					// Did not set any post types, skip
					if ( ! $is_post_context || ! isset( $condition['postType'] ) ) {
						break;
					}

					// Check if the current post type matches any of the selected post types. $post_type considered template preview as well
					if ( in_array( $post_type, $condition['postType'] ) ) {
						$condition_met = true;
					}
					break;

				// Archive (any/author/data/term)
				case 'archiveType':
					if ( ! isset( $condition['archiveType'] ) ) {
						break;
					}

					// Archive pages include category, tag, author, date, custom post type, and custom taxonomy based archives.
					if ( in_array( 'any', $condition['archiveType'] ) && ( is_archive() || strpos( $preview_type, 'archive' ) !== false ) ) {
						$condition_met = true;
					}

					// Post type archive
					elseif ( in_array( 'postType', $condition['archiveType'] ) && ( is_post_type_archive() || $preview_type === 'archive-cpt' ) ) {
						if ( empty( $condition['archivePostTypes'] ) ) {
							// no post types set, any post type archive matches
							$condition_met = true;
						} else {

							// Previewing a template with content set to a CPT archive
							if ( $preview_type === 'archive-cpt' ) {
								$preview_cpt = Helpers::get_template_setting( 'templatePreviewPostType', $post_id );
								if ( $preview_cpt && in_array( $preview_cpt, $condition['archivePostTypes'] ) ) {
									$condition_met = true;
								}
							}
							// or, check if the post type archive matches the post type condition
							elseif ( is_post_type_archive( $condition['archivePostTypes'] ) ) {
								$condition_met = true;
							}

						}
					}

					// Author archive
					elseif ( in_array( 'author', $condition['archiveType'] ) && ( is_author() || $preview_type === 'archive-author' ) ) {
						$condition_met = true;
					}

					// Date archive
					elseif ( in_array( 'date', $condition['archiveType'] ) && ( is_date() || $preview_type === 'archive-date' ) ) {
						$condition_met = true;
					}

					// Term archive
					elseif ( in_array( 'term', $condition['archiveType'] ) && ( is_category() || is_tag() || is_tax() || $preview_type === 'archive-term' ) ) {
						if ( empty( $condition['archiveTerms'] ) ) {
							// no taxonomies set, any taxonomy archive matches
							$condition_met = true;
						} elseif ( is_array( $condition['archiveTerms'] ) ) {

							// Previewing a template, with populate content set to archive of term
							if ( $preview_type === 'archive-term' ) {
								// Note the post_id here is the template post Id (because in this archive situation the preview_id was not set)
								$preview_term = Helpers::get_template_setting( 'templatePreviewTerm', $post_id );

								if ( ! empty( $preview_term ) ) {
									$preview_term     = explode( '::', $preview_term );
									$queried_taxonomy = isset( $preview_term[0] ) ? $preview_term[0] : '';
									$queried_term_id  = isset( $preview_term[1] ) ? intval( $preview_term[1] ) : '';
								}
							}

							// All the other situations in frontend: is_category() || is_tag() || is_tax()
							else {
								$queried_object = get_queried_object();

								if ( is_object( $queried_object ) ) {
									$queried_term_id  = intval( $queried_object->term_id );
									$queried_taxonomy = $queried_object->taxonomy;
								}
							}

							// Check if queried taxonomy and term_id matches any of the selected archive terms
							if ( ! empty( $queried_term_id ) && ! empty( $queried_taxonomy ) ) {
								foreach ( $condition['archiveTerms'] as $archive_term ) {
									$term_parts = explode( '::', $archive_term );
									$taxonomy   = $term_parts[0];
									$term_id    = $term_parts[1];

									if ( $queried_taxonomy === $taxonomy ) {
										if ( $queried_term_id === intval( $term_id ) ) {
											$condition_met = true;
											break;
										}

										// Applied for taxonomy::all (all terms of a taxonomy)
										elseif ( 'all' == $term_id ) {
											$condition_met = true;
											break;
										}

										// The condition includes child terms, check if the queried term id is child of the term id set in the condition
										elseif ( isset( $condition['archiveTermsIncludeChildren'] ) && term_is_ancestor_of( $term_id, $queried_term_id, $queried_taxonomy ) ) {
											$condition_met = true;
											break;
										}
									}
								}
							}
						}
					}
					break;

				// Check for search
				case 'search':
					if ( is_search() || $preview_type === 'search' ) {
						$condition_met = true;
					}
					break;

				// Check for error
				case 'error':
					if ( is_404() || $preview_type === 'error' ) {
						$condition_met = true;
					}
					break;

				// Check for a specific term assigned to the post
				case 'terms':
					// Did not set any terms, skip
					if ( ! $is_post_context || ! isset( $condition['terms'] ) || empty( $post_id ) ) {
						break;
					}

					$terms = $condition['terms'];

					foreach ( $terms as $term ) {
						$tax_term = explode( '::', $term );
						$taxonomy = $tax_term[0];
						$term     = $tax_term[1];

						$post_terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

						if ( is_array( $post_terms ) && in_array( $term, $post_terms ) ) {
							$condition_met = true;
							break;
						}
					}
					break;

				// Check for a specific post ID or children
				case 'ids':
					// Did not set any post IDs, skip
					if ( ! $is_post_context || ! isset( $condition['ids'] ) || empty( $post_id ) ) {
						break;
					}

					// Specif post ID
					if ( in_array( $post_id, $condition['ids'] ) ) {
						$condition_met = true;
						break;
					}

					// Apply to child pages
					elseif ( isset( $condition['idsIncludeChildren'] ) ) {
						$ancestors = get_post_ancestors( $post_id );

						foreach ( $ancestors as $ancestor_id ) {
							if ( in_array( $ancestor_id, $condition['ids'] ) ) {
								$condition_met = true;
								break;
							}
						}
					}
					break;
			}

			// Store condition result
			$exclude                          = isset( $condition['exclude'] );
			$include_or_exclude               = $exclude ? 'exclude' : 'include';
			$results[ $include_or_exclude ][] = [
				'condition_type' => $condition_type,
				'condition_met'  => $condition_met,
				'exclude'        => $exclude,
			];
		} // end foreach

		// STEP: Analyze results
		// If exclude is empty: user wants to insert the section to certain criteria. We return true if any of the conditions is true
		if ( empty( $results['exclude'] ) ) {
			$run_template = false;

			foreach ( $results['include'] as $result ) {
				if ( $result['condition_met'] ) {
					$run_template = true;
					break;
				}
			}
		}

		// If include is empty: user wants to insert the section to all pages, except certain criteria. We return true if all of the conditions are true
		elseif ( empty( $results['include'] ) ) {
			$run_template = true;

			foreach ( $results['exclude'] as $result ) {
				if ( $result['condition_met'] ) {
					$run_template = false;
					break;
				}
			}
		}

		// If both include and exclude are set, we return true if any of the include conditions is true and none of the exclude conditions is true
		else {
			$run_template = false;

			foreach ( $results['include'] as $result ) {
				if ( $result['condition_met'] ) {
					$run_template = true;
					break;
				}
			}

			foreach ( $results['exclude'] as $result ) {
				if ( $result['condition_met'] ) {
					$run_template = false;
					break;
				}
			}
		}

		return $run_template;
	}

	/**
	 * Only used for popup inserted directly through the Template element (non-loop).
	 *
	 * NOTE: Do not use this function in other places!
	 *
	 * @since 1.12
	 */
	public static function generate_popup_setting_css( $popup_id ) {
		$unique_key = 'template_popup_' . $popup_id;

		// Return if already generated
		if ( isset( Assets::$inline_css[ $unique_key ] ) ) {
			return Assets::$inline_css[ $unique_key ];
		}

		$styles                     = '';
		$template_settings_controls = Settings::get_controls_data( 'template' );
		$popup_template_settings    = Helpers::get_template_settings( $popup_id );

		// Maybe need to enqueue some scripts like AJAX loader
		if ( is_array( $popup_template_settings ) && ! empty( $popup_template_settings ) ) {
			Assets::enqueue_setting_specific_scripts( $popup_template_settings );
		}

		if ( ! empty( $template_settings_controls['controls'] ) ) {
			// Generate CSS for popup settings
			Assets::generate_inline_css_from_element(
				[
					'settings'             => $popup_template_settings,
					'_templateCssSelector' => ".brxe-popup-{$popup_id}"
				],
				$template_settings_controls['controls'],
				$unique_key
			);

			if ( isset( Assets::$inline_css[ $unique_key ] ) ) {
				$styles .= "\n/* POPUP SETTINGS CSS */\n" . Assets::$inline_css[ $unique_key ] . "\n";
			}
		}

		return $styles;
	}
}

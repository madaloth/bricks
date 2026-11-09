<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Ajax {
	public function __construct() {
		// In builder
		add_action( 'wp_ajax_bricks_command_palette_get_posts', [ $this, 'command_palette_get_posts' ] );
		add_action( 'wp_ajax_bricks_generate_code_signature', [ $this, 'generate_code_signature' ] );

		if ( Database::get_setting( 'builderPostSelectorShowUrlPath', false ) ) {
			add_filter( 'bricks/builder/post_title', [ $this, 'add_url_path_to_post_title' ], 20, 2 );
		}

		add_filter( 'sanitize_post_meta_' . BRICKS_DB_PAGE_CONTENT, [ $this, 'sanitize_bricks_postmeta' ], 10, 3 );
		add_filter( 'sanitize_post_meta_' . BRICKS_DB_PAGE_HEADER, [ $this, 'sanitize_bricks_postmeta' ], 10, 3 );
		add_filter( 'sanitize_post_meta_' . BRICKS_DB_PAGE_FOOTER, [ $this, 'sanitize_bricks_postmeta' ], 10, 3 );
		add_filter( 'sanitize_post_meta_' . BRICKS_DB_PAGE_SETTINGS, [ $this, 'sanitize_bricks_postmeta_page_settings' ], 10, 3 );
		add_action( 'update_post_metadata', [ $this, 'update_bricks_postmeta' ], 10, 5 );

		add_action( 'wp_ajax_bricks_import_images', [ $this, 'import_images' ] );
		add_action( 'wp_ajax_bricks_download_image', [ $this, 'download_image' ] );
		add_action( 'wp_ajax_bricks_verify_media_upload_folder', [ $this, 'verify_media_upload_folder' ] );
		add_action( 'wp_ajax_bricks_check_media_filename', [ $this, 'check_media_filename' ] );
		add_action( 'wp_ajax_bricks_get_attachment_file_state', [ $this, 'get_attachment_file_state' ] );
		add_action( 'wp_ajax_bricks_get_media_attachment_details', [ $this, 'get_media_attachment_details' ] );
		add_action( 'wp_ajax_bricks_save_media_attachment_details', [ $this, 'save_media_attachment_details' ] );
		add_action( 'wp_ajax_bricks_set_media_health_issue_ignored', [ $this, 'set_media_health_issue_ignored' ] );
		add_action( 'wp_ajax_bricks_prepare_media_health_action', [ $this, 'prepare_media_health_action' ] );
		add_action( 'wp_ajax_bricks_process_media_health_action', [ $this, 'process_media_health_action' ] );
		add_action( 'wp_ajax_bricks_save_media_browser_preferences', [ $this, 'save_media_browser_preferences' ] );
		add_action( 'wp_ajax_bricks_scan_media_health', [ $this, 'scan_media_health' ] );
		add_action( 'wp_ajax_bricks_scan_media_metadata_index', [ $this, 'scan_media_metadata_index' ] );
		add_action( 'wp_ajax_bricks_prepare_media_bulk_selection', [ $this, 'prepare_media_bulk_selection' ] );
		add_action( 'wp_ajax_bricks_process_media_bulk_action', [ $this, 'process_media_bulk_action' ] );
		add_action( 'wp_ajax_bricks_prepare_media_folder_bulk_selection', [ $this, 'prepare_media_folder_bulk_selection' ] );
		add_action( 'wp_ajax_bricks_process_media_folder_bulk_move', [ $this, 'process_media_folder_bulk_move' ] );
		add_action( 'wp_ajax_bricks_manage_media_folder', [ $this, 'manage_media_folder' ] );
		add_action( 'wp_ajax_bricks_download_media_archive', [ $this, 'download_media_archive' ] );
		add_action( 'wp_ajax_bricks_import_media_from_url', [ $this, 'import_media_from_url' ] );
		add_action( 'wp_ajax_bricks_get_image_metadata', [ $this, 'get_image_metadata' ] );
		add_action( 'wp_ajax_bricks_get_image_from_custom_field', [ $this, 'get_image_from_custom_field' ] );

		add_action( 'wp_ajax_bricks_get_dynamic_data_preview_content', [ $this, 'get_dynamic_data_preview_content' ] );

		add_action( 'wp_ajax_bricks_get_posts', [ $this, 'get_posts' ] );
		add_action( 'wp_ajax_bricks_get_terms_options', [ $this, 'get_terms_options' ] );
		add_action( 'wp_ajax_bricks_get_users', [ $this, 'get_users' ] );

		add_action( 'wp_ajax_bricks_render_data', [ $this, 'render_data' ] );

		add_action( 'wp_ajax_bricks_publish_post', [ $this, 'publish_post' ] );
		add_action( 'wp_ajax_bricks_save_post', [ $this, 'save_post' ] );
		add_action( 'wp_ajax_bricks_save_template_screenshot', [ $this, 'save_template_screenshot' ] );
		add_action( 'wp_ajax_bricks_create_autosave', [ $this, 'create_autosave' ] );
		add_action( 'wp_ajax_bricks_get_builder_url', [ $this, 'get_builder_url' ] );
		add_action( 'wp_ajax_bricks_get_partial_builder_data', [ $this, 'get_partial_builder_data' ] );

		add_action( 'wp_ajax_bricks_save_color_palette', [ $this, 'save_color_palette' ] );
		add_action( 'wp_ajax_bricks_save_builder_ui_preferences', [ $this, 'save_builder_ui_preferences' ] );
		add_action( 'wp_ajax_bricks_reset_builder_ui_preferences', [ $this, 'reset_builder_ui_preferences' ] );
		add_action( 'wp_ajax_bricks_save_builder_ui_profile', [ $this, 'save_builder_ui_profile' ] );
		add_action( 'wp_ajax_bricks_delete_builder_ui_profile', [ $this, 'delete_builder_ui_profile' ] );
		add_action( 'wp_ajax_bricks_save_builder_scale_off', [ $this, 'save_builder_scale_off' ] );
		add_action( 'wp_ajax_bricks_save_builder_width_locked', [ $this, 'save_builder_width_locked' ] );

		add_action( 'wp_ajax_bricks_render_element', [ $this, 'render_element' ] );

		add_action( 'wp_ajax_bricks_get_pages', [ $this, 'get_pages' ] );
		add_action( 'wp_ajax_bricks_create_new_page', [ $this, 'create_new_page' ] );
		add_action( 'wp_ajax_bricks_duplicate_post_page', [ $this, 'duplicate_content' ] );

		add_action( 'wp_ajax_bricks_get_my_templates_data', [ $this, 'get_my_templates_data' ] );
		add_action( 'wp_ajax_bricks_unified_global_export', [ $this, 'unified_global_export' ] );
		add_action( 'wp_ajax_bricks_unified_global_import_inspect', [ $this, 'unified_global_import_inspect' ] );
		add_action( 'wp_ajax_bricks_unified_global_import_apply', [ $this, 'unified_global_import_apply' ] );
		add_action( 'wp_ajax_bricks_import_global_transfer_file', [ $this, 'import_global_transfer_file' ] );
		add_action( 'wp_ajax_bricks_import_global_classes_file', [ $this, 'import_global_classes_file' ] );

		add_action( 'wp_ajax_bricks_get_remote_templates_data', [ $this, 'get_remote_templates_data' ] );
		add_action( 'wp_ajax_bricks_get_remote_template_package', [ $this, 'get_remote_template_package' ] );
		add_action( 'wp_ajax_bricks_get_remote_components', [ $this, 'get_remote_components' ] );
		add_action( 'wp_ajax_bricks_inspect_remote_component', [ $this, 'inspect_remote_component' ] );
		add_action( 'wp_ajax_bricks_apply_remote_component', [ $this, 'apply_remote_component' ] );

		add_action( 'wp_ajax_bricks_get_current_user_id', [ $this, 'get_current_user_id' ] );

		add_action( 'wp_ajax_bricks_query_loop_delete_random_seed_transient', [ $this, 'query_loop_delete_random_seed_transient' ] );

		// In Gutenberg
		add_action( 'wp_ajax_bricks_get_html_from_content', [ $this, 'get_html_from_content' ] );

		// Get template elements by template ID
		add_action( 'wp_ajax_bricks_get_template_elements_by_id', [ $this, 'get_template_elements_by_id' ] );

		// Get custom shape divider SVG from URL (@since 1.8.6)
		add_action( 'wp_ajax_bricks_get_custom_shape_divider', [ $this, 'get_custom_shape_divider' ] );

		// Frontend: Regenerate form nonce (@since 1.9.6)
		add_action( 'wp_ajax_bricks_regenerate_form_nonce', [ $this, 'regenerate_form_nonce' ] );
		add_action( 'wp_ajax_nopriv_bricks_regenerate_form_nonce', [ $this, 'regenerate_form_nonce' ] );

		// Frontend: Regenerate query nonce (@since 1.11)
		add_action( 'wp_ajax_bricks_regenerate_query_nonce', [ $this, 'regenerate_query_nonce' ] );
		add_action( 'wp_ajax_nopriv_bricks_regenerate_query_nonce', [ $this, 'regenerate_query_nonce' ] );

		// Restore global class (@since 1.11)
		add_action( 'wp_ajax_bricks_restore_global_class', [ $this, 'restore_global_class' ] );

		// Add new action for deleting global classes permanently (@since 1.11)
		add_action( 'wp_ajax_bricks_delete_global_classes_permanently', [ $this, 'delete_global_classes_permanently' ] );

		// Delete global variables permanently (@since 2.4)
		add_action( 'wp_ajax_bricks_delete_global_variables_permanently', [ $this, 'delete_global_variables_permanently' ] );

		// Clean up orphaned elements across site (@since 2.0)
		add_action( 'wp_ajax_bricks_cleanup_orphaned_elements', [ $this, 'cleanup_orphaned_elements' ] );

		// Scan for orphaned elements across site (@since 2.0)
		add_action( 'wp_ajax_bricks_scan_orphaned_elements', [ $this, 'scan_orphaned_elements' ] );

		// Query API (@since 2.1)
		add_action( 'wp_ajax_bricks_query_api', [ $this, 'query_api' ] );
	}

	/**
	 * Command palette: Get posts for the PopupCommandPalette.vue
	 *
	 * @since 2.0
	 */
	public function command_palette_get_posts() {
		self::verify_request( 'bricks-nonce-builder' );

		// Get all posts with direct SQL query
		global $wpdb;

		// Get selected post type
		$post_type = ! empty( $_POST['postType'] ) ? sanitize_text_field( $_POST['postType'] ) : 'any';

		// Get all public post types if no post type is selected
		if ( $post_type === 'any' ) {
			$post_types = array_keys( get_post_types( [ 'public' => true ] ) );
			$post_types = array_map( 'sanitize_text_field', $post_types );
		} else {
			$post_types = [ $post_type ];
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Get all post IDs in a single query
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ($placeholders)
				AND post_status IN ('publish', 'draft', 'pending', 'private')
				ORDER BY post_modified DESC",
				$post_types
			)
		);

		// Process all posts
		$posts          = [];
		$post_edit_link = admin_url() . 'post.php?post=POST_ID&action=edit';

		foreach ( $post_ids as $post_id ) {
			$posts[] = [
				'id'        => $post_id,
				'title'     => get_the_title( $post_id ),
				'postType'  => get_post_type( $post_id ),
				'slug'      => get_post_field( 'post_name', $post_id ),
				'permalink' => get_permalink( $post_id ),
				'status'    => get_post_status( $post_id ),
				'editUrl'   => Helpers::get_builder_edit_link( $post_id ),
				'editUrlWp' => str_replace( 'POST_ID', $post_id, $post_edit_link ),
			];
		}

		wp_send_json_success( $posts );
	}

	/**
	 * Check if current endpoint is Bricks AJAX endpoint
	 *
	 * @param string $action E.g. 'get_template_elements_by_id' or 'form_submit'.
	 * @param string $action E.g. 'get_template_elements_by_id' or 'form_submit'.
	 *
	 * @since 1.11
	 *
	 * @return boolean
	 * @return boolean
	 */
	public static function is_current_endpoint( $action ) {
		if ( ! bricks_is_ajax_call() || ! isset( $_POST['action'] ) ) {
			return false;
		}

		return sanitize_text_field( $_POST['action'] ) === 'bricks_' . $action;
	}

	/**
	 * Builder: Generate code signature
	 *
	 * @since 1.9.7
	 */
	public function generate_code_signature() {
		self::verify_request( 'bricks-nonce-builder' );

		// Check if code signatures are locked (@since 1.11.1)
		if ( Helpers::code_signature_generation_locked() ) {
			wp_send_json_error( esc_html__( 'Code signatures are locked.', 'bricks' ) );
		}

		if (
			! empty( $_POST['element'] ) &&
			Helpers::code_execution_enabled() &&
			Capabilities::current_user_can_execute_code()
		) {
			$element  = self::decode( $_POST['element'], false );
			$elements = Admin::process_elements_for_signature( [ $element ] );

			wp_send_json_success( [ 'element' => $elements[0] ] );
		}

		wp_send_json_error( esc_html__( 'Not allowed', 'bricks' ) );
	}

	/**
	 * Return the default auto-signed code response payload.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_empty_auto_signed_code_response() {
		return [
			'elements'            => [],
			'componentProperties' => [],
			'globalQueries'       => [],
		];
	}

	/**
	 * Check if the auto-signed code response payload contains any entries.
	 *
	 * @since 2.4
	 *
	 * @param array $auto_signed_code Auto-signed code response payload.
	 *
	 * @return boolean
	 */
	private static function auto_signed_code_response_has_entries( $auto_signed_code ) {
		return ! empty( $auto_signed_code['elements'] ) || ! empty( $auto_signed_code['componentProperties'] ) || ! empty( $auto_signed_code['globalQueries'] );
	}

	/**
	 * Maybe auto-sign builder elements for the current request.
	 *
	 * @since 2.4
	 *
	 * @param array $elements          Bricks elements to process.
	 * @param array $auto_signed_code  Auto-signed code response payload.
	 * @param bool  $strip_slashes     Whether to strip slashes before generating signatures.
	 *
	 * @return array
	 */
	private static function maybe_auto_sign_builder_elements( $elements, &$auto_signed_code, $strip_slashes = false ) {
		if ( ! is_array( $elements ) || ! count( $elements ) ) {
			return $elements;
		}

		return Admin::process_elements_for_signature( $elements, false, $strip_slashes, $auto_signed_code, false, true );
	}

	/**
	 * Maybe auto-sign stored builder area when save payload has no elements.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id          Post ID.
	 * @param string $area             Builder area.
	 * @param array  $auto_signed_code Auto-signed code response payload.
	 *
	 * @return boolean
	 */
	private static function maybe_auto_sign_stored_builder_area( $post_id, $area, &$auto_signed_code ) {
		$meta_key = BRICKS_DB_PAGE_CONTENT;

		if ( $area === 'header' ) {
			$meta_key = BRICKS_DB_PAGE_HEADER;
		} elseif ( $area === 'footer' ) {
			$meta_key = BRICKS_DB_PAGE_FOOTER;
		}

		$elements = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $elements ) || ! count( $elements ) ) {
			return false;
		}

		// Match the slashed shape of decoded builder save payloads before updating post meta.
		$elements         = wp_slash( $elements );
		$updated_elements = self::maybe_auto_sign_builder_elements( $elements, $auto_signed_code, true );

		if ( $updated_elements === $elements ) {
			return false;
		}

		update_post_meta( $post_id, $meta_key, $updated_elements );

		return true;
	}

	/**
	 * Maybe auto-sign builder global queries for the current request.
	 *
	 * @since 2.4
	 *
	 * @param array $global_queries    Global queries to process.
	 * @param array $auto_signed_code  Auto-signed code response payload.
	 *
	 * @return array
	 */
	private static function maybe_auto_sign_builder_global_queries( $global_queries, &$auto_signed_code ) {
		if (
			! is_array( $global_queries ) ||
			! Helpers::code_execution_enabled() ||
			Helpers::code_signature_generation_locked() ||
			! Capabilities::current_user_can_execute_code()
		) {
			return $global_queries;
		}

		foreach ( $global_queries as $index => $global_query ) {
			$query_id = $global_query['id'] ?? '';

			$query_settings = $global_query['settings'] ?? [];

			if ( ! $query_id || empty( $query_settings['queryEditor'] ) ) {
				continue;
			}

			$updated_query_settings = Admin::process_query_settings_for_signature( $query_settings, false, false, true );

			if ( $updated_query_settings === $query_settings ) {
				continue;
			}

			$global_queries[ $index ]['settings'] = $updated_query_settings;

			$auto_signed_code['globalQueries'][] = [
				'id'        => $query_id,
				'signature' => $updated_query_settings['signature'] ?? '',
				'user_id'   => $updated_query_settings['user_id'] ?? 0,
				'time'      => $updated_query_settings['time'] ?? 0,
				'code'      => $query_settings['queryEditor'],
			];
		}

		return $global_queries;
	}

	/**
	 * Decode stringified JSON data
	 *
	 * @since 1.0
	 */
	public static function decode( $data, $run_wp_slash = true ) {
		$data = stripslashes( $data );
		$data = json_decode( $data, true );
		$data = $run_wp_slash ? wp_slash( $data ) : $data; // Make sure we keep the good slashes on update_post_meta

		return $data;
	}

	/**
	 * Save array option or delete it if empty.
	 *
	 * @param string $option_name
	 * @param array  $items
	 * @return mixed
	 * @since 2.4
	 */
	private static function save_array_option( $option_name, $items ) {
		$items = is_array( $items ) ? array_values( $items ) : [];

		if ( empty( $items ) ) {
			return delete_option( $option_name );
		}

		return update_option( $option_name, $items );
	}

	/**
	 * Merge incoming trash items with the database trash and remove restored items.
	 *
	 * @param array $incoming_trash
	 * @param array $existing_trash
	 * @param array $active_items
	 * @return array
	 * @since 2.4
	 */
	private static function sync_trash_items( $incoming_trash, $existing_trash, $active_items ) {
		$incoming_trash  = is_array( $incoming_trash ) ? $incoming_trash : [];
		$existing_trash  = is_array( $existing_trash ) ? $existing_trash : [];
		$active_items    = is_array( $active_items ) ? $active_items : [];
		$active_item_ids = array_column( $active_items, 'id' );

		$merged_trash = array_merge( $existing_trash, $incoming_trash );
		$unique_trash = [];

		foreach ( $merged_trash as $trash_item ) {
			if ( ! isset( $trash_item['id'] ) ) {
				continue;
			}

			if ( in_array( $trash_item['id'], $active_item_ids, true ) ) {
				continue;
			}

			$existing_index = array_search( $trash_item['id'], array_column( $unique_trash, 'id' ), true );

			if ( $existing_index === false ) {
				$unique_trash[] = $trash_item;
				continue;
			}

			$current_deleted_at = $unique_trash[ $existing_index ]['deletedAt'] ?? 0;
			$new_deleted_at     = $trash_item['deletedAt'] ?? 0;

			if ( $new_deleted_at >= $current_deleted_at ) {
				$unique_trash[ $existing_index ] = $trash_item;
			}
		}

		return array_values( $unique_trash );
	}

	/**
	 * Remove item IDs from trash and active collections and return the deleted items.
	 *
	 * @param array $item_ids
	 * @param array $trashed_items
	 * @param array $active_items
	 * @param bool  $allow_active_delete
	 * @return array
	 * @since 2.4
	 */
	private static function collect_deleted_items_from_collections( $item_ids, $trashed_items, $active_items = [], $allow_active_delete = false ) {
		$item_ids            = is_array( $item_ids ) ? $item_ids : [];
		$trashed_items       = is_array( $trashed_items ) ? $trashed_items : [];
		$active_items        = is_array( $active_items ) ? $active_items : [];
		$allow_active_delete = (bool) $allow_active_delete;
		$deleted_items       = [];
		$trashed_assoc       = array_column( $trashed_items, null, 'id' );
		$active_assoc        = array_column( $active_items, null, 'id' );

		foreach ( $item_ids as $item_id ) {
			if ( isset( $trashed_assoc[ $item_id ] ) ) {
				$deleted_items[] = $trashed_assoc[ $item_id ];
				unset( $trashed_assoc[ $item_id ] );
			} elseif ( $allow_active_delete && isset( $active_assoc[ $item_id ] ) ) {
				$deleted_items[] = $active_assoc[ $item_id ];
				unset( $active_assoc[ $item_id ] );
			}
		}

		return [
			'deleted_items' => $deleted_items,
			'trashed_items' => array_values( $trashed_assoc ),
			'active_items'  => array_values( $active_assoc ),
		];
	}

	/**
	 * Form element: Regenerate nonce
	 *
	 * @since 1.9.6
	 */
	public function regenerate_form_nonce() {
		echo wp_create_nonce( 'bricks-nonce-form' );
		wp_die();
	}

	/**
	 *
	 * Query Sort/Filter: Regenerate nonce
	 *
	 * @since 1.11
	 */
	public function regenerate_query_nonce() {
		wp_send_json_success(
			[
				'bricks_nonce' => wp_create_nonce( 'bricks-nonce' ),
				'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Verify nonce (AJAX call)
	 *
	 * wp-admin: 'bricks-nonce-admin'
	 * builder:  'bricks-nonce-builder'
	 * frontend: 'bricks-nonce' (= default)
	 *
	 * @return void
	 */
	public static function verify_nonce( $nonce = 'bricks-nonce' ) {
		if ( ! check_ajax_referer( $nonce, 'nonce', false ) ) {
			wp_send_json_error( "verify_nonce: \"$nonce\" is invalid." );
		}
	}

	/**
	 * Verify request: nonce and user access
	 *
	 * Check for builder in order to not trigger on wp_auth_check
	 *
	 * @since 1.0
	 */
	public static function verify_request( $nonce = 'bricks-nonce' ) {
		self::verify_nonce( $nonce );

		// Verify user access (get_the_ID() returns 0 in AJAX call)
		$post_id = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : get_the_ID();

		if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( 'verify_request: User can not use builder (' . get_current_user_id() . ')' );
		}
	}

	/**
	 * Generate Style Manager CSS file
	 *
	 * Creates style-manager.min.css (contains color variables, utility classes, and global variables with scale property.
	 *
	 * @return bool True on success, false on failure
	 *
	 * @since 2.2
	 */
	public static function generate_style_manager_css_file() {
		$color_palettes              = get_option( BRICKS_DB_COLOR_PALETTE, [] );
		$global_variables_categories = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

		$css_content = '/* Bricks Style Manager CSS - Generated on ' . date( 'Y-m-d H:i:s' ) . " */\n\n";

		// STEP 1: Generate CSS variables from color palettes
		$css_variables = [];

		if ( is_array( $color_palettes ) && ! empty( $color_palettes ) ) {
			foreach ( $color_palettes as $palette ) {
				if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
					continue;
				}

				foreach ( $palette['colors'] as $color ) {
					// Skip colors without 'light' value (old format colors)
					if ( empty( $color['light'] ) ) {
						continue;
					}

					// Extract variable name from raw (e.g., "var(--primary)" => "--primary")
					if ( ! empty( $color['raw'] ) ) {
						$var_name = Helpers::extract_name_from_css_variable( $color['raw'] );
						if ( $var_name ) {
							$css_variables[ $var_name ] = $color['light'];
						}
					}
				}
			}
		}

		// STEP 2: Generate CSS variables from global variables categories with scale property
		if ( is_array( $global_variables_categories ) && ! empty( $global_variables_categories ) ) {
			$global_variables = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

			foreach ( $global_variables_categories as $category ) {
				// Skip categories without scale property
				if ( empty( $category['scale'] ) || ! is_array( $category['scale'] ) ) {
					continue;
				}

				// Get all variables in this category
				$category_variables = array_filter(
					$global_variables,
					function( $variable ) use ( $category ) {
						return ! empty( $variable['category'] ) && $variable['category'] === $category['id'];
					}
				);

				// Generate CSS variables for each variable in the category
				foreach ( $category_variables as $variable ) {
					if ( empty( $variable['name'] ) || empty( $variable['value'] ) ) {
						continue;
					}

					$var_name = Helpers::extract_name_from_css_variable( $variable['name'] );
					if ( $var_name ) {
						$css_variables[ $var_name ] = $variable['value'];
					}
				}
			}
		}

		// Add CSS variables to content
		if ( ! empty( $css_variables ) ) {
			$css_content .= "/* CSS Variables */\n\n";
			$css_content .= ":root {\n";
			foreach ( $css_variables as $var_name => $var_value ) {
				$css_content .= "  {$var_name}: {$var_value};\n";
			}
			$css_content .= "}\n\n";
		}

		$css_content .= Assets::generate_style_manager_utility_classes_css();

		// Minify CSS
		$css_content = Assets::minify_css( $css_content );

		// Write CSS file
		$css_file_path = Assets::$css_dir . '/style-manager.min.css';

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return false;
		}

		if ( ! Assets::ensure_css_directory() ) {
			return false;
		}

		return $wp_filesystem->put_contents( $css_file_path, $css_content, FS_CHMOD_FILE );
	}

	/**
	 * Generate color palette CSS file (deprecated - use generate_style_manager_css_file instead)
	 *
	 * @deprecated 2.2 Use generate_style_manager_css_file() instead
	 */
	public static function generate_color_palette_css_file( $color_palettes ) {
		return self::generate_style_manager_css_file();
	}

	/**
	 * Save color palette
	 *
	 * @since 1.0
	 */
	public function save_color_palette() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'edit_color_palettes' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		if ( isset( $_POST['colorPalette'] ) ) {
			$color_palette = stripslashes_deep( $_POST['colorPalette'] );

			$color_palette_updated = update_option( BRICKS_DB_COLOR_PALETTE, $color_palette );

			// Generate color palette CSS file (@since 2.2)
			$css_file_generated = self::generate_color_palette_css_file( $color_palette );

			wp_send_json_success(
				[
					'color_palette_updated' => $color_palette_updated,
					'css_file_generated'    => $css_file_generated
				]
			);
		} else {
			wp_send_json_error( [ 'message' => esc_html__( 'New color could not be saved.', 'bricks' ) ] );
		}
	}

	public function save_builder_ui_preferences() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( Builder::builder_ui_customiser_disabled() || ! Builder_Permissions::user_has_permission( 'access_builder_interface_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$preferences = ! empty( $_POST['preferences'] ) ? json_decode( wp_unslash( $_POST['preferences'] ), true ) : [];
		$preferences = Builder::sanitize_builder_ui( $preferences );
		$user_id     = get_current_user_id();

		update_user_meta( $user_id, BRICKS_DB_BUILDER_UI_PREFERENCES, $preferences );

		$can_manage_builder_ui_profiles = Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' );

		wp_send_json_success(
			[
				'builderUiDefaults' => Builder::get_builder_ui_defaults(),
				'builderUiUser'     => Builder::get_builder_ui_user( $user_id ),
				'builderUiResolved' => Builder::get_resolved_builder_ui( $user_id ),
				'builderUiProfiles' => $can_manage_builder_ui_profiles ? Builder::get_builder_ui_profiles() : [],
			]
		);
	}

	public function reset_builder_ui_preferences() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( Builder::builder_ui_customiser_disabled() || ! Builder_Permissions::user_has_permission( 'access_builder_interface_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$user_id            = get_current_user_id();
		$current_builder_ui = Builder::get_resolved_builder_ui( $user_id );
		$preferences        = [];

		if ( ! empty( $current_builder_ui['panel']['pinnedControlGroups'] ) ) {
			$preferences['panel']['pinnedControlGroups'] = $current_builder_ui['panel']['pinnedControlGroups'];
		}

		if ( $preferences ) {
			update_user_meta(
				$user_id,
				BRICKS_DB_BUILDER_UI_PREFERENCES,
				Builder::sanitize_builder_ui( $preferences )
			);
		} else {
			delete_user_meta( $user_id, BRICKS_DB_BUILDER_UI_PREFERENCES );
		}

		$can_manage_builder_ui_profiles = Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' );

		wp_send_json_success(
			[
				'builderUiDefaults' => Builder::get_builder_ui_defaults(),
				'builderUiUser'     => Builder::get_builder_ui_user( $user_id ),
				'builderUiResolved' => Builder::get_resolved_builder_ui( $user_id ),
				'builderUiProfiles' => $can_manage_builder_ui_profiles ? Builder::get_builder_ui_profiles() : [],
			]
		);
	}

	/**
	 * Save builder interface profile.
	 *
	 * @return void
	 */
	public function save_builder_ui_profile() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( Builder::builder_ui_customiser_disabled() || ! Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$profile_id = ! empty( $_POST['profileId'] )
			? sanitize_key( wp_unslash( $_POST['profileId'] ) )
			: '';
		$label      = ! empty( $_POST['label'] )
			? sanitize_text_field( wp_unslash( $_POST['label'] ) )
			: '';
		$config     = ! empty( $_POST['config'] )
			? json_decode( wp_unslash( $_POST['config'] ), true )
			: [];

		if ( ! $label ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Name is required', 'bricks' ) ] );
		}

		$profiles = Builder::get_builder_ui_profiles();

		if ( ! $profile_id ) {
			do {
				$profile_id = sanitize_key( Helpers::generate_random_id( false ) );
			} while ( isset( $profiles[ $profile_id ] ) );
		}

		$profiles[ $profile_id ] = [
			'id'     => $profile_id,
			'label'  => $label,
			'config' => Builder::sanitize_builder_ui( is_array( $config ) ? $config : [] ),
		];

		Builder::save_builder_ui_profiles( $profiles );

		$user_id = get_current_user_id();

		wp_send_json_success(
			[
				'builderUiDefaults' => Builder::get_builder_ui_defaults(),
				'builderUiUser'     => Builder::get_builder_ui_user( $user_id ),
				'builderUiResolved' => Builder::get_resolved_builder_ui( $user_id ),
				'builderUiProfiles' => Builder::get_builder_ui_profiles(),
				'profileId'         => $profile_id,
			]
		);
	}

	/**
	 * Delete builder interface profile.
	 *
	 * @return void
	 */
	public function delete_builder_ui_profile() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( Builder::builder_ui_customiser_disabled() || ! Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$profile_id = ! empty( $_POST['profileId'] )
			? sanitize_key( wp_unslash( $_POST['profileId'] ) )
			: '';

		if ( ! $profile_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Name is required', 'bricks' ) ] );
		}

		$profiles = Builder::get_builder_ui_profiles();

		if ( isset( $profiles[ $profile_id ] ) ) {
			unset( $profiles[ $profile_id ] );
		}

		Builder::save_builder_ui_profiles( $profiles );

		$assignments = Builder::get_builder_ui_profile_assignments();

		foreach ( [ 'roles', 'users' ] as $assignment_type ) {
			foreach ( $assignments[ $assignment_type ] as $subject => $assigned_profile_id ) {
				if ( $assigned_profile_id === $profile_id ) {
					unset( $assignments[ $assignment_type ][ $subject ] );
				}
			}
		}

		Builder::save_builder_ui_profile_assignments( $assignments );

		$user_id = get_current_user_id();

		wp_send_json_success(
			[
				'builderUiDefaults' => Builder::get_builder_ui_defaults(),
				'builderUiUser'     => Builder::get_builder_ui_user( $user_id ),
				'builderUiResolved' => Builder::get_resolved_builder_ui( $user_id ),
				'builderUiProfiles' => Builder::get_builder_ui_profiles(),
			]
		);
	}

	/**
	 * Save builder state 'off' (enabled by default)
	 *
	 * @since 1.3.2
	 */
	public function save_builder_scale_off() {
		self::verify_request( 'bricks-nonce-builder' );

		$scale_off = isset( $_POST['off'] ) ? $_POST['off'] == 'true' : false;
		$user_id   = get_current_user_id();

		if ( $scale_off ) {
			update_user_meta( $user_id, BRICKS_DB_BUILDER_SCALE_OFF, true );
		} else {
			delete_user_meta( $user_id, BRICKS_DB_BUILDER_SCALE_OFF );
		}

		wp_send_json_success(
			[
				'scale_off' => $scale_off,
				'user_id'   => $user_id,
			]
		);
	}

	/**
	 * Save builder width locked state (disabled by default)
	 *
	 * Only apply for bas breakpoint. Allows users on smaller screen not having to set a custom width on every page load.
	 *
	 * @since 1.3.2
	 */
	public function save_builder_width_locked() {
		self::verify_request( 'bricks-nonce-builder' );

		$preview_width = isset( $_POST['width'] ) ? intval( $_POST['width'] ) : false;
		$user_id       = get_current_user_id();

		if ( $preview_width ) {
			update_user_meta( $user_id, BRICKS_DB_BUILDER_WIDTH_LOCKED, $preview_width );
		} else {
			delete_user_meta( $user_id, BRICKS_DB_BUILDER_WIDTH_LOCKED );
		}

		wp_send_json_success(
			[
				'preview_width' => $preview_width,
				'user_id'       => $user_id,
			]
		);
	}

	/**
	 * Optionally append the URL path to post titles in the builder.
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post ID.
	 *
	 * @return string
	 *
	 * @since 2.4
	 */
	public function add_url_path_to_post_title( $title, $post_id ) {
		if ( ! Database::get_setting( 'builderPostSelectorShowUrlPath', false ) ) {
			return $title;
		}

		$permalink = get_permalink( $post_id );
		// Preserve query strings used by drafts and plain permalink structures (for example, "/?p=299").
		$relative_url = $permalink ? wp_make_link_relative( $permalink ) : '';

		if ( ! $relative_url ) {
			return $title;
		}

		$relative_url = $relative_url === '/' ? $relative_url : untrailingslashit( $relative_url );

		return sprintf( '%1$s (%2$s)', $title, esc_html( $relative_url ) );
	}

	/**
	 * Get pages
	 *
	 * @since 1.0
	 */
	public function get_pages() {
		self::verify_request( 'bricks-nonce-builder' );

		$locale_switched = switch_to_locale( Builder::get_builder_locale( determine_locale() ) );

		$post_type     = ! empty( $_POST['postType'] ) ? sanitize_text_field( $_POST['postType'] ) : 'page';
		$paginated     = ! empty( $_POST['paginated'] );
		$page          = ! empty( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page      = ! empty( $_POST['perPage'] ) ? min( 100, max( 1, absint( $_POST['perPage'] ) ) ) : 20;
		$status        = ! empty( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
		$search        = ! empty( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$image_size    = ! empty( $_POST['imageSize'] ) ? sanitize_text_field( $_POST['imageSize'] ) : 'large';
		$orderby       = ! empty( $_POST['orderby'] ) ? sanitize_key( $_POST['orderby'] ) : 'date';
		$order         = ! empty( $_POST['order'] ) && strtoupper( $_POST['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$orderby       = in_array( $orderby, [ 'date', 'modified', 'title' ], true ) ? $orderby : 'date';
		$media_filters = isset( $_POST['mediaFilters'] ) && is_array( $_POST['mediaFilters'] )
			? wp_unslash( $_POST['mediaFilters'] )
			: [];
		$media_view    = ! empty( $_POST['mediaView'] ) &&
			sanitize_key( $_POST['mediaView'] ) === 'trash' &&
			Media_Browser_Bulk::trash_enabled()
				? 'trash'
				: 'library';
		$media_folder  = isset( $_POST['mediaFolder'] ) && is_scalar( $_POST['mediaFolder'] )
			? sanitize_text_field( wp_unslash( $_POST['mediaFolder'] ) )
			: '';

		if ( $post_type === 'attachment' ) {
			$media_filters['folderId'] = $media_folder;
		}

		$include_media_selection_data = ! empty( $_POST['includeMediaSelectionData'] );
		$include_media_health         = $post_type === 'attachment' && ! $include_media_selection_data && Media_Browser_Health::enabled();

		$query_args = [
			'posts_per_page'   => $paginated ? $per_page : -1,
			'paged'            => $paginated ? $page : 1,
			'orderby'          => $orderby,
			'order'            => $order,
			'post_status'      => $status ? $status : ( $post_type === 'attachment' ? ( $media_view === 'trash' ? 'trash' : [ 'inherit', 'private' ] ) : 'any' ),
			'post_type'        => $post_type,
			'fields'           => 'ids',
			'no_found_rows'    => ! $paginated,
			'suppress_filters' => true, // WPML (also prevents any posts_where filters from modifying the query)
			'lang'             => '', // Polylang
		];

		if ( $post_type === 'attachment' && ! empty( $_POST['mediaAttachmentId'] ) && is_scalar( $_POST['mediaAttachmentId'] ) ) {
			$query_args['p'] = absint( $_POST['mediaAttachmentId'] );
		}

		if ( $post_type !== 'attachment' ) {
			$query_args['perm'] = 'editable';
		}

		if ( $search && $post_type !== 'attachment' ) {
			$query_args['s'] = $search;
		}

		// NOTE: Undocumented
		$query_args = apply_filters( 'bricks/ajax/get_pages_args', $query_args );

		$folder_provider        = null;
		$folder_root_query_args = [];

		if ( $post_type !== 'attachment' ) {
			$folder_provider = Media_Folder_Providers::get_active( $post_type );

			if ( $folder_provider ) {
				$folder_root_query_args                   = $query_args;
				$folder_root_query_args['post_status']    = 'any';
				$folder_root_query_args['paged']          = 1;
				$folder_root_query_args['posts_per_page'] = 1;
				$folder_root_query_args['no_found_rows']  = false;
				unset( $folder_root_query_args['s'] );

				$query_args = $folder_provider->apply_query( $query_args, $media_folder );
			}
		}

		if ( $post_type === 'attachment' ) {
			$media_query = Media_Browser_Query::run( $query_args, $search, $media_filters );

			if ( is_wp_error( $media_query ) ) {
				$status     = $media_query->get_error_code() === 'media_metadata_index_incomplete' ? 409 : 400;
				$error_data = $media_query->get_error_data();

				wp_send_json_error(
					[
						'code'          => $media_query->get_error_code(),
						'message'       => $media_query->get_error_message(),
						'metadataIndex' => is_array( $error_data ) && isset( $error_data['metadataIndex'] )
							? $error_data['metadataIndex']
							: Media_Browser_Query::get_metadata_index_state(),
					],
					$status
				);
			}

			$page_ids = $media_query['ids'];
		} else {
			$posts_query = new \WP_Query( $query_args );
			$page_ids    = $posts_query->posts;
		}

		// Media_Browser_Query applies WordPress media-library visibility before calculating totals and pages.
		if ( $post_type !== 'attachment' ) {
			$page_ids = array_values(
				array_filter(
					$page_ids,
					function( $page_id ) {
						return current_user_can( 'edit_post', $page_id );
					}
				)
			);
		}

		if ( $paginated ) {
			if ( $post_type === 'attachment' ) {
				$total       = (int) $media_query['total'];
				$total_pages = (int) $media_query['totalPages'];
			} else {
				$total       = (int) $posts_query->found_posts;
				$total_pages = (int) $posts_query->max_num_pages;
			}
		} else {
			$total       = count( $page_ids );
			$total_pages = 1;
		}

		$post_type_total = $total;

		if ( $folder_provider && ( $media_folder !== '' || $search || $status ) ) {
			$folder_root_query = new \WP_Query( $folder_root_query_args );
			$post_type_total   = (int) $folder_root_query->found_posts;
		}

		$pages = [];

		foreach ( $page_ids as $page_id ) {
			$page_title     = wp_kses_post( get_the_title( $page_id ) );
			$page_post_type = get_post_type( $page_id );

			// NOTE: Undocumented
			$page_title = apply_filters( 'bricks/builder/post_title', $page_title, $page_id );

			$page_data = [
				'id'        => $page_id,
				'title'     => $page_title,
				'postType'  => $page_post_type,
				'canEdit'   => current_user_can( 'edit_post', $page_id ),
				'status'    => get_post_status( $page_id ),
				'slug'      => get_post_field( 'post_name', $page_id ),
				'date'      => get_the_date( '', $page_id ),
				'author'    => get_the_author_meta( 'display_name', get_post_field( 'post_author', $page_id ) ),
				'editUrl'   => Helpers::get_builder_edit_link( $page_id ),
				'editUrlWp' => get_edit_post_link( $page_id, 'raw' ),
				'permalink' => get_permalink( $page_id ),
			];

			if ( $page_post_type === 'attachment' ) {
				$attachment_url         = wp_get_attachment_url( $page_id );
				$attached_file          = get_attached_file( $page_id );
				$attachment_path        = $attachment_url ? wp_parse_url( $attachment_url, PHP_URL_PATH ) : '';
				$attachment_metadata    = wp_get_attachment_metadata( $page_id );
				$attachment_file_size   = is_array( $attachment_metadata ) && isset( $attachment_metadata['filesize'] )
					? $attachment_metadata['filesize']
					: false;
				$page_data['permalink'] = $attachment_url ? $attachment_url : get_permalink( $page_id );
				$page_data['filename']  = $attached_file ? wp_basename( $attached_file ) : ( $attachment_path ? wp_basename( $attachment_path ) : '' );
				$page_data['mimeType']  = get_post_mime_type( $page_id );
				$page_data['canDelete'] = current_user_can( 'delete_post', $page_id );

				if ( ! is_numeric( $attachment_file_size ) && $attached_file && is_file( $attached_file ) ) {
					$attachment_file_size = filesize( $attached_file );
				}

				$page_data['fileSize'] = is_numeric( $attachment_file_size )
					? size_format( (int) $attachment_file_size )
					: '';

				if ( wp_attachment_is_image( $page_id ) ) {
					$page_data['image'] = wp_get_attachment_image_url( $page_id, $image_size );
				}

				if ( $include_media_health ) {
					$page_data['health'] = Media_Browser_Health::get_result( $page_id, false );
				}

				if ( $include_media_selection_data ) {
					$attachment_sizes = [];

					if ( wp_attachment_is_image( $page_id ) ) {
						$size_names   = ! empty( $attachment_metadata['sizes'] ) ? array_keys( $attachment_metadata['sizes'] ) : [];
						$size_names[] = 'full';

						foreach ( array_unique( $size_names ) as $size_name ) {
							$size_data = wp_get_attachment_image_src( $page_id, $size_name );

							if ( ! is_array( $size_data ) || empty( $size_data[0] ) ) {
								continue;
							}

							$attachment_sizes[ $size_name ] = [
								'url'    => $size_data[0],
								'width'  => (int) ( $size_data[1] ?? 0 ),
								'height' => (int) ( $size_data[2] ?? 0 ),
							];
						}
					}

					$page_data['attachment'] = [
						'id'       => $page_id,
						'filename' => $page_data['filename'],
						'mime'     => $page_data['mimeType'],
						'url'      => $attachment_url ? $attachment_url : '',
						'sizes'    => $attachment_sizes,
					];
				}
			} elseif ( has_post_thumbnail( $page_id ) ) {
				$page_data['image'] = get_the_post_thumbnail_url( $page_id, $image_size );
			}

			$pages[] = $page_data;
		}

		if ( $paginated ) {
			$post_statuses = [];

			if ( $post_type !== 'attachment' ) {
				$status_counts = wp_count_posts( $post_type, 'readable' );

				foreach ( get_post_stati( [], 'objects' ) as $post_status => $post_status_object ) {
					if ( empty( $status_counts->{$post_status} ) || $post_status_object->internal ) {
						continue;
					}

					$post_statuses[ $post_status ] = $post_status_object->label;
				}
			}

			$response = [
				'posts'               => $pages,
				'total'               => $total,
				'totalPages'          => $total_pages,
				'postTypeTotal'       => $post_type_total,
				'statusOptions'       => $post_statuses,
				'mediaFolderProvider' => Media_Browser_Bulk::get_folder_provider( $post_type ),
			];

			if ( $post_type !== 'attachment' ) {
				$post_type_object = get_post_type_object( $post_type );

				$response['postTypePluralLabel'] = $post_type_object
					? ( $post_type_object->labels->name ?? $post_type_object->label ?? '' )
					: '';
			}

			if ( $post_type === 'attachment' ) {
				$response['mediaFilterOptions'] = Media_Browser_Query::get_filter_options();
				$response['mediaTrashEnabled']  = Media_Browser_Bulk::trash_enabled();
				$response['mediaCapabilities']  = Media_Browser_Query::get_current_user_capabilities();

				if ( $include_media_health ) {
					$response['mediaHealthSummary'] = Media_Browser_Health::get_summary();
				}
			}

			if ( $locale_switched ) {
				restore_previous_locale();
			}

			wp_send_json_success( $response );
		}

		if ( $locale_switched ) {
			restore_previous_locale();
		}

		wp_send_json_success( $pages );
	}

	/**
	 * Create new page
	 *
	 * @since 1.0
	 * @since 2.0: Command palette support
	 */
	public function create_new_page() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( Admin::EDITING_CAP ) ) {
			wp_send_json_error( esc_html__( 'Not allowed', 'bricks' ) );
		}

		$new_page_id = wp_insert_post(
			[
				'post_title' => ! empty( $_POST['title'] ) ? esc_html( $_POST['title'] ) : esc_html__( '(no title)', 'bricks' ),
				'post_type'  => ! empty( $_POST['postType'] ) ? esc_html( $_POST['postType'] ) : 'page',
			]
		);

		Helpers::maybe_save_acf_default_values( $new_page_id );

		$new_post       = get_post( $new_page_id );
		$post_edit_link = admin_url() . 'post.php?post=POST_ID&action=edit';

		$new_post = [
			'id'        => $new_post->ID,
			'title'     => wp_kses_post( $new_post->post_title ),
			'slug'      => $new_post->post_name,
			'postType'  => $new_post->post_type,
			'status'    => $new_post->post_status,
			'editUrl'   => Helpers::get_builder_edit_link( $new_post->ID ),
			'editUrlWp' => str_replace( 'POST_ID', $new_post->ID, $post_edit_link ),
			'permalink' => get_permalink( $new_post->ID ),
		];

		wp_send_json_success( $new_post );
	}

	/**
	 * Duplicate page or post in the builder (Bricks or WordPress)
	 *
	 * @since 1.9.8
	 * @since 2.0: Command palette support
	 */
	public function duplicate_content() {
		self::verify_request( 'bricks-nonce-builder' );

		$post_id = ! empty( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;

		// Return: User can not edit this post
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$new_post_id = Admin::duplicate_content( $post_id );

		if ( ! $new_post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Post could not be duplicated', 'bricks' ) ] );
		}

		$new_post       = get_post( $new_post_id );
		$post_edit_link = admin_url() . 'post.php?post=POST_ID&action=edit';

		$new_post = [
			'id'        => $new_post->ID,
			'title'     => wp_kses_post( $new_post->post_title ),
			'slug'      => $new_post->post_name,
			'postType'  => $new_post->post_type,
			'status'    => $new_post->post_status,
			'editUrl'   => Helpers::get_builder_edit_link( $new_post->ID ),
			'editUrlWp' => str_replace( 'POST_ID', $new_post->ID, $post_edit_link ),
			'permalink' => get_permalink( $new_post->ID ),
		];

		wp_send_json_success( $new_post );
	}

	/**
	 * Decode Woo preview context from a builder request.
	 *
	 * @since 2.4
	 *
	 * @param array $data Optional request data.
	 * @return array
	 */
	private static function get_woo_preview_context_from_request( $data = [] ) {
		$raw_context = null;

		if ( is_array( $data ) && array_key_exists( 'wooPreviewContext', $data ) ) {
			$raw_context = $data['wooPreviewContext'];
		} elseif ( isset( $_POST['wooPreviewContext'] ) ) {
			$raw_context = $_POST['wooPreviewContext'];
		}

		if ( is_string( $raw_context ) ) {
			$raw_context     = wp_unslash( $raw_context );
			$decoded_context = json_decode( $raw_context, true );

			return is_array( $decoded_context ) ? $decoded_context : [];
		}

		return is_array( $raw_context ) ? $raw_context : [];
	}

	/**
	 * Push Woo preview context for the current builder request.
	 *
	 * @since 2.4
	 *
	 * @param array $data Optional request data.
	 * @return array Pushed Woo preview context keys.
	 */
	private static function push_woo_preview_context_from_request( $data = [] ) {
		if ( ! ( class_exists( '\Bricks\Woocommerce' ) && \Bricks\Woocommerce::is_woocommerce_active() ) ) {
			return [];
		}

		$woo_preview_context = self::get_woo_preview_context_from_request( $data );
		$checkout_v2_context = $woo_preview_context['checkoutV2'] ?? [];
		$account_v2_context  = $woo_preview_context['accountV2'] ?? [];
		$pushed_contexts     = [
			'checkoutV2' => [],
			'accountV2'  => [],
		];

		if ( is_array( $checkout_v2_context ) ) {
			foreach ( $checkout_v2_context as $state => $state_context ) {
				if ( ! is_array( $state_context ) ) {
					continue;
				}

				$state = sanitize_key( $state );

				if ( ! $state ) {
					continue;
				}

				\Bricks\Woocommerce::push_checkout_v2_state_preview_context( $state, $state_context );
				$pushed_contexts['checkoutV2'][] = $state;
			}
		}

		if ( is_array( $account_v2_context ) ) {
			foreach ( $account_v2_context as $state => $state_context ) {
				if ( ! is_array( $state_context ) ) {
					continue;
				}

				$state = sanitize_key( $state );

				if ( ! $state ) {
					continue;
				}

				\Bricks\Woocommerce::push_account_v2_state_preview_context( $state, $state_context );
				$pushed_contexts['accountV2'][] = $state;
			}
		}

		return $pushed_contexts;
	}

	/**
	 * Pop Woo preview context pushed for the current builder request.
	 *
	 * @since 2.4
	 *
	 * @param array $pushed_contexts Woo preview context keys.
	 * @return void
	 */
	private static function pop_woo_preview_context_from_request( $pushed_contexts = [] ) {
		if ( ! ( class_exists( '\Bricks\Woocommerce' ) && \Bricks\Woocommerce::is_woocommerce_active() ) ) {
			return;
		}

		$checkout_v2_states = is_array( $pushed_contexts ) && isset( $pushed_contexts['checkoutV2'] ) ? $pushed_contexts['checkoutV2'] : [];
		$account_v2_states  = is_array( $pushed_contexts ) && isset( $pushed_contexts['accountV2'] ) ? $pushed_contexts['accountV2'] : [];

		foreach ( array_reverse( (array) $checkout_v2_states ) as $state ) {
			\Bricks\Woocommerce::pop_checkout_v2_state_preview_context( $state );
		}

		foreach ( array_reverse( (array) $account_v2_states ) as $state ) {
			\Bricks\Woocommerce::pop_account_v2_state_preview_context( $state );
		}
	}

	/**
	 * Render element HTML from settings
	 *
	 * AJAX call / REST API call: In-builder (getHTML for PHP-rendered elements)
	 *
	 * @since 1.0
	 */
	public static function render_element( $data ) {
		$is_ajax          = bricks_is_ajax_call();
		$auto_signed_code = self::get_empty_auto_signed_code_response();

		if ( $is_ajax && isset( $_POST ) ) {
			$data = $_POST;
		}

		$loop_element           = $data['loopElement'] ?? false;
		$element                = $data['element'] ?? false;
		$element_name           = $element['name'] ?? false;
		$parent_loops           = $data['parentLoops'] ?? [];
		$return_html_attributes = rest_sanitize_boolean( $data['renderAttributes'] ?? false );

		// AJAX call
		if ( isset( $_POST['element'] ) ) {
			self::verify_request( 'bricks-nonce-builder' );

			$element = stripslashes_deep( $element );
		}

		if ( Helpers::auto_sign_code_on_builder_rerender_enabled() && is_array( $element ) ) {
			$processed_elements = self::maybe_auto_sign_builder_elements( [ $element ], $auto_signed_code );
			$element            = $processed_elements[0] ?? $element;
			$element_name       = $element['name'] ?? $element_name;
		}

		// No additional processing needed for REST API calls or builder.php
		// REST API permissions are already checked in API->render_element_permissions_check()
		$pushed_woo_preview_contexts = self::push_woo_preview_context_from_request( $data );

		$response_data = null;

		try {

			/**
			 * Builder: Init Query to get the builder preview for the first loop item (e.g.: "Product Category Image" DD)
			 *
			 * @since 1.4
			 */
			if ( ! empty( $loop_element ) ) {
				// If it's an element inside a component, should use the component data (@since 1.12)
				$cid       = $loop_element['cid'] ?? false;
				$component = $element['componentInstance'] ?? false;

				if ( $cid && isset( $component['id'] ) && $component['id'] === $cid ) {
					$loop_element = $component;
				}

				// Run parent queries (@since 1.12.2)
				if ( ! empty( $parent_loops ) ) {
					foreach ( $parent_loops as $parent_loop ) {
						// Run each parent query
						self::simulate_bricks_query( $parent_loop );
					}
				}

				// Run the current query
				self::simulate_bricks_query( $loop_element );
			}

			/**
			 * STEP: Resolve parent property connections in element settings (nested components)
			 *
			 * Get request elements to resolve parent properties with live builder data
			 *
			 * @since 2.2
			 */
			$request_elements = [];
			if ( isset( $element['componentInstance']['elements'] ) && is_array( $element['componentInstance']['elements'] ) ) {
				$request_elements = $element['componentInstance']['elements'];
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( $element['settings'] as $setting_key => $setting_value ) {
					// Check if the setting value is a parent property connection string
					if ( is_string( $setting_value ) && strpos( $setting_value, 'parent:' ) === 0 ) {
						// Resolve the parent property value
						$resolved_value = Helpers::resolve_parent_property_value( $setting_value, $request_elements );

						// Replace the connection string with the resolved value
						$element['settings'][ $setting_key ] = $resolved_value;
					}
				}
			}

			// Init element class (i.e. new Bricks\Element_Alert( $element ))
			$element_class_name = isset( Elements::$elements[ $element_name ]['class'] ) ? Elements::$elements[ $element_name ]['class'] : false;

			if ( class_exists( $element_class_name ) ) {
				$element['is_frontend'] = false;

				$element_instance = new $element_class_name( $element );

				if ( $return_html_attributes ) {
					unset( Builder::$html_attributes[ $element_instance->uid ] );
				}

				$element_instance->load();

				// Init element: enqueue styles/scripts, render element
				ob_start();
				$element_instance->init();
				$response = ob_get_clean();

				// Similar in frontend.php (#86c4mgk80; @since 2.4)
				$response = apply_filters( 'bricks/frontend/render_element', $response, $element_instance );
			}

			// Element doesn't exist
			else {
				// translators: %s: Element name
				$no_element_text = sprintf( esc_html__( 'Element "%s" doesn\'t exist.', 'bricks' ), $element_name );

				// Check: Element is disabled via element manager (@since 2.0)
				if ( isset( Elements::$manager[ $element_name ]['status'] ) && Elements::$manager[ $element_name ]['status'] === 'disabled' ) {
					$no_element_text = esc_html__( 'Element has been disabled globally.', 'bricks' ) . ' (<a href="' . admin_url( 'admin.php?page=bricks-elements' ) . '" target="_blank">Bricks > ' . esc_html__( 'Elements', 'bricks' ) . '</a>)';
				}

				$no_element_text = "$element_name: $no_element_text";

				$response = '<div class="bricks-element-placeholder no-php-class">' . $no_element_text . '</div>';
			}

			$response_data = [
				'html' => $response,
			];

			if ( $return_html_attributes && isset( $element_instance ) ) {
				$uid = $element_instance->uid;

				$response_data['htmlAttributes'] = [
					'uid'   => $uid,
					'value' => Builder::$html_attributes[ $uid ] ?? null,
				];
			}

			// Template element: Add additional builder data (CSS & list of elements to run scripts (@since 1.5))
			if ( $element_name === 'template' ) {
				$template_id = ! empty( $element['settings']['template'] ) ? $element ['settings']['template'] : false;

				if ( $template_id ) {
					$additional_data = Element_Template::get_builder_call_additional_data( $template_id );

					$response_data = array_merge( $response_data, $additional_data );
				}
			}
		} finally {
			self::pop_woo_preview_context_from_request( $pushed_woo_preview_contexts );
		}

		if ( self::auto_signed_code_response_has_entries( $auto_signed_code ) ) {
			$response_data['autoSignedCode'] = $auto_signed_code;
		}

		if ( $is_ajax ) {
			// Subsequent element render via AJAX call
			wp_send_json_success( $response_data );
		}

		// Initial element render via PHP or REST API
		else {
			return $response_data;
		}
	}

	/**
	 * Generate the HTML based on the builder content data (post Id or content)
	 *
	 * Used to feed Rank Math SEO & Yoast content analysis (@since 1.11)
	 *
	 * NOTE: This method doesn't generate any styles!
	 */
	public function get_html_from_content() {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			$nonce = 'bricks-nonce-builder';
		} elseif ( is_admin() ) {
			$nonce = 'bricks-nonce-admin';
		} else {
			$nonce = 'bricks-nonce';
		}

		self::verify_request( $nonce );

		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;

		// Set active templates for the current post
		Database::set_active_templates( $post_id );

		// Get the content data
		$data = Helpers::get_bricks_data( $post_id, 'content' );

		// Limit the content-analysis More block handling to this post ID during this AJAX render.
		$full_post_content_id = function() use ( $post_id ) {
			return $post_id;
		};

		add_filter( 'bricks/content_analysis/full_post_content_id', $full_post_content_id );

		try {
			$html = ! empty( $data ) ? Frontend::render_data( $data ) : '';
		} finally {
			remove_filter( 'bricks/content_analysis/full_post_content_id', $full_post_content_id );
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Get template elements by template ID
	 *
	 * To generate global classes CSS in builder.
	 *
	 * @since 1.8.2
	 */
	public function get_template_elements_by_id() {
		self::verify_request( 'bricks-nonce-builder' );

		$template_ids            = ! empty( $_POST['templateIds'] ) ? self::decode( $_POST['templateIds'] ) : [];
		$template_elements_by_id = [];

		// CSS lookup needs readable templates, including for users who cannot edit templates.
		$can_read_template = static function( $template_id ) {
			$template_id = filter_var( $template_id, FILTER_VALIDATE_INT );

			return $template_id > 0 && get_post_type( $template_id ) === BRICKS_DB_TEMPLATE_SLUG && current_user_can( 'read_post', $template_id );
		};

		if ( is_array( $template_ids ) ) {
			foreach ( $template_ids as $template_id ) {
				if ( ! $can_read_template( $template_id ) ) {
					continue;
				}

				$template_elements = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );

				// Builder class CSS needs the element names inside nested Template elements too.
				// Otherwise global class styles render on the frontend, but not on the canvas (#86bz9arh2; @since 2.3.9).
				$template_elements_by_id[ $template_id ] = Database::get_nested_template_data( $template_elements, [ $template_id ], 0, 10, $can_read_template );
			}
		}

		wp_send_json_success( $template_elements_by_id );
	}

	/**
	 * Query control: Get posts
	 *
	 * @since 1.0
	 */
	public function get_posts() {
		if ( ! check_ajax_referer( 'bricks-nonce-builder', 'nonce', false ) ) {
			if ( ! check_ajax_referer( 'bricks-nonce-admin', 'nonce', false ) ) {
				wp_send_json_error( 'Invalid nonce' );
			}
		}

		$post_type = 'any';

		// Get specific post type
		if ( ! empty( $_GET['postType'] ) ) {
			$post_type = array_map( 'sanitize_text_field', (array) $_GET['postType'] );
		}

		// Resolve 'any' explicitly so builder searches include public post types excluded from frontend search.
		if ( $post_type === 'any' || $post_type === [ 'any' ] ) {
			$post_type = array_keys( get_post_types( [ 'public' => true ] ) );
		}

		// Exclude specific post types if requested
		if ( ! empty( $_GET['excludePostTypes'] ) ) {
			$exclude_post_types = array_map( 'sanitize_text_field', (array) $_GET['excludePostTypes'] );

			if ( is_array( $post_type ) ) {
				$post_type = array_diff( $post_type, $exclude_post_types );
			}
		}

		// Set query args
		$query_args = [ 'post_type' => $post_type ];

		// Necessary to retrieve more than 2 posts initially
		if ( $post_type !== 'any' ) {
			$query_args['orderby'] = 'date';
		}

		if ( ! empty( $_GET['search'] ) ) {
			$query_args['s']       = stripslashes_deep( sanitize_text_field( $_GET['search'] ) );
			$query_args['orderby'] = 'relevance'; // (#86c0zr03p)
		}

		// @since 2.0
		if ( ! empty( $_GET['postStatus'] ) ) {
			$query_args['post_status'] = sanitize_text_field( $_GET['postStatus'] );
		}

		$posts = Helpers::get_posts_by_post_id( $query_args );

		foreach ( $posts as $post_id => $post_title ) {
			// NOTE: Undocumented
			$posts[ $post_id ] = apply_filters( 'bricks/builder/post_title', $post_title, $post_id );
		}

		// If AJAX request contains "include" parameter, make sure some post_ids are included in the response
		if ( ! empty( $_GET['include'] ) ) {
			$include_post_ids = (array) $_GET['include'];
			$include_post_ids = array_map( 'intval', $include_post_ids );

			foreach ( $include_post_ids as $post_id ) {
				if ( ! array_key_exists( $post_id, $posts ) ) {
					$post_title = get_the_title( $post_id );

					if ( Database::get_setting( 'builderPostSelectorShowUrlPath', false ) ) {
						$post_type = get_post_type( $post_id );

						if ( $post_type ) {
							$post_type_object = get_post_type_object( $post_type );
							$post_type_label  = $post_type_object ? $post_type_object->labels->singular_name : ucfirst( $post_type );
							$post_title      .= ' (' . $post_type_label . ')';
							$post_title       = apply_filters( 'bricks/builder/post_title', $post_title, $post_id );
						}
					}

					$posts[ $post_id ] = $post_title;
				}
			}
		}

		wp_send_json_success( $posts );
	}

	/**
	 * Get users
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function get_users() {
		self::verify_request( 'bricks-nonce-builder' );

		$args = [
			'count_total' => false,
			'number'      => 50,
		];

		$search_term = ! empty( $_GET['search'] ) ? stripslashes_deep( sanitize_text_field( $_GET['search'] ) ) : '';
		if ( $search_term ) {
			$args['search'] = $search_term;
		}

		// Query users
		$users = Helpers::get_users_options( $args, true );

		if ( ! empty( $_GET['include'] ) ) {
			$include_user_ids = (array) $_GET['include'];
			$include_user_ids = array_map( 'intval', $include_user_ids );

			foreach ( $include_user_ids as $user_id ) {
				if ( ! array_key_exists( $user_id, $users ) ) {
					$user = get_userdata( $user_id );
					if ( $user ) {
						$users[ $user_id ] = $user->display_name;
					}
				}
			}
		}

		wp_send_json_success( $users );
	}

	/**
	 * Get terms
	 *
	 * @since 1.0
	 */
	public function get_terms_options() {
		if ( ! check_ajax_referer( 'bricks-nonce-builder', 'nonce', false ) ) {
			if ( ! check_ajax_referer( 'bricks-nonce-admin', 'nonce', false ) ) {
				wp_send_json_error( 'Invalid nonce' );
			}
		}

		$post_types = ! empty( $_GET['postTypes'] ) ? array_map( 'sanitize_text_field', $_GET['postTypes'] ) : null;
		$taxonomy   = ! empty( $_GET['taxonomy'] ) ? array_map( 'sanitize_text_field', $_GET['taxonomy'] ) : null;

		// Filter out 'undefined' from taxonomy list (JS quirk)
		if ( is_array( $taxonomy ) ) {
			$taxonomy = array_filter(
				$taxonomy,
				function( $t ) {
					return $t !== 'undefined';
				}
			);

			if ( empty( $taxonomy ) ) {
				$taxonomy = null;
			}
		}

		$search      = ! empty( $_GET['search'] ) ? stripslashes_deep( sanitize_text_field( $_GET['search'] ) ) : ''; // Improve performance (@since 1.12)
		$include_all = ! empty( $_GET['includeAll'] ) ? true : null;
		$terms       = [];

		if ( ! empty( $post_types ) ) {
			foreach ( (array) $post_types as $post_type ) {
				$type_terms = Helpers::get_terms_options( $taxonomy, $post_type, $include_all, $search );

				if ( ! empty( $type_terms ) ) {
					$terms = array_merge( $terms, $type_terms );
				}
			}
		} elseif ( ! empty( $taxonomy ) ) {
			$terms = Helpers::get_terms_options( $taxonomy, null, $include_all, $search );
		}

		// If AJAX request contains "include" parameter, make sure some term_ids are included in the response (@since 1.12)
		if ( ! empty( $_GET['include'] ) ) {
			$include_terms = (array) $_GET['include'];

			foreach ( $include_terms as $term_string ) {
				if ( ! array_key_exists( $term_string, $terms ) ) {
					$term_parts = explode( '::', $term_string );
					$taxonomy   = sanitize_key( $term_parts[0] );
					$term_id    = sanitize_key( $term_parts[1] );

					if ( $term_id !== 0 && taxonomy_exists( $taxonomy ) ) {
						$term_name      = '';
						$taxonomy_label = Helpers::generate_taxonomy_label( $taxonomy );

						if ( $term_id === 'all' ) {
							$term_name = esc_html__( 'All terms', 'bricks' );
						} else {
							$term = get_term( $term_id, $taxonomy );
							if ( $term && ! is_wp_error( $term ) ) {
								$term_name = $term->name;
							}
						}

						$terms[ $term_string ] = "{$term_name} ({$taxonomy_label})";
					}
				}
			}
		}

		// Apply filter to each term name (@since 1.11)
		foreach ( $terms as $key => $value ) {
			$term_parts = explode( '::', $key );

			if ( count( $term_parts ) === 2 ) {
				$taxonomy = sanitize_key( $term_parts[0] );
				$term_id  = sanitize_key( $term_parts[1] );

				if ( $term_id !== 0 && $term_id !== 'all' && taxonomy_exists( $taxonomy ) ) {
					// NOTE: Undocumented (@since 1.11)
					$filtered_name = apply_filters( 'bricks/builder/term_name', $value, $term_id, $taxonomy );
					$terms[ $key ] = sanitize_text_field( $filtered_name );
				}
			}
		}

		wp_send_json_success( $terms );
	}

	/**
	 * Render Bricks data for static header/content/footer and query loop preview HTML in builder
	 *
	 * @since 1.0
	 */
	public static function render_data() {
		self::verify_request( 'bricks-nonce-builder' );

		$pushed_woo_preview_contexts = self::push_woo_preview_context_from_request();

		$auto_signed_code      = self::get_empty_auto_signed_code_response();
		$dangerously_auto_sign = Helpers::auto_sign_code_on_builder_rerender_enabled();
		$response_data         = null;

		try {
			if ( empty( $_POST['elements'] ) ) {
				return;
			}

			$area     = ! empty( $_POST['area'] ) ? sanitize_text_field( $_POST['area'] ) : 'content';
			$post_id  = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;
			$elements = self::decode( $_POST['elements'], false );
			$elements = array_map( 'Bricks\Helpers::set_is_frontend_to_false', $elements );

			// Use global classes data from builder not from database
			if ( ! empty( $_POST['globalClasses'] ) ) {
				Database::$global_data['globalClasses'] = self::decode( $_POST['globalClasses'], false );
			}

			// Use global queries data from builder not from database (@since 2.1)
			if ( ! empty( $_POST['globalQueries'] ) ) {
				$global_queries = self::decode( $_POST['globalQueries'], false );

				if ( $dangerously_auto_sign ) {
					$global_queries = self::maybe_auto_sign_builder_global_queries( $global_queries, $auto_signed_code );
				}

				Database::$global_data['globalQueries'] = $global_queries;
			}

			/**
			 * Use real-time, possibly unsaved components data from builder to generate in-builder styles correctly
			 *
			 * Needed as components data in builder is unsaved and not yet in database.
			 *
			 * @since 1.12
			 */
			$components = isset( $_POST['components'] ) ? self::decode( $_POST['components'], false ) : false;
			if ( $components ) {
				Database::$global_data['components'] = $components;
			}

			// Set Theme Styles (for correct preview of query loop nodes)
			Theme_Styles::load_set_styles( $post_id );

			// Use global elements data from builder (@since 1.7.1)
			$global_elements = ! empty( $_POST['globalElements'] ) ? $_POST['globalElements'] : [];

			if ( is_array( $global_elements ) && count( $global_elements ) ) {
				foreach ( $elements as $index => $element ) {
					$global_element_id = $element['global'] ?? false;

					if ( $global_element_id ) {
						foreach ( $global_elements as $global_element ) {
							if ( ! empty( $global_element['global'] ) && $global_element['global'] == $global_element_id ) {
								$elements[ $index ]['settings'] = $global_element['settings'];

								// To skip getting element setting from db in Frontend::render_data() > render_element() later on
								$elements[ $index ]['global_settings_checked'] = true;
							}
						}
					}
				}
			}

			if ( $dangerously_auto_sign ) {
				$elements = self::maybe_auto_sign_builder_elements( $elements, $auto_signed_code );
			}

			// Generate query loop styles for dynamic data
			$loop_name = "loop_{$post_id}";

			// Use loop element ID as loop_name if possible
			if ( isset( $elements[0]['id'] ) ) {
				$loop_name = "loop_{$elements[0]['id']}";
			}

			// Run parent queries (@since 1.12.2)
			$parent_loops = ! empty( $_POST['parentLoops'] ) ? self::decode( $_POST['parentLoops'], false ) : [];
			if ( ! empty( $parent_loops ) ) {
				foreach ( $parent_loops as $parent_loop ) {
					// Run each parent query
					self::simulate_bricks_query( $parent_loop );
				}
			}

			// STEP: Resolve parent property connections in nested components (@since 2.2)
			$parent_element = ! empty( $_POST['element'] ) ? self::decode( $_POST['element'], false ) : false;

			if ( $dangerously_auto_sign && $parent_element ) {
				$processed_parent = self::maybe_auto_sign_builder_elements( [ $parent_element ], $auto_signed_code );
				$parent_element   = $processed_parent[0] ?? $parent_element;
			}

			$elements_with_parent = $elements;
			$parent_instance_id   = '';
			if ( $parent_element && isset( $parent_element['cid'] ) ) {
				// Add parent element to search array so nested components can find it
				array_unshift( $elements_with_parent, $parent_element );
				$parent_instance_id = $parent_element['id'];
			}

			foreach ( $elements as $index => $element ) {
				if ( isset( $element['properties'] ) && is_array( $element['properties'] ) ) {
					foreach ( $element['properties'] as $prop_id => $prop_value ) {
						// Check if property value is a parent property connection string
						if ( is_string( $prop_value ) && strpos( $prop_value, 'parent:' ) === 0 ) {
							// Resolve parent property value using live builder data
							$resolved_value = Helpers::resolve_parent_property_value( $prop_value, $elements_with_parent, $parent_instance_id );

							// Replace connection string with resolved value
							$elements[ $index ]['properties'][ $prop_id ] = $resolved_value;
						}
					}
				}
			}

			/**
			 * Include local slot descendants before CSS generation and render.
			 *
			 * Builder loop previews send component-root query elements from the component tree,
			 * but instance slot content lives in the local page tree. Merge the slot subtree here
			 * so ghost nodes render the same slotted content and styles as the frontend.
			 * (#86c7p02wq; @since 2.4)
			 */
			if ( $parent_element && ! empty( $parent_element['slotChildren'] ) ) {
				$element_indexes_by_id = [];

				foreach ( $elements as $element_index => $element ) {
					if ( empty( $element['id'] ) ) {
						continue;
					}

					$element_id  = (string) $element['id'];
					$element_key = (string) ( $element['slotElementKey'] ?? $element_id );

					// Slot children can share source IDs across component instances, so prefer the contextual key.
					$element_indexes_by_id[ $element_key ] = $element_index;

					if ( ! isset( $element_indexes_by_id[ $element_id ] ) ) {
						$element_indexes_by_id[ $element_id ] = $element_index;
					}
				}

				foreach ( Helpers::get_component_slot_child_elements( $parent_element, $elements ) as $slot_child_element ) {
					if ( empty( $slot_child_element['id'] ) ) {
						continue;
					}

					$slot_child_id = (string) ( $slot_child_element['slotElementKey'] ?? $slot_child_element['id'] );

					if ( isset( $element_indexes_by_id[ $slot_child_id ] ) ) {
						$element_index              = $element_indexes_by_id[ $slot_child_id ];
						$elements[ $element_index ] = array_merge( $elements[ $element_index ], $slot_child_element );
					} else {
						$element_indexes_by_id[ $slot_child_id ] = count( $elements );
						$elements[]                              = $slot_child_element;
					}
				}
			}

			$filtered_elements = $elements;

			// If post-content with source="bricks" is in elements, remove it
			if ( ! Helpers::is_bricks_template( $post_id ) ) {
				$filtered_elements = array_filter(
					$elements,
					function( $element ) {
						return $element['name'] !== 'post-content' || $element['settings']['dataSource'] !== 'bricks';
					}
				);
			}

			// Generate Assets before Frontend render to add 'data-query-loop-index' attribute successfully in builder
			Assets::generate_css_from_elements( $filtered_elements, $loop_name );

			$inline_css = Assets::$inline_css[ $loop_name ] ?? '';

			// Collect HTML strings for all elements start (@since 2.0)
			add_filter( 'bricks/frontend/render_element', [ 'Bricks\Builder', 'collect_elements_html' ], 10, 2 );
			add_filter( 'bricks/frontend/render_loop', [ 'Bricks\Builder', 'collect_looping_html' ], 10, 3 );
			add_action( 'bricks/dynamic_data/tag_value_parsed', [ 'Bricks\Builder', 'collect_looping_dynamic_data' ], 10, 7 ); // (#86c4tzdxq; @since 2.2)

			/**
			 * STEP: Add component instance to elements array so slot.php can find it via slotChildren
			 *
			 * The component instance (with slotChildren) is sent separately in $_POST['element'],
			 * but slot.php's find_component_instance_with_slot() looks for it in Frontend::$elements.
			 *
			 * (#86c7p02wq; @since 2.4)
			 */
			if ( $parent_element && ! empty( $parent_element['slotChildren'] ) ) {
				// Add component instance to elements so slot can find it
				array_unshift( $elements, $parent_element );
			}

			$html = Frontend::render_data( $elements, $area );

			// Collect HTML strings for all elements end (@since 2.0)
			remove_filter( 'bricks/frontend/render_loop', [ 'Bricks\Builder', 'collect_looping_html' ], 10, 3 );
			remove_filter( 'bricks/frontend/render_element', [ 'Bricks\Builder', 'collect_elements_html' ], 10, 2 );
			remove_action( 'bricks/dynamic_data/tag_value_parsed', [ 'Bricks\Builder', 'collect_looping_dynamic_data' ], 10, 7 ); // (#86c4tzdxq; @since 2.2)

			$inline_css .= Assets::$inline_css_dynamic_data;

			/**
			 * Add missing global classes in builder preview if template element loop
			 *
			 * @since 1.8.2 If not static area (global classes are already added in dynamic area)
			 */
			if ( ! isset( $_POST['staticArea'] ) ) {
				$inline_css .= Assets::generate_global_classes();
			}

			$styles = ! empty( $inline_css ) ? "\n<style id=\"bricks-$loop_name\">/* {$loop_name} CSS */\n{$inline_css}</style>\n" : '';

			$data = [
				'html'   => $html,
				'styles' => isset( $_POST['staticArea'] ) ? $inline_css : $styles, // StaticArea.vue: inline CSS only, otherwise full styles with <style> tag (@since 1.12)
			];

			// For builder elements to reduce render_elements API calls (@since 2.0)
			$data['elementsHtml']        = Builder::$elements_html;
			$data['previewTexts']        = Builder::$preview_texts;
			$data['loopingHtml']         = Builder::$looping_html;
			$data['loopVisibleElements'] = Builder::$loop_visible_elements; // For ACF flexible content preview (@since 2.4)
			$data['templatesData']       = Builder::$templates_data;
			$data['loopingDynamicData']  = Builder::$looping_dynamic_data;

			if ( self::auto_signed_code_response_has_entries( $auto_signed_code ) ) {
				$data['autoSignedCode'] = $auto_signed_code;
			}

			// Run query to get query results count in builder (@since 1.9.1)
			$element = ! empty( $_POST['element'] ) ? self::decode( $_POST['element'], false ) : false;

			if ( $element ) {
				$query               = new Query( $element );
				$query_results_count = $query->count;

				$data[ "query_results_count:{$element['id']}" ] = $query_results_count;
			}

			$response_data = $data;
		} finally {
			self::pop_woo_preview_context_from_request( $pushed_woo_preview_contexts );
		}

		if ( is_array( $response_data ) ) {
			wp_send_json_success( $response_data );
		}
	}

	/**
	 * Don't check for chnage when creating revision as all that changed is the postmeta
	 *
	 * @since 1.7
	 */
	public function dont_check_for_revision_changes() {
		return false;
	}

	/**
	 * Save post
	 *
	 * @since 1.0
	 */
	public function save_post() {
		self::verify_request( 'bricks-nonce-builder' );

		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		// Return: No post ID set
		if ( ! $post_id ) {
			wp_send_json_error( 'Error: No postId provided!' );
		}

		$post = get_post( $post_id );

		// Update post at the very end
		$the_post = false;

		/**
		 * Save revision in database
		 */
		$revision_id = 0;

		/**
		 * Bricks elements data changed (header, content, footer, components)
		 *
		 * If not, don't save post & don't create revision
		 */
		$bricks_data_changed   = isset( $_POST['header'] ) || isset( $_POST['content'] ) || isset( $_POST['footer'] ) || isset( $_POST['components'] );
		$dangerously_auto_sign = Helpers::auto_sign_code_on_builder_save_enabled();
		$auto_signed_code      = self::get_empty_auto_signed_code_response();

		// Page settings changed: Re-generate external CSS file
		if ( ! $bricks_data_changed ) {
			$bricks_data_changed = isset( $_POST['pageSettings'] );
		}

		/**
		 * Save components in database
		 *
		 * @since 1.12
		 * @since 2.1: decode param 2 true to add back backslashes via wp_slash
		 * @since 2.1: decode param 2 false as wp_slash not needed for options
		 */
		$components = isset( $_POST['components'] ) ? self::decode( $_POST['components'], false ) : false;

		if ( is_array( $components ) ) {
			$stored_components = get_option( BRICKS_DB_COMPONENTS, [] );
			$stored_components = is_array( $stored_components ) ? array_column( $stored_components, null, 'id' ) : [];
			$incoming_ids      = [];

			// Authorize the complete replacement before sanitizing or writing any component.
			foreach ( $components as $component ) {
				$component_id = $component['id'] ?? '';

				if ( ! is_string( $component_id ) || $component_id === '' || isset( $incoming_ids[ $component_id ] ) ) {
					wp_send_json_error( [ 'message' => esc_html__( 'Invalid data', 'bricks' ) ] );
				}

				$incoming_ids[ $component_id ] = true;
				$stored_component              = $stored_components[ $component_id ] ?? null;
				$permission                    = $stored_component === null ? 'create_components' : 'edit_components';

				if ( $component !== $stored_component && ! Builder_Permissions::user_has_permission( $permission ) ) {
					wp_send_json_error( [ 'message' => esc_html__( 'Not allowed to edit components.', 'bricks' ) ] );
				}
			}

			if ( array_diff_key( $stored_components, $incoming_ids ) && ! Builder_Permissions::user_has_permission( 'delete_components' ) ) {
				wp_send_json_error( [ 'message' => esc_html__( 'Not allowed to edit components.', 'bricks' ) ] );
			}

			foreach ( $components as $index => $component ) {
				$stored_component = $stored_components[ $component['id'] ] ?? [];

				// Unchanged definitions may contain trusted code the current user cannot author.
				if ( $component === $stored_component ) {
					continue;
				}

				$component_elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];
				$stored_elements    = is_array( $stored_component['elements'] ?? null ) ? $stored_component['elements'] : [];

				// The normal save sanitizer expects slashed data; options store unslashed values.
				$component_elements = wp_unslash( Helpers::security_check_elements_before_save( wp_slash( $component_elements ), null, 'component', wp_slash( $stored_elements ) ) );

				if ( $dangerously_auto_sign ) {
					$component_elements = self::maybe_auto_sign_builder_elements( $component_elements, $auto_signed_code );
				}

				$components[ $index ]['elements'] = $component_elements;

				// Descriptions are formatted help text, never an executable content surface.
				if ( isset( $component['desc'] ) && is_string( $component['desc'] ) ) {
					$components[ $index ]['desc'] = wp_kses_post( $component['desc'] );
				}
				foreach ( $component['properties'] ?? [] as $property_index => $property ) {
					if ( isset( $property['desc'] ) && is_string( $property['desc'] ) ) {
						$components[ $index ]['properties'][ $property_index ]['desc'] = wp_kses_post( $property['desc'] );
					}
				}

				// Property defaults can also become rendered element settings.
				if ( ! Capabilities::current_user_can_execute_code() && ! current_user_can( 'unfiltered_html' ) && is_array( $component['properties'] ?? null ) ) {
					$properties = wp_slash( $components[ $index ]['properties'] );
					array_walk_recursive( $properties, [ 'Bricks\Helpers', 'apply_wp_filter_post_kses_to_array' ] );
					$components[ $index ]['properties'] = wp_unslash( $properties );
				}
			}

			update_option( BRICKS_DB_COMPONENTS, $components );
		}

		/*
		 * Collect notifications & conflicts (users modified the same global class at the same time)
		 *
		 * Global data in database differs from builder data
		 *
		 * Data: globalClasses
		 *
		 * @since 1.9.8
		 */
		$notifications       = [];
		$conflicting_classes = [];

		/**
		 * Create revision if data contains 'header', 'footer', or 'content'
		 *
		 * To avoid create false empty revision.
		 *
		 * @since 1.7.1 (if check added)
		 */
		if ( $bricks_data_changed ) {
			// Disabled WordPress content diff check
			add_filter( 'wp_save_post_revision_check_for_changes', [ $this, 'dont_check_for_revision_changes' ] );

			$revision_id = wp_save_post_revision( $post );

			// Delete autosave (@since 1.7)
			if ( $revision_id ) {
				$autosave = wp_get_post_autosave( $post_id );

				if ( $autosave ) {
					wp_delete_post_revision( $autosave );
				}
			}

			remove_filter( 'wp_save_post_revision_check_for_changes', [ $this, 'dont_check_for_revision_changes' ] );
		}

		/**
		 * Save color palettes
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['colorPalette'] ) && Builder_Permissions::user_has_permission( 'edit_color_palettes' ) ) {
			$color_palette = self::decode( $_POST['colorPalette'], false );

			// Process renamed palette variables before saving the updated palette.
			if ( isset( $_POST['colorPaletteRenamed'] ) && ! empty( $_POST['colorPaletteRenamed'] ) && is_array( $color_palette ) ) {
				$renamed_color_ids = self::decode( $_POST['colorPaletteRenamed'], false );

				if ( is_array( $renamed_color_ids ) && ! empty( $renamed_color_ids ) ) {
					$existing_color_palette = get_option( BRICKS_DB_COLOR_PALETTE, [] );
					$existing_colors_by_id  = [];
					$new_colors_by_id       = [];

					$extract_color_variable_name = static function( $raw ) {
						if ( ! is_string( $raw ) || ! strlen( trim( $raw ) ) ) {
							return '';
						}

						$raw = trim( $raw );

						if ( preg_match( '/var\(\s*--([\w-]+)\s*\)/', $raw, $matches ) ) {
							return $matches[1];
						}

						if ( strpos( $raw, '--' ) === 0 ) {
							return ltrim( $raw, '-' );
						}

						return '';
					};

					if ( is_array( $existing_color_palette ) ) {
						foreach ( $existing_color_palette as $palette ) {
							if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
								continue;
							}

							foreach ( $palette['colors'] as $color ) {
								if ( ! empty( $color['id'] ) ) {
									$existing_colors_by_id[ $color['id'] ] = $color;
								}
							}
						}
					}

					foreach ( $color_palette as $palette ) {
						if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
							continue;
						}

						foreach ( $palette['colors'] as $color ) {
							if ( ! empty( $color['id'] ) ) {
								$new_colors_by_id[ $color['id'] ] = $color;
							}
						}
					}

					$renamed_color_names = [];

					foreach ( $renamed_color_ids as $color_id ) {
						if ( ! isset( $existing_colors_by_id[ $color_id ] ) || ! isset( $new_colors_by_id[ $color_id ] ) ) {
							continue;
						}

						$old_name = $extract_color_variable_name( $existing_colors_by_id[ $color_id ]['raw'] ?? '' );
						$new_name = $extract_color_variable_name( $new_colors_by_id[ $color_id ]['raw'] ?? '' );

						if ( ! $old_name || ! $new_name || $old_name === $new_name ) {
							continue;
						}

						$renamed_color_names[ $old_name ] = $new_name;
					}

					if ( ! empty( $renamed_color_names ) ) {
						Helpers::update_global_variable_references_batch( $renamed_color_names );
					}
				}
			}

			if ( is_array( $color_palette ) && count( $color_palette ) ) {
				update_option( BRICKS_DB_COLOR_PALETTE, $color_palette );
			} else {
				delete_option( BRICKS_DB_COLOR_PALETTE );
			}

			// Generate color palette CSS file (@since 2.2)
			$css_file_generated = self::generate_color_palette_css_file( $color_palette );
		}

		/**
		 * Save style manager settings
		 *
		 * @since 2.2
		 */
		if ( isset( $_POST['styleManager'] ) ) {
			$style_manager = self::decode( $_POST['styleManager'], false );

			if ( is_array( $style_manager ) && ! empty( $style_manager ) ) {
				update_option( BRICKS_DB_STYLE_MANAGER, $style_manager );
			} else {
				delete_option( BRICKS_DB_STYLE_MANAGER );
			}
		}

		/**
		 * Save global query loops
		 *
		 * Required capability: access_query_manager
		 *
		 * @since 2.1
		 */
		if ( isset( $_POST['globalQueries'] ) && Builder_Permissions::user_has_permission( 'access_query_manager' ) ) {
			$global_queries = self::decode( $_POST['globalQueries'], false );

			if ( $dangerously_auto_sign ) {
				$global_queries = self::maybe_auto_sign_builder_global_queries( $global_queries, $auto_signed_code );
			}

			if ( is_array( $global_queries ) && count( $global_queries ) ) {
				update_option( BRICKS_DB_GLOBAL_QUERIES, $global_queries );
			} else {
				delete_option( BRICKS_DB_GLOBAL_QUERIES );
			}
		}

		/**
		 * Save globalQueriesCategories
		 *
		 * @since 2.1
		 */
		if ( isset( $_POST['globalQueriesCategories'] ) && Builder_Permissions::user_has_permission( 'access_query_manager' ) ) {
			$global_queries_categories = self::decode( $_POST['globalQueriesCategories'], false );

			if ( is_array( $global_queries_categories ) && count( $global_queries_categories ) ) {
				update_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, $global_queries_categories );
			} else {
				delete_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES );
			}
		}

		/**
		 * Save icon sets
		 *
		 * @since 2.0
		 */
		$icon_sets                          = null;
		$custom_icons                       = null;
		$previous_custom_icons              = get_option( BRICKS_DB_CUSTOM_ICONS, [] );
		$icon_set_attachment_cleanup        = isset( $_POST['iconSetAttachmentCleanup'] )
			? self::decode( $_POST['iconSetAttachmentCleanup'], false )
			: [];
		$icon_set_attachment_cleanup_result = [];

		$previous_custom_icons = is_array( $previous_custom_icons ) ? $previous_custom_icons : [];

		if ( is_array( $icon_set_attachment_cleanup ) ) {
			$icon_set_attachment_cleanup = array_values(
				array_unique(
					array_filter(
						array_map(
							fn( $set_id ) => is_scalar( $set_id ) ? (string) $set_id : '',
							$icon_set_attachment_cleanup
						)
					)
				)
			);
		} else {
			$icon_set_attachment_cleanup = [];
		}

		if ( isset( $_POST['iconSets'] ) ) {
			$icon_sets = self::decode( $_POST['iconSets'], false );

			if ( is_array( $icon_sets ) && count( $icon_sets ) ) {
				update_option( BRICKS_DB_ICON_SETS, $icon_sets );
			} else {
				delete_option( BRICKS_DB_ICON_SETS );
			}
		}

		/**
		 * Save custom icons
		 *
		 * @since 2.0
		 */
		if ( isset( $_POST['customIcons'] ) ) {
			$custom_icons = self::decode( $_POST['customIcons'], false );

			if ( is_array( $custom_icons ) && count( $custom_icons ) ) {
				update_option( BRICKS_DB_CUSTOM_ICONS, $custom_icons );
			} else {
				delete_option( BRICKS_DB_CUSTOM_ICONS );
			}
		}

		/**
		 * Cleanup must follow a complete, successfully persisted icon-manager save.
		 * This prevents partial or stale requests from deleting media.
		 *
		 * @since 2.4
		 */
		if (
			$icon_set_attachment_cleanup &&
			is_array( $icon_sets ) &&
			is_array( $custom_icons ) &&
			Builder_Permissions::user_has_permission( 'access_icon_manager' ) &&
			get_option( BRICKS_DB_ICON_SETS, [] ) === $icon_sets &&
			get_option( BRICKS_DB_CUSTOM_ICONS, [] ) === $custom_icons
		) {
			$remaining_set_ids = [];

			foreach ( $icon_sets as $icon_set ) {
				if ( isset( $icon_set['id'] ) && is_scalar( $icon_set['id'] ) ) {
					$remaining_set_ids[ (string) $icon_set['id'] ] = true;
				}
			}

			$removed_set_ids = array_values(
				array_filter(
					$icon_set_attachment_cleanup,
					fn( $set_id ) => ! isset( $remaining_set_ids[ $set_id ] )
				)
			);

			$icon_set_attachment_cleanup_result = Custom_Icon_Manager::delete_set_attachments(
				$removed_set_ids,
				$previous_custom_icons,
				$custom_icons
			);
		}

		/**
		 * Save disabled icon sets
		 *
		 * @since 2.0
		 */
		if ( isset( $_POST['disabledIconSets'] ) ) {
			$disabled_icon_sets = self::decode( $_POST['disabledIconSets'], false );

			if ( is_array( $disabled_icon_sets ) && count( $disabled_icon_sets ) ) {
				update_option( BRICKS_DB_DISABLED_ICON_SETS, $disabled_icon_sets );
			} else {
				delete_option( BRICKS_DB_DISABLED_ICON_SETS );
			}
		}

		/**
		 * Save font favorites
		 *
		 * @since 2.0
		 */
		if ( isset( $_POST['fontFavorites'] ) ) {
			$font_favorites = self::decode( $_POST['fontFavorites'], false );

			if ( is_array( $font_favorites ) && count( $font_favorites ) ) {
				update_option( BRICKS_DB_FONT_FAVORITES, $font_favorites );
			} else {
				delete_option( BRICKS_DB_FONT_FAVORITES );
			}
		}

		/**
		 * Save global classes
		 *
		 * @since 1.4
		 * @since 1.9.9: Added global classes conflict check and notifications (if enabled in Bricks settings)
		 */
		$global_changes      = isset( $_POST['globalChanges'] ) ? self::decode( $_POST['globalChanges'], false ) : [];
		$global_ids_added    = $global_changes['added'] ?? [];
		$global_ids_deleted  = $global_changes['deleted'] ?? [];
		$global_ids_modified = $global_changes['modified'] ?? [];
		$global_ids_trashed  = $global_changes['trashed'] ?? []; // Track trashed classes (@since 1.11)

		if ( isset( $_POST['globalClasses'] ) && ( Builder_Permissions::user_has_permission( 'edit_global_classes' ) || Builder_Permissions::user_has_permission( 'create_global_classes' ) ) ) {
			$global_classes = self::decode( $_POST['globalClasses'], false );

			// Remove in savePost if conflict has been discarded
			$global_classes_timestamp = $_POST['globalClassesTimestamp'] ?? 0;

			// Check: Global classes conflict check enabled
			$global_classes_snyc = Database::get_setting( 'builderGlobalClassesSync', false );

			$global_classes_db           = $global_classes_snyc ? get_option( BRICKS_DB_GLOBAL_CLASSES, [] ) : [];
			$global_classes_db_timestamp = $global_classes_snyc ? get_option( BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP, 0 ) : 0;
			$global_classes_db_user_id   = $global_classes_snyc ? get_option( BRICKS_DB_GLOBAL_CLASSES_USER, 0 ) : 0;
			$global_classes_trash        = get_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, [] );

			/**
			 * STEP: Classes with newer timestamp found in database
			 *
			 * The same or a different user has modified global classes in the meantime.
			 *
			 * Collect new classes, deleted classes, and modified classes to show in builder to accept or discard.
			 *
			 * @since 1.9.9
			 */
			if (
				$global_classes_snyc &&
				$global_classes_timestamp && $global_classes_db_timestamp &&
				intval( $global_classes_db_timestamp ) > intval( $global_classes_timestamp )
			) {
				$global_classes_db_user = get_user_by( 'ID', $global_classes_db_user_id );
				$author                 = $global_classes_db_user->display_name ?? esc_html__( 'Another user', 'bricks' );
				$avatar                 = get_avatar( $global_classes_db_user_id, 40 );

				// Collect all added, deleted and modified classes from database
				$classes_added    = [];
				$classes_deleted  = [];
				$classes_modified = [];
				$classes_trashed  = [];

				// STEP: Loop through classes in database to find newly added and modified classes
				foreach ( $global_classes_db as $global_class_db ) {
					$found    = false;
					$modified = false;

					foreach ( $global_classes as $global_class ) {
						// Class found (i.e. not newly added)
						if ( $global_class['id'] === $global_class_db['id'] ) {
							$found                   = true;
							$compare_global_class    = $global_class;
							$compare_global_class_db = $global_class_db;

							// Remove 'modified' timestamp and 'user' (ID) to compare
							unset( $compare_global_class['modified'], $compare_global_class_db['modified'] );
							unset( $compare_global_class['user'], $compare_global_class_db['user'] );

							// Class modified (name, category, settings, etc.)
							if ( wp_json_encode( $compare_global_class ) !== wp_json_encode( $compare_global_class_db ) ) {
								// Database 'modified' timestamp newer than builder timestamp
								if ( ! empty( $global_class_db['modified'] ) && intval( $global_class_db['modified'] ) > intval( $global_classes_timestamp ) ) {
									$modified = true;
								}
							}
							break;
						}
					}

					// Class added in database, but not in builder, and not deleted or trashed in builder
					if (
						! $found &&
						! in_array( $global_class_db['id'], $global_ids_added ) &&
						! in_array( $global_class_db['id'], $global_ids_deleted ) &&
						! in_array( $global_class_db['id'], $global_ids_trashed )
					) {
						$classes_added[] = $global_class_db;
					}

					// Class modified and not deleted or trashed in builder
					if ( $found && $modified && ! in_array( $global_class_db['id'], $global_ids_deleted ) && ! in_array( $global_class_db['id'], $global_ids_trashed ) ) {
						$classes_modified[] = $global_class_db;

						// CONFLICT: Class modified in database and builder
						if ( in_array( $global_class_db['id'], $global_ids_modified ) ) {
							$conflicting_classes[] = $global_class_db;
						}
					}
				}

				// Check for trashed classes
				foreach ( $global_ids_trashed as $trashed_id ) {
					$trashed_class = array_filter(
						$global_classes_db,
						function( $class ) use ( $trashed_id ) {
							return $class['id'] === $trashed_id;
						}
					);

					if ( ! empty( $trashed_class ) ) {
						$classes_trashed[] = reset( $trashed_class );
					}
				}

				// Handle modified class in database, incoming class moved the same class to trash
				foreach ( $classes_trashed as $trashed_class ) {
					if ( in_array( $trashed_class['id'], $global_ids_modified ) ) {
						// Keep the modifications by User A, but move to trash
						$global_classes_trash[] = $trashed_class;
						$notifications[]        = [
							'key'    => 'globalClassesTrashed',
							'action' => 'trashed',
							'data'   => [ $trashed_class ],
							// translators: %s: Class name
							'desc'   => sprintf( esc_html__( 'Class "%s" has been moved to trash but your modifications are kept.', 'bricks' ), $trashed_class['name'] ),
						];
					}
				}

				// Handle modified class in database, incoming class permanently deletes the same class
				foreach ( $global_ids_deleted as $deleted_id ) {
					if ( in_array( $deleted_id, $global_ids_modified ) || in_array( $deleted_id, $global_ids_added ) ) {
						$conflicting_class = array_filter(
							$global_classes,
							function( $class ) use ( $deleted_id ) {
								return $class['id'] === $deleted_id;
							}
						);

						if ( ! empty( $conflicting_class ) ) {
							$conflicting_class     = reset( $conflicting_class );
							$conflicting_classes[] = $conflicting_class;
							$notifications[]       = [
								'key'    => 'globalClassesConflict',
								'action' => 'conflict',
								'data'   => [ $conflicting_class ],
								// translators: %s: Class name
								'desc'   => sprintf( esc_html__( 'Conflict: Class "%s" has been permanently deleted by another user.', 'bricks' ), $conflicting_class['name'] ),
							];
						}
					}
				}

				// Add notifications for modified, added, and deleted classes
				if ( count( $classes_modified ) ) {
					$notifications[] = [
						'data'      => $classes_modified,
						'key'       => 'globalClasses',
						'action'    => 'modified',
						'author'    => $author,
						'avatar'    => $avatar,
						'timestamp' => $global_classes_db_timestamp,
						'desc'      => esc_html__( 'Modified', 'bricks' ) . ': ' . esc_html__( 'Classes', 'bricks' ) . ' (' . count( $classes_modified ) . ')',
					];

					// Add conflict: Classes modified in database and builder
					if ( count( $conflicting_classes ) ) {
						$notifications[] = [
							'conflict' => '<strong>' . esc_html( 'Conflict', 'bricks' ) . '</strong>: ' . esc_html__( 'The following classes have been modified by the user below since your last save. Accept or discard those changes to continue your save.', 'bricks' ),
							'data'     => $conflicting_classes,
						];
					}
				}

				// Add notification: Classes added
				if ( count( $classes_added ) ) {
					$notifications[] = [
						'data'      => $classes_added,
						'key'       => 'globalClasses',
						'action'    => 'added',
						'author'    => $author,
						'avatar'    => $avatar,
						'timestamp' => $global_classes_db_timestamp,
						'desc'      => esc_html__( 'Added', 'bricks' ) . ': ' . esc_html__( 'Classes', 'bricks' ) . ' (' . count( $classes_added ) . ')',
					];
				}

				foreach ( $global_classes as $global_class ) {
					$found = false;

					// STEP: Loop through classes in builder to find deleted database classes
					foreach ( $global_classes_db as $global_class_db ) {
						if ( $global_class['id'] === $global_class_db['id'] ) {
							$found = true;
							break;
						}
					}

					// Class deleted: Not found in builder, and not added in builder either
					if ( ! $found && ! in_array( $global_class['id'], $global_ids_added ) ) {
						$classes_deleted[] = $global_class;
					}
				}

				// Add notification: Classes deleted
				if ( count( $classes_deleted ) ) {
					$notifications[] = [
						'data'      => $classes_deleted,
						'key'       => 'globalClasses',
						'action'    => 'deleted',
						'author'    => $author,
						'avatar'    => $avatar,
						'timestamp' => $global_classes_db_timestamp,
						'desc'      => esc_html__( 'Deleted', 'bricks' ) . ': ' . esc_html__( 'Classes', 'bricks' ) . ' (' . count( $classes_deleted ) . ')',
					];
				}
			}

			// STEP: Return notifications without save if conflicts found
			if ( count( $notifications ) && count( $conflicting_classes ) || ! empty( $conflicting_trash_classes ) ) {
				wp_send_json_error(
					[
						'notifications'   => $notifications,
						'globalClassesDb' => $global_classes_db,
					]
				);
			}

			/**
			 * STEP: Handle global classes trash synchronization
			 *
			 * globalClassesTrash: Array of trashed global classes (always sent from builder, even if unchanged)
			 *
			 * @since 1.11
			 */
			$incoming_trash = isset( $_POST['globalClassesTrash'] ) ? self::decode( $_POST['globalClassesTrash'], false ) : [];
			$existing_trash = get_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, [] );
			$final_trash    = self::sync_trash_items( $incoming_trash, $existing_trash, $global_classes );

			// Check for conflicts between final trash and active classes
			$conflicting_trash_classes = array_intersect(
				array_column( $final_trash, 'id' ),
				array_column( $global_classes, 'id' )
			);

			if ( ! empty( $conflicting_trash_classes ) ) {
				$notifications[] = [
					'key'    => 'globalClassesTrashConflict',
					'action' => 'conflict',
					'data'   => $conflicting_trash_classes,
					'desc'   => esc_html__( 'Conflict: Some classes exist in both trash and active classes', 'bricks' ),
				];
			}

			// Return notifications if trash conflicts found
			if ( ! empty( $incoming_trash ) && count( $notifications ) ) {
				wp_send_json_error(
					[
						'notifications'      => $notifications,
						'globalClassesDb'    => $global_classes_db,
						'globalClassesTrash' => $existing_trash,
					]
				);
			}

			self::save_array_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, $final_trash );

			// STEP: Add 'modified' timestamp and 'user' (ID) to modified global class (to compare on save)
			if ( count( $global_ids_modified ) ) {
				$current_timestamp = time();
				$current_user_id   = get_current_user_id();

				foreach ( $global_classes as $global_class_index => $global_class ) {
					if ( in_array( $global_class['id'], $global_ids_modified ) ) {
						$global_classes[ $global_class_index ]['modified'] = $current_timestamp;
						$global_classes[ $global_class_index ]['user_id']  = $current_user_id;
					}
				}
			}

			$global_classes_response = Helpers::save_global_classes_in_db( $global_classes );

			if ( isset( $global_classes_response['timestamp'] ) ) {
				$_POST['globalClassesTimestamp'] = $global_classes_response['timestamp'];
			}

			if ( isset( $global_classes_response['user_id'] ) ) {
				$_POST['globalClassesUser'] = get_userdata( $global_classes_response['user_id'] )->display_name ?? '';
			}

			// STEP: Return notifications
			if ( count( $notifications ) ) {
				wp_send_json_error(
					[
						'notifications'        => $notifications,
						'globalClassesDb'      => $global_classes_db,
						'globalClassesTrashDb' => $existing_trash,
					]
				);
			}
		}

		/**
		 * Save global classes locked
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['globalClassesLocked'] ) && Builder_Permissions::user_has_permission( 'lock_unlock_global_classes' ) ) {
			$global_classes_locked = self::decode( $_POST['globalClassesLocked'], false );

			if ( is_array( $global_classes_locked ) && count( $global_classes_locked ) ) {
				update_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, $global_classes_locked, false );
			} else {
				delete_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED );
			}
		}

		/**
		 * Save global classes categories
		 *
		 * @since 1.9.4
		 */
		if ( isset( $_POST['globalClassesCategories'] ) && Builder_Permissions::user_has_permission( 'edit_global_classes' ) ) {
			$global_classes_categories = self::decode( $_POST['globalClassesCategories'], false );

			Database::save_global_classes_categories(
				is_array( $global_classes_categories ) ? $global_classes_categories : []
			);
		}

		/**
		 * Save global variables
		 *
		 * @since 1.9.8
		 */
		if ( isset( $_POST['globalVariables'] ) && Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			$global_variables = self::decode( $_POST['globalVariables'], false );

			// Process renamed variables if any
			if ( isset( $_POST['globalVariablesRenamed'] ) && ! empty( $_POST['globalVariablesRenamed'] ) ) {
				$renamed_variable_ids = self::decode( $_POST['globalVariablesRenamed'], false );

				if ( is_array( $renamed_variable_ids ) && ! empty( $renamed_variable_ids ) ) {
					// Get existing variables from database to compare
					$existing_variables = Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

					// Create lookup arrays for existing and new variables by ID
					$existing_variables_by_id = [];
					$new_variables_by_id      = [];

					// Index existing variables by ID
					if ( is_array( $existing_variables ) ) {
						foreach ( $existing_variables as $variable ) {
							if ( isset( $variable['id'] ) ) {
								$existing_variables_by_id[ $variable['id'] ] = $variable;
							}
						}
					}

					// Index new variables by ID
					if ( is_array( $global_variables ) ) {
						foreach ( $global_variables as $variable ) {
							if ( isset( $variable['id'] ) ) {
								$new_variables_by_id[ $variable['id'] ] = $variable;
							}
						}
					}

					$renamed_variable_names = [];

					// Process each renamed variable
					foreach ( $renamed_variable_ids as $variable_id ) {
						// Skip if this is a new variable (not in database)
						if ( ! isset( $existing_variables_by_id[ $variable_id ] ) || ! isset( $new_variables_by_id[ $variable_id ] ) ) {
							continue;
						}

						$old_name = $existing_variables_by_id[ $variable_id ]['name'];
						$new_name = $new_variables_by_id[ $variable_id ]['name'];

						// Skip if name hasn't actually changed
						if ( $old_name === $new_name ) {
							continue;
						}

						$renamed_variable_names[ $old_name ] = $new_name;
					}

					if ( ! empty( $renamed_variable_names ) ) {
						Helpers::update_global_variable_references_batch( $renamed_variable_names );
					}
				}
			}

			$incoming_variables_trash = isset( $_POST['globalVariablesTrash'] ) ? self::decode( $_POST['globalVariablesTrash'], false ) : [];
			$existing_variables_trash = Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES_TRASH, [] );
			$final_variables_trash    = self::sync_trash_items( $incoming_variables_trash, $existing_variables_trash, $global_variables );

			Helpers::save_global_variables_array_option( BRICKS_DB_GLOBAL_VARIABLES_TRASH, $final_variables_trash );

			Helpers::save_global_variables_in_db( $global_variables );

			// Regenerate Style Manager CSS file to include scale-based variables (@since 2.2)
			$css_file_generated = self::generate_style_manager_css_file();
		}

		/**
		 * Save global variables categories
		 *
		 * @since 1.9.8
		 */
		if ( isset( $_POST['globalVariablesCategories'] ) && Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			$global_variables_categories = self::decode( $_POST['globalVariablesCategories'], false );

			if ( is_array( $global_variables_categories ) && count( $global_variables_categories ) ) {
				update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, $global_variables_categories, false );
			} else {
				delete_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES );
			}

			// Regenerate Style Manager CSS file to include scale-based variables (@since 2.2)
			$css_file_generated = self::generate_style_manager_css_file();
		}

		/**
		 * Save global elements
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['globalElements'] ) ) {
			if ( isset( $_POST['globalElements'] ) && Builder_Permissions::user_has_permission( 'manage_global_elements' ) ) {
				$global_elements = self::decode( $_POST['globalElements'], false );

				if ( is_array( $global_elements ) && count( $global_elements ) ) {
					$global_elements = Helpers::security_check_elements_before_save( $global_elements, null, 'global' );

					if ( $dangerously_auto_sign ) {
						$global_elements = self::maybe_auto_sign_builder_elements( $global_elements, $auto_signed_code );
					}

					update_option( BRICKS_DB_GLOBAL_ELEMENTS, $global_elements );
				} else {
					delete_option( BRICKS_DB_GLOBAL_ELEMENTS );
				}
			}
		}

		/**
		 * Save pinned elements
		 *
		 * @since 1.4
		 */

		if ( isset( $_POST['pinnedElements'] ) && Builder_Permissions::user_has_permission( 'pin_unpin_elements' ) ) {
			$pinned_elements = self::decode( $_POST['pinnedElements'], false );

			if ( is_array( $pinned_elements ) && count( $pinned_elements ) ) {
				update_option( BRICKS_DB_PINNED_ELEMENTS, $pinned_elements );
			} else {
				delete_option( BRICKS_DB_PINNED_ELEMENTS );
			}
		}

		/**
		 * Save pseudo-classes
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['pseudoClasses'] ) && Builder_Permissions::user_has_permission( 'access_pseudo_selectors' ) ) {
			// Do not run wp_slash on pseudo-classes as it can break the CSS selector syntax (e.g. ':nth-child('1')' becomes ':nth-child(\'1\')' which is invalid) (@since 2.4)
			$global_pseudo_classes = self::decode( $_POST['pseudoClasses'], false );

			if ( is_array( $global_pseudo_classes ) && count( $global_pseudo_classes ) ) {
				update_option( BRICKS_DB_PSEUDO_CLASSES, $global_pseudo_classes );
			} else {
				delete_option( BRICKS_DB_PSEUDO_CLASSES );
			}
		}

		/**
		 * Save theme styles
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['themeStyles'] ) && Builder_Permissions::user_has_permission( 'access_theme_styles' ) ) {
			$theme_styles = self::decode( $_POST['themeStyles'], false );

			foreach ( $theme_styles as $theme_style_id => $theme_style ) {
				// Remove empty settings 'group'
				if ( isset( $theme_style['settings'] ) ) {
					foreach ( $theme_style['settings'] as $group_key => $group_settings ) {
						if ( ! $group_settings || ( is_array( $group_settings ) && ! count( $group_settings ) ) ) {
							unset( $theme_styles[ $theme_style_id ]['settings'][ $group_key ] );
						}
					}
				}
			}

			if ( is_array( $theme_styles ) && count( $theme_styles ) ) {
				update_option( BRICKS_DB_THEME_STYLES, $theme_styles );
			} else {
				delete_option( BRICKS_DB_THEME_STYLES );
			}
		}

		/**
		 * Save page data (post meta table)
		 */
		$header  = isset( $_POST['header'] ) ? self::decode( $_POST['header'] ) : [];
		$content = isset( $_POST['content'] ) ? self::decode( $_POST['content'] ) : [];
		$footer  = isset( $_POST['footer'] ) ? self::decode( $_POST['footer'] ) : [];

		/**
		 * Save page setting
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['pageSettings'] ) && Builder_Permissions::user_has_permission( 'access_page_settings' ) ) {
			$page_settings = self::decode( $_POST['pageSettings'] );

			if ( is_array( $page_settings ) && count( $page_settings ) ) {
				if ( ! empty( $page_settings['postName'] ) || ! empty( $page_settings['postTitle'] ) ) {
					$the_post['ID'] = $post_id;
				}

				// Update post name (slug)
				if ( ! empty( $page_settings['postName'] ) ) {
					$the_post['post_name'] = trim( $page_settings['postName'] );

					unset( $page_settings['postName'] );
				}

				// Update post title
				if ( ! empty( $page_settings['postTitle'] ) ) {
					$the_post['post_title'] = trim( $page_settings['postTitle'] );

					unset( $page_settings['postTitle'] );
				}

				update_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, $page_settings );
			} else {
				delete_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS );
			}
		}

		/**
		 * Bricks template
		 *
		 * @since 1.4
		 */
		$template_type = ! empty( $_POST['templateType'] ) ? sanitize_text_field( $_POST['templateType'] ) : false;

		if ( $template_type ) {
			update_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, $template_type );

			switch ( $template_type ) {
				// Header template
				case 'header':
					if ( isset( $_POST['header'] ) ) {
						$header = Helpers::security_check_elements_before_save( $header, $post_id, 'header' );

						if ( $dangerously_auto_sign ) {
							$header = self::maybe_auto_sign_builder_elements( $header, $auto_signed_code, true );
						}

						if ( is_array( $header ) && count( $header ) ) {
							// Save revision in post meta ('update_post_meta' can't process post type 'revision'
							if ( $revision_id ) {
								update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_HEADER, $header );
							}

							update_post_meta( $post_id, BRICKS_DB_PAGE_HEADER, $header );
						} else {
							delete_post_meta( $post_id, BRICKS_DB_PAGE_HEADER );
						}
					}
					break;

				// Footer template
				case 'footer':
					if ( isset( $_POST['footer'] ) ) {
						$footer = Helpers::security_check_elements_before_save( $footer, $post_id, 'footer' );

						if ( $dangerously_auto_sign ) {
							$footer = self::maybe_auto_sign_builder_elements( $footer, $auto_signed_code, true );
						}

						if ( is_array( $footer ) && count( $footer ) ) {
							// Save revision in post meta ('update_post_meta' can't process post type 'revision'
							if ( $revision_id ) {
								update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_FOOTER, $footer );
							}

							update_post_meta( $post_id, BRICKS_DB_PAGE_FOOTER, $footer );
						} else {
							delete_post_meta( $post_id, BRICKS_DB_PAGE_FOOTER );
						}
					}
					break;

				// Any other template type
				default:
					if ( isset( $_POST['content'] ) ) {
						$content = Helpers::security_check_elements_before_save( $content, $post_id, 'content' );

						if ( $dangerously_auto_sign ) {
							$content = self::maybe_auto_sign_builder_elements( $content, $auto_signed_code, true );
						}

						if ( is_array( $content ) && count( $content ) ) {
							// Save revision in post meta ('update_post_meta' can't process post type 'revision')
							if ( $revision_id ) {
								update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_CONTENT, $content );
							}

							update_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, $content );
						} else {
							delete_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT );
						}
					}
			}
		}

		/**
		 * Template settings
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['templateSettings'] ) && Builder_Permissions::user_has_permission( 'access_template_settings' ) ) {
			$template_settings = self::decode( $_POST['templateSettings'], false );

			if ( is_array( $template_settings ) && count( $template_settings ) ) {
				// User saved template settings: Delete auto content notification
				unset( $template_settings['templatePreviewAutoContent'] );

				Helpers::set_template_settings( $post_id, $template_settings );
			} else {
				Helpers::delete_template_settings( $post_id );
			}
		}

		/**
		 * Content (not a Bricks template)
		 *
		 * @since 1.4
		 */
		if ( isset( $_POST['content'] ) && get_post_type( $post_id ) !== BRICKS_DB_TEMPLATE_SLUG ) {
			$content = Helpers::security_check_elements_before_save( $content, $post_id, 'content' );

			if ( $dangerously_auto_sign ) {
				$content = self::maybe_auto_sign_builder_elements( $content, $auto_signed_code, true );
			}

			if ( is_array( $content ) && count( $content ) ) {
				// Update empty or existing Gutenberg post_content (preserve Classic Editor data)
				$existing_post_content = $post->post_content;

				if ( Database::get_setting( 'bricks_to_wp' ) && ( ! $existing_post_content || has_blocks( get_post( $post_id ) ) ) ) {
					// Converters consume raw settings; wp_update_post expects slashed content.
					$new_post_content = wp_slash( Blocks::serialize_bricks_to_blocks( wp_unslash( $content ), $post_id ) );

					if ( $new_post_content ) {
						$the_post = (
							[
								'ID'           => $post_id,
								'post_content' => $new_post_content,
							]
						);
					}
				}

				// Save revision in post meta ('update_post_meta' can't process post type 'revision')
				if ( $revision_id ) {
					update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_CONTENT, $content );
				}

				// Save content in post meta
				update_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, $content );
			} else {
				delete_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT );
			}
		}

		if ( $dangerously_auto_sign ) {
			$save_area = ! empty( $_POST['area'] ) ? sanitize_text_field( wp_unslash( $_POST['area'] ) ) : '';

			if ( ! $save_area && in_array( $template_type, [ 'header', 'footer' ], true ) ) {
				$save_area = $template_type;
			}

			if ( ! in_array( $save_area, [ 'header', 'content', 'footer' ], true ) ) {
				$save_area = 'content';
			}

			if ( ! isset( $_POST[ $save_area ] ) ) {
				self::maybe_auto_sign_stored_builder_area( $post_id, $save_area, $auto_signed_code );
			}
		}

		$editor_mode_before_save    = Helpers::get_editor_mode( $post_id );
		$has_bricks_content         = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
		$active_content_template_id = 0;

		if ( $editor_mode_before_save !== 'wordpress' && get_post_type( $post_id ) !== BRICKS_DB_TEMPLATE_SLUG && ! $has_bricks_content ) {
			Database::set_active_templates( $post_id );

			$active_templates_post_id   = isset( Database::$active_templates['post_id'] ) ? (int) Database::$active_templates['post_id'] : 0;
			$active_content_template_id = isset( Database::$active_templates['content'] ) ? (int) Database::$active_templates['content'] : 0;

			if ( $active_templates_post_id !== $post_id || $active_content_template_id === $post_id ) {
				$active_content_template_id = 0;
			}
		}

		// Always set editor mode to 'bricks' if the post itself or its active content template renders with Bricks.
		// (#86c48ct1y; #86c6ddrec; @since 2.0)
		$editor_mode = ( in_array( $template_type, [ 'header', 'footer' ], true ) ||
				$has_bricks_content ||
				$active_content_template_id )
				? 'bricks'
				: 'wordpress';

		update_post_meta( $post_id, BRICKS_DB_EDITOR_MODE, $editor_mode );

		/**
		 * STEP: Update post to (1) update post date & (2) re-generate CSS file via 'save_post' in files.php
		 *
		 * Check $wp_post_updated to ensure wp_update_post did not already ran above.
		 *
		 * @since 1.5.7
		 */
		if ( $bricks_data_changed ) {
			$post_id = $the_post ? wp_update_post( $the_post ) : wp_update_post( $post );
		}

		/**
		 * Save custom fonts
		 *
		 * @since 1.0
		 */
		if ( isset( $_POST['fonts'] ) && Builder_Permissions::user_has_permission( 'access_font_manager' ) ) {
			$custom_fonts = self::decode( $_POST['fonts'], false );
			$custom_fonts = $custom_fonts['custom'];
			$font_changes = isset( $_POST['fontChanges'] ) ? self::decode( $_POST['fontChanges'], false ) : [];

			// Get existing custom fonts from database for comparison
			$existing_custom_fonts = Custom_Fonts::get_custom_fonts();

			// Track actual post IDs for fonts (for variant processing later)
			$actual_post_ids = [];

			// Helper function to get font extension from URL or type
			$get_font_extension = function( $font_data ) {
				// First try to get from type
				if ( isset( $font_data['type'] ) && $font_data['type'] ) {
					$type_parts = explode( '/', $font_data['type'] );
					if ( count( $type_parts ) >= 2 ) {
						$extension = $type_parts[1];
						// Normalize common variations
						if ( $extension === 'truetype' ) {
							$extension = 'ttf';
						}
						return $extension;
					}
				}

				// Fallback to getting from URL
				if ( isset( $font_data['url'] ) && $font_data['url'] ) {
					$path_info = pathinfo( $font_data['url'] );
					if ( isset( $path_info['extension'] ) ) {
						return strtolower( $path_info['extension'] );
					}
				}

				// Default fallback
				return 'woff2';
			};

			$apply_subset_metadata = function( $subset_entry, $font_data ) {
				if ( ! empty( $font_data['unicode-range'] ) ) {
					$unicode_range = Custom_Fonts::sanitize_unicode_range( $font_data['unicode-range'] );

					if ( $unicode_range ) {
						$subset_entry['unicode-range'] = $unicode_range;
					}
				}

				if ( ! empty( $font_data['font-weight-range'] ) ) {
					$font_weight_range = Custom_Fonts::sanitize_font_weight_range( $font_data['font-weight-range'] );

					if ( $font_weight_range ) {
						$subset_entry['font-weight-range'] = $font_weight_range;
					}
				}

				return $subset_entry;
			};

			// Process family level changes
			if ( ! empty( $font_changes['families'] ) ) {
				// Process deleted fonts - check if any existing fonts are missing from new data
				if ( $existing_custom_fonts ) {
					foreach ( $existing_custom_fonts as $font_id => $font_data ) {
						if ( ! isset( $custom_fonts[ $font_id ] ) || in_array( $font_id, $font_changes['families']['deleted'] ) ) {
							$post_id = intval( str_replace( 'custom_font_', '', $font_id ) );
							wp_delete_post( $post_id, true );
						}
					}
				}

				// Process added and modified fonts
				foreach ( $custom_fonts as $font_id => $font_data ) {
					$post_id     = intval( str_replace( 'custom_font_', '', $font_id ) );
					$is_new      = ! isset( $existing_custom_fonts[ $font_id ] );
					$is_modified = in_array( $font_id, $font_changes['families']['modified'] );

					if ( $is_new || $is_modified ) {
						// Prepare post data
						$post_data = [
							'post_title'  => $font_data['family'],
							'post_type'   => BRICKS_DB_CUSTOM_FONTS,
							'post_status' => 'publish',
						];

						if ( ! $is_new ) {
							$post_data['ID'] = $post_id;
							$result          = wp_update_post( $post_data );

							// For existing fonts, post_id stays the same
							$actual_post_ids[ $font_id ] = $post_id;
						} else {
							// Check if this is a draft post that already exists (created by create_draft_font)
							$existing_post = get_post( $post_id );
							if ( $existing_post && $existing_post->post_type === BRICKS_DB_CUSTOM_FONTS ) {
								// Update the existing draft post
								$post_data['ID']             = $post_id;
								$result                      = wp_update_post( $post_data );
								$actual_post_ids[ $font_id ] = $post_id;
							} else {
								// Create a completely new post
								$new_post_id                 = wp_insert_post( $post_data );
								$actual_post_ids[ $font_id ] = $new_post_id;
								$post_id                     = $new_post_id;
							}
						}

						// Update font faces if they exist
						if ( isset( $font_data['fontFaces'] ) ) {
							// Check if fontFaces is an array before updating
							if ( is_array( $font_data['fontFaces'] ) ) {
								// Convert frontend format (URL/type) to database format (attachment ID/extension)
								$converted_font_faces = [];
								foreach ( $font_data['fontFaces'] as $variant_key => $font_face ) {
									if ( isset( $font_face[0] ) && is_array( $font_face[0] ) ) {
										$converted_font_faces[ $variant_key ] = [];

										foreach ( $font_face as $subset ) {
											if ( empty( $subset['url'] ) ) {
												continue;
											}

											$font_extension = $get_font_extension( $subset );
											$attachment_id  = attachment_url_to_postid( $subset['url'] );

											if ( ! $attachment_id ) {
												continue;
											}

											$subset_entry                           = [ $font_extension => $attachment_id ];
											$converted_font_faces[ $variant_key ][] = $apply_subset_metadata( $subset_entry, $subset );
										}

										if ( empty( $converted_font_faces[ $variant_key ] ) ) {
											unset( $converted_font_faces[ $variant_key ] );
										}
									} elseif ( isset( $font_face['url'] ) && $font_face['url'] ) {
										$font_extension = $get_font_extension( $font_face );
										$attachment_id  = attachment_url_to_postid( $font_face['url'] );

										if ( $attachment_id ) {
											$subset_entry                         = [ $font_extension => $attachment_id ];
											$converted_font_faces[ $variant_key ] = $apply_subset_metadata( $subset_entry, $font_face );
										}
									}
								}

								$meta_result = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $converted_font_faces );
							}
						}
					} else {
						// For unchanged fonts, still track their post_id
						$actual_post_ids[ $font_id ] = $post_id;
					}
				}
			}

			// Process variant level changes
			if ( ! empty( $font_changes['variants'] ) ) {

				// Before processing, detect "replace" scenarios where same variant is both deleted and added
				// and move them to modified instead
				$deleted_variants = $font_changes['variants']['deleted'] ?? [];
				$added_variants   = $font_changes['variants']['added'] ?? [];

				foreach ( $deleted_variants as $font_id => $variants ) {
					if ( isset( $added_variants[ $font_id ] ) ) {
						// Find variants that are both deleted and added (replacements)
						$common_variants = array_intersect( $variants, $added_variants[ $font_id ] );

						if ( ! empty( $common_variants ) ) {

							// Move common variants from deleted/added to modified
							if ( ! isset( $font_changes['variants']['modified'] ) ) {
								$font_changes['variants']['modified'] = [];
							}
							if ( ! isset( $font_changes['variants']['modified'][ $font_id ] ) ) {
								$font_changes['variants']['modified'][ $font_id ] = [];
							}

							foreach ( $common_variants as $variant_key ) {
								// Add to modified
								if ( ! in_array( $variant_key, $font_changes['variants']['modified'][ $font_id ] ) ) {
									$font_changes['variants']['modified'][ $font_id ][] = $variant_key;
								}

								// Remove from deleted
								$delete_index = array_search( $variant_key, $font_changes['variants']['deleted'][ $font_id ] );
								if ( $delete_index !== false ) {
									unset( $font_changes['variants']['deleted'][ $font_id ][ $delete_index ] );
								}

								// Remove from added
								$add_index = array_search( $variant_key, $font_changes['variants']['added'][ $font_id ] );
								if ( $add_index !== false ) {
									unset( $font_changes['variants']['added'][ $font_id ][ $add_index ] );
								}
							}

							// Clean up empty arrays
							if ( empty( $font_changes['variants']['deleted'][ $font_id ] ) ) {
								unset( $font_changes['variants']['deleted'][ $font_id ] );
							}
							if ( empty( $font_changes['variants']['added'][ $font_id ] ) ) {
								unset( $font_changes['variants']['added'][ $font_id ] );
							}
						}
					}
				}

				foreach ( $font_changes['variants'] as $change_type => $fonts ) {
					if ( empty( $fonts ) ) {
						continue;
					}

					foreach ( $fonts as $font_id => $variants ) {
						if ( empty( $variants ) ) {
							continue;
						}

						// Use the actual post_id that was tracked during family processing
						$post_id = isset( $actual_post_ids[ $font_id ] ) ? $actual_post_ids[ $font_id ] : intval( str_replace( 'custom_font_', '', $font_id ) );

						// Get current font faces
						$current_font_faces = get_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, true );

						// If we have a structured array of font faces
						if ( is_array( $current_font_faces ) ) {
							// Process deleted variants
							if ( $change_type === 'deleted' ) {
								foreach ( $variants as $variant_key ) {
									// Extract weight and style from variant key (e.g., "400normal")
									preg_match( '/(\d+)(.*)/', $variant_key, $matches );
									if ( count( $matches ) >= 3 ) {
										$weight = $matches[1];
										$style  = $matches[2] ? $matches[2] : 'normal';

										// Construct the expected database key format
										$db_key = $weight;
										if ( $style !== 'normal' ) {
											$db_key .= $style;
										}

										// Unset using the determined database key
										if ( isset( $current_font_faces[ $db_key ] ) ) {
											unset( $current_font_faces[ $db_key ] );
										}

										// Also unset using the client-side key as a fallback for potential inconsistencies
										if ( $db_key !== $variant_key && isset( $current_font_faces[ $variant_key ] ) ) {
											unset( $current_font_faces[ $variant_key ] );
										}
									}
								}

								// Update the font faces
								$meta_result = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $current_font_faces );
							}
							// Process added variants
							elseif ( $change_type === 'added' ) {
								// Get the updated font data from the client
								if ( isset( $custom_fonts[ $font_id ] ) && isset( $custom_fonts[ $font_id ]['fontFaces'] ) ) {
									$new_font_faces = $custom_fonts[ $font_id ]['fontFaces'];

									// If fontFaces is a string (CSS), convert it to an array
									if ( is_string( $new_font_faces ) ) {
										// Try to parse as JSON first
										$decoded = json_decode( $new_font_faces, true );
										if ( $decoded && is_array( $decoded ) ) {
											$new_font_faces = $decoded;
										} else {
											// If not JSON, it might be a CSS string - we'll keep the current structure
											$new_font_faces = $current_font_faces;
										}
									}

									// For each added variant, ensure it exists in the font faces
									foreach ( $variants as $variant_key ) {
										// Extract weight and style from variant key (e.g., "400normal")
										preg_match( '/(\d+)(.*)/', $variant_key, $matches );
										if ( count( $matches ) >= 3 ) {
											$weight = $matches[1];
											$style  = $matches[2] ? $matches[2] : 'normal';
											$db_key = $weight;
											if ( $style !== 'normal' ) {
												$db_key .= $style;
											}

											// Check if this variant exists in the new font faces
											if ( isset( $new_font_faces[ $variant_key ] ) ) {
												$new_face_data = $new_font_faces[ $variant_key ];
												$new_entry     = [];

												// Handle array of subsets
												if ( isset( $new_face_data[0] ) && is_array( $new_face_data[0] ) ) {
													foreach ( $new_face_data as $subset ) {
														if ( isset( $subset['url'] ) ) {
															$font_extension = $get_font_extension( $subset );
															$attachment_id  = attachment_url_to_postid( $subset['url'] );
															if ( $attachment_id ) {
																$subset_entry = [ $font_extension => $attachment_id ];
																$new_entry[]  = $apply_subset_metadata( $subset_entry, $subset );
															}
														}
													}
												} else {
													// Single (legacy)
													if ( isset( $new_face_data['url'] ) ) {
														$font_extension = $get_font_extension( $new_face_data );
														$attachment_id  = attachment_url_to_postid( $new_face_data['url'] );
														if ( $attachment_id ) {
															$new_entry = $apply_subset_metadata(
																[ $font_extension => $attachment_id ],
																$new_face_data
															);
														}
													}
												}

												if ( ! empty( $new_entry ) ) {
													$current_font_faces[ $db_key ] = $new_entry;
													// Clean up variant_key if different from db_key
													if ( $db_key !== $variant_key && isset( $current_font_faces[ $variant_key ] ) ) {
														unset( $current_font_faces[ $variant_key ] );
													}
												}
											}
										}
									}

									// Update the font faces
									$meta_result = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $current_font_faces );
								}
							}
							// Process modified variants
							elseif ( $change_type === 'modified' ) {
								// Get the updated font data from the client
								if ( isset( $custom_fonts[ $font_id ] ) && isset( $custom_fonts[ $font_id ]['fontFaces'] ) ) {
									$new_font_faces = $custom_fonts[ $font_id ]['fontFaces'];

									// If fontFaces is a string (CSS), convert it to an array
									if ( is_string( $new_font_faces ) ) {
										// Try to parse as JSON first
										$decoded = json_decode( $new_font_faces, true );
										if ( $decoded ) {
											$new_font_faces = $decoded;
										}
									}

									// For each modified variant, update it in the font faces
									foreach ( $variants as $variant_key ) {
										// Extract weight and style from variant key (e.g., "400normal")
										preg_match( '/(\d+)(.*)/', $variant_key, $matches );
										if ( count( $matches ) >= 3 ) {
											$weight = $matches[1];
											$style  = $matches[2] ? $matches[2] : 'normal';
											$db_key = $weight;
											if ( $style !== 'normal' ) {
												$db_key .= $style;
											}

											// Check if this variant exists in the new font faces
											if ( isset( $new_font_faces[ $variant_key ] ) ) {
												$new_face_data = $new_font_faces[ $variant_key ];
												$new_entry     = [];

												// Handle array of subsets
												if ( isset( $new_face_data[0] ) && is_array( $new_face_data[0] ) ) {
													foreach ( $new_face_data as $subset ) {
														if ( isset( $subset['url'] ) ) {
															$font_extension = $get_font_extension( $subset );
															$attachment_id  = attachment_url_to_postid( $subset['url'] );
															if ( $attachment_id ) {
																$subset_entry = [ $font_extension => $attachment_id ];
																$new_entry[]  = $apply_subset_metadata( $subset_entry, $subset );
															}
														}
													}
												} else {
													// Single (legacy)
													if ( isset( $new_face_data['url'] ) ) {
														$font_extension = $get_font_extension( $new_face_data );
														$attachment_id  = attachment_url_to_postid( $new_face_data['url'] );
														if ( $attachment_id ) {
															$new_entry = $apply_subset_metadata(
																[ $font_extension => $attachment_id ],
																$new_face_data
															);
														}
													}
												}

												if ( ! empty( $new_entry ) ) {
													$current_font_faces[ $db_key ] = $new_entry;
													// Clean up variant_key if different from db_key
													if ( $db_key !== $variant_key && isset( $current_font_faces[ $variant_key ] ) ) {
														unset( $current_font_faces[ $variant_key ] );
													}
												}
											}
										}
									}

									// Update the font faces
									$meta_result = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $current_font_faces );
								}
							}
						}
						// If we have font faces from the client
						elseif ( isset( $custom_fonts[ $font_id ]['fontFaces'] ) ) {
							// Convert from URL/type format to extension/attachment_id format
							if ( is_array( $custom_fonts[ $font_id ]['fontFaces'] ) ) {
								$converted_font_faces = [];
								foreach ( $custom_fonts[ $font_id ]['fontFaces'] as $variant_key => $font_data ) {
									if ( isset( $font_data[0] ) && is_array( $font_data[0] ) ) {
										$converted_font_faces[ $variant_key ] = [];

										foreach ( $font_data as $subset ) {
											if ( empty( $subset['url'] ) ) {
												continue;
											}

											$font_extension = $get_font_extension( $subset );
											$attachment_id  = attachment_url_to_postid( $subset['url'] );

											if ( ! $attachment_id ) {
												continue;
											}

											$subset_entry                           = [ $font_extension => $attachment_id ];
											$converted_font_faces[ $variant_key ][] = $apply_subset_metadata( $subset_entry, $subset );
										}

										if ( empty( $converted_font_faces[ $variant_key ] ) ) {
											unset( $converted_font_faces[ $variant_key ] );
										}
									} elseif ( isset( $font_data['url'] ) && $font_data['url'] ) {
										$font_extension = $get_font_extension( $font_data );
										$attachment_id  = attachment_url_to_postid( $font_data['url'] );

										if ( $attachment_id ) {
											$subset_entry                         = [ $font_extension => $attachment_id ];
											$converted_font_faces[ $variant_key ] = $apply_subset_metadata( $subset_entry, $font_data );
										}
									}
								}

								if ( ! empty( $converted_font_faces ) ) {
									$meta_result = update_post_meta( $post_id, BRICKS_DB_CUSTOM_FONT_FACES, $converted_font_faces );
								}
							}
						}
					}
				}
			}

			// Regenerate custom font face CSS rules after font changes
			// This ensures fonts are immediately available in frontend/builder
			if ( ! empty( $font_changes ) || ! empty( $custom_fonts ) ) {
				// Clear the static cache to force regeneration
				Custom_Fonts::$fonts           = false;
				Custom_Fonts::$font_face_rules = '';

				// Get all custom fonts (this regenerates the CSS rules)
				$fonts = Custom_Fonts::get_custom_fonts();

				// Update the cached CSS rules option
				if ( Custom_Fonts::$font_face_rules ) {
					update_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES, Custom_Fonts::$font_face_rules );
				} else {
					delete_option( BRICKS_DB_CUSTOM_FONT_FACE_RULES );
				}
			}
		}

		/**
		 * STEP: Components changed: Regenerate external files CSS
		 * NOTE: Reconsider if this ends up causing builder save delay on large sites.
		 *
		 * @since 2.0
		 */
		if ( $bricks_data_changed &&
			is_array( $components ) &&
			! empty( $components ) &&
			Database::get_setting( 'cssLoading' ) === 'file'
		) {
			// Create a CRON job to regenerate CSS files in the background
			Assets_Files::schedule_css_file_regeneration();
		}

		$response_data = $_POST;

		if ( $icon_set_attachment_cleanup_result ) {
			$response_data['iconSetAttachmentCleanupResult'] = $icon_set_attachment_cleanup_result;
		}

		if ( self::auto_signed_code_response_has_entries( $auto_signed_code ) ) {
			$response_data['autoSignedCode'] = $auto_signed_code;
		}

		wp_send_json_success( $response_data );
	}

	/**
	 * Save generated template screenshot
	 *
	 * @since 1.10
	 */
	public function save_template_screenshot() {
		// Verify nonce
		self::verify_request( 'bricks-nonce-builder' );

		// Check if the user can upload files
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'User does not have permission to upload files.' );
		}

		$post_id = ! empty( $_POST['screenshotPostId'] ) ? intval( $_POST['screenshotPostId'] ) : 0;

		// Return: No post ID set
		if ( ! $post_id ) {
			wp_send_json_error( 'Error: No screenshotPostId provided!' );
		}

		if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( 'verify_request: User cannot use builder (' . get_current_user_id() . ')' );
		}

		if ( ! Database::get_setting( 'generateTemplateScreenshots', false ) ) {
			wp_send_json_error( esc_html__( 'Template screenshot generation is disabled.', 'bricks' ) );
		}

		if ( ! isset( $_FILES['screenshot'] ) || empty( $_FILES['screenshot'] ) ) {
			wp_send_json_error( 'No screenshot file provided' );
		}

		$screenshot_file = $_FILES['screenshot'];

		// Check for upload errors
		if ( $screenshot_file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( 'File upload error: ' . $screenshot_file['error'] );
		}

		// Check file size (limit to 5MB for security purposes)
		$max_size = 5 * 1024 * 1024; // 5MB
		if ( $screenshot_file['size'] > $max_size ) {
			wp_send_json_error( 'File size exceeds the maximum limit.' );
		}

		// Use the WordPress compatibility layer so optional EXIF and Fileinfo extensions are not required.
		$mime_type = false;

		if ( function_exists( 'wp_get_image_mime' ) ) {
			$mime_type = wp_get_image_mime( $screenshot_file['tmp_name'] );
		} elseif ( function_exists( 'getimagesize' ) ) {
			$image_size = getimagesize( $screenshot_file['tmp_name'] );
			$mime_type  = ! empty( $image_size['mime'] ) ? $image_size['mime'] : false;
		}

		$allowed_types = [ 'image/webp', 'image/png' ];

		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			wp_send_json_error( 'Invalid file type. Expected WebP or PNG image.' );
		}

		// Get WordPress upload directory
		$wp_upload_dir = wp_upload_dir();
		$custom_dir    = $wp_upload_dir['basedir'] . '/' . BRICKS_TEMPLATE_SCREENSHOTS_DIR . '/';

		// Ensure the custom directory exists and is writable
		if ( ! file_exists( $custom_dir ) ) {
			if ( ! wp_mkdir_p( $custom_dir ) ) {
				wp_send_json_error( 'Failed to create upload directory.' );
			}
		}

		if ( ! is_writable( $custom_dir ) ) {
			wp_send_json_error( 'Upload directory is not writable.' );
		}

		// Get and then Deleted all existing files in the custom directory starting with 'template-screenshot-$post_id-'
		$existing_files = glob( $custom_dir . "template-screenshot-$post_id-*" );
		foreach ( $existing_files as $file ) {
			if ( file_exists( $file ) && ! unlink( $file ) ) {
				error_log( "Failed to delete old screenshot file: $file" );
			}
		}

		// Define the file path with timestamp to avoid caching issues
		$timestamp = time();
		$extension = ( $mime_type === 'image/webp' ) ? 'webp' : 'png';
		$filename  = "template-screenshot-$post_id-$timestamp.$extension";
		$filepath  = $custom_dir . $filename;

		// Move the uploaded file
		if ( ! move_uploaded_file( $screenshot_file['tmp_name'], $filepath ) ) {
			wp_send_json_error( 'Error saving screenshot' );
		}

		// Verify that the file was actually uploaded and is readable
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			wp_send_json_error( 'Failed to save or read the uploaded file.' );
		}

		// Convert file path to URL
		$fileurl = str_replace( $wp_upload_dir['basedir'], $wp_upload_dir['baseurl'], $filepath );

		// Sanitize the URL before sending it back
		$fileurl = esc_url( $fileurl );

		wp_send_json_success( [ 'file_url' => $fileurl ] );
	}

	/**
	 * Sanitize Bricks postmeta
	 */
	public function sanitize_bricks_postmeta( $meta_value, $meta_key, $object_type ) {
		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : get_the_ID();

		// Return: No post ID set
		if ( ! $post_id ) {
			return $meta_value;
		}

		// Return: Is in-builder
		if ( check_ajax_referer( 'bricks-nonce-builder', 'nonce', false ) ) {
			return $meta_value;
		}

		if ( $meta_key === BRICKS_DB_PAGE_CONTENT ) {
			$meta_value = Helpers::security_check_elements_before_save( $meta_value, $post_id, 'content' );
		} elseif ( $meta_key === BRICKS_DB_PAGE_HEADER ) {
			$meta_value = Helpers::security_check_elements_before_save( $meta_value, $post_id, 'header' );
		} elseif ( $meta_key === BRICKS_DB_PAGE_FOOTER ) {
			$meta_value = Helpers::security_check_elements_before_save( $meta_value, $post_id, 'footer' );
		}

		return $meta_value;
	}

	/**
	 * Sanitize Bricks postmeta page settings
	 *
	 * @since 1.9.9
	 */
	public function sanitize_bricks_postmeta_page_settings( $meta_value, $meta_key, $object_type ) {
		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : get_the_ID();

		// Return: No post ID set
		if ( ! $post_id ) {
			return $meta_value;
		}

		if ( $meta_key === BRICKS_DB_PAGE_SETTINGS ) {
			// User has no 'unfiltered_html' cap: Remove page settings that could contain <script> tags
			if ( ! current_user_can( 'unfiltered_html' ) && is_array( $meta_value ) ) {
				// Remove script settings
				foreach ( $meta_value as $key => $value ) {
					if ( in_array( $key, [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ] ) ) {
						unset( $meta_value[ $key ] );
					}
				}

				// Use existing script settings (added by user with 'unfiltered_html' cap)
				$existing_page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );
				if ( is_array( $existing_page_settings ) ) {
					foreach ( $existing_page_settings as $key => $value ) {
						if ( in_array( $key, [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ] ) ) {
							$meta_value[ $key ] = $existing_page_settings[ $key ];
						}
					}
				}
			}
		}

		return $meta_value;
	}

	/**
	 * Update postmeta: Prevent user without builder access from updating Bricks postmeta
	 */
	public function update_bricks_postmeta( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		// Return: Not Bricks postmeta
		$is_bricks_postmeta = in_array( $meta_key, [ BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_FOOTER ], true );
		if ( $is_bricks_postmeta ) {
			// Revisions use post type 'revision': Check builder access against the parent post ID (@since 2.3.2)
			$builder_access_post_id = wp_is_post_revision( $object_id );

			if ( $builder_access_post_id ) {
				$builder_access_post_id = (int) $builder_access_post_id;
			} else {
				$builder_access_post_id = $object_id;
			}

			// User doesn't have builder access, but WPML is processing a translation, allow the update (@since 1.11)
			if ( ! Capabilities::current_user_can_use_builder( $builder_access_post_id ) && \Bricks\Integrations\Wpml\Wpml::is_processing_wpml_translation() ) {
				// Reset the processing flag after our check
				\Bricks\Integrations\Wpml\Wpml::end_processing_wpml_translation();

				return $check;
			}

			// If the user does not have builder access and WPML is not processing a translation, block the update
			if ( ! Capabilities::current_user_can_use_builder( $builder_access_post_id ) ) {
				return false;
			}
		}

		// STEP: Handle password protection template population
		if ( $meta_key === BRICKS_DB_TEMPLATE_TYPE && $meta_value === 'password_protection' && get_post_type( $object_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
			// Check if template type was just set to password_protection
			$template_type = get_post_meta( $object_id, BRICKS_DB_TEMPLATE_TYPE, true );

			if ( $template_type === 'password_protection' && Capabilities::current_user_can_use_builder( $object_id ) ) {
				// Get current content
				$current_content = get_post_meta( $object_id, BRICKS_DB_PAGE_CONTENT, true );

				// If content is empty, populate the template
				if ( empty( $current_content ) ) {
					Password_Protection::populate_template( $object_id );
				}
			}
		}

		return $check;
	}

	/**
	 * Create autosave
	 *
	 * @since 1.0
	 */
	public static function create_autosave() {
		self::verify_request( 'bricks-nonce-builder' );

		$area     = ! empty( $_POST['area'] ) ? sanitize_text_field( $_POST['area'] ) : false;
		$post_id  = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;
		$elements = ! empty( $_POST['elements'] ) ? self::decode( $_POST['elements'] ) : false;

		if ( ! $area || ! $post_id || ! $elements ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		// 1/2: Create autosave
		$autosave_id = wp_create_post_autosave(
			[
				'post_ID'       => $post_id,
				'post_type'     => $post_type,
				'post_excerpt'  => '<!-- Built With Bricks -->', // Forces $autosave_is_different to 'true'
				'post_modified' => current_time( 'mysql' ),
			]
		);

		if ( is_wp_error( $autosave_id ) ) {
			wp_send_json_error( new \WP_Error( 'autosave_error', $autosave_id ) );
		}

		// 2/2: Save elements in db post meta with autosave post ID
		$elements = self::decode( $_POST['elements'] );

		$elements = Helpers::security_check_elements_before_save( $elements, $post_id, 'content' );

		if ( ! is_array( $elements ) ) {
			wp_send_json_error( new \WP_Error( 'element_error', 'No elements' ) );
		}

		switch ( $area ) {
			case 'header':
				update_metadata( 'post', $autosave_id, BRICKS_DB_PAGE_HEADER, $elements );
				break;

			case 'content':
				update_metadata( 'post', $autosave_id, BRICKS_DB_PAGE_CONTENT, $elements );
				break;

			case 'footer':
				update_metadata( 'post', $autosave_id, BRICKS_DB_PAGE_FOOTER, $elements );
				break;
		}

		wp_send_json_success( [ 'autosave_id' => $autosave_id ] );
	}

	/**
	 * Get bulider URL
	 *
	 * To reload builder with newly saved postName/postTitle (page settigns)
	 *
	 * @since 1.0
	 */
	public function get_builder_url() {
		self::verify_request( 'bricks-nonce-builder' );

		wp_send_json_success( [ 'url' => Helpers::get_builder_edit_link( ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0 ) ] );
	}

	/**
	 * Publish post
	 *
	 * @since 1.0
	 */
	public function publish_post() {
		self::verify_request( 'bricks-nonce-builder' );

		// Return: Current user can not publish posts
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_send_json_error( 'Error: You do not have permission to publish posts' );
		}

		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( 'Error: No postId provided.' );
		}

		$response = wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		wp_send_json_success( $response );
	}

	/**
	 * Get image metadata
	 *
	 * @since 1.0
	 */
	public function get_image_metadata() {
		if ( bricks_is_builder() || bricks_is_builder_call() ) {
			$nonce = 'bricks-nonce-builder';
		} elseif ( is_admin() ) {
			$nonce = 'bricks-nonce-admin';
		}

		self::verify_request( $nonce ?? 'bricks-nonce' );

		$image_id   = ! empty( $_POST['imageId'] ) ? intval( $_POST['imageId'] ) : 0;
		$image_size = ! empty( $_POST['imageSize'] ) ? sanitize_text_field( $_POST['imageSize'] ) : '';

		if ( ! $image_id ) {
			wp_send_json_error( 'Error: No imageId provided.' );
		}

		$get_attachment_metadata = wp_get_attachment_metadata( $image_id );

		// SVG returns empty metadata but still has a preview URL.
		if ( ! $get_attachment_metadata ) {
			wp_send_json_success( [ 'src' => wp_get_attachment_image_src( $image_id, $image_size ) ] );
		}

		$response = [
			'filename' => isset( $get_attachment_metadata['original_image'] ) ? $get_attachment_metadata['original_image'] : '',
			'full'     => [
				'width'  => isset( $get_attachment_metadata['width'] ) ? $get_attachment_metadata['width'] : '',
				'height' => isset( $get_attachment_metadata['height'] ) ? $get_attachment_metadata['height'] : '',
			],
			'sizes'    => isset( $get_attachment_metadata['sizes'] ) ? $get_attachment_metadata['sizes'] : [],
			'src'      => wp_get_attachment_image_src( $image_id, $image_size ),
		];

		wp_send_json_success( $response );
	}

	/**
	 * Get Image Id from a custom field
	 *
	 * @since 1.0
	 */
	public function get_image_from_custom_field() {
		self::verify_request( 'bricks-nonce-builder' );

		$image_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;
		$meta_key = ! empty( $_POST['metaKey'] ) ? sanitize_text_field( $_POST['metaKey'] ) : '';
		$size     = ! empty( $_POST['size'] ) ? sanitize_text_field( $_POST['size'] ) : BRICKS_DEFAULT_IMAGE_SIZE;

		if ( ! $image_id ) {
			wp_send_json_error( 'Error: No postId provided' );
		}

		if ( ! $meta_key ) {
			wp_send_json_error( 'Error: No postmeta key provided' );
		}

		// Get images from custom field
		$images = Integrations\Dynamic_Data\Providers::render_tag( $meta_key, $image_id, 'image', [ 'size' => $size ] );

		if ( empty( $images ) ) {
			wp_send_json_error( 'Error: Image not found' );
		}

		if ( is_numeric( $images[0] ) ) {
			$get_attachment_metadata = wp_get_attachment_metadata( $images[0] );

			if ( empty( $get_attachment_metadata ) ) {
				wp_send_json_error( 'Error: Image not found' );
			}

			$output = [
				'filename' => isset( $get_attachment_metadata['original_image'] ) ? $get_attachment_metadata['original_image'] : '',
				'id'       => $images[0],
				'size'     => $size,
				'url'      => wp_get_attachment_image_url( $images[0], $size ),
			];
		}

		// Might be a Gravatar image
		else {
			$output = [
				'url' => $images[0]
			];
		}

		wp_send_json_success( $output );
	}

	/**
	 * Download image to WordPress media library (Unsplash)
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function download_image() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed', 'bricks' ) ], 403 );
		}

		// http://www.codingduniya.com/2016/07/generate-featured-image-for-post-using.html
		$file_array   = [];
		$download_url = isset( $_POST['download_url'] ) && is_string( $_POST['download_url'] ) ? esc_url_raw( wp_unslash( $_POST['download_url'] ) ) : '';

		$tmp = download_url( $download_url );

		$file_array['tmp_name'] = $tmp;

		// Manually add file extension as Unsplash download URL doesn't provide file extension
		$file_name          = isset( $_POST['file_name'] ) && is_string( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';
		$file_array['name'] = $file_name ? $file_name . '.jpg' : '';

		// Check for download errors
		if ( is_wp_error( $tmp ) ) {
			wp_send_json_error( $tmp );
		}

		$id = media_handle_sideload( $file_array, 0 );

		// If error storing permanently, unlink
		if ( is_wp_error( $id ) ) {
			if ( isset( $file_array['tmp_name'] ) ) {
				wp_delete_file( $file_array['tmp_name'] );
			}

			wp_send_json_error( $id );
		}

		$attachment = wp_prepare_attachment_for_js( $id );

		if ( ! is_array( $attachment ) ) {
			wp_delete_attachment( $id, true );
			wp_send_json_error( [ 'message' => __( 'WordPress could not prepare the uploaded attachment.', 'bricks' ) ], 500 );
		}

		wp_send_json_success( $attachment );
	}

	/**
	 * Import one public media URL from the Builder media browser.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function import_media_from_url() {
		self::verify_request( 'bricks-nonce-builder' );

		$url       = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? trim( wp_unslash( $_POST['url'] ) ) : '';
		$filename  = isset( $_POST['filename'] ) && is_string( $_POST['filename'] ) ? wp_unslash( $_POST['filename'] ) : '';
		$parent_id = isset( $_POST['parentId'] ) && is_scalar( $_POST['parentId'] ) ? absint( $_POST['parentId'] ) : 0;

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				[
					'code'      => 'media_import_upload_not_allowed',
					'message'   => __( 'You are not allowed to upload files.', 'bricks' ),
					'filename'  => '',
					'url'       => $url,
					'retryable' => false,
				],
				403
			);
		}

		if ( $parent_id && ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error(
				[
					'code'      => 'media_import_parent_not_allowed',
					'message'   => __( 'You are not allowed to attach media to this post.', 'bricks' ),
					'filename'  => '',
					'url'       => $url,
					'retryable' => false,
				],
				403
			);
		}

		$attachment_id = Media_Browser_Upload::import_from_url( $url, $parent_id, 'any', $filename );

		if ( is_wp_error( $attachment_id ) ) {
			$error_data = $attachment_id->get_error_data();
			$status     = is_array( $error_data ) && ! empty( $error_data['status'] ) ? (int) $error_data['status'] : 400;

			wp_send_json_error( Media_Browser_Upload::error_payload( $attachment_id, $url ), $status );
		}

		$attachment = wp_prepare_attachment_for_js( $attachment_id );

		if ( ! is_array( $attachment ) ) {
			// Roll back an import the Builder cannot represent instead of leaving a hidden duplicate behind.
			wp_delete_attachment( $attachment_id, true );

			wp_send_json_error(
				[
					'code'      => 'media_import_prepare_failed',
					'message'   => __( 'WordPress could not prepare the uploaded attachment.', 'bricks' ),
					'filename'  => '',
					'url'       => $url,
					'retryable' => true,
				],
				500
			);
		}

		wp_send_json_success(
			[
				'attachment'   => $attachment,
				'capabilities' => [
					'canEdit'   => current_user_can( 'edit_post', $attachment_id ),
					'canDelete' => current_user_can( 'delete_post', $attachment_id ),
				],
			]
		);
	}

	/**
	 * Get one attachment for the Builder media browser inspector.
	 *
	 * Fetching details on demand keeps the paginated media response compact. The
	 * raw metadata sizes supplement WordPress' prepared response, which only
	 * contains image sizes exposed by the media-library chooser filter.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function get_media_attachment_details() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;

		if ( ! current_user_can( 'upload_files' ) || ( $attachment_id && ! current_user_can( 'edit_post', $attachment_id ) ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Not allowed', 'bricks' ),
				],
				403
			);
		}

		if ( ! $attachment_id || get_post_type( $attachment_id ) !== 'attachment' ) {
			wp_send_json_error(
				[
					'message' => __( 'Not found', 'bricks' ),
				],
				404
			);
		}

		$attachment = wp_prepare_attachment_for_js( $attachment_id );

		if ( ! is_array( $attachment ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Not found', 'bricks' ),
				],
				404
			);
		}

		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$attachment = self::prepare_media_attachment_size_details( $attachment_id, $attachment, $metadata );

		if ( class_exists( Media_Browser_Health::class ) ) {
			$attachment['health'] = Media_Browser_Health::get_result( $attachment_id, false );
		}

		wp_send_json_success(
			[
				'attachment'   => $attachment,
				'capabilities' => [
					'canEdit'   => current_user_can( 'edit_post', $attachment_id ),
					'canDelete' => current_user_can( 'delete_post', $attachment_id ),
				],
			]
		);
	}

	/**
	 * Get the current local file state for one Builder attachment control.
	 *
	 * This intentionally checks WordPress' local attachment path instead of its
	 * public URL, which can still be served from a browser or CDN cache after the
	 * underlying file has disappeared.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function get_attachment_file_state() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;

		if ( ! $attachment_id || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Not allowed', 'bricks' ),
				],
				403
			);
		}

		$attachment_exists = get_post_type( $attachment_id ) === 'attachment';

		if ( $attachment_exists && ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Not allowed', 'bricks' ),
				],
				403
			);
		}

		$file_state = $attachment_exists && class_exists( Media_Browser_Health::class )
			? Media_Browser_Health::get_attachment_file_state( $attachment_id )
			: ( $attachment_exists ? 'unknown' : 'missing' );

		wp_send_json_success(
			[
				'fileState'   => $file_state,
				'unavailable' => in_array( $file_state, [ 'missing', 'unreadable', 'empty' ], true ),
			]
		);
	}

	/**
	 * Save one attachment from the Builder media inspector.
	 *
	 * Keeping the decorative flag in the same request as alternative text makes
	 * the two mutually exclusive states atomic from the inspector's perspective.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function save_media_attachment_details() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;
		$values        = [
			'title'       => isset( $_POST['title'] ) && is_scalar( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
			'alt'         => isset( $_POST['alt'] ) && is_scalar( $_POST['alt'] ) ? wp_unslash( $_POST['alt'] ) : '',
			'caption'     => isset( $_POST['caption'] ) && is_scalar( $_POST['caption'] ) ? wp_unslash( $_POST['caption'] ) : '',
			'description' => isset( $_POST['description'] ) && is_scalar( $_POST['description'] ) ? wp_unslash( $_POST['description'] ) : '',
		];

		if ( isset( $_POST['decorative'] ) ) {
			$values['decorative'] = ! empty( $_POST['decorative'] );
		}

		$result = Media_Browser_Health::save_attachment_details( $attachment_id, $values );

		if ( is_wp_error( $result ) ) {
			self::send_media_health_error( $result );
		}

		$attachment = wp_prepare_attachment_for_js( $attachment_id );

		if ( ! is_array( $attachment ) ) {
			self::send_media_health_error(
				new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) )
			);
		}

		$attachment           = self::prepare_media_attachment_size_details( $attachment_id, $attachment, wp_get_attachment_metadata( $attachment_id ) );
		$attachment['health'] = $result;

		wp_send_json_success( [ 'attachment' => $attachment ] );
	}

	/**
	 * Ignore or restore one health finding for one attachment.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function set_media_health_issue_ignored() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;
		$issue_code    = isset( $_POST['issueCode'] ) && is_scalar( $_POST['issueCode'] )
			? sanitize_key( wp_unslash( $_POST['issueCode'] ) )
			: '';
		$ignored       = isset( $_POST['ignored'] ) && is_scalar( $_POST['ignored'] ) && (string) $_POST['ignored'] === '1';
		$result        = Media_Browser_Health::set_issue_ignored( $attachment_id, $issue_code, $ignored );

		if ( is_wp_error( $result ) ) {
			self::send_media_health_error( $result );
		}

		wp_send_json_success( [ 'health' => $result ] );
	}

	/**
	 * Prepare one attachment health remediation dialog.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function prepare_media_health_action() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;
		$action        = isset( $_POST['healthAction'] ) && is_scalar( $_POST['healthAction'] )
			? sanitize_key( wp_unslash( $_POST['healthAction'] ) )
			: '';
		$result        = Media_Browser_Health_Actions::prepare( $attachment_id, $action );

		if ( is_wp_error( $result ) ) {
			self::send_media_health_error( $result );
		}

		wp_send_json_success( [ 'context' => $result ] );
	}

	/**
	 * Process one attachment health remediation action.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function process_media_health_action() {
		self::verify_request( 'bricks-nonce-builder' );

		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] )
			? absint( $_POST['attachmentId'] )
			: 0;
		$action        = isset( $_POST['healthAction'] ) && is_scalar( $_POST['healthAction'] )
			? sanitize_key( wp_unslash( $_POST['healthAction'] ) )
			: '';
		$options       = [
			'mode'         => isset( $_POST['mode'] ) && is_scalar( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
			'quality'      => isset( $_POST['quality'] ) && is_scalar( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 0,
			'maxDimension' => isset( $_POST['maxDimension'] ) && is_scalar( $_POST['maxDimension'] ) ? absint( $_POST['maxDimension'] ) : 0,
			'targetMime'   => isset( $_POST['targetMime'] ) && is_scalar( $_POST['targetMime'] ) ? sanitize_mime_type( wp_unslash( $_POST['targetMime'] ) ) : '',
			'relativePath' => isset( $_POST['relativePath'] ) && is_scalar( $_POST['relativePath'] ) ? wp_unslash( $_POST['relativePath'] ) : '',
			'confirmed'    => isset( $_POST['confirmed'] ) && is_scalar( $_POST['confirmed'] ) && (string) $_POST['confirmed'] === '1',
		];
		$uploaded_file = isset( $_FILES['replacementFile'] ) && is_array( $_FILES['replacementFile'] )
			? $_FILES['replacementFile']
			: null;
		$result        = Media_Browser_Health_Actions::process( $attachment_id, $action, $options, $uploaded_file );

		if ( is_wp_error( $result ) ) {
			self::send_media_health_error( $result );
		}

		$attachment = wp_prepare_attachment_for_js( $attachment_id );

		if ( ! is_array( $attachment ) ) {
			self::send_media_health_error(
				new \WP_Error( 'media_health_attachment_not_found', __( 'Not found', 'bricks' ) )
			);
		}

		$attachment           = self::prepare_media_attachment_size_details( $attachment_id, $attachment, wp_get_attachment_metadata( $attachment_id ) );
		$attachment['health'] = $result['health'];
		$response             = array_merge( $result, [ 'attachment' => $attachment ] );

		if ( ! empty( $result['newAttachmentId'] ) ) {
			$new_attachment = wp_prepare_attachment_for_js( $result['newAttachmentId'] );

			if ( is_array( $new_attachment ) ) {
				$new_attachment            = self::prepare_media_attachment_size_details( $result['newAttachmentId'], $new_attachment, wp_get_attachment_metadata( $result['newAttachmentId'] ) );
				$new_attachment['health']  = $result['newHealth'];
				$response['newAttachment'] = $new_attachment;
			}
		}

		wp_send_json_success( $response );
	}

	/**
	 * Return the HTTP status for a Media Health error code.
	 *
	 * @param string $code Media Health error code.
	 * @return int
	 */
	private static function get_media_health_error_status( $code ) {
		if ( $code === 'media_health_attachment_not_allowed' ) {
			return 403;
		}

		if ( in_array( $code, [ 'media_health_attachment_not_found', 'media_health_issue_not_found' ], true ) ) {
			return 404;
		}

		if ( in_array( $code, [ 'media_health_action_confirmation_required', 'media_health_action_issue_missing' ], true ) ) {
			return 409;
		}

		return 400;
	}

	/**
	 * Send a stable Media Health error payload with an appropriate HTTP status.
	 *
	 * @param \WP_Error $error Media Health error.
	 * @return void
	 */
	private static function send_media_health_error( $error ) {
		$code   = $error->get_error_code();
		$status = self::get_media_health_error_status( $code );

		wp_send_json_error(
			[
				'code'    => $code,
				'message' => $error->get_error_message(),
			],
			$status
		);
	}

	/**
	 * Save global Media Browser preferences exposed inside the Builder.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function save_media_browser_preferences() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed', 'bricks' ) ], 403 );
		}

		$details_docked = isset( $_POST['detailsDocked'] ) && is_scalar( $_POST['detailsDocked'] )
			? (string) $_POST['detailsDocked']
			: '';

		if ( ! in_array( $details_docked, [ '0', '1' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameter', 'bricks' ) ], 400 );
		}

		$details_docked = $details_docked === '1';
		$settings       = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$settings       = is_array( $settings ) ? $settings : [];

		if ( $details_docked ) {
			unset( $settings['builderMediaDetailsDocked'] );
		} else {
			$settings['builderMediaDetailsDocked'] = false;
		}

		update_option( BRICKS_DB_GLOBAL_SETTINGS, $settings );

		wp_send_json_success( [ 'detailsDocked' => $details_docked ] );
	}

	/**
	 * Process one resumable Media Browser health scan chunk.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function scan_media_health() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed', 'bricks' ) ], 403 );
		}

		$force = ! empty( $_POST['force'] );

		if ( $force && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed', 'bricks' ) ], 403 );
		}

		$cursor = isset( $_POST['cursor'] ) && is_scalar( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$result = Media_Browser_Health::scan( $cursor, $force );

		if ( is_wp_error( $result ) ) {
			$status = 400;

			if ( $result->get_error_code() === 'media_health_scan_busy' ) {
				$status = 409;
			} elseif ( $result->get_error_code() === 'media_health_scan_not_allowed' ) {
				$status = 403;
			}

			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				$status
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process one resumable numeric attachment-metadata index chunk.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function scan_media_metadata_index() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed', 'bricks' ) ], 403 );
		}

		$result = Media_Browser_Query::scan_metadata_index();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				in_array( $result->get_error_code(), [ 'media_metadata_index_busy', 'media_metadata_index_invalidated' ], true ) ? 409 : 400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Resolve and snapshot a Builder media bulk selection.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function prepare_media_bulk_selection() {
		self::verify_request( 'bricks-nonce-builder' );

		$selection = isset( $_POST['selection'] ) && is_string( $_POST['selection'] )
			? json_decode( wp_unslash( $_POST['selection'] ), true )
			: [];
		$operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		$token     = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
		$result    = $token
			? Media_Browser_Bulk::get_selection_details( $token, isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0 )
			: Media_Browser_Bulk::prepare_selection( is_array( $selection ) ? $selection : [], $operation );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process one Builder media bulk-action chunk.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function process_media_bulk_action() {
		self::verify_request( 'bricks-nonce-builder' );

		$token     = isset( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
		$operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		$cursor    = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$options   = isset( $_POST['options'] ) && is_string( $_POST['options'] )
			? json_decode( wp_unslash( $_POST['options'] ), true )
			: [];
		$result    = Media_Browser_Bulk::process( $token, $operation, $cursor, is_array( $options ) ? $options : [] );

		if ( is_wp_error( $result ) ) {
			$status = $result->get_error_code() === 'media_bulk_usage_changed' ? 409 : 400;

			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				$status
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Resolve and snapshot a folder-enabled non-media selection.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function prepare_media_folder_bulk_selection() {
		self::verify_request( 'bricks-nonce-builder' );

		$selection = isset( $_POST['selection'] ) && is_string( $_POST['selection'] )
			? json_decode( wp_unslash( $_POST['selection'] ), true )
			: [];
		$result    = Media_Folder_Bulk::prepare_selection( is_array( $selection ) ? $selection : [] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process one folder move chunk for non-media items.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function process_media_folder_bulk_move() {
		self::verify_request( 'bricks-nonce-builder' );

		$token     = isset( $_POST['token'] ) ? sanitize_key( $_POST['token'] ) : '';
		$folder_id = isset( $_POST['folderId'] ) ? absint( $_POST['folderId'] ) : 0;
		$cursor    = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$result    = Media_Folder_Bulk::process_move( $token, $folder_id, $cursor );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create, rename, or delete a media folder through the active provider.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function manage_media_folder() {
		self::verify_request( 'bricks-nonce-builder' );

		$result = Media_Browser_Bulk::manage_folder( $_POST );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				],
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Download a prepared one-time media archive.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function download_media_archive() {
		$token = isset( $_GET['token'] ) ? sanitize_key( $_GET['token'] ) : '';

		if ( ! $token || ! check_ajax_referer( 'bricks-media-archive-' . $token, '_wpnonce', false ) ) {
			wp_die( esc_html__( 'This media download has expired.', 'bricks' ), 403 );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Not allowed', 'bricks' ), 403 );
		}

		$result = Media_Browser_Bulk::stream_archive( $token );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 404 );
		}
	}

	/**
	 * Add every generated image size to a prepared media attachment.
	 *
	 * @since 2.4
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $attachment    Prepared WordPress attachment data.
	 * @param mixed $metadata      Raw WordPress attachment metadata.
	 * @return array
	 */
	protected static function prepare_media_attachment_size_details( $attachment_id, $attachment, $metadata ) {
		$generated_sizes        = [];
		$generated_size_indexes = [];

		if ( ! empty( $attachment['sizes'] ) && is_array( $attachment['sizes'] ) ) {
			foreach ( $attachment['sizes'] as $size_name => $size ) {
				$size_name = (string) $size_name;

				if ( $size_name === '' || $size_name === 'full' || ! is_array( $size ) ) {
					continue;
				}

				$size_url = isset( $size['url'] ) && is_scalar( $size['url'] )
					? esc_url_raw( (string) $size['url'] )
					: '';

				if ( ! $size_url ) {
					continue;
				}

				$generated_size_indexes[ $size_name ] = count( $generated_sizes );
				$generated_sizes[]                    = [
					'name'     => $size_name,
					'filename' => '',
					'url'      => $size_url,
					'width'    => isset( $size['width'] ) && is_scalar( $size['width'] ) ? absint( $size['width'] ) : 0,
					'height'   => isset( $size['height'] ) && is_scalar( $size['height'] ) ? absint( $size['height'] ) : 0,
					'filesize' => self::get_media_size_filesize( $size ),
				];
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			if ( empty( $attachment['sizes'] ) || ! is_array( $attachment['sizes'] ) ) {
				$attachment['sizes'] = [];
			}

			foreach ( $metadata['sizes'] as $size_name => $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}

				$size_name = (string) $size_name;

				if ( $size_name === '' ) {
					continue;
				}

				$size_data = wp_get_attachment_image_src( $attachment_id, $size_name );

				if ( ! is_array( $size_data ) || empty( $size_data[0] ) || ! is_scalar( $size_data[0] ) ) {
					continue;
				}

				$size_url = esc_url_raw( (string) $size_data[0] );

				if ( ! $size_url ) {
					continue;
				}

				$prepared_size = [
					'name'     => $size_name,
					'filename' => ! empty( $size['file'] ) && is_scalar( $size['file'] ) ? wp_basename( (string) $size['file'] ) : '',
					'url'      => $size_url,
					'width'    => isset( $size['width'] ) && is_scalar( $size['width'] )
						? absint( $size['width'] )
						: absint( is_scalar( $size_data[1] ?? null ) ? $size_data[1] : 0 ),
					'height'   => isset( $size['height'] ) && is_scalar( $size['height'] )
						? absint( $size['height'] )
						: absint( is_scalar( $size_data[2] ?? null ) ? $size_data[2] : 0 ),
					'filesize' => self::get_media_size_filesize( $size ),
				];

				if ( isset( $generated_size_indexes[ $size_name ] ) ) {
					$existing_size = $generated_sizes[ $generated_size_indexes[ $size_name ] ];

					if ( ! $prepared_size['filesize'] && ! empty( $existing_size['filesize'] ) ) {
						$prepared_size['filesize'] = $existing_size['filesize'];
					}

					$generated_sizes[ $generated_size_indexes[ $size_name ] ] = $prepared_size;
				} else {
					$generated_size_indexes[ $size_name ] = count( $generated_sizes );
					$generated_sizes[]                    = $prepared_size;
				}

				// Keep picker selection data complete when a custom size is not exposed by WordPress' chooser filter.
				if ( empty( $attachment['sizes'][ $size_name ] ) || ! is_array( $attachment['sizes'][ $size_name ] ) ) {
					$attachment['sizes'][ $size_name ] = [
						'url'    => $prepared_size['url'],
						'width'  => $prepared_size['width'],
						'height' => $prepared_size['height'],
					];
				}
			}
		}

		$attachment['generatedSizes'] = $generated_sizes;

		return $attachment;
	}

	/**
	 * Return a generated media size's file size in bytes.
	 *
	 * WordPress metadata uses `filesize`, while some prepared attachment sources
	 * expose the same value as `filesizeInBytes`.
	 *
	 * @since 2.4
	 *
	 * @param array $size Prepared or raw media size data.
	 * @return int
	 */
	protected static function get_media_size_filesize( $size ) {
		foreach ( [ 'filesizeInBytes', 'filesize' ] as $key ) {
			if ( isset( $size[ $key ] ) && is_scalar( $size[ $key ] ) ) {
				return absint( $size[ $key ] );
			}
		}

		return 0;
	}

	/**
	 * Verify or repair the destination of an already uploaded attachment.
	 *
	 * @since 2.4.2
	 * @since 2.4.4 Accepts the queued provider ID for custom-provider verification and retry.
	 * @return void
	 */
	public function verify_media_upload_folder() {
		self::verify_request( 'bricks-nonce-builder' );
		// providerId is optional for older clients; new queues pin it to prevent retargeting.
		$attachment_id = isset( $_POST['attachmentId'] ) && is_scalar( $_POST['attachmentId'] ) ? absint( $_POST['attachmentId'] ) : 0;
		$folder_id     = isset( $_POST['folderId'] ) && is_scalar( $_POST['folderId'] ) ? absint( $_POST['folderId'] ) : 0;
		$retry         = isset( $_POST['retry'] ) && $_POST['retry'] === '1';
		$provider_id   = isset( $_POST['providerId'] ) && is_scalar( $_POST['providerId'] ) ? sanitize_key( wp_unslash( $_POST['providerId'] ) ) : '';
		$result        = Media_Browser_Upload::verify_folder_assignment( $attachment_id, $folder_id, $retry, $provider_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				$result->get_error_code() === 'media_folder_not_allowed' ? 403 : 400
			);
		}
		wp_send_json_success(
			[
				'attachmentId' => $attachment_id,
				'folderId'     => $folder_id
			]
		);
	}

	/**
	 * Check a staged media filename against the target WordPress upload directory.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function check_media_filename() {
		self::verify_request( 'bricks-nonce-builder' );

		$filename  = isset( $_POST['filename'] ) && is_string( $_POST['filename'] ) ? wp_unslash( $_POST['filename'] ) : '';
		$parent_id = isset( $_POST['parentId'] ) && is_scalar( $_POST['parentId'] ) ? absint( $_POST['parentId'] ) : 0;

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error(
				[
					'code'    => 'media_upload_not_allowed',
					'message' => __( 'You are not allowed to upload files.', 'bricks' ),
				],
				403
			);
		}

		if ( $parent_id && ! current_user_can( 'edit_post', $parent_id ) ) {
			wp_send_json_error(
				[
					'code'    => 'media_upload_parent_not_allowed',
					'message' => __( 'You are not allowed to attach media to this post.', 'bricks' ),
				],
				403
			);
		}

		$availability = Media_Browser_Upload::check_filename_availability( $filename, $parent_id );

		if ( is_wp_error( $availability ) ) {
			$error_data = $availability->get_error_data();
			$status     = is_array( $error_data ) && ! empty( $error_data['status'] ) ? (int) $error_data['status'] : 400;

			wp_send_json_error( Media_Browser_Upload::error_payload( $availability ), $status );
		}

		wp_send_json_success( $availability );
	}

	/**
	 * Import paste element images (cross-site)
	 *
	 * @since 1.12.2
	 */
	public function import_images() {
		self::verify_request( 'bricks-nonce-builder' );

		$target_images  = $_POST['images'] ?? [];
		$handled_images = [];

		foreach ( $target_images as $target_image ) {
			$element_id   = $target_image['elementId'] ?? false; // Will replace the new image settings to this element
			$element_name = $target_image['elementName'] ?? false;
			$setting_key  = $target_image['settingKey'] ?? false; // Will replace the new image settings to this setting key
			$settings     = $target_image['settings'] ?? []; // Copied image settings

			// STEP: Resue the logic in Templates::import_images()
			if ( count( $settings ) ) {
				// Handle image-gallery element
				if ( $element_name === 'image-gallery' ) {
					// The images are stored in the 'images' setting key
					$images = $settings['images'] ?? [];
					$size   = $settings['size'] ?? 'full';

					if ( ! count( $images ) ) {
						continue;
					}

					$new_images = [];

					foreach ( $images as $image ) {
						// import_image requires size to be set
						$image['size'] = $size;
						$new_image     = Templates::import_image( $image, true );

						if ( ! $new_image || ( is_array( $new_image ) && isset( $new_image['error'] ) ) ) {
							continue;
						}

						// Remove the 'size', 'filename' key (Not needed)
						unset( $new_image['size'] );
						unset( $new_image['filename'] );
						$new_images[] = $new_image;
					}

					$handled_images[] = [
						'elementId'   => $element_id,
						'elementName' => $element_name,
						'settingKey'  => $setting_key,
						'settings'    => [
							'images' => $new_images, // follow image-gallery format
							'size'   => $size, // follow image-gallery format
						]
					];
				}

				// Handle Image and SVG element (single image)
				else {
					$new_image = Templates::import_image( $settings, true );

					if ( ! $new_image || ( is_array( $new_image ) && isset( $new_image['error'] ) ) ) {
						continue;
					}

					$handled_images[] = [
						'elementId'   => $element_id,
						'elementName' => $element_name,
						'settingKey'  => $setting_key,
						'settings'    => $new_image,
					];
				}

			}
		}

		wp_send_json_success( $handled_images );
	}

	/**
	 * Parse content through dynamic data logic
	 *
	 * @since 1.5.1
	 */
	public function get_dynamic_data_preview_content() {
		self::verify_request( 'bricks-nonce-builder' );

		$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;
		$content = ! empty( $_POST['content'] ) ? wp_kses_post( $_POST['content'] ) : '';
		$context = ! empty( $_POST['context'] ) ? sanitize_text_field( $_POST['context'] ) : 'text';

		if ( ! $post_id ) {
			wp_send_json_error( 'Error: No post ID' );
		}

		if ( ! $content ) {
			wp_send_json_error( 'Error: No content' );
		}

		$pushed_woo_preview_contexts = self::push_woo_preview_context_from_request();

		// Use stripslashes to unescape img URLs, etc. (@since 1.7)
		if ( is_string( $content ) ) {
			$content = stripslashes( $content );
		}

		try {
			// STEP: Set up post data so WP core function like get_the_ID() work inside custom PHP functions called via DD 'echo:'
			global $post;

			$post = get_post( $post_id );

			setup_postdata( $post );

			// Get content from custom field
			if ( is_array( $content ) ) {
				// Array format used to parse colors in the builder (@since 1.5.1)
				foreach ( $content as $key => $data ) {
					$content[ $key ]['value'] = bricks_render_dynamic_data( $data['value'], $post_id, $context );
				}
			} else {
				// Preview composed links e.g. "https://my-domain.com/?user={wp_user_id}" (@since 1.5.4)
				if ( $context == 'link' && ( strpos( $content, '{' ) !== 0 || substr_count( $content, '}' ) > 1 ) ) {
					$context = 'text';
				}

				$content = bricks_render_dynamic_data( $content, $post_id, $context );
			}

			wp_reset_postdata();

			// If controlName is for video background, we handle it differently based on full url or video ID (@since 1.12.3)
			$control_name = ! empty( $_POST['controlName'] ) ? sanitize_text_field( $_POST['controlName'] ) : false;
			if ( $control_name === 'backgroundVideoUrl' ) {

				// Only escape URL if it's valid one. If we have a video ID, we don't escape it (so that we don't add http:// as prefix to video ID).
				if ( filter_var( trim( $content ), FILTER_VALIDATE_URL ) ) {
					$content = esc_url( $content );
				}
			}

			elseif ( 'link' === $context ) {
				$content = esc_url( $content );
			}

			// When output a code field, extract the content
			elseif ( is_string( $content ) && strpos( $content, '<pre' ) === 0 ) {
				preg_match( '#<\s*?code\b[^>]*>(.*?)</code\b[^>]*>#s', $content, $matches );
				$content = isset( $matches[1] ) ? $matches[1] : $content;

				// esc_html to escape code tags
				$content = esc_html( $content );
			}

			/**
			 * Run additional checks for non-basic text elements like removing extra <p> tags, etc.
			 *
			 * ContentEditable.js provides the element name.
			 *
			 * @since 1.7
			 */
			$element_name = ! empty( $_POST['elementName'] ) ? sanitize_text_field( $_POST['elementName'] ) : false;

			if ( $element_name && $element_name !== 'text-basic' ) {
				$content = Helpers::parse_editor_content( $content );
			}
		} finally {
			wp_reset_postdata();

			self::pop_woo_preview_context_from_request( $pushed_woo_preview_contexts );
		}

		// NOTE: We are not escaping text content since it could contain formatting tags like <strong> (@since 1.5.1 - preview dynamic data)
		wp_send_json_success( [ 'content' => $content ] );
	}

	/**
	 * Get latest remote templates data in builder (PopupTemplates.vue)
	 *
	 * @since 1.0
	 */
	public function get_remote_templates_data() {
		self::verify_request( 'bricks-nonce-builder' );

		$remote_templates = Templates::get_remote_templates_data();

		wp_send_json_success( $remote_templates );
	}

	/**
	 * Review a remote template, then localize its components after dependency selection.
	 *
	 * A 404/unsupported response is intentionally returned to the builder so it
	 * can continue with the unchanged legacy template payload.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function get_remote_template_package() {
		self::verify_request( 'bricks-nonce-builder' );

		$operation            = sanitize_key( wp_unslash( $_POST['operation'] ?? 'insert' ) ) === 'import' ? 'import' : 'insert';
		$operation_permission = $operation === 'import' ? 'import_export_templates' : 'insert_templates';

		if ( ! Builder_Permissions::user_has_permission( 'access_remote_templates' ) || ! Builder_Permissions::user_has_permission( $operation_permission ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ], 403 );
		}

		$source_id   = sanitize_text_field( wp_unslash( $_POST['sourceId'] ?? '' ) );
		$template_id = absint( $_POST['templateId'] ?? 0 );
		$source      = Remote_Library::get_source( $source_id );

		if ( is_wp_error( $source ) || ! $template_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Remote library source not found.', 'bricks' ) ], 404 );
		}

		$plan_token = sanitize_text_field( wp_unslash( $_POST['planToken'] ?? '' ) );
		$transient  = 'brx_remote_template_plan_' . get_current_user_id() . '_' . md5( $plan_token );
		$plan       = $plan_token ? get_transient( $transient ) : false;

		if ( $plan_token && ( ! is_array( $plan ) || $plan['sourceId'] !== $source_id || $plan['templateId'] !== $template_id || $plan['operation'] !== $operation ) ) {
			self::send_remote_library_error( new \WP_Error( 'remote_template_plan_expired', esc_html__( 'The import plan expired. Inspect the remote template again.', 'bricks' ), [ 'status' => 409 ] ) );
		}

		$package = $plan ? $plan['package'] : Remote_Library::fetch_template_package( $source_id, $template_id );

		if ( is_wp_error( $package ) ) {
			self::send_remote_library_error( $package );
		}

		if (
			! empty( $package['components'] ) &&
			(
				! Builder_Permissions::user_has_permission( 'access_remote_components' ) ||
				! Builder_Permissions::user_has_permission( 'import_export_components' ) ||
				( $operation === 'insert' && ! Builder_Permissions::user_has_permission( 'insert_components' ) )
			)
		) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed to import component dependencies.', 'bricks' ) ], 403 );
		}

		if ( ! $plan_token ) {
			$template = Remote_Component_Transfer::template_for_review( $package );
			if ( is_wp_error( $template ) ) {
				self::send_remote_library_error( $template );
			}

			$plan_token = wp_generate_password( 32, false, false );
			$transient  = 'brx_remote_template_plan_' . get_current_user_id() . '_' . md5( $plan_token );
			set_transient(
				$transient,
				[
					'sourceId'            => $source_id,
					'templateId'          => $template_id,
					'operation'           => $operation,
					'package'             => $package,
					'designSystemVersion' => Component_Repository::get_design_system_version(),
				],
				30 * MINUTE_IN_SECONDS
			);

			wp_send_json_success(
				[
					'template'  => $template,
					'planToken' => $plan_token,
				]
			);
		}

		$reviewed_class_map = json_decode( wp_unslash( $_POST['reviewedClassMap'] ?? '{}' ), true );
		if ( ! isset( $_POST['reviewedClassMap'] ) || ! is_array( $reviewed_class_map ) ) {
			self::send_remote_library_error( new \WP_Error( 'remote_template_invalid_review', esc_html__( 'Invalid import selection.', 'bricks' ), [ 'status' => 400 ] ) );
		}
		$reviewed_class_map = array_intersect_key( $reviewed_class_map, array_flip( array_column( $package['dependencies']['globalClasses'] ?? [], 'id' ) ) );
		$reviewed_class_map = array_filter(
			$reviewed_class_map,
			function( $id ) {
				return is_string( $id ) && $id !== '';
			}
		);

		$result = Remote_Component_Transfer::apply_template_package(
			$package,
			$source_id,
			$source['name'],
			[
				'conflictMode'                => sanitize_key( wp_unslash( $_POST['conflictMode'] ?? 'keep' ) ),
				'expectedDesignSystemVersion' => $plan['designSystemVersion'],
				'reviewedClassMap'            => $reviewed_class_map,
				'importImages'                => ! empty( $_POST['importImages'] ) && current_user_can( 'upload_files' ),
				'sourceUrl'                   => $source['url'],
			]
		);

		if ( is_wp_error( $result ) ) {
			self::send_remote_library_error( $result, true );
		}

		$plan['designSystemVersion'] = Component_Repository::get_design_system_version();
		set_transient( $transient, $plan, 30 * MINUTE_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * Get remote component summaries for the component manager.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function get_remote_components() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_remote_components' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ], 403 );
		}

		$source_id = sanitize_text_field( wp_unslash( $_POST['sourceId'] ?? '' ) );
		$args      = [
			'page'     => max( 1, (int) ( $_POST['page'] ?? 1 ) ),
			'per_page' => min( 100, max( 1, (int) ( $_POST['perPage'] ?? 50 ) ) ),
			'search'   => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
			'category' => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ),
		];
		$catalog   = Remote_Library::fetch_components_catalog( $source_id, $args, ! empty( $_POST['refresh'] ) );

		if ( is_wp_error( $catalog ) ) {
			self::send_remote_library_error( $catalog );
		}

		wp_send_json_success( $catalog );
	}

	/**
	 * Inspect a remote component package and cache an opaque import plan.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function inspect_remote_component() {
		self::verify_request( 'bricks-nonce-builder' );

		if (
			! Builder_Permissions::user_has_permission( 'access_remote_components' ) ||
			! Builder_Permissions::user_has_permission( 'import_export_components' )
		) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ], 403 );
		}

		$source_id    = sanitize_text_field( wp_unslash( $_POST['sourceId'] ?? '' ) );
		$component_id = sanitize_key( wp_unslash( $_POST['componentId'] ?? '' ) );
		$source       = Remote_Library::get_source( $source_id );

		if ( is_wp_error( $source ) ) {
			self::send_remote_library_error( $source );
		}

		$package = Remote_Library::fetch_component_package( $source_id, $component_id );

		if ( is_wp_error( $package ) ) {
			self::send_remote_library_error( $package );
		}

		$inspection = Remote_Component_Transfer::inspect( $package, $source_id, $source['name'] );

		if ( is_wp_error( $inspection ) ) {
			self::send_remote_library_error( $inspection );
		}

		$plan_token = wp_generate_password( 32, false, false );
		$transient  = self::remote_component_plan_transient( $plan_token );

		set_transient(
			$transient,
			[
				'userId'     => get_current_user_id(),
				'sourceId'   => $source_id,
				'sourceName' => $source['name'],
				'sourceUrl'  => $source['url'],
				'package'    => $package,
			],
			10 * MINUTE_IN_SECONDS
		);

		$inspection['planToken'] = $plan_token;

		wp_send_json_success( $inspection );
	}

	/**
	 * Apply a previously inspected remote component import plan.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function apply_remote_component() {
		self::verify_request( 'bricks-nonce-builder' );

		if (
			! Builder_Permissions::user_has_permission( 'access_remote_components' ) ||
			! Builder_Permissions::user_has_permission( 'import_export_components' )
		) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ], 403 );
		}

		$plan_token = sanitize_text_field( wp_unslash( $_POST['planToken'] ?? '' ) );
		$transient  = self::remote_component_plan_transient( $plan_token );
		$plan       = get_transient( $transient );

		if ( ! is_array( $plan ) || (int) ( $plan['userId'] ?? 0 ) !== get_current_user_id() ) {
			wp_send_json_error( [ 'message' => esc_html__( 'The remote component import plan expired. Inspect the component again.', 'bricks' ) ], 410 );
		}

		$result = Remote_Component_Transfer::apply(
			$plan['package'],
			$plan['sourceId'],
			$plan['sourceName'],
			[
				'conflictMode'                => sanitize_key( wp_unslash( $_POST['conflictMode'] ?? 'keep' ) ),
				'expectedDesignSystemVersion' => (int) ( $_POST['expectedDesignSystemVersion'] ?? 0 ),
				'importImages'                => ! empty( $_POST['importImages'] ) && current_user_can( 'upload_files' ),
				'label'                       => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
				'skippedDependencies'         => json_decode( wp_unslash( $_POST['skippedDependencies'] ?? '{}' ), true ),
				'sourceUrl'                   => $plan['sourceUrl'],
			]
		);

		if ( is_wp_error( $result ) ) {
			self::send_remote_library_error( $result, true );
		}

		delete_transient( $transient );

		wp_send_json_success( $result );
	}

	/**
	 * Build the user-scoped transient name for a remote component plan.
	 *
	 * @param string $plan_token Opaque plan token.
	 * @return string
	 */
	private static function remote_component_plan_transient( $plan_token ) {
		return 'brx_remote_component_plan_' . get_current_user_id() . '_' . md5( $plan_token );
	}

	/**
	 * Send a consistent AJAX response for a remote-library error.
	 *
	 * @param \WP_Error $error        Remote-library error.
	 * @param bool      $include_data Include structured error data.
	 * @return void
	 */
	private static function send_remote_library_error( $error, $include_data = false ) {
		$error_data = $error->get_error_data();
		$status     = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 400 ) : 400;
		$response   = [
			'message' => $error->get_error_message(),
			'code'    => $error->get_error_code(),
		];

		if ( $include_data ) {
			$response['data'] = $error_data;
		}

		wp_send_json_error( $response, $status );
	}

	/**
	 * Builder: Get "My templates" from db
	 *
	 * @since 1.4
	 */
	public function get_my_templates_data() {
		self::verify_request( 'bricks-nonce-builder' );

		wp_send_json_success(
			Templates::get_templates(
				[
					'post_status'           => 'any',
					'lang'                  => '', // Get all templates in builder for Polylang (@since 1.9.5)
					'remove_code_signature' => true, // Don't remove signature from local templates (@since 1.9.7)
				]
			)
		);
	}

	/**
	 * Public AJAX entry point to export unified global data.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function unified_global_export() {
		self::verify_unified_global_transfer_request();
		Unified_Global_Transfer::export_from_request();
	}

	/**
	 * Public AJAX entry point to inspect unified global import data.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function unified_global_import_inspect() {
		self::verify_unified_global_transfer_request();
		Unified_Global_Transfer::inspect_from_request();
	}

	/**
	 * Public AJAX entry point to apply unified global import data.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function unified_global_import_apply() {
		self::verify_unified_global_transfer_request();
		Unified_Global_Transfer::apply_from_request();
	}

	/**
	 * Public AJAX entry point to normalize Class Manager import files.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function import_global_classes_file() {
		self::verify_request( 'bricks-nonce-builder' );

		if (
			! Builder_Permissions::user_has_permission( 'access_class_manager' ) ||
			(
				! Builder_Permissions::user_has_permission( 'create_global_classes' ) &&
				! Builder_Permissions::user_has_permission( 'edit_global_classes' )
			)
		) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$classes = Unified_Global_Transfer::extract_classes_from_request();

		if ( is_wp_error( $classes ) ) {
			wp_send_json_error( [ 'message' => $classes->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'classes' => $classes,
			]
		);
	}

	/**
	 * Public AJAX entry point to normalize manager import files.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function import_global_transfer_file() {
		self::verify_request( 'bricks-nonce-builder' );

		$type = ! empty( $_POST['transferType'] ) ? sanitize_text_field( wp_unslash( $_POST['transferType'] ) ) : '';
		$data = Unified_Global_Transfer::extract_transfer_type_from_request( $type );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( [ 'message' => $data->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'data' => $data,
			]
		);
	}

	/**
	 * Verify unified transfer AJAX requests from builder or wp-admin.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function verify_unified_global_transfer_request() {
		if ( check_ajax_referer( 'bricks-nonce-builder', 'nonce', false ) ) {
			self::verify_request( 'bricks-nonce-builder' );
			return;
		}

		if ( check_ajax_referer( 'bricks-nonce-admin', 'nonce', false ) && current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
	}

	/**
	 * Get current user
	 *
	 * Verify logged-in user when builder is loaded on the frontend.
	 *
	 * @since 1.5
	 */
	public function get_current_user_id() {
		self::verify_request( 'bricks-nonce-builder' );

		wp_send_json_success( [ 'user_id' => get_current_user_id() ] );
	}


	/**
	 * Delete bricks query loop random seed transient
	 *
	 * @since 1.7.1
	 */
	public function query_loop_delete_random_seed_transient() {
		self::verify_request( 'bricks-nonce-builder' );

		$element_id = ! empty( $_POST['elementId'] ) ? sanitize_text_field( $_POST['elementId'] ) : false;

		if ( ! $element_id ) {
			wp_send_json_error( 'Error: No element ID' );
		}

		// @see Bricks\Query->set_bricks_query_loop_random_order_seed()
		$transient_name = "bricks_query_loop_random_seed_$element_id";

		delete_transient( $transient_name );

		wp_send_json_success();
	}

	/**
	 * Get custom shape divider (SVG) from attachment ID
	 *
	 * Only allow to select SVG files from the media library for security reasons.
	 *
	 * @since 1.8.6
	 */
	public function get_custom_shape_divider() {
		self::verify_request( 'bricks-nonce-builder' );

		$svg_path = ! empty( $_POST['id'] ) ? get_attached_file( intval( $_POST['id'] ) ) : false;
		$svg      = $svg_path ? Helpers::file_get_contents( $svg_path ) : false;

		wp_send_json_success( $svg );
	}

	public function restore_global_class() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! current_user_can( 'edit_posts' ) && ! Builder_Permissions::user_has_permission( 'access_class_manager' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$class_id = $_POST['class_id'] ?? null;

		if ( ! $class_id ) {
			wp_send_json_error( 'No class ID provided' );
		}

		$global_classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$trash          = get_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, [] );

		foreach ( $trash as $index => $class ) {
			if ( $class['id'] === $class_id ) {
				// Check for name conflicts
				$name = $class['name'];
				foreach ( $global_classes as $existing_class ) {
					if ( $existing_class['name'] === $name ) {
						$name = $name . '-restored';
						break;
					}
				}
				$class['name'] = $name;

				// Remove from trash and add to global classes
				unset( $trash[ $index ] );
				$global_classes[] = $class;
				break;
			}
		}

		update_option( BRICKS_DB_GLOBAL_CLASSES, $global_classes );
		update_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, array_values( $trash ) );

		wp_send_json_success();
	}

	/**
	 * Delete global classes permanently
	 *
	 * @since 1.11
	 */
	public function delete_global_classes_permanently() {
		// Verify nonce and user capabilities
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_class_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		// Get class IDs to delete
		$class_ids = $_POST['classIds'] ?? [];

		// If $class_ids is a string, try to decode it
		if ( is_string( $class_ids ) ) {
			$class_ids = self::decode( $class_ids, false );
		}

		if ( empty( $class_ids ) || ! is_array( $class_ids ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No classes specified for deletion', 'bricks' ) ] );
		}

		$delete_result = self::collect_deleted_items_from_collections(
			$class_ids,
			get_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, [] ),
			get_option( BRICKS_DB_GLOBAL_CLASSES, [] ),
			true
		);

		self::save_array_option( BRICKS_DB_GLOBAL_CLASSES_TRASH, $delete_result['trashed_items'] );
		self::save_array_option( BRICKS_DB_GLOBAL_CLASSES, $delete_result['active_items'] );

		if ( count( $delete_result['deleted_items'] ) ) {
			// Singular/plural message based on the number of classes deleted
			$deleted_count = count( $delete_result['deleted_items'] );
			// translators: %d: number of classes deleted
			$message = _n(
				'%d class permanently deleted',
				'%d classes permanently deleted',
				$deleted_count,
				'bricks'
			);
			$message = sprintf( $message, $deleted_count );

			wp_send_json_success(
				[
					'message'        => $message,
					'deletedClasses' => $delete_result['deleted_items']
				]
			);
		} else {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete classes', 'bricks' ) ] );
		}
	}

	/**
	 * Delete global variables permanently.
	 *
	 * @since 2.4
	 */
	public function delete_global_variables_permanently() {
		self::verify_request( 'bricks-nonce-builder' );

		if ( ! Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$variable_ids = $_POST['variableIds'] ?? [];

		if ( is_string( $variable_ids ) ) {
			$variable_ids = self::decode( $variable_ids, false );
		}

		if ( empty( $variable_ids ) || ! is_array( $variable_ids ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No variables specified for deletion', 'bricks' ) ] );
		}

		$delete_result = self::collect_deleted_items_from_collections(
			$variable_ids,
			Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES_TRASH, [] ),
			Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] ),
			true
		);

		Helpers::save_global_variables_array_option( BRICKS_DB_GLOBAL_VARIABLES_TRASH, $delete_result['trashed_items'] );
		Helpers::save_global_variables_in_db( $delete_result['active_items'] );
		self::generate_style_manager_css_file();

		if ( count( $delete_result['deleted_items'] ) ) {
			$deleted_count = count( $delete_result['deleted_items'] );
			// translators: %d: number of variables deleted
			$message = _n(
				'%d variable permanently deleted',
				'%d variables permanently deleted',
				$deleted_count,
				'bricks'
			);
			$message = sprintf( $message, $deleted_count );

			wp_send_json_success(
				[
					'message'          => $message,
					'deletedVariables' => $delete_result['deleted_items']
				]
			);
		}

		wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete variables', 'bricks' ) ] );
	}

	/**
	 * Used in the builder only
	 *
	 * - Force Query is_looping to true
	 * - Force Query loop_object
	 * - If the loop_object is WP_Post, setup the post data and save in global $post (Mimic the loop)
	 * - Caution: Never restore global $post in this function. Don't use this if unsure.
	 *
	 * @since 1.12.2
	 */
	public static function simulate_bricks_query( $loop_settings ) {
		$query = new Query( $loop_settings );

		// Include this structural parent in first-node checks, but do not inherit an active context from query history (#86caprbc5)
		$query->is_simulated_loop_context = true;
		$query->initial_loop_index        = null;
		$query->is_looping                = false;
		$query->loop_object               = null;

		// Query::count can describe all matching rows even when the current offset or page has no results.
		$query_results = $query->query_result;
		if ( $query->object_type === 'post' && is_object( $query_results ) && isset( $query_results->posts ) ) {
			$query_results = $query_results->posts;
		}

		$has_query_result = is_array( $query_results ) && count( $query_results ) > 0;

		if ( ! empty( $query->count ) && $has_query_result ) {
			$query->initial_loop_index = $query->loop_index;
			$query->is_looping         = true;

			// Use reset because not all provider result arrays are sequential (e.g. JetEngine)
			$query->loop_object = reset( $query_results );

			// Set global $post for the loop object
			if ( is_a( $query->loop_object, 'WP_Post' ) ) {
				global $post;
				$post = $query->loop_object;
				setup_postdata( $post );
			}
		}
	}

	/**
	 * Clean up orphaned elements across site
	 *
	 * @since 2.0
	 */
	public function cleanup_orphaned_elements() {
		self::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		// Find orphaned elements across the site
		$orphaned_data = Helpers::find_orphaned_elements_across_site();

		if ( empty( $orphaned_data['orphaned_by_post_id'] ) ) {
			wp_send_json_success(
				[
					'message'       => esc_html__( 'No orphaned elements found.', 'bricks' ),
					'total_cleaned' => 0,
					'posts_cleaned' => 0,
				]
			);
		}

		// Clean up orphaned elements
		$cleanup_result = Helpers::cleanup_orphaned_elements_across_site( $orphaned_data );

		if ( $cleanup_result['success'] ) {
			$message = sprintf(
				esc_html__( 'Successfully removed %1$d orphaned elements across %2$d posts.', 'bricks' ),
				$cleanup_result['total_cleaned'],
				$cleanup_result['posts_cleaned']
			);

			wp_send_json_success(
				[
					'message'       => $message,
					'total_cleaned' => $cleanup_result['total_cleaned'],
					'posts_cleaned' => $cleanup_result['posts_cleaned'],
				]
			);
		} else {
			wp_send_json_error( [ 'message' => esc_html__( 'Failed to clean up orphaned elements.', 'bricks' ) ] );
		}
	}

	/**
	 * Scan for orphaned elements across site
	 *
	 * @since 2.0
	 */
	public function scan_orphaned_elements() {
		self::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		// Scan for orphaned elements
		$scan_result = Helpers::find_orphaned_elements_across_site();

		// translators: %1$d: Number of orphaned elements found, %2$d: Number of posts scanned
		$message = sprintf(
			esc_html__( 'Scan complete: %1$d orphaned elements found across %2$d posts.', 'bricks' ),
			$scan_result['total_orphans'],
			$scan_result['total_posts']
		);

		wp_send_json_success(
			[
				'message'             => $message,
				'orphaned_by_post_id' => $scan_result['orphaned_by_post_id'],
				'total_orphans'       => $scan_result['total_orphans'],
				'total_posts'         => $scan_result['total_posts'],
			]
		);
	}

	/**
	 * Get builder data for instant navigation (page-specific data only)
	 *
	 * @since 2.2
	 */
	public function get_partial_builder_data() {
		self::verify_request( 'bricks-nonce-builder' );

		$post_id = isset( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No post ID provided.', 'bricks' ) ] );
		}

		if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No permission to edit this post.', 'bricks' ) ] );
		}

		// Reset active templates for instant navigation so template previews don't keep the previous page's content template. (#86ca6tka7; @since 2.4)
		Database::init_active_templates();

		// Set page data for builder_data() to work correctly
		Database::set_page_data( $post_id );

		// Set active templates for the new post
		Database::set_active_templates( $post_id );

		// Calculate active theme styles for the new post
		Theme_Styles::set_active_style( $post_id );
		$theme_style_active_ids = array_keys( Theme_Styles::$settings_by_id );
		$theme_style_active_id  = end( $theme_style_active_ids );

		$pushed_woo_preview_contexts = self::push_woo_preview_context_from_request();
		$response_data               = null;

		try {
			$data = Builder::partial_builder_data( $post_id );

			$data['dynamicWrapper'] = apply_filters( 'bricks/builder/dynamic_wrapper', [] ); // (#86c74y1xg; @since 2.4)

			$data['themeStyleActiveIds'] = $theme_style_active_ids;
			$data['themeStyleActiveId']  = $theme_style_active_id;

			// Add additional data that might be needed
			$data['postId']      = $post_id;
			$data['postStatus']  = get_post_status( $post_id );
			$data['postType']    = get_post_type( $post_id );
			$data['previewUrl']  = add_query_arg( 'bricks_preview', time(), get_the_permalink( $post_id ) );
			$data['editPostUrl'] = htmlspecialchars_decode( get_edit_post_link( $post_id ) );
			$data['builderUrl']  = Helpers::get_builder_edit_link( $post_id );
			$data['permalink']   = get_permalink( $post_id );

			// URL to edit header/content/footer templates
			$data['editHeaderUrl']  = ! empty( Database::$active_templates['header'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['header'] ) : '';
			$data['editContentUrl'] = ! empty( Database::$active_templates['content'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['content'] ) : '';
			$data['editFooterUrl']  = ! empty( Database::$active_templates['footer'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['footer'] ) : '';

			// Template IDs for instant navigation (@since 1.11)
			$data['editHeaderId']  = ! empty( Database::$active_templates['header'] ) ? Database::$active_templates['header'] : 0;
			$data['editContentId'] = ! empty( Database::$active_templates['content'] ) ? Database::$active_templates['content'] : 0;
			$data['editFooterId']  = ! empty( Database::$active_templates['footer'] ) ? Database::$active_templates['footer'] : 0;

			$data['pageTitle'] = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) );

			if ( $data['postType'] === BRICKS_DB_TEMPLATE_SLUG ) {
				$data['pageTitle'] .= ' (' . __( 'Template', 'bricks' ) . ')';
			} else {
				$data['pageTitle'] .= ' (' . __( 'Builder', 'bricks' ) . ')';
			}

			// Include template type for header/footer templates
			if ( $data['postType'] === BRICKS_DB_TEMPLATE_SLUG ) {
				$data['templateType'] = Templates::get_template_type( $post_id );
			} else {
				$data['templateType'] = 'content'; // Default for pages/posts
			}

			$response_data = $data;
		} finally {
			self::pop_woo_preview_context_from_request( $pushed_woo_preview_contexts );
		}

		wp_send_json_success( $response_data );
	}

	/**
	 * Query API
	 *
	 * @since 2.1
	 */
	public function query_api() {
		self::verify_request( 'bricks-nonce-builder' );

		$settings    = $_POST['settings'] ?? [];
		$element_id  = isset( $_POST['elementId'] ) && is_string( $_POST['elementId'] ) ? sanitize_text_field( $_POST['elementId'] ) : '';
		$post_id     = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : get_the_ID();
		$property_id = isset( $_POST['propertyId'] ) && is_string( $_POST['propertyId'] ) ? sanitize_text_field( $_POST['propertyId'] ) : '';
		$response    = Query_API::make_preview_request( $settings, $element_id, $post_id, $property_id );

		if ( isset( $response['error'] ) ) {
			wp_send_json_error(
				[
					'message'    => $response['error'],
					'status'     => $response['status'] ?? 500,
					'headers'    => $response['headers'] ?? null,
					'last_fetch' => $response['last_fetch'] ?? time(),
				]
			);
		}

		// Return both full response and extracted data
		wp_send_json_success(
			[
				'full_response'  => $response['full_response'] ?? null,   // Full API response
				'extracted_data' => $response['extracted_data'] ?? null, // Extracted data
				'response_path'  => $response['response_path'] ?? null,   // Path used
				'status'         => $response['status'] ?? 200,
				'headers'        => $response['headers'] ?? null,
				'last_fetch'     => $response['last_fetch'] ?? time(),
			]
		);

	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Components {
	public function __construct() {
		add_action( 'wp_ajax_bricks_upgrade_components', [ $this, 'upgrade_components' ] );
		add_action( 'wp_ajax_bricks_get_component_instances', [ $this, 'get_component_instances' ] );
		add_action( 'wp_ajax_bricks_get_component_preview_html', [ $this, 'get_component_preview_html' ] );
		add_action( 'wp_ajax_bricks_save_component_screenshot', [ $this, 'save_component_screenshot' ] );
		add_action( 'wp_ajax_bricks_delete_component_screenshots', [ $this, 'delete_component_screenshots' ] );
		add_action( 'update_option_' . BRICKS_DB_COMPONENTS, [ $this, 'cleanup_removed_component_screenshots' ], 10, 2 );
	}

	/**
	 * Upgrade components to latest data structure
	 *
	 * @since 1.12
	 */
	public static function upgrade_components( $components, $is_import = true ) {
		// Component in-builder (PanelElements.vue)
		if ( bricks_is_ajax_call() && isset( $_POST['action'] ) && $_POST['action'] === 'bricks_upgrade_components' ) {
			Ajax::verify_request( 'bricks-nonce-builder' );
			$components = $_POST['components'] ?? [];

			if ( is_string( $components ) ) {
				// JSON preserves property types; remove WordPress request slashes before decoding.
				$components = json_decode( wp_unslash( $components ), true );

				if ( ! is_array( $components ) ) {
					wp_send_json_error( [], 400 );
				}

				// Decoded JSON is already unslashed. Keep the legacy array import path unchanged.
				$is_import = false;
			}
		}

		foreach ( $components as $index => $component ) {
			/**
			 * STEP: Convert 1.12-beta components to 1.12 data structure
			 *
			 * Move root component element (incl. name, settings, children, label) from component object to elements array.
			 */
			if ( isset( $component['name'] ) ) {
				$component_root_element = [
					'id'       => $component['id'],
					'name'     => $component['name'],
					'settings' => $component['settings'] ?? [],
					'children' => $component['children'] ?? [],
					'parent'   => 0,
				];

				// Move component label to root element
				if ( ! empty( $component['label'] ) ) {
					$component_root_element['label'] = $component['label'];
				}

				// Add root element as first item to elements array
				if ( isset( $components[ $index ]['elements'] ) && is_array( $components[ $index ]['elements'] ) ) {
					array_unshift( $components[ $index ]['elements'], $component_root_element );
				} else {
					$components[ $index ]['elements'] = [ $component_root_element ];
				}

				// Remove root level properties
				unset( $components[ $index ]['name'] );
				unset( $components[ $index ]['settings'] );
				unset( $components[ $index ]['children'] );
			}

			// Remove backslashes from component settings if this is import action (i.e. Code element; @since 2.0)
			$components[ $index ] = $is_import ? stripslashes_deep( $components[ $index ] ) : $components[ $index ];
		}

		// Return: Components in-builder import (PanelElements.vue)
		if ( bricks_is_ajax_call() && isset( $_POST['action'] ) && $_POST['action'] === 'bricks_upgrade_components' ) {
			wp_send_json_success(
				[
					'newComponents' => $components,
				]
			);
		}

		// Return upgraded components
		return $components;
	}

	public function get_component_instances() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		// Get component IDS from real-time builder data to account for deleted components, etc.
		$component_ids   = $_POST['componentIds'] ?? [];
		$current_post_id = $_POST['postId'] ?? 0;

		// Loop over all Bricks-enabled post types to get elements with 'cid' key
		$instances = [];

		// Include Gutenberg-only posts without Bricks content metadata.
		$bricks_post_ids = Helpers::get_all_bricks_post_ids( [ 'meta_query' => [] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Removes the metadata constraint to include Gutenberg-only posts.
		$template_ids    = Templates::get_all_template_ids();
		$post_ids        = array_unique( array_merge( $bricks_post_ids, $template_ids ) );

		foreach ( $post_ids as $post_id ) {
			// Usage includes Builder destinations, so authorize each target before reading its data.
			if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
				continue;
			}

			$type = 'content';

			// Get template type (header, footer, etc.) for templates
			if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				$type = Templates::get_template_type( $post_id );
			}

			// The current post's Bricks elements are counted live by the Builder.
			$bricks_data = (int) $post_id === (int) $current_post_id ? [] : Database::get_data( $post_id, $type );
			$bricks_data = array_merge( $bricks_data, Helpers::get_component_block_data( $post_id ) );

			// Expand component data to include nested components (@since 2.1)
			$bricks_data = Database::get_component_data( $bricks_data );

			// Stringify the data to search for all 'cid' appearances
			$bricks_data_json = wp_json_encode( $bricks_data );

			// Find all 'cid' keys in the data
			preg_match_all( '/"cid":"(.*?)"/', $bricks_data_json, $matches );

			// Loop over all matches and add them to the $instances array
			foreach ( $matches[1] as $cid ) {
				// Skip if the component ID is not in the list of component IDs
				if ( ! in_array( $cid, $component_ids, true ) ) {
					continue;
				}

				if ( empty( $instances[ $cid ][ $post_id ] ) ) {
					$post_title       = get_the_title( $post_id );
					$post_type        = get_post_type( $post_id );
					$post_type_object = get_post_type_object( $post_type );

					$instances[ $cid ][ $post_id ] = [
						'count'      => 1,
						'post_title' => $post_title,
						'post_type'  => $post_type_object->labels->singular_name ?? $post_type,
						'permalink'  => Helpers::get_builder_edit_link( $post_id ),
					];
				} else {
					$instances[ $cid ][ $post_id ]['count']++;
				}
			}
		}

		wp_send_json_success( $instances );
	}

	/**
	 * Return a standalone HTML document for a component screenshot preview.
	 *
	 * @since 2.4
	 */
	public function get_component_preview_html() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		if ( ! Database::get_setting( 'generateComponentScreenshots', false ) ) {
			wp_send_json_error( esc_html__( 'Component screenshot generation is disabled.', 'bricks' ) );
		}

		if ( ! Builder_Permissions::user_has_permission( 'edit_components' ) ) {
			wp_send_json_error( esc_html__( 'No permission.', 'bricks' ) );
		}

		$component_id = ! empty( $_POST['componentId'] ) ? sanitize_text_field( wp_unslash( $_POST['componentId'] ) ) : '';
		$post_id      = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $component_id ) {
			wp_send_json_error( esc_html__( 'No component ID provided.', 'bricks' ) );
		}

		if ( ! $post_id || ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( esc_html__( 'No permission.', 'bricks' ) );
		}

		$preview = $this->render_component_preview( $component_id, $post_id );

		if ( empty( $preview['component_html'] ) ) {
			wp_send_json_error( esc_html__( 'Component preview could not be generated.', 'bricks' ) );
		}

		wp_send_json_success(
			[
				'html'            => $this->get_component_preview_document( $preview['component_html'], $preview['styles_html'] ?? '' ),
				'captureSelector' => 'bricks-component-preview',
			]
		);
	}

	/**
	 * Save generated component screenshot and persist its metadata.
	 *
	 * @since 2.4
	 */
	public function save_component_screenshot() {
		Ajax::verify_request( 'bricks-nonce-builder' );

		if ( ! Database::get_setting( 'generateComponentScreenshots', false ) ) {
			wp_send_json_error( esc_html__( 'Component screenshot generation is disabled.', 'bricks' ) );
		}

		if ( ! Builder_Permissions::user_has_permission( 'edit_components' ) ) {
			wp_send_json_error( esc_html__( 'No permission.', 'bricks' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( esc_html__( 'User does not have permission to upload files.', 'bricks' ) );
		}

		$component_id = ! empty( $_POST['componentId'] ) ? sanitize_text_field( wp_unslash( $_POST['componentId'] ) ) : '';
		$post_id      = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : 0;

		if ( ! $component_id ) {
			wp_send_json_error( esc_html__( 'No component ID provided.', 'bricks' ) );
		}

		if ( ! Helpers::get_component_by_cid( $component_id ) ) {
			wp_send_json_error( esc_html__( 'Component not found.', 'bricks' ) );
		}

		if ( ! $post_id || ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			wp_send_json_error( esc_html__( 'No permission.', 'bricks' ) );
		}

		if ( ! isset( $_FILES['screenshot'] ) || empty( $_FILES['screenshot'] ) ) {
			wp_send_json_error( esc_html__( 'No screenshot file provided.', 'bricks' ) );
		}

		$screenshot_file = $_FILES['screenshot'];

		if ( $screenshot_file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( esc_html__( 'File upload failed.', 'bricks' ) );
		}

		$max_size = 5 * 1024 * 1024;
		if ( $screenshot_file['size'] > $max_size ) {
			wp_send_json_error( esc_html__( 'File size exceeds the maximum limit.', 'bricks' ) );
		}

		$finfo         = new \finfo( FILEINFO_MIME_TYPE );
		$mime_type     = $finfo->file( $screenshot_file['tmp_name'] );
		$allowed_types = [ 'image/webp', 'image/png' ];

		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			wp_send_json_error( esc_html__( 'Invalid screenshot file type.', 'bricks' ) );
		}

		$image_type = exif_imagetype( $screenshot_file['tmp_name'] );
		if ( ! in_array( $image_type, [ IMAGETYPE_WEBP, IMAGETYPE_PNG ], true ) ) {
			wp_send_json_error( esc_html__( 'Invalid image type detected.', 'bricks' ) );
		}

		$custom_dir = $this->get_component_screenshots_dir();

		if ( ! file_exists( $custom_dir ) && ! wp_mkdir_p( $custom_dir ) ) {
			wp_send_json_error( esc_html__( 'Failed to create upload directory.', 'bricks' ) );
		}

		if ( ! is_writable( $custom_dir ) ) {
			wp_send_json_error( esc_html__( 'Upload directory is not writable.', 'bricks' ) );
		}

		$this->delete_component_screenshot_files( $component_id );

		$timestamp          = time();
		$extension          = $mime_type === 'image/webp' ? 'webp' : 'png';
		$component_file_key = sanitize_file_name( $component_id );
		$filename           = "component-screenshot-{$component_file_key}-{$timestamp}.{$extension}";
		$filepath           = trailingslashit( $custom_dir ) . $filename;

		if ( ! move_uploaded_file( $screenshot_file['tmp_name'], $filepath ) ) {
			wp_send_json_error( esc_html__( 'Error saving screenshot.', 'bricks' ) );
		}

		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			wp_send_json_error( esc_html__( 'Failed to save or read the uploaded file.', 'bricks' ) );
		}

		$wp_upload_dir = wp_upload_dir();
		$file_url      = str_replace( $wp_upload_dir['basedir'], $wp_upload_dir['baseurl'], $filepath );
		$file_url      = esc_url( $file_url );

		$this->update_component_thumbnail_metadata( $component_id, $file_url, $timestamp );

		wp_send_json_success(
			[
				'file_url' => $file_url,
				'updated'  => $timestamp,
			]
		);
	}

	/**
	 * Delete screenshot files for components removed from the option store.
	 *
	 * @since 2.4
	 *
	 * @param array $old_components Previous option value.
	 * @param array $new_components Current option value.
	 */
	public function cleanup_removed_component_screenshots( $old_components, $new_components ) {
		$old_components = is_array( $old_components ) ? $old_components : [];
		$new_components = is_array( $new_components ) ? $new_components : [];

		$old_ids = array_filter(
			array_map(
				static function( $component ) {
					return $component['id'] ?? '';
				},
				$old_components
			)
		);

		$new_ids = array_filter(
			array_map(
				static function( $component ) {
					return $component['id'] ?? '';
				},
				$new_components
			)
		);

		foreach ( array_diff( $old_ids, $new_ids ) as $component_id ) {
			$this->delete_component_screenshot_files( $component_id );
		}
	}

	/**
	 * Delete all component screenshots and their stored thumbnail metadata.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function delete_component_screenshots() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Not allowed', 'bricks' ) ] );
		}

		$custom_dir     = $this->get_component_screenshots_dir();
		$existing_files = glob( $custom_dir . 'component-screenshot-*' );

		foreach ( is_array( $existing_files ) ? $existing_files : [] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		if ( is_dir( $custom_dir ) ) {
			$remaining_files = scandir( $custom_dir );

			if ( is_array( $remaining_files ) && count( $remaining_files ) === 2 ) {
				rmdir( $custom_dir );
			}
		}

		$components = get_option( BRICKS_DB_COMPONENTS, [] );

		foreach ( is_array( $components ) ? $components : [] as $index => $component ) {
			unset( $components[ $index ]['thumbnail'], $components[ $index ]['thumbnailUpdated'] );
		}

		update_option( BRICKS_DB_COMPONENTS, $components );

		wp_send_json_success( [ 'message' => esc_html__( 'Component screenshots deleted', 'bricks' ) ] );
	}

	/**
	 * Render a component preview and collect its inline styles.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @param int    $post_id      Active builder post ID.
	 * @return array
	 */
	private function render_component_preview( $component_id, $post_id ) {
		$component = Helpers::get_component_by_cid( $component_id );

		if ( empty( $component['elements'] ) ) {
			return [];
		}

		Database::set_page_data( $post_id );
		Theme_Styles::load_set_styles( $post_id );

		$main_element = null;

		foreach ( $component['elements'] as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $component_id ) {
				$main_element = $element;
				break;
			}
		}

		if ( ! $main_element || empty( $main_element['name'] ) ) {
			return [];
		}

		$element_id         = "{$component_id}-preview";
		$component_element  = [
			'id'         => $element_id,
			'name'       => $main_element['name'],
			'cid'        => $component_id,
			'properties' => [],
		];
		$component_css_key  = "component_{$component_id}";
		$preview_post_id    = Database::$page_data['preview_or_post_id'] ?? $post_id;
		$component_instance = Helpers::get_component_instance( $component_element );
		$all_elements       = [];

		Assets::generate_css_from_elements( [ $component_element ], $component_css_key );

		if ( ! empty( $component_instance ) ) {
			Helpers::get_component_elements_recursive( $component_instance, $all_elements );
		} else {
			$all_elements = [ $component_element ];
		}

		Assets::enqueue_setting_specific_scripts( $all_elements );

		$component_css = Assets::$inline_css[ $component_css_key ] ?? '';
		$styles_html   = $this->get_component_preview_styles_html( $component_id, $component_css, $post_id );

		global $post;

		$original_post = $post;
		$post          = get_post( $preview_post_id ? $preview_post_id : $post_id );

		if ( $post ) {
			setup_postdata( $post );
		}

		Frontend::$elements[ $element_id ] = $component_element;

		$component_html = Frontend::render_element( $component_element );

		unset( Frontend::$elements[ $element_id ] );

		if ( $original_post ) {
			$post = $original_post;
			setup_postdata( $post );
		} else {
			wp_reset_postdata();
		}

		return [
			'component_html' => $component_html,
			'styles_html'    => $styles_html,
		];
	}

	/**
	 * Build the standalone component preview HTML document.
	 *
	 * @since 2.4
	 *
	 * @param string $component_html Rendered component markup.
	 * @param string $styles_html    Preview styles and stylesheets.
	 * @return string
	 */
	private function get_component_preview_document( $component_html, $styles_html ) {
		$charset = get_bloginfo( 'charset' );

		return '<!doctype html><html><head><meta charset="' . esc_attr( $charset ) . '"><meta name="viewport" content="width=device-width, initial-scale=1">' . $styles_html . '<style id="bricks-component-preview-layout">html,body{margin:0;padding:0;background:#fff;}body.bricks-is-frontend{padding:24px;}.brx-component-preview-shell{display:inline-block;max-width:100%;}.brx-component-preview-shell > *{max-width:100%;}</style></head><body class="brx-body bricks-is-frontend wp-embed-responsive"><div id="bricks-component-preview" class="brx-component-preview-shell">' . $component_html . '</div></body></html>';
	}

	/**
	 * Build styles and stylesheet links required by the component preview document.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id  Component ID.
	 * @param string $component_css Component inline CSS.
	 * @param int    $post_id       Active builder post ID.
	 * @return string
	 */
	private function get_component_preview_styles_html( $component_id, $component_css, $post_id ) {
		Assets::set_assets_directory();

		$styles_html  = $this->get_component_preview_stylesheets_html();
		$styles_html .= $this->get_component_preview_dependency_stylesheets_html();
		$global_css   = $this->get_component_preview_inline_css( $post_id );
		$preview_css  = $global_css . $component_css;

		if ( $preview_css ) {
			$styles_html .= Assets::load_webfonts( $preview_css, true );
			$styles_html .= '<style id="bricks-inline-css-component-preview-' . esc_attr( $component_id ) . '">' . $preview_css . '</style>';
		}

		return $styles_html;
	}

	/**
	 * Return inline CSS required by the component preview document.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Active builder post ID.
	 * @return string
	 */
	private function get_component_preview_inline_css( $post_id ) {
		$global_css = '';

		$global_classes_css = Assets::generate_global_classes();
		if ( $global_classes_css ) {
			$global_css .= "\n/* Global Classes */\n" . $global_classes_css;
		}

		$global_variables = Assets::get_global_variables();
		if ( $global_variables ) {
			$variables_css = Assets::format_variables_as_css( $global_variables );
			if ( $variables_css ) {
				$global_css .= "\n/* Global Variables */\n" . $variables_css;
			}
		}

		$global_colors = Assets::generate_inline_css_color_vars( Database::$global_data['colorPalette'] ?? [] );
		if ( $global_colors ) {
			$global_css .= "\n/* Global Colors */\n" . $global_colors;
		}

		Theme_Styles::load_set_styles( $post_id );

		if ( ! empty( Theme_Styles::$settings_by_id ) ) {
			$theme_style_css = '';

			foreach ( Theme_Styles::$settings_by_id as $settings ) {
				$theme_style_css .= Assets::generate_inline_css_theme_style( $settings );
			}

			if ( $theme_style_css ) {
				$global_css .= "\n/* Theme Styles */\n" . $theme_style_css;
			}
		}

		return $global_css;
	}

	/**
	 * Return stylesheet links required by the component preview document.
	 *
	 * @since 2.4
	 * @return string
	 */
	private function get_component_preview_stylesheets_html() {
		$stylesheets  = [];
		$frontend_css = Database::get_setting( 'cssLoading' ) === 'file'
			? ( Database::get_setting( 'disableBricksCascadeLayer' ) ? 'frontend-light.min.css' : 'frontend-light-layer.min.css' )
			: ( Database::get_setting( 'disableBricksCascadeLayer' ) ? 'frontend.min.css' : 'frontend-layer.min.css' );

		$stylesheets[] = [
			'file' => BRICKS_PATH_ASSETS . 'css/' . $frontend_css,
			'url'  => BRICKS_URL_ASSETS . 'css/' . $frontend_css,
		];

		$style_manager_file = Assets::$css_dir . '/style-manager.min.css';
		if ( file_exists( $style_manager_file ) ) {
			$stylesheets[] = [
				'file' => $style_manager_file,
				'url'  => Assets::$css_url . '/style-manager.min.css',
			];
		}

		$global_elements_file = Assets::$css_dir . '/global-elements.min.css';
		if ( file_exists( $global_elements_file ) ) {
			$stylesheets[] = [
				'file' => $global_elements_file,
				'url'  => Assets::$css_url . '/global-elements.min.css',
			];
		}

		$html = '';

		foreach ( $stylesheets as $stylesheet ) {
			if ( empty( $stylesheet['file'] ) || ! file_exists( $stylesheet['file'] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Screenshot preview HTML is generated outside the normal enqueue lifecycle.
			$html .= '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', filemtime( $stylesheet['file'] ), $stylesheet['url'] ) ) . '">';
		}

		return $html;
	}

	/**
	 * Return dependency stylesheet links enqueued for the component preview.
	 *
	 * @since 2.4
	 * @return string
	 */
	private function get_component_preview_dependency_stylesheets_html() {
		$wp_styles = wp_styles();

		if ( ! $wp_styles instanceof \WP_Styles || empty( $wp_styles->queue ) ) {
			return '';
		}

		$html = '';

		foreach ( $wp_styles->queue as $handle ) {
			if ( in_array( $handle, [ 'bricks-frontend', 'bricks-style-manager', 'bricks-global-elements' ], true ) ) {
				continue;
			}

			$stylesheet = $wp_styles->registered[ $handle ] ?? null;

			if ( ! $stylesheet || empty( $stylesheet->src ) ) {
				continue;
			}

			$href = $stylesheet->src;

			if ( strpos( $href, '//' ) !== 0 && ! preg_match( '#^https?://#', $href ) ) {
				$href = $wp_styles->base_url . $href;
			}

			if ( ! empty( $stylesheet->ver ) ) {
				$href = add_query_arg( 'ver', $stylesheet->ver, $href );
			}

			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Screenshot preview HTML is generated outside the normal enqueue lifecycle.
			$html .= '<link rel="stylesheet" href="' . esc_url( $href ) . '">';
		}

		return $html;
	}

	/**
	 * Return the component screenshots directory path.
	 *
	 * @since 2.4
	 * @return string
	 */
	private function get_component_screenshots_dir() {
		$wp_upload_dir = wp_upload_dir();

		return trailingslashit( $wp_upload_dir['basedir'] ) . trailingslashit( BRICKS_COMPONENT_SCREENSHOTS_DIR );
	}

	/**
	 * Delete all existing screenshot files for the provided component ID.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @return void
	 */
	private function delete_component_screenshot_files( $component_id ) {
		$component_file_key = sanitize_file_name( $component_id );
		$custom_dir         = $this->get_component_screenshots_dir();
		$existing_files     = glob( trailingslashit( $custom_dir ) . "component-screenshot-{$component_file_key}-*" );

		if ( empty( $existing_files ) ) {
			return;
		}

		foreach ( $existing_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Persist thumbnail metadata for a component.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @param string $file_url     Screenshot URL.
	 * @param int    $timestamp    Screenshot timestamp.
	 * @return void
	 */
	private function update_component_thumbnail_metadata( $component_id, $file_url, $timestamp ) {
		$components = get_option( BRICKS_DB_COMPONENTS, [] );

		if ( ! is_array( $components ) ) {
			return;
		}

		foreach ( $components as $index => $component ) {
			if ( empty( $component['id'] ) || $component['id'] !== $component_id ) {
				continue;
			}

			$components[ $index ]['thumbnail']        = $file_url;
			$components[ $index ]['thumbnailUpdated'] = $timestamp;
			break;
		}

		update_option( BRICKS_DB_COMPONENTS, $components );
	}
}

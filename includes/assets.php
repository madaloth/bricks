<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Assets {
	public static $wp_uploads_dir = '';
	public static $css_dir        = '';
	public static $css_url        = '';

	public static $google_fonts_urls   = [];
	public static $global_colors       = [];
	public static $db_colors_generated = false; // (@since 2.1)

	private static $google_fonts_by_family           = null;
	private static $google_font_families_by_css      = [];
	private static $google_fonts_preconnect_returned = false;

	public static $inline_css = [
		'color_vars'       => '',
		'theme_style'      => '',
		'global'           => '',
		'global_classes'   => '',
		'global_variables' => '',
		'utility_classes'  => '',
		'page'             => '',
		'template'         => '',
		'header'           => '',
		'content'          => '',
		'footer'           => '',
		'popup'            => '',
	];

	public static $elements = [];

	// Popup settings have no external stylesheet; retain them separately from element CSS.
	public static $popup_settings_css = '';

	// Set by Assets_Files::generate_post_css_file() method during AJAX
	public static $post_id = 0;

	/**
	 * Store inline CSS per css_type (content, theme_style, etc.) & breakpoint
	 *
	 * key: css_type
	 * subkeys: breakpoints
	 * sub-subkeys: css selector
	 */
	public static $inline_css_breakpoints = [];

	public static $global_classes_elements = [];

	// Item = Individual unique CSS rules - avoid inline style duplicates (@since 1.8)
	public static $unique_inline_css = [];

	/**
	 * Last emitted rule groups, keyed by component selector and shared/icon origin.
	 *
	 * Each entry stores ordered rules and their original full CSS source. Compare
	 * only the last group so changed styles can be emitted again in cascade order.
	 * Reset with the public duplication tracker for each external CSS file.
	 *
	 * @since 2.4.3
	 * @var array
	 */
	private static $component_css_groups = [];

	// Dynamic data CSS string (e.g. dynamic data 'featured_image' set in single post template, etc.)
	public static $inline_css_dynamic_data = '';

	// Stores the post_id values for all the templates and pages where we need to fetch the page settings values
	public static $page_settings_post_ids = [];

	// Keep track of the elements inside of a loop that were already styled - avoid duplicates (@since 1.5)
	public static $css_looping_elements = [];

	// Template/loop combinations already included in the current CSS generation.
	public static $templates_css = [];

	// Keep track the common selectors inside of a loop that were already styled - avoid duplicates (@since 1.8)
	public static $generated_loop_common_selectors = [];

	// Keep track the common selectors inside of a loop that were already styled for each breakpoint
	public static $generated_loop_common_selectors_breakpoints = [];

	// Keep track of the current element that is being styled (@since 1.8)
	public static $current_generating_element = null;

	// Keep track of element IDs that will add data-loop-index attribute (@since 1.8)
	public static $loop_index_elements = [];

	public function __construct() {
		self::set_assets_directory();

		// The settings UI can regenerate files before the "External files" selection is saved.
		if ( Ajax::is_current_endpoint( 'get_css_files_list' ) || Ajax::is_current_endpoint( 'regenerate_css_file' ) ) {
			Database::$global_settings['cssLoading'] = 'file';
		}

		// "CSS loading method" set to 'file'
		if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
			self::autoload_files();
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_setting_specific_scripts' ] );

		// Frontend: Enqueue Style Manager CSS file for external CSS loading (@since 2.4)
		if ( ! bricks_is_builder() && Database::get_setting( 'cssLoading' ) === 'file' ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style_manager_css' ] );
		}

		// Enqueue RTL CSS file after main CSS file (@since 2.0)
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_rtl_specific_scripts' ], 15 );

		add_action( 'switch_blog', [ $this, 'set_assets_directory' ] );

		// Check if cascade layer is enabled to wrap  WordPress generated styles (@since 2.0)
		if ( ! Database::get_setting( 'disableBricksCascadeLayer' ) ) {
			add_filter( 'style_loader_tag', [ $this, 'wrap_mediaelement_styles_in_cascade_layer' ], 10, 2 ); // Mediaelement styles
			add_filter( 'style_loader_tag', [ $this, 'wrap_select2_styles_in_cascade_layer' ], 10, 2 ); // Select2 styles
		}
	}

	/**
	 * Enqueue Style Manager CSS file
	 *
	 * Contains color palette variables, utility classes, and global variables with scale property.
	 *
	 * @since 2.2
	 */
	public function enqueue_style_manager_css() {
		$css_file_path = self::$css_dir . '/style-manager.min.css';
		$css_file_url  = self::$css_url . '/style-manager.min.css';

		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'bricks-style-manager',
				$css_file_url,
				[],
				filemtime( $css_file_path )
			);
		}
	}

	/**
	 * Generate utility classes CSS from Style Manager data.
	 *
	 * Used inline for "Inline styles" CSS loading and in style-manager.min.css for "External files".
	 *
	 * @param string $format Return format: 'string' or 'map'.
	 *
	 * @return string|array
	 *
	 * @since 2.4
	 */
	public static function generate_style_manager_utility_classes_css( $format = 'string' ) {
		$color_palettes              = get_option( BRICKS_DB_COLOR_PALETTE, [] );
		$global_variables_categories = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$css_content                 = '';
		$css_map                     = [];
		$global_class_name_map       = [];
		$is_map                      = $format === 'map';
		$utility_config              = [
			'bg'      => [
				'property' => 'background-color',
				'prefix'   => 'bg'
			],
			'text'    => [
				'property' => 'color',
				'prefix'   => 'text'
			],
			'border'  => [
				'property' => 'border-color',
				'prefix'   => 'border'
			],
			'outline' => [
				'property' => 'outline-color',
				'prefix'   => 'outline'
			],
			'fill'    => [
				'property' => 'fill',
				'prefix'   => 'fill'
			],
			'stroke'  => [
				'property' => 'stroke',
				'prefix'   => 'stroke'
			],
		];

		// Build a class-name lookup so utility CSS can be keyed by global class ID for ordered output (@since 2.4)
		if ( $is_map ) {
			$global_classes = Database::$global_data['globalClasses'] ?? get_option( BRICKS_DB_GLOBAL_CLASSES, [] );

			if ( ! is_array( $global_classes ) ) {
				$global_classes = [];
			}

			foreach ( $global_classes as $global_class ) {
				if ( empty( $global_class['id'] ) ) {
					continue;
				}

				if ( ! empty( $global_class['name'] ) ) {
					$global_class_name_map[ $global_class['name'] ] = $global_class;
				}
			}
		}

		// STEP: Generate utility classes for color palettes.
		if ( is_array( $color_palettes ) && ! empty( $color_palettes ) ) {
			foreach ( $color_palettes as $palette ) {
				if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
					continue;
				}

				$root_colors = array_filter(
					$palette['colors'],
					function( $color ) {
						return empty( $color['type'] ) && ! empty( $color['light'] );
					}
				);

				foreach ( $root_colors as $root_color ) {
					$shades = array_filter(
						$palette['colors'],
						function( $color ) use ( $root_color ) {
							return ! empty( $color['light'] ) && ! empty( $color['type'] ) && ! empty( $color['parent'] ) && $color['parent'] === $root_color['id'];
						}
					);

					$all_colors        = array_merge( [ $root_color ], $shades );
					$enabled_utilities = ! empty( $root_color['utilityClasses'] ) && is_array( $root_color['utilityClasses'] ) ? $root_color['utilityClasses'] : [];

					if ( empty( $enabled_utilities ) ) {
						continue;
					}

					if ( ! $is_map && ! empty( $root_color['raw'] ) ) {
						$css_content .= "/* Utility classes for {$root_color['raw']} */\n\n";
					}

					foreach ( $enabled_utilities as $utility_key ) {
						if ( ! isset( $utility_config[ $utility_key ] ) ) {
							continue;
						}

						$property = $utility_config[ $utility_key ]['property'];
						$prefix   = $utility_config[ $utility_key ]['prefix'];

						foreach ( $all_colors as $color ) {
							if ( empty( $color['raw'] ) || ( $is_map && empty( $root_color['id'] ) ) ) {
								continue;
							}

							$var_name   = Helpers::extract_name_from_css_variable( $color['raw'] );
							$var_name   = str_replace( '--', '', $var_name );
							$class_name = "{$prefix}-{$var_name}";

							if ( $is_map ) {
								$class_id = "brxuc_color_{$root_color['id']}_{$utility_key}";

								if ( ! empty( $color['type'] ) ) {
									$class_id .= '_' . substr( $color['type'], 0, 1 ) . '_' . ( $color['index'] ?? '' );
								}

								$class_id             = $global_class_name_map[ $class_name ]['id'] ?? $class_id;
								$css_map[ $class_id ] = ".{$class_name} { {$property}: {$color['raw']}; }\n";
							} else {
								$css_content .= ".{$class_name} { {$property}: {$color['raw']}; }\n";
							}
						}
					}

					if ( ! $is_map ) {
						$css_content .= "\n";
					}
				}
			}
		}

		// STEP: Generate utility classes for global variables categories with scale property.
		if ( is_array( $global_variables_categories ) && ! empty( $global_variables_categories ) ) {
			$global_variables = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

			foreach ( $global_variables_categories as $category ) {
				if ( empty( $category['scale'] ) || ! is_array( $category['scale'] ) ) {
					continue;
				}

				if ( empty( $category['utilityClasses'] ) || ! is_array( $category['utilityClasses'] ) ) {
					continue;
				}

				$category_variables = array_filter(
					$global_variables,
					function( $variable ) use ( $category ) {
						return ! empty( $variable['category'] ) && $variable['category'] === $category['id'];
					}
				);

				if ( empty( $category_variables ) ) {
					continue;
				}

				if ( ! $is_map && ! empty( $category['name'] ) ) {
					$css_content .= "/* Utility classes for {$category['name']} */\n\n";
				}

				$utility_classes = $category['utilityClasses'];
				$prefix          = $category['scale']['prefix'] ?? '';

				foreach ( $utility_classes as $utility_class ) {
					if ( empty( $utility_class['className'] ) || empty( $utility_class['cssProperty'] ) ) {
						continue;
					}

					$class_name_pattern = $utility_class['className'];
					$css_property       = $utility_class['cssProperty'];

					foreach ( $category_variables as $variable ) {
						if ( empty( $variable['name'] ) ) {
							continue;
						}

						$scale_name = str_replace( $prefix, '', $variable['name'] );

						if ( strpos( $class_name_pattern, '*' ) !== false ) {
							$class_name = str_replace( '*', $scale_name, $class_name_pattern );
						} else {
							$class_name = $class_name_pattern . $scale_name;
						}

						$var_reference = Helpers::extract_name_from_css_variable( $variable['name'] );

						if ( $is_map ) {
							$utility_class_id     = $utility_class['id'] ?? '';
							$class_id             = $global_class_name_map[ $class_name ]['id'] ?? "brxuc_scale_{$category['id']}_{$utility_class_id}_{$scale_name}";
							$css_map[ $class_id ] = ".{$class_name} { {$css_property}: var({$var_reference}); }\n";
						} else {
							$css_content .= ".{$class_name} { {$css_property}: var({$var_reference}); }\n";
						}
					}
				}

				if ( ! $is_map ) {
					$css_content .= "\n";
				}
			}
		}

		return $is_map ? $css_map : $css_content;
	}

	/**
	 * Helper function to set Bricks assets directory & URL
	 *
	 * In the constructor and on blog switch (multisite).
	 *
	 * @since 1.9.9
	 */
	public static function set_assets_directory() {
		$wp_uploads_dir = wp_upload_dir( null, false );

		self::$wp_uploads_dir = $wp_uploads_dir['basedir'];
		self::$css_dir        = $wp_uploads_dir['basedir'] . '/bricks/css';
		self::$css_url        = $wp_uploads_dir['baseurl'] . '/bricks/css';
	}

	/**
	 * Ensure Bricks CSS directory exists.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public static function ensure_css_directory() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return false;
		}

		$css_dir = untrailingslashit( self::$css_dir );

		if ( $wp_filesystem->exists( $css_dir ) ) {
			return true;
		}

		$missing_dirs = [];
		$current_dir  = $css_dir;

		while ( $current_dir && ! $wp_filesystem->exists( $current_dir ) ) {
			$missing_dirs[] = $current_dir;
			$parent_dir     = dirname( $current_dir );

			if ( $parent_dir === $current_dir ) {
				break;
			}

			$current_dir = $parent_dir;
		}

		foreach ( array_reverse( $missing_dirs ) as $missing_dir ) {
			if ( ! $wp_filesystem->exists( $missing_dir ) && ! $wp_filesystem->mkdir( $missing_dir, FS_CHMOD_DIR ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * CSS loading method "External Files": Autoload PHP files
	 *
	 * @since 1.3.5
	 */
	public static function autoload_files() {
		foreach ( glob( BRICKS_PATH . 'includes/assets/*.php' ) as $filename ) {
			require_once $filename;

			// Get last declared class to construct it
			$get_declared_classes = get_declared_classes();
			$last_class_name      = end( $get_declared_classes );

			// Init class
			new $last_class_name();
		}
	}

	/**
	 * Enqueue RTL specific scripts
	 *
	 * @since 2.0
	 */
	public function enqueue_rtl_specific_scripts() {
		if ( is_rtl() ) {
			if ( ! Database::get_setting( 'disableBricksCascadeLayer' ) ) {
				wp_enqueue_style( 'bricks-frontend-rtl', BRICKS_URL_ASSETS . 'css/frontend-rtl-layer.min.css', [], filemtime( BRICKS_PATH_ASSETS . 'css/frontend-rtl-layer.min.css' ) );
			} else {
				wp_enqueue_style( 'bricks-frontend-rtl', BRICKS_URL_ASSETS . 'css/frontend-rtl.min.css', [], filemtime( BRICKS_PATH_ASSETS . 'css/frontend-rtl.min.css' ) );
			}
		}
	}

	/**
	 * Load element setting specific scripts (icon fonts, animations, lightbox, etc.)
	 *
	 * Run for all CSS loading methods.
	 *
	 * @since 1.3.4
	 */
	public static function enqueue_setting_specific_scripts( $settings = [] ) {
		if ( empty( $settings ) ) {
			// Get all Bricks elements used on the page (header, content, footer)
			$bricks_settings_string = '';
			$all_elements           = [];
			$header_elements        = Database::get_template_data( 'header' );
			$content_elements       = Database::get_template_data( 'content' );
			$footer_elements        = Database::get_template_data( 'footer' );

			if ( is_array( $header_elements ) ) {
				$all_elements = array_merge( $all_elements, $header_elements );
			}

			if ( is_array( $content_elements ) ) {
				$all_elements = array_merge( $all_elements, $content_elements );
			}

			if ( is_array( $footer_elements ) ) {
				$all_elements = array_merge( $all_elements, $footer_elements );
			}

			$is_builder_call = bricks_is_builder();

			// Expand Post Content elements with Bricks data source (@since 2.2)
			$expand_post_content = function( $elements ) use ( &$expand_post_content ) {
				$expanded = [];

				foreach ( $elements as $element ) {
					$expanded[] = $element;

					// If Post Content with Bricks data source, fetch and add those elements too
					if ( ! empty( $element['name'] ) && $element['name'] === 'post-content' && ! empty( $element['settings']['dataSource'] ) && $element['settings']['dataSource'] === 'bricks' ) {
						$post_id = get_the_ID();

						// Skip templates to avoid infinite loops
						if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
							$post_id = Helpers::get_template_preview_post_id( $post_id );
						}

						if ( $post_id ) {
							$bricks_data = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

							if ( ! empty( $bricks_data ) && is_array( $bricks_data ) ) {
								// Recursively expand nested Post Content elements
								$expanded = array_merge( $expanded, $expand_post_content( $bricks_data ) );
							}
						}
					}
				}

				return $expanded;
			};

			// Expand all elements to include Post Content Bricks data
			$all_elements = $expand_post_content( $all_elements );

			// Get popup template IDs and expand Post Content in popups too (@since 2.2)
			$popup_template_ids = Database::$active_templates['popup'];

			// Scan expanded elements for popup interactions and add popup elements to $all_elements
			foreach ( $all_elements as $element ) {
				if ( ! empty( $element['settings']['_interactions'] ) && is_array( $element['settings']['_interactions'] ) ) {
					foreach ( $element['settings']['_interactions'] as $interaction ) {
						if ( ! empty( $interaction['templateId'] ) && is_numeric( $interaction['templateId'] ) ) {
							$popup_id = intval( $interaction['templateId'] );
							if ( ! in_array( $popup_id, $popup_template_ids ) ) {
								$popup_template_ids[] = $popup_id;
							}
						}
					}
				}
			}

			// Add popup elements to $all_elements so they're processed in the main loop below
			foreach ( $popup_template_ids as $popup_template_id ) {
				$popup_data = Database::get_data( $popup_template_id );
				if ( ! empty( $popup_data ) ) {
					$popup_data   = $expand_post_content( $popup_data );
					$all_elements = array_merge( $all_elements, $popup_data );
				}
				// Get popup template settings (contain animation from popup interactions)
				$bricks_settings_string .= wp_json_encode( Helpers::get_template_settings( $popup_template_id ) );
			}

			// Process all elements (header, content, footer, popups) - components are expanded here
			foreach ( $all_elements as $element ) {

				// No need to as we get the full element data below (@since 2.2)
				// $bricks_settings_string .= wp_json_encode( $element );

				// Get component instance string (@since 1.12)
				if ( ! empty( $element['cid'] ) ) {
					$component_instance = Helpers::get_component_instance( $element );
					if ( ! empty( $component_instance ) ) {

						// Helper recursive function to process component instances for nested components support (@since 2.0)
						$process_component_elements = function( $component_instance ) use ( &$process_component_elements, &$bricks_settings_string, $is_builder_call ) {
								// Add component instance but exclude elements array to avoid duplication
								$component_instance_without_elements = $component_instance;
								unset( $component_instance_without_elements['elements'] );
								$bricks_settings_string .= wp_json_encode( self::resolve_global_queries_for_assets( $component_instance_without_elements ) );

							if ( ! empty( $component_instance['elements'] ) && is_array( $component_instance['elements'] ) ) {
								foreach ( $component_instance['elements'] as $component_element ) {
									// Check if element is a component instance
									if ( ! empty( $component_element['cid'] ) ) {
										$nested_component_instance = Helpers::get_component_instance( $component_element );
										if ( ! empty( $nested_component_instance ) ) {
											// No need to as we add this above (@since 2.2)
											// $bricks_settings_string .= wp_json_encode( $nested_component_instance );
											// Recursively process nested component elements
											$process_component_elements( $nested_component_instance );
										}
									}
									// Else, just add component element, if it's not hidden (@since 2.2)
									elseif ( empty( $component_element['settings']['_hideElementFrontend'] ) || $is_builder_call ) {
										$bricks_settings_string .= wp_json_encode( self::resolve_global_queries_for_assets( $component_element ) );
									}
								}
							}
						};

						// Process initial component instance
						if ( ! empty( $component_instance ) ) {
							$process_component_elements( $component_instance );
						}
					}
				}

				// Get local element string
				// Only if not hidden in frontend (@since 2.2)
				elseif ( empty( $element['settings']['_hideElementFrontend'] ) || $is_builder_call ) {
					$bricks_settings_string .= wp_json_encode( self::resolve_global_queries_for_assets( $element ) );
				}
			}
		} else {
			$bricks_settings_string = wp_json_encode( self::resolve_global_queries_for_assets( $settings ) );
		}

		$theme_style_settings_string = wp_json_encode( Theme_Styles::$settings_by_id );

		// Add settings of used global element to Bricks settings string
		if ( strpos( $bricks_settings_string, '"global"' ) ) {
			$global_elements = is_array( Database::$global_data['elements'] ) ? Database::$global_data['elements'] : [];

			foreach ( $global_elements as $global_element ) {
				$global_element_id = ! empty( $global_element['global'] ) ? $global_element['global'] : false;

				if ( ! $global_element_id ) {
					$global_element_id = ! empty( $global_element['id'] ) ? $global_element['id'] : false;
				}

				if ( $global_element_id && strpos( $bricks_settings_string, $global_element_id ) ) {
					$bricks_settings_string .= wp_json_encode( self::resolve_global_queries_for_assets( $global_element ) );
				}
			}
		}

		/**
		 * STEP: Load icon font files
		 *
		 * 1. Check for icon font 'library' settings in Bricks data & theme styles ('prevArrow', 'nextArrow', etc.)
		 * 2. Check for icon font family in settings in Bricks data & theme styles ('Custom CSS', etc.)
		 */

		// Font Awesome 6.4.2 - Brands (@since 1.9.2)
		if (
			bricks_is_builder() ||
			strpos( $bricks_settings_string, '"library":"fontawesomeBrands' ) ||
			strpos( $theme_style_settings_string, '"library":"fontawesomeBrands' ) ||
			strpos( $bricks_settings_string, 'Font Awesome 6 Brands' ) ||
			strpos( $theme_style_settings_string, 'Font Awesome 6 Brands' )
		) {
			wp_enqueue_style( 'bricks-font-awesome-6-brands' );
		}

		// Font Awesome 6.4.2 - Regular & Solid (@since 1.9.2)
		if (
			bricks_is_builder() ||
			strpos( $bricks_settings_string, '"library":"fontawesomeRegular' ) ||
			strpos( $theme_style_settings_string, '"library":"fontawesomeRegular' ) ||
			strpos( $bricks_settings_string, '"library":"fontawesomeSolid' ) ||
			strpos( $theme_style_settings_string, '"library":"fontawesomeSolid' ) ||
			strpos( $bricks_settings_string, 'Font Awesome 6 Free' ) ||
			strpos( $theme_style_settings_string, 'Font Awesome 6 Free' ) ||
			strpos( $bricks_settings_string, 'Font Awesome 6 Solid' ) ||
			strpos( $theme_style_settings_string, 'Font Awesome 6 Solid' )
		) {
			wp_enqueue_style( 'bricks-font-awesome-6' );
		}

		// Iconicons
		if (
			bricks_is_builder() ||
			strpos( $bricks_settings_string, '"library":"ionicons' ) !== false ||
			strpos( $theme_style_settings_string, '"library":"ionicons' ) !== false ||
			strpos( $bricks_settings_string, 'Ionicons' ) !== false ||
			strpos( $theme_style_settings_string, 'Ionicons' ) !== false
		) {
			wp_enqueue_style( 'bricks-ionicons' );
		}

		// Themify icons
		if (
			bricks_is_builder() ||
			strpos( $bricks_settings_string, '"library":"themify' ) !== false ||
			strpos( $theme_style_settings_string, '"library":"themify' ) !== false ||
			strpos( $bricks_settings_string, 'themify' ) !== false ||
			strpos( $theme_style_settings_string, 'themify' ) !== false
		) {
			wp_enqueue_style( 'bricks-themify-icons' );
		}

		/**
		 * STEP: Load animation CSS file
		 *
		 * Check for '_animation' settings in Bricks data
		 *
		 * @since 1.6 - '_animation' deprecated  in favor of interactions (@see add_data_attributes)
		 */
		if ( bricks_is_builder() || strpos( $bricks_settings_string, '"_animation"' ) !== false ) {
			wp_enqueue_style( 'bricks-animate' );
		}

		/**
		 * STEP: Load "AJAX loader" animation CSS file
		 *
		 * Check for 'ajax_loader_animation' or 'popupAjaxLoaderAnimation' settings in Bricks data
		 */
		if ( strpos( $bricks_settings_string, '"ajax_loader_animation"' ) !== false || strpos( $bricks_settings_string, '"popupAjaxLoaderAnimation"' ) !== false ) {
			wp_enqueue_style( 'bricks-ajax-loader' );
		}

		/**
		 * STEP: Load balloon (tooltip) CSS file
		 *
		 * Check for data-balloon-pos settings in Bricks data
		 */
		if ( bricks_is_builder() || strpos( $bricks_settings_string, 'data-balloon' ) !== false ) {
			wp_enqueue_style( 'bricks-tooltips' );
		}

		/**
		 * STEP: Load Photoswipe for any lightbox setting
		 *
		 * lightboxImage, lightboxVideo, Map 'infoImages', etc.
		 */
		if (
			strpos( $bricks_settings_string, '"lightbox"' ) !== false ||
			strpos( $bricks_settings_string, '"lightboxImage"' ) !== false ||
			strpos( $bricks_settings_string, '"lightboxVideo"' ) !== false ||
			strpos( $bricks_settings_string, '"infoImages' ) !== false
		) {
			wp_enqueue_script( 'bricks-photoswipe' );
			wp_enqueue_script( 'bricks-photoswipe-lightbox' );
			wp_enqueue_style( 'bricks-photoswipe' );
		}

		/**
		 * STEP: Load global elements style file
		 *
		 * CSS class selector: .brxe-{global_element_id} and not CSS 'id'
		 */
		$global_elements_css_file_url = self::$css_url . '/global-elements.min.css';
		$global_elements_css_file_dir = self::$css_dir . '/global-elements.min.css';

		if ( ! bricks_is_builder() && strpos( $bricks_settings_string, '"global"' ) && Database::get_setting( 'cssLoading' ) === 'file' && file_exists( $global_elements_css_file_dir ) ) {
			wp_enqueue_style( 'bricks-global-elements', $global_elements_css_file_url, [], filemtime( $global_elements_css_file_dir ) );
		}

		/**
		 * STEP: Get inline CSS to load webfonts when using external files
		 *
		 * Set in element settings
		 */
		if ( Database::get_setting( 'cssLoading' ) === 'file' && empty( $settings ) ) {
			$inline_css = self::generate_inline_css();
			self::load_webfonts( $inline_css );
		}
	}

	/**
	 * Resolve query references before detecting assets in element or template data.
	 *
	 * @param mixed $data Element, settings, or list of elements. Non-array values pass through unchanged.
	 * @return mixed
	 * @since 2.4.1 (#86cbhjwy2)
	 */
	private static function resolve_global_queries_for_assets( $data ) {
		// Preserve non-array input for the caller's JSON encoding.
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( $key === 'query' && ! empty( $value['id'] ) ) {
				// Global query references omit settings such as the AJAX loader animation.
				$data[ $key ] = Helpers::maybe_get_global_query_settings( $value );
			} else {
				$data[ $key ] = self::resolve_global_queries_for_assets( $value );
			}
		}

		return $data;
	}

	/**
	 * Minify CSS string (remove line breaks & tabs)
	 *
	 * @param string $inline_css CSS string.
	 *
	 * @since 1.3.4
	 */
	public static function minify_css( $inline_css ) {
		if ( ! isset( $_GET['debug'] ) ) {
			// Minify: Remove line breaks
			$inline_css = str_replace( "\n", '', $inline_css );

			// Minify: Remove tabs
			$inline_css = preg_replace( '/\t+/', '', $inline_css );

			// Minify: Remove double spaces (@since 1.9.9)
			$inline_css = preg_replace( '/\s+/', ' ', $inline_css );

			// Minify: Remove CSS code comments (@since 1.9.9)
			$inline_css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $inline_css );
		}

		return $inline_css;
	}

	/**
	 * Reset the duplication tracking arrays
	 *
	 * These arrays are used to avoid duplicates in inline CSS generation,
	 * must reset them before generating new set of CSS for each post/page/template.
	 *
	 * Currently used in Assets_Files::regenerate_css_files() method (#86c4gzbq8)
	 *
	 * @since 2.0.1
	 * @since 2.4.3 Also reset ordered component rule groups.
	 *
	 * @return void
	 */
	public static function reset_duplication_tracking() {
		// Each external CSS file needs its own complete styles. Reset both trackers
		// together so rules emitted into another file cannot suppress this file's rules.
		self::$unique_inline_css    = [];
		self::$component_css_groups = [];

		// Reset the CSS looping elements array
		self::$css_looping_elements = [];

		self::$templates_css = [];

		// Reset the generated loop common selectors array
		self::$generated_loop_common_selectors = [];

		// Reset the generated loop common selectors breakpoints array
		self::$generated_loop_common_selectors_breakpoints = [];

		// Reset the current generating element
		self::$current_generating_element = null;

		// Reset the loop index elements
		self::$loop_index_elements = [];
	}

	/**
	 * Format a URL value for CSS url().
	 *
	 * Quote the URL so valid dynamic tag values that contain parentheses remain valid CSS.
	 *
	 * @since 2.3.6 #86c7yzzbg;
	 *
	 * @param string $url The URL to format.
	 * @return string|null
	 */
	public static function generate_css_url_value( $url ) {
		if ( $url === 'none' ) {
			return 'none';
		}

		$url = esc_url_raw( $url );
		$url = preg_replace( '/[\x00-\x1F\x7F]/', '', $url );

		// Skip the declaration when sanitization removes the value, instead of emitting invalid CSS.
		// (#86c7yzzbg; @since 2.3.6)
		if ( ! $url ) {
			return null;
		}

		$url = str_replace( [ '\\', '"' ], [ '\\\\', '\"' ], $url );

		return 'url("' . $url . '")';
	}


	/**
	 * Generate inline CSS
	 *
	 * Bricks Settings: "CSS loading Method" set to "Inline Styles" (= default)
	 *
	 * - Color Vars
	 * - Theme Styles
	 * - Global CSS Classes
	 * - Global Custom CSS
	 * - Page Custom CSS
	 * - Header
	 * - Content
	 * - Footer
	 * - Custom Fonts
	 * - Template
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string $inline_css
	 */
	public static function generate_inline_css( $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$inline_css = '';

		$template_settings_controls = Settings::get_controls_data( 'template' );

		// STEP Color palette CSS color vars
		$color_palettes = Database::$global_data['colorPalette'] ?? [];
		$color_vars     = self::generate_inline_css_color_vars( $color_palettes );
		if ( $color_vars ) {
			self::$inline_css['color_vars'] .= $color_vars;
		}

		// STEP: Color palette CSS color vars dark mode (@since 2.2)
		$dark_mode_color_vars = self::generate_inline_css_color_vars( $color_palettes, 'dark' );
		if ( $dark_mode_color_vars ) {
			self::$inline_css['color_vars'] .= $dark_mode_color_vars;
		}

		// STEP Theme Styles
		$theme_style_css = '';
		foreach ( Theme_Styles::$settings_by_id as $style_id => $settings ) {
			$theme_style_css .= self::generate_inline_css_theme_style( $settings );
		}

		if ( $theme_style_css ) {
			self::$inline_css['theme_style'] = $theme_style_css;
		}

		// STEP Global variables (@since 1.9.8)
		$global_variables = self::get_global_variables();
		$variables_css    = self::format_variables_as_css( $global_variables );
		if ( $variables_css ) {
			self::$inline_css['global_variables'] = $variables_css;
		}

		// Check: Use active template ID to retrieve page data
		$content_template_id = Database::$active_templates['content'];

		if ( $content_template_id ) {
			Database::set_page_data( $content_template_id );
		}

		// STEP Page settings (main page or template)
		if ( Database::$page_settings ) {
			self::$page_settings_post_ids[] = $content_template_id;
		}

		// STEP Page header + content + footer + popups

		// STEP Header
		$header_template = Database::get_template_data( 'header' );

		if ( ! empty( $header_template ) && is_array( $header_template ) ) {
			// Add header template ID
			self::$page_settings_post_ids[] = Database::$active_templates['header'];

			self::generate_css_from_elements( $header_template, 'header' );
		}

		// STEP Content
		$content_type     = ! empty( Database::$active_templates['content_type'] ) ? Database::$active_templates['content_type'] : 'content';
		$content_template = Database::get_template_data( $content_type );

		if ( ! empty( $content_template ) && is_array( $content_template ) ) {
			// Add content page or template ID
			$content_id = isset( Database::$active_templates[ $content_type ] ) ? Database::$active_templates[ $content_type ] : false;

			// Array check as template type 'popup' contains an array, not a string (@since 1.6)
			if ( $content_id && ! is_array( $content_id ) ) {
				self::$page_settings_post_ids[] = $content_id;
			}

			self::generate_css_from_elements( $content_template, 'content' );
		}

		// STEP Footer
		$footer_template = Database::get_template_data( 'footer' );

		if ( ! empty( $footer_template ) && is_array( $footer_template ) ) {
			// Add footer template ID
			self::$page_settings_post_ids[] = Database::$active_templates['footer'];

			self::generate_css_from_elements( $footer_template, 'footer' );
		}

		// STEP Popups
		if ( ! empty( Database::$active_templates['popup'] ) ) {
			foreach ( Database::$active_templates['popup'] as $popup_id ) {
				$popup_template_settings = Helpers::get_template_settings( $popup_id );

				if ( ! empty( $template_settings_controls['controls'] ) ) {
					self::$popup_settings_css .= self::generate_inline_css_from_element(
						[
							'settings'             => $popup_template_settings,
							'_templateCssSelector' => ".brxe-popup-{$popup_id}"
						],
						$template_settings_controls['controls'],
						'popup'
					);
				}

				$popup_data = Database::get_data( $popup_id );

				if ( empty( $popup_data ) ) {
					continue;
				}

				self::$page_settings_post_ids[] = $popup_id;

				self::generate_css_from_elements( $popup_data, 'popup' );
			}
		}

		// STEP Global Classes
		self::generate_global_classes();

		// STEP Utility classes (inline CSS loading method only)
		if ( Database::get_setting( 'cssLoading' ) !== 'file' ) {
			self::$inline_css['utility_classes'] = self::generate_style_manager_utility_classes_css();
		}

		// STEP Generates the Page Settings CSS (After the content because of the Templates and Post Content elements)
		self::generate_inline_css_page_settings();

		// STEP Template header settings
		$template_header_id       = Database::$active_templates['header'];
		$template_header_settings = Helpers::get_template_settings( $template_header_id );

		if ( ! empty( $template_settings_controls['controls'] ) ) {
			self::generate_inline_css_from_element(
				[ 'settings' => $template_header_settings ],
				$template_settings_controls['controls'],
				'template'
			);
		}

		$template_css = self::$inline_css['template'];

		// STEP Bricks Settings - Custom CSS
		if ( ! empty( Database::$global_settings['customCss'] ) ) {
			self::$inline_css['global'] = trim( Database::$global_settings['customCss'] );
		}

		// STEP: Concatinate styles (respecting precedences)

		// Global variables
		if ( ! empty( self::$inline_css['global_variables'] ) ) {
			$inline_css .= "/* GLOBAL VARIABLES CSS */\n" . self::$inline_css['global_variables'];
		}

		// Theme styles
		if ( ! empty( self::$inline_css['theme_style'] ) ) {
			$inline_css .= "\n/* THEME STYLE CSS */\n" . self::$inline_css['theme_style'];
		}

		// Utility classes
		if ( ! empty( self::$inline_css['utility_classes'] ) ) {
			$inline_css .= "\n/* UTILITY CLASSES */\n" . self::$inline_css['utility_classes'];
		}

		// Global classes
		if ( ! empty( self::$inline_css['global_classes'] ) ) {
			$inline_css .= "\n/* GLOBAL CLASSES CSS */\n" . self::$inline_css['global_classes'];
		}

		// Color palettes
		if ( ! empty( self::$inline_css['color_vars'] ) ) {
			$inline_css .= "\n/* COLOR VARS */\n" . self::$inline_css['color_vars'];
		}

		// Page settings
		if ( ! empty( self::$inline_css['page'] ) ) {
			$page_settings_ids = implode( ', ', array_unique( self::$page_settings_post_ids ) );
			$inline_css       .= "\n/* PAGE CSS (ID: {$page_settings_ids}) */\n" . self::$inline_css['page'];
		}

		// Header
		if ( ! empty( self::$inline_css['header'] ) ) {
			$inline_css .= "\n/* HEADER CSS (ID: {$template_header_id}) */\n" . self::$inline_css['header'];
		}

		// Content
		if ( ! empty( self::$inline_css['content'] ) ) {
			$inline_css .= "\n/* CONTENT CSS (ID: {$post_id}) */\n" . self::$inline_css['content'];
		}

		// Footer
		if ( ! empty( self::$inline_css['footer'] ) ) {
			$footer_id   = Database::$active_templates['footer'];
			$inline_css .= "\n/* FOOTER CSS (ID: {$footer_id}) */\n" . self::$inline_css['footer'];
		}

		// Popup
		if ( ! empty( self::$inline_css['popup'] ) ) {
			$popup_ids   = implode( ',', array_unique( Database::$active_templates['popup'] ) );
			$inline_css .= "\n/* POPUP CSS (ID: {$popup_ids}) */\n" . self::$inline_css['popup'];
		}

		// Template header settings
		$template_css = trim( $template_css );

		if ( $template_css ) {
			$inline_css .= "\n/* TEMPLATE CSS */\n" . $template_css;
		}

		// Bricks settings - Custom CSS
		if ( ! empty( self::$inline_css['global'] ) ) {
			$inline_css .= "\n/* GLOBAL CSS */\n" . self::$inline_css['global'];
		}

		/**
		 * Build Google fonts array by scanning inline CSS for Google fonts
		 */
		self::load_webfonts( $inline_css );

		return $inline_css;
	}

	/**
	 * Generates list of global palette colors as CSS vars
	 *
	 * @param array  $color_palettes
	 * @param string $mode 'light'|'dark' (@since 2.2)
	 *
	 * @return string
	 */
	public static function generate_inline_css_color_vars( $color_palettes, $mode = 'light' ) {
		self::$global_colors = [];
		$css_vars            = [];

		foreach ( $color_palettes as $palette ) {
			if ( empty( $palette['id'] ) || empty( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $color ) {
				// Skip: color has no 'id'
				$color_id = $color['id'] ?? false;

				if ( ! $color_id ) {
					continue;
				}

				$color_value  = '';
				$css_var_name = "--bricks-color-{$color_id}";

				/**
				 * Check light color value (@since 2.2)
				 *
				 * @since 2.2
				 */
				if ( $mode === 'light' && ! empty( $color['light'] ) ) {
					$color_value = $color['light'];
				}

				/**
				 * Check dark color value (@since 2.2)
				 *
				 * @since 2.2
				 */
				if ( $mode === 'dark' && ! empty( $color['dark'] ) ) {
					$color_value = $color['dark'];
				}

				/**
				 * Check for 'rgb' & 'hex' before 'raw' value
				 *
				 * As 'raw' can contain 'var()' color
				 *
				 * @since 1.10: Avoids creating a CSS var like: --cr: var(--cr)
				 */
				if ( ! empty( $color['rgb'] ) ) {
					$color_value = $color['rgb'];
				} elseif ( ! empty( $color['hex'] ) ) {
					$color_value = $color['hex'];
				}

				/**
				 * Check for 'raw' value: blue, var(--color-light), etc.
				 *
				 * @since 1.10
				 * @see #86bw0wage
				 */
				$raw_value = $color['raw'] ?? false;
				if ( $raw_value ) {
					$raw_value = bricks_render_dynamic_data( $raw_value, self::$post_id );

					// Raw is not a CSS var
					if ( strpos( $raw_value, 'var(' ) === false ) {
						$color_value = $raw_value;
					}

					// Raw is a CSS variable definition with hex/rgb color as the value
					elseif ( $color_value ) {
						// Remove 'var(' and ')' from $raw_value
						$css_var_name = str_replace( [ 'var(', ')' ], '', $raw_value );

						self::$global_colors[ $color_id ] = $raw_value;

						// Output: --primary: #ffd64f
						$css_vars[] = "$css_var_name: $color_value;";

						// Skip logic below as color is a CSS variable name & value definition
						$color_value = false;
					}
				}

				if ( $color_value ) {
					self::$global_colors[ $color_id ] = $color_value;

					$css_vars[] = "$css_var_name: $color_value;";
				}
			}
		}

		$root_selector = $mode === 'dark' ? ':root[data-brx-theme="dark"] {' : ':root {';

		$css_variables = ! empty( $css_vars ) ? $root_selector . PHP_EOL . implode( PHP_EOL, $css_vars ) . PHP_EOL . '}' . PHP_EOL : '';

		return $css_variables;
	}

	/**
	 * Helper function to generate color code based on color array
	 *
	 * @param array $color
	 *
	 * @return string
	 */
	public static function generate_css_color( $color ) {
		/**
		 * Avoid unnecessary re-generation by checking the flag
		 *
		 * @pre 2.1: Re-run 'generate_inline_css_color_vars' to set self::$global_colors on file save to add color var to 'post-{ID}.min.css'
		 * @since 2.1
		 */
		if ( ! self::$db_colors_generated ) {
			$color_vars_inline_css     = self::generate_inline_css_color_vars( get_option( BRICKS_DB_COLOR_PALETTE, [] ) );
			self::$db_colors_generated = true;
		}

		// Return color var if it exists as defined in the color palette
		if ( ! empty( $color['id'] ) ) {
			if ( array_key_exists( $color['id'], self::$global_colors ) ) {
				// Return 'raw' CSS var value from color
				$color_value = self::$global_colors[ $color['id'] ];

				if ( $color_value && strpos( $color_value, 'var(' ) !== false ) {
					return $color_value;
				}

				// Return Bricks color CSS var
				return "var(--bricks-color-{$color['id']})";
			}
		}

		// Plain color value (@since 1.5 for CSS vars, dynamic data color)
		if ( ! empty( $color['raw'] ) ) {
			return bricks_render_dynamic_data( $color['raw'], self::$post_id );
		}

		if ( ! empty( $color['rgb'] ) ) {
			return $color['rgb'];
		}

		if ( ! empty( $color['hex'] ) ) {
			return $color['hex'];
		}
	}

	/**
	 * Generate theme style CSS string
	 *
	 * @return string Inline CSS for theme styles.
	 */
	public static function generate_inline_css_theme_style( $settings = [] ) {
		if ( ! is_array( $settings ) ) {
			return;
		}

		$controls = Theme_Styles::$controls;

		if ( ! count( $controls ) ) {
			Theme_Styles::set_controls();
			$controls = Theme_Styles::$controls;
		}

		// Generic colors must precede button presets at equal specificity, regardless of saved group order.
		// Keep button specificity low so global classes can still override presets (#86cbhr8bd).
		if ( isset( $settings['colors'] ) ) {
			$settings = [ 'colors' => $settings['colors'] ] + $settings;
		}

		$inline_css     = '';
		$stylesheet_css = '';

		$links_additional_css_selectors = $settings['links']['cssSelectors'] ?? '';

		foreach ( $settings as $group_key => $group_settings ) {
			$group_controls = $controls[ $group_key ] ?? false;

			if ( ! $group_controls ) {
				continue;
			}

			// Equal-specificity rules must follow control order (e.g. All headings before H1–H6,
			// button defaults before presets), including breakpoint and pseudo-class settings.
			$settings_by_control = [];

			foreach ( $group_settings as $setting_key => $setting_value ) {
				$control_key = explode( ':', str_replace( '_hover', '', $setting_key ) )[0];

				// Legacy responsive keys still render, so they must share the same ordering bucket.
				foreach ( Breakpoints::$breakpoints as $breakpoint ) {
					$normalized_key = preg_replace( '/_' . preg_quote( $breakpoint['key'], '/' ) . '$/', '', $control_key );
					if ( $normalized_key !== $control_key ) {
						$control_key = $normalized_key;
						break;
					}
				}

				$settings_by_control[ $control_key ][ $setting_key ] = $setting_value;
			}

			$ordered_settings = [];

			foreach ( array_keys( $group_controls ) as $control_key ) {
				$ordered_settings += $settings_by_control[ $control_key ] ?? [];
			}

			$group_settings = $ordered_settings + $group_settings;

			// CSS 'stylesheet' group settings (@since 2.0)
			if ( $group_key === 'css' && ! empty( $group_settings['stylesheet'] ) ) {
				$stylesheet_css .= $group_settings['stylesheet'] . "\n";
			}

			// Link: Custom CSS selectors (@since 1.10)
			if ( $group_key === 'links' && $links_additional_css_selectors ) {
				foreach ( $group_controls as &$group_control ) {
					if ( ! empty( $group_control['css'] ) ) {
						foreach ( $group_control['css'] as &$css_rule ) {
							if ( ! empty( $css_rule['selector'] ) ) {
								$css_rule['selector'] .= ", $links_additional_css_selectors";
							} else {
								$css_rule['selector'] = $links_additional_css_selectors;
							}
						}
					}
				}
			}

			$element = [ 'settings' => $group_settings ];

			$inline_css .= self::generate_inline_css_from_element( $element, $group_controls, 'theme_style' );
		}

		// Breakpoint CSS
		$inline_css = self::generate_inline_css_for_breakpoints( 'theme_style', $inline_css );

		// Theme Styles > Stylesheet: Always global CSS (never breakpoint-aware)
		if ( $stylesheet_css ) {
			$inline_css = $stylesheet_css . $inline_css;
		}

		$contextual_spacing_settings = $settings['contextualSpacing'] ?? [];

		// STEP: Contextual spacing - Remove default margins from custom selectors (@since 2.0)
		$remove_default_margin_selectors = $contextual_spacing_settings['contextualSpacingRemoveDefaultMargins'] ?? false;
		if ( $remove_default_margin_selectors ) {
			if ( is_array( $remove_default_margin_selectors ) ) {
				$remove_default_margin_selectors = implode( ', ', $remove_default_margin_selectors );
			}

			$inline_css .= $remove_default_margin_selectors . ' {margin: 0;}' . PHP_EOL;
		}

		// STEP: Contextual spacing - Remove default padding from custom selectors (@since 2.2)
		$remove_default_padding_selectors = $contextual_spacing_settings['contextualSpacingRemoveDefaultPadding'] ?? false;
		if ( $remove_default_padding_selectors ) {
			if ( is_array( $remove_default_padding_selectors ) ) {
				$remove_default_padding_selectors = implode( ', ', $remove_default_padding_selectors );
			}

			$inline_css .= $remove_default_padding_selectors . ' {padding: 0;}' . PHP_EOL;
		}

		return $inline_css;
	}

	/**
	 * Get global variables
	 *
	 * @since 1.9.8
	 */
	public static function get_global_variables() {
		return Database::$global_data['globalVariables'] ?? [];
	}

	public static function format_variables_as_css( $variables ) {
		$css = ':root {';
		foreach ( $variables as $variable ) {
			// Ensure that the variable name is set. Value can be empty (@since 1.11)
			if ( isset( $variable['name'] ) && isset( $variable['value'] ) && $variable['value'] !== '' ) {
				$css .= "--{$variable['name']}: {$variable['value']};";
			}
		}
		$css .= '}';
		return $css;
	}


	/**
	 * Generate global classes CSS string
	 *
	 * @return string Styles for global classes.
	 *
	 * @since 1.12: Add key param to generate separate CSS to avoid duplicated styles (no result template)
	 */
	public static function generate_global_classes( $key = 'global_classes' ) {
		$use_load_order = Database::get_setting( 'globalClassesLoadOrder' );

		if ( empty( self::$global_classes_elements ) ) {
			return;
		}

		$global_classes = Database::$global_data['globalClasses'];

		if ( empty( $global_classes ) ) {
			return;
		}

		// Ensure key is a string and not empty
		$key = empty( $key ) ? 'global_classes' : (string) $key;

		$global_class_id_map = array_flip( array_column( $global_classes, 'id' ) );
		$inline_css          = '';
		$initial_inline_css  = self::$inline_css[ $key ] ?? '';

		// Collect classes to process: either in Class Manager order or element encounter order (@since 2.4)
		$classes_to_process = [];

		// Load-order mode needs utility CSS keyed by global class ID so it can follow Class Manager order (@since 2.4)
		$utility_classes = $use_load_order ? self::generate_style_manager_utility_classes_css( 'map' ) : [];

		if ( $use_load_order ) {
			// Class Manager order: iterate global classes list, skip unused (@since 2.4)
			foreach ( $global_classes as $global_class ) {
				$global_class_id = $global_class['id'] ?? '';

				if ( empty( $global_class_id ) || ! isset( self::$global_classes_elements[ $global_class_id ] ) ) {
					continue;
				}

				$classes_to_process[] = [
					'class'         => $global_class,
					'element_names' => self::$global_classes_elements[ $global_class_id ],
				];
			}
		} else {
			// Element encounter order (default): iterate used classes as encountered on the page
			foreach ( self::$global_classes_elements as $global_class_id => $element_names ) {
				if ( ! isset( $global_class_id_map[ $global_class_id ] ) ) {
					continue;
				}

				$global_class_index = $global_class_id_map[ $global_class_id ];
				$global_class       = $global_classes[ $global_class_index ];

				$classes_to_process[] = [
					'class'         => $global_class,
					'element_names' => $element_names,
				];
			}
		}

		foreach ( $classes_to_process as $class_data ) {
			$global_class    = $class_data['class'];
			$element_names   = $class_data['element_names'];
			$global_class_id = $global_class['id'] ?? '';

			// Add utility CSS before the matching global class CSS so Class Manager order controls the cascade (@since 2.4)
			if ( ! empty( $utility_classes[ $global_class_id ] ) && ! in_array( $utility_classes[ $global_class_id ], self::$unique_inline_css, true ) ) {
				$inline_css               .= $utility_classes[ $global_class_id ];
				self::$unique_inline_css[] = $utility_classes[ $global_class_id ];
			}

			if ( isset( $utility_classes[ $global_class_id ] ) ) {
				unset( $utility_classes[ $global_class_id ] );
			}

			/**
			 * STEP: Generate CSS for class.selectors
			 *
			 * Generate selector CSS per attached element type so element-specific controls
			 * such as Icon Box `iconColor` can resolve their CSS definitions correctly.
			 *
			 * @since 2.0
			 */
			$class_selectors = $global_class['selectors'] ?? [];

			// Generate CSS for each element attached to this class
			foreach ( $element_names as $element_name ) {
				$element_controls = Elements::get_element( [ 'name' => $element_name ], 'controls' );

				// Classes retain shared root styles even when the remaining elements omit those controls.
				$root_control_keys = [
					'_typography',
					'_gridGap',
					'_columnGap',
					'_rowGap',
					'_gridTemplateColumns',
					'_gridTemplateRows',
					'_gridAutoColumns',
					'_gridAutoRows',
					'_gridAutoFlow',
					'_justifyItemsGrid',
					'_alignItemsGrid',
					'_justifyContentGrid',
					'_alignContentGrid',
					'_direction',
					'_flexWrap',
				];
				$root_controls     = Elements::get_element( [ 'name' => 'container' ], 'controls' );
				$element_controls += array_intersect_key( $root_controls, array_flip( $root_control_keys ) );

				foreach ( $class_selectors as $class_selector ) {
					$selector_settings = $class_selector['settings'] ?? false;
					$selector_selector = $class_selector['selector'] ?? '';

					if ( ! $selector_settings ) {
						continue;
					}

					// Starts with pseudo: Remove space between element ID and selector.
					if ( strpos( $selector_selector, ':' ) === 0 ) {
						$selector_selector = '&' . $selector_selector;
					}

					$class_selector_controls = $element_controls;

					// Match element.selectors behavior by applying the custom selector as the control root.
					foreach ( $class_selector_controls as $control_key => $control ) {
						if ( ! empty( $control['css'] ) ) {
							foreach ( $control['css'] as $css_index => $css_rule ) {
								$class_selector_controls[ $control_key ]['css'][ $css_index ]['selector'] = $selector_selector;
							}
						}
					}

					$inline_css .= self::generate_inline_css_from_element(
						[
							'name'            => $element_name,
							'settings'        => $selector_settings,
							'_cssGlobalClass' => $global_class['name'], // Special property to add global CSS class the CSS selector
						],
						$class_selector_controls,
						$key
					);
				}

				$inline_css .= self::generate_inline_css_from_element(
					[
						'name'            => $element_name,
						'settings'        => $global_class['settings'] ?? [],
						'_cssGlobalClass' => $global_class['name'], // Special property to add global CSS class the CSS selector
					],
					$element_controls,
					$key
				);
			}
		}

		// Frontend enqueue reads the global inline bucket, so mirror ordered return CSS back into it (@since 2.4)
		if ( $use_load_order ) {
			self::$inline_css[ $key ] = $initial_inline_css . $inline_css;
		}

		return $inline_css;
	}

	public static function generate_inline_css_page_settings() {
		if ( empty( self::$page_settings_post_ids ) ) {
			return;
		}

		// Remove duplicated pages
		$post_ids = array_unique( self::$page_settings_post_ids );

		if ( ! isset( Settings::$controls['page'] ) ) {
			Settings::set_controls();
		}

		$page_settings_css      = '';
		$page_settings_controls = Settings::get_controls_data( 'page' );

		foreach ( $post_ids as $post_id ) {
			$page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

			if ( empty( $page_settings ) ) {
				continue;
			}

			// Return: Template has not been published
			if ( $post_id && get_post_status( $post_id ) !== 'publish' && get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				continue;
			}

			$page_settings_css .= self::generate_inline_css_from_element(
				[ 'settings' => $page_settings ],
				$page_settings_controls['controls'],
				'page'
			);
		}

		return $page_settings_css;
	}

	/**
	 * Get page settings scripts
	 *
	 * @param string $script_key customScriptsHeader, customScriptsBodyHeader, customScriptsBodyFooter.
	 *
	 * @return string
	 */
	public static function get_page_settings_scripts( $script_key = '' ) {
		if ( empty( self::$page_settings_post_ids ) ) {
			return;
		}

		// Remove duplicated pages
		$post_ids = array_unique( self::$page_settings_post_ids );

		$page_settings_scripts = '';

		foreach ( $post_ids as $post_id ) {
			$page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

			if ( empty( $page_settings[ $script_key ] ) ) {
				continue;
			}

			$page_settings_scripts .= stripslashes_deep( $page_settings[ $script_key ] ) . PHP_EOL;
		}

		return $page_settings_scripts;
	}

	/**
	 * Render the Google Fonts preconnect resource hint.
	 *
	 * @since 2.4
	 */
	public static function render_google_fonts_preconnect() {
		echo '<link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>';
	}

	/**
	 * Load Adobe & Google fonts according to inline CSS (source of truth) and remove loading wrapper
	 *
	 * @param string $inline_css The CSS to scan for fonts.
	 * @param bool   $return_html_links Optional. If true, returns HTML link tags instead of enqueuing. Default false.
	 * @return string|void HTML link tags if $return_html_links is true, void otherwise.
	 */
	public static function load_webfonts( $inline_css, $return_html_links = false ) {
		// Initialize webfont links if returning HTML
		$webfont_links = '';

		/**
		 * STEP: Adobe fonts
		 *
		 * If an Adobe found is found in Google fonts list, and skip it in Google fonts list.
		 *
		 * @since 1.7.1
		 */
		$adobe_fonts_project_id = ! empty( Database::$global_settings['adobeFontsProjectId'] ) ? Database::$global_settings['adobeFontsProjectId'] : false;
		$adobe_fonts            = Database::$adobe_fonts;
		$adobe_fonts_in_use     = [];

		if ( $adobe_fonts_project_id && is_array( $adobe_fonts ) && count( $adobe_fonts ) ) {
			foreach ( $adobe_fonts as $adobe_font ) {
				// Check if Adobe font is used in inline CSS
				if ( ! empty( $adobe_font['slug'] ) && strpos( $inline_css, $adobe_font['slug'] ) !== false ) {
					$adobe_fonts_in_use[] = $adobe_font['slug'];
					continue; // Font found, continue with the next font
				}

				// Font not found from the slug: Check css_names (@since 1.11)
				if ( ! empty( $adobe_font['css_names'] ) && is_array( $adobe_font['css_names'] ) ) {
					foreach ( $adobe_font['css_names'] as $css_name ) {
						if ( strpos( $inline_css, $css_name ) !== false ) {
							$adobe_fonts_in_use[] = $css_name;
						}
					}
				}
			}

			// At least one Adobe font is in use: Load Adobe fonts CSS file
			if ( count( $adobe_fonts_in_use ) ) {
				if ( $return_html_links ) {
					$webfont_links .= "<link rel=\"stylesheet\" href=\"https://use.typekit.net/{$adobe_fonts_project_id}.css\">";
				} else {
					wp_enqueue_style( "adobe-fonts-project-id-$adobe_fonts_project_id", "https://use.typekit.net/$adobe_fonts_project_id.css" );
				}
			}
		}

		// Return: Google fonts disabled
		if ( Helpers::google_fonts_disabled() ) {
			return $return_html_links ? $webfont_links : null;
		}

		/**
		 * STEP: Google fonts
		 *
		 * Add 'wdth' only for font-variation-settings (as non-variable fonts don't have it, it causes a 400 error)
		 *
		 * @since 1.8 Google Fonts API v2 (https://developers.google.com/fonts/docs/css2)
		 */
		$used_google_font_families = self::get_google_font_families_in_css( $inline_css );
		$google_fonts_families     = [];
		$active_google_fonts       = []; // Each font is an item (keys: family, variants, axis)

		if ( count( $used_google_font_families ) ) {
			$google_fonts_families = array_intersect_key(
				self::get_google_fonts_by_family(),
				array_fill_keys( $used_google_font_families, true )
			);
		}

		// Get custom font family names to avoid loading Google fonts with same names (@since 2.0)
		$custom_fonts         = Custom_Fonts::get_custom_fonts();
		$custom_font_families = [];

		if ( is_array( $custom_fonts ) ) {
			foreach ( $custom_fonts as $custom_font ) {
				if ( ! empty( $custom_font['family'] ) ) {
					$custom_font_families[] = $custom_font['family'];
				}
			}
		}

		// Scan inline CSS for each Google font
		foreach ( $google_fonts_families as $google_font ) {
			$google_font_family = ! empty( $google_font['family'] ) ? $google_font['family'] : false;

			if ( ! $google_font_family ) {
				continue;
			}

			$index = strpos( $inline_css, $google_font_family );

			// Skip iteration if this Google Font isn't found in inline CSS
			if ( $index === false ) {
				continue;
			}

			// Skip: Font already loaded via Adobe fonts above
			if ( in_array( $google_font_family, $adobe_fonts_in_use ) ) {
				continue;
			}

			// Skip: Font family name matches a custom font (avoids loading Google font when custom font with same name exists) (@since 2.0)
			if ( in_array( $google_font_family, $custom_font_families ) ) {
				continue;
			}

			$add_google_font = false;
			$font_variants   = []; // Each variation is an item with key: axis tag (ital, wdth, wght) value: axis value
			$axis_in_use     = []; // Alphabetical sorted list of axis tags in use for Google font URL

			// Search all Google Font occurrences to build up font weights
			while ( $index = strpos( $inline_css, $google_font_family, $index ) ) {
				$font_rule_index_start = strrpos( substr( $inline_css, 0, $index ), '{' ) + 1;
				$font_rule_index_end   = strpos( $inline_css, '}', $index );

				$font_rules_string = substr( $inline_css, $font_rule_index_start, $font_rule_index_end - $font_rule_index_start );
				$font_rules        = explode( ';', $font_rules_string );
				$font_axis         = [];

				foreach ( $font_rules as $font_rule_string ) {
					$font_rule    = explode( ':', trim( $font_rule_string ) );
					$css_property = ! empty( $font_rule[0] ) ? trim( $font_rule[0] ) : false;
					$css_value    = ! empty( $font_rule[1] ) ? trim( $font_rule[1] ) : false;

					if ( ! $css_property || ! $css_value ) {
						continue;
					}

					// Remove !important and trim to prevent Google font API URL error
					$css_value = trim( str_ireplace( '!important', '', $css_value ) );

					switch ( $css_property ) {
						case 'font-family':
							// Remove added single or double quotes (") from font-family value to find match
							$css_value = str_replace( "'", '', $css_value );
							$css_value = str_replace( '"', '', $css_value );

							// Remove fallback font (@since 1.5.1)
							$fallback_font_index = strpos( $css_value, ',' );

							if ( $fallback_font_index ) {
								$css_value = substr_replace( $css_value, '', $fallback_font_index, strlen( $css_value ) );
							}

							if ( $css_value === $google_font_family ) {
								$add_google_font = $google_font_family;
							}
							break;

						case 'font-weight':
							$font_axis['wght'] = $css_value;
							$axis_in_use[]     = 'wght';
							break;

						case 'font-style':
							if ( $css_value === 'italic' || $css_value === 'oblique' ) {
								$font_axis['ital'] = 1;
								$axis_in_use[]     = 'ital';
							}
							break;

						// font-variation-settings (@since 1.8)
						case 'font-variation-settings':
							// Remove single & double quotes from axis keys & values
							$css_value           = str_replace( "'", '', $css_value );
							$css_value           = str_replace( '"', '', $css_value );
							$font_variation_axis = explode( ',', $css_value );

							foreach ( $font_variation_axis as $axis ) {
								$axis_parts = explode( ' ', trim( $axis ) );
								$axis_key   = isset( $axis_parts[0] ) ? $axis_parts[0] : false;
								$axis_value = isset( $axis_parts[1] ) ? $axis_parts[1] : false;

								// Add axis key & value to font variants (e.g.: 'wdth' => '125', 'wght' => '400', etc.)
								if ( $axis_key && $axis_value ) {
									$font_axis[ $axis_key ] = $axis_value;
									$axis_in_use[]          = $axis_key;
								}
							}
							break;
					}
				}

				$font_variants[] = $font_axis;

				// Increase index to start next iteration right after last inline CSS pointer
				$index++;
			}

			// Check next Google Font
			if ( ! $add_google_font ) {
				continue;
			}

			// Load all available Google font variants so font-family doesn't have to be selected when just changing the font-weight, etc. (@since 1.5.1)
			$google_font_variants = ! empty( $google_font['variants'] ) && is_array( $google_font['variants'] ) ? $google_font['variants'] : [];

			foreach ( $google_font_variants as $google_font_variant ) {
				$google_font_axis = [];

				// 'italic' = 400 (normal)
				if ( $google_font_variant === 'italic' ) {
					$google_font_axis['wght'] = 400;
					$axis_in_use[]            = 'wght';
				}

				// italic non-400 font-weight (e.g.: 700italic)
				else {
					$google_font_axis['wght'] = str_replace( 'italic', '', $google_font_variant );
					$axis_in_use[]            = 'wght';
				}

				if ( strpos( $google_font_variant, 'italic' ) !== false ) {
					$google_font_axis['ital'] = 1;
					$axis_in_use[]            = 'ital';
				}

				$font_variants[] = $google_font_axis;
			}

			// Remove duplicate axis
			$axis_in_use = array_unique( $axis_in_use );

			sort( $axis_in_use );

			// Alphabetically sort axis (a-z like ital,slnt,wdth,wght)
			usort(
				$axis_in_use,
				function( $a, $b ) {
					return Helpers::google_fonts_get_axis_rank( $a ) > Helpers::google_fonts_get_axis_rank( $b ) ? 1 : -1;
				}
			);

			// Add family, variants, axis to active Google fonts array
			$active_google_fonts[] = [
				'family'   => $add_google_font,
				'variants' => $font_variants,
				'axis'     => array_unique( $axis_in_use ),
			];
		} // END: foreach ( $google_fonts as $google_font )

		$active_google_fonts_url = 'https://fonts.googleapis.com/css2';
		$is_first_family         = true;

		foreach ( $active_google_fonts as $google_font ) {
			// Replace font family spaces with plus sign and add to Google font URL
			$google_font_family       = str_replace( ' ', '+', $google_font['family'] );
			$active_google_fonts_url .= $is_first_family ? "?family=$google_font_family" : "&family=$google_font_family";
			$is_first_family          = false;
			$axis_in_use              = $google_font['axis'];
			$font_variants            = $google_font['variants'];

			$active_google_fonts_url .= ':' . implode( ',', $axis_in_use ) . '@'; // E.g.: :ital,wght@1,100,400;1,100,700

			$final_variants = [];

			foreach ( $font_variants as $font_variant ) {
				// Sort axis keys alphabetically
				ksort( $font_variant );

				$final_variant = [];

				// Loop over alphabetically sorted axis (ital, wdth, wght, etc.)
				foreach ( $axis_in_use as $axis ) {
					$axis_value = ! empty( $font_variant[ $axis ] ) ? $font_variant[ $axis ] : false;

					// variant has axis value
					if ( $axis_value ) {
						$final_variant[] = $axis_value;
					}

					// Fallback to default axis value
					else {
						if ( $axis === 'wdth' ) {
							$final_variant[] = 100;
						} elseif ( $axis === 'wght' ) {
							$final_variant[] = 400;
						} else {
							// 'ital', 'slnt' etc.
							$final_variant[] = 0;
						}
					}
				}

				$final_variants[] = implode( ',', $final_variant );
			}

			// Sort variants (https://developers.google.com/fonts/docs/css2#strictness)
			sort( $final_variants, SORT_NATURAL );

			$final_variants = array_unique( $final_variants );
			$final_variants = array_values( $final_variants );

			// Stringify font variants by ;
			$active_google_fonts_url .= implode( ';', $final_variants );

			$active_google_fonts_url .= '&display=swap';
		}

		// Use font stylesheet URLs
		if ( bricks_is_builder() || ! count( $active_google_fonts ) ) {
			return $return_html_links ? $webfont_links : null;
		}

		// Frontend: Load Google font files (via Webfont loader OR stylesheets (= default))

		// Return HTML links if requested (for AJAX contexts like Gutenberg)
		if ( $return_html_links ) {
			if ( ! self::$google_fonts_preconnect_returned ) {
				$webfont_links                         .= '<link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>';
				self::$google_fonts_preconnect_returned = true;
			}

			$webfont_links .= "<link rel=\"stylesheet\" href=\"{$active_google_fonts_url}\">";
			return $webfont_links;
		}

		// Fonts are already loaded (@since 1.9.9)
		if ( in_array( $active_google_fonts_url, self::$google_fonts_urls ) ) {
			return;
		}

		$google_fonts_urls_count = count( self::$google_fonts_urls );

		$google_fonts_preconnect_callback = [ __CLASS__, 'render_google_fonts_preconnect' ];

		// Multiple CSS sources can reach this method before wp_head; register one callback
		if ( has_action( 'wp_head', $google_fonts_preconnect_callback ) === false ) {
			add_action( 'wp_head', $google_fonts_preconnect_callback, 7 );
		}

		// Pass null to allow to pass multiple Google fonts via the 'family' URL parameter (#86byx84wn)
		wp_enqueue_style( $google_fonts_urls_count ? "bricks-google-fonts-$google_fonts_urls_count" : 'bricks-google-fonts', $active_google_fonts_url, [], null );

		// Add to list of loaded Google fonts to avoid duplicate loading the same font URL multiple times
		self::$google_fonts_urls[] = $active_google_fonts_url;

		/**
		 * Hide DOM until all webfonts are loaded via fontfaceobserver.min.js (contains Promise polyfill)
		 *
		 * https://github.com/bramstein/fontfaceobserver
		 * https://web.dev/codelab-avoid-invisible-text/
		 *
		 * Webfont Loader is no longer updated (2017) & does not support Google Fonts API v2 (https://github.com/typekit/webfontloader/issues/430).
		 *
		 * @since 1.8: Use FontFaceObserver (2.3.0) instead of Webfont Loader.
		 */
		if ( Database::get_setting( 'webfontLoading' ) === 'webfontloader' && $google_fonts_urls_count === 0 ) {
			$font_face_observer      = "document.addEventListener('DOMContentLoaded', function() {";
			$font_face_observer_load = '';

			foreach ( $active_google_fonts as $index => $active_google_font ) {
				$font_family = ! empty( $active_google_font['family'] ) ? $active_google_font['family'] : false;

				if ( ! $font_family ) {
					continue;
				}

				$font_face_observer      .= "const fontFaceObserver_$index = new FontFaceObserver('$font_family'); ";
				$font_face_observer_load .= "fontFaceObserver_$index.load(null, 1000)"; // Give up font-loading after max. 1000ms (default of 3000ms is too long)

				if ( $index < count( $active_google_fonts ) - 1 ) {
					$font_face_observer_load .= ',';
				}
			}

			// Second function is the error callback, which runs after 1000ms (see above)
			$font_face_observer .= "Promise.all([$font_face_observer_load]).then(function() {
				document.body.style.opacity = null;
			}, function () {
				document.body.style.opacity = null;
			});";

			$font_face_observer .= '})';

			if ( $font_face_observer_load ) {
				// Ensure DOM is loaded with 'opacity: 0' to avoid any content from briefly showing (high priority to ensure the 'style' is not reset/overwritten by the user)
				add_filter(
					'bricks/body/attributes',
					function( $attributes ) {
						if ( isset( $attributes['style'] ) ) {
							$attributes['style'] .= '; opacity: 0;';
						} else {
							$attributes['style'] = 'opacity: 0;';
						}

						return $attributes;
					},
					999999
				);

				wp_enqueue_script( 'bricks-fontfaceobserver', BRICKS_URL_ASSETS . 'js/libs/fontfaceobserver.min.js', [], '2.3.0', false );
				wp_add_inline_script( 'bricks-fontfaceobserver', $font_face_observer );
			}
		}
	}

	/**
	 * Get Google Fonts indexed by family for the current request.
	 *
	 * @return array
	 */
	private static function get_google_fonts_by_family() {
		if ( self::$google_fonts_by_family !== null ) {
			return self::$google_fonts_by_family;
		}

		$google_fonts_string = Helpers::file_get_contents( BRICKS_PATH_ASSETS . 'fonts/google-fonts.min.json' );
		$google_fonts        = json_decode( $google_fonts_string, true );
		$google_fonts        = is_array( $google_fonts ) ? $google_fonts : [];

		self::$google_fonts_by_family = [];

		foreach ( $google_fonts as $google_font ) {
			if ( ! empty( $google_font['family'] ) ) {
				self::$google_fonts_by_family[ $google_font['family'] ] = $google_font;
			}
		}

		return self::$google_fonts_by_family;
	}

	/**
	 * Get the font families used by font-family declarations in CSS.
	 *
	 * @param string $inline_css CSS to inspect.
	 * @return array
	 */
	private static function get_google_font_families_in_css( $inline_css ) {
		$cache_key = hash( 'sha256', $inline_css );

		if ( isset( self::$google_font_families_by_css[ $cache_key ] ) ) {
			return self::$google_font_families_by_css[ $cache_key ];
		}

		$font_families = [];
		$matches       = [];

		preg_match_all( '/(?:^|[;{])\s*font-family\s*:\s*([^;}]+)/i', $inline_css, $matches );

		foreach ( $matches[1] as $font_family_value ) {
			$font_family_value = trim( str_ireplace( '!important', '', $font_family_value ) );
			$font_family_value = str_replace( [ "'", '"' ], '', $font_family_value );
			$fallback_index    = strpos( $font_family_value, ',' );

			if ( $fallback_index !== false ) {
				$font_family_value = substr( $font_family_value, 0, $fallback_index );
			}

			$font_family_value = trim( $font_family_value );

			if ( $font_family_value !== '' ) {
				$font_families[] = $font_family_value;
			}
		}

		self::$google_font_families_by_css[ $cache_key ] = array_values( array_unique( $font_families ) );

		return self::$google_font_families_by_css[ $cache_key ];
	}

	/**
	 * Format a single font family name for use in a CSS declaration.
	 *
	 * Generic families are CSS keywords and must remain unquoted. All other names
	 * are escaped and quoted so externally loaded families can contain spaces.
	 *
	 * @since 2.4
	 *
	 * @param string $font_family Font family name.
	 * @return string CSS-safe font family value.
	 */
	private static function format_font_family_value( $font_family ) {
		$generic_font_families = [
			'cursive',
			'emoji',
			'fangsong',
			'fantasy',
			'math',
			'monospace',
			'sans-serif',
			'serif',
			'system-ui',
			'ui-monospace',
			'ui-rounded',
			'ui-sans-serif',
			'ui-serif',
		];

		$font_family = trim( (string) $font_family );
		$font_family = preg_replace( '/[\x00-\x1F\x7F]/', '', $font_family );

		if ( in_array( strtolower( $font_family ), $generic_font_families, true ) ) {
			return strtolower( $font_family );
		}

		$font_family = str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $font_family );

		return '"' . $font_family . '"';
	}

	/**
	 * Parse a raw CSS string and extract selector → property[] mappings.
	 *
	 * Uses a character-by-character state machine to handle comments, string
	 * literals, url() functions, and nested braces correctly. Only extracts
	 * top-level rule declarations (depth 1). At-rules (@media, @keyframes,
	 * etc.) are skipped entirely.
	 *
	 * Used by CSS sync dedup to determine which CSS properties are already
	 * present in _cssCustom so they can be omitted from control-generated CSS.
	 *
	 * @since 2.4
	 *
	 * @param string $css Raw CSS string (may contain newlines, comments, etc.).
	 * @return array Map of [ selector => [ property_name, ... ] ].
	 */
	public static function extract_custom_css_properties( $css ) {
		if ( ! is_string( $css ) || $css === '' ) {
			return [];
		}

		$result = [];
		$len    = strlen( $css );
		$depth  = 0;

		$selector   = '';
		$block      = '';
		$is_at_rule = false;

		$i = 0;

		while ( $i < $len ) {
			$char = $css[ $i ];

			// COMMENT: /* ... */
			if ( $char === '/' && $i + 1 < $len && $css[ $i + 1 ] === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = $end !== false ? $end + 2 : $len;
				continue;
			}

			// STRING: "..." or '...'
			if ( $char === '"' || $char === "'" ) {
				$string_start = $i;
				$quote        = $char;
				$i++;

				while ( $i < $len ) {
					if ( $css[ $i ] === '\\' ) {
						$i += 2;
						continue;
					}

					if ( $css[ $i ] === $quote ) {
						$i++;
						break;
					}

					$i++;
				}

				// Attribute values must remain intact so exclusions match generated selectors.
				if ( $depth === 0 ) {
					$selector .= substr( $css, $string_start, $i - $string_start );
				}

				// Placeholder in block content (actual string value irrelevant for property extraction)
				elseif ( $depth === 1 && ! $is_at_rule ) {
					$block .= $quote . '_' . $quote;
				}

				continue;
			}

			// URL: url(...) — skip content to avoid braces inside data URIs
			if ( $char === 'u' && $i + 3 < $len && substr( $css, $i, 4 ) === 'url(' ) {
				$i    += 4;
				$paren = 1;

				while ( $i < $len && $paren > 0 ) {
					if ( $css[ $i ] === '(' ) {
						$paren++;
					} elseif ( $css[ $i ] === ')' ) {
						$paren--;
					}

					$i++;
				}

				if ( $depth === 1 && ! $is_at_rule ) {
					$block .= 'url(_)';
				}

				continue;
			}

			// OPEN BRACE
			if ( $char === '{' ) {
				if ( $depth === 0 ) {
					$selector   = trim( $selector );
					$is_at_rule = isset( $selector[0] ) && $selector[0] === '@';
					$block      = '';
				}

				$depth++;
				$i++;
				continue;
			}

			// CLOSE BRACE
			if ( $char === '}' ) {
				$depth--;

				if ( $depth === 0 && ! $is_at_rule && $selector !== '' ) {
					$properties = self::parse_declaration_properties( $block );

					if ( ! empty( $properties ) ) {
						$selectors = self::split_css_selectors( $selector );

						foreach ( $selectors as $sel ) {
							if ( ! isset( $result[ $sel ] ) ) {
								$result[ $sel ] = [];
							}

							$result[ $sel ] = array_merge( $result[ $sel ], $properties );
						}
					}
				}

				if ( $depth === 0 ) {
					$selector   = '';
					$is_at_rule = false;
				}

				if ( $depth < 0 ) {
					$depth = 0;
				}

				$i++;
				continue;
			}

			// ACCUMULATE
			if ( $depth === 0 ) {
				$selector .= $char;
			} elseif ( $depth === 1 && ! $is_at_rule ) {
				$block .= $char;
			}

			$i++;
		}

		// Deduplicate property arrays and expand logical properties to physical equivalents
		foreach ( $result as $sel => $props ) {
			$expanded       = self::expand_logical_properties( $props );
			$result[ $sel ] = array_values( array_unique( $expanded ) );
		}

		return $result;
	}

	/**
	 * Extract CSS property names from a declarations block string.
	 *
	 * @since 2.4
	 *
	 * @param string $block CSS declarations (content between { and }).
	 * @return array List of valid CSS property names (lowercase).
	 */
	private static function parse_declaration_properties( $block ) {
		$properties = [];
		$parts      = self::split_css_declarations( $block );

		foreach ( $parts as $part ) {
			$part      = trim( $part );
			$colon_pos = strpos( $part, ':' );

			if ( $colon_pos === false || $colon_pos === 0 ) {
				continue;
			}

			$prop = self::canonicalize_css_property( substr( $part, 0, $colon_pos ) );

			// Validate: standard (margin-top), vendor-prefixed (-webkit-transform), custom (--my-var)
			if ( preg_match( '/^-{0,2}[a-z][a-z0-9-]*$/', $prop ) ) {
				$properties[] = $prop;
			}
		}

		return $properties;
	}

	/**
	 * Return the modern name used to compare legacy gap aliases.
	 *
	 * Authored CSS keeps its original spelling; this canonical form is only used
	 * for CSS Sync lookup and frontend declaration deduplication.
	 *
	 * @since 2.4
	 * @see https://app.clickup.com/t/2615406/86c2v7zk1
	 *
	 * @param string $property CSS property name.
	 * @return string Canonical property name.
	 */
	private static function canonicalize_css_property( $property ) {
		$property = strtolower( trim( (string) $property ) );
		$aliases  = [
			'grid-gap'        => 'gap',
			'grid-column-gap' => 'column-gap',
			'grid-row-gap'    => 'row-gap',
		];

		return isset( $aliases[ $property ] ) ? $aliases[ $property ] : $property;
	}

	/**
	 * Split a CSS declaration list on top-level semicolons.
	 *
	 * @since 2.4
	 *
	 * @param string $declarations CSS declarations.
	 * @return array Declaration strings without trailing semicolons.
	 */
	private static function split_css_declarations( $declarations ) {
		if ( ! is_string( $declarations ) || $declarations === '' ) {
			return [];
		}

		$parts   = [];
		$current = '';
		$paren   = 0;
		$quote   = '';
		$len     = strlen( $declarations );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $declarations[ $i ];

			if ( $quote ) {
				$current .= $char;

				if ( $char === '\\' && $i + 1 < $len ) {
					$i++;
					$current .= $declarations[ $i ];
					continue;
				}

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote    = $char;
				$current .= $char;
				continue;
			}

			if ( $char === '/' && $i + 1 < $len && $declarations[ $i + 1 ] === '*' ) {
				$end = strpos( $declarations, '*/', $i + 2 );

				if ( $end === false ) {
					break;
				}

				$i = $end + 1;
				continue;
			}

			if ( $char === '(' ) {
				$paren++;
			} elseif ( $char === ')' ) {
				$paren = max( 0, $paren - 1 );
			}

			if ( $char === ';' && $paren === 0 ) {
				$trimmed = trim( $current );

				if ( $trimmed !== '' ) {
					$parts[] = $trimmed;
				}

				$current = '';
				continue;
			}

			$current .= $char;
		}

		$trimmed = trim( $current );

		if ( $trimmed !== '' ) {
			$parts[] = $trimmed;
		}

		return $parts;
	}

	/**
	 * Split a CSS selector string by commas, respecting brackets and parentheses.
	 *
	 * Handles selectors like `:is(.a, .b)` or `[attr="a,b"]` without
	 * incorrectly splitting on commas inside those constructs.
	 *
	 * @since 2.4
	 *
	 * @param string $selector_string Possibly comma-separated CSS selector string.
	 * @return array Individual trimmed selectors.
	 */
	private static function split_css_selectors( $selector_string ) {
		$selectors = [];
		$current   = '';
		$paren     = 0;
		$bracket   = 0;
		$quote     = '';
		$escaped   = false;
		$len       = strlen( $selector_string );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $selector_string[ $i ];

			if ( $escaped ) {
				$current .= $char;
				$escaped  = false;
				continue;
			}

			if ( $char === '\\' ) {
				$current .= $char;
				$escaped  = true;
				continue;
			}

			if ( $quote !== '' ) {
				$current .= $char;

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$current .= $char;
				$quote    = $char;
				continue;
			}

			if ( $char === '(' ) {
				$paren++;
			} elseif ( $char === ')' ) {
				$paren = max( 0, $paren - 1 );
			} elseif ( $char === '[' ) {
				$bracket++;
			} elseif ( $char === ']' ) {
				$bracket = max( 0, $bracket - 1 );
			} elseif ( $char === ',' && $paren === 0 && $bracket === 0 ) {
				$trimmed = trim( $current );

				if ( $trimmed !== '' ) {
					$selectors[] = $trimmed;
				}

				$current = '';
				continue;
			}

			$current .= $char;
		}

		$trimmed = trim( $current );

		if ( $trimmed !== '' ) {
			$selectors[] = $trimmed;
		}

		return $selectors;
	}

	/**
	 * Split a selector into its ancestor scope and final compound selector.
	 *
	 * Whitespace and combinators inside functional pseudo-classes, attribute
	 * selectors, quoted values, and escapes do not delimit the final compound.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector without a top-level comma.
	 * @return array{scope: string, compound: string} Selector scope and final compound.
	 */
	private static function split_css_selector_final_compound( $selector ) {
		$paren          = 0;
		$bracket        = 0;
		$quote          = '';
		$escaped        = false;
		$compound_start = 0;
		$len            = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $selector[ $i ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( $char === '\\' ) {
				$escaped = true;
				continue;
			}

			if ( $quote !== '' ) {
				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote = $char;
				continue;
			}

			if ( $char === '(' ) {
				$paren++;
				continue;
			}

			if ( $char === ')' ) {
				$paren = max( 0, $paren - 1 );
				continue;
			}

			if ( $char === '[' ) {
				$bracket++;
				continue;
			}

			if ( $char === ']' ) {
				$bracket = max( 0, $bracket - 1 );
				continue;
			}

			if ( $paren !== 0 || $bracket !== 0 ) {
				continue;
			}

			if ( ctype_space( $char ) || in_array( $char, [ '>', '+', '~' ], true ) ) {
				$compound_start = $i + 1;
			} elseif ( $char === '|' && isset( $selector[ $i + 1 ] ) && $selector[ $i + 1 ] === '|' ) {
				$compound_start = $i + 2;
				$i++;
			}
		}

		return [
			'scope'    => substr( $selector, 0, $compound_start ),
			'compound' => substr( $selector, $compound_start ),
		];
	}

	/**
	 * Wrap every final compound selector while preserving its ancestor scope.
	 *
	 * @since 2.4
	 *
	 * @param string $selector_string  Possibly comma-separated CSS selector string.
	 * @param string $selector_wrapper Wrapper containing a `%s` placeholder.
	 * @return string Wrapped selector string.
	 */
	private static function wrap_css_selector_compounds( $selector_string, $selector_wrapper ) {
		$wrapped_selectors = [];

		foreach ( self::split_css_selectors( $selector_string ) as $selector_part ) {
			$selector_parts      = self::split_css_selector_final_compound( $selector_part );
			$wrapped_selectors[] = $selector_parts['scope'] . sprintf( $selector_wrapper, $selector_parts['compound'] );
		}

		return implode( ', ', $wrapped_selectors );
	}

	/**
	 * Filter CSS declarations by removing those whose property is in the exclusion list.
	 *
	 * Each declaration is a string like "property: value" or "property: value !important".
	 *
	 * @since 2.4
	 *
	 * @param array $declarations      Array of "property: value" strings.
	 * @param array $excluded_properties Array of lowercase CSS property names to exclude.
	 * @return array Filtered declarations (re-indexed).
	 */
	private static function filter_synced_declarations( $declarations, $excluded_properties ) {
		// Treat legacy gap spellings as the same declaration so Custom CSS remains
		// authoritative without forcing a saved-data migration. #86c2v7zk1; @since 2.4
		$filtered            = [];
		$excluded_properties = array_map( [ self::class, 'canonicalize_css_property' ], $excluded_properties );

		foreach ( $declarations as $decl ) {
			$parts = self::split_css_declarations( $decl );

			if ( empty( $parts ) ) {
				$parts = [ $decl ];
			}

			foreach ( $parts as $part ) {
				$colon_pos = strpos( $part, ':' );

				if ( $colon_pos === false ) {
					$filtered[] = $part;
					continue;
				}

				$prop = self::canonicalize_css_property( substr( $part, 0, $colon_pos ) );

				if ( in_array( $prop, $excluded_properties, true ) ) {
					continue;
				}

				$filtered[] = $part;
			}
		}

		return array_values( $filtered );
	}

	/**
	 * Expand CSS shorthands to the control declarations they cover.
	 *
	 * When _cssCustom contains `padding-inline: 20px`, the exclusion list needs
	 * to also include `padding-left` and `padding-right` so the control-generated
	 * physical declarations are filtered out by filter_synced_declarations().
	 *
	 * @since 2.4
	 *
	 * @param array $properties Array of CSS property names.
	 * @return array Expanded array with physical equivalents added.
	 */
	private static function expand_logical_properties( $properties ) {
		static $logical_map = [
			'margin'              => [ 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-inline', 'margin-block' ],
			'padding'             => [ 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-inline', 'padding-block' ],
			'padding-inline'      => [ 'padding-left', 'padding-right' ],
			'padding-block'       => [ 'padding-top', 'padding-bottom' ],
			'margin-inline'       => [ 'margin-left', 'margin-right' ],
			'margin-block'        => [ 'margin-top', 'margin-bottom' ],
			'border-inline'       => [ 'border-left', 'border-right', 'border-inline-width', 'border-inline-style', 'border-inline-color' ],
			'border-block'        => [ 'border-top', 'border-bottom', 'border-block-width', 'border-block-style', 'border-block-color' ],
			'border-inline-width' => [ 'border-left-width', 'border-right-width' ],
			'border-block-width'  => [ 'border-top-width', 'border-bottom-width' ],
			'border-inline-style' => [ 'border-left-style', 'border-right-style' ],
			'border-block-style'  => [ 'border-top-style', 'border-bottom-style' ],
			'border-inline-color' => [ 'border-left-color', 'border-right-color' ],
			'border-block-color'  => [ 'border-top-color', 'border-bottom-color' ],
			'border'              => [
				'border-top-width',
				'border-right-width',
				'border-bottom-width',
				'border-left-width',
				'border-top-style',
				'border-right-style',
				'border-bottom-style',
				'border-left-style',
				'border-top-color',
				'border-right-color',
				'border-bottom-color',
				'border-left-color',
				'border-top',
				'border-right',
				'border-bottom',
				'border-left',
				'border-inline',
				'border-block',
				'border-width',
				'border-style',
				'border-color',
			],
			'border-width'        => [ 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-inline-width', 'border-block-width' ],
			'border-style'        => [ 'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style', 'border-inline-style', 'border-block-style' ],
			'border-color'        => [ 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color', 'border-inline-color', 'border-block-color' ],
			'border-top'          => [ 'border-top-width', 'border-top-style', 'border-top-color' ],
			'border-right'        => [ 'border-right-width', 'border-right-style', 'border-right-color' ],
			'border-bottom'       => [ 'border-bottom-width', 'border-bottom-style', 'border-bottom-color' ],
			'border-left'         => [ 'border-left-width', 'border-left-style', 'border-left-color' ],
			'border-radius'       => [
				'border-top-left-radius',
				'border-top-right-radius',
				'border-bottom-right-radius',
				'border-bottom-left-radius',
			],
			'inset'               => [ 'top', 'right', 'bottom', 'left' ],
			'inset-inline'        => [ 'left', 'right' ],
			'inset-block'         => [ 'top', 'bottom' ],
		];

		// Logical expansion and gap alias matching share this list during frontend
		// deduplication, so aliases must be canonical before either comparison.
		$properties = array_map( [ self::class, 'canonicalize_css_property' ], $properties );
		$expanded   = $properties;
		$pending    = $properties;

		while ( $pending ) {
			$prop = array_shift( $pending );

			if ( isset( $logical_map[ $prop ] ) ) {
				foreach ( $logical_map[ $prop ] as $physical ) {
					if ( ! in_array( $physical, $expanded, true ) ) {
						$expanded[] = $physical;
						$pending[]  = $physical;
					}
				}
			}
		}

		return $expanded;
	}

	/**
	 * Loop over repeater items to generate CSS for each item (e.g. Slider 'items')
	 *
	 * @param array      $settings Element settings.
	 * @param array      $repeater_items Repeater item settings.
	 * @param string     $css_selector Element CSS selector.
	 * @param array      $repeater_control Repeater control definition.
	 * @param string     $css_type Stylesheet context.
	 * @param array|null $background_images Optional accumulator for responsive background image declarations, updated by reference.
	 * @param array      $css_sync_exclusions Authored CSS properties excluded from control declarations.
	 *
	 * @since 1.3.5
	 */
	public static function generate_inline_css_from_repeater( $settings, $repeater_items, $css_selector, $repeater_control, $css_type, &$background_images = null, $css_sync_exclusions = [] ) {
		$controls  = $repeater_control['fields'];
		$selector  = ! empty( $repeater_control['selector'] ) ? $repeater_control['selector'] : '.repeater-item';
		$nth_child = 1;
		$css_rules = [];

		foreach ( $repeater_items as $index => $item ) {
			foreach ( $item as $key => $value ) {
				// Explicit zero values must override existing spacing just like any other CSS value. (#86cbengc6; @since 2.4.3)
				if ( ! $value && ! is_numeric( $value ) ) {
					continue;
				}

				$repeater_css_selector = $css_selector;

				// Modify CSS selector for repeater item control
				switch ( $selector ) {
					// SwiperJS: target slide index by data attribute
					case 'swiperJs':
						$repeater_css_selector .= isset( $settings['hasLoop'] ) ? ' .swiper-slide' : ' .swiper-slide[data-brx-swiper-index="' . $index . '"]';
						break;

					// Apply CSS to every repeater item via field ID (e.g. posts element: dynamicMargin, etc.)
					case 'fieldId':
						$item_id                = ! empty( $item['id'] ) ? $item['id'] : $index;
						$repeater_css_selector .= ' .repeater-item [data-field-id="' . $item_id . '"]';
						break;

					// Theme Styles > Contextual Spacing (since 2.0)
					case 'contextualSpacing':
						$item_selector = $item['selector'] ?? false;

						if ( ! $item_selector ) {
							break;
						}

						$contextual_spacing_selectors = [
							".brxe-text * + :is($item_selector)",
							".brxe-post-content:not([data-source=bricks]) * + :is($item_selector)",
							Woocommerce::get_contextual_spacing_content_roots() . " * + :is($item_selector)" . Woocommerce::get_contextual_spacing_managed_exclusion(),
						];

						// Get additional selectors from 'contextualSpacingApplyTo'
						$user_selectors_string = $settings['contextualSpacingApplyTo'] ?? '';

						// STEP: User-defined selectors
						if ( ! empty( $user_selectors_string ) ) {
							$user_selectors = explode( ',', $user_selectors_string );

							if ( is_array( $user_selectors ) ) {
								foreach ( $user_selectors as $user_selector ) {
									$contextual_spacing_selectors[] = "{$user_selector} * + :is({$item_selector})";
								}
							}
						}

						$repeater_css_selector = implode( ', ', $contextual_spacing_selectors );
						break;

					// Default: Target correct repeater item via :nth-child pseudo class
					default:
						$repeater_css_selector .= " $selector:nth-child($nth_child)";
						break;
				}

				$css_rules_repeater = self::generate_css_rules_from_setting( $settings, $key, $value, $controls, $repeater_css_selector, $css_type, false, $css_sync_exclusions, $background_images );

				if ( $css_rules_repeater ) {
					foreach ( $css_rules_repeater as $css_rule_selector => $css_rules_array ) {
						if ( ! isset( $css_rules[ $css_rule_selector ] ) ) {
							$css_rules[ $css_rule_selector ] = [];
						}

						$css_rules[ $css_rule_selector ] = array_merge( $css_rules[ $css_rule_selector ], $css_rules_array );
					}
				}
			}

			$nth_child++;
		}

		return $css_rules;
	}

	/**
	 * Check setting values for dynamic data, including tags embedded in CSS functions.
	 *
	 * @param mixed $value Setting value.
	 * @return bool
	 * @since 2.4.4
	 */
	private static function has_dynamic_css_value( $value ) {
		if ( is_string( $value ) ) {
			return strpos( $value, '{' ) !== false;
		}

		// Inspect values directly so ordinary nested settings are not mistaken for tag braces.
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_dynamic_css_value( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Generate CSS string from individual setting
	 *
	 * Optional origin metadata records rules[breakpoint][selector][origin] and
	 * exact roots[selector] scopes. An external flag marks definitions whose
	 * targets or nested generators cannot be safely classified as component-local.
	 * Passing null keeps existing callers outside this bookkeeping path.
	 *
	 * @param array      $settings Element settings.
	 * @param string     $setting_key Setting key, including breakpoint and state suffixes.
	 * @param mixed      $setting_value Setting value.
	 * @param array      $controls Control definitions.
	 * @param string     $selector Element CSS selector.
	 * @param string     $css_type Stylesheet context.
	 * @param bool       $is_component_root Whether the element is a component root.
	 * @param array      $css_sync_exclusions Authored CSS properties excluded from control declarations.
	 * @param array|null $background_images Optional accumulator for responsive background image declarations, updated by reference.
	 * @param array|null $rule_origins Optional control-origin metadata accumulator, updated by reference; null disables tracking.
	 *
	 * @return array key: CSS selector. value: array of CSS rules for this CSS selector.
	 *
	 * @since 1.3.5
	 * @since 2.4.3 Added optional rule origins for component CSS generation.
	 */
	public static function generate_css_rules_from_setting( $settings, $setting_key, $setting_value, $controls, $selector, $css_type, $is_component_root = false, $css_sync_exclusions = [], &$background_images = null, &$rule_origins = null ) {
		// @since 1.8.2 Add 'staticArea' check to get correct post ID when generating dynamic CSS for static areas in the builder
		$post_id = wp_doing_ajax() && ! isset( $_POST['staticArea'] ) && ! empty( self::$post_id ) ? self::$post_id : get_the_ID();

		/**
		 * Shop & Blog page: $post_id is the first looping post id: So we need to get the original post id
		 *
		 * @since 1.9.1
		 */
		if ( ( is_home() || ( Woocommerce::is_woocommerce_active() && is_shop() ) ) && ! Query::is_any_looping() && isset( Database::$page_data['original_post_id'] ) ) {
			$post_id = Database::$page_data['original_post_id'];
		}

		if ( Helpers::is_bricks_template( $post_id ) ) {
			$preview_id = Helpers::get_template_preview_post_id( $post_id );
			$post_id    = $preview_id ? $preview_id : $post_id;
		}

		/**
		 * STEP: Get plain control key (extract breakpoint & pseudo-class)
		 *
		 * From '_margin:tablet_portait:hover' to '_margin'
		 */
		$control_key       = $setting_key;
		$control_key_parts = explode( ':', $control_key );

		// Component variant (@since 2.2)
		$variant_id = '';

		// Check if setting key has a variant suffix (e.g., '_background:variant-abc123')
		foreach ( $control_key_parts as $part ) {
			if ( strpos( $part, 'variant-' ) === 0 ) {
				$variant_id = $part;
				// Remove variant from control key
				$control_key = str_replace( ":$variant_id", '', $control_key );
				break;
			}
		}

		// BREAKPOINT
		$breakpoint = '';

		// Derive variant scopes from setting keys, including authored-only variants,
		// so later safety checks do not have to infer ownership from CSS selector text.
		if ( $rule_origins !== null && $variant_id ) {
			$rule_origins['roots'][ $selector . '[data-brx-variant="' . substr( $variant_id, strlen( 'variant-' ) ) . '"]' ] = true;
		}

		foreach ( Breakpoints::$breakpoints as $bp ) {
			$breakpoint_key = $bp['key'];

			if ( $breakpoint ) {
				continue;
			}

			/**
			 * Check if breakpoint is part of setting key
			 *
			 * Example: '_background:tablet_portrait'
			 *
			 * @since 1.3.5: we use ":" as the breakpoint delimiter
			 */

			// More than one part means we have a breakpoint
			if ( count( $control_key_parts ) > 1 ) {
				// Second part is the breakpoint key
				if ( ! empty( $control_key_parts[1] ) && $breakpoint_key === $control_key_parts[1] ) {
					$breakpoint = $control_key_parts[1];

					// Remove breakpoint from control key
					$control_key = str_replace( ":$breakpoint", '', $control_key );
				}

				continue;
			}

			/**
			 * Fallback to original '_tablet_portrait' syntax
			 *
			 * Example: '_background_tablet_portrait'
			 *
			 * @pre 1.3.5 we used "_" as the breakpoint delimiter
			 */
			elseif ( strpos( $control_key, "_$breakpoint_key" ) ) {
				$control_key = str_replace( "_$breakpoint_key", '', $control_key );
				$breakpoint  = $breakpoint_key;
			}
		}

		// PSEUDO-CLASS (':hover' @pre 1.3.5: '_hover')
		$pseudo_class = '';

		// @pre 1.3.5: Fallback to original '_hover' syntax (e.g.: _margin_hover)
		if ( strpos( $control_key, '_hover' ) ) {
			$control_key  = str_replace( '_hover', '', $control_key );
			$pseudo_class = ':hover';
		}

		// @since 1.3.5
		else {
			foreach ( Database::$global_data['pseudoClasses'] as $pseudo_class_selector ) {
				if ( $pseudo_class ) {
					continue;
				}

				$pseudo_class_starts_at = strpos( $control_key, ':' );

				if ( $pseudo_class_starts_at === false ) {
					continue;
				}

				$pseudo_class_part = substr( $control_key, $pseudo_class_starts_at );

				if ( $pseudo_class_part === $pseudo_class_selector ) {
					$pseudo_class = $pseudo_class_part;
					$control_key  = str_replace( $pseudo_class, '', $control_key );
				}
			}
		}

		$control      = $controls[ $control_key ] ?? false;
		$control_type = $control['type'] ?? '';
		$css_rules    = [];
		// Native Icon states and breakpoints can produce several exact SVG selectors.
		$icon_selectors = [];

		/**
		 * STEP: Convert specific control setting key to new values
		 *
		 * Control keys: imageRatio (#86c50gz77)
		 *
		 * @since 2.0.2
		 */
		$convert_control_keys = [
			'imageRatio' => [
				'ratio-square' => '1/1',
				'ratio-16-9'   => '16/9',
				'ratio-4-3'    => '4/3',
			],
		];

		if ( $convert_control_keys[ $control_key ] ?? false ) {
			$convert_keys = $convert_control_keys[ $control_key ];

			if ( isset( $convert_keys[ $setting_value ] ) ) {
				$setting_value = $convert_keys[ $setting_value ];
			}
		}

		// STEP: Loop over repeater items to generate CSS string
		if ( $control_type === 'repeater' ) {
			$css_rules_repeater = self::generate_inline_css_from_repeater( $settings, $setting_value, $selector, $control, $css_type, $background_images, $css_sync_exclusions );
			// Repeater generation does not report nested rule origins; keep its original output.
			if ( $rule_origins !== null && $css_rules_repeater ) {
				$rule_origins['external'] = true;
			}

			if ( is_array( $css_rules_repeater ) && count( $css_rules_repeater ) ) {
				$css_rules = array_merge( $css_rules, $css_rules_repeater );
			}
		}

		// Icon control: Add default 'css' selector rule to generate CSS rules for icon.height, icon.width, etc. (@since 2.0)
		elseif ( $control_type === 'icon' && ! isset( $control['css'] ) ) {
			$control['css'] = [
				[ 'selector' => isset( $control['root'] ) ? '' : '&{pseudo} svg' ],
			];
		}

		$css_definitions = isset( $control['css'] ) && is_array( $control['css'] ) ? $control['css'] : false;

		// Check if setting value uses dynamic data tags (@since 1.8)
		$has_dynamic_value = self::has_dynamic_css_value( $setting_value );

		// STEP: Is a CSS control: Loop through all control 'css' arrays to generate CSS rules from setting
		if ( $css_definitions ) {
			$original_setting_value = $setting_value;

			foreach ( $css_definitions as $css_definition ) {
				// Each definition must start from the normalized control value, not a previous declaration.
				$setting_value = $original_setting_value;

				// Track contributions per definition, since several controls can share one selector.
				$previous_rules   = $rule_origins !== null ? $css_rules : [];
					$css_property = isset( $css_definition['property'] ) ? $css_definition['property'] : '';
					// Absolute selector use by Woo state elements because the element is not rendered in the DOM at all (@since 2.4)
					$absolute_selector = isset( $css_definition['absoluteSelector'] ) && is_string( $css_definition['absoluteSelector'] ) ? trim( $css_definition['absoluteSelector'] ) : '';
					$css_selector      = $absolute_selector !== '' ? $absolute_selector : ( isset( $css_definition['id'] ) ? $css_definition['id'] : $selector ); // control 'id' @since 1.5.6
				$loop_index_selector   = '';

				// Append query loop index (to target specific loop item) if using dynamic tags (@since 1.8)
				if ( $has_dynamic_value && Query::is_looping() ) {
					$loop_index_selector = '[data-query-loop-index="' . Query::get_looping_unique_identifier() . '"]';

					// Maybe add loop index to element attribute to the element
					self::maybe_add_query_loop_index_attribute_to_element();
				}

				// Has custom selector & is not a ::before OR ::after pseudo element (those are always applied to the element root @since 1.4)
				$custom_selector = ! empty( $css_definition['selector'] ) ? $css_definition['selector'] : '';

				// @since 1.4: Multiple CSS selector (see Social Icons element)
				if ( strpos( $custom_selector, ', ' ) ) {
					$custom_selector = str_replace( ', ', ", $css_selector ", $custom_selector );
				}

				if (
					$custom_selector &&
					! strpos( $pseudo_class, ':before' ) &&
					! strpos( $pseudo_class, ':after' )
				) {
					$add_selector_space = ! empty( $css_selector );

					// Starts with '&' meaning no space
					if ( substr( $custom_selector, 0, 1 ) === '&' ) {
						$custom_selector    = substr( $custom_selector, 1 );
						$add_selector_space = false;
					}

					// Keep the loop index selector on the element root when styling descendants. (#86c83q14k; @since 2.3.6)
					if ( $loop_index_selector && $add_selector_space ) {
						$css_selector       .= $loop_index_selector;
						$loop_index_selector = '';
					}

					// Add loop index selector before combinator to target correct loop item when styling descendants (e.g. '.line / svg' '&.horizontal .line', etc.). (#86c83q14k; @since 2.3.6)
					elseif ( $loop_index_selector && preg_match( '/\s|[>+~]/', $custom_selector ) ) {
						$custom_selector     = preg_replace( '/(\s|[>+~])/', $loop_index_selector . '$1', $custom_selector, 1 );
						$loop_index_selector = '';
					}

					// Add space between element ID & setting CSS selector
					if ( $add_selector_space ) {
						$css_selector .= ' ';
					}

					// Append custom selector
					$css_selector .= $custom_selector;
				}

				// STEP Replace {pseudo} placeholder (see accordion.php) to apply pseudoclass in between selector
				// Icon controls may store the pseudo state on nested keys like fill:hover,
				// so defer replacement until those keys are parsed below.
				if ( strpos( $css_selector, '{pseudo}' ) !== false && ( $pseudo_class || $control_type !== 'icon' ) ) {
					$css_selector = str_replace( '{pseudo}', $pseudo_class, $css_selector );
				}

				// Append pseudo-class
				elseif ( $pseudo_class ) {
					// Check: Add pseudo-class to every selector in case multiple CSS selectors are passed in one CSS rule (see: theme styles $link_css_selectors)
					if ( strpos( $css_selector, ', ' ) ) {
						$css_selector = str_replace( ', ', "$pseudo_class, ", $css_selector );
					}

					$css_selector .= $pseudo_class;
				}

				// STEP: Prepend variant data-attribute selector for component variants
				if ( $variant_id ) {
					// Strip 'variant-' prefix
					$variant_id_clean = str_replace( 'variant-', '', $variant_id );

					// Split by comma for multiple selectors
					$selectors         = explode( ', ', $css_selector );
					$variant_selectors = [];

					foreach ( $selectors as $sel ) {
						$sel = trim( $sel );

						if ( $is_component_root ) {
							// Root element: The element itself has data-brx-variant attribute
							// Append [data-brx-variant] to the selector (no space)
							// e.g., .brxe-uqsagm[data-brx-variant="abc123"]
							if ( strpos( $sel, '#' ) === 0 || strpos( $sel, '.' ) === 0 ) {
								$variant_selectors[] = $sel . "[data-brx-variant=\"$variant_id_clean\"]";
							} else {
								$variant_selectors[] = $sel . "[data-brx-variant=\"$variant_id_clean\"]";
							}
						} else {
							// Child element: Parent has data-brx-variant attribute
							// Prepend [data-brx-variant] as descendant selector (with space)
							// e.g., [data-brx-variant="abc123"] .brxe-child-id
							$variant_selectors[] = "[data-brx-variant=\"$variant_id_clean\"] " . $sel;
						}
					}

					$css_selector = implode( ', ', $variant_selectors );
				}

				/**
				 * Wrap the final element selector while preserving any ancestor scope.
				 *
				 * Used when a control must also style an element wrapper without moving the
				 * root attributes away from the element that owns them.
				 */
				if ( ! empty( $css_definition['selectorWrapper'] ) ) {
					$css_selector = self::wrap_css_selector_compounds( $css_selector, $css_definition['selectorWrapper'] );
				}

				// Trust only local root definitions and the native Icon SVG definition.
				// Explicit targets, wrappers, and extension definitions may affect other rules.
				// Save this selector before specialized generators add their own descendants.
				$definition_origin   = 'unknown';
				$definition_selector = $css_selector;
				if ( $rule_origins !== null && ( $absolute_selector !== '' || isset( $css_definition['id'] ) || ! empty( $css_definition['selectorWrapper'] ) ) ) {
					$rule_origins['external'] = true;
				}
				if ( $rule_origins !== null && $absolute_selector === '' && ! isset( $css_definition['id'] ) && empty( $css_definition['selectorWrapper'] ) && Component_Css::is_supported_state( $pseudo_class ) ) {
					if ( ! $custom_selector ) {
						$definition_origin = 'root';
					} elseif ( $control_type === 'icon' && in_array( $custom_selector, [ 'svg', '{pseudo} svg' ], true ) && $css_property === '' && ! isset( $css_definition['value'] ) ) {
						$definition_origin               = 'icon';
						$icon_selectors[ $css_selector ] = true;
					}
				}

				/**
				 * STEP: Use CSS property 'value'
				 *
				 * Replace '%s' placeholders with 'value'
				 *
				 * @see '_content' for pseudo classes runs through as well
				 * @example repeat(%s, 1fr) to set mobile breakpoint CSS grid columns without having to use classes.
				 *
				 * @since 1.3
				 */
				if ( isset( $css_definition['value'] ) ) {
					/**
					 * Skip adding CSS rule for 'value' if specific setting is set
					 *
					 * To fix having to apply setting in a specific order.
					 *
					 * Example: Nav menu: 'mobileMenuPosition' before 'mobileMenuWidth'
					 *
					 * @since 1.10
					 */
					$skip_if_set = $css_definition['skipIfSet'] ?? false;
					if ( $skip_if_set ) {
						$check_for_setting_key = str_replace( $control_key, $skip_if_set, $setting_key );

						if ( isset( $settings[ $check_for_setting_key ] ) ) {
							continue;
						}
					}

					if ( $css_property === 'content' ) {
						// Strip slashes except if it's the pseudo class "_content", then we'll keep the slashes e.g. "\f410" (@since 1.5.1)
						if ( $control_key !== '_content' ) {
							$setting_value = stripslashes_deep( $setting_value );
						}

						$setting_value = bricks_render_dynamic_data( $setting_value, $post_id );
					}

					// 'required' value set, but doesn't match $setting_value: Skip adding rule (@since 1.8)
					if ( ! empty( $css_definition['required'] ) && $setting_value !== $css_definition['required'] ) {
						continue;
					}

					if ( strpos( $css_definition['value'], '%s' ) === false ) {
						$setting_value = $css_definition['value'];
					} else {
						$setting_value = str_replace( '%s', $setting_value, $css_definition['value'] );

						/**
						 * Wrap 'content' in quotes if not:
						 *
						 * CSS function: attr, counter, url
						 * Keyword: none, open-quote, close-quote
						 *
						 * @since 1.10
						 */
						if ( $control_key === '_content' &&
							strpos( $setting_value, 'attr(' ) === false &&
							strpos( $setting_value, 'counter(' ) === false &&
							strpos( $setting_value, 'url(' ) === false &&
							$setting_value !== 'none' &&
							$setting_value !== 'open-quote' &&
							$setting_value !== 'close-quote'
						) {
							// Wrap in quotes if not already in single or double quotes
							if ( substr( $setting_value, 0, 1 ) !== "'" && substr( $setting_value, 0, 1 ) !== '"' ) {
								$setting_value = "'$setting_value'";
							}
						}
					}

					$css_rules[ $css_selector ][] = "$css_property: $setting_value";
				} elseif ( is_array( $setting_value ) ) {
					$background_size = $setting_value['size'] ?? false;
					$background_url  = false;

					// Generate CSS declarations according to control type
					switch ( $control_type ) {
						case 'background':
							foreach ( $setting_value as $background_property => $background_value ) {
								switch ( $background_property ) {
									case 'color':
										$color_code = self::generate_css_color( $background_value );

										// Possible 'unset' value for dynamic color  (@since 2.0)
										if ( $has_dynamic_value && empty( $color_code ) ) {
											$color_code = 'unset';
										}

										if ( ! empty( $color_code ) ) {
											// Support dynamic data style (@since 1.8)
											if ( $has_dynamic_value ) {
												self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . ' {background-color: ' . $color_code . ' } ';
											} else {
												$css_rules[ $css_selector . $loop_index_selector ][] = "background-color: {$color_code}";
											}

											// Tweak Map Info Box small arrow color (popupContentBackground) (@since 2.0)
											if ( $setting_key === 'popupContentBackground' ) {
												$infobox                                        = str_replace( ' .brx-popup-content', '.brx-infobox-popup::after', $css_selector );
												$css_rules[ $infobox . $loop_index_selector ][] = "border-top-color: {$color_code}";
											}
										}
										break;

									case 'image':
										$dynamic_tag = ! empty( $background_value['useDynamicData'] ) ? $background_value['useDynamicData'] : false;

										if ( $dynamic_tag ) {
											// Generating template CSS file with dynamic image doesn't have a post ID (generate as inline CSS below instead)
											// Remove the checking of $post_id, otherwise term page with zero posts unable to generate background image (#86bzfj8d4)
											$image_size = $background_value['size'] ?? BRICKS_DEFAULT_IMAGE_SIZE;
											$images     = Integrations\Dynamic_Data\Providers::render_tag( $dynamic_tag, $post_id, 'image', [ 'size' => $image_size ] );
											$image_id   = $images[0] ?? 0;

											if ( $image_id ) {
												$background_url = is_numeric( $image_id ) ? wp_get_attachment_image_url( $image_id, $image_size ) : $image_id;
											} else {
												$background_url = 'none'; // Explicitly set to 'none' to prevent background image from being set (@since 2.0)
											}
										} else {
											$background_url = false;

											// originalUrl set: External URL with dynamic data (@since 1.11)
											if ( ! empty( $background_value['external'] ) && $background_value['external'] != 'true' ) {
												$background_url = bricks_render_dynamic_data( $background_value['external'], $post_id );
											}

											elseif ( ! empty( $background_value['url'] ) ) {
												$background_url = $background_value['url'];
											}
										}

										// Generate background image style if background_url is set (@since 1.8)
										if ( $background_url ) {
											// Keep 'none' unquoted, but quote URLs to avoid breaking CSS on parentheses.
											// (#86c7yzzbg; @since 2.3.6)
											$background_url = self::generate_css_url_value( $background_url );

											if ( $background_url === null ) {
												break;
											}

											// Keep static overrides beside dynamic images in the footer cascade, including loop-item scope.
											if ( is_array( $background_images ) ) {
												$image_group      = wp_json_encode( [ $selector, $control_key, $pseudo_class, $variant_id, $css_definition ] );
												$image_breakpoint = $breakpoint ? $breakpoint : 'desktop';
												$excluded         = $css_sync_exclusions[ $breakpoint ][ $css_selector . $loop_index_selector ] ?? [];

												if ( $dynamic_tag || ! in_array( 'background-image', $excluded, true ) ) {
													$background_images[ $image_group ]['values'][ $image_breakpoint ]         = $background_url;
													$background_images[ $image_group ][ $dynamic_tag ? 'dynamic' : 'static' ] = true;

													if ( $dynamic_tag ) {
														$background_images[ $image_group ]['selector'] = $css_selector . $loop_index_selector;
														if ( $image_breakpoint === Breakpoints::$base_key ) {
															$background_images[ $image_group ]['dynamic_base'] = true;
														}
													}
												}
											}

											// Add breakpoint-specific dynamic data via inline CSS (as we need the post ID of the requested post)
											if ( $dynamic_tag ) {
												$dynamic_data_background = $css_selector . $loop_index_selector . ' {background-image: ' . $background_url . '} ';

												// Is mobile first: No breakpoint = desktop
												if ( ! $breakpoint && Breakpoints::$is_mobile_first ) {
													$breakpoint = 'desktop';
												}

												// Add at-media rule for breakpoint (@since 1.8)
												if ( $breakpoint ) {
													$at_media_rule = self::get_at_media_rule_for_breakpoint( $breakpoint );

													if ( $at_media_rule ) {
														$dynamic_data_background = $at_media_rule . ' {' . $dynamic_data_background . '}';
													}
												}

												self::$inline_css_dynamic_data .= $dynamic_data_background;
											} else {
												$css_rules[ $css_selector . $loop_index_selector ][] = 'background-image: ' . $background_url;
											}
										}
										break;

									case 'attachment':
										$css_rules[ $css_selector ][] = "background-attachment: $background_value";
										break;

									case 'blendMode':
										$css_rules[ $css_selector ][] = "background-blend-mode: $background_value";
										break;

									case 'repeat':
										$css_rules[ $css_selector ][] = "background-repeat: $background_value";
										break;

									case 'position':
										// Custom background-position x/y values
										if ( $background_value === 'custom' ) {
											$background_position = [];

											if ( isset( $setting_value['positionX'] ) ) {
												$background_position[] = $setting_value['positionX'];
											} else {
												$background_position[] = 'center';
											}

											if ( isset( $setting_value['positionY'] ) ) {
												$background_position[] = $setting_value['positionY'];
											} else {
												$background_position[] = 'center';
											}

											$css_rules[ $css_selector ][] = 'background-position: ' . implode( ' ', $background_position );
										} else {
											$css_rules[ $css_selector ][] = "background-position: $background_value";
										}
										break;

									case 'size':
										if ( $background_size !== 'custom' ) {
											$css_rules[ $css_selector ][] = "background-size: $background_value";
										}
										break;

									case 'custom':
										if ( $background_size === 'custom' ) {
											$css_rules[ $css_selector ][] = 'background-size: ' . bricks_render_dynamic_data( $background_value, $post_id );
										}
										break;
								}

								// Set background-size to cover (Bricks default)
								if ( $background_url && ! $background_size ) {
									$css_rules[ $css_selector ][] = 'background-size: cover';
								}
							}
							break;

						case 'border':
							$border_directions = ! empty( $control['directions'] ) ? $control['directions'] : [ 'top', 'right', 'bottom', 'left' ];
							$border_width      = ! empty( $setting_value['width'] ) ? $setting_value['width'] : [];
							$border_style      = ! empty( $setting_value['style'] ) ? $setting_value['style'] : '';
							$border_color      = ! empty( $setting_value['color'] ) ? self::generate_css_color( $setting_value['color'] ) : '';

							$border_widths = [];

							foreach ( $border_directions as $direction ) {
								$number = isset( $border_width[ $direction ] ) ? $border_width[ $direction ] : '';
								$unit   = ! empty( $border_width['unit'][ $direction ] ) ? trim( $border_width['unit'][ $direction ] ) : '';

								// Skip: No number, nor unit
								if ( $number === '' && $unit === '' ) {
									continue;
								}

								// Number only: Add defaultUnit
								if ( is_numeric( $number ) && $number != 0 ) {
									$unit = 'px';
								}

								// Unitless (default: 'px')
								if ( $unit === '-' || $unit === 'none' ) {
									$unit = '';
								}

								// Append unit
								$value = $unit && strpos( $number, $unit ) === false ? $number . $unit : $number;

								if ( $unit === 'auto' ) {
									$value = 'auto';
								}

								$border_widths[ $direction ] = $value;
							}

							$border_width_directions = array_keys( $border_widths );
							$border_width_values     = array_values( $border_widths );
							$border_style_set        = false;
							$border_color_set        = false;

							// border-width
							if ( count( $border_width_values ) ) {
								// All four border sides have same value: Use 'border' CSS shorthand
								if ( count( $border_width_values ) === 4 && count( array_unique( $border_width_values ) ) === 1 ) {
									// border: 0
									if ( $border_width_values[0] == '0' ) {
										$css_rules[ $css_selector ][] = 'border: 0';
									}

									// border per direction (if style set)
									elseif ( $border_style ) {
										// NOTE: We shouldn't set a custom border-color as the default, but use currentcolor instead (keeping it for backwards compatibility)
										if ( ! $border_color ) {
											$border_color = 'var(--bricks-border-color)';
										}

										// Support dynamic style (@since 1.8)
										if ( $has_dynamic_value ) {
											self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . ' {border:' . $border_width_values[0] . ' ' . $border_style . ' ' . $border_color . '} ';
										} else {
											$css_rules[ $css_selector . $loop_index_selector ][] = "border: {$border_width_values[0]} $border_style $border_color";
										}

										$border_style_set = true;
										$border_color_set = true;
									}

									// Same border-width for all sides, but no border-style set (@since 1.10)
									else {
										$css_rules[ $css_selector . $loop_index_selector ][] = "border-width: {$border_width_values[0]}";
									}
								}

								// Different values per direction: Use 'border-{direction}' CSS shorthand
								else {
									foreach ( $border_widths as $direction => $value ) {
										if ( $border_style && $border_color ) {
											$css_rules[ $css_selector ][] = "border-$direction: {$value} {$border_style} {$border_color}";

											// Support dynamic data border (@since 1.10)
											if ( $has_dynamic_value ) {
												self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . " {border-$direction: {$value} {$border_style} {$border_color}}";
											}

											$border_style_set = true;
											$border_color_set = true;
										} else {
											$css_rules[ $css_selector ][] = "border-$direction-width: {$value}";

											if ( $border_style ) {
												$css_rules[ $css_selector ][] = "border-$direction-style: {$border_style}";

												$border_style_set = true;
											}

											if ( $border_color ) {
												$css_rules[ $css_selector ][] = "border-$direction-color: {$border_color}";

												// Support dynamic data border (@since 1.10)
												if ( $has_dynamic_value ) {
													self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . " {border-$direction-color: {$border_color}}";
												}

												$border_color_set = true;
											}
										}
									}
								}
							}

							// border-style (if not set)
							if ( $border_style && ! $border_style_set ) {
								$css_rules[ $css_selector ][] = "border-style: {$border_style}";
							}

							// border-color (if not set)
							if ( $border_color && ! $border_color_set ) {
								// Support dynamic style (@since 1.8)
								if ( $has_dynamic_value ) {
									self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . ' {border-color:' . $border_color . '} ';
								} else {
									$css_rules[ $css_selector . $loop_index_selector ][] = "border-color: $border_color";
								}
							}

							// STEP: border-radius
							if ( empty( $setting_value['radius'] ) ) {
								break;
							}

							$border_radius        = $setting_value['radius'];
							$border_radius_rules  = [];
							$border_radius_widths = [];

							foreach ( $border_directions as $direction ) {
								$number = isset( $border_radius[ $direction ] ) ? $border_radius[ $direction ] : '';
								$unit   = ! empty( $border_radius['unit'][ $direction ] ) ? $border_radius['unit'][ $direction ] : '';

								// Skip: No number, nor unit
								if ( $number === '' && $unit === '' ) {
									continue;
								}

								// Number only: Add defaultUnit
								if ( is_numeric( $number ) && $number != 0 ) {
									$unit = 'px';
								}

								// Unitless (default: 'px')
								if ( $unit === '-' || $unit === 'none' ) {
									$unit = '';
								}

								// Append unit
								$value = $unit && strpos( $number, $unit ) === false ? $number . $unit : $number;

								$border_radius_rules[ $direction ] = $value;
								$border_radius_widths[]            = $value;
							}

							if ( count( $border_radius_widths ) === 4 ) {
								// All four border-radius values are identical: Use 'border-radius' shorthand syntax
								if ( count( array_unique( $border_radius_widths ) ) === 1 ) {
									$css_rules[ $css_selector ][] = "border-radius: {$border_radius_widths[0]}";
								} else {
									$border_radius_widths         = join( ' ', $border_radius_widths );
									$css_rules[ $css_selector ][] = "border-radius: {$border_radius_widths}";
								}
							}

							// Add individual border-radius rule (e.g. border-top-right-radius)
							else {
								foreach ( $border_radius_rules as $direction => $value ) {
									if ( $direction === 'top' ) {
										$css_rules[ $css_selector ][] = "border-top-left-radius: $value";
									} elseif ( $direction === 'right' ) {
										$css_rules[ $css_selector ][] = "border-top-right-radius: $value";
									}if ( $direction === 'bottom' ) {
										$css_rules[ $css_selector ][] = "border-bottom-right-radius: $value";
									}if ( $direction === 'left' ) {
										$css_rules[ $css_selector ][] = "border-bottom-left-radius: $value";
									}
								}
							}
							break;

						case 'box-shadow':
							$box_shadow = [];

							if ( isset( $setting_value['inset'] ) ) {
								$box_shadow[] = 'inset';
							}

							$box_shadow_values = ! empty( $setting_value['values'] ) ? $setting_value['values'] : '';

							if ( $box_shadow_values ) {
								$box_shadow_properties = [ 'offsetX', 'offsetY', 'blur', 'spread' ];

								foreach ( $box_shadow_properties as $key ) {
									$box_shadow_value = isset( $box_shadow_values[ $key ] ) ? $box_shadow_values[ $key ] : 0;

									// Number only: Add defaultUnit
									if ( is_numeric( $box_shadow_value ) && $box_shadow_value != 0 ) {
										$box_shadow_value .= 'px';
									}

									$box_shadow[] = $box_shadow_value;
								}
							}

							$box_shadow_color = isset( $setting_value['color'] ) ? $setting_value['color'] : '';

							if ( $box_shadow_color ) {
								$color_code = self::generate_css_color( $box_shadow_color );

								if ( $color_code ) {
									$box_shadow[] = $color_code;
								}
							} else {
								$box_shadow[] = 'transparent';
							}

							$css_rules[ $css_selector ][] = 'box-shadow: ' . join( ' ', $box_shadow );
							break;

						case 'color':
							$color_code = self::generate_css_color( $setting_value );

							if ( $color_code ) {
								// Support dynamic style (@since 1.8)
								if ( $has_dynamic_value ) {
									self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . ' {' . $css_property . ':' . $color_code . '} ';
								} else {
									$css_rules[ $css_selector . $loop_index_selector ][] = "{$css_property}: {$color_code}";
								}
							}
							break;

						case 'dimensions':
						case 'spacing': // @since 1.5.1
							$directions = [ 'top', 'right', 'bottom', 'left' ];

							// Custom directions
							if ( ! empty( $control['directions'] ) ) {
								$directions = isset( $control['directions'][0] ) ? $control['directions'] : array_keys( $control['directions'] );
							}

							// Populate values for all set directions
							foreach ( $directions as $direction ) {
								$number = isset( $setting_value[ $direction ] ) ? $setting_value[ $direction ] : '';
								$unit   = ! empty( $setting_value['unit'][ $direction ] ) ? trim( $setting_value['unit'][ $direction ] ) : '';

								// Skip: No number, nor unit
								if ( $number === '' && $unit === '' ) {
									continue;
								}

								// Number only: Add defaultUnit
								if ( is_numeric( $number ) && $number != 0 && ! $unit ) {
									$unit = 'px';
								}

								// Unitless (default: 'px')
								if ( $unit === '-' || $unit === 'none' ) {
									$unit = '';
								}

								// Append unit
								$value = $unit && strpos( $number, $unit ) === false ? $number . $unit : $number;

								if ( $unit === 'auto' ) {
									$value = 'auto';
								}

								$property = $direction;

								if ( $css_property ) {
									// @see 'grid-{key}-gap' in '_gridGap' (@since.1.5.5)
									if ( strpos( $css_property, '{key}' ) !== false ) {
										$property = str_replace( '{key}', $direction, $css_property );
									} else {
										$property = "$css_property-$direction";
									}
								}

								$css_rules[ $css_selector ][] = "$property: $value";
							}
							break;

						case 'filters':
							// CSS filters
							$filters = [];

							foreach ( $setting_value as $filter_key => $filter_value ) {
								if ( $filter_value === '' ) {
									continue;
								}

								switch ( $filter_key ) {
									case 'blur':
										$filter_value .= 'px';
										break;

									case 'brightness':
									case 'contrast':
									case 'invert':
									case 'opacity':
									case 'saturate':
									case 'sepia':
										$filter_value .= '%';
										break;

									case 'hue-rotate':
										$filter_value .= 'deg';
										break;
								}

								$filters[] = $filter_key . '(' . $filter_value . ')';
							}

							$css_rules[ $css_selector ][] = 'filter: ' . join( ' ', $filters );
							break;

						case 'gradient':
							if ( ! isset( $setting_value['colors'] ) ) {
								return;
							}

							$gradient_declaration = '';

							// Selector to target individual loop items (@since 2.3)
							$css_loop_selector = $css_selector . $loop_index_selector;

							$setting_value['applyTo'] = $setting_value['applyTo'] ?? 'background';

							if ( ! empty( $setting_value['cssSelector'] ) ) {
								// Remove custom selector to only use gradient selector (@since 1.7)
								if ( $custom_selector ) {
									$css_selector = str_replace( $custom_selector, '', $css_selector );
								}

								$css_selector .= " {$setting_value['cssSelector']}";

								// Also append to loop selector (@since 2.3)
								$css_loop_selector .= " {$setting_value['cssSelector']}";
							}

							if ( $setting_value['applyTo'] === 'text' ) {
								$css_rules[ $css_selector ][] = '-webkit-background-clip: text';
								$css_rules[ $css_selector ][] = '-webkit-text-fill-color: transparent';
							}

							$gradient_count = count( $setting_value['colors'] );

							$gradient_declaration .= 'background-image: ';

							// STEP: Check if 'repeat' is set and adjust the gradient declaration accordingly (@since 1.9.3)
							if ( isset( $setting_value['repeat'] ) ) {
								$gradient_declaration .= 'repeating-';
							}

							// STEP: Set gradient type (linear, radial, conic)
							$gradient_type         = $setting_value['gradientType'] ?? 'linear';
							$gradient_declaration .= "$gradient_type-gradient(";

							// STEP: Set radial gradient position & shape & size (@since 1.9.4)
							if ( $gradient_type === 'radial' ) {
								$radial_shape    = $setting_value['radialShape'] ?? '';
								$radial_size     = $setting_value['radialSize'] ?? '';
								$radial_position = $setting_value['radialPosition'] ?? 'center';

								// If custom position is set, use custom position control value
								if ( $radial_position === 'custom' ) {
									$radial_position = $setting_value['radialCustomPosition'] ?? 'center';
								}

								$gradient_declaration .= "$radial_shape $radial_size at $radial_position, ";
							}
							// STEP: Set conic gradient angle & position
							elseif ( $gradient_type === 'conic' ) {
								$conic_angle    = isset( $setting_value['conicAngle'] ) ? "{$setting_value['conicAngle']}deg" : '0deg';
								$conic_position = $setting_value['conicPosition'] ?? 'center';

								// If custom position is set, use custom position control value
								if ( $conic_position === 'custom' ) {
									$conic_position = $setting_value['conicCustomPosition'] ?? 'center';
								}

								$gradient_declaration .= "from $conic_angle at $conic_position, ";
							}
							// STEP: Set linear gradient angle
							elseif ( $gradient_type === 'linear' && isset( $setting_value['angle'] ) ) {
								$gradient_declaration .= "{$setting_value['angle']}deg, ";
							}

							// One color (use as second color too)
							if ( $gradient_count === 1 ) {
								$setting_value['colors'][] = $setting_value['colors'][0];
							}

							$colors = [];

							foreach ( $setting_value['colors'] as $color ) {
								if ( ! empty( $color['color']['raw'] ) ) {
									$color_value = $color['color']['raw'];
								} elseif ( ! empty( $color['color']['rgb'] ) ) {
									$color_value = $color['color']['rgb'];
								} elseif ( ! empty( $color['color']['hex'] ) ) {
									$color_value = $color['color']['hex'];
								} else {
									$color_value = false;
								}

								if ( $color_value ) {
									$color_stop = $color['stop'] ?? '';

									// Append % if $color_stop is a number
									if ( is_numeric( $color_stop ) ) {
										$color_stop .= '%';
									}

									$colors[] = $color_stop ? "$color_value $color_stop" : $color_value;
								}
							}

							$gradient_is_dd_tag = false;

							if ( count( $colors ) ) {
								// Parse dynamic data for gradient colors (@since 1.7.1)
								foreach ( $colors as $index => $color ) {
									// Check if color is dynamic data tag
									if ( $color && strpos( $color, '{' ) === 0 ) {
										$gradient_is_dd_tag = true;
									}

									$colors[ $index ] = bricks_render_dynamic_data( $color, $post_id );
								}

								// Remove empty colors (i.e. non-existent dynamic data)
								$colors = array_filter( $colors );

								// Only one color left (use as second color too)
								if ( count( $colors ) === 1 ) {
									$colors[] = $colors[0];
								}

								$gradient_declaration .= join( ', ', $colors );
								$gradient_declaration .= ')';

								/**
								 * Apply overlay to ::before pseudo class
								 * Can conflict with custom pseudo rules, but then user can move those CSS rules into ::after.
								 * Proper overlay requires ::before selector.
								 */
								if ( $setting_value['applyTo'] === 'overlay' ) {
									$position_key = $breakpoint ? "_position:$breakpoint" : '_position';

									// Set position: relative for element around pseudo overlay (if no _position set explicitly)
									if ( ! isset( $settings[ $position_key ] ) ) {
										$css_rules[ $css_selector ][] = 'position: relative';
									}

									// Set position: relative for child elements here instead of .has-overlay (@since 1.6)
									// Don't set position: relative for figcaption elements, so we can get rid of "!important" statement in _image.scss (@since 2.1)
									$css_rules[ ":where($css_selector > *:not(figcaption))" ] = [ 'position: relative' ];

									$css_selector      .= '::before';
									$css_loop_selector .= '::before';
								}

								// Using $css_loop_selector to target individual loop items (@since 2.3)
								// NOTE: Don't add CSS rule here, if we are using dynamic data for gradient colors and loading CSS via external file (added later via inline CSS)
								if ( ! ( $gradient_is_dd_tag && Database::get_setting( 'cssLoading' ) === 'file' ) ) {
									$css_rules[ $css_loop_selector ][] = $gradient_declaration;
								}

								if ( $setting_value['applyTo'] === 'overlay' ) {
									$css_rules[ $css_selector ][] = 'position: absolute; content: ""; top: 0; right: 0; bottom: 0; left: 0; pointer-events: none';
								}

								/**
								 * External files: Add gradient to inline CSS
								 *
								 * Needed as gradient DD color set in template is not outputted in template CSS file.
								 *
								 * @see #863h7kvdd
								 * @since 1.9.2
								 */
								if ( $gradient_is_dd_tag && Database::get_setting( 'cssLoading' ) === 'file' ) {
									self::$inline_css_dynamic_data .= $css_loop_selector . '{' . $gradient_declaration . '}';
								}
							}
							break;

						case 'icon':
							foreach ( $setting_value as $key => $val ) {
								// Icon subkeys can include breakpoint and pseudo suffixes
								// (e.g. fill:hover, width:tablet_portrait:hover).
								// Parse them here so nested SVG/icon styles can target
								// the same state as normal controls.
								$icon_key_parts    = explode( ':', $key );
								$key               = $icon_key_parts[0];
								$icon_breakpoint   = '';
								$icon_pseudo_class = '';
								$icon_css_selector = $css_selector;

								foreach ( array_slice( $icon_key_parts, 1 ) as $icon_key_part ) {
									if ( ! $icon_breakpoint ) {
										foreach ( Breakpoints::$breakpoints as $bp ) {
											if ( $icon_key_part === $bp['key'] ) {
												$icon_breakpoint = $icon_key_part;
												continue 2;
											}
										}
									}

									if ( ! $icon_pseudo_class ) {
										foreach ( Database::$global_data['pseudoClasses'] as $pseudo_class_selector ) {
											if ( ":$icon_key_part" === $pseudo_class_selector ) {
												$icon_pseudo_class = $pseudo_class_selector;
												continue 2;
											}
										}
									}
								}

								if ( $icon_pseudo_class ) {
									if ( strpos( $icon_css_selector, '{pseudo}' ) !== false ) {
										$icon_css_selector = str_replace( '{pseudo}', $icon_pseudo_class, $icon_css_selector );
									} else {
										$icon_css_selector .= $icon_pseudo_class;
									}
								} elseif ( strpos( $icon_css_selector, '{pseudo}' ) !== false ) {
									$icon_css_selector = str_replace( '{pseudo}', '', $icon_css_selector );
								}

								// Nested Icon settings queue responsive rules directly. Record their final
								// breakpoint and state here instead of assuming the enclosing control's scope.
								if ( $rule_origins !== null && in_array( $key, [ 'height', 'width', 'strokeWidth', 'stroke', 'fill' ], true ) ) {
									$origin = $definition_origin === 'icon' && Component_Css::is_supported_state( $icon_pseudo_class ) ? 'icon' : 'unknown';
									if ( $origin === 'icon' ) {
										$icon_selectors[ $icon_css_selector ] = true;
									}
									$origin_breakpoint = $icon_breakpoint !== '' ? $icon_breakpoint : ( $breakpoint !== '' ? $breakpoint : 'desktop' );
									$rule_origins['rules'][ $origin_breakpoint ][ $icon_css_selector ][ $origin ] = true;
								}

								switch ( $key ) {
									case 'height':
									case 'width':
										// Add default unit 'px'
										if ( is_numeric( $val ) ) {
											$val .= 'px';
										}

										if ( $icon_breakpoint ) {
											self::$inline_css_breakpoints[ $css_type ][ $icon_breakpoint ][ $icon_css_selector ][] = "$key: $val";
										} else {
											$css_rules[ $icon_css_selector ][] = "$key: $val";
										}
										break;

									case 'strokeWidth':
										// Add default unit 'px'
										if ( is_numeric( $val ) ) {
											$val .= 'px';
										}

										if ( $icon_breakpoint ) {
											self::$inline_css_breakpoints[ $css_type ][ $icon_breakpoint ][ $icon_css_selector ][] = "stroke-width: $val";
										} else {
											$css_rules[ $icon_css_selector ][] = "stroke-width: $val";
										}
										break;

									case 'stroke':
									case 'fill':
										$color_code = self::generate_css_color( $val );

										if ( $color_code ) {
											// Keep unpainted groups transparent while recoloring shapes with their own presentation attributes.
											$graphic_elements = 'path, rect, circle, ellipse, line, polyline, polygon, g, use, image, text, tspan';
											$graphic_selector = "$icon_css_selector :is($graphic_elements):not([$key='none']):not([$key='none'] *), $icon_css_selector :is($graphic_elements)[$key]:not([$key='none'])";
											$root_selector    = ":is($icon_css_selector):not([$key='none'])";
											$graphic_rule     = "$key: $color_code";

											// Paint selectors belong to the same native Icon group as sizing,
											// including rules queued directly for nested breakpoints and states.
											if ( $rule_origins !== null ) {
												foreach ( [ $root_selector, $graphic_selector ] as $paint_selector ) {
													if ( $origin === 'icon' ) {
														$icon_selectors[ $paint_selector ] = true;
													}
													$rule_origins['rules'][ $origin_breakpoint ][ $paint_selector ][ $origin ] = true;
												}
											}

											if ( $icon_breakpoint ) {
												self::$inline_css_breakpoints[ $css_type ][ $icon_breakpoint ][ $root_selector ][] = "$key: $color_code";

												self::$inline_css_breakpoints[ $css_type ][ $icon_breakpoint ][ $graphic_selector ][] = $graphic_rule;

												if ( $key === 'fill' ) {
													self::$inline_css_breakpoints[ $css_type ][ $icon_breakpoint ][ $icon_css_selector ][] = "color: $color_code";
												}
											} else {
												$css_rules[ $root_selector ][] = "$key: $color_code";

												$css_rules[ $graphic_selector ][] = $graphic_rule;

												if ( $key === 'fill' ) {
													$css_rules[ $icon_css_selector ][] = "color: $color_code";
												}
											}
										}
										break;
								}
							}
							break;

						case 'image':
							if ( ! empty( $setting_value['url'] ) ) {
								$css_rules[ $css_selector ][] = $css_property . ': url(' . $setting_value['url'] . ')';
							}
							break;

						case 'radio':
							if ( count( $setting_value ) === 1 ) {
								$css_rules[ $css_selector ][] = $css_property . ': ' . $setting_value[0];
							}
							break;

						case 'text-shadow':
							$text_shadow        = [];
							$text_shadow_values = $setting_value['values'] ?? '';

							if ( $text_shadow_values ) {
								if ( ! empty( $text_shadow_values['offsetX'] ) ) {
									$text_shadow[] = is_numeric( $text_shadow_values['offsetX'] ) ? $text_shadow_values['offsetX'] . 'px' : $text_shadow_values['offsetX'];
								} else {
									$text_shadow[] = 0;
								}

								if ( ! empty( $text_shadow_values['offsetY'] ) ) {
									$text_shadow[] = is_numeric( $text_shadow_values['offsetY'] ) ? $text_shadow_values['offsetY'] . 'px' : $text_shadow_values['offsetY'];
								} else {
									$text_shadow[] = 0;
								}

								if ( ! empty( $text_shadow_values['blur'] ) ) {
									$text_shadow[] = is_numeric( $text_shadow_values['blur'] ) ? $text_shadow_values['blur'] . 'px' : $text_shadow_values['blur'];
								} else {
									$text_shadow[] = 0;
								}
							}

							$text_shadow_color = $setting_value['color'] ?? '';

							if ( $text_shadow_color ) {
								$color_code = self::generate_css_color( $text_shadow_color );

								if ( $color_code ) {
									$text_shadow[] = $color_code;
								}
							} else {
								$text_shadow[] = 'transparent';
							}

							$css_rules[ $css_selector ][] = 'text-shadow: ' . join( ' ', $text_shadow );
							break;

						case 'transform':
							$transform      = '';
							$scale3d_values = [];

							foreach ( $setting_value as $attribute => $value ) {
								// Collect scale3d values, then continue
								// because scale3d needs all three values together (@since 2.3)
								if ( strpos( $attribute, 'scale3d' ) === 0 ) {
									$scale3d_values[ $attribute ] = $value;
									continue;
								}

								switch ( $attribute ) {
									case 'translateX':
									case 'translateY':
										// Add default unit 'px' is number-only
										if ( is_numeric( $value ) && ! strpos( $value, 'var' ) && ! strpos( $value, 'calc' ) ) {
											$value .= 'px';
										}
										break;

									case 'perspective':
										// @since 2.3
										// Add default unit 'px' if number-only
										if ( is_numeric( $value ) && ! strpos( $value, 'px' ) && ! strpos( $value, 'var' ) && ! strpos( $value, 'calc' ) ) {
											$value .= 'px';
										}
										break;

									case 'rotateX':
									case 'rotateY':
									case 'rotateZ':
									case 'skewX':
									case 'skewY':
										// Remove unit, then add 'deg'
										$value  = intval( $value );
										$value .= 'deg';
										break;

								}

								// If $attribute is "perspective", it must be the first transform function (@since 2.3)
								if ( $attribute === 'perspective' ) {
									$transform = $attribute . "($value)" . ( $transform ? ' ' . $transform : '' );
								} else {
									$transform .= ' ' . $attribute . "($value)";
								}
							}

							// Add scale3d if at least one value is present (@since 2.3)
							if ( ! empty( $scale3d_values['scale3dX'] ) || ! empty( $scale3d_values['scale3dY'] ) || ! empty( $scale3d_values['scale3dZ'] ) ) {
								$x          = ! empty( $scale3d_values['scale3dX'] ) ? $scale3d_values['scale3dX'] : 1;
								$y          = ! empty( $scale3d_values['scale3dY'] ) ? $scale3d_values['scale3dY'] : 1;
								$z          = ! empty( $scale3d_values['scale3dZ'] ) ? $scale3d_values['scale3dZ'] : 1;
								$transform .= ' scale3d(' . $x . ', ' . $y . ', ' . $z . ')';
							}

							$css_rules[ $css_selector ][] = $css_property . ': ' . $transform;
							break;

						case 'typography':
							foreach ( $setting_value as $font_property => $font_value ) {
								switch ( $font_property ) {
									case 'color':
										$color_code = self::generate_css_color( $font_value );

										// Possible 'unset' value for dynamic color (@since 2.0)
										if ( $has_dynamic_value && empty( $color_code ) ) {
											$color_code = 'unset';
										}

										if ( $color_code ) {
											if ( $has_dynamic_value ) {
												self::$inline_css_dynamic_data .= $css_selector . $loop_index_selector . ' {color: ' . $color_code . ' } ';
											} else {
												$css_rules[ $css_selector . $loop_index_selector ][] = "color: $color_code";
											}
										}
										break;

									case 'font-family':
										// Check: Custom font (value syntax: 'custom_font_{id})
										$custom_font_id = strpos( $font_value, 'custom_font_' ) !== false ? filter_var( $font_value, FILTER_SANITIZE_NUMBER_INT ) : false;

										if ( $custom_font_id ) {
											$font_value = get_the_title( $custom_font_id );
										}

										// Check: Append fallback font (@since 1.5.1)
										$fallback_font = ! empty( $setting_value['fallback'] ) ? ", {$setting_value['fallback']}" : '';

										$font_family_value = self::format_font_family_value( $font_value );

										$css_rules[ $css_selector ][] = "$font_property: $font_family_value$fallback_font";
										break;

									case 'text-shadow':
										$text_shadow        = [];
										$text_shadow_values = $font_value['values'] ?? '';

										if ( $text_shadow_values ) {
											if ( ! empty( $text_shadow_values['offsetX'] ) ) {
												$text_shadow[] = is_numeric( $text_shadow_values['offsetX'] ) ? $text_shadow_values['offsetX'] . 'px' : $text_shadow_values['offsetX'];
											} else {
												$text_shadow[] = 0;
											}

											if ( ! empty( $text_shadow_values['offsetY'] ) ) {
												$text_shadow[] = is_numeric( $text_shadow_values['offsetY'] ) ? $text_shadow_values['offsetY'] . 'px' : $text_shadow_values['offsetY'];
											} else {
												$text_shadow[] = 0;
											}

											if ( ! empty( $text_shadow_values['blur'] ) ) {
												$text_shadow[] = is_numeric( $text_shadow_values['blur'] ) ? $text_shadow_values['blur'] . 'px' : $text_shadow_values['blur'];
											} else {
												$text_shadow[] = 0;
											}
										}

										$text_shadow_color = $font_value['color'] ?? '';

										if ( $text_shadow_color ) {
											$color_code = self::generate_css_color( $text_shadow_color );

											if ( $color_code ) {
												$text_shadow[] = $color_code;
											}
										} else {
											$text_shadow[] = 'transparent';
										}

										$css_rules[ $css_selector ][] = $font_property . ': ' . join( ' ', $text_shadow );
										break;

									default:
										if (
											! is_array( $font_value ) &&
											$font_property !== 'font-variants' &&
											$font_property !== 'fallback'
										) {
											if ( in_array( $font_property, [ 'font-size', 'letter-spacing' ] ) ) {
												// Numeric value: Append defaultUnit (px)
												if ( is_numeric( $font_value ) ) {
													$font_value .= 'px';
												}
											}

											$css_rules[ $css_selector ][] = "{$font_property}: {$font_value}";
										}
								}
							}
							break;

						default:
							if ( Capabilities::current_user_can_use_builder() ) {
								error_log( 'Error: Control type ' . $control_type . ' is not defined!' );
							}
							break;
					}
				}

				// String value (number, etc.)
				else {
					// ControlNumber
					if ( $control_type === 'number' ) {
						// Append unit (only once for each css_selector to avoid 'pxpx', etc.)
						if ( ! empty( $control['unit'] ) && ! strpos( $setting_value, $control['unit'] ) ) {
							$setting_value .= $control['unit'];
						}

						// Number + unit
						elseif ( ! empty( $control['units'] ) ) {
							// Unit missing: Append default unit (px)
							if ( is_numeric( $setting_value ) ) {
								$setting_value = $setting_value . 'px';
							}
						}
					}

					// Build CSS property for 'transform'
					if ( strlen( $css_property ) && strpos( $css_property, 'transform:' ) !== false ) {
						$transform_parts = explode( ':', $css_property );
						$css_property    = $transform_parts[0];
						$setting_value   = "$transform_parts[1]($setting_value)";
					}

					// Simple string CSS value

					// Invert gutter/spacing (image gallery, slider etc.)
					if ( $setting_value !== '' ) {
						if ( isset( $css_definition['invert'] ) ) {
							$css_rules[ $css_selector ][] = "$css_property: -$setting_value";
						} else {
							$css_rules[ $css_selector ][] = "$css_property: $setting_value";
						}
					}
				}

				// Append ' !important' to CSS rule
				if ( ! empty( $css_rules[ $css_selector ] ) ) {
					foreach ( $css_rules[ $css_selector ] as $index => $rule ) {
						if ( isset( $css_definition['important'] ) && ! strpos( $rule, '!important' ) ) {
							$css_rules[ $css_selector ][ $index ] .= ' !important';
						}
					}
				}
				// Retain every contributing origin, including unknown descendants introduced by
				// specialized generators. Mixed ownership makes the whole block ineligible.
				// Keep breakpoint keys inside 'rules' so user-defined keys cannot collide with metadata.
				if ( $rule_origins !== null ) {
					foreach ( $css_rules as $rule_selector => $declarations ) {
						if ( ( $previous_rules[ $rule_selector ] ?? null ) === $declarations ) {
							continue;
						}

						$origin = 'unknown';
						if ( $definition_origin === 'root' && $rule_selector === $definition_selector ) {
							$origin = 'root';
						} elseif ( $definition_origin === 'icon' && isset( $icon_selectors[ $rule_selector ] ) ) {
							$origin = 'icon';
						}
						$origin_breakpoint = $breakpoint !== '' ? $breakpoint : 'desktop';
						$rule_origins['rules'][ $origin_breakpoint ][ $rule_selector ][ $origin ] = true;
					}
				}
			}
		}

		// Add breakpoint-specific CSS string to css_type (content, theme_style, etc.)
		if ( $breakpoint ) {
			// Add CSS selector array to css_type and breakpoint
			if ( ! isset( self::$inline_css_breakpoints[ $css_type ][ $breakpoint ] ) ) {
				self::$inline_css_breakpoints[ $css_type ][ $breakpoint ] = [];
			}

			// CSS sync dedup: Filter breakpoint declarations that exist in _cssCustom (@since 2.4)
			if ( ! empty( $css_sync_exclusions ) && isset( $css_sync_exclusions[ $breakpoint ] ) ) {
				foreach ( $css_rules as $css_sel => $css_decls ) {
					if ( isset( $css_sync_exclusions[ $breakpoint ][ $css_sel ] ) ) {
						$css_rules[ $css_sel ] = self::filter_synced_declarations( $css_decls, $css_sync_exclusions[ $breakpoint ][ $css_sel ] );

						// Remove selector if all declarations were filtered out
						if ( empty( $css_rules[ $css_sel ] ) ) {
							unset( $css_rules[ $css_sel ] );
						}
					}
				}
			}

			if ( count( $css_rules ) ) {
				foreach ( $css_rules as $css_selector => $css_declarations ) {
					// Remove duplicate CSS declarations
					$css_declarations = array_unique( $css_declarations, SORT_STRING );

					if ( Query::is_looping() ) {
						// Loop item styles are identical per breakpoint, so only queue each selector/declaration set once (@since 2.4)
						$loop_breakpoint_selector_key = "$css_type|$breakpoint|$css_selector|" . implode( ';', $css_declarations );

						if ( in_array( $loop_breakpoint_selector_key, self::$generated_loop_common_selectors_breakpoints, true ) ) {
							continue;
						}

						self::$generated_loop_common_selectors_breakpoints[] = $loop_breakpoint_selector_key;
					}

					if ( ! isset( self::$inline_css_breakpoints[ $css_type ][ $breakpoint ][ $css_selector ] ) ) {
						self::$inline_css_breakpoints[ $css_type ][ $breakpoint ][ $css_selector ] = [];
					}

					self::$inline_css_breakpoints[ $css_type ][ $breakpoint ][ $css_selector ] = array_merge( self::$inline_css_breakpoints[ $css_type ][ $breakpoint ][ $css_selector ], $css_declarations );
				}
			}

			/**
			 * Add plain CSS: _cssCustom but skip 'breakpoints' controls like 'slidesToShow', etc.
			 *
			 * @since 1.10: Add breakpoint-specific page setting custom CSS ('customCss')
			 */
			elseif (
				! count( $css_rules ) &&
				is_string( $setting_value ) &&
				strpos( $setting_value, '{' ) !== false
				&& ( ! isset( $control['breakpoints'] ) || $css_type === 'page' )
			) {
				if ( Query::is_looping() ) {
					// Breakpoint custom CSS in loops is static too, so skip repeated loop iterations (@since 2.4)
					$loop_breakpoint_custom_css_key = "$css_type|$breakpoint|_cssCustom|$selector|$setting_value";

					if ( in_array( $loop_breakpoint_custom_css_key, self::$generated_loop_common_selectors_breakpoints, true ) ) {
						return [];
					}

					self::$generated_loop_common_selectors_breakpoints[] = $loop_breakpoint_custom_css_key;
				}

				if ( ! isset( self::$inline_css_breakpoints[ $css_type ][ $breakpoint ]['_cssCustom'] ) ) {
					self::$inline_css_breakpoints[ $css_type ][ $breakpoint ]['_cssCustom'] = '';
				}

				self::$inline_css_breakpoints[ $css_type ][ $breakpoint ]['_cssCustom'] .= $setting_value;
			}

			return [];
		}

		return $css_rules;
	}

	/**
	 * Generate CSS string
	 *
	 * Component Buttons can omit unchanged, independently ordered rule groups.
	 * Unknown ownership or overlapping authored CSS retains full-block generation.
	 *
	 * @since 2.4.3 Added component rule origin tracking and ordered group deduplication.
	 *
	 * @param array  $element Array containing all element data (to retrieve element settings and name).
	 * @param array  $controls Array containing all element controls (to retrieve CSS selectors and properties).
	 * @param string $css_type String global/page/header/content/footer/mobile.
	 *
	 * @return string (use & process asset-optimization)
	 */
	public static function generate_inline_css_from_element( $element, $controls, $css_type ) {
		$settings                         = ! empty( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
		$element_id                       = $element['id'] ?? '';
		self::$current_generating_element = $element;

		// Update scroll snap selector in page settings
		if ( $css_type === 'page' && isset( $controls['scrollSnapType']['css'] ) ) {
			// Controls that apply CSS to the snapping elements (default: .brxe-section)
			// These control the behavior of individual elements that snap into view
			$snapping_element_controls = [ 'scrollSnapAlign', 'scrollMargin', 'scrollSnapStop' ];

			if ( isset( $settings['scrollSnapSelector'] ) ) {
				// Replace default .brxe-section selector with user's custom selector for snapping elements (@since 1.12.2)
				foreach ( $snapping_element_controls as $control_key ) {
					if ( isset( $controls[ $control_key ]['css'] ) ) {
						foreach ( $controls[ $control_key ]['css'] as &$css_rule ) {
							if ( isset( $css_rule['selector'] ) && $css_rule['selector'] === '.brxe-section' ) {
								$css_rule['selector'] = $settings['scrollSnapSelector'];
							}
						}
					}
				}

				if ( isset( $settings['scrollSnapAlign'] ) ) {
					// Remove the default scroll-snap-align rule from scrollSnapType when custom selector is defined (@since 1.10)
					$controls['scrollSnapType']['css'] = array_filter(
						$controls['scrollSnapType']['css'],
						function( $css_rule ) {
							return ! ( isset( $css_rule['selector'] ) && $css_rule['selector'] === '.brxe-section' );
						}
					);
				} else {
					// Just update the selector if scrollSnapAlign is not set (@since 1.12.2)
					foreach ( $controls['scrollSnapType']['css'] as &$css_rule ) {
						if ( isset( $css_rule['selector'] ) && $css_rule['selector'] === '.brxe-section' ) {
							$css_rule['selector'] = $settings['scrollSnapSelector'];
						}
					}
				}
			} elseif ( isset( $settings['scrollSnapAlign'] ) ) {
				// Remove the default scroll-snap-align rule from scrollSnapType (@since 1.12.2)
				$controls['scrollSnapType']['css'] = array_filter(
					$controls['scrollSnapType']['css'],
					function( $css_rule ) {
						return ! ( isset( $css_rule['selector'] ) && $css_rule['selector'] === '.brxe-section' );
					}
				);
			}
		}

		// STEP: Generate CSS selector
		$css_selector = '';

		// Is component: Set selector to component root or child ID (@since 1.12)
		$component_id = $element['cid'] ?? $element['parentComponent'] ?? false;

		if ( $component_id ) {
			$css_selector = ! empty( $element['cid'] ) ? ".brxe-{$element['cid']}" : ".brxe-{$element['id']}";

			// Check for component element "selectors" (@since 2.1)
			$component_element = Helpers::get_component_element_by_id( $component_id );
			if ( ! empty( $element['cid'] ) && ! empty( $component_element['selectors'] ) ) {
				$element['selectors'] = $component_element['selectors'];
			}

			/**
			 * Icon and Video controls generate descendant selectors. Prefer the full nested
			 * render path so repeated component siblings cannot match each other's rules.
			 *
			 * #86cb2khh3; @since 2.3.12
			 */
			if ( in_array( $element['name'], [ 'icon', 'video' ], true ) ) {
				$instance_id = $element['cssInstanceId'] ?? false;
				if ( ! $instance_id ) {
					$instance_id = isset( $element['cid'] ) ? $element['id'] : $element['instanceId'];
				}

				$css_selector .= '.brxi-' . $instance_id;
			}
		}

		elseif ( $element_id ) {
			// Check if user has set a custom CSS ID
			$element_attribute_id = Helpers::get_element_attribute_id( $element_id, $settings );

			// Global element: Use CSS class (to apply styles to every occurence of this global element)
			$global_element_id = Helpers::get_global_element( $element, 'global' );

			if ( $global_element_id ) {
				$css_selector = ".brxe-{$global_element_id}";
			}

			// Element in loop selector
			elseif ( Query::is_any_looping() ) {
				$looping_query_id = Query::is_any_looping();
				$loop_element_id  = Query::get_query_element_id( $looping_query_id );
				// Combine loop element ID, element ID, and loop index (enable multiple query loops containing the same template element
				$loop_style_key = $loop_element_id . $element_id . Query::get_loop_index( $looping_query_id );

				// Is nested query: Append parent loop element ID and parent loop index (@since 1.10)
				if ( Query::get_looping_level() > 0 ) {
					$parent_loop_id  = Query::get_parent_loop_id();
					$loop_style_key .= Query::get_query_element_id( $parent_loop_id ) . Query::get_loop_index( $parent_loop_id );
				}

				// CSS is identical for every loop item (except DD featured image)
				if ( ! in_array( $loop_style_key, self::$css_looping_elements ) ) {
					self::$css_looping_elements[] = $loop_style_key;

					// Using custom CSS id or default id
					$css_selector = ".{$element_attribute_id}";

					// Prefix selector with loop element ID to precede default element styles
					// (as we uses CSS classes instead of element ID inside a query loop)
					if ( $loop_element_id && $loop_element_id !== $element_id ) {
						$css_selector = ".brxe-{$loop_element_id} $css_selector";
					}

					// Append element name CSS class (to ensure query loop CSS styles precede default CSS like .brxe-container "width: 1100px", etc.)
					$css_selector .= ".brxe-{$element['name']}";
				}

				// Return: No need to generate CSS for this element in the loop (@since 1.5)
				else {
					return;
				}
			}

			/**
			 * Slides of 'slider-nested' element: Use 'data-id' as CSS selector to target cloned slides too (splide padding, etc.)
			 *
			 * .splide__slide selector needed to on frontend for specificity.
			 *
			 * @since 1.5.1
			 */
			elseif ( ! empty( $element['parent'] ) && isset( self::$elements[ $element['parent'] ]['name'] ) && self::$elements[ $element['parent'] ]['name'] === 'slider-nested' ) {
				$css_selector = "[data-id=\"{$element_attribute_id}\"].splide__slide";
			}

			// Some Woo elements replace the normal Bricks ID with an ID required by WooCommerce.
			// Target their persistent Bricks class so generated styles still match. (#86cavrwb8; @since 2.4)
			elseif ( self::uses_element_class_selector( $element, $settings ) ) {
				$css_selector = ".brxe-{$element_id}";
			}

			// Default (custom CSS ID or the default brxe-)
			else {
				$css_selector = "#$element_attribute_id";
			}
		}

		// STEP: Prepend global class name
		if ( ! empty( $element['_cssGlobalClass'] ) ) {
			$css_selector = ".{$element['_cssGlobalClass']}";

			// Append element/class.selector (@since 2.0)
			if ( ! empty( $element['_selector'] ) ) {
				$css_selector .= strpos( $element['_selector'], ':' ) === 0 ? "{$element['_selector']}" : " {$element['_selector']}";
			}

			// Append element name CSS class, if class chaining is not disabled (= default)
			elseif ( ! Database::get_setting( 'disableClassChaining' ) ) {
				$css_selector .= ".brxe-{$element['name']}";
			}
		}

		// STEP: Selector is for a specific template setting
		if ( ! empty( $element['_templateCssSelector'] ) ) {
			$css_selector = $element['_templateCssSelector'];
		}

		$css_rules  = [];
		$inline_css = '';

		/**
		 * STEP: Get global element settings (inline CSS loading method only)
		 *
		 * External files: Use global-elements.min.css to reflect global element changes everywhere.
		 */
		if ( Database::get_setting( 'cssLoading' ) !== 'file' ) {
			$global_elements = is_array( Database::$global_data['elements'] ) ? Database::$global_data['elements'] : [];
			foreach ( $global_elements as $global_element ) {
				// @since 1.2.1
				if ( ! empty( $global_element['global'] ) && ! empty( $element['global'] ) && $global_element['global'] === $element['global'] ) {
					$settings   = ! empty( $global_element['settings'] ) ? $global_element['settings'] : [];
					$element_id = $global_element['global'];
				}

				// @pre 1.2.1
				elseif ( ! empty( $global_element['id'] ) && $global_element['id'] === $element_id ) {
					$settings   = ! empty( $global_element['settings'] ) ? $global_element['settings'] : [];
					$element_id = $global_element['global'];
				}
			}
		}

		// Increase specificity of popup CSS selectors for inside query loop
		if ( Api::is_current_endpoint( 'load_popup_content' ) ) {
			// Wrong selector if not looping (#86bx46frm; @since 1.12)
			$css_selector = Query::is_any_looping() ? ".brx-popup$css_selector" : ".brx-popup $css_selector";
		}

		// CSS sync dedup: Build exclusion map from _cssCustom to skip duplicate control CSS (@since 2.4)
		$css_sync_exclusions = [];

		if ( Database::get_setting( 'builderCssSync', false ) ) {
			// Base (desktop) _cssCustom
			if ( ! empty( $settings['_cssCustom'] ) ) {
				$custom_css              = self::normalize_element_custom_css( $settings['_cssCustom'], $element_id, $settings, $element );
				$css_sync_exclusions[''] = self::extract_custom_css_properties( $custom_css );
			}

			// Breakpoint-specific _cssCustom (e.g., _cssCustom:tablet_portrait)
			foreach ( Breakpoints::$breakpoints as $bp ) {
				$bp_custom_key = "_cssCustom:{$bp['key']}";

				if ( ! empty( $settings[ $bp_custom_key ] ) ) {
					$custom_css                        = self::normalize_element_custom_css( $settings[ $bp_custom_key ], $element_id, $settings, $element );
					$css_sync_exclusions[ $bp['key'] ] = self::extract_custom_css_properties( $custom_css );
				}
			}
		}

		// Limit optimization to component Buttons, including nested Buttons. Loops,
		// global classes, and theme styles keep their existing CSS ordering rules.
		$background_images       = [];
		$component_css_candidate = $component_id && $element['name'] === 'button' && $css_type !== 'theme_style' && empty( $element['_cssGlobalClass'] ) && ! Query::is_any_looping();
		// Once groups exist, track intervening elements too: their rules may invalidate
		// suppression. Otherwise avoid bookkeeping for unrelated elements.
		$rule_origins = $component_css_candidate || self::$component_css_groups ? [] : null;

		// STEP: Generate CSS rules array of every element setting
		foreach ( $settings as $setting_key => $setting_value ) {
			// Breakpoint custom CSS is emitted directly by generate_css_rules_from_setting().
			// Normalize it here because it bypasses the base and variant custom CSS paths below. (#86cavrwb8; @since 2.4)
			if (
				strpos( $setting_key, '_cssCustom:' ) === 0 &&
				strpos( $setting_key, '_cssCustom:variant-' ) !== 0
			) {
				$setting_value = self::normalize_element_custom_css( $setting_value, $element_id, $settings, $element );
			}

			$is_component_root = ! empty( $element['cid'] ) || ! empty( $element['_isComponentRoot'] );
			$setting_css_rules = self::generate_css_rules_from_setting( $settings, $setting_key, $setting_value, $controls, $css_selector, $css_type, $is_component_root, $css_sync_exclusions, $background_images, $rule_origins );

			// Add new CSS rules to existing ones
			if ( is_array( $setting_css_rules ) ) {
				foreach ( $setting_css_rules as $selector => $new_rules ) {
					if ( isset( $css_rules[ $selector ] ) ) {
						$css_rules[ $selector ] = array_merge( $css_rules[ $selector ], $new_rules );
					} else {
						$css_rules[ $selector ] = $new_rules;
					}
				}
			}
		}

		/**
		 * STEP: Add element.selectors settings
		 *
		 * @since 2.0
		 */
		$element_selectors = $element['selectors'] ?? [];

		foreach ( $element_selectors as $selector ) {
			$selector_settings = $selector['settings'] ?? false;
			$selector_selector = $selector['selector'] ?? '';

			// Starts with pseudo: Remove space between element ID and selector
			if ( strpos( $selector_selector, ':' ) === 0 ) {
				$selector_selector = '&' . $selector_selector;
			}

			if ( $selector_settings ) {
				$element_controls = $controls;
				// Set all control.css selectors to the element selector
				foreach ( $element_controls as $control_key => $control ) {
					if ( ! empty( $control['css'] ) ) {
						foreach ( $control['css'] as $css_index => $css_rule ) {
							$element_controls[ $control_key ]['css'][ $css_index ]['selector'] = $selector_selector;
						}
					}
				}

				foreach ( $selector_settings as $selector_setting_key => $selector_setting_value ) {
					$selector_css_rules = self::generate_css_rules_from_setting( $selector_settings, $selector_setting_key, $selector_setting_value, $element_controls, $css_selector, $css_type, false, $css_sync_exclusions, $background_images );

					// Add new CSS rules to existing ones
					if ( is_array( $selector_css_rules ) ) {
						foreach ( $selector_css_rules as $selector => $new_rules ) {
							if ( isset( $css_rules[ $selector ] ) ) {
								$css_rules[ $selector ] = array_merge( $css_rules[ $selector ], $new_rules );
							} else {
								$css_rules[ $selector ] = $new_rules;
							}
						}
					}
				}
			}
		}

		// Replay only mixed image sources in the existing breakpoint order; static-only CSS stays unchanged.
		foreach ( $background_images as $background_image ) {
			if ( empty( $background_image['dynamic'] ) || empty( $background_image['static'] ) ) {
				continue;
			}

			$image_css = [];
			foreach ( $background_image['values'] as $image_breakpoint => $image_value ) {
				// A static base already precedes authored CSS and needs no replay when dynamic images are media-bound.
				if ( $image_breakpoint === Breakpoints::$base_key && empty( $background_image['dynamic_base'] ) ) {
					continue;
				}

				$image_css[ $image_breakpoint ] = $background_image['selector'] . ' {background-image: ' . $image_value . '}';
			}

			self::$inline_css_dynamic_data .= self::generate_inline_css_for_breakpoints( $css_type, $image_css['desktop'] ?? '', $image_css );
		}

		// Contextual spacing (@since 2.0)
		if ( $css_type === 'theme_style' ) {
			// Get user-defined selectors from contextualSpacingApplyTo
			$user_selectors_string = isset( $settings['contextualSpacingApplyTo'] ) ? $settings['contextualSpacingApplyTo'] : '';
			if ( $user_selectors_string ) {
				$user_selectors = explode( ',', $user_selectors_string );

				foreach ( $user_selectors as $user_selector ) {
					// Heading spacing
					$heading_spacing = isset( $settings['contextualSpacingHeading'] ) ? $settings['contextualSpacingHeading'] : '';
					if ( $heading_spacing ) {
						// Add default unit 'px' if numeric value
						if ( is_numeric( $heading_spacing ) ) {
							$heading_spacing .= 'px';
						}
						$css_rules[ $user_selector . ' :is(h1, h2, h3, h4, h5, h6):not(:first-child)' ] = [ "margin-block-start: {$heading_spacing}" ];
					}

					// Paragraph spacing
					$paragraph_spacing = isset( $settings['contextualSpacingParagraph'] ) ? $settings['contextualSpacingParagraph'] : '';
					if ( $paragraph_spacing ) {
						// Add default unit 'px' if numeric value
						if ( is_numeric( $paragraph_spacing ) ) {
							$paragraph_spacing .= 'px';
						}
						$css_rules[ $user_selector . ' p:not(:first-child)' ] = [ "margin-block-start: {$paragraph_spacing}" ];
					}

					// Fallback spacing
					$fallback_spacing = isset( $settings['contextualSpacingFallback'] ) ? $settings['contextualSpacingFallback'] : '';
					if ( $fallback_spacing ) {
						// Add default unit 'px' if numeric value
						if ( is_numeric( $fallback_spacing ) ) {
							$fallback_spacing .= 'px';
						}
						$css_rules[ $user_selector . ' > * + *' ] = [ "margin-block-start: {$fallback_spacing}" ];
					}
				}
			}
		}

		// CSS sync dedup: Filter desktop control declarations that exist in _cssCustom (@since 2.4)
		if ( ! empty( $css_sync_exclusions[''] ) ) {
			foreach ( $css_rules as $sel => &$decls ) {
				if ( isset( $css_sync_exclusions[''][ $sel ] ) ) {
					$decls = self::filter_synced_declarations( $decls, $css_sync_exclusions[''][ $sel ] );
				}
			}

			unset( $decls );

			// Remove selectors whose declarations were fully filtered out
			$css_rules = array_filter(
				$css_rules,
				function ( $decls ) {
					return ! empty( $decls );
				}
			);
		}

		// STEP: Generate inline CSS (string)
		foreach ( $css_rules as $css_selector => $css_declarations ) {
			if ( Query::is_looping() ) {
				// Skip generation if css_selector generated before to avoid duplicate CSS (@since 1.8)
				if ( in_array( $css_selector, self::$generated_loop_common_selectors ) ) {
					continue;
				}

				// Add to generated unique selectors
				self::$generated_loop_common_selectors[] = $css_selector;
			}

			// Remove duplicate CSS declarations
			$css_declarations = array_unique( $css_declarations, SORT_STRING );

			// Order CSS declarations
			$css_declarations = array_values( $css_declarations );

			$inline_css .= $css_selector . ' {' . join( '; ', $css_declarations ) . '}' . PHP_EOL;
		}

		// STEP: Append custom CSS (string)
		$custom_css = '';
		// Retain the exact prepared text and order, including variant overrides.
		// Authored CSS belongs to the shared group only after an independence check.
		$authored_css = '';

		// Global & page settings: Custom CSS
		if ( ! empty( $settings['customCss'] ) ) {
			$custom_css = $settings['customCss'];
		}

		// Element: Custom CSS (if looping, render custom_css for loop index = 0 only)
		if ( ! empty( $settings['_cssCustom'] ) ) {
			$custom_css = $settings['_cssCustom'];
			$custom_css = self::normalize_element_custom_css( $custom_css, $element_id, $settings, $element );

			if ( Query::is_looping() ) {
				static $element_custom_css = [];

				$loop_element_id = Query::get_query_element_id();

				// Combine the loop element ID with the element id - enable multiple query loops containing the same template element (@since 1.5)
				$loop_style_key = $loop_element_id . $element_id;

				// CSS is identical for every loop item: Skip custom CSS for 2nd+ element (@since 1.5.1)
				if ( in_array( $loop_style_key, $element_custom_css ) ) {
					$custom_css = '';
				} else {
					$element_custom_css[] = $loop_style_key;
				}
			}

			if ( $custom_css ) {
				$custom_css = str_replace( [ "\r","\n" ], '', $custom_css );
				$custom_css = str_replace( '  ', ' ', $custom_css );
				$custom_css = str_replace( '}.', '} .', $custom_css );
			}
		}

		// Parse CSS
		$custom_css = Helpers::parse_css( $custom_css );

		if ( $custom_css ) {
			$inline_css   .= $custom_css . PHP_EOL;
			$authored_css .= $custom_css . PHP_EOL;
		}

		// Component variant custom CSS (@since 2.2)
		// Process variant-specific _cssCustom:variant-{id} settings separately
		foreach ( $settings as $setting_key => $setting_value ) {
			// ONLY process variant-specific custom CSS (skip base _cssCustom)
			if ( strpos( $setting_key, '_cssCustom:variant-' ) === 0 && is_string( $setting_value ) && ! empty( $setting_value ) ) {
				$variant_css = $setting_value;
				$variant_css = self::normalize_element_custom_css( $variant_css, $element_id, $settings, $element );

				if ( $variant_css ) {
					$variant_css = str_replace( [ "\r", "\n" ], '', $variant_css );
					$variant_css = str_replace( '  ', ' ', $variant_css );
					$variant_css = str_replace( '}.', '} .', $variant_css );

					// Parse and append
					$parsed_variant_css = Helpers::parse_css( $variant_css );
					if ( $parsed_variant_css ) {
						$inline_css   .= $parsed_variant_css . PHP_EOL;
						$authored_css .= $parsed_variant_css . PHP_EOL;
					}
				}
			}
		}

		if ( ! isset( self::$inline_css[ $css_type ] ) ) {
			self::$inline_css[ $css_type ] = '';
		}

		// Capture candidate rules before breakpoint serialization consumes the queue.
		// Keep base authored CSS after generated base rules, as in the original output.
		$component_rules = [];
		if ( $component_css_candidate ) {
			$component_rules = [ 'desktop' => $css_rules ] + ( self::$inline_css_breakpoints[ $css_type ] ?? [] );
			if ( $authored_css !== '' ) {
				$component_rules['desktop']['_cssCustom'] = $authored_css;
			}
		}

		// STEP: Add breakpoint CSS
		if ( $css_type !== 'theme_style' ) {
			$inline_css = self::generate_inline_css_for_breakpoints( $css_type, $inline_css ) . PHP_EOL;
		}

		// Is loop OR component child (@since 1.12)
		if ( Query::is_looping() || ! empty( $element['parentComponent'] ) ) {
			$inline_css = str_replace( "#brxe-$element_id", ".brxe-$element_id", $inline_css );
			// Classification and reserialization must use the same child class selectors
			// as the original output, including selectors inside authored breakpoint CSS.
			foreach ( $component_rules as &$breakpoint_rules ) {
				if ( isset( $breakpoint_rules['_cssCustom'] ) ) {
					$breakpoint_rules['_cssCustom'] = str_replace( "#brxe-$element_id", ".brxe-$element_id", $breakpoint_rules['_cssCustom'] );
				}
			}
			unset( $breakpoint_rules );
		}

		// Return: No inline CSS
		if ( ! $inline_css ) {
			return '';
		}

		// Return: Inline CSS is not unique (@since 1.8)
		if ( $css_type !== 'theme_style' && in_array( $inline_css, self::$unique_inline_css ) ) {
			return '';
		}

		// Preserve the complete block for legacy duplicate tracking and safe fallbacks.
		$source_css       = $inline_css;
		$component_groups = null;

		// Intervening CSS that might overlap a component makes previous groups stale.
		// Clear conservatively: keeping extra CSS is safer than removing a needed override.
		if ( $element_selectors ) {
			self::$component_css_groups = [];
		} elseif ( $component_css_candidate ) {
			$component_selector = ! empty( $element['cid'] ) ? ".brxe-{$element['cid']}" : ".brxe-{$element['id']}";
			$component_groups   = Component_Css::group_rules( $component_rules, $rule_origins, $component_selector );

			if ( $component_groups !== null ) {
				$inline_css = self::deduplicate_component_css( $component_rules, $component_groups, $component_selector, $css_type, $source_css );
				// Generated declaration values can also contain child IDs; normalize rebuilt output.
				if ( ! empty( $element['parentComponent'] ) ) {
					$inline_css = str_replace( "#brxe-$element_id", ".brxe-$element_id", $inline_css );
				}
			} else {
				self::$component_css_groups = [];
			}
		} else {
			// Explicit control targets and loop/component descendants may overlap an earlier group.
			if ( ! empty( $rule_origins['external'] ) || ! empty( $element['_cssGlobalClass'] ) || Query::is_any_looping() ) {
				self::$component_css_groups = [];
			}
			if ( $component_id && $rule_origins !== null ) {
				// Unclassified component descendants may share selectors with earlier Button styles.
				foreach ( $rule_origins['rules'] ?? [] as $selectors ) {
					foreach ( $selectors as $origins ) {
						if ( isset( $origins['unknown'] ) ) {
							self::$component_css_groups = [];
						}
					}
				}
			}
			// Authored CSS on another element can target any previously emitted component.
			foreach ( $settings as $setting_key => $setting_value ) {
				if ( ( $setting_key === 'customCss' || strpos( $setting_key, '_cssCustom' ) === 0 ) && ! empty( $setting_value ) ) {
					self::$component_css_groups = [];
					break;
				}
			}
		}

		// Integrations expect complete original blocks in this public tracker. Group
		// entries consult it too, so clearing the public cache restores required styles.
		self::$unique_inline_css[] = $source_css;

		// If both groups were already emitted, do not append a whitespace-only block.
		if ( $component_groups !== null && trim( $inline_css ) === '' ) {
			return '';
		}

		// Add unique inline CSS by css_type to inline CSS
		// Group filtering may intentionally replay A after B; substring suppression
		// would discard that required replay. Keep the legacy shortcut for other CSS.
		if ( $component_groups !== null || strpos( self::$inline_css[ $css_type ], $inline_css ) === false ) {
			self::$inline_css[ $css_type ] .= $inline_css;
		}

		// Return: Inline CSS of individual element, global class, etc.
		return $inline_css;
	}

	/**
	 * Filter complete ordered component groups before serializing their remaining rules.
	 *
	 * Track the original block in the public duplication cache so integrations that
	 * clear or restore that cache also control the lifetime of these groups.
	 *
	 * @since 2.4.3
	 *
	 * @param array  $rules Rules keyed by breakpoint and selector; _cssCustom holds unchanged authored text.
	 * @param array  $groups Shared/icon groups containing ordered breakpoint, selector, and declaration arrays.
	 * @param string $selector Runtime component selector used as the cache key.
	 * @param string $css_type Output context passed to the existing breakpoint serializer.
	 * @param string $source_css Original complete element CSS.
	 * @return string Remaining CSS, preserving breakpoint and declaration order.
	 */
	private static function deduplicate_component_css( $rules, $groups, $selector, $css_type, $source_css ) {
		$filtered = false;
		foreach ( $groups as $origin => $group_rules ) {
			if ( ! $group_rules ) {
				continue;
			}

			// Compare the complete ordered group with its last emitted version. Historical
			// membership alone would remove the final A in A/B/A and change the cascade.
			$previous = self::$component_css_groups[ $selector ][ $origin ] ?? [];
			if ( ( $previous['rules'] ?? null ) === $group_rules && in_array( $previous['source'] ?? null, self::$unique_inline_css, true ) ) {
				foreach ( $group_rules as $breakpoint => $selectors ) {
					foreach ( $selectors as $rule_selector => $declarations ) {
						unset( $rules[ $breakpoint ][ $rule_selector ] );
						$filtered = true;
					}
				}
			} else {
				self::$component_css_groups[ $selector ][ $origin ] = [
					'rules'  => $group_rules,
					'source' => $source_css
				];
			}
		}

		// Avoid rebuilding untouched blocks, preserving their original formatting and bytes.
		if ( ! $filtered ) {
			return $source_css;
		}

		// Rebuild only after removing complete groups. Match the original declaration
		// serialization and leave authored text in its original position without rewriting it.
		$desktop_css = '';
		foreach ( $rules['desktop'] ?? [] as $rule_selector => $declarations ) {
			$desktop_css .= $rule_selector === '_cssCustom' ? $declarations : $rule_selector . ' {' . join( '; ', array_values( array_unique( $declarations, SORT_STRING ) ) ) . '}' . PHP_EOL;
		}

		// Explicit rules avoid consuming the shared queue twice and preserve mobile-first media order.
		return self::generate_inline_css_for_breakpoints( $css_type, $desktop_css, $rules ) . PHP_EOL;
	}

	/**
	 * Normalize element custom CSS to the runtime root selector.
	 *
	 * @since 2.3.3
	 *
	 * @param string $custom_css Raw custom CSS.
	 * @param string $element_id Element ID.
	 * @param array  $settings Element settings.
	 * @param array  $element Element data.
	 *
	 * @return string
	 */
	public static function normalize_element_custom_css( $custom_css, $element_id, $settings, $element = [] ) {
		if ( ! is_string( $custom_css ) || $custom_css === '' ) {
			return $custom_css;
		}

		$stored_root_selector  = "#brxe-$element_id";
		$runtime_root_selector = Query::is_looping() ? ".brxe-$element_id" : $stored_root_selector;

		if ( ! empty( $settings['_cssId'] ) ) {
			$stored_root_selector = '#' . $settings['_cssId'];

			if ( ! Query::is_looping() ) {
				$runtime_root_selector = '#' . Helpers::get_element_attribute_id( $element_id, $settings );
			}
		}

		// Custom CSS is stored against the editor ID, but native-ID elements expose only
		// this Bricks class at runtime. (#86cavrwb8; @since 2.4)
		if ( self::uses_element_class_selector( $element, $settings ) ) {
			$runtime_root_selector = ".brxe-$element_id";
		}

		if ( $stored_root_selector !== $runtime_root_selector ) {
			$custom_css = str_replace( $stored_root_selector, $runtime_root_selector, $custom_css );
		}

		return $custom_css;
	}

	/**
	 * Whether an element keeps a WooCommerce-required root ID.
	 *
	 * These elements cannot render the normal Bricks ID. Keeping this decision in one place
	 * ensures generated CSS and custom CSS normalization use the same class selector.
	 *
	 * (#86cavrwb8; @since 2.4)
	 *
	 * @param array $element Element data.
	 * @param array $settings Element settings.
	 *
	 * @return bool
	 */
	private static function uses_element_class_selector( $element, $settings ) {
		$element_name = $element['name'] ?? '';

		// These elements always replace #brxe-{id} with a fixed WooCommerce ID.
		// Their unique .brxe-{id} class is therefore the only persistent selector shared by builder and frontend markup.
		if ( in_array( $element_name, [ 'woocommerce-form-field', 'woocommerce-checkout-order-summary' ], true ) ) {
			return true;
		}

		// The generic Form Checkbox normally keeps its Bricks ID. Only the shipping-address variant
		// must expose WooCommerce's #ship-to-different-address fragment target instead.
		return $element_name === 'form-checkbox' && ( $settings['wooFields'] ?? '' ) === 'shipAddress';
	}

	/**
	 * Generate inline CSS for breakpoints of specific type (content, theme_style, etc.)
	 *
	 * Explicit breakpoint rules leave the queued stylesheet declarations intact.
	 *
	 * @param string     $css_type Stylesheet context.
	 * @param string     $desktop_css CSS for the desktop breakpoint.
	 * @param array|null $breakpoint_rules Explicit rules keyed by breakpoint, or null to use queued stylesheet declarations.
	 *
	 * @since 1.3.5
	 * @since 2.4.3 Consume paused breakpoint entries when serializing queued rules.
	 *
	 * @return string
	 */
	public static function generate_inline_css_for_breakpoints( $css_type, $desktop_css, $breakpoint_rules = null ) {
		$breakpoints = Breakpoints::get_breakpoints();
		$base_width  = Breakpoints::$base_width;
		$inline_css  = '';

		foreach ( $breakpoints as $index => $breakpoint ) {
			$key = ! empty( $breakpoint['key'] ) ? $breakpoint['key'] : false;

			// Paused rules must not leak into the next element's origin classification.
			// Only consume the shared queue on its normal path; explicit rule sets leave it intact.
			if ( isset( $breakpoint['paused'] ) ) {
				if ( $breakpoint_rules === null && $key !== false ) {
					unset( self::$inline_css_breakpoints[ $css_type ][ $key ] );
				}
				continue;
			}

			$label = ! empty( $breakpoint['label'] ) ? $breakpoint['label'] : $key;
			$width = ! empty( $breakpoint['width'] ) ? $breakpoint['width'] : false;
			$value = $breakpoint_rules !== null ? ( $breakpoint_rules[ $key ] ?? false ) : ( self::$inline_css_breakpoints[ $css_type ][ $key ] ?? false );

			if ( $key === 'desktop' ) {
				$value = $desktop_css;
			}

			/**
			 * Breakpoint CSS
			 *
			 * key: CSS selector
			 * value: CSS rules
			 *
			 * @since 1.8.2
			 */
			if ( is_array( $value ) ) {
				$css_rules_per_css_selector = '';

				foreach ( $value as $css_selector => $css_rules ) {
					// Custom CSS (no CSS selector, all in one string)
					if ( $css_selector === '_cssCustom' ) {
						$css_rules_per_css_selector .= '/* CUSTOM CSS */' . PHP_EOL . $css_rules . PHP_EOL;
					}
					// CSS selector with CSS rules (rules = array)
					else {
						$css_rules_per_css_selector .= $css_selector . ' {' . join( '; ', $css_rules ) . '}' . PHP_EOL;
					}
				}

				$value = $css_rules_per_css_selector;
			}

			// Returtn: No CSS rules
			if ( empty( $value ) ) {
				continue;
			}

			// Skip adding @media rule for custom base breakpoint
			$is_base_breakpoint = isset( $breakpoint['base'] );

			if ( $is_base_breakpoint ) {
				$label .= ' (BASE)';
			}

			/**
			 * Larger than base breakpoint:  use 'min-width'
			 * Smaller than base breakpoint: use 'max-width'
			 */
			$breakpoint_css = "\n/* BREAKPOINT: $label */\n";

			if ( ! $is_base_breakpoint ) {
				$breakpoint_css .= $width > $base_width ? "@media (min-width: {$width}px) {\n" : "@media (max-width: {$width}px) {\n";
			}

			$breakpoint_css .= $value;

			if ( ! $is_base_breakpoint ) {
				$breakpoint_css .= '}';
			}

			// Is base breakpoint, but not mobile-frist: Add first
			if ( ! Breakpoints::$is_mobile_first && ( $is_base_breakpoint || $width > $base_width ) ) {
				$inline_css = $breakpoint_css . $inline_css;
			}

			// Not the base breakpoint: Append (as @media rules need to come last)
			else {
				$inline_css .= $breakpoint_css;
			}

			// Explicit rule sets must not consume the ordinary breakpoint styles.
			if ( $breakpoint_rules === null ) {
				// Clear breakpoint value to avoid generating duplicates (see: theme styles)
				unset( self::$inline_css_breakpoints[ $css_type ][ $key ] );
			}
		}

		return $inline_css;
	}

	/**
	 * Get @media rule for specific breakpoint
	 *
	 * @param string $bp The breakpoint key to return the @media rule for.
	 *
	 * @since 1.7.2
	 */
	public static function get_at_media_rule_for_breakpoint( $bp ) {
		$breakpoints = Breakpoints::get_breakpoints();
		$base_width  = Breakpoints::$base_width;
		$inline_css  = '';

		foreach ( $breakpoints as $breakpoint ) {
			$key   = ! empty( $breakpoint['key'] ) ? $breakpoint['key'] : false;
			$width = ! empty( $breakpoint['width'] ) ? $breakpoint['width'] : false;

			if ( $key !== $bp ) {
				continue;
			}

			// Skip adding @media rule for custom base breakpoint
			$is_base_breakpoint = isset( $breakpoint['base'] );

			/**
			 * Larger than base breakpoint:  use 'min-width'
			 * Smaller than base breakpoint: use 'max-width'
			 */
			$at_media_rule = '';

			if ( ! $is_base_breakpoint ) {
				$at_media_rule = $width > $base_width ? "@media (min-width: {$width}px)" : "@media (max-width: {$width}px)";
			}

			return $at_media_rule;
		}
	}

	/**
	 * Generate CSS from elements
	 *
	 * @param array  $elements Array to loop through all the elements to generate CSS string of entire data.
	 * @param string $css_type header, footer, content, etc. (see: $inline_css).
	 *
	 * @return void
	 */
	public static function generate_css_from_elements( $elements, $css_type ) {
		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return;
		}

		// Set the preview environment
		if ( Helpers::is_bricks_template( self::$post_id ) ) {
			$template_preview_post_id = Helpers::get_template_preview_post_id( self::$post_id );

			$post_id = empty( $template_preview_post_id ) ? self::$post_id : $template_preview_post_id;

			global $post;
			$post = get_post( $post_id );
			setup_postdata( $post );
		}

		// Flat element list
		self::$elements = [];

		/**
		 * Set component context on slot descendants before processing nested component instances.
		 *
		 * Slot children are stored as local page elements. CSS generation needs their component
		 * context before nested component expansion so AJAX pagination includes their styles.
		 * (#86c7p02wq; @since 2.3.8)
		 */
		$slot_child_elements = [];

		foreach ( $elements as $element ) {
			foreach ( Helpers::get_component_slot_child_elements( $element, $elements ) as $slot_child_element ) {
				if ( empty( $slot_child_element['id'] ) ) {
					continue;
				}

				$slot_child_key                         = (string) ( $slot_child_element['slotElementKey'] ?? $slot_child_element['id'] );
				$slot_child_elements[ $slot_child_key ] = $slot_child_element;
			}
		}

		if ( ! empty( $slot_child_elements ) ) {
			foreach ( $elements as &$ref_element ) {
				$ref_element_id  = isset( $ref_element['id'] ) ? (string) $ref_element['id'] : '';
				$ref_element_key = (string) ( $ref_element['slotElementKey'] ?? $ref_element_id );

				if ( $ref_element_key && isset( $slot_child_elements[ $ref_element_key ] ) ) {
					$ref_element = array_merge( $ref_element, $slot_child_elements[ $ref_element_key ] );
				} elseif ( $ref_element_id && isset( $slot_child_elements[ $ref_element_id ] ) ) {
					$ref_element = array_merge( $ref_element, $slot_child_elements[ $ref_element_id ] );
				}
			}

			unset( $ref_element );
		}

		// Prepare flat list of elements for recursive calls
		foreach ( $elements as $element ) {
			// STEP: Get component (@since 1.12)
			$component_id = $element['cid'] ?? false;
			$component    = $component_id ? Helpers::get_component_instance( $element ) : false;

			// Is component: Add all elements of this component to self::$elements array to generate CSS for them
			if ( $component ) {
				// Handle component elements recursively (@since 2.0.1)
				self::insert_component_elements( $component['elements'], $component_id, $element, $component );
			}

			// Is local element: Add element to self::$elements array
			elseif ( empty( self::$elements[ $element['id'] ] ) ) {
				// Component no longer exists: Remove component ID from element
				if ( $component_id && ! $component ) {
					unset( $element['cid'] );
				}

				// ajaxLocalId generated in API request (If the query has dashed) (#86c4957mc)
				$unique_id                    = ! empty( $element['ajaxLocalId'] ) ? $element['ajaxLocalId'] : $element['id'];
				self::$elements[ $unique_id ] = $element;
			}
		}

		/**
		 * Ensure slot descendants generate component-style selectors in partial render contexts.
		 *
		 * This mirrors the frontend render path so nested slot content keeps the same class/id
		 * behavior after AJAX pagination as it has on the initial page load.
		 * (#86c7p02wq; @since 2.3.8)
		 */
		foreach ( $elements as $element ) {
			foreach ( Helpers::get_component_slot_child_elements( $element, self::$elements ) as $slot_child_element ) {
				if ( empty( $slot_child_element['id'] ) ) {
					continue;
				}

				$slot_child_id = (string) ( $slot_child_element['slotElementKey'] ?? $slot_child_element['id'] );

				if ( isset( self::$elements[ $slot_child_id ] ) ) {
					self::$elements[ $slot_child_id ] = array_merge( self::$elements[ $slot_child_id ], $slot_child_element );
				}
			}
		}

		// Get CSS for each root element (children will be processed recursively in generate_css_from_element)
		foreach ( self::$elements as $element_id => $element ) {
			if ( empty( $element['parent'] ) ) {
				self::generate_css_from_element( $element, $css_type );
			}
		}

		// Reset the preview environment
		if ( Helpers::is_bricks_template( self::$post_id ) ) {
			wp_reset_postdata();
		}
	}

	/**
	 * Recursively process component elements to handle nested components
	 *
	 * @param array  $component_elements The elements array from a component
	 * @param string $parent_component_id The parent component ID
	 * @param array  $original_element The original element data
	 * @param array  $original_component The original component data
	 * @param string $css_instance_id CSS-only nested component instance path.
	 *
	 * @since 2.0.1
	 * @since 2.3.12 Added the CSS/render instance path for repeated nested components. (#86cb2khh3)
	 */
	private static function insert_component_elements(
		$component_elements,
		$parent_component_id,
		$original_element,
		$original_component,
		$css_instance_id = ''
	) {
		// Use the direct slot payload ID for slotted component CSS/query context, not the outer component ID.
		// (#86ca4tpmq; @since 2.3.8)
		$original_instance_id = ! empty( $original_element['slotInstanceId'] ) ? $original_element['slotInstanceId'] : ( $original_element['instanceId'] ?? $original_element['id'] );
		// Keep selector/cache identity separate from instanceId, which remains the property and query owner. (#86cb2khh3; @since 2.3.12)
		$css_instance_id = $css_instance_id ?: ( $original_element['cssInstanceId'] ?? $original_element['id'] );

		foreach ( $component_elements as $component_element ) {
				// Nested component: Process recursively
			if ( ! empty( $component_element['cid'] ) ) {
				$nested_component_id = $component_element['cid'];
				// Each nested component host adds one segment to the stable render path. (#86cb2khh3; @since 2.3.12)
				$nested_css_instance_id = $css_instance_id . '-' . $component_element['id'];

				// Set instanceId for nested component element BEFORE calling get_component_instance
				// This ensures parent property references can be resolved correctly (@since 2.2)
				if ( empty( $component_element['instanceId'] ) ) {
					$component_element['instanceId'] = $original_instance_id;
				}

				$component_element['cssInstanceId'] = $nested_css_instance_id;

				$nested_component = Helpers::get_component_instance( $component_element );

				if ( ! empty( $nested_component['elements'] ) ) {

						// STEP 1: Process all direct children of the nested component
					foreach ( $nested_component['elements'] as $nested_component_element ) {
						$nested_component_element['parentComponent'] = $nested_component_id;
						$nested_component_element['instanceId']      = $original_instance_id;
						$nested_component_element['cssInstanceId']   = $nested_css_instance_id;

						// Root variant styles must target the same element that receives data-brx-variant.
						if ( $nested_component_element['id'] === $nested_component_id ) {
							$nested_component_element['_isComponentRoot'] = true;
						}

						// Key by render path because sibling instances reuse component element IDs. (#86cb2khh3; @since 2.3.12)
						$unique_id                    = $nested_component_element['cssInstanceId'] . '-' . $nested_component_element['id'];
						self::$elements[ $unique_id ] = $nested_component_element;
					}

						// STEP 2: Recursively process nested component elements
						self::insert_component_elements(
							$nested_component['elements'],
							$nested_component_id,
							$original_element,
							$original_component,
							$nested_css_instance_id
						);
				}
			}

				// Component child element (including component root)
			else {
					// Add component root element to self::$elements array (@since 2.0)
				if ( $parent_component_id === $component_element['id'] ) {
						// Component element instance (local element) (#86c3aq36e)
					// Use grandparent instanceId if available (nested component) (#86c51y7xy; @since 2.1).
					$unique_id = $original_element['ajaxLocalId'] ?? $original_element['id'];

					if ( empty( self::$elements[ $unique_id ] ) ) {
						// Preserve the nested selector/cache path while storing under the contextual local key. (#86cb2khh3; @since 2.3.12)
						$original_element['cssInstanceId'] = $css_instance_id;
						// Get component instance settings
						if ( ! empty( $component_element['settings'] ) ) {
								$original_element['settings'] = $component_element['settings'];
						}

						// Get children from component
						if ( ! empty( $component_element['children'] ) ) {
								$original_element['children'] = $component_element['children'];
						}

						// Component no longer exists: Remove component ID from element
						if ( $parent_component_id && ! $original_component ) {
							unset( $original_element['cid'] );
						}

						self::$elements[ $unique_id ] = $original_element;
					}
				} else {
					// Set 'parentComponent' to add .brxe- component class to child elements
					$component_element['parentComponent'] = $parent_component_id;
					$component_element['instanceId']      = $original_instance_id;
					$component_element['cssInstanceId']   = $css_instance_id;

					// If the child element's parent is the component root element, set the parent to the current component instance (#86c4957mc)
					if ( ! empty( $component_element['parent'] ) && $component_element['parent'] === $parent_component_id ) {
							$component_element['parent'] = $original_element['id'];
					}

					// Key by render path because sibling instances reuse component element IDs. (#86cb2khh3; @since 2.3.12)
					$unique_id                    = $component_element['cssInstanceId'] . '-' . $component_element['id'];
					self::$elements[ $unique_id ] = $component_element;
				}
			}
		}
	}

	public static function generate_css_from_element( $element, $css_type ) {
		$settings = $element['settings'] ?? false;

		// Skip if _hideElementFrontend is enabled (#86c5e06gg; @since 2.2)
		if ( ! empty( $settings['_hideElementFrontend'] ) ) {
			return;
		}

		/**
		 * NOT IN USE: Remove condition check for now because not all conditions working on External CSS setup
		 * CSS files generated via AJAX call, conditions lie "Current URL", "URL Parameter" would not work properly.
		 *
		 * @since 2.2 (#86c82h81z)
		 */
		// Check element conditions (#86c5e06gg; @since 2.2)
		// $conditions_met = self::maybe_run_condition_check( $element );
		// if (! $conditions_met) {
		// return;
		// }

		// Store elements global classes (for each class ID, store the elements that use it)
		$element_css_global_classes = $settings['_cssGlobalClasses'] ?? false;

		if ( $element_css_global_classes ) {
			/**
			 * Ensure global classes IDs are an array
			 *
			 * If "Multiple options" is not enabled, the selected class is stored as a string.
			 *
			 * @since 2.0
			 */
			if ( is_string( $element_css_global_classes ) ) {
				$element_css_global_classes = explode( ' ', $element_css_global_classes );
			}

			if ( is_array( $element_css_global_classes ) ) {
				foreach ( $element_css_global_classes as $css_class_id ) {
					if ( ! isset( self::$global_classes_elements[ $css_class_id ] ) || ! in_array( $element['name'], self::$global_classes_elements[ $css_class_id ] ) ) {
						self::$global_classes_elements[ $css_class_id ][] = $element['name'];
					}
				}
			}
		}

		/**
		 * Allow third-party plugins to add custom loopable elements to CSS generation in the loop
		 *
		 * @see https://academy.bricksbuilder.io/article/filter-bricks-assets-generate_css_from_element
		 *
		 * @since 1.9.2
		 */
		$loop_elements            = [ 'section', 'container', 'block', 'div' ];
		$additional_loop_elements = apply_filters( 'bricks/assets/generate_css_from_element', [], $element, $css_type );

		if ( is_array( $additional_loop_elements ) ) {
			$loop_elements = array_merge( $loop_elements, $additional_loop_elements );
			$loop_elements = array_unique( $loop_elements );
		}

		/**
		 * Adjust the element ID to include the instance ID if available. Avoid incorrect history ID generation. (@since 2.0)
		 *
		 * Direct slot component instances already have a unique local ID. Keep CSS query lookup
		 * aligned with Query so nested slot content is processed under the same loop ID.
		 * (#86c82k2b3; @since 2.3.8)
		 */
		$query_element_id         = $element['id'] ?? false;
		$is_direct_slot_component = ! empty( $element['slotInstanceId'] );

		if (
			! empty( $element['instanceId'] ) &&
			! empty( $element['parentComponent'] ) &&
			! $is_direct_slot_component &&
			strpos( $element['id'], '-' ) === false
		) {
			$query_element_id .= '-' . $element['instanceId'];
		}

		if ( in_array( $element['name'], $loop_elements ) && isset( $settings['hasLoop'] ) && ! Query::is_looping( $query_element_id ) ) {
			$query = new Query( $element );

			// Prevent endless loop (@since 2.0)
			unset( $element['settings']['hasLoop'] );

			// Prevent condition execution when looping (#86c7q832a; @since 2.2)
			unset( $element['settings']['_conditions'] );

			// Run the query at least once to generate the minimum styles
			if ( empty( $query->count ) ) {
				// Fake the loop so it doesn't run the query again
				$query->is_looping = 1;

				self::generate_css_from_element( $element, $css_type );
			}

			// Render styles according to the results found
			else {
				$query->render( 'Bricks\Assets::generate_css_from_element', compact( 'element', 'css_type' ) ); // Recursive
			}

			// Destroy query to explicitly remove it from the global store
			$query->destroy();
			unset( $query );

			// After generating the CSS of the loop, do not continue here
			return;
		}

		// Nestable elements (section, container, block, div, slider-nested, etc.)
		if ( ! empty( $element['children'] ) ) {
			// Slot subtrees are local page data, so their child IDs stay unsuffixed even when instanceId is set.
			// (#86c876000; @since 2.3.8)
			$is_slot_child = ! empty( $element['isSlotChild'] );
			// Slotted component roots need their local ID when resolving generated child selectors.
			// (#86ca4tpmq; @since 2.3.8)
			// Nested components prefer their full CSS/render path before the slot/instance fallback. (#86cb2khh3; @since 2.3.12)
			$element_instance_id = $element['cssInstanceId'] ?? false;
			if ( ! $element_instance_id ) {
				$element_instance_id = ! empty( $element['slotInstanceId'] ) ? $element['slotInstanceId'] : ( $element['instanceId'] ?? false );
			}

			$parent_element = isset( $element['parent'] ) ? ( self::$elements[ $element['parent'] ] ?? false ) : false;

			if ( ! $is_slot_child && $parent_element && ! empty( $parent_element['slotChildren'] ) ) {
				foreach ( $parent_element['slotChildren'] as $slot_child_ids ) {
					if ( is_array( $slot_child_ids ) && in_array( $element['id'], $slot_child_ids ) ) {
						$is_slot_child = true;
						break;
					}
				}
			}

			foreach ( $element['children'] as $child_id ) {
				$parent_id  = $element['parent'] ?? false;
				$element_id = $element['id'] ?? false;

				// Ensure the child_id is not the same as the parent_id to avoid infinite loops (#86bzkuvge)
				if ( $child_id === $element_id || $child_id === $parent_id ) {
					continue;
				}

				$original_child_id = $child_id;

				// Amend the child ID or certain element styles will be missing because component's children has unique_id (#86c4957mc)
				if ( ! empty( $element['cid'] ) && ! isset( $element['instanceId'] ) ) {
					// Component instance root: children has unique_id
					$child_id = $element_id . '-' . $child_id;
				} elseif ( $element_instance_id ) {
					// Component child element with children (nestable)
					$child_id = $element_instance_id . '-' . $child_id;
				}

				// Check if the modified child ID exists. If not, revert to original ID (e.g. for elements inside a nestable element within a slot)
				if ( $is_slot_child && ! array_key_exists( $child_id, self::$elements ) && array_key_exists( $original_child_id, self::$elements ) ) {
					$child_id = $original_child_id;
				}

				if ( array_key_exists( $child_id, self::$elements ) ) {
					$child_element = self::$elements[ $child_id ];

					self::generate_css_from_element( $child_element, $css_type ); // Recursive
				}
			}
		}

		/**
		 * Component instance with slots: Process slot children
		 *
		 * @since 2.2
		 */
		if ( ! empty( $element['slotChildren'] ) ) {
			foreach ( $element['slotChildren'] as $slot_id => $slot_child_ids ) {
				if ( is_array( $slot_child_ids ) ) {
					foreach ( $slot_child_ids as $child_id ) {
						if ( array_key_exists( $child_id, self::$elements ) ) {
							self::generate_css_from_element( self::$elements[ $child_id ], $css_type ); // Recursive
						}
					}
				}
			}
		}

		/**
		 * Template/Map Connector element: Generate CSS for all elements inside the template
		 *
		 * If inside query loop (non-loop template CSS is generated once for every template in templates.php 'render_shortcode')
		 *
		 * Needed for external files CSS loading method
		 */
		elseif ( in_array( $element['name'], [ 'template', 'map-connector', 'map' ], true ) ) {
			$key         = $element['name'] === 'template' ? 'template' : 'infoBoxTemplateId';
			$template_id = ! empty( $settings[ $key ] ) ? intval( $settings[ $key ] ) : 0;

			if ( $template_id && $template_id != get_the_ID() ) {
				$template_data = get_post_meta( $template_id, BRICKS_DB_PAGE_CONTENT, true );

				// Template used inside a loop
				$looping_query_id   = Query::is_any_looping();
				$looping_element_id = Query::get_query_element_id( $looping_query_id );

				// To ensure we only render styles once for each template, or a combination of a template inside of a loop element (multiple loops using the same template)
				if ( $looping_element_id ) {
					$template_key = "{$template_id}-{$looping_element_id}";

					// Avoid infinite loops
					if ( ! empty( $template_data ) && is_array( $template_data ) && ! in_array( $template_key, self::$templates_css ) ) {
						self::$templates_css[] = $template_key;

						// Add the template ID to the page settings list to be rendered
						if ( ! in_array( $template_id, self::$page_settings_post_ids ) ) {
							self::$page_settings_post_ids[] = $template_id;
						}

						// Check for icon fonts and global elements
						self::enqueue_setting_specific_scripts( $template_data );

						// Store the current main render_data self::$elements
						$store_elements = self::$elements;

						self::generate_css_from_elements( $template_data, $css_type ); // Recursive call

						// Reset the main render_data self::$elements
						self::$elements = $store_elements;
					}
				}
			}
		}

		// Post Content element: Rendering Bricks data
		elseif ( $element['name'] === 'post-content' && isset( $settings['dataSource'] ) && $settings['dataSource'] === 'bricks' ) {
			/**
			 * Post Content used inside a loop
			 *
			 * @since 1.7: Use loop object type instead of query object type so it works with user defined query type which is also a post (@see #862j64bkn)
			 */
			$looping_query_id = Query::is_any_looping();
			$loop_object_type = Query::get_loop_object_type( $looping_query_id );

			$post_id = $loop_object_type === 'post' ? get_the_ID() : Database::$page_data['preview_or_post_id'];

			// Do not remove this line to avoid infinite loops
			if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				$post_id = Helpers::get_template_preview_post_id( $post_id );
			}

			if ( ! empty( $post_id ) ) {
				$bricks_data = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

				if ( ! empty( $bricks_data ) && is_array( $bricks_data ) ) {
					// Add the Post ID to the list of page settings to generate
					self::$page_settings_post_ids[] = $post_id;

					// Check for icon fonts and global elements
					self::enqueue_setting_specific_scripts( $bricks_data );

					// Store the current main render_data self::$elements
					$store_elements = self::$elements;

					self::generate_css_from_elements( $bricks_data, $css_type ); // Recursive call

					// Reset the main render_data self::$elements
					self::$elements = $store_elements;
				}
			}
		}

		/**
		 * Add @media query rules for menu toggle
		 *
		 * Nav menu @since 1.5.1
		 * Nav nested @since 1.8
		 */
		if ( $element['name'] === 'nav-menu' || $element['name'] === 'nav-nested' ) {
			$show_toggle_at_breakpoint = ! empty( $settings['mobileMenu'] ) ? $settings['mobileMenu'] : 'mobile_landscape';

			if ( ! in_array( $show_toggle_at_breakpoint, [ 'always', 'never' ] ) ) {
				$element_class_name = ! empty( Elements::$elements[ $element['name'] ]['class'] ) ? Elements::$elements[ $element['name'] ]['class'] : false;

				if ( $element_class_name ) {
					$element_instance = new $element_class_name( $element );

					// Handle custom breakpoint (@since 2.2)
					if ( $show_toggle_at_breakpoint === 'custom' && ! empty( $settings['mobileMenuCustomBreakpoint'] ) ) {
						$breakpoint = $settings['mobileMenuCustomBreakpoint'];
					} else {
						$breakpoint = Breakpoints::get_breakpoint_by( 'key', $show_toggle_at_breakpoint );
					}

					if ( ! isset( self::$inline_css[ $css_type ] ) ) {
						self::$inline_css[ $css_type ] = '';
					}

					self::$inline_css[ $css_type ] .= $element_instance->generate_mobile_menu_inline_css( $settings, $breakpoint );
				}
			}
		}

		$controls = Elements::get_element( $element, 'controls' );

		self::generate_inline_css_from_element( $element, $controls, $css_type );
	}

	/**
	 * Add the attribute [data-query-loop-index] to the current style element
	 *
	 * Only add HTML attribute once per element ID.
	 *
	 * @since 1.8
	 */
	public static function maybe_add_query_loop_index_attribute_to_element() {
		if ( ! self::$current_generating_element ) {
			return;
		}

		$current_element_id = self::$current_generating_element['id'] ?? false;

		// Return: Without element ID, could be a popup template (@since 1.12)
		if ( ! $current_element_id ) {
			return;
		}

		// Stop if the element ID previously processed before
		if ( in_array( $current_element_id, self::$loop_index_elements ) ) {
			return;
		}

		// Add the element ID to the list of processed elements
		self::$loop_index_elements[] = $current_element_id;

		// Fire the filter
		add_filter(
			'bricks/element/render_attributes',
			function( $attributes, $key, $element ) use ( $current_element_id ) {
				if ( $element->id !== $current_element_id ) {
					return $attributes;
				}

				$unique_loop_index = Query::get_looping_unique_identifier();

				if ( $unique_loop_index !== '' ) {
					$attributes[ $key ]['data-query-loop-index'] = $unique_loop_index;
				}

				return $attributes;
			},
			10,
			3
		);
	}

	/**
	 * Wrap WordPress block styles in cascade layer when enabled
	 *
	 * NOTE: Not in use, but keeping it here for reference
	 */
	public function wrap_block_styles_in_cascade_layer( $tag, $handle ) {
		if ( $handle === 'wp-block-library' || $handle === 'global-styles' ) {
			// Extract the CSS file URL from the tag
			preg_match( '/href=(["\'])([^\1]+)\1.*?media=\'([^\']+)\'/', $tag, $matches );
			$css_url = $matches[2];
			$media   = $matches[3]; // media='(.*?)'

			// Create a new style tag that wraps the content in a cascade layer
			$tag = "<style>@import url('$css_url') layer(bricks.gutenberg);</style>";
		}
		return $tag;
	}

	/**
	 * Wrap mediaelement styles in cascade layer
	 *
	 * @since 2.0
	 */
	public function wrap_mediaelement_styles_in_cascade_layer( $tag, $handle ) {
		if ( $handle === 'mediaelement' || $handle === 'wp-mediaelement' ) {
			preg_match( '/href=(["\'])([^\1]+)\1.*?media=\'([^\']+)\'/', $tag, $matches );
			$css_url = $matches[2];
			$media   = $matches[3];

			$tag = "<style>@import url('$css_url') layer(bricks);</style>";
		}
		return $tag;
	}

	/**
	 * Wrap select2 styles in cascade layer
	 *
	 * @since 2.0
	 */
	public function wrap_select2_styles_in_cascade_layer( $tag, $handle ) {
		if ( $handle === 'select2-css' || $handle === 'select2' || $handle === 'wc-select2' ) {
			preg_match( '/href=(["\'])([^\1]+)\1.*?media=\'([^\']+)\'/', $tag, $matches );
			$css_url = $matches[2];
			$media   = $matches[3];

			$tag = "<style>@import url('$css_url') layer(bricks);</style>";
		}
		return $tag;
	}

	/**
	 * Wrap WooCommerce photoswipe styles in cascade layer
	 *
	 * @since 2.0.2
	 *
	 * @since 2.1: NOTE: Not in use as it causes Woo product gallery lightbox to not work
	 */
	public function wrap_photoswipe_styles_in_cascade_layer( $tag, $handle ) {
		if ( $handle === 'wc-photoswipe' || $handle === 'photoswipe' || $handle === 'photoswipe-default-skin' ) {
			// Extract the CSS file URL from the tag
			preg_match( '/href=(["\'])([^\1]+)\1/', $tag, $matches );

			if ( ! empty( $matches[2] ) ) {
				$css_url = $matches[2];
				$tag     = "<style>@import url('$css_url') layer(bricks);</style>";
			}
		}

		return $tag;
	}

	/**
	 * Maybe run condition check for element
	 * NOT IN USE: Remove condition check for now because not all conditions working on External CSS setup. CSS files generated via AJAX call, conditions lie "Current URL", "URL Parameter" would not work properly. (#86c82h81z; @since 2.2)
	 * #86c5e06gg; @since 2.2
	 */
	public static function maybe_run_condition_check( $element ) {
		$settings = $element['settings'] ?? false;
		// Check element conditions (#86c5e06gg; @since 2.2)
		if ( ! empty( $settings['_conditions'] ) ) {
			$element_class_name = ! empty( Elements::$elements[ $element['name'] ]['class'] ) ? Elements::$elements[ $element['name'] ]['class'] : false;

			// TODO: A new should_render_element inside init function in base.php to cover bricks/element/render hook
			if ( $element_class_name ) {
				$looping_query_id = Query::is_any_looping();
				$loop_object_type = Query::get_loop_object_type( $looping_query_id );

				$post_id = $loop_object_type === 'post' ? get_the_ID() : Database::$page_data['preview_or_post_id'];

				// Do not remove this line to avoid infinite loops
				if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
					$post_id = Helpers::get_template_preview_post_id( $post_id );
				}
				$element_instance = new $element_class_name( $element );
				$element_instance->set_post_id( $post_id );

				// Third parameter to force execute condition check. (External CSS methods will have bricks_is_builder_call() when saving a post)
				$conditions = Conditions::check( $settings['_conditions'], $element_instance, true );

				// Return: Conditions not met
				if ( ! $conditions ) {
					return false;
				}
			}
		}

		return true;
	}
}

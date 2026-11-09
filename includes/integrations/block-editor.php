<?php
namespace Bricks\Integrations;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Block_Editor {
	public function __construct() {
		add_action( 'after_setup_theme', [ $this, 'add_color_palette_theme_support' ] );
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_shortcode( 'bricks_component', [ $this, 'render_component_shortcode' ] );
	}

	/**
	 * Add Bricks color palettes to the Gutenberg Block Editor.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public function add_color_palette_theme_support() {
		$editor_color_palette = self::get_editor_color_palette( \Bricks\Database::$global_data['colorPalette'] ?? [] );

		if ( empty( $editor_color_palette ) ) {
			return;
		}

		$registered_color_palette = get_theme_support( 'editor-color-palette' );

		if ( is_array( $registered_color_palette ) && ! empty( $registered_color_palette[0] ) && is_array( $registered_color_palette[0] ) ) {
			$registered_color_slugs = [];

			foreach ( $registered_color_palette[0] as $registered_color ) {
				if ( ! empty( $registered_color['slug'] ) ) {
					$registered_color_slugs[ $registered_color['slug'] ] = true;
				}
			}

			$editor_color_palette = array_filter(
				$editor_color_palette,
				function( $color ) use ( &$registered_color_slugs ) {
					if ( empty( $color['slug'] ) || isset( $registered_color_slugs[ $color['slug'] ] ) ) {
						return false;
					}

					$registered_color_slugs[ $color['slug'] ] = true;

					return true;
				}
			);

			$editor_color_palette = array_merge( $registered_color_palette[0], $editor_color_palette );
		}

		add_theme_support( 'editor-color-palette', $editor_color_palette );
	}

	/**
	 * Convert Bricks color palettes into the Gutenberg editor color palette format.
	 *
	 * @param array $color_palettes Bricks color palettes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_editor_color_palette( $color_palettes ) {
		$editor_color_palette = [];
		$used_slugs           = [];

		if ( empty( $color_palettes ) || ! is_array( $color_palettes ) ) {
			return $editor_color_palette;
		}

		foreach ( $color_palettes as $palette ) {
			if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $color ) {
				if ( empty( $color ) || ! is_array( $color ) ) {
					continue;
				}

				$color_value = self::get_editor_color_value( $color );

				if ( ! $color_value ) {
					continue;
				}

				$color_name = self::get_editor_color_name( $color );
				$color_slug = self::get_editor_color_slug( $color, $color_name );

				if ( ! $color_slug ) {
					continue;
				}

				if ( isset( $used_slugs[ $color_slug ] ) ) {
					$color_slug = self::get_unique_editor_color_slug( $color_slug, $used_slugs, $color['id'] ?? '' );
				}

				$used_slugs[ $color_slug ] = true;

				$editor_color_palette[] = [
					'name'  => $color_name ? $color_name : $color_slug,
					'slug'  => $color_slug,
					'color' => $color_value,
				];
			}
		}

		return $editor_color_palette;
	}

	/**
	 * Get a Gutenberg-compatible color value from a Bricks color.
	 *
	 * @param array $color Bricks color.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function get_editor_color_value( $color ) {
		$color_value = '';

		foreach ( [ 'light', 'rgb', 'hex' ] as $key ) {
			if ( ! empty( $color[ $key ] ) && is_string( $color[ $key ] ) ) {
				$color_value = $color[ $key ];
				break;
			}
		}

		if ( ! $color_value && ! empty( $color['raw'] ) && is_string( $color['raw'] ) && strpos( $color['raw'], 'var(' ) === false ) {
			$color_value = $color['raw'];
		}

		$color_value = trim( sanitize_text_field( $color_value ) );

		if (
			! $color_value ||
			strpos( $color_value, '{' ) !== false ||
			strpos( $color_value, '}' ) !== false ||
			strpos( $color_value, ';' ) !== false ||
			strpos( $color_value, '<' ) !== false ||
			strpos( $color_value, '>' ) !== false ||
			strpos( $color_value, 'echo:' ) !== false
		) {
			return '';
		}

		return $color_value;
	}

	/**
	 * Get the Gutenberg color name from a Bricks color.
	 *
	 * @param array $color Bricks color.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function get_editor_color_name( $color ) {
		if ( ! empty( $color['name'] ) && is_string( $color['name'] ) ) {
			return sanitize_text_field( $color['name'] );
		}

		$css_variable_name = self::get_css_variable_name( $color['raw'] ?? '' );

		if ( $css_variable_name ) {
			return $css_variable_name;
		}

		if ( ! empty( $color['raw'] ) && is_string( $color['raw'] ) && strpos( $color['raw'], 'var(' ) === false ) {
			return sanitize_text_field( $color['raw'] );
		}

		if ( ! empty( $color['id'] ) ) {
			return 'bricks-color-' . sanitize_key( $color['id'] );
		}

		return '';
	}

	/**
	 * Get the Gutenberg color slug from a Bricks color.
	 *
	 * @param array  $color      Bricks color.
	 * @param string $color_name Gutenberg color name.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function get_editor_color_slug( $color, $color_name ) {
		$slug_source = self::get_css_variable_name( $color['raw'] ?? '' );

		if ( ! $slug_source && $color_name ) {
			$slug_source = $color_name;
		}

		if ( ! $slug_source && ! empty( $color['id'] ) ) {
			$slug_source = 'bricks-color-' . $color['id'];
		}

		return sanitize_title( $slug_source );
	}

	/**
	 * Get a unique Gutenberg color slug.
	 *
	 * @param string $slug       Color slug.
	 * @param array  $used_slugs Used color slugs.
	 * @param string $color_id   Bricks color ID.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function get_unique_editor_color_slug( $slug, $used_slugs, $color_id = '' ) {
		if ( $color_id ) {
			$color_id_slug = sanitize_title( "{$slug}-{$color_id}" );

			if ( ! isset( $used_slugs[ $color_id_slug ] ) ) {
				return $color_id_slug;
			}
		}

		$index = 2;

		while ( isset( $used_slugs[ "{$slug}-{$index}" ] ) ) {
			$index++;
		}

		return "{$slug}-{$index}";
	}

	/**
	 * Extract a CSS variable name from a Bricks raw color value.
	 *
	 * @param string $raw_value Raw color value.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function get_css_variable_name( $raw_value ) {
		if ( ! is_string( $raw_value ) ) {
			return '';
		}

		if ( preg_match( '/^var\(\s*--([a-zA-Z0-9_-]+)\s*(?:,[^)]+)?\)$/', trim( $raw_value ), $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}

		return '';
	}

	/**
	 * Register all component blocks
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$this->register_bricks_components_as_blocks();
	}

	/**
	 * Register all components as blocks, if enabled for block editor
	 */
	public function register_bricks_components_as_blocks() {
		if ( ! \Bricks\Database::get_setting( 'bricksComponentsInBlockEditor' ) ) {
			return;
		}

		$components              = get_option( BRICKS_DB_COMPONENTS, [] );
		$registerable_components = [];

		foreach ( $components as $component ) {
			if ( empty( $component['id'] ) || empty( $component['elements'] ) ) {
				continue;
			}

			$registerable_components[] = $component;
		}

		// Preserve raw saved-content identities before canonical names claim a colliding slug.
		foreach ( $registerable_components as $component ) {
			$this->register_component_block( $component, true );
		}

		foreach ( $registerable_components as $component ) {
			$this->register_component_block( $component, false );
		}
	}

	/**
	 * Register a single component block
	 *
	 * @param array     $component Component data.
	 * @param bool|null $legacy    True for the raw legacy name, false for canonical, null for both.
	 *
	 * @return void
	 */
	public function register_component_block( $component, $legacy = null ) {
		// Skip if no data
		if ( ! $component || empty( $component['elements'] ) ) {
			return;
		}

		$block_name = \Bricks\Helpers::get_component_block_name( $component['id'] );

		// Get component name from the first element or use ID
		$component_name = '';
		if ( isset( $component['elements'][0]['label'] ) ) {
			$component_name = $component['elements'][0]['label'];
		} else {
			$component_name = sprintf(
				/* translators: %s: Component ID */
				__( 'Component %s', 'bricks' ),
				$component['id']
			);
		}

		$attributes = [
			'componentId' => [
				'type'    => 'string',
				'default' => $component['id'],
			],
			'properties'  => [
				'type'    => 'object',
				'default' => [],
			],
			'blockId'     => [
				'type'    => 'string',
				'default' => '',
			],
			'variant'     => [
				'type'    => 'string',
				'default' => '',
			],
			'_preview'    => [
				'type'    => 'boolean',
				'default' => false,
			],
		];

		$block_settings = [
			'attributes'      => $attributes,
			'render_callback' => [ $this, 'render_component_block' ],
			'category'        => $component['blockCategory'] ?? 'bricks',
			'supports'        => [
				'align' => [ 'wide', 'full' ],
			],
		];

		$legacy_block_name = 'bricks-components/' . $component['id'];
		$block_names       = $legacy === true
			? [ $legacy_block_name ]
			: ( $legacy === false ? [ $block_name ] : array_unique( [ $block_name, $legacy_block_name ] ) );

		foreach ( $block_names as $registered_block_name ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $registered_block_name ) ) {
				continue;
			}

			register_block_type( $registered_block_name, $block_settings );
		}
	}

	/**
	 * Render component block
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public function render_component_block( $attributes ) {
		try {
			$component_id = $attributes['componentId'] ?? '';

			if ( ! $component_id ) {
				return '';
			}

			// Check if component is enabled for block editor before rendering
			$components = get_option( BRICKS_DB_COMPONENTS, [] );
			$component  = null;

			foreach ( $components as $comp ) {
				if ( isset( $comp['id'] ) && $comp['id'] === $component_id ) {
					$component = $comp;
					break;
				}
			}

			// Return empty string if component not found or not enabled for block editor
			if ( ! $component ) {
				return '';
			}

			// Check if component is enabled for block editor
			if ( \Bricks\Database::get_setting( 'bricksComponentsInBlockEditor' ) === 'manual' && empty( $component['blockEditor'] ) ) {
				return '';
			}

			// Translate attributes if WPML is active
			if ( \Bricks\Integrations\Wpml\Wpml::is_wpml_active() ) {
				$attributes = \Bricks\Integrations\Wpml\Wpml::translate_component_block_attributes( $attributes, get_the_ID() );
			}

			// Render component directly with attributes
			$content = $this->render_component_shortcode( $attributes );

			if ( ! $content ) {
				return '';
			}

			// Apply alignment class wrapper
			if ( ! empty( $attributes['align'] ) ) {
				return '<div class="align' . esc_attr( $attributes['align'] ) . '">' . $content . '</div>';
			}

			return $content;
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Render component shortcode: [bricks_component id="component_id"]
	 *
	 * Simplified version that leverages Bricks' native component system
	 */
	public function render_component_shortcode( $attributes = [] ) {
		try {
			// Handle both direct calls (from blocks) and shortcode calls
			$component_id = ! empty( $attributes['componentId'] ) ? sanitize_text_field( $attributes['componentId'] ) :
							( ! empty( $attributes['id'] ) ? sanitize_text_field( $attributes['id'] ) : false );

			if ( ! $component_id ) {
				return '';
			}

			// Check if component exists
			$component = \Bricks\Helpers::get_component_by_cid( $component_id );
			if ( ! $component ) {
				return '';
			}

			// Check if component is enabled for block editor
			if ( \Bricks\Database::get_setting( 'bricksComponentsInBlockEditor' ) === 'manual' && empty( $component['blockEditor'] ) ) {
				return '';
			}

			// Get properties from attributes (handle both new and legacy formats)
			$properties = [];
			if ( isset( $attributes['properties'] ) && is_array( $attributes['properties'] ) ) {
				// New format: single properties object
				$properties = $attributes['properties'];
			}

			// Get block ID for unique element ID
			$block_id = ! empty( $attributes['blockId'] ) ? sanitize_text_field( $attributes['blockId'] ) : '';

			// Get variant
			$variant = ! empty( $attributes['variant'] ) ? sanitize_text_field( $attributes['variant'] ) : '';

			// Get the main element from the component
			$main_element = null;
			foreach ( $component['elements'] as $element ) {
				if ( $element['id'] === $component_id ) {
					$main_element = $element;
					break;
				}
			}

			if ( ! $main_element ) {
				return '';
			}

			// Create component element using the main element's name and structure
			// Use blockId for consistent element ID, fallback to component ID if no blockId
			$element_id = $block_id ? $component_id . '-' . $block_id : $component_id;

			$component_element = [
				'id'         => $element_id,
				'name'       => $main_element['name'], // Use the actual element name (e.g., 'post - title')
				'cid'        => $component_id,
				'properties' => $properties,
			];

			// Add variant if specified
			if ( $variant ) {
				$component_element['variant'] = $variant;
			}

			// Generate CSS for this component instance
			\Bricks\Assets::generate_css_from_elements( [ $component_element ], "component_$component_id" );

			// Prepare all settings into enqueue_setting_specific_scripts
			$all_elements       = [];
			$component_instance = \Bricks\Helpers::get_component_instance( $component_element );

			if ( ! empty( $component_instance ) ) {
				// Get all nested elements for this component instance (#86c7ac7wk; @since 2.2)
				\Bricks\Helpers::get_component_elements_recursive( $component_instance, $all_elements );
			} else {
				$all_elements = [ $component_element ];
			}

			// Enqueue icon fonts and other setting-specific scripts for this component instance
			\Bricks\Assets::enqueue_setting_specific_scripts( $all_elements );

			// Ensure theme styles are loaded for Gutenberg context
			if ( $this->is_gutenberg_render() && empty( \Bricks\Theme_Styles::$settings_by_id ) ) {
				\Bricks\Theme_Styles::load_set_styles();
			}

			// Handle CSS output based on context
			$html = '';
			if ( bricks_is_builder() || bricks_is_builder_call() || $this->is_gutenberg_render() ) {
				// For builder/Gutenberg: Add inline CSS for immediate preview
				$component_css = \Bricks\Assets::$inline_css[ "component_$component_id" ] ?? '';

				$global_css = '';

				// Gutenberg loads shared globals once via the block editor stylesheet to preserve frontend cascade order (@since 2.3.8)
				if ( ! $this->is_gutenberg_render() ) {
					// Add global styles for editor contexts
					$global_classes_css = \Bricks\Assets::generate_global_classes();
					if ( $global_classes_css ) {
						$global_css .= "\n/* Global Classes */\n" . $global_classes_css;
					}

					$global_variables = \Bricks\Assets::get_global_variables();
					if ( $global_variables ) {
						$variables_css = \Bricks\Assets::format_variables_as_css( $global_variables );
						if ( $variables_css ) {
							$global_css .= "\n/* Global Variables */\n" . $variables_css;
						}
					}

					$global_colors = \Bricks\Assets::generate_inline_css_color_vars( \Bricks\Database::$global_data['colorPalette'] );
					if ( $global_colors ) {
						$global_css .= "\n/* Global Colors */\n" . $global_colors;
					}

					// Add theme styles that apply to current page
					$theme_style_css = '';
					if ( ! empty( \Bricks\Theme_Styles::$settings_by_id ) ) {
						foreach ( \Bricks\Theme_Styles::$settings_by_id as $style_id => $settings ) {
							$theme_style_css .= \Bricks\Assets::generate_inline_css_theme_style( $settings );
						}
					}
					if ( $theme_style_css ) {
						$global_css .= "\n/* Theme Styles */\n" . $theme_style_css;
					}
				}

				$webfont_links = '';

				// Scope CSS to Gutenberg editor canvas
				if ( $this->is_gutenberg_render() ) {
					$all_css = $global_css . $component_css;

					// Add webfonts
					$webfont_links = $component_css ? \Bricks\Assets::load_webfonts( $component_css, true ) : '';

					if ( $all_css ) {
						$scoped_css = self::scope_css_for_gutenberg( $all_css );
						$html      .= "{$webfont_links}<style id=\"bricks-inline-css-component-{$component_id}\">{$scoped_css}</style>";
					} else {
						$html .= $webfont_links;
					}
				} else {
					if ( $component_css ) {
						\Bricks\Assets::load_webfonts( $component_css );
					}

					// Keep Builder shortcode previews unchanged; Gutenberg handles navigation in its click capture.
					$editor_css = "a.brxe-{$component_id}, .brxe-{$component_id} a { pointer-events: none; }";
					$html      .= "{$webfont_links}<style id=\"bricks-inline-css-component-{$component_id}\">{$global_css}{$component_css}{$editor_css}</style>";
				}
			} else {
				// For frontend: Add CSS to Bricks' normal CSS handling system
				$component_css = \Bricks\Assets::$inline_css[ "component_$component_id" ] ?? '';
				if ( $component_css ) {
					// Add to dynamic CSS for frontend output
					\Bricks\Assets::$inline_css_dynamic_data .= $component_css;

					// Load webfonts for frontend
					\Bricks\Assets::load_webfonts( $component_css );
				}
			}

			// Prevent infinite loops
			static $rendered_components = [];
			if ( in_array( $component_id, $rendered_components, true ) ) {
				return '';
			}

			$rendered_components[] = $component_id;

			// Let Bricks handle everything - this is the key simplification!
			// But first, ensure we have post context for post-related elements
			global $post;
			$original_post = $post;

			// If no post context in Gutenberg, try to get the current editing post
			if ( ! $post && $this->is_gutenberg_render() ) {
				$post_id = get_the_ID();
				if ( ! $post_id && isset( $_GET['post'] ) ) {
					$post_id = intval( $_GET['post'] );
				}
				if ( ! $post_id && isset( $_POST['post_id'] ) ) {
					$post_id = intval( $_POST['post_id'] );
				}

				if ( $post_id ) {
					$post = get_post( $post_id );
					setup_postdata( $post );
				}
			}

			// Add parent component to Frontend::$elements so nested components can resolve parent properties
			// See: Helpers::resolve_parent_property_value()
			\Bricks\Frontend::$elements[ $element_id ] = $component_element;

			$html .= \Bricks\Frontend::render_element( $component_element );

			// Restore original post context
			if ( $original_post ) {
				$post = $original_post;
				setup_postdata( $post );
			} elseif ( ! $original_post && $post ) {
				wp_reset_postdata();
			}

			array_pop( $rendered_components );

			return $html;

		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Scope CSS for Gutenberg editor while preserving root-level declarations.
	 *
	 * Keeps @font-face and @keyframes at root level and scopes regular rules to the editor iframe body.
	 *
	 * @param string $css CSS to scope.
	 * @param bool   $map_post_content_links Whether native content uses a frontend Post Content wrapper.
	 * @param bool   $preserve_native_links Whether to limit the frontend anchor reset to Bricks elements.
	 * @return string Scoped CSS.
	 */
	public static function scope_css_for_gutenberg( $css, $map_post_content_links = false, $preserve_native_links = false ) {
		if ( empty( $css ) ) {
			return $css;
		}

		$root_level_css = '';

		// STEP: Extract @font-face blocks
		if ( preg_match_all( '/@font-face\s*\{[^}]*\}/s', $css, $font_matches ) ) {
			foreach ( $font_matches[0] as $font_block ) {
				$root_level_css .= $font_block . "\n";
				$css             = str_replace( $font_block, '', $css );
			}
		}

		// STEP: Extract @keyframes blocks
		if ( preg_match_all( '/@(?:-webkit-)?keyframes\s+[^{]+\{(?:[^{}]*\{[^}]*\})*[^}]*\}/s', $css, $keyframe_matches ) ) {
			foreach ( $keyframe_matches[0] as $keyframe_block ) {
				$root_level_css .= $keyframe_block . "\n";
				$css             = str_replace( $keyframe_block, '', $css );
			}
		}

		return $root_level_css . self::scope_css_rules_for_gutenberg( $css, $map_post_content_links, $preserve_native_links );
	}

	/**
	 * Generate global class CSS needed by component blocks in the Gutenberg canvas.
	 *
	 * @param int $post_id Edited post ID.
	 * @return string
	 *
	 * @since 2.3.8
	 */
	public static function generate_gutenberg_global_classes_css( $post_id = 0 ) {
		if ( ! \Bricks\Database::get_setting( 'bricksComponentsInBlockEditor' ) ) {
			return '';
		}

		$global_classes_elements = [];
		$components              = \Bricks\Database::$global_data['components'] ?? get_option( BRICKS_DB_COMPONENTS, [] );

		if ( $post_id && function_exists( 'parse_blocks' ) ) {
			$post = get_post( $post_id );

			if ( $post && ! empty( $post->post_content ) ) {
				self::collect_component_blocks_global_class_usage( parse_blocks( $post->post_content ), $global_classes_elements );
			}
		}

		if ( is_array( $components ) ) {
			foreach ( $components as $component ) {
				if ( ! self::is_component_enabled_for_gutenberg( $component ) ) {
					continue;
				}

				self::collect_component_global_class_usage( $component['id'], [], '', $global_classes_elements );
				self::collect_component_property_global_class_usage( $component, $global_classes_elements );
			}
		}

		if ( empty( $global_classes_elements ) ) {
			return '';
		}

		$original_global_classes_elements = \Bricks\Assets::$global_classes_elements;
		$original_inline_css              = \Bricks\Assets::$inline_css;
		$original_inline_css_breakpoints  = \Bricks\Assets::$inline_css_breakpoints;
		$original_unique_inline_css       = \Bricks\Assets::$unique_inline_css;
		$original_inline_css_dynamic_data = \Bricks\Assets::$inline_css_dynamic_data;
		$original_generating_element      = \Bricks\Assets::$current_generating_element;
		$css                              = '';

		try {
			\Bricks\Assets::$global_classes_elements = $global_classes_elements;
			$css                                     = \Bricks\Assets::generate_global_classes( 'gutenberg_global_classes' );
		} finally {
			\Bricks\Assets::$global_classes_elements    = $original_global_classes_elements;
			\Bricks\Assets::$inline_css                 = $original_inline_css;
			\Bricks\Assets::$inline_css_breakpoints     = $original_inline_css_breakpoints;
			\Bricks\Assets::$unique_inline_css          = $original_unique_inline_css;
			\Bricks\Assets::$inline_css_dynamic_data    = $original_inline_css_dynamic_data;
			\Bricks\Assets::$current_generating_element = $original_generating_element;
		}

		return $css ? $css : '';
	}

	/**
	 * Load layout libraries before server-rendered component previews are inserted.
	 *
	 * Previews can add or change elements without reloading the editor, so their
	 * assets cannot depend on the saved post content or current component properties.
	 *
	 * @return void
	 * @since 2.4
	 */
	public static function enqueue_gutenberg_component_element_assets() {
		$layer_suffix = ! \Bricks\Database::get_setting( 'disableBricksCascadeLayer' ) ? '-layer' : '';

		foreach ( [ 'splide', 'swiper', 'isotope' ] as $library ) {
			$handle = "bricks-{$library}";
			$script = "js/libs/{$library}.min.js";
			$style  = "css/libs/{$library}{$layer_suffix}.min.css";

			wp_enqueue_script( $handle, BRICKS_URL_ASSETS . $script, [ 'bricks-scripts' ], filemtime( BRICKS_PATH_ASSETS . $script ), true );
			wp_enqueue_style( $handle, BRICKS_URL_ASSETS . $style, [], filemtime( BRICKS_PATH_ASSETS . $style ) );
		}
	}

	/**
	 * Check if a component can render in Gutenberg.
	 *
	 * @param array $component Component data.
	 * @return bool
	 *
	 * @since 2.3.8
	 */
	private static function is_component_enabled_for_gutenberg( $component ) {
		if ( empty( $component['id'] ) || empty( $component['elements'] ) || ! is_array( $component['elements'] ) ) {
			return false;
		}

		return \Bricks\Database::get_setting( 'bricksComponentsInBlockEditor' ) !== 'manual' || ! empty( $component['blockEditor'] );
	}

	/**
	 * Collect global class usage for a component instance.
	 *
	 * @param string $component_id Component ID.
	 * @param array  $properties Component block properties.
	 * @param string $variant Component variant ID.
	 * @param array  $global_classes_elements Global class usage map.
	 * @return void
	 *
	 * @since 2.3.8
	 */
	private static function collect_component_global_class_usage( $component_id, $properties, $variant, &$global_classes_elements ) {
		$component = \Bricks\Helpers::get_component_by_cid( $component_id );

		if ( ! $component || ! self::is_component_enabled_for_gutenberg( $component ) ) {
			return;
		}

		$main_element = self::get_component_main_element( $component );

		if ( ! $main_element ) {
			return;
		}

		$component_element = [
			'id'         => $component_id,
			'name'       => $main_element['name'],
			'cid'        => $component_id,
			'properties' => is_array( $properties ) ? $properties : [],
		];

		if ( $variant ) {
			$component_element['variant'] = $variant;
		}

		$component_instance = \Bricks\Helpers::get_component_instance( $component_element );
		$elements           = [];

		if ( ! empty( $component_instance ) ) {
			\Bricks\Helpers::get_component_elements_recursive( $component_instance, $elements );
		} else {
			$elements = [ $component_element ];
		}

		foreach ( $elements as $element ) {
			self::add_element_global_class_usage( $element, $global_classes_elements );
		}
	}

	/**
	 * Get the root element for a component.
	 *
	 * @param array $component Component data.
	 * @return array|false
	 *
	 * @since 2.3.8
	 */
	private static function get_component_main_element( $component ) {
		foreach ( $component['elements'] as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $component['id'] ) {
				return $element;
			}
		}

		return false;
	}

	/**
	 * Collect global class options exposed through component class properties.
	 *
	 * @param array $component Component data.
	 * @param array $global_classes_elements Global class usage map.
	 * @return void
	 *
	 * @since 2.3.8
	 */
	private static function collect_component_property_global_class_usage( $component, &$global_classes_elements ) {
		if ( empty( $component['properties'] ) || ! is_array( $component['properties'] ) ) {
			return;
		}

		foreach ( $component['properties'] as $property ) {
			if ( ( $property['type'] ?? '' ) !== 'class' || empty( $property['connections'] ) || ! is_array( $property['connections'] ) ) {
				continue;
			}

			$property_class_ids = self::get_property_global_class_ids( $property );

			if ( empty( $property_class_ids ) ) {
				continue;
			}

			foreach ( $property['connections'] as $element_id => $setting_keys ) {
				$element = self::get_component_element_by_id( $component, $element_id );

				if ( ! $element ) {
					continue;
				}

				foreach ( $property_class_ids as $class_id ) {
					self::add_global_class_usage( $class_id, $element['name'], $global_classes_elements );
				}
			}
		}
	}

	/**
	 * Get global class IDs referenced by a class property.
	 *
	 * @param array $property Component property.
	 * @return array
	 *
	 * @since 2.3.8
	 */
	private static function get_property_global_class_ids( $property ) {
		$class_ids = [];

		if ( ! empty( $property['default'] ) ) {
			$class_ids = array_merge( $class_ids, self::normalize_global_class_ids( $property['default'] ) );
		}

		if ( empty( $property['options'] ) || ! is_array( $property['options'] ) ) {
			return array_values( array_unique( array_filter( $class_ids ) ) );
		}

		foreach ( $property['options'] as $option ) {
			if ( ! empty( $option['id'] ) ) {
				$class_ids[] = $option['id'];
			}

			if ( ! empty( $option['value'] ) ) {
				$class_ids = array_merge( $class_ids, self::normalize_global_class_ids( $option['value'] ) );
			}
		}

		return array_values( array_unique( array_filter( $class_ids ) ) );
	}

	/**
	 * Get a component element by ID.
	 *
	 * @param array  $component Component data.
	 * @param string $element_id Element ID.
	 * @return array|false
	 *
	 * @since 2.3.8
	 */
	private static function get_component_element_by_id( $component, $element_id ) {
		foreach ( $component['elements'] as $element ) {
			if ( isset( $element['id'] ) && (string) $element['id'] === (string) $element_id ) {
				return $element;
			}
		}

		return false;
	}

	/**
	 * Collect component block global class usage from parsed Gutenberg blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $global_classes_elements Global class usage map.
	 * @return void
	 *
	 * @since 2.3.8
	 */
	private static function collect_component_blocks_global_class_usage( $blocks, &$global_classes_elements ) {
		if ( ! is_array( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( \Bricks\Helpers::is_component_block_name( $block_name ) ) {
				$attrs        = $block['attrs'] ?? [];
				$component_id = \Bricks\Helpers::get_component_id_from_block( $block );
				$properties   = ! empty( $attrs['properties'] ) && is_array( $attrs['properties'] ) ? $attrs['properties'] : [];
				$variant      = ! empty( $attrs['variant'] ) ? sanitize_text_field( $attrs['variant'] ) : '';

				self::collect_component_global_class_usage( $component_id, $properties, $variant, $global_classes_elements );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_component_blocks_global_class_usage( $block['innerBlocks'], $global_classes_elements );
			}
		}
	}

	/**
	 * Add global class usage for an element.
	 *
	 * @param array $element Element data.
	 * @param array $global_classes_elements Global class usage map.
	 * @return void
	 *
	 * @since 2.3.8
	 */
	private static function add_element_global_class_usage( $element, &$global_classes_elements ) {
		$element_name = $element['name'] ?? '';

		if ( ! $element_name ) {
			return;
		}

		$class_ids = $element['settings']['_cssGlobalClasses'] ?? false;

		foreach ( self::normalize_global_class_ids( $class_ids ) as $class_id ) {
			self::add_global_class_usage( $class_id, $element_name, $global_classes_elements );
		}
	}

	/**
	 * Normalize a global class ID value to an array.
	 *
	 * @param mixed $class_ids Global class ID value.
	 * @return array
	 *
	 * @since 2.3.8
	 */
	private static function normalize_global_class_ids( $class_ids ) {
		if ( is_string( $class_ids ) ) {
			$class_ids = explode( ' ', $class_ids );
		}

		return is_array( $class_ids ) ? array_values( array_filter( $class_ids ) ) : [];
	}

	/**
	 * Add one global class/element pair to the usage map.
	 *
	 * @param string $class_id Global class ID.
	 * @param string $element_name Element name.
	 * @param array  $global_classes_elements Global class usage map.
	 * @return void
	 *
	 * @since 2.3.8
	 */
	private static function add_global_class_usage( $class_id, $element_name, &$global_classes_elements ) {
		if ( ! $class_id || ! $element_name ) {
			return;
		}

		if ( ! isset( $global_classes_elements[ $class_id ] ) ) {
			$global_classes_elements[ $class_id ] = [];
		}

		if ( ! in_array( $element_name, $global_classes_elements[ $class_id ], true ) ) {
			$global_classes_elements[ $class_id ][] = $element_name;
		}
	}

	/**
	 * Scope regular CSS rules to the block editor canvas.
	 *
	 * @param string $css CSS to scope.
	 * @param bool   $map_post_content_links Whether native content uses a frontend Post Content wrapper.
	 * @param bool   $preserve_native_links Whether to limit the frontend anchor reset to Bricks elements.
	 * @return string
	 */
	private static function scope_css_rules_for_gutenberg( $css, $map_post_content_links = false, $preserve_native_links = false ) {
		$scoped_css = '';
		$offset     = 0;
		$length     = strlen( $css );

		while ( $offset < $length ) {
			$open_position = strpos( $css, '{', $offset );

			if ( $open_position === false ) {
				$scoped_css .= substr( $css, $offset );
				break;
			}

			$selector = substr( $css, $offset, $open_position - $offset );
			$close    = self::find_matching_css_brace( $css, $open_position );

			if ( $close === false ) {
				$scoped_css .= substr( $css, $offset );
				break;
			}

			$body   = substr( $css, $open_position + 1, $close - $open_position - 1 );
			$prefix = '';

			if ( preg_match( '/^(\\s*(?:\\/\\*.*?\\*\\/\\s*)*)(.*?)$/s', $selector, $matches ) ) {
				$prefix   = $matches[1];
				$selector = $matches[2];
			}

			$selector = trim( $selector );

			if ( $selector === '' ) {
				$scoped_css .= $prefix . '{' . $body . '}';
			} elseif ( strpos( $selector, '@' ) === 0 ) {
				$scoped_css .= $prefix . $selector . '{' . self::scope_css_at_rule_body_for_gutenberg( $selector, $body, $map_post_content_links, $preserve_native_links ) . '}';
			} else {
				$scoped_css .= $prefix . self::scope_css_selector_list_for_gutenberg( $selector, $map_post_content_links, $preserve_native_links ) . '{' . $body . '}';
			}

			$offset = $close + 1;
		}

		return $scoped_css;
	}

	/**
	 * Scope at-rule contents when they contain regular style rules.
	 *
	 * @param string $selector At-rule selector.
	 * @param string $body At-rule body.
	 * @param bool   $map_post_content_links Whether native content uses a frontend Post Content wrapper.
	 * @param bool   $preserve_native_links Whether to limit the frontend anchor reset to Bricks elements.
	 * @return string
	 */
	private static function scope_css_at_rule_body_for_gutenberg( $selector, $body, $map_post_content_links = false, $preserve_native_links = false ) {
		if ( preg_match( '/^@(media|supports|container|layer)\\b/i', $selector ) ) {
			return self::scope_css_rules_for_gutenberg( $body, $map_post_content_links, $preserve_native_links );
		}

		return $body;
	}

	/**
	 * Find the matching closing brace for a CSS block.
	 *
	 * @param string $css CSS string.
	 * @param int    $open_position Opening brace position.
	 * @return int|false
	 */
	private static function find_matching_css_brace( $css, $open_position ) {
		$depth  = 0;
		$length = strlen( $css );

		for ( $i = $open_position; $i < $length; $i++ ) {
			if ( $css[ $i ] === '{' ) {
				$depth++;
			} elseif ( $css[ $i ] === '}' ) {
				$depth--;

				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return false;
	}

	/**
	 * Scope a comma-separated selector list to the block editor canvas.
	 *
	 * @param string $selector_list CSS selector list.
	 * @param bool   $map_post_content_links Whether native content uses a frontend Post Content wrapper.
	 * @param bool   $preserve_native_links Whether to limit the frontend anchor reset to Bricks elements.
	 * @return string
	 */
	private static function scope_css_selector_list_for_gutenberg( $selector_list, $map_post_content_links = false, $preserve_native_links = false ) {
		$selectors = self::split_css_selector_list( $selector_list );
		$scoped    = [];

		foreach ( $selectors as $selector ) {
			$scoped[] = self::scope_css_selector_for_gutenberg( $selector, $map_post_content_links, $preserve_native_links );
		}

		return implode( ', ', $scoped );
	}

	/**
	 * Split a selector list while respecting functional pseudo selectors.
	 *
	 * @param string $selector_list CSS selector list.
	 * @return array
	 */
	private static function split_css_selector_list( $selector_list ) {
		$selectors     = [];
		$current       = '';
		$paren_depth   = 0;
		$bracket_depth = 0;
		$length        = strlen( $selector_list );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector_list[ $i ];

			if ( $char === '(' ) {
				$paren_depth++;
			} elseif ( $char === ')' ) {
				$paren_depth = max( 0, $paren_depth - 1 );
			} elseif ( $char === '[' ) {
				$bracket_depth++;
			} elseif ( $char === ']' ) {
				$bracket_depth = max( 0, $bracket_depth - 1 );
			}

			if ( $char === ',' && $paren_depth === 0 && $bracket_depth === 0 ) {
				$selectors[] = trim( $current );
				$current     = '';
				continue;
			}

			$current .= $char;
		}

		if ( trim( $current ) !== '' ) {
			$selectors[] = trim( $current );
		}

		return $selectors;
	}

	/**
	 * Scope one selector to the block editor canvas.
	 *
	 * @param string $selector CSS selector.
	 * @param bool   $map_post_content_links Whether native content uses a frontend Post Content wrapper.
	 * @param bool   $preserve_native_links Whether to limit the frontend anchor reset to Bricks elements.
	 * @return string
	 */
	private static function scope_css_selector_for_gutenberg( $selector, $map_post_content_links = false, $preserve_native_links = false ) {
		$selector = trim( $selector );

		if ( $selector === '' ) {
			return $selector;
		}

		// Native links need WordPress defaults; keep the zero-specificity reset for Bricks previews and linked icons.
		if ( $preserve_native_links && $selector === ':where(a)' ) {
			$selector = ':where(a):where([class*="brxe-"], [class*="brxe-"] *, .bricks-link-wrapper)';
		}

		// Keep native shortcode autosizing free of frontend form styles without changing preview specificity.
		if ( $selector === 'textarea' ) {
			$selector = 'textarea:where(:not(.blocks-shortcode__textarea))';
		}

		// Native blocks have no Post Content wrapper. Preserve its specificity and link exclusions.
		if ( $map_post_content_links && preg_match( '/^:where\(\.brxe-post-content\):not\(\[data-source=(["\']?)bricks\1\]\)\s+a(?![\w-])/', $selector ) ) {
			$selector = ':where(.is-root-container)' . substr( $selector, strlen( ':where(.brxe-post-content)' ) );
		}

		// Native blocks lack the embedded-content wrapper; keep preview rules and their specificity intact.
		if ( preg_match( '/^\.brxe-post-content:not\(\[data-source=(["\']?)bricks\1\]\)(\s+\*\s*\+\s*.+)$/', $selector, $matches ) ) {
			$native_selector = '.is-root-container:not([data-source=bricks])' . $matches[2] . ':not(:where([class*="brxe-"], [class*="brxe-"] *))';

			return self::scope_css_selector_to_editor_wrapper( ' ' . $selector ) . ', ' . self::scope_css_selector_to_editor_wrapper( ' ' . $native_selector );
		}

		if (
			self::selector_starts_with_class( $selector, 'block-editor-iframe__html' ) ||
			self::selector_starts_with_class( $selector, 'block-editor-iframe__body' )
		) {
			return $selector;
		}

		if ( self::selector_starts_with_class( $selector, 'editor-styles-wrapper' ) ) {
			return self::scope_css_selector_to_editor_wrapper( substr( $selector, strlen( '.editor-styles-wrapper' ) ) );
		}

		if ( self::selector_starts_with_class( $selector, 'is-root-container' ) ) {
			return self::scope_css_selector_to_editor_wrapper( ' ' . $selector );
		}

		if ( strpos( $selector, ':root' ) === 0 ) {
			return self::scope_css_selector_to_editor_html( substr( $selector, strlen( ':root' ) ) );
		}

		if ( preg_match( '/^body([^\\s>+~]*)(.*)$/', $selector, $matches ) ) {
			return self::scope_css_selector_to_editor_body( $matches[1] . $matches[2] );
		}

		if ( preg_match( '/^html(?:[^\\s>+~]*)?(.*)$/', $selector, $matches ) ) {
			return self::scope_css_selector_to_editor_html( $matches[1] );
		}

		return self::scope_css_selector_to_editor_wrapper( ' ' . $selector );
	}

	/**
	 * Return the block editor iframe body selector.
	 *
	 * @param string $suffix Selector suffix.
	 * @return string
	 */
	private static function scope_css_selector_to_editor_wrapper( $suffix = '' ) {
		return '.block-editor-iframe__body' . $suffix;
	}

	/**
	 * Return the block editor iframe html selector.
	 *
	 * @param string $suffix Selector suffix.
	 * @return string
	 */
	private static function scope_css_selector_to_editor_html( $suffix = '' ) {
		return '.block-editor-iframe__html' . $suffix;
	}

	/**
	 * Return the block editor iframe body selector.
	 *
	 * @param string $suffix Selector suffix.
	 * @return string
	 */
	private static function scope_css_selector_to_editor_body( $suffix = '' ) {
		return '.block-editor-iframe__body' . $suffix;
	}

	/**
	 * Check if a selector starts with the given class name.
	 *
	 * @param string $selector   CSS selector.
	 * @param string $class_name CSS class name without the leading dot.
	 * @return bool
	 */
	private static function selector_starts_with_class( $selector, $class_name ) {
		$class_selector = '.' . $class_name;

		if ( strpos( $selector, $class_selector ) !== 0 ) {
			return false;
		}

		$next_char = substr( $selector, strlen( $class_selector ), 1 );

		return $next_char === '' || ! preg_match( '/[a-zA-Z0-9_-]/', $next_char );
	}

	/**
	 * Check if we're in a Gutenberg ServerSideRender context
	 */
	private function is_gutenberg_render() {
		// Check if we're in a REST API call for block rendering
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Check for AJAX request from Gutenberg block renderer
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			if ( isset( $_POST['action'] ) && $_POST['action'] === 'gutenberg_render_block' ) {
				return true;
			}
		}

		// Check if we're in admin and not in Bricks builder
		if ( is_admin() && ! bricks_is_builder() && ! bricks_is_builder_call() ) {
			return true;
		}

		// Check for specific Gutenberg query parameters
		if ( isset( $_GET['context'] ) && sanitize_text_field( wp_unslash( $_GET['context'] ) ) === 'edit' ) {
			return true;
		}

		// Check if current screen is Gutenberg editor
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return true;
			}
		}

		// Check for block editor specific headers or request attributes
		if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && is_admin() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get select options from the first connected element with a select control
	 *
	 * @param array $property    The component property array.
	 * @param array $elements    The component elements array.
	 * @return array|null The select options array or null if not found.
	 */
	public function get_select_options_from_connected_elements( $property, $elements ) {
		// Only process select properties that have connections
		if ( $property['type'] !== 'select' || empty( $property['connections'] ) || ! is_array( $property['connections'] ) ) {
			return null;
		}

		// Check each connected element
		foreach ( $property['connections'] as $element_id => $connection_paths ) {
			// Find the element in the elements array
			$element = $this->find_element_by_id( $elements, $element_id );
			if ( ! $element ) {
				continue;
			}

			// Get the element's controls to find select controls
			$element_controls = \Bricks\Elements::get_element( $element, 'controls' );
			if ( empty( $element_controls ) ) {
				continue;
			}

			// Check each connection path to find select controls
			foreach ( $connection_paths as $path ) {
				// For simple paths (most common case)
				if ( strpos( $path, '.' ) === false ) {
					if ( isset( $element_controls[ $path ] ) ) {
						$control = $element_controls[ $path ];
						if ( isset( $control['type'] ) && $control['type'] === 'select' && ! empty( $control['options'] ) ) {
							return $control['options'];
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Find element by ID in elements array (recursive)
	 *
	 * @param array  $elements   The elements array to search.
	 * @param string $element_id The element ID to find.
	 * @return array|null The element array or null if not found.
	 */
	private function find_element_by_id( $elements, $element_id ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				return $element;
			}

			// Search recursively in children
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$found = $this->find_element_by_id( $element['children'], $element_id );
				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

}

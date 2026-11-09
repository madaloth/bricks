<?php
namespace Bricks\Integrations\Wpml;

use Bricks\Elements;
use Bricks\Database;
use Bricks\Helpers;
use Bricks\Settings;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Wpml {
	public $wpml_identifier                        = 'Bricks';
	public static $is_active                       = false;
	private static $is_processing_wpml_translation = false; // @since 1.11

	// String callbacks can complete several posts within one WPML request.
	private $posts_with_translated_strings = [];

	/**
	 * Request-local, unresolved definitions keyed by blog, current/default language, and component ID.
	 *
	 * Retain the source alongside its translation to detect same-ID edits. Instance properties,
	 * selected variants, slots, and rendered output must never be stored in this cache.
	 *
	 * @var array
	 * @since 2.4.3
	 */
	private static $translated_components = [];

	/**
	 * Invalidation generation used to reject translations interrupted by a string update.
	 *
	 * @var int
	 * @since 2.4.3
	 */
	private static $translation_cache_generation = 0;

	// Hold hook callbacks for switching builder language to ensure they can be removed after execution to prevent side effects (@since 2.3)
	private $switch_builder_lang_before = null;
	private $switch_builder_lang_after  = null;

	public function __construct() {
		self::$is_active = self::is_wpml_active();

		if ( ! self::$is_active ) {
			return;
		}

		add_action( 'init', [ $this, 'init_elements' ] );

		// WPML (@since 1.7)
		if ( function_exists( 'icl_object_id' ) ) {
			add_filter( 'bricks/database/bricks_get_all_templates_by_type_args', [ $this, 'wpml_get_posts_args' ] );
			add_filter( 'bricks/get_templates/query_vars', [ $this, 'wpml_get_posts_args' ] );
		}

		// Prefix template cache keys with content language to keep builder UI locale separate from queried content.
		add_filter( 'bricks/database/get_all_templates_cache_key', [ $this, 'get_all_templates_cache_key' ] );
		add_filter( 'bricks/get_templates_query/cache_key', [ $this, 'add_template_language_cache_key' ] );

		// Set builder content language before templates and element controls are queried. (#86c9vt6tw; @since 2.3.6)
		add_action( 'wp', [ $this, 'set_builder_content_language' ], 1 );

		add_filter( 'wpml_page_builder_support_required', [ $this, 'wpml_page_builder_support_required' ], 10, 1 );
		add_action( 'wpml_page_builder_register_strings', [ $this, 'wpml_page_builder_register_strings' ], 10, 2 );
		add_action( 'wpml_pro_translation_completed', [ $this, 'handle_translation_completed_no_strings' ], 10, 3 );
		add_action( 'wpml_pro_translation_completed', [ $this, 'update_translated_post_component_blocks' ], 20, 3 );

		/**
		 * Using a closure to ensure this function is only triggered from the 'wpml_page_builder_string_translated' hook
		 *
		 * Necessary because the function sets $is_processing_wpml_translation to true, which should only happen in the context of WPML string translation.
		 *
		 * @since 1.11
		 */
		add_action(
			'wpml_page_builder_string_translated',
			function( $package_kind, $translated_post_id, $original_post, $string_translations, $lang ) {
				$this->wpml_page_builder_string_translated( $package_kind, $translated_post_id, $original_post, $string_translations, $lang );
			},
			10,
			5
		);

		// Addressing all page builder "Corner cases"
		// https://git.onthegosystems.com/glue-plugins/wpml/wpml-page-builders/-/wikis/Integrating-a-page-builder-with-WPML#corner-cases
		add_filter( 'wpml_pb_is_editing_translation_with_native_editor', [ $this, 'wpml_pb_is_editing_translation_with_native_editor' ], 10, 2 );
		add_filter( 'wpml_pb_is_page_builder_page', [ $this, 'wpml_pb_is_page_builder_page' ], 10, 2 );

		// Hide WPML language switcher for specific Bricks admin pages
		add_action( 'admin_head', [ $this, 'hide_wpml_language_switcher_for_bricks' ] );

		// WPML Media Translation
		add_filter( 'wp_get_attachment_image_src', [ $this, 'translate_attachment_image_src' ], 10, 3 );

		// Resolve attachment IDs from element data to current language (alt, caption, URLs) (@since 2.4)
		add_filter( 'bricks/resolve_attachment_id', [ $this, 'filter_resolve_attachment_id' ], 10, 1 );

		add_filter( 'bricks/builder/post_title', [ $this, 'add_langugage_to_post_title' ], 10, 3 );

		// Add language parameter to query args (@since 1.9.9)
		add_filter( 'bricks/posts/query_vars', [ $this, 'add_language_query_var' ], 100, 3 );

		// Add language to query loop cache key. (When enabled cacheQueryLoops) (@since 2.3.2)
		add_filter( 'bricks/query/cache_key', [ $this, 'add_query_language_cache_key' ], 100, 2 );

		// Add language code to populate correct export template link (@since 1.10)
		add_filter( 'bricks/export_template_args', [ $this, 'add_export_template_arg' ], 10, 2 );

		// Filter builder edit link (@since 1.10)
		add_filter( 'bricks/get_builder_edit_link', [ $this, 'filter_builder_edit_link' ], 10, 2 );

		// Apply filter to each term name (@since 1.11)
		add_filter( 'bricks/builder/term_name', [ $this, 'add_language_to_term_name' ], 10, 3 );

		// Nav menu element: Show all translated menus in the builder control (@since 2.3.5)
		add_filter( 'bricks/elements/nav_menu/menus', [ $this, 'get_all_nav_menus' ] );
		add_filter( 'bricks/elements/nav_menu/name', [ $this, 'add_language_to_nav_menu_name' ], 10, 2 );

		// Reassign filter element IDs for translated posts (fix DB AJAX) (@since 1.12.2)
		add_filter( 'bricks/fix_filter_element_db', [ $this, 'fix_filter_element_db' ], 10, 3 );

		// Add language code to filter element data (@since 1.12.2)
		add_filter( 'bricks/query_filters/element_data', [ $this, 'set_filter_element_language' ], 10, 3 );

		// Switch language for Bricks job execution (@since 1.12.2)
		add_action( 'bricks_execute_filter_index_job', [ $this, 'bricks_execute_filter_index_job' ], 10 );

		// Enable WPML hooks in Bricks frontend endpoints (@since 1.12.2)
		add_action( 'bricks/render_query_result/start', [ $this, 'wpml_get_term_adjust_id' ] );
		add_action( 'bricks/render_query_page/start', [ $this, 'wpml_get_term_adjust_id' ] );
		add_action( 'bricks/render_popup_content/start', [ $this, 'wpml_get_term_adjust_id' ] );

		/**
		 * Component translation support using WPML String Packages
		 *
		 * @since 2.1
		 */
		// Declare string package kind for components
		add_filter( 'wpml_active_string_package_kinds', [ $this, 'declare_component_string_package_kind' ] );

		// Register component strings when components are saved
		add_action( 'update_option_' . BRICKS_DB_COMPONENTS, [ $this, 'register_components_string_packages' ], 10, 2 );

		// Translation writes can occur during the same request as a frontend render (#86cbfk591; @since 2.4.3).
		foreach ( [ 'wpml_st_string_registered', 'wpml_st_string_updated', 'wpml_st_string_unregistered', 'wpml_st_add_string_translation', 'wpml_st_translation_file_updated' ] as $hook ) {
			add_action( $hook, [ self::class, 'reset_component_translation_cache' ], 10, 0 );
		}

		// Switch builder language (#86c6h2bv7; @since 2.2)
		add_action( 'bricks/builder/switch_locale', [ $this, 'switch_builder_languge' ] );

		/**
		 * Register strings for "Components as Blocks" in Gutenberg
		 *
		 * @since 2.2
		 */
		add_filter( 'wpml_found_strings_in_block', [ $this, 'wpml_found_strings_in_block' ], 10, 2 );

		// Register global settings strings (@since 2.2)
		add_action( 'update_option_' . BRICKS_DB_GLOBAL_SETTINGS, [ $this, 'register_global_settings_strings' ], 10, 2 );

		// Translate global settings strings (@since 2.2)
		add_filter( 'bricks/user_activation_email/from_name', [ $this, 'translate_global_settings_string' ] );
		add_filter( 'bricks/user_activation_email/subject', [ $this, 'translate_global_settings_string' ] );
		add_filter( 'bricks/user_activation_email/content', [ $this, 'translate_global_settings_string' ] );
	}

	/**
	 * Update post meta without losing slashes from database-loaded values.
	 *
	 * WordPress unslashes metadata inputs before storing them, so values read from
	 * the database must be slashed again before they are written back.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value.
	 *
	 * @return int|bool Meta ID if the key did not exist, true on update, or false on failure.
	 *
	 * @since 2.3.13
	 */
	private static function update_post_meta_preserving_slashes( $post_id, $meta_key, $meta_value ) {
		return update_post_meta( $post_id, $meta_key, self::slash_strings( $meta_value ) );
	}

	/**
	 * Add slashes recursively without coercing non-string values on older WordPress versions.
	 *
	 * @param mixed $value Value to slash.
	 *
	 * @return mixed
	 *
	 * @since 2.3.13
	 */
	private static function slash_strings( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'slash_strings' ], $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * Register strings for "Components as Blocks" in Gutenberg
	 *
	 * @param array $strings
	 * @param array $block
	 *
	 * @return array
	 *
	 * @since 2.2
	 * @since 2.4.5 Expose the active link field, including internal URL parameters.
	 */
	public function wpml_found_strings_in_block( $strings, $block ) {
		// Convert block object to array if needed (WP_Block_Parser_Block)
		if ( is_object( $block ) ) {
			$block = (array) $block;
		}

		// Check if this is a Bricks component block
		if ( empty( $block['blockName'] ) || ! \Bricks\Helpers::is_component_block_name( $block['blockName'] ) ) {
			return $strings;
		}

		$component_id = \Bricks\Helpers::get_component_id_from_block( $block );

		if ( empty( $component_id ) || empty( $block['attrs']['properties'] ) ) {
			return $strings;
		}

		$properties = $block['attrs']['properties'];
		$block_id   = $block['attrs']['blockId'] ?? '';

		// Get component configuration to know property types
		$components = get_option( BRICKS_DB_COMPONENTS, [] );

		$component = null;
		foreach ( $components as $c ) {
			if ( isset( $c['id'] ) && (string) $c['id'] === (string) $component_id ) {
				$component = $c;
				break;
			}
		}

		if ( ! $component || empty( $component['properties'] ) ) {
			return $strings;
		}

		// Map definitions by ID
		$property_definitions = [];
		foreach ( $component['properties'] as $prop ) {
			if ( isset( $prop['id'] ) ) {
				$property_definitions[ $prop['id'] ] = $prop;
			}
		}

		foreach ( $properties as $property_key => $property_value ) {
			if ( ! isset( $property_definitions[ $property_key ] ) ) {
				continue;
			}

			$definition = $property_definitions[ $property_key ];
			$type       = $definition['type'] ?? 'text';
			$label      = $definition['label'] ?? $property_key;

			// Prefix string name with block ID to ensure uniqueness per instance
			$string_name = 'property_' . $property_key;
			if ( $block_id ) {
				$string_name = $block_id . '_' . $string_name;
			}

			// Handle different property types
			switch ( $type ) {
				case 'text':
				case 'textarea':
				case 'editor':
					if ( is_string( $property_value ) && ! empty( $property_value ) ) {
						$strings[] = (object) [
							'id'    => $string_name,
							'name'  => $string_name,
							'value' => $property_value,
							'type'  => ( $type === 'textarea' || $type === 'editor' ) ? 'TEXTAREA' : 'LINE',
						];
					}
					break;

				case 'link':
				case 'image':
					if ( self::get_wpml_link_field( $property_value ) ) {
						$link_field = self::get_wpml_link_field( $property_value );
						$strings[]  = (object) [
							'id'    => $string_name . '_' . $link_field,
							'name'  => $string_name . '_' . $link_field,
							'value' => $property_value[ $link_field ],
							'type'  => 'LINE',
						];
					}
					break;

				case 'image-gallery':
					if ( is_array( $property_value ) && isset( $property_value['images'] ) && is_array( $property_value['images'] ) ) {
						foreach ( $property_value['images'] as $index => $image ) {
							if ( isset( $image['url'] ) && ! empty( $image['url'] ) ) {
								$strings[] = (object) [
									'id'    => $string_name . '_image_' . $index . '_url',
									'name'  => $string_name . '_image_' . $index . '_url',
									'value' => $image['url'],
									'type'  => 'LINE',
								];
							}
						}
					}
					break;
			}
		}

		return $strings;
	}

	/**
	 * Handle WPML translation completion for component blocks
	 * Updates the translated post content with translated component properties
	 *
	 * @param int    $new_post_id     ID of the translated post.
	 * @param array  $fields          Translated fields.
	 * @param object $job             Translation job object.
	 *
	 * @since 2.2
	 */
	public function update_translated_post_component_blocks( $new_post_id, $fields, $job ) {
		$original_post_id = $job->original_doc_id ?? false;

		if ( ! $original_post_id ) {
			return;
		}

		// Switch to target language to ensure we get correct translations
		global $sitepress;
		$original_lang = $sitepress->get_current_language();
		$target_lang   = $job->language_code;

		if ( $original_lang !== $target_lang ) {
			$sitepress->switch_lang( $target_lang );
		}

		$post    = get_post( $new_post_id );
		$content = $post ? $post->post_content : '';
		$blocks  = parse_blocks( $content );

		// We need to pass original post ID to translate_component_block_attributes
		// because strings are registered against the original post ID package
		$updated_blocks = $this->update_component_blocks_recursive( $blocks, $original_post_id );

		// Serialize and update if changed
		$new_content = serialize_blocks( $updated_blocks );

		if ( $new_content !== $content ) {
			// Update post content
			$post_data = [
				'ID'           => $new_post_id,
				'post_content' => wp_slash( $new_content ),
			];

			// Remove hook to prevent loop
			remove_action( 'wpml_pro_translation_completed', [ $this, 'update_translated_post_component_blocks' ], 20 );

			wp_update_post( $post_data );

			// Re-add hook
			add_action( 'wpml_pro_translation_completed', [ $this, 'update_translated_post_component_blocks' ], 20, 3 );
		}

		if ( $original_lang !== $target_lang ) {
			$sitepress->switch_lang( $original_lang );
		}
	}

	/**
	 * Recursively update component blocks with translations
	 *
	 * @param array $blocks
	 * @param int   $original_post_id
	 * @return array
	 */
	private function update_component_blocks_recursive( $blocks, $original_post_id ) {
		foreach ( $blocks as &$block ) {
			// Check if Bricks component block
			if (
				isset( $block['blockName'] ) &&
				\Bricks\Helpers::is_component_block_name( $block['blockName'] ) &&
				! empty( $block['attrs'] )
			) {
				$block['attrs'] = self::translate_component_block_attributes( $block['attrs'], $original_post_id );
			}

			// Recurse inner blocks
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->update_component_blocks_recursive( $block['innerBlocks'], $original_post_id );
			}
		}

		return $blocks;
	}

	/**
	 * Register global settings strings for translation
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $value     The new option value.
	 *
	 * @since 2.2
	 */
	public function register_global_settings_strings( $old_value, $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return;
		}

		$package = [
			'kind'  => 'Bricks',
			'name'  => 'Global settings',
			'title' => 'Global settings',
		];

		$strings = [
			'userActivationLinkEmailFromName' => 'LINE',
			'userActivationLinkEmailSubject'  => 'LINE',
			'userActivationLinkEmailContent'  => 'VISUAL',
		];

		foreach ( $strings as $key => $type ) {
			if ( ! empty( $value[ $key ] ) ) {
				$string_title = "Bricks User Activation: $key";
				do_action( 'wpml_register_string', $value[ $key ], $key, $package, $string_title, $type );
			}
		}
	}

	/**
	 * Translate global settings string
	 *
	 * @param string $value
	 * @return string
	 *
	 * @since 2.2
	 */
	public function translate_global_settings_string( $value ) {
		$filter = current_filter();
		$map    = [
			'bricks/user_activation_email/from_name' => 'userActivationLinkEmailFromName',
			'bricks/user_activation_email/subject'   => 'userActivationLinkEmailSubject',
			'bricks/user_activation_email/content'   => 'userActivationLinkEmailContent',
		];

		if ( ! isset( $map[ $filter ] ) ) {
			return $value;
		}

		$name    = $map[ $filter ];
		$package = [
			'kind'  => 'Bricks',
			'name'  => 'Global settings',
			'title' => 'Global settings',
		];

		return apply_filters( 'wpml_translate_string', $value, $name, $package );
	}


	/**
	 * Handle WPML translation completion ONLY when there are no strings to translate
	 * This is a fallback for when wpml_page_builder_register_strings is not triggered
	 *
	 * @param int    $new_post_id     ID of the translated post.
	 * @param array  $fields          Translated fields.
	 * @param string $original_post   Original post data.
	 *
	 * @since 1.12
	 */
	public function handle_translation_completed_no_strings( $new_post_id, $fields, $original_post ) {
		// Only this target's string callback can replace its no-strings fallback.
		if ( isset( $this->posts_with_translated_strings[ $new_post_id ] ) ) {
			unset( $this->posts_with_translated_strings[ $new_post_id ] );
			return;
		}

		$original_post_id = $original_post->original_doc_id ?? false;

		if ( ! $original_post_id ) {
			return;
		}

		// Skip if not processing a Bricks post
		if ( ! $this->wpml_pb_is_page_builder_page( false, get_post( $original_post_id ) ) ) {
			return;
		}

		// Meta keys to copy from original post to translation
		$meta_keys = [
			BRICKS_DB_PAGE_CONTENT,
			BRICKS_DB_PAGE_HEADER,
			BRICKS_DB_PAGE_FOOTER,
			BRICKS_DB_TEMPLATE_TYPE,
			BRICKS_DB_TEMPLATE_SETTINGS,
			BRICKS_DB_PAGE_SETTINGS,
		];

		// Copy each meta key using WPML's helper function
		foreach ( $meta_keys as $meta_key ) {
			$meta_value = get_post_meta( $original_post_id, $meta_key, true );
			if ( $meta_value ) {
				if ( in_array( $meta_key, [ BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_FOOTER ], true ) ) {
					$filter_elements = \Bricks\Query_Filters::filter_controls_elements();

					// Ensure each Filter element Bricks ID is unique
					$meta_value = \Bricks\Helpers::generate_new_element_ids( $meta_value, $filter_elements );
				} elseif ( $meta_key === BRICKS_DB_TEMPLATE_SETTINGS ) {
					$target_language = ! empty( $original_post->language_code ) ? $original_post->language_code : self::get_post_language_code( $new_post_id );
					$meta_value      = self::translate_template_settings( $meta_value, $target_language );
				}
				// WPML completion may run without a user; authorize only this content write.
				self::$is_processing_wpml_translation = in_array( $meta_key, [ BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_FOOTER ], true );
				try {
					self::update_post_meta_preserving_slashes( $new_post_id, $meta_key, $meta_value );
				} finally {
					self::end_processing_wpml_translation();
				}
			}
		}

		// Clear unique inline CSS if using file-based CSS loading
		if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
			\Bricks\Assets::reset_duplication_tracking();

			$template_type = get_post_meta( $new_post_id, BRICKS_DB_TEMPLATE_TYPE, true );
			$area          = 'content';

			if ( $template_type === 'header' || $template_type === 'footer' ) {
				$area = $template_type;
			}

			$meta_key = Database::get_bricks_data_key( $area );
			$elements = get_post_meta( $new_post_id, $meta_key, true );

			if ( $elements ) {
				\Bricks\Assets_Files::generate_post_css_file( $new_post_id, $area, $elements );
			}
		}
	}

	/**
	 * Check if WPML is currently processing a translation.
	 *
	 * @return bool True if WPML is processing a translation, false otherwise.
	 * @since 1.11
	 */
	public static function is_processing_wpml_translation() {
		return self::$is_processing_wpml_translation;
	}

	/**
	 * Reset the WPML translation processing flag.
	 *
	 * This method should be called after the translation process is complete and the flag is no longer needed.
	 *
	 * @since 1.11
	 */
	public static function end_processing_wpml_translation() {
		self::$is_processing_wpml_translation = false;
	}

	/**
	 * Add language query var
	 *
	 * @see https://wpml.org/documentation/support/debugging-theme-compatibility/#issue-custom-non-standard-wordpress-ajax-requests-always-return-the-default-language-content
	 * @since 1.9.9
	 */
	public function add_language_query_var( $query_vars, $settings, $element_id ) {
		if ( ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
			do_action( 'wpml_switch_language', $current_lang );
		}

		// Resolve copied source-language selections after switching the query's content language.
		if ( ! empty( $query_vars['tax_query'] ) && is_array( $query_vars['tax_query'] ) && has_filter( 'wpml_object_id' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Resolves IDs in an existing clause without adding a query.
			$query_vars['tax_query'] = $this->translate_tax_query_terms( $query_vars['tax_query'] );
		}

		return $query_vars;
	}

	/**
	 * Resolve term IDs without changing taxonomy query grouping or non-ID fields.
	 *
	 * @param array $tax_query Taxonomy clauses in WordPress query format.
	 * @return array
	 * @since 2.4.3
	 */
	private function translate_tax_query_terms( $tax_query ) {
		if ( isset( $tax_query['taxonomy'], $tax_query['terms'] ) ) {
			if ( ! in_array( $tax_query['field'] ?? 'term_id', [ 'term_id', 'id' ], true ) ) {
				return $tax_query;
			}

			$translate = static function( $term_id ) use ( $tax_query ) {
				if ( ! is_numeric( $term_id ) || (int) $term_id <= 0 ) {
					return $term_id;
				}

				$translated_id = apply_filters( 'wpml_object_id', $term_id, $tax_query['taxonomy'], true );

				return $translated_id ? $translated_id : $term_id;
			};

			$tax_query['terms'] = is_array( $tax_query['terms'] )
				? array_map( $translate, $tax_query['terms'] )
				: $translate( $tax_query['terms'] );
		} else {
			foreach ( $tax_query as $key => $clause ) {
				if ( is_array( $clause ) ) {
					$tax_query[ $key ] = $this->translate_tax_query_terms( $clause );
				}
			}
		}

		return $tax_query;
	}

	/**
	 * Add language to query loop cache key. (When enabled cacheQueryLoops)
	 *
	 * @since 2.3.2
	 */
	public function add_query_language_cache_key( $cache_key, $query ) {
		$current_lang = self::get_current_language();

		// Fallback to locale
		if ( empty( $current_lang ) ) {
			$current_lang = get_locale();
		}

		if ( ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
		}

		// If we still don't have a language, return the original cache key
		if ( empty( $current_lang ) ) {
			return $cache_key;
		}

		return $cache_key . '_' . $current_lang;
	}

	/**
	 * Add language code to export template args
	 *
	 * @since 1.10
	 */
	public function add_export_template_arg( $args, $post_id ) {
		$post_language = self::get_post_language_code( $post_id );

		if ( ! empty( $post_language ) ) {
			$args['lang'] = $post_language;
		}

		return $args;
	}

	/**
	 * Hide the WPML language switcher on specified Bricks admin pages.
	 */
	public function hide_wpml_language_switcher_for_bricks() {
		global $pagenow;

		$bricks_admin_pages_to_hide_language_switcher = [ 'bricks-settings' ];

		if ( $pagenow == 'admin.php' && isset( $_GET['page'] ) && in_array( $_GET['page'], $bricks_admin_pages_to_hide_language_switcher ) ) {
			echo '<style>
				#wp-admin-bar-WPML_ALS {
					display: none !important;
				}
			</style>';
		}
	}

	/**
	 * Check if WPML plugin is active
	 *
	 * @return boolean
	 */
	public static function is_wpml_active() {
		return class_exists( 'SitePress' );
	}

	/**
	 * Init WPML elements
	 */
	public function init_elements() {
		$wpml_elements = [ 'wpml-language-switcher' ];

		foreach ( $wpml_elements as $element_name ) {
			$wpml_element_file = BRICKS_PATH . "includes/integrations/wpml/elements/$element_name.php";

			// Get the class name from the element name
			$class_name = str_replace( '-', '_', $element_name );
			$class_name = ucwords( $class_name, '_' );
			$class_name = "Bricks\\$class_name";

			if ( is_readable( $wpml_element_file ) ) {
				Elements::register_element( $wpml_element_file, $element_name, $class_name );
			}
		}
	}

	/**
	 * WPML: Add 'suppress_filters' => false query arg to get templates of currently viewed language
	 *
	 * @param array $query_args
	 * @return array
	 *
	 * @since 1.7
	 */
	public function wpml_get_posts_args( $query_args ) {
		if ( ! isset( $query_args['suppress_filters'] ) ) {
			$query_args['suppress_filters'] = false;
		}

		$this->maybe_switch_template_query_language( $query_args );

		return $query_args;
	}

	/**
	 * Temporarily switch WPML to the edited content language for Bricks template queries.
	 *
	 * Builder UI language can differ from edited content language. Template selectors
	 * need content-language results even while element labels are loaded in UI language.
	 * Polylang does not require this because it filters queries based on the current language, not the UI language.
	 *
	 * @param array $query_args
	 *
	 * @since 2.3.6
	 */
	private function maybe_switch_template_query_language( $query_args ) {
		if ( empty( Database::$page_data['language'] ) ) {
			return;
		}

		$post_type         = $query_args['post_type'] ?? '';
		$is_template_query = is_array( $post_type )
			? in_array( BRICKS_DB_TEMPLATE_SLUG, $post_type, true )
			: $post_type === BRICKS_DB_TEMPLATE_SLUG;

		if ( ! $is_template_query ) {
			return;
		}

		global $sitepress;

		if ( ! $sitepress || ! method_exists( $sitepress, 'get_current_language' ) ) {
			return;
		}

		$content_language  = sanitize_key( Database::$page_data['language'] );
		$original_language = $sitepress->get_current_language();

		if ( ! $content_language || $original_language === $content_language ) {
			return;
		}

		do_action( 'wpml_switch_language', $content_language );

		$restore_language = null;
		$restore_language = function() use ( $original_language, &$restore_language ) {
			do_action( 'wpml_switch_language', $original_language );
			remove_action( 'bricks/get_templates/query_after', $restore_language, PHP_INT_MAX );
			remove_action( 'bricks/database/get_all_templates_by_type/after_query', $restore_language, PHP_INT_MAX );
		};

		if ( current_filter() === 'bricks/get_templates/query_vars' ) {
			add_action( 'bricks/get_templates/query_after', $restore_language, PHP_INT_MAX );
		} elseif ( current_filter() === 'bricks/database/bricks_get_all_templates_by_type_args' ) {
			add_action( 'bricks/database/get_all_templates_by_type/after_query', $restore_language, PHP_INT_MAX );
		}
	}

	/**
	 * Set the builder content language from the edited post.
	 *
	 * Builder UI locale can differ from the edited content language. Use the post
	 * language for translated content queries so controls do not follow the user
	 * profile language or fixed builderLocale setting.
	 *
	 * @since 2.3.6
	 */
	public function set_builder_content_language() {
		if ( ! bricks_is_builder() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( is_home() ) {
			$post_id = get_option( 'page_for_posts' );
		}

		$post_id = apply_filters( 'bricks/builder/data_post_id', $post_id );
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		$language_code = self::get_post_language_code( $post_id );

		if ( empty( $language_code ) ) {
			return;
		}

		Database::set_page_data_language( $language_code );
	}

	/**
	 * WMPL: Register 'Bricks' identifier for WPML
	 *
	 * https://git.onthegosystems.com/glue-plugins/wpml/wpml-page-builders/-/wikis/Integrating-a-page-builder-with-WPML#declaring-support-for-a-page-builder
	 *
	 * @since 1.8
	 */
	public function wpml_page_builder_support_required( $plugins ) {
		$plugins[] = $this->wpml_identifier; // = 'Bricks'

		return $plugins;
	}

	/**
	 * WPML: Register text strings of Bricks elements for translation in WPML
	 *
	 * @param \WP_Post|stdClass $post
	 * @param array             $package_data
	 *
	 * @since 1.8
	 */
	public function wpml_page_builder_register_strings( $post, $package_data ) {
		// Return: Package is not for 'Bricks'
		if ( $package_data['kind'] !== $this->wpml_identifier ) {
			return;
		}

		$template_type = get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, true );

		switch ( $template_type ) {
			case 'header':
				$bricks_elements = Database::get_data( $post->ID, 'header' );
				break;
			case 'footer':
				$bricks_elements = Database::get_data( $post->ID, 'footer' );
				break;
			default:
				$bricks_elements = Database::get_data( $post->ID, 'content' );
				break;
		}

		$page_settings = get_post_meta( $post->ID, BRICKS_DB_PAGE_SETTINGS, true );

		if (
			( empty( $bricks_elements ) || ! is_array( $bricks_elements ) ) &&
			( empty( $page_settings ) || ! is_array( $page_settings ) )
		) {
			return;
		}

		/**
		 * Start the string package registration
		 * NOTE: Wrapping string registration with 'wpml_start_string_package_registration' and
		 * 'wpml_delete_unused_package_strings' actions ensures WPML can track and clean up unused strings when content is updated.
		 * See: https://wpml.org/documentation/support/string-package-translation/#updating-strings-and-removing-unused-ones
		 *
		 * @since 1.11
		 */
		do_action( 'wpml_start_string_package_registration', $package_data );

		if ( ! empty( $bricks_elements ) && is_array( $bricks_elements ) ) {
			// Build the elements tree
			$elements_tree = \Bricks\Helpers::build_elements_tree( $bricks_elements );

			// Traverse the tree and process each element
			$this->traverse_elements_tree( $elements_tree, $post );
		}

		if ( ! empty( $page_settings ) && is_array( $page_settings ) ) {
			$this->process_page_settings( $page_settings, $post );
		}

		// End the string package registration and remove unused strings (@since 1.11)
		do_action( 'wpml_delete_unused_package_strings', $package_data );
	}

	/**
	 * Traverse the tree and process each element in a depth-first manner.
	 *
	 * @param array                   $elements
	 * @param \WP_Post|stdClass|array $post_or_package Post object for regular elements, package array for components.
	 *
	 * @since 1.10.2
	 */
	private function traverse_elements_tree( $elements, $post_or_package ) {
		if ( ! is_array( $elements ) ) {
			\Bricks\Helpers::maybe_log( 'Bricks: Invalid elements provided to traverse_elements_tree' );
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! isset( $element['id'] ) ) {
				\Bricks\Helpers::maybe_log( 'Bricks: Invalid element encountered during traversal: missing ID' );
				continue;
			}

			// Process the current element
			$this->process_element( $element, $post_or_package );

			// Recursively process children
			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$this->traverse_elements_tree( $element['children'], $post_or_package );
			}
		}
	}

	private function process_element( $element, $post_or_package ) {
		$element_name     = ! empty( $element['name'] ) ? $element['name'] : false;
		$element_settings = ! empty( $element['settings'] ) ? $element['settings'] : false;
		$element_config   = Elements::get_element( [ 'name' => $element_name ] );
		$element_controls = ! empty( $element_config['controls'] ) ? $element_config['controls'] : false;
		$element_label    = ! empty( $element_config['label'] ) ? $element_config['label'] : $element_name;

		// Handle component properties (@since 2.1)
		if ( isset( $element['cid'] ) && isset( $element['properties'] ) && is_array( $element['properties'] ) ) {
			$this->process_component_properties( $element, $post_or_package );
		}

		if ( ! $element_settings || ! $element_name || ! is_array( $element_controls ) ) {
			return;
		}

		// Loop over element controls to get translatable settings
		foreach ( $element_controls as $key => $control ) {
			$this->process_control( $key, $control, $element_settings, $element, $element_label, $post_or_package );
		}

		$this->process_query_no_results_text( $element_settings, $element, $element_label, $post_or_package );
	}

	/**
	 * Register the Query control's no-results text for translation.
	 *
	 * Query control fields are nested in the element settings, so they are not
	 * handled by the regular top-level control processing.
	 *
	 * @param array                   $element_settings Element settings.
	 * @param array                   $element          Element data.
	 * @param string                  $element_label    Element label.
	 * @param \WP_Post|stdClass|array $post_or_package Post object or component package.
	 *
	 * @return void
	 *
	 * @since 2.3.13
	 */
	private function process_query_no_results_text( $element_settings, $element, $element_label, $post_or_package ) {
		$query_settings  = Helpers::maybe_get_global_query_settings( $element_settings['query'] ?? [] );
		$no_results_text = $query_settings['no_results_text'] ?? '';

		if ( ! is_string( $no_results_text ) || $no_results_text === '' ) {
			return;
		}

		$string_id = "{$element['id']}_query_no_results_text";
		$this->register_wpml_string( $no_results_text, $string_id, $element_label, $post_or_package, 'text' );
	}

	/**
	 * Register translatable page settings using the same control pipeline as elements.
	 *
	 * Page settings are stored outside the Bricks element tree. Representing them as a
	 * synthetic element keeps filter callbacks and translation IDs consistent without
	 * changing the saved page settings structure.
	 *
	 * @param array             $page_settings Page settings values.
	 * @param \WP_Post|stdClass $post          Source post object.
	 *
	 * @since 2.4
	 */
	private function process_page_settings( $page_settings, $post ) {
		$page_controls_data = Settings::get_controls_data( 'page' );

		if ( empty( $page_controls_data['controls'] ) ) {
			Settings::set_controls();
			$page_controls_data = Settings::get_controls_data( 'page' );
		}

		$page_controls = $page_controls_data['controls'] ?? [];

		if ( empty( $page_controls ) ) {
			return;
		}

		$element = [
			'id'       => 'pageSettings',
			'name'     => 'page-settings',
			'settings' => $page_settings,
		];

		foreach ( $page_controls as $key => $control ) {
			$this->process_control( $key, $control, $page_settings, $element, esc_html__( 'Page settings', 'bricks' ), $post );
		}
	}

	/**
	 * Get control types that WPML should expose for translation.
	 *
	 * Code remains excluded by default because translating executable code can change its
	 * behavior. The filter lets developers opt in globally or for a specific element,
	 * control, or page settings context.
	 *
	 * @param array      $element Bricks element data or the synthetic page settings element.
	 * @param array|null $control Current control definition.
	 * @param string     $key     Current control key.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	private function get_translatable_control_types( $element, $control = null, $key = '' ) {
		return apply_filters(
			'bricks/wpml/translatable_control_types',
			[ 'text', 'textarea', 'editor', 'repeater', 'link' ],
			$element,
			$control,
			$key
		);
	}

	/**
	 * Process component properties for translation
	 *
	 * @param array                   $element The element containing component properties.
	 * @param \WP_Post|stdClass|array $post_or_package The post object or package data.
	 *
	 * @since 2.1
	 */
	private function process_component_properties( $element, $post_or_package ) {
		if ( ! isset( $element['id'] ) || ! isset( $element['cid'] ) || ! isset( $element['properties'] ) ) {
			return;
		}

		$element_id    = $element['id'];
		$component_id  = $element['cid'];
		$properties    = $element['properties'];
		$element_label = "Component Instance (CID: $component_id)";

		foreach ( $properties as $property_key => $property_value ) {
			// Handle direct text/HTML strings (text & textarea properties)
			if ( is_string( $property_value ) && ! empty( $property_value ) ) {
				$string_id = "{$element_id}_prop_{$property_key}";
				$this->register_wpml_string( $property_value, $string_id, $element_label, $post_or_package );
			}

			// Handle objects with URLs (link property)
			elseif ( is_array( $property_value ) ) {
				// Handle link-type properties
				$link_field = self::get_wpml_link_field( $property_value );
				if ( $link_field ) {
					$string_id = "{$element_id}_prop_{$property_key}_{$link_field}";
					$this->register_wpml_string( $property_value[ $link_field ], $string_id, $element_label, $post_or_package );
				}

				// Handle image galleries or image properties
				if ( isset( $property_value['images'] ) && is_array( $property_value['images'] ) ) {
					foreach ( $property_value['images'] as $index => $image ) {
						if ( isset( $image['url'] ) && is_string( $image['url'] ) && ! empty( $image['url'] ) ) {
							$string_id = "{$element_id}_prop_{$property_key}_image_{$index}_url";
							$this->register_wpml_string( $image['url'], $string_id, $element_label, $post_or_package );
						}
					}
				}
			}
		}
	}

	/**
	 * Select active link text without exposing stale values from another link type.
	 *
	 * Internal destinations remain post IDs; only their parameter text belongs in WPML.
	 * Keep historical URL handling for links and image properties without a type.
	 *
	 * @param mixed $value Saved link or property value.
	 * @return string|false
	 * @since 2.4.5
	 */
	private static function get_wpml_link_field( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$field = ( $value['type'] ?? '' ) === 'internal' ? 'urlParams' : 'url';

		return isset( $value[ $field ] ) && is_string( $value[ $field ] ) && ! empty( $value[ $field ] ) ? $field : false;
	}

	/**
	 * Register a control value for translation when its type and key are translatable.
	 *
	 * @param string                  $key             Control key.
	 * @param array                   $control         Control definition.
	 * @param array                   $element_settings Current element settings.
	 * @param array                   $element         Current element data.
	 * @param string                  $element_label   Label used for the WPML string package.
	 * @param \WP_Post|stdClass|array $post_or_package Source post object or component package data.
	 *
	 * @return void
	 *
	 * @since 2.4
	 * @since 2.4.5 Register internal URL parameters under a separate string ID.
	 */
	private function process_control( $key, $control, $element_settings, $element, $element_label, $post_or_package ) {
		$control_type               = ! empty( $control['type'] ) ? $control['type'] : false;
		$translatable_control_types = $this->get_translatable_control_types( $element, $control, $key );

		if ( ! in_array( $control_type, $translatable_control_types ) ) {
			return;
		}

		// Exclude certain controls from translation according to their key (@since 1.9.2)
		// Filter @since 2.3.3
		$exclude_control_from_translation = apply_filters(
			'bricks/wpml/exclude_controls_from_translation',
			[ 'customTag', '_gridTemplateColumns', '_gridTemplateRows', '_cssId', 'targetSelector' ],
			$element,
			$control,
			$key
		);

		if ( in_array( $key, $exclude_control_from_translation ) ) {
			return;
		}

		$string_value = ! empty( $element_settings[ $key ] ) ? $element_settings[ $key ] : '';

		if ( $control_type == 'repeater' && isset( $control['fields'] ) ) {
			$this->process_repeater_control( $key, $control, $element_settings, $element, $element_label, $post_or_package );
			return;
		}

		$string_id = "{$element['id']}_$key";

		if ( $control_type === 'link' && is_array( $string_value ) ) {
			$link_field = self::get_wpml_link_field( $string_value );

			if ( ! $link_field ) {
				return;
			}

			// Separate IDs prevent old external URL translations from replacing parameter text (#123pt1p1051).
			$string_id   .= $link_field === 'urlParams' ? '_urlParams' : '';
			$string_value = $string_value[ $link_field ];
		}

		if ( ! is_string( $string_value ) || empty( $string_value ) ) {
			return;
		}

		$this->register_wpml_string( $string_value, $string_id, $element_label, $post_or_package, $control_type );
	}

	/**
	 * Register translatable values from each item in a repeater control.
	 *
	 * @param string                  $key             Repeater control key.
	 * @param array                   $control         Repeater control definition and field definitions.
	 * @param array                   $element_settings Current element settings.
	 * @param array                   $element         Current element data.
	 * @param string                  $element_label   Label used for the WPML string package.
	 * @param \WP_Post|stdClass|array $post_or_package Source post object or component package data.
	 *
	 * @return void
	 *
	 * @since 2.4
	 * @since 2.4.5 Register internal link parameters with the same IDs used on readback.
	 */
	private function process_repeater_control( $key, $control, $element_settings, $element, $element_label, $post_or_package ) {
		$repeater_items = ! empty( $element_settings[ $key ] ) ? $element_settings[ $key ] : [];

		if ( is_array( $repeater_items ) ) {
			foreach ( $repeater_items as $repeater_index => $repeater_item ) {
				if ( is_array( $repeater_item ) ) {
					foreach ( $repeater_item as $repeater_key => $repeater_value ) {
						// Get the type of this field, check if it's one of the accepted types
						$repeater_field_control           = $control['fields'][ $repeater_key ] ?? [];
						$repeater_field_type              = $repeater_field_control['type'] ?? false;
						$translatable_control_types       = $this->get_translatable_control_types( $element, $repeater_field_control, $repeater_key );
						$exclude_control_from_translation = apply_filters(
							'bricks/wpml/exclude_controls_from_translation',
							[ 'customTag', '_gridTemplateColumns', '_gridTemplateRows', '_cssId', 'targetSelector' ],
							$element,
							$control,
							$repeater_key
						);
						$is_form_html_field               = $this->is_translatable_form_html_field( $element, $key, $repeater_key, $repeater_field_type );
						$is_form_html_field               = $is_form_html_field && ! in_array( $repeater_key, $exclude_control_from_translation );

						if ( ! in_array( $repeater_field_type, $translatable_control_types ) && ! $is_form_html_field ) {
							continue;
						}

						$string_value = ! empty( $repeater_value ) ? $repeater_value : '';

						$string_id = "{$element['id']}_{$key}_{$repeater_index}_{$repeater_key}";

						if ( $repeater_field_type === 'link' && is_array( $string_value ) ) {
							$link_field = self::get_wpml_link_field( $string_value );

							if ( ! $link_field ) {
								continue;
							}

							$string_id   .= $link_field === 'urlParams' ? '_urlParams' : '';
							$string_value = $string_value[ $link_field ];
						}

						if ( ! is_string( $string_value ) || empty( $string_value ) ) {
							continue;
						}

						$this->register_wpml_string( $string_value, $string_id, $element_label, $post_or_package, $repeater_field_type );
					}
				}
			}
		}
	}

	private function is_translatable_form_html_field( $element, $repeater_control_key, $repeater_field_key, $repeater_field_type ) {
		return $repeater_field_type === 'code' && ! empty( $element['name'] ) && $element['name'] === 'form' && $repeater_control_key === 'fields' && $repeater_field_key === 'html';
	}

	/**
	 * Helper function to register a string for translation in WPML
	 */
	private function register_wpml_string( $string_value, $string_id, $element_label, $post_or_package, $control_type = null ) {
		if ( ! $string_value ) {
			return;
		}

		$string_title = "Bricks ($element_label)"; // Title of the string used in the translation

		// Determine the string type based on control type
		if ( in_array( $control_type, [ 'textarea', 'code' ], true ) ) {
			$string_type = 'TEXTAREA';
		} else {
			$string_type = 'LINE'; // 'LINE', 'TEXTAREA', 'VISUAL'
		}

		// Handle both post objects and package arrays
		if ( is_array( $post_or_package ) ) {
			// This is a component package
			$package_data = $post_or_package;
		} else {
			// This is a regular post object
			$package_data = [
				'kind'    => $this->wpml_identifier,
				'name'    => $post_or_package->ID,
				'post_id' => $post_or_package->ID,
				'title'   => "Bricks (ID {$post_or_package->ID})",
			];
		}

		do_action( 'wpml_register_string', $string_value, $string_id, $package_data, $string_title, $string_type );
	}

	/**
	 * Resolve a registered WPML string ID suffix against the current element data.
	 *
	 * Historically this integration stored string IDs as plain underscore-separated paths
	 * such as "{$elementId}_post_type" or "{$elementId}_fields_0_field_id".
	 *
	 * The old apply path tried to rebuild the target path with explode( '_', $string_id ),
	 * which worked only while every setting key was a single segment. Keys like "post_type",
	 * "remove_filters_text", "field_id", or component property keys with "_" broke that
	 * assumption and could send translations to the wrong place.
	 *
	 * We deliberately keep the stored string ID format unchanged. Existing WPML packages
	 * on production sites already reference those IDs, so renaming them would risk making
	 * old translations unreachable. The safe fix is to resolve the legacy suffix against
	 * the current element data instead of changing the identifier format.
	 *
	 * Supported suffix shapes:
	 * - settingKey
	 * - repeaterKey_index_fieldKey
	 * - prop_propertyKey
	 * - prop_propertyKey_url
	 * - prop_propertyKey_image_index_url
	 * - settingKey_urlParams, repeaterKey_index_fieldKey_urlParams, prop_propertyKey_urlParams
	 *
	 * @param array  $element       Bricks element data.
	 * @param string $string_suffix String ID without the element ID prefix.
	 *
	 * @return array|false
	 *
	 * @since 2.3.6
	 * @since 2.4.5 Resolve internal URL parameter paths without changing legacy IDs.
	 */
	private function resolve_wpml_translation_target( $element, $string_suffix ) {
		if ( strpos( $string_suffix, 'prop_' ) === 0 ) {
			$resolved_property_target = $this->resolve_wpml_property_translation_target( $element, substr( $string_suffix, 5 ) );

			if ( $resolved_property_target ) {
				return $resolved_property_target;
			}
		}

		$resolved_target = $this->resolve_wpml_setting_translation_target( $element, $string_suffix );

		return $resolved_target ? $resolved_target : $this->resolve_wpml_url_params_translation_target( $element, $string_suffix );
	}

	/**
	 * Resolve parameter IDs against saved keys, including keys containing underscores.
	 *
	 * Legacy exact matches take precedence. Ignore obsolete parameter translations
	 * when a link has changed type or no longer contains parameter text.
	 *
	 * @param array  $element       Bricks element data.
	 * @param string $string_suffix String ID without the element prefix.
	 * @return array|false
	 * @since 2.4.5
	 */
	private function resolve_wpml_url_params_translation_target( $element, $string_suffix ) {
		if ( substr( $string_suffix, -10 ) !== '_urlParams' ) {
			return false;
		}

		$settings   = $element['settings'] ?? [];
		$properties = $element['properties'] ?? [];

		foreach ( is_array( $settings ) ? $settings : [] as $key => $value ) {
			if ( $string_suffix === $key . '_urlParams' && self::get_wpml_link_field( $value ) === 'urlParams' ) {
				return [
					'type'  => 'setting_url',
					'key'   => $key,
					'field' => 'urlParams'
				];
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $index => $item ) {
				if ( ! ctype_digit( (string) $index ) || ! is_array( $item ) ) {
					continue;
				}

				foreach ( $item as $field => $link ) {
					if ( $string_suffix === "{$key}_{$index}_{$field}_urlParams" && self::get_wpml_link_field( $link ) === 'urlParams' ) {
						return [
							'type'       => 'repeater_url',
							'key'        => $key,
							'index'      => $index,
							'field'      => $field,
							'link_field' => 'urlParams'
						];
					}
				}
			}
		}

		foreach ( is_array( $properties ) ? $properties : [] as $key => $value ) {
			if ( $string_suffix === "prop_{$key}_urlParams" && self::get_wpml_link_field( $value ) === 'urlParams' ) {
				return [
					'type'  => 'property_url',
					'key'   => $key,
					'field' => 'urlParams'
				];
			}
		}

		return false;
	}

	/**
	 * Resolve a setting or repeater string ID suffix against saved element settings.
	 *
	 * Exact setting key matches must win before repeater parsing. Otherwise "post_type"
	 * would be misread as "post" + repeater index + field key.
	 *
	 * @param array  $element       Bricks element data.
	 * @param string $string_suffix String ID suffix to resolve.
	 *
	 * @return array|false
	 *
	 * @since 2.3.6
	 */
	private function resolve_wpml_setting_translation_target( $element, $string_suffix ) {
		$settings = $element['settings'] ?? null;

		if ( ! is_array( $settings ) || $string_suffix === '' ) {
			return false;
		}

		if ( $string_suffix === 'query_no_results_text' ) {
			$query_settings = Helpers::maybe_get_global_query_settings( $settings['query'] ?? [] );

			if ( isset( $query_settings['no_results_text'] ) && is_string( $query_settings['no_results_text'] ) ) {
				return [
					'type' => 'query_setting',
					'key'  => 'no_results_text',
				];
			}
		}

		if ( array_key_exists( $string_suffix, $settings ) ) {
			if ( is_string( $settings[ $string_suffix ] ) ) {
				return [
					'type' => 'setting',
					'key'  => $string_suffix,
				];
			}

			if ( is_array( $settings[ $string_suffix ] ) && isset( $settings[ $string_suffix ]['url'] ) ) {
				return [
					'type' => 'setting_url',
					'key'  => $string_suffix,
				];
			}
		}

		$setting_keys = array_keys( $settings );

		usort(
			$setting_keys,
			static function( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( $setting_keys as $setting_key ) {
			$prefix = $setting_key . '_';

			if ( strpos( $string_suffix, $prefix ) !== 0 ) {
				continue;
			}

			$repeater_suffix = substr( $string_suffix, strlen( $prefix ) );

			if ( $repeater_suffix === '' ) {
				continue;
			}

			$index_separator = strpos( $repeater_suffix, '_' );

			if ( $index_separator === false ) {
				continue;
			}

			$repeater_index = substr( $repeater_suffix, 0, $index_separator );
			$repeater_key   = substr( $repeater_suffix, $index_separator + 1 );

			if ( $repeater_index === '' || $repeater_key === '' || ! ctype_digit( (string) $repeater_index ) ) {
				continue;
			}

			if (
				! isset( $settings[ $setting_key ][ $repeater_index ] ) ||
				! is_array( $settings[ $setting_key ][ $repeater_index ] ) ||
				! array_key_exists( $repeater_key, $settings[ $setting_key ][ $repeater_index ] )
			) {
				continue;
			}

			$repeater_value = $settings[ $setting_key ][ $repeater_index ][ $repeater_key ];

			if ( is_string( $repeater_value ) ) {
				return [
					'type'  => 'repeater',
					'key'   => $setting_key,
					'index' => $repeater_index,
					'field' => $repeater_key,
				];
			}

			if ( is_array( $repeater_value ) && isset( $repeater_value['url'] ) ) {
				return [
					'type'  => 'repeater_url',
					'key'   => $setting_key,
					'index' => $repeater_index,
					'field' => $repeater_key,
				];
			}
		}

		return false;
	}

	/**
	 * Resolve a component property string ID suffix against saved element properties.
	 *
	 * Component property keys use the same legacy underscore-separated ID format, so they
	 * need the same treatment as element settings. Match against real property names first,
	 * then handle the URL and image URL variants that WPML registers for those properties.
	 *
	 * @param array  $element         Bricks element data.
	 * @param string $property_suffix Property-specific part of the string ID.
	 *
	 * @return array|false
	 *
	 * @since 2.3.6
	 */
	private function resolve_wpml_property_translation_target( $element, $property_suffix ) {
		$properties = $element['properties'] ?? null;

		if ( ! is_array( $properties ) || $property_suffix === '' ) {
			return false;
		}

		$property_keys = array_keys( $properties );

		usort(
			$property_keys,
			static function( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( $property_keys as $property_key ) {
			$property_value = $properties[ $property_key ];

			if ( $property_suffix === $property_key && is_string( $property_value ) ) {
				return [
					'type' => 'property',
					'key'  => $property_key,
				];
			}

			if (
				$property_suffix === $property_key . '_url' &&
				is_array( $property_value ) &&
				isset( $property_value['url'] )
			) {
				return [
					'type' => 'property_url',
					'key'  => $property_key,
				];
			}

			$image_prefix = $property_key . '_image_';

			if (
				strpos( $property_suffix, $image_prefix ) === 0 &&
				is_array( $property_value ) &&
				isset( $property_value['images'] ) &&
				is_array( $property_value['images'] )
			) {
				$image_suffix = substr( $property_suffix, strlen( $image_prefix ) );

				if ( preg_match( '/^(\d+)_url$/', $image_suffix, $matches ) ) {
					$image_index = $matches[1];

					if (
						isset( $property_value['images'][ $image_index ] ) &&
						is_array( $property_value['images'][ $image_index ] ) &&
						isset( $property_value['images'][ $image_index ]['url'] )
					) {
						return [
							'type'  => 'property_image_url',
							'key'   => $property_key,
							'index' => $image_index,
						];
					}
				}
			}
		}

		return false;
	}

	/**
	 * Apply a translated string to the previously resolved target path.
	 *
	 * @param array  $element           Bricks element data.
	 * @param array  $resolved_target   Resolved target metadata.
	 * @param string $translated_value  Translated string value.
	 *
	 * @return array
	 *
	 * @since 2.3.6
	 * @since 2.4.5 Apply link parameter translations without replacing destination IDs.
	 */
	private function apply_wpml_translation_to_target( $element, $resolved_target, $translated_value ) {
		switch ( $resolved_target['type'] ) {
			case 'setting':
				$element['settings'][ $resolved_target['key'] ] = $translated_value;
				break;

			case 'setting_url':
				$element['settings'][ $resolved_target['key'] ][ $resolved_target['field'] ?? 'url' ] = $translated_value;
				break;

			case 'query_setting':
				$element['settings']['query'][ $resolved_target['key'] ] = $translated_value;
				break;

			case 'repeater':
				$element['settings'][ $resolved_target['key'] ][ $resolved_target['index'] ][ $resolved_target['field'] ] = $translated_value;
				break;

			case 'repeater_url':
				$element['settings'][ $resolved_target['key'] ][ $resolved_target['index'] ][ $resolved_target['field'] ][ $resolved_target['link_field'] ?? 'url' ] = $translated_value;
				break;

			case 'property':
				$element['properties'][ $resolved_target['key'] ] = $translated_value;
				break;

			case 'property_url':
				$element['properties'][ $resolved_target['key'] ][ $resolved_target['field'] ?? 'url' ] = $translated_value;
				break;

			case 'property_image_url':
				$element['properties'][ $resolved_target['key'] ]['images'][ $resolved_target['index'] ]['url'] = $translated_value;
				break;
		}

		return $element;
	}

	/**
	 * Translate template settings object references to a specific WPML language.
	 *
	 * @param array  $template_settings Template settings.
	 * @param string $lang              Target language code.
	 *
	 * @return array
	 *
	 * @since 2.3.12
	 */
	public static function translate_template_settings( $template_settings, $lang = null ) {
		if ( ! is_array( $template_settings ) ) {
			return $template_settings;
		}

		if ( isset( $template_settings['templateConditions'] ) && is_array( $template_settings['templateConditions'] ) ) {
			$template_settings['templateConditions'] = self::translate_template_conditions( $template_settings['templateConditions'], $lang );
		}

		return $template_settings;
	}

	/**
	 * Translate template condition object references to a specific WPML language.
	 *
	 * @param array  $template_conditions Template conditions.
	 * @param string $lang                Target language code.
	 *
	 * @return array
	 *
	 * @since 2.3.12
	 */
	public static function translate_template_conditions( $template_conditions, $lang = null ) {
		if ( ! self::$is_active || ! has_filter( 'wpml_object_id' ) || ! is_array( $template_conditions ) ) {
			return $template_conditions;
		}

		foreach ( $template_conditions as &$condition ) {
			if ( ! is_array( $condition ) || empty( $condition['main'] ) ) {
				continue;
			}

			if ( $condition['main'] === 'ids' && isset( $condition['ids'] ) && is_array( $condition['ids'] ) ) {
				foreach ( $condition['ids'] as &$condition_post_id ) {
					$condition_post_id = self::translate_template_condition_post_id( $condition_post_id, $lang );
				}

				unset( $condition_post_id );
			}

			if ( $condition['main'] === 'terms' && isset( $condition['terms'] ) && is_array( $condition['terms'] ) ) {
				foreach ( $condition['terms'] as &$condition_term ) {
					$condition_term = self::translate_template_condition_term( $condition_term, $lang );
				}

				unset( $condition_term );
			}

			if ( $condition['main'] === 'archiveType' && isset( $condition['archiveTerms'] ) && is_array( $condition['archiveTerms'] ) ) {
				foreach ( $condition['archiveTerms'] as &$archive_term ) {
					$archive_term = self::translate_template_condition_term( $archive_term, $lang );
				}

				unset( $archive_term );
			}
		}

		unset( $condition );

		return $template_conditions;
	}

	/**
	 * Translate a template condition post ID to a specific WPML language.
	 *
	 * @param int|string $post_id Post ID.
	 * @param string     $lang    Target language code.
	 *
	 * @return int|string
	 *
	 * @since 2.3.12
	 */
	private static function translate_template_condition_post_id( $post_id, $lang = null ) {
		if ( ! is_numeric( $post_id ) ) {
			return $post_id;
		}

		$post_type = get_post_type( $post_id );

		if ( ! $post_type ) {
			return $post_id;
		}

		return self::translate_object_id( $post_id, $post_type, $lang );
	}

	/**
	 * Translate a template condition taxonomy term reference to a specific WPML language.
	 *
	 * @param string $term_ref Term reference in taxonomy::term_id format.
	 * @param string $lang     Target language code.
	 *
	 * @return string
	 *
	 * @since 2.3.12
	 */
	private static function translate_template_condition_term( $term_ref, $lang = null ) {
		$term_parts = explode( '::', (string) $term_ref );

		if ( count( $term_parts ) < 2 ) {
			return $term_ref;
		}

		$taxonomy = $term_parts[0];
		$term_id  = $term_parts[1];

		if ( empty( $taxonomy ) || $term_id === 'all' || ! is_numeric( $term_id ) ) {
			return $term_ref;
		}

		$term_parts[1] = self::translate_object_id( $term_id, $taxonomy, $lang );

		return implode( '::', $term_parts );
	}

	/**
	 * Translate a WPML object ID, optionally to a specific language.
	 *
	 * @param int|string $object_id   Object ID.
	 * @param string     $object_type Object type.
	 * @param string     $lang        Target language code.
	 *
	 * @return int|string
	 *
	 * @since 2.3.12
	 */
	private static function translate_object_id( $object_id, $object_type, $lang = null ) {
		$translated_object_id = $lang
			? apply_filters( 'wpml_object_id', $object_id, $object_type, true, $lang )
			: apply_filters( 'wpml_object_id', $object_id, $object_type, true );

		return $translated_object_id ? $translated_object_id : $object_id;
	}

	/**
	 * WPML: Translated strings are applied to the translated post.
	 *
	 * https://git.onthegosystems.com/glue-plugins/wpml/wpml-page-builders/-/wikis/Integrating-a-page-builder-with-WPML#applying-the-string-translations-in-post-translation
	 *
	 * @param string            $package_kind
	 * @param int               $translated_post_id
	 * @param \WP_Post|stdClass $original_post
	 * @param array             $string_translations
	 * @param string            $lang
	 *
	 * @since 1.8 NOTE: This is a modified version of the original function
	 * @since 2.3.6 Resolve legacy WPML string IDs against saved Bricks data instead of
	 *            assuming every "_" marks a fixed path boundary.
	 */
	private function wpml_page_builder_string_translated( $package_kind, $translated_post_id, $original_post, $string_translations, $lang ) {
		// Return: Package is not for 'Bricks'
		if ( $package_kind !== $this->wpml_identifier ) {
			return;
		}

		/**
		 * Indicate that the current request is processing a WPML translation
		 * This flag is necessary because WPML's REST API does not provide user context,
		 * which causes issues with our capability checks when updating Bricks postmeta.
		 * Setting this flag to `true` allows us to bypass those checks safely within this request.
		 *
		 * @since 1.11
		 */
		self::$is_processing_wpml_translation                       = true;
		$this->posts_with_translated_strings[ $translated_post_id ] = true;

		$original_post_id = $original_post->ID;

		/**
		 * Steps:
		 *
		 * 1. Get Bricks data from original post
		 * 2. Update template type
		 * 3. Update Bricks data with the translated strings
		 * 4. Update template settings if this is a template
		 * 5. Save to the translated post
		 */

		$area          = 'content';
		$template_type = get_post_meta( $original_post_id, BRICKS_DB_TEMPLATE_TYPE, true );

		// Update the BRICKS_DB_TEMPLATE_TYPE of the translated post with the value from the original post
		update_post_meta( $translated_post_id, BRICKS_DB_TEMPLATE_TYPE, $template_type );

		if ( $template_type === 'header' || $template_type === 'footer' ) {
			$area = $template_type;
		}

		$meta_key                = Database::get_bricks_data_key( $area );
		$has_bricks_elements     = is_array( get_post_meta( $original_post_id, $meta_key, true ) );
		$bricks_elements         = Database::get_data( $original_post_id, $area );
		$page_settings           = null;
		$translate_page_settings = false;

		if ( ! $has_bricks_elements ) {
			$bricks_elements = [];
		}

		// Loop over translations for this post
		foreach ( $string_translations as $string_id => $translation ) {
			$translated_value   = $translation[ $lang ]['value'] ?? null;
			$separator_position = strpos( $string_id, '_' );

			if ( ! is_string( $translated_value ) || $separator_position === false ) {
				continue;
			}

			$element_id    = substr( $string_id, 0, $separator_position );
			$string_suffix = substr( $string_id, $separator_position + 1 );

			// Older code split every "_" in the string ID and rebuilt the path from fixed
			// segment positions. That was fragile once valid keys like "post_type" or
			// "field_id" entered the ID. Keep the old IDs for compatibility, but resolve
			// them against the current element data instead of renaming them.
			if ( $element_id === '' || $string_suffix === '' ) {
				continue;
			}

			if ( $element_id === 'pageSettings' ) {
				if ( $page_settings === null ) {
					$page_settings = get_post_meta( $translated_post_id, BRICKS_DB_PAGE_SETTINGS, true );

					if ( ! is_array( $page_settings ) ) {
						$page_settings = get_post_meta( $original_post_id, BRICKS_DB_PAGE_SETTINGS, true );
					}
				}

				$page_settings_element = [
					'id'       => 'pageSettings',
					'name'     => 'page-settings',
					'settings' => is_array( $page_settings ) ? $page_settings : [],
				];
				$resolved_target       = $this->resolve_wpml_translation_target( $page_settings_element, $string_suffix );

				if ( $resolved_target ) {
					$page_settings_element   = $this->apply_wpml_translation_to_target( $page_settings_element, $resolved_target, $translated_value );
					$page_settings           = $page_settings_element['settings'];
					$translate_page_settings = true;
				}

				continue;
			}

			// Loop over element and replace their text
			foreach ( $bricks_elements as $index => $element ) {
				// STEP: Check if this is a 'Template' element and replace the template ID with the translated template ID if it exists (@since 1.9.4)
				if ( $element['name'] ?? null === 'template' ) {
					// Fetch the original template ID from the element settings
					$original_template_id = $element['settings']['template'] ?? null;

					if ( $original_template_id ) {
						// Fetch the translated ID of the linked 'bricks_template' post
						$translated_template_id = apply_filters( 'wpml_object_id', $original_template_id, BRICKS_DB_TEMPLATE_SLUG, true, $lang );

						// Check if the translated ID is valid; if not, retain the original ID
						if ( $translated_template_id ) {
							// Replace the original ID with the translated ID
							$bricks_elements[ $index ]['settings']['template'] = $translated_template_id;
						}
					}
				}

				// STEP: Translate popup template IDs in element interactions (@since 1.11)
				if ( isset( $element['settings']['_interactions'] ) && is_array( $element['settings']['_interactions'] ) ) {
					foreach ( $element['settings']['_interactions'] as $interaction_index => $interaction ) {
						if (
							isset( $interaction['action'] ) &&
							isset( $interaction['target'] ) &&
							isset( $interaction['templateId'] ) &&
							$interaction['action'] === 'show' &&
							$interaction['target'] === 'popup' &&
							is_numeric( $interaction['templateId'] )
						) {
							$original_popup_id   = intval( $interaction['templateId'] );
							$translated_popup_id = apply_filters( 'wpml_object_id', $original_popup_id, BRICKS_DB_TEMPLATE_SLUG, true, $lang );

							if ( $translated_popup_id ) {
								$bricks_elements[ $index ]['settings']['_interactions'][ $interaction_index ]['templateId'] = $translated_popup_id;
							}
						}
					}
				}

				if ( ( $element['id'] ?? null ) !== $element_id ) {
					continue;
				}

				$resolved_target = $this->resolve_wpml_translation_target( $element, $string_suffix );

				if ( ! $resolved_target ) {
					continue;
				}

				$bricks_elements[ $index ] = $this->apply_wpml_translation_to_target(
					$bricks_elements[ $index ],
					$resolved_target,
					$translated_value
				);
			}
		}

		// Save the original post data which now contains the translations
		if ( in_array( $meta_key, [ BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_FOOTER ], true ) ) {
			/**
			 * To avoid all IDs regenerate for every translation sync (especially query ids), only regenerate the IDs of the filter elements to solve index issues.
			 * This is not same as Polylang
			 * Not recommended as not unique element IDs might issue might happen (#862je0kmd)
			 *
			 * @since 1.12.2
			 */
			$filter_elements = \Bricks\Query_Filters::filter_controls_elements();

			// Ensure each Filter element Bricks ID is unique (@since 1.12.2)
			$bricks_elements = Helpers::generate_new_element_ids( $bricks_elements, $filter_elements );
		}

		if ( $has_bricks_elements ) {
			self::update_post_meta_preserving_slashes( $translated_post_id, $meta_key, $bricks_elements );
		}

		if ( $translate_page_settings ) {
			self::update_post_meta_preserving_slashes( $translated_post_id, BRICKS_DB_PAGE_SETTINGS, $page_settings );
		}

		// Update template settings if this is a template
		if ( get_post_type( $translated_post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
			// Get the template settings from the original post
			$original_template_settings = Helpers::get_template_settings( $original_post->ID );
			$original_template_settings = self::translate_template_settings( $original_template_settings, $lang );

			// Set the original template settings on the translated post
			self::update_post_meta_preserving_slashes( $translated_post_id, BRICKS_DB_TEMPLATE_SETTINGS, $original_template_settings );
		}

		/**
		 * STEP: Clear unique_inline_css T
		 *
		 * To regenerate CSS file for secondary languages without triggering return on line 2356 in assets.php
		 */
		if ( Database::get_setting( 'cssLoading' ) == 'file' ) {
			\Bricks\Assets::reset_duplication_tracking();

			\Bricks\Assets::$unique_inline_css = [];
		}
	}

	/**
	 * Translation edited with Bricks (POST 'bricks-is-builder' set)
	 *
	 * Skip translating this post save.
	 *
	 * https://git.onthegosystems.com/glue-plugins/wpml/wpml-page-builders/-/wikis/Integrating-a-page-builder-with-WPML#1-the-translation-is-edited-with-the-page-builder-editor-instead-of-a-wpml-translation-editor
	 *
	 * @param bool $is_translation_with_native_editor
	 * @param int  $translated_post_id
	 *
	 * @since 1.8
	 */
	public function wpml_pb_is_editing_translation_with_native_editor( $is_translation_with_native_editor, $translated_post_id ) {
		if ( ! $is_translation_with_native_editor && isset( $_POST['bricks-is-builder'] ) ) {
			$post_id = ! empty( $_POST['postId'] ) ? intval( $_POST['postId'] ) : false;

			return $translated_post_id === $post_id;
		}

		return $is_translation_with_native_editor;
	}

	/**
	 * Check if post is built & rendered with Bricks
	 *
	 * https://git.onthegosystems.com/glue-plugins/wpml/wpml-page-builders/-/wikis/Integrating-a-page-builder-with-WPML#2-the-original-page-or-post-is-not-built-with-the-page-builder
	 *
	 * @param bool              $is_pb_post
	 * @param \WP_Post|stdClass $post
	 *
	 * @since 1.8
	 */
	public function wpml_pb_is_page_builder_page( $is_pb_post, $post ) {
		if ( ! $is_pb_post ) {
			$post_id       = $post->ID;
			$area          = 'content';
			$template_type = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, true );

			if ( $template_type === 'header' || $template_type === 'footer' ) {
				$area = $template_type;
			}

			$meta_key    = Database::get_bricks_data_key( $area );
			$bricks_data = get_post_meta( $post_id, $meta_key, true );

			// Post has Bricks data && is rendered with Bricks
			$editor_mode                    = get_post_meta( $post_id, BRICKS_DB_EDITOR_MODE, true );
			$built_and_rendered_with_bricks = $bricks_data && $editor_mode === 'bricks';

			return $built_and_rendered_with_bricks;
		}

		return $is_pb_post;
	}

	/**
	 * Modify the wp_get_attachment_image_src output to return the translated image src.
	 *
	 * @param array        $image          The array containing the image src and dimensions.
	 * @param int          $attachment_id  The attachment ID.
	 * @param string|array $size           Image size.
	 *
	 * @return array
	 */
	public function translate_attachment_image_src( $image, $attachment_id, $size ) {
		$translated_id = $this->get_translated_attachment_id( $attachment_id );

		// If the translated ID is different than the original, get the src for the translated image.
		if ( $translated_id !== $attachment_id ) {
			$image = wp_get_attachment_image_src( $translated_id, $size );
		}

		return $image;
	}

	/**
	 * Map Bricks-stored attachment ID to WPML translated attachment for frontend output.
	 *
	 * @param int $attachment_id Attachment post ID.
	 *
	 * @return int
	 *
	 * @since 2.4
	 */
	public function filter_resolve_attachment_id( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return 0;
		}

		$translated = (int) $this->get_translated_attachment_id( $attachment_id );

		return $translated ? $translated : $attachment_id;
	}

	/**
	 * Translate the attachment ID to the current language's version.
	 *
	 * @param int $attachment_id
	 *
	 * @return int
	 */
	public function get_translated_attachment_id( $attachment_id ) {
		return apply_filters( 'wpml_object_id', $attachment_id, 'attachment', true );
	}

	/**
	 * Add language code to post title
	 *
	 * @param string $title   The original title of the page.
	 * @param int    $page_id The ID of the page.
	 * @param string $context The Builder context requesting the title.
	 * @return string The modified title with the language suffix.
	 */
	public function add_langugage_to_post_title( $title, $page_id, $context = '' ) {
		if ( isset( $_GET['addLanguageToPostTitle'] ) || $context === 'popup_interaction' ) {
			return $this->add_language_to_post_title( $title, $page_id );
		}

		// Return the original title if conditions are not met
		return $title;
	}

	/**
	 * Prefix a post title with its language code.
	 *
	 * @param string $title   The original post title.
	 * @param int    $post_id The post ID.
	 * @return string The language-prefixed title.
	 *
	 * @since 2.4
	 */
	private function add_language_to_post_title( $title, $post_id ) {
		$language_code = self::get_post_language_code( $post_id );
		$language_code = is_string( $language_code ) ? strtoupper( sanitize_key( $language_code ) ) : '';

		return $language_code ? "[$language_code] $title" : $title;
	}

	/**
	 * Add language code to term name
	 *
	 * @param string $name    The original name of the term.
	 * @param int    $term_id The ID of the term.
	 * @param string $taxonomy The taxonomy of the term.
	 * @return string The modified name with the language suffix.
	 *
	 * @since 1.11
	 */
	public function add_language_to_term_name( $name, $term_id, $taxonomy ) {
		\Bricks\Ajax::verify_nonce( 'bricks-nonce-builder' );

		if ( ! isset( $_GET['addLanguageToTermName'] ) || ! filter_var( $_GET['addLanguageToTermName'], FILTER_VALIDATE_BOOLEAN ) ) {
			return $name;
		}

		$term_id = absint( $term_id );

		if ( $term_id === 0 || ! term_exists( $term_id, $taxonomy ) ) {
			return $name;
		}

		if ( ! function_exists( 'apply_filters' ) || ! has_filter( 'wpml_element_language_details' ) ) {
			return $name;
		}

		$language_details = apply_filters(
			'wpml_element_language_details',
			null,
			[
				'element_id'   => $term_id,
				'element_type' => $taxonomy,
			]
		);

		if ( ! is_object( $language_details ) || ! isset( $language_details->language_code ) ) {
			return $name;
		}

		$language_code = strtoupper( sanitize_key( $language_details->language_code ) );

		if ( ! empty( $language_code ) ) {
			return '[' . $language_code . '] ' . $name;
		}

		return $name;
	}

	/**
	 * Get all nav menus for the builder control.
	 *
	 * @param array|null $menus Nav menu terms.
	 * @return array
	 *
	 * @since 2.3.5
	 */
	public function get_all_nav_menus( $menus ) {
		global $sitepress;

		if ( ! is_object( $sitepress ) ) {
			return is_array( $menus ) ? $menus : wp_get_nav_menus();
		}

		$has_get_terms_args_filter = remove_filter( 'get_terms_args', [ $sitepress, 'get_terms_args_filter' ] );
		$has_get_term_filter       = remove_filter( 'get_term', [ $sitepress, 'get_term_adjust_id' ], 1 );
		$has_terms_clauses_filter  = remove_filter( 'terms_clauses', [ $sitepress, 'terms_clauses' ] );

		try {
			if ( $menus === null ) {
				$menus = wp_get_nav_menus();
			}
		} finally {
			if ( $has_terms_clauses_filter ) {
				add_filter( 'terms_clauses', [ $sitepress, 'terms_clauses' ], 10, 3 );
			}

			if ( $has_get_term_filter ) {
				add_filter( 'get_term', [ $sitepress, 'get_term_adjust_id' ], 1, 1 );
			}

			if ( $has_get_terms_args_filter ) {
				add_filter( 'get_terms_args', [ $sitepress, 'get_terms_args_filter' ], 10, 2 );
			}
		}

		return $menus;
	}

	/**
	 * Add language code to nav menu name.
	 *
	 * @param string   $name The original name of the nav menu.
	 * @param \WP_Term $menu Nav menu term.
	 * @return string The modified name with the language prefix.
	 *
	 * @since 2.3.5
	 */
	public function add_language_to_nav_menu_name( $name, $menu ) {
		if ( ! is_object( $menu ) || empty( $menu->term_id ) ) {
			return $name;
		}

		$language_code = ! empty( $menu->term_taxonomy_id )
			? $this->get_nav_menu_language_code( $menu->term_taxonomy_id )
			: '';
		$language_code = ! empty( $language_code ) ? strtoupper( sanitize_key( $language_code ) ) : '';

		if ( ! empty( $language_code ) ) {
			return '[' . $language_code . '] ' . $name;
		}

		return $name;
	}

	/**
	 * Get WPML language code for a nav menu term.
	 *
	 * @param int $term_taxonomy_id Nav menu term taxonomy ID.
	 * @return string
	 *
	 * @since 2.3.5
	 */
	private function get_nav_menu_language_code( $term_taxonomy_id ) {
		if ( ! has_filter( 'wpml_element_language_code' ) ) {
			return '';
		}

		return apply_filters(
			'wpml_element_language_code',
			null,
			[
				'element_id'   => $term_taxonomy_id,
				'element_type' => 'nav_menu',
			]
		);
	}

	/**
	 * Get the language code of a post
	 *
	 * @since 1.10
	 */
	public static function get_post_language_code( $post_id ) {
		$language_info = apply_filters( 'wpml_post_language_details', null, $post_id );

		return ! empty( $language_info['language_code'] ) ? $language_info['language_code'] : '';
	}

	/**
	 * Get the current language code in WPML
	 *
	 * @return string|null The current language code or null if not set.
	 *
	 * @since 1.9.9
	 */
	public static function get_current_language() {
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return \ICL_LANGUAGE_CODE; // phpcs:ignore
		}

		return null;
	}

	/**
	 * Get the URL format for WPML
	 *
	 * @since 1.9.9
	 */
	public static function get_url_format() {
		global $sitepress;

		if ( ! $sitepress || ! method_exists( $sitepress, 'get_setting' ) ) {
			return null;
		}

		return $sitepress->get_setting( 'language_negotiation_type' );
	}

	/**
	 * Filter the builder edit link to include the language code
	 *
	 * @param string $url The original builder edit URL.
	 * @param int    $post_id The post ID.
	 * @return string The filtered URL.
	 *
	 * @since 1.10
	 */
	public function filter_builder_edit_link( $url, $post_id ) {
		if ( empty( $url ) || empty( $post_id ) || ! is_numeric( $post_id ) ) {
			return $url;
		}

		if ( ! get_post( $post_id ) ) {
			return $url;
		}

		$post_language_details = apply_filters( 'wpml_post_language_details', null, $post_id );

		// Verify we got a valid array/object response and it has the required language_code
		if ( ! empty( $post_language_details ) &&
			is_array( $post_language_details ) &&
			isset( $post_language_details['language_code'] ) &&
			! empty( $post_language_details['language_code'] )
		) {

			// Sanitize the language code
			$lang_code = sanitize_key( $post_language_details['language_code'] );

			$url = apply_filters( 'wpml_permalink', $url, $lang_code );
		}

		return $url;
	}

	/**
	 * Get content language for cache keys.
	 *
	 * @return string
	 *
	 * @since 2.3.6
	 */
	private static function get_content_language() {
		if ( ! empty( Database::$page_data['language'] ) ) {
			return sanitize_key( Database::$page_data['language'] );
		}

		// WPML can switch query language within a request while ICL_LANGUAGE_CODE stays fixed.
		$current_language = apply_filters( 'wpml_current_language', null );
		if ( empty( $current_language ) ) {
			$current_language = self::get_current_language();
		}

		return ! empty( $current_language ) ? sanitize_key( $current_language ) : get_locale();
	}

	/**
	 * Add content language to template query cache key.
	 *
	 * @param string $cache_key
	 * @return string
	 *
	 * @since 2.3.6
	 */
	public function add_template_language_cache_key( $cache_key ) {
		return $cache_key . '_' . self::get_content_language();
	}

	/**
	 * Prefix cache key with content language to ensure correct templates are loaded for different languages
	 *
	 * @since 1.7.1
	 */
	public function get_all_templates_cache_key( $cache_key ) {
		return self::get_content_language() . "_$cache_key";
	}

	/**
	 * Reassign new IDs for filter elements when fixing the filter element DB
	 *
	 * @since 1.12.2
	 */
	public function fix_filter_element_db( $handled, $post_id, $template_type ) {
		$language_code = self::get_post_language_code( $post_id );

		if ( ! $language_code ) {
			return $handled;
		}

		$default_language = apply_filters( 'wpml_default_language', null );

		// If default language, skip
		if ( $language_code === $default_language ) {
			return $handled;
		}

		// We need to reassign new IDs for filter elements
		$filter_elements = \Bricks\Query_Filters::filter_controls_elements();

		// Ensure each Filter element Bricks ID is unique
		$bricks_elements = Database::get_data( $post_id, $template_type );

		$bricks_elements = \Bricks\Helpers::generate_new_element_ids( $bricks_elements, $filter_elements );

		// Update the post meta with the new elements, will auto update custom element DB and reindex
		self::update_post_meta_preserving_slashes( $post_id, Database::get_bricks_data_key( $template_type ), $bricks_elements );

		// Return true to indicate the filter element DB has been handled
		return true;
	}

	/**
	 * Insert language code into the element settings
	 *
	 * @since 1.12.2
	 */
	public function set_filter_element_language( $data, $element, $post_id ) {
		// Get the language code of the post
		$language_code = self::get_post_language_code( $post_id );

		if ( empty( $language_code ) ) {
			return $data;
		}

		// Insert the language code into the element settings
		$data['language'] = $language_code;

		return $data;
	}

	/**
	 * Switch language based on query filter index job
	 * Otherwise, the values of the index records is following the current language set by WPML plugin
	 *
	 * @since 1.12.2
	 */
	public function bricks_execute_filter_index_job( $job ) {
		$language_code = $job['language'] ?? false;

		if ( ! empty( $language_code ) ) {
			do_action( 'wpml_switch_language', $language_code );
		}
	}

	/**
	 * Run WPML hooks to auto-adjust term IDs in Bricks frontend endpoints
	 *
	 * Adjust queried categories and tags ids according to the language
	 *
	 * @since 1.12.2
	 */
	public function wpml_get_term_adjust_id( $request_data ) {
		global $sitepress;

		if ( ! $sitepress || ! method_exists( $sitepress, 'get_setting' ) ) {
			return;
		}

		// @see sitepress.class.php set_term_filters_and_hooks()
		if ( $sitepress->get_setting( 'auto_adjust_ids' ) ) {
			add_filter( 'get_term', [ $sitepress, 'get_term_adjust_id' ], 1, 1 );
		}
	}

	/**
	 * Register component strings for translation when components are saved
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $value     The new option value.
	 *
	 * @since 2.1
	 */
	public function register_components_string_packages( $old_value, $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return;
		}

		// Register each component as its own package with unique name
		foreach ( $value as $component ) {
			if ( ! isset( $component['id'] ) ) {
				continue;
			}

			$component_id   = $component['id'];
			$component_name = $component['name'] ?? "Component $component_id";

			// Create unique package for this component
			$package = [
				'kind'      => 'Bricks components',
				'kind_slug' => 'bricks-components',
				'name'      => $component_id,
				'title'     => $component_name,
			];

			// Start string package registration for this component
			do_action( 'wpml_start_string_package_registration', $package );

			// Process component elements if they exist
			if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
				// Build the elements tree and traverse it
				$elements_tree = \Bricks\Helpers::build_elements_tree( $component['elements'] );
				$this->traverse_elements_tree( $elements_tree, $package );
			}

			// Process component properties defaults if they exist
			if ( isset( $component['properties'] ) && is_array( $component['properties'] ) ) {
				$this->process_component_properties_defaults( $component['properties'], $package );
			}

			// End string package registration and cleanup unused strings
			do_action( 'wpml_delete_unused_package_strings', $package );
		}
	}

	/**
	 * Process component properties default values for translation
	 *
	 * @param array $properties The component properties array.
	 * @param array $package    The string package data.
	 *
	 * @since 2.1
	 * @since 2.4.5 Include internal link parameters in default property packages.
	 */
	private function process_component_properties_defaults( $properties, $package ) {
		foreach ( $properties as $property ) {
			if ( ! isset( $property['id'] ) || ! isset( $property['type'] ) ) {
				continue;
			}

			$property_id    = $property['id'];
			$property_type  = $property['type'];
			$property_label = $property['label'] ?? "Property $property_id";
			$default_value  = $property['default'] ?? null;

			if ( ! $default_value ) {
				continue;
			}

			switch ( $property_type ) {
				case 'text':
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$string_name = "property_{$property_id}_default";
						do_action( 'wpml_register_string', $default_value, $string_name, $package, $property_label, 'LINE' );
					}
					break;

				case 'editor':
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$string_name = "property_{$property_id}_default";
						do_action( 'wpml_register_string', $default_value, $string_name, $package, $property_label, 'TEXTAREA' );
					}
					break;

				case 'image':
					if ( is_array( $default_value ) && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$string_name = "property_{$property_id}_default_url";
						do_action( 'wpml_register_string', $default_value['url'], $string_name, $package, $property_label, 'LINE' );
					}
					break;

				case 'image-gallery':
					if ( is_array( $default_value ) && isset( $default_value['images'] ) && is_array( $default_value['images'] ) ) {
						foreach ( $default_value['images'] as $index => $image ) {
							if ( isset( $image['url'] ) && ! empty( $image['url'] ) ) {
								$string_name = "property_{$property_id}_default_image_{$index}_url";
								do_action( 'wpml_register_string', $image['url'], $string_name, $package, $property_label, 'LINE' );
							}
						}
					}
					break;

				case 'link':
					if ( in_array( $default_value['type'] ?? '', [ 'internal', 'external' ], true ) && self::get_wpml_link_field( $default_value ) ) {
						$link_field  = self::get_wpml_link_field( $default_value );
						$string_name = "property_{$property_id}_default_{$link_field}";
						do_action( 'wpml_register_string', $default_value[ $link_field ], $string_name, $package, $property_label, 'LINE' );
					}
					break;

				case 'select':
					if ( isset( $property['options'] ) && is_array( $property['options'] ) ) {
						foreach ( $property['options'] as $option_index => $option ) {
							if ( isset( $option['label'] ) && ! empty( $option['label'] ) ) {
								$string_name = "property_{$property_id}_option_{$option_index}_label";
								do_action( 'wpml_register_string', $option['label'], $string_name, $package, $property_label, 'LINE' );
							}
							if ( isset( $option['value'] ) && ! empty( $option['value'] ) ) {
								$string_name = "property_{$property_id}_option_{$option_index}_value";
								do_action( 'wpml_register_string', $option['value'], $string_name, $package, $property_label, 'LINE' );
							}
						}
					}
					break;
			}
		}
	}

	/**
	 * Declare string package kind for components
	 *
	 * @param array $active_string_package_kinds
	 * @return array
	 *
	 * @since 2.1
	 */
	public function declare_component_string_package_kind( $active_string_package_kinds ) {
		$active_string_package_kinds['bricks-components'] = [
			'title'  => 'Bricks Components',
			'slug'   => 'bricks-components',
			'plural' => 'Bricks Components',
		];

		return $active_string_package_kinds;
	}

	/**
	 * Translate a component on-the-fly using WPML string translations
	 *
	 * Component and child-element lookups share the translated definition within this request.
	 * Helpers::get_component_instance() applies each instance's overrides to the returned value
	 * afterward, so differently configured instances can reuse translations without sharing state.
	 *
	 * @param array $component The component to translate.
	 * @return array The component with translated strings.
	 *
	 * @since 2.1
	 * @since 2.4.3 Reuse unchanged frontend component translations per request (#86cbfk591).
	 */
	public static function get_translated_component( $component ) {
		$current_language = self::get_current_language();
		$default_language = apply_filters( 'wpml_default_language', null );

		// Return original if current language is default or not set
		if ( ! $current_language || $current_language === $default_language ) {
			return $component;
		}

		// Each component has its own package with unique name
		if ( ! isset( $component['id'] ) ) {
			return $component;
		}

		$component_id   = $component['id'];
		$component_name = $component['name'] ?? "Component $component_id";
		$cache_enabled  = self::can_cache_component_translation( $component );
		$cache_key      = wp_json_encode( [ get_current_blog_id(), $current_language, $default_language, $component_id ] );
		$generation     = self::$translation_cache_generation;

		if ( $cache_enabled && isset( self::$translated_components[ $cache_key ] ) ) {
			$cached = self::$translated_components[ $cache_key ];

			// Compare the source too: unsaved or newly replaced definitions can reuse the same ID.
			if ( $cached['source'] === $component ) {
				return $cached['translated'];
			}
		}

		// A bypass must not leave an older result available when caching resumes later.
		unset( self::$translated_components[ $cache_key ] );

		// Create the package data for translation lookups
		$package = [
			'kind'      => 'Bricks components',
			'kind_slug' => 'bricks-components',
			'name'      => $component_id,
			'title'     => $component_name,
		];

		$translated_component = $component;

		// Translate component elements if they exist
		if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
			$translated_component['elements'] = self::get_translated_elements( $component['elements'], $package );
		}

		// Translate component properties defaults if they exist
		if ( isset( $component['properties'] ) && is_array( $component['properties'] ) ) {
			$translated_component['properties'] = self::get_translated_component_properties( $component['properties'], $package );
		}

		// A translation callback may update strings and clear the cache while this result is built.
		if ( $cache_enabled && $generation === self::$translation_cache_generation ) {
			// Store before instance properties, variants, slots, and dynamic data are resolved.
			self::$translated_components[ $cache_key ] = [
				'source'     => $component,
				'translated' => $translated_component,
			];
		}

		return $translated_component;
	}

	/**
	 * Clear request-local definitions after WPML changes its strings or translations.
	 *
	 * Advancing the generation also prevents an in-flight translation from repopulating the cache
	 * with a result assembled before the update. No persistent cache or saved component data is changed.
	 *
	 * @return void
	 * @since 2.4.3
	 */
	public static function reset_component_translation_cache() {
		self::$translated_components = [];
		++self::$translation_cache_generation;
	}

	/**
	 * Determine whether this frontend component translation can be reused.
	 *
	 * Editing/translation requests and mismatched language contexts always bypass the cache.
	 * For eligible frontend requests, the filter below can override callback eligibility.
	 *
	 * @param array $component Unresolved component definition.
	 * @return bool
	 * @since 2.4.3
	 */
	private static function can_cache_component_translation( $component ) {
		if ( is_admin() || bricks_is_builder() || self::$is_processing_wpml_translation ) {
			return false;
		}

		// WPML can switch its runtime language while ICL_LANGUAGE_CODE stays unchanged.
		if ( apply_filters( 'wpml_current_language', null ) !== self::get_current_language() ) {
			return false;
		}

		global $wp_filter;
		$callbacks = $wp_filter['wpml_translate_string']->callbacks ?? [];
		$cacheable = ! empty( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$function = $callback['function'];

				// Additional callbacks may depend on the current post, loop row, or instance.
				if ( ! is_array( $function ) || ! is_object( $function[0] ) || get_class( $function[0] ) !== 'WPML_Package_Translation' || $function[1] !== 'translate_string' ) {
					$cacheable = false;
				}
			}
		}

		/**
		 * Opt out, or opt in after verifying custom translation callbacks are context-independent.
		 *
		 * Returning true permits reuse across instances and loop rows for an unchanged definition.
		 * Return false when translation output depends on mutable context not covered by the cache key.
		 * This filter does not override the admin, Builder, or language-context guards above.
		 *
		 * @param bool  $cacheable Whether the registered callbacks are safe to reuse.
		 * @param array $component Unresolved component definition.
		 * @since 2.4.3
		 */
		return (bool) apply_filters( 'bricks/wpml/cache_component_translation', $cacheable, $component );
	}

	/**
	 * Translate component elements on-the-fly
	 *
	 * @param array $elements The elements to translate.
	 * @param array $package  The WPML package data.
	 * @return array The translated elements.
	 *
	 * @since 2.1
	 * @since 2.4.5 Read internal link parameters using their registered field-specific IDs.
	 */
	private static function get_translated_elements( $elements, $package ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}

		foreach ( $elements as &$element ) {
			if ( ! isset( $element['id'] ) ) {
				continue;
			}

			// Translate element settings
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( $element['settings'] as $setting_key => $setting_value ) {
					if ( is_string( $setting_value ) && ! empty( $setting_value ) ) {
						$string_id        = "{$element['id']}_{$setting_key}";
						$translated_value = apply_filters( 'wpml_translate_string', $setting_value, $string_id, $package );

						if ( $translated_value !== $setting_value ) {
							$element['settings'][ $setting_key ] = $translated_value;
						}
					}
					// External URL IDs keep their historical shape; internal parameter IDs add "_urlParams".
					elseif ( self::get_wpml_link_field( $setting_value ) ) {
						$link_field     = self::get_wpml_link_field( $setting_value );
						$string_id      = "{$element['id']}_{$setting_key}" . ( $link_field === 'urlParams' ? '_urlParams' : '' );
						$translated_url = apply_filters( 'wpml_translate_string', $setting_value[ $link_field ], $string_id, $package );

						if ( $translated_url !== $setting_value[ $link_field ] ) {
							$element['settings'][ $setting_key ][ $link_field ] = $translated_url;
						}
					}
					// Handle repeater settings
					elseif ( is_array( $setting_value ) ) {
						foreach ( $setting_value as $repeater_index => $repeater_item ) {
							if ( is_array( $repeater_item ) ) {
								foreach ( $repeater_item as $repeater_key => $repeater_value ) {
									if ( is_string( $repeater_value ) && ! empty( $repeater_value ) ) {
										$string_id        = "{$element['id']}_{$setting_key}_{$repeater_index}_{$repeater_key}";
										$translated_value = apply_filters( 'wpml_translate_string', $repeater_value, $string_id, $package );
										if ( $translated_value !== $repeater_value ) {
											$element['settings'][ $setting_key ][ $repeater_index ][ $repeater_key ] = $translated_value;
										}
									}
									// Repeater links use the same field-specific ID convention as top-level links.
									elseif ( self::get_wpml_link_field( $repeater_value ) ) {
										$link_field     = self::get_wpml_link_field( $repeater_value );
										$string_id      = "{$element['id']}_{$setting_key}_{$repeater_index}_{$repeater_key}" . ( $link_field === 'urlParams' ? '_urlParams' : '' );
										$translated_url = apply_filters( 'wpml_translate_string', $repeater_value[ $link_field ], $string_id, $package );
										if ( $translated_url !== $repeater_value[ $link_field ] ) {
											$element['settings'][ $setting_key ][ $repeater_index ][ $repeater_key ][ $link_field ] = $translated_url;
										}
									}
								}
							}
						}
					}
				}

				$query_settings  = Helpers::maybe_get_global_query_settings( $element['settings']['query'] ?? [] );
				$no_results_text = $query_settings['no_results_text'] ?? '';

				if ( is_string( $no_results_text ) && $no_results_text !== '' ) {
					$string_id        = "{$element['id']}_query_no_results_text";
					$translated_value = apply_filters( 'wpml_translate_string', $no_results_text, $string_id, $package );

					if ( $translated_value !== $no_results_text ) {
						$element['settings']['query']['no_results_text'] = $translated_value;
					}
				}
			}

			// Handle component properties (for component instances)
			if ( isset( $element['properties'] ) && is_array( $element['properties'] ) ) {
				foreach ( $element['properties'] as $property_key => $property_value ) {
					if ( is_string( $property_value ) && ! empty( $property_value ) ) {
						$string_id        = "{$element['id']}_prop_{$property_key}";
						$translated_value = apply_filters( 'wpml_translate_string', $property_value, $string_id, $package );
						if ( $translated_value !== $property_value ) {
							$element['properties'][ $property_key ] = $translated_value;
						}
					}
					// Handle link-type properties
					elseif ( self::get_wpml_link_field( $property_value ) ) {
						$link_field     = self::get_wpml_link_field( $property_value );
						$string_id      = "{$element['id']}_prop_{$property_key}_{$link_field}";
						$translated_url = apply_filters( 'wpml_translate_string', $property_value[ $link_field ], $string_id, $package );
						if ( $translated_url !== $property_value[ $link_field ] ) {
							$element['properties'][ $property_key ][ $link_field ] = $translated_url;
						}
					}
					elseif ( is_array( $property_value ) && isset( $property_value['images'] ) && is_array( $property_value['images'] ) ) {
						foreach ( $property_value['images'] as $image_index => $image ) {
							if ( isset( $image['url'] ) && ! empty( $image['url'] ) ) {
								$string_id      = "{$element['id']}_prop_{$property_key}_image_{$image_index}_url";
								$translated_url = apply_filters( 'wpml_translate_string', $image['url'], $string_id, $package );

								if ( $translated_url !== $image['url'] ) {
									$element['properties'][ $property_key ]['images'][ $image_index ]['url'] = $translated_url;
								}
							}
						}
					}
				}
			}

			// Recursively translate children
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$element['children'] = self::get_translated_elements( $element['children'], $package );
			}
		}

		return $elements;
	}

	/**
	 * Translate component properties defaults on-the-fly
	 *
	 * @param array $properties The properties to translate.
	 * @param array $package    The WPML package data.
	 * @return array The translated properties.
	 *
	 * @since 2.1
	 * @since 2.4.5 Translate internal link parameter defaults.
	 */
	private static function get_translated_component_properties( $properties, $package ) {
		if ( ! is_array( $properties ) ) {
			return $properties;
		}

		foreach ( $properties as &$property ) {
			if ( ! isset( $property['id'] ) || ! isset( $property['type'] ) ) {
				continue;
			}

			$property_id   = $property['id'];
			$property_type = $property['type'];
			$default_value = $property['default'] ?? null;

			if ( ! $default_value ) {
				continue;
			}

			switch ( $property_type ) {
				case 'text':
				case 'textarea':
				case 'editor':
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$string_id        = "property_{$property_id}_default";
						$translated_value = apply_filters( 'wpml_translate_string', $default_value, $string_id, $package );
						if ( $translated_value !== $default_value ) {
							$property['default'] = $translated_value;
						}
					}
					break;

				case 'image':
					if ( is_array( $default_value ) && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$string_id      = "property_{$property_id}_default_url";
						$translated_url = apply_filters( 'wpml_translate_string', $default_value['url'], $string_id, $package );
						if ( $translated_url !== $default_value['url'] ) {
							$property['default']['url'] = $translated_url;
						}
					}
					break;

				case 'link':
					if ( self::get_wpml_link_field( $default_value ) ) {
						$link_field     = self::get_wpml_link_field( $default_value );
						$string_id      = "property_{$property_id}_default_{$link_field}";
						$translated_url = apply_filters( 'wpml_translate_string', $default_value[ $link_field ], $string_id, $package );
						if ( $translated_url !== $default_value[ $link_field ] ) {
							$property['default'][ $link_field ] = $translated_url;
						}
					}
					break;

				case 'select':
					if ( isset( $property['options'] ) && is_array( $property['options'] ) ) {
						foreach ( $property['options'] as $option_index => $option ) {
							if ( isset( $option['label'] ) && ! empty( $option['label'] ) ) {
								$string_id        = "property_{$property_id}_option_{$option_index}_label";
								$translated_label = apply_filters( 'wpml_translate_string', $option['label'], $string_id, $package );
								if ( $translated_label !== $option['label'] ) {
									$property['options'][ $option_index ]['label'] = $translated_label;
								}
							}
							if ( isset( $option['value'] ) && ! empty( $option['value'] ) ) {
								$string_id        = "property_{$property_id}_option_{$option_index}_value";
								$translated_value = apply_filters( 'wpml_translate_string', $option['value'], $string_id, $package );
								if ( $translated_value !== $option['value'] ) {
									$property['options'][ $option_index ]['value'] = $translated_value;
								}
							}
						}
					}
					break;
			}
		}

		return $properties;
	}

	/**
	 * Switch WPML language for builder element labels.
	 *
	 * @param string $locale The locale to switch to.
	 *
	 * @since 2.2
	 */
	public function switch_builder_languge( $locale ) {
		if ( ! $locale ) {
			return;
		}

		global $sitepress;

		if ( ! $sitepress || ! method_exists( $sitepress, 'get_language_code_from_locale' ) || ! method_exists( $sitepress, 'get_current_language' ) ) {
			return;
		}

		$original_language = $sitepress->get_current_language();
		$language_code     = $sitepress->get_language_code_from_locale( $locale );

		if ( ! $language_code ) {
			return;
		}

		// Safe-guard to ensure we don't add multiple hooks if this function is called multiple times
		if ( $this->switch_builder_lang_before ) {
			remove_action( 'bricks/load_elements/before', $this->switch_builder_lang_before );
		}

		if ( $this->switch_builder_lang_after ) {
			remove_action( 'bricks/load_elements/after', $this->switch_builder_lang_after );
		}

		// Switch language before builder init so elements label and controls are in the correct language
		$this->switch_builder_lang_before = function() use ( $language_code ) {
			do_action( 'wpml_switch_language', $language_code );
		};

		// Restore to original language after builder init to avoid affecting other WPML functionalities
		$this->switch_builder_lang_after = function() use ( $original_language ) {
			do_action( 'wpml_switch_language', $original_language );

			// Cleanup
			remove_action( 'bricks/load_elements/before', $this->switch_builder_lang_before );
			remove_action( 'bricks/load_elements/after', $this->switch_builder_lang_after );

			$this->switch_builder_lang_before = null;
			$this->switch_builder_lang_after  = null;
		};

		// Add the hooks to switch language before and after builder elements are loaded
		add_action( 'bricks/load_elements/before', $this->switch_builder_lang_before );
		add_action( 'bricks/load_elements/after', $this->switch_builder_lang_after );
	}

	/**
	 * Translate component block attributes on-the-fly
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $post_id    Current post ID.
	 * @return array Translated attributes.
	 *
	 * @since 2.2
	 * @since 2.4.5 Read internal link parameter translations for component blocks.
	 */
	public static function translate_component_block_attributes( $attributes, $post_id ) {
		// Use the original post ID for package lookup if available
		// This handles the case where we are rendering a translation but strings are registered to original
		$original_post_id = apply_filters( 'wpml_original_element_id', null, $post_id, 'post_' . get_post_type( $post_id ) );
		if ( $original_post_id ) {
			$post_id = $original_post_id;
		}

		if ( empty( $attributes['properties'] ) || ! is_array( $attributes['properties'] ) ) {
			return $attributes;
		}

		$block_id = $attributes['blockId'] ?? '';

		if ( empty( $block_id ) ) {
			return $attributes;
		}

		// Package definition must match what WPML uses for Gutenberg blocks
		// Usually kind=Gutenberg, name=post_id, title="Page Builder Page $post_id"
		// However, providing just kind and name (which is post_id) should be sufficient for lookup
		$package = [
			'kind'    => 'Gutenberg',
			'name'    => (string) $post_id,
			'title'   => 'Page Builder Page ' . $post_id,
			'post_id' => $post_id,
		];

		foreach ( $attributes['properties'] as $key => $value ) {
			$string_name = $block_id . '_property_' . $key;

			// Handle text/textarea/editor
			if ( is_string( $value ) ) {
				$translated_value = apply_filters( 'wpml_translate_string', $value, $string_name, $package );
				if ( $translated_value !== $value ) {
					$attributes['properties'][ $key ] = $translated_value;
				}
			}
			// Handle link/image
			elseif ( self::get_wpml_link_field( $value ) ) {
				$link_field     = self::get_wpml_link_field( $value );
				$translated_url = apply_filters( 'wpml_translate_string', $value[ $link_field ], $string_name . '_' . $link_field, $package );
				if ( $translated_url !== $value[ $link_field ] ) {
					$attributes['properties'][ $key ][ $link_field ] = $translated_url;
				}
			}
			// Handle image gallery
			elseif ( is_array( $value ) && isset( $value['images'] ) && is_array( $value['images'] ) ) {
				foreach ( $value['images'] as $index => $image ) {
					if ( isset( $image['url'] ) ) {
						$translated_url = apply_filters( 'wpml_translate_string', $image['url'], $string_name . '_image_' . $index . '_url', $package );
						if ( $translated_url !== $image['url'] ) {
							$attributes['properties'][ $key ]['images'][ $index ]['url'] = $translated_url;
						}
					}
				}
			}
		}

		return $attributes;
	}
}

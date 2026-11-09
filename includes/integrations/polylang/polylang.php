<?php
namespace Bricks\Integrations\Polylang;

use Bricks\Elements;
use Bricks\Database;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Polylang {
	public static $is_active = false;

	public function __construct() {
		self::$is_active = class_exists( 'Polylang' );

		if ( ! self::$is_active ) {
			return;
		}

		if ( \Bricks\Maintenance::get_mode() ) {
			// Modify Polylang language switcher post ID when in maintenance mode (@since 2.0)
			add_filter( 'pll_the_languages_args', [ $this, 'modify_language_switcher_post_id' ] );
		}

		// Change the register hook to rest_pre_dispatch for Polylang in REST API requests (#86c3htjt0 @since 2.0)
		add_filter( 'bricks/dynamic_data/register_hook', [ $this, 'register_hook' ] );

		add_action( 'init', [ $this, 'init_elements' ] );

		// Set builder content language before templates and element controls are queried. (#86c9vt6tw; @since 2.3.6)
		add_action( 'wp', [ $this, 'set_builder_content_language' ], 1 );

		add_filter( 'bricks/helpers/get_posts_args', [ $this, 'polylang_get_posts_args' ] );
		add_filter( 'bricks/ajax/get_pages_args', [ $this, 'polylang_get_posts_args' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ] );

		// Output Polylang AJAX backend prefilter in the builder to enable media language filtering (@since 2.3.2)
		if ( bricks_is_builder_main() ) {
			add_action( 'wp_footer', [ $this, 'builder_polylang_ajax_prefilter' ], 21 );
		}

		// Choose which Bricks post metas to copy when duplicating a post
		add_filter( 'pll_copy_post_metas', [ $this, 'copy_bricks_post_metas' ], 10, 3 );

		// Modify Bricks IDs when copying post metas (@since 1.12.2)
		// add_filter( 'pll_translate_post_meta', [ $this, 'unique_bricks_ids' ], 10, 2 );

		add_filter( 'bricks/search_form/home_url', [ $this, 'modify_search_form_home_url' ] );

		add_filter( 'bricks/builder/post_title', [ $this, 'add_langugage_to_post_title' ], 10, 2 );

		// Add language code to term name (@since 1.11)
		add_filter( 'bricks/builder/term_name', [ $this, 'add_language_to_term_name' ], 10, 3 );
		add_filter( 'bricks/get_terms_options/excluded_taxonomies', [ $this, 'term_list_exclude_taxonomy' ], 10 );

		// Add language parameter to query args (@since 1.9.9)
		add_filter( 'bricks/posts/query_vars', [ $this, 'add_language_query_var' ], 100, 3 );
		add_filter( 'bricks/get_templates/query_vars', [ $this, 'add_template_language_query_var' ], 100 );
		add_filter( 'bricks/get_templates_query/cache_key', [ $this, 'add_template_language_cache_key' ] );
		add_filter( 'bricks/database/get_all_templates_cache_key', [ $this, 'get_all_templates_cache_key' ] );
		add_filter( 'bricks/database/bricks_get_all_templates_by_type_args', [ $this, 'polylang_get_all_templates_args' ] );

		// Add language to query loop cache key. (When enabled cacheQueryLoops) (@since 2.3.2)
		add_filter( 'bricks/query/cache_key', [ $this, 'add_query_language_cache_key' ], 100, 2 );

		// Add language code to populate correct export template link (@since 1.10)
		add_filter( 'bricks/export_template_args', [ $this, 'add_export_template_arg' ], 10, 2 );

		// Reassign filter element IDs for translated posts (fix DB AJAX) (@since 1.12.2)
		add_filter( 'bricks/fix_filter_element_db', [ $this, 'fix_filter_element_db' ], 10, 3 );

		// Add language code to filter element data (@since 1.12.2)
		add_filter( 'bricks/query_filters/element_data', [ $this, 'set_filter_element_language' ], 10, 3 );

		// Swicth locale for Bricks API requests (@since 2.0)
		add_action( 'bricks/render_query_result/start', [ $this, 'switch_locale' ] );
		add_action( 'bricks/render_query_page/start', [ $this, 'switch_locale' ] );
		add_action( 'bricks/render_popup_content/start', [ $this, 'switch_locale' ] );

		// Register global settings strings (@since 2.2)
		add_action( 'update_option_' . BRICKS_DB_GLOBAL_SETTINGS, [ $this, 'register_global_settings_strings' ], 10, 2 );

		// Translate global settings strings
		add_filter( 'bricks/user_activation_email/from_name', [ $this, 'translate_global_settings_string' ] );
		add_filter( 'bricks/user_activation_email/subject', [ $this, 'translate_global_settings_string' ] );
		add_filter( 'bricks/user_activation_email/content', [ $this, 'translate_global_settings_string' ] );

		/**
		 * Component translation support using Polylang String Translation
		 *
		 * @since 2.2
		 */
		if ( is_admin() ) {
			// Register strings (components, global settings) only on relevant admin pages (Polylang requirement)
			add_action( 'admin_init', [ $this, 'maybe_register_strings' ] );
		}
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
		if ( ! is_array( $value ) || empty( $value ) || ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		$strings = [
			'userActivationLinkEmailFromName' => false,
			'userActivationLinkEmailSubject'  => false,
			'userActivationLinkEmailContent'  => true,
		];

		foreach ( $strings as $key => $is_multiline ) {
			if ( ! empty( $value[ $key ] ) ) {
				// Use the key as the name for simplicity and consistency
				pll_register_string( $key, $value[ $key ], 'Bricks global settings', $is_multiline );
			}
		}
	}

	/**
	 * Register global settings strings from database
	 *
	 * @since 2.2
	 */
	private function register_global_settings_strings_from_db() {
		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
		$this->register_global_settings_strings( null, $settings );
	}

	/**
	 * Translate global settings string
	 *
	 * @param string $value
	 * @return string
	 */
	public function translate_global_settings_string( $value ) {
		if ( ! function_exists( 'pll__' ) || ! function_exists( 'pll_translate_string' ) ) {
			return $value;
		}

		// Check if we are in the context of a REST API request or AJAX request where the language might be set via query var
		$lang = self::get_current_language();

		if ( $lang ) {
			return pll_translate_string( $value, $lang );
		}

		// Fallback: If locale is switched via switch_to_locale() in init.php, try to get language from that
		$current_locale = get_locale();
		$poly_lang      = \PLL()->model->get_language( $current_locale );

		if ( $poly_lang && isset( $poly_lang->slug ) ) {
			return pll_translate_string( $value, $poly_lang->slug );
		}

		return pll__( $value );
	}

	/**
	 * init or rest_api_init is too early and causing Poylang no language set, use rest_pre_dispatch which will run after rest_api_init and before callback.
	 *
	 * #86c3htjt0
	 *
	 * @since 2.0.2
	 */
	public function register_hook( $hook ) {
		return \Bricks\Api::is_bricks_rest_request() ? 'rest_pre_dispatch' : $hook;
	}

	/**
	 * Set the builder content language from the edited post.
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
	 * Add language query var to cache key to avoid cache conflicts
	 *
	 * @since 1.9.9
	 */
	public function add_template_language_cache_key( $cache_key ) {
		// Retrieve the current language on page load
		$current_lang = self::get_current_language();

		// Use Database::$page_data['language'] if set (API request)
		if ( isset( Database::$page_data['language'] ) && ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
		}

		$cache_key .= '_' . $current_lang;

		return $cache_key;
	}

	/**
	 * Add language to query loop cache key. (When enabled cacheQueryLoops)
	 *
	 * @since 2.3.2
	 */
	public function add_query_language_cache_key( $cache_key, $query ) {
		// Retrieve the current language on page load
		$current_lang = self::get_current_language();

		// Use Database::$page_data['language'] if set (API request)
		if ( isset( Database::$page_data['language'] ) && ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
		}

		// If we still don't have a language, return the original cache key
		if ( empty( $current_lang ) ) {
			return $cache_key;
		}

		return $cache_key . '_' . $current_lang;
	}

	/**
	 * Add language query var when getting templates
	 *
	 * @since 1.9.9
	 */
	public function add_template_language_query_var( $args ) {
		// Check if the current language is set (@since 1.9.9) and the post type is translated (@since 1.11.1)
		if ( ! empty( Database::$page_data['language'] ) &&
			function_exists( 'pll_is_translated_post_type' ) &&
			pll_is_translated_post_type( BRICKS_DB_TEMPLATE_SLUG )
		) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
			// Set the language query var
			$args['lang'] = $current_lang;
		}

		return $args;
	}

	/**
	 * Add current language to cache key
	 * Unable to use get_locale() in API endpoint
	 *
	 * @since 1.12.2
	 */
	public function get_all_templates_cache_key( $cache_key ) {
		// Retrieve the current language on page load
		$current_lang = self::get_current_language();

		// Use Database::$page_data['language'] if set (API request)
		if ( isset( Database::$page_data['language'] ) && ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
		}

		return $current_lang . "_$cache_key";
	}

	/**
	 * Add language query var when getting all templates (database.php)
	 *
	 * @since 1.12.2
	 */
	public function polylang_get_all_templates_args( $query_args ) {
		// Retrieve the current language on page load
		$current_lang = self::get_current_language();

		// Use Database::$page_data['language'] if set (API request)
		if ( isset( Database::$page_data['language'] ) && ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );
		}

		// Set the language query var
		$query_args['lang'] = $current_lang;

		return $query_args;
	}

	/**
	 * Add language query var
	 *
	 * @since 1.9.9
	 */
	public function add_language_query_var( $query_vars, $settings, $element_id ) {
		if ( ! empty( Database::$page_data['language'] ) ) {
			$current_lang = sanitize_key( Database::$page_data['language'] );

			// Whether to not set the language query var if the post type is not translated
			if ( isset( $query_vars['post_type'] ) && function_exists( 'pll_is_translated_post_type' ) ) {
				$post_type               = $query_vars['post_type'];
				$is_translated_post_type = true;

				// Polylang function to check if a post type is translated
				$check_is_translated_post_type = function( $pt ) {
					return pll_is_translated_post_type( $pt );
				};

				if ( is_array( $post_type ) ) {
					// Multiple post types are queried, as long as one of them is not translated, do not set the language query var as no way to determine the language
					foreach ( $post_type as $pt ) {
						if ( ! $check_is_translated_post_type( $pt ) ) {
							$is_translated_post_type = false;
							break;
						}
					}
				} else {
					$is_translated_post_type = $check_is_translated_post_type( $post_type );
				}

				if ( ! $is_translated_post_type ) {
					// Post type is not translated, so do not set the language query var
					$current_lang = '';
				}
			}

			// Set the language query var
			$query_vars['lang'] = $current_lang;
		}

		return $query_vars;
	}

	public function wp_enqueue_scripts() {
		wp_enqueue_style( 'bricks-polylang', BRICKS_URL_ASSETS . 'css/integrations/polylang.min.css', [ 'bricks-frontend' ], filemtime( BRICKS_PATH_ASSETS . 'css/integrations/polylang.min.css' ) );
	}

	/**
	 * Output jQuery AJAX prefilter in the builder to inject Polylang's pll_ajax_backend parameter
	 *
	 * Polylang uses pll_ajax_backend to distinguish admin AJAX requests from frontend ones.
	 * Without this parameter, media library AJAX requests from the builder are treated as frontend
	 * requests, which prevents language filtering and the per-attachment language dropdown.
	 *
	 * @see PLL_Admin_Base::admin_print_footer_scripts() in Polylang
	 * @see Polylang::is_ajax_on_front() for how pll_ajax_backend is checked
	 *
	 * @since 2.3.2
	 */
	public function builder_polylang_ajax_prefilter() {
		global $post;

		$post_id = $post instanceof \WP_Post ? $post->ID : 0;

		$params = [ 'pll_ajax_backend' => 1 ];

		if ( ! empty( $post_id ) ) {
			$params['pll_post_id'] = (int) $post_id;
		}

		/**
		 * Filters the Polylang AJAX parameters injected into media library requests in the builder
		 *
		 * @since 2.3.2
		 *
		 * @param array $params  Parameters to add to media AJAX requests.
		 * @param int   $post_id Current post ID.
		 */
		$params = apply_filters( 'bricks/polylang/builder_ajax_params', $params, $post_id );

		$str = http_build_query( $params );
		$arr = wp_json_encode( $params );
		?>
		<script>
			if (typeof jQuery != 'undefined') {
				jQuery(function($) {
					var pllAjaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php', 'relative' ) ); ?>';
					var mediaActions = ['query-attachments', 'save-attachment', 'send-attachment-to-editor', 'get-attachment', 'save-attachment-compat', 'save-attachment-order', 'bricks_import_media_from_url'];

					function getAction(data) {
						if ('string' === typeof data) {
							var match = data.match(/(?:^|&)action=([^&]*)/);
							return match ? decodeURIComponent(match[1]) : '';
						}
						if (data && 'object' === typeof data) {
							return data.action || '';
						}
						return '';
					}

					$.ajaxPrefilter(function(options, originalOptions, jqXHR) {
						if (-1 === options.url.indexOf(pllAjaxUrl) && -1 === pllAjaxUrl.indexOf(options.url)) {
							return;
						}

						var action = getAction(originalOptions.data);

						if (-1 === mediaActions.indexOf(action)) {
							return;
						}

						if ('string' === typeof options.data) {
							options.data += '&<?php echo $str; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
						} else if ('object' === typeof options.data && options.data) {
							Object.assign(options.data, <?php echo $arr; // phpcs:ignore WordPress.Security.EscapeOutput ?>);
						}
					});
				});
			}
		</script>
		<?php
	}

	/**
	 * Copy Bricks' post metas when duplicating a post
	 *
	 * @since 1.9.1
	 */
	public function copy_bricks_post_metas( $metas, $sync, $original_post_id ) {
		// Return: Do not copy metas when syncing (let Polylang handle it)
		if ( $sync ) {
			return $metas;
		}

		// Return: Do not copy Bricks' metas when the post is not rendered with Bricks
		if ( \Bricks\Helpers::get_editor_mode( $original_post_id ) !== 'bricks' ) {
			return $metas;
		}

		$meta_keys_to_check = [
			BRICKS_DB_TEMPLATE_TYPE,
			BRICKS_DB_EDITOR_MODE,
			BRICKS_DB_PAGE_SETTINGS,
			BRICKS_DB_TEMPLATE_SETTINGS,
		];

		$template_type = get_post_meta( $original_post_id, BRICKS_DB_TEMPLATE_TYPE, true );

		if ( $template_type === 'header' ) {
			$meta_keys_to_check[] = BRICKS_DB_PAGE_HEADER;
		} elseif ( $template_type === 'footer' ) {
			$meta_keys_to_check[] = BRICKS_DB_PAGE_FOOTER;
		} else {
			$meta_keys_to_check[] = BRICKS_DB_PAGE_CONTENT;
		}

		$additional_metas = [];

		// Add metas only if they exist
		foreach ( $meta_keys_to_check as $meta_key_to_check ) {
			if ( metadata_exists( 'post', $original_post_id, $meta_key_to_check ) ) {
				$additional_metas[] = $meta_key_to_check;
			}
		}

		return array_merge( $metas, $additional_metas );
	}

	/**
	 * Modify Bricks IDs when translating/copy post meta
	 *
	 * @since 1.12.2
	 */
	public function unique_bricks_ids( $value, $meta_key ) {
		if ( ! in_array( $meta_key, [ BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_FOOTER ], true ) ) {
			return $value;
		}

		// Ensure each Bricks ID is unique
		return \Bricks\Helpers::generate_new_element_ids( $value );
	}

	/**
	 * Init Polylang elements
	 *
	 * polylang-language-switcher
	 */
	public function init_elements() {
		$polylang_elements = [
			'polylang-language-switcher',
		];

		foreach ( $polylang_elements as $element_name ) {
			$polylang_element_file = BRICKS_PATH . "includes/integrations/polylang/elements/$element_name.php";

			// Get the class name from the element name
			$class_name = str_replace( '-', '_', $element_name );
			$class_name = ucwords( $class_name, '_' );
			$class_name = "Bricks\\$class_name";

			if ( is_readable( $polylang_element_file ) ) {
				Elements::register_element( $polylang_element_file, $element_name, $class_name );
			}
		}
	}

	/**
	 * Set the query arg to get all the posts/pages languages
	 *
	 * @param array $query_args
	 * @return array
	 */
	public function polylang_get_posts_args( $query_args ) {
		if ( ! isset( $query_args['lang'] ) ) {
			$query_args['lang'] = '';
		}

		return $query_args;
	}

	/**
	 * Modify the search form action URL to use the home URL
	 *
	 * @param string $url
	 * @return string
	 *
	 * @since 1.9.4
	 */
	public function modify_search_form_home_url( $url ) {
		// Check if Polylang is active
		if ( function_exists( 'pll_current_language' ) ) {
			// Get the current language slug
			$current_lang_slug = pll_current_language( 'slug' );

			// Append the language slug to the base home URL (if it's not the default language)
			$default_lang = pll_default_language( 'slug' );
			if ( $current_lang_slug !== $default_lang ) {
				return trailingslashit( home_url() ) . $current_lang_slug;
			}
		}

		// Return the original URL if Polylang is not active or if it's the default language
		return $url;
	}

	/**
	 * Add language code to post title
	 *
	 * @param string $title   The original title of the page.
	 * @param int    $page_id The ID of the page.
	 * @return string The modified title with the language suffix.
	 *
	 * @since 1.9.4
	 */
	public function add_langugage_to_post_title( $title, $page_id ) {
		if ( isset( $_GET['addLanguageToPostTitle'] ) ) {
			$language_code = self::get_post_language_code( $page_id );
			$language_code = ! empty( $language_code ) ? strtoupper( $language_code ) : '';

			if ( ! empty( $language_code ) ) {
				return "[$language_code] $title";
			}
		}

		// Return the original title if conditions are not met
		return $title;
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

		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return $name;
		}

		$term_id = absint( $term_id );

		if ( $term_id === 0 || ! term_exists( $term_id, $taxonomy ) ) {
			return $name;
		}

		$language_code = pll_get_term_language( $term_id );
		$language_code = ! empty( $language_code ) ? strtoupper( sanitize_key( $language_code ) ) : '';

		if ( ! empty( $language_code ) ) {
			return '[' . $language_code . '] ' . $name;
		}

		return $name;
	}

	/**
	 * Get the language code of a post
	 *
	 * @since 1.10
	 */
	public static function get_post_language_code( $post_id ) {
		return function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';
	}

	/**
	 * Get the current language code in Polylang.
	 *
	 * @return string|null The current language code or null if not set.
	 *
	 * @since 1.9.9
	 */
	public static function get_current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			return \pll_current_language(); // phpcs:ignore
		}

		return null;
	}

	/**
	 * Reassign new IDs for filter elements when fixing the filter element DB
	 *
	 * @since 1.12.2
	 */
	public function fix_filter_element_db( $handled, $post_id, $template_type ) {
		$language_code = self::get_post_language_code( $post_id );

		if ( empty( $language_code ) ) {
			return $handled;
		}

		$default_language = pll_default_language( 'slug' );

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
		update_post_meta( $post_id, Database::get_bricks_data_key( $template_type ), $bricks_elements );

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
	 * Exclude the language taxonomy from the term list
	 * - Remove pll_xxxx term options from the term list in the builder
	 *
	 * @since 2.0
	 */
	public function term_list_exclude_taxonomy( $excluded_taxonomies ) {
		$excluded_taxonomies[] = 'post_translations';
		$excluded_taxonomies[] = 'term_translations';

		return $excluded_taxonomies;
	}

	/**
	 * Switch the locale for Bricks API requests
	 *
	 * @since 2.0
	 */
	public function switch_locale( $request_data ) {
		if ( ! empty( Database::$page_data['language'] ) && function_exists( 'pll_current_language' ) ) {
			// Polylang alreay set the language based on the request
			$locale = pll_current_language( 'locale' );
			if ( $locale ) {
				// Switch the locale to the current language
				switch_to_locale( $locale );
			}
		}
	}

	/**
	 * Modify the Polylang modify_language_switcher_post_id post ID when in maintenance mode
	 *
	 * @since 2.0
	 */
	public function modify_language_switcher_post_id( $args ) {
		$args['post_id'] = \Bricks\Maintenance::get_original_post_id() ?? $args['post_id'];

		return $args;
	}

	/**
	 * Maybe register strings (components, global settings) - only on relevant admin pages
	 *
	 * Only runs on:
	 * - Polylang strings admin page (admin.php?page=mlang_strings)
	 *
	 * @since 2.2
	 */
	public function maybe_register_strings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking admin page context, not processing form data
		$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

		// Only register on Polylang strings page
		$allowed_pages = [ 'mlang_strings' ];

		if ( ! in_array( $current_page, $allowed_pages, true ) ) {
			return;
		}

		$this->register_components_strings();
		$this->register_global_settings_strings_from_db();
	}

	/**
	 * Register component strings for translation
	 *
	 * Polylang requires strings to be registered on admin side.
	 *
	 * @since 2.2
	 */
	private function register_components_strings() {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		// Get components from database
		$components = get_option( BRICKS_DB_COMPONENTS, [] );

		if ( ! is_array( $components ) || empty( $components ) ) {
			return;
		}

		// Register each component's translatable strings
		foreach ( $components as $component ) {
			if ( ! isset( $component['id'] ) ) {
				continue;
			}

			$component_id = $component['id'];

			// Context for all strings in this component
			$context = "bricks-component-$component_id";

			// Process component elements if they exist
			if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
				// Build the elements tree and traverse it
				$elements_tree = \Bricks\Helpers::build_elements_tree( $component['elements'] );
				$this->traverse_elements_tree( $elements_tree, $context );
			}

			// Process component properties defaults if they exist
			if ( isset( $component['properties'] ) && is_array( $component['properties'] ) ) {
				$this->process_component_properties_defaults( $component['properties'], $context );
			}
		}
	}

	/**
	 * Traverse the tree and process each element in a depth-first manner.
	 *
	 * @param array  $elements
	 * @param string $context Polylang context string.
	 *
	 * @since 2.2
	 */
	private function traverse_elements_tree( $elements, $context ) {
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
			$this->process_element( $element, $context );

			// Recursively process children
			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$this->traverse_elements_tree( $element['children'], $context );
			}
		}
	}

	/**
	 * Process an element and register its translatable strings
	 *
	 * @param array  $element
	 * @param string $context Polylang context string.
	 *
	 * @since 2.2
	 */
	private function process_element( $element, $context ) {
		$element_name     = ! empty( $element['name'] ) ? $element['name'] : false;
		$element_settings = ! empty( $element['settings'] ) ? $element['settings'] : false;
		$element_config   = Elements::get_element( [ 'name' => $element_name ] );
		$element_controls = ! empty( $element_config['controls'] ) ? $element_config['controls'] : false;

		// Handle component properties (@since 2.2)
		if ( isset( $element['cid'] ) && isset( $element['properties'] ) && is_array( $element['properties'] ) ) {
			$this->process_component_properties( $element, $context );
		}

		if ( ! $element_settings || ! $element_name || ! is_array( $element_controls ) ) {
			return;
		}

		$translatable_control_types = [ 'text', 'textarea', 'editor', 'repeater', 'link' ];

		// Loop over element controls to get translatable settings
		foreach ( $element_controls as $key => $control ) {
			$this->process_control( $key, $control, $element_settings, $element, $translatable_control_types, $context );
		}
	}

	/**
	 * Process component properties for translation
	 *
	 * @param array  $element The element containing component properties.
	 * @param string $context Polylang context string.
	 *
	 * @since 2.2
	 */
	private function process_component_properties( $element, $context ) {
		if ( ! isset( $element['id'] ) || ! isset( $element['cid'] ) || ! isset( $element['properties'] ) ) {
			return;
		}

		$element_id = $element['id'];
		$properties = $element['properties'];

		foreach ( $properties as $property_key => $property_value ) {
			// Handle direct text/HTML strings (text & textarea properties)
			if ( is_string( $property_value ) && ! empty( $property_value ) ) {
				$string_name = "{$element_id}_prop_{$property_key}";
				$this->register_polylang_string( $property_value, $string_name, $context );
			}

			// Handle objects with URLs (link property)
			elseif ( is_array( $property_value ) ) {
				// Handle link-type properties
				if ( isset( $property_value['url'] ) && is_string( $property_value['url'] ) && ! empty( $property_value['url'] ) ) {
					$string_name = "{$element_id}_prop_{$property_key}_url";
					$this->register_polylang_string( $property_value['url'], $string_name, $context );
				}

				// Handle image galleries or image properties
				if ( isset( $property_value['images'] ) && is_array( $property_value['images'] ) ) {
					foreach ( $property_value['images'] as $index => $image ) {
						if ( isset( $image['url'] ) && is_string( $image['url'] ) && ! empty( $image['url'] ) ) {
							$string_name = "{$element_id}_prop_{$property_key}_image_{$index}_url";
							$this->register_polylang_string( $image['url'], $string_name, $context );
						}
					}
				}
			}
		}
	}

	/**
	 * Process a control and register its translatable strings
	 *
	 * @param string $key
	 * @param array  $control
	 * @param array  $element_settings
	 * @param array  $element
	 * @param array  $translatable_control_types
	 * @param string $context Polylang context string.
	 *
	 * @since 2.2
	 */
	private function process_control( $key, $control, $element_settings, $element, $translatable_control_types, $context ) {
		$control_type = ! empty( $control['type'] ) ? $control['type'] : false;

		if ( ! in_array( $control_type, $translatable_control_types ) ) {
			return;
		}

		// Exclude certain controls from translation according to their key
		// Filter @since 2.3.3
		$exclude_control_from_translation = apply_filters(
			'bricks/polylang/exclude_controls_from_translation',
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
			$this->process_repeater_control( $key, $control, $element_settings, $element, $translatable_control_types, $context );
			return;
		}

		// If control type is link, specifically process the URL
		if ( $control_type === 'link' && isset( $string_value['url'] ) ) {
			$string_value = $string_value['url'];
		}

		if ( ! is_string( $string_value ) || empty( $string_value ) ) {
			return;
		}

		$string_name = "{$element['id']}_{$key}";
		$this->register_polylang_string( $string_value, $string_name, $context );
	}

	/**
	 * Process a repeater control and register its translatable strings
	 *
	 * @param string $key
	 * @param array  $control
	 * @param array  $element_settings
	 * @param array  $element
	 * @param array  $translatable_control_types
	 * @param string $context Polylang context string.
	 *
	 * @since 2.2
	 */
	private function process_repeater_control( $key, $control, $element_settings, $element, $translatable_control_types, $context ) {
		$repeater_items = ! empty( $element_settings[ $key ] ) ? $element_settings[ $key ] : [];

		if ( is_array( $repeater_items ) ) {
			foreach ( $repeater_items as $repeater_index => $repeater_item ) {
				if ( is_array( $repeater_item ) ) {
					foreach ( $repeater_item as $repeater_key => $repeater_value ) {
						// Get the type of this field, check if it's one of the accepted types
						$repeater_field_type = isset( $control['fields'][ $repeater_key ]['type'] ) ? $control['fields'][ $repeater_key ]['type'] : false;
						if ( ! in_array( $repeater_field_type, $translatable_control_types ) ) {
							continue;
						}

						$string_value = ! empty( $repeater_value ) ? $repeater_value : '';

						// If control type is link, get the URL
						if ( $repeater_field_type === 'link' && isset( $string_value['url'] ) ) {
							$string_value = $string_value['url'];
						}

						if ( ! is_string( $string_value ) || empty( $string_value ) ) {
							continue;
						}

						$string_name = "{$element['id']}_{$key}_{$repeater_index}_{$repeater_key}";
						$this->register_polylang_string( $string_value, $string_name, $context );
					}
				}
			}
		}
	}

	/**
	 * Helper function to register a string for translation in Polylang
	 *
	 * @param string $string_value The string value to register.
	 * @param string $string_name  The unique identifier for the string.
	 * @param string $context      The context/group for the string.
	 *
	 * @since 2.2
	 */
	private function register_polylang_string( $string_value, $string_name, $context ) {
		if ( ! $string_value || ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		pll_register_string( $string_name, $string_value, $context, false );
	}

	/**
	 * Process component properties default values for translation
	 *
	 * @param array  $properties The component properties array.
	 * @param string $context    Polylang context string.
	 *
	 * @since 2.2
	 */
	private function process_component_properties_defaults( $properties, $context ) {
		foreach ( $properties as $property ) {
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
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$string_name = "property_{$property_id}_default";
						$this->register_polylang_string( $default_value, $string_name, $context );
					}
					break;

				case 'editor':
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$string_name = "property_{$property_id}_default";
						$this->register_polylang_string( $default_value, $string_name, $context );
					}
					break;

				case 'image':
					if ( is_array( $default_value ) && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$string_name = "property_{$property_id}_default_url";
						$this->register_polylang_string( $default_value['url'], $string_name, $context );
					}
					break;

				case 'image-gallery':
					if ( is_array( $default_value ) && isset( $default_value['images'] ) && is_array( $default_value['images'] ) ) {
						foreach ( $default_value['images'] as $index => $image ) {
							if ( isset( $image['url'] ) && ! empty( $image['url'] ) ) {
								$string_name = "property_{$property_id}_default_image_{$index}_url";
								$this->register_polylang_string( $image['url'], $string_name, $context );
							}
						}
					}
					break;

				case 'link':
					if ( is_array( $default_value ) && isset( $default_value['type'] ) && $default_value['type'] === 'external' && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$string_name = "property_{$property_id}_default_url";
						$this->register_polylang_string( $default_value['url'], $string_name, $context );
					}
					break;

				case 'select':
					if ( isset( $property['options'] ) && is_array( $property['options'] ) ) {
						foreach ( $property['options'] as $option_index => $option ) {
							if ( isset( $option['label'] ) && ! empty( $option['label'] ) ) {
								$string_name = "property_{$property_id}_option_{$option_index}_label";
								$this->register_polylang_string( $option['label'], $string_name, $context );
							}
							if ( isset( $option['value'] ) && ! empty( $option['value'] ) ) {
								$string_name = "property_{$property_id}_option_{$option_index}_value";
								$this->register_polylang_string( $option['value'], $string_name, $context );
							}
						}
					}
					break;
			}
		}
	}

	/**
	 * Translate a component on-the-fly using Polylang string translations
	 *
	 * @param array $component The component to translate.
	 * @return array The component with translated strings.
	 *
	 * @since 2.2
	 */
	public static function get_translated_component( $component ) {
		$current_language = self::get_current_language();
		$default_language = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : null;

		// Return original if current language is default or not set
		if ( ! $current_language || $current_language === $default_language || ! function_exists( 'pll_translate_string' ) ) {
			return $component;
		}

		// Each component has its own context based on ID
		if ( ! isset( $component['id'] ) ) {
			return $component;
		}

		$translated_component = $component;

		// Translate component elements if they exist
		if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
			$translated_component['elements'] = self::get_translated_elements( $component['elements'], $current_language );
		}

		// Translate component properties defaults if they exist
		if ( isset( $component['properties'] ) && is_array( $component['properties'] ) ) {
			$translated_component['properties'] = self::get_translated_component_properties( $component['properties'], $current_language );
		}

		return $translated_component;
	}

	/**
	 * Translate component elements on-the-fly
	 *
	 * @param array  $elements The elements to translate.
	 * @param string $lang     The target language code.
	 * @return array The translated elements.
	 *
	 * @since 2.2
	 */
	private static function get_translated_elements( $elements, $lang ) {
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
						$translated_value = pll_translate_string( $setting_value, $lang );

						if ( $translated_value !== $setting_value ) {
							$element['settings'][ $setting_key ] = $translated_value;
						}
					}
					// Handle link settings (URL translation)
					elseif ( is_array( $setting_value ) && isset( $setting_value['url'] ) && ! empty( $setting_value['url'] ) ) {
						$translated_url = pll_translate_string( $setting_value['url'], $lang );

						if ( $translated_url !== $setting_value['url'] ) {
							$element['settings'][ $setting_key ]['url'] = $translated_url;
						}
					}
					// Handle repeater settings
					elseif ( is_array( $setting_value ) ) {
						foreach ( $setting_value as $repeater_index => $repeater_item ) {
							if ( is_array( $repeater_item ) ) {
								foreach ( $repeater_item as $repeater_key => $repeater_value ) {
									if ( is_string( $repeater_value ) && ! empty( $repeater_value ) ) {
										$translated_value = pll_translate_string( $repeater_value, $lang );
										if ( $translated_value !== $repeater_value ) {
											$element['settings'][ $setting_key ][ $repeater_index ][ $repeater_key ] = $translated_value;
										}
									}
									// Handle link in repeater
									elseif ( is_array( $repeater_value ) && isset( $repeater_value['url'] ) && ! empty( $repeater_value['url'] ) ) {
										$translated_url = pll_translate_string( $repeater_value['url'], $lang );
										if ( $translated_url !== $repeater_value['url'] ) {
											$element['settings'][ $setting_key ][ $repeater_index ][ $repeater_key ]['url'] = $translated_url;
										}
									}
								}
							}
						}
					}
				}
			}

			// Handle component properties (for component instances)
			if ( isset( $element['properties'] ) && is_array( $element['properties'] ) ) {
				foreach ( $element['properties'] as $property_key => $property_value ) {
					if ( is_string( $property_value ) && ! empty( $property_value ) ) {
						$translated_value = pll_translate_string( $property_value, $lang );
						if ( $translated_value !== $property_value ) {
							$element['properties'][ $property_key ] = $translated_value;
						}
					}
					// Handle link-type properties
					elseif ( is_array( $property_value ) && isset( $property_value['url'] ) && ! empty( $property_value['url'] ) ) {
						$translated_url = pll_translate_string( $property_value['url'], $lang );
						if ( $translated_url !== $property_value['url'] ) {
							$element['properties'][ $property_key ]['url'] = $translated_url;
						}
					}
				}
			}

			// Recursively translate children
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$element['children'] = self::get_translated_elements( $element['children'], $lang );
			}
		}

		return $elements;
	}

	/**
	 * Translate component properties defaults on-the-fly
	 *
	 * @param array  $properties The properties to translate.
	 * @param string $lang       The target language code.
	 * @return array The translated properties.
	 *
	 * @since 2.2
	 */
	private static function get_translated_component_properties( $properties, $lang ) {
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
				case 'editor':
					if ( is_string( $default_value ) && ! empty( $default_value ) ) {
						$translated_value = pll_translate_string( $default_value, $lang );
						if ( $translated_value !== $default_value ) {
							$property['default'] = $translated_value;
						}
					}
					break;

				case 'image':
					if ( is_array( $default_value ) && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$translated_url = pll_translate_string( $default_value['url'], $lang );
						if ( $translated_url !== $default_value['url'] ) {
							$property['default']['url'] = $translated_url;
						}
					}
					break;

				case 'link':
					if ( is_array( $default_value ) && isset( $default_value['url'] ) && ! empty( $default_value['url'] ) ) {
						$translated_url = pll_translate_string( $default_value['url'], $lang );
						if ( $translated_url !== $default_value['url'] ) {
							$property['default']['url'] = $translated_url;
						}
					}
					break;

				case 'select':
					if ( isset( $property['options'] ) && is_array( $property['options'] ) ) {
						foreach ( $property['options'] as $option_index => $option ) {
							if ( isset( $option['label'] ) && ! empty( $option['label'] ) ) {
								$translated_label = pll_translate_string( $option['label'], $lang );
								if ( $translated_label !== $option['label'] ) {
									$property['options'][ $option_index ]['label'] = $translated_label;
								}
							}
							if ( isset( $option['value'] ) && ! empty( $option['value'] ) ) {
								$translated_value = pll_translate_string( $option['value'], $lang );
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
}

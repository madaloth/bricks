<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Query {
	// The query unique ID
	private $id = '';

	// Component ID (@since 1.12.2)
	public $component_id = '';

	// Instance ID (@since 2.0)
	public $instance_id = '';

	// Element ID
	public $element_id = '';

	// Element name to be used in WooCommerce (@since 1.11.1)
	public $element_name = '';

	// Element settings
	public $settings = [];

	// Query vars
	public $query_vars = [];

	// Type of object queried: 'post', 'term', 'user'
	public $object_type = 'post';

	// Query result (WP_Posts | WP_Term_Query | WP_User_Query | Other)
	public $query_result;

	// Fake query result (@since 1.12.2)
	public $fake_result;

	// Query results total
	public $count = 0;

	// Query results total pages
	public $max_num_pages = 1;

	// Is looping
	public $is_looping = false;

	// When looping, keep the iteration index
	public $loop_index = 0;

	/**
	 * Initial index for this render cycle, which can be non-zero after offsets, pagination, or filters.
	 *
	 * @since 2.3.11
	 */
	public $initial_loop_index = null;

	/**
	 * Include simulated builder parents in first-node validation, even when the parent has no results.
	 *
	 * @since 2.3.11
	 */
	public $is_simulated_loop_context = false;

	// When looping, keep the object
	public $loop_object = null;

	// Store the original post before looping to restore the context (nested loops)
	private $original_post_id = 0;

	// Cache key
	private $cache_key = false;

	/**
	 * WordPress query currently being prepared for builder AJAX preview.
	 *
	 * The reference keeps the global pre_get_posts callback scoped to this query if another
	 * callback starts a nested WP_Query while the builder query is being prepared.
	 *
	 * @var \WP_Query|null
	 *
	 * @since 2.3.13
	 */
	private $builder_preview_query = null;

	// Store query history (including those destroyed)
	public static $query_history = [];

	/**
	 * Host page identity captured before populated previews temporarily replace the main query.
	 *
	 * @var array|null
	 * @since 2.4.5
	 */
	public static $frontend_query_context = null;

	// Store the start position of the query (@since 1.12.2)
	public $start = 0;

	// Store the end position of the query (@since 1.12.2)
	public $end = 0;

	/**
	 * Class constructor
	 *
	 * @param array $element
	 */
	public function __construct( $element = [] ) {
		$this->register_query();

		$this->element_id   = $element['id'] ?? '';
		$this->element_name = $element['name'] ?? '';
		$this->component_id = $element['cid'] ?? '';
		$this->instance_id  = $element['instanceId'] ?? '';

		/**
		 * Adjust the element ID to include the instance ID if available. Avoid incorrect history ID generation. (@since 2.0)
		 *
		 * Direct slot component instances keep their local ID because their slotChildren map is
		 * stored on that local instance, not on the outer component instance.
		 * (#86c82k2b3; @since 2.3.8)
		 */
		$is_direct_slot_component = ! empty( $element['slotInstanceId'] );

		if (
			! empty( $element['instanceId'] ) &&
			! empty( $element['parentComponent'] ) &&
			! $is_direct_slot_component &&
			strpos( $element['id'], '-' ) === false
		) {
			$this->element_id .= '-' . $element['instanceId'];
		}

		// Check for stored query in query history
		$query_instance = self::get_query_by_element_id( $this->element_id );

		if ( $query_instance ) {
			// Assign the history query instance properties to this instance, avoid running the query again
			foreach ( $query_instance as $key => $value ) {
				if ( $key === 'id' ) {
					continue;
				}
				$this->$key = $value;
			}

			// First-node tracking state must not be inherited from query history (#86caprbc5)
			$this->initial_loop_index        = null;
			$this->is_simulated_loop_context = false;
		} else {

			// STEP: Get global query settings (@since 2.1)
			if ( isset( $element['settings']['query'] ) ) {
				$element['settings']['query'] = Helpers::maybe_get_global_query_settings( $element['settings']['query'] ?? [] );
			}

			$this->object_type = ! empty( $element['settings']['query']['objectType'] ) ? $element['settings']['query']['objectType'] : 'post';

			// Remove object type from query vars to avoid future conflicts
			unset( $element['settings']['query']['objectType'] );

			$this->settings = ! empty( $element['settings'] ) ? $element['settings'] : [];

			// STEP: Set the query vars from the element settings
			$this->query_vars = self::prepare_query_vars_from_settings( $this->settings, $this->element_id, $this->element_name );

			// STEP: Perform the query, set the query result, count and max_num_pages
			$this->run();

			// (@since 1.12.2)
			$this->handle_no_results();

			/**
			 * Filter: Force query run (to skip add_to_history() method below)
			 *
			 * AJAX filter plugins, etc. might want to use this.
			 *
			 * @since 1.9.2: Set $query_vars['bricks_force_run'] = true to force run query rerun (i.e. inside Query Editor or custom code snippet)
			 *
			 * @see https://academy.bricksbuilder.io/article/filter-bricks-query-force_run/
			 *
			 * @since 1.9.1.1
			 */
			$force_run = apply_filters( 'bricks/query/force_run', false, $this ) || ( isset( $this->query_vars['bricks_force_run'] ) && $this->query_vars['bricks_force_run'] );

			/**
			 * STEP: Add query instance to query history (Query::$query_history) to access & reuse query instance later
			 *
			 * Only for WP core query types (post, term, user) as other potentially nested query types (e.g. ACF, Meta Box, Woo cart content, etc.) don't have a unique ID.
			 *
			 * @since 1.9.1
			 */
			if ( in_array( $this->object_type, [ 'post', 'term', 'user', 'api' ] ) && ! $force_run ) {
				$this->add_to_history();
			}
		}
	}

	/**
	 * Get query instance by element ID from the query history
	 *
	 * @since 1.9.1
	 */
	public static function get_query_by_element_id( $element_id = '', $is_dynamic_data = false ) {
		if ( empty( $element_id ) ) {
			return false;
		}

		$query           = false;
		$history_queries = self::$query_history;

		// Check if any query history element_id matches the given element_id
		if ( ! empty( $history_queries ) ) {
			$query_history_id = self::generate_query_history_id( $element_id );

			if ( isset( $history_queries[ $query_history_id ] ) ) {
				$query = $history_queries[ $query_history_id ];
			}

			// If using in dynamic data, and no query history found, maybe user wants to get query history based on $element_id
			if ( ! $query && $is_dynamic_data && self::is_looping() ) {
				if ( isset( $history_queries[ $element_id ] ) ) {
					$query = $history_queries[ $element_id ];
				}
			}
		}

		return $query;
	}

	/**
	 * Add current query instance to query history
	 *
	 * @since 1.9.1
	 */
	public function add_to_history() {
		$identifier = self::generate_query_history_id( $this->element_id );

		if ( $identifier ) {
			self::$query_history[ $identifier ] = $this;
		}
	}

	/**
	 * Generate a unique identifier for the query history
	 *
	 * Use the element ID and every active ancestor loop context.
	 *
	 * @since 1.9.1
	 */
	public static function generate_query_history_id( $element_id ) {
		global $bricks_loop_query;

		$ancestors = [];

		foreach ( (array) $bricks_loop_query as $query_id => $query ) {
			// A lookup inside the target loop must use the same context as its constructor.
			if ( $query->element_id === $element_id ) {
				break;
			}

			if ( $query->is_looping ) {
				$ancestors[] = $query_id;
			}
		}

		$unique_id = [];

		// Keep the existing single-parent key format, adding the remaining ancestor contexts.
		foreach ( array_reverse( $ancestors ) as $query_id ) {
			$loop_id = self::get_loop_object_id( $query_id );

			if ( $loop_id === null || $loop_id === false || $loop_id === '' ) {
				return;
			}

			$unique_id[] = self::get_query_element_id( $query_id );

			if ( count( $unique_id ) === 1 ) {
				$unique_id[] = $element_id;
			}

			$unique_id[] = self::get_query_object_type( $query_id );
			$unique_id[] = $loop_id;
		}

		if ( ! $ancestors ) {
			$unique_id[] = $element_id;
		}

		return implode( '_', $unique_id );
	}

	/**
	 * Add query to global store
	 */
	public function register_query() {
		global $bricks_loop_query;
		$this->id = Helpers::generate_random_id( false );

		if ( ! is_array( $bricks_loop_query ) ) {
			$bricks_loop_query = [];
		}

		$bricks_loop_query[ $this->id ] = $this;
	}

	/**
	 * Calling unset( $query ) does not destroy query quickly enough
	 *
	 * Have to call the 'destroy' method explicitly before unset.
	 */
	public function __destruct() {
		$this->destroy();
	}

	/**
	 * Use the destroy method to remove the query from the global store
	 *
	 * @return void
	 */
	public function destroy() {
		global $bricks_loop_query;

		unset( $bricks_loop_query[ $this->id ] );
	}

	/**
	 * Get the query cache
	 *
	 * @since 1.5
	 *
	 * @return mixed
	 */
	public function get_query_cache() {
		if ( ! isset( Database::$global_settings['cacheQueryLoops'] ) || ! bricks_is_frontend() || bricks_is_builder_call() ) {
			return false;
		}

		// Check: Nesting query?
		$parent_query_id  = self::is_any_looping();
		$parent_object_id = $parent_query_id ? self::get_loop_object_id( $parent_query_id ) : 0;

		// Include in the cache key a representation of the query vars to break cache for certain scenarios like pagination or search keywords
		$query_vars = wp_json_encode( $this->query_vars );

		// Get & set query loop cache (@since 1.5)
		$cache_key = md5( "brx_query_{$this->element_id}_{$query_vars}_{$parent_object_id}" );

		// Allow cache key modification for multilanguage (@since 2.3.2)
		$this->cache_key = apply_filters( 'bricks/query/cache_key', $cache_key, $this );

		return wp_cache_get( $this->cache_key, 'bricks' );
	}

	/**
	 * Set the query cache
	 *
	 * @since 1.5
	 *
	 * @return void
	 */
	public function set_query_cache( $object ) {
		if ( ! $this->cache_key ) {
			return;
		}

		wp_cache_set( $this->cache_key, $object, 'bricks', MINUTE_IN_SECONDS );
	}

	/**
	 * Prepare query_vars for the Query before running it
	 * Remove unwanted keys, set defaults, populate correct query vars, etc.
	 * Static method to be used by other classes. (Bricks\Database)
	 *
	 * @since 1.8
	 */
	public static function prepare_query_vars_from_settings( $settings = [], $fallback_element_id = '', $element_name = '', $skip_main_query = false ) {
		$object_type = self::get_query_object_type();
		$element_id  = self::get_query_element_id();

		/**
		 * $object_type and $element_id are empty when this method is called in pre_get_post (main query)
		 * Reason: We just call prepare_query_vars_from_settings() without initializing the Query class
		 * Impact: Some query_vars will be missing because not going through the switch statement and Bricks PHP filters not fired
		 *
		 * @since 1.9.1
		 */
		if ( empty( $object_type ) ) {
			$object_type = $settings['query']['objectType'] ?? 'post';
		}

		if ( empty( $element_id ) && ! empty( $fallback_element_id ) ) {
			$element_id = $fallback_element_id;
		}

		// For Query filters to amend the setting. Unable to use bricks/element/settings filter before render. Undocumented (@since 1.12.2)
		$settings   = apply_filters( 'bricks/query/prepare_query_vars_from_settings', $settings, $element_id );
		$query_vars = $settings['query'] ?? [];

		// Some elements already built the query vars. (carousel, related-posts)
		if ( isset( $query_vars['bricks_skip_query_vars'] ) ) {
			return $query_vars;
		}

		// Unset infinite scroll
		if ( isset( $query_vars['infinite_scroll'] ) ) {
			unset( $query_vars['infinite_scroll'] );
		}

		// Unset isLiveSearch
		if ( isset( $query_vars['is_live_search'] ) ) {
			unset( $query_vars['is_live_search'] );
		}

		// Do not use meta_key if orderby is not set to meta_value or meta_value_num
		if ( isset( $query_vars['meta_key'] ) ) {
			$orderby = isset( $query_vars['orderby'] ) ? $query_vars['orderby'] : '';

			// orderby might be an array (@since 1.11.1)
			$valid_orderby_values = [ 'meta_value', 'meta_value_num' ];

			if (
				( is_string( $orderby ) && ! in_array( $orderby, $valid_orderby_values ) ) ||
				( is_array( $orderby ) && ! array_intersect( $orderby, $valid_orderby_values ) )
			) {
				unset( $query_vars['meta_key'] );
			}
		}

		/**
		 * Use PHP editor
		 *
		 * Returns PHP array with query arguments
		 *
		 * Supported if 'objectType' is 'post', 'term' or 'user'.
		 * No merge query.
		 *
		 * @since 1.9.1
		 */
		if ( isset( $query_vars['useQueryEditor'] ) && ! empty( $query_vars['queryEditor'] ) && in_array( $object_type, [ 'post','term','user' ] ) ) {
			// Return: Code execution not enabled (Bricks setting or filter)
			if ( ! Helpers::code_execution_enabled() ) {
				return [];
			}

			$post_id = Database::$page_data['preview_or_post_id'];

			// Sanitize element code (queryEditor)
			$signature                    = $query_vars['signature'] ?? false;
			$php_query_raw                = $query_vars['queryEditor'];
			$php_query_raw                = Helpers::sanitize_element_php_code( $post_id, $element_id, $php_query_raw, $signature );
			$php_query_raw                = is_string( $php_query_raw ) && ! isset( $php_query_raw['error'] ) ? bricks_render_dynamic_data( $php_query_raw, $post_id ) : '';
			$query_vars['posts_per_page'] = get_option( 'posts_per_page' );

			// Define an anonymous function that simulates the scope for user code
			$execute_user_code = function () use ( $php_query_raw ) {
				// Initialize a variable to capture the result of user code
				$user_result = null;

				// Capture user code output using output buffering
				ob_start();

				// Execute the user code
				$user_result = eval( $php_query_raw );

				// Get the captured output
				ob_get_clean();

				// Return the user code result
				return $user_result;
			};

			ob_start();

			// Prepare & set error reporting
			$error_reporting = error_reporting( E_ALL );
			$display_errors  = ini_get( 'display_errors' );
			ini_set( 'display_errors', 1 );

			try {
				$php_query = $execute_user_code();
			} catch ( \Exception $error ) {
				echo 'Exception: ' . $error->getMessage();
				return;
			} catch ( \ParseError $error ) {
				echo 'ParseError: ' . $error->getMessage();
				return;
			} catch ( \Error $error ) {
				echo 'Error: ' . $error->getMessage();
				return;
			}

			// Reset error reporting
			ini_set( 'display_errors', $display_errors );
			error_reporting( $error_reporting );

			// @see https://www.php.net/manual/en/function.eval.php
			if ( version_compare( PHP_VERSION, '7', '<' ) && $php_query === false || ! empty( $error ) ) {
				// $php_query = $error;
				ob_end_clean();
			} else {
				ob_get_clean();
			}

			$object_type = empty( $object_type ) ? 'post' : $object_type;

			if ( ! empty( $php_query ) && is_array( $php_query ) ) {
				$query_vars          = array_merge( $query_vars, $php_query );
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );

				if ( $object_type === 'term' ) {
					// Handle term pagination (#86bwwav1e)
					$query_vars = self::get_term_pagination_query_var( $query_vars );
				}

				if ( $object_type === 'user' ) {
					$query_vars = self::get_user_pagination_query_var( $query_vars );
				}
			}

			/**
			 * php Editor not triggering query_vars, new query filters unable to merge query_vars
			 *
			 * @since 1.11.1: Add $element_name parameter to the filter
			 * @since 1.9.6
			 */
			$query_vars = apply_filters( "bricks/{$object_type}s/query_vars", $query_vars, $settings, $element_id, $element_name );

			// @since 2.0
			if ( $object_type === 'post' ) {
				$query_vars = self::post_in_correction( $query_vars );
				$query_vars = self::author_in_correction( $query_vars ); // @since 2.4
			}

			return $query_vars;
		}

		/**
		 * arrayEditor
		 *
		 * @since 2.2
		 */
		if ( isset( $query_vars['arrayEditor'] ) && ! empty( $query_vars['arrayEditor'] ) && $object_type === 'array' ) {

			// Pagination support
			if ( isset( $query_vars['pagination_enabled'] ) && $query_vars['pagination_enabled'] ) {
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );
				$query_vars          = self::get_array_pagination_query_var( $query_vars );
			}
			return $query_vars;
		}

		// Meta Query vars
		$query_vars = self::parse_meta_query_vars( $query_vars );

		// Orderby & Order (@since 1.11.1)
		$query_vars = self::parse_orderby_vars( $query_vars, $object_type );

		// Set different query vars depending on the object type
		switch ( $object_type ) {
			case 'post':
				// Attachments
				$query_attachments      = false;
				$query_only_attachments = false;

				// post_type can be 'string' or 'array'
				$post_type = ! empty( $query_vars['post_type'] ) ? $query_vars['post_type'] : false;

				if ( $post_type ) {
					if ( is_array( $post_type ) ) {
						$query_attachments = in_array( 'attachment', $post_type );

						if ( $query_attachments && count( $post_type ) === 1 ) {
							$query_only_attachments = true;
						}
					} else {
						$query_attachments      = $post_type === 'attachment';
						$query_only_attachments = $post_type === 'attachment';
					}
				}

				$query_vars['post_status'] = 'publish';

				/**
				 * Post type 'attachment' included: Add post status 'inherit'
				 *
				 * @see: https://developer.wordpress.org/reference/classes/wp_query/#post-type-parameters
				 */
				if ( $query_attachments ) {
					$query_vars['post_status'] = [ 'inherit', 'publish' ];
				}

				// Query ONLY attachments: Set 'post_mime_type' query var
				if ( $query_only_attachments ) {
					$mime_types = isset( $query_vars['post_mime_type'] ) ? bricks_render_dynamic_data( $query_vars['post_mime_type'] ) : 'image';

					$mime_types = explode( ',', $mime_types );

					$query_vars['post_mime_type'] = $mime_types;
				}

				// Page & Pagination
				// @since 1.7.1 - Standardize use the get_paged_query_var() function to get the paged value
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );

				// Value must be -1 or > 1 (0 is not allowed)
				$query_vars['posts_per_page'] = isset( $query_vars['posts_per_page'] ) && is_numeric( $query_vars['posts_per_page'] ) ? intval( $query_vars['posts_per_page'] ) : get_option( 'posts_per_page' );

				// Exclude current post
				if ( isset( $query_vars['exclude_current_post'] ) ) {
					// @since 1.8 - Capture exclude_current_post value inside builder call
					if ( is_single() || is_page() || bricks_is_builder_call() ) {
						// Current post not working with populate content in builder mode (@since 1.9.5)
						$post_id                      = ! self::is_any_looping() && isset( Database::$page_data['preview_or_post_id'] ) ? Database::$page_data['preview_or_post_id'] : get_the_ID();
						$query_vars['post__not_in'][] = $post_id;
					}

					unset( $query_vars['exclude_current_post'] );
				}

				if ( isset( $query_vars['post_parent'] ) ) {
					$post_parent = bricks_render_dynamic_data( $query_vars['post_parent'] );

					if ( strpos( $post_parent, ',' ) !== false ) {
						$post_parent = explode( ',', $post_parent );

						$query_vars['post_parent__in'] = (array) $post_parent;

						unset( $query_vars['post_parent'] );
					} else {
						$query_vars['post_parent'] = (int) $post_parent;
					}
				}

				// Performance boost (@since 2.1)
				if ( isset( $query_vars['disable_update_post_meta_cache'] ) ) {
					$query_vars['update_post_meta_cache'] = false;
					unset( $query_vars['disable_update_post_meta_cache'] );
				}

				// Performance boost (@since 2.1)
				if ( isset( $query_vars['disable_update_post_term_cache'] ) ) {
					$query_vars['update_post_term_cache'] = false;
					unset( $query_vars['disable_update_post_term_cache'] );
				}

				// Parse include/exclude dynamic data for post/author IDs (@since 2.4)
				$query_vars = self::set_include_exclude_in_vars( $query_vars );

				// Tax query
				$query_vars = self::set_tax_query_vars( $query_vars );

				// @see: https://academy.bricksbuilder.io/article/filter-bricks-posts-merge_query/
				$merge_query = apply_filters( 'bricks/posts/merge_query', true, $element_id );

				/**
				 * Merge wp_query vars and posts element query vars
				 *
				 * @since 1.7: Merge query only if 'disable_query_merge' control is not set!
				 * @since 1.9.9: Merge query only if 'woo_disable_query_merge' control is not set! (Products element)
				 * @since 2.0: Do not merge if skip_main_query is true (#86c42z22c; #86c3zyd4z; @see database.php)
				 */
				if ( $merge_query &&
					( is_archive() || is_author() || is_search() || is_home() ) &&
					empty( $query_vars['disable_query_merge'] ) &&
					empty( $query_vars['woo_disable_query_merge'] ) &&
					! $skip_main_query
				) {
					global $wp_query;

					$query_vars = wp_parse_args( $query_vars, $wp_query->query );
				}

				// @see: https://academy.bricksbuilder.io/article/filter-bricks-posts-query_vars/
				// @since 1.11.1: Added $element_name
				$query_vars = apply_filters( 'bricks/posts/query_vars', $query_vars, $settings, $element_id, $element_name );

				/**
				 * Set default post type to 'post' if:
				 * - post_type is not set
				 * - brx_is_search is set (Only availabe after bricks/posts/query_vars hook)
				 * - is_archive_main_query is not set (otherwise, will get unexpected result on search page)
				 *
				 * @since 1.12.2 (#86c0zaxrv)
				 */
				$is_bricks_search      = isset( $query_vars['brx_is_search'] ) ? true : false;
				$is_archive_main_query = isset( $query_vars['is_archive_main_query'] ) ? true : false;

				if ( ! $post_type && $is_bricks_search && ! $is_archive_main_query ) {
					$query_vars['post_type'] = 'post';
				}

				// (@since 2.0)
				$query_vars = self::post_in_correction( $query_vars );
				$query_vars = self::author_in_correction( $query_vars ); // @since 2.4
				break;

			case 'term':
				// Number. Default is "0" (all) but as a safety procedure we limit the number
				// Sanitize number to ensure it's an integer (#86c74nf1g; @since 2.2)
				$query_vars['number'] = isset( $query_vars['number'] ) && is_numeric( $query_vars['number'] ) ? intval( $query_vars['number'] ) : get_option( 'posts_per_page' );

				// Paged - set the paged key to the correct value (#86bwqwa31)
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );

				// Handle term pagination (#86bwwav1e)
				$query_vars = self::get_term_pagination_query_var( $query_vars );

				// Hide empty
				if ( isset( $query_vars['show_empty'] ) ) {
					$query_vars['hide_empty'] = $query_vars['show_empty'] === false;

					unset( $query_vars['show_empty'] );
				}

				// Current Post Term - (@since 1.8.4)
				if ( isset( $query_vars['current_post_term'] ) ) {
					// Current post term not working with populate content in builder mode (@since 1.9.5)
					$post_id                  = ! self::is_any_looping() && isset( Database::$page_data['preview_or_post_id'] ) ? Database::$page_data['preview_or_post_id'] : get_the_ID();
					$query_vars['object_ids'] = $post_id;

					unset( $query_vars['current_post_term'] );
				}

				if ( isset( $query_vars['child_of'] ) ) {
					$query_vars['child_of'] = bricks_render_dynamic_data( $query_vars['child_of'] );
				}

				if ( isset( $query_vars['parent'] ) ) {
					$query_vars['parent'] = bricks_render_dynamic_data( $query_vars['parent'] );
				}

				// Include & Exclude terms
				if ( isset( $query_vars['tax_query'] ) ) {
					$query_vars['include'] = self::convert_terms_to_ids( $query_vars['tax_query'] );

					unset( $query_vars['tax_query'] );
				}

				if ( isset( $query_vars['tax_query_not'] ) ) {
					$query_vars['exclude'] = self::convert_terms_to_ids( $query_vars['tax_query_not'] );

					unset( $query_vars['tax_query_not'] );
				}

				// @see: https://academy.bricksbuilder.io/article/filter-bricks-terms-query_vars/
				$query_vars = apply_filters( 'bricks/terms/query_vars', $query_vars, $settings, $element_id, $element_name );
				break;

			case 'user':
				// Unset post_type
				if ( isset( $query_vars['post_type'] ) ) {
					unset( $query_vars['post_type'] );
				}

				// Current Post Author - (@since 1.9.1)
				if ( isset( $query_vars['current_post_author'] ) ) {
					$current_post = get_post(); // Get the current post object
					// Check if the current post has an author
					if ( is_a( $current_post, 'WP_Post' ) && ! empty( $current_post->post_author ) ) {
						$query_vars['include'] = $current_post->post_author;
					}

					unset( $query_vars['current_post_author'] );
				}

				// Sanitize number to ensure it's an integer, use posts_per_page to match with the placeholder logic and get_user_pagination_query_var (#86c74nf1g; @since 2.2)
				$query_vars['number'] = isset( $query_vars['number'] ) && is_numeric( $query_vars['number'] ) ? $query_vars['number'] : get_option( 'posts_per_page' );

				// Paged
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );

				// Handle user pagination (@since 1.12)
				$query_vars = self::get_user_pagination_query_var( $query_vars );

				// @see: https://academy.bricksbuilder.io/article/filter-bricks-users-query_vars/
				$query_vars = apply_filters( 'bricks/users/query_vars', $query_vars, $settings, $element_id, $element_name );
				break;

			// @since 2.1
			case 'api':
				// Unset everything except paged for security purposes
				foreach ( $query_vars as $key => $value ) {
					if ( $key === 'paged' ) {
						continue;
					}
					unset( $query_vars[ $key ] );
				}

				// Only set the paged query var
				$query_vars['paged'] = self::get_paged_query_var( $query_vars );
				break;
		}

		return $query_vars;
	}

	/**
	 * Perform the query (maybe cache)
	 *
	 * Set $this->query_result, $this->count, $this->max_num_pages
	 *
	 * @return void (@since 1.8)
	 */
	public function run() {
		$count         = $this->count;
		$start         = $this->start;
		$end           = $this->end;
		$max_num_pages = $this->max_num_pages;
		$query_vars    = $this->query_vars;

		/**
		 * NOTE: Query for live_search should not run on page load
		 *
		 * However, this will cause many issues.
		 * - Elements not showing on the initial page load and their JS will not be enqueue. Subsequent AJAX search unable to initialize the JS
		 * - Templates are not populated with content on initial page load, especially popup templates. Subsequent AJAX search unable trigger the popup
		 *
		 * Current solution: Run the query on initial page load, remove them in render() method if live_search is enabled
		 *
		 * @since 1.9.6
		 */
		switch ( $this->object_type ) {
			case 'post':
				$result = $this->run_wp_query();

				// STEP: Populate the total count
				$count = empty( $query_vars['no_found_rows'] ) ? $result->found_posts : ( is_array( $result->posts ) ? count( $result->posts ) : 0 );

				$max_num_pages = empty( $query_vars['posts_per_page'] ) ? 1 : ceil( $count / $query_vars['posts_per_page'] );

				// STEP: Calculate the starting and ending position (@since 1.12.2)
				if ( $count > 0 ) {
					$page     = (int) ( $query_vars['paged'] ?? 1 );
					$per_page = (int) ( $query_vars['posts_per_page'] ?? get_option( 'posts_per_page' ) );

					// Maybe user set -1 to posts_per_page
					if ( $per_page === -1 ) {
						$start = 1;
						$end   = $count;
					} else {
						// Calculate the starting position
						if ( $page === 1 ) {
							// First page starts at 1
							$start = 1;
						} else {
							// For subsequent pages, calculate start relative to paged results
							$start = ( ( $page - 1 ) * $per_page ) + 1;
						}

						// Calculate the ending position
						$end = min( $start + $per_page - 1, $count );
					}
				}

				break;

			case 'term':
				$term_result = $this->run_wp_term_query();
				$result      = $term_result['terms'];
				$count       = $term_result['total'];

				// STEP: Get the original offset value (@since 1.9.1)
				$original_offset = ! empty( $query_vars['original_offset'] ) ? $query_vars['original_offset'] : 0;

				// STEP: Populate the total count
				if ( ! empty( $query_vars['number'] ) ) {
					// Subtract the $original_offset to fix pagination (@since 1.9.1)
					$count = $count > 0 ? $count - $original_offset : 0;
				}

				// STEP : Populate the max number of pages
				$max_num_pages = empty( $query_vars['number'] ) || count( $result ) < 1 ? 1 : ceil( $count / $query_vars['number'] );

				// STEP: Calculate the starting and ending position (@since 1.12.2)
				if ( $count > 0 ) {
					$page     = (int) ( $query_vars['paged'] ?? 1 );
					$per_page = (int) ( $query_vars['number'] ?? get_option( 'posts_per_page' ) );

					// Maybe user set 0 to number
					if ( $per_page === 0 ) {
						$start = 1;
						$end   = $count;
					} else {
						// Calculate the starting position
						if ( $page === 1 ) {
							// First page starts at 1
							$start = 1;
						} else {
							// For subsequent pages, calculate start relative to paged results
							$start = ( ( $page - 1 ) * $per_page ) + 1;
						}

						// Calculate the ending position
						$end = min( $start + $per_page - 1, $count );
					}
				}
				break;

			case 'user':
				$users_query = $this->run_wp_user_query();

				// STEP: The query result
				$result = $users_query->get_results();

				// STEP: Populate the total count of the users in this query
				$count = $users_query->get_total();

				// STEP: Get the original offset value (@since 1.9.1)
				$original_offset = ! empty( $query_vars['original_offset'] ) ? $query_vars['original_offset'] : 0;

				// STEP: Subtract the $original_offset to fix pagination (@since 1.9.1)
				$count = $count > 0 ? $count - $original_offset : 0;

				// STEP : Populate the max number of pages
				$max_num_pages = empty( $query_vars['number'] ) || count( $result ) < 1 ? 1 : ceil( $count / $query_vars['number'] );

				// STEP: Calculate the starting and ending position (@since 1.12.2)
				if ( $count > 0 ) {
					$page     = (int) ( $query_vars['paged'] ?? 1 );
					$per_page = (int) ( $query_vars['number'] ?? get_option( 'posts_per_page' ) );

					// Maybe user set 0 to number
					if ( $per_page === -1 ) {
						$start = 1;
						$end   = $count;
					} else {
						// Calculate the starting position
						if ( $page === 1 ) {
							// First page starts at 1
							$start = 1;
						} else {
							// For subsequent pages, calculate start relative to paged results
							$start = ( ( $page - 1 ) * $per_page ) + 1;
						}

						// Calculate the ending position
						$end = min( $start + $per_page - 1, $count );
					}

				}
				break;

			// Query API (@since 2.1)
			case 'api':
				$count         = 0;
				$max_num_pages = 1; // Default to 1 page for API queries

				// Run the API query
				$data = $this->run_query_api_query();

				$result = $data['results'] ?? [];

				// If the result is an array, count the number of items
				if ( ! empty( $result ) && is_array( $result ) ) {
					$count = count( $result );

					$max_num_pages = ! empty( $data['total_pages'] ) ? $data['total_pages'] : 1;
				}

				break;

			// Query Array (@since 2.2)
			case 'array':
				$array_result  = $this->run_array_query();
				$result        = $array_result['results'] ?? [];
				$count         = $array_result['total_items'] ?? 0;
				$start         = $array_result['start'] ?? 0;
				$end           = $array_result['end'] ?? 0;
				$max_num_pages = $array_result['total_pages'] ?? 1;
				break;

			default:
				// Allow other query providers to return a query result (Woo Cart, ACF, Metabox...)
				$result = apply_filters( 'bricks/query/run', [], $this );

				$count = ! empty( $result ) && is_array( $result ) ? count( $result ) : 0;

				// STEP: Apply array_conditions if applicable (@since 2.2)
				$array_condition_supported_tags = \Bricks\Integrations\Dynamic_Data\Providers::get_array_supported_tags_list();
				// Just get the objectType key from the supported tags list
				$supported_object_types = array_map(
					function( $item ) {
						return $item['objectType'];
					},
					$array_condition_supported_tags
				);

				// Ensure the $supported_object_types is an array
				$supported_object_types = is_array( $supported_object_types ) ? $supported_object_types : [];

				if (
					in_array( $this->object_type, $supported_object_types, true ) &&
					isset( $this->query_vars['array_conditions'] ) &&
					is_array( $this->query_vars['array_conditions'] ) && ! empty( $this->query_vars['array_conditions'] )
				) {
					$filtered_result = Query_Array::apply_conditions( $result, $this->query_vars['array_conditions'], Database::$page_data['preview_or_post_id'], $this );
					$result          = $filtered_result;
					$count           = is_array( $result ) ? count( $result ) : 0;

					// Avoid showing in frontend
					unset( $this->query_vars['array_conditions'] );
				}
				break;
		}

		/**
		 * Set the query result, count and max_num_pages in a centralized way
		 * Previously this was done in run_wp_query(), run_wp_term_query() and run_wp_user_query()
		 * Filters provided
		 *
		 * @see https://academy.bricksbuilder.io/article/filter-bricks-query-result/
		 * @see https://academy.bricksbuilder.io/article/filter-bricks-query-result_count/
		 * @see https://academy.bricksbuilder.io/article/filter-bricks-query-result_max_num_pages/ (@since 1.9.1)
		 *
		 * @since 1.8
		 */
		$this->query_result = apply_filters( 'bricks/query/result', $result, $this );
		$this->count        = apply_filters( 'bricks/query/result_count', $count, $this );

		// Pagination element relies on this value (@since 1.9.1)
		$this->max_num_pages = apply_filters( 'bricks/query/result_max_num_pages', $max_num_pages, $this );

		// Set the starting and ending position (@since 1.12.2)
		$this->start = apply_filters( 'bricks/query/result_start', $start, $this );
		$this->end   = apply_filters( 'bricks/query/result_end', $end, $this );
	}

	/**
	 * Handle no results situation for post, user and term queries
	 * Need to run another query to continue execute the remaining elements inside the query loop.
	 * - To ensure necessary element's scripts and styles are enqueued on page load
	 * - To ensure necessary AJAX popups are generated and output on page load
	 *
	 * @since 1.12.2
	 */
	public function handle_no_results() {
		// Skip if not an actual page load
		if (
			( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return;
		}

		// Skip if there are results
		if ( $this->count > 0 ) {
			return;
		}

		$handle_children = Helpers::handle_no_results_children_elements();
		$fake_result     = [];

		switch ( $this->object_type ) {
			case 'post':
				if ( $handle_children ) {
					$original_query_vars_copy = $this->query_vars;

					// Build new query vars
					$query_vars = Query_Filters::$query_vars_before_merge[ $this->element_id ] ?? $this->query_vars;

					// Ensure 1 row only
					$query_vars['posts_per_page'] = 1;
					$query_vars['paged']          = 1;
					$query_vars['offset']         = 0;
					$query_vars['brx_fake_query'] = true; // Flag to identify fake query

					// Remove all tax_query and meta_query
					unset( $query_vars['tax_query'] );
					unset( $query_vars['meta_query'] );

					// Set the new query vars
					$this->query_vars = $query_vars;

					// Run the query
					$fake_result = $this->run_wp_query();

					// Restore the original query vars
					$this->query_vars = $original_query_vars_copy;
				}

				break;

			case 'term':
				if ( $handle_children ) {
					$original_query_vars_copy = $this->query_vars;

					// Build new query vars
					$query_vars = Query_Filters::$query_vars_before_merge[ $this->element_id ] ?? $this->query_vars;

					// Ensure 1 row only
					$query_vars['number']          = 1;
					$query_vars['offset']          = 0;
					$query_vars['paged']           = 1;
					$query_vars['original_offset'] = 0;
					$query_vars['brx_fake_query']  = true;

					// Remove all tax_query and meta_query
					unset( $query_vars['tax_query'] );
					unset( $query_vars['meta_query'] );

					$this->query_vars = $query_vars;

					// Run the query
					$term_result = $this->run_wp_term_query();

					$fake_result = $term_result['terms'];

					// Restore the original query vars
					$this->query_vars = $original_query_vars_copy;
				}

				break;
			case 'user':
				// If Query Filters is enabled
				if ( $handle_children ) {
					$original_query_vars_copy = $this->query_vars;

					// Build new query vars
					$query_vars = Query_Filters::$query_vars_before_merge[ $this->element_id ] ?? $this->query_vars;

					// Ensure 1 row only
					$query_vars['number']          = 1;
					$query_vars['offset']          = 0;
					$query_vars['paged']           = 1;
					$query_vars['original_offset'] = 0;
					$query_vars['brx_fake_query']  = true; // Flag to identify fake query

					// Remove all tax_query and meta_query
					unset( $query_vars['tax_query'] );
					unset( $query_vars['meta_query'] );

					$this->query_vars = $query_vars;

					// Run the query
					$user_query = $this->run_wp_user_query();

					$fake_result = $user_query->get_results();

					// Restore the original query vars
					$this->query_vars = $original_query_vars_copy;
				}
				break;

			default:
				// We don't handle this currently has query filters only support post, term and user queries
				$fake_result = apply_filters( 'bricks/query/run_fake', [], $this );
				break;
		}

		$this->fake_result = apply_filters( 'bricks/query/fake_result', $fake_result, $this );
	}

	/**
	 * Run WP_Term_Query
	 *
	 * @see https://developer.wordpress.org/reference/classes/wp_term_query/
	 *
	 * @return array Terms (WP_Term)
	 */
	public function run_wp_term_query() {
		// Cache?
		$result = $this->get_query_cache();

		if ( $result === false ) {
			/**
			 * As long as weighted_relevance is set, and no query filter sort flag is set, we change orderby to include and remove order
			 *
			 *  @since 2.2
			 */
			if (
				! isset( $this->query_vars['brx_sort_applied'] ) &&
				isset( $this->query_vars['orderby'] ) &&
				isset( $this->query_vars['brx_orderby'] ) && $this->query_vars['brx_orderby'] === 'weighted_relevance' && ! empty( $this->query_vars['include'] ) ) {
				$this->query_vars['orderby'] = 'include';
				unset( $this->query_vars['order'] );
			}

			$terms_query = new \WP_Term_Query( $this->query_vars );
			$total       = count( $terms_query->get_terms() ); // Default total count

			// Avoid PHP error if user use PHP Query Editor and return non-array value (#86c5yymz3)
			if ( is_array( $this->query_vars ) ) {
				// Run another query to get the total count, set number to 0 to avoid limit
				$total_terms_query = new \WP_Term_Query( array_merge( $this->query_vars, [ 'number' => 0 ] ) );
				$total             = count( $total_terms_query->get_terms() );
			}

			$result = [
				'terms' => $terms_query->get_terms(),
				'total' => $total,
			];

			$this->set_query_cache( $result );
		}

		return $result;
	}

	/**
	 * Run WP_User_Query
	 *
	 * @see https://developer.wordpress.org/reference/classes/wp_user_query/
	 *
	 * @return WP_User_Query (@since 1.8)
	 */
	public function run_wp_user_query() {
		// Cache?
		$users_query = $this->get_query_cache();

		if ( $users_query === false ) {
			/**
			 * As long as weighted_relevance is set, and no query filter sort flag is set, we change orderby to include and remove order
			 *
			 *  @since 2.2
			 */
			if (
				! isset( $this->query_vars['brx_sort_applied'] ) &&
				isset( $this->query_vars['orderby'] ) &&
				isset( $this->query_vars['brx_orderby'] ) && $this->query_vars['brx_orderby'] === 'weighted_relevance' && ! empty( $this->query_vars['include'] ) ) {
				$this->query_vars['orderby'] = 'include';
				unset( $this->query_vars['order'] );
			}

			$users_query = self::get_wp_user_query( $this->query_vars );

			$this->set_query_cache( $users_query );
		}

		return $users_query;
	}

	/**
	 * Run a user query with the same distinct metadata results for loops and filter populations.
	 *
	 * @param array $query_vars WordPress user query variables.
	 * @return \WP_User_Query
	 * @since 2.4.4
	 */
	public static function get_wp_user_query( $query_vars ) {
		if ( empty( $query_vars['meta_query'] ) ) {
			return new \WP_User_Query( $query_vars );
		}

		$user_query = new \WP_User_Query();
		$distinct   = static function( $query ) use ( $user_query ) {
			// Query hooks may start unrelated nested queries; only change this population.
			if ( $query === $user_query ) {
				self::set_distinct_user_query( $query );
			}
		};
		add_action( 'pre_user_query', $distinct );

		try {
			$user_query->prepare_query( $query_vars );
			$user_query->query();
		} finally {
			remove_action( 'pre_user_query', $distinct );
		}

		return $user_query;
	}

	/**
	 * Run WP_Query
	 *
	 * @return object
	 */
	public function run_wp_query() {
		// Cache?
		$posts_query = $this->get_query_cache();

		if ( $posts_query === false ) {
			add_action( 'pre_get_posts', [ $this, 'set_pagination_with_offset' ], 5 );
			add_filter( 'found_posts', [ $this, 'fix_found_posts_with_offset' ], 5, 2 );

			/**
			 * As long as weighted_relevance is set, and no query filter sort flag is set, we change orderby to post__in and remove order
			 *
			 *  @since 2.2
			 */
			if (
				! isset( $this->query_vars['brx_sort_applied'] ) &&
				isset( $this->query_vars['orderby'] ) &&
				isset( $this->query_vars['brx_orderby'] ) && $this->query_vars['brx_orderby'] === 'weighted_relevance' && ! empty( $this->query_vars['post__in'] ) ) {
				$this->query_vars['orderby'] = 'post__in';
				unset( $this->query_vars['order'] );
			}

			$use_random_seed = self::use_random_seed( $this->query_vars );

			// @since 1.7.1 - Avoid duplicate posts when using 'rand' orderby
			if ( $use_random_seed ) {
				add_filter( 'posts_orderby', [ $this, 'set_bricks_query_loop_random_order_seed' ], 11 );
			}

			/**
			 * Set builder preview query_vars as we are not relying on setup_query function in includes/elements/base.php anymore
			 * Shouldn't merge with preview query_vars if 'disable_query_merge' is set (#86bx7cfxp)
			 * Shouldn't merge with preview query_vars if 'woo_disable_query_merge' is set for Products element (@since 1.9.9)
			 *
			 * @since 1.9.1
			 */
			if ( Helpers::is_bricks_preview() && ! isset( $this->query_vars['disable_query_merge'] ) && ! isset( $this->query_vars['woo_disable_query_merge'] ) ) {
				$post_id                    = Database::$page_data['preview_or_post_id'];
				$builder_preview_query_vars = Helpers::get_template_preview_query_vars( $post_id );

				// Use custom deep merge function instead of wp_parse_args() as second parameter is just a default value (@since 1.9.4)
				$this->query_vars = self::merge_query_vars( $this->query_vars, $builder_preview_query_vars );
			}

			/**
			 * Use main query if:
			 * - User set is_archive_main_query to true
			 * - Not in builder preview
			 * - Not in single post / page / attachment
			 * - Not infinite scroll or load more request
			 * - Not render_query_result request
			 *
			 * Otherwise, init a new query.
			 *
			 * @since 1.9.1
			 */
			$is_archive_main_query = isset( $this->settings['query']['is_archive_main_query'] ) ? true : false;

			if ( $is_archive_main_query && ! Helpers::is_bricks_preview() && ! is_singular() && ! Api::is_current_endpoint( 'load_query_page' ) && ! Api::is_current_endpoint( 'query_result' ) && ! Api::is_current_endpoint( 'load_popup_content' ) ) {
				global $wp_query;
				$posts_query = $wp_query;
			} else {
				$is_builder_ajax_query = bricks_is_ajax_call() && is_admin() && bricks_is_builder_call();

				if ( $is_builder_ajax_query ) {
					/**
					 * Instantiate the query without arguments so its identity is available before query().
					 * Passing arguments to the constructor would run pre_get_posts before the callback can
					 * distinguish this query from WP_Query instances started by other callbacks.
					 */
					$posts_query                 = new \WP_Query();
					$this->builder_preview_query = $posts_query;

					add_action( 'pre_get_posts', [ $this, 'set_builder_preview_query_home' ], 5 );

					try {
						$posts_query->query( $this->query_vars );
					} finally {
						// Never leak the global callback if a third-party query hook throws an exception.
						remove_action( 'pre_get_posts', [ $this, 'set_builder_preview_query_home' ], 5 );
						$this->builder_preview_query = null;
					}
				} else {
					$posts_query = new \WP_Query( $this->query_vars );
				}
			}

			// @since 1.7.1 - Avoid duplicate posts when using 'rand' orderby
			if ( $use_random_seed ) {
				remove_filter( 'posts_orderby', [ $this, 'set_bricks_query_loop_random_order_seed' ], 11 );
			}

			remove_action( 'pre_get_posts', [ $this, 'set_pagination_with_offset' ], 5 );
			remove_filter( 'found_posts', [ $this, 'fix_found_posts_with_offset' ], 5, 2 );

			$this->set_query_cache( $posts_query );
		}

		return $posts_query;
	}

	/**
	 * Get the page number for a query based on the query var "paged"
	 *
	 * @since 1.5
	 *
	 * @return integer
	 */
	public static function get_paged_query_var( $query_vars ) {
		$paged = 1;

		/**
		 * Return paged 1 if 'disable_query_merge' is true
		 *
		 * Avoid query_var param merged accidentally if 'disable_query_merge' is true
		 *
		 * Return paged 1 if 'woo_disable_query_merge' is true for Product elements (@since 1.9.9)
		 *
		 * @since 1.7.1
		 */
		if ( isset( $query_vars['disable_query_merge'] ) || isset( $query_vars['woo_disable_query_merge'] ) ) {
			return $paged;
		}

		if ( \Bricks\Helpers::get_ajax_current_page() ) {
			// (@since 2.2)
			$paged = \Bricks\Helpers::get_ajax_current_page();
		} elseif ( get_query_var( 'page' ) ) {
			// Check for 'page' on static front page
			$paged = get_query_var( 'page' );
		} elseif ( get_query_var( 'paged' ) ) {
			$paged = get_query_var( 'paged' );
		} else {
			$paged = ! empty( $query_vars['paged'] ) ? abs( $query_vars['paged'] ) : 1;
		}

		return intval( $paged );
	}

	/**
	 * Parse the Meta Query vars through the DD logic
	 *
	 * @Since 1.5
	 *
	 * @param array $query_vars
	 * @return array
	 */
	public static function parse_meta_query_vars( $query_vars ) {
		if ( empty( $query_vars['meta_query'] ) ) {
			return $query_vars;
		}

		// Loop through meta_query and rebuild the meta_query vars
		foreach ( $query_vars['meta_query'] as $key => $query_item ) {
			// Unset the id key
			if ( isset( $query_item['id'] ) ) {
				unset( $query_item['id'] );
			}

			// Render dynamic data
			if ( isset( $query_item['value'] ) ) {
				$query_item['value'] = bricks_render_dynamic_data( $query_item['value'] );
			}

			// Handle 'clause_name' for orderby (@since 1.12)
			$clause_name = '';
			if ( isset( $query_item['clause_name'] ) ) {
				$clause_name = esc_html( $query_item['clause_name'] );
				unset( $query_item['clause_name'] );
			}

			// Assign modified query item back to the query vars
			$query_vars['meta_query'][ $key ] = $query_item;

			// Use clause name as key if set (for orderby) (@since 1.12)
			if ( $clause_name !== '' ) {
				// Assign the clause to the new key
				$query_vars['meta_query'][ $clause_name ] = $query_item;
				// Unset the original key
				unset( $query_vars['meta_query'][ $key ] );
			}
		}

		if ( ! empty( $query_vars['meta_query_relation'] ) ) {
			$query_vars['meta_query']['relation'] = $query_vars['meta_query_relation'];
		}

		unset( $query_vars['meta_query_relation'] );

		return $query_vars;
	}

	/**
	 * Parse the Orderby vars
	 *
	 * @since 1.11.1
	 */
	public static function parse_orderby_vars( $query_vars, $object_type ) {
		if ( ! in_array( $object_type, [ 'post', 'user' ] ) ) {
			return $query_vars;
		}

		$orderby        = $query_vars['orderby'] ?? 'date'; // Default orderby = date
		$order          = $query_vars['order'] ?? 'DESC'; // Default order = DESC
		$new_orderby    = [];
		$use_wp_default = false;

		// orderby & order might be multiple values
		if ( is_array( $orderby ) ) {

			foreach ( $orderby as $index => $option ) {
				// Custom key to set WP default orderby
				if ( $option === '_default' ) {
					$use_wp_default = true;
					break;
				}

				// These options wouldn't work with multiple orderby (@since 1.12)
				if ( in_array( $option, [ 'post__in', 'post_name__in', 'post_parent__in', 'rand', 'relevance' ], true ) ) {
					// As long as these options found, set orderby as string and break the loop
					$new_orderby = $option;
					break;
				}

				$new_orderby[ $option ] = is_array( $order ) && isset( $order[ $index ] ) ? strtoupper( $order[ $index ] ) : 'DESC';
			}

			// Always unset order if orderby is an array
			unset( $query_vars['order'] );
		} else {
			$use_wp_default = $orderby === '_default';
			$new_orderby    = $orderby;

			// Correction if order is an array but new_orderby is not an array (#86c4j20h9)
			if ( is_array( $order ) && ! empty( $order ) && is_string( $new_orderby ) ) {
				$new_orderby = [
					$new_orderby => strtoupper( $order[0] ?? 'DESC' ),
				];

				unset( $query_vars['order'] );
			}
		}

		if ( $use_wp_default ) {
			// Use WP default, unset orderby key to avoid modifying the query (@since 1.12)
			unset( $query_vars['orderby'] );
			$query_vars['brx_default_orderby'] = true; // Set a flag to be used in WooCommerce logic
		} else {
			// Set new orderby
			$query_vars['orderby'] = $new_orderby;
		}

		return $query_vars;
	}

	/**
	 * Set 'tax_query' vars (e.g. Carousel, Posts, Related Posts)
	 *
	 * Include & exclude terms of different taxonomies
	 *
	 * @since 1.3.2
	 */
	public static function set_tax_query_vars( $query_vars ) {
		// Include terms
		if ( isset( $query_vars['tax_query'] ) ) {
			$terms     = $query_vars['tax_query'];
			$tax_query = [];

			foreach ( $terms as $term ) {
				if ( ! is_string( $term ) ) {
					continue;
				}

				$term_parts = explode( '::', $term );
				$taxonomy   = isset( $term_parts[0] ) ? $term_parts[0] : false;
				$term       = isset( $term_parts[1] ) ? $term_parts[1] : false;

				if ( ! $taxonomy || ! $term ) {
					continue;
				}

				if ( isset( $tax_query[ $taxonomy ] ) ) {
					$tax_query[ $taxonomy ]['terms'][] = $term;
				} else {
					$tax_query[ $taxonomy ] = [
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => [ $term ],
					];
				}
			}

			$tax_query = array_values( $tax_query );

			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'OR';

				$query_vars['tax_query'] = [ $tax_query ];
			} else {
				$query_vars['tax_query'] = $tax_query;
			}
		}

		// Exclude terms
		if ( isset( $query_vars['tax_query_not'] ) ) {
			$terms             = $query_vars['tax_query_not'];
			$tax_query_exclude = [];

			foreach ( $query_vars['tax_query_not'] as $term ) {
				if ( ! is_string( $term ) ) {
					continue;
				}

				$term_parts = explode( '::', $term );
				$taxonomy   = $term_parts[0];
				$term       = $term_parts[1];

				if ( isset( $tax_query_exclude[ $taxonomy ] ) ) {
					$tax_query_exclude[ $taxonomy ]['terms'][] = $term;
				} else {
					$tax_query_exclude[ $taxonomy ] = [
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => [ $term ],
						'operator' => 'NOT IN',
					];
				}
			}

			$tax_query_exclude = array_values( $tax_query_exclude );

			if ( count( $tax_query_exclude ) > 1 ) {
				$tax_query_exclude['relation'] = 'AND';

				$query_vars['tax_query'][] = [ $tax_query_exclude ];
			} else {
				$query_vars['tax_query'][] = $tax_query_exclude;
			}

			unset( $query_vars['tax_query_not'] );
		}

		if ( isset( $query_vars['tax_query_advanced'] ) ) {
			foreach ( $query_vars['tax_query_advanced'] as $tax_query ) {
				// Remove Bricks controls IDs
				unset( $tax_query['id'] );

				// Sometimes terms might be empty when using EXIST or NOT EXIST compare operator (@since 1.12)
				if ( isset( $tax_query['terms'] ) ) {
					$tax_query['terms'] = bricks_render_dynamic_data( $tax_query['terms'] );

					if ( strpos( $tax_query['terms'], ',' ) ) {
						$tax_query['terms'] = explode( ',', $tax_query['terms'] );
						$tax_query['terms'] = array_map( 'trim', $tax_query['terms'] );
					}
				}

				if ( isset( $tax_query['include_children'] ) ) {
					$tax_query['include_children'] = filter_var( $tax_query['include_children'], FILTER_VALIDATE_BOOLEAN );
				}

				$query_vars['tax_query'][] = $tax_query;
			}
		}

		if ( isset( $query_vars['tax_query'] ) && is_array( $query_vars['tax_query'] ) && count( $query_vars['tax_query'] ) > 1 ) {
			$query_vars['tax_query']['relation'] = isset( $query_vars['tax_query_relation'] ) ? $query_vars['tax_query_relation'] : 'AND';
		}

		unset( $query_vars['tax_query_relation'] );
		unset( $query_vars['tax_query_advanced'] );

		return $query_vars;
	}

	/**
	 * Parse include/exclude dynamic data for post/author IDs.
	 * (Previously was set_post_in_vars static function, but moved to a more generic function to be used for author__in and author__not_in as well)
	 * #86c75ahhj
	 *
	 * @since 2.4
	 */
	public static function set_include_exclude_in_vars( $query_vars ) {
		$keys = [
			'post__in',
			'post__not_in',
			'author__in',
			'author__not_in',
		];

		return self::parse_dynamic_id_in_vars( $query_vars, $keys );
	}

	/**
	 * Shared parser for include/exclude ID arrays.
	 *
	 * @since 2.4
	 */
	private static function parse_dynamic_id_in_vars( $query_vars, $keys = [] ) {
		foreach ( (array) $keys as $key ) {
			if ( ! isset( $query_vars[ $key ] ) ) {
				continue;
			}

			// Maybe user places comma separated string via hooks or query editor.
			$values = is_array( $query_vars[ $key ] ) ? $query_vars[ $key ] : explode( ',', $query_vars[ $key ] );

			$new_values = [];

			foreach ( $values as $data ) {
				if ( is_string( $data ) ) {
					$data = trim( $data );

					if ( strpos( $data, '{' ) !== false && strpos( $data, '}' ) !== false ) {
						// If needed, insert :value to retrieve IDs only from dynamic tags.
						if ( strpos( $data, ':value' ) === false ) {
							$data = str_replace( '}', ':value}', $data );
						}

						$data = bricks_render_dynamic_data( $data );

						// It should contain comma separated string after parsing dynamic data.
						if ( strpos( $data, ',' ) !== false ) {
							$data = explode( ',', $data );
							$data = array_map( 'trim', $data );

							if ( ! empty( $data ) ) {
								$new_values = array_merge( $new_values, $data );
								continue;
							}
						}

						// Maybe <br> as separator for certain dynamic data in MetaBox.
						elseif ( strpos( $data, '<br>' ) !== false ) {
							$data = explode( '<br>', $data );
							$data = array_map( 'trim', $data );

							if ( ! empty( $data ) ) {
								$new_values = array_merge( $new_values, $data );
								continue;
							}
						}
					}
				}

				$new_values[] = $data;
			}

			$query_vars[ $key ] = $new_values;
		}

		return $query_vars;
	}

	/**
	 * If post__in and post__not_in are set, correct the query
	 *
	 * @since 2.0
	 */
	public static function post_in_correction( $query_vars ) {
		if ( ! isset( $query_vars['post__in'] ) || ! isset( $query_vars['post__not_in'] ) ) {
			return $query_vars;
		}

		$post__in     = $query_vars['post__in'];
		$post__not_in = $query_vars['post__not_in'];

		// If both are empty, return
		if ( empty( $post__in ) && empty( $post__not_in ) ) {
			return $query_vars;
		}

		// If post__in is empty, return
		if ( empty( $post__in ) ) {
			return $query_vars;
		}

		// If post__not_in is empty, return
		if ( empty( $post__not_in ) ) {
			return $query_vars;
		}

		// If both are set, remove the post__not_in from post__in
		$query_vars['post__in'] = array_diff( $post__in, $post__not_in );

		// If post__in is empty, force to show empty results
		if ( empty( $query_vars['post__in'] ) ) {
			$query_vars['post__in'] = [ 0 ];
		}

		// Remove post__not_in
		unset( $query_vars['post__not_in'] );

		return $query_vars;
	}

	/**
	 * Modifies $query offset variable to make pagination work in combination with offset.
	 *
	 * @see https://codex.wordpress.org/Making_Custom_Queries_using_Offset_and_Pagination
	 * Note that the link recommends exiting the filter if $query->is_paged returns false,
	 * but then max_num_pages on the first page is incorrect.
	 *
	 * @param \WP_Query $query WordPress query.
	 */
	public function set_pagination_with_offset( $query ) {
		if ( ! isset( $this->query_vars['offset'] ) ) {
			return;
		}

		$new_offset = $this->query_vars['offset'] + ( $query->get( 'paged', 1 ) - 1 ) * $query->get( 'posts_per_page' );
		$query->set( 'offset', $new_offset );
	}

	/**
	 * Restore frontend home-query semantics for post loops rendered through builder AJAX.
	 *
	 * WordPress marks queries created under admin-ajax.php as admin queries. This prevents an
	 * otherwise standard posts query from becoming a home query, so core skips its sticky-post
	 * handling. Only restore the flag when no other query type would prevent it on the frontend.
	 *
	 * @param \WP_Query $query WordPress query.
	 *
	 * @since 2.3.13
	 */
	public function set_builder_preview_query_home( $query ) {
		// pre_get_posts is global, so leave nested or third-party queries untouched.
		if ( $query !== $this->builder_preview_query ) {
			return;
		}

		// The target query can trigger more queries later in get_posts(), so this callback is now done.
		remove_action( 'pre_get_posts', [ $this, 'set_builder_preview_query_home' ], 5 );

		// Guard conditional methods added after the minimum supported WordPress version.
		$is_favicon = method_exists( $query, 'is_favicon' ) && $query->is_favicon();
		$is_sitemap = method_exists( $query, 'is_sitemap' ) && $query->is_sitemap();

		if (
			$query->is_singular() ||
			$query->is_archive() ||
			$query->is_search() ||
			$query->is_feed() ||
			$query->is_trackback() ||
			$query->is_404() ||
			$query->is_robots() ||
			$is_favicon ||
			$is_sitemap
		) {
			return;
		}

		$query->is_home = true;
	}

	/**
	 * Handle term pagination
	 *
	 * @since 1.9.8
	 */
	public static function get_term_pagination_query_var( $query_vars ) {
		// Pagination: Fix the offset value
		$offset = ! empty( $query_vars['offset'] ) ? $query_vars['offset'] : 0;

		// Store the original offset value
		$query_vars['original_offset'] = $offset;

		// If pagination exists, and number is limited (!= 0), use $offset as the pagination trigger
		if ( isset( $query_vars['paged'] ) && $query_vars['paged'] !== 1 && ! empty( $query_vars['number'] ) ) {
			$query_vars['offset'] = ( $query_vars['paged'] - 1 ) * $query_vars['number'] + $offset;
		}

		return $query_vars;
	}

	/**
	 * Handle user pagination
	 *
	 * @since 1.12
	 */
	public static function get_user_pagination_query_var( $query_vars ) {
		// Pagination (number, offset, paged). Default is "-1" but as a safety procedure we limit the number (0 is not allowed)
		$query_vars['number'] = ! empty( $query_vars['number'] ) ? $query_vars['number'] : get_option( 'posts_per_page' );

		// Pagination: Fix the offset value (@since 1.5)
		$offset = ! empty( $query_vars['offset'] ) ? $query_vars['offset'] : 0;

		// Store the original offset value (@since 1.9.1)
		$query_vars['original_offset'] = $offset;

		if ( ! empty( $offset ) && $query_vars['paged'] !== 1 ) {
			$query_vars['offset'] = ( $query_vars['paged'] - 1 ) * $query_vars['number'] + $offset;
		}

		return $query_vars;
	}


	public static function get_array_pagination_query_var( $query_vars ) {
		// Pagination: Fix the offset value
		$offset = ! empty( $query_vars['offset'] ) ? $query_vars['offset'] : 0;

		// Store the original offset value
		$query_vars['original_offset'] = $offset;

		// If pagination exists, and number is limited (!= 0), use $offset as the pagination trigger
		if ( isset( $query_vars['paged'] ) && $query_vars['paged'] !== 1 && ! empty( $query_vars['items_per_page'] ) ) {
			$query_vars['offset'] = ( $query_vars['paged'] - 1 ) * $query_vars['items_per_page'] + $offset;
		}

		return $query_vars;
	}

	/**
	 * By default, WordPress includes offset posts into the final post count.
	 * This method excludes them.
	 *
	 * @see https://codex.wordpress.org/Making_Custom_Queries_using_Offset_and_Pagination
	 * Note that the link recommends exiting the filter if $query->is_paged returns false,
	 * but then max_num_pages on the first page is incorrect.
	 *
	 * @param int       $found_posts Found posts.
	 * @param \WP_Query $query WordPress query.
	 * @return int Modified found posts.
	 */
	public function fix_found_posts_with_offset( $found_posts, $query ) {
		if ( ! isset( $this->query_vars['offset'] ) ) {
			return $found_posts;
		}

		return $found_posts - $this->query_vars['offset'];
	}

	/**
	 * Set the initial loop index (needed for the infinite scroll)
	 *
	 * @since 1.5
	 */
	public function init_loop_index() {
		$paged         = isset( $this->query_vars['paged'] ) ? $this->query_vars['paged'] : 1;
		$offset        = isset( $this->query_vars['offset'] ) ? $this->query_vars['offset'] : 0;
		$initial_index = 0;

		switch ( $this->object_type ) {
			// Post loop
			case 'post':
				// 'posts_per_page' not set by default when using 'queryEditor' (@since 1.9.1)
				$posts_per_page = isset( $this->query_vars['posts_per_page'] ) ? intval( $this->query_vars['posts_per_page'] ) : get_option( 'posts_per_page' );
				$initial_index  = $offset + ( $posts_per_page > 0 ? ( $paged - 1 ) * $posts_per_page : 0 );
				break;

			// Term loop
			case 'term':
				$initial_index = isset( $this->query_vars['offset'] ) ? $this->query_vars['offset'] : 0;
				break;

			// User loop
			case 'user':
				$initial_index = $offset + ( ! isset( $this->query_vars['brx_consumed_offset'] ) && isset( $this->query_vars['number'] ) && $this->query_vars['number'] > 0 ? ( $paged - 1 ) * $this->query_vars['number'] : 0 );
				break;

			// Array loop
			case 'array':
				$initial_index = isset( $this->query_vars['offset'] ) ? $this->query_vars['offset'] : 0;
				break;
		}

		$initial_index += (int) ( $this->query_vars['brx_consumed_offset'] ?? 0 );

		/**
		 * @see https://academy.bricksbuilder.io/article/filter-bricks-query-init_loop_index/
		 *
		 * @since 1.11
		 */
		$this->initial_loop_index = apply_filters( 'bricks/query/init_loop_index', $initial_index, $this->object_type, $this );

		return $this->initial_loop_index;
	}

	/**
	 * Main render function
	 *
	 * @param string  $callback to render each item.
	 * @param array   $args callback function args.
	 * @param boolean $return_array whether returns a string or an array of all the iterations.
	 */
	public function render( $callback, $args, $return_array = false ) {
		// Remove array keys
		$args = array_values( $args );

		// Query results
		$query_result = $this->query_result;

		$content = [];

		$this->loop_index = $this->init_loop_index();

		$this->is_looping = true;

		// @see https://academy.bricksbuilder.io/article/action-bricks-query-before_loop (@since 1.7.2)
		do_action( 'bricks/query/before_loop', $this, $args );

		// Query is empty
		if ( empty( $this->count ) ) {
			$this->is_looping = false;
			$content[]        = $this->get_no_results_content();

			/**
			 * Use fake query to continue execute the remaining elements inside the query loop.
			 *
			 * This can ensure all necessary element's script and styles enqueued on page load. Also AJAX popups in any nested templates or injected via custom code can be generated and output on page load.
			 *
			 * @since 1.12.2
			 */
			if (
				Helpers::handle_no_results_children_elements() &&
				! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) &&
				! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			) {
				$this->is_looping = true;
				$query_result     = $this->fake_result;

				// STEP: Loop posts
				if ( $this->object_type == 'post' ) {
					$this->original_post_id = get_the_ID();

					while ( $query_result->have_posts() ) {
						$query_result->the_post();

						$this->loop_object = get_post();

						$part = call_user_func_array( $callback, $args );

						self::parse_dynamic_data( $part, get_the_ID() );

						$this->loop_index++;
					}
				}

				// STEP: Loop terms
				elseif ( $this->object_type == 'term' ) {
					foreach ( $query_result as $term_object ) {
						$this->loop_object = $term_object;

						$part = call_user_func_array( $callback, $args );

						self::parse_dynamic_data( $part, get_the_ID() );

						$this->loop_index++;
					}
				}

				// STEP: Loop users
				elseif ( $this->object_type == 'user' ) {
					foreach ( $query_result as $user_object ) {
						$this->loop_object = $user_object;

						$part = call_user_func_array( $callback, $args );

						self::parse_dynamic_data( $part, get_the_ID() );

						$this->loop_index++;
					}
				}

				// STEP: Other render providers (wooCart, ACF repeater, Meta Box groups)
				else {
					$this->original_post_id = get_the_ID();

					foreach ( $query_result as $loop_key => $loop_object ) {
						// @see: https://academy.bricksbuilder.io/article/filter-bricks-query-loop_object/
						$this->loop_object = apply_filters( 'bricks/query/loop_object', $loop_object, $loop_key, $this );

						$part = call_user_func_array( $callback, $args );

						self::parse_dynamic_data( $part, get_the_ID() );

						$this->loop_index++;
					}
				}
			}
		}

		// Iterate
		else {
			if ( Database::get_setting( 'cssLoading' ) === 'file' ) {
				// Has results - dequeue the "no results" template CSS if it exists (@since 1.12)
				$no_results_template_id = $this->settings['query']['no_results_template'] ?? false;

				if ( $no_results_template_id ) {
					// This was optimistically enqueued in Files->scan_for_templates()
					wp_dequeue_style( "bricks-post-$no_results_template_id" );
				}
			}

			// STEP: Loop posts
			if ( $this->object_type == 'post' ) {
				$this->original_post_id = get_the_ID();

				while ( $query_result->have_posts() ) {
					$query_result->the_post();

					$this->loop_object = get_post();

					$part = call_user_func_array( $callback, $args );

					$content[] = self::parse_dynamic_data( $part, get_the_ID() );

					$this->loop_index++;
				}
			}

			// STEP: Loop terms
			elseif ( $this->object_type == 'term' ) {
				foreach ( $query_result as $term_object ) {
					$this->loop_object = $term_object;

					$part = call_user_func_array( $callback, $args );

					$content[] = self::parse_dynamic_data( $part, get_the_ID() );

					$this->loop_index++;
				}
			}

			// STEP: Loop users
			elseif ( $this->object_type == 'user' ) {
				foreach ( $query_result as $user_object ) {
					$this->loop_object = $user_object;

					$part = call_user_func_array( $callback, $args );

					$content[] = self::parse_dynamic_data( $part, get_the_ID() );

					$this->loop_index++;
				}
			}

			// STEP: Other render providers (wooCart, ACF repeater, Meta Box groups)
			else {
				$this->original_post_id = get_the_ID();

				foreach ( $query_result as $loop_key => $loop_object ) {
					// @see: https://academy.bricksbuilder.io/article/filter-bricks-query-loop_object/
					$this->loop_object = apply_filters( 'bricks/query/loop_object', $loop_object, $loop_key, $this );

					$part = call_user_func_array( $callback, $args );

					$content[] = self::parse_dynamic_data( $part, get_the_ID() );

					$this->loop_index++;
				}
			}

			// STEP: Remove the HTML content if live_search is enabled as it's not needed on initial page load (@since 1.9.6)
			$is_live_search         = $this->settings['query']['is_live_search'] ?? false;
			$supress_render_content = $is_live_search && ! Api::is_current_endpoint( 'query_result' ) && Helpers::enabled_query_filters() && ! bricks_is_builder_call();
			$supress_render_content = apply_filters( 'bricks/query/supress_render_content', $supress_render_content, $this );
			if ( $supress_render_content ) {
				$content = [];
			}
		}

		/**
		 * Custom Marker to avoid HTML comment removal by plugins
		 * - will be converted to HTML comment in the frontend
		 *
		 * @since 1.12.3
		 */
		if ( is_array( $content ) && isset( $content[0] ) ) {
			$content[0] = $this->maybe_add_loop_marker( $content[0] );
		}

		// @see https://academy.bricksbuilder.io/article/action-bricks-query-after_loop (@since 1.7.2)
		do_action( 'bricks/query/after_loop', $this, $args );

		$this->loop_object = null;

		$this->is_looping = false;

		$this->reset_postdata();

		return $return_array ? $content : implode( '', $content );
	}

	public static function parse_dynamic_data( $content, $post_id ) {
		if ( is_array( $content ) ) {
			if ( isset( $content['background']['image']['useDynamicData'] ) ) {
				$size = isset( $content['background']['image']['size'] ) ? $content['background']['image']['size'] : BRICKS_DEFAULT_IMAGE_SIZE;

				$images = Integrations\Dynamic_Data\Providers::render_tag( $content['background']['image']['useDynamicData'], $post_id, 'image', [ 'size' => $size ] );

				if ( isset( $images[0] ) ) {
					$content['background']['image']['url'] = is_numeric( $images[0] ) ? wp_get_attachment_image_url( $images[0], $size ) : $images[0];

					unset( $content['background']['image']['useDynamicData'] );
				}
			}

			return map_deep( $content, [ 'Bricks\Integrations\Dynamic_Data\Providers', 'render_content' ] );
		} else {
			return bricks_render_dynamic_data( $content, $post_id );
		}
	}

	/**
	 * Reset the global $post to the parent query or the global $wp_query
	 *
	 * @since 1.5
	 *
	 * @return void
	 */
	public function reset_postdata() {
		// Reset is not needed
		if ( empty( $this->original_post_id ) ) {
			return;
		}

		$looping_query_id = self::is_any_looping();

		// Not a nested query, reset global query
		if ( ! $looping_query_id ) {
			wp_reset_postdata();
		}

		// Set the parent query context
		global $post;

		$post = get_post( $this->original_post_id );

		setup_postdata( $post );
	}

	/**
	 * Get the current Query object
	 *
	 * @return Query
	 */
	public static function get_query_object( $query_id = false ) {
		global $bricks_loop_query;

		if ( ! is_array( $bricks_loop_query ) || $query_id && ! array_key_exists( $query_id, $bricks_loop_query ) ) {
			return false;
		}

		return $query_id ? $bricks_loop_query[ $query_id ] : end( $bricks_loop_query );
	}

	/**
	 * Get the current Query object type
	 *
	 * @return string
	 */
	public static function get_query_object_type( $query_id = '' ) {
		$query = self::get_query_object( $query_id );

		return $query ? $query->object_type : '';
	}

	/**
	 * Get the object of the current loop iteration
	 *
	 * @return mixed
	 */
	public static function get_loop_object( $query_id = '' ) {
		$query = self::get_query_object( $query_id );

		return $query ? $query->loop_object : null;
	}

	/**
	 * Get the object ID of the current loop iteration
	 *
	 * @return mixed
	 */
	public static function get_loop_object_id( $query_id = '' ) {
		$object = self::get_loop_object( $query_id );

		$object_id = 0;

		if ( is_a( $object, 'WP_Post' ) ) {
			$object_id = $object->ID;
		}

		if ( is_a( $object, 'WP_Term' ) ) {
			$object_id = $object->term_id;
		}

		if ( is_a( $object, 'WP_User' ) ) {
			$object_id = $object->ID;
		}

		/**
		 * Non-WP query loops (ACF, Meta Box, Woo Cart, etc.)
		 *
		 * @since 1.9.1.1
		 */
		if ( ! $object_id ) {
			$any          = $query_id ? $query_id : self::is_any_looping();
			$query_object = self::get_query_object( $any );

			if ( is_a( $query_object, 'Bricks\Query' ) ) {
				$object_id = $query_object->loop_index;
			}
		}

		// @see: https://academy.bricksbuilder.io/article/filter-bricks-query-loop_object_id/
		return apply_filters( 'bricks/query/loop_object_id', $object_id, $object, $query_id );
	}

	/**
	 * Get the object type of the current loop iteration
	 *
	 * @return mixed
	 */
	public static function get_loop_object_type( $query_id = '' ) {
		$object = self::get_loop_object( $query_id );

		$object_type = null;

		if ( is_a( $object, 'WP_Post' ) ) {
			$object_type = 'post';
		}

		if ( is_a( $object, 'WP_Term' ) ) {
			$object_type = 'term';
		}

		if ( is_a( $object, 'WP_User' ) ) {
			$object_type = 'user';
		}

		// @see: https://academy.bricksbuilder.io/article/filter-bricks-query-loop_object_type/
		return apply_filters( 'bricks/query/loop_object_type', $object_type, $object, $query_id );
	}

	/**
	 * Get the current loop iteration index
	 *
	 * @since 1.10: Add $query_id to get the loop index of a specific query
	 *
	 * @return mixed
	 */
	public static function get_loop_index( $query_id = '' ) {
		// For AJAX popup to simulate is_looping if context being set (@since 1.9.4)
		$force_loop_index = apply_filters( 'bricks/query/force_loop_index', '' );

		if ( $force_loop_index !== '' ) {
			return $force_loop_index;
		}

		$query = self::get_query_object( $query_id );

		return $query && $query->is_looping ? $query->loop_index : '';
	}

	/**
	 * Get a unique identifier for the current looping query
	 *
	 * @param string $type 'query', 'interaction', 'popup'
	 * @return string
	 * @since 1.10
	 */
	public static function get_looping_unique_identifier( $type = 'query' ) {
		$looping_query_id = self::is_any_looping();

		if ( ! $looping_query_id ) {
			return '';
		}

		/**
		 * Looping dynamic data CSS
		 *
		 * Example: background-image, color, etc.
		 *
		 * @since 1.10
		 */
		if ( $type === 'query' ) {
			// Top level loop
			if ( self::get_looping_level() < 1 ) {
				$component_id = self::get_query_element_component_id( $looping_query_id );
				$instance_id  = self::get_query_element_instance_id( $looping_query_id );

				if ( $component_id ) {
					// Add query element ID if component ID exists (@since 1.12.2)
					// Format: query_element_id:loop_index
					$unique_loop_id = [
						self::get_query_element_id( $looping_query_id ),
						self::get_loop_index( $looping_query_id ),
					];
				} elseif ( $instance_id ) {
					// Format: instance_id:loop_index (#86c511c31 @since 2.0.2)
					$unique_loop_id = [
						$instance_id,
						self::get_loop_index( $looping_query_id ),
					];
				} else {
					// Format: loop_index
					$unique_loop_id = [
						self::get_loop_index( $looping_query_id )
					];
				}

			}

			// Nested loop
			else {
				// Format: parent_element_id:parent_loop_index:query_element_id:loop_index
				$parent_loop_id = self::get_parent_loop_id();
				$unique_loop_id = [
					self::get_query_element_id( $parent_loop_id ),
					self::get_loop_index( $parent_loop_id ),
					self::get_query_element_id( $looping_query_id ),
					self::get_loop_index( $looping_query_id ),
				];
			}
		}

		/**
		 * For AJAX popup data attribute: data-popup-loop-id
		 * For interactions data attribute: data-interaction-loop-id
		 *
		 * Avoid incorrect popup trigger in nested loops
		 *
		 * @since 1.9.4
		 */
		else {
			// Top level loop
			if ( self::get_looping_level() < 1 ) {
				// Format: query_element_id:loop_index:object_type:object_id
				$unique_loop_id = [
					self::get_query_element_id( $looping_query_id ),
					self::get_loop_index( $looping_query_id ),
					self::get_loop_object_type( $looping_query_id ),
					self::get_loop_object_id( $looping_query_id ),
				];
			}

			// Nested loop
			else {
				/**
				 * Format: parent_element_id:parent_loop_index:query_element_id:loop_index:parent_query_element_id:parent_loop_index
				 *
				 * parent_query_element_id:parent_loop_index (@since 1.12)
				 */
				$parent_loop_id = self::get_parent_loop_id();
				$unique_loop_id = [
					self::get_query_element_id( $looping_query_id ),
					self::get_loop_index( $looping_query_id ),
					self::get_loop_object_type( $looping_query_id ),
					self::get_loop_object_id( $looping_query_id ),
					self::get_query_element_id( $parent_loop_id ),
					self::get_loop_index( $parent_loop_id ),
				];
			}
		}

		return implode( ':', $unique_loop_id );
	}

	/**
	 * Check if the render function is looping (in the current query)
	 *
	 * @param string $element_id Checks if the element_id matches the element that is set to loop (e.g. container).
	 *
	 * @return boolean
	 */
	public static function is_looping( $element_id = '', $query_id = '' ) {
		// For AJAX popup to simulate is_looping if context being set (@since 1.9.4)
		$force_is_looping = apply_filters( 'bricks/query/force_is_looping', false, $query_id, $element_id );

		if ( $force_is_looping ) {
			return true;
		}

		$query = self::get_query_object( $query_id );

		if ( ! $query ) {
			return false;
		}

		if ( empty( $element_id ) ) {
			return $query->is_looping;
		}

		// Still here, search for the element_id query
		$query = self::get_query_for_element_id( $element_id );

		return $query ? $query->is_looping : false;
	}

	/**
	 * Get query object created for a specific element ID
	 *
	 * @param string $element_id
	 * @return mixed
	 */
	public static function get_query_for_element_id( $element_id = '' ) {
		if ( empty( $element_id ) ) {
			return false;
		}

		global $bricks_loop_query;

		if ( empty( $bricks_loop_query ) ) {
			return false;
		}

		foreach ( $bricks_loop_query as $key => $query ) {
			if ( $query->element_id == $element_id ) {
				return $query;
			}
		}

		return false;
	}

	/**
	 * Get element ID of query loop element
	 *
	 * @param object $query Defaults to current query.
	 *
	 * @since 1.4
	 *
	 * @return string|boolean Element ID or false
	 */
	public static function get_query_element_id( $query = '' ) {
		$query = self::get_query_object( $query );

		return ! empty( $query->element_id ) ? $query->element_id : false;
	}

	/**
	 * Get component ID of query loop element
	 *
	 * @since 1.12.2
	 */
	public static function get_query_element_component_id( $query = '' ) {
		$query = self::get_query_object( $query );

		return ! empty( $query->component_id ) ? $query->component_id : false;
	}

	/**
	 * Get instance ID of query loop element
	 *
	 * @since 2.0.2
	 */
	public static function get_query_element_instance_id( $query = '' ) {
		$query = self::get_query_object( $query );

		return ! empty( $query->instance_id ) ? $query->instance_id : false;
	}

	/**
	 * Check whether all active query contexts are rendering their first real loop node.
	 *
	 * Simulated builder parent queries participate even when they have no results, so an
	 * empty parent cannot leak preview text from a child query rendered without its context.
	 *
	 * @return boolean
	 *
	 * @since 2.3.11
	 */
	public static function is_rendering_first_loop_node() {
		global $bricks_loop_query;

		if ( empty( $bricks_loop_query ) ) {
			return false;
		}

		$has_participating_query = false;

		foreach ( $bricks_loop_query as $query ) {
			if ( ! $query->is_looping && ! $query->is_simulated_loop_context ) {
				continue;
			}

			$has_participating_query = true;

			if (
				empty( $query->count ) ||
				$query->initial_loop_index === null ||
				$query->loop_index !== $query->initial_loop_index
			) {
				return false;
			}
		}

		return $has_participating_query;
	}

	/**
	 * Get the current looping level
	 *
	 * @return int
	 * @since 1.10
	 */
	public static function get_looping_level() {
		global $bricks_loop_query;

		// Avoid array errors
		if ( empty( $bricks_loop_query ) ) {
			return 0;
		}

		$query_ids = array_reverse( array_keys( $bricks_loop_query ) );

		$looping_queries = array_filter(
			$query_ids,
			function( $query_id ) use ( $bricks_loop_query ) {
				return $bricks_loop_query[ $query_id ]->is_looping;
			}
		);

		$level = count( $looping_queries ) > 0 ? count( $looping_queries ) - 1 : 0;
		return $level;
	}

	/**
	 * Get the direct parent loop ID
	 *
	 * @since 1.10
	 */
	public static function get_parent_loop_id() {
		$current_looping_id = self::is_any_looping();

		if ( ! $current_looping_id ) {
			return false;
		}

		global $bricks_loop_query;

		$query_ids = array_reverse( array_keys( $bricks_loop_query ) );

		$looping_queries = array_filter(
			$query_ids,
			function( $query_id ) use ( $bricks_loop_query ) {
				return $bricks_loop_query[ $query_id ]->is_looping;
			}
		);

		$looping_queries = array_values( $looping_queries );

		if ( count( $looping_queries ) < 2 ) {
			return false;
		}

		$parent_loop_id = false;

		foreach ( $looping_queries as $key => $query_id ) {
			if ( $query_id == $current_looping_id ) {
				$parent_loop_id = $looping_queries[ $key + 1 ];
				break;
			}
		}

		return $parent_loop_id;
	}

	/**
	 * Check if there is any active query looping (nested queries) and if yes, return the query ID of the most deep query
	 *
	 * @return mixed
	 */
	public static function is_any_looping() {
		global $bricks_loop_query;

		if ( empty( $bricks_loop_query ) ) {
			return false;
		}

		$query_ids = array_reverse( array_keys( $bricks_loop_query ) );

		foreach ( $query_ids as $query_id ) {
			if ( $bricks_loop_query[ $query_id ]->is_looping ) {
				return $query_id;
			}
		}

		return false;
	}

	/**
	 * Convert a list of option strings taxonomy::term_id into a list of term_ids
	 */
	public static function convert_terms_to_ids( $terms = [] ) {
		if ( empty( $terms ) ) {
			return [];
		}

		$options = [];

		foreach ( $terms as $term ) {
			if ( ! is_string( $term ) ) {
				continue;
			}

			$term_parts = explode( '::', $term );
			// $taxonomy   = $term_parts[0];

			$options[] = $term_parts[1];
		}

		return $options;
	}

	public function get_no_results_content() {
		// Return: Avoid showing no results message when infinite scroll is enabled (@since 1.5.6)
		if ( Api::is_current_endpoint( 'load_query_page' ) ) {
			return '';
		}

		// Return: Avoid showing no results message when live search is enabled and not on query_results API endpoint (@since 1.9.6)
		if ( isset( $this->settings['query']['is_live_search'] ) && ! Api::is_current_endpoint( 'query_result' ) ) {
			return '';
		}

		$template_id = $this->settings['query']['no_results_template'] ?? false;
		$text        = $this->settings['query']['no_results_text'] ?? '';
		$content     = '';

		if ( $template_id && \Bricks\Integrations\Wpml\Wpml::$is_active && has_filter( 'wpml_object_id' ) ) {
			$translated_template_id = apply_filters( 'wpml_object_id', $template_id, BRICKS_DB_TEMPLATE_SLUG, true );

			if ( $translated_template_id && get_post_status( $translated_template_id ) === 'publish' ) {
				$template_id = $translated_template_id;
			}
		}

		if ( $template_id || $text ) {
			// Use template if set
			if ( $template_id ) {
				// Check if the template is published to avoid unncessary queries especially when generate global classes (@since 2.0)
				if ( get_post_status( $template_id ) === 'publish' ) {
					$content = do_shortcode( '[bricks_template id="' . $template_id . '"]' );
					// Generate global classes and insert inline to compatible with third-party plugin, will be removed on next AJAX call together with .bricks-posts-nothing-found (@since 1.12)
					$global_class_key = 'global_classes_' . $template_id;
					Assets::generate_global_classes( $global_class_key );
					$content .= '<style>';
					$content .= Assets::$inline_css[ "$global_class_key" ] ?? '';
					$content .= Assets::$inline_css[ "template_$template_id" ] ?? '';
					$content .= '</style>';
				}
			} else {
				$content = bricks_render_dynamic_data( $text );
				$content = do_shortcode( $content );
			}

			/**
			 * Use custom HTML tag if set
			 *
			 * Must wrap content inside .bricks-posts-nothing-found to target via JavaScript.
			 *
			 * @since 1.9.8
			 */
			$html_tag = Helpers::get_html_tag_from_element_settings( $this->settings, 'div' );

			// Convert <a> to <div> to avoid issues with links within the no results content (@since 1.11)
			if ( $html_tag === 'a' ) {
				$html_tag = 'div';
			}

			$wrapper = "<$html_tag" . ' class="bricks-posts-nothing-found" style="width: inherit; max-width: 100%; grid-column: 1/-1">';

			// Special case for table row
			if ( $html_tag === 'tr' ) {
				$wrapper .= '<td colspan="100%">';
			}

			$content = $wrapper . $content;

			// Special case for table row
			if ( $html_tag === 'tr' ) {
				$content .= '</td>';
			}

			$content .= "</$html_tag>";
		}

		// @see: https://academy.bricksbuilder.io/article/filter-bricks-query_no_results_content/
		$content = apply_filters( 'bricks/query/no_results_content', $content, $this->settings, $this->element_id );

		return $content;
	}

	/**
	 * Insert data-brx-loop-start="$this->element_id" for the first HTML node
	 *
	 * @param string $content
	 * @return string
	 * @since 2.0
	 */
	public function maybe_add_loop_marker( $html ) {
		// Do not generate if AJAX or REST request
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX || defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html;
		}

		// Check if it's a valid data type
		if ( ! is_string( $html ) || trim( $html ) === '' ) {
			return $html;
		}

		// Insert data-brx-loop-start="$this->element_id" to the HTML string
		$html = preg_replace( '/^<([a-z0-9]+)([^>]*)>/i', '<$1$2 data-brx-loop-start="' . $this->element_id . '">', $html );

		return $html;
	}

	/**
	 * Check if the query is using random seed
	 * Use random seed when: 'orderby' is 'rand' && 'randomSeedTtl' > 0
	 * Default: 60 minutes
	 *
	 * @param array $query_vars
	 * @return boolean
	 * @since 1.9.8
	 */
	public static function use_random_seed( $query_vars = [] ) {
		return isset( $query_vars['orderby'] ) && $query_vars['orderby'] === 'rand' && ! ( isset( $query_vars['randomSeedTtl'] ) && absint( $query_vars['randomSeedTtl'] ) === 0 );
	}

	/**
	 * Get the random seed statement for the query
	 *
	 * @param string $element_id
	 * @param array  $query_vars
	 * @return string
	 * @since 1.9.8
	 */
	public static function get_random_seed_statement( $element_id = '', $query_vars = [] ) {
		if ( empty( $element_id ) || ! isset( $query_vars['orderby'] ) || $query_vars['orderby'] !== 'rand' ) {
			return '';
		}

		// Transient name is based on the element ID
		$transient_name = "bricks_query_loop_random_seed_{$element_id}";
		$random_seed    = get_transient( $transient_name );

		if ( ! $random_seed ) {
			// Generate a random seed for this query
			$random_seed = rand( 0, 99999 );

			// Default transient TTL is 60 minutes
			$random_seed_ttl = ! empty( $query_vars['randomSeedTtl'] ) ? absint( $query_vars['randomSeedTtl'] ) : 60;

			set_transient( $transient_name, $random_seed, $random_seed_ttl * MINUTE_IN_SECONDS );
		}

		return 'RAND(' . $random_seed . ')';
	}

	/**
	 * Use random seed to make sure the order is the same for all queries of the same element
	 *
	 * The transient is also deleted when the random seed setting inside the query loop control is changed.
	 *
	 * @param string $order_statement
	 * @return string
	 * @since 1.7.1
	 */
	public function set_bricks_query_loop_random_order_seed( $order_statement ) {
		$random_seed_statement = self::get_random_seed_statement( $this->element_id, $this->query_vars );

		if ( ! empty( $random_seed_statement ) ) {
			return $random_seed_statement;
		}

		return $order_statement;
	}

	/**
	 * Add DISTINCT to the query or multiple same users might be returned if the user has multiple same key meta values
	 * This is a workaround for the issue with the user query and meta query
	 *
	 * @see wp-includes/class-wp-user-query.php search has_or_relation()
	 * @since 1.12
	 */
	public static function set_distinct_user_query( $user_query ) {
		if (
			$user_query->meta_query &&
			$user_query->meta_query->queries &&
			! empty( $user_query->meta_query->queries ) &&
			$user_query->query_fields
		) {
			if ( strpos( $user_query->query_fields, 'DISTINCT ' ) !== 0 ) {
				$user_query->query_fields = 'DISTINCT ' . $user_query->query_fields;
			}
		}
		return $user_query;
	}

	/**
	 * All query arguments that can be set for the archive query
	 * https://developer.wordpress.org/reference/classes/wp_query/#parameters
	 *
	 * @return array
	 *
	 * @since 1.8
	 */
	public static function archive_query_arguments() {
		$arguments = [
			'post_type',
			'post_status',
			'p',
			'page_id',
			'name',
			'pagename',
			'page',
			'hour',
			'minute',
			'second',
			'year',
			'monthnum',
			'day',
			'w',
			'm',
			'cat',
			'category_name',
			'category__and',
			'category__in',
			'category__not_in',
			'tag',
			'tag_id',
			'tag__and',
			'tag__in',
			'tag__not_in',
			'tag_slug__and',
			'tag_slug__in',
			'taxonomy',
			'term',
			'field',
			'operator',
			'include_children',
			'paged',
			'posts_per_page',
			'nopaging',
			'offset',
			'ignore_sticky_posts',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'post__in',
			'post__not_in',
			'post_name__in',
			'author',
			'author_name',
			'author__in',
			'author__not_in',
			's',
			'exact',
			'sentence',
			'meta_key',
			'meta_value',
			'meta_value_num',
			'meta_compare',
			'meta_query',
			'date_query',
			'cache_results',
			'update_post_term_cache',
			'update_post_meta_cache',
			'no_found_rows',
			'order',
			'orderby',
			'perm',
			'post_mime_type',
			'comment_count',
			'comment_status',
			'post_comment_status',
			'tax_query', // @since 1.9.8 (#86by08fg0)
		];

		// NOTE: Undocumented
		return apply_filters( 'bricks/query/archive_query_arguments', $arguments );
	}

	/**
	 * All bricks query object types that can be set for the archive query.
	 * If there is custom query by user and it might be used as archive query, should be added here.
	 *
	 * @return array
	 *
	 * @since 1.8
	 */
	public static function archive_query_supported_object_types() {
		// Only post query should be supported (WP_Query)
		$object_types = [
			'post',
			// 'term',
			// 'user',
		];

		// NOTE: Undocumented
		return apply_filters( 'bricks/query/archive_query_supported_object_types', $object_types );
	}

	/**
	 * Merge two query vars arrays, instead of using wp_parse_args
	 *
	 * wp_parse_args will only set those values that are not already set in the original array.
	 *
	 * @param array $original_query_vars
	 * @param array $merging_query_vars
	 * @param bool  $meta_query_logic (@since 1.11.1)
	 * @return array
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_parse_args/
	 *
	 * @since 1.9.4
	 */
	public static function merge_query_vars( $original_query_vars = [], $merging_query_vars = [], $meta_query_logic = false ) {
		// Avoid null values
		if ( is_null( $merging_query_vars ) ) {
			return $original_query_vars;
		}

		$original_query_vars = self::normalize_include_query_vars( $original_query_vars );
		$merging_query_vars  = self::normalize_include_query_vars( $merging_query_vars );

		// Empty WP_Query author defaults are not constraints and must not participate in merge intersections.
		$original_query_vars = self::remove_empty_author_query_vars( $original_query_vars );
		$merging_query_vars  = self::remove_empty_author_query_vars( $merging_query_vars );

		foreach ( $merging_query_vars as $key => $value ) {
			// If the key already exists in the $original_query_vars, and the value is an array, merge the two arrays
			if ( isset( $original_query_vars[ $key ] ) && is_array( $original_query_vars[ $key ] ) && is_array( $value ) ) {
				/**
				 * Handle special case for 'tax_query'
				 * merging via key might be wrong, as the key is just index of the array
				 */
				if ( $key === 'tax_query' ) {
					$original_query_vars[ $key ] = self::merge_tax_or_meta_query_vars( $original_query_vars[ $key ], $value, 'tax' );
				}

				/**
				 * Handle special case for 'meta_query'
				 *
				 * This logic is still needed for 'meta_query' to work correctly.
				 * Otherwise will merge wrongly into wrong array when performing query filter.
				 *
				 * @since 1.11.1: Add $meta_query_logic for URL query filter on page load (not API endpoint), otherwise meta_query cannot merge correctly.
				 * Review in future see if possible to remove this so all meta_query will run the same logic.
				 *
				 * @since 1.9.8
				 */
				elseif ( $key === 'meta_query' && ( Api::is_current_endpoint( 'query_result' ) || $meta_query_logic ) ) {
					$original_query_vars[ $key ] = self::merge_tax_or_meta_query_vars( $original_query_vars[ $key ], $value, 'meta' );
				}

				/**
				 * Handle special case for 'orderby' in query filter calls
				 *
				 * The sequence of orderby is important, so we need to merge them correctly.
				 */
				elseif ( $key === 'orderby' ) {
					$original_query_vars[ $key ] = self::merge_query_filter_orderby( $original_query_vars[ $key ], $value );
				}

				elseif ( $key === 'role__in' ) {
					// Used in WP_User_Query, should use merging query vars
					$original_query_vars[ $key ] = $value;
				}

				elseif ( $key === 'posts_per_page' || $key === 'number' || $key === 'post_type' ) {
					// Used in WP_Query, WP_Term_Query & WP_User_Query, should use merging query vars
					$original_query_vars[ $key ] = $value;
				}

				// Include constraints must intersect for post, user, and term queries.
				elseif ( in_array( $key, [ 'post__in', 'author__in', 'include' ], true ) ) {
					// Empty arrays are WordPress defaults, not restrictions to zero objects.
					if ( empty( $original_query_vars[ $key ] ) ) {
						$original_query_vars[ $key ] = $value;
					} elseif ( ! empty( $value ) ) {
						$intersect_ids = array_values( array_intersect( $original_query_vars[ $key ], $value ) );
						$empty_ids     = $key === 'include' ? [ PHP_INT_MAX ] : [ 0 ];

						$original_query_vars[ $key ] = empty( $intersect_ids ) ? $empty_ids : $intersect_ids;
					}
				}

				// post__not_in & author__not_in should use union (@since 2.4)
				elseif ( $key === 'post__not_in' || $key === 'author__not_in' ) {
					$original_query_vars[ $key ] = array_values(
						array_unique(
							array_merge( (array) $original_query_vars[ $key ], (array) $value )
						)
					);
				}

				else {
					$original_query_vars[ $key ] = self::merge_query_vars( $original_query_vars[ $key ], $value ); // Recursively merge arrays (@since 1.9.6)
				}

			} else {
				$original_query_vars[ $key ] = $value;
			}
		}

		// Correct the merged query vars to avoid post__in and post__not_in occurs at the same time (@since 2.0)
		$original_query_vars = self::post_in_correction( $original_query_vars );

		// Correct author query vars conflicts (author, author__in, author__not_in) (@since 2.4)
		$original_query_vars = self::author_in_correction( $original_query_vars );

		return $original_query_vars;
	}

	/**
	 * Correct author query vars when author, author__in and author__not_in are combined.
	 * #86c92m5v4
	 *
	 * @since 2.4
	 */
	public static function author_in_correction( $query_vars ) {
		$query_vars = self::remove_empty_author_query_vars( $query_vars );

		if ( ! isset( $query_vars['author'] ) && ! isset( $query_vars['author__in'] ) && ! isset( $query_vars['author__not_in'] ) ) {
			return $query_vars;
		}

		// Normalize author__in
		if ( isset( $query_vars['author__in'] ) ) {
			$author_in                = is_array( $query_vars['author__in'] ) ? $query_vars['author__in'] : explode( ',', (string) $query_vars['author__in'] );
			$author_in                = array_map( 'trim', $author_in );
			$author_in                = array_filter( $author_in, 'strlen' );
			$author_in                = array_map( 'absint', $author_in );
			$author_in                = array_values( array_unique( $author_in ) );
			$query_vars['author__in'] = $author_in;
		}

		// Normalize author__not_in
		if ( isset( $query_vars['author__not_in'] ) ) {
			$author_not_in                = is_array( $query_vars['author__not_in'] ) ? $query_vars['author__not_in'] : explode( ',', (string) $query_vars['author__not_in'] );
			$author_not_in                = array_map( 'trim', $author_not_in );
			$author_not_in                = array_filter( $author_not_in, 'strlen' );
			$author_not_in                = array_map( 'absint', $author_not_in );
			$author_not_in                = array_filter( $author_not_in );
			$author_not_in                = array_values( array_unique( $author_not_in ) );
			$query_vars['author__not_in'] = $author_not_in;
		}

		/**
		 * Handle author parameter
		 * If author exists, split positive values into include and negative values into exclude.
		 * Note: author parameter might contain negative values meant for exclusion
		 */
		if ( isset( $query_vars['author'] ) ) {
			$author_raw = is_array( $query_vars['author'] ) ? $query_vars['author'] : explode( ',', (string) $query_vars['author'] ); // Supposed to be string or integer. For defensive, also handle array and convert to array if it's comma separated string.
			$author_raw = array_map( 'trim', $author_raw );
			$author_raw = array_filter( $author_raw, 'strlen' );

			$author_include = [];
			$author_exclude = [];

			// STEP: Split positive and negative author IDs into separate arrays for inclusion and exclusion
			foreach ( $author_raw as $author_id ) {
				$author_id_int = intval( $author_id );

				if ( $author_id_int < 0 ) {
					$author_exclude[] = absint( $author_id_int );
				} elseif ( $author_id_int > 0 ) {
					$author_include[] = $author_id_int;
				}
			}

			$author_include = array_values( array_unique( $author_include ) );
			$author_exclude = array_values( array_unique( $author_exclude ) );

			// STEP: Merge with existing author__in and author__not_in if they exist, otherwise set them based on author parameter
			if ( ! empty( $author_include ) ) {
				if ( isset( $query_vars['author__in'] ) ) {
					$author_in                = array_intersect( $query_vars['author__in'], $author_include );
					$query_vars['author__in'] = empty( $author_in ) ? [ 0 ] : array_values( $author_in );
				} else {
					$query_vars['author__in'] = $author_include;
				}
			} elseif ( ! isset( $query_vars['author__in'] ) && empty( $author_exclude ) ) {
				// Preserve previous behavior when author is set but contains no usable IDs.
				$query_vars['author__in'] = [ 0 ];
			}

			// If there are excluded authors, merge them with existing author__not_in or set as author__not_in if it doesn't exist.
			if ( ! empty( $author_exclude ) ) {
				if ( isset( $query_vars['author__not_in'] ) ) {
					$query_vars['author__not_in'] = array_values( array_unique( array_merge( $query_vars['author__not_in'], $author_exclude ) ) );
				} else {
					$query_vars['author__not_in'] = $author_exclude;
				}
			}

			unset( $query_vars['author'] );
		}

		// If both include and exclude are set, apply include first then remove excluded authors.
		if ( isset( $query_vars['author__in'] ) && isset( $query_vars['author__not_in'] ) ) {
			$author_in = array_diff( $query_vars['author__in'], $query_vars['author__not_in'] );

			$query_vars['author__in'] = empty( $author_in ) ? [ 0 ] : array_values( $author_in );

			unset( $query_vars['author__not_in'] );
		}

		return $query_vars;
	}

	/**
	 * Normalize scalar include constraints before the array-based merge.
	 *
	 * @param array $query_vars Query variables.
	 * @return array
	 *
	 * @since 2.4.3
	 */
	private static function normalize_include_query_vars( $query_vars ) {
		foreach ( [ 'post__in', 'author__in', 'include' ] as $key ) {
			if ( ! isset( $query_vars[ $key ] ) || is_array( $query_vars[ $key ] ) ) {
				continue;
			}

			// Current-post-author queries use a scalar; integrations can supply ID lists.
			$ids                = preg_split( '/[\s,]+/', trim( (string) $query_vars[ $key ] ), -1, PREG_SPLIT_NO_EMPTY );
			$query_vars[ $key ] = array_values( array_unique( array_map( 'absint', $ids ) ) );
		}

		return $query_vars;
	}

	/**
	 * Remove empty author defaults added to WP_Query query variables.
	 *
	 * Third-party providers can restore a complete query_vars array. These defaults must be removed
	 * before author constraints are merged, or an empty include can invalidate a real author filter.
	 *
	 * #86cate6nx
	 *
	 * @param array $query_vars Query variables.
	 * @return array
	 *
	 * @since 2.4
	 */
	private static function remove_empty_author_query_vars( $query_vars ) {
		$empty_defaults = [
			'author'         => '',
			'author__in'     => [],
			'author__not_in' => [],
		];

		foreach ( $empty_defaults as $key => $empty_default ) {
			if ( isset( $query_vars[ $key ] ) && $query_vars[ $key ] === $empty_default ) {
				unset( $query_vars[ $key ] );
			}
		}

		return $query_vars;
	}

	/**
	 * Special case for merging 'tax_query' and 'meta_query' vars
	 *
	 * Only merge if the 'taxonomy' or 'key' are identical.
	 *
	 * @since 1.9.6
	 */
	public static function merge_tax_or_meta_query_vars( $original_tax_query, $merging_tax_query, $type = 'tax' ) {
		// Handle relation
		$original_relation = $original_tax_query['relation'] ?? false;
		$merging_relation  = $merging_tax_query['relation'] ?? false;

		// Remove relation from both arrays
		unset( $original_tax_query['relation'] );
		unset( $merging_tax_query['relation'] );

		$original_tax_query = array_values( $original_tax_query );
		$merging_tax_query  = array_values( $merging_tax_query );

		// Merge tax_query or meta_query vars
		foreach ( $merging_tax_query as $merging_tax_query_item ) {
			$found = false;

			foreach ( $original_tax_query as &$original_tax_query_item ) { // Use reference to modify original array
				if ( $type === 'meta' ) {
					$ori_key     = $original_tax_query_item['key'] ?? '';
					$mer_key     = $merging_tax_query_item['key'] ?? '';
					$ori_compare = $original_tax_query_item['compare'] ?? '=';
					$mer_compare = $merging_tax_query_item['compare'] ?? '=';

					// Skip if key is empty, probably both are individual set of meta query, not intended to merge (@since 1.11.1)
					if ( $ori_key === '' || $mer_key === '' ) {
						continue;
					}

					/**
					 * Meta merge logic
					 *
					 * Only merge if the 'key' is identical && 'compare' is identical.
					 *
					 * @since 1.9.8
					 */
					if (
						// Check if key is same
						( $ori_key === $mer_key ) &&
						// Check if compare is same
						( $ori_compare === $mer_compare )
					) {
						$found = true;

						// Merge the rest of the properties
						$original_tax_query_item = self::merge_query_vars( $original_tax_query_item, $merging_tax_query_item );
					}
				}

				elseif ( $type === 'tax' ) {
					$ori_taxonmy  = $original_tax_query_item['taxonomy'] ?? '';
					$mer_taxonmy  = $merging_tax_query_item['taxonomy'] ?? '';
					$ori_field    = $original_tax_query_item['field'] ?? 'term_id';
					$mer_field    = $merging_tax_query_item['field'] ?? 'term_id';
					$ori_operator = $original_tax_query_item['operator'] ?? 'IN';
					$mer_operator = $merging_tax_query_item['operator'] ?? 'IN';
					$no_merge     = isset( $original_tax_query_item['brx_no_merge'] ) && $original_tax_query_item['brx_no_merge'];

					// Skip if taxonomy is empty, not intended to merge (@since 1.11.1.1) OR if no_merge is set (@since 1.12)
					if ( $ori_taxonmy === '' || $mer_taxonmy === '' || $no_merge ) {
						continue;
					}

					// Taxonomy merge logic
					if (
						// Check if taxonomy is same
						( $ori_taxonmy === $mer_taxonmy ) &&
						// Check if field is same
						( $ori_field === $mer_field ) &&
						// Check if operator is same
						( $ori_operator === $mer_operator )
					) {
						$found = true;

						// Convert terms to array if it's not already
						if ( isset( $original_tax_query_item['terms'] ) && ! is_array( $original_tax_query_item['terms'] ) ) {
							$original_tax_query_item['terms'] = [ $original_tax_query_item['terms'] ];
						}
						if ( isset( $merging_tax_query_item['terms'] ) && ! is_array( $merging_tax_query_item['terms'] ) ) {
							$merging_tax_query_item['terms'] = [ $merging_tax_query_item['terms'] ];
						}

						// Merge terms if they exist in both original and merging items
						if ( isset( $original_tax_query_item['terms'] ) && isset( $merging_tax_query_item['terms'] ) ) {
							$original_tax_query_item['terms'] = array_merge( $original_tax_query_item['terms'], $merging_tax_query_item['terms'] );
						} else {
							// If one of the items doesn't have terms, just copy the terms from the merging item
							$original_tax_query_item['terms'] = isset( $merging_tax_query_item['terms'] ) ? $merging_tax_query_item['terms'] : $original_tax_query_item['terms'];
						}

						// Ensure unique & no empty terms
						$original_tax_query_item['terms'] = array_unique( $original_tax_query_item['terms'] );
						$original_tax_query_item['terms'] = array_filter( $original_tax_query_item['terms'] );

						// Remove the operator if it's already set in the original item
						if ( isset( $merging_tax_query_item['operator'] ) && isset( $original_tax_query_item['operator'] ) ) {
							unset( $merging_tax_query_item['operator'] );
						}

						// Remove the terms as we've already merged them
						unset( $merging_tax_query_item['terms'] );

						// Merge the rest of the properties
						$original_tax_query_item = self::merge_query_vars( $original_tax_query_item, $merging_tax_query_item );
					}
				}
			}

			if ( ! $found ) {
				$original_tax_query[] = $merging_tax_query_item;
			}
		}

		// Restore relation
		if ( $original_relation ) {
			$original_tax_query['relation'] = $original_relation;
		}

		// Set merging_relation as priority
		if ( $merging_relation ) {
			$original_tax_query['relation'] = $merging_relation;
		}

		return $original_tax_query;
	}

	/**
	 * Merging filter's orderby vars to the original orderby vars
	 *
	 * Filter's orderby vars as priority.
	 *
	 * @since 1.11.1
	 */
	public static function merge_query_filter_orderby( $original_orderby, $merging_orderby ) {
		if ( ! is_array( $original_orderby ) ) {
			$original_orderby = [ $original_orderby ];
		}

		if ( ! is_array( $merging_orderby ) ) {
			$merging_orderby = [ $merging_orderby ];
		}

		// Set merging_orderby as priority
		$new_orderby = $merging_orderby;

		$merging_orderby_keys = array_keys( $merging_orderby );

		/**
		 * Merge with original_orderby
		 * If the original key exists in the merging_orderby, skip it
		 * Otherwise, add it to the new_orderby array
		 */
		foreach ( $original_orderby as $original_key => $original_value ) {
			if ( ! in_array( $original_key, $merging_orderby_keys ) ) {
				$new_orderby[ $original_key ] = $original_value;
			}
		}

		return $new_orderby;
	}

	/**
	 * Keep visitor-selected filters within the server's post type and status scope.
	 *
	 * @param array $query_vars Query variables after visitor filters.
	 * @param array $authorized_vars Query variables before visitor filters.
	 * @return array
	 * @since 2.4.4
	 */
	public static function constrain_frontend_post_scope( array $query_vars, array $authorized_vars ) {
		foreach ( [ 'post_type', 'post_status' ] as $key ) {
			$original = $authorized_vars[ $key ] ?? null;
			$selected = $query_vars[ $key ] ?? null;
			if ( $selected === $original ) {
				continue;
			}
			$allowed      = self::frontend_post_scope_values( $key, $original, $authorized_vars );
			$requested    = self::frontend_post_scope_values( $key, $selected, $authorized_vars );
			$intersection = array_values( array_intersect( $requested, $allowed ) );
			if ( ! $intersection ) {
				// WordPress can ignore unknown statuses and gives singular selectors priority
				// over post__in. Use its impossible include sentinel without those selectors.
				unset( $query_vars['p'], $query_vars['page_id'], $query_vars['attachment_id'], $query_vars['name'], $query_vars['pagename'], $query_vars['attachment'] );
				$query_vars['post__in'] = [ 0 ];
				$query_vars[ $key ]     = $allowed ? $allowed : [ $key === 'post_type' ? 'post' : 'publish' ];
			} else {
				$query_vars[ $key ] = is_array( $selected ) || count( $intersection ) > 1 ? $intersection : $intersection[0];
			}
		}
		return $query_vars;
	}

	/**
	 * Expand WordPress's default and wildcard scope without treating unknown names as unrestricted.
	 *
	 * @param string $key Post type or status query key.
	 * @param mixed  $value Explicit selection, default or wildcard.
	 * @param array  $query_vars Trusted query context for WordPress defaults.
	 * @return array
	 * @since 2.4.4
	 */
	private static function frontend_post_scope_values( $key, $value, array $query_vars ) {
		if ( $key === 'post_type' ) {
			if ( empty( $value ) ) {
				$value = ! empty( $query_vars['attachment_id'] ) || ! empty( $query_vars['attachment'] ) ? 'attachment' : 'post';
				if ( ! empty( $query_vars['page_id'] ) || ! empty( $query_vars['pagename'] ) ) {
					$value = 'page';
				}
				$taxonomy_query = new \WP_Tax_Query( $query_vars['tax_query'] ?? [] );
				$taxonomies     = array_keys( $taxonomy_query->queried_terms );
				if ( ! empty( $query_vars['taxonomy'] ) ) {
					$taxonomies[] = $query_vars['taxonomy'];
				}
				$is_singular = ! empty( $query_vars['p'] ) || ! empty( $query_vars['name'] ) || ! empty( $query_vars['page_id'] ) || ! empty( $query_vars['pagename'] ) || ! empty( $query_vars['attachment_id'] ) || ! empty( $query_vars['attachment'] );
				$is_search   = ! $is_singular && ! isset( $query_vars['post_type'] ) && isset( $query_vars['s'] );
				if ( $is_search || array_diff( $taxonomies, [ 'category', 'post_tag' ] ) ) {
					$value = 'any';
				}
			}
			$registered = array_values( get_post_types() );
			$values     = $value === 'any' ? array_values( get_post_types( [ 'exclude_from_search' => false ] ) ) : (array) $value;
		} else {
			$registered = array_values( get_post_stati() );
			$values     = empty( $value ) ? [ 'publish' ] : ( is_string( $value ) ? array_map( 'trim', explode( ',', $value ) ) : (array) $value );
			if ( in_array( 'any', $values, true ) ) {
				$values = array_merge( $values, array_diff( $registered, get_post_stati( [ 'exclude_from_search' => true ] ) ) );
			}
		}
		$values = array_filter( $values, 'is_string' );
		return array_values( array_unique( array_intersect( $values, $registered ) ) );
	}

	/**
	 * Resolve the host query identity independently of retained template and loop data.
	 *
	 * Frontend localization and AJAX entry points capture this context before element
	 * rendering can temporarily replace the main query for populated template previews.
	 *
	 * @return array Context keys: id (post/archive object ID) and type (post, term or user).
	 * @since 2.4.5
	 */
	public static function get_frontend_query_context() {
		if ( self::$frontend_query_context !== null ) {
			return self::$frontend_query_context;
		}

		$object = get_queried_object();

		if ( $object instanceof \WP_Term ) {
			return [
				'id'   => $object->term_id,
				'type' => 'term',
			];
		}

		if ( $object instanceof \WP_User ) {
			return [
				'id'   => $object->ID,
				'type' => 'user',
			];
		}

		return [
			'id'   => Database::$page_data['preview_or_post_id'] ?? get_the_ID(),
			'type' => 'post',
		];
	}

	/**
	 * Authenticate rendered query state without exposing the signing key to visitors.
	 *
	 * @param array  $query_vars Query variables to carry between frontend requests.
	 * @param string $element_id Full query element ID, including its component instance.
	 * @param string $object_type Query object type.
	 * @param int    $post_id Post or queried archive object ID used by the frontend request.
	 * @param array  $query_settings Resolved saved query settings.
	 * @param string $context_type Page context type, independent of the query object type.
	 * @return array
	 * @since 2.4.4
	 * @since 2.4.5 Binds archive snapshots to their host object ID and context type.
	 */
	public static function sign_frontend_query_vars( array $query_vars, $element_id, $object_type, $post_id, array $query_settings = [], $context_type = 'post' ) {
		unset( $query_vars['_bricks_query_signature'] );
		$query_vars['_bricks_query_signature'] = self::frontend_query_signature( $query_vars, $element_id, $object_type, $post_id, $query_settings, $context_type );
		return $query_vars;
	}

	/**
	 * Bind a query snapshot to its page, element, object type and visitor.
	 *
	 * @param array  $query_vars Query variables without the transport signature.
	 * @param string $element_id Full query element ID.
	 * @param string $object_type Query object type.
	 * @param int    $post_id Post or queried archive object ID.
	 * @param array  $query_settings Current saved query settings.
	 * @param string $context_type Page context type.
	 * @return string
	 * @since 2.4.4
	 * @since 2.4.5 Separates archive types while retaining the existing post signature format.
	 */
	private static function frontend_query_signature( array $query_vars, $element_id, $object_type, $post_id, array $query_settings, $context_type ) {
		$capabilities = wp_get_current_user()->allcaps;
		ksort( $capabilities );
		if ( ! empty( $query_settings['id'] ) ) {
			$query_settings = Helpers::maybe_get_global_query_settings( $query_settings );
		}
		// Query constructors store the object type separately from their saved settings.
		unset( $query_settings['objectType'] );
		// Sign the wire representation: JSON turns objects into maps and integral floats
		// into integers, while JavaScript may reorder integer-shaped object keys.
		$encoded = wp_json_encode( [ $query_vars, $query_settings ] );
		if ( ! is_string( $encoded ) ) {
			return '';
		}
		$normalized = json_decode( $encoded, true );
		if ( ! is_array( $normalized ) || json_last_error() !== JSON_ERROR_NONE ) {
			return '';
		}
		// Keep cached post-page signatures valid; archive types distinguish overlapping term/user IDs.
		$context = $context_type === 'post' ? (int) $post_id : [ $context_type, (int) $post_id ];
		$payload = wp_json_encode( [ get_current_blog_id(), (string) $element_id, (string) $object_type, $context, get_current_user_id(), $capabilities, self::normalize_frontend_query_key_order( $normalized ) ] );
		return is_string( $payload ) ? hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ) : '';
	}

	/**
	 * Match JavaScript's integer-key ordering without changing named clause order.
	 *
	 * @param array $value JSON-decoded query state.
	 * @return array
	 * @since 2.4.4
	 */
	private static function normalize_frontend_query_key_order( array $value ) {
		$indexed = [];
		$named   = [];
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$item = self::normalize_frontend_query_key_order( $item );
			}
			// ECMAScript enumerates array-index object keys before other string keys.
			if ( is_int( $key ) && $key >= 0 && $key < 4294967295 ) {
				$indexed[ $key ] = $item;
			} else {
				$named[ $key ] = $item;
			}
		}
		ksort( $indexed, SORT_NUMERIC );
		return $indexed + $named;
	}

	/**
	 * Restore authenticated query vars from the frontend (dynamic data parsed).
	 *
	 * Handle different cases when the original query vars should be restored from the populated query vars
	 *
	 * Previously, the logic maintained in api.php
	 *
	 * @param mixed  $original_query_vars Signed frontend snapshot.
	 * @param array  $populated_query_vars Fresh server-generated query variables.
	 * @param string $query_object_type Query object type.
	 * @param string $element_id Full query element ID.
	 * @param int    $post_id Validated post or queried archive object ID.
	 * @param array  $query_settings Current saved query settings.
	 * @param string $context_type Validated page context type.
	 * @return array
	 * @since 1.12
	 * @since 2.4.5 Verifies archive identity independently of the normalized post lookup ID.
	 */
	public static function restore_original_query_vars_from_frontend( $original_query_vars, $populated_query_vars, $query_object_type, $element_id = '', $post_id = 0, array $query_settings = [], $context_type = 'post' ) {
		$signature = is_array( $original_query_vars ) ? ( $original_query_vars['_bricks_query_signature'] ?? '' ) : '';
		if ( is_array( $original_query_vars ) ) {
			unset( $original_query_vars['_bricks_query_signature'] );
		}

		// Old cached pages and custom clients may omit the token. Rebuild from saved settings;
		// a public nonce cannot authorize client-supplied publication or query constraints.
		if (
			! is_string( $signature ) || $signature === '' || $element_id === '' ||
			! hash_equals( self::frontend_query_signature( $original_query_vars, $element_id, $query_object_type, $post_id, $query_settings, $context_type ), $signature )
		) {
			return $populated_query_vars;
		}

		if ( $query_object_type === 'post' ) {
			foreach ( [ 'post_type', 'post_status' ] as $key ) {
				// REST may lack the original archive context. Only compare explicit current
				// constraints; missing snapshot keys are filled from the server below.
				if ( ! array_key_exists( $key, $populated_query_vars ) || ! isset( $original_query_vars[ $key ] ) || $original_query_vars[ $key ] === $populated_query_vars[ $key ] ) {
					continue;
				}

				$original_scope = self::frontend_post_scope_values( $key, $original_query_vars[ $key ], $original_query_vars );
				$current_scope  = self::frontend_post_scope_values( $key, $populated_query_vars[ $key ], $populated_query_vars );

				// A hook can revoke visibility without changing saved settings or capabilities.
				if ( ! $original_scope || array_diff( $original_scope, $current_scope ) ) {
					return $populated_query_vars;
				}
			}
		}

		// Always unset original query vars offset value (@since 1.12)
		if ( isset( $original_query_vars['offset'] ) && in_array( $query_object_type, [ 'term', 'user' ], true ) ) {
			unset( $original_query_vars['offset'] );
		}

		// STEP: page number should be removed from original query vars because it was set when user lands on /page/n (#86c0vzgr0)
		if ( isset( $original_query_vars['paged'] ) ) {
			unset( $original_query_vars['paged'] );
		}

		// STEP: Original query vars should not merge with filters to remain the 'OR' relation
		if ( isset( $original_query_vars['meta_query'] ) ) {
			$original_query_vars['meta_query'] = [ $original_query_vars['meta_query'] ];
		}

		// STEP: Special handle user query fields (#86c7hjhky; @since 2.2)
		if ( $query_object_type === 'user' ) {
			// Unset role__in from frontend.
			unset( $original_query_vars['role__in'] );
		}

		// Original query vars as priority, check if any key found in the populated query vars but not in the original query vars
		foreach ( $populated_query_vars as $key => $value ) {
			// Exclude 'tax_query' and 'meta_query' as they are handled separately
			if ( in_array( $key, [ 'tax_query', 'meta_query' ] ) ) {
				continue;
			}

			if ( ! isset( $original_query_vars[ $key ] ) ) {
				$original_query_vars[ $key ] = $value;
			}
		}

		return $original_query_vars;
	}

	/**
	 * Run query API
	 *
	 * @since 2.1
	 */
	public function run_query_api_query() {
		$settings = $this->settings['query'] ?? [];

		// Create API instance
		$api = new Query_API( $settings, $this->element_id );

		 // Add pagination parameter if it exists
		if ( isset( $this->query_vars['paged'] ) && is_numeric( $this->query_vars['paged'] ) ) {
			$api->set_pagination( absint( $this->query_vars['paged'] ) );
		}

		// Make the request
		$response = $api->request();

		// For builder.php to collect the response data and show in the popup
		do_action( 'bricks/query/query_api_response', $response, $this->element_id );

		if ( ! $response ) {
			return [
				'results'     => [],
				'total_pages' => 1,
			];
		}

		return [
			'results'     => $api->get_extracted_data() ?? [],
			'total_pages' => $api->get_total_pages() ?? 1,
		];
	}

	/**
	 * Run array query
	 *
	 * @since 2.2
	 */
	public function run_array_query() {
		$post_id      = Database::$page_data['preview_or_post_id'];
		$parsed_array = Query_Array::get_array_data( $this->query_vars, $post_id, $this );
		$result       = is_array( $parsed_array ) ? $parsed_array : [];
		$total_items  = count( $result );
		$start        = 0;
		$end          = 0;
		$current_page = ! empty( $this->query_vars['paged'] ) ? (int) $this->query_vars['paged'] : 1;

		// Calculate total pages
		$items_per_page = ! empty( $this->query_vars['items_per_page'] ) ? (int) $this->query_vars['items_per_page'] : 0;

		if ( $items_per_page > 0 && $total_items > 0 ) {
			$total_pages = ceil( $total_items / $items_per_page );

			// Slice the result array based on the current page
			$offset = ( $current_page - 1 ) * $items_per_page;
			$result = array_slice( $result, $offset, $items_per_page );
		} else {
			$total_pages = 1;
		}

		// Calculate the starting and ending position
		if ( $total_items > 0 ) {
			// Maybe user set 0 to items_per_page
			if ( $items_per_page === 0 ) {
				$start = 1;
				$end   = $total_items;
			} else {
				$start = ( ( $current_page - 1 ) * $items_per_page ) + 1;
				$end   = min( $start + $items_per_page - 1, $total_items );
			}
		}

		// Avoid showing in frontend
		unset( $this->query_vars['arrayEditor'] );
		unset( $this->query_vars['array_conditions'] );

		return [
			'results'     => $result,
			'total_items' => $total_items,
			'total_pages' => $total_pages,
			'start'       => $start,
			'end'         => $end
		];
	}
}

<?php
/**
 * Query Filter abilities
 *
 * List/read/write Bricks Query Filter elements (filter-checkbox,
 * filter-radio, filter-select, filter-range, filter-search,
 * filter-datepicker, filter-submit, filter-active-filters). Each filter
 * binds to a target Query Loop element via `settings.filterQueryId`. A
 * filter without `filterQueryId` never fires on the frontend.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Filters {

	/**
	 * Known filter element names - sourced from
	 * includes/elements/filter-*.php. Filters inherit from Filter_Element
	 * (filter-base.php) which contributes `filterQueryId`.
	 *
	 * @since 2.4
	 */
	const FILTER_ELEMENT_NAMES = [
		'filter-checkbox',
		'filter-radio',
		'filter-select',
		'filter-range',
		'filter-search',
		'filter-datepicker',
		'filter-submit',
		'filter-active-filters',
	];

	/**
	 * Known filter source values - sourced from
	 * includes/elements/filter-base.php:1170-1182 (filterSource control).
	 *
	 * @since 2.4
	 */
	const FILTER_SOURCES = [ 'taxonomy', 'wpField', 'customField' ];

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: list filters site-wide (same gate as list-templates).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	/**
	 * Permission: read a filter element on a specific post.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		return Elements::read_post_permission( $input );
	}

	/**
	 * Permission: edit a filter element (reuses element-level gating).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function edit_permission( $input ) {
		return Elements::edit_element_permission( $input );
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve a filter element in a post.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id    Resolved post ID.
	 * @param string $element_id Filter element ID.
	 * @return array|\WP_Error { index, element, elements }
	 */
	private static function find_filter_element( $post_id, $element_id ) {
		Manager::flush_post_cache( $post_id );
		$area     = Elements::get_save_area_for_post( $post_id );
		$elements = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( 'element', $element_id );
		}

		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) !== $element_id ) {
				continue;
			}

			$name = $element['name'] ?? '';

			if ( ! in_array( $name, self::FILTER_ELEMENT_NAMES, true ) ) {
				return Error::invalid_param( 'elementId', 'a query-filter element id (one of: ' . implode( ', ', self::FILTER_ELEMENT_NAMES ) . ')', $name );
			}

			return [
				'index'    => $index,
				'element'  => $element,
				'elements' => $elements,
				'area'     => $area,
			];
		}

		return Error::not_found( 'element', $element_id );
	}

	/**
	 * Validate a target query-loop element ID inside the current element tree.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements Element tree.
	 * @param string $element_id Target query-loop element ID.
	 * @return true|\WP_Error
	 */
	private static function validate_query_loop_target( array $elements, string $element_id ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || (string) ( $element['id'] ?? '' ) !== $element_id ) {
				continue;
			}

			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			if ( ! empty( $settings['hasLoop'] ) || ( $element['name'] ?? '' ) === 'posts' ) {
				return true;
			}

			return Error::invalid_param( 'filterQueryId', 'a query-loop element id', $element_id );
		}

		return Error::not_found( 'query-loop element', $element_id );
	}

	// ==================================================================
	// LIST QUERY FILTERS
	// ==================================================================

	/**
	 * Input schema for list-query-filters
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_query_filters_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'postType' => [
						'type'        => 'string',
						'description' => __( 'Narrow the scan to a single post type (post, page, bricks_template, product, etc.). Default scans all Bricks-enabled post types.', 'bricks' ),
					],
					'postId'   => [
						'type'        => 'integer',
						'description' => __( 'Narrow the scan to a single post ID.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-query-filters
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_query_filters_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'        => 'array',
					'description' => __( 'Filter element summaries with postId, postTitle, elementId, elementName, filterQueryId, filterSource, label, and editUrl.', 'bricks' ),
				],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
				'hasMore' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: enumerate query-filter elements across posts.
	 *
	 * Iterates posts that have Bricks content, walks the flat element tree
	 * of each, and emits one row per filter element found. Expensive on
	 * large sites - pass `postId` or `postType` when possible.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_query_filters( $input ) {
		$post_id_filter = isset( $input['postId'] ) ? (int) $input['postId'] : 0;

		$post_types = [];
		if ( ! empty( $input['postType'] ) ) {
			$post_types[] = sanitize_key( $input['postType'] );
		} else {
			// Bricks-enabled post types + templates.
			$post_types = array_values(
				array_unique(
					array_merge(
						(array) ( \Bricks\Database::$global_settings['postTypes'] ?? [ 'post', 'page' ] ),
						[ BRICKS_DB_TEMPLATE_SLUG ]
					)
				)
			);
		}

		$data_keys = [
			\Bricks\Database::get_bricks_data_key( 'content' ),
			\Bricks\Database::get_bricks_data_key( 'header' ),
			\Bricks\Database::get_bricks_data_key( 'footer' ),
		];

		$meta_query = [ 'relation' => 'OR' ];

		foreach ( $data_keys as $data_key ) {
			$meta_query[] = [
				'key'     => $data_key,
				'compare' => 'EXISTS',
			];
		}

		$query_args = [
			'post_type'              => $post_types,
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bricks element data lives in post meta.
			'cache_results'          => false,
			'update_post_meta_cache' => false,
		];

		if ( $post_id_filter ) {
			$query_args['p'] = $post_id_filter;
		}

		$post_ids = get_posts( $query_args );
		$items    = [];

		foreach ( $post_ids as $post_id ) {
			// Per-row edit check: the coarse `edit_posts` gate on the ability
			// does not guarantee the caller can edit *this* post (e.g. author
			// without edit_others_posts). Skip rows the caller can't edit so
			// enumeration never leaks beyond their own posts.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$area     = Elements::get_save_area_for_post( $post_id );
			$elements = \Bricks\Database::get_data( $post_id, $area );

			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $element ) {
				$name = $element['name'] ?? '';

				if ( ! in_array( $name, self::FILTER_ELEMENT_NAMES, true ) ) {
					continue;
				}

				$settings = $element['settings'] ?? [];

				$items[] = [
					'postId'        => (int) $post_id,
					'postTitle'     => get_the_title( $post_id ),
					'postType'      => get_post_type( $post_id ),
					'elementId'     => $element['id'] ?? '',
					'elementName'   => $name,
					'filterQueryId' => $settings['filterQueryId'] ?? '',
					'filterSource'  => $settings['filterSource'] ?? '',
					'label'         => $settings['filterLabel'] ?? ( $settings['label'] ?? '' ),
					'editUrl'       => \Bricks\Helpers::get_builder_edit_link( $post_id ),
				];
			}
		}

		return Reference::paginate( $items, $input );
	}

	// ==================================================================
	// GET FILTER ELEMENT
	// ==================================================================

	/**
	 * Input schema for get-filter-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_filter_element_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The filter element ID.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for get-filter-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_filter_element_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'     => [ 'type' => 'string' ],
				'elementName'   => [ 'type' => 'string' ],
				'filterQueryId' => [ 'type' => 'string' ],
				'filterSource'  => [ 'type' => 'string' ],
				'settings'      => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: read a single filter element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_filter_element( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$found = self::find_filter_element( $post_id, $input['elementId'] );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$settings = $found['element']['settings'] ?? [];

		return [
			'elementId'     => $found['element']['id'] ?? '',
			'elementName'   => $found['element']['name'] ?? '',
			'filterQueryId' => $settings['filterQueryId'] ?? '',
			'filterSource'  => $settings['filterSource'] ?? '',
			'settings'      => $settings,
		];
	}

	// ==================================================================
	// UPDATE FILTER ELEMENT
	// ==================================================================

	/**
	 * Input schema for update-filter-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_filter_element_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The filter element ID.', 'bricks' ),
		];
		$properties['settings']  = [
			'type'        => 'object',
			'description' => __( 'Partial-merge settings object. Common keys: filterSource (taxonomy|wpField|customField), filterTaxonomy, filterPostMeta, filterWpField, filterLabel, filterStyle. See skills/query-filters for full per-element-type keys.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'settings' ],
		];
	}

	/**
	 * Output schema for update-filter-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_filter_element_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'  => [ 'type' => 'string' ],
				'settings'   => [ 'type' => 'object' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: partial-merge a filter element's settings.
	 *
	 * Validates filterSource if provided but does NOT validate per-element
	 * keys (filterTaxonomy/filterPostMeta/etc.) - those depend on element
	 * type and site configuration. The builder's own control UI is the
	 * source of truth for the full per-element shape; we delegate shape
	 * validity to the caller.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_filter_element( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id = $input['elementId'];
		$settings   = $input['settings'];

		if ( ! is_array( $settings ) ) {
			return Error::invalid_param( 'settings', 'an object of filter settings', $settings );
		}

		if ( isset( $settings['filterSource'] ) && ! in_array( $settings['filterSource'], self::FILTER_SOURCES, true ) ) {
			return Error::invalid_param( 'settings.filterSource', 'one of: ' . implode( ', ', self::FILTER_SOURCES ), $settings['filterSource'] );
		}

		$found = self::find_filter_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];
		$existing = $elements[ $index ]['settings'] ?? [];

		$elements[ $index ]['settings'] = Elements::deep_merge( $existing, $settings );

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'  => $element_id,
			'settings'   => $elements[ $index ]['settings'],
			'revisionId' => $result['revisionId'],
		];
	}

	// ==================================================================
	// SET FILTER TARGET QUERY
	// ==================================================================

	/**
	 * Input schema for set-filter-target-query
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_filter_target_query_schema() {
		$properties                  = Elements::post_identifier_properties();
		$properties['elementId']     = [
			'type'        => 'string',
			'description' => __( 'The filter element ID.', 'bricks' ),
		];
		$properties['filterQueryId'] = [
			'type'        => 'string',
			'description' => __( 'Element ID of the target query-loop element. Pass an empty string to unbind.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'filterQueryId' ],
		];
	}

	/**
	 * Output schema for set-filter-target-query
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_filter_target_query_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'     => [ 'type' => 'string' ],
				'filterQueryId' => [ 'type' => 'string' ],
				'revisionId'    => [
					'type' => [ 'integer', 'null' ],
				],
			],
		];
	}

	/**
	 * Callback: bind or unbind a filter element to a query-loop element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_filter_target_query( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id      = $input['elementId'];
		$filter_query_id = (string) $input['filterQueryId'];

		$found = self::find_filter_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];

		if ( $filter_query_id !== '' ) {
			$target_check = self::validate_query_loop_target( $elements, $filter_query_id );

			if ( is_wp_error( $target_check ) ) {
				return $target_check;
			}
		}

		$elements[ $index ]['settings']                  = $elements[ $index ]['settings'] ?? [];
		$elements[ $index ]['settings']['filterQueryId'] = $filter_query_id;

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'     => $element_id,
			'filterQueryId' => $filter_query_id,
			'revisionId'    => $result['revisionId'],
		];
	}

	// ==================================================================
	// REINDEX FILTERS
	// ==================================================================

	/**
	 * Permission for reindex-filters: must be able to manage_options (drops
	 * and recreates index tables).
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	public static function reindex_filters_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Input schema for reindex-filters
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function reindex_filters_schema() {
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for reindex-filters
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function reindex_filters_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'reindexed' => [
					'type'        => 'boolean',
					'description' => __( 'True when the index table was dropped/recreated and index jobs were queued or started.', 'bricks' ),
				],
				'pending'   => [
					'type'        => 'integer',
					'description' => __( 'Number of index jobs still queued after the ability triggers one bounded indexing pass.', 'bricks' ),
				],
				'progress'  => [
					'type'        => 'string',
					'description' => __( 'Human-readable indexing progress after the ability triggers one bounded indexing pass.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: drop/recreate the query-filter index table and queue jobs.
	 *
	 * The Bricks query-filters subsystem maintains an index table that is
	 * normally kept in sync incrementally. This mirrors the Bricks > Settings
	 * > Query filters > Regenerate filter index button. Use after restoring
	 * posts from a backup, after a bulk import, or when filter elements stop
	 * matching expected results.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input (unused).
	 * @return array|\WP_Error
	 */
	public static function reindex_filters( $input ) {
		Manager::ensure_post_admin_loaded();

		if ( ! class_exists( '\\Bricks\\Query_Filters' ) ) {
			return Error::internal_error(
				'reindex-filters',
				'Bricks query filters subsystem is not loaded. Confirm the Bricks plugin is active.',
				[]
			);
		}

		$result = \Bricks\Query_Filters::get_instance()->reindex();

		if ( is_array( $result ) && isset( $result['error'] ) ) {
			return Error::internal_error( 'reindex-filters', $result['error'], $result );
		}

		if ( ! class_exists( '\\Bricks\\Query_Filters_Indexer' ) || ! method_exists( '\\Bricks\\Query_Filters_Indexer', 'get_instance' ) ) {
			return Error::internal_error(
				'reindex-filters',
				'Bricks query filter indexer is not loaded.',
				[]
			);
		}

		$indexer = \Bricks\Query_Filters_Indexer::get_instance();

		// MCP calls usually have no browser auth cookies, so the admin-ajax background worker cannot authenticate.
		if ( count( $indexer->get_jobs() ) > 0 ) {
			$indexer->continue_index_jobs();
		}

		return [
			'reindexed' => true,
			'pending'   => count( $indexer->get_jobs() ),
			'progress'  => $indexer->get_overall_progress(),
		];
	}
}

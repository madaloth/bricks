<?php
/**
 * Global Query abilities
 *
 * CRUD for reusable queries stored in `BRICKS_DB_GLOBAL_QUERIES`. A global
 * query is a saved set of query-loop parameters an element or another query
 * can reference by ID (avoiding duplicated query configs across templates).
 *
 * Storage shape:
 *   [
 *     [
 *       'id'       => 'qid_1',
 *       'name'     => 'Recent posts',
 *       'category' => 'cat_1',     // optional
 *       'settings' => [ ... ],     // the query object (same shape as inline
 *                                   // element `query` setting)
 *     ],
 *     ...
 *   ]
 *
 * Categories are stored separately in `BRICKS_DB_GLOBAL_QUERIES_CATEGORIES`
 * with shape:
 *   [ [ 'id' => 'cat_1', 'name' => 'Blog queries' ], ... ]
 *
 * Query writes go through `Database::update_global_queries()` and category
 * writes use the matching main-site option when the multisite constants ask
 * Bricks to share global queries.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Queries {
	// ==================================================================
	// Input / output schemas
	// ==================================================================

	/**
	 * Input schema for list-global-queries
	 *
	 * @since 2.4
	 */
	public static function list_global_queries_schema() {
		$schema = [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'search'     => [
						'type'        => 'string',
						'description' => __( 'Case-insensitive substring filter on query label.', 'bricks' ),
					],
					'categoryId' => [
						'type'        => 'string',
						'description' => __( 'Filter by category ID.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];

		return $schema;
	}

	/**
	 * Output schema for list-global-queries
	 *
	 * @since 2.4
	 */
	public static function list_global_queries_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'      => [ 'type' => 'array' ],
				'categories' => [ 'type' => 'array' ],
				'total'      => [ 'type' => 'integer' ],
				'page'       => [ 'type' => 'integer' ],
				'perPage'    => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Input schema for get-global-query
	 *
	 * @since 2.4
	 */
	public static function get_global_query_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'queryId' ],
			'properties' => [
				'queryId' => [
					'type'        => 'string',
					'description' => __( 'ID of the global query to read.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for get-global-query
	 *
	 * @since 2.4
	 */
	public static function get_global_query_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Input schema for create-global-query
	 *
	 * @since 2.4
	 */
	public static function create_global_query_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'query' ],
			'properties' => [
				'label'    => [
					'type'        => 'string',
					'description' => __( 'Human-readable label. Must be unique across global queries. (Alias: `name` accepted for back-compat with stored shape.)', 'bricks' ),
				],
				'name'     => [
					'type'        => 'string',
					'description' => __( 'Alias for `label` (back-compat with the underlying storage key). Provide either `label` or `name`.', 'bricks' ),
				],
				'category' => [
					'type'        => 'string',
					'description' => __( 'Optional category ID (call `list-global-queries` to see existing categories, or create one first via `create-global-query-category`).', 'bricks' ),
				],
				'query'    => [
					'type'        => 'object',
					'description' => __( 'The query-loop settings object. Use the same shape you would store inline on an element, for example `{ objectType: "post", postType: ["post"], posts_per_page: 10, orderby: "date", order: "DESC" }`.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for create-global-query
	 *
	 * @since 2.4
	 */
	public static function create_global_query_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Input schema for update-global-query
	 *
	 * @since 2.4
	 */
	public static function update_global_query_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'queryId' ],
			'properties' => [
				'queryId'  => [
					'type'        => 'string',
					'description' => __( 'ID of the query to update.', 'bricks' ),
				],
				'label'    => [
					'type'        => 'string',
					'description' => __( 'New label. Must remain unique. (Alias: `name` accepted for back-compat.)', 'bricks' ),
				],
				'name'     => [
					'type'        => 'string',
					'description' => __( 'Alias for `label` (back-compat with the underlying storage key).', 'bricks' ),
				],
				'category' => [
					'type'        => 'string',
					'description' => __( 'New category ID, or empty string to clear.', 'bricks' ),
				],
				'query'    => [
					'type'        => 'object',
					'description' => __( 'Replacement query settings. Always a full replacement; no partial merge happens inside `query`.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for update-global-query
	 *
	 * @since 2.4
	 */
	public static function update_global_query_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Input schema for delete-global-query
	 *
	 * @since 2.4
	 */
	public static function delete_global_query_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'queryId' ],
			'properties' => [
				'queryId' => [
					'type'        => 'string',
					'description' => __( 'ID of the query to delete.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for delete-global-query
	 *
	 * @since 2.4
	 */
	public static function delete_global_query_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deletedId' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Input schema for create-global-query-category
	 *
	 * @since 2.4
	 */
	public static function create_category_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'name' ],
			'properties' => [
				'name' => [
					'type'        => 'string',
					'description' => __( 'Category display name. Must be unique.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for create-global-query-category
	 *
	 * @since 2.4
	 */
	public static function create_category_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'category' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Input schema for delete-global-query-category
	 *
	 * @since 2.4
	 */
	public static function delete_category_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'categoryId' ],
			'properties' => [
				'categoryId' => [
					'type'        => 'string',
					'description' => __( 'ID of the category to delete. By default the call is rejected if any queries still reference this category; pass force:true to detach them (they become uncategorized).', 'bricks' ),
				],
				'force'      => [
					'type'        => 'boolean',
					'description' => __( 'When true, detach member queries (category cleared) instead of rejecting the delete. Defaults to false.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for delete-global-query-category
	 *
	 * @since 2.4
	 */
	public static function delete_category_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deletedId' => [ 'type' => 'string' ],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Permission: read - requires the Bricks `access_query_manager` builder
	 * permission. Query configs can include sensitive meta-query filters
	 * and should not leak to callers who cannot see them in the builder.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_query_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_query_manager' );
		}

		return true;
	}

	/**
	 * Permission: write - same gate as the admin UI's save-queries AJAX
	 * action (`Builder_Permissions::user_has_permission('access_query_manager')`).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	/**
	 * Reject global-query PHP editor changes through abilities.
	 *
	 * @since 2.4
	 *
	 * @param array $existing Current stored query settings.
	 * @param array $incoming Incoming replacement query settings.
	 * @return true|\WP_Error
	 */
	private static function check_query_code_sensitive_permissions( array $existing, array $incoming ) {
		if ( self::query_code_sensitive_payload( $existing ) !== self::query_code_sensitive_payload( $incoming ) && ! Elements::can_author_php() ) {
			return Error::code_sensitive_write_forbidden();
		}

		return true;
	}

	/**
	 * Extract the executable subset of a global-query settings row.
	 *
	 * @since 2.4
	 *
	 * @param array $query Query settings.
	 * @return array
	 */
	private static function query_code_sensitive_payload( array $query ): array {
		$payload = [];

		foreach ( [ 'useQueryEditor', 'queryEditor', 'signature' ] as $key ) {
			if ( ! array_key_exists( $key, $query ) ) {
				continue;
			}

			$value = $query[ $key ];
			// Native query execution checks presence rather than truthiness of this flag.
			if ( ( $value === '' || $value === null || $value === false || $value === [] ) && ! ( $key === 'useQueryEditor' && isset( $query[ $key ] ) ) ) {
				continue;
			}

			$payload[ $key ] = $value;
		}

		return $payload;
	}

	// ==================================================================
	// Execute callbacks
	// ==================================================================

	/**
	 * Execute: list-global-queries
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_global_queries( $input ) {
		Manager::flush_options_cache();

		$queries    = self::load_queries();
		$categories = self::load_categories();

		$search      = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$category_id = isset( $input['categoryId'] ) ? (string) $input['categoryId'] : '';

		$rows = [];
		foreach ( $queries as $query ) {
			$label = (string) ( $query['name'] ?? $query['label'] ?? '' );
			$cat   = (string) ( $query['category'] ?? '' );

			if ( $search !== '' && stripos( $label, $search ) === false ) {
				continue;
			}
			if ( $category_id !== '' && $cat !== $category_id ) {
				continue;
			}

			$rows[] = [
				'id'       => (string) ( $query['id'] ?? '' ),
				'label'    => $label,
				'category' => $cat,
				'summary'  => self::summarize( $query['settings'] ?? $query['query'] ?? [] ),
			];
		}

		return array_merge(
			Reference::paginate( $rows, $input ),
			[ 'categories' => array_values( $categories ) ]
		);
	}

	/**
	 * Execute: get-global-query
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_global_query( $input ) {
		Manager::flush_options_cache();

		$id = (string) ( $input['queryId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'queryId', 'non-empty string', $id );
		}

		$queries = self::load_queries();

		foreach ( $queries as $query ) {
			if ( (string) ( $query['id'] ?? '' ) === $id ) {
				return [ 'query' => self::decorate_query_row( $query ) ];
			}
		}

		return Error::not_found( 'global_query', $id );
	}

	/**
	 * Execute: create-global-query
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_global_query( $input ) {
		Manager::flush_options_cache();

		// BUG-055: accept `label` (canonical public name) or `name` (storage-key alias).
		$label_input = $input['label'] ?? ( $input['name'] ?? '' );
		$label       = sanitize_text_field( trim( (string) $label_input ) );
		$query       = $input['query'] ?? null;
		$category    = isset( $input['category'] ) ? sanitize_text_field( (string) $input['category'] ) : '';

		if ( $label === '' ) {
			return Error::invalid_param( 'label', 'non-empty string (or pass via `name` alias)', $label );
		}
		if ( ! is_array( $query ) ) {
			return Error::invalid_param( 'query', 'object with query-loop settings', $query );
		}

		$code_check = self::check_query_code_sensitive_permissions( [], $query );
		if ( is_wp_error( $code_check ) ) {
			return $code_check;
		}

		$queries = self::load_queries();

		foreach ( $queries as $existing ) {
			if ( strcasecmp( (string) ( $existing['name'] ?? $existing['label'] ?? '' ), $label ) === 0 ) {
				return Error::conflict_duplicate_name( 'global query', $label, [ 'existingId' => $existing['id'] ?? '' ] );
			}
		}

		if ( $category !== '' && ! self::category_exists( $category ) ) {
			return Error::not_found( 'global_query_category', $category );
		}

		$id  = self::generate_id( 'query' );
		$row = [
			'id'       => $id,
			'name'     => $label,
			'settings' => Elements::sign_php_settings( $query, 'queryEditor' ),
			'modified' => round( microtime( true ) * 1000 ),
			'user_id'  => get_current_user_id(),
		];
		if ( $category !== '' ) {
			$row['category'] = $category;
		}

		$queries[] = $row;
		\Bricks\Database::update_global_queries( $queries );

		return [ 'query' => self::decorate_query_row( $row ) ];
	}

	/**
	 * Execute: update-global-query
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_global_query( $input ) {
		Manager::flush_options_cache();

		$id = (string) ( $input['queryId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'queryId', 'non-empty string', $id );
		}

		$queries = self::load_queries();
		$found   = false;

		foreach ( $queries as $index => $existing ) {
			if ( (string) ( $existing['id'] ?? '' ) !== $id ) {
				continue;
			}

			// BUG-055: accept `label` or `name` alias for relabel.
			$has_label_input = array_key_exists( 'label', $input ) || array_key_exists( 'name', $input );

			if ( $has_label_input ) {
				$new_label_input = array_key_exists( 'label', $input ) ? $input['label'] : $input['name'];
				$new_label       = sanitize_text_field( trim( (string) $new_label_input ) );
				if ( $new_label === '' ) {
					return Error::invalid_param( 'label', 'non-empty string (or pass via `name` alias)', $new_label );
				}

				foreach ( $queries as $j => $other ) {
					if ( $j === $index ) {
						continue;
					}
					if ( strcasecmp( (string) ( $other['name'] ?? $other['label'] ?? '' ), $new_label ) === 0 ) {
						return Error::conflict_duplicate_name( 'global query', $new_label, [ 'existingId' => $other['id'] ?? '' ] );
					}
				}

				$existing['name'] = $new_label;
				unset( $existing['label'] );
			}

			if ( array_key_exists( 'category', $input ) ) {
				$cat = sanitize_text_field( (string) $input['category'] );
				if ( $cat === '' ) {
					unset( $existing['category'] );
				} else {
					if ( ! self::category_exists( $cat ) ) {
						return Error::not_found( 'global_query_category', $cat );
					}
					$existing['category'] = $cat;
				}
			}

			if ( array_key_exists( 'query', $input ) ) {
				if ( ! is_array( $input['query'] ) ) {
					return Error::invalid_param( 'query', 'object with query-loop settings', $input['query'] );
				}

				$query           = $input['query'];
				$stored_settings = is_array( $existing['settings'] ?? null ) ? $existing['settings'] : [];
				$code_check      = self::check_query_code_sensitive_permissions( $stored_settings, $query );
				if ( is_wp_error( $code_check ) ) {
					return $code_check;
				}

				$existing['settings'] = Elements::sign_php_settings( $query, 'queryEditor' );
				unset( $existing['query'] );
			}

			$existing['modified'] = round( microtime( true ) * 1000 );
			$existing['user_id']  = get_current_user_id();

			$queries[ $index ] = $existing;
			$found             = true;

			\Bricks\Database::update_global_queries( $queries );

			return [ 'query' => self::decorate_query_row( $existing ) ];
		}

		if ( ! $found ) {
			return Error::not_found( 'global_query', $id );
		}
	}

	/**
	 * Execute: delete-global-query
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_global_query( $input ) {
		Manager::flush_options_cache();

		$id = (string) ( $input['queryId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'queryId', 'non-empty string', $id );
		}

		$queries = self::load_queries();

		foreach ( $queries as $index => $existing ) {
			if ( (string) ( $existing['id'] ?? '' ) === $id ) {
				unset( $queries[ $index ] );
				\Bricks\Database::update_global_queries( array_values( $queries ) );
				return [ 'deletedId' => $id ];
			}
		}

		return Error::not_found( 'global_query', $id );
	}

	/**
	 * Execute: create-global-query-category
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_category( $input ) {
		Manager::flush_options_cache();

		$name = sanitize_text_field( trim( (string) ( $input['name'] ?? '' ) ) );
		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'non-empty string', $name );
		}

		$categories = self::load_categories();

		foreach ( $categories as $existing ) {
			if ( strcasecmp( (string) ( $existing['name'] ?? '' ), $name ) === 0 ) {
				return Error::conflict_duplicate_name( 'global query category', $name, [ 'existingId' => $existing['id'] ?? '' ] );
			}
		}

		$id           = self::generate_id( 'cat' );
		$row          = [
			'id'   => $id,
			'name' => $name
		];
		$categories[] = $row;

		if ( false === self::update_categories( array_values( $categories ) ) ) {
			return Error::internal_error(
				'global_query_category_write',
				'Failed to persist global query categories.',
				[ 'categoryId' => $id ]
			);
		}

		return [ 'category' => $row ];
	}

	/**
	 * Execute: delete-global-query-category
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_category( $input ) {
		Manager::flush_options_cache();

		$id = sanitize_text_field( (string) ( $input['categoryId'] ?? '' ) );
		if ( $id === '' ) {
			return Error::invalid_param( 'categoryId', 'non-empty string', $id );
		}

		$force = ! empty( $input['force'] );

		$categories = self::load_categories();
		$kept       = [];
		$found      = false;

		foreach ( $categories as $existing ) {
			if ( (string) ( $existing['id'] ?? '' ) === $id ) {
				$found = true;
				continue;
			}
			$kept[] = $existing;
		}

		if ( ! $found ) {
			return Error::not_found( 'global_query_category', $id );
		}

		// BUG-053: refuse to silently detach member queries. Caller must opt in via
		// force:true. Surfaces the dependency so AI callers don't lose category info
		// in a way that's hard to recover.
		$queries    = self::load_queries();
		$member_ids = [];

		foreach ( $queries as $query ) {
			if ( isset( $query['category'] ) && (string) $query['category'] === $id ) {
				$member_ids[] = (string) ( $query['id'] ?? '' );
			}
		}

		if ( ! empty( $member_ids ) && ! $force ) {
			return Error::conflict(
				'bricks_category_has_queries',
				[
					'message'    => sprintf(
						'Category %s still has %d quer%s. Re-call with force:true to detach them (they become uncategorized) or move them with update-global-query first.',
						$id,
						count( $member_ids ),
						count( $member_ids ) === 1 ? 'y' : 'ies'
					),
					'categoryId' => $id,
					'queryIds'   => $member_ids,
				]
			);
		}

		if ( false === self::update_categories( array_values( $kept ) ) ) {
			return Error::internal_error(
				'global_query_category_write',
				'Failed to persist global query categories.',
				[ 'categoryId' => $id ]
			);
		}

		$changed = false;
		foreach ( $queries as $index => $query ) {
			if ( isset( $query['category'] ) && (string) $query['category'] === $id ) {
				unset( $queries[ $index ]['category'] );
				$changed = true;
			}
		}
		if ( $changed ) {
			\Bricks\Database::update_global_queries( array_values( $queries ) );
		}

		return [
			'deletedId'        => $id,
			'detachedQueryIds' => $member_ids,
		];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Load global query definitions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function load_queries(): array {
		$queries = \Bricks\Database::get_global_queries();
		return is_array( $queries ) ? array_values( $queries ) : [];
	}

	private static function load_categories(): array {
		if ( is_multisite() && defined( 'BRICKS_MULTISITE_USE_MAIN_SITE_GLOBAL_QUERIES_CATEGORIES' ) && BRICKS_MULTISITE_USE_MAIN_SITE_GLOBAL_QUERIES_CATEGORIES ) {
			$categories = get_blog_option( get_main_site_id(), BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );
		} else {
			$categories = get_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, [] );
		}

		return is_array( $categories ) ? array_values( $categories ) : [];
	}

	private static function update_categories( array $categories ) {
		if ( is_multisite() && defined( 'BRICKS_MULTISITE_USE_MAIN_SITE_GLOBAL_QUERIES_CATEGORIES' ) && BRICKS_MULTISITE_USE_MAIN_SITE_GLOBAL_QUERIES_CATEGORIES ) {
			return update_blog_option( get_main_site_id(), BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, $categories );
		}

		return update_option( BRICKS_DB_GLOBAL_QUERIES_CATEGORIES, $categories );
	}

	private static function category_exists( string $id ): bool {
		foreach ( self::load_categories() as $category ) {
			if ( (string) ( $category['id'] ?? '' ) === $id ) {
				return true;
			}
		}
		return false;
	}

	private static function generate_id( string $prefix ): string {
		return $prefix . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * Decorate a stored global-query row with the canonical `label` public field
	 * while keeping `name` for back-compat. BUG-055: the storage key is `name`
	 * (used by the builder JS reading the option directly) but the public ability
	 * surface uses `label` consistently across schemas.
	 *
	 * @since 2.4
	 *
	 * @param array $row Stored global-query row.
	 * @return array
	 */
	private static function decorate_query_row( array $row ): array {
		if ( ! isset( $row['label'] ) && isset( $row['name'] ) ) {
			$row['label'] = (string) $row['name'];
		}

		return $row;
	}

	/**
	 * Strip a query object down to a few diagnostic fields for list responses.
	 *
	 * Keeps list payloads under control - full query configs can run into
	 * thousands of bytes when meta-query trees are deep.
	 */
	private static function summarize( $query ): array {
		if ( ! is_array( $query ) ) {
			return [];
		}
		return [
			'objectType'     => $query['objectType'] ?? null,
			'postType'       => $query['postType'] ?? ( $query['post_type'] ?? null ),
			'posts_per_page' => $query['posts_per_page'] ?? null,
			'orderby'        => $query['orderby'] ?? null,
			'order'          => $query['order'] ?? null,
		];
	}
}

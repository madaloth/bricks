<?php
/**
 * Reference abilities
 *
 * Provides element schemas, dynamic data tags, and builder guide documentation.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reference {
	// ------------------------------------------------------------------
	// Shared permission callback
	// ------------------------------------------------------------------

	/**
	 * Permission: any user with builder access
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function builder_access_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// bricks/list-element-types
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-element-types
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_element_types_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'category' => [
						'type'        => 'string',
						'description' => __( 'Filter by element category.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-element-types
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_element_types_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type' => 'array',
				],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
				'hasMore' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: list all registered element types
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_element_types( $input ) {
		$elements = \Bricks\Elements::$elements;
		$category = $input['category'] ?? '';
		$result   = [];

		foreach ( $elements as $name => $element ) {
			// Skip deprecated elements
			if ( ! empty( $element['deprecated'] ) ) {
				continue;
			}

			// Filter by category if provided
			if ( $category && ( $element['category'] ?? '' ) !== $category ) {
				continue;
			}

			$result[] = [
				'name'         => $name,
				'label'        => $element['label'] ?? $name,
				'category'     => $element['category'] ?? '',
				'icon'         => $element['icon'] ?? '',
				'nestable'     => ! empty( $element['nestable'] ),
				'nestableHide' => ! empty( $element['nestableHtml'] ),
			];
		}

		return self::paginate( $result, $input );
	}

	// ------------------------------------------------------------------
	// bricks/get-element-schema
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-element-schema
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_schema_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementName' => [
					'type'        => 'string',
					'description' => __( 'Element type name, e.g. "heading", "image", "container".', 'bricks' ),
					'required'    => true,
				],
				'controlKeys' => [
					'type'        => 'array',
					'description' => __( 'Optional exact control keys to return. Use this when you only need a few settings (for example fields, actions, and submitButtonText on a form) instead of loading the full element schema.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'elementName' ],
		];
	}

	/**
	 * Output schema for get-element-schema
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_schema_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'name'                 => [ 'type' => 'string' ],
				'label'                => [ 'type' => 'string' ],
				'nestable'             => [ 'type' => 'boolean' ],
				'nestableChildren'     => [
					'type'        => 'array',
					'description' => __( 'Builder default child trees for creating this element. Preserve required wrapper settings and allocate fresh IDs when inserting these trees. Do not replace existing content with these defaults.', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
				'stateChildren'        => [
					'type'        => 'array',
					'description' => __( 'Currently available canonical state child trees. Repair an existing parent by appending only missing direct state children through the page or component editing abilities. Preserve existing children and allocate fresh IDs for inserted trees.', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
				'controls'             => [ 'type' => 'object' ],
				'controlGroups'        => [ 'type' => 'object' ],
				'requestedControlKeys' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'missingControlKeys'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Callback: get full settings schema for an element type
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_element_schema( $input ) {
		$element_name           = $input['elementName'];
		$requested_control_keys = null;

		if ( array_key_exists( 'controlKeys', $input ) ) {
			if ( ! is_array( $input['controlKeys'] ) ) {
				return Error::invalid_param( 'controlKeys', 'array of non-empty control key strings', $input['controlKeys'] );
			}

			$requested_control_keys = [];
			foreach ( $input['controlKeys'] as $index => $control_key ) {
				if ( ! is_string( $control_key ) || trim( $control_key ) === '' ) {
					return Error::invalid_param( "controlKeys[{$index}]", 'non-empty control key string', $control_key );
				}

				$requested_control_keys[] = trim( $control_key );
			}

			$requested_control_keys = array_values( array_unique( $requested_control_keys ) );
		}

		if ( ! isset( \Bricks\Elements::$elements[ $element_name ] ) ) {
			return Error::not_found( 'element_type', $element_name );
		}

		// Ensure element is fully loaded (controls populated)
		$element_data = \Bricks\Elements::get_element( [ 'name' => $element_name ] );

		if ( empty( $element_data ) ) {
			return Error::not_found( 'element_type', $element_name );
		}

		// Strip render callbacks from the complete runtime settings schema.
		$controls       = Element_Settings_Schema::get( $element_name );
		$control_groups = $element_data['controlGroups'] ?? [];

		if ( is_array( $requested_control_keys ) ) {
			$requested_control_key_map = array_fill_keys( $requested_control_keys, true );
			$controls                  = array_intersect_key( $controls, $requested_control_key_map );

			$requested_group_keys = [];
			foreach ( $controls as $control ) {
				if ( is_array( $control ) && ! empty( $control['group'] ) ) {
					$requested_group_keys[ (string) $control['group'] ] = true;
				}
			}

			$control_groups = array_intersect_key( $control_groups, $requested_group_keys );
		}

		foreach ( $controls as $key => $control ) {
			unset( $controls[ $key ]['render'] );
		}

		$response = [
			'name'          => $element_name,
			'label'         => $element_data['label'] ?? $element_name,
			'nestable'      => ! empty( $element_data['nestable'] ),
			'controls'      => $controls,
			'controlGroups' => $control_groups,
		];

		if ( in_array( $element_name, [ 'nav-nested', 'dropdown' ], true ) ) {
			$response['nestableChildren'] = $element_data['nestableChildren'] ?? [];
		}

		// Expose the same feature-gated defaults as Builder repair, without suggesting replacement of saved content.
		if ( $element_name === 'woocommerce-account-page-v2' ) {
			$groups  = \Bricks\Woocommerce::get_builder_state_group_definitions();
			$group   = $groups[ $element_name ] ?? [];
			$enabled = [];
			foreach ( $group['previewToState'] ?? [] as $state ) {
				$enabled[] = $group['states'][ $state ] ?? '';
			}
			$response['stateChildren'] = array_values(
				array_filter(
					$element_data['nestableChildren'] ?? [],
					static function ( $child ) use ( $enabled ) {
						return in_array( $child['name'] ?? '', $enabled, true );
					}
				)
			);
		}

		if ( is_array( $requested_control_keys ) ) {
			$response['requestedControlKeys'] = $requested_control_keys;
			$response['missingControlKeys']   = array_values( array_diff( $requested_control_keys, array_keys( $controls ) ) );
		}

		return $response;
	}

	// ------------------------------------------------------------------
	// bricks/list-query-loop-types
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-query-loop-types
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_query_loop_types_schema() {
		return [
			'type'       => 'object',
			'properties' => [],
		];
	}

	/**
	 * Output schema for list-query-loop-types
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_query_loop_types_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'                     => [
					'type'        => 'array',
					'description' => __( 'Runtime query loop object types after Bricks and provider filters have run.', 'bricks' ),
				],
				'total'                     => [ 'type' => 'integer' ],
				'coreTypes'                 => [
					'type'        => 'array',
					'description' => __( 'Built-in Bricks object types seeded before filters.', 'bricks' ),
				],
				'providerPrefixes'          => [
					'type'        => 'array',
					'description' => __( 'Known provider prefixes for interpreting dynamic object types.', 'bricks' ),
				],
				'acfFlexibleQueryTypes'     => [
					'type'        => 'array',
					'description' => __( 'Object types that can use ACF Flexible Content preview controls.', 'bricks' ),
				],
				'arrayConditionObjectTypes' => [
					'type'        => 'array',
					'description' => __( 'Object types that can use array result filters.', 'bricks' ),
				],
				'notes'                     => [ 'type' => 'array' ],
			],
		];
	}

	/**
	 * Callback: list runtime query loop object types.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_query_loop_types( $input ) {
		$control_options          = \Bricks\Setup::get_control_options();
		$query_types              = $control_options['queryTypes'] ?? [];
		$acf_flexible_query_types = array_values( $control_options['acfFlexibleQueryTypes'] ?? [] );
		$array_condition_types    = [];
		$array_supported_tags     = class_exists( '\\Bricks\\Integrations\\Dynamic_Data\\Providers' )
			? \Bricks\Integrations\Dynamic_Data\Providers::get_array_supported_tags_list()
			: [];
		$core_types               = [ 'post', 'term', 'user', 'api', 'array' ];
		$query_editor_types       = [ 'post', 'term', 'user' ];

		foreach ( $array_supported_tags as $tag ) {
			if ( ! empty( $tag['objectType'] ) ) {
				$array_condition_types[] = (string) $tag['objectType'];
			}
		}

		$array_condition_types = array_values( array_unique( $array_condition_types ) );
		$items                 = [];

		foreach ( $query_types as $object_type => $label ) {
			$object_type = (string) $object_type;
			$source      = self::classify_query_loop_type( $object_type );
			$label       = is_scalar( $label ) ? wp_strip_all_tags( (string) $label ) : '';

			$items[] = [
				'objectType'           => $object_type,
				'label'                => $label,
				'source'               => $source['source'],
				'engine'               => $source['engine'],
				'supportsQueryEditor'  => in_array( $object_type, $query_editor_types, true ),
				'supportsArrayFilters' => $object_type === 'array' || in_array( $object_type, $array_condition_types, true ),
				'isAcfFlexibleContent' => in_array( $object_type, $acf_flexible_query_types, true ),
				'notes'                => $source['notes'],
			];
		}

		return [
			'items'                     => $items,
			'total'                     => count( $items ),
			'coreTypes'                 => $core_types,
			'providerPrefixes'          => self::query_loop_provider_prefixes(),
			'acfFlexibleQueryTypes'     => $acf_flexible_query_types,
			'arrayConditionObjectTypes' => $array_condition_types,
			'notes'                     => [
				'The source of truth is Setup::get_control_options()[queryTypes] after the bricks/setup/control_options filter runs.',
				'Custom Query PHP is a mode for post, term, and user loops. It is not a separate objectType.',
				'Media attachments use objectType post with post_type attachment.',
			],
		];
	}

	/**
	 * Known provider prefixes used by Bricks query loop integrations.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function query_loop_provider_prefixes() {
		return [
			[
				'prefix' => 'acf_',
				'source' => 'acf',
				'notes'  => 'ACF Relationship, Post Object, Repeater, and Flexible Content fields.',
			],
			[
				'prefix' => 'mb_',
				'source' => 'metabox',
				'notes'  => 'Meta Box post fields, group fields, and relationships.',
			],
			[
				'prefix' => 'je_',
				'source' => 'jetengine',
				'notes'  => 'JetEngine repeater/posts fields.',
			],
			[
				'prefix' => 'je_relation_',
				'source' => 'jetengine',
				'notes'  => 'JetEngine relations.',
			],
			[
				'prefix' => 'cmb2_',
				'source' => 'cmb2',
				'notes'  => 'Prefix recognized by Bricks for provider-style query limits when a matching runtime type is registered.',
			],
			[
				'prefix' => 'pods_',
				'source' => 'pods',
				'notes'  => 'Prefix recognized by Bricks for provider-style query limits when a matching runtime type is registered.',
			],
			[
				'prefix' => 'ts_',
				'source' => 'toolset',
				'notes'  => 'Prefix recognized by Bricks for provider-style query limits when a matching runtime type is registered.',
			],
		];
	}

	/**
	 * Classify a query loop object type for AI clients.
	 *
	 * @since 2.4
	 *
	 * @param string $object_type Query loop object type.
	 * @return array
	 */
	private static function classify_query_loop_type( $object_type ) {
		switch ( $object_type ) {
			case 'post':
				return [
					'source' => 'core',
					'engine' => 'WP_Query',
					'notes'  => 'Posts, pages, custom post types, products, and media attachments.',
				];

			case 'term':
				return [
					'source' => 'core',
					'engine' => 'WP_Term_Query',
					'notes'  => 'Taxonomy terms.',
				];

			case 'user':
				return [
					'source' => 'core',
					'engine' => 'WP_User_Query',
					'notes'  => 'WordPress users.',
				];

			case 'api':
				return [
					'source' => 'core',
					'engine' => 'Bricks Query API',
					'notes'  => 'Remote data configured through Query API controls.',
				];

			case 'array':
				return [
					'source' => 'core',
					'engine' => 'Bricks array parser',
					'notes'  => 'Array or JSON-like data from the array editor or dynamic data.',
				];

			case 'wooCart':
				return [
					'source' => 'woocommerce',
					'engine' => 'WooCommerce cart',
					'notes'  => 'Current cart contents.',
				];
		}

		if ( strpos( $object_type, 'acf_' ) === 0 ) {
			return [
				'source' => 'acf',
				'engine' => 'Dynamic data provider',
				'notes'  => 'ACF provider-backed field loop.',
			];
		}

		if ( strpos( $object_type, 'mb_' ) === 0 ) {
			return [
				'source' => 'metabox',
				'engine' => 'Dynamic data provider',
				'notes'  => 'Meta Box provider-backed field or relationship loop.',
			];
		}

		if ( strpos( $object_type, 'je_relation_' ) === 0 ) {
			return [
				'source' => 'jetengine',
				'engine' => 'Dynamic data provider',
				'notes'  => 'JetEngine relation loop.',
			];
		}

		if ( strpos( $object_type, 'je_' ) === 0 ) {
			return [
				'source' => 'jetengine',
				'engine' => 'Dynamic data provider',
				'notes'  => 'JetEngine provider-backed field loop.',
			];
		}

		if ( strpos( $object_type, 'cmb2_' ) === 0 ) {
			return [
				'source' => 'cmb2',
				'engine' => 'Dynamic data provider or custom filter',
				'notes'  => 'CMB2-prefixed loop type registered at runtime.',
			];
		}

		if ( strpos( $object_type, 'pods_' ) === 0 ) {
			return [
				'source' => 'pods',
				'engine' => 'Dynamic data provider or custom filter',
				'notes'  => 'Pods-prefixed loop type registered at runtime.',
			];
		}

		if ( strpos( $object_type, 'ts_' ) === 0 ) {
			return [
				'source' => 'toolset',
				'engine' => 'Dynamic data provider or custom filter',
				'notes'  => 'Toolset-prefixed loop type registered at runtime.',
			];
		}

		return [
			'source' => 'custom',
			'engine' => 'bricks/query/run',
			'notes'  => 'Custom loop type added through bricks/setup/control_options and implemented through query filters.',
		];
	}

	// ------------------------------------------------------------------
	// bricks/list-dynamic-data-tags
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-dynamic-data-tags
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_dynamic_data_tags_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'group'            => [
						'type'        => 'string',
						'description' => __( 'Filter by provider group (e.g. `WP`, `ACF`, `WooCommerce`). Display label, can vary by integration.', 'bricks' ),
					],
					'provider'         => [
						'type'        => 'string',
						'description' => __( 'Filter by provider key (stable identifier). Common values: `wp`, `acf`, `woo`, `jetengine`, `metabox`, `cmb2`, `pods`, `toolset`.', 'bricks' ),
					],
					'postId'           => [
						'type'        => 'integer',
						'description' => __( 'Optional. Set post context before enumerating so provider-specific tags (ACF fields bound to the post type, Woo product tags, JetEngine meta boxes) reflect what is actually available on that post.', 'bricks' ),
					],
					'includeModifiers' => [
						'type'        => 'boolean',
						'description' => __( 'Include the `modifiers` block enumerating positional modifiers (`:plain`, `:link`, `:image`, ...) and key-value modifiers (`@fallback`, `@sanitize`, ...) with usage examples. Off by default.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-dynamic-data-tags
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_dynamic_data_tags_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'     => [ 'type' => 'array' ],
				'total'     => [ 'type' => 'integer' ],
				'page'      => [ 'type' => 'integer' ],
				'perPage'   => [ 'type' => 'integer' ],
				'hasMore'   => [ 'type' => 'boolean' ],
				'postId'    => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Post context used when enumerating, or null for a global list.', 'bricks' ),
				],
				'modifiers' => [
					'type'        => 'object',
					'description' => __( 'Present only when requested via `includeModifiers`.', 'bricks' ),
					'properties'  => [
						'positional' => [ 'type' => 'array' ],
						'keyValue'   => [ 'type' => 'array' ],
					],
				],
			],
		];
	}

	/**
	 * Callback: list available dynamic data tags
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_dynamic_data_tags( $input ) {
		$group                  = $input['group'] ?? '';
		$provider               = $input['provider'] ?? '';
		$post_id                = isset( $input['postId'] ) ? (int) $input['postId'] : 0;
		$include_modifiers      = ! empty( $input['includeModifiers'] );
		$previous_page_data     = \Bricks\Database::$page_data;
		$previous_page_settings = \Bricks\Database::$page_settings;

		try {
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );

				if ( ! $post ) {
					return Error::not_found( 'post', $post_id );
				}

				// Set post context so providers that filter tags by post type
				// (ACF, JetEngine, Meta Box, etc.) return the correct set.
				\Bricks\Database::set_page_data( $post_id );
			}

			$all_tags = \Bricks\Integrations\Dynamic_Data\Providers::get_dynamic_tags_list();
		} finally {
			if ( $post_id > 0 ) {
				\Bricks\Database::$page_data     = $previous_page_data;
				\Bricks\Database::$page_settings = $previous_page_settings;
			}
		}

		$result = [];

		foreach ( $all_tags as $tag ) {
			if ( $group && ( $tag['group'] ?? '' ) !== $group ) {
				continue;
			}

			if ( $provider && ( $tag['provider'] ?? '' ) !== $provider ) {
				continue;
			}

			$result[] = [
				'tag'      => $tag['name'] ?? '',
				'label'    => $tag['label'] ?? '',
				'group'    => $tag['group'] ?? '',
				'provider' => $tag['provider'] ?? '',
			];
		}

		$response           = self::paginate( $result, $input );
		$response['postId'] = $post_id > 0 ? $post_id : null;

		if ( $include_modifiers ) {
			$response['modifiers'] = self::dynamic_data_modifiers();
		}

		return $response;
	}

	/**
	 * Permission: list dynamic data tags.
	 *
	 * Global discovery only needs builder access. When a concrete post context
	 * is supplied, require edit access to that post because provider output may
	 * reveal post-scoped field groups or custom-field names.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_dynamic_data_tags_permission( $input ) {
		$base_permission = self::builder_access_permission( $input );

		if ( is_wp_error( $base_permission ) ) {
			return $base_permission;
		}

		$post_id = isset( $input['postId'] ) ? (int) $input['postId'] : 0;

		if ( $post_id <= 0 ) {
			return true;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return Error::not_found( 'post', $post_id );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		return true;
	}

	/**
	 * Enumeration of the dynamic-data modifier surface.
	 *
	 * Two systems coexist:
	 * - **Positional** modifiers (colon-separated) live in each provider's
	 *   filter pipeline. The canonical set is defined in Base::set_render_filters
	 *   (see includes/integrations/dynamic-data/providers/base.php:118).
	 * - **Key-value** modifiers (`@key:value`) live in Dynamic_Data_Parser
	 *   and are global across providers. The allow-list is in
	 *   set_allowed_keys (includes/integrations/dynamic-data/dynamic-data-parser.php:128).
	 *
	 * Kept here as a hand-maintained reference because neither system
	 * exposes a machine-readable schema. Bump carefully when Bricks adds
	 * new modifiers and reference the source line for easy cross-check.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	// ------------------------------------------------------------------
	// bricks/preview-dynamic-tag
	// ------------------------------------------------------------------

	/**
	 * Input schema for preview-dynamic-tag
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function preview_dynamic_tag_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'tag'        => [
					'type'        => 'string',
					'description' => __( 'The dynamic-data expression to render. May include modifiers and multiple tags, for example `{post_title:plain}`, `{featured_image:image}`, or `Hello {user_first_name @fallback:\'there\'}`.', 'bricks' ),
					'required'    => true,
				],
				'expression' => [
					'type'        => 'string',
					'description' => __( 'Alias for `tag`.', 'bricks' ),
				],
				'postId'     => [
					'type'        => 'integer',
					'description' => __( 'Post context. Required for post-scoped tags; pass the ID of the post where this copy will render.', 'bricks' ),
					'required'    => true,
				],
				'context'    => [
					'type'        => 'string',
					'description' => __( 'Render context: `text` (default), `image`, or `link`. `text` returns a string; `image` and `link` return arrays of attachment IDs / URLs.', 'bricks' ),
				],
			],
			'required'   => [ 'postId' ],
		];
	}

	/**
	 * Output schema for preview-dynamic-tag
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function preview_dynamic_tag_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'tag'         => [ 'type' => 'string' ],
				'postId'      => [ 'type' => 'integer' ],
				'context'     => [ 'type' => 'string' ],
				'rendered'    => [
					'type'        => [ 'string', 'array', 'null' ],
					'description' => __( 'Rendered value. String for context=text; array for image/link contexts.', 'bricks' ),
				],
				'isEmpty'     => [
					'type'        => 'boolean',
					'description' => __( 'True when the rendered value is empty after trimming. Useful for catching tags that would silently disappear in production.', 'bricks' ),
				],
				'unknownTags' => [
					'type'        => 'array',
					'description' => __( 'Tag names from the input that no provider recognized. These will render as literal text in production.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Callback: render a dynamic-data expression for a specific post.
	 *
	 * Read-only preview. Does not save. Intended as a pre-flight check so
	 * clients can confirm a tag resolves to what the user expects before
	 * committing it into a template.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview_dynamic_tag( $input ) {
		Manager::flush_post_cache( (int) $input['postId'] );

		$post_id                = (int) $input['postId'];
		$tag                    = isset( $input['tag'] ) ? (string) $input['tag'] : (string) ( $input['expression'] ?? '' );
		$context                = $input['context'] ?? 'text';
		$previous_page_data     = \Bricks\Database::$page_data;
		$previous_page_settings = \Bricks\Database::$page_settings;

		if ( $tag === '' ) {
			return Error::missing_param( 'tag', [ 'tag', 'expression' ] );
		}

		if ( ! in_array( $context, [ 'text', 'image', 'link' ], true ) ) {
			return Error::invalid_param( 'context', 'one of: text, image, link', $context );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return Error::not_found( 'post', $post_id );
		}

		try {
			// set_page_data is safe for non-Bricks post types - it just sets globals.
			// Wrap defensively in case future Bricks versions tighten this path.
			try {
				\Bricks\Database::set_page_data( $post_id );
			} catch ( \Throwable $e ) {
				// Continue - non-Bricks post types should still resolve post-scoped tags.
				unset( $e );
			}

			$rendered = \Bricks\Integrations\Dynamic_Data\Providers::render_content( $tag, $post_id, $context );

			$is_empty = is_array( $rendered ) ? empty( $rendered ) : trim( (string) $rendered ) === '';

			// Detect unknown tags: scan input for {tag_name} patterns that survived rendering unchanged.
			// Char class must match the same shapes the renderer accepts:
			// - `:` for positional modifiers (e.g. {post_title:upper})
			// - `|` for legacy pipe-fallback (e.g. {post_title|fallback:none})
			// - `@` for key-value args (e.g. {post_title @sanitize:false} or {post_title@sanitize:false})
			// Regression cases that must still match the bare tag name:
			// {post_title}, {post_title:upper}, {post_date|fallback:none}, {post_excerpt @sanitize}, {post_excerpt@sanitize}
			$unknown_tags = [];
			if ( is_string( $rendered ) && preg_match_all( '/\{([a-zA-Z_][a-zA-Z0-9_:.\-@|\s\'\"]*)\}/', $tag, $input_matches ) ) {
				foreach ( $input_matches[0] as $literal_tag ) {
					if ( strpos( $rendered, $literal_tag ) !== false ) {
						// Tag survived unchanged - provider didn't recognize it.
						$bare           = preg_replace( '/[\s@:|].*$/', '', $input_matches[1][ array_search( $literal_tag, $input_matches[0], true ) ] );
						$unknown_tags[] = $bare;
					}
				}
				$unknown_tags = array_values( array_unique( $unknown_tags ) );
			}

			return [
				'tag'         => $tag,
				'postId'      => $post_id,
				'context'     => $context,
				'rendered'    => $rendered,
				'isEmpty'     => $is_empty,
				'unknownTags' => $unknown_tags,
			];
		} finally {
			\Bricks\Database::$page_data     = $previous_page_data;
			\Bricks\Database::$page_settings = $previous_page_settings;
		}
	}

	/**
	 * Permission: preview dynamic data for any editable post context.
	 *
	 * Dynamic-data preview is a read-only verification helper. It should work
	 * for CPT posts whose data is rendered through Bricks templates even when
	 * the CPT itself is not directly builder-editable.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_dynamic_tag_permission( $input ) {
		$post_id = isset( $input['postId'] ) ? (int) $input['postId'] : 0;

		if ( $post_id <= 0 ) {
			return Error::missing_param( 'postId', [ 'postId' ] );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return Error::not_found( 'post', $post_id );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		return true;
	}

	private static function dynamic_data_modifiers(): array {
		$key_value_keys = class_exists( '\\Bricks\\Integrations\\Dynamic_Data\\Dynamic_Data_Parser' )
			? \Bricks\Integrations\Dynamic_Data\Dynamic_Data_Parser::get_allowed_keys()
			: [];

		$key_value_descriptions = [
			'fallback'       => 'Value to render when the tag resolves to empty. Example: `{post_title @fallback:\'Untitled\'}`.',
			'fallback-image' => 'Fallback image attachment ID for image tags. Example: `{post_thumbnail @fallback-image:\'123\'}`.',
			'sanitize'       => 'Pass `false` to disable sanitization. Example: `{post_content @sanitize:false}`.',
			'exclude'        => 'Comma-separated IDs to exclude. Used with query-aware tags.',
			'start-at'       => 'Starting index for `query_loop_index`.',
			'pad'            => 'Zero-pad `query_loop_index` output.',
			'key'            => 'JSON path selector for `query_api` responses. Example: `@key:\'title|rendered\'`.',
			'is-array'       => 'Internal only - forces array to JSON conversion in array loops.',
			'date'           => 'Source date for `format_date`. Example: `@date:\'2024-01-01\'`.',
			'from'           => 'Input date format for `format_date`. Example: `@from:\'Y-m-d\'`.',
			'to'             => 'Output date format for `format_date`. Example: `@to:\'d/m/Y\'`.',
		];

		$key_value = [];

		foreach ( $key_value_keys as $key ) {
			$key_value[] = [
				'key'         => $key,
				'description' => $key_value_descriptions[ $key ] ?? '',
			];
		}

		return [
			'positional' => [
				[
					'modifier'    => 'plain',
					'description' => __( 'Strip HTML tags. Example: `{post_excerpt:plain}`.', 'bricks' )
				],
				[
					'modifier'    => 'format',
					'description' => __( 'Preserve HTML formatting on raw-text tags. Example: `{post_excerpt:format}`.', 'bricks' )
				],
				[
					'modifier'    => 'raw',
					'description' => __( 'Skip rendering the tag; return the raw resolved value.', 'bricks' )
				],
				[
					'modifier'    => 'link',
					'description' => __( 'Wrap the output in an anchor to the tag\'s associated URL.', 'bricks' )
				],
				[
					'modifier'    => 'newTab',
					'description' => __( 'Open the link in a new tab. Chain after `:link`.', 'bricks' )
				],
				[
					'modifier'    => 'tel',
					'description' => __( 'Render as a `tel:` link.', 'bricks' )
				],
				[
					'modifier'    => 'image',
					'description' => __( 'Render as an `<img>` tag instead of a URL.', 'bricks' )
				],
				[
					'modifier'    => 'url',
					'description' => __( 'Return just the URL for file-field tags.', 'bricks' )
				],
				[
					'modifier'    => 'value',
					'description' => __( 'Return the stored value instead of the human-readable label (ACF, MB checkbox_list, etc.).', 'bricks' )
				],
				[
					'modifier'    => 'context',
					'description' => __( 'Add the archive title context/prefix.', 'bricks' )
				],
				[
					'modifier'    => 'prefix',
					'description' => __( 'Alias of `context`.', 'bricks' )
				],
				[
					'modifier'    => '{number}',
					'description' => __( 'Integer argument: word limit on text tags, pixel size on avatar tags. Example: `{post_excerpt:20}`.', 'bricks' )
				],
				[
					'modifier'    => 'array_value|{key}',
					'description' => __( 'Read a key from an array-valued field. Example: `{acf_link_field:array_value|title}`.', 'bricks' )
				],
				[
					'modifier'    => '{meta_key}',
					'description' => __( 'Positional argument falls through to `meta_key` (terms separator, date format, image size, etc.). Multiple positional args accumulate.', 'bricks' )
				],
			],
			'keyValue'   => $key_value,
		];
	}

	// ------------------------------------------------------------------
	// bricks/get-builder-guide
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-builder-guide
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_builder_guide_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'topic' => [
					'type'        => 'string',
					'description' => __( 'Topic to retrieve: elements, layout, styling, dynamic-data, query-loops, or components.', 'bricks' ),
					'required'    => true,
				],
			],
			'required'   => [ 'topic' ],
		];
	}

	/**
	 * Output schema for get-builder-guide
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_builder_guide_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'content' => [ 'type' => 'string' ],
				'topic'   => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Callback: get builder guide documentation
	 *
	 * Returns curated markdown documentation for generating valid Bricks element JSON.
	 * Covers 6 topics: elements, layout, styling, dynamic-data, query-loops, components.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_builder_guide( $input ) {
		$topic  = $input['topic'] ?? '';
		$topics = self::get_guide_topics();

		if ( ! $topic ) {
			return Error::missing_param( 'topic', array_keys( $topics ) );
		}

		if ( ! isset( $topics[ $topic ] ) ) {
			return Error::invalid_param( 'topic', 'one of: ' . implode( ', ', array_keys( $topics ) ), $topic );
		}

		return [
			'content' => $topics[ $topic ],
			'topic'   => $topic,
		];
	}

	/**
	 * Get guide topics content
	 *
	 * Returns all builder guide topics with comprehensive markdown documentation.
	 *
	 * @since 2.4
	 *
	 * @return array Topic name => markdown content
	 */
	public static function get_guide_topics() {
		return [
			'elements'     => self::guide_elements(),
			'layout'       => self::guide_layout(),
			'styling'      => self::guide_styling(),
			'dynamic-data' => self::guide_dynamic_data(),
			'query-loops'  => self::guide_query_loops(),
			'components'   => self::guide_components(),
		];
	}

	// ------------------------------------------------------------------
	// Pagination helper
	// ------------------------------------------------------------------

	/**
	 * Paginate an array of items
	 *
	 * Extracts page/perPage from input, slices the array, and returns a
	 * standardised envelope: { items, total, page, perPage, hasMore }.
	 *
	 * @since 2.4
	 *
	 * @param array $items Full result array.
	 * @param array $input Ability input (may contain page, perPage).
	 * @return array Paginated envelope.
	 */
	public static function paginate( $items, $input ) {
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, (int) ( $input['perPage'] ?? 25 ) ) );
		$total    = count( $items );
		$offset   = ( $page - 1 ) * $per_page;
		$sliced   = array_slice( $items, $offset, $per_page );

		return [
			'items'   => array_values( $sliced ),
			'total'   => $total,
			'page'    => $page,
			'perPage' => $per_page,
			'hasMore' => $offset + $per_page < $total,
		];
	}

	/**
	 * Guide: Elements
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_elements() {
		return <<<'GUIDE'
Bricks stores page content as a flat array of element objects in `wp_postmeta`. Each element has an `id` (6-char alphanumeric), a `name` (registered element type), a `parent` (another element's id, or `0` for root), a `children` array of direct child IDs, and a `settings` object.

## Element data format (stored in DB)

```json
[
  {
    "id": "nuespu",
    "name": "section",
    "parent": 0,
    "children": ["oxbafp"],
    "settings": {}
  },
  {
    "id": "oxbafp",
    "name": "container",
    "parent": "nuespu",
    "children": ["rkvpli"],
    "settings": {}
  },
  {
    "id": "rkvpli",
    "name": "heading",
    "parent": "oxbafp",
    "children": [],
    "settings": { "text": "I am a heading" }
  }
]
```

- `id`: Unique internal 6-character Bricks identifier. This id is also used in the default frontend selector `#brxe-{id}`. In nested `{name, children}` input, `id` may be omitted and Bricks will generate ids while preserving parent-child nesting. In flat arrays, provide or preserve valid 6-character ids for every `id`, `parent`, and `children` reference. Do not use semantic values like `hero` or `pricing-section` as the element `id`; only set `_cssId` when you intentionally need a custom HTML id.
- `name`: Must match a registered element type (use `list-element-types` to see all).
- `parent`: The `id` of the parent element, or `0` for root-level elements.
- `children`: Array of direct child element IDs. Automatically built from parent references during save - you do not need to provide this.
- `settings`: Element-specific settings. Keys vary by element type. Use `get-element-schema` for full control details.
- `label`: Optional custom label shown in the structure panel.

## Nested MCP-friendly input format

The `set-page-elements` and `add-element` abilities accept a nested format where `children` contains element objects instead of ID strings:

```json
{
  "name": "container",
  "settings": { "_direction": "row" },
  "children": [
    { "name": "heading", "settings": { "tag": "h2", "text": "Title" } },
    { "name": "text-basic", "settings": { "text": "Paragraph text" } }
  ]
}
```

This is automatically flattened into Bricks' native format with proper `parent` and `children` references. Only nestable elements (container, div, section, block, accordion, tabs-nested, slider-nested, form, etc.) can have children.

## Common elements and key settings

**heading**: `tag` (h1-h6), `text`, `link` (link object)
**text-basic**: `text` (supports HTML), `tag` (div, p, span)
**image**: `image` ({ id, url, size }), `link` (lightbox|attachment|media|url), `altText`, `caption`
**button**: `text`, `link` (link object), `icon` ({ icon, library }), `style` (primary|secondary|etc.), `size` (sm|md|lg|xl)
**section**: Full-width page band. Use for section-level background, padding, and anchors.
**container**: Width-constrained inner wrapper. Usually one direct child inside a section.
**block**: Inner layout group, card, column, or repeating query-loop item.
**div**: Plain semantic wrapper when you do not need Bricks' section/container presets.
**video**: `videoType` (youtube|vimeo|media|file), `youTubeId`, `vimeoId`, `media` ({ id, url })

## Link objects

Most elements use a link control object:

```json
{
  "type": "external",
  "url": "https://example.com",
  "newTab": true
}
```

Types: `external` (url field), `internal` (postId field), `meta` (useDynamicData field).

## Image objects

```json
{
  "id": 123,
  "url": "https://example.com/photo.jpg",
  "size": "large"
}
```

Sizes: `thumbnail`, `medium`, `medium_large`, `large`, `full`. Dynamic: `{ "useDynamicData": "{featured_image}" }`.

## Icon objects

```json
{
  "icon": "fas fa-arrow-right",
  "library": "fontAwesome"
}
```
GUIDE;
	}

	/**
	 * Guide: Layout
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_layout() {
		return <<<'GUIDE'
Bricks uses a flex/grid-based layout system. Layout elements (section, container, block, div) are nestable and control child arrangement. Non-nestable elements can also have flex/grid properties applied via their Style tab.

## Page structure

A typical page uses this hierarchy:

1. `section`: full-width band for section-level background, spacing, and anchors.
2. `container`: width-constrained inner wrapper. Use one per section unless there is a deliberate nested max-width.
3. `block`: reusable inner layout group, card, column, row, or query-loop item.
4. `div`: plain semantic wrapper for fine-grained grouping.

Do not use container as the default grouping element inside another container. If you need an inner row, card grid, or card body, use block or div.

```json
[
  {
    "name": "section",
    "settings": {},
    "children": [
      {
        "name": "container",
        "settings": { "_direction": "column", "_rowGap": "var(--space-l)" },
        "children": [
          { "name": "heading", "settings": { "tag": "h1", "text": "Hero" } },
          {
            "name": "block",
            "settings": { "_direction": "row", "_columnGap": "var(--space-m)" },
            "children": [
              { "name": "text-basic", "settings": { "text": "Left column" } },
              { "name": "text-basic", "settings": { "text": "Right column" } }
            ]
          }
        ]
      }
    ]
  }
]
```

## Flex layout (default)

Layout elements default to flexbox. Key settings on the **Content tab**:

Compatibility note (#86c2v7zk1): `_gridGap` keeps its historical setting name for saved-data compatibility, but emits the modern `gap` property. Gap, column gap, and row gap are available for flex, inline-flex, and grid layouts; individual values override the shorthand.

| Setting | CSS Property | Values |
|---------|-------------|--------|
| `_direction` | flex-direction | `row`, `column`, `row-reverse`, `column-reverse` |
| `_justifyContent` | justify-content | `flex-start`, `center`, `flex-end`, `space-between`, `space-around`, `space-evenly` |
| `_alignItems` | align-items | `flex-start`, `center`, `flex-end`, `stretch`, `baseline` |
| `_flexWrap` | flex-wrap | `nowrap`, `wrap`, `wrap-reverse` |
| `_gridGap` | gap | e.g. `20px`, `2rem` |
| `_columnGap` | column-gap | e.g. `20px`, `2rem` |
| `_rowGap` | row-gap | e.g. `20px` |

Child elements can set `_alignSelf`, `_flexGrow`, `_flexShrink`, `_flexBasis`, `_order`.

**Important**: Layout elements (container, div) use `_direction` for flex-direction. Non-nestable elements use `_flexDirection` instead. Both map to the same CSS property.

## Grid layout

Set `_display: "grid"` on a layout element, then configure:

| Setting | CSS Property |
|---------|-------------|
| `_gridTemplateColumns` | grid-template-columns (e.g. `repeat(3, 1fr)`) |
| `_gridTemplateRows` | grid-template-rows |
| `_gridGap` | gap |
| `_gridAutoFlow` | grid-auto-flow (`row`, `column`, `dense`) |

Grid children use `_gridItemColumnSpan` (e.g. `span 2`) and `_gridItemRowSpan`.

## Sizing and spacing

| Setting | CSS Property | Example |
|---------|-------------|---------|
| `_width` | width | `100%`, `500px` |
| `_widthMax` | max-width | `1200px` |
| `_height` | height | `400px`, `100vh` |
| `_margin` | margin | `{ "top": "20", "bottom": "20", "unit": { "top": "px", "bottom": "px" } }` |
| `_padding` | padding | Same structure as margin |

Spacing values use a structured object with `top`, `right`, `bottom`, `left` and `unit` sub-object. Omitted sides default to `0`.

## Positioning

| Setting | Values |
|---------|--------|
| `_position` | `static`, `relative`, `absolute`, `fixed`, `sticky` |
| `_top`, `_right`, `_bottom`, `_left` | Position offsets (e.g. `0px`, `10%`) |
| `_zIndex` | z-index number |

## Display control

| Setting | Values |
|---------|--------|
| `_display` | `flex`, `block`, `inline-block`, `inline`, `none`, `grid` |
| `_visibility` | `visible`, `hidden` |
| `_overflow` | `visible`, `hidden`, `scroll`, `auto` |
| `_opacity` | `0` to `1` (e.g. `0.5`) |
GUIDE;
	}

	/**
	 * Guide: Styling
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_styling() {
		return <<<'GUIDE'
Element styling uses underscore-prefixed settings keys. These can target active site breakpoints and pseudo-classes using colon-prefixed suffixes.

## Setting key format

```
{controlKey}:{breakpoint}{pseudoClass}
```

Examples:
- `_typography` - Desktop, default state
- `_typography:tablet_portrait` - Tablet breakpoint
- `_typography:hover` - Hover state
- `_background:tablet_portrait:hover` - Tablet + hover

## Breakpoints

| Key | Description | Media query |
|-----|-------------|-------------|
Breakpoints are site-configurable. Use `bricks/list-breakpoints` and copy an active breakpoint key. Omit the breakpoint suffix for the site's base breakpoint.

## Typography

Key: `_typography` - Object with CSS font properties:

```json
{
  "_typography": {
    "font-family": "Inter",
    "font-size": "18px",
    "font-weight": "700",
    "line-height": "1.6",
    "letter-spacing": "0.5px",
    "text-align": "center",
    "text-transform": "uppercase",
    "color": { "hex": "#333333" }
  }
}
```

Responsive example (smaller font on mobile):
```json
{
  "_typography": { "font-size": "24px" },
  "_typography:mobile_portrait": { "font-size": "18px" }
}
```

## Colors

Colors are objects with one of these formats:
- **Hex**: `{ "hex": "#ff0000" }`
- **RGBA**: `{ "rgb": "rgba(255,0,0,0.5)" }`
- **CSS Variable**: `{ "raw": "var(--primary)" }`
- **Palette reference**: `{ "id": "palette-color-id" }` - References a color from the color palette by its ID. Resolves to `var(--bricks-color-{id})` in CSS.

## Background

Key: `_background` - Object with background properties:

```json
{
  "_background": {
    "color": { "hex": "#f5f5f5" },
    "image": { "id": 123, "url": "https://...", "size": "cover" },
    "size": "cover",
    "position": "center",
    "repeat": "no-repeat"
  }
}
```

## Border

Key: `_border` - Object with border properties:

```json
{
  "_border": {
    "width": { "top": "1", "right": "1", "bottom": "1", "left": "1" },
    "style": "solid",
    "color": { "hex": "#e0e0e0" },
    "radius": { "top": "8", "right": "8", "bottom": "8", "left": "8" }
  }
}
```

Radius keys: `top` = top-left, `right` = top-right, `bottom` = bottom-right, `left` = bottom-left.

## Box shadow

Key: `_boxShadow`:

```json
{
  "_boxShadow": {
    "values": { "offsetX": "0", "offsetY": "4", "blur": "12", "spread": "0" },
    "color": { "hex": "#00000020" }
  }
}
```

## Transform

Key: `_transform`:

```json
{
  "_transform": {
    "translateY": "-10px",
    "rotateZ": "5",
    "scale3dX": "1.1",
    "scale3dY": "1.1"
  }
}
```

## CSS transitions

Key: `_cssTransition` - String value, e.g. `"all 0.3s ease"`.

## CSS filters

Key: `_cssFilters`:

```json
{
  "_cssFilters": {
    "blur": "5",
    "brightness": "110",
    "saturate": "120"
  }
}
```

## Custom CSS

Key: `_cssCustom` - String with CSS. Prefer native style settings or `bricks/convert-html-css-to-bricks-data` for mappable CSS. When custom CSS is needed, write a complete CSS rule with the persisted selector: `#brxe-{id}` for normal elements, `.brxe-{id}` inside components, or `.{class-name}` for global classes.

```css
#brxe-wxb5dn { transition: transform 0.3s; }
#brxe-wxb5dn:hover { transform: scale(1.05); }
```

## CSS classes and ID

- `_cssClasses`: Space-separated class names (e.g. `"card elevated"`)
- `_cssId`: HTML ID without `#` (e.g. `"hero-section"`)
- `_cssGlobalClasses`: Array of global class IDs (e.g. `["abc123", "def456"]`)

## Gradient

Key: `_gradient`:

```json
{
  "_gradient": {
    "applyTo": "background",
    "gradientType": "linear",
    "angle": 135,
    "colors": [
      { "color": { "hex": "#667eea" }, "stop": "0" },
      { "color": { "hex": "#764ba2" }, "stop": "100" }
    ]
  }
}
```

`applyTo`: `background`, `text`, or `overlay`. `gradientType`: `linear`, `radial`, `conic`.

## Pseudo-class styling

Add an active pseudo-class suffix to a CSS-generating style key:

```json
{
  "_background": { "color": { "hex": "#ffffff" } },
  "_background:hover": { "color": { "hex": "#f0f0f0" } },
  "_typography:hover": { "color": { "hex": "#0066cc" } },
  "_cssTransition": "all 0.3s ease"
}
```

Pseudo-classes are site-configurable. Use `bricks/list-pseudo-classes` and append an active returned selector directly (for example `:hover`, `:focus-visible`, or `:nth-child(2n)`). Use `_cssCustom` when the desired selector is not in that runtime list.
GUIDE;
	}

	/**
	 * Guide: Dynamic Data
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_dynamic_data() {
		return <<<'GUIDE'
Dynamic data lets elements display content from the database (post fields, custom fields, user data, etc.) instead of static values. Tags are enclosed in curly braces and placed in text fields.

## Syntax

Use `{tag_name}` in any text setting:

```json
{
  "name": "heading",
  "settings": {
    "tag": "h1",
    "text": "{post_title}"
  }
}
```

Multiple tags can be combined with static text:

```json
{
  "text": "Published on {post_date} by {post_author}"
}
```

## Common tags

### Post data
- `{post_title}` - Post title
- `{post_content}` - Post content
- `{post_excerpt}` - Post excerpt
- `{post_date}` - Publish date
- `{post_url}` - Permalink
- `{post_id}` - Post ID
- `{post_author}` - Author display name
- `{featured_image}` - Featured image (for image elements)

### Author data
- `{author_name}` - Author display name
- `{author_url}` - Author archive URL
- `{author_bio}` - Author description
- `{author_avatar}` - Author avatar image

### Site data
- `{site_title}` - Site name
- `{site_tagline}` - Site tagline
- `{site_url}` - Site URL

### Archive/term data
- `{term_name}` - Current archive term name
- `{term_description}` - Term description

Use `list-dynamic-data-tags` to get the complete list of available tags for your site, including tags from ACF, Meta Box, and other integrations.

## Dynamic data in links

For link fields, use `meta` type with `useDynamicData`:

```json
{
  "link": {
    "type": "meta",
    "useDynamicData": "{post_url}"
  }
}
```

## Dynamic data in images

For image fields:

```json
{
  "image": {
    "useDynamicData": "{featured_image}"
  }
}
```

Or with ACF: `{acf_image_field_name}`.

## Filters and modifiers

Some tags support modifiers via colons:

- `{post_date:Y-m-d}` - Custom date format
- `{post_terms_category}` - Terms from specific taxonomy

## Context

Dynamic data resolves based on context:
- On a single post page: resolves to that post's data
- Inside a query loop: resolves to the current loop item
- On an archive page: resolves to the archive's context
GUIDE;
	}

	/**
	 * Guide: Query Loops
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_query_loops() {
		return <<<'GUIDE'
Query loops repeat an element for each result of a database query. Enable on any nestable element (container, div, etc.) by setting `hasLoop: true` and configuring the `query` object.

## Basic query loop

```json
{
  "name": "container",
  "settings": {
    "_direction": "row",
    "_flexWrap": "wrap",
    "_columnGap": "20px",
    "_rowGap": "20px",
    "hasLoop": true,
    "query": {
      "objectType": "post",
      "post_type": ["post"],
      "posts_per_page": 6,
      "orderby": "date",
      "order": "DESC"
    }
  },
  "children": [
    {
      "name": "heading",
      "settings": { "tag": "h3", "text": "{post_title}" }
    },
    {
      "name": "text-basic",
      "settings": { "text": "{post_excerpt}" }
    }
  ]
}
```

The container repeats once per query result. Children use dynamic data tags to display each item's data.

## Discover query types

Call `bricks/list-query-loop-types` before building provider-backed loops. The returned `items[].objectType` values are the source of truth. Bricks starts with five built-in types, then dynamic-data providers, WooCommerce, and custom code can add more through `bricks/setup/control_options`.

## Query types

| `objectType` | Description | Key parameters |
|-------------|-------------|----------------|
| `post` | WordPress posts/pages/CPTs | `post_type`, `posts_per_page`, `orderby`, `order` |
| `term` | Taxonomy terms | `taxonomy`, `number`, `parent` |
| `user` | WordPress users | `role__in`, `number` |
| `api` | Query API data | Query API controls |
| `array` | JSON/bracket array data | `arrayEditor`, `items_per_page`, `array_conditions` |
| `acf_*` | ACF Relationship, Post Object, Repeater, or Flexible Content field | Exact object type from `bricks/list-query-loop-types` |
| `mb_*` | Meta Box field, group, or relationship | Exact object type from `bricks/list-query-loop-types` |
| `je_*`, `je_relation_*` | JetEngine field or relation | Exact object type from `bricks/list-query-loop-types` |
| `wooCart` | WooCommerce cart contents | Current cart context |

Media is not a separate object type: use `post` with `post_type: ["attachment"]`. Custom Query PHP is a mode for `post`, `term`, and `user`; it is not an object type.

## Post query parameters

```json
{
  "query": {
    "objectType": "post",
    "post_type": ["post"],
    "posts_per_page": 10,
    "offset": 0,
    "orderby": "date",
    "order": "DESC",
    "exclude_current_post": true,
    "ignore_sticky_posts": true,
    "post__in": [1, 2, 3],
    "post__not_in": [4, 5]
  }
}
```

### Ordering

`orderby` values: `date`, `title`, `ID`, `rand`, `menu_order`, `meta_value`, `meta_value_num`, `modified`, `comment_count`, `author`, `name`.

For `meta_value` / `meta_value_num`, also set `meta_key`:

```json
{
  "query": {
    "orderby": "meta_value_num",
    "order": "DESC",
    "meta_key": "price"
  }
}
```

### Taxonomy filtering

Include posts with specific terms:

```json
{
  "query": {
    "tax_query": ["category::5", "category::12"]
  }
}
```

Format: `taxonomy::term_id`. Exclude with `tax_query_not`.

### Meta query

Filter by custom fields:

```json
{
  "query": {
    "meta_query_relation": "AND",
    "meta_query": [
      {
        "key": "price",
        "value": "100",
        "compare": ">=",
        "type": "NUMERIC"
      },
      {
        "key": "status",
        "value": "active",
        "compare": "="
      }
    ]
  }
}
```

Compare operators: `=`, `!=`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS`.

## Term query

```json
{
  "query": {
    "objectType": "term",
    "taxonomy": ["category"],
    "number": 10,
    "show_empty": false,
    "orderby": "count",
    "order": "DESC"
  }
}
```

## User query

```json
{
  "query": {
    "objectType": "user",
    "role__in": ["subscriber"],
    "number": 20,
    "orderby": "registered",
    "order": "DESC"
  }
}
```

## Array query

Use `array` for array-like data, not as a generic fallback for provider fields that already have their own object type.

```json
{
  "query": {
    "objectType": "array",
    "arrayEditor": "[{\"name\":\"Alpha\"},{\"name\":\"Beta\"}]",
    "items_per_page": 2
  }
}
```

Inside the loop, use `{query_array:raw}` or `{query_array:raw @key:'name'}`. To loop an inner array, nest another Array loop and set its `arrayEditor` to the parent value, for example `{query_array:raw @key:'items'}`.

## No results

Show content when a query returns no results:

```json
{
  "query": {
    "no_results_text": "No posts found."
  }
}
```

Or reference a section template: `"no_results_template": 123`.

## Pagination and infinite scroll

For pagination, the query trail is automatically rendered. For infinite scroll:

```json
{
  "query": {
    "infinite_scroll": true,
    "infinite_scroll_margin": "200px"
  }
}
```

## PHP query editor

Bricks abilities cannot create, modify, import, sign, or render PHP Query Editor payloads. Configure PHP queries manually in the Bricks Builder. Unrelated ability edits preserve an existing signed query when its executable fields remain unchanged.
GUIDE;
	}

	/**
	 * Guide: Components
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function guide_components() {
		return <<<'GUIDE'
Components are reusable element groups with configurable properties. They are defined once and instantiated across pages. Instances inherit the component's element tree and can override specific properties.

## Component structure

A component definition contains:
- **id**: Root element ID (also serves as the component ID)
- **elements**: Flat array of elements (same format as page elements)
- **properties**: Configurable inputs exposed to instances
- **variants**: Style variations (e.g. "primary", "secondary")

## Using a component (inserting an instance)

To add a component instance, use `add-element` with a `cid` (component ID) and optionally `properties` overrides:

```json
{
  "name": "div",
  "cid": "comp_abc123",
  "properties": {
    "prop_title": "Custom Title",
    "prop_bg": { "hex": "#ff0000" }
  }
}
```

- `cid`: The component definition ID. Must reference an existing component (use `list-components` to find IDs).
- `properties`: Key-value pairs where keys are property IDs from the component definition. Only include properties you want to override - others use defaults.
- `variant`: Optional variant ID to apply a style variation.

Instance elements should NOT have `children` - children come from the component definition automatically.

## Properties

Each component property has:
- **id**: Unique property identifier (e.g. `prop_title`)
- **label**: Human-readable name
- **type**: `text`, `image`, `link`, `select`, `toggle`, `class`, `editor`, `icon`, `image-gallery`, `query`
- **default**: Default value
- **connections**: Maps property to element settings (`{ elementId: [settingKey, ...] }`)

Property values match the expected format for their type:
- `text`: String value
- `image`: Image object `{ id, url, size }`
- `link`: Link object `{ type, url, newTab }`
- `toggle`: Boolean
- `select`: String (option key)
- `class`: Array of global class IDs
- `icon`: Icon object `{ icon, library }`

## Variants

Variants are style variations stored as suffixed setting keys in the component's elements:

```json
{
  "_background": { "color": { "hex": "#ffffff" } },
  "_background:variant-dark": { "color": { "hex": "#1a1a1a" } }
}
```

To use a variant in an instance, set the `variant` key:

```json
{
  "name": "div",
  "cid": "comp_abc123",
  "variant": "variant-dark"
}
```

## Slots

Components can define slots (placeholder elements) where instance content is injected. Slot elements have `name: "slot"`. Instances provide slot content via `slotChildren`.

## Reading components

Use `list-components` for summaries (IDs, labels, property types, variant names) and `get-component` for full data including the element tree and property connections.

## Managing components

Use the focused component abilities when you need to maintain definitions:
- `list-components`: summary list with IDs, labels, property types, variant names, and usage counts.
- `get-component`: full definition, including elements, properties, and variants.
- `create-component`: create a new definition from a flat/nested element tree.
- `update-component`: replace a definition. Read the existing component first because this is a full replacement. Removing any existing slot requires `allowSlotOrphans: true`, even when the current usage scan finds no slot content.
- `delete-component`: delete a definition. A current `expectedUsageCount` is required as review evidence, and `allowOrphans: true` is always required because usage discovery cannot guarantee that no references exist.
- `extract-component-from-elements`: turn repeated saved elements into a reusable component and replace them with an instance.

When only inserting an existing component into a page/template, use `add-element` with `cid`.

## Example: card component instance

Given a "Card" component with properties `prop_title` (text), `prop_image` (image), `prop_link` (link):

```json
{
  "name": "div",
  "cid": "card_abc",
  "properties": {
    "prop_title": "Product Name",
    "prop_image": { "id": 456, "url": "https://example.com/photo.jpg", "size": "medium" },
    "prop_link": { "type": "external", "url": "https://example.com/product" }
  },
  "variant": "variant-featured"
}
```
GUIDE;
	}
}

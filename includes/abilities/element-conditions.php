<?php
/**
 * Element condition abilities
 *
 * Read and write the `_conditions` array stored on an element's `settings`
 * key. Conditions are stored as OR groups containing AND items: the element
 * renders when any group matches, and every item inside that group matches.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Conditions {

	/**
	 * Core element condition keys.
	 *
	 * Extensible via the `bricks/abilities/element_conditions/allowed_keys`
	 * filter so custom condition providers can opt into the strict MCP writer.
	 *
	 * @since 2.4
	 */
	const CONDITION_KEYS = [
		'browser',
		'current_url',
		'date',
		'datetime',
		'dynamic_data',
		'featured_image',
		'operating_system',
		'post_author',
		'post_date',
		'post_id',
		'post_parent',
		'post_status',
		'post_title',
		'referer',
		'time',
		'user_id',
		'user_logged_in',
		'user_registered',
		'user_role',
		'weekday',
		'woo_checkout_login_step_needed',
		'woo_product_category',
		'woo_product_featured',
		'woo_product_new',
		'woo_product_purchased_by_user',
		'woo_product_rating',
		'woo_product_sale',
		'woo_product_sold_individually',
		'woo_product_stock_management',
		'woo_product_stock_quantity',
		'woo_product_stock_status',
		'woo_product_tag',
		'woo_product_type',
		'woo_order_withdrawal_screen',
	];

	/**
	 * Core condition compare operators.
	 *
	 * @since 2.4
	 */
	const COMPARES = [ '==', '!=', '>=', '<=', '>', '<', 'contains', 'contains_not', 'empty', 'empty_not' ];

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: read an element's display conditions.
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
	 * Permission: write an element's display conditions.
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
	 * Locate an element in a post by ID.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id    Resolved post ID.
	 * @param string $element_id Element ID.
	 * @return array|\WP_Error { index, element, elements, area }
	 */
	private static function find_element( $post_id, $element_id ) {
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
	 * Require the builder permission that gates the Conditions panel.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	private static function check_conditions_access_permission() {
		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_element_conditions' ) ) {
			return Error::forbidden_builder_permission( 'access_element_conditions' );
		}

		return true;
	}

	/**
	 * Require element-specific edit permission plus Conditions panel access.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element array.
	 * @return true|\WP_Error
	 */
	private static function check_edit_permission_for_element( array $element ) {
		$element_name = $element['name'] ?? '';

		if ( $element_name && ! \Bricks\Builder_Permissions::user_has_permission( "edit_element_{$element_name}" ) ) {
			return Error::forbidden_builder_permission( "edit_element_{$element_name}" );
		}

		return self::check_conditions_access_permission();
	}

	// ==================================================================
	// GET ELEMENT CONDITIONS
	// ==================================================================

	/**
	 * Input schema for get-element-conditions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_conditions_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The element ID whose display conditions you want to read.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for get-element-conditions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_conditions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'           => [ 'type' => 'string' ],
				'elementName'         => [ 'type' => 'string' ],
				'conditions'          => [
					'type'        => 'array',
					'description' => __( 'Element display conditions. Outer array is OR groups, inner arrays are AND items.', 'bricks' ),
				],
				'conditionGroupCount' => [ 'type' => 'integer' ],
				'conditionItemCount'  => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: read the `_conditions` array on an element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_element_conditions( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$found = self::find_element( $post_id, $input['elementId'] );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$permission = self::check_conditions_access_permission();

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$settings   = $found['element']['settings'] ?? [];
		$conditions = isset( $settings['_conditions'] ) && is_array( $settings['_conditions'] )
			? array_values( $settings['_conditions'] )
			: [];

		return [
			'elementId'           => $found['element']['id'] ?? '',
			'elementName'         => $found['element']['name'] ?? '',
			'conditions'          => $conditions,
			'conditionGroupCount' => count( $conditions ),
			'conditionItemCount'  => self::count_condition_items( $conditions ),
		];
	}

	// ==================================================================
	// UPDATE ELEMENT CONDITIONS
	// ==================================================================

	/**
	 * Input schema for update-element-conditions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_conditions_schema() {
		$properties               = Elements::post_identifier_properties();
		$properties['elementId']  = [
			'type'        => 'string',
			'description' => __( 'The element ID.', 'bricks' ),
		];
		$properties['conditions'] = [
			'type'        => 'array',
			'description' => __( 'Full replacement of the `_conditions` array. Outer array is OR groups, inner arrays are AND items. Pass an empty array to clear all conditions.', 'bricks' ),
			'items'       => [
				'type'  => 'array',
				'items' => self::condition_item_schema(),
			],
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'conditions' ],
		];
	}

	/**
	 * Output schema for update-element-conditions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_conditions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'           => [ 'type' => 'string' ],
				'conditions'          => [ 'type' => 'array' ],
				'conditionGroupCount' => [ 'type' => 'integer' ],
				'conditionItemCount'  => [ 'type' => 'integer' ],
				'revisionId'          => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: replace the `_conditions` array on an element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_element_conditions( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id = $input['elementId'];
		$found      = self::find_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$permission = self::check_edit_permission_for_element( $found['element'] );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$conditions = self::normalize_conditions( $input['conditions'] );

		if ( is_wp_error( $conditions ) ) {
			return $conditions;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];

		$elements[ $index ]['settings']                = $elements[ $index ]['settings'] ?? [];
		$elements[ $index ]['settings']['_conditions'] = $conditions;

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'           => $element_id,
			'conditions'          => $conditions,
			'conditionGroupCount' => count( $conditions ),
			'conditionItemCount'  => self::count_condition_items( $conditions ),
			'revisionId'          => $result['revisionId'],
		];
	}

	/**
	 * Condition item schema used by the update ability.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function condition_item_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'           => [
					'type'        => [ 'string', 'integer' ],
					'description' => __( 'Unique condition row ID. Generated when omitted.', 'bricks' ),
				],
				'key'          => [
					'type'        => 'string',
					'description' => __( 'Condition type.', 'bricks' ),
				],
				'compare'      => [
					'type'        => 'string',
					'description' => __( 'Comparison operator. Defaults to `==` when omitted.', 'bricks' ),
					'enum'        => self::COMPARES,
				],
				'value'        => [
					'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
					'description' => __( 'Value to compare against. Not required when compare is `empty` or `empty_not`.', 'bricks' ),
				],
				'dynamic_data' => [
					'type'        => 'string',
					'description' => __( 'Dynamic data tag used by key `dynamic_data`, for example `{post_title}`.', 'bricks' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Count condition items across all OR groups.
	 *
	 * @since 2.4
	 *
	 * @param array $conditions Conditions array.
	 * @return int
	 */
	private static function count_condition_items( array $conditions ) {
		$count = 0;

		foreach ( $conditions as $group ) {
			$count += is_array( $group ) ? count( $group ) : 0;
		}

		return $count;
	}

	/**
	 * Normalize and validate the OR-of-AND conditions array before saving.
	 *
	 * @since 2.4
	 *
	 * @param mixed $conditions Input conditions.
	 * @return array|\WP_Error
	 */
	private static function normalize_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return Error::invalid_param( 'conditions', 'an array of condition groups', $conditions );
		}

		$normalized = [];

		foreach ( $conditions as $group_index => $group ) {
			if ( ! is_array( $group ) ) {
				return Error::invalid_param( "conditions[{$group_index}]", 'an array of condition objects', $group );
			}

			if ( empty( $group ) ) {
				return Error::invalid_param( "conditions[{$group_index}]", 'at least one condition object. Pass `conditions: []` to clear all conditions.', $group );
			}

			$normalized_group = [];

			foreach ( $group as $item_index => $condition ) {
				$normalized_condition = self::normalize_condition_item( $condition, (int) $group_index, (int) $item_index );

				if ( is_wp_error( $normalized_condition ) ) {
					return $normalized_condition;
				}

				$normalized_group[] = $normalized_condition;
			}

			$normalized[] = $normalized_group;
		}

		return $normalized;
	}

	/**
	 * Normalize one condition item.
	 *
	 * @since 2.4
	 *
	 * @param mixed $condition   Condition item.
	 * @param int   $group_index OR-group index.
	 * @param int   $item_index  AND-item index.
	 * @return array|\WP_Error
	 */
	private static function normalize_condition_item( $condition, int $group_index, int $item_index ) {
		$path = "conditions[{$group_index}][{$item_index}]";

		if ( ! is_array( $condition ) ) {
			return Error::invalid_param( $path, 'a condition object', $condition );
		}

		$unknown_keys = array_values( array_diff( array_keys( $condition ), [ 'id', 'key', 'compare', 'value', 'dynamic_data' ] ) );

		if ( $unknown_keys ) {
			return Error::invalid_param( $path, 'known Bricks condition keys only. Unknown: ' . implode( ', ', $unknown_keys ), $condition );
		}

		$key = $condition['key'] ?? '';

		if ( ! is_string( $key ) || $key === '' ) {
			return Error::invalid_param( "{$path}.key", 'a non-empty condition key', $key );
		}

		if ( ! in_array( $key, self::allowed_condition_keys(), true ) ) {
			return Error::invalid_param( "{$path}.key", 'one of: ' . implode( ', ', self::allowed_condition_keys() ), $key );
		}

		$compare = $condition['compare'] ?? '==';

		if ( ! is_string( $compare ) || ! in_array( $compare, self::COMPARES, true ) ) {
			return Error::invalid_param( "{$path}.compare", 'one of: ' . implode( ', ', self::COMPARES ), $compare );
		}

		if ( $key === 'dynamic_data' ) {
			if ( empty( $condition['dynamic_data'] ) || ! is_string( $condition['dynamic_data'] ) ) {
				return Error::invalid_param( "{$path}.dynamic_data", 'a non-empty dynamic data tag such as `{post_title}`', $condition['dynamic_data'] ?? null );
			}
		} elseif ( array_key_exists( 'dynamic_data', $condition ) ) {
			return Error::invalid_param( "{$path}.dynamic_data", 'omit `dynamic_data` unless key is `dynamic_data`', $condition['dynamic_data'] );
		}

		if ( ! in_array( $compare, [ 'empty', 'empty_not' ], true ) && ! array_key_exists( 'value', $condition ) ) {
			return Error::invalid_param( "{$path}.value", 'a value for compare operator `' . $compare . '`', null );
		}

		$id = self::normalize_row_id( $condition['id'] ?? null, "{$path}.id" );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$normalized = [
			'id'      => $id,
			'key'     => $key,
			'compare' => $compare,
		];

		if ( array_key_exists( 'value', $condition ) ) {
			$normalized['value'] = $condition['value'];
		}

		if ( $key === 'dynamic_data' ) {
			$normalized['dynamic_data'] = $condition['dynamic_data'];
		}

		return $normalized;
	}

	/**
	 * Normalize an optional condition row ID.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $id   Incoming ID.
	 * @param string $path Error path.
	 * @return string|\WP_Error
	 */
	private static function normalize_row_id( $id, string $path ) {
		if ( $id === null || $id === '' ) {
			return \Bricks\Helpers::generate_random_id( false );
		}

		if ( ! is_scalar( $id ) ) {
			return Error::invalid_param( $path, 'a string or integer row ID', $id );
		}

		return (string) $id;
	}

	/**
	 * Allowed condition keys.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function allowed_condition_keys() {
		$keys = apply_filters( 'bricks/abilities/element_conditions/allowed_keys', self::CONDITION_KEYS );

		return is_array( $keys ) ? array_values( array_unique( array_filter( $keys, 'is_string' ) ) ) : self::CONDITION_KEYS;
	}
}

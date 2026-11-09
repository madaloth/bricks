<?php
/**
 * Interaction abilities
 *
 * Read and write the `_interactions` array stored on an element's
 * `settings` key. Each interaction pairs a trigger (click / enterView /
 * formSubmit / etc.) with an action (show / hide / setAttribute /
 * startAnimation / scrollTo / etc.) and a target (self / custom /
 * popup). Emitted to the DOM as a JSON-encoded `data-interactions`
 * attribute - see includes/interactions.php:630 - and consumed by
 * assets/js/bricks.min.js on the frontend.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Interactions {

	/**
	 * Supported trigger values. Sourced from
	 * includes/interactions.php:46-86 (trigger_options).
	 *
	 * @since 2.4
	 */
	const TRIGGERS = [
		// Element events
		'click',
		'mouseover',
		'focus',
		'blur',
		'mouseenter',
		'mouseleave',
		'enterView',
		'leaveView',
		'animationEnd',
		'ajaxStart',
		'ajaxEnd',
		'filterSubmitStart',
		'filterSubmitEnd',
		'formSubmit',
		'formSuccess',
		'formError',
		// Browser/window
		'scroll',
		'contentLoaded',
		'mouseleaveWindow',
		// Query filters (@since 1.11)
		'filterOptionEmpty',
		'filterOptionNotEmpty',
		// WooCommerce (@since 2.0)
		'wooAddedToCart',
		'wooAddingToCart',
		'wooRemovedFromCart',
		'wooUpdateCart',
		'wooCartContentsChanged',
		'wooCouponApplied',
		'wooCouponRemoved',
	];

	/**
	 * Supported action values. Sourced from
	 * includes/interactions.php:193-212.
	 *
	 * @since 2.4
	 */
	const ACTIONS = [
		'show',
		'hide',
		'click',
		'setAttribute',
		'removeAttribute',
		'toggleAttribute',
		'toggleOffCanvas',
		'loadMore',
		'loadMoreGallery',
		'startAnimation',
		'scrollTo',
		'javascript',
		'openAddress',
		'closeAddress',
		'clearForm',
		'storageAdd',
		'storageRemove',
		'storageCount',
	];

	/**
	 * Supported target values. Sourced from
	 * includes/interactions.php:298-302.
	 *
	 * @since 2.4
	 */
	const TARGETS = [ 'self', 'custom', 'popup' ];

	/**
	 * Actions that operate on a target element.
	 *
	 * @since 2.4
	 */
	const TARGETED_ACTIONS = [
		'show',
		'hide',
		'click',
		'setAttribute',
		'removeAttribute',
		'toggleAttribute',
		'startAnimation',
		'scrollTo',
		'javascript',
	];

	/**
	 * Storage backends supported by interaction conditions and storage actions.
	 *
	 * @since 2.4
	 */
	const STORAGE_TYPES = [ 'windowStorage', 'sessionStorage', 'localStorage' ];

	/**
	 * Storage compare operators supported by interaction conditions.
	 *
	 * @since 2.4
	 */
	const STORAGE_COMPARES = [
		'exists',
		'notExists',
		'==',
		'!=',
		'>=',
		'<=',
		'>',
		'<',
	];

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: read an element's interactions.
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
	 * Permission: write an element's interactions.
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
	 * @return array|\WP_Error { index, element, elements }
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
	 * Require the builder permission that gates the element Interactions panel.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	private static function check_interactions_access_permission() {
		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_element_interactions' ) ) {
			return Error::forbidden_builder_permission( 'access_element_interactions' );
		}

		return true;
	}

	/**
	 * Require element-specific edit permission plus Interactions panel access.
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

		return self::check_interactions_access_permission();
	}

	/**
	 * Return element-level and inherited global-class interactions in frontend order.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element array.
	 * @return array { own, inherited, effective }
	 */
	public static function get_effective_interactions_for_element( array $element ) {
		$settings   = $element['settings'] ?? [];
		$element_id = (string) ( $element['id'] ?? '' );
		$own        = isset( $settings['_interactions'] ) && is_array( $settings['_interactions'] ) ? array_values( $settings['_interactions'] ) : [];
		$inherited  = [];
		$effective  = [];
		$class_ids  = ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : [];
		self::ensure_global_classes_loaded();
		$class_names = self::global_class_names_by_id();

		\Bricks\Interactions::get_global_class_interactions();

		foreach ( $class_ids as $class_id ) {
			if ( empty( \Bricks\Interactions::$global_class_interactions[ $class_id ] ) || ! is_array( \Bricks\Interactions::$global_class_interactions[ $class_id ] ) ) {
				continue;
			}

			$rows  = array_values( \Bricks\Interactions::$global_class_interactions[ $class_id ] );
			$group = [
				'source'       => 'globalClass',
				'sourceId'     => (string) $class_id,
				'sourceName'   => $class_names[ $class_id ] ?? '',
				'interactions' => $rows,
			];

			$inherited = array_merge( [ $group ], $inherited );
			$effective = array_merge( self::annotate_interactions( $rows, $group ), $effective );
		}

		$effective = array_merge(
			self::annotate_interactions(
				$own,
				[
					'source'     => 'element',
					'sourceId'   => $element_id,
					'sourceName' => $element['name'] ?? '',
				]
			),
			$effective
		);

		return [
			'own'       => $own,
			'inherited' => $inherited,
			'effective' => $effective,
		];
	}

	/**
	 * Add read-only source metadata to interaction rows.
	 *
	 * @since 2.4
	 *
	 * @param array $interactions Interaction rows.
	 * @param array $source       Source metadata.
	 * @return array
	 */
	private static function annotate_interactions( array $interactions, array $source ) {
		$annotated = [];

		foreach ( $interactions as $interaction ) {
			if ( ! is_array( $interaction ) ) {
				continue;
			}

			$annotated[] = array_merge(
				$interaction,
				[
					'source'     => $source['source'] ?? '',
					'sourceId'   => $source['sourceId'] ?? '',
					'sourceName' => $source['sourceName'] ?? '',
				]
			);
		}

		return $annotated;
	}

	/**
	 * Map global class IDs to labels/names for readback metadata.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function global_class_names_by_id() {
		$names          = [];
		$global_classes = \Bricks\Database::$global_data['globalClasses'] ?? [];

		if ( ! is_array( $global_classes ) ) {
			return $names;
		}

		foreach ( $global_classes as $global_class ) {
			if ( empty( $global_class['id'] ) ) {
				continue;
			}

			$names[ $global_class['id'] ] = $global_class['name'] ?? $global_class['label'] ?? '';
		}

		return $names;
	}

	/**
	 * Ensure global class data exists before reading class-level interactions.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function ensure_global_classes_loaded() {
		if ( ! array_key_exists( 'globalClasses', \Bricks\Database::$global_data ) ) {
			\Bricks\Database::get_global_data();
		}
	}

	// ==================================================================
	// GET ELEMENT INTERACTIONS
	// ==================================================================

	/**
	 * Input schema for get-element-interactions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_interactions_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The element ID whose interactions you want to read.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for get-element-interactions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_element_interactions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'             => [ 'type' => 'string' ],
				'elementName'           => [ 'type' => 'string' ],
				'interactions'          => [
					'type'        => 'array',
					'description' => __( 'Element-level `_interactions` rows only. Use `effectiveInteractions` to see global-class rows inherited at runtime.', 'bricks' ),
					'items'       => self::interaction_item_schema(),
				],
				'inheritedInteractions' => [
					'type'        => 'array',
					'description' => __( 'Global-class interaction groups inherited by this element. Each group includes source, sourceId, sourceName, and interactions.', 'bricks' ),
				],
				'effectiveInteractions' => [
					'type'        => 'array',
					'description' => __( 'Flattened runtime interaction list in frontend order. Each row includes read-only source metadata (`element` or `globalClass`).', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: read the `_interactions` array on an element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_element_interactions( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$found = self::find_element( $post_id, $input['elementId'] );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$permission = self::check_interactions_access_permission();

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$interactions = self::get_effective_interactions_for_element( $found['element'] );

		return [
			'elementId'             => $found['element']['id'] ?? '',
			'elementName'           => $found['element']['name'] ?? '',
			'interactions'          => $interactions['own'],
			'inheritedInteractions' => $interactions['inherited'],
			'effectiveInteractions' => $interactions['effective'],
		];
	}

	// ==================================================================
	// UPDATE ELEMENT INTERACTIONS
	// ==================================================================

	/**
	 * Input schema for update-element-interactions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_interactions_schema() {
		$properties                 = Elements::post_identifier_properties();
		$properties['elementId']    = [
			'type'        => 'string',
			'description' => __( 'The element ID.', 'bricks' ),
		];
		$properties['interactions'] = [
			'type'        => 'array',
			'description' => __( 'Full replacement of the interactions array. Order is significant, so partial merges are not safe on ordered repeaters. Each entry needs at least `{ trigger, action }`; `target` defaults to `self`. `visibilityThreshold` is a numeric percentage from 0 to 100. Values are validated against the known trigger, action, and target lists.', 'bricks' ),
			'items'       => self::interaction_item_schema(),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'interactions' ],
		];
	}

	/**
	 * Output schema for update-element-interactions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_interactions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'    => [ 'type' => 'string' ],
				'interactions' => [
					'type'  => 'array',
					'items' => self::interaction_item_schema(),
				],
				'revisionId'   => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Interaction item schema used by the update ability.
	 *
	 * Detailed trigger/action requirements are enforced by the normalizer. The
	 * ability schema still declares every accepted key so values survive schema
	 * sanitization before reaching that validation layer.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function interaction_item_schema() {
		$setting_value_schema = [
			'type' => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
		];
		$properties           = array_fill_keys( self::allowed_interaction_keys(), $setting_value_schema );

		$properties['id']                  = [
			'type'        => [ 'string', 'integer' ],
			'description' => __( 'Unique interaction row ID. Generated when omitted.', 'bricks' ),
		];
		$properties['trigger']             = [
			'type' => 'string',
			'enum' => self::TRIGGERS,
		];
		$properties['action']              = [
			'type' => 'string',
			'enum' => self::ACTIONS,
		];
		$properties['target']              = [
			'type' => 'string',
			'enum' => self::TARGETS,
		];
		$properties['visibilityThreshold'] = [
			'type'        => 'number',
			'description' => __( 'Percentage of the source element that must be visible before an enter or leave viewport interaction is triggered.', 'bricks' ),
			'minimum'     => 0,
			'maximum'     => 100,
		];

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/**
	 * Callback: replace the `_interactions` array on an element.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_element_interactions( $input ) {
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

		$interactions = self::normalize_interactions( $input['interactions'] );

		if ( is_wp_error( $interactions ) ) {
			return $interactions;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];

		$elements[ $index ]['settings']                  = $elements[ $index ]['settings'] ?? [];
		$elements[ $index ]['settings']['_interactions'] = array_values( $interactions );

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'    => $element_id,
			'interactions' => $elements[ $index ]['settings']['_interactions'],
			'revisionId'   => $result['revisionId'],
		];
	}

	/**
	 * Normalize and validate interaction rows before saving.
	 *
	 * @since 2.4
	 *
	 * @param mixed $interactions Input interactions.
	 * @return array|\WP_Error
	 */
	private static function normalize_interactions( $interactions ) {
		if ( ! is_array( $interactions ) ) {
			return Error::invalid_param( 'interactions', 'an array of interaction objects', $interactions );
		}

		$normalized = [];

		foreach ( $interactions as $i => $interaction ) {
			if ( ! is_array( $interaction ) ) {
				return Error::invalid_param( "interactions[{$i}]", 'an interaction object', $interaction );
			}

			$unknown_keys = array_values( array_diff( array_keys( $interaction ), self::allowed_interaction_keys() ) );

			if ( $unknown_keys ) {
				return Error::invalid_param( "interactions[{$i}]", 'known Bricks interaction keys only. Unknown: ' . implode( ', ', $unknown_keys ), $interaction );
			}

			$trigger = $interaction['trigger'] ?? '';
			$action  = $interaction['action'] ?? '';
			$target  = $interaction['target'] ?? '';

			if ( ! $trigger || ! in_array( $trigger, self::TRIGGERS, true ) ) {
				return Error::invalid_param( "interactions[{$i}].trigger", 'one of: ' . implode( ', ', self::TRIGGERS ), $trigger );
			}

			if ( ! $action || ! in_array( $action, self::ACTIONS, true ) ) {
				return Error::invalid_param( "interactions[{$i}].action", 'one of: ' . implode( ', ', self::ACTIONS ), $action );
			}

			if ( $target && ! in_array( $target, self::TARGETS, true ) ) {
				return Error::invalid_param( "interactions[{$i}].target", 'one of: ' . implode( ', ', self::TARGETS ), $target );
			}

			$interaction_id = self::normalize_row_id( $interaction['id'] ?? null, "interactions[{$i}].id" );

			if ( is_wp_error( $interaction_id ) ) {
				return $interaction_id;
			}

			$interaction['id'] = $interaction_id;

			$validation = self::validate_interaction_requirements( $interaction, (int) $i );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$normalized[] = $validation;
		}

		return $normalized;
	}

	/**
	 * Interaction keys accepted by the frontend and builder controls.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function allowed_interaction_keys() {
		return [
			'id',
			'trigger',
			'disablePreventDefault',
			'rootMargin',
			'visibilityThreshold',
			'ajaxQueryId',
			'filterElementId',
			'formId',
			'delay',
			'scrollOffset',
			'animationId',
			'action',
			'storageType',
			'actionAttributeKey',
			'actionAttributeValue',
			'loadMoreQuery',
			'loadMoreTargetSelector',
			'animationType',
			'animationDuration',
			'animationDelay',
			'targetFormSelector',
			'target',
			'targetSelector',
			'offCanvasSelector',
			'scrollToOffset',
			'scrollToDelay',
			'templateId',
			'infoBoxId',
			'popupContextType',
			'popupContextId',
			'jsFunction',
			'jsFunctionArgs',
			'runOnce',
			'conditionsSep',
			'interactionConditions',
			'interactionConditionsRelation',
			'toggleOffCanvasInfo',
		];
	}

	/**
	 * Validate action and trigger-specific fields, returning the normalized row.
	 *
	 * @since 2.4
	 *
	 * @param array $interaction Interaction row.
	 * @param int   $index       Interaction index.
	 * @return array|\WP_Error
	 */
	private static function validate_interaction_requirements( array $interaction, int $index ) {
		$trigger = $interaction['trigger'];
		$action  = $interaction['action'];
		$target  = $interaction['target'] ?? '';

		if ( array_key_exists( 'visibilityThreshold', $interaction ) ) {
			$visibility_threshold = $interaction['visibilityThreshold'];

			if ( ! is_int( $visibility_threshold ) && ! is_float( $visibility_threshold ) ) {
				return Error::invalid_param( "interactions[{$index}].visibilityThreshold", 'a number from 0 to 100', $visibility_threshold );
			}

			if ( ! is_finite( (float) $visibility_threshold ) || $visibility_threshold < 0 || $visibility_threshold > 100 ) {
				return Error::invalid_param( "interactions[{$index}].visibilityThreshold", 'a number from 0 to 100', $visibility_threshold );
			}
		}

		if ( in_array( $trigger, [ 'ajaxStart', 'ajaxEnd', 'filterSubmitStart', 'filterSubmitEnd' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].ajaxQueryId", 'ajaxQueryId' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( in_array( $trigger, [ 'filterOptionEmpty', 'filterOptionNotEmpty' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].filterElementId", 'filterElementId' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( in_array( $trigger, [ 'formSubmit', 'formSuccess', 'formError' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].formId", 'formId' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $trigger === 'scroll' ) {
			$required = self::require_numeric( $interaction, "interactions[{$index}].scrollOffset", 'scrollOffset' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( in_array( $action, self::TARGETED_ACTIONS, true ) ) {
			if ( $target === 'custom' ) {
				$required = self::require_non_empty( $interaction, "interactions[{$index}].targetSelector", 'targetSelector' );

				if ( is_wp_error( $required ) ) {
					return $required;
				}
			}

			if ( $target === 'popup' ) {
				$required = self::require_numeric( $interaction, "interactions[{$index}].templateId", 'templateId' );

				if ( is_wp_error( $required ) ) {
					return $required;
				}
			}
		}

		if ( in_array( $action, [ 'setAttribute', 'removeAttribute', 'toggleAttribute' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].actionAttributeKey", 'actionAttributeKey' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}

			if ( in_array( $action, [ 'setAttribute', 'toggleAttribute' ], true ) ) {
				$required = self::require_non_empty( $interaction, "interactions[{$index}].actionAttributeValue", 'actionAttributeValue' );

				if ( is_wp_error( $required ) ) {
					return $required;
				}
			}
		}

		if ( $action === 'toggleOffCanvas' ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].offCanvasSelector", 'offCanvasSelector' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $action === 'loadMore' ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].loadMoreQuery", 'loadMoreQuery' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $action === 'loadMoreGallery' ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].loadMoreTargetSelector", 'loadMoreTargetSelector' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $action === 'startAnimation' ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].animationType", 'animationType' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $action === 'clearForm' && ! in_array( $trigger, [ 'formSubmit', 'formSuccess', 'formError' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].targetFormSelector", 'targetFormSelector' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( $action === 'javascript' ) {
			$javascript_validation = self::validate_javascript_interaction( $interaction, $index );

			if ( is_wp_error( $javascript_validation ) ) {
				return $javascript_validation;
			}

			$interaction = $javascript_validation;
		}

		if ( in_array( $action, [ 'openAddress', 'closeAddress' ], true ) ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].infoBoxId", 'infoBoxId' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( in_array( $action, [ 'storageAdd', 'storageRemove', 'storageCount' ], true ) ) {
			$required = self::validate_storage_action( $interaction, $index );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		if ( isset( $interaction['interactionConditions'] ) ) {
			$conditions = self::normalize_interaction_conditions( $interaction['interactionConditions'], $index );

			if ( is_wp_error( $conditions ) ) {
				return $conditions;
			}

			$interaction['interactionConditions'] = $conditions;
		}

		if ( isset( $interaction['interactionConditionsRelation'] ) && ! in_array( $interaction['interactionConditionsRelation'], [ 'and', 'or' ], true ) ) {
			return Error::invalid_param( "interactions[{$index}].interactionConditionsRelation", '`and` or `or`', $interaction['interactionConditionsRelation'] );
		}

		return $interaction;
	}

	/**
	 * Require a non-empty scalar field.
	 *
	 * @since 2.4
	 *
	 * @param array  $row   Source row.
	 * @param string $path  Error path.
	 * @param string $field Field key.
	 * @return true|\WP_Error
	 */
	private static function require_non_empty( array $row, string $path, string $field ) {
		if ( ! array_key_exists( $field, $row ) || $row[ $field ] === '' || $row[ $field ] === null || is_array( $row[ $field ] ) ) {
			return Error::invalid_param( $path, 'a non-empty scalar value', $row[ $field ] ?? null );
		}

		return true;
	}

	/**
	 * Normalize an optional repeater row ID.
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
	 * Require a numeric field.
	 *
	 * @since 2.4
	 *
	 * @param array  $row   Source row.
	 * @param string $path  Error path.
	 * @param string $field Field key.
	 * @return true|\WP_Error
	 */
	private static function require_numeric( array $row, string $path, string $field ) {
		if ( ! array_key_exists( $field, $row ) || ! is_numeric( $row[ $field ] ) ) {
			return Error::invalid_param( $path, 'a numeric value', $row[ $field ] ?? null );
		}

		return true;
	}

	/**
	 * Validate storage action fields.
	 *
	 * @since 2.4
	 *
	 * @param array $interaction Interaction row.
	 * @param int   $index       Interaction index.
	 * @return true|\WP_Error
	 */
	private static function validate_storage_action( array $interaction, int $index ) {
		$required = self::require_non_empty( $interaction, "interactions[{$index}].storageType", 'storageType' );

		if ( is_wp_error( $required ) ) {
			return $required;
		}

		if ( ! in_array( $interaction['storageType'], self::STORAGE_TYPES, true ) ) {
			return Error::invalid_param( "interactions[{$index}].storageType", 'one of: ' . implode( ', ', self::STORAGE_TYPES ), $interaction['storageType'] );
		}

		$required = self::require_non_empty( $interaction, "interactions[{$index}].actionAttributeKey", 'actionAttributeKey' );

		if ( is_wp_error( $required ) ) {
			return $required;
		}

		if ( $interaction['action'] === 'storageAdd' ) {
			$required = self::require_non_empty( $interaction, "interactions[{$index}].actionAttributeValue", 'actionAttributeValue' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}
		}

		return true;
	}

	/**
	 * Normalize and validate conditional interaction rows.
	 *
	 * @since 2.4
	 *
	 * @param mixed $conditions        Interaction conditions.
	 * @param int   $interaction_index Parent interaction index.
	 * @return array|\WP_Error
	 */
	private static function normalize_interaction_conditions( $conditions, int $interaction_index ) {
		if ( ! is_array( $conditions ) ) {
			return Error::invalid_param( "interactions[{$interaction_index}].interactionConditions", 'an array of condition objects', $conditions );
		}

		$normalized = [];

		foreach ( $conditions as $condition_index => $condition ) {
			if ( ! is_array( $condition ) ) {
				return Error::invalid_param( "interactions[{$interaction_index}].interactionConditions[{$condition_index}]", 'a condition object', $condition );
			}

			$unknown_keys = array_values( array_diff( array_keys( $condition ), [ 'id', 'conditionType', 'storageKey', 'storageCompare', 'storageCompareValue' ] ) );

			if ( $unknown_keys ) {
				return Error::invalid_param( "interactions[{$interaction_index}].interactionConditions[{$condition_index}]", 'known interaction condition keys only. Unknown: ' . implode( ', ', $unknown_keys ), $condition );
			}

			$condition_id = self::normalize_row_id( $condition['id'] ?? null, "interactions[{$interaction_index}].interactionConditions[{$condition_index}].id" );

			if ( is_wp_error( $condition_id ) ) {
				return $condition_id;
			}

			$condition['id'] = $condition_id;

			$required = self::require_non_empty( $condition, "interactions[{$interaction_index}].interactionConditions[{$condition_index}].conditionType", 'conditionType' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}

			if ( ! in_array( $condition['conditionType'], self::STORAGE_TYPES, true ) ) {
				return Error::invalid_param( "interactions[{$interaction_index}].interactionConditions[{$condition_index}].conditionType", 'one of: ' . implode( ', ', self::STORAGE_TYPES ), $condition['conditionType'] );
			}

			$required = self::require_non_empty( $condition, "interactions[{$interaction_index}].interactionConditions[{$condition_index}].storageKey", 'storageKey' );

			if ( is_wp_error( $required ) ) {
				return $required;
			}

			$condition['storageCompare'] = $condition['storageCompare'] ?? 'exists';

			if ( ! in_array( $condition['storageCompare'], self::STORAGE_COMPARES, true ) ) {
				return Error::invalid_param( "interactions[{$interaction_index}].interactionConditions[{$condition_index}].storageCompare", 'one of: ' . implode( ', ', self::STORAGE_COMPARES ), $condition['storageCompare'] );
			}

			if ( ! in_array( $condition['storageCompare'], [ 'exists', 'notExists' ], true ) ) {
				$required = self::require_non_empty( $condition, "interactions[{$interaction_index}].interactionConditions[{$condition_index}].storageCompareValue", 'storageCompareValue' );

				if ( is_wp_error( $required ) ) {
					return $required;
				}
			}

			$normalized[] = $condition;
		}

		return $normalized;
	}

	/**
	 * Validate Bricks' JavaScript interaction action.
	 *
	 * The interaction stores a function name in `jsFunction`; it does not store
	 * or evaluate inline JavaScript source. Reject the misleading `javascript`
	 * key so MCP callers do not receive a successful response for a payload the
	 * frontend would ignore.
	 *
	 * @since 2.4
	 *
	 * @param array $interaction Interaction row.
	 * @param int   $index       Interaction index.
	 * @return array|\WP_Error
	 */
	private static function validate_javascript_interaction( array $interaction, int $index ) {
		if ( array_key_exists( 'javascript', $interaction ) ) {
			return Error::invalid_param(
				"interactions[{$index}].javascript",
				'omit inline JavaScript; use `jsFunction` to reference an existing function name',
				$interaction['javascript']
			);
		}

		$function = $interaction['jsFunction'] ?? '';

		if ( ! is_string( $function ) || $function === '' ) {
			return Error::invalid_param( "interactions[{$index}].jsFunction", 'a non-empty JavaScript function name', $function );
		}

		if ( ! preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)*$/', $function ) ) {
			return Error::invalid_param(
				"interactions[{$index}].jsFunction",
				'a function name without parentheses or the `window` object, for example `myFunction` or `MyApp.handleClick`',
				$function
			);
		}

		if ( strpos( $function, 'window.' ) === 0 || $function === 'window' ) {
			return Error::invalid_param(
				"interactions[{$index}].jsFunction",
				'a function name without the `window` object, for example `myFunction` or `MyApp.handleClick`',
				$function
			);
		}

		if ( isset( $interaction['jsFunctionArgs'] ) && ! is_array( $interaction['jsFunctionArgs'] ) ) {
			return Error::invalid_param( "interactions[{$index}].jsFunctionArgs", 'an array of argument objects', $interaction['jsFunctionArgs'] );
		}

		if ( isset( $interaction['jsFunctionArgs'] ) ) {
			foreach ( $interaction['jsFunctionArgs'] as $arg_index => $arg ) {
				if ( ! is_array( $arg ) ) {
					return Error::invalid_param( "interactions[{$index}].jsFunctionArgs[{$arg_index}]", 'an argument object', $arg );
				}

				$unknown_keys = array_values( array_diff( array_keys( $arg ), [ 'id', 'jsFunctionArg' ] ) );

				if ( $unknown_keys ) {
					return Error::invalid_param( "interactions[{$index}].jsFunctionArgs[{$arg_index}]", 'known JavaScript argument keys only. Unknown: ' . implode( ', ', $unknown_keys ), $arg );
				}

				if ( empty( $arg['jsFunctionArg'] ) || ! is_string( $arg['jsFunctionArg'] ) ) {
					return Error::invalid_param( "interactions[{$index}].jsFunctionArgs[{$arg_index}].jsFunctionArg", 'a non-empty argument value', $arg['jsFunctionArg'] ?? null );
				}

				$arg_id = self::normalize_row_id( $arg['id'] ?? null, "interactions[{$index}].jsFunctionArgs[{$arg_index}].id" );

				if ( is_wp_error( $arg_id ) ) {
					return $arg_id;
				}

				$interaction['jsFunctionArgs'][ $arg_index ]['id'] = $arg_id;
			}
		}

		return $interaction;
	}
}

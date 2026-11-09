<?php
/**
 * Element Validator
 *
 * Validates element data before write operations.
 * Checks element types, parent references, component instances, circular refs.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Validator {
	/**
	 * Bricks stores element IDs as six-character internal identifiers.
	 *
	 * @since 2.4
	 */
	const ELEMENT_ID_LENGTH = 6;

	/**
	 * Validate an array of elements
	 *
	 * Allows scoped callers to validate trusted element types that are unavailable
	 * in the runtime registry. (#86catbj04; @since 2.4)
	 *
	 * @since 2.4
	 *
	 * @param array    $elements                           Flat elements array.
	 * @param string[] $allowed_unregistered_element_types Known element types allowed when unavailable in the runtime registry.
	 * @return true|\WP_Error True if valid, WP_Error with first validation error.
	 */
	public static function validate( $elements, $allowed_unregistered_element_types = [] ) {
		if ( ! is_array( $elements ) ) {
			return new \WP_Error( 'invalid_input', 'Elements must be an array.' );
		}

		$identifier_check = self::validate_identifiers( $elements );

		if ( is_wp_error( $identifier_check ) ) {
			return $identifier_check;
		}

		// Avoid a PHP TypeError when a caller passes a malformed scoped allowlist. (#86catbj04; @since 2.4)
		if ( ! is_array( $allowed_unregistered_element_types ) ) {
			return new \WP_Error( 'invalid_input', 'Allowed unregistered element types must be an array.' );
		}

		$allowed_unregistered_element_types = array_fill_keys(
			array_filter( $allowed_unregistered_element_types, 'is_string' ),
			true
		);

		// Build ID index for reference validation
		$id_index = [];

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( $id ) {
				if ( isset( $id_index[ $id ] ) ) {
					return Error::conflict(
						'duplicate_element_id',
						[
							'message'   => sprintf( 'Duplicate element id "%s" found in element tree.', $id ),
							'elementId' => $id,
						]
					);
				}

				$id_index[ $id ] = $element;
			}
		}

		foreach ( $elements as $element ) {
			$error = self::validate_element( $element, $id_index, $allowed_unregistered_element_types );

			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		$scaffold_check = self::validate_navigation_scaffolds( $id_index );
		if ( is_wp_error( $scaffold_check ) ) {
			return $scaffold_check;
		}

		// Check for circular references
		$circular = self::check_circular_references( $elements );

		if ( is_wp_error( $circular ) ) {
			return $circular;
		}

		return true;
	}

	/**
	 * Require the wrappers used by both Builder controls and navigation scripts.
	 *
	 * Reject missing structure instead of moving authored children or inserting
	 * default links. Component instances own their structure in their definition.
	 *
	 * @since 2.4.2
	 *
	 * @param array $id_index Elements indexed by ID.
	 * @return true|\WP_Error
	 */
	private static function validate_navigation_scaffolds( $id_index ) {
		$required_classes = [
			'nav-nested' => 'brx-nav-nested-items',
			'dropdown'   => 'brx-dropdown-content',
		];
		$children         = [];
		foreach ( $id_index as $child ) {
			$children[ $child['parent'] ?? 0 ][] = $child;
		}

		foreach ( $id_index as $id => $element ) {
			$name = $element['name'] ?? '';
			if ( ! empty( $element['cid'] ) || ! isset( $required_classes[ $name ] ) ) {
				continue;
			}

			$required_class = $required_classes[ $name ];
			foreach ( $children[ $id ] ?? [] as $child ) {
				$wrapper_name = $child['name'] ?? '';
				if ( ! empty( $child['cid'] ) ) {
					$root         = \Bricks\Helpers::get_component_element_by_id( $child['cid'] );
					$wrapper_name = $root['name'] ?? '';
				}
				$wrapper = \Bricks\Elements::get_element( [ 'name' => $wrapper_name ] );
				if ( empty( $wrapper['nestable'] ) ) {
					continue;
				}

				$child['settings'] = $child['settings'] ?? [];
				$settings          = ! empty( $child['cid'] )
					? \Bricks\Helpers::get_component_instance( $child, 'settings' )
					: ( $child['settings'] ?? [] );
				$classes           = $settings['_hidden']['_cssClasses'] ?? '';
				if ( is_string( $classes ) && in_array( $required_class, preg_split( '/\s+/', trim( $classes ) ), true ) ) {
					continue 2;
				}
			}

			return Error::conflict(
				'missing_navigation_scaffold',
				[
					'message'       => sprintf(
						/* translators: 1: Element type, 2: Element ID, 3: Required wrapper class. */
						__( '%1$s element "%2$s" requires a nestable direct child wrapper with settings._hidden._cssClasses containing "%3$s". Read nestableChildren from bricks/get-element-schema for the canonical structure; preserve existing content when repairing the tree.', 'bricks' ),
						$name,
						$id,
						$required_class
					),
					'elementId'     => $id,
					'elementName'   => $name,
					'requiredClass' => $required_class,
					'schemaAbility' => 'bricks/get-element-schema',
				]
			);
		}

		return true;
	}

	/**
	 * Validate element IDs and parent references before lookup maps are built.
	 *
	 * Write paths call this immediately after normalization so malformed array or
	 * object values cannot reach PHP array-key operations or core save helpers.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Flat elements array.
	 * @return true|\WP_Error True when identifiers are safe to index.
	 */
	public static function validate_identifiers( $elements ) {
		if ( ! is_array( $elements ) ) {
			return new \WP_Error( 'invalid_input', 'Elements must be an array.' );
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				return Error::invalid_param( 'element', 'an object', $element );
			}

			$id   = $element['id'] ?? '';
			$name = is_string( $element['name'] ?? null ) ? $element['name'] : '';

			if ( $id === '' || $id === null ) {
				return new \WP_Error( 'missing_id', 'Element is missing an ID.' );
			}

			$id_error = self::validate_element_id( $id, $name, 'id' );

			if ( is_wp_error( $id_error ) ) {
				return $id_error;
			}

			$parent = $element['parent'] ?? 0;

			if ( $parent !== 0 && $parent !== '0' ) {
				$parent_error = self::validate_element_id( $parent, $name, 'parent' );

				if ( is_wp_error( $parent_error ) ) {
					return $parent_error;
				}
			}
		}

		return true;
	}

	/**
	 * Validate a single element
	 *
	 * Accepts the scoped runtime-registry exceptions prepared by validate().
	 * (#86catbj04; @since 2.4)
	 *
	 * @since 2.4
	 *
	 * @param array $element                            Element data.
	 * @param array $id_index                           Map of all element IDs in the array.
	 * @param array $allowed_unregistered_element_types Allowed unregistered element types indexed by name.
	 * @return true|\WP_Error
	 */
	private static function validate_element( $element, $id_index, $allowed_unregistered_element_types ) {
		$id   = $element['id'] ?? '';
		$name = $element['name'] ?? '';

		// Must have ID
		if ( empty( $id ) ) {
			return new \WP_Error( 'missing_id', 'Element is missing an ID.' );
		}

		$id_error = self::validate_element_id( $id, $name, 'id' );

		if ( is_wp_error( $id_error ) ) {
			return $id_error;
		}

		// Must have name
		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_name', "Element '{$id}' is missing a name." );
		}

		// Element type must exist (skip for component instances that may reference custom elements)
		if ( empty( $element['cid'] ) && ! isset( \Bricks\Elements::$elements[ $name ] ) ) {
			if ( class_exists( '\Bricks\Woocommerce' ) ) {
				\Bricks\Woocommerce::register_advanced_modular_support_element( $name );
			}
		}

		if ( empty( $element['cid'] ) && ! isset( \Bricks\Elements::$elements[ $name ] ) && ! isset( $allowed_unregistered_element_types[ $name ] ) ) {
			return new \WP_Error( 'invalid_element_type', "Unknown element type '{$name}' on element '{$id}'." );
		}

		// Parent reference must be valid
		$parent = $element['parent'] ?? 0;

		if ( $parent !== 0 && $parent !== '0' ) {
			$parent_error = self::validate_element_id( $parent, $name, 'parent' );

			if ( is_wp_error( $parent_error ) ) {
				return $parent_error;
			}

			if ( ! isset( $id_index[ $parent ] ) ) {
				return new \WP_Error( 'invalid_parent', "Element '{$id}' references non-existent parent '{$parent}'." );
			}
		}

		// Component instance validation
		if ( ! empty( $element['cid'] ) ) {
			$error = self::validate_component_instance( $element, $id_index );

			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		// Settings shape validation (query, link, dynamic-data tags)
		if ( isset( $element['settings'] ) ) {
			$error = self::validate_settings( $element['settings'], $id, $name );

			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		return true;
	}

	/**
	 * Validate an internal Bricks element id or parent reference.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $id           Element ID value.
	 * @param string $element_name Element type, when known.
	 * @param string $path         Field path, e.g. `id` or `parent`.
	 * @return true|\WP_Error
	 */
	private static function validate_element_id( $id, $element_name = '', $path = 'id' ) {
		if ( ! is_string( $id ) || strlen( $id ) !== self::ELEMENT_ID_LENGTH ) {
			return Error::invalid_element_id( $id, (string) $element_name, $path );
		}

		return true;
	}

	/**
	 * Validate settings shape. Catches the common write-time mistakes that
	 * silently render as broken markup or crash the builder:
	 *
	 *  - `query` set to null / a string / a scalar (must be array if present).
	 *  - `query.objectType` missing when query is set (frontend renderer
	 *    accesses sub-keys without guarding).
	 *  - A link control set to a non-array.
	 *  - A link control with `type === 'external'` but `url` empty (renders an `href=""`).
	 *  - A link control with `type === 'internal'` but `postId` missing.
	 *  - An Image control with a scalar value or an external source without a URL.
	 *  - Dynamic-data tag patterns that look intentional but malformed
	 *    (`{tag` / `tag}` / `{ tag }`).
	 *
	 * @since 2.4
	 *
	 * @param array  $settings     Element settings.
	 * @param string $element_id   Element ID for error context.
	 * @param string $element_name Element name for error context.
	 * @return true|\WP_Error
	 */
	private static function validate_settings( $settings, $element_id, $element_name ) {
		if ( ! is_array( $settings ) ) {
			return new \WP_Error(
				'invalid_settings',
				"Element '{$element_id}' settings must be an object.",
				[
					'elementId'   => $element_id,
					'elementName' => $element_name
				]
			);
		}

		// `query` shape - must be array, must have objectType when set.
		if ( array_key_exists( 'query', $settings ) ) {
			$query = $settings['query'];

			if ( $query !== null && ! is_array( $query ) ) {
				return new \WP_Error(
					'invalid_query_shape',
					"Element '{$element_id}' has a `query` setting that is not an object. Bricks expects `query` to be an object like `{ objectType: 'post', post_type: ['post'], posts_per_page: 10 }` - or omitted entirely.",
					[
						'elementId'   => $element_id,
						'elementName' => $element_name,
						'received'    => gettype( $query )
					]
				);
			}

			if ( is_array( $query ) && empty( $query['objectType'] ) ) {
				return new \WP_Error(
					'invalid_query_shape',
					"Element '{$element_id}' has a `query` without `objectType`. Add `objectType: 'post' | 'term' | 'user'` (or omit `query` entirely).",
					[
						'elementId'   => $element_id,
						'elementName' => $element_name
					]
				);
			}
		}

		if ( $element_name === 'image' && array_key_exists( 'image', $settings ) ) {
			$image = $settings['image'];

			if ( $image !== null && $image !== false && ! is_array( $image ) ) {
				return Error::invalid_param( 'settings.image', 'an image object', $image );
			}

			if (
				is_array( $image ) &&
				array_key_exists( 'external', $image ) &&
				! is_bool( $image['external'] ) &&
				! is_string( $image['external'] )
			) {
				return Error::invalid_param( 'settings.image.external', 'a boolean or dynamic URL string', $image['external'] );
			}

			if (
				is_array( $image ) &&
				array_key_exists( 'url', $image ) &&
				$image['url'] !== null &&
				$image['url'] !== false &&
				! is_string( $image['url'] )
			) {
				return Error::invalid_param( 'settings.image.url', 'a URL string', $image['url'] );
			}

			if (
				is_array( $image ) &&
				! empty( $image['external'] ) &&
				( ! isset( $image['url'] ) || ! is_string( $image['url'] ) || trim( $image['url'] ) === '' )
			) {
				return Error::invalid_param(
					'settings.image.url',
					'a non-empty URL when settings.image.external is set',
					$image['url'] ?? null
				);
			}
		}

		// Link control values must be arrays with a usable target when type is set.
		// Resolve them from the owning element's controls: e.g. Image uses `link`
		// as a select and stores its actual link control value under `url`.
		foreach ( self::get_link_control_keys( $element_name, $settings ) as $link_control_key ) {
			$link = $settings[ $link_control_key ];

			if ( $link === null || $link === '' ) {
				continue;
			}

			$link_error = self::validate_link_setting( $link, $element_id );

			if ( is_wp_error( $link_error ) ) {
				return $link_error;
			}
		}

		// Dynamic-data tag pattern check on string leaves. Walk top-level keys
		// only - code/CSS/JS settings naturally contain `{` / `}` and would
		// false-positive a recursive walk.
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, self::BRACE_BEARING_KEYS, true ) ) {
				continue;
			}

			$tag_error = self::validate_dynamic_tags( $value, $element_id, (string) $key );

			if ( is_wp_error( $tag_error ) ) {
				return $tag_error;
			}
		}

		return true;
	}

	/**
	 * Get populated settings backed by a link control in the element schema.
	 *
	 * Falls back to the legacy `link` key for component/custom element data whose
	 * controls are unavailable, preserving the validator's existing protection.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @param array  $settings     Element settings.
	 * @return string[]
	 */
	private static function get_link_control_keys( $element_name, $settings ) {
		$controls = \Bricks\Elements::get_element( [ 'name' => $element_name ], 'controls' );

		if ( ! is_array( $controls ) || ! $controls ) {
			return array_key_exists( 'link', $settings ) ? [ 'link' ] : [];
		}

		$link_control_keys = [];

		foreach ( $controls as $control_key => $control ) {
			if ( is_array( $control ) && ( $control['type'] ?? '' ) === 'link' && array_key_exists( $control_key, $settings ) ) {
				$link_control_keys[] = $control_key;
			}
		}

		return $link_control_keys;
	}

	/**
	 * Settings keys whose values legitimately contain `{` / `}` characters
	 * (raw CSS, JS, JSON, schema markup). Skipped by the dynamic-tag walker
	 * to avoid false positives.
	 *
	 * @since 2.4
	 */
	private const BRACE_BEARING_KEYS = [
		'_cssCustom',
		'css',
		'customCss',
		'code',
		'codeAfter',
		'codeBefore',
		'executeCode',
		'javascriptCode',
		'jsCode',
		'phpCode',
		'cssCode',
		'htmlCode',
		'schema',
	];

	/**
	 * Validate a `link` control value. Bricks renders links via
	 * Base::set_link_attributes which silently no-ops when type is missing
	 * but happily emits `href=""` when type is set without a target. Catch
	 * the latter at write time so we don't ship broken anchors.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $link        Link value.
	 * @param string $element_id  Element ID for error context.
	 * @return true|\WP_Error
	 */
	private static function validate_link_setting( $link, $element_id ) {
		if ( ! is_array( $link ) ) {
			return new \WP_Error(
				'invalid_link_shape',
				"Element '{$element_id}' has a `link` setting that is not an object. Bricks expects `link` to be an object like `{ type: 'external', url: 'https://example.com' }`.",
				[
					'elementId' => $element_id,
					'received'  => gettype( $link )
				]
			);
		}

		$type = $link['type'] ?? '';

		if ( ! $type ) {
			return true; // Empty link is valid - renders nothing.
		}

		$known_types = [
			'internal',
			'external',
			'meta',
			'taxonomy',
			'media',
			'lightboxImage',
			'lightboxVideo',
			'lightboxSelfHostedVideo',
			'lightboxIframe',
			'lightboxInline',
		];

		if ( ! in_array( $type, $known_types, true ) ) {
			return new \WP_Error(
				'invalid_link_type',
				"Element '{$element_id}' has unknown `link.type = '{$type}'`. Use one of: " . implode( ', ', $known_types ) . '.',
				[
					'elementId' => $element_id,
					'type'      => $type
				]
			);
		}

		if ( $type === 'external' ) {
			$url = $link['url'] ?? '';

			if ( ! is_string( $url ) || trim( $url ) === '' ) {
				return new \WP_Error(
					'invalid_link_url',
					"Element '{$element_id}' has `link.type = 'external'` but `link.url` is empty. Either provide a URL or remove the `link` setting.",
					[ 'elementId' => $element_id ]
				);
			}
		}

		if ( $type === 'internal' && empty( $link['postId'] ) && empty( $link['useDynamicData'] ) ) {
			return new \WP_Error(
				'invalid_link_target',
				"Element '{$element_id}' has `link.type = 'internal'` but no `link.postId`. Either provide a `postId` or set `useDynamicData: true`.",
				[ 'elementId' => $element_id ]
			);
		}

		return true;
	}

	/**
	 * Walk a settings tree and reject malformed dynamic-data tag patterns.
	 *
	 * Bricks dynamic data uses `{tag}` / `{tag:filter}` syntax. Unbalanced
	 * braces (`{tag`, `tag}`) indicate a typo or a hand-edited setting that
	 * will render as literal text on the frontend. We don't try to validate
	 * that the tag exists - providers register tags at runtime and the
	 * validator runs in admin context - but we can flag the syntax error.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $value      Setting subtree.
	 * @param string $element_id Element ID for error context.
	 * @return true|\WP_Error
	 */
	private static function validate_dynamic_tags( $value, $element_id, $key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $sub_key => $sub ) {
				if ( in_array( (string) $sub_key, self::BRACE_BEARING_KEYS, true ) ) {
					continue;
				}

				$error = self::validate_dynamic_tags( $sub, $element_id, (string) $sub_key );

				if ( is_wp_error( $error ) ) {
					return $error;
				}
			}

			return true;
		}

		if ( ! is_string( $value ) || $value === '' ) {
			return true;
		}

		$opens  = substr_count( $value, '{' );
		$closes = substr_count( $value, '}' );

		if ( $opens !== $closes ) {
			$key_hint = $key !== '' ? " on `{$key}`" : '';
			return new \WP_Error(
				'invalid_dynamic_tag',
				"Element '{$element_id}' has a setting{$key_hint} with unbalanced `{` / `}` braces, which renders as literal text. If you meant a dynamic data tag, balance the braces (e.g. `{post_title}`); otherwise escape the literal text.",
				[
					'elementId' => $element_id,
					'key'       => $key,
					'value'     => $value
				]
			);
		}

		return true;
	}

	/**
	 * Validate a component instance
	 *
	 * @since 2.4
	 *
	 * @param array $element Element data with 'cid'.
	 * @return true|\WP_Error
	 */
	private static function validate_component_instance( $element, $id_index ) {
		$cid       = $element['cid'];
		$component = \Bricks\Helpers::get_component_by_cid( $cid );

		if ( ! $component ) {
			return new \WP_Error( 'invalid_component', "Component '{$cid}' not found." );
		}

		// Validate property keys match component definition
		if ( ! empty( $element['properties'] ) && is_array( $element['properties'] ) ) {
			$defined_props = [];

			if ( ! empty( $component['properties'] ) ) {
				foreach ( $component['properties'] as $prop ) {
					$defined_props[ $prop['id'] ?? '' ] = $prop;
				}
			}

			foreach ( $element['properties'] as $prop_key => $prop_value ) {
				if ( ! isset( $defined_props[ $prop_key ] ) ) {
					return new \WP_Error(
						'invalid_property',
						"Property '{$prop_key}' not found in component '{$cid}' definition."
					);
				}

				if ( is_string( $prop_value ) && strpos( $prop_value, 'parent:' ) === 0 ) {
					if ( ! preg_match( '/^parent:cid_([^:]+):prop_([^:]+)$/', $prop_value ) ) {
						return new \WP_Error(
							'invalid_parent_property_reference',
							"Property '{$prop_key}' on component instance '{$element['id']}' has an invalid parent property reference '{$prop_value}'. Expected parent:cid_{componentId}:prop_{propertyId}."
						);
					}
				}
			}
		}

		// Validate variant ID
		if ( ! empty( $element['variant'] ) ) {
			$variant_found = false;
			$variants      = $component['variants'] ?? [];

			foreach ( $variants as $variant ) {
				if ( ( $variant['id'] ?? '' ) === $element['variant'] ) {
					$variant_found = true;
					break;
				}
			}

			if ( ! $variant_found ) {
				return new \WP_Error(
					'invalid_variant',
					"Variant '{$element['variant']}' not found in component '{$cid}'."
				);
			}
		}

		if ( ! empty( $element['slotChildren'] ) ) {
			$error = self::validate_component_slot_children( $element, $component, $id_index );

			if ( is_wp_error( $error ) ) {
				return $error;
			}
		}

		return true;
	}

	/**
	 * Validate component instance slot children.
	 *
	 * @since 2.4
	 *
	 * @param array $element   Component instance element.
	 * @param array $component Referenced component record.
	 * @param array $id_index  Map of all element IDs in the current tree.
	 * @return true|\WP_Error
	 */
	private static function validate_component_slot_children( $element, $component, $id_index ) {
		if ( ! is_array( $element['slotChildren'] ) ) {
			return new \WP_Error( 'invalid_slot_children', "Component instance '{$element['id']}' slotChildren must be an object keyed by slot element id." );
		}

		$slot_ids = [];

		foreach ( (array) ( $component['elements'] ?? [] ) as $component_element ) {
			if ( ( $component_element['name'] ?? '' ) === 'slot' && ! empty( $component_element['id'] ) ) {
				$slot_ids[ $component_element['id'] ] = true;
			}
		}

		foreach ( $element['slotChildren'] as $slot_id => $child_ids ) {
			if ( ! isset( $slot_ids[ $slot_id ] ) ) {
				return new \WP_Error(
					'invalid_component_slot',
					"Component instance '{$element['id']}' references slot '{$slot_id}', but component '{$element['cid']}' has no matching slot element."
				);
			}

			if ( ! is_array( $child_ids ) ) {
				return new \WP_Error(
					'invalid_slot_children',
					"Component instance '{$element['id']}' slot '{$slot_id}' must contain an array of child element ids."
				);
			}

			foreach ( $child_ids as $child_id ) {
				$child_error = self::validate_element_id( $child_id, 'slot child', "slotChildren.{$slot_id}" );

				if ( is_wp_error( $child_error ) ) {
					return $child_error;
				}

				if ( ! isset( $id_index[ $child_id ] ) ) {
					return new \WP_Error(
						'invalid_slot_child',
						"Component instance '{$element['id']}' slot '{$slot_id}' references non-existent child '{$child_id}'."
					);
				}

				$child_parent = (string) ( $id_index[ $child_id ]['parent'] ?? '' );

				if ( $child_parent !== (string) $element['id'] ) {
					return new \WP_Error(
						'invalid_slot_child_parent',
						"Slot child '{$child_id}' must use component instance '{$element['id']}' as its parent."
					);
				}
			}
		}

		return true;
	}

	/**
	 * Check for circular parent references
	 *
	 * @since 2.4
	 *
	 * @param array $elements Flat elements array.
	 * @return true|\WP_Error
	 */
	private static function check_circular_references( $elements ) {
		$parent_map = [];

		foreach ( $elements as $element ) {
			$id     = $element['id'] ?? '';
			$parent = $element['parent'] ?? 0;

			if ( is_string( $id ) && $id !== '' ) {
				$parent_map[ $id ] = $parent;
			}
		}

		foreach ( $parent_map as $id => $parent ) {
			$visited = [ $id => true ];
			$current = $parent;

			while ( $current && $current !== 0 && $current !== '0' ) {
				if ( isset( $visited[ $current ] ) ) {
					return new \WP_Error( 'circular_reference', "Circular parent reference detected involving element '{$id}'." );
				}

				$visited[ $current ] = true;
				$current             = $parent_map[ $current ] ?? 0;
			}
		}

		return true;
	}
}

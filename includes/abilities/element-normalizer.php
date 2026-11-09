<?php
/**
 * Element Normalizer
 *
 * Converts nested {name, children} format to flat Bricks element array.
 * Generates IDs, resolves parent references, validates nestable elements.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Element_Normalizer {
	/**
	 * Normalize elements from nested or mixed format to flat Bricks array
	 *
	 * @since 2.4
	 *
	 * @param array $elements Input elements (nested or flat).
	 * @return array|\WP_Error Flat elements array or error.
	 */
	public function normalize( $elements ) {
		if ( ! is_array( $elements ) ) {
			return new \WP_Error( 'invalid_input', 'Elements must be an array.' );
		}

		$flat = [];

		foreach ( $elements as $element ) {
			$result = $this->flatten_element( $element, 0 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$flat = array_merge( $flat, $result );
		}

		return $flat;
	}

	/**
	 * Flatten a single element and its children recursively
	 *
	 * @since 2.4
	 *
	 * @param array      $element Element data (may have children).
	 * @param int|string $parent  Parent element ID (0 for root).
	 * @return array|\WP_Error Flat array of elements.
	 */
	private function flatten_element( $element, $parent = 0 ) {
		$flat = [];

		if ( ! is_array( $element ) ) {
			return Error::invalid_param( 'element', 'an object', $element );
		}

		$name = $element['name'] ?? null;

		// Preserve exact plugin identifiers; only resolve an unambiguous registered match.
		if ( is_string( $name ) && ! isset( \Bricks\Elements::$elements[ $name ] ) ) {
			$matches = [];

			foreach ( array_keys( \Bricks\Elements::$elements ) as $registered_name ) {
				if ( strcasecmp( $name, $registered_name ) === 0 ) {
					$matches[] = $registered_name;
				}
			}

			if ( count( $matches ) === 1 ) {
				$element['name'] = $matches[0];
			}
		}

		$element = self::normalize_setting_aliases( $element );

		if ( is_wp_error( $element ) ) {
			return $element;
		}

		$element = self::normalize_image_settings( $element );

		// Already in flat/Bricks format:
		// - Has an ID and either an explicit parent or appears at the top level
		// - Either no 'children' key, or 'children' is an array of ID strings (Bricks DB format)
		// vs. nested MCP-friendly format where 'children' contains element objects (arrays with 'name' key)
		if (
			isset( $element['id'] ) &&
			( array_key_exists( 'parent', $element ) || 0 === $parent )
		) {
			if ( ! isset( $element['children'] ) && ! self::has_nested_slot_children( $element ) ) {
				return [ $element ];
			}

			// Children is an array of ID strings (Bricks DB format) - not nested element objects
			if (
				is_array( $element['children'] ?? null ) &&
				( empty( $element['children'] ) || is_string( $element['children'][0] ) ) &&
				! self::has_nested_slot_children( $element )
			) {
				return [ $element ];
			}
		}

		if ( array_key_exists( 'parent', $element ) && ! array_key_exists( 'id', $element ) ) {
			return Error::invalid_element_id( '', (string) ( $element['name'] ?? '' ), 'id' );
		}

		// Generate ID if not provided
		$id = $element['id'] ?? self::generate_id();

		// Component instance: skip children processing
		if ( ! empty( $element['cid'] ) ) {
			$flat_element = [
				'id'     => $id,
				'name'   => $element['name'] ?? self::component_root_name( (string) $element['cid'] ),
				'parent' => $parent,
			];

			if ( ! empty( $element['cid'] ) ) {
				$flat_element['cid'] = $element['cid'];
			}

			if ( ! empty( $element['properties'] ) ) {
				$flat_element['properties'] = $element['properties'];
			}

			if ( ! empty( $element['variant'] ) ) {
				$flat_element['variant'] = $element['variant'];
			}

			$slot_flat = [];

			if ( ! empty( $element['slotChildren'] ) ) {
				$slot_children = [];

				if ( ! is_array( $element['slotChildren'] ) ) {
					return Error::invalid_param( 'slotChildren', 'an object mapping slot element ids to child element ids or nested child objects', $element['slotChildren'] );
				}

				foreach ( $element['slotChildren'] as $slot_id => $children ) {
					if ( ! is_array( $children ) ) {
						return Error::invalid_param( "slotChildren.{$slot_id}", 'an array of child element ids or nested child objects', $children );
					}

					$slot_children[ $slot_id ] = [];

					foreach ( $children as $child ) {
						if ( is_array( $child ) ) {
							$child_result = $this->flatten_element( $child, $id );

							if ( is_wp_error( $child_result ) ) {
								return $child_result;
							}

							if ( ! empty( $child_result[0]['id'] ) ) {
								$slot_children[ $slot_id ][] = $child_result[0]['id'];
							}

							$slot_flat = array_merge( $slot_flat, $child_result );
						} else {
							$slot_children[ $slot_id ][] = (string) $child;
						}
					}
				}

				$flat_element['slotChildren'] = $slot_children;
			}

			if ( ! empty( $element['settings'] ) ) {
				$flat_element['settings'] = $element['settings'];
			}

			$selectors = self::normalize_selectors( $element );
			if ( is_wp_error( $selectors ) ) {
				return $selectors;
			}

			if ( ! empty( $selectors ) ) {
				$flat_element['selectors'] = $selectors;
			}

			if ( ! empty( $element['label'] ) ) {
				$flat_element['label'] = $element['label'];
			}

			return array_merge( [ $flat_element ], $slot_flat );
		}

		// Regular element
		$name = $element['name'] ?? '';

		if ( empty( $name ) ) {
			return new \WP_Error( 'missing_element_name', 'Element must have a "name" property.' );
		}

		// Check nestable before processing children
		$children = $element['children'] ?? [];

		if ( ! empty( $children ) ) {
			$is_nestable = self::is_nestable( $name );

			if ( ! $is_nestable ) {
				return new \WP_Error(
					'not_nestable',
					"Element '{$name}' is not nestable and cannot have children."
				);
			}
		}

		// Build flat element
		$flat_element = [
			'id'       => $id,
			'name'     => $name,
			'parent'   => $parent,
			'settings' => $element['settings'] ?? [],
		];

		if ( ! empty( $element['label'] ) ) {
			$flat_element['label'] = $element['label'];
		}

		$selectors = self::normalize_selectors( $element );
		if ( is_wp_error( $selectors ) ) {
			return $selectors;
		}

		if ( ! empty( $selectors ) ) {
			$flat_element['selectors'] = $selectors;
		}

		$flat[] = $flat_element;

		// Process children
		foreach ( $children as $child ) {
			$child_result = $this->flatten_element( $child, $id );

			if ( is_wp_error( $child_result ) ) {
				return $child_result;
			}

			$flat = array_merge( $flat, $child_result );
		}

		return $flat;
	}

	/**
	 * Supply preview URLs for attachment-backed Image and Gallery settings.
	 *
	 * @since 2.4.2
	 *
	 * @param array $element Element data.
	 * @return array Element with missing attachment URLs resolved.
	 */
	public static function normalize_image_settings( array $element ) {
		if ( ( $element['name'] ?? '' ) === 'image' && is_array( $element['settings']['image'] ?? null ) ) {
			$element['settings']['image'] = self::normalize_attachment_image( $element['settings']['image'] );
		} elseif ( ( $element['name'] ?? '' ) === 'image-gallery' && is_array( $element['settings']['items']['images'] ?? null ) && empty( $element['settings']['items']['useDynamicData'] ) ) {
			foreach ( $element['settings']['items']['images'] as $key => $image ) {
				if ( is_array( $image ) ) {
					$element['settings']['items']['images'][ $key ] = self::normalize_attachment_image( $image, $element['settings']['items']['size'] ?? null );
				}
			}
		}

		return $element;
	}

	/**
	 * Resolve only missing local URLs, preserving explicit and dynamic sources.
	 *
	 * @since 2.4.2
	 *
	 * @param array       $image Image setting.
	 * @param string|null $size  Gallery image size.
	 * @return array Image setting.
	 */
	private static function normalize_attachment_image( array $image, $size = null ) {
		$id = $image['id'] ?? null;

		if ( ! empty( $image['url'] ) || ! empty( $image['external'] ) || ! empty( $image['useDynamicData'] ) || ! ( is_int( $id ) || is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) {
			return $image;
		}

		$size = $size ?? ( $image['size'] ?? BRICKS_DEFAULT_IMAGE_SIZE );

		if ( ! is_string( $size ) || $size === '' ) {
			return $image;
		}

		$url = wp_get_attachment_image_url( (int) $id, $size );

		if ( $url ) {
			$image['url'] = $url;
		}

		return $image;
	}

	/**
	 * Normalize common code-oriented setting names to canonical Bricks keys.
	 *
	 * @param array $element Incoming element.
	 * @return array|\WP_Error Normalized element, or an error for ambiguity.
	 */
	private static function normalize_setting_aliases( array $element ) {
		$settings = $element['settings'] ?? null;

		if ( ! is_array( $settings ) || ! array_key_exists( 'customAttributes', $settings ) ) {
			return $element;
		}

		$name = (string) ( $element['name'] ?? '' );

		// Unknown elements and plugin controls are opaque authority. Only normalize
		// the alias when the registered element does not declare it as a real control.
		if ( $name === '' || ! isset( \Bricks\Elements::$elements[ $name ] ) ) {
			return $element;
		}

		$controls = method_exists( '\Bricks\Elements', 'get_element' )
			? \Bricks\Elements::get_element( [ 'name' => $name ], 'controls' )
			: ( \Bricks\Elements::$elements[ $name ]['controls'] ?? [] );

		if ( is_array( $controls ) && array_key_exists( 'customAttributes', $controls ) ) {
			return $element;
		}

		if ( array_key_exists( '_attributes', $settings ) ) {
			return Error::invalid_param(
				'settings.customAttributes',
				'customAttributes or the canonical _attributes key, but not both',
				$settings['customAttributes']
			);
		}

		if ( ! is_array( $settings['customAttributes'] ) ) {
			return Error::invalid_param( 'settings.customAttributes', 'an array of custom attribute records', $settings['customAttributes'] );
		}

		$settings['_attributes'] = $settings['customAttributes'];
		unset( $settings['customAttributes'] );
		$element['settings'] = $settings;

		return $element;
	}

	/**
	 * Generate a 6-character alphanumeric ID
	 *
	 * Matches Bricks' ID format: [a-z0-9]{6}
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	public static function generate_id() {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$id .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
		}

		return $id;
	}

	/**
	 * Resolve the root element name for a component instance.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @return string
	 */
	private static function component_root_name( string $component_id ): string {
		$component = \Bricks\Helpers::get_component_by_cid( $component_id );
		$root      = is_array( $component['elements'] ?? null ) ? reset( $component['elements'] ) : null;

		return is_array( $root ) && ! empty( $root['name'] ) ? (string) $root['name'] : 'div';
	}

	/**
	 * Normalize custom selector objects for element/global class styling.
	 *
	 * @since 2.4
	 *
	 * @param array $element Incoming element payload.
	 * @return array|\WP_Error Normalized selectors, or error on invalid shape.
	 */
	private static function normalize_selectors( $element ) {
		if ( ! array_key_exists( 'selectors', $element ) ) {
			return [];
		}

		if ( ! is_array( $element['selectors'] ) ) {
			return new \WP_Error( 'invalid_element_selectors', 'Element "selectors" must be an array.' );
		}

		$selectors = [];

		foreach ( $element['selectors'] as $index => $selector ) {
			if ( ! is_array( $selector ) ) {
				return new \WP_Error( 'invalid_element_selector', "Element selector at index {$index} must be an object." );
			}

			$selector_value = isset( $selector['selector'] ) ? trim( (string) $selector['selector'] ) : '';

			if ( $selector_value === '' ) {
				return new \WP_Error( 'invalid_element_selector', "Element selector at index {$index} must include a non-empty selector string." );
			}

			$normalized = [
				'id'       => ! empty( $selector['id'] ) ? (string) $selector['id'] : \Bricks\Helpers::generate_random_id( false ),
				'selector' => $selector_value,
				'settings' => [],
			];

			if ( isset( $selector['settings'] ) ) {
				if ( ! is_array( $selector['settings'] ) ) {
					return new \WP_Error( 'invalid_element_selector_settings', "Element selector settings at index {$index} must be an object." );
				}

				$normalized['settings'] = $selector['settings'];
			}

			if ( isset( $selector['label'] ) && trim( (string) $selector['label'] ) !== '' ) {
				$normalized['label'] = trim( (string) $selector['label'] );
			}

			$selectors[] = $normalized;
		}

		return $selectors;
	}

	/**
	 * Check whether a component instance uses nested element objects in slotChildren.
	 *
	 * @since 2.4
	 *
	 * @param array $element Incoming element payload.
	 * @return bool
	 */
	private static function has_nested_slot_children( array $element ): bool {
		if ( empty( $element['slotChildren'] ) || ! is_array( $element['slotChildren'] ) ) {
			return false;
		}

		foreach ( $element['slotChildren'] as $children ) {
			if ( ! is_array( $children ) ) {
				continue;
			}

			foreach ( $children as $child ) {
				if ( is_array( $child ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if an element type is nestable
	 *
	 * @since 2.4
	 *
	 * @param string $name Element type name.
	 * @return bool
	 */
	public static function is_nestable( $name ) {
		$element_data = \Bricks\Elements::$elements[ $name ] ?? [];

		// If element is loaded with full data, check nestable flag
		if ( isset( $element_data['nestable'] ) ) {
			return ! empty( $element_data['nestable'] );
		}

		// Fallback: known nestable elements
		$known_nestable = [
			'section',
			'container',
			'div',
			'block',
			'accordion',
			'accordion-nested',
			'tabs-nested',
			'slider-nested',
			'offcanvas',
			'popup',
			'form',
			'nav-nested',
			'dropdown',
			'mega-menu',
		];

		return in_array( $name, $known_nestable, true );
	}
}

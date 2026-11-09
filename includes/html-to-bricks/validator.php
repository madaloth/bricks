<?php
/**
 * HTML to Bricks Validator
 *
 * Validates generated Bricks elements and global classes for structural
 * correctness. Purely diagnostic — populates validation results but does
 * not block conversion success.
 *
 * PHP port of src/vue/utils/bricksValidator/structural.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structural validator for Bricks element and global class data.
 *
 * @since 2.4
 */
class Html_To_Bricks_Validator {

	/**
	 * Error codes for validation errors.
	 *
	 * @since 2.4
	 * @var array
	 */
	const ERROR_CODES = [
		// Element errors.
		'INVALID_ID'             => 'INVALID_ID',
		'INVALID_ELEMENT_TYPE'   => 'INVALID_ELEMENT_TYPE',
		'INVALID_SETTINGS'       => 'INVALID_SETTINGS',
		'INVALID_CHILDREN'       => 'INVALID_CHILDREN',
		'INVALID_PARENT'         => 'INVALID_PARENT',
		'INVALID_TAG'            => 'INVALID_TAG',
		'ORPHAN_ELEMENT'         => 'ORPHAN_ELEMENT',
		'MISSING_CHILD'          => 'MISSING_CHILD',
		'CIRCULAR_REFERENCE'     => 'CIRCULAR_REFERENCE',
		'DUPLICATE_ID'           => 'DUPLICATE_ID',

		// Global class errors.
		'INVALID_CLASS_ID'       => 'INVALID_CLASS_ID',
		'INVALID_CLASS_NAME'     => 'INVALID_CLASS_NAME',
		'RESERVED_CLASS_NAME'    => 'RESERVED_CLASS_NAME',
		'INVALID_CLASS_SETTINGS' => 'INVALID_CLASS_SETTINGS',
		'CLASS_NAME_TOO_LONG'    => 'CLASS_NAME_TOO_LONG',

		// Warning codes.
		'UNEXPECTED_CHILDREN'    => 'UNEXPECTED_CHILDREN',
		'EMPTY_CHILDREN'         => 'EMPTY_CHILDREN',
	];

	/**
	 * Reserved class name prefixes.
	 *
	 * @since 2.4
	 * @var array
	 */
	const RESERVED_CLASS_PREFIXES = [ 'brxe-', 'bricks-', 'brx-' ];

	/**
	 * Maximum class name length.
	 *
	 * @since 2.4
	 * @var int
	 */
	const MAX_CLASS_NAME_LENGTH = 100;

	/**
	 * Valid characters pattern for class names.
	 *
	 * @since 2.4
	 * @var string
	 */
	const CLASS_NAME_PATTERN = '/^[a-zA-Z_\-][a-zA-Z0-9_\-]*$/';

	/**
	 * Required properties for a global class object.
	 *
	 * @since 2.4
	 * @var array
	 */
	const GLOBAL_CLASS_REQUIRED_PROPS = [ 'id', 'name' ];

	/**
	 * Get nestable element types from element mappings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_nestable_elements() {
		static $nestable = null;

		if ( $nestable !== null ) {
			return $nestable;
		}

		$nestable = [];
		$mappings = Html_To_Bricks_Element_Mappings::get_mappings();

		foreach ( $mappings as $name => $mapping ) {
			if ( ! empty( $mapping['nestable'] ) ) {
				$nestable[] = $name;
			}
		}

		return $nestable;
	}

	/**
	 * Get valid tags per element type from element mappings.
	 *
	 * @since 2.4
	 *
	 * @return array Map of element name => array of valid tags.
	 */
	private static function get_element_valid_tags() {
		static $valid_tags = null;

		if ( $valid_tags !== null ) {
			return $valid_tags;
		}

		$valid_tags = [];
		$mappings   = Html_To_Bricks_Element_Mappings::get_mappings();

		foreach ( $mappings as $name => $mapping ) {
			if ( ! empty( $mapping['supportedTags'] ) ) {
				$valid_tags[ $name ] = $mapping['supportedTags'];
			}
		}

		return $valid_tags;
	}

	/**
	 * Get known element types from element mappings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_element_types() {
		static $types = null;

		if ( $types !== null ) {
			return $types;
		}

		$types = array_keys( Html_To_Bricks_Element_Mappings::get_mappings() );

		return $types;
	}

	/**
	 * Create a validation error array.
	 *
	 * @since 2.4
	 *
	 * @param string $code    Error code from ERROR_CODES.
	 * @param string $message Human-readable error message.
	 * @param array  $details Additional error details.
	 * @return array
	 */
	private static function create_error( $code, $message, $details = [] ) {
		return array_merge(
			[
				'code'    => $code,
				'message' => $message,
			],
			$details
		);
	}

	/**
	 * Create a validation warning array.
	 *
	 * @since 2.4
	 *
	 * @param string $code    Warning code.
	 * @param string $message Human-readable warning message.
	 * @param array  $details Additional warning details.
	 * @return array
	 */
	private static function create_warning( $code, $message, $details = [] ) {
		return array_merge(
			[
				'code'    => $code,
				'message' => $message,
			],
			$details
		);
	}

	/**
	 * Validate a single Bricks element.
	 *
	 * @since 2.4
	 *
	 * @param mixed $element Element to validate.
	 * @param array $options {
	 *     Validation options.
	 *
	 *     @type bool $strict             If true, treat warnings as errors.
	 *     @type bool $allow_unknown_types If true, don't error on unknown element types.
	 * }
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $isValid  Whether element passed validation.
	 *     @type array $errors   Validation errors.
	 *     @type array $warnings Validation warnings.
	 * }
	 */
	public static function validate_element( $element, $options = [] ) {
		$strict              = ! empty( $options['strict'] );
		$allow_unknown_types = ! empty( $options['allow_unknown_types'] );
		$errors              = [];
		$warnings            = [];

		// Element must be an array (object equivalent).
		if ( ! is_array( $element ) ) {
			$errors[] = self::create_error( self::ERROR_CODES['INVALID_SETTINGS'], 'Element must be a non-null object' );

			return [
				'isValid'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			];
		}

		// Validate ID (required, must be string).
		if ( empty( $element['id'] ) || ! is_string( $element['id'] ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_ID'],
				'Element must have a string id',
				[
					'received' => gettype( isset( $element['id'] ) ? $element['id'] : null ),
					'value'    => isset( $element['id'] ) ? $element['id'] : null,
				]
			);
		}

		// Validate name (required, must be valid element type).
		if ( empty( $element['name'] ) || ! is_string( $element['name'] ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_ELEMENT_TYPE'],
				'Element must have a string name',
				[ 'received' => gettype( isset( $element['name'] ) ? $element['name'] : null ) ]
			);
		} elseif ( ! $allow_unknown_types && ! in_array( $element['name'], self::get_element_types(), true ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_ELEMENT_TYPE'],
				'Unknown element type: ' . $element['name'],
				[ 'elementType' => $element['name'] ]
			);
		}

		// Validate settings (must be array if present).
		if ( isset( $element['settings'] ) ) {
			if ( ! is_array( $element['settings'] ) ) {
				$errors[] = self::create_error(
					self::ERROR_CODES['INVALID_SETTINGS'],
					'Settings must be an object',
					[ 'received' => gettype( $element['settings'] ) ]
				);
			} else {
				// Validate tag setting if present and element type has valid tags.
				$valid_tags = self::get_element_valid_tags();

				if ( isset( $element['name'] ) && isset( $valid_tags[ $element['name'] ] ) && ! empty( $element['settings']['tag'] ) ) {
					if ( ! in_array( $element['settings']['tag'], $valid_tags[ $element['name'] ], true ) ) {
						$warnings[] = self::create_warning(
							self::ERROR_CODES['INVALID_TAG'],
							"Tag '{$element['settings']['tag']}' may not be valid for element type '{$element['name']}'",
							[
								'tag'         => $element['settings']['tag'],
								'elementType' => $element['name'],
								'validTags'   => $valid_tags[ $element['name'] ],
							]
						);
					}
				}
			}
		}

		// Validate children (must be array if present).
		if ( isset( $element['children'] ) ) {
			if ( ! is_array( $element['children'] ) ) {
				$errors[] = self::create_error(
					self::ERROR_CODES['INVALID_CHILDREN'],
					'Children must be an array',
					[ 'received' => gettype( $element['children'] ) ]
				);
			} else {
				$nestable = self::get_nestable_elements();

				// Check if element type supports children.
				if ( ! empty( $element['name'] ) && ! in_array( $element['name'], $nestable, true ) ) {
					$warnings[] = self::create_warning(
						self::ERROR_CODES['UNEXPECTED_CHILDREN'],
						"Element type '{$element['name']}' is not nestable but has children",
						[
							'elementType'   => $element['name'],
							'childrenCount' => count( $element['children'] ),
						]
					);
				}

				// Validate each child ID is a string.
				foreach ( $element['children'] as $index => $child_id ) {
					if ( ! is_string( $child_id ) ) {
						$errors[] = self::create_error(
							self::ERROR_CODES['INVALID_CHILDREN'],
							"Child at index {$index} must be a string id",
							[
								'index'    => $index,
								'received' => gettype( $child_id ),
							]
						);
					}
				}

				// Warn if children array is empty on a nestable element.
				if ( empty( $element['children'] ) && ! empty( $element['name'] ) && in_array( $element['name'], $nestable, true ) ) {
					$warnings[] = self::create_warning(
						self::ERROR_CODES['EMPTY_CHILDREN'],
						'Nestable element has empty children array',
						[ 'elementType' => $element['name'] ]
					);
				}
			}
		}

		// Validate parent (must be string, 0, null, or absent).
		if ( isset( $element['parent'] ) && $element['parent'] !== null ) {
			if ( $element['parent'] !== 0 && ! is_string( $element['parent'] ) ) {
				$errors[] = self::create_error(
					self::ERROR_CODES['INVALID_PARENT'],
					'Parent must be a string id, 0, null, or undefined',
					[
						'received' => gettype( $element['parent'] ),
						'value'    => $element['parent'],
					]
				);
			}
		}

		// In strict mode, warnings become errors.
		if ( $strict && ! empty( $warnings ) ) {
			$errors   = array_merge( $errors, $warnings );
			$warnings = [];
		}

		return [
			'isValid'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Validate an array of Bricks elements.
	 *
	 * @since 2.4
	 *
	 * @param mixed $elements Array of elements to validate.
	 * @param array $options  Validation options (passed to validate_element).
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $isValid  Whether all elements passed validation.
	 *     @type array $errors   Validation errors.
	 *     @type array $warnings Validation warnings.
	 * }
	 */
	public static function validate_elements( $elements, $options = [] ) {
		$errors   = [];
		$warnings = [];

		// Must be an array.
		if ( ! is_array( $elements ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_SETTINGS'],
				'Elements must be an array',
				[ 'received' => gettype( $elements ) ]
			);

			return [
				'isValid'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			];
		}

		// Check for duplicate IDs.
		$ids        = [];
		$duplicates = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				if ( isset( $ids[ $element['id'] ] ) ) {
					$duplicates[ $element['id'] ] = true;
				}
				$ids[ $element['id'] ] = true;
			}
		}

		if ( ! empty( $duplicates ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['DUPLICATE_ID'],
				'Duplicate element IDs found',
				[ 'duplicateIds' => array_keys( $duplicates ) ]
			);
		}

		// Validate each element.
		foreach ( $elements as $index => $element ) {
			$result = self::validate_element( $element, $options );

			if ( ! $result['isValid'] ) {
				foreach ( $result['errors'] as $error ) {
					$errors[] = array_merge(
						$error,
						[
							'elementIndex' => $index,
							'elementId'    => is_array( $element ) && isset( $element['id'] ) ? $element['id'] : null,
						]
					);
				}
			}

			foreach ( $result['warnings'] as $warning ) {
				$warnings[] = array_merge(
					$warning,
					[
						'elementIndex' => $index,
						'elementId'    => is_array( $element ) && isset( $element['id'] ) ? $element['id'] : null,
					]
				);
			}
		}

		return [
			'isValid'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Validate parent-child relationships in an element tree.
	 *
	 * Checks for:
	 * - Orphan elements (parent points to non-existent element)
	 * - Missing children (children array references non-existent elements)
	 * - Parent-child back-reference mismatches
	 * - Circular references (via DFS)
	 *
	 * @since 2.4
	 *
	 * @param mixed $elements Array of elements to validate.
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $isValid  Whether tree is structurally valid.
	 *     @type array $errors   Validation errors.
	 *     @type array $warnings Validation warnings.
	 * }
	 */
	public static function validate_element_tree( $elements ) {
		$errors   = [];
		$warnings = [];

		if ( ! is_array( $elements ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_SETTINGS'],
				'Elements must be an array',
				[ 'received' => gettype( $elements ) ]
			);

			return [
				'isValid'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			];
		}

		// Build ID lookup map.
		$element_map = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$element_map[ $element['id'] ] = $element;
			}
		}

		// Check each element.
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}

			// Check parent exists (if not root).
			if ( ! empty( $element['parent'] ) && $element['parent'] !== 0 ) {
				if ( ! isset( $element_map[ $element['parent'] ] ) ) {
					$errors[] = self::create_error(
						self::ERROR_CODES['ORPHAN_ELEMENT'],
						"Element '{$element['id']}' references non-existent parent '{$element['parent']}'",
						[
							'elementId' => $element['id'],
							'parentId'  => $element['parent'],
						]
					);
				}
			}

			// Check children exist.
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as $child_id ) {
					if ( ! isset( $element_map[ $child_id ] ) ) {
						$errors[] = self::create_error(
							self::ERROR_CODES['MISSING_CHILD'],
							"Element '{$element['id']}' references non-existent child '{$child_id}'",
							[
								'elementId' => $element['id'],
								'childId'   => $child_id,
							]
						);
					} else {
						// Verify child's parent points back.
						$child = $element_map[ $child_id ];

						if ( isset( $child['parent'] ) && $child['parent'] !== $element['id'] ) {
							$warnings[] = self::create_warning(
								self::ERROR_CODES['INVALID_PARENT'],
								"Child '{$child_id}' parent does not match. Expected '{$element['id']}', got '{$child['parent']}'",
								[
									'childId'        => $child_id,
									'expectedParent' => $element['id'],
									'actualParent'   => $child['parent'],
								]
							);
						}
					}
				}
			}
		}

		// Check for circular references via DFS.
		$visited         = [];
		$recursion_stack = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) && ( empty( $element['parent'] ) || $element['parent'] === 0 ) ) {
				self::detect_cycles( $element['id'], $element_map, $visited, $recursion_stack, $errors );
			}
		}

		return [
			'isValid'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * DFS cycle detection helper.
	 *
	 * @since 2.4
	 *
	 * @param string $element_id      Current element ID.
	 * @param array  $element_map     Map of id => element.
	 * @param array  $visited         Set of already-visited IDs (by reference).
	 * @param array  $recursion_stack Current DFS path (by reference).
	 * @param array  $errors          Error accumulator (by reference).
	 * @param array  $path            Current traversal path for error reporting.
	 * @return bool Whether a cycle was detected.
	 */
	private static function detect_cycles( $element_id, &$element_map, &$visited, &$recursion_stack, &$errors, $path = [] ) {
		if ( isset( $recursion_stack[ $element_id ] ) ) {
			$cycle_path = array_merge( $path, [ $element_id ] );
			$errors[]   = self::create_error(
				self::ERROR_CODES['CIRCULAR_REFERENCE'],
				'Circular reference detected: ' . implode( ' -> ', $cycle_path ),
				[ 'cycle' => $cycle_path ]
			);

			return true;
		}

		if ( isset( $visited[ $element_id ] ) ) {
			return false;
		}

		$visited[ $element_id ]         = true;
		$recursion_stack[ $element_id ] = true;

		if ( isset( $element_map[ $element_id ] ) ) {
			$element = $element_map[ $element_id ];

			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as $child_id ) {
					if ( self::detect_cycles( $child_id, $element_map, $visited, $recursion_stack, $errors, array_merge( $path, [ $element_id ] ) ) ) {
						return true;
					}
				}
			}
		}

		unset( $recursion_stack[ $element_id ] );

		return false;
	}

	/**
	 * Validate a single global class object.
	 *
	 * @since 2.4
	 *
	 * @param mixed $global_class Global class object to validate.
	 * @param array $options {
	 *     Validation options.
	 *
	 *     @type bool $strict If true, also validate selectors structure.
	 * }
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $isValid  Whether class passed validation.
	 *     @type array $errors   Validation errors.
	 *     @type array $warnings Validation warnings.
	 * }
	 */
	public static function validate_global_class( $global_class, $options = [] ) {
		$strict   = ! empty( $options['strict'] );
		$errors   = [];
		$warnings = [];

		// Must be an array (object equivalent).
		if ( ! is_array( $global_class ) ) {
			$errors[] = self::create_error( self::ERROR_CODES['INVALID_CLASS_SETTINGS'], 'Global class must be an object' );

			return [
				'isValid'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			];
		}

		// Validate required properties.
		foreach ( self::GLOBAL_CLASS_REQUIRED_PROPS as $prop ) {
			if ( empty( $global_class[ $prop ] ) ) {
				$code     = $prop === 'id' ? self::ERROR_CODES['INVALID_CLASS_ID'] : self::ERROR_CODES['INVALID_CLASS_NAME'];
				$errors[] = self::create_error(
					$code,
					"Global class must have a '{$prop}' property",
					[ 'property' => $prop ]
				);
			}
		}

		// Validate ID format.
		if ( isset( $global_class['id'] ) && ! empty( $global_class['id'] ) && ! is_string( $global_class['id'] ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_CLASS_ID'],
				'Global class id must be a string',
				[ 'received' => gettype( $global_class['id'] ) ]
			);
		}

		// Validate name.
		if ( ! empty( $global_class['name'] ) ) {
			if ( ! is_string( $global_class['name'] ) ) {
				$errors[] = self::create_error(
					self::ERROR_CODES['INVALID_CLASS_NAME'],
					'Global class name must be a string',
					[ 'received' => gettype( $global_class['name'] ) ]
				);
			} else {
				// Check length.
				if ( strlen( $global_class['name'] ) > self::MAX_CLASS_NAME_LENGTH ) {
					$errors[] = self::create_error(
						self::ERROR_CODES['CLASS_NAME_TOO_LONG'],
						'Class name exceeds maximum length of ' . self::MAX_CLASS_NAME_LENGTH,
						[
							'length'    => strlen( $global_class['name'] ),
							'maxLength' => self::MAX_CLASS_NAME_LENGTH,
						]
					);
				}

				// Check for reserved prefixes.
				foreach ( self::RESERVED_CLASS_PREFIXES as $prefix ) {
					if ( strpos( $global_class['name'], $prefix ) === 0 ) {
						$warnings[] = self::create_warning(
							self::ERROR_CODES['RESERVED_CLASS_NAME'],
							"Class name starts with reserved prefix '{$prefix}'",
							[
								'className' => $global_class['name'],
								'prefix'    => $prefix,
							]
						);
						break;
					}
				}

				// Check valid characters (warning only).
				if ( ! preg_match( self::CLASS_NAME_PATTERN, $global_class['name'] ) ) {
					$warnings[] = self::create_warning(
						self::ERROR_CODES['INVALID_CLASS_NAME'],
						'Class name contains unusual characters that may cause issues',
						[ 'className' => $global_class['name'] ]
					);
				}
			}
		}

		// Validate settings if present.
		if ( isset( $global_class['settings'] ) && ! is_array( $global_class['settings'] ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_CLASS_SETTINGS'],
				'Global class settings must be an object',
				[ 'received' => gettype( $global_class['settings'] ) ]
			);
		}

		// Validate selectors if present.
		if ( isset( $global_class['selectors'] ) ) {
			if ( ! is_array( $global_class['selectors'] ) ) {
				$errors[] = self::create_error(
					self::ERROR_CODES['INVALID_CLASS_SETTINGS'],
					'Global class selectors must be an array',
					[ 'received' => gettype( $global_class['selectors'] ) ]
				);
			} elseif ( $strict ) {
				foreach ( $global_class['selectors'] as $index => $selector ) {
					if ( ! is_array( $selector ) ) {
						$errors[] = self::create_error(
							self::ERROR_CODES['INVALID_CLASS_SETTINGS'],
							"Selector at index {$index} must be an object",
							[ 'index' => $index ]
						);
					} elseif ( empty( $selector['selector'] ) || ! is_string( $selector['selector'] ) ) {
						$errors[] = self::create_error(
							self::ERROR_CODES['INVALID_CLASS_SETTINGS'],
							"Selector at index {$index} must have a string 'selector' property",
							[ 'index' => $index ]
						);
					}
				}
			}
		}

		return [
			'isValid'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Validate an array of global classes.
	 *
	 * @since 2.4
	 *
	 * @param mixed $global_classes Array of global class objects to validate.
	 * @param array $options        Validation options (passed to validate_global_class).
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool  $isValid  Whether all classes passed validation.
	 *     @type array $errors   Validation errors.
	 *     @type array $warnings Validation warnings.
	 * }
	 */
	public static function validate_global_classes( $global_classes, $options = [] ) {
		$errors   = [];
		$warnings = [];

		if ( ! is_array( $global_classes ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['INVALID_CLASS_SETTINGS'],
				'Global classes must be an array',
				[ 'received' => gettype( $global_classes ) ]
			);

			return [
				'isValid'  => false,
				'errors'   => $errors,
				'warnings' => $warnings,
			];
		}

		// Check for duplicate IDs and names.
		$ids             = [];
		$names           = [];
		$duplicate_ids   = [];
		$duplicate_names = [];

		foreach ( $global_classes as $gc ) {
			if ( is_array( $gc ) ) {
				if ( ! empty( $gc['id'] ) ) {
					if ( isset( $ids[ $gc['id'] ] ) ) {
						$duplicate_ids[ $gc['id'] ] = true;
					}
					$ids[ $gc['id'] ] = true;
				}

				if ( ! empty( $gc['name'] ) ) {
					if ( isset( $names[ $gc['name'] ] ) ) {
						$duplicate_names[ $gc['name'] ] = true;
					}
					$names[ $gc['name'] ] = true;
				}
			}
		}

		if ( ! empty( $duplicate_ids ) ) {
			$errors[] = self::create_error(
				self::ERROR_CODES['DUPLICATE_ID'],
				'Duplicate global class IDs found',
				[ 'duplicateIds' => array_keys( $duplicate_ids ) ]
			);
		}

		if ( ! empty( $duplicate_names ) ) {
			$warnings[] = self::create_warning(
				self::ERROR_CODES['INVALID_CLASS_NAME'],
				'Duplicate global class names found',
				[ 'duplicateNames' => array_keys( $duplicate_names ) ]
			);
		}

		// Validate each global class.
		foreach ( $global_classes as $index => $gc ) {
			$result = self::validate_global_class( $gc, $options );

			if ( ! $result['isValid'] ) {
				foreach ( $result['errors'] as $error ) {
					$errors[] = array_merge(
						$error,
						[
							'classIndex' => $index,
							'classId'    => is_array( $gc ) && isset( $gc['id'] ) ? $gc['id'] : null,
							'className'  => is_array( $gc ) && isset( $gc['name'] ) ? $gc['name'] : null,
						]
					);
				}
			}

			foreach ( $result['warnings'] as $warning ) {
				$warnings[] = array_merge(
					$warning,
					[
						'classIndex' => $index,
						'classId'    => is_array( $gc ) && isset( $gc['id'] ) ? $gc['id'] : null,
						'className'  => is_array( $gc ) && isset( $gc['name'] ) ? $gc['name'] : null,
					]
				);
			}
		}

		return [
			'isValid'  => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Validate full conversion results.
	 *
	 * Runs element, tree, and global class validation. This is the main
	 * entry point called from the converter pipeline.
	 *
	 * @since 2.4
	 *
	 * @param array $elements       Generated Bricks elements.
	 * @param array $global_classes Generated global classes.
	 * @return array {
	 *     Combined validation result.
	 *
	 *     @type bool  $isValid  Whether all validation passed.
	 *     @type array $elements Element validation result.
	 *     @type array $tree     Tree validation result.
	 *     @type array $classes  Class validation result.
	 *     @type array $errors   All errors combined.
	 *     @type array $warnings All warnings combined.
	 * }
	 */
	public static function validate_conversion( $elements, $global_classes ) {
		$elements_result = self::validate_elements( $elements );
		$tree_result     = self::validate_element_tree( $elements );
		$classes_result  = self::validate_global_classes( $global_classes );

		return [
			'isValid'  => $elements_result['isValid'] && $tree_result['isValid'] && $classes_result['isValid'],
			'elements' => $elements_result,
			'tree'     => $tree_result,
			'classes'  => $classes_result,
			'errors'   => array_merge( $elements_result['errors'], $tree_result['errors'], $classes_result['errors'] ),
			'warnings' => array_merge( $elements_result['warnings'], $tree_result['warnings'], $classes_result['warnings'] ),
		];
	}
}

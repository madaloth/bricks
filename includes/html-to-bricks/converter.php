<?php
/**
 * HTML to Bricks Converter - Main Orchestrator
 *
 * Main entry point for converting HTML/CSS/JS into native Bricks elements.
 * Coordinates the 12-step pipeline using all sub-modules.
 *
 * PHP port of src/vue/utils/htmlToBricks/index.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main orchestrator for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Converter {

	/**
	 * Always-matching pseudo-class used as a constant authored specificity offset.
	 *
	 * `:nth-child(0)` matches no element, so its negation also matches document
	 * roots while contributing one pseudo-class of specificity.
	 *
	 * @since 2.4
	 * @var string
	 */
	private const AUTHORED_SPECIFICITY_OFFSET = ':not(:nth-child(0))';

	/**
	 * Animation keywords used to identify animation names in shorthand.
	 *
	 * @since 2.4
	 * @var array
	 */
	private static $animation_keywords = [
		'normal'            => true,
		'reverse'           => true,
		'alternate'         => true,
		'alternate-reverse' => true,
		'forwards'          => true,
		'backwards'         => true,
		'both'              => true,
		'running'           => true,
		'paused'            => true,
		'infinite'          => true,
		'linear'            => true,
		'ease'              => true,
		'ease-in'           => true,
		'ease-out'          => true,
		'ease-in-out'       => true,
		'step-start'        => true,
		'step-end'          => true,
		'initial'           => true,
		'inherit'           => true,
		'unset'             => true,
		'revert'            => true,
		'revert-layer'      => true,
	];

	/**
	 * Convert HTML string to Bricks elements.
	 *
	 * Main entry point for HTML-to-Bricks conversion. Runs the full 12-step
	 * pipeline: parse HTML, convert DOM, parse CSS, process classes/variables,
	 * apply styles, and create code elements.
	 *
	 * @since 2.4
	 *
	 * @param string $html_string Raw HTML string to convert.
	 * @param array  $options {
	 *     Optional conversion options.
	 *
	 *     @type bool  $create_global_classes Whether to create global classes. Default true.
	 *     @type bool  $extract_variables     Whether to extract CSS variables. Default true.
	 *     @type bool  $validate              Whether to validate conversion results. Default true.
	 *     @type array $existing_classes      Existing global classes for conflict detection.
	 *     @type array $existing_variables    Existing global variables for deduplication.
	 *     @type string $base_breakpoint_key  Breakpoint key for base CSS rules.
	 *     @type float $source_root_font_size_px Source document root font size in pixels.
	 *     @type float $target_root_font_size_px Target Bricks root font size in pixels.
	 *     @type bool  $scope_global_css_to_roots Scope otherwise-global CSS to page roots.
	 *     @type bool  $preserve_html_defaults Preserve ordinary semantic HTML defaults.
	 * }
	 * @return array {
	 *     Conversion result.
	 *
	 *     @type bool   $success          Whether conversion was successful.
	 *     @type array  $elements         Flat array of Bricks elements.
	 *     @type array  $global_classes    New global class objects.
	 *     @type array  $global_variables  New global variable objects.
	 *     @type array  $skipped_global_variables Existing-name variables retained for caller reconciliation.
	 *     @type bool   $has_executable_js Whether HTML contained inline or external JS.
	 *     @type array  $class_mapping     Map of className => globalClassId.
	 *     @type object $errors            Error collector instance.
	 *     @type array  $validation        Validation result (if validate option is true).
	 * }
	 */
	public static function convert( $html_string, $options = [] ) {
		$create_global_classes  = isset( $options['create_global_classes'] ) ? $options['create_global_classes'] : true;
		$extract_variables      = isset( $options['extract_variables'] ) ? $options['extract_variables'] : true;
		$validate               = isset( $options['validate'] ) ? $options['validate'] : true;
		$existing_classes       = isset( $options['existing_classes'] ) ? $options['existing_classes'] : null;
		$existing_variables     = isset( $options['existing_variables'] ) ? $options['existing_variables'] : null;
		$base_breakpoint_key    = isset( $options['base_breakpoint_key'] )
			? $options['base_breakpoint_key']
			: ( isset( $options['baseBreakpointKey'] ) ? $options['baseBreakpointKey'] : '' );
		$rem_normalization      = self::get_rem_normalization_options( $options );
		$preserve_html_defaults = ! empty( $options['preserve_html_defaults'] );
		$scope_global_css       = ! empty( $options['scope_global_css_to_roots'] ) || $preserve_html_defaults;

		$errors = new Html_To_Bricks_Error_Collector();

		$result = [
			'success'                  => false,
			'elements'                 => [],
			'global_classes'           => [],
			'global_variables'         => [],
			'skipped_global_variables' => [],
			'has_executable_js'        => false,
			'class_mapping'            => [],
			'errors'                   => $errors,
			'rem_normalization'        => self::build_rem_normalization_metadata( $rem_normalization, 0 ),
			'semantic_defaults'        => [
				'applied' => false,
				'profile' => null,
				'tags'    => [],
			],
		];

		// Step 1: Parse HTML
		$parse_result = Html_To_Bricks_Html_Parser::parse_html( $html_string );
		$errors->merge( $parse_result['errors'] );

		// Check if there are any resources even if body is empty.
		// Note: Html_To_Bricks_Html_Parser returns snake_case keys; the JS
		// source used camelCase. Read snake_case here (the producer's contract).
		$has_resources = ( ! empty( $parse_result['scripts'] ) )
			|| ( ! empty( $parse_result['external_scripts'] ) )
			|| ( ! empty( $parse_result['styles'] ) )
			|| ( ! empty( $parse_result['external_stylesheets'] ) );

		if ( ! $parse_result['success'] && ! $has_resources ) {
			return $result;
		}

		// Step 2: Convert DOM to Bricks elements
		$elements = ! empty( $parse_result['body_content'] ) ? Html_To_Bricks_Element_Mapper::convert_to_elements( $parse_result['body_content'], $errors ) : [];

		if ( empty( $elements ) && ! $has_resources ) {
			return $result;
		}

		// Step 3: Parse CSS
		$authored_css = implode( "\n\n", $parse_result['styles'] );
		$semantic_css = '';

		if ( $preserve_html_defaults ) {
			$semantic_defaults           = self::build_semantic_defaults( $elements );
			$result['semantic_defaults'] = $semantic_defaults['metadata'];
			$semantic_css                = $semantic_defaults['css'];
		}

		$all_css          = trim( implode( "\n\n", array_filter( [ $semantic_css, $authored_css ] ) ) );
		$rem_token_count  = Html_To_Bricks_Css_Parser::count_normalizable_rem_tokens( $all_css );
		$rem_token_count += self::count_inline_rem_tokens( $elements );

		if ( $rem_normalization ) {
			$semantic_css = Html_To_Bricks_Css_Parser::normalize_rem_units(
				$semantic_css,
				$rem_normalization['source_root_font_size_px'],
				$rem_normalization['target_root_font_size_px']
			);
			$authored_css = Html_To_Bricks_Css_Parser::normalize_rem_units(
				$authored_css,
				$rem_normalization['source_root_font_size_px'],
				$rem_normalization['target_root_font_size_px']
			);
		}

		$result['rem_normalization'] = self::build_rem_normalization_metadata( $rem_normalization, $rem_token_count );
		$semantic_rules              = Html_To_Bricks_Css_Parser::parse_css( $semantic_css );

		foreach ( $semantic_rules as &$semantic_rule ) {
			$semantic_rule['_bricksSemanticDefault'] = true;
		}

		unset( $semantic_rule );

		$css_rules              = array_merge( $semantic_rules, Html_To_Bricks_Css_Parser::parse_css( $authored_css ) );
		$base_source_typography = self::extract_effective_base_typography( $css_rules, $rem_normalization );

		if ( ! empty( $base_source_typography ) ) {
			$css_rules = self::remove_transferred_base_typography( $css_rules, array_keys( $base_source_typography ) );
		}

		// Step 4: Collect class names and IDs from elements
		$class_names = Html_To_Bricks_Class_Creator::collect_class_names( $elements );
		$element_ids = [];

		foreach ( $elements as $el ) {
			if ( ! empty( $el['settings']['_cssId'] ) ) {
				$element_ids[] = $el['settings']['_cssId'];
			}
		}

		// Step 5: Process global classes
		if ( $create_global_classes ) {
			$class_result             = Html_To_Bricks_Class_Creator::process_classes(
				$class_names,
				$css_rules,
				$existing_classes,
				[ 'base_breakpoint_key' => $base_breakpoint_key ]
			);
			$result['global_classes'] = array_merge(
				$class_result['newClasses'],
				isset( $class_result['conflictingClasses'] ) ? $class_result['conflictingClasses'] : []
			);
			$result['class_mapping']  = $class_result['classMap'];
		}

		// Step 5b: Extract and process CSS variables
		if ( $extract_variables ) {
			$variable_result                    = Html_To_Bricks_Variable_Parser::extract_and_process_variables( $css_rules, $existing_variables );
			$result['global_variables']         = $variable_result['newVariables'];
			$result['skipped_global_variables'] = $variable_result['skippedVariables'];
		}

		// Keep root declarations as ordinary fallback CSS when extraction is
		// intentionally disabled. Otherwise stripping them would lose source data.
		$css_rules_without_root_vars = $extract_variables
			? Html_To_Bricks_Variable_Parser::strip_root_css_variables_from_rules( $css_rules )
			: $css_rules;

		// Step 6: Apply class mapping to elements
		$updated_elements = Html_To_Bricks_Class_Creator::apply_class_mapping( $elements, $result['class_mapping'] );

		// Step 7: Process ID-specific CSS
		$id_rules = Html_To_Bricks_Css_Parser::extract_id_rules( $css_rules, $element_ids );
		if ( Html_To_Bricks_Class_Creator::has_opaque_effects( $css_rules ) ) {
			foreach ( $id_rules as &$rules ) {
				foreach ( $rules['targets'] as &$target ) {
					$target['preserveNativeEffects'] = true;
				}
				unset( $target );
			}
			unset( $rules );
		}
		$updated_elements = self::apply_id_styles( $updated_elements, $id_rules, $base_breakpoint_key );

		// Step 8: Convert inline styles to _cssCustom
		$updated_elements = self::convert_inline_styles( $updated_elements, $rem_normalization );
		$updated_elements = self::preserve_native_container_max_width_fidelity(
			$updated_elements,
			$result['global_classes'],
			is_array( $existing_classes ) ? $existing_classes : []
		);
		$updated_elements = self::apply_base_typography_to_roots( $updated_elements, $base_source_typography );

		// Step 9: Extract global CSS (not targeting specific classes/IDs)
		$extracted_class_names = $create_global_classes ? $class_names : [];
		$global_rules          = Html_To_Bricks_Css_Parser::extract_global_rules( $css_rules_without_root_vars, $extracted_class_names, $element_ids );
		if ( $scope_global_css && ! empty( $global_rules ) ) {
			$scoped_result    = self::scope_global_rules_to_roots( $updated_elements, $global_rules );
			$updated_elements = $scoped_result['elements'];
			$global_rules     = self::offset_authored_fallback_rules( $scoped_result['remainingRules'] );
		}
		$global_css = Html_To_Bricks_Css_Parser::rules_to_string( $global_rules );

		// Step 10: Create CSS/HTML Code element at top
		$css_code_element = Html_To_Bricks_Js_Handler::create_css_code_element( $global_css, $parse_result['external_stylesheets'] );

		// Step 11: Create external scripts Code element at bottom
		$external_scripts_code_element = Html_To_Bricks_Js_Handler::create_external_scripts_code_element( $parse_result['external_scripts'] );

		// Inline scripts are converted in-place; external scripts become a Code element at the end.
		$result['has_executable_js'] = ! empty( $parse_result['scripts'] ) || ! empty( $parse_result['external_scripts'] );

		// Insert CSS code element at beginning
		if ( $css_code_element ) {
			array_unshift( $updated_elements, $css_code_element );
		}

		// Append external scripts code element at end
		if ( $external_scripts_code_element ) {
			$updated_elements[] = $external_scripts_code_element;
		}

		$result['elements'] = $updated_elements;
		$result['success']  = ! empty( $result['elements'] );

		// Step 12: Validate if requested.
		if ( $validate ) {
			$result['validation'] = Html_To_Bricks_Validator::validate_conversion( $result['elements'], $result['global_classes'] );
		}

		return $result;
	}

	/**
	 * Convert CSS-only content to Bricks global classes.
	 *
	 * Used when input is CSS without HTML. Creates global classes and
	 * identifies existing elements that should be linked to these classes.
	 *
	 * @since 2.4
	 *
	 * @param string $css_string Raw CSS string.
	 * @param array  $options {
	 *     Optional conversion options.
	 *
	 *     @type array $existing_classes   Existing global classes.
	 *     @type array $existing_elements  Existing elements in canvas.
	 *     @type array $existing_variables Existing global variables.
	 *     @type string $base_breakpoint_key Breakpoint key for base CSS rules.
	 *     @type float $source_root_font_size_px Source document root font size in pixels.
	 *     @type float $target_root_font_size_px Target Bricks root font size in pixels.
	 * }
	 * @return array {
	 *     Conversion result.
	 *
	 *     @type bool   $success                      Whether conversion was successful.
	 *     @type array  $global_classes                New global class objects.
	 *     @type array  $global_variables              New global variable objects.
	 *     @type array  $skipped_global_variables      Existing-name variables retained for caller reconciliation.
	 *     @type array  $class_map                     Map of className => globalClassId.
	 *     @type array  $elements_to_update            Patch-only element updates that should be linked.
	 *     @type array  $generated_elements            Fallback Code elements.
	 *     @type array  $class_keyframe_css_by_class   Keyframes per class name.
	 *     @type string $remaining_css_for_code_element Remaining CSS for Code element.
	 *     @type object $errors                        Error collector instance.
	 * }
	 */
	public static function convert_css( $css_string, $options = [] ) {
		$existing_classes    = isset( $options['existing_classes'] ) ? $options['existing_classes'] : [];
		$existing_elements   = isset( $options['existing_elements'] ) ? $options['existing_elements'] : [];
		$existing_variables  = isset( $options['existing_variables'] ) ? $options['existing_variables'] : [];
		$base_breakpoint_key = isset( $options['base_breakpoint_key'] )
			? $options['base_breakpoint_key']
			: ( isset( $options['baseBreakpointKey'] ) ? $options['baseBreakpointKey'] : '' );
		$rem_normalization   = self::get_rem_normalization_options( $options );

		$errors = new Html_To_Bricks_Error_Collector();

		$result = [
			'success'                        => false,
			'global_classes'                 => [],
			'global_variables'               => [],
			'skipped_global_variables'       => [],
			'class_map'                      => [],
			'elements_to_update'             => [],
			'generated_elements'             => [],
			'class_keyframe_css_by_class'    => [],
			'remaining_css_for_code_element' => '',
			'errors'                         => $errors,
			'rem_normalization'              => self::build_rem_normalization_metadata( $rem_normalization, 0 ),
		];

		// Step 1: Parse CSS
		$rem_token_count = Html_To_Bricks_Css_Parser::count_normalizable_rem_tokens( $css_string );

		if ( $rem_normalization ) {
			$css_string = Html_To_Bricks_Css_Parser::normalize_rem_units(
				$css_string,
				$rem_normalization['source_root_font_size_px'],
				$rem_normalization['target_root_font_size_px']
			);
		}

		$result['rem_normalization'] = self::build_rem_normalization_metadata( $rem_normalization, $rem_token_count );
		$css_rules                   = Html_To_Bricks_Css_Parser::parse_css( $css_string );

		if ( empty( $css_rules ) ) {
			return $result;
		}

		// Step 1b: Extract and process CSS variables
		$variable_result                    = Html_To_Bricks_Variable_Parser::extract_and_process_variables( $css_rules, $existing_variables );
		$result['global_variables']         = $variable_result['newVariables'];
		$result['skipped_global_variables'] = $variable_result['skippedVariables'];

		// Remove imported root variables from rules
		$css_rules_without_root_vars = Html_To_Bricks_Variable_Parser::strip_root_css_variables_from_rules( $css_rules );

		// Step 2: Collect class names from CSS rules
		$class_names_from_css = Html_To_Bricks_Class_Creator::collect_class_names_from_rules( $css_rules );

		if ( empty( $class_names_from_css ) ) {
			$fallback_css          = trim( Html_To_Bricks_Css_Parser::rules_to_string( $css_rules_without_root_vars ) );
			$fallback_code_element = Html_To_Bricks_Js_Handler::process_scripts( [], [], $fallback_css, [] );

			if ( $fallback_code_element ) {
				$result['generated_elements']             = [ $fallback_code_element ];
				$result['remaining_css_for_code_element'] = $fallback_css;
				$result['success']                        = true;
				return $result;
			}

			// Still successful if variables were extracted
			if ( ! empty( $result['global_variables'] ) ) {
				$result['success'] = true;
				return $result;
			}

			return $result;
		}

		$class_rule_map     = Html_To_Bricks_Css_Parser::extract_class_rules( $css_rules, $class_names_from_css );
		$keyframe_ownership = self::map_keyframes_to_owning_classes( $class_rule_map, $css_rules );

		// Step 3: Create global classes
		$class_result = Html_To_Bricks_Class_Creator::process_classes(
			$class_names_from_css,
			$css_rules,
			$existing_classes,
			[ 'base_breakpoint_key' => $base_breakpoint_key ]
		);

		$result['global_classes']              = array_merge(
			$class_result['newClasses'],
			isset( $class_result['conflictingClasses'] ) ? $class_result['conflictingClasses'] : []
		);
		$result['class_keyframe_css_by_class'] = $keyframe_ownership['classKeyframeCssByClass'];
		$result['global_classes']              = self::append_owned_keyframes_to_classes(
			$result['global_classes'],
			$keyframe_ownership['classKeyframeCssByClass']
		);

		// CSS-only import keeps existing class mappings by name
		$existing_class_map = [];

		foreach ( $existing_classes as $css_class ) {
			if ( isset( $css_class['name'] ) && isset( $css_class['id'] ) ) {
				$existing_class_map[ $css_class['name'] ] = $css_class['id'];
			}
		}

		$result['class_map'] = $class_result['classMap'];

		foreach ( $class_names_from_css as $class_name ) {
			if ( ! isset( $result['class_map'][ $class_name ] ) && isset( $existing_class_map[ $class_name ] ) ) {
				$result['class_map'][ $class_name ] = $existing_class_map[ $class_name ];
			}
		}

		// Step 4: Find existing elements that use these classes
		$result['elements_to_update'] = self::build_css_only_element_updates(
			$existing_elements,
			Html_To_Bricks_Class_Creator::find_elements_with_classes( $existing_elements, $class_names_from_css ),
			$result['class_map']
		);

		$global_rules   = Html_To_Bricks_Css_Parser::extract_global_rules( $css_rules_without_root_vars, $class_names_from_css, [] );
		$fallback_rules = [];

		foreach ( $global_rules as $rule ) {
			// Keep non-keyframe rules
			if ( empty( $rule['isAtRule'] ) || ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] !== 'keyframes' ) ) {
				$fallback_rules[] = $rule;
				continue;
			}

			// Filter out keyframes that are already assigned to a class
			$keyframe_name = self::normalize_animation_name( isset( $rule['atRuleParams'] ) ? $rule['atRuleParams'] : '' );

			if ( ! isset( $keyframe_ownership['assignedKeyframeNames'][ $keyframe_name ] ) ) {
				$fallback_rules[] = $rule;
			}
		}

		$result['remaining_css_for_code_element'] = trim( Html_To_Bricks_Css_Parser::rules_to_string( $fallback_rules ) );
		$fallback_code_element                    = Html_To_Bricks_Js_Handler::process_scripts( [], [], $result['remaining_css_for_code_element'], [] );

		if ( $fallback_code_element ) {
			$result['generated_elements'] = [ $fallback_code_element ];
		}

		$result['success'] = true;

		return $result;
	}

	/**
	 * Build suggested element updates for CSS-only conversion.
	 *
	 * @since 2.4
	 *
	 * @param array $existing_elements Existing Bricks elements.
	 * @param array $matches           Matching element/class rows.
	 * @param array $class_map         Class name to global class ID map.
	 * @return array Patch-only suggested updates keyed by element ID context.
	 */
	private static function build_css_only_element_updates( $existing_elements, $matches, $class_map ) {
		if ( empty( $matches ) ) {
			return [];
		}

		$elements_by_id = [];

		foreach ( $existing_elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$elements_by_id[ $element['id'] ] = $element;
			}
		}

		$updates = [];

		foreach ( $matches as $match ) {
			$element_id = isset( $match['elementId'] ) ? $match['elementId'] : '';
			$element    = isset( $elements_by_id[ $element_id ] ) ? $elements_by_id[ $element_id ] : null;

			if ( ! $element ) {
				continue;
			}

			$settings         = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$matching_classes = isset( $match['matchingClasses'] ) && is_array( $match['matchingClasses'] ) ? $match['matchingClasses'] : [];
			$global_ids       = [];

			foreach ( $matching_classes as $class_name ) {
				if ( isset( $class_map[ $class_name ] ) ) {
					$global_ids[] = $class_map[ $class_name ];
				}
			}

			if ( empty( $global_ids ) ) {
				continue;
			}

			$existing_global_ids           = isset( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ? $settings['_cssGlobalClasses'] : [];
			$settings['_cssGlobalClasses'] = array_values( array_unique( array_merge( $existing_global_ids, $global_ids ) ) );

			$local_classes           = ! empty( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] )
				? array_filter( preg_split( '/\s+/', $settings['_cssClasses'] ) )
				: [];
			$remaining_local_classes = array_values( array_diff( $local_classes, $matching_classes ) );

			if ( empty( $remaining_local_classes ) ) {
				unset( $settings['_cssClasses'] );
			} else {
				$settings['_cssClasses'] = implode( ' ', $remaining_local_classes );
			}

			$settings_patch = [
				'_cssGlobalClasses' => $settings['_cssGlobalClasses'],
				'_cssClasses'       => $settings['_cssClasses'] ?? '',
			];

			$updates[] = [
				'elementId'       => $element_id,
				'matchingClasses' => $matching_classes,
				'globalClassIds'  => $global_ids,
				'settings'        => $settings_patch,
			];
		}

		return $updates;
	}

	/**
	 * Apply ID-specific CSS rules to elements.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements            Bricks elements.
	 * @param array  $id_rules            ID rules from CSS parser.
	 * @param string $base_breakpoint_key Configured Bricks base breakpoint key.
	 * @return array Updated elements.
	 */
	private static function apply_id_styles( $elements, $id_rules, $base_breakpoint_key = 'desktop' ) {
		return array_map(
			function ( $element ) use ( $id_rules, $base_breakpoint_key ) {
				$css_id = isset( $element['settings']['_cssId'] ) ? $element['settings']['_cssId'] : '';

				if ( ! $css_id || ! isset( $id_rules[ $css_id ] ) ) {
					return $element;
				}

				$rules = $id_rules[ $css_id ];

				$has_base    = ! empty( $rules['base'] );
				$has_pseudo  = ! empty( $rules['pseudo'] );
				$has_targets = ! empty( $rules['targets'] ) && is_array( $rules['targets'] );

				if ( ! $has_base && ! $has_pseudo && ! $has_targets ) {
					return $element;
				}

				$updated             = $element;
				$updated['settings'] = isset( $element['settings'] ) ? $element['settings'] : [];
				$selector            = '#' . $css_id;

				// Build targets from base/pseudo if no explicit targets
				if ( $has_targets ) {
					$mapper_targets = $rules['targets'];
				} else {
					$mapper_targets = [];

					if ( $has_base ) {
						$mapper_targets[] = Html_To_Bricks_Css_Control_Mapper::build_root_target( $selector, $rules['base'] );
					}

					if ( $has_pseudo ) {
						foreach ( $rules['pseudo'] as $pseudo => $declarations ) {
							$mapper_targets[] = Html_To_Bricks_Css_Control_Mapper::build_root_target( "{$selector}{$pseudo}", $declarations );
						}
					}
				}

				$native_targets     = [];
				$css_custom_targets = [];

				foreach ( $mapper_targets as $source_order => &$target ) {
					$target['sourceOrder'] = $source_order;
				}
				unset( $target );

				foreach ( $mapper_targets as $target ) {
					if ( ! empty( $target['atRules'] ) && ! self::is_native_breakpoint_target( $target, $base_breakpoint_key ) ) {
						if ( self::is_plain_resolved_breakpoint_target( $target ) ) {
							// Store direction-incompatible queries at the base so Bricks does
							// not add its opposite breakpoint wrapper around the fallback.
							$target['breakpoint'] = '';
						}

						$css_custom_targets[] = $target;
					} else {
						$native_targets[] = $target;
					}
				}

				$mapped = Html_To_Bricks_Css_Control_Mapper::map_targets_with_schema(
					$element['name'],
					$selector,
					$native_targets,
					$updated['settings'],
					$mapper_targets
				);

				$updated['settings'] = $mapped['settings'];

				$unmapped_targets = array_merge(
					isset( $mapped['unmappedTargets'] ) ? $mapped['unmappedTargets'] : [],
					$css_custom_targets
				);
				$policy           = Html_To_Bricks_Css_Control_Mapper::get_native_effect_policy( $mapper_targets, $native_targets );
				usort(
					$unmapped_targets,
					static function ( $a, $b ) {
						return $a['sourceOrder'] <=> $b['sourceOrder'];
					}
				);
				foreach ( $unmapped_targets as &$target ) {
					$effect = $policy[ Html_To_Bricks_Css_Control_Mapper::normalize_selector( $target['selectorBase'] ?? $target['selector'] ?? '' ) ] ?? [];
					foreach ( $target['declarations'] ?? [] as $property => $value ) {
						if ( ( $property === 'all' && ( ! empty( $effect['hasShadow'] ) || ! empty( $effect['gradient'] ) ) ) || ( $property === 'box-shadow' && empty( $effect['shadow'] ) ) || ( ( $property === 'background' || strpos( $property, 'background-' ) === 0 ) && ! empty( $effect['gradient'] ) && empty( $effect['background'] ) ) ) {
							$target['preserveEffectCascade'] = true;
						}
					}
				}
				unset( $target );
				$root_unmapped_targets   = [];
				$custom_selector_targets = [];

				foreach ( $unmapped_targets as $target ) {
					if ( Html_To_Bricks_Class_Creator::is_root_selector_target( $target, $selector ) || ! empty( $target['preserveEffectCascade'] ) ) {
						$root_unmapped_targets[] = $target;
					} else {
						$custom_selector_targets[] = $target;
					}
				}

				$css_custom_settings = Html_To_Bricks_Css_Control_Mapper::build_css_custom_settings_from_targets( $root_unmapped_targets );

				foreach ( $css_custom_settings as $key => $css_custom_fallback ) {
					$existing_css_custom         = isset( $updated['settings'][ $key ] ) ? $updated['settings'][ $key ] : '';
					$updated['settings'][ $key ] = trim( "{$existing_css_custom}\n{$css_custom_fallback}" );
				}

				$selector_objects = Html_To_Bricks_Class_Creator::create_selector_objects_from_targets(
					$selector,
					$custom_selector_targets,
					isset( $element['name'] ) ? $element['name'] : 'div'
				);

				if ( ! empty( $selector_objects ) ) {
					$existing_selectors   = isset( $updated['selectors'] ) && is_array( $updated['selectors'] ) ? $updated['selectors'] : [];
					$updated['selectors'] = array_merge( $existing_selectors, $selector_objects );
				}

				return $updated;
			},
			$elements
		);
	}

	/**
	 * Check whether an at-rule target is exactly one resolved Bricks breakpoint.
	 *
	 * Compound or nested conditions cannot be represented by a responsive control
	 * key, so they must remain custom CSS even when they contain a known width.
	 *
	 * @since 2.4
	 *
	 * @param array  $target              Parsed CSS target.
	 * @param string $base_breakpoint_key Configured Bricks base breakpoint key.
	 * @return bool True when the target can safely use native responsive controls.
	 */
	private static function is_native_breakpoint_target( $target, $base_breakpoint_key = 'desktop' ) {
		if ( ! self::is_plain_resolved_breakpoint_target( $target ) ) {
			return false;
		}

		$breakpoint = (string) $target['breakpoint'];

		$breakpoints  = class_exists( '\\Bricks\\Breakpoints' ) && ! empty( Breakpoints::$breakpoints )
			? Breakpoints::$breakpoints
			: [
				[
					'key'   => 'desktop',
					'width' => 1279,
					'base'  => true,
				],
				[
					'key'   => 'tablet_portrait',
					'width' => 991,
				],
				[
					'key'   => 'mobile_landscape',
					'width' => 767,
				],
				[
					'key'   => 'mobile_portrait',
					'width' => 478,
				],
			];
		$base_key     = trim( (string) $base_breakpoint_key );
		$base_key     = $base_key !== '' ? $base_key : 'desktop';
		$base_width   = null;
		$target_width = null;

		foreach ( $breakpoints as $breakpoint_row ) {
			if ( (string) ( $breakpoint_row['key'] ?? '' ) === $base_key ) {
				$base_width = (float) ( $breakpoint_row['width'] ?? 0 );
			}

			if ( (string) ( $breakpoint_row['key'] ?? '' ) === $breakpoint ) {
				$target_width = (float) ( $breakpoint_row['width'] ?? 0 );
			}
		}

		// Bricks emits keys wider than its base as min-width queries. A source
		// max-width query can only map natively when the target is below the base.
		return $base_width > 0 && $target_width > 0 && $target_width < $base_width;
	}

	/**
	 * Check whether a target is exactly one parser-resolved max-width query.
	 *
	 * @since 2.4
	 *
	 * @param array $target Parsed CSS target.
	 * @return bool True for one exact resolved max-width media query.
	 */
	private static function is_plain_resolved_breakpoint_target( $target ) {
		$breakpoint = isset( $target['breakpoint'] ) ? (string) $target['breakpoint'] : '';
		$at_rules   = isset( $target['atRules'] ) && is_array( $target['atRules'] ) ? $target['atRules'] : [];

		if ( $breakpoint === '' || count( $at_rules ) !== 1 ) {
			return false;
		}

		$at_rule = $at_rules[0];
		$params  = trim( (string) ( $at_rule['params'] ?? '' ) );

		return strtolower( (string) ( $at_rule['name'] ?? '' ) ) === 'media' &&
			(bool) preg_match( '/^(?:\(\s*max-width\s*:\s*\d+(?:\.\d+)?px\s*\)|max-width\s*:\s*\d+(?:\.\d+)?px)$/i', $params ) &&
			Html_To_Bricks_Css_Parser::resolve_breakpoint_from_media_query( $params ) === $breakpoint;
	}

	/**
	 * Convert inline styles to _cssCustom.
	 *
	 * @since 2.4
	 *
	 * @param array      $elements          Bricks elements.
	 * @param array|null $rem_normalization Valid rem normalization settings.
	 * @return array Updated elements.
	 */
	private static function convert_inline_styles( $elements, $rem_normalization = null ) {
		return array_map(
			function ( $element ) use ( $rem_normalization ) {
				if ( empty( $element['settings']['_inlineStyle'] ) ) {
					return $element;
				}

				$updated             = $element;
				$updated['settings'] = isset( $element['settings'] ) ? $element['settings'] : [];
				$inline_style        = $updated['settings']['_inlineStyle'];

				if ( $rem_normalization ) {
					$inline_style = Html_To_Bricks_Css_Parser::normalize_rem_units(
						$inline_style,
						$rem_normalization['source_root_font_size_px'],
						$rem_normalization['target_root_font_size_px']
					);
				}

				$css_id   = ! empty( $updated['settings']['_cssId'] ) ? $updated['settings']['_cssId'] : '';
				$selector = $css_id ? "#{$css_id}" : '#brxe-' . $element['id'];

				$inline_declarations = Html_To_Bricks_Css_Parser::parse_declarations( $inline_style );

				$mapped = Html_To_Bricks_Css_Control_Mapper::map_targets_with_schema(
					$element['name'],
					$selector,
					[ Html_To_Bricks_Css_Control_Mapper::build_root_target( $selector, $inline_declarations ) ],
					$updated['settings']
				);

				$updated['settings'] = $mapped['settings'];

				$css_custom_fallback = Html_To_Bricks_Css_Control_Mapper::build_css_custom_from_targets( isset( $mapped['unmappedTargets'] ) ? $mapped['unmappedTargets'] : [] );

				if ( $css_custom_fallback ) {
					$existing_css_custom               = isset( $updated['settings']['_cssCustom'] ) ? $updated['settings']['_cssCustom'] : '';
					$updated['settings']['_cssCustom'] = trim( "{$existing_css_custom}\n{$css_custom_fallback}" );
				}

				// Remove temporary property
				unset( $updated['settings']['_inlineStyle'] );

				return $updated;
			},
			$elements
		);
	}

	/**
	 * Keep imported max-width constraints responsive on native containers.
	 *
	 * Bricks gives `.brxe-container` a fixed base width plus `max-width: 100%`.
	 * An imported max-width overrides that safety declaration while leaving the
	 * fixed width active, so a container can overflow viewports narrower than
	 * the imported maximum. Source block elements instead have an automatic
	 * width. Add the equivalent local width only when the import supplied a
	 * max-width without supplying its own width in the same style context.
	 *
	 * @since 2.4
	 *
	 * @param array $elements         Converted Bricks elements.
	 * @param array $global_classes   Global classes created by this conversion.
	 * @param array $existing_classes Existing global classes available to the conversion.
	 * @return array Updated elements.
	 */
	private static function preserve_native_container_max_width_fidelity( $elements, $global_classes, $existing_classes = [] ) {
		$classes_by_id = [];

		foreach ( array_merge( $existing_classes, $global_classes ) as $global_class ) {
			if ( empty( $global_class['id'] ) || empty( $global_class['settings'] ) || ! is_array( $global_class['settings'] ) ) {
				continue;
			}

			$classes_by_id[ $global_class['id'] ] = $global_class['settings'];
		}

		return array_map(
			function ( $element ) use ( $classes_by_id ) {
				if ( ( $element['name'] ?? '' ) !== 'container' ) {
					return $element;
				}

				$settings_by_origin = [ isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [] ];

				foreach ( $settings_by_origin[0]['_cssGlobalClasses'] ?? [] as $global_class_id ) {
					if ( isset( $classes_by_id[ $global_class_id ] ) ) {
						$settings_by_origin[] = $classes_by_id[ $global_class_id ];
					}
				}

				$max_width_contexts = [];
				$width_contexts     = [];

				foreach ( $settings_by_origin as $origin_settings ) {
					foreach ( $origin_settings as $setting_key => $setting_value ) {
						if ( $setting_value === null || $setting_value === '' ) {
							continue;
						}

						if ( preg_match( '/^_widthMax(?::|$)/', (string) $setting_key ) ) {
							$max_width_contexts[ substr( (string) $setting_key, strlen( '_widthMax' ) ) ] = true;
						} elseif ( preg_match( '/^_width(?::|$)/', (string) $setting_key ) ) {
							$width_contexts[ substr( (string) $setting_key, strlen( '_width' ) ) ] = true;
						}
					}
				}

				foreach ( array_keys( $max_width_contexts ) as $context_suffix ) {
					if ( isset( $width_contexts[ $context_suffix ] ) ) {
						continue;
					}

					$element['settings'][ "_width{$context_suffix}" ] = '100%';
				}

				return $element;
			},
			$elements
		);
	}

	/**
	 * Resolve the source page's effective inherited base typography.
	 *
	 * A body declaration is closest to imported page content and therefore wins
	 * when present. Without body, :root beats html by specificity; later rules
	 * win within each selector. Conditional rules are intentionally excluded.
	 *
	 * @since 2.4
	 *
	 * @param array      $rules             Parsed CSS rules.
	 * @param array|null $rem_normalization Explicit source/target root-size normalization.
	 * @return array Effective inherited typography declarations.
	 */
	private static function extract_effective_base_typography( $rules, $rem_normalization = null ) {
		$properties = [ 'font-family', 'font-size', 'line-height' ];
		$candidates = [];

		foreach ( [ 'html', ':root', 'body' ] as $selector ) {
			$candidates[ $selector ] = [];

			foreach ( $properties as $property ) {
				$candidates[ $selector ][ $property ] = [
					'value'    => null,
					'priority' => 0,
				];
			}
		}

		self::collect_base_typography_candidates( $rules, $candidates, $properties );

		$typography = [];

		foreach ( $properties as $property ) {
			$value =
				$candidates['body'][ $property ]['value'] ??
				$candidates[':root'][ $property ]['value'] ??
				$candidates['html'][ $property ]['value'] ??
				null;

			if ( $value !== null ) {
				$typography[ $property ] = $value;
			}
		}

		if (
			! isset( $typography['font-size'] ) &&
			is_array( $rem_normalization ) &&
			isset( $rem_normalization['source_root_font_size_px'] )
		) {
			$typography['font-size'] = self::format_pixel_value( $rem_normalization['source_root_font_size_px'] );
		}

		return $typography;
	}

	/**
	 * Collect typography candidates, descending only into cascade layers.
	 *
	 * @since 2.4
	 *
	 * @param array $rules      Parsed CSS rules.
	 * @param array $candidates Candidate values by selector and property.
	 * @param array $properties Transferable typography property names.
	 * @param int   $priority   Cascade priority: unlayered beats layered.
	 * @return void
	 */
	private static function collect_base_typography_candidates( $rules, &$candidates, $properties, $priority = 2 ) {
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( strtolower( (string) ( $rule['atRuleName'] ?? '' ) ) === 'layer' ) {
					self::collect_base_typography_candidates(
						self::parse_nested_at_rule_rules( $rule ),
						$candidates,
						$properties,
						1
					);
				}

				continue;
			}

			if ( ! self::is_transferable_base_typography_rule( $rule ) ) {
				continue;
			}

			foreach ( $rule['selectors'] as $selector ) {
				$selector = strtolower( trim( (string) $selector ) );

				if ( ! array_key_exists( $selector, $candidates ) ) {
					continue;
				}

				foreach ( $rule['declarations'] as $property => $value ) {
					$property = strtolower( trim( (string) $property ) );
					$value    = trim( (string) $value );

					if (
						in_array( $property, $properties, true ) &&
						$value !== '' &&
						$priority >= $candidates[ $selector ][ $property ]['priority']
					) {
						$candidates[ $selector ][ $property ] = [
							'value'    => $value,
							'priority' => $priority,
						];
					}
				}
			}
		}
	}

	/**
	 * Parse the contents of an opaque block at-rule.
	 *
	 * @since 2.4
	 *
	 * @param array $rule Parsed at-rule.
	 * @return array Nested parsed rules.
	 */
	private static function parse_nested_at_rule_rules( $rule ) {
		$raw         = (string) ( $rule['raw'] ?? '' );
		$open_index  = strpos( $raw, '{' );
		$close_index = strrpos( $raw, '}' );

		if ( $open_index === false || $close_index === false || $close_index <= $open_index ) {
			return [];
		}

		return Html_To_Bricks_Css_Parser::parse_css( substr( $raw, $open_index + 1, $close_index - $open_index - 1 ) );
	}

	/**
	 * Whether a parsed rule can contribute unconditional inherited typography.
	 *
	 * Cascade layers are unconditional ordering wrappers. Media, supports, and
	 * other parent at-rules depend on runtime conditions and remain in fallback
	 * CSS instead of being promoted to every converted page root.
	 *
	 * @since 2.4
	 *
	 * @param array $rule Parsed CSS rule.
	 * @return bool
	 */
	private static function is_transferable_base_typography_rule( $rule ) {
		if (
			! empty( $rule['isAtRule'] ) ||
			! empty( $rule['mediaQuery'] ) ||
			empty( $rule['selectors'] ) ||
			empty( $rule['declarations'] )
		) {
			return false;
		}

		foreach ( $rule['atRules'] ?? [] as $at_rule ) {
			if ( strtolower( (string) ( $at_rule['name'] ?? '' ) ) !== 'layer' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove transferred typography declarations from standalone base selectors.
	 *
	 * Mixed selectors are split so exact root/html/body branches lose only the
	 * transferred properties while unrelated selector branches keep the original
	 * declaration set. Conditional rules remain untouched.
	 *
	 * @since 2.4
	 *
	 * @param array $rules      Parsed CSS rules.
	 * @param array $properties Transferred property names.
	 * @return array Rules without transferred standalone typography declarations.
	 */
	private static function remove_transferred_base_typography( $rules, $properties ) {
		$filtered_rules = [];

		foreach ( $rules as $rule ) {
			if (
				! empty( $rule['isAtRule'] ) &&
				strtolower( (string) ( $rule['atRuleName'] ?? '' ) ) === 'layer'
			) {
				$nested_rules = self::remove_transferred_base_typography(
					self::parse_nested_at_rule_rules( $rule ),
					$properties
				);

				if ( empty( $nested_rules ) ) {
					continue;
				}

				$header              = '@layer' . ( empty( $rule['atRuleParams'] ) ? '' : ' ' . $rule['atRuleParams'] );
				$rule['raw']         = "{$header} {\n" . self::indent_css( Html_To_Bricks_Css_Parser::rules_to_string( $nested_rules ) ) . "\n}";
				$rule['nestedRules'] = $nested_rules;
				$filtered_rules[]    = $rule;
				continue;
			}

			if ( ! self::is_transferable_base_typography_rule( $rule ) ) {
				$filtered_rules[] = $rule;
				continue;
			}

			$base_selectors       = [];
			$unaffected_selectors = [];

			foreach ( $rule['selectors'] as $selector ) {
				if ( in_array( strtolower( trim( (string) $selector ) ), [ ':root', 'html', 'body' ], true ) ) {
					$base_selectors[] = $selector;
				} else {
					$unaffected_selectors[] = $selector;
				}
			}

			if ( empty( $base_selectors ) ) {
				$filtered_rules[] = $rule;
				continue;
			}

			$declarations = [];

			foreach ( $rule['declarations'] as $property => $value ) {
				if ( ! in_array( strtolower( trim( (string) $property ) ), $properties, true ) ) {
					$declarations[ $property ] = $value;
				}
			}

			if ( ! empty( $declarations ) ) {
				$base_rule                 = $rule;
				$base_rule['selectors']    = $base_selectors;
				$base_rule['declarations'] = $declarations;
				$filtered_rules[]          = $base_rule;
			}

			if ( ! empty( $unaffected_selectors ) ) {
				$unaffected_rule              = $rule;
				$unaffected_rule['selectors'] = $unaffected_selectors;
				$filtered_rules[]             = $unaffected_rule;
			}
		}

		return $filtered_rules;
	}

	/**
	 * Indent serialized CSS for a rebuilt at-rule.
	 *
	 * @since 2.4
	 *
	 * @param string $css CSS text.
	 * @return string Indented CSS.
	 */
	private static function indent_css( $css ) {
		return implode(
			"\n",
			array_map(
				function ( $line ) {
					return $line === '' ? '' : '  ' . $line;
				},
				explode( "\n", trim( (string) $css ) )
			)
		);
	}

	/**
	 * Apply inherited source typography to each converted page root.
	 *
	 * The rule stays page-scoped in the element tree and survives removal of a
	 * blocked global Code fallback without changing the WordPress body or root.
	 *
	 * @since 2.4
	 *
	 * @param array $elements   Converted Bricks elements.
	 * @param array $typography Effective inherited source typography.
	 * @return array Updated elements.
	 */
	private static function apply_base_typography_to_roots( $elements, $typography ) {
		if ( empty( $typography ) || ! is_array( $typography ) ) {
			return $elements;
		}

		return array_map(
			function ( $element ) use ( $typography ) {
				if ( ! is_array( $element ) || ! empty( $element['parent'] ) || empty( $element['id'] ) || ( $element['name'] ?? '' ) === 'code' ) {
					return $element;
				}

				$updated             = $element;
				$updated['settings'] = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
				$css_id              = trim( (string) ( $updated['settings']['_cssId'] ?? '' ) );
				$selector            = $css_id !== '' ? '#' . $css_id : '#brxe-' . $element['id'];
				$declarations        = [];

				foreach ( $typography as $property => $value ) {
					$declarations[] = "  {$property}: {$value};";
				}

				$typography_rule = "{$selector} {\n" . implode( "\n", $declarations ) . "\n}";
				$existing_css    = isset( $updated['settings']['_cssCustom'] ) ? trim( (string) $updated['settings']['_cssCustom'] ) : '';

				$updated['settings']['_cssCustom'] = trim( implode( "\n", array_filter( [ $existing_css, $typography_rule ] ) ) );

				return $updated;
			},
			$elements
		);
	}

	/**
	 * Build the deterministic semantic HTML baseline used by page imports.
	 *
	 * The baseline precedes authored CSS in the same parsed rule stream. This
	 * mirrors user-agent defaults while keeping later source rules authoritative.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Converted Bricks elements.
	 * @return array{css: string, metadata: array}
	 */
	private static function build_semantic_defaults( $elements ) {
		$present = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$tag      = strtolower( trim( (string) ( $settings['tag'] ?? '' ) ) );

			if ( $tag === 'custom' ) {
				$tag = strtolower( trim( (string) ( $settings['customTag'] ?? '' ) ) );
			}

			if ( $tag === '' ) {
				$tag = ( $element['name'] ?? '' ) === 'section' ? 'section' : ( ( $element['name'] ?? '' ) === 'div' ? 'div' : '' );
			}

			if ( $tag !== '' ) {
				$present[ $tag ] = true;
			}
		}

		$rules = [];

		foreach ( [ 'div', 'main', 'nav', 'article', 'aside', 'section', 'header', 'footer' ] as $tag ) {
			if ( isset( $present[ $tag ] ) ) {
				$rules[] = "{$tag} { display: block; }";
			}
		}

		if ( isset( $present['p'] ) ) {
			$rules[] = 'p { display: block; margin-block: 1em; margin-inline: 0; }';
		}

		$heading_defaults = [
			'h1' => [ '2em', '0.67em' ],
			'h2' => [ '1.5em', '0.83em' ],
			'h3' => [ '1.17em', '1em' ],
			'h4' => [ '1em', '1.33em' ],
			'h5' => [ '0.83em', '1.67em' ],
			'h6' => [ '0.67em', '2.33em' ],
		];

		foreach ( $heading_defaults as $tag => $defaults ) {
			if ( ! isset( $present[ $tag ] ) ) {
				continue;
			}

			$rules[] = sprintf(
				'%1$s { display: block; font-size: %2$s; margin-block: %3$s; margin-inline: 0; font-weight: bold; line-height: inherit; }',
				$tag,
				$defaults[0],
				$defaults[1]
			);
		}

		if ( isset( $present['ul'] ) ) {
			$rules[] = 'ul { display: block; list-style-type: disc; margin-block: 1em; margin-inline: 0; padding-inline-start: 40px; }';
		}

		if ( isset( $present['ol'] ) ) {
			$rules[] = 'ol { display: block; list-style-type: decimal; margin-block: 1em; margin-inline: 0; padding-inline-start: 40px; }';
		}

		if ( isset( $present['li'] ) ) {
			$rules[] = ':where(li).brxe-text-basic, :where(li).brxe-div { display: list-item; }';
		}

		if ( isset( $present['figure'] ) ) {
			$rules[] = 'figure { display: block; margin-block: 1em; margin-inline: 40px; }';
		}

		$tags = array_keys( $present );
		sort( $tags );

		return [
			'css'      => implode( "\n", $rules ),
			'metadata' => [
				'applied' => ! empty( $rules ),
				'profile' => 'bricks-semantic-html-v1',
				'tags'    => $tags,
			],
		];
	}

	/**
	 * Move unconditional global selectors into each converted root's custom CSS.
	 *
	 * Full-page imports own their content roots, so scoping ordinary selectors
	 * there preserves source behavior without requiring a Code element. At-rules
	 * stay in the fallback path because rewriting their nested selectors requires
	 * a complete CSS AST transformation.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Converted elements.
	 * @param array $rules    Parsed global CSS rules.
	 * @return array{elements: array, remainingRules: array}
	 */
	private static function scope_global_rules_to_roots( $elements, $rules ) {
		$scopeable = [];
		$remaining = [];

		foreach ( $rules as $rule ) {
			if (
				! empty( $rule['isAtRule'] ) ||
				! empty( $rule['atRules'] ) ||
				empty( $rule['selectors'] ) ||
				empty( $rule['declarations'] )
			) {
				$remaining[] = $rule;
				continue;
			}

			$safe_selectors   = [];
			$unsafe_selectors = [];

			foreach ( $rule['selectors'] as $selector ) {
				if ( self::is_selector_safe_to_scope( $selector ) ) {
					$safe_selectors[] = $selector;
				} else {
					$unsafe_selectors[] = $selector;
				}
			}

			if ( ! empty( $safe_selectors ) ) {
				$scoped_rule              = $rule;
				$scoped_rule['selectors'] = $safe_selectors;
				$scopeable[]              = $scoped_rule;
			}

			if ( ! empty( $unsafe_selectors ) ) {
				$fallback_rule              = $rule;
				$fallback_rule['selectors'] = $unsafe_selectors;
				$remaining[]                = $fallback_rule;
			}
		}

		if ( empty( $scopeable ) ) {
			return [
				'elements'       => $elements,
				'remainingRules' => $remaining,
			];
		}

		$root_indexes   = [];
		$root_selectors = [];

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) || ! empty( $element['parent'] ) || empty( $element['id'] ) || ( $element['name'] ?? '' ) === 'code' ) {
				continue;
			}

			$settings         = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$css_id           = trim( (string) ( $settings['_cssId'] ?? '' ) );
			$root_indexes[]   = $index;
			$root_selectors[] = $css_id !== '' ? '#' . $css_id : '#brxe-' . $element['id'];
		}

		if ( empty( $root_indexes ) ) {
			return [
				'elements'       => $elements,
				'remainingRules' => $remaining,
			];
		}

		$updated = $elements;
		if ( count( $root_indexes ) > 1 ) {
			$scope_attribute = self::add_shared_root_scope_attribute( $updated, $root_indexes );
			$root_selectors  = [ '[' . $scope_attribute . ']' ];
		}

		$owner_index       = $root_indexes[0];
		$owner             = $updated[ $owner_index ];
		$owner['settings'] = isset( $owner['settings'] ) && is_array( $owner['settings'] ) ? $owner['settings'] : [];
		$scoped_css        = self::build_root_scoped_css( $scopeable, $root_selectors );
		$existing_css      = trim( (string) ( $owner['settings']['_cssCustom'] ?? '' ) );

		$owner['settings']['_cssCustom'] = trim( implode( "\n", array_filter( [ $existing_css, $scoped_css ] ) ) );
		$updated[ $owner_index ]         = $owner;

		return [
			'elements'       => $updated,
			'remainingRules' => $remaining,
		];
	}

	/**
	 * Add one collision-safe shared data attribute to imported top-level roots.
	 *
	 * A shared selector keeps page-wide CSS storage proportional to rules plus
	 * roots. Repeating every root ID inside every selector would still grow as
	 * roots multiplied by rules.
	 *
	 * @since 2.4
	 *
	 * @param array $elements     Converted elements.
	 * @param array $root_indexes Top-level element indexes.
	 * @return string Shared attribute name.
	 */
	private static function add_shared_root_scope_attribute( &$elements, $root_indexes ) {
		$owner_id  = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $elements[ $root_indexes[0] ]['id'] ) );
		$base_name = 'data-bricks-import-scope-' . $owner_id;
		$name      = $base_name;
		$suffix    = 1;
		$existing  = [];

		foreach ( $elements as $element ) {
			foreach ( (array) ( $element['settings']['_attributes'] ?? [] ) as $attribute ) {
				if ( ! empty( $attribute['name'] ) ) {
					$existing[ strtolower( (string) $attribute['name'] ) ] = true;
				}
			}
		}

		while ( isset( $existing[ strtolower( $name ) ] ) ) {
			$name = $base_name . '-' . $suffix;
			$suffix++;
		}

		foreach ( $root_indexes as $index ) {
			$settings                       = isset( $elements[ $index ]['settings'] ) && is_array( $elements[ $index ]['settings'] ) ? $elements[ $index ]['settings'] : [];
			$settings['_attributes']        = isset( $settings['_attributes'] ) && is_array( $settings['_attributes'] ) ? $settings['_attributes'] : [];
			$settings['_attributes'][]      = [
				'id'    => Html_To_Bricks_Element_Mapper::generate_id(),
				'name'  => $name,
				'value' => '',
			];
			$elements[ $index ]['settings'] = $settings;
		}

		return $name;
	}

	/**
	 * Whether a selector can move below a Bricks content root losslessly.
	 *
	 * Root compounds and child combinators depend on the real document
	 * html/body state or on the source root element itself. Keep those selectors
	 * in fallback CSS instead of rewriting them to an impossible descendant.
	 *
	 * @since 2.4
	 *
	 * @param mixed $selector Parsed selector.
	 * @return bool
	 */
	private static function is_selector_safe_to_scope( $selector ) {
		$selector   = trim( (string) $selector );
		$normalized = strtolower( $selector );

		if ( $selector === '' ) {
			return false;
		}

		if ( in_array( $normalized, [ ':root', 'html', 'body' ], true ) || $selector === '*' ) {
			return true;
		}

		if ( preg_match( '/^:(?:where|is)\([^)]*(?::root|\b(?:html|body)\b)/i', $selector ) ) {
			return false;
		}

		if ( self::selector_has_top_level_sibling_combinator( $selector ) ) {
			return false;
		}

		$relative = $selector;

		while ( preg_match( '/^(?:html|body|:root)(?=\s|$|[.#:\[>+~])(.*)$/i', $relative, $matches ) ) {
			$remainder = $matches[1];

			if ( $remainder === '' ) {
				return true;
			}

			if ( ! preg_match( '/^\s+/', $remainder ) ) {
				return false;
			}

			// Removing a document-root compound changes authored specificity.
			// Keep the selector in fallback CSS instead of collapsing it with an
			// otherwise lower-specificity descendant selector.
			return false;
		}

		return $relative !== '' &&
			! preg_match( '/^[>+~]/', $relative ) &&
			$relative === $selector;
	}

	/**
	 * Serialize unconditional rules below one Bricks root selector.
	 *
	 * @param array $rules          Parsed CSS rules.
	 * @param array $root_selectors Root element selectors.
	 * @return string
	 */
	private static function build_root_scoped_css( $rules, $root_selectors ) {
		$scoped_rules   = [];
		$scope_selector = ':where(' . implode( ', ', $root_selectors ) . ')';

		foreach ( $rules as $rule ) {
			$selectors           = [];
			$is_semantic_default = ! empty( $rule['_bricksSemanticDefault'] );

			foreach ( $rule['selectors'] as $selector ) {
				$selector   = trim( (string) $selector );
				$normalized = strtolower( $selector );

				if ( in_array( $normalized, [ ':root', 'html', 'body' ], true ) ) {
					// Document-root declarations originally have lower specificity
					// than authored classes. Keep the zero-specificity scope wrapper
					// so a root class such as .proof can still override body styles.
					$selectors[] = $scope_selector;
				} else {
					$relative_selector = preg_replace(
						'/^(?:(?:html|body|:root)\s+)+/i',
						'',
						$selector
					);
					$adapted_selector  = $is_semantic_default
						? self::adapt_semantic_selector_to_bricks( $relative_selector )
						: self::add_authored_selector_offset( $relative_selector );
					$selectors[]       = $scope_selector . ':is(' . $adapted_selector . ')';
					$selectors[]       = $scope_selector . ' ' . $adapted_selector;

					$root_origin = self::split_selector_leading_compound( $adapted_selector );

					if ( $root_origin !== null ) {
						$selectors[] = $scope_selector . ':is(' . $root_origin['compound'] . ')' . $root_origin['remainder'];
					}
				}
			}

			$scoped              = $rule;
			$scoped['selectors'] = array_values( array_unique( $selectors ) );
			$scoped_rules[]      = $scoped;
		}

		return trim( Html_To_Bricks_Css_Parser::rules_to_string( $scoped_rules ) );
	}

	/**
	 * Add one constant specificity offset to an authored selector.
	 *
	 * Every authored selector receives the same always-matching pseudo-class on
	 * its target compound. The constant offset preserves all source-to-source
	 * specificity differences, beats Bricks class resets, and keeps authored
	 * rules above the lower semantic compatibility profile.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Source selector scoped below an imported root.
	 * @return string Offset selector.
	 */
	private static function add_authored_selector_offset( $selector ) {
		$selector             = trim( (string) $selector );
		$pseudo_element_index = self::find_top_level_pseudo_element_index( $selector );

		if ( $pseudo_element_index !== null ) {
			return substr( $selector, 0, $pseudo_element_index ) .
				self::AUTHORED_SPECIFICITY_OFFSET .
				substr( $selector, $pseudo_element_index );
		}

		return $selector . self::AUTHORED_SPECIFICITY_OFFSET;
	}

	/**
	 * Locate the first top-level pseudo-element in a selector.
	 *
	 * Structural pseudo-classes cannot follow a pseudo-element. The authored
	 * offset therefore belongs before `::part()`, `::slotted()`, ordinary
	 * double-colon pseudo-elements, and their legacy single-colon spellings.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Parsed selector.
	 * @return int|null Byte offset of the pseudo-element.
	 */
	private static function find_top_level_pseudo_element_index( $selector ) {
		$length        = strlen( $selector );
		$paren_depth   = 0;
		$bracket_depth = 0;
		$quote         = '';
		$escaped       = false;

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $selector[ $index ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( $character === '\\' ) {
				$escaped = true;
				continue;
			}

			if ( $quote !== '' ) {
				if ( $character === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $character === '"' || $character === "'" ) {
				$quote = $character;
				continue;
			}

			if ( $character === '(' ) {
				$paren_depth++;
				continue;
			}

			if ( $character === ')' && $paren_depth > 0 ) {
				$paren_depth--;
				continue;
			}

			if ( $character === '[' ) {
				$bracket_depth++;
				continue;
			}

			if ( $character === ']' && $bracket_depth > 0 ) {
				$bracket_depth--;
				continue;
			}

			if ( $paren_depth !== 0 || $bracket_depth !== 0 || $character !== ':' ) {
				continue;
			}

			if ( substr( $selector, $index, 2 ) === '::' ) {
				return $index;
			}

			if ( preg_match( '/^:(?:before|after|first-line|first-letter)(?=$|[^a-z0-9_-])/i', substr( $selector, $index ) ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Split a selector after its first top-level compound.
	 *
	 * A page import can turn the selector's leading compound into the Bricks
	 * content root itself. Keeping that compound separate lets root scoping
	 * preserve selectors such as `main h2` and `[data-page] > h2` without a
	 * full selector AST. Functional and attribute-selector contents are skipped
	 * while locating the first real combinator.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Selector with its authored specificity offset.
	 * @return array{compound: string, remainder: string}|null Split selector.
	 */
	private static function split_selector_leading_compound( $selector ) {
		$length        = strlen( $selector );
		$paren_depth   = 0;
		$bracket_depth = 0;
		$quote         = '';
		$escaped       = false;

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $selector[ $index ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( $character === '\\' ) {
				$escaped = true;
				continue;
			}

			if ( $quote !== '' ) {
				if ( $character === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $character === '"' || $character === "'" ) {
				$quote = $character;
				continue;
			}

			if ( $character === '(' ) {
				$paren_depth++;
				continue;
			}

			if ( $character === ')' && $paren_depth > 0 ) {
				$paren_depth--;
				continue;
			}

			if ( $character === '[' ) {
				$bracket_depth++;
				continue;
			}

			if ( $character === ']' && $bracket_depth > 0 ) {
				$bracket_depth--;
				continue;
			}

			if ( $paren_depth !== 0 || $bracket_depth !== 0 ) {
				continue;
			}

			if ( ctype_space( $character ) || in_array( $character, [ '>', '+', '~' ], true ) ) {
				$compound = trim( substr( $selector, 0, $index ) );

				if ( $compound === '' ) {
					return null;
				}

				return [
					'compound'  => $compound,
					'remainder' => substr( $selector, $index ),
				];
			}
		}

		return null;
	}

	/**
	 * Detect sibling combinators that could escape an imported content root.
	 *
	 * Adjacent top-level source nodes become separate Bricks roots. A locally
	 * scoped rule cannot preserve `+` or `~` relationships between those roots,
	 * so those selectors must remain in the exact global fallback.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Parsed selector.
	 * @return bool
	 */
	private static function selector_has_top_level_sibling_combinator( $selector ) {
		$length        = strlen( $selector );
		$paren_depth   = 0;
		$bracket_depth = 0;
		$quote         = '';
		$escaped       = false;

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $selector[ $index ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( $character === '\\' ) {
				$escaped = true;
				continue;
			}

			if ( $quote !== '' ) {
				if ( $character === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $character === '"' || $character === "'" ) {
				$quote = $character;
				continue;
			}

			if ( $character === '(' ) {
				$paren_depth++;
				continue;
			}

			if ( $character === ')' && $paren_depth > 0 ) {
				$paren_depth--;
				continue;
			}

			if ( $character === '[' ) {
				$bracket_depth++;
				continue;
			}

			if ( $character === ']' && $bracket_depth > 0 ) {
				$bracket_depth--;
				continue;
			}

			if ( $paren_depth === 0 && $bracket_depth === 0 && in_array( $character, [ '+', '~' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply the authored specificity offset to rules retained in fallback CSS.
	 *
	 * Scoped and fallback authored rules still participate in one cascade. Both
	 * sides need the same constant offset or moving only one selector can reverse
	 * source specificity. Conditional grouping rules are rewritten recursively;
	 * keyframe selectors are animation syntax and remain untouched.
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed fallback rules.
	 * @return array Offset fallback rules.
	 */
	private static function offset_authored_fallback_rules( $rules ) {
		$updated = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['_bricksSemanticDefault'] ) ) {
				$updated[] = $rule;
				continue;
			}

			if ( ! empty( $rule['isAtRule'] ) ) {
				$at_rule_name = strtolower( (string) ( $rule['atRuleName'] ?? '' ) );

				if ( substr( $at_rule_name, -9 ) === 'keyframes' ) {
					$updated[] = $rule;
					continue;
				}

				$raw         = (string) ( $rule['raw'] ?? '' );
				$open_index  = strpos( $raw, '{' );
				$close_index = strrpos( $raw, '}' );

				if ( $open_index !== false && $close_index !== false && $close_index > $open_index ) {
					$nested = self::offset_authored_fallback_rules(
						Html_To_Bricks_Css_Parser::parse_css(
							substr( $raw, $open_index + 1, $close_index - $open_index - 1 )
						)
					);

					if ( ! empty( $nested ) ) {
						$header              = trim( substr( $raw, 0, $open_index ) );
						$rule['raw']         = "{$header} {\n" . self::indent_css( Html_To_Bricks_Css_Parser::rules_to_string( $nested ) ) . "\n}";
						$rule['nestedRules'] = $nested;
					}
				}

				$updated[] = $rule;
				continue;
			}

			if ( ! empty( $rule['selectors'] ) ) {
				$rule['selectors'] = array_map(
					[ self::class, 'add_authored_selector_offset' ],
					$rule['selectors']
				);
			}

			$updated[] = $rule;
		}

		return $updated;
	}

	/**
	 * Bind a semantic compatibility selector to its Bricks element class.
	 *
	 * Bricks resets headings, text, and containers through element classes.
	 * Adding the matching class to the rightmost semantic target preserves the
	 * source selector's relative cascade without adding root-ID specificity.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Source selector scoped below an imported root.
	 * @return string Target-aware selector.
	 */
	private static function adapt_semantic_selector_to_bricks( $selector ) {
		$element_classes = [
			'p'       => 'brxe-text-basic',
			'h1'      => 'brxe-heading',
			'h2'      => 'brxe-heading',
			'h3'      => 'brxe-heading',
			'h4'      => 'brxe-heading',
			'h5'      => 'brxe-heading',
			'h6'      => 'brxe-heading',
			'div'     => 'brxe-div',
			'main'    => 'brxe-div',
			'nav'     => 'brxe-div',
			'article' => 'brxe-div',
			'aside'   => 'brxe-div',
			'figure'  => 'brxe-div',
			'ul'      => 'brxe-div',
			'ol'      => 'brxe-div',
			'li'      => 'brxe-div',
			'section' => 'brxe-section',
			'header'  => 'brxe-section',
			'footer'  => 'brxe-section',
		];

		return preg_replace_callback(
			'/(^|[\s>+~])(p|h[1-6]|div|main|nav|article|aside|figure|ul|ol|li|section|header|footer)((?:::{0,1}[a-z-]+(?:\([^)]*\))?)*)$/i',
			static function ( $matches ) use ( $element_classes ) {
				$tag = strtolower( $matches[2] );

				return $matches[1] . ':where(' . $matches[2] . ').' . $element_classes[ $tag ] . $matches[3];
			},
			trim( (string) $selector )
		);
	}

	/**
	 * Format a numeric pixel value without unnecessary trailing zeroes.
	 *
	 * @since 2.4
	 *
	 * @param float|int $value Pixel value.
	 * @return string CSS pixel value.
	 */
	private static function format_pixel_value( $value ) {
		$formatted = rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );

		return $formatted . 'px';
	}

	/**
	 * Return valid explicit rem root normalization settings.
	 *
	 * Ability input validation rejects partial or invalid pairs. This defensive
	 * check keeps direct converter callers opt-in and preserves legacy output.
	 *
	 * @since 2.4
	 *
	 * @param array $options Conversion options.
	 * @return array|null Normalization settings, or null when not configured.
	 */
	private static function get_rem_normalization_options( $options ) {
		$has_source = array_key_exists( 'source_root_font_size_px', $options );
		$has_target = array_key_exists( 'target_root_font_size_px', $options );

		if ( ! $has_source || ! $has_target ) {
			return null;
		}

		$source = $options['source_root_font_size_px'];
		$target = $options['target_root_font_size_px'];

		if (
			( ! is_int( $source ) && ! is_float( $source ) ) ||
			( ! is_int( $target ) && ! is_float( $target ) ) ||
			! is_finite( (float) $source ) ||
			! is_finite( (float) $target ) ||
			$source <= 0 ||
			$target <= 0
		) {
			return null;
		}

		return [
			'source_root_font_size_px' => (float) $source,
			'target_root_font_size_px' => (float) $target,
			'scale'                    => (float) $source / (float) $target,
		];
	}

	/**
	 * Build stable rem normalization metadata for converter consumers.
	 *
	 * @since 2.4
	 *
	 * @param array|null $normalization Valid rem normalization settings.
	 * @param int        $token_count  Normalizable rem token count.
	 * @return array Rem normalization metadata.
	 */
	private static function build_rem_normalization_metadata( $normalization, $token_count ) {
		$metadata = [
			'applied'                     => (bool) $normalization,
			'source_root_font_size_px'    => null,
			'target_root_font_size_px'    => null,
			'scale'                       => null,
			'detected_token_count'        => (int) $token_count,
			'converted_token_count'       => $normalization ? (int) $token_count : 0,
			'skipped_escaped_token_count' => 0,
		];

		if ( $normalization ) {
			$metadata = array_merge( $metadata, $normalization );
		}

		return $metadata;
	}

	/**
	 * Count normalizable rem tokens in temporary inline-style settings.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Converted Bricks elements.
	 * @return int Normalizable inline rem token count.
	 */
	private static function count_inline_rem_tokens( $elements ) {
		$count = 0;

		foreach ( $elements as $element ) {
			if ( ! empty( $element['settings']['_inlineStyle'] ) ) {
				$count += Html_To_Bricks_Css_Parser::count_normalizable_rem_tokens( $element['settings']['_inlineStyle'] );
			}
		}

		return $count;
	}

	/**
	 * Append owned keyframe CSS to global classes.
	 *
	 * When a keyframe is used by exactly one class, append it to that class's
	 * _cssCustom so it stays with the class rather than in a Code element.
	 *
	 * @since 2.4
	 *
	 * @param array $global_classes           Global class objects.
	 * @param array $class_keyframe_css_by_class Keyframe CSS per class name.
	 * @return array Updated global classes.
	 */
	private static function append_owned_keyframes_to_classes( $global_classes = [], $class_keyframe_css_by_class = [] ) {
		if ( ! is_array( $global_classes ) || empty( $global_classes ) ) {
			return $global_classes;
		}

		return array_map(
			function ( $global_class ) use ( $class_keyframe_css_by_class ) {
				$name = isset( $global_class['name'] ) ? $global_class['name'] : '';

				if ( ! $name || ! isset( $class_keyframe_css_by_class[ $name ] ) ) {
					return $global_class;
				}

				$keyframe_css  = $class_keyframe_css_by_class[ $name ];
				$updated_class = $global_class;

				if ( ! isset( $updated_class['settings'] ) ) {
					$updated_class['settings'] = [];
				}

				$existing_css_custom = isset( $updated_class['settings']['_cssCustom'] ) ? $updated_class['settings']['_cssCustom'] : '';
				$parts               = array_filter( [ $existing_css_custom, $keyframe_css ] );

				$updated_class['settings']['_cssCustom'] = trim( implode( "\n", $parts ) );

				return $updated_class;
			},
			$global_classes
		);
	}

	/**
	 * Map keyframes to the classes that own them.
	 *
	 * If a @keyframes rule is used by exactly one class, it's assigned to that class.
	 *
	 * @since 2.4
	 *
	 * @param array $class_rule_map Class rule map from extract_class_rules.
	 * @param array $css_rules      Parsed CSS rules.
	 * @return array {
	 *     @type array $classKeyframeCssByClass  Keyframe CSS strings per class name.
	 *     @type array $assignedKeyframeNames    Set of keyframe names assigned to a class.
	 * }
	 */
	private static function map_keyframes_to_owning_classes( $class_rule_map = [], $css_rules = [] ) {
		$keyframe_rules = array_filter(
			$css_rules,
			function ( $rule ) {
				return ! empty( $rule['isAtRule'] ) && isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'keyframes';
			}
		);

		if ( empty( $keyframe_rules ) ) {
			return [
				'classKeyframeCssByClass' => [],
				'assignedKeyframeNames'   => [],
			];
		}

		// Build a map of animation name => set of class names that use it
		$keyframe_users = [];

		foreach ( $class_rule_map as $class_name => $class_rules ) {
			$animation_names = self::extract_animation_names_for_class_rules( $class_rules );

			foreach ( $animation_names as $animation_name ) {
				if ( ! isset( $keyframe_users[ $animation_name ] ) ) {
					$keyframe_users[ $animation_name ] = [];
				}
				$keyframe_users[ $animation_name ][ $class_name ] = true;
			}
		}

		$class_keyframe_css_by_class = [];
		$assigned_keyframe_names     = [];

		foreach ( $keyframe_rules as $rule ) {
			$keyframe_name = self::normalize_animation_name( isset( $rule['atRuleParams'] ) ? $rule['atRuleParams'] : '' );

			if ( ! $keyframe_name ) {
				continue;
			}

			$users = isset( $keyframe_users[ $keyframe_name ] ) ? $keyframe_users[ $keyframe_name ] : [];

			if ( count( $users ) !== 1 ) {
				continue;
			}

			$owning_class = array_keys( $users )[0];

			if ( ! isset( $class_keyframe_css_by_class[ $owning_class ] ) ) {
				$class_keyframe_css_by_class[ $owning_class ] = [];
			}

			$class_keyframe_css_by_class[ $owning_class ][] = $rule['raw'];
			$assigned_keyframe_names[ $keyframe_name ]      = true;
		}

		// Join each class's keyframe CSS strings
		foreach ( $class_keyframe_css_by_class as $class_name => $css_parts ) {
			$class_keyframe_css_by_class[ $class_name ] = trim( implode( "\n\n", $css_parts ) );
		}

		return [
			'classKeyframeCssByClass' => $class_keyframe_css_by_class,
			'assignedKeyframeNames'   => $assigned_keyframe_names,
		];
	}

	/**
	 * Extract animation names from class rules.
	 *
	 * @since 2.4
	 *
	 * @param array $class_rules Class rules object with targets.
	 * @return array Animation names.
	 */
	private static function extract_animation_names_for_class_rules( $class_rules = [] ) {
		$names   = [];
		$targets = isset( $class_rules['targets'] ) && is_array( $class_rules['targets'] ) ? $class_rules['targets'] : [];

		foreach ( $targets as $target ) {
			$declarations = isset( $target['declarations'] ) ? $target['declarations'] : [];
			$extracted    = self::extract_animation_names_from_declarations( $declarations );

			foreach ( $extracted as $name ) {
				$names[ $name ] = true;
			}
		}

		return array_keys( $names );
	}

	/**
	 * Extract animation names from CSS declarations.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations.
	 * @return array Animation names.
	 */
	private static function extract_animation_names_from_declarations( $declarations = [] ) {
		$names = [];

		// animation-name property
		if ( ! empty( $declarations['animation-name'] ) ) {
			$values = self::split_css_list( $declarations['animation-name'] );

			foreach ( $values as $value ) {
				$normalized = self::normalize_animation_name( $value );

				if ( $normalized && $normalized !== 'none' ) {
					$names[ $normalized ] = true;
				}
			}
		}

		// animation shorthand property
		if ( ! empty( $declarations['animation'] ) ) {
			$items = self::split_css_list( $declarations['animation'] );

			foreach ( $items as $item ) {
				$normalized = self::extract_animation_name_from_shorthand( $item );

				if ( $normalized && $normalized !== 'none' ) {
					$names[ $normalized ] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * Extract animation name from shorthand value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS animation shorthand value.
	 * @return string Animation name or empty string.
	 */
	private static function extract_animation_name_from_shorthand( $value = '' ) {
		$tokens = preg_split( '/\s+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( $tokens as $token ) {
			$normalized = self::normalize_animation_name( $token );

			if ( ! $normalized ) {
				continue;
			}

			if ( isset( self::$animation_keywords[ $normalized ] ) ) {
				continue;
			}

			// Skip duration/delay values (e.g. "2s", "300ms", ".5s")
			if ( preg_match( '/^-?\d*\.?\d+m?s$/i', $normalized ) ) {
				continue;
			}

			// Skip iteration count (pure numbers)
			if ( preg_match( '/^-?\d*\.?\d+$/', $normalized ) ) {
				continue;
			}

			// Skip timing functions with parentheses
			if ( strpos( $normalized, '(' ) !== false ) {
				continue;
			}

			return $normalized;
		}

		return '';
	}

	/**
	 * Split CSS comma-separated list, respecting parentheses.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS value string.
	 * @return array Array of trimmed values.
	 */
	private static function split_css_list( $value = '' ) {
		$items       = [];
		$buffer      = '';
		$paren_depth = 0;
		$str         = (string) $value;

		$str_len = strlen( $str );

		for ( $i = 0; $i < $str_len; $i++ ) {
			$char = $str[ $i ];

			if ( $char === '(' ) {
				$paren_depth++;
				$buffer .= $char;
				continue;
			}

			if ( $char === ')' ) {
				$paren_depth = max( 0, $paren_depth - 1 );
				$buffer     .= $char;
				continue;
			}

			if ( $char === ',' && $paren_depth === 0 ) {
				$trimmed = trim( $buffer );

				if ( $trimmed !== '' ) {
					$items[] = $trimmed;
				}

				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		$trimmed = trim( $buffer );

		if ( $trimmed !== '' ) {
			$items[] = $trimmed;
		}

		return $items;
	}

	/**
	 * Normalize animation name by trimming and removing quotes.
	 *
	 * @since 2.4
	 *
	 * @param string $value Raw animation name.
	 * @return string Normalized name.
	 */
	private static function normalize_animation_name( $value = '' ) {
		return preg_replace( '/^[\'"]|[\'"]$/', '', trim( (string) $value ) );
	}
}

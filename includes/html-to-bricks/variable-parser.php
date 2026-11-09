<?php
/**
 * CSS Variable Parser for HTML to Bricks Converter
 *
 * Extracts CSS custom properties (variables) from :root and creates
 * Bricks global variables.
 *
 * PHP port of src/vue/utils/htmlToBricks/variableParser.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS variable parser for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Variable_Parser {
	/**
	 * Extract CSS variables from parsed CSS rules.
	 *
	 * Looks for :root selectors and extracts all custom properties (--*).
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed CSS rules from css parser.
	 * @return array Array of variable objects { name, value }.
	 */
	public static function extract_css_variables( $rules ) {
		$variables = [];

		foreach ( $rules as $rule ) {
			// Skip at-rules
			if ( ! empty( $rule['isAtRule'] ) ) {
				continue;
			}

			// Check for :root selector
			$has_root_selector = false;

			if ( ! empty( $rule['selectors'] ) ) {
				foreach ( $rule['selectors'] as $selector ) {
					if ( $selector === ':root' || $selector === 'html' || $selector === ':host' ) {
						$has_root_selector = true;
						break;
					}
				}
			}

			if ( ! $has_root_selector ) {
				continue;
			}

			// Extract CSS custom properties from declarations
			if ( ! empty( $rule['declarations'] ) ) {
				foreach ( $rule['declarations'] as $property => $value ) {
					if ( strpos( $property, '--' ) === 0 ) {
						$variables[] = [
							// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DeprecatedWhitelistCommentFound -- Not a whitelist comment.
							'name'  => substr( $property, 2 ),
							'value' => $value,
						];
					}
				}
			}
		}

		return $variables;
	}

	/**
	 * Parse CSS variables from a raw CSS string.
	 *
	 * Simpler parser for cases where you have raw CSS text containing
	 * variable declarations.
	 *
	 * @since 2.4
	 *
	 * @param string $css_text CSS text containing variable declarations.
	 * @return array Array of variable objects { name, value }.
	 */
	public static function parse_css_variables_from_text( $css_text ) {
		$variables = [];

		if ( ! $css_text || ! is_string( $css_text ) ) {
			return $variables;
		}

		// Split by semicolon and process each declaration
		$parts = explode( ';', $css_text );

		foreach ( $parts as $part ) {
			$declaration = trim( $part );

			if ( strpos( $declaration, '--' ) !== 0 ) {
				continue;
			}

			$colon_index = strpos( $declaration, ':' );

			if ( $colon_index === false ) {
				$variables[] = [
					'name'  => ltrim( $declaration, '-' ),
					'value' => '',
				];
			} else {
				$name  = trim( substr( $declaration, 0, $colon_index ) );
				$name  = preg_replace( '/^--/', '', $name );
				$value = trim( substr( $declaration, $colon_index + 1 ) );

				$variables[] = [
					'name'  => $name,
					'value' => $value,
				];
			}
		}

		return $variables;
	}

	/**
	 * Create global variable objects with IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $variables Array of { name, value } objects.
	 * @return array Array of GlobalVariable objects.
	 */
	public static function create_global_variables( $variables ) {
		return array_map(
			function ( $variable ) {
				return [
					'id'    => Html_To_Bricks_Element_Mapper::generate_id(),
					'name'  => $variable['name'],
					'value' => $variable['value'],
				];
			},
			$variables
		);
	}

	/**
	 * Process CSS variables and filter against existing ones.
	 *
	 * @since 2.4
	 *
	 * @param array $variables          Extracted variables { name, value }.
	 * @param array $existing_variables Existing global variables.
	 * @return array Result with newVariables and skippedVariables.
	 */
	public static function process_variables( $variables, $existing_variables = [] ) {
		$existing_names    = [];
		$new_variables     = [];
		$skipped_variables = [];

		foreach ( $existing_variables as $v ) {
			if ( isset( $v['name'] ) ) {
				$existing_names[ $v['name'] ] = true;
			}
		}

		foreach ( $variables as $variable ) {
			if ( isset( $existing_names[ $variable['name'] ] ) ) {
				$skipped_variables[] = $variable;
			} else {
				$new_variables[] = [
					'id'    => Html_To_Bricks_Element_Mapper::generate_id(),
					'name'  => $variable['name'],
					'value' => $variable['value'],
				];
			}
		}

		return [
			'newVariables'     => $new_variables,
			'skippedVariables' => $skipped_variables,
			'totalExtracted'   => count( $variables ),
		];
	}

	/**
	 * Extract and process CSS variables from parsed rules.
	 *
	 * Main entry point for variable extraction in the converter.
	 *
	 * @since 2.4
	 *
	 * @param array $rules              Parsed CSS rules.
	 * @param array $existing_variables Existing global variables.
	 * @return array Result with newVariables, skippedVariables, and counts.
	 */
	public static function extract_and_process_variables( $rules, $existing_variables = [] ) {
		$extracted_variables = self::extract_css_variables( $rules );
		return self::process_variables( $extracted_variables, $existing_variables );
	}

	/**
	 * Remove CSS custom properties from root-like selectors.
	 *
	 * Used to avoid duplicating imported variables in Code element cssCode.
	 * Keeps non-variable declarations intact.
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed CSS rules.
	 * @return array Rules with root variable declarations removed.
	 */
	public static function strip_root_css_variables_from_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return [];
		}

		$result = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) || ! isset( $rule['selectors'] ) || ! is_array( $rule['selectors'] ) ) {
				$result[] = $rule;
				continue;
			}

			$has_root_selector = false;

			foreach ( $rule['selectors'] as $selector ) {
				if ( $selector === ':root' || $selector === 'html' || $selector === ':host' ) {
					$has_root_selector = true;
					break;
				}
			}

			if ( ! $has_root_selector || ! isset( $rule['declarations'] ) || ! is_array( $rule['declarations'] ) ) {
				$result[] = $rule;
				continue;
			}

			$declarations = [];

			foreach ( $rule['declarations'] as $property => $value ) {
				if ( strpos( $property, '--' ) !== 0 ) {
					$declarations[ $property ] = $value;
				}
			}

			// Drop rule if all declarations were variables
			if ( empty( $declarations ) ) {
				continue;
			}

			$result[] = array_merge( $rule, [ 'declarations' => $declarations ] );
		}

		return $result;
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Array Query Parser
 *
 * Handles parsing of array strings for query loop functionality.
 * Supports JSON format and custom bracket notation with associative arrays.
 *
 * @since 2.2
 */
class Query_Array {
	public static $is_parsing_array_loop_dd = false; // Dynamic data rendering will refer to this flag to add 'is-array' filter to force array values convert to json string

	/**
	 * Get array data from query vars
	 *
	 * This method handles the integration with the Query class
	 *
	 * @param array $query_vars Query variables
	 * @param int   $post_id Post ID for dynamic data rendering
	 * @return array Array data for the query
	 */
	public static function get_array_data( $query_vars, $post_id, $query_instance ) {
		if ( ! isset( $query_vars['arrayEditor'] ) || empty( $query_vars['arrayEditor'] ) ) {
			return [];
		}

		// Set parsing flag for dynamic data rendering
		self::$is_parsing_array_loop_dd = true;

		// Render dynamic data
		$raw_string = bricks_render_dynamic_data( $query_vars['arrayEditor'], $post_id );

		// Reset parsing flag
		self::$is_parsing_array_loop_dd = false;

		// Convert string to array
		$parsed_array = self::parse( $raw_string );

		$result = is_array( $parsed_array ) ? $parsed_array : [];

		// Offset handling
		$offset_key = ! isset( $query_vars['pagination_enabled'] ) ? 'offset' : 'original_offset';
		$offset     = ! empty( $query_vars[ "$offset_key" ] ) ? (int) $query_vars[ "$offset_key" ] : 0;

		if ( $offset > 0 ) {
			$result = array_slice( $result, $offset );
		}

		// Filter array with conditions if provided
		if ( isset( $query_vars['array_conditions'] ) && is_array( $query_vars['array_conditions'] ) && ! empty( $query_vars['array_conditions'] ) ) {
			$result = self::apply_conditions( $result, $query_vars['array_conditions'], $post_id, $query_instance );
		}

		return $result;
	}

	/**
	 * Apply conditions to filter the array
	 *
	 * @param array $array Array to filter
	 * @param array $conditions Conditions to apply
	 * @return array Filtered array
	 */
	public static function apply_conditions( $array, $conditions, $post_id, $query_instance ) {
		$filtered = [];

		// Mimic query render() for each item, otherwise can't get the accurate dynamic data value
		$query_instance->is_looping = true;
		$query_instance->loop_index = $query_instance->init_loop_index();

		// $conditions is an array of condition arrays (key, operator, value)
		foreach ( $array as $item ) {
			$query_instance->loop_object = $item;
			// Parse and render dynamic data in conditions
			$executed_conditions  = self::process_dynamic_settings( $conditions, [ 'compare', 'id' ], '', $post_id );
			$meets_all_conditions = true;

			// Evaluate each condition
			foreach ( $executed_conditions as $condition ) {
				// Skip further checks in condition set if we already have a false condition inside this set
				if ( $meets_all_conditions === false ) {
					continue;
				}

				$value1   = $condition['key'] ?? '';
				$operator = $condition['compare'] ?? '==';
				$value2   = $condition['value'] ?? '';

				switch ( $operator ) {
					case '==':
						// Convert boolean-like strings to actual booleans
						Conditions::boolean_converter( $value1, $value2 );
						$meets_all_conditions = $value1 == $value2;
						break;
					case '!=':
						// Convert boolean-like strings to actual booleans
						Conditions::boolean_converter( $value1, $value2 );
						$meets_all_conditions = $value1 != $value2;
						break;

					case '>':
						$meets_all_conditions = floatval( $value1 ) > floatval( $value2 );
						break;

					case '<':
						$meets_all_conditions = floatval( $value1 ) < floatval( $value2 );
						break;

					case '>=':
						$meets_all_conditions = floatval( $value1 ) >= floatval( $value2 );
						break;

					case '<=':
						$meets_all_conditions = floatval( $value1 ) <= floatval( $value2 );
						break;

					case 'contains':
						if ( $value1 && gettype( $value1 ) === 'string' && gettype( $value2 ) === 'string' ) {
							$meets_all_conditions = strpos( $value1, $value2 ) !== false;
						} else {
							$meets_all_conditions = false;
						}
						break;

					case 'contains_not':
						if ( $value1 && gettype( $value1 ) === 'string' && gettype( $value2 ) === 'string' ) {
							$meets_all_conditions = strpos( $value1, $value2 ) === false;
						} else {
							$meets_all_conditions = true;
						}
						break;

					case 'empty':
						$meets_all_conditions = (string) $value1 === '';
						break;

					case 'empty_not':
						$meets_all_conditions = (string) $value1 !== '';
						break;
				}
			}

			if ( $meets_all_conditions ) {
				$filtered[] = $item;
			}

			$query_instance->loop_index++;
		}

		// Reset loop state
		$query_instance->is_looping  = false;
		$query_instance->loop_object = null;
		$query_instance->loop_index  = 0;

		return $filtered;
	}

	private static function process_dynamic_settings( $data, $excluded_keys, $current_key, $post_id ) {
		// If it's an array, process each element recursively
		if ( is_array( $data ) ) {
			$processed = [];

			foreach ( $data as $key => $value ) {
				$processed[ $key ] = self::process_dynamic_settings(
					$value,
					$excluded_keys,
					$key,
					$post_id
				);
			}

			return $processed;
		}

		// If it's a string and not in excluded keys, render dynamic data
		if ( is_string( $data ) && ! in_array( $current_key, $excluded_keys, true ) ) {
			// Reuse Conditions logic to auto add :value for ACF true_false fields
			$data = Conditions::maybe_transform_dynamic_tag( $data );
			return bricks_render_dynamic_data( $data, $post_id );
		}

		// Return unchanged for other data types or excluded keys
		return $data;
	}

	/**
	 * Parse array string safely with multiple fallback methods
	 *
	 * @param string $array_string The array string to parse
	 * @return array|false Parsed array or false on failure
	 */
	public static function parse( $array_string ) {
		if ( ! self::validate_input( $array_string ) ) {
			return false;
		}

		$array_string = trim( $array_string );

		// Try JSON decode first (fastest for valid JSON)
		$json_result = self::parse_json( $array_string );
		if ( $json_result !== false ) {
			return $json_result;
		}

		// Don't run bracket parser for malformed JSON-like payloads.
		// It can corrupt object data by splitting on commas/quotes. (#86c9e6yr0; @since 2.3.3)
		if ( self::is_likely_json_payload( $array_string ) ) {
			return false;
		}

		// Try custom bracket notation parsing (complicated)
		return self::parse_bracket_notation( $array_string );
	}

	/**
	 * Validate input string
	 *
	 * @param mixed $input Input to validate
	 * @return bool True if valid, false otherwise
	 */
	private static function validate_input( $input ) {
		if ( ! is_string( $input ) || $input === '' ) {
			return false;
		}

		$trimmed = trim( $input );

		// Must start with [ and end with ]
		return self::string_starts_with( $trimmed, '[' ) && self::string_ends_with( $trimmed, ']' );
	}

	/**
	 * Attempt to parse as JSON
	 *
	 * @param string $array_string Array string to parse
	 * @return array|false Parsed array or false on failure
	 */
	private static function parse_json( $array_string ) {
		$decoded = json_decode( $array_string, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded;
		}

		// If JSON parsing fails, attempt to repair common issues like unescaped quotes and try again.
		$repaired_json = self::repair_unescaped_quotes_in_json( $array_string );

		if ( $repaired_json !== $array_string ) {
			$decoded = json_decode( $repaired_json, true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return false;
	}

	/**
	 * Determine if input is JSON-like (not custom bracket notation).
	 *
	 * @param string $array_string Input string
	 * @return bool
	 *
	 * @since 2.3.3
	 */
	private static function is_likely_json_payload( $array_string ) {
		$trimmed = trim( $array_string );

		if ( $trimmed === '' ) {
			return false;
		}

		// Bracket-notation associative arrays should still be parsed by custom parser.
		if ( strpos( $trimmed, '=>' ) !== false ) {
			return false;
		}

		// Treat only array-of-object payloads as JSON-like to avoid broad false positives.
		return preg_match( '/^\[\s*\{/', $trimmed ) === 1;
	}

	/**
	 * Repair malformed JSON where unescaped double quotes appear inside string values.
	 *
	 * Example: "copy":"<ul style="margin:0">" -> style=\"margin:0\".
	 *
	 * @param string $json JSON string
	 * @return string Repaired JSON string
	 *
	 * @since 2.3.3
	 */
	private static function repair_unescaped_quotes_in_json( $json ) {
		$length     = strlen( $json );
		$result     = '';
		$in_string  = false;
		$is_escaped = false;
		$quote_char = '"';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $json[ $i ];

			if ( ! $in_string ) {
				if ( $char === $quote_char ) {
					$in_string = true;
				}

				$result .= $char;
				continue;
			}

			if ( $is_escaped ) {
				$result    .= $char;
				$is_escaped = false;
				continue;
			}

			if ( $char === '\\' ) {
				$result    .= $char;
				$is_escaped = true;
				continue;
			}

			if ( $char === $quote_char ) {
				$next_non_whitespace = self::next_non_whitespace_char( $json, $i + 1, $length );

				// If the quote is not ending a string token, escape it.
				if ( in_array( $next_non_whitespace, [ ':', ',', '}', ']' ], true ) ) {
					$in_string = false;
					$result   .= $char;
				} else {
					$result .= '\\"';
				}

				continue;
			}

			$result .= $char;
		}

		return $result;
	}

	/**
	 * Get the next non-whitespace character from a position.
	 *
	 * @param string $string Input string
	 * @param int    $start_index Start index
	 * @param int    $length String length
	 * @return string
	 *
	 * @since 2.3.3
	 */
	private static function next_non_whitespace_char( $string, $start_index, $length ) {
		// Ensure $string is string type to avoid PHP 8.1+ warnings when accessing characters.
		if ( ! is_string( $string ) || $start_index >= $length ) {
			return '';
		}

		for ( $i = $start_index; $i < $length; $i++ ) {
			if ( ! ctype_space( $string[ $i ] ) ) {
				return $string[ $i ];
			}
		}

		return '';
	}

	/**
	 * Parse custom bracket notation
	 *
	 * @param string $array_string Array string to parse
	 * @return array|false Parsed array or false on failure
	 */
	private static function parse_bracket_notation( $array_string ) {
		// Remove outer brackets
		$content = substr( $array_string, 1, -1 );

		if ( $content === '' ) {
			return [];
		}

		// Extract nested bracket pairs
		$bracket_pairs = self::extract_bracket_pairs( $content );

		if ( ! empty( $bracket_pairs ) ) {
			return self::parse_nested_arrays( $bracket_pairs );
		}

		// No nested brackets, parse as simple array
		return self::parse_simple_array( $content );
	}

	/**
	 * Extract bracket pairs from content
	 *
	 * @param string $content Content to extract from
	 * @return array Array of bracket pair contents
	 */
	private static function extract_bracket_pairs( $content ) {
		$bracket_pairs       = [];
		$current_pair        = '';
		$bracket_depth       = 0;
		$in_quotes           = false;
		$quote_char          = null;
		$inside_bracket_pair = false;
		$content_length      = strlen( $content );

		for ( $i = 0; $i < $content_length; $i++ ) {
			$char      = $content[ $i ];
			$prev_char = $i > 0 ? $content[ $i - 1 ] : null;

			// Handle quote state
			if ( self::is_quote_toggle( $char, $prev_char, $in_quotes, $quote_char ) ) {
				if ( ! $in_quotes ) {
					$in_quotes  = true;
					$quote_char = $char;
				} elseif ( $char === $quote_char ) {
					$in_quotes  = false;
					$quote_char = null;
				}
			}

			// Handle brackets only when not in quotes
			if ( ! $in_quotes ) {
				switch ( $char ) {
					case '[':
						if ( $bracket_depth === 0 ) {
							$inside_bracket_pair = true;
							$current_pair        = '';
						} else {
							$current_pair .= $char;
						}
						$bracket_depth++;
						break;

					case ']':
						$bracket_depth--;
						if ( $bracket_depth === 0 && $inside_bracket_pair ) {
							$bracket_pairs[]     = trim( $current_pair );
							$current_pair        = '';
							$inside_bracket_pair = false;
						} else {
							$current_pair .= $char;
						}
						break;

					default:
						if ( $inside_bracket_pair ) {
							$current_pair .= $char;
						}
						break;
				}
			} elseif ( $inside_bracket_pair ) {
				$current_pair .= $char;
			}
		}

		return $bracket_pairs;
	}

	/**
	 * Parse nested arrays from bracket pairs
	 *
	 * @param array $bracket_pairs Array of bracket pair contents
	 * @return array Parsed nested arrays
	 */
	private static function parse_nested_arrays( $bracket_pairs ) {
		$result = [];

		foreach ( $bracket_pairs as $pair ) {
			$parsed_pair = self::parse_single_pair( $pair );
			if ( $parsed_pair !== false ) {
				$result[] = $parsed_pair;
			}
		}

		return $result;
	}

	/**
	 * Parse a single bracket pair
	 *
	 * @param string $pair_content Content of the bracket pair
	 * @return array|false Parsed array or false on failure
	 */
	private static function parse_single_pair( $pair_content ) {
		$pair_content = trim( $pair_content );

		if ( $pair_content === '' ) {
			return [];
		}

		// Check if it's an associative array (contains =>)
		return self::string_contains( $pair_content, '=>' )
			? self::parse_associative_array( $pair_content )
			: self::parse_simple_array( $pair_content );
	}

	/**
	 * Parse associative array content
	 *
	 * @param string $content Content to parse
	 * @return array|false Parsed associative array or false on failure
	 */
	private static function parse_associative_array( $content ) {
		$result = [];
		$items  = self::split_array_items( $content );

		foreach ( $items as $item ) {
			$item = trim( $item );

			// Parse key => value pairs with more specific regex
			if ( preg_match( '/^([\'"])([^\'"]*)\1\s*=>\s*(.+)$/s', $item, $matches ) ) {
				$key            = $matches[2];
				$value_part     = trim( $matches[3] );
				$result[ $key ] = self::parse_value( $value_part );
			}
		}

		return empty( $result ) ? false : $result;
	}

	/**
	 * Parse different value types
	 *
	 * @param string $value_part Value string to parse
	 * @return mixed Parsed value
	 */
	private static function parse_value( $value_part ) {
		// String value in quotes
		if ( preg_match( '/^([\'"])([^\'"]*)\1$/s', $value_part, $value_matches ) ) {
			return $value_matches[2];
		}

		// Numeric value
		if ( is_numeric( $value_part ) ) {
			return self::string_contains( $value_part, '.' )
				? (float) $value_part
				: (int) $value_part;
		}

		// Boolean value
		$lower_value = strtolower( $value_part );
		if ( in_array( $lower_value, [ 'true', 'false' ], true ) ) {
			return $lower_value === 'true';
		}

		// Default to string
		return $value_part;
	}

	/**
	 * PHP 7.4-compatible starts-with helper.
	 *
	 * @param string $haystack Full string
	 * @param string $needle Prefix
	 * @return bool
	 *
	 * @since 2.3.3
	 */
	private static function string_starts_with( $haystack, $needle ) {
		if ( $needle === '' ) {
			return true;
		}

		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}

	/**
	 * PHP 7.4-compatible ends-with helper.
	 *
	 * @param string $haystack Full string
	 * @param string $needle Suffix
	 * @return bool
	 *
	 * @since 2.3.3
	 */
	private static function string_ends_with( $haystack, $needle ) {
		if ( $needle === '' ) {
			return true;
		}

		$needle_length = strlen( $needle );
		$hay_length    = strlen( $haystack );

		if ( $needle_length > $hay_length ) {
			return false;
		}

		return substr( $haystack, -$needle_length ) === $needle;
	}

	/**
	 * PHP 7.4-compatible contains helper.
	 *
	 * @param string $haystack Full string
	 * @param string $needle Substring
	 * @return bool
	 *
	 * @since 2.3.3
	 */
	private static function string_contains( $haystack, $needle ) {
		if ( $needle === '' ) {
			return true;
		}

		return strpos( $haystack, $needle ) !== false;
	}

	/**
	 * Parse simple array content
	 *
	 * @param string $content Content to parse
	 * @return array Parsed simple array
	 */
	private static function parse_simple_array( $content ) {
		$items  = self::split_array_items( $content );
		$result = [];

		foreach ( $items as $item ) {
			$item = trim( $item, " \t\n\r\0\x0B\"'`" );

			if ( $item !== '' ) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Split array items by comma, respecting quotes and nested brackets
	 *
	 * @param string $content Content to split
	 * @return array Array of items
	 */
	private static function split_array_items( $content ) {
		$items          = [];
		$current_item   = '';
		$quote_char     = null;
		$bracket_depth  = 0;
		$in_quotes      = false;
		$content_length = strlen( $content );

		for ( $i = 0; $i < $content_length; $i++ ) {
			$char      = $content[ $i ];
			$prev_char = $i > 0 ? $content[ $i - 1 ] : null;

			// Handle quote state
			if ( self::is_quote_toggle( $char, $prev_char, $in_quotes, $quote_char ) ) {
				if ( ! $in_quotes ) {
					$in_quotes  = true;
					$quote_char = $char;
				} elseif ( $char === $quote_char ) {
					$in_quotes  = false;
					$quote_char = null;
				}
			}

			// Handle brackets for nested arrays
			if ( ! $in_quotes ) {
				if ( $char === '[' ) {
					$bracket_depth++;
				} elseif ( $char === ']' ) {
					$bracket_depth--;
				}
			}

			// Split on comma only if not in quotes and not in nested brackets
			if ( $char === ',' && ! $in_quotes && $bracket_depth === 0 ) {
				if ( $current_item !== '' ) {
					$items[] = $current_item;
				}
				$current_item = '';
			} else {
				$current_item .= $char;
			}
		}

		// Add the last item if not empty
		if ( $current_item !== '' ) {
			$items[] = $current_item;
		}

		return $items;
	}

	/**
	 * Check if character should toggle quote state
	 *
	 * @param string      $char Current character
	 * @param string|null $prev_char Previous character
	 * @param bool        $in_quotes Current quote state
	 * @param string|null $quote_char Current quote character
	 * @return bool True if quote state should toggle
	 */
	private static function is_quote_toggle( $char, $prev_char, $in_quotes, $quote_char ) {
		return in_array( $char, [ '"', "'" ], true ) && $prev_char !== '\\';
	}
}

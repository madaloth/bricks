<?php
namespace Bricks\Integrations\Dynamic_Data;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Class Dynamic_Data_Parser
 *
 * Parses arguments for dynamic data tags, including filters and key-value pairs.
 */
class Dynamic_Data_Parser {
	/**
	 * The input string to parse
	 *
	 * @var string
	 */
	private $input;

	/**
	 * List of allowed keys for arguments
	 *
	 * @var array
	 */
	private static $allowed_keys;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->set_allowed_keys();
	}

	/**
	 * Parse the given input string
	 *
	 * @param string $input The input string to parse.
	 * @return array Associative array with 'tag', 'args', and 'original_tag'.
	 */
	public function parse( $input ) {
		$this->input = $input;
		return $this->parse_tag_and_args();
	}

	/**
	 * Parse the tag and its arguments
	 *
	 * @return array Associative array with 'tag', 'args', and 'original_tag'
	 */
	private function parse_tag_and_args() {
		// Preserve colons inside echo function arguments while keeping filters after the function call.
		if ( strpos( trim( $this->input ), 'echo:' ) === 0 ) {
			return $this->parse_echo_tag();
		}

		// Split at top-level key-value arguments only; nested tags can contain their own @key arguments (#86cagh1f8).
		$parts = $this->split_at_kv_args( $this->input );

		// Parse the tag and filters
		// Taxonomy separators are literal text; trimming the tag would change their output (#123pt1nrptv).
		$is_post_terms   = strpos( $parts[0], 'post_terms_' ) === 0 && strpos( $parts[0], ':' ) !== false;
		$tag_and_filters = explode( ':', $is_post_terms ? $parts[0] : rtrim( $parts[0] ) );
		$tag             = array_shift( $tag_and_filters );

		$args          = [];
		$named_filters = [ 'context', 'prefix', 'image', 'link', 'newTab', 'tel', 'value', 'raw', 'url', 'format', 'plain', 'slug', 'term_id' ];

		// Add filters to args with numeric keys
		foreach ( $tag_and_filters as $index => $filter ) {
			// Preserve literal separator parts, including colons, while retaining modifier cleanup.
			if ( $is_post_terms && $filter !== '' && ! ctype_space( $filter[0] ) ) {
				$trimmed_filter = rtrim( $filter );

				if ( in_array( $trimmed_filter, $named_filters, true ) || is_numeric( $trimmed_filter ) || strpos( $trimmed_filter, 'array_value|' ) === 0 ) {
					$filter = $trimmed_filter;
				}
			}

			$args[ $index ] = $filter;
		}

		// Parse key-value arguments if they exist
		if ( isset( $parts[1] ) ) {
			$kv_args = $this->parse_kv_args( $parts[1] );
			$args    = array_merge( $args, $kv_args );
		}

		return [
			'tag'          => $tag,
			'args'         => $args,
			'original_tag' => $this->input
		];
	}

	/**
	 * Parse the key-value arguments of the tag while preserving nested arguments (#86cagh1f8).
	 *
	 * @param string $args_string The string containing all arguments.
	 * @return array Associative array of arguments
	 */
	private function parse_kv_args( $args_string ) {
		$args     = [];
		$position = 0;
		$length   = strlen( $args_string );

		while ( $position < $length ) {
			$arg_position = $this->find_kv_arg_position( $args_string, $position );

			if ( $arg_position === false ) {
				break;
			}

			$key_end = strpos( $args_string, ':', $arg_position );

			if ( $key_end === false ) {
				break;
			}

			$key         = substr( $args_string, $arg_position + 1, $key_end - $arg_position - 1 );
			$value_start = $key_end + 1;
			$next_arg    = $this->find_kv_arg_position( $args_string, $value_start );
			$value_end   = $next_arg === false ? $length : $next_arg;
			$value       = $this->unwrap_quoted_value( trim( substr( $args_string, $value_start, $value_end - $value_start ) ) );

			if ( in_array( $key, self::get_allowed_keys(), true ) ) {
				$args[ $key ] = $value;
			}

			if ( $next_arg === false ) {
				break;
			}

			$position = $next_arg;
		}

		return $args;
	}

	/**
	 * Split the tag/filter section from top-level key-value arguments (#86cagh1f8).
	 *
	 * @param string $input Dynamic tag content without outer braces.
	 * @return array
	 */
	private function split_at_kv_args( $input ) {
		$position = $this->find_kv_arg_position( $input );

		if ( $position === false ) {
			return [ $input ];
		}

		$tag_and_filters = substr( $input, 0, $position );

		if ( strpos( $tag_and_filters, 'post_terms_' ) === 0 && strpos( $tag_and_filters, ':' ) !== false ) {
			// One whitespace character separates @args; preceding spaces belong to the separator (#123pt1nrptv).
			if ( $tag_and_filters !== '' && ctype_space( substr( $tag_and_filters, -1 ) ) ) {
				$tag_and_filters = substr( $tag_and_filters, 0, -1 );
			}
		} else {
			$tag_and_filters = rtrim( $tag_and_filters );
		}

		return [ $tag_and_filters, substr( $input, $position ) ];
	}

	/**
	 * Find the next allowed key-value argument outside nested syntax (#86cagh1f8).
	 *
	 * @param string $input  Dynamic tag content.
	 * @param int    $offset Position to start scanning from.
	 * @return int|false
	 */
	private function find_kv_arg_position( $input, $offset = 0 ) {
		$quote       = '';
		$escaped     = false;
		$brace_depth = 0;
		$paren_depth = 0;
		$length      = strlen( $input );

		for ( $i = $offset; $i < $length; $i++ ) {
			$char = $input[ $i ];

			if ( $escaped ) {
				$escaped = false;
				continue;
			}

			if ( $quote && $char === '\\' ) {
				$escaped = true;
				continue;
			}

			if ( $char === '{' && ( ! $quote || $this->has_closing_brace( $input, $i ) ) ) {
				$brace_depth++;
				continue;
			}

			if ( $char === '}' && $brace_depth > 0 ) {
				$brace_depth--;
				continue;
			}

			if ( $quote && $brace_depth > 0 ) {
				continue;
			}

			if ( $quote ) {
				if ( $char === $quote && $this->is_closing_quote( $input, $i ) ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '\'' || $char === '"' ) {
				if ( $this->is_opening_quote( $input, $i ) ) {
					$quote = $char;
				}

				continue;
			}

			if ( $char === '(' ) {
				$paren_depth++;
				continue;
			}

			if ( $char === ')' && $paren_depth > 0 ) {
				$paren_depth--;
				continue;
			}

			if ( $char === '@' && $brace_depth === 0 && $paren_depth === 0 && $this->is_allowed_key_marker( $input, $i ) ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Check whether the opening brace has a later closing brace (#86cagh1f8).
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Opening brace position.
	 * @return bool
	 */
	private function has_closing_brace( $input, $position ) {
		return strpos( $input, '}', $position + 1 ) !== false;
	}

	/**
	 * Check whether the current @ starts an allowed key-value argument.
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Position of the @ character.
	 * @return bool
	 */
	private function is_allowed_key_marker( $input, $position ) {
		foreach ( self::get_allowed_keys() as $key ) {
			$key_length = strlen( $key );

			if ( substr( $input, $position + 1, $key_length ) === $key && substr( $input, $position + $key_length + 1, 1 ) === ':' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove matching surrounding quotes from a parsed value.
	 *
	 * @param string $value Parsed argument value.
	 * @return string
	 */
	private function unwrap_quoted_value( $value ) {
		if ( strlen( $value ) < 2 ) {
			return $value;
		}

		$quote = $value[0];

		if ( ( $quote === '\'' || $quote === '"' ) && substr( $value, -1 ) === $quote ) {
			return substr( $value, 1, -1 );
		}

		return $value;
	}

	/**
	 * Check whether a quote starts a quoted argument.
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Quote position.
	 * @return bool
	 */
	private function is_opening_quote( $input, $position ) {
		return in_array( $this->get_previous_non_space( $input, $position ), [ '', '(', ',', ':' ], true );
	}

	/**
	 * Check whether a quote closes a quoted argument.
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Quote position.
	 * @return bool
	 */
	private function is_closing_quote( $input, $position ) {
		return in_array( $this->get_next_non_space( $input, $position ), [ '', ',', ')', '}', '@' ], true );
	}

	/**
	 * Get the previous non-space character.
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Current position.
	 * @return string
	 */
	private function get_previous_non_space( $input, $position ) {
		for ( $i = $position - 1; $i >= 0; $i-- ) {
			if ( $input[ $i ] === ' ' ) {
				continue;
			}

			return $input[ $i ];
		}

		return '';
	}

	/**
	 * Get the next non-space character.
	 *
	 * @param string $input    Dynamic tag content.
	 * @param int    $position Current position.
	 * @return string
	 */
	private function get_next_non_space( $input, $position ) {
		$length = strlen( $input );

		for ( $i = $position + 1; $i < $length; $i++ ) {
			if ( $input[ $i ] === ' ' ) {
				continue;
			}

			return $input[ $i ];
		}

		return '';
	}

	/**
	 * Set the allowed keys for arguments
	 * Uses the 'bricks/dynamic_data/allowed_keys' filter to allow modification of the allowed keys.
	 *
	 * TEXT: @fallback:'Just some text'
	 * IMAGE: @fallback-image:123 (Image ID or URL)
	 * SANITIZE: @sanitize:false (@since 1.11.1)
	 * EXCLUDE: @exclude:q1w2e3,880712 ({active_filters_count @query:'mn9456' @exclude:'q1w2e3,880712'} @since 2.0)
	 * START-AT: @start-at:1 (query_loop_index; @since 2.1)
	 * PAD: @pad:3 (query_loop_index; @since 2.1)
	 * KEY: @key:'title|rendered' (For {query_api} @since 2.1)
	 * IS-ARRAY: Only internal user to force array convert to json string in Array loop (@since 2.2)
	 * DATE, FROM, TO: @date:'2024-01-01' @from:'Y-m-d' @to:'d/m/Y' (for {format_date}; @since 2.2)
	 */
	public function set_allowed_keys() {
		$default_keys = [ 'fallback', 'fallback-image', 'sanitize', 'exclude', 'start-at', 'pad', 'key', 'is-array', 'date', 'from', 'to' ];

		// NOTE: Undocumented
		self::$allowed_keys = apply_filters( 'bricks/dynamic_data/allowed_keys', $default_keys );
	}

	/**
	 * Get the allowed keys
	 *
	 * @return array
	 *
	 * @since 2.0
	 */
	public static function get_allowed_keys() {
		return (array) self::$allowed_keys;
	}

	/**
	 * Parse echo tags specially to preserve function arguments with colons
	 *
	 * @return array Associative array with 'tag', 'args', and 'original_tag'
	 * @since 2.0 (#86c45bh2y)
	 */
	private function parse_echo_tag() {
		// Split at top-level key-value arguments only; echo callbacks can contain nested dynamic tags (#86cagh1f8).
		$parts = $this->split_at_kv_args( $this->input );

		$echo_part = trim( $parts[0] ); // This contains "echo:function_name(args):filter"

		// For echo tags, find the first colon and treat everything after as the function call
		$colon_pos = strpos( $echo_part, ':' );

		if ( $colon_pos === false ) {
			// No colon found, treat as simple echo tag
			$tag           = $echo_part;
			$function_call = '';
		} else {
			$tag           = substr( $echo_part, 0, $colon_pos ); // "echo"
			$function_call = trim( substr( $echo_part, $colon_pos + 1 ) ); // "function_name(args):filter"
		}

		// Parse the function call and filters from the echo part
		$args = $this->parse_echo_function_call_and_filters( $function_call );

		// Parse key-value arguments if they exist
		if ( isset( $parts[1] ) ) {
			$kv_args = $this->parse_kv_args( $parts[1] );
			$args    = array_merge( $args, $kv_args );
		}

		return [
			'tag'          => $tag,
			'args'         => $args,
			'original_tag' => $this->input
		];
	}

	/**
	 * Parse the echo function call and filters after it.
	 *
	 * @param string $echo_args Echo tag arguments without the "echo:" prefix.
	 * @return array Numeric echo arguments and filters.
	 */
	private function parse_echo_function_call_and_filters( $echo_args ) {
		$args = [];

		if ( $echo_args === '' ) {
			return $args;
		}

		$function_call_end = $this->get_echo_function_call_end_position( $echo_args );

		if ( $function_call_end === false ) {
			return strpos( $echo_args, '(' ) !== false ? [ $echo_args ] : $this->parse_colon_filters( $echo_args );
		}

		$function_call = trim( substr( $echo_args, 0, $function_call_end + 1 ) );

		if ( $function_call !== '' ) {
			$args[] = $function_call;
		}

		$filters = $this->parse_colon_filters( substr( $echo_args, $function_call_end + 1 ) );

		return array_merge( $args, $filters );
	}

	/**
	 * Find the matching closing parenthesis of an echo function call.
	 *
	 * @param string $echo_args Echo tag arguments without the "echo:" prefix.
	 * @return int|false Closing parenthesis position, or false if not a function call.
	 */
	private function get_echo_function_call_end_position( $echo_args ) {
		if ( ! preg_match( '/^[a-zA-Z0-9_]+\(/', $echo_args ) ) {
			return false;
		}

		$depth    = 0;
		$in_quote = false;
		$length   = strlen( $echo_args );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $echo_args[ $i ];

			if ( $char === '\'' ) {
				$in_quote = ! $in_quote;
				continue;
			}

			if ( $in_quote ) {
				continue;
			}

			if ( $char === '(' ) {
				$depth++;
				continue;
			}

			if ( $char === ')' && $depth > 0 ) {
				$depth--;

				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return false;
	}

	/**
	 * Parse colon-separated filters.
	 *
	 * @param string $filters_string Filter string.
	 * @return array Parsed filters.
	 */
	private function parse_colon_filters( $filters_string ) {
		$filters_string = ltrim( trim( $filters_string ), ':' );

		if ( $filters_string === '' ) {
			return [];
		}

		return explode( ':', $filters_string );
	}
}

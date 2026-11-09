<?php
/**
 * CSS Parser for HTML to Bricks Converter
 *
 * Parses CSS strings and extracts rules, selectors, and properties.
 *
 * PHP port of src/vue/utils/htmlToBricks/cssParser.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS parser for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Css_Parser {
	/**
	 * Normalize numeric rem dimensions between explicit source and target roots.
	 *
	 * Strings, comments, URLs, and media-query preludes are intentionally left
	 * untouched. This keeps content tokens and responsive conditions faithful
	 * while translating declaration values to Bricks' configured root scale.
	 *
	 * @since 2.4
	 *
	 * @param string    $css_string               CSS source.
	 * @param int|float $source_root_font_size_px Source root font size in pixels.
	 * @param int|float $target_root_font_size_px Target root font size in pixels.
	 * @param int|null  $token_count              Number of normalized tokens.
	 * @return string Normalized CSS source.
	 */
	public static function normalize_rem_units( $css_string, $source_root_font_size_px, $target_root_font_size_px, &$token_count = null ) {
		$scale = (float) $source_root_font_size_px / (float) $target_root_font_size_px;

		return self::process_rem_units( (string) $css_string, $scale, $token_count );
	}

	/**
	 * Count rem dimensions that are safe for root normalization.
	 *
	 * @since 2.4
	 *
	 * @param string $css_string CSS source.
	 * @return int Normalizable rem token count.
	 */
	public static function count_normalizable_rem_tokens( $css_string ) {
		$token_count = 0;
		self::process_rem_units( (string) $css_string, null, $token_count );

		return $token_count;
	}

	/**
	 * Scan CSS dimensions without mutating syntax-sensitive regions.
	 *
	 * A null scale provides detection-only behavior. The scanner preserves the
	 * original source byte-for-byte unless a numeric rem token is normalized.
	 *
	 * @since 2.4
	 *
	 * @param string     $css_string CSS source.
	 * @param float|null $scale      Root-size scale, or null for detection only.
	 * @param int|null   $token_count Matched token count.
	 * @return string Processed CSS source.
	 */
	private static function process_rem_units( $css_string, $scale, &$token_count = null ) {
		$length           = strlen( $css_string );
		$output           = '';
		$matches          = 0;
		$url_depth        = 0;
		$in_media_prelude = false;

		for ( $index = 0; $index < $length; ) {
			$char = $css_string[ $index ];

			if ( $char === '/' && $index + 1 < $length && $css_string[ $index + 1 ] === '*' ) {
				$comment_end = strpos( $css_string, '*/', $index + 2 );
				$segment_end = $comment_end === false ? $length : $comment_end + 2;
				$output     .= substr( $css_string, $index, $segment_end - $index );
				$index       = $segment_end;
				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote   = $char;
				$output .= $char;
				++$index;

				while ( $index < $length ) {
					$quoted_char = $css_string[ $index ];
					$output     .= $quoted_char;
					++$index;

					if ( $quoted_char === '\\' && $index < $length ) {
						$output .= $css_string[ $index ];
						++$index;
						continue;
					}

					if ( $quoted_char === $quote ) {
						break;
					}
				}

				continue;
			}

			if ( $url_depth > 0 ) {
				$output .= $char;

				if ( $char === '\\' && $index + 1 < $length ) {
					$output .= $css_string[ $index + 1 ];
					$index  += 2;
					continue;
				}

				if ( $char === '(' ) {
					++$url_depth;
				} elseif ( $char === ')' ) {
					--$url_depth;
				}

				++$index;
				continue;
			}

			if ( $char === '@' && preg_match( '/\A@media(?![a-zA-Z0-9_-])/i', substr( $css_string, $index ) ) ) {
				$in_media_prelude = true;
			}

			if ( $char === '(' && preg_match( '/(?:^|[^a-zA-Z0-9_-])url$/i', $output ) ) {
				$url_depth = 1;
				$output   .= $char;
				++$index;
				continue;
			}

			if ( $in_media_prelude ) {
				$output .= $char;
				++$index;

				if ( $char === '{' ) {
					$in_media_prelude = false;
				}

				continue;
			}

			$previous_char = $index > 0 ? $css_string[ $index - 1 ] : '';

			if (
				( $previous_char === '' || ! preg_match( '/[a-zA-Z0-9_-]/', $previous_char ) ) &&
				preg_match( '/\A[+-]?(?:\d+\.\d*|\.\d+|\d+)(?:[eE][+-]?\d+)?rem(?![a-zA-Z0-9_-])/i', substr( $css_string, $index ), $rem_match )
			) {
				++$matches;

				if ( $scale === null ) {
					$output .= $rem_match[0];
				} else {
					$numeric_value = (float) substr( $rem_match[0], 0, -3 );
					$scaled_value  = round( $numeric_value * $scale, 6, PHP_ROUND_HALF_UP );
					$formatted     = rtrim( rtrim( number_format( $scaled_value, 6, '.', '' ), '0' ), '.' );

					if ( $formatted === '-0' || $formatted === '' ) {
						$formatted = '0';
					}

					$output .= $formatted . 'rem';
				}

				$index += strlen( $rem_match[0] );
				continue;
			}

			$output .= $char;
			++$index;
		}

		$token_count = $matches;

		return $output;
	}

	/**
	 * Parse CSS string into structured rules.
	 *
	 * @since 2.4
	 *
	 * @param string $css_string CSS string to parse.
	 * @return array Array of CSS rule objects.
	 */
	public static function parse_css( $css_string ) {
		if ( ! $css_string || ! is_string( $css_string ) ) {
			return [];
		}

		$rules = [];

		try {
			// Remove comments
			$css   = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css_string );
			$rules = self::parse_rules_block( $css );
		} catch ( \Exception $e ) {
			// Silently handle parse errors
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Bricks HTML Import] CSS parsing error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return $rules;
	}

	/**
	 * Find the closing brace matching an opening brace.
	 *
	 * @since 2.4
	 *
	 * @param string $css        CSS source.
	 * @param int    $open_index Opening brace index.
	 * @return int Closing brace index, or -1.
	 */
	private static function find_matching_brace( $css, $open_index ) {
		$depth  = 0;
		$quote  = '';
		$length = strlen( $css );

		for ( $i = $open_index; $i < $length; $i++ ) {
			$char     = $css[ $i ];
			$previous = $i > 0 ? $css[ $i - 1 ] : '';

			if ( $quote ) {
				if ( $char === $quote && $previous !== '\\' ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote = $char;
				continue;
			}

			if ( $char === '{' ) {
				++$depth;
			}

			if ( $char === '}' ) {
				--$depth;

				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return -1;
	}

	/**
	 * Indent CSS by two spaces.
	 *
	 * @since 2.4
	 *
	 * @param string $css CSS source.
	 * @return string Indented CSS.
	 */
	private static function indent_css( $css = '' ) {
		return implode(
			"\n",
			array_map(
				function ( $line ) {
					return $line ? "  {$line}" : $line;
				},
				explode( "\n", (string) $css )
			)
		);
	}

	/**
	 * Parse a CSS block, preserving media-rule ownership context.
	 *
	 * @since 2.4
	 *
	 * @param string $css             CSS source.
	 * @param array  $parent_at_rules Parent at-rules.
	 * @param array  $parent_media    Parent media breakpoint context.
	 * @return array Parsed rules.
	 */
	private static function parse_rules_block( $css, $parent_at_rules = [], $parent_media = [] ) {
		$rules  = [];
		$index  = 0;
		$length = strlen( $css );

		while ( $index < $length ) {
			while ( $index < $length && preg_match( '/\s/', $css[ $index ] ) ) {
				++$index;
			}

			if ( $index >= $length ) {
				break;
			}

			$open_index      = strpos( $css, '{', $index );
			$semicolon_index = strpos( $css, ';', $index );

			if ( $open_index === false ) {
				if ( $css[ $index ] === '@' && $semicolon_index !== false ) {
					$raw = trim( substr( $css, $index, $semicolon_index - $index + 1 ) );

					if ( preg_match( '/^@([a-z-]+)\s*([^;]*)/i', $raw, $at_rule_match ) ) {
						$rules[] = [
							'selectors'    => [],
							'declarations' => [],
							'raw'          => $raw,
							'isAtRule'     => true,
							'atRuleName'   => $at_rule_match[1],
							'atRuleParams' => isset( $at_rule_match[2] ) ? trim( $at_rule_match[2] ) : '',
							'atRules'      => $parent_at_rules,
						];
					}

					$index = $semicolon_index + 1;
					continue;
				}

				break;
			}

			if ( $css[ $index ] === '@' && $semicolon_index !== false && $semicolon_index < $open_index ) {
				$raw = trim( substr( $css, $index, $semicolon_index - $index + 1 ) );

				if ( preg_match( '/^@([a-z-]+)\s*([^;]*)/i', $raw, $at_rule_match ) ) {
					$rules[] = [
						'selectors'    => [],
						'declarations' => [],
						'raw'          => $raw,
						'isAtRule'     => true,
						'atRuleName'   => $at_rule_match[1],
						'atRuleParams' => isset( $at_rule_match[2] ) ? trim( $at_rule_match[2] ) : '',
						'atRules'      => $parent_at_rules,
					];
				}

				$index = $semicolon_index + 1;
				continue;
			}

			$close_index = self::find_matching_brace( $css, $open_index );

			if ( $close_index === -1 ) {
				break;
			}

			$header = trim( substr( $css, $index, $open_index - $index ) );
			$block  = trim( substr( $css, $open_index + 1, $close_index - $open_index - 1 ) );
			$raw    = trim( substr( $css, $index, $close_index - $index + 1 ) );

			if ( ! $header || ! $block ) {
				$index = $close_index + 1;
				continue;
			}

			if ( strpos( $header, '@' ) === 0 ) {
				preg_match( '/^@([a-z-]+)\s*(.*)$/i', $header, $at_rule_match );

				$at_rule_name    = isset( $at_rule_match[1] ) ? $at_rule_match[1] : '';
				$at_rule_params  = isset( $at_rule_match[2] ) ? trim( $at_rule_match[2] ) : '';
				$at_rule_context = [
					'name'   => $at_rule_name,
					'params' => $at_rule_params,
				];
				$media_context   = $parent_media;

				if ( $at_rule_name === 'media' ) {
					$media_context = [
						'breakpoint' => self::resolve_breakpoint_from_media_query( $at_rule_params ),
						'mediaQuery' => $at_rule_params,
					];
				}

				$rules[] = [
					'selectors'    => [],
					'declarations' => [],
					'raw'          => $raw,
					'isAtRule'     => true,
					'atRuleName'   => $at_rule_name,
					'atRuleParams' => $at_rule_params,
					'nestedRules'  => $at_rule_name === 'media'
						? self::parse_rules_block( $block, array_merge( $parent_at_rules, [ $at_rule_context ] ), $media_context )
						: [],
					'atRules'      => $parent_at_rules,
				];

				$index = $close_index + 1;
				continue;
			}

			$selectors    = self::split_selector_list( $header );
			$declarations = self::parse_declarations( $block );

			if ( ! empty( $selectors ) && ! empty( $declarations ) ) {
				$rules[] = [
					'selectors'    => $selectors,
					'declarations' => $declarations,
					'raw'          => $raw,
					'isAtRule'     => false,
					'atRules'      => $parent_at_rules,
					'breakpoint'   => isset( $parent_media['breakpoint'] ) ? $parent_media['breakpoint'] : '',
					'mediaQuery'   => isset( $parent_media['mediaQuery'] ) ? $parent_media['mediaQuery'] : '',
				];
			}

			$index = $close_index + 1;
		}

		return $rules;
	}

	/**
	 * Return the default Bricks breakpoints used by the converter fallback.
	 *
	 * @since 2.4
	 *
	 * @return array Breakpoint rows.
	 */
	private static function get_default_breakpoints() {
		return [
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
	}

	/**
	 * Return known breakpoints from Bricks when available.
	 *
	 * @since 2.4
	 *
	 * @return array Breakpoint rows.
	 */
	private static function get_known_breakpoints() {
		if ( class_exists( '\Bricks\Breakpoints' ) && ! empty( Breakpoints::$breakpoints ) ) {
			return array_values(
				array_filter(
					Breakpoints::$breakpoints,
					function ( $breakpoint ) {
						return empty( $breakpoint['base'] ) && ! empty( $breakpoint['key'] ) && ! empty( $breakpoint['width'] );
					}
				)
			);
		}

		return self::get_default_breakpoints();
	}

	/**
	 * Resolve a media query to a Bricks breakpoint key.
	 *
	 * @since 2.4
	 *
	 * @param string $media_query Media query contents without the @media token.
	 * @return string Breakpoint key, or empty string when unsupported.
	 */
	public static function resolve_breakpoint_from_media_query( $media_query = '' ) {
		if ( ! preg_match( '/max-width\s*:\s*(\d+(?:\.\d+)?)px/i', (string) $media_query, $match ) ) {
			return '';
		}

		$width = (float) $match[1];

		foreach ( self::get_known_breakpoints() as $breakpoint ) {
			if ( isset( $breakpoint['width'] ) && (float) $breakpoint['width'] === $width ) {
				return isset( $breakpoint['key'] ) ? $breakpoint['key'] : '';
			}
		}

		return '';
	}

	/**
	 * Split a CSS selector list while respecting commas inside functions,
	 * attribute selectors, and quoted strings.
	 *
	 * @since 2.4
	 *
	 * @param string $selector_text Raw selector list.
	 * @return array Individual selectors.
	 */
	public static function split_selector_list( $selector_text = '' ) {
		$selectors     = [];
		$buffer        = '';
		$paren_depth   = 0;
		$bracket_depth = 0;
		$quote         = '';
		$escaped       = false;
		$chars         = str_split( (string) $selector_text );

		foreach ( $chars as $char ) {
			if ( $escaped ) {
				$buffer .= $char;
				$escaped = false;
				continue;
			}

			if ( $char === '\\' ) {
				$buffer .= $char;
				$escaped = true;
				continue;
			}

			if ( $quote ) {
				$buffer .= $char;

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$buffer .= $char;
				$quote   = $char;
				continue;
			}

			if ( $char === '(' ) {
				++$paren_depth;
				$buffer .= $char;
				continue;
			}

			if ( $char === ')' ) {
				$paren_depth = max( 0, $paren_depth - 1 );
				$buffer     .= $char;
				continue;
			}

			if ( $char === '[' ) {
				++$bracket_depth;
				$buffer .= $char;
				continue;
			}

			if ( $char === ']' ) {
				$bracket_depth = max( 0, $bracket_depth - 1 );
				$buffer       .= $char;
				continue;
			}

			if ( $char === ',' && $paren_depth === 0 && $bracket_depth === 0 ) {
				if ( trim( $buffer ) !== '' ) {
					$selectors[] = trim( $buffer );
				}

				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		if ( trim( $buffer ) !== '' ) {
			$selectors[] = trim( $buffer );
		}

		return $selectors;
	}

	/**
	 * Parse CSS declarations string into property-value pairs.
	 *
	 * @since 2.4
	 *
	 * @param string $declaration_text CSS declarations (content between { }).
	 * @return array Associative array with property-value pairs.
	 */
	public static function parse_declarations( $declaration_text ) {
		$declarations = [];

		if ( ! $declaration_text ) {
			return $declarations;
		}

		$parts          = [];
		$buffer         = '';
		$quote          = '';
		$escaped        = false;
		$in_comment     = false;
		$function_depth = 0;
		$length         = strlen( $declaration_text );

		for ( $index = 0; $index < $length; ++$index ) {
			$char = $declaration_text[ $index ];
			$next = $index + 1 < $length ? $declaration_text[ $index + 1 ] : '';

			if ( $in_comment ) {
				$buffer .= $char;

				if ( $char === '*' && $next === '/' ) {
					$buffer    .= $next;
					$in_comment = false;
					++$index;
				}

				continue;
			}

			if ( $quote ) {
				$buffer .= $char;

				if ( $escaped ) {
					$escaped = false;
					continue;
				}

				if ( $char === '\\' ) {
					$escaped = true;
					continue;
				}

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( $char === '/' && $next === '*' ) {
				$buffer    .= '/*';
				$in_comment = true;
				++$index;
				continue;
			}

			if ( $char === '\\' && $next !== '' ) {
				$buffer .= $char . $next;
				++$index;
				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( $char === '(' || $char === '[' ) {
				++$function_depth;
			} elseif ( ( $char === ')' || $char === ']' ) && $function_depth > 0 ) {
				--$function_depth;
			}

			if ( $char === ';' && $function_depth === 0 ) {
				$parts[] = $buffer;
				$buffer  = '';
				continue;
			}

			$buffer .= $char;
		}

		$parts[] = $buffer;

		foreach ( $parts as $part ) {
			$trimmed = trim( $part );

			if ( ! $trimmed ) {
				continue;
			}

			// Find first colon (property-value separator)
			$colon_index = strpos( $trimmed, ':' );

			if ( $colon_index === false ) {
				continue;
			}

			$property = trim( substr( $trimmed, 0, $colon_index ) );
			$value    = trim( substr( $trimmed, $colon_index + 1 ) );

			if ( $property && $value !== '' ) {
				$declarations[ $property ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * Parse inline style attribute string.
	 *
	 * @since 2.4
	 *
	 * @param string $style_string Inline style string.
	 * @return array Associative array with property-value pairs.
	 */
	public static function parse_inline_style( $style_string ) {
		return self::parse_declarations( $style_string );
	}

	/**
	 * Extract class-specific CSS rules from parsed rules.
	 *
	 * @since 2.4
	 *
	 * @param array $rules       Parsed CSS rules.
	 * @param array $class_names Class names to extract rules for.
	 * @return array Object mapping class names to their CSS.
	 */
	public static function extract_class_rules( $rules, $class_names ) {
		$class_rules = [];

		foreach ( $class_names as $class_name ) {
			$class_rules[ $class_name ] = [
				'base'    => [],
				'pseudo'  => [],
				'targets' => [],
			];
		}

		$process_rule = function ( $rule, $at_rules = [] ) use ( &$process_rule, &$class_rules, $class_names ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'media' && ! empty( $rule['nestedRules'] ) && is_array( $rule['nestedRules'] ) ) {
					$media_at_rules = array_merge(
						$at_rules,
						[
							[
								'name'   => $rule['atRuleName'],
								'params' => isset( $rule['atRuleParams'] ) ? $rule['atRuleParams'] : '',
							],
						]
					);

					foreach ( $rule['nestedRules'] as $nested_rule ) {
						$process_rule( $nested_rule, $media_at_rules );
					}
				}

				return;
			}

			$rule_at_rules = ! empty( $rule['atRules'] ) && is_array( $rule['atRules'] ) ? $rule['atRules'] : $at_rules;

			foreach ( $rule['selectors'] as $selector ) {
				$normalized_selector = self::normalize_selector( $selector );

				foreach ( $class_names as $class_name ) {
					$class_selector = ".{$class_name}";

					if ( ! self::is_valid_root_match( $normalized_selector, $class_selector ) ) {
						continue;
					}

					$stripped = self::strip_trailing_pseudo( $normalized_selector );
					$base     = $stripped['base'];
					$pseudo   = $stripped['pseudo'];

					$class_rules[ $class_name ]['targets'][] = [
						'selector'     => self::display_selector( $selector ),
						'selectorBase' => $base,
						'pseudo'       => $pseudo,
						'breakpoint'   => isset( $rule['breakpoint'] ) ? $rule['breakpoint'] : '',
						'mediaQuery'   => isset( $rule['mediaQuery'] ) ? $rule['mediaQuery'] : '',
						'declarations' => $rule['declarations'],
						'atRules'      => $rule_at_rules,
					];

					// Legacy root-only buckets are base-breakpoint only.
					if ( $base === $class_selector && ! $pseudo && empty( $rule_at_rules ) ) {
						$class_rules[ $class_name ]['base'] = array_merge(
							$class_rules[ $class_name ]['base'],
							$rule['declarations']
						);
						continue;
					}

					if ( $base === $class_selector && $pseudo && empty( $rule_at_rules ) ) {
						if ( ! isset( $class_rules[ $class_name ]['pseudo'][ $pseudo ] ) ) {
							$class_rules[ $class_name ]['pseudo'][ $pseudo ] = [];
						}

						$class_rules[ $class_name ]['pseudo'][ $pseudo ] = array_merge(
							$class_rules[ $class_name ]['pseudo'][ $pseudo ],
							$rule['declarations']
						);
					}
				}
			}
		};

		foreach ( $rules as $rule ) {
			$process_rule( $rule );
		}

		return $class_rules;
	}

	/**
	 * Extract ID-specific CSS rules from parsed rules.
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed CSS rules.
	 * @param array $ids   IDs to extract rules for.
	 * @return array Object mapping IDs to their CSS.
	 */
	public static function extract_id_rules( $rules, $ids ) {
		$id_rules = [];

		foreach ( $ids as $id ) {
			$id_rules[ $id ] = [
				'base'    => [],
				'pseudo'  => [],
				'targets' => [],
			];
		}

		$process_rule = function ( $rule, $at_rules = [] ) use ( &$process_rule, &$id_rules, $ids ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'media' && ! empty( $rule['nestedRules'] ) && is_array( $rule['nestedRules'] ) ) {
					$media_at_rules = array_merge(
						$at_rules,
						[
							[
								'name'   => $rule['atRuleName'],
								'params' => isset( $rule['atRuleParams'] ) ? $rule['atRuleParams'] : '',
							],
						]
					);

					foreach ( $rule['nestedRules'] as $nested_rule ) {
						$process_rule( $nested_rule, $media_at_rules );
					}
				}

				return;
			}

			$rule_at_rules = ! empty( $rule['atRules'] ) && is_array( $rule['atRules'] ) ? $rule['atRules'] : $at_rules;

			foreach ( $rule['selectors'] as $selector ) {
				$normalized_selector = self::normalize_selector( $selector );

				foreach ( $ids as $id ) {
					$id_selector = "#{$id}";

					if ( ! self::is_valid_root_match( $normalized_selector, $id_selector ) ) {
						continue;
					}

					$stripped = self::strip_trailing_pseudo( $normalized_selector );
					$base     = $stripped['base'];
					$pseudo   = $stripped['pseudo'];

					$id_rules[ $id ]['targets'][] = [
						'selector'     => self::display_selector( $selector ),
						'selectorBase' => $base,
						'pseudo'       => $pseudo,
						'breakpoint'   => isset( $rule['breakpoint'] ) ? $rule['breakpoint'] : '',
						'mediaQuery'   => isset( $rule['mediaQuery'] ) ? $rule['mediaQuery'] : '',
						'declarations' => $rule['declarations'],
						'atRules'      => $rule_at_rules,
					];

					if ( $base === $id_selector && ! $pseudo && empty( $rule_at_rules ) ) {
						$id_rules[ $id ]['base'] = array_merge(
							$id_rules[ $id ]['base'],
							$rule['declarations']
						);
						continue;
					}

					if ( $base === $id_selector && $pseudo && empty( $rule_at_rules ) ) {
						if ( ! isset( $id_rules[ $id ]['pseudo'][ $pseudo ] ) ) {
							$id_rules[ $id ]['pseudo'][ $pseudo ] = [];
						}

						$id_rules[ $id ]['pseudo'][ $pseudo ] = array_merge(
							$id_rules[ $id ]['pseudo'][ $pseudo ],
							$rule['declarations']
						);
					}
				}
			}
		};

		foreach ( $rules as $rule ) {
			$process_rule( $rule );
		}

		return $id_rules;
	}

	/**
	 * Extract global CSS rules (rules not targeting specific classes/IDs).
	 *
	 * @since 2.4
	 *
	 * @param array $rules       Parsed CSS rules.
	 * @param array $class_names Class names to exclude.
	 * @param array $ids         IDs to exclude.
	 * @return array Array of global CSS rules.
	 */
	public static function extract_global_rules( $rules, $class_names = [], $ids = [] ) {
		$global_rules = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'media' && ! empty( $rule['nestedRules'] ) && is_array( $rule['nestedRules'] ) ) {
					$nested_rules = self::extract_global_rules( $rule['nestedRules'], $class_names, $ids );

					if ( empty( $nested_rules ) ) {
						continue;
					}

					$rule['nestedRules'] = array_values( $nested_rules );
					$global_rules[]      = $rule;
					continue;
				}

				$global_rules[] = $rule;
				continue;
			}

			$selectors = [];

			foreach ( $rule['selectors'] as $selector ) {
				if ( ! self::selector_targets_known( $selector, $class_names, $ids ) ) {
					$selectors[] = $selector;
				}
			}

			if ( empty( $selectors ) ) {
				continue;
			}

			$rule['selectors'] = $selectors;
			$global_rules[]    = $rule;
		}

		return $global_rules;
	}

	/**
	 * Whether a selector is owned by a known class or ID root.
	 *
	 * @since 2.4
	 *
	 * @param string $selector    CSS selector.
	 * @param array  $class_names Known class names.
	 * @param array  $ids         Known IDs.
	 * @return bool
	 */
	private static function selector_targets_known( $selector, $class_names = [], $ids = [] ) {
		$normalized_selector = self::normalize_selector( $selector );

		foreach ( $class_names as $class_name ) {
			if ( self::is_valid_root_match( $normalized_selector, ".{$class_name}" ) ) {
				return true;
			}
		}

		foreach ( $ids as $id ) {
			if ( self::is_valid_root_match( $normalized_selector, "#{$id}" ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert CSS rules back to string.
	 *
	 * @since 2.4
	 *
	 * @param array $rules          Parsed CSS rules.
	 * @param bool  $inside_at_rule Whether an ancestor already supplies the media wrapper.
	 * @return string CSS string.
	 */
	public static function rules_to_string( $rules, $inside_at_rule = false ) {
		$parts = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'media' && isset( $rule['nestedRules'] ) && is_array( $rule['nestedRules'] ) ) {
					$nested_css = trim( self::rules_to_string( $rule['nestedRules'], true ) );

					if ( ! $nested_css ) {
						continue;
					}

					$parts[] = "@{$rule['atRuleName']} {$rule['atRuleParams']} {\n" . self::indent_css( $nested_css ) . "\n}";
					continue;
				}

				$parts[] = $rule['raw'];
				continue;
			}

			if ( ! $inside_at_rule && ! empty( $rule['mediaQuery'] ) ) {
				$parts[] = self::format_media_rule( $rule );
				continue;
			}

			$selectors_list = $inside_at_rule
				? array_map( [ self::class, 'strengthen_conditional_native_selector' ], $rule['selectors'] )
				: $rule['selectors'];
			$selectors      = implode( ', ', $selectors_list );
			$declarations   = [];

			foreach ( $rule['declarations'] as $prop => $value ) {
				$declarations[] = "  {$prop}: {$value};";
			}

			$parts[] = "{$selectors} {\n" . implode( "\n", $declarations ) . "\n}";
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Format a single media-scoped rule while preserving source order.
	 *
	 * @since 2.4
	 *
	 * @param array $rule Parsed CSS rule.
	 * @return string CSS string.
	 */
	private static function format_media_rule( $rule ) {
		$selectors_list = array_map(
			[ self::class, 'strengthen_conditional_native_selector' ],
			$rule['selectors']
		);
		$selectors      = implode( ', ', $selectors_list );
		$declarations   = [];

		foreach ( $rule['declarations'] as $prop => $value ) {
			$declarations[] = "    {$prop}: {$value};";
		}

		return "@media {$rule['mediaQuery']} {\n  {$selectors} {\n" . implode( "\n", $declarations ) . "\n  }\n}";
	}

	/**
	 * Match conditional heading tag rules to Bricks' native selector specificity.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return string Strengthened selector when the tag maps to a native heading.
	 */
	private static function strengthen_conditional_native_selector( $selector ) {
		$trimmed = trim( (string) $selector );

		if ( preg_match( '/^h[1-6]$/i', $trimmed ) ) {
			return $trimmed . '.brxe-heading';
		}

		return $selector;
	}

	/**
	 * Convert declarations object to CSS string.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations Property-value pairs.
	 * @return string CSS declarations string.
	 */
	public static function declarations_to_string( $declarations ) {
		$parts = [];

		foreach ( $declarations as $prop => $value ) {
			$parts[] = "{$prop}: {$value}";
		}

		return implode( '; ', $parts );
	}

	/**
	 * Extract unique class names from CSS selectors.
	 *
	 * @since 2.4
	 *
	 * @param array $rules Parsed CSS rules.
	 * @return array Array of unique class names.
	 */
	public static function extract_class_names_from_css( $rules ) {
		$class_names = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) ) {
				if ( isset( $rule['atRuleName'] ) && $rule['atRuleName'] === 'media' && ! empty( $rule['nestedRules'] ) && is_array( $rule['nestedRules'] ) ) {
					foreach ( self::extract_class_names_from_css( $rule['nestedRules'] ) as $class_name ) {
						$class_names[ $class_name ] = true;
					}
				}

				continue;
			}

			foreach ( $rule['selectors'] as $selector ) {
				if ( preg_match_all( '/\.([a-zA-Z_-][a-zA-Z0-9_-]*)/', $selector, $matches ) ) {
					foreach ( $matches[1] as $class_name ) {
						$class_names[ $class_name ] = true;
					}
				}
			}
		}

		return array_keys( $class_names );
	}

	/**
	 * Parse shorthand margin/padding value into individual values.
	 *
	 * @since 2.4
	 *
	 * @param string $value Shorthand value (e.g., '10px 20px').
	 * @return array Object with top, right, bottom, left values.
	 */
	public static function parse_spacing_shorthand( $value ) {
		$parts = preg_split( '/\s+/', trim( $value ) );

		switch ( count( $parts ) ) {
			case 1:
				return [
					'top'    => $parts[0],
					'right'  => $parts[0],
					'bottom' => $parts[0],
					'left'   => $parts[0]
				];
			case 2:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[0],
					'left'   => $parts[1]
				];
			case 3:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[2],
					'left'   => $parts[1]
				];
			case 4:
				return [
					'top'    => $parts[0],
					'right'  => $parts[1],
					'bottom' => $parts[2],
					'left'   => $parts[3]
				];
			default:
				return [
					'top'    => $value,
					'right'  => $value,
					'bottom' => $value,
					'left'   => $value
				];
		}
	}

	/**
	 * Parse border shorthand value.
	 *
	 * @since 2.4
	 *
	 * @param string $value Border shorthand (e.g., '1px solid #000').
	 * @return array Object with width, style, color.
	 */
	public static function parse_border_shorthand( $value ) {
		$result = [
			'width' => null,
			'style' => null,
			'color' => null,
		];

		$parts = preg_split( '/\s+/', trim( $value ) );

		$border_styles = [ 'none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ];

		foreach ( $parts as $part ) {
			if ( preg_match( '/^\d/', $part ) ) {
				$result['width'] = $part;
			} elseif ( in_array( $part, $border_styles, true ) ) {
				$result['style'] = $part;
			} else {
				$result['color'] = $part;
			}
		}

		return $result;
	}

	/**
	 * Normalize a CSS selector.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return string Normalized selector.
	 */
	private static function normalize_selector( $selector = '' ) {
		$s = trim( (string) $selector );
		$s = preg_replace( '/\s+/', ' ', $s );
		$s = preg_replace( '/\s*([>+~])\s*/', '$1', $s );
		return $s;
	}

	/**
	 * Normalize selector whitespace without changing authored combinator spacing.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return string Display selector.
	 */
	private static function display_selector( $selector = '' ) {
		return preg_replace( '/\s+/', ' ', trim( (string) $selector ) );
	}

	/**
	 * Strip trailing pseudo-elements/classes from a selector.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return array { base: string, pseudo: string }.
	 */
	private static function strip_trailing_pseudo( $selector = '' ) {
		$base   = $selector;
		$pseudo = '';
		$regex  = '/(::?[a-z-]+(?:\([^)]*\))?)$/i';

		while ( true ) {
			if ( preg_match( $regex, $base, $match ) ) {
				$pseudo = $match[1] . $pseudo;
				$base   = substr( $base, 0, -strlen( $match[1] ) );
			} else {
				break;
			}
		}

		return [
			'base'   => self::normalize_selector( $base ),
			'pseudo' => $pseudo,
		];
	}

	/**
	 * Check if a selector is a valid root match for a given root selector.
	 *
	 * @since 2.4
	 *
	 * @param string $selector      Normalized selector.
	 * @param string $root_selector Root selector to match against.
	 * @return bool
	 */
	private static function is_valid_root_match( $selector, $root_selector ) {
		if ( strpos( $selector, $root_selector ) !== 0 ) {
			return false;
		}

		$next_char = isset( $selector[ strlen( $root_selector ) ] ) ? $selector[ strlen( $root_selector ) ] : '';

		return ! $next_char || ! preg_match( '/[a-zA-Z0-9_-]/', $next_char );
	}
}

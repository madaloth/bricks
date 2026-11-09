<?php
/**
 * CSS Value Parsers
 *
 * Utilities for parsing CSS values into structured formats.
 *
 * PHP port of src/vue/utils/cssToControls/parsers.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS value parsers for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Css_Value_Parsers {
	/**
	 * Trim only whitespace characters defined by CSS Syntax.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS value.
	 * @return string Trimmed CSS value.
	 */
	private static function trim_css_whitespace( $value ) {
		return preg_replace( '/^[ \t\r\n\f]+|[ \t\r\n\f]+$/', '', $value );
	}

	/**
	 * Split a CSS value on top-level whitespace while preserving functions.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS value.
	 * @return array Top-level value parts.
	 */
	private static function split_value_parts( $value ) {
		$parts            = [];
		$current          = '';
		$depth            = 0;
		$quote            = '';
		$comment          = false;
		$preserve_comment = false;
		$length           = strlen( $value );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $value[ $index ];
			$next      = $index + 1 < $length ? $value[ $index + 1 ] : '';

			if ( $comment ) {
				if ( $preserve_comment ) {
					$current .= $character;
				}

				if ( $character === '*' && $next === '/' ) {
					if ( $preserve_comment ) {
						$current .= '/';
					}

					++$index;
					$comment          = false;
					$preserve_comment = false;
				}

				continue;
			}

			if ( $character === '\\' ) {
				$current .= $character;

				if ( $next === '' ) {
					continue;
				}

				if ( ctype_xdigit( $next ) ) {
					$digits = 0;

					while ( $index + 1 < $length && $digits < 6 && ctype_xdigit( $value[ $index + 1 ] ) ) {
						$current .= $value[ ++$index ];
						++$digits;
					}

					if ( $index + 1 < $length && strpos( " \t\r\n\f", $value[ $index + 1 ] ) !== false ) {
						$current .= $value[ ++$index ];

						if ( $current[ strlen( $current ) - 1 ] === "\r" && $index + 1 < $length && $value[ $index + 1 ] === "\n" ) {
							$current .= $value[ ++$index ];
						}
					}
				} else {
					$current .= $next;
					++$index;
				}

				continue;
			}

			if ( $quote !== '' ) {
				if ( $character === $quote ) {
					$quote = '';
				}

				$current .= $character;
				continue;
			}

			if ( $character === '"' || $character === "'" ) {
				$quote    = $character;
				$current .= $character;
				continue;
			}

			if ( $character === '/' && $next === '*' ) {
				if ( $depth === 0 ) {
					$current = self::trim_css_whitespace( $current );

					if ( $current !== '' ) {
						$parts[] = $current;
					}

					$current = '';
				} else {
					$current         .= '/*';
					$preserve_comment = true;
				}

				++$index;
				$comment = true;
				continue;
			}

			if ( $character === '(' ) {
				++$depth;
			} elseif ( $character === ')' ) {
				$depth = max( 0, $depth - 1 );
			}

			if ( strpos( " \t\r\n\f", $character ) !== false && $depth === 0 ) {
				$current = self::trim_css_whitespace( $current );

				if ( $current !== '' ) {
					$parts[] = $current;
				}

				$current = '';
				continue;
			}

			$current .= $character;
		}

		$current = self::trim_css_whitespace( $current );

		if ( $current !== '' ) {
			$parts[] = $current;
		}

		return $parts;
	}

	/**
	 * Parse a CSS value with unit.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS value like "16px", "1.5em", "100%".
	 * @return array|null { value: mixed, unit: string } or null if invalid.
	 */
	public static function parse_unit( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		$trimmed = self::trim_css_whitespace( $value );

		// Handle unitless values (like line-height: 1.5)
		if ( preg_match( '/^-?[\d.]+$/', $trimmed ) ) {
			return [
				'value' => (float) $trimmed,
				'unit'  => ''
			];
		}

		// Match number + unit
		if ( preg_match( '/^(-?[\d.]+)(px|em|rem|%|vw|vh|vmin|vmax|ch|ex|pt|pc|cm|mm|in)$/i', $trimmed, $match ) ) {
			return [
				'value' => (float) $match[1],
				'unit'  => strtolower( $match[2] )
			];
		}

		// Handle CSS function values.
		if ( preg_match( '/^(calc|var|clamp|min|max)\(/', $trimmed ) ) {
			return [
				'value' => $trimmed,
				'unit'  => 'function'
			];
		}

		return null;
	}

	/**
	 * Parse a color value into Bricks format.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS color value.
	 * @return array|null { hex: string } or { raw: string } or null.
	 */
	public static function parse_color( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		$trimmed = strtolower( trim( $value ) );

		// Handle CSS variables
		if ( strpos( $trimmed, 'var(' ) === 0 ) {
			return [ 'raw' => $trimmed ];
		}

		// Handle hex colors
		if ( strpos( $trimmed, '#' ) === 0 ) {
			return [ 'hex' => $trimmed ];
		}

		// Handle rgb/rgba
		if ( strpos( $trimmed, 'rgb' ) === 0 ) {
			return [ 'raw' => $trimmed ];
		}

		// Handle hsl/hsla
		if ( strpos( $trimmed, 'hsl' ) === 0 ) {
			return [ 'raw' => $trimmed ];
		}

		// Handle named colors
		$named_colors = [
			'transparent',
			'inherit',
			'currentcolor',
			'black',
			'white',
			'red',
			'green',
			'blue',
			'yellow',
			'orange',
			'purple',
			'pink',
			'gray',
			'grey',
		];

		if ( in_array( $trimmed, $named_colors, true ) ) {
			return [ 'raw' => $trimmed ];
		}

		return null;
	}

	/**
	 * Parse shorthand margin/padding values.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS shorthand value like "10px 20px".
	 * @return array|null { top, right, bottom, left }.
	 */
	public static function parse_spacing_shorthand( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		$parts  = self::split_value_parts( self::trim_css_whitespace( $value ) );
		$parsed = array_map(
			function ( $p ) {
				$result = self::parse_unit( $p );
				return $result ? $result : [
					'value' => $p,
					'unit'  => ''
				];
			},
			$parts
		);

		if ( empty( $parsed ) ) {
			return null;
		}

		$format_value = function ( $v ) {
			if ( $v['unit'] === 'function' ) {
				return $v['value'];
			}
			if ( $v['unit'] === '' ) {
				return (string) $v['value'];
			}
			return $v['value'] . $v['unit'];
		};

		$count = count( $parts );

		switch ( $count ) {
			case 1:
				$val = $format_value( $parsed[0] );
				return [
					'top'    => $val,
					'right'  => $val,
					'bottom' => $val,
					'left'   => $val
				];

			case 2:
				$tb = $format_value( $parsed[0] );
				$lr = $format_value( $parsed[1] );
				return [
					'top'    => $tb,
					'right'  => $lr,
					'bottom' => $tb,
					'left'   => $lr
				];

			case 3:
				return [
					'top'    => $format_value( $parsed[0] ),
					'right'  => $format_value( $parsed[1] ),
					'bottom' => $format_value( $parsed[2] ),
					'left'   => $format_value( $parsed[1] ),
				];

			case 4:
				return [
					'top'    => $format_value( $parsed[0] ),
					'right'  => $format_value( $parsed[1] ),
					'bottom' => $format_value( $parsed[2] ),
					'left'   => $format_value( $parsed[3] ),
				];

			default:
				return null;
		}
	}

	/**
	 * Parse box-shadow value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS box-shadow value.
	 * @return array|null Bricks shadow format.
	 */
	public static function parse_box_shadow( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		if ( $value === 'none' ) {
			return null;
		}

		$trimmed = trim( $value );

		// Check for inset
		$inset       = strpos( $trimmed, 'inset' ) !== false;
		$clean_value = trim( str_replace( 'inset', '', $trimmed ) );

		// Try to extract parts
		$parts = preg_split( '/\s+/', $clean_value );

		if ( count( $parts ) < 2 ) {
			return null;
		}

		$values = [];
		$color  = null;

		foreach ( $parts as $part ) {
			$parsed = self::parse_unit( $part );

			if ( $parsed ) {
				$values[] = $parsed;
			} else {
				$parsed_color = self::parse_color( $part );

				if ( $parsed_color ) {
					$color = $part;
				}
			}
		}

		if ( count( $values ) < 2 ) {
			return null;
		}

		$format_value = function ( $v ) {
			if ( ! $v ) {
				return '0px';
			}

			if ( $v['unit'] === 'function' ) {
				return $v['value'];
			}

			if ( $v['unit'] === '' ) {
				return $v['value'] . 'px';
			}

			return $v['value'] . $v['unit'];
		};

		return [
			'inset'   => $inset,
			'offsetX' => $format_value( $values[0] ),
			'offsetY' => $format_value( $values[1] ),
			'blur'    => $format_value( isset( $values[2] ) ? $values[2] : null ),
			'spread'  => $format_value( isset( $values[3] ) ? $values[3] : null ),
			'color'   => $color ? $color : 'rgba(0,0,0,0.2)',
		];
	}

	/**
	 * Parse border value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS border value like "1px solid #000".
	 * @return array|null { width, style, color }.
	 */
	public static function parse_border( $value ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		if ( $value === 'none' ) {
			return null;
		}

		$parts = preg_split( '/\s+/', trim( $value ) );

		if ( count( $parts ) < 1 ) {
			return null;
		}

		$result = [];

		$border_styles = [ 'none', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ];

		$remaining_parts = [];

		// Resolve an explicit numeric width before ambiguous function values. This
		// lets "1px solid var(--color)" retain the variable as its color while
		// preserving the legacy width interpretation of "var(--border-width)".
		foreach ( $parts as $part ) {
			$parsed_unit = self::parse_unit( $part );

			if ( ! isset( $result['width'] ) && $parsed_unit && $parsed_unit['unit'] !== 'function' ) {
				$result['width'] = $parsed_unit['value'] . ( $parsed_unit['unit'] ? $parsed_unit['unit'] : 'px' );
				continue;
			}

			$remaining_parts[] = $part;
		}

		$parts           = $remaining_parts;
		$remaining_parts = [];

		foreach ( $parts as $part ) {
			if ( in_array( strtolower( $part ), $border_styles, true ) ) {
				$result['style'] = strtolower( $part );
				continue;
			}

			$remaining_parts[] = $part;
		}

		foreach ( $remaining_parts as $part ) {
			$parsed_unit = self::parse_unit( $part );

			if ( ! isset( $result['width'] ) && $parsed_unit ) {
				$result['width'] = $parsed_unit['unit'] === 'function'
					? $parsed_unit['value']
					: $parsed_unit['value'] . ( $parsed_unit['unit'] ? $parsed_unit['unit'] : 'px' );
				continue;
			}

			$parsed_color = self::parse_color( $part );

			if ( $parsed_color ) {
				$result['color'] = $parsed_color;
			}
		}

		return ! empty( $result ) ? $result : null;
	}

	/**
	 * Parse border-radius value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS border-radius value.
	 * @return array|null { top, right, bottom, left }.
	 */
	public static function parse_border_radius( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		return self::parse_spacing_shorthand( $value );
	}

	/**
	 * Parse font-family value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS font-family value.
	 * @return string|null First font family name.
	 */
	public static function parse_font_family( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		// CSS variables cannot be represented by the font-family control. Ignore
		// quoted family names here because they are literals, not CSS functions.
		$unquoted_value = preg_replace( '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/', '', $value );

		if ( preg_match( '/(^|[^a-z0-9_-])var\(/i', $unquoted_value ) ) {
			return null;
		}

		return trim( $value );
	}

	/**
	 * Parse font-weight value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS font-weight value.
	 * @return int|null
	 */
	public static function parse_font_weight( $value ) {
		if ( ! $value ) {
			return null;
		}

		$trimmed = strtolower( trim( (string) $value ) );

		$weight_map = [
			'thin'       => 100,
			'hairline'   => 100,
			'extralight' => 200,
			'ultralight' => 200,
			'light'      => 300,
			'normal'     => 400,
			'regular'    => 400,
			'medium'     => 500,
			'semibold'   => 600,
			'demibold'   => 600,
			'bold'       => 700,
			'extrabold'  => 800,
			'ultrabold'  => 800,
			'black'      => 900,
			'heavy'      => 900,
		];

		if ( isset( $weight_map[ $trimmed ] ) ) {
			return $weight_map[ $trimmed ];
		}

		$numeric = (int) $trimmed;

		if ( $numeric >= 100 && $numeric <= 900 ) {
			return $numeric;
		}

		return null;
	}

	/**
	 * Parse text-align value.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS text-align value.
	 * @return string|null
	 */
	public static function parse_text_align( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		$valid   = [ 'left', 'center', 'right', 'justify', 'start', 'end' ];
		$trimmed = strtolower( trim( $value ) );

		return in_array( $trimmed, $valid, true ) ? $trimmed : null;
	}

	/**
	 * Parse background-image value (gradient or url).
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS background-image value.
	 * @return array|null
	 */
	public static function parse_background_image( $value ) {
		if ( ! $value || ! is_string( $value ) ) {
			return null;
		}

		if ( $value === 'none' ) {
			return null;
		}

		$trimmed = trim( $value );

		// Data URLs can contain semicolons, quotes, and parentheses. Keep them in
		// custom CSS instead of coercing them into Bricks' media-library image shape.
		if ( preg_match( '/^url\(\s*([\'\"]?)data:/i', $trimmed ) ) {
			return null;
		}

		// Gradient
		if ( strpos( $trimmed, 'gradient(' ) !== false ) {
			return [ 'gradient' => $trimmed ];
		}

		// URL
		if ( preg_match( '/url\(["\']?([^"\')\s]+)["\']?\)/', $trimmed, $match ) ) {
			return [ 'url' => $match[1] ];
		}

		return null;
	}
	/**
	 * Check a literal length supported by native effect controls.
	 *
	 * @since 2.4.3
	 * @param string $value CSS value.
	 * @return bool
	 */
	private static function is_native_effect_length( $value ) {
		return (bool) preg_match( '/^(?:[+-]?(?:\d*\.\d+|\d+\.?\d*)(?:px|em|rem|vw|vh|vmin|vmax|ch|ex|pt|pc|cm|mm|in)|[+-]?0+(?:\.0+)?)$/i', $value );
	}

	/**
	 * Parse a literal effect color without guessing unresolved functions.
	 *
	 * @since 2.4.3
	 * @param string $value CSS color.
	 * @return array|null
	 */
	private static function parse_native_effect_color( $value ) {
		if ( preg_match( '/^#[\da-f]{3}(?:[\da-f]|[\da-f]{3}|[\da-f]{5})?$/i', $value ) ) {
			return self::parse_color( $value );
		}
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\((.*)\)$/is', $value, $functional ) ) {
			$number  = '[+-]?(?:\d*\.\d+|\d+\.?\d*)';
			$rgb     = strpos( strtolower( $functional[1] ), 'rgb' ) === 0;
			$channel = "{$number}%?";
			$first   = $rgb ? $channel : "{$number}(?:deg)?";
			$rest    = $rgb ? $channel : "{$number}%";
			$pattern = '/^\s*(?:' . $first . '\s*,\s*' . $rest . '\s*,\s*' . $rest . '(?:\s*,\s*' . $channel . ')?|' . $first . '\s+' . $rest . '\s+' . $rest . '(?:\s*\/\s*' . $channel . ')?)\s*$/i';
			if ( ! preg_match( $pattern, $functional[2] ) ) {
				return null;
			}
			if ( $rgb && strpos( $functional[2], ',' ) !== false ) {
				$channels    = array_slice( explode( ',', $functional[2] ), 0, 3 );
				$percentages = count(
					array_filter(
						$channels,
						static function ( $part ) {
							return strpos( $part, '%' ) !== false;
						}
					)
				);
				if ( $percentages !== 0 && $percentages !== 3 ) {
					return null;
				}
			}
			return self::parse_color( $value );
		}
		if ( preg_match( '/^[a-z]+$/i', $value ) && strtolower( $value ) !== 'inherit' ) {
			return self::parse_color( $value );
		}
		return null;
	}

	/**
	 * Parse one shadow without dropping layers or guessing unresolved values.
	 *
	 * @since 2.4.3
	 * @param string $value CSS shadow.
	 * @return array|null Native shadow setting or null for preserved CSS.
	 */
	public static function parse_native_box_shadow( $value ) {
		if ( ! is_string( $value ) || preg_match( '/[!\\\\;]|\/\*/', $value ) ) {
			return null;
		}
		$layers = preg_split( '/,(?![^()]*\))/', trim( $value ) );
		if ( count( $layers ) !== 1 ) {
			return null;
		}
		$lengths             = [];
		$color               = null;
		$inset               = false;
		$color_after_lengths = false;
		foreach ( self::split_value_parts( $layers[0] ) as $part ) {
			if ( strtolower( $part ) === 'inset' ) {
				if ( $inset ) {
					return null;
				}
				$inset = true;
			} elseif ( self::is_native_effect_length( $part ) ) {
				if ( $color_after_lengths ) {
					return null;
				}
				$lengths[] = $part;
			} else {
				if ( $color || ! self::parse_native_effect_color( $part ) ) {
					return null;
				}
				$color               = self::parse_native_effect_color( $part );
				$color_after_lengths = count( $lengths ) > 0;
			}
		}
		if ( count( $lengths ) < 2 || count( $lengths ) > 4 || (float) ( $lengths[2] ?? '0' ) < 0 ) {
			return null;
		}
		$setting = [
			'values' => array_combine( [ 'offsetX', 'offsetY', 'blur', 'spread' ], array_pad( $lengths, 4, '0' ) ),
			// CSS defaults to currentColor; the Builder's visual default is different.
			'color'  => $color ? $color : [ 'raw' => 'currentcolor' ],
		];
		if ( $inset ) {
			$setting['inset'] = true;
		}
		return $setting;
	}

	/**
	 * Parse linear gradients expressible by the native angle/color-stop controls.
	 *
	 * @since 2.4.3
	 * @param string $value CSS background image.
	 * @return array|null Native gradient setting or null for preserved CSS.
	 */
	public static function parse_native_linear_gradient( $value ) {
		if ( ! is_string( $value ) || preg_match( '/[!\\\\;]|\/\*/', $value ) || ! preg_match( '/^linear-gradient\((.*)\)$/is', trim( $value ), $match ) ) {
			return null;
		}
		$stops      = array_map( 'trim', preg_split( '/,(?![^()]*\))/', $match[1] ) );
		$angle      = '180';
		$directions = [
			'to top'    => '0',
			'to right'  => '90',
			'to bottom' => '180',
			'to left'   => '270'
		];
		if ( preg_match( '/^([+-]?(?:\d*\.\d+|\d+\.?\d*))deg$/i', $stops[0], $angle_match ) ) {
			$angle = $angle_match[1];
			array_shift( $stops );
		} elseif ( isset( $directions[ strtolower( $stops[0] ) ] ) ) {
			$angle = $directions[ strtolower( array_shift( $stops ) ) ];
		}
		if ( count( $stops ) < 2 ) {
			return null;
		}
		$colors = [];
		foreach ( $stops as $stop ) {
			$parts = self::split_value_parts( $stop );
			$color = self::parse_native_effect_color( $parts[0] ?? '' );
			if ( ! $color || count( $parts ) > 2 ) {
				return null;
			}
			$entry = [ 'color' => $color ];
			if ( count( $parts ) === 2 ) {
				if ( ! self::is_native_effect_length( $parts[1] ) && ! preg_match( '/^[+-]?(?:\d*\.\d+|\d+\.?\d*)%$/', $parts[1] ) ) {
					return null;
				}
				$entry['stop'] = preg_match( '/^[+-]?0+(?:\.0+)?$/', $parts[1] ) ? '0%' : $parts[1];
			}
			$colors[] = $entry;
		}
		return [
			'gradientType' => 'linear',
			'applyTo'      => 'background',
			'angle'        => $angle,
			'colors'       => $colors
		];
	}

}

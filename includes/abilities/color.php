<?php
/**
 * Color utilities
 *
 * Small, focused port of the subset of TinyColor.js behavior used by the
 * Bricks builder for shade/tint/transparent-shade generation. Ported
 * from src/vue/components/main/style-manager/color-manager/PopupColorShades.vue:230
 * so a round-trip through this ability matches what the Generate button
 * produces inside the builder.
 *
 * Scope intentionally narrow - we support hex, rgb(a), and hsl(a) input,
 * plus linear RGB mixing. Named colors and perceptual color spaces are
 * out of scope; callers pass validated CSS color strings.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Color {
	/**
	 * Parse a CSS color string into an internal rgba struct plus a
	 * `format` label so the serializer can round-trip the same notation.
	 *
	 * @since 2.4
	 *
	 * @param string $input CSS color string.
	 * @return array|null { r, g, b, a, format } - null if unparseable.
	 */
	public static function parse( $input ) {
		$raw = strtolower( trim( (string) $input ) );

		if ( $raw === '' ) {
			return null;
		}

		// Hex: #rgb, #rrggbb, #rrggbbaa.
		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $raw, $m ) ) {
			$hex = $m[1];

			if ( strlen( $hex ) === 3 ) {
				$r = hexdec( str_repeat( $hex[0], 2 ) );
				$g = hexdec( str_repeat( $hex[1], 2 ) );
				$b = hexdec( str_repeat( $hex[2], 2 ) );
				$a = 1.0;
			} elseif ( strlen( $hex ) === 6 ) {
				$r = hexdec( substr( $hex, 0, 2 ) );
				$g = hexdec( substr( $hex, 2, 2 ) );
				$b = hexdec( substr( $hex, 4, 2 ) );
				$a = 1.0;
			} elseif ( strlen( $hex ) === 8 ) {
				$r = hexdec( substr( $hex, 0, 2 ) );
				$g = hexdec( substr( $hex, 2, 2 ) );
				$b = hexdec( substr( $hex, 4, 2 ) );
				$a = hexdec( substr( $hex, 6, 2 ) ) / 255;
			} else {
				return null;
			}

			return [
				'r'      => $r,
				'g'      => $g,
				'b'      => $b,
				'a'      => $a,
				'format' => 'hex'
			];
		}

		// rgb() / rgba() - comma or space-separated.
		if ( preg_match( '/^rgba?\((.+)\)$/', $raw, $m ) ) {
			$parts = self::split_components( $m[1] );

			if ( count( $parts ) < 3 || count( $parts ) > 4 ) {
				return null;
			}

			$r = self::to_byte( $parts[0] );
			$g = self::to_byte( $parts[1] );
			$b = self::to_byte( $parts[2] );
			$a = isset( $parts[3] ) ? self::to_alpha( $parts[3] ) : 1.0;

			if ( $r === null || $g === null || $b === null || $a === null ) {
				return null;
			}

			return [
				'r'      => $r,
				'g'      => $g,
				'b'      => $b,
				'a'      => $a,
				'format' => 'rgb'
			];
		}

		// hsl() / hsla() - comma or space-separated.
		if ( preg_match( '/^hsla?\((.+)\)$/', $raw, $m ) ) {
			$parts = self::split_components( $m[1] );

			if ( count( $parts ) < 3 || count( $parts ) > 4 ) {
				return null;
			}

			$h = self::to_hue( $parts[0] );
			$s = self::to_percentage( $parts[1] );
			$l = self::to_percentage( $parts[2] );
			$a = isset( $parts[3] ) ? self::to_alpha( $parts[3] ) : 1.0;

			if ( $h === null || $s === null || $l === null || $a === null ) {
				return null;
			}

			$rgb = self::hsl_to_rgb( $h, $s, $l );

			return [
				'r'      => $rgb[0],
				'g'      => $rgb[1],
				'b'      => $rgb[2],
				'a'      => $a,
				'format' => 'hsl'
			];
		}

		return null;
	}

	/**
	 * Check whether a value uses a known color syntax but fails to parse.
	 *
	 * Palette colors can still use broader CSS values such as `var(...)` or
	 * newer color functions. This guard only catches malformed values for the
	 * syntaxes this utility intentionally owns: hex, rgb(a), and hsl(a).
	 *
	 * @since 2.4
	 *
	 * @param string $input CSS color string.
	 * @return bool
	 */
	public static function has_malformed_known_syntax( $input ) {
		$raw = strtolower( trim( (string) $input ) );

		if ( $raw === '' ) {
			return false;
		}

		if ( ! preg_match( '/^(?:#|rgba?\(|hsla?\()/', $raw ) ) {
			return false;
		}

		return self::parse( $raw ) === null;
	}

	/**
	 * Linear RGB mix of color1 and color2 at the given percentage of
	 * color2. Matches TinyColor.mix() semantics - percentage of 100
	 * returns color2, percentage of 0 returns color1.
	 *
	 * @since 2.4
	 *
	 * @param array $c1         Parsed color 1.
	 * @param array $c2         Parsed color 2.
	 * @param float $percentage 0-100.
	 * @return array Parsed mixed color inheriting c2's format.
	 */
	public static function mix( $c1, $c2, $percentage ) {
		$p = max( 0.0, min( 100.0, (float) $percentage ) ) / 100.0;

		return [
			'r'      => (int) round( $c1['r'] + ( $c2['r'] - $c1['r'] ) * $p ),
			'g'      => (int) round( $c1['g'] + ( $c2['g'] - $c1['g'] ) * $p ),
			'b'      => (int) round( $c1['b'] + ( $c2['b'] - $c1['b'] ) * $p ),
			'a'      => $c1['a'] + ( $c2['a'] - $c1['a'] ) * $p,
			'format' => $c2['format'] ?? 'hex',
		];
	}

	/**
	 * Apply an alpha value to a color keeping its RGB components intact.
	 * Used by the transparent-shade path where the builder sets HSL alpha
	 * directly rather than mixing.
	 *
	 * @since 2.4
	 *
	 * @param array $c     Parsed color.
	 * @param float $alpha 0-1.
	 * @return array
	 */
	public static function with_alpha( $c, $alpha ) {
		$out      = $c;
		$out['a'] = max( 0.0, min( 1.0, (float) $alpha ) );
		return $out;
	}

	/**
	 * Serialize a parsed color back to a string in the given format.
	 *
	 * @since 2.4
	 *
	 * @param array  $c      Parsed color.
	 * @param string $format hex | rgb | hsl - force a specific output format. Default: match input.
	 * @return string
	 */
	public static function to_string( $c, $format = '' ) {
		$format = $format !== '' ? $format : ( $c['format'] ?? 'hex' );

		if ( $format === 'rgb' ) {
			if ( abs( $c['a'] - 1.0 ) < 1e-6 ) {
				return sprintf( 'rgb(%d, %d, %d)', $c['r'], $c['g'], $c['b'] );
			}
			return sprintf( 'rgba(%d, %d, %d, %s)', $c['r'], $c['g'], $c['b'], self::fmt_alpha( $c['a'] ) );
		}

		if ( $format === 'hsl' ) {
			$hsl = self::rgb_to_hsl( $c['r'], $c['g'], $c['b'] );

			if ( abs( $c['a'] - 1.0 ) < 1e-6 ) {
				return sprintf( 'hsl(%d %d%% %d%%)', round( $hsl[0] ), round( $hsl[1] ), round( $hsl[2] ) );
			}

			return sprintf( 'hsl(%d %d%% %d%% / %s)', round( $hsl[0] ), round( $hsl[1] ), round( $hsl[2] ), self::fmt_alpha( $c['a'] ) );
		}

		// hex
		if ( abs( $c['a'] - 1.0 ) > 1e-6 ) {
			// TinyColor toHexString collapses alpha; toHex8String preserves it. Match toHexString behavior per the Vue code.
			return sprintf( '#%02x%02x%02x', $c['r'], $c['g'], $c['b'] );
		}

		return sprintf( '#%02x%02x%02x', $c['r'], $c['g'], $c['b'] );
	}

	/**
	 * Derive the dark-mode pair for a light color using the same HSL
	 * lightness inversion as PopupStylesColors.vue::toggleDarkMode().
	 *
	 * @since 2.4
	 *
	 * @param string $input CSS color string.
	 * @return string Empty string if the input cannot be parsed.
	 */
	public static function derive_dark_mode_color( $input ) {
		$base = self::parse( $input );

		if ( ! $base ) {
			return '';
		}

		$hsl = self::rgb_to_hsl( $base['r'], $base['g'], $base['b'] );

		$hsl[2] = 100 - $hsl[2];

		if ( $hsl[2] > 40 && $hsl[2] < 60 ) {
			$hsl[2] = $hsl[2] < 50 ? 20 : 80;
		}

		$rgb = self::hsl_to_rgb( $hsl[0], $hsl[1], $hsl[2] );

		return self::to_string(
			[
				'r'      => $rgb[0],
				'g'      => $rgb[1],
				'b'      => $rgb[2],
				'a'      => $base['a'],
				'format' => $base['format'] ?? 'hex',
			]
		);
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Split a component list like "255, 128, 64" or "255 128 64" or "255 128 64 / 0.5".
	 *
	 * @since 2.4
	 *
	 * @param string $input Body inside the parens.
	 * @return array
	 */
	private static function split_components( $input ) {
		$input = str_replace( '/', ',', $input );
		$parts = preg_split( '/[\s,]+/', trim( $input ) );
		return array_values( array_filter( $parts, static fn( $p ) => $p !== '' ) );
	}

	/**
	 * Convert an rgb channel token to a 0-255 byte. Accepts "255" or "100%".
	 *
	 * @since 2.4
	 *
	 * @param string $s Token.
	 * @return int|null
	 */
	private static function to_byte( $s ) {
		$s = trim( (string) $s );

		if ( substr( $s, -1 ) === '%' ) {
			$value = self::to_number( substr( $s, 0, -1 ) );

			if ( $value === null ) {
				return null;
			}

			return max( 0, min( 255, (int) round( ( $value / 100 ) * 255 ) ) );
		}

		$value = self::to_number( $s );

		if ( $value === null ) {
			return null;
		}

		return max( 0, min( 255, (int) round( $value ) ) );
	}

	/**
	 * Normalize a percentage-ish token to a float 0-1 (for s/l) or 0-100.
	 *
	 * @since 2.4
	 *
	 * @param string $s Token.
	 * @return float|null
	 */
	private static function to_percentage( $s ) {
		$s = trim( (string) $s );

		if ( substr( $s, -1 ) === '%' ) {
			return self::to_number( substr( $s, 0, -1 ) );
		}

		return self::to_number( $s );
	}

	/**
	 * Normalize an alpha token to 0-1.
	 *
	 * @since 2.4
	 *
	 * @param string $s Token.
	 * @return float|null
	 */
	private static function to_alpha( $s ) {
		$s = trim( (string) $s );

		if ( substr( $s, -1 ) === '%' ) {
			$value = self::to_number( substr( $s, 0, -1 ) );

			if ( $value === null ) {
				return null;
			}

			return max( 0.0, min( 1.0, $value / 100 ) );
		}

		$value = self::to_number( $s );

		if ( $value === null ) {
			return null;
		}

		return max( 0.0, min( 1.0, $value ) );
	}

	/**
	 * Parse an HSL hue token. Bare numbers and explicit degrees are supported.
	 *
	 * @since 2.4
	 *
	 * @param string $s Token.
	 * @return float|null
	 */
	private static function to_hue( $s ) {
		$s = trim( (string) $s );

		if ( substr( $s, -3 ) === 'deg' ) {
			$s = substr( $s, 0, -3 );
		}

		return self::to_number( $s );
	}

	/**
	 * Parse a numeric CSS component token without PHP's loose coercion.
	 *
	 * @since 2.4
	 *
	 * @param string $s Token.
	 * @return float|null
	 */
	private static function to_number( $s ) {
		$s = trim( (string) $s );

		if ( ! preg_match( '/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $s ) ) {
			return null;
		}

		return (float) $s;
	}

	/**
	 * HSL to RGB conversion. h in 0-360, s/l in 0-100.
	 *
	 * @since 2.4
	 *
	 * @param float $h Hue (0-360).
	 * @param float $s Saturation (0-100).
	 * @param float $l Lightness (0-100).
	 * @return array [ r, g, b ] 0-255.
	 */
	private static function hsl_to_rgb( $h, $s, $l ) {
		$h = fmod( fmod( $h, 360 ) + 360, 360 ) / 360;
		$s = max( 0, min( 100, $s ) ) / 100;
		$l = max( 0, min( 100, $l ) ) / 100;

		if ( $s === 0.0 ) {
			$v = (int) round( $l * 255 );
			return [ $v, $v, $v ];
		}

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		$r = self::hue_to_rgb( $p, $q, $h + 1 / 3 );
		$g = self::hue_to_rgb( $p, $q, $h );
		$b = self::hue_to_rgb( $p, $q, $h - 1 / 3 );

		return [ (int) round( $r * 255 ), (int) round( $g * 255 ), (int) round( $b * 255 ) ];
	}

	/**
	 * RGB to HSL conversion. r/g/b in 0-255.
	 *
	 * @since 2.4
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return array [ h (0-360), s (0-100), l (0-100) ].
	 */
	private static function rgb_to_hsl( $r, $g, $b ) {
		$r /= 255;
		$g /= 255;
		$b /= 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;

		if ( $max === $min ) {
			return [ 0, 0, $l * 100 ];
		}

		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return [ ( $h / 6 ) * 360, $s * 100, $l * 100 ];
	}

	/**
	 * Helper for hsl_to_rgb.
	 *
	 * @since 2.4
	 *
	 * @param float $p Constant.
	 * @param float $q Constant.
	 * @param float $t Hue fraction.
	 * @return float
	 */
	private static function hue_to_rgb( $p, $q, $t ) {
		if ( $t < 0 ) {
			++$t; }
		if ( $t > 1 ) {
			--$t; }
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t; }
		if ( $t < 1 / 2 ) {
			return $q; }
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6; }
		return $p;
	}

	/**
	 * Format an alpha value trimmed for CSS output ("0.5" not "0.500").
	 *
	 * @since 2.4
	 *
	 * @param float $a Alpha 0-1.
	 * @return string
	 */
	private static function fmt_alpha( $a ) {
		$s = rtrim( rtrim( sprintf( '%.3F', $a ), '0' ), '.' );
		return $s === '' ? '0' : $s;
	}
}

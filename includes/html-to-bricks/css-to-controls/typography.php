<?php
/**
 * Typography CSS to Bricks Converter
 *
 * Converts typography-related CSS properties to Bricks _typography settings.
 *
 * PHP port of src/vue/utils/cssToControls/typography.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typography CSS to Bricks converter.
 *
 * @since 2.4
 */
class Html_To_Bricks_Typography {
	/**
	 * Typography CSS properties.
	 *
	 * @var array
	 */
	const TYPOGRAPHY_PROPS = [
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'line-height',
		'letter-spacing',
		'text-align',
		'text-transform',
		'text-decoration',
		'color',
	];

	/**
	 * Convert typography CSS declarations to Bricks _typography setting.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations { property: value }.
	 * @return array|null Bricks _typography setting or null if no typography props.
	 */
	public static function convert_typography( $declarations ) {
		if ( ! $declarations || ! is_array( $declarations ) ) {
			return null;
		}

		$typography = [];

		foreach ( $declarations as $prop => $value ) {
			$low_prop = strtolower( $prop );

			if ( ! in_array( $low_prop, self::TYPOGRAPHY_PROPS, true ) ) {
				continue;
			}

			switch ( $low_prop ) {
				case 'font-family':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_font_family( $value );

					if ( $parsed ) {
						$typography['font-family'] = $parsed;
					}
					break;

				case 'font-size':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_unit( $value );

					if ( $parsed ) {
						$typography['font-size'] = $parsed['unit'] === 'function'
							? $parsed['value']
							: $parsed['value'] . ( $parsed['unit'] ? $parsed['unit'] : 'px' );
					}
					break;

				case 'font-weight':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_font_weight( $value );

					if ( $parsed !== null ) {
						$typography['font-weight'] = $parsed;
					}
					break;

				case 'font-style':
					if ( in_array( strtolower( $value ), [ 'normal', 'italic', 'oblique' ], true ) ) {
						$typography['font-style'] = strtolower( $value );
					}
					break;

				case 'line-height':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_unit( $value );

					if ( $parsed ) {
						if ( $parsed['unit'] === 'function' || $parsed['unit'] === '' ) {
							$typography['line-height'] = $parsed['value'];
						} else {
							$typography['line-height'] = $parsed['value'] . $parsed['unit'];
						}
					}
					break;

				case 'letter-spacing':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_unit( $value );

					if ( $parsed ) {
						$typography['letter-spacing'] = $parsed['unit'] === 'function'
							? $parsed['value']
							: $parsed['value'] . ( $parsed['unit'] ? $parsed['unit'] : 'px' );
					}
					break;

				case 'text-align':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_text_align( $value );

					if ( $parsed ) {
						$typography['text-align'] = $parsed;
					}
					break;

				case 'text-transform':
					if ( in_array( strtolower( $value ), [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
						$typography['text-transform'] = strtolower( $value );
					}
					break;

				case 'text-decoration':
					$typography['text-decoration'] = $value;
					break;

				case 'color':
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_color( $value );

					if ( $parsed ) {
						$typography['color'] = $parsed;
					}
					break;
			}
		}

		return ! empty( $typography ) ? $typography : null;
	}

	/**
	 * Check if a CSS property is typography-related.
	 *
	 * @since 2.4
	 *
	 * @param string $property CSS property name.
	 * @return bool
	 */
	public static function is_typography_property( $property ) {
		return in_array( strtolower( $property ), self::TYPOGRAPHY_PROPS, true );
	}
}

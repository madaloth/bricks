<?php
/**
 * Spacing CSS to Bricks Converter
 *
 * Converts margin/padding CSS properties to Bricks spacing settings.
 *
 * PHP port of src/vue/utils/cssToControls/spacing.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spacing CSS to Bricks converter.
 *
 * @since 2.4
 */
class Html_To_Bricks_Spacing {
	/**
	 * Preserve CSS values that are not expressible as a simple numeric unit.
	 *
	 * @since 2.4
	 *
	 * @param string $value CSS spacing value.
	 * @return string|null Formatted spacing value.
	 */
	private static function format_spacing_value( $value ) {
		$parsed = Html_To_Bricks_Css_Value_Parsers::parse_unit( $value );

		if ( $parsed ) {
			return $parsed['unit'] === 'function'
				? $parsed['value']
				: $parsed['value'] . ( $parsed['unit'] ? $parsed['unit'] : 'px' );
		}

		$raw = preg_replace( '/^[ \t\r\n\f]+|[ \t\r\n\f]+$/', '', (string) $value );
		return $raw !== '' ? $raw : null;
	}

	/**
	 * Spacing CSS properties.
	 *
	 * @var array
	 */
	const SPACING_PROPS = [
		'margin',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
	];

	/**
	 * Convert margin CSS declarations to Bricks _margin setting.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations.
	 * @return array|null Bricks _margin setting.
	 */
	public static function convert_margin( $declarations ) {
		if ( ! $declarations ) {
			return null;
		}

		$margin = [];

		// Handle shorthand first
		if ( ! empty( $declarations['margin'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_spacing_shorthand( $declarations['margin'] );

			if ( $parsed ) {
				$margin = array_merge( $margin, $parsed );
			}
		}

		// Individual properties override shorthand
		$sides = [ 'top', 'right', 'bottom', 'left' ];

		foreach ( $sides as $side ) {
			$prop = "margin-{$side}";

			if ( ! empty( $declarations[ $prop ] ) ) {
				$value = self::format_spacing_value( $declarations[ $prop ] );

				if ( $value !== null ) {
					$margin[ $side ] = $value;
				}
			}
		}

		return ! empty( $margin ) ? $margin : null;
	}

	/**
	 * Convert padding CSS declarations to Bricks _padding setting.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations.
	 * @return array|null Bricks _padding setting.
	 */
	public static function convert_padding( $declarations ) {
		if ( ! $declarations ) {
			return null;
		}

		$padding = [];

		// Handle shorthand first
		if ( ! empty( $declarations['padding'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_spacing_shorthand( $declarations['padding'] );

			if ( $parsed ) {
				$padding = array_merge( $padding, $parsed );
			}
		}

		// Individual properties override shorthand
		$sides = [ 'top', 'right', 'bottom', 'left' ];

		foreach ( $sides as $side ) {
			$prop = "padding-{$side}";

			if ( ! empty( $declarations[ $prop ] ) ) {
				$value = self::format_spacing_value( $declarations[ $prop ] );

				if ( $value !== null ) {
					$padding[ $side ] = $value;
				}
			}
		}

		return ! empty( $padding ) ? $padding : null;
	}

	/**
	 * Check if a CSS property is spacing-related.
	 *
	 * @since 2.4
	 *
	 * @param string $property CSS property name.
	 * @return bool
	 */
	public static function is_spacing_property( $property ) {
		return in_array( strtolower( $property ), self::SPACING_PROPS, true );
	}
}

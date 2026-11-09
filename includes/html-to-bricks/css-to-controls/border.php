<?php
/**
 * Border CSS to Bricks Converter
 *
 * Converts border and box-shadow CSS properties to Bricks settings.
 *
 * PHP port of src/vue/utils/cssToControls/border.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Border CSS to Bricks converter.
 *
 * @since 2.4
 */
class Html_To_Bricks_Border {
	/**
	 * Border CSS properties.
	 *
	 * @var array
	 */
	const BORDER_PROPS = [
		'border',
		'border-width',
		'border-style',
		'border-color',
		'border-radius',
		'border-top',
		'border-right',
		'border-bottom',
		'border-left',
		'border-top-width',
		'border-right-width',
		'border-bottom-width',
		'border-left-width',
		'border-top-style',
		'border-right-style',
		'border-bottom-style',
		'border-left-style',
		'border-top-color',
		'border-right-color',
		'border-bottom-color',
		'border-left-color',
		'border-top-left-radius',
		'border-top-right-radius',
		'border-bottom-right-radius',
		'border-bottom-left-radius',
	];

	/**
	 * Border directions.
	 *
	 * @var array
	 */
	const BORDER_DIRECTIONS = [ 'top', 'right', 'bottom', 'left' ];

	/**
	 * Directional border shorthand properties.
	 *
	 * @var array
	 */
	const BORDER_SIDE_PROPS = [
		'border-top'    => 'top',
		'border-right'  => 'right',
		'border-bottom' => 'bottom',
		'border-left'   => 'left',
	];

	/**
	 * Directional border width properties.
	 *
	 * @var array
	 */
	const BORDER_WIDTH_PROPS = [
		'border-top-width'    => 'top',
		'border-right-width'  => 'right',
		'border-bottom-width' => 'bottom',
		'border-left-width'   => 'left',
	];

	/**
	 * Radius corner property map to Bricks border control directions.
	 *
	 * @var array
	 */
	const RADIUS_CORNERS = [
		'border-top-left-radius'     => 'top',
		'border-top-right-radius'    => 'right',
		'border-bottom-right-radius' => 'bottom',
		'border-bottom-left-radius'  => 'left',
	];

	/**
	 * Format a parsed unit value for Bricks settings.
	 *
	 * @since 2.4
	 *
	 * @param array|null $parsed Parsed unit value.
	 * @return string|null Formatted value.
	 */
	private static function format_unit_value( $parsed ) {
		if ( ! $parsed ) {
			return null;
		}

		return $parsed['unit'] === 'function'
			? $parsed['value']
			: $parsed['value'] . ( $parsed['unit'] ? $parsed['unit'] : 'px' );
	}

	/**
	 * Set one or more directional values on a border setting key.
	 *
	 * @since 2.4
	 *
	 * @param array  $border     Border setting.
	 * @param string $key        Border setting key.
	 * @param array  $directions Directions to set.
	 * @param mixed  $value      Value to set.
	 */
	private static function set_directional_value( &$border, $key, $directions, $value ) {
		if ( $value === null || $value === '' ) {
			return;
		}

		if ( empty( $border[ $key ] ) || ! is_array( $border[ $key ] ) ) {
			$border[ $key ] = [];
		}

		foreach ( $directions as $direction ) {
			$border[ $key ][ $direction ] = $value;
		}
	}

	/**
	 * Apply a CSS unit value to one or more border width directions.
	 *
	 * @since 2.4
	 *
	 * @param array  $border     Border setting.
	 * @param array  $directions Directions to set.
	 * @param string $value      CSS value.
	 * @return bool Whether the value was applied.
	 */
	private static function apply_width( &$border, $directions, $value ) {
		$parsed    = Html_To_Bricks_Css_Value_Parsers::parse_unit( $value );
		$formatted = self::format_unit_value( $parsed );

		if ( $formatted === null ) {
			return false;
		}

		self::set_directional_value( $border, 'width', $directions, $formatted );
		return true;
	}

	/**
	 * Convert border CSS declarations to Bricks _border setting.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations.
	 * @return array|null Bricks _border setting.
	 */
	public static function convert_border( $declarations ) {
		if ( ! $declarations ) {
			return null;
		}

		$border = [];

		// Handle shorthand 'border' first
		if ( ! empty( $declarations['border'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_border( $declarations['border'] );

			if ( $parsed ) {
				if ( ! empty( $parsed['width'] ) ) {
					self::set_directional_value( $border, 'width', self::BORDER_DIRECTIONS, $parsed['width'] );
				}

				if ( ! empty( $parsed['style'] ) ) {
					$border['style'] = $parsed['style'];
				}

				if ( ! empty( $parsed['color'] ) ) {
					$border['color'] = $parsed['color'];
				}
			}
		}

		// Handle directional border shorthands.
		foreach ( self::BORDER_SIDE_PROPS as $prop => $direction ) {
			if (
				! array_key_exists( $prop, $declarations ) ||
				$declarations[ $prop ] === '' ||
				$declarations[ $prop ] === null
			) {
				continue;
			}

			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_border( $declarations[ $prop ] );

			if ( ! $parsed || empty( $parsed['width'] ) || ! empty( $parsed['style'] ) || ! empty( $parsed['color'] ) ) {
				continue;
			}

			self::set_directional_value( $border, 'width', [ $direction ], $parsed['width'] );
		}

		// Handle individual border properties
		if ( ! empty( $declarations['border-width'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_spacing_shorthand( $declarations['border-width'] );

			if ( $parsed ) {
				$border['width'] = $parsed;
			}
		}

		foreach ( self::BORDER_WIDTH_PROPS as $prop => $direction ) {
			if (
				array_key_exists( $prop, $declarations ) &&
				$declarations[ $prop ] !== '' &&
				$declarations[ $prop ] !== null
			) {
				self::apply_width( $border, [ $direction ], $declarations[ $prop ] );
			}
		}

		if ( ! empty( $declarations['border-style'] ) ) {
			$border['style'] = $declarations['border-style'];
		}

		if ( ! empty( $declarations['border-color'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_color( $declarations['border-color'] );

			if ( $parsed ) {
				$border['color'] = $parsed;
			}
		}

		// Handle border-radius
		if ( ! empty( $declarations['border-radius'] ) ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_border_radius( $declarations['border-radius'] );

			if ( $parsed ) {
				$border['radius'] = $parsed;
			}
		}

		// Handle individual border-radius properties
		foreach ( self::RADIUS_CORNERS as $prop => $direction ) {
			if ( ! empty( $declarations[ $prop ] ) ) {
				$parsed = Html_To_Bricks_Css_Value_Parsers::parse_unit( $declarations[ $prop ] );

				if ( $parsed ) {
					self::set_directional_value( $border, 'radius', [ $direction ], self::format_unit_value( $parsed ) );
				}
			}
		}

		return ! empty( $border ) ? $border : null;
	}

	/**
	 * Convert box-shadow CSS to Bricks _boxShadow setting.
	 *
	 * @since 2.4
	 *
	 * @param array $declarations CSS declarations.
	 * @return array|null Bricks _boxShadow setting.
	 */
	public static function convert_box_shadow( $declarations ) {
		if ( ! $declarations || empty( $declarations['box-shadow'] ) ) {
			return null;
		}

		return Html_To_Bricks_Css_Value_Parsers::parse_box_shadow( $declarations['box-shadow'] );
	}

	/**
	 * Check if a CSS property is border-related.
	 *
	 * @since 2.4
	 *
	 * @param string $property CSS property name.
	 * @return bool
	 */
	public static function is_border_property( $property ) {
		return in_array( strtolower( $property ), self::BORDER_PROPS, true );
	}

	/**
	 * Check if a CSS property is box-shadow.
	 *
	 * @since 2.4
	 *
	 * @param string $property CSS property name.
	 * @return bool
	 */
	public static function is_box_shadow_property( $property ) {
		return strtolower( $property ) === 'box-shadow';
	}
}

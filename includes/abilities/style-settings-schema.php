<?php
/**
 * Runtime style settings schema.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates persisted style keys against the controls active on this site.
 *
 * @since 2.4
 */
class Style_Settings_Schema {
	/**
	 * Validate one element setting key, including its responsive suffixes.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @param string $key          Persisted setting key.
	 * @return true|\WP_Error
	 */
	public static function validate_element_key( $element_name, $key ) {
		$schema   = self::element_schema( $element_name );
		$base_key = self::base_key( $key );

		if ( ! isset( $schema[ $base_key ] ) || ! is_array( $schema[ $base_key ] ) ) {
			return self::unsupported_key_error( "settings.{$key}", $key, array_keys( $schema ), "the '{$element_name}' element" );
		}

		if ( strpos( (string) $key, '|' ) !== false || strpos( (string) $key, ':' ) === false ) {
			return true;
		}

		if ( ! self::control_generates_css( $base_key, $schema[ $base_key ] ) ) {
			return Error::invalid_param(
				"settings.{$key}",
				"a CSS-generating setting for the '{$element_name}' element",
				$key
			);
		}

		return self::validate_css_key_suffix( $key, "settings.{$key}" );
	}

	/**
	 * Validate global-class settings against all registered CSS controls.
	 *
	 * A global class is not tied to one element type, so the live union of
	 * registered element controls is the only complete runtime authority.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings Settings submitted by the caller.
	 * @param string $path     Parameter path used in structured errors.
	 * @param array  $existing Existing settings whose opaque keys must remain editable.
	 * @return true|\WP_Error
	 */
	public static function validate_global_class_settings( $settings, $path, $existing = [] ) {
		$controls = self::global_class_controls();

		foreach ( array_keys( $settings ) as $key ) {
			if ( array_key_exists( $key, $existing ) ) {
				continue;
			}

			$base_key = self::base_key( $key );

			if ( ! isset( $controls[ $base_key ] ) ) {
				return self::unsupported_key_error( "{$path}.{$key}", $key, array_keys( $controls ), 'a global class' );
			}

			$validation = self::validate_css_key_suffix( $key, "{$path}.{$key}" );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		return true;
	}

	/**
	 * Validate a grouped theme-style settings patch against runtime controls.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings Settings submitted by the caller.
	 * @param string $path     Parameter path used in structured errors.
	 * @param array  $existing Existing settings whose opaque keys must remain editable.
	 * @return true|\WP_Error
	 */
	public static function validate_theme_style_settings( $settings, $path = 'settings', $existing = [] ) {
		$groups = \Bricks\Theme_Styles::get_controls();
		$groups = is_array( $groups ) ? $groups : [];

		foreach ( $settings as $group_key => $group_settings ) {
			if ( array_key_exists( $group_key, $existing ) && ! isset( $groups[ $group_key ] ) ) {
				continue;
			}

			if ( ! isset( $groups[ $group_key ] ) || ! is_array( $groups[ $group_key ] ) ) {
				return self::unsupported_key_error( "{$path}.{$group_key}", $group_key, array_keys( $groups ), 'a theme style section' );
			}

			if ( ! is_array( $group_settings ) ) {
				return Error::invalid_param( "{$path}.{$group_key}", 'an object of theme style controls', $group_settings );
			}

			$existing_group = is_array( $existing[ $group_key ] ?? null ) ? $existing[ $group_key ] : [];

			foreach ( array_keys( $group_settings ) as $control_key ) {
				if ( array_key_exists( $control_key, $existing_group ) ) {
					continue;
				}

				$base_key = self::base_key( $control_key );

				if ( ! isset( $groups[ $group_key ][ $base_key ] ) ) {
					return self::unsupported_key_error(
						"{$path}.{$group_key}.{$control_key}",
						$control_key,
						array_keys( $groups[ $group_key ] ),
						"the '{$group_key}' theme style section"
					);
				}

				$validation = self::validate_css_key_suffix( $control_key, "{$path}.{$group_key}.{$control_key}" );

				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		}

		return true;
	}

	/**
	 * Validate breakpoint, pseudo-class, and variant suffixes.
	 *
	 * @since 2.4
	 *
	 * @param string $key  Persisted setting key.
	 * @param string $path Parameter path used in structured errors.
	 * @return true|\WP_Error
	 */
	private static function validate_css_key_suffix( $key, $path ) {
		$key    = (string) $key;
		$suffix = substr( $key, strlen( self::base_key( $key ) ) );

		if ( $suffix === '' ) {
			return true;
		}

		if ( preg_match( '/:variant-[A-Za-z0-9_-]+$/', $suffix, $variant_match ) ) {
			$suffix = substr( $suffix, 0, -strlen( $variant_match[0] ) );
		}

		foreach ( self::breakpoint_keys() as $breakpoint_key ) {
			$breakpoint = ':' . $breakpoint_key;

			if ( strpos( $suffix, $breakpoint ) === 0 && ( strlen( $suffix ) === strlen( $breakpoint ) || $suffix[ strlen( $breakpoint ) ] === ':' ) ) {
				$suffix = substr( $suffix, strlen( $breakpoint ) );
				break;
			}
		}

		if ( $suffix === '' || in_array( $suffix, self::pseudo_classes(), true ) ) {
			return true;
		}

		$suggestion = self::correct_double_colon_key( $key );
		$expected   = 'a valid runtime style key using an active breakpoint, pseudo-class, and optional component variant';

		if ( $suggestion !== null ) {
			$expected .= "; use '{$suggestion}'";
		}

		return Error::invalid_param( $path, $expected, $key );
	}

	/**
	 * Return the union of CSS controls registered by all active elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function global_class_controls() {
		static $controls = null;

		if ( $controls !== null ) {
			return $controls;
		}

		$controls = [];
		$elements = property_exists( '\\Bricks\\Elements', 'elements' ) && is_array( \Bricks\Elements::$elements )
			? array_keys( \Bricks\Elements::$elements )
			: [];

		foreach ( $elements as $element_name ) {
			foreach ( self::element_schema( $element_name ) as $key => $definition ) {
				if ( self::control_generates_css( $key, $definition ) ) {
					$controls[ $key ] = true;
				}
			}
		}

		return $controls;
	}

	/**
	 * Return one element's runtime schema once per request.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return array
	 */
	private static function element_schema( $element_name ) {
		static $schemas = [];

		if ( ! array_key_exists( $element_name, $schemas ) ) {
			$schemas[ $element_name ] = Element_Settings_Schema::get( $element_name );
		}

		return $schemas[ $element_name ];
	}

	/**
	 * Whether a runtime control can produce persisted CSS.
	 *
	 * @since 2.4
	 *
	 * @param string $key        Control key.
	 * @param array  $definition Runtime control definition.
	 * @return bool
	 */
	private static function control_generates_css( $key, $definition ) {
		return $key === '_cssCustom' || ! empty( $definition['css'] );
	}

	/**
	 * Return active breakpoint keys from the same runtime source as CSS output.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function breakpoint_keys() {
		$breakpoints = \Bricks\Breakpoints::get_breakpoints();
		$keys        = [];

		foreach ( (array) $breakpoints as $breakpoint ) {
			if ( ! empty( $breakpoint['key'] ) ) {
				$keys[] = (string) $breakpoint['key'];
			}
		}

		return $keys;
	}

	/**
	 * Return active pseudo-classes from the CSS generation runtime.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function pseudo_classes() {
		$pseudo_classes = \Bricks\Database::$global_data['pseudoClasses'] ?? [];

		return is_array( $pseudo_classes ) ? array_values( array_map( 'strval', $pseudo_classes ) ) : [];
	}

	/**
	 * Suggest the single-colon form previously documented incorrectly.
	 *
	 * @since 2.4
	 *
	 * @param string $key Invalid setting key.
	 * @return string|null
	 */
	private static function correct_double_colon_key( $key ) {
		if ( strpos( $key, '::' ) === false ) {
			return null;
		}

		$candidate = str_replace( '::', ':', $key );
		$result    = self::validate_css_key_suffix( $candidate, $candidate );

		return $result === true ? $candidate : null;
	}

	/**
	 * Return an unsupported-key error with an unambiguous runtime suggestion.
	 *
	 * @since 2.4
	 *
	 * @param string $path       Parameter path used in structured errors.
	 * @param string $key        Unsupported key.
	 * @param array  $candidates Runtime-supported keys.
	 * @param string $target     Target resource description.
	 * @return \WP_Error
	 */
	private static function unsupported_key_error( $path, $key, $candidates, $target ) {
		$base_key   = self::base_key( $key );
		$suggestion = self::closest_key( $base_key, $candidates );
		$expected   = "a registered setting for {$target}";

		if ( $suggestion !== null ) {
			$expected .= "; did you mean '{$suggestion}'?";
		}

		return Error::invalid_param( $path, $expected, $key );
	}

	/**
	 * Find a clearly closest runtime key without maintaining aliases.
	 *
	 * @since 2.4
	 *
	 * @param string $key        Unsupported key.
	 * @param array  $candidates Runtime-supported keys.
	 * @return string|null
	 */
	private static function closest_key( $key, $candidates ) {
		$scores = [];

		foreach ( $candidates as $candidate ) {
			similar_text( strtolower( $key ), strtolower( (string) $candidate ), $score );
			$scores[ (string) $candidate ] = $score;
		}

		arsort( $scores, SORT_NUMERIC );
		$keys = array_keys( $scores );

		if ( empty( $keys ) || $scores[ $keys[0] ] < 65 ) {
			return null;
		}

		if ( isset( $keys[1] ) && $scores[ $keys[0] ] - $scores[ $keys[1] ] < 8 ) {
			return null;
		}

		return $keys[0];
	}

	/**
	 * Strip dynamic suffixes from a persisted setting key.
	 *
	 * @since 2.4
	 *
	 * @param string $key Persisted setting key.
	 * @return string
	 */
	private static function base_key( $key ) {
		$parts = preg_split( '/[:|]/', (string) $key, 2 );

		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : (string) $key;
	}
}

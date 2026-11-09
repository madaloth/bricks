<?php
/**
 * Element style normalizer
 *
 * Converts user-facing custom CSS shapes into persisted Bricks settings.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize MCP-authored element CSS before persisting element trees.
 *
 * @since 2.4
 */
class Element_Style_Normalizer {
	/**
	 * Normalize all element style settings in a flat element array.
	 *
	 * @since 2.4
	 *
	 * @param array $elements                Flat Bricks element rows.
	 * @param bool  $component_definition   Whether these rows are saved inside a component definition.
	 * @param array $existing_elements      Trusted persisted baseline for preserving unchanged workspace CSS.
	 * @return array
	 */
	public static function normalize_elements( array $elements, bool $component_definition = false, array $existing_elements = [] ): array {
		self::load_html_to_bricks_mapper();
		$existing_by_id = [];
		foreach ( $existing_elements as $existing_element ) {
			if ( is_array( $existing_element ) && is_string( $existing_element['id'] ?? null ) ) {
				$existing_by_id[ $existing_element['id'] ] = $existing_element;
			}
		}

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$existing_element = is_string( $element['id'] ?? null ) ? ( $existing_by_id[ $element['id'] ] ?? [] ) : [];
			$element          = self::normalize_element( $element, $component_definition, $existing_element );
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Normalize settings for a single element.
	 *
	 * @since 2.4
	 *
	 * @param array $element              Element row.
	 * @param bool  $component_definition Whether the row is saved inside a component definition.
	 * @param array $existing_element     Trusted persisted element, when available.
	 * @return array
	 */
	private static function normalize_element( array $element, bool $component_definition, array $existing_element = [] ): array {
		$settings = $element['settings'] ?? [];

		if ( ! is_array( $settings ) ) {
			return $element;
		}

		$element_id   = (string) ( $element['id'] ?? '' );
		$element_name = (string) ( $element['name'] ?? '' );

		if ( $element_id === '' || $element_name === '' ) {
			return $element;
		}

		$root_selector      = self::element_root_selector( $element, $settings, $component_definition );
		$existing_settings  = is_array( $existing_element['settings'] ?? null ) ? $existing_element['settings'] : [];
		$preserve_unchanged = ( $existing_element['name'] ?? '' ) === $element_name &&
			self::element_root_selector( $existing_element, $existing_settings, $component_definition ) === $root_selector;

		foreach ( array_keys( $settings ) as $key ) {
			if ( strpos( (string) $key, '_cssCustom' ) !== 0 || ! is_string( $settings[ $key ] ) || trim( $settings[ $key ] ) === '' ) {
				continue;
			}

			// A workspace text edit must not reformat or remap CSS already saved by
			// the Builder. Changed CSS and changed selector identities still normalize.
			if ( $preserve_unchanged && ( $existing_settings[ $key ] ?? null ) === $settings[ $key ] ) {
				continue;
			}

			// Breakpoint and variant CSS cannot be safely moved into base controls
			// with the current PHP mapper, but it still must not persist `%root%`.
			if ( $key !== '_cssCustom' ) {
				$settings[ $key ] = self::replace_root_placeholder( $settings[ $key ], $root_selector );
				continue;
			}

			$settings = self::map_base_custom_css_to_settings(
				$element_name,
				$root_selector,
				$settings
			);
		}

		$element['settings'] = $settings;

		return $element;
	}

	/**
	 * Normalize a global class settings payload.
	 *
	 * @since 2.4
	 *
	 * @param string $class_name Class name without dot.
	 * @param array  $settings   Global class settings.
	 * @return array
	 */
	public static function normalize_global_class_settings( string $class_name, array $settings ): array {
		self::load_html_to_bricks_mapper();

		$class_name = trim( $class_name );

		if ( $class_name === '' ) {
			return $settings;
		}

		$root_selector = '.' . $class_name;

		foreach ( array_keys( $settings ) as $key ) {
			if ( strpos( (string) $key, '_cssCustom' ) !== 0 || ! is_string( $settings[ $key ] ) || trim( $settings[ $key ] ) === '' ) {
				continue;
			}

			if ( $key !== '_cssCustom' ) {
				$settings[ $key ] = self::replace_root_placeholder( $settings[ $key ], $root_selector );
				continue;
			}

			$settings = self::map_base_custom_css_to_settings( 'div', $root_selector, $settings );
		}

		return $settings;
	}

	/**
	 * Move mappable root CSS declarations into Bricks settings.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name  Element name used for schema lookup.
	 * @param string $root_selector Persisted root selector.
	 * @param array  $settings      Settings array.
	 * @return array
	 */
	private static function map_base_custom_css_to_settings( string $element_name, string $root_selector, array $settings ): array {
		$custom_css = self::normalize_custom_css_input( (string) $settings['_cssCustom'], $root_selector );

		if ( $custom_css === '' ) {
			unset( $settings['_cssCustom'] );
			return $settings;
		}

		$rules   = \Bricks\Html_To_Bricks_Css_Parser::parse_css( $custom_css );
		$targets = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) || empty( $rule['selectors'] ) || empty( $rule['declarations'] ) ) {
				continue;
			}

			foreach ( $rule['selectors'] as $selector ) {
				$targets[] = \Bricks\Html_To_Bricks_Css_Control_Mapper::build_root_target( $selector, $rule['declarations'] );
			}
		}

		if ( empty( $targets ) ) {
			$settings['_cssCustom'] = $custom_css;
			return $settings;
		}

		unset( $settings['_cssCustom'] );

		$mapped = \Bricks\Html_To_Bricks_Css_Control_Mapper::map_targets_with_schema(
			$element_name,
			$root_selector,
			$targets,
			$settings
		);

		$settings     = $mapped['settings'];
		$fallback_css = self::build_fallback_custom_css( $rules, $mapped['unmappedTargets'] ?? [] );

		if ( $fallback_css !== '' ) {
			$settings['_cssCustom'] = $fallback_css;
		}

		return $settings;
	}

	/**
	 * Build fallback CSS from unmapped targets and preserved at-rules.
	 *
	 * @since 2.4
	 *
	 * @param array $rules           Parsed CSS rules.
	 * @param array $unmapped_targets Unmapped rule targets.
	 * @return string
	 */
	private static function build_fallback_custom_css( array $rules, array $unmapped_targets ): string {
		$fallback = \Bricks\Html_To_Bricks_Css_Control_Mapper::build_css_custom_from_targets( $unmapped_targets );
		$parts    = [];

		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['isAtRule'] ) && ! empty( $rule['raw'] ) ) {
				$parts[] = trim( (string) $rule['raw'] );
			}
		}

		if ( trim( $fallback ) !== '' ) {
			$parts[] = trim( $fallback );
		}

		return trim( implode( "\n", array_filter( $parts ) ) );
	}

	/**
	 * Normalize raw custom CSS into a parseable block using the persisted root.
	 *
	 * @since 2.4
	 *
	 * @param string $custom_css    Raw custom CSS.
	 * @param string $root_selector Persisted root selector.
	 * @return string
	 */
	private static function normalize_custom_css_input( string $custom_css, string $root_selector ): string {
		$custom_css = self::replace_root_placeholder( $custom_css, $root_selector );
		$custom_css = trim( $custom_css );

		if ( $custom_css !== '' && strpos( $custom_css, '{' ) === false && strpos( $custom_css, ':' ) !== false ) {
			$custom_css = "{$root_selector} {\n  {$custom_css}\n}";
		}

		return $custom_css;
	}

	/**
	 * Replace the builder UI's `%root%` token with the persisted selector.
	 *
	 * @since 2.4
	 *
	 * @param string $custom_css    Raw custom CSS.
	 * @param string $root_selector Persisted root selector.
	 * @return string
	 */
	private static function replace_root_placeholder( string $custom_css, string $root_selector ): string {
		return str_replace( '%root%', $root_selector, $custom_css );
	}

	/**
	 * Resolve the selector that should be stored in custom CSS.
	 *
	 * @since 2.4
	 *
	 * @param array $element              Element row.
	 * @param array $settings             Element settings.
	 * @param bool  $component_definition Whether saved inside a component definition.
	 * @return string
	 */
	private static function element_root_selector( array $element, array $settings, bool $component_definition ): string {
		$element_id = (string) ( $element['id'] ?? '' );

		if ( ! empty( $element['cid'] ) ) {
			return '.brxe-' . $element['cid'];
		}

		if ( $component_definition || ! empty( $element['parentComponent'] ) ) {
			return '.brxe-' . $element_id;
		}

		if ( ! empty( $settings['_cssId'] ) ) {
			return '#' . $settings['_cssId'];
		}

		return '#brxe-' . $element_id;
	}

	/**
	 * Load PHP HTML-to-Bricks CSS mapper classes when called outside conversion.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function load_html_to_bricks_mapper(): void {
		if ( class_exists( '\\Bricks\\Html_To_Bricks_Css_Control_Mapper', false ) ) {
			return;
		}

		foreach ( glob( BRICKS_PATH . 'includes/html-to-bricks/*.php' ) as $html_to_bricks_file ) {
			require_once $html_to_bricks_file;
		}

		foreach ( glob( BRICKS_PATH . 'includes/html-to-bricks/css-to-controls/*.php' ) as $css_to_controls_file ) {
			require_once $css_to_controls_file;
		}
	}
}

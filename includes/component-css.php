<?php
/**
 * Ordered component CSS groups based on control rule ownership.
 *
 * @since 2.4.3
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify generated rules without reconstructing ownership from selector syntax.
 *
 * @since 2.4.3
 */
class Component_Css {
	/**
	 * States whose selectors stay within the generated component scope.
	 *
	 * Compound or custom states retain the full CSS block; this list limits only
	 * deduplication eligibility, not which states users can style.
	 *
	 * @since 2.4.3
	 * @var string[]
	 */
	const STATES = [ '', ':hover', ':focus', ':focus-visible', ':focus-within', ':active', ':visited', ':disabled', ':checked', ':before', ':after', '::before', '::after' ];

	/**
	 * Icon controls can emit these declarations, including color alongside fill.
	 * Logical sizes and all can override the same properties in authored CSS.
	 *
	 * @since 2.4.3
	 * @var string[]
	 */
	const ICON_PROPERTIES = [ 'height', 'width', 'inline-size', 'block-size', 'stroke-width', 'stroke', 'fill', 'color', 'all' ];

	/**
	 * The last authored CSS classification per component and breakpoint.
	 *
	 * This is a pure parsing cache: it never decides whether rules were emitted.
	 * Each entry stores the CSS string, exact root selectors, and safety result.
	 * Retaining only the latest entry per scope avoids storing every instance's CSS.
	 *
	 * @since 2.4.3
	 * @var array
	 */
	private static $authored_css = [];

	/**
	 * Whether a generated state has a known local scope.
	 *
	 * @since 2.4.3
	 *
	 * @param string $state Generated pseudo state.
	 * @return bool
	 */
	public static function is_supported_state( $state ) {
		return in_array( $state, self::STATES, true );
	}

	/**
	 * Group complete ordered rule sets only when their ownership is unambiguous.
	 *
	 * A selector receiving multiple origins must retain its combined declarations.
	 * Authored SVG declarations that overlap Icon controls also retain the old path.
	 * Both groups preserve breakpoint, selector, and declaration insertion order.
	 * Origin metadata uses rules[breakpoint][selector][origin], roots[selector],
	 * and an optional external flag for targets that cannot be safely classified.
	 *
	 * @since 2.4.3
	 *
	 * @param array  $rules Rules keyed by breakpoint and selector.
	 * @param array  $origins Control-origin metadata and exact component variant scopes.
	 * @param string $selector Runtime component root selector.
	 * @return array|null Shared and icon groups, or null when separation is unsafe.
	 */
	public static function group_rules( $rules, $origins, $selector ) {
		// Explicit targets or untracked generators can affect rules outside this component.
		if ( ! empty( $origins['external'] ) ) {
			return null;
		}
		$groups = [
			'shared' => [],
			'icon'   => []
		];
		$roots  = [ $selector => true ] + ( $origins['roots'] ?? [] );

		foreach ( $rules as $breakpoint => $selectors ) {
			foreach ( $selectors as $rule_selector => $declarations ) {
				if ( $rule_selector === '_cssCustom' ) {
					// Keep authored text with root styles, then validate it against all collected scopes.
					$groups['shared'][ $breakpoint ][ $rule_selector ] = $declarations;
					continue;
				}

				// A mixed or unknown origin cannot be split without changing declaration order.
				$source = $origins['rules'][ $breakpoint ][ $rule_selector ] ?? [];
				if ( ! is_array( $declarations ) || ! is_array( $source ) || count( $source ) !== 1 || ( ! isset( $source['root'] ) && ! isset( $source['icon'] ) ) ) {
					return null;
				}

				$is_root = isset( $source['root'] );
				foreach ( $declarations as $declaration ) {
					// Values that can escape a generated rule must retain the original block.
					if ( ! is_string( $declaration ) || strpbrk( $declaration, '{}\\' ) !== false || strpos( $declaration, '/*' ) !== false ) {
						return null;
					}
					// Preserve the legacy no-op colon from a cleared Icon property. Other Icon
					// declarations must match native output; extra properties could overlap authored CSS.
					if ( ! $is_root && trim( $declaration ) !== ':' ) {
						$parts = explode( ':', $declaration, 2 );
						if ( count( $parts ) !== 2 || ! in_array( $parts[0], [ 'height', 'width', 'stroke-width', 'stroke', 'fill', 'color' ], true ) || strpos( $parts[1], ';' ) !== false ) {
							return null;
						}
					}
				}
				// Match the existing serializer's declaration deduplication without sorting rules.
				$group = $is_root ? 'shared' : 'icon';
				$groups[ $group ][ $breakpoint ][ $rule_selector ] = array_values( array_unique( $declarations, SORT_STRING ) );
				if ( $is_root ) {
					$roots[ $rule_selector ] = true;
				}
			}
		}

		// Variant scopes may appear after authored CSS in the input, so validate only now.
		foreach ( $groups['shared'] as $breakpoint => $selectors ) {
			if ( ! isset( $selectors['_cssCustom'] ) ) {
				continue;
			}

			// Identical authored text is reusable only if its allowed component scopes also match.
			$css      = $selectors['_cssCustom'];
			$previous = self::$authored_css[ $selector ][ $breakpoint ] ?? [];
			if ( ( $previous['css'] ?? null ) !== $css || ( $previous['roots'] ?? null ) !== $roots ) {
				$previous                                       = [
					'css'   => $css,
					'roots' => $roots,
					'safe'  => self::is_authored_css_independent( $css, $roots )
				];
				self::$authored_css[ $selector ][ $breakpoint ] = $previous;
			}

			if ( ! $previous['safe'] ) {
				return null;
			}
		}

		return $groups;
	}

	/**
	 * Recognize a fully consumed, flat authored stylesheet without changing its bytes.
	 *
	 * The importer parser accepts broader CSS and recovers from invalid syntax.
	 * Optimization needs a stricter contract: reject escapes, comments, at-rules,
	 * nesting, and any unconsumed input instead of treating a partial parse as safe.
	 *
	 * @since 2.4.3
	 *
	 * @param string $css Prepared authored CSS.
	 * @param array  $roots Exact selectors tagged as roots by the generator.
	 * @return bool Whether authored CSS can stay with the shared component rules.
	 */
	private static function is_authored_css_independent( $css, $roots ) {
		if ( ! is_string( $css ) || strpos( $css, '\\' ) !== false || strpos( $css, '@' ) !== false || strpos( $css, '/*' ) !== false ) {
			return false;
		}

		// Use exact scopes supplied by the generator, including both Button and SVG states.
		// Other descendants or functional selectors retain their original complete CSS.
		$scopes = [];
		foreach ( $roots as $root => $unused ) {
			foreach ( self::STATES as $state ) {
				$scopes[ $root . $state ]          = 'root';
				$scopes[ $root . $state . ' svg' ] = 'icon';
				$scopes[ $root . ' svg' . $state ] = 'icon';
			}
		}

		$offset = 0;
		// Load the existing importer parser only when authored CSS needs checking.
		// Its nested file location is not covered by the flat class autoloader.
		require_once __DIR__ . '/html-to-bricks/css-parser.php';

		foreach ( Html_To_Bricks_Css_Parser::parse_css( $css ) as $rule ) {
			// Require contiguous source coverage: parser recovery must not hide unsafe input.
			$offset += strspn( $css, " \t\r\n\f", $offset );
			$raw     = $rule['raw'] ?? '';
			if ( $raw === '' || ! empty( $rule['isAtRule'] ) || substr( $css, $offset, strlen( $raw ) ) !== $raw ) {
				return false;
			}

			// Nested blocks, including ambiguous braces in values, use the full-block fallback.
			$offset += strlen( $raw );
			$body    = substr( $raw, strpos( $raw, '{' ) + 1, -1 );
			if ( strpos( $body, '{' ) !== false || strpos( $body, '}' ) !== false ) {
				return false;
			}

			foreach ( $rule['selectors'] as $rule_selector ) {
				$scope = $scopes[ $rule_selector ] ?? null;
				if ( $scope === null ) {
					return false;
				}

				foreach ( $rule['declarations'] as $property => $value ) {
					// Root overrides stay beside generated root rules. SVG overrides of Icon
					// properties could lose precedence if a later instance only emitted its Icon group.
					$property = strtolower( $property );
					if ( strspn( $property, '-abcdefghijklmnopqrstuvwxyz0123456789' ) !== strlen( $property ) || ( $scope === 'icon' && in_array( $property, self::ICON_PROPERTIES, true ) ) ) {
						return false;
					}
				}
			}
		}

		// A successfully parsed prefix does not make a dropped trailing fragment safe.
		$offset += strspn( $css, " \t\r\n\f", $offset );
		return $offset === strlen( $css );
	}
}

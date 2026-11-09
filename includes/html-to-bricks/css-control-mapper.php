<?php
/**
 * CSS to Control Mapper
 *
 * Maps CSS declarations to Bricks element control settings using the control index.
 *
 * PHP port of src/vue/utils/htmlToBricks/cssControlMapper.js
 *
 * @since 2.4
 * @package Bricks
 */

namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS to control mapper for HTML to Bricks conversion.
 *
 * @since 2.4
 */
class Html_To_Bricks_Css_Control_Mapper {
	/**
	 * Control index cache keyed by element name.
	 *
	 * @since 2.4
	 * @var array
	 */
	private static $control_index_cache = [];

	/**
	 * Direct control types that pass values through as-is.
	 *
	 * @since 2.4
	 * @var array
	 */
	private static $direct_control_types = [
		'number'          => true,
		'text'            => true,
		'select'          => true,
		'direction'       => true,
		'align-items'     => true,
		'justify-content' => true,
	];

	/**
	 * Pseudo selectors available in Bricks' default pseudo-class store.
	 *
	 * Other pseudos remain scoped CSS because they are not guaranteed to exist
	 * in the site's pseudo-class store; structural pseudos also depend on DOM position.
	 *
	 * @var array
	 */
	private static $native_style_pseudos = [
		':hover'  => true,
		':focus'  => true,
		':active' => true,
	];

	/**
	 * Spacing side map for individual margin/padding properties.
	 *
	 * @since 2.4
	 * @var array
	 */
	private static $spacing_side_map = [
		'margin-top'     => [ 'margin', 'top' ],
		'margin-right'   => [ 'margin', 'right' ],
		'margin-bottom'  => [ 'margin', 'bottom' ],
		'margin-left'    => [ 'margin', 'left' ],
		'padding-top'    => [ 'padding', 'top' ],
		'padding-right'  => [ 'padding', 'right' ],
		'padding-bottom' => [ 'padding', 'bottom' ],
		'padding-left'   => [ 'padding', 'left' ],
	];

	/**
	 * Normalize a CSS selector string.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return string Normalized selector.
	 */
	public static function normalize_selector( $selector = '' ) {
		$sel = trim( (string) $selector );
		$sel = preg_replace( '/\s+/', ' ', $sel );
		$sel = preg_replace( '/\s*([>+~])\s*/', '$1', $sel );
		return $sel;
	}

	/**
	 * Return the modern name used to compare legacy gap aliases.
	 *
	 * Imported CSS may use either spelling. Canonical comparison lets the stale
	 * generated control index continue to map aliases without regenerating it.
	 *
	 * @since 2.4
	 * @see https://app.clickup.com/t/2615406/86c2v7zk1
	 *
	 * @param string $property CSS property name.
	 * @return string Canonical property name.
	 */
	private static function canonicalize_gap_property( $property ) {
		$property = strtolower( trim( (string) $property ) );
		$aliases  = [
			'grid-gap'        => 'gap',
			'grid-column-gap' => 'column-gap',
			'grid-row-gap'    => 'row-gap',
		];

		return isset( $aliases[ $property ] ) ? $aliases[ $property ] : $property;
	}

	/**
	 * Strip trailing pseudo-elements/classes from a selector.
	 *
	 * @since 2.4
	 *
	 * @param string $selector CSS selector.
	 * @return array Associative array with 'base' and 'pseudo' keys.
	 */
	public static function strip_trailing_pseudo( $selector = '' ) {
		$base   = (string) $selector;
		$pseudo = '';

		while ( true ) {
			if ( preg_match( '/(::?[a-z-]+(?:\([^)]*\))?)$/i', $base, $match ) ) {
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
	 * Check whether a pseudo selector can use a native Bricks setting suffix.
	 *
	 * @since 2.4
	 *
	 * @param string $pseudo Pseudo selector suffix.
	 * @return bool
	 */
	private static function can_map_pseudo_to_native_state( $pseudo = '' ) {
		return ! $pseudo || isset( self::$native_style_pseudos[ strtolower( (string) $pseudo ) ] );
	}

	/**
	 * Expand a control selector relative to a root selector.
	 *
	 * @since 2.4
	 *
	 * @param string $root_selector Root element selector.
	 * @param string $selector      Control CSS selector.
	 * @param string $pseudo        Pseudo string to replace {pseudo} placeholder.
	 * @return string Expanded selector.
	 */
	private static function expand_control_selector( $root_selector, $selector, $pseudo = '' ) {
		if ( empty( $selector ) ) {
			return self::normalize_selector( $root_selector );
		}

		$sel = (string) $selector;

		if ( strpos( $sel, '{pseudo}' ) !== false ) {
			$sel = str_replace( '{pseudo}', $pseudo, $sel );
		}

		if ( strpos( $sel, '&' ) === 0 ) {
			return self::normalize_selector( $root_selector . substr( $sel, 1 ) );
		}

		return self::normalize_selector( $root_selector . ' ' . $sel );
	}

	/**
	 * Check if a CSS property can map to a control.
	 *
	 * @since 2.4
	 *
	 * @param array  $control              Control definition.
	 * @param string $css_property          Control's CSS property.
	 * @param string $declaration_property  Declaration's CSS property.
	 * @return bool Whether the property can map.
	 */
	private static function can_property_map_to_control( $control, $css_property, $declaration_property ) {
		$control_type = isset( $control['type'] ) ? $control['type'] : ( isset( $control['controlType'] ) ? $control['controlType'] : '' );

		// The converter consumes both legacy and modern gap spellings but always maps
		// them onto the existing Bricks setting keys. #86c2v7zk1; @since 2.4
		$css_property         = self::canonicalize_gap_property( $css_property );
		$declaration_property = self::canonicalize_gap_property( $declaration_property );

		if ( $css_property === $declaration_property ) {
			return true;
		}

		if ( $control_type === 'typography' && $css_property === 'font' ) {
			if ( $declaration_property === 'color' || strpos( $declaration_property, 'font-' ) === 0 ) {
				return true;
			}

			$typography_props = [ 'line-height', 'letter-spacing', 'text-align', 'text-transform', 'text-decoration' ];
			if ( in_array( $declaration_property, $typography_props, true ) ) {
				return true;
			}
		}

		if ( $control_type === 'spacing' && in_array( $css_property, [ 'margin', 'padding' ], true ) ) {
			return $declaration_property === $css_property || strpos( $declaration_property, $css_property . '-' ) === 0;
		}

		if ( $control_type === 'background' && strpos( $css_property, 'background' ) === 0 ) {
			if ( $declaration_property === $css_property ) {
				return true;
			}

			$bg_props = [
				'background-color',
				'background-image',
				'background-size',
				'background-position',
				'background-repeat',
				'background-attachment',
			];
			if ( in_array( $declaration_property, $bg_props, true ) ) {
				return true;
			}
		}

		if ( $control_type === 'border' && strpos( $css_property, 'border' ) === 0 ) {
			return $declaration_property === $css_property || strpos( $declaration_property, 'border' ) === 0;
		}

		return false;
	}

	/**
	 * Create element control index for efficient lookups.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return array Flat list of control mappings.
	 */
	private static function create_element_control_index( $element_name ) {
		if ( empty( $element_name ) ) {
			return [];
		}

		if ( isset( self::$control_index_cache[ $element_name ] ) ) {
			return self::$control_index_cache[ $element_name ];
		}

		$controls = Html_To_Bricks_Control_Index::get_all_element_controls( $element_name );
		$index    = [];
		$order    = 0;

		foreach ( $controls as $control_key => $control ) {
			$css_mappings = isset( $control['css'] ) && is_array( $control['css'] ) ? $control['css'] : [];

			foreach ( $css_mappings as $mapping ) {
				if ( empty( $mapping['property'] ) ) {
					continue;
				}

				$index[] = [
					'controlKey'  => $control_key,
					'controlType' => isset( $control['type'] ) ? $control['type'] : '',
					'property'    => self::canonicalize_gap_property( $mapping['property'] ),
					'selector'    => isset( $mapping['selector'] ) ? $mapping['selector'] : '',
					'order'       => $order++,
				];
			}
		}

		self::$control_index_cache[ $element_name ] = $index;
		return $index;
	}

	/**
	 * Merge a setting value into the settings array.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings Settings array (passed by reference).
	 * @param string $key      Setting key.
	 * @param mixed  $value    Setting value.
	 */
	private static function merge_setting( &$settings, $key, $value ) {
		if ( $value === null ) {
			return;
		}

		if (
			is_array( $value ) &&
			isset( $settings[ $key ] ) &&
			is_array( $settings[ $key ] )
		) {
			$settings[ $key ] = array_merge( $settings[ $key ], $value );
			return;
		}

		$settings[ $key ] = $value;
	}

	/**
	 * Map a CSS property/value to a spacing control value.
	 *
	 * @since 2.4
	 *
	 * @param string $property       CSS property.
	 * @param string $value          CSS value.
	 * @param array  $existing_value Existing spacing value.
	 * @return array|null Spacing value or null.
	 */
	private static function map_to_spacing_value( $property, $value, $existing_value = [] ) {
		$low_property = strtolower( $property );
		$next_value   = ! empty( $existing_value ) ? $existing_value : [];

		if ( $low_property === 'margin' ) {
			$parsed = Html_To_Bricks_Spacing::convert_margin( [ 'margin' => $value ] );
			return $parsed ? array_merge( $next_value, $parsed ) : null;
		}

		if ( $low_property === 'padding' ) {
			$parsed = Html_To_Bricks_Spacing::convert_padding( [ 'padding' => $value ] );
			return $parsed ? array_merge( $next_value, $parsed ) : null;
		}

		if ( isset( self::$spacing_side_map[ $low_property ] ) ) {
			$side                   = self::$spacing_side_map[ $low_property ];
			$next_value[ $side[1] ] = $value;
			return $next_value;
		}

		return null;
	}

	/**
	 * Map a CSS property/value to a typography control value.
	 *
	 * @since 2.4
	 *
	 * @param string $property       CSS property.
	 * @param string $value          CSS value.
	 * @param array  $existing_value Existing typography value.
	 * @return array|null Typography value or null.
	 */
	private static function map_to_typography_value( $property, $value, $existing_value = [] ) {
		$parsed = Html_To_Bricks_Typography::convert_typography( [ $property => $value ] );
		return $parsed ? array_merge( ! empty( $existing_value ) ? $existing_value : [], $parsed ) : null;
	}

	/**
	 * Map a CSS property/value to a background control value.
	 *
	 * @since 2.4
	 *
	 * @param string $property       CSS property.
	 * @param string $value          CSS value.
	 * @param array  $existing_value Existing background value.
	 * @return array|null Background value or null.
	 */
	private static function map_to_background_value( $property, $value, $existing_value = [] ) {
		$low_property = strtolower( $property );
		$next_value   = ! empty( $existing_value ) ? $existing_value : [];

		if ( $low_property === 'background-color' ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_color( $value );

			if ( ! $parsed ) {
				return null;
			}

			$next_value['color'] = $parsed;
			return $next_value;
		}

		if ( $low_property === 'background-image' ) {
			$parsed = Html_To_Bricks_Css_Value_Parsers::parse_background_image( $value );

			if ( ! $parsed || empty( $parsed['url'] ) ) {
				return null;
			}

			$next_value['image'] = [ 'url' => $parsed['url'] ];
			return $next_value;
		}

		if ( $low_property === 'background-size' ) {
			$next_value['size'] = $value;
			return $next_value;
		}

		if ( $low_property === 'background-position' ) {
			$next_value['position'] = $value;
			return $next_value;
		}

		if ( $low_property === 'background-repeat' ) {
			$next_value['repeat'] = $value;
			return $next_value;
		}

		if ( $low_property === 'background-attachment' ) {
			$next_value['attachment'] = $value;
			return $next_value;
		}

		return null;
	}

	/**
	 * Map a CSS property/value to a border control value.
	 *
	 * @since 2.4
	 *
	 * @param string $property       CSS property.
	 * @param string $value          CSS value.
	 * @param array  $existing_value Existing border value.
	 * @return array|null Border value or null.
	 */
	private static function map_to_border_value( $property, $value, $existing_value = [] ) {
		$parsed = Html_To_Bricks_Border::convert_border( [ $property => $value ] );

		if ( ! $parsed ) {
			return null;
		}

		$next_value = array_merge( ! empty( $existing_value ) ? $existing_value : [], $parsed );

		foreach ( [ 'width', 'radius' ] as $key ) {
			if (
				isset( $parsed[ $key ] ) &&
				is_array( $parsed[ $key ] ) &&
				isset( $existing_value[ $key ] ) &&
				is_array( $existing_value[ $key ] )
			) {
				$next_value[ $key ] = array_merge( $existing_value[ $key ], $parsed[ $key ] );
			}
		}

		return $next_value;
	}

	/**
	 * Map a CSS declaration to a control value based on the control type.
	 *
	 * @since 2.4
	 *
	 * @param string $control_type  Control type.
	 * @param string $property      CSS property.
	 * @param string $value         CSS value.
	 * @param mixed  $existing_value Existing value for this control.
	 * @return mixed Mapped value or null.
	 */
	private static function map_declaration_to_control_value( $control_type, $property, $value, $existing_value = null ) {
		if ( $control_type === 'box-shadow' ) {
			return Html_To_Bricks_Css_Value_Parsers::parse_native_box_shadow( $value );
		}
		if ( $control_type === 'gradient' ) {
			return Html_To_Bricks_Css_Value_Parsers::parse_native_linear_gradient( $value );
		}

		if ( $control_type === 'color' ) {
			return Html_To_Bricks_Css_Value_Parsers::parse_color( $value );
		}

		if ( $control_type === 'spacing' ) {
			return self::map_to_spacing_value( $property, $value, is_array( $existing_value ) ? $existing_value : [] );
		}

		if ( $control_type === 'typography' ) {
			return self::map_to_typography_value( $property, $value, is_array( $existing_value ) ? $existing_value : [] );
		}

		if ( $control_type === 'background' ) {
			return self::map_to_background_value( $property, $value, is_array( $existing_value ) ? $existing_value : [] );
		}

		if ( $control_type === 'border' ) {
			return self::map_to_border_value( $property, $value, is_array( $existing_value ) ? $existing_value : [] );
		}

		if ( isset( self::$direct_control_types[ $control_type ] ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Pick the best candidate from a list of control mapping candidates.
	 *
	 * @since 2.4
	 *
	 * @param array  $candidates       List of candidate mappings.
	 * @param string $effective_display Effective CSS display for this target.
	 * @return array|null Best candidate or null.
	 */
	private static function pick_candidate( $candidates, $effective_display = '' ) {
		if ( empty( $candidates ) ) {
			return null;
		}

		// Sort by rank first, then by order
		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['rank'] !== $b['rank'] ) {
					return $a['rank'] - $b['rank'];
				}
				return $a['order'] - $b['order'];
			}
		);

		$top_rank = $candidates[0]['rank'];

		$top = array_filter(
			$candidates,
			function ( $entry ) use ( $top_rank ) {
				return $entry['rank'] === $top_rank;
			}
		);
		$top = array_values( $top );

		$unique_keys = array_unique(
			array_map(
				function ( $entry ) {
					return $entry['controlKey'];
				},
				$top
			)
		);

		if ( count( $unique_keys ) > 1 ) {
			// Prefer non-inherited controls (those not starting with '_')
			$non_inherited = array_values(
				array_filter(
					$top,
					function ( $entry ) {
						return strpos( (string) $entry['controlKey'], '_' ) !== 0;
					}
				)
			);

			if ( count( $non_inherited ) === 1 ) {
				return $non_inherited[0];
			}

			if ( count( $non_inherited ) > 1 ) {
				usort(
					$non_inherited,
					function ( $a, $b ) {
						return $a['order'] - $b['order'];
					}
				);
				return $non_inherited[0];
			}

			// All candidates are _-prefixed inherited/layout controls. Bricks uses
			// separate alignment controls for grid and flex, even though they map to
			// the same CSS properties.
			$inherited = array_values(
				array_filter(
					$top,
					function ( $entry ) {
						return strpos( (string) $entry['controlKey'], '_' ) === 0;
					}
				)
			);
			$is_grid   = in_array( strtolower( trim( (string) $effective_display ) ), [ 'grid', 'inline-grid' ], true );
			$grid_only = array_values(
				array_filter(
					$inherited,
					function ( $entry ) {
						return substr( (string) $entry['controlKey'], -4 ) === 'Grid';
					}
				)
			);

			if ( $is_grid && ! empty( $grid_only ) ) {
				usort(
					$grid_only,
					function ( $a, $b ) {
						return $a['order'] - $b['order'];
					}
				);
				return $grid_only[0];
			}

			$non_grid = array_values(
				array_filter(
					$inherited,
					function ( $entry ) {
						return substr( (string) $entry['controlKey'], -4 ) !== 'Grid';
					}
				)
			);

			if ( count( $non_grid ) === 1 ) {
				return $non_grid[0];
			}

			if ( count( $non_grid ) > 1 ) {
				usort(
					$non_grid,
					function ( $a, $b ) {
						return $a['order'] - $b['order'];
					}
				);
				return $non_grid[0];
			}

			return null;
		}

		return $top[0];
	}

	/**
	 * Place display controls before settings whose generated CSS depends on them.
	 *
	 * Bricks emits layout reset declarations from the display control. Keeping
	 * display first prevents those resets from overriding imported alignment.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Mapped Bricks settings.
	 * @return array Settings with stable display-first ordering.
	 */
	private static function order_display_settings_first( $settings ) {
		$display_settings = [];
		$other_settings   = [];

		foreach ( $settings as $key => $value ) {
			if ( preg_match( '/^_display(?=:|$)/', (string) $key ) ) {
				$display_settings[ $key ] = $value;
				continue;
			}

			$other_settings[ $key ] = $value;
		}

		return array_merge( $display_settings, $other_settings );
	}

	/**
	 * Keep unresolved effect declarations together instead of splitting their cascade.
	 *
	 * @since 2.4.3
	 * @param array      $targets CSS targets.
	 * @param array|null $mapped_targets Targets that can use native controls.
	 * @return array Conversion policy keyed by selector.
	 */
	public static function get_native_effect_policy( $targets, $mapped_targets = null ) {
		$groups         = [];
		$mapped_targets = $mapped_targets ?? $targets;
		foreach ( $targets as $target ) {
			$selector = self::normalize_selector( $target['selectorBase'] ?? $target['selector'] ?? '' );
			if ( ! isset( $groups[ $selector ] ) ) {
				$groups[ $selector ] = [
					'shadow'     => true,
					'hasShadow'  => false,
					'background' => true,
					'gradient'   => false,
					'shorthand'  => false,
					'longhand'   => false
				];
			}
			$policy = &$groups[ $selector ];
			if ( ! empty( $target['preserveNativeEffects'] ) ) {
				$policy['shadow']     = false;
				$policy['background'] = false;
			}
			foreach ( $target['declarations'] ?? [] as $property => $value ) {
				$low = strtolower( $property );
				if ( $low === 'box-shadow' ) {
					$policy['hasShadow'] = true;
				}
				if ( ! in_array( $target, $mapped_targets, true ) ) {
					if ( $low === 'box-shadow' ) {
						$policy['shadow'] = false;
					}
					if ( $low === 'background' || strpos( $low, 'background-' ) === 0 ) {
						$policy['background'] = false;
					}
				}
				if ( $low === 'all' ) {
					$policy['shadow']     = false;
					$policy['background'] = false;
				}
				if ( $low === 'box-shadow' && ! Html_To_Bricks_Css_Value_Parsers::parse_native_box_shadow( $value ) ) {
					$policy['shadow'] = false;
				}
				if ( $low === 'background' || strpos( $low, 'background-' ) === 0 ) {
					$policy['gradient']  = $policy['gradient'] || stripos( $value, 'gradient(' ) !== false;
					$policy['shorthand'] = $policy['shorthand'] || $low === 'background';
					$policy['longhand']  = $policy['longhand'] || $low !== 'background';
					if ( $low === 'background' || $low === 'background-image' ) {
						if ( ! Html_To_Bricks_Css_Value_Parsers::parse_native_linear_gradient( $value ) ) {
							$policy['background'] = false;
						}
					} elseif ( ! in_array( $low, [ 'background-color', 'background-size', 'background-position', 'background-repeat', 'background-attachment' ], true ) || stripos( $value, '!important' ) !== false || ( $low !== 'background-color' && strpos( $value, ',' ) !== false ) ) {
						$policy['background'] = false;
					}
				}
			}
			unset( $policy );
		}
		foreach ( $groups as &$policy ) {
			if ( $policy['shorthand'] && $policy['longhand'] ) {
				$policy['background'] = false;
			}
		}
		unset( $policy );
		return $groups;
	}

	/**
	 * Preserve the reset effects of a gradient-only background shorthand.
	 *
	 * @since 2.4.3
	 * @param array $declarations CSS declarations.
	 * @return array Expanded declarations without a competing background-image fallback.
	 */
	private static function expand_native_gradient_background( $declarations ) {
		$expanded = [];
		foreach ( $declarations as $property => $value ) {
			if ( strtolower( $property ) !== 'background' ) {
				$expanded[ $property ] = $value;
				continue;
			}
			$expanded = array_merge(
				$expanded,
				[
					'background-image'      => $value,
					'background-color'      => 'transparent',
					'background-position'   => '0% 0%',
					'background-size'       => 'auto',
					'background-repeat'     => 'repeat',
					'background-attachment' => 'scroll',
					'background-origin'     => 'padding-box',
					'background-clip'       => 'border-box',
				]
			);
		}
		return $expanded;
	}

	/**
	 * Map CSS targets to schema-driven control settings.
	 *
	 * @since 2.4
	 *
	 * @param string     $element_name    Element name.
	 * @param string     $root_selector   Root CSS selector.
	 * @param array      $targets         CSS targets with declarations.
	 * @param array      $initial_settings Initial settings.
	 * @param array|null $effect_targets All related effect targets, including CSS fallbacks.
	 * @return array Associative array with 'settings' and 'unmappedTargets'.
	 */
	private static function map_targets_to_schema_controls( $element_name, $root_selector, $targets = [], $initial_settings = [], $effect_targets = null ) {
		$index         = self::create_element_control_index( $element_name );
		$settings      = $initial_settings;
		$effect_policy = self::get_native_effect_policy( array_merge( $effect_targets ?? [], $targets ), $targets );
		$unmapped      = [];

		if ( empty( $index ) || empty( $root_selector ) ) {
			return [
				'settings'        => $settings,
				'unmappedTargets' => $targets,
			];
		}

		// Resolve display before mapping any declaration. CSS declaration order is
		// not significant, and responsive alignment can inherit a base display.
		$display_by_context = [];

		foreach ( $targets as $target ) {
			$target_declarations = isset( $target['declarations'] ) ? $target['declarations'] : [];
			$target_selector     = self::normalize_selector( isset( $target['selectorBase'] ) ? $target['selectorBase'] : ( isset( $target['selector'] ) ? $target['selector'] : '' ) );
			$target_pseudo       = isset( $target['pseudo'] ) ? $target['pseudo'] : '';
			$target_breakpoint   = isset( $target['breakpoint'] ) ? $target['breakpoint'] : '';

			foreach ( $target_declarations as $property => $value ) {
				if ( strtolower( (string) $property ) !== 'display' ) {
					continue;
				}

				$context_key                        = "{$target_selector}\x00{$target_pseudo}\x00{$target_breakpoint}";
				$display_by_context[ $context_key ] = strtolower( trim( (string) $value ) );
			}
		}

		foreach ( $targets as $target ) {
			$target_declarations       = isset( $target['declarations'] ) ? $target['declarations'] : [];
			$remaining_declarations    = [];
			$target_pseudo             = isset( $target['pseudo'] ) ? $target['pseudo'] : '';
			$target_breakpoint         = isset( $target['breakpoint'] ) ? $target['breakpoint'] : '';
			$target_selector_no_pseudo = self::normalize_selector( isset( $target['selectorBase'] ) ? $target['selectorBase'] : ( isset( $target['selector'] ) ? $target['selector'] : '' ) );
			$target_selector_full      = self::normalize_selector( isset( $target['selector'] ) ? $target['selector'] : '' );

			$context_prefix              = "{$target_selector_no_pseudo}\x00{$target_pseudo}\x00";
			$non_pseudo_context_prefix   = "{$target_selector_no_pseudo}\x00\x00";
			$exact_context               = $context_prefix . $target_breakpoint;
			$exact_non_pseudo_context    = $non_pseudo_context_prefix . $target_breakpoint;
			$base_context                = $context_prefix;
			$base_non_pseudo_context     = $non_pseudo_context_prefix;
			$exact_display_key           = self::get_responsive_control_key( '_display', $target_breakpoint, $target_pseudo );
			$exact_non_pseudo_key        = self::get_responsive_control_key( '_display', $target_breakpoint, '' );
			$base_display_key            = self::get_responsive_control_key( '_display', '', $target_pseudo );
			$base_non_pseudo_display_key = self::get_responsive_control_key( '_display', '', '' );
			$effective_display           = '';

			if ( isset( $display_by_context[ $exact_context ] ) ) {
				$effective_display = $display_by_context[ $exact_context ];
			} elseif ( isset( $initial_settings[ $exact_display_key ] ) ) {
				$effective_display = $initial_settings[ $exact_display_key ];
			} elseif ( $target_pseudo && isset( $display_by_context[ $exact_non_pseudo_context ] ) ) {
				$effective_display = $display_by_context[ $exact_non_pseudo_context ];
			} elseif ( $target_pseudo && isset( $initial_settings[ $exact_non_pseudo_key ] ) ) {
				$effective_display = $initial_settings[ $exact_non_pseudo_key ];
			} elseif ( $target_breakpoint && isset( $display_by_context[ $base_context ] ) ) {
				$effective_display = $display_by_context[ $base_context ];
			} elseif ( isset( $initial_settings[ $base_display_key ] ) ) {
				$effective_display = $initial_settings[ $base_display_key ];
			} elseif ( isset( $display_by_context[ $base_non_pseudo_context ] ) ) {
				$effective_display = $display_by_context[ $base_non_pseudo_context ];
			} elseif ( isset( $initial_settings[ $base_non_pseudo_display_key ] ) ) {
				$effective_display = $initial_settings[ $base_non_pseudo_display_key ];
			}

			// Bricks setting suffixes only render for pseudo classes in the site store.
			// Structural selectors depend on DOM position, so keep the complete rule
			// as scoped CSS instead of creating inert keys such as `_padding:first-child`.
			if ( ! self::can_map_pseudo_to_native_state( $target_pseudo ) ) {
				$unmapped[] = $target;
				continue;
			}

			$policy = $effect_policy[ $target_selector_no_pseudo ];
			if ( $policy['gradient'] && $policy['background'] && count(
				array_filter(
					$index,
					static function ( $control ) {
						return $control['controlType'] === 'gradient';
					}
				)
			) ) {
				$target_declarations = self::expand_native_gradient_background( $target_declarations );
			}

			foreach ( $target_declarations as $property => $value ) {
				$declaration_property = self::canonicalize_gap_property( $property );
				if ( ( $declaration_property === 'box-shadow' && ! $policy['shadow'] ) || ( $policy['gradient'] && ! $policy['background'] && ( $declaration_property === 'background' || strpos( $declaration_property, 'background-' ) === 0 ) ) ) {
					$remaining_declarations[ $property ] = $value;
					continue;
				}
				$native_gradient = $declaration_property === 'background-image' && Html_To_Bricks_Css_Value_Parsers::parse_native_linear_gradient( $value );
				$candidates      = [];

				foreach ( $index as $control_mapping ) {
					if ( $native_gradient && $control_mapping['controlType'] === 'background' ) {
						continue;
					}
					if ( ! self::can_property_map_to_control( $control_mapping, $control_mapping['property'], $declaration_property ) ) {
						continue;
					}

					$has_pseudo_placeholder = strpos( (string) ( isset( $control_mapping['selector'] ) ? $control_mapping['selector'] : '' ), '{pseudo}' ) !== false;
					$control_selector       = self::expand_control_selector(
						$root_selector,
						isset( $control_mapping['selector'] ) ? $control_mapping['selector'] : '',
						$has_pseudo_placeholder ? $target_pseudo : ''
					);
					$selector_to_compare    = $has_pseudo_placeholder ? $target_selector_full : $target_selector_no_pseudo;

					if ( $control_selector !== $selector_to_compare ) {
						continue;
					}

					$candidates[] = array_merge(
						$control_mapping,
						[ 'rank' => $control_mapping['property'] === $declaration_property ? 0 : 1 ]
					);
				}

				$chosen = self::pick_candidate( $candidates, $effective_display );

				if ( ! $chosen ) {
					$remaining_declarations[ $property ] = $value;
					continue;
				}

				$setting_key  = self::get_responsive_control_key( $chosen['controlKey'], $target_breakpoint, $target_pseudo );
				$mapped_value = self::map_declaration_to_control_value(
					$chosen['controlType'],
					$declaration_property,
					$value,
					isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : null
				);

				if ( $mapped_value === null ) {
					$remaining_declarations[ $property ] = $value;
					continue;
				}

				if ( in_array( $chosen['controlType'], [ 'box-shadow', 'gradient' ], true ) ) {
					$settings[ $setting_key ] = $mapped_value;
				} else {
					self::merge_setting( $settings, $setting_key, $mapped_value );
				}
			}

			if ( ! empty( $remaining_declarations ) ) {
				$unmapped[] = array_merge( $target, [ 'declarations' => $remaining_declarations ] );
			}
		}

		return [
			'settings'        => self::order_display_settings_first( $settings ),
			'unmappedTargets' => $unmapped,
		];
	}

	/**
	 * Build a responsive control key from a base key, breakpoint, and pseudo.
	 *
	 * @since 2.4
	 *
	 * @param string $control_key Base control key.
	 * @param string $breakpoint  Breakpoint key.
	 * @param string $pseudo      Pseudo selector.
	 * @return string Responsive control key.
	 */
	public static function get_responsive_control_key( $control_key, $breakpoint = '', $pseudo = '' ) {
		return $breakpoint ? "{$control_key}:{$breakpoint}{$pseudo}" : "{$control_key}{$pseudo}";
	}

	/**
	 * Build CSS custom string from unmapped targets.
	 *
	 * @since 2.4
	 *
	 * @param array $targets Unmapped CSS targets.
	 * @return string CSS custom string.
	 */
	public static function build_css_custom_from_targets( $targets = [] ) {
		$groups = [];
		$order  = [];

		foreach ( $targets as $target ) {
			$declarations = isset( $target['declarations'] ) ? $target['declarations'] : [];
			$selector     = isset( $target['selector'] ) ? $target['selector'] : '';

			if ( empty( $declarations ) || empty( $selector ) ) {
				continue;
			}

			$at_rules = [];

			if ( ! empty( $target['atRules'] ) && is_array( $target['atRules'] ) ) {
				foreach ( $target['atRules'] as $at_rule ) {
					if ( ! empty( $at_rule['name'] ) ) {
						$at_rules[] = $at_rule;
					}
				}
			}

			$group_key = implode(
				"\x01",
				array_map(
					function ( $at_rule ) {
						$name   = isset( $at_rule['name'] ) ? $at_rule['name'] : '';
						$params = isset( $at_rule['params'] ) ? $at_rule['params'] : '';

						return "{$name}\x00{$params}";
					},
					$at_rules
				)
			);

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = [
					'atRules' => $at_rules,
					'entries' => [],
				];
				$order[]              = $group_key;
			}

			$groups[ $group_key ]['entries'][] = [
				'selector'     => $selector,
				'declarations' => $declarations,
			];
		}

		$blocks = [];

		foreach ( $order as $group_key ) {
			$group   = $groups[ $group_key ];
			$entries = [];

			foreach ( $group['entries'] as $entry ) {
				$lines = [];

				foreach ( $entry['declarations'] as $property => $value ) {
					$lines[] = "{$property}: {$value};";
				}

				if ( ! empty( $lines ) ) {
					$entries[] = "{$entry['selector']} {\n  " . implode( "\n  ", $lines ) . "\n}";
				}
			}

			$css = implode( "\n", $entries );

			if ( ! $css ) {
				continue;
			}

			foreach ( array_reverse( $group['atRules'] ) as $at_rule ) {
				$name     = isset( $at_rule['name'] ) ? $at_rule['name'] : '';
				$params   = ! empty( $at_rule['params'] ) ? ' ' . $at_rule['params'] : '';
				$indented = implode(
					"\n",
					array_map(
						function ( $line ) {
							return $line ? "  {$line}" : $line;
						},
						explode( "\n", $css )
					)
				);
				$css      = "@{$name}{$params} {\n{$indented}\n}";
			}

			$blocks[] = $css;
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Build _cssCustom settings grouped by breakpoint.
	 *
	 * @since 2.4
	 *
	 * @param array  $targets       CSS targets.
	 * @param string $root_selector Root CSS selector to duplicate for Bricks specificity.
	 * @return array CSS custom settings.
	 */
	public static function build_css_custom_settings_from_targets( $targets = [], $root_selector = '' ) {
		$targets_by_breakpoint = [];
		$root_selector         = self::normalize_selector( $root_selector );

		foreach ( $targets as $target ) {
			$breakpoint = isset( $target['breakpoint'] ) ? $target['breakpoint'] : '';
			if ( ! empty( $target['preserveEffectCascade'] ) && ! empty( $target['atRules'] ) ) {
				$breakpoint = '';
			}

			if ( $root_selector && ! empty( $target['selector'] ) && ! empty( $target['atRules'] ) && empty( $target['preserveEffectCascade'] ) ) {
				$selector = self::normalize_selector( $target['selector'] );

				if ( strpos( $selector, $root_selector ) === 0 ) {
					$boundary = substr( $selector, strlen( $root_selector ), 1 );

					if ( $boundary === '' || preg_match( '/[\s\.:#\[>+~]/', $boundary ) ) {
						// Native Bricks rules include the element type class (for example
						// .card.brxe-div). Repeating the global class gives conditional
						// fallback CSS equal specificity so resolved breakpoints and
						// preserved custom at-rules can override base controls.
						$target['selector'] = $root_selector . $selector;
					}
				}
			}

			if ( $breakpoint && ! empty( $target['atRules'] ) && is_array( $target['atRules'] ) ) {
				$target['atRules'] = array_values(
					array_filter(
						$target['atRules'],
						static function ( $at_rule ) {
							if ( ( $at_rule['name'] ?? '' ) !== 'media' ) {
								return true;
							}

							$params = trim( (string) ( $at_rule['params'] ?? '' ) );

							// Bricks already wraps breakpoint-scoped settings in the
							// matching media query. Keep compound media conditions,
							// but remove a plain max-width wrapper to avoid invalid
							// nested @media output.
							return ! preg_match( '/^\(?\s*max-width\s*:\s*\d+(?:\.\d+)?px\s*\)?$/i', $params );
						}
					)
				);
			}

			if ( ! isset( $targets_by_breakpoint[ $breakpoint ] ) ) {
				$targets_by_breakpoint[ $breakpoint ] = [];
			}

			$targets_by_breakpoint[ $breakpoint ][] = $target;
		}

		$settings = [];

		foreach ( $targets_by_breakpoint as $breakpoint => $breakpoint_targets ) {
			$css_custom = self::build_css_custom_from_targets( $breakpoint_targets );

			if ( $css_custom ) {
				$key              = $breakpoint ? "_cssCustom:{$breakpoint}" : '_cssCustom';
				$settings[ $key ] = trim( $css_custom );
			}
		}

		return $settings;
	}

	/**
	 * Build a root target from a selector and declarations.
	 *
	 * @since 2.4
	 *
	 * @param string $root_selector Root CSS selector.
	 * @param array  $declarations  CSS declarations.
	 * @return array Target array.
	 */
	public static function build_root_target( $root_selector, $declarations = [] ) {
		$normalized = self::normalize_selector( $root_selector );
		$parts      = self::strip_trailing_pseudo( $normalized );

		return [
			'selector'     => $normalized,
			'selectorBase' => $parts['base'],
			'pseudo'       => $parts['pseudo'],
			'declarations' => $declarations,
		];
	}

	/**
	 * Map CSS targets to element schema controls (public API).
	 *
	 * @since 2.4
	 *
	 * @param string     $element_name    Element name.
	 * @param string     $root_selector   Root CSS selector.
	 * @param array      $targets         CSS targets.
	 * @param array      $initial_settings Initial settings.
	 * @param array|null $effect_targets All related effect targets, including CSS fallbacks.
	 * @return array Associative array with 'settings' and 'unmappedTargets'.
	 */
	public static function map_targets_with_schema( $element_name, $root_selector, $targets = [], $initial_settings = [], $effect_targets = null ) {
		return self::map_targets_to_schema_controls(
			$element_name,
			self::normalize_selector( $root_selector ),
			$targets,
			$initial_settings,
			$effect_targets
		);
	}

	/**
	 * Map one full CSS selector target into settings for a Bricks custom selector object.
	 *
	 * @since 2.4
	 *
	 * @param string     $element_name Element schema name.
	 * @param array      $target       CSS target.
	 * @param array|null $targets Related targets for one effect selector.
	 * @return array Selector settings.
	 */
	public static function map_target_to_selector_settings( $element_name = 'div', $target = [], $targets = null ) {
		$original_selector = trim( (string) ( isset( $target['selector'] ) ? $target['selector'] : '' ) );
		$selector          = self::normalize_selector( $original_selector );

		if ( ! $selector ) {
			return [];
		}

		$targets = array_map(
			static function ( $entry ) use ( $selector ) {
				$entry['selector']     = $selector;
				$entry['selectorBase'] = $selector;
				$entry['pseudo']       = '';
				return $entry;
			},
			$targets ?? [ $target ]
		);

		$mapped = self::map_targets_with_schema(
			$element_name,
			$selector,
			$targets,
			[]
		);

		$settings            = isset( $mapped['settings'] ) ? $mapped['settings'] : [];
		$unmapped            = array_map(
			function ( $unmapped_target ) use ( $original_selector, $selector ) {
				$unmapped_target['selector'] = $original_selector ? $original_selector : $selector;
				return $unmapped_target;
			},
			isset( $mapped['unmappedTargets'] ) ? $mapped['unmappedTargets'] : []
		);
		$css_custom_settings = self::build_css_custom_settings_from_targets( $unmapped );
		$settings            = array_merge( $settings, $css_custom_settings );

		return $settings;
	}

	/**
	 * Map root declarations for a global class.
	 *
	 * Applies spacing, typography, and background-color directly to inherited controls.
	 *
	 * @since 2.4
	 *
	 * @param string $root_selector       Root selector.
	 * @param array  $base_declarations   Base declarations.
	 * @param array  $pseudo_declarations Pseudo-state declarations keyed by pseudo string.
	 * @param array  $initial_settings    Initial settings.
	 * @return array Associative array with 'settings' and 'unmappedTargets'.
	 */
	public static function map_root_declarations_for_global_class( $root_selector, $base_declarations = [], $pseudo_declarations = [], $initial_settings = [] ) {
		$settings        = $initial_settings;
		$unmapped        = [];
		$normalized_root = self::normalize_selector( $root_selector );

		$apply_root = function ( $declarations, $pseudo = '' ) use ( &$settings, &$unmapped, $normalized_root ) {
			if ( ! self::can_map_pseudo_to_native_state( $pseudo ) ) {
				$unmapped[] = [
					'selector'     => $normalized_root . $pseudo,
					'selectorBase' => $normalized_root,
					'pseudo'       => $pseudo,
					'declarations' => $declarations,
				];
				return;
			}
			$remaining = [];

			// Margin
			$margin = Html_To_Bricks_Spacing::convert_margin( $declarations );

			if ( $margin ) {
				$key              = "_margin{$pseudo}";
				$settings[ $key ] = array_merge( isset( $settings[ $key ] ) ? $settings[ $key ] : [], $margin );
			}

			// Padding
			$padding = Html_To_Bricks_Spacing::convert_padding( $declarations );

			if ( $padding ) {
				$key              = "_padding{$pseudo}";
				$settings[ $key ] = array_merge( isset( $settings[ $key ] ) ? $settings[ $key ] : [], $padding );
			}

			// Typography
			$typography = Html_To_Bricks_Typography::convert_typography( $declarations );

			if ( $typography ) {
				$key              = "_typography{$pseudo}";
				$settings[ $key ] = array_merge( isset( $settings[ $key ] ) ? $settings[ $key ] : [], $typography );
			}

			foreach ( $declarations as $property => $value ) {
				$low = strtolower( (string) $property );

				// Skip spacing properties (already handled)
				$is_spacing = $low === 'margin' || $low === 'padding' || isset( self::$spacing_side_map[ $low ] );

				// Keep unsupported typography values in custom CSS instead of
				// dropping them after the typography converter rejects them.
				$is_typography = is_array( $typography ) && array_key_exists( $low, $typography );

				if ( $is_spacing || $is_typography ) {
					continue;
				}

				if ( $low === 'background-color' ) {
					$parsed = Html_To_Bricks_Css_Value_Parsers::parse_color( $value );

					if ( $parsed ) {
						$key               = "_background{$pseudo}";
						$existing          = isset( $settings[ $key ] ) ? $settings[ $key ] : [];
						$existing['color'] = $parsed;
						$settings[ $key ]  = $existing;
						continue;
					}
				}

				$remaining[ $property ] = $value;
			}

			if ( ! empty( $remaining ) ) {
				$unmapped[] = [
					'selector'     => $normalized_root . $pseudo,
					'selectorBase' => $normalized_root,
					'pseudo'       => $pseudo,
					'declarations' => $remaining,
				];
			}
		};

		$apply_root( $base_declarations, '' );

		foreach ( $pseudo_declarations as $pseudo => $declarations ) {
			$apply_root( $declarations, $pseudo );
		}

		return [
			'settings'        => $settings,
			'unmappedTargets' => $unmapped,
		];
	}

	/**
	 * Clear the control index cache.
	 *
	 * @since 2.4
	 */
	public static function clear_cache() {
		self::$control_index_cache = [];
	}
}

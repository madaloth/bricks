<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Builder {
	public static $dynamic_data          = []; // key: DD tag; value: DD tag value
	public static $html_attributes       = []; // key: header, main, footer, element ID; value: array with element attributes
	public static $elements_html         = [];
	public static $preview_texts         = [];
	public static $looping_html          = [];
	public static $loop_visible_elements = [];
	public static $templates_data        = [];
	public static $looping_dynamic_data  = [];
	public static $query_api_cache       = []; // Cached data for query API results

	public function __construct() {
		// Builder: Add login form to page
		if ( bricks_is_builder_main() ) {
			add_action( 'wp_print_footer_scripts', 'wp_auth_check_html', 5 );
		}

		// Remove admin bar styles and disable admin bar in builder
		if ( BRICKS_DEBUG === false || bricks_is_builder_iframe() ) {
			add_action( 'wp_print_styles', [ $this, 'remove_admin_bar_inline_styles' ] );
			add_filter( 'show_admin_bar', [ $this, 'show_admin_bar' ] );
		}

		add_action( 'init', [ $this, 'set_language_direction' ] );

		// Refresh WordPress dates and built-in post type labels before the translation filter masks the current locale.
		if ( bricks_is_builder_main() ) {
			switch_to_locale( self::get_builder_locale( determine_locale() ) );
		}

		// @since 1.11: Changed from 'locale' to 'determine_locale' to avoid conflicts with other plugins (i.e. WPML)
		add_filter( 'determine_locale', [ $this, 'maybe_set_locale' ], 99999, 1 ); // Hook in after TranslatePress

		add_action( 'send_headers', [ $this, 'dont_cache_headers' ] );

		add_action( 'wp_footer', [ $this, 'element_x_templates' ] );

		// Server-rendered canvas links also need the description; Builder requests do not instantiate Frontend.
		add_action( 'bricks_body', [ Frontend::class, 'add_new_tab_link_description' ] );

		add_action( 'bricks_before_site_wrapper', [ $this, 'before_site_wrapper' ] );
		add_action( 'bricks_after_site_wrapper', [ $this, 'after_site_wrapper' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_print_styles', [ $this, 'enqueue_global_styles' ] );

		add_filter( 'tiny_mce_before_init', [ $this, 'tiny_mce_before_init' ] );

		add_action( 'template_redirect', [ $this, 'template_redirect' ] );

		// In the builder force our own template to avoid conflicts with other builders
		add_filter( 'template_include', [ $this, 'template_include' ], 1001 );

		// Skip loading Cloudflare Rocket Loader (@since 2.0)
		if ( self::cloudflare_rocket_loader_disabled() ) {
			add_action( 'template_redirect', [ $this, 'cloudflare_rocket_loader_modify_script_tags' ], 0 );
		}
	}

	public static function get_default_builder_ui() {
		return [
			'version'    => 8,
			'toolbar'    => [
				'position'       => 'top',
				'layouts'        => self::get_default_toolbar_layouts(),
				'hidden'         => [ 'insertElement', 'toolbarDivider' ],
				'insertElements' => [],
			],
			'slots'      => [
				'panel'          => 'left',
				'structure'      => 'right',
				'pinnedControls' => 'panel',
			],
			'bottomDock' => [
				'order'  => [ 'panel', 'structure', 'pinnedControls' ],
				'active' => 'panel',
				'width'  => 'full',
			],
			'panel'      => [
				'minimized'           => false,
				'pinnedControlGroups' => [],
			],
			'structure'  => [
				'minimized' => false,
			],
		];
	}

	public static function sanitize_builder_ui( $config ) {
		$raw_config  = is_array( $config ) ? $config : [];
		$defaults    = self::get_default_builder_ui();
		$config      = array_replace_recursive( $defaults, $raw_config );
		$raw_toolbar = is_array( $raw_config['toolbar'] ?? null ) ? $raw_config['toolbar'] : [];

		$bottom_modules = $defaults['bottomDock']['order'];

		$config['version'] = 8;

		$config['toolbar']['position']       = in_array( $config['toolbar']['position'], [ 'top', 'bottom', 'left', 'right' ], true ) ? $config['toolbar']['position'] : 'top';
		$config['toolbar']['hidden']         = array_key_exists( 'hidden', $raw_toolbar ) ? $raw_toolbar['hidden'] : $defaults['toolbar']['hidden'];
		$config['toolbar']['hidden']         = self::sanitize_toolbar_hidden_items( is_array( $config['toolbar']['hidden'] ) ? $config['toolbar']['hidden'] : [] );
		$config['toolbar']['insertElements'] = self::sanitize_toolbar_insert_elements( $config['toolbar']['insertElements'] ?? [] );
		$config['toolbar']['layouts']        = self::sanitize_toolbar_layouts(
			[
				'position' => $config['toolbar']['position'],
				'hidden'   => $config['toolbar']['hidden'],
				'layouts'  => $raw_toolbar['layouts'] ?? null,
				'order'    => $raw_toolbar['order'] ?? null,
			]
		);
		unset( $config['toolbar']['order'] );

		$config['slots']['panel']          = in_array( $config['slots']['panel'], [ 'left', 'right', 'bottom' ], true ) ? $config['slots']['panel'] : 'left';
		$config['slots']['structure']      = in_array( $config['slots']['structure'], [ 'left', 'right', 'bottom' ], true ) ? $config['slots']['structure'] : 'right';
		$config['slots']['pinnedControls'] = in_array( $config['slots']['pinnedControls'], [ 'panel', 'bottom' ], true ) ? $config['slots']['pinnedControls'] : 'panel';

		$config['dimensions'] = [];

		if ( isset( $raw_config['dimensions']['panelWidth'] ) ) {
			$config['dimensions']['panelWidth'] = min( 600, max( 240, (int) $raw_config['dimensions']['panelWidth'] ) );
		}

		if ( isset( $raw_config['dimensions']['structureWidth'] ) ) {
			$config['dimensions']['structureWidth'] = min( 600, max( 240, (int) $raw_config['dimensions']['structureWidth'] ) );
		}

		$config['bottomDock']['order'] = array_values(
			array_unique(
				array_filter(
					array_merge(
						is_array( $config['bottomDock']['order'] ) ? $config['bottomDock']['order'] : [],
						$bottom_modules
					),
					static function( $item ) use ( $bottom_modules ) {
						return in_array( $item, $bottom_modules, true );
					}
				)
			)
		);

		$config['bottomDock']['active'] = in_array( $config['bottomDock']['active'], $bottom_modules, true )
			? $config['bottomDock']['active']
			: $config['bottomDock']['order'][0];
		$config['bottomDock']['width']  = in_array( $config['bottomDock']['width'], [ 'full', 'contained' ], true )
			? $config['bottomDock']['width']
			: $defaults['bottomDock']['width'];

		if ( isset( $raw_config['bottomDock']['height'] ) ) {
			$config['bottomDock']['height'] = max( 160, (int) $raw_config['bottomDock']['height'] );
		} else {
			unset( $config['bottomDock']['height'] );
		}

		$bottom_dock_sizes = [];

		if ( isset( $raw_config['bottomDock']['sizes'] ) && is_array( $raw_config['bottomDock']['sizes'] ) ) {
			foreach ( $raw_config['bottomDock']['sizes'] as $module => $size ) {
				if ( in_array( $module, $bottom_modules, true ) ) {
					$bottom_dock_sizes[ $module ] = max( 160, (int) $size );
				}
			}
		}

		if ( $bottom_dock_sizes ) {
			$config['bottomDock']['sizes'] = $bottom_dock_sizes;
		} else {
			unset( $config['bottomDock']['sizes'] );
		}

		if ( isset( $raw_config['bottomDock']['layout'] ) && is_array( $raw_config['bottomDock']['layout'] ) ) {
			$bottom_dock_layout = self::sanitize_builder_ui_column_layout(
				$raw_config['bottomDock']['layout'],
				count( $bottom_modules )
			);

			if ( $bottom_dock_layout ) {
				$config['bottomDock']['layout'] = $bottom_dock_layout;
			} else {
				unset( $config['bottomDock']['layout'] );
			}
		} else {
			unset( $config['bottomDock']['layout'] );
		}

		$config['panel']['minimized']           = ! empty( $config['panel']['minimized'] );
		$config['panel']['pinnedControlGroups'] = array_values(
			array_unique(
				array_filter(
					is_array( $config['panel']['pinnedControlGroups'] ) ? array_map( 'sanitize_text_field', $config['panel']['pinnedControlGroups'] ) : []
				)
			)
		);

		if ( isset( $raw_config['panel']['pinnedSectionHeight'] ) ) {
			$pinned_section_height                  = (int) $raw_config['panel']['pinnedSectionHeight'];
			$config['panel']['pinnedSectionHeight'] = $pinned_section_height === 0 ? 0 : min( 800, max( 150, $pinned_section_height ) );
		} else {
			unset( $config['panel']['pinnedSectionHeight'] );
		}

		if ( isset( $raw_config['panel']['pinnedControlsLayout'] ) && is_array( $raw_config['panel']['pinnedControlsLayout'] ) ) {
			$pinned_controls_layout = self::sanitize_builder_ui_column_layout( $raw_config['panel']['pinnedControlsLayout'] );

			if ( $pinned_controls_layout ) {
				$config['panel']['pinnedControlsLayout'] = $pinned_controls_layout;
			} else {
				unset( $config['panel']['pinnedControlsLayout'] );
			}
		} else {
			unset( $config['panel']['pinnedControlsLayout'] );
		}

		$config['structure']['minimized'] = ! empty( $config['structure']['minimized'] );
		unset( $config['structure']['visible'] );

		return self::compact_builder_ui( $config );
	}

	/**
	 * Sanitize builder UI CSS size value.
	 *
	 * @param mixed $value CSS size value.
	 * @return string
	 */
	public static function sanitize_builder_ui_css_size( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );

		if ( ! $value || strlen( $value ) > 80 || preg_match( '/[;{}<>]/', $value ) || preg_match( '/url\s*\(/i', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitize builder interface column layout.
	 *
	 * @param mixed $layout Column layout config.
	 * @param int   $max_columns Maximum allowed columns.
	 * @return array
	 */
	public static function sanitize_builder_ui_column_layout( $layout, $max_columns = 6 ) {
		if ( ! is_array( $layout ) ) {
			return [];
		}

		$max_columns       = max( 1, (int) $max_columns );
		$sanitized_layout  = [];
		$sanitized_columns = isset( $layout['columns'] )
			? min( $max_columns, max( 1, (int) $layout['columns'] ) )
			: null;
		$columns           = $sanitized_columns ?? 1;
		$column_widths     = [];
		$raw_column_widths = isset( $layout['columnWidths'] ) && is_array( $layout['columnWidths'] )
			? $layout['columnWidths']
			: [];

		if (
			empty( $raw_column_widths ) &&
			! empty( $layout['columnWidth'] ) &&
			trim( (string) $layout['columnWidth'] ) !== '360'
		) {
			$legacy_column_width = self::sanitize_builder_ui_css_size( $layout['columnWidth'] );

			if ( $legacy_column_width ) {
				for ( $index = 0; $index < $columns; $index++ ) {
					$column_widths[ $index ] = $legacy_column_width;
				}
			}
		}

		foreach ( $raw_column_widths as $index => $width ) {
			$column_index = (int) $index;

			if ( (string) $column_index !== (string) $index || $column_index < 0 || $column_index >= $columns ) {
				continue;
			}

			$width = self::sanitize_builder_ui_css_size( $width );

			if ( $width ) {
				$column_widths[ $column_index ] = $width;
			}
		}

		if ( $sanitized_columns !== null || $column_widths ) {
			$sanitized_layout['columns'] = $sanitized_columns ?? 1;
		}

		if ( $column_widths ) {
			$sanitized_layout['columnWidths'] = $column_widths;
		}

		return $sanitized_layout;
	}

	/**
	 * Remove empty builder interface values that do not carry preference intent.
	 *
	 * @param array $config Builder interface config.
	 * @return array
	 */
	public static function compact_builder_ui( $config ) {
		if ( ! is_array( $config ) ) {
			return [];
		}

		$config = self::remove_empty_builder_ui_scalars( $config );

		if ( empty( $config['dimensions'] ) ) {
			unset( $config['dimensions'] );
		}

		if ( empty( $config['toolbar']['insertElements'] ) ) {
			unset( $config['toolbar']['insertElements'] );
		}

		if ( empty( $config['bottomDock']['sizes'] ) ) {
			unset( $config['bottomDock']['sizes'] );
		}

		if ( empty( $config['bottomDock']['layout'] ) ) {
			unset( $config['bottomDock']['layout'] );
		}

		if ( empty( $config['panel']['pinnedControlGroups'] ) ) {
			unset( $config['panel']['pinnedControlGroups'] );
		}

		if ( isset( $config['panel']['minimized'] ) && $config['panel']['minimized'] === false ) {
			unset( $config['panel']['minimized'] );
		}

		if ( isset( $config['structure']['minimized'] ) && $config['structure']['minimized'] === false ) {
			unset( $config['structure']['minimized'] );
		}

		foreach ( [ 'toolbar', 'bottomDock', 'panel', 'structure' ] as $key ) {
			if ( empty( $config[ $key ] ) ) {
				unset( $config[ $key ] );
			}
		}

		return $config;
	}

	/**
	 * Remove empty strings and null values from nested builder interface config.
	 *
	 * @param array $value Builder interface config fragment.
	 * @return array
	 */
	public static function remove_empty_builder_ui_scalars( $value ) {
		foreach ( $value as $key => $item ) {
			if ( $item === '' || $item === null ) {
				unset( $value[ $key ] );
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = self::remove_empty_builder_ui_scalars( $item );
			}
		}

		return $value;
	}

	public static function get_toolbar_items() {
		return [
			'logo',
			'styles',
			'templates',
			'pages',
			'settings',
			'commandPalette',
			'elements',
			'insertElement',
			'toolbarDivider',
			'mode',
			'reloadCanvas',
			'breakpointManager',
			'breakpointCluster',
			'undo',
			'redo',
			'publish',
			'editWordPress',
			'openFrontend',
			'preview',
			'save',
		];
	}

	public static function get_required_toolbar_items() {
		return [ 'elements', 'save' ];
	}

	public static function get_default_toolbar_groups() {
		return [
			'logo'              => 'start',
			'styles'            => 'start',
			'pages'             => 'start',
			'templates'         => 'start',
			'settings'          => 'start',
			'commandPalette'    => 'start',
			'elements'          => 'start',
			'insertElement'     => 'start',
			'toolbarDivider'    => 'start',
			'mode'              => 'center',
			'reloadCanvas'      => 'center',
			'breakpointManager' => 'center',
			'breakpointCluster' => 'center',
			'undo'              => 'end',
			'redo'              => 'end',
			'publish'           => 'end',
			'editWordPress'     => 'end',
			'openFrontend'      => 'end',
			'preview'           => 'end',
			'save'              => 'end',
		];
	}

	public static function is_insert_element_toolbar_item( $item_id ) {
		return is_string( $item_id ) && (
			$item_id === 'insertElement' ||
			strpos( $item_id, 'insertElement:' ) === 0
		);
	}

	public static function is_toolbar_divider_item( $item_id ) {
		return is_string( $item_id ) && (
			$item_id === 'toolbarDivider' ||
			strpos( $item_id, 'toolbarDivider:' ) === 0
		);
	}

	public static function is_repeatable_toolbar_item( $item_id ) {
		return (
			( self::is_insert_element_toolbar_item( $item_id ) && $item_id !== 'insertElement' ) ||
			( self::is_toolbar_divider_item( $item_id ) && $item_id !== 'toolbarDivider' )
		);
	}

	public static function get_toolbar_item_base_id( $item_id ) {
		if ( self::is_insert_element_toolbar_item( $item_id ) ) {
			return 'insertElement';
		}

		if ( self::is_toolbar_divider_item( $item_id ) ) {
			return 'toolbarDivider';
		}

		return $item_id;
	}

	public static function is_toolbar_item_allowed( $item_id ) {
		return (
			in_array( $item_id, self::get_toolbar_items(), true ) ||
			self::is_insert_element_toolbar_item( $item_id ) ||
			self::is_toolbar_divider_item( $item_id )
		);
	}

	public static function get_default_toolbar_layout( $order = [] ) {
		$toolbar_items = self::get_toolbar_items();
		$group_map     = self::get_default_toolbar_groups();
		$normalized    = [
			'start'  => [],
			'center' => [],
			'end'    => [],
		];
		$seen          = [];
		$toolbar_order = is_array( $order ) && count( $order ) ? $order : $toolbar_items;

		foreach ( $toolbar_order as $item ) {
			if ( ! in_array( $item, $toolbar_items, true ) || isset( $seen[ $item ] ) ) {
				continue;
			}

			$group                  = $group_map[ $item ] ?? 'end';
			$normalized[ $group ][] = $item;
			$seen[ $item ]          = true;
		}

		return $normalized;
	}

	public static function get_default_toolbar_layouts() {
		$layout = self::get_default_toolbar_layout();

		return [
			'top'    => $layout,
			'right'  => self::get_empty_toolbar_layout(),
			'bottom' => self::get_empty_toolbar_layout(),
			'left'   => self::get_empty_toolbar_layout(),
		];
	}

	public static function get_empty_toolbar_layout() {
		return [
			'start'  => [],
			'center' => [],
			'end'    => [],
		];
	}

	public static function sanitize_toolbar_hidden_items( $items ) {
		$toolbar_items          = self::get_toolbar_items();
		$required_toolbar_items = self::get_required_toolbar_items();
		$items                  = is_array( $items ) ? array_map( 'sanitize_text_field', $items ) : [];
		$items                  = array_map( [ __CLASS__, 'get_toolbar_item_base_id' ], $items );

		return array_values(
			array_unique(
				array_filter(
					$items,
					static function( $item ) use ( $toolbar_items, $required_toolbar_items ) {
						return in_array( $item, $toolbar_items, true ) && ! in_array( $item, $required_toolbar_items, true );
					}
				)
			)
		);
	}

	public static function sanitize_toolbar_insert_elements( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $items as $item_id => $target ) {
			$item_id = sanitize_text_field( $item_id );

			if ( ! self::is_insert_element_toolbar_item( $item_id ) || $item_id === 'insertElement' || ! is_array( $target ) ) {
				continue;
			}

			$type = sanitize_text_field( $target['type'] ?? '' );

			if ( $type === 'component' && ! empty( $target['cid'] ) ) {
				$sanitized[ $item_id ] = self::remove_empty_builder_ui_scalars(
					[
						'type'  => 'component',
						'cid'   => sanitize_text_field( $target['cid'] ),
						'name'  => sanitize_text_field( $target['name'] ?? '' ),
						'label' => sanitize_text_field( $target['label'] ?? '' ),
					]
				);
			} elseif ( $type === 'element' && ! empty( $target['name'] ) ) {
				$sanitized[ $item_id ] = self::remove_empty_builder_ui_scalars(
					[
						'type'  => 'element',
						'name'  => sanitize_text_field( $target['name'] ),
						'label' => sanitize_text_field( $target['label'] ?? '' ),
					]
				);
			}
		}

		return $sanitized;
	}

	public static function sanitize_toolbar_layouts_seed( $layouts, $hidden = [], $fallback_position = 'top' ) {
		$toolbar_items = self::get_toolbar_items();
		$group_map     = self::get_default_toolbar_groups();
		$hidden_items  = array_flip( self::sanitize_toolbar_hidden_items( $hidden ) );
		$positions     = [ 'top', 'right', 'bottom', 'left' ];
		$normalized    = [];
		$seen          = [];

		foreach ( $positions as $position ) {
			$normalized[ $position ] = self::get_empty_toolbar_layout();
		}

		foreach ( $positions as $position ) {
			foreach ( [ 'start', 'center', 'end' ] as $group ) {
				$group_items = is_array( $layouts[ $position ][ $group ] ?? null ) ? $layouts[ $position ][ $group ] : [];

				foreach ( $group_items as $item ) {
					$item               = sanitize_text_field( $item );
					$item_base_id       = self::get_toolbar_item_base_id( $item );
					$is_repeatable_item = self::is_repeatable_toolbar_item( $item );
					$seen_id            = $is_repeatable_item ? $item : $item_base_id;

					if (
						! self::is_toolbar_item_allowed( $item ) ||
						( isset( $hidden_items[ $item_base_id ] ) && ! $is_repeatable_item ) ||
						isset( $seen[ $seen_id ] )
					) {
						continue;
					}

					$normalized[ $position ][ $group ][] = $item;
					$seen[ $seen_id ]                    = true;
				}
			}
		}

		$fallback_position = in_array( $fallback_position, $positions, true ) ? $fallback_position : 'top';

		foreach ( $toolbar_items as $item ) {
			if ( isset( $hidden_items[ $item ] ) || isset( $seen[ $item ] ) ) {
				continue;
			}

			$group                                        = $group_map[ $item ] ?? 'end';
			$normalized[ $fallback_position ][ $group ][] = $item;
			$seen[ $item ]                                = true;
		}

		return $normalized;
	}

	public static function sanitize_toolbar_layouts( $toolbar ) {
		$position          = in_array( $toolbar['position'] ?? '', [ 'top', 'bottom', 'left', 'right' ], true ) ? $toolbar['position'] : 'top';
		$hidden_items      = self::sanitize_toolbar_hidden_items( $toolbar['hidden'] ?? [] );
		$toolbar_layouts   = is_array( $toolbar['layouts'] ?? null ) ? $toolbar['layouts'] : [];
		$legacy_layout     = is_array( $toolbar['order'] ?? null ) ? self::get_default_toolbar_layout( $toolbar['order'] ) : null;
		$sanitized_layouts = [];

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $toolbar_position ) {
			$sanitized_layouts[ $toolbar_position ] = self::get_empty_toolbar_layout();
			$layout                                 = $toolbar_layouts[ $toolbar_position ] ?? null;

			if ( ! is_array( $layout ) && $legacy_layout && $toolbar_position === $position ) {
				$layout = $legacy_layout;
			}

			if ( ! is_array( $layout ) && $toolbar_position === $position ) {
				$layout = self::get_default_toolbar_layout();
			}

			$sanitized_layouts[ $toolbar_position ] = is_array( $layout ) ? $layout : self::get_empty_toolbar_layout();
		}

		return self::sanitize_toolbar_layouts_seed( $sanitized_layouts, $hidden_items, $position );
	}

	public static function get_builder_ui_defaults() {
		if ( self::builder_ui_customiser_disabled() ) {
			return self::get_default_builder_ui();
		}

		return self::sanitize_builder_ui( Database::get_setting( 'builderInterfaceDefaults', [] ) );
	}

	/**
	 * Check if the builder interface customiser is disabled.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public static function builder_ui_customiser_disabled() {
		return Database::get_setting( 'builderDisableInterfaceCustomiser', false );
	}

	/**
	 * Check whether instant navigation is enabled.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	public static function instant_navigation_enabled() {
		if ( Database::get_setting( 'builderDisableInstantNavigation', false ) ) {
			return false;
		}

		return true;
	}

	public static function get_builder_ui_user( $user_id = 0 ) {
		if ( self::builder_ui_customiser_disabled() ) {
			return [];
		}

		$user_id = $user_id ?: get_current_user_id();
		$config  = get_user_meta( $user_id, BRICKS_DB_BUILDER_UI_PREFERENCES, true );

		return is_array( $config ) ? self::sanitize_builder_ui( $config ) : [];
	}

	/**
	 * Get builder interface profiles.
	 *
	 * @return array
	 */
	public static function get_builder_ui_profiles() {
		$profiles = get_option( BRICKS_DB_BUILDER_UI_PROFILES, null );

		if ( is_array( $profiles ) ) {
			return self::sanitize_builder_ui_profiles( $profiles );
		}

		$global_settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if (
			is_array( $global_settings ) &&
			array_key_exists( 'builderInterfaceProfiles', $global_settings )
		) {
			$profiles = self::sanitize_builder_ui_profiles( $global_settings['builderInterfaceProfiles'] );

			update_option( BRICKS_DB_BUILDER_UI_PROFILES, $profiles );
			self::delete_legacy_builder_ui_profiles();

			return $profiles;
		}

		return [];
	}

	/**
	 * Delete legacy builder interface profiles from global settings.
	 *
	 * @return void
	 */
	public static function delete_legacy_builder_ui_profiles() {
		$global_settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if (
			! is_array( $global_settings ) ||
			! array_key_exists( 'builderInterfaceProfiles', $global_settings )
		) {
			return;
		}

		unset( $global_settings['builderInterfaceProfiles'] );

		Database::$global_settings = $global_settings;

		update_option( BRICKS_DB_GLOBAL_SETTINGS, $global_settings );
	}

	/**
	 * Sanitize builder interface profiles.
	 *
	 * @param array $profiles Builder interface profiles.
	 * @return array
	 */
	public static function sanitize_builder_ui_profiles( $profiles ) {
		if ( ! is_array( $profiles ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $profiles as $profile_id => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$id = ! empty( $profile['id'] ) ? sanitize_key( $profile['id'] ) : sanitize_key( $profile_id );

			if ( ! $id ) {
				continue;
			}

			$label  = ! empty( $profile['label'] ) ? sanitize_text_field( $profile['label'] ) : $id;
			$config = ! empty( $profile['config'] ) && is_array( $profile['config'] ) ? $profile['config'] : [];

			$sanitized[ $id ] = [
				'id'     => $id,
				'label'  => $label,
				'config' => self::sanitize_builder_ui( $config ),
			];
		}

		return $sanitized;
	}

	/**
	 * Get builder interface profile assignments.
	 *
	 * @return array
	 */
	public static function get_builder_ui_profile_assignments() {
		$assignments = Database::get_setting( 'builderInterfaceProfileAssignments', [] );

		return self::sanitize_builder_ui_profile_assignments( $assignments );
	}

	/**
	 * Sanitize builder interface profile assignments.
	 *
	 * @param array $assignments Builder interface profile assignments.
	 * @return array
	 */
	public static function sanitize_builder_ui_profile_assignments( $assignments ) {
		$sanitized = [
			'roles' => [],
			'users' => [],
		];

		if ( ! is_array( $assignments ) ) {
			return $sanitized;
		}

		$profiles    = self::get_builder_ui_profiles();
		$profile_ids = array_keys( $profiles );
		$roles       = array_keys( wp_roles()->get_names() );

		if ( ! empty( $assignments['roles'] ) && is_array( $assignments['roles'] ) ) {
			foreach ( $assignments['roles'] as $role => $profile_id ) {
				$role       = sanitize_key( $role );
				$profile_id = sanitize_key( $profile_id );

				if ( in_array( $role, $roles, true ) && in_array( $profile_id, $profile_ids, true ) ) {
					$sanitized['roles'][ $role ] = $profile_id;
				}
			}
		}

		if ( ! empty( $assignments['users'] ) && is_array( $assignments['users'] ) ) {
			foreach ( $assignments['users'] as $user_id => $profile_id ) {
				$user_id    = absint( $user_id );
				$profile_id = sanitize_key( $profile_id );

				if ( $user_id && in_array( $profile_id, $profile_ids, true ) ) {
					$sanitized['users'][ $user_id ] = $profile_id;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Save builder interface profiles.
	 *
	 * @param array $profiles Builder interface profiles.
	 * @return bool
	 */
	public static function save_builder_ui_profiles( $profiles ) {
		self::delete_legacy_builder_ui_profiles();

		return update_option( BRICKS_DB_BUILDER_UI_PROFILES, self::sanitize_builder_ui_profiles( $profiles ) );
	}

	/**
	 * Save builder interface profile assignments.
	 *
	 * @param array $assignments Builder interface profile assignments.
	 * @return bool
	 */
	public static function save_builder_ui_profile_assignments( $assignments ) {
		$global_settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if ( ! is_array( $global_settings ) ) {
			$global_settings = [];
		}

		$global_settings['builderInterfaceProfileAssignments'] = self::sanitize_builder_ui_profile_assignments(
			$assignments
		);
		Database::$global_settings                             = $global_settings;

		return update_option( BRICKS_DB_GLOBAL_SETTINGS, $global_settings );
	}

	/**
	 * Get builder interface profile for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_builder_ui_profile_for_user( $user_id = 0 ) {
		if ( self::builder_ui_customiser_disabled() ) {
			return [];
		}

		$user_id = $user_id ? $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return [];
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return [];
		}

		$assignments = self::get_builder_ui_profile_assignments();
		$profiles    = self::get_builder_ui_profiles();
		$profile_id  = ! empty( $assignments['users'][ $user_id ] ) ? $assignments['users'][ $user_id ] : '';

		if ( ! $profile_id && ! empty( $user->roles ) ) {
			foreach ( $user->roles as $role ) {
				if ( ! empty( $assignments['roles'][ $role ] ) ) {
					$profile_id = $assignments['roles'][ $role ];
					break;
				}
			}
		}

		return $profile_id && ! empty( $profiles[ $profile_id ] ) ? $profiles[ $profile_id ] : [];
	}

	public static function merge_builder_ui_configs( $defaults, $user ) {
		$defaults = is_array( $defaults ) ? $defaults : [];
		$user     = is_array( $user ) ? $user : [];
		$merged   = array_replace_recursive( $defaults, $user );

		if ( is_array( $user['toolbar'] ?? null ) && array_key_exists( 'hidden', $user['toolbar'] ) ) {
			$merged['toolbar']['hidden'] = $user['toolbar']['hidden'];
		}

		if ( is_array( $user['toolbar'] ?? null ) && array_key_exists( 'layouts', $user['toolbar'] ) ) {
			$merged['toolbar']['layouts'] = $user['toolbar']['layouts'];
		}

		if ( is_array( $user['toolbar'] ?? null ) && array_key_exists( 'insertElements', $user['toolbar'] ) ) {
			$merged['toolbar']['insertElements'] = $user['toolbar']['insertElements'];
		}

		return $merged;
	}

	public static function get_resolved_builder_ui( $user_id = 0 ) {
		if ( self::builder_ui_customiser_disabled() ) {
			return self::get_default_builder_ui();
		}

		$defaults = self::get_builder_ui_defaults();
		$profile  = self::get_builder_ui_profile_for_user( $user_id );
		$user     = self::get_builder_ui_user( $user_id );

		$config = self::merge_builder_ui_configs( $defaults, $user );

		if ( ! empty( $profile['config'] ) ) {
			$config = self::merge_builder_ui_configs( $config, $profile['config'] );
		}

		return self::sanitize_builder_ui( $config );
	}

	/**
	 * Remove 'admin-bar' inline styles
	 *
	 * Necessary for WordPress 6.4+ as html {margin-top: 32px !important} causes gap in builder.
	 *
	 * @since 1.9.3
	 */
	public function remove_admin_bar_inline_styles() {
		// Remove 'admin-bar' inline style
		if ( wp_style_is( 'admin-bar', 'enqueued' ) ) {
			wp_style_add_data( 'admin-bar', 'after', '' );
		}
	}

	/**
	 * Don't cache headers or browser history buffer in builder
	 *
	 * To fix browser back button issue.
	 *
	 * https://developer.mozilla.org/en-US/docs/Web/HTTP/Caching
	 * https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control
	 *
	 * "at present any pages using Cache-Control: no-store will not be eligible for bfcache."
	 * - https://web.dev/bfcache/#minimize-use-of-cache-control-no-store
	 *
	 * @since 1.6.2
	 */
	public function dont_cache_headers() {
		header_remove( 'Cache-Control' );

		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' ); // HTTP 1.1
		header( 'Pragma: no-cache' ); // HTTP 1.0
		header( 'Expires: 0' ); // HTTP 1.0 proxies
	}

	/**
	 * Remove admin bar and CSS
	 *
	 * @since 1.0
	 */
	public function show_admin_bar() {
		remove_action( 'wp_head', '_admin_bar_bump_cb' );

		return false;
	}

	/**
	 * Set a different language locale in builder if user has specified a different admin language
	 *
	 * @since 1.1.2
	 */
	public function maybe_set_locale( $locale ) {
		return self::get_builder_locale( $locale );
	}

	/**
	 * Resolve the Builder language for both the main window and AJAX UI data.
	 *
	 * @param string $locale Default request locale.
	 * @return string
	 *
	 * @since 2.4
	 */
	public static function get_builder_locale( $locale ) {
		// Check for builder language
		$builder_locale = Database::get_setting( 'builderLocale', false );

		if ( $builder_locale && $builder_locale !== 'site-default' ) {
			do_action( 'bricks/builder/switch_locale', $builder_locale );
			return $builder_locale;
		}

		// Check for specific WP dashboard user language
		$user = wp_get_current_user();

		if ( ! empty( $user->locale ) ) {
			if ( $locale !== $user->locale ) {
				do_action( 'bricks/builder/switch_locale', $user->locale );
			}

			$locale = $user->locale;
		}

		return $locale;
	}

	/**
	 * Set language direction in builder (panels)
	 *
	 * Apply only to main window (toolbar & panels). Canvas should use frontend direction.
	 *
	 * @since 1.5
	 */
	public function set_language_direction() {
		// Return: Window is not main builder window
		if ( ! bricks_is_builder_main() ) {
			return;
		}

		$direction = Database::get_setting( 'builderLanguageDirection', false );

		if ( ! $direction ) {
			$builder_locale = Database::get_setting( 'builderLocale', false );

			// If builderLocale is set to "site-default", get the site's default locale
			if ( $builder_locale === 'site-default' ) {
				$builder_locale = get_locale();
			}

			// Determine if the locale is a RTL or LTR language
			// NOTE: Best not to hardcode RTL languages if possible!
			$rtl_languages = [ 'ar', 'he', 'fa', 'ur', 'yi', 'ps', 'dv', 'ckb', 'sd', 'ug' ];

			// Apply filter to allow RTL languages to be added
			$rtl_languages = apply_filters( 'bricks/rtl_languages', $rtl_languages );

			$language_code = substr( $builder_locale, 0, 2 );
			$direction     = in_array( $language_code, $rtl_languages ) ? 'rtl' : 'ltr';
		}

		global $wp_locale, $wp_styles;

		$wp_locale->text_direction = $direction;

		if ( ! is_a( $wp_styles, 'WP_Styles' ) ) {
			$wp_styles = new \WP_Styles();
		}

		$wp_styles->text_direction = $direction;
	}

	/**
	 * Canvas: Add element x-template render scripts to wp_footer
	 */
	public function element_x_templates() {
		if ( ! bricks_is_builder_iframe() ) {
			return;
		}

		foreach ( Elements::$elements as $element ) {
			echo $element['class']::render_builder();
		}
	}

	/**
	 * Before site wrapper (opening tag to render builder)
	 *
	 * @since 1.0
	 */
	public function before_site_wrapper() {
		if ( bricks_is_builder_main() ) {
			echo '<div class="brx-body main">';
			$this->render_preloader();
		} elseif ( bricks_is_builder_iframe() ) {
			echo '<div class="brx-body iframe">';
		}
	}

	/**
	 * Render the initial builder preloader before the Vue application mounts.
	 *
	 * @since 2.4
	 */
	private function render_preloader() {
		$version_parts = explode( '-', BRICKS_VERSION );

		echo '<div id="bricks-preloader" class="loading">';
		echo '<div class="bricks-loading-inner">';
		echo '<div class="bricks-logo-animated">';
		echo '<div class="cube top-left"></div>';
		echo '<div class="cube top-right"></div>';
		echo '<div class="cube bottom-left"></div>';
		echo '<div class="cube bottom-right"></div>';
		echo '</div>';
		echo '<div class="title">';
		echo '<img src="' . esc_url( BRICKS_URL_ASSETS . 'images/bricks-logo-text.svg' ) . '" alt="' . esc_attr__( 'Bricks', 'bricks' ) . '">';
		echo '<label class="version">';
		echo '<span class="number">' . esc_html( $version_parts[0] ) . '</span>';

		if ( ! empty( $version_parts[1] ) ) {
			echo '<span class="type">' . esc_html( $version_parts[1] ) . '</span>';
		}

		echo '</label>';
		echo '</div>';
		echo '<a class="sub-title" href="https://bricksbuilder.io" target="_blank" rel="noopener">bricksbuilder.io</a>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * After site wrapper (closing tag to render builder)
	 *
	 * @since 1.0
	 */
	public function after_site_wrapper() {
		if ( bricks_is_builder() ) {
			echo '</div>'; // END .brx-body
		}
	}

	/**
	 * Resolve Gutenberg global styles before the Builder prints their dependants.
	 *
	 * @since 2.4.1
	 */
	public function enqueue_global_styles() {
		if ( ! function_exists( 'wp_enqueue_global_styles' ) || ! wp_style_is( 'global-styles', 'enqueued' ) || wp_style_is( 'global-styles', 'registered' ) ) {
			return;
		}

		// WordPress defers classic-theme global styles until the footer, after the Builder prints block variations.
		// Generate the CSS outside wp_enqueue_scripts, where on-demand loading would defer it again.
		// https://core.trac.wordpress.org/ticket/64099
		wp_enqueue_global_styles();
	}

	/**
	 * Enqueue styles and scripts
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {
		// Access MediaElementsJS (element: Audio) and to get global 'wp' object to open media library (control type 'image', 'audio' etc.)
		wp_enqueue_media();

		// Order matters for CSS flexbox (enqueue builder styles before frontend styles)
		if ( bricks_is_builder() ) {
			wp_enqueue_style( 'bricks-builder', BRICKS_URL_ASSETS . 'css/builder.min.css', [], filemtime( BRICKS_PATH_ASSETS . 'css/builder.min.css' ) );

			if ( is_rtl() ) {
				wp_enqueue_style( 'bricks-builder-rtl', BRICKS_URL_ASSETS . 'css/builder-rtl.min.css', [], filemtime( BRICKS_PATH_ASSETS . 'css/builder-rtl.min.css' ) );
			}

			// Builder isotope (PopupUnsplash.vue)
			wp_enqueue_script( 'bricks-isotope' );

			// Datepicker (form & countdown)
			wp_enqueue_script( 'bricks-flatpickr' );
			wp_enqueue_style( 'bricks-flatpickr' );

			// Builder Mode "Custom": Add CSS variables as inline CSS
			$builder_mode   = Database::get_setting( 'builderMode', 'dark' );
			$builder_ui_css = Database::get_setting( 'builderModeCss', '' );

			if ( $builder_mode === 'custom' && ! empty( $builder_ui_css ) ) {
				wp_add_inline_style( 'bricks-tooltips', $builder_ui_css );
			}

			add_filter( 'mce_buttons_2', [ $this, 'add_editor_buttons' ] );
		}

		if ( bricks_is_builder_main() ) {
			// Manually enqueue dashicons for 'wp_enqueue_media' as 'get_wp_editor' prevents dashicons enqueue
			wp_enqueue_style( 'bricks-dashicons', includes_url( '/css/dashicons.min.css' ), [], null );

			wp_enqueue_script( 'bricks-builder', BRICKS_URL_ASSETS . 'js/main.min.js', [ 'bricks-scripts', 'jquery' ], filemtime( BRICKS_PATH_ASSETS . 'js/main.min.js' ), true );
		}

		// Load Adobe fonts file
		// NOTE: Enqueue in main window for Font manager (@since 2.0)
		$adobe_fonts_project_id = ! empty( Database::get_setting( 'adobeFontsProjectId' ) ) ? Database::get_setting( 'adobeFontsProjectId' ) : false;

		if ( $adobe_fonts_project_id ) {
			wp_enqueue_style( "adobe-fonts-project-id-$adobe_fonts_project_id", "https://use.typekit.net/$adobe_fonts_project_id.css" );
		}

		if ( bricks_is_builder_iframe() ) {
			// Enqueue Dashicons for ACF icon picker (@since 2.0)
			wp_enqueue_style( 'bricks-dashicons', includes_url( '/css/dashicons.min.css' ), [], null );

			wp_enqueue_script( 'bricks-countdown' );
			wp_enqueue_script( 'bricks-counter' );
			wp_enqueue_script( 'bricks-flatpickr' );
			wp_enqueue_script( 'bricks-google-maps' );
			wp_enqueue_script( 'bricks-piechart' );
			wp_enqueue_script( 'bricks-swiper' );
			wp_enqueue_script( 'bricks-typed' );
			wp_enqueue_script( 'bricks-tocbot' );

			// Form element richtext field TinyMCE 8 (@since 2.1)
			wp_enqueue_script( 'bricks-tinymce8-builder' );

			wp_enqueue_script( 'bricks-builder', BRICKS_URL_ASSETS . 'js/iframe.min.js', [ 'bricks-scripts', 'jquery' ], filemtime( BRICKS_PATH_ASSETS . 'js/iframe.min.js' ), true );
		}

		$post_id        = get_the_ID();
		$featured_image = false;

		/**
		 * Get control options to ensure filter 'bricks/setup/control_options' ran
		 *
		 * Eaxmples: 'queryTypes', custom user control options, etc.
		 *
		 * @since 1.5.5
		 */
		$control_options = Setup::get_control_options();

		// NOTE: Set post ID to posts page
		if ( is_home() ) {
			$post_id = get_option( 'page_for_posts' );
		}

		// NOTE: Undocumented
		$post_id = apply_filters( 'bricks/builder/data_post_id', $post_id );

		if ( has_post_thumbnail( $post_id ) ) {
			$featured_image = [
				'id'  => get_post_thumbnail_id(),
				'url' => get_the_post_thumbnail_url( $post_id ),
			];

			$image_sizes = array_keys( $control_options['imageSizes'] );

			foreach ( $image_sizes as $image_size ) {
				$featured_image[ $image_size ] = get_the_post_thumbnail_url( $post_id, $image_size );
			}
		}

		$builder_ui_customiser_disabled   = self::builder_ui_customiser_disabled();
		$can_customize_builder_ui         = (
			! $builder_ui_customiser_disabled &&
			Builder_Permissions::user_has_permission( 'access_builder_interface_manager' )
		);
		$can_manage_builder_ui_profiles   = (
			! $builder_ui_customiser_disabled &&
			Builder_Permissions::user_has_permission( 'manage_builder_interface_profiles' )
		);
		$can_access_builder_ui_customiser = $can_customize_builder_ui || $can_manage_builder_ui_profiles;
		$builder_ui_defaults              = $builder_ui_customiser_disabled ? self::get_default_builder_ui() : self::get_builder_ui_defaults();
		$builder_ui_user                  = $builder_ui_customiser_disabled ? [] : self::get_builder_ui_user();
		$builder_ui_resolved              = $builder_ui_customiser_disabled ? $builder_ui_defaults : self::get_resolved_builder_ui();

		wp_localize_script(
			'bricks-builder',
			'bricksData',
			[
				'loadData'                          => self::builder_data( $post_id ), // Initial data to bootstrap builder iframe
				'dynamicWrapper'                    => apply_filters( 'bricks/builder/dynamic_wrapper', [] ),

				// Bricks settings
				'classPreviewOnHover'               => Database::get_setting( 'builderClassPreviewOnHover', false ),
				'colorPreviewOnHover'               => Database::get_setting( 'builderColorPreviewOnHover', false ),
				'fontFamilyPreviewOnHover'          => Database::get_setting( 'builderFontFamilyPreviewOnHover', false ),
				'variablePreviewOnHover'            => Database::get_setting( 'builderVariablePreviewOnHover', false ),
				'applyVariablesAndCalculations'     => Database::get_setting( 'builderApplyVariablesAndCalculations', false ), // Gate Enter-time CSS variable and math normalization. (@since 2.4)
				'variableSelectFirstOnEnter'        => Database::get_setting( 'builderVariableSelectFirstOnEnter', false ), // Allow Enter to resolve exact or first visible variable suggestions. (@since 2.4)
				'classExpandSelectedOnType'         => Database::get_setting( 'builderClassExpandSelectedOnType', false ), // Allow selected class suggestions to expand while typing a suffix. (@since 2.4)
				'classAutoSelectFirst'              => Database::get_setting( 'builderClassAutoSelectFirst', false ),
				'classAutoSelectLast'               => ! Database::get_setting( 'builderDisableClassAutoSelectLast', false ),

				'customBreakpoints'                 => Database::get_setting( 'customBreakpoints', false ),
				'disableClassManager'               => Database::get_setting( 'disableClassManager', false ),
				'disableVariablesManager'           => Database::get_setting( 'disableVariablesManager', false ),
				'disableClassChaining'              => Database::get_setting( 'disableClassChaining', false ),
				'defaultTemplatesDisabled'          => Database::get_setting( 'defaultTemplatesDisabled' ),
				'generateComponentScreenshots'      => (bool) Database::get_setting( 'generateComponentScreenshots', false ),
				'generateTemplateScreenshots'       => (bool) Database::get_setting( 'generateTemplateScreenshots', false ),
				'disableGlobalClasses'              => Database::get_setting( 'builderDisableGlobalClassesInterface', false ),
				'disablePanelAutoExpand'            => Database::get_setting( 'builderDisablePanelAutoExpand', false ),
				'disableElementSpacing'             => Database::get_setting( 'disableElementSpacing', false ),
				'canvasScrollIntoView'              => Database::get_setting( 'canvasScrollIntoView', false ),
				'structureAutoSync'                 => Database::get_setting( 'structureAutoSync', false ),
				'structureDuplicateElement'         => Database::get_setting( 'structureDuplicateElement', false ),
				'structureDeleteElement'            => Database::get_setting( 'structureDeleteElement', false ),
				'structureCollapsed'                => Database::get_setting( 'structureCollapsed', false ),
				'builderElementBreadcrumbs'         => Database::get_setting( 'builderElementBreadcrumbs', false ),
				'builderDisableRestApi'             => Database::get_setting( 'builderDisableRestApi', false ),
				'instantNavigation'                 => self::instant_navigation_enabled(),
				'builderResponsiveControlIndicator' => Database::get_setting( 'builderResponsiveControlIndicator', 'any' ),
				'builderControlGroupVisibility'     => Database::get_setting( 'builderControlGroupVisibility', 'open' ),
				'builderFontFamilyControl'          => Database::get_setting( 'builderFontFamilyControl', 'all' ),
				'builderMediaPicker'                => Database::get_setting( 'builderMediaPicker', 'bricks' ),
				'mediaHealth'                       => Media_Browser_Health::get_client_config(),
				'builderWrapElement'                => Database::get_setting( 'builderWrapElement', 'block' ),
				'builderInsertElement'              => Database::get_setting( 'builderInsertElement', 'block' ),
				'builderInsertLayout'               => Database::get_setting( 'builderInsertLayout', 'block' ),
				'enableDynamicDataPreview'          => Database::get_setting( 'enableDynamicDataPreview', false ),
				'enableQueryFilters'                => Database::get_setting( 'enableQueryFilters', false ),
				'enableQueryFiltersIntegration'     => Database::get_setting( 'enableQueryFiltersIntegration', false ),
				'builderQueryMaxResults'            => Database::get_setting( 'builderQueryMaxResults', false ),
				'builderDynamicDropdownKey'         => Database::get_setting( 'builderDynamicDropdownKey', false ),
				'builderDynamicDropdownNoLabel'     => Database::get_setting( 'builderDynamicDropdownNoLabel', false ),
				'builderDynamicDropdownExpand'      => Database::get_setting( 'builderDynamicDropdownExpand', false ),
				'builderGlobalClassesSync'          => Database::get_setting( 'builderGlobalClassesSync', false ),
				'builderVariablePickerHideValue'    => Database::get_setting( 'builderVariablePickerHideValue', false ),
				'builderCodeVim'                    => Database::get_setting( 'builderCodeVim', false ),
				'disableBuilderInterfaceCustomiser' => $builder_ui_customiser_disabled,
				'builderCssSync'                    => Database::get_setting( 'builderCssSync', false ),
				'builderCloudflareRocketLoader'     => self::cloudflare_rocket_loader_disabled(),
				'bricksComponentsInBlockEditor'     => Database::get_setting( 'bricksComponentsInBlockEditor', false ),
				'autosave'                          => [
					'disabled' => Database::get_setting( 'builderAutosaveDisabled', false ),
					'interval' => Database::get_setting( 'builderAutosaveInterval', 60 ),
				],
				'toolbarLogoLink'                   => Database::get_setting( 'builderToolbarLogoLink', 'current' ),
				'toolbarLogoLinkCustom'             => Database::get_setting( 'builderToolbarLogoLinkCustom', '' ),
				'toolbarLogoLinkNewTab'             => Database::get_setting( 'builderToolbarLogoLinkNewTab', '' ),
				'mode'                              => Database::get_setting( 'builderMode', 'dark' ),
				'featuredImage'                     => $featured_image,
				'builderUiDefaults'                 => $builder_ui_defaults,
				'builderUiUser'                     => $builder_ui_user,
				'builderUiResolved'                 => $builder_ui_resolved,
				'builderUiProfiles'                 => $can_manage_builder_ui_profiles ? self::get_builder_ui_profiles() : [],
				'canCustomizeBuilderUi'             => $can_customize_builder_ui,
				'canManageBuilderUiProfiles'        => $can_manage_builder_ui_profiles,
				'canAccessBuilderUiCustomiser'      => $can_access_builder_ui_customiser,
				'scaleOff'                          => get_user_meta( get_current_user_id(), BRICKS_DB_BUILDER_SCALE_OFF, true ),
				'widthLocked'                       => get_user_meta( get_current_user_id(), BRICKS_DB_BUILDER_WIDTH_LOCKED, true ),

				'allowedHtmlTags'                   => Helpers::get_allowed_html_tags(),
				'validPseudoClasses'                => Helpers::get_valid_pseudo_classes(),
				'validPseudoElements'               => Helpers::get_valid_pseudo_elements(),
				'wp'                                => self::get_wordpress_data(),
				'academy'                           => [
					'home'           => 'https://academy.bricksbuilder.io/',
					'components'     => 'https://academy.bricksbuilder.io/builder/features/components/',
					'elementManager' => 'https://academy.bricksbuilder.io/builder/interface/editing-elements/',
					'layout'         => 'https://academy.bricksbuilder.io/builder/styling/layout/',
					'headerTemplate' => 'https://academy.bricksbuilder.io/builder/features/create-template/',
					'footerTemplate' => 'https://academy.bricksbuilder.io/builder/features/create-template/',
					'createElement'  => 'https://academy.bricksbuilder.io/developer/elements/create-your-own-elements/',
					'globalElement'  => 'https://academy.bricksbuilder.io/builder/features/global-elements/',
					'pseudoClasses'  => 'https://academy.bricksbuilder.io/builder/styling/pseudo-classes/',
					'conditions'     => 'https://academy.bricksbuilder.io/builder/features/element-conditions/',
					'interactions'   => 'https://academy.bricksbuilder.io/builder/features/interactions/',
					'popups'         => 'https://academy.bricksbuilder.io/builder/features/popup-builder/',
					'capabilities'   => 'https://academy.bricksbuilder.io/builder/interface/builder-access/',
				],

				'version'                           => BRICKS_VERSION,
				'debug'                             => isset( $_GET['debug'] ) ? sanitize_text_field( $_GET['debug'] ) : false,
				'message'                           => isset( $_GET['message'] ) ? sanitize_text_field( $_GET['message'] ) : false,
				'breakpoints'                       => Breakpoints::$breakpoints,
				'builderPreviewParam'               => BRICKS_BUILDER_IFRAME_PARAM,
				'maxUploadSize'                     => wp_max_upload_size(),
				'allowedMediaMimeTypes'             => get_allowed_mime_types(),

				'dynamicTags'                       => Integrations\Dynamic_Data\Providers::get_dynamic_tags_list(),
				'dynamicTagsQueryLoop'              => Integrations\Dynamic_Data\Providers::get_query_supported_tags_list(),
				'dynamicTagsArraySupport'           => Integrations\Dynamic_Data\Providers::get_array_supported_tags_list(),

				// URL to edit header/content/footer templates
				'editHeaderUrl'                     => ! empty( Database::$active_templates['header'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['header'] ) : '',
				'editContentUrl'                    => ! empty( Database::$active_templates['content'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['content'] ) : '',
				'editFooterUrl'                     => ! empty( Database::$active_templates['footer'] ) ? Helpers::get_builder_edit_link( Database::$active_templates['footer'] ) : '',

				// Template IDs (@since 2.2)
				'editHeaderId'                      => ! empty( Database::$active_templates['header'] ) ? Database::$active_templates['header'] : 0,
				'editContentId'                     => ! empty( Database::$active_templates['content'] ) ? Database::$active_templates['content'] : 0,
				'editFooterId'                      => ! empty( Database::$active_templates['footer'] ) ? Database::$active_templates['footer'] : 0,

				'locale'                            => get_locale(),
				'i18n'                              => self::i18n(),
				'nonce'                             => wp_create_nonce( 'bricks-nonce-builder' ),
				'ajaxUrl'                           => admin_url( 'admin-ajax.php' ),
				'restApiUrl'                        => Api::get_rest_api_url(),
				'homeUrl'                           => home_url( '/' ),
				'adminUrl'                          => admin_url(),
				'loginUrl'                          => wp_login_url(),
				'themeUrl'                          => BRICKS_URL,
				'assetsUrl'                         => BRICKS_URL_ASSETS,
				'editPostUrl'                       => get_edit_post_link( $post_id ),
				'previewUrl'                        => add_query_arg( 'bricks_preview', time(), get_the_permalink( $post_id ) ),
				'siteName'                          => get_bloginfo( 'name' ),
				'siteUrl'                           => get_site_url(),
				'settingsUrl'                       => Helpers::settings_url(),
				'elementsManagerUrl'                => Helpers::elements_manager_url(),

				'defaultImageSize'                  => 'large',
				'author'                            => get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ),
				'canManageOptions'                  => current_user_can( 'manage_options' ),
				'isTemplate'                        => get_post_type() === BRICKS_DB_TEMPLATE_SLUG,
				'isRtl'                             => is_rtl(),
				'postId'                            => $post_id,
				'postStatus'                        => get_post_status( $post_id ),
				'postType'                          => get_post_type( $post_id ),
				'postTypeUrl'                       => admin_url( 'edit.php?post_type=' ) . get_post_type( $post_id ),
				'postTypeEditUrls'                  => self::get_post_type_edit_urls(),
				'postTypesRegistered'               => Helpers::get_registered_post_types(),
				'postTypesSupported'                => Helpers::get_supported_post_types(),
				'postsPerPage'                      => get_option( 'posts_per_page' ),
				'elements'                          => Elements::$elements,
				'elementsCatFirst'                  => self::get_first_elements_category( $post_id ),
				'wpEditor'                          => $this->get_wp_editor(),
				'recaptchaIds'                      => [],
				'saveMessages'                      => $this->save_messages(),
				'builderParam'                      => BRICKS_BUILDER_PARAM,

				'animatedTypingInstances'           => [], // Necessary to destroy and then reinit TypedJS instances
				'videoInstances'                    => [], // Necessary to destroy and then reinit Plyr instances
				'splideInstances'                   => [], // Necessary to destroy and then reinit SplideJS instances
				'tocbotInstances'                   => [], // Necessary to destroy and then reinit Tocbot instances
				'swiperInstances'                   => [], // Necessary to destroy and then reinit SwiperJS instances
				'isotopeInstances'                  => [], // Necessary to destroy and then reinit Isotope instances
				'filterInstances'                   => [], // Necessary to destroy and then reinit query filter instances
				'googleMapInstances'                => [], // Necessary to destroy and then reinit Google Maps instances
				'leafletMapInstances'               => [], // Necessary to destroy and then reinit Leaflet Maps instances
				'activeFiltersCountInstances'       => [], // Necessary to destroy and then reinit query filter instances
				'choicesInstances'                  => [], // Necessary to destroy and then reinit ChoicesJS instances

				'icons'                             => self::get_icon_font_classes(),

				'controls'                          => [
					'themeStyles'  => Theme_Styles::get_controls_data(),
					'settings'     => Settings::get_controls_data(),
					'conditions'   => Conditions::get_controls_data(),
					'interactions' => Interactions::get_controls_data(),
				],

				'controlOptions'                    => $control_options, // Static data

				'themeStyles'                       => Theme_Styles::$styles,

				'remoteTemplateSettings'            => Templates::get_remote_template_settings(),
				'remoteLibrary'                     => Remote_Library::get_builder_data(),

				'template'                          => [
					'orderBy' => $control_options['templatesOrderBy'],
					'preview' => self::get_template_preview_data( $post_id ),

					'authors' => Templates::get_template_authors(),
					'bundles' => Templates::get_template_bundles(),
					'tags'    => Templates::get_template_tags(),

					'types'   => $control_options['templateTypes'],
				],

				'mailchimpLists'                    => Integrations\Form\Actions\Mailchimp::get_list_options(),
				'wooCommerceActive'                 => Woocommerce::$is_active,
				'googleFontsDisabled'               => Helpers::google_fonts_disabled(),
				'fonts'                             => self::get_fonts(),
				'themeStylesLoadingMethod'          => Database::get_setting( 'themeStylesLoadingMethod', 'specific' ), // @since 2.0
				'codeMirrorConfig'                  => apply_filters( 'bricks/builder/codemirror_config', [] ),
				'builderGlobalClassesImport'        => Database::get_setting( 'builderGlobalClassesImport' ),
				'builderHtmlCssConverter'           => Database::get_setting( 'builderHtmlCssConverter' ),
				'placeholderImage'                  => [
					'img'     => self::get_template_placeholder_image(),
					'svg'     => self::get_template_placeholder_image( true ),
					'svgPath' => self::get_template_placeholder_image( true, 'path' ),
				],
				'pasteAndImportImage'               => Database::get_setting( 'importImageOnPaste', false ),
				'adobeFontsProjectId'               => Database::get_setting( 'adobeFontsProjectId' ),
				'bricksGoogleMarkerScript'          => BRICKS_URL_ASSETS . 'js/libs/bricks-google-marker.min.js?v=' . BRICKS_VERSION, // @since 2.0
				'infoboxScript'                     => BRICKS_URL_ASSETS . 'js/libs/infobox.min.js?v=' . BRICKS_VERSION, // @since 2.0
				'markerClustererScript'             => BRICKS_URL_ASSETS . 'js/libs/markerclusterer.min.js?v=' . BRICKS_VERSION, // @since 2.0
			]
		);

		/**
		 * Deregister wp-polyfill.min.js as it is causing performance issue for Firefox browser (in WordPress 6.4+)
		 *
		 * @since 1.9.5
		 */
		if ( ! Database::get_setting( 'builderWpPolyfill', false ) && wp_script_is( 'wp-polyfill', 'registered' ) ) {
			wp_deregister_script( 'wp-polyfill' );
			wp_register_script( 'wp-polyfill', false );
		}
	}

	/**
	 * Get WordPress data for use in builder x-template (to reduce AJAX calls)
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_wordpress_data() {
		return [
			'post' => [
				'title'         => Helpers::get_the_title( get_the_ID(), false ),
				'title_context' => Helpers::get_the_title( get_the_ID(), true ),
			],
		];
	}

	/**
	 * Get post type edit screen URLs available to the current user.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	private static function get_post_type_edit_urls() {
		$edit_urls = [];

		foreach ( array_keys( Helpers::get_supported_post_types() ) as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );

			if (
				! $post_type_object ||
				! $post_type_object->show_ui ||
				! current_user_can( $post_type_object->cap->edit_posts )
			) {
				continue;
			}

			$edit_urls[ $post_type ] = add_query_arg( 'post_type', $post_type, admin_url( 'edit.php' ) );
		}

		return $edit_urls;
	}

	/**
	 * Get all fonts
	 *
	 * - Adobe fonts (@since 1.7.1)
	 * - Custom fonts
	 * - Google fonts
	 * - Standard fonts
	 *
	 * @since 1.2.1
	 *
	 * @return array
	 */
	public static function get_fonts() {
		$fonts = [];

		// Build font dropdown 'options' for ControlTypography.vue
		$options = [];

		// STEP: Adobe fonts
		$adobe_fonts = Database::$adobe_fonts;

		if ( is_array( $adobe_fonts ) && count( $adobe_fonts ) ) {
			$options['adobeFontsGroupTitle'] = 'Adobe fonts';

			foreach ( $adobe_fonts as $adobe_font ) {
				$adobe_font_family_name      = $adobe_font['name'] ?? '';
				$adobe_font_family_slug      = $adobe_font['slug'] ?? '';
				$adobe_font_family_css_names = $adobe_font['css_names'] ?? [];

				if ( ! $adobe_font_family_name ) {
					continue;
				}

				/**
				 * Segmented fonts: For legacy Adobe Fonts kits, a font may have multiple CSS names (is always an array, though) (@since 1.9.4)
				 *
				 * Example: Azo Sans (where 'slug' is 'azo-sans', but css_names[0] is 'azo-sans-web', the latter which we need).
				 *
				 * https://fonts.adobe.com/docs/api/css_names
				 */
				if ( is_array( $adobe_font_family_css_names ) && count( $adobe_font_family_css_names ) ) {
						// Concatenate CSS names
						$adobe_font_family_key = implode( ', ', $adobe_font_family_css_names );

						$options[ $adobe_font_family_key ] = $adobe_font_family_name;
				}

				// Fallack to font slug
				else {
					$options[ $adobe_font_family_slug ] = $adobe_font_family_name;
				}
			}

			if ( count( $adobe_fonts ) ) {
				$fonts['adobe'] = $adobe_fonts;
			}
		}

		// STEP: Custom fonts
		$custom_fonts = Custom_Fonts::get_custom_fonts();

		if ( $custom_fonts ) {
			$options['customFontsGroupTitle'] = esc_html__( 'Custom fonts', 'bricks' );

			foreach ( $custom_fonts as $custom_font_id => $custom_font ) {
				$options[ $custom_font_id ] = $custom_font['family'];
			}

			$fonts['custom'] = $custom_fonts;
		}

		// STEP: Google fonts (if not disabled via filter OR settings)
		if ( ! Helpers::google_fonts_disabled() ) {
			$google_fonts = self::get_google_fonts();

			$options['googleFontsGroupTitle'] = 'Google fonts';

			foreach ( $google_fonts as $google_font ) {
				$options[ $google_font['family'] ] = $google_font['family'];
			}

			$fonts['google'] = $google_fonts;
		}

		// STEP: Standard fonts
		$standard_fonts = self::get_standard_fonts();

		$options['standardFontsGroupTitle'] = esc_html__( 'Standard fonts', 'bricks' );

		foreach ( $standard_fonts as $standard_font ) {
			$options[ $standard_font ] = $standard_font;
		}

		$fonts['standard'] = $standard_fonts;

		$fonts['options'] = $options;

		return $fonts;
	}

	/**
	 * Get standard (web safe) fonts
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_standard_fonts() {
		$standard_fonts = [
			'Arial',
			'Helvetica',
			'Helvetica Neue',
			'Times New Roman',
			'Times',
			'Georgia',
			'Courier New',
		];

		return apply_filters( 'bricks/builder/standard_fonts', $standard_fonts );
	}

	/**
	 * Get Google fonts
	 *
	 * Return fonts array with 'family' & 'variants' (to update font-weight for each font in builder)
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_google_fonts() {
		/**
		 * STEP: Generate Google fonts JSON file from API response
		 *
		 * We only need the 'family' & 'variants' properties from the Google fonts API response.
		 *
		 * DEV_ONLY: Set $google_fonts_generate below to true to generate the JSON file!
		 * NOTE First get the Google fonts JSON file from the API response (https://www.googleapis.com/webfonts/v1/webfonts) and save it to the src/assets/fonts/ folder.
		 *
		 * @since 1.7.1
		 */
		$google_fonts_generate = false;

		if ( $google_fonts_generate ) {
			$google_fonts = file_get_contents(
				BRICKS_URL . 'src/assets/fonts/google-fonts.json',
				false,
				stream_context_create(
					[
						'ssl' => [
							'verify_peer'      => false,
							'verify_peer_name' => false,
						],
					]
				)
			);

			$google_fonts           = json_decode( $google_fonts, true );
			$google_fonts           = $google_fonts['items'] ?? [];
			$google_fonts_processed = [];

			foreach ( $google_fonts as $google_font ) {
				$family   = ! empty( $google_font['family'] ) ? $google_font['family'] : false;
				$variants = ! empty( $google_font['variants'] ) ? wp_json_encode( $google_font['variants'] ) : false;

				$variants = str_replace( 'regular', '400', $variants );

				if ( ! $family ) {
					continue;
				}

				$google_fonts_processed[] = [
					'family'   => $family,
					'variants' => json_decode( $variants, true ),
				];
			}

			// Encode into minified JSON format
			$json = wp_json_encode( $google_fonts_processed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			// Save the modified JSON data back to the file
			file_put_contents( BRICKS_PATH . 'src/assets/fonts/google-fonts.min.json', $json );

			return [];
		}

		// STEP: Get contents of the Google fonts JSON file
		$google_fonts = Helpers::file_get_contents( BRICKS_PATH_ASSETS . 'fonts/google-fonts.min.json' );

		// Return: Empty file OR not found
		if ( ! $google_fonts ) {
			return [];
		}

		// Decode the JSON data into a PHP object
		$google_fonts = json_decode( $google_fonts, true );

		return is_array( $google_fonts ) ? $google_fonts : [];
	}

	/**
	 * Template placeholder image (if importImages set to false)
	 *
	 * @since 1.0
	 */
	public static function get_template_placeholder_image( $is_svg = false, $format = 'url' ) {
		$image = $is_svg ? 'placeholder-svg.svg' : 'placeholder-image-800x600.jpg';

		$default_image = $format === 'path' ? BRICKS_PATH . 'assets/images/' . $image : get_template_directory_uri() . '/assets/images/' . $image;

		// @see https://article.bricksbuilder.io/article/filter-placeholder_image/ (@since 2.0)
		return apply_filters( 'bricks/placeholder_image', $default_image, $is_svg, $format );
	}

	/**
	 * Template preview data
	 *
	 * @since 1.0
	 */
	public static function get_template_preview_data( $post_id ) {
		$preview_data = [];

		// Placeholder HTML
		$placeholder = '<section class="brxe-container brxe-alert" style="cursor: pointer">';

		$placeholder .= Helpers::get_element_placeholder(
			[
				'icon-class' => 'ti-layout',
				'text'       => esc_html__( 'Click to set preview content.', 'bricks' ),
			]
		);

		$placeholder .= '</section>';

		$preview_data['placeholder'] = $placeholder;

		// Only add the preview post id if there is a preview
		if ( Helpers::is_bricks_template( $post_id ) ) {
			$preview_post_id = Helpers::get_template_preview_post_id( $post_id );

			if ( $preview_post_id ) {
				$post_id = intval( $preview_post_id );
			}

			$preview_data['postId'] = $post_id;
		}

		return $preview_data;
	}

	/**
	 * Post thumbnail data (for use in _background control)
	 *
	 * @since 1.0
	 */
	public function get_post_thumbnail() {
		return [
			'filename' => basename( get_attached_file( get_post_thumbnail_id( get_the_ID() ) ) ),
			'full'     => get_the_post_thumbnail_url( get_the_ID(), 'full' ),
			'id'       => get_post_thumbnail_id( get_the_ID() ),
			'size'     => BRICKS_DEFAULT_IMAGE_SIZE,
			'url'      => get_the_post_thumbnail_url( get_the_ID(), BRICKS_DEFAULT_IMAGE_SIZE ),
		];
	}

	/**
	 * Custom TinyMCE settings for builder
	 *
	 * @since 1.0
	 */
	public function tiny_mce_before_init( $in ) {
		// Remove certain TinyMCE plugins in builder
		$plugins = explode( ',', $in['plugins'] );
		$key     = array_search( 'fullscreen', $plugins );

		if ( isset( $plugins[ $key ] ) ) {
			unset( $plugins[ $key ] );
		}

		$in['plugins'] = join( ',', $plugins );

		return $in;
	}

	/**
	 * WordPress editor
	 *
	 * Without tag button, "Add media" button (use respective elements instead)
	 *
	 * @since 1.0
	 */
	public function get_wp_editor() {
		ob_start();

		$mce_buttons = add_filter(
			'mce_buttons',
			function( $buttons ) {
				// NOTE: Show all editor controls @since 1.3.6 ("Basic Text" element)
				// Remove formatselect button (paragraph/heading/preformatted etc.)
				// $buttons_to_remove = [ 'formatselect' ];

				// foreach ( $buttons as $index => $button_name ) {
				// if ( in_array( $button_name, $buttons_to_remove ) ) {
				// unset( $buttons[ $index ] );
				// }
				// }

				// Add dynamic tag picker dropdown button to tinyMCE editor
				$buttons[] = 'tagPickerButton';

				return $buttons;
			}
		);

		$content   = '%%BRICKS_EDITOR_CONTENT_PLACEHOLDER%%';
		$editor_id = 'brickswpeditor'; // No dashes, see https://codex.wordpress.org/Function_Reference/wp_editor
		$settings  = [
			'editor_class' => 'bricks-wp-editor',
			// 'media_buttons' => false, // Use image element instead
			'quicktags'    => [
				'buttons' => 'sup',
			// 'buttons' => 'strong,em,ul,ol,li,link,close', // No spaces
			],
		];

		wp_editor( $content, $editor_id, $settings );

		return ob_get_clean();
	}

	/**
	 * Add 'superscript' & 'subscript' button to TinyMCE in builder
	 *
	 * @since 1.4
	 */
	public function add_editor_buttons( $buttons ) {
		if ( ! in_array( 'superscript', $buttons ) ) {
			$buttons[] = 'superscript';
		}

		if ( ! in_array( 'subscript', $buttons ) ) {
			$buttons[] = 'subscript';
		}

		return $buttons;
	}

	/**
	 * Builder strings
	 *
	 * @since 1.0
	 */
	public static function i18n() {
		$i18n = I18n::get_all_i18n();

		return apply_filters( 'bricks/builder/i18n', $i18n );
	}

	/**
	 * Custom save messages
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function save_messages() {
		$messages = [
			esc_html__( 'All right', 'bricks' ),
			esc_html__( 'Amazing', 'bricks' ),
			esc_html__( 'Aye', 'bricks' ),
			esc_html__( 'Beautiful', 'bricks' ),
			esc_html__( 'Brilliant', 'bricks' ),
			esc_html__( 'Champ', 'bricks' ),
			esc_html__( 'Cool', 'bricks' ),
			esc_html__( 'Congrats', 'bricks' ),
			esc_html__( 'Done', 'bricks' ),
			esc_html__( 'Excellent', 'bricks' ),
			esc_html__( 'Exceptional', 'bricks' ),
			esc_html__( 'Exquisite', 'bricks' ),
			esc_html__( 'Enjoy', 'bricks' ),
			esc_html__( 'Fantastic', 'bricks' ),
			esc_html__( 'Fine', 'bricks' ),
			esc_html__( 'Good', 'bricks' ),
			esc_html__( 'Grand', 'bricks' ),
			esc_html__( 'Impressive', 'bricks' ),
			esc_html__( 'Incredible', 'bricks' ),
			esc_html__( 'Magnificent', 'bricks' ),
			esc_html__( 'Marvelous', 'bricks' ),
			esc_html__( 'Neat', 'bricks' ),
			esc_html__( 'Nice job', 'bricks' ),
			esc_html__( 'Okay', 'bricks' ),
			esc_html__( 'Outstanding', 'bricks' ),
			esc_html__( 'Remarkable', 'bricks' ),
			esc_html__( 'Saved', 'bricks' ),
			esc_html__( 'Skillful', 'bricks' ),
			esc_html__( 'Stunning', 'bricks' ),
			esc_html__( 'Superb', 'bricks' ),
			esc_html__( 'Sure thing', 'bricks' ),
			esc_html__( 'Sweet', 'bricks' ),
			esc_html_x( 'Top', 'save confirmation', 'bricks' ),
			esc_html__( 'Very well', 'bricks' ),
			esc_html__( 'Woohoo', 'bricks' ),
			esc_html__( 'Wonderful', 'bricks' ),
			esc_html__( 'Yeah', 'bricks' ),
			esc_html__( 'Yep', 'bricks' ),
			esc_html__( 'Yes', 'bricks' ),
		];

		$messages = apply_filters( 'bricks/builder/save_messages', $messages );

		return $messages;
	}

	/**
	 * Get icon font classes
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public static function get_icon_font_classes() {
		return [
			'close'         => 'ion-md-close',
			'undo'          => 'ion-ios-undo',
			'redo'          => 'ion-ios-redo',

			'arrowRight'    => 'ion-ios-arrow-forward',
			'arrowDown'     => 'ion-ios-arrow-down',
			'arrowLeft'     => 'ion-ios-arrow-back',
			'arrowUp'       => 'ion-ios-arrow-up',

			'preview'       => 'ion-ios-eye',
			'settings'      => 'ion-md-settings',
			'structure'     => 'ion-ios-albums',

			'publish'       => 'ion-ios-power',
			'templates'     => 'ion-ios-folder-open',
			'page'          => 'ion-md-document',

			'desktop'       => 'ion-md-desktop',
			'mobile'        => 'ion-md-phone-portrait',
			'globe'         => 'ion-md-globe',
			'documentation' => 'ion-ios-help-buoy',
			'panelMaximize' => 'ion-ios-qr-scanner',
			'panelMinimize' => 'ion-ios-qr-scanner',

			'add'           => 'ion-md-add',
			'addTi'         => 'ti-plus',
			'remove'        => 'ion-md-remove',
			'edit'          => 'ion-md-create',
			'clone'         => 'ion-ios-copy',
			'move'          => 'ion-md-move',
			'save'          => 'ion-md-save',
			'check'         => 'ion-md-checkmark',
			'trash'         => 'ion-md-trash',
			'trashTi'       => 'ti-trash',
			'newTab'        => 'ti-new-window',

			'brush'         => 'ion-md-brush',
			'image'         => 'ion-ios-image',
			'video'         => 'ion-md-videocam',
			'cssFilter'     => 'ion-md-color-filter',

			'faceSad'       => 'ti-face-sad',
			'heart'         => 'ion-md-heart',
			'refresh'       => 'ti-reload',
			'help'          => 'ti-help-alt',
			'helpIon'       => 'ion-md-help-circle',
			'hover'         => 'ti-hand-point-up',
			'more'          => 'ti-more-alt',
			'notifications' => 'ti-bell',
			'revisions'     => 'ion-md-time',
			'link'          => 'ion-ios-link',
			'docs'          => 'ti-agenda',
			'email'         => 'ion-ios-mail',

			'search'        => 'ti-search',
			'wordpress'     => 'ti-wordpress',

			'import'        => 'ti-import',
			'export'        => 'ti-export',
			'download'      => 'ti-download',
			'zoomIn'        => 'ti-zoom-in',
		];
	}

	/**
	 * Based on post_type or template type select the first elements category to show up on builder.
	 */
	public static function get_first_elements_category( $post_id = 0 ) {
		$post_type = get_post_type( $post_id );

		// NOTE: Undocumented
		$category = apply_filters( 'bricks/builder/first_element_category', false, $post_id, $post_type );

		if ( $category ) {
			return $category;
		}

		if ( 'page' !== $post_type ) {
			return 'single';
		}

		return '';
	}

	/**
	 * Check permissions for a certain user to access the Bricks builder
	 *
	 * @since 1.0
	 */
	public function template_redirect() {
		// Redirect non-logged-in visitors to home page
		if ( ! is_user_logged_in() ) {
			wp_redirect( home_url() );
			die;
		}

		// STEP: Return if license in not valid
		$license_is_valid = License::license_is_valid();

		if ( ! $license_is_valid ) {
			wp_redirect( admin_url( 'admin.php?page=bricks-license' ) );
		}

		// STEP: Return if current user can not edit this post
		$post_id = is_single() ? get_the_ID() : 0;
		if ( ! Capabilities::current_user_can_use_builder( $post_id ) ) {
			// Redirect users without builder capabilities back to WordPress admin area
			wp_redirect( admin_url( '/?action=edit&bricks_notice=error_role_manager' ) );
			die();
		}

		// NOTE: Don't check for template
		if ( is_home() || ( function_exists( 'is_shop' ) && is_shop() ) ) {
			return;
		}

		// STEP: Return if post type is not supported for editing with Bricks
		$current_post_type = get_post_type();

		$supported_post_types = Database::get_setting( 'postTypes', [] );

		// Bricks templates always have builder support
		if ( $current_post_type === BRICKS_DB_TEMPLATE_SLUG ) {
			$supported_post_types[] = BRICKS_DB_TEMPLATE_SLUG;
		}

		// NOTE: Undocumented
		$supported_post_types = apply_filters( 'bricks/builder/supported_post_types', $supported_post_types, $current_post_type );

		if ( ! in_array( $current_post_type, $supported_post_types ) ) {
			wp_redirect( admin_url( "/edit.php?post_type={$current_post_type}&bricks_notice=error_post_type" ) );
		}
	}

	/**
	 * Prepare trash items for builder load data.
	 *
	 * @param array $trash_items
	 * @return array
	 * @since 2.4
	 */
	private static function prepare_trash_items_for_builder( $trash_items ) {
		if ( empty( $trash_items ) || ! is_array( $trash_items ) ) {
			return [];
		}

		$current_user_id = get_current_user_id();

		foreach ( $trash_items as $key => $trash_item ) {
			if ( ! is_array( $trash_item ) ) {
				unset( $trash_items[ $key ] );
				continue;
			}

			$trashed_by_user_id = ! empty( $trash_item['user_id'] ) ? (int) $trash_item['user_id'] : 0;

			if ( $trashed_by_user_id && $trashed_by_user_id !== $current_user_id ) {
				$user = get_user_by( 'id', $trashed_by_user_id );

				if ( $user ) {
					$trash_items[ $key ]['deletedBy'] = $user->display_name;
				}
			}
		}

		return array_values( $trash_items );
	}

	/**
	 * Load editable content or the surrounding content preview when editing a header/footer.
	 *
	 * Empty editable content is valid and must not fall back to the preview post's elements.
	 * Both Builder loaders use this policy so instant navigation preserves the same ownership.
	 *
	 * @param int   $post_id Post being edited.
	 * @param array $content Existing content elements belonging to that post.
	 * @return array Content elements and optional WordPress blocks for the same source post.
	 * @see #86c43x6k5
	 * @since 2.4.4
	 */
	private static function get_content_data( $post_id, $content ) {
		$data = [ 'content' => $content ];

		if ( ! empty( $content ) ) {
			return $data;
		}

		$content_post_id = $post_id;
		$template_type   = Templates::get_template_type( $post_id );

		// Only headers and footers have a separate, non-editable content area for the preview post.
		if ( in_array( $template_type, [ 'header', 'footer' ], true ) ) {
			$preview_post_id = Helpers::get_template_preview_post_id( $post_id );

			if ( $preview_post_id ) {
				$content_post_id = $preview_post_id;
				$data['content'] = Database::get_data( $content_post_id, 'content' );
			}
		}

		// Block conversion must use the content owner, not the post supplying dynamic-data context.
		if ( empty( $data['content'] ) && Database::get_setting( 'wp_to_bricks' ) ) {
			$converter = new Blocks();
			$content   = $converter->convert_blocks_to_bricks( $content_post_id );

			if ( is_array( $content ) ) {
				$data['content'] = $content;

				// Keep development diagnostics tied to the converted source, which may differ from the active template.
				$post           = get_post( $content_post_id );
				$data['blocks'] = $post ? parse_blocks( $post->post_content ) : [];
			}
		}

		return $data;
	}

	/**
	 * Get page data for builder
	 *
	 * @since 1.0
	 * @since 2.4.4 Keep editable template content separate from the active preview context (#86c43x6k5).
	 *
	 * @return array
	 */
	public static function builder_data( $post_id ) {
		$global_data              = Database::$global_data;
		$page_data                = Database::$page_data;
		$theme_styles             = Theme_Styles::$styles;
		$theme_style_active_ids   = array_keys( Theme_Styles::$settings_by_id );
		$theme_style_active_id    = end( $theme_style_active_ids ); // Get most specific theme style (@since 2.0)
		$template_settings        = Helpers::get_template_settings( $post_id );
		$template_type            = Templates::get_template_type( $post_id );
		$template_preview_post_id = Helpers::get_template_preview_post_id( $post_id );
		$iframe_body_classes_id   = self::get_iframe_body_classes_target_id( $post_id, $template_preview_post_id );

		$load_data = [
			'breakpoints'                       => Breakpoints::$breakpoints,
			'stateGroupDefinitions'             => Woocommerce::get_builder_state_group_definitions(), // @since 2.4
			// Expose only the beta data Vue still needs while the feature is dormant.
			'advancedModularElementsEnabled'    => Woocommerce::use_advanced_modular_elements(), // @since 2.4
			'advancedModularStateElements'      => Woocommerce::get_advanced_modular_state_elements(), // @since 2.4
			'wooContextualSpacingContentRoots'  => Woocommerce::get_contextual_spacing_content_roots(),
			'wooContextualSpacingExclusion'     => Woocommerce::get_contextual_spacing_managed_exclusion(),
			'advancedModularDynamicTags'        => Woocommerce::get_advanced_modular_dynamic_tags(), // @since 2.4
			'wooPage'                           => Woocommerce::get_builder_woo_page( $post_id ), // @since 2.4
			'iframeBodyClasses'                 => self::get_iframe_body_classes( $iframe_body_classes_id ), // @since 2.4
			'permissions'                       => Builder_Permissions::get_current_user_permissions(), // @since 2.0
			'breakpointActive'                  => Breakpoints::$base_key,
			'themeStyles'                       => $theme_styles,
			'themeStyleActiveIds'               => $theme_style_active_ids,
			'themeStyleActiveId'                => $theme_style_active_id,
			'pinnedElements'                    => get_option( BRICKS_DB_PINNED_ELEMENTS, [] ),
			'elementManager'                    => Elements::$manager,
			'codeExecutionEnabled'              => Helpers::code_execution_enabled(),
			'currentUserId'                     => get_current_user_id(),
			'queryMaxResults'                   => self::get_query_max_results(),
			'rememberSpacingLinkState'          => Database::get_setting( 'builderRememberSpacingLinkState' ), // @since 2.2
			'builderDisablePinnedControlGroups' => Database::get_setting( 'builderDisablePinnedControlGroups', false ), // @since 2.3
			'userCan'                           => [
				'unfilteredHtml' => current_user_can( 'unfiltered_html' ),
				'executeCode'    => Capabilities::current_user_can_execute_code(),
				'uploadFiles'    => current_user_can( 'upload_files' ),
				'uploadSvg'      => Capabilities::current_user_can_upload_svg(),
				'editPosts'      => current_user_can( 'edit_posts' ), // User can create/edit posts (@since 2.0)
				'publishPosts'   => current_user_can( 'publish_posts' ),
				'publishPages'   => current_user_can( 'publish_pages' ),
			],
			'blockCategories'                   => function_exists( 'get_block_categories' ) ? get_block_categories( null ) : [],
		];

		// Components
		if ( ! empty( $global_data['components'] ) ) {
			// STEP: Upgrade components to use latest data structure and add to load_data
			$load_data['components'] = Components::upgrade_components( $global_data['components'], false );
		}

		// Add color palettes to load_data
		if ( ! empty( $global_data['colorPalette'] ) && is_array( $global_data['colorPalette'] ) ) {
			$load_data['colorPalette'] = $global_data['colorPalette'];
		}

		// Add styleManager settings to load_data (@since 2.2)
		if ( ! empty( $global_data['styleManager'] ) ) {
			$load_data['styleManager'] = $global_data['styleManager'];
		}

		// Set light/dark mode based on styleManager settings (@since 2.2)
		$mode              = ! empty( $load_data['styleManager']['defaultMode'] ) ? $load_data['styleManager']['defaultMode'] : 'light';
		$load_data['mode'] = $mode;

		// Add global queries to load_data (@since 2.1)
		if ( ! empty( $global_data['globalQueries'] ) && is_array( $global_data['globalQueries'] ) ) {
			$load_data['globalQueries'] = $global_data['globalQueries'];
		}

		// Add global queries categories to load_data (@since 2.1)
		if ( ! empty( $global_data['globalQueriesCategories'] ) && is_array( $global_data['globalQueriesCategories'] ) ) {
			$load_data['globalQueriesCategories'] = $global_data['globalQueriesCategories'];
		}

		// Add font favorites to load_data
		if ( ! empty( $global_data['fontFavorites'] ) ) {
			$load_data['fontFavorites'] = $global_data['fontFavorites'];
		}

		// A local palette must never replace variables that another site may still use.
		$load_data['canReconcilePaletteVariables'] = ! is_multisite() || ( ! BRICKS_MULTISITE_USE_MAIN_SITE_VARIABLES && ! BRICKS_MULTISITE_USE_MAIN_SITE_COLOR_PALETTE );

		// Add global variables (@since 1.9.8)
		if ( ! empty( $global_data['globalVariables'] ) ) {
			$load_data['globalVariables'] = $global_data['globalVariables'];
		}

		// Add global variables trash (@since 2.4)
		if ( ! empty( $global_data['globalVariablesTrash'] ) ) {
			$load_data['globalVariablesTrash'] = self::prepare_trash_items_for_builder( $global_data['globalVariablesTrash'] );
		}

		// Add icon sets (@since 2.0)
		if ( ! empty( $global_data['iconSets'] ) ) {
			$load_data['iconSets'] = $global_data['iconSets'];
		}

		if ( ! empty( $global_data['customIcons'] ) ) {
			$load_data['customIcons'] = $global_data['customIcons'];
		}

		if ( ! empty( $global_data['disabledIconSets'] ) ) {
			$load_data['disabledIconSets'] = $global_data['disabledIconSets'];
		}

		// Add global variables categories (@since 1.9.8)
		if ( ! empty( $global_data['globalVariablesCategories'] ) ) {
			$load_data['globalVariablesCategories'] = $global_data['globalVariablesCategories'];
		}

		// Add global classes
		if ( ! empty( $global_data['globalClasses'] ) ) {
			$load_data['globalClasses'] = $global_data['globalClasses'];
		}

		// Add global classes trash (@since 1.11)
		if ( ! empty( $global_data['globalClassesTrash'] ) ) {
			$load_data['globalClassesTrash'] = self::prepare_trash_items_for_builder( $global_data['globalClassesTrash'] );
		}

		// Add global classes categories
		if ( ! empty( $global_data['globalClassesCategories'] ) ) {
			$load_data['globalClassesCategories'] = $global_data['globalClassesCategories'];
		}

		// Add global classes locked
		if ( ! empty( $global_data['globalClassesLocked'] ) ) {
			$load_data['globalClassesLocked'] = $global_data['globalClassesLocked'];
		}

		// Add global classes timestamp (@since 1.9.8)
		if ( ! empty( $global_data['globalClassesTimestamp'] ) ) {
			$load_data['globalClassesTimestamp'] = $global_data['globalClassesTimestamp'];
		}

		// Add global classes user (@since 1.9.8)
		if ( ! empty( $global_data['globalClassesUser'] ) ) {
			$load_data['globalClassesUser'] = $global_data['globalClassesUser'];
		}

		// Add pseudo classes
		if ( ! empty( $global_data['pseudoClasses'] ) ) {
			$load_data['pseudoClasses'] = $global_data['pseudoClasses'];
		} else {
			$load_data['pseudoClasses'] = [
				':hover',
				':active',
				':focus',
			];
		}

		// Add elements & global settings
		if ( ! empty( $global_data['elements'] ) ) {
			$load_data['globalElements'] = $global_data['elements'];
		}

		if ( ! empty( $global_data['settings'] ) ) {
			$load_data['globalSettings'] = $global_data['settings'];
		}

		// Add page data to load_data
		if ( ! empty( $page_data['header'] ) ) {
			$load_data['header'] = $page_data['header'];
		} else {
			// Check for header template
			$template_header_id = Database::$active_templates['header'];
			$header_template    = $template_header_id ? Database::get_data( $template_header_id, 'header' ) : Database::get_data( $post_id, 'header' );

			if ( ! empty( $header_template ) ) {
				$load_data['header'] = $header_template;

				// Sticky header, header postition etc.
				$load_data['templateHeaderSettings'] = Helpers::get_template_settings( $template_header_id );
			}
		}

		// Content
		$template_content_id = Database::$active_templates['content'];

		$load_data = array_merge( $load_data, self::get_content_data( $post_id, $page_data['content'] ) );

		// Add template preview logic for single templates (@since 1.12)
		if ( $template_content_id && $template_content_id != $post_id ) {
			// Load template content for static area
			$template_content = Database::get_data( $template_content_id, 'content' );

			if ( ! empty( $template_content ) ) {
				$load_data['staticContent'] = $template_content;

				// Add template page settings to generate CSS for static content (@since 1.12)
				$template_page_settings = get_post_meta( $template_content_id, BRICKS_DB_PAGE_SETTINGS, true );

				if ( ! empty( $template_page_settings ) ) {
					$load_data['outerPostContentTemplatePageSettings'] = $template_page_settings;
				}
			}
		}

		// Footer
		if ( ! empty( $page_data['footer'] ) ) {
			$load_data['footer'] = $page_data['footer'];
		} else {
			$template_footer_id = Database::$active_templates['footer'];

			// Check for footer template
			$footer_template = $template_footer_id ? Database::get_data( $template_footer_id, 'footer' ) : [];

			if ( ! empty( $footer_template ) ) {
				$load_data['footer'] = $footer_template;
			}
		}

		if ( ! empty( $page_data['settings'] ) ) {
			$load_data['pageSettings'] = $page_data['settings'];
		}

		// @since 1.7.1 - Default template type is 'content' (so listenHistory in builder can work properly)
		$load_data['templateType'] = ! empty( $template_type ) ? $template_type : 'content';

		// Template settings
		if ( $template_settings ) {
			$load_data['templateSettings'] = $template_settings;
		}

		// Parse elements to replace dynamic data (needed for background image)
		$template_preview_post_id = $template_preview_post_id ? $template_preview_post_id : $post_id;

		if ( $template_type !== 'header' && ! empty( $load_data['header'] ) && is_array( $load_data['header'] ) ) {
			$load_data['header'] = self::render_dynamic_data_on_elements( $load_data['header'], $template_preview_post_id );
		}

		if ( ! empty( $load_data['content'] ) && is_array( $load_data['content'] ) ) {
			$load_data['content'] = self::render_dynamic_data_on_elements( $load_data['content'], $template_preview_post_id );
		}

		if ( $template_type !== 'footer' && ! empty( $load_data['footer'] ) && is_array( $load_data['footer'] ) ) {
			$load_data['footer'] = self::render_dynamic_data_on_elements( $load_data['footer'], $template_preview_post_id );
		}

		/**
		 * Generate element HTML strings in PHP for fast initial render
		 *
		 * Individual element HTML AJAX calls in builder are too slow.
		 * Only load for dynamic area, but not static areas.
		 */
		$load_data['elementsHtml'] = [];

		// Remove setting in builder to get 'elementsHtml' with element ID for all PHP elements (@since 1.7)
		unset( Database::$global_settings['elementAttsAsNeeded'] );

		// New rendering mode: Collect HTML strings for all elements start (@since 2.0)
		add_filter( 'bricks/frontend/render_element', [ __CLASS__, 'collect_elements_html' ], 10, 2 );
		add_filter( 'bricks/frontend/render_loop', [ __CLASS__, 'collect_looping_html' ], 10, 3 );
		add_action( 'bricks/query/query_api_response', [ __CLASS__, 'collect_query_api_results' ], 10, 2 );
		add_action( 'bricks/dynamic_data/tag_value_parsed', [ __CLASS__, 'collect_looping_dynamic_data' ], 10, 7 ); // (#86c4tzdxq; @since 2.2)

		// Header
		if ( $template_type === 'header' && isset( $load_data['header'] ) && is_array( $load_data['header'] ) ) {
			Frontend::render_data( $load_data['header'], 'header' );
		}

		// Content
		if ( ! in_array( $template_type, [ 'header', 'footer' ] ) && isset( $load_data['content'] ) && is_array( $load_data['content'] ) ) {
			Frontend::render_data( $load_data['content'], 'content' );
		}

		// Footer
		if ( $template_type === 'footer' && isset( $load_data['footer'] ) && is_array( $load_data['footer'] ) ) {
			Frontend::render_data( $load_data['footer'], 'footer' );
		}

		remove_filter( 'bricks/frontend/render_loop', [ __CLASS__, 'collect_looping_html' ], 10, 3 );
		// Collect HTML strings for all elements end (@since 2.0)
		remove_filter( 'bricks/frontend/render_element', [ __CLASS__, 'collect_elements_html' ], 10, 2 );
		remove_action( 'bricks/query/query_api_response', [ __CLASS__, 'collect_query_api_results' ], 10, 2 );
		remove_action( 'bricks/dynamic_data/tag_value_parsed', [ __CLASS__, 'collect_looping_dynamic_data' ], 10, 7 ); // (#86c4tzdxq; @since 2.2)

		// Set collected HTML strings for all elements for fast initial render, reduce API calls (@since 2.0)
		$load_data['elementsHtml']        = self::$elements_html;
		$load_data['previewTexts']        = self::$preview_texts;
		$load_data['loopingHtml']         = self::$looping_html;
		$load_data['loopVisibleElements'] = self::$loop_visible_elements; // For ACF flexible content preview (@since 2.4)
		$load_data['templatesData']       = self::$templates_data;
		$load_data['queryApiCache']       = self::$query_api_cache;

		/**
		 * STEP: Pre-populate dynamic data to minimize AJAX requests on builder load
		 *
		 * Only if Bricks builder setting 'enableDynamicDataPreview' is enabled, we pre-populate.
		 *
		 * @see render_dynamic_data_on_elements
		 *
		 * @since 1.7.1
		 */
		if ( Database::get_setting( 'enableDynamicDataPreview', false ) && is_array( self::$dynamic_data ) && count( self::$dynamic_data ) ) {
			$load_data['dynamicData']        = self::$dynamic_data;
			$load_data['loopingDynamicData'] = self::$looping_dynamic_data;
		}

		/**
		 * STEP: Code signatures validation for builder unsignedCodeIds
		 *
		 * @since 2.0
		 */
		$load_data['invalidCodeSignatures'] = self::get_invalid_code_signatures( $load_data );

		/**
		 * STEP: Add custom attributes to builder
		 *
		 * Keys: 'header', 'main', 'footer', or individual Bricks element ID
		 *
		 * @since 1.10
		 */
		$load_data['htmlAttributes'] = self::$html_attributes;

		$load_data['htmlAttributes']['header']  = apply_filters( 'bricks/header/attributes', [] );
		$load_data['htmlAttributes']['content'] = apply_filters( 'bricks/content/attributes', [] );
		$load_data['htmlAttributes']['footer']  = apply_filters( 'bricks/footer/attributes', [] );

		return $load_data;
	}

	/**
	 * Get target post ID for builder iframe body classes.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Template or page post ID.
	 * @param int $template_preview_post_id Template preview post ID.
	 *
	 * @return int
	 */
	private static function get_iframe_body_classes_target_id( $post_id, $template_preview_post_id = 0 ) {
		if ( get_post_type( $post_id ) !== BRICKS_DB_TEMPLATE_SLUG ) {
			return $post_id;
		}

		$template_type = (string) Templates::get_template_type( $post_id );

		if ( strpos( $template_type, 'wc_' ) === 0 ) {
			return $post_id;
		}

		return $template_preview_post_id ? $template_preview_post_id : $post_id;
	}

	/**
	 * Get body classes for the builder iframe target post.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array
	 */
	public static function get_iframe_body_classes( $post_id = 0 ) {
		$target_post = get_post( $post_id );

		if ( ! $target_post ) {
			return [];
		}

		$global_keys = [
			'post',
			'wp_query',
			'wp_the_query',
			'id',
			'authordata',
			'currentday',
			'currentmonth',
			'page',
			'pages',
			'multipage',
			'more',
			'numpages',
		];

		$stored_globals = [];
		$iframe_param   = BRICKS_BUILDER_IFRAME_PARAM;
		$page_type_set  = isset( Database::$page_data['current_page_type'] );
		$page_type      = $page_type_set ? Database::$page_data['current_page_type'] : null;
		$is_woocommerce = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Temporary builder iframe flag only.
		$iframe_exists = isset( $_GET[ $iframe_param ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Temporary builder iframe flag only.
		$iframe_value = $_GET[ $iframe_param ] ?? null;

		foreach ( $global_keys as $global_key ) {
			$stored_globals[ $global_key ] = [
				'exists' => array_key_exists( $global_key, $GLOBALS ),
				'value'  => $GLOBALS[ $global_key ] ?? null,
			];
		}

		try {
			$query = new \WP_Query();
			$query->init();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Temporary builder iframe flag only.
			$_GET[ $iframe_param ] = 1;

			$query->post              = $target_post;
			$query->posts             = [ $target_post ];
			$query->post_count        = 1;
			$query->found_posts       = 1;
			$query->queried_object    = $target_post;
			$query->queried_object_id = $target_post->ID;
			$query->is_singular       = true;
			$query->is_page           = $target_post->post_type === 'page';
			$query->is_attachment     = $target_post->post_type === 'attachment';
			$query->is_single         = ! $query->is_page && ! $query->is_attachment;

			$query->set( 'p', $target_post->ID );
			$query->set( 'post_type', $target_post->post_type );

			if ( $query->is_page ) {
				$query->set( 'page_id', $target_post->ID );
			}

			$GLOBALS['wp_query']     = $query;
			$GLOBALS['wp_the_query'] = $query;
			$GLOBALS['post']         = $target_post;

			setup_postdata( $target_post );

			Database::$page_data['current_page_type'] = 'post';

			$template_type  = $target_post->post_type === BRICKS_DB_TEMPLATE_SLUG
				? (string) Templates::get_template_type( $target_post->ID )
				: '';
			$is_woocommerce = strpos( $template_type, 'wc_' ) !== false;

			if ( $is_woocommerce ) {
				add_filter( 'is_woocommerce', '__return_true' );
			}

			$classes = get_body_class();

			$frontend_only_classes = [
				'brx-body',
				'bricks-is-frontend',
				'wp-embed-responsive',
			];

			return array_values( array_unique( array_diff( $classes, $frontend_only_classes ) ) );
		} finally {
			if ( $is_woocommerce ) {
				remove_filter( 'is_woocommerce', '__return_true' );
			}

			if ( $page_type_set ) {
				Database::$page_data['current_page_type'] = $page_type;
			} else {
				unset( Database::$page_data['current_page_type'] );
			}

			foreach ( $stored_globals as $global_key => $global_data ) {
				if ( $global_data['exists'] ) {
					$GLOBALS[ $global_key ] = $global_data['value'];
				} else {
					unset( $GLOBALS[ $global_key ] );
				}
			}

			if ( $iframe_exists ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restores temporary builder iframe flag.
				$_GET[ $iframe_param ] = $iframe_value;
			} else {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restores temporary builder iframe flag.
				unset( $_GET[ $iframe_param ] );
			}
		}
	}

	/**
	 * Get partial page data for builder (lighter version for instant navigation)
	 *
	 * NOTE: This is a dedicated function to keep things separate
	 * But this functionality could be integrated into builder_data with a $partial_load parameter in the future
	 *
	 * @since 2.2
	 * @since 2.4.4 Share initial loading's content ownership and preview-context rules (#86c43x6k5).
	 *
	 * @return array
	 */
	public static function partial_builder_data( $post_id ) {
		$page_data                = Database::$page_data;
		$template_settings        = Helpers::get_template_settings( $post_id );
		$template_type            = Templates::get_template_type( $post_id );
		$template_preview_post_id = Helpers::get_template_preview_post_id( $post_id );
		$iframe_body_classes_id   = self::get_iframe_body_classes_target_id( $post_id, $template_preview_post_id );

		$load_data = [
			'header'            => [],
			'content'           => [],
			'footer'            => [],
			'pageSettings'      => [],
			'templateSettings'  => [],
			'templatePreview'   => self::get_template_preview_data( $post_id ),
			'wooPage'           => Woocommerce::get_builder_woo_page( $post_id ), // @since 2.4
			'iframeBodyClasses' => self::get_iframe_body_classes( $iframe_body_classes_id ), // @since 2.4
		];

		// Add page data to load_data
		if ( ! empty( $page_data['header'] ) ) {
			$load_data['header'] = $page_data['header'];
		} else {
			// Check for header template
			$template_header_id = Database::$active_templates['header'];
			$header_template    = $template_header_id ? Database::get_data( $template_header_id, 'header' ) : Database::get_data( $post_id, 'header' );

			if ( ! empty( $header_template ) ) {
				$load_data['header'] = $header_template;

				// Sticky header, header postition etc.
				$load_data['templateHeaderSettings'] = Helpers::get_template_settings( $template_header_id );
			}
		}

		// Content
		$template_content_id = Database::$active_templates['content'];

		$load_data = array_merge( $load_data, self::get_content_data( $post_id, $page_data['content'] ) );

		// Add template preview logic for single templates (@since 1.12)
		if ( $template_content_id && $template_content_id != $post_id ) {
			// Load template content for static area
			$template_content = Database::get_data( $template_content_id, 'content' );

			if ( ! empty( $template_content ) ) {
				$load_data['staticContent'] = $template_content;

				// Add template page settings to generate CSS for static content (@since 1.12)
				$template_page_settings = get_post_meta( $template_content_id, BRICKS_DB_PAGE_SETTINGS, true );

				if ( ! empty( $template_page_settings ) ) {
					$load_data['outerPostContentTemplatePageSettings'] = $template_page_settings;
				}
			}
		}

		// Footer
		if ( ! empty( $page_data['footer'] ) ) {
			$load_data['footer'] = $page_data['footer'];
		} else {
			$template_footer_id = Database::$active_templates['footer'];

			// Check for footer template
			$footer_template = $template_footer_id ? Database::get_data( $template_footer_id, 'footer' ) : [];

			if ( ! empty( $footer_template ) ) {
				$load_data['footer'] = $footer_template;
			}
		}

		if ( ! empty( $page_data['settings'] ) ) {
			$load_data['pageSettings'] = $page_data['settings'];
		}

		// @since 1.7.1 - Default template type is 'content' (so listenHistory in builder can work properly)
		$load_data['templateType'] = ! empty( $template_type ) ? $template_type : 'content';

		// Refresh settings controls based on new template type
		$GLOBALS['post'] = get_post( $post_id );
		// Translate the UI independently of the content rendered below.
		$locale_switched = switch_to_locale( self::get_builder_locale( determine_locale() ) );

		try {
			Settings::set_controls();

			$load_data['controls'] = [
				'settings' => Settings::get_controls_data(),
			];
		} finally {
			if ( $locale_switched ) {
				restore_previous_locale();
			}
		}

		$load_data['elementsCatFirst'] = self::get_first_elements_category( $post_id );

		// Template settings
		if ( $template_settings ) {
			$load_data['templateSettings'] = $template_settings;
		}

		// Parse elements to replace dynamic data (needed for background image)
		$template_preview_post_id = $template_preview_post_id ? $template_preview_post_id : $post_id;

		if ( $template_type !== 'header' && ! empty( $load_data['header'] ) && is_array( $load_data['header'] ) ) {
			$load_data['header'] = self::render_dynamic_data_on_elements( $load_data['header'], $template_preview_post_id );
		}

		if ( ! empty( $load_data['content'] ) && is_array( $load_data['content'] ) ) {
			$load_data['content'] = self::render_dynamic_data_on_elements( $load_data['content'], $template_preview_post_id );
		}

		if ( $template_type !== 'footer' && ! empty( $load_data['footer'] ) && is_array( $load_data['footer'] ) ) {
			$load_data['footer'] = self::render_dynamic_data_on_elements( $load_data['footer'], $template_preview_post_id );
		}

		/**
		 * Generate element HTML strings in PHP for fast initial render
		 *
		 * Individual element HTML AJAX calls in builder are too slow.
		 * Only load for dynamic area, but not static areas.
		 */
		$load_data['elementsHtml'] = [];

		// Remove setting in builder to get 'elementsHtml' with element ID for all PHP elements (@since 1.7)
		unset( Database::$global_settings['elementAttsAsNeeded'] );

		// New rendering mode: Collect HTML strings for all elements start (@since 2.0)
		add_filter( 'bricks/frontend/render_element', [ __CLASS__, 'collect_elements_html' ], 10, 2 );
		add_filter( 'bricks/frontend/render_loop', [ __CLASS__, 'collect_looping_html' ], 10, 3 );
		add_action( 'bricks/query/query_api_response', [ __CLASS__, 'collect_query_api_results' ], 10, 2 );

		// Header
		if ( $template_type === 'header' && isset( $load_data['header'] ) && is_array( $load_data['header'] ) ) {
			Frontend::render_data( $load_data['header'], 'header' );
		}

		// Content
		if ( ! in_array( $template_type, [ 'header', 'footer' ] ) && isset( $load_data['content'] ) && is_array( $load_data['content'] ) ) {
			Frontend::render_data( $load_data['content'], 'content' );
		}

		// Footer
		if ( $template_type === 'footer' && isset( $load_data['footer'] ) && is_array( $load_data['footer'] ) ) {
			Frontend::render_data( $load_data['footer'], 'footer' );
		}

		remove_filter( 'bricks/frontend/render_loop', [ __CLASS__, 'collect_looping_html' ], 10, 3 );
		// Collect HTML strings for all elements end (@since 2.0)
		remove_filter( 'bricks/frontend/render_element', [ __CLASS__, 'collect_elements_html' ], 10, 2 );
		remove_action( 'bricks/query/query_api_response', [ __CLASS__, 'collect_query_api_results' ], 10, 2 );

		// Set collected HTML strings for all elements for fast initial render, reduce API calls (@since 2.0)
		$load_data['elementsHtml']        = self::$elements_html;
		$load_data['previewTexts']        = self::$preview_texts;
		$load_data['loopingHtml']         = self::$looping_html;
		$load_data['loopVisibleElements'] = self::$loop_visible_elements; // For ACF flexible content preview (@since 2.4)
		$load_data['templatesData']       = self::$templates_data;
		$load_data['queryApiCache']       = self::$query_api_cache;

		/**
		 * STEP: Pre-populate dynamic data to minimize AJAX requests on builder load
		 *
		 * Only if Bricks builder setting 'enableDynamicDataPreview' is enabled, we pre-populate.
		 *
		 * @see render_dynamic_data_on_elements
		 *
		 * @since 1.7.1
		 */
		if ( Database::get_setting( 'enableDynamicDataPreview', false ) && is_array( self::$dynamic_data ) && count( self::$dynamic_data ) ) {
			$load_data['dynamicData']        = self::$dynamic_data;
			$load_data['loopingDynamicData'] = self::$looping_dynamic_data;
		}

		/**
		 * STEP: Code signatures validation for builder unsignedCodeIds
		 *
		 * @since 2.0
		 */
		$load_data['invalidCodeSignatures'] = self::get_invalid_code_signatures( $load_data );

		/**
		 * STEP: Add custom attributes to builder
		 *
		 * Keys: 'header', 'main', 'footer', or individual Bricks element ID
		 *
		 * @since 1.10
		 */
		$load_data['htmlAttributes'] = self::$html_attributes;

		$load_data['htmlAttributes']['header']  = apply_filters( 'bricks/header/attributes', [] );
		$load_data['htmlAttributes']['content'] = apply_filters( 'bricks/content/attributes', [] );
		$load_data['htmlAttributes']['footer']  = apply_filters( 'bricks/footer/attributes', [] );

		return $load_data;
	}

	/**
	 * Bricks 2.0 render mode
	 *
	 * Collect HTML string of every single element for initial fast builder render
	 *
	 * Use Frontend::render_data() instead of Ajax::render_element so all attributes can be collected successfully as well without execute apply_filters bricks/element/render_attributes
	 *
	 * @since 2.0 (#86c2z8bmd)
	 */
	public static function collect_elements_html( $html, $instance ) {
		$element_name = $instance->element['name'] ?? '';
		$settings     = $instance->element['settings'] ?? [];

		/**
		 * Skip: Nav menu
		 *
		 * As inside '.brx-dropdown-content' the nav-menu wrapper, etc. is not needed
		 *
		 * @since 1.11
		 */
		if ( $element_name === 'nav-menu' ) {
			return $html;
		}

		// Skip: Code element to prevent critical errors with code execution enabled on builder load
		if ( $element_name === 'code' ) {
			return $html;
		}

		/**
		 * Template element
		 *
		 * Skip to render template inline CSS in builder (TODO: Improve performance)
		 *
		 * @since 2.0: Do not skip if this is a render component call
		 */
		if ( $element_name === 'template' && empty( $_POST['isRenderComponent'] ) ) {
			return $html;
		}

		/**
		 * Skip: Shortcode element with bricks_template to render nested accordions, tabs, slider in builder
		 *
		 * Necessary as we skip nestable elements below (line 2335)
		 *
		 * @since 1.11
		 */
		if ( $element_name === 'shortcode' ) {
			$is_bricks_shortcode = ! empty( $settings['shortcode'] ) && strpos( $settings['shortcode'], 'bricks_template' ) !== false;

			if ( $is_bricks_shortcode ) {
				return $html;
			}
		}

		// Do not skip nestable elements or all inner elements wouldn't have HTML and cause extra render_elements calls in the builder
		// Skip nestable elements
		// if ( $instance->nestable ) {
		// return $html;
		// }

		// Handle component: Use unique ID that already considered elements inside component (@since 2.0)
		$element_id = $instance->uid ?? $instance->id;

		if ( ! empty( $_POST['isRenderComponent'] ) ) {
			// Full component rerenders need one HTML entry per nested render path, not per outer instance.
			// Nested component roots use cid in Element::$uid and in the Vue cache lookup, so the producer must use the same prefix. (#86cb2khh3; @since 2.3.12)
			$root_instance_id = sanitize_key( $_POST['isRenderComponent'] );
			$css_instance_id  = sanitize_key( $instance->element['cssInstanceId'] ?? $root_instance_id );
			$element_id       = ( ! empty( $instance->cid ) ? $instance->cid : $instance->id ) . '-' . $css_instance_id;
		}

		if ( ! isset( self::$elements_html[ $element_id ] ) ) {
			// Manually run bricks_render_dynamic_data as bricks/frontend/render_data not yet triggered (#86c3reqnt)
			self::$elements_html[ $element_id ] = bricks_render_dynamic_data( $html );
		}

		// Pre-populate dynamic data for all elements (Only if not looping #86c4pmh96)
		if ( Database::get_setting( 'enableDynamicDataPreview', false ) && ! empty( $settings ) && ! Query::is_any_looping() ) {
			$settings_string = wp_json_encode( $settings );

			// Get all dynamic data tags inside element settings
			preg_match_all( '/\{([^{}"]+)\}/', $settings_string, $matches );
			$dynamic_data_tags = $matches[1];

			foreach ( $dynamic_data_tags as $dynamic_data_tag ) {
				$dynamic_data_value = \Bricks\Integrations\Dynamic_Data\Providers::render_tag( $dynamic_data_tag, $instance->post_id );

				if ( $dynamic_data_value ) {
					self::$dynamic_data[ "{$dynamic_data_tag}" ] = $dynamic_data_value;
				}
			}
		}

		// Generate preview text for the first rendered query path. Exclude nestable elements to avoid unnecessary HTML generation.
		// Checking every active/simulated query prevents later or contextless nested results from leaking into the first builder node.
		// (#86caprbc5; @since 2.3.11)
		if ( Query::is_any_looping() && Query::is_rendering_first_loop_node() && ! $instance->nestable ) {
			if ( ! isset( self::$preview_texts[ $element_id ] ) ) {
				// Manually run bricks_render_dynamic_data as bricks/frontend/render_data not yet triggered (#86c3reqnt)
				// Do not wp_strip_all_tags so first loop node can render complete HTML (#86c5hj7au; @since 2.1)
				self::$preview_texts[ $element_id ] = bricks_render_dynamic_data( $html );
			}
		}

		return $html;
	}

	/**
	 * Bricks 2.0 builder render mode
	 *
	 * Collect query loop first node HTML string if the query located inside a component.
	 *
	 * @since 2.0
	 */
	public static function collect_looping_html( $html, $element_data, $instance ) {
		$element_id = $instance->uid ?? $instance->id;

		if ( ! empty( $_POST['isRenderComponent'] ) ) {
			// Loop HTML must follow the same cid-first cache key contract as regular component HTML. (#86cb2khh3; @since 2.3.12)
			$root_instance_id = sanitize_key( $_POST['isRenderComponent'] );
			$css_instance_id  = sanitize_key( $instance->element['cssInstanceId'] ?? $root_instance_id );
			$element_id       = ( ! empty( $instance->cid ) ? $instance->cid : $instance->id ) . '-' . $css_instance_id;
		}

		if ( ! isset( self::$looping_html[ $element_id ] ) ) {
			self::$looping_html[ $element_id ] = $html;
		}

		return $html;
	}

	/**
	 * Collect dynamic data used inside looping
	 *
	 * @since 2.2 #86c4tzdxq
	 */
	public static function collect_looping_dynamic_data( $value, $tag, $original_tag, $args, $post, $context, $provider ) {
		// Collect dynamic data used inside looping
		if ( ! Query::is_any_looping() ) {
			return;
		}

		$query_id = Query::get_query_element_id( Query::is_any_looping() );
		$key      = $original_tag . '||' . $query_id;
		if ( ! isset( self::$looping_dynamic_data[ $key ] ) ) {
			self::$looping_dynamic_data[ $key ] = $value;
		}
	}

	/**
	 * Collect query results for API calls
	 *
	 * Used in PopupQueryApi.vue
	 *
	 * @since 2.1
	 */
	public static function collect_query_api_results( $results, $element_id ) {
		// Collect query results for external API calls
		if ( isset( self::$query_api_cache[ $element_id ] ) ) {
			return;
		}

		// Do not cache if it's error
		if ( isset( $results['error'] ) && ! empty( $results['error'] ) ) {
			return;
		}
		// Add results to cache
		self::$query_api_cache[ $element_id ] = $results;
	}

	/**
	 * Screens all elements and try to convert dynamic data to enhance builder experience
	 *
	 * @param array $elements
	 * @param int   $post_id
	 */
	public static function render_dynamic_data_on_elements( $elements, $post_id ) {
		$elements_json = wp_json_encode( $elements );

		if ( ! is_string( $elements_json ) ) {
			return $elements;
		}

		// Custom background URLs can contain dynamic tags without using the image dynamic data field.
		// (#86c7yzzbg; @since 2.3.6)
		if (
			strpos( $elements_json, 'useDynamicData' ) === false &&
			strpos( $elements_json, '_background' ) === false
		) {
			return $elements;
		}

		foreach ( $elements as $index => $element ) {
			$elements[ $index ]['settings'] = self::render_dynamic_data_on_settings( $element['settings'], $post_id );
		}

		return $elements;
	}

	/**
	 * On the settings array, if _background exists and is set to image, get the image URL
	 * Needed when setting element background image
	 * Refreshes every responsive _background image that uses dynamic data. (#86c6pygjb; @since 2.3.6)
	 *
	 * @param array $settings
	 * @param int   $post_id
	 */
	public static function render_dynamic_data_on_settings( $settings, $post_id ) {
		// Return: Do not render dynamic data for elements inside a loop
		if ( isset( $settings['hasLoop'] ) ) {
			return $settings;
		}

		foreach ( $settings as $setting_key => $setting_value ) {
			if ( $setting_key !== '_background' && strpos( $setting_key, '_background:' ) !== 0 ) {
				continue;
			}

			$background_image = ! empty( $setting_value['image'] ) && is_array( $setting_value['image'] )
				? $setting_value['image']
				: [];

			if ( empty( $background_image ) ) {
				continue;
			}

			$background_image_dd_tag = ! empty( $setting_value['image']['useDynamicData'] ) ? $setting_value['image']['useDynamicData'] : false;

			if ( is_array( $background_image_dd_tag ) ) {
				$background_image_dd_tag = ! empty( $background_image_dd_tag['name'] ) ? $background_image_dd_tag['name'] : false;
			}

			if ( ! $background_image_dd_tag ) {
				$external_url = $background_image['external'] ?? '';

				if ( ! is_string( $external_url ) || strpos( $external_url, '{' ) === false ) {
					continue;
				}

				// Clear source-page preview fields before resolving against the active builder preview post.
				// (#86c7yzzbg; @since 2.3.6)
				unset(
					$settings[ $setting_key ]['image']['id'],
					$settings[ $setting_key ]['image']['url'],
					$settings[ $setting_key ]['image']['filename'],
					$settings[ $setting_key ]['image']['full']
				);

				// Resolve the editable Custom URL into a builder preview URL while preserving the original tag in 'external'.
				// (#86c7yzzbg; @since 2.3.6)
				$rendered_url = bricks_render_dynamic_data( $external_url, $post_id, 'link' );

				if ( ! $rendered_url ) {
					continue;
				}

				$settings[ $setting_key ]['image']['url'] = $rendered_url;

				$rendered_url_path = wp_parse_url( $rendered_url, PHP_URL_PATH );
				$filename          = $rendered_url_path ? basename( $rendered_url_path ) : '';

				if ( $filename ) {
					$settings[ $setting_key ]['image']['filename'] = $filename;
				}

				continue;
			}

			$size = ! empty( $setting_value['image']['size'] ) ? $setting_value['image']['size'] : BRICKS_DEFAULT_IMAGE_SIZE;

			// Clear stale source-page preview data before resolving against the current preview post. (#86c6pygjb; @since 2.3.6)
			unset(
				$settings[ $setting_key ]['image']['id'],
				$settings[ $setting_key ]['image']['url'],
				$settings[ $setting_key ]['image']['filename'],
				$settings[ $setting_key ]['image']['full']
			);

			$images   = Integrations\Dynamic_Data\Providers::render_tag( $background_image_dd_tag, $post_id, 'image', [ 'size' => $size ] );
			$image_id = ! empty( $images[0] ) ? $images[0] : false;

			if ( ! $image_id ) {
				continue;
			}

			if ( is_numeric( $image_id ) ) {
				$settings[ $setting_key ]['image']['id']   = $image_id;
				$settings[ $setting_key ]['image']['size'] = $size;
				$settings[ $setting_key ]['image']['url']  = wp_get_attachment_image_url( $image_id, $size );
			} else {
				$settings[ $setting_key ]['image']['url'] = $image_id;
			}
		}

		return $settings;
	}


	/**
	 * Builder: Force Bricks template to avoid conflicts with other builders (Elementor PRO, etc.)
	 */
	public function template_include( $template ) {
		if ( bricks_is_builder() ) {
			$template = BRICKS_PATH . 'template-parts/builder.php';
		}

		return $template;
	}

	/**
	 * Helper function to check if a AJAX or REST API call comes from inside the builder
	 *
	 * NOTE: Use bricks_is_builder_call() to check if AJAX/REST API call inside the builder
	 *
	 * @since 1.5.5
	 *
	 * @return boolean
	 */
	public static function is_builder_call() {
		/**
		 * STEP: Builder AJAX call: Check data for 'bricks-is-builder'
		 */
		if ( bricks_is_ajax_call() && check_ajax_referer( 'bricks-nonce-builder', 'nonce', false ) ) {
			$action     = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
			$is_builder = isset( $_REQUEST['bricks-is-builder'] );

			if ( $is_builder ) {
				return true;
			}
		}

		/**
		 * STEP: REST API call
		 *
		 * Is default builder render.
		 */
		if ( bricks_is_rest_call() ) {
			return ! empty( $_SERVER['HTTP_X_BRICKS_IS_BUILDER'] );
		}

		/**
		 * STEP: Builder frontend preview (window opened via builder toolbar preview icon)
		 *
		 * Check needed as referrer check below is the builder.
		 *
		 * @since 1.6.2
		 */
		if ( isset( $_GET['bricks_preview'] ) ) {
			return false;
		}

		// STEP: Check query string of referer URL (@since 1.5.5)
		$referer          = ! empty( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : wp_get_referer();
		$url_parsed       = $referer ? wp_parse_url( $referer ) : '';
		$url_query_string = isset( $url_parsed['query'] ) ? $url_parsed['query'] : '';

		if ( $url_query_string && strpos( $url_query_string, 'bricks=run' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Return the maximum number of query loop results to display in the builder
	 *
	 * @since 1.11
	 */
	public static function get_query_max_results() {
		return Database::get_setting( 'builderQueryMaxResults', false );
	}

	/**
	 * Get query max results info
	 *
	 * @since 1.11
	 */
	public static function get_query_max_results_info() {
		return sprintf( esc_html__( 'Query loop results in the builder are limited to %1$s.', 'bricks' ), self::get_query_max_results() ) . ' <a href="' . admin_url( 'admin.php?page=bricks-settings#tab-builder' ) . '" target="_blank">[' . esc_html__( 'Edit', 'bricks' ) . ']</a>';
	}

	/**
	 * Check if user enabled through Bricks > Settings > Builder builderCloudflareRocketLoader
	 *
	 * - Ensure that the request is coming from Cloudflare.
	 * - TODO: Will be set as default in a future version of Bricks. (#86c2rdm5a)
	 *
	 * @since 2.0
	 */
	public static function cloudflare_rocket_loader_disabled() {
		return ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && Database::get_setting( 'builderCloudflareRocketLoader', false );
	}

	/**
	 * Add data-cfasync="false" to all <script> tags to avoid Cloudflare Rocket Loader
	 *
	 * @since 2.0
	 */
	public function cloudflare_rocket_loader_modify_script_tags() {
		ob_start(
			function ( $html ) {
				// Use preg_replace to add data-cfasync="false" to all <script> tags
				$html = preg_replace_callback(
					'/<script\b([^>]*)>/i',
					function ( $matches ) {
						// Check if the script tag already has data-cfasync="false"
						if ( isset( $matches[1] ) && $matches[1] !== '' && strpos( $matches[1], 'data-cfasync' ) === false ) {
							// Add data-cfasync="false" to the script tag
							return '<script data-cfasync="false" ' . $matches[1] . '>';
						}

						// Return the original ta
						return $matches[0];
					},
					$html
				);

				return $html;
			}
		);
	}

	/**
	 * Returns an array of invalid code signatures elements IDs
	 *
	 * @param array $data (elements data)
	 * @since 2.0
	 */
	public static function get_invalid_code_signatures( $data ) {
		$invalid_code_signatures = [];

		// Check from 'header', 'content', 'footer'
		$elements = array_merge(
			$data['header'] ?? [],
			$data['content'] ?? [],
			$data['footer'] ?? []
		);

		// Elements from components
		foreach ( Database::$global_data['components'] as $component ) {
			if ( ! empty( $component['elements'] ) && is_array( $component['elements'] ) ) {
				$elements = array_merge( $elements, $component['elements'] );
			}
		}

		foreach ( $elements as $element ) {
			$element_settings = $element['settings'] ?? [];
			$element_name     = $element['name'] ?? '';

			$global_settings = Helpers::get_global_element( $element, 'settings' );

			if ( $global_settings ) {
				$element_settings = $global_settings;
			}

			// Check: Component root
			$component_instance_settings = ! empty( $element['cid'] ) ? Helpers::get_component_instance( $element, 'settings' ) : false;

			if ( $component_instance_settings ) {
				$element_settings = $component_instance_settings;
			}

			if ( empty( $element_settings ) ) {
				continue;
			}

			// STEP: Code element
			if ( $element_name === 'code' ) {
				$element['execute_code'] = isset( $element_settings['executeCode'] );

				// Execute code
				if ( $element['execute_code'] ) {
					$element_settings_code = isset( $element_settings['code'] ) ? $element_settings['code'] : '';

					// Skip if no code or empty code
					if ( empty( $element_settings_code ) ) {
						continue;
					}

					$valid = false;

					if ( ! empty( $element_settings['signature'] ) ) {
						$valid = Helpers::verify_code_signature( $element_settings['signature'], $element_settings_code );
					}

					if ( ! $valid ) {
						$invalid_code_signatures[] = $element['id'];
					}
				}

				continue;
			}

			// STEP: SVG element
			if ( $element_name === 'svg' ) {
				$element['execute_code'] = isset( $element_settings['code'] ) && ! empty( $element_settings['code'] );

				if ( $element['execute_code'] ) {
					$element_settings_code = isset( $element_settings['code'] ) ? $element_settings['code'] : '';

					// Skip if no code or empty code
					if ( empty( $element_settings_code ) ) {
						continue;
					}

					$valid = false;

					if ( ! empty( $element_settings['signature'] ) ) {
						$valid = Helpers::verify_code_signature( $element_settings['signature'], $element_settings_code );
					}

					if ( ! $valid ) {
						$invalid_code_signatures[] = $element['id'];
					}
				}

				continue;
			}

			// STEP: Query editor element
			if ( isset( $element_settings['query']['queryEditor'] ) ) {
				$element['execute_code'] = isset( $element_settings['query']['useQueryEditor'] );

				if ( $element['execute_code'] ) {
					$element_settings_code = isset( $element_settings['query']['queryEditor'] ) ? $element_settings['query']['queryEditor'] : '';

					// Skip if no code or empty code
					if ( empty( $element_settings_code ) ) {
						continue;
					}

					$valid = false;

					if ( ! empty( $element_settings['query']['signature'] ) ) {
						$valid = Helpers::verify_code_signature( $element_settings['query']['signature'], $element_settings_code );
					}

					if ( ! $valid ) {
						$invalid_code_signatures[] = $element['id'];
					}
				}

				continue;
			}

		}

		return $invalid_code_signatures;
	}
}

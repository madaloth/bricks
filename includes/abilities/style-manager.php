<?php
/**
 * Style Manager abilities
 *
 * Read and write `BRICKS_DB_STYLE_MANAGER` - the option that drives the
 * builder's light/dark default mode behavior and the html font-size used as
 * the px to rem divisor for variable scales.
 *
 * Shape (observed keys - admin UI may add more):
 *   [
 *     'htmlFontSize'   => '10px',     // px to rem divisor (default '10px')
 *     'minScreenWidth' => 360,        // fluid-scale min width
 *     'maxScreenWidth' => 1440,       // fluid-scale max width
 *     'defaultMode'    => 'light',    // 'light' | 'dark' | 'auto'
 *     ...
 *   ]
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Style_Manager {
	public static function get_style_manager_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public static function get_style_manager_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'styleManager' => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Whole option contents. Null if the option has never been written (Bricks falls back to internal defaults).', 'bricks' ),
				],
			],
		];
	}

	public static function set_style_manager_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'styleManager' ],
			'properties' => [
				'styleManager' => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Full replacement of the style-manager option. Pass null or empty object to reset to defaults. Common keys: htmlFontSize, minScreenWidth, maxScreenWidth, defaultMode (light|dark|auto).', 'bricks' ),
				],
			],
		];
	}

	public static function set_style_manager_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'      => [ 'type' => 'boolean' ],
				'styleManager' => [ 'type' => [ 'object', 'null' ] ],
			],
		];
	}

	public static function read_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if (
			! \Bricks\Builder_Permissions::user_has_permission( 'access_class_manager' ) &&
			! \Bricks\Builder_Permissions::user_has_permission( 'access_variable_manager' )
		) {
			return Error::forbidden_builder_permission( 'access_class_manager|access_variable_manager' );
		}

		return true;
	}

	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	public static function get_style_manager( $input ) {
		Manager::flush_options_cache();
		$value = get_option( BRICKS_DB_STYLE_MANAGER, null );
		return [ 'styleManager' => is_array( $value ) ? $value : null ];
	}

	public static function set_style_manager( $input ) {
		$value = $input['styleManager'] ?? null;

		if ( $value === null || ( is_array( $value ) && empty( $value ) ) ) {
			delete_option( BRICKS_DB_STYLE_MANAGER );
			return [
				'success'      => true,
				'styleManager' => null,
			];
		}

		if ( ! is_array( $value ) ) {
			return Error::invalid_param( 'styleManager', 'object or null', $value );
		}

		update_option( BRICKS_DB_STYLE_MANAGER, $value );

		return [
			'success'      => true,
			'styleManager' => $value,
		];
	}
}

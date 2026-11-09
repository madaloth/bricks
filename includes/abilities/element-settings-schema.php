<?php
/**
 * Runtime element settings schema.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the authoritative runtime schema for persisted element settings.
 *
 * @since 2.4
 */
class Element_Settings_Schema {
	/**
	 * Canonical child setting definitions indexed by element name.
	 *
	 * @since 2.4
	 *
	 * @var array|null
	 */
	private static $canonical_definitions = null;

	/**
	 * Return the runtime setting definitions for an element type.
	 *
	 * Runtime controls remain the primary source of truth. Virtual definitions
	 * describe persisted Bricks settings that are managed outside the standard
	 * element control registry.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return array
	 */
	public static function get( $element_name ) {
		$controls = \Bricks\Elements::get_element( [ 'name' => $element_name ], 'controls' );
		$controls = is_array( $controls ) ? $controls : [];
		$schema   = array_merge(
			self::get_canonical_definitions( $element_name ),
			self::get_virtual_definitions( $element_name ),
			$controls
		);

		/**
		 * Filter the complete runtime setting schema for an element type.
		 *
		 * Plugins should use this filter for valid persisted settings that do not
		 * have a standard Builder control.
		 *
		 * @since 2.4
		 *
		 * @param array  $schema       Setting definitions keyed by setting name.
		 * @param string $element_name Element name.
		 */
		$filtered_schema = apply_filters( "bricks/elements/{$element_name}/settings_schema", $schema, $element_name );

		if ( ! is_array( $filtered_schema ) ) {
			$filtered_schema = $schema;
		}

		return array_filter( $filtered_schema, 'is_array' );
	}

	/**
	 * Return settings emitted by registered canonical element trees.
	 *
	 * Element factories are the source of truth for internal child metadata. The
	 * registry is inspected lazily once per request so core and plugin-provided
	 * canonical children do not need a second allowlist.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return array
	 */
	private static function get_canonical_definitions( $element_name ) {
		if ( self::$canonical_definitions === null ) {
			self::$canonical_definitions = [];

			if ( property_exists( '\\Bricks\\Elements', 'elements' ) && is_array( \Bricks\Elements::$elements ) ) {
				foreach ( array_keys( \Bricks\Elements::$elements ) as $provider_name ) {
					$provider = \Bricks\Elements::get_element( [ 'name' => $provider_name ] );

					if ( ! is_array( $provider ) ) {
						continue;
					}

					self::collect_canonical_definitions( $provider['nestableItem'] ?? [] );
					self::collect_canonical_definitions( $provider['nestableChildren'] ?? [] );
				}
			}
		}

		return self::$canonical_definitions[ $element_name ] ?? [];
	}

	/**
	 * Collect setting definitions recursively from canonical child data.
	 *
	 * @since 2.4
	 *
	 * @param array $children Canonical child or child list.
	 * @return void
	 */
	private static function collect_canonical_definitions( $children ) {
		if ( ! is_array( $children ) ) {
			return;
		}

		if ( isset( $children['name'] ) ) {
			$children = [ $children ];
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || empty( $child['name'] ) ) {
				continue;
			}

			$child_name = (string) $child['name'];

			foreach ( (array) ( $child['settings'] ?? [] ) as $key => $value ) {
				$base_key = self::base_setting_key( $key );

				if ( ! isset( self::$canonical_definitions[ $child_name ][ $base_key ] ) ) {
					self::$canonical_definitions[ $child_name ][ $base_key ] = [
						'type'      => self::infer_type( $value ),
						'virtual'   => true,
						'internal'  => true,
						'canonical' => true,
					];
				}
			}

			self::collect_canonical_definitions( $child['children'] ?? [] );
		}
	}

	/**
	 * Strip responsive and pseudo suffixes from a canonical setting key.
	 *
	 * @since 2.4
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function base_setting_key( $key ) {
		$parts = preg_split( '/[:|]/', (string) $key, 2 );

		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : (string) $key;
	}

	/**
	 * Infer a compact control-style type from a canonical value.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Canonical setting value.
	 * @return string
	 */
	private static function infer_type( $value ) {
		if ( is_bool( $value ) ) {
			return 'checkbox';
		}

		if ( is_int( $value ) ) {
			return 'integer';
		}

		if ( is_float( $value ) ) {
			return 'number';
		}

		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );

			return $is_list ? 'array' : 'object';
		}

		return is_string( $value ) ? 'string' : 'mixed';
	}

	/**
	 * Return Bricks settings managed outside the standard control registry.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @return array
	 */
	private static function get_virtual_definitions( $element_name ) {
		$definitions = [
			'_conditions'          => [
				'type'     => 'conditions',
				'virtual'  => true,
				'internal' => true,
			],
			'_cssGlobalClasses'    => [
				'type'     => 'array',
				'virtual'  => true,
				'internal' => true,
			],
			'_hidden'              => [
				'tab'                  => 'style',
				'type'                 => 'object',
				'virtual'              => true,
				'internal'             => true,
				'properties'           => [
					'_cssClasses' => [ 'type' => 'string' ],
				],
				'additionalProperties' => false,
			],
			'_hideElementBuilder'  => [
				'type'     => 'checkbox',
				'virtual'  => true,
				'internal' => true,
			],
			'_hideElementFrontend' => [
				'type'     => 'checkbox',
				'virtual'  => true,
				'internal' => true,
			],
			'_interactions'        => [
				'type'     => 'interactions',
				'virtual'  => true,
				'internal' => true,
			],
		];

		// Query-loop controls are registered only during an interactive Builder request.
		if ( in_array( $element_name, [ 'section', 'container', 'block', 'div' ], true ) ) {
			$definitions['hasLoop'] = [
				'tab'         => 'content',
				'label'       => __( 'Query loop', 'bricks' ),
				'type'        => 'checkbox',
				'virtual'     => true,
				'description' => __( 'Enable a query loop on this layout element.', 'bricks' ),
			];
			$definitions['query']   = [
				'tab'         => 'content',
				'label'       => __( 'Query', 'bricks' ),
				'type'        => 'query',
				'virtual'     => true,
				'required'    => [ 'hasLoop', '!=', '' ],
				'description' => __( 'Query object. Use bricks/list-query-loop-types for supported objectType values.', 'bricks' ),
			];
		}

		return $definitions;
	}
}

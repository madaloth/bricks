<?php
/**
 * Sidebar abilities
 *
 * CRUD for custom WordPress sidebars registered by Bricks. Storage lives
 * in `BRICKS_DB_SIDEBARS` as an ordered array of:
 *   [ 'id' => 'slug', 'name' => 'Display Name', 'description' => __( '...', 'bricks' ) ]
 *
 * Deleting a sidebar also drops its entry from the core `sidebars_widgets`
 * option so placed widgets are removed alongside it - same behavior as
 * the admin UI.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sidebars {
	// ==================================================================
	// Schemas
	// ==================================================================

	/**
	 * Input schema for list-sidebars.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_sidebars_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public static function list_sidebars_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'sidebars' => [ 'type' => 'array' ],
				'total'    => [ 'type' => 'integer' ],
			],
		];
	}

	public static function create_sidebar_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'name' ],
			'properties' => [
				'name'        => [
					'type'        => 'string',
					'description' => __( 'Human-readable sidebar name. Must be unique. The ID is derived from the name (lowercased, spaces to underscores).', 'bricks' ),
				],
				'description' => [
					'type'        => 'string',
					'description' => __( 'Optional description.', 'bricks' ),
				],
			],
		];
	}

	public static function create_sidebar_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'sidebar' => [ 'type' => 'object' ],
			],
		];
	}

	public static function update_sidebar_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'sidebarId' ],
			'properties' => [
				'sidebarId'   => [
					'type'        => 'string',
					'description' => __( 'The sidebar\'s immutable ID (set at creation time).', 'bricks' ),
				],
				'name'        => [
					'type'        => 'string',
					'description' => __( 'New display name. Must remain unique.', 'bricks' ),
				],
				'description' => [
					'type'        => 'string',
					'description' => __( 'New description. Pass empty string to clear.', 'bricks' ),
				],
			],
		];
	}

	public static function update_sidebar_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'sidebar' => [ 'type' => 'object' ],
			],
		];
	}

	public static function delete_sidebar_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'sidebarId' ],
			'properties' => [
				'sidebarId' => [ 'type' => 'string' ],
			],
		];
	}

	public static function delete_sidebar_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deletedId' => [ 'type' => 'string' ],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Permission: read - `edit_posts` matches the Bricks Sidebars admin screen.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		return Manager::require_cap( 'edit_posts' );
	}

	/**
	 * Permission: write - `edit_posts` matches the Bricks Sidebars admin screen.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	// ==================================================================
	// Execute
	// ==================================================================

	/**
	 * Callback: list sidebars.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_sidebars( $input ) {
		Manager::flush_options_cache();
		$sidebars = self::load();
		return [
			'sidebars' => $sidebars,
			'total'    => count( $sidebars ),
		];
	}

	public static function create_sidebar( $input ) {
		Manager::flush_options_cache();

		$name        = sanitize_text_field( trim( (string) ( $input['name'] ?? '' ) ) );
		$description = isset( $input['description'] ) ? sanitize_text_field( (string) $input['description'] ) : '';

		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'non-empty string', $name );
		}

		$id = strtolower( str_replace( ' ', '_', $name ) );
		$id = preg_replace( '/[^a-z0-9_]/', '', $id );
		if ( $id === '' ) {
			return Error::invalid_param( 'name', 'name derivable to a non-empty ID (letters/digits/spaces)', $name );
		}

		$sidebars = self::load();

		foreach ( $sidebars as $existing ) {
			if ( ( $existing['id'] ?? '' ) === $id ) {
				return Error::conflict_duplicate_name( 'sidebar', $name, [ 'existingId' => $id ] );
			}
			if ( strcasecmp( (string) ( $existing['name'] ?? '' ), $name ) === 0 ) {
				return Error::conflict_duplicate_name( 'sidebar', $name, [ 'existingId' => $existing['id'] ?? '' ] );
			}
		}

		$row        = [
			'id'          => $id,
			'name'        => $name,
			'description' => $description,
		];
		$sidebars[] = $row;

		$saved = self::persist_sidebars( $sidebars );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [ 'sidebar' => $row ];
	}

	public static function update_sidebar( $input ) {
		Manager::flush_options_cache();

		$id = (string) ( $input['sidebarId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'sidebarId', 'non-empty string', $id );
		}

		$sidebars = self::load();

		foreach ( $sidebars as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) !== $id ) {
				continue;
			}

			if ( array_key_exists( 'name', $input ) ) {
				$new_name = sanitize_text_field( trim( (string) $input['name'] ) );
				if ( $new_name === '' ) {
					return Error::invalid_param( 'name', 'non-empty string', $new_name );
				}

				foreach ( $sidebars as $j => $other ) {
					if ( $j === $index ) {
						continue;
					}
					if ( strcasecmp( (string) ( $other['name'] ?? '' ), $new_name ) === 0 ) {
						return Error::conflict_duplicate_name( 'sidebar', $new_name, [ 'existingId' => $other['id'] ?? '' ] );
					}
				}

				$existing['name'] = $new_name;
			}

			if ( array_key_exists( 'description', $input ) ) {
				$existing['description'] = sanitize_text_field( (string) $input['description'] );
			}

			$sidebars[ $index ] = $existing;

			$saved = self::persist_sidebars( $sidebars );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return [ 'sidebar' => $existing ];
		}

		return Error::not_found( 'sidebar', $id );
	}

	public static function delete_sidebar( $input ) {
		Manager::flush_options_cache();

		$id = (string) ( $input['sidebarId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'sidebarId', 'non-empty string', $id );
		}

		$sidebars = self::load();
		$kept     = [];
		$found    = false;

		foreach ( $sidebars as $existing ) {
			if ( ( $existing['id'] ?? '' ) === $id ) {
				$found = true;
				continue;
			}
			$kept[] = $existing;
		}

		if ( ! $found ) {
			return Error::not_found( 'sidebar', $id );
		}

		$saved = self::persist_sidebars( $kept );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$sidebars_widgets = get_option( 'sidebars_widgets', [] );
		if ( isset( $sidebars_widgets[ $id ] ) ) {
			unset( $sidebars_widgets[ $id ] );
			$saved_widgets = update_option( 'sidebars_widgets', $sidebars_widgets );

			if ( ! $saved_widgets && get_option( 'sidebars_widgets', [] ) !== $sidebars_widgets ) {
				return Error::internal_error( 'delete_sidebar', 'Could not persist widget removal for the deleted sidebar.' );
			}
		}

		return [ 'deletedId' => $id ];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Load stored custom sidebars.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function load(): array {
		$sidebars = get_option( BRICKS_DB_SIDEBARS, [] );
		return is_array( $sidebars ) ? array_values( $sidebars ) : [];
	}

	/**
	 * Persist the custom-sidebar registry and verify the stored value.
	 *
	 * `update_option()` returns false for both failed writes and unchanged
	 * values, so use a readback check before reporting failure.
	 *
	 * @since 2.4
	 *
	 * @param array $sidebars Sidebar rows to store.
	 * @return true|\WP_Error
	 */
	private static function persist_sidebars( array $sidebars ) {
		$sidebars = array_values( $sidebars );

		if ( empty( $sidebars ) ) {
			$deleted = delete_option( BRICKS_DB_SIDEBARS );
			$current = get_option( BRICKS_DB_SIDEBARS, [] );

			if ( ! $deleted && ! empty( $current ) ) {
				return Error::internal_error( 'sidebars', 'Could not remove the custom sidebar registry.' );
			}

			return true;
		}

		$updated = update_option( BRICKS_DB_SIDEBARS, $sidebars, false );

		if ( ! $updated && self::load() !== $sidebars ) {
			return Error::internal_error( 'sidebars', 'Could not persist the custom sidebar registry.' );
		}

		return true;
	}
}

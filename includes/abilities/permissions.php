<?php
/**
 * Builder permission abilities
 *
 * Exposes the Bricks builder-access model:
 *
 * - custom capability definitions live in BRICKS_DB_CAPABILITIES_PERMISSIONS
 * - role access is stored as WordPress role capabilities
 *
 * Do not route this through `bricks_global_settings`. The admin settings form
 * also treats these as special fields instead of saving them into the global
 * settings option.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Permissions {

	/**
	 * Read permission.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function read_permission( $input = [] ) {
		return Manager::require_cap( 'manage_options' );
	}

	/**
	 * Write permission.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function write_permission( $input = [] ) {
		return Manager::require_cap( 'manage_options' );
	}

	/**
	 * Input schema for list-builder-permissions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_builder_permissions_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Output schema for list-builder-permissions.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_builder_permissions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'permissionSections'        => [
					'type'        => 'object',
					'description' => __( 'Builder permission sections from the Bricks permission registry.', 'bricks' ),
				],
				'capabilities'              => [
					'type'        => 'object',
					'description' => __( 'Default and custom builder capability definitions keyed by capability id.', 'bricks' ),
				],
				'roleAccess'                => [
					'type'        => 'object',
					'description' => __( 'Current role assignment. Values are WordPress capability ids such as bricks_edit_content, bricks_full_access, a custom capability id, or empty string for no access.', 'bricks' ),
				],
				'builderAccessCapabilities' => [
					'type'        => 'array',
					'description' => __( 'Capability choices available in the Builder access role select.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * List builder permission sections, capability definitions, and role access.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_builder_permissions( $input ) {
		$sections     = \Bricks\Builder_Permissions::get_sections( true );
		$custom       = self::get_custom_capabilities();
		$capabilities = [];

		foreach ( \Bricks\Builder_Permissions::DEFAULT_CAPABILITIES as $capability_id => $label ) {
			$capabilities[ $capability_id ] = \Bricks\Builder_Permissions::get_default_capability_permissions( $capability_id );
		}

		foreach ( $custom as $capability_id => $capability ) {
			$capabilities[ $capability_id ] = $capability;
		}

		return [
			'permissionSections'        => $sections,
			'capabilities'              => $capabilities,
			'roleAccess'                => self::role_access_snapshot(),
			'builderAccessCapabilities' => \Bricks\Capabilities::builder_caps(),
		];
	}

	/**
	 * Input schema for upsert-builder-capability.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function upsert_builder_capability_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'id', 'label', 'permissions' ],
			'properties'           => [
				'id'          => [
					'type'        => 'string',
					'description' => __( 'Custom WordPress capability id. Must not be one of the built-in Bricks capability ids.', 'bricks' ),
				],
				'label'       => [
					'type'        => 'string',
					'description' => __( 'Display label shown in the Builder access select.', 'bricks' ),
				],
				'description' => [
					'type'        => 'string',
					'description' => __( 'Optional internal description.', 'bricks' ),
				],
				'permissions' => [
					'type'        => 'array',
					'description' => __( 'Builder permission keys to grant to this custom capability. Must match keys returned by list-builder-permissions.permissionSections.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Output schema for upsert-builder-capability.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function upsert_builder_capability_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'string' ],
				'created'    => [ 'type' => 'boolean' ],
				'capability' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Create or update one custom builder capability definition.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function upsert_builder_capability( $input ) {
		$id = sanitize_key( (string) ( $input['id'] ?? '' ) );

		if ( $id === '' ) {
			return Error::invalid_param( 'id', 'a non-empty custom capability id', $input['id'] ?? null );
		}

		if ( in_array( $id, array_keys( \Bricks\Builder_Permissions::DEFAULT_CAPABILITIES ), true ) ) {
			return Error::invalid_param( 'id', 'a custom capability id, not a built-in Bricks capability id', $id );
		}

		$label = trim( (string) ( $input['label'] ?? '' ) );
		if ( $label === '' ) {
			return Error::invalid_param( 'label', 'a non-empty string', $input['label'] ?? null );
		}

		$permissions = $input['permissions'] ?? null;
		if ( ! is_array( $permissions ) ) {
			return Error::invalid_param( 'permissions', 'an array of builder permission keys', $permissions );
		}

		$permissions = array_values( array_unique( array_map( 'strval', $permissions ) ) );
		$valid       = self::valid_permission_keys();

		foreach ( $permissions as $index => $permission ) {
			if ( ! isset( $valid[ $permission ] ) ) {
				return Error::invalid_param( "permissions[{$index}]", 'a permission key returned by list-builder-permissions', $permission );
			}
		}

		$custom  = self::get_custom_capabilities();
		$created = ! isset( $custom[ $id ] );

		$custom[ $id ] = [
			'label'       => sanitize_text_field( $label ),
			'description' => isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '',
			'permissions' => $permissions,
		];

		update_option( BRICKS_DB_CAPABILITIES_PERMISSIONS, $custom );

		$administrator = get_role( 'administrator' );
		if ( $administrator && ! $administrator->has_cap( $id ) ) {
			$administrator->add_cap( $id, true );
		}

		return [
			'id'         => $id,
			'created'    => $created,
			'capability' => $custom[ $id ],
		];
	}

	/**
	 * Input schema for set-builder-role-access.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_builder_role_access_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'roleAccess' ],
			'properties'           => [
				'roleAccess' => [
					'type'                 => 'object',
					'description'          => __( 'Map of role slug to builder access capability id. Empty string means no access. Built-in choices are bricks_edit_content and bricks_full_access; custom capability ids must already exist.', 'bricks' ),
					'additionalProperties' => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Output schema for set-builder-role-access.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_builder_role_access_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'updated'        => [ 'type' => 'array' ],
				'unchanged'      => [ 'type' => 'array' ],
				'beforeSnapshot' => [ 'type' => 'object' ],
				'roleAccess'     => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Assign builder access capabilities to WordPress roles.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_builder_role_access( $input ) {
		$incoming = $input['roleAccess'] ?? null;

		if ( ! is_array( $incoming ) || empty( $incoming ) ) {
			return Error::invalid_param( 'roleAccess', 'a non-empty role-to-capability map', $incoming );
		}

		$roles        = wp_roles()->get_names();
		$allowed_caps = array_filter( array_column( \Bricks\Capabilities::builder_caps(), 'capability' ) );
		$allowed_caps = array_fill_keys( $allowed_caps, true );

		$before    = self::role_access_snapshot();
		$validated = [];

		foreach ( $incoming as $role => $capability ) {
			$role       = sanitize_key( (string) $role );
			$capability = sanitize_key( (string) $capability );

			if ( ! isset( $roles[ $role ] ) ) {
				return Error::invalid_param( 'roleAccess.' . $role, 'an existing WordPress role slug', $role );
			}

			if ( $role === 'administrator' ) {
				return Error::invalid_param( 'roleAccess.administrator', 'administrator is always handled by Bricks as full access', $capability );
			}

			if ( $capability !== '' && ! isset( $allowed_caps[ $capability ] ) ) {
				return Error::invalid_param( 'roleAccess.' . $role, 'an existing builder access capability id, or empty string for no access', $capability );
			}

			$validated[ $role ] = $capability;
		}

		\Bricks\Capabilities::save_builder_capabilities( $validated );

		$after     = self::role_access_snapshot();
		$updated   = [];
		$unchanged = [];

		foreach ( $validated as $role => $capability ) {
			if ( ( $before[ $role ]['capability'] ?? '' ) === ( $after[ $role ]['capability'] ?? '' ) ) {
				$unchanged[] = $role;
			} else {
				$updated[] = $role;
			}
		}

		return [
			'updated'        => $updated,
			'unchanged'      => $unchanged,
			'beforeSnapshot' => $before,
			'roleAccess'     => $after,
		];
	}

	/**
	 * Input schema for delete-builder-capability.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_builder_capability_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'id' ],
			'properties'           => [
				'id' => [
					'type'        => 'string',
					'description' => __( 'Custom builder capability id to delete. Built-in Bricks capabilities cannot be deleted.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for delete-builder-capability.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_builder_capability_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'      => [ 'type' => 'boolean' ],
				'beforeDelete' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Delete a custom builder capability definition.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_builder_capability( $input ) {
		$id = sanitize_key( (string) ( $input['id'] ?? '' ) );

		if ( $id === '' ) {
			return Error::invalid_param( 'id', 'a non-empty custom capability id', $input['id'] ?? null );
		}

		$custom = self::get_custom_capabilities();

		if ( ! isset( $custom[ $id ] ) ) {
			return Error::not_found( 'builder_capability', $id );
		}

		$before = $custom[ $id ];

		if ( ! \Bricks\Builder_Permissions::delete_capability( $id ) ) {
			return Error::internal_error( 'delete_builder_capability', 'Unable to delete builder capability.' );
		}

		return [
			'deleted'      => true,
			'beforeDelete' => $before,
		];
	}

	/**
	 * Custom capability definitions from storage.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function get_custom_capabilities(): array {
		$custom = get_option( BRICKS_DB_CAPABILITIES_PERMISSIONS, [] );

		if ( ! is_array( $custom ) ) {
			return [];
		}

		return array_diff_key( $custom, \Bricks\Builder_Permissions::DEFAULT_CAPABILITIES );
	}

	/**
	 * Valid builder permission keys.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function valid_permission_keys(): array {
		$valid = [];

		foreach ( \Bricks\Builder_Permissions::get_sections( true ) as $section ) {
			if ( empty( $section['permissions'] ) || ! is_array( $section['permissions'] ) ) {
				continue;
			}

			foreach ( $section['permissions'] as $permission => $label ) {
				$valid[ $permission ] = true;
			}
		}

		return $valid;
	}

	/**
	 * Snapshot role access using the same choices shown in Settings > Builder access.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function role_access_snapshot(): array {
		$roles       = wp_roles()->get_names();
		$choice_caps = array_merge(
			[
				\Bricks\Capabilities::FULL_ACCESS,
				\Bricks\Capabilities::EDIT_CONTENT,
			],
			array_keys( self::get_custom_capabilities() )
		);
		$snapshot    = [];

		foreach ( $roles as $role_key => $label ) {
			$role       = get_role( $role_key );
			$capability = '';

			if ( $role ) {
				foreach ( $choice_caps as $candidate ) {
					if ( $role->has_cap( $candidate ) ) {
						$capability = $candidate;
						break;
					}
				}
			}

			if ( $role_key === 'administrator' ) {
				$capability = \Bricks\Capabilities::FULL_ACCESS;
			}

			$snapshot[ $role_key ] = [
				'label'        => translate_user_role( $label ),
				'capability'   => $capability,
				'effectiveCap' => $capability,
			];
		}

		return $snapshot;
	}
}

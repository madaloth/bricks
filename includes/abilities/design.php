<?php
/**
 * Design system abilities
 *
 * Color palettes, global classes, theme styles, components, and global variables.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Design {
	/**
	 * Stable ownership resource names.
	 */
	const COLOR_PALETTES_RESOURCE             = 'colorPalettes';
	const GLOBAL_CLASSES_RESOURCE             = 'globalClasses';
	const GLOBAL_CLASS_CATEGORIES_RESOURCE    = 'globalClassCategories';
	const GLOBAL_CLASS_LOCKS_RESOURCE         = 'globalClassLocks';
	const THEME_STYLES_RESOURCE               = 'themeStyles';
	const GLOBAL_VARIABLES_RESOURCE           = 'globalVariables';
	const GLOBAL_VARIABLE_CATEGORIES_RESOURCE = 'globalVariableCategories';

	/**
	 * Shade types supported by the Builder color-shade generator.
	 */
	const COLOR_SHADE_TYPES = [ 'light', 'dark', 'transparent' ];

	// ------------------------------------------------------------------
	// Shared schemas
	// ------------------------------------------------------------------

	/**
	 * Empty input schema (no parameters).
	 *
	 * `properties` is a `stdClass` instead of an empty array because PHP
	 * `json_encode([])` emits `[]`, but JSON Schema requires `{}` for an
	 * empty object - MCP clients reject the array form.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function empty_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Schema for an exact design-resource ownership precondition.
	 *
	 * @since 2.4
	 *
	 * @param string $description  Human-readable source guidance.
	 * @param bool   $require_item Whether itemDigest is required.
	 * @return array
	 */
	private static function ownership_input_schema( $description, $require_item = false ) {
		$required = [ 'resource', 'siteId', 'version', 'resourceDigest' ];

		if ( $require_item ) {
			$required[] = 'itemDigest';
		}

		return [
			'type'        => 'object',
			'description' => $description,
			'properties'  => [
				'resource'       => [ 'type' => 'string' ],
				'siteId'         => [ 'type' => 'integer' ],
				'version'        => [ 'type' => 'integer' ],
				'resourceDigest' => [
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				],
				'itemDigest'     => [
					'type'        => 'string',
					'description' => __( 'Required for item-level update and delete operations. Copy the complete itemOwnership envelope returned for that item.', 'bricks' ),
					'minLength'   => 64,
					'maxLength'   => 64,
				],
			],
			'required'    => $required,
		];
	}

	/**
	 * Read an option with its authority-local design-system version.
	 *
	 * @since 2.4
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Missing-row value.
	 * @return array|\WP_Error
	 */
	private static function read_owned_design_option( $option, $default = [] ) {
		return Design_Option_Store::read_versioned( $option, self::DESIGN_SYSTEM_VERSION_OPTION, $default );
	}

	/**
	 * Build an ownership envelope and optionally bind one authoritative item.
	 *
	 * @since 2.4
	 *
	 * @param string $resource Stable resource name.
	 * @param array  $snapshot Exact versioned snapshot.
	 * @param mixed  $item     Full unredacted item, or null for resource ownership.
	 * @return array|\WP_Error
	 */
	private static function design_ownership( $resource, array $snapshot, $item = null ) {
		$ownership = Design_Option_Store::ownership_envelope( $resource, $snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		if ( func_num_args() >= 3 ) {
			$ownership['itemDigest'] = Design_Option_Store::item_digest( $item );
		}

		return $ownership;
	}

	/**
	 * Bind one item digest to an already-authoritative resource envelope.
	 *
	 * @since 2.4
	 *
	 * @param array $ownership Resource ownership.
	 * @param mixed $item      Complete authoritative item.
	 * @return array
	 */
	private static function item_ownership( array $ownership, $item ) {
		$ownership['itemDigest'] = Design_Option_Store::item_digest( $item );

		return $ownership;
	}

	/**
	 * Report a successful write whose authoritative response could not be built.
	 *
	 * @since 2.4
	 *
	 * @param string         $resource       Stable resource name.
	 * @param string         $item_id        Target item ID, when applicable.
	 * @param \WP_Error|null $readback_error Readback failure.
	 * @param array|null     $live_ownership Ownership when the read succeeded.
	 * @param mixed          $live_item      Exact live item when available.
	 * @return \WP_Error
	 */
	private static function committed_design_readback_error( $resource, $item_id, $readback_error = null, $live_ownership = null, $live_item = null ) {
		return Error::internal_error(
			'design-committed-readback',
			'The design-system write committed, but its authoritative response could not be completed. Do not retry this create or mutation blindly; re-read the resource first.',
			[
				'resource'               => $resource,
				'itemId'                 => $item_id,
				'committed'              => true,
				'manualRecoveryRequired' => true,
				'readbackErrorCode'      => $readback_error instanceof \WP_Error ? $readback_error->get_error_code() : '',
				'liveOwnership'          => $live_ownership,
				'liveItem'               => $live_item,
			]
		);
	}

	/**
	 * Read color palettes from their runtime authority with an atomic version.
	 *
	 * Color palettes predate Design_Option_Store's resource routing and can be
	 * shared from the main site independently, so pin the initial read here.
	 * Subsequent owned rereads and writes stay pinned by the snapshot site ID.
	 *
	 * @since 2.4
	 *
	 * @return array|\WP_Error
	 */
	private static function read_color_palette_snapshot() {
		$shared_from_main = function_exists( 'is_multisite' )
			&& is_multisite()
			&& defined( 'BRICKS_MULTISITE_USE_MAIN_SITE_COLOR_PALETTE' )
			&& BRICKS_MULTISITE_USE_MAIN_SITE_COLOR_PALETTE;
		$current_site_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$main_site_id     = $shared_from_main && function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : $current_site_id;

		if ( ! $shared_from_main || $main_site_id === $current_site_id ) {
			return self::read_owned_design_option( BRICKS_DB_COLOR_PALETTE, [] );
		}

		if ( ! function_exists( 'switch_to_blog' ) || ! switch_to_blog( $main_site_id ) ) {
			return Error::internal_error(
				'color-palette-authority',
				'Could not switch to the main-site color-palette authority.',
				[ 'siteId' => $main_site_id ]
			);
		}

		try {
			return self::read_owned_design_option( BRICKS_DB_COLOR_PALETTE, [] );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve the effective palette list, including Bricks' unsaved defaults.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Exact palette snapshot.
	 * @return array
	 */
	private static function color_palettes_from_snapshot( array $snapshot ): array {
		$palettes = $snapshot['value'] ?? [];

		if ( ! is_array( $palettes ) || empty( $palettes ) ) {
			$palettes = \Bricks\Database::default_color_palette();
		}

		return is_array( $palettes ) ? $palettes : [];
	}

	/**
	 * Add authoritative palette and color digests to response copies.
	 *
	 * @since 2.4
	 *
	 * @param array $palettes Complete stored palettes.
	 * @return array
	 */
	private static function decorate_color_palette_digests( array $palettes, array $ownership ): array {
		foreach ( $palettes as &$palette ) {
			if ( ! is_array( $palette ) ) {
				continue;
			}

			$palette_digest = Design_Option_Store::item_digest( $palette );
			$colors         = is_array( $palette['colors'] ?? null ) ? $palette['colors'] : [];

			foreach ( $colors as &$color ) {
				if ( is_array( $color ) ) {
					$color_ownership        = self::item_ownership( $ownership, $color );
					$color['colorDigest']   = $color_ownership['itemDigest'];
					$color['itemOwnership'] = $color_ownership;
				}
			}

			unset( $color );
			$palette_ownership               = $ownership;
			$palette_ownership['itemDigest'] = $palette_digest;
			$palette['colors']               = $colors;
			$palette['paletteDigest']        = $palette_digest;
			$palette['itemOwnership']        = $palette_ownership;
		}

		unset( $palette );

		return $palettes;
	}

	/**
	 * Persist palettes through owned CAS and return a verified fresh snapshot.
	 *
	 * @since 2.4
	 *
	 * @param array         $snapshot          Exact palette before-image.
	 * @param array         $palettes          Replacement palettes.
	 * @param array         $expected          Caller ownership.
	 * @param callable|null $item_resolver     Target item resolver.
	 * @param bool          $delete_option     Whether an empty store is absent.
	 * @param array         $related_snapshots Exact related rows guarded by SQL.
	 * @return array|\WP_Error
	 */
	private static function save_color_palettes_owned( array $snapshot, array $palettes, array $expected, $item_resolver = null, bool $delete_option = false, array $related_snapshots = [] ) {
		$ownership_item = $item_resolver;
		$snapshot_value = $snapshot['value'] ?? null;

		if ( is_callable( $item_resolver ) && ( ! is_array( $snapshot_value ) || empty( $snapshot_value ) ) ) {
			$ownership_item = call_user_func( $item_resolver, self::color_palettes_from_snapshot( $snapshot ) );
		}

		$result = Design_Option_Store::compare_and_swap_owned(
			self::COLOR_PALETTES_RESOURCE,
			$snapshot,
			array_values( $palettes ),
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected,
			$ownership_item,
			$delete_option,
			$related_snapshots
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $response_snapshot ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				'',
				$response_snapshot
			);
		}

		$ownership = self::design_ownership( self::COLOR_PALETTES_RESOURCE, $response_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				'',
				$ownership
			);
		}

		$stored    = $response_snapshot['value'];
		$stored    = is_array( $stored ) ? array_values( $stored ) : [];
		$committed = is_array( $result['value'] ?? null ) ? array_values( $result['value'] ) : array_values( $palettes );

		if ( $stored !== $committed ) {
			$live_item = is_callable( $item_resolver )
				? call_user_func( $item_resolver, $stored )
				: null;

			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				'',
				null,
				$ownership,
				$live_item
			);
		}

		\Bricks\Database::$global_data['colorPalette'] = $stored;

		return [
			'changed'   => ( $result['status'] ?? '' ) !== 'no_change',
			'write'     => $result,
			'snapshot'  => $response_snapshot,
			'ownership' => $ownership,
		];
	}

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: color palette access
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function color_palette_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'edit_color_palettes' ) ) {
			return Error::forbidden_builder_permission( 'edit_color_palettes' );
		}

		return true;
	}

	/**
	 * Permission: global classes read
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function global_classes_read_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_class_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_class_manager' );
		}

		return true;
	}

	/**
	 * Permission: create global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function create_global_class_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'create_global_classes' ) ) {
			return Error::forbidden_builder_permission( 'create_global_classes' );
		}

		return true;
	}

	/**
	 * Permission: edit global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function edit_global_class_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'edit_global_classes' ) ) {
			return Error::forbidden_builder_permission( 'edit_global_classes' );
		}

		return true;
	}

	/**
	 * Permission: delete global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_global_class_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'delete_global_classes' ) ) {
			return Error::forbidden_builder_permission( 'delete_global_classes' );
		}

		return true;
	}

	/**
	 * Permission: theme styles read
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function theme_styles_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_theme_styles' ) ) {
			return Error::forbidden_builder_permission( 'access_theme_styles' );
		}

		return true;
	}

	/**
	 * Permission: components read
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function components_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! self::user_has_any_builder_permission( [ 'insert_components', 'set_component_props', 'edit_components', 'create_components', 'delete_components', 'import_export_components' ] ) ) {
			return Error::forbidden_builder_permission( 'insert_components|edit_components' );
		}

		return true;
	}

	/**
	 * Permission: create components.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function create_component_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'create_components' ) ) {
			return Error::forbidden_builder_permission( 'create_components' );
		}

		return true;
	}

	/**
	 * Permission: update components.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function update_component_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'edit_components' ) ) {
			return Error::forbidden_builder_permission( 'edit_components' );
		}

		return true;
	}

	/**
	 * Permission: delete components.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_component_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'delete_components' ) ) {
			return Error::forbidden_builder_permission( 'delete_components' );
		}

		return true;
	}

	/**
	 * Permission: extract a component from an existing element subtree.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function extract_component_permission( $input ) {
		$post_id = (int) ( $input['postId'] ?? 0 );

		if ( $post_id <= 0 ) {
			return Error::invalid_param( 'postId', 'a positive integer', $post_id );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		if ( ! \Bricks\Capabilities::current_user_can_use_builder( $post_id ) ) {
			return Error::forbidden_builder_access( $post_id );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'create_components' ) ) {
			return Error::forbidden_builder_permission( 'create_components' );
		}

		Manager::ensure_post_admin_loaded();
		$locked_by = wp_check_post_lock( $post_id );

		if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
			return Error::locked_by_other_user( $post_id, (int) $locked_by );
		}

		wp_set_post_lock( $post_id );

		return true;
	}

	/**
	 * Whether the current user has any of the provided builder permissions.
	 *
	 * @since 2.4
	 *
	 * @param string[] $permissions Permission keys.
	 * @return bool
	 */
	private static function user_has_any_builder_permission( $permissions ) {
		foreach ( $permissions as $permission ) {
			if ( \Bricks\Builder_Permissions::user_has_permission( $permission ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Permission: global variables read
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function global_variables_read_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_variable_manager' );
		}

		return true;
	}

	/**
	 * Permission: global variables write
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function global_variables_write_permission( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_variable_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_variable_manager' );
		}

		return true;
	}

	// ==================================================================
	// COLOR PALETTES
	// ==================================================================

	/**
	 * Input schema for list-color-palettes
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_color_palettes_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'paletteId' => [
						'type'        => 'string',
						'description' => __( 'Return a single palette by ID. Omit to list all palettes.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-color-palettes
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_color_palettes_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'     => [
					'type'        => 'array',
					'description' => __( 'Array of complete color palettes. Each palette and color includes a digest plus ready itemOwnership calculated from the complete authoritative record before response decoration.', 'bricks' ),
				],
				'total'     => [ 'type' => 'integer' ],
				'page'      => [ 'type' => 'integer' ],
				'perPage'   => [ 'type' => 'integer' ],
				'hasMore'   => [ 'type' => 'boolean' ],
				'ownership' => [
					'type'        => 'object',
					'description' => __( 'Compact color-palette resource ownership. Pass it unchanged for creates and as the base of item-bound update/delete ownership.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: list all color palettes
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_color_palettes( $input ) {
		Manager::flush_options_cache();
		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$palettes  = self::color_palettes_from_snapshot( $palette_snapshot );
		$ownership = self::design_ownership( self::COLOR_PALETTES_RESOURCE, $palette_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$palettes = self::decorate_color_palette_digests( $palettes, $ownership );

		// Single palette filter
		$palette_id = $input['paletteId'] ?? '';

		if ( $palette_id ) {
			foreach ( $palettes as $palette ) {
				if ( ( $palette['id'] ?? '' ) === $palette_id ) {
					$result              = Reference::paginate( [ $palette ], $input );
					$result['ownership'] = $ownership;
					return $result;
				}
			}

			return Error::not_found( 'palette', $palette_id );
		}

		$result              = Reference::paginate( $palettes, $input );
		$result['ownership'] = $ownership;

		return $result;
	}

	/**
	 * Extract a palette CSS variable name without the leading `--`.
	 *
	 * @since 2.4
	 *
	 * @param string $raw Raw palette variable value.
	 * @return string
	 */
	private static function extract_palette_variable_name( $raw ) {
		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return '';
		}

		$raw = trim( $raw );

		if ( preg_match( '/var\(\s*--([\w-]+)\s*\)/', $raw, $matches ) ) {
			return $matches[1];
		}

		if ( strpos( $raw, '--' ) === 0 ) {
			return ltrim( $raw, '-' );
		}

		return '';
	}

	/**
	 * Normalize a palette variable name the same way the builder creates
	 * modern palette color values: always store `raw` as `var(--name)`.
	 *
	 * @since 2.4
	 *
	 * @param string $raw      Incoming raw variable value/name.
	 * @param string $color_id Palette color ID.
	 * @return string
	 */
	private static function normalize_palette_color_raw( $raw, $color_id ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';

		if ( $raw === '' ) {
			return "var(--bricks-color-{$color_id})";
		}

		$normalized = strtolower( str_replace( ' ', '-', $raw ) );

		if ( preg_match( '/var\(\s*--([\w-]+)\s*\)/', $normalized, $matches ) ) {
			$normalized = $matches[1];
		}

		$normalized = preg_replace( '/[^a-z0-9-_]/i', '', $normalized );

		if ( strpos( $normalized, '--' ) !== 0 ) {
			$normalized = "--{$normalized}";
		}

		if ( $normalized === '--' ) {
			$normalized = "--bricks-color-{$color_id}";
		}

		return "var({$normalized})";
	}

	/**
	 * Validate the complete shade graph of one palette candidate.
	 *
	 * Light and dark shade rows may intentionally share a CSS variable, but a
	 * raw value must remain unique within each mode. This mirrors the Builder's
	 * paired light/dark representation while preventing ambiguous duplicate
	 * rows inside either mode.
	 *
	 * @since 2.4
	 *
	 * @param array  $colors Complete palette color candidate.
	 * @param string $param  Parameter prefix for validation errors.
	 * @return true|\WP_Error
	 */
	private static function validate_palette_shade_graph( array $colors, string $param = 'colors' ) {
		$by_id               = [];
		$seen_tuples         = [];
		$seen_raw            = [];
		$seen_raw_signatures = [];
		$root_raws           = [];
		$seen_ids            = [];

		foreach ( $colors as $index => $color ) {
			if ( ! is_array( $color ) ) {
				return Error::invalid_param( "{$param}[{$index}]", 'a palette color object', $color );
			}

			$id = (string) ( $color['id'] ?? '' );

			if ( $id !== '' ) {
				if ( isset( $seen_ids[ $id ] ) ) {
					return Error::conflict(
						'duplicate_palette_color_id',
						[
							'message'   => sprintf( 'Palette colors at %s[%d] and %s[%d] use the same ID.', $param, $seen_ids[ $id ], $param, $index ),
							'id'        => $id,
							'firstSeen' => $seen_ids[ $id ],
							'duplicate' => $index,
						]
					);
				}

				$seen_ids[ $id ] = $index;
				$by_id[ $id ]    = $color;
			}

			if ( empty( $color['parent'] ) && empty( $color['type'] ) ) {
				$root_raw = (string) ( $color['raw'] ?? '' );

				if ( $root_raw !== '' ) {
					if ( isset( $root_raws[ $root_raw ] ) ) {
						return Error::conflict(
							'duplicate_palette_color_variable',
							[
								'message'   => sprintf( 'Root palette colors at %s[%d] and %s[%d] use the same raw variable.', $param, $root_raws[ $root_raw ], $param, $index ),
								'raw'       => $root_raw,
								'firstSeen' => $root_raws[ $root_raw ],
								'duplicate' => $index,
							]
						);
					}

					$root_raws[ $root_raw ] = $index;
				}
			}
		}

		foreach ( $colors as $index => $color ) {
			$id         = (string) ( $color['id'] ?? '' );
			$has_parent = isset( $color['parent'] ) && (string) $color['parent'] !== '';
			$has_type   = isset( $color['type'] ) && (string) $color['type'] !== '';

			if ( $has_parent !== $has_type ) {
				return Error::invalid_param( "{$param}[{$index}].parent|type", 'both parent and type for palette shades, or neither for root colors', null );
			}

			if ( ! $has_parent ) {
				continue;
			}

			$parent_id = (string) $color['parent'];
			$type      = (string) $color['type'];

			if ( ! in_array( $type, self::COLOR_SHADE_TYPES, true ) ) {
				return Error::invalid_param( "{$param}[{$index}].type", 'one of: ' . implode( ', ', self::COLOR_SHADE_TYPES ), $type );
			}

			if ( $parent_id === $id ) {
				return Error::invalid_param( "{$param}[{$index}].parent", 'a different root color in the same palette', $parent_id );
			}

			$parent = $by_id[ $parent_id ] ?? null;

			if ( ! is_array( $parent ) ) {
				return Error::not_found( 'parent color', $parent_id );
			}

			if ( ! empty( $parent['parent'] ) || ! empty( $parent['type'] ) ) {
				return Error::invalid_param( "{$param}[{$index}].parent", 'a root color, not another shade', $parent_id );
			}

			if ( ! array_key_exists( 'index', $color ) || ! is_numeric( $color['index'] ) || (int) $color['index'] < 0 || (string) (int) $color['index'] !== (string) $color['index'] ) {
				return Error::invalid_param( "{$param}[{$index}].index", 'a non-negative integer for palette shades', $color['index'] ?? null );
			}

			$has_light = array_key_exists( 'light', $color ) && $color['light'] !== '';
			$has_dark  = array_key_exists( 'dark', $color ) && $color['dark'] !== '';

			if ( $has_light === $has_dark ) {
				return Error::invalid_param( "{$param}[{$index}].light|dark", 'exactly one shade mode value', null );
			}

			$mode      = $has_dark ? 'dark' : 'light';
			$tuple_key = implode( "\0", [ $parent_id, $type, (string) (int) $color['index'], $mode ] );

			if ( isset( $seen_tuples[ $tuple_key ] ) ) {
				return Error::conflict(
					'duplicate_palette_shade',
					[
						'message'   => sprintf( 'Palette shades at %s[%d] and %s[%d] use the same parent, type, index, and mode.', $param, $seen_tuples[ $tuple_key ], $param, $index ),
						'firstSeen' => $seen_tuples[ $tuple_key ],
						'duplicate' => $index,
					]
				);
			}

			$seen_tuples[ $tuple_key ] = $index;
			$raw                       = (string) ( $color['raw'] ?? '' );
			$raw_key                   = $mode . "\0" . $raw;
			$raw_signature             = implode( "\0", [ $parent_id, $type, (string) (int) $color['index'] ] );

			if ( $raw === '' ) {
				return Error::invalid_param( "{$param}[{$index}].raw", 'a non-empty palette shade variable', $raw );
			}

			if ( isset( $root_raws[ $raw ] ) ) {
				return Error::conflict(
					'duplicate_palette_shade_raw',
					[
						'message'   => sprintf( 'Palette shade at %s[%d] reuses the root variable from %s[%d].', $param, $index, $param, $root_raws[ $raw ] ),
						'raw'       => $raw,
						'firstSeen' => $root_raws[ $raw ],
						'duplicate' => $index,
					]
				);
			}

			if ( isset( $seen_raw[ $raw_key ] ) ) {
				return Error::conflict(
					'duplicate_palette_shade_raw',
					[
						'message'   => sprintf( 'Palette shades at %s[%d] and %s[%d] use the same raw variable in %s mode.', $param, $seen_raw[ $raw_key ], $param, $index, $mode ),
						'raw'       => $raw,
						'mode'      => $mode,
						'firstSeen' => $seen_raw[ $raw_key ],
						'duplicate' => $index,
					]
				);
			}

			if ( isset( $seen_raw_signatures[ $raw ] ) && $seen_raw_signatures[ $raw ]['signature'] !== $raw_signature ) {
				return Error::conflict(
					'duplicate_palette_shade_raw',
					[
						'message'   => sprintf( 'Palette shades at %s[%d] and %s[%d] reuse a raw variable without matching parent, type, and index.', $param, $seen_raw_signatures[ $raw ]['index'], $param, $index ),
						'raw'       => $raw,
						'firstSeen' => $seen_raw_signatures[ $raw ]['index'],
						'duplicate' => $index,
					]
				);
			}

			$seen_raw[ $raw_key ]        = $index;
			$seen_raw_signatures[ $raw ] = [
				'signature' => $raw_signature,
				'index'     => $index,
			];
		}

		return true;
	}

	/**
	 * Validate palette-local shade graphs and globally unique color IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $palettes Complete color-palette resource candidate.
	 * @return true|\WP_Error
	 */
	private static function validate_color_palette_resource_graph( array $palettes ) {
		$seen_ids = [];

		foreach ( $palettes as $palette_index => $palette ) {
			$palette_id = (string) ( $palette['id'] ?? '' );
			$colors     = is_array( $palette['colors'] ?? null ) ? $palette['colors'] : [];
			$valid      = self::validate_palette_shade_graph( $colors, "palettes[{$palette_index}].colors" );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			foreach ( $colors as $color_index => $color ) {
				$color_id = (string) ( $color['id'] ?? '' );

				if ( $color_id === '' ) {
					continue;
				}

				if ( isset( $seen_ids[ $color_id ] ) ) {
					return Error::conflict(
						'duplicate_palette_color_id',
						[
							'message'             => sprintf( 'Color ID "%s" is duplicated across palettes.', $color_id ),
							'id'                  => $color_id,
							'firstPaletteId'      => $seen_ids[ $color_id ]['paletteId'],
							'firstColorIndex'     => $seen_ids[ $color_id ]['colorIndex'],
							'duplicatePaletteId'  => $palette_id,
							'duplicateColorIndex' => $color_index,
						]
					);
				}

				$seen_ids[ $color_id ] = [
					'paletteId'  => $palette_id,
					'colorIndex' => $color_index,
				];
			}
		}

		return true;
	}

	/**
	 * Enforce shade raw uniqueness across every palette and global variable.
	 *
	 * The Builder represents a light/dark pair as two rows sharing one raw
	 * variable. That exact opposite-mode pair is the only allowed duplicate.
	 *
	 * @since 2.4
	 *
	 * @param array  $candidate        Candidate shade row.
	 * @param array  $palettes         Complete palette resource candidate.
	 * @param array  $variables        Authoritative global variables.
	 * @param string $candidate_palette_id Candidate palette ID.
	 * @param string $exclude_color_id Existing color ID being replaced.
	 * @return true|\WP_Error
	 */
	private static function validate_palette_shade_raw_is_unique( array $candidate, array $palettes, array $variables, string $candidate_palette_id, string $exclude_color_id = '' ) {
		$raw = (string) ( $candidate['raw'] ?? '' );

		if ( $raw === '' ) {
			return Error::invalid_param( 'raw', 'a non-empty palette shade variable', $raw );
		}

		if ( self::palette_raw_exists_in_global_variables( $raw, $variables ) ) {
			return Error::conflict(
				'duplicate_palette_color_variable',
				[
					'raw'     => $raw,
					'source'  => 'global_variables',
					'message' => sprintf( 'Palette shade variable `%s` already exists in Variable Manager.', $raw ),
				]
			);
		}

		$candidate_mode = ! empty( $candidate['dark'] ) ? 'dark' : 'light';

		foreach ( $palettes as $palette ) {
			foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : [] as $existing ) {
				$is_excluded = (string) ( $palette['id'] ?? '' ) === $candidate_palette_id && (string) ( $existing['id'] ?? '' ) === $exclude_color_id;

				if ( $is_excluded || (string) ( $existing['raw'] ?? '' ) !== $raw ) {
					continue;
				}

				$existing_mode = ! empty( $existing['dark'] ) ? 'dark' : 'light';
				$paired        =
					(string) ( $palette['id'] ?? '' ) === $candidate_palette_id &&
					! empty( $existing['parent'] ) &&
					(string) ( $existing['parent'] ?? '' ) === (string) ( $candidate['parent'] ?? '' ) &&
					(string) ( $existing['type'] ?? '' ) === (string) ( $candidate['type'] ?? '' ) &&
					(int) ( $existing['index'] ?? -1 ) === (int) ( $candidate['index'] ?? -2 ) &&
					$existing_mode !== $candidate_mode;

				if ( $paired ) {
					continue;
				}

				return Error::conflict(
					'duplicate_palette_color_variable',
					[
						'raw'             => $raw,
						'source'          => 'color_palettes',
						'existingColorId' => (string) ( $existing['id'] ?? '' ),
						'message'         => sprintf( 'Palette shade variable `%s` is already used by another palette color.', $raw ),
					]
				);
			}
		}

		return true;
	}

	/**
	 * Reject malformed values for color syntaxes the MCP parser owns.
	 *
	 * Bricks can store broader CSS values such as `var(...)`, so this does not
	 * try to validate every possible CSS color. It only blocks typo-shaped
	 * hex/rgb/hsl values that would otherwise be saved but fail later when
	 * deriving dark-mode pairs or shade scales.
	 *
	 * @since 2.4
	 *
	 * @param string $param Parameter name for error reporting.
	 * @param mixed  $value Color value.
	 * @return true|\WP_Error
	 */
	private static function validate_palette_color_value( $param, $value ) {
		if ( ! Color::has_malformed_known_syntax( $value ) ) {
			return true;
		}

		return Error::invalid_param( $param, 'a valid hex, rgb(), rgba(), hsl(), or hsla() color string', $value );
	}

	/**
	 * Check if a palette variable name already exists in Variable Manager.
	 *
	 * @since 2.4
	 *
	 * @param string $raw Normalized palette raw value.
	 * @param array  $variables Authoritative variable rows.
	 * @return bool
	 */
	private static function palette_raw_exists_in_global_variables( $raw, $variables ) {
		if ( ! is_array( $variables ) ) {
			return false;
		}

		foreach ( $variables as $variable ) {
			if ( isset( $variable['name'] ) && "var(--{$variable['name']})" === $raw ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a palette variable name already exists in any color palette.
	 *
	 * @since 2.4
	 *
	 * @param string $raw              Normalized palette raw value.
	 * @param array  $palettes         Current palettes.
	 * @param string $exclude_color_id Optional color ID to ignore.
	 * @return bool
	 */
	private static function palette_raw_exists_in_palettes( $raw, $palettes, $exclude_color_id = '' ) {
		if ( ! is_array( $palettes ) ) {
			return false;
		}

		foreach ( $palettes as $palette ) {
			if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $color ) {
				if ( $exclude_color_id && ( $color['id'] ?? '' ) === $exclude_color_id ) {
					continue;
				}

				if ( ( $color['raw'] ?? '' ) === $raw ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return a duplicate-name conflict when a palette raw value is already used.
	 *
	 * @since 2.4
	 *
	 * @param string $raw              Normalized palette raw value.
	 * @param array  $palettes         Current palettes.
	 * @param array  $variables        Authoritative variable rows.
	 * @param string $exclude_color_id Optional color ID to ignore.
	 * @return true|\WP_Error
	 */
	private static function validate_palette_raw_is_unique( $raw, $palettes, $variables, $exclude_color_id = '' ) {
		if ( self::palette_raw_exists_in_global_variables( $raw, $variables ) ) {
			return Error::conflict(
				'duplicate_palette_color_variable',
				[
					'raw'     => $raw,
					'source'  => 'global_variables',
					'message' => sprintf( 'Palette color variable `%s` already exists in Variable Manager.', $raw ),
				]
			);
		}

		if ( self::palette_raw_exists_in_palettes( $raw, $palettes, $exclude_color_id ) ) {
			return Error::conflict(
				'duplicate_palette_color_variable',
				[
					'raw'     => $raw,
					'source'  => 'color_palettes',
					'message' => sprintf( 'Palette color variable `%s` already exists in a color palette.', $raw ),
				]
			);
		}

		return true;
	}

	/**
	 * Read and bind the variable store used by palette raw-name uniqueness.
	 *
	 * @since 2.4
	 *
	 * @param array $palette_snapshot Exact palette snapshot.
	 * @return array|\WP_Error
	 */
	private static function palette_variable_uniqueness_guard( array $palette_snapshot ) {
		$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		if ( (int) $variable_snapshot['siteId'] !== (int) $palette_snapshot['siteId'] ) {
			return Error::conflict(
				'palette_variable_uniqueness_not_atomic',
				[
					'message'        => 'Palette and Variable Manager names use different site authorities, so raw-name uniqueness cannot be guarded atomically. No palette data was changed.',
					'paletteSiteId'  => (int) $palette_snapshot['siteId'],
					'variableSiteId' => (int) $variable_snapshot['siteId'],
				]
			);
		}

		return $variable_snapshot;
	}

	/**
	 * Extract unique CSS variable names emitted by palette color rows.
	 *
	 * @since 2.4
	 *
	 * @param array $colors Palette colors.
	 * @return array
	 */
	private static function palette_color_variable_names( array $colors ): array {
		$names = [];

		foreach ( $colors as $color ) {
			$name = is_array( $color ) ? self::extract_palette_variable_name( $color['raw'] ?? '' ) : '';

			if ( $name !== '' ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Collect bounded reference evidence for named palette variables.
	 *
	 * @since 2.4
	 *
	 * @param array $variable_names CSS variable names without the leading dashes.
	 * @return array|\WP_Error
	 */
	private static function palette_variable_usage_evidence( array $variable_names ) {
		if ( empty( $variable_names ) ) {
			return [
				'referenceCount'     => 0,
				'locations'          => [],
				'locationsTruncated' => false,
				'coverage'           => self::current_site_usage_coverage(),
			];
		}

		$locations = [];
		$post_ids  = array_values(
			array_unique(
				array_merge(
					\Bricks\Helpers::get_all_bricks_post_ids(),
					\Bricks\Templates::get_all_template_ids()
				)
			)
		);

		foreach ( $post_ids as $post_id ) {
			$area  = get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG
				? \Bricks\Templates::get_template_type( $post_id )
				: 'content';
			$count = self::count_css_variable_references( \Bricks\Database::get_data( $post_id, $area ), $variable_names );

			if ( $count > 0 ) {
				$locations[] = [
					'type'   => get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ? 'template' : 'post',
					'postId' => (int) $post_id,
					'area'   => $area,
					'count'  => $count,
				];
			}
		}

		$resources = [
			'globalClasses'   => BRICKS_DB_GLOBAL_CLASSES,
			'themeStyles'     => BRICKS_DB_THEME_STYLES,
			'components'      => BRICKS_DB_COMPONENTS,
			'globalVariables' => BRICKS_DB_GLOBAL_VARIABLES,
		];

		if ( defined( 'BRICKS_DB_GLOBAL_SETTINGS' ) ) {
			$resources['globalSettings'] = BRICKS_DB_GLOBAL_SETTINGS;
		}

		foreach ( $resources as $resource => $option ) {
			$snapshot = self::read_owned_design_option( $option, [] );

			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$count = self::count_css_variable_references( $snapshot['value'], $variable_names );

			if ( $count > 0 ) {
				$locations[] = [
					'type'     => 'designResource',
					'resource' => $resource,
					'siteId'   => (int) $snapshot['siteId'],
					'count'    => $count,
				];
			}
		}

		return [
			'referenceCount'     => array_sum( array_column( $locations, 'count' ) ),
			'locations'          => array_slice( $locations, 0, 100 ),
			'locationsTruncated' => count( $locations ) > 100,
			'coverage'           => self::current_site_usage_coverage(),
		];
	}

	/**
	 * Describe the bounded scope of destructive reference discovery.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function current_site_usage_coverage(): array {
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();

		return [
			'scope'           => 'currentSite',
			'siteId'          => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'networkComplete' => ! $is_multisite,
		];
	}

	/**
	 * Count CSS variable references in a nested value.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value          Nested value.
	 * @param array $variable_names CSS variable names.
	 * @return int
	 */
	private static function count_css_variable_references( $value, array $variable_names ): int {
		if ( is_string( $value ) ) {
			$count = 0;

			foreach ( $variable_names as $name ) {
				$count += preg_match_all( '/var\\(\\s*--' . preg_quote( $name, '/' ) . '\\b/', $value );
			}

			return $count;
		}

		if ( ! is_array( $value ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $value as $item ) {
			$count += self::count_css_variable_references( $item, $variable_names );
		}

		return $count;
	}

	/**
	 * Input schema for create-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_color_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'The palette ID to add the color to. If omitted, adds to the first palette.', 'bricks' ),
				],
				'light'             => [
					'type'        => 'string',
					'description' => __( 'Light mode color value (hex, rgb, etc.). REQUIRED when creating a root palette color (i.e. when neither `parent` nor `type` is set). Not in the JSON Schema `required` array because shade rows omit it.', 'bricks' ),
				],
				'dark'              => [
					'type'        => 'string',
					'description' => __( 'Dark mode color value. For root colors, this enables dark mode. For shade rows, this stores a dark-mode shade and requires a dark-mode parent.', 'bricks' ),
				],
				'raw'               => [
					'type'        => 'string',
					'description' => __( 'CSS variable reference/name. Normalized to `var(--name)`. If omitted, generated as `var(--bricks-color-{id})`.', 'bricks' ),
				],
				'darkModeEnabled'   => [
					'type'        => 'boolean',
					'description' => __( 'Enable the builder dark-mode pair for a root color. When true and dark is omitted, the dark value is derived from light using the builder HSL inversion.', 'bricks' ),
				],
				'parent'            => [
					'type'        => 'string',
					'description' => __( 'Parent root-color ID for a shade. Parent and type must be provided together.', 'bricks' ),
				],
				'type'              => [
					'type'        => 'string',
					'enum'        => self::COLOR_SHADE_TYPES,
					'description' => __( 'Shade type. One of: light, dark, transparent. Parent and type must be provided together.', 'bricks' ),
				],
				'index'             => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Required non-negative shade index for shade colors.', 'bricks' ),
				],
				'utilityClasses'    => [
					'type'        => 'array',
					'description' => __( 'Enabled color utility class groups, e.g. bg, text, border, outline, fill, stroke.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required exact color-palette resource ownership from the latest list or palette write. Color creation depends on reviewed membership, variable uniqueness, parent relationships, and order.', 'bricks' )
				),
			],
			'required'             => [ 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for create-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_color_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'color'            => [
					'type'        => 'object',
					'description' => __( 'The created color object with generated ID.', 'bricks' ),
				],
				'paletteId'        => [
					'type'        => 'string',
					'description' => __( 'The palette ID the color was added to.', 'bricks' ),
				],
				'ownership'        => [ 'type' => 'object' ],
				'colorDigest'      => [ 'type' => 'string' ],
				'paletteDigest'    => [ 'type' => 'string' ],
				'itemOwnership'    => [ 'type' => 'object' ],
				'paletteOwnership' => [ 'type' => 'object' ],
				'changed'          => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: create a color in a palette
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_color( $input ) {
		Manager::flush_options_cache();
		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette resource ownership from the latest list or write', $expected_ownership );
		}

		$palettes   = self::color_palettes_from_snapshot( $palette_snapshot );
		$palette_id = $input['paletteId'] ?? '';

		// Find target palette
		$target_index = false;

		if ( $palette_id ) {
			foreach ( $palettes as $index => $palette ) {
				if ( ( $palette['id'] ?? '' ) === $palette_id ) {
					$target_index = $index;
					break;
				}
			}

			if ( $target_index === false ) {
				return Error::not_found( 'palette', $palette_id );
			}
		} else {
			// Default to first palette
			$target_index = 0;
			$palette_id   = $palettes[0]['id'] ?? '';
		}

		$color_id       = \Bricks\Helpers::generate_random_id( false );
		$raw            = self::normalize_palette_color_raw( $input['raw'] ?? '', $color_id );
		$is_shade       = ! empty( $input['parent'] ) || ! empty( $input['type'] );
		$has_light      = isset( $input['light'] ) && $input['light'] !== '';
		$has_dark       = isset( $input['dark'] ) && $input['dark'] !== '';
		$variable_guard = self::palette_variable_uniqueness_guard( $palette_snapshot );

		if ( ! $has_light && ! $has_dark ) {
			return Error::invalid_param( 'light|dark', 'at least one CSS color string', null );
		}

		if ( ! $is_shade && ! $has_light ) {
			// `light` is conditionally required when creating a root palette color (no parent/type).
			return Error::invalid_param( 'light', 'a CSS color string (required when creating a root palette color - omit `parent`/`type` for root, set both for a shade)', $input['light'] ?? null );
		}

		foreach ( [ 'light', 'dark' ] as $color_key ) {
			if ( isset( $input[ $color_key ] ) && $input[ $color_key ] !== '' ) {
				$color_validation = self::validate_palette_color_value( $color_key, $input[ $color_key ] );

				if ( is_wp_error( $color_validation ) ) {
					return $color_validation;
				}
			}
		}

		if ( $is_shade && ( empty( $input['parent'] ) || empty( $input['type'] ) ) ) {
			return Error::invalid_param( 'parent|type', 'both parent and type for palette shades', null );
		}

		if ( $is_shade && array_key_exists( 'darkModeEnabled', $input ) ) {
			return Error::invalid_param( 'darkModeEnabled', 'only root palette colors can enable dark mode', $input['darkModeEnabled'] );
		}

		if ( $is_shade && $has_light && $has_dark ) {
			return Error::invalid_param( 'light|dark', 'separate light and dark shade rows, matching the builder', null );
		}

		if ( is_wp_error( $variable_guard ) ) {
			return $variable_guard;
		}

		if ( ! $is_shade ) {
			$raw_unique = self::validate_palette_raw_is_unique( $raw, $palettes, $variable_guard['value'] );
			if ( is_wp_error( $raw_unique ) ) {
				return $raw_unique;
			}
		}

		if ( $is_shade ) {
			$parent_color = null;
			foreach ( $palettes[ $target_index ]['colors'] ?? [] as $palette_color ) {
				if ( ( $palette_color['id'] ?? '' ) === $input['parent'] ) {
					$parent_color = $palette_color;
					break;
				}
			}

			if ( ! $parent_color ) {
				return Error::not_found( 'parent color', $input['parent'] );
			}

			if ( $has_dark && empty( $parent_color['darkModeEnabled'] ) ) {
				return Error::invalid_param( 'dark', 'only when the parent color has darkModeEnabled', $input['dark'] );
			}

			if ( empty( $input['raw'] ) ) {
				$parent_name = self::extract_palette_variable_name( $parent_color['raw'] ?? '' );
				$shade_step  = isset( $input['index'] ) ? (int) $input['index'] + 1 : 1;
				$raw         = $parent_name
					? sprintf( 'var(--%s-%s-%d)', $parent_name, substr( $input['type'], 0, 1 ), $shade_step )
					: $raw;
			}
		}

		$dark_mode_enabled = ! $is_shade && ( ! empty( $input['darkModeEnabled'] ) || $has_dark );

		if ( isset( $input['dark'] ) && array_key_exists( 'darkModeEnabled', $input ) && ! $input['darkModeEnabled'] ) {
			return Error::invalid_param( 'darkModeEnabled', 'true when dark is provided', $input['darkModeEnabled'] );
		}

		$color = [
			'id'  => $color_id,
			'raw' => $raw,
		];

		if ( $has_light ) {
			$color['light'] = $input['light'];
		}

		if ( $dark_mode_enabled ) {
			$color['darkModeEnabled'] = true;
			$color['dark']            = isset( $input['dark'] ) ? $input['dark'] : Color::derive_dark_mode_color( $input['light'] );

			if ( $color['dark'] === '' ) {
				return Error::invalid_param( 'light', 'a parseable CSS color string when darkModeEnabled is true', $input['light'] );
			}
		} elseif ( $has_dark ) {
			$color['dark'] = $input['dark'];
		}

		if ( isset( $input['parent'] ) ) {
			$color['parent'] = $input['parent'];
		}

		if ( isset( $input['type'] ) ) {
			$color['type'] = $input['type'];
		}

		if ( isset( $input['index'] ) ) {
			$color['index'] = (int) $input['index'];
		}

		if ( isset( $input['utilityClasses'] ) && is_array( $input['utilityClasses'] ) && ! empty( $input['utilityClasses'] ) ) {
			$color['utilityClasses'] = array_values( $input['utilityClasses'] );
		}

		if ( ! isset( $palettes[ $target_index ]['colors'] ) || ! is_array( $palettes[ $target_index ]['colors'] ) ) {
			$palettes[ $target_index ]['colors'] = [];
		}

		if ( $is_shade ) {
			$raw_unique = self::validate_palette_shade_raw_is_unique( $color, $palettes, $variable_guard['value'], $palette_id );

			if ( is_wp_error( $raw_unique ) ) {
				return $raw_unique;
			}
		}

		// Add to palette
		$palettes[ $target_index ]['colors'][] = $color;

		$shade_graph_valid = self::validate_color_palette_resource_graph( $palettes );

		if ( is_wp_error( $shade_graph_valid ) ) {
			return $shade_graph_valid;
		}

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			null,
			false,
			$variable_guard ? [ $variable_guard ] : []
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$saved_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $palette_id );
		$saved_color   = is_array( $saved_palette )
			? self::find_design_item( $saved_palette['colors'] ?? [], 'id', $color_id )
			: null;

		if ( ! is_array( $saved_palette ) || ! is_array( $saved_color ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				$color_id,
				null,
				$save_result['ownership'],
				$saved_color
			);
		}

		return [
			'color'            => $saved_color,
			'paletteId'        => $palette_id,
			'ownership'        => $save_result['ownership'],
			'colorDigest'      => Design_Option_Store::item_digest( $saved_color ),
			'paletteDigest'    => Design_Option_Store::item_digest( $saved_palette ),
			'itemOwnership'    => self::item_ownership( $save_result['ownership'], $saved_color ),
			'paletteOwnership' => self::item_ownership( $save_result['ownership'], $saved_palette ),
			'changed'          => $save_result['changed'],
		];
	}

	/**
	 * Input schema for update-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_color_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'colorId'           => [
					'type'        => 'string',
					'description' => __( 'The color ID to update.', 'bricks' ),
					'required'    => true,
				],
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'Optional palette ID to scope the lookup. If omitted, the color is searched across all palettes.', 'bricks' ),
				],
				'light'             => [
					'type'        => 'string',
					'description' => __( 'New light mode value (top-level convenience).', 'bricks' ),
				],
				'dark'              => [
					'type'        => 'string',
					'description' => __( 'New dark mode value (top-level convenience).', 'bricks' ),
				],
				'raw'               => [
					'type'        => 'string',
					'description' => __( 'New CSS variable reference (top-level convenience).', 'bricks' ),
				],
				'darkModeEnabled'   => [
					'type'        => 'boolean',
					'description' => __( 'Enable/disable the builder dark-mode pair. Enabling derives dark from light when dark is omitted; disabling removes dark values and dark-mode shades.', 'bricks' ),
				],
				'parent'            => [
					'type'        => 'string',
					'description' => __( 'Parent root-color ID for a shade. Parent and type must be provided together.', 'bricks' ),
				],
				'type'              => [
					'type'        => 'string',
					'enum'        => self::COLOR_SHADE_TYPES,
					'description' => __( 'Shade type. One of: light, dark, transparent. Parent and type must be provided together.', 'bricks' ),
				],
				'index'             => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Required non-negative shade index for shade colors.', 'bricks' ),
				],
				'utilityClasses'    => [
					'type'        => 'array',
					'description' => __( 'Enabled color utility class groups, e.g. bg, text, border, outline, fill, stroke.', 'bricks' ),
				],
				'color'             => [
					'type'        => 'object',
					'description' => __( 'Full nested color shape. Any of: { light, dark, raw, darkModeEnabled, parent, type, index, utilityClasses }. If both top-level and nested forms are provided, nested wins.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required color-palette ownership including this color colorDigest from the latest palette read or write. Graph-affecting changes also validate the included resourceDigest.', 'bricks' ),
					true
				),
			],
			'required'             => [ 'colorId', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for update-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_color_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'color'            => [
					'type'        => 'object',
					'description' => __( 'The updated color object.', 'bricks' ),
				],
				'ownership'        => [ 'type' => 'object' ],
				'colorDigest'      => [ 'type' => 'string' ],
				'paletteDigest'    => [ 'type' => 'string' ],
				'itemOwnership'    => [ 'type' => 'object' ],
				'paletteOwnership' => [ 'type' => 'object' ],
				'changed'          => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: update an existing color
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_color( $input ) {
		Manager::flush_options_cache();
		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette ownership including this color colorDigest', $expected_ownership );
		}

		$palettes      = self::color_palettes_from_snapshot( $palette_snapshot );
		$color_id      = $input['colorId'];
		$scope_palette = $input['paletteId'] ?? '';

		if ( ! is_array( $palettes ) || empty( $palettes ) ) {
			return Error::not_found( 'palette', $color_id );
		}

		$current_graph_valid = self::validate_color_palette_resource_graph( $palettes );

		if ( is_wp_error( $current_graph_valid ) ) {
			return $current_graph_valid;
		}

		// Build the patch: nested `color` wins over top-level convenience keys.
		$nested = ( isset( $input['color'] ) && is_array( $input['color'] ) ) ? $input['color'] : [];
		$patch  = [];

		foreach ( [ 'light', 'dark', 'raw', 'darkModeEnabled', 'parent', 'type', 'index', 'utilityClasses' ] as $key ) {
			if ( array_key_exists( $key, $nested ) ) {
				$patch[ $key ] = $nested[ $key ];
			} elseif ( array_key_exists( $key, $input ) ) {
				$patch[ $key ] = $input[ $key ];
			}
		}

		if ( empty( $patch ) ) {
			return Error::invalid_param(
				'color',
				'at least one updatable field (light, dark, raw, darkModeEnabled, parent, type, index, utilityClasses), either at the top level or under `color`',
				null
			);
		}

		if ( array_key_exists( 'raw', $patch ) ) {
			$patch['raw'] = self::normalize_palette_color_raw( $patch['raw'], $color_id );
		}
		$variable_guard = null;

		if ( array_key_exists( 'raw', $patch ) ) {
			$variable_guard = self::palette_variable_uniqueness_guard( $palette_snapshot );

			if ( is_wp_error( $variable_guard ) ) {
				return $variable_guard;
			}
		}

		foreach ( [ 'light', 'dark' ] as $color_key ) {
			if ( array_key_exists( $color_key, $patch ) && $patch[ $color_key ] !== null ) {
				$color_validation = self::validate_palette_color_value( $color_key, $patch[ $color_key ] );

				if ( is_wp_error( $color_validation ) ) {
					return $color_validation;
				}
			}
		}

		// Find the color (optionally scoped to paletteId).
		$found                = false;
		$found_palette_index  = false;
		$remove_dark_shades   = false;
		$rename_references    = [];
		$transitioned_to_root = false;

		foreach ( $palettes as $p_index => &$palette ) {
			if ( $scope_palette && ( $palette['id'] ?? '' ) !== $scope_palette ) {
				continue;
			}

			if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $c_index => &$color ) {
				if ( ( $color['id'] ?? '' ) !== $color_id ) {
					continue;
				}

				$old_raw       = $color['raw'] ?? '';
				$old_root_name = self::extract_palette_variable_name( $old_raw );
				$was_shade     = ! empty( $color['parent'] ) || ! empty( $color['type'] );

				foreach ( $patch as $key => $value ) {
					if ( $key === 'darkModeEnabled' ) {
						continue;
					}

					if ( $key === 'dark' && $value === null ) {
						unset( $color['dark'] );
						continue;
					}

					if ( $key === 'raw' ) {
						$color['raw'] = $value;
						continue;
					}

					if ( $key === 'utilityClasses' ) {
						if ( is_array( $value ) && ! empty( $value ) ) {
							$color['utilityClasses'] = array_values( $value );
						} else {
							unset( $color['utilityClasses'] );
						}
						continue;
					}

					if ( $key === 'index' && $value !== null ) {
						$color['index'] = (int) $value;
						continue;
					}

					if ( $value === null ) {
						unset( $color[ $key ] );
					} else {
						$color[ $key ] = $value;
					}
				}

				$is_shade             = ! empty( $color['parent'] ) || ! empty( $color['type'] );
				$transitioned_to_root = $was_shade && ! $is_shade;

				if ( $is_shade && array_key_exists( 'darkModeEnabled', $patch ) ) {
					return Error::invalid_param( 'darkModeEnabled', 'only root palette colors can enable dark mode', $patch['darkModeEnabled'] );
				}

				if ( $is_shade && ! empty( $color['light'] ) && ! empty( $color['dark'] ) ) {
					return Error::invalid_param( 'light|dark', 'separate light and dark shade rows, matching the builder', null );
				}

				if ( $is_shade && ! empty( $color['dark'] ) ) {
					$parent_color = null;
					foreach ( $palette['colors'] as $palette_color ) {
						if ( ( $palette_color['id'] ?? '' ) === ( $color['parent'] ?? '' ) ) {
							$parent_color = $palette_color;
							break;
						}
					}

					if ( ! $parent_color ) {
						return Error::not_found( 'parent color', $color['parent'] ?? '' );
					}

					if ( empty( $parent_color['darkModeEnabled'] ) ) {
						return Error::invalid_param( 'dark', 'only when the parent color has darkModeEnabled', $color['dark'] );
					}
				}

				if ( ! $is_shade && array_key_exists( 'dark', $patch ) && $patch['dark'] !== null ) {
					$color['darkModeEnabled'] = true;
				}

				if ( ! $is_shade && array_key_exists( 'dark', $patch ) && $patch['dark'] === null ) {
					unset( $color['darkModeEnabled'] );
					$remove_dark_shades = true;
				}

				if ( array_key_exists( 'darkModeEnabled', $patch ) && ! $is_shade ) {
					if ( $patch['darkModeEnabled'] ) {
						$color['darkModeEnabled'] = true;

						if ( empty( $color['dark'] ) ) {
							$color['dark'] = Color::derive_dark_mode_color( $color['light'] ?? '' );

							if ( $color['dark'] === '' ) {
								return Error::invalid_param( 'light', 'a parseable CSS color string when darkModeEnabled is true', $color['light'] ?? null );
							}
						}
					} else {
						unset( $color['darkModeEnabled'], $color['dark'] );
						$remove_dark_shades = true;
					}
				}

				if ( ( $is_shade || $transitioned_to_root ) && $variable_guard === null ) {
					$variable_guard = self::palette_variable_uniqueness_guard( $palette_snapshot );

					if ( is_wp_error( $variable_guard ) ) {
						return $variable_guard;
					}
				}

				if ( ! $is_shade && ( array_key_exists( 'raw', $patch ) || $transitioned_to_root ) ) {
					$raw_unique = self::validate_palette_raw_is_unique( $color['raw'] ?? '', $palettes, $variable_guard['value'], $color_id );
					if ( is_wp_error( $raw_unique ) ) {
						return $raw_unique;
					}
				}

				if ( $is_shade ) {
					$raw_unique = self::validate_palette_shade_raw_is_unique( $color, $palettes, $variable_guard['value'], (string) ( $palette['id'] ?? '' ), $color_id );

					if ( is_wp_error( $raw_unique ) ) {
						return $raw_unique;
					}
				}

				$new_root_name = self::extract_palette_variable_name( $color['raw'] ?? '' );

				if ( $old_root_name && $new_root_name && $old_root_name !== $new_root_name ) {
					$rename_references[] = [
						'oldName' => $old_root_name,
						'newName' => $new_root_name,
					];
				}

				$found               = $color;
				$found_palette_index = $p_index;
				break 2;
			}

			unset( $color );
		}

		unset( $palette );

		if ( ! $found ) {
			return Error::not_found( 'color', $color_id );
		}

		if ( $found_palette_index !== false && array_key_exists( 'raw', $patch ) ) {
			$new_root_name = self::extract_palette_variable_name( $found['raw'] ?? '' );

			foreach ( $palettes[ $found_palette_index ]['colors'] as &$palette_color ) {
				if ( ( $palette_color['parent'] ?? '' ) !== $color_id ) {
					continue;
				}

				$old_shade_name = self::extract_palette_variable_name( $palette_color['raw'] ?? '' );
				$shade_step     = 1;

				if ( ! empty( $palette_color['raw'] ) && preg_match( '/-[a-z]-([0-9]+)\)$/i', $palette_color['raw'], $matches ) ) {
					$shade_step = (int) $matches[1];
				} elseif ( isset( $palette_color['index'] ) && is_numeric( $palette_color['index'] ) ) {
					$shade_step = (int) $palette_color['index'] + 1;
				}

				if ( ! empty( $palette_color['type'] ) && $new_root_name ) {
					$palette_color['raw'] = sprintf( 'var(--%s-%s-%d)', $new_root_name, substr( $palette_color['type'], 0, 1 ), $shade_step );
				}

				$new_shade_name = self::extract_palette_variable_name( $palette_color['raw'] ?? '' );

				if ( $old_shade_name && $new_shade_name && $old_shade_name !== $new_shade_name ) {
					$rename_references[] = [
						'oldName' => $old_shade_name,
						'newName' => $new_shade_name,
					];
				}
			}
			unset( $palette_color );
		}

		if ( $remove_dark_shades && $found_palette_index !== false ) {
			$palettes[ $found_palette_index ]['colors'] = array_values(
				array_filter(
					$palettes[ $found_palette_index ]['colors'],
					static function( $color ) use ( $color_id ) {
						return ! ( ( $color['parent'] ?? '' ) === $color_id && ! empty( $color['dark'] ) );
					}
				)
			);
		}

		if ( ! empty( $rename_references ) ) {
			return Error::conflict(
				'palette_color_reference_rewrite_not_atomic',
				[
					'message'                => 'Changing this color variable would require rewriting references across posts and multiple design options. That compound rewrite is not atomic, so no palette or reference data was changed.',
					'colorId'                => $color_id,
					'referenceRenamePreview' => $rename_references,
					'paletteCommitted'       => false,
					'manualRecoveryRequired' => false,
				]
			);
		}

		$shade_graph_valid = self::validate_color_palette_resource_graph( $palettes );

		if ( is_wp_error( $shade_graph_valid ) ) {
			return $shade_graph_valid;
		}

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			static function ( $authoritative_palettes ) use ( $color_id, $scope_palette ) {
				foreach ( is_array( $authoritative_palettes ) ? $authoritative_palettes : [] as $authoritative_palette ) {
					if ( $scope_palette !== '' && ( $authoritative_palette['id'] ?? '' ) !== $scope_palette ) {
						continue;
					}

					$item = self::find_design_item( $authoritative_palette['colors'] ?? [], 'id', $color_id );

					if ( is_array( $item ) ) {
						return $item;
					}
				}

				return null;
			},
			false,
			$variable_guard ? [ $variable_guard ] : []
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$saved_color   = null;
		$saved_palette = null;

		foreach ( is_array( $save_result['snapshot']['value'] ?? null ) ? $save_result['snapshot']['value'] : [] as $palette ) {
			if ( $scope_palette !== '' && ( $palette['id'] ?? '' ) !== $scope_palette ) {
				continue;
			}

			$item = self::find_design_item( $palette['colors'] ?? [], 'id', $color_id );

			if ( is_array( $item ) ) {
				$saved_color   = $item;
				$saved_palette = $palette;
				break;
			}
		}

		if ( ! is_array( $saved_color ) || ! is_array( $saved_palette ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				$color_id,
				null,
				$save_result['ownership'],
				$saved_color
			);
		}

		return [
			'color'            => $saved_color,
			'ownership'        => $save_result['ownership'],
			'colorDigest'      => Design_Option_Store::item_digest( $saved_color ),
			'paletteDigest'    => Design_Option_Store::item_digest( $saved_palette ),
			'itemOwnership'    => self::item_ownership( $save_result['ownership'], $saved_color ),
			'paletteOwnership' => self::item_ownership( $save_result['ownership'], $saved_palette ),
			'changed'          => $save_result['changed'],
		];
	}

	// ------------------------------------------------------------------
	// bricks/create-color-palette
	// ------------------------------------------------------------------

	/**
	 * Input schema for create-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_color_palette_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'name'              => [
					'type'        => 'string',
					'description' => __( 'Display name for the palette.', 'bricks' ),
					'required'    => true,
				],
				'colors'            => [
					'type'        => 'array',
					'description' => __( 'Optional initial colors. Shade rows require paired parent/type, a root parent in this palette, an allowed type, and a non-negative index. Shade parent/type/index/mode and raw values must be unique. IDs are preserved when supplied so parent relationships survive imports; missing IDs/raw values are generated.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required exact color-palette resource ownership from the latest palette read or write. Creation validates names, color IDs, variable uniqueness, graph membership, and order against that resource.', 'bricks' )
				),
			],
			'required'             => [ 'name', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for create-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_color_palette_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'palette'         => [
					'type'        => 'object',
					'description' => __( 'The created palette ({ id, name, colors }).', 'bricks' ),
				],
				'ownership'       => [ 'type' => 'object' ],
				'paletteDigest'   => [ 'type' => 'string' ],
				'colorDigests'    => [ 'type' => 'object' ],
				'itemOwnership'   => [ 'type' => 'object' ],
				'colorOwnerships' => [ 'type' => 'object' ],
				'changed'         => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: create a color palette
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_color_palette( $input ) {
		Manager::flush_options_cache();

		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'a non-empty string', $input['name'] ?? null );
		}

		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette resource ownership from the latest list or write', $expected_ownership );
		}

		$palettes = self::color_palettes_from_snapshot( $palette_snapshot );

		// Reject duplicate palette name - matches the uniqueness rule on create_global_class.
		foreach ( $palettes as $existing_palette ) {
			if ( is_array( $existing_palette ) && ( $existing_palette['name'] ?? '' ) === $name ) {
				return Error::conflict_duplicate_name( 'color_palette', $name );
			}
		}

		$colors                       = [];
		$id_map                       = [];
		$raw_by_id                    = [];
		$root_dark_mode_enabled_by_id = [];
		$seen_root_raw                = [];
		$seen_input_ids               = [];
		$variable_guard               = null;

		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			$variable_guard = self::palette_variable_uniqueness_guard( $palette_snapshot );

			if ( is_wp_error( $variable_guard ) ) {
				return $variable_guard;
			}

			foreach ( $input['colors'] as $i => $color ) {
				if ( ! is_array( $color ) ) {
					return Error::invalid_param( "colors[{$i}]", 'a palette color object', $color );
				}

				$source_id = ! empty( $color['id'] ) ? (string) $color['id'] : "index:{$i}";

				if ( ! empty( $color['id'] ) ) {
					if ( isset( $seen_input_ids[ $source_id ] ) ) {
						return Error::conflict(
							'duplicate_palette_color_id',
							[
								'message'   => sprintf( 'Duplicate palette color id "%s" at colors[%d].', $source_id, $i ),
								'param'     => "colors[{$i}].id",
								'id'        => $source_id,
								'firstSeen' => $seen_input_ids[ $source_id ],
								'duplicate' => $i,
							]
						);
					}

					$seen_input_ids[ $source_id ] = $i;
				}

				$id_map[ $source_id ] = ! empty( $color['id'] ) ? (string) $color['id'] : \Bricks\Helpers::generate_random_id( false );
			}

			foreach ( $input['colors'] as $i => $color ) {
				$source_id = ! empty( $color['id'] ) ? (string) $color['id'] : "index:{$i}";
				$color_id  = $id_map[ $source_id ];
				$is_shade  = ! empty( $color['parent'] ) || ! empty( $color['type'] );

				if ( ! $is_shade ) {
					$raw_by_id[ $color_id ]                    = isset( $color['raw'] ) ? self::normalize_palette_color_raw( $color['raw'], $color_id ) : self::normalize_palette_color_raw( '', $color_id );
					$root_dark_mode_enabled_by_id[ $color_id ] = ! empty( $color['darkModeEnabled'] ) || ! empty( $color['dark'] );
				}
			}

			foreach ( $input['colors'] as $i => $color ) {
				$source_id = ! empty( $color['id'] ) ? (string) $color['id'] : "index:{$i}";
				$color_id  = $id_map[ $source_id ];

				$has_light = isset( $color['light'] ) && $color['light'] !== '';
				$has_dark  = isset( $color['dark'] ) && $color['dark'] !== '';

				if ( ! $has_light && ! $has_dark ) {
					return Error::invalid_param( "colors[{$i}].light|dark", 'at least one CSS color string', null );
				}

				$entry = [
					'id' => $color_id,
				];

				if ( isset( $color['light'] ) ) {
					$entry['light'] = $color['light'];
				}

				if ( isset( $color['dark'] ) ) {
					$entry['dark'] = $color['dark'];
				}

				if ( isset( $color['parent'] ) ) {
					$entry['parent'] = $id_map[ $color['parent'] ] ?? $color['parent'];
				}

				if ( isset( $color['type'] ) ) {
					$entry['type'] = $color['type'];
				}

				$is_shade = ! empty( $entry['parent'] ) || ! empty( $entry['type'] );

				if ( $is_shade && ( empty( $entry['parent'] ) || empty( $entry['type'] ) ) ) {
					return Error::invalid_param( "colors[{$i}].parent|type", 'both parent and type for palette shades', null );
				}

				if ( $is_shade && array_key_exists( 'darkModeEnabled', $color ) ) {
					return Error::invalid_param( "colors[{$i}].darkModeEnabled", 'only root palette colors can enable dark mode', $color['darkModeEnabled'] );
				}

				if ( $is_shade && $has_light && $has_dark ) {
					return Error::invalid_param( "colors[{$i}].light|dark", 'separate light and dark shade rows, matching the builder', null );
				}

				if ( ! $is_shade && ! $has_light ) {
					return Error::invalid_param( "colors[{$i}].light", 'a CSS color string for root palette colors', $entry['light'] ?? null );
				}

				foreach ( [ 'light', 'dark' ] as $color_key ) {
					if ( isset( $entry[ $color_key ] ) && $entry[ $color_key ] !== '' ) {
						$color_validation = self::validate_palette_color_value( "colors[{$i}].{$color_key}", $entry[ $color_key ] );

						if ( is_wp_error( $color_validation ) ) {
							return $color_validation;
						}
					}
				}

				if ( ! $is_shade && $has_dark && array_key_exists( 'darkModeEnabled', $color ) && ! $color['darkModeEnabled'] ) {
					return Error::invalid_param( "colors[{$i}].darkModeEnabled", 'true when dark is provided', $color['darkModeEnabled'] );
				}

				if ( $is_shade && ! isset( $raw_by_id[ $entry['parent'] ] ) ) {
					return Error::not_found( 'parent color', $entry['parent'] );
				}

				if ( $is_shade && $has_dark && empty( $root_dark_mode_enabled_by_id[ $entry['parent'] ] ) ) {
					return Error::invalid_param( "colors[{$i}].dark", 'only when the parent color has darkModeEnabled', $entry['dark'] ?? null );
				}

				if ( isset( $color['index'] ) ) {
					$entry['index'] = (int) $color['index'];
				}

				if ( isset( $color['utilityClasses'] ) && is_array( $color['utilityClasses'] ) && ! empty( $color['utilityClasses'] ) ) {
					$entry['utilityClasses'] = array_values( $color['utilityClasses'] );
				}

				if ( isset( $color['raw'] ) ) {
					$entry['raw'] = self::normalize_palette_color_raw( $color['raw'], $color_id );
				} elseif ( ! empty( $entry['parent'] ) && ! empty( $entry['type'] ) && ! empty( $raw_by_id[ $entry['parent'] ] ) ) {
					$parent_name  = self::extract_palette_variable_name( $raw_by_id[ $entry['parent'] ] );
					$shade_step   = isset( $entry['index'] ) ? (int) $entry['index'] + 1 : 1;
					$entry['raw'] = $parent_name
						? sprintf( 'var(--%s-%s-%d)', $parent_name, substr( $entry['type'], 0, 1 ), $shade_step )
						: self::normalize_palette_color_raw( '', $color_id );
				} else {
					$entry['raw'] = self::normalize_palette_color_raw( '', $color_id );
				}

				if ( ! $is_shade ) {
					$raw_unique = self::validate_palette_raw_is_unique( $entry['raw'], $palettes, $variable_guard['value'] );
					if ( is_wp_error( $raw_unique ) ) {
						return $raw_unique;
					}

					if ( in_array( $entry['raw'], $seen_root_raw, true ) ) {
						return Error::conflict(
							'duplicate_palette_color_variable',
							[
								'raw'     => $entry['raw'],
								'source'  => 'color_palette_input',
								'message' => sprintf( 'Palette color variable `%s` is duplicated in the input colors.', $entry['raw'] ),
							]
						);
					}

					$seen_root_raw[] = $entry['raw'];
				}

				if ( ( ! $is_shade && ! empty( $entry['dark'] ) ) || ! empty( $color['darkModeEnabled'] ) ) {
					$entry['darkModeEnabled'] = true;

					if ( empty( $entry['dark'] ) ) {
						$entry['dark'] = Color::derive_dark_mode_color( $entry['light'] ?? '' );

						if ( $entry['dark'] === '' ) {
							return Error::invalid_param( "colors[{$i}].light", 'a parseable CSS color string when darkModeEnabled is true', $entry['light'] ?? null );
						}
					}
				}

				$raw_by_id[ $entry['id'] ] = $entry['raw'];
				$colors[]                  = $entry;
			}
		}

		$palette = [
			'id'     => \Bricks\Helpers::generate_random_id( false ),
			'name'   => $name,
			'colors' => $colors,
		];

		$shade_graph_valid = self::validate_palette_shade_graph( $colors );

		if ( is_wp_error( $shade_graph_valid ) ) {
			return $shade_graph_valid;
		}

		foreach ( $colors as $color ) {
			if ( empty( $color['parent'] ) && empty( $color['type'] ) ) {
				continue;
			}

			$raw_unique = self::validate_palette_shade_raw_is_unique( $color, $palettes, $variable_guard['value'], (string) $palette['id'] );

			if ( is_wp_error( $raw_unique ) ) {
				return $raw_unique;
			}
		}

		$palettes[] = $palette;

		$resource_graph_valid = self::validate_color_palette_resource_graph( $palettes );

		if ( is_wp_error( $resource_graph_valid ) ) {
			return $resource_graph_valid;
		}

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			null,
			false,
			$variable_guard ? [ $variable_guard ] : []
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$saved_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $palette['id'] );

		if ( ! is_array( $saved_palette ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				(string) $palette['id'],
				null,
				$save_result['ownership'],
				$saved_palette
			);
		}

		$color_digests    = [];
		$color_ownerships = [];

		foreach ( is_array( $saved_palette['colors'] ?? null ) ? $saved_palette['colors'] : [] as $saved_color ) {
			if ( ! empty( $saved_color['id'] ) ) {
				$color_digests[ $saved_color['id'] ]    = Design_Option_Store::item_digest( $saved_color );
				$color_ownerships[ $saved_color['id'] ] = self::item_ownership( $save_result['ownership'], $saved_color );
			}
		}

		return [
			'palette'         => $saved_palette,
			'ownership'       => $save_result['ownership'],
			'paletteDigest'   => Design_Option_Store::item_digest( $saved_palette ),
			'colorDigests'    => $color_digests,
			'itemOwnership'   => self::item_ownership( $save_result['ownership'], $saved_palette ),
			'colorOwnerships' => $color_ownerships,
			'changed'         => $save_result['changed'],
		];
	}

	// ------------------------------------------------------------------
	// bricks/update-color-palette
	// ------------------------------------------------------------------

	/**
	 * Input schema for update-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_color_palette_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'The palette ID to update.', 'bricks' ),
					'required'    => true,
				],
				'name'              => [
					'type'        => 'string',
					'description' => __( 'New display name.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required color-palette ownership including this palette paletteDigest from the latest palette read or write.', 'bricks' ),
					true
				),
			],
			'required'             => [ 'paletteId', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for update-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_color_palette_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'palette'       => [
					'type'        => 'object',
					'description' => __( 'The updated palette ({ id, name, colors }).', 'bricks' ),
				],
				'ownership'     => [ 'type' => 'object' ],
				'paletteDigest' => [ 'type' => 'string' ],
				'itemOwnership' => [ 'type' => 'object' ],
				'changed'       => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: update palette metadata (rename)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_color_palette( $input ) {
		Manager::flush_options_cache();
		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette ownership including this palette paletteDigest', $expected_ownership );
		}

		$palettes   = self::color_palettes_from_snapshot( $palette_snapshot );
		$palette_id = $input['paletteId'];

		if ( ! is_array( $palettes ) || empty( $palettes ) ) {
			return Error::not_found( 'palette', $palette_id );
		}

		if ( ! array_key_exists( 'name', $input ) ) {
			return Error::invalid_param( 'name', 'a string (only updatable field today)', null );
		}

		$name = trim( (string) $input['name'] );

		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'a non-empty string', $input['name'] );
		}

		$found = null;

		foreach ( $palettes as &$palette ) {
			if ( ( $palette['id'] ?? '' ) === $palette_id ) {
				$palette['name'] = $name;
				$found           = $palette;
				break;
			}
		}

		unset( $palette );

		if ( ! $found ) {
			return Error::not_found( 'palette', $palette_id );
		}

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			static function ( $authoritative_palettes ) use ( $palette_id ) {
				return self::find_design_item( $authoritative_palettes, 'id', $palette_id );
			}
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$saved_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $palette_id );

		if ( ! is_array( $saved_palette ) ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				$palette_id,
				null,
				$save_result['ownership'],
				$saved_palette
			);
		}

		return [
			'palette'       => $saved_palette,
			'ownership'     => $save_result['ownership'],
			'paletteDigest' => Design_Option_Store::item_digest( $saved_palette ),
			'itemOwnership' => self::item_ownership( $save_result['ownership'], $saved_palette ),
			'changed'       => $save_result['changed'],
		];
	}

	// ------------------------------------------------------------------
	// bricks/delete-color
	// ------------------------------------------------------------------

	/**
	 * Input schema for delete-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_color_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'colorId'           => [
					'type'        => 'string',
					'description' => __( 'The color ID to delete.', 'bricks' ),
					'required'    => true,
				],
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'Optional palette ID to scope the lookup.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required color-palette ownership including this color colorDigest. Deleting a root also removes its shades, so the included resourceDigest protects the reviewed graph.', 'bricks' ),
					true
				),
				'allowOrphans'      => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true when the deletion removes any named CSS variable, including root shades. Fresh reference evidence is not a guarantee.', 'bricks' ),
				],
			],
			'required'             => [ 'colorId', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for delete-color
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_color_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'beforeDelete'         => [
					'type'        => 'object',
					'description' => __( 'Snapshot of the deleted color ({ id, light, dark?, raw?, paletteId, deletedShades? }). Deleting a root color also removes its shades to match the builder.', 'bricks' ),
				],
				'deleted'              => [ 'type' => 'boolean' ],
				'ownership'            => [ 'type' => 'object' ],
				'colorDigest'          => [ 'type' => 'string' ],
				'paletteDigest'        => [ 'type' => 'string' ],
				'changed'              => [ 'type' => 'boolean' ],
				'removedVariableNames' => [ 'type' => 'array' ],
				'usageEvidence'        => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: delete a single color from a palette
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_color( $input ) {
		Manager::flush_options_cache();
		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette ownership including this color colorDigest', $expected_ownership );
		}

		$palettes      = self::color_palettes_from_snapshot( $palette_snapshot );
		$color_id      = $input['colorId'];
		$scope_palette = $input['paletteId'] ?? '';

		if ( ! is_array( $palettes ) || empty( $palettes ) ) {
			return Error::not_found( 'color', $color_id );
		}

		$snapshot = null;

		foreach ( $palettes as &$palette ) {
			if ( $scope_palette && ( $palette['id'] ?? '' ) !== $scope_palette ) {
				continue;
			}

			if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $c_index => $color ) {
				if ( ( $color['id'] ?? '' ) !== $color_id ) {
					continue;
				}

				$snapshot = array_merge( $color, [ 'paletteId' => $palette['id'] ?? '' ] );
				array_splice( $palette['colors'], $c_index, 1 );
				$deleted_shades    = [];
				$palette['colors'] = array_values(
					array_filter(
						$palette['colors'],
						static function( $palette_color ) use ( $color_id, &$deleted_shades ) {
							if ( ( $palette_color['parent'] ?? '' ) === $color_id ) {
								$deleted_shades[] = $palette_color;
								return false;
							}

							return true;
						}
					)
				);

				if ( ! empty( $deleted_shades ) ) {
					$snapshot['deletedShades'] = $deleted_shades;
				}
				break 2;
			}
		}

		unset( $palette );

		if ( ! $snapshot ) {
			return Error::not_found( 'color', $color_id );
		}

		$deleted_color = $snapshot;
		unset( $deleted_color['paletteId'], $deleted_color['deletedShades'] );
		$removed_colors         = array_merge( [ $deleted_color ], $snapshot['deletedShades'] ?? [] );
		$removed_variable_names = self::palette_color_variable_names( $removed_colors );

		if ( ! empty( $removed_variable_names ) && ( $input['allowOrphans'] ?? null ) !== true ) {
			return Error::conflict(
				'palette_color_orphan_acknowledgement_required',
				[
					'message'              => 'Deleting named palette colors always requires allowOrphans=true. Fresh usage discovery is audit evidence, not a guarantee.',
					'removedVariableNames' => $removed_variable_names,
				]
			);
		}

		$usage_evidence = self::palette_variable_usage_evidence( $removed_variable_names );

		if ( is_wp_error( $usage_evidence ) ) {
			return $usage_evidence;
		}

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			static function ( $authoritative_palettes ) use ( $color_id, $scope_palette ) {
				foreach ( is_array( $authoritative_palettes ) ? $authoritative_palettes : [] as $authoritative_palette ) {
					if ( $scope_palette && ( $authoritative_palette['id'] ?? '' ) !== $scope_palette ) {
						continue;
					}

					$item = self::find_design_item( $authoritative_palette['colors'] ?? [], 'id', $color_id );

					if ( is_array( $item ) ) {
						return $item;
					}
				}

				return null;
			}
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		foreach ( is_array( $save_result['snapshot']['value'] ?? null ) ? $save_result['snapshot']['value'] : [] as $palette ) {
			$live_color = self::find_design_item( $palette['colors'] ?? [], 'id', $color_id );

			if ( $live_color !== null ) {
				return self::committed_design_readback_error(
					self::COLOR_PALETTES_RESOURCE,
					$color_id,
					null,
					$save_result['ownership'],
					$live_color
				);
			}
		}

		$saved_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $snapshot['paletteId'] ?? '' );

		return [
			'deleted'              => true,
			'beforeDelete'         => $snapshot,
			'ownership'            => $save_result['ownership'],
			'colorDigest'          => Design_Option_Store::item_digest( $deleted_color ),
			'paletteDigest'        => is_array( $saved_palette ) ? Design_Option_Store::item_digest( $saved_palette ) : '',
			'changed'              => $save_result['changed'],
			'removedVariableNames' => $removed_variable_names,
			'usageEvidence'        => $usage_evidence,
		];
	}

	/**
	 * Input schema for delete-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_color_palette_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'The palette ID to delete.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required color-palette ownership including this palette paletteDigest. The resourceDigest protects the reviewed palette graph.', 'bricks' ),
					true
				),
				'allowOrphans'      => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true when any named CSS variables are removed with the palette. Fresh reference evidence is not a guarantee.', 'bricks' ),
				],
			],
			'required'             => [ 'paletteId', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for delete-color-palette
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_color_palette_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'beforeDelete'         => [
					'type'        => 'object',
					'description' => __( 'Snapshot of the deleted palette (id, name, color count).', 'bricks' ),
				],
				'deleted'              => [ 'type' => 'boolean' ],
				'ownership'            => [ 'type' => 'object' ],
				'paletteDigest'        => [ 'type' => 'string' ],
				'changed'              => [ 'type' => 'boolean' ],
				'removedVariableNames' => [ 'type' => 'array' ],
				'usageEvidence'        => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: delete a color palette
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_color_palette( $input ) {
		Manager::flush_options_cache();

		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'color-palette ownership including this palette paletteDigest', $expected_ownership );
		}

		$palettes   = self::color_palettes_from_snapshot( $palette_snapshot );
		$palette_id = $input['paletteId'];

		if ( ! is_array( $palettes ) || empty( $palettes ) ) {
			return Error::not_found( 'palette', $palette_id );
		}

		$target_index = false;
		$snapshot     = null;
		$deleted      = null;

		foreach ( $palettes as $index => $palette ) {
			if ( ( $palette['id'] ?? '' ) === $palette_id ) {
				$target_index = $index;
				$deleted      = $palette;
				$snapshot     = [
					'id'         => $palette['id'] ?? '',
					'name'       => $palette['name'] ?? '',
					'colorCount' => isset( $palette['colors'] ) && is_array( $palette['colors'] ) ? count( $palette['colors'] ) : 0,
				];
				break;
			}
		}

		if ( $target_index === false ) {
			return Error::not_found( 'palette', $palette_id );
		}

		if ( count( $palettes ) === 1 ) {
			return Error::conflict(
				'color_palette_required',
				[
					'message'   => 'The final effective color palette cannot be deleted because Bricks immediately reactivates its built-in default. Keep one palette, or create a replacement first. No palette data was changed.',
					'paletteId' => $palette_id,
				]
			);
		}

		$removed_variable_names = self::palette_color_variable_names( $deleted['colors'] ?? [] );

		if ( ! empty( $removed_variable_names ) && ( $input['allowOrphans'] ?? null ) !== true ) {
			return Error::conflict(
				'palette_color_orphan_acknowledgement_required',
				[
					'message'              => 'Deleting a palette with named CSS variables always requires allowOrphans=true. Fresh usage discovery is audit evidence, not a guarantee.',
					'removedVariableNames' => $removed_variable_names,
				]
			);
		}

		$usage_evidence = self::palette_variable_usage_evidence( $removed_variable_names );

		if ( is_wp_error( $usage_evidence ) ) {
			return $usage_evidence;
		}

		array_splice( $palettes, $target_index, 1 );

		$save_result = self::save_color_palettes_owned(
			$palette_snapshot,
			$palettes,
			$expected_ownership,
			static function ( $authoritative_palettes ) use ( $palette_id ) {
				return self::find_design_item( $authoritative_palettes, 'id', $palette_id );
			},
			empty( $palettes ) && ! empty( $palette_snapshot['exists'] )
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$live_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $palette_id );

		if ( $live_palette !== null ) {
			return self::committed_design_readback_error(
				self::COLOR_PALETTES_RESOURCE,
				$palette_id,
				null,
				$save_result['ownership'],
				$live_palette
			);
		}

		return [
			'deleted'              => true,
			'beforeDelete'         => $snapshot,
			'ownership'            => $save_result['ownership'],
			'paletteDigest'        => Design_Option_Store::item_digest( $deleted ),
			'changed'              => $save_result['changed'],
			'removedVariableNames' => $removed_variable_names,
			'usageEvidence'        => $usage_evidence,
		];
	}

	// ==================================================================
	// GLOBAL CLASSES
	// ==================================================================

	/**
	 * Input schema for list-global-classes
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_global_classes_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'category' => [
						'type'        => 'string',
						'description' => __( 'Filter by category ID. Omit to list all classes.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-global-classes
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_global_classes_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'             => [
					'type'        => 'array',
					'description' => __( 'Array of global classes with id, name, settings, selectors, category.', 'bricks' ),
				],
				'total'             => [ 'type' => 'integer' ],
				'page'              => [ 'type' => 'integer' ],
				'perPage'           => [ 'type' => 'integer' ],
				'hasMore'           => [ 'type' => 'boolean' ],
				'categories'        => [
					'type'        => 'array',
					'description' => __( 'Array of class categories with id and name.', 'bricks' ),
				],
				'locked'            => [
					'type'        => 'array',
					'description' => __( 'Array of locked class IDs.', 'bricks' ),
				],
				'ownership'         => [
					'type'        => 'object',
					'description' => __( 'Exact global-class resource ownership. Each returned class also has an itemDigest derived from its complete authoritative row.', 'bricks' ),
				],
				'lockOwnership'     => [
					'type'        => 'object',
					'description' => __( 'Exact lock-resource ownership required together with class ownership for update and delete.', 'bricks' ),
				],
				'categoryOwnership' => [
					'type'        => 'object',
					'description' => __( 'Exact category-resource ownership required when assigning a non-empty category.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: list all global classes
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_global_classes( $input ) {
		$snapshot = self::global_classes_snapshot();

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$classes            = $snapshot['classes'];
		$categories         = $snapshot['categories'];
		$locked             = $snapshot['locked'];
		$ownership          = $snapshot['ownership'];
		$lock_ownership     = $snapshot['lockOwnership'];
		$category_ownership = $snapshot['categoryOwnership'];

		// Filter by category
		$category = $input['category'] ?? '';

		if ( $category ) {
			$classes = array_values(
				array_filter(
					$classes,
					function ( $class ) use ( $category ) {
						return ( $class['category'] ?? '' ) === $category;
					}
				)
			);
		}

		$result = Reference::paginate( $classes, $input );

		$result['categories']        = $categories;
		$result['locked']            = $locked;
		$result['ownership']         = $ownership;
		$result['lockOwnership']     = $lock_ownership;
		$result['categoryOwnership'] = $category_ownership;

		return $result;
	}

	/**
	 * Return the complete global-class authority without pagination.
	 *
	 * Workspace imports use this snapshot so class validation, candidate
	 * rendering, and ownership checks all derive from the same read.
	 *
	 * @since 2.4
	 *
	 * @return array|\WP_Error
	 */
	public static function global_classes_snapshot() {
		Manager::flush_options_cache();
		$class_snapshot    = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$category_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
		$locked_snapshot   = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );

		foreach ( [ $class_snapshot, $category_snapshot, $locked_snapshot ] as $snapshot ) {
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
		}

		$stored_classes = $class_snapshot['value'];
		$categories     = $category_snapshot['value'];
		$locked         = $locked_snapshot['value'];

		if ( ! is_array( $stored_classes ) ) {
			$stored_classes = [];
		}

		$classes            = $stored_classes;
		$ownership          = self::design_ownership( self::GLOBAL_CLASSES_RESOURCE, $class_snapshot );
		$lock_ownership     = self::design_ownership( self::GLOBAL_CLASS_LOCKS_RESOURCE, $locked_snapshot );
		$category_ownership = self::design_ownership( self::GLOBAL_CLASS_CATEGORIES_RESOURCE, $category_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		if ( is_wp_error( $lock_ownership ) ) {
			return $lock_ownership;
		}

		if ( is_wp_error( $category_ownership ) ) {
			return $category_ownership;
		}

		foreach ( $classes as &$class ) {
			$item_ownership         = self::item_ownership( $ownership, $class );
			$class['itemDigest']    = $item_ownership['itemDigest'];
			$class['itemOwnership'] = $item_ownership;
		}

		unset( $class );

		return [
			'classes'           => $classes,
			'storedClasses'     => $stored_classes,
			'categories'        => is_array( $categories ) ? $categories : [],
			'locked'            => is_array( $locked ) ? $locked : [],
			'ownership'         => $ownership,
			'lockOwnership'     => $lock_ownership,
			'categoryOwnership' => $category_ownership,
		];
	}

	/**
	 * Validate a non-empty class category against the same-read category authority.
	 *
	 * @since 2.4
	 *
	 * @param array $class_snapshot Class-store snapshot.
	 * @param array $category_ids   Categories to assign.
	 * @param mixed $expected       Expected category ownership.
	 * @return array|null|\WP_Error
	 */
	private static function global_class_category_guard( array $class_snapshot, array $category_ids, $expected ) {
		$category_ids = array_values( array_unique( array_filter( array_map( 'strval', $category_ids ) ) ) );

		if ( empty( $category_ids ) ) {
			return null;
		}

		if ( ! is_array( $expected ) ) {
			return Error::invalid_param( 'expectedCategoryOwnership', 'category ownership from the same latest class read', $expected );
		}

		$snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$valid = Design_Option_Store::validate_ownership(
			self::GLOBAL_CLASS_CATEGORIES_RESOURCE,
			$snapshot,
			$expected
		);

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$categories = is_array( $snapshot['value'] ) ? $snapshot['value'] : [];

		foreach ( $category_ids as $category_id ) {
			if ( ! is_array( self::find_design_item( $categories, 'id', $category_id ) ) ) {
				return Error::not_found( 'global_class_category', $category_id );
			}
		}

		if ( (int) $snapshot['siteId'] !== (int) $class_snapshot['siteId'] ) {
			return Error::conflict(
				'global_class_category_not_atomic',
				[
					'message'     => 'Categorized class writes cannot be made atomic because classes and categories use different site authorities. No class data was changed.',
					'categoryIds' => $category_ids,
				]
			);
		}

		$ownership = self::design_ownership( self::GLOBAL_CLASS_CATEGORIES_RESOURCE, $snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		return [
			'snapshot'  => $snapshot,
			'ownership' => $ownership,
		];
	}

	/**
	 * Refresh category ownership after a categorized class write.
	 *
	 * @since 2.4
	 *
	 * @param array|null $guard Validated category guard.
	 * @return array|null|\WP_Error
	 */
	private static function global_class_category_ownership_after_write( $guard ) {
		if ( ! is_array( $guard ) ) {
			return null;
		}

		$readback  = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_CATEGORIES, [] );
		$ownership = is_wp_error( $readback )
			? $readback
			: self::design_ownership( self::GLOBAL_CLASS_CATEGORIES_RESOURCE, $readback );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASS_CATEGORIES_RESOURCE,
				'',
				$ownership
			);
		}

		return $ownership;
	}

	/**
	 * Input schema for create-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_global_class_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'name'                      => [
					'type'        => 'string',
					'description' => __( 'CSS class name (e.g. "my-button"). Must be unique.', 'bricks' ),
					'required'    => true,
				],
				'settings'                  => [
					'type'        => 'object',
					'description' => __( 'CSS settings object. Keys follow the pattern: _controlKey, _controlKey:breakpoint, _controlKey:pseudoClass. E.g. { "_typography": { "font-size": "16px" }, "_padding": { "top": "10px" }, "_background:hover": { "color": { "hex": "#ff0000" } } }.', 'bricks' ),
				],
				'selectors'                 => [
					'type'        => 'array',
					'description' => __( 'Custom CSS sub-selectors. Each with: id, selector (e.g. "&::before"), settings, label.', 'bricks' ),
					'items'       => self::global_class_selector_schema(),
				],
				'category'                  => [
					'type'        => 'string',
					'description' => __( 'Category ID to assign. Optional. A non-empty value requires expectedCategoryOwnership from list-global-classes.', 'bricks' ),
				],
				'expectedCategoryOwnership' => self::ownership_input_schema(
					__( 'Required when category is non-empty. Copy categoryOwnership from the same latest class read.', 'bricks' )
				),
			],
			'required'   => [ 'name' ],
		];
	}

	/**
	 * Output schema for create-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_global_class_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'class'             => [
					'type'        => 'object',
					'description' => __( 'The created global class object.', 'bricks' ),
				],
				'ownership'         => [ 'type' => 'object' ],
				'itemDigest'        => [ 'type' => 'string' ],
				'itemOwnership'     => [ 'type' => 'object' ],
				'categoryOwnership' => [ 'type' => [ 'object', 'null' ] ],
			],
		];
	}

	/**
	 * Callback: create a global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_global_class( $input ) {
		$validation = self::validate_global_class_input_shape( $input, 'class' );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		Manager::flush_options_cache();
		$class_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$classes = $class_snapshot['value'];

		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$name = $input['name'];

		// Check for duplicate name
		foreach ( $classes as $existing ) {
			if ( ( $existing['name'] ?? '' ) === $name ) {
				return Error::conflict_duplicate_name( 'global_class', $name );
			}
		}

		$new_class      = self::build_global_class( $input );
		$category_guard = self::global_class_category_guard(
			$class_snapshot,
			[ (string) ( $new_class['category'] ?? '' ) ],
			$input['expectedCategoryOwnership'] ?? null
		);

		if ( is_wp_error( $category_guard ) ) {
			return $category_guard;
		}

		$classes[]          = $new_class;
		$expected_ownership = self::design_ownership( self::GLOBAL_CLASSES_RESOURCE, $class_snapshot );

		if ( is_wp_error( $expected_ownership ) ) {
			return $expected_ownership;
		}

		$save_result = self::save_global_classes_from_snapshot(
			$class_snapshot,
			$classes,
			$expected_ownership,
			null,
			$category_guard ? [ $category_guard['snapshot'] ] : []
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$authoritative_class = self::find_design_item( $save_result['snapshot']['value'] ?? [], 'id', $new_class['id'] );
		$committed_class     = self::find_design_item( $save_result['write']['value'] ?? [], 'id', $new_class['id'] );

		if (
			! is_array( $authoritative_class ) ||
			! is_array( $committed_class ) ||
			! hash_equals( Design_Option_Store::item_digest( $committed_class ), Design_Option_Store::item_digest( $authoritative_class ) )
		) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				(string) $new_class['id'],
				null,
				$save_result['ownership'],
				$authoritative_class
			);
		}

		$item_ownership     = self::item_ownership( $save_result['ownership'], $authoritative_class );
		$category_ownership = self::global_class_category_ownership_after_write( $category_guard );

		if ( is_wp_error( $category_ownership ) ) {
			return $category_ownership;
		}

		return [
			'class'             => $authoritative_class,
			'ownership'         => $save_result['ownership'],
			'itemDigest'        => $item_ownership['itemDigest'],
			'itemOwnership'     => $item_ownership,
			'categoryOwnership' => $category_ownership,
		];
	}

	/**
	 * Input schema for batch-create-global-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function batch_create_global_classes_schema() {
		$class_schema = self::create_global_class_schema();
		unset( $class_schema['properties']['name']['required'] );
		unset( $class_schema['properties']['expectedCategoryOwnership'] );
		$class_schema['properties']['id'] = [
			'type'        => 'string',
			'description' => __( 'Optional 6-character source ID to preserve, such as an ID returned by the HTML/CSS converter.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => [
				'classes'                   => [
					'type'        => 'array',
					'description' => __( 'Global classes to validate and create in one atomic option write. Pass converter `global_classes` directly to preserve their IDs and element references.', 'bricks' ),
					'items'       => $class_schema,
				],
				'expectedOwnership'         => self::ownership_input_schema(
					__( 'Required exact global-class ownership from the latest class read or write. Batch creation is a full-store append and rejects any intervening class change.', 'bricks' )
				),
				'expectedCategoryOwnership' => self::ownership_input_schema(
					__( 'Required when any class has a non-empty category. Copy categoryOwnership from the same latest class read.', 'bricks' )
				),
				'dryRun'                    => [
					'type'        => 'boolean',
					'description' => __( 'Validate and normalize every class without saving. Defaults to false.', 'bricks' ),
				],
				'returnClasses'             => [
					'type'        => 'boolean',
					'description' => __( 'Return full normalized class objects. Defaults to false to keep large conversion batches compact.', 'bricks' ),
				],
			],
			'required'   => [ 'classes', 'expectedOwnership' ],
		];
	}

	/**
	 * Output schema for batch-create-global-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function batch_create_global_classes_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'createdClassIds'               => [ 'type' => 'array' ],
				'classNameToId'                 => [ 'type' => 'object' ],
				'classCount'                    => [ 'type' => 'integer' ],
				'dryRun'                        => [ 'type' => 'boolean' ],
				'valid'                         => [ 'type' => 'boolean' ],
				'ownership'                     => [ 'type' => 'object' ],
				'itemDigests'                   => [ 'type' => 'object' ],
				'categoryOwnership'             => [ 'type' => [ 'object', 'null' ] ],
				'normalizedDynamicFontFamilies' => [ 'type' => 'integer' ],
				'classes'                       => [
					'type'        => 'array',
					'description' => __( 'Full normalized classes, only when returnClasses is true.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Create multiple global classes in one validated option write.
	 *
	 * The full batch is rejected before saving when any name or ID conflicts,
	 * so converter output never leaves a partially-created design system.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function batch_create_global_classes( $input ) {
		$batch = $input['classes'] ?? null;

		if ( ! is_array( $batch ) || empty( $batch ) ) {
			return Error::invalid_param( 'classes', 'non-empty array of global class objects', $batch );
		}

		return self::process_global_class_batch( $input, $batch, ! empty( $input['dryRun'] ) );
	}

	/**
	 * Validate and optionally persist a global-class batch.
	 *
	 * Writes use a database compare-and-swap so any global-class option change
	 * after this method's read rejects the batch instead of losing that change.
	 *
	 * @since 2.4
	 *
	 * @param array $input   Ability input.
	 * @param array $batch   Global classes to create.
	 * @param bool  $dry_run Whether to validate without saving.
	 * @return array|\WP_Error
	 */
	private static function process_global_class_batch( $input, $batch, $dry_run ) {
		Manager::flush_options_cache();
		$class_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) ) {
			return Error::invalid_param( 'expectedOwnership', 'global-class ownership envelope from the latest class read or write', $expected_ownership );
		}

		$classes = $class_snapshot['value'];

		if ( ! is_array( $classes ) ) {
			$classes = [];
		}

		$known_names = [];
		$known_ids   = [];

		foreach ( $classes as $existing ) {
			if ( ! empty( $existing['name'] ) ) {
				$known_names[ $existing['name'] ] = true;
			}

			if ( ! empty( $existing['id'] ) ) {
				$known_ids[ $existing['id'] ] = true;
			}
		}

		foreach ( $batch as $index => $class_input ) {
			if ( ! is_array( $class_input ) ) {
				return Error::invalid_param( "classes[{$index}]", 'global class object', $class_input );
			}

			$validation = self::validate_global_class_input_shape( $class_input, "classes[{$index}]" );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$name = $class_input['name'] ?? '';

			if ( isset( $known_names[ $name ] ) ) {
				return Error::conflict_duplicate_name( 'global_class', $name );
			}

			$known_names[ $name ] = true;

			if ( isset( $class_input['id'] ) ) {
				$class_id = $class_input['id'];

				if ( ! is_string( $class_id ) || strlen( $class_id ) !== 6 ) {
					return Error::invalid_param( "classes[{$index}].id", '6-character Bricks class ID', $class_id );
				}

				if ( isset( $known_ids[ $class_id ] ) ) {
					return Error::invalid_param( "classes[{$index}].id", 'unique 6-character Bricks class ID', $class_id );
				}

				$known_ids[ $class_id ] = true;
			}
		}

		$created                          = [];
		$class_name_to_id                 = [];
		$normalized_dynamic_font_families = 0;

		foreach ( $batch as $class_input ) {
			$new_class                              = self::build_global_class( $class_input, $normalized_dynamic_font_families );
			$created[]                              = $new_class;
			$class_name_to_id[ $new_class['name'] ] = $new_class['id'];
		}

		$category_guard = self::global_class_category_guard(
			$class_snapshot,
			array_column( $created, 'category' ),
			$input['expectedCategoryOwnership'] ?? null
		);

		if ( is_wp_error( $category_guard ) ) {
			return $category_guard;
		}

		$ownership_validation = Design_Option_Store::validate_ownership(
			self::GLOBAL_CLASSES_RESOURCE,
			$class_snapshot,
			$expected_ownership
		);

		if ( is_wp_error( $ownership_validation ) ) {
			return $ownership_validation;
		}

		if ( ! $dry_run ) {
			$save_result = self::save_global_classes_from_snapshot(
				$class_snapshot,
				array_merge( $classes, $created ),
				$expected_ownership,
				null,
				$category_guard ? [ $category_guard['snapshot'] ] : []
			);

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}

		$response_snapshot = $dry_run
			? $class_snapshot
			: $save_result['snapshot'];

		if ( is_wp_error( $response_snapshot ) ) {
			return $response_snapshot;
		}

		$response_ownership = self::design_ownership( self::GLOBAL_CLASSES_RESOURCE, $response_snapshot );

		if ( is_wp_error( $response_ownership ) ) {
			return $response_ownership;
		}

		$category_ownership = $dry_run
			? ( $category_guard['ownership'] ?? null )
			: self::global_class_category_ownership_after_write( $category_guard );

		if ( is_wp_error( $category_ownership ) ) {
			return $category_ownership;
		}

		$response = [
			'createdClassIds'               => array_column( $created, 'id' ),
			'classNameToId'                 => $class_name_to_id,
			'classCount'                    => count( $created ),
			'dryRun'                        => $dry_run,
			'valid'                         => true,
			'ownership'                     => $response_ownership,
			'categoryOwnership'             => $category_ownership,
			'itemDigests'                   => [],
			'normalizedDynamicFontFamilies' => $normalized_dynamic_font_families,
		];

		foreach ( $created as $class ) {
			$response['itemDigests'][ $class['id'] ] = Design_Option_Store::item_digest( $class );
		}

		if ( ! empty( $input['returnClasses'] ) ) {
			$response['classes'] = $created;
		}

		return $response;
	}

	/**
	 * Schema for one global-class selector object.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function global_class_selector_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'string' ],
				'selector' => [ 'type' => 'string' ],
				'settings' => [ 'type' => 'object' ],
				'label'    => [ 'type' => 'string' ],
			],
			'required'   => [ 'selector' ],
		];
	}

	/**
	 * Validate nested global-class data before normalization.
	 *
	 * Ability schemas are advisory for some MCP clients, so callbacks must not
	 * assume that selector rows or settings have already been type-checked.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $input Global class data.
	 * @param string $path  Parameter path used in structured errors.
	 * @return true|\WP_Error
	 */
	private static function validate_global_class_input_shape( $input, $path ) {
		if ( ! is_array( $input ) ) {
			return Error::invalid_param( $path, 'global class object', $input );
		}

		$name = $input['name'] ?? '';
		if ( ! is_string( $name ) || $name === '' ) {
			return Error::invalid_param( "{$path}.name", 'non-empty CSS class name', $name );
		}

		if ( array_key_exists( 'settings', $input ) && ! is_array( $input['settings'] ) ) {
			return Error::invalid_param( "{$path}.settings", 'global class settings object', $input['settings'] );
		}

		if ( isset( $input['settings'] ) ) {
			$validation = Style_Settings_Schema::validate_global_class_settings( $input['settings'], "{$path}.settings" );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( ! array_key_exists( 'selectors', $input ) ) {
			return true;
		}

		if ( ! is_array( $input['selectors'] ) ) {
			return Error::invalid_param( "{$path}.selectors", 'array of selector objects', $input['selectors'] );
		}

		foreach ( $input['selectors'] as $index => $selector ) {
			if ( ! is_array( $selector ) ) {
				return Error::invalid_param( "{$path}.selectors[{$index}]", 'selector object', $selector );
			}

			$selector_value = $selector['selector'] ?? '';
			if ( ! is_string( $selector_value ) || trim( $selector_value ) === '' ) {
				return Error::invalid_param( "{$path}.selectors[{$index}].selector", 'non-empty CSS selector string', $selector_value );
			}

			if ( array_key_exists( 'id', $selector ) && ! is_string( $selector['id'] ) ) {
				return Error::invalid_param( "{$path}.selectors[{$index}].id", 'selector ID string', $selector['id'] );
			}

			if ( array_key_exists( 'label', $selector ) && ! is_string( $selector['label'] ) ) {
				return Error::invalid_param( "{$path}.selectors[{$index}].label", 'selector label string', $selector['label'] );
			}

			if ( array_key_exists( 'settings', $selector ) && ! is_array( $selector['settings'] ) ) {
				return Error::invalid_param( "{$path}.selectors[{$index}].settings", 'selector settings object', $selector['settings'] );
			}

			if ( isset( $selector['settings'] ) ) {
				$validation = Style_Settings_Schema::validate_global_class_settings(
					$selector['settings'],
					"{$path}.selectors[{$index}].settings"
				);

				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		}

		return true;
	}

	/**
	 * Move variable-driven font families from native typography into scoped CSS.
	 *
	 * Bricks quotes native typography font-family values. CSS variables must stay
	 * in custom CSS so the browser, rather than Bricks, resolves the value.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings            Bricks settings.
	 * @param string $scope_selector      Persisted CSS selector for these settings.
	 * @param int    $normalization_count Number of normalized font families.
	 * @return array Normalized settings.
	 */
	private static function normalize_dynamic_font_family_settings( $settings, $scope_selector, &$normalization_count ) {
		foreach ( array_keys( $settings ) as $setting_key ) {
			if ( ! preg_match( '/^_typography(?=:|$)/', (string) $setting_key ) || ! is_array( $settings[ $setting_key ] ) ) {
				continue;
			}

			$font_family = $settings[ $setting_key ]['font-family'] ?? null;

			if ( ! is_string( $font_family ) || ! preg_match( '/\bvar\s*\(/i', $font_family ) ) {
				continue;
			}

			$font_family = trim( $font_family );
			unset( $settings[ $setting_key ]['font-family'] );

			if ( empty( $settings[ $setting_key ] ) ) {
				unset( $settings[ $setting_key ] );
			}

			$custom_context = self::dynamic_font_custom_css_context( (string) $setting_key );
			$custom_key     = $custom_context['key'];
			$selector       = $scope_selector . $custom_context['pseudo'];
			$existing_css   = isset( $settings[ $custom_key ] ) && is_string( $settings[ $custom_key ] )
				? trim( $settings[ $custom_key ] )
				: '';
			$rule_exists    = preg_match(
				'/' . preg_quote( $selector, '/' ) . '\s*\{[^}]*font-family\s*:\s*' . preg_quote( $font_family, '/' ) . '\s*;?/is',
				$existing_css
			);

			if ( ! $rule_exists ) {
				$font_rule               = "{$selector} {\n  font-family: {$font_family};\n}";
				$settings[ $custom_key ] = trim( implode( "\n", array_filter( [ $existing_css, $font_rule ] ) ) );
			}

			++$normalization_count;
		}

		return $settings;
	}

	/**
	 * Resolve the custom CSS key and pseudo suffix for a typography setting.
	 *
	 * @since 2.4
	 *
	 * @param string $setting_key Typography setting key.
	 * @return array Custom CSS key and selector pseudo suffix.
	 */
	private static function dynamic_font_custom_css_context( $setting_key ) {
		$suffix     = substr( $setting_key, strlen( '_typography' ) );
		$custom_key = '_cssCustom';

		if ( preg_match( '/^:(variant-[^:]+)(.*)$/', $suffix, $variant_match ) ) {
			return [
				'key'    => "_cssCustom:{$variant_match[1]}",
				'pseudo' => $variant_match[2],
			];
		}

		if ( class_exists( '\\Bricks\\Breakpoints' ) ) {
			foreach ( \Bricks\Breakpoints::get_breakpoints() as $breakpoint ) {
				$breakpoint_key = isset( $breakpoint['key'] ) ? (string) $breakpoint['key'] : '';

				if ( $breakpoint_key === '' || ! preg_match( '/^:' . preg_quote( $breakpoint_key, '/' ) . '(?=:|$)/', $suffix ) ) {
					continue;
				}

				$custom_key = "_cssCustom:{$breakpoint_key}";
				$suffix     = substr( $suffix, strlen( $breakpoint_key ) + 1 );
				break;
			}
		}

		return [
			'key'    => $custom_key,
			'pseudo' => $suffix,
		];
	}

	/**
	 * Build the persisted selector for a global-class selector row.
	 *
	 * @since 2.4
	 *
	 * @param string $root_selector     Root global-class selector.
	 * @param string $relative_selector Selector row value.
	 * @return string Persisted scoped selector.
	 */
	private static function global_class_selector_scope( $root_selector, $relative_selector ) {
		$relative_selector = trim( (string) $relative_selector );

		if ( strpos( $relative_selector, '&' ) !== false ) {
			return str_replace( '&', $root_selector, $relative_selector );
		}

		if ( strpos( $relative_selector, $root_selector ) === 0 ) {
			return $relative_selector;
		}

		return trim( "{$root_selector} {$relative_selector}" );
	}

	/**
	 * Build one normalized global class without saving it.
	 *
	 * @since 2.4
	 *
	 * @param array $input               Global class data.
	 * @param int   $normalization_count Number of normalized font families.
	 * @return array
	 */
	private static function build_global_class( $input, &$normalization_count = 0 ) {
		$name          = $input['name'];
		$root_selector = '.' . $name;

		$new_class = [
			'id'       => isset( $input['id'] ) ? $input['id'] : \Bricks\Helpers::generate_random_id( false ),
			'name'     => $name,
			'modified' => time(),
			'user_id'  => get_current_user_id(),
		];

		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$normalized_settings   = Element_Style_Normalizer::normalize_global_class_settings( $name, $input['settings'] );
			$new_class['settings'] = self::normalize_dynamic_font_family_settings( $normalized_settings, $root_selector, $normalization_count );
		} else {
			$new_class['settings'] = [];
		}

		if ( isset( $input['selectors'] ) && is_array( $input['selectors'] ) ) {
			foreach ( $input['selectors'] as &$selector ) {
				if ( empty( $selector['id'] ) ) {
					$selector['id'] = \Bricks\Helpers::generate_random_id( false );
				}

				if ( isset( $selector['settings'] ) && is_array( $selector['settings'] ) ) {
					$selector_scope       = self::global_class_selector_scope( $root_selector, $selector['selector'] );
					$selector['settings'] = self::normalize_dynamic_font_family_settings( $selector['settings'], $selector_scope, $normalization_count );
				}
			}

			unset( $selector );

			$new_class['selectors'] = $input['selectors'];
		}

		if ( isset( $input['category'] ) ) {
			$new_class['category'] = $input['category'];
		}

		return $new_class;
	}

	/**
	 * Input schema for update-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_global_class_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'classId'                   => [
					'type'        => 'string',
					'description' => __( 'The global class ID to update.', 'bricks' ),
					'required'    => true,
				],
				'name'                      => [
					'type'        => 'string',
					'description' => __( 'New CSS class name. Optional.', 'bricks' ),
				],
				'settings'                  => [
					'type'        => 'object',
					'description' => __( 'Settings to deep-merge with existing (only provided keys are updated). Optional.', 'bricks' ),
				],
				'selectors'                 => [
					'type'        => 'array',
					'description' => __( 'New selectors array (full replacement). Optional.', 'bricks' ),
					'items'       => self::global_class_selector_schema(),
				],
				'category'                  => [
					'type'        => 'string',
					'description' => __( 'New category ID. Optional. A non-empty value requires expectedCategoryOwnership from list-global-classes.', 'bricks' ),
				],
				'expectedCategoryOwnership' => self::ownership_input_schema(
					__( 'Required when category is non-empty. Copy categoryOwnership from the same latest class read.', 'bricks' )
				),
				'expectedOwnership'         => self::ownership_input_schema(
					__( 'Required global-class ownership including this class itemDigest from the latest class read or write.', 'bricks' ),
					true
				),
				'lockOwnership'             => self::ownership_input_schema(
					__( 'Required lock ownership from the same latest class read. The exact lock row is guarded in the final class commit.', 'bricks' )
				),
			],
			'required'   => [ 'classId', 'expectedOwnership', 'lockOwnership' ],
		];
	}

	/**
	 * Output schema for update-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_global_class_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'class'             => [
					'type'        => 'object',
					'description' => __( 'The updated global class object.', 'bricks' ),
				],
				'ownership'         => [ 'type' => 'object' ],
				'lockOwnership'     => [ 'type' => 'object' ],
				'itemDigest'        => [ 'type' => 'string' ],
				'itemOwnership'     => [ 'type' => 'object' ],
				'categoryOwnership' => [ 'type' => [ 'object', 'null' ] ],
			],
		];
	}

	/**
	 * Callback: update a global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_global_class( $input ) {
		Manager::flush_options_cache();
		$class_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$classes  = $class_snapshot['value'];
		$class_id = $input['classId'];

		if ( ! is_array( $classes ) || empty( $classes ) ) {
			return Error::not_found( 'global_class', $class_id );
		}

		// Check locked status
		$locked_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );

		if ( is_wp_error( $locked_snapshot ) ) {
			return $locked_snapshot;
		}

		$locked             = $locked_snapshot['value'];
		$expected_ownership = $input['expectedOwnership'] ?? null;
		$lock_ownership     = $input['lockOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'global-class ownership including itemDigest', $expected_ownership );
		}

		if ( ! is_array( $lock_ownership ) ) {
			return Error::invalid_param( 'lockOwnership', 'global-class lock ownership envelope', $lock_ownership );
		}

		$lock_validation = Design_Option_Store::validate_ownership(
			self::GLOBAL_CLASS_LOCKS_RESOURCE,
			$locked_snapshot,
			$lock_ownership
		);

		if ( is_wp_error( $lock_validation ) ) {
			return $lock_validation;
		}

		if ( is_array( $locked ) && in_array( $class_id, $locked, true ) ) {
			return Error::conflict(
				'global_class_locked',
				[
					'message' => sprintf( 'Global class "%s" is locked and cannot be edited.', $class_id ),
					'classId' => $class_id,
				]
			);
		}

		// Find the class
		$found_index = false;

		foreach ( $classes as $index => $class ) {
			if ( ( $class['id'] ?? '' ) === $class_id ) {
				$found_index = $index;
				break;
			}
		}

		if ( $found_index === false ) {
			return Error::not_found( 'global_class', $class_id );
		}

		$original_class = $classes[ $found_index ];
		$category_guard = self::global_class_category_guard(
			$class_snapshot,
			[ (string) ( $input['category'] ?? '' ) ],
			$input['expectedCategoryOwnership'] ?? null
		);

		if ( is_wp_error( $category_guard ) ) {
			return $category_guard;
		}

		// Check for duplicate name if renaming
		if ( isset( $input['name'] ) && $input['name'] !== $classes[ $found_index ]['name'] ) {
			foreach ( $classes as $i => $existing ) {
				if ( $i !== $found_index && ( $existing['name'] ?? '' ) === $input['name'] ) {
					return Error::conflict_duplicate_name( 'global_class', $input['name'] );
				}
			}

			$classes[ $found_index ]['name'] = $input['name'];
		}

		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$validation = Style_Settings_Schema::validate_global_class_settings(
				$input['settings'],
				'settings',
				is_array( $classes[ $found_index ]['settings'] ?? null ) ? $classes[ $found_index ]['settings'] : []
			);

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$classes[ $found_index ]['settings'] = Elements::deep_merge( $classes[ $found_index ]['settings'] ?? [], $input['settings'] );
			$classes[ $found_index ]['settings'] = Element_Style_Normalizer::normalize_global_class_settings(
				$classes[ $found_index ]['name'],
				$classes[ $found_index ]['settings']
			);
		}

		if ( isset( $input['selectors'] ) && is_array( $input['selectors'] ) ) {
			// Generate IDs for selectors that don't have one
			foreach ( $input['selectors'] as $selector_index => &$selector ) {
				if ( isset( $selector['settings'] ) && is_array( $selector['settings'] ) ) {
					$existing_selector          = self::find_design_item(
						$classes[ $found_index ]['selectors'] ?? [],
						'id',
						$selector['id'] ?? ''
					);
					$existing_selector_settings = is_array( $existing_selector['settings'] ?? null ) ? $existing_selector['settings'] : [];

					$validation = Style_Settings_Schema::validate_global_class_settings(
						$selector['settings'],
						"selectors[{$selector_index}].settings",
						$existing_selector_settings
					);

					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}

				if ( empty( $selector['id'] ) ) {
					$selector['id'] = \Bricks\Helpers::generate_random_id( false );
				}
			}

			unset( $selector );

			$classes[ $found_index ]['selectors'] = $input['selectors'];
		}

		if ( isset( $input['category'] ) ) {
			$classes[ $found_index ]['category'] = $input['category'];
		}

		if ( $classes[ $found_index ] !== $original_class ) {
			$classes[ $found_index ]['modified'] = time();
			$classes[ $found_index ]['user_id']  = get_current_user_id();
		}

		$save_result = self::save_global_classes_from_snapshot(
			$class_snapshot,
			$classes,
			$expected_ownership,
			static function ( $authoritative_classes ) use ( $class_id ) {
				return self::find_design_item( $authoritative_classes, 'id', $class_id );
			},
			array_merge( [ $locked_snapshot ], $category_guard ? [ $category_guard['snapshot'] ] : [] )
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$lock_readback           = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );
		$lock_readback_ownership = is_wp_error( $lock_readback )
			? $lock_readback
			: self::design_ownership( self::GLOBAL_CLASS_LOCKS_RESOURCE, $lock_readback );

		if ( is_wp_error( $lock_readback_ownership ) ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				$class_id,
				$lock_readback_ownership,
				$save_result['ownership']
			);
		}

		$authoritative_class = self::find_design_item( $save_result['snapshot']['value'] ?? [], 'id', $class_id );
		$committed_class     = self::find_design_item( $save_result['write']['value'] ?? [], 'id', $class_id );

		if (
			! is_array( $authoritative_class ) ||
			! is_array( $committed_class ) ||
			! hash_equals( Design_Option_Store::item_digest( $committed_class ), Design_Option_Store::item_digest( $authoritative_class ) )
		) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				$class_id,
				null,
				$save_result['ownership'],
				$authoritative_class
			);
		}

		$item_ownership     = self::item_ownership( $save_result['ownership'], $authoritative_class );
		$category_ownership = self::global_class_category_ownership_after_write( $category_guard );

		if ( is_wp_error( $category_ownership ) ) {
			return $category_ownership;
		}

		return [
			'class'             => $authoritative_class,
			'ownership'         => $save_result['ownership'],
			'lockOwnership'     => $lock_readback_ownership,
			'itemDigest'        => $item_ownership['itemDigest'],
			'itemOwnership'     => $item_ownership,
			'categoryOwnership' => $category_ownership,
		];
	}

	/**
	 * Input schema for delete-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_global_class_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'classId'           => [
					'type'        => 'string',
					'description' => __( 'The global class ID to delete.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required global-class ownership including this class itemDigest from the latest class read or write.', 'bricks' ),
					true
				),
				'lockOwnership'     => self::ownership_input_schema(
					__( 'Required lock ownership from the same latest class read. The exact lock row is guarded in the final delete.', 'bricks' )
				),
				'allowOrphans'      => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true for every deletion, even when fresh usage evidence is zero. Class reference discovery is audit evidence, not a guarantee.', 'bricks' ),
				],
			],
			'required'             => [ 'classId', 'expectedOwnership', 'lockOwnership', 'allowOrphans' ],
		];
	}

	/**
	 * Output schema for delete-global-class
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_global_class_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'beforeDelete'  => [
					'type'        => 'object',
					'description' => __( 'Complete unredacted class row that was deleted.', 'bricks' ),
				],
				'ownership'     => [ 'type' => 'object' ],
				'lockOwnership' => [ 'type' => 'object' ],
				'itemDigest'    => [ 'type' => 'string' ],
				'usageEvidence' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: delete a global class
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_global_class( $input ) {
		if ( ! array_key_exists( 'allowOrphans', $input ) ) {
			return Error::missing_param( 'allowOrphans' );
		}

		if ( $input['allowOrphans'] !== true ) {
			return Error::conflict(
				'global_class_orphan_acknowledgement_required',
				[
					'message' => 'Deleting a global class always requires allowOrphans=true. Fresh usage discovery is audit evidence, not a guarantee that no references exist.',
				]
			);
		}

		Manager::flush_options_cache();
		$class_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$classes  = $class_snapshot['value'];
		$class_id = $input['classId'];

		if ( ! is_array( $classes ) || empty( $classes ) ) {
			return Error::not_found( 'global_class', $class_id );
		}

		// Check locked status - locked classes cannot be deleted either.
		$locked_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );

		if ( is_wp_error( $locked_snapshot ) ) {
			return $locked_snapshot;
		}

		$locked             = $locked_snapshot['value'];
		$expected_ownership = $input['expectedOwnership'] ?? null;
		$lock_ownership     = $input['lockOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'global-class ownership including itemDigest', $expected_ownership );
		}

		if ( ! is_array( $lock_ownership ) ) {
			return Error::invalid_param( 'lockOwnership', 'global-class lock ownership envelope', $lock_ownership );
		}

		$lock_validation = Design_Option_Store::validate_ownership(
			self::GLOBAL_CLASS_LOCKS_RESOURCE,
			$locked_snapshot,
			$lock_ownership
		);

		if ( is_wp_error( $lock_validation ) ) {
			return $lock_validation;
		}

		if ( is_array( $locked ) && in_array( $class_id, $locked, true ) ) {
			return Error::conflict(
				'global_class_locked',
				[
					'message' => sprintf( 'Global class "%s" is locked and cannot be deleted.', $class_id ),
					'classId' => $class_id,
				]
			);
		}

		$target_index = false;
		$snapshot     = null;

		foreach ( $classes as $index => $class ) {
			if ( ( $class['id'] ?? '' ) === $class_id ) {
				$target_index = $index;
				$snapshot     = $class;
				break;
			}
		}

		if ( $target_index === false ) {
			return Error::not_found( 'global_class', $class_id );
		}

		$usage_evidence = self::global_class_usage_evidence( $class_id );

		if ( is_wp_error( $usage_evidence ) ) {
			return $usage_evidence;
		}

		array_splice( $classes, $target_index, 1 );

		$save_result = self::save_global_classes_from_snapshot(
			$class_snapshot,
			$classes,
			$expected_ownership,
			static function ( $authoritative_classes ) use ( $class_id ) {
				return self::find_design_item( $authoritative_classes, 'id', $class_id );
			},
			[ $locked_snapshot ]
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$lock_readback           = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES_LOCKED, [] );
		$lock_readback_ownership = is_wp_error( $lock_readback )
			? $lock_readback
			: self::design_ownership( self::GLOBAL_CLASS_LOCKS_RESOURCE, $lock_readback );

		if ( is_wp_error( $lock_readback_ownership ) ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				$class_id,
				$lock_readback_ownership,
				$save_result['ownership']
			);
		}

		$authoritative_class = self::find_design_item( $save_result['snapshot']['value'] ?? [], 'id', $class_id );

		if ( $authoritative_class !== null ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				$class_id,
				null,
				$save_result['ownership'],
				$authoritative_class
			);
		}

		return [
			'beforeDelete'  => $snapshot,
			'ownership'     => $save_result['ownership'],
			'lockOwnership' => $lock_readback_ownership,
			'itemDigest'    => Design_Option_Store::item_digest( $snapshot ),
			'usageEvidence' => $usage_evidence,
		];
	}

	/**
	 * Collect fresh, bounded global-class reference evidence.
	 *
	 * @since 2.4
	 *
	 * @param string $class_id Global class ID.
	 * @return array|\WP_Error
	 */
	private static function global_class_usage_evidence( string $class_id ) {
		$current_site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( Design_Option_Store::authority_site_id( BRICKS_DB_GLOBAL_CLASSES ) !== $current_site_id ) {
			return Error::conflict(
				'global_class_usage_scan_main_site_authority',
				[
					'message' => 'The class is shared from another site authority, so this subsite cannot prove a complete reference scan. Delete it from its authority context.',
					'classId' => $class_id,
				]
			);
		}

		$locations = [];
		$post_ids  = array_values(
			array_unique(
				array_merge(
					\Bricks\Helpers::get_all_bricks_post_ids(),
					\Bricks\Templates::get_all_template_ids()
				)
			)
		);

		foreach ( $post_ids as $post_id ) {
			$area  = get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG
				? \Bricks\Templates::get_template_type( $post_id )
				: 'content';
			$count = self::count_global_class_references( \Bricks\Database::get_data( $post_id, $area ), $class_id );

			if ( $count > 0 ) {
				$locations[] = [
					'type'   => get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ? 'template' : 'post',
					'postId' => (int) $post_id,
					'area'   => $area,
					'count'  => $count,
				];
			}
		}

		$component_snapshot = self::read_owned_design_option( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		foreach ( is_array( $component_snapshot['value'] ) ? $component_snapshot['value'] : [] as $component ) {
			$count = self::count_global_class_references( $component['elements'] ?? [], $class_id );

			if ( $count > 0 ) {
				$locations[] = [
					'type'        => 'component',
					'componentId' => (string) ( $component['id'] ?? '' ),
					'count'       => $count,
				];
			}
		}

		return [
			'classId'            => $class_id,
			'referenceCount'     => array_sum( array_column( $locations, 'count' ) ),
			'locations'          => array_slice( $locations, 0, 100 ),
			'locationsTruncated' => count( $locations ) > 100,
			'orphaningAccepted'  => true,
			'coverage'           => self::current_site_usage_coverage(),
		];
	}

	/**
	 * Count one class ID in nested Bricks settings.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $value    Nested value.
	 * @param string $class_id Class ID.
	 * @return int
	 */
	private static function count_global_class_references( $value, string $class_id ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $value as $key => $item ) {
			if ( $key === '_cssGlobalClasses' && is_array( $item ) ) {
				foreach ( $item as $candidate ) {
					$count += (string) $candidate === $class_id ? 1 : 0;
				}
				continue;
			}

			$count += self::count_global_class_references( $item, $class_id );
		}

		return $count;
	}

	/**
	 * Save global classes against their exact before-image.
	 *
	 * Global class timestamp and user options remain part of the Builder's
	 * notification contract, but are only updated after the guarded class write
	 * succeeds.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot          Exact global-class option snapshot.
	 * @param array $classes           Replacement global classes.
	 * @param array $expected          Exact expected resource/item ownership.
	 * @param mixed $item              Full item or resolver for item ownership.
	 * @param array $related_snapshots Exact related rows guarded by the final SQL.
	 * @return array|\WP_Error
	 */
	private static function save_global_classes_from_snapshot( array $snapshot, array $classes, array $expected, $item = null, array $related_snapshots = [] ) {
		$classes = array_values( array_filter( $classes ) );
		$result  = Design_Option_Store::compare_and_swap_owned(
			self::GLOBAL_CLASSES_RESOURCE,
			$snapshot,
			$classes,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected,
			$item,
			empty( $classes ),
			$related_snapshots
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ( $result['status'] ?? '' ) !== 'no_change' ) {
			$metadata = [
				BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP => time(),
				BRICKS_DB_GLOBAL_CLASSES_USER      => get_current_user_id(),
			];

			foreach ( $metadata as $option => $value ) {
				$metadata_result = Design_Option_Store::update_related( BRICKS_DB_GLOBAL_CLASSES, $option, $value );
				$metadata_read   = Design_Option_Store::read_related( BRICKS_DB_GLOBAL_CLASSES, $option, null );
				$metadata_saved  = ! is_wp_error( $metadata_result )
					&& ! is_wp_error( $metadata_read )
					&& array_key_exists( 'value', $metadata_read )
					// Scalar options round-trip through wp_options as strings.
					// update_option() may also return false for a truthful no-op.
					&& (string) $metadata_read['value'] === (string) $value;

				if ( ! $metadata_saved ) {
					$committed_snapshot  = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );
					$committed_ownership = is_wp_error( $committed_snapshot )
						? null
						: self::design_ownership( self::GLOBAL_CLASSES_RESOURCE, $committed_snapshot );

					return Error::internal_error(
						'global-class-metadata-partial-write',
						'The global-class data committed, but its Builder notification metadata did not. Re-read before retrying; manual metadata recovery may be required.',
						[
							'classCommitted'         => true,
							'manualRecoveryRequired' => true,
							'failedMetadataOption'   => $option,
							'ownership'              => is_wp_error( $committed_ownership ) ? null : $committed_ownership,
						]
					);
				}
			}
		}

		$response_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] );

		if ( is_wp_error( $response_snapshot ) ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				'',
				$response_snapshot
			);
		}

		$ownership = self::design_ownership( self::GLOBAL_CLASSES_RESOURCE, $response_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error(
				self::GLOBAL_CLASSES_RESOURCE,
				'',
				$ownership
			);
		}

		return [
			'write'     => $result,
			'snapshot'  => $response_snapshot,
			'ownership' => $ownership,
		];
	}

	/**
	 * Find one complete authoritative item by a stored field.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $items Collection of stored rows.
	 * @param string $field Item field.
	 * @param string $value Expected field value.
	 * @return mixed
	 */
	private static function find_design_item( $items, $field, $value ) {
		if ( ! is_array( $items ) ) {
			return null;
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) && (string) ( $item[ $field ] ?? '' ) === (string) $value ) {
				return $item;
			}
		}

		return null;
	}

	// ==================================================================
	// THEME STYLES
	// ==================================================================

	/**
	 * Get theme style conditions from the runtime storage shape.
	 *
	 * Theme_Styles::set_active_style() reads conditions from
	 * settings.conditions.conditions. Older MCP writes used a top-level
	 * conditions key, so keep that as a read-only fallback for summaries.
	 *
	 * @since 2.4
	 *
	 * @param array $style Theme style entry.
	 * @return array
	 */
	private static function get_theme_style_conditions( $style ) {
		$conditions = $style['settings']['conditions']['conditions'] ?? null;

		if ( is_array( $conditions ) ) {
			return array_values( $conditions );
		}

		$legacy_conditions = $style['conditions'] ?? null;

		return is_array( $legacy_conditions ) ? array_values( $legacy_conditions ) : [];
	}

	/**
	 * Store theme style conditions in the shape used by Bricks at render time.
	 *
	 * @since 2.4
	 *
	 * @param array $style      Theme style entry.
	 * @param array $conditions Condition rows.
	 * @return array
	 */
	private static function set_theme_style_conditions( $style, $conditions ) {
		if ( ! is_array( $style['settings'] ?? null ) ) {
			$style['settings'] = [];
		}

		if ( ! is_array( $style['settings']['conditions'] ?? null ) ) {
			$style['settings']['conditions'] = [];
		}

		$style['settings']['conditions']['conditions'] = array_values( $conditions );
		unset( $style['conditions'] );

		return $style;
	}

	/**
	 * Build a theme-style response from one authoritative post-write snapshot.
	 *
	 * @since 2.4
	 *
	 * @param string     $id             Theme style ID.
	 * @param bool       $deleted        Whether the item must now be absent.
	 * @param array|null $committed_item Exact committed item for create/update.
	 * @return array|\WP_Error
	 */
	private static function theme_style_after_write( $id, $deleted = false, $committed_item = null ) {
		$snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $snapshot ) ) {
			return self::committed_design_readback_error( self::THEME_STYLES_RESOURCE, $id, $snapshot );
		}

		$ownership = self::design_ownership( self::THEME_STYLES_RESOURCE, $snapshot );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error( self::THEME_STYLES_RESOURCE, $id, $ownership );
		}

		$styles = is_array( $snapshot['value'] ?? null ) ? $snapshot['value'] : [];
		$style  = array_key_exists( $id, $styles ) ? $styles[ $id ] : null;

		if ( $deleted ) {
			if ( $style !== null ) {
				return self::committed_design_readback_error( self::THEME_STYLES_RESOURCE, $id, null, $ownership, $style );
			}

			return [ 'ownership' => $ownership ];
		}

		if ( ! is_array( $style ) ) {
			return self::committed_design_readback_error( self::THEME_STYLES_RESOURCE, $id, null, $ownership, $style );
		}

		if (
			is_array( $committed_item ) &&
			! hash_equals( Design_Option_Store::item_digest( $committed_item ), Design_Option_Store::item_digest( $style ) )
		) {
			return self::committed_design_readback_error( self::THEME_STYLES_RESOURCE, $id, null, $ownership, $style );
		}

		$item_ownership = self::item_ownership( $ownership, $style );

		return [
			'style'         => $style,
			'ownership'     => $ownership,
			'itemDigest'    => $item_ownership['itemDigest'],
			'itemOwnership' => $item_ownership,
		];
	}

	/**
	 * Input schema for get-theme-styles
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_theme_styles_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'style' => [
					'type'        => 'string',
					'description' => __( 'Return a single theme style by ID. Omit to list all style IDs and labels (without full settings).', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for get-theme-styles
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_theme_styles_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'styles'      => [
					'type'        => [ 'object', 'array' ],
					'description' => __( 'Theme styles. With style filter: single style with full settings. Without: all styles with labels only.', 'bricks' ),
				],
				'itemDigests' => [
					'type'        => [ 'object', 'array' ],
					'description' => __( 'Digest by style ID, always calculated from each complete unredacted authoritative style, including when styles are summarized.', 'bricks' ),
				],
				'ownership'   => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: get theme styles
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_theme_styles( $input ) {
		Manager::flush_options_cache();
		$style_snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_snapshot ) ) {
			return $style_snapshot;
		}

		$styles = $style_snapshot['value'];

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		$ownership = self::design_ownership( self::THEME_STYLES_RESOURCE, $style_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$style_id = (string) ( $input['style'] ?? '' );

		// Single style: return full data
		if ( $style_id !== '' ) {
			if ( ! isset( $styles[ $style_id ] ) ) {
				return Error::not_found( 'theme_style', $style_id );
			}

			return [
				'styles'      => [ $style_id => $styles[ $style_id ] ],
				'itemDigests' => [ $style_id => Design_Option_Store::item_digest( $styles[ $style_id ] ) ],
				'ownership'   => $ownership,
			];
		}

		// No filter: return summaries only (id + label)
		$summaries    = [];
		$item_digests = [];

		foreach ( $styles as $id => $style ) {
			$summaries[ $id ]    = [
				'label'      => $style['label'] ?? $id,
				'conditions' => self::get_theme_style_conditions( $style ),
			];
			$item_digests[ $id ] = Design_Option_Store::item_digest( $style );
		}

		return [
			'styles'      => $summaries,
			'itemDigests' => $item_digests,
			'ownership'   => $ownership,
		];
	}

	// ------------------------------------------------------------------
	// bricks/list-theme-styles
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-theme-styles
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_theme_styles_schema() {
		return [
			'type'       => 'object',
			'properties' => Manager::pagination_schema_properties(),
		];
	}

	/**
	 * Output schema for list-theme-styles
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_theme_styles_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'     => [
					'type'        => 'array',
					'description' => __( 'Theme styles with id, label, condition summary, and settings group keys (no full settings).', 'bricks' ),
				],
				'total'     => [ 'type' => 'integer' ],
				'page'      => [ 'type' => 'integer' ],
				'perPage'   => [ 'type' => 'integer' ],
				'hasMore'   => [ 'type' => 'boolean' ],
				'ownership' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: list theme styles (paginated, summary only)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_theme_styles( $input ) {
		Manager::flush_options_cache();
		$style_snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_snapshot ) ) {
			return $style_snapshot;
		}

		$styles = $style_snapshot['value'];

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		$ownership = self::design_ownership( self::THEME_STYLES_RESOURCE, $style_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$items = [];

		foreach ( $styles as $id => $style ) {
			$settings_groups = [];

			if ( isset( $style['settings'] ) && is_array( $style['settings'] ) ) {
				$settings_groups = array_keys( $style['settings'] );
			}

			$items[] = [
				'id'             => (string) $id,
				'label'          => $style['label'] ?? (string) $id,
				'conditions'     => self::get_theme_style_conditions( $style ),
				'settingsGroups' => $settings_groups,
				'itemDigest'     => Design_Option_Store::item_digest( $style ),
				'itemOwnership'  => self::item_ownership( $ownership, $style ),
			];
		}

		$result = Reference::paginate( $items, $input );

		$result['ownership'] = $ownership;

		return $result;
	}

	// ------------------------------------------------------------------
	// bricks/create-theme-style
	// ------------------------------------------------------------------

	/**
	 * Input schema for create-theme-style
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_theme_style_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'label'      => [
					'type'        => 'string',
					'description' => __( 'Display label for the theme style.', 'bricks' ),
					'required'    => true,
				],
				'conditions' => [
					'type'        => 'array',
					'description' => __( 'Optional condition rules. Same shape as template conditions (e.g. `[{ main: "any" }]` for site-wide). Stored under `settings.conditions.conditions`, which is the shape Bricks reads at render time. Use `any` (NOT `entireWebsite`) and `terms` (NOT `archiveTerm`).', 'bricks' ),
				],
				'settings'   => [
					'type'        => 'object',
					'description' => __( 'Optional initial settings, grouped by section (general, typography, colors, buttons, forms, sectionContainer, sectionRows, etc.). Pass `{}` to start blank.', 'bricks' ),
				],
			],
			'required'             => [ 'label' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for create-theme-style
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_theme_style_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'string' ],
				'style'         => [
					'type'        => 'object',
					'description' => __( 'The created theme style. Conditions are persisted under `settings.conditions.conditions`.', 'bricks' ),
				],
				'ownership'     => [ 'type' => 'object' ],
				'itemDigest'    => [ 'type' => 'string' ],
				'itemOwnership' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: create theme style
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_theme_style( $input ) {
		Manager::flush_options_cache();

		$label = trim( (string) ( $input['label'] ?? '' ) );

		if ( $label === '' ) {
			return Error::invalid_param( 'label', 'a non-empty string', $input['label'] ?? null );
		}

		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$validation = Style_Settings_Schema::validate_theme_style_settings( $input['settings'] );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		$style_snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_snapshot ) ) {
			return $style_snapshot;
		}

		$styles = $style_snapshot['value'];

		if ( ! is_array( $styles ) ) {
			$styles = [];
		}

		$id = \Bricks\Helpers::generate_random_id( false );

		$style = [
			'label'    => $label,
			'settings' => is_array( $input['settings'] ?? null ) ? $input['settings'] : [],
		];

		if ( is_array( $input['conditions'] ?? null ) ) {
			$style = self::set_theme_style_conditions( $style, $input['conditions'] );
		}

		$styles[ $id ]      = $style;
		$expected_ownership = self::design_ownership( self::THEME_STYLES_RESOURCE, $style_snapshot );

		if ( is_wp_error( $expected_ownership ) ) {
			return $expected_ownership;
		}

		$save_result = Design_Option_Store::compare_and_swap_owned(
			self::THEME_STYLES_RESOURCE,
			$style_snapshot,
			$styles,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected_ownership
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$committed_style = is_array( $save_result['value'][ $id ] ?? null ) ? $save_result['value'][ $id ] : $style;
		$readback        = self::theme_style_after_write( $id, false, $committed_style );

		if ( is_wp_error( $readback ) ) {
			return $readback;
		}

		return [
			'id'            => $id,
			'style'         => $readback['style'],
			'ownership'     => $readback['ownership'],
			'itemDigest'    => $readback['itemDigest'],
			'itemOwnership' => $readback['itemOwnership'],
		];
	}

	// ------------------------------------------------------------------
	// bricks/update-theme-style
	// ------------------------------------------------------------------

	/**
	 * Input schema for update-theme-style
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_theme_style_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'                => [
					'type'        => 'string',
					'description' => __( 'The theme style ID to update.', 'bricks' ),
					'required'    => true,
				],
				'label'             => [
					'type'        => 'string',
					'description' => __( 'New display label.', 'bricks' ),
				],
				'conditions'        => [
					'type'        => 'array',
					'description' => __( 'Replace conditions array. Pass omitted to leave unchanged.', 'bricks' ),
				],
				'settings'          => [
					'type'        => 'object',
					'description' => __( 'Settings patch. Deep-merged into the existing settings; pass `null` at any leaf to clear it, or omit `settings` entirely to leave the existing values untouched.', 'bricks' ),
				],
				'replace'           => [
					'type'        => 'boolean',
					'description' => __( 'If true, `settings` REPLACES the entire settings tree instead of merging. Default false.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required theme-style ownership including this style itemDigest from the latest theme-style read or write.', 'bricks' ),
					true
				),
			],
			'required'             => [ 'id', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for update-theme-style
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_theme_style_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'string' ],
				'style'          => [
					'type'        => 'object',
					'description' => __( 'The updated theme style.', 'bricks' ),
				],
				'ownership'      => [ 'type' => 'object' ],
				'itemDigest'     => [ 'type' => 'string' ],
				'impactEvidence' => [ 'type' => 'object' ],
				'itemOwnership'  => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: update theme style
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_theme_style( $input ) {
		Manager::flush_options_cache();

		$id             = (string) $input['id'];
		$style_snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_snapshot ) ) {
			return $style_snapshot;
		}

		$styles = $style_snapshot['value'];

		if ( ! is_array( $styles ) || ! isset( $styles[ $id ] ) ) {
			return Error::not_found( 'theme_style', $id );
		}

		$style = $styles[ $id ];

		if ( array_key_exists( 'label', $input ) ) {
			$label = trim( (string) $input['label'] );

			if ( $label === '' ) {
				return Error::invalid_param( 'label', 'a non-empty string', $input['label'] );
			}

			$style['label'] = $label;
		}

		if ( array_key_exists( 'conditions', $input ) ) {
			if ( ! is_array( $input['conditions'] ) ) {
				return Error::invalid_param( 'conditions', 'an array of condition objects', $input['conditions'] );
			}

			$style = self::set_theme_style_conditions( $style, $input['conditions'] );
		}

		if ( array_key_exists( 'settings', $input ) ) {
			if ( ! is_array( $input['settings'] ) ) {
				return Error::invalid_param( 'settings', 'an object', $input['settings'] );
			}

			$existing_settings = is_array( $style['settings'] ?? null ) ? $style['settings'] : [];
			$validation        = Style_Settings_Schema::validate_theme_style_settings(
				$input['settings'],
				'settings',
				$existing_settings
			);

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$replace = ! empty( $input['replace'] );

			if ( $replace ) {
				$existing_conditions = $style['settings']['conditions'] ?? null;
				$style['settings']   = $input['settings'];

				if ( ! array_key_exists( 'conditions', $input ) && ! isset( $style['settings']['conditions'] ) && is_array( $existing_conditions ) ) {
					$style['settings']['conditions'] = $existing_conditions;
				}
			} else {
				$existing          = is_array( $style['settings'] ?? null ) ? $style['settings'] : [];
				$style['settings'] = Elements::deep_merge( $existing, $input['settings'] );
			}
		}

		$styles[ $id ]      = $style;
		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'theme-style ownership including itemDigest', $expected_ownership );
		}

		$save_result = Design_Option_Store::compare_and_swap_owned(
			self::THEME_STYLES_RESOURCE,
			$style_snapshot,
			$styles,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected_ownership,
			static function ( $authoritative_styles ) use ( $id ) {
				return is_array( $authoritative_styles ) && array_key_exists( $id, $authoritative_styles )
					? $authoritative_styles[ $id ]
					: null;
			}
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$committed_style = is_array( $save_result['value'][ $id ] ?? null ) ? $save_result['value'][ $id ] : $style;
		$readback        = self::theme_style_after_write( $id, false, $committed_style );

		if ( is_wp_error( $readback ) ) {
			return $readback;
		}

		return [
			'id'            => $id,
			'style'         => $readback['style'],
			'ownership'     => $readback['ownership'],
			'itemDigest'    => $readback['itemDigest'],
			'itemOwnership' => $readback['itemOwnership'],
		];
	}

	// ------------------------------------------------------------------
	// bricks/delete-theme-style
	// ------------------------------------------------------------------

	/**
	 * Input schema for delete-theme-style.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_theme_style_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'                      => [
					'type'        => 'string',
					'description' => __( 'Theme style ID to delete.', 'bricks' ),
					'required'    => true,
				],
				'expectedOwnership'       => self::ownership_input_schema(
					__( 'Required theme-style ownership including this style itemDigest from the latest theme-style read or write.', 'bricks' ),
					true
				),
				'acknowledgeStyleRemoval' => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true when the style has settings or conditions. The response includes fresh bounded impact evidence.', 'bricks' ),
				],
			],
			'required'             => [ 'id', 'expectedOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for delete-theme-style.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_theme_style_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'      => [ 'type' => 'boolean' ],
				'id'           => [ 'type' => 'string' ],
				'beforeDelete' => [
					'type'        => 'object',
					'description' => __( 'Snapshot of the removed theme style.', 'bricks' ),
				],
				'ownership'    => [ 'type' => 'object' ],
				'itemDigest'   => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Callback: delete theme style.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_theme_style( $input ) {
		Manager::flush_options_cache();

		$id             = (string) $input['id'];
		$style_snapshot = self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_snapshot ) ) {
			return $style_snapshot;
		}

		$styles = $style_snapshot['value'];

		if ( ! is_array( $styles ) || ! isset( $styles[ $id ] ) ) {
			return Error::not_found( 'theme_style', $id );
		}

		$before_delete      = $styles[ $id ];
		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'theme-style ownership including itemDigest', $expected_ownership );
		}

		$impact_evidence = self::theme_style_removal_impact_evidence( $before_delete );

		if ( $impact_evidence['requiresAcknowledgement'] && ( $input['acknowledgeStyleRemoval'] ?? null ) !== true ) {
			return Error::conflict(
				'theme_style_removal_acknowledgement_required',
				[
					'message'        => 'Deleting a theme style with settings or conditions requires acknowledgeStyleRemoval=true. No theme-style data was changed.',
					'styleId'        => $id,
					'impactEvidence' => $impact_evidence,
				]
			);
		}

		unset( $styles[ $id ] );

		$save_result = Design_Option_Store::compare_and_swap_owned(
			self::THEME_STYLES_RESOURCE,
			$style_snapshot,
			$styles,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected_ownership,
			static function ( $authoritative_styles ) use ( $id ) {
				return is_array( $authoritative_styles ) && array_key_exists( $id, $authoritative_styles )
					? $authoritative_styles[ $id ]
					: null;
			},
			empty( $styles )
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$readback = self::theme_style_after_write( $id, true );

		if ( is_wp_error( $readback ) ) {
			return $readback;
		}

		return [
			'deleted'        => true,
			'id'             => $id,
			'beforeDelete'   => $before_delete,
			'ownership'      => $readback['ownership'],
			'itemDigest'     => Design_Option_Store::item_digest( $before_delete ),
			'impactEvidence' => $impact_evidence,
		];
	}

	/**
	 * Summarize the bounded impact of removing one authoritative theme style.
	 *
	 * @since 2.4
	 *
	 * @param array $style Complete theme-style row.
	 * @return array
	 */
	private static function theme_style_removal_impact_evidence( array $style ): array {
		$settings   = is_array( $style['settings'] ?? null ) ? $style['settings'] : [];
		$conditions = self::get_theme_style_conditions( $style );
		$conditions = is_array( $conditions ) ? $conditions : [];

		return [
			'requiresAcknowledgement' => ! empty( $settings ) || ! empty( $conditions ),
			'conditionCount'          => count( $conditions ),
			'conditions'              => array_slice( $conditions, 0, 25 ),
			'conditionsTruncated'     => count( $conditions ) > 25,
			'settingsKeyCount'        => count( $settings ),
			'settingsKeys'            => array_slice( array_keys( $settings ), 0, 50 ),
			'settingsKeysTruncated'   => count( $settings ) > 50,
			'settingsDigest'          => Design_Option_Store::item_digest( $settings ),
		];
	}

	// ==================================================================
	// COMPONENTS
	// ==================================================================

	/**
	 * Input schema for list-components
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_components_schema() {
		return [
			'type'       => 'object',
			'properties' => Manager::pagination_schema_properties(),
		];
	}

	/**
	 * Output schema for list-components
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_components_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'               => [
					'type'        => 'array',
					'description' => __( 'Array of component summaries with id, label, desc, category, properties, variants, slot count, element count, and componentDigest for safe writes.', 'bricks' ),
				],
				'total'               => [ 'type' => 'integer' ],
				'page'                => [ 'type' => 'integer' ],
				'perPage'             => [ 'type' => 'integer' ],
				'hasMore'             => [ 'type' => 'boolean' ],
				'designSystemVersion' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: list all components (summaries)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_components( $input ) {
		Manager::flush_options_cache();
		$component_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = $component_snapshot['value'];

		if ( ! is_array( $components ) ) {
			$components = [];
		}

		$result = [];

		foreach ( $components as $component ) {
			$elements   = $component['elements'] ?? [];
			$properties = $component['properties'] ?? [];
			$variants   = $component['variants'] ?? [];

			// Find root element for label
			$root_id = $component['id'] ?? '';
			$label   = $root_id;

			foreach ( $elements as $element ) {
				if ( ( $element['id'] ?? '' ) === $root_id ) {
					$label = $element['label'] ?? $element['name'] ?? $root_id;
					break;
				}
			}

			// Count slots
			$slot_count = 0;

			foreach ( $elements as $element ) {
				if ( ( $element['name'] ?? '' ) === 'slot' ) {
					$slot_count++;
				}
			}

			// Property summaries
			$property_summaries = [];

			foreach ( $properties as $prop ) {
				$property_summaries[] = [
					'id'              => $prop['id'] ?? '',
					'label'           => $prop['label'] ?? '',
					'type'            => $prop['type'] ?? '',
					'default'         => $prop['default'] ?? null,
					'group'           => $prop['group'] ?? '',
					'connectionCount' => isset( $prop['connections'] ) && is_array( $prop['connections'] ) ? count( $prop['connections'] ) : 0,
					'optionCount'     => isset( $prop['options'] ) && is_array( $prop['options'] ) ? count( $prop['options'] ) : 0,
				];
			}

			// Variant names
			$variant_names = [];

			foreach ( $variants as $variant ) {
				$variant_names[] = [
					'id'   => $variant['id'] ?? '',
					'name' => $variant['name'] ?? '',
				];
			}

			$result[] = [
				'id'                 => $root_id,
				'componentDigest'    => self::component_digest( $component ),
				'label'              => $label,
				'category'           => $component['category'] ?? '',
				'desc'               => $component['desc'] ?? '',
				'properties'         => $property_summaries,
				'propertyGroupCount' => isset( $component['propertyGroups'] ) && is_array( $component['propertyGroups'] ) ? count( $component['propertyGroups'] ) : 0,
				'variants'           => $variant_names,
				'slotCount'          => $slot_count,
				'elementCount'       => count( $elements ),
				'version'            => $component['_version'] ?? '',
			];
		}

		$response = Reference::paginate( $result, $input );

		$response['designSystemVersion'] = (int) $component_snapshot['designSystemVersion'];

		return $response;
	}

	/**
	 * Input schema for get-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_component_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'componentId' => [
					'type'        => 'string',
					'description' => __( 'The component ID (same as root element ID).', 'bricks' ),
					'required'    => true,
				],
			],
			'required'   => [ 'componentId' ],
		];
	}

	/**
	 * Output schema for get-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_component_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'component'                => [
					'type'        => 'object',
					'description' => __( 'Component data: elements, full property definitions, variants, and slots. Code-sensitive settings are redacted when the caller cannot execute code; componentPayloadRedacted reports that state.', 'bricks' ),
				],
				'designSystemVersion'      => [
					'type'        => 'integer',
					'description' => __( 'Current design-system version. Pass this as expectedDesignSystemVersion when updating or deleting this component.', 'bricks' ),
				],
				'componentDigest'          => [
					'type'        => 'string',
					'description' => __( 'SHA-256 digest of the complete authoritative component record, including unredacted code-sensitive settings and opaque fields. Pass it unchanged as expectedComponentDigest for update/delete.', 'bricks' ),
				],
				'componentPayloadRedacted' => [
					'type'        => 'boolean',
					'description' => __( 'True when code-sensitive element settings were hidden. The authoritative digest remains usable, but do not replace `elements` from this redacted payload; omit `elements` for metadata-only edits or use a code-authorized read.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: get full component data
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_component( $input ) {
		Manager::flush_options_cache();
		$component_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		// Refresh in-memory components (static property populated at boot).
		\Bricks\Database::$global_data['components'] = is_array( $component_snapshot['value'] ) ? $component_snapshot['value'] : [];
		$component_id                                = (string) $input['componentId'];
		$component                                   = null;

		foreach ( \Bricks\Database::$global_data['components'] as $candidate ) {
			if ( is_array( $candidate ) && (string) ( $candidate['id'] ?? '' ) === $component_id ) {
				$component = $candidate;
				break;
			}
		}

		if ( $component === null ) {
			return Error::not_found( 'component', $component_id );
		}

		$component_digest           = self::component_digest( $component );
		$component_payload_redacted = false;

		if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
			$authoritative_elements     = $component['elements'];
			$component['elements']      = Elements::redact_code_sensitive_elements( $component['elements'] );
			$component_payload_redacted = $component['elements'] !== $authoritative_elements;
		}

		if ( is_array( $component['properties'] ?? null ) ) {
			$authoritative_properties   = $component['properties'];
			$component['properties']    = Elements::redact_component_code_defaults( $authoritative_properties );
			$component_payload_redacted = $component_payload_redacted || $component['properties'] !== $authoritative_properties;
		}

		return [
			'component'                => $component,
			'componentDigest'          => $component_digest,
			'componentPayloadRedacted' => $component_payload_redacted,
			'designSystemVersion'      => (int) $component_snapshot['designSystemVersion'],
		];
	}

	// ==================================================================
	// Component authoring - create / update / delete / extract-from-elements
	// ==================================================================

	/**
	 * Input schema for create-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_component_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'label'             => [
					'type'        => 'string',
					'description' => __( 'Display label. Must be unique across components.', 'bricks' ),
					'required'    => true,
				],
				'elements'          => [
					'type'        => 'array',
					'description' => __( 'Elements tree for the component. The first element is the root (id will be set as componentId). Pass `parent: "0"` on flat root input. In nested format, you may omit ids. All ids are reassigned on create to avoid collisions and remain 6-character Bricks ids.', 'bricks' ),
					'required'    => true,
				],
				'properties'        => [
					'type'        => 'array',
					'description' => __( 'Optional property definitions. Each entry follows the Bricks component property shape: `{ id, label, type, connections, default, desc, group, options, multiple, replace }`. For `type: "class"`, `multiple` controls single vs multi-select instance input, `replace` controls merge vs replace behavior, and custom `options` get stable option IDs so instance values can reference presets instead of raw class-id arrays.', 'bricks' ),
				],
				'variants'          => [
					'type'        => 'array',
					'description' => __( 'Optional variant definitions. Each: { id, name }.', 'bricks' ),
				],
				'category'          => [
					'type'        => 'string',
					'description' => __( 'Optional category label for grouping in the builder panel. Reuse an existing component category string when appropriate or provide a new label.', 'bricks' ),
				],
				'desc'              => [
					'type'        => 'string',
					'description' => __( 'Optional component description shown in the builder panel.', 'bricks' ),
				],
				'propertyGroups'    => [
					'type'        => 'array',
					'description' => __( 'Optional property groups. Each: { id, name }.', 'bricks' ),
				],
				'blockEditor'       => [
					'type'        => 'boolean',
					'description' => __( 'Whether this component is enabled as a block when Bricks component blocks are manual.', 'bricks' ),
				],
				'blockCategory'     => [
					'type'        => 'string',
					'description' => __( 'Optional WordPress block category slug.', 'bricks' ),
				],
				'blockIcon'         => [
					'type'        => 'object',
					'description' => __( 'Optional block icon settings.', 'bricks' ),
				],
				'blockPreviewImage' => [
					'type'        => 'object',
					'description' => __( 'Optional block preview image settings.', 'bricks' ),
				],
			],
			'required'   => [ 'label', 'elements' ],
		];
	}

	/**
	 * Output schema for create-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_component_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'component'                => [
					'type'        => 'object',
					'description' => __( 'The created component (id, elements, properties, variants).', 'bricks' ),
				],
				'componentDigest'          => [ 'type' => 'string' ],
				'componentPayloadRedacted' => [ 'type' => 'boolean' ],
				'designSystemVersion'      => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: create a component
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_component( $input ) {
		Manager::flush_options_cache();

		$label    = trim( (string) ( $input['label'] ?? '' ) );
		$elements = $input['elements'] ?? [];

		if ( $label === '' ) {
			return Error::invalid_param( 'label', 'a non-empty string', $label );
		}

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::invalid_param( 'elements', 'a non-empty array starting with the root element', $elements );
		}

		$normalizer = new Element_Normalizer();
		$elements   = $normalizer->normalize( $elements );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$component_snapshot = Design_Option_Store::read( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = $component_snapshot['value'];

		if ( ! is_array( $components ) ) {
			$components = [];
		}

		// Enforce unique component label across the store.
		foreach ( $components as $existing ) {
			$existing_label = self::component_label( $existing );

			if ( strcasecmp( $existing_label, $label ) === 0 ) {
				return Error::conflict_duplicate_name(
					'component',
					$label,
					[
						'existingId' => $existing['id'] ?? '',
					]
				);
			}
		}

		// Rewrite IDs so caller-supplied ids never collide with existing data.
		$reindexed = self::reindex_component_elements( $elements, $label );

		$prepared = self::prepare_component_elements_for_save( $reindexed['elements'], [] );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$properties = self::normalize_component_properties(
			$input['properties'] ?? [],
			$prepared,
			$reindexed['idMap']
		);

		if ( is_wp_error( $properties ) ) {
			return $properties;
		}

		$properties = Elements::prepare_component_code_defaults( [], $properties, true );

		if ( is_wp_error( $properties ) ) {
			return $properties;
		}

		$property_groups = self::normalize_component_property_groups( $input['propertyGroups'] ?? [] );

		if ( is_wp_error( $property_groups ) ) {
			return $property_groups;
		}

		$variants = self::normalize_component_variants( $input['variants'] ?? [] );

		if ( is_wp_error( $variants ) ) {
			return $variants;
		}

		$component = [
			'id'         => $reindexed['rootId'],
			'elements'   => $prepared,
			'properties' => $properties,
			'variants'   => $variants,
			'_created'   => time(),
			'_user_id'   => get_current_user_id(),
			'_version'   => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
		];

		if ( ! empty( $input['category'] ) ) {
			$component['category'] = (string) $input['category'];
		}

		if ( ! empty( $input['desc'] ) ) {
			$component['desc'] = wp_kses_post( (string) $input['desc'] );
		}

		if ( ! empty( $property_groups ) ) {
			$component['propertyGroups'] = $property_groups;
		}

		$optional_fields = self::apply_component_optional_fields( $component, $input );

		if ( is_wp_error( $optional_fields ) ) {
			return $optional_fields;
		}

		$components[] = $component;

		$save_result = Design_Option_Store::compare_and_swap( $component_snapshot, $components );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		\Bricks\Database::$global_data['components'] = $components;

		return self::versioned_component_after_write( (string) $component['id'], false, $component );
	}

	/**
	 * Input schema for update-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_component_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'componentId'                 => [
					'type'        => 'string',
					'description' => __( 'The component ID to update.', 'bricks' ),
					'required'    => true,
				],
				'expectedDesignSystemVersion' => [
					'type'        => 'integer',
					'description' => __( 'Required safety precondition. Pass designSystemVersion from list-components/get-component. From get-design-context, pass designSystemSnapshot.resources.components.version; its top-level version is only the legacy current-site counter on multisite.', 'bricks' ),
					'required'    => true,
				],
				'expectedComponentDigest'     => [
					'type'        => 'string',
					'minLength'   => 64,
					'maxLength'   => 64,
					'description' => __( 'Required SHA-256 componentDigest from list-components/get-component or the latest component mutation response. It covers the full authoritative component, including hidden code-sensitive and opaque fields.', 'bricks' ),
					'required'    => true,
				],
				'label'                       => [
					'type'        => 'string',
					'description' => __( 'New label. Must remain unique across components.', 'bricks' ),
				],
				'elements'                    => [
					'type'        => 'array',
					'description' => __( 'Replacement elements tree. Passing this replaces the full tree; to edit a single element, read the component first and pass the modified tree back. Element `id` values are internal 6-character Bricks IDs used by builder references and default frontend selectors. In nested format, you may omit ids. In flat format, ids and parent references must be valid 6-character ids.', 'bricks' ),
				],
				'properties'                  => [
					'type'        => 'array',
					'description' => __( 'Replacement properties array. For `type: "class"`, `multiple` controls single vs multi-select instance input, `replace` controls merge vs replace behavior, and custom `options` get stable option IDs so instance values can reference presets instead of raw class-id arrays.', 'bricks' ),
				],
				'variants'                    => [
					'type'        => 'array',
					'description' => __( 'Replacement variants array.', 'bricks' ),
				],
				'allowSlotOrphans'            => [
					'type'        => 'boolean',
					'description' => __( 'Required only when the replacement removes an existing slot. Pass literal true to acknowledge that removing any slot can orphan instance content, even when the current usage scan finds none. Slot IDs are preserved by order where possible. The usage scan is review evidence, not a guarantee that every reference was found.', 'bricks' ),
				],
				'category'                    => [
					'type'        => 'string',
					'description' => __( 'New category label, or empty string to clear.', 'bricks' ),
				],
				'desc'                        => [
					'type'        => 'string',
					'description' => __( 'New description, or empty string to clear.', 'bricks' ),
				],
				'propertyGroups'              => [
					'type'        => 'array',
					'description' => __( 'Replacement property groups array. Each: { id, name }.', 'bricks' ),
				],
				'blockEditor'                 => [
					'type'        => 'boolean',
					'description' => __( 'Enable or disable this component as a block when Bricks component blocks are manual.', 'bricks' ),
				],
				'blockCategory'               => [
					'type'        => 'string',
					'description' => __( 'New WordPress block category slug, or empty string to clear.', 'bricks' ),
				],
				'blockIcon'                   => [
					'type'        => 'object',
					'description' => __( 'Replacement block icon settings.', 'bricks' ),
				],
				'blockPreviewImage'           => [
					'type'        => 'object',
					'description' => __( 'Replacement block preview image settings.', 'bricks' ),
				],
			],
			'required'   => [ 'componentId', 'expectedDesignSystemVersion', 'expectedComponentDigest' ],
		];
	}

	/**
	 * Output schema for update-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_component_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'component'                => [ 'type' => 'object' ],
				'componentDigest'          => [ 'type' => 'string' ],
				'componentPayloadRedacted' => [ 'type' => 'boolean' ],
				'designSystemVersion'      => [ 'type' => 'integer' ],
				'slotRemovalAudit'         => [
					'type'        => 'object',
					'description' => __( 'Present only when slots were removed. Records the explicit allowSlotOrphans acknowledgement, removed slot IDs, and fresh usage-scan evidence. The evidence is not a guarantee that every reference was found.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Prepare an update-component input without persisting it.
	 *
	 * This internal bridge lets file-oriented workspace preview use the exact
	 * component normalization that the focused writer applies. It intentionally
	 * keeps the public ability's version/digest preconditions and fresh slot-use
	 * audit, but performs no compare-and-swap or option write.
	 *
	 * @since 2.4
	 *
	 * @param array $input Focused update-component input.
	 * @return array|\WP_Error Prepared component and slot-removal audit.
	 */
	public static function prepare_component_update_preview( array $input ) {
		Manager::flush_options_cache();

		$component_id = (string) ( $input['componentId'] ?? '' );

		if ( $component_id === '' ) {
			return Error::invalid_param( 'componentId', 'a non-empty string', $component_id );
		}

		$component_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$version_precondition = self::validate_design_system_version_precondition(
			$input,
			'expectedDesignSystemVersion',
			BRICKS_DB_COMPONENTS,
			$component_snapshot
		);

		if ( is_wp_error( $version_precondition ) ) {
			return $version_precondition;
		}

		$components = is_array( $component_snapshot['value'] ) ? $component_snapshot['value'] : [];
		$index      = self::component_index( $components, $component_id );

		if ( $index < 0 ) {
			return Error::not_found( 'component', $component_id );
		}

		$digest_precondition = self::validate_component_digest_precondition( $input, $components[ $index ] );

		if ( is_wp_error( $digest_precondition ) ) {
			return $digest_precondition;
		}

		$prepared = self::prepare_component_update_candidate( $components[ $index ], $components, $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$audit = self::component_slot_removal_audit( $component_id, $prepared['removedSlotIds'], $input );

		if ( is_wp_error( $audit ) ) {
			return $audit;
		}

		$prepared['slotRemovalAudit'] = $audit;

		return $prepared;
	}

	/**
	 * Build the exact component record that update-component will persist.
	 *
	 * The caller supplies the authoritative current row and sibling collection;
	 * this method performs no option reads, usage scans, or persistence.
	 *
	 * @since 2.4
	 *
	 * @param array $current    Authoritative component row.
	 * @param array $components Authoritative sibling collection.
	 * @param array $input      Focused update-component input.
	 * @return array|\WP_Error Prepared component, removed slot IDs, and ID map.
	 */
	private static function prepare_component_update_candidate( array $current, array $components, array $input ) {
		$component_id   = (string) ( $input['componentId'] ?? '' );
		$existing_label = self::component_label( $current );
		$new_label      = null;
		$update_id_map  = [];

		self::ensure_component_metadata( $current );

		if ( array_key_exists( 'label', $input ) ) {
			$new_label = trim( (string) $input['label'] );

			if ( $new_label === '' ) {
				return Error::invalid_param( 'label', 'a non-empty string', $new_label );
			}

			foreach ( $components as $other ) {
				if ( (string) ( $other['id'] ?? '' ) === $component_id ) {
					continue;
				}

				if ( strcasecmp( self::component_label( $other ), $new_label ) === 0 ) {
					return Error::conflict_duplicate_name(
						'component',
						$new_label,
						[ 'existingId' => $other['id'] ?? '' ]
					);
				}
			}
		}

		$removed_slot_ids = [];

		if ( array_key_exists( 'elements', $input ) ) {
			if ( ! is_array( $input['elements'] ) || empty( $input['elements'] ) ) {
				return Error::invalid_param( 'elements', 'a non-empty array', $input['elements'] );
			}

			$normalizer = new Element_Normalizer();
			$elements   = $normalizer->normalize( $input['elements'] );

			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$incoming_root_id = (string) ( $elements[0]['id'] ?? '' );
			$update_id_map    = $incoming_root_id !== '' && $incoming_root_id !== $component_id ? [ $incoming_root_id => $component_id ] : [];
			$elements         = self::normalize_update_elements( $elements, $component_id );
			$slot_plan        = self::component_slot_update_plan( $current, $elements );

			if ( ! empty( $slot_plan['idMap'] ) ) {
				$elements      = self::remap_component_element_ids( $elements, $slot_plan['idMap'] );
				$update_id_map = array_merge( $update_id_map, $slot_plan['idMap'] );
			}

			$removed_slot_ids = $slot_plan['removedSlotIds'];
			$prepared         = self::prepare_component_elements_for_save(
				$elements,
				is_array( $current['elements'] ?? null ) ? $current['elements'] : []
			);

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$current['elements'] = $prepared;

			if ( $new_label === null && $existing_label !== '' ) {
				$current = self::set_component_root_label( $current, $existing_label );
			}
		}

		if ( array_key_exists( 'properties', $input ) ) {
			$original_properties = is_array( $current['properties'] ?? null ) ? $current['properties'] : [];
			$properties          = self::normalize_component_properties(
				$input['properties'],
				is_array( $current['elements'] ?? null ) ? $current['elements'] : [],
				$update_id_map
			);

			if ( is_wp_error( $properties ) ) {
				return $properties;
			}

			$properties = Elements::prepare_component_code_defaults( $original_properties, $properties );

			if ( is_wp_error( $properties ) ) {
				return $properties;
			}

			$current['properties'] = $properties;
		} elseif ( array_key_exists( 'elements', $input ) && ! empty( $current['properties'] ) ) {
			$original_properties = $current['properties'];
			$properties          = self::normalize_component_properties(
				$current['properties'],
				is_array( $current['elements'] ?? null ) ? $current['elements'] : []
			);

			if ( is_wp_error( $properties ) ) {
				return $properties;
			}

			$properties = Elements::prepare_component_code_defaults( $original_properties, $properties );

			if ( is_wp_error( $properties ) ) {
				return $properties;
			}

			$current['properties'] = $properties;
		}

		if ( array_key_exists( 'variants', $input ) ) {
			$variants = self::normalize_component_variants( $input['variants'] );

			if ( is_wp_error( $variants ) ) {
				return $variants;
			}

			$current['variants'] = $variants;
		}

		if ( array_key_exists( 'propertyGroups', $input ) ) {
			$property_groups = self::normalize_component_property_groups( $input['propertyGroups'] );

			if ( is_wp_error( $property_groups ) ) {
				return $property_groups;
			}

			if ( empty( $property_groups ) ) {
				unset( $current['propertyGroups'] );
			} else {
				$current['propertyGroups'] = $property_groups;
			}
		}

		foreach ( [ 'category', 'desc' ] as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = (string) $input[ $key ];
			if ( $key === 'desc' && $value !== '' ) {
				$value = wp_kses_post( $value );
			}
			if ( $value === '' ) {
				unset( $current[ $key ] );
			} else {
				$current[ $key ] = $value;
			}
		}

		$optional_fields = self::apply_component_optional_fields( $current, $input );

		if ( is_wp_error( $optional_fields ) ) {
			return $optional_fields;
		}

		if ( $new_label !== null ) {
			$current = self::set_component_root_label( $current, $new_label );
		}

		return [
			'component'      => $current,
			'removedSlotIds' => $removed_slot_ids,
			'updateIdMap'    => $update_id_map,
		];
	}

	/**
	 * Callback: update an existing component (partial merge)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_component( $input ) {
		Manager::flush_options_cache();

		$component_id = (string) ( $input['componentId'] ?? '' );

		if ( $component_id === '' ) {
			return Error::invalid_param( 'componentId', 'a non-empty string', $component_id );
		}

		$component_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$version_precondition = self::validate_design_system_version_precondition(
			$input,
			'expectedDesignSystemVersion',
			BRICKS_DB_COMPONENTS,
			$component_snapshot
		);

		if ( is_wp_error( $version_precondition ) ) {
			return $version_precondition;
		}

		$components = $component_snapshot['value'];

		if ( ! is_array( $components ) ) {
			$components = [];
		}

		$index = self::component_index( $components, $component_id );

		if ( $index === -1 ) {
			return Error::not_found( 'component', $component_id );
		}

		$digest_precondition = self::validate_component_digest_precondition( $input, $components[ $index ] );

		if ( is_wp_error( $digest_precondition ) ) {
			return $digest_precondition;
		}

		$prepared = self::prepare_component_update_candidate( $components[ $index ], $components, $input );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$current            = $prepared['component'];
		$slot_removal_audit = self::component_slot_removal_audit( $component_id, $prepared['removedSlotIds'], $input );

		if ( is_wp_error( $slot_removal_audit ) ) {
			return $slot_removal_audit;
		}

		$components[ $index ] = $current;

		$save_result = Design_Option_Store::compare_and_swap(
			$component_snapshot,
			$components,
			false,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			(int) $input['expectedDesignSystemVersion']
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		\Bricks\Database::$global_data['components'] = $components;

		$response = self::versioned_component_after_write( $component_id, false, $current );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $slot_removal_audit ) ) {
			$response['slotRemovalAudit'] = $slot_removal_audit;
		}

		return $response;
	}

	/**
	 * Input schema for delete-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_component_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'componentId'                 => [
					'type'        => 'string',
					'description' => __( 'The component ID to delete.', 'bricks' ),
					'required'    => true,
				],
				'expectedDesignSystemVersion' => [
					'type'        => 'integer',
					'description' => __( 'Required safety precondition. Pass designSystemVersion from list-components/get-component. From get-design-context, pass designSystemSnapshot.resources.components.version; its top-level version is only the legacy current-site counter on multisite.', 'bricks' ),
					'required'    => true,
				],
				'expectedComponentDigest'     => [
					'type'        => 'string',
					'minLength'   => 64,
					'maxLength'   => 64,
					'description' => __( 'Required SHA-256 componentDigest from list-components/get-component or the latest component mutation response. It covers the full authoritative component, including hidden code-sensitive and opaque fields.', 'bricks' ),
					'required'    => true,
				],
				'allowOrphans'                => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true for every deletion, including when current usage is zero. This explicitly accepts that deleting a component can leave missing instances which a usage scan may not discover.', 'bricks' ),
					'required'    => true,
				],
				'expectedUsageCount'          => [
					'type'        => 'integer',
					'description' => __( 'Required review precondition. Delete only if the current fresh usage count matches the reviewed count. This is audit evidence and does not guarantee that every component reference was found; allowOrphans=true is still mandatory.', 'bricks' ),
					'required'    => true,
				],
			],
			'required'   => [ 'componentId', 'expectedDesignSystemVersion', 'expectedComponentDigest', 'allowOrphans', 'expectedUsageCount' ],
		];
	}

	/**
	 * Output schema for delete-component
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_component_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'             => [ 'type' => 'boolean' ],
				'beforeDelete'        => [
					'type'        => 'object',
					'description' => __( 'Deletion audit snapshot: id, label, componentDigest, elementCount, usageCount, usedOnPosts, and orphaningAccepted. The digest covers the full authoritative component. Usage is computed fresh after the digest precondition passes, but is review evidence rather than a guarantee that every reference was found.', 'bricks' ),
				],
				'designSystemVersion' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: delete a component (DESTRUCTIVE - removes from options).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_component( $input ) {
		Manager::flush_options_cache();

		$component_id = (string) ( $input['componentId'] ?? '' );

		if ( $component_id === '' ) {
			return Error::invalid_param( 'componentId', 'a non-empty string', $component_id );
		}

		if ( ! array_key_exists( 'expectedUsageCount', $input ) ) {
			return Error::missing_param( 'expectedUsageCount' );
		}

		if ( ! array_key_exists( 'allowOrphans', $input ) ) {
			return Error::missing_param( 'allowOrphans' );
		}

		if ( $input['allowOrphans'] !== true ) {
			return Error::conflict(
				'component_delete_confirmation_required',
				[
					'message'     => 'Deleting a component always requires allowOrphans=true, even when the reviewed usage count is zero. Usage discovery is audit evidence, not a guarantee that no references exist.',
					'componentId' => $component_id,
				]
			);
		}

		$component_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$version_precondition = self::validate_design_system_version_precondition(
			$input,
			'expectedDesignSystemVersion',
			BRICKS_DB_COMPONENTS,
			$component_snapshot
		);

		if ( is_wp_error( $version_precondition ) ) {
			return $version_precondition;
		}

		$components = $component_snapshot['value'];

		if ( ! is_array( $components ) ) {
			$components = [];
		}

		$found_index          = -1;
		$snapshot             = null;
		$expected_usage_count = (int) $input['expectedUsageCount'];
		$current_component    = null;

		foreach ( $components as $i => $comp ) {
			if ( is_array( $comp ) && ( $comp['id'] ?? '' ) === $component_id ) {
				$found_index       = $i;
				$current_component = $comp;
				break;
			}
		}

		if ( $found_index === -1 ) {
			return Error::not_found( 'component', $component_id );
		}

		$digest_precondition = self::validate_component_digest_precondition( $input, $current_component );

		if ( is_wp_error( $digest_precondition ) ) {
			return $digest_precondition;
		}

		$usage_authority = self::validate_component_usage_scan_authority( $component_id, 'delete' );

		if ( is_wp_error( $usage_authority ) ) {
			return $usage_authority;
		}

		$usage = self::compute_component_usage();

		if ( is_wp_error( $usage ) ) {
			return $usage;
		}

		$used_on_posts = $usage[ $component_id ] ?? [];
		$usage_count   = is_array( $used_on_posts )
			? count( $used_on_posts )
			: 0;
		$snapshot      = [
			'id'                => $component_id,
			'label'             => self::component_label( $current_component ),
			'componentDigest'   => self::component_digest( $current_component ),
			'elementCount'      => is_array( $current_component['elements'] ?? null ) ? count( $current_component['elements'] ) : 0,
			'usageCount'        => $usage_count,
			'usedOnPosts'       => $used_on_posts,
			'orphaningAccepted' => true,
			'coverage'          => self::current_site_usage_coverage(),
		];

		if ( (int) $snapshot['usageCount'] !== $expected_usage_count ) {
			return Error::conflict(
				'component_usage_count_mismatch',
				[
					'message'            => sprintf( 'Component "%s" usage count changed from expected %d to %d. Re-read component usage before deleting.', $component_id, $expected_usage_count, (int) $snapshot['usageCount'] ),
					'componentId'        => $component_id,
					'expectedUsageCount' => $expected_usage_count,
					'actualUsageCount'   => (int) $snapshot['usageCount'],
					'usedOnPosts'        => $snapshot['usedOnPosts'],
				]
			);
		}

		array_splice( $components, $found_index, 1 );
		$components  = array_values( $components );
		$save_result = Design_Option_Store::compare_and_swap(
			$component_snapshot,
			$components,
			false,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			(int) $input['expectedDesignSystemVersion']
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		\Bricks\Database::$global_data['components'] = $components;

		$versioned_delete = self::versioned_component_after_write( $component_id, true );

		if ( is_wp_error( $versioned_delete ) ) {
			return $versioned_delete;
		}

		return [
			'deleted'             => true,
			'beforeDelete'        => $snapshot,
			'designSystemVersion' => $versioned_delete['designSystemVersion'],
		];
	}

	/**
	 * Input schema for extract-component-from-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function extract_component_from_elements_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'postId'        => [
					'type'        => 'integer',
					'description' => __( 'Post ID to extract from.', 'bricks' ),
					'required'    => true,
				],
				'rootElementId' => [
					'type'        => 'string',
					'description' => __( 'Element id that becomes the new component root. Its subtree is cloned into the component; the original element is replaced in-place with a component instance reference.', 'bricks' ),
					'required'    => true,
				],
				'label'         => [
					'type'        => 'string',
					'description' => __( 'Component label. Must be unique across components.', 'bricks' ),
					'required'    => true,
				],
				'areaKey'       => [
					'type'        => 'string',
					'description' => __( 'Which area of the post holds the element: "content" (default), "header", or "footer".', 'bricks' ),
				],
				'category'      => [
					'type'        => 'string',
					'description' => __( 'Optional category label for grouping the new component in the builder panel.', 'bricks' ),
				],
				'desc'          => [
					'type'        => 'string',
					'description' => __( 'Optional component description shown in the builder panel.', 'bricks' ),
				],
			],
			'required'   => [ 'postId', 'rootElementId', 'label' ],
		];
	}

	/**
	 * Output schema for extract-component-from-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function extract_component_from_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'component'           => [ 'type' => 'object' ],
				'componentDigest'     => [ 'type' => 'string' ],
				'postId'              => [ 'type' => 'integer' ],
				'areaKey'             => [ 'type' => 'string' ],
				'designSystemVersion' => [ 'type' => 'integer' ],
				'revisionId'          => [
					'type'        => 'integer',
					'description' => __( 'Revision snapshot ID captured before the post write. Pass it to `bricks/restore-revision` to undo.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: extract-component-from-elements
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function extract_component_from_elements( $input ) {
		Manager::flush_options_cache();

		$post_id         = (int) ( $input['postId'] ?? 0 );
		$root_element_id = (string) ( $input['rootElementId'] ?? '' );
		$label           = trim( (string) ( $input['label'] ?? '' ) );
		$area_key        = (string) ( $input['areaKey'] ?? 'content' );

		if ( ! in_array( $area_key, [ 'content', 'header', 'footer' ], true ) ) {
			return Error::invalid_param( 'areaKey', 'one of: content, header, footer', $area_key );
		}

		if ( $post_id <= 0 ) {
			return Error::invalid_param( 'postId', 'a positive integer', $post_id );
		}

		if ( $root_element_id === '' ) {
			return Error::invalid_param( 'rootElementId', 'a non-empty element id', $root_element_id );
		}

		if ( $label === '' ) {
			return Error::invalid_param( 'label', 'a non-empty string', $label );
		}

		if ( ! get_post( $post_id ) ) {
			return Error::not_found( 'post', $post_id );
		}

		$component_snapshot = Design_Option_Store::read( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = $component_snapshot['value'];

		if ( ! is_array( $components ) ) {
			$components = [];
		}

		foreach ( $components as $existing ) {
			if ( strcasecmp( self::component_label( $existing ), $label ) === 0 ) {
				return Error::conflict_duplicate_name(
					'component',
					$label,
					[
						'existingId' => $existing['id'] ?? '',
					]
				);
			}
		}

		$elements = \Bricks\Database::get_data( $post_id, $area_key );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( "post_elements[{$area_key}]", $post_id );
		}

		$expected_document_digest = Save_Pipeline::document_digest( $elements );
		$root_index               = -1;

		foreach ( $elements as $i => $el ) {
			if ( ( $el['id'] ?? '' ) === $root_element_id ) {
				$root_index = $i;
				break;
			}
		}

		if ( $root_index === -1 ) {
			return Error::not_found( "element[{$root_element_id}]", $post_id );
		}

		// Collect the subtree (root + descendants) before rewriting ids.
		$subtree_ids = self::collect_descendant_ids( $elements, $root_element_id );
		$subtree     = [];

		foreach ( $elements as $el ) {
			if ( in_array( $el['id'] ?? '', $subtree_ids, true ) ) {
				$subtree[] = $el;
			}
		}

		// Reindex the cloned subtree so it has fresh IDs inside the component.
		$reindexed          = self::reindex_component_elements( $subtree, $label );
		$component_root     = $reindexed['rootId'];
		$component_elements = self::rebuild_component_children( $reindexed['elements'] );
		$component_elements = Element_Style_Normalizer::normalize_elements( $component_elements, true );

		$code_check = self::check_component_code_permissions( [], $component_elements );

		if ( is_wp_error( $code_check ) ) {
			return $code_check;
		}

		$valid = Element_Validator::validate( $component_elements );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$component = [
			'id'         => $component_root,
			'elements'   => $component_elements,
			'properties' => [],
			'variants'   => [],
			'_created'   => time(),
			'_user_id'   => get_current_user_id(),
			'_version'   => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
		];

		if ( ! empty( $input['category'] ) ) {
			$component['category'] = (string) $input['category'];
		}

		if ( ! empty( $input['desc'] ) ) {
			$component['desc'] = wp_kses_post( (string) $input['desc'] );
		}

		// Build the replacement element: a component instance in place of the original root.
		$original_root = $elements[ $root_index ];

		$instance = [
			'id'       => $root_element_id,
			'name'     => $original_root['name'] ?? 'section',
			'parent'   => $original_root['parent'] ?? 0,
			'children' => [],
			'settings' => [],
			'cid'      => $component_root,
		];

		// Remove old subtree + splice instance in the root's position.
		$new_elements = [];

		foreach ( $elements as $el ) {
			$id = $el['id'] ?? '';

			if ( $id === $root_element_id ) {
				$new_elements[] = $instance;
				continue;
			}

			if ( in_array( $id, $subtree_ids, true ) ) {
				continue;
			}

			$new_elements[] = $el;
		}

		return self::commit_extracted_component(
			$component_snapshot,
			$components,
			$component,
			$post_id,
			$elements,
			$new_elements,
			$area_key,
			$expected_document_digest
		);
	}

	/**
	 * Commit a component insertion before its page replacement.
	 *
	 * @since 2.4
	 *
	 * @param array  $component_snapshot      Exact component-store before-image.
	 * @param array  $components              Components from the before-image.
	 * @param array  $component               Component to insert.
	 * @param int    $post_id                 Target post ID.
	 * @param array  $original_elements       Exact authoritative page before-image.
	 * @param array  $new_elements            Replacement page tree.
	 * @param string $area_key                Bricks data area.
	 * @param string $expected_document_digest Digest of the exact original page read.
	 * @return array|\WP_Error
	 */
	private static function commit_extracted_component(
		array $component_snapshot,
		array $components,
		array $component,
		int $post_id,
		array $original_elements,
		array $new_elements,
		string $area_key,
		string $expected_document_digest
	) {
		$component_digest = Design_Option_Store::item_digest( $component );
		$components[]     = $component;
		$save_result      = Design_Option_Store::compare_and_swap( $component_snapshot, $components );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$persisted_components                        = is_array( $save_result['value'] ?? null ) ? $save_result['value'] : $components;
		\Bricks\Database::$global_data['components'] = $persisted_components;

		$persisted_component = null;

		foreach ( $persisted_components as $persisted_component ) {
			if ( (string) ( $persisted_component['id'] ?? '' ) === (string) $component['id'] ) {
				break;
			}

			$persisted_component = null;
		}

		if ( $persisted_component === null || ! hash_equals( $component_digest, Design_Option_Store::item_digest( $persisted_component ) ) ) {
			$rollback = self::compensate_extracted_component( $component_snapshot, $component );

			if ( is_wp_error( $rollback ) ) {
				return self::component_extraction_compensation_error( $rollback, $component_snapshot, $component, null );
			}

			return Error::conflict(
				'extract_component_write_readback_mismatch',
				[
					'message'          => 'The exact component insert was not present after persistence. The page was not changed and any unchanged insert was removed.',
					'componentId'      => (string) $component['id'],
					'pageWriteStarted' => false,
				]
			);
		}

		$current_authority = Design_Option_Store::authority_site_id( BRICKS_DB_COMPONENTS );

		if ( $current_authority !== (int) $component_snapshot['siteId'] ) {
			$rollback = self::compensate_extracted_component( $component_snapshot, $component );

			if ( is_wp_error( $rollback ) ) {
				return self::component_extraction_compensation_error( $rollback, $component_snapshot, $component, null );
			}

			return Error::conflict(
				'extract_component_authority_changed',
				[
					'message'          => 'Component authority changed before the page mutation. The component insert was removed from its original authority; re-read and retry.',
					'componentId'      => (string) $component['id'],
					'originalSiteId'   => (int) $component_snapshot['siteId'],
					'currentSiteId'    => $current_authority,
					'pageWriteStarted' => false,
				]
			);
		}

		$pipeline_result = Save_Pipeline::execute( $post_id, $new_elements, $area_key, [], $expected_document_digest );

		if ( is_wp_error( $pipeline_result ) ) {
			$rollback = self::compensate_extracted_component( $component_snapshot, $component );

			if ( is_wp_error( $rollback ) ) {
				return self::component_extraction_compensation_error( $rollback, $component_snapshot, $component, $pipeline_result );
			}

			return $pipeline_result;
		}

		$versioned_component = self::versioned_component_after_write( (string) $component['id'], false, $component );
		$component_matches   = ! is_wp_error( $versioned_component )
			&& hash_equals( self::component_digest( $component ), (string) ( $versioned_component['componentDigest'] ?? '' ) );

		if ( ! $component_matches ) {
			return self::recover_inconsistent_component_extraction(
				$component_snapshot,
				$component,
				$post_id,
				$original_elements,
				$new_elements,
				$area_key,
				$pipeline_result,
				$versioned_component
			);
		}

		return [
			'component'           => $versioned_component['component'],
			'componentDigest'     => $versioned_component['componentDigest'],
			'postId'              => $post_id,
			'areaKey'             => $area_key,
			'designSystemVersion' => $versioned_component['designSystemVersion'],
			'revisionId'          => $pipeline_result['revisionId'] ?? 0,
		];
	}

	/**
	 * Recover a page whose extracted component did not survive unchanged.
	 *
	 * The page rollback is guarded by the exact digest returned by its successful
	 * save. Component cleanup only removes the exact component inserted by this
	 * request, so neither compensation can overwrite a concurrent writer.
	 *
	 * @since 2.4
	 *
	 * @param array           $component_snapshot Original component authority.
	 * @param array           $component          Exact inserted component.
	 * @param int             $post_id            Target post ID.
	 * @param array           $original_elements  Exact page before-image.
	 * @param array           $new_elements       Extraction page candidate.
	 * @param string          $area_key           Bricks data area.
	 * @param array           $pipeline_result    Successful page result.
	 * @param array|\WP_Error $component_readback Component readback or failure.
	 * @return \WP_Error
	 */
	private static function recover_inconsistent_component_extraction(
		array $component_snapshot,
		array $component,
		int $post_id,
		array $original_elements,
		array $new_elements,
		string $area_key,
		array $pipeline_result,
		$component_readback
	) {
		$committed_page_digest = (string) ( $pipeline_result['documentDigest'] ?? Save_Pipeline::document_digest( $new_elements ) );
		$page_rollback         = Save_Pipeline::execute( $post_id, $original_elements, $area_key, [], $committed_page_digest );

		if ( is_wp_error( $page_rollback ) ) {
			return self::component_extraction_partial_commit_error(
				$component_snapshot,
				$component,
				$post_id,
				$area_key,
				$page_rollback,
				$component_readback,
				false
			);
		}

		$component_rollback = self::compensate_extracted_component( $component_snapshot, $component );

		if ( is_wp_error( $component_rollback ) ) {
			return self::component_extraction_partial_commit_error(
				$component_snapshot,
				$component,
				$post_id,
				$area_key,
				$component_rollback,
				$component_readback,
				true
			);
		}

		return Error::conflict(
			'extract_component_consistency_recovered',
			[
				'message'                    => 'The page committed, but the exact inserted component was not authoritative afterward. Both writes were safely compensated; re-read before retrying.',
				'componentId'                => (string) $component['id'],
				'postId'                     => $post_id,
				'areaKey'                    => $area_key,
				'pageWasCommitted'           => true,
				'pageRestored'               => true,
				'componentInsertCompensated' => true,
				'manualRecoveryRequired'     => false,
				'readbackErrorCode'          => is_wp_error( $component_readback ) ? $component_readback->get_error_code() : '',
			]
		);
	}

	/**
	 * Report exact live state after extraction compensation loses a race.
	 *
	 * @since 2.4
	 *
	 * @param array           $component_snapshot Original component authority.
	 * @param array           $component          Exact inserted component.
	 * @param int             $post_id            Target post ID.
	 * @param string          $area_key           Bricks data area.
	 * @param \WP_Error       $compensation_error Compensation failure.
	 * @param array|\WP_Error $component_readback Component readback or failure.
	 * @param bool            $page_restored      Whether page compensation completed.
	 * @return \WP_Error
	 */
	private static function component_extraction_partial_commit_error(
		array $component_snapshot,
		array $component,
		int $post_id,
		string $area_key,
		\WP_Error $compensation_error,
		$component_readback,
		bool $page_restored
	) {
		$live_components     = Design_Option_Store::read_from_snapshot( $component_snapshot, [] );
		$live_page           = \Bricks\Database::get_data( $post_id, $area_key );
		$live_component_rows = is_wp_error( $live_components ) || ! is_array( $live_components['value'] ?? null )
			? []
			: $live_components['value'];
		$live_component      = self::find_design_item( $live_component_rows, 'id', (string) $component['id'] );

		return Error::conflict(
			'extract_component_committed_partial',
			[
				'message'                         => 'Extraction committed at least one write, and compensation could not safely complete because authoritative data moved. Concurrent data was preserved; re-read the page and component store before manual recovery.',
				'componentId'                     => (string) $component['id'],
				'postId'                          => $post_id,
				'areaKey'                         => $area_key,
				'pageWasCommitted'                => true,
				'pageRestored'                    => $page_restored,
				'componentInsertCompensated'      => false,
				'manualRecoveryRequired'          => true,
				'compensationErrorCode'           => $compensation_error->get_error_code(),
				'readbackErrorCode'               => is_wp_error( $component_readback ) ? $component_readback->get_error_code() : '',
				'livePageDigest'                  => Save_Pipeline::document_digest( is_array( $live_page ) ? $live_page : [] ),
				'livePageElementCount'            => is_array( $live_page ) ? count( $live_page ) : 0,
				'componentAuthoritySiteId'        => (int) $component_snapshot['siteId'],
				'currentComponentAuthoritySiteId' => Design_Option_Store::authority_site_id( BRICKS_DB_COMPONENTS ),
				'liveComponentCount'              => count( $live_component_rows ),
				'liveComponentsDigest'            => is_wp_error( $live_components ) ? '' : Design_Option_Store::item_digest( $live_component_rows ),
				'liveComponentPresent'            => is_array( $live_component ),
				'liveComponentDigest'             => is_array( $live_component ) ? Design_Option_Store::item_digest( $live_component ) : '',
				'liveComponentsReadError'         => is_wp_error( $live_components ) ? $live_components->get_error_code() : '',
			]
		);
	}

	/**
	 * Remove only this extraction's component while preserving concurrent writes.
	 *
	 * @since 2.4
	 *
	 * @param array $authority_snapshot Original authority snapshot.
	 * @param array $component          Exact inserted component.
	 * @return array|\WP_Error
	 */
	private static function compensate_extracted_component( array $authority_snapshot, array $component ) {
		$component_id     = (string) $component['id'];
		$component_digest = Design_Option_Store::item_digest( $component );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$current = Design_Option_Store::read_from_snapshot( $authority_snapshot, [] );

			if ( is_wp_error( $current ) ) {
				return $current;
			}

			$components   = is_array( $current['value'] ) ? $current['value'] : [];
			$target_index = false;

			foreach ( $components as $index => $candidate ) {
				if ( (string) ( $candidate['id'] ?? '' ) === $component_id ) {
					$target_index = $index;
					break;
				}
			}

			if ( $target_index === false ) {
				\Bricks\Database::$global_data['components'] = $components;
				return [
					'success' => true,
					'status'  => 'already_absent',
					'value'   => $components,
				];
			}

			$live_component = $components[ $target_index ];

			if ( ! hash_equals( $component_digest, Design_Option_Store::item_digest( $live_component ) ) ) {
				return Error::conflict(
					'extract_component_compensation_changed',
					[
						'message'                => 'The inserted component changed before compensation. The concurrent component was preserved.',
						'componentId'            => $component_id,
						'siteId'                 => (int) $current['siteId'],
						'liveComponentDigest'    => Design_Option_Store::item_digest( $live_component ),
						'manualRecoveryRequired' => true,
					]
				);
			}

			$filtered = $components;
			array_splice( $filtered, $target_index, 1 );
			$rollback = Design_Option_Store::compare_and_swap( $current, $filtered );

			if ( ! is_wp_error( $rollback ) ) {
				\Bricks\Database::$global_data['components'] = is_array( $rollback['value'] ?? null ) ? $rollback['value'] : $filtered;
				return $rollback;
			}

			if ( $rollback->get_error_code() !== 'bricks_conflict_design_option_write' ) {
				return $rollback;
			}
		}

		return Error::conflict(
			'extract_component_compensation_raced',
			[
				'message'     => 'The component store kept changing while the failed extraction was being compensated. The current store was preserved.',
				'componentId' => $component_id,
				'siteId'      => (int) $authority_snapshot['siteId'],
			]
		);
	}

	/**
	 * Report an extraction whose component insert could not be compensated.
	 *
	 * @since 2.4
	 *
	 * @param \WP_Error      $rollback_error Rollback failure.
	 * @param array          $snapshot       Original component-store snapshot.
	 * @param array          $component      Inserted component.
	 * @param \WP_Error|null $page_error     Original page failure, when any.
	 * @return \WP_Error
	 */
	private static function component_extraction_compensation_error( \WP_Error $rollback_error, array $snapshot, array $component, $page_error ) {
		return Error::conflict(
			'extract_component_compensation_required',
			[
				'message'                => 'The page was not committed, but the inserted component could not be removed without overwriting a concurrent change. Re-read components and remove the reported component if it is still orphaned.',
				'componentId'            => (string) $component['id'],
				'siteId'                 => (int) $snapshot['siteId'],
				'pageErrorCode'          => $page_error instanceof \WP_Error ? $page_error->get_error_code() : '',
				'compensationErrorCode'  => $rollback_error->get_error_code(),
				'manualRecoveryRequired' => true,
			]
		);
	}

	/**
	 * Read back a component mutation with its version from one authority query.
	 *
	 * @since 2.4
	 *
	 * @param string     $component_id        Component ID.
	 * @param bool       $deleted             Whether the component must be absent.
	 * @param array|null $committed_component Exact committed candidate.
	 * @return array|\WP_Error
	 */
	private static function versioned_component_after_write( string $component_id, bool $deleted = false, $committed_component = null ) {
		$snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_COMPONENTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $snapshot ) ) {
			return self::component_committed_readback_error( $component_id, $snapshot );
		}

		$components = is_array( $snapshot['value'] ) ? $snapshot['value'] : [];
		$component  = null;

		foreach ( $components as $candidate ) {
			if ( (string) ( $candidate['id'] ?? '' ) === $component_id ) {
				$component = $candidate;
				break;
			}
		}

		if ( $deleted ) {
			if ( $component !== null ) {
				return self::component_committed_readback_error( $component_id, $snapshot, $component );
			}

			return [
				'designSystemVersion' => (int) $snapshot['designSystemVersion'],
			];
		}

		if ( $component === null ) {
			return self::component_committed_readback_error( $component_id, $snapshot );
		}

		if (
			is_array( $committed_component ) &&
			! hash_equals( self::component_digest( $committed_component ), self::component_digest( $component ) )
		) {
			return self::component_committed_readback_error( $component_id, $snapshot, $component );
		}

		$component_digest           = self::component_digest( $component );
		$component_payload_redacted = false;

		if ( isset( $component['elements'] ) && is_array( $component['elements'] ) ) {
			$authoritative_elements     = $component['elements'];
			$component['elements']      = Elements::redact_code_sensitive_elements( $component['elements'] );
			$component_payload_redacted = $component['elements'] !== $authoritative_elements;
		}

		if ( is_array( $component['properties'] ?? null ) ) {
			$authoritative_properties   = $component['properties'];
			$component['properties']    = Elements::redact_component_code_defaults( $authoritative_properties );
			$component_payload_redacted = $component_payload_redacted || $component['properties'] !== $authoritative_properties;
		}

		return [
			'component'                => $component,
			'componentDigest'          => $component_digest,
			'componentPayloadRedacted' => $component_payload_redacted,
			'designSystemVersion'      => (int) $snapshot['designSystemVersion'],
		];
	}

	/**
	 * Report a committed component mutation whose exact response is unavailable.
	 *
	 * @since 2.4
	 *
	 * @param string          $component_id Component ID.
	 * @param array|\WP_Error $snapshot     Live snapshot or read error.
	 * @param array|null      $live_component Live component when present.
	 * @return \WP_Error
	 */
	private static function component_committed_readback_error( string $component_id, $snapshot, $live_component = null ) {
		$ownership = null;

		if ( is_array( $snapshot ) ) {
			$ownership = Design_Option_Store::ownership_envelope( 'components', $snapshot );
			$ownership = is_wp_error( $ownership ) ? null : $ownership;
		}

		$live_digest      = is_array( $live_component ) ? self::component_digest( $live_component ) : '';
		$payload_redacted = false;

		if ( is_array( $live_component ) && isset( $live_component['elements'] ) && is_array( $live_component['elements'] ) ) {
			$authoritative_elements     = $live_component['elements'];
			$live_component['elements'] = Elements::redact_code_sensitive_elements( $live_component['elements'] );
			$payload_redacted           = $live_component['elements'] !== $authoritative_elements;
		}

		if ( is_array( $live_component['properties'] ?? null ) ) {
			$authoritative_properties     = $live_component['properties'];
			$live_component['properties'] = Elements::redact_component_code_defaults( $authoritative_properties );
			$payload_redacted             = $payload_redacted || $live_component['properties'] !== $authoritative_properties;
		}

		return Error::internal_error(
			'component-committed-readback',
			'The component mutation committed, but its exact authoritative response could not be completed. Do not retry blindly; re-read components first.',
			[
				'componentId'              => $component_id,
				'committed'                => true,
				'manualRecoveryRequired'   => true,
				'readbackErrorCode'        => $snapshot instanceof \WP_Error ? $snapshot->get_error_code() : '',
				'liveOwnership'            => $ownership,
				'liveComponentPresent'     => is_array( $live_component ),
				'liveComponentDigest'      => $live_digest,
				'liveComponent'            => $live_component,
				'componentPayloadRedacted' => $payload_redacted,
			]
		);
	}

	/**
	 * Read a component's label from its root element.
	 *
	 * @since 2.4
	 *
	 * @param array $component Component record.
	 * @return string
	 */
	private static function component_label( $component ) {
		$root_id  = $component['id'] ?? '';
		$elements = $component['elements'] ?? [];

		foreach ( $elements as $el ) {
			if ( ( $el['id'] ?? '' ) === $root_id ) {
				return (string) ( $el['label'] ?? $el['name'] ?? $root_id );
			}
		}

		return (string) $root_id;
	}

	/**
	 * Locate a component in an authoritative collection.
	 *
	 * @since 2.4
	 *
	 * @param array  $components   Component rows.
	 * @param string $component_id Component ID.
	 * @return int Index, or -1 when absent.
	 */
	private static function component_index( array $components, string $component_id ): int {
		foreach ( $components as $index => $component ) {
			if ( (string) ( $component['id'] ?? '' ) === $component_id ) {
				return (int) $index;
			}
		}

		return -1;
	}

	/**
	 * Ensure component records contain the metadata fields expected by the builder.
	 *
	 * @since 2.4
	 *
	 * @param array $component Component record.
	 * @return void
	 */
	private static function ensure_component_metadata( array &$component ): void {
		if ( empty( $component['_created'] ) ) {
			$component['_created'] = time();
		}

		if ( empty( $component['_user_id'] ) ) {
			$component['_user_id'] = get_current_user_id();
		}

		if ( empty( $component['_version'] ) ) {
			$component['_version'] = defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '';
		}
	}

	/**
	 * Set the component label on the root element.
	 *
	 * @since 2.4
	 *
	 * @param array  $component Component row.
	 * @param string $label     Component label.
	 * @return array
	 */
	private static function set_component_root_label( array $component, string $label ): array {
		foreach ( $component['elements'] as $index => $element ) {
			if ( ( $element['id'] ?? '' ) === ( $component['id'] ?? '' ) ) {
				$component['elements'][ $index ]['label'] = $label;
				break;
			}
		}

		return $component;
	}

	/**
	 * Validate optimistic concurrency for component writes.
	 *
	 * @since 2.4
	 *
	 * @param array  $input         Input payload.
	 * @param string $param         Parameter name.
	 * @param string $anchor_option Authority-defining design option.
	 * @param array  $versioned_snapshot Optional payload-and-version snapshot.
	 * @return true|\WP_Error
	 */
	private static function validate_design_system_version_precondition( array $input, string $param = 'expectedDesignSystemVersion', string $anchor_option = '', array $versioned_snapshot = [] ) {
		if ( ! array_key_exists( $param, $input ) ) {
			return Error::missing_param( $param );
		}

		if ( $anchor_option === '' ) {
			$anchor_option = BRICKS_DB_COMPONENTS;
		}

		$expected = (int) $input[ $param ];
		$current  = array_key_exists( 'designSystemVersion', $versioned_snapshot )
			? (int) $versioned_snapshot['designSystemVersion']
			: self::design_system_version_for_option( $anchor_option );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( $expected !== $current ) {
			return Error::conflict(
				'design_system_version_mismatch',
				[
					'message'                     => sprintf( 'Design system changed from expected version %d to %d. Re-read the component before writing.', $expected, $current ),
					'expectedDesignSystemVersion' => $expected,
					'actualDesignSystemVersion'   => $current,
				]
			);
		}

		return true;
	}

	/**
	 * Validate that the client reviewed this exact authoritative component.
	 *
	 * @since 2.4
	 *
	 * @param array  $input     Mutation input.
	 * @param array  $component Exact unredacted component row.
	 * @param string $param     Digest parameter name.
	 * @return true|\WP_Error
	 */
	private static function validate_component_digest_precondition( array $input, array $component, string $param = 'expectedComponentDigest' ) {
		if ( ! array_key_exists( $param, $input ) ) {
			return Error::missing_param( $param );
		}

		$expected = strtolower( trim( (string) $input[ $param ] ) );
		$actual   = self::component_digest( $component );

		if ( strlen( $expected ) !== 64 || ! ctype_xdigit( $expected ) ) {
			return Error::invalid_param( $param, 'a 64-character SHA-256 componentDigest', $input[ $param ] );
		}

		if ( ! hash_equals( $actual, $expected ) ) {
			return Error::conflict(
				'component_digest_mismatch',
				[
					'message'                 => 'The component changed after the reviewed read. Re-read the component before updating or deleting it.',
					'componentId'             => (string) ( $component['id'] ?? '' ),
					'expectedComponentDigest' => $expected,
					'actualComponentDigest'   => $actual,
				]
			);
		}

		return true;
	}

	/**
	 * Digest the complete authoritative component record.
	 *
	 * The digest is calculated before response redaction so hidden executable
	 * settings and unknown future fields remain part of the write precondition.
	 *
	 * @since 2.4
	 *
	 * @param array $component Exact component row.
	 * @return string
	 */
	private static function component_digest( array $component ): string {
		return hash( 'sha256', (string) wp_json_encode( $component ) );
	}

	/**
	 * Read the design-system version from an option's authority site.
	 *
	 * @since 2.4
	 *
	 * @param string $anchor_option Authority-defining design option.
	 * @return int|\WP_Error
	 */
	private static function design_system_version_for_option( string $anchor_option ) {
		if ( ! class_exists( Design_Option_Store::class ) ) {
			return self::design_system_version();
		}

		$version_snapshot = Design_Option_Store::read_related(
			$anchor_option,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			0
		);

		if ( is_wp_error( $version_snapshot ) ) {
			return $version_snapshot;
		}

		return (int) $version_snapshot['value'];
	}

	/**
	 * Normalize and validate component property definitions.
	 *
	 * @since 2.4
	 *
	 * @param mixed $properties Caller-supplied properties.
	 * @param array $elements   Component elements.
	 * @param array $id_map     Optional element ID remap.
	 * @return array|\WP_Error
	 */
	private static function normalize_component_properties( $properties, array $elements, array $id_map = [] ) {
		if ( $properties === null || $properties === [] ) {
			return [];
		}

		if ( ! is_array( $properties ) ) {
			return Error::invalid_param( 'properties', 'an array of component property definitions', $properties );
		}

		$properties  = self::remap_component_properties( array_values( $properties ), $id_map );
		$element_ids = [];

		foreach ( $elements as $element ) {
			$element_id = (string) ( $element['id'] ?? '' );

			if ( $element_id !== '' ) {
				$element_ids[ $element_id ] = (string) ( $element['name'] ?? '' );
			}
		}

		$allowed_keys = [
			'id',
			'label',
			'type',
			'connections',
			'default',
			'desc',
			'group',
			'options',
			'multiple',
			'replace',
		];

		$allowed_types = [
			'text',
			'editor',
			'icon',
			'image',
			'image-gallery',
			'link',
			'select',
			'toggle',
			'query',
			'class',
		];

		$normalized = [];

		foreach ( $properties as $index => $property ) {
			if ( ! is_array( $property ) ) {
				return Error::invalid_param( "properties[{$index}]", 'an object with { id, label, type }', $property );
			}

			foreach ( array_keys( $property ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return Error::invalid_param( "properties[{$index}].{$key}", 'one of: ' . implode( ', ', $allowed_keys ), $property[ $key ] );
				}
			}

			$property_id = trim( (string) ( $property['id'] ?? '' ) );
			$label       = trim( (string) ( $property['label'] ?? '' ) );
			$type        = trim( (string) ( $property['type'] ?? '' ) );

			if ( $property_id === '' ) {
				return Error::invalid_param( "properties[{$index}].id", 'a non-empty string', $property['id'] ?? null );
			}

			if ( $label === '' ) {
				return Error::invalid_param( "properties[{$index}].label", 'a non-empty string', $property['label'] ?? null );
			}

			if ( ! in_array( $type, $allowed_types, true ) ) {
				return Error::invalid_param( "properties[{$index}].type", 'one of: ' . implode( ', ', $allowed_types ), $type );
			}

			$item = [
				'id'    => $property_id,
				'label' => $label,
				'type'  => $type,
			];

			if ( array_key_exists( 'connections', $property ) ) {
				$connections = self::normalize_component_property_connections(
					$property['connections'],
					$element_ids,
					"properties[{$index}].connections",
					$type
				);

				if ( is_wp_error( $connections ) ) {
					return $connections;
				}

				if ( ! empty( $connections ) ) {
					$item['connections'] = $connections;
				}
			}

			foreach ( [ 'default', 'desc', 'group' ] as $key ) {
				if ( array_key_exists( $key, $property ) ) {
					$item[ $key ] = $key === 'desc' && is_string( $property[ $key ] ) ? wp_kses_post( $property[ $key ] ) : $property[ $key ];
				}
			}

			if ( array_key_exists( 'options', $property ) ) {
				$options = self::normalize_component_property_options( $property['options'], "properties[{$index}].options" );

				if ( is_wp_error( $options ) ) {
					return $options;
				}

				if ( ! empty( $options ) ) {
					$item['options'] = $options;
				}
			}

			foreach ( [ 'multiple', 'replace' ] as $key ) {
				if ( array_key_exists( $key, $property ) ) {
					$item[ $key ] = (bool) $property[ $key ];
				}
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize a component property's element-control connections.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $connections Property connections.
	 * @param array  $element_ids Valid element IDs.
	 * @param string $path        Error path.
	 * @param string $property_type Component property type.
	 * @return array|\WP_Error
	 */
	private static function normalize_component_property_connections( $connections, array $element_ids, string $path, string $property_type ) {
		if ( $connections === null || $connections === [] ) {
			return [];
		}

		if ( ! is_array( $connections ) ) {
			return Error::invalid_param( $path, 'an object mapping elementId to control keys', $connections );
		}

		$normalized = [];

		foreach ( $connections as $element_id => $control_keys ) {
			$element_id = (string) $element_id;

			if ( $element_id === '' || ! isset( $element_ids[ $element_id ] ) ) {
				return Error::invalid_param(
					"{$path}.{$element_id}",
					'an element id present in the component elements array',
					$element_id
				);
			}

			if ( ! is_array( $control_keys ) || empty( $control_keys ) ) {
				return Error::invalid_param( "{$path}.{$element_id}", 'a non-empty array of control keys', $control_keys );
			}

			$normalized_keys = [];

			foreach ( $control_keys as $control_key ) {
				$control_key = trim( (string) $control_key );

				if ( $control_key === '' ) {
					return Error::invalid_param( "{$path}.{$element_id}", 'non-empty control key strings', $control_keys );
				}

				if ( self::is_code_sensitive_component_connection( $control_key, $element_ids[ $element_id ], $property_type ) ) {
					return Error::code_sensitive_write_forbidden();
				}

				$normalized_keys[] = $control_key;
			}

			$normalized[ $element_id ] = array_values( array_unique( $normalized_keys ) );
		}

		return $normalized;
	}

	/**
	 * Whether a component property connection can author executable settings.
	 *
	 * @since 2.4
	 *
	 * @param string $control_key Connected element control key.
	 * @param string $element_name Connected element name.
	 * @param string $property_type Component property type.
	 * @return bool
	 */
	private static function is_code_sensitive_component_connection( string $control_key, string $element_name = '', string $property_type = '' ): bool {
		$parts       = explode( ':', $control_key );
		$setting_key = $parts[0];
		$sub_key     = $parts[1] ?? '';

		if ( count( $parts ) > 2 ) {
			return true;
		}

		if ( $setting_key === 'query' ) {
			// Whole query values require a query property; ordinary nested controls remain bindable.
			return $sub_key === ''
				? $property_type !== 'query'
				: in_array( explode( '|', $sub_key )[0], [ 'useQueryEditor', 'queryEditor', 'signature' ], true );
		}

		if ( $element_name === 'svg' && $setting_key === 'code' && $sub_key === '' && \Bricks\Capabilities::current_user_can_execute_code() ) {
			return false;
		}

		return in_array(
			$setting_key,
			[
				'executeCode',
				'code',
				'cssCode',
				'javascriptCode',
				'useDynamicData',
				'parseDynamicData',
				'supressPhpErrors',
				'noRoot',
				'noRootForce',
				'signature',
			],
			true
		);
	}

	/**
	 * Normalize select/class property options.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $options Caller-supplied options.
	 * @param string $path    Error path.
	 * @return array|\WP_Error
	 */
	private static function normalize_component_property_options( $options, string $path ) {
		if ( $options === null || $options === [] ) {
			return [];
		}

		if ( ! is_array( $options ) ) {
			return Error::invalid_param( $path, 'an array of option objects', $options );
		}

		$normalized   = [];
		$allowed_keys = [ 'id', 'label', 'value' ];
		$seen_ids     = [];

		foreach ( array_values( $options ) as $index => $option ) {
			if ( ! is_array( $option ) ) {
				return Error::invalid_param( "{$path}[{$index}]", 'an option object', $option );
			}

			foreach ( array_keys( $option ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return Error::invalid_param( "{$path}[{$index}].{$key}", 'one of: ' . implode( ', ', $allowed_keys ), $option[ $key ] );
				}
			}

			if ( ! array_key_exists( 'value', $option ) ) {
				return Error::missing_param( "{$path}[{$index}].value" );
			}

			$option_id = trim( (string) ( $option['id'] ?? '' ) );

			if ( $option_id === '' ) {
				do {
					$option_id = \Bricks\Helpers::generate_random_id( false );
				} while ( isset( $seen_ids[ $option_id ] ) );
			} elseif ( isset( $seen_ids[ $option_id ] ) ) {
				return Error::invalid_param( "{$path}[{$index}].id", 'a unique option id within this property', $option_id );
			}

			$seen_ids[ $option_id ] = true;

			$item = [
				'id'    => $option_id,
				'value' => $option['value'],
			];

			if ( array_key_exists( 'label', $option ) ) {
				$item['label'] = (string) $option['label'];
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize component property groups.
	 *
	 * @since 2.4
	 *
	 * @param mixed $groups Caller-supplied groups.
	 * @return array|\WP_Error
	 */
	private static function normalize_component_property_groups( $groups ) {
		if ( $groups === null || $groups === [] ) {
			return [];
		}

		if ( ! is_array( $groups ) ) {
			return Error::invalid_param( 'propertyGroups', 'an array of { id, name } objects', $groups );
		}

		$normalized   = [];
		$allowed_keys = [ 'id', 'name' ];

		foreach ( array_values( $groups ) as $index => $group ) {
			if ( ! is_array( $group ) ) {
				return Error::invalid_param( "propertyGroups[{$index}]", 'an object with { id, name }', $group );
			}

			foreach ( array_keys( $group ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return Error::invalid_param( "propertyGroups[{$index}].{$key}", 'one of: ' . implode( ', ', $allowed_keys ), $group[ $key ] );
				}
			}

			$id   = trim( (string) ( $group['id'] ?? '' ) );
			$name = trim( (string) ( $group['name'] ?? '' ) );

			if ( $id === '' ) {
				return Error::invalid_param( "propertyGroups[{$index}].id", 'a non-empty string', $group['id'] ?? null );
			}

			if ( $name === '' ) {
				return Error::invalid_param( "propertyGroups[{$index}].name", 'a non-empty string', $group['name'] ?? null );
			}

			$normalized[] = [
				'id'   => $id,
				'name' => $name,
			];
		}

		return $normalized;
	}

	/**
	 * Normalize component variants.
	 *
	 * @since 2.4
	 *
	 * @param mixed $variants Caller-supplied variants.
	 * @return array|\WP_Error
	 */
	private static function normalize_component_variants( $variants ) {
		if ( $variants === null || $variants === [] ) {
			return [];
		}

		if ( ! is_array( $variants ) ) {
			return Error::invalid_param( 'variants', 'an array of { id, name } objects', $variants );
		}

		$normalized   = [];
		$allowed_keys = [ 'id', 'name' ];

		foreach ( array_values( $variants ) as $index => $variant ) {
			if ( ! is_array( $variant ) ) {
				return Error::invalid_param( "variants[{$index}]", 'an object with { id, name }', $variant );
			}

			foreach ( array_keys( $variant ) as $key ) {
				if ( ! in_array( $key, $allowed_keys, true ) ) {
					return Error::invalid_param( "variants[{$index}].{$key}", 'one of: ' . implode( ', ', $allowed_keys ), $variant[ $key ] );
				}
			}

			$id   = trim( (string) ( $variant['id'] ?? '' ) );
			$name = trim( (string) ( $variant['name'] ?? '' ) );

			if ( ! preg_match( '/^variant-[A-Za-z0-9_-]+$/', $id ) ) {
				return Error::invalid_param( "variants[{$index}].id", 'a string starting with "variant-"', $variant['id'] ?? null );
			}

			if ( $name === '' ) {
				return Error::invalid_param( "variants[{$index}].name", 'a non-empty string', $variant['name'] ?? null );
			}

			$normalized[] = [
				'id'   => $id,
				'name' => $name,
			];
		}

		return $normalized;
	}

	/**
	 * Apply optional component fields that live on the component record itself.
	 *
	 * @since 2.4
	 *
	 * @param array $component Component record.
	 * @param array $input     Ability input.
	 * @return true|\WP_Error
	 */
	private static function apply_component_optional_fields( array &$component, array $input ) {
		if ( array_key_exists( 'blockEditor', $input ) ) {
			if ( ! empty( $input['blockEditor'] ) ) {
				$component['blockEditor'] = 1;
			} else {
				unset( $component['blockEditor'] );
			}
		}

		if ( array_key_exists( 'blockCategory', $input ) ) {
			$block_category = trim( (string) $input['blockCategory'] );

			if ( $block_category === '' ) {
				unset( $component['blockCategory'] );
			} else {
				$component['blockCategory'] = $block_category;
			}
		}

		foreach ( [ 'blockIcon', 'blockPreviewImage' ] as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( $input[ $key ] === null || $input[ $key ] === [] || $input[ $key ] === '' ) {
				unset( $component[ $key ] );
				continue;
			}

			if ( ! is_array( $input[ $key ] ) ) {
				return Error::invalid_param( $key, 'an object, or empty/null to clear', $input[ $key ] );
			}

			$component[ $key ] = $input[ $key ];
		}

		return true;
	}

	/**
	 * Prepare component element data before saving it into the global component store.
	 *
	 * Component definitions do not go through Save_Pipeline because they live in
	 * an option, not post meta. Keep their validation and sensitive-code checks
	 * aligned with normal element writes before update_option() persists them.
	 *
	 * @since 2.4
	 *
	 * @param array $incoming Incoming component elements.
	 * @param array $existing Existing component elements, when updating.
	 * @return array|\WP_Error
	 */
	private static function prepare_component_elements_for_save( array $incoming, array $existing = [] ) {
		$incoming = self::rebuild_component_children( $incoming );
		$existing = self::rebuild_component_children( $existing );
		$incoming = Element_Style_Normalizer::normalize_elements( $incoming, true );

		$permission_check = Elements::check_full_tree_permissions( $existing, $incoming );

		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		$code_check = self::check_component_code_permissions( $existing, $incoming );

		if ( is_wp_error( $code_check ) ) {
			return $code_check;
		}

		$valid = Element_Validator::validate( $incoming );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return Elements::sign_authorized_code( $existing, $incoming );
	}

	/**
	 * Rebuild `children` arrays from parent references.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Flat element rows.
	 * @return array
	 */
	private static function rebuild_component_children( array $elements ): array {
		$children_map = [];

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( $id === '' ) {
				continue;
			}

			$parent_key = (string) ( $element['parent'] ?? 0 );

			if ( ! isset( $children_map[ $parent_key ] ) ) {
				$children_map[ $parent_key ] = [];
			}

			$children_map[ $parent_key ][] = $id;
		}

		foreach ( $elements as &$element ) {
			$id                  = $element['id'] ?? '';
			$element['children'] = $children_map[ $id ] ?? [];
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Prevent component writes from authoring executable payloads.
	 *
	 * @since 2.4
	 *
	 * @param array $existing Existing component elements.
	 * @param array $incoming Incoming component elements.
	 * @return true|\WP_Error
	 */
	private static function check_component_code_permissions( array $existing, array $incoming ) {
		return Elements::check_code_sensitive_permissions( $existing, $incoming );
	}

	/**
	 * Reindex every element id in a subtree. Returns the new root id and
	 * the remapped elements array, preserving parent/child relationships.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements             Input elements.
	 * @param string $label                Label to stamp on the root.
	 * @return array { rootId, rootOldId, idMap, elements }
	 */
	private static function reindex_component_elements( $elements, $label ) {
		$id_map    = [];
		$reindexed = [];

		// First pass: assign new ids.
		foreach ( $elements as $el ) {
			$old_id            = $el['id'] ?? '';
			$id_map[ $old_id ] = \Bricks\Helpers::generate_random_id( false );
		}

		$root_old_id = $elements[0]['id'] ?? '';
		$root_new_id = $id_map[ $root_old_id ] ?? \Bricks\Helpers::generate_random_id( false );

		// Second pass: remap ids + parent + children + label.
		foreach ( $elements as $el ) {
			$old_id   = $el['id'] ?? '';
			$el['id'] = $id_map[ $old_id ] ?? \Bricks\Helpers::generate_random_id( false );

			$parent = $el['parent'] ?? 0;

			if ( $old_id === $root_old_id ) {
				$el['parent'] = 0;
				$el['label']  = $label;
			} elseif ( isset( $id_map[ $parent ] ) ) {
				$el['parent'] = $id_map[ $parent ];
			} else {
				$el['parent'] = $root_new_id;
			}

			if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_values(
					array_map(
						static function ( $child_id ) use ( $id_map ) {
							return $id_map[ $child_id ] ?? $child_id;
						},
						$el['children']
					)
				);
			}

			if ( isset( $el['slotChildren'] ) && is_array( $el['slotChildren'] ) ) {
				$el['slotChildren'] = self::remap_slot_children( $el['slotChildren'], $id_map );
			}

			$el = self::remap_parent_property_references_in_value( $el, $id_map );

			$reindexed[] = $el;
		}

		return [
			'rootId'    => $root_new_id,
			'rootOldId' => $root_old_id,
			'idMap'     => $id_map,
			'elements'  => $reindexed,
		];
	}

	/**
	 * Remap slot child ids after cloning elements.
	 *
	 * `slotChildren` keys point at slot ids on the referenced component, while
	 * values point at local element ids. Only the local child ids are remapped.
	 *
	 * @since 2.4
	 *
	 * @param array $slot_children Slot children map.
	 * @param array $id_map        Old-to-new element id map.
	 * @return array
	 */
	private static function remap_slot_children( array $slot_children, array $id_map ): array {
		$remapped = [];

		foreach ( $slot_children as $slot_id => $children ) {
			if ( ! is_array( $children ) ) {
				continue;
			}

			$remapped[ $slot_id ] = array_values(
				array_map(
					static function ( $child_id ) use ( $id_map ) {
						return $id_map[ $child_id ] ?? $child_id;
					},
					$children
				)
			);
		}

		return $remapped;
	}

	/**
	 * Remap component property connections after element id rewrites.
	 *
	 * @since 2.4
	 *
	 * @param array $properties Properties array.
	 * @param array $id_map     Old-to-new element id map.
	 * @return array
	 */
	private static function remap_component_properties( array $properties, array $id_map ): array {
		if ( empty( $id_map ) ) {
			return $properties;
		}

		foreach ( $properties as &$property ) {
			if ( ! is_array( $property ) || empty( $property['connections'] ) || ! is_array( $property['connections'] ) ) {
				continue;
			}

			$connections = [];

			foreach ( $property['connections'] as $element_id => $control_keys ) {
				$element_id = (string) $element_id;
				$new_id     = $id_map[ $element_id ] ?? $element_id;

				if ( ! isset( $connections[ $new_id ] ) ) {
					$connections[ $new_id ] = [];
				}

				if ( is_array( $control_keys ) ) {
					$connections[ $new_id ] = array_values( array_unique( array_merge( $connections[ $new_id ], $control_keys ) ) );
				}
			}

			$property['connections'] = $connections;
		}

		unset( $property );

		return $properties;
	}

	/**
	 * Remap parent-property references stored inside nested component instances.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value  Value to inspect.
	 * @param array $id_map Old-to-new element id map.
	 * @return mixed
	 */
	private static function remap_parent_property_references_in_value( $value, array $id_map ) {
		if ( is_string( $value ) && preg_match( '/^parent:cid_([^:]+):prop_([^:]+)$/', $value, $matches ) ) {
			$component_id = $matches[1];
			$property_id  = $matches[2];

			if ( isset( $id_map[ $component_id ] ) ) {
				return 'parent:cid_' . $id_map[ $component_id ] . ':prop_' . $property_id;
			}

			return $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child_value ) {
			$value[ $key ] = self::remap_parent_property_references_in_value( $child_value, $id_map );
		}

		return $value;
	}

	/**
	 * Normalize caller-supplied elements for update-component:
	 * force the root id to stay equal to the existing componentId so
	 * instances in the wild don't lose their reference.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements     Incoming elements.
	 * @param string $component_id Current component id.
	 * @return array
	 */
	private static function normalize_update_elements( $elements, $component_id ) {
		if ( empty( $elements ) ) {
			return [];
		}

		$incoming_root_id = $elements[0]['id'] ?? '';

		$id_map = $incoming_root_id !== '' && $incoming_root_id !== $component_id ? [ $incoming_root_id => $component_id ] : [];

		$normalized = [];

		foreach ( $elements as $el ) {
			$id = $el['id'] ?? '';

			if ( $incoming_root_id !== '' && $id === $incoming_root_id ) {
				$el['id'] = $component_id;
			}

			if ( $incoming_root_id !== '' && ( $el['parent'] ?? '' ) === $incoming_root_id ) {
				$el['parent'] = $component_id;
			}

			if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_values(
					array_map(
						static function ( $child_id ) use ( $incoming_root_id, $component_id ) {
							return $child_id === $incoming_root_id ? $component_id : $child_id;
						},
						$el['children']
					)
				);
			}

			if ( isset( $el['slotChildren'] ) && is_array( $el['slotChildren'] ) ) {
				$el['slotChildren'] = self::remap_slot_children( $el['slotChildren'], $id_map );
			}

			$el = self::remap_parent_property_references_in_value( $el, $id_map );

			$normalized[] = $el;
		}

		return $normalized;
	}

	/**
	 * Preserve slot IDs during component tree replacement where possible.
	 *
	 * Slot instance content is keyed by the component slot element ID, so silently
	 * changing slot IDs can hide user-authored content across every instance.
	 *
	 * @since 2.4
	 *
	 * @param array $current  Existing component row.
	 * @param array $incoming Incoming normalized element tree.
	 * @return array ID map and removed slot IDs.
	 */
	private static function component_slot_update_plan( array $current, array $incoming ): array {
		$current_slots  = self::component_slot_rows( is_array( $current['elements'] ?? null ) ? $current['elements'] : [] );
		$incoming_slots = self::component_slot_rows( $incoming );

		if ( empty( $current_slots ) && empty( $incoming_slots ) ) {
			return [
				'idMap'          => [],
				'removedSlotIds' => [],
			];
		}

		$current_ids  = array_column( $current_slots, 'id' );
		$incoming_ids = array_column( $incoming_slots, 'id' );
		$id_map       = [];

		$missing_current_slots = array_values(
			array_filter(
				$current_slots,
				static function ( $slot ) use ( $incoming_ids ) {
					return ! in_array( $slot['id'], $incoming_ids, true );
				}
			)
		);

		$new_incoming_slots = array_values(
			array_filter(
				$incoming_slots,
				static function ( $slot ) use ( $current_ids ) {
					return ! in_array( $slot['id'], $current_ids, true );
				}
			)
		);

		foreach ( $new_incoming_slots as $index => $incoming_slot ) {
			if ( empty( $missing_current_slots[ $index ]['id'] ) ) {
				break;
			}

			$id_map[ $incoming_slot['id'] ] = $missing_current_slots[ $index ]['id'];
		}

		$final_incoming_ids = array_map(
			static function ( $slot_id ) use ( $id_map ) {
				return $id_map[ $slot_id ] ?? $slot_id;
			},
			$incoming_ids
		);

		return [
			'idMap'          => $id_map,
			'removedSlotIds' => array_values( array_diff( $current_ids, $final_incoming_ids ) ),
		];
	}

	/**
	 * Validate and record a fresh usage audit for actual slot removals.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id    Component ID.
	 * @param array  $removed_slot_ids Removed slot IDs after stable remapping.
	 * @param array  $input            Ability input.
	 * @return array|null|\WP_Error Audit, null when no slots are removed, or error.
	 */
	private static function component_slot_removal_audit( string $component_id, array $removed_slot_ids, array $input ) {
		if ( empty( $removed_slot_ids ) ) {
			return null;
		}

		$usage_authority = self::validate_component_usage_scan_authority( $component_id, 'remove_slots' );

		if ( is_wp_error( $usage_authority ) ) {
			return $usage_authority;
		}

		$slot_usage = self::get_component_slot_usage( $component_id );

		if ( is_wp_error( $slot_usage ) ) {
			return $slot_usage;
		}

		$orphaned = [];

		foreach ( $removed_slot_ids as $slot_id ) {
			if ( ! empty( $slot_usage[ $slot_id ] ) ) {
				$orphaned[ $slot_id ] = $slot_usage[ $slot_id ];
			}
		}

		if ( ( $input['allowSlotOrphans'] ?? null ) !== true ) {
			return Error::conflict(
				'component_slot_removal_confirmation_required',
				[
					'message'          => 'This update removes existing component slots. Preserve the slots, or pass allowSlotOrphans=true to explicitly accept possible orphaned instance content. This acknowledgement is required even when the fresh usage scan finds no content because usage discovery is evidence, not a guarantee.',
					'componentId'      => $component_id,
					'removedSlotIds'   => $removed_slot_ids,
					'orphanedSlotUses' => $orphaned,
				]
			);
		}

		return [
			'allowSlotOrphans' => true,
			'removedSlotIds'   => $removed_slot_ids,
			'orphanedSlotUses' => $orphaned,
			'coverage'         => self::current_site_usage_coverage(),
		];
	}

	/**
	 * Require a complete local reference scan before destructive component changes.
	 *
	 * A subsite cannot discover references from every site that shares main-site
	 * components, so its local zero-usage result is not sufficient evidence.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @param string $operation    Destructive operation.
	 * @return true|\WP_Error
	 */
	private static function validate_component_usage_scan_authority( string $component_id, string $operation ) {
		if (
			function_exists( 'is_multisite' ) &&
			is_multisite() &&
			Design_Option_Store::authority_site_id( BRICKS_DB_COMPONENTS ) !== (int) get_current_blog_id()
		) {
			return Error::conflict(
				'component_usage_scan_main_site_authority',
				[
					'message'     => 'This destructive component operation is unavailable from a subsite while components use main-site authority. Run it on the component authority site so reference evidence is not limited to the current subsite.',
					'componentId' => $component_id,
					'operation'   => $operation,
				]
			);
		}

		return true;
	}

	/**
	 * Return slot element rows in tree order.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Component elements.
	 * @return array
	 */
	private static function component_slot_rows( array $elements ): array {
		$slots = [];

		foreach ( array_values( $elements ) as $index => $element ) {
			$id = (string) ( $element['id'] ?? '' );

			if ( $id !== '' && ( $element['name'] ?? '' ) === 'slot' ) {
				$slots[] = [
					'id'    => $id,
					'index' => $index,
				];
			}
		}

		return $slots;
	}

	/**
	 * Remap element IDs and references inside a component element tree.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element tree.
	 * @param array $id_map   Old-to-new ID map.
	 * @return array
	 */
	private static function remap_component_element_ids( array $elements, array $id_map ): array {
		if ( empty( $id_map ) ) {
			return $elements;
		}

		foreach ( $elements as &$element ) {
			if ( isset( $element['id'], $id_map[ $element['id'] ] ) ) {
				$element['id'] = $id_map[ $element['id'] ];
			}

			if ( isset( $element['parent'], $id_map[ $element['parent'] ] ) ) {
				$element['parent'] = $id_map[ $element['parent'] ];
			}

			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$element['children'] = array_values(
					array_map(
						static function ( $child_id ) use ( $id_map ) {
							return $id_map[ $child_id ] ?? $child_id;
						},
						$element['children']
					)
				);
			}

			if ( isset( $element['slotChildren'] ) && is_array( $element['slotChildren'] ) ) {
				$element['slotChildren'] = self::remap_slot_children( $element['slotChildren'], $id_map );
			}

			$element = self::remap_parent_property_references_in_value( $element, $id_map );
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Find existing slot content for a component by slot ID.
	 *
	 * @since 2.4
	 *
	 * @param string $component_id Component ID.
	 * @return array|\WP_Error
	 */
	private static function get_component_slot_usage( string $component_id ) {
		$usage           = [];
		$bricks_post_ids = \Bricks\Helpers::get_all_bricks_post_ids();
		$template_ids    = \Bricks\Templates::get_all_template_ids();
		$post_ids        = array_merge( $bricks_post_ids, $template_ids );

		foreach ( $post_ids as $post_id ) {
			$type = 'content';

			if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				$type = \Bricks\Templates::get_template_type( $post_id );
			}

			$elements = \Bricks\Database::get_data( $post_id, $type );
			self::collect_component_slot_usage_from_elements(
				is_array( $elements ) ? $elements : [],
				$component_id,
				[
					'postId'     => $post_id,
					'postTitle'  => get_the_title( $post_id ),
					'postType'   => get_post_type( $post_id ),
					'targetType' => 'post',
				],
				$usage
			);
		}

		$component_snapshot = Design_Option_Store::read( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = $component_snapshot['value'];

		if ( is_array( $components ) ) {
			foreach ( $components as $component ) {
				$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];

				self::collect_component_slot_usage_from_elements(
					$elements,
					$component_id,
					[
						'componentId'    => $component['id'] ?? '',
						'componentLabel' => self::component_label( $component ),
						'targetType'     => 'component',
					],
					$usage
				);
			}
		}

		return $usage;
	}

	/**
	 * Collect component slot usage from an element array.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements     Elements to scan.
	 * @param string $component_id Component ID.
	 * @param array  $context      Usage context.
	 * @param array  $usage        Usage map, by reference.
	 */
	private static function collect_component_slot_usage_from_elements( array $elements, string $component_id, array $context, array &$usage ): void {
		foreach ( $elements as $element ) {
			if ( ( $element['cid'] ?? '' ) !== $component_id || empty( $element['slotChildren'] ) || ! is_array( $element['slotChildren'] ) ) {
				continue;
			}

			foreach ( $element['slotChildren'] as $slot_id => $child_ids ) {
				if ( ! is_array( $child_ids ) || empty( $child_ids ) ) {
					continue;
				}

				$slot_id = (string) $slot_id;

				if ( ! isset( $usage[ $slot_id ] ) ) {
					$usage[ $slot_id ] = [];
				}

				$usage[ $slot_id ][] = array_merge(
					$context,
					[
						'elementId'  => $element['id'] ?? '',
						'childCount' => count( $child_ids ),
					]
				);
			}
		}
	}

	/**
	 * Walk children to collect every id under a given root id.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements Elements tree.
	 * @param string $root_id  Root id.
	 * @return array
	 */
	private static function collect_descendant_ids( $elements, $root_id ) {
		$by_id = [];

		foreach ( $elements as $el ) {
			$by_id[ $el['id'] ?? '' ] = $el;
		}

		$ids   = [ $root_id ];
		$stack = [ $root_id ];

		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			$el      = $by_id[ $current ] ?? null;

			if ( ! $el ) {
				continue;
			}

			$children = is_array( $el['children'] ?? null ) ? $el['children'] : [];

			if ( isset( $el['slotChildren'] ) && is_array( $el['slotChildren'] ) ) {
				foreach ( $el['slotChildren'] as $slot_child_ids ) {
					if ( is_array( $slot_child_ids ) ) {
						$children = array_merge( $children, $slot_child_ids );
					}
				}
			}

			foreach ( $children as $child_id ) {
				if ( ! in_array( $child_id, $ids, true ) ) {
					$ids[]   = $child_id;
					$stack[] = $child_id;
				}
			}
		}

		return $ids;
	}

	// ==================================================================
	// GLOBAL VARIABLES
	// ==================================================================

	/**
	 * Input schema for list-global-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_global_variables_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'category' => [
						'type'        => 'string',
						'description' => __( 'Filter by category ID. Omit to list all variables.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-global-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_global_variables_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'             => [
					'type'        => 'array',
					'description' => __( 'Array of global variables with id, name, value, category, and ready itemOwnership. Returned rows can be passed directly to set-global-variables.', 'bricks' ),
				],
				'total'             => [ 'type' => 'integer' ],
				'page'              => [ 'type' => 'integer' ],
				'perPage'           => [ 'type' => 'integer' ],
				'hasMore'           => [ 'type' => 'boolean' ],
				'categories'        => [
					'type'        => 'array',
					'description' => __( 'Array of variable categories with id, name, scale config, and ready itemOwnership.', 'bricks' ),
				],
				'variableOwnership' => [ 'type' => 'object' ],
				'categoryOwnership' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: list all global variables
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_global_variables( $input ) {
		Manager::flush_options_cache();
		$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$category_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		if ( is_wp_error( $category_snapshot ) ) {
			return $category_snapshot;
		}

		$variables  = $variable_snapshot['value'];
		$categories = $category_snapshot['value'];

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		$variable_ownership = self::design_ownership( self::GLOBAL_VARIABLES_RESOURCE, $variable_snapshot );
		$category_ownership = self::design_ownership( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, $category_snapshot );

		if ( is_wp_error( $variable_ownership ) ) {
			return $variable_ownership;
		}

		if ( is_wp_error( $category_ownership ) ) {
			return $category_ownership;
		}

		// Filter by category
		$category = $input['category'] ?? '';

		if ( $category ) {
			$variables = array_values(
				array_filter(
					$variables,
					function ( $var ) use ( $category ) {
						return ( $var['category'] ?? '' ) === $category;
					}
				)
			);
		}

		$result = Reference::paginate( $variables, $input );

		foreach ( $result['items'] as &$variable ) {
			$variable['itemOwnership'] = self::item_ownership( $variable_ownership, $variable );
		}
		unset( $variable );

		$categories = is_array( $categories ) ? $categories : [];
		foreach ( $categories as &$category_row ) {
			$category_row['itemOwnership'] = self::item_ownership( $category_ownership, $category_row );
		}
		unset( $category_row );

		$result['categories']        = $categories;
		$result['variableOwnership'] = $variable_ownership;
		$result['categoryOwnership'] = $category_ownership;

		return $result;
	}

	/**
	 * Input schema for set-global-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_global_variables_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'variables'                 => [
					'type'        => 'array',
					'description' => __( 'Array of variables to upsert. Each: { id, name, value, category, scale?, paletteImportSource? }. Preserve paletteImportSource from returned rows: it identifies unchanged copies awaiting a palette import. Ready rows from list-global-variables can be passed directly; response-only itemDigest and itemOwnership are stripped before validation. Variables with matching id are updated, new variables are appended. Existing variables not in this array are preserved. For typography/spacing scale variables, use bricks/generate-scale-variables.', 'bricks' ),
					'required'    => true,
				],
				'categories'                => [
					'type'        => 'array',
					'description' => __( 'Reserved for a future atomic variables-and-categories transaction. Omit this field: combined category writes are currently rejected before any mutation so a conflict cannot leave the two stores torn.', 'bricks' ),
				],
				'expectedVariableOwnership' => self::ownership_input_schema(
					__( 'Required exact variable ownership from the latest list or variable write.', 'bricks' )
				),
				'expectedCategoryOwnership' => self::ownership_input_schema(
					__( 'Required exact category ownership from the same latest list. Categories are a guarded validation dependency.', 'bricks' )
				),
			],
			'required'   => [ 'variables', 'expectedVariableOwnership', 'expectedCategoryOwnership' ],
		];
	}

	/**
	 * Output schema for set-global-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_global_variables_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'variables'         => [
					'type'        => 'array',
					'description' => __( 'The saved variables array.', 'bricks' ),
				],
				'categories'        => [
					'type'        => 'array',
					'description' => __( 'The saved categories array.', 'bricks' ),
				],
				'warnings'          => [
					'type'        => 'array',
					'description' => __( 'Non-blocking warnings about category mismatches or scale categories that should use generate-scale-variables.', 'bricks' ),
				],
				'variableOwnership' => [ 'type' => 'object' ],
				'categoryOwnership' => [ 'type' => 'object' ],
				'itemOwnerships'    => [ 'type' => 'object' ],
				'changed'           => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: set-global-variables
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_global_variables( $input ) {
		Manager::flush_options_cache();
		$incoming = $input['variables'];

		if ( ! is_array( $incoming ) ) {
			return Error::invalid_param( 'variables', 'an array of variable objects', $incoming );
		}

		if ( array_key_exists( 'categories', $input ) ) {
			return Error::conflict(
				'global_variable_categories_not_atomic',
				[
					'message' => 'Combined variable and category writes are not yet atomic. Omit categories; no variable or category data was changed.',
				]
			);
		}

		$expected_variable_ownership = $input['expectedVariableOwnership'] ?? null;
		$expected_category_ownership = $input['expectedCategoryOwnership'] ?? null;

		if ( ! is_array( $expected_variable_ownership ) ) {
			return Error::invalid_param( 'expectedVariableOwnership', 'variable ownership from the latest list or write', $expected_variable_ownership );
		}

		if ( ! is_array( $expected_category_ownership ) ) {
			return Error::invalid_param( 'expectedCategoryOwnership', 'category ownership from the same latest list', $expected_category_ownership );
		}

		// list-global-variables returns copy-ready rows with ownership metadata.
		// Strip only the response-level fields before canonical shape validation.
		foreach ( $incoming as &$variable ) {
			if ( is_array( $variable ) ) {
				unset( $variable['itemDigest'], $variable['itemOwnership'] );
			}
		}
		unset( $variable );

		// Validate variable structure
		$allowed_keys = [ 'id', 'name', 'value', 'category', 'scale', 'paletteImportSource' ];

		// Track which incoming rows arrived WITHOUT a caller-supplied id. The
		// auto-generated id below would otherwise make a name-only call look like
		// an explicit-different-id call to the (category,name) upsert and trip the
		// duplicate-name conflict, defeating idempotent name-only re-saves.
		$caller_supplied_id = [];

		foreach ( $incoming as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				return Error::invalid_param( "variables[{$index}]", 'an object with { id, name, value, category }', $variable );
			}

			if ( empty( $variable['name'] ) || ! is_string( $variable['name'] ) ) {
				return Error::invalid_param( "variables[{$index}].name", 'a non-empty string', $variable['name'] ?? null );
			}

			$normalized_name = self::normalize_global_variable_name( $variable['name'] );

			if ( $normalized_name === null ) {
				return Error::invalid_param( "variables[{$index}].name", 'a CSS custom-property-compatible name such as 2xl, 100, -local, brand-primary, or --brand (without whitespace or delimiters)', $variable['name'] );
			}

			$incoming[ $index ]['name'] = $normalized_name;

			if ( ! isset( $variable['value'] ) ) {
				return Error::invalid_param( "variables[{$index}].value", 'a defined value', null );
			}

			if ( ! is_string( $variable['value'] ) && ! is_numeric( $variable['value'] ) ) {
				return Error::invalid_param( "variables[{$index}].value", 'a string or number', $variable['value'] );
			}

			// Preserve Builder import provenance in copy-ready variable rows.
			if ( array_key_exists( 'paletteImportSource', $variable ) ) {
				$source      = $variable['paletteImportSource'];
				$source_keys = [ 'paletteId', 'colorId', 'name', 'value' ];
				if ( ! is_array( $source ) || count( $source ) !== count( $source_keys ) || array_diff( array_keys( $source ), $source_keys ) ) {
					return Error::invalid_param( "variables[{$index}].paletteImportSource", 'an object with paletteId, colorId, name, and value', $source );
				}
				foreach ( $source_keys as $source_key ) {
					if ( ! is_string( $source[ $source_key ] ) || $source[ $source_key ] === '' ) {
						return Error::invalid_param( "variables[{$index}].paletteImportSource.{$source_key}", 'a non-empty string', $source[ $source_key ] );
					}
				}
			}

			// Reject unknown keys - Vue Variable Manager renders only the canonical
			// shape and trips on extras (the scale generator's `step` / `ratio` keys
			// were the original repro for this).
			$unknown = array_diff( array_keys( $variable ), $allowed_keys );

			if ( ! empty( $unknown ) ) {
				return Error::unknown_param(
					"variables[{$index}]." . reset( $unknown ),
					$allowed_keys
				);
			}

			$caller_supplied_id[ $index ] = ! empty( $variable['id'] );

			// Generate ID if missing
			if ( empty( $variable['id'] ) ) {
				$incoming[ $index ]['id'] = \Bricks\Helpers::generate_random_id( false );
			}
		}

		$category_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

		if ( is_wp_error( $category_snapshot ) ) {
			return $category_snapshot;
		}

		$category_valid = Design_Option_Store::validate_ownership(
			self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE,
			$category_snapshot,
			$expected_category_ownership
		);

		if ( is_wp_error( $category_valid ) ) {
			return $category_valid;
		}

		$categories_for_validation = $category_snapshot['value'];
		$category_ids              = [];

		foreach ( is_array( $categories_for_validation ) ? $categories_for_validation : [] as $category ) {
			if ( ! empty( $category['id'] ) ) {
				$category_ids[ (string) $category['id'] ] = true;
			}
		}

		foreach ( $incoming as $index => $variable ) {
			$category_id = (string) ( $variable['category'] ?? '' );

			if ( $category_id !== '' && ! isset( $category_ids[ $category_id ] ) ) {
				return Error::invalid_param( "variables[{$index}].category", 'an existing variable category ID or an empty string', $category_id );
			}
		}

		$scale_validation = self::validate_scale_variable_writes( $incoming, $categories_for_validation );

		if ( is_wp_error( $scale_validation ) ) {
			return $scale_validation;
		}

		// Load existing variables and merge by ID
		$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		$existing = $variable_snapshot['value'];

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$palette_snapshot = self::read_color_palette_snapshot();

		if ( is_wp_error( $palette_snapshot ) ) {
			return $palette_snapshot;
		}

		// Build lookup of existing variables by ID
		$existing_by_id = [];

		foreach ( $existing as $index => $var ) {
			$id = $var['id'] ?? '';

			if ( $id ) {
				$existing_by_id[ $id ] = $index;
			}
		}

		// Build lookup of existing variables by (category, name) so name-only callers stay idempotent.
		// Without this, a repeat call that omits `id` generates a fresh id and appends a duplicate.
		$existing_by_category_name = [];

		foreach ( $existing as $index => $var ) {
			$var_name     = $var['name'] ?? '';
			$var_category = $var['category'] ?? '';

			if ( $var_name === '' ) {
				continue;
			}

			$key                               = $var_category . "\x00" . $var_name;
			$existing_by_category_name[ $key ] = $index;
		}

		// Upsert: update existing by ID, then by (category,name), else append new.
		foreach ( $incoming as $i => $variable ) {
			$id           = $variable['id'] ?? '';
			$var_name     = $variable['name'] ?? '';
			$var_category = $variable['category'] ?? '';
			$name_key     = $var_category . "\x00" . $var_name;

			if ( $id && isset( $existing_by_id[ $id ] ) ) {
				$matched_index = $existing_by_id[ $id ];

				if ( (string) ( $existing[ $matched_index ]['name'] ?? '' ) !== $var_name ) {
					return Error::conflict(
						'global_variable_reference_rewrite_not_atomic',
						[
							'message'      => 'Renaming a global variable cannot atomically rewrite every CSS reference. No variable data was changed.',
							'variableId'   => $id,
							'oldName'      => (string) ( $existing[ $matched_index ]['name'] ?? '' ),
							'newName'      => $var_name,
							'beforeUpdate' => $existing[ $matched_index ],
							'proposed'     => $variable,
						]
					);
				}

				$existing[ $matched_index ] = array_merge( $existing[ $matched_index ], $variable );
				continue;
			}

			// Caller passed a name that already exists in this category.
			if ( $var_name !== '' && isset( $existing_by_category_name[ $name_key ] ) ) {
				$matched_index       = $existing_by_category_name[ $name_key ];
				$matched_existing_id = $existing[ $matched_index ]['id'] ?? '';

				// Explicit different id pointing at the same (category,name) is a conflict.
				// Only when the CALLER supplied that id — an auto-generated one means a
				// name-only re-save, which should upsert in place (below), not conflict.
				if ( ! empty( $caller_supplied_id[ $i ] ) && $matched_existing_id && $id !== $matched_existing_id ) {
					return Error::conflict_duplicate_name(
						'global_variable',
						$var_name,
						[
							'category'   => $var_category,
							'existingId' => $matched_existing_id,
							'incomingId' => $id,
							'index'      => $i,
						]
					);
				}

				// Name-only upsert: reuse the existing id so we replace in place.
				$variable['id']                    = $matched_existing_id ? $matched_existing_id : $id;
				$existing[ $matched_index ]        = array_merge( $existing[ $matched_index ], $variable );
				$existing_by_id[ $variable['id'] ] = $matched_index;
				continue;
			}

			// Fresh row - append.
			$existing[] = $variable;

			$new_index                               = count( $existing ) - 1;
			$existing_by_id[ $variable['id'] ?? '' ] = $new_index;
			if ( $var_name !== '' ) {
				$existing_by_category_name[ $name_key ] = $new_index;
			}
		}

		$variables   = array_values( $existing );
		$names_by_id = [];

		foreach ( $variables as $variable ) {
			$name = (string) ( $variable['name'] ?? '' );
			$id   = (string) ( $variable['id'] ?? '' );

			if ( isset( $names_by_id[ $name ] ) && $names_by_id[ $name ] !== $id ) {
				return Error::conflict_duplicate_name(
					'global_variable',
					$name,
					[
						'existingId' => $names_by_id[ $name ],
						'incomingId' => $id,
					]
				);
			}

			$names_by_id[ $name ] = $id;
		}

		$palette_names = [];

		foreach ( self::color_palettes_from_snapshot( $palette_snapshot ) as $palette ) {
			foreach ( is_array( $palette['colors'] ?? null ) ? $palette['colors'] : [] as $color ) {
				$palette_name = self::extract_palette_variable_name( $color['raw'] ?? '' );

				if ( $palette_name !== '' ) {
					$palette_names[ $palette_name ] = (string) ( $color['id'] ?? '' );
				}
			}
		}

		$collision_sensitive_names = [];
		foreach ( $incoming as $variable ) {
			$id = (string) ( $variable['id'] ?? '' );

			if ( ! isset( $existing_by_id[ $id ] ) ) {
				$collision_sensitive_names[] = (string) ( $variable['name'] ?? '' );
			}
		}

		$palette_same_authority = (int) $variable_snapshot['siteId'] === (int) $palette_snapshot['siteId'];

		if ( ! empty( $collision_sensitive_names ) && ! $palette_same_authority ) {
			return Error::conflict(
				'global_variable_palette_uniqueness_not_atomic',
				[
					'message'        => 'Variable and palette names use different site authorities, so creation cannot guard CSS custom-property uniqueness atomically. No variable data was changed.',
					'variableSiteId' => (int) $variable_snapshot['siteId'],
					'paletteSiteId'  => (int) $palette_snapshot['siteId'],
				]
			);
		}

		foreach ( array_unique( $collision_sensitive_names ) as $name ) {
			if ( isset( $palette_names[ $name ] ) ) {
				return Error::conflict(
					'global_variable_palette_name_collision',
					[
						'message'        => 'A palette color already emits the requested CSS custom-property name. No variable data was changed.',
						'variableName'   => $name,
						'paletteColorId' => $palette_names[ $name ],
					]
				);
			}
		}

		$same_authority    = (int) $variable_snapshot['siteId'] === (int) $category_snapshot['siteId'];
		$related_snapshots = $same_authority ? [ $category_snapshot ] : [];

		if ( $palette_same_authority ) {
			$related_snapshots[] = $palette_snapshot;
		}

		$save_result = self::save_global_variables_from_snapshot(
			$variable_snapshot,
			$variables,
			$expected_variable_ownership,
			$related_snapshots
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		if ( ! $same_authority ) {
			$category_after       = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
			$category_still_valid = is_wp_error( $category_after )
				? $category_after
				: Design_Option_Store::validate_ownership(
					self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE,
					$category_after,
					$expected_category_ownership
				);

			if ( is_wp_error( $category_still_valid ) ) {
				$rollback = Design_Option_Store::compare_and_swap(
					$save_result['snapshot'],
					is_array( $variable_snapshot['value'] ) ? $variable_snapshot['value'] : [],
					empty( $variable_snapshot['value'] ),
					self::DESIGN_SYSTEM_VERSION_OPTION,
					(int) $save_result['snapshot']['designSystemVersion']
				);

				if ( is_wp_error( $rollback ) ) {
					return Error::conflict(
						'global_variable_category_compensation_required',
						[
							'message'                => 'Variables committed after category validation, the category authority then changed, and the variable write could not be safely compensated. Re-read both stores and repair manually.',
							'manualRecoveryRequired' => true,
							'variablesCommitted'     => true,
							'categoryCommitted'      => false,
							'compensationErrorCode'  => $rollback->get_error_code(),
						]
					);
				}

				return $category_still_valid;
			}
		}

		$variable_readback = $save_result['snapshot'];
		$category_readback = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

		if ( is_wp_error( $category_readback ) ) {
			return self::committed_design_readback_error( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, '', $category_readback );
		}

		$variables          = is_array( $variable_readback['value'] ) ? $variable_readback['value'] : [];
		$categories         = is_array( $category_readback['value'] ) ? $category_readback['value'] : [];
		$category_ownership = self::design_ownership( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, $category_readback );
		$item_ownerships    = [];

		if ( is_wp_error( $category_ownership ) ) {
			return self::committed_design_readback_error( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, '', $category_ownership );
		}

		foreach ( $variables as $variable ) {
			if ( ! empty( $variable['id'] ) ) {
				$item_ownerships[ $variable['id'] ] = self::item_ownership( $save_result['ownership'], $variable );
			}
		}

		return [
			'variables'         => $variables,
			'categories'        => $categories,
			'warnings'          => self::global_variable_write_warnings( $incoming, $categories ),
			'variableOwnership' => $save_result['ownership'],
			'categoryOwnership' => $category_ownership,
			'itemOwnerships'    => $item_ownerships,
			'changed'           => $save_result['changed'],
		];
	}

	/**
	 * Normalize the optional public `--` prefix without changing CSS identity.
	 *
	 * @since 2.4
	 *
	 * @param string $name Incoming variable name.
	 * @return string|null
	 */
	private static function normalize_global_variable_name( string $name ) {
		$normalized = trim( $name );

		if ( strpos( $normalized, '--' ) === 0 ) {
			$normalized = substr( $normalized, 2 );
		}

		if ( $normalized === '' || $normalized === '-' || ! preg_match( '/^[A-Za-z0-9_-]+$/', $normalized ) ) {
			return null;
		}

		return $normalized;
	}

	/**
	 * Build non-blocking warnings for global-variable writes.
	 *
	 * @since 2.4
	 *
	 * @param array $incoming   Incoming variables from the current write.
	 * @param array $categories Current variable categories.
	 * @return array
	 */
	private static function global_variable_write_warnings( $incoming, $categories ) {
		$warnings = [];

		if ( empty( $categories ) || ! is_array( $categories ) ) {
			return $warnings;
		}

		$category_ids     = [];
		$scale_categories = [];

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) ) {
				continue;
			}

			$category_ids[ $category['id'] ] = true;

			if ( ! empty( $category['scale'] ) && is_array( $category['scale'] ) ) {
				$scale_categories[ $category['id'] ] = [
					'name'   => (string) ( $category['name'] ?? $category['id'] ),
					'prefix' => (string) ( $category['scale']['prefix'] ?? '' ),
				];
			}
		}

		if ( empty( $category_ids ) ) {
			return $warnings;
		}

		$uncategorized_count = 0;
		$unknown_categories  = [];
		$scale_static        = [];
		$scale_like_static   = [];

		foreach ( $incoming as $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			$category_id = $variable['category'] ?? '';

			if ( $category_id === '' ) {
				$uncategorized_count++;

				if ( self::looks_like_static_scale_variable( $variable ) ) {
					$scale_like_static[] = (string) ( $variable['name'] ?? '' );
				}

				continue;
			}

			if ( empty( $category_ids[ $category_id ] ) ) {
				$unknown_categories[ $category_id ] = true;
				continue;
			}

			if ( ! empty( $scale_categories[ $category_id ] ) ) {
				$scale_prefix  = $scale_categories[ $category_id ]['prefix'];
				$targets_scale = $scale_prefix === '' || strpos( (string) ( $variable['name'] ?? '' ), $scale_prefix ) === 0;

				if ( $targets_scale && stripos( (string) ( $variable['value'] ?? '' ), 'clamp(' ) === false ) {
					$scale_static[ $category_id ] = true;
				}
			}

			foreach ( $scale_categories as $scale_category_id => $scale_category ) {
				$scale_prefix = $scale_category['prefix'];

				if ( $category_id === $scale_category_id || $scale_prefix === '' || strpos( (string) ( $variable['name'] ?? '' ), $scale_prefix ) !== 0 ) {
					continue;
				}

				if ( stripos( (string) ( $variable['value'] ?? '' ), 'clamp(' ) === false ) {
					$scale_static[ $scale_category_id ] = true;
				}
			}

			if ( self::looks_like_static_scale_variable( $variable ) ) {
				$scale_like_static[] = (string) ( $variable['name'] ?? '' );
			}
		}

		if ( $uncategorized_count > 0 ) {
			$warnings[] = [
				'code'    => 'uncategorized_variables',
				'message' => sprintf( '%d variable(s) were saved without a category even though variable categories exist. Use the existing category IDs when possible.', $uncategorized_count ),
				'count'   => $uncategorized_count,
			];
		}

		if ( ! empty( $unknown_categories ) ) {
			$warnings[] = [
				'code'        => 'unknown_variable_categories',
				'message'     => 'Some variables reference category IDs that do not exist in the current variable categories.',
				'categoryIds' => array_keys( $unknown_categories ),
			];
		}

		if ( ! empty( $scale_static ) ) {
			$warnings[] = [
				'code'        => 'scale_category_static_variables',
				'message'     => 'Some variables were written into scale categories without fluid clamp() values. For typography and spacing scales, use bricks/generate-scale-variables.',
				'categoryIds' => array_keys( $scale_static ),
			];
		}

		if ( ! empty( $scale_like_static ) ) {
			$warnings[] = [
				'code'      => 'scale_like_static_variables',
				'message'   => 'Some variables look like spacing or typography scale tokens but use static values. Use bricks/generate-scale-variables for spacing and type scales.',
				'variables' => array_values( array_unique( array_filter( $scale_like_static ) ) ),
			];
		}

		return $warnings;
	}

	/**
	 * Detect hand-authored static variables that look like scale tokens.
	 *
	 * @since 2.4
	 *
	 * @param array $variable Variable object.
	 * @return bool
	 */
	private static function looks_like_static_scale_variable( array $variable ): bool {
		$name  = (string) ( $variable['name'] ?? '' );
		$value = (string) ( $variable['value'] ?? '' );

		if ( $name === '' || stripos( $value, 'clamp(' ) !== false ) {
			return false;
		}

		foreach ( [ 'space-', 'spacing-', 'text-', 'type-', 'fs-', 'font-size-' ] as $prefix ) {
			if ( strpos( $name, $prefix ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scale scopes the Style Manager renders a scale editor for.
	 *
	 * PopupStyles.vue has one fixed tab per scope, and PopupStylesScaleEditor.vue lists
	 * a category only when `scale.scaleScope` matches the open tab. A scale category with
	 * any other scope is unreachable from the UI.
	 *
	 * @since 2.4
	 */
	const SCALE_SCOPES = [ 'typography', 'spacing' ];

	/**
	 * Scale types the generator knows how to resolve names for.
	 *
	 * @since 2.4
	 */
	const SCALE_TYPES = [ 'tshirt', 'numeric', 'custom' ];

	/**
	 * Validate incoming variable categories before they are written verbatim to the DB.
	 *
	 * `set_global_variables()` used to `update_option()` whatever category array it was
	 * handed. A `scale` object missing `scaleScope` or `scaleNames` still reads as a scale
	 * category to the abilities layer (which then rejects hand-authored values for its
	 * prefix) and to ThePopupManagerCategories.vue (which renders the "scale" badge), but:
	 *
	 * - PopupStylesScaleEditor.vue filters on `scale.scaleScope`, so a scope-less scale never
	 *   appears under Style Manager > Typography/Spacing and cannot be edited or regenerated.
	 * - actions.js `regenerateVariables()` derives each variable's scale step from its index
	 *   in `scaleNames`. With `scaleNames` empty the baseline index collapses to 0 and every
	 *   generated value is silently rewritten one step per position off, corrupting the scale
	 *   the next time the html font size or min/max screen width changes.
	 *
	 * Neither state is reachable from the builder, which always seeds a category from
	 * `scaleDefaults` (scaleScope + scaleNames + baseline). Validate here so the abilities
	 * surface cannot author data its own UI is unable to represent or repair.
	 *
	 * @since 2.4
	 *
	 * @param array $categories Incoming categories.
	 * @return true|\WP_Error
	 */
	private static function validate_variable_categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return Error::invalid_param( 'categories', 'an array of category objects', $categories );
		}

		$seen_ids = [];

		foreach ( $categories as $index => $category ) {
			if ( ! is_array( $category ) ) {
				return Error::invalid_param( "categories[{$index}]", 'an object with { id, name, scale? }', $category );
			}

			if ( empty( $category['id'] ) || ! is_string( $category['id'] ) ) {
				return Error::invalid_param( "categories[{$index}].id", 'a non-empty string', $category['id'] ?? null );
			}

			if ( isset( $seen_ids[ $category['id'] ] ) ) {
				return Error::conflict_duplicate_name( 'variable_category', $category['id'], [ 'index' => $index ] );
			}

			$seen_ids[ $category['id'] ] = true;

			if ( ! isset( $category['scale'] ) ) {
				continue;
			}

			$scale_validation = self::validate_scale_config( $category['scale'], "categories[{$index}].scale" );

			if ( is_wp_error( $scale_validation ) ) {
				return $scale_validation;
			}
		}

		return true;
	}

	/**
	 * Validate a single `scale` config object.
	 *
	 * Shared by set-global-variables (writes the category) and generate-scale-variables
	 * (reads it) so a scale can never be generated from a config the builder cannot open.
	 *
	 * `scaleNames` is required even though `resolve_scale_name()` can compute t-shirt and
	 * numeric names positionally: actions.js `regenerateVariables()` and the Style Manager
	 * preview both read the stored list, not the computed one.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $scale Scale config.
	 * @param string $param Dotted param path used in error messages.
	 * @return true|\WP_Error
	 */
	private static function validate_scale_config( $scale, $param ) {
		if ( ! is_array( $scale ) || empty( $scale ) ) {
			return Error::invalid_param( $param, 'a non-empty scale config object, or omit the key entirely', $scale );
		}

		if ( empty( $scale['scaleScope'] ) || ! in_array( $scale['scaleScope'], self::SCALE_SCOPES, true ) ) {
			return Error::invalid_param(
				"{$param}.scaleScope",
				sprintf(
					'one of: %s. Without it the scale never appears under Style Manager > Typography/Spacing and cannot be edited in the builder',
					implode( ', ', self::SCALE_SCOPES )
				),
				$scale['scaleScope'] ?? null
			);
		}

		if ( empty( $scale['prefix'] ) || ! is_string( $scale['prefix'] ) ) {
			return Error::invalid_param( "{$param}.prefix", 'a non-empty string, e.g. "text-"', $scale['prefix'] ?? null );
		}

		if ( isset( $scale['scaleType'] ) && ! in_array( $scale['scaleType'], self::SCALE_TYPES, true ) ) {
			return Error::invalid_param( "{$param}.scaleType", 'one of: ' . implode( ', ', self::SCALE_TYPES ), $scale['scaleType'] );
		}

		if ( empty( $scale['scaleNames'] ) || ! is_array( $scale['scaleNames'] ) ) {
			return Error::invalid_param(
				"{$param}.scaleNames",
				'a non-empty array of scale step names, e.g. ["2xs","xs","s","m","l","xl","2xl"]. regenerateVariables() derives each variable\'s scale step from its index in this list',
				$scale['scaleNames'] ?? null
			);
		}

		if ( empty( $scale['baseline'] ) || ! in_array( (string) $scale['baseline'], array_map( 'strval', $scale['scaleNames'] ), true ) ) {
			return Error::invalid_param( "{$param}.baseline", 'one of the entries in scale.scaleNames', $scale['baseline'] ?? null );
		}

		return true;
	}

	/**
	 * Reject hand-authored values for variables that belong to a configured scale.
	 *
	 * @since 2.4
	 *
	 * @param array $incoming   Incoming variables from the current write.
	 * @param array $categories Current or incoming variable categories.
	 * @return true|\WP_Error
	 */
	private static function validate_scale_variable_writes( $incoming, $categories ) {
		if ( empty( $incoming ) || empty( $categories ) || ! is_array( $incoming ) || ! is_array( $categories ) ) {
			return true;
		}

		$scale_categories = [];

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) || empty( $category['scale'] ) || ! is_array( $category['scale'] ) ) {
				continue;
			}

			$scale_categories[] = [
				'id'     => (string) $category['id'],
				'name'   => (string) ( $category['name'] ?? $category['id'] ),
				'prefix' => (string) ( $category['scale']['prefix'] ?? '' ),
			];
		}

		if ( empty( $scale_categories ) ) {
			return true;
		}

		foreach ( $incoming as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			$name        = (string) ( $variable['name'] ?? '' );
			$value       = (string) ( $variable['value'] ?? '' );
			$category_id = (string) ( $variable['category'] ?? '' );

			foreach ( $scale_categories as $category ) {
				$prefix = $category['prefix'];

				if ( $prefix !== '' ) {
					$targets_scale = strpos( $name, $prefix ) === 0;
				} else {
					$targets_scale = $category_id === $category['id'];
				}

				if ( ! $targets_scale ) {
					continue;
				}

				if ( stripos( $value, 'clamp(' ) !== false ) {
					continue;
				}

				return Error::invalid_param(
					"variables[{$index}].value",
					sprintf( 'a fluid clamp() value generated by bricks/generate-scale-variables for the "%s" scale category', $category['name'] ),
					$value
				);
			}
		}

		return true;
	}

	/**
	 * Input schema for set-global-variable-categories.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_global_variable_categories_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'categories'                => [
					'type'        => 'array',
					'description' => __( 'Complete replacement global-variable category list. Preserve opaque fields returned by the latest read; response-only itemDigest and itemOwnership fields are stripped before persistence.', 'bricks' ),
				],
				'expectedOwnership'         => self::ownership_input_schema(
					__( 'Required exact category ownership from the latest global-variable read or category write.', 'bricks' )
				),
				'expectedVariableOwnership' => self::ownership_input_schema(
					__( 'Required exact variable ownership from the same latest global-variable read. Category removals are guarded against this variable store.', 'bricks' )
				),
			],
			'required'             => [ 'categories', 'expectedOwnership', 'expectedVariableOwnership' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for set-global-variable-categories.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_global_variable_categories_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'categories'        => [ 'type' => 'array' ],
				'categoryOwnership' => [ 'type' => 'object' ],
				'itemOwnerships'    => [ 'type' => 'object' ],
				'changed'           => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Replace global-variable categories with exact ownership.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_global_variable_categories( $input ) {
		$categories                  = $input['categories'] ?? null;
		$expected                    = $input['expectedOwnership'] ?? null;
		$expected_variable_ownership = $input['expectedVariableOwnership'] ?? null;

		if ( ! is_array( $categories ) ) {
			return Error::invalid_param( 'categories', 'complete array of variable category objects', $categories );
		}

		if ( ! is_array( $expected ) ) {
			return Error::invalid_param( 'expectedOwnership', 'category ownership from the latest global-variable read or category write', $expected );
		}

		if ( ! is_array( $expected_variable_ownership ) ) {
			return Error::invalid_param( 'expectedVariableOwnership', 'variable ownership from the same latest global-variable read', $expected_variable_ownership );
		}

		// list-global-variables decorates complete category rows with ready
		// ownership metadata. Accept direct read-modify-write round trips without
		// persisting those transport-only fields into the Builder option.
		foreach ( $categories as &$category ) {
			if ( is_array( $category ) ) {
				unset( $category['itemDigest'], $category['itemOwnership'] );
			}
		}
		unset( $category );

		$valid = self::validate_variable_categories( $categories );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		$variable_valid = Design_Option_Store::validate_ownership(
			self::GLOBAL_VARIABLES_RESOURCE,
			$variable_snapshot,
			$expected_variable_ownership
		);

		if ( is_wp_error( $variable_valid ) ) {
			return $variable_valid;
		}

		$current_category_ids     = [];
		$current_categories_by_id = [];
		foreach ( is_array( $snapshot['value'] ) ? $snapshot['value'] : [] as $current_category ) {
			if ( is_array( $current_category ) && ! empty( $current_category['id'] ) ) {
				$current_id                              = (string) $current_category['id'];
				$current_category_ids[]                  = $current_id;
				$current_categories_by_id[ $current_id ] = $current_category;
			}
		}

		$candidate_category_ids     = [];
		$candidate_categories_by_id = [];
		foreach ( $categories as $candidate_category ) {
			$candidate_id                                = (string) $candidate_category['id'];
			$candidate_category_ids[]                    = $candidate_id;
			$candidate_categories_by_id[ $candidate_id ] = $candidate_category;
		}

		$removed_category_ids    = array_values( array_diff( $current_category_ids, $candidate_category_ids ) );
		$referenced_removed      = [];
		$referenced_category_ids = [];

		foreach ( is_array( $variable_snapshot['value'] ) ? $variable_snapshot['value'] : [] as $variable ) {
			$variable_category = is_array( $variable ) ? (string) ( $variable['category'] ?? '' ) : '';

			if ( $variable_category !== '' && in_array( $variable_category, $removed_category_ids, true ) ) {
				$referenced_removed[] = $variable_category;
			}

			if ( $variable_category !== '' ) {
				$referenced_category_ids[] = $variable_category;
			}
		}

		$referenced_removed      = array_values( array_unique( $referenced_removed ) );
		$referenced_category_ids = array_values( array_unique( $referenced_category_ids ) );

		if ( ! empty( $referenced_removed ) ) {
			return Error::conflict(
				'global_variable_category_referenced',
				[
					'message'     => 'One or more removed categories are still referenced by global variables. Update or delete those variables first, then re-read both stores.',
					'categoryIds' => $referenced_removed,
				]
			);
		}

		foreach ( $referenced_category_ids as $category_id ) {
			if ( ! isset( $current_categories_by_id[ $category_id ], $candidate_categories_by_id[ $category_id ] ) ) {
				continue;
			}

			$before_scale = $current_categories_by_id[ $category_id ]['scale'] ?? null;
			$after_scale  = $candidate_categories_by_id[ $category_id ]['scale'] ?? null;

			if ( Design_Option_Store::item_digest( $before_scale ) !== Design_Option_Store::item_digest( $after_scale ) ) {
				return Error::conflict(
					'global_variable_category_scale_atomic_update_unavailable',
					[
						'message'       => 'Changing the scale config of a category referenced by variables requires atomically regenerating its variables and references. No category data was changed.',
						'categoryId'    => $category_id,
						'beforeScale'   => $before_scale,
						'proposedScale' => $after_scale,
					]
				);
			}
		}

		$same_authority = (int) $snapshot['siteId'] === (int) $variable_snapshot['siteId'];

		if ( ! empty( $removed_category_ids ) && ! $same_authority ) {
			return Error::conflict(
				'global_variable_category_removal_not_atomic',
				[
					'message'     => 'Category removal cannot be made atomic because variables and categories use different site authorities. No category data was changed.',
					'categoryIds' => $removed_category_ids,
				]
			);
		}

		$write = Design_Option_Store::compare_and_swap_owned(
			self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE,
			$snapshot,
			array_values( $categories ),
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected,
			null,
			empty( $categories ),
			$same_authority ? [ $variable_snapshot ] : []
		);

		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$readback  = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$ownership = is_wp_error( $readback ) ? $readback : self::design_ownership( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, $readback );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, '', $ownership );
		}

		$saved            = is_array( $readback['value'] ) ? $readback['value'] : [];
		$committed        = is_array( $write['value'] ?? null ) ? array_values( $write['value'] ) : [];
		$committed_exists = ! empty( $categories );

		if (
			(bool) $readback['exists'] !== $committed_exists ||
			! hash_equals( Design_Option_Store::item_digest( $committed ), Design_Option_Store::item_digest( array_values( $saved ) ) )
		) {
			return self::committed_design_readback_error(
				self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE,
				'',
				null,
				$ownership,
				$saved
			);
		}

		$item_ownerships = [];

		foreach ( $saved as $category ) {
			if ( ! empty( $category['id'] ) ) {
				$item_ownerships[ $category['id'] ] = self::item_ownership( $ownership, $category );
			}
		}

		return [
			'categories'        => $saved,
			'categoryOwnership' => $ownership,
			'itemOwnerships'    => $item_ownerships,
			'changed'           => ( $write['status'] ?? '' ) !== 'no_change',
		];
	}

	/**
	 * Input schema for delete-global-variable.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_global_variable_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'variableId'        => [
					'type'        => 'string',
					'description' => __( 'Global variable ID to delete.', 'bricks' ),
					'required'    => true,
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required ready itemOwnership from the latest global-variable read or write.', 'bricks' )
				),
				'allowOrphans'      => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true for every deletion, even when fresh bounded usage evidence is zero. Reference discovery is audit evidence, not a guarantee.', 'bricks' ),
				],
			],
			'required'             => [ 'variableId', 'expectedOwnership', 'allowOrphans' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for delete-global-variable.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_global_variable_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'           => [ 'type' => 'boolean' ],
				'variableId'        => [ 'type' => 'string' ],
				'beforeDelete'      => [
					'type'        => 'object',
					'description' => __( 'Snapshot of the removed variable.', 'bricks' ),
				],
				'variableOwnership' => [ 'type' => 'object' ],
				'itemDigest'        => [ 'type' => 'string' ],
				'changed'           => [ 'type' => 'boolean' ],
				'usageEvidence'     => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: delete-global-variable.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_global_variable( $input ) {
		if ( ! array_key_exists( 'allowOrphans', $input ) ) {
			return Error::missing_param( 'allowOrphans' );
		}

		if ( $input['allowOrphans'] !== true ) {
			return Error::conflict(
				'global_variable_orphan_acknowledgement_required',
				[
					'message' => 'Deleting a global variable always requires allowOrphans=true. Fresh reference discovery is audit evidence, not a guarantee that no references exist.',
				]
			);
		}

		Manager::flush_options_cache();

		$variable_id       = (string) $input['variableId'];
		$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		$variables          = $variable_snapshot['value'];
		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
			return Error::invalid_param( 'expectedOwnership', 'ready variable itemOwnership from the latest read or write', $expected_ownership );
		}

		if ( ! is_array( $variables ) ) {
			$variables = [];
		}

		foreach ( $variables as $index => $variable ) {
			if ( (string) ( $variable['id'] ?? '' ) !== $variable_id ) {
				continue;
			}

			$before_delete  = $variable;
			$usage_evidence = self::global_variable_usage_evidence( (string) ( $variable['name'] ?? '' ) );

			if ( is_wp_error( $usage_evidence ) ) {
				return $usage_evidence;
			}

			array_splice( $variables, $index, 1 );
			$save_result = Design_Option_Store::compare_and_swap_owned(
				self::GLOBAL_VARIABLES_RESOURCE,
				$variable_snapshot,
				array_values( $variables ),
				self::DESIGN_SYSTEM_VERSION_OPTION,
				$expected_ownership,
				static function ( $authoritative_variables ) use ( $variable_id ) {
					return self::find_design_item( $authoritative_variables, 'id', $variable_id );
				},
				empty( $variables )
			);

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			if ( empty( $variables ) ) {
				self::generate_empty_global_variables_css();
			}

			$readback  = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
			$ownership = is_wp_error( $readback ) ? $readback : self::design_ownership( self::GLOBAL_VARIABLES_RESOURCE, $readback );

			if ( is_wp_error( $ownership ) ) {
				return self::committed_design_readback_error( self::GLOBAL_VARIABLES_RESOURCE, $variable_id, $ownership );
			}

			$live_variable = self::find_design_item( is_array( $readback['value'] ) ? $readback['value'] : [], 'id', $variable_id );

			if ( $live_variable !== null ) {
				return self::committed_design_readback_error(
					self::GLOBAL_VARIABLES_RESOURCE,
					$variable_id,
					null,
					$ownership,
					$live_variable
				);
			}

			return [
				'deleted'           => true,
				'variableId'        => $variable_id,
				'beforeDelete'      => $before_delete,
				'variableOwnership' => $ownership,
				'itemDigest'        => Design_Option_Store::item_digest( $before_delete ),
				'changed'           => ( $save_result['status'] ?? '' ) !== 'no_change',
				'usageEvidence'     => $usage_evidence,
			];
		}

		return Error::not_found( 'global_variable', $variable_id );
	}

	/**
	 * Collect authority-safe reference evidence for one global variable.
	 *
	 * @since 2.4
	 *
	 * @param string $variable_name Variable name.
	 * @return array|\WP_Error
	 */
	private static function global_variable_usage_evidence( string $variable_name ) {
		$current_site_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$authority_site_id = Design_Option_Store::authority_site_id( BRICKS_DB_GLOBAL_VARIABLES );

		if ( $authority_site_id !== $current_site_id ) {
			return Error::conflict(
				'global_variable_usage_scan_main_site_authority',
				[
					'message'      => 'The variable is shared from another site authority, so this subsite cannot prove a complete reference scan. Delete it from its authority context.',
					'variableName' => $variable_name,
				]
			);
		}

		$evidence = self::palette_variable_usage_evidence( [ ltrim( $variable_name, '-' ) ] );

		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}

		$evidence['variableName']      = $variable_name;
		$evidence['orphaningAccepted'] = true;

		return $evidence;
	}

	/**
	 * Save global variables against their exact before-image.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Exact global-variable option snapshot.
	 * @param array $variables Replacement variables.
	 * @param array $expected Exact variable ownership.
	 * @param array $related  Same-authority validation dependencies.
	 * @return array|\WP_Error
	 */
	private static function save_global_variables_from_snapshot( array $snapshot, array $variables, array $expected, array $related = [] ) {
		$variables = array_values( $variables );
		$result    = Design_Option_Store::compare_and_swap_owned(
			self::GLOBAL_VARIABLES_RESOURCE,
			$snapshot,
			$variables,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected,
			null,
			empty( $variables ),
			$related
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $variables ) ) {
			self::generate_empty_global_variables_css();
		}

		$response_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

		if ( is_wp_error( $response_snapshot ) ) {
			return self::committed_design_readback_error( self::GLOBAL_VARIABLES_RESOURCE, '', $response_snapshot );
		}

		$ownership = self::design_ownership( self::GLOBAL_VARIABLES_RESOURCE, $response_snapshot );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_design_readback_error( self::GLOBAL_VARIABLES_RESOURCE, '', $ownership );
		}

		$stored           = is_array( $response_snapshot['value'] ) ? array_values( $response_snapshot['value'] ) : [];
		$committed        = is_array( $result['value'] ?? null ) ? array_values( $result['value'] ) : [];
		$committed_exists = ! empty( $variables );

		if (
			(bool) $response_snapshot['exists'] !== $committed_exists ||
			! hash_equals( Design_Option_Store::item_digest( $committed ), Design_Option_Store::item_digest( $stored ) )
		) {
			return self::committed_design_readback_error(
				self::GLOBAL_VARIABLES_RESOURCE,
				'',
				null,
				$ownership,
				$stored
			);
		}

		return [
			'write'     => $result,
			'snapshot'  => $response_snapshot,
			'ownership' => $ownership,
			'changed'   => ( $result['status'] ?? '' ) !== 'no_change',
		];
	}

	/**
	 * Remove stale generated variable CSS after the authoritative store becomes empty.
	 *
	 * @since 2.4
	 */
	private static function generate_empty_global_variables_css() {
		if ( ! class_exists( '\\Bricks\\Assets_Global_Variables', false ) && defined( 'BRICKS_PATH' ) ) {
			$asset_path = BRICKS_PATH . 'includes/assets/global-variables.php';

			if ( file_exists( $asset_path ) ) {
				require_once $asset_path;
			}
		}

		if ( class_exists( '\\Bricks\\Assets_Global_Variables', false ) ) {
			\Bricks\Assets_Global_Variables::generate_css_file( [] );
		}
	}

	// ------------------------------------------------------------------
	// bricks/get-design-context
	// ------------------------------------------------------------------

	const DESIGN_SYSTEM_VERSION_OPTION = 'bricks_mcp_design_system_version';
	const COMPONENT_USAGE_TRANSIENT    = 'bricks_mcp_component_usage';
	const COMPONENT_USAGE_TTL          = HOUR_IN_SECONDS;

	/**
	 * Input schema for get-design-context
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_design_context_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'includeUsage'   => [
					'type'        => 'boolean',
					'description' => __( 'Include `usedOnPosts` per component, limited to posts the caller can edit in the Builder. Off by default: the first computation scans every Bricks post and can take a few seconds on large sites. Usage is cached for one hour and filtered for the current caller on every read.', 'bricks' ),
				],
				'responseFormat' => [
					'type'        => 'string',
					'enum'        => [ 'detailed', 'summary' ],
					'description' => __( 'Use `summary` for session orientation: includes counts, breakpoints, and capped resource lists. Default `detailed` preserves the full current response.', 'bricks' ),
				],
				'limit'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => __( 'Maximum items per large list when `responseFormat` is `summary`. Default 100.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for get-design-context
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_design_context_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'counts'               => [ 'type' => 'object' ],
				'colorPalettes'        => [ 'type' => 'array' ],
				'globalClasses'        => [ 'type' => 'array' ],
				'themeStyles'          => [ 'type' => 'array' ],
				'components'           => [ 'type' => 'array' ],
				'globalVariables'      => [ 'type' => 'array' ],
				'variableCategories'   => [
					'type'        => 'array',
					'description' => __( 'Global variable categories, including scale configs used by generate-scale-variables.', 'bricks' ),
				],
				'breakpoints'          => [
					'type'        => 'object',
					'description' => __( 'Current responsive breakpoint contract: customEnabled, isMobileFirst, baseKey, baseWidth, and active breakpoint items.', 'bricks' ),
				],
				'truncated'            => [
					'type'        => 'object',
					'description' => __( 'Present in summary responses when large lists were capped.', 'bricks' ),
				],
				'version'              => [
					'type'        => 'integer',
					'description' => __( 'Legacy current-site design counter. On mixed-authority multisite setups, use designSystemSnapshot and the resource-specific versions instead.', 'bricks' ),
				],
				'designSystemSnapshot' => [
					'type'                 => 'object',
					'description'          => __( 'Authority-correct design snapshot. Use its resource version for focused writes and its token for workspace drift checks.', 'bricks' ),
					'properties'           => [
						'token'       => [ 'type' => 'string' ],
						'authorities' => [ 'type' => 'array' ],
						'resources'   => [ 'type' => 'object' ],
					],
					'required'             => [ 'token', 'authorities', 'resources' ],
					'additionalProperties' => false,
				],
				'usageCached'          => [
					'type'        => 'boolean',
					'description' => __( 'True when component usage was served from the transient. Absent when `includeUsage` was not requested.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: get-design-context
	 *
	 * Any user with design-system access. Design-system data is not
	 * user-sensitive, but classes, components, theme styles, and component
	 * usage reveal editorial state, so gate behind the same manager
	 * permissions the builder UI uses.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function get_design_context_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		$permissions = [
			'edit_color_palettes',
			'access_class_manager',
			'access_variable_manager',
			'access_theme_styles',
			'insert_components',
			'set_component_props',
			'edit_components',
			'create_components',
		];

		if ( ! self::user_has_any_builder_permission( $permissions ) ) {
			return Error::forbidden_builder_permission( 'design_system_access' );
		}

		return true;
	}

	/**
	 * Callback: get-design-context
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_design_context( $input ) {
		Manager::flush_options_cache();

		$include_usage = ! empty( $input['includeUsage'] );
		$is_summary    = ( $input['responseFormat'] ?? 'detailed' ) === 'summary';
		$limit         = isset( $input['limit'] ) ? min( 500, max( 1, (int) $input['limit'] ) ) : 100;

		$class_snapshot             = Design_Option_Store::read_versioned( BRICKS_DB_GLOBAL_CLASSES, self::DESIGN_SYSTEM_VERSION_OPTION, [] );
		$component_snapshot         = Design_Option_Store::read_versioned( BRICKS_DB_COMPONENTS, self::DESIGN_SYSTEM_VERSION_OPTION, [] );
		$variable_snapshot          = Design_Option_Store::read_versioned( BRICKS_DB_GLOBAL_VARIABLES, self::DESIGN_SYSTEM_VERSION_OPTION, [] );
		$variable_category_snapshot = Design_Option_Store::read_versioned( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, self::DESIGN_SYSTEM_VERSION_OPTION, [] );
		$palette_snapshot           = Design_Option_Store::read_versioned( BRICKS_DB_COLOR_PALETTE, self::DESIGN_SYSTEM_VERSION_OPTION, [] );
		$style_snapshot             = Design_Option_Store::read_versioned( BRICKS_DB_THEME_STYLES, self::DESIGN_SYSTEM_VERSION_OPTION, [] );

		foreach ( [ $class_snapshot, $component_snapshot, $variable_snapshot, $variable_category_snapshot, $palette_snapshot, $style_snapshot ] as $snapshot ) {
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
		}

		$design_snapshot = self::design_system_snapshot(
			[
				'globalClasses'            => $class_snapshot,
				'components'               => $component_snapshot,
				'globalVariables'          => $variable_snapshot,
				'globalVariableCategories' => $variable_category_snapshot,
				'colorPalettes'            => $palette_snapshot,
				'themeStyles'              => $style_snapshot,
			]
		);

		if ( is_wp_error( $design_snapshot ) ) {
			return $design_snapshot;
		}

		$palettes            = self::color_palettes_from_snapshot( $palette_snapshot );
		$classes             = $class_snapshot['value'];
		$styles              = $style_snapshot['value'];
		$component           = $component_snapshot['value'];
		$variables           = $variable_snapshot['value'];
		$variable_categories = $variable_category_snapshot['value'];

		if ( ! is_array( $variable_categories ) ) {
			$variable_categories = [];
		}

		$palette_summaries = [];
		$total_colors      = 0;

		foreach ( $palettes as $palette ) {
			$colors              = isset( $palette['colors'] ) && is_array( $palette['colors'] ) ? $palette['colors'] : [];
			$total_colors       += count( $colors );
			$palette_summaries[] = [
				'id'         => $palette['id'] ?? '',
				'name'       => $palette['name'] ?? '',
				'colorCount' => count( $colors ),
			];
		}

		$class_summaries = [];

		foreach ( $classes as $class ) {
			$class_summaries[] = [
				'id'           => $class['id'] ?? '',
				'name'         => $class['name'] ?? '',
				'category'     => $class['category'] ?? '',
				'hasSettings'  => ! empty( $class['settings'] ),
				'hasSelectors' => ! empty( $class['selectors'] ),
			];
		}

		$style_summaries = [];

		foreach ( $styles as $id => $style ) {
			// Theme styles are keyed by id in some shapes, array-of-objects in others.
			if ( is_array( $style ) && ! empty( $style['id'] ) ) {
				$style_summaries[] = [
					'id'         => (string) $style['id'],
					'label'      => $style['label'] ?? (string) $style['id'],
					'conditions' => self::get_theme_style_conditions( $style ),
				];
			} elseif ( ( is_string( $id ) || is_int( $id ) ) && is_array( $style ) ) {
				$style_summaries[] = [
					'id'         => (string) $id,
					'label'      => $style['label'] ?? (string) $id,
					'conditions' => self::get_theme_style_conditions( $style ),
				];
			}
		}

		$component_summaries = [];

		foreach ( $component as $comp ) {
			$root_id        = $comp['id'] ?? '';
			$elements       = isset( $comp['elements'] ) && is_array( $comp['elements'] ) ? $comp['elements'] : [];
			$label          = $root_id;
			$slot_count     = 0;
			$property_types = [];

			foreach ( $elements as $el ) {
				if ( ( $el['id'] ?? '' ) === $root_id ) {
					$label = $el['label'] ?? $el['name'] ?? $root_id;
				}

				if ( ( $el['name'] ?? '' ) === 'slot' ) {
					$slot_count++;
				}
			}

			if ( isset( $comp['properties'] ) && is_array( $comp['properties'] ) ) {
				foreach ( $comp['properties'] as $property ) {
					$type = (string) ( $property['type'] ?? '' );

					if ( $type !== '' ) {
						$property_types[ $type ] = true;
					}
				}
			}

			$component_summaries[] = [
				'id'                 => $root_id,
				'label'              => $label,
				'category'           => $comp['category'] ?? '',
				'desc'               => $comp['desc'] ?? '',
				'propertyCount'      => isset( $comp['properties'] ) && is_array( $comp['properties'] ) ? count( $comp['properties'] ) : 0,
				'propertyTypes'      => array_keys( $property_types ),
				'propertyGroupCount' => isset( $comp['propertyGroups'] ) && is_array( $comp['propertyGroups'] ) ? count( $comp['propertyGroups'] ) : 0,
				'variantCount'       => isset( $comp['variants'] ) && is_array( $comp['variants'] ) ? count( $comp['variants'] ) : 0,
				'slotCount'          => $slot_count,
				'elementCount'       => count( $elements ),
				'version'            => $comp['_version'] ?? '',
			];
		}

		$variable_summaries    = [];
		$variables_by_category = [];

		foreach ( $variables as $var ) {
			$category_id = $var['category'] ?? '';

			if ( $category_id !== '' ) {
				if ( empty( $variables_by_category[ $category_id ] ) ) {
					$variables_by_category[ $category_id ] = 0;
				}

				$variables_by_category[ $category_id ]++;
			}

			$variable_summaries[] = [
				'id'       => $var['id'] ?? '',
				'name'     => $var['name'] ?? '',
				'value'    => $var['value'] ?? '',
				'category' => $var['category'] ?? '',
			];
		}

		$variable_category_summaries = [];

		foreach ( $variable_categories as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$category_id = $category['id'] ?? '';

			$variable_category_summaries[] = [
				'id'            => $category_id,
				'name'          => $category['name'] ?? '',
				'scale'         => ( isset( $category['scale'] ) && is_array( $category['scale'] ) ) ? $category['scale'] : null,
				'variableCount' => $category_id && isset( $variables_by_category[ $category_id ] ) ? $variables_by_category[ $category_id ] : 0,
			];
		}

		$breakpoints = \Bricks\Breakpoints::get_breakpoints();

		$response = [
			'counts'               => [
				'colorPalettes'      => count( $palette_summaries ),
				'colors'             => $total_colors,
				'globalClasses'      => count( $class_summaries ),
				'themeStyles'        => count( $style_summaries ),
				'components'         => count( $component_summaries ),
				'globalVariables'    => count( $variable_summaries ),
				'variableCategories' => count( $variable_category_summaries ),
			],
			'colorPalettes'        => $palette_summaries,
			'globalClasses'        => $class_summaries,
			'themeStyles'          => $style_summaries,
			'components'           => $component_summaries,
			'globalVariables'      => $variable_summaries,
			'variableCategories'   => $variable_category_summaries,
			'breakpoints'          => [
				'customEnabled' => (bool) \Bricks\Database::get_setting( 'customBreakpoints', false ),
				'isMobileFirst' => \Bricks\Breakpoints::$is_mobile_first,
				'baseKey'       => \Bricks\Breakpoints::$base_key,
				'baseWidth'     => \Bricks\Breakpoints::$base_width,
				'items'         => array_values( $breakpoints ),
			],
			'version'              => (int) $design_snapshot['resources']['currentSite']['version'],
			'designSystemSnapshot' => $design_snapshot,
		];

		if ( $is_summary ) {
			foreach ( [ 'globalClasses', 'components', 'globalVariables' ] as $key ) {
				$total = count( $response[ $key ] );

				if ( $total > $limit ) {
					$response[ $key ]              = array_slice( $response[ $key ], 0, $limit );
					$response['truncated'][ $key ] = [
						'total' => $total,
						'limit' => $limit,
					];
				}
			}
		}

		if ( $include_usage && ! empty( $component_summaries ) ) {
			$usage = self::get_component_usage_cached();

			if ( is_wp_error( $usage ) ) {
				return $usage;
			}

			$response['usageCached'] = $usage['cached'];

			foreach ( $response['components'] as &$summary ) {
				$cid                    = $summary['id'];
				$summary['usedOnPosts'] = $usage['data'][ $cid ] ?? [];
			}

			unset( $summary );
		}

		return $response;
	}

	/**
	 * Current design-system version counter.
	 *
	 * Bumped on every write to a design-system option (see
	 * Manager::hook_design_system_writes). Exposed in ability responses
	 * so callers can detect drift between calls.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	public static function design_system_version(): int {
		return (int) get_option( self::DESIGN_SYSTEM_VERSION_OPTION, 0 );
	}

	/**
	 * Capture every design-system authority used by ability reads and rendering.
	 *
	 * A single integer is sufficient only when every resource is stored on the
	 * current site. Multisite can route classes, components, variables, and
	 * their categories independently, so the token binds the version counter
	 * on every distinct authority while resource entries expose the correct
	 * integer for focused mutation preconditions.
	 *
	 * @since 2.4
	 *
	 * @param array $versioned_resources Optional versioned option snapshots keyed by resource.
	 * @return array|\WP_Error
	 */
	public static function design_system_snapshot( array $versioned_resources = [] ) {
		$current_site_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$current_snapshot = $versioned_resources['themeStyles'] ?? Design_Option_Store::read_related(
			BRICKS_DB_THEME_STYLES,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			0
		);

		if ( is_wp_error( $current_snapshot ) ) {
			return $current_snapshot;
		}

		if ( ! array_key_exists( 'designSystemVersion', $current_snapshot ) ) {
			$current_snapshot['designSystemVersion'] = (int) $current_snapshot['value'];
		}

		$current_version = (int) ( $current_snapshot['designSystemVersion'] ?? -1 );
		if (
			(int) ( $current_snapshot['siteId'] ?? -1 ) !== $current_site_id ||
			$current_version < 0
		) {
			return Error::conflict(
				'design_system_snapshot_unstable',
				[
					'message' => 'The current-site design payload was not paired with a valid authority version. Retry the read.',
				]
			);
		}

		$authorities = [ (string) $current_site_id => $current_version ];
		$resources   = [
			'currentSite'   => [
				'siteId'  => $current_site_id,
				'version' => $current_version,
			],
			'themeStyles'   => [
				'siteId'  => $current_site_id,
				'version' => $current_version,
			],
			'breakpoints'   => [
				'siteId'  => $current_site_id,
				'version' => $current_version,
			],
			'pseudoClasses' => [
				'siteId'  => $current_site_id,
				'version' => $current_version,
			],
		];
		$anchors     = [
			'globalClasses'            => BRICKS_DB_GLOBAL_CLASSES,
			'globalClassCategories'    => BRICKS_DB_GLOBAL_CLASSES_CATEGORIES,
			'components'               => BRICKS_DB_COMPONENTS,
			'globalVariables'          => BRICKS_DB_GLOBAL_VARIABLES,
			'globalVariableCategories' => BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES,
			'colorPalettes'            => BRICKS_DB_COLOR_PALETTE,
		];

		foreach ( [ 'themeStyles' ] as $local_resource ) {
			if ( ! isset( $versioned_resources[ $local_resource ] ) ) {
				continue;
			}

			$local_snapshot = $versioned_resources[ $local_resource ];
			if (
				(int) ( $local_snapshot['siteId'] ?? -1 ) !== $current_site_id ||
				(int) ( $local_snapshot['designSystemVersion'] ?? -1 ) !== $current_version
			) {
				return Error::conflict(
					'design_system_snapshot_unstable',
					[
						'message'  => 'The design system changed while current-site resources were being read. Retry get-design-context.',
						'resource' => $local_resource,
					]
				);
			}
		}

		foreach ( $anchors as $resource => $anchor_option ) {
			$site_id          = Design_Option_Store::authority_site_id( $anchor_option );
			$version_snapshot = $versioned_resources[ $resource ] ?? Design_Option_Store::read_related(
				$anchor_option,
				self::DESIGN_SYSTEM_VERSION_OPTION,
				0
			);

			if ( is_wp_error( $version_snapshot ) ) {
				return $version_snapshot;
			}

			if ( ! array_key_exists( 'designSystemVersion', $version_snapshot ) ) {
				$version_snapshot['designSystemVersion'] = (int) $version_snapshot['value'];
			}

			$version = (int) ( $version_snapshot['designSystemVersion'] ?? -1 );
			if ( (int) ( $version_snapshot['siteId'] ?? -1 ) !== $site_id || $version < 0 ) {
				return Error::conflict(
					'design_system_snapshot_unstable',
					[
						'message'  => 'A design payload was not paired with a valid authority version. Retry the read.',
						'resource' => $resource,
					]
				);
			}

			if (
				array_key_exists( (string) $site_id, $authorities ) &&
				$authorities[ (string) $site_id ] !== $version
			) {
				return Error::conflict(
					'design_system_snapshot_unstable',
					[
						'message'  => 'The design system changed while resources on one authority were being read. Retry get-design-context.',
						'resource' => $resource,
					]
				);
			}

			$authorities[ (string) $site_id ] = $version;
			$resources[ $resource ]           = [
				'siteId'  => $site_id,
				'version' => $version,
			];
		}

		ksort( $authorities, SORT_NUMERIC );

		return [
			'token'       => hash( 'sha256', (string) wp_json_encode( $authorities ) ),
			'authorities' => array_map(
				static function ( $site_id, $version ) {
					return [
						'siteId'  => (int) $site_id,
						'version' => (int) $version,
					];
				},
				array_keys( $authorities ),
				array_values( $authorities )
			),
			'resources'   => $resources,
		];
	}

	/**
	 * Bump the design-system version counter and invalidate the component
	 * usage transient.
	 *
	 * Called from the option-write hooks registered in Manager.
	 *
	 * @since 2.4
	 */
	public static function bump_design_system_version(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic option increment prevents lost updates.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
				self::DESIGN_SYSTEM_VERSION_OPTION,
				'1',
				'no'
			)
		);

		wp_cache_delete( self::DESIGN_SYSTEM_VERSION_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		delete_transient( self::COMPONENT_USAGE_TRANSIENT );
	}

	/**
	 * Fetch component usage with a lazy transient.
	 *
	 * Returns { data, cached } where `data` is `{ componentId => [ usage rows ] }`
	 * and `cached` reports whether the value was served from the transient.
	 *
	 * @since 2.4
	 *
	 * @return array|\WP_Error
	 */
	private static function get_component_usage_cached() {
		$cached = get_transient( self::COMPONENT_USAGE_TRANSIENT );
		$usage  = $cached;

		if ( ! is_array( $cached ) ) {
			$usage = self::compute_component_usage();

			if ( is_wp_error( $usage ) ) {
				return $usage;
			}

			set_transient( self::COMPONENT_USAGE_TRANSIENT, $usage, self::COMPONENT_USAGE_TTL );
		}

		// Keep the shared cache complete; permissions may differ between callers or change before expiry.
		$visible_posts = [];
		foreach ( $usage as $cid => $rows ) {
			$usage[ $cid ] = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( &$visible_posts ) {
						if ( ( $row['targetType'] ?? '' ) === 'component' ) {
							return true;
						}

						$post_id = (int) ( $row['postId'] ?? 0 );
						if ( ! isset( $visible_posts[ $post_id ] ) ) {
							$visible_posts[ $post_id ] = $post_id > 0 && \Bricks\Capabilities::current_user_can_use_builder( $post_id );
						}

						return $visible_posts[ $post_id ];
					}
				)
			);
		}

		return [
			'data'   => $usage,
			'cached' => is_array( $cached ),
		];
	}

	/**
	 * Scan every Bricks post, template, and component definition for component instances.
	 * Expensive; result is cached by get_component_usage_cached().
	 *
	 * TODO: promote to a maintained `bricks_component_usage` index table
	 * updated on Save_Pipeline writes if large-site latency becomes a
	 * real complaint. Not urgent - Bricks sites typically hold repeated
	 * layouts in templates, not in thousands of posts.
	 *
	 * @since 2.4
	 *
	 * @return array<string, array<int, array<string, mixed>>>|\WP_Error
	 */
	private static function compute_component_usage() {
		$instances = [];

		$bricks_post_ids = \Bricks\Helpers::get_all_bricks_post_ids( [ 'meta_query' => [] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Removes the metadata constraint to include Gutenberg-only posts.
		$template_ids    = \Bricks\Templates::get_all_template_ids();
		$post_ids        = array_unique( array_merge( $bricks_post_ids, $template_ids ) );

		foreach ( $post_ids as $post_id ) {
			$type = 'content';

			if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				$type = \Bricks\Templates::get_template_type( $post_id );
			}

			$bricks_data = \Bricks\Database::get_data( $post_id, $type );
			$bricks_data = array_merge( $bricks_data, \Bricks\Helpers::get_component_block_data( $post_id ) );
			$bricks_data = \Bricks\Database::get_component_data( $bricks_data );
			$json        = wp_json_encode( $bricks_data );

			if ( ! $json ) {
				continue;
			}

			if ( ! preg_match_all( '/"cid":"(.*?)"/', $json, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $cid ) {
				if ( ! isset( $instances[ $cid ] ) ) {
					$instances[ $cid ] = [];
				}

				if ( isset( $instances[ $cid ][ $post_id ] ) ) {
					$instances[ $cid ][ $post_id ]['count']++;
					continue;
				}

				$post_type        = get_post_type( $post_id );
				$post_type_object = get_post_type_object( $post_type );

				$instances[ $cid ][ $post_id ] = [
					'postId'    => $post_id,
					'postTitle' => get_the_title( $post_id ),
					'postType'  => $post_type_object->labels->singular_name ?? $post_type,
					'count'     => 1,
					'editUrl'   => \Bricks\Helpers::get_builder_edit_link( $post_id ),
				];
			}
		}

		$component_snapshot = Design_Option_Store::read( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = $component_snapshot['value'];

		if ( is_array( $components ) ) {
			foreach ( $components as $component ) {
				$component_id = (string) ( $component['id'] ?? '' );
				$elements     = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];
				$json         = wp_json_encode( $elements );

				if ( $component_id === '' || ! $json ) {
					continue;
				}

				if ( ! preg_match_all( '/"cid":"(.*?)"/', $json, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $cid ) {
					if ( ! isset( $instances[ $cid ] ) ) {
						$instances[ $cid ] = [];
					}

					$usage_key = 'component:' . $component_id;

					if ( isset( $instances[ $cid ][ $usage_key ] ) ) {
						$instances[ $cid ][ $usage_key ]['count']++;
						continue;
					}

					$instances[ $cid ][ $usage_key ] = [
						'componentId'    => $component_id,
						'componentLabel' => self::component_label( $component ),
						'targetType'     => 'component',
						'count'          => 1,
					];
				}
			}
		}

		// Flatten inner usage maps to sequential arrays for stable JSON shape.
		foreach ( $instances as $cid => $by_post ) {
			$instances[ $cid ] = array_values( $by_post );
		}

		return $instances;
	}

	// ------------------------------------------------------------------
	// bricks/generate-color-shades
	//
	// PHP port of generateShades() in PopupColorShades.vue:230. Mirrors
	// the builder exactly so generated shade sets match the
	// Generate Shades button. See includes/abilities/color.php for the
	// mix/alpha math.
	// ------------------------------------------------------------------

	/**
	 * Input schema for generate-color-shades
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function generate_color_shades_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'paletteId'         => [
					'type'        => 'string',
					'description' => __( 'ID of the palette that owns the base color. Required when `save` is true.', 'bricks' ),
				],
				'colorId'           => [
					'type'        => 'string',
					'description' => __( 'ID of the base color inside the palette. Required when `save` is true.', 'bricks' ),
				],
				'baseColor'         => [
					'type'        => 'string',
					'description' => __( 'Raw base color string (hex, rgb(a), or hsl(a)). Required when `paletteId` / `colorId` are omitted. Lets you preview shades without needing an existing palette.', 'bricks' ),
				],
				'baseVariable'      => [
					'type'        => 'string',
					'description' => __( 'Optional CSS variable reference for the base (e.g. `var(--primary)`). When present, shade `raw` becomes `var(--{name}-{l|d|t}-{index})` to match the builder\'s auto-naming.', 'bricks' ),
				],
				'shadeType'         => [
					'type'        => 'string',
					'description' => __( 'Param name is exactly `shadeType` (NOT `type`). One of: light (mix toward white), dark (mix toward black), transparent (reduce alpha on base HSL).', 'bricks' ),
					'enum'        => self::COLOR_SHADE_TYPES,
					'required'    => true,
				],
				'steps'             => [
					'type'        => 'integer',
					'description' => __( 'Param name is exactly `steps` (NOT `count`). How many shades to generate (excluding the original color). Matches the builder\'s "Shade steps" input. Clamped to 1-20.', 'bricks' ),
					'required'    => true,
					'minimum'     => 1,
					'maximum'     => 20,
				],
				'mode'              => [
					'type'        => 'string',
					'description' => __( 'light (default) or dark. When dark, shades are stored in each shade\'s `dark` field to match the builder\'s dark-mode pair. Only set dark if the base color has darkModeEnabled.', 'bricks' ),
					'enum'        => [ 'light', 'dark' ],
				],
				'save'              => [
					'type'        => 'boolean',
					'description' => __( 'When true, remove any existing shades of the same type/parent/mode from the palette and insert the new ones. When false (default), return the generated shades for review.', 'bricks' ),
				],
				'expectedOwnership' => self::ownership_input_schema(
					__( 'Required when save is true. Use saveOwnership from a preview targeting this palette color. Includes the parent color digest and complete palette resource digest.', 'bricks' )
				),
			],
			'required'             => [ 'shadeType', 'steps' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for generate-color-shades
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function generate_color_shades_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'shades'           => [
					'type'        => 'array',
					'description' => __( 'Generated shade objects: { id, type, raw, light|dark, index, parent }.', 'bricks' ),
				],
				'saved'            => [ 'type' => 'boolean' ],
				'paletteId'        => [ 'type' => [ 'string', 'null' ] ],
				'colorId'          => [ 'type' => [ 'string', 'null' ] ],
				'saveOwnership'    => [ 'type' => [ 'object', 'null' ] ],
				'ownership'        => [ 'type' => [ 'object', 'null' ] ],
				'colorDigest'      => [ 'type' => [ 'string', 'null' ] ],
				'paletteDigest'    => [ 'type' => [ 'string', 'null' ] ],
				'itemOwnership'    => [ 'type' => [ 'object', 'null' ] ],
				'paletteOwnership' => [ 'type' => [ 'object', 'null' ] ],
				'changed'          => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Permission: bricks_full_access.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	public static function generate_color_shades_permission() {
		return Manager::require_cap( 'bricks_full_access' );
	}

	/**
	 * Callback: generate color shades
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function generate_color_shades( $input ) {
		Manager::flush_options_cache();

		$allowed_keys = [ 'paletteId', 'colorId', 'baseColor', 'baseVariable', 'shadeType', 'steps', 'mode', 'save', 'expectedOwnership' ];
		$unknown      = array_diff( array_keys( (array) $input ), $allowed_keys );
		if ( ! empty( $unknown ) ) {
			return Error::unknown_param( reset( $unknown ), $allowed_keys );
		}

		$shade_type = (string) ( $input['shadeType'] ?? '' );
		$steps      = max( 1, min( 20, (int) ( $input['steps'] ?? 0 ) ) );
		$mode       = (string) ( $input['mode'] ?? 'light' );
		$save       = ! empty( $input['save'] );

		if ( ! in_array( $shade_type, self::COLOR_SHADE_TYPES, true ) ) {
			return Error::invalid_param( 'shadeType', 'one of: ' . implode( ', ', self::COLOR_SHADE_TYPES ), $shade_type );
		}

		if ( ! in_array( $mode, [ 'light', 'dark' ], true ) ) {
			return Error::invalid_param( 'mode', 'one of: light, dark', $mode );
		}

		$palette_id = isset( $input['paletteId'] ) ? (string) $input['paletteId'] : '';
		$color_id   = isset( $input['colorId'] ) ? (string) $input['colorId'] : '';
		$raw_base   = isset( $input['baseColor'] ) ? (string) $input['baseColor'] : '';
		$raw_var    = isset( $input['baseVariable'] ) ? (string) $input['baseVariable'] : '';

		$palette          = null;
		$palette_index    = -1;
		$color            = null;
		$color_index      = -1;
		$palette_snapshot = null;
		$save_ownership   = null;

		if ( $palette_id !== '' || $color_id !== '' || $save ) {
			if ( $palette_id === '' || $color_id === '' ) {
				return Error::invalid_param( 'paletteId|colorId', 'both required when referencing an existing palette or when save=true', null );
			}

			$palette_snapshot = self::read_color_palette_snapshot();

			if ( is_wp_error( $palette_snapshot ) ) {
				return $palette_snapshot;
			}

			$palettes = self::color_palettes_from_snapshot( $palette_snapshot );

			foreach ( $palettes as $pi => $p ) {
				if ( ( $p['id'] ?? '' ) === $palette_id ) {
					$palette       = $p;
					$palette_index = $pi;
					break;
				}
			}

			if ( ! $palette ) {
				return Error::not_found( 'palette', $palette_id );
			}

			$colors = $palette['colors'] ?? [];

			foreach ( $colors as $ci => $c ) {
				if ( ( $c['id'] ?? '' ) === $color_id ) {
					$color       = $c;
					$color_index = $ci;
					break;
				}
			}

			if ( ! $color ) {
				return Error::not_found( 'color', $color_id );
			}

			if ( ! empty( $color['parent'] ) || ! empty( $color['type'] ) ) {
				return Error::invalid_param( 'colorId', 'a root palette color; shade generation cannot target another shade', $color_id );
			}

			$save_ownership = self::design_ownership( self::COLOR_PALETTES_RESOURCE, $palette_snapshot, $color );

			if ( is_wp_error( $save_ownership ) ) {
				return $save_ownership;
			}

			if ( $mode === 'dark' ) {
				if ( empty( $color['darkModeEnabled'] ) ) {
					return Error::invalid_param( 'mode', 'dark only when the palette color has darkModeEnabled', $mode );
				}

				if ( empty( $color['dark'] ) && ! empty( $color['light'] ) ) {
					$derived_dark = Color::derive_dark_mode_color( $color['light'] );

					if ( $derived_dark === '' ) {
						return Error::invalid_param( 'color.dark', 'a saved dark value or parseable light value', $color['dark'] ?? null );
					}

					$color['dark'] = $derived_dark;

					if ( $save ) {
						$palette['colors'][ $color_index ] = $color;
					}
				}
			}

			// Derive the base color from the palette entry.
			if ( $raw_base === '' ) {
				$raw_base = $mode === 'dark'
					? (string) ( $color['dark'] ?? $color['light'] ?? '' )
					: (string) ( $color['light'] ?? '' );
			}

			if ( $raw_var === '' ) {
				$raw_var = (string) ( $color['raw'] ?? '' );
			}
		}

		if ( $raw_base === '' ) {
			return Error::invalid_param( 'baseColor', 'a CSS color string (hex / rgb / hsl)', $raw_base );
		}

		$base = Color::parse( $raw_base );

		if ( ! $base ) {
			return Error::invalid_param( 'baseColor', 'a parseable CSS color string', $raw_base );
		}

		$var_name = '';

		if ( $raw_var !== '' && preg_match( '/var\((--[a-zA-Z0-9-_]+)\)/', $raw_var, $m ) ) {
			$var_name = $m[1];
		}

		$type_char = $shade_type[0]; // l | d | t

		// Mix target per builder: light to white, dark to black, transparent to alpha-only.
		if ( $shade_type === 'light' ) {
			$mix_target = Color::parse( '#ffffff' );
		} elseif ( $shade_type === 'dark' ) {
			$mix_target = Color::parse( '#000000' );
		} else {
			$mix_target = null;
		}

		$shades = [];

		for ( $i = 0; $i < $steps; $i++ ) {
			// Percentage formula from PopupColorShades.vue:250.
			$percentage = $shade_type === 'transparent'
				? ( 100 / ( $steps + 1 ) ) * ( $i + 1 )
				: ( 100 / ( $steps + 1 ) ) * ( $steps - $i );

			if ( $shade_type === 'transparent' ) {
				$shade_color  = Color::with_alpha( $base, $percentage / 100 );
				$force_format = 'hsl';
			} else {
				$shade_color  = Color::mix( $mix_target, $base, $percentage );
				$force_format = '';
			}

			$raw = $var_name !== ''
				? sprintf( 'var(%s-%s-%d)', $var_name, $type_char, $i + 1 )
				: $raw_base;

			$shade = [
				'id'     => \Bricks\Helpers::generate_random_id( false ),
				'type'   => $shade_type,
				'raw'    => $raw,
				'index'  => $i,
				'parent' => $color_id,
			];

			$output_format = $force_format !== '' ? $force_format : ( $base['format'] ?? 'hex' );
			$value         = Color::to_string( $shade_color, $output_format );

			if ( $mode === 'light' ) {
				$shade['light'] = $value;
			} elseif ( $mode === 'dark' ) {
				$shade['dark'] = $value;
			}

			$shades[] = $shade;
		}

		$saved = false;

		if ( $save ) {
			$expected_ownership = $input['expectedOwnership'] ?? null;

			if ( ! is_array( $expected_ownership ) || ! isset( $expected_ownership['itemDigest'] ) ) {
				return Error::invalid_param( 'expectedOwnership', 'saveOwnership from a current generate-color-shades preview', $expected_ownership );
			}

			$variable_guard = self::palette_variable_uniqueness_guard( $palette_snapshot );

			if ( is_wp_error( $variable_guard ) ) {
				return $variable_guard;
			}

			$colors = $palette['colors'] ?? [];

			// Per PopupColorShades.vue:314 - remove existing shades with same type/parent/mode first.
			$colors = array_values(
				array_filter(
					$colors,
					static function ( $c ) use ( $shade_type, $color_id, $mode ) {
						$same = ( $c['type'] ?? '' ) === $shade_type
							&& ( $c['parent'] ?? '' ) === $color_id;

						if ( ! $same ) {
							return true;
						}

						// When regenerating light-mode shades we only remove existing light-mode entries - same for dark.
						if ( $mode === 'dark' ) {
							return empty( $c['dark'] );
						}

						return empty( $c['light'] );
					}
				)
			);

			$uniqueness_palettes                             = $palettes;
			$uniqueness_palettes[ $palette_index ]['colors'] = $colors;

			foreach ( $shades as $shade ) {
				$raw_unique = self::validate_palette_shade_raw_is_unique( $shade, $uniqueness_palettes, $variable_guard['value'], $palette_id );

				if ( is_wp_error( $raw_unique ) ) {
					return $raw_unique;
				}
			}

			$colors                     = array_merge( $colors, $shades );
			$palette['colors']          = $colors;
			$palettes[ $palette_index ] = $palette;

			$shade_graph_valid = self::validate_color_palette_resource_graph( $palettes );

			if ( is_wp_error( $shade_graph_valid ) ) {
				return $shade_graph_valid;
			}

			$save_result = self::save_color_palettes_owned(
				$palette_snapshot,
				$palettes,
				$expected_ownership,
				static function ( $authoritative_palettes ) use ( $palette_id, $color_id ) {
					$authoritative_palette = self::find_design_item( $authoritative_palettes, 'id', $palette_id );

					return is_array( $authoritative_palette )
						? self::find_design_item( $authoritative_palette['colors'] ?? [], 'id', $color_id )
						: null;
				},
				false,
				[ $variable_guard ]
			);

			if ( is_wp_error( $save_result ) ) {
				return $save_result;
			}

			$saved_palette = self::find_design_item( $save_result['snapshot']['value'], 'id', $palette_id );
			$saved_color   = is_array( $saved_palette ) ? self::find_design_item( $saved_palette['colors'] ?? [], 'id', $color_id ) : null;

			if ( ! is_array( $saved_palette ) || ! is_array( $saved_color ) ) {
				return self::committed_design_readback_error(
					self::COLOR_PALETTES_RESOURCE,
					$color_id,
					null,
					$save_result['ownership'],
					$saved_color
				);
			}

			$save_ownership = $save_result['ownership'];
			$color          = $saved_color;
			$palette        = $saved_palette;
			$saved          = true;
			$changed        = $save_result['changed'];
		} else {
			$changed = false;
		}

		return [
			'shades'           => $shades,
			'saved'            => $saved,
			'paletteId'        => $palette_id ? $palette_id : null,
			'colorId'          => $color_id ? $color_id : null,
			'saveOwnership'    => $save_ownership,
			'ownership'        => $saved ? $save_ownership : null,
			'colorDigest'      => is_array( $color ) ? Design_Option_Store::item_digest( $color ) : null,
			'paletteDigest'    => is_array( $palette ) ? Design_Option_Store::item_digest( $palette ) : null,
			'itemOwnership'    => is_array( $color ) && is_array( $save_ownership )
				? self::item_ownership( $save_ownership, $color )
				: null,
			'paletteOwnership' => is_array( $palette ) && is_array( $save_ownership )
				? self::item_ownership( $save_ownership, $palette )
				: null,
			'changed'          => $changed,
		];
	}

	// ------------------------------------------------------------------
	// bricks/generate-scale-variables
	//
	// PHP port of createVariableObject() in src/vue/store/actions.js:6168.
	// Generates fluid clamp() variables for a scale range. Math mirrors
	// the builder exactly so a preview requested through the ability
	// matches what the user sees when they hit Generate in the UI.
	// ------------------------------------------------------------------

	const SCALE_DEFAULT_HTML_FONT_SIZE_PX = 10;
	const SCALE_BASE_BROWSER_FONT_SIZE_PX = 16;

	/**
	 * Input schema for generate-scale-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function generate_scale_variables_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'categoryId'                => [
					'type'        => 'string',
					'description' => __( 'ID of an existing global variable category with a scale config. Mutually exclusive with `category`.', 'bricks' ),
				],
				'category'                  => [
					'type'        => 'object',
					'description' => __( 'Inline category definition for preview-only generation. Use this to preview a scale before saving the category. Must include `scale` object. Mutually exclusive with `categoryId`.', 'bricks' ),
				],
				'scaleRange'                => [
					'type'        => 'object',
					'description' => __( 'Inclusive range of scale steps relative to baseline. Default: { from: -2, to: 4 }. Example: -1 is one step below baseline, +2 is two steps above.', 'bricks' ),
					'properties'  => [
						'from' => [ 'type' => 'integer' ],
						'to'   => [ 'type' => 'integer' ],
					],
				],
				'save'                      => [
					'type'        => 'boolean',
					'description' => __( 'Reserved for atomic preview/apply. Direct two-store persistence currently fails closed; use the preview rows with ownership-guarded variable/category abilities.', 'bricks' ),
				],
				'expectedGenerationDigest'  => [ 'type' => 'string' ],
				'expectedVariableOwnership' => self::ownership_input_schema( __( 'Variable ownership returned in saveOwnership by the preview.', 'bricks' ) ),
				'expectedCategoryOwnership' => self::ownership_input_schema( __( 'Category resource ownership returned in saveOwnership by the preview.', 'bricks' ) ),
			],
		];
	}

	/**
	 * Output schema for generate-scale-variables
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function generate_scale_variables_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'variables'        => [
					'type'        => 'array',
					'description' => __( 'Generated variable objects (id, name, value, category, scale).', 'bricks' ),
				],
				'scaleNames'       => [
					'type'        => 'array',
					'description' => __( 'Step names for the generated range, in order. Written back to the category when `save` is true.', 'bricks' ),
				],
				'saved'            => [
					'type'        => 'boolean',
					'description' => __( 'Whether the variables were persisted to the global variables store.', 'bricks' ),
				],
				'basis'            => [
					'type'        => 'object',
					'description' => __( 'Math basis used: htmlFontSizePx, minScreenWidth, maxScreenWidth, unit.', 'bricks' ),
				],
				'generationDigest' => [ 'type' => 'string' ],
				'saveOwnership'    => [ 'type' => [ 'object', 'null' ] ],
			],
		];
	}

	/**
	 * Permission for generate-scale-variables
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	public static function generate_scale_variables_permission() {
		return Manager::require_cap( 'bricks_full_access' );
	}

	/**
	 * Callback: generate fluid scale variables
	 *
	 * Ports createVariableObject + getVariableScaleName from
	 * src/vue/store/actions.js:6058-6365. Math is intentionally identical
	 * to the Vue implementation - round-trip through the builder should
	 * produce the same clamp() output.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function generate_scale_variables( $input ) {
		Manager::flush_options_cache();

		$category_id     = $input['categoryId'] ?? '';
		$inline_category = $input['category'] ?? null;
		$save_requested  = ! empty( $input['save'] );

		if ( ! $category_id && ! is_array( $inline_category ) ) {
			return Error::invalid_param( 'categoryId|category', 'one of categoryId (string) or category (object)', null );
		}

		if ( $category_id && is_array( $inline_category ) ) {
			return Error::invalid_param( 'categoryId|category', 'only one of categoryId or category', null );
		}

		$category          = null;
		$category_snapshot = null;
		$variable_snapshot = null;

		if ( $category_id ) {
			$category_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
			$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

			if ( is_wp_error( $category_snapshot ) ) {
				return $category_snapshot;
			}

			if ( is_wp_error( $variable_snapshot ) ) {
				return $variable_snapshot;
			}

			$categories = $category_snapshot['value'];

			if ( is_array( $categories ) ) {
				foreach ( $categories as $cat ) {
					if ( ( $cat['id'] ?? '' ) === $category_id ) {
						$category = $cat;
						break;
					}
				}
			}

			if ( ! $category ) {
				return Error::not_found( 'variable_category', $category_id );
			}
		} else {
			$category = $inline_category;
		}

		if ( $variable_snapshot === null ) {
			$variable_snapshot = self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] );

			if ( is_wp_error( $variable_snapshot ) ) {
				return $variable_snapshot;
			}
		}

		$scale_validation = self::validate_scale_config( $category['scale'] ?? null, $category_id ? "variable_category[{$category_id}].scale" : 'category.scale' );

		if ( is_wp_error( $scale_validation ) ) {
			return $scale_validation;
		}

		$range_from = (int) ( $input['scaleRange']['from'] ?? -2 );
		$range_to   = (int) ( $input['scaleRange']['to'] ?? 4 );

		if ( $range_from > $range_to ) {
			return Error::invalid_param( 'scaleRange', 'from <= to', compact( 'range_from', 'range_to' ) );
		}

		$style_manager_snapshot = Design_Option_Store::read_related( BRICKS_DB_GLOBAL_VARIABLES, BRICKS_DB_STYLE_MANAGER, [] );
		$theme_styles_snapshot  = Design_Option_Store::read_related( BRICKS_DB_GLOBAL_VARIABLES, BRICKS_DB_THEME_STYLES, [] );

		if ( is_wp_error( $style_manager_snapshot ) ) {
			return $style_manager_snapshot;
		}

		if ( is_wp_error( $theme_styles_snapshot ) ) {
			return $theme_styles_snapshot;
		}

		if (
			(int) $style_manager_snapshot['siteId'] !== (int) $variable_snapshot['siteId'] ||
			(int) $theme_styles_snapshot['siteId'] !== (int) $variable_snapshot['siteId']
		) {
			return Error::conflict(
				'scale_variable_basis_authority_mismatch',
				[
					'message'        => 'Scale preview inputs could not be pinned to the variable authority site. No variables were generated or saved.',
					'variableSiteId' => (int) $variable_snapshot['siteId'],
				]
			);
		}

		$style_manager     = is_array( $style_manager_snapshot['value'] ) ? $style_manager_snapshot['value'] : [];
		$theme_styles      = is_array( $theme_styles_snapshot['value'] ) ? $theme_styles_snapshot['value'] : [];
		$html_font_size_px = self::resolve_scale_html_font_size( $style_manager, $theme_styles );
		$min_screen_width  = (float) ( $style_manager['minScreenWidth'] ?? 360 );
		$max_screen_width  = (float) ( $style_manager['maxScreenWidth'] ?? 1440 );

		$variables   = [];
		$scale_names = [];

		for ( $scale = $range_from; $scale <= $range_to; $scale++ ) {
			$obj = self::build_scale_variable( $category, $scale, $html_font_size_px, $min_screen_width, $max_screen_width );

			if ( $obj ) {
				$variables[]   = $obj;
				$scale_names[] = (string) $obj['scale']['scaleName'];
			}
		}

		$unit = ( $html_font_size_px && is_numeric( $html_font_size_px ) ) ? 'rem' : 'px';

		$semantic_variables = array_map(
			static function ( $variable ) {
				unset( $variable['id'] );
				return $variable;
			},
			$variables
		);
		$basis              = [
			'htmlFontSizePx' => $html_font_size_px,
			'minScreenWidth' => $min_screen_width,
			'maxScreenWidth' => $max_screen_width,
			'unit'           => $unit,
			'basisSiteId'    => (int) $variable_snapshot['siteId'],
			'basisDigest'    => Design_Option_Store::item_digest(
				[
					'styleManager' => $style_manager,
					'themeStyles'  => $theme_styles,
				]
			),
		];
		$generation_digest  = hash(
			'sha256',
			(string) wp_json_encode(
				[
					'category'  => $category,
					'range'     => [ $range_from, $range_to ],
					'basis'     => $basis,
					'variables' => $semantic_variables,
					'names'     => $scale_names,
				]
			)
		);
		$save_ownership     = null;

		if ( $category_id ) {
			$variable_ownership = self::design_ownership( self::GLOBAL_VARIABLES_RESOURCE, $variable_snapshot );
			$category_ownership = self::design_ownership( self::GLOBAL_VARIABLE_CATEGORIES_RESOURCE, $category_snapshot );

			if ( is_wp_error( $variable_ownership ) ) {
				return $variable_ownership;
			}

			if ( is_wp_error( $category_ownership ) ) {
				return $category_ownership;
			}

			$save_ownership = [
				'variableOwnership'     => $variable_ownership,
				'categoryOwnership'     => $category_ownership,
				'categoryItemOwnership' => self::item_ownership( $category_ownership, $category ),
			];
		}

		if ( $save_requested ) {
			return Error::conflict(
				'scale_variable_atomic_save_unavailable',
				[
					'message'          => 'Scale preview succeeded, but variables and category step names cannot yet be committed as one atomic operation. No data was changed. Persist only after an atomic compound writer is available.',
					'generationDigest' => $generation_digest,
					'saveOwnership'    => $save_ownership,
					'saved'            => false,
				]
			);
		}

		return [
			'variables'        => $variables,
			'scaleNames'       => $scale_names,
			'saved'            => false,
			'basis'            => $basis,
			'generationDigest' => $generation_digest,
			'saveOwnership'    => $save_ownership,
		];
	}

	/**
	 * Resolve the html font-size (in px) used as px to rem divisor.
	 *
	 * Mirrors the three-source fallback in actions.js:6203:
	 *   style manager override > theme styles (base breakpoint) > default 10px.
	 *
	 * @since 2.4
	 *
	 * @param array $style_manager Variable-authority Style Manager settings.
	 * @param array $theme_styles  Variable-authority theme styles.
	 * @return float
	 */
	private static function resolve_scale_html_font_size( array $style_manager, array $theme_styles ) {
		if ( is_array( $style_manager ) && ! empty( $style_manager['htmlFontSize'] ) ) {
			$parsed = self::parse_html_font_size_to_px( $style_manager['htmlFontSize'] );

			if ( $parsed ) {
				return $parsed;
			}
		}

		// Theme styles typographyHtml (base breakpoint).
		if ( is_array( $theme_styles ) ) {
			foreach ( $theme_styles as $theme_style ) {
				$typography = $theme_style['settings']['typography'] ?? null;

				if ( is_array( $typography ) && ! empty( $typography['typographyHtml'] ) ) {
					$parsed = self::parse_html_font_size_to_px( $typography['typographyHtml'] );

					if ( $parsed ) {
						return $parsed;
					}
				}
			}
		}

		return self::SCALE_DEFAULT_HTML_FONT_SIZE_PX;
	}

	/**
	 * Parse an html font-size string to px. Mirrors parseHtmlFontSizeToPx in actions.js.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Raw value (e.g. "10px", "62.5%", "1rem").
	 * @return float|null
	 */
	private static function parse_html_font_size_to_px( $value ) {
		if ( $value === null ) {
			return null;
		}

		$normalized = strtolower( trim( (string) $value ) );

		if ( $normalized === '' ) {
			return null;
		}

		if ( preg_match( '/^-?[\d.]+px$/', $normalized ) ) {
			return (float) $normalized;
		}

		if ( preg_match( '/^-?[\d.]+rem$/', $normalized ) ) {
			return (float) $normalized * self::SCALE_BASE_BROWSER_FONT_SIZE_PX;
		}

		if ( preg_match( '/^-?[\d.]+%$/', $normalized ) ) {
			return ( (float) $normalized / 100 ) * self::SCALE_BASE_BROWSER_FONT_SIZE_PX;
		}

		return null;
	}

	/**
	 * Build a single fluid variable object for a scale step.
	 *
	 * Port of createVariableObject() from actions.js:6168. The shape of
	 * category.scale matches what the builder writes.
	 *
	 * @since 2.4
	 *
	 * @param array $category           Category with scale config.
	 * @param int   $scale              Scale step relative to baseline.
	 * @param float $html_font_size_px  Resolved html font-size.
	 * @param float $min_screen_width   Min screen width in px.
	 * @param float $max_screen_width   Max screen width in px.
	 * @return array|null
	 */
	private static function build_scale_variable( $category, $scale, $html_font_size_px, $min_screen_width, $max_screen_width ) {
		$scale_cfg = $category['scale'] ?? [];

		$prefix              = $scale_cfg['prefix'] ?? '';
		$min_font_size       = (float) ( $scale_cfg['minFontSize'] ?? 16 );
		$min_scale_ratio     = $scale_cfg['minScaleRatio'] ?? 1.2;
		$min_scale_ratio_sel = $scale_cfg['minScaleRatioSelect'] ?? 1.2;
		$max_font_size       = (float) ( $scale_cfg['maxFontSize'] ?? 20 );
		$max_scale_ratio     = $scale_cfg['maxScaleRatio'] ?? 1.25;
		$max_scale_ratio_sel = $scale_cfg['maxScaleRatioSelect'] ?? 1.25;
		$is_manual           = ! empty( $scale_cfg['isManual'] );
		$manual_values       = $scale_cfg['manualValues'] ?? [];
		$scale_names         = $scale_cfg['scaleNames'] ?? [];

		$min_scale_ratio = ( $min_scale_ratio_sel === 'custom' ) ? (float) $min_scale_ratio : (float) $min_scale_ratio_sel;
		$max_scale_ratio = ( $max_scale_ratio_sel === 'custom' ) ? (float) $max_scale_ratio : (float) $max_scale_ratio_sel;

		$min_val = null;
		$max_val = null;

		if ( $is_manual && is_array( $manual_values ) && is_array( $scale_names ) && ! empty( $scale_names ) ) {
			$baseline   = $scale_cfg['baseline'] ?? '';
			$norm       = static fn( $v ) => strtolower( (string) $v );
			$baseline_i = -1;

			foreach ( $scale_names as $i => $name ) {
				if ( $norm( $name ) === $norm( $baseline ) ) {
					$baseline_i = $i;
					break;
				}
			}

			if ( $baseline_i === -1 ) {
				$baseline_i = (int) floor( count( $scale_names ) / 2 );
			}

			$actual_index = $baseline_i + $scale;

			if ( isset( $manual_values[ $actual_index ]['min'], $manual_values[ $actual_index ]['max'] ) ) {
				if ( preg_match( '/^([\d.]+)/', (string) $manual_values[ $actual_index ]['min'], $mn ) &&
					preg_match( '/^([\d.]+)/', (string) $manual_values[ $actual_index ]['max'], $mx ) ) {
					$min_val = (float) $mn[1];
					$max_val = (float) $mx[1];
				}
			}
		}

		if ( $min_val === null || $max_val === null ) {
			$min_val = $min_font_size;
			$max_val = $max_font_size;

			if ( $scale < 0 ) {
				$iterations = abs( $scale );

				for ( $i = 0; $i < $iterations; $i++ ) {
					$min_val /= $min_scale_ratio;
					$max_val /= $max_scale_ratio;
				}
			} elseif ( $scale > 0 ) {
				for ( $i = 0; $i < $scale; $i++ ) {
					$min_val *= $min_scale_ratio;
					$max_val *= $max_scale_ratio;
				}
			}
		}

		$unit  = 'px';
		$min_w = $min_screen_width;
		$max_w = $max_screen_width;

		if ( $html_font_size_px && is_numeric( $html_font_size_px ) && is_finite( (float) $html_font_size_px ) ) {
			$html_font_size_px = (float) $html_font_size_px;
			$min_val          /= $html_font_size_px;
			$max_val          /= $html_font_size_px;
			$min_w            /= $html_font_size_px;
			$max_w            /= $html_font_size_px;
			$unit              = 'rem';
		}

		$min_val = round( $min_val, 2 );
		$max_val = round( $max_val, 2 );
		$min_w   = round( $min_w, 2 );
		$max_w   = round( $max_w, 2 );

			$run   = $max_w - $min_w;
			$slope = $run !== 0.0 ? ( $max_val - $min_val ) / $run : 0;

		$fluid = sprintf( 'calc(%s * (100vw - %s%s) + %s%s)', self::fmt_num( $slope ), self::fmt_num( $min_w ), $unit, self::fmt_num( $min_val ), $unit );
		$value = sprintf( 'clamp(%s%s, %s, %s%s)', self::fmt_num( $min_val ), $unit, $fluid, self::fmt_num( $max_val ), $unit );

		$scale_name = self::resolve_scale_name( $category, $scale );

		if ( $scale_name === null ) {
			return null;
		}

		return [
			'id'       => \Bricks\Helpers::generate_random_id( false ),
			'name'     => $prefix . $scale_name,
			'value'    => $value,
			'category' => $category['id'] ?? '',
			'scale'    => [
				'scale'     => $scale,
				'scaleName' => (string) $scale_name,
			],
		];
	}

	/**
	 * Port of getVariableScaleName() from actions.js:6058.
	 *
	 * @since 2.4
	 *
	 * @param array $category Category with scale config.
	 * @param int   $scale    Scale step.
	 * @return string|int|null
	 */
	private static function resolve_scale_name( $category, $scale ) {
		$scale_cfg   = $category['scale'] ?? [];
		$scale_names = $scale_cfg['scaleNames'] ?? [];
		$scale_type  = $scale_cfg['scaleType'] ?? '';
		$baseline    = $scale_cfg['baseline'] ?? '';

		if ( $scale_type === 'tshirt' ) {
			$baseline_pos = self::tshirt_size_position( $baseline );

			if ( $baseline_pos === null ) {
				return null;
			}

			return self::position_to_tshirt_size( $baseline_pos + $scale );
		}

		if ( $scale_type === 'numeric' ) {
			return (int) $baseline + $scale;
		}

		// Custom scale.
		if ( ! is_array( $scale_names ) || empty( $scale_names ) ) {
			return '-custom';
		}

		$baseline_i = array_search( $baseline, $scale_names, true );

		if ( $baseline_i === false ) {
			return '-custom';
		}

		return $scale_names[ $baseline_i + $scale ] ?? null;
	}

	/**
	 * Parse a t-shirt size (s, m, l, xs, 2xs, xl, 3xl) to integer position.
	 *
	 * @since 2.4
	 *
	 * @param mixed $size Size string.
	 * @return int|null
	 */
	private static function tshirt_size_position( $size ) {
		$size = strtolower( (string) $size );

		if ( $size === 's' ) {
			return 0; }
		if ( $size === 'm' ) {
			return 1; }
		if ( $size === 'l' ) {
			return 2; }

		if ( preg_match( '/^(\d*)xs$/', $size, $m ) ) {
			$n = ( $m[1] === '' ) ? 1 : (int) $m[1];
			return -$n;
		}

		if ( preg_match( '/^(\d*)xl$/', $size, $m ) ) {
			$n = ( $m[1] === '' ) ? 1 : (int) $m[1];
			return 2 + $n;
		}

		return null;
	}

	/**
	 * Convert integer position back to t-shirt size string.
	 *
	 * @since 2.4
	 *
	 * @param int $pos Position.
	 * @return string|null
	 */
	private static function position_to_tshirt_size( $pos ) {
		if ( $pos === 0 ) {
			return 's'; }
		if ( $pos === 1 ) {
			return 'm'; }
		if ( $pos === 2 ) {
			return 'l'; }

		if ( $pos < 0 ) {
			$n = abs( $pos );
			return $n === 1 ? 'xs' : "{$n}xs";
		}

		if ( $pos > 2 ) {
			$n = $pos - 2;
			return $n === 1 ? 'xl' : "{$n}xl";
		}

		return null;
	}

	/**
	 * Format a number for CSS output. Mirrors Vue toString behavior:
	 * trim trailing .0 but keep fractional precision as-is.
	 *
	 * @since 2.4
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function fmt_num( $n ) {
		// Remove trailing zeros from fractional part but preserve
		// integer values without decimal point (matches Vue's default).
		if ( (float) $n === floor( $n ) ) {
			return (string) (int) $n;
		}

		return rtrim( rtrim( sprintf( '%.10F', $n ), '0' ), '.' );
	}

	// ------------------------------------------------------------------
	// bricks/audit-design-system
	//
	// Read-only scan that surfaces design-system rot: orphan var() refs,
	// unused classes/variables/components, theme styles without conditions,
	// and palette fragmentation. Runs a single post+template pass so it
	// scales linearly with site size. No caching - user-triggered only.
	// ------------------------------------------------------------------

	/**
	 * Input schema for audit-design-system
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function audit_design_system_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'scope'        => [
					'type'        => 'string',
					'enum'        => [ 'all', 'orphans', 'unused', 'theme-styles', 'palettes' ],
					'default'     => 'all',
					'description' => __( 'Filter which audit categories to run. `orphans` = references to missing resources (broken). `unused` = resources referenced nowhere (cleanup). `theme-styles` = theme styles with no conditions (dead). `palettes` = fragmentation signals. `all` runs everything.', 'bricks' ),
				],
				'skipPostScan' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Skip the posts and templates scan. Faster, but disables unused-class, unused-variable, unused-component, and orphan-class detection. Only the palette and theme-style configuration are checked.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for audit-design-system
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function audit_design_system_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'summary' => [
					'type'       => 'object',
					'properties' => [
						'errors'   => [ 'type' => 'integer' ],
						'warnings' => [ 'type' => 'integer' ],
						'infos'    => [ 'type' => 'integer' ],
						'scanned'  => [
							'type'       => 'object',
							'properties' => [
								'posts'       => [ 'type' => 'integer' ],
								'classes'     => [ 'type' => 'integer' ],
								'variables'   => [ 'type' => 'integer' ],
								'components'  => [ 'type' => 'integer' ],
								'palettes'    => [ 'type' => 'integer' ],
								'themeStyles' => [ 'type' => 'integer' ],
							],
						],
					],
				],
				'issues'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'severity'     => [ 'type' => 'string' ],
							'category'     => [ 'type' => 'string' ],
							'resourceType' => [ 'type' => 'string' ],
							// Accept string and integer because resources span post IDs (ints),
							// hashed CIDs (strings), and option keys (strings). Forcing one shape
							// trips the executor's output validator and 500s the recovery tool
							// exactly when the caller most needs it.
							'resourceId'   => [ 'type' => [ 'string', 'integer', 'null' ] ],
							'label'        => [ 'type' => [ 'string', 'null' ] ],
							'message'      => [ 'type' => 'string' ],
							'suggestion'   => [ 'type' => [ 'string', 'null' ] ],
						],
					],
				],
			],
		];
	}

	/**
	 * Permission: audit the design system
	 *
	 * @since 2.4
	 *
	 * @return bool|\WP_Error
	 */
	public static function audit_design_system_permission() {
		return self::get_design_context_permission( [] );
	}

	/**
	 * Callback: audit the design system
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function audit_design_system( $input ) {
		$scope          = $input['scope'] ?? 'all';
		$skip_post_scan = ! empty( $input['skipPostScan'] );
		$run_all        = $scope === 'all';

		Manager::flush_options_cache();

		$snapshots = [
			'classes'     => self::read_owned_design_option( BRICKS_DB_GLOBAL_CLASSES, [] ),
			'variables'   => self::read_owned_design_option( BRICKS_DB_GLOBAL_VARIABLES, [] ),
			'components'  => self::read_owned_design_option( BRICKS_DB_COMPONENTS, [] ),
			'palettes'    => self::read_color_palette_snapshot(),
			'themeStyles' => self::read_owned_design_option( BRICKS_DB_THEME_STYLES, [] ),
		];

		foreach ( $snapshots as $snapshot ) {
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
		}

		$classes      = is_array( $snapshots['classes']['value'] ) ? $snapshots['classes']['value'] : [];
		$variables    = is_array( $snapshots['variables']['value'] ) ? $snapshots['variables']['value'] : [];
		$components   = is_array( $snapshots['components']['value'] ) ? $snapshots['components']['value'] : [];
		$palettes     = self::color_palettes_from_snapshot( $snapshots['palettes'] );
		$theme_styles = is_array( $snapshots['themeStyles']['value'] ) ? $snapshots['themeStyles']['value'] : [];

		$issues                  = [];
		$shared_unused_resources = [
			'classes'    => self::multisite_resource_is_shared( 'BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES' ),
			'variables'  => self::multisite_resource_is_shared( 'BRICKS_MULTISITE_USE_MAIN_SITE_VARIABLES' ),
			'components' => self::multisite_resource_is_shared( 'BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS' ),
		];

		$scanned_posts = 0;
		$refs          = [
			'classes'   => [],
			'cids'      => [],
			'variables' => [],
		];

		$run_post_scan = ! $skip_post_scan && ( $run_all || $scope === 'orphans' || $scope === 'unused' );

		if ( $run_post_scan && ( $run_all || $scope === 'unused' ) && in_array( true, $shared_unused_resources, true ) ) {
			$suppressed = array_keys( array_filter( $shared_unused_resources ) );
			$issues[]   = [
				'severity'     => 'warning',
				'category'     => 'coverage',
				'resourceType' => 'design_audit',
				'resourceId'   => 'shared-unused-current-site-coverage',
				'label'        => 'Shared-resource unused coverage',
				'message'      => sprintf( 'Unused findings for shared %s were suppressed because this audit scans references on the current site only.', implode( ', ', $suppressed ) ),
				'suggestion'   => 'Run a network-wide reference audit before treating shared resources as unused.',
				'coverage'     => self::current_site_usage_coverage(),
			];
		}

		if ( $run_post_scan ) {
			$post_references = self::audit_collect_post_references();

			if ( is_wp_error( $post_references ) ) {
				return $post_references;
			}

			[ $refs, $scanned_posts ] = $post_references;
			self::audit_collect_design_option_references( $classes, $theme_styles, $refs );
		}

		// Theme styles - always fast
		if ( $run_all || $scope === 'theme-styles' ) {
			foreach ( $theme_styles as $style_id => $style ) {
				$conditions = self::get_theme_style_conditions( $style );

				if ( ! is_array( $conditions ) || empty( $conditions ) ) {
					$issues[] = [
						'severity'     => 'warning',
						'category'     => 'theme-styles',
						'resourceType' => 'theme_style',
						'resourceId'   => $style_id,
						'label'        => $style['label'] ?? $style_id,
						'message'      => 'Theme style has no conditions. It will not apply to any post.',
						'suggestion'   => 'Add conditions (e.g. [{ main: "any" }]) via update-theme-style, or delete the style.',
					];
				}
			}
		}

		// Palette fragmentation
		if ( $run_all || $scope === 'palettes' ) {
			$colors_by_hex = [];

			foreach ( $palettes as $palette ) {
				$pid = $palette['id'] ?? '';

				foreach ( $palette['colors'] ?? [] as $color ) {
					$raw = strtolower( trim( (string) ( $color['raw'] ?? '' ) ) );

					if ( $raw === '' ) {
						continue;
					}

					if ( ! isset( $colors_by_hex[ $raw ] ) ) {
						$colors_by_hex[ $raw ] = [];
					}

					$colors_by_hex[ $raw ][] = [
						'paletteId' => $pid,
						'colorId'   => $color['id'] ?? '',
						'name'      => $color['name'] ?? '',
					];

					$has_variable_reference = preg_match( '/var\\(\\s*--[a-zA-Z0-9_-]+\\s*\\)/', $raw );

					if ( ! $has_variable_reference ) {
						$issues[] = [
							'severity'     => 'info',
							'category'     => 'palettes',
							'resourceType' => 'palette_color',
							'resourceId'   => ( $color['id'] ?? '' ),
							'label'        => $color['name'] ?? $raw,
							'message'      => 'Palette color `raw` has no CSS variable reference; it can only be referenced by stored color value.',
							'suggestion'   => 'Set `raw` to a CSS variable reference such as `var(--brand-primary)`, or leave it as a literal color if that is intentional.',
						];
					}
				}
			}

			foreach ( $colors_by_hex as $raw => $locations ) {
				if ( count( $locations ) > 1 ) {
					$labels = array_map(
						function ( $l ) {
							return $l['name'] ? $l['name'] : $l['colorId'];
						},
						$locations
					);

					$issues[] = [
						'severity'     => 'info',
						'category'     => 'palettes',
						'resourceType' => 'palette_color',
						'resourceId'   => $raw,
						'label'        => $raw,
						'message'      => sprintf( 'Color `%s` appears %d times across palettes: %s.', $raw, count( $locations ), implode( ', ', $labels ) ),
						'suggestion'   => 'Consolidate to one canonical color; delete duplicates.',
					];
				}
			}
		}

		if ( $run_post_scan ) {
			$class_ids_in_store      = [];
			$variable_names_in_store = [];

			foreach ( $classes as $c ) {
				if ( ! empty( $c['id'] ) ) {
					$class_ids_in_store[ $c['id'] ] = $c['name'] ?? $c['id'];
				}
			}

			foreach ( $variables as $v ) {
				if ( ! empty( $v['name'] ) ) {
					$variable_names_in_store[ $v['name'] ] = true;
				}
			}

			$variable_names_defined = $variable_names_in_store;
			self::audit_collect_palette_variable_definitions( $palettes, $variable_names_defined );

			$component_ids_in_store = [];

			foreach ( $components as $comp ) {
				if ( ! empty( $comp['id'] ) ) {
					$component_ids_in_store[ $comp['id'] ] = $comp['label'] ?? $comp['id'];
				}
			}

			// Orphan references: referenced in posts but not in store.
			if ( $run_all || $scope === 'orphans' ) {
				foreach ( $refs['classes'] as $class_id => $_ ) {
					if ( ! isset( $class_ids_in_store[ $class_id ] ) ) {
						$issues[] = [
							'severity'     => 'error',
							'category'     => 'orphans',
							'resourceType' => 'global_class',
							'resourceId'   => $class_id,
							'label'        => $class_id,
							'message'      => 'Elements reference global class id that no longer exists.',
							'suggestion'   => 'Remove the stale class id from affected elements, or recreate the class.',
						];
					}
				}

				foreach ( $refs['variables'] as $var_name => $_ ) {
					if ( ! isset( $variable_names_defined[ $var_name ] ) ) {
						$issues[] = [
							'severity'     => 'error',
							'category'     => 'orphans',
							'resourceType' => 'global_variable',
							'resourceId'   => $var_name,
							'label'        => __( '--', 'bricks' ) . $var_name,
							'message'      => sprintf( 'CSS references `var(--%s)` but the variable is not defined.', $var_name ),
							'suggestion'   => 'Create the variable, or replace the reference with an existing one.',
						];
					}
				}

				foreach ( $refs['cids'] as $cid => $_ ) {
					if ( ! isset( $component_ids_in_store[ $cid ] ) ) {
						$issues[] = [
							'severity'     => 'error',
							'category'     => 'orphans',
							'resourceType' => 'component',
							'resourceId'   => $cid,
							'label'        => $cid,
							'message'      => 'Elements reference a component that no longer exists.',
							'suggestion'   => 'Recreate the component or remove the orphan instances.',
						];
					}
				}
			}

			// Unused: in store but not referenced anywhere.
			if ( $run_all || $scope === 'unused' ) {
				if ( ! $shared_unused_resources['classes'] ) {
					foreach ( $class_ids_in_store as $class_id => $class_name ) {
						if ( ! isset( $refs['classes'][ $class_id ] ) ) {
							$issues[] = [
								'severity'     => 'info',
								'category'     => 'unused',
								'resourceType' => 'global_class',
								'resourceId'   => $class_id,
								'label'        => __( '.', 'bricks' ) . $class_name,
								'message'      => 'Global class is defined but not referenced by any post, template, or component on this site.',
								'suggestion'   => 'Delete if obsolete, or apply it where intended.',
							];
						}
					}
				}

				if ( ! $shared_unused_resources['variables'] ) {
					foreach ( $variable_names_in_store as $var_name => $_ ) {
						if ( ! isset( $refs['variables'][ $var_name ] ) ) {
							$issues[] = [
								'severity'     => 'info',
								'category'     => 'unused',
								'resourceType' => 'global_variable',
								'resourceId'   => $var_name,
								'label'        => __( '--', 'bricks' ) . $var_name,
								'message'      => 'Global variable is defined but not referenced on this site.',
								'suggestion'   => 'Delete if obsolete, or wire it into a class/theme-style.',
							];
						}
					}
				}

				if ( ! $shared_unused_resources['components'] ) {
					foreach ( $component_ids_in_store as $cid => $label ) {
						if ( ! isset( $refs['cids'][ $cid ] ) ) {
							$issues[] = [
								'severity'     => 'info',
								'category'     => 'unused',
								'resourceType' => 'component',
								'resourceId'   => $cid,
								'label'        => $label,
								'message'      => 'Component has zero instances across posts and templates on this site.',
								'suggestion'   => 'Delete if obsolete, or instance it where intended.',
							];
						}
					}
				}
			}
		}

		$summary = [
			'errors'   => 0,
			'warnings' => 0,
			'infos'    => 0,
			'scanned'  => [
				'posts'       => $scanned_posts,
				'classes'     => count( $classes ),
				'variables'   => count( $variables ),
				'components'  => count( $components ),
				'palettes'    => count( $palettes ),
				'colors'      => array_sum(
					array_map(
						static function ( $palette ) {
							return is_array( $palette['colors'] ?? null ) ? count( $palette['colors'] ) : 0;
						},
						$palettes
					)
				),
				'themeStyles' => count( $theme_styles ),
			],
		];

		foreach ( $issues as $issue ) {
			if ( $issue['severity'] === 'error' ) {
				$summary['errors']++;
			} elseif ( $issue['severity'] === 'warning' ) {
				$summary['warnings']++;
			} else {
				$summary['infos']++;
			}
		}

		return [
			'summary' => $summary,
			'issues'  => $issues,
		];
	}

	/**
	 * Whether a design resource is configured as a network-shared authority.
	 *
	 * @since 2.4
	 *
	 * @param string $flag Multisite sharing constant.
	 * @return bool
	 */
	private static function multisite_resource_is_shared( string $flag ): bool {
		return function_exists( 'is_multisite' ) && is_multisite() && defined( $flag ) && constant( $flag );
	}

	/**
	 * Collect referenced class ids, component ids, and variable names from
	 * every Bricks post + template + component definition in a single pass.
	 *
	 * Matches:
	 *   - "_cssGlobalClasses":["id1","id2"]   global class references
	 *   - "cid":"..."                          component instance references
	 *   - var(--name)                          variable references
	 *
	 * @since 2.4
	 *
	 * @return array [ refs, scanned_post_count ]
	 */
	private static function audit_collect_post_references() {
		$refs = [
			'classes'   => [],
			'cids'      => [],
			'variables' => [],
		];

		$post_ids = array_merge(
			\Bricks\Helpers::get_all_bricks_post_ids(),
			\Bricks\Templates::get_all_template_ids()
		);

		$scanned = 0;

		foreach ( $post_ids as $post_id ) {
			$type = 'content';

			if ( get_post_type( $post_id ) === BRICKS_DB_TEMPLATE_SLUG ) {
				$type = \Bricks\Templates::get_template_type( $post_id );
			}

			$bricks_data = \Bricks\Database::get_data( $post_id, $type );
			$json        = wp_json_encode( $bricks_data );

			if ( ! $json ) {
				continue;
			}

			$scanned++;
			self::audit_scan_json( $json, $refs );
		}

		// Also scan component definitions - they can reference classes
		// and variables the rest of the site doesn't use directly.
		$component_snapshot = self::read_owned_design_option( BRICKS_DB_COMPONENTS, [] );

		if ( is_wp_error( $component_snapshot ) ) {
			return $component_snapshot;
		}

		$components = is_array( $component_snapshot['value'] ) ? $component_snapshot['value'] : [];

		if ( ! empty( $components ) ) {
			$components_json = wp_json_encode( $components );

			if ( $components_json ) {
				self::audit_scan_json( $components_json, $refs );
			}
		}

		return [ $refs, $scanned ];
	}

	/**
	 * Scan global design options that can reference CSS variables.
	 *
	 * @since 2.4
	 *
	 * @param array $classes      Global class rows.
	 * @param array $theme_styles Theme style rows.
	 * @param array $refs         Reference set (modified by reference).
	 * @return void
	 */
	private static function audit_collect_design_option_references( array $classes, array $theme_styles, array &$refs ): void {
		foreach ( [ $classes, $theme_styles ] as $rows ) {
			$json = wp_json_encode( $rows );

			if ( $json ) {
				self::audit_scan_json( $json, $refs );
			}
		}
	}

	/**
	 * Add named palette CSS variables that Bricks emits on the frontend.
	 *
	 * A palette color whose raw value is `var(--name)` and has a light, dark,
	 * RGB, or hex value defines that variable. Keep the audit aligned with
	 * Assets::generate_inline_css_color_vars() so those references are not
	 * reported as missing Variable Manager entries.
	 *
	 * @since 2.4
	 *
	 * @param array $palettes       Color palette rows.
	 * @param array $variable_names Defined variable names (modified by reference).
	 * @return void
	 */
	private static function audit_collect_palette_variable_definitions( array $palettes, array &$variable_names ): void {
		foreach ( $palettes as $palette ) {
			if ( empty( $palette['colors'] ) || ! is_array( $palette['colors'] ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $color ) {
				$has_color_value = ! empty( $color['light'] ) || ! empty( $color['dark'] ) || ! empty( $color['rgb'] ) || ! empty( $color['hex'] );

				if ( ! $has_color_value ) {
					continue;
				}

				$raw = is_string( $color['raw'] ?? null ) ? trim( $color['raw'] ) : '';

				if ( preg_match( '/^var\(\s*--([a-zA-Z0-9_-]+)\s*\)$/', $raw, $matches ) ) {
					$variable_names[ $matches[1] ] = true;
				}
			}
		}
	}

	/**
	 * Populate $refs with class ids, component cids, and variable names
	 * found in a JSON blob. Separate from the caller so components and
	 * posts hit the same regex set.
	 *
	 * @since 2.4
	 *
	 * @param string $json JSON haystack.
	 * @param array  $refs Reference set (modified by reference).
	 */
	private static function audit_scan_json( $json, array &$refs ) {
		if ( preg_match_all( '/"_cssGlobalClasses":\[([^\]]*)\]/', $json, $m ) ) {
			foreach ( $m[1] as $list ) {
				if ( preg_match_all( '/"([^"]+)"/', $list, $ids ) ) {
					foreach ( $ids[1] as $id ) {
						$refs['classes'][ $id ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '/"cid":"([^"]+)"/', $json, $m ) ) {
			foreach ( $m[1] as $cid ) {
				$refs['cids'][ $cid ] = true;
			}
		}

		if ( preg_match_all( '/var\(--([a-zA-Z0-9_-]+)/', $json, $m ) ) {
			foreach ( $m[1] as $name ) {
				$refs['variables'][ $name ] = true;
			}
		}
	}
}

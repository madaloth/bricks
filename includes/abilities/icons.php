<?php
/**
 * Icon abilities
 *
 * Surface for custom icon sets and custom icons Bricks manages in
 * `BRICKS_DB_ICON_SETS`, `BRICKS_DB_CUSTOM_ICONS`, and the disable list
 * in `BRICKS_DB_DISABLED_ICON_SETS`.
 *
 * The admin "Icons" page is the canonical UX. These abilities provide
 * parity for the common CRUD operations - reading icon sets, toggling built-in
 * and custom icon libraries on/off, adding SVG icons, and removing them.
 *
 * SVG payloads are size-bound before sanitization, then pass through the
 * same sanitizer Bricks uses for media uploads (`Bricks\Svg` /
 * `enshrined\svgSanitize`) so a malicious inline `<script>` or `onload=`
 * cannot sneak in via this surface.
 *
 * Storage shapes:
 *   BRICKS_DB_ICON_SETS          : [[ 'id', 'name', 'backgroundColor'? ], ...]
 *   BRICKS_DB_CUSTOM_ICONS       : [[ 'id', 'setId', 'name', 'url', 'attachment_id' ], ...]
 *   BRICKS_DB_DISABLED_ICON_SETS : [ 'font-awesome-6-brands', 'set_abc123', ... ]
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Icons {
	/**
	 * Maximum accepted raw SVG payload size in bytes. Filterable.
	 */
	const MAX_SVG_BYTES = 1024 * 1024;

	// ==================================================================
	// Schemas
	// ==================================================================

	/**
	 * Input schema for list-icon-sets.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_icon_sets_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public static function list_icon_sets_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'customIconSets'   => [ 'type' => 'array' ],
				'disabledIconSets' => [ 'type' => 'array' ],
			],
		];
	}

	public static function set_disabled_icon_sets_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'disabledSets'     => [
					'type'        => 'array',
					'description' => __( 'Full-replacement list of built-in or custom icon-set IDs to hide from the builder (e.g. `["font-awesome-6-brands", "ionicons", "set_abc123"]`). Pass empty array to re-enable all.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
				'disabledIconSets' => [
					'type'        => 'array',
					'description' => __( 'Alias for `disabledSets`.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	public static function set_disabled_icon_sets_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'      => [ 'type' => 'boolean' ],
				'disabledSets' => [ 'type' => 'array' ],
			],
		];
	}

	public static function list_custom_icons_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'setId' => [
						'type'        => 'string',
						'description' => __( 'Optional custom-icon-set ID filter. Returns all icons when omitted.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	public static function list_custom_icons_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [ 'type' => 'array' ],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
			],
		];
	}

	public static function upload_custom_icon_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'setId', 'name', 'svg' ],
			'properties' => [
				'setId' => [
					'type'        => 'string',
					'description' => __( 'ID of the custom icon set the icon belongs to (existing set required). Create sets via `bricks/create-custom-icon-set` or the admin UI.', 'bricks' ),
				],
				'name'  => [
					'type'        => 'string',
					'description' => __( 'Human-readable name shown in the icon picker.', 'bricks' ),
				],
				'svg'   => [
					'type'        => 'string',
					'description' => __( 'Raw SVG markup. Maximum 1 MB by default (configurable via `bricks/abilities/icons/max_svg_bytes` filter). Sanitized server-side via Bricks\' SVG sanitizer, then stored as a Media Library attachment. The custom icon row stores the attachment URL and ID, matching the builder icon manager.', 'bricks' ),
				],
			],
		];
	}

	public static function upload_custom_icon_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'icon'       => [ 'type' => 'object' ],
				'attachment' => [ 'type' => 'object' ],
			],
		];
	}

	public static function delete_custom_icon_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'iconId' ],
			'properties' => [
				'iconId' => [ 'type' => 'string' ],
			],
		];
	}

	public static function delete_custom_icon_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deletedId' => [ 'type' => 'string' ],
			],
		];
	}

	public static function create_custom_icon_set_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'name' ],
			'properties' => [
				'name'            => [
					'type'        => 'string',
					'description' => __( 'Human-readable label shown in the builder icon picker.', 'bricks' ),
				],
				'backgroundColor' => [
					'type'        => 'string',
					'description' => __( 'Optional CSS color value shown behind icons in the picker preview.', 'bricks' ),
				],
			],
		];
	}

	public static function create_custom_icon_set_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'  => [ 'type' => 'string' ],
				'set' => [ 'type' => 'object' ],
			],
		];
	}

	public static function delete_custom_icon_set_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'setId' ],
			'properties' => [
				'setId'             => [
					'type'        => 'string',
					'description' => __( 'ID of the custom icon set to delete. Also removes every custom icon assigned to that set.', 'bricks' ),
				],
				'deleteAttachments' => [
					'type'        => 'boolean',
					'description' => __( 'Whether to permanently delete unreferenced SVG attachments assigned to the set. Defaults to false.', 'bricks' ),
				],
			],
		];
	}

	public static function delete_custom_icon_set_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'            => [ 'type' => 'boolean' ],
				'deletedSetId'       => [ 'type' => 'string' ],
				'deletedIcons'       => [
					'type'        => 'integer',
					'description' => __( 'Number of custom icon rows removed from `BRICKS_DB_CUSTOM_ICONS` as a side effect.', 'bricks' ),
				],
				'deletedAttachments' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'skippedAttachments' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Read permission.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_icon_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_icon_manager' );
		}

		return true;
	}

	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	public static function upload_permission( $input ) {
		$permission = self::write_permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return Error::forbidden_builder_permission( 'upload_files' );
		}
		if ( class_exists( '\\Bricks\\Capabilities' ) && ! \Bricks\Capabilities::current_user_can_upload_svg() ) {
			return Error::forbidden_builder_permission( 'bricks_upload_svg' );
		}
		return true;
	}

	// ==================================================================
	// Execute
	// ==================================================================

	/**
	 * Callback: list icon sets.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_icon_sets( $input ) {
		Manager::flush_options_cache();
		return [
			'customIconSets'   => self::load_icon_sets(),
			'disabledIconSets' => self::load_disabled_sets(),
		];
	}

	public static function set_disabled_icon_sets( $input ) {
		$rows = $input['disabledSets'] ?? ( $input['disabledIconSets'] ?? null );

		if ( ! is_array( $rows ) ) {
			return Error::invalid_param( 'disabledSets', 'array of icon-set IDs', $rows );
		}

		$clean = [];
		foreach ( $rows as $index => $row ) {
			if ( ! is_string( $row ) ) {
				return Error::invalid_param( "disabledSets[$index]", 'string', $row );
			}
			$id = trim( $row );
			if ( $id === '' ) {
				continue;
			}
			$clean[ $id ] = true;
		}

		$final = array_keys( $clean );

		if ( empty( $final ) ) {
			delete_option( BRICKS_DB_DISABLED_ICON_SETS );
		} else {
			update_option( BRICKS_DB_DISABLED_ICON_SETS, $final );
		}

		return [
			'success'      => true,
			'disabledSets' => $final,
		];
	}

	public static function list_custom_icons( $input ) {
		Manager::flush_options_cache();

		$icons  = self::load_custom_icons();
		$set_id = isset( $input['setId'] ) ? (string) $input['setId'] : '';

		if ( $set_id !== '' ) {
			$icons = array_values(
				array_filter(
					$icons,
					fn( $icon ) => (string) ( $icon['setId'] ?? '' ) === $set_id
				)
			);
		}

		return Reference::paginate( $icons, $input );
	}

	public static function upload_custom_icon( $input ) {
		$set_id = (string) ( $input['setId'] ?? '' );
		$name   = trim( (string) ( $input['name'] ?? '' ) );
		$svg    = (string) ( $input['svg'] ?? '' );

		if ( $set_id === '' ) {
			return Error::invalid_param( 'setId', 'non-empty string', $set_id );
		}
		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'non-empty string', $name );
		}
		if ( $svg === '' ) {
			return Error::invalid_param( 'svg', 'non-empty SVG markup', $svg );
		}
		if ( class_exists( '\\Bricks\\Capabilities' ) && ! \Bricks\Capabilities::current_user_can_upload_svg() ) {
			return Error::forbidden_builder_permission( 'bricks_upload_svg' );
		}

		$max_bytes = self::max_svg_bytes();
		if ( strlen( $svg ) > $max_bytes ) {
			return Error::invalid_param(
				'svg',
				sprintf( 'SVG markup <= %d bytes', $max_bytes ),
				[ 'bytes' => strlen( $svg ) ]
			);
		}

		if ( ! self::icon_set_exists( $set_id ) ) {
			return Error::not_found( 'icon_set', $set_id );
		}

		$sanitized = self::sanitize_svg( $svg );
		if ( $sanitized === null ) {
			return Error::invalid_param( 'svg', 'valid, sanitizable SVG markup', '(first 80 chars: ' . mb_substr( $svg, 0, 80 ) . ')' );
		}

		$attachment = self::create_svg_attachment( $sanitized, $name );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$icons = self::load_custom_icons();
		$id    = 'icon_' . substr( md5( uniqid( '', true ) ), 0, 10 );

		$row = [
			'id'            => $id,
			'setId'         => $set_id,
			'name'          => $name,
			'url'           => $attachment['url'],
			'attachment_id' => $attachment['id'],
		];

		$icons[] = $row;
		$saved   = update_option( BRICKS_DB_CUSTOM_ICONS, $icons );

		if ( ! $saved && get_option( BRICKS_DB_CUSTOM_ICONS, [] ) !== $icons ) {
			wp_delete_attachment( (int) $attachment['id'], true );
			return Error::internal_error( 'upload_custom_icon', 'Could not persist the custom icon registry.' );
		}

		return [
			'icon'       => $row,
			'attachment' => $attachment,
		];
	}

	public static function delete_custom_icon( $input ) {
		$id = (string) ( $input['iconId'] ?? '' );
		if ( $id === '' ) {
			return Error::invalid_param( 'iconId', 'non-empty string', $id );
		}

		$icons              = self::load_custom_icons();
		$kept               = [];
		$found              = false;
		$deleted_attachment = 0;

		foreach ( $icons as $icon ) {
			if ( (string) ( $icon['id'] ?? '' ) === $id ) {
				$found              = true;
				$deleted_attachment = (int) ( $icon['attachment_id'] ?? 0 );
				continue;
			}
			$kept[] = $icon;
		}

		if ( ! $found ) {
			return Error::not_found( 'custom_icon', $id );
		}

		if ( count( $kept ) ) {
			$saved = update_option( BRICKS_DB_CUSTOM_ICONS, $kept );

			if ( ! $saved && get_option( BRICKS_DB_CUSTOM_ICONS, [] ) !== $kept ) {
				return Error::internal_error( 'delete_custom_icon', 'Could not persist the custom icon registry.' );
			}
		} else {
			$deleted = delete_option( BRICKS_DB_CUSTOM_ICONS );

			if ( ! $deleted && ! empty( get_option( BRICKS_DB_CUSTOM_ICONS, [] ) ) ) {
				return Error::internal_error( 'delete_custom_icon', 'Could not remove the custom icon registry.' );
			}
		}

		// Force-delete the uploaded SVG so it does not leak (mirrors delete-custom-font).
		if ( $deleted_attachment && get_post( $deleted_attachment ) ) {
			wp_delete_attachment( $deleted_attachment, true );
		}

		return [ 'deletedId' => $id ];
	}

	/**
	 * Callback: create a custom icon set.
	 *
	 * Mirrors the admin "Icons" page's create-set flow. Generates a unique
	 * `set_<hex>` ID and appends a row to `BRICKS_DB_ICON_SETS`.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_custom_icon_set( $input ) {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( $name === '' ) {
			return Error::invalid_param( 'name', 'non-empty string', $name );
		}

		$background_color = isset( $input['backgroundColor'] ) ? (string) $input['backgroundColor'] : '';

		$sets = self::load_icon_sets();
		$id   = 'set_' . substr( md5( uniqid( '', true ) ), 0, 10 );

		$row = [
			'id'   => $id,
			'name' => $name,
		];

		if ( $background_color !== '' ) {
			$row['backgroundColor'] = $background_color;
		}

		$sets[] = $row;

		$saved = update_option( BRICKS_DB_ICON_SETS, $sets );

		if ( ! $saved && get_option( BRICKS_DB_ICON_SETS, [] ) !== $sets ) {
			return Error::internal_error( 'create_custom_icon_set', 'Could not persist the custom icon-set registry.' );
		}

		return [
			'id'  => $id,
			'set' => $row,
		];
	}

	/**
	 * Callback: delete a custom icon set.
	 *
	 * Removes the set row from `BRICKS_DB_ICON_SETS` and cascades to drop every
	 * custom icon row in `BRICKS_DB_CUSTOM_ICONS` that referenced the set. The
	 * underlying SVG attachments remain in the Media Library unless the caller
	 * explicitly requests cleanup.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_custom_icon_set( $input ) {
		$set_id             = (string) ( $input['setId'] ?? '' );
		$delete_attachments = ! empty( $input['deleteAttachments'] );

		if ( $set_id === '' ) {
			return Error::invalid_param( 'setId', 'non-empty string', $set_id );
		}

		$sets  = self::load_icon_sets();
		$kept  = [];
		$found = false;

		foreach ( $sets as $set ) {
			if ( (string) ( $set['id'] ?? '' ) === $set_id ) {
				$found = true;
				continue;
			}
			$kept[] = $set;
		}

		if ( ! $found ) {
			return Error::not_found( 'icon_set', $set_id );
		}

		if ( count( $kept ) ) {
			$saved = update_option( BRICKS_DB_ICON_SETS, $kept );

			if ( ! $saved && get_option( BRICKS_DB_ICON_SETS, [] ) !== $kept ) {
				return Error::internal_error( 'delete_custom_icon_set', 'Could not persist the custom icon-set registry.' );
			}
		} else {
			$deleted = delete_option( BRICKS_DB_ICON_SETS );

			if ( ! $deleted && ! empty( get_option( BRICKS_DB_ICON_SETS, [] ) ) ) {
				return Error::internal_error( 'delete_custom_icon_set', 'Could not remove the custom icon-set registry.' );
			}
		}

		// Cascade: drop custom icons in this set.
		$icons         = self::load_custom_icons();
		$icons_kept    = [];
		$icons_dropped = 0;

		foreach ( $icons as $icon ) {
			if ( (string) ( $icon['setId'] ?? '' ) === $set_id ) {
				$icons_dropped++;
				continue;
			}
			$icons_kept[] = $icon;
		}

		if ( $icons_dropped > 0 ) {
			if ( count( $icons_kept ) ) {
				$saved = update_option( BRICKS_DB_CUSTOM_ICONS, $icons_kept );

				if ( ! $saved && get_option( BRICKS_DB_CUSTOM_ICONS, [] ) !== $icons_kept ) {
					return Error::internal_error( 'delete_custom_icon_set', 'Could not persist the custom icon registry.' );
				}
			} else {
				$deleted = delete_option( BRICKS_DB_CUSTOM_ICONS );

				if ( ! $deleted && ! empty( get_option( BRICKS_DB_CUSTOM_ICONS, [] ) ) ) {
					return Error::internal_error( 'delete_custom_icon_set', 'Could not remove the custom icon registry.' );
				}
			}
		}

		$attachment_cleanup = $delete_attachments
			? \Bricks\Custom_Icon_Manager::delete_set_attachments( [ $set_id ], $icons, $icons_kept )
			: [
				'deletedAttachments' => [],
				'skippedAttachments' => [],
			];

		return [
			'deleted'            => true,
			'deletedSetId'       => $set_id,
			'deletedIcons'       => $icons_dropped,
			'deletedAttachments' => $attachment_cleanup['deletedAttachments'],
			'skippedAttachments' => $attachment_cleanup['skippedAttachments'],
		];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Load custom icon sets.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function load_icon_sets(): array {
		$sets = get_option( BRICKS_DB_ICON_SETS, [] );
		return is_array( $sets ) ? array_values( $sets ) : [];
	}

	private static function load_disabled_sets(): array {
		$sets = get_option( BRICKS_DB_DISABLED_ICON_SETS, [] );
		return is_array( $sets ) ? array_values( array_map( 'strval', $sets ) ) : [];
	}

	private static function load_custom_icons(): array {
		$icons = get_option( BRICKS_DB_CUSTOM_ICONS, [] );
		return is_array( $icons ) ? array_values( $icons ) : [];
	}

	private static function icon_set_exists( string $id ): bool {
		foreach ( self::load_icon_sets() as $set ) {
			if ( (string) ( $set['id'] ?? '' ) === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Run SVG through the Bricks-bundled sanitizer.
	 *
	 * Returns the cleaned SVG or null if the sanitizer rejected the input.
	 */
	private static function sanitize_svg( string $svg ): ?string {
		$clean = \Bricks\Svg::sanitize_markup( $svg );
		return $clean === false ? null : $clean;
	}

	/**
	 * Store sanitized SVG markup as a WordPress attachment.
	 *
	 * @since 2.4
	 *
	 * @param string $svg  Sanitized SVG markup.
	 * @param string $name Attachment title.
	 * @return array|\WP_Error
	 */
	private static function create_svg_attachment( string $svg, string $name ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( $name );
		if ( $filename === '' ) {
			$filename = 'bricks-custom-icon';
		}
		if ( substr( strtolower( $filename ), -4 ) !== '.svg' ) {
			$filename .= '.svg';
		}

		$upload = wp_upload_bits( $filename, null, $svg );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return Error::conflict(
				'upload_failed',
				[
					'message' => $upload['error'] ?? 'wp_upload_bits failed',
				]
			);
		}

		if ( ! \Bricks\Svg::file_matches_contents( $upload['file'], $svg ) ) {
			self::delete_uploaded_file( $upload['file'] );
			return Error::conflict( 'upload_failed', [ 'message' => __( 'File not uploaded due to a sanitization error. Please verify the SVG file or get in touch.', 'bricks' ) ] );
		}

		$attachment = [
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_mime_type' => 'image/svg+xml',
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		];

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			self::delete_uploaded_file( $upload['file'] );
			return $attachment_id;
		}

		if ( ! $attachment_id ) {
			self::delete_uploaded_file( $upload['file'] );
			return Error::internal_error( 'upload_custom_icon', 'WordPress failed to create an SVG attachment.' );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return [
			'id'       => (int) $attachment_id,
			'url'      => $upload['url'],
			'mimeType' => 'image/svg+xml',
		];
	}

	/**
	 * Return the maximum accepted raw SVG payload size.
	 *
	 * @return int
	 */
	private static function max_svg_bytes(): int {
		return max( 1, (int) apply_filters( 'bricks/abilities/icons/max_svg_bytes', self::MAX_SVG_BYTES ) );
	}

	/**
	 * Delete a failed temporary upload using WordPress' filesystem hook.
	 *
	 * @param string $path Uploaded file path.
	 * @return void
	 */
	private static function delete_uploaded_file( string $path ): void {
		if ( $path !== '' && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}

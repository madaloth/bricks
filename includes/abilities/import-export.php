<?php
/**
 * Import/Export abilities
 *
 * Unified transfer packages for MCP parity with the Bricks import/export UI.
 * The ability layer owns transport validation and confirmation flags; the
 * shared transfer engine owns selector data, manifests, conflict detection,
 * permissions, and writes.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Import_Export {
	const BUNDLE_VERSION = '2.x';

	const MAX_TEMPLATE_ZIP_BYTES = 16777216; // 16 MB.

	const MAX_TEMPLATE_ZIP_FILES = 50;

	const MAX_TEMPLATE_JSON_BYTES = 2097152; // 2 MB.

	/**
	 * Keys included in the legacy global-data bundle, mapped to option names.
	 */
	const BUNDLE_KEYS = [
		'globalClasses'       => 'BRICKS_DB_GLOBAL_CLASSES',
		'globalClassesCats'   => 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES',
		'colorPalette'        => 'BRICKS_DB_COLOR_PALETTE',
		'components'          => 'BRICKS_DB_COMPONENTS',
		'breakpoints'         => 'BRICKS_DB_BREAKPOINTS',
		'globalVariables'     => 'BRICKS_DB_GLOBAL_VARIABLES',
		'globalVariablesCats' => 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES',
		'themeStyles'         => 'BRICKS_DB_THEME_STYLES',
		'pseudoClasses'       => 'BRICKS_DB_PSEUDO_CLASSES',
	];

	// ==================================================================
	// Unified transfer schemas
	// ==================================================================

	/**
	 * Input schema for list-transfer-items.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_transfer_items_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'includeTypes' => [
					'type'        => 'array',
					'description' => __( 'Optional transfer type IDs to include. Omit to list every exportable type for the current user.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	public static function list_transfer_items_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'types' => [
					'type'        => 'array',
					'description' => __( 'Exportable transfer types and item IDs. Pass these item IDs to `bricks/export-transfer-package`.', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}

	public static function export_transfer_package_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'types', 'items' ],
			'properties' => [
				'types'                  => [
					'type'        => 'array',
					'description' => __( 'Transfer type IDs to export, for example `classes`, `components`, `templates`, or `settings`. Use `bricks/list-transfer-items` first.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
					'minItems'    => 1,
				],
				'items'                  => [
					'type'        => 'object',
					'description' => __( 'Object keyed by transfer type. Each value must be the exact item IDs selected for that type. Use `["all"]` only for singleton types such as `breakpoints`; otherwise pass IDs from `bricks/list-transfer-items`.', 'bricks' ),
				],
				'allowSensitiveSettings' => [
					'type'        => 'boolean',
					'description' => __( 'Required when exporting sensitive settings tabs such as `api-keys` or `custom-code`.', 'bricks' ),
				],
			],
		];
	}

	public static function export_transfer_package_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'filename'  => [ 'type' => 'string' ],
				'zipBase64' => [
					'type'        => 'string',
					'description' => __( 'Base64-encoded ZIP package with `manifest.json` and selected transfer files.', 'bricks' ),
				],
				'zipHash'   => [
					'type'        => 'string',
					'description' => __( 'SHA-256 hash of the decoded ZIP bytes. Pass this as `expectedZipHash` before importing.', 'bricks' ),
				],
				'zipBytes'  => [ 'type' => 'integer' ],
				'manifest'  => [
					'type'        => 'object',
					'description' => __( 'Transfer manifest for the exported package.', 'bricks' ),
				],
			],
		];
	}

	public static function inspect_transfer_package_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'zipBase64' ],
			'properties' => [
				'zipBase64' => [
					'type'        => 'string',
					'description' => __( 'Base64-encoded unified transfer ZIP exported by Bricks.', 'bricks' ),
				],
			],
		];
	}

	public static function inspect_transfer_package_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'manifest'    => [
					'type'        => 'object',
					'description' => __( 'Manifest filtered by the current user permissions and annotated with conflicts and warnings. The warnings array contains code, type, and message for permission restrictions, including icon sets excluded because SVG uploads are not allowed.', 'bricks' ),
				],
				'zipHash'     => [ 'type' => 'string' ],
				'zipBytes'    => [ 'type' => 'integer' ],
				'maxZipBytes' => [ 'type' => 'integer' ],
			],
		];
	}

	public static function import_transfer_package_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'zipBase64', 'expectedZipHash', 'types', 'items' ],
			'properties' => [
				'zipBase64'              => [
					'type'        => 'string',
					'description' => __( 'Base64-encoded unified transfer ZIP. Inspect it first with `bricks/inspect-transfer-package`.', 'bricks' ),
				],
				'expectedZipHash'        => [
					'type'        => 'string',
					'description' => __( 'SHA-256 hash returned by `bricks/inspect-transfer-package` for the exact ZIP being imported.', 'bricks' ),
				],
				'types'                  => [
					'type'        => 'array',
					'description' => __( 'Transfer type IDs to import from the inspected manifest.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
					'minItems'    => 1,
				],
				'items'                  => [
					'type'        => 'object',
					'description' => __( 'Object keyed by transfer type. Each value must be the exact manifest item IDs to import.', 'bricks' ),
				],
				'conflictMode'           => [
					'type'        => 'string',
					'enum'        => [ 'skip', 'replace' ],
					'description' => __( 'Default conflict behavior. Defaults to `skip`. `replace` requires `allowOverwrite: true`.', 'bricks' ),
				],
				'conflictDecisions'      => [
					'type'        => 'object',
					'description' => __( 'Optional per-type object of item ID to `skip` or `replace`. Any `replace` value requires `allowOverwrite: true`.', 'bricks' ),
				],
				'allowOverwrite'         => [
					'type'        => 'boolean',
					'description' => __( 'Required when `conflictMode` or any `conflictDecisions` value is `replace`.', 'bricks' ),
				],
				'allowSensitiveSettings' => [
					'type'        => 'boolean',
					'description' => __( 'Required when importing sensitive settings tabs such as `api-keys` or `custom-code`.', 'bricks' ),
				],
				'importImages'           => [
					'type'        => 'boolean',
					'description' => __( 'When importing templates, download supported remote images into the media library. Requires `upload_files`.', 'bricks' ),
				],
				'includeRefresh'         => [
					'type'        => 'boolean',
					'description' => __( 'Include the builder refresh payload in the response. Defaults to false to keep MCP responses compact.', 'bricks' ),
				],
			],
		];
	}

	public static function import_transfer_package_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'results' => [
					'type'        => 'object',
					'description' => __( 'Per-type import counts and item statuses.', 'bricks' ),
				],
				'refresh' => [
					'type'        => 'object',
					'description' => __( 'Optional builder refresh payload when `includeRefresh` is true.', 'bricks' ),
				],
				'zipHash' => [ 'type' => 'string' ],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Unified transfer permission.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function transfer_permission( $input ) {
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		return Error::forbidden_builder_permission( 'read' );
	}

	// ==================================================================
	// Unified transfer execute
	// ==================================================================

	/**
	 * Callback: list exportable unified transfer items.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_transfer_items( $input ) {
		$types = [];

		if ( ! empty( $input['includeTypes'] ) ) {
			$types = self::normalize_transfer_types_input( $input['includeTypes'] );

			if ( is_wp_error( $types ) ) {
				return $types;
			}
		}

		return \Bricks\Unified_Global_Transfer::list_export_items( $types );
	}

	/**
	 * Callback: export a unified transfer package.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function export_transfer_package( $input ) {
		$types_check = self::normalize_transfer_types_input( $input['types'] ?? null );

		if ( is_wp_error( $types_check ) ) {
			return $types_check;
		}

		$types = $types_check;
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];

		$selection_check = self::validate_explicit_item_selection( $types, $items );

		if ( is_wp_error( $selection_check ) ) {
			return $selection_check;
		}

		if ( self::selection_includes_sensitive_settings( $types, $items ) && empty( $input['allowSensitiveSettings'] ) ) {
			return Error::missing_param( 'allowSensitiveSettings' );
		}

		return \Bricks\Unified_Global_Transfer::export_package( $types, $items );
	}

	/**
	 * Callback: inspect a unified transfer package.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function inspect_transfer_package( $input ) {
		$bytes = self::decode_transfer_zip_base64( $input['zipBase64'] ?? '' );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$result = \Bricks\Unified_Global_Transfer::inspect_package_bytes( $bytes );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['maxZipBytes'] = \Bricks\Unified_Global_Transfer::get_mcp_transfer_max_zip_bytes();

		return $result;
	}

	/**
	 * Callback: import a unified transfer package.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function import_transfer_package( $input ) {
		$bytes = self::decode_transfer_zip_base64( $input['zipBase64'] ?? '' );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$zip_hash      = hash( 'sha256', $bytes );
		$expected_hash = isset( $input['expectedZipHash'] ) ? strtolower( trim( (string) $input['expectedZipHash'] ) ) : '';

		if ( $expected_hash === '' ) {
			return Error::missing_param( 'expectedZipHash' );
		}

		if ( ! hash_equals( $expected_hash, $zip_hash ) ) {
			return Error::conflict(
				'transfer_zip_hash_mismatch',
				[
					'message'         => 'The ZIP hash does not match the package that was inspected. Re-run `bricks/inspect-transfer-package` and pass the returned `zipHash`.',
					'expectedZipHash' => $expected_hash,
					'actualZipHash'   => $zip_hash,
				]
			);
		}

		$types_check = self::normalize_transfer_types_input( $input['types'] ?? null );

		if ( is_wp_error( $types_check ) ) {
			return $types_check;
		}

		$types = $types_check;
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? $input['items'] : [];

		$selection_check = self::validate_explicit_item_selection( $types, $items );

		if ( is_wp_error( $selection_check ) ) {
			return $selection_check;
		}

		$conflict_mode      = isset( $input['conflictMode'] ) ? (string) $input['conflictMode'] : 'skip';
		$conflict_decisions = isset( $input['conflictDecisions'] ) && is_array( $input['conflictDecisions'] ) ? $input['conflictDecisions'] : [];

		if ( self::selection_requests_overwrite( $conflict_mode, $conflict_decisions ) && empty( $input['allowOverwrite'] ) ) {
			return Error::missing_param( 'allowOverwrite' );
		}

		if ( self::selection_includes_sensitive_settings( $types, $items ) && empty( $input['allowSensitiveSettings'] ) ) {
			return Error::missing_param( 'allowSensitiveSettings' );
		}

		if ( self::selection_includes_custom_code_settings( $types, $items ) ) {
			return Error::code_sensitive_write_forbidden();
		}

		$result = \Bricks\Unified_Global_Transfer::import_package_bytes(
			$bytes,
			$types,
			$items,
			$conflict_mode,
			$conflict_decisions,
			! empty( $input['importImages'] ),
			! empty( $input['includeRefresh'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge(
			[
				'success' => true,
				'zipHash' => $zip_hash,
			],
			$result
		);
	}

	// ==================================================================
	// Legacy execute callbacks (unregistered)
	// ==================================================================

	/**
	 * Callback: export global Bricks data.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function export_global_data( $input ) {
		Manager::flush_options_cache();

		$allowed_keys = array_keys( self::BUNDLE_KEYS );
		$include      = isset( $input['include'] ) && is_array( $input['include'] ) ? array_map( 'strval', $input['include'] ) : [];
		$skipped      = [];

		if ( ! empty( $include ) ) {
			$unknown = array_values( array_diff( $include, $allowed_keys ) );

			if ( ! empty( $unknown ) ) {
				// Non-destructive: report the unknown entries on the response instead
				// of failing the whole call. Partial exports are still useful.
				$skipped = $unknown;
			}

			$keys = array_values( array_intersect( $allowed_keys, $include ) );
		} else {
			$keys = $allowed_keys;
		}

		$bundle = [
			'version'    => self::BUNDLE_VERSION,
			'exportedAt' => gmdate( 'c' ),
			'site'       => home_url(),
		];
		$counts = [];

		foreach ( $keys as $key ) {
			$constant = self::BUNDLE_KEYS[ $key ] ?? null;
			if ( ! $constant || ! defined( $constant ) ) {
				continue;
			}

			$value = get_option( constant( $constant ), [] );

			if ( $key === 'components' && is_array( $value ) ) {
				$value = self::redact_component_exports( $value );
			}

			$bundle[ $key ] = is_array( $value ) ? array_values( $value ) : $value;
			$counts[ $key ] = is_array( $value ) ? count( $value ) : 0;
		}

		return [
			'bundle'              => $bundle,
			'counts'              => $counts,
			'skipped'             => $skipped,
			'designSystemVersion' => Design::design_system_version(),
		];
	}

	public static function import_global_data( $input ) {
		Manager::flush_options_cache();

		$bundle = $input['bundle'] ?? null;
		if ( ! is_array( $bundle ) ) {
			return Error::invalid_param( 'bundle', 'object', $bundle );
		}

		$version = isset( $bundle['version'] ) ? (string) $bundle['version'] : '';
		if ( $version !== '' && ! self::is_supported_bundle_version( $version ) ) {
			return Error::invalid_param( 'bundle.version', '2.x or a Bricks 2.x version string', $version );
		}

		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'merge';
		if ( ! in_array( $mode, [ 'replace', 'merge' ], true ) ) {
			return Error::invalid_param( 'mode', 'replace|merge', $mode );
		}

		$on_duplicate = isset( $input['onDuplicate'] ) ? (string) $input['onDuplicate'] : 'skip';
		if ( ! in_array( $on_duplicate, [ 'skip', 'replace' ], true ) ) {
			return Error::invalid_param( 'onDuplicate', 'skip|replace', $on_duplicate );
		}

		$dry_run = ! empty( $input['dryRun'] );

		if ( ! $dry_run || array_key_exists( 'expectedDesignSystemVersion', $input ) ) {
			$version_check = self::validate_design_system_version_precondition( $input );

			if ( is_wp_error( $version_check ) ) {
				return $version_check;
			}
		}

		$candidates = [];
		$skipped    = [];

		foreach ( $bundle as $key => $value ) {
			if ( in_array( $key, [ 'version', 'exportedAt', 'site' ], true ) ) {
				continue;
			}

			$constant = self::BUNDLE_KEYS[ $key ] ?? null;
			if ( ! $constant || ! defined( $constant ) ) {
				$skipped[] = [
					'key'    => (string) $key,
					'reason' => 'unknown_key',
				];
				continue;
			}

			if ( ! is_array( $value ) ) {
				return Error::invalid_param( "bundle.{$key}", 'array', $value );
			}

			$shape_check = self::validate_global_data_bundle_key( (string) $key, $value );

			if ( is_wp_error( $shape_check ) ) {
				return $shape_check;
			}

			if ( $key === 'components' ) {
				$code_check = self::check_components_code_permissions( $value );

				if ( is_wp_error( $code_check ) ) {
					return $code_check;
				}
			}

			if ( $key === 'components' ) {
				foreach ( $value as &$component ) {
					$component['elements'] = Elements::sign_authorized_code( [], $component['elements'] ?? [] );
					if ( isset( $component['properties'] ) ) {
						$component['properties'] = Elements::prepare_component_code_defaults( [], $component['properties'] );
						if ( is_wp_error( $component['properties'] ) ) {
							return $component['properties'];
						}
					}
				}
				unset( $component );
			}
			$candidates[ $key ] = $value;
		}

		if ( empty( $candidates ) ) {
			return Error::invalid_param( 'bundle', 'at least one recognized global-data key: ' . implode( ', ', array_keys( self::BUNDLE_KEYS ) ), array_keys( $bundle ) );
		}

		if ( $mode === 'replace' ) {
			$replace_check = self::validate_replace_key_precondition( $input, array_keys( $candidates ) );

			if ( is_wp_error( $replace_check ) ) {
				return $replace_check;
			}
		}

		$planned_values = [];
		$plan           = [];
		$counts         = [];

		foreach ( $candidates as $key => $value ) {
			$constant    = self::BUNDLE_KEYS[ $key ];
			$option_name = constant( $constant );
			$existing    = get_option( $option_name, [] );
			$existing    = is_array( $existing ) ? $existing : [];

			if ( $mode === 'merge' ) {
				$merge                  = self::merge_rows( $key, $existing, $value, $on_duplicate );
				$planned_values[ $key ] = $merge['rows'];
				$stats                  = $merge['stats'];
			} else {
				$planned_values[ $key ] = array_values( $value );
				$stats                  = self::replace_stats( $key, $existing, $planned_values[ $key ] );
			}

			$counts[ $key ] = count( $planned_values[ $key ] );
			$plan[]         = array_merge(
				[
					'key'    => $key,
					'action' => $mode,
				],
				$stats
			);
		}

		if ( $mode === 'replace' && ! $dry_run ) {
			$count_check = self::validate_expected_counts_precondition( $input, $plan );

			if ( is_wp_error( $count_check ) ) {
				return $count_check;
			}
		}

		$imported = [];

		if ( ! $dry_run ) {
			foreach ( $planned_values as $key => $rows ) {
				$constant    = self::BUNDLE_KEYS[ $key ];
				$option_name = constant( $constant );

				$persisted = self::persist_option_value( $option_name, $rows, 'import_global_data' );

				if ( is_wp_error( $persisted ) ) {
					return $persisted;
				}

				$imported[] = $key;
			}
		}

		return [
			'success'             => true,
			'imported'            => $imported,
			'skipped'             => $skipped,
			'counts'              => $counts,
			'plan'                => $plan,
			'dryRun'              => $dry_run,
			'mode'                => $mode,
			'designSystemVersion' => Design::design_system_version(),
		];
	}

	public static function export_templates( $input ) {
		$ids = $input['templateIds'] ?? null;
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return Error::invalid_param( 'templateIds', 'non-empty array of integers', $ids );
		}

		if ( ! class_exists( '\\ZipArchive' ) ) {
			return Error::internal_error( 'export_templates', 'ZipArchive PHP extension is not available on this host.' );
		}

		if ( ! class_exists( '\\Bricks\\Templates' ) ) {
			return Error::internal_error( 'export_templates', 'Bricks Templates class is unavailable.' );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return Error::internal_error( 'export_templates', 'wp_upload_dir error: ' . $upload_dir['error'] );
		}

		$temp_base = trailingslashit( $upload_dir['basedir'] ) . ( defined( 'BRICKS_TEMP_DIR' ) ? BRICKS_TEMP_DIR : 'bricks-temp' );
		if ( ! wp_mkdir_p( $temp_base ) ) {
			return Error::internal_error( 'export_templates', 'Could not create the Bricks temporary export directory.' );
		}

		$temp_path = trailingslashit( $temp_base ) . 'export-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $temp_path ) ) {
			return Error::internal_error( 'export_templates', 'Could not create a request-scoped export directory.' );
		}

		$files = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== ( defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template' ) ) {
				self::rmdir_recursive( $temp_path );
				return Error::not_found( 'template', (string) $id );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				self::rmdir_recursive( $temp_path );
				return Error::forbidden_builder_permission( "edit_post($id)" );
			}

			$data = \Bricks\Templates::export_template( $id );
			if ( ! is_array( $data ) || empty( $data['name'] ) || ! isset( $data['content'] ) ) {
				continue;
			}
			$data = self::redact_template_export( $data );

			if ( strlen( (string) $data['content'] ) > self::max_template_json_bytes() ) {
				self::rmdir_recursive( $temp_path );

				return Error::invalid_param( 'templateIds', 'templates whose JSON export is within the maximum allowed size', [ 'templateId' => $id ] );
			}

			$file_name = wp_basename( (string) $data['name'] );
			$file_path = trailingslashit( $temp_path ) . $file_name;
			$written   = file_put_contents( $file_path, $data['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Writes exported template JSON to a local temp file for ZipArchive.

			if ( $written === false ) {
				self::rmdir_recursive( $temp_path );

				return Error::internal_error( 'export_templates', 'Could not write template export file.' );
			}

			$files[] = [
				'path' => $file_path,
				'name' => $file_name,
			];
		}

		if ( empty( $files ) ) {
			self::rmdir_recursive( $temp_path );

			return Error::internal_error( 'export_templates', 'No templates could be exported.' );
		}

		if ( count( $files ) > self::max_template_zip_files() ) {
			self::rmdir_recursive( $temp_path );

			return Error::invalid_param( 'templateIds', sprintf( 'at most %d exportable templates', self::max_template_zip_files() ), count( $files ) );
		}

		$zip_filename = 'templates-' . gmdate( 'Y-m-d-His' ) . '.zip';
		$zip_path     = trailingslashit( $temp_path ) . $zip_filename;
		$zip          = new \ZipArchive();
		if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			self::rmdir_recursive( $temp_path );

			return Error::internal_error( 'export_templates', 'Could not create ZIP archive at ' . $zip_path );
		}

		foreach ( $files as $f ) {
			$zip->addFile( $f['path'], $f['name'] );
		}
		$zip->close();

		$zip_bytes = file_get_contents( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local temp ZIP generated by this method.

		if ( ! is_string( $zip_bytes ) ) {
			self::rmdir_recursive( $temp_path );

			return Error::internal_error( 'export_templates', 'Could not read generated ZIP archive.' );
		}

		if ( strlen( $zip_bytes ) > self::max_template_zip_bytes() ) {
			self::rmdir_recursive( $temp_path );

			return Error::invalid_param( 'templateIds', sprintf( 'export ZIP <= %d bytes', self::max_template_zip_bytes() ), [ 'bytes' => strlen( $zip_bytes ) ] );
		}

		self::rmdir_recursive( $temp_path );

		return [
			'filename'      => $zip_filename,
			'zipBase64'     => base64_encode( $zip_bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a ZIP download payload for MCP transport.
			'templateCount' => count( $files ),
		];
	}

	public static function import_template_bundle( $input ) {
		$b64 = isset( $input['zipBase64'] ) ? (string) $input['zipBase64'] : '';
		if ( $b64 === '' ) {
			return Error::invalid_param( 'zipBase64', 'non-empty base64 string', $b64 );
		}

		$dry_run = array_key_exists( 'dryRun', $input ) ? rest_sanitize_boolean( $input['dryRun'] ) : false;

		$max_zip_bytes     = self::max_template_zip_bytes();
		$normalized_base64 = preg_replace( '/\s+/', '', $b64 );

		if ( ! is_string( $normalized_base64 ) ) {
			return Error::invalid_param( 'zipBase64', 'valid base64-encoded ZIP', '(normalization failed)' );
		}

		$max_base64_chars = (int) ceil( $max_zip_bytes / 3 ) * 4 + 4;
		if ( strlen( $normalized_base64 ) > $max_base64_chars ) {
			return Error::invalid_param(
				'zipBase64',
				sprintf( 'ZIP archive no larger than %d bytes', $max_zip_bytes ),
				[
					'encodedChars' => strlen( $normalized_base64 ),
					'maxBytes'     => $max_zip_bytes,
				]
			);
		}

		$bytes = base64_decode( $normalized_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes caller-provided template ZIP content.
		if ( $bytes === false || $bytes === '' ) {
			return Error::invalid_param( 'zipBase64', 'valid base64-encoded ZIP', '(decode failed)' );
		}

		if ( strlen( $bytes ) > $max_zip_bytes ) {
			return Error::invalid_param(
				'zipBase64',
				sprintf( 'ZIP archive no larger than %d bytes', $max_zip_bytes ),
				[
					'receivedBytes' => strlen( $bytes ),
					'maxBytes'      => $max_zip_bytes,
				]
			);
		}

		if ( ! class_exists( '\\ZipArchive' ) ) {
			return Error::internal_error( 'import_template_bundle', 'ZipArchive PHP extension is not available on this host.' );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return Error::internal_error( 'import_template_bundle', 'wp_upload_dir error: ' . $upload_dir['error'] );
		}
		$temp_base = trailingslashit( $upload_dir['basedir'] ) . ( defined( 'BRICKS_TEMP_DIR' ) ? BRICKS_TEMP_DIR : 'bricks-temp' );
		wp_mkdir_p( $temp_base );

		$temp_path = trailingslashit( $temp_base ) . 'import-' . wp_generate_password( 8, false );
		wp_mkdir_p( $temp_path );

		$zip_path = trailingslashit( $temp_path ) . 'bundle.zip';
		$written  = file_put_contents( $zip_path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Writes caller-provided ZIP bytes to a local temp file for ZipArchive validation.

		if ( $written === false ) {
			self::rmdir_recursive( $temp_path );

			return Error::internal_error( 'import_template_bundle', 'Could not write ZIP archive to a temp file.' );
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			self::rmdir_recursive( $temp_path );
			return Error::invalid_param( 'zipBase64', 'ZIP archive', '(open failed)' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native property as numFiles.
		$file_count    = (int) $zip->numFiles;
		$max_zip_files = self::max_template_zip_files();

		if ( $file_count > $max_zip_files ) {
			$zip->close();
			self::rmdir_recursive( $temp_path );

			return Error::invalid_param(
				'zipBase64',
				sprintf( 'ZIP archive containing %d files or fewer', $max_zip_files ),
				[
					'fileCount' => $file_count,
					'maxFiles'  => $max_zip_files,
				]
			);
		}

		$imported      = [];
		$would_import  = [];
		$skipped_files = [];
		$extracted     = [];
		$max_json_size = self::max_template_json_bytes();

		for ( $i = 0; $i < $file_count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}
			$name = $stat['name'];
			if ( strpos( $name, '..' ) !== false || strpos( $name, '/' ) !== false || strpos( $name, '\\' ) !== false ) {
				$skipped_files[] = $name;
				continue;
			}
			if ( ! preg_match( '/\.json$/i', $name ) ) {
				$skipped_files[] = $name;
				continue;
			}

			if ( isset( $stat['size'] ) && (int) $stat['size'] > $max_json_size ) {
				$skipped_files[] = $name;
				continue;
			}

			if ( ! $zip->extractTo( $temp_path, $name ) ) {
				$skipped_files[] = $name;
				continue;
			}

			$extracted[] = trailingslashit( $temp_path ) . $name;
		}
		$zip->close();

		foreach ( $extracted as $path ) {
			$file_size = file_exists( $path ) ? filesize( $path ) : false;

			if ( $file_size === false || $file_size > $max_json_size ) {
				wp_delete_file( $path );
				$skipped_files[] = basename( $path );
				continue;
			}

			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local template JSON file extracted from the validated ZIP.
			if ( ! is_string( $contents ) ) {
				wp_delete_file( $path );
				$skipped_files[] = basename( $path );
				continue;
			}

			$data = json_decode( $contents, true );
			wp_delete_file( $path );

			if ( ! is_array( $data ) || empty( $data['content'] ) && empty( $data['header'] ) && empty( $data['footer'] ) ) {
				$skipped_files[] = basename( $path );
				continue;
			}

			if ( is_array( $data['components'] ?? null ) ) {
				$data['components'] = \Bricks\Components::upgrade_components( $data['components'], true );
			}

			$code_check = self::check_template_data_code_permissions( $data );

			if ( is_wp_error( $code_check ) ) {
				wp_delete_file( $zip_path );
				self::rmdir_recursive( $temp_path );
				return $code_check;
			}

			if ( $dry_run ) {
				$would_import[] = [
					'title'        => ! empty( $data['title'] ) ? (string) $data['title'] : __( '(no title)', 'bricks' ),
					'templateType' => isset( $data['templateType'] ) ? (string) $data['templateType'] : 'content',
				];
				continue;
			}

			foreach ( [ 'content', 'header', 'footer' ] as $code_area ) {
				if ( is_array( $data[ $code_area ] ?? null ) ) {
					$data[ $code_area ] = Elements::sign_authorized_code( [], $data[ $code_area ] );
				}
			}
			foreach ( $data['components'] ?? [] as $component_index => $component ) {
				$data['components'][ $component_index ]['elements'] = Elements::sign_authorized_code( [], $component['elements'] ?? [] );
				if ( isset( $component['properties'] ) ) {
					$defaults = Elements::prepare_component_code_defaults( [], $component['properties'] );
					if ( is_wp_error( $defaults ) ) {
						return $defaults;
					}
					$data['components'][ $component_index ]['properties'] = $defaults;
				}
			}
			$result = self::insert_template( $data );
			if ( is_wp_error( $result ) ) {
				$skipped_files[] = basename( $path );
				continue;
			}
			$imported[] = $result;
		}

		wp_delete_file( $zip_path );
		self::rmdir_recursive( $temp_path );

		return [
			'success'           => true,
			'importedTemplates' => $imported,
			'wouldImport'       => $would_import,
			'skippedFiles'      => $skipped_files,
			'dryRun'            => $dry_run,
		];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Normalize transfer type IDs from ability input.
	 *
	 * @since 2.4
	 *
	 * @param mixed $types Raw type list.
	 * @return string[]|\WP_Error
	 */
	private static function normalize_transfer_types_input( $types ) {
		if ( ! is_array( $types ) || empty( $types ) ) {
			return Error::missing_param( 'types' );
		}

		$allowed = array_flip( \Bricks\Unified_Global_Transfer::get_transfer_type_ids() );
		$clean   = [];

		foreach ( array_values( $types ) as $index => $type ) {
			$type = sanitize_text_field( (string) $type );

			if ( ! isset( $allowed[ $type ] ) ) {
				return Error::invalid_param( "types[{$index}]", 'one of: ' . implode( ', ', array_keys( $allowed ) ), $type );
			}

			$clean[] = $type;
		}

		$clean = array_values( array_unique( $clean ) );

		return ! empty( $clean ) ? $clean : Error::missing_param( 'types' );
	}

	/**
	 * Require explicit item IDs for each selected transfer type.
	 *
	 * @since 2.4
	 *
	 * @param string[] $types Transfer type IDs.
	 * @param mixed    $items Raw item map.
	 * @return true|\WP_Error
	 */
	private static function validate_explicit_item_selection( array $types, $items ) {
		if ( ! is_array( $items ) ) {
			return Error::invalid_param( 'items', 'an object keyed by transfer type with selected item ID arrays', $items );
		}

		foreach ( $types as $type ) {
			if ( ! array_key_exists( $type, $items ) ) {
				return Error::missing_param( "items.{$type}" );
			}

			if ( ! is_array( $items[ $type ] ) || empty( $items[ $type ] ) ) {
				return Error::invalid_param( "items.{$type}", 'a non-empty array of selected item IDs', $items[ $type ] );
			}

			foreach ( array_values( $items[ $type ] ) as $index => $item_id ) {
				if ( trim( (string) $item_id ) === '' ) {
					return Error::invalid_param( "items.{$type}[{$index}]", 'a non-empty item ID string', $item_id );
				}
			}
		}

		return true;
	}

	/**
	 * Whether selected settings include sensitive tabs.
	 *
	 * @since 2.4
	 *
	 * @param string[] $types Transfer type IDs.
	 * @param array    $items Item map.
	 * @return bool
	 */
	private static function selection_includes_sensitive_settings( array $types, array $items ): bool {
		if ( ! in_array( 'settings', $types, true ) ) {
			return false;
		}

		$settings_ids = array_map( 'strval', is_array( $items['settings'] ?? null ) ? $items['settings'] : [] );

		return (bool) array_intersect( $settings_ids, [ 'settings', 'all', 'api-keys', 'custom-code' ] );
	}

	/**
	 * Whether selected settings can alter executable global code configuration.
	 *
	 * The shared transfer UI supports this tab, but abilities must never use it
	 * to enable code execution or author global scripts.
	 *
	 * @since 2.4
	 *
	 * @param string[] $types Transfer type IDs.
	 * @param array    $items Item map.
	 * @return bool
	 */
	private static function selection_includes_custom_code_settings( array $types, array $items ): bool {
		if ( ! in_array( 'settings', $types, true ) ) {
			return false;
		}

		$settings_ids = array_map( 'strval', is_array( $items['settings'] ?? null ) ? $items['settings'] : [] );

		return (bool) array_intersect( $settings_ids, [ 'settings', 'all', 'custom-code' ] );
	}

	/**
	 * Whether the import input requests conflict replacement.
	 *
	 * @since 2.4
	 *
	 * @param string $conflict_mode      Default conflict mode.
	 * @param array  $conflict_decisions Per-item conflict decisions.
	 * @return bool
	 */
	private static function selection_requests_overwrite( string $conflict_mode, array $conflict_decisions ): bool {
		if ( $conflict_mode === 'replace' ) {
			return true;
		}

		foreach ( $conflict_decisions as $type_decisions ) {
			if ( ! is_array( $type_decisions ) ) {
				continue;
			}

			foreach ( $type_decisions as $decision ) {
				if ( (string) $decision === 'replace' ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Decode and size-check a base64 transfer ZIP payload.
	 *
	 * @since 2.4
	 *
	 * @param mixed $zip_base64 Base64 ZIP input.
	 * @return string|\WP_Error
	 */
	private static function decode_transfer_zip_base64( $zip_base64 ) {
		$zip_base64 = isset( $zip_base64 ) ? (string) $zip_base64 : '';

		if ( $zip_base64 === '' ) {
			return Error::missing_param( 'zipBase64' );
		}

		$max_zip_bytes     = \Bricks\Unified_Global_Transfer::get_mcp_transfer_max_zip_bytes();
		$normalized_base64 = preg_replace( '/\s+/', '', $zip_base64 );

		if ( ! is_string( $normalized_base64 ) || $normalized_base64 === '' ) {
			return Error::invalid_param( 'zipBase64', 'valid base64-encoded ZIP', '(normalization failed)' );
		}

		$max_base64_chars = (int) ceil( $max_zip_bytes / 3 ) * 4 + 4;

		if ( strlen( $normalized_base64 ) > $max_base64_chars ) {
			return Error::invalid_param(
				'zipBase64',
				sprintf( 'ZIP archive no larger than %d bytes', $max_zip_bytes ),
				[
					'encodedChars' => strlen( $normalized_base64 ),
					'maxBytes'     => $max_zip_bytes,
				]
			);
		}

		$bytes = base64_decode( $normalized_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes caller-provided transfer ZIP content.

		if ( $bytes === false || $bytes === '' ) {
			return Error::invalid_param( 'zipBase64', 'valid base64-encoded ZIP', '(decode failed)' );
		}

		if ( strlen( $bytes ) > $max_zip_bytes ) {
			return Error::invalid_param(
				'zipBase64',
				sprintf( 'ZIP archive no larger than %d bytes', $max_zip_bytes ),
				[
					'receivedBytes' => strlen( $bytes ),
					'maxBytes'      => $max_zip_bytes,
				]
			);
		}

		return $bytes;
	}

	/**
	 * Validate optimistic concurrency for global-data imports.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	private static function validate_design_system_version_precondition( array $input ) {
		if ( ! array_key_exists( 'expectedDesignSystemVersion', $input ) ) {
			return Error::missing_param( 'expectedDesignSystemVersion' );
		}

		$expected = (int) $input['expectedDesignSystemVersion'];
		$current  = Design::design_system_version();

		if ( $expected !== $current ) {
			return Error::conflict(
				'design_system_version_mismatch',
				[
					'message'                     => sprintf( 'Global data changed from expected version %d to %d. Re-read `bricks/get-design-context` before importing.', $expected, $current ),
					'expectedDesignSystemVersion' => $expected,
					'actualDesignSystemVersion'   => $current,
				]
			);
		}

		return true;
	}

	/**
	 * Validate explicit replace key allow-list.
	 *
	 * @since 2.4
	 *
	 * @param array    $input          Ability input.
	 * @param string[] $candidate_keys Recognized bundle keys in the import.
	 * @return true|\WP_Error
	 */
	private static function validate_replace_key_precondition( array $input, array $candidate_keys ) {
		if ( ! array_key_exists( 'replaceKeys', $input ) ) {
			return Error::missing_param( 'replaceKeys' );
		}

		if ( ! is_array( $input['replaceKeys'] ) || empty( $input['replaceKeys'] ) ) {
			return Error::invalid_param( 'replaceKeys', 'a non-empty array of recognized bundle keys to overwrite', $input['replaceKeys'] );
		}

		$replace_keys = [];

		foreach ( array_values( $input['replaceKeys'] ) as $index => $key ) {
			$key = trim( (string) $key );

			if ( ! isset( self::BUNDLE_KEYS[ $key ] ) ) {
				return Error::invalid_param( "replaceKeys[{$index}]", 'one of: ' . implode( ', ', array_keys( self::BUNDLE_KEYS ) ), $key );
			}

			$replace_keys[] = $key;
		}

		$replace_keys   = array_values( array_unique( $replace_keys ) );
		$candidate_keys = array_values( array_unique( $candidate_keys ) );
		$missing        = array_values( array_diff( $candidate_keys, $replace_keys ) );
		$unexpected     = array_values( array_diff( $replace_keys, $candidate_keys ) );

		if ( ! empty( $missing ) || ! empty( $unexpected ) ) {
			return Error::conflict(
				'global_data_replace_keys_mismatch',
				[
					'message'     => 'Replace imports require `replaceKeys` to exactly match the recognized bundle keys being overwritten.',
					'bundleKeys'  => $candidate_keys,
					'replaceKeys' => $replace_keys,
					'missing'     => $missing,
					'unexpected'  => $unexpected,
				]
			);
		}

		return true;
	}

	/**
	 * Validate current row counts before destructive replace writes.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @param array $plan  Import plan.
	 * @return true|\WP_Error
	 */
	private static function validate_expected_counts_precondition( array $input, array $plan ) {
		if ( ! array_key_exists( 'expectedCounts', $input ) ) {
			return Error::missing_param( 'expectedCounts' );
		}

		if ( ! is_array( $input['expectedCounts'] ) ) {
			return Error::invalid_param( 'expectedCounts', 'an object keyed by bundle key with current row counts', $input['expectedCounts'] );
		}

		foreach ( $input['expectedCounts'] as $key => $count ) {
			if ( ! isset( self::BUNDLE_KEYS[ $key ] ) ) {
				return Error::invalid_param( "expectedCounts.{$key}", 'one of: ' . implode( ', ', array_keys( self::BUNDLE_KEYS ) ), $key );
			}

			if ( ! is_numeric( $count ) || (int) $count < 0 ) {
				return Error::invalid_param( "expectedCounts.{$key}", 'integer >= 0', $count );
			}
		}

		foreach ( $plan as $row ) {
			$key = (string) ( $row['key'] ?? '' );

			if ( ! array_key_exists( $key, $input['expectedCounts'] ) ) {
				return Error::missing_param( "expectedCounts.{$key}" );
			}

			$expected = (int) $input['expectedCounts'][ $key ];
			$actual   = (int) ( $row['beforeCount'] ?? 0 );

			if ( $expected !== $actual ) {
				return Error::conflict(
					'global_data_count_mismatch',
					[
						'message'       => sprintf( 'Global data key "%s" count changed from expected %d to %d. Re-read current counts before replacing.', $key, $expected, $actual ),
						'key'           => $key,
						'expectedCount' => $expected,
						'actualCount'   => $actual,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Validate the imported value for one bundle key.
	 *
	 * @since 2.4
	 *
	 * @param string $key  Bundle key.
	 * @param array  $rows Bundle rows.
	 * @return true|\WP_Error
	 */
	private static function validate_global_data_bundle_key( string $key, array $rows ) {
		if ( empty( $rows ) ) {
			return true;
		}

		foreach ( array_values( $rows ) as $index => $row ) {
			if ( $key !== 'pseudoClasses' && ! is_array( $row ) ) {
				return Error::invalid_param( "bundle.{$key}[{$index}]", 'object row', $row );
			}

			if ( $key === 'components' ) {
				if ( ! is_array( $row ) ) {
					return Error::invalid_param( "bundle.{$key}[{$index}]", 'component object', $row );
				}

				if ( empty( $row['id'] ) || ! is_string( $row['id'] ) ) {
					return Error::invalid_param( "bundle.{$key}[{$index}].id", 'a non-empty component id string', $row['id'] ?? null );
				}

				if ( ! is_array( $row['elements'] ?? null ) ) {
					return Error::invalid_param( "bundle.{$key}[{$index}].elements", 'component elements array', $row['elements'] ?? null );
				}
			}
		}

		return true;
	}

	/**
	 * Merge rows with safe duplicate handling.
	 *
	 * @since 2.4
	 *
	 * @param string $key          Bundle key.
	 * @param array  $existing     Existing rows.
	 * @param array  $incoming     Incoming rows.
	 * @param string $on_duplicate Duplicate policy: skip|replace.
	 * @return array { rows, stats }
	 */
	private static function merge_rows( string $key, array $existing, array $incoming, string $on_duplicate ): array {
		$result        = array_values( $existing );
		$identity_map  = self::identity_index( $key, $result );
		$added         = 0;
		$overwritten   = 0;
		$skipped_dupes = 0;

		foreach ( $incoming as $row ) {
			$identity = self::row_identity( $key, $row );

			if ( $identity !== null && isset( $identity_map[ $identity ] ) ) {
				if ( $on_duplicate === 'replace' ) {
					$result[ $identity_map[ $identity ] ] = $row;
					$overwritten++;
				} else {
					$skipped_dupes++;
				}

				continue;
			}

			if ( $identity === null && in_array( $row, $result, true ) ) {
				$skipped_dupes++;
				continue;
			}

			$result[] = $row;

			if ( $identity !== null ) {
				$identity_map[ $identity ] = count( $result ) - 1;
			}

			$added++;
		}

		return [
			'rows'  => array_values( $result ),
			'stats' => [
				'beforeCount'           => count( $existing ),
				'incomingCount'         => count( $incoming ),
				'afterCount'            => count( $result ),
				'addedCount'            => $added,
				'overwrittenCount'      => $overwritten,
				'skippedDuplicateCount' => $skipped_dupes,
				'removedCount'          => 0,
			],
		];
	}

	/**
	 * Build replace-mode stats.
	 *
	 * @since 2.4
	 *
	 * @param string $key      Bundle key.
	 * @param array  $existing Existing rows.
	 * @param array  $incoming Incoming rows.
	 * @return array
	 */
	private static function replace_stats( string $key, array $existing, array $incoming ): array {
		$existing_identities = array_keys( self::identity_index( $key, $existing ) );
		$incoming_identities = array_keys( self::identity_index( $key, $incoming ) );

		$added   = count( array_diff( $incoming_identities, $existing_identities ) );
		$removed = count( array_diff( $existing_identities, $incoming_identities ) );
		$kept    = count( array_intersect( $incoming_identities, $existing_identities ) );

		if ( empty( $existing_identities ) && empty( $incoming_identities ) ) {
			$added   = max( 0, count( $incoming ) - count( $existing ) );
			$removed = max( 0, count( $existing ) - count( $incoming ) );
			$kept    = min( count( $existing ), count( $incoming ) );
		}

		return [
			'beforeCount'           => count( $existing ),
			'incomingCount'         => count( $incoming ),
			'afterCount'            => count( $incoming ),
			'addedCount'            => $added,
			'overwrittenCount'      => $kept,
			'skippedDuplicateCount' => 0,
			'removedCount'          => $removed,
		];
	}

	/**
	 * Index rows by their stable identity for a given global-data key.
	 *
	 * @since 2.4
	 *
	 * @param string $key  Bundle key.
	 * @param array  $rows Rows.
	 * @return array<string, int>
	 */
	private static function identity_index( string $key, array $rows ): array {
		$index = [];

		foreach ( array_values( $rows ) as $row_index => $row ) {
			$identity = self::row_identity( $key, $row );

			if ( $identity !== null && ! isset( $index[ $identity ] ) ) {
				$index[ $identity ] = $row_index;
			}
		}

		return $index;
	}

	/**
	 * Resolve a stable row identity for safe merge/replace planning.
	 *
	 * @since 2.4
	 *
	 * @param string $key Bundle key.
	 * @param mixed  $row Row value.
	 * @return string|null
	 */
	private static function row_identity( string $key, $row ) {
		if ( is_array( $row ) ) {
			$fields = $key === 'breakpoints' ? [ 'key', 'id' ] : [ 'id', 'key', 'name' ];

			foreach ( $fields as $field ) {
				if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && (string) $row[ $field ] !== '' ) {
					return $field . ':' . (string) $row[ $field ];
				}
			}

			return null;
		}

		if ( is_scalar( $row ) ) {
			return 'value:' . (string) $row;
		}

		return null;
	}

	/**
	 * Does a global-data bundle version match this importer.
	 *
	 * @since 2.4
	 *
	 * @param string $version Bundle version.
	 * @return bool
	 */
	private static function is_supported_bundle_version( string $version ): bool {
		return $version === self::BUNDLE_VERSION || strpos( $version, '2.' ) === 0;
	}

	/**
	 * Redact code-sensitive component payloads from exports when needed.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return array
	 */
	private static function redact_component_exports( array $components ): array {
		if ( self::current_user_can_read_code_sensitive_payload() ) {
			return $components;
		}

		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) || ! is_array( $component['elements'] ?? null ) ) {
				continue;
			}

			$component['elements'] = Elements::redact_code_sensitive_elements( $component['elements'] );
			if ( is_array( $component['properties'] ?? null ) ) {
				$component['properties'] = Elements::redact_component_code_defaults( $component['properties'] );
			}
			$components[ $index ] = $component;
		}

		return $components;
	}

	/**
	 * Redact code-sensitive element payloads from a template export JSON blob.
	 *
	 * @since 2.4
	 *
	 * @param array $data Export row from Bricks core.
	 * @return array
	 */
	private static function redact_template_export( array $data ): array {
		if ( self::current_user_can_read_code_sensitive_payload() || ! is_string( $data['content'] ?? null ) ) {
			return $data;
		}

		$payload = json_decode( $data['content'], true );

		if ( ! is_array( $payload ) ) {
			return $data;
		}

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $payload[ $area ] ?? null ) ) {
				$payload[ $area ] = Elements::redact_code_sensitive_elements( $payload[ $area ] );
			}
		}

		if ( is_array( $payload['components'] ?? null ) ) {
			$payload['components'] = self::redact_component_exports( $payload['components'] );
		}

		$encoded = wp_json_encode( $payload );

		if ( is_string( $encoded ) ) {
			$data['content'] = $encoded;
		}

		return $data;
	}

	/**
	 * Reject component imports that carry executable payloads.
	 *
	 * @since 2.4
	 *
	 * @param array $components Component rows.
	 * @return true|\WP_Error
	 */
	private static function check_components_code_permissions( array $components ) {
		foreach ( $components as $component ) {
			if ( isset( $component['properties'] ) && ! is_array( $component['properties'] ) ) {
				return Error::invalid_param( 'component.properties', 'array', $component['properties'] );
			}
			$defaults = Elements::prepare_component_code_defaults( [], is_array( $component['properties'] ?? null ) ? $component['properties'] : [] );
			if ( is_wp_error( $defaults ) ) {
				return $defaults;
			}
			if ( is_array( $component ) && self::elements_contain_code_sensitive_payload( $component['elements'] ?? [] ) ) {
				return self::code_permission_error();
			}
		}

		return true;
	}

	/**
	 * Reject template imports that carry executable payloads.
	 *
	 * @since 2.4
	 *
	 * @param array $data Template JSON data.
	 * @return true|\WP_Error
	 */
	private static function check_template_data_code_permissions( array $data ) {
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( self::elements_contain_code_sensitive_payload( $data[ $area ] ?? [] ) ) {
				return self::code_permission_error();
			}
		}

		$component_check = self::check_components_code_permissions( $data['components'] ?? [] );

		if ( is_wp_error( $component_check ) ) {
			return $component_check;
		}

		return true;
	}

	/**
	 * Whether an element array includes executable code, query editor, or echo tags.
	 *
	 * @since 2.4
	 *
	 * @param mixed $elements Element rows.
	 * @return bool
	 */
	private static function elements_contain_code_sensitive_payload( $elements ): bool {
		if ( ! is_array( $elements ) ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( Elements::code_sensitive_payload_for_current_user( $element ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user may read code-sensitive element payloads.
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private static function current_user_can_read_code_sensitive_payload(): bool {
		return class_exists( '\\Bricks\\Capabilities' ) && \Bricks\Capabilities::current_user_can_execute_code();
	}

	/**
	 * Standard forbidden response for code-sensitive import/export payloads.
	 *
	 * @since 2.4
	 *
	 * @return \WP_Error
	 */
	private static function code_permission_error(): \WP_Error {
		return Error::code_sensitive_write_forbidden();
	}

	/**
	 * Persist an option value and verify it survived the WordPress write path.
	 *
	 * `update_option()` returns false for unchanged values as well as failed
	 * writes, so callers that report import success need a readback check.
	 *
	 * @since 2.4
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Value to persist.
	 * @param string $context     Error context.
	 * @return true|\WP_Error
	 */
	private static function persist_option_value( string $option_name, $value, string $context ) {
		$updated = update_option( $option_name, $value );

		if ( ! $updated && get_option( $option_name ) !== $value ) {
			return Error::internal_error( $context, "Could not persist option {$option_name}." );
		}

		return true;
	}

	/**
	 * Maximum decoded ZIP bytes accepted by template import.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function max_template_zip_bytes(): int {
		return max(
			1,
			(int) apply_filters( 'bricks/abilities/import_export/max_template_zip_bytes', self::MAX_TEMPLATE_ZIP_BYTES )
		);
	}

	/**
	 * Maximum files accepted inside a template ZIP.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function max_template_zip_files(): int {
		return max(
			1,
			(int) apply_filters( 'bricks/abilities/import_export/max_template_zip_files', self::MAX_TEMPLATE_ZIP_FILES )
		);
	}

	/**
	 * Maximum decoded JSON bytes accepted per template file.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function max_template_json_bytes(): int {
		return max(
			1,
			(int) apply_filters( 'bricks/abilities/import_export/max_template_json_bytes', self::MAX_TEMPLATE_JSON_BYTES )
		);
	}

	/**
	 * Insert a single template from decoded JSON.
	 *
	 * Mirrors the subset of `Templates::import_template()` that applies
	 * to a single record - post_title, templateType, content/header/footer,
	 * templateSettings. Image downloading and global-class/variable merging
	 * are skipped here (import via the admin UI when those are needed).
	 */
	private static function insert_template( array $data ) {
		$slug  = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ? BRICKS_DB_TEMPLATE_SLUG : 'bricks_template';
		$title = ! empty( $data['title'] ) ? (string) $data['title'] : __( '(no title)', 'bricks' );

		$post_id = wp_insert_post(
			[
				'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
				'post_title'  => $title,
				'post_type'   => $slug,
			],
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		\Bricks\Templates::import_components_from_template_data( $data );

		$template_type = isset( $data['templateType'] ) ? (string) $data['templateType'] : 'content';
		if ( defined( 'BRICKS_DB_TEMPLATE_TYPE' ) ) {
			$type_saved = update_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, $template_type );

			if ( ! $type_saved && get_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, true ) !== $template_type ) {
				wp_delete_post( $post_id, true );
				return Error::internal_error( 'import_template_bundle', 'Could not save template type metadata.' );
			}
		}

		foreach ( [ 'header', 'content', 'footer' ] as $area_key ) {
			if ( ! empty( $data[ $area_key ] ) && is_array( $data[ $area_key ] ) ) {
				$result = Save_Pipeline::execute( (int) $post_id, $data[ $area_key ], $area_key );

				if ( is_wp_error( $result ) ) {
					wp_delete_post( $post_id, true );
					return $result;
				}
			}
		}

		if ( ! empty( $data['templateSettings'] ) && is_array( $data['templateSettings'] ) && defined( 'BRICKS_DB_TEMPLATE_SETTINGS' ) ) {
			$template_settings = self::sanitize_imported_template_settings( $data['templateSettings'] );
			\Bricks\Helpers::set_template_settings( $post_id, $template_settings );

			if ( get_post_meta( $post_id, BRICKS_DB_TEMPLATE_SETTINGS, true ) !== $template_settings ) {
				wp_delete_post( $post_id, true );
				return Error::internal_error( 'import_template_bundle', 'Could not save template settings metadata.' );
			}
		}

		return [
			'id'           => (int) $post_id,
			'title'        => $title,
			'templateType' => $template_type,
		];
	}

	/**
	 * Remove imported preview settings that point at resources missing locally.
	 *
	 * Mirrors the established admin template import path so MCP imports do not
	 * persist stale template settings that the builder cannot resolve.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Imported template settings.
	 * @return array
	 */
	private static function sanitize_imported_template_settings( array $settings ): array {
		$template_preview_term = $settings['templatePreviewTerm'] ?? 0;

		if ( $template_preview_term ) {
			$preview_term     = explode( '::', (string) $template_preview_term );
			$preview_taxonomy = isset( $preview_term[0] ) ? (string) $preview_term[0] : '';
			$preview_term_id  = isset( $preview_term[1] ) ? (int) $preview_term[1] : 0;

			if ( ! $preview_term_id || ! term_exists( $preview_term_id, $preview_taxonomy ) ) {
				unset( $settings['templatePreviewTerm'] );
			}
		}

		$template_preview_author = $settings['templatePreviewAuthor'] ?? 0;
		if ( $template_preview_author && ! get_user_by( 'id', $template_preview_author ) ) {
			unset( $settings['templatePreviewAuthor'] );
		}

		$template_preview_post_type = $settings['templatePreviewPostType'] ?? '';
		if ( $template_preview_post_type && ! post_type_exists( (string) $template_preview_post_type ) ) {
			unset( $settings['templatePreviewPostType'] );
		}

		return $settings;
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $item;
			if ( is_link( $path ) ) {
				wp_delete_file( $path );
			} elseif ( is_dir( $path ) ) {
				self::rmdir_recursive( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir );
	}
}

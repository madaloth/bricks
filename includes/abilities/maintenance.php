<?php
/**
 * Maintenance abilities
 *
 * Admin-only maintenance operations with MCP parity for the Bricks settings
 * admin page: regenerate CSS files, scan for orphaned elements, clean them up.
 *
 * Explicitly NOT exposed here:
 * - `regenerate-code-signatures` - security-sensitive (flipping signatures
 *   can re-enable previously-blocked custom-code execution). Belongs in the
 *   excluded-settings domain, not the MCP surface.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Maintenance {
	// ==================================================================
	// Schemas
	// ==================================================================

	/**
	 * Input schema for regenerate-css-files.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function regenerate_css_files_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
		];
	}

	public static function regenerate_css_files_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'            => [ 'type' => 'boolean' ],
				'generatedFiles'     => [ 'type' => 'array' ],
				'generatedFileCount' => [ 'type' => 'integer' ],
				'cssLoading'         => [
					'type'        => 'string',
					'description' => __( 'Current `cssLoading` setting (`inline` or `file`). When `inline`, file regeneration has no runtime effect beyond ensuring files exist for fallback.', 'bricks' ),
				],
			],
		];
	}

	public static function list_orphaned_elements_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
		];
	}

	public static function list_orphaned_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'totalOrphans'    => [ 'type' => 'integer' ],
				'totalPosts'      => [ 'type' => 'integer' ],
				'orphansByPostId' => [ 'type' => 'object' ],
			],
		];
	}

	public static function cleanup_orphaned_elements_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'dryRun' => [
					'type'        => 'boolean',
					'description' => __( 'When true, scan and report what would be cleaned without modifying postmeta. Default false.', 'bricks' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	public static function cleanup_orphaned_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'      => [ 'type' => 'boolean' ],
				'totalCleaned' => [ 'type' => 'integer' ],
				'postsCleaned' => [ 'type' => 'integer' ],
				'message'      => [ 'type' => 'string' ],
				'dryRun'       => [ 'type' => 'boolean' ],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Permission: all maintenance ops require `manage_options` - these
	 * touch site-wide disk state and post rows.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function admin_permission( $input ) {
		return Manager::require_cap( 'manage_options' );
	}

	// ==================================================================
	// Execute
	// ==================================================================

	/**
	 * Execute: regenerate-css-files
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function regenerate_css_files( $input ) {
		$input_check = self::reject_no_parameter_input( $input );
		if ( is_wp_error( $input_check ) ) {
			return $input_check;
		}

		self::load_css_file_dependencies();

		if ( ! class_exists( '\\Bricks\\Assets_Files' ) ) {
			return Error::internal_error(
				'regenerate_css_files',
				'Bricks Assets_Files class is unavailable - cannot regenerate CSS.'
			);
		}

		$generated = \Bricks\Assets_Files::regenerate_css_files();
		$files     = is_array( $generated ) ? array_values( $generated ) : [];

		return [
			'success'            => true,
			'generatedFiles'     => $files,
			'generatedFileCount' => count( $files ),
			'cssLoading'         => (string) \Bricks\Database::get_setting( 'cssLoading', 'inline' ),
		];
	}

	/**
	 * Execute: list-orphaned-elements
	 *
	 * Scans every post using Bricks meta for elements whose parent no longer
	 * exists in the same tree. Read-only - returns what would be cleaned if
	 * `cleanup-orphaned-elements` ran.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_orphaned_elements( $input ) {
		$input_check = self::reject_no_parameter_input( $input );
		if ( is_wp_error( $input_check ) ) {
			return $input_check;
		}

		if ( ! class_exists( '\\Bricks\\Helpers' ) || ! method_exists( '\\Bricks\\Helpers', 'find_orphaned_elements_across_site' ) ) {
			return Error::internal_error(
				'list_orphaned_elements',
				'Bricks orphan-scan helper is unavailable in this Bricks build.'
			);
		}

		$scan = \Bricks\Helpers::find_orphaned_elements_across_site();

		return [
			'totalOrphans'    => (int) ( $scan['total_orphans'] ?? 0 ),
			'totalPosts'      => (int) ( $scan['total_posts'] ?? 0 ),
			'orphansByPostId' => self::object_response( $scan['orphaned_by_post_id'] ?? [] ),
		];
	}

	/**
	 * Execute: cleanup-orphaned-elements
	 *
	 * Destructive: strips orphan rows from the Bricks postmeta of every
	 * affected post. Parity with the "Clean up orphaned elements" button in
	 * Bricks > Settings > General.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function cleanup_orphaned_elements( $input ) {
		$input_check = self::reject_no_parameter_input( $input, [ 'dryRun' ] );
		if ( is_wp_error( $input_check ) ) {
			return $input_check;
		}

		if ( ! class_exists( '\\Bricks\\Helpers' ) || ! method_exists( '\\Bricks\\Helpers', 'find_orphaned_elements_across_site' ) ) {
			return Error::internal_error(
				'cleanup_orphaned_elements',
				'Bricks orphan-scan helper is unavailable in this Bricks build.'
			);
		}

		$orphaned = \Bricks\Helpers::find_orphaned_elements_across_site();
		// Use rest_sanitize_boolean so string "false", "0", "no" become false. PHP's
		// `(bool) "false"` returns true, which would silently turn a dry run into a
		// destructive run.
		$dry_run = array_key_exists( 'dryRun', $input ) ? rest_sanitize_boolean( $input['dryRun'] ) : false;

		if ( empty( $orphaned['orphaned_by_post_id'] ) ) {
			return [
				'success'      => true,
				'totalCleaned' => 0,
				'postsCleaned' => 0,
				'message'      => 'No orphaned elements found.',
				'dryRun'       => $dry_run,
			];
		}

		if ( $dry_run ) {
			return [
				'success'      => true,
				'totalCleaned' => (int) ( $orphaned['total_orphans'] ?? 0 ),
				'postsCleaned' => is_array( $orphaned['orphaned_by_post_id'] ) ? count( $orphaned['orphaned_by_post_id'] ) : 0,
				'message'      => sprintf(
					'Dry run: would remove %d orphaned elements across %d posts.',
					(int) ( $orphaned['total_orphans'] ?? 0 ),
					is_array( $orphaned['orphaned_by_post_id'] ) ? count( $orphaned['orphaned_by_post_id'] ) : 0
				),
				'dryRun'       => true,
			];
		}

		$result = \Bricks\Helpers::cleanup_orphaned_elements_across_site( $orphaned );

		if ( empty( $result['success'] ) ) {
			return Error::internal_error(
				'cleanup_orphaned_elements',
				'Cleanup helper reported failure.',
				is_array( $result ) ? $result : []
			);
		}

		return [
			'success'      => true,
			'totalCleaned' => (int) ( $result['total_cleaned'] ?? 0 ),
			'postsCleaned' => (int) ( $result['posts_cleaned'] ?? 0 ),
			'message'      => sprintf(
				'Removed %d orphaned elements across %d posts.',
				(int) ( $result['total_cleaned'] ?? 0 ),
				(int) ( $result['posts_cleaned'] ?? 0 )
			),
			'dryRun'       => false,
		];
	}

	/**
	 * Reject unexpected parameters for no-argument maintenance tools.
	 *
	 * @param mixed $input Ability input.
	 * @return true|\WP_Error
	 */
	private static function reject_no_parameter_input( $input, array $allowed = [] ) {
		$input = is_array( $input ) || is_object( $input ) ? (array) $input : [];

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				return Error::unknown_param( (string) $key, $allowed );
			}
		}

		return true;
	}

	/**
	 * Load CSS-regeneration classes in CLI/MCP contexts.
	 *
	 * Bricks normally pulls these classes in during the frontend/admin asset
	 * bootstrap. The long-lived MCP server does not necessarily hit that path,
	 * so load the asset file helpers explicitly before calling
	 * `Assets_Files::regenerate_css_files()`.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function load_css_file_dependencies(): void {
		if ( ! defined( 'BRICKS_PATH' ) ) {
			return;
		}

		$dependencies = [
			'\\Bricks\\Assets_Color_Palettes'    => 'includes/assets/color-palettes.php',
			'\\Bricks\\Assets_Global_Custom_Css' => 'includes/assets/global-custom-css.php',
			'\\Bricks\\Assets_Global_Elements'   => 'includes/assets/global-elements.php',
			'\\Bricks\\Assets_Theme_Styles'      => 'includes/assets/theme-styles.php',
			'\\Bricks\\Assets_Global_Variables'  => 'includes/assets/global-variables.php',
			'\\Bricks\\Assets_Files'             => 'includes/assets/files.php',
		];

		foreach ( $dependencies as $class => $relative_path ) {
			if ( class_exists( $class, false ) ) {
				continue;
			}

			$path = BRICKS_PATH . $relative_path;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Force associative output to encode as a JSON object, even when empty.
	 *
	 * @param mixed $value Raw value.
	 * @return object
	 */
	private static function object_response( $value ): object {
		return (object) ( is_array( $value ) ? $value : [] );
	}
}

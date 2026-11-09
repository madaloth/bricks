<?php
/**
 * Breakpoint abilities
 *
 * Read and write the site's responsive breakpoints. Bricks supports
 * "custom breakpoints" (mobile-first or desktop-first) - this surface
 * lets clients configure them without touching `BRICKS_DB_BREAKPOINTS`
 * directly.
 *
 * The write path uses exact authority ownership and does NOT regenerate CSS
 * files automatically. Callers changing widths should follow up with
 * `bricks/regenerate-css-files` if `cssLoading` is set to `file`.
 *
 * Bricks stores breakpoints descending by width. Core derives mobile-first
 * mode from the base row's position before sorting the returned list: if the
 * smallest row is base, it sits last in the stored list and `isMobileFirst`
 * becomes true.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Breakpoints {
	/**
	 * Maximum page records inspected for destructive-change evidence.
	 */
	const USAGE_PAGE_SCAN_LIMIT = 200;

	/**
	 * Maximum option-backed design records inspected per resource type.
	 */
	const USAGE_OPTION_SCAN_LIMIT = 200;

	/**
	 * Maximum keys and values inspected inside one page or design record.
	 */
	const USAGE_INSPECTION_NODE_LIMIT = 5000;

	/**
	 * Maximum token comparisons performed inside one design record.
	 */
	const USAGE_MATCH_COMPARISON_LIMIT = 5000;

	/**
	 * Maximum matching records returned per resource type.
	 */
	const USAGE_MATCH_LIMIT = 25;

	/**
	 * Authority-local design version option.
	 */
	const DESIGN_SYSTEM_VERSION_OPTION = 'bricks_mcp_design_system_version';

	/**
	 * Stable ownership resource names.
	 */
	const BREAKPOINT_RESOURCE      = 'breakpoints';
	const GLOBAL_SETTINGS_RESOURCE = 'globalSettings';

	/**
	 * Input schema for list-breakpoints
	 *
	 * @since 2.4
	 */
	public static function list_breakpoints_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Output schema for list-breakpoints
	 *
	 * @since 2.4
	 */
	public static function list_breakpoints_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'customEnabled'           => [
					'type'        => 'boolean',
					'description' => __( 'Whether the site is using custom breakpoints instead of Bricks defaults. Writable through `bricks/set-breakpoints` via `customEnabled`, not through `bricks/set-global-settings`.', 'bricks' ),
				],
				'isMobileFirst'           => [
					'type'        => 'boolean',
					'description' => __( 'True when the smallest breakpoint is the `base`. CSS is emitted with min-width rules.', 'bricks' ),
				],
				'baseKey'                 => [
					'type'        => 'string',
					'description' => __( 'Key of the base breakpoint.', 'bricks' ),
				],
				'baseWidth'               => [
					'type'        => 'integer',
					'description' => __( 'Width in pixels of the base breakpoint.', 'bricks' ),
				],
				'breakpoints'             => [
					'type'        => 'array',
					'description' => __( 'All breakpoints, sorted by width according to the active paradigm. Each row has { key, label, width, icon, base? }.', 'bricks' ),
				],
				'defaults'                => [
					'type'        => 'array',
					'description' => __( 'The built-in default breakpoints (reference for reset operations).', 'bricks' ),
				],
				'breakpointOwnership'     => [ 'type' => 'object' ],
				'globalSettingsOwnership' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Input schema for set-breakpoints
	 *
	 * @since 2.4
	 */
	public static function set_breakpoints_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'breakpoints', 'expectedOwnership' ],
			'properties' => [
				'breakpoints'                     => [
					'type'        => 'array',
					'description' => __( 'Full replacement set of breakpoints. Each row requires { key (string), label (string), width (integer ≥ 0) } and may include { icon (string), base (boolean) }. Exactly one row must be `base: true`. Keys must be unique.', 'bricks' ),
					'items'       => [
						'type'       => 'object',
						'required'   => [ 'key', 'label', 'width' ],
						'properties' => [
							'key'   => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
							'width' => [
								'type'    => 'integer',
								'minimum' => 0
							],
							'icon'  => [ 'type' => 'string' ],
							'base'  => [ 'type' => 'boolean' ],
						],
					],
				],
				'customEnabled'                   => [
					'type'        => 'boolean',
					'description' => __( 'Optional master toggle for custom breakpoints. Omit to leave the current toggle unchanged. Set true to apply the stored custom set, or false to make Bricks fall back to its built-in defaults.', 'bricks' ),
				],
				'expectedOwnership'               => [
					'type'        => 'object',
					'description' => __( 'Required breakpoint ownership envelope returned by the latest `bricks/list-breakpoints` or `bricks/set-breakpoints` response.', 'bricks' ),
				],
				'expectedGlobalSettingsOwnership' => [
					'type'        => 'object',
					'description' => __( 'Required global-settings ownership envelope when `customEnabled` is present.', 'bricks' ),
				],
				'allowRemovedBreakpoints'         => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true when the replacement removes or renames any current breakpoint key. This acknowledges that existing responsive settings can retain references to removed keys. Width, label, icon, ordering, and additive edits do not require it.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for set-breakpoints
	 *
	 * @since 2.4
	 */
	public static function set_breakpoints_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'                 => [ 'type' => 'boolean' ],
				'changed'                 => [ 'type' => 'boolean' ],
				'breakpointsChanged'      => [ 'type' => 'boolean' ],
				'globalSettingsChanged'   => [ 'type' => 'boolean' ],
				'customEnabled'           => [ 'type' => 'boolean' ],
				'isMobileFirst'           => [ 'type' => 'boolean' ],
				'baseKey'                 => [ 'type' => 'string' ],
				'baseWidth'               => [ 'type' => 'integer' ],
				'breakpoints'             => [ 'type' => 'array' ],
				'customBreakpoints'       => [ 'type' => 'array' ],
				'breakpointOwnership'     => [ 'type' => 'object' ],
				'globalSettingsOwnership' => [ 'type' => 'object' ],
				'removedBreakpointKeys'   => [ 'type' => 'array' ],
				'removalUsageEvidence'    => [ 'type' => [ 'object', 'null' ] ],
				'note'                    => [
					'type'        => 'string',
					'description' => __( 'Follow-up guidance, for example "run bricks/regenerate-css-files" when `cssLoading` is set to `file`.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: read breakpoints - any user with builder access.
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

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_breakpoints_manager' ) ) {
			return Error::forbidden_builder_permission( 'access_breakpoints_manager' );
		}

		return true;
	}

	/**
	 * Permission: write breakpoints - admin-only (site-wide visual impact).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function write_permission( $input ) {
		$can_read = self::read_permission( $input );

		if ( is_wp_error( $can_read ) ) {
			return $can_read;
		}

		if ( array_key_exists( 'customEnabled', (array) $input ) && ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Execute: list-breakpoints
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_breakpoints( $input ) {
		Manager::flush_options_cache();

		$breakpoint_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_BREAKPOINTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			false
		);
		$settings_snapshot   = Design_Option_Store::read_versioned(
			BRICKS_DB_GLOBAL_SETTINGS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $breakpoint_snapshot ) ) {
			return $breakpoint_snapshot;
		}

		if ( is_wp_error( $settings_snapshot ) ) {
			return $settings_snapshot;
		}

		$breakpoint_ownership = Design_Option_Store::ownership_envelope( self::BREAKPOINT_RESOURCE, $breakpoint_snapshot );
		$settings_ownership   = Design_Option_Store::ownership_envelope( self::GLOBAL_SETTINGS_RESOURCE, $settings_snapshot );

		if ( is_wp_error( $breakpoint_ownership ) ) {
			return $breakpoint_ownership;
		}

		if ( is_wp_error( $settings_ownership ) ) {
			return $settings_ownership;
		}

		$breakpoints = \Bricks\Breakpoints::get_breakpoints();
		$defaults    = \Bricks\Breakpoints::get_default_breakpoints();
		$settings    = is_array( $settings_snapshot['value'] ) ? $settings_snapshot['value'] : [];
		$custom      = ! empty( $settings['customBreakpoints'] );

		return [
			'customEnabled'           => $custom,
			'isMobileFirst'           => \Bricks\Breakpoints::$is_mobile_first,
			'baseKey'                 => \Bricks\Breakpoints::$base_key,
			'baseWidth'               => \Bricks\Breakpoints::$base_width,
			'breakpoints'             => array_values( $breakpoints ),
			'defaults'                => $defaults,
			'breakpointOwnership'     => $breakpoint_ownership,
			'globalSettingsOwnership' => $settings_ownership,
		];
	}

	/**
	 * Execute: set-breakpoints
	 *
	 * Full-replacement write - callers pass the complete ordered list they
	 * want persisted. The bricks CSS files are NOT regenerated here; flip
	 * `cssLoading` back and forth or call `bricks/regenerate-css-files`.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_breakpoints( $input ) {
		$rows               = $input['breakpoints'] ?? null;
		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return Error::invalid_param( 'breakpoints', 'non-empty array of breakpoint rows', $rows );
		}

		if ( ! is_array( $expected_ownership ) ) {
			return Error::invalid_param( 'expectedOwnership', 'breakpoint ownership envelope from the latest read or write', $expected_ownership );
		}

		$writes_settings             = array_key_exists( 'customEnabled', $input );
		$expected_settings_ownership = $input['expectedGlobalSettingsOwnership'] ?? null;

		if ( $writes_settings && ! is_array( $expected_settings_ownership ) ) {
			return Error::invalid_param( 'expectedGlobalSettingsOwnership', 'global-settings ownership envelope from the latest breakpoint read or write', $expected_settings_ownership );
		}

		$seen_keys = [];
		$bases     = 0;
		$clean     = [];

		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return Error::invalid_param( "breakpoints[$index]", 'object', $row );
			}

			$key   = isset( $row['key'] ) ? (string) $row['key'] : '';
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$width = isset( $row['width'] ) ? (int) $row['width'] : -1;
			$icon  = isset( $row['icon'] ) ? (string) $row['icon'] : '';
			$base  = ! empty( $row['base'] );

			if ( $key === '' || ! preg_match( '/^[a-z0-9_-]+$/i', $key ) ) {
				return Error::invalid_param( "breakpoints[$index].key", 'non-empty alphanumeric identifier', $key );
			}
			if ( $label === '' ) {
				return Error::invalid_param( "breakpoints[$index].label", 'non-empty string', $label );
			}
			if ( $width < 0 ) {
				return Error::invalid_param( "breakpoints[$index].width", 'non-negative integer', $width );
			}
			if ( isset( $seen_keys[ $key ] ) ) {
				return Error::invalid_param( "breakpoints[$index].key", 'unique key across all breakpoints', $key );
			}

			$seen_keys[ $key ] = true;
			$entry             = [
				'key'   => $key,
				'label' => $label,
				'width' => $width,
			];
			if ( $icon !== '' ) {
				$entry['icon'] = $icon;
			}
			if ( $base ) {
				$entry['base'] = true;
				$bases++;
			}

			$clean[] = $entry;
		}

		if ( $bases !== 1 ) {
			return Error::invalid_param( 'breakpoints', 'exactly one row marked `base: true`', [ 'baseCount' => $bases ] );
		}

		$widths = array_column( $clean, 'width' );
		array_multisort( $widths, SORT_DESC, $clean );

		$breakpoint_snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_BREAKPOINTS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			false
		);
		$settings_snapshot   = Design_Option_Store::read_versioned(
			BRICKS_DB_GLOBAL_SETTINGS,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $breakpoint_snapshot ) ) {
			return $breakpoint_snapshot;
		}

		if ( is_wp_error( $settings_snapshot ) ) {
			return $settings_snapshot;
		}

		$current_breakpoints = is_array( $breakpoint_snapshot['value'] ) && ! empty( $breakpoint_snapshot['value'] )
			? $breakpoint_snapshot['value']
			: \Bricks\Breakpoints::get_default_breakpoints();
		$current_keys        = array_values(
			array_filter(
				array_map(
					static function( $row ) {
						return is_array( $row ) ? (string) ( $row['key'] ?? '' ) : '';
					},
					$current_breakpoints
				)
			)
		);
		$removed_keys        = array_values( array_diff( $current_keys, array_keys( $seen_keys ) ) );

		if ( ! empty( $removed_keys ) && ( $input['allowRemovedBreakpoints'] ?? null ) !== true ) {
			return Error::conflict(
				'breakpoint-removal-acknowledgement-required',
				[
					'message'               => __( 'This replacement removes or renames existing breakpoint keys. Preserve those keys, or pass allowRemovedBreakpoints=true after reviewing the bounded usage evidence. Existing responsive settings are not rewritten automatically.', 'bricks' ),
					'removedBreakpointKeys' => $removed_keys,
					'removalUsageEvidence'  => self::breakpoint_usage_evidence( $removed_keys ),
				]
			);
		}

		$removal_evidence = ! empty( $removed_keys ) ? self::breakpoint_usage_evidence( $removed_keys ) : null;

		$valid = Design_Option_Store::validate_ownership( self::BREAKPOINT_RESOURCE, $breakpoint_snapshot, $expected_ownership );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( $writes_settings ) {
			$valid = Design_Option_Store::validate_ownership( self::GLOBAL_SETTINGS_RESOURCE, $settings_snapshot, $expected_settings_ownership );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		// These fields belong to Builder controls, not the replacement input.
		// Preserve only known metadata from the owned snapshot for retained keys.
		$current_by_key = array_column( $current_breakpoints, null, 'key' );

		foreach ( $clean as $index => $entry ) {
			$current = $current_by_key[ $entry['key'] ] ?? [];

			foreach ( [ 'widthBuilder', 'edited', 'custom', 'paused' ] as $field ) {
				if ( array_key_exists( $field, $current ) ) {
					$clean[ $index ][ $field ] = $current[ $field ];
				}
			}
		}

		$settings_save = [
			'success' => true,
			'status'  => 'no_change',
			'value'   => $settings_snapshot['value'],
		];

		if ( $writes_settings ) {
			$settings = is_array( $settings_snapshot['value'] ) ? $settings_snapshot['value'] : [];

			if ( ! empty( $input['customEnabled'] ) ) {
				$settings['customBreakpoints'] = true;
			} else {
				unset( $settings['customBreakpoints'] );
			}

			$settings_save = Design_Option_Store::compare_and_swap_owned(
				self::GLOBAL_SETTINGS_RESOURCE,
				$settings_snapshot,
				$settings,
				self::DESIGN_SYSTEM_VERSION_OPTION,
				$expected_settings_ownership
			);

			if ( is_wp_error( $settings_save ) ) {
				return $settings_save;
			}
		}

		$breakpoint_save = Design_Option_Store::compare_and_swap_owned(
			self::BREAKPOINT_RESOURCE,
			$breakpoint_snapshot,
			$clean,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected_ownership
		);

		if ( is_wp_error( $breakpoint_save ) ) {
			if ( $settings_save['status'] !== 'no_change' ) {
				$rollback = self::compensate_global_settings( $settings_snapshot, $settings_save );

				if ( is_wp_error( $rollback ) ) {
					return Error::conflict(
						'breakpoint_compensation_required',
						[
							'message'                => __( 'Breakpoints were not saved, but the custom-breakpoint setting could not be restored without overwriting a concurrent settings change. Re-read settings and repair the toggle if needed.', 'bricks' ),
							'breakpointErrorCode'    => $breakpoint_save->get_error_code(),
							'compensationErrorCode'  => $rollback->get_error_code(),
							'manualRecoveryRequired' => true,
						]
					);
				}
			}

			return $breakpoint_save;
		}

		$breakpoint_readback = Design_Option_Store::read_versioned_from_snapshot(
			$breakpoint_snapshot,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			false
		);
		$settings_readback   = Design_Option_Store::read_versioned_from_snapshot(
			$settings_snapshot,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			[]
		);

		if ( is_wp_error( $breakpoint_readback ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::BREAKPOINT_RESOURCE,
				$breakpoint_readback
			);
		}

		if ( is_wp_error( $settings_readback ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::GLOBAL_SETTINGS_RESOURCE,
				$settings_readback,
				[ self::BREAKPOINT_RESOURCE => self::snapshot_evidence( $breakpoint_readback ) ]
			);
		}

		$live_evidence = [
			self::BREAKPOINT_RESOURCE      => self::snapshot_evidence( $breakpoint_readback ),
			self::GLOBAL_SETTINGS_RESOURCE => self::snapshot_evidence( $settings_readback ),
		];

		if ( maybe_serialize( $breakpoint_readback['value'] ) !== maybe_serialize( $breakpoint_save['value'] ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::BREAKPOINT_RESOURCE,
				null,
				$live_evidence
			);
		}

		if ( maybe_serialize( $settings_readback['value'] ) !== maybe_serialize( $settings_save['value'] ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::GLOBAL_SETTINGS_RESOURCE,
				null,
				$live_evidence
			);
		}

		return self::breakpoint_write_response( $breakpoint_readback, $settings_readback, $breakpoint_save, $settings_save, $removed_keys, $removal_evidence );
	}

	/**
	 * Return explicit evidence when a write committed but its result cannot be
	 * safely represented from the live authority.
	 *
	 * @since 2.4
	 *
	 * @param array          $saves          Persistence results keyed by resource.
	 * @param string         $failed_resource Resource whose readback failed or diverged.
	 * @param \WP_Error|null $readback_error Readback failure, when available.
	 * @param array          $live_evidence  Safe live authority evidence.
	 * @return \WP_Error
	 */
	private static function committed_readback_error( array $saves, string $failed_resource, $readback_error = null, array $live_evidence = [] ) {
		$committed = [];

		foreach ( $saves as $resource => $save ) {
			if ( is_array( $save ) && ( $save['status'] ?? 'no_change' ) !== 'no_change' ) {
				$committed[] = $resource;
			}
		}

		return Error::conflict(
			'breakpoint-committed-readback',
			[
				'message'                => __( 'One or more breakpoint resources committed, but the live authority could not be proven to equal this write. Re-read breakpoints before another mutation and manually reconcile the reported committed resources when required.', 'bricks' ),
				'failedResource'         => $failed_resource,
				'committedResources'     => $committed,
				'manualRecoveryRequired' => ! empty( $committed ),
				'readbackErrorCode'      => $readback_error instanceof \WP_Error ? $readback_error->get_error_code() : '',
				'liveEvidence'           => $live_evidence,
			]
		);
	}

	/**
	 * Reduce a live snapshot to non-content evidence.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Authority snapshot.
	 * @return array
	 */
	private static function snapshot_evidence( array $snapshot ) {
		return [
			'exists'         => (bool) ( $snapshot['exists'] ?? false ),
			'siteId'         => (int) ( $snapshot['siteId'] ?? 0 ),
			'resourceDigest' => hash( 'sha256', ( ! empty( $snapshot['exists'] ) ? '1' : '0' ) . "\0" . (string) ( $snapshot['raw'] ?? '' ) ),
		];
	}

	/**
	 * Restore the first half of a failed compound breakpoint write.
	 *
	 * The rollback is guarded by the exact raw value produced by our settings
	 * write, so an intervening settings writer is preserved.
	 *
	 * @since 2.4
	 *
	 * @param array $before      Original global-settings snapshot.
	 * @param array $save_result Successful first-write result.
	 * @return array|\WP_Error
	 */
	private static function compensate_global_settings( array $before, array $save_result ) {
		$after = [
			'option'   => $before['option'],
			'exists'   => true,
			'raw'      => maybe_serialize( $save_result['value'] ),
			'autoload' => $before['autoload'] ?? null,
			'value'    => $save_result['value'],
			'siteId'   => $before['siteId'],
		];

		return Design_Option_Store::compare_and_swap(
			$after,
			$before['value'],
			! $before['exists']
		);
	}

	/**
	 * Build a write response from exact authority readbacks.
	 *
	 * @since 2.4
	 *
	 * @param array $breakpoint_snapshot Exact breakpoint readback.
	 * @param array $settings_snapshot   Exact global-settings readback.
	 * @param array $breakpoint_save     Breakpoint persistence result.
	 * @param array $settings_save       Settings persistence result.
	 * @param array $removed_keys        Removed breakpoint keys.
	 * @param array $removal_evidence    Bounded removal usage evidence.
	 * @return array|\WP_Error
	 */
	private static function breakpoint_write_response( array $breakpoint_snapshot, array $settings_snapshot, array $breakpoint_save, array $settings_save, array $removed_keys = [], $removal_evidence = null ) {
		$breakpoint_ownership = Design_Option_Store::ownership_envelope( self::BREAKPOINT_RESOURCE, $breakpoint_snapshot );
		$settings_ownership   = Design_Option_Store::ownership_envelope( self::GLOBAL_SETTINGS_RESOURCE, $settings_snapshot );
		$live_evidence        = [
			self::BREAKPOINT_RESOURCE      => self::snapshot_evidence( $breakpoint_snapshot ),
			self::GLOBAL_SETTINGS_RESOURCE => self::snapshot_evidence( $settings_snapshot ),
		];

		if ( is_wp_error( $breakpoint_ownership ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::BREAKPOINT_RESOURCE,
				$breakpoint_ownership,
				$live_evidence
			);
		}

		if ( is_wp_error( $settings_ownership ) ) {
			return self::committed_readback_error(
				[
					self::BREAKPOINT_RESOURCE      => $breakpoint_save,
					self::GLOBAL_SETTINGS_RESOURCE => $settings_save
				],
				self::GLOBAL_SETTINGS_RESOURCE,
				$settings_ownership,
				$live_evidence
			);
		}

		$settings     = is_array( $settings_snapshot['value'] ) ? $settings_snapshot['value'] : [];
		$saved_custom = is_array( $breakpoint_snapshot['value'] ) ? array_values( $breakpoint_snapshot['value'] ) : [];
		$note         = '';

		if ( ( $settings['cssLoading'] ?? '' ) === 'file' ) {
			$note = __( 'Call `bricks/regenerate-css-files` - cssLoading=file means Bricks caches CSS on disk and breakpoint changes are not yet reflected.', 'bricks' );
		}

		Manager::flush_options_cache();
		$breakpoints = \Bricks\Breakpoints::get_breakpoints();

		return [
			'success'                 => true,
			'changed'                 => $breakpoint_save['status'] !== 'no_change' || $settings_save['status'] !== 'no_change',
			'breakpointsChanged'      => $breakpoint_save['status'] !== 'no_change',
			'globalSettingsChanged'   => $settings_save['status'] !== 'no_change',
			'customEnabled'           => ! empty( $settings['customBreakpoints'] ),
			'isMobileFirst'           => \Bricks\Breakpoints::$is_mobile_first,
			'baseKey'                 => \Bricks\Breakpoints::$base_key,
			'baseWidth'               => \Bricks\Breakpoints::$base_width,
			'breakpoints'             => array_values( $breakpoints ),
			'customBreakpoints'       => $saved_custom,
			'breakpointOwnership'     => $breakpoint_ownership,
			'globalSettingsOwnership' => $settings_ownership,
			'removedBreakpointKeys'   => $removed_keys,
			'removalUsageEvidence'    => $removal_evidence,
			'note'                    => $note,
		];
	}

	/**
	 * Produce bounded, review-only evidence for removed breakpoint references.
	 *
	 * The scan deliberately reports its caps and availability. It is not a
	 * proof that no references exist, so explicit acknowledgement remains
	 * mandatory even when zero matches are found.
	 *
	 * @since 2.4
	 *
	 * @param array $keys Removed breakpoint keys.
	 * @return array
	 */
	private static function breakpoint_usage_evidence( array $keys ) {
		return self::removal_usage_evidence( $keys, 'breakpoint' );
	}

	/**
	 * Scan bounded design authorities for removed-token references.
	 *
	 * @since 2.4
	 *
	 * @param array  $tokens Removed tokens.
	 * @param string $kind   Token kind.
	 * @return array
	 */
	private static function removal_usage_evidence( array $tokens, string $kind ) {
		$matches           = [
			'pages'         => [],
			'globalClasses' => [],
			'themeStyles'   => [],
			'components'    => [],
		];
		$scanned           = [
			'pages'         => 0,
			'globalClasses' => 0,
			'themeStyles'   => 0,
			'components'    => 0,
		];
		$pages_truncated   = false;
		$truncated_scopes  = [];
		$truncated_records = array_fill_keys( array_keys( $matches ), [] );
		$scan_unavailable  = [];

		if ( function_exists( 'get_posts' ) && defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
			$meta_query = [ 'relation' => 'OR' ];
			foreach ( [ 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_FOOTER' ] as $constant ) {
				if ( defined( $constant ) ) {
					$meta_query[] = [
						'key'     => constant( $constant ),
						'compare' => 'EXISTS',
					];
				}
			}

			$post_ids        = get_posts(
				[
					'post_type'              => 'any',
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'posts_per_page'         => self::USAGE_PAGE_SCAN_LIMIT + 1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded destructive-change evidence.
				]
			);
			$pages_truncated = count( $post_ids ) > self::USAGE_PAGE_SCAN_LIMIT;
			$post_ids        = array_slice( $post_ids, 0, self::USAGE_PAGE_SCAN_LIMIT );
			if ( $pages_truncated ) {
				$truncated_scopes[] = 'pages';
			}

			foreach ( $post_ids as $post_id ) {
				$scanned['pages']++;
				$page_data = [];

				foreach ( [ 'BRICKS_DB_PAGE_HEADER', 'BRICKS_DB_PAGE_CONTENT', 'BRICKS_DB_PAGE_FOOTER' ] as $constant ) {
					if ( defined( $constant ) ) {
						$page_data[ $constant ] = get_post_meta( $post_id, constant( $constant ), true );
					}
				}

				$record_truncated = false;
				$found            = self::find_removed_references( $page_data, $tokens, $kind, $record_truncated );
				if ( ! empty( $found ) ) {
					$record_truncated = ! self::append_usage_match(
						$matches['pages'],
						[
							'id'     => (int) $post_id,
							'tokens' => $found,
						]
					) || $record_truncated;
				}
				if ( $record_truncated ) {
					$truncated_scopes[] = 'pages';
					self::append_truncated_record( $truncated_records['pages'], (int) $post_id );
				}
			}
		} else {
			$scan_unavailable[] = 'pages';
		}

		$stores = [
			'globalClasses' => 'BRICKS_DB_GLOBAL_CLASSES',
			'themeStyles'   => 'BRICKS_DB_THEME_STYLES',
			'components'    => 'BRICKS_DB_COMPONENTS',
		];

		foreach ( $stores as $scope => $constant ) {
			if ( ! defined( $constant ) ) {
				$scan_unavailable[] = $scope;
				continue;
			}

			$snapshot = Design_Option_Store::read( constant( $constant ), [] );
			if ( is_wp_error( $snapshot ) ) {
				$scan_unavailable[] = $scope;
				continue;
			}

			$rows = is_array( $snapshot['value'] ) ? $snapshot['value'] : [];
			if ( count( $rows ) > self::USAGE_OPTION_SCAN_LIMIT ) {
				$truncated_scopes[] = $scope;
				$rows               = array_slice( $rows, 0, self::USAGE_OPTION_SCAN_LIMIT, true );
			}

			foreach ( $rows as $index => $row ) {
				$scanned[ $scope ]++;
				$id               = is_array( $row ) ? (string) ( $row['id'] ?? $index ) : (string) $index;
				$record_truncated = false;
				$found            = self::find_removed_references( $row, $tokens, $kind, $record_truncated );

				if ( ! empty( $found ) ) {
					$record_truncated = ! self::append_usage_match(
						$matches[ $scope ],
						[
							'id'     => $id,
							'tokens' => $found,
						]
					) || $record_truncated;
				}
				if ( $record_truncated ) {
					$truncated_scopes[] = $scope;
					self::append_truncated_record( $truncated_records[ $scope ], $id );
				}
			}
		}

		$match_count = 0;
		foreach ( $matches as $rows ) {
			$match_count += count( $rows );
		}

		return [
			'kind'                   => $kind,
			'removedTokens'          => array_values( $tokens ),
			'scanned'                => $scanned,
			'matches'                => $matches,
			'returnedMatchCount'     => $match_count,
			'matchesLimitedPerScope' => self::USAGE_MATCH_LIMIT,
			'pageScanLimit'          => self::USAGE_PAGE_SCAN_LIMIT,
			'optionScanLimit'        => self::USAGE_OPTION_SCAN_LIMIT,
			'inspectionNodeLimit'    => self::USAGE_INSPECTION_NODE_LIMIT,
			'matchComparisonLimit'   => self::USAGE_MATCH_COMPARISON_LIMIT,
			'pagesTruncated'         => $pages_truncated,
			'truncatedScopes'        => array_values( array_unique( $truncated_scopes ) ),
			'truncatedRecords'       => $truncated_records,
			'scanUnavailable'        => array_values( array_unique( $scan_unavailable ) ),
			'complete'               => empty( $truncated_scopes ) && empty( $scan_unavailable ),
			'disclaimer'             => __( 'Review evidence only. A zero count does not prove that no references exist.', 'bricks' ),
		];
	}

	/**
	 * Append a match while bounding response size.
	 *
	 * @since 2.4
	 *
	 * @param array $matches Match rows.
	 * @param array $match   Candidate row.
	 * @return bool Whether the match was returned.
	 */
	private static function append_usage_match( array &$matches, array $match ): bool {
		if ( count( $matches ) < self::USAGE_MATCH_LIMIT ) {
			$matches[] = $match;
			return true;
		}

		return false;
	}

	/**
	 * Record one resource whose nested inspection reached its work limit.
	 *
	 * @since 2.4
	 *
	 * @param array      $records Resource identifiers.
	 * @param int|string $id      Resource identifier.
	 * @return void
	 */
	private static function append_truncated_record( array &$records, $id ): void {
		if ( count( $records ) < self::USAGE_MATCH_LIMIT && ! in_array( $id, $records, true ) ) {
			$records[] = $id;
		}
	}

	/**
	 * Find removed references in an opaque design value.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $value  Value.
	 * @param array  $tokens Removed tokens.
	 * @param string $kind   Token kind.
	 * @param bool   $truncated Whether the inspection budget was exhausted.
	 * @return array
	 */
	private static function find_removed_references( $value, array $tokens, string $kind, bool &$truncated = false ) {
		$found = [];
		self::collect_removed_references( $value, $tokens, $kind, $found, $truncated );
		return array_values( array_keys( $found ) );
	}

	/**
	 * Recursively inspect keys and scalar values for removed references.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $value  Value.
	 * @param array  $tokens Removed tokens.
	 * @param string $kind   Token kind.
	 * @param array  $found  Found-token map.
	 * @param bool   $truncated Whether the inspection budget was exhausted.
	 * @return void
	 */
	private static function collect_removed_references( $value, array $tokens, string $kind, array &$found, bool &$truncated ): void {
		$remaining             = self::USAGE_INSPECTION_NODE_LIMIT;
		$comparisons_remaining = self::USAGE_MATCH_COMPARISON_LIMIT;
		$stack                 = [ $value ];

		while ( ! empty( $stack ) ) {
			if ( $remaining <= 0 ) {
				$truncated = true;
				break;
			}

			$current = array_pop( $stack );
			--$remaining;

			if ( is_array( $current ) || is_object( $current ) ) {
				foreach ( (array) $current as $key => $child ) {
					if ( $remaining <= 0 ) {
						$truncated = true;
						break;
					}

					--$remaining;
					if ( ! self::match_removed_reference( (string) $key, $tokens, $kind, $found, $comparisons_remaining ) ) {
						$truncated = true;
						break;
					}
					$stack[] = $child;
				}
				continue;
			}

			if ( is_scalar( $current ) ) {
				if ( ! self::match_removed_reference( (string) $current, $tokens, $kind, $found, $comparisons_remaining ) ) {
					$truncated = true;
					break;
				}
			}
		}
	}

	/**
	 * Match one scalar against removed style-scope tokens.
	 *
	 * @since 2.4
	 *
	 * @param string $candidate Candidate scalar.
	 * @param array  $tokens    Removed tokens.
	 * @param string $kind      Token kind.
	 * @param array  $found     Found-token map.
	 * @param int    $remaining Remaining token comparisons.
	 * @return bool Whether every token was inspected.
	 */
	private static function match_removed_reference( string $candidate, array $tokens, string $kind, array &$found, int &$remaining ): bool {
		foreach ( $tokens as $token ) {
			if ( $remaining <= 0 ) {
				return false;
			}
			--$remaining;
			$token   = (string) $token;
			$pattern = $kind === 'breakpoint'
				? '/(?:^|[:_])' . preg_quote( $token, '/' ) . '(?:$|[:_])/i'
				: '/' . preg_quote( $token, '/' ) . '(?:$|:)/i';

			if ( $candidate === $token || preg_match( $pattern, $candidate ) ) {
				$found[ $token ] = true;
			}
		}

		return true;
	}

}

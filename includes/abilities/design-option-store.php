<?php
/**
 * Conflict-safe persistence for Bricks design options.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists complete option before-images with an exact database compare-and-swap.
 *
 * This is intentionally lower-level than update_option(): design abilities must
 * prepare a complete replacement value in PHP, but may not silently overwrite a
 * Builder or importer write that lands between their read and save.
 */
class Design_Option_Store {
	/**
	 * Read the exact stored option row, including whether it exists.
	 *
	 * Reading the serialized database value instead of normalizing through
	 * get_option() preserves associative array keys and distinguishes a missing
	 * row from a row whose value equals the caller's default.
	 *
	 * @since 2.4
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Value returned in the snapshot when the row is absent.
	 * @return array|\WP_Error
	 */
	public static function read( string $option, $default = false ) {
		$site_id = self::authority_site_id( $option );

		return self::on_site(
			$site_id,
			static function () use ( $option, $default, $site_id ) {
				return self::read_current_site( $option, $default, $site_id );
			}
		);
	}

	/**
	 * Re-read an option from the authority site captured by an earlier snapshot.
	 *
	 * Compensation must not follow a changed multisite authority to another
	 * options table. This pins the read to the same site as the original write.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Earlier exact option snapshot.
	 * @param mixed $default  Value returned when the option row is absent.
	 * @return array|\WP_Error
	 */
	public static function read_from_snapshot( array $snapshot, $default = false ) {
		$option  = isset( $snapshot['option'] ) ? (string) $snapshot['option'] : '';
		$site_id = isset( $snapshot['siteId'] ) ? (int) $snapshot['siteId'] : 0;

		if ( $option === '' || $site_id <= 0 ) {
			return Error::internal_error(
				'design-option-snapshot',
				'The design option snapshot does not identify its authority.',
				[
					'option' => $option,
					'siteId' => $site_id,
				]
			);
		}

		return self::on_site(
			$site_id,
			static function () use ( $option, $default, $site_id ) {
				return self::read_current_site( $option, $default, $site_id );
			}
		);
	}

	/**
	 * Read an option and its authority's optimistic version in one SQL snapshot.
	 *
	 * @since 2.4
	 *
	 * @param string $option         Option name.
	 * @param string $version_option Authority-local version option.
	 * @param mixed  $default        Value returned when the option row is absent.
	 * @return array|\WP_Error
	 */
	public static function read_versioned( string $option, string $version_option, $default = false ) {
		$site_id = self::authority_site_id( $option );

		return self::on_site(
			$site_id,
			static function () use ( $option, $version_option, $default, $site_id ) {
				return self::read_versioned_current_site( $option, $version_option, $default, $site_id );
			}
		);
	}

	/**
	 * Re-read an option and version from an earlier snapshot's authority.
	 *
	 * @since 2.4
	 *
	 * @param array  $snapshot       Earlier exact option snapshot.
	 * @param string $version_option Authority-local version option.
	 * @param mixed  $default        Value returned when the option row is absent.
	 * @return array|\WP_Error
	 */
	public static function read_versioned_from_snapshot( array $snapshot, string $version_option, $default = false ) {
		$option  = isset( $snapshot['option'] ) ? (string) $snapshot['option'] : '';
		$site_id = isset( $snapshot['siteId'] ) ? (int) $snapshot['siteId'] : 0;

		if ( $option === '' || $site_id <= 0 ) {
			return Error::internal_error(
				'design-option-snapshot',
				'The design option snapshot does not identify its authority.',
				[
					'option' => $option,
					'siteId' => $site_id,
				]
			);
		}

		return self::on_site(
			$site_id,
			static function () use ( $option, $version_option, $default, $site_id ) {
				return self::read_versioned_current_site( $option, $version_option, $default, $site_id );
			}
		);
	}

	/**
	 * Create a compact ownership envelope from an exact versioned snapshot.
	 *
	 * @since 2.4
	 *
	 * @param string $resource Stable public resource name.
	 * @param array  $snapshot Exact snapshot returned by read_versioned().
	 * @return array|\WP_Error
	 */
	public static function ownership_envelope( string $resource, array $snapshot ) {
		if (
			$resource === '' ||
			! isset( $snapshot['siteId'] ) ||
			! array_key_exists( 'designSystemVersion', $snapshot )
		) {
			return Error::internal_error(
				'design-ownership',
				'The design ownership snapshot is incomplete.',
				[ 'resource' => $resource ]
			);
		}

		$resource_digest = self::resource_digest( $snapshot );

		if ( is_wp_error( $resource_digest ) ) {
			return $resource_digest;
		}

		return [
			'resource'       => $resource,
			'siteId'         => (int) $snapshot['siteId'],
			'version'        => (int) $snapshot['designSystemVersion'],
			'resourceDigest' => $resource_digest,
		];
	}

	/**
	 * Digest the exact stored authority row, including absent-row state.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Exact option snapshot.
	 * @return string|\WP_Error
	 */
	public static function resource_digest( array $snapshot ) {
		if ( ! array_key_exists( 'exists', $snapshot ) || ! array_key_exists( 'raw', $snapshot ) ) {
			return Error::internal_error(
				'design-ownership',
				'The design resource snapshot has no exact stored before-image.'
			);
		}

		$exists = (bool) $snapshot['exists'];
		$raw    = $exists ? (string) $snapshot['raw'] : '';

		return hash( 'sha256', ( $exists ? '1' : '0' ) . "\0" . $raw );
	}

	/**
	 * Digest a complete unredacted item with deterministic map ordering.
	 *
	 * Associative keys are canonicalized recursively, while indexed list order
	 * remains meaningful.
	 *
	 * @since 2.4
	 *
	 * @param mixed $item Complete authoritative item.
	 * @return string
	 */
	public static function item_digest( $item ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical_digest_value( $item ) ) );
	}

	/**
	 * Validate an ownership envelope and optional item digest.
	 *
	 * @since 2.4
	 *
	 * @param string $resource Stable public resource name.
	 * @param array  $snapshot Current exact versioned snapshot.
	 * @param array  $expected Expected ownership envelope, optionally with itemDigest.
	 * @param mixed  $item     Current complete item, or resolver receiving the resource value.
	 * @return true|\WP_Error
	 */
	public static function validate_ownership( string $resource, array $snapshot, array $expected, $item = null ) {
		$actual = self::ownership_envelope( $resource, $snapshot );

		if ( is_wp_error( $actual ) ) {
			return $actual;
		}

		$mismatches = [];

		foreach ( [ 'resource', 'siteId', 'version', 'resourceDigest' ] as $field ) {
			if ( ! array_key_exists( $field, $expected ) || (string) $expected[ $field ] !== (string) $actual[ $field ] ) {
				$mismatches[] = $field;
			}
		}

		$actual_item_digest = '';

		if ( array_key_exists( 'itemDigest', $expected ) ) {
			$authoritative_item = is_callable( $item ) ? call_user_func( $item, $snapshot['value'] ?? null ) : $item;
			$actual_item_digest = self::item_digest( $authoritative_item );

			if ( ! is_string( $expected['itemDigest'] ) || ! hash_equals( $expected['itemDigest'], $actual_item_digest ) ) {
				$mismatches[] = 'itemDigest';
			}
		}

		if ( empty( $mismatches ) ) {
			return true;
		}

		return Error::conflict(
			'design_ownership',
			[
				'message'                => 'The design resource ownership changed. Re-read the authoritative resource and retry.',
				'resource'               => $resource,
				'mismatches'             => $mismatches,
				'expectedSiteId'         => isset( $expected['siteId'] ) ? (int) $expected['siteId'] : null,
				'actualSiteId'           => $actual['siteId'],
				'expectedVersion'        => isset( $expected['version'] ) ? (int) $expected['version'] : null,
				'actualVersion'          => $actual['version'],
				'expectedResourceDigest' => isset( $expected['resourceDigest'] ) ? (string) $expected['resourceDigest'] : '',
				'actualResourceDigest'   => $actual['resourceDigest'],
				'expectedItemDigest'     => isset( $expected['itemDigest'] ) ? (string) $expected['itemDigest'] : '',
				'actualItemDigest'       => $actual_item_digest,
			]
		);
	}

	/**
	 * Validate ownership, then persist through a version-bound exact-raw CAS.
	 *
	 * @since 2.4
	 *
	 * @param string $resource       Stable public resource name.
	 * @param array  $snapshot       Snapshot used to prepare the mutation.
	 * @param mixed  $value          Replacement option value.
	 * @param string $version_option Authority-local version option.
	 * @param array  $expected       Expected ownership envelope.
	 * @param mixed  $item           Current complete item, or resolver receiving the resource value.
	 * @param bool   $delete         Whether to delete the resource row.
	 * @param array  $related_snapshots Exact related rows required by the final SQL.
	 * @return array|\WP_Error
	 */
	public static function compare_and_swap_owned(
		string $resource,
		array $snapshot,
		$value,
		string $version_option,
		array $expected,
		$item = null,
		bool $delete = false,
		array $related_snapshots = []
	) {
		$default = array_key_exists( 'value', $snapshot ) ? $snapshot['value'] : false;
		$current = self::read_versioned_from_snapshot( $snapshot, $version_option, $default );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$valid = self::validate_ownership( $resource, $current, $expected, $item );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$guards_valid = self::validate_related_snapshots( $current, $related_snapshots );

		if ( is_wp_error( $guards_valid ) ) {
			return $guards_valid;
		}

		return self::compare_and_swap(
			$current,
			$value,
			$delete,
			$version_option,
			(int) $current['designSystemVersion'],
			$related_snapshots
		);
	}

	/**
	 * Read a related option from the same authority site as an anchor option.
	 *
	 * @since 2.4
	 *
	 * @param string $anchor_option Authority-defining design option.
	 * @param string $option        Related option.
	 * @param mixed  $default       Missing-row value.
	 * @return array|\WP_Error
	 */
	public static function read_related( string $anchor_option, string $option, $default = false ) {
		$site_id = self::authority_site_id( $anchor_option );

		return self::on_site(
			$site_id,
			static function () use ( $option, $default, $site_id ) {
				return self::read_current_site( $option, $default, $site_id );
			}
		);
	}

	/**
	 * Update a related option on the same authority site as an anchor option.
	 *
	 * @since 2.4
	 *
	 * @param string $anchor_option Authority-defining design option.
	 * @param string $option        Related option.
	 * @param mixed  $value         New value.
	 * @return bool|\WP_Error
	 */
	public static function update_related( string $anchor_option, string $option, $value ) {
		$site_id = self::authority_site_id( $anchor_option );

		return self::on_site(
			$site_id,
			static function () use ( $option, $value ) {
				return update_option( $option, $value );
			}
		);
	}

	/**
	 * Resolve the runtime authority site for a design option.
	 *
	 * @since 2.4
	 *
	 * @param string $option Option name.
	 * @return int
	 */
	public static function authority_site_id( string $option ): int {
		$current_site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return $current_site_id;
		}

		$main_site_options = [
			'BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES'       => [
				'BRICKS_DB_GLOBAL_CLASSES',
				'BRICKS_DB_GLOBAL_CLASSES_LOCKED',
				'BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP',
				'BRICKS_DB_GLOBAL_CLASSES_USER',
				'BRICKS_DB_GLOBAL_CLASSES_TRASH',
				'BRICKS_DB_PSEUDO_CLASSES',
			],
			'BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES_CATEGORIES' => [ 'BRICKS_DB_GLOBAL_CLASSES_CATEGORIES' ],
			'BRICKS_MULTISITE_USE_MAIN_SITE_COMPONENTS'    => [ 'BRICKS_DB_COMPONENTS' ],
			'BRICKS_MULTISITE_USE_MAIN_SITE_VARIABLES'     => [ 'BRICKS_DB_GLOBAL_VARIABLES' ],
			'BRICKS_MULTISITE_USE_MAIN_SITE_VARIABLES_CATEGORIES' => [ 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES' ],
			'BRICKS_MULTISITE_USE_MAIN_SITE_COLOR_PALETTE' => [ 'BRICKS_DB_COLOR_PALETTE' ],
		];

		foreach ( $main_site_options as $flag => $option_constants ) {
			if ( ! defined( $flag ) || ! constant( $flag ) ) {
				continue;
			}

			foreach ( $option_constants as $option_constant ) {
				if ( defined( $option_constant ) && $option === constant( $option_constant ) ) {
					return (int) get_main_site_id();
				}
			}
		}

		return $current_site_id;
	}

	/**
	 * Read one option from the already-selected authority site.
	 *
	 * @since 2.4
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Missing-row value.
	 * @param int    $site_id Authority site ID.
	 * @return array|\WP_Error
	 */
	private static function read_current_site( string $option, $default, int $site_id ) {
		global $wpdb;

		$wpdb->last_error = '';
		$row              = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An exact uncached before-image is required for compare-and-swap.
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option
			),
			ARRAY_A
		);

		if ( $row === null && $wpdb->last_error !== '' ) {
			return self::database_error( $option, 'read', $wpdb->last_error );
		}

		$exists = is_array( $row );
		$raw    = $exists ? (string) $row['option_value'] : null;

		return [
			'option'   => $option,
			'exists'   => $exists,
			'raw'      => $raw,
			'autoload' => $exists ? (string) $row['autoload'] : null,
			'value'    => $exists ? maybe_unserialize( $raw ) : $default,
			'siteId'   => $site_id,
		];
	}

	/**
	 * Read one option row and version row from the same database read view.
	 *
	 * @since 2.4
	 *
	 * @param string $option         Option name.
	 * @param string $version_option Version option.
	 * @param mixed  $default        Missing-row value.
	 * @param int    $site_id        Authority site ID.
	 * @return array|\WP_Error
	 */
	private static function read_versioned_current_site( string $option, string $version_option, $default, int $site_id ) {
		global $wpdb;

		$wpdb->last_error = '';
		$row              = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Payload and optimistic version must share one database read view.
			$wpdb->prepare(
				"SELECT target.option_name AS target_name, target.option_value, target.autoload,
					COALESCE(CAST(design_version.option_value AS UNSIGNED), 0) AS design_system_version
				FROM (SELECT 1) AS snapshot
				LEFT JOIN {$wpdb->options} AS target ON target.option_name = %s
				LEFT JOIN {$wpdb->options} AS design_version ON design_version.option_name = %s
				LIMIT 1",
				$option,
				$version_option
			),
			ARRAY_A
		);

		if ( $row === null ) {
			if ( $wpdb->last_error !== '' ) {
				return self::database_error( $option, 'versioned_read', $wpdb->last_error );
			}

			return self::database_error( $option, 'versioned_read', 'The versioned option snapshot returned no row.' );
		}

		$exists = $row['target_name'] !== null;
		$raw    = $exists ? (string) $row['option_value'] : null;

		return [
			'option'              => $option,
			'exists'              => $exists,
			'raw'                 => $raw,
			'autoload'            => $exists ? (string) $row['autoload'] : null,
			'value'               => $exists ? maybe_unserialize( $raw ) : $default,
			'siteId'              => $site_id,
			'designSystemVersion' => (int) $row['design_system_version'],
		];
	}

	/**
	 * Persist a replacement value only if the option still matches a snapshot.
	 *
	 * The optional delete flag preserves normal WordPress absent-row semantics
	 * for stores such as global classes and variables, where an empty store is
	 * represented by deleting the option rather than serializing an empty array.
	 *
	 * @since 2.4
	 *
	 * @param array    $snapshot Exact snapshot returned by read().
	 * @param mixed    $value             Replacement option value.
	 * @param bool     $delete            Whether to delete the row.
	 * @param string   $version_option   Optional authority-local version option.
	 * @param int|null $expected_version Optional expected version.
	 * @param array    $related_snapshots Exact related rows guarded in the final SQL.
	 * @return array|\WP_Error
	 */
	public static function compare_and_swap( array $snapshot, $value, bool $delete = false, string $version_option = '', $expected_version = null, array $related_snapshots = [] ) {
		$site_id      = isset( $snapshot['siteId'] ) ? (int) $snapshot['siteId'] : self::authority_site_id( (string) ( $snapshot['option'] ?? '' ) );
		$guards_valid = self::validate_related_snapshot_authorities( $site_id, $related_snapshots );

		if ( is_wp_error( $guards_valid ) ) {
			return $guards_valid;
		}

		return self::on_site(
			$site_id,
			static function () use ( $snapshot, $value, $delete, $version_option, $expected_version, $related_snapshots ) {
				return self::compare_and_swap_current_site( $snapshot, $value, $delete, $version_option, $expected_version, $related_snapshots );
			}
		);
	}

	/**
	 * Persist on the already-selected authority site.
	 *
	 * @since 2.4
	 *
	 * @param array    $snapshot        Exact option snapshot.
	 * @param mixed    $value           Replacement value.
	 * @param bool     $delete          Whether to delete.
	 * @param string   $version_option  Optional version option checked in the same statement.
	 * @param int|null $expected_version Expected version.
	 * @param array    $related_snapshots Exact related rows guarded in the same statement.
	 * @return array|\WP_Error
	 */
	private static function compare_and_swap_current_site( array $snapshot, $value, bool $delete, string $version_option, $expected_version, array $related_snapshots ) {
		global $wpdb;

		$option = isset( $snapshot['option'] ) ? (string) $snapshot['option'] : '';

		if (
			$option === '' ||
			! array_key_exists( 'exists', $snapshot ) ||
			! array_key_exists( 'raw', $snapshot ) ||
			! array_key_exists( 'value', $snapshot )
		) {
			return Error::internal_error(
				'design-option-cas',
				'The design option snapshot is incomplete.',
				[ 'option' => $option ]
			);
		}

		wp_protect_special_option( $option );

		$exists       = (bool) $snapshot['exists'];
		$bind_version = $version_option !== '' && $expected_version !== null;
		$guard        = self::related_guard_sql( $related_snapshots );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Guard fragments and their argument lists are generated only from exact
		// internal snapshots; PHPCS cannot count these dynamic placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $delete ) {
			if ( ! $exists ) {
				return self::result( 'no_change', false );
			}

			do_action( 'delete_option', $option );

			$wpdb->last_error = '';

			if ( $bind_version ) {
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete and explicit cache invalidation implement the CAS.
					$wpdb->prepare(
						"DELETE target FROM {$wpdb->options} AS target
						LEFT JOIN {$wpdb->options} AS design_version ON design_version.option_name = %s
						{$guard['joins']}
						WHERE target.option_name = %s
						AND BINARY target.option_value = BINARY %s
						AND COALESCE(CAST(design_version.option_value AS UNSIGNED), 0) = %d
						{$guard['where']}",
						...array_merge(
							[ $version_option ],
							$guard['args'],
							[
								$option,
								(string) $snapshot['raw'],
								(int) $expected_version,
							]
						)
					)
				);
			} else {
				$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete and explicit cache invalidation implement the CAS.
					$wpdb->prepare(
						"DELETE target FROM {$wpdb->options} AS target
						{$guard['joins']}
						WHERE target.option_name = %s AND BINARY target.option_value = BINARY %s
						{$guard['where']}",
						...array_merge( $guard['args'], [ $option, (string) $snapshot['raw'] ] )
					)
				);
			}

			if ( $deleted === false ) {
				return self::database_error( $option, 'delete', $wpdb->last_error );
			}

			if ( $deleted !== 1 ) {
				return self::conflict_error( $snapshot );
			}

			self::invalidate_cache( $option );
			do_action( "delete_option_{$option}", $option );
			do_action( 'deleted_option', $option );

			return self::result( 'deleted', null );
		}

		$old_value = self::filtered_old_value( $snapshot );

		if ( is_object( $value ) ) {
			$value = clone $value;
		}

		$value = sanitize_option( $option, $value );
		$value = apply_filters( "pre_update_option_{$option}", $value, $old_value, $option );
		$value = apply_filters( 'pre_update_option', $value, $option, $old_value );

		// update_option() delegates an absent row to add_option(), which clones
		// object values and sanitizes a second time before serialization.
		if ( ! $exists ) {
			if ( is_object( $value ) ) {
				$value = clone $value;
			}

			$value = sanitize_option( $option, $value );
		}

		$serialized = (string) maybe_serialize( $value );

		if ( $exists && ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) ) {
			return self::result( 'no_change', $value );
		}

		if ( $exists ) {
			do_action( 'update_option', $option, $old_value, $value );

			$wpdb->last_error = '';

			if ( $bind_version ) {
				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update and explicit cache invalidation implement the CAS.
					$wpdb->prepare(
						"UPDATE {$wpdb->options} AS target
						LEFT JOIN {$wpdb->options} AS design_version ON design_version.option_name = %s
						{$guard['joins']}
						SET target.option_value = %s
						WHERE target.option_name = %s
						AND BINARY target.option_value = BINARY %s
						AND COALESCE(CAST(design_version.option_value AS UNSIGNED), 0) = %d
						{$guard['where']}",
						...array_merge(
							[ $version_option ],
							$guard['args'],
							[
								$serialized,
								$option,
								(string) $snapshot['raw'],
								(int) $expected_version,
							]
						)
					)
				);
			} else {
				$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update and explicit cache invalidation implement the CAS.
					$wpdb->prepare(
						"UPDATE {$wpdb->options} AS target
						{$guard['joins']}
						SET target.option_value = %s
						WHERE target.option_name = %s AND BINARY target.option_value = BINARY %s
						{$guard['where']}",
						...array_merge( $guard['args'], [ $serialized, $option, (string) $snapshot['raw'] ] )
					)
				);
			}

			if ( $updated === false ) {
				return self::database_error( $option, 'update', $wpdb->last_error );
			}

			if ( $updated !== 1 ) {
				return self::conflict_error( $snapshot );
			}

			self::invalidate_cache( $option );
			do_action( "update_option_{$option}", $old_value, $value, $option );
			do_action( 'updated_option', $option, $old_value, $value );

			return self::result( 'updated', $value );
		}

		// Core checks the dynamic default again before delegating an absent
		// option to add_option(). If it changed, core does not insert a row.
		$current_default = apply_filters( "default_option_{$option}", false, $option, false );

		if ( $current_default !== $old_value ) {
			return Error::conflict(
				'design_option_default_changed',
				[
					'message' => sprintf( 'The filtered default for design option "%s" changed while the write was being prepared. No row was inserted.', $option ),
					'option'  => $option,
				]
			);
		}

		$autoload             = self::autoload_value( $option, $value, $serialized );
		$insert_version_join  = '';
		$insert_version_where = '';
		$insert_version_args  = [];

		if ( $bind_version ) {
			$insert_version_join  = " LEFT JOIN {$wpdb->options} AS design_insert_version ON design_insert_version.option_name = %s";
			$insert_version_where = ' AND COALESCE(CAST(design_insert_version.option_value AS UNSIGNED), 0) = %d';
			$insert_version_args  = [ $version_option ];
		}

		do_action( 'add_option', $option, $value );

		$wpdb->last_error = '';
		$added            = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE distinguishes an absent-row race and cache is invalidated below.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
				SELECT %s, %s, %s FROM (SELECT 1) AS guarded_insert
				{$insert_version_join}
				{$guard['joins']}
				WHERE 1 = 1
				{$insert_version_where}
				{$guard['where']}",
				...array_merge(
					[ $option, $serialized, $autoload ],
					$insert_version_args,
					$guard['args'],
					$bind_version ? [ (int) $expected_version ] : []
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $added === false ) {
			return self::database_error( $option, 'insert', $wpdb->last_error );
		}

		if ( $added !== 1 ) {
			return self::conflict_error( $snapshot );
		}

		self::invalidate_cache( $option );
		do_action( "add_option_{$option}", $option, $value );
		do_action( 'added_option', $option, $value );

		return self::result( 'added', $value );
	}

	/**
	 * Determine the autoload marker WordPress would use for a new option.
	 *
	 * @since 2.4
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Unserialized value.
	 * @param string $serialized Serialized value.
	 * @return string
	 */
	private static function autoload_value( string $option, $value, string $serialized ): string {
		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			return (string) wp_determine_option_autoload_value( $option, $value, $serialized, null );
		}

		return 'yes';
	}

	/**
	 * Invalidate every option cache bucket that can retain this row.
	 *
	 * @since 2.4
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private static function invalidate_cache( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Reproduce get_option() retrieval filters for the snapshot value.
	 *
	 * The database before-image remains unfiltered for CAS, while write filters
	 * and actions receive the same semantic old value as update_option().
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Exact option snapshot.
	 * @return mixed
	 */
	private static function filtered_old_value( array $snapshot ) {
		$option = (string) $snapshot['option'];
		$pre    = apply_filters( "pre_option_{$option}", false, $option, false );
		$pre    = apply_filters( 'pre_option', $pre, $option, false );

		if ( $pre !== false ) {
			return $pre;
		}

		if ( ! empty( $snapshot['exists'] ) ) {
			return apply_filters( "option_{$option}", $snapshot['value'], $option );
		}

		return apply_filters( "default_option_{$option}", false, $option, false );
	}

	/**
	 * Validate related snapshots against their pinned authority before writing.
	 *
	 * @since 2.4
	 *
	 * @param array $target_snapshot  Target resource snapshot.
	 * @param array $related_snapshots Related exact snapshots.
	 * @return true|\WP_Error
	 */
	private static function validate_related_snapshots( array $target_snapshot, array $related_snapshots ) {
		$site_id = isset( $target_snapshot['siteId'] ) ? (int) $target_snapshot['siteId'] : 0;
		$valid   = self::validate_related_snapshot_authorities( $site_id, $related_snapshots );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		foreach ( $related_snapshots as $related ) {
			$default = array_key_exists( 'value', $related ) ? $related['value'] : false;
			$current = self::read_from_snapshot( $related, $default );

			if ( is_wp_error( $current ) ) {
				return $current;
			}

			if (
				(bool) $current['exists'] !== (bool) $related['exists'] ||
				(string) $current['raw'] !== (string) $related['raw']
			) {
				return Error::conflict(
					'design_option_related_write',
					[
						'message' => sprintf( 'The related design option "%s" changed while this write was being prepared.', $related['option'] ),
						'option'  => $related['option'],
						'siteId'  => $site_id,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Fail closed when a related guard belongs to another authority.
	 *
	 * @since 2.4
	 *
	 * @param int   $site_id          Target authority.
	 * @param array $related_snapshots Related exact snapshots.
	 * @return true|\WP_Error
	 */
	private static function validate_related_snapshot_authorities( int $site_id, array $related_snapshots ) {
		foreach ( $related_snapshots as $related ) {
			$option           = isset( $related['option'] ) ? (string) $related['option'] : '';
			$related_site     = isset( $related['siteId'] ) ? (int) $related['siteId'] : 0;
			$has_before_image = array_key_exists( 'exists', $related ) && array_key_exists( 'raw', $related );

			if ( $option === '' || ! $has_before_image ) {
				return Error::internal_error(
					'design-option-guard',
					'The related design option guard is incomplete.',
					[ 'option' => $option ]
				);
			}

			if ( $site_id <= 0 || $related_site !== $site_id ) {
				return Error::conflict(
					'design_option_guard_authority',
					[
						'message'       => 'A related design option guard cannot be enforced across authority sites.',
						'option'        => $option,
						'targetSiteId'  => $site_id,
						'relatedSiteId' => $related_site,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Build exact present/absent related-row predicates for a final CAS.
	 *
	 * @since 2.4
	 *
	 * @param array $related_snapshots Related exact snapshots.
	 * @return array|\WP_Error
	 */
	private static function related_guard_sql( array $related_snapshots ) {
		global $wpdb;

		$joins = '';
		$where = '';
		$args  = [];

		foreach ( array_values( $related_snapshots ) as $index => $related ) {
			$alias  = 'design_guard_' . $index;
			$option = (string) $related['option'];

			if ( ! empty( $related['exists'] ) ) {
				$joins .= " INNER JOIN {$wpdb->options} AS {$alias} ON {$alias}.option_name = %s AND BINARY {$alias}.option_value = BINARY %s";
				$args[] = $option;
				$args[] = (string) $related['raw'];
			} else {
				$joins .= " LEFT JOIN {$wpdb->options} AS {$alias} ON {$alias}.option_name = %s";
				$where .= " AND {$alias}.option_name IS NULL";
				$args[] = $option;
			}
		}

		return [
			'joins' => $joins,
			'where' => $where,
			'args'  => $args,
		];
	}

	/**
	 * Convert a value to a type-preserving canonical digest tree.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return array
	 */
	private static function canonical_digest_value( $value ): array {
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				return [
					'type'  => 'list',
					'value' => array_map( [ self::class, 'canonical_digest_value' ], $value ),
				];
			}

			$entries = [];
			$keys    = array_keys( $value );
			usort(
				$keys,
				static function ( $left, $right ) {
					$left_key  = ( is_int( $left ) ? 'i:' : 's:' ) . (string) $left;
					$right_key = ( is_int( $right ) ? 'i:' : 's:' ) . (string) $right;
					return strcmp( $left_key, $right_key );
				}
			);

			foreach ( $keys as $key ) {
				$entries[] = [
					'keyType' => is_int( $key ) ? 'integer' : 'string',
					'key'     => $key,
					'value'   => self::canonical_digest_value( $value[ $key ] ),
				];
			}

			return [
				'type'  => 'map',
				'value' => $entries,
			];
		}

		if ( is_object( $value ) ) {
			return [
				'type'       => 'object',
				'class'      => get_class( $value ),
				'properties' => self::canonical_digest_value( (array) $value ),
			];
		}

		if ( is_float( $value ) ) {
			return [
				'type'  => 'float',
				'value' => bin2hex( pack( 'E', $value ) ),
			];
		}

		if ( is_int( $value ) ) {
			return [
				'type'  => 'integer',
				'value' => (string) $value,
			];
		}

		if ( is_bool( $value ) ) {
			return [
				'type'  => 'boolean',
				'value' => $value,
			];
		}

		if ( $value === null ) {
			return [ 'type' => 'null' ];
		}

		return [
			'type'  => is_resource( $value ) ? 'resource' : 'string',
			'value' => (string) $value,
		];
	}

	/**
	 * Determine whether an array has consecutive zero-based integer keys.
	 *
	 * @since 2.4
	 *
	 * @param array $value Candidate array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		$expected = 0;

		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}

			++$expected;
		}

		return true;
	}

	/**
	 * Build a successful persistence result.
	 *
	 * @since 2.4
	 *
	 * @param string $status Persistence status.
	 * @param mixed  $value  Saved value.
	 * @return array
	 */
	private static function result( string $status, $value ): array {
		return [
			'success' => true,
			'status'  => $status,
			'value'   => $value,
		];
	}

	/**
	 * Return a structured conflict after an exact-before-image mismatch.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Expected option snapshot.
	 * @return \WP_Error
	 */
	private static function conflict_error( array $snapshot ): \WP_Error {
		$current = self::read( (string) $snapshot['option'] );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		return Error::conflict(
			'design_option_write',
			[
				'message'        => sprintf( 'The design option "%s" changed while this write was being prepared. Re-read it and retry.', $snapshot['option'] ),
				'option'         => $snapshot['option'],
				'expectedExists' => (bool) $snapshot['exists'],
				'actualExists'   => (bool) $current['exists'],
				'expectedDigest' => hash( 'sha256', (string) $snapshot['raw'] ),
				'actualDigest'   => hash( 'sha256', (string) $current['raw'] ),
			]
		);
	}

	/**
	 * Return a structured database failure distinct from a write conflict.
	 *
	 * @since 2.4
	 *
	 * @param string $option Option name.
	 * @param string $stage  Failed persistence stage.
	 * @param string $detail Database error detail.
	 * @return \WP_Error
	 */
	private static function database_error( string $option, string $stage, string $detail ): \WP_Error {
		return Error::internal_error(
			'design-option-cas',
			sprintf( 'The design option "%s" could not be persisted because the database operation failed.', $option ),
			[
				'option'        => $option,
				'stage'         => $stage,
				'databaseError' => $detail,
			]
		);
	}

	/**
	 * Execute against one site's options table and restore the caller's site.
	 *
	 * @since 2.4
	 *
	 * @param int      $site_id  Target site ID.
	 * @param callable $callback Operation.
	 * @return mixed
	 */
	private static function on_site( int $site_id, callable $callback ) {
		$current_site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : $site_id;
		$switched        = $site_id > 0 && $site_id !== $current_site_id;

		if ( $switched ) {
			if ( ! function_exists( 'switch_to_blog' ) || ! switch_to_blog( $site_id ) ) {
				return Error::internal_error(
					'design-option-authority',
					sprintf( 'Could not switch to the authority site %d for the design option write.', $site_id ),
					[ 'siteId' => $site_id ]
				);
			}
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}

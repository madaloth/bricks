<?php
/**
 * Global Pseudo-Class abilities
 *
 * Read and write `BRICKS_DB_PSEUDO_CLASSES` - the list of extra
 * pseudo-class selectors available throughout the builder style panel.
 *
 * Storage shape: a flat array of string selectors, e.g.
 *   [ ':hover', ':active', ':focus', ':focus-visible' ]
 *
 * Defaults to `[':hover', ':active', ':focus']` when the option is
 * empty. Write path mirrors the admin UI's save-pseudo-classes AJAX
 * action (gated on the `access_pseudo_selectors` builder permission).
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pseudo_Classes {
	/**
	 * Maximum selectors accepted by the agent write contract.
	 */
	const MAX_PSEUDO_CLASSES = 100;

	/**
	 * Maximum selector length accepted by the agent write contract.
	 */
	const MAX_PSEUDO_CLASS_LENGTH = 512;

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
	 * Maximum selector comparisons performed inside one design record.
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
	 * Stable ownership resource name.
	 */
	const OWNERSHIP_RESOURCE = 'pseudoClasses';

	/**
	 * Default pseudo-classes used when the option is absent.
	 *
	 * Kept in sync with `Database::get_global_data()`'s fallback list.
	 */
	const DEFAULTS = [ ':hover', ':active', ':focus' ];

	/**
	 * Input schema for listing pseudo-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_pseudo_classes_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Output schema for listing pseudo-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_pseudo_classes_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'pseudoClasses' => [ 'type' => 'array' ],
				'ownership'     => [ 'type' => 'object' ],
				'isDefault'     => [
					'type'        => 'boolean',
					'description' => __( 'True when the option is unset and Bricks is using the built-in default list.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Input schema for replacing pseudo-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_pseudo_classes_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'pseudoClasses', 'expectedOwnership' ],
			'properties' => [
				'pseudoClasses'             => [
					'type'        => [ 'array', 'null' ],
					'description' => __( 'Full-replacement list of pseudo-class selectors. Each entry must start with `:` (for example `[":hover", ":active", ":focus", ":focus-visible"]`). Functional arguments may contain balanced selector syntax, but CSS block delimiters, declarations, comments, controls, and malformed nesting are rejected. An empty array or `null` resets to the built-in defaults, and duplicates are removed.', 'bricks' ),
					'maxItems'    => self::MAX_PSEUDO_CLASSES,
					'items'       => [
						'type'      => 'string',
						'maxLength' => self::MAX_PSEUDO_CLASS_LENGTH,
					],
				],
				'expectedOwnership'         => [
					'type'        => 'object',
					'description' => __( 'Required ownership envelope returned by the latest `bricks/list-pseudo-classes` or `bricks/set-pseudo-classes` response.', 'bricks' ),
				],
				'allowRemovedPseudoClasses' => [
					'type'        => 'boolean',
					'description' => __( 'Required and must be literal true when the replacement removes any current selector. This acknowledges that existing style settings can retain removed-selector suffixes. Additive and reorder-only edits do not require it.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for replacing pseudo-classes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_pseudo_classes_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'              => [ 'type' => 'boolean' ],
				'changed'              => [ 'type' => 'boolean' ],
				'pseudoClasses'        => [ 'type' => 'array' ],
				'ownership'            => [ 'type' => 'object' ],
				'removedPseudoClasses' => [ 'type' => 'array' ],
				'removalUsageEvidence' => [ 'type' => [ 'object', 'null' ] ],
			],
		];
	}

	/**
	 * Permission: read - builder access with `access_pseudo_selectors`.
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
		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_pseudo_selectors' ) ) {
			return Error::forbidden_builder_permission( 'access_pseudo_selectors' );
		}
		return true;
	}

	/**
	 * Permission: write - same builder permission as pseudo-class reads.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	/**
	 * List the effective pseudo-classes and their ownership envelope.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Pseudo-class state, or an authority read error.
	 */
	public static function list_pseudo_classes( $input ) {
		Manager::flush_options_cache();

		$snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_PSEUDO_CLASSES,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			null
		);

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$ownership = Design_Option_Store::ownership_envelope( self::OWNERSHIP_RESOURCE, $snapshot );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$raw = $snapshot['value'];

		if ( is_array( $raw ) && count( $raw ) ) {
			return [
				'pseudoClasses' => array_values( array_unique( array_map( 'strval', $raw ) ) ),
				'ownership'     => $ownership,
				'isDefault'     => false,
			];
		}
		return [
			'pseudoClasses' => self::DEFAULTS,
			'ownership'     => $ownership,
			'isDefault'     => true,
		];
	}

	/**
	 * Replace pseudo-classes with ownership and removal safeguards.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Write result, validation error, or conflict.
	 */
	public static function set_pseudo_classes( $input ) {
		$rows               = $input['pseudoClasses'] ?? null;
		$expected_ownership = $input['expectedOwnership'] ?? null;

		if ( ! is_array( $expected_ownership ) ) {
			return Error::invalid_param( 'expectedOwnership', 'ownership envelope from the latest pseudo-class read or write', $expected_ownership );
		}

		if ( $rows !== null && ! is_array( $rows ) ) {
			return Error::invalid_param( 'pseudoClasses', 'array of selector strings', $rows );
		}
		if ( is_array( $rows ) && count( $rows ) > self::MAX_PSEUDO_CLASSES ) {
			return Error::invalid_param( 'pseudoClasses', 'no more than ' . self::MAX_PSEUDO_CLASSES . ' selector strings', [ 'count' => count( $rows ) ] );
		}

		$clean = [];
		foreach ( (array) $rows as $index => $row ) {
			if ( ! is_string( $row ) ) {
				return Error::invalid_param( "pseudoClasses[$index]", 'string', $row );
			}
			$sel = trim( $row );
			if ( $sel === '' ) {
				continue;
			}
			if ( strlen( $sel ) > self::MAX_PSEUDO_CLASS_LENGTH ) {
				return Error::invalid_param( "pseudoClasses[$index]", 'selector no longer than ' . self::MAX_PSEUDO_CLASS_LENGTH . ' bytes', $sel );
			}
			if ( ! self::is_valid_pseudo_class( $sel ) ) {
				return Error::invalid_param( "pseudoClasses[$index]", 'pseudo-class starting with ":" (e.g. `:hover`, `:nth-child(2n)`)', $sel );
			}
			$clean[ $sel ] = true;
		}

		$final    = array_keys( $clean );
		$delete   = empty( $final );
		$snapshot = Design_Option_Store::read_versioned(
			BRICKS_DB_PSEUDO_CLASSES,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			null
		);

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$current = is_array( $snapshot['value'] ) && ! empty( $snapshot['value'] )
			? array_values( array_unique( array_map( 'strval', $snapshot['value'] ) ) )
			: self::DEFAULTS;
		$removed = array_values( array_diff( $current, $delete ? self::DEFAULTS : $final ) );

		if ( ! empty( $removed ) && ( $input['allowRemovedPseudoClasses'] ?? null ) !== true ) {
			return Error::conflict(
				'pseudo-class-removal-acknowledgement-required',
				[
					'message'              => __( 'This replacement removes existing pseudo-class selectors. Preserve them, or pass allowRemovedPseudoClasses=true after reviewing the bounded usage evidence. Existing style settings are not rewritten automatically.', 'bricks' ),
					'removedPseudoClasses' => $removed,
					'removalUsageEvidence' => self::pseudo_class_usage_evidence( $removed ),
				]
			);
		}

		$removal_evidence = ! empty( $removed ) ? self::pseudo_class_usage_evidence( $removed ) : null;

		$save_result = Design_Option_Store::compare_and_swap_owned(
			self::OWNERSHIP_RESOURCE,
			$snapshot,
			$final,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			$expected_ownership,
			null,
			$delete
		);

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$readback = Design_Option_Store::read_versioned_from_snapshot(
			$snapshot,
			self::DESIGN_SYSTEM_VERSION_OPTION,
			null
		);

		if ( is_wp_error( $readback ) ) {
			return self::committed_readback_error( $save_result, $readback );
		}

		$ownership = Design_Option_Store::ownership_envelope( self::OWNERSHIP_RESOURCE, $readback );

		if ( is_wp_error( $ownership ) ) {
			return self::committed_readback_error( $save_result, $ownership, self::snapshot_evidence( $readback ) );
		}

		$saved            = is_array( $readback['value'] ) && ! empty( $readback['value'] )
			? array_values( array_unique( array_map( 'strval', $readback['value'] ) ) )
			: self::DEFAULTS;
		$committed_exists = ! $delete;
		$committed_value  = $delete ? self::DEFAULTS : $final;

		if ( (bool) $readback['exists'] !== $committed_exists || maybe_serialize( $saved ) !== maybe_serialize( $committed_value ) ) {
			return self::committed_readback_error( $save_result, null, self::snapshot_evidence( $readback ) );
		}

		return [
			'success'              => true,
			'changed'              => $save_result['status'] !== 'no_change',
			'pseudoClasses'        => $saved,
			'ownership'            => $ownership,
			'removedPseudoClasses' => $removed,
			'removalUsageEvidence' => $removal_evidence,
		];
	}

	/**
	 * Validate one stored pseudo-class without allowing selector or rule escape.
	 *
	 * @since 2.4
	 *
	 * @param string $selector Pseudo-class selector.
	 * @return bool
	 */
	private static function is_valid_pseudo_class( string $selector ): bool {
		if ( preg_match( '/[{};\x00-\x1f\x7f]/', $selector ) || strpos( $selector, '/*' ) !== false || strpos( $selector, '*/' ) !== false ) {
			return false;
		}

		$matches = [];
		if ( ! preg_match( '/^:[a-z][a-z0-9-]*(?:\(([\p{L}\p{N} _#.\-+~>*=:,%\[\]"\'|^$()\/]+)\))?$/iu', $selector, $matches ) ) {
			return false;
		}

		if ( ! isset( $matches[1] ) ) {
			return true;
		}

		$parentheses = 0;
		$brackets    = 0;
		$quote       = '';
		$length      = strlen( $matches[1] );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $matches[1][ $index ];

			if ( $quote !== '' ) {
				if ( $character === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $character === '"' || $character === "'" ) {
				$quote = $character;
				continue;
			}

			if ( $character === '(' ) {
				++$parentheses;
			} elseif ( $character === ')' ) {
				if ( $parentheses === 0 ) {
					return false;
				}
				--$parentheses;
			} elseif ( $character === '[' ) {
				++$brackets;
			} elseif ( $character === ']' ) {
				if ( $brackets === 0 ) {
					return false;
				}
				--$brackets;
			}
		}

		return $parentheses === 0 && $brackets === 0 && $quote === '';
	}

	/**
	 * Return explicit post-commit readback evidence.
	 *
	 * @since 2.4
	 *
	 * @param array          $save_result    Persistence result.
	 * @param \WP_Error|null $readback_error Readback or ownership failure.
	 * @param array          $live_evidence  Safe live authority evidence.
	 * @return \WP_Error
	 */
	private static function committed_readback_error( array $save_result, $readback_error = null, array $live_evidence = [] ) {
		$committed = ( $save_result['status'] ?? 'no_change' ) !== 'no_change';

		return Error::conflict(
			'pseudo-classes-committed-readback',
			[
				'message'                => __( 'The pseudo-class write completed, but the live authority could not be proven to equal this write. Re-read pseudo classes before another mutation and manually reconcile when required.', 'bricks' ),
				'committedResources'     => $committed ? [ self::OWNERSHIP_RESOURCE ] : [],
				'manualRecoveryRequired' => $committed,
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
	 * Produce bounded, review-only evidence for removed pseudo references.
	 *
	 * @since 2.4
	 *
	 * @param array $selectors Removed selectors.
	 * @return array
	 */
	private static function pseudo_class_usage_evidence( array $selectors ) {
		$matches           = [
			'pages'         => [],
			'globalClasses' => [],
			'themeStyles'   => [],
			'components'    => [],
		];
		$scanned           = array_fill_keys( array_keys( $matches ), 0 );
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

				if ( self::collect_usage_match( $matches['pages'], (int) $post_id, $page_data, $selectors ) ) {
					$truncated_scopes[] = 'pages';
					self::append_truncated_record( $truncated_records['pages'], (int) $post_id );
				}
			}
		} else {
			$scan_unavailable[] = 'pages';
		}

		foreach (
			[
				'globalClasses' => 'BRICKS_DB_GLOBAL_CLASSES',
				'themeStyles'   => 'BRICKS_DB_THEME_STYLES',
				'components'    => 'BRICKS_DB_COMPONENTS',
			] as $scope => $constant
		) {
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
				$id = is_array( $row ) ? (string) ( $row['id'] ?? $index ) : (string) $index;
				if ( self::collect_usage_match( $matches[ $scope ], $id, $row, $selectors ) ) {
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
			'kind'                   => 'pseudoClass',
			'removedTokens'          => array_values( $selectors ),
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
	 * Append one bounded usage match when removed selectors occur.
	 *
	 * @since 2.4
	 *
	 * @param array      $matches   Match rows.
	 * @param int|string $id        Resource identifier.
	 * @param mixed      $value     Opaque design value.
	 * @param array      $selectors Removed selectors.
	 * @return bool Whether inspection or match collection reached a limit.
	 */
	private static function collect_usage_match( array &$matches, $id, $value, array $selectors ): bool {
		$found     = [];
		$truncated = false;
		self::find_pseudo_references( $value, $selectors, $found, $truncated );

		if ( ! empty( $found ) ) {
			if ( count( $matches ) < self::USAGE_MATCH_LIMIT ) {
				$matches[] = [
					'id'     => $id,
					'tokens' => array_values( array_keys( $found ) ),
				];
			} else {
				$truncated = true;
			}
		}

		return $truncated;
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
	 * Locate pseudo selector suffixes within a bounded amount of work.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value     Value.
	 * @param array $selectors Removed selectors.
	 * @param array $found     Found-selector map.
	 * @param bool  $truncated Whether the inspection budget was exhausted.
	 * @return void
	 */
	private static function find_pseudo_references( $value, array $selectors, array &$found, bool &$truncated ): void {
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
					if ( ! self::match_pseudo_reference( (string) $key, $selectors, $found, $comparisons_remaining ) ) {
						$truncated = true;
						break;
					}
					$stack[] = $child;
				}
				continue;
			}

			if ( is_scalar( $current ) ) {
				if ( ! self::match_pseudo_reference( (string) $current, $selectors, $found, $comparisons_remaining ) ) {
					$truncated = true;
					break;
				}
			}
		}
	}

	/**
	 * Match one scalar against removed selectors.
	 *
	 * @since 2.4
	 *
	 * @param string $candidate Candidate scalar.
	 * @param array  $selectors Removed selectors.
	 * @param array  $found     Found-selector map.
	 * @param int    $remaining Remaining selector comparisons.
	 * @return bool Whether every selector was inspected.
	 */
	private static function match_pseudo_reference( string $candidate, array $selectors, array &$found, int &$remaining ): bool {
		foreach ( $selectors as $selector ) {
			if ( $remaining <= 0 ) {
				return false;
			}
			--$remaining;
			$selector = (string) $selector;

			if ( $candidate === $selector || preg_match( '/' . preg_quote( $selector, '/' ) . '(?:$|:)/i', $candidate ) ) {
				$found[ $selector ] = true;
			}
		}

		return true;
	}
}

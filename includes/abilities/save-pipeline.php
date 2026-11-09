<?php
/**
 * Save Pipeline
 *
 * Shared pipeline for all element write operations.
 * Normalize > security check > validate > revision > save > CSS regen.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Save_Pipeline {
	const FILTER_REGISTRY_LOCK_TIMEOUT = 5;
	const WRITE_INTENT_STRUCTURAL      = 'structural';
	const WRITE_INTENT_PARTIAL         = 'partial';

	/**
	 * Execute the save pipeline
	 *
	 * Supports scoped runtime-registry exceptions for trusted callers.
	 * (#86catbj04; @since 2.4)
	 *
	 * @since 2.4
	 *
	 * @param int      $post_id                           Post ID.
	 * @param array    $elements                          Elements to save (nested or flat).
	 * @param string   $area                              Content area: 'content', 'header', 'footer'. Default 'content'.
	 * @param string[] $allowed_unregistered_element_types Known element types allowed when unavailable in the runtime registry.
	 * @param string   $expected_document_digest           Optional SHA-256 digest of the current saved tree.
	 * @param bool     $preserve_valid_signatures          Deprecated compatibility flag. Unchanged signatures are preserved automatically; changed PHP follows the shared authoring authorization.
	 * @param array    $context                            Save context. `writeIntent=partial` preserves valid submitted children order; `workspaceBaselineElements` supplies trusted persisted CSS for exact workspace writes.
	 * @return array|\WP_Error {
	 *     Success envelope or error.
	 *
	 *     @type array    $elements   Normalized, saved elements.
	 *     @type int|null $revisionId Revision ID of the snapshot taken before save, or null when there was no prior content.
	 *     @type bool     $changed    Whether persisted element data changed.
	 * }
	 */
	public static function execute( $post_id, $elements, $area = 'content', $allowed_unregistered_element_types = [], $expected_document_digest = '', $preserve_valid_signatures = false, $context = [] ) {
		$elements = self::prepare_elements( $post_id, $elements, $area, $allowed_unregistered_element_types, $context );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$filter_registry_lock = self::acquire_filter_registry_lock( $post_id, $elements );

		if ( is_wp_error( $filter_registry_lock ) ) {
			return $filter_registry_lock;
		}

		try {
			// Recheck after acquiring the registry lock. The metadata update below
			// synchronously registers the filter IDs before the lock is released.
			if ( $filter_registry_lock ) {
				$filter_id_check = self::validate_filter_element_ids( $post_id, $elements );

				if ( is_wp_error( $filter_id_check ) ) {
					return $filter_id_check;
				}
			}

			$meta_key          = \Bricks\Database::get_bricks_data_key( $area );
			$existing_elements = self::get_authoritative_elements( $post_id, $meta_key );
			$meta_exists       = metadata_exists( 'post', $post_id, $meta_key );
			$current_digest    = self::document_digest( $existing_elements );
			$candidate_digest  = self::document_digest( $elements );

			if ( $expected_document_digest !== '' && ! hash_equals( $expected_document_digest, $current_digest ) ) {
				return Error::conflict(
					'document_digest_mismatch',
					[
						'message'                => 'The Bricks document changed after the candidate was prepared. Re-read the page and preview the write again.',
						'expectedDocumentDigest' => $expected_document_digest,
						'actualDocumentDigest'   => $current_digest,
					]
				);
			}

			if ( hash_equals( $current_digest, $candidate_digest ) ) {
				return [
					'elements'       => $elements,
					'revisionId'     => null,
					'documentDigest' => $candidate_digest,
					'changed'        => false,
				];
			}

			// Step 8: Snapshot existing content into a revision after validation
			// passes but before mutating the database.
			$revision_id = self::take_revision_snapshot( $post_id, $area );

			if ( is_wp_error( $revision_id ) ) {
				return $revision_id;
			}

			// WordPress unslashes metadata values, but the CAS predicate must match
			// the original stored bytes. Slash only the value being written.
			$meta_value = self::slash_strings( $elements );
			$updated    = $meta_exists
				? update_metadata( 'post', $post_id, $meta_key, $meta_value, $existing_elements )
				: add_post_meta( $post_id, $meta_key, $meta_value, true );

			// update_metadata() returns false when the value is unchanged as well as
			// when its compare-and-swap predicate loses a race. A fresh read
			// distinguishes the idempotent case from a real concurrent edit.
			if ( $updated === false ) {
				$after_attempt = self::get_authoritative_elements( $post_id, $meta_key );

				if ( self::document_digest( $after_attempt ) !== $candidate_digest ) {
					if ( $revision_id ) {
						wp_delete_post_revision( $revision_id );
					}

					return Error::conflict(
						'document_write_conflict',
						[
							'message'                => 'The Bricks document changed while it was being saved. No candidate data was committed.',
							'expectedDocumentDigest' => $current_digest,
							'actualDocumentDigest'   => self::document_digest( $after_attempt ),
						]
					);
				}
			}
		} finally {
			self::release_filter_registry_lock( $filter_registry_lock );
		}

		// Step 10: Trigger CSS regeneration via wp_update_post
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_modified' => current_time( 'mysql' ),
			]
		);

		return [
			'elements'       => $elements,
			'revisionId'     => $revision_id,
			'documentDigest' => $candidate_digest,
			'changed'        => true,
		];
	}

	/**
	 * Execute a settings-only write while retaining stored child order.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id                  Post ID.
	 * @param array  $elements                 Complete element document with a settings-only change.
	 * @param string $area                     Bricks save area.
	 * @param string $expected_document_digest Optional optimistic-concurrency digest.
	 * @return array|\WP_Error Save result.
	 */
	public static function execute_partial( $post_id, $elements, $area = 'content', $expected_document_digest = '' ) {
		return self::execute(
			$post_id,
			$elements,
			$area,
			[],
			$expected_document_digest,
			false,
			[ 'writeIntent' => self::WRITE_INTENT_PARTIAL ]
		);
	}

	/**
	 * Restore metadata input slashes without coercing scalar types on older WordPress versions.
	 *
	 * @since 2.4.5
	 *
	 * @param mixed $value Unslashed value.
	 * @return mixed Slashed value with non-string types preserved.
	 */
	public static function slash_strings( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'slash_strings' ], $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * Read the current persisted element tree without trusting request-local metadata cache.
	 *
	 * Failed metadata compare-and-swap writes do not invalidate the WordPress
	 * metadata cache. Evict it before both the precondition read and any failed
	 * write readback so a concurrent database value cannot look like success.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Bricks element meta key.
	 * @return array Current persisted elements.
	 */
	private static function get_authoritative_elements( $post_id, $meta_key ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$elements = get_post_meta( $post_id, $meta_key, true );

		return is_array( $elements ) ? $elements : [];
	}

	/**
	 * Run the save pipeline without writing to the database.
	 *
	 * @since 2.4
	 *
	 * @param int      $post_id                           Post ID.
	 * @param array    $elements                          Elements to validate (nested or flat).
	 * @param string   $area                              Content area: 'content', 'header', 'footer'. Default 'content'.
	 * @param string[] $allowed_unregistered_element_types Known element types allowed when unavailable in the runtime registry.
	 * @param bool     $preserve_valid_signatures          Deprecated compatibility flag. Unchanged signatures are preserved automatically; changed PHP follows the shared authoring authorization.
	 * @param array    $context                            Save context. `writeIntent=partial` preserves valid submitted children order; `workspaceBaselineElements` supplies trusted persisted CSS for exact workspace writes.
	 * @return array|\WP_Error {
	 *     Dry-run envelope or error.
	 *
	 *     @type array $elements Normalized elements that would be saved.
	 * }
	 */
	public static function dry_run( $post_id, $elements, $area = 'content', $allowed_unregistered_element_types = [], $preserve_valid_signatures = false, $context = [] ) {
		$elements = self::prepare_elements( $post_id, $elements, $area, $allowed_unregistered_element_types, $context );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		return [
			'elements'       => $elements,
			'documentDigest' => self::document_digest( $elements ),
		];
	}

	/**
	 * Validate a settings-only write while retaining stored child order.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id  Post ID.
	 * @param array  $elements Complete element document with a settings-only change.
	 * @param string $area     Bricks save area.
	 * @return array|\WP_Error Dry-run result.
	 */
	public static function dry_run_partial( $post_id, $elements, $area = 'content' ) {
		return self::dry_run(
			$post_id,
			$elements,
			$area,
			[],
			false,
			[ 'writeIntent' => self::WRITE_INTENT_PARTIAL ]
		);
	}

	/**
	 * Generate a stable digest for a normalized Bricks element tree.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element tree.
	 * @return string
	 */
	public static function document_digest( array $elements ): string {
		return hash( 'sha256', (string) wp_json_encode( array_values( $elements ) ) );
	}

	/**
	 * Normalize, sanitize, validate, and sign elements before persistence.
	 *
	 * Passes scoped runtime-registry exceptions to element validation.
	 * (#86catbj04; @since 2.4)
	 *
	 * @since 2.4
	 *
	 * @param int      $post_id                           Post ID.
	 * @param array    $elements                          Elements to prepare (nested or flat).
	 * @param string   $area                              Content area: 'content', 'header', 'footer'. Default 'content'.
	 * @param string[] $allowed_unregistered_element_types Known element types allowed when unavailable in the runtime registry.
	 * @param array    $context                            Save context. `writeIntent=partial` preserves valid submitted children order; `workspaceBaselineElements` supplies trusted persisted CSS for exact workspace writes.
	 * @return array|\WP_Error Prepared elements or error.
	 */
	private static function prepare_elements( $post_id, $elements, $area = 'content', $allowed_unregistered_element_types = [], $context = [] ) {
		// Step 1: Normalize (nested > flat)
		$normalizer = new Element_Normalizer();
		$elements   = $normalizer->normalize( $elements );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$identifier_check = Element_Validator::validate_identifiers( $elements );

		if ( is_wp_error( $identifier_check ) ) {
			return $identifier_check;
		}

		// Step 2: Build children arrays from parent references
		$write_intent = $context['writeIntent'] ?? self::WRITE_INTENT_STRUCTURAL;
		$elements     = self::rebuild_children( $elements, $write_intent, $context['workspaceBaselineElements'] ?? [] );

		// Step 3: Convert UI-facing custom CSS (`%root%`) into persisted selectors
		// and move mappable declarations into native Bricks style controls.
		$elements = Element_Style_Normalizer::normalize_elements( $elements, false, $context['workspaceBaselineElements'] ?? [] );

		// Step 3b: Prune null/'' settings values + empty nested objects so full-tree
		// writes match the partial-merge semantics in Elements::deep_merge. Mirrors
		// the builder's per-control behavior (PanelControl.vue:972, elements.js:4255).
		foreach ( $elements as &$element ) {
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = Elements::prune_empty_setting_values( $element['settings'] );
			}
		}
		unset( $element );

		// Step 4: Reject code-sensitive writes before core sanitization can
		// silently strip the payload and make the MCP write look successful.
		$existing = \Bricks\Database::get_data( $post_id, $area );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$code_check = Elements::check_code_sensitive_permissions( $existing, $elements );
		if ( is_wp_error( $code_check ) ) {
			return $code_check;
		}

		// Step 5: Security check (KSES, code execution, element count guard)
		$elements = \Bricks\Helpers::security_check_elements_before_save( $elements, $post_id, $area );

		// Step 6: Validate
		$valid = Element_Validator::validate( $elements, $allowed_unregistered_element_types );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Step 6b: Query Filter IDs are site-wide registry keys. Reusing one on
		// another post makes frontend lookups ambiguous and corrupts the index.
		$filter_id_check = self::validate_filter_element_ids( $post_id, $elements );

		if ( is_wp_error( $filter_id_check ) ) {
			return $filter_id_check;
		}

		return Elements::sign_authorized_code( $existing, $elements );
	}

	/**
	 * Reject filter element IDs already used by another non-trashed post.
	 *
	 * @param int   $post_id  Post being saved.
	 * @param array $elements Normalized element tree.
	 *
	 * @return true|\WP_Error
	 *
	 * @since 2.4
	 */
	private static function validate_filter_element_ids( $post_id, $elements ) {
		$filter_ids = self::get_filter_element_ids( $elements );

		if ( empty( $filter_ids ) ) {
			return true;
		}

		$conflicts = \Bricks\Query_Filters::get_filter_element_id_conflicts( $post_id, $filter_ids );

		if ( empty( $conflicts ) ) {
			return true;
		}

		$conflict  = reset( $conflicts );
		$filter_id = (string) ( $conflict['filter_id'] ?? '' );
		$owner_id  = absint( $conflict['post_id'] ?? 0 );

		return Error::conflict(
			'filter_element_id',
			[
				'message'      => sprintf( 'Filter element id "%1$s" is already used by post %2$d. Filter element IDs must be unique across the site.', $filter_id, $owner_id ),
				'elementId'    => $filter_id,
				'postId'       => $post_id,
				'ownerPostId'  => $owner_id,
				'suggestedFix' => 'Use new 6-character IDs, or omit IDs in nested element input so Bricks generates them.',
			]
		);
	}

	/**
	 * Return physical Query Filter element IDs from a prepared element tree.
	 *
	 * @param array $elements Prepared element tree.
	 *
	 * @return string[] Query Filter element IDs.
	 *
	 * @since 2.4
	 */
	private static function get_filter_element_ids( $elements ) {
		if ( ! class_exists( '\\Bricks\\Query_Filters' ) || ! \Bricks\Helpers::enabled_query_filters() ) {
			return [];
		}

		$filter_element_names = \Bricks\Query_Filters::filter_controls_elements();
		$filter_ids           = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! in_array( $element['name'] ?? '', $filter_element_names, true ) ) {
				continue;
			}

			if ( ! empty( $element['id'] ) ) {
				$filter_ids[] = (string) $element['id'];
			}
		}

		return array_values( array_unique( $filter_ids ) );
	}

	/**
	 * Acquire the site-scoped Query Filter registry advisory lock when needed.
	 *
	 * @param int   $post_id  Post being saved.
	 * @param array $elements Prepared element tree.
	 *
	 * @return string|\WP_Error Lock name, empty string when no lock is needed, or error.
	 *
	 * @since 2.4
	 */
	private static function acquire_filter_registry_lock( $post_id, $elements ) {
		if ( empty( self::get_filter_element_ids( $elements ) ) ) {
			return '';
		}

		global $wpdb;

		$lock_name   = self::get_filter_registry_lock_name();
		$lock_result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::FILTER_REGISTRY_LOCK_TIMEOUT )
		);

		if ( (string) $lock_result === '1' ) {
			return $lock_name;
		}

		if ( (string) $lock_result === '0' ) {
			return Error::conflict(
				'filter_registry_busy',
				[
					'message'    => 'The Query Filter registry is busy with another ability save. Retry this operation.',
					'postId'     => absint( $post_id ),
					'retryable'  => true,
					'retryAfter' => 1,
				]
			);
		}

		return Error::internal_error(
			'query_filter_registry_lock',
			'Could not acquire the Query Filter registry lock.',
			[ 'postId' => absint( $post_id ) ]
		);
	}

	/**
	 * Return the site-scoped advisory lock name.
	 *
	 * @return string Lock name no longer than MySQL's 64-character limit.
	 *
	 * @since 2.4
	 */
	private static function get_filter_registry_lock_name() {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? DB_NAME : '';
		$scope    = $database . '|' . $wpdb->prefix;

		return 'bricks_qf_write_' . md5( $scope );
	}

	/**
	 * Release a previously acquired Query Filter registry advisory lock.
	 *
	 * @param string $lock_name Advisory lock name, or empty when no lock was needed.
	 *
	 * @since 2.4
	 */
	private static function release_filter_registry_lock( $lock_name ) {
		if ( ! $lock_name ) {
			return;
		}

		global $wpdb;

		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
		);
	}

	/**
	 * Snapshot the current Bricks element tree into a post revision.
	 *
	 * Core WordPress revisions only capture `post_content`. Bricks element
	 * trees live in post meta, so we force a revision via the
	 * `wp_save_post_revision_check_for_changes` filter and then copy the
	 * current value of the Bricks meta key onto the revision row. This
	 * mirrors the ajax save path at includes/ajax.php:1451-1467 / 2234 and
	 * is the only thing that makes MCP writes reversible.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id Post ID.
	 * @param string $area    Content area: 'content', 'header', 'footer'.
	 * @return int|null|\WP_Error Revision ID, null when there was nothing to snapshot, or error.
	 */
	private static function take_revision_snapshot( $post_id, $area ) {
		$has_snapshot_data = false;
		$areas             = array_values( array_unique( array_merge( [ $area ], [ 'content', 'header', 'footer' ] ) ) );

		foreach ( $areas as $snapshot_area ) {
			$snapshot_key   = \Bricks\Database::get_bricks_data_key( $snapshot_area );
			$snapshot_value = get_post_meta( $post_id, $snapshot_key, true );

			if ( ! empty( $snapshot_value ) ) {
				$has_snapshot_data = true;
				break;
			}
		}

		if ( ! $has_snapshot_data && empty( get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true ) ) ) {
			return null;
		}

		// Force a revision even if core WP thinks post_content is unchanged.
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		try {
			$revision_id = wp_save_post_revision( $post_id );
		} finally {
			remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		}

		if ( is_wp_error( $revision_id ) ) {
			return $revision_id;
		}

		if ( ! $revision_id ) {
			return null;
		}

		// Copy the Bricks post meta onto the revision row for every area, not
		// just the one we're about to mutate - a single "restore" should roll
		// back the full state of the post.
		$areas = [ 'content', 'header', 'footer' ];
		foreach ( $areas as $snapshot_area ) {
			$snapshot_key   = \Bricks\Database::get_bricks_data_key( $snapshot_area );
			$snapshot_value = get_post_meta( $post_id, $snapshot_key, true );

			if ( ! empty( $snapshot_value ) ) {
				update_metadata( 'post', $revision_id, $snapshot_key, self::slash_strings( $snapshot_value ) );
			}
		}

		// Also snapshot page settings so a full restore is possible.
		$page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );
		if ( ! empty( $page_settings ) ) {
			update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_SETTINGS, self::slash_strings( $page_settings ) );
		}

		// Clear any stale autosave so the revisions UI stays coherent.
		$autosave = wp_get_post_autosave( $post_id );
		if ( $autosave ) {
			wp_delete_post_revision( $autosave->ID );
		}

		return (int) $revision_id;
	}

	/**
	 * Rebuild children arrays from parent references
	 *
	 * Bricks expects each element to have a 'children' array containing
	 * the IDs of its direct children. This method builds those arrays
	 * from the 'parent' field on each element.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements     Flat elements array.
	 * @param string $write_intent Whether submitted structure or stored child order is authoritative.
	 * @param array  $baseline     Stored elements the submission was edited from, when known.
	 * @return array Elements with correct children arrays.
	 */
	private static function rebuild_children( $elements, $write_intent = self::WRITE_INTENT_STRUCTURAL, $baseline = [] ) {
		// Build parent > children ID map
		$children_map = [];

		foreach ( $elements as $element ) {
			$id     = $element['id'] ?? '';
			$parent = $element['parent'] ?? 0;

			if ( ! is_string( $id ) || $id === '' ) {
				continue;
			}

			$parent_key = (string) $parent;

			if ( ! isset( $children_map[ $parent_key ] ) ) {
				$children_map[ $parent_key ] = [];
			}

			$children_map[ $parent_key ][] = $id;
		}

		// The Builder orders siblings by `children`, not by position in the flat
		// array, so the two legitimately disagree on Builder-made pages. Where a
		// submission leaves a parent's child list and their flat order exactly as
		// stored, the stored order is kept rather than rewritten from flat order.
		$stored_children = [];
		$stored_position = [];

		foreach ( is_array( $baseline ) ? array_values( $baseline ) : [] as $position => $stored ) {
			$stored_id = $stored['id'] ?? '';

			if ( is_string( $stored_id ) && $stored_id !== '' ) {
				$stored_position[ $stored_id ] = $position;

				if ( isset( $stored['children'] ) && is_array( $stored['children'] ) ) {
					$stored_children[ $stored_id ] = $stored['children'];
				}
			}
		}

		// Assign children arrays to each element. Settings-only writers submit the
		// complete stored document, so an existing children array is authoritative.
		// Validate it against parent references and append any missing valid child
		// once, using lookup maps to keep this linear on wide pages.
		foreach ( $elements as &$element ) {
			$id       = $element['id'] ?? '';
			$children = is_string( $id ) && $id !== '' ? $children_map[ $id ] ?? [] : [];

			if ( $write_intent === self::WRITE_INTENT_PARTIAL && isset( $element['children'] ) && is_array( $element['children'] ) ) {
				$valid_child_ids = array_fill_keys( $children, true );
				$seen            = [];
				$ordered         = [];

				foreach ( $element['children'] as $child_id ) {
					if ( ! is_string( $child_id ) || ! isset( $valid_child_ids[ $child_id ] ) || isset( $seen[ $child_id ] ) ) {
						continue;
					}

					$seen[ $child_id ] = true;
					$ordered[]         = $child_id;
				}

				foreach ( $children as $child_id ) {
					if ( ! isset( $seen[ $child_id ] ) ) {
						$ordered[] = $child_id;
					}
				}

				$children = $ordered;
			} elseif ( is_string( $id ) && isset( $stored_children[ $id ] ) && ( $element['children'] ?? null ) === $stored_children[ $id ] && count( $children ) === count( $stored_children[ $id ] ) && ! array_diff( $children, $stored_children[ $id ] ) ) {
				$flat_order_as_stored = true;
				$previous             = -1;

				foreach ( $children as $child_id ) {
					if ( ! isset( $stored_position[ $child_id ] ) || $stored_position[ $child_id ] < $previous ) {
						$flat_order_as_stored = false;
						break;
					}

					$previous = $stored_position[ $child_id ];
				}

				if ( $flat_order_as_stored ) {
					$children = $stored_children[ $id ];
				}
			}

			$element['children'] = $children;
		}

		unset( $element );

		return $elements;
	}
}

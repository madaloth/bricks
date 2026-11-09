<?php
/**
 * Revision abilities
 *
 * List, inspect, and restore Bricks revisions. These are genuinely
 * Bricks-specific: WP core revisions only snapshot post_content, but
 * Bricks element trees live in postmeta (_bricks_page_content_2 etc.).
 * The Save_Pipeline copies that postmeta onto revision rows - these
 * abilities expose and operate on those snapshots.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revisions {
	// ------------------------------------------------------------------
	// list-revisions
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-revisions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_revisions_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				Elements::post_identifier_properties(),
				[
					'limit' => [
						'type'        => 'integer',
						'description' => __( 'Max revisions to return (default 10, max 50).', 'bricks' ),
					],
				]
			),
		];
	}

	/**
	 * Output schema for list-revisions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_revisions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'revisions' => [
					'type'        => 'array',
					'description' => __( 'Revisions ordered newest-first.', 'bricks' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'            => [ 'type' => 'integer' ],
							'author'        => [ 'type' => 'string' ],
							'authorId'      => [ 'type' => 'integer' ],
							'date'          => [ 'type' => 'string' ],
							'dateGmt'       => [ 'type' => 'string' ],
							'hasBricksData' => [ 'type' => 'boolean' ],
							'areas'         => [
								'type'        => 'array',
								'description' => __( 'Which Bricks areas have data on this revision.', 'bricks' ),
								'items'       => [ 'type' => 'string' ],
							],
						],
					],
				],
				'total'     => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Permission: list-revisions (read-only)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_revisions_permission( $input ) {
		$post_id = Input_Resolver::resolve_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		return true;
	}

	/**
	 * Callback: list revisions for a post
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_revisions( $input ) {
		$post_id = self::resolve( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit = max( 1, min( 50, $limit ) );

		$wp_revisions = wp_get_post_revisions(
			$post_id,
			[
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$revisions = [];

		foreach ( $wp_revisions as $rev ) {
			$author = get_userdata( (int) $rev->post_author );

			// Check which Bricks areas have data on this revision.
			$areas = [];

			foreach ( [ 'content', 'header', 'footer' ] as $area ) {
				$key  = \Bricks\Database::get_bricks_data_key( $area );
				$data = get_post_meta( $rev->ID, $key, true );

				if ( ! empty( $data ) ) {
					$areas[] = $area;
				}
			}

			$revisions[] = [
				'id'            => (int) $rev->ID,
				'author'        => $author ? $author->display_name : '',
				'authorId'      => (int) $rev->post_author,
				'date'          => $rev->post_date,
				'dateGmt'       => $rev->post_date_gmt,
				'hasBricksData' => ! empty( $areas ),
				'areas'         => $areas,
			];
		}

		return [
			'revisions' => $revisions,
			'total'     => count( $revisions ),
		];
	}

	// ------------------------------------------------------------------
	// get-revision
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-revision
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_revision_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'revisionId' => [
					'type'        => 'integer',
					'description' => __( 'Revision post ID.', 'bricks' ),
				],
				'area'       => [
					'type'        => 'string',
					'description' => __( 'Content area to read: content, header, or footer. Default: content.', 'bricks' ),
					'enum'        => [ 'content', 'header', 'footer' ],
				],
			],
			'required'   => [ 'revisionId' ],
		];
	}

	/**
	 * Output schema for get-revision
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_revision_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'revisionId' => [ 'type' => 'integer' ],
				'parentId'   => [ 'type' => 'integer' ],
				'area'       => [ 'type' => 'string' ],
				'elements'   => [
					'type'        => 'array',
					'description' => __( 'The Bricks element tree at this revision.', 'bricks' ),
				],
				'date'       => [ 'type' => 'string' ],
				'author'     => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Permission: get-revision (read-only)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function get_revision_permission( $input ) {
		$revision_id = $input['revisionId'] ?? 0;
		$revision    = get_post( $revision_id );

		if ( ! $revision || $revision->post_type !== 'revision' ) {
			return Error::not_found( 'revision', $revision_id );
		}

		if ( ! current_user_can( 'edit_post', $revision->post_parent ) ) {
			return Error::forbidden_edit_post( $revision->post_parent );
		}

		return true;
	}

	/**
	 * Callback: get full element tree from a specific revision
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_revision( $input ) {
		$revision_id = (int) $input['revisionId'];
		$area        = $input['area'] ?? 'content';
		$revision    = get_post( $revision_id );

		if ( ! $revision || $revision->post_type !== 'revision' ) {
			return Error::not_found( 'revision', $revision_id );
		}

		$meta_key = \Bricks\Database::get_bricks_data_key( $area );
		$elements = get_post_meta( $revision_id, $meta_key, true );
		$author   = get_userdata( (int) $revision->post_author );

		return [
			'revisionId' => $revision_id,
			'parentId'   => (int) $revision->post_parent,
			'area'       => $area,
			'elements'   => is_array( $elements ) ? $elements : [],
			'date'       => $revision->post_date,
			'author'     => $author ? $author->display_name : '',
		];
	}

	// ------------------------------------------------------------------
	// restore-revision
	// ------------------------------------------------------------------

	/**
	 * Input schema for restore-revision
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function restore_revision_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'revisionId' => [
					'type'        => 'integer',
					'description' => __( 'Revision post ID to restore from.', 'bricks' ),
				],
				'postId'     => [
					'type'        => 'integer',
					'description' => __( 'Optional. The parent post ID the revision belongs to. When provided, it must match the revision parent; otherwise the call is rejected. Accepted purely for caller convenience and cross-checking - the parent is always inferred from revisionId.', 'bricks' ),
				],
			],
			'required'   => [ 'revisionId' ],
		];
	}

	/**
	 * Output schema for restore-revision
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function restore_revision_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'restored'       => [ 'type' => 'boolean' ],
				'postId'         => [ 'type' => 'integer' ],
				'fromRevisionId' => [ 'type' => 'integer' ],
				'newRevisionId'  => [
					'type'        => 'integer',
					'description' => __( 'A new revision ID pointing at the pre-restore state, so restores are themselves reversible.', 'bricks' ),
				],
				'areas'          => [
					'type'        => 'array',
					'description' => __( 'Bricks areas that were restored.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Permission: restore-revision (write)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function restore_revision_permission( $input ) {
		$revision_id = $input['revisionId'] ?? 0;
		$revision    = get_post( $revision_id );

		if ( ! $revision || $revision->post_type !== 'revision' ) {
			return Error::not_found( 'revision', $revision_id );
		}

		$post_id = $revision->post_parent;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		if ( ! \Bricks\Capabilities::current_user_can_use_builder( $post_id ) ) {
			return Error::forbidden_builder_access( $post_id );
		}

		// Write path: check lock.
		Manager::ensure_post_admin_loaded();
		$locked_by = wp_check_post_lock( $post_id );

		if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
			return Error::locked_by_other_user( $post_id, (int) $locked_by );
		}

		wp_set_post_lock( $post_id );

		return true;
	}

	/**
	 * Callback: restore a revision
	 *
	 * Copies the Bricks postmeta from the revision back onto the parent
	 * post, but first takes a new snapshot of the current state so the
	 * restore itself is reversible via a second restore-revision call.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function restore_revision( $input ) {
		$revision_id = (int) $input['revisionId'];
		$revision    = get_post( $revision_id );

		if ( ! $revision || $revision->post_type !== 'revision' ) {
			return Error::not_found( 'revision', $revision_id );
		}

		$post_id = (int) $revision->post_parent;

		// If the caller supplied postId, verify it matches the revision's
		// parent. Mismatches are treated as caller-side bugs to avoid
		// silently restoring onto a different post than expected.
		if ( isset( $input['postId'] ) && $input['postId'] !== '' ) {
			$claimed_post_id = (int) $input['postId'];

			if ( $claimed_post_id !== $post_id ) {
				return Error::conflict(
					'revision_post_mismatch',
					[
						'message'        => sprintf(
							'postId %d does not match the parent of revision %d (parent: %d).',
							$claimed_post_id,
							$revision_id,
							$post_id
						),
						'revisionId'     => $revision_id,
						'postId'         => $claimed_post_id,
						'expectedPostId' => $post_id,
					]
				);
			}
		}

		// A revision restore may carry existing signed code through unchanged, but
		// changed executable payloads require current authoring authorization.
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			$key      = \Bricks\Database::get_bricks_data_key( $area );
			$incoming = get_post_meta( $revision_id, $key, true );

			if ( empty( $incoming ) || ! is_array( $incoming ) ) {
				continue;
			}

			$existing   = get_post_meta( $post_id, $key, true );
			$existing   = is_array( $existing ) ? $existing : [];
			$code_check = Elements::check_code_sensitive_permissions( $existing, $incoming );

			if ( is_wp_error( $code_check ) ) {
				return $code_check;
			}
		}

		// Step 1: Snapshot the CURRENT state into a new revision so the
		// restore can itself be undone.
		$new_revision_id = self::snapshot_current_state( $post_id );

		// Step 2: Copy Bricks postmeta from the source revision onto
		// the parent post.
		$areas_restored = [];

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			$key  = \Bricks\Database::get_bricks_data_key( $area );
			$data = get_post_meta( $revision_id, $key, true );

			if ( ! empty( $data ) ) {
				$existing = get_post_meta( $post_id, $key, true );
				$data     = Elements::sign_authorized_code( is_array( $existing ) ? $existing : [], $data );
				update_post_meta( $post_id, $key, Save_Pipeline::slash_strings( $data ) );
				$areas_restored[] = $area;
			}
		}

		// Also restore page settings if they were snapshotted.
		$page_settings = get_post_meta( $revision_id, BRICKS_DB_PAGE_SETTINGS, true );

		if ( ! empty( $page_settings ) ) {
			update_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, Save_Pipeline::slash_strings( $page_settings ) );
		}

		// Step 3: Touch modified timestamp to trigger CSS regen.
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_modified' => current_time( 'mysql' ),
			]
		);

		return [
			'restored'       => true,
			'postId'         => $post_id,
			'fromRevisionId' => $revision_id,
			'newRevisionId'  => $new_revision_id,
			'areas'          => $areas_restored,
		];
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Resolve a post identifier from input (convenience wrapper).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error
	 */
	private static function resolve( array $input ) {
		return Input_Resolver::resolve_post_id( $input );
	}

	/**
	 * Snapshot the current Bricks state of a post into a new revision.
	 *
	 * Reuses the same pattern as Save_Pipeline::take_revision_snapshot()
	 * but is public to this class. Returns the revision ID or null.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	private static function snapshot_current_state( int $post_id ): ?int {
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		try {
			$revision_id = wp_save_post_revision( $post_id );
		} finally {
			remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		}

		if ( ! $revision_id ) {
			return null;
		}

		// Copy all Bricks areas onto the revision.
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			$key   = \Bricks\Database::get_bricks_data_key( $area );
			$value = get_post_meta( $post_id, $key, true );

			if ( ! empty( $value ) ) {
				update_metadata( 'post', $revision_id, $key, Save_Pipeline::slash_strings( $value ) );
			}
		}

		// Page settings too.
		$page_settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

		if ( ! empty( $page_settings ) ) {
			update_metadata( 'post', $revision_id, BRICKS_DB_PAGE_SETTINGS, Save_Pipeline::slash_strings( $page_settings ) );
		}

		return (int) $revision_id;
	}
}

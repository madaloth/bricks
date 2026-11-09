<?php
namespace Bricks\Integrations\HappyFiles;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * HappyFiles-backed Browser folder provider.
 *
 * The adapter operates on HappyFiles' existing post-type taxonomies. It does
 * not copy folder terms or object relationships into Bricks-owned storage.
 *
 * @since 2.4
 */
class Media_Folder_Provider implements \Bricks\Media_Folder_Provider, \Bricks\Media_Folder_Post_Type_Provider, \Bricks\Media_Folder_Item_Provider, \Bricks\Media_Folder_Item_Assignment_Provider {
	/**
	 * Post type handled by this provider instance.
	 *
	 * @var string
	 */
	private $post_type = 'attachment';

	/**
	 * Request-local advisory lock depth for reentrant provider writes.
	 *
	 * @var int
	 */
	private $folder_write_lock_depth = 0;

	/**
	 * Normalized folders cached for the lifetime of this provider instance.
	 *
	 * @var array|null
	 */
	private $folders_cache = null;

	/**
	 * Return a provider clone scoped to one HappyFiles-enabled post type.
	 *
	 * @param string $post_type WordPress post type.
	 * @return \Bricks\Media_Folder_Provider|null
	 */
	public function for_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );

		if ( ! $post_type ) {
			return null;
		}

		$provider                          = clone $this;
		$provider->post_type               = $post_type;
		$provider->folder_write_lock_depth = 0;
		$provider->folders_cache           = null;

		return $provider;
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'happyfiles';
	}

	/**
	 * Return the provider label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'HappyFiles';
	}

	/**
	 * Whether HappyFiles folders are enabled for this post type and current user.
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\\HappyFiles\\User' ) &&
			in_array( $this->post_type, $this->get_enabled_post_types(), true ) &&
			taxonomy_exists( $this->get_taxonomy() ) &&
			( $this->post_type !== 'attachment' || ! get_option( 'happyfiles_disable_for_files', false ) ) &&
			\HappyFiles\User::$folder_access !== 'none';
	}

	/**
	 * Whether the current user has HappyFiles full folder access.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return $this->is_available() && \HappyFiles\User::$folder_access === 'full';
	}

	/**
	 * Whether the current user may assign existing items.
	 *
	 * HappyFiles reserves moving existing items for its full-access role.
	 *
	 * @return bool
	 */
	public function can_assign() {
		if ( ! $this->can_manage() ) {
			return false;
		}

		if ( $this->post_type === 'attachment' ) {
			return current_user_can( 'upload_files' );
		}

		$post_type_object = get_post_type_object( $this->post_type );

		return $post_type_object && current_user_can( $post_type_object->cap->edit_posts );
	}

	/**
	 * Return normalized HappyFiles folders in native sibling order.
	 *
	 * @return array
	 */
	public function get_folders() {
		if ( is_array( $this->folders_cache ) ) {
			return $this->folders_cache;
		}

		if ( ! $this->is_available() ) {
			$this->folders_cache = [];

			return $this->folders_cache;
		}

		$args  = [
			'taxonomy'   => $this->get_taxonomy(),
			'hide_empty' => false,
		];
		$terms = is_callable( [ '\\HappyFiles\\Helpers', 'get_terms' ] )
			? \HappyFiles\Helpers::get_terms( $args )
			: get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			$this->folders_cache = [];

			return $this->folders_cache;
		}

		$position_key = defined( 'HAPPYFILES_POSITION' ) ? HAPPYFILES_POSITION : 'happyfiles_position';
		$folders      = [];

		foreach ( $terms as $index => $term ) {
			$position  = get_term_meta( $term->term_id, $position_key, true );
			$folders[] = [
				'id'       => (int) $term->term_id,
				'name'     => wp_specialchars_decode( $term->name ),
				'parent'   => (int) $term->parent,
				'count'    => $this->get_visible_item_count( $term->term_id ),
				'position' => is_numeric( $position ) ? (int) $position : PHP_INT_MAX - count( $terms ) + $index,
			];
		}

		usort(
			$folders,
			static function( $a, $b ) {
				if ( $a['position'] === $b['position'] ) {
					return strnatcasecmp( $a['name'], $b['name'] );
				}

				return $a['position'] < $b['position'] ? -1 : 1;
			}
		);
		$folders = $this->apply_folder_count_mode( $folders );

		$this->folders_cache = array_map(
			static function( $folder ) {
				unset( $folder['position'] );
				return $folder;
			},
			$folders
		);

		return $this->folders_cache;
	}

	/**
	 * Format folder counts according to the HappyFiles folder count setting.
	 *
	 * @param array $folders Normalized folders with direct item counts.
	 * @return array
	 */
	private function apply_folder_count_mode( $folders ) {
		$mode = sanitize_key( get_option( 'happyfiles_folder_count_mode', 'current' ) );

		if ( ! in_array( $mode, [ 'current', 'current_children', 'total' ], true ) ) {
			$mode = 'current';
		}

		if ( $mode === 'current' ) {
			return $folders;
		}

		$children_by_parent = [];
		$direct_counts      = [];

		foreach ( $folders as $folder ) {
			$folder_id                          = absint( $folder['id'] );
			$parent_id                          = absint( $folder['parent'] );
			$direct_counts[ $folder_id ]        = (int) $folder['count'];
			$children_by_parent[ $parent_id ][] = $folder_id;
		}

		foreach ( $folders as &$folder ) {
			$current_count  = (int) $folder['count'];
			$children_count = $this->get_descendant_item_count(
				absint( $folder['id'] ),
				$children_by_parent,
				$direct_counts
			);
			$total_count    = $current_count + $children_count;

			if ( $mode === 'total' ) {
				$folder['count'] = $total_count;
			} elseif ( $children_count > 0 ) {
				$folder['count'] = sprintf( '%d/%d', $current_count, $total_count );
			}
		}

		unset( $folder );

		return $folders;
	}

	/**
	 * Count items in every descendant while tolerating malformed folder cycles.
	 *
	 * @param int   $folder_id          Folder ID.
	 * @param array $children_by_parent Child IDs grouped by parent ID.
	 * @param array $direct_counts      Direct item counts keyed by folder ID.
	 * @return int
	 */
	private function get_descendant_item_count( $folder_id, $children_by_parent, $direct_counts ) {
		$count   = 0;
		$pending = $children_by_parent[ $folder_id ] ?? [];
		$visited = [ $folder_id => true ];

		while ( $pending ) {
			$child_id = absint( array_pop( $pending ) );

			if ( isset( $visited[ $child_id ] ) ) {
				continue;
			}

			$visited[ $child_id ] = true;
			$count               += (int) ( $direct_counts[ $child_id ] ?? 0 );

			foreach ( $children_by_parent[ $child_id ] ?? [] as $descendant_id ) {
				$pending[] = $descendant_id;
			}
		}

		return $count;
	}

	/**
	 * Count items using the same status and language filters as retrieval.
	 *
	 * @param int|string $folder_id HappyFiles term ID, zero for all items, or "unfiled".
	 * @return int
	 */
	private function get_visible_item_count( $folder_id ) {
		$post_status = $this->post_type === 'attachment'
			? \Bricks\Media_Browser_Query::get_visible_attachment_statuses()
			: 'any';
		$query_args  = $this->apply_query(
			[
				'posts_per_page'   => 1,
				'paged'            => 1,
				'post_type'        => $this->post_type,
				'post_status'      => $post_status,
				'fields'           => 'ids',
				'no_found_rows'    => false,
				'suppress_filters' => false,
				'lang'             => '',
			],
			$folder_id === 'unfiled' ? 'unfiled' : absint( $folder_id )
		);
		$query       = new \WP_Query( $query_args );

		return (int) $query->found_posts;
	}

	/**
	 * Return visible totals for the virtual folder entries.
	 *
	 * @return array
	 */
	public function get_scope_counts() {
		return [
			'all'     => $this->get_visible_item_count( 0 ),
			'unfiled' => $this->get_visible_item_count( 'unfiled' ),
		];
	}

	/**
	 * Apply the selected HappyFiles folder to query arguments.
	 *
	 * @param array      $query_args Query arguments.
	 * @param int|string $folder_id  Folder ID, "unfiled", or empty.
	 * @return array
	 */
	public function apply_query( $query_args, $folder_id ) {
		if ( ! $this->is_available() || $folder_id === '' || $folder_id === 0 ) {
			return $query_args;
		}

		$tax_query = isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] )
			? $query_args['tax_query']
			: [];

		if ( $folder_id === 'unfiled' ) {
			$tax_query[] = [
				'taxonomy' => $this->get_taxonomy(),
				'operator' => 'NOT EXISTS',
			];
		} else {
			$folder_id = absint( $folder_id );

			if ( ! $folder_id ) {
				return $query_args;
			}

			$tax_query[] = [
				'taxonomy'         => $this->get_taxonomy(),
				'field'            => 'term_id',
				'terms'            => [ $folder_id ],
				'include_children' => false,
			];
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Folder browsing requires the provider's taxonomy relationship.
		$query_args['tax_query'] = $tax_query;

		return apply_filters( 'happyfiles_folder_query_args', $query_args, $folder_id === 'unfiled' ? -1 : $folder_id );
	}

	/**
	 * Return HappyFiles folders assigned to one item.
	 *
	 * @param int $item_id WordPress object ID.
	 * @return array|\WP_Error
	 */
	public function get_item_folder_ids( $item_id ) {
		if ( ! $this->is_available() ) {
			return [];
		}

		$folder_ids = is_callable( [ '\\HappyFiles\\Helpers', 'get_object_terms' ] )
			? \HappyFiles\Helpers::get_object_terms( absint( $item_id ), $this->get_taxonomy(), [ 'fields' => 'ids' ] )
			: wp_get_object_terms( absint( $item_id ), $this->get_taxonomy(), [ 'fields' => 'ids' ] );

		if ( is_wp_error( $folder_ids ) ) {
			return $folder_ids;
		}

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $folder_ids ) ) ) );
	}

	/**
	 * Assign an attachment using HappyFiles' configured folder semantics.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Folder ID, or zero for Uncategorized.
	 * @return true|\WP_Error
	 */
	public function assign_attachment( $attachment_id, $folder_id ) {
		return $this->assign_item( $attachment_id, $folder_id );
	}

	/**
	 * Assign an item using HappyFiles' configured folder semantics.
	 *
	 * @param int $item_id   WordPress object ID.
	 * @param int $folder_id Folder ID, or zero for Uncategorized.
	 * @return true|\WP_Error
	 */
	public function assign_item( $item_id, $folder_id ) {
		$item_id = absint( $item_id );

		if (
			! $item_id ||
			get_post_type( $item_id ) !== $this->post_type ||
			! $this->can_assign() ||
			! current_user_can( 'edit_post', $item_id )
		) {
			return new \WP_Error( 'media_folder_not_allowed', __( 'You are not allowed to move these items.', 'bricks' ) );
		}

		$folder_id = absint( $folder_id );

		if ( $folder_id && ! $this->folder_exists( $folder_id ) ) {
			return new \WP_Error( 'media_folder_not_found', __( 'The folder no longer exists.', 'bricks' ) );
		}

		$folder_ids = [];

		if ( $folder_id ) {
			if ( get_option( 'happyfiles_multiple_folders', false ) ) {
				$folder_ids = $this->get_item_folder_ids( $item_id );

				if ( is_wp_error( $folder_ids ) ) {
					return $folder_ids;
				}

				$folder_ids   = array_map( 'absint', (array) $folder_ids );
				$folder_ids[] = $folder_id;
				$folder_ids   = array_values( array_unique( $folder_ids ) );
			} else {
				$folder_ids = [ $folder_id ];
			}
		}

		$result = wp_set_object_terms( $item_id, $folder_ids, $this->get_taxonomy(), false );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Create, rename, move, or delete a HappyFiles folder.
	 *
	 * Folder deletion only deletes the term and its relationships. Attachments
	 * remain in the WordPress media library.
	 *
	 * @param array $input Request input.
	 * @return array|\WP_Error
	 */
	public function manage_folder( $input ) {
		if ( ! $this->can_manage() ) {
			return new \WP_Error( 'media_folder_not_allowed', __( 'You are not allowed to manage media folders.', 'bricks' ) );
		}

		return $this->with_folder_write_lock(
			function() use ( $input ) {
				if ( ! $this->can_manage() ) {
					return new \WP_Error( 'media_folder_not_allowed', __( 'You are not allowed to manage media folders.', 'bricks' ) );
				}

				return $this->manage_folder_unlocked( $input );
			}
		);
	}

	/**
	 * Apply a folder mutation while the provider write lock is held.
	 *
	 * @param array $input Request input.
	 * @return array|\WP_Error
	 */
	private function manage_folder_unlocked( $input ) {

		$operation = isset( $input['operation'] ) ? sanitize_key( $input['operation'] ) : '';
		$folder_id = isset( $input['folderId'] ) ? absint( $input['folderId'] ) : 0;
		$name      = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		$parent    = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		$position  = isset( $input['position'] ) && is_numeric( $input['position'] )
			? max( 0, (int) $input['position'] )
			: null;
		$folders   = in_array( $operation, [ 'create', 'rename', 'move' ], true ) ? $this->get_folders() : [];

		if ( $parent && ! $this->folder_exists( $parent ) ) {
			return new \WP_Error( 'media_folder_parent_not_found', __( 'The parent media folder no longer exists.', 'bricks' ) );
		}

		if ( $operation === 'create' ) {
			if ( $name === '' ) {
				return new \WP_Error( 'media_folder_name_required', __( 'Enter a media folder name.', 'bricks' ) );
			}

			if ( $this->sibling_name_exists( $name, $parent, 0, $folders ) ) {
				return $this->duplicate_name_error();
			}

			$this->invalidate_folders_cache();
			$result = wp_insert_term( $name, $this->get_taxonomy(), [ 'parent' => $parent ] );

			if ( ! is_wp_error( $result ) && $result !== false ) {
				$created_folder_id = isset( $result['term_id'] ) ? absint( $result['term_id'] ) : 0;
				$position_result   = $this->update_folder_positions(
					array_merge( $this->get_sibling_ids( $parent, $folders ), [ $created_folder_id ] )
				);

				if ( is_wp_error( $position_result ) ) {
					return $position_result;
				}
			}
		} elseif ( $operation === 'rename' ) {
			if ( ! $folder_id || $name === '' ) {
				return new \WP_Error( 'media_folder_invalid', __( 'Choose a media folder and enter a name.', 'bricks' ) );
			}

			$folder = $this->find_folder( $folder_id, $folders );

			if ( ! $folder ) {
				return new \WP_Error( 'media_folder_invalid', __( 'Choose a media folder.', 'bricks' ) );
			}

			if ( $this->sibling_name_exists( $name, $folder['parent'], $folder_id, $folders ) ) {
				return $this->duplicate_name_error();
			}

			$this->invalidate_folders_cache();
			$result = wp_update_term( $folder_id, $this->get_taxonomy(), [ 'name' => $name ] );
		} elseif ( $operation === 'move' ) {
			$folder = $this->find_folder( $folder_id, $folders );

			if ( ! $folder_id || ! $folder ) {
				return new \WP_Error( 'media_folder_invalid', __( 'Choose a media folder.', 'bricks' ) );
			}

			if ( $parent === $folder_id || $this->folder_is_descendant_of( $parent, $folder_id, $folders ) ) {
				return new \WP_Error(
					'media_folder_parent_invalid',
					__( 'A media folder cannot be moved into itself or one of its subfolders.', 'bricks' )
				);
			}

			if ( $this->sibling_name_exists( $folder['name'], $parent, $folder_id, $folders ) ) {
				return $this->duplicate_name_error();
			}

			$result = $this->move_folder( $folder, $parent, $position );
		} elseif ( $operation === 'delete' ) {
			if ( ! $folder_id ) {
				return new \WP_Error( 'media_folder_invalid', __( 'Choose a media folder.', 'bricks' ) );
			}

			$this->invalidate_folders_cache();
			$result = wp_delete_term( $folder_id, $this->get_taxonomy() );
		} else {
			return new \WP_Error( 'media_folder_operation_invalid', __( 'Invalid media folder operation.', 'bricks' ) );
		}

		if ( is_wp_error( $result ) || $result === false ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'media_folder_update_failed', __( 'The media folder could not be updated.', 'bricks' ) );
		}

		return [ 'folders' => $this->get_folders() ];
	}

	/**
	 * Move a folder and persist the complete order of affected sibling groups.
	 *
	 * @param array    $folder   Folder being moved.
	 * @param int      $parent   Destination parent ID.
	 * @param int|null $position Destination insertion position, or null to append.
	 * @return true|false|\WP_Error
	 */
	private function move_folder( $folder, $parent, $position ) {
		return $this->with_folder_write_lock(
			function() use ( $folder, $parent, $position ) {
				$current_folders = $this->get_folders();
				$current_folder  = $this->find_folder( (int) $folder['id'], $current_folders );

				if ( ! $current_folder ) {
					return new \WP_Error( 'media_folder_invalid', __( 'Choose a media folder.', 'bricks' ) );
				}

				if ( $parent && ! $this->find_folder( $parent, $current_folders ) ) {
					return new \WP_Error( 'media_folder_parent_not_found', __( 'The parent media folder no longer exists.', 'bricks' ) );
				}

				if ( $parent === (int) $current_folder['id'] || $this->folder_is_descendant_of( $parent, (int) $current_folder['id'], $current_folders ) ) {
					return new \WP_Error(
						'media_folder_parent_invalid',
						__( 'A media folder cannot be moved into itself or one of its subfolders.', 'bricks' )
					);
				}

				if ( $this->sibling_name_exists( $current_folder['name'], $parent, (int) $current_folder['id'], $current_folders ) ) {
					return $this->duplicate_name_error();
				}

				return $this->move_folder_unlocked( $current_folder, $parent, $position, $current_folders );
			}
		);
	}

	/**
	 * Move a folder while the provider write lock is held.
	 *
	 * @param array    $folder   Current folder state.
	 * @param int      $parent   Destination parent ID.
	 * @param int|null $position Destination insertion position, or null to append.
	 * @param array    $folders  Fresh normalized folder list.
	 * @return true|false|\WP_Error
	 */
	private function move_folder_unlocked( $folder, $parent, $position, $folders ) {
		$folder_id       = (int) $folder['id'];
		$previous_parent = (int) $folder['parent'];

		if ( $position === null && $previous_parent === $parent ) {
			return true;
		}

		$this->invalidate_folders_cache();

		$destination_ids   = $this->get_sibling_ids( $parent, $folders, $folder_id );
		$insertion_index   = $position === null ? count( $destination_ids ) : min( $position, count( $destination_ids ) );
		$previous_siblings = $this->get_sibling_ids( $previous_parent, $folders, $folder_id );
		$affected_ids      = array_values( array_unique( array_merge( $destination_ids, $previous_siblings, [ $folder_id ] ) ) );
		$snapshot          = $this->get_folder_move_snapshot( $folder_id, $previous_parent, $affected_ids );

		array_splice( $destination_ids, $insertion_index, 0, [ $folder_id ] );

		if ( $previous_parent !== $parent ) {
			$parent_result = wp_update_term( $folder_id, $this->get_taxonomy(), [ 'parent' => $parent ] );

			if ( is_wp_error( $parent_result ) || $parent_result === false ) {
				return $parent_result;
			}
		}

		$position_result = $this->update_folder_positions( $destination_ids );

		if ( is_wp_error( $position_result ) ) {
			return $this->rollback_folder_move( $snapshot, $position_result );
		}

		if ( $previous_parent !== $parent ) {
			$position_result = $this->update_folder_positions( $previous_siblings );

			if ( is_wp_error( $position_result ) ) {
				return $this->rollback_folder_move( $snapshot, $position_result );
			}
		}

		return true;
	}

	/**
	 * Run a provider mutation under a site-scoped MySQL advisory lock.
	 *
	 * @param callable $callback Mutation callback.
	 * @return mixed|\WP_Error
	 */
	private function with_folder_write_lock( $callback ) {
		global $wpdb;

		if ( $this->folder_write_lock_depth > 0 ) {
			$this->folder_write_lock_depth++;

			try {
				return call_user_func( $callback );
			} finally {
				$this->folder_write_lock_depth--;
			}
		}

		$lock_name = sprintf( 'bricks_happyfiles_%d', get_current_blog_id() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot be cached.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		if ( (string) $acquired !== '1' ) {
			return new \WP_Error( 'media_folder_locked', __( 'The media folders are being updated. Try again.', 'bricks' ) );
		}

		$this->folder_write_lock_depth = 1;

		try {
			return call_user_func( $callback );
		} finally {
			$this->folder_write_lock_depth = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot be cached.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Capture the term parent and exact position metadata before a move.
	 *
	 * @param int   $folder_id       Folder being moved.
	 * @param int   $previous_parent Existing parent ID.
	 * @param array $folder_ids      Position metadata IDs that may change.
	 * @return array
	 */
	private function get_folder_move_snapshot( $folder_id, $previous_parent, $folder_ids ) {
		$position_key = defined( 'HAPPYFILES_POSITION' ) ? HAPPYFILES_POSITION : 'happyfiles_position';
		$positions    = [];

		foreach ( $folder_ids as $position_folder_id ) {
			$positions[ (int) $position_folder_id ] = [
				'exists' => metadata_exists( 'term', $position_folder_id, $position_key ),
				'value'  => get_term_meta( $position_folder_id, $position_key, true ),
			];
		}

		return [
			'folderId'  => $folder_id,
			'parent'    => $previous_parent,
			'positions' => $positions,
		];
	}

	/**
	 * Restore a failed folder move without hiding a rollback failure.
	 *
	 * @param array     $snapshot Original parent and position state.
	 * @param \WP_Error $error    Original move error.
	 * @return \WP_Error
	 */
	private function rollback_folder_move( $snapshot, $error ) {
		$position_key  = defined( 'HAPPYFILES_POSITION' ) ? HAPPYFILES_POSITION : 'happyfiles_position';
		$parent_result = wp_update_term(
			$snapshot['folderId'],
			$this->get_taxonomy(),
			[ 'parent' => $snapshot['parent'] ]
		);
		$rollback_ok   = ! is_wp_error( $parent_result ) && $parent_result !== false;

		foreach ( $snapshot['positions'] as $folder_id => $position ) {
			$result = $position['exists']
				? update_term_meta( $folder_id, $position_key, $position['value'] )
				: delete_term_meta( $folder_id, $position_key );

			if ( is_wp_error( $result ) ) {
				$rollback_ok = false;
			}
		}

		if ( ! $rollback_ok ) {
			return new \WP_Error( 'media_folder_rollback_failed', __( 'The media folder move failed and could not be fully restored.', 'bricks' ) );
		}

		return $error;
	}

	/**
	 * Return ordered sibling IDs for a parent.
	 *
	 * @param int   $parent_id         Parent folder ID.
	 * @param array $folders           Normalized folder list in provider order.
	 * @param int   $excluded_folder_id Optional folder ID to exclude.
	 * @return array
	 */
	private function get_sibling_ids( $parent_id, $folders, $excluded_folder_id = 0 ) {
		$folder_ids = [];

		foreach ( $folders as $folder ) {
			if (
				(int) $folder['parent'] === (int) $parent_id &&
				(int) $folder['id'] !== (int) $excluded_folder_id
			) {
				$folder_ids[] = (int) $folder['id'];
			}
		}

		return $folder_ids;
	}

	/**
	 * Persist sequential HappyFiles positions for a sibling group.
	 *
	 * @param array $folder_ids Ordered folder IDs.
	 * @return true|\WP_Error
	 */
	private function update_folder_positions( $folder_ids ) {
		$position_key = defined( 'HAPPYFILES_POSITION' ) ? HAPPYFILES_POSITION : 'happyfiles_position';

		foreach ( array_values( $folder_ids ) as $position => $folder_id ) {
			$result = update_term_meta( $folder_id, $position_key, $position );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Find a normalized folder by ID.
	 *
	 * @param int   $folder_id Folder ID.
	 * @param array $folders   Normalized folder list.
	 * @return array|null
	 */
	private function find_folder( $folder_id, $folders ) {
		foreach ( $folders as $folder ) {
			if ( (int) $folder['id'] === (int) $folder_id ) {
				return $folder;
			}
		}

		return null;
	}

	/**
	 * Determine whether a sibling already uses the requested folder name.
	 *
	 * @param string $name              Requested folder name.
	 * @param int    $parent_id         Destination parent ID.
	 * @param int    $excluded_folder_id Folder ID excluded while editing itself.
	 * @param array  $folders           Normalized folder list.
	 * @return bool
	 */
	private function sibling_name_exists( $name, $parent_id, $excluded_folder_id, $folders ) {
		$name = $this->normalize_folder_name( $name );

		foreach ( $folders as $folder ) {
			if (
				(int) $folder['id'] === (int) $excluded_folder_id ||
				(int) $folder['parent'] !== (int) $parent_id
			) {
				continue;
			}

			if ( $this->normalize_folder_name( $folder['name'] ) === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a folder name for sibling comparisons.
	 *
	 * @param string $name Folder name.
	 * @return string
	 */
	private function normalize_folder_name( $name ) {
		$name = trim( wp_specialchars_decode( (string) $name ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Return the duplicate sibling-name error.
	 *
	 * @return \WP_Error
	 */
	private function duplicate_name_error() {
		return new \WP_Error(
			'media_folder_name_exists',
			__( 'A media folder with this name already exists in this location.', 'bricks' )
		);
	}

	/**
	 * Return the HappyFiles taxonomy for this post type.
	 *
	 * @return string
	 */
	private function get_taxonomy() {
		if ( $this->post_type === 'attachment' ) {
			return defined( 'HAPPYFILES_TAXONOMY' ) ? HAPPYFILES_TAXONOMY : '';
		}

		return 'hf_cat_' . $this->post_type;
	}

	/**
	 * Return the post types enabled in HappyFiles.
	 *
	 * @return array
	 */
	private function get_enabled_post_types() {
		if (
			class_exists( '\\HappyFiles\\Settings' ) &&
			method_exists( '\\HappyFiles\\Settings', 'get_enabled_post_types' )
		) {
			$enabled_post_types = \HappyFiles\Settings::get_enabled_post_types();
		} else {
			$enabled_post_types = array_merge(
				[ 'attachment' ],
				(array) get_option( 'happyfiles_post_types', [] )
			);
			$enabled_post_types = apply_filters( 'happyfiles/post_types', $enabled_post_types );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $enabled_post_types ) ) ) );
	}

	/**
	 * Check a folder through HappyFiles' WPML-aware helper when available.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	private function folder_exists( $folder_id ) {
		$exists = is_callable( [ '\\HappyFiles\\Helpers', 'term_exists' ] )
			? \HappyFiles\Helpers::term_exists( $folder_id, $this->get_taxonomy() )
			: term_exists( $folder_id, $this->get_taxonomy() );

		return ! empty( $exists ) && ! is_wp_error( $exists );
	}

	/**
	 * Determine whether a folder is nested below another folder.
	 *
	 * @param int        $folder_id   Potential descendant folder ID.
	 * @param int        $ancestor_id Potential ancestor folder ID.
	 * @param array|null $folders     Optional normalized folder snapshot.
	 * @return bool
	 */
	private function folder_is_descendant_of( $folder_id, $ancestor_id, $folders = null ) {
		$folder_id   = absint( $folder_id );
		$ancestor_id = absint( $ancestor_id );

		if ( ! $folder_id || ! $ancestor_id ) {
			return false;
		}

		$parents = [];

		$folders = is_array( $folders ) ? $folders : $this->get_folders();

		foreach ( $folders as $folder ) {
			$parents[ (int) $folder['id'] ] = (int) $folder['parent'];
		}

		$visited = [];

		while ( $folder_id && ! isset( $visited[ $folder_id ] ) ) {
			if ( $folder_id === $ancestor_id ) {
				return true;
			}

			$visited[ $folder_id ] = true;
			$folder_id             = isset( $parents[ $folder_id ] ) ? $parents[ $folder_id ] : 0;
		}

		return false;
	}

	/**
	 * Clear the request-local folder snapshot after a mutation.
	 *
	 * @return void
	 */
	private function invalidate_folders_cache() {
		$this->folders_cache = null;
	}
}

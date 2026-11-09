<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Move-only bulk selection service for folder-enabled Browser post types.
 *
 * Media attachments retain their dedicated bulk pipeline. This service only
 * accepts non-attachment post types and only performs folder assignment.
 *
 * @since 2.4
 */
class Media_Folder_Bulk {
	const TOKEN_TTL  = 900;
	const MAX_ITEMS  = 10000;
	const BATCH_SIZE = 25;

	/**
	 * Resolve and snapshot a non-media folder selection.
	 *
	 * @param array $selection Selection descriptor.
	 * @return array|\WP_Error
	 */
	public static function prepare_selection( $selection ) {
		$selection = is_array( $selection ) ? $selection : [];
		$post_type = isset( $selection['postType'] ) ? sanitize_key( $selection['postType'] ) : '';

		if ( ! $post_type || $post_type === 'attachment' || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'folder_bulk_post_type_invalid', __( 'Choose a valid post type.', 'bricks' ) );
		}

		$provider = Media_Folder_Providers::get_active( $post_type );

		if (
			! $provider instanceof Media_Folder_Item_Assignment_Provider ||
			! $provider->can_assign()
		) {
			return new \WP_Error( 'folder_bulk_provider_unavailable', __( 'Folder assignment is not available.', 'bricks' ) );
		}

		$mode        = isset( $selection['mode'] ) ? sanitize_key( $selection['mode'] ) : 'explicit';
		$query_total = null;

		if ( $mode === 'query' ) {
			$resolved = self::resolve_query_selection( $selection, $post_type, $provider );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$ids         = $resolved['ids'];
			$query_total = $resolved['total'];
		} else {
			$ids = isset( $selection['ids'] ) && is_array( $selection['ids'] ) ? $selection['ids'] : [];
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ( $query_total !== null && $query_total > self::MAX_ITEMS ) || count( $ids ) > self::MAX_ITEMS ) {
			return new \WP_Error(
				'folder_bulk_too_many_items',
				// translators: %s: Maximum number of items.
				sprintf( __( 'Select no more than %s items at once.', 'bricks' ), number_format_i18n( self::MAX_ITEMS ) )
			);
		}

		$excluded_ids = isset( $selection['excludedIds'] ) && is_array( $selection['excludedIds'] )
			? array_map( 'absint', $selection['excludedIds'] )
			: [];
		$ids          = array_values( array_diff( $ids, $excluded_ids ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		$ids = array_values(
			array_filter(
				$ids,
				static function( $item_id ) use ( $post_type ) {
					return get_post_type( $item_id ) === $post_type && current_user_can( 'edit_post', $item_id );
				}
			)
		);

		if ( empty( $ids ) ) {
			return new \WP_Error( 'folder_bulk_empty_selection', __( 'Select at least one item.', 'bricks' ) );
		}

		$token = str_replace( '-', '', wp_generate_uuid4() );
		set_transient(
			self::selection_key( $token ),
			[
				'userId'     => get_current_user_id(),
				'postType'   => $post_type,
				'providerId' => sanitize_key( $provider->get_id() ),
				'ids'        => $ids,
			],
			self::TOKEN_TTL
		);

		return [
			'token' => $token,
			'count' => count( $ids ),
		];
	}

	/**
	 * Move one chunk of a prepared selection.
	 *
	 * @param string $token     Prepared selection token.
	 * @param int    $folder_id Destination folder ID, or zero for Uncategorized.
	 * @param int    $cursor    Zero-based cursor.
	 * @return array|\WP_Error
	 */
	public static function process_move( $token, $folder_id, $cursor = 0 ) {
		$token     = sanitize_key( $token );
		$selection = get_transient( self::selection_key( $token ) );

		if ( ! is_array( $selection ) || (int) ( $selection['userId'] ?? 0 ) !== get_current_user_id() ) {
			return new \WP_Error( 'folder_bulk_selection_expired', __( 'The selection has expired. Select the items again.', 'bricks' ) );
		}

		$post_type = sanitize_key( $selection['postType'] ?? '' );
		$provider  = Media_Folder_Providers::get_active( $post_type );

		if (
			! $provider instanceof Media_Folder_Item_Assignment_Provider ||
			sanitize_key( $provider->get_id() ) !== sanitize_key( $selection['providerId'] ?? '' ) ||
			! $provider->can_assign()
		) {
			return new \WP_Error( 'folder_bulk_provider_unavailable', __( 'Folder assignment is not available.', 'bricks' ) );
		}

		$folder_id = absint( $folder_id );
		$cursor    = max( 0, absint( $cursor ) );
		$ids       = array_values( array_map( 'absint', (array) ( $selection['ids'] ?? [] ) ) );
		$total     = count( $ids );
		$chunk     = array_slice( $ids, $cursor, self::BATCH_SIZE );
		$items     = [];

		foreach ( $chunk as $item_id ) {
			if ( get_post_type( $item_id ) !== $post_type || ! current_user_can( 'edit_post', $item_id ) ) {
				$items[] = self::item_result( $item_id, 'skipped', 'not_allowed', __( 'Not allowed', 'bricks' ) );
				continue;
			}

			$result = $provider->assign_item( $item_id, $folder_id );

			if ( is_wp_error( $result ) ) {
				$items[] = self::item_result( $item_id, 'error', $result->get_error_code(), $result->get_error_message() );
			} else {
				$items[] = self::item_result( $item_id, 'success', 'folder_moved', __( 'Item moved', 'bricks' ) );
			}
		}

		$next_cursor = $cursor + count( $items );
		$done        = $next_cursor >= $total;

		if ( $done ) {
			delete_transient( self::selection_key( $token ) );
		} else {
			set_transient( self::selection_key( $token ), $selection, self::TOKEN_TTL );
		}

		return [
			'processed'  => min( $next_cursor, $total ),
			'total'      => $total,
			'succeeded'  => count( array_filter( $items, [ __CLASS__, 'item_succeeded' ] ) ),
			'skipped'    => count( array_filter( $items, [ __CLASS__, 'item_skipped' ] ) ),
			'failed'     => count( array_filter( $items, [ __CLASS__, 'item_failed' ] ) ),
			'nextCursor' => $done ? null : $next_cursor,
			'done'       => $done,
			'items'      => $items,
		];
	}

	/**
	 * Resolve a query-mode post selection.
	 *
	 * @param array                        $selection Selection descriptor.
	 * @param string                       $post_type WordPress post type.
	 * @param Media_Folder_Provider|object $provider  Scoped folder provider with assignment support.
	 * @return array|\WP_Error
	 */
	private static function resolve_query_selection( $selection, $post_type, $provider ) {
		$search    = isset( $selection['search'] ) ? sanitize_text_field( $selection['search'] ) : '';
		$status    = isset( $selection['status'] ) ? sanitize_key( $selection['status'] ) : '';
		$orderby   = isset( $selection['orderby'] ) ? sanitize_key( $selection['orderby'] ) : 'date';
		$order     = isset( $selection['order'] ) && strtoupper( $selection['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$orderby   = in_array( $orderby, [ 'date', 'modified', 'title' ], true ) ? $orderby : 'date';
		$folder_id = isset( $selection['folderId'] ) && is_scalar( $selection['folderId'] )
			? sanitize_text_field( $selection['folderId'] )
			: '';

		$query_args = [
			'posts_per_page'   => self::MAX_ITEMS + 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Resolve only enough IDs to enforce the bulk limit.
			'paged'            => 1,
			'orderby'          => $orderby,
			'order'            => $order,
			'post_status'      => $status ? $status : 'any',
			'post_type'        => $post_type,
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
			'lang'             => '',
		];

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$query_args = apply_filters( 'bricks/ajax/get_pages_args', $query_args );

		// Filters may customize visibility and ordering, but cannot broaden the prepared selection.
		$query_args['post_type']      = $post_type;
		$query_args['fields']         = 'ids';
		$query_args['posts_per_page'] = self::MAX_ITEMS + 1; // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Resolve only enough IDs to enforce the bulk limit.
		$query_args['paged']          = 1;
		$query_args['no_found_rows']  = false;

		$query_args = $provider->apply_query( $query_args, $folder_id );
		$query      = new \WP_Query( $query_args );

		return [
			'ids'   => is_array( $query->posts ) ? $query->posts : [],
			'total' => max( count( (array) $query->posts ), absint( $query->found_posts ) ),
		];
	}

	/**
	 * Return a prepared-selection transient key.
	 *
	 * @param string $token Selection token.
	 * @return string
	 */
	private static function selection_key( $token ) {
		return 'bricks_folder_bulk_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * Return one normalized item result.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $status  Result status.
	 * @param string $code    Result code.
	 * @param string $message Result message.
	 * @return array
	 */
	private static function item_result( $item_id, $status, $code, $message ) {
		return [
			'id'      => $item_id,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		];
	}

	/**
	 * Whether an item result succeeded.
	 *
	 * @param array $item Item result.
	 * @return bool
	 */
	private static function item_succeeded( $item ) {
		return $item['status'] === 'success';
	}

	/**
	 * Whether an item result was skipped.
	 *
	 * @param array $item Item result.
	 * @return bool
	 */
	private static function item_skipped( $item ) {
		return $item['status'] === 'skipped';
	}

	/**
	 * Whether an item result failed.
	 *
	 * @param array $item Item result.
	 * @return bool
	 */
	private static function item_failed( $item ) {
		return $item['status'] === 'error';
	}
}

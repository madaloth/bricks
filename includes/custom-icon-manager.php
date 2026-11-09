<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Shared custom icon attachment cleanup.
 *
 * Cleanup candidates always come from the persisted custom icon registry. This
 * prevents a client from using icon-set deletion to remove unrelated media.
 *
 * @since 2.4
 */
class Custom_Icon_Manager {
	/**
	 * Permanently delete unreferenced SVG attachments assigned to icon sets.
	 *
	 * @param array $set_ids         Icon set IDs selected for attachment cleanup.
	 * @param array $source_icons    Custom icons before the set deletion.
	 * @param array $remaining_icons Custom icons after the set deletion.
	 * @return array
	 */
	public static function delete_set_attachments( $set_ids, $source_icons, $remaining_icons ) {
		$set_id_lookup   = [];
		$source_icons    = is_array( $source_icons ) ? $source_icons : [];
		$remaining_icons = is_array( $remaining_icons ) ? $remaining_icons : [];
		$referenced_ids  = [];
		$candidate_ids   = [];

		foreach ( is_array( $set_ids ) ? $set_ids : [] as $set_id ) {
			if ( is_scalar( $set_id ) && (string) $set_id !== '' ) {
				$set_id_lookup[ (string) $set_id ] = true;
			}
		}

		foreach ( $remaining_icons as $icon ) {
			$attachment_id = absint( $icon['attachment_id'] ?? 0 );

			if ( $attachment_id ) {
				$referenced_ids[ $attachment_id ] = true;
			}
		}

		foreach ( $source_icons as $icon ) {
			$set_id        = (string) ( $icon['setId'] ?? '' );
			$attachment_id = absint( $icon['attachment_id'] ?? 0 );

			if ( isset( $set_id_lookup[ $set_id ] ) && $attachment_id ) {
				$candidate_ids[ $attachment_id ] = true;
			}
		}

		$result = [
			'deletedAttachments' => [],
			'skippedAttachments' => [],
		];

		foreach ( array_keys( $candidate_ids ) as $attachment_id ) {
			$mime = (string) get_post_mime_type( $attachment_id );

			if (
				isset( $referenced_ids[ $attachment_id ] ) ||
				get_post_type( $attachment_id ) !== 'attachment' ||
				! in_array( $mime, [ 'image/svg+xml', 'image/svg' ], true ) ||
				! current_user_can( 'delete_post', $attachment_id )
			) {
				$result['skippedAttachments'][] = $attachment_id;
				continue;
			}

			if ( ! wp_delete_attachment( $attachment_id, true ) ) {
				$result['skippedAttachments'][] = $attachment_id;
				continue;
			}

			$result['deletedAttachments'][] = $attachment_id;
		}

		return $result;
	}
}

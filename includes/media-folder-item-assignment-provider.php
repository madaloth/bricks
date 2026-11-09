<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Optional contract for providers that can assign items to folders.
 *
 * @since 2.4
 */
interface Media_Folder_Item_Assignment_Provider {
	/**
	 * Assign one item to a folder.
	 *
	 * A zero folder ID represents no folder relationship. Providers decide how
	 * assignment behaves when their native storage supports multiple folders.
	 *
	 * @param int $item_id   WordPress object ID.
	 * @param int $folder_id Folder ID, or zero for Uncategorized.
	 * @return true|\WP_Error
	 */
	public function assign_item( $item_id, $folder_id );
}

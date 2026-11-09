<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Optional contract for providers that can read item-folder assignments.
 *
 * @since 2.4
 */
interface Media_Folder_Item_Provider {
	/**
	 * Return folder IDs assigned to one item.
	 *
	 * @param int $item_id WordPress object ID.
	 * @return array|\WP_Error
	 */
	public function get_item_folder_ids( $item_id );
}

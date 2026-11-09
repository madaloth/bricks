<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Contract for media-folder integrations used by the Builder media browser.
 *
 * Providers own their folder storage and permissions. Bricks only consumes the
 * normalized folder data and delegates filtering, assignment, and folder CRUD.
 *
 * @since 2.4
 */
interface Media_Folder_Provider {
	/**
	 * Return the stable provider identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Return the provider label shown to integrations and diagnostics.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this provider is available to the current user and media library.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Whether the current user may create, rename, move, and delete folders.
	 *
	 * @return bool
	 */
	public function can_manage();

	/**
	 * Whether the current user may assign existing attachments to folders.
	 *
	 * Per-attachment edit permission is checked separately for every assignment.
	 *
	 * @return bool
	 */
	public function can_assign();

	/**
	 * Return a flat, normalized folder list.
	 *
	 * Every folder must contain id, name, parent, and count keys.
	 * Optional uploadable: false marks a folder as unavailable for upload assignment.
	 * Bricks sends no folder assignment for uploads queued there; provider defaults apply.
	 * Omitted or true preserves upload targeting. This flag applies only to that
	 * folder, not its children, and does not replace server-side assignment checks.
	 *
	 * @since 2.4.4 Supports the optional uploadable folder flag.
	 *
	 * @return array
	 */
	public function get_folders();

	/**
	 * Apply a folder constraint to media query arguments.
	 *
	 * @param array      $query_args Query arguments.
	 * @param int|string $folder_id  Positive folder ID, "unfiled", or empty.
	 * @return array
	 */
	public function apply_query( $query_args, $folder_id );

	/**
	 * Assign one attachment to a folder.
	 *
	 * A zero folder ID represents no folder relationship. Providers decide how assignment
	 * behaves when their native storage supports multiple folders.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Folder ID, or zero for Uncategorized.
	 * @return true|\WP_Error
	 */
	public function assign_attachment( $attachment_id, $folder_id );

	/**
	 * Create, rename, move, reorder, or delete a folder and return refreshed folders.
	 *
	 * @param array $input Unslashed request input. The provider sanitizes fields it consumes.
	 * @return array|\WP_Error
	 */
	public function manage_folder( $input );
}

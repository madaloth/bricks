<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Optional contract for folder providers that support multiple post types.
 *
 * Providers implementing only Media_Folder_Provider remain scoped to the media
 * library. Multi-post-type providers return a post-type-scoped provider instance
 * so the existing provider methods do not need a breaking signature change.
 *
 * @since 2.4
 */
interface Media_Folder_Post_Type_Provider {
	/**
	 * Return a provider instance scoped to one post type.
	 *
	 * @param string $post_type WordPress post type.
	 * @return Media_Folder_Provider|null
	 */
	public function for_post_type( $post_type );
}

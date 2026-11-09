<?php
/**
 * Content lifecycle & media abilities
 *
 * Generic WordPress operations (create, duplicate, delete posts; upload and
 * search media) that don't exist as core abilities yet. These fill gaps in
 * the WP Abilities API - expect them to be deprecated / replaced when
 * WordPress core ships equivalents (tracked at WordPress/ai#40).
 *
 * Bricks-specific enhancements (element seeding on create, postmeta
 * copying on duplicate, lock awareness on delete) are layered on top of
 * the generic WP calls but are the reason these live in the bricks/
 * namespace rather than a separate generic plugin.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Content {
	// ==================================================================
	// CREATE POST
	// ==================================================================

	/**
	 * Input schema for create-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_post_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'title'    => [
					'type'        => 'string',
					'description' => __( 'Post title.', 'bricks' ),
				],
				'postType' => [
					'type'        => 'string',
					'description' => __( 'Post type slug. Default: page. Must be a Bricks-enabled post type.', 'bricks' ),
				],
				'status'   => [
					'type'        => 'string',
					'description' => __( 'Post status. Default: draft.', 'bricks' ),
					'enum'        => [ 'draft', 'publish', 'pending', 'private', 'future' ],
				],
				'slug'     => [
					'type'        => 'string',
					'description' => __( 'URL slug. Auto-generated from title if omitted.', 'bricks' ),
				],
				'parent'   => [
					'type'        => 'integer',
					'description' => __( 'Parent post ID (for hierarchical post types like pages).', 'bricks' ),
				],
				'elements' => [
					'type'        => 'array',
					'description' => __( 'Bricks element objects to seed the page with, not JSON strings. When provided, the tree is saved atomically with the post; partial state is never written. Element `id` values are internal 6-character Bricks IDs used by builder references and default frontend selectors. In nested format, you may omit ids and Bricks generates them while preserving parent-child nesting. In flat format, ids and parent references must be valid 6-character ids.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
			],
			'required'   => [ 'title' ],
		];
	}

	/**
	 * Output schema for create-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_post_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'postId'     => [ 'type' => 'integer' ],
				'title'      => [ 'type' => 'string' ],
				'slug'       => [ 'type' => 'string' ],
				'postType'   => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'editUrl'    => [ 'type' => 'string' ],
				'builderUrl' => [ 'type' => 'string' ],
				'permalink'  => [ 'type' => 'string' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID if elements were seeded.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: create-post
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function create_post_permission( $input ) {
		$post_type = $input['postType'] ?? 'page';
		$pt_object = get_post_type_object( $post_type );

		if ( ! $pt_object ) {
			return Error::invalid_param( 'postType', 'a registered post type', $post_type );
		}

		if ( ! current_user_can( $pt_object->cap->create_posts ) ) {
			return Error::forbidden_builder_permission( $pt_object->cap->create_posts );
		}

		$status = $input['status'] ?? 'draft';
		if ( self::status_requires_publish_cap( $status ) && ! current_user_can( $pt_object->cap->publish_posts ) ) {
			return Error::forbidden_builder_permission( $pt_object->cap->publish_posts );
		}

		// Verify the post type is Bricks-enabled.
		$allowed = Input_Resolver::default_allow_list();

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return Error::bricks_not_enabled_on_post_type( 0, $post_type, $allowed );
		}

		return true;
	}

	/**
	 * Callback: create a new post/page with optional Bricks element seeding
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_post( $input ) {
		// Enforce required `title` (schema declares it required but
		// Manager::validate_top_level_input only checks unknown keys).
		if ( ! isset( $input['title'] ) || ! is_string( $input['title'] ) || trim( $input['title'] ) === '' ) {
			return Error::missing_param( 'title' );
		}

		$post_type = $input['postType'] ?? 'page';
		$status    = $input['status'] ?? 'draft';
		$elements  = null;

		if ( array_key_exists( 'elements', $input ) ) {
			if ( ! is_array( $input['elements'] ) ) {
				return Error::invalid_param( 'elements', 'an array of Bricks elements', $input['elements'] );
			}

			$normalizer = new Element_Normalizer();
			$elements   = $normalizer->normalize( $input['elements'] );

			if ( is_wp_error( $elements ) ) {
				return $elements;
			}

			$permission_check = Elements::check_full_tree_permissions( [], $elements );

			if ( is_wp_error( $permission_check ) ) {
				return $permission_check;
			}
		}

		$post_data = [
			'post_title'  => $input['title'],
			'post_type'   => $post_type,
			'post_status' => $status,
		];

		if ( ! empty( $input['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $input['slug'] );
		}

		if ( ! empty( $input['parent'] ) ) {
			$post_data['post_parent'] = (int) $input['parent'];
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Mark this post as Bricks-rendered (ensures Bricks templates apply).
		update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

		// Seed elements if provided - atomic with creation.
		$revision_id = null;

		if ( is_array( $elements ) && ! empty( $elements ) ) {
			$save_result = Save_Pipeline::execute( $post_id, $elements, 'content' );

			if ( is_wp_error( $save_result ) ) {
				// Roll back the post creation.
				wp_delete_post( $post_id, true );
				return $save_result;
			}

			$revision_id = $save_result['revisionId'] ?? null;
		}

		$post = get_post( $post_id );

		return [
			'postId'     => $post_id,
			'title'      => $post->post_title,
			'slug'       => $post->post_name,
			'postType'   => $post->post_type,
			'status'     => $post->post_status,
			'editUrl'    => get_edit_post_link( $post_id, '' ),
			'builderUrl' => add_query_arg( 'bricks', 'run', get_permalink( $post_id ) ),
			'permalink'  => get_permalink( $post_id ),
			'revisionId' => $revision_id,
		];
	}

	// ==================================================================
	// DUPLICATE POST
	// ==================================================================

	/**
	 * Input schema for duplicate-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function duplicate_post_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				Elements::post_identifier_properties(),
				[
					'newTitle'  => [
						'type'        => 'string',
						'description' => __( 'Title for the duplicate. Default: "{original title} (Copy)".', 'bricks' ),
					],
					'newSlug'   => [
						'type'        => 'string',
						'description' => __( 'Slug for the duplicate. Auto-generated if omitted.', 'bricks' ),
					],
					'newStatus' => [
						'type'        => 'string',
						'description' => __( 'Status for the duplicate. Default: draft.', 'bricks' ),
						'enum'        => [ 'draft', 'publish', 'pending', 'private' ],
					],
				]
			),
		];
	}

	/**
	 * Output schema for duplicate-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function duplicate_post_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'postId'     => [ 'type' => 'integer' ],
				'sourceId'   => [ 'type' => 'integer' ],
				'title'      => [ 'type' => 'string' ],
				'slug'       => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'editUrl'    => [ 'type' => 'string' ],
				'builderUrl' => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	/**
	 * Permission: duplicate-post
	 *
	 * Requires read access to the source and create-posts on its type.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function duplicate_post_permission( $input ) {
		$post_id = Input_Resolver::resolve_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		if ( ! \Bricks\Capabilities::current_user_can_use_builder( $post_id ) ) {
			return Error::forbidden_builder_access( $post_id );
		}

		$post      = get_post( $post_id );
		$pt_object = get_post_type_object( $post->post_type );

		if ( ! current_user_can( $pt_object->cap->create_posts ) ) {
			return Error::forbidden_builder_permission( $pt_object->cap->create_posts );
		}

		$status = $input['newStatus'] ?? 'draft';
		if ( self::status_requires_publish_cap( $status ) && ! current_user_can( $pt_object->cap->publish_posts ) ) {
			return Error::forbidden_builder_permission( $pt_object->cap->publish_posts );
		}

		return true;
	}

	/**
	 * Callback: duplicate a post including all Bricks data
	 *
	 * Copies the element tree, page settings, featured image, and
	 * post meta (excluding internal WP meta prefixed with _edit_).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function duplicate_post( $input ) {
		$source_id = Input_Resolver::resolve_post_id( $input );

		if ( is_wp_error( $source_id ) ) {
			return $source_id;
		}

		$source = get_post( $source_id );

		if ( ! $source ) {
			return Error::not_found( 'post', $source_id );
		}

		$source_bricks_data = [];

		// Duplicating a post creates a new executable payload rather than
		// preserving an unchanged row. Check authoring permission before creating
		// a new post containing executable content.
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			$key  = \Bricks\Database::get_bricks_data_key( $area );
			$data = get_post_meta( $source_id, $key, true );

			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}

			$code_check = Elements::check_code_sensitive_permissions( [], $data );

			if ( is_wp_error( $code_check ) ) {
				return $code_check;
			}

			$source_bricks_data[ $area ] = Elements::sign_authorized_code( [], $data );
		}

		$new_title  = $input['newTitle'] ?? $source->post_title . ' (Copy)';
		$new_status = $input['newStatus'] ?? 'draft';

		$new_post_data = [
			'post_title'   => $new_title,
			'post_type'    => $source->post_type,
			'post_status'  => $new_status,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
			'post_parent'  => $source->post_parent,
		];

		if ( ! empty( $input['newSlug'] ) ) {
			$new_post_data['post_name'] = sanitize_title( $input['newSlug'] );
		}

		$new_id = wp_insert_post( $new_post_data, true );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy Bricks element data for all areas. Regenerate element IDs so
		// the duplicate can be edited side-by-side with the source without
		// the autosave/lock system getting confused, and so global-class /
		// component references stay scoped to the new post.
		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			$key  = \Bricks\Database::get_bricks_data_key( $area );
			$data = $source_bricks_data[ $area ] ?? [];

			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}

			$new_data = \Bricks\Helpers::generate_new_element_ids( $data );
			$new_data = wp_slash( $new_data );

			update_post_meta( $new_id, $key, $new_data );
		}

		// Copy page settings.
		$page_settings = get_post_meta( $source_id, BRICKS_DB_PAGE_SETTINGS, true );

		if ( ! empty( $page_settings ) ) {
			update_post_meta( $new_id, BRICKS_DB_PAGE_SETTINGS, $page_settings );
		}

		// Copy editor mode flag.
		$editor_mode = get_post_meta( $source_id, '_bricks_editor_mode', true );

		if ( $editor_mode ) {
			update_post_meta( $new_id, '_bricks_editor_mode', $editor_mode );
		}

		// Copy featured image.
		$thumbnail_id = get_post_thumbnail_id( $source_id );

		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_id, $thumbnail_id );
		}

		$new_post     = get_post( $new_id );
		$type_enabled = in_array( $new_post->post_type, Input_Resolver::default_allow_list(), true );

		return [
			'postId'     => $new_id,
			'sourceId'   => $source_id,
			'title'      => $new_post->post_title,
			'slug'       => $new_post->post_name,
			'status'     => $new_post->post_status,
			'editUrl'    => get_edit_post_link( $new_id, '' ),
			'builderUrl' => $type_enabled
				? add_query_arg( 'bricks', 'run', get_permalink( $new_id ) )
				: null,
		];
	}

	// ==================================================================
	// DELETE POST
	// ==================================================================

	/**
	 * Input schema for delete-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_post_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				Elements::post_identifier_properties(),
				[
					'forceDelete' => [
						'type'        => 'boolean',
						'description' => __( 'Permanently delete instead of trashing. Default: false.', 'bricks' ),
					],
				]
			),
		];
	}

	/**
	 * Output schema for delete-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_post_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted' => [ 'type' => 'boolean' ],
				'postId'  => [ 'type' => 'integer' ],
				'title'   => [ 'type' => 'string' ],
				'trashed' => [
					'type'        => 'boolean',
					'description' => __( 'True if moved to trash (recoverable). False if permanently deleted.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: delete-post
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_post_permission( $input ) {
		$post_id = Input_Resolver::resolve_post_id(
			$input,
			[
				'allow_any_post_type' => true,
				'allow_trash'         => ! empty( $input['forceDelete'] ),
			]
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return Error::forbidden_edit_post( $post_id );
		}

		// Lock check: don't delete what someone is editing.
		Manager::ensure_post_admin_loaded();
		$locked_by = wp_check_post_lock( $post_id );

		if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
			return Error::locked_by_other_user( $post_id, (int) $locked_by );
		}

		return true;
	}

	/**
	 * Callback: delete (trash) a post
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_post( $input ) {
		$force_delete = ! empty( $input['forceDelete'] );
		$post_id      = Input_Resolver::resolve_post_id(
			$input,
			[
				'allow_any_post_type' => true,
				'allow_trash'         => $force_delete,
			]
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post  = get_post( $post_id );
		$title = $post->post_title;

		$result = wp_delete_post( $post_id, $force_delete );

		if ( ! $result ) {
			return Error::conflict(
				'delete_failed',
				[
					'message' => sprintf( 'Failed to delete post %d.', $post_id ),
					'postId'  => $post_id,
				]
			);
		}

		return [
			'deleted' => true,
			'postId'  => $post_id,
			'title'   => $title,
			'trashed' => ! $force_delete && get_post_status( $post_id ) === 'trash',
		];
	}

	// ==================================================================
	// UPLOAD MEDIA
	// ==================================================================

	/**
	 * Input schema for upload-media
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function upload_media_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'url'         => [
					'type'        => 'string',
					'description' => __( 'Public http/https URL to sideload the media from. Private, loopback, link-local, unsafe-port, and credentialed URLs are rejected. Mutually exclusive with base64.', 'bricks' ),
				],
				'base64'      => [
					'type'        => 'string',
					'description' => __( 'Base64-encoded file data. Mutually exclusive with url.', 'bricks' ),
				],
				'filename'    => [
					'type'        => 'string',
					'description' => __( 'Filename including extension (e.g. "hero.jpg"). Required when using base64.', 'bricks' ),
				],
				'title'       => [
					'type'        => 'string',
					'description' => __( 'Attachment title.', 'bricks' ),
				],
				'alt'         => [
					'type'        => 'string',
					'description' => __( 'Alt text for the image.', 'bricks' ),
				],
				'caption'     => [
					'type'        => 'string',
					'description' => __( 'Attachment caption.', 'bricks' ),
				],
				'description' => [
					'type'        => 'string',
					'description' => __( 'Attachment description.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for upload-media
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function upload_media_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'integer' ],
				'url'      => [ 'type' => 'string' ],
				'title'    => [ 'type' => 'string' ],
				'alt'      => [ 'type' => 'string' ],
				'filename' => [ 'type' => 'string' ],
				'mimeType' => [ 'type' => 'string' ],
				'width'    => [ 'type' => 'integer' ],
				'height'   => [ 'type' => 'integer' ],
				'filesize' => [ 'type' => 'integer' ],
				'sizes'    => [
					'type'        => 'object',
					'description' => __( 'Available image sizes with url, width, and height.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: upload-media
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function upload_media_permission( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return Error::forbidden_builder_permission( 'upload_files' );
		}

		return true;
	}

	/**
	 * Callback: upload media from URL or base64
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function upload_media( $input ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = null;
		$has_url       = ! empty( $input['url'] );
		$has_base64    = ! empty( $input['base64'] );

		if ( $has_url && $has_base64 ) {
			return Error::invalid_param( 'source', 'exactly one of url or base64 + filename', [ 'url', 'base64' ] );
		}

		if ( $has_url ) {
			$attachment_id = self::sideload_from_url( $input['url'], $input['title'] ?? '' );
		} elseif ( $has_base64 && ! empty( $input['filename'] ) ) {
			$attachment_id = self::upload_from_base64( $input['base64'], $input['filename'], $input['title'] ?? '' );
		} else {
			return Error::missing_param( 'source', [ 'url', 'base64 + filename' ] );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set optional metadata.
		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
			delete_post_meta( $attachment_id, '_wp_attachment_alt' );
		}

		if ( ! empty( $input['caption'] ) || ! empty( $input['description'] ) ) {
			$update = [ 'ID' => $attachment_id ];

			if ( ! empty( $input['caption'] ) ) {
				$update['post_excerpt'] = $input['caption'];
			}

			if ( ! empty( $input['description'] ) ) {
				$update['post_content'] = $input['description'];
			}

			wp_update_post( $update );
		}

		return self::format_attachment( $attachment_id );
	}

	// ==================================================================
	// FIND MEDIA
	// ==================================================================

	/**
	 * Input schema for find-media
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function find_media_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'query'       => [
					'type'        => 'string',
					'description' => __( 'Search by filename, title, or alt text.', 'bricks' ),
				],
				'mimeType'    => [
					'type'        => 'string',
					'description' => __( 'Filter by MIME type (e.g. "image", "image/jpeg", "application/pdf").', 'bricks' ),
				],
				'limit'       => [
					'type'        => 'integer',
					'description' => __( 'Max results (default 10, max 50).', 'bricks' ),
				],
				'orderBy'     => [
					'type'        => 'string',
					'description' => __( 'Sort order: modified (default), title, date.', 'bricks' ),
					'enum'        => [ 'modified', 'title', 'date' ],
				],
				'detailLevel' => [
					'type'        => 'string',
					'description' => __( 'Response detail: full returns all image size variants (default); compact returns core metadata plus common thumbnail URLs.', 'bricks' ),
					'enum'        => [ 'full', 'compact' ],
				],
			],
		];
	}

	/**
	 * Output schema for find-media
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function find_media_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'results' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'           => [ 'type' => 'integer' ],
							'url'          => [ 'type' => 'string' ],
							'title'        => [ 'type' => 'string' ],
							'alt'          => [ 'type' => 'string' ],
							'filename'     => [ 'type' => 'string' ],
							'mimeType'     => [ 'type' => 'string' ],
							'width'        => [ 'type' => 'integer' ],
							'height'       => [ 'type' => 'integer' ],
							'filesize'     => [ 'type' => 'integer' ],
							'thumbnailUrl' => [ 'type' => 'string' ],
							'mediumUrl'    => [ 'type' => 'string' ],
							'largeUrl'     => [ 'type' => 'string' ],
							'sizes'        => [ 'type' => 'object' ],
						],
					],
				],
				'total'   => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Input schema for delete-media.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_media_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'attachmentId' => [
					'type'        => 'integer',
					'description' => __( 'Media attachment ID to delete.', 'bricks' ),
				],
				'forceDelete'  => [
					'type'        => 'boolean',
					'description' => __( 'Permanently delete instead of trashing when WordPress media trash is enabled. Default false.', 'bricks' ),
				],
			],
			'required'   => [ 'attachmentId' ],
		];
	}

	/**
	 * Output schema for delete-media.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_media_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'      => [ 'type' => 'boolean' ],
				'attachmentId' => [ 'type' => 'integer' ],
				'title'        => [ 'type' => 'string' ],
				'trashed'      => [
					'type'        => 'boolean',
					'description' => __( 'True if moved to trash. False if permanently deleted.', 'bricks' ),
				],
				'beforeDelete' => [
					'type'        => 'object',
					'description' => __( 'Attachment snapshot before deletion.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: find-media (read-only)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function find_media_permission( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return Error::forbidden_builder_permission( 'upload_files' );
		}

		return true;
	}

	/**
	 * Permission: delete-media.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_media_permission( $input ) {
		$attachment_id = self::resolve_attachment_id( $input );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return Error::forbidden_edit_post( $attachment_id );
		}

		return true;
	}

	/**
	 * Callback: search the media library
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function find_media( $input ) {
		$query        = $input['query'] ?? '';
		$mime_type    = $input['mimeType'] ?? '';
		$limit        = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit        = max( 1, min( 50, $limit ) );
		$order_by     = $input['orderBy'] ?? 'modified';
		$detail_level = $input['detailLevel'] ?? 'full';

		if ( ! in_array( $detail_level, [ 'full', 'compact' ], true ) ) {
			return Error::invalid_param( 'detailLevel', 'one of: full, compact', $detail_level );
		}

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'no_found_rows'  => false,
		];

		if ( $query !== '' ) {
			global $wpdb;

			$search_like = '%' . $wpdb->esc_like( $query ) . '%';

			$args['bricks_abilities_media_search'] = true;

			$join_filter = static function ( $join, $query_obj ) use ( $wpdb ) {
				if ( ! $query_obj instanceof \WP_Query || $query_obj->get( 'bricks_abilities_media_search' ) !== true ) {
					return $join;
				}

				$join .= " LEFT JOIN {$wpdb->postmeta} AS bricks_ability_attached_file_meta ON ({$wpdb->posts}.ID = bricks_ability_attached_file_meta.post_id AND bricks_ability_attached_file_meta.meta_key = '_wp_attached_file')";
				$join .= " LEFT JOIN {$wpdb->postmeta} AS bricks_ability_alt_meta ON ({$wpdb->posts}.ID = bricks_ability_alt_meta.post_id AND bricks_ability_alt_meta.meta_key = '_wp_attachment_image_alt')";

				return $join;
			};

			$where_filter = static function ( $where, $query_obj ) use ( $wpdb, $search_like ) {
				if ( ! $query_obj instanceof \WP_Query || $query_obj->get( 'bricks_abilities_media_search' ) !== true ) {
					return $where;
				}

				$where .= $wpdb->prepare(
					" AND (
						{$wpdb->posts}.post_title LIKE %s
						OR {$wpdb->posts}.post_excerpt LIKE %s
						OR {$wpdb->posts}.post_content LIKE %s
						OR bricks_ability_attached_file_meta.meta_value LIKE %s
						OR bricks_ability_alt_meta.meta_value LIKE %s
					)",
					$search_like,
					$search_like,
					$search_like,
					$search_like,
					$search_like
				);

				return $where;
			};

			$distinct_filter = static function ( $distinct, $query_obj ) {
				if ( ! $query_obj instanceof \WP_Query || $query_obj->get( 'bricks_abilities_media_search' ) !== true ) {
					return $distinct;
				}

				return 'DISTINCT';
			};

			add_filter( 'posts_join', $join_filter, 10, 2 );
			add_filter( 'posts_where', $where_filter, 10, 2 );
			add_filter( 'posts_distinct', $distinct_filter, 10, 2 );
		}

		if ( $mime_type !== '' ) {
			$args['post_mime_type'] = $mime_type;
		}

		switch ( $order_by ) {
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'date':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'modified':
			default:
				$args['orderby'] = 'modified';
				$args['order']   = 'DESC';
				break;
		}

		$args     = \Bricks\Media_Browser_Query::apply_media_library_restrictions( $args );
		$wp_query = new \WP_Query( $args );

		if ( $query !== '' ) {
			remove_filter( 'posts_join', $join_filter );
			remove_filter( 'posts_where', $where_filter );
			remove_filter( 'posts_distinct', $distinct_filter );
		}

		$results = [];

		foreach ( $wp_query->posts as $attachment ) {
			$results[] = self::format_attachment( $attachment->ID, $detail_level );
		}

		return [
			'results' => $results,
			'total'   => (int) $wp_query->found_posts,
		];
	}

	/**
	 * Callback: delete a media attachment.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_media( $input ) {
		$attachment_id = self::resolve_attachment_id( $input );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$before       = self::format_attachment( $attachment_id );
		$force_delete = ! empty( $input['forceDelete'] );
		$result       = wp_delete_attachment( $attachment_id, $force_delete );

		if ( ! $result ) {
			return Error::conflict(
				'delete_failed',
				[
					'message'      => sprintf( 'Failed to delete media attachment %d.', $attachment_id ),
					'attachmentId' => $attachment_id,
				]
			);
		}

		$remaining = get_post( $attachment_id );
		$trashed   = $remaining instanceof \WP_Post && $remaining->post_status === 'trash';

		return [
			'deleted'      => true,
			'attachmentId' => $attachment_id,
			'title'        => $before['title'],
			'trashed'      => $trashed,
			'beforeDelete' => $before,
		];
	}

	// ==================================================================
	// INTERNALS
	// ==================================================================

	/**
	 * Resolve and validate an attachment ID from ability input.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error
	 */
	private static function resolve_attachment_id( array $input ) {
		$attachment_id = isset( $input['attachmentId'] ) ? (int) $input['attachmentId'] : 0;

		if ( $attachment_id <= 0 ) {
			return Error::missing_param( 'attachmentId', [ 'attachmentId' ] );
		}

		$post = get_post( $attachment_id );

		if ( ! $post instanceof \WP_Post || $post->post_type !== 'attachment' ) {
			return Error::not_found( 'media attachment', $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Sideload a media file from a remote URL.
	 *
	 * @since 2.4
	 *
	 * @param string $url   Remote file URL.
	 * @param string $title Attachment title.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	private static function sideload_from_url( string $url, string $title = '' ) {
		$url = self::validate_sideload_url( $url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$url = self::validate_sideload_url( $url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$tmp = download_url( $url, self::sideload_timeout() );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$tmp_size = filesize( $tmp );
		$max_size = self::max_media_upload_bytes();

		if ( $tmp_size !== false && $tmp_size > $max_size ) {
			wp_delete_file( $tmp );

			return Error::invalid_param(
				'url',
				sprintf( 'remote media file no larger than %d bytes', $max_size ),
				[
					'receivedBytes' => $tmp_size,
					'maxBytes'      => $max_size,
				]
			);
		}

		$file_array = [
			'name'     => self::filename_from_url( $url ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, 0, $title ? $title : null );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Extract a sideload filename from a URL path.
	 *
	 * @since 2.4
	 *
	 * @param string $url Remote file URL.
	 * @return string
	 */
	private static function filename_from_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( is_string( $path ) && $path !== '' ) {
			$filename = basename( $path );

			if ( is_string( $filename ) && $filename !== '' ) {
				return sanitize_file_name( $filename );
			}
		}

		return 'remote-media';
	}

	/**
	 * Validate a remote URL before passing it to WordPress sideloading.
	 *
	 * `download_url()` uses `wp_safe_remote_get()`, but validating here gives
	 * MCP callers a clear Bricks error before the server performs a fetch.
	 *
	 * @since 2.4
	 *
	 * @param string $url Remote file URL.
	 * @return string|\WP_Error Validated URL or error.
	 */
	private static function validate_sideload_url( string $url ) {
		$validated_url = wp_http_validate_url( $url );

		if ( ! $validated_url ) {
			return Error::invalid_url( $url, 'a public http/https URL without credentials or unsafe ports' );
		}

		$host = wp_parse_url( $validated_url, PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' || ! self::host_resolves_publicly( $host ) ) {
			return Error::invalid_url( $url, 'a public host that does not resolve to a private, loopback, or link-local address' );
		}

		return $validated_url;
	}

	/**
	 * Determine whether a host resolves only to public IP addresses.
	 *
	 * @since 2.4
	 *
	 * @param string $host URL host.
	 * @return bool
	 */
	private static function host_resolves_publicly( string $host ): bool {
		$host = trim( $host, '[] .' );

		if ( $host === '' ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		$ips = [];

		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}

					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$a_records = gethostbynamel( $host );

		if ( is_array( $a_records ) ) {
			$ips = array_merge( $ips, $a_records );
		}

		$ips = array_unique( array_filter( $ips ) );

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an IP is globally routable.
	 *
	 * @since 2.4
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * Maximum media upload size accepted by MCP media helpers.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function max_media_upload_bytes(): int {
		$site_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 10485760;

		return max(
			1,
			(int) apply_filters( 'bricks/abilities/content/max_media_upload_bytes', $site_limit )
		);
	}

	/**
	 * Remote media download timeout in seconds.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function sideload_timeout(): int {
		return max( 1, (int) apply_filters( 'bricks/abilities/content/sideload_timeout', 30 ) );
	}

	/**
	 * Upload a media file from base64-encoded data.
	 *
	 * @since 2.4
	 *
	 * @param string $base64   Base64-encoded file content.
	 * @param string $filename Filename with extension.
	 * @param string $title    Attachment title.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	private static function upload_from_base64( string $base64, string $filename, string $title = '' ) {
		$filename = sanitize_file_name( $filename );

		if ( $filename === '' ) {
			return Error::disallowed_mime( 'filename', $filename, 'safe filename with a WordPress-allowed media extension' );
		}

		$allowed_mimes = get_allowed_mime_types();
		$file_type     = wp_check_filetype( $filename, $allowed_mimes );

		if ( empty( $file_type['ext'] ) || empty( $file_type['type'] ) ) {
			return Error::disallowed_mime( 'filename', $filename, 'filename with a WordPress-allowed media extension' );
		}

		$max_size          = self::max_media_upload_bytes();
		$normalized_base64 = preg_replace( '/\s+/', '', $base64 );

		if ( ! is_string( $normalized_base64 ) ) {
			return Error::invalid_base64( 'valid base64-encoded data' );
		}

		$max_base64_chars = (int) ceil( $max_size / 3 ) * 4 + 4;

		if ( strlen( $normalized_base64 ) > $max_base64_chars ) {
			return Error::invalid_base64(
				sprintf( 'media file no larger than %d bytes', $max_size ),
				[
					'encodedChars' => strlen( $normalized_base64 ),
					'maxBytes'     => $max_size,
				]
			);
		}

		$decoded = base64_decode( $normalized_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes caller-provided upload content.

		if ( $decoded === false ) {
			return Error::invalid_base64( 'valid base64-encoded data' );
		}

		if ( strlen( $decoded ) > $max_size ) {
			return Error::invalid_base64(
				sprintf( 'media file no larger than %d bytes', $max_size ),
				[
					'receivedBytes' => strlen( $decoded ),
					'maxBytes'      => $max_size,
				]
			);
		}

		// wp_upload_bits() skips the upload/sideload prefilters that normally sanitize SVGs.
		$is_svg = $file_type['type'] === 'image/svg+xml' || in_array( strtolower( $file_type['ext'] ), [ 'svg', 'svgz' ], true );
		if ( $is_svg ) {
			$decoded = \Bricks\Svg::sanitize_contents( $decoded );
			if ( $decoded === false ) {
				return Error::conflict( 'upload_failed', [ 'message' => __( 'File not uploaded due to a sanitization error. Please verify the SVG file or get in touch.', 'bricks' ) ] );
			}
		}

		$upload = wp_upload_bits( $filename, null, $decoded );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return Error::conflict(
				'upload_failed',
				[
					'message' => $upload['error'] ?? 'wp_upload_bits returned no file path',
				]
			);
		}

		if ( $is_svg && ! \Bricks\Svg::file_matches_contents( $upload['file'], $decoded ) ) {
			wp_delete_file( $upload['file'] );
			return Error::conflict( 'upload_failed', [ 'message' => __( 'File not uploaded due to a sanitization error. Please verify the SVG file or get in touch.', 'bricks' ) ] );
		}

		$check = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ), $allowed_mimes );

		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			wp_delete_file( $upload['file'] );

			return Error::disallowed_mime( 'base64', $filename, 'file payload matching a WordPress-allowed media type after MIME sniff' );
		}

		$mime_type = (string) $check['type'];

		$attachment = [
			'post_title'     => $title ? $title : pathinfo( $filename, PATHINFO_FILENAME ),
			'post_mime_type' => $mime_type,
			'post_status'    => 'inherit',
			'guid'           => $upload['url'],
		];

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * Does the requested post status require publish capability?
	 *
	 * @since 2.4
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private static function status_requires_publish_cap( string $status ): bool {
		return in_array( $status, [ 'publish', 'future', 'private' ], true );
	}

	/**
	 * Format an attachment post into a consistent output shape.
	 *
	 * @since 2.4
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $detail_level  Output detail level: full or compact.
	 * @return array
	 */
	private static function format_attachment( int $attachment_id, string $detail_level = 'full' ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$post     = get_post( $attachment_id );
		$url      = wp_get_attachment_url( $attachment_id );
		$alt      = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$alt      = $alt !== '' ? $alt : get_post_meta( $attachment_id, '_wp_attachment_alt', true );

		$sizes        = [];
		$compact_urls = [
			'thumbnailUrl' => '',
			'mediumUrl'    => '',
			'largeUrl'     => '',
		];

		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( array_keys( $metadata['sizes'] ) as $size_name ) {
				$size = wp_get_attachment_image_src( $attachment_id, $size_name );

				if ( ! is_array( $size ) || empty( $size[0] ) ) {
					continue;
				}

				$sizes[ $size_name ] = [
					'url'    => (string) $size[0],
					'width'  => (int) ( $size[1] ?? 0 ),
					'height' => (int) ( $size[2] ?? 0 ),
				];

				if ( $size_name === 'thumbnail' ) {
					$compact_urls['thumbnailUrl'] = (string) $size[0];
				} elseif ( $size_name === 'medium' ) {
					$compact_urls['mediumUrl'] = (string) $size[0];
				} elseif ( $size_name === 'large' ) {
					$compact_urls['largeUrl'] = (string) $size[0];
				}
			}
		}

		$attachment = [
			'id'       => $attachment_id,
			'url'      => $url ? $url : '',
			'title'    => $post->post_title ?? '',
			'alt'      => $alt ? $alt : '',
			'filename' => ! empty( $metadata['file'] ) ? basename( $metadata['file'] ) : '',
			'mimeType' => $post->post_mime_type ?? '',
			'width'    => (int) ( $metadata['width'] ?? 0 ),
			'height'   => (int) ( $metadata['height'] ?? 0 ),
			'filesize' => (int) ( $metadata['filesize'] ?? 0 ),
		];

		if ( $detail_level === 'compact' ) {
			return array_merge( $attachment, $compact_urls );
		}

		$attachment['sizes'] = $sizes;

		return $attachment;
	}
}

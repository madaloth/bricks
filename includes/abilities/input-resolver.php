<?php
/**
 * Input resolver
 *
 * Multi-identifier addressing for post/template-scoped abilities.
 * MCP clients can pass `postId`, `slug`, `path` (hierarchical), or `title`
 * instead of being forced to look up IDs up front.
 *
 * Lookup order: postId > slug > path > title.
 * Failures return structured WP_Error via Bricks\Abilities\Error.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Input_Resolver {
	/**
	 * Resolve a post identifier to an integer ID.
	 *
	 * Options:
	 * - `allow_post_types`    array override of the allow-list (defaults to Bricks-enabled + template slug).
	 * - `allow_any_post_type` bool  bypass the allow-list entirely (used by find-post so callers can see non-enabled posts).
	 * - `require_bricks`      bool  reject posts whose post type is not Bricks-enabled (default true).
	 * - `allow_trash`         bool  allow resolving trashed posts (for force-delete cleanup).
	 *
	 * @param array $input Ability input.
	 * @param array $opts  Resolver options.
	 * @return int|\WP_Error Resolved post ID or structured error.
	 */
	public static function resolve_post_id( array $input, array $opts = [] ) {
		$defaults = [
			'allow_post_types'    => null,
			'allow_any_post_type' => false,
			'require_bricks'      => true,
			'allow_trash'         => false,
		];
		$opts     = array_merge( $defaults, $opts );

		$post_id = null;
		$source  = null;

		if ( isset( $input['postId'] ) && is_numeric( $input['postId'] ) ) {
			$post_id = (int) $input['postId'];
			$source  = 'postId';
		} elseif ( ! empty( $input['slug'] ) && is_string( $input['slug'] ) ) {
			$post_id = self::resolve_by_slug( $input['slug'], $opts );
			$source  = 'slug';
		} elseif ( ! empty( $input['path'] ) && is_string( $input['path'] ) ) {
			$post_id = self::resolve_by_path( $input['path'], $opts );
			$source  = 'path';
		} elseif ( ! empty( $input['title'] ) && is_string( $input['title'] ) ) {
			$post_id = self::resolve_by_title( $input['title'], $opts );
			$source  = 'title';
		} else {
			return Error::missing_param( 'postId', [ 'postId', 'slug', 'path', 'title' ] );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! $post_id ) {
			return Error::not_found( 'post', $input[ $source ] ?? null );
		}

		// Validate the post actually exists.
		$post = get_post( $post_id );

		if ( ! $post ) {
			return Error::not_found( 'post', $post_id );
		}

		// Trash check.
		if ( $post->post_status === 'trash' && ! $opts['allow_trash'] ) {
			return Error::post_in_trash( $post_id );
		}

		// Post type allow-list.
		if ( ! $opts['allow_any_post_type'] && $opts['require_bricks'] ) {
			$allowed = $opts['allow_post_types'] ?? self::default_allow_list();

			if ( ! in_array( $post->post_type, $allowed, true ) ) {
				return Error::bricks_not_enabled_on_post_type( $post_id, $post->post_type, $allowed );
			}
		}

		return $post_id;
	}

	/**
	 * Resolve a template identifier.
	 *
	 * Accepts `templateId`, `slug`, or `title`. Always scoped to the
	 * Bricks template post type.
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error
	 */
	public static function resolve_template_id( array $input ) {
		$opts = [
			'allow_post_types' => [ BRICKS_DB_TEMPLATE_SLUG ],
			'require_bricks'   => false,
		];

		if ( isset( $input['templateId'] ) && is_numeric( $input['templateId'] ) ) {
			// Normalize to postId for the shared code path.
			return self::resolve_post_id( [ 'postId' => (int) $input['templateId'] ], $opts );
		}

		if ( isset( $input['popupId'] ) && is_numeric( $input['popupId'] ) ) {
			return self::resolve_post_id( [ 'postId' => (int) $input['popupId'] ], $opts );
		}

		if ( ! empty( $input['slug'] ) || ! empty( $input['title'] ) ) {
			return self::resolve_post_id( $input, $opts );
		}

		return Error::missing_param( 'templateId', [ 'templateId', 'slug', 'title' ] );
	}

	/**
	 * Default allow-list: Bricks-enabled post types + template CPT.
	 *
	 * @return array
	 */
	public static function default_allow_list(): array {
		$settings = \Bricks\Database::$global_settings ?? [];
		$enabled  = isset( $settings['postTypes'] ) && is_array( $settings['postTypes'] )
			? $settings['postTypes']
			: [ 'page', 'post' ];

		$list = array_values( array_unique( array_merge( $enabled, [ BRICKS_DB_TEMPLATE_SLUG ] ) ) );

		return $list;
	}

	/**
	 * Resolve by slug across allowed post types.
	 *
	 * @param string $slug Post slug.
	 * @param array  $opts Resolver options.
	 * @return int|\WP_Error|null
	 */
	private static function resolve_by_slug( string $slug, array $opts ) {
		$post_types = $opts['allow_any_post_type']
			? self::searchable_post_types()
			: ( $opts['allow_post_types'] ?? self::default_allow_list() );

		$posts = get_posts(
			[
				'name'                   => $slug,
				'post_type'              => $post_types,
				'post_status'            => ! empty( $opts['allow_trash'] ) ? [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ] : [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page'         => 5,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		return self::single_match_or_error( $posts, 'post', $slug );
	}

	/**
	 * Resolve a hierarchical path like "parent/child".
	 *
	 * @param string $path Hierarchical path.
	 * @param array  $opts Resolver options.
	 * @return int|\WP_Error|null
	 */
	private static function resolve_by_path( string $path, array $opts ) {
		$path       = ltrim( $path, '/' );
		$post_types = $opts['allow_any_post_type']
			? self::searchable_post_types()
			: ( $opts['allow_post_types'] ?? self::default_allow_list() );

		// Try each candidate post type - get_page_by_path only takes one or an array.
		$post = get_page_by_path( $path, OBJECT, $post_types );

		if ( $post instanceof \WP_Post ) {
			return (int) $post->ID;
		}

		return null;
	}

	/**
	 * Resolve by exact title across allowed post types.
	 *
	 * @param string $title Post title.
	 * @param array  $opts  Resolver options.
	 * @return int|\WP_Error|null
	 */
	private static function resolve_by_title( string $title, array $opts ) {
		$post_types = $opts['allow_any_post_type']
			? self::searchable_post_types()
			: ( $opts['allow_post_types'] ?? self::default_allow_list() );

		$query = new \WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => ! empty( $opts['allow_trash'] ) ? [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ] : [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page'         => 5,
				'title'                  => $title,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'no_found_rows'          => true,
			]
		);

		return self::single_match_or_error( $query->posts, 'post', $title );
	}

	/**
	 * Collapse a result set into a single ID, or return not_found / ambiguous_match.
	 *
	 * @param array  $posts      Matched posts (WP_Post[]).
	 * @param string $resource   Resource label for errors.
	 * @param mixed  $identifier Identifier used for the lookup.
	 * @return int|\WP_Error|null
	 */
	private static function single_match_or_error( array $posts, string $resource, $identifier ) {
		if ( empty( $posts ) ) {
			return null;
		}

		if ( count( $posts ) === 1 ) {
			return (int) $posts[0]->ID;
		}

		$matches = array_map(
			function ( \WP_Post $post ) {
				return [
					'id'       => (int) $post->ID,
					'title'    => $post->post_title,
					'slug'     => $post->post_name,
					'postType' => $post->post_type,
					'status'   => $post->post_status,
				];
			},
			$posts
		);

		return Error::ambiguous_match( $resource, $matches );
	}

	/**
	 * Post types we're willing to search when `allow_any_post_type` is set.
	 *
	 * Limits to publicly-queryable types + template CPT to avoid CPTs with
	 * deliberately private data (e.g. attachments metadata).
	 *
	 * @return array
	 */
	public static function searchable_post_types(): array {
		$public = get_post_types(
			[
				'public'       => true,
				'show_in_rest' => true,
			],
			'names'
		);

		$public[] = BRICKS_DB_TEMPLATE_SLUG;

		return array_values( array_unique( $public ) );
	}
}

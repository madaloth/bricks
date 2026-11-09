<?php
/**
 * CMS structure abilities
 *
 * Client-visible introspection of the content model a Bricks site ships
 * with: custom post types, taxonomies, and field-provider schemas from
 * ACF, JetEngine, Meta Box, CMB2, Pods, Toolset, and WooCommerce. Also
 * exposes small site-setup gaps that are needed while building Bricks sites,
 * such as WordPress reading settings.
 *
 * Dispatch pattern: a single `bricks/list-cms-sources` ability accepts
 * a `source` argument and routes to a per-provider handler. Each handler
 * returns a summary scoped to *what Bricks needs to know* - structure
 * and labels, not CRUD. We are deliberately not a general-purpose
 * field-management API.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cms {

	/**
	 * Supported source keys. Consumers dispatch on these; keep lowercase,
	 * hyphenated, and stable.
	 */
	const SOURCES = [ 'wp', 'acf', 'jetengine', 'metabox', 'cmb2', 'pods', 'toolset', 'woo' ];

	// ------------------------------------------------------------------
	// bricks/list-cms-sources - schemas
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-cms-sources
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_cms_sources_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'source' => [
					'type'        => 'string',
					'description' => __( 'Source to introspect: wp (post types + taxonomies), acf (field groups), jetengine (CCTs + meta boxes), metabox, cmb2, pods, toolset, or woo. Omit to receive a directory of available sources on this site.', 'bricks' ),
					'enum'        => self::SOURCES,
				],
			],
		];
	}

	/**
	 * Output schema for list-cms-sources
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_cms_sources_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'source'    => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'Echoes the requested source, or null when returning the directory.', 'bricks' ),
				],
				'available' => [
					'type'        => 'array',
					'description' => __( 'Per-source availability: [{ source, active, reason? }].', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
				'data'      => [
					'type'        => [ 'object', 'array' ],
					'description' => __( 'Source-specific payload. The shape varies per source and is documented inline.', 'bricks' ),
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// Permission
	// ------------------------------------------------------------------

	/**
	 * Any user with builder access may read CMS structure.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_cms_sources_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Dispatch
	// ------------------------------------------------------------------

	/**
	 * Callback: list-cms-sources
	 *
	 * Dispatches to a per-source handler. When `source` is omitted, returns
	 * the availability directory only - useful as a cheap probe before
	 * picking which source to query.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_cms_sources( $input ) {
		$availability = self::availability();
		$source       = isset( $input['source'] ) ? (string) $input['source'] : '';

		if ( $source === '' ) {
			return [
				'source'    => null,
				'available' => $availability,
				'data'      => [],
			];
		}

		if ( ! in_array( $source, self::SOURCES, true ) ) {
			return Error::invalid_param( 'source', 'one of: ' . implode( ', ', self::SOURCES ), $source );
		}

		$is_active = false;

		foreach ( $availability as $row ) {
			if ( $row['source'] === $source ) {
				$is_active = $row['active'];
				break;
			}
		}

		if ( ! $is_active ) {
			return Error::conflict(
				'source_not_available',
				[
					'message' => sprintf( 'Source "%s" is not active on this site. Install/enable the corresponding plugin or pick a different source.', $source ),
					'source'  => $source,
				]
			);
		}

		$data = self::dispatch( $source );

		return [
			'source'    => $source,
			'available' => $availability,
			'data'      => $data,
		];
	}

	// ------------------------------------------------------------------
	// bricks/get-reading-settings and bricks/set-reading-settings
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-reading-settings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_reading_settings_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [],
			'additionalProperties' => false,
		];
	}

	/**
	 * Input schema for set-reading-settings.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_reading_settings_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'showOnFront'   => [
					'type'        => 'string',
					'description' => __( 'WordPress front page mode. Use `posts` for latest posts or `page` for a static front page.', 'bricks' ),
					'enum'        => [ 'posts', 'page' ],
				],
				'pageOnFront'   => [
					'type'        => 'integer',
					'description' => __( 'Page ID to use as the static homepage. Pass 0 to clear.', 'bricks' ),
				],
				'pageForPosts'  => [
					'type'        => 'integer',
					'description' => __( 'Page ID to use as the posts page. Pass 0 to clear.', 'bricks' ),
				],
				'postsPerPage'  => [
					'type'        => 'integer',
					'description' => __( 'Number of blog posts to show per page (the headline "Blog pages show at most" field on Settings > Reading). Must be a positive integer, max 1000.', 'bricks' ),
					'minimum'     => 1,
					'maximum'     => 1000,
				],
				'postsPerRss'   => [
					'type'        => 'integer',
					'description' => __( 'Number of items to show in syndication (RSS) feeds. Must be a positive integer, max 1000.', 'bricks' ),
					'minimum'     => 1,
					'maximum'     => 1000,
				],
				'rssUseExcerpt' => [
					'type'        => 'boolean',
					'description' => __( 'For each post in a feed, include the excerpt (true) or full text (false).', 'bricks' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for reading settings abilities.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function reading_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'showOnFront'   => [
					'type'        => 'string',
					'description' => __( 'Current `show_on_front` option: posts or page.', 'bricks' ),
				],
				'pageOnFront'   => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Current static homepage summary, or null when unset.', 'bricks' ),
				],
				'pageForPosts'  => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'Current posts page summary, or null when unset.', 'bricks' ),
				],
				'postsPerPage'  => [
					'type'        => 'integer',
					'description' => __( 'Current `posts_per_page` option.', 'bricks' ),
				],
				'postsPerRss'   => [
					'type'        => 'integer',
					'description' => __( 'Current `posts_per_rss` option.', 'bricks' ),
				],
				'rssUseExcerpt' => [
					'type'        => 'boolean',
					'description' => __( 'Current `rss_use_excerpt` option.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission for reading-settings writes and reads.
	 *
	 * @since 2.4
	 *
	 * @return true|\WP_Error
	 */
	public static function reading_settings_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Error::forbidden_builder_permission( 'manage_options' );
		}

		return true;
	}

	/**
	 * Callback: get WordPress reading settings.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_reading_settings( $input ) {
		return [
			'showOnFront'   => get_option( 'show_on_front', 'posts' ),
			'pageOnFront'   => self::format_reading_page( get_option( 'page_on_front' ) ),
			'pageForPosts'  => self::format_reading_page( get_option( 'page_for_posts' ) ),
			'postsPerPage'  => (int) get_option( 'posts_per_page', 10 ),
			'postsPerRss'   => (int) get_option( 'posts_per_rss', 10 ),
			'rssUseExcerpt' => (bool) (int) get_option( 'rss_use_excerpt', 0 ),
		];
	}

	/**
	 * Callback: set WordPress reading settings.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_reading_settings( $input ) {
		$allowed_keys = [ 'showOnFront', 'pageOnFront', 'pageForPosts', 'postsPerPage', 'postsPerRss', 'rssUseExcerpt' ];
		$unknown      = array_diff( array_keys( $input ), $allowed_keys );

		if ( ! empty( $unknown ) ) {
			return Error::unknown_param( reset( $unknown ), $allowed_keys );
		}

		if ( empty( $input ) ) {
			return Error::invalid_param( 'settings', 'at least one of: ' . implode( ', ', $allowed_keys ), $input );
		}

		$show_on_front = array_key_exists( 'showOnFront', $input ) ? $input['showOnFront'] : get_option( 'show_on_front', 'posts' );

		if ( ! in_array( $show_on_front, [ 'posts', 'page' ], true ) ) {
			return Error::invalid_param( 'showOnFront', 'one of: posts, page', $show_on_front );
		}

		$page_on_front  = array_key_exists( 'pageOnFront', $input ) ? $input['pageOnFront'] : absint( get_option( 'page_on_front' ) );
		$page_for_posts = array_key_exists( 'pageForPosts', $input ) ? $input['pageForPosts'] : absint( get_option( 'page_for_posts' ) );

		foreach ( [
			'pageOnFront'  => $page_on_front,
			'pageForPosts' => $page_for_posts
		] as $param => $page_id ) {
			$validation = self::validate_reading_page_id( $param, $page_id );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( $show_on_front === 'page' && ! absint( $page_on_front ) ) {
			return Error::invalid_param( 'pageOnFront', 'a valid page ID when showOnFront is `page`', $page_on_front );
		}

		if ( $show_on_front === 'page' && absint( $page_on_front ) > 0 && absint( $page_on_front ) === absint( $page_for_posts ) ) {
			return Error::invalid_param( 'pageForPosts', 'a different page ID than pageOnFront', $page_for_posts );
		}

		// Validate per-page counters before any writes.
		foreach ( [ 'postsPerPage', 'postsPerRss' ] as $count_param ) {
			if ( ! array_key_exists( $count_param, $input ) ) {
				continue;
			}

			$value = $input[ $count_param ];

			if ( ! is_int( $value ) || $value < 1 || $value > 1000 ) {
				return Error::invalid_param( $count_param, 'a positive integer between 1 and 1000', $value );
			}
		}

		if ( array_key_exists( 'rssUseExcerpt', $input ) && ! is_bool( $input['rssUseExcerpt'] ) ) {
			return Error::invalid_param( 'rssUseExcerpt', 'a boolean', $input['rssUseExcerpt'] );
		}

		if ( array_key_exists( 'showOnFront', $input ) ) {
			update_option( 'show_on_front', $show_on_front );
		}

		if ( array_key_exists( 'pageOnFront', $input ) ) {
			update_option( 'page_on_front', absint( $page_on_front ) );
		}

		if ( array_key_exists( 'pageForPosts', $input ) ) {
			update_option( 'page_for_posts', absint( $page_for_posts ) );
		}

		if ( array_key_exists( 'postsPerPage', $input ) ) {
			update_option( 'posts_per_page', (int) $input['postsPerPage'] );
		}

		if ( array_key_exists( 'postsPerRss', $input ) ) {
			update_option( 'posts_per_rss', (int) $input['postsPerRss'] );
		}

		if ( array_key_exists( 'rssUseExcerpt', $input ) ) {
			update_option( 'rss_use_excerpt', $input['rssUseExcerpt'] ? 1 : 0 );
		}

		return self::get_reading_settings( [] );
	}

	/**
	 * Validate a page ID used by WordPress reading settings.
	 *
	 * @since 2.4
	 *
	 * @param string $param   Parameter name.
	 * @param mixed  $page_id Page ID.
	 * @return true|\WP_Error
	 */
	private static function validate_reading_page_id( $param, $page_id ) {
		if ( ! is_int( $page_id ) ) {
			return Error::invalid_param( $param, 'an integer page ID', $page_id );
		}

		if ( $page_id === 0 ) {
			return true;
		}

		if ( $page_id < 0 ) {
			return Error::invalid_param( $param, 'a positive page ID or 0 to clear', $page_id );
		}

		$post = get_post( $page_id );

		if ( ! $post || $post->post_type !== 'page' ) {
			return Error::invalid_param( $param, 'an existing WordPress page ID', $page_id );
		}

		if ( in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return Error::invalid_param( $param, 'a page that is not trashed or auto-draft', $page_id );
		}

		return true;
	}

	/**
	 * Format a reading-settings page reference.
	 *
	 * @since 2.4
	 *
	 * @param mixed $page_id Page ID.
	 * @return array|null
	 */
	private static function format_reading_page( $page_id ) {
		$page_id = absint( $page_id );

		if ( ! $page_id ) {
			return null;
		}

		$post = get_post( $page_id );

		if ( ! $post ) {
			return [
				'id'      => $page_id,
				'missing' => true,
			];
		}

		return [
			'id'        => $page_id,
			'title'     => get_the_title( $post ),
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'editUrl'   => get_edit_post_link( $page_id, '' ),
			'permalink' => get_permalink( $page_id ),
		];
	}

	/**
	 * Report availability of every known source.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function availability(): array {
		$probe = [
			'wp'        => true,
			'acf'       => function_exists( 'acf_get_field_groups' ),
			'jetengine' => class_exists( '\\Jet_Engine' ),
			'metabox'   => function_exists( 'rwmb_meta' ) || class_exists( '\\RWMB_Core' ),
			'cmb2'      => class_exists( '\\CMB2' ),
			'pods'      => function_exists( 'pods' ),
			'toolset'   => defined( 'TYPES_VERSION' ) || class_exists( '\\Types_Main' ),
			'woo'       => class_exists( '\\WooCommerce' ),
		];

		$rows = [];

		foreach ( self::SOURCES as $source ) {
			$rows[] = [
				'source' => $source,
				'active' => (bool) ( $probe[ $source ] ?? false ),
				'reason' => ( $probe[ $source ] ?? false ) ? '' : 'Source plugin not detected on this site.',
			];
		}

		return $rows;
	}

	/**
	 * Route to the per-source handler.
	 *
	 * @since 2.4
	 *
	 * @param string $source
	 * @return array
	 */
	private static function dispatch( string $source ): array {
		switch ( $source ) {
			case 'wp':
				return self::list_wp();
			case 'acf':
				return self::list_acf();
			case 'jetengine':
				return self::list_jetengine();
			case 'metabox':
				return self::list_metabox();
			case 'cmb2':
				return self::list_cmb2();
			case 'pods':
				return self::list_pods();
			case 'toolset':
				return self::list_toolset();
			case 'woo':
				return self::list_woo();
		}

		return [];
	}

	// ------------------------------------------------------------------
	// Source: wp
	// ------------------------------------------------------------------

	/**
	 * Core WP post types + taxonomies, with a Bricks-enabled flag per CPT.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_wp(): array {
		$bricks_enabled_cpts = [];

		Manager::flush_options_cache();
		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if ( is_array( $settings ) && isset( $settings['postTypes'] ) && is_array( $settings['postTypes'] ) ) {
			$bricks_enabled_cpts = $settings['postTypes'];
		}

		$post_types = [];

		foreach ( get_post_types( [], 'objects' ) as $pt ) {
			$post_types[] = [
				'slug'          => $pt->name,
				'label'         => $pt->label,
				'public'        => (bool) $pt->public,
				'hierarchical'  => (bool) $pt->hierarchical,
				'bricksEnabled' => in_array( $pt->name, $bricks_enabled_cpts, true ) || in_array( $pt->name, [ 'page', 'post' ], true ),
				'supports'      => array_values( array_keys( array_filter( get_all_post_type_supports( $pt->name ) ) ) ),
				'restBase'      => $pt->rest_base ? $pt->rest_base : $pt->name,
				'showInRest'    => (bool) $pt->show_in_rest,
			];
		}

		$taxonomies = [];

		foreach ( get_taxonomies( [], 'objects' ) as $tx ) {
			$taxonomies[] = [
				'slug'         => $tx->name,
				'label'        => $tx->label,
				'public'       => (bool) $tx->public,
				'hierarchical' => (bool) $tx->hierarchical,
				'objectTypes'  => $tx->object_type,
			];
		}

		return [
			'postTypes'  => $post_types,
			'taxonomies' => $taxonomies,
		];
	}

	// ------------------------------------------------------------------
	// Source: acf
	// ------------------------------------------------------------------

	/**
	 * ACF field groups with fields and location rules.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_acf(): array {
		$groups = [];

		foreach ( acf_get_field_groups() as $group ) {
			$fields        = acf_get_fields( $group );
			$field_summary = [];

			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					$field_summary[] = [
						'key'      => $field['key'] ?? '',
						'name'     => $field['name'] ?? '',
						'label'    => $field['label'] ?? '',
						'type'     => $field['type'] ?? '',
						'required' => ! empty( $field['required'] ),
					];
				}
			}

			$groups[] = [
				'key'      => $group['key'] ?? '',
				'title'    => $group['title'] ?? '',
				'active'   => ! isset( $group['active'] ) ? true : (bool) $group['active'],
				'location' => $group['location'] ?? [],
				'fields'   => $field_summary,
			];
		}

		return [ 'fieldGroups' => $groups ];
	}

	// ------------------------------------------------------------------
	// Source: jetengine
	// ------------------------------------------------------------------

	/**
	 * JetEngine CCTs + meta boxes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_jetengine(): array {
		$ccts = [];

		if ( function_exists( 'jet_engine' ) ) {
			$je = jet_engine();

			if ( isset( $je->modules ) && method_exists( $je->modules, 'is_module_active' ) && $je->modules->is_module_active( 'custom-content-types' ) ) {
				$module = $je->modules->get_module( 'custom-content-types' );

				if (
					$module &&
					isset( $module->instance ) &&
					is_object( $module->instance ) &&
					isset( $module->instance->data ) &&
					is_object( $module->instance->data ) &&
					method_exists( $module->instance->data, 'get_items' )
				) {
					$items = $module->instance->data->get_items();

					foreach ( (array) $items as $item ) {
						$ccts[] = [
							'slug'   => $item['slug'] ?? '',
							'title'  => $item['name'] ?? '',
							'fields' => isset( $item['meta_fields'] ) ? array_map(
								static function ( $f ) {
									return [
										'name'  => $f['name'] ?? '',
										'title' => $f['title'] ?? '',
										'type'  => $f['type'] ?? '',
									];
								},
								(array) $item['meta_fields']
							) : [],
						];
					}
				}
			}
		}

		return [ 'customContentTypes' => $ccts ];
	}

	// ------------------------------------------------------------------
	// Source: metabox
	// ------------------------------------------------------------------

	/**
	 * Meta Box registered meta boxes with fields.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_metabox(): array {
		$boxes = [];

		if ( function_exists( 'rwmb_get_registry' ) ) {
			$registry = rwmb_get_registry( 'meta_box' );

			if ( $registry && method_exists( $registry, 'all' ) ) {
				foreach ( $registry->all() as $box ) {
					$meta_box = $box->meta_box ?? [];
					$fields   = [];

					foreach ( $meta_box['fields'] ?? [] as $field ) {
						$fields[] = [
							'id'   => $field['id'] ?? '',
							'name' => $field['name'] ?? '',
							'type' => $field['type'] ?? '',
						];
					}

					$boxes[] = [
						'id'        => $meta_box['id'] ?? '',
						'title'     => $meta_box['title'] ?? '',
						'postTypes' => $meta_box['post_types'] ?? [],
						'fields'    => $fields,
					];
				}
			}
		}

		return [ 'metaBoxes' => $boxes ];
	}

	// ------------------------------------------------------------------
	// Source: cmb2
	// ------------------------------------------------------------------

	/**
	 * CMB2 registered boxes.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_cmb2(): array {
		$boxes = [];

		if ( function_exists( 'cmb2_boxes' ) ) {
			foreach ( cmb2_boxes() as $box ) {
				if ( ! is_object( $box ) || ! method_exists( $box, 'prop' ) ) {
					continue;
				}

				$fields = [];

				if ( method_exists( $box, 'prop' ) ) {
					$raw_fields = $box->prop( 'fields' );

					foreach ( (array) $raw_fields as $field ) {
						$fields[] = [
							'id'   => $field['id'] ?? '',
							'name' => $field['name'] ?? '',
							'type' => $field['type'] ?? '',
						];
					}
				}

				$boxes[] = [
					'id'         => $box->cmb_id ?? '',
					'title'      => $box->prop( 'title' ),
					'objectType' => $box->prop( 'object_types' ),
					'fields'     => $fields,
				];
			}
		}

		return [ 'boxes' => $boxes ];
	}

	// ------------------------------------------------------------------
	// Source: pods
	// ------------------------------------------------------------------

	/**
	 * Pods configurations.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_pods(): array {
		$items = [];

		if ( function_exists( 'pods_api' ) ) {
			$pods = pods_api()->load_pods( [ 'names' => false ] );

			foreach ( (array) $pods as $pod ) {
				$fields = [];

				foreach ( (array) ( $pod['fields'] ?? [] ) as $field ) {
					$fields[] = [
						'name'  => $field['name'] ?? '',
						'label' => $field['label'] ?? '',
						'type'  => $field['type'] ?? '',
					];
				}

				$items[] = [
					'name'   => $pod['name'] ?? '',
					'label'  => $pod['label'] ?? '',
					'type'   => $pod['type'] ?? '',
					'fields' => $fields,
				];
			}
		}

		return [ 'pods' => $items ];
	}

	// ------------------------------------------------------------------
	// Source: toolset
	// ------------------------------------------------------------------

	/**
	 * Toolset Types - deliberately thin. Toolset's introspection surface
	 * is large; we expose just the CPTs it registered so clients can cross
	 * reference against the `wp` source.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_toolset(): array {
		$post_types = [];

		if ( function_exists( 'wpcf_get_active_custom_types' ) ) {
			foreach ( (array) wpcf_get_active_custom_types() as $slug => $def ) {
				$post_types[] = [
					'slug'  => $slug,
					'label' => $def['labels']['name'] ?? $slug,
				];
			}
		}

		return [ 'postTypes' => $post_types ];
	}

	// ------------------------------------------------------------------
	// Source: woo
	// ------------------------------------------------------------------

	/**
	 * WooCommerce product types + attribute taxonomies.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function list_woo(): array {
		$product_types = [];

		if ( function_exists( 'wc_get_product_types' ) ) {
			foreach ( wc_get_product_types() as $slug => $label ) {
				$product_types[] = [
					'slug'  => $slug,
					'label' => $label,
				];
			}
		}

		$attributes = [];

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				$attributes[] = [
					'slug'       => 'pa_' . ( $attr->attribute_name ?? '' ),
					'label'      => $attr->attribute_label ?? '',
					'orderBy'    => $attr->attribute_orderby ?? '',
					'publicView' => (bool) ( $attr->attribute_public ?? false ),
				];
			}
		}

		return [
			'productTypes'        => $product_types,
			'attributeTaxonomies' => $attributes,
		];
	}
}

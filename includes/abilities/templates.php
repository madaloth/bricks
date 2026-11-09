<?php
/**
 * Template abilities
 *
 * List, read, create, delete templates and manage template settings.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Templates {
	/**
	 * Valid template types
	 *
	 * @since 2.4
	 *
	 * @var array
	 */
	private static $valid_types = [
		'header',
		'footer',
		'content',
		'section',
		'popup',
		'archive',
		'search',
		'error',
		'password_protection',
		'wc_archive',
		'wc_product',
		'wc_cart',
		'wc_cart_empty',
		'wc_form_checkout',
		'wc_form_pay',
		'wc_thankyou',
		'wc_order_receipt',
		'wc_account_dashboard',
		'wc_account_orders',
		'wc_account_view_order',
		'wc_account_downloads',
		'wc_account_addresses',
		'wc_account_form_edit_address',
		'wc_account_form_edit_account',
		'wc_account_form_login',
		'wc_account_form_lost_password',
		'wc_account_form_lost_password_confirmation',
		'wc_account_reset_password',
		'wc_account_payment_methods',
		'wc_account_add_payment_method',
	];

	// ------------------------------------------------------------------
	// Shared schemas
	// ------------------------------------------------------------------

	/**
	 * Schema: templateId only
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function template_id_schema() {
		return [
			'type'       => 'object',
			'properties' => self::template_identifier_properties(),
		];
	}

	/**
	 * Shared property bag for template identifier schemas.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function template_identifier_properties() {
		return [
			'templateId' => [
				'type'        => 'integer',
				'description' => __( 'Template ID. The primary identifier; prefer this whenever it is known.', 'bricks' ),
			],
			'slug'       => [
				'type'        => 'string',
				'description' => __( 'Template slug. Alternative to templateId.', 'bricks' ),
			],
			'title'      => [
				'type'        => 'string',
				'description' => __( 'Exact template title. Alternative to `templateId`; ambiguous when multiple templates share the same title.', 'bricks' ),
			],
		];
	}

	/**
	 * Resolve the template ID from input (for execute callbacks).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error
	 */
	public static function get_resolved_template_id( array $input ) {
		return Input_Resolver::resolve_template_id( $input );
	}

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: list templates
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if (
			! \Bricks\Builder_Permissions::user_has_permission( 'create_templates' ) &&
			! \Bricks\Builder_Permissions::user_has_permission( 'edit_templates' ) &&
			! \Bricks\Builder_Permissions::user_has_permission( 'insert_templates' )
		) {
			return Error::forbidden_builder_permission( 'edit_templates|insert_templates' );
		}

		return true;
	}

	/**
	 * Shared template resolve + authorize helper.
	 *
	 * Resolves templateId/slug/title to int, confirms edit cap, builder perm,
	 * and (for writes) acquires the post lock.
	 *
	 * @since 2.4
	 *
	 * @param array  $input      Ability input.
	 * @param string $permission Builder permission to check.
	 * @param bool   $is_write   Whether to acquire the post lock.
	 * @return true|\WP_Error
	 */
	private static function resolve_and_authorize_template( array $input, string $permission, bool $is_write = false ) {
		$template_id = Input_Resolver::resolve_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		if ( ! current_user_can( 'edit_post', $template_id ) ) {
			return Error::forbidden_edit_post( $template_id );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) {
			return Error::forbidden_builder_permission( $permission );
		}

		if ( $is_write ) {
			Manager::ensure_post_admin_loaded();
			$locked_by = wp_check_post_lock( $template_id );

			if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
				return Error::locked_by_other_user( $template_id, (int) $locked_by );
			}

			wp_set_post_lock( $template_id );
		}

		return true;
	}

	/**
	 * Permission: read a specific template
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		return self::resolve_and_authorize_template( $input, 'edit_templates', false );
	}

	/**
	 * Permission: create template
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function create_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'create_templates' ) ) {
			return Error::forbidden_builder_permission( 'create_templates' );
		}

		if ( ! empty( $input['settings'] ) && ! \Bricks\Builder_Permissions::user_has_permission( 'access_template_settings' ) ) {
			return Error::forbidden_builder_permission( 'access_template_settings' );
		}

		return true;
	}

	/**
	 * Permission: delete template
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_permission( $input ) {
		return self::resolve_and_authorize_template( $input, 'delete_templates', true );
	}

	/**
	 * Permission: template settings
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function settings_read_permission( $input ) {
		return self::resolve_and_authorize_template( $input, 'access_template_settings', false );
	}

	/**
	 * Permission: write template settings.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function settings_write_permission( $input ) {
		return self::resolve_and_authorize_template( $input, 'access_template_settings', true );
	}

	/**
	 * Permission: insert template into a target post.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function insert_permission( $input ) {
		$target_check = self::authorize_insert_target( $input );

		if ( is_wp_error( $target_check ) ) {
			return $target_check;
		}

		$source_id = (int) ( $input['sourceTemplateId'] ?? ( $input['templateId'] ?? 0 ) );

		if ( $source_id <= 0 ) {
			return Error::invalid_param( 'sourceTemplateId', 'positive integer', $source_id );
		}

		$source_post = get_post( $source_id );

		if ( ! $source_post || $source_post->post_type !== BRICKS_DB_TEMPLATE_SLUG ) {
			return Error::invalid_param( 'sourceTemplateId', 'a Bricks template post ID', $source_id );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'insert_templates' ) ) {
			return Error::forbidden_builder_permission( 'insert_templates' );
		}

		return true;
	}

	/**
	 * Permission: insert remote template into a target post.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function insert_remote_template_permission( $input ) {
		$target_check = self::authorize_insert_target( $input );

		if ( is_wp_error( $target_check ) ) {
			return $target_check;
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_remote_templates' ) ) {
			return Error::forbidden_builder_permission( 'access_remote_templates' );
		}

		$design_mode = self::remote_template_design_import_mode( $input );

		if ( is_wp_error( $design_mode ) ) {
			return $design_mode;
		}

		if ( $design_mode !== 'none' ) {
			$required_permissions = [
				'edit_color_palettes',
				'access_theme_styles',
				'create_global_classes',
				'access_variable_manager',
			];

			foreach ( $required_permissions as $permission ) {
				if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) {
					return Error::forbidden_builder_permission( $permission );
				}
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return Error::forbidden_builder_permission( 'manage_options' );
			}
		}

		return true;
	}

	/**
	 * Authorize target post for template insertion and acquire its lock.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error Resolved target post ID.
	 */
	private static function authorize_insert_target( array $input ) {
		$target_id = (int) ( $input['targetPostId'] ?? 0 );

		if ( $target_id <= 0 ) {
			return Error::invalid_param( 'targetPostId', 'positive integer', $target_id );
		}

		$target_id = Input_Resolver::resolve_post_id( [ 'postId' => $target_id ] );

		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return Error::forbidden_edit_post( $target_id );
		}

		if ( ! \Bricks\Capabilities::current_user_can_use_builder( $target_id ) ) {
			return Error::forbidden_builder_access( $target_id );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'insert_templates' ) ) {
			return Error::forbidden_builder_permission( 'insert_templates' );
		}

		Manager::ensure_post_admin_loaded();
		$locked_by = wp_check_post_lock( $target_id );

		if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
			return Error::locked_by_other_user( $target_id, (int) $locked_by );
		}

		wp_set_post_lock( $target_id );

		return $target_id;
	}

	/**
	 * Permission: remote template library access.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function remote_templates_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_remote_templates' ) ) {
			return Error::forbidden_builder_permission( 'access_remote_templates' );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// bricks/list-templates
	// ------------------------------------------------------------------

	/**
	 * Input schema for list-templates
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_templates_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				[
					'type' => [
						'type'        => 'string',
						'description' => __( 'Filter by template type: header, footer, content, section, popup, archive, search, error, password_protection, or WooCommerce types.', 'bricks' ),
					],
				],
				Manager::pagination_schema_properties()
			),
		];
	}

	/**
	 * Output schema for list-templates
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_templates_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'        => 'array',
					'description' => __( 'Array of template summaries with id, title, type, status, editUrl, conditionCount.', 'bricks' ),
				],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
				'hasMore' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: list all templates
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_templates( $input ) {
		$type = $input['type'] ?? '';

		$args = [
			'post_type'              => BRICKS_DB_TEMPLATE_SLUG,
			'posts_per_page'         => -1,
			'post_status'            => 'any',
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'cache_results'          => false,
			'update_post_meta_cache' => false,
		];

		// Filter by type via meta query
		if ( $type ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bricks stores template type in post meta.
				[
					'key'   => BRICKS_DB_TEMPLATE_TYPE,
					'value' => $type,
				],
			];
		}

		$posts     = get_posts( $args );
		$templates = [];

		foreach ( $posts as $post ) {
			$template_type   = get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, true );
			$conditions      = \Bricks\Helpers::get_template_settings( $post->ID );
			$condition_count = 0;

			if ( is_array( $conditions ) && ! empty( $conditions['templateConditions'] ) ) {
				$condition_count = count( $conditions['templateConditions'] );
			}

			$templates[] = [
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'type'           => $template_type ? $template_type : 'content',
				'status'         => $post->post_status,
				'editUrl'        => \Bricks\Helpers::get_builder_edit_link( $post->ID ),
				'conditionCount' => $condition_count,
			];
		}

		return Reference::paginate( $templates, $input );
	}

	// ------------------------------------------------------------------
	// bricks/get-template
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_template_schema() {
		$properties = self::template_identifier_properties();

		$properties['includeElements'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether to include the template element tree. Defaults to true.', 'bricks' ),
		];

		$properties['includeSettings'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether to include template settings. Defaults to true.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * Output schema for get-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_template_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'integer' ],
				'title'    => [ 'type' => 'string' ],
				'type'     => [ 'type' => 'string' ],
				'status'   => [ 'type' => 'string' ],
				'elements' => [ 'type' => 'array' ],
				'settings' => [ 'type' => 'object' ],
				'editUrl'  => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Callback: get template data
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_template( $input ) {
		$template_id = self::get_resolved_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		Manager::flush_post_cache( $template_id );
		$post = get_post( $template_id );

		if ( ! $post ) {
			return Error::not_found( 'template', $template_id );
		}

		$template_type    = get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true );
		$include_elements = ! array_key_exists( 'includeElements', $input ) || $input['includeElements'];
		$include_settings = ! array_key_exists( 'includeSettings', $input ) || $input['includeSettings'];

		$response = [
			'id'      => $template_id,
			'title'   => $post->post_title,
			'type'    => $template_type ? $template_type : 'content',
			'status'  => $post->post_status,
			'editUrl' => \Bricks\Helpers::get_builder_edit_link( $template_id ),
		];

		if ( $include_elements ) {
			$area     = ( $template_type === 'header' || $template_type === 'footer' ) ? $template_type : 'content';
			$elements = \Bricks\Database::get_data( $template_id, $area );

			$response['elements'] = is_array( $elements ) ? Elements::redact_code_sensitive_elements( $elements ) : [];
		}

		if ( $include_settings ) {
			$settings = \Bricks\Helpers::get_template_settings( $template_id );

			$response['settings'] = is_array( $settings ) ? $settings : [];
		}

		return $response;
	}

	// ------------------------------------------------------------------
	// bricks/create-template
	// ------------------------------------------------------------------

	/**
	 * Input schema for create-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_template_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'title'    => [
					'type'        => 'string',
					'description' => __( 'Template title.', 'bricks' ),
					'required'    => true,
				],
				'type'     => [
					'type'        => 'string',
					'description' => __( 'Template type: header, footer, content, section, popup, archive, search, error, password_protection, or a WooCommerce template type such as wc_product.', 'bricks' ),
					'required'    => true,
				],
				'status'   => [
					'type'        => 'string',
					'description' => __( 'Initial WordPress status for the template. Defaults to draft.', 'bricks' ),
					'enum'        => [ 'draft', 'publish', 'private' ],
				],
				'elements' => [
					'type'        => 'array',
					'description' => __( 'Optional initial elements (nested format accepted). Element `id` values are internal 6-character Bricks IDs used by builder references and default frontend selectors. In nested format, you may omit ids and Bricks generates them while preserving parent-child nesting. In flat format, ids and parent references must be valid 6-character ids.', 'bricks' ),
				],
				'settings' => [
					'type'        => 'object',
					'description' => __( 'Optional template settings (conditions, etc.).', 'bricks' ),
				],
			],
			'required'   => [ 'title', 'type' ],
		];
	}

	/**
	 * Output schema for create-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function create_template_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'templateId' => [ 'type' => 'integer' ],
				'editUrl'    => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Callback: create a new template
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function create_template( $input ) {
		$title    = sanitize_text_field( $input['title'] );
		$type     = sanitize_text_field( $input['type'] );
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		$elements = null;

		// Validate template type
		if ( ! in_array( $type, self::$valid_types, true ) ) {
			return Error::invalid_param(
				'type',
				'one of: ' . implode( ', ', self::$valid_types ),
				$type
			);
		}

		if ( ! in_array( $status, [ 'draft', 'publish', 'private' ], true ) ) {
			return Error::invalid_param( 'status', 'one of: draft, publish, private', $status );
		}

		if ( in_array( $status, [ 'publish', 'private' ], true ) && ! current_user_can( 'publish_posts' ) ) {
			return Error::forbidden_builder_permission( 'publish_posts' );
		}

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

		// Reject duplicate titles - wp_insert_post only auto-suffixes the slug,
		// not the title, so callers would otherwise create indistinguishable
		// templates that share the same name in the admin list.
		$existing = get_posts(
			[
				'post_type'              => BRICKS_DB_TEMPLATE_SLUG,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'title'                  => $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		if ( ! empty( $existing ) ) {
			return Error::conflict_duplicate_name(
				'template',
				$title,
				[ 'existingId' => (int) $existing[0] ]
			);
		}

		// Create template post
		$post_id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
				'post_status' => $status,
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set template type
		update_post_meta( $post_id, BRICKS_DB_TEMPLATE_TYPE, $type );

		// Save elements if provided
		if ( is_array( $elements ) && ! empty( $elements ) ) {
			$area   = ( $type === 'header' || $type === 'footer' ) ? $type : 'content';
			$result = Save_Pipeline::execute( $post_id, $elements, $area );

			if ( is_wp_error( $result ) ) {
				wp_delete_post( $post_id, true );
				return $result;
			}
		}

		// Save settings if provided
		if ( ! empty( $input['settings'] ) ) {
			\Bricks\Helpers::set_template_settings( $post_id, $input['settings'] );
		}

		return [
			'templateId' => $post_id,
			'editUrl'    => \Bricks\Helpers::get_builder_edit_link( $post_id ),
			'status'     => $status,
		];
	}

	// ------------------------------------------------------------------
	// bricks/delete-template
	// ------------------------------------------------------------------

	/**
	 * Output schema for delete-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function delete_template_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success'      => [ 'type' => 'boolean' ],
				'deletedId'    => [ 'type' => 'integer' ],
				'beforeDelete' => [
					'type'        => 'object',
					'description' => __( 'Snapshot of the template as it existed immediately before deletion. Use it to verify the intended target was deleted.', 'bricks' ),
					'properties'  => [
						'id'           => [ 'type' => 'integer' ],
						'title'        => [ 'type' => 'string' ],
						'type'         => [ 'type' => 'string' ],
						'elementCount' => [ 'type' => 'integer' ],
					],
				],
			],
		];
	}

	/**
	 * Callback: delete a template
	 *
	 * Destructive. wp_delete_post with force=true permanently removes the
	 * post; there is no Bricks revision path to restore it. The response
	 * includes a `beforeDelete` snapshot so callers can show the user what
	 * was removed.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function delete_template( $input ) {
		$template_id = self::get_resolved_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		$post          = get_post( $template_id );
		$template_type = \Bricks\Templates::get_template_type( $template_id );
		$area          = ( $template_type === 'header' || $template_type === 'footer' ) ? $template_type : 'content';
		$elements      = \Bricks\Database::get_data( $template_id, $area );

		$before = [
			'id'           => $template_id,
			'title'        => $post ? $post->post_title : '',
			'type'         => get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true ),
			'elementCount' => is_array( $elements ) ? count( $elements ) : 0,
		];

		$result = wp_delete_post( $template_id, true );

		if ( ! $result ) {
			return Error::conflict(
				'delete_failed',
				[
					'message'    => sprintf( 'Failed to delete template %d.', $template_id ),
					'templateId' => $template_id,
				]
			);
		}

		return [
			'success'      => true,
			'deletedId'    => $template_id,
			'beforeDelete' => $before,
		];
	}

	// ------------------------------------------------------------------
	// bricks/get-template-settings
	// ------------------------------------------------------------------

	/**
	 * Output schema for get-template-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_template_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings'   => [ 'type' => 'object' ],
				'templateId' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: get template settings
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_template_settings( $input ) {
		$template_id = self::get_resolved_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		Manager::flush_post_cache( $template_id );
		$settings = \Bricks\Helpers::get_template_settings( $template_id );

		return [
			'settings'   => is_array( $settings ) ? $settings : [],
			'templateId' => $template_id,
		];
	}

	// ------------------------------------------------------------------
	// bricks/set-template-settings
	// ------------------------------------------------------------------

	/**
	 * Input schema for set-template-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_template_settings_schema() {
		$properties             = self::template_identifier_properties();
		$properties['settings'] = [
			'type'        => 'object',
			'description' => __( 'Settings to merge. Only provided keys are updated.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'settings' ],
		];
	}

	/**
	 * Output schema for set-template-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_template_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings'   => [ 'type' => 'object' ],
				'templateId' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: set template settings via partial merge
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function set_template_settings( $input ) {
		$template_id = self::get_resolved_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		Manager::flush_post_cache( $template_id );
		$new_settings = $input['settings'];
		$existing     = \Bricks\Helpers::get_template_settings( $template_id );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Deep merge settings
		$settings = Elements::deep_merge( $existing, $new_settings );

		// Save
		\Bricks\Helpers::set_template_settings( $template_id, $settings );

		return [
			'settings'   => $settings,
			'templateId' => $template_id,
		];
	}

	// ==================================================================
	// bricks/set-template-conditions
	// ==================================================================

	/**
	 * Input schema for set-template-conditions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_template_conditions_schema() {
		$properties = self::template_identifier_properties();

		$condition_schema = [
			'type'                 => 'object',
			'properties'           => [
				'main'                        => [
					'type'        => 'string',
					'description' => __( 'Condition kind. Use `any` for entire website (NOT `entireWebsite`). Use `terms` for term-based matching (NOT `archiveTerm`). `hook` is accepted for legacy data only and is normalized to `any` when `hookName` is present.', 'bricks' ),
					'enum'        => [ 'any', 'frontpage', 'postType', 'archiveType', 'search', 'error', 'terms', 'ids', 'hook' ],
				],
				'postType'                    => [
					'type'        => 'array',
					'description' => __( 'When `main=postType`: list of post-type slugs (e.g. `["post", "page", "book"]`). When omitted, the template applies to all post types.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
				'archiveType'                 => [
					'type'        => 'array',
					'description' => __( 'When `main=archiveType`: one or more of `any`, `postType`, `author`, `date`, `term`.', 'bricks' ),
					'items'       => [
						'type' => 'string',
						'enum' => [ 'any', 'postType', 'author', 'date', 'term' ],
					],
				],
				'archivePostTypes'            => [
					'type'        => 'array',
					'description' => __( 'When `archiveType` includes `postType`: post-type slugs.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
				'archiveTerms'                => [
					'type'        => 'array',
					'description' => __( 'When `archiveType` includes `term`: term identifiers in `taxonomy::{id}` or `taxonomy::all` form (e.g. `"category::5"`, `"post_tag::12"`, `"product_cat::all"`). NOT raw term IDs.', 'bricks' ),
					'items'       => [
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]+::([0-9]+|all)$',
					],
				],
				'archiveTermsIncludeChildren' => [
					'type'        => 'boolean',
					'description' => __( 'Apply to child terms too.', 'bricks' ),
				],
				'terms'                       => [
					'type'        => 'array',
					'description' => __( 'When `main=terms`: term identifiers in `taxonomy::{id}` form (e.g. `"category::5"`). The runtime `taxonomy::all` branch only applies to archiveTerms.', 'bricks' ),
					'items'       => [
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]+::[0-9]+$',
					],
				],
				'ids'                         => [
					'type'        => 'array',
					'description' => __( 'When `main=ids`: individual post IDs (integers, not strings).', 'bricks' ),
					'items'       => [ 'type' => 'integer' ],
				],
				'idsIncludeChildren'          => [
					'type'        => 'boolean',
					'description' => __( 'When `main=ids`: apply to child pages of each listed post.', 'bricks' ),
				],
				'hookName'                    => [
					'type'        => 'string',
					'description' => __( 'Section templates only. WP hook to render the template on, e.g. `bricks_before_footer`. Combine with any normal condition to limit where the hook runs.', 'bricks' ),
				],
				'hookPriority'                => [
					'type'        => 'integer',
					'description' => __( 'Section templates only. Hook priority (default 10).', 'bricks' ),
				],
				'exclude'                     => [
					'type'        => 'boolean',
					'description' => __( 'Invert the condition: exclude matching contexts instead of including them.', 'bricks' ),
				],
			],
			'required'             => [ 'main' ],
			'additionalProperties' => false,
		];

		return [
			'type'       => 'object',
			'properties' => array_merge(
				$properties,
				[
					'conditions' => [
						'type'        => 'array',
						'description' => __( 'Array of condition objects. Pass an empty array to clear all conditions.', 'bricks' ),
						'required'    => true,
						'items'       => $condition_schema,
					],
				]
			),
			'required'   => [ 'conditions' ],
		];
	}

	/**
	 * Output schema for set-template-conditions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_template_conditions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'templateId'     => [ 'type' => 'integer' ],
				'conditions'     => [ 'type' => 'array' ],
				'conditionCount' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: set-template-conditions
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_template_conditions( $input ) {
		$template_id = self::get_resolved_template_id( $input );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		$conditions = $input['conditions'] ?? [];

		if ( ! is_array( $conditions ) ) {
			return Error::invalid_param( 'conditions', 'an array of condition objects', $conditions );
		}

		$valid_main           = [ 'any', 'frontpage', 'postType', 'archiveType', 'search', 'error', 'terms', 'ids', 'hook' ];
		$archive_term_pattern = '/^[a-z0-9_-]+::([0-9]+|all)$/';
		$term_pattern         = '/^[a-z0-9_-]+::[0-9]+$/';
		$template_type        = \Bricks\Templates::get_template_type( $template_id );

		foreach ( $conditions as $index => $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['main'] ) ) {
				return Error::invalid_param( "conditions[{$index}].main", 'a condition kind string', $condition );
			}

			$main = $condition['main'];

			if ( ! in_array( $main, $valid_main, true ) ) {
				return Error::invalid_param(
					"conditions[{$index}].main",
					'one of: ' . implode( ', ', $valid_main ) . ' (use `any` not `entireWebsite`, `terms` not `archiveTerm`)',
					$main
				);
			}

			if ( ! empty( $condition['hookName'] ) && $template_type !== 'section' ) {
				return Error::invalid_param( "conditions[{$index}].hookName", 'hookName is only valid for section templates', $condition['hookName'] );
			}

			if ( $main === 'hook' ) {
				if ( empty( $condition['hookName'] ) ) {
					return Error::invalid_param( "conditions[{$index}].hookName", 'a non-empty string when main=hook', $condition['hookName'] ?? null );
				}

				$conditions[ $index ]['main'] = 'any';
			}

			$array_fields = [
				'postType'         => 'an array of post-type slugs, e.g. ["post", "page", "book"]',
				'archiveType'      => 'an array of archive kinds, e.g. ["postType"]',
				'archivePostTypes' => 'an array of post-type slugs, e.g. ["book"]',
				'archiveTerms'     => 'an array of strings in `taxonomy::id` or `taxonomy::all` format',
				'terms'            => 'an array of strings in `taxonomy::id` format',
				'ids'              => 'an array of integer post IDs',
			];

			foreach ( $array_fields as $field => $expected ) {
				if ( isset( $condition[ $field ] ) && ! is_array( $condition[ $field ] ) ) {
					return Error::invalid_param( "conditions[{$index}].{$field}", $expected, $condition[ $field ] );
				}
			}

			foreach ( [ 'postType', 'archivePostTypes' ] as $post_type_key ) {
				if ( empty( $condition[ $post_type_key ] ) ) {
					continue;
				}

				foreach ( $condition[ $post_type_key ] as $post_type_index => $post_type ) {
					if ( ! is_string( $post_type ) || $post_type === '' ) {
						return Error::invalid_param( "conditions[{$index}].{$post_type_key}[{$post_type_index}]", 'a non-empty post-type slug string', $post_type );
					}
				}
			}

			if ( ! empty( $condition['archiveType'] ) ) {
				$valid_archive_types = [ 'any', 'postType', 'author', 'date', 'term' ];

				foreach ( $condition['archiveType'] as $archive_type_index => $archive_type ) {
					if ( ! is_string( $archive_type ) || ! in_array( $archive_type, $valid_archive_types, true ) ) {
						return Error::invalid_param(
							"conditions[{$index}].archiveType[{$archive_type_index}]",
							'one of: ' . implode( ', ', $valid_archive_types ),
							$archive_type
						);
					}
				}
			}

			if ( ! empty( $condition['ids'] ) ) {
				foreach ( $condition['ids'] as $id_index => $id ) {
					if ( ! is_int( $id ) ) {
						return Error::invalid_param( "conditions[{$index}].ids[{$id_index}]", 'an integer post ID (not a numeric string)', $id );
					}
				}
			}

			foreach ( [ 'terms', 'archiveTerms' ] as $term_key ) {
				if ( empty( $condition[ $term_key ] ) || ! is_array( $condition[ $term_key ] ) ) {
					continue;
				}
				foreach ( $condition[ $term_key ] as $term_index => $term_id ) {
					$pattern = $term_key === 'archiveTerms' ? $archive_term_pattern : $term_pattern;

					if ( ! is_string( $term_id ) || ! preg_match( $pattern, $term_id ) ) {
						$expected = $term_key === 'archiveTerms'
							? 'string in `taxonomy::id` or `taxonomy::all` format (e.g., `category::5` or `product_cat::all`)'
							: 'string in `taxonomy::id` format (e.g., `category::5`)';

						return Error::invalid_param(
							"conditions[{$index}].{$term_key}[{$term_index}]",
							$expected,
							$term_id
						);
					}
				}
			}
		}

		Manager::flush_post_cache( $template_id );
		$settings = \Bricks\Helpers::get_template_settings( $template_id );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$settings['templateConditions'] = array_values( $conditions );

		\Bricks\Helpers::set_template_settings( $template_id, $settings );

		return [
			'templateId'     => $template_id,
			'conditions'     => $settings['templateConditions'],
			'conditionCount' => count( $settings['templateConditions'] ),
		];
	}

	// ==================================================================
	// bricks/list-template-sources
	// ==================================================================

	/**
	 * Input schema for list-template-sources
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_template_sources_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Output schema for list-template-sources
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_template_sources_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'sources' => [
					'type'        => 'array',
					'description' => __( 'Every configured template source: Bricks built-ins (wireframes, design-sets), admin-configured remote URLs, and group headers.', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}

	/**
	 * Callback: list-template-sources
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_template_sources( $input ) {
		$sources = \Bricks\Templates::get_remote_template_settings();

		return [ 'sources' => is_array( $sources ) ? self::redact_template_sources( $sources ) : [] ];
	}

	/**
	 * Redact credential values from template source rows.
	 *
	 * Core template-source helpers include passwords because the builder uses
	 * the same shape to fetch remote templates. The MCP list response only needs to
	 * expose whether a password exists.
	 *
	 * @since 2.4
	 *
	 * @param array $sources Template source rows.
	 * @return array
	 */
	private static function redact_template_sources( array $sources ): array {
		$redacted = [];

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				$redacted[] = $source;
				continue;
			}

			if ( array_key_exists( 'password', $source ) ) {
				$source['passwordConfigured'] = trim( (string) $source['password'] ) !== '';
				unset( $source['password'] );
			}

			$redacted[] = $source;
		}

		return $redacted;
	}

	// ==================================================================
	// bricks/list-remote-templates
	// ==================================================================

	/**
	 * Input schema for list-remote-templates
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_remote_templates_schema() {
		$properties = [
			'source' => [
				'type'        => 'string',
				'description' => __( 'Source `id` or `url` from list-template-sources. Examples: `wireframes`, `design-sets`, or a full https URL.', 'bricks' ),
				'required'    => true,
			],
			'mode'   => [
				'type'        => 'string',
				'enum'        => [ 'summary', 'full' ],
				'description' => __( 'Return lightweight template metadata by default. Use `full` only when you need the complete template payload for a specific import/inspection workflow.', 'bricks' ),
			],
		];

		$properties = array_merge( $properties, Manager::pagination_schema_properties( 100 ) );

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'source' ],
		];
	}

	/**
	 * Output schema for list-remote-templates
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_remote_templates_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'source'    => [ 'type' => 'string' ],
				'mode'      => [ 'type' => 'string' ],
				'templates' => [ 'type' => 'array' ],
				'total'     => [ 'type' => 'integer' ],
				'page'      => [ 'type' => 'integer' ],
				'perPage'   => [ 'type' => 'integer' ],
				'hasMore'   => [ 'type' => 'boolean' ],
				'tags'      => [ 'type' => 'array' ],
				'bundles'   => [ 'type' => 'array' ],
				'authors'   => [ 'type' => 'array' ],
				'error'     => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	/**
	 * Callback: list-remote-templates
	 *
	 * Wraps the core Templates::get_remote_templates_data() which reads
	 * `$_POST['templateSource']` directly. We set that super-global for
	 * the duration of the call and restore it afterwards - ugly but
	 * unavoidable without a core refactor.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_remote_templates( $input ) {
		$source   = self::resolve_remote_template_source( (string) $input['source'] );
		$mode     = isset( $input['mode'] ) ? (string) $input['mode'] : 'summary';
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $input['perPage'] ?? 25 ) ) );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( ! in_array( $mode, [ 'summary', 'full' ], true ) ) {
			return Error::invalid_param( 'mode', 'summary or full', $mode );
		}

		if ( ! in_array( $source, [ 'wireframes', 'design-sets', 'bricksTemplatesGroupTitle', 'remoteTemplatesGroupTitle' ], true ) ) {
			$validated_source = Settings::validate_remote_template_fetch_url( $source, 'source' );

			if ( is_wp_error( $validated_source ) ) {
				return $validated_source;
			}

			$source = $validated_source;
		}

		$data = self::get_remote_templates_data_for_source( $source );

		if ( ! is_array( $data ) ) {
			return [
				'source'    => $source,
				'mode'      => $mode,
				'templates' => [],
				'total'     => 0,
				'page'      => $page,
				'perPage'   => $per_page,
				'hasMore'   => false,
				'tags'      => [],
				'bundles'   => [],
				'authors'   => [],
				'error'     => 'Remote template source returned an invalid response.',
			];
		}

		if ( ! empty( $data['error'] ) ) {
			return [
				'source'    => $source,
				'mode'      => $mode,
				'templates' => [],
				'total'     => 0,
				'page'      => $page,
				'perPage'   => $per_page,
				'hasMore'   => false,
				'tags'      => [],
				'bundles'   => [],
				'authors'   => [],
				'error'     => (string) $data['error'],
			];
		}

		$templates = self::remote_template_list_value( $data['templates'] ?? [], $mode === 'summary' );
		$total     = count( $templates );
		$offset    = ( $page - 1 ) * $per_page;

		return [
			'source'    => $source,
			'mode'      => $mode,
			'templates' => array_values( array_slice( $templates, $offset, $per_page ) ),
			'total'     => $total,
			'page'      => $page,
			'perPage'   => $per_page,
			'hasMore'   => $offset + $per_page < $total,
			'tags'      => self::remote_template_list_value( $data['tags'] ?? [] ),
			'bundles'   => self::remote_template_list_value( $data['bundles'] ?? [] ),
			'authors'   => self::remote_template_list_value( $data['authors'] ?? [] ),
			'error'     => null,
		];
	}

	/**
	 * Normalize remote template response fields to JSON arrays.
	 *
	 * Some built-in sources return associative maps for metadata buckets. The
	 * MCP output schema promises arrays, so normalize every bucket to a list
	 * before the adapter validates the result.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Raw response bucket.
	 * @param bool  $summary_only Whether to collapse template payloads to metadata.
	 * @return array
	 */
	private static function remote_template_list_value( $value, bool $summary_only = false ): array {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		$items = is_array( $value ) ? array_values( $value ) : [];

		if ( ! $summary_only ) {
			return $items;
		}

		return array_map( [ __CLASS__, 'remote_template_summary_value' ], $items );
	}

	/**
	 * Collapse a remote template payload to client-friendly metadata.
	 *
	 * @since 2.4
	 *
	 * @param mixed $template Raw remote template row.
	 * @return mixed
	 */
	private static function remote_template_summary_value( $template ) {
		if ( $template instanceof \stdClass ) {
			$template = (array) $template;
		}

		if ( ! is_array( $template ) ) {
			return $template;
		}

		$summary_keys = [
			'id',
			'title',
			'name',
			'slug',
			'type',
			'templateType',
			'category',
			'tags',
			'bundle',
			'bundles',
			'author',
			'screenshot',
			'thumbnail',
			'preview',
			'url',
			'created',
			'updated',
		];
		$summary      = [];

		foreach ( $summary_keys as $key ) {
			if ( array_key_exists( $key, $template ) ) {
				$summary[ $key ] = $template[ $key ];
			}
		}

		if ( ! empty( $summary ) ) {
			return $summary;
		}

		foreach ( $template as $key => $value ) {
			if ( is_scalar( $value ) || $value === null ) {
				$summary[ $key ] = $value;
			}
		}

		return $summary;
	}

	/**
	 * Resolve a caller-provided source to a built-in source ID or saved URL.
	 *
	 * The underlying builder helper accepts any `templateSource` string and can
	 * turn it into an outbound request URL. MCP should only invoke known sources:
	 * built-in Bricks IDs or URLs already configured in Bricks settings.
	 *
	 * @since 2.4
	 *
	 * @param string $source Raw source value.
	 * @return string|\WP_Error
	 */
	private static function resolve_remote_template_source( string $source ) {
		$source = trim( $source );

		if ( $source === '' ) {
			return Error::invalid_param( 'source', 'a built-in source ID or configured remote template URL', $source );
		}

		$sources = \Bricks\Templates::get_remote_template_settings();

		if ( ! is_array( $sources ) ) {
			return Error::invalid_param( 'source', 'a built-in source ID or configured remote template URL', $source );
		}

		foreach ( $sources as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			if ( ! empty( $candidate['id'] ) && (string) $candidate['id'] === $source ) {
				return $source;
			}

			if ( ! empty( $candidate['url'] ) && (string) $candidate['url'] === $source && $source !== 'remoteTemplatesGroupTitle' ) {
				return $source;
			}
		}

		return Error::invalid_param( 'source', 'one of the IDs or URLs returned by list-template-sources', $source );
	}

	/**
	 * Fetch remote template-library data for a validated source.
	 *
	 * Core reads `$_POST['templateSource']` directly, so keep the superglobal
	 * shim isolated in one place until the lower-level helper accepts arguments.
	 *
	 * @since 2.4
	 *
	 * @param string $source Built-in source ID or configured remote URL.
	 * @return array|\WP_Error
	 */
	private static function get_remote_templates_data_for_source( string $source ) {
		$saved_post              = $_POST;
		$_POST['templateSource'] = $source;

		try {
			$data = \Bricks\Templates::get_remote_templates_data();
		} finally {
			$_POST = $saved_post;
		}

		return $data;
	}

	// ==================================================================
	// bricks/insert-remote-template
	// ==================================================================

	/**
	 * Input schema for insert-remote-template.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function insert_remote_template_schema() {
		$properties = self::insert_template_placement_schema_properties();

		unset( $properties['sourceTemplateId'], $properties['templateId'] );

		$properties = array_merge(
			[
				'source'               => [
					'type'        => 'string',
					'description' => __( 'Remote template source `id` or configured URL from list-template-sources. Examples: `wireframes` or `design-sets`.', 'bricks' ),
					'required'    => true,
				],
				'remoteTemplateId'     => [
					'type'        => [ 'integer', 'string' ],
					'description' => __( 'Remote template ID returned by list-remote-templates.', 'bricks' ),
				],
				'remoteTemplateName'   => [
					'type'        => 'string',
					'description' => __( 'Remote template slug/name returned by list-remote-templates. Alternative to remoteTemplateId.', 'bricks' ),
				],
				'remoteTemplateTitle'  => [
					'type'        => 'string',
					'description' => __( 'Exact remote template title. Alternative to remoteTemplateId.', 'bricks' ),
				],
				'remoteTemplateBundle' => [
					'type'        => 'string',
					'description' => __( 'Optional bundle slug returned by list-remote-templates, such as `reality`. Required to disambiguate remoteTemplateId values that repeat across design-set bundles.', 'bricks' ),
				],
				'importDesignAssets'   => [
					'type'        => 'string',
					'enum'        => [ 'missing', 'all', 'none' ],
					'description' => __( 'Whether to import design assets referenced by the remote template. `missing` imports only absent assets. `all` mirrors accepting the builder import prompts and updates matching variables/classes/theme styles/palettes. Defaults to `missing`.', 'bricks' ),
				],
				'applyThemeStyle'      => [
					'type'        => 'boolean',
					'description' => __( 'When true, the last imported or updated remote theme style is applied to the entire site by adding an `any` condition. Useful for MCP imports where there is no builder prompt to select the imported theme style.', 'bricks' ),
				],
				'importImages'         => [
					'type'        => 'boolean',
					'description' => __( 'When true, remote image settings are imported into the media library using the same conversion helper as the builder template popup. Defaults to false, matching the builder import-images toggle when it is off.', 'bricks' ),
				],
			],
			$properties
		);

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'targetPostId', 'source' ],
		];
	}

	/**
	 * Output schema for insert-remote-template.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function insert_remote_template_output_schema() {
		$schema = self::insert_template_output_schema();

		$schema['properties'] = array_merge(
			[
				'remoteSource'        => [ 'type' => 'string' ],
				'remoteTemplateId'    => [ 'type' => [ 'integer', 'string', 'null' ] ],
				'remoteTemplateTitle' => [ 'type' => [ 'string', 'null' ] ],
			],
			$schema['properties'],
			[
				'importedDesignAssets' => [ 'type' => 'object' ],
				'skippedDesignAssets'  => [ 'type' => 'object' ],
				'warnings'             => [ 'type' => 'array' ],
			]
		);

		unset( $schema['properties']['sourceTemplateId'] );

		return $schema;
	}

	/**
	 * Callback: insert-remote-template.
	 *
	 * Fetches a remote library template, optionally imports missing design
	 * assets, then uses the same placement/save path as local template inserts.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function insert_remote_template( $input ) {
		$target_id   = (int) ( $input['targetPostId'] ?? 0 );
		$source      = self::resolve_remote_template_source( (string) ( $input['source'] ?? '' ) );
		$position    = (string) ( $input['position'] ?? 'end' );
		$anchor_id   = isset( $input['anchorElementId'] ) ? (string) $input['anchorElementId'] : '';
		$area_key    = (string) ( $input['areaKey'] ?? 'content' );
		$design_mode = self::remote_template_design_import_mode( $input );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( is_wp_error( $design_mode ) ) {
			return $design_mode;
		}

		if ( ! in_array( $source, [ 'wireframes', 'design-sets', 'bricksTemplatesGroupTitle', 'remoteTemplatesGroupTitle' ], true ) ) {
			$validated_source = Settings::validate_remote_template_fetch_url( $source, 'source' );

			if ( is_wp_error( $validated_source ) ) {
				return $validated_source;
			}

			$source = $validated_source;
		}

		$data = self::get_remote_templates_data_for_source( $source );

		if ( ! is_array( $data ) ) {
			return Error::invalid_param( 'source', 'remote template source returning template data', $source );
		}

		if ( ! empty( $data['error'] ) ) {
			return Error::invalid_param( 'source', 'remote template source without errors', (string) $data['error'] );
		}

		$template = self::resolve_remote_template_from_data( $data, $input );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$source_elements = self::remote_template_elements_for_area( $template, $area_key );

		if ( is_wp_error( $source_elements ) ) {
			return $source_elements;
		}

		$target_validation = self::validate_insert_target( $target_id, $position, $anchor_id, $area_key );

		if ( is_wp_error( $target_validation ) ) {
			return $target_validation;
		}

		$design_snapshot = $design_mode === 'none' ? [] : self::snapshot_design_options();
		$apply_style     = ! empty( $input['applyThemeStyle'] );
		$import_images   = ! empty( $input['importImages'] );
		$style_bundle    = isset( $input['remoteTemplateBundle'] ) ? (string) $input['remoteTemplateBundle'] : '';

		$design_result             = self::import_remote_template_design_assets( $template, $data, $source_elements, $design_mode, $apply_style, $style_bundle );
		$source_elements           = $design_result['elements'];
		$normalize_result          = self::normalize_remote_template_elements_for_insert( $source_elements );
		$source_elements           = $normalize_result['elements'];
		$design_result['warnings'] = array_merge( $design_result['warnings'], $normalize_result['warnings'] );
		$convert_result            = self::convert_remote_template_elements_for_insert( $source_elements, $import_images );
		$source_elements           = $convert_result['elements'];
		$design_result['warnings'] = array_merge( $design_result['warnings'], $convert_result['warnings'] );

		$result = self::insert_elements_into_target(
			$target_id,
			$source_elements,
			$position,
			$anchor_id,
			$area_key
		);

		if ( is_wp_error( $result ) ) {
			$restore_result = self::restore_design_options( $design_snapshot );

			if ( is_wp_error( $restore_result ) ) {
				return $restore_result;
			}

			return $result;
		}

		unset( $result['sourceTemplateId'] );

		return array_merge(
			[
				'remoteSource'        => $source,
				'remoteTemplateId'    => $template['id'] ?? null,
				'remoteTemplateTitle' => $template['title'] ?? null,
			],
			$result,
			[
				'importedDesignAssets' => $design_result['imported'],
				'skippedDesignAssets'  => $design_result['skipped'],
				'warnings'             => $design_result['warnings'],
			]
		);
	}

	/**
	 * Convert remote template elements through the same compatibility steps as
	 * the builder template popup before the shared ability save path validates.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Remote template elements.
	 * @param bool  $import_images Whether to sideload remote images.
	 * @return array
	 */
	private static function convert_remote_template_elements_for_insert( array $elements, bool $import_images ): array {
		$warnings = [];

		\Bricks\Templates::$template_images = [];

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$elements[ $index ]['settings'] = [];
			} else {
				\Bricks\Templates::import_images( $element['settings'], $import_images );
			}

			if ( ! isset( $element['children'] ) || ! is_array( $element['children'] ) ) {
				$elements[ $index ]['children'] = [];
			}
		}

		if ( count( \Bricks\Templates::$template_images ) ) {
			$elements_encoded = wp_json_encode( $elements );

			foreach ( \Bricks\Templates::$template_images as $template_image ) {
				$elements_encoded = str_replace(
					wp_json_encode( $template_image['old'] ),
					wp_json_encode( $template_image['new'] ),
					$elements_encoded
				);
			}

			$decoded = json_decode( $elements_encoded, true );

			if ( is_array( $decoded ) ) {
				$elements = $decoded;
			} else {
				$warnings[] = 'Remote template image conversion produced invalid JSON; inserted original image settings.';
			}
		}

		\Bricks\Templates::$template_images = [];

		return [
			'elements' => $elements,
			'warnings' => $warnings,
		];
	}

	/**
	 * Normalize remote template design import mode.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return string|\WP_Error
	 */
	private static function remote_template_design_import_mode( array $input ) {
		$mode = isset( $input['importDesignAssets'] ) ? (string) $input['importDesignAssets'] : 'missing';

		if ( ! in_array( $mode, [ 'missing', 'all', 'none' ], true ) ) {
			return Error::invalid_param( 'importDesignAssets', 'missing, all, or none', $mode );
		}

		return $mode;
	}

	/**
	 * Resolve a remote template row from a fetched remote-library payload.
	 *
	 * @since 2.4
	 *
	 * @param array $data  Remote source data.
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function resolve_remote_template_from_data( array $data, array $input ) {
		$templates = self::remote_template_list_value( $data['templates'] ?? [] );

		if ( empty( $templates ) ) {
			return Error::not_found( 'remote_templates', (string) ( $input['source'] ?? '' ) );
		}

		$id      = isset( $input['remoteTemplateId'] ) ? (string) $input['remoteTemplateId'] : '';
		$name    = isset( $input['remoteTemplateName'] ) ? (string) $input['remoteTemplateName'] : '';
		$title   = isset( $input['remoteTemplateTitle'] ) ? (string) $input['remoteTemplateTitle'] : '';
		$bundle  = isset( $input['remoteTemplateBundle'] ) ? (string) $input['remoteTemplateBundle'] : '';
		$matches = [];

		foreach ( $templates as $template ) {
			if ( $template instanceof \stdClass ) {
				$template = (array) $template;
			}

			if ( ! is_array( $template ) ) {
				continue;
			}

			$template_names   = array_values(
				array_unique(
					array_filter(
						array_map(
							'strval',
							[
								$template['name'] ?? '',
								$template['slug'] ?? '',
							]
						)
					)
				)
			);
			$template_bundles = array_values(
				array_unique(
					array_filter(
						array_map(
							'strval',
							array_merge(
								(array) ( $template['bundles'] ?? [] ),
								(array) ( $template['bundle'] ?? [] )
							)
						)
					)
				)
			);

			if ( $id !== '' && (string) ( $template['id'] ?? '' ) !== $id ) {
				continue;
			}

			if ( $name !== '' && ! in_array( $name, $template_names, true ) ) {
				continue;
			}

			if ( $title !== '' && (string) ( $template['title'] ?? '' ) !== $title ) {
				continue;
			}

			if ( $bundle !== '' && ! in_array( $bundle, $template_bundles, true ) ) {
				continue;
			}

			$matches[] = $template;
		}

		if ( count( $matches ) === 1 ) {
			return $matches[0];
		}

		if ( count( $matches ) > 1 ) {
			$candidates = array_map(
				function( $template ) {
					return sprintf(
						'%s (%s)',
						(string) ( $template['title'] ?? $template['name'] ?? $template['id'] ?? '' ),
						implode( ',', array_map( 'strval', (array) ( $template['bundles'] ?? [] ) ) )
					);
				},
				$matches
			);

			return Error::invalid_param( 'remoteTemplateId', 'a unique remote template identifier; add remoteTemplateName, remoteTemplateTitle, or remoteTemplateBundle. Matches: ' . implode( '; ', $candidates ), $id );
		}

		$received = $id;

		if ( $received === '' ) {
			$received = $name;
		}

		if ( $received === '' ) {
			$received = $title;
		}

		return Error::not_found( 'remote_template', $received );
	}

	/**
	 * Get the remote template element area to insert.
	 *
	 * @since 2.4
	 *
	 * @param array  $template Remote template row.
	 * @param string $area_key Target area.
	 * @return array|\WP_Error
	 */
	private static function remote_template_elements_for_area( array $template, string $area_key ) {
		$area_key = in_array( $area_key, [ 'content', 'header', 'footer' ], true ) ? $area_key : 'content';
		$elements = $template[ $area_key ] ?? null;

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			foreach ( [ 'content', 'header', 'footer' ] as $fallback_area ) {
				if ( is_array( $template[ $fallback_area ] ?? null ) && ! empty( $template[ $fallback_area ] ) ) {
					return $template[ $fallback_area ];
				}
			}

			return Error::not_found( 'remote_template_elements', (string) ( $template['id'] ?? '' ) );
		}

		return $elements;
	}

	/**
	 * Normalize legacy remote-library element shapes before strict ability validation.
	 *
	 * The builder template popup accepts older remote payload shapes and normalizes
	 * them client-side. Remote-template abilities need the same compatibility layer
	 * before the shared element validator runs.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Remote template elements.
	 * @return array
	 */
	private static function normalize_remote_template_elements_for_insert( array $elements ): array {
		$warnings         = [];
		$component_ids    = [];
		$local_components = get_option( BRICKS_DB_COMPONENTS, [] );
		$local_components = is_array( $local_components ) ? $local_components : [];

		foreach ( $local_components as $component ) {
			if ( ! empty( $component['id'] ) ) {
				$component_ids[ (string) $component['id'] ] = true;
			}
		}

		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['cid'] ) && empty( $component_ids[ (string) $element['cid'] ] ) && ! empty( $element['name'] ) ) {
				$warnings[] = sprintf(
					"Removed unresolved remote component reference '%s' from element '%s' because the remote source did not provide that component definition.",
					(string) $element['cid'],
					(string) ( $element['id'] ?? '' )
				);
				unset( $element['cid'], $element['properties'], $element['variant'], $element['slotChildren'] );
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				self::normalize_remote_template_settings_for_insert( $element['settings'], $warnings, (string) ( $element['id'] ?? '' ) );
			}
		}

		unset( $element );

		$unknown_result = self::remove_unavailable_remote_template_elements( $elements );
		$elements       = $unknown_result['elements'];
		$warnings       = array_merge( $warnings, $unknown_result['warnings'] );

		return [
			'elements' => $elements,
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Remove remote elements that are unavailable in the current install.
	 *
	 * Remote design sets can contain integration elements from plugins that are
	 * inactive on the target site. The builder import path tolerates that looser
	 * payload; the ability validator is stricter, so normalize before saving.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Remote template elements.
	 * @return array
	 */
	private static function remove_unavailable_remote_template_elements( array $elements ): array {
		if ( empty( \Bricks\Elements::$elements ) ) {
			return [
				'elements' => $elements,
				'warnings' => [],
			];
		}

		$warnings = [];
		$removed  = [];
		$by_id    = [];
		$kept     = [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$name = (string) ( $element['name'] ?? '' );
			$id   = (string) ( $element['id'] ?? '' );

			if ( empty( $element['cid'] ) && $name !== '' && ! isset( \Bricks\Elements::$elements[ $name ] ) ) {
				$removed[ $id ] = $element;
				$warnings[]     = "Removed unavailable remote element type '{$name}' on element '{$id}'.";
				continue;
			}

			$kept[] = $element;
		}

		if ( ! count( $removed ) ) {
			return [
				'elements' => $elements,
				'warnings' => [],
			];
		}

		$collect_children = function( string $id ) use ( &$collect_children, $removed, $by_id ): array {
			$children = [];

			foreach ( $removed[ $id ]['children'] ?? [] as $child_id ) {
				$child_id = (string) $child_id;

				if ( isset( $removed[ $child_id ] ) ) {
					$children = array_merge( $children, $collect_children( $child_id ) );
				} elseif ( isset( $by_id[ $child_id ] ) ) {
					$children[] = $child_id;
				}
			}

			return $children;
		};

		foreach ( $kept as &$element ) {
			$parent = isset( $element['parent'] ) ? (string) $element['parent'] : '';

			if ( isset( $removed[ $parent ] ) ) {
				while ( isset( $removed[ $parent ] ) ) {
					$parent = (string) ( $removed[ $parent ]['parent'] ?? '0' );
				}

				$element['parent'] = $parent;
			}

			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$children = [];

				foreach ( $element['children'] as $child_id ) {
					$child_id = (string) $child_id;

					if ( isset( $removed[ $child_id ] ) ) {
						$children = array_merge( $children, $collect_children( $child_id ) );
					} else {
						$children[] = $child_id;
					}
				}

				$element['children'] = array_values( array_unique( $children ) );
			}
		}

		unset( $element );

		return [
			'elements' => array_values( $kept ),
			'warnings' => $warnings,
		];
	}

	/**
	 * Normalize legacy remote-template setting values before strict validation.
	 *
	 * @since 2.4
	 *
	 * @param array  $settings  Element settings.
	 * @param array  $warnings  Warning messages.
	 * @param string $element_id Element id for warning context.
	 * @return void
	 */
	private static function normalize_remote_template_settings_for_insert( array &$settings, array &$warnings, string $element_id ): void {
		if ( isset( $settings['query'] ) && is_array( $settings['query'] ) && empty( $settings['query']['objectType'] ) ) {
			$settings['query']['objectType'] = 'post';
			$warnings[]                      = "Added missing query objectType 'post' on remote element '{$element_id}'.";
		}

		foreach ( $settings as $key => &$value ) {
			if ( $key === 'link' && ! is_array( $value ) ) {
				if ( is_string( $value ) && $value !== '' ) {
					$value      = [
						'type' => 'external',
						'url'  => $value,
					];
					$warnings[] = "Converted legacy string link setting on remote element '{$element_id}' to an external link object.";
				} else {
					unset( $settings[ $key ] );
					$warnings[] = "Removed empty legacy link setting on remote element '{$element_id}'.";
				}

				continue;
			}

			if ( is_array( $value ) ) {
				self::normalize_remote_template_settings_for_insert( $value, $warnings, $element_id );
			}
		}

		unset( $value );
	}

	/**
	 * Snapshot design-system options before remote-template asset import.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function snapshot_design_options(): array {
		$missing  = (object) [ 'bricks_abilities_missing_option' => true ];
		$snapshot = [];

		foreach ( self::design_option_names() as $option_name ) {
			$value                    = get_option( $option_name, $missing );
			$snapshot[ $option_name ] = [
				'exists' => $value !== $missing,
				'value'  => $value,
			];
		}

		return $snapshot;
	}

	/**
	 * Restore design-system options if remote-template insertion fails.
	 *
	 * @since 2.4
	 *
	 * @param array $snapshot Snapshot from snapshot_design_options().
	 * @return true|\WP_Error
	 */
	private static function restore_design_options( array $snapshot ) {
		foreach ( $snapshot as $option_name => $row ) {
			if ( empty( $row['exists'] ) ) {
				$deleted = delete_option( $option_name );

				if ( ! $deleted && get_option( $option_name, null ) !== null ) {
					return Error::internal_error( 'insert_remote_template', "Could not restore missing design option {$option_name} after insertion failed." );
				}

				continue;
			}

			$updated = update_option( $option_name, $row['value'] );

			if ( ! $updated && get_option( $option_name ) !== $row['value'] ) {
				return Error::internal_error( 'insert_remote_template', "Could not restore design option {$option_name} after insertion failed." );
			}
		}

		return true;
	}

	/**
	 * Option names modified by remote-template design asset imports.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	private static function design_option_names(): array {
		$options = [];

		foreach ( [ 'BRICKS_DB_GLOBAL_CLASSES', 'BRICKS_DB_GLOBAL_VARIABLES', 'BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES', 'BRICKS_DB_COLOR_PALETTE', 'BRICKS_DB_THEME_STYLES', 'BRICKS_DB_STYLE_MANAGER' ] as $constant ) {
			if ( defined( $constant ) ) {
				$options[] = constant( $constant );
			}
		}

		return $options;
	}

	/**
	 * Import missing design assets referenced by a remote template.
	 *
	 * Existing site assets are never overwritten. When an incoming global class
	 * matches an existing class by name, element class references are mapped to
	 * the local class ID instead of creating a duplicate.
	 *
	 * @since 2.4
	 *
	 * @param array  $template Remote template row.
	 * @param array  $data Remote source payload.
	 * @param array  $elements Source elements.
	 * @param string $mode Import mode: missing|all|none.
	 * @param bool   $apply_theme_style Whether to apply the imported theme style site-wide.
	 * @param string $style_bundle Optional bundle slug used to avoid applying unrelated theme styles.
	 * @return array
	 */
	private static function import_remote_template_design_assets( array $template, array $data, array $elements, string $mode, bool $apply_theme_style = false, string $style_bundle = '' ) {
		$imported  = [
			'globalClasses'            => 0,
			'globalVariables'          => 0,
			'globalVariableCategories' => 0,
			'themeStyles'              => 0,
			'colorPalette'             => 0,
			'styleManager'             => 0,
		];
		$skipped   = [
			'globalClasses'            => 0,
			'globalVariables'          => 0,
			'globalVariableCategories' => 0,
			'themeStyles'              => 0,
			'colorPalette'             => 0,
			'styleManager'             => 0,
		];
		$warnings  = [];
		$class_map = [];

		$global_classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$global_classes = is_array( $global_classes ) ? array_values( $global_classes ) : [];
		$class_ids      = [];
		$class_names    = [];

		foreach ( $global_classes as $index => $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			if ( ! empty( $class['id'] ) ) {
				$class_ids[ (string) $class['id'] ] = $index;
			}

			if ( ! empty( $class['name'] ) ) {
				$class_names[ (string) $class['name'] ] = $index;
			}
		}

		$template_classes = is_array( $template['global_classes'] ?? null ) ? $template['global_classes'] : [];

		foreach ( $template_classes as $class ) {
			if ( ! is_array( $class ) || empty( $class['id'] ) ) {
				$skipped['globalClasses']++;
				continue;
			}

			$remote_id = (string) $class['id'];
			$name      = isset( $class['name'] ) ? (string) $class['name'] : '';

			if ( isset( $class_ids[ $remote_id ] ) ) {
				$class_map[ $remote_id ] = $remote_id;
				if ( $mode === 'all' ) {
					unset( $class['_exists'] );
					$global_classes[ $class_ids[ $remote_id ] ] = $class;
					$imported['globalClasses']++;
				} else {
					$skipped['globalClasses']++;
				}
				continue;
			}

			if ( $name !== '' && isset( $class_names[ $name ] ) ) {
				$local_id = (string) ( $global_classes[ $class_names[ $name ] ]['id'] ?? '' );

				if ( $local_id !== '' ) {
					$class_map[ $remote_id ] = $local_id;
				}

				if ( $mode === 'all' ) {
					unset( $class['_exists'] );
					if ( $local_id !== '' ) {
						$class['id'] = $local_id;
					}
					$global_classes[ $class_names[ $name ] ] = $class;
					$imported['globalClasses']++;
				} else {
					$skipped['globalClasses']++;
				}
				continue;
			}

			if ( in_array( $mode, [ 'missing', 'all' ], true ) ) {
				unset( $class['_exists'] );
				$global_classes[]        = $class;
				$class_ids[ $remote_id ] = count( $global_classes ) - 1;
				if ( $name !== '' ) {
					$class_names[ $name ] = count( $global_classes ) - 1;
				}
				$class_map[ $remote_id ] = $remote_id;
				$imported['globalClasses']++;
			} else {
				$skipped['globalClasses']++;
			}
		}

		if ( $imported['globalClasses'] > 0 ) {
			\Bricks\Helpers::save_global_classes_in_db( $global_classes );
		}

		if ( ! empty( $class_map ) ) {
			$elements = self::remap_element_global_classes( $elements, $class_map );
		}

		if ( $mode === 'none' ) {
			return [
				'elements' => $elements,
				'imported' => $imported,
				'skipped'  => $skipped,
				'warnings' => $warnings,
			];
		}

		$used_color_ids = [];
		self::collect_color_ids( $elements, $used_color_ids );

		$template_theme_styles = [];

		if ( is_array( $template['themeStyle'] ?? null ) ) {
			$template_theme_styles[] = $template['themeStyle'];
		}

		if ( is_array( $template['themeStyles'] ?? null ) ) {
			$template_theme_styles = $template['themeStyles'];
		}

		$theme_styles           = get_option( BRICKS_DB_THEME_STYLES, [] );
		$theme_styles           = is_array( $theme_styles ) ? $theme_styles : [];
		$applied_theme_style_id = '';

		foreach ( $template_theme_styles as $style ) {
			if ( ! is_array( $style ) || empty( $style['id'] ) ) {
				$skipped['themeStyles']++;
				continue;
			}

			self::collect_color_ids( $style['settings'] ?? [], $used_color_ids );

			$style_id        = (string) $style['id'];
			$style_label     = (string) ( $style['label'] ?? $style_id );
			$can_apply_style = ! $style_bundle || stripos( $style_label, $style_bundle ) !== false;

			if ( isset( $theme_styles[ $style_id ] ) ) {
				if ( $mode === 'all' ) {
					$theme_styles[ $style_id ] = [
						'label'    => $style_label,
						'settings' => is_array( $style['settings'] ?? null ) ? $style['settings'] : [],
					];
					if ( $can_apply_style ) {
						$applied_theme_style_id = $style_id;
					}
					$imported['themeStyles']++;
				} else {
					$skipped['themeStyles']++;
				}
				continue;
			}

			$theme_styles[ $style_id ] = [
				'label'    => $style_label,
				'settings' => is_array( $style['settings'] ?? null ) ? $style['settings'] : [],
			];
			if ( $can_apply_style ) {
				$applied_theme_style_id = $style_id;
			}
			$imported['themeStyles']++;
		}

		if ( $applied_theme_style_id && $apply_theme_style ) {
			$theme_styles[ $applied_theme_style_id ]['settings']['conditions']['conditions'] = [
				[
					'main' => 'any',
				],
			];
		}

		if ( $imported['themeStyles'] > 0 ) {
			update_option( BRICKS_DB_THEME_STYLES, $theme_styles );
		}

		$remote_palettes   = is_array( $data['colorPalette'] ?? null ) ? $data['colorPalette'] : [];
		$template_palettes = [];

		foreach ( $remote_palettes as $palette ) {
			if ( ! is_array( $palette ) || ! is_array( $palette['colors'] ?? null ) ) {
				continue;
			}

			foreach ( $palette['colors'] as $color ) {
				if ( is_array( $color ) && ! empty( $color['id'] ) && isset( $used_color_ids[ (string) $color['id'] ] ) ) {
					$template_palettes[] = $palette;
					break;
				}
			}
		}

		$used_variable_names        = self::collect_global_variable_names_from_data(
			[
				'elements'      => $elements,
				'globalClasses' => $template_classes,
				'themeStyles'   => $template_theme_styles,
				'colorPalette'  => $template_palettes,
			]
		);
		$remote_variables           = self::filter_remote_global_variables_by_names(
			is_array( $data['globalVariables'] ?? null ) ? $data['globalVariables'] : [],
			$used_variable_names
		);
		$remote_variable_categories = self::filter_remote_global_variable_categories_for_variables(
			$remote_variables,
			is_array( $data['globalVariablesCategories'] ?? null ) ? $data['globalVariablesCategories'] : []
		);
		$local_variable_categories  = get_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, [] );
		$local_variable_categories  = is_array( $local_variable_categories ) ? array_values( $local_variable_categories ) : [];
		$category_ids               = [];
		$category_names             = [];

		foreach ( $local_variable_categories as $index => $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			if ( ! empty( $category['id'] ) ) {
				$category_ids[ (string) $category['id'] ] = $index;
			}

			if ( ! empty( $category['name'] ) ) {
				$category_names[ (string) $category['name'] ] = $index;
			}
		}

		foreach ( $remote_variable_categories as $category ) {
			if ( ! is_array( $category ) || empty( $category['id'] ) ) {
				$skipped['globalVariableCategories']++;
				continue;
			}

			$category_id   = (string) $category['id'];
			$category_name = isset( $category['name'] ) ? (string) $category['name'] : '';

			if ( isset( $category_ids[ $category_id ] ) ) {
				if ( $mode === 'all' ) {
					$local_variable_categories[ $category_ids[ $category_id ] ] = $category;
					$imported['globalVariableCategories']++;
				} else {
					$skipped['globalVariableCategories']++;
				}
				continue;
			}

			if ( $category_name !== '' && isset( $category_names[ $category_name ] ) ) {
				if ( $mode === 'all' ) {
					$category['id'] = (string) ( $local_variable_categories[ $category_names[ $category_name ] ]['id'] ?? $category_id );
					$local_variable_categories[ $category_names[ $category_name ] ] = $category;
					$imported['globalVariableCategories']++;
				} else {
					$skipped['globalVariableCategories']++;
				}
				continue;
			}

			$local_variable_categories[]  = $category;
			$category_ids[ $category_id ] = count( $local_variable_categories ) - 1;
			if ( $category_name !== '' ) {
				$category_names[ $category_name ] = count( $local_variable_categories ) - 1;
			}
			$imported['globalVariableCategories']++;
		}

		if ( $imported['globalVariableCategories'] > 0 ) {
			update_option( BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES, array_values( $local_variable_categories ), false );
		}

		$local_variables = get_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$local_variables = is_array( $local_variables ) ? array_values( $local_variables ) : [];
		$variable_names  = [];
		$variable_ids    = [];

		foreach ( $local_variables as $idx => $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}

			if ( ! empty( $variable['name'] ) ) {
				$variable_names[ (string) $variable['name'] ] = $idx;
			}

			if ( ! empty( $variable['id'] ) ) {
				$variable_ids[ (string) $variable['id'] ] = $idx;
			}
		}

		foreach ( $remote_variables as $variable ) {
			if ( ! is_array( $variable ) || empty( $variable['name'] ) ) {
				$skipped['globalVariables']++;
				continue;
			}

			$name = (string) $variable['name'];
			$id   = isset( $variable['id'] ) ? (string) $variable['id'] : '';

			$existing_index = $variable_names[ $name ] ?? ( $id !== '' && isset( $variable_ids[ $id ] ) ? $variable_ids[ $id ] : null );

			if ( $existing_index !== null ) {
				if ( $mode === 'all' ) {
					$local_variables[ $existing_index ] = $variable;
					$imported['globalVariables']++;
				} else {
					$skipped['globalVariables']++;
				}
				continue;
			}

			$local_variables[]       = $variable;
			$variable_names[ $name ] = count( $local_variables ) - 1;
			if ( $id !== '' ) {
				$variable_ids[ $id ] = count( $local_variables ) - 1;
			}
			$imported['globalVariables']++;
		}

		if ( $imported['globalVariables'] > 0 ) {
			\Bricks\Helpers::save_global_variables_in_db( $local_variables );
		}

		$local_palettes = get_option( BRICKS_DB_COLOR_PALETTE, [] );
		$local_palettes = is_array( $local_palettes ) ? array_values( $local_palettes ) : [];
		$palette_ids    = [];
		$palette_names  = [];

		foreach ( $local_palettes as $palette ) {
			if ( ! is_array( $palette ) ) {
				continue;
			}

			if ( ! empty( $palette['id'] ) ) {
				$palette_ids[ (string) $palette['id'] ] = true;
			}

			if ( ! empty( $palette['name'] ) ) {
				$palette_names[] = (string) $palette['name'];
			}
		}

		foreach ( $remote_palettes as $palette ) {
			if ( ! is_array( $palette ) || empty( $palette['id'] ) || ! is_array( $palette['colors'] ?? null ) ) {
				$skipped['colorPalette']++;
				continue;
			}

			$palette_id = (string) $palette['id'];

			if ( isset( $palette_ids[ $palette_id ] ) ) {
				if ( $mode === 'all' ) {
					foreach ( $local_palettes as $index => $local_palette ) {
						if ( (string) ( $local_palette['id'] ?? '' ) === $palette_id ) {
							$local_palettes[ $index ] = $palette;
							$imported['colorPalette']++;
							continue 2;
						}
					}
				}

				$skipped['colorPalette']++;
				continue;
			}

			$has_used_color = false;

			foreach ( $palette['colors'] as $color ) {
				if ( is_array( $color ) && ! empty( $color['id'] ) && isset( $used_color_ids[ (string) $color['id'] ] ) ) {
					$has_used_color = true;
					break;
				}
			}

			if ( ! $has_used_color ) {
				$skipped['colorPalette']++;
				continue;
			}

			if ( ! empty( $palette['name'] ) && in_array( (string) $palette['name'], $palette_names, true ) ) {
				$palette['name'] = self::unique_palette_name( (string) $palette['name'], $palette_names );
			}

			$local_palettes[]           = $palette;
			$palette_ids[ $palette_id ] = true;
			if ( ! empty( $palette['name'] ) ) {
				$palette_names[] = (string) $palette['name'];
			}
			$imported['colorPalette']++;
		}

		if ( $imported['colorPalette'] > 0 ) {
			update_option( BRICKS_DB_COLOR_PALETTE, $local_palettes );
		}

		if ( is_array( $data['styleManager'] ?? null ) && ! empty( $data['styleManager'] ) ) {
			$local_style_manager = get_option( BRICKS_DB_STYLE_MANAGER, [] );
			$local_style_manager = is_array( $local_style_manager ) ? $local_style_manager : [];

			if ( $mode === 'all' || empty( $local_style_manager ) ) {
				update_option( BRICKS_DB_STYLE_MANAGER, array_merge( $local_style_manager, $data['styleManager'] ) );
				$imported['styleManager']++;
			} else {
				$skipped['styleManager']++;
			}
		}

		return [
			'elements' => $elements,
			'imported' => $imported,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		];
	}

	/**
	 * Collect global CSS variable names referenced by remote template data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $data Data to inspect.
	 * @return array
	 */
	private static function collect_global_variable_names_from_data( $data ): array {
		$names = [];

		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				$names = array_merge( $names, self::collect_global_variable_names_from_data( $item ) );
			}

			return array_values( array_unique( array_filter( $names ) ) );
		}

		if ( ! is_string( $data ) ) {
			return [];
		}

		if ( ! preg_match_all( '/var\(\s*--([A-Za-z0-9_-]+)\s*\)/', $data, $matches ) ) {
			return [];
		}

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Filter remote variables to the names referenced by the selected template.
	 *
	 * @since 2.4
	 *
	 * @param array $variables Remote variables.
	 * @param array $variable_names Referenced variable names.
	 * @return array
	 */
	private static function filter_remote_global_variables_by_names( array $variables, array $variable_names ): array {
		$variable_names = array_values( array_unique( array_filter( array_map( 'strval', $variable_names ) ) ) );

		if ( empty( $variables ) || empty( $variable_names ) ) {
			return [];
		}

		$variables_by_name = [];

		foreach ( $variables as $variable ) {
			if ( is_array( $variable ) && ! empty( $variable['name'] ) ) {
				$variables_by_name[ (string) $variable['name'] ] = $variable;
			}
		}

		$selected_names = [];
		$pending_names  = $variable_names;

		while ( ! empty( $pending_names ) ) {
			$variable_name = array_shift( $pending_names );

			if ( isset( $selected_names[ $variable_name ] ) || empty( $variables_by_name[ $variable_name ] ) ) {
				continue;
			}

			$selected_names[ $variable_name ] = true;

			foreach ( self::collect_global_variable_names_from_data( $variables_by_name[ $variable_name ] ) as $nested_name ) {
				if ( ! isset( $selected_names[ $nested_name ] ) ) {
					$pending_names[] = $nested_name;
				}
			}
		}

		return array_values(
			array_filter(
				$variables,
				function( $variable ) use ( $selected_names ) {
					return is_array( $variable ) && isset( $selected_names[ (string) ( $variable['name'] ?? '' ) ] );
				}
			)
		);
	}

	/**
	 * Keep only categories used by the filtered remote variables.
	 *
	 * @since 2.4
	 *
	 * @param array $variables Remote variables selected for import.
	 * @param array $categories Remote variable categories.
	 * @return array
	 */
	private static function filter_remote_global_variable_categories_for_variables( array $variables, array $categories ): array {
		$category_ids = [];

		foreach ( $variables as $variable ) {
			if ( is_array( $variable ) && ! empty( $variable['category'] ) ) {
				$category_ids[ (string) $variable['category'] ] = true;
			}
		}

		if ( empty( $category_ids ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$categories,
				function( $category ) use ( $category_ids ) {
					return is_array( $category ) && isset( $category_ids[ (string) ( $category['id'] ?? '' ) ] );
				}
			)
		);
	}

	/**
	 * Recursively collect Bricks color IDs from a settings payload.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Settings value.
	 * @param array $ids Collected IDs.
	 * @return void
	 */
	private static function collect_color_ids( $value, array &$ids ): void {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		if ( ! empty( $value['id'] ) && ( isset( $value['hex'] ) || isset( $value['rgb'] ) || isset( $value['raw'] ) ) ) {
			$ids[ (string) $value['id'] ] = true;
			return;
		}

		foreach ( $value as $child ) {
			self::collect_color_ids( $child, $ids );
		}
	}

	/**
	 * Remap remote global class IDs on inserted elements to local IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element rows.
	 * @param array $class_map Remote ID to local ID map.
	 * @return array
	 */
	private static function remap_element_global_classes( array $elements, array $class_map ): array {
		foreach ( $elements as &$element ) {
			if ( empty( $element['settings']['_cssGlobalClasses'] ) || ! is_array( $element['settings']['_cssGlobalClasses'] ) ) {
				continue;
			}

			$mapped = [];

			foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
				$class_id = (string) $class_id;
				$mapped[] = $class_map[ $class_id ] ?? $class_id;
			}

			$element['settings']['_cssGlobalClasses'] = array_values( array_unique( $mapped ) );
		}

		unset( $element );

		return $elements;
	}

	/**
	 * Generate a unique palette label.
	 *
	 * @since 2.4
	 *
	 * @param string $name Existing name.
	 * @param array  $existing Existing names.
	 * @return string
	 */
	private static function unique_palette_name( string $name, array $existing ): string {
		$base  = $name;
		$index = 2;

		while ( in_array( $name, $existing, true ) ) {
			$name = "{$base} #{$index}";
			$index++;
		}

		return $name;
	}

	// ==================================================================
	// bricks/insert-template
	// ==================================================================

	/**
	 * Input schema for insert-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function insert_template_schema() {
		return [
			'type'       => 'object',
			'properties' => self::insert_template_placement_schema_properties(),
			'required'   => [ 'targetPostId' ],
		];
	}

	/**
	 * Shared placement schema properties for template insertion abilities.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function insert_template_placement_schema_properties() {
		return [
			'targetPostId'     => [
				'type'        => 'integer',
				'description' => __( 'Destination post/page/template ID.', 'bricks' ),
				'required'    => true,
			],
			'sourceTemplateId' => [
				'type'        => 'integer',
				'description' => __( 'Source Bricks template post ID.', 'bricks' ),
				'required'    => true,
			],
			'templateId'       => [
				'type'        => 'integer',
				'description' => __( 'Alias for `sourceTemplateId`.', 'bricks' ),
			],
			'position'         => [
				'type'        => 'string',
				'description' => __( 'Where to place the template\'s elements in the target tree. `replace` overwrites the entire tree. `start` / `end` insert at the top/bottom of the root level. `before` / `after` insert as siblings of `anchorElementId`. `append` / `prepend` insert as children of `anchorElementId`. Defaults to `end`.', 'bricks' ),
				'enum'        => [ 'replace', 'start', 'end', 'before', 'after', 'append', 'prepend' ],
			],
			'anchorElementId'  => [
				'type'        => 'string',
				'description' => __( 'Existing element id in the target post. Required for position = before | after | append | prepend. The template\'s root element(s) are slotted relative to this anchor, with their parent and ordering set accordingly.', 'bricks' ),
			],
			'areaKey'          => [
				'type'        => 'string',
				'description' => __( 'Target area: content (default), header, footer.', 'bricks' ),
				'enum'        => [ 'content', 'header', 'footer' ],
			],
		];
	}

	/**
	 * Output schema for insert-template
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function insert_template_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'targetPostId'     => [ 'type' => 'integer' ],
				'sourceTemplateId' => [ 'type' => 'integer' ],
				'position'         => [ 'type' => 'string' ],
				'anchorElementId'  => [ 'type' => [ 'string', 'null' ] ],
				'areaKey'          => [ 'type' => 'string' ],
				'insertedRootIds'  => [
					'type'        => 'array',
					'description' => __( 'IDs of the template\'s root elements after re-keying. Useful for follow-up reads/updates.', 'bricks' ),
				],
				'elementCount'     => [
					'type'        => 'integer',
					'description' => __( 'Total number of elements in the saved target tree (existing + inserted).', 'bricks' ),
				],
				'revisionId'       => [ 'type' => [ 'integer', 'null' ] ],
			],
		];
	}

	/**
	 * Callback: insert-template
	 *
	 * Local templates only. For remote library templates use insert-remote-template.
	 * All template element IDs are regenerated before the merge so they
	 * never collide with existing target-post ids. Revision is snapshotted
	 * pre-write - use restore-revision to undo.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function insert_template( $input ) {
		$target_id = (int) ( $input['targetPostId'] ?? 0 );
		$source_id = (int) ( $input['sourceTemplateId'] ?? ( $input['templateId'] ?? 0 ) );
		$position  = (string) ( $input['position'] ?? 'end' );
		$anchor_id = isset( $input['anchorElementId'] ) ? (string) $input['anchorElementId'] : '';
		$area_key  = (string) ( $input['areaKey'] ?? 'content' );

		$target_post = get_post( $target_id );

		if ( ! $target_post ) {
			return Error::not_found( 'post', $target_id );
		}

		$source_post = get_post( $source_id );

		if ( ! $source_post ) {
			return Error::not_found( 'template', $source_id );
		}

		if ( $source_post->post_type !== BRICKS_DB_TEMPLATE_SLUG ) {
			return Error::invalid_param( 'sourceTemplateId', 'a Bricks template post ID', $source_id );
		}

		$source_type     = \Bricks\Templates::get_template_type( $source_id );
		$source_elements = \Bricks\Database::get_data( $source_id, $source_type );

		if ( ! is_array( $source_elements ) ) {
			$source_elements = [];
		}

		if ( empty( $source_elements ) ) {
			return Error::not_found( 'template_elements', $source_id );
		}

		$result = self::insert_elements_into_target(
			$target_id,
			$source_elements,
			$position,
			$anchor_id,
			$area_key
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge(
			$result,
			[
				'targetPostId'     => $target_id,
				'sourceTemplateId' => $source_id,
			]
		);
	}

	/**
	 * Insert an element tree into a target post using the shared save path.
	 *
	 * @since 2.4
	 *
	 * @param int    $target_id       Destination post ID.
	 * @param array  $source_elements Source element tree.
	 * @param string $position        Placement mode.
	 * @param string $anchor_id       Optional anchor element ID.
	 * @param string $area_key        Target area.
	 * @return array|\WP_Error
	 */
	private static function insert_elements_into_target( int $target_id, array $source_elements, string $position, string $anchor_id, string $area_key ) {
		$target_validation = self::validate_insert_target( $target_id, $position, $anchor_id, $area_key );

		if ( is_wp_error( $target_validation ) ) {
			return $target_validation;
		}

		$anchor_required = $target_validation['anchorRequired'];

		// Regenerate every id in the source tree so merge can't collide.
		$rekeyed = self::rekey_elements( $source_elements );
		$source  = self::order_template_elements( $rekeyed['elements'] );

		// Source roots (parent === 0 or "0") after rekeying.
		$source_roots = [];

		foreach ( $source as $el ) {
			$parent = $el['parent'] ?? 0;

			if ( $parent === 0 || $parent === '0' ) {
				$source_roots[] = $el['id'];
			}
		}

		if ( $position === 'replace' ) {
			$merged = $source;
		} else {
			$target_elements = self::order_template_elements( $target_validation['targetElements'] );

			$merged = self::merge_template_into_target(
				$target_elements,
				$source,
				$source_roots,
				$position,
				$anchor_id
			);

			if ( is_wp_error( $merged ) ) {
				return $merged;
			}
		}

		$save_result = Save_Pipeline::execute( $target_id, $merged, $area_key );

		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		return [
			'targetPostId'    => $target_id,
			'position'        => $position,
			'anchorElementId' => $anchor_required ? $anchor_id : null,
			'areaKey'         => $area_key,
			'insertedRootIds' => $source_roots,
			'elementCount'    => is_array( $save_result['elements'] ?? null ) ? count( $save_result['elements'] ) : count( $merged ),
			'revisionId'      => $save_result['revisionId'] ?? null,
		];
	}

	/**
	 * Validate target post and anchor placement before any global design import.
	 *
	 * @since 2.4
	 *
	 * @param int    $target_id Destination post ID.
	 * @param string $position  Placement mode.
	 * @param string $anchor_id Optional anchor element ID.
	 * @param string $area_key  Target area.
	 * @return array|\WP_Error
	 */
	private static function validate_insert_target( int $target_id, string $position, string $anchor_id, string $area_key ) {
		$target_post = get_post( $target_id );

		if ( ! $target_post ) {
			return Error::not_found( 'post', $target_id );
		}

		$anchor_required = in_array( $position, [ 'before', 'after', 'append', 'prepend' ], true );

		if ( $anchor_required && $anchor_id === '' ) {
			return Error::invalid_param( 'anchorElementId', 'a non-empty element id when position is before|after|append|prepend', $anchor_id );
		}

		$target_elements = [];

		if ( $position !== 'replace' ) {
			$target_elements = \Bricks\Database::get_data( $target_id, $area_key );
			$target_elements = is_array( $target_elements ) ? $target_elements : [];
		}

		if ( $anchor_required ) {
			$anchor_exists = false;

			foreach ( $target_elements as $element ) {
				if ( ( $element['id'] ?? '' ) === $anchor_id ) {
					$anchor_exists = true;
					break;
				}
			}

			if ( ! $anchor_exists ) {
				return Error::not_found( "anchor_element[{$anchor_id}]", $target_id );
			}
		}

		return [
			'anchorRequired' => $anchor_required,
			'targetElements' => $target_elements,
		];
	}

	/**
	 * Order a stored template tree before structural placement rebuilds children.
	 *
	 * Parent references determine membership; saved children determine sibling
	 * order. Missing children retain their flat order. Keep unreachable elements
	 * for the save validator rather than silently dropping malformed data.
	 *
	 * @since 2.4.3
	 *
	 * @param array $elements Stored flat elements.
	 * @return array
	 */
	private static function order_template_elements( array $elements ): array {
		$by_id    = [];
		$children = [];

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( ! is_string( $id ) || $id === '' || isset( $by_id[ $id ] ) ) {
				return $elements;
			}

			$by_id[ $id ] = $element;
			$children[ (string) ( $element['parent'] ?? 0 ) ][ $id ] = $id;
		}

		foreach ( $by_id as $id => $element ) {
			$remaining = $children[ $id ] ?? [];
			$ordered   = [];

			foreach ( (array) ( $element['children'] ?? [] ) as $child_id ) {
				if ( is_string( $child_id ) && isset( $remaining[ $child_id ] ) ) {
					$ordered[] = $child_id;
					unset( $remaining[ $child_id ] );
				}
			}

			$children[ $id ] = array_merge( $ordered, array_values( $remaining ) );
		}

		$ordered = [];
		$seen    = [];
		$stack   = array_reverse( array_values( $children[0] ?? [] ) );

		while ( $stack ) {
			$id = array_pop( $stack );

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$ordered[]   = $by_id[ $id ];

			foreach ( array_reverse( $children[ $id ] ?? [] ) as $child_id ) {
				$stack[] = $child_id;
			}
		}

		foreach ( $elements as $element ) {
			if ( ! isset( $seen[ $element['id'] ] ) ) {
				$ordered[] = $element;
			}
		}

		return $ordered;
	}

	/**
	 * Regenerate every id in an element tree while preserving
	 * parent/child relationships. Returns the remapped elements plus the
	 * old to new id map.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Input elements.
	 * @return array { elements, idMap }
	 */
	private static function rekey_elements( $elements ) {
		$id_map = [];

		foreach ( $elements as $el ) {
			$old = $el['id'] ?? '';

			if ( $old !== '' ) {
				$id_map[ $old ] = \Bricks\Helpers::generate_random_id( false );
			}
		}

		$rekeyed = [];

		foreach ( $elements as $el ) {
			$old    = $el['id'] ?? '';
			$parent = $el['parent'] ?? 0;

			$el['id']     = $id_map[ $old ] ?? \Bricks\Helpers::generate_random_id( false );
			$el['parent'] = ( $parent === 0 || $parent === '0' ) ? 0 : ( $id_map[ $parent ] ?? $parent );

			if ( isset( $el['children'] ) && is_array( $el['children'] ) ) {
				$el['children'] = array_values(
					array_map(
						static function ( $child_id ) use ( $id_map ) {
							return $id_map[ $child_id ] ?? $child_id;
						},
						$el['children']
					)
				);
			}

			$rekeyed[] = $el;
		}

		$rekeyed = self::replace_exact_id_references( $rekeyed, $id_map );

		return [
			'elements' => $rekeyed,
			'idMap'    => $id_map,
		];
	}

	/**
	 * Replace old element IDs only when a scalar value exactly matches.
	 *
	 * This updates stored interaction/condition references without rewriting
	 * unrelated text, CSS, or content strings that merely contain the old id.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value  Value to walk.
	 * @param array $id_map Old ID to new ID map.
	 * @return mixed
	 */
	private static function replace_exact_id_references( $value, array $id_map ) {
		if ( is_string( $value ) && isset( $id_map[ $value ] ) ) {
			return $id_map[ $value ];
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::replace_exact_id_references( $child, $id_map );
		}

		return $value;
	}

	/**
	 * Merge source elements into target elements at the given position.
	 *
	 * Bricks elements are a flat array. Parent/child relationships are
	 * stored on each element via the `parent` field. Sibling order is
	 * determined by position in the array. So "insert before anchor"
	 * means: (a) set each source root's parent to anchor.parent, then
	 * (b) splice the source array into the target array at anchor's
	 * index. `children` arrays are rebuilt by Save_Pipeline::rebuild_children.
	 *
	 * @since 2.4
	 *
	 * @param array  $target       Existing target elements.
	 * @param array  $source       Re-keyed source elements.
	 * @param array  $source_roots Root ids within source (parent === 0).
	 * @param string $position     start|end|before|after|append|prepend.
	 * @param string $anchor_id    Target element id (for before|after|append|prepend).
	 * @return array|\WP_Error
	 */
	private static function merge_template_into_target( $target, $source, $source_roots, $position, $anchor_id ) {
		$anchor       = null;
		$anchor_index = -1;

		if ( $anchor_id !== '' ) {
			foreach ( $target as $idx => $el ) {
				if ( ( $el['id'] ?? '' ) === $anchor_id ) {
					$anchor       = $el;
					$anchor_index = $idx;
					break;
				}
			}
		}

		// Remap source root parents according to the chosen position.
		if ( $position === 'before' || $position === 'after' ) {
			$new_parent = $anchor['parent'] ?? 0;

			foreach ( $source as &$el ) {
				if ( in_array( $el['id'], $source_roots, true ) ) {
					$el['parent'] = $new_parent;
				}
			}
			unset( $el );
		} elseif ( $position === 'append' || $position === 'prepend' ) {
			foreach ( $source as &$el ) {
				if ( in_array( $el['id'], $source_roots, true ) ) {
					$el['parent'] = $anchor_id;
				}
			}
			unset( $el );
		} elseif ( $position === 'start' || $position === 'end' ) {
			foreach ( $source as &$el ) {
				if ( in_array( $el['id'], $source_roots, true ) ) {
					$el['parent'] = 0;
				}
			}
			unset( $el );
		}

		// Splice based on position.
		if ( $position === 'start' ) {
			return array_merge( $source, $target );
		}

		if ( $position === 'end' ) {
			return array_merge( $target, $source );
		}

		if ( $position === 'before' ) {
			return array_merge(
				array_slice( $target, 0, $anchor_index ),
				$source,
				array_slice( $target, $anchor_index )
			);
		}

		if ( $position === 'after' ) {
			$insert_at = self::last_descendant_index( $target, $anchor_id ) + 1;

			return array_merge(
				array_slice( $target, 0, $insert_at ),
				$source,
				array_slice( $target, $insert_at )
			);
		}

		if ( $position === 'prepend' ) {
			// Insert right after the anchor so source roots become anchor's first children.
			$insert_at = $anchor_index + 1;

			return array_merge(
				array_slice( $target, 0, $insert_at ),
				$source,
				array_slice( $target, $insert_at )
			);
		}

		if ( $position === 'append' ) {
			// Insert after the last descendant of the anchor so source roots become last children.
			$insert_at = self::last_descendant_index( $target, $anchor_id ) + 1;

			return array_merge(
				array_slice( $target, 0, $insert_at ),
				$source,
				array_slice( $target, $insert_at )
			);
		}

		return Error::invalid_param( 'position', 'one of: replace, start, end, before, after, append, prepend', $position );
	}

	/**
	 * Find the array index of the last descendant of the given anchor.
	 * Descendants are found by walking the flat list and checking parent
	 * chains. Returns the anchor's own index when it has no descendants.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements  Flat elements array.
	 * @param string $anchor_id Anchor id.
	 * @return int
	 */
	private static function last_descendant_index( $elements, $anchor_id ) {
		$descendant_ids = [ $anchor_id ];
		$anchor_index   = -1;

		foreach ( $elements as $idx => $el ) {
			if ( ( $el['id'] ?? '' ) === $anchor_id ) {
				$anchor_index = $idx;
				break;
			}
		}

		// Walk repeatedly - cheaper than building an adjacency map for small element counts.
		$changed = true;

		while ( $changed ) {
			$changed = false;

			foreach ( $elements as $el ) {
				$parent = $el['parent'] ?? 0;
				$id     = $el['id'] ?? '';

				if ( $id === '' || in_array( $id, $descendant_ids, true ) ) {
					continue;
				}

				if ( in_array( (string) $parent, $descendant_ids, true ) ) {
					$descendant_ids[] = $id;
					$changed          = true;
				}
			}
		}

		$last_index = $anchor_index;

		foreach ( $elements as $idx => $el ) {
			if ( in_array( $el['id'] ?? '', $descendant_ids, true ) ) {
				$last_index = max( $last_index, $idx );
			}
		}

		return $last_index;
	}
}

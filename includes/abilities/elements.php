<?php
/**
 * Element abilities
 *
 * Read, create, update, and remove page/template elements and page settings.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/code-authoring.php';

class Elements {
	private const COMPONENT_CODE_KEYS = [ 'executeCode', 'code', 'cssCode', 'javascriptCode', 'useDynamicData', 'parseDynamicData', 'supressPhpErrors', 'noRoot', 'noRootForce', 'queryEditor', 'useQueryEditor', 'signature' ];

	// ------------------------------------------------------------------
	// Shared schemas
	// ------------------------------------------------------------------

	/**
	 * Shared schema: multi-identifier post reference
	 *
	 * Accepts any of postId, slug, path, or title. At least one must be
	 * provided. Resolution order: postId > slug > path > title.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function post_id_schema() {
		return [
			'type'       => 'object',
			'properties' => self::post_identifier_properties(),
		];
	}

	/**
	 * Shared property bag for the multi-identifier schemas.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function post_identifier_properties() {
		return [
			'postId' => [
				'type'        => 'integer',
				'description' => __( 'Post, page, or template ID. The primary identifier; prefer this whenever it is known.', 'bricks' ),
			],
			'slug'   => [
				'type'        => 'string',
				'description' => __( 'Post slug. Alternative to postId.', 'bricks' ),
			],
			'path'   => [
				'type'        => 'string',
				'description' => __( 'Hierarchical path like "parent/child". Alternative to postId for nested pages.', 'bricks' ),
			],
			'title'  => [
				'type'        => 'string',
				'description' => __( 'Exact post title. Alternative to `postId`; ambiguous when multiple posts share the same title.', 'bricks' ),
			],
		];
	}

	/**
	 * Shared schema additions for scoped element reads.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function scoped_read_properties() {
		return [
			'elementId' => [
				'type'        => 'string',
				'description' => __( 'Optional: return only this element and its children.', 'bricks' ),
			],
			'maxDepth'  => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Optional descendant depth to include. 0 returns only the root element(s), 1 includes direct children, and so on.', 'bricks' ),
			],
		];
	}

	/**
	 * Allowed element fields for compact read/write responses.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	private static function element_return_fields() {
		return [
			'id',
			'name',
			'parent',
			'label',
			'settings',
			'children',
			'cid',
			'properties',
			'variant',
			'slotChildren',
			'selectors',
		];
	}

	// ------------------------------------------------------------------
	// Shared permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Derive the Bricks save area for a post.
	 *
	 * Maps a post to one of `header` / `footer` / `content` based on its template
	 * type, so element-write call sites land data on the meta key the renderer
	 * actually reads from. Header/footer templates that took the default `content`
	 * area silently disappeared from the frontend - this is the fix.
	 *
	 * Non-template posts and content-area templates resolve to `content`. Other
	 * template types (archive, search, error, popup, single, page) also use the
	 * `content` area in Bricks' storage model.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Resolved post ID.
	 * @return string Save area: `header`, `footer`, or `content`.
	 */
	public static function get_save_area_for_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || get_post_type( $post_id ) !== BRICKS_DB_TEMPLATE_SLUG ) {
			return 'content';
		}

		$template_type = \Bricks\Templates::get_template_type( $post_id );

		if ( $template_type === 'header' || $template_type === 'footer' ) {
			return $template_type;
		}

		return 'content';
	}

	/**
	 * Shared resolve + authorize helper.
	 *
	 * 1. Resolves any supported identifier (postId/slug/path/title) to an int.
	 * 2. Confirms `edit_post`, Bricks builder access, and (for writes) post lock.
	 * 3. Mutates `$input['postId']` in place so execute callbacks can stay
	 *    simple and skip redundant resolution.
	 *
	 * @since 2.4
	 *
	 * @param array $input  Ability input (passed by reference).
	 * @param bool  $is_write Whether this is a write path - if so, acquire the post lock.
	 * @return true|\WP_Error
	 */
	private static function resolve_and_authorize( array &$input, bool $is_write = false ) {
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

		if ( $is_write ) {
			$lock_check = self::assert_post_unlocked( $post_id );

			if ( is_wp_error( $lock_check ) ) {
				return $lock_check;
			}
		}

		$input['postId'] = $post_id;

		return true;
	}

	/**
	 * Confirm the target post is not locked by someone else.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	private static function assert_post_unlocked( int $post_id ) {
		Manager::ensure_post_admin_loaded();
		$locked_by = wp_check_post_lock( $post_id );

		if ( $locked_by && (int) $locked_by !== get_current_user_id() ) {
			return Error::locked_by_other_user( $post_id, (int) $locked_by );
		}

		return true;
	}

	/**
	 * Acquire the edit lock after action-specific permissions have passed.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Post ID.
	 * @return true|\WP_Error
	 */
	public static function acquire_post_lock( int $post_id ) {
		$lock_check = self::assert_post_unlocked( $post_id );

		if ( is_wp_error( $lock_check ) ) {
			return $lock_check;
		}

		wp_set_post_lock( $post_id );

		return true;
	}

	/**
	 * Permission: read access to post elements
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_post_permission( $input ) {
		return self::resolve_and_authorize( $input, false );
	}

	/**
	 * Permission: write access to elements (set-page-elements)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function write_elements_permission( $input ) {
		return self::resolve_and_authorize( $input, false );
	}

	/**
	 * Permission: add element
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function add_element_permission( $input ) {
		return self::resolve_and_authorize( $input, false );
	}

	/**
	 * Permission: edit element
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function edit_element_permission( $input ) {
		return self::resolve_and_authorize( $input, false );
	}

	/**
	 * Permission: batch edit elements
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function batch_update_elements_permission( $input ) {
		return self::resolve_and_authorize( $input, false );
	}

	/**
	 * Permission: delete element
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function delete_element_permission( $input ) {
		$check = self::resolve_and_authorize( $input, false );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'delete_elements' ) ) {
			return Error::forbidden_builder_permission( 'delete_elements' );
		}

		return true;
	}

	/**
	 * Resolve the post ID from input for execute callbacks.
	 *
	 * Permission callbacks already ran the resolver and auth'd, so when
	 * this is called from an execute callback it's guaranteed to succeed.
	 * We still call it (rather than trusting the input) because the
	 * mutations from the permission callback don't propagate back to the
	 * caller's copy of `$args`.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|\WP_Error
	 */
	public static function get_resolved_post_id( array $input ) {
		return Input_Resolver::resolve_post_id( $input );
	}

	/**
	 * Permission: page settings read
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function page_settings_read_permission( $input ) {
		$check = self::resolve_and_authorize( $input, false );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_page_settings' ) ) {
			return Error::forbidden_builder_permission( 'access_page_settings' );
		}

		return true;
	}

	/**
	 * Permission: page settings write.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function page_settings_write_permission( $input ) {
		$check = self::resolve_and_authorize( $input, false );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'access_page_settings' ) ) {
			return Error::forbidden_builder_permission( 'access_page_settings' );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// bricks/get-page-structure
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-page-structure
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_page_structure_schema() {
		$properties = array_merge(
			self::post_identifier_properties(),
			self::scoped_read_properties()
		);

		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * Output schema for get-page-structure
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_page_structure_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'tree'   => [ 'type' => 'array' ],
				'postId' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: get page structure as nested tree
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_page_structure( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$area     = self::get_save_area_for_post( $post_id );
		$elements = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $elements ) ) {
			$elements = [];
		}

		$max_depth = self::optional_max_depth( $input );
		if ( is_wp_error( $max_depth ) ) {
			return $max_depth;
		}

		if ( isset( $input['elementId'] ) ) {
			$element_id = $input['elementId'];
			$elements   = self::filter_element_tree( $elements, $element_id, $max_depth );

			if ( empty( $elements ) ) {
				return Error::not_found( 'element', $element_id );
			}
		} elseif ( $max_depth !== null ) {
			$elements = self::filter_root_elements_by_depth( $elements, $max_depth );
		}

		$tree = self::build_tree( $elements );

		return [
			'tree'   => $tree,
			'postId' => $post_id,
		];
	}

	/**
	 * Build nested tree from flat elements array
	 *
	 * @since 2.4
	 *
	 * @param array $elements Flat elements array.
	 * @return array Nested tree.
	 */
	private static function build_tree( $elements ) {
		$map               = [];
		$tree              = [];
		$flat_children_map = [];
		$declared_children = [];
		$parent_ids        = [];
		$root_ids          = [];

		// Index elements by ID
		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( ! $id ) {
				continue;
			}

			$map[ $id ] = [
				'id'       => $id,
				'name'     => $element['name'] ?? '',
				'label'    => $element['label'] ?? '',
				'parent'   => $element['parent'] ?? 0,
				'children' => [],
			];

			// Include component ID if present
			if ( ! empty( $element['cid'] ) ) {
				$map[ $id ]['cid'] = $element['cid'];
			}

			$parent            = $element['parent'] ?? 0;
			$parent_ids[ $id ] = $parent;
			if ( $parent ) {
				$parent_key                         = (string) $parent;
				$flat_children_map[ $parent_key ]   = $flat_children_map[ $parent_key ] ?? [];
				$flat_children_map[ $parent_key ][] = $id;
			}

			$declared_children[ $id ] = isset( $element['children'] ) && is_array( $element['children'] )
				? $element['children']
				: [];
		}

		foreach ( $map as $id => $node ) {
			$parent = $parent_ids[ $id ] ?? 0;
			if ( ! $parent || ! isset( $map[ $parent ] ) ) {
				$root_ids[] = $id;
			}
		}

		// Attach references in persisted children order. Parent references remain
		// the membership authority; flat order is only a safe fallback for a valid
		// child omitted from a stale children array.
		foreach ( $map as $parent_id => &$parent_node ) {
			$valid_children = $flat_children_map[ $parent_id ] ?? [];
			$valid_set      = array_fill_keys( $valid_children, true );
			$seen           = [];
			$ordered        = [];

			foreach ( $declared_children[ $parent_id ] ?? [] as $child_id ) {
				if ( ! is_string( $child_id ) || ! isset( $valid_set[ $child_id ] ) || isset( $seen[ $child_id ] ) ) {
					continue;
				}

				$seen[ $child_id ] = true;
				$ordered[]         = $child_id;
			}

			foreach ( $valid_children as $child_id ) {
				if ( ! isset( $seen[ $child_id ] ) ) {
					$ordered[] = $child_id;
				}
			}

			foreach ( $ordered as $child_id ) {
				if ( isset( $map[ $child_id ] ) ) {
					$parent_node['children'][] = &$map[ $child_id ];
				}
			}
		}
		unset( $parent_node );

		foreach ( $root_ids as $root_id ) {
			if ( isset( $map[ $root_id ] ) ) {
				$tree[] = &$map[ $root_id ];
			}
		}

		return $tree;
	}

	// ------------------------------------------------------------------
	// bricks/get-page-elements
	// ------------------------------------------------------------------

	/**
	 * Input schema for get-page-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_page_elements_schema() {
		$properties = array_merge(
			self::post_identifier_properties(),
			self::scoped_read_properties()
		);

		$properties['includeSettings'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether to include full settings on returned elements. Defaults to true.', 'bricks' ),
		];

		$properties['responseFormat'] = [
			'type'        => 'string',
			'enum'        => [ 'detailed', 'summary' ],
			'description' => __( 'Use `summary` after broad writes to verify element counts, hierarchy, headings, global-class references, and SVG Image fallback sizing contracts without returning the full settings payload. Default `detailed` preserves the full element response.', 'bricks' ),
		];

		$properties['returnFields'] = [
			'type'        => 'array',
			'description' => __( 'Optional list of element fields to return. `id` is always included. Defaults to all fields.', 'bricks' ),
			'items'       => [
				'type' => 'string',
				'enum' => self::element_return_fields(),
			],
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
		];
	}

	/**
	 * Output schema for get-page-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_page_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elements'       => [ 'type' => 'array' ],
				'summary'        => [
					'type'        => 'object',
					'description' => __( 'Compact structural and reference-integrity verification when responseFormat is summary.', 'bricks' ),
				],
				'postId'         => [ 'type' => 'integer' ],
				'documentDigest' => [
					'type'        => 'string',
					'description' => __( 'SHA-256 digest of the complete current Bricks element tree. Use as expectedDocumentDigest for optimistic-concurrency writes and import previews.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: get full page elements
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_page_elements( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$area     = self::get_save_area_for_post( $post_id );
		$elements = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $elements ) ) {
			$elements = [];
		}

		$all_elements = $elements;

		$max_depth = self::optional_max_depth( $input );
		if ( is_wp_error( $max_depth ) ) {
			return $max_depth;
		}

		if ( isset( $input['elementId'] ) ) {
			$element_id = $input['elementId'];
			$elements   = self::filter_element_tree( $elements, $element_id, $max_depth );

			if ( empty( $elements ) ) {
				return Error::not_found( 'element', $element_id );
			}
		} elseif ( $max_depth !== null ) {
			$elements = self::filter_root_elements_by_depth( $elements, $max_depth );
		}

		if ( ( $input['responseFormat'] ?? 'detailed' ) === 'summary' ) {
			Manager::flush_options_cache();
			$class_snapshot = Design_Option_Store::read( BRICKS_DB_GLOBAL_CLASSES, [] );

			if ( is_wp_error( $class_snapshot ) ) {
				return $class_snapshot;
			}

			$global_classes = $class_snapshot['value'];
			$is_scoped      = isset( $input['elementId'] ) || $max_depth !== null;
			$summary        = self::summarize_elements(
				$elements,
				is_array( $global_classes ) ? $global_classes : [],
				$is_scoped ? $all_elements : null
			);

			if ( $is_scoped ) {
				$summary['scope'] = [
					'elementId' => $input['elementId'] ?? null,
					'maxDepth'  => $max_depth,
				];
			}

			return [
				'summary'        => $summary,
				'postId'         => $post_id,
				'documentDigest' => Save_Pipeline::document_digest( $all_elements ),
			];
		}

		$elements = self::format_elements_for_response( $elements, $input );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		return [
			'elements'       => $elements,
			'postId'         => $post_id,
			'documentDigest' => Save_Pipeline::document_digest( $all_elements ),
		];
	}

	/**
	 * Build a compact verification summary for an element collection.
	 *
	 * @since 2.4
	 *
	 * @param array      $elements           Bricks element rows included in the summary.
	 * @param array      $global_classes     Available global classes.
	 * @param array|null $integrity_elements Optional full tree used only for integrity checks when the summary is scoped.
	 * @return array
	 */
	public static function summarize_elements( array $elements, array $global_classes = [], $integrity_elements = null ) {
		$full_integrity = null;

		if ( is_array( $integrity_elements ) ) {
			$full_integrity = self::summarize_elements( $integrity_elements, $global_classes );
		}

		$by_id                                      = [];
		$duplicate_ids                              = [];
		$by_name                                    = [];
		$root_ids                                   = [];
		$invalid_parent_ids                         = [];
		$invalid_child_references                   = [];
		$parent_child_mismatches                    = [];
		$heading_outline                            = [];
		$referenced_global_class_ids                = [];
		$custom_css_element_count                   = 0;
		$custom_css_own_selector_mismatch_ids       = [];
		$data_svg_image_ids                         = [];
		$data_svg_image_without_width_ids           = [];
		$unscoped_data_svg_image_ids                = [];
		$data_svg_image_invalid_viewbox_ids         = [];
		$data_svg_image_without_intrinsic_ratio_ids = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = (string) ( $element['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}

			if ( isset( $by_id[ $id ] ) ) {
				$duplicate_ids[] = $id;
			}

			$by_id[ $id ] = $element;
		}

		foreach ( $by_id as $id => $element ) {
			$name             = (string) ( $element['name'] ?? '' );
			$parent           = $element['parent'] ?? 0;
			$settings         = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			$by_name[ $name ] = ( $by_name[ $name ] ?? 0 ) + 1;

			if ( ! $parent ) {
				$root_ids[] = $id;
			} elseif ( ! isset( $by_id[ (string) $parent ] ) ) {
				$invalid_parent_ids[] = $id;
			} else {
				$parent_children = is_array( $by_id[ (string) $parent ]['children'] ?? null ) ? $by_id[ (string) $parent ]['children'] : [];
				$parent_children = array_map( 'strval', $parent_children );

				if ( ! in_array( $id, $parent_children, true ) ) {
					$parent_child_mismatches[] = "{$parent}:{$id}";
				}
			}

			$children = is_array( $element['children'] ?? null ) ? $element['children'] : [];

			foreach ( $children as $child_id ) {
				$child_id = (string) $child_id;

				if ( ! isset( $by_id[ $child_id ] ) ) {
					$invalid_child_references[] = "{$id}:{$child_id}";
					continue;
				}

				if ( (string) ( $by_id[ $child_id ]['parent'] ?? 0 ) !== $id ) {
					$parent_child_mismatches[] = "{$id}:{$child_id}";
				}
			}

			$tag = strtolower( (string) ( $settings['tag'] ?? ( $name === 'heading' ? 'h3' : '' ) ) );
			if ( $name === 'heading' && preg_match( '/^h[1-6]$/', $tag ) ) {
				$heading_outline[] = [
					'id'    => $id,
					'level' => (int) substr( $tag, 1 ),
					'text'  => trim( wp_strip_all_tags( (string) ( $settings['text'] ?? '' ) ) ),
				];
			}

			foreach ( $settings['_cssGlobalClasses'] ?? [] as $class_id ) {
				$referenced_global_class_ids[ (string) $class_id ] = true;
			}

			foreach ( $settings as $setting_key => $setting_value ) {
				if ( strpos( (string) $setting_key, '_cssCustom' ) === 0 && is_string( $setting_value ) && trim( $setting_value ) !== '' ) {
					++$custom_css_element_count;

					$custom_css_id = trim( (string) ( $settings['_cssId'] ?? '' ) );
					if ( $custom_css_id !== '' && strpos( $setting_value, '#brxe-' . $id ) !== false ) {
						$custom_css_own_selector_mismatch_ids[] = $id;
					}

					break;
				}
			}

			if ( $name === 'image' ) {
				$image_url = $settings['image']['url'] ?? ( $settings['url'] ?? '' );

				if ( is_string( $image_url ) && strpos( $image_url, 'data:image/svg+xml' ) === 0 ) {
					$data_svg_image_ids[] = $id;
					$svg_markup           = self::decode_data_svg_url( $image_url );
					$svg_opening_tag      = '';

					if ( preg_match( '/<svg\b[^>]*>/i', $svg_markup, $svg_matches ) ) {
						$svg_opening_tag = $svg_matches[0];
					}

					$has_canonical_viewbox = preg_match( '/\bviewBox\s*=/', $svg_opening_tag ) === 1;
					$has_lowercase_viewbox = preg_match( '/\bviewbox\s*=/', $svg_opening_tag ) === 1;
					$has_intrinsic_width   = preg_match( '/\bwidth\s*=/i', $svg_opening_tag ) === 1;
					$has_intrinsic_height  = preg_match( '/\bheight\s*=/i', $svg_opening_tag ) === 1;

					if ( ! $has_canonical_viewbox && $has_lowercase_viewbox ) {
						$data_svg_image_invalid_viewbox_ids[] = $id;
					}

					if ( ! $has_canonical_viewbox && ! ( $has_intrinsic_width && $has_intrinsic_height ) ) {
						$data_svg_image_without_intrinsic_ratio_ids[] = $id;
					}

					if ( empty( $settings['_width'] ) ) {
						$data_svg_image_without_width_ids[] = $id;
					}

					$has_sizing_or_class_contract = (
						! empty( $settings['_width'] ) ||
						! empty( $settings['_height'] ) ||
						! empty( $settings['_cssClasses'] ) ||
						! empty( $settings['_cssGlobalClasses'] )
					);

					if ( ! $has_sizing_or_class_contract ) {
						$unscoped_data_svg_image_ids[] = $id;
					}
				}
			}
		}

		$known_global_class_ids = [];
		$global_class_settings  = [];
		foreach ( $global_classes as $global_class ) {
			if ( is_array( $global_class ) && ! empty( $global_class['id'] ) ) {
				$class_id                            = (string) $global_class['id'];
				$known_global_class_ids[ $class_id ] = true;
				$global_class_settings[ $class_id ]  = is_array( $global_class['settings'] ?? null ) ? $global_class['settings'] : [];
			}
		}

		$single_container_section_layout_root_ids = [];
		foreach ( $root_ids as $root_id ) {
			$root = $by_id[ $root_id ];

			if ( ( $root['name'] ?? '' ) !== 'section' || count( $root['children'] ?? [] ) !== 1 ) {
				continue;
			}

			$child_id = (string) $root['children'][0];
			if ( ( $by_id[ $child_id ]['name'] ?? '' ) !== 'container' ) {
				continue;
			}

			$layout_settings = is_array( $root['settings'] ?? null ) ? $root['settings'] : [];
			foreach ( $layout_settings['_cssGlobalClasses'] ?? [] as $class_id ) {
				$layout_settings = array_merge( $global_class_settings[ (string) $class_id ] ?? [], $layout_settings );
			}

			$display = strtolower( (string) ( $layout_settings['_display'] ?? '' ) );
			if (
				in_array( $display, [ 'grid', 'inline-grid', 'flex', 'inline-flex' ], true ) ||
				! empty( $layout_settings['_gridTemplateColumns'] )
			) {
				$single_container_section_layout_root_ids[] = $root_id;
			}
		}

		$missing_global_class_ids = array_values( array_diff( array_keys( $referenced_global_class_ids ), array_keys( $known_global_class_ids ) ) );
		sort( $missing_global_class_ids );
		ksort( $by_name );

		$integrity = [
			'duplicateIds'            => array_values( array_unique( $duplicate_ids ) ),
			'invalidParentElementIds' => array_values( array_unique( $invalid_parent_ids ) ),
			'invalidChildReferences'  => array_values( array_unique( $invalid_child_references ) ),
			'parentChildMismatches'   => array_values( array_unique( $parent_child_mismatches ) ),
			'missingGlobalClassIds'   => $missing_global_class_ids,
		];

		if ( is_array( $full_integrity ) ) {
			$integrity = $full_integrity['integrity'];
		}

		return [
			'elementCount'                          => count( $by_id ),
			'elementIdDigest'                       => hash( 'sha256', implode( ',', array_keys( $by_id ) ) ),
			'rootIds'                               => $root_ids,
			'byName'                                => $by_name,
			'headingOutline'                        => $heading_outline,
			'referencedGlobalClassCount'            => count( $referenced_global_class_ids ),
			'customCssElementCount'                 => $custom_css_element_count,
			'customCssOwnSelectorMismatchIds'       => array_values( array_unique( $custom_css_own_selector_mismatch_ids ) ),
			'dataSvgImageIds'                       => $data_svg_image_ids,
			'dataSvgImagesWithoutExplicitWidthIds'  => $data_svg_image_without_width_ids,
			'dataSvgImagesWithoutSizingOrClassIds'  => $unscoped_data_svg_image_ids,
			'dataSvgImagesWithInvalidViewBoxIds'    => $data_svg_image_invalid_viewbox_ids,
			'dataSvgImagesWithoutIntrinsicRatioIds' => $data_svg_image_without_intrinsic_ratio_ids,
			'singleContainerSectionLayoutRootIds'   => $single_container_section_layout_root_ids,
			'integrity'                             => array_merge(
				[ 'valid' => count( array_filter( $integrity ) ) === 0 ],
				$integrity
			),
		];
	}

	/**
	 * Decode an SVG data URL for structural validation.
	 *
	 * @since 2.4
	 *
	 * @param string $url SVG data URL.
	 * @return string
	 */
	private static function decode_data_svg_url( $url ) {
		$separator = strpos( $url, ',' );
		if ( $separator === false ) {
			return '';
		}

		$metadata = substr( $url, 0, $separator );
		$payload  = substr( $url, $separator + 1 );

		if ( stripos( $metadata, ';base64' ) !== false ) {
			$decoded = base64_decode( $payload, true );
			return is_string( $decoded ) ? $decoded : '';
		}

		return rawurldecode( $payload );
	}

	/**
	 * Filter elements to specific element + descendants
	 *
	 * @since 2.4
	 *
	 * @param array    $elements   All elements.
	 * @param string   $element_id Target element ID.
	 * @param int|null $max_depth  Optional descendant depth. Null includes all descendants.
	 * @return array Filtered elements.
	 */
	public static function filter_element_tree( $elements, $element_id, $max_depth = null ) {
		$by_id = self::index_elements_by_id( $elements );

		if ( ! isset( $by_id[ $element_id ] ) ) {
			return [];
		}

		$include_ids = self::collect_descendant_ids( $elements, [ $element_id ], $max_depth );

		return array_values(
			array_filter(
				$elements,
				function( $el ) use ( $include_ids ) {
					return isset( $include_ids[ $el['id'] ?? '' ] );
				}
			)
		);
	}

	/**
	 * Filter a full element tree to root elements and descendants up to a depth.
	 *
	 * @since 2.4
	 *
	 * @param array $elements  All elements.
	 * @param int   $max_depth Descendant depth from each root element.
	 * @return array Filtered elements.
	 */
	private static function filter_root_elements_by_depth( $elements, $max_depth ) {
		$by_id    = self::index_elements_by_id( $elements );
		$root_ids = [];

		foreach ( $elements as $element ) {
			$id     = $element['id'] ?? '';
			$parent = $element['parent'] ?? 0;

			if ( ! $id ) {
				continue;
			}

			if ( ! $parent || ! isset( $by_id[ $parent ] ) ) {
				$root_ids[] = $id;
			}
		}

		$include_ids = self::collect_descendant_ids( $elements, $root_ids, $max_depth );

		return array_values(
			array_filter(
				$elements,
				function( $el ) use ( $include_ids ) {
					return isset( $include_ids[ $el['id'] ?? '' ] );
				}
			)
		);
	}

	/**
	 * Collect root and descendant IDs up to an optional depth.
	 *
	 * @since 2.4
	 *
	 * @param array    $elements  All elements.
	 * @param string[] $root_ids  Root element IDs.
	 * @param int|null $max_depth Optional descendant depth. Null includes all descendants.
	 * @return array Map of included element IDs.
	 */
	private static function collect_descendant_ids( $elements, $root_ids, $max_depth = null ) {
		$children_by_parent = [];

		foreach ( $elements as $element ) {
			$id     = $element['id'] ?? '';
			$parent = $element['parent'] ?? 0;

			if ( ! $id || ! $parent ) {
				continue;
			}

			if ( ! isset( $children_by_parent[ $parent ] ) ) {
				$children_by_parent[ $parent ] = [];
			}

			$children_by_parent[ $parent ][] = $id;
		}

		$include_ids = [];
		$queue       = [];

		foreach ( $root_ids as $root_id ) {
			$include_ids[ $root_id ] = true;
			$queue[]                 = [ $root_id, 0 ];
		}

		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			$id      = $current[0];
			$depth   = $current[1];

			if ( $max_depth !== null && $depth >= $max_depth ) {
				continue;
			}

			foreach ( $children_by_parent[ $id ] ?? [] as $child_id ) {
				if ( isset( $include_ids[ $child_id ] ) ) {
					continue;
				}

				$include_ids[ $child_id ] = true;
				$queue[]                  = [ $child_id, $depth + 1 ];
			}
		}

		return $include_ids;
	}

	/**
	 * Redact executable-code payloads from element arrays for users who cannot
	 * execute code. This keeps layout reads useful while avoiding PHP/SVG/query
	 * source disclosure through MCP.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element rows.
	 * @return array
	 */
	public static function redact_code_sensitive_elements( array $elements ): array {
		if ( \Bricks\Capabilities::current_user_can_execute_code() ) {
			return $elements;
		}

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$name     = (string) ( $element['name'] ?? '' );
			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];

			if ( $name === 'code' && ! \Bricks\Abilities\Code_Authoring::can_author_without_php( $settings ) ) {
				foreach ( [ 'code', 'cssCode', 'javascriptCode', 'useDynamicData' ] as $key ) {
					if ( isset( $settings[ $key ] ) ) {
						$settings[ $key ]             = '';
						$settings[ "{$key}Redacted" ] = true;
					}
				}

				if ( isset( $settings['signature'] ) ) {
					unset( $settings['signature'] );
					$settings['signatureRedacted'] = true;
				}
			}

			if ( $name === 'svg' && isset( $settings['code'] ) ) {
				$settings['code']         = '';
				$settings['codeRedacted'] = true;

				if ( isset( $settings['signature'] ) ) {
					unset( $settings['signature'] );
					$settings['signatureRedacted'] = true;
				}
			}

			if ( isset( $settings['query'] ) && is_array( $settings['query'] ) ) {
				if ( isset( $settings['query']['queryEditor'] ) ) {
					$settings['query']['queryEditor']         = '';
					$settings['query']['queryEditorRedacted'] = true;
				}

				if ( isset( $settings['query']['signature'] ) ) {
					unset( $settings['query']['signature'] );
					$settings['query']['signatureRedacted'] = true;
				}
			}

			if ( array_key_exists( 'settings', $element ) || ! empty( $settings ) ) {
				$element['settings'] = self::redact_echo_dynamic_tags( $settings );
			}

			if ( ! empty( $element['cid'] ) && is_array( $element['properties'] ?? null ) ) {
				foreach ( $element['properties'] as $property_id => $property ) {
					if ( is_array( $property ) ) {
						$element['properties'][ $property_id ] = self::redact_component_property_value( $property );
					}
				}
			}
			$elements[ $index ] = $element;
		}

		return $elements;
	}

	/**
	 * Apply response-shaping options to element rows.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Element rows.
	 * @param array $input    Ability input.
	 * @return array|\WP_Error Formatted element rows or error.
	 */
	private static function format_elements_for_response( array $elements, array $input ) {
		$elements = self::redact_code_sensitive_elements( $elements );

		foreach ( $elements as $index => $element ) {
			$formatted = self::format_element_for_response( $element, $input );

			if ( is_wp_error( $formatted ) ) {
				return $formatted;
			}

			$elements[ $index ] = $formatted;
		}

		return $elements;
	}

	/**
	 * Apply response-shaping options to a single element row.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element row.
	 * @param array $input   Ability input.
	 * @return array|\WP_Error Formatted element row or error.
	 */
	private static function format_element_for_response( array $element, array $input ) {
		$return_fields = self::optional_return_fields( $input );

		if ( is_wp_error( $return_fields ) ) {
			return $return_fields;
		}

		if ( $return_fields !== null ) {
			$return_fields = array_values( array_unique( array_merge( [ 'id' ], $return_fields ) ) );
			$filtered      = [];

			foreach ( $return_fields as $field ) {
				if ( array_key_exists( $field, $element ) ) {
					$filtered[ $field ] = $element[ $field ];
				}
			}

			return $filtered;
		}

		if ( array_key_exists( 'includeSettings', $input ) && ! $input['includeSettings'] ) {
			unset( $element['settings'] );
		}

		return $element;
	}

	/**
	 * Parse optional non-negative maxDepth input.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return int|null|\WP_Error Parsed depth, null when omitted, or error.
	 */
	private static function optional_max_depth( array $input ) {
		if ( ! array_key_exists( 'maxDepth', $input ) ) {
			return null;
		}

		$max_depth = $input['maxDepth'];

		if ( ! is_numeric( $max_depth ) || (int) $max_depth < 0 ) {
			return Error::invalid_param( 'maxDepth', 'integer >= 0', $max_depth );
		}

		return (int) $max_depth;
	}

	/**
	 * Parse optional returnFields input.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return string[]|null|\WP_Error Field list, null when omitted, or error.
	 */
	private static function optional_return_fields( array $input ) {
		if ( ! array_key_exists( 'returnFields', $input ) ) {
			return null;
		}

		if ( ! is_array( $input['returnFields'] ) ) {
			return Error::invalid_param( 'returnFields', 'array of element field names', $input['returnFields'] );
		}

		$allowed = self::element_return_fields();

		foreach ( $input['returnFields'] as $index => $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, $allowed, true ) ) {
				return Error::invalid_param( "returnFields[{$index}]", 'one of: ' . implode( ', ', $allowed ), $field );
			}
		}

		return $input['returnFields'];
	}

	/**
	 * Reject code-sensitive element writes through abilities.
	 *
	 * Changed payloads follow their language capabilities. Unchanged restricted
	 * payloads may pass through so unrelated edits preserve saved code.
	 *
	 * @since 2.4
	 *
	 * @param array $existing Current saved elements.
	 * @param array $incoming Incoming elements.
	 * @return true|\WP_Error
	 */
	public static function check_code_sensitive_permissions( array $existing, array $incoming ) {
		$existing_by_id = self::index_elements_by_id( $existing );

		foreach ( $incoming as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id       = (string) ( $element['id'] ?? '' );
			$previous = $id !== '' && isset( $existing_by_id[ $id ] ) ? $existing_by_id[ $id ] : [];

			if ( ( $element['name'] ?? '' ) === 'svg' && \Bricks\Helpers::code_signature_generation_locked() ) {
				$settings     = $element['settings'] ?? [];
				$old_settings = ( $previous['name'] ?? '' ) === 'svg' ? ( $previous['settings'] ?? [] ) : [];
				$code         = self::present_sensitive_values( $settings, [ 'code' ] );
				// Clearing code needs no new signature; retained signatures cannot authorize different source.
				if ( ( $code && $code !== self::present_sensitive_values( $old_settings, [ 'code' ] ) ) || self::present_sensitive_values( $settings, [ 'signature' ] ) !== self::present_sensitive_values( $old_settings, [ 'signature' ] ) || ( $code && ( $settings['source'] ?? '' ) === 'code' && ( $old_settings['source'] ?? '' ) !== 'code' ) ) {
					return Error::code_sensitive_write_forbidden();
				}
			}

			$previous_payload = self::code_sensitive_payload_for_current_user( $previous );
			$incoming_payload = self::code_sensitive_payload_for_current_user( $element );

			// Component cycles are invalid and cannot be resolved safely across property contexts.
			if ( self::payload_contains_component_cycle( $incoming_payload ) || $previous_payload !== $incoming_payload ) {
				return Error::code_sensitive_write_forbidden();
			}
		}

		return true;
	}

	/**
	 * Extract the code-sensitive subset of an element row.
	 *
	 * @since 2.4
	 *
	 * @param array $element              Element row.
	 * @param array $active_component_ids Component IDs active in this traversal.
	 * @param bool  $include_capability_gated_payloads Whether to include echo tags and SVG source.
	 * @return array
	 */
	public static function code_sensitive_payload( array $element, array &$active_component_ids = [], bool $include_capability_gated_payloads = true ): array {
		$name     = (string) ( $element['name'] ?? '' );
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$payload  = [];

		if ( $name === 'code' ) {
			$payload['code'] = array_intersect_key( $settings, array_flip( [ 'executeCode', 'code', 'cssCode', 'javascriptCode', 'useDynamicData', 'parseDynamicData', 'supressPhpErrors', 'noRoot', 'noRootForce', 'signature' ] ) );
		}

		if ( $include_capability_gated_payloads && $name === 'svg' ) {
			$payload['svg'] = self::present_sensitive_values( $settings, [ 'code', 'signature' ] );
		}

		if ( isset( $settings['query'] ) && is_array( $settings['query'] ) ) {
			$payload['query'] = self::present_sensitive_values(
				$settings['query'],
				[ 'useQueryEditor', 'queryEditor', 'signature' ]
			);

			$global_query_payload = self::global_query_code_sensitive_payload( $settings['query'] );

			if ( ! empty( $global_query_payload ) ) {
				$payload['globalQuery'] = $global_query_payload;
			}
		}

		if ( ! empty( $element['cid'] ) && is_array( $element['properties'] ?? null ) ) {
			$property_payload = self::extract_component_property_code_payload( $element['properties'] );

			if ( ! empty( $property_payload ) ) {
				$payload['componentProperties'] = $property_payload;
			}
		}

		if ( ! empty( $element['cid'] ) ) {
			$component_payload = self::component_instance_code_sensitive_payload( $element, $active_component_ids, $include_capability_gated_payloads );

			if ( ! empty( $component_payload ) ) {
				$payload['component'] = $component_payload;
			}
		}

		if ( $include_capability_gated_payloads ) {
			$echo_tags = self::extract_echo_dynamic_tags( $element );
			if ( ! empty( $echo_tags ) ) {
				$payload['echoTags'] = $echo_tags;
			}
		}

		return array_filter(
			$payload,
			static function ( $value ) {
				return ! empty( $value );
			}
		);
	}

	/**
	 * Extract payloads the current user cannot author or execute through abilities.
	 *
	 * Echo dynamic tags and inline SVG source follow the Builder capability contract.
	 * SVG signatures follow native signing permissions. Code elements additionally follow their
	 * language permissions and the explicit PHP abilities opt-in.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element row.
	 * @return array
	 */
	public static function code_sensitive_payload_for_current_user( array $element ): array {
		$active_component_ids = [];
		$payload              = self::code_sensitive_payload( $element, $active_component_ids, ! \Bricks\Capabilities::current_user_can_execute_code() );
		return self::restricted_code_payload( $payload );
	}

	/**
	 * Apply the PHP authorization contract to persistent authoring as well as execution.
	 *
	 * @return bool
	 * @since 2.4
	 */
	public static function can_author_php(): bool {
		return defined( 'BRICKS_ENABLE_PHP_ABILITIES' ) && BRICKS_ENABLE_PHP_ABILITIES === true && class_exists( Execute_PHP::class ) && ! is_wp_error( Execute_PHP::authorize_request() );
	}

	/**
	 * Describe the missing authorization without exposing source code.
	 *
	 * @param array $element Imported element.
	 * @return string
	 */
	public static function code_authoring_restriction_reason( array $element ): string {
		$payload = self::code_sensitive_payload_for_current_user( $element );
		if ( isset( $payload['code'] ) ) {
			if ( isset( $payload['code']['executeCode'] ) && ! \Bricks\Capabilities::current_user_can_execute_code() ) {
				return 'code_capability_required';
			}
			return ( \Bricks\Abilities\Code_Authoring::requires_php( $payload['code'] ) || ( isset( $payload['code']['executeCode'] ) && (string) ( $payload['code']['code'] ?? '' ) !== '' ) ) ? 'php_abilities_not_authorized' : 'unfiltered_html_required';
		}
		if ( isset( $payload['query'] ) || isset( $payload['globalQuery'] ) || isset( $payload['componentProperties'] ) ) {
			return 'php_abilities_not_authorized';
		}
		return 'code_capability_required';
	}

	/**
	 * Remove authorized portions of an extracted code payload.
	 *
	 * @param array $payload Extracted payload tree.
	 * @return array
	 */
	private static function restricted_code_payload( array $payload ): array {
		$can_php = self::can_author_php();
		if ( isset( $payload['code'] ) ) {
			$payload['code'] = \Bricks\Abilities\Code_Authoring::restricted_settings( $payload['code'], $can_php );
		}
		if ( $can_php ) {
			unset( $payload['query'], $payload['globalQuery'], $payload['componentProperties'] );
		}
		if ( isset( $payload['component'] ) ) {
			foreach ( $payload['component'] as $id => $nested ) {
				if ( is_array( $nested ) ) {
					$payload['component'][ $id ] = self::restricted_code_payload( $nested );
				}
			}
			$payload['component'] = array_filter( $payload['component'] );
		}
		return array_filter( $payload );
	}

	/**
	 * Sign authorized SVG and changed PHP after final element validation.
	 *
	 * Signatures are derived from final bytes, never accepted as authorization.
	 * Keep unchanged signed rows intact and make repeated preview/apply deterministic.
	 *
	 * @param array $existing Existing element rows.
	 * @param array $incoming Validated incoming rows.
	 * @return array
	 */
	public static function sign_authorized_code( array $existing, array $incoming ): array {
		$can_php  = self::can_author_php();
		$previous = self::index_elements_by_id( $existing );
		foreach ( $incoming as &$element ) {
			$old      = $previous[ $element['id'] ?? '' ] ?? [];
			$settings = $element['settings'] ?? [];
			if ( ( $element['name'] ?? '' ) === 'svg' && ( $settings['source'] ?? '' ) === 'code' ) {
				$old_settings = $old['settings'] ?? [];
				if ( ( $old['name'] ?? '' ) === 'svg' && ( $old_settings['source'] ?? '' ) === 'code' && self::present_sensitive_values( $settings, [ 'code', 'signature' ] ) === self::present_sensitive_values( $old_settings, [ 'code', 'signature' ] ) ) {
					// Unrelated edits cannot rewrite the identity or date of the saved signing event.
					foreach ( [ 'user_id', 'time' ] as $key ) {
						if ( array_key_exists( $key, $old_settings ) ) {
							$element['settings'][ $key ] = $old_settings[ $key ];
						} else {
							unset( $element['settings'][ $key ] );
						}
					}
				} elseif ( is_string( $settings['code'] ?? null ) && $settings['code'] !== '' && strpos( $settings['code'], '<?' ) === false && current_user_can( 'manage_options' ) && \Bricks\Capabilities::current_user_can_execute_code() && \Bricks\Helpers::code_execution_enabled() && ! \Bricks\Helpers::code_signature_generation_locked() ) {
					$element['settings']['signature'] = wp_hash( $settings['code'] );
					$element['settings']['user_id']   = get_current_user_id();
					unset( $element['settings']['time'] );
				}
			}
			if ( ! $can_php ) {
				continue;
			}
			if ( ( $element['name'] ?? '' ) === 'code' && ( \Bricks\Abilities\Code_Authoring::requires_php( $settings ) || isset( $settings['executeCode'] ) ) && self::code_sensitive_payload( $element ) !== self::code_sensitive_payload( $old ) ) {
				$element['settings'] = self::sign_php_settings( $settings );
			}
			if ( ! empty( $element['cid'] ) && is_array( $element['properties'] ?? null ) ) {
				foreach ( $element['properties'] as $property_id => $property ) {
					if ( is_array( $property ) && $property !== ( $old['properties'][ $property_id ] ?? [] ) ) {
						$element['properties'][ $property_id ] = self::sign_php_settings( $property, 'queryEditor' );
					}
				}
			}
			if ( isset( $settings['query'] ) && is_array( $settings['query'] ) && $settings['query'] !== ( $old['settings']['query'] ?? [] ) ) {
				$element['settings']['query'] = self::sign_php_settings( $settings['query'], 'queryEditor' );
			}
		}
		unset( $element );
		return $incoming;
	}

	/**
	 * Redact all executable keys recognized by the component write boundary.
	 *
	 * @param array $value Component property value.
	 * @return array
	 */
	private static function redact_component_property_value( array $value ): array {
		if ( \Bricks\Capabilities::current_user_can_execute_code() ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( (string) $key, self::COMPONENT_CODE_KEYS, true ) ) {
				if ( $key === 'signature' ) {
					unset( $value[ $key ] );
				} else {
					$value[ $key ] = '';
				}
				$value[ "{$key}Redacted" ] = true;
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = self::redact_component_property_value( $item );
			}
		}
		return self::redact_echo_dynamic_tags( $value );
	}

	/**
	 * Apply query-code read permissions to component definition defaults.
	 *
	 * @param array $properties Component property definitions.
	 * @return array
	 */
	public static function redact_component_code_defaults( array $properties ): array {
		foreach ( $properties as &$property ) {
			if ( is_array( $property['default'] ?? null ) ) {
				$property['default'] = self::redact_component_property_value( $property['default'] );
			}
		}
		unset( $property );
		return $properties;
	}

	/**
	 * Validate and sign component query defaults before they become live queries.
	 *
	 * @param array $existing Existing property definitions.
	 * @param array $incoming Incoming property definitions.
	 * @param bool  $new_component Whether the caller generated a fresh component ID.
	 * @return array|\WP_Error
	 */
	public static function prepare_component_code_defaults( array $existing, array $incoming, bool $new_component = false ) {
		$previous = self::index_elements_by_id( $existing );
		if ( ! $new_component && ! self::can_author_php() ) {
			$next = self::index_elements_by_id( $incoming );
			foreach ( $previous as $id => $property ) {
				if ( ( $property['type'] ?? '' ) === 'query' && ! isset( $next[ $id ] ) ) {
					return Error::code_sensitive_write_forbidden();
				}
			}
		}
		foreach ( $incoming as &$property ) {
			if ( ! is_array( $property ) ) {
				continue;
			}
			$id              = (string) ( $property['id'] ?? '' );
			$old_property    = $previous[ $id ] ?? [];
			$binding_changed = ( $property['connections'] ?? [] ) !== ( $old_property['connections'] ?? [] ) || ( $property['type'] ?? '' ) !== ( $old_property['type'] ?? '' );
			// Existing instances can retain signed overrides, even for removed property IDs.
			if ( ! $new_component && $binding_changed && ( ( $property['type'] ?? '' ) === 'query' || ( $old_property['type'] ?? '' ) === 'query' ) && ! self::can_author_php() ) {
				return Error::code_sensitive_write_forbidden();
			}
			if ( ! is_array( $property['default'] ?? null ) ) {
				continue;
			}
			$old_default = $previous[ $id ]['default'] ?? [];
			$default     = $property['default'];
			if ( ! self::can_author_php() && self::extract_component_property_code_payload( is_array( $old_default ) ? $old_default : [] ) !== self::extract_component_property_code_payload( $default ) ) {
				return Error::code_sensitive_write_forbidden();
			}
			$before = [
				'id'       => $id,
				'settings' => [ 'query' => $old_default ]
			];
			$after  = [
				'id'       => $id,
				'settings' => [ 'query' => $default ]
			];
			// Rebinding unchanged signed defaults can move executable PHP to a new target.
			if ( $binding_changed ) {
				if ( self::code_sensitive_payload_for_current_user( $after ) !== [] ) {
					return Error::code_sensitive_write_forbidden();
				}
			}
			$permission = self::check_code_sensitive_permissions( [ $before ], [ $after ] );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
			if ( $default !== $old_default ) {
				$property['default'] = self::sign_php_settings( $default, 'queryEditor' );
			}
		}
		unset( $property );
		return $incoming;
	}

	/**
	 * Create the native signature for an authorized PHP setting.
	 *
	 * @param array  $settings Validated settings.
	 * @param string $key PHP code setting.
	 * @return array
	 */
	public static function sign_php_settings( array $settings, string $key = 'code' ): array {
		if ( self::can_author_php() && isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
			$settings['signature'] = wp_hash( $settings[ $key ] );
			$settings['user_id']   = get_current_user_id();
			// Caller-supplied provenance must not impersonate a previous signing event.
			unset( $settings['time'] );
		}
		return $settings;
	}

	/**
	 * Extract executable query payloads embedded in component property values.
	 *
	 * @since 2.4
	 *
	 * @param array $value Component property value tree.
	 * @return array
	 */
	private static function extract_component_property_code_payload( array $value ): array {
		$payload = [];

		foreach ( $value as $key => $item ) {
			if ( in_array( (string) $key, self::COMPONENT_CODE_KEYS, true ) && ( ( $item !== '' && $item !== null && $item !== false && $item !== [] ) || ( isset( $value[ $key ] ) && in_array( (string) $key, [ 'executeCode', 'parseDynamicData', 'supressPhpErrors', 'noRoot', 'noRootForce', 'useQueryEditor' ], true ) ) ) ) {
				$payload[ $key ] = $item;
				continue;
			}

			if ( is_array( $item ) ) {
				$nested = self::extract_component_property_code_payload( $item );

				if ( ! empty( $nested ) ) {
					$payload[ $key ] = $nested;
				}
			}
		}

		return $payload;
	}

	/**
	 * Resolve code-sensitive settings referenced through a global query ID.
	 *
	 * @since 2.4
	 *
	 * @param array $query Query control settings.
	 * @return array
	 */
	private static function global_query_code_sensitive_payload( array $query ): array {
		$query_id = (string) ( $query['id'] ?? '' );

		if ( $query_id === '' || ! method_exists( '\\Bricks\\Database', 'get_global_queries' ) ) {
			return [];
		}

		foreach ( (array) \Bricks\Database::get_global_queries() as $global_query ) {
			if ( ! is_array( $global_query ) || (string) ( $global_query['id'] ?? '' ) !== $query_id ) {
				continue;
			}

			$global_settings = is_array( $global_query['settings'] ?? null ) ? $global_query['settings'] : [];
			$sensitive       = self::present_sensitive_values( $global_settings, [ 'useQueryEditor', 'queryEditor', 'signature' ] );

			return empty( $sensitive ) ? [] : [
				'id'       => $query_id,
				'settings' => $sensitive,
			];
		}

		return [];
	}

	/**
	 * Extract code-sensitive values after component properties are applied.
	 *
	 * @since 2.4
	 *
	 * @param array $element              Component instance element.
	 * @param array $active_component_ids Component IDs active in this traversal.
	 * @param bool  $include_capability_gated_payloads Whether to include echo tags and SVG source.
	 * @return array
	 */
	private static function component_instance_code_sensitive_payload( array $element, array &$active_component_ids, bool $include_capability_gated_payloads ): array {
		if ( ! method_exists( '\\Bricks\\Helpers', 'get_component_instance' ) ) {
			return [];
		}

		$component_id = (string) ( $element['cid'] ?? '' );

		if ( $component_id === '' ) {
			return [];
		}

		if ( isset( $active_component_ids[ $component_id ] ) ) {
			return [ 'componentCycle' => $component_id ];
		}

		$active_component_ids[ $component_id ] = true;

		$elements = \Bricks\Helpers::get_component_instance( $element, 'elements' );
		$payload  = [];

		foreach ( ( is_array( $elements ) ? $elements : [] ) as $component_element ) {
			if ( ! is_array( $component_element ) ) {
				continue;
			}

			$element_payload = self::code_sensitive_payload( $component_element, $active_component_ids, $include_capability_gated_payloads );

			if ( ! empty( $element_payload ) ) {
				$payload[ (string) ( $component_element['id'] ?? count( $payload ) ) ] = $element_payload;
			}
		}

		unset( $active_component_ids[ $component_id ] );

		return $payload;
	}

	/**
	 * Whether a payload contains an unresolved component cycle.
	 *
	 * @since 2.4
	 *
	 * @param array $payload Code-sensitive payload tree.
	 * @return bool
	 */
	private static function payload_contains_component_cycle( array $payload ): bool {
		if ( isset( $payload['componentCycle'] ) ) {
			return true;
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) && self::payload_contains_component_cycle( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return present, meaningful code-sensitive values from a settings array.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Settings array.
	 * @param array $keys     Keys to read.
	 * @return array
	 */
	private static function present_sensitive_values( array $settings, array $keys ): array {
		$out = [];

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			$value = $settings[ $key ];
			// Query Editor activation uses isset(), so false and empty values still change execution.
			if ( ( $value === '' || $value === null || $value === false || $value === [] ) && ! ( $key === 'useQueryEditor' && isset( $settings[ $key ] ) ) ) {
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Extract `{echo:...}` dynamic-data tags from an element payload.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element row.
	 * @return string[]
	 */
	private static function extract_echo_dynamic_tags( array $element ): array {
		$json = wp_json_encode( $element );

		if ( ! is_string( $json ) || $json === '' ) {
			return [];
		}

		if ( ! preg_match_all( '/\{echo:([^\}]+)\}/', $json, $matches ) ) {
			return [];
		}

		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Redact `{echo:...}` dynamic-data snippets from arbitrary setting values.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Setting value.
	 * @return mixed
	 */
	private static function redact_echo_dynamic_tags( $value ) {
		if ( is_string( $value ) ) {
			$redacted = preg_replace( '/\{echo:[^\}]+\}/', '{echo:redacted}', $value );

			return is_string( $redacted ) ? $redacted : $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::redact_echo_dynamic_tags( $item );
			}
		}

		return $value;
	}

	// ------------------------------------------------------------------
	// bricks/get-page-settings
	// ------------------------------------------------------------------

	/**
	 * Output schema for get-page-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_page_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [ 'type' => 'object' ],
				'postId'   => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: get page settings
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_page_settings( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$settings = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			foreach ( [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ] as $script_key ) {
				unset( $settings[ $script_key ] );
			}
		}

		return [
			'settings' => $settings,
			'postId'   => $post_id,
		];
	}

	// ------------------------------------------------------------------
	// bricks/set-page-elements (Phase 6)
	// ------------------------------------------------------------------

	/**
	 * Input schema for set-page-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_page_elements_schema() {
		$properties                           = self::post_identifier_properties();
		$properties['elements']               = [
			'type'        => 'array',
			'description' => __( 'Element objects, not JSON strings. Accepts nested {name, children} format or flat Bricks format. Element `id` is the internal 6-character Bricks ID used by builder references and the default frontend selector `#brxe-{id}`. In nested format, you may omit `id` and Bricks generates ids while preserving parent-child nesting. In flat format, ids and parent references must be valid 6-character ids.', 'bricks' ),
			'items'       => [
				'type' => 'object',
			],
		];
		$properties['expectedDocumentDigest'] = [
			'type'        => 'string',
			'description' => __( 'Optional optimistic-concurrency precondition. Pass a SHA-256 document digest returned by a preview workflow; the save is rejected if the current Bricks tree changed before commit.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elements' ],
		];
	}

	/**
	 * Output schema for set-page-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_page_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementIds'     => [
					'type'        => 'array',
					'description' => __( 'Array of saved element IDs.', 'bricks' ),
				],
				'elementCount'   => [ 'type' => 'integer' ],
				'revisionId'     => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the snapshot taken before this save. Pass to bricks/restore-revision to roll back. Null if the page had no prior content.', 'bricks' ),
				],
				'documentDigest' => [
					'type'        => 'string',
					'description' => __( 'SHA-256 digest of the saved normalized element tree.', 'bricks' ),
				],
				'changed'        => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: replace entire element tree
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function set_page_elements( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );

		$area     = self::get_save_area_for_post( $post_id );
		$existing = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$normalizer = new Element_Normalizer();
		$elements   = $normalizer->normalize( $input['elements'] );

		if ( is_wp_error( $elements ) ) {
			return $elements;
		}

		$permission_check = self::check_full_tree_permissions( $existing, $elements );

		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		$lock = self::acquire_post_lock( $post_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$result = Save_Pipeline::execute(
			$post_id,
			$elements,
			$area,
			[],
			(string) ( $input['expectedDocumentDigest'] ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved = $result['elements'];

		return [
			'elementIds'     => array_map(
				function ( $el ) {
					return $el['id'] ?? '';
				},
				$saved
			),
			'elementCount'   => count( $saved ),
			'revisionId'     => $result['revisionId'],
			'documentDigest' => $result['documentDigest'],
			'changed'        => $result['changed'],
		];
	}

	/**
	 * Enforce the same builder permission boundaries for full-tree replacement
	 * that the granular add/update/remove abilities enforce one operation at a time.
	 *
	 * @since 2.4
	 *
	 * @param array $existing Current saved elements.
	 * @param array $incoming Normalized replacement elements.
	 * @return true|\WP_Error
	 */
	public static function check_full_tree_permissions( $existing, $incoming ) {
		$identifier_check = Element_Validator::validate_identifiers( $incoming );

		if ( is_wp_error( $identifier_check ) ) {
			return $identifier_check;
		}

		$existing_by_id = self::index_elements_by_id( $existing );
		$incoming_by_id = self::index_elements_by_id( $incoming );

		foreach ( $existing_by_id as $id => $element ) {
			if ( ! isset( $incoming_by_id[ $id ] ) ) {
				if ( ! \Bricks\Builder_Permissions::user_has_permission( 'delete_elements' ) ) {
					return Error::forbidden_builder_permission( 'delete_elements' );
				}

				break;
			}
		}

		foreach ( $incoming_by_id as $id => $element ) {
			if ( isset( $existing_by_id[ $id ] ) ) {
				continue;
			}

			$check = self::check_add_permissions_for_element( $element );

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$move_check = self::check_full_tree_move_permissions( $existing, $incoming, $existing_by_id, $incoming_by_id );

		if ( is_wp_error( $move_check ) ) {
			return $move_check;
		}

		foreach ( $incoming_by_id as $id => $incoming_element ) {
			if ( ! isset( $existing_by_id[ $id ] ) ) {
				continue;
			}

			$existing_element = $existing_by_id[ $id ];
			$semantic_check   = self::validate_changed_settings_for_element(
				$incoming_element,
				$existing_element['settings'] ?? [],
				$incoming_element['settings'] ?? []
			);

			if ( is_wp_error( $semantic_check ) ) {
				return $semantic_check;
			}

			if ( self::element_content_changed( $existing_element, $incoming_element ) ) {
				$check = self::check_edit_permissions_for_element( $existing_element );

				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			$settings_check = self::check_settings_permissions_for_element(
				$existing_element,
				$existing_element['settings'] ?? [],
				$incoming_element['settings'] ?? []
			);

			if ( is_wp_error( $settings_check ) ) {
				return $settings_check;
			}

			$class_check = self::check_global_class_assignment_permission( $existing_element, $incoming_element );

			if ( is_wp_error( $class_check ) ) {
				return $class_check;
			}

			$selector_check = self::check_selector_permissions_for_element( $existing_element, $incoming_element );

			if ( is_wp_error( $selector_check ) ) {
				return $selector_check;
			}

			$existing_name = $existing_element['name'] ?? '';
			$incoming_name = $incoming_element['name'] ?? '';

			if ( $existing_name !== $incoming_name ) {
				$check = self::check_add_permissions_for_element( $incoming_element );

				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}

			if (
				! empty( $incoming_element['cid'] ) &&
				( ( $existing_element['cid'] ?? '' ) !== $incoming_element['cid'] )
			) {
				if ( ! \Bricks\Builder_Permissions::user_has_permission( 'insert_components' ) ) {
					return Error::forbidden_builder_permission( 'insert_components' );
				}
			}

			if (
				! empty( $existing_element['cid'] ) &&
				empty( $incoming_element['cid'] ) &&
				! \Bricks\Builder_Permissions::user_has_permission( 'edit_components' )
			) {
				return Error::forbidden_builder_permission( 'edit_components' );
			}

			if (
				! empty( $incoming_element['cid'] ) &&
				(
					self::normalized_compare_value( $existing_element['properties'] ?? [] ) !== self::normalized_compare_value( $incoming_element['properties'] ?? [] ) ||
					self::normalized_compare_value( $existing_element['variant'] ?? '' ) !== self::normalized_compare_value( $incoming_element['variant'] ?? '' )
				) &&
				! \Bricks\Builder_Permissions::user_has_permission( 'set_component_props' )
			) {
				return Error::forbidden_builder_permission( 'set_component_props' );
			}
		}

		return true;
	}

	/**
	 * Index elements by ID.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Elements.
	 * @return array
	 */
	private static function index_elements_by_id( $elements ) {
		$indexed = [];

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( is_string( $id ) && $id !== '' ) {
				$indexed[ $id ] = $element;
			}
		}

		return $indexed;
	}

	/**
	 * Determine whether an element ID exists in a flat element tree.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements Elements.
	 * @param string $id       Element ID.
	 * @return bool
	 */
	private static function element_exists( array $elements, string $id ): bool {
		if ( $id === '' ) {
			return false;
		}

		foreach ( $elements as $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enforce add permissions for a single element.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element data.
	 * @return true|\WP_Error
	 */
	private static function check_add_permissions_for_element( $element ) {
		$element_name = $element['name'] ?? '';

		// Filter elements are not registered while the feature is off, which would
		// otherwise surface as a missing permission and read as a deliberate restriction.
		if ( is_string( $element_name ) && strpos( $element_name, 'filter-' ) === 0 && method_exists( '\Bricks\Helpers', 'enabled_query_filters' ) && ! \Bricks\Helpers::enabled_query_filters() ) {
			return Error::conflict(
				'query_filters_disabled',
				[
					'message' => 'Query Filters are turned off in the Bricks settings, so filter elements cannot be added. Enable the "enableQueryFilters" setting with bricks/set-global-settings, then retry.',
					'setting' => 'enableQueryFilters',
				]
			);
		}

		if ( $element_name && ! \Bricks\Builder_Permissions::user_has_permission( "add_element_{$element_name}" ) ) {
			return Error::forbidden_builder_permission( "add_element_{$element_name}" );
		}

		if ( ! empty( $element['cid'] ) && ! \Bricks\Builder_Permissions::user_has_permission( 'insert_components' ) ) {
			return Error::forbidden_builder_permission( 'insert_components' );
		}

		if (
			! empty( $element['cid'] ) &&
			( ! empty( $element['properties'] ) || ! empty( $element['variant'] ) ) &&
			! \Bricks\Builder_Permissions::user_has_permission( 'set_component_props' )
		) {
			return Error::forbidden_builder_permission( 'set_component_props' );
		}

		if ( ! empty( $element['settings']['_cssGlobalClasses'] ) && ! \Bricks\Builder_Permissions::user_has_permission( 'assign_unassign_global_classes' ) ) {
			return Error::forbidden_builder_permission( 'assign_unassign_global_classes' );
		}

		$semantic_check = self::validate_changed_settings_for_element(
			$element,
			[],
			$element['settings'] ?? []
		);

		if ( is_wp_error( $semantic_check ) ) {
			return $semantic_check;
		}

		$settings_check = self::check_settings_permissions_for_element(
			[ 'name' => $element_name ],
			[],
			$element['settings'] ?? []
		);

		if ( is_wp_error( $settings_check ) ) {
			return $settings_check;
		}

		$selector_check = self::check_selector_permissions_for_element( [], $element );

		if ( is_wp_error( $selector_check ) ) {
			return $selector_check;
		}

		return true;
	}

	/**
	 * Enforce edit permissions for a single existing element.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element data.
	 * @return true|\WP_Error
	 */
	private static function check_edit_permissions_for_element( $element ) {
		$element_name = $element['name'] ?? '';

		if ( $element_name && ! \Bricks\Builder_Permissions::user_has_permission( "edit_element_{$element_name}" ) ) {
			return Error::forbidden_builder_permission( "edit_element_{$element_name}" );
		}

		return true;
	}

	/**
	 * Enforce the dedicated global-class assign/unassign permission.
	 *
	 * The builder UI gates `_cssGlobalClasses` changes behind
	 * `assign_unassign_global_classes`; MCP element writes must do the same.
	 *
	 * @since 2.4
	 *
	 * @param array $existing_element Current saved element.
	 * @param array $incoming_element Replacement element.
	 * @return true|\WP_Error
	 */
	private static function check_global_class_assignment_permission( $existing_element, $incoming_element ) {
		$existing_classes = $existing_element['settings']['_cssGlobalClasses'] ?? [];
		$incoming_classes = $incoming_element['settings']['_cssGlobalClasses'] ?? [];

		if ( self::normalized_compare_value( $existing_classes ) === self::normalized_compare_value( $incoming_classes ) ) {
			return true;
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'assign_unassign_global_classes' ) ) {
			return Error::forbidden_builder_permission( 'assign_unassign_global_classes' );
		}

		return true;
	}

	/**
	 * Enforce selector/style permissions for element custom selectors.
	 *
	 * @since 2.4
	 *
	 * @param array $existing_element Current saved element.
	 * @param array $incoming_element Replacement element.
	 * @return true|\WP_Error
	 */
	private static function check_selector_permissions_for_element( $existing_element, $incoming_element ) {
		$existing_selectors = $existing_element['selectors'] ?? [];
		$incoming_selectors = $incoming_element['selectors'] ?? [];

		if ( self::normalized_compare_value( $existing_selectors ) === self::normalized_compare_value( $incoming_selectors ) ) {
			return true;
		}

		foreach ( [ 'access_pseudo_selectors', 'access_element_styles' ] as $permission ) {
			if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) {
				return Error::forbidden_builder_permission( $permission );
			}
		}

		return true;
	}

	/**
	 * Enforce panel-level permissions for changed settings keys.
	 *
	 * Bricks filters content controls, CSS controls, hide, conditions,
	 * interactions, query loops, and global-class assignment independently in
	 * the builder UI. MCP writes can update arbitrary settings, so they must
	 * reproduce those gates on the changed keys.
	 *
	 * @since 2.4
	 *
	 * @param array $element           Element being changed.
	 * @param array $existing_settings Current settings.
	 * @param array $incoming_settings Replacement settings.
	 * @return true|\WP_Error
	 */
	private static function check_settings_permissions_for_element( $element, $existing_settings, $incoming_settings ) {
		$changed_keys = self::changed_setting_keys( $existing_settings, $incoming_settings );

		if ( empty( $changed_keys ) ) {
			return true;
		}

		$element_name = $element['name'] ?? '';

		foreach ( $changed_keys as $key ) {
			$permission = self::permission_for_setting_key( $element_name, $key );

			if ( ! $permission ) {
				continue;
			}

			if ( ! \Bricks\Builder_Permissions::user_has_permission( $permission ) ) {
				return Error::forbidden_builder_permission( $permission );
			}
		}

		return true;
	}

	/**
	 * Top-level settings keys whose values changed.
	 *
	 * @since 2.4
	 *
	 * @param array $existing_settings Current settings.
	 * @param array $incoming_settings Replacement settings.
	 * @return string[]
	 */
	private static function changed_setting_keys( $existing_settings, $incoming_settings ) {
		$keys = array_unique( array_merge( array_keys( $existing_settings ), array_keys( $incoming_settings ) ) );
		$out  = [];

		foreach ( $keys as $key ) {
			$old = array_key_exists( $key, $existing_settings ) ? $existing_settings[ $key ] : null;
			$new = array_key_exists( $key, $incoming_settings ) ? $incoming_settings[ $key ] : null;

			if ( self::normalized_compare_value( $old ) !== self::normalized_compare_value( $new ) ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * Builder permission required to change a settings key.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @param string $key          Changed settings key.
	 * @return string|null Permission key, or null if another check handles it.
	 */
	private static function permission_for_setting_key( $element_name, $key ) {
		$base_key = self::base_setting_key( $key );

		if ( $base_key === '_cssGlobalClasses' ) {
			return null; // Dedicated assignment check handles this key.
		}

		if ( in_array( $base_key, [ '_hideElementBuilder', '_hideElementFrontend' ], true ) ) {
			return 'access_element_hide';
		}

		if ( $base_key === '_conditions' ) {
			return 'access_element_conditions';
		}

		if ( $base_key === '_interactions' ) {
			return 'access_element_interactions';
		}

		if ( in_array( $base_key, [ 'hasLoop', 'query' ], true ) ) {
			return 'access_query_loop_builder';
		}

		$control = self::get_element_setting_definition( $element_name, $base_key );

		if ( is_array( $control ) ) {
			return ! empty( $control['css'] ) || ( $control['tab'] ?? '' ) === 'style'
				? 'access_element_styles'
				: 'access_element_content';
		}

		return strpos( $base_key, '_' ) === 0 ? 'access_element_styles' : 'access_element_content';
	}

	/**
	 * Strip breakpoint, pseudo, and attribute sub-key suffixes from a setting key.
	 *
	 * @since 2.4
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function base_setting_key( $key ) {
		$parts = preg_split( '/[:|]/', (string) $key, 2 );

		return is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : (string) $key;
	}

	/**
	 * Look up an element setting definition from the shared runtime schema.
	 *
	 * @since 2.4
	 *
	 * @param string $element_name Element name.
	 * @param string $key          Base control key.
	 * @return array|null
	 */
	private static function get_element_setting_definition( $element_name, $key ) {
		$schema = Element_Settings_Schema::get( $element_name );

		return isset( $schema[ $key ] ) && is_array( $schema[ $key ] ) ? $schema[ $key ] : null;
	}

	/**
	 * Enforce move permission when a full-tree write changes parent or order.
	 *
	 * @since 2.4
	 *
	 * @param array $existing       Current saved elements.
	 * @param array $incoming       Normalized replacement elements.
	 * @param array $existing_by_id Current elements indexed by ID.
	 * @param array $incoming_by_id Replacement elements indexed by ID.
	 * @return true|\WP_Error
	 */
	private static function check_full_tree_move_permissions( $existing, $incoming, $existing_by_id, $incoming_by_id ) {
		$structure_changed = false;

		foreach ( $incoming_by_id as $id => $incoming_element ) {
			if ( ! isset( $existing_by_id[ $id ] ) ) {
				continue;
			}

			$existing_parent = self::normalize_parent_id( $existing_by_id[ $id ]['parent'] ?? 0 );
			$incoming_parent = self::normalize_parent_id( $incoming_element['parent'] ?? 0 );

			if ( $existing_parent !== $incoming_parent ) {
				$structure_changed = true;
				break;
			}

			if ( self::normalized_compare_value( $existing_by_id[ $id ]['slotChildren'] ?? [] ) !== self::normalized_compare_value( $incoming_element['slotChildren'] ?? [] ) ) {
				$structure_changed = true;
				break;
			}
		}

		if ( ! $structure_changed ) {
			$existing_order = self::children_order_map( $existing, true );
			$incoming_order = self::children_order_map( $incoming, false );
			$parents        = array_unique( array_merge( array_keys( $existing_order ), array_keys( $incoming_order ) ) );

			foreach ( $parents as $parent ) {
				$old_order = array_values(
					array_filter(
						$existing_order[ $parent ] ?? [],
						function( $id ) use ( $incoming_by_id ) {
							return isset( $incoming_by_id[ $id ] );
						}
					)
				);
				$new_order = array_values(
					array_filter(
						$incoming_order[ $parent ] ?? [],
						function( $id ) use ( $existing_by_id ) {
							return isset( $existing_by_id[ $id ] );
						}
					)
				);

				if ( $old_order !== $new_order ) {
					$structure_changed = true;
					break;
				}
			}
		}

		if ( $structure_changed && ! \Bricks\Builder_Permissions::user_has_permission( 'move_elements' ) ) {
			return Error::forbidden_builder_permission( 'move_elements' );
		}

		return true;
	}

	/**
	 * Build a map of parent ID to ordered child IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $elements             Elements.
	 * @param bool  $prefer_children_prop Whether saved `children` arrays should drive nested order.
	 * @return array
	 */
	private static function children_order_map( $elements, $prefer_children_prop ) {
		$order = [];

		foreach ( $elements as $element ) {
			$parent = self::normalize_parent_id( $element['parent'] ?? 0 );
			$id     = $element['id'] ?? '';

			if ( $id ) {
				if ( ! isset( $order[ $parent ] ) ) {
					$order[ $parent ] = [];
				}

				$order[ $parent ][] = $id;
			}
		}

		if ( ! $prefer_children_prop ) {
			return $order;
		}

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( ! $id || ! isset( $element['children'] ) || ! is_array( $element['children'] ) ) {
				continue;
			}

			$order[ $id ] = array_values(
				array_filter(
					$element['children'],
					function( $child_id ) {
						return is_string( $child_id ) && $child_id !== '';
					}
				)
			);
		}

		return $order;
	}

	/**
	 * Compare element payload excluding structure-only keys.
	 *
	 * @since 2.4
	 *
	 * @param array $existing Current element.
	 * @param array $incoming Replacement element.
	 * @return bool
	 */
	private static function element_content_changed( $existing, $incoming ) {
		$structure_keys = [ 'parent', 'children', 'slotChildren' ];

		foreach ( $structure_keys as $key ) {
			unset( $existing[ $key ], $incoming[ $key ] );
		}

		return self::normalized_compare_value( $existing ) !== self::normalized_compare_value( $incoming );
	}

	/**
	 * Normalize a value for stable semantic comparisons.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function normalized_compare_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::normalized_compare_value( $child );
		}

		if ( ! wp_is_numeric_array( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * Normalize root and string parent IDs to a comparable value.
	 *
	 * @since 2.4
	 *
	 * @param mixed $parent Parent value.
	 * @return string
	 */
	private static function normalize_parent_id( $parent ) {
		return ( $parent === 0 || $parent === '0' || $parent === null || $parent === false ) ? '0' : (string) $parent;
	}

	// ------------------------------------------------------------------
	// bricks/add-element (Phase 6)
	// ------------------------------------------------------------------

	/**
	 * Input schema for add-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function add_element_schema() {
		$properties             = self::post_identifier_properties();
		$properties['element']  = [
			'type'        => 'object',
			'description' => __( 'Element to add. Use {name, settings?, children?} for regular elements, or {cid, properties?, variant?, slotChildren?} for component instances. Element `id` is the internal 6-character Bricks ID used by builder references and the default frontend selector `#brxe-{id}`. In nested format, you may omit `id` and Bricks generates ids while preserving parent-child nesting. In flat format, ids and parent references must be valid 6-character ids.', 'bricks' ),
		];
		$properties['parentId'] = [
			'type'        => 'string',
			'description' => __( 'Parent element ID. Omit or "0" for root level.', 'bricks' ),
		];
		$properties['position'] = [
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'Position among siblings (0-indexed). Omit to append.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'element' ],
		];
	}

	/**
	 * Output schema for add-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function add_element_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementIds' => [ 'type' => 'array' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot. Pass to bricks/restore-revision to roll back.', 'bricks' ),
				],
				'changed'    => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: add element(s)
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function add_element( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$new_el    = $input['element'];
		$parent_id = $input['parentId'] ?? '0';
		$position  = $input['position'] ?? null;

		if ( $position !== null ) {
			$position = (int) $position;

			if ( $position < 0 ) {
				return Error::invalid_param( 'position', 'integer >= 0', $position );
			}
		}

		// Normalize new elements
		$normalizer   = new Element_Normalizer();
		$new_elements = $normalizer->normalize( [ $new_el ] );

		if ( is_wp_error( $new_elements ) ) {
			return $new_elements;
		}

		// Set parent on root elements of the new tree
		foreach ( $new_elements as &$element ) {
			if ( empty( $element['parent'] ) || $element['parent'] === 0 || $element['parent'] === '0' ) {
				$element['parent'] = $parent_id === '0' ? 0 : $parent_id;
			}
		}

		unset( $element );

		// Check per-element add permissions
		foreach ( $new_elements as $element ) {
			$check = self::check_add_permissions_for_element( $element );

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		// Load existing elements
		$area     = self::get_save_area_for_post( $post_id );
		$existing = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$expected_document_digest = Save_Pipeline::document_digest( $existing );

		if ( $parent_id !== '0' && $parent_id !== 0 && ! self::element_exists( $existing, (string) $parent_id ) ) {
			return Error::not_found( 'parent_element', $parent_id );
		}

		// Root order lives in the flat document. Nested order lives in the
		// parent's children array and must not be inferred from flat storage.
		if ( self::normalize_parent_id( $parent_id ) !== '0' ) {
			$existing = self::insert_nested_child_order( $existing, $new_elements, (string) $parent_id, $position );
			$existing = array_merge( $existing, $new_elements );
		} elseif ( $position !== null ) {
			$existing = self::insert_at_position( $existing, $new_elements, $parent_id, $position );
		} else {
			$existing = array_merge( $existing, $new_elements );
		}

		// Collect new element IDs before pipeline (pipeline may modify array)
		$new_ids = array_map(
			function( $el ) {
				return $el['id'];
			},
			$new_elements
		);

		$lock = self::acquire_post_lock( $post_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Save through pipeline
		$result = Save_Pipeline::execute_partial( $post_id, $existing, $area, $expected_document_digest );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementIds' => $new_ids,
			'revisionId' => $result['revisionId'],
			'changed'    => $result['changed'],
		];
	}

	/**
	 * Insert elements at a specific position among siblings
	 *
	 * @since 2.4
	 *
	 * @param array  $existing     Existing flat elements array.
	 * @param array  $new_elements New elements to insert.
	 * @param string $parent_id    Parent element ID.
	 * @param int    $position     Position index among siblings.
	 * @return array Updated elements array.
	 */
	private static function insert_at_position( $existing, $new_elements, $parent_id, $position ) {
		$position = max( 0, (int) $position );

		// Find siblings of target parent
		$normalized_parent = $parent_id === '0' ? 0 : $parent_id;
		$sibling_indices   = [];

		foreach ( $existing as $index => $element ) {
			$el_parent = $element['parent'] ?? 0;

			if ( $el_parent === $normalized_parent || (string) $el_parent === (string) $normalized_parent ) {
				$sibling_indices[] = $index;
			}
		}

		// Determine insertion point in the flat array
		if ( $position >= count( $sibling_indices ) ) {
			$insert_at = $parent_id === '0' || $parent_id === 0
				? count( $existing )
				: self::last_descendant_index( $existing, (string) $parent_id ) + 1;
		} else {
			$insert_at = $sibling_indices[ $position ];
		}

		// Splice new elements into the flat array
		array_splice( $existing, $insert_at, 0, $new_elements );

		return $existing;
	}

	/**
	 * Insert new root IDs into a nested parent's authoritative child order.
	 *
	 * @since 2.4
	 *
	 * @param array    $existing     Existing flat element document.
	 * @param array    $new_elements Normalized new element subtree.
	 * @param string   $parent_id    Existing parent element ID.
	 * @param int|null $position     Requested sibling position, or null to append.
	 * @return array Updated existing document.
	 */
	private static function insert_nested_child_order( $existing, $new_elements, $parent_id, $position ) {
		$parent_index = self::find_element_index( $existing, $parent_id );

		if ( $parent_index === null ) {
			return $existing;
		}

		$children = self::ordered_direct_child_ids( $existing, $parent_id );
		$new_ids  = [];

		foreach ( $new_elements as $element ) {
			if ( self::normalize_parent_id( $element['parent'] ?? 0 ) === $parent_id ) {
				$new_ids[] = $element['id'];
			}
		}

		$insert_at = $position === null ? count( $children ) : min( max( 0, (int) $position ), count( $children ) );
		array_splice( $children, $insert_at, 0, $new_ids );
		$existing[ $parent_index ]['children'] = $children;

		return $existing;
	}

	/**
	 * Return valid direct children in stored order with flat-order fallback.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements  Flat element document.
	 * @param string $parent_id Parent element ID.
	 * @return string[] Ordered child IDs.
	 */
	private static function ordered_direct_child_ids( $elements, $parent_id ) {
		$direct_ids = [];
		$valid      = [];

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( $id && self::normalize_parent_id( $element['parent'] ?? 0 ) === $parent_id ) {
				$direct_ids[] = $id;
				$valid[ $id ] = true;
			}
		}

		$parent_index = self::find_element_index( $elements, $parent_id );
		$declared     = $parent_index === null ? [] : ( $elements[ $parent_index ]['children'] ?? [] );
		$ordered      = [];
		$seen         = [];

		foreach ( is_array( $declared ) ? $declared : [] as $child_id ) {
			if ( is_string( $child_id ) && isset( $valid[ $child_id ] ) && ! isset( $seen[ $child_id ] ) ) {
				$ordered[]         = $child_id;
				$seen[ $child_id ] = true;
			}
		}

		foreach ( $direct_ids as $child_id ) {
			if ( ! isset( $seen[ $child_id ] ) ) {
				$ordered[] = $child_id;
			}
		}

		return $ordered;
	}

	/**
	 * Find the last flat-array index occupied by an element or its descendants.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements  Existing flat elements array.
	 * @param string $anchor_id Element id to inspect.
	 * @return int
	 */
	private static function last_descendant_index( $elements, $anchor_id ) {
		$descendant_ids = [ $anchor_id ];
		$anchor_index   = -1;

		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) === $anchor_id ) {
				$anchor_index = $index;
				break;
			}
		}

		$changed = true;

		while ( $changed ) {
			$changed = false;

			foreach ( $elements as $element ) {
				$id     = $element['id'] ?? '';
				$parent = $element['parent'] ?? 0;

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

		foreach ( $elements as $index => $element ) {
			if ( in_array( $element['id'] ?? '', $descendant_ids, true ) ) {
				$last_index = max( $last_index, $index );
			}
		}

		return $last_index;
	}

	// ------------------------------------------------------------------
	// bricks/update-element (Phase 6)
	// ------------------------------------------------------------------

	/**
	 * Input schema for update-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_schema() {
		$properties                  = self::post_identifier_properties();
		$properties['elementId']     = [
			'type'        => 'string',
			'description' => __( 'The element ID to update.', 'bricks' ),
		];
		$properties['settings']      = [
			'type'        => 'object',
			'description' => __( 'Settings to merge. Partial: only the keys you provide are updated. Pass `null` or `""` for a key to delete it (matches the builder, where clearing a control removes the key).', 'bricks' ),
		];
		$properties['dryRun']        = [
			'type'        => 'boolean',
			'description' => __( 'Validate and normalize the update without saving or creating a revision. Defaults to false.', 'bricks' ),
		];
		$properties['returnElement'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether to return the updated element in the response. Defaults to true for backward compatibility.', 'bricks' ),
		];
		$properties['returnFields']  = [
			'type'        => 'array',
			'description' => __( 'Optional list of fields to return on the updated element. `id` is always included. Ignored when returnElement is false.', 'bricks' ),
			'items'       => [
				'type' => 'string',
				'enum' => self::element_return_fields(),
			],
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'settings' ],
		];
	}

	/**
	 * Output schema for update-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_element_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'element'       => [ 'type' => 'object' ],
				'revisionId'    => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot. Pass to bricks/restore-revision to roll back.', 'bricks' ),
				],
				'dryRun'        => [ 'type' => 'boolean' ],
				'valid'         => [ 'type' => 'boolean' ],
				'changed'       => [ 'type' => 'boolean' ],
				'normalization' => [
					'type'        => 'object',
					'description' => __( 'Dry-run summary of how the save pipeline normalized the requested element.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Apply a partial settings update to an in-memory element tree.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements   Element tree, mutated in place.
	 * @param string $element_id Element ID to update.
	 * @param array  $settings   Settings to merge.
	 * @return array|\WP_Error Update summary or error.
	 */
	private static function apply_element_settings_update( array &$elements, $element_id, $settings ) {
		if ( ! is_array( $settings ) ) {
			return Error::invalid_param( 'settings', 'object', $settings );
		}

		$found_index = self::find_element_index( $elements, $element_id );

		if ( $found_index === null ) {
			return Error::not_found( 'element', $element_id );
		}

		$check = self::check_edit_permissions_for_element( $elements[ $found_index ] );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$before_settings                      = $elements[ $found_index ]['settings'] ?? [];
		$elements[ $found_index ]['settings'] = self::deep_merge( $before_settings, $settings );
		$after_element                        = $elements[ $found_index ];

		$settings_validation = self::validate_changed_settings_for_element(
			$after_element,
			$before_settings,
			$after_element['settings'] ?? []
		);

		if ( is_wp_error( $settings_validation ) ) {
			return $settings_validation;
		}

		$settings_check = self::check_settings_permissions_for_element(
			$after_element,
			$before_settings,
			$after_element['settings'] ?? []
		);

		if ( is_wp_error( $settings_check ) ) {
			return $settings_check;
		}

		$class_check = self::check_global_class_assignment_permission(
			[ 'settings' => $before_settings ],
			[ 'settings' => $after_element['settings'] ?? [] ]
		);

		if ( is_wp_error( $class_check ) ) {
			return $class_check;
		}

		return [
			'elementId' => $element_id,
			'index'     => $found_index,
			'before'    => $before_settings,
			'after'     => $after_element,
		];
	}

	/**
	 * Validate only changed setting keys against active element controls.
	 *
	 * Existing opaque keys remain editable for legacy, component, and plugin
	 * compatibility. Newly introduced keys must be a runtime control or a
	 * virtual setting implemented outside the control registry.
	 *
	 * @since 2.4
	 *
	 * @param array $element           Element being changed.
	 * @param array $existing_settings Current settings.
	 * @param array $incoming_settings Replacement settings.
	 * @return true|\WP_Error Validation result.
	 */
	private static function validate_changed_settings_for_element( $element, $existing_settings, $incoming_settings ) {
		$element_name = $element['name'] ?? '';

		foreach ( self::changed_setting_keys( $existing_settings, $incoming_settings ) as $key ) {
			if ( ! array_key_exists( $key, $existing_settings ) ) {
				// The save pipeline adds signing metadata before workspace permission checks.
				// Accept only the authorized signer's values, without exposing new controls.
				if ( $element_name === 'code' && in_array( $key, [ 'signature', 'user_id' ], true ) && isset( $incoming_settings['code'] ) && is_string( $incoming_settings['code'] ) && self::can_author_php() ) {
					$signed_settings = self::sign_php_settings( $incoming_settings );

					if ( ( $incoming_settings[ $key ] ?? null ) === $signed_settings[ $key ] ) {
						continue;
					}
				}

				$validation = Style_Settings_Schema::validate_element_key( $element_name, $key );

				if ( is_wp_error( $validation ) ) {
					return $validation;
				}
			}
		}

		if ( $element_name === 'image' && array_key_exists( 'image', $incoming_settings ) ) {
			$image = $incoming_settings['image'];

			if ( is_array( $image ) && ! empty( $image['external'] ) && empty( $image['url'] ) ) {
				return Error::invalid_param(
					'settings.image.url',
					'a non-empty URL when settings.image.external is true',
					$image['url'] ?? null
				);
			}
		}

		return true;
	}

	/**
	 * Find an element index by ID.
	 *
	 * @since 2.4
	 *
	 * @param array  $elements   Element tree.
	 * @param string $element_id Element ID.
	 * @return int|null Element index or null when not found.
	 */
	private static function find_element_index( array $elements, $element_id ) {
		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Summarize save-pipeline normalization for an updated element.
	 *
	 * @since 2.4
	 *
	 * @param array $proposed   Element before the save pipeline.
	 * @param array $normalized Element after the save pipeline.
	 * @return array Normalization summary.
	 */
	private static function element_normalization_summary( array $proposed, array $normalized ) {
		$changed_fields = [];
		$keys           = array_unique( array_merge( array_keys( $proposed ), array_keys( $normalized ) ) );

		foreach ( $keys as $key ) {
			$before = array_key_exists( $key, $proposed ) ? $proposed[ $key ] : null;
			$after  = array_key_exists( $key, $normalized ) ? $normalized[ $key ] : null;

			if ( self::normalized_compare_value( $before ) !== self::normalized_compare_value( $after ) ) {
				$changed_fields[] = (string) $key;
			}
		}

		$changed_settings = self::changed_setting_keys(
			$proposed['settings'] ?? [],
			$normalized['settings'] ?? []
		);

		return [
			'changed'             => ! empty( $changed_fields ) || ! empty( $changed_settings ),
			'changedFields'       => $changed_fields,
			'changedSettingsKeys' => $changed_settings,
		];
	}

	/**
	 * Callback: partial-merge update element settings
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_element( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$element_id = $input['elementId'];
		$settings   = $input['settings'];
		$area       = self::get_save_area_for_post( $post_id );
		$elements   = \Bricks\Database::get_data( $post_id, $area );
		$dry_run    = ! empty( $input['dryRun'] );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( 'element', $element_id );
		}

		$expected_document_digest = Save_Pipeline::document_digest( $elements );

		$update = self::apply_element_settings_update( $elements, $element_id, $settings );
		if ( is_wp_error( $update ) ) {
			return $update;
		}

		if ( ! array_key_exists( 'returnElement', $input ) || $input['returnElement'] ) {
			$return_fields = self::optional_return_fields( $input );
			if ( is_wp_error( $return_fields ) ) {
				return $return_fields;
			}
		}

		if ( ! $dry_run ) {
			$lock = self::acquire_post_lock( $post_id );

			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
		}

		$result = $dry_run
			? Save_Pipeline::dry_run_partial( $post_id, $elements, $area )
			: Save_Pipeline::execute_partial( $post_id, $elements, $area, $expected_document_digest );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved       = $result['elements'];
		$revision_id = $dry_run ? null : $result['revisionId'];
		$response    = [
			'revisionId' => $revision_id,
			'dryRun'     => $dry_run,
			'valid'      => true,
			'changed'    => $dry_run ? false : $result['changed'],
		];

		foreach ( $saved as $element ) {
			if ( ( $element['id'] ?? '' ) === $element_id ) {
				if ( $dry_run ) {
					$response['normalization'] = self::element_normalization_summary( $update['after'], $element );
				}

				if ( ! array_key_exists( 'returnElement', $input ) || $input['returnElement'] ) {
					$formatted = self::format_elements_for_response( [ $element ], $input );

					if ( is_wp_error( $formatted ) ) {
						return $formatted;
					}

					$response['element'] = $formatted[0];
				}

				return $response;
			}
		}

		return Error::not_found( 'element', $element_id );
	}

	// ------------------------------------------------------------------
	// bricks/batch-update-elements
	// ------------------------------------------------------------------

	/**
	 * Input schema for batch-update-elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function batch_update_elements_schema() {
		$properties                   = self::post_identifier_properties();
		$properties['updates']        = [
			'type'        => 'array',
			'description' => __( 'Element setting patches to apply in order. Each item requires elementId and settings.', 'bricks' ),
			'items'       => [
				'type'       => 'object',
				'properties' => [
					'elementId' => [
						'type'        => 'string',
						'description' => __( 'Element ID to update.', 'bricks' ),
					],
					'settings'  => [
						'type'        => 'object',
						'description' => __( 'Settings to merge into the element. Pass `null` or `""` for a key to delete it.', 'bricks' ),
					],
				],
				'required'   => [ 'elementId', 'settings' ],
			],
		];
		$properties['dryRun']         = [
			'type'        => 'boolean',
			'description' => __( 'Validate and normalize all patches without saving or creating a revision. Defaults to false.', 'bricks' ),
		];
		$properties['returnElements'] = [
			'type'        => 'boolean',
			'description' => __( 'Whether to return updated elements. Defaults to false to keep batch responses compact.', 'bricks' ),
		];
		$properties['returnFields']   = [
			'type'        => 'array',
			'description' => __( 'Optional list of fields to return on updated elements when returnElements is true. `id` is always included.', 'bricks' ),
			'items'       => [
				'type' => 'string',
				'enum' => self::element_return_fields(),
			],
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'updates' ],
		];
	}

	/**
	 * Output schema for batch-update-elements.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function batch_update_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'updatedElementIds' => [ 'type' => 'array' ],
				'revisionId'        => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot. Null for dry runs.', 'bricks' ),
				],
				'dryRun'            => [ 'type' => 'boolean' ],
				'valid'             => [ 'type' => 'boolean' ],
				'changed'           => [ 'type' => 'boolean' ],
				'elements'          => [ 'type' => 'array' ],
				'normalization'     => [
					'type'        => 'object',
					'description' => __( 'Dry-run normalization summary keyed by element ID.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: partial-merge update settings on multiple elements in one save.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function batch_update_elements( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$updates = $input['updates'] ?? null;

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return Error::invalid_param( 'updates', 'non-empty array of element update objects', $updates );
		}

		if ( ! empty( $input['returnElements'] ) ) {
			$return_fields = self::optional_return_fields( $input );
			if ( is_wp_error( $return_fields ) ) {
				return $return_fields;
			}
		}

		Manager::flush_post_cache( $post_id );
		$area     = self::get_save_area_for_post( $post_id );
		$elements = \Bricks\Database::get_data( $post_id, $area );
		$dry_run  = ! empty( $input['dryRun'] );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( 'elements', $post_id );
		}

		$expected_document_digest = Save_Pipeline::document_digest( $elements );

		$updated_ids = [];
		$proposed    = [];

		foreach ( $updates as $index => $update ) {
			if ( ! is_array( $update ) ) {
				return Error::invalid_param( "updates[{$index}]", 'element update object', $update );
			}

			$element_id = $update['elementId'] ?? '';
			if ( ! is_string( $element_id ) || $element_id === '' ) {
				return Error::invalid_param( "updates[{$index}].elementId", 'non-empty string', $element_id );
			}

			if ( ! array_key_exists( 'settings', $update ) ) {
				return Error::invalid_param( "updates[{$index}].settings", 'object', null );
			}

			$result = self::apply_element_settings_update( $elements, $element_id, $update['settings'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_ids[]           = $element_id;
			$proposed[ $element_id ] = $result['after'];
		}

		$updated_ids = array_values( array_unique( $updated_ids ) );

		if ( ! $dry_run ) {
			$lock = self::acquire_post_lock( $post_id );

			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
		}

		$result = $dry_run
			? Save_Pipeline::dry_run_partial( $post_id, $elements, $area )
			: Save_Pipeline::execute_partial( $post_id, $elements, $area, $expected_document_digest );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved       = $result['elements'];
		$saved_by_id = self::index_elements_by_id( $saved );
		$response    = [
			'updatedElementIds' => $updated_ids,
			'revisionId'        => $dry_run ? null : $result['revisionId'],
			'dryRun'            => $dry_run,
			'valid'             => true,
			'changed'           => $dry_run ? false : $result['changed'],
		];

		if ( $dry_run ) {
			$response['normalization'] = [];

			foreach ( $updated_ids as $element_id ) {
				if ( isset( $saved_by_id[ $element_id ], $proposed[ $element_id ] ) ) {
					$response['normalization'][ $element_id ] = self::element_normalization_summary( $proposed[ $element_id ], $saved_by_id[ $element_id ] );
				}
			}
		}

		if ( ! empty( $input['returnElements'] ) ) {
			$elements_out = [];

			foreach ( $updated_ids as $element_id ) {
				if ( isset( $saved_by_id[ $element_id ] ) ) {
					$elements_out[] = $saved_by_id[ $element_id ];
				}
			}

			$formatted = self::format_elements_for_response( $elements_out, $input );
			if ( is_wp_error( $formatted ) ) {
				return $formatted;
			}

			$response['elements'] = $formatted;
		}

		return $response;
	}

	/**
	 * Deep merge two arrays (second overwrites first).
	 *
	 * Mirrors the Bricks builder's per-control save semantics
	 * (PanelControl.vue:972 and store/actions/elements.js:4255-4318):
	 * an overlay value of `null` or `''` deletes the key, and a nested
	 * associative array that becomes empty after merge is also dropped.
	 * 0, false, and empty numeric arrays are preserved (legitimate values).
	 *
	 * @since 2.4
	 *
	 * @param array $base    Base array.
	 * @param array $overlay Overlay array.
	 * @return array Merged array.
	 */
	public static function deep_merge( $base, $overlay ) {
		foreach ( $overlay as $key => $value ) {
			// Sentinel deletion: null or '' unsets the key.
			if ( $value === null || $value === '' ) {
				unset( $base[ $key ] );
				continue;
			}

			// Recursive deep-merge for nested associative arrays.
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! wp_is_numeric_array( $value ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );

				if ( empty( $base[ $key ] ) ) {
					unset( $base[ $key ] );
				}
				continue;
			}

			// Overlay introduces a new associative array: prune any null/'' inside it
			// so we never store a freshly-stowed empty subtree.
			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
				$value = self::prune_empty_setting_values( $value );

				if ( empty( $value ) ) {
					unset( $base[ $key ] );
					continue;
				}
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Recursively strip null/'' values and empty nested objects from a settings array.
	 *
	 * Used by the save pipeline so full-tree writes (set-page-elements, add-element,
	 * template insert, import-export) match the partial-merge semantics in deep_merge.
	 * Conservative: 0, false, and numeric arrays (lists) are left untouched.
	 *
	 * @since 2.4
	 *
	 * @param mixed $settings Settings value (typically an array).
	 * @return mixed Pruned settings.
	 */
	public static function prune_empty_setting_values( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		foreach ( $settings as $key => $value ) {
			if ( $value === null || $value === '' ) {
				unset( $settings[ $key ] );
				continue;
			}

			if ( is_array( $value ) && ! wp_is_numeric_array( $value ) ) {
				$pruned = self::prune_empty_setting_values( $value );

				if ( empty( $pruned ) ) {
					unset( $settings[ $key ] );
				} else {
					$settings[ $key ] = $pruned;
				}
			}
		}

		return $settings;
	}

	// ------------------------------------------------------------------
	// bricks/remove-element (Phase 6)
	// ------------------------------------------------------------------

	/**
	 * Input schema for remove-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function remove_element_schema() {
		$properties                   = self::post_identifier_properties();
		$properties['elementId']      = [
			'type'        => 'string',
			'description' => __( 'The element ID to remove.', 'bricks' ),
		];
		$properties['removeChildren'] = [
			'type'        => 'boolean',
			'description' => __( 'Also remove child elements (default: true).', 'bricks' ),
			'default'     => true,
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for remove-element
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function remove_element_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'removedIds' => [ 'type' => 'array' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot. Pass to bricks/restore-revision to roll back.', 'bricks' ),
				],
				'changed'    => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: remove element and optionally its children
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function remove_element( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$element_id      = $input['elementId'];
		$remove_children = $input['removeChildren'] ?? true;
		$area            = self::get_save_area_for_post( $post_id );
		$elements        = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( 'element', $element_id );
		}

		$expected_document_digest = Save_Pipeline::document_digest( $elements );

		// Collect IDs to remove
		$remove_ids = [ $element_id => true ];

		if ( $remove_children ) {
			$changed = true;

			while ( $changed ) {
				$changed = false;

				foreach ( $elements as $element ) {
					$id     = $element['id'] ?? '';
					$parent = $element['parent'] ?? 0;

					if ( $id && ! isset( $remove_ids[ $id ] ) && isset( $remove_ids[ (string) $parent ] ) ) {
						$remove_ids[ $id ] = true;
						$changed           = true;
					}
				}
			}
		}

		$target_index = self::find_element_index( $elements, $element_id );

		if ( $target_index === null ) {
			return Error::not_found( 'element', $element_id );
		}

		if ( ! $remove_children ) {
			$target_parent = self::normalize_parent_id( $elements[ $target_index ]['parent'] ?? 0 );
			$child_ids     = self::ordered_direct_child_ids( $elements, $element_id );
			$child_id_map  = array_fill_keys( $child_ids, true );

			foreach ( $elements as &$element ) {
				if ( isset( $child_id_map[ $element['id'] ?? '' ] ) ) {
					$element['parent'] = $target_parent === '0' ? 0 : $target_parent;
				}
			}
			unset( $element );

			if ( $target_parent !== '0' ) {
				$parent_index = self::find_element_index( $elements, $target_parent );

				if ( $parent_index !== null ) {
					$siblings = self::ordered_direct_child_ids( $elements, $target_parent );

					$target_offset = array_search( $element_id, $siblings, true );
					$target_offset = $target_offset === false ? count( $siblings ) : $target_offset;

					$removed_sibling_ids = array_fill_keys( array_merge( [ $element_id ], $child_ids ), true );
					$siblings            = array_values(
						array_filter(
							$siblings,
							function ( $sibling_id ) use ( $removed_sibling_ids ) {
								return ! isset( $removed_sibling_ids[ $sibling_id ] );
							}
						)
					);
					array_splice( $siblings, $target_offset, 0, $child_ids );
					$elements[ $parent_index ]['children'] = $siblings;
				}
			} elseif ( ! empty( $child_ids ) ) {
				$elements = self::promote_children_to_root_position( $elements, $element_id, $child_ids );
			}
		}

		// Remove elements
		$elements = array_values(
			array_filter(
				$elements,
				function( $el ) use ( $remove_ids ) {
					return ! isset( $remove_ids[ $el['id'] ?? '' ] );
				}
			)
		);

		$lock = self::acquire_post_lock( $post_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Save through pipeline
		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area, $expected_document_digest );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'removedIds' => array_keys( $remove_ids ),
			'revisionId' => $result['revisionId'],
			'changed'    => $result['changed'],
		];
	}

	/**
	 * Move promoted child rows into the removed root's flat-order position.
	 *
	 * Root sibling order has no parent children array, so the flat document is
	 * authoritative at that level.
	 *
	 * @since 2.4
	 *
	 * @param array    $elements   Flat element document.
	 * @param string   $removed_id Root element being removed.
	 * @param string[] $child_ids  Direct children promoted to roots.
	 * @return array Reordered document.
	 */
	private static function promote_children_to_root_position( $elements, $removed_id, $child_ids ) {
		$child_map = array_fill_keys( $child_ids, true );
		$promoted  = [];
		$remaining = [];
		$insert_at = 0;
		$seen_root = false;

		foreach ( $elements as $element ) {
			$id = $element['id'] ?? '';

			if ( isset( $child_map[ $id ] ) ) {
				$promoted[ $id ] = $element;
				continue;
			}

			if ( $id === $removed_id ) {
				$insert_at = count( $remaining );
				$seen_root = true;
				continue;
			}

			$remaining[] = $element;
		}

		if ( ! $seen_root ) {
			return $elements;
		}

		$ordered = [];
		foreach ( $child_ids as $child_id ) {
			if ( isset( $promoted[ $child_id ] ) ) {
				$ordered[] = $promoted[ $child_id ];
			}
		}

		array_splice( $remaining, $insert_at, 0, $ordered );

		return $remaining;
	}

	// ------------------------------------------------------------------
	// bricks/set-page-settings (Phase 6)
	// ------------------------------------------------------------------

	/**
	 * Input schema for set-page-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_page_settings_schema() {
		$properties             = self::post_identifier_properties();
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
	 * Output schema for set-page-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function set_page_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'settings' => [ 'type' => 'object' ],
				'postId'   => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Callback: set page settings via partial merge
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function set_page_settings( $input ) {
		$post_id = self::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		Manager::flush_post_cache( $post_id );
		$new_settings = $input['settings'];
		$existing     = get_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, true );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Deep merge settings
		$settings = self::deep_merge( $existing, $new_settings );

		foreach ( [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ] as $script_key ) {
			if ( array_key_exists( $script_key, $new_settings ) && ( $existing[ $script_key ] ?? null ) !== $new_settings[ $script_key ] ) {
				return Error::code_sensitive_write_forbidden();
			}
		}

		// Strip custom script keys for users without unfiltered_html
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$script_keys = [ 'customScriptsHeader', 'customScriptsBodyHeader', 'customScriptsBodyFooter' ];

			foreach ( $script_keys as $key ) {
				if ( isset( $new_settings[ $key ] ) ) {
					// Preserve existing value, don't allow update
					if ( isset( $existing[ $key ] ) ) {
						$settings[ $key ] = $existing[ $key ];
					} else {
						unset( $settings[ $key ] );
					}
				}
			}
		}

		$lock = self::acquire_post_lock( $post_id );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		// Update post title and slug if provided (mirrors Ajax::save_post behavior)
		if ( isset( $new_settings['postTitle'] ) || isset( $new_settings['postName'] ) ) {
			$post_data = [ 'ID' => $post_id ];

			if ( isset( $new_settings['postTitle'] ) ) {
				$post_data['post_title'] = sanitize_text_field( $new_settings['postTitle'] );
			}

			if ( isset( $new_settings['postName'] ) ) {
				$post_data['post_name'] = sanitize_title( $new_settings['postName'] );
			}

			wp_update_post( $post_data );
		}

		// Save page settings
		update_post_meta( $post_id, BRICKS_DB_PAGE_SETTINGS, $settings );

		return [
			'settings' => $settings,
			'postId'   => $post_id,
		];
	}

	// ------------------------------------------------------------------
	// bricks/find-post
	// ------------------------------------------------------------------

	/**
	 * Input schema for find-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function find_post_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'query'      => [
					'type'        => 'string',
					'description' => __( 'Slug/title fragment for text search. Omit to list most recently modified posts. Mutually exclusive with postId/slug/path.', 'bricks' ),
				],
				'postId'     => [
					'type'        => 'integer',
					'description' => __( 'Direct lookup by post ID. Returns a single-result list. Mutually exclusive with query/slug/path.', 'bricks' ),
				],
				'slug'       => [
					'type'        => 'string',
					'description' => __( 'Direct lookup by exact post_name. Returns posts matching the slug across allowed post types. Mutually exclusive with query/postId/path.', 'bricks' ),
				],
				'path'       => [
					'type'        => 'string',
					'description' => __( 'Direct lookup by hierarchical path (e.g. "parent/child"). Mutually exclusive with query/postId/slug.', 'bricks' ),
				],
				'postType'   => [
					'type'        => 'string',
					'description' => __( 'Filter to a single post type. Omit to search all editable types.', 'bricks' ),
				],
				'status'     => [
					'type'        => 'string',
					'description' => __( 'draft | publish | pending | private | future | any. Default: any.', 'bricks' ),
				],
				'bricksOnly' => [
					'type'        => 'boolean',
					'description' => __( 'Restrict to Bricks-enabled post types + template CPT. Default: false (so non-Bricks posts are surfaced with bricksEnabled: false).', 'bricks' ),
				],
				'limit'      => [
					'type'        => 'integer',
					'description' => __( 'Max results, 1..50. Default 10.', 'bricks' ),
				],
				'orderBy'    => [
					'type'        => 'string',
					'description' => __( 'modified | title | relevance. Default modified.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for find-post
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function find_post_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'results' => [
					'type'        => 'array',
					'description' => __( 'Matched posts with edit/builder URLs and Bricks metadata.', 'bricks' ),
				],
				'total'   => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Permission: find-post
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function find_post_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	/**
	 * Callback: search for posts/pages/templates by slug, title, or path fragment.
	 *
	 * Returns posts across all post types the user can edit by default, so the
	 * caller can see entries that are not Bricks-enabled and understand why
	 * they're off-limits (bricksEnabled: false + a null builderUrl).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function find_post( $input ) {
		$query       = isset( $input['query'] ) ? (string) $input['query'] : '';
		$status      = $input['status'] ?? 'any';
		$bricks_only = ! empty( $input['bricksOnly'] );
		$limit       = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit       = max( 1, min( 50, $limit ) );
		$order_by    = $input['orderBy'] ?? 'modified';
		$explicit_pt = $input['postType'] ?? null;

		// Direct-lookup identifiers. Mutually exclusive with each other and with `query`.
		$direct_identifiers = [];

		if ( isset( $input['postId'] ) && $input['postId'] !== '' && $input['postId'] !== 0 ) {
			$direct_identifiers[] = 'postId';
		}

		if ( isset( $input['slug'] ) && $input['slug'] !== '' ) {
			$direct_identifiers[] = 'slug';
		}

		if ( isset( $input['path'] ) && $input['path'] !== '' ) {
			$direct_identifiers[] = 'path';
		}

		if ( $query !== '' && ! empty( $direct_identifiers ) ) {
			$direct_identifiers[] = 'query';
		}

		if ( count( $direct_identifiers ) > 1 ) {
			return Error::invalid_param(
				'identifier',
				'exactly one of postId, slug, path, or query',
				$direct_identifiers
			);
		}

		$post_types = $bricks_only
			? Input_Resolver::default_allow_list()
			: Input_Resolver::searchable_post_types();

		if ( $explicit_pt ) {
			$post_types = [ $explicit_pt ];
		}

		// Direct lookup path. Builds the result list from a single direct
		// identifier and returns early so the WP_Query text-search branch
		// is skipped entirely.
		if ( ! empty( $direct_identifiers ) && $direct_identifiers[0] !== 'query' ) {
			$direct_posts = [];

			if ( $direct_identifiers[0] === 'postId' ) {
				$direct_post = get_post( (int) $input['postId'] );

				if ( $direct_post instanceof \WP_Post && in_array( $direct_post->post_type, (array) $post_types, true ) ) {
					$direct_posts[] = $direct_post;
				}
			} elseif ( $direct_identifiers[0] === 'slug' ) {
				$slug_posts = get_posts(
					[
						'post_type'              => $post_types,
						'post_status'            => $status === 'any'
							? [ 'publish', 'draft', 'pending', 'private', 'future' ]
							: $status,
						'name'                   => sanitize_title( (string) $input['slug'] ),
						'posts_per_page'         => $limit,
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
					]
				);

				if ( is_array( $slug_posts ) ) {
					$direct_posts = $slug_posts;
				}
			} elseif ( $direct_identifiers[0] === 'path' ) {
				$path_input = ltrim( (string) $input['path'], '/' );
				$pt_list    = (array) $post_types;

				foreach ( $pt_list as $pt ) {
					$found = get_page_by_path( $path_input, OBJECT, $pt );

					if ( $found instanceof \WP_Post ) {
						$direct_posts[] = $found;
						break;
					}
				}
			}

			return self::find_post_format_results( $direct_posts );
		}

		$args = [
			'post_type'              => $post_types,
			'post_status'            => $status === 'any'
				? [ 'publish', 'draft', 'pending', 'private', 'future' ]
				: $status,
			'posts_per_page'         => $limit,
			'update_post_term_cache' => false,
			'no_found_rows'          => false,
		];

		switch ( $order_by ) {
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'relevance':
				break;
			case 'modified':
			default:
				$args['orderby'] = 'modified';
				$args['order']   = 'DESC';
				break;
		}

		if ( $query !== '' ) {
			global $wpdb;

			$search_like   = '%' . $wpdb->esc_like( $query ) . '%';
			$search_tokens = self::find_post_search_tokens( $query );

			$where_filter = static function ( $where ) use ( $wpdb, $search_like, $search_tokens ) {
				$exact_clause = $wpdb->prepare(
					"(
						{$wpdb->posts}.post_title LIKE %s
						OR {$wpdb->posts}.post_name LIKE %s
						OR {$wpdb->posts}.post_content LIKE %s
						OR {$wpdb->posts}.post_excerpt LIKE %s
					)",
					$search_like,
					$search_like,
					$search_like,
					$search_like
				);

				if ( empty( $search_tokens ) ) {
					$where .= " AND {$exact_clause}";
					return $where;
				}

				$token_clauses = [];

				foreach ( $search_tokens as $token ) {
					$token_like      = '%' . $wpdb->esc_like( $token ) . '%';
					$token_clauses[] = $wpdb->prepare(
						"(
							{$wpdb->posts}.post_title LIKE %s
							OR {$wpdb->posts}.post_name LIKE %s
							OR {$wpdb->posts}.post_content LIKE %s
							OR {$wpdb->posts}.post_excerpt LIKE %s
						)",
						$token_like,
						$token_like,
						$token_like,
						$token_like
					);
				}

				$where .= ' AND (' . $exact_clause . ' OR (' . implode( ' AND ', $token_clauses ) . '))';

				return $where;
			};

			add_filter( 'posts_where', $where_filter );

			if ( $order_by === 'relevance' ) {
				$exact = $query;

				$orderby_filter = static function ( $orderby ) use ( $wpdb, $exact, $search_like ) {
					return $wpdb->prepare(
						"CASE
							WHEN {$wpdb->posts}.post_name = %s THEN 0
							WHEN {$wpdb->posts}.post_title = %s THEN 1
							WHEN {$wpdb->posts}.post_name LIKE %s THEN 2
							WHEN {$wpdb->posts}.post_title LIKE %s THEN 3
							ELSE 4
						END, {$wpdb->posts}.post_modified DESC",
						$exact,
						$exact,
						$search_like,
						$search_like
					);
				};

				add_filter( 'posts_orderby', $orderby_filter );
			}
		}

		$wp_query = new \WP_Query( $args );

		if ( $query !== '' ) {
			remove_filter( 'posts_where', $where_filter );

			if ( isset( $orderby_filter ) ) {
				remove_filter( 'posts_orderby', $orderby_filter );
			}
		}

		return self::find_post_format_results( $wp_query->posts );
	}

	/**
	 * Format an array of WP_Post objects into the find-post response shape.
	 *
	 * Centralizes the per-post cap gate, Bricks-enablement decoration, and
	 * lock-state surfacing so both the text-search and direct-lookup paths
	 * return the same response envelope.
	 *
	 * @since 2.4
	 *
	 * @param array $posts Array of WP_Post objects.
	 * @return array { results, total } response envelope.
	 */
	private static function find_post_format_results( array $posts ): array {
		$bricks_allowed = Input_Resolver::default_allow_list();
		$results        = [];

		Manager::ensure_post_admin_loaded();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			// Per-post cap gate.
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$post_type_enabled = in_array( $post->post_type, $bricks_allowed, true );
			$area              = self::get_save_area_for_post( $post->ID );
			$has_bricks_data   = ! empty( get_post_meta( $post->ID, \Bricks\Database::get_bricks_data_key( $area ), true ) );
			$locked_by         = wp_check_post_lock( $post->ID );

			$results[] = [
				'id'            => (int) $post->ID,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'path'          => self::build_post_path( $post ),
				'postType'      => $post->post_type,
				'status'        => $post->post_status,
				'bricksEnabled' => $post_type_enabled,
				'hasBricksData' => $has_bricks_data,
				'locked'        => (bool) $locked_by,
				'lockedBy'      => $locked_by ? (int) $locked_by : null,
				'editUrl'       => get_edit_post_link( $post->ID, '' ),
				'builderUrl'    => $post_type_enabled
					? add_query_arg( 'bricks', 'run', get_permalink( $post->ID ) )
					: null,
				'modifiedGmt'   => $post->post_modified_gmt,
			];
		}

		return [
			'results' => $results,
			'total'   => count( $results ),
		];
	}

	/**
	 * Build a hierarchical path string for a post (parent/child/...).
	 *
	 * @since 2.4
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private static function build_post_path( \WP_Post $post ): string {
		if ( ! is_post_type_hierarchical( $post->post_type ) || ! $post->post_parent ) {
			return $post->post_name;
		}

		$segments = [ $post->post_name ];
		$parent   = get_post( $post->post_parent );
		$guard    = 0;

		while ( $parent instanceof \WP_Post && $guard++ < 10 ) {
			array_unshift( $segments, $parent->post_name );

			if ( ! $parent->post_parent ) {
				break;
			}

			$parent = get_post( $parent->post_parent );
		}

		return implode( '/', $segments );
	}

	/**
	 * Split a human search query into conservative fallback tokens.
	 *
	 * WordPress title fragments often include generated prefixes/suffixes
	 * between otherwise meaningful words. Keep exact LIKE search first, then
	 * use these tokens as an AND fallback so `MCP QA - 20260506-120136` can
	 * match `MCP QA - Page - 20260506-120136`.
	 *
	 * @since 2.4
	 *
	 * @param string $query Search query.
	 * @return string[]
	 */
	private static function find_post_search_tokens( string $query ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return [];
		}

		$tokens = [];

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( strlen( $part ) < 2 ) {
				continue;
			}

			$tokens[ strtolower( $part ) ] = $part;

			if ( count( $tokens ) >= 8 ) {
				break;
			}
		}

		return array_values( $tokens );
	}
}

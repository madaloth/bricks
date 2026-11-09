<?php
/**
 * Navigation menu abilities
 *
 * Compact WordPress nav-menu CRUD for Bricks site-building workflows. These
 * wrap WordPress menus plus the Bricks menu-item metadata used by the Nav Menu
 * element for template-backed mega menus and multilevel items.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Navigation {
	const META_MEGA_MENU_TEMPLATE_ID = '_bricks_mega_menu_template_id';
	const META_MULTILEVEL            = '_bricks_multilevel';

	// ==================================================================
	// Schemas
	// ==================================================================

	/**
	 * Get input schema for listing WordPress navigation menus.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_nav_menus_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public static function list_nav_menus_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'menus'               => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'total'               => [ 'type' => 'integer' ],
				'registeredLocations' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
		];
	}

	public static function get_nav_menu_schema() {
		return [
			'type'       => 'object',
			'properties' => self::menu_identifier_properties(),
		];
	}

	public static function get_nav_menu_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'menu'                => [ 'type' => 'object' ],
				'registeredLocations' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
		];
	}

	public static function save_nav_menu_schema() {
		return [
			'type'       => 'object',
			'properties' => array_merge(
				self::menu_identifier_properties(),
				[
					'name'              => [
						'type'        => 'string',
						'description' => __( 'Menu name. Required when creating a new menu. When menuId/slug is provided, renames the existing menu.', 'bricks' ),
					],
					'description'       => [
						'type'        => 'string',
						'description' => __( 'Optional menu description. Pass an empty string to clear.', 'bricks' ),
					],
					'locations'         => [
						'type'        => 'array',
						'description' => __( 'Theme location slugs to assign this menu to. Omitted locations are preserved.', 'bricks' ),
						'items'       => [ 'type' => 'string' ],
					],
					'unassignLocations' => [
						'type'        => 'array',
						'description' => __( 'Theme location slugs to clear.', 'bricks' ),
						'items'       => [ 'type' => 'string' ],
					],
					'items'             => [
						'type'        => 'array',
						'description' => __( 'Ordered nested menu item tree to create/update/reorder. Omitted existing items are preserved, not deleted. Use delete-nav-menu-items for explicit deletion.', 'bricks' ),
						'items'       => self::menu_item_input_schema(),
					],
				]
			),
		];
	}

	public static function save_nav_menu_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'created'        => [ 'type' => 'boolean' ],
				'menuId'         => [ 'type' => 'integer' ],
				'before'         => [ 'type' => [ 'object', 'null' ] ],
				'after'          => [ 'type' => 'object' ],
				'createdItemIds' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'updatedItemIds' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'warnings'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	public static function delete_nav_menu_items_schema() {
		return [
			'type'       => 'object',
			'required'   => [ 'menuId', 'itemIds' ],
			'properties' => [
				'menuId'  => [
					'type'        => 'integer',
					'description' => __( 'Nav menu term ID containing the items.', 'bricks' ),
				],
				'itemIds' => [
					'type'        => 'array',
					'description' => __( 'Explicit nav menu item post IDs to delete.', 'bricks' ),
					'items'       => [ 'type' => 'integer' ],
				],
			],
		];
	}

	public static function delete_nav_menu_items_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'menuId'         => [ 'type' => 'integer' ],
				'deletedItemIds' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'beforeDelete'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'after'          => [ 'type' => 'object' ],
			],
		];
	}

	public static function delete_nav_menu_schema() {
		return [
			'type'       => 'object',
			'properties' => self::menu_identifier_properties(),
		];
	}

	public static function delete_nav_menu_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'      => [ 'type' => 'boolean' ],
				'menuId'       => [ 'type' => 'integer' ],
				'beforeDelete' => [ 'type' => 'object' ],
			],
		];
	}

	private static function menu_identifier_properties() {
		return [
			'menuId' => [
				'type'        => 'integer',
				'description' => __( 'WordPress nav menu term ID. Prefer this whenever known.', 'bricks' ),
			],
			'slug'   => [
				'type'        => 'string',
				'description' => __( 'Nav menu slug. Alternative to menuId.', 'bricks' ),
			],
			'name'   => [
				'type'        => 'string',
				'description' => __( 'Exact nav menu name. Alternative to menuId; required when creating via save-nav-menu.', 'bricks' ),
			],
		];
	}

	private static function menu_item_input_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'menuItemId'    => [ 'type' => 'integer' ],
				'title'         => [ 'type' => 'string' ],
				'url'           => [ 'type' => 'string' ],
				'type'          => [
					'type'        => 'string',
					'description' => __( 'WordPress menu item type. Common values: custom, post_type, taxonomy, post_type_archive.', 'bricks' ),
				],
				'object'        => [ 'type' => 'string' ],
				'objectId'      => [ 'type' => 'integer' ],
				'status'        => [ 'type' => 'string' ],
				'parentId'      => [ 'type' => 'integer' ],
				'position'      => [ 'type' => 'integer' ],
				'classes'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'attrTitle'     => [ 'type' => 'string' ],
				'description'   => [ 'type' => 'string' ],
				'target'        => [ 'type' => 'string' ],
				'xfn'           => [ 'type' => 'string' ],
				'bricksOptions' => [
					'type'        => 'object',
					'description' => __( 'Bricks menu-item options. megaMenuTemplateId attaches a Bricks template to a top-level item; multilevel enables Bricks multilevel behavior. Pass null/0/false to clear.', 'bricks' ),
					'properties'  => [
						'megaMenuTemplateId' => [ 'type' => [ 'integer', 'null' ] ],
						'multilevel'         => [ 'type' => [ 'boolean', 'null' ] ],
					],
				],
				'children'      => [
					'type'        => 'array',
					'description' => __( 'Nested child menu items with the same shape.', 'bricks' ),
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}

	// ==================================================================
	// Permissions
	// ==================================================================

	/**
	 * Check read access for navigation abilities.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 *
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		return Manager::require_cap( 'edit_theme_options' );
	}

	public static function write_permission( $input ) {
		return self::read_permission( $input );
	}

	// ==================================================================
	// Execute
	// ==================================================================

	/**
	 * List WordPress navigation menus with Bricks metadata summary.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 *
	 * @return array
	 */
	public static function list_nav_menus( $input ) {
		$menus = wp_get_nav_menus( [ 'hide_empty' => false ] );
		$items = [];

		foreach ( $menus as $menu ) {
			$items[] = self::normalize_menu( $menu, false );
		}

		return [
			'menus'               => $items,
			'total'               => count( $items ),
			'registeredLocations' => self::registered_locations(),
		];
	}

	public static function get_nav_menu( $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		return [
			'menu'                => self::normalize_menu( $menu, true ),
			'registeredLocations' => self::registered_locations(),
		];
	}

	public static function save_nav_menu( $input ) {
		$created  = false;
		$before   = null;
		$warnings = [];

		if ( ! empty( $input['menuId'] ) || ! empty( $input['slug'] ) ) {
			$menu = self::resolve_menu( $input );

			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$menu_id = (int) $menu->term_id;
			$before  = self::normalize_menu( $menu, true );
		} else {
			$name = sanitize_text_field( trim( (string) ( $input['name'] ?? '' ) ) );

			if ( $name === '' ) {
				return Error::missing_param( 'name' );
			}

			$menu_id = wp_create_nav_menu( $name );

			if ( is_wp_error( $menu_id ) ) {
				return self::wrap_wp_error( $menu_id, 'create_nav_menu_failed' );
			}

			$created = true;
		}

		if ( array_key_exists( 'description', $input ) || ( ! $created && array_key_exists( 'name', $input ) ) ) {
			$current_menu = wp_get_nav_menu_object( $menu_id );
			$current_name = $current_menu ? $current_menu->name : ( $input['name'] ?? '' );
			$update       = [
				'menu-name'   => sanitize_text_field( (string) $current_name ),
				'description' => $current_menu ? sanitize_text_field( (string) $current_menu->description ) : '',
			];

			if ( ! $created && array_key_exists( 'name', $input ) ) {
				$name = sanitize_text_field( trim( (string) $input['name'] ) );

				if ( $name === '' ) {
					return Error::invalid_param( 'name', 'non-empty string', $name );
				}

				$update['menu-name'] = $name;
			}

			if ( array_key_exists( 'description', $input ) ) {
				$update['description'] = sanitize_text_field( (string) $input['description'] );
			}

			$result = wp_update_nav_menu_object( $menu_id, $update );

			if ( is_wp_error( $result ) ) {
				return self::wrap_wp_error( $result, 'update_nav_menu_failed', [ 'menuId' => $menu_id ] );
			}
		}

		$locations_result = self::save_locations( $menu_id, $input );

		if ( is_wp_error( $locations_result ) ) {
			return $locations_result;
		}

		$created_item_ids = [];
		$updated_item_ids = [];

		if ( array_key_exists( 'items', $input ) ) {
			if ( ! is_array( $input['items'] ) ) {
				return Error::invalid_param( 'items', 'array of menu item objects', $input['items'] );
			}

			// BUG-043: detect cycles before any writes so partial writes don't leak.
			// Seeded from the existing menu so re-parenting an item under one of its
			// own current descendants is caught, not just cycles within this payload.
			$cycle_check = self::detect_item_cycles( $input['items'], $menu_id );

			if ( is_wp_error( $cycle_check ) ) {
				return $cycle_check;
			}

			$item_result = self::save_item_tree( $menu_id, $input['items'], null, 1, $created_item_ids, $updated_item_ids, $warnings );

			if ( is_wp_error( $item_result ) ) {
				return $item_result;
			}
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return Error::not_found( 'nav_menu', $menu_id );
		}

		return [
			'created'        => $created,
			'menuId'         => $menu_id,
			'before'         => $before,
			'after'          => self::normalize_menu( $menu, true ),
			'createdItemIds' => $created_item_ids,
			'updatedItemIds' => $updated_item_ids,
			'warnings'       => $warnings,
		];
	}

	public static function delete_nav_menu_items( $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		if ( empty( $input['itemIds'] ) || ! is_array( $input['itemIds'] ) ) {
			return Error::invalid_param( 'itemIds', 'non-empty array of menu item IDs', $input['itemIds'] ?? null );
		}

		$menu_id       = (int) $menu->term_id;
		$menu_items    = self::get_menu_items( $menu_id );
		$items_by_id   = [];
		$before_delete = [];
		$deleted_ids   = [];

		foreach ( $menu_items as $item ) {
			$items_by_id[ (int) $item->ID ] = $item;
		}

		foreach ( $input['itemIds'] as $item_id ) {
			$item_id = (int) $item_id;

			if ( ! isset( $items_by_id[ $item_id ] ) ) {
				return Error::invalid_param( 'itemIds', 'IDs belonging to the selected menu', $item_id );
			}

			$before_delete[] = self::normalize_menu_item( $items_by_id[ $item_id ] );
			$deleted         = wp_delete_post( $item_id, true );

			if ( ! $deleted ) {
				return Error::conflict(
					'delete_nav_menu_item_failed',
					[
						'message'    => sprintf( 'Failed to delete nav menu item %d.', $item_id ),
						'menuId'     => $menu_id,
						'menuItemId' => $item_id,
					]
				);
			}

			$deleted_ids[] = $item_id;
		}

		$menu = wp_get_nav_menu_object( $menu_id );

		return [
			'menuId'         => $menu_id,
			'deletedItemIds' => $deleted_ids,
			'beforeDelete'   => $before_delete,
			'after'          => self::normalize_menu( $menu, true ),
		];
	}

	public static function delete_nav_menu( $input ) {
		$menu = self::resolve_menu( $input );

		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_id = (int) $menu->term_id;
		$before  = self::normalize_menu( $menu, true );
		$result  = wp_delete_nav_menu( $menu_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return Error::conflict(
				'delete_nav_menu_failed',
				[
					'message' => sprintf( 'Failed to delete nav menu %d.', $menu_id ),
					'menuId'  => $menu_id,
				]
			);
		}

		return [
			'deleted'      => true,
			'menuId'       => $menu_id,
			'beforeDelete' => $before,
		];
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * Resolve a navigation menu from one of the supported identifiers.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 *
	 * @return \WP_Term|\WP_Error
	 */
	private static function resolve_menu( array $input ) {
		if ( ! empty( $input['menuId'] ) ) {
			$menu = wp_get_nav_menu_object( (int) $input['menuId'] );

			if ( $menu ) {
				return $menu;
			}

			return Error::not_found( 'nav_menu', (int) $input['menuId'] );
		}

		if ( ! empty( $input['slug'] ) ) {
			$menu = wp_get_nav_menu_object( sanitize_title( $input['slug'] ) );

			if ( $menu ) {
				return $menu;
			}

			return Error::not_found( 'nav_menu', $input['slug'] );
		}

		if ( ! empty( $input['name'] ) ) {
			$menu = wp_get_nav_menu_object( sanitize_text_field( $input['name'] ) );

			if ( $menu ) {
				return $menu;
			}

			return Error::not_found( 'nav_menu', $input['name'] );
		}

		return Error::missing_param( 'menuId', [ 'menuId', 'slug', 'name' ] );
	}

	private static function normalize_menu( $menu, bool $include_items ): array {
		$menu_id    = (int) $menu->term_id;
		$items      = self::get_menu_items( $menu_id );
		$summary    = self::menu_item_summary( $items );
		$normalized = [
			'menuId'              => $menu_id,
			'name'                => $menu->name,
			'slug'                => $menu->slug,
			'description'         => $menu->description,
			'count'               => count( $items ),
			'locations'           => self::locations_for_menu( $menu_id ),
			'hasBricksMegaMenus'  => $summary['hasBricksMegaMenus'],
			'hasBricksMultilevel' => $summary['hasBricksMultilevel'],
		];

		if ( $include_items ) {
			$normalized['items'] = self::build_item_tree( $items );
		}

		return $normalized;
	}

	private static function get_menu_items( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );

		return is_array( $items ) ? array_values( $items ) : [];
	}

	private static function normalize_menu_item( $item ): array {
		$template_id   = (int) get_post_meta( $item->ID, self::META_MEGA_MENU_TEMPLATE_ID, true );
		$template      = $template_id ? get_post( $template_id ) : null;
		$template_type = $template ? get_post_meta( $template->ID, BRICKS_DB_TEMPLATE_TYPE, true ) : 'content';
		$template_type = ! empty( $template_type ) ? $template_type : 'content';

		return [
			'menuItemId'    => (int) $item->ID,
			'title'         => (string) $item->title,
			'url'           => (string) $item->url,
			'type'          => (string) $item->type,
			'object'        => (string) $item->object,
			'objectId'      => (int) $item->object_id,
			'parentId'      => (int) $item->menu_item_parent,
			'position'      => (int) $item->menu_order,
			'status'        => (string) $item->post_status,
			'classes'       => is_array( $item->classes ) ? array_values( array_filter( $item->classes ) ) : [],
			'attrTitle'     => (string) $item->attr_title,
			'description'   => (string) $item->description,
			'target'        => (string) $item->target,
			'xfn'           => (string) $item->xfn,
			'bricksOptions' => [
				'megaMenuTemplateId' => $template_id > 0 ? $template_id : null,
				'megaMenuTemplate'   => $template ? [
					'id'     => (int) $template->ID,
					'title'  => get_the_title( $template->ID ),
					'status' => $template->post_status,
					'type'   => $template_type,
				] : null,
				'multilevel'         => (bool) get_post_meta( $item->ID, self::META_MULTILEVEL, true ),
			],
		];
	}

	private static function build_item_tree( array $items ): array {
		$normalized = [];
		$by_parent  = [];

		foreach ( $items as $item ) {
			$row = self::normalize_menu_item( $item );

			$row['children']                  = [];
			$normalized[ $row['menuItemId'] ] = $row;
			$by_parent[ $row['parentId'] ][]  = $row['menuItemId'];
		}

		$walk = function( $parent_id ) use ( &$walk, &$normalized, &$by_parent ) {
			$children = [];

			foreach ( $by_parent[ $parent_id ] ?? [] as $item_id ) {
				$row             = $normalized[ $item_id ];
				$row['children'] = $walk( $item_id );
				$children[]      = $row;
			}

			return $children;
		};

		return $walk( 0 );
	}

	private static function menu_item_summary( array $items ): array {
		$summary = [
			'hasBricksMegaMenus'  => false,
			'hasBricksMultilevel' => false,
		];

		foreach ( $items as $item ) {
			if ( get_post_meta( $item->ID, self::META_MEGA_MENU_TEMPLATE_ID, true ) ) {
				$summary['hasBricksMegaMenus'] = true;
			}

			if ( get_post_meta( $item->ID, self::META_MULTILEVEL, true ) ) {
				$summary['hasBricksMultilevel'] = true;
			}
		}

		return $summary;
	}

	private static function save_locations( int $menu_id, array $input ) {
		$registered = get_registered_nav_menus();

		foreach ( [ 'locations', 'unassignLocations' ] as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( ! is_array( $input[ $key ] ) ) {
				return Error::invalid_param( $key, 'array of registered theme location slugs', $input[ $key ] );
			}

			foreach ( $input[ $key ] as $location ) {
				if ( ! array_key_exists( (string) $location, $registered ) ) {
					return Error::invalid_param( $key, 'registered theme location slug', $location );
				}
			}
		}

		if ( ! array_key_exists( 'locations', $input ) && ! array_key_exists( 'unassignLocations', $input ) ) {
			return true;
		}

		$locations = get_nav_menu_locations();

		foreach ( $input['locations'] ?? [] as $location ) {
			$locations[ (string) $location ] = $menu_id;
		}

		foreach ( $input['unassignLocations'] ?? [] as $location ) {
			unset( $locations[ (string) $location ] );
		}

		set_theme_mod( 'nav_menu_locations', $locations );

		return true;
	}

	private static function registered_locations(): array {
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$locations  = [];

		foreach ( $registered as $location => $description ) {
			$menu_id = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;

				$locations[] = [
					'location'    => (string) $location,
					'description' => (string) $description,
					'menuId'      => $menu_id ? $menu_id : null,
					'menuName'    => $menu ? $menu->name : null,
				];
		}

		return $locations;
	}

	private static function locations_for_menu( int $menu_id ): array {
		$locations = [];

		foreach ( self::registered_locations() as $location ) {
			if ( (int) ( $location['menuId'] ?? 0 ) === $menu_id ) {
				$locations[] = $location['location'];
			}
		}

		return $locations;
	}

	private static function save_item_tree( int $menu_id, array $items, $parent_id, int $position, array &$created_item_ids, array &$updated_item_ids, array &$warnings ) {
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return Error::invalid_param( 'items', 'array of menu item objects', $item );
			}

			$item_parent_id = $parent_id === null ? (int) ( $item['parentId'] ?? 0 ) : (int) $parent_id;
			$item_position  = isset( $item['position'] ) ? max( 1, (int) $item['position'] ) : $position + $index;
			$result         = self::save_menu_item( $menu_id, $item, $item_parent_id, $item_position, $warnings );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $item['menuItemId'] ) ) {
				$updated_item_ids[] = $result;
			} else {
				$created_item_ids[] = $result;
			}

			if ( isset( $item['children'] ) ) {
				if ( ! is_array( $item['children'] ) ) {
					return Error::invalid_param( 'children', 'array of menu item objects', $item['children'] );
				}

				$children_result = self::save_item_tree( $menu_id, $item['children'], $result, 1, $created_item_ids, $updated_item_ids, $warnings );

				if ( is_wp_error( $children_result ) ) {
					return $children_result;
				}
			}
		}

		return true;
	}

	/**
	 * Detect cycles in the incoming item tree before any writes happen.
	 *
	 * Builds a parent map seeded from the menu's CURRENT items, then overlays the
	 * parent relationships declared in this payload, then walks every chain. This
	 * catches self-parent (a->a) and in-payload cycles (a->b->a) as well as the
	 * transitive case where an existing item is re-parented under one of its own
	 * current descendants (re-parent 792 under 794 when 794 is a child of 792).
	 *
	 * @since 2.4
	 *
	 * @param array $items   Incoming items (possibly nested).
	 * @param int   $menu_id Menu being written, used to seed the existing tree.
	 * @return true|\WP_Error
	 */
	private static function detect_item_cycles( array $items, int $menu_id = 0 ) {
		$parent_map = [];

		// Seed with the menu's existing parent relationships so re-parenting an
		// item under one of its current descendants is detected. Incoming items
		// below overwrite these where they declare a new parent.
		if ( $menu_id > 0 ) {
			$existing_items = wp_get_nav_menu_items( $menu_id, [ 'update_post_term_cache' => false ] );

			if ( is_array( $existing_items ) ) {
				foreach ( $existing_items as $existing_item ) {
					$parent_map[ (int) $existing_item->ID ] = (int) $existing_item->menu_item_parent;
				}
			}
		}

		$collect = function( array $nodes, $parent_id ) use ( &$collect, &$parent_map ) {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$item_id = (int) ( $node['menuItemId'] ?? 0 );

				if ( $item_id > 0 ) {
					$declared_parent        = $parent_id === null ? (int) ( $node['parentId'] ?? 0 ) : (int) $parent_id;
					$parent_map[ $item_id ] = $declared_parent;
				}

				if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
					$collect( $node['children'], $item_id );
				}
			}
		};

		$collect( $items, null );

		$max_depth = max( 1, count( $parent_map ) );

		foreach ( $parent_map as $item_id => $parent_id ) {
			$current = $parent_id;
			$visited = [ $item_id => true ];
			$walked  = 0;

			while ( $current !== 0 && $walked <= $max_depth ) {
				if ( isset( $visited[ $current ] ) ) {
					return Error::invalid_param(
						'parentId',
						'a non-circular parent chain',
						[
							'menuItemId' => $item_id,
							'parentId'   => $parent_id,
							'cycleNode'  => $current,
							'code'       => 'bricks_circular_parent',
						]
					);
				}

				$visited[ $current ] = true;
				$current             = isset( $parent_map[ $current ] ) ? $parent_map[ $current ] : 0;
				$walked++;
			}

			if ( $walked > $max_depth ) {
				return Error::invalid_param(
					'parentId',
					'a non-circular parent chain',
					[
						'menuItemId' => $item_id,
						'parentId'   => $parent_id,
						'code'       => 'bricks_circular_parent',
					]
				);
			}
		}

		return true;
	}

	private static function save_menu_item( int $menu_id, array $item, int $parent_id, int $position, array &$warnings ) {
		$existing_id = ! empty( $item['menuItemId'] ) ? (int) $item['menuItemId'] : 0;
		$args        = $existing_id ? self::menu_item_args_from_existing( $existing_id ) : self::default_menu_item_args();

		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( $existing_id && ! self::item_belongs_to_menu( $existing_id, $menu_id ) ) {
			return Error::invalid_param( 'menuItemId', 'an existing item ID that belongs to the selected menu', $existing_id );
		}

		$args['menu-item-db-id']     = $existing_id;
		$args['menu-item-parent-id'] = $parent_id;
		$args['menu-item-position']  = $position;

		self::apply_menu_item_input( $args, $item );

		$validation = self::validate_menu_item_args( $item, $args, $parent_id, $warnings );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$item_id = wp_update_nav_menu_item( $menu_id, $existing_id, $args );

		if ( is_wp_error( $item_id ) ) {
			return self::wrap_wp_error(
				$item_id,
				'save_nav_menu_item_failed',
				[
					'menuId'     => $menu_id,
					'menuItemId' => $existing_id,
				]
			);
		}

		if ( isset( $item['bricksOptions'] ) ) {
			$meta_result = self::save_bricks_options( (int) $item_id, $item['bricksOptions'] );

			if ( is_wp_error( $meta_result ) ) {
				return $meta_result;
			}
		}

		return (int) $item_id;
	}

	private static function item_belongs_to_menu( int $item_id, int $menu_id ): bool {
		$terms = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		return in_array( $menu_id, array_map( 'intval', $terms ), true );
	}

	private static function default_menu_item_args(): array {
		return [
			'menu-item-title'       => '',
			'menu-item-url'         => '',
			'menu-item-type'        => 'custom',
			'menu-item-object'      => 'custom',
			'menu-item-object-id'   => 0,
			'menu-item-parent-id'   => 0,
			'menu-item-position'    => 0,
			'menu-item-status'      => 'publish',
			'menu-item-attr-title'  => '',
			'menu-item-description' => '',
			'menu-item-target'      => '',
			'menu-item-classes'     => '',
			'menu-item-xfn'         => '',
		];
	}

	private static function menu_item_args_from_existing( int $item_id ) {
		$post = get_post( $item_id );

		if ( ! $post || $post->post_type !== 'nav_menu_item' ) {
			return Error::not_found( 'nav_menu_item', $item_id );
		}

		$item = wp_setup_nav_menu_item( $post );

		if ( ! $item || is_wp_error( $item ) ) {
			return Error::not_found( 'nav_menu_item', $item_id );
		}

		return [
			'menu-item-title'       => (string) $item->title,
			'menu-item-url'         => (string) $item->url,
			'menu-item-type'        => (string) $item->type,
			'menu-item-object'      => (string) $item->object,
			'menu-item-object-id'   => (int) $item->object_id,
			'menu-item-parent-id'   => (int) $item->menu_item_parent,
			'menu-item-position'    => (int) $item->menu_order,
			'menu-item-status'      => (string) $item->post_status,
			'menu-item-attr-title'  => (string) $item->attr_title,
			'menu-item-description' => (string) $item->description,
			'menu-item-target'      => (string) $item->target,
			'menu-item-classes'     => is_array( $item->classes ) ? implode( ' ', array_map( 'sanitize_html_class', $item->classes ) ) : sanitize_text_field( (string) $item->classes ),
			'menu-item-xfn'         => (string) $item->xfn,
		];
	}

	private static function apply_menu_item_input( array &$args, array $item ) {
		$map = [
			'title'       => __( 'menu-item-title', 'bricks' ),
			'url'         => 'menu-item-url',
			'type'        => 'menu-item-type',
			'object'      => 'menu-item-object',
			'objectId'    => 'menu-item-object-id',
			'status'      => 'menu-item-status',
			'attrTitle'   => 'menu-item-attr-title',
			'description' => __( 'menu-item-description', 'bricks' ),
			'target'      => 'menu-item-target',
			'xfn'         => 'menu-item-xfn',
		];

		foreach ( $map as $input_key => $arg_key ) {
			if ( array_key_exists( $input_key, $item ) ) {
				if ( $input_key === 'objectId' ) {
					$args[ $arg_key ] = (int) $item[ $input_key ];
				} elseif ( $input_key === 'url' ) {
					$args[ $arg_key ] = self::normalize_menu_item_url( (string) $item[ $input_key ] );
				} else {
					$args[ $arg_key ] = sanitize_text_field( (string) $item[ $input_key ] );
				}
			}
		}

		if ( array_key_exists( 'classes', $item ) ) {
			$classes                   = is_array( $item['classes'] ) ? $item['classes'] : preg_split( '/\s+/', (string) $item['classes'] );
			$args['menu-item-classes'] = implode( ' ', array_values( array_filter( array_map( 'sanitize_html_class', $classes ) ) ) );
		}
	}

	private static function normalize_menu_item_url( string $url ): string {
		$url = trim( $url );

		if ( $url !== '' && strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) {
			$url = home_url( $url );
		}

		return esc_url_raw( $url );
	}

	private static function validate_menu_item_args( array $item, array $args, int $parent_id, array &$warnings ) {
		if ( $args['menu-item-type'] === 'custom' ) {
			if ( trim( (string) $args['menu-item-title'] ) === '' ) {
				return Error::invalid_param( 'title', 'non-empty string for custom menu items', $args['menu-item-title'] );
			}

			if ( trim( (string) $args['menu-item-url'] ) === '' ) {
				return Error::invalid_param( 'url', 'non-empty URL for custom menu items', $args['menu-item-url'] );
			}
		} elseif ( empty( $args['menu-item-object-id'] ) || empty( $args['menu-item-object'] ) ) {
			return Error::invalid_param( 'objectId', 'objectId and object for non-custom menu items', $args['menu-item-object-id'] );
		}

		// BUG-044: reject menu items pointing to a post/term that no longer exists.
		// Without this, WP happily creates orphan nav_menu_item rows that are
		// invisible to get-nav-menu (wp_setup_nav_menu_item drops them).
		$object_id   = (int) ( $args['menu-item-object-id'] ?? 0 );
		$object_type = (string) ( $args['menu-item-type'] ?? '' );
		$object_slug = (string) ( $args['menu-item-object'] ?? '' );

		if ( $object_id > 0 ) {
			if ( $object_type === 'post_type' ) {
				$post = get_post( $object_id );

				if ( ! $post || ( $object_slug !== '' && $post->post_type !== $object_slug ) ) {
					return Error::invalid_param(
						'objectId',
						'an existing post for the given object type',
						[
							'objectId' => $object_id,
							'object'   => $object_slug,
							'code'     => 'bricks_object_not_found',
						]
					);
				}
			} elseif ( $object_type === 'taxonomy' ) {
				$term = $object_slug !== '' ? get_term( $object_id, $object_slug ) : get_term( $object_id );

				if ( ! $term || is_wp_error( $term ) ) {
					return Error::invalid_param(
						'objectId',
						'an existing term for the given taxonomy',
						[
							'objectId' => $object_id,
							'object'   => $object_slug,
							'code'     => 'bricks_object_not_found',
						]
					);
				}
			} elseif ( $object_type === 'post_type_archive' ) {
				if ( $object_slug !== '' && ! post_type_exists( $object_slug ) ) {
					return Error::invalid_param(
						'object',
						'a registered post type for post_type_archive items',
						[
							'object' => $object_slug,
							'code'   => 'bricks_object_not_found',
						]
					);
				}
			}
		}

		if ( isset( $item['bricksOptions'] ) ) {
			if ( ! is_array( $item['bricksOptions'] ) ) {
				return Error::invalid_param( 'bricksOptions', 'object', $item['bricksOptions'] );
			}

			$result = self::validate_bricks_options( $item['bricksOptions'], $parent_id, $warnings );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	private static function validate_bricks_options( array $options, int $parent_id, array &$warnings ) {
		$has_mega_template = array_key_exists( 'megaMenuTemplateId', $options ) && ! empty( $options['megaMenuTemplateId'] );
		$has_multilevel    = array_key_exists( 'multilevel', $options ) && ! empty( $options['multilevel'] );

		if ( $parent_id !== 0 && ( $has_mega_template || $has_multilevel ) ) {
			return Error::invalid_param( 'bricksOptions', 'Bricks mega menu and multilevel options only on top-level menu items', $options );
		}

		if ( ! $has_mega_template ) {
			return true;
		}

		$template_id = (int) $options['megaMenuTemplateId'];
		$template    = get_post( $template_id );

		if ( ! $template || $template->post_type !== BRICKS_DB_TEMPLATE_SLUG ) {
			return Error::invalid_param( 'megaMenuTemplateId', 'published Bricks template ID', $template_id );
		}

		if ( $template->post_status !== 'publish' ) {
			return Error::invalid_param( 'megaMenuTemplateId', 'published Bricks template ID', $template_id );
		}

		if ( ! current_user_can( 'edit_post', $template_id ) ) {
			return Error::forbidden_edit_post( $template_id );
		}

		$template_type = get_post_meta( $template_id, BRICKS_DB_TEMPLATE_TYPE, true );
		$template_type = ! empty( $template_type ) ? $template_type : 'content';

		if ( ! in_array( $template_type, [ 'section', 'content' ], true ) ) {
			$warnings[] = [
				'code'       => 'bricks_nav_menu_template_type_unusual',
				'message'    => sprintf( 'Template %d is type "%s"; section templates are recommended for Bricks mega menus.', $template_id, $template_type ),
				'templateId' => $template_id,
				'type'       => $template_type,
			];
		}

		return true;
	}

	private static function save_bricks_options( int $item_id, array $options ) {
		if ( array_key_exists( 'megaMenuTemplateId', $options ) ) {
			$template_id = (int) $options['megaMenuTemplateId'];

			if ( $template_id > 0 ) {
				update_post_meta( $item_id, self::META_MEGA_MENU_TEMPLATE_ID, (string) $template_id );
			} else {
				delete_post_meta( $item_id, self::META_MEGA_MENU_TEMPLATE_ID );
			}
		}

		if ( array_key_exists( 'multilevel', $options ) ) {
			if ( ! empty( $options['multilevel'] ) ) {
				update_post_meta( $item_id, self::META_MULTILEVEL, true );
			} else {
				delete_post_meta( $item_id, self::META_MULTILEVEL );
			}
		}

		return true;
	}

	private static function wrap_wp_error( \WP_Error $error, string $reason, array $ctx = [] ): \WP_Error {
		$error_data = $error->get_error_data();

		return Error::conflict(
			$reason,
			array_merge(
				[
					'message'     => $error->get_error_message(),
					'wpErrorCode' => $error->get_error_code(),
					'wpErrorData' => $error_data,
				],
				$ctx
			)
		);
	}
}

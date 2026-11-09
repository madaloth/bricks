<?php
/**
 * Popup abilities
 *
 * Thin wrappers on top of the template abilities, narrowed to
 * `type=popup`. Popup-specific settings (popupCloseOn,
 * popupBodyScroll, popupAjax, popupLimit*) live in the template settings
 * meta (BRICKS_DB_TEMPLATE_SETTINGS), the same storage as generic
 * template settings, but we expose a narrower surface here and also
 * surface opener elements (interactions with target=popup) so callers
 * can debug "popup won't open" problems.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Popups {

	/**
	 * Popup-specific template setting keys. Sourced from
	 * includes/popups.php:96-560 (self::$controls).
	 *
	 * @since 2.4
	 */
	const POPUP_SETTING_KEYS = [
		'popupCloseOn',
		'popupBodyScroll',
		'popupAjax',
		'popupAjaxLoaderAnimation',
		'popupAjaxLoaderColor',
		'popupAjaxLoaderScale',
		'popupAjaxLoaderSelector',
		'popupLimitWindow',
		'popupLimitSessionStorage',
		'popupLimitLocalStorage',
		'popupLimitTimeStorage',
	];

	/**
	 * Popup checkbox controls that render as enabled when the key exists.
	 *
	 * @since 2.4
	 */
	const POPUP_CHECKBOX_KEYS = [
		'popupBodyScroll',
		'popupAjax',
	];

	/**
	 * Popup number controls. Empty/false values should remove the key.
	 *
	 * @since 2.4
	 */
	const POPUP_NUMBER_KEYS = [
		'popupAjaxLoaderScale',
		'popupLimitWindow',
		'popupLimitSessionStorage',
		'popupLimitLocalStorage',
		'popupLimitTimeStorage',
	];

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: list popups.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_permission( $input ) {
		return Templates::list_permission( $input );
	}

	/**
	 * Permission: read a popup's config.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_permission( $input ) {
		return Templates::read_permission( $input );
	}

	/**
	 * Permission: edit a popup's settings.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function edit_permission( $input ) {
		return Templates::settings_write_permission( $input );
	}

	// ==================================================================
	// LIST POPUPS
	// ==================================================================

	/**
	 * Input schema for list-popups
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_popups_schema() {
		return [
			'type'       => 'object',
			'properties' => Manager::pagination_schema_properties(),
		];
	}

	/**
	 * Output schema for list-popups
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_popups_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'        => 'array',
					'description' => __( 'Popup template summaries with id, title, status, editUrl, conditionCount, popupAjax, popupLimit (any).', 'bricks' ),
				],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
				'hasMore' => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Callback: list popup templates with popup-specific metadata.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_popups( $input ) {
		$posts = get_posts(
			[
				'post_type'              => BRICKS_DB_TEMPLATE_SLUG,
				'posts_per_page'         => -1,
				'post_status'            => 'any',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bricks stores template type in post meta.
					[
						'key'   => BRICKS_DB_TEMPLATE_TYPE,
						'value' => 'popup',
					],
				],
				'cache_results'          => false,
				'update_post_meta_cache' => false,
			]
		);

		$items = [];

		foreach ( $posts as $post ) {
			$settings        = \Bricks\Helpers::get_template_settings( $post->ID );
			$settings        = is_array( $settings ) ? $settings : [];
			$condition_count = isset( $settings['templateConditions'] ) && is_array( $settings['templateConditions'] )
				? count( $settings['templateConditions'] )
				: 0;

			$has_popup_limit = isset( $settings['popupLimitWindow'] )
				|| isset( $settings['popupLimitSessionStorage'] )
				|| isset( $settings['popupLimitLocalStorage'] )
				|| isset( $settings['popupLimitTimeStorage'] );

			$items[] = [
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'status'         => $post->post_status,
				'editUrl'        => \Bricks\Helpers::get_builder_edit_link( $post->ID ),
				'conditionCount' => $condition_count,
				'popupAjax'      => ! empty( $settings['popupAjax'] ),
				'hasPopupLimit'  => $has_popup_limit,
			];
		}

		return Reference::paginate( $items, $input );
	}

	// ==================================================================
	// GET POPUP CONFIG
	// ==================================================================

	/**
	 * Input schema for get-popup-config
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_popup_config_schema() {
		return [
			'type'       => 'object',
			'properties' => self::popup_identifier_properties(),
		];
	}

	/**
	 * Output schema for get-popup-config
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_popup_config_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'popupId'       => [ 'type' => 'integer' ],
				'title'         => [ 'type' => 'string' ],
				'status'        => [ 'type' => 'string' ],
				'popupSettings' => [
					'type'        => 'object',
					'description' => __( 'Popup-specific keys: popupCloseOn, popupBodyScroll, popupAjax, popupLimit*.', 'bricks' ),
				],
				'conditions'    => [
					'type'        => 'array',
					'description' => __( 'Template display conditions describing where the popup is eligible to render. Without matching conditions, Bricks does not include the popup DOM on normal page renders, so `bricksOpenPopup(id)` only works if another path has already rendered that popup.', 'bricks' ),
				],
				'openers'       => [
					'type'        => 'array',
					'description' => __( 'Elements across the site that open this popup via element-level or global-class interactions (action+target=popup+templateId=popupId). Each row: postId, postTitle, elementId, elementName, trigger, action, source, sourceId, sourceName.', 'bricks' ),
				],
				'allSettings'   => [
					'type'        => 'object',
					'description' => __( 'Full template settings object (includes generic template settings on top of popup keys).', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: read popup config + opener references.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_popup_config( $input ) {
		$popup_id = Templates::get_resolved_template_id( $input );

		if ( is_wp_error( $popup_id ) ) {
			return $popup_id;
		}

		Manager::flush_post_cache( $popup_id );
		$post = get_post( $popup_id );

		if ( ! $post ) {
			return Error::not_found( 'popup', $popup_id );
		}

		$template_type = get_post_meta( $popup_id, BRICKS_DB_TEMPLATE_TYPE, true );

		if ( $template_type !== 'popup' ) {
			return Error::invalid_param( 'templateId', 'a template with type=popup', $template_type );
		}

		$settings = \Bricks\Helpers::get_template_settings( $popup_id );
		$settings = is_array( $settings ) ? $settings : [];

		$popup_settings = [];
		foreach ( self::POPUP_SETTING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$popup_settings[ $key ] = $settings[ $key ];
			}
		}

		return [
			'popupId'       => $popup_id,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'popupSettings' => $popup_settings,
			'conditions'    => $settings['templateConditions'] ?? [],
			'openers'       => self::find_popup_openers( $popup_id ),
			'allSettings'   => $settings,
		];
	}

	// ==================================================================
	// UPDATE POPUP SETTINGS
	// ==================================================================

	/**
	 * Input schema for update-popup-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_popup_settings_schema() {
		$properties             = self::popup_identifier_properties();
		$properties['settings'] = [
			'type'        => 'object',
			'description' => __( 'Partial-merge of popup-specific keys. Recognized: ', 'bricks' ) . implode( ', ', self::POPUP_SETTING_KEYS ) . '. Pass null/false/empty string to unset optional popup keys. `popupCloseOn` must be one of `backdrop`, `esc`, or `none`.',
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'settings' ],
		];
	}

	/**
	 * Output schema for update-popup-settings
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_popup_settings_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'popupId'       => [ 'type' => 'integer' ],
				'popupSettings' => [ 'type' => 'object' ],
				'allSettings'   => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Callback: partial-merge popup-specific template settings.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_popup_settings( $input ) {
		$popup_id = Templates::get_resolved_template_id( $input );

		if ( is_wp_error( $popup_id ) ) {
			return $popup_id;
		}

		$template_type = get_post_meta( $popup_id, BRICKS_DB_TEMPLATE_TYPE, true );

		if ( $template_type !== 'popup' ) {
			return Error::invalid_param( 'templateId', 'a template with type=popup', $template_type );
		}

		$new_settings = $input['settings'];

		if ( ! is_array( $new_settings ) ) {
			return Error::invalid_param( 'settings', 'an object of popup settings', $new_settings );
		}

		$normalized = self::normalize_popup_settings( $new_settings );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		Manager::flush_post_cache( $popup_id );
		$existing = \Bricks\Helpers::get_template_settings( $popup_id );
		$existing = is_array( $existing ) ? $existing : [];

		$merged = Elements::deep_merge( $existing, $normalized['set'] );

		foreach ( $normalized['unset'] as $key ) {
			unset( $merged[ $key ] );
		}

		\Bricks\Helpers::set_template_settings( $popup_id, $merged );

		$popup_settings = [];
		foreach ( self::POPUP_SETTING_KEYS as $key ) {
			if ( array_key_exists( $key, $merged ) ) {
				$popup_settings[ $key ] = $merged[ $key ];
			}
		}

		return [
			'popupId'       => $popup_id,
			'popupSettings' => $popup_settings,
			'allSettings'   => $merged,
		];
	}

	/**
	 * Popup identifier properties, including popupId as a templateId alias.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function popup_identifier_properties(): array {
		$properties = Templates::template_identifier_properties();

		$properties['popupId'] = [
			'type'        => 'integer',
			'description' => __( 'Alias for `templateId` when addressing popup templates.', 'bricks' ),
		];

		return $properties;
	}

	/**
	 * Validate and normalize popup setting updates before persistence.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Raw incoming setting patch.
	 * @return array|\WP_Error Array with `set` and `unset` keys.
	 */
	private static function normalize_popup_settings( array $settings ) {
		$set   = [];
		$unset = [];

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;

			if ( in_array( $key, self::POPUP_SETTING_KEYS, true ) && ( $value === null || $value === false || $value === '' ) ) {
				$unset[] = $key;
				continue;
			}

			if ( $key === 'popupCloseOn' ) {
				if ( ! is_scalar( $value ) ) {
					return Error::invalid_param( 'settings.popupCloseOn', 'one of: backdrop, esc, none', $value );
				}

				$value = (string) $value;

				if ( ! in_array( $value, [ 'backdrop', 'esc', 'none' ], true ) ) {
					return Error::invalid_param( 'settings.popupCloseOn', 'one of: backdrop, esc, none', $value );
				}

				$set[ $key ] = $value;
				continue;
			}

			if ( in_array( $key, self::POPUP_CHECKBOX_KEYS, true ) ) {
				if ( $value === true || $value === 1 || $value === '1' ) {
					$set[ $key ] = true;
					continue;
				}

				return Error::invalid_param( "settings.{$key}", 'true to enable, or false/null/empty string to unset', $value );
			}

			if ( in_array( $key, self::POPUP_NUMBER_KEYS, true ) ) {
				if ( ! is_numeric( $value ) ) {
					return Error::invalid_param( "settings.{$key}", 'number, or false/null/empty string to unset', $value );
				}

				$set[ $key ] = (float) $value;
				continue;
			}

			if ( in_array( $key, self::POPUP_SETTING_KEYS, true ) && is_array( $value ) && $key !== 'popupAjaxLoaderColor' ) {
				return Error::invalid_param( "settings.{$key}", 'scalar popup setting value', $value );
			}

			// Reject any key that is not in the popup-settings allow-list. Without
			// this gate the foreach silently merged arbitrary keys into stored
			// template settings.
			if ( ! in_array( $key, self::POPUP_SETTING_KEYS, true ) ) {
				return Error::unknown_param( "settings.{$key}", self::POPUP_SETTING_KEYS );
			}

			$set[ $key ] = $value;
		}

		return [
			'set'   => $set,
			'unset' => array_values( array_unique( $unset ) ),
		];
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Find elements across all Bricks posts that open this popup via
	 * element-level or global-class interactions (target=popup, templateId=popup_id).
	 *
	 * Mirrors the lookup done by interactions.php:588-598 at render time.
	 *
	 * @since 2.4
	 *
	 * @param int $popup_id Popup template ID.
	 * @return array
	 */
	private static function find_popup_openers( $popup_id ) {
		$post_types = array_values(
			array_unique(
				array_merge(
					(array) ( \Bricks\Database::$global_settings['postTypes'] ?? [ 'post', 'page' ] ),
					[ BRICKS_DB_TEMPLATE_SLUG ]
				)
			)
		);

		$data_keys = [
			\Bricks\Database::get_bricks_data_key( 'content' ),
			\Bricks\Database::get_bricks_data_key( 'header' ),
			\Bricks\Database::get_bricks_data_key( 'footer' ),
		];

		$meta_query = [ 'relation' => 'OR' ];

		foreach ( $data_keys as $data_key ) {
			$meta_query[] = [
				'key'     => $data_key,
				'compare' => 'EXISTS',
			];
		}

		$post_ids = get_posts(
			[
				'post_type'              => $post_types,
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bricks element data lives in post meta.
				'cache_results'          => false,
				'update_post_meta_cache' => false,
			]
		);

		$openers = [];

		foreach ( $post_ids as $post_id ) {
			$area     = Elements::get_save_area_for_post( $post_id );
			$elements = \Bricks\Database::get_data( $post_id, $area );

			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $element ) {
				$interactions = Interactions::get_effective_interactions_for_element( $element );
				$interactions = $interactions['effective'] ?? [];

				if ( ! is_array( $interactions ) || empty( $interactions ) ) {
					continue;
				}

				foreach ( $interactions as $interaction ) {
					$target      = $interaction['target'] ?? '';
					$template_id = $interaction['templateId'] ?? null;

					if ( $target !== 'popup' ) {
						continue;
					}

					if ( (int) $template_id !== (int) $popup_id ) {
						continue;
					}

					$openers[] = [
						'postId'      => (int) $post_id,
						'postTitle'   => get_the_title( $post_id ),
						'elementId'   => $element['id'] ?? '',
						'elementName' => $element['name'] ?? '',
						'trigger'     => $interaction['trigger'] ?? '',
						'action'      => $interaction['action'] ?? '',
						'source'      => $interaction['source'] ?? '',
						'sourceId'    => $interaction['sourceId'] ?? '',
						'sourceName'  => $interaction['sourceName'] ?? '',
					];
				}
			}
		}

		return $openers;
	}
}

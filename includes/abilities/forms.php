<?php
/**
 * Form abilities
 *
 * Read and mutate Bricks Form elements (fields, actions) plus list stored
 * submissions. Backed by the same element-tree traversal + Save_Pipeline
 * revision snapshot path used by update-element.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Forms {

	/**
	 * Known field types - sourced from includes/elements/form.php:176-198.
	 *
	 * @since 2.4
	 */
	const FIELD_TYPES = [
		'email',
		'text',
		'textarea',
		'richtext',
		'tel',
		'number',
		'url',
		'image',
		'gallery',
		'checkbox',
		'select',
		'radio',
		'file',
		'datepicker',
		'password',
		'rememberme',
		'html',
		'hidden',
	];

	/**
	 * Known action types - sourced from includes/integrations/form/init.php:1122-1148.
	 *
	 * @since 2.4
	 */
	const ACTION_TYPES = [
		'custom',
		'email',
		'webhook',
		'redirect',
		'mailchimp',
		'sendgrid',
		'login',
		'registration',
		'lost-password',
		'reset-password',
		'create-post',
		'update-post',
		'unlock-password-protection',
		'save-submission',
	];

	/**
	 * Currently available built-in form action keys.
	 *
	 * Bricks conditionally exposes `unlock-password-protection` and
	 * `save-submission` based on global settings. The frontend submit handler
	 * skips actions that are not in Init::get_available_actions() unless a
	 * custom hook with the same key is registered, so writes should validate
	 * against the same runtime list to avoid saving silent no-ops.
	 *
	 * @since 2.4
	 *
	 * @return string[]
	 */
	private static function available_action_types() {
		Manager::flush_options_cache();
		self::refresh_runtime_form_settings();

		if ( class_exists( '\\Bricks\\Integrations\\Form\\Init' ) ) {
			$actions = \Bricks\Integrations\Form\Init::get_available_actions();

			if ( is_array( $actions ) ) {
				$action_keys = array_keys( $actions );

				if ( self::save_submissions_enabled() && ! in_array( 'save-submission', $action_keys, true ) ) {
					$action_keys[] = 'save-submission';
				}

				return $action_keys;
			}
		}

		return self::ACTION_TYPES;
	}

	/**
	 * Validate a form action key against the same rules used on submit.
	 *
	 * @since 2.4
	 *
	 * @param string $action Action key.
	 * @return bool
	 */
	private static function is_allowed_action_type( $action ) {
		return in_array( $action, self::available_action_types(), true ) || has_action( "bricks/form/action/{$action}" );
	}

	/**
	 * Human-readable action list for validation errors.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function allowed_actions_hint() {
		return 'one of the currently available built-in actions: ' . implode( ', ', self::available_action_types() ) . ', or a custom action key with a registered `bricks/form/action/{key}` hook';
	}

	/**
	 * Refresh Bricks' static settings cache from the option in long-running MCP processes.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function refresh_runtime_form_settings(): void {
		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		if ( is_array( $settings ) && class_exists( '\\Bricks\\Database' ) ) {
			\Bricks\Database::$global_data['settings'] = $settings;
			\Bricks\Database::$global_settings         = $settings;
		}
	}

	/**
	 * Is "Save form submissions" enabled in current Bricks settings?
	 *
	 * @since 2.4
	 *
	 * @return bool
	 */
	private static function save_submissions_enabled(): bool {
		$settings = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );

		return is_array( $settings ) && ! empty( $settings['saveFormSubmissions'] );
	}

	// ------------------------------------------------------------------
	// Permission callbacks
	// ------------------------------------------------------------------

	/**
	 * Permission: read form config (same gate as read_post).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function read_form_permission( $input ) {
		return Elements::read_post_permission( $input );
	}

	/**
	 * Permission: edit form (reuses update-element gating so element-level
	 * capabilities are honored - same path a builder user would hit).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function edit_form_permission( $input ) {
		return Elements::edit_element_permission( $input );
	}

	/**
	 * Permission: list submissions - must be able to view the backing post
	 * and have Bricks form-submission access.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function list_submissions_permission( $input ) {
		$can_read = Elements::read_post_permission( $input );

		if ( is_wp_error( $can_read ) ) {
			return $can_read;
		}

		if ( ! \Bricks\Capabilities::current_user_can_form_submission_access() ) {
			return Error::forbidden_builder_permission( \Bricks\Capabilities::FORM_SUBMISSION_ACCESS );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Shared helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve the form element in a post by element ID.
	 *
	 * @since 2.4
	 *
	 * @param int    $post_id    Resolved post ID.
	 * @param string $element_id Form element ID (6 chars).
	 * @return array|\WP_Error { index, element, elements } - the found index,
	 *                          the element itself, and the full flat tree.
	 */
	private static function find_form_element( $post_id, $element_id ) {
		Manager::flush_post_cache( $post_id );
		$area     = Elements::get_save_area_for_post( $post_id );
		$elements = \Bricks\Database::get_data( $post_id, $area );

		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return Error::not_found( 'element', $element_id );
		}

		foreach ( $elements as $index => $element ) {
			if ( ( $element['id'] ?? '' ) !== $element_id ) {
				continue;
			}

			if ( ( $element['name'] ?? '' ) !== 'form' ) {
				return Error::invalid_param( 'elementId', 'a form element id', $element_id );
			}

			return [
				'index'    => $index,
				'element'  => $element,
				'elements' => $elements,
				'area'     => $area,
			];
		}

		return Error::not_found( 'element', $element_id );
	}

	// ==================================================================
	// GET FORM CONFIG
	// ==================================================================

	/**
	 * Input schema for get-form-config
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_form_config_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The form element ID (6 chars).', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for get-form-config
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_form_config_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId' => [ 'type' => 'string' ],
				'fields'    => [
					'type'        => 'array',
					'description' => __( 'Ordered list of form fields. Each field has id, type, label, and type-specific keys (see skills/forms).', 'bricks' ),
				],
				'actions'   => [
					'type'        => 'array',
					'description' => __( 'List of action-type strings triggered on successful submit (e.g. email, webhook, redirect).', 'bricks' ),
				],
				'settings'  => [
					'type'        => 'object',
					'description' => __( 'Full form element settings including per-action config keys (emailTo, webhookUrl, etc.).', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: read form config.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function get_form_config( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$found = self::find_form_element( $post_id, $input['elementId'] );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$settings = $found['element']['settings'] ?? [];

		return [
			'elementId' => $input['elementId'],
			'fields'    => $settings['fields'] ?? [],
			'actions'   => $settings['actions'] ?? [],
			'settings'  => $settings,
		];
	}

	// ==================================================================
	// UPDATE FORM FIELDS
	// ==================================================================

	/**
	 * Input schema for update-form-fields
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_form_fields_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The form element ID.', 'bricks' ),
		];
		$properties['fields']    = [
			'type'        => 'array',
			'description' => __( 'Full replacement of the fields array. Each field must have { id, type } at minimum. Type must be one of: ', 'bricks' ) . implode( ', ', self::FIELD_TYPES ) . '.',
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId', 'fields' ],
		];
	}

	/**
	 * Output schema for update-form-fields
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_form_fields_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'  => [ 'type' => 'string' ],
				'fields'     => [ 'type' => 'array' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot. Pass to bricks/restore-revision to roll back.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: replace the fields array on a form element.
	 *
	 * Full replacement (not merge) - fields arrays are order-sensitive and
	 * partial merges on sequential items produce nondeterministic shapes.
	 * Callers fetch via get-form-config, mutate, then send the full new list.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_form_fields( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id = $input['elementId'];
		$fields     = $input['fields'];

		if ( ! is_array( $fields ) ) {
			return Error::invalid_param( 'fields', 'an array of field objects', $fields );
		}

		// Validate each field has a recognized type (the only sanity check we
		// can apply without re-implementing the Form control schema).
		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				return Error::invalid_param( "fields[{$i}]", 'a field object with id and type', $field );
			}

			if ( empty( $field['id'] ) || ! is_string( $field['id'] ) ) {
				return Error::invalid_param( "fields[{$i}].id", 'a non-empty field id string', $field['id'] ?? null );
			}

			$type = $field['type'] ?? '';

			if ( ! $type || ! in_array( $type, self::FIELD_TYPES, true ) ) {
				return Error::invalid_param( "fields[{$i}].type", 'one of: ' . implode( ', ', self::FIELD_TYPES ), $type );
			}
		}

		$found = self::find_form_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];

		$elements[ $index ]['settings']           = $elements[ $index ]['settings'] ?? [];
		$elements[ $index ]['settings']['fields'] = array_values( $fields );

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'  => $element_id,
			'fields'     => $elements[ $index ]['settings']['fields'],
			'revisionId' => $result['revisionId'],
		];
	}

	// ==================================================================
	// UPDATE FORM ACTIONS
	// ==================================================================

	/**
	 * Input schema for update-form-actions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_form_actions_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'The form element ID.', 'bricks' ),
		];
		$properties['actions']   = [
			'type'        => 'array',
			'description' => __( 'Replacement list of action-type strings. Built-in actions are validated against the Bricks form action registry, so `save-submission` and `unlock-password-protection` require their global settings. Custom action keys are accepted only when a matching `bricks/form/action/{key}` hook is registered.', 'bricks' ),
		];
		$properties['settings']  = [
			'type'        => 'object',
			'description' => __( 'Optional partial-merge of per-action settings (emailTo, webhookUrl, redirectUrl, etc.). See skills/forms for the full key list by action type.', 'bricks' ),
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for update-form-actions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function update_form_actions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elementId'  => [ 'type' => 'string' ],
				'actions'    => [ 'type' => 'array' ],
				'settings'   => [ 'type' => 'object' ],
				'revisionId' => [
					'type'        => [ 'integer', 'null' ],
					'description' => __( 'Revision ID of the pre-save snapshot.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: update actions list and/or partial-merge per-action settings.
	 *
	 * Provide `actions` to replace the action list.
	 * Provide `settings` to partial-merge action-specific keys
	 * (emailTo, webhookUrl, redirectUrl, etc.).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function update_form_actions( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id = $input['elementId'];

		if ( ! isset( $input['actions'] ) && ! isset( $input['settings'] ) ) {
			return Error::invalid_param( 'actions', 'at least one of actions or settings', null );
		}

		if ( isset( $input['actions'] ) ) {
			if ( ! is_array( $input['actions'] ) ) {
				return Error::invalid_param( 'actions', 'an array of action-type strings', $input['actions'] );
			}

			foreach ( $input['actions'] as $i => $action ) {
				if ( ! is_string( $action ) || $action === '' || ! self::is_allowed_action_type( $action ) ) {
					return Error::invalid_param( "actions[{$i}]", self::allowed_actions_hint(), $action );
				}
			}
		}

		$found = self::find_form_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$elements = $found['elements'];
		$index    = $found['index'];
		$area     = $found['area'];
		$settings = $elements[ $index ]['settings'] ?? [];

		if ( isset( $input['actions'] ) ) {
			$settings['actions'] = array_values( $input['actions'] );
		}

		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$settings = Elements::deep_merge( $settings, $input['settings'] );
		}

		$elements[ $index ]['settings'] = $settings;

		$result = Save_Pipeline::execute_partial( $post_id, $elements, $area );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'elementId'  => $element_id,
			'actions'    => $settings['actions'] ?? [],
			'settings'   => $settings,
			'revisionId' => $result['revisionId'],
		];
	}

	// ==================================================================
	// LIST FORM SUBMISSIONS
	// ==================================================================

	/**
	 * Input schema for list-form-submissions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_form_submissions_schema() {
		$properties              = Elements::post_identifier_properties();
		$properties['elementId'] = [
			'type'        => 'string',
			'description' => __( 'Form element ID (6 chars). Required.', 'bricks' ),
		];
		$properties              = array_merge( $properties, Manager::pagination_schema_properties( 100 ) );

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'elementId' ],
		];
	}

	/**
	 * Output schema for list-form-submissions
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_form_submissions_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'        => 'array',
					'description' => __( 'Submission rows with id, created_at, form_data (decoded), ip, user_id, status, favorite.', 'bricks' ),
				],
				'total'   => [ 'type' => 'integer' ],
				'page'    => [ 'type' => 'integer' ],
				'perPage' => [ 'type' => 'integer' ],
				'hasMore' => [ 'type' => 'boolean' ],
				'note'    => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'Set when the submissions table is missing (Save Submission feature disabled). Empty items list in that case.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Callback: list form submissions for a given form element.
	 *
	 * Reads the `{prefix}bricks_form_submissions` table (see
	 * includes/integrations/form/submission-database.php:11,76-93).
	 * If the table is missing, Save Submission is disabled site-wide - we
	 * return an empty list with a `note` rather than a hard error.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function list_form_submissions( $input ) {
		global $wpdb;

		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$element_id = $input['elementId'];

		$found = self::find_form_element( $post_id, $element_id );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$table = $wpdb->prefix . 'bricks_form_submissions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks Bricks' custom submissions table availability.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return [
				'items'   => [],
				'total'   => 0,
				'page'    => 1,
				'perPage' => 25,
				'hasMore' => false,
				'note'    => 'Submissions table not found. Enable "Save form submissions" under Bricks > Settings > General.',
			];
		}

		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $input['perPage'] ?? 25 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads Bricks' custom submissions table for MCP pagination.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a fixed Bricks suffix.
				"SELECT COUNT(*) FROM `{$table}` WHERE form_id = %s AND post_id = %d",
				$element_id,
				$post_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads Bricks' custom submissions table for MCP pagination.
		$rows = $wpdb->get_results(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from $wpdb->prefix and a fixed Bricks suffix.
			$wpdb->prepare(
				"SELECT id, post_id, form_id, created_at, form_data, ip, user_id, status, favorite
				 FROM `{$table}`
				 WHERE form_id = %s AND post_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$element_id,
				$post_id,
				$per_page,
				$offset
			),
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$items = [];

		foreach ( (array) $rows as $row ) {
			$decoded          = json_decode( $row['form_data'] ?? '', true );
			$row['form_data'] = is_array( $decoded ) ? $decoded : [];
			$items[]          = $row;
		}

		return [
			'items'   => $items,
			'total'   => $total,
			'page'    => $page,
			'perPage' => $per_page,
			'hasMore' => ( $page * $per_page ) < $total,
			'note'    => null,
		];
	}
}

<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WooCommerce predefined elements provider.
 *
 * @since 2.4
 */
class Woocommerce_Predefined_Elements {
	const REMOTE_SCHEMA_VERSION = '1.0.0';
	const REMOTE_CACHE_TTL      = 21600; // 6h
	const REMOTE_TRANSIENT_KEY  = 'bricks_woo_predefined_elements_remote_cache_v1';
	const REMOTE_LAST_GOOD_KEY  = 'bricks_woo_predefined_elements_remote_last_good_v1';

	/**
	 * Cached local presets (loaded once per request).
	 *
	 * @var array|null
	 */
	private $local_presets_cache = null;

	/**
	 * Check if this provider supports the requested generator context.
	 *
	 * @since 2.4
	 *
	 * @param string $generator_type Generator type.
	 * @param string $generator_page Generator page.
	 * @param string $generator_area Generator area.
	 *
	 * @return bool
	 */
	public function supports( $generator_type, $generator_page, $generator_area ) {
		if ( $generator_type !== 'woocommerce' ) {
			return false;
		}

		switch ( $generator_page ) {
			case 'checkout':
				return in_array( $generator_area, [ 'state-checkout', 'state-login', 'state-pay', 'state-thankyou', 'state-receipt' ], true );
			case 'cart':
				return in_array( $generator_area, [ 'state-cart', 'state-empty' ], true );
			case 'dynamic-fragment':
				return $generator_area === 'content';
			case 'myaccount':
				// Saved generator requests must not produce elements that the store cannot register.
				if ( $generator_area === 'state-order-withdrawal' ) {
					return Woocommerce::is_order_withdrawal_enabled();
				}

				return in_array(
					$generator_area,
					[
						'state-dashboard',
						'state-orders',
						'state-view-order',
						'state-downloads',
						'state-addresses',
						'state-edit-address',
						'state-edit-account',
						'state-payment-methods',
						'state-add-payment-method',
						'state-login',
						'state-lost-password',
						'state-lost-password-confirmation',
						'state-reset-password',
					],
					true
				);
			default:
				return false;
		}
	}

	/**
	 * Get normalized preset payload by ID.
	 *
	 * @since 2.4
	 *
	 * @param string $preset_id Preset ID.
	 * @param array  $site_context Site context.
	 *
	 * @return array|false
	 */
	public function get_preset_payload( $preset_id, $site_context = [] ) {
		$preset_id      = sanitize_key( $preset_id );
		$local_presets  = $this->get_local_presets();
		$remote_presets = $this->get_remote_presets( ! empty( $site_context['refresh_remote'] ) );

		$preset = $remote_presets[ $preset_id ] ?? ( $local_presets[ $preset_id ] ?? false );

		if ( ! is_array( $preset ) ) {
			return false;
		}

		$preset['id']            = $preset['id'] ?? $preset_id;
		$preset['source']        = $preset['source'] ?? 'local';
		$preset['presetVersion'] = $preset['presetVersion'] ?? '1.0.0';
		$preset['target']        = $preset['target'] ?? [
			'generatorType' => 'woocommerce',
			'generatorPage' => 'checkout',
			'generatorArea' => 'state-checkout',
		];
		$preset['children']      = isset( $preset['children'] ) && is_array( $preset['children'] ) ? $preset['children'] : [];

		return $preset;
	}

	/**
	 * Validate generated payload.
	 *
	 * @since 2.4
	 *
	 * @param array $payload Payload.
	 *
	 * @return true|\WP_Error
	 */
	public function validate_payload( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['children'] ) || ! is_array( $payload['children'] ) ) {
			return new \WP_Error( 'invalid_payload', esc_html__( 'Invalid predefined payload.', 'bricks' ) );
		}

		$stack = $payload['children'];
		while ( ! empty( $stack ) ) {
			$node = array_shift( $stack );

			if ( ! is_array( $node ) || empty( $node['name'] ) || ! is_string( $node['name'] ) ) {
				return new \WP_Error( 'invalid_payload_node', esc_html__( 'Invalid predefined payload node.', 'bricks' ) );
			}

			switch ( $node['name'] ) {
				case '__checkout_fields__':
					return new \WP_Error( 'invalid_payload_placeholder', esc_html__( 'Unresolved predefined fields placeholder found.', 'bricks' ) );

				case '__account_edit_address_fields__':
					return new \WP_Error( 'invalid_payload_placeholder', esc_html__( 'Unresolved predefined fields placeholder found.', 'bricks' ) );

				case '__preset__':
					return new \WP_Error( 'invalid_payload_preset_placeholder', esc_html__( 'Unresolved predefined preset placeholder found.', 'bricks' ) );
			}

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as $child ) {
					$stack[] = $child;
				}
			}
		}

		return true;
	}

	/**
	 * Merge checkout fields into preset payload.
	 *
	 * @since 2.4
	 *
	 * @param array  $payload Payload.
	 * @param array  $wc_checkout_fields WC checkout fields.
	 * @param array  $existing_keys Existing keys in target parent.
	 * @param string $operation Operation.
	 *
	 * @return array
	 */
	public function merge_checkout_fields( $payload, $wc_checkout_fields, $existing_keys = [], $operation = 'generate' ) {
		// Adapt concrete form-field elements to match the site's WC checkout fields.
		$payload['children'] = $this->adapt_checkout_fields_for_site( $payload['children'], $wc_checkout_fields );

		// Hydrate labels/placeholders for any standalone woocommerce-form-field nodes.
		$payload['children'] = $this->hydrate_form_field_nodes( $payload['children'], $wc_checkout_fields );

		$payload['fieldKeys'] = $this->collect_field_keys_from_children( $payload['children'] );

		return $payload;
	}

	/**
	 * Merge Account edit-address fields into preset payload.
	 *
	 * @since 2.4
	 *
	 * @param array $payload Preset payload.
	 * @param array $account_edit_address_fields Account edit-address fields.
	 * @return array
	 */
	public function merge_account_edit_address_fields( $payload, $account_edit_address_fields ) {
		$payload['children']  = $this->expand_account_edit_address_fields_placeholder( $payload['children'], $account_edit_address_fields );
		$payload['fieldKeys'] = $this->collect_field_keys_from_children( $payload['children'] );

		return $payload;
	}

	/**
	 * Normalize payload for active breakpoints.
	 *
	 * @since 2.4
	 *
	 * @param array $payload Payload.
	 * @param array $active_breakpoints Active breakpoint keys.
	 *
	 * @return array
	 */
	public function normalize_for_breakpoints( $payload, $active_breakpoints = [] ) {
		$active_breakpoints = array_values( array_unique( array_filter( array_map( 'sanitize_key', $active_breakpoints ) ) ) );

		$payload['children'] = $this->normalize_children_for_breakpoints( $payload['children'], $active_breakpoints );

		return $payload;
	}

	/**
	 * Generate payload for requested operation/preset/context.
	 *
	 * @since 2.4
	 *
	 * @param string $operation Operation.
	 * @param string $preset Preset ID.
	 * @param array  $existing_keys Existing field keys.
	 * @param array  $context Context.
	 *
	 * @return array|\WP_Error
	 */
	public function generate( $operation, $preset, $existing_keys = [], $context = [] ) {
		$operation      = sanitize_key( $operation ? $operation : 'generate' );
		$preset         = sanitize_key( $preset );
		$generator_type = isset( $context['generator_type'] ) ? sanitize_key( $context['generator_type'] ) : '';
		$generator_page = isset( $context['generator_page'] ) ? sanitize_key( $context['generator_page'] ) : '';
		$generator_area = isset( $context['generator_area'] ) ? sanitize_key( $context['generator_area'] ) : '';

		if ( ! in_array( $operation, [ 'generate', 'sync-missing', 'audit-fields', 'generate-missing-fields', 'remove-invalid-fields', 'remove-duplicate-fields' ], true ) ) {
			return new \WP_Error( 'invalid_operation', esc_html__( 'Invalid generator operation.', 'bricks' ) );
		}

		$checkout_fields = isset( $context['checkout_fields'] ) && is_array( $context['checkout_fields'] ) ? $context['checkout_fields'] : [];

		// Account edit-address shares the maintenance actions, but audits against Woo address fields instead of Checkout fields.
		$field_maintenance_operations            = [ 'audit-fields', 'generate-missing-fields', 'remove-invalid-fields', 'remove-duplicate-fields' ];
		$checkout_field_operations               = array_merge( [ 'sync-missing' ], $field_maintenance_operations );
		$is_account_edit_address_field_operation = $generator_page === 'myaccount' && $generator_area === 'state-edit-address' && in_array( $operation, $field_maintenance_operations, true );

		if ( in_array( $operation, $checkout_field_operations, true ) && ! $is_account_edit_address_field_operation ) {
			if ( empty( $checkout_fields ) ) {
				return new \WP_Error( 'no_checkout_fields', esc_html__( 'No checkout fields found for this preset.', 'bricks' ) );
			}

			$checkout_fields = $this->get_effective_checkout_fields( $checkout_fields, $context );
		}

		if ( $is_account_edit_address_field_operation ) {
			// Use the billing/shipping union so custom fields added to either endpoint are still surfaced to the builder.
			$account_edit_address_fields = Woocommerce::get_account_edit_address_generation_fields();

			if ( empty( $account_edit_address_fields ) ) {
				return new \WP_Error( 'no_account_edit_address_fields', esc_html__( 'No account address fields found for this preset.', 'bricks' ) );
			}

			$audit = $this->audit_account_edit_address_field_elements( $context['field_elements'] ?? [], $account_edit_address_fields );

			switch ( $operation ) {
				case 'audit-fields':
					return $this->format_account_edit_address_field_audit_response( $audit, esc_html__( 'Account address fields checked.', 'bricks' ) );

				case 'generate-missing-fields':
					$children = $this->get_missing_account_edit_address_field_generation_payload( $audit, $account_edit_address_fields );

					if ( empty( $children ) ) {
						return $this->format_account_edit_address_field_audit_response( $audit, esc_html__( 'No missing account address fields to generate.', 'bricks' ) );
					}

					$response             = $this->format_account_edit_address_field_audit_response( $audit, esc_html__( 'Generated missing account address fields.', 'bricks' ) );
					$response['children'] = $children;

					return $response;

				case 'remove-duplicate-fields':
					$response                        = $this->format_account_edit_address_field_audit_response( $audit, esc_html__( 'Removed duplicate account address fields.', 'bricks' ) );
					$response['duplicateElementIds'] = array_values(
						array_filter(
							array_map(
								function( $field_element ) {
									return $field_element['elementId'] ?? '';
								},
								$audit['duplicateFieldElements']
							)
						)
					);

					return $response;

				case 'remove-invalid-fields':
					$response                      = $this->format_account_edit_address_field_audit_response( $audit, esc_html__( 'Removed invalid account address fields.', 'bricks' ) );
					$response['invalidElementIds'] = array_values(
						array_filter(
							array_map(
								function( $field_element ) {
									return $field_element['elementId'] ?? '';
								},
								$audit['invalidFieldElements']
							)
						)
					);

					return $response;
			}
		}

		if ( in_array( $operation, $field_maintenance_operations, true ) ) {
			$audit = $this->audit_checkout_field_elements( $context['field_elements'] ?? [], $checkout_fields );

			// Field maintenance operations return audit metadata instead of a preset tree.
			switch ( $operation ) {
				case 'audit-fields':
					return $this->format_checkout_field_audit_response( $audit, esc_html__( 'Checkout fields checked.', 'bricks' ) );

				case 'generate-missing-fields':
					$generated_fields = $this->prepare_checkout_field_generation_payload(
						$this->get_missing_checkout_field_generation_payload( $audit, $checkout_fields ),
						$context['account_fields_wrapper_id'] ?? '',
						$checkout_fields
					);
					$children         = $generated_fields['children'];
					$account_children = $generated_fields['account_children'];

					if ( empty( $children ) && empty( $account_children ) ) {
						return $this->format_checkout_field_audit_response( $audit, esc_html__( 'No missing checkout fields to generate.', 'bricks' ) );
					}

					$response                           = $this->format_checkout_field_audit_response( $audit, esc_html__( 'Generated missing checkout fields.', 'bricks' ) );
					$response['children']               = $children;
					$response['accountFieldChildren']   = $account_children;
					$response['accountFieldsWrapperId'] = $generated_fields['account_fields_wrapper_id'];

					return $response;

				case 'remove-duplicate-fields':
					$response                        = $this->format_checkout_field_audit_response( $audit, esc_html__( 'Removed duplicate checkout fields.', 'bricks' ) );
					$response['duplicateElementIds'] = array_values(
						array_filter(
							array_map(
								function( $field_element ) {
									return $field_element['elementId'] ?? '';
								},
								$audit['duplicateFieldElements']
							)
						)
					);

					return $response;

				case 'remove-invalid-fields':
					$response                      = $this->format_checkout_field_audit_response( $audit, esc_html__( 'Removed invalid checkout fields.', 'bricks' ) );
					$response['invalidElementIds'] = array_values(
						array_filter(
							array_map(
								function( $field_element ) {
									return $field_element['elementId'] ?? '';
								},
								$audit['invalidFieldElements']
							)
						)
					);

					return $response;
			}
		}

		$field_preset_sections = $this->get_field_preset_sections();

		// Keep sync-missing as field-centric behavior for field presets.
		if ( $operation === 'sync-missing' ) {
			if ( ! isset( $field_preset_sections[ $preset ] ) ) {
				return new \WP_Error( 'sync_not_supported', esc_html__( 'Sync missing is only available for checkout field presets.', 'bricks' ) );
			}

			$generated = [
				'children'   => [],
				'field_keys' => [],
			];

			foreach ( $field_preset_sections[ $preset ] as $section ) {
				$section_generated       = $this->generate_checkout_field_elements( $section, $checkout_fields, $existing_keys, $operation );
				$generated['children']   = array_merge( $generated['children'], $section_generated['children'] );
				$generated['field_keys'] = array_merge( $generated['field_keys'], $section_generated['field_keys'] );
			}

			$generated_fields = $this->prepare_checkout_field_generation_payload( $generated['children'], $context['account_fields_wrapper_id'] ?? '', $checkout_fields );
			$children         = $generated_fields['children'];
			$account_children = $generated_fields['account_children'];

			if ( empty( $children ) && empty( $account_children ) ) {
				return [
					'children'         => [],
					'operation'        => $operation,
					'missingFieldKeys' => [],
					'missingCount'     => 0,
					'message'          => esc_html__( 'No missing checkout fields found for this preset.', 'bricks' ),
					'presetMeta'       => [
						'id'      => $preset,
						'source'  => 'local',
						'version' => '1.0.0',
					],
				];
			}

			$response = [
				'children'               => $children,
				'accountFieldChildren'   => $account_children,
				'accountFieldsWrapperId' => $generated_fields['account_fields_wrapper_id'],
				'operation'              => $operation,
				'missingFieldKeys'       => $generated['field_keys'],
				'missingCount'           => count( $generated['field_keys'] ),
				'message'                => esc_html__( 'Synced missing checkout fields.', 'bricks' ),
				'presetMeta'             => [
					'id'      => $preset,
					'source'  => 'local',
					'version' => '1.0.0',
				],
			];

			return apply_filters( 'bricks/woocommerce/predefined_elements/generated_payload', $response, $preset, $context );
		}

		if ( $preset === 'complete-checkout-block' && ! empty( $context['multistep'] ) ) {
			$preset_payload = $this->get_preset_payload(
				'complete-checkout-block-multistep',
				[
					'refresh_remote' => ! empty( $context['refresh_remote'] ),
				]
			);
		} else {
			$preset_payload = $this->get_preset_payload(
				$preset,
				[
					'refresh_remote' => ! empty( $context['refresh_remote'] ),
				]
			);
		}

		if ( ! $preset_payload ) {
			return new \WP_Error( 'unknown_preset', esc_html__( 'Preset not implemented yet.', 'bricks' ) );
		}

		$target_element = $context['target_element'] ?? '';
		$preset_target  = $preset_payload['target']['targetElement'] ?? '';

		if ( $target_element && $preset_target && $target_element !== $preset_target ) {
			return new \WP_Error( 'invalid_target', esc_html__( 'Invalid target element.', 'bricks' ) );
		}

		$preset_target_type = isset( $preset_payload['target']['generatorType'] ) ? sanitize_key( $preset_payload['target']['generatorType'] ) : '';
		$preset_target_page = isset( $preset_payload['target']['generatorPage'] ) ? sanitize_key( $preset_payload['target']['generatorPage'] ) : '';
		$preset_target_area = isset( $preset_payload['target']['generatorArea'] ) ? sanitize_key( $preset_payload['target']['generatorArea'] ) : '';

		if (
			( $generator_type && $preset_target_type && $generator_type !== $preset_target_type ) ||
			( $generator_page && $preset_target_page && $generator_page !== $preset_target_page ) ||
			( $generator_area && $preset_target_area && $generator_area !== $preset_target_area )
		) {
			return new \WP_Error( 'invalid_target', esc_html__( 'Invalid generator target.', 'bricks' ) );
		}

		if ( $preset_target_page === 'checkout' || ( ! $preset_target_page && $generator_page === 'checkout' ) ) {
			if ( empty( $checkout_fields ) ) {
				return new \WP_Error( 'no_checkout_fields', esc_html__( 'No checkout fields found for this preset.', 'bricks' ) );
			}

			$checkout_fields = $this->get_effective_checkout_fields( $checkout_fields, $context );
			$preset_payload  = $this->merge_checkout_fields( $preset_payload, $checkout_fields, $existing_keys, $operation );
		} elseif (
			( $preset_target_page === 'myaccount' || ( ! $preset_target_page && $generator_page === 'myaccount' ) ) &&
			( $preset_target_area === 'state-edit-address' || ( ! $preset_target_area && $generator_area === 'state-edit-address' ) )
		) {
			$account_edit_address_fields = Woocommerce::get_account_edit_address_generation_fields();

			if ( empty( $account_edit_address_fields ) ) {
				return new \WP_Error( 'no_account_edit_address_fields', esc_html__( 'No account address fields found for this preset.', 'bricks' ) );
			}

			$preset_payload = $this->merge_account_edit_address_fields( $preset_payload, $account_edit_address_fields );
		} else {
			$preset_payload['fieldKeys'] = [];
		}

		$preset_payload             = $this->normalize_for_breakpoints( $preset_payload, $this->get_active_breakpoint_keys() );
		$preset_payload['children'] = $this->assign_runtime_ids( $preset_payload['children'] );

		$valid = $this->validate_payload( $preset_payload );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$field_keys = isset( $preset_payload['fieldKeys'] ) && is_array( $preset_payload['fieldKeys'] ) ? $preset_payload['fieldKeys'] : $this->collect_field_keys_from_children( $preset_payload['children'] );
		$message    = esc_html__( 'Generated predefined elements.', 'bricks' );

		$response = [
			'children'         => $preset_payload['children'],
			'operation'        => $operation,
			'missingFieldKeys' => $field_keys,
			'missingCount'     => count( $field_keys ),
			'message'          => $message,
			'presetMeta'       => [
				'id'          => $preset_payload['id'] ?? $preset,
				'label'       => $preset_payload['label'] ?? '',
				'description' => $preset_payload['description'] ?? '',
				'source'      => $preset_payload['source'] ?? 'local',
				'version'     => $preset_payload['presetVersion'] ?? '1.0.0',
			],
		];

		if ( ! empty( $preset_payload['globalClasses'] ) && is_array( $preset_payload['globalClasses'] ) ) {
			$response['globalClasses'] = $preset_payload['globalClasses'];
		}

		return apply_filters( 'bricks/woocommerce/predefined_elements/generated_payload', $response, $preset, $context );
	}

	/**
	 * Return local preset definitions.
	 *
	 * Loads preset definitions from JSON files.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_local_presets() {
		if ( $this->local_presets_cache !== null ) {
			return $this->local_presets_cache;
		}

		$presets = $this->load_json_presets();

		$presets = apply_filters( 'bricks/woocommerce/predefined_elements/local_presets', $presets );

		$this->local_presets_cache = is_array( $presets ) ? $presets : [];

		return $this->local_presets_cache;
	}

	/**
	 * Get remote presets keyed by preset ID.
	 *
	 * @since 2.4
	 *
	 * @param bool $force_refresh Force refresh.
	 *
	 * @return array
	 */
	private function get_remote_presets( $force_refresh = false ) {
		// Not implemented yet. Reserved for potential future use
		$remote_payload = $this->get_remote_payload( $force_refresh );
		$presets        = [];

		if ( ! is_array( $remote_payload ) || empty( $remote_payload['presets'] ) || ! is_array( $remote_payload['presets'] ) ) {
			return $presets;
		}

		foreach ( $remote_payload['presets'] as $preset ) {
			$preset_id = isset( $preset['id'] ) ? sanitize_key( $preset['id'] ) : '';
			if ( ! $preset_id || empty( $preset['children'] ) || ! is_array( $preset['children'] ) ) {
				continue;
			}

			$preset['id']          = $preset_id;
			$preset['source']      = 'remote';
			$presets[ $preset_id ] = $preset;
		}

		return $presets;
	}

	/**
	 * Get remote payload with local fallback and last-known-good cache.
	 *
	 * Not in use yet, reserved for potential future use when remote presets are implemented.
	 *
	 * @since 2.4
	 *
	 * @param bool $force_refresh Force refresh.
	 *
	 * @return array|false
	 */
	private function get_remote_payload( $force_refresh = false ) {
		$cached = get_transient( self::REMOTE_TRANSIENT_KEY );
		if ( ! $force_refresh && $this->is_valid_remote_payload( $cached ) ) {
			return $cached;
		}

		$remote_url = apply_filters( 'bricks/woocommerce/predefined_elements/remote_url', '' );
		$remote_url = is_string( $remote_url ) ? trim( $remote_url ) : '';

		if ( ! $remote_url ) {
			$last_good = get_option( self::REMOTE_LAST_GOOD_KEY, false );
			return $this->is_valid_remote_payload( $last_good ) ? $last_good : false;
		}

		$request_url = add_query_arg(
			[
				'schemaVersion' => self::REMOTE_SCHEMA_VERSION,
				'site'          => get_site_url(),
				'time'          => time(),
			],
			$remote_url
		);

		$response = Helpers::remote_get( $request_url );

		if ( is_wp_error( $response ) ) {
			$last_good = get_option( self::REMOTE_LAST_GOOD_KEY, false );
			return $this->is_valid_remote_payload( $last_good ) ? $last_good : false;
		}

		$remote_payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$remote_payload = apply_filters( 'bricks/woocommerce/predefined_elements/remote_payload', $remote_payload );

		if ( ! $this->is_valid_remote_payload( $remote_payload ) ) {
			$last_good = get_option( self::REMOTE_LAST_GOOD_KEY, false );
			return $this->is_valid_remote_payload( $last_good ) ? $last_good : false;
		}

		set_transient( self::REMOTE_TRANSIENT_KEY, $remote_payload, self::REMOTE_CACHE_TTL );
		update_option( self::REMOTE_LAST_GOOD_KEY, $remote_payload, false );

		return $remote_payload;
	}

	/**
	 * Check remote payload contract.
	 *
	 * @since 2.4
	 *
	 * @param mixed $payload Payload.
	 *
	 * @return bool
	 */
	private function is_valid_remote_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		if ( empty( $payload['schemaVersion'] ) || empty( $payload['presets'] ) || ! is_array( $payload['presets'] ) ) {
			return false;
		}

		foreach ( $payload['presets'] as $preset ) {
			if ( empty( $preset['id'] ) || empty( $preset['target'] ) || ! is_array( $preset['target'] ) || empty( $preset['children'] ) || ! is_array( $preset['children'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate checkout field elements for one checkout section.
	 *
	 * Used at runtime for sync-missing operations.
	 *
	 * @since 2.4
	 *
	 * @param string $section Section key.
	 * @param array  $checkout_fields Checkout fields.
	 * @param array  $existing_keys Existing keys.
	 * @param string $operation Operation.
	 *
	 * @return array
	 */
	private function generate_checkout_field_elements( $section, $checkout_fields, $existing_keys, $operation ) {
		$section_fields = isset( $checkout_fields[ $section ] ) && is_array( $checkout_fields[ $section ] ) ? $checkout_fields[ $section ] : [];

		$section_fields = $this->sort_checkout_fields_by_priority( $section_fields );

		$children   = [];
		$field_keys = [];

		foreach ( $section_fields as $field_key => $field_config ) {
			if ( ! is_array( $field_config ) ) {
				continue;
			}

			$field_key = sanitize_text_field( $field_key );
			if ( ! $field_key ) {
				continue;
			}

			if ( $operation === 'sync-missing' && in_array( $field_key, $existing_keys, true ) ) {
				continue;
			}

			$settings = [
				'fieldKey' => $field_key,
			];

			if ( ! empty( $field_config['label'] ) ) {
				$settings['label'] = sanitize_text_field( $field_config['label'] );
			}

			if ( ! empty( $field_config['placeholder'] ) ) {
				$settings['placeholder'] = sanitize_text_field( $field_config['placeholder'] );
			}

			$settings['placeholder'] = ! empty( $settings['placeholder'] ) ? $settings['placeholder'] : ( ! empty( $settings['label'] ) ? $settings['label'] : $field_key );

			$element_label = ! empty( $field_config['label'] ) ? $field_config['label'] : $field_key;

			switch ( $section ) {
				case 'account':
					$element_label = esc_html__( 'Account', 'bricks' ) . ': ' . $element_label;
					break;

				case 'shipping':
					$element_label = esc_html__( 'Shipping', 'bricks' ) . ': ' . $element_label;
					break;

				case 'billing':
					$element_label = esc_html__( 'Billing', 'bricks' ) . ': ' . $element_label;
					break;

				case 'order':
					$element_label = esc_html__( 'Additional', 'bricks' ) . ': ' . $element_label;
					break;
			}

			$children[] = [
				'name'     => 'woocommerce-form-field',
				'settings' => $settings,
				'label'    => $element_label,
			];

			$field_keys[] = $field_key;
		}

		return [
			'children'   => $children,
			'field_keys' => $field_keys,
		];
	}

	/**
	 * Expand Account edit-address field placeholders.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 * @param array $account_edit_address_fields Account edit-address fields.
	 * @return array
	 */
	private function expand_account_edit_address_fields_placeholder( $children, $account_edit_address_fields ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		$expanded = [];

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( ( $child['name'] ?? '' ) === '__account_edit_address_fields__' ) {
				// The placeholder keeps presets compact while still expanding to the site's filtered Woo address fields.
				$expanded = array_merge( $expanded, $this->generate_account_edit_address_field_elements( $account_edit_address_fields ) );
				continue;
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$child['children'] = $this->expand_account_edit_address_fields_placeholder( $child['children'], $account_edit_address_fields );
			}

			$expanded[] = $child;
		}

		return $expanded;
	}

	/**
	 * Generate Account edit-address field elements.
	 *
	 * @since 2.4
	 *
	 * @param array $account_edit_address_fields Account edit-address fields.
	 * @return array
	 */
	private function generate_account_edit_address_field_elements( $account_edit_address_fields ) {
		if ( ! is_array( $account_edit_address_fields ) ) {
			return [];
		}

		$children = [];

		foreach ( $account_edit_address_fields as $field_key => $field_config ) {
			if ( ! is_array( $field_config ) ) {
				continue;
			}

			// Store normalized keys in generated elements; the frontend renderer adds the billing/shipping prefix at runtime.
			$field_key = Woocommerce::normalize_account_edit_address_field_key( $field_key );

			if ( ! $field_key ) {
				continue;
			}

			$label       = ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field_key;
			$placeholder = ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : $label;

			$children[] = [
				'name'     => 'woocommerce-form-field',
				'settings' => [
					'fieldSource'         => 'accountEditAddress',
					'fieldKey'            => $field_key,
					'label'               => $label,
					'placeholder'         => $placeholder,
					'disableRowFirstLast' => true,
				],
				'label'    => esc_html__( 'Address', 'bricks' ) . ': ' . $label,
			];
		}

		return $children;
	}

	/**
	 * Hydrate standalone woocommerce-form-field nodes from checkout fields.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 * @param array $checkout_fields Checkout fields.
	 *
	 * @return array
	 */
	private function hydrate_form_field_nodes( $children, $checkout_fields ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( $this->is_checkout_form_field_element( $child ) ) {
				$field_key = isset( $child['settings']['fieldKey'] ) ? sanitize_text_field( $child['settings']['fieldKey'] ) : '';

				if ( $field_key ) {
					$field_config = $this->find_checkout_field_config( $field_key, $checkout_fields );

					if ( is_array( $field_config ) ) {
						if ( empty( $child['settings']['label'] ) && ! empty( $field_config['label'] ) ) {
							$children[ $index ]['settings']['label'] = sanitize_text_field( $field_config['label'] );
						}

						if ( empty( $child['settings']['placeholder'] ) && ! empty( $field_config['placeholder'] ) ) {
							$children[ $index ]['settings']['placeholder'] = sanitize_text_field( $field_config['placeholder'] );
						}
					}
				}
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$children[ $index ]['children'] = $this->hydrate_form_field_nodes( $child['children'], $checkout_fields );
			}
		}

		return $children;
	}

	/**
	 * Assign runtime element IDs and normalize dynamic references.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 *
	 * @return array
	 */
	private function assign_runtime_ids( $children ) {
		$id_map   = [];
		$children = $this->assign_runtime_ids_recursive( $children, $id_map );

		return $this->replace_runtime_references_recursive( $children, $id_map );
	}

	/**
	 * Assign runtime IDs to all nodes while mapping source IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 * @param array $id_map Source to runtime ID map.
	 *
	 * @return array
	 */
	private function assign_runtime_ids_recursive( $children, &$id_map ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) || empty( $child['name'] ) ) {
				unset( $children[ $index ] );
				continue;
			}

			$source_id  = isset( $child['id'] ) && is_string( $child['id'] ) ? sanitize_key( $child['id'] ) : '';
			$runtime_id = Helpers::generate_random_id( false );

			$children[ $index ]['id'] = $runtime_id;

			if ( $source_id ) {
				$id_map[ $source_id ] = $runtime_id;
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$children[ $index ]['children'] = $this->assign_runtime_ids_recursive( $child['children'], $id_map );
			}
		}

		return array_values( $children );
	}

	/**
	 * Replace dynamic tags and root selectors using runtime IDs.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 * @param array $id_map Source to runtime ID map.
	 *
	 * @return array
	 */
	private function replace_runtime_references_recursive( $children, $id_map ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) || empty( $child['name'] ) ) {
				unset( $children[ $index ] );
				continue;
			}

			$element_id = isset( $child['id'] ) && is_string( $child['id'] ) ? $child['id'] : '';

			if ( ! empty( $child['settings'] ) && is_array( $child['settings'] ) ) {
				$children[ $index ]['settings'] = $this->replace_runtime_value_recursive( $child['settings'], $id_map, $element_id );
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$children[ $index ]['children'] = $this->replace_runtime_references_recursive( $child['children'], $id_map );
			}
		}

		return array_values( $children );
	}

	/**
	 * Replace runtime tokens in any value shape.
	 *
	 * @since 2.4
	 *
	 * @param mixed  $value Value.
	 * @param array  $id_map Source to runtime ID map.
	 * @param string $element_id Current element ID.
	 * @param string $setting_key Current setting key.
	 *
	 * @return mixed
	 */
	private function replace_runtime_value_recursive( $value, $id_map, $element_id = '', $setting_key = '' ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->replace_runtime_value_recursive( $item, $id_map, $element_id, $key );
			}

			return $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( $setting_key === 'queryId' && isset( $id_map[ $value ] ) ) {
			return $id_map[ $value ];
		}

		$value = preg_replace_callback(
			'/\{query_results_count:([^}]+)\}/',
			function( $matches ) use ( $id_map ) {
				$source_id  = sanitize_key( $matches[1] );
				$runtime_id = $id_map[ $source_id ] ?? '';

				return $runtime_id ? '{query_results_count:' . $runtime_id . '}' : $matches[0];
			},
			$value
		);

		// Remap literal #brxe-{sourceId} selectors from clipboard JSON to runtime IDs.
		if ( strpos( $value, '#brxe-' ) !== false ) {
			foreach ( $id_map as $source_id => $runtime_id ) {
				if ( strpos( $value, '#brxe-' . $source_id ) !== false ) {
					$value = str_replace( '#brxe-' . $source_id, '#brxe-' . $runtime_id, $value );
				}
			}
		}

		return $value;
	}

	/**
	 * Normalize children settings for active breakpoints.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 * @param array $active_breakpoints Active breakpoints.
	 *
	 * @return array
	 */
	private function normalize_children_for_breakpoints( $children, $active_breakpoints ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				unset( $children[ $index ] );
				continue;
			}

			if ( ! empty( $child['settings'] ) && is_array( $child['settings'] ) ) {
				$children[ $index ]['settings'] = $this->normalize_settings_for_breakpoints( $child['settings'], $active_breakpoints );
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$children[ $index ]['children'] = $this->normalize_children_for_breakpoints( $child['children'], $active_breakpoints );
			}
		}

		return array_values( $children );
	}

	/**
	 * Keep base settings and only keep safe responsive overrides.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Settings.
	 * @param array $active_breakpoints Active breakpoint keys.
	 *
	 * @return array
	 */
	private function normalize_settings_for_breakpoints( $settings, $active_breakpoints ) {
		$normalized = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || strpos( $key, ':' ) === false ) {
				$normalized[ $key ] = $value;
				continue;
			}

			$parts = explode( ':', $key );

			// No responsive segment in second position: keep as-is.
			if ( empty( $parts[1] ) ) {
				$normalized[ $key ] = $value;
				continue;
			}

			$responsive_key = sanitize_key( $parts[1] );

			// Keep when the responsive segment is an active breakpoint.
			if ( in_array( $responsive_key, $active_breakpoints, true ) ) {
				$normalized[ $key ] = $value;
				continue;
			}

			// Drop likely breakpoint-specific settings when breakpoint key isn't active.
			if ( $this->looks_like_breakpoint_key( $responsive_key ) ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Check if the key likely represents a breakpoint key.
	 *
	 * @since 2.4
	 *
	 * @param string $key Key.
	 *
	 * @return bool
	 */
	private function looks_like_breakpoint_key( $key ) {
		$known_default = [ 'desktop', 'tablet_portrait', 'mobile_landscape', 'mobile_portrait' ];

		if ( in_array( $key, $known_default, true ) ) {
			return true;
		}

		if ( strpos( $key, 'mobile' ) === 0 || strpos( $key, 'tablet' ) === 0 || strpos( $key, 'desktop' ) === 0 ) {
			return true;
		}

		// Most custom breakpoint keys in Bricks are lower-case and underscore-based.
		return (bool) preg_match( '/^[a-z0-9]+(?:_[a-z0-9]+)+$/', $key );
	}

	/**
	 * Get the checkout sections maintained by each field preset.
	 *
	 * WooCommerce renders account creation from its billing-form lifecycle, so the
	 * existing Billing fields preset owns both billing and account fields.
	 *
	 * @since 2.4
	 * @since 2.4 The Billing fields preset also maintains checkout account fields.
	 *
	 * @return array<string, string[]>
	 */
	private function get_field_preset_sections() {
		return [
			'checkout-fields-billing'                => [ 'billing', 'account' ],
			'checkout-fields-shipping'               => [ 'shipping' ],
			'additional-fields'                      => [ 'order' ],
			'checkout-fields-additional-information' => [ 'order' ],
		];
	}

	/**
	 * Find checkout field config by key across sections.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $checkout_fields Checkout fields.
	 *
	 * @return array|false
	 */
	private function find_checkout_field_config( $field_key, $checkout_fields ) {
		foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $section ) {
			if ( ! empty( $checkout_fields[ $section ][ $field_key ] ) && is_array( $checkout_fields[ $section ][ $field_key ] ) ) {
				return $checkout_fields[ $section ][ $field_key ];
			}
		}

		return false;
	}

	/**
	 * Get active breakpoint keys.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_active_breakpoint_keys() {
		$keys        = [];
		$breakpoints = Breakpoints::get_breakpoints();

		if ( is_array( $breakpoints ) ) {
			foreach ( $breakpoints as $breakpoint ) {
				if ( empty( $breakpoint['key'] ) || ! empty( $breakpoint['paused'] ) ) {
					continue;
				}

				$keys[] = sanitize_key( $breakpoint['key'] );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Collect all checkout field keys found in children.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 *
	 * @return array
	 */
	private function collect_field_keys_from_children( $children ) {
		$field_keys = [];

		if ( ! is_array( $children ) ) {
			return $field_keys;
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( ( $child['name'] ?? '' ) === 'woocommerce-form-field' && ! empty( $child['settings']['fieldKey'] ) ) {
				$field_keys[] = sanitize_text_field( $child['settings']['fieldKey'] );
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$field_keys = array_merge( $field_keys, $this->collect_field_keys_from_children( $child['children'] ) );
			}
		}

		return array_values( array_unique( array_filter( $field_keys ) ) );
	}

	/**
	 * Collect checkout field keys found in children.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children.
	 *
	 * @return array
	 */
	private function collect_checkout_field_keys_from_children( $children ) {
		$field_keys = [];

		if ( ! is_array( $children ) ) {
			return $field_keys;
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( $this->is_checkout_form_field_element( $child ) && ! empty( $child['settings']['fieldKey'] ) ) {
				$field_keys[] = sanitize_text_field( $child['settings']['fieldKey'] );
			}

			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$field_keys = array_merge( $field_keys, $this->collect_checkout_field_keys_from_children( $child['children'] ) );
			}
		}

		return array_values( array_unique( array_filter( $field_keys ) ) );
	}

	/**
	 * Check whether an element is a Checkout form field.
	 *
	 * Account auth forms also reuse woocommerce-form-field and must not be
	 * validated against the checkout field registry.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element data.
	 *
	 * @return bool
	 */
	private function is_checkout_form_field_element( $element ) {
		if ( ! is_array( $element ) || ( $element['name'] ?? '' ) !== 'woocommerce-form-field' ) {
			return false;
		}

		$field_source = isset( $element['settings']['fieldSource'] ) ? sanitize_key( $element['settings']['fieldSource'] ) : '';

		return ! $field_source || $field_source === 'checkout';
	}

	/**
	 * Apply checkout-state field removals before auditing/generation.
	 *
	 * @since 2.4
	 *
	 * @param array $checkout_fields Checkout fields.
	 * @param array $context Request context.
	 *
	 * @return array
	 */
	private function get_effective_checkout_fields( $checkout_fields, $context ) {
		if ( ! is_array( $checkout_fields ) ) {
			return [];
		}

		$remove_billing_fields  = isset( $context['remove_billing_fields'] ) && is_array( $context['remove_billing_fields'] ) ? array_map( 'sanitize_text_field', $context['remove_billing_fields'] ) : [];
		$remove_shipping_fields = isset( $context['remove_shipping_fields'] ) && is_array( $context['remove_shipping_fields'] ) ? array_map( 'sanitize_text_field', $context['remove_shipping_fields'] ) : [];

		foreach ( $remove_billing_fields as $field_key ) {
			unset( $checkout_fields['billing'][ $field_key ] );
		}

		foreach ( $remove_shipping_fields as $field_key ) {
			unset( $checkout_fields['shipping'][ $field_key ] );
		}

		return $checkout_fields;
	}

	/**
	 * Sort checkout fields by WooCommerce priority.
	 *
	 * @since 2.4
	 *
	 * @param array $fields Checkout fields.
	 *
	 * @return array
	 */
	private function sort_checkout_fields_by_priority( $fields ) {
		if ( ! is_array( $fields ) ) {
			return [];
		}

		uasort(
			$fields,
			function( $field_a, $field_b ) {
				$priority_a = isset( $field_a['priority'] ) ? intval( $field_a['priority'] ) : PHP_INT_MAX;
				$priority_b = isset( $field_b['priority'] ) ? intval( $field_b['priority'] ) : PHP_INT_MAX;

				return $priority_a <=> $priority_b;
			}
		);

		return $fields;
	}

	/**
	 * Audit posted checkout field elements against the current field registry.
	 *
	 * @since 2.4
	 *
	 * @param array $field_elements Field elements from builder.
	 * @param array $checkout_fields Effective checkout fields.
	 *
	 * @return array
	 */
	private function audit_checkout_field_elements( $field_elements, $checkout_fields ) {
		$valid_fields              = $this->get_checkout_field_registry( $checkout_fields );
		$existing_valid_field_keys = [];
		$invalid_field_elements    = [];
		$duplicate_field_elements  = [];
		$seen_valid_field_keys     = [];

		foreach ( $field_elements as $field_element ) {
			if ( ! is_array( $field_element ) ) {
				continue;
			}

			$element_id = isset( $field_element['id'] ) ? sanitize_text_field( $field_element['id'] ) : '';
			$field_key  = isset( $field_element['fieldKey'] ) ? sanitize_text_field( $field_element['fieldKey'] ) : '';
			$label      = isset( $field_element['label'] ) ? sanitize_text_field( $field_element['label'] ) : '';

			if ( ! $field_key ) {
				continue;
			}

			if ( isset( $valid_fields[ $field_key ] ) ) {
				if ( isset( $seen_valid_field_keys[ $field_key ] ) ) {
					$duplicate_field_elements[] = [
						'elementId'    => $element_id,
						'fieldKey'     => $field_key,
						'label'        => $label ? $label : $valid_fields[ $field_key ]['label'],
						'section'      => $valid_fields[ $field_key ]['section'],
						'sectionLabel' => $valid_fields[ $field_key ]['sectionLabel'],
					];
					continue;
				}

				$seen_valid_field_keys[ $field_key ] = true;
				$existing_valid_field_keys[]         = $field_key;
				continue;
			}

			$invalid_field_elements[] = [
				'elementId' => $element_id,
				'fieldKey'  => $field_key,
				'label'     => $label ? $label : $field_key,
				'section'   => $this->guess_checkout_field_section_from_key( $field_key ),
			];
		}

		$existing_valid_field_keys = array_values( array_unique( array_filter( $existing_valid_field_keys ) ) );
		$missing_field_keys        = array_values( array_diff( array_keys( $valid_fields ), $existing_valid_field_keys ) );
		$missing_fields            = [];

		foreach ( $missing_field_keys as $field_key ) {
			$missing_fields[] = $valid_fields[ $field_key ];
		}

		return [
			'validFields'            => $valid_fields,
			'validFieldKeys'         => array_keys( $valid_fields ),
			'existingValidFieldKeys' => $existing_valid_field_keys,
			'missingFieldKeys'       => $missing_field_keys,
			'missingFields'          => $missing_fields,
			'invalidFieldElements'   => $invalid_field_elements,
			'duplicateFieldElements' => $duplicate_field_elements,
			'missingCount'           => count( $missing_field_keys ),
			'invalidCount'           => count( $invalid_field_elements ),
			'duplicateCount'         => count( $duplicate_field_elements ),
		];
	}

	/**
	 * Format the checkout field audit response for the builder.
	 *
	 * @since 2.4
	 *
	 * @param array  $audit Audit data.
	 * @param string $message Response message.
	 *
	 * @return array
	 */
	private function format_checkout_field_audit_response( $audit, $message ) {
		return [
			'children'               => [],
			'operation'              => 'audit-fields',
			'message'                => $message,
			'validFieldKeys'         => $audit['validFieldKeys'],
			'existingValidFieldKeys' => $audit['existingValidFieldKeys'],
			'missingFieldKeys'       => $audit['missingFieldKeys'],
			'missingFields'          => $audit['missingFields'],
			'missingCount'           => $audit['missingCount'],
			'invalidFieldElements'   => $audit['invalidFieldElements'],
			'invalidCount'           => $audit['invalidCount'],
			'duplicateFieldElements' => $audit['duplicateFieldElements'],
			'duplicateCount'         => $audit['duplicateCount'],
		];
	}

	/**
	 * Build a lookup of valid checkout fields.
	 *
	 * @since 2.4
	 * @since 2.4 Includes WooCommerce's conditional checkout account fields.
	 *
	 * @param array $checkout_fields Effective checkout fields.
	 *
	 * @return array
	 */
	private function get_checkout_field_registry( $checkout_fields ) {
		$registry = [];

		foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $section ) {
			$section_fields = isset( $checkout_fields[ $section ] ) && is_array( $checkout_fields[ $section ] ) ? $this->sort_checkout_fields_by_priority( $checkout_fields[ $section ] ) : [];

			foreach ( $section_fields as $field_key => $field_config ) {
				if ( ! is_array( $field_config ) ) {
					continue;
				}

				$field_key = sanitize_text_field( $field_key );

				if ( ! $field_key ) {
					continue;
				}

				$registry[ $field_key ] = [
					'fieldKey'     => $field_key,
					'label'        => ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field_key,
					'placeholder'  => ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : '',
					'section'      => $section,
					'sectionLabel' => $this->get_checkout_section_label( $section ),
				];
			}
		}

		return $registry;
	}

	/**
	 * Generate payload for missing checkout fields.
	 *
	 * @since 2.4
	 *
	 * @param array $audit Audit data.
	 * @param array $checkout_fields Effective checkout fields.
	 *
	 * @return array
	 */
	private function get_missing_checkout_field_generation_payload( $audit, $checkout_fields ) {
		if ( empty( $audit['missingFieldKeys'] ) ) {
			return [];
		}

		$children = [];

		foreach ( $audit['missingFieldKeys'] as $field_key ) {
			$field_config = $this->find_checkout_field_config( $field_key, $checkout_fields );

			if ( ! is_array( $field_config ) ) {
				continue;
			}

			$section = $this->get_checkout_field_section( $field_key, $checkout_fields );

			$children[] = [
				'name'     => 'woocommerce-form-field',
				'settings' => array_filter(
					[
						'fieldKey'    => $field_key,
						'label'       => ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : '',
						'placeholder' => ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : '',
					]
				),
				'label'    => $this->get_generated_checkout_field_label( $field_key, $field_config, $section ),
			];
		}

		return $children;
	}

	/**
	 * Route generated account fields into their required lifecycle wrapper.
	 *
	 * Account credential fields must never be appended directly to the Checkout
	 * state: optional registration relies on the wrapper's create-account toggle.
	 * The field list comes from WooCommerce's live registry, so generation and audit
	 * automatically follow the current username/password generation settings.
	 *
	 * @since 2.4
	 *
	 * @param array  $children Generated checkout field elements.
	 * @param string $account_fields_wrapper_id Existing account wrapper ID.
	 * @param array  $checkout_fields Effective WooCommerce checkout fields.
	 *
	 * @return array
	 */
	private function prepare_checkout_field_generation_payload( $children, $account_fields_wrapper_id = '', $checkout_fields = [] ) {
		$account_fields_wrapper_id = sanitize_text_field( $account_fields_wrapper_id );
		$root_children             = [];
		$account_children          = [];

		foreach ( $children as $child ) {
			$field_key = isset( $child['settings']['fieldKey'] ) ? sanitize_text_field( $child['settings']['fieldKey'] ) : '';

			if ( $this->get_checkout_field_section( $field_key, $checkout_fields ) === 'account' ) {
				$account_children[] = $child;
				continue;
			}

			$root_children[] = $child;
		}

		// Older beta structures may not have the wrapper. Create the smallest valid
		// lifecycle structure rather than leaving credentials at the state root. (#86cb33dre; @since 2.4)
		if ( ! $account_fields_wrapper_id && ! empty( $account_children ) ) {
			$account_fields_wrapper_id = Helpers::generate_random_id( false );
			$root_children[]           = [
				'id'       => $account_fields_wrapper_id,
				'name'     => 'woocommerce-checkout-account-fields',
				'label'    => esc_html__( 'Checkout account fields', 'bricks' ),
				'settings' => [],
				'children' => array_merge(
					[
						[
							'name'     => 'form-checkbox',
							'label'    => esc_html__( 'Create account', 'bricks' ),
							'settings' => [
								'wooFields' => 'createaccount',
							],
						],
					],
					$account_children
				),
			];
			$account_children          = [];
		}

		return [
			'children'                  => $root_children,
			'account_children'          => $account_children,
			'account_fields_wrapper_id' => $account_fields_wrapper_id,
		];
	}

	/**
	 * Audit posted Account edit-address field elements against the current address field registry.
	 *
	 * @since 2.4
	 *
	 * @param array $field_elements Field elements from builder.
	 * @param array $account_edit_address_fields Account edit-address fields.
	 *
	 * @return array
	 */
	private function audit_account_edit_address_field_elements( $field_elements, $account_edit_address_fields ) {
		$valid_fields              = $this->get_account_edit_address_field_registry( $account_edit_address_fields );
		$existing_valid_field_keys = [];
		$invalid_field_elements    = [];
		$duplicate_field_elements  = [];
		$seen_valid_field_keys     = [];

		foreach ( $field_elements as $field_element ) {
			if ( ! is_array( $field_element ) ) {
				continue;
			}

			$element_id   = isset( $field_element['id'] ) ? sanitize_text_field( $field_element['id'] ) : '';
			$field_key    = isset( $field_element['fieldKey'] ) ? Woocommerce::normalize_account_edit_address_field_key( $field_element['fieldKey'] ) : '';
			$field_source = isset( $field_element['fieldSource'] ) ? sanitize_text_field( $field_element['fieldSource'] ) : '';
			$label        = isset( $field_element['label'] ) ? sanitize_text_field( $field_element['label'] ) : '';

			if ( ! $field_key ) {
				continue;
			}

			// No-source fields are accepted for backwards compatibility, but explicit Checkout fields are invalid here.
			$is_valid_account_source = $field_source === '' || $field_source === 'accountEditAddress';

			if ( $is_valid_account_source && isset( $valid_fields[ $field_key ] ) ) {
				if ( isset( $seen_valid_field_keys[ $field_key ] ) ) {
					$duplicate_field_elements[] = [
						'elementId'    => $element_id,
						'fieldKey'     => $field_key,
						'label'        => $label ? $label : $valid_fields[ $field_key ]['label'],
						'section'      => $valid_fields[ $field_key ]['section'],
						'sectionLabel' => $valid_fields[ $field_key ]['sectionLabel'],
					];
					continue;
				}

				$seen_valid_field_keys[ $field_key ] = true;
				$existing_valid_field_keys[]         = $field_key;
				continue;
			}

			$invalid_field_elements[] = [
				'elementId' => $element_id,
				'fieldKey'  => $field_key,
				'label'     => $label ? $label : $field_key,
				'section'   => 'address',
			];
		}

		$existing_valid_field_keys = array_values( array_unique( array_filter( $existing_valid_field_keys ) ) );
		$missing_field_keys        = array_values( array_diff( array_keys( $valid_fields ), $existing_valid_field_keys ) );
		$missing_fields            = [];

		foreach ( $missing_field_keys as $field_key ) {
			$missing_fields[] = $valid_fields[ $field_key ];
		}

		return [
			'validFields'            => $valid_fields,
			'validFieldKeys'         => array_keys( $valid_fields ),
			'existingValidFieldKeys' => $existing_valid_field_keys,
			'missingFieldKeys'       => $missing_field_keys,
			'missingFields'          => $missing_fields,
			'invalidFieldElements'   => $invalid_field_elements,
			'duplicateFieldElements' => $duplicate_field_elements,
			'missingCount'           => count( $missing_field_keys ),
			'invalidCount'           => count( $invalid_field_elements ),
			'duplicateCount'         => count( $duplicate_field_elements ),
		];
	}

	/**
	 * Format the Account edit-address field audit response for the builder.
	 *
	 * @since 2.4
	 *
	 * @param array  $audit Audit data.
	 * @param string $message Response message.
	 *
	 * @return array
	 */
	private function format_account_edit_address_field_audit_response( $audit, $message ) {
		return [
			'children'               => [],
			'operation'              => 'audit-fields',
			'message'                => $message,
			'validFieldKeys'         => $audit['validFieldKeys'],
			'existingValidFieldKeys' => $audit['existingValidFieldKeys'],
			'missingFieldKeys'       => $audit['missingFieldKeys'],
			'missingFields'          => $audit['missingFields'],
			'missingCount'           => $audit['missingCount'],
			'invalidFieldElements'   => $audit['invalidFieldElements'],
			'invalidCount'           => $audit['invalidCount'],
			'duplicateFieldElements' => $audit['duplicateFieldElements'],
			'duplicateCount'         => $audit['duplicateCount'],
		];
	}

	/**
	 * Build a lookup of valid Account edit-address fields.
	 *
	 * @since 2.4
	 *
	 * @param array $account_edit_address_fields Account edit-address fields.
	 *
	 * @return array
	 */
	private function get_account_edit_address_field_registry( $account_edit_address_fields ) {
		$registry = [];

		if ( ! is_array( $account_edit_address_fields ) ) {
			return $registry;
		}

		foreach ( $account_edit_address_fields as $field_key => $field_config ) {
			if ( ! is_array( $field_config ) ) {
				continue;
			}

			$field_key = Woocommerce::normalize_account_edit_address_field_key( $field_key );

			if ( ! $field_key ) {
				continue;
			}

			$registry[ $field_key ] = [
				'fieldKey'     => $field_key,
				'label'        => ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field_key,
				'placeholder'  => ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : '',
				'section'      => 'address',
				'sectionLabel' => esc_html__( 'Address', 'bricks' ),
			];
		}

		return $registry;
	}

	/**
	 * Generate payload for missing Account edit-address fields.
	 *
	 * @since 2.4
	 *
	 * @param array $audit Audit data.
	 * @param array $account_edit_address_fields Account edit-address fields.
	 *
	 * @return array
	 */
	private function get_missing_account_edit_address_field_generation_payload( $audit, $account_edit_address_fields ) {
		if ( empty( $audit['missingFieldKeys'] ) || ! is_array( $account_edit_address_fields ) ) {
			return [];
		}

		$fields_to_generate = [];

		foreach ( $audit['missingFieldKeys'] as $field_key ) {
			$field_key = Woocommerce::normalize_account_edit_address_field_key( $field_key );

			if ( ! $field_key || empty( $account_edit_address_fields[ $field_key ] ) || ! is_array( $account_edit_address_fields[ $field_key ] ) ) {
				continue;
			}

			$fields_to_generate[ $field_key ] = $account_edit_address_fields[ $field_key ];
		}

		return $this->generate_account_edit_address_field_elements( $fields_to_generate );
	}

	/**
	 * Get a user-facing label for a checkout section.
	 *
	 * @since 2.4
	 *
	 * @param string $section Section key.
	 *
	 * @return string
	 */
	private function get_checkout_section_label( $section ) {
		switch ( $section ) {
			case 'account':
				return esc_html__( 'Account', 'bricks' );

			case 'shipping':
				return esc_html__( 'Shipping', 'bricks' );

			case 'order':
				return esc_html__( 'Additional', 'bricks' );

			case 'billing':
			default:
				return esc_html__( 'Billing', 'bricks' );
		}
	}

	/**
	 * Get the section for a checkout field key.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $checkout_fields Effective checkout fields.
	 *
	 * @return string
	 */
	private function get_checkout_field_section( $field_key, $checkout_fields ) {
		foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $section ) {
			if ( ! empty( $checkout_fields[ $section ][ $field_key ] ) ) {
				return $section;
			}
		}

		return '';
	}

	/**
	 * Build the generated element label for a checkout field.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 * @param array  $field_config Field config.
	 * @param string $section Section key.
	 *
	 * @return string
	 */
	private function get_generated_checkout_field_label( $field_key, $field_config, $section ) {
		$element_label = ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field_key;

		if ( $section ) {
			$element_label = $this->get_checkout_section_label( $section ) . ': ' . $element_label;
		}

		return $element_label;
	}

	/**
	 * Guess the checkout section from a field key.
	 *
	 * @since 2.4
	 *
	 * @param string $field_key Field key.
	 *
	 * @return string
	 */
	private function guess_checkout_field_section_from_key( $field_key ) {
		$field_key = sanitize_text_field( $field_key );

		if ( strpos( $field_key, 'shipping_' ) === 0 ) {
			return 'shipping';
		}

		if ( strpos( $field_key, 'billing_' ) === 0 ) {
			return 'billing';
		}

		if ( strpos( $field_key, 'account_' ) === 0 ) {
			return 'account';
		}

		return 'order';
	}

	/**
	 * Recursively glob JSON files from the presets directory, skipping raw/ subdirectories.
	 *
	 * @since 2.4
	 *
	 * @param string $dir Base directory to scan.
	 *
	 * @return array Array of JSON file paths.
	 */
	private static function glob_json_presets( $dir ) {
		$files = [];

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		foreach ( glob( "$dir/*.json" ) as $file ) {
			$files[] = $file;
		}

		foreach ( glob( "$dir/*", GLOB_ONLYDIR ) as $sub_dir ) {
			// Skip raw/ directories — those are source files for the build script.
			if ( basename( $sub_dir ) === 'raw' ) {
				continue;
			}

			$files = array_merge( $files, self::glob_json_presets( $sub_dir ) );
		}

		return $files;
	}

	/**
	 * Load presets from JSON files in the presets directory.
	 *
	 * JSON files use the Bricks clipboard format (flat array with id/parent references).
	 * This method reads each file, converts flat to nested, translates strings, and
	 * returns an array keyed by preset ID.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function load_json_presets() {
		$presets    = [];
		$dir        = BRICKS_PATH . 'includes/woocommerce/presets';
		$json_files = self::glob_json_presets( $dir );

		if ( empty( $json_files ) ) {
			return $presets;
		}

		$translation_map = $this->get_preset_translation_map();

		foreach ( $json_files as $file ) {
			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! $raw ) {
				continue;
			}

			$data = json_decode( $raw, true );

			if ( ! is_array( $data ) || empty( $data['id'] ) ) {
				continue;
			}

			$preset_id = sanitize_key( $data['id'] );

			if ( ! $preset_id ) {
				continue;
			}

			// Convert flat clipboard content to nested children tree.
			if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
				$data['children'] = $this->convert_flat_to_nested( $data['content'] );
				unset( $data['content'] );
			}

			// Strip clipboard metadata.
			unset( $data['source'], $data['sourceUrl'], $data['version'] );

			// Apply translations.
			if ( ! empty( $data['children'] ) && is_array( $data['children'] ) ) {
				$data['children'] = $this->translate_preset_strings( $data['children'], $translation_map );
			}

			$data['source']        = 'local';
			$presets[ $preset_id ] = $data;
		}

		return $presets;
	}

	/**
	 * Convert flat clipboard-format elements to a nested children tree.
	 *
	 * The clipboard format stores each element as a flat object with 'id', 'parent',
	 * and 'children' (an array of child IDs). This method rebuilds the hierarchy
	 * and strips the id/parent fields so assign_runtime_ids() can generate fresh ones.
	 *
	 * @since 2.4
	 *
	 * @param array $flat_elements Flat elements from clipboard JSON.
	 *
	 * @return array Nested children tree.
	 */
	private function convert_flat_to_nested( $flat_elements ) {
		if ( ! is_array( $flat_elements ) ) {
			return [];
		}

		$index = [];
		foreach ( $flat_elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}
			$index[ $element['id'] ] = $element;
		}

		// Find root elements: those whose parent is not in the index.
		$root_ids = [];
		foreach ( $flat_elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}
			$parent = $element['parent'] ?? '';
			if ( ! isset( $index[ $parent ] ) ) {
				$root_ids[] = $element['id'];
			}
		}

		$build = function( $id ) use ( &$index, &$build ) {
			if ( ! isset( $index[ $id ] ) ) {
				return null;
			}

			$element  = $index[ $id ];
			$children = [];

			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as $child_id ) {
					$child = $build( $child_id );
					if ( $child ) {
						$children[] = $child;
					}
				}
			}

			// Strip flat-format fields; assign_runtime_ids() will generate new ones.
			unset( $element['parent'] );

			// Keep the source id for reference resolution (e.g. query_results_count:ID, _cssCustom #brxe-ID).
			// assign_runtime_ids() will remap these.
			$element['children'] = $children;

			// Strip extra clipboard fields.
			unset( $element['themeStyles'] );

			return $element;
		};

		$roots = [];
		foreach ( $root_ids as $root_id ) {
			$root = $build( $root_id );
			if ( $root ) {
				$roots[] = $root;
			}
		}

		return $roots;
	}

	/**
	 * Return the translation map for known user-facing preset strings.
	 *
	 * Keys are the English strings as they appear in the JSON files.
	 * Values are translated without escaping so trusted preset HTML stays intact.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private function get_preset_translation_map() {
		$map = [];
		// Merge auto-generated translation strings from build-presets.js.
		$generated_file = BRICKS_PATH . 'includes/woocommerce/presets/translation-strings.php';

		if ( file_exists( $generated_file ) ) {
			$generated = include $generated_file;

			if ( is_array( $generated ) ) {
				// Generated strings fill gaps; manual entries above take precedence.
				$map = array_merge( $generated, $map );
			}
		}

		return $map;
	}

	/**
	 * Apply translations to known user-facing strings in the children tree.
	 *
	 * @since 2.4
	 *
	 * @param array $children Nested children.
	 * @param array $map Translation map.
	 *
	 * @return array
	 */
	private function translate_preset_strings( $children, $map ) {
		if ( ! is_array( $children ) ) {
			return [];
		}

		foreach ( $children as &$child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$child = $this->translate_preset_element_strings( $child, $map );

			// Recurse into children.
			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$child['children'] = $this->translate_preset_strings( $child['children'], $map );
			}
		}

		return $children;
	}

	/**
	 * Apply translations to known user-facing element strings.
	 *
	 * @since 2.4
	 *
	 * @param array $element Element.
	 * @param array $map Translation map.
	 *
	 * @return array
	 */
	private function translate_preset_element_strings( $element, $map ) {
		if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = $this->translate_preset_settings_strings( $element['settings'], $map );
		}

		if ( ! empty( $element['label'] ) && is_string( $element['label'] ) ) {
			$element['label'] = $this->translate_preset_string( $element['label'], $map );
		}

		return $element;
	}

	/**
	 * Apply translations to known user-facing settings strings.
	 *
	 * @since 2.4
	 *
	 * @param array $settings Element settings.
	 * @param array $map Translation map.
	 *
	 * @return array
	 */
	private function translate_preset_settings_strings( $settings, $map ) {
		if ( isset( $settings['text'] ) && is_string( $settings['text'] ) ) {
			$settings['text'] = $this->translate_preset_string( $settings['text'], $map );
		}

		if ( isset( $settings['buttonText'] ) && is_string( $settings['buttonText'] ) ) {
			$settings['buttonText'] = $this->translate_preset_string( $settings['buttonText'], $map );
		}

		foreach ( [ 'label', 'placeholder', 'stepLabel', 'title', 'ariaLabel' ] as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = $this->translate_preset_string( $settings[ $key ], $map );
			}
		}

		if ( ! empty( $settings['bars'] ) && is_array( $settings['bars'] ) ) {
			foreach ( $settings['bars'] as &$bar ) {
				if ( isset( $bar['title'] ) && is_string( $bar['title'] ) ) {
					$bar['title'] = $this->translate_preset_string( $bar['title'], $map );
				}
			}
			unset( $bar );
		}

		if ( ! empty( $settings['link'] ) && is_array( $settings['link'] ) ) {
			foreach ( [ 'title', 'ariaLabel' ] as $key ) {
				if ( isset( $settings['link'][ $key ] ) && is_string( $settings['link'][ $key ] ) ) {
					$settings['link'][ $key ] = $this->translate_preset_string( $settings['link'][ $key ], $map );
				}
			}
		}

		if ( ! empty( $settings['_attributes'] ) && is_array( $settings['_attributes'] ) ) {
			foreach ( $settings['_attributes'] as &$attribute ) {
				$attribute_name = isset( $attribute['name'] ) ? strtolower( (string) $attribute['name'] ) : '';

				if (
					in_array( $attribute_name, [ 'title', 'aria-label' ], true ) &&
					isset( $attribute['value'] ) &&
					is_string( $attribute['value'] )
				) {
					$attribute['value'] = $this->translate_preset_string( $attribute['value'], $map );
				}
			}
			unset( $attribute );
		}

		return $settings;
	}

	/**
	 * Translate a preset string when it exists in the generated map.
	 *
	 * @since 2.4
	 *
	 * @param string $value Source string.
	 * @param array  $map Translation map.
	 *
	 * @return string
	 */
	private function translate_preset_string( $value, $map ) {
		return isset( $map[ $value ] ) ? $map[ $value ] : $value;
	}

	/**
	 * Adapt checkout form-field elements in a JSON-sourced preset to match the site's WooCommerce fields.
	 *
	 * - Removes form-field elements whose fieldKey is not in the WC checkout field registry.
	 * - Adds missing WC fields at the end of the appropriate section container.
	 * - Updates labels and placeholders from the site's WC field config.
	 *
	 * @since 2.4
	 * @since 2.4 Hydrates and filters checkout account fields alongside billing fields.
	 *
	 * @param array $children Nested children tree.
	 * @param array $checkout_fields Effective WC checkout fields (after removal filters).
	 *
	 * @return array Modified children tree.
	 */
	private function adapt_checkout_fields_for_site( $children, $checkout_fields ) {
		if ( ! is_array( $children ) || empty( $checkout_fields ) ) {
			return $children;
		}

		// Build a flat registry of all valid field keys.
		$valid_keys = [];
		foreach ( [ 'billing', 'shipping', 'account', 'order' ] as $section ) {
			if ( ! empty( $checkout_fields[ $section ] ) && is_array( $checkout_fields[ $section ] ) ) {
				foreach ( array_keys( $checkout_fields[ $section ] ) as $key ) {
					$valid_keys[ $key ] = $section;
				}
			}
		}

		// Collect existing checkout field keys from the tree. Account auth fields
		// can also use woocommerce-form-field but are not part of the checkout registry.
		$existing_keys = $this->collect_checkout_field_keys_from_children( $children );

		// Remove invalid fields and update labels/placeholders from WC config.
		$children = $this->filter_and_update_form_fields( $children, $valid_keys, $checkout_fields );

		// Find missing field keys per section.
		$missing_per_section = [];
		foreach ( $valid_keys as $key => $section ) {
			if ( ! in_array( $key, $existing_keys, true ) ) {
				$missing_per_section[ $section ][] = $key;
			}
		}

		// Append missing fields to the appropriate section container.
		if ( ! empty( $missing_per_section ) ) {
			$children = $this->append_missing_fields_to_sections( $children, $missing_per_section, $checkout_fields );
		}

		return $children;
	}

	/**
	 * Remove invalid form-field elements and update labels/placeholders from WC config.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children tree.
	 * @param array $valid_keys Valid field keys map (key => section).
	 * @param array $checkout_fields WC checkout fields.
	 *
	 * @return array
	 */
	private function filter_and_update_form_fields( $children, $valid_keys, $checkout_fields ) {
		$filtered = [];

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			// Filter out invalid form-field elements.
			if ( $this->is_checkout_form_field_element( $child ) ) {
				$field_key = $child['settings']['fieldKey'] ?? '';

				if ( ! $field_key || ! isset( $valid_keys[ $field_key ] ) ) {
					continue; // Remove: field doesn't exist in WC config.
				}

				// Update label and placeholder from WC config.
				$field_config = $this->find_checkout_field_config( $field_key, $checkout_fields );
				if ( is_array( $field_config ) ) {
					if ( ! empty( $field_config['label'] ) ) {
						$child['settings']['label']       = sanitize_text_field( $field_config['label'] );
						$child['settings']['placeholder'] = ! empty( $field_config['placeholder'] )
							? sanitize_text_field( $field_config['placeholder'] )
							: sanitize_text_field( $field_config['label'] );
					}

					// Update element label.
					$section = $valid_keys[ $field_key ];
					$label   = ! empty( $field_config['label'] ) ? $field_config['label'] : $field_key;
					switch ( $section ) {
						case 'account':
							$child['label'] = esc_html__( 'Account', 'bricks' ) . ': ' . $label;
							break;

						case 'billing':
							$child['label'] = esc_html__( 'Billing', 'bricks' ) . ': ' . $label;
							break;

						case 'shipping':
							$child['label'] = esc_html__( 'Shipping', 'bricks' ) . ': ' . $label;
							break;

						case 'order':
							$child['label'] = esc_html__( 'Additional', 'bricks' ) . ': ' . $label;
							break;
					}
				}
			}

			// Recurse.
			if ( ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$child['children'] = $this->filter_and_update_form_fields( $child['children'], $valid_keys, $checkout_fields );
			}

			$filtered[] = $child;
		}

		return $filtered;
	}

	/**
	 * Append missing WC checkout fields to the correct section containers.
	 *
	 * Finds the section container by looking for existing form-field elements
	 * with matching fieldKey prefix and appending new fields to the same parent.
	 *
	 * @since 2.4
	 *
	 * @param array $children Children tree.
	 * @param array $missing_per_section Missing fields grouped by section.
	 * @param array $checkout_fields WC checkout fields.
	 *
	 * @return array
	 */
	private function append_missing_fields_to_sections( $children, &$missing_per_section, $checkout_fields ) {
		foreach ( $children as &$child ) {
			if ( ! is_array( $child ) || empty( $child['children'] ) || ! is_array( $child['children'] ) ) {
				continue;
			}

			// Both credentials may be generated automatically, leaving an empty account
			// wrapper that must remain the insertion target if settings change later. (#86cb33dre; @since 2.4)
			$container_section = ( $child['name'] ?? '' ) === 'woocommerce-checkout-account-fields' ? 'account' : null;
			foreach ( $child['children'] as $sub_child ) {
				if ( $this->is_checkout_form_field_element( $sub_child ) ) {
					$fk                = $sub_child['settings']['fieldKey'] ?? '';
					$container_section = $this->guess_checkout_field_section_from_key( $fk );
					break;
				}
			}

			// If this container holds fields for a section that has missing fields, append them.
			if ( $container_section && ! empty( $missing_per_section[ $container_section ] ) ) {
				$sorted_fields = isset( $checkout_fields[ $container_section ] ) ? $this->sort_checkout_fields_by_priority( $checkout_fields[ $container_section ] ) : [];

				foreach ( $missing_per_section[ $container_section ] as $field_key ) {
					$field_config = $sorted_fields[ $field_key ] ?? [];
					if ( ! is_array( $field_config ) ) {
						continue;
					}

					$label       = ! empty( $field_config['label'] ) ? sanitize_text_field( $field_config['label'] ) : $field_key;
					$placeholder = ! empty( $field_config['placeholder'] ) ? sanitize_text_field( $field_config['placeholder'] ) : $label;

					$element_label = $label;
					switch ( $container_section ) {
						case 'account':
							$element_label = esc_html__( 'Account', 'bricks' ) . ': ' . $label;
							break;

						case 'billing':
							$element_label = esc_html__( 'Billing', 'bricks' ) . ': ' . $label;
							break;

						case 'shipping':
							$element_label = esc_html__( 'Shipping', 'bricks' ) . ': ' . $label;
							break;

						case 'order':
							$element_label = esc_html__( 'Additional', 'bricks' ) . ': ' . $label;
							break;
					}

					$child['children'][] = [
						'name'     => 'woocommerce-form-field',
						'label'    => $element_label,
						'settings' => [
							'fieldKey'    => $field_key,
							'label'       => $label,
							'placeholder' => $placeholder,
						],
					];
				}

				// Clear so we don't append again in a deeper recursion.
				unset( $missing_per_section[ $container_section ] );
			}

			// Recurse.
			$child['children'] = $this->append_missing_fields_to_sections( $child['children'], $missing_per_section, $checkout_fields );
		}

		return $children;
	}
}

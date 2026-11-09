<?php
/**
 * Typed existing-site editing facade for coding agents.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project canonical Bricks files into a compact public edit map and compile
 * typed operations back into the Site Changeset contract.
 */
class Site_Edit_Plan {
	const CONTRACT_VERSION      = '1';
	const WORKSPACE_TTL         = 900;
	const MAX_OPERATIONS        = 64;
	const MAX_PLAN_BYTES        = 65536;
	const MAX_MAP_ELEMENTS      = 2000;
	const WORKSPACE_CHUNK_BYTES = 524288;
	const MAX_WORKSPACE_CHUNKS  = 16;
	const MAX_ACTIVE_WORKSPACES = 3;
	const TRANSIENT_PREFIX      = 'bricks_agent_site_edit_';

	/**
	 * Checkout schema.
	 *
	 * @return array
	 */
	public static function checkout_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'targets'      => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => Site_Changeset::MAX_TARGETS,
					'description' => __( 'Pass exact targets only, for example {"scope":"page","postId":13} or {"scope":"design","resource":"globalVariable","id":"accent"}. Do not pass pageIds, resourceIds, or responseFormat.', 'bricks' ),
					'items'       => [
						'type'                 => 'object',
						'properties'           => [
							'scope'    => [
								'type'        => 'string',
								'enum'        => [ 'page', 'design' ],
								'description' => __( 'Use page with postId, or design with resource and id.', 'bricks' )
							],
							'postId'   => [
								'type'    => 'integer',
								'minimum' => 1
							],
							'resource' => [
								'type' => 'string',
								'enum' => [ 'globalClass', 'globalVariable', 'themeStyle', 'component' ]
							],
							'id'       => [
								'type'      => 'string',
								'minLength' => 1
							],
						],
						'required'             => [ 'scope' ],
						'additionalProperties' => false,
					],
				],
				'elementIds'   => [
					'type'        => 'array',
					'maxItems'    => 64,
					'description' => __( 'When exact element IDs are known, return only those element outlines. Design resources without elements remain visible.', 'bricks' ),
					'items'       => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 128
					],
				],
				'elementNames' => [
					'type'        => 'array',
					'maxItems'    => 32,
					'description' => __( 'Optionally restrict element outlines to exact Bricks element names.', 'bricks' ),
					'items'       => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 128
					],
				],
				'textQuery'    => [
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Optionally restrict outlines to visible text containing this case-insensitive query.', 'bricks' ),
				],
				'editableOnly' => [
					'type'        => 'boolean',
					'description' => __( 'Return only element outlines that advertise at least one typed editable operation.', 'bricks' ),
				],
			],
			'required'             => [ 'targets' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Preview schema.
	 *
	 * @return array
	 */
	public static function preview_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'workspaceToken' => [
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				],
				'operations'     => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_OPERATIONS,
					'description' => __( 'Operations are one atomic intent and use selections from the same frozen pre-edit workspace.', 'bricks' ),
					'items'       => self::operation_schema(),
				],
			],
			'required'             => [ 'workspaceToken', 'operations' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Direct commit schema for the common two-call typed edit path.
	 *
	 * @return array
	 */
	public static function commit_schema(): array {
		$schema                                      = self::preview_schema();
		$schema['properties']['operations']['items'] = self::commit_operation_schema();
		$schema['properties']['idempotencyKey']      = [
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 128,
			'description' => __( 'Use one stable key for this exact edit plan. Repeat the same call and key to resume or replay safely.', 'bricks' ),
		];
		$schema['properties']['responseFormat']      = [
			'type'        => 'string',
			'enum'        => [ 'summary', 'document' ],
			'description' => __( 'Use summary unless another edit requires the complete canonical readback.', 'bricks' ),
		];
		$schema['properties']['maxSteps']            = [
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => Site_Changeset::MAX_TARGETS
		];
		$schema['properties']['timeBudgetMs']        = [
			'type'    => 'integer',
			'minimum' => 250,
			'maximum' => 20000
		];
		$schema['required'][]                        = 'idempotencyKey';
		return $schema;
	}

	/** One-call schema for edits whose exact page, element, or variable IDs are known. */
	public static function exact_commit_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'operations'     => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_OPERATIONS,
					'description' => __( 'Operations resolve against one pre-edit snapshot. When set-element-label renames a component element, companion set-element-attributes may select it by either the original label or that new label.', 'bricks' ),
					'items'       => [
						'type'                 => 'object',
						'properties'           => [
							'op'              => [
								'type' => 'string',
								'enum' => [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes', 'set-variable-value', 'set-design-setting' ]
							],
							'postId'          => [
								'type'    => 'integer',
								'minimum' => 1
							],
							'pageTitle'       => [
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'For page text operations, use an exact unique page or template title when postId is not known.', 'bricks' )
							],
							'componentId'     => [
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 128
							],
							'componentName'   => [
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'For component element operations, use an exact unique component name when componentId is not known.', 'bricks' )
							],
							'elementId'       => [
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 128
							],
							'matchText'       => [
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 20000,
								'description' => __( 'Exact current text or label used to resolve one unique editable element when elementId is not known.', 'bricks' )
							],
							'id'              => [
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 128
							],
							'name'            => [
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 200,
								'description' => __( 'For set-design-setting, use an exact unique resource name when its ID is not known.', 'bricks' )
							],
							'resource'        => [
								'type' => 'string',
								'enum' => [ 'globalClass', 'themeStyle' ]
							],
							'settingPath'     => [
								'type'        => 'string',
								'pattern'     => '^/settings/[A-Za-z0-9_:-]+(?:/[A-Za-z0-9_:-]+)*$',
								'maxLength'   => 500,
								'description' => __( 'Exact canonical settings leaf, for example /settings/_background/color/raw.', 'bricks' )
							],
							'value'           => [
								'type'        => [ 'string', 'number', 'boolean', 'array' ],
								'maxLength'   => 20000,
								'description' => __( 'For set-element-attributes pass an array of safe {id?, name, value} accessibility attributes; only role and aria-* names are accepted. Pass scalar values for every other operation.', 'bricks' ),
								'items'       => self::attribute_record_schema(),
							],
							'expectedValue'   => [
								'type'        => [ 'string', 'number', 'boolean', 'array' ],
								'maxLength'   => 20000,
								'description' => __( 'Optional exact current value guard. For set-element-attributes pass the current public role and aria-* records as {name, value}, or [] when absent; internal record IDs are ignored. The entire request fails before preview when it no longer matches.', 'bricks' ),
								'items'       => self::attribute_record_schema(),
							],
							'allowBlindWrite' => [
								'type'        => 'boolean',
								'description' => __( 'Required as literal true when expectedValue is omitted. Use only when the user supplied the exact target ID and intentionally requested replacement regardless of its current value.', 'bricks' )
							],
						],
						'required'             => [ 'op', 'value' ],
						'additionalProperties' => false,
					],
				],
				'idempotencyKey' => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 128,
					'description' => __( 'Use one stable key for this exact operation set. Identical calls replay safely; altered operations under the same key fail.', 'bricks' )
				],
				'responseFormat' => [
					'type' => 'string',
					'enum' => [ 'summary', 'document' ]
				],
				'maxSteps'       => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => Site_Changeset::MAX_TARGETS
				],
				'timeBudgetMs'   => [
					'type'    => 'integer',
					'minimum' => 250,
					'maximum' => 20000
				],
			],
			'required'             => [ 'operations', 'idempotencyKey' ],
			'additionalProperties' => false,
		];
	}

	/** Return the strict custom-attribute record schema shared by exact values and guards. */
	private static function attribute_record_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'    => [
					'type'      => 'string',
					'maxLength' => 128,
				],
				'name'  => [
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				],
				'value' => [
					'type'      => 'string',
					'maxLength' => 2000,
				],
			],
			'required'             => [ 'name', 'value' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Checkout output schema.
	 *
	 * @return array
	 */
	public static function checkout_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion' => [ 'type' => 'string' ],
				'workspaceToken'  => [ 'type' => 'string' ],
				'expiresAt'       => [ 'type' => 'integer' ],
				'atomic'          => [ 'type' => 'boolean' ],
				'resources'       => [ 'type' => 'array' ],
				'manifest'        => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Preview output schema extends the Site Changeset response summary.
	 *
	 * @return array
	 */
	public static function preview_output_schema(): array {
		$schema = Site_Changeset::output_schema();
		$schema['properties']['compiledOperationCount'] = [ 'type' => 'integer' ];
		return $schema;
	}

	/** Return the direct commit Site Changeset response schema. */
	public static function commit_output_schema(): array {
		$schema = Site_Changeset::output_schema();
		$schema['properties']['compiledOperationCount'] = [ 'type' => 'integer' ];
		return $schema;
	}

	/**
	 * Authorize map checkout with the canonical changeset permission contract.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function checkout_permission( $input ) {
		return Site_Changeset::checkout_permission( $input );
	}

	/**
	 * Reauthorize the frozen canonical documents before compilation.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_permission( $input ) {
		$workspace = self::workspace( (string) ( $input['workspaceToken'] ?? '' ) );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		return Site_Changeset::preview_permission( [ 'documents' => $workspace['documents'] ] );
	}

	/**
	 * Reauthorize a new workspace or a durable direct-commit replay.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function commit_permission( $input ) {
		$token         = (string) ( $input['workspaceToken'] ?? '' );
		$key           = (string) ( $input['idempotencyKey'] ?? '' );
		$preview_token = self::direct_preview_token( $token, $key, $input['operations'] ?? null );
		$apply         = Site_Changeset::apply_permission(
			[
				'previewToken'   => $preview_token,
				'idempotencyKey' => $key
			]
		);
		if ( ! is_wp_error( $apply ) ) {
			return true;
		}
		if ( strpos( (string) $apply->get_error_code(), 'site_changeset_idempotency_mismatch' ) !== false ) {
			// Let execution return the precise journal mismatch instead of a generic
			// WP_Ability permission wrapper; no mutation path is authorized by it.
			return true;
		}
		$workspace = self::workspace( $token );
		return is_wp_error( $workspace ) ? $apply : Site_Changeset::preview_permission( [ 'documents' => $workspace['documents'] ] );
	}

	/** Authorize an exact one-call request or its durable replay. */
	public static function exact_commit_permission( $input ) {
		$normalized = self::normalize_exact_request( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$journal_key   = self::exact_idempotency_key( (string) $normalized['idempotencyKey'] );
		$identity      = self::exact_request_identity( $normalized['operations'], (string) $normalized['idempotencyKey'] );
		$preview_token = self::exact_preview_token( $identity );
		$apply         = Site_Changeset::apply_permission(
			[
				'previewToken'   => $preview_token,
				'idempotencyKey' => $journal_key
			]
		);
		if ( ! is_wp_error( $apply ) ) {
			return true;
		}
		if ( strpos( (string) $apply->get_error_code(), 'site_changeset_idempotency_mismatch' ) !== false ) {
			return true;
		}
		return Site_Changeset::checkout_permission( [ 'targets' => self::exact_targets( $normalized['operations'] ) ] );
	}

	/**
	 * Checkout canonical authority through the registered changeset ability and
	 * return only public, bounded element outlines and typed-operation digests.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function checkout( $input ) {
		return self::checkout_map( $input, null );
	}

	/** Shared public/internal map checkout with an optional deterministic token. */
	private static function checkout_map( array $input, $forced_token ) {
		$checkout = self::execute_ability( 'bricks/checkout-site-changeset', [ 'targets' => $input['targets'] ?? null ] );
		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		$documents = $checkout['documents'] ?? null;
		if ( ! is_array( $documents ) || empty( $documents ) ) {
			return Error::internal_error( 'site-edit-map', 'The canonical changeset checkout returned no documents.' );
		}

		$token           = is_string( $forced_token ) ? $forced_token : bin2hex( random_bytes( 32 ) );
		$expires_at      = time() + self::WORKSPACE_TTL;
		$resources       = [];
		$digests         = [];
		$selections      = [];
		$selection_index = 0;
		$remaining       = self::MAX_MAP_ELEMENTS;
		$filters         = self::checkout_filters( $input );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		foreach ( $documents as $entry ) {
			$resource = self::project_resource( $entry, $token, $remaining, $digests, $selections, $selection_index, $filters );
			if ( is_wp_error( $resource ) ) {
				return $resource;
			}
			$resources[] = $resource;
		}

		$workspace                    = [
			'contractVersion' => self::CONTRACT_VERSION,
			'userId'          => get_current_user_id(),
			'siteId'          => get_current_blog_id(),
			'expiresAt'       => $expires_at,
			'executionPolicy' => self::execution_policy(),
			'documents'       => $documents,
			'digests'         => $digests,
			'selections'      => $selections,
		];
		$workspace['workspaceDigest'] = self::workspace_digest( $workspace );

		$stored = self::store_workspace( $token, $workspace );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'workspaceToken'  => $token,
			'expiresAt'       => $expires_at,
			'atomic'          => false,
			'resources'       => $resources,
			'manifest'        => [
				'count'              => count( $resources ),
				'visibleElements'    => self::MAX_MAP_ELEMENTS - $remaining,
				'elementLimit'       => self::MAX_MAP_ELEMENTS,
				'applyOrder'         => 'pages-first-then-design-stable',
				'completeForTargets' => ! array_reduce(
					$resources,
					static function ( $carry, $resource ) {
						return $carry || ! empty( $resource['truncated'] ); },
					false
				),
				'atomic'             => false,
			],
		];
	}

	/** Commit exact known targets in one public call through the same guarded engine. */
	public static function commit_exact( $input ) {
		$normalized = self::normalize_exact_request( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$key             = (string) $normalized['idempotencyKey'];
		$journal_key     = self::exact_idempotency_key( $key );
		$response_format = (string) ( $normalized['responseFormat'] ?? 'summary' );
		$identity        = self::exact_request_identity( $normalized['operations'], $key );
		$preview_token   = self::exact_preview_token( $identity );
		$lock_identity   = hash( 'sha256', "exact-site-edit-lock\0" . $journal_key );
		$lock            = self::acquire_direct_commit_lock( $lock_identity, $journal_key );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$apply = Site_Changeset::apply_permission(
				[
					'previewToken'   => $preview_token,
					'idempotencyKey' => $journal_key
				]
			);
			if ( ! is_wp_error( $apply ) ) {
				return self::apply_direct_token( $preview_token, $journal_key, $response_format, $normalized );
			}
			if ( strpos( (string) $apply->get_error_code(), 'site_changeset_idempotency_mismatch' ) !== false ) {
				return $apply;
			}
			$bound_identity = get_transient( self::exact_binding_key( $key ) );
			if ( is_string( $bound_identity ) && ( strlen( $bound_identity ) !== 64 || ! hash_equals( $bound_identity, $identity ) ) ) {
				return Error::conflict( 'site_edit_exact_idempotency_mismatch', [ 'message' => 'This exact-edit idempotency key is bound to another operation set.' ] );
			}

			$map = self::checkout_map(
				[
					'targets'      => self::exact_targets( $normalized['operations'] ),
					'elementIds'   => self::exact_element_ids( $normalized['operations'] ),
					'editableOnly' => true,
				],
				$identity
			);
			if ( is_wp_error( $map ) ) {
				return $map;
			}

			$compact = self::resolve_exact_operations( $map, $normalized['operations'] );
			if ( is_wp_error( $compact ) ) {
				self::delete_workspace( $identity );
				return $compact;
			}
			$binding_key = self::exact_binding_key( $key );
			if ( ! is_string( $bound_identity ) ) {
				if ( ! set_transient( $binding_key, $identity, self::WORKSPACE_TTL ) || get_transient( $binding_key ) !== $identity ) {
					self::delete_workspace( $identity );
					return Error::internal_error( 'site-edit-exact', 'Could not persist the exact-edit replay binding. No Bricks data was changed.' );
				}
			}
			$commit_input                   = $normalized;
			$commit_input['workspaceToken'] = $identity;
			$commit_input['operations']     = $compact;
			return self::commit_locked( $commit_input, $identity, $journal_key, $response_format, $preview_token );
		} finally {
			self::release_workspace_lock( $lock );
		}
	}

	/**
	 * Compile typed operations into frozen canonical documents, then delegate
	 * all validation, CAS, preparation, permissions, and token issuance to the
	 * registered Site Changeset preview ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview( $input ) {
		return self::preview_plan( $input, true );
	}

	/**
	 * Compile, preview, and apply a typed plan without exposing the intermediate
	 * Site Changeset token. A server-HMAC token preserves retry/resume identity.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function commit( $input ) {
		$token           = (string) ( $input['workspaceToken'] ?? '' );
		$key             = (string) ( $input['idempotencyKey'] ?? '' );
		$response_format = (string) ( $input['responseFormat'] ?? 'summary' );
		if ( $key === '' || strlen( $key ) > 128 || ! in_array( $response_format, [ 'summary', 'document' ], true ) ) {
			return Error::invalid_param( 'idempotencyKey/responseFormat', 'a stable key no longer than 128 characters and summary or document response format', null );
		}
		$lock = self::acquire_direct_commit_lock( $token, $key );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			return self::commit_locked( $input, $token, $key, $response_format );
		} finally {
			self::release_workspace_lock( $lock );
		}
	}

	/** Execute one direct typed commit while its request identity is locked. */
	private static function commit_locked( array $input, string $token, string $key, string $response_format, $forced_preview_token = null ) {
		$preview_token = is_string( $forced_preview_token ) ? $forced_preview_token : self::direct_preview_token( $token, $key, $input['operations'] ?? null );
		$apply         = Site_Changeset::apply_permission(
			[
				'previewToken'   => $preview_token,
				'idempotencyKey' => $key
			]
		);
		if ( ! is_wp_error( $apply ) ) {
			return self::apply_direct_token( $preview_token, $key, $response_format, $input );
		}
		if ( strpos( (string) $apply->get_error_code(), 'site_changeset_idempotency_mismatch' ) !== false ) {
			return $apply;
		}
		$workspace = self::workspace( $token );
		if ( is_wp_error( $workspace ) ) {
			return $apply;
		}

		$compiled_input = self::expand_commit_operations( $input );
		if ( is_wp_error( $compiled_input ) ) {
			return $compiled_input;
		}
		$preview = self::preview_plan( $compiled_input, false, $preview_token );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$preview['compiledOperationCount'] = count( (array) ( $input['operations'] ?? [] ) );
		if ( (string) ( $preview['state'] ?? '' ) === 'no_change' ) {
			return Error::internal_error( 'site-edit-plan', 'The direct edit preview did not create its durable no-change replay checkpoint. No Bricks data was changed.' );
		}
		self::delete_workspace( $token );
		return self::apply_direct_token( $preview_token, $key, $response_format, $input );
	}

	/**
	 * Shared typed compiler and preview implementation.
	 *
	 * @param array $input             Ability input.
	 * @param bool  $consume_workspace Whether to consume the workspace.
	 * @return array|\WP_Error
	 */
	private static function preview_plan( $input, bool $consume_workspace, $forced_token = null ) {
		$operations = $input['operations'] ?? null;
		if ( ! is_array( $operations ) || count( $operations ) < 1 || count( $operations ) > self::MAX_OPERATIONS ) {
			return Error::invalid_param( 'operations', 'an array of 1-64 typed operations', $operations );
		}

		if ( strlen( (string) wp_json_encode( $operations ) ) > self::MAX_PLAN_BYTES ) {
			return Error::invalid_param( 'operations', 'a typed plan no larger than 64 KiB', null );
		}

		$workspace = self::workspace( (string) ( $input['workspaceToken'] ?? '' ) );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$documents = $workspace['documents'];
		$seen      = [];
		foreach ( $operations as $index => $operation ) {
			$selection_key = (string) ( $operation['resourcePath'] ?? '' ) . '#' . (string) ( $operation['elementId'] ?? '' ) . '#' . (string) ( $operation['settingPath'] ?? '' ) . '#' . (string) ( $operation['op'] ?? '' );
			if ( isset( $seen[ $selection_key ] ) ) {
				return Error::conflict(
					'site_edit_duplicate_selection',
					[
						'message'   => 'One typed plan cannot mutate the same selection twice.',
						'operation' => $index
					]
				);
			}
			$seen[ $selection_key ] = true;
			$result                 = self::compile_operation( $documents, $workspace['digests'], $operation, $index );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$dependencies = self::dependency_status( $documents );
		if ( is_wp_error( $dependencies ) ) {
			return $dependencies;
		}

		if ( is_string( $forced_token ) ) {
			$permission = Site_Changeset::preview_permission( [ 'documents' => $documents ] );
			$result     = is_wp_error( $permission ) ? $permission : Site_Changeset::preview_with_token( [ 'documents' => $documents ], $forced_token );
		} else {
			$result = self::execute_ability( 'bricks/preview-site-changeset', [ 'documents' => $documents ] );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $consume_workspace ) {
			self::delete_workspace( (string) $input['workspaceToken'] );
		}
		$result['compiledOperationCount'] = count( $operations );
		return $result;
	}

	/** Apply or resume one server-derived direct-commit token. */
	private static function apply_direct_token( string $preview_token, string $key, string $response_format, array $input ) {
		$result = self::execute_ability(
			'bricks/apply-site-changeset',
			[
				'previewToken'   => $preview_token,
				'idempotencyKey' => $key,
				'responseFormat' => $response_format,
				'maxSteps'       => max( 1, min( Site_Changeset::MAX_TARGETS, (int) ( $input['maxSteps'] ?? Site_Changeset::MAX_TARGETS ) ) ),
				'timeBudgetMs'   => max( 250, min( 20000, (int) ( $input['timeBudgetMs'] ?? 20000 ) ) ),
			]
		);
		if ( ! is_wp_error( $result ) ) {
			$result['compiledOperationCount'] = count( (array) ( $input['operations'] ?? [] ) );
		}
		return $result;
	}

	/** Validate and canonically order one exact-target request. */
	private static function normalize_exact_request( $input ) {
		if ( ! is_array( $input ) ) {
			return Error::invalid_param( 'input', 'an exact site edit request', $input );
		}
		$key             = (string) ( $input['idempotencyKey'] ?? '' );
		$response_format = (string) ( $input['responseFormat'] ?? 'summary' );
		$operations      = $input['operations'] ?? null;
		if ( $key === '' || strlen( $key ) > 128 || ! in_array( $response_format, [ 'summary', 'document' ], true ) ) {
			return Error::invalid_param( 'idempotencyKey/responseFormat', 'a stable key no longer than 128 characters and summary or document response format', null );
		}
		if ( ! is_array( $operations ) || count( $operations ) < 1 || count( $operations ) > self::MAX_OPERATIONS || strlen( (string) wp_json_encode( $operations ) ) > self::MAX_PLAN_BYTES ) {
			return Error::invalid_param( 'operations', 'an array of 1-64 exact operations no larger than 64 KiB', null );
		}

		$normalized = [];
		$seen       = [];
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) || ! array_key_exists( 'value', $operation ) ) {
				return Error::invalid_param( "operations[{$index}]", 'an exact operation with a value', $operation );
			}
			$op    = (string) ( $operation['op'] ?? '' );
			$value = $operation['value'];
			if ( $op === 'set-element-attributes' ? ! self::is_exact_attributes( $value ) : ! self::is_supported_scalar( $value ) ) {
				$expected_value = $op === 'set-element-attributes' ? 'an array of canonical {id?, name, value} custom-attribute records' : 'text or a number no larger than 20,000 bytes';
				return Error::invalid_param( "operations[{$index}].value", $expected_value, $value );
			}

			if ( in_array( $op, [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true ) ) {
				$post_id        = (int) ( $operation['postId'] ?? 0 );
				$component_id   = (string) ( $operation['componentId'] ?? '' );
				$element_id     = (string) ( $operation['elementId'] ?? '' );
				$match_text     = (string) ( $operation['matchText'] ?? '' );
				$component_name = (string) ( $operation['componentName'] ?? '' );
				if ( $component_id !== '' || $component_name !== '' ) {
					if ( $op === 'insert-text-before' ) {
						return Error::invalid_param( "operations[{$index}].op", 'component element replacement operations, not sibling insertion', $op );
					}
					if ( $component_id === '' ) {
						$component_id = self::resolve_design_resource_id( 'component', $component_name );
						if ( is_wp_error( $component_id ) ) {
							return $component_id;
						}
					}
					$post_id = 0;
				} elseif ( $post_id < 1 ) {
					$post_id = self::resolve_page_id( (string) ( $operation['pageTitle'] ?? '' ) );
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}
				}
				if ( ( $element_id === '' && $match_text === '' ) || strlen( $element_id ) > 128 || strlen( $match_text ) > 20000 || ( $op !== 'set-element-attributes' && ! is_string( $value ) ) ) {
					return Error::invalid_param( "operations[{$index}]", 'a page text operation with postId or pageTitle, elementId or exact matchText, and a text value', $operation );
				}
				$item          = [
					'op'          => $op,
					'postId'      => $post_id,
					'componentId' => $component_id,
					'elementId'   => $element_id,
					'matchText'   => $match_text,
					'value'       => $value
				];
				$scope_key     = $component_id !== '' ? "component:{$component_id}" : "page:{$post_id}";
				$selection_key = $scope_key . '#' . ( $element_id !== '' ? $element_id : 'text:' . hash( 'sha256', $match_text ) ) . "#{$op}";
			} elseif ( $op === 'set-variable-value' ) {
				$id = (string) ( $operation['id'] ?? '' );
				if ( $id === '' || strlen( $id ) > 128 ) {
					return Error::invalid_param( "operations[{$index}]", 'set-variable-value with a global variable id and value', $operation );
				}
				$item          = [
					'op'    => $op,
					'id'    => $id,
					'value' => $value
				];
				$selection_key = "globalVariable:{$id}";
			} elseif ( $op === 'set-design-setting' ) {
				$resource     = (string) ( $operation['resource'] ?? '' );
				$id           = (string) ( $operation['id'] ?? '' );
				$name         = (string) ( $operation['name'] ?? '' );
				$setting_path = (string) ( $operation['settingPath'] ?? '' );
				if ( ! in_array( $resource, [ 'globalClass', 'themeStyle' ], true ) || is_wp_error( self::setting_path_segments( $setting_path ) ) ) {
					return Error::invalid_param( "operations[{$index}]", 'set-design-setting with globalClass or themeStyle and an exact /settings/... leaf', $operation );
				}
				if ( $id === '' ) {
					$id = self::resolve_design_resource_id( $resource, $name );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
				}
				if ( strlen( $id ) > 128 ) {
					return Error::invalid_param( "operations[{$index}].id", 'a design resource id no longer than 128 bytes', $id );
				}
				$item          = [
					'op'          => $op,
					'resource'    => $resource,
					'id'          => $id,
					'settingPath' => $setting_path,
					'value'       => $value
				];
				$selection_key = "{$resource}:{$id}:{$setting_path}";
			} else {
				return Error::invalid_param( "operations[{$index}].op", 'a supported exact page, component, variable, or design-setting operation', $op );
			}

			if ( array_key_exists( 'expectedValue', $operation ) ) {
				$expected = $operation['expectedValue'];
				if ( $op === 'set-element-attributes' ? ! self::is_exact_attributes( $expected ) : ! self::is_supported_scalar( $expected ) ) {
					return Error::invalid_param( "operations[{$index}].expectedValue", 'text or a number no larger than 20,000 bytes', $expected );
				}
				$item['expectedValue'] = $expected;
			} elseif ( ( $operation['allowBlindWrite'] ?? false ) === true ) {
				$item['allowBlindWrite'] = true;
			} else {
				return Error::invalid_param( "operations[{$index}]", 'expectedValue or literal allowBlindWrite true for an exact user-supplied target', $operation );
			}
			if ( isset( $seen[ $selection_key ] ) ) {
				return Error::conflict(
					'site_edit_duplicate_selection',
					[
						'message'   => 'One exact request cannot mutate the same selection twice.',
						'operation' => $index
					]
				);
			}
			$seen[ $selection_key ] = true;
			$normalized[]           = $item;
		}

		usort(
			$normalized,
			static function ( $left, $right ): int {
				return strcmp( (string) wp_json_encode( $left ), (string) wp_json_encode( $right ) );
			}
		);
		if ( count( self::exact_targets( $normalized ) ) > Site_Changeset::MAX_TARGETS ) {
			return Error::invalid_param( 'operations', 'exact operations spanning no more than 25 page or design targets', null );
		}
		return [
			'operations'     => $normalized,
			'idempotencyKey' => $key,
			'responseFormat' => $response_format,
			'maxSteps'       => max( 1, min( Site_Changeset::MAX_TARGETS, (int) ( $input['maxSteps'] ?? Site_Changeset::MAX_TARGETS ) ) ),
			'timeBudgetMs'   => max( 250, min( 20000, (int) ( $input['timeBudgetMs'] ?? 20000 ) ) ),
		];
	}

	/** Return the unique canonical targets required by exact operations. */
	private static function exact_targets( array $operations ): array {
		$targets = [];
		foreach ( $operations as $operation ) {
			if ( in_array( $operation['op'], [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true ) && (string) ( $operation['componentId'] ?? '' ) !== '' ) {
				$target = [
					'scope'    => 'design',
					'resource' => 'component',
					'id'       => (string) $operation['componentId']
				];
			} elseif ( in_array( $operation['op'], [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true ) ) {
				$target = [
					'scope'  => 'page',
					'postId' => (int) $operation['postId']
				];
			} else {
				$target = [
					'scope'    => 'design',
					'resource' => $operation['op'] === 'set-variable-value' ? 'globalVariable' : (string) $operation['resource'],
					'id'       => (string) $operation['id']
				];
			}
			$targets[ hash( 'sha256', (string) wp_json_encode( $target ) ) ] = $target;
		}
		return array_values( $targets );
	}

	/** Return the unique element IDs required by exact page operations. */
	private static function exact_element_ids( array $operations ): array {
		$ids = [];
		foreach ( $operations as $operation ) {
			if ( in_array( $operation['op'], [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true ) && (string) ( $operation['elementId'] ?? '' ) !== '' ) {
				$ids[] = (string) $operation['elementId'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Resolve exact targets to authenticated compact refs and guard old values. */
	private static function resolve_exact_operations( array $map, array $operations ) {
		$workspace = self::workspace( (string) ( $map['workspaceToken'] ?? '' ) );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		$compact = [];
		foreach ( $operations as $index => $operation ) {
			$selection_ref = '';
			$current_value = null;
			$found         = false;
			$selector_text = self::exact_selector_text( $operations, $operation );
			foreach ( $map['resources'] as $resource ) {
				$is_element_operation = in_array( $operation['op'], [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true );
				$page_matches         = (string) ( $operation['componentId'] ?? '' ) === '' && (int) ( $resource['target']['postId'] ?? 0 ) === (int) ( $operation['postId'] ?? 0 );
				$component_matches    = (string) ( $operation['componentId'] ?? '' ) !== '' && (string) ( $resource['target']['resource'] ?? '' ) === 'component' && (string) ( $resource['target']['id'] ?? '' ) === (string) $operation['componentId'];
				if ( $is_element_operation && ( $page_matches || $component_matches ) ) {
					$matches = [];
					foreach ( $resource['elements'] ?? [] as $element ) {
						$candidate_id    = (string) ( $element['id'] ?? '' );
						$candidate_value = self::exact_workspace_value( $workspace['documents'], array_merge( $operation, [ 'elementId' => $candidate_id ] ) );
						$candidate_text  = self::exact_workspace_match_text( $workspace['documents'], $operation, $candidate_id );
						$id_matches      = (string) ( $operation['elementId'] ?? '' ) !== '' && $candidate_id === (string) $operation['elementId'];
						$text_matches    = (string) ( $operation['elementId'] ?? '' ) === '' && $candidate_text === $selector_text;
						if ( ( $id_matches || $text_matches ) && in_array( $operation['op'], (array) ( $element['editableOps'] ?? [] ), true ) ) {
							$matches[] = [
								'ref'   => (string) ( $element['selectionRef'] ?? '' ),
								'value' => $candidate_value,
							];
						}
					}
					if ( count( $matches ) === 1 ) {
						$selection_ref = $matches[0]['ref'];
						$current_value = $matches[0]['value'];
						$found         = true;
						break;
					}
				}
				if ( $operation['op'] === 'set-variable-value' && (string) ( $resource['target']['resource'] ?? '' ) === 'globalVariable' && (string) ( $resource['target']['id'] ?? '' ) === (string) $operation['id'] ) {
					$selection_ref = (string) ( $resource['selectionRef'] ?? '' );
					$current_value = self::exact_workspace_value( $workspace['documents'], $operation );
					$found         = true;
					break;
				}
				if ( $operation['op'] === 'set-design-setting' && (string) ( $resource['target']['resource'] ?? '' ) === (string) $operation['resource'] && (string) ( $resource['target']['id'] ?? '' ) === (string) $operation['id'] && in_array( 'set-design-setting', (array) ( $resource['editableOps'] ?? [] ), true ) ) {
					$selection_ref = (string) ( $resource['selectionRef'] ?? '' );
					$current_value = self::exact_workspace_value( $workspace['documents'], $operation );
					$found         = true;
					break;
				}
			}
			if ( ! $found || $selection_ref === '' ) {
				return Error::conflict(
					'site_edit_exact_target_missing',
					[
						'message'   => 'One exact target is missing, ambiguous, or not safe for this typed operation.',
						'operation' => $index
					]
				);
			}
			$expected_value = $operation['expectedValue'] ?? null;
			if ( $operation['op'] === 'set-element-attributes' && is_array( $expected_value ) ) {
				$expected_value = self::exact_attribute_values( $expected_value );
			}
			if ( array_key_exists( 'expectedValue', $operation ) && $expected_value !== $current_value ) {
				return Error::conflict(
					'site_edit_expected_value_changed',
					[
						'message'   => 'One exact target no longer has the expected current value. No Bricks data was changed.',
						'operation' => $index
					]
				);
			}
			$compact_operation = [
				'op'           => $operation['op'],
				'selectionRef' => $selection_ref,
				'value'        => $operation['value']
			];
			if ( isset( $operation['settingPath'] ) ) {
				$compact_operation['settingPath'] = $operation['settingPath'];
			}
			$compact[] = $compact_operation;
		}
		return $compact;
	}

	/** Resolve a safe same-request component label alias back to its pre-edit selector. */
	private static function exact_selector_text( array $operations, array $operation ): string {
		$match_text = (string) ( $operation['matchText'] ?? '' );
		if ( $operation['op'] !== 'set-element-attributes' || $match_text === '' || (string) ( $operation['componentId'] ?? '' ) === '' ) {
			return $match_text;
		}
		$aliases = [];
		foreach ( $operations as $candidate ) {
			if ( $candidate['op'] === 'set-element-label' && (string) ( $candidate['componentId'] ?? '' ) === (string) $operation['componentId'] && (string) ( $candidate['value'] ?? '' ) === $match_text && (string) ( $candidate['matchText'] ?? '' ) !== '' ) {
				$aliases[] = (string) $candidate['matchText'];
			}
		}
		$aliases = array_values( array_unique( $aliases ) );
		return count( $aliases ) === 1 ? $aliases[0] : $match_text;
	}

	/** Resolve one exact named page or template without returning its document to the client. */
	private static function resolve_page_id( string $title ) {
		if ( $title === '' || strlen( $title ) > 200 ) {
			return Error::invalid_param( 'pageTitle', 'an exact unique page or template title no longer than 200 bytes', $title );
		}
		$input      = [
			'query'      => $title,
			'scope'      => 'page',
			'maxMatches' => 20,
		];
		$permission = Agent_File::resolve_permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$resolved = Agent_File::resolve( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$post_id     = (int) ( $resolved['target']['postId'] ?? 0 );
		$match_label = (string) ( $resolved['matches'][0]['label'] ?? '' );
		if ( (string) ( $resolved['state'] ?? '' ) !== 'resolved' || $post_id < 1 || ! self::exact_name_matches( $title, $match_label ) ) {
			return Error::conflict(
				'site_edit_exact_target_missing',
				[
					'message' => 'The named page or template is missing, ambiguous, or could not be resolved completely.',
					'state'   => $resolved['state'] ?? 'unknown',
					'matches' => $resolved['matches'] ?? [],
				]
			);
		}
		return $post_id;
	}

	/** Resolve one exact named design resource without returning its document to the client. */
	private static function resolve_design_resource_id( string $resource, string $name ) {
		if ( $name === '' || strlen( $name ) > 200 ) {
			return Error::invalid_param( 'name', 'an exact unique design resource name no longer than 200 bytes', $name );
		}
		$input      = [
			'query'         => $name,
			'scope'         => 'design',
			'resourceKinds' => [ $resource ],
			'maxMatches'    => 20,
		];
		$permission = Agent_File::resolve_permission( $input );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$resolved = Agent_File::resolve( $input );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$match_label = (string) ( $resolved['matches'][0]['label'] ?? '' );
		if ( (string) ( $resolved['state'] ?? '' ) !== 'resolved' || (string) ( $resolved['target']['resource'] ?? '' ) !== $resource || (string) ( $resolved['target']['id'] ?? '' ) === '' || ! self::exact_name_matches( $name, $match_label ) ) {
			return Error::conflict(
				'site_edit_exact_target_missing',
				[
					'message' => 'The named design resource is missing, ambiguous, or could not be resolved completely.',
					'state'   => $resolved['state'] ?? 'unknown',
					'matches' => $resolved['matches'] ?? [],
				]
			);
		}
		return (string) $resolved['target']['id'];
	}

	/** Return whether an Agent File match has the exact requested human name. */
	private static function exact_name_matches( string $requested, string $matched ): bool {
		$normalize = static function ( string $value ): string {
			$value = trim( wp_strip_all_tags( $value ) );
			$value = preg_replace( '/\s+/', ' ', $value );
			return strtolower( is_string( $value ) ? $value : '' );
		};
		return $normalize( $requested ) !== '' && $normalize( $requested ) === $normalize( $matched );
	}

	/** Return whether a typed leaf value is bounded and JSON-scalar. */
	private static function is_supported_scalar( $value ): bool {
		return ( is_string( $value ) && strlen( $value ) <= 20000 ) || is_numeric( $value ) || is_bool( $value );
	}

	/** Return whether a custom-attribute replacement is bounded and canonical. */
	private static function is_exact_attributes( $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > 64 || strlen( (string) wp_json_encode( $value ) ) > 20000 ) {
			return false;
		}
		$names = [];
		foreach ( $value as $attribute ) {
			if ( ! is_array( $attribute ) || ! isset( $attribute['name'], $attribute['value'] ) || ! is_string( $attribute['name'] ) || ! is_string( $attribute['value'] ) || $attribute['name'] === '' || strlen( $attribute['name'] ) > 200 || strlen( $attribute['value'] ) > 2000 ) {
				return false;
			}
			$name = strtolower( $attribute['name'] );
			if ( ! preg_match( '/^(?:role|aria-[a-z0-9_.:-]+)$/', $name ) || isset( $names[ $name ] ) ) {
				return false;
			}
			$names[ $name ] = true;
			$extra          = array_diff( array_keys( $attribute ), [ 'id', 'name', 'value' ] );
			if ( ! empty( $extra ) || ( isset( $attribute['id'] ) && ( ! is_string( $attribute['id'] ) || strlen( $attribute['id'] ) > 128 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Return the safe public projection used by attribute CAS checks. */
	private static function exact_attribute_values( array $attributes ): array {
		return array_map(
			static function ( array $attribute ): array {
				return [
					'name'  => strtolower( $attribute['name'] ),
					'value' => $attribute['value'],
				];
			},
			array_values( $attributes )
		);
	}

	/** Return whether one stored attribute is part of the safe semantic contract. */
	private static function is_public_attribute_record( $attribute ): bool {
		return is_array( $attribute ) && is_string( $attribute['name'] ?? null ) && is_string( $attribute['value'] ?? null ) && preg_match( '/^(?:role|aria-[a-z0-9_.:-]+)$/', strtolower( $attribute['name'] ) ) === 1;
	}

	/** Replace public accessibility attributes while preserving opaque custom records. */
	private static function merge_exact_attributes( array $current, array $desired ): array {
		$remaining = [];
		foreach ( $desired as $attribute ) {
			$attribute['name']               = strtolower( $attribute['name'] );
			$remaining[ $attribute['name'] ] = $attribute;
		}

		$merged = [];
		foreach ( $current as $attribute ) {
			if ( ! self::is_public_attribute_record( $attribute ) ) {
				$merged[] = $attribute;
				continue;
			}
			$name = strtolower( $attribute['name'] );
			if ( ! isset( $remaining[ $name ] ) ) {
				continue;
			}
			$replacement = $remaining[ $name ];
			if ( isset( $attribute['id'] ) ) {
				$replacement['id'] = $attribute['id'];
			}
			$merged[] = $replacement;
			unset( $remaining[ $name ] );
		}

		return array_merge( $merged, array_values( $remaining ) );
	}

	/** Parse one deliberately narrow canonical settings path. */
	private static function setting_path_segments( string $path ) {
		if ( strlen( $path ) > 500 || ! preg_match( '#^/settings/[A-Za-z0-9_:-]+(?:/[A-Za-z0-9_:-]+)*$#', $path ) ) {
			return Error::invalid_param( 'settingPath', 'an exact canonical /settings/... leaf no longer than 500 bytes', $path );
		}
		return explode( '/', ltrim( $path, '/' ) );
	}

	/** Read one settings leaf without exposing its surrounding canonical file. */
	private static function value_at_setting_path( array $item, string $path ) {
		$segments = self::setting_path_segments( $path );
		if ( is_wp_error( $segments ) ) {
			return null;
		}
		$value = $item;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/** Set one settings leaf while preserving every unrelated and opaque field. */
	private static function set_value_at_setting_path( array &$item, string $path, $value ) {
		$segments = self::setting_path_segments( $path );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}
		$cursor =& $item;
		$last   = array_pop( $segments );
		foreach ( $segments as $segment ) {
			if ( isset( $cursor[ $segment ] ) && ! is_array( $cursor[ $segment ] ) ) {
				return Error::conflict( 'site_edit_setting_path_blocked', [ 'message' => 'The setting path crosses an existing non-object value.' ] );
			}
			if ( ! isset( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor =& $cursor[ $segment ];
		}
		$cursor[ $last ] = $value;
		return true;
	}

	/** Read the untruncated current value from one frozen canonical workspace. */
	private static function exact_workspace_value( array $documents, array $operation ) {
		foreach ( $documents as $entry ) {
			$document = $entry['document'] ?? [];
			if ( $operation['op'] === 'set-variable-value' && (string) ( $document['resource'] ?? '' ) === 'globalVariable' && (string) ( $document['id'] ?? '' ) === (string) $operation['id'] ) {
				return $document['item']['value'] ?? null;
			}
			$is_element_operation = in_array( $operation['op'], [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes' ], true );
			$page_matches         = (string) ( $operation['componentId'] ?? '' ) === '' && (int) ( $entry['target']['postId'] ?? 0 ) === (int) ( $operation['postId'] ?? 0 );
			$component_matches    = (string) ( $operation['componentId'] ?? '' ) !== '' && (string) ( $document['resource'] ?? '' ) === 'component' && (string) ( $document['id'] ?? '' ) === (string) $operation['componentId'];
			if ( $is_element_operation && ( $page_matches || $component_matches ) ) {
				$elements = $component_matches ? ( $document['item']['elements'] ?? [] ) : ( $document['elements'] ?? [] );
				foreach ( $elements as $element ) {
					if ( (string) ( $element['id'] ?? '' ) === (string) $operation['elementId'] ) {
						if ( $operation['op'] === 'set-element-label' ) {
							return (string) ( $element['label'] ?? '' );
						}
						if ( $operation['op'] === 'set-element-attributes' ) {
							$attributes = is_array( $element['settings']['_attributes'] ?? null ) ? $element['settings']['_attributes'] : [];
							return self::exact_attribute_values( array_values( array_filter( $attributes, [ self::class, 'is_public_attribute_record' ] ) ) );
						}
						return $element['settings']['text'] ?? '';
					}
				}
			}
			if ( $operation['op'] === 'set-design-setting' && (string) ( $document['resource'] ?? '' ) === (string) $operation['resource'] && (string) ( $document['id'] ?? '' ) === (string) $operation['id'] ) {
				return self::value_at_setting_path( $document['item'] ?? [], (string) $operation['settingPath'] );
			}
		}
		return null;
	}

	/** Read one element's operation-specific exact text or label for semantic selection. */
	private static function exact_workspace_match_text( array $documents, array $operation, string $element_id ): string {
		foreach ( $documents as $entry ) {
			$document          = $entry['document'] ?? [];
			$page_matches      = (string) ( $operation['componentId'] ?? '' ) === '' && (int) ( $entry['target']['postId'] ?? 0 ) === (int) ( $operation['postId'] ?? 0 );
			$component_matches = (string) ( $operation['componentId'] ?? '' ) !== '' && (string) ( $document['resource'] ?? '' ) === 'component' && (string) ( $document['id'] ?? '' ) === (string) $operation['componentId'];
			if ( ! $page_matches && ! $component_matches ) {
				continue;
			}
			$elements = $component_matches ? ( $document['item']['elements'] ?? [] ) : ( $document['elements'] ?? [] );
			foreach ( $elements as $element ) {
				if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
					if ( $operation['op'] === 'set-element-label' ) {
						return (string) ( $element['label'] ?? '' );
					}
					if ( $operation['op'] === 'set-element-attributes' ) {
						return (string) ( $element['label'] ?? $element['settings']['text'] ?? '' );
					}
					return (string) ( $element['settings']['text'] ?? '' );
				}
			}
		}
		return '';
	}

	/** Return the deterministic user/site/request workspace identity. */
	private static function exact_request_identity( array $operations, string $key ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( [ 'exact-site-edit', self::CONTRACT_VERSION, get_current_user_id(), get_current_blog_id(), $key, $operations ] ), wp_salt( 'auth' ) );
	}

	/** Return the hidden durable Site Changeset token for an exact request. */
	private static function exact_preview_token( string $identity ): string {
		return hash_hmac( 'sha256', "exact-site-edit\0" . $identity, wp_salt( 'auth' ) );
	}

	/** Namespace the caller key away from generic and two-call Site Changesets. */
	private static function exact_idempotency_key( string $key ): string {
		return 'site-edit-exact:' . hash( 'sha256', $key );
	}

	/** Return a non-enumerable short-lived binding key for pre-journal crashes. */
	private static function exact_binding_key( string $key ): string {
		$identity = get_current_blog_id() . "\0" . get_current_user_id() . "\0" . $key;
		return self::TRANSIENT_PREFIX . 'exact_' . hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}

	/**
	 * Typed operation schema.
	 *
	 * @return array
	 */
	private static function operation_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'op'              => [
					'type' => 'string',
					'enum' => [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes', 'set-variable-value', 'set-design-setting' ],
				],
				'resourcePath'    => [
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => __( 'Copy the resourcePath from the selected map resource. This is a virtual repository path, not a JSON Pointer or setting name.', 'bricks' )
				],
				'elementId'       => [
					'type'        => 'string',
					'maxLength'   => 128,
					'description' => __( 'For page text operations, copy the exact element id whose editableOps includes the requested operation. Omit for resource-level operations.', 'bricks' )
				],
				'selectionDigest' => [
					'type'        => 'string',
					'minLength'   => 64,
					'maxLength'   => 64,
					'description' => __( 'Copy the selectionDigest from that exact element or design resource.', 'bricks' )
				],
				'assertCount'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1
				],
				'value'           => [
					'type'      => [ 'string', 'number', 'boolean', 'array' ],
					'maxLength' => 20000
				],
				'settingPath'     => [
					'type'      => 'string',
					'pattern'   => '^/settings/[A-Za-z0-9_:-]+(?:/[A-Za-z0-9_:-]+)*$',
					'maxLength' => 500
				],
			],
			'required'             => [ 'op', 'resourcePath', 'selectionDigest', 'assertCount' ],
			'additionalProperties' => false,
		];
	}

	/** Return the compact direct-commit operation schema. */
	private static function commit_operation_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'op'           => [
					'type' => 'string',
					'enum' => [ 'set-text', 'insert-text-before', 'set-element-label', 'set-element-attributes', 'set-variable-value', 'set-design-setting' ]
				],
				'selectionRef' => [
					'type'        => 'string',
					'pattern'     => '^s[1-9][0-9]*_[a-f0-9]{16}$',
					'description' => __( 'Copy the short workspace-bound selectionRef advertised by the exact element or variable resource.', 'bricks' )
				],
				'value'        => [
					'type'      => [ 'string', 'number', 'boolean', 'array' ],
					'maxLength' => 20000
				],
				'settingPath'  => [
					'type'      => 'string',
					'pattern'   => '^/settings/[A-Za-z0-9_:-]+(?:/[A-Za-z0-9_:-]+)*$',
					'maxLength' => 500
				],
			],
			'required'             => [ 'op', 'selectionRef', 'value' ],
			'additionalProperties' => false,
		];
	}

	/** Validate and normalize compact checkout filters. */
	private static function checkout_filters( array $input ) {
		$filters = [
			'elementIds'   => $input['elementIds'] ?? [],
			'elementNames' => $input['elementNames'] ?? [],
			'textQuery'    => (string) ( $input['textQuery'] ?? '' ),
			'editableOnly' => ! empty( $input['editableOnly'] ),
		];
		foreach ( [
			'elementIds'   => 64,
			'elementNames' => 32
		] as $key => $limit ) {
			if ( ! is_array( $filters[ $key ] ) || count( $filters[ $key ] ) > $limit ) {
				return Error::invalid_param( $key, "an array of at most {$limit} strings", $filters[ $key ] );
			}
			foreach ( $filters[ $key ] as $value ) {
				if ( ! is_string( $value ) || $value === '' || strlen( $value ) > 128 ) {
					return Error::invalid_param( $key, 'non-empty strings no longer than 128 bytes', $value );
				}
			}
			$filters[ $key ] = array_values( array_unique( $filters[ $key ] ) );
		}
		if ( strlen( $filters['textQuery'] ) > 200 ) {
			return Error::invalid_param( 'textQuery', 'text no longer than 200 bytes', null );
		}
		return $filters;
	}

	/** Return whether one public outline matches every requested filter. */
	private static function outline_matches_filters( array $outline, array $filters ): bool {
		if ( ! empty( $filters['elementIds'] ) && ! in_array( (string) $outline['id'], $filters['elementIds'], true ) ) {
			return false;
		}
		if ( ! empty( $filters['elementNames'] ) && ! in_array( (string) $outline['name'], $filters['elementNames'], true ) ) {
			return false;
		}
		if ( $filters['textQuery'] !== '' && stripos( (string) $outline['text'], $filters['textQuery'] ) === false ) {
			return false;
		}
		return empty( $filters['editableOnly'] ) || ! empty( $outline['editableOps'] );
	}

	/** Expand short workspace-bound selection references for the shared compiler. */
	private static function expand_commit_operations( array $input ) {
		$workspace = self::workspace( (string) ( $input['workspaceToken'] ?? '' ) );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		$operations = $input['operations'] ?? null;
		if ( ! is_array( $operations ) || count( $operations ) < 1 || count( $operations ) > self::MAX_OPERATIONS ) {
			return Error::invalid_param( 'operations', 'an array of 1-64 compact typed operations', $operations );
		}
		$expanded = [];
		foreach ( $operations as $index => $operation ) {
			$ref       = is_array( $operation ) ? (string) ( $operation['selectionRef'] ?? '' ) : '';
			$selection = $workspace['selections'][ $ref ] ?? null;
			$op        = is_array( $operation ) ? (string) ( $operation['op'] ?? '' ) : '';
			if ( ! is_array( $selection ) || ! in_array( $op, (array) ( $selection['editableOps'] ?? [] ), true ) ) {
				return Error::conflict(
					'site_edit_selection_changed',
					[
						'message'   => 'The compact operation does not match an advertised selection and operation from this workspace.',
						'operation' => $index
					]
				);
			}
			$expanded[] = [
				'op'              => $op,
				'resourcePath'    => (string) $selection['resourcePath'],
				'elementId'       => (string) $selection['elementId'],
				'selectionDigest' => (string) $selection['selectionDigest'],
				'assertCount'     => 1,
				'value'           => $operation['value'] ?? null,
				'settingPath'     => $operation['settingPath'] ?? null,
			];
		}
		$input['operations'] = $expanded;
		return $input;
	}

	/**
	 * Project one canonical resource.
	 *
	 * @param array  $entry     Canonical changeset entry.
	 * @param string $token     Workspace token.
	 * @param int    $remaining Remaining public element budget.
	 * @param array  $digests   Digest map.
	 * @param array  $selections Compact selection map.
	 * @param int    $selection_index Next selection number.
	 * @param array  $filters   Public projection filters.
	 * @return array|\WP_Error
	 */
	private static function project_resource( array $entry, string $token, int &$remaining, array &$digests, array &$selections, int &$selection_index, array $filters ) {
		$path     = (string) ( $entry['path'] ?? '' );
		$document = $entry['document'] ?? null;
		if ( $path === '' || ! is_array( $document ) ) {
			return Error::internal_error( 'site-edit-map', 'A canonical changeset entry is malformed.' );
		}

		$resource = [
			'resourcePath' => $path,
			'scope'        => (string) ( $entry['scope'] ?? '' ),
			'target'       => $entry['target'] ?? [],
			'truncated'    => false,
		];
		$item     = $document['item'] ?? null;

		if ( $resource['scope'] === 'design' && is_array( $item ) ) {
			$resource['resource'] = (string) ( $document['resource'] ?? '' );
			$resource['label']    = (string) ( $item['name'] ?? $item['label'] ?? $item['id'] ?? '' );
			if ( $resource['resource'] === 'globalVariable' ) {
				$resource['value'] = $item['value'] ?? '';
			}
		}

		$resource_digest             = self::selection_digest( $token, $path, '', $document['baseline'] ?? null, $item );
		$resource['selectionDigest'] = $resource_digest;
		$digests[ $path . '#' ]      = $resource_digest;
		if ( (string) ( $document['resource'] ?? '' ) === 'globalVariable' ) {
			$selection_ref                = self::selection_ref( ++$selection_index, $token, $path, '', $resource_digest );
			$resource['selectionRef']     = $selection_ref;
			$selections[ $selection_ref ] = [
				'resourcePath'    => $path,
				'elementId'       => '',
				'selectionDigest' => $resource_digest,
				'editableOps'     => [ 'set-variable-value' ]
			];
		}
		if ( in_array( (string) ( $document['resource'] ?? '' ), [ 'globalClass', 'themeStyle' ], true ) ) {
			$selection_ref                = self::selection_ref( ++$selection_index, $token, $path, '', $resource_digest );
			$resource['selectionRef']     = $selection_ref;
			$resource['editableOps']      = [ 'set-design-setting' ];
			$selections[ $selection_ref ] = [
				'resourcePath'    => $path,
				'elementId'       => '',
				'selectionDigest' => $resource_digest,
				'editableOps'     => [ 'set-design-setting' ]
			];
		}

		$elements = $resource['scope'] === 'page' ? ( $document['elements'] ?? [] ) : ( $item['elements'] ?? [] );
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return $resource;
		}

		$resource['elements'] = [];
		$total                = count( $elements );
		$id_counts            = [];
		foreach ( $elements as $element ) {
			$id = is_array( $element ) ? (string) ( $element['id'] ?? '' ) : '';
			if ( $id !== '' ) {
				$id_counts[ $id ] = (int) ( $id_counts[ $id ] ?? 0 ) + 1;
			}
		}
		$connected_controls = $resource['scope'] === 'design' ? self::component_connected_controls( $item ) : [];
		$omitted            = 0;
		foreach ( $elements as $position => $element ) {
			if ( ! is_array( $element ) || (string) ( $element['id'] ?? '' ) === '' ) {
				continue;
			}
			$id        = (string) $element['id'];
			$settings  = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			$duplicate = (int) ( $id_counts[ $id ] ?? 0 ) !== 1;
			$digest    = $duplicate ? '' : self::selection_digest( $token, $path, $id, $document['baseline'] ?? null, $element );
			$outline   = [
				'id'            => $id,
				'position'      => $position,
				'name'          => (string) ( $element['name'] ?? '' ),
				'parent'        => $element['parent'] ?? 0,
				'label'         => (string) ( $element['label'] ?? '' ),
				'tag'           => (string) ( $settings['tag'] ?? '' ),
				'text'          => self::public_text( $settings['text'] ?? '' ),
				'globalClasses' => array_values( array_map( 'strval', (array) ( $settings['_cssGlobalClasses'] ?? [] ) ) ),
				'styleKeys'     => self::style_keys( $settings ),
				'attributes'    => self::public_attributes( $settings['_attributes'] ?? [] ),
			];
			if ( $digest !== '' ) {
				$outline['selectionDigest'] = $digest;
			}
			$instance_boundary      = ! empty( $element['cid'] ) || ! empty( $element['componentId'] ) || ! empty( $element['parentComponent'] ) || ! empty( $settings['componentId'] );
			$text_connected         = ! empty( $connected_controls[ $id ]['text'] );
			$component_definition   = $resource['scope'] === 'design' && (string) ( $document['resource'] ?? '' ) === 'component';
			$outline['boundary']    = [
				'componentInstance'  => $instance_boundary,
				'propertyControlled' => ! empty( $connected_controls[ $id ] ),
				'duplicateId'        => $duplicate,
			];
			$outline['editableOps'] = [];
			if ( ! $duplicate && ! $instance_boundary ) {
				if ( ! $text_connected && in_array( $outline['name'], [ 'heading', 'text', 'text-basic', 'text-link', 'button' ], true ) ) {
					$outline['editableOps'][] = 'set-text';
					if ( $resource['scope'] === 'page' ) {
						$outline['editableOps'][] = 'insert-text-before';
					}
				}
				if ( $component_definition && empty( $connected_controls[ $id ] ) ) {
					$outline['editableOps'][] = 'set-element-label';
				}
				if ( $component_definition && empty( $connected_controls[ $id ] ) ) {
					$outline['editableOps'][] = 'set-element-attributes';
				}
			}
			if ( ! self::outline_matches_filters( $outline, $filters ) ) {
				++$omitted;
				continue;
			}
			if ( $remaining < 1 ) {
				$resource['truncated'] = true;
				break;
			}
			if ( is_array( $settings['link'] ?? null ) ) {
				$outline['link'] = [
					'type' => (string) ( $settings['link']['type'] ?? '' ),
					'url'  => self::public_text( $settings['link']['url'] ?? '', 500 ),
				];
			}
			if ( $resource['scope'] === 'design' ) {
				$outline['componentDefinition'] = true;
				$outline['componentId']         = (string) ( $document['id'] ?? '' );
			}
			$resource['elements'][] = $outline;
			if ( $digest !== '' ) {
				$digests[ $path . '#' . $id ] = $digest;
				if ( ! empty( $outline['editableOps'] ) ) {
					$selection_ref = self::selection_ref( ++$selection_index, $token, $path, $id, $digest );
					$resource['elements'][ count( $resource['elements'] ) - 1 ]['selectionRef'] = $selection_ref;
					$selections[ $selection_ref ] = [
						'resourcePath'    => $path,
						'elementId'       => $id,
						'selectionDigest' => $digest,
						'editableOps'     => $outline['editableOps']
					];
				}
			}
			--$remaining;
		}
		$resource['elementCount']    = $total;
		$resource['omittedElements'] = $omitted;

		return $resource;
	}

	/**
	 * Compile one typed operation in place.
	 *
	 * @param array $documents Canonical documents.
	 * @param array $digests   Frozen public selection digests.
	 * @param mixed $operation Operation.
	 * @param int   $index     Operation index.
	 * @return true|\WP_Error
	 */
	private static function compile_operation( array &$documents, array $digests, $operation, int $index ) {
		if ( ! is_array( $operation ) || (int) ( $operation['assertCount'] ?? 0 ) !== 1 ) {
			return Error::invalid_param( "operations[{$index}]", 'one typed operation with assertCount 1', $operation );
		}

		$path       = (string) ( $operation['resourcePath'] ?? '' );
		$element_id = (string) ( $operation['elementId'] ?? '' );
		$key        = $path . '#' . $element_id;
		$expected   = (string) ( $digests[ $key ] ?? '' );
		$supplied   = (string) ( $operation['selectionDigest'] ?? '' );
		if ( $expected === '' || strlen( $supplied ) !== 64 || ! hash_equals( $expected, $supplied ) ) {
			return Error::conflict(
				'site_edit_selection_changed',
				[
					'message'   => 'The operation does not match one exact selection from this edit map. Checkout a new map before retrying.',
					'operation' => $index
				]
			);
		}

		$document_index = null;
		foreach ( $documents as $candidate_index => $entry ) {
			if ( (string) ( $entry['path'] ?? '' ) === $path ) {
				$document_index = $candidate_index;
				break;
			}
		}
		if ( $document_index === null ) {
			return Error::conflict(
				'site_edit_selection_changed',
				[
					'message'   => 'The selected resource is no longer in this edit workspace.',
					'operation' => $index
				]
			);
		}

		$op = (string) ( $operation['op'] ?? '' );
		if ( $op === 'set-variable-value' ) {
			$document =& $documents[ $document_index ]['document'];
			$value    = $operation['value'] ?? null;
			if ( $element_id !== '' || (string) ( $document['resource'] ?? '' ) !== 'globalVariable' || ( ! is_string( $value ) && ! is_numeric( $value ) ) || ( is_string( $value ) && strlen( $value ) > 20000 ) ) {
				return Error::invalid_param( "operations[{$index}]", 'a global-variable resource selection and value', $operation );
			}
			$document['item']['value'] = $value;
			return true;
		}
		if ( $op === 'set-design-setting' ) {
			$document     =& $documents[ $document_index ]['document'];
			$resource     = (string) ( $document['resource'] ?? '' );
			$setting_path = (string) ( $operation['settingPath'] ?? '' );
			$value        = $operation['value'] ?? null;
			if ( $element_id !== '' || ! in_array( $resource, [ 'globalClass', 'themeStyle' ], true ) || ! self::is_supported_scalar( $value ) ) {
				return Error::invalid_param( "operations[{$index}]", 'a global-class or theme-style resource selection, settingPath, and scalar value', $operation );
			}
			return self::set_value_at_setting_path( $document['item'], $setting_path, $value );
		}

		if ( $element_id === '' ) {
			return Error::invalid_param( "operations[{$index}].elementId", 'an element selection from the edit map', $element_id );
		}

		$document =& $documents[ $document_index ]['document'];
		if ( (string) ( $document['resource'] ?? '' ) === 'component' ) {
			$elements =& $document['item']['elements'];
		} elseif ( (string) ( $documents[ $document_index ]['scope'] ?? '' ) === 'page' ) {
			$elements =& $document['elements'];
		} else {
			return Error::invalid_param( "operations[{$index}]", 'an element-bearing page or component resource', $operation );
		}

		$element_index = null;
		$match_count   = 0;
		foreach ( $elements as $candidate_index => $element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
				$element_index = $candidate_index;
				++$match_count;
			}
		}
		if ( $element_index === null || $match_count !== 1 ) {
			return Error::conflict(
				'site_edit_selection_changed',
				[
					'message'   => 'The selected element is no longer present exactly once.',
					'operation' => $index
				]
			);
		}

		$element = $elements[ $element_index ];
		if ( in_array( $op, [ 'set-text', 'insert-text-before' ], true ) && ! in_array( (string) ( $element['name'] ?? '' ), [ 'heading', 'text', 'text-basic', 'text-link', 'button' ], true ) ) {
			return Error::conflict(
				'site_edit_element_unsupported',
				[
					'message'   => 'Typed text editing is not runtime-certified for this element type.',
					'operation' => $index
				]
			);
		}
		if ( ! empty( $element['cid'] ) || ! empty( $element['componentId'] ) || ! empty( $element['parentComponent'] ) || ! empty( $element['settings']['componentId'] ) ) {
			return Error::conflict(
				'site_edit_component_instance_boundary',
				[
					'message'   => 'Edit the component definition or its public property instead of mutating a component instance boundary.',
					'operation' => $index
				]
			);
		}
		$connected_controls = (string) ( $document['resource'] ?? '' ) === 'component' ? self::component_connected_controls( $document['item'] ) : [];
		$controlled         = ( $op === 'set-text' && ! empty( $connected_controls[ $element_id ]['text'] ) ) ||
			( $op === 'set-element-label' && ! empty( $connected_controls[ $element_id ]['label'] ) ) ||
			( $op === 'set-element-attributes' && ( ! empty( $connected_controls[ $element_id ]['_attributes'] ) || ! empty( $connected_controls[ $element_id ]['customAttributes'] ) ) );
		if ( $controlled ) {
			return Error::conflict(
				'site_edit_component_property_boundary',
				[
					'message'   => 'This component text is controlled by a public component property and cannot be overwritten directly.',
					'operation' => $index
				]
			);
		}

		$settings =& $elements[ $element_index ]['settings'];
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		if ( $op === 'set-text' ) {
			if ( ! array_key_exists( 'value', $operation ) || ! is_string( $operation['value'] ) || strlen( $operation['value'] ) > 20000 ) {
				return Error::invalid_param( "operations[{$index}].value", 'text no larger than 20,000 bytes', $operation['value'] ?? null );
			}
			$settings['text'] = $operation['value'];
			return true;
		}
		if ( $op === 'set-element-label' ) {
			if ( (string) ( $document['resource'] ?? '' ) !== 'component' || ! is_string( $operation['value'] ?? null ) || strlen( $operation['value'] ) > 20000 ) {
				return Error::invalid_param( "operations[{$index}]", 'a component element selection and label no larger than 20,000 bytes', $operation );
			}
			$elements[ $element_index ]['label'] = $operation['value'];
			return true;
		}
		if ( $op === 'set-element-attributes' ) {
			if ( (string) ( $document['resource'] ?? '' ) !== 'component' || ! self::is_exact_attributes( $operation['value'] ?? null ) ) {
				return Error::invalid_param( "operations[{$index}]", 'a component element selection and canonical custom-attribute records', $operation );
			}
			$current_attributes      = is_array( $settings['_attributes'] ?? null ) ? $settings['_attributes'] : [];
			$settings['_attributes'] = self::merge_exact_attributes( $current_attributes, array_values( $operation['value'] ) );
			return true;
		}
		if ( $op === 'insert-text-before' ) {
			$value = $operation['value'] ?? null;
			if ( (string) ( $documents[ $document_index ]['scope'] ?? '' ) !== 'page' || ! is_string( $value ) || strlen( $value ) > 20000 ) {
				return Error::invalid_param( "operations[{$index}]", 'a page text selection and text no larger than 20,000 bytes', $operation );
			}

			unset( $settings );
			$new_id = self::generated_element_id( $elements, $supplied, $value );
			$parent = $element['parent'] ?? 0;
			if ( (string) $parent !== '' && (string) $parent !== '0' ) {
				$parent_index = null;
				$parent_count = 0;
				foreach ( $elements as $candidate_index => $candidate ) {
					if ( (string) ( $candidate['id'] ?? '' ) === (string) $parent ) {
						$parent_index = $candidate_index;
						++$parent_count;
					}
				}
				if ( $parent_index === null || $parent_count !== 1 || ! is_array( $elements[ $parent_index ]['children'] ?? null ) ) {
					return Error::conflict(
						'site_edit_parent_changed',
						[
							'message'   => 'The selected element parent is missing or malformed.',
							'operation' => $index,
						]
					);
				}
				$child_positions = array_keys( $elements[ $parent_index ]['children'], $element_id, true );
				if ( count( $child_positions ) !== 1 ) {
					return Error::conflict(
						'site_edit_parent_changed',
						[
							'message'   => 'The selected element is not present exactly once in its parent children list.',
							'operation' => $index,
						]
					);
				}
				array_splice( $elements[ $parent_index ]['children'], $child_positions[0], 0, [ $new_id ] );
			}

			$new_element = [
				'id'       => $new_id,
				'name'     => 'text-basic',
				'parent'   => $parent,
				'children' => [],
				'settings' => [ 'text' => $value ],
			];
			array_splice( $elements, $element_index, 0, [ $new_element ] );
			return true;
		}

		return Error::invalid_param( "operations[{$index}].op", 'a supported typed operation', $op );
	}

	/** Generate one deterministic collision-free Bricks element ID. */
	private static function generated_element_id( array $elements, string $selection_digest, string $value ): string {
		$used = [];
		foreach ( $elements as $element ) {
			$used[ (string) ( $element['id'] ?? '' ) ] = true;
		}
		$counter = 0;
		do {
			$id = substr( hash( 'sha256', $selection_digest . "\0" . $value . "\0" . $counter ), 0, 6 );
			++$counter;
		} while ( isset( $used[ $id ] ) );
		return $id;
	}

	/** Return whether a component property owns one element control. */
	private static function component_connected_controls( array $item ): array {
		$connected = [];
		foreach ( is_array( $item['properties'] ?? null ) ? $item['properties'] : [] as $property ) {
			foreach ( is_array( $property['connections'] ?? null ) ? $property['connections'] : [] as $element_id => $controls ) {
				foreach ( is_array( $controls ) ? $controls : [] as $control ) {
					$connected[ (string) $element_id ][ (string) $control ] = true;
				}
			}
		}
		return $connected;
	}

	/**
	 * Resolve a user/site-bound short-lived workspace.
	 *
	 * @param string $token Workspace token.
	 * @return array|\WP_Error
	 */
	private static function workspace( string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return Error::conflict( 'site_edit_workspace_missing', [ 'message' => 'The site edit workspace is missing, expired, or malformed. Checkout a new edit map.' ] );
		}

		$workspace = self::read_workspace( $token );
		if ( ! is_array( $workspace ) || (int) ( $workspace['expiresAt'] ?? 0 ) < time() ) {
			return Error::conflict( 'site_edit_workspace_missing', [ 'message' => 'The site edit workspace is missing or expired. Checkout a new edit map.' ] );
		}
		if ( (int) ( $workspace['userId'] ?? 0 ) !== get_current_user_id() || (int) ( $workspace['siteId'] ?? 0 ) !== get_current_blog_id() ) {
			return Error::forbidden_builder_permission( 'site_edit_workspace_owner' );
		}
		if ( ( $workspace['executionPolicy'] ?? null ) !== self::execution_policy() ) {
			return Error::conflict( 'site_edit_execution_policy_changed', [ 'message' => 'The caller execution policy changed after checkout. Checkout a new edit map.' ] );
		}
		$actual_digest = (string) ( $workspace['workspaceDigest'] ?? '' );
		$unsigned      = $workspace;
		unset( $unsigned['workspaceDigest'] );
		if ( strlen( $actual_digest ) !== 64 || ! hash_equals( $actual_digest, self::workspace_digest( $unsigned ) ) ) {
			return Error::conflict( 'site_edit_workspace_tampered', [ 'message' => 'The stored site edit workspace failed its integrity check. Checkout a new edit map.' ] );
		}

		return $workspace;
	}

	/**
	 * Execute a registered ability so the admin deny-list remains authoritative.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed|\WP_Error
	 */
	private static function execute_ability( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return Error::ability_disabled( $name );
		}

		try {
			return $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			return Error::internal_error(
				'site-edit-plan',
				$throwable->getMessage(),
				[
					'ability'   => $name,
					'exception' => get_class( $throwable )
				]
			);
		}
	}

	/**
	 * Fail before preview when a later focused apply dependency is disabled.
	 *
	 * @param array $documents Compiled canonical documents.
	 * @return true|\WP_Error
	 */
	private static function dependency_status( array $documents ) {
		$required = [ 'bricks/preview-site-changeset', 'bricks/apply-site-changeset' ];
		foreach ( $documents as $entry ) {
			if ( (string) ( $entry['scope'] ?? '' ) === 'page' ) {
				$required = array_merge( $required, [ 'bricks/preview-page-workspace', 'bricks/apply-page-workspace' ] );
				continue;
			}
			$resource = (string) ( $entry['document']['resource'] ?? '' );
			$focused  = [
				'globalVariable' => 'bricks/set-global-variables',
				'component'      => 'bricks/update-component',
			];
			$required = array_merge( $required, [ 'bricks/preview-design-resource-workspace', 'bricks/apply-design-resource-workspace' ] );
			if ( isset( $focused[ $resource ] ) ) {
				$required[] = $focused[ $resource ];
			}
		}

		foreach ( array_unique( $required ) as $name ) {
			if ( ( class_exists( Manager::class ) && Manager::is_ability_disabled( $name ) ) || ! is_object( function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null ) ) {
				return Error::ability_disabled( $name );
			}
		}
		return true;
	}

	/** Build an HMAC-bound exact-selection digest. */
	private static function selection_digest( string $token, string $path, string $element_id, $baseline, $selected ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( [ $token, $path, $element_id, $baseline, $selected ] ), wp_salt( 'auth' ) );
	}

	/** Return a compact reference authenticated to one workspace and selection. */
	private static function selection_ref( int $index, string $token, string $path, string $element_id, string $digest ): string {
		$tag = substr( hash_hmac( 'sha256', $token . "\0" . $path . "\0" . $element_id . "\0" . $digest, wp_salt( 'auth' ) ), 0, 16 );
		return 's' . $index . '_' . $tag;
	}

	/** Return the request identity bound to one direct typed commit. */
	private static function commit_request_digest( string $token, $operations ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				[
					'userId'         => get_current_user_id(),
					'siteId'         => get_current_blog_id(),
					'workspaceToken' => $token,
					'operations'     => $operations,
				]
			)
		);
	}

	/** Return the hidden Site Changeset token bound to the exact direct request. */
	private static function direct_preview_token( string $token, string $key, $operations ): string {
		return hash_hmac( 'sha256', self::commit_request_digest( $token, $operations ) . "\0" . $key, wp_salt( 'auth' ) );
	}

	/**
	 * Store one workspace in cache-safe bounded chunks and cap active workspaces.
	 *
	 * @param string $token     Workspace token.
	 * @param array  $workspace Frozen workspace.
	 * @return true|\WP_Error
	 */
	private static function store_workspace( string $token, array $workspace ) {
		$encoded = (string) wp_json_encode( $workspace );
		if ( $encoded === '' ) {
			return Error::internal_error( 'site-edit-map', 'Could not encode the short-lived site edit workspace. No Bricks data was changed.' );
		}

		$lock = self::acquire_workspace_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$base   = self::transient_key( $token );
			$chunks = str_split( $encoded, self::WORKSPACE_CHUNK_BYTES );
			if ( count( $chunks ) > self::MAX_WORKSPACE_CHUNKS ) {
				return Error::conflict( 'site_edit_workspace_too_large', [ 'message' => 'The short-lived site edit workspace exceeds the 8 MiB storage bound. Split the target set and retry checkout.' ] );
			}
			foreach ( $chunks as $index => $chunk ) {
				if ( self::set_transient_verified( $base . '_part_' . $index, $chunk, self::WORKSPACE_TTL ) ) {
					continue;
				}
				self::delete_workspace_parts( $base, count( $chunks ) );
				return Error::internal_error( 'site-edit-map', 'Could not persist the short-lived site edit workspace. No Bricks data was changed.' );
			}

			$meta = [
				'partCount' => count( $chunks ),
				'bytes'     => strlen( $encoded ),
				'expiresAt' => (int) $workspace['expiresAt'],
			];
			if ( ! self::set_transient_verified( $base, $meta, self::WORKSPACE_TTL ) ) {
				self::delete_workspace_parts( $base, count( $chunks ) );
				return Error::internal_error( 'site-edit-map', 'Could not persist the short-lived site edit workspace. No Bricks data was changed.' );
			}

			$index_key = self::active_index_key();
			$active    = get_transient( $index_key );
			$active    = is_array( $active ) ? $active : [];
			$now       = time();
			$active    = array_values(
				array_filter(
					$active,
					static function ( $entry ) use ( $token, $now ): bool {
						return is_array( $entry ) && (string) ( $entry['token'] ?? '' ) !== $token && (int) ( $entry['expiresAt'] ?? 0 ) >= $now;
					}
				)
			);
			$active[]  = [
				'token'     => $token,
				'expiresAt' => (int) $workspace['expiresAt']
			];
			$overflow  = max( 0, count( $active ) - self::MAX_ACTIVE_WORKSPACES );
			while ( $overflow > 0 ) {
				$evicted = array_shift( $active );
				self::delete_workspace( (string) ( $evicted['token'] ?? '' ), false );
				--$overflow;
			}
			if ( ! self::set_transient_verified( $index_key, $active, self::WORKSPACE_TTL ) ) {
				self::delete_workspace( $token, false );
				return Error::internal_error( 'site-edit-map', 'Could not index the short-lived site edit workspace. No Bricks data was changed.' );
			}

			return true;
		} finally {
			self::release_workspace_lock( $lock );
		}
	}

	/**
	 * Persist a transient while accepting WordPress's false return for an
	 * unchanged existing value only after exact immediate readback.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiry in seconds.
	 * @return bool
	 */
	private static function set_transient_verified( string $key, $value, int $expiration ): bool {
		if ( set_transient( $key, $value, $expiration ) ) {
			return true;
		}

		return get_transient( $key ) === $value;
	}

	/**
	 * Read and decode one chunked workspace.
	 *
	 * @param string $token Workspace token.
	 * @return array|false
	 */
	private static function read_workspace( string $token ) {
		$base = self::transient_key( $token );
		$meta = get_transient( $base );
		if ( ! is_array( $meta ) || (int) ( $meta['partCount'] ?? 0 ) < 1 || (int) ( $meta['partCount'] ?? 0 ) > self::MAX_WORKSPACE_CHUNKS ) {
			return false;
		}

		$encoded = '';
		for ( $index = 0; $index < (int) $meta['partCount']; ++$index ) {
			$chunk = get_transient( $base . '_part_' . $index );
			if ( ! is_string( $chunk ) ) {
				return false;
			}
			$encoded .= $chunk;
		}
		if ( strlen( $encoded ) !== (int) ( $meta['bytes'] ?? -1 ) ) {
			return false;
		}

		$workspace = json_decode( $encoded, true );
		return is_array( $workspace ) ? $workspace : false;
	}

	/**
	 * Delete a workspace and optionally remove it from the active index.
	 *
	 * @param string $token        Workspace token.
	 * @param bool   $update_index Whether to update the active index.
	 * @return void
	 */
	private static function delete_workspace( string $token, bool $update_index = true ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return;
		}
		$lock = $update_index ? self::acquire_workspace_lock() : null;
		if ( is_wp_error( $lock ) ) {
			return;
		}

		try {
			$base = self::transient_key( $token );
			$meta = get_transient( $base );
			self::delete_workspace_parts( $base, is_array( $meta ) ? (int) ( $meta['partCount'] ?? 0 ) : 0 );

			if ( ! $update_index ) {
				return;
			}
			$index_key = self::active_index_key();
			$active    = get_transient( $index_key );
			if ( ! is_array( $active ) ) {
				return;
			}
			$active = array_values(
				array_filter(
					$active,
					static function ( $entry ) use ( $token ): bool {
						return (string) ( $entry['token'] ?? '' ) !== $token;
					}
				)
			);
			set_transient( $index_key, $active, self::WORKSPACE_TTL );
		} finally {
			if ( is_string( $lock ) ) {
				self::release_workspace_lock( $lock );
			}
		}
	}

	/**
	 * Acquire the per-user/site database-session lock for workspace index mutations.
	 *
	 * @return string|\WP_Error
	 */
	private static function acquire_workspace_lock() {
		global $wpdb;
		$name = 'bricks_se_' . substr( hash( 'sha256', self::active_index_key() ), 0, 40 );
		$got  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session lock serializes the bounded workspace index.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name )
		);
		return (string) $got === '1' ? $name : Error::conflict( 'site_edit_workspace_busy', [ 'message' => 'Another site edit workspace is being updated. Retry checkout shortly.' ] );
	}

	/** Acquire one direct-commit lock so preview and durable journal initialization cannot race. */
	private static function acquire_direct_commit_lock( string $token, string $key ) {
		global $wpdb;
		$name = 'bricks_se_commit_' . substr( hash( 'sha256', get_current_blog_id() . "\0" . get_current_user_id() . "\0" . $token . "\0" . $key ), 0, 32 );
		$got  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session lock fences direct preview creation and replay.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name )
		);
		return (string) $got === '1' ? $name : Error::conflict( 'site_edit_commit_in_progress', [ 'message' => 'This typed edit plan is already committing. Retry the same workspace token and idempotency key shortly.' ] );
	}

	/**
	 * Release the per-user/site workspace index lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_workspace_lock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the session lock acquired above.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name )
		);
	}

	/** Delete bounded transient parts and metadata. */
	private static function delete_workspace_parts( string $base, int $part_count ): void {
		$part_count = max( 0, min( self::MAX_WORKSPACE_CHUNKS, $part_count ) );
		for ( $index = 0; $index < $part_count; ++$index ) {
			delete_transient( $base . '_part_' . $index );
		}
		delete_transient( $base );
	}

	/** Return the current user/site active-workspace index key. */
	private static function active_index_key(): string {
		$identity = get_current_blog_id() . ':' . get_current_user_id();
		return self::TRANSIENT_PREFIX . 'active_' . hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}

	/** Build a non-enumerable transient key. */
	private static function transient_key( string $token ): string {
		return self::TRANSIENT_PREFIX . hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/** Return the security-relevant caller policy bound to this workspace. */
	private static function execution_policy(): array {
		return [
			'userId'         => get_current_user_id(),
			'canExecuteCode' => \Bricks\Capabilities::current_user_can_execute_code(),
		];
	}

	/** Return a server-authenticated digest for the frozen transient payload. */
	private static function workspace_digest( array $workspace ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( $workspace ), wp_salt( 'auth' ) );
	}

	/** Return bounded plain text for the public map. */
	private static function public_text( $value, int $limit = 1000 ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		$bounded = substr( $value, 0, $limit );
		while ( $bounded !== '' && preg_match( '//u', $bounded ) !== 1 ) {
			$bounded = substr( $bounded, 0, -1 );
		}
		return $bounded;
	}

	/** Return safe public accessibility attributes only. */
	private static function public_attributes( $attributes ): array {
		$output = [];
		foreach ( is_array( $attributes ) ? $attributes : [] as $attribute ) {
			$name = strtolower( (string) ( $attribute['name'] ?? '' ) );
			if ( preg_match( '/^(?:role|aria-[a-z0-9_.:-]+)$/', $name ) ) {
				$output[] = [
					'name'  => $name,
					'value' => self::public_text( $attribute['value'] ?? '', 500 )
				];
			}
		}
		return $output;
	}

	/** Return style key names without exposing executable or opaque settings. */
	private static function style_keys( array $settings ): array {
		return array_values(
			array_filter(
				array_map( 'strval', array_keys( $settings ) ),
				static function ( string $key ): bool {
					return strpos( $key, '_' ) === 0 && ! in_array( $key, [ '_cssCustom', '_conditions', '_interactions' ], true );
				}
			)
		);
	}
}

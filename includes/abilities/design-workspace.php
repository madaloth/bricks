<?php
/**
 * Design-resource workspace abilities.
 *
 * File-oriented checkout, preview, and token-only apply for one existing
 * global class, global variable, theme style, or component at a time.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe, scoped design-resource workspaces for coding agents.
 */
class Design_Workspace {
	const CONTRACT_VERSION   = '1';
	const SCHEMA_VERSION     = 1;
	const PREVIEW_TTL        = 900;
	const PREVIEW_PREFIX     = 'bricks_agent_design_preview_';
	const JOURNAL_PREFIX     = 'bricks_agent_design_journal_';
	const MAX_TERMINAL_BYTES = 8 * MB_IN_BYTES;

	/**
	 * Destroy one design-workspace preview which an outer coordinator inspected
	 * but will never apply directly.
	 *
	 * @param string $token Focused preview bearer token.
	 * @return bool
	 */
	public static function discard_preview( string $token ): bool {
		if ( $token === '' ) {
			return false;
		}

		return delete_transient( self::preview_key( $token ) );
	}

	/**
	 * Supported public resource names.
	 *
	 * @return string[]
	 */
	private static function resources(): array {
		return [ 'globalClass', 'globalVariable', 'themeStyle', 'component' ];
	}

	/**
	 * Checkout input schema.
	 *
	 * @return array
	 */
	public static function checkout_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'resource' => [
					'type' => 'string',
					'enum' => self::resources(),
				],
				'id'       => [ 'type' => 'string' ],
			],
			'required'             => [ 'resource', 'id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Preview input schema.
	 *
	 * @return array
	 */
	public static function preview_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'document'         => [ 'type' => 'object' ],
				'allowSlotOrphans' => [
					'type'        => 'boolean',
					'description' => __( 'Explicitly acknowledge removed component slots after reviewing their use. Bound into the frozen preview.', 'bricks' ),
				],
			],
			'required'             => [ 'document' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Token-only apply input schema.
	 *
	 * @return array
	 */
	public static function apply_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'previewToken'   => [ 'type' => 'string' ],
				'idempotencyKey' => [
					'type'      => 'string',
					'maxLength' => 128,
				],
			],
			'required'             => [ 'previewToken', 'idempotencyKey' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Shared loose output schema. The response is resource-specific but always
	 * includes the workspace and transaction fields declared here.
	 *
	 * @return array
	 */
	public static function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion'    => [ 'type' => 'string' ],
				'schemaVersion'      => [ 'type' => 'integer' ],
				'manifest'           => [ 'type' => 'object' ],
				'files'              => [ 'type' => 'object' ],
				'resource'           => [ 'type' => 'string' ],
				'id'                 => [ 'type' => 'string' ],
				'changed'            => [ 'type' => 'boolean' ],
				'previewToken'       => [ 'type' => [ 'string', 'null' ] ],
				'expiresAt'          => [ 'type' => [ 'integer', 'null' ] ],
				'candidateDigest'    => [ 'type' => 'string' ],
				'diff'               => [ 'type' => 'object' ],
				'validation'         => [ 'type' => 'object' ],
				'transactionState'   => [ 'type' => 'string' ],
				'normalizationDelta' => [ 'type' => [ 'object', 'null' ] ],
				'preparedFile'       => [ 'type' => [ 'object', 'null' ] ],
				'authoritative'      => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Checkout permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function checkout_permission( $input ) {
		return self::resource_permission( (string) ( $input['resource'] ?? '' ), false );
	}

	/**
	 * Preview permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_permission( $input ) {
		$document = is_array( $input['document'] ?? null ) ? $input['document'] : [];

		return self::resource_permission( (string) ( $document['resource'] ?? '' ), true );
	}

	/**
	 * Apply permission callback resolves the frozen candidate before routing to
	 * the focused write permission. A consumed idempotent retry uses its journal.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function apply_permission( $input ) {
		$token     = (string) ( $input['previewToken'] ?? '' );
		$candidate = $token !== '' ? get_transient( self::preview_key( $token ) ) : false;

		if ( ! is_array( $candidate ) ) {
			$key     = (string) ( $input['idempotencyKey'] ?? '' );
			$journal = $key !== '' ? get_option( self::journal_key( get_current_user_id(), $key ), null ) : null;

			if ( is_array( $journal ) && hash_equals( (string) ( $journal['previewTokenHash'] ?? '' ), hash( 'sha256', $token ) ) ) {
				if ( array_key_exists( 'result', $journal ) && self::digest( $journal['executionPolicy'] ?? [] ) !== self::digest( self::execution_policy() ) ) {
					return Error::conflict( 'design_workspace_execution_policy_changed', [ 'message' => 'The caller execution policy changed after preview. Preview again under the current permissions.' ] );
				}

				return self::resource_permission( (string) ( $journal['resource'] ?? '' ), true );
			}

			return Error::conflict( 'design_workspace_preview_missing', [ 'message' => 'The design workspace preview is missing, expired, consumed, or belongs to another operation. Preview the file again.' ] );
		}

		return self::resource_permission( (string) ( $candidate['resource'] ?? '' ), true );
	}

	/**
	 * Export one existing resource as a deterministic canonical file.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function checkout( $input ) {
		$resource = (string) ( $input['resource'] ?? '' );
		$id       = (string) ( $input['id'] ?? '' );
		$valid    = self::validate_target( $resource, $id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$read = self::read_resource( $resource, $id );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		$document = self::workspace_document( $resource, $id, $read );
		$path     = self::file_path( $resource, $id );

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'schemaVersion'   => self::SCHEMA_VERSION,
			'manifest'        => [
				'resource'     => $resource,
				'id'           => $id,
				'editableFile' => $path,
				'files'        => [
					$path => [
						'authority' => 'canonical-working-copy',
						'sha256'    => self::digest( $document ),
					],
				],
				'applyScope'   => 'single-existing-resource',
			],
			'files'           => [ $path => $document ],
		];
	}

	/**
	 * Validate and freeze an edited resource without writing Bricks data.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview( $input ) {
		$document = $input['document'] ?? null;

		if ( ! is_array( $document ) ) {
			return Error::invalid_param( 'document', 'a checked-out design workspace object', $document );
		}

		$shape = self::validate_document( $document );

		if ( is_wp_error( $shape ) ) {
			return $shape;
		}

		$size = self::validate_document_size( $document );

		if ( is_wp_error( $size ) ) {
			return $size;
		}

		$resource   = (string) $document['resource'];
		$id         = (string) $document['id'];
		$permission = self::resource_permission( $resource, true );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$live = self::read_resource( $resource, $id );

		if ( is_wp_error( $live ) ) {
			return $live;
		}

		if ( ! hash_equals( self::digest( $live['baseline'] ), self::digest( $document['baseline'] ) ) ) {
			return Error::conflict(
				'design_workspace_baseline_changed',
				[
					'message'          => 'The design resource or one of its guarded dependencies changed after checkout. Check it out again.',
					'resource'         => $resource,
					'id'               => $id,
					'expectedBaseline' => $document['baseline'],
					'actualBaseline'   => $live['baseline'],
				]
			);
		}

		$live_editable   = self::editable_projection( $resource, $live['item'] );
		$item_validation = self::validate_item( $resource, $id, $live_editable, $document['item'] );

		if ( is_wp_error( $item_validation ) ) {
			return $item_validation;
		}

		$prepared_item = $resource === 'component'
			? $document['item']
			: self::prepare_item( $resource, $id, $document['item'] );

		if ( is_wp_error( $prepared_item ) ) {
			return $prepared_item;
		}

		$normalization_paths = self::changed_paths( $document['item'], $prepared_item, '/item' );
		$document['item']    = $prepared_item;

		$changed_paths = self::changed_paths( $live_editable, $prepared_item );

		if ( $resource === 'component' && ! empty( $changed_paths ) ) {
			$component_input = self::focused_update_input(
				$resource,
				$id,
				$live,
				$document['item'],
				! empty( $input['allowSlotOrphans'] )
			);

			if ( is_wp_error( $component_input ) ) {
				return $component_input;
			}

			$component_prepared = Design::prepare_component_update_preview( $component_input );

			if ( is_wp_error( $component_prepared ) ) {
				return $component_prepared;
			}

			$prepared_item       = self::editable_projection( 'component', $component_prepared['component'] );
			$normalization_paths = self::changed_paths( $document['item'], $prepared_item, '/item' );
			$document['item']    = $prepared_item;
			$changed_paths       = self::changed_paths( $live_editable, $prepared_item );
		}

		if ( empty( $changed_paths ) ) {
			return [
				'contractVersion'    => self::CONTRACT_VERSION,
				'resource'           => $resource,
				'id'                 => $id,
				'changed'            => false,
				'previewToken'       => null,
				'expiresAt'          => null,
				'candidateDigest'    => self::digest( $document ),
				'diff'               => [ 'changedPaths' => [] ],
				'validation'         => self::validation_contract( $resource ),
				'normalizationDelta' => empty( $normalization_paths ) ? null : [ 'changedPaths' => $normalization_paths ],
				'preparedFile'       => empty( $normalization_paths ) ? null : $document,
				'transactionState'   => 'no_change',
			];
		}

		$focused_input = self::focused_update_input(
			$resource,
			$id,
			$live,
			$document['item'],
			! empty( $input['allowSlotOrphans'] )
		);

		if ( is_wp_error( $focused_input ) ) {
			return $focused_input;
		}

		$focused_validation = self::validate_focused_input( $resource, $focused_input );

		if ( is_wp_error( $focused_validation ) ) {
			return $focused_validation;
		}

		$expires_at                   = time() + self::PREVIEW_TTL;
		$candidate                    = [
			'contractVersion' => self::CONTRACT_VERSION,
			'resource'        => $resource,
			'id'              => $id,
			'userId'          => get_current_user_id(),
			'executionPolicy' => self::execution_policy(),
			'baseline'        => $live['baseline'],
			'item'            => $document['item'],
			'focusedInput'    => $focused_input,
			'changedPaths'    => $changed_paths,
			'expiresAt'       => $expires_at,
		];
		$candidate['candidateDigest'] = self::digest( $candidate );
		$token                        = bin2hex( random_bytes( 32 ) );

		if ( ! set_transient( self::preview_key( $token ), $candidate, self::PREVIEW_TTL ) ) {
			return Error::internal_error( 'design-workspace-preview', 'Could not persist the short-lived design workspace preview. No design data was changed.' );
		}

		return [
			'contractVersion'    => self::CONTRACT_VERSION,
			'resource'           => $resource,
			'id'                 => $id,
			'changed'            => true,
			'previewToken'       => $token,
			'expiresAt'          => $expires_at,
			'candidateDigest'    => $candidate['candidateDigest'],
			'diff'               => [ 'changedPaths' => $changed_paths ],
			'validation'         => self::validation_contract( $resource ),
			'normalizationDelta' => empty( $normalization_paths ) ? null : [ 'changedPaths' => $normalization_paths ],
			'preparedFile'       => empty( $normalization_paths ) ? null : $document,
			'transactionState'   => 'previewed',
		];
	}

	/**
	 * Commit the exact frozen resource candidate through its focused ability.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function apply( $input ) {
		$token           = (string) ( $input['previewToken'] ?? '' );
		$idempotency_key = (string) ( $input['idempotencyKey'] ?? '' );

		if ( $token === '' ) {
			return Error::invalid_param( 'previewToken', 'a non-empty token from preview-design-resource-workspace', $token );
		}

		if ( $idempotency_key === '' || strlen( $idempotency_key ) > 128 ) {
			return Error::invalid_param( 'idempotencyKey', 'a non-empty string no longer than 128 characters', $idempotency_key );
		}

		$journal_key = self::journal_key( get_current_user_id(), $idempotency_key );
		$lock        = self::acquire_lock( $journal_key );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$token_hash = hash( 'sha256', $token );
			$journal    = get_option( $journal_key, null );

			if ( is_array( $journal ) ) {
				if ( ! hash_equals( (string) ( $journal['previewTokenHash'] ?? '' ), $token_hash ) ) {
					return Error::conflict( 'design_workspace_idempotency_mismatch', [ 'message' => 'This idempotency key is already bound to another design workspace preview.' ] );
				}

				$permission = self::resource_permission( (string) ( $journal['resource'] ?? '' ), true );

				if ( is_wp_error( $permission ) ) {
					return $permission;
				}

				if ( array_key_exists( 'result', $journal ) ) {
					if ( self::digest( $journal['executionPolicy'] ?? [] ) !== self::digest( self::execution_policy() ) ) {
						return Error::conflict( 'design_workspace_execution_policy_changed', [ 'message' => 'The caller execution policy changed after preview. Preview again under the current permissions.' ] );
					}

					return $journal['result'];
				}

				return Error::conflict(
					'design_workspace_recovery_required',
					[
						'message'                => 'A previous apply stopped after preparing this exact focused write but before recording its outcome. Re-read the resource before manual recovery; do not retry with another key.',
						'manualRecoveryRequired' => true,
						'resource'               => $journal['resource'] ?? '',
						'id'                     => $journal['id'] ?? '',
						'candidateDigest'        => $journal['candidateDigest'] ?? '',
					]
				);
			}

			$candidate = get_transient( self::preview_key( $token ) );

			if ( ! is_array( $candidate ) ) {
				return Error::conflict( 'design_workspace_preview_missing', [ 'message' => 'The design workspace preview is missing, expired, or already consumed. Preview the file again.' ] );
			}

			if ( (int) ( $candidate['userId'] ?? 0 ) !== get_current_user_id() || (int) ( $candidate['expiresAt'] ?? 0 ) < time() ) {
				return Error::conflict( 'design_workspace_preview_expired', [ 'message' => 'The design workspace preview expired or belongs to another user. Preview the file again.' ] );
			}

			if ( ! hash_equals( (string) $candidate['candidateDigest'], self::candidate_digest( $candidate ) ) ) {
				return Error::conflict( 'design_workspace_preview_corrupt', [ 'message' => 'The frozen design workspace preview failed its integrity check. Preview the file again.' ] );
			}

			if ( self::digest( $candidate['executionPolicy'] ?? [] ) !== self::digest( self::execution_policy() ) ) {
				return Error::conflict( 'design_workspace_execution_policy_changed', [ 'message' => 'The caller execution policy changed after preview. Preview again under the current permissions.' ] );
			}

			$resource   = (string) $candidate['resource'];
			$id         = (string) $candidate['id'];
			$permission = self::resource_permission( $resource, true );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$live = self::read_resource( $resource, $id );

			if ( is_wp_error( $live ) ) {
				return $live;
			}

			if ( ! hash_equals( self::digest( $candidate['baseline'] ), self::digest( $live['baseline'] ) ) ) {
				return Error::conflict(
					'design_workspace_baseline_changed',
					[
						'message'        => 'The design resource or a guarded dependency changed after preview. Check it out and preview again.',
						'actualBaseline' => $live['baseline']
					]
				);
			}

			$prepared = [
				'previewTokenHash' => $token_hash,
				'resource'         => $resource,
				'id'               => $id,
				'candidateDigest'  => $candidate['candidateDigest'],
				'executionPolicy'  => $candidate['executionPolicy'],
				'createdAt'        => time(),
			];

			if ( ! add_option( $journal_key, $prepared, '', 'no' ) ) {
				return Error::conflict( 'design_workspace_idempotency_race', [ 'message' => 'Another request claimed this idempotency key. Retry the same token and key to inspect its result.' ] );
			}

			$scheduled = wp_schedule_single_event( time() + DAY_IN_SECONDS, 'bricks_cleanup_agent_design_journal', [ $journal_key ], true );

			if ( is_wp_error( $scheduled ) || $scheduled === false ) {
				delete_option( $journal_key );

				return Error::internal_error( 'design-workspace-apply', 'The idempotency cleanup event could not be scheduled, so apply stopped before changing design data.' );
			}

			$result = self::execute_focused_update( $resource, $candidate['focusedInput'] );

			if ( is_wp_error( $result ) ) {
				$data             = $result->get_error_data();
				$ambiguous_commit = self::is_ambiguous_focused_error( $result );

				if ( $ambiguous_commit ) {
					$result = Error::internal_error(
						'design-workspace-focused-outcome',
						'The focused ability stopped after execution began and its commit state cannot be proven. Do not retry with another key; read the resource before manual recovery.',
						[
							'commitState'            => 'unknown',
							'manualRecoveryRequired' => true,
							'resource'               => $resource,
							'id'                     => $id,
							'focusedError'           => self::compact_error( $result ),
						]
					);
				}

				if ( $ambiguous_commit || ( is_array( $data ) && ! empty( $data['committed'] ) ) ) {
					if ( ! self::persist_terminal_result( $journal_key, $token_hash, $resource, $candidate['executionPolicy'], $result ) ) {
						delete_transient( self::preview_key( $token ) );

						return self::journal_persistence_error( $resource, $id );
					}
					delete_transient( self::preview_key( $token ) );
				} else {
					delete_option( $journal_key );
				}

				return $result;
			}

			$authoritative = self::read_resource( $resource, $id );

			if ( is_wp_error( $authoritative ) ) {
				$result = Error::internal_error(
					'design-workspace-readback',
					'The focused design write committed, but workspace readback failed. Do not retry with another key; read the resource before recovery.',
					[
						'committed'              => true,
						'manualRecoveryRequired' => true,
						'resource'               => $resource,
						'id'                     => $id,
						'focusedResult'          => self::compact_focused_result( $resource, $id, $result ),
					]
				);
			} else {
				$expected_projection      = self::editable_projection( $resource, $candidate['item'] );
				$authoritative_projection = self::editable_projection( $resource, $authoritative['item'] );
				$normalization_paths      = self::changed_paths( $expected_projection, $authoritative_projection, '/item' );
				$authoritative_document   = self::workspace_document( $resource, $id, $authoritative );
				$authoritative_path       = self::file_path( $resource, $id );
				$authoritative_workspace  = [
					'manifest' => [
						'editableFile' => $authoritative_path,
						'sha256'       => self::digest( $authoritative_document ),
					],
					'files'    => [ $authoritative_path => $authoritative_document ],
				];

				if ( ! empty( $normalization_paths ) ) {
					$result = Error::internal_error(
						'design-workspace-readback-mismatch',
						'The focused ability committed, but its authoritative editable data differs from the frozen file. Stop the plan and check out the resource again before manual recovery.',
						[
							'committed'              => true,
							'manualRecoveryRequired' => true,
							'resource'               => $resource,
							'id'                     => $id,
							'changedPaths'           => $normalization_paths,
							'expectedDigest'         => self::digest( $expected_projection ),
							'authoritative'          => $authoritative_workspace['manifest'],
							'focusedResult'          => self::compact_focused_result( $resource, $id, $result ),
						]
					);
				} else {
					$result = [
						'contractVersion'    => self::CONTRACT_VERSION,
						'resource'           => $resource,
						'id'                 => $id,
						'changed'            => true,
						'candidateDigest'    => $candidate['candidateDigest'],
						'diff'               => [ 'changedPaths' => $candidate['changedPaths'] ],
						'transactionState'   => 'committed',
						'normalizationDelta' => null,
						'focusedResult'      => self::compact_focused_result( $resource, $id, $result ),
						'authoritative'      => $authoritative_workspace,
					];
				}
			}

			if ( self::terminal_result_size( $result ) > self::MAX_TERMINAL_BYTES ) {
				$result = Error::internal_error(
					'design-workspace-terminal-size',
					'The design write committed, but its authoritative terminal envelope exceeded the durable journal limit. Re-read the resource before manual recovery.',
					[
						'committed'              => true,
						'manualRecoveryRequired' => true,
						'resource'               => $resource,
						'id'                     => $id,
					]
				);
			}

			if ( ! self::persist_terminal_result( $journal_key, $token_hash, $resource, $candidate['executionPolicy'], $result ) ) {
				delete_transient( self::preview_key( $token ) );

				return self::journal_persistence_error( $resource, $id );
			}
			delete_transient( self::preview_key( $token ) );

			return $result;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Route a resource to its existing read or write permission contract.
	 *
	 * @param string $resource Resource name.
	 * @param bool   $write    Whether write permission is required.
	 * @return true|\WP_Error
	 */
	private static function resource_permission( string $resource, bool $write ) {
		switch ( $resource ) {
			case 'globalClass':
				return $write ? Design::edit_global_class_permission( [] ) : Design::global_classes_read_permission( [] );
			case 'globalVariable':
				return $write ? Design::global_variables_write_permission( [] ) : Design::global_variables_read_permission( [] );
			case 'themeStyle':
				return Design::theme_styles_permission( [] );
			case 'component':
				return $write ? Design::update_component_permission( [] ) : Design::components_permission( [] );
		}

		return Error::invalid_param( 'resource', 'globalClass, globalVariable, themeStyle, or component', $resource );
	}

	/**
	 * Read one complete authoritative item and its focused write preconditions.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Item ID.
	 * @return array|\WP_Error
	 */
	private static function read_resource( string $resource, string $id ) {
		if ( $resource === 'globalClass' ) {
			$snapshot = Design::global_classes_snapshot();

			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			foreach ( $snapshot['classes'] as $item ) {
				if ( (string) ( $item['id'] ?? '' ) !== $id ) {
					continue;
				}

				$item_ownership = $item['itemOwnership'];
				unset( $item['itemDigest'], $item['itemOwnership'] );

				return [
					'item'     => $item,
					'baseline' => [
						'expectedOwnership'         => $item_ownership,
						'lockOwnership'             => $snapshot['lockOwnership'],
						'expectedCategoryOwnership' => $snapshot['categoryOwnership'],
					],
				];
			}

			return Error::not_found( 'global_class', $id );
		}

		if ( $resource === 'globalVariable' ) {
			$page = 1;

			do {
				$response = Design::list_global_variables(
					[
						'page'    => $page,
						'perPage' => 100
					]
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				foreach ( $response['items'] as $item ) {
					if ( (string) ( $item['id'] ?? '' ) !== $id ) {
						continue;
					}

					$item_ownership = $item['itemOwnership'];
					unset( $item['itemDigest'], $item['itemOwnership'] );

					return [
						'item'     => $item,
						'baseline' => [
							'expectedVariableOwnership' => $response['variableOwnership'],
							'expectedCategoryOwnership' => $response['categoryOwnership'],
							'itemOwnership'             => $item_ownership,
						],
					];
				}

				$page++;
			} while ( ! empty( $response['hasMore'] ) );

			return Error::not_found( 'global_variable', $id );
		}

		if ( $resource === 'themeStyle' ) {
			$response = Design::get_theme_styles( [ 'style' => $id ] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$ownership               = $response['ownership'];
			$ownership['itemDigest'] = $response['itemDigests'][ $id ];

			return [
				'item'     => $response['styles'][ $id ],
				'baseline' => [ 'expectedOwnership' => $ownership ],
			];
		}

		$response = Design::get_component( [ 'componentId' => $id ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['componentPayloadRedacted'] ) ) {
			return Error::conflict(
				'design_workspace_component_redacted',
				[
					'message'     => 'A lossless editable component workspace cannot be returned because executable settings were redacted. Use an execute-code-authorized caller or the focused metadata-only ability.',
					'componentId' => $id,
				]
			);
		}

		return [
			'item'     => $response['component'],
			'baseline' => [
				'expectedDesignSystemVersion' => $response['designSystemVersion'],
				'expectedComponentDigest'     => $response['componentDigest'],
			],
		];
	}

	/**
	 * Validate the outer workspace document.
	 *
	 * @param array $document Document.
	 * @return true|\WP_Error
	 */
	private static function validate_document( array $document ) {
		$allowed = [ 'schemaVersion', 'resource', 'id', 'baseline', 'item' ];
		$unknown = array_diff( array_keys( $document ), $allowed );

		if ( ! empty( $unknown ) ) {
			return Error::unknown_param( 'document.' . reset( $unknown ), $allowed );
		}

		if ( (int) ( $document['schemaVersion'] ?? 0 ) !== self::SCHEMA_VERSION ) {
			return Error::conflict( 'design_workspace_schema_mismatch', [ 'message' => 'The design workspace schema is unsupported. Check out a fresh file.' ] );
		}

		$target = self::validate_target( (string) ( $document['resource'] ?? '' ), (string) ( $document['id'] ?? '' ) );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( ! is_array( $document['baseline'] ?? null ) || ! is_array( $document['item'] ?? null ) ) {
			return Error::invalid_param( 'document', 'baseline and item objects from checkout', $document );
		}

		return true;
	}

	/**
	 * Validate target names.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Item ID.
	 * @return true|\WP_Error
	 */
	private static function validate_target( string $resource, string $id ) {
		if ( ! in_array( $resource, self::resources(), true ) ) {
			return Error::invalid_param( 'resource', 'globalClass, globalVariable, themeStyle, or component', $resource );
		}

		if ( $id === '' ) {
			return Error::invalid_param( 'id', 'a non-empty existing resource ID', $id );
		}

		return true;
	}

	/**
	 * Bound transient-backed preview payloads by resource complexity.
	 *
	 * @param array $document Workspace document.
	 * @return true|\WP_Error
	 */
	private static function validate_document_size( array $document ) {
		$resource = (string) ( $document['resource'] ?? '' );
		$limits   = [
			'globalClass'    => 2 * MB_IN_BYTES,
			'globalVariable' => 64 * KB_IN_BYTES,
			'themeStyle'     => 2 * MB_IN_BYTES,
			'component'      => 5 * MB_IN_BYTES,
		];
		$bytes    = strlen( (string) wp_json_encode( $document ) );

		if ( isset( $limits[ $resource ] ) && $bytes > $limits[ $resource ] ) {
			return Error::invalid_param( 'document', sprintf( 'a %s workspace no larger than %d bytes', $resource, $limits[ $resource ] ), $bytes );
		}

		if ( $resource === 'component' && count( (array) ( $document['item']['elements'] ?? [] ) ) > 5000 ) {
			return Error::invalid_param( 'document.item.elements', 'no more than 5000 component elements', count( (array) $document['item']['elements'] ) );
		}

		return true;
	}

	/**
	 * Validate immutable fields, editable shapes, and unsupported opaque edits.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Item ID.
	 * @param array  $before   Authoritative item.
	 * @param array  $after    Edited item.
	 * @return true|\WP_Error
	 */
	private static function validate_item( string $resource, string $id, array $before, array $after ) {
		if ( $resource !== 'themeStyle' && (string) ( $after['id'] ?? '' ) !== $id ) {
			return Error::conflict(
				'design_workspace_id_immutable',
				[
					'message' => 'Design workspace item IDs are immutable. Create/delete/rename are outside this update-only workflow.',
					'id'      => $id
				]
			);
		}

		$editable = [];

		if ( $resource === 'globalClass' ) {
			$editable = [ 'name', 'settings', 'selectors', 'category' ];

			if ( trim( (string) ( $after['name'] ?? '' ) ) === '' || ( isset( $after['settings'] ) && ! is_array( $after['settings'] ) ) || ( isset( $after['selectors'] ) && ! is_array( $after['selectors'] ) ) ) {
				return Error::invalid_param( 'document.item', 'a class with a non-empty name and object settings/array selectors when present', $after );
			}
		} elseif ( $resource === 'globalVariable' ) {
			$editable = [ 'name', 'value', 'category', 'scale' ];

			if ( (string) ( $after['name'] ?? '' ) !== (string) ( $before['name'] ?? '' ) ) {
				return Error::conflict( 'global_variable_reference_rewrite_not_atomic', [ 'message' => 'Renaming a global variable cannot atomically rewrite site references. The workspace is update-only; preserve its name.' ] );
			}

			if ( ! array_key_exists( 'value', $after ) || ( ! is_string( $after['value'] ) && ! is_numeric( $after['value'] ) ) ) {
				return Error::invalid_param( 'document.item.value', 'a string or number', $after['value'] ?? null );
			}

			if ( array_key_exists( 'category', $before ) && ! array_key_exists( 'category', $after ) ) {
				return Error::invalid_param( 'document.item.category', 'an explicit category ID or empty string; do not remove the field', null );
			}

			if ( array_key_exists( 'scale', $before ) && ! array_key_exists( 'scale', $after ) ) {
				return Error::conflict(
					'design_workspace_variable_scale_removal_unsupported',
					[ 'message' => 'The focused variable upsert cannot remove scale metadata safely. Preserve the scale field or use the scale generator workflow.' ]
				);
			}
		} elseif ( $resource === 'themeStyle' ) {
			$editable = [ 'label', 'settings' ];

			if ( isset( $after['label'] ) && trim( (string) $after['label'] ) === '' ) {
				return Error::invalid_param( 'document.item.label', 'a non-empty label', $after['label'] );
			}

			if ( isset( $after['settings'] ) && ! is_array( $after['settings'] ) ) {
				return Error::invalid_param( 'document.item.settings', 'an object', $after['settings'] );
			}
		} else {
			$editable = [ 'elements', 'properties', 'variants', 'category', 'desc', 'propertyGroups', 'blockEditor', 'blockCategory', 'blockIcon', 'blockPreviewImage' ];

			if ( empty( $after['elements'] ) || ! is_array( $after['elements'] ) || (string) ( $after['id'] ?? '' ) !== $id ) {
				return Error::invalid_param( 'document.item', 'a component with its immutable id and non-empty elements array', $after );
			}

			if ( trim( self::component_label( $after ) ) === '' ) {
				return Error::invalid_param( 'document.item.elements', 'a root element with a non-empty component label or name', $after['elements'] );
			}
		}

		$all_keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		foreach ( $all_keys as $key ) {
			if ( $key === 'id' || in_array( $key, $editable, true ) ) {
				continue;
			}

			if ( self::digest( $before[ $key ] ?? null ) !== self::digest( $after[ $key ] ?? null ) ) {
				return Error::conflict(
					'design_workspace_readonly_field',
					[
						'message' => sprintf( 'Field "%s" is preserved but not editable through this workspace because the focused Bricks ability does not own it.', $key ),
						'field'   => $key
					]
				);
			}
		}

		return true;
	}

	/**
	 * Produce the exact side-effect-free canonical item that apply is expected
	 * to persist. Generated selector/element IDs and safe style normalization
	 * happen during preview and are frozen into the token, so authoritative
	 * readback is compared with prepared data instead of raw agent input.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Resource ID.
	 * @param array  $item     Edited item.
	 * @return array|\WP_Error
	 */
	private static function prepare_item( string $resource, string $id, array $item ) {
		if ( $resource === 'globalClass' ) {
			if ( isset( $item['settings'] ) ) {
				$item['settings'] = Element_Style_Normalizer::normalize_global_class_settings(
					(string) $item['name'],
					$item['settings']
				);
			}

			foreach ( (array) ( $item['selectors'] ?? [] ) as $index => $selector ) {
				if ( empty( $selector['id'] ) ) {
					$item['selectors'][ $index ]['id'] = \Bricks\Helpers::generate_random_id( false );
				}
			}
		} elseif ( $resource === 'themeStyle' && isset( $item['label'] ) ) {
			$item['label'] = trim( (string) $item['label'] );
		}

		return $item;
	}

	/**
	 * Build the exact focused update input frozen by preview.
	 *
	 * @param string $resource          Resource name.
	 * @param string $id                Item ID.
	 * @param array  $live              Live read and baseline.
	 * @param array  $item              Edited item.
	 * @param bool   $allow_slot_orphans Slot-removal acknowledgement.
	 * @return array|\WP_Error
	 */
	private static function focused_update_input( string $resource, string $id, array $live, array $item, bool $allow_slot_orphans ) {
		$before   = $live['item'];
		$baseline = $live['baseline'];

		if ( $resource === 'globalClass' ) {
			$input = [
				'classId'           => $id,
				'expectedOwnership' => $baseline['expectedOwnership'],
				'lockOwnership'     => $baseline['lockOwnership'],
			];

			foreach ( [ 'name', 'selectors', 'category' ] as $key ) {
				if ( self::digest( $before[ $key ] ?? null ) !== self::digest( $item[ $key ] ?? null ) ) {
					$input[ $key ] = $item[ $key ] ?? ( $key === 'selectors' ? [] : '' );
				}
			}

			if ( self::digest( $before['settings'] ?? [] ) !== self::digest( $item['settings'] ?? [] ) ) {
				$input['settings'] = self::replacement_patch( $before['settings'] ?? [], $item['settings'] ?? [] );
			}

			if ( ! empty( $item['category'] ) ) {
				$input['expectedCategoryOwnership'] = $baseline['expectedCategoryOwnership'];
			}

			return $input;
		}

		if ( $resource === 'globalVariable' ) {
			$focused_item = array_intersect_key( $item, array_flip( [ 'id', 'name', 'value', 'category', 'scale' ] ) );

			return [
				'variables'                 => [ $focused_item ],
				'expectedVariableOwnership' => $baseline['expectedVariableOwnership'],
				'expectedCategoryOwnership' => $baseline['expectedCategoryOwnership'],
			];
		}

		if ( $resource === 'themeStyle' ) {
			$input = [
				'id'                => $id,
				'expectedOwnership' => $baseline['expectedOwnership'],
			];

			foreach ( [ 'label' ] as $key ) {
				if ( self::digest( $before[ $key ] ?? null ) !== self::digest( $item[ $key ] ?? null ) ) {
					$input[ $key ] = $item[ $key ] ?? '';
				}
			}

			if ( self::digest( $before['settings'] ?? [] ) !== self::digest( $item['settings'] ?? [] ) ) {
				$input['settings'] = $item['settings'] ?? [];
				$input['replace']  = true;
			}

			return $input;
		}

		$input        = [
			'componentId'                 => $id,
			'expectedDesignSystemVersion' => $baseline['expectedDesignSystemVersion'],
			'expectedComponentDigest'     => $baseline['expectedComponentDigest'],
		];
		$before_label = self::component_label( $before );
		$after_label  = self::component_label( $item );

		if ( $before_label !== $after_label ) {
			$input['label'] = $after_label;
		}

		foreach ( [ 'elements', 'properties', 'variants', 'propertyGroups' ] as $key ) {
			if ( self::digest( $before[ $key ] ?? [] ) !== self::digest( $item[ $key ] ?? [] ) ) {
				$input[ $key ] = $item[ $key ] ?? [];
			}
		}

		foreach ( [ 'category', 'desc', 'blockCategory' ] as $key ) {
			if ( self::digest( $before[ $key ] ?? '' ) !== self::digest( $item[ $key ] ?? '' ) ) {
				$input[ $key ] = (string) ( $item[ $key ] ?? '' );
			}
		}

		if ( self::digest( ! empty( $before['blockEditor'] ) ) !== self::digest( ! empty( $item['blockEditor'] ) ) ) {
			$input['blockEditor'] = ! empty( $item['blockEditor'] );
		}

		foreach ( [ 'blockIcon', 'blockPreviewImage' ] as $key ) {
			if ( self::digest( $before[ $key ] ?? [] ) !== self::digest( $item[ $key ] ?? [] ) ) {
				$input[ $key ] = $item[ $key ] ?? [];
			}
		}

		if ( isset( $input['elements'] ) && $allow_slot_orphans ) {
			$input['allowSlotOrphans'] = true;
		}

		return $input;
	}

	/**
	 * Resolve the focused public schema for one workspace resource.
	 *
	 * @param string $resource Resource name.
	 * @return array|\WP_Error
	 */
	private static function focused_schema( string $resource ) {
		$callbacks = [
			'globalClass'    => [ Design::class, 'update_global_class_schema' ],
			'globalVariable' => [ Design::class, 'set_global_variables_schema' ],
			'themeStyle'     => [ Design::class, 'update_theme_style_schema' ],
			'component'      => [ Design::class, 'update_component_schema' ],
		];

		if ( ! isset( $callbacks[ $resource ] ) || ! is_callable( $callbacks[ $resource ] ) ) {
			return Error::internal_error( 'design-workspace-focused-schema', 'The focused design schema is unavailable. No design data was changed.' );
		}

		return call_user_func( $callbacks[ $resource ] );
	}

	/**
	 * Registered focused write ability for a workspace resource.
	 *
	 * @param string $resource Resource name.
	 * @return string
	 */
	private static function focused_ability_name( string $resource ): string {
		$names = [
			'globalClass'    => 'bricks/update-global-class',
			'globalVariable' => 'bricks/set-global-variables',
			'themeStyle'     => 'bricks/update-theme-style',
			'component'      => 'bricks/update-component',
		];

		return $names[ $resource ] ?? '';
	}

	/**
	 * Run the focused public input schema during side-effect-free preview.
	 *
	 * @param string $resource Resource name.
	 * @param array  $input    Proposed focused input.
	 * @return true|\WP_Error
	 */
	private static function validate_focused_input( string $resource, array $input ) {
		$schema = self::focused_schema( $resource );

		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
			return Error::internal_error( 'design-workspace-focused-schema', 'WordPress schema validation is unavailable. No design data was changed.' );
		}

		return rest_validate_value_from_schema( $input, $schema, 'input' );
	}

	/**
	 * Execute one focused update through the registered public ability contract.
	 * This preserves the admin deny-list, schema/permission/output validation,
	 * execution hooks, normalization, referential validation, CAS, and readback.
	 *
	 * @param string $resource Resource name.
	 * @param array  $input    Frozen focused input.
	 * @return array|\WP_Error
	 */
	private static function execute_focused_update( string $resource, array $input ) {
		$ability_name = self::focused_ability_name( $resource );
		$ability      = $ability_name !== '' && function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;

		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return Error::internal_error( 'design-workspace-focused-update', 'The focused design update is unavailable. No design data was changed.' );
		}

		try {
			return $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			return Error::internal_error(
				'design-workspace-focused-update',
				$throwable->getMessage(),
				[ 'exception' => get_class( $throwable ) ]
			);
		}
	}

	/**
	 * Treat unexpected errors after focused execution begins as an unknown
	 * commit state. Manager wraps every callback Throwable as
	 * bricks_internal_error, including a Throwable raised after persistence.
	 * Conservatively retaining the journal makes a readback/manual recovery
	 * necessary instead of allowing an unsafe retry under another key.
	 *
	 * @param \WP_Error $error Focused execution error.
	 * @return bool
	 */
	private static function is_ambiguous_focused_error( \WP_Error $error ): bool {
		return in_array(
			$error->get_error_code(),
			[ 'ability_callback_exception', 'ability_invalid_output', 'bricks_internal_error' ],
			true
		);
	}

	/**
	 * Remove duplicated canonical payloads from a focused result. The workspace
	 * already returns one authoritative file; keeping the same component or
	 * whole variable store again would multiply response and journal size.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Resource ID.
	 * @param mixed  $result   Focused result.
	 * @return mixed
	 */
	private static function compact_focused_result( string $resource, string $id, $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		if ( $resource === 'globalClass' ) {
			unset( $result['class'] );
		} elseif ( $resource === 'globalVariable' ) {
			$result['itemOwnership'] = $result['itemOwnerships'][ $id ] ?? null;
			unset( $result['variables'], $result['categories'], $result['itemOwnerships'] );
		} elseif ( $resource === 'themeStyle' ) {
			unset( $result['style'] );
		} elseif ( $resource === 'component' ) {
			unset( $result['component'] );
		}

		return $result;
	}

	/**
	 * Bound a nested focused error before storing it in another durable error.
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private static function compact_error( \WP_Error $error ): array {
		return [
			'code'    => $error->get_error_code(),
			'message' => substr( $error->get_error_message(), 0, 2000 ),
		];
	}

	/**
	 * Persist and verify a token-bound terminal journal result.
	 *
	 * @param string $journal_key Journal option key.
	 * @param string $token_hash  Preview-token hash.
	 * @param string $resource    Resource name.
	 * @param array  $policy      Execution policy bound by preview.
	 * @param mixed  $result      Terminal result.
	 * @return bool
	 */
	private static function persist_terminal_result( string $journal_key, string $token_hash, string $resource, array $policy, $result ): bool {
		if ( self::terminal_result_size( $result ) > self::MAX_TERMINAL_BYTES ) {
			return false;
		}

		$terminal = [
			'previewTokenHash' => $token_hash,
			'resource'         => $resource,
			'executionPolicy'  => $policy,
			'result'           => $result,
		];
		$expected = hash( 'sha256', maybe_serialize( $terminal ) );

		update_option( $journal_key, $terminal, false );

		$stored = get_option( $journal_key, null );

		return is_array( $stored ) && hash_equals( $expected, hash( 'sha256', maybe_serialize( $stored ) ) );
	}

	/**
	 * Measure a terminal result before durable storage.
	 *
	 * @param mixed $result Result.
	 * @return int
	 */
	private static function terminal_result_size( $result ): int {
		return strlen( maybe_serialize( $result ) );
	}

	/**
	 * Report a committed write whose terminal journal could not be proven.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Resource ID.
	 * @return \WP_Error
	 */
	private static function journal_persistence_error( string $resource, string $id ): \WP_Error {
		return Error::internal_error(
			'design-workspace-journal',
			'The design write reached a terminal state, but its idempotency result could not be durably recorded. Do not retry with another key; read the resource before manual recovery.',
			[
				'commitState'            => 'unknown',
				'manualRecoveryRequired' => true,
				'resource'               => $resource,
				'id'                     => $id,
			]
		);
	}

	/**
	 * Preview validation boundary disclosed to callers.
	 *
	 * @param string $resource Resource name.
	 * @return array
	 */
	private static function validation_contract( string $resource ): array {
		return [
			'previewValidated' => [
				'document schema and immutable identity',
				'exact item and guarded dependency baseline',
				'resource-specific editable field shapes',
				'side-effect-free selector, element, label, and style preparation',
				'permission and execution-policy identity',
				'component slot-removal acknowledgement when detectable',
			],
			'applyValidated'   => [
				'exact baseline recheck immediately before mutation',
				sprintf( '%s focused ability normalization and referential constraints', $resource ),
				'focused compare-and-swap and authoritative readback',
			],
			'limitation'       => 'Preview freezes deterministic file normalization. Apply still re-runs focused referential and concurrency checks against live authoritative storage.',
		];
	}

	/**
	 * Project a resource to the fields the focused update ability owns. This
	 * excludes server metadata such as modified timestamps while making any
	 * focused normalization of editable data explicit in apply readback.
	 *
	 * @param string $resource Resource name.
	 * @param array  $item     Resource item.
	 * @return array
	 */
	private static function editable_projection( string $resource, array $item ): array {
		$keys = [];

		switch ( $resource ) {
			case 'globalClass':
				$keys = [ 'id', 'name', 'settings', 'selectors', 'category' ];
				break;
			case 'globalVariable':
				$keys = [ 'id', 'name', 'value', 'category', 'scale' ];
				break;
			case 'themeStyle':
				$keys = [ 'label', 'settings' ];
				break;
			case 'component':
				$keys = [ 'id', 'elements', 'properties', 'variants', 'category', 'desc', 'propertyGroups', 'blockEditor', 'blockCategory', 'blockIcon', 'blockPreviewImage' ];
				break;
		}

		$projection = [];

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$projection[ $key ] = $item[ $key ];
			}
		}

		return $projection;
	}

	/**
	 * Build a deletion-aware deep-merge patch.
	 *
	 * @param array $before Existing settings.
	 * @param array $after  Desired settings.
	 * @return array
	 */
	private static function replacement_patch( array $before, array $after ): array {
		$patch = $after;

		foreach ( $before as $key => $value ) {
			if ( ! array_key_exists( $key, $after ) ) {
				$patch[ $key ] = null;
				continue;
			}

			if ( is_array( $value ) && is_array( $after[ $key ] ) && ! wp_is_numeric_array( $value ) && ! wp_is_numeric_array( $after[ $key ] ) ) {
				$patch[ $key ] = self::replacement_patch( $value, $after[ $key ] );
			}
		}

		return $patch;
	}

	/**
	 * Read a component label from its root element using the focused ability's
	 * public label semantics.
	 *
	 * @param array $component Component row.
	 * @return string
	 */
	private static function component_label( array $component ): string {
		$id       = (string) ( $component['id'] ?? '' );
		$elements = is_array( $component['elements'] ?? null ) ? $component['elements'] : [];

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && (string) ( $element['id'] ?? '' ) === $id ) {
				return (string) ( $element['label'] ?? $element['name'] ?? $id );
			}
		}

		if ( is_array( $elements[0] ?? null ) ) {
			return (string) ( $elements[0]['label'] ?? $elements[0]['name'] ?? $id );
		}

		return $id;
	}

	/**
	 * Return compact JSON-pointer-like changed paths.
	 *
	 * @param mixed  $before Before value.
	 * @param mixed  $after  After value.
	 * @param string $path   Current path.
	 * @return string[]
	 */
	private static function changed_paths( $before, $after, string $path = '/item' ): array {
		if ( self::digest( $before ) === self::digest( $after ) ) {
			return [];
		}

		if ( ! is_array( $before ) || ! is_array( $after ) || wp_is_numeric_array( $before ) || wp_is_numeric_array( $after ) ) {
			return [ $path ];
		}

		$paths = [];
		$keys  = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		foreach ( $keys as $key ) {
			$paths = array_merge( $paths, self::changed_paths( $before[ $key ] ?? null, $after[ $key ] ?? null, $path . '/' . str_replace( '~', '~0', str_replace( '/', '~1', (string) $key ) ) ) );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Stable digest that ignores associative object key ordering.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalize( $value ) ) );
	}

	/**
	 * Canonicalize associative arrays while preserving list ordering.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! wp_is_numeric_array( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		foreach ( $value as &$child ) {
			$child = self::canonicalize( $child );
		}
		unset( $child );

		return $value;
	}

	/**
	 * Digest a candidate without its self-referential digest.
	 *
	 * @param array $candidate Candidate.
	 * @return string
	 */
	private static function candidate_digest( array $candidate ): string {
		unset( $candidate['candidateDigest'] );

		return self::digest( $candidate );
	}

	/**
	 * Bind preview to the current execution policy.
	 *
	 * @return array
	 */
	private static function execution_policy(): array {
		return [
			'canExecuteCode' => \Bricks\Capabilities::current_user_can_execute_code(),
			'userId'         => get_current_user_id(),
		];
	}

	/**
	 * Build a reusable canonical file from one authoritative resource read.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Item ID.
	 * @param array  $read     Authoritative item and baseline.
	 * @return array
	 */
	private static function workspace_document( string $resource, string $id, array $read ): array {
		return [
			'schemaVersion' => self::SCHEMA_VERSION,
			'resource'      => $resource,
			'id'            => $id,
			'baseline'      => $read['baseline'],
			'item'          => self::editable_projection( $resource, $read['item'] ),
		];
	}

	/**
	 * Deterministic workspace path.
	 *
	 * @param string $resource Resource name.
	 * @param string $id       Item ID.
	 * @return string
	 */
	private static function file_path( string $resource, string $id ): string {
		$directories = [
			'globalClass'    => 'classes',
			'globalVariable' => 'variables',
			'themeStyle'     => 'theme-styles',
			'component'      => 'components',
		];

		return sprintf( 'design/%s/%s.bricks.json', $directories[ $resource ] ?? $resource, rawurlencode( $id ) );
	}

	/**
	 * Preview transient key.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function preview_key( string $token ): string {
		return self::PREVIEW_PREFIX . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/**
	 * Idempotency journal key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Caller key.
	 * @return string
	 */
	private static function journal_key( int $user_id, string $key ): string {
		return self::JOURNAL_PREFIX . substr( hash( 'sha256', $user_id . "\0" . $key ), 0, 40 );
	}

	/**
	 * Acquire a database-session lock for one idempotency key.
	 *
	 * @param string $authority_key Journal key.
	 * @return string|\WP_Error
	 */
	private static function acquire_lock( string $authority_key ) {
		global $wpdb;

		$lock_name = 'bricks_dw_' . substr( hash( 'sha256', $authority_key ), 0, 40 );
		$acquired  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL session lock fences concurrent retries.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
		);

		if ( (string) $acquired === '1' ) {
			return $lock_name;
		}

		return Error::conflict( 'design_workspace_apply_in_progress', [ 'message' => 'This design workspace operation is already applying. Retry the same token and idempotency key shortly.' ] );
	}

	/**
	 * Release a database-session lock.
	 *
	 * @param string $lock_name Lock name.
	 * @return void
	 */
	private static function release_lock( string $lock_name ): void {
		global $wpdb;

		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the session lock acquired above.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
		);
	}

	/**
	 * Delete a terminal idempotency journal after its 24-hour retry window.
	 * Prepared journals are retained as manual-recovery evidence because the
	 * request may have stopped after the focused CAS committed.
	 *
	 * @param string $key Journal option key.
	 * @return void
	 */
	public static function cleanup_journal( string $key ): void {
		if ( strpos( $key, self::JOURNAL_PREFIX ) !== 0 ) {
			return;
		}

		$lock = self::acquire_lock( $key );

		if ( is_wp_error( $lock ) ) {
			return;
		}

		try {
			$journal = get_option( $key, null );

			if ( ! is_array( $journal ) || array_key_exists( 'result', $journal ) ) {
				delete_option( $key );
			}
		} finally {
			self::release_lock( $lock );
		}
	}
}

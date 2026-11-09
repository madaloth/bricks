<?php
/**
 * Bounded, resumable site changesets for file-oriented agents.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrate existing page and design-resource workspaces without claiming
 * cross-resource database atomicity.
 */
class Site_Changeset {
	const CONTRACT_VERSION    = '1';
	const MAX_TARGETS         = 25;
	const MAX_DOCUMENT_BYTES  = 1572864;
	const MAX_CHANGESET_BYTES = 6291456;
	const MAX_JOURNAL_BYTES   = 8388608;
	const PREVIEW_TTL         = 900;
	const JOURNAL_TTL         = 86400;
	const PREVIEW_PREFIX      = 'bricks_agent_changeset_preview_';
	const JOURNAL_PREFIX      = 'bricks_agent_changeset_journal_';
	const CLEANUP_HOOK        = 'bricks_cleanup_agent_changeset_journal';

	/**
	 * Checkout schema.
	 *
	 * @return array
	 */
	public static function checkout_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'targets' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_TARGETS,
					'items'    => self::target_schema(),
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
				'documents' => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => self::MAX_TARGETS,
					'items'    => [
						'type'                 => 'object',
						'properties'           => [
							'scope'            => [
								'type' => 'string',
								'enum' => [ 'page', 'design' ]
							],
							'target'           => self::target_schema(),
							'path'             => [ 'type' => 'string' ],
							'document'         => [ 'type' => 'object' ],
							'allowSlotOrphans' => [ 'type' => 'boolean' ],
						],
						'required'             => [ 'scope', 'target', 'document' ],
						'additionalProperties' => false,
					],
				],
			],
			'required'             => [ 'documents' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Apply/resume schema.
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
					'maxLength' => 128
				],
				'maxSteps'       => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_TARGETS
				],
				'timeBudgetMs'   => [
					'type'    => 'integer',
					'minimum' => 250,
					'maximum' => 20000
				],
				'responseFormat' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'document' ],
					'description' => __( 'Use summary for compact authoritative digests and change metadata. Use document only when the complete canonical readback is required for another edit.', 'bricks' ),
				],
			],
			'required'             => [ 'previewToken', 'idempotencyKey' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Resolve-journal schema.
	 *
	 * @return array
	 */
	public static function resolve_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'idempotencyKey'              => [
					'type'      => 'string',
					'maxLength' => 128,
				],
				'expectedChangesetDigest'     => [
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				],
				'acknowledgeRecoveryResolved' => [ 'type' => 'boolean' ],
			],
			'required'             => [ 'idempotencyKey', 'expectedChangesetDigest', 'acknowledgeRecoveryResolved' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Shared output schema.
	 *
	 * @return array
	 */
	public static function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion'        => [ 'type' => 'string' ],
				'documents'              => [ 'type' => 'array' ],
				'manifest'               => [ 'type' => 'object' ],
				'files'                  => [ 'type' => 'object' ],
				'previewToken'           => [ 'type' => [ 'string', 'null' ] ],
				'expiresAt'              => [ 'type' => [ 'integer', 'null' ] ],
				'changesetDigest'        => [ 'type' => 'string' ],
				'preparedDocuments'      => [ 'type' => [ 'array', 'null' ] ],
				'state'                  => [ 'type' => 'string' ],
				'manualRecoveryRequired' => [ 'type' => 'boolean' ],
				'stopError'              => [ 'type' => [ 'object', 'null' ] ],
				'nextStep'               => [ 'type' => [ 'integer', 'null' ] ],
				'steps'                  => [ 'type' => 'array' ],
				'atomic'                 => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Resolve output schema.
	 *
	 * @return array
	 */
	public static function resolve_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'state'           => [ 'type' => 'string' ],
				'previousState'   => [ 'type' => 'string' ],
				'changesetDigest' => [ 'type' => 'string' ],
				'deleted'         => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Two-call canonical-file commit schema.
	 *
	 * @return array
	 */
	public static function agent_file_commit_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'target'           => self::target_schema(),
				'document'         => [
					'type'                 => 'object',
					'description'          => __( 'The resolved canonical file. Agents may send the complete file or omit its immutable schema and target envelope; Bricks restores omitted identity from target and rejects conflicting identity.', 'bricks' ),
					'properties'           => [
						'schemaVersion' => [ 'type' => 'integer' ],
						'resource'      => [ 'type' => 'string' ],
						'id'            => [ 'type' => 'string' ],
						'target'        => [ 'type' => 'object' ],
						'baseline'      => [ 'type' => 'object' ],
						'item'          => [ 'type' => 'object' ],
						'elements'      => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
					'required'             => [ 'baseline' ],
					'anyOf'                => [
						[ 'required' => [ 'baseline', 'item' ] ],
						[ 'required' => [ 'baseline', 'elements' ] ],
					],
					'additionalProperties' => false,
				],
				'idempotencyKey'   => [
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 128,
				],
				'allowSlotOrphans' => [ 'type' => 'boolean' ],
				'removeElementIds' => [
					'type'        => 'array',
					'items'       => [
						'type'      => 'string',
						'minLength' => 1
					],
					'uniqueItems' => true,
					'description' => __( 'For intentional page or template removals, list the ID of each removed element; descendants removed with it are implied. Omitted or empty preserves all existing elements. Do not list omissions caused by an incomplete copy of the resolved file.', 'bricks' ),
				],
			],
			'required'             => [ 'target', 'document', 'idempotencyKey' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Two-call canonical-file commit output schema.
	 *
	 * @return array
	 */
	public static function agent_file_commit_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion'        => [ 'type' => 'string' ],
				'state'                  => [ 'type' => 'string' ],
				'target'                 => [ 'type' => 'object' ],
				'path'                   => [ 'type' => 'string' ],
				'authoritative'          => [ 'type' => [ 'object', 'null' ] ],
				'changedPaths'           => [ 'type' => 'array' ],
				'normalizationDelta'     => [ 'type' => [ 'object', 'null' ] ],
				'operationDigest'        => [ 'type' => 'string' ],
				'manualRecoveryRequired' => [ 'type' => 'boolean' ],
				'stopError'              => [ 'type' => [ 'object', 'null' ] ],
				'atomic'                 => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Shared opaque preservation-envelope identity for resolve and commit.
	 *
	 * @param mixed $baseline Canonical document baseline.
	 * @return string
	 */
	public static function agent_file_baseline_digest( $baseline ): string {
		return self::digest( $baseline );
	}

	/**
	 * Restore unchanged values lost by native REST query-parameter transport.
	 * Match reordered list items by ID before restoring their scalar types.
	 *
	 * @param mixed $submitted            Submitted canonical value.
	 * @param mixed $current              Current authoritative value.
	 * @param bool  $restore_empty_arrays Restore empty arrays omitted by query encoding.
	 * @return mixed
	 */
	private static function agent_file_restore_unchanged_types( $submitted, $current, bool $restore_empty_arrays = false ) {
		if ( is_array( $submitted ) && is_array( $current ) ) {
			$current_by_id = [];
			if ( wp_is_numeric_array( $submitted ) && wp_is_numeric_array( $current ) ) {
				foreach ( $current as $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) && is_scalar( $item['id'] ) ) {
						$current_by_id[ (string) $item['id'] ] = $item;
					}
				}
			}

			foreach ( $submitted as $key => $value ) {
				$reference = $current[ $key ] ?? null;
				if ( is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) && isset( $current_by_id[ (string) $value['id'] ] ) ) {
					$reference = $current_by_id[ (string) $value['id'] ];
				}

				if ( $reference !== null || array_key_exists( $key, $current ) ) {
					$submitted[ $key ] = self::agent_file_restore_unchanged_types( $value, $reference, $restore_empty_arrays );
				}
			}

			if ( $restore_empty_arrays ) {
				// Query encoding drops empty arrays; retain canonical key order for no-op checks.
				$missing_empty = [];
				foreach ( $current as $key => $value ) {
					if ( $value === [] && ! array_key_exists( $key, $submitted ) ) {
						$missing_empty[ $key ] = [];
					}
				}

				if ( $missing_empty ) {
					if ( wp_is_numeric_array( $current ) ) {
						$submitted += $missing_empty;
					} else {
						$ordered = [];
						foreach ( $current as $key => $value ) {
							if ( array_key_exists( $key, $submitted ) ) {
								$ordered[ $key ] = $submitted[ $key ];
							} elseif ( array_key_exists( $key, $missing_empty ) ) {
								$ordered[ $key ] = [];
							}
						}
						$submitted = $ordered + $submitted;
					}
				}
			}

			return $submitted;
		}

		if ( $submitted === $current ) {
			return $submitted;
		}

		if ( ! is_string( $submitted ) ) {
			return $submitted;
		}

		if ( is_int( $current ) || is_float( $current ) ) {
			return $submitted === (string) $current ? $current : $submitted;
		}

		if ( is_bool( $current ) ) {
			return in_array( $submitted, $current ? [ '1', 'true' ] : [ '0', 'false', '' ], true ) ? $current : $submitted;
		}

		return $submitted;
	}

	/**
	 * Identify the native query-only REST request that loses scalar types.
	 * A typed JSON or PHP caller may intentionally change only a value's type.
	 *
	 * @param array $input Raw commit input.
	 * @return bool
	 */
	private static function agent_file_uses_query_transport( array $input ): bool {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'DELETE' || ! empty( $_SERVER['CONTENT_LENGTH'] ) || ! is_array( $_GET['input'] ?? null ) ) {
			return false;
		}

		$query_input    = wp_unslash( $_GET['input'] );
		$query_document = $query_input['document'] ?? null;
		$document       = $input['document'] ?? null;
		$field          = is_array( $document ) && array_key_exists( 'elements', $document ) ? 'elements' : 'item';

		return is_array( $query_document ) && is_array( $document )
			&& ( $query_input['idempotencyKey'] ?? null ) === ( $input['idempotencyKey'] ?? null )
			&& ( $query_document['baseline'] ?? null ) === ( $document['baseline'] ?? null )
			&& ( $query_document[ $field ] ?? null ) === ( $document[ $field ] ?? null );
	}

	/**
	 * Check focused write permission on first use while letting an existing
	 * journal record permission loss or reauthorize terminal readback itself.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function agent_file_commit_permission( $input ) {
		$key = (string) ( $input['idempotencyKey'] ?? '' );
		if ( $key !== '' && is_array( get_option( self::journal_key( get_current_user_id(), $key ), null ) ) ) {
			return true;
		}

		$target   = is_array( $input['target'] ?? null ) ? $input['target'] : [];
		$document = is_array( $input['document'] ?? null ) ? $input['document'] : null;
		if ( $document === null ) {
			return Error::invalid_param( 'document', 'one canonical page or design-resource file', $document );
		}

		$normalized = self::normalize_agent_file_document( $target, $document );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return self::documents_permission(
			[
				[
					'scope'    => $normalized['target']['scope'],
					'target'   => $normalized['target'],
					'document' => $normalized['document'],
				],
			]
		);
	}

	/**
	 * Commit one canonical file through the Site Changeset persistence engine.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function commit_agent_file( $input ) {
		$query_transport = self::agent_file_uses_query_transport( is_array( $input ) ? $input : [] );
		$target          = is_array( $input['target'] ?? null ) ? $input['target'] : [];
		$document        = is_array( $input['document'] ?? null ) ? $input['document'] : null;
		$idempotency_key = (string) ( $input['idempotencyKey'] ?? '' );

		if ( $document === null || $idempotency_key === '' || strlen( $idempotency_key ) > 128 ) {
			return Error::invalid_param( 'agentFileCommit', 'one canonical document and a non-empty idempotency key', null );
		}

		$targets = self::validate_targets( [ $target ] );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}
		$target = $targets[0];

		$removals = self::agent_file_removal_ids( $input['removeElementIds'] ?? [], $target );
		if ( is_wp_error( $removals ) ) {
			return $removals;
		}

		$payload = self::validate_agent_file_payload( $target, $document );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$dependency = self::agent_file_dependency_status( $target );
		if ( is_wp_error( $dependency ) ) {
			return $dependency;
		}

		$journal_key = self::journal_key( get_current_user_id(), $idempotency_key );
		$lock        = self::acquire_lock( $journal_key );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$journal = get_option( $journal_key, null );
			$frozen  = null;

			if ( is_array( $journal ) ) {
				$entry          = $journal['candidate']['documents'][0] ?? [];
				$step           = $journal['steps'][0] ?? [];
				$journal_target = is_array( $step['target'] ?? null ) ? $step['target'] : ( $entry['target'] ?? null );
				if ( ( $journal['mode'] ?? '' ) !== 'agent_file' || $journal_target !== $target ) {
					return Error::conflict( 'agent_file_idempotency_mismatch', [ 'message' => 'This idempotency key is bound to a different canonical-file candidate.' ] );
				}

				$frozen = is_array( $entry['document'] ?? null )
					? $entry['document']
					: ( is_array( $step['authoritativeDocument'] ?? null ) ? $step['authoritativeDocument'] : null );
				if ( $target['scope'] === 'page' && $frozen === null ) {
					return Error::internal_error( 'agent-file-commit', 'The durable agent-file journal has no frozen page identity. Inspect the target before manual recovery.' );
				}
			}

			$normalized = self::normalize_agent_file_document( $target, $document, $frozen );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$document         = $normalized['document'];
			$request_identity = [
				'userId'           => get_current_user_id(),
				'siteId'           => get_current_blog_id(),
				'target'           => $target,
				'document'         => $document,
				'allowSlotOrphans' => ! empty( $input['allowSlotOrphans'] ),
			];
			// Preserve the request identity of existing journals that predate explicit removals.
			if ( $removals ) {
				$request_identity['removeElementIds'] = $removals;
			}
			$request_digest = self::digest( $request_identity );

			if ( is_array( $journal ) ) {
				if ( ! hash_equals( (string) ( $journal['requestDigest'] ?? '' ), $request_digest ) ) {
					return Error::conflict( 'agent_file_idempotency_mismatch', [ 'message' => 'This idempotency key is bound to a different canonical-file candidate.' ] );
				}
			} else {
				$entry = [
					'scope'    => (string) ( $target['scope'] ?? '' ),
					'target'   => $target,
					'document' => $document,
				];
				if ( ! empty( $input['allowSlotOrphans'] ) ) {
					$entry['allowSlotOrphans'] = true;
				}

				$documents = self::validate_documents( [ $entry ] );
				if ( is_wp_error( $documents ) ) {
					return $documents;
				}

				if ( ! array_key_exists( 'baseline', $document ) || ! is_array( $document['baseline'] ) ) {
					return Error::invalid_param( 'document.baseline', 'the unchanged opaque baseline returned by resolve-agent-file', $document['baseline'] ?? null );
				}

				$permission = self::documents_permission( $documents );
				if ( is_wp_error( $permission ) ) {
					return $permission;
				}

				$fresh = self::checkout_target( $target );
				if ( is_wp_error( $fresh ) ) {
					return $fresh;
				}

				$normalized_baseline = self::agent_file_restore_unchanged_types( $document['baseline'], $fresh['document']['baseline'] ?? null, $query_transport );
				if ( ! hash_equals( self::digest( $normalized_baseline ), self::digest( $fresh['document']['baseline'] ?? null ) ) ) {
					return Error::conflict( 'agent_file_stale_baseline', [ 'message' => 'The canonical file changed. Resolve it again before committing.' ] );
				}

				// Query parameters lose editable scalar types; typed callers retain their exact values.
				$field = $target['scope'] === 'page' ? 'elements' : 'item';

				if ( $query_transport ) {
					$document[ $field ] = self::agent_file_restore_unchanged_types( $document[ $field ], $fresh['document'][ $field ] ?? null, true );
				}

				if ( $target['scope'] === 'page' ) {
					$preservation = self::validate_agent_file_removals( $fresh['document']['elements'], $document['elements'], $removals );
					if ( is_wp_error( $preservation ) ) {
						return $preservation;
					}
				}

				$document['baseline']                 = $fresh['document']['baseline'];
				$documents[0]['document']['baseline'] = $document['baseline'];
				$documents[0]['document'][ $field ]   = $document[ $field ];

				$documents[0]['path']         = $fresh['path'];
				$candidate                    = [
					'contractVersion' => self::CONTRACT_VERSION,
					'userId'          => get_current_user_id(),
					'executionPolicy' => self::execution_policy(),
					'documents'       => $documents,
					'expiresAt'       => time() + self::JOURNAL_TTL,
				];
				$candidate['changesetDigest'] = self::candidate_digest( $candidate );
				$journal                      = self::new_journal( $journal_key, '', $candidate );
				$journal['mode']              = 'agent_file';
				$journal['requestDigest']     = $request_digest;

				$initialized = self::initialize_journal( $journal_key, $journal, 'agent-file-commit' );
				if ( is_wp_error( $initialized ) ) {
					return $initialized;
				}
				$journal = $initialized;
			}

			if ( self::is_terminal( (string) ( $journal['state'] ?? '' ) ) ) {
				return self::agent_file_journal_response( $journal );
			}

			$journal = self::advance_journal( $journal_key, $journal, $idempotency_key, 1, 20000 );

			return self::agent_file_journal_response( $journal );
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Normalize explicit removal intent before binding an idempotent request.
	 *
	 * @param mixed $ids    Requested removed element IDs.
	 * @param array $target Validated canonical target.
	 * @return array|\WP_Error
	 * @since 2.4.3
	 */
	private static function agent_file_removal_ids( $ids, array $target ) {
		if ( ! is_array( $ids ) || array_values( $ids ) !== $ids ) {
			return Error::invalid_param( 'removeElementIds', 'a list of distinct existing element IDs', $ids );
		}
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || $id === '' ) {
				return Error::invalid_param( 'removeElementIds', 'a list of distinct existing element IDs', $ids );
			}
		}
		if ( count( array_unique( $ids ) ) !== count( $ids ) || ( $ids && $target['scope'] !== 'page' ) ) {
			return Error::invalid_param( 'removeElementIds', 'distinct element IDs for a page or template target', $ids );
		}
		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Reject accidental omissions before a full-file write can delete content.
	 *
	 * Intent names the top-most removed elements. Listing their removed
	 * descendants as well is accepted, so a caller never has to work out which
	 * of the two forms applies.
	 *
	 * @param array $current  Fresh authoritative elements after the baseline check.
	 * @param array $proposed Submitted canonical elements.
	 * @param array $intent   Sorted IDs intentionally removed by this request.
	 * @return true|\WP_Error
	 * @since 2.4.3
	 */
	private static function validate_agent_file_removals( array $current, array $proposed, array $intent ) {
		$remaining = [];
		foreach ( $proposed as $element ) {
			$id = is_array( $element ) ? ( $element['id'] ?? null ) : null;
			if ( is_string( $id ) || is_int( $id ) ) {
				$remaining[ (string) $id ] = true;
			}
		}

		$removed = [];
		foreach ( $current as $element ) {
			$id = (string) ( $element['id'] ?? '' );
			if ( $id !== '' && ! isset( $remaining[ $id ] ) ) {
				$removed[ $id ] = $element;
			}
		}

		// A removed element whose parent survives (or is the root) starts a removed subtree.
		$roots = [];
		foreach ( $removed as $id => $element ) {
			if ( ! isset( $removed[ (string) ( $element['parent'] ?? 0 ) ] ) ) {
				$roots[ $id ] = 0;
			}
		}
		foreach ( $removed as $id => $element ) {
			$ancestor = (string) ( $element['parent'] ?? 0 );
			while ( isset( $removed[ $ancestor ] ) && ! isset( $roots[ $ancestor ] ) ) {
				$ancestor = (string) ( $removed[ $ancestor ]['parent'] ?? 0 );
			}
			if ( isset( $roots[ $ancestor ] ) ) {
				++$roots[ $ancestor ];
			}
		}

		$unacknowledged = array_diff( array_map( 'strval', array_keys( $roots ) ), $intent );
		$not_removed    = array_filter(
			$intent,
			static function ( $id ) use ( $removed ) {
				return ! isset( $removed[ $id ] );
			}
		);
		if ( ! $unacknowledged && ! $not_removed ) {
			return true;
		}

		$details = [];
		foreach ( array_slice( array_keys( $roots ), 0, 20 ) as $id ) {
			$element   = $removed[ $id ];
			$settings  = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			$text      = $settings['text'] ?? ( $settings['title'] ?? '' );
			$details[] = [
				'id'              => (string) $id,
				'name'            => (string) ( $element['name'] ?? '' ),
				'label'           => (string) ( $element['label'] ?? '' ),
				'text'            => is_string( $text ) ? mb_substr( wp_strip_all_tags( $text ), 0, 80 ) : '',
				'parent'          => $element['parent'] ?? 0,
				'descendantCount' => $roots[ $id ],
				'acknowledged'    => in_array( (string) $id, $intent, true ),
			];
		}

		return Error::conflict(
			'agent_file_element_removal',
			[
				'message'                  => 'The submitted file drops existing elements that removeElementIds does not cover, or removeElementIds names elements the file still contains. Restore every dropped element whose removal is not part of the requested edit. For intentional removals, list the top-most removed element IDs in removeElementIds. No changes were saved; the corrected request can reuse this idempotency key.',
				'removedElementCount'      => count( $removed ),
				'removedElements'          => $details,
				'removedElementsTruncated' => count( $roots ) > 20,
				'retainedButListed'        => array_values( $not_removed ),
			]
		);
	}

	/**
	 * Restore redundant immutable file identity from the explicit commit target.
	 * Supplied identity must still match so a compact request cannot redirect a
	 * canonical file across resources or page save areas.
	 *
	 * @param array      $target   Explicit agent-file target.
	 * @param array      $document Submitted canonical document.
	 * @param array|null $frozen   Frozen identity from an existing journal.
	 * @return array|\WP_Error
	 */
	private static function normalize_agent_file_document( array $target, array $document, $frozen = null ) {
		$targets = self::validate_targets( [ $target ] );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$target  = $targets[0];
		$payload = self::validate_agent_file_payload( $target, $document );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$expected_schema = $target['scope'] === 'page'
			? Workspace::PAGE_WORKSPACE_SCHEMA_VERSION
			: Design_Workspace::SCHEMA_VERSION;

		if ( isset( $document['schemaVersion'] ) && (int) $document['schemaVersion'] !== $expected_schema ) {
			return Error::conflict(
				'agent_file_document_target_mismatch',
				[ 'message' => 'The canonical document schema does not match the explicit commit target. Resolve the file again.' ]
			);
		}
		$document['schemaVersion'] = $expected_schema;

		if ( $target['scope'] === 'design' ) {
			foreach ( [ 'resource', 'id' ] as $field ) {
				if ( isset( $document[ $field ] ) && (string) $document[ $field ] !== (string) $target[ $field ] ) {
					return Error::conflict(
						'agent_file_document_target_mismatch',
						[ 'message' => 'The canonical document identity does not match the explicit commit target. Resolve the file again.' ]
					);
				}
				$document[ $field ] = $target[ $field ];
			}
		} else {
			$frozen_target            = is_array( $frozen['target'] ?? null ) ? $frozen['target'] : null;
			$expected_document_target = [
				'postId' => $target['postId'],
				'area'   => (string) ( $frozen_target['area'] ?? Elements::get_save_area_for_post( $target['postId'] ) ),
			];
			if ( isset( $document['target'] ) && ! is_array( $document['target'] ) ) {
				return Error::invalid_param( 'document.target', 'the canonical page target object', $document['target'] );
			}
			foreach ( $document['target'] ?? [] as $field => $value ) {
				if ( ! array_key_exists( $field, $expected_document_target ) || (string) $value !== (string) $expected_document_target[ $field ] ) {
					return Error::conflict(
						'agent_file_document_target_mismatch',
						[ 'message' => 'The canonical page identity does not match the explicit commit target. Resolve the file again.' ]
					);
				}
			}
			$document['target'] = $expected_document_target;
		}

		return [
			'target'   => $target,
			'document' => $document,
		];
	}

	/**
	 * Validate the editable half of a compact canonical document before any
	 * durable journal is created.
	 *
	 * @param array $target   Validated commit target.
	 * @param array $document Submitted canonical document.
	 * @return true|\WP_Error
	 */
	private static function validate_agent_file_payload( array $target, array $document ) {
		if ( ! is_array( $document['baseline'] ?? null ) ) {
			return Error::invalid_param( 'document.baseline', 'the unchanged opaque baseline returned by resolve-agent-file', $document['baseline'] ?? null );
		}

		$field = $target['scope'] === 'page' ? 'elements' : 'item';
		if ( ! is_array( $document[ $field ] ?? null ) ) {
			return Error::invalid_param( "document.{$field}", "the editable {$field} returned by resolve-agent-file", $document[ $field ] ?? null );
		}

		return true;
	}

	/**
	 * Preserve the admin deny-list composition of the repository, changeset,
	 * and focused workspace contracts used by the fast path.
	 *
	 * @param array $target Target.
	 * @return true|\WP_Error
	 */
	private static function agent_file_dependency_status( array $target ) {
		if ( ! class_exists( Manager::class ) || ! is_callable( [ Manager::class, 'is_ability_disabled' ] ) ) {
			return true;
		}

		$focused  = ( $target['scope'] ?? '' ) === 'page'
			? [ 'bricks/checkout-page-workspace', 'bricks/preview-page-workspace', 'bricks/apply-page-workspace' ]
			: [ 'bricks/checkout-design-resource-workspace', 'bricks/preview-design-resource-workspace', 'bricks/apply-design-resource-workspace' ];
		$required = array_merge( [ 'bricks/preview-site-changeset', 'bricks/apply-site-changeset' ], $focused );

		foreach ( $required as $ability ) {
			if ( Manager::is_ability_disabled( $ability ) ) {
				return Error::ability_disabled( $ability );
			}
		}

		return true;
	}

	/**
	 * Checkout permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function checkout_permission( $input ) {
		$targets = $input['targets'] ?? null;

		if ( ! is_array( $targets ) ) {
			return Error::invalid_param( 'targets', 'an array of 1-25 page/design targets', $targets );
		}

		foreach ( $targets as $target ) {
			$result = self::target_permission( is_array( $target ) ? $target : [], false );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Preview permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_permission( $input ) {
		return self::documents_permission( $input['documents'] ?? null );
	}

	/**
	 * Apply permission callback resolves either the transient candidate or the
	 * durable journal and rechecks every intended write permission.
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

			if ( is_array( $journal ) && ! hash_equals( (string) ( $journal['previewTokenHash'] ?? '' ), hash( 'sha256', $token ) ) ) {
				return Error::conflict( 'site_changeset_idempotency_mismatch', [ 'message' => 'This idempotency key is bound to another changeset preview.' ] );
			}

			if ( ! is_array( $journal ) ) {
				return Error::conflict( 'site_changeset_missing', [ 'message' => 'The site changeset preview is missing, expired, consumed, or belongs to another operation.' ] );
			}

			$candidate = $journal['candidate'] ?? null;
		}

		if ( ! is_array( $candidate ) || (int) ( $candidate['userId'] ?? 0 ) !== get_current_user_id() ) {
			return Error::forbidden_builder_permission( 'site_changeset_owner' );
		}

		if ( is_array( $journal ?? null ) && self::is_terminal( (string) ( $journal['state'] ?? '' ) ) ) {
			return self::terminal_journal_permission( $journal );
		}

		// The callback rechecks and journals current per-document write authority.
		// Doing that here would prevent it from recording permission loss after a
		// partial commit because WP_Ability never invokes a denied callback.
		return true;
	}

	/**
	 * Resolution only deletes the current user's terminal recovery journal.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function resolve_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		return true;
	}

	/**
	 * Batch checkout up to 25 canonical page/design files.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function checkout( $input ) {
		$targets = self::validate_targets( $input['targets'] ?? null );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$documents = [];
		$paths     = [];
		$total     = 0;

		foreach ( $targets as $target ) {
			$checkout = self::checkout_target( $target );

			if ( is_wp_error( $checkout ) ) {
				return $checkout;
			}

			$document = $checkout['document'];
			$bytes    = self::encoded_bytes( $document );

			if ( $bytes > self::MAX_DOCUMENT_BYTES ) {
				return Error::conflict(
					'site_changeset_document_too_large',
					[
						'message' => 'One canonical workspace file exceeds the 1.5 MiB changeset limit. Edit it through the focused workspace.',
						'target'  => $target,
						'bytes'   => $bytes
					]
				);
			}

			$total += $bytes;
			if ( $total > self::MAX_CHANGESET_BYTES ) {
				return Error::conflict(
					'site_changeset_too_large',
					[
						'message' => 'The canonical files exceed the 6 MiB bounded changeset limit. Split the edit into smaller changesets.',
						'bytes'   => $total
					]
				);
			}

			$entry       = [
				'scope'    => $target['scope'],
				'target'   => $target,
				'path'     => $checkout['path'],
				'document' => $document,
			];
			$documents[] = $entry;
			$paths[]     = $checkout['path'];
		}

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'atomic'          => false,
			'documents'       => $documents,
			'manifest'        => [
				'count'      => count( $documents ),
				'totalBytes' => $total,
				'applyOrder' => 'pages-first-then-design-stable',
				'atomic'     => false,
				'files'      => $paths,
			],
		];
	}

	/**
	 * Validate all edited files without changing Bricks page/design data and
	 * freeze the exact ordered changeset.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview( $input ) {
		return self::preview_internal( $input, null );
	}

	/**
	 * Preview with a server-derived token for an internal facade that must hide
	 * the intermediate token while retaining durable replay identity.
	 *
	 * @param array  $input Ability input.
	 * @param string $token Server-authenticated 64-character token.
	 * @return array|\WP_Error
	 */
	public static function preview_with_token( $input, string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return Error::invalid_param( 'previewToken', 'a server-authenticated 64-character hexadecimal token', null );
		}
		return self::preview_internal( $input, $token );
	}

	/** Shared Site Changeset preview implementation. */
	private static function preview_internal( $input, $forced_token ) {
		$documents = self::validate_documents( $input['documents'] ?? null );

		if ( is_wp_error( $documents ) ) {
			return $documents;
		}

		$policy           = self::execution_policy();
		$steps            = [];
		$change_count     = 0;
		$has_preparation  = false;
		$focused_previews = [];

		try {
			foreach ( $documents as $index => $entry ) {
				$preview = self::preview_entry( $entry );

				if ( is_wp_error( $preview ) ) {
					return Error::conflict(
						'site_changeset_preview_failed',
						[
							'message'   => 'One changeset file failed focused preview. No Bricks page or design data was changed.',
							'step'      => $index,
							'target'    => $entry['target'],
							'innerCode' => $preview->get_error_code(),
							'innerData' => $preview->get_error_data()
						]
					);
				}

				$changed       = ! empty( $preview['previewToken'] );
				$change_count += $changed ? 1 : 0;
				if ( $changed ) {
					$focused_previews[] = [
						'scope' => $entry['scope'],
						'token' => (string) $preview['previewToken']
					];
				}

				if ( is_array( $preview['preparedFile'] ?? null ) ) {
					$documents[ $index ]['document'] = $preview['preparedFile'];
					$has_preparation                 = true;
				}

				$steps[] = [
					'index'      => $index,
					'target'     => $entry['target'],
					'changed'    => $changed,
					'validation' => $preview['validation'] ?? null,
					'prepared'   => is_array( $preview['preparedFile'] ?? null ),
				];
			}
		} finally {
			foreach ( $focused_previews as $focused_preview ) {
				if ( $focused_preview['scope'] === 'page' ) {
					Workspace::discard_page_workspace_preview( $focused_preview['token'] );
				} else {
					Design_Workspace::discard_preview( $focused_preview['token'] );
				}
			}
		}

		if ( $change_count === 0 && ! is_string( $forced_token ) ) {
			return [
				'contractVersion'        => self::CONTRACT_VERSION,
				'atomic'                 => false,
				'previewToken'           => null,
				'expiresAt'              => null,
				'changesetDigest'        => self::digest( $documents ),
				'preparedDocuments'      => $has_preparation ? $documents : null,
				'state'                  => 'no_change',
				'manualRecoveryRequired' => false,
				'steps'                  => $steps,
			];
		}

		$candidate                    = [
			'contractVersion' => self::CONTRACT_VERSION,
			'userId'          => get_current_user_id(),
			'executionPolicy' => $policy,
			'documents'       => $documents,
			'expiresAt'       => time() + self::PREVIEW_TTL,
		];
		$candidate['changesetDigest'] = self::candidate_digest( $candidate );
		$token                        = is_string( $forced_token ) ? $forced_token : bin2hex( random_bytes( 32 ) );

		$preview_key = self::preview_key( $token );
		if ( ! set_transient( $preview_key, $candidate, self::PREVIEW_TTL ) ) {
			$stored = get_transient( $preview_key );
			if ( ! is_array( $stored ) || ! hash_equals( self::digest( $candidate ), self::digest( $stored ) ) ) {
				return Error::internal_error( 'site-changeset-preview', 'Could not persist the short-lived site changeset preview. No Bricks data was changed.' );
			}
		}

		return [
			'contractVersion'        => self::CONTRACT_VERSION,
			'atomic'                 => false,
			'previewToken'           => $token,
			'expiresAt'              => $candidate['expiresAt'],
			'changesetDigest'        => $candidate['changesetDigest'],
			'preparedDocuments'      => $has_preparation ? $documents : null,
			'state'                  => 'previewed',
			'manualRecoveryRequired' => false,
			'steps'                  => $steps,
		];
	}

	/**
	 * Start or continue a durable time-sliced changeset apply.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function apply( $input ) {
		return self::apply_or_resume( $input );
	}

	/**
	 * Explicit resume alias for clients which distinguish first apply from
	 * continuation in their harness.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function resume( $input ) {
		return self::apply_or_resume( $input );
	}

	/**
	 * Delete a terminal recovery journal after an operator confirms that its
	 * partial/manual state has been inspected and resolved.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function resolve( $input ) {
		$key          = (string) ( $input['idempotencyKey'] ?? '' );
		$expected     = (string) ( $input['expectedChangesetDigest'] ?? '' );
		$acknowledged = ( $input['acknowledgeRecoveryResolved'] ?? false ) === true;

		if ( $key === '' || strlen( $key ) > 128 || strlen( $expected ) !== 64 || ! $acknowledged ) {
			return Error::invalid_param( 'resolution', 'a valid idempotency key, exact changeset digest, and literal recovery acknowledgement', [ 'acknowledged' => $acknowledged ] );
		}

		$journal_key = self::journal_key( get_current_user_id(), $key );
		$lock        = self::acquire_lock( $journal_key );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$journal = get_option( $journal_key, null );
			if ( ! is_array( $journal ) ) {
				return Error::conflict( 'site_changeset_resolution_missing', [ 'message' => 'No durable changeset journal exists for this user and idempotency key.' ] );
			}

			$state  = (string) ( $journal['state'] ?? '' );
			$digest = (string) ( $journal['candidate']['changesetDigest'] ?? '' );

			if ( ! in_array( $state, [ 'partial_commit', 'manual_recovery' ], true ) ) {
				return Error::conflict( 'site_changeset_resolution_not_recoverable', [ 'message' => 'Only an inspected partial-commit or manual-recovery journal can be explicitly resolved.' ] );
			}

			if ( ! hash_equals( $digest, $expected ) ) {
				return Error::conflict( 'site_changeset_resolution_mismatch', [ 'message' => 'The expected changeset digest does not match this recovery journal.' ] );
			}

			$permission = self::document_targets_permission( $journal['candidate']['documents'] ?? null );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			delete_option( $journal_key );
			if ( get_option( $journal_key, null ) !== null ) {
				return Error::internal_error( 'site-changeset-resolution', 'The terminal recovery journal could not be deleted.' );
			}

			return [
				'state'           => 'resolved',
				'previousState'   => $state,
				'changesetDigest' => $digest,
				'deleted'         => true,
			];
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Durable apply implementation.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function apply_or_resume( array $input ) {
		$token           = (string) ( $input['previewToken'] ?? '' );
		$idempotency_key = (string) ( $input['idempotencyKey'] ?? '' );
		$max_steps       = max( 1, min( self::MAX_TARGETS, (int) ( $input['maxSteps'] ?? 5 ) ) );
		$time_budget     = max( 250, min( 20000, (int) ( $input['timeBudgetMs'] ?? 8000 ) ) );
		$response_format = (string) ( $input['responseFormat'] ?? 'document' );

		if ( $token === '' || $idempotency_key === '' || strlen( $idempotency_key ) > 128 || ! in_array( $response_format, [ 'summary', 'document' ], true ) ) {
			return Error::invalid_param(
				'previewToken/idempotencyKey',
				'a preview token and non-empty idempotency key no longer than 128 characters',
				[
					'hasPreviewToken'      => $token !== '',
					'idempotencyKeyLength' => strlen( $idempotency_key ),
				]
			);
		}

		$journal_key = self::journal_key( get_current_user_id(), $idempotency_key );
		$lock        = self::acquire_lock( $journal_key );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$token_hash = hash( 'sha256', $token );
			$journal    = get_option( $journal_key, null );

			if ( ! is_array( $journal ) ) {
				$candidate = get_transient( self::preview_key( $token ) );

				if ( ! is_array( $candidate ) || (int) ( $candidate['userId'] ?? 0 ) !== get_current_user_id() || (int) ( $candidate['expiresAt'] ?? 0 ) < time() ) {
					return Error::conflict( 'site_changeset_preview_expired', [ 'message' => 'The changeset preview expired, was consumed, or belongs to another user.' ] );
				}

				if ( ! hash_equals( (string) ( $candidate['changesetDigest'] ?? '' ), self::candidate_digest( $candidate ) ) ) {
					return Error::conflict( 'site_changeset_preview_corrupt', [ 'message' => 'The frozen changeset failed its integrity check.' ] );
				}

				$journal     = self::new_journal( $journal_key, $token_hash, $candidate );
				$initialized = self::initialize_journal( $journal_key, $journal, 'site-changeset-apply' );
				if ( is_wp_error( $initialized ) ) {
					return $initialized;
				}
				$journal = $initialized;

				delete_transient( self::preview_key( $token ) );
			} elseif ( ! hash_equals( (string) ( $journal['previewTokenHash'] ?? '' ), $token_hash ) ) {
				return Error::conflict( 'site_changeset_idempotency_mismatch', [ 'message' => 'This idempotency key is bound to another changeset preview.' ] );
			}

			if ( self::is_terminal( (string) ( $journal['state'] ?? '' ) ) ) {
				$permission = self::terminal_journal_permission( $journal );
				if ( is_wp_error( $permission ) ) {
					return $permission;
				}
				return self::journal_response( $journal, null, 0, $response_format );
			}

			$response_start = self::next_step_index( $journal );
			$journal        = self::advance_journal( $journal_key, $journal, $idempotency_key, $max_steps, $time_budget );
			$steps_done     = is_int( $response_start ) ? max( 0, ( self::next_step_index( $journal ) ?? count( $journal['steps'] ) ) - $response_start ) : 0;

			return self::journal_response( $journal, $response_start, $steps_done, $response_format );
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Store and verify a new journal before any inner preview or mutation.
	 *
	 * @param string $journal_key Journal option key.
	 * @param array  $journal     New journal.
	 * @param string $operation   Error operation label.
	 * @return array|\WP_Error
	 */
	private static function initialize_journal( string $journal_key, array $journal, string $operation ) {
		if ( self::journal_bytes( $journal ) > self::MAX_JOURNAL_BYTES ) {
			return Error::conflict( 'site_changeset_journal_too_large', [ 'message' => 'The durable changeset journal would exceed 8 MiB. Split the changeset before applying.' ] );
		}

		if ( ! add_option( $journal_key, $journal, '', 'no' ) ) {
			return Error::conflict( 'site_changeset_idempotency_race', [ 'message' => 'Another request claimed this changeset idempotency key.' ] );
		}

		$stored = get_option( $journal_key, null );
		if ( ! is_array( $stored ) || ! hash_equals( self::digest( $journal ), self::digest( $stored ) ) ) {
			delete_option( $journal_key );
			return Error::internal_error( $operation, 'The durable changeset journal could not be verified, so apply stopped before any inner mutation.' );
		}

		$scheduled = wp_schedule_single_event( time() + self::JOURNAL_TTL, self::CLEANUP_HOOK, [ $journal_key ], true );
		if ( is_wp_error( $scheduled ) || $scheduled === false ) {
			delete_option( $journal_key );
			return Error::internal_error( $operation, 'Cleanup could not be scheduled, so the changeset stopped before any inner mutation.' );
		}

		return $stored;
	}

	/**
	 * Advance any Site Changeset journal through its shared guarded write engine.
	 *
	 * @param string $journal_key Journal option key.
	 * @param array  $journal     Journal.
	 * @param string $outer_key   Caller idempotency key.
	 * @param int    $max_steps   Maximum steps.
	 * @param int    $time_budget Time budget in milliseconds.
	 * @return array
	 */
	private static function advance_journal( string $journal_key, array $journal, string $outer_key, int $max_steps, int $time_budget ): array {
		if ( self::digest( $journal['candidate']['executionPolicy'] ?? [] ) !== self::digest( self::execution_policy() ) ) {
			$journal = self::stop_journal( $journal, 'site_changeset_execution_policy_changed', 'The caller execution policy changed after preview.' );
			self::persist_journal( $journal_key, $journal );
			return $journal;
		}

		$permission = self::documents_permission( $journal['candidate']['documents'] ?? null );
		if ( is_wp_error( $permission ) ) {
			$journal = self::stop_journal( $journal, $permission->get_error_code(), 'A required write permission was lost after preview.', $permission->get_error_data() );
			self::persist_journal( $journal_key, $journal );
			return $journal;
		}

		$started    = microtime( true );
		$steps_done = 0;

		while ( $steps_done < $max_steps && ( microtime( true ) - $started ) * 1000 < $time_budget ) {
			$index = self::next_step_index( $journal );
			if ( $index === null ) {
				break;
			}

			$step    = $journal['steps'][ $index ];
			$journal = ( $step['state'] ?? '' ) === 'applying'
				? self::replay_applying_step( $journal_key, $journal, $index )
				: self::prepare_and_apply_step( $journal_key, $journal, $index, $outer_key );
			++$steps_done;

			if ( self::is_terminal( (string) $journal['state'] ) ) {
				break;
			}
		}

		if ( ! self::is_terminal( (string) $journal['state'] ) ) {
			if ( self::next_step_index( $journal ) === null ) {
				$journal['state']                  = 'committed';
				$journal['manualRecoveryRequired'] = false;
				$journal['completedAt']            = time();
			} else {
				$journal['state'] = 'in_progress';
			}
			self::persist_journal( $journal_key, $journal );
		}

		return $journal;
	}

	/**
	 * Prepare a fresh focused preview and checkpoint its replay identity before
	 * invoking the inner mutation.
	 *
	 * @param string $journal_key Journal option key.
	 * @param array  $journal     Journal.
	 * @param int    $index       Step index.
	 * @param string $outer_key   Caller idempotency key.
	 * @return array
	 */
	private static function prepare_and_apply_step( string $journal_key, array $journal, int $index, string $outer_key ): array {
		$entry                               = $journal['candidate']['documents'][ $index ];
		$journal['steps'][ $index ]['state'] = 'validating';
		self::persist_journal( $journal_key, $journal );

		$fresh = self::checkout_target( $entry['target'] );
		if ( is_wp_error( $fresh ) ) {
			return self::fail_step( $journal_key, $journal, $index, $fresh );
		}

		$rebased = self::rebase_entry( $entry, $fresh['document'] );
		if ( is_wp_error( $rebased ) ) {
			return self::fail_step( $journal_key, $journal, $index, $rebased );
		}

		$preview_entry             = $entry;
		$preview_entry['document'] = $rebased;
		$preview                   = self::preview_entry( $preview_entry );

		if ( is_wp_error( $preview ) ) {
			return self::fail_step( $journal_key, $journal, $index, $preview );
		}

		if ( is_array( $preview['diff']['changedPaths'] ?? null ) ) {
			$journal['steps'][ $index ]['changedPaths'] = $preview['diff']['changedPaths'];
		}
		$journal['steps'][ $index ]['normalizationDelta'] = $preview['normalizationDelta'] ?? null;

		if ( empty( $preview['previewToken'] ) ) {
			$journal['steps'][ $index ]['state']                     = 'no_change';
			$journal['steps'][ $index ]['authoritativeDocument']     = $fresh['document'];
			$journal['steps'][ $index ]['result']                    = self::compact_inner_result( $preview );
			$journal['candidate']['documents'][ $index ]['document'] = null;
			self::persist_journal( $journal_key, $journal );
			return $journal;
		}

		$inner_token                                  = (string) $preview['previewToken'];
		$inner_key                                    = hash( 'sha256', $outer_key . "\0" . $index . "\0" . $journal['candidate']['changesetDigest'] );
		$journal['steps'][ $index ]['state']          = 'applying';
		$journal['steps'][ $index ]['innerToken']     = $inner_token;
		$journal['steps'][ $index ]['innerTokenHash'] = hash( 'sha256', $inner_token );
		$journal['steps'][ $index ]['innerKey']       = $inner_key;
		self::persist_journal( $journal_key, $journal );

		return self::replay_applying_step( $journal_key, $journal, $index );
	}

	/**
	 * Invoke or replay one checkpointed inner idempotent apply.
	 *
	 * @param string $journal_key Journal key.
	 * @param array  $journal     Journal.
	 * @param int    $index       Step index.
	 * @return array
	 */
	private static function replay_applying_step( string $journal_key, array $journal, int $index ): array {
		$step  = $journal['steps'][ $index ];
		$entry = $journal['candidate']['documents'][ $index ];

		if ( empty( $step['innerToken'] ) || empty( $step['innerKey'] ) || ! hash_equals( (string) ( $step['innerTokenHash'] ?? '' ), hash( 'sha256', (string) $step['innerToken'] ) ) ) {
			return self::manual_recovery( $journal_key, $journal, $index, 'site_changeset_inner_checkpoint_corrupt', 'The durable inner replay checkpoint is missing or corrupt.' );
		}

		$inner_input = [
			'previewToken'   => $step['innerToken'],
			'idempotencyKey' => $step['innerKey'],
		];
		$result      = self::execute_inner_ability(
			$entry['scope'] === 'page' ? 'bricks/apply-page-workspace' : 'bricks/apply-design-resource-workspace',
			$inner_input
		);

		/**
		 * Fires after an inner ability returns but before outer readback/checkpoint.
		 * A process termination here is recoverable because the replay identity was
		 * durably stored while the step was still `applying`.
		 *
		 * @param array $entry  Frozen changeset entry.
		 * @param int   $index  Step index.
		 * @param mixed $result Inner ability result.
		 */
		do_action( 'bricks_site_changeset_after_inner_apply', $entry, $index, $result );

		if ( is_wp_error( $result ) ) {
			$data      = $result->get_error_data();
			$ambiguous = in_array(
				$result->get_error_code(),
				[ 'bricks_internal_error', 'ability_callback_exception', 'ability_invalid_output' ],
				true
			);

			if ( $ambiguous || ( is_array( $data ) && ( ! empty( $data['committed'] ) || ! empty( $data['manualRecoveryRequired'] ) ) ) ) {
				return self::manual_recovery( $journal_key, $journal, $index, $result->get_error_code(), $result->get_error_message(), $data );
			}

			return self::fail_step( $journal_key, $journal, $index, $result );
		}

		$readback = self::checkout_target( $entry['target'] );
		if ( is_wp_error( $readback ) ) {
			return self::manual_recovery( $journal_key, $journal, $index, 'site_changeset_readback_failed', 'The inner write returned success, but canonical authoritative checkout failed.', [ 'innerResult' => $result ] );
		}

		$verified = self::verify_inner_readback( $entry, $result, $readback['document'] );
		if ( is_wp_error( $verified ) ) {
			return self::manual_recovery(
				$journal_key,
				$journal,
				$index,
				$verified->get_error_code(),
				$verified->get_error_message(),
				is_array( $verified->get_error_data() ) ? $verified->get_error_data() : []
			);
		}

		$readback_bytes = self::encoded_bytes( $readback['document'] );
		if ( $readback_bytes > self::MAX_DOCUMENT_BYTES ) {
			return self::manual_recovery(
				$journal_key,
				$journal,
				$index,
				'site_changeset_readback_too_large',
				'The inner write committed, but its canonical readback exceeds the bounded changeset file size. Re-read it through the focused workspace.',
				[
					'bytes'          => $readback_bytes,
					'documentDigest' => self::digest( $readback['document'] )
				]
			);
		}

		$committed                              = $journal;
		$committed['steps'][ $index ]['state']  = 'committed';
		$committed['steps'][ $index ]['result'] = self::compact_inner_result( $result );
		$committed['steps'][ $index ]['authoritativeDocument']     = $readback['document'];
		$committed['steps'][ $index ]['innerToken']                = null;
		$committed['candidate']['documents'][ $index ]['document'] = null;

		if ( self::journal_bytes( $committed ) > self::MAX_JOURNAL_BYTES ) {
			return self::manual_recovery(
				$journal_key,
				$journal,
				$index,
				'site_changeset_readback_journal_too_large',
				'The inner write committed, but its canonical readback cannot fit the bounded durable journal. Re-read it through the focused workspace.',
				[
					'bytes'          => $readback_bytes,
					'documentDigest' => self::digest( $readback['document'] )
				]
			);
		}

		$journal = $committed;
		self::persist_journal( $journal_key, $journal );

		return $journal;
	}

	/**
	 * Bind the outer authoritative readback to the exact successful inner write.
	 * A concurrent write after the inner commit is an uncertain outcome, not a
	 * normal committed result.
	 *
	 * @param array $entry    Frozen changeset entry.
	 * @param array $result   Successful inner result.
	 * @param array $readback Canonical outer readback.
	 * @return true|\WP_Error
	 */
	private static function verify_inner_readback( array $entry, array $result, array $readback ) {
		if ( $entry['scope'] === 'page' ) {
			$expected_target = $entry['document']['target'] ?? null;
			$actual_target   = $readback['target'] ?? null;
			$expected_digest = (string) ( $result['savedDocumentDigest'] ?? '' );
			$actual_digest   = (string) ( $readback['baseline']['documentDigest'] ?? '' );

			if ( $expected_target !== $actual_target || $expected_digest === '' || ! hash_equals( $expected_digest, $actual_digest ) ) {
				return Error::conflict(
					'site_changeset_readback_mismatch',
					[
						'message'                => 'The page write committed, but canonical readback no longer matches the frozen target and saved document digest. Re-read the page before manual recovery.',
						'committed'              => true,
						'manualRecoveryRequired' => true,
						'expectedTarget'         => $expected_target,
						'actualTarget'           => $actual_target,
						'expectedDigest'         => $expected_digest,
						'actualDigest'           => $actual_digest,
					]
				);
			}

			return true;
		}

		$expected_digest = (string) ( $result['authoritative']['manifest']['sha256'] ?? '' );
		$actual_digest   = self::digest( $readback );
		if ( $expected_digest === '' || ! hash_equals( $expected_digest, $actual_digest ) ) {
			return Error::conflict(
				'site_changeset_readback_mismatch',
				[
					'message'                => 'The design write committed, but canonical readback no longer matches the inner authoritative file. Re-read the resource before manual recovery.',
					'committed'              => true,
					'manualRecoveryRequired' => true,
					'expectedDigest'         => $expected_digest,
					'actualDigest'           => $actual_digest,
				]
			);
		}

		return true;
	}

	/**
	 * Fail a certain inner step without claiming rollback.
	 *
	 * @param string    $journal_key Journal key.
	 * @param array     $journal     Journal.
	 * @param int       $index       Step index.
	 * @param \WP_Error $error       Inner error.
	 * @return array
	 */
	private static function fail_step( string $journal_key, array $journal, int $index, \WP_Error $error ): array {
		$journal['steps'][ $index ]['state'] = 'failed';
		$journal['steps'][ $index ]['error'] = self::error_envelope( $error );
		$has_commit                          = self::has_committed_step( $journal );
		$journal['state']                    = $has_commit ? 'partial_commit' : 'failed_before_commit';
		$journal['manualRecoveryRequired']   = $has_commit;
		$journal['completedAt']              = time();
		self::persist_journal( $journal_key, $journal );

		return $journal;
	}

	/**
	 * Stop on an uncertain inner outcome.
	 *
	 * @param string $journal_key Journal key.
	 * @param array  $journal     Journal.
	 * @param int    $index       Step index.
	 * @param string $code        Error code.
	 * @param string $message     Message.
	 * @param array  $data        Context.
	 * @return array
	 */
	private static function manual_recovery( string $journal_key, array $journal, int $index, string $code, string $message, array $data = [] ): array {
		$journal['steps'][ $index ]['state'] = 'manual_recovery';
		$journal['steps'][ $index ]['error'] = [
			'code'    => $code,
			'message' => $message,
			'data'    => self::compact_error_data( $data )
		];
		$journal['state']                    = 'manual_recovery';
		$journal['manualRecoveryRequired']   = true;
		$journal['completedAt']              = time();
		self::persist_journal( $journal_key, $journal );

		return $journal;
	}

	/**
	 * Rebase an entry against a just-in-time checkout.
	 *
	 * @param array $entry          Frozen entry.
	 * @param array $fresh_document Fresh canonical document.
	 * @return array|\WP_Error
	 */
	private static function rebase_entry( array $entry, array $fresh_document ) {
		$frozen = $entry['document'];

		if ( $entry['scope'] === 'page' ) {
			if ( ( $frozen['target'] ?? null ) !== ( $fresh_document['target'] ?? null ) || ( $frozen['baseline']['documentDigest'] ?? null ) !== ( $fresh_document['baseline']['documentDigest'] ?? null ) ) {
				return Error::conflict( 'site_changeset_page_changed', [ 'message' => 'The target page changed after checkout.' ] );
			}

			if ( self::digest( $frozen['baseline']['designSystemSnapshot'] ?? [] ) !== self::digest( $fresh_document['baseline']['designSystemSnapshot'] ?? [] ) ) {
				return Error::conflict( 'site_changeset_page_design_changed', [ 'message' => 'The design system changed before this page step. Pages run first so this cannot be attributed to an earlier design step in the changeset.' ] );
			}

			$fresh_document['elements'] = $frozen['elements'];
			return $fresh_document;
		}

		$resource = (string) $frozen['resource'];
		if ( ( $frozen['resource'] ?? null ) !== ( $fresh_document['resource'] ?? null ) || ( $frozen['id'] ?? null ) !== ( $fresh_document['id'] ?? null ) ) {
			return Error::conflict( 'site_changeset_target_changed', [ 'message' => 'The design-resource target identity changed.' ] );
		}

		if ( self::item_identity( $resource, $frozen['baseline'] ) !== self::item_identity( $resource, $fresh_document['baseline'] ) ) {
			return Error::conflict( 'site_changeset_item_changed', [ 'message' => 'The target design item changed externally after checkout.' ] );
		}

		if ( self::digest( self::dependencies( $resource, $frozen['baseline'] ) ) !== self::digest( self::dependencies( $resource, $fresh_document['baseline'] ) ) ) {
			return Error::conflict( 'site_changeset_dependency_changed', [ 'message' => 'A guarded category/lock dependency changed after checkout.' ] );
		}

		$fresh_document['item'] = $frozen['item'];

		return $fresh_document;
	}

	/**
	 * Preview one page or design entry through its public focused contract.
	 *
	 * @param array $entry Entry.
	 * @return array|\WP_Error
	 */
	private static function preview_entry( array $entry ) {
		$ability_name = $entry['scope'] === 'page'
			? 'bricks/preview-page-workspace'
			: 'bricks/preview-design-resource-workspace';

		if ( $entry['scope'] === 'page' ) {
			return self::execute_inner_ability( $ability_name, [ 'document' => $entry['document'] ] );
		}

		$input = [ 'document' => $entry['document'] ];
		if ( ! empty( $entry['allowSlotOrphans'] ) ) {
			$input['allowSlotOrphans'] = true;
		}

		return self::execute_inner_ability( $ability_name, $input );
	}

	/**
	 * Checkout one target through its public focused contract.
	 *
	 * @param array $target Target.
	 * @return array|\WP_Error
	 */
	private static function checkout_target( array $target ) {
		if ( $target['scope'] === 'page' ) {
			$result = self::execute_inner_ability( 'bricks/checkout-page-workspace', [ 'postId' => (int) $target['postId'] ] );
		} else {
			$result = self::execute_inner_ability(
				'bricks/checkout-design-resource-workspace',
				[
					'resource' => $target['resource'],
					'id'       => $target['id']
				]
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$path = (string) ( $result['manifest']['editableFile'] ?? '' );
		if ( $path === '' || ! is_array( $result['files'][ $path ] ?? null ) ) {
			return Error::internal_error( 'site-changeset-checkout', 'The focused workspace returned no canonical editable file.', [ 'target' => $target ] );
		}

		if ( $target['scope'] === 'page' ) {
			$post = get_post( (int) $target['postId'] );
			if ( ! is_object( $post ) || empty( $post->post_type ) ) {
				return Error::internal_error( 'site-changeset-checkout', 'The checked-out page has no canonical repository path.', [ 'target' => $target ] );
			}

			$is_template = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) && $post->post_type === BRICKS_DB_TEMPLATE_SLUG;
			$outer_path  = $is_template
				? sprintf( 'templates/%d.bricks.json', (int) $target['postId'] )
				: sprintf( 'content/%s/%d.bricks.json', $post->post_type, (int) $target['postId'] );
		} else {
			// The focused design workspace already uses the virtual repository's
			// canonical classes/variables/theme-styles/components path mapping.
			$outer_path = $path;
		}

		return [
			'document' => $result['files'][ $path ],
			'path'     => $outer_path
		];
	}

	/**
	 * Execute an inner workspace through its registered ability contract.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed|\WP_Error
	 */
	private static function execute_inner_ability( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return Error::internal_error( 'site-changeset-inner-ability', 'A required focused workspace ability is unavailable. No new inner mutation was started.', [ 'ability' => $name ] );
		}

		try {
			return $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			return Error::internal_error(
				'site-changeset-inner-ability',
				$throwable->getMessage(),
				[
					'ability'                => $name,
					'exception'              => get_class( $throwable ),
					'manualRecoveryRequired' => true,
				]
			);
		}
	}

	/**
	 * Route one target to focused read/write permissions.
	 *
	 * @param array $target Target.
	 * @param bool  $write  Write intent.
	 * @return true|\WP_Error
	 */
	private static function target_permission( array $target, bool $write ) {
		if ( ( $target['scope'] ?? '' ) === 'page' ) {
			$document = [
				'target'   => [ 'postId' => (int) ( $target['postId'] ?? 0 ) ],
				'elements' => []
			];
			return $write
				? Workspace::preview_page_workspace_permission( [ 'document' => $document ] )
				: Workspace::checkout_page_workspace_permission( [ 'postId' => (int) ( $target['postId'] ?? 0 ) ] );
		}

		$input = [
			'resource' => (string) ( $target['resource'] ?? '' ),
			'id'       => (string) ( $target['id'] ?? '' )
		];
		return $write
			? Design_Workspace::preview_permission( [ 'document' => $input ] )
			: Design_Workspace::checkout_permission( $input );
	}

	/**
	 * Recheck write permission for canonical documents, preserving code-sensitive
	 * page inspection performed by the focused callback.
	 *
	 * @param mixed $documents Documents.
	 * @return true|\WP_Error
	 */
	private static function documents_permission( $documents ) {
		if ( ! is_array( $documents ) ) {
			return Error::invalid_param( 'documents', 'an array of canonical changeset documents', $documents );
		}

		foreach ( $documents as $entry ) {
			if ( ! is_array( $entry ) ) {
				return Error::invalid_param( 'documents[]', 'a canonical changeset entry', $entry );
			}

			// Completed steps replace their frozen file with canonical readback in
			// the journal. They require no further write authorization on resume.
			if ( ! is_array( $entry['document'] ?? null ) ) {
				continue;
			}

			$result = $entry['scope'] === 'page'
				? Workspace::preview_page_workspace_permission( [ 'document' => $entry['document'] ] )
				: Design_Workspace::preview_permission( [ 'document' => $entry['document'] ] );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Reauthorize every frozen target, including completed steps whose large
	 * candidate document was replaced by compact canonical readback.
	 *
	 * @param mixed $documents Frozen changeset documents.
	 * @return true|\WP_Error
	 */
	private static function document_targets_permission( $documents ) {
		if ( ! is_array( $documents ) ) {
			return Error::invalid_param( 'documents', 'an array of canonical changeset documents', $documents );
		}

		foreach ( $documents as $entry ) {
			if ( ! is_array( $entry ) || ! is_array( $entry['target'] ?? null ) ) {
				return Error::invalid_param( 'documents[]', 'a canonical changeset entry with a target', $entry );
			}

			$result = self::target_permission( $entry['target'], true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/** Reauthorize a terminal replay before returning stored summaries or files. */
	private static function terminal_journal_permission( array $journal ) {
		if ( self::digest( $journal['candidate']['executionPolicy'] ?? [] ) !== self::digest( self::execution_policy() ) ) {
			return Error::conflict( 'site_changeset_execution_policy_changed', [ 'message' => 'The caller execution policy changed after preview.' ] );
		}

		return self::document_targets_permission( $journal['candidate']['documents'] ?? null );
	}

	/**
	 * Validate and pages-first order checkout targets.
	 *
	 * @param mixed $targets Targets.
	 * @return array|\WP_Error
	 */
	private static function validate_targets( $targets ) {
		if ( ! is_array( $targets ) || count( $targets ) < 1 || count( $targets ) > self::MAX_TARGETS ) {
			return Error::invalid_param( 'targets', 'an array of 1-25 targets', $targets );
		}

		$normalized = [];
		$seen       = [];

		foreach ( $targets as $index => $target ) {
			if ( ! is_array( $target ) || ! in_array( $target['scope'] ?? '', [ 'page', 'design' ], true ) ) {
				return Error::invalid_param( "targets[{$index}]", 'a page or design target', $target );
			}

			if ( $target['scope'] === 'page' ) {
				$post_id = (int) ( $target['postId'] ?? 0 );
				if ( $post_id < 1 ) {
					return Error::invalid_param( "targets[{$index}].postId", 'a positive post ID', $post_id );
				}
				$item = [
					'scope'  => 'page',
					'postId' => $post_id
				];
				$key  = 'page:' . $post_id;
			} else {
				$resource = (string) ( $target['resource'] ?? '' );
				$id       = (string) ( $target['id'] ?? '' );
				if ( ! in_array( $resource, [ 'globalClass', 'globalVariable', 'themeStyle', 'component' ], true ) || $id === '' ) {
					return Error::invalid_param( "targets[{$index}]", 'a supported design resource and non-empty ID', $target );
				}
				$item = [
					'scope'    => 'design',
					'resource' => $resource,
					'id'       => $id
				];
				$key  = 'design:' . $resource . ':' . $id;
			}

			if ( isset( $seen[ $key ] ) ) {
				return Error::conflict(
					'site_changeset_duplicate_target',
					[
						'message' => 'A changeset cannot contain the same target twice.',
						'target'  => $item
					]
				);
			}

			$seen[ $key ]        = true;
			$item['_inputOrder'] = $index;
			$normalized[]        = $item;
		}

		usort(
			$normalized,
			static function ( array $left, array $right ): int {
				$scope_order = ( $left['scope'] === 'page' ? 0 : 1 ) <=> ( $right['scope'] === 'page' ? 0 : 1 );
				return $scope_order !== 0 ? $scope_order : $left['_inputOrder'] <=> $right['_inputOrder'];
			}
		);

		foreach ( $normalized as &$target ) {
			unset( $target['_inputOrder'] );
		}
		unset( $target );

		return $normalized;
	}

	/**
	 * Validate edited entries and canonical order.
	 *
	 * @param mixed $documents Documents.
	 * @return array|\WP_Error
	 */
	private static function validate_documents( $documents ) {
		if ( ! is_array( $documents ) || count( $documents ) < 1 || count( $documents ) > self::MAX_TARGETS ) {
			return Error::invalid_param( 'documents', 'an array of 1-25 canonical files', $documents );
		}

		$total   = 0;
		$targets = [];
		$by_key  = [];

		foreach ( $documents as $index => $entry ) {
			if ( ! is_array( $entry ) || ! is_array( $entry['target'] ?? null ) || ! is_array( $entry['document'] ?? null ) || ( $entry['scope'] ?? '' ) !== ( $entry['target']['scope'] ?? '' ) ) {
				return Error::invalid_param( "documents[{$index}]", 'a checkout entry with matching scope, target, and document', $entry );
			}

			$bytes = self::encoded_bytes( $entry['document'] );
			if ( $bytes > self::MAX_DOCUMENT_BYTES ) {
				return Error::conflict(
					'site_changeset_document_too_large',
					[
						'message' => 'One edited file exceeds the 1.5 MiB changeset limit.',
						'index'   => $index,
						'bytes'   => $bytes
					]
				);
			}
			$total         += $bytes;
			$targets[]      = $entry['target'];
			$key            = self::target_key( $entry['target'] );
			$by_key[ $key ] = $entry;
		}

		if ( $total > self::MAX_CHANGESET_BYTES ) {
			return Error::conflict(
				'site_changeset_too_large',
				[
					'message' => 'The edited files exceed the 6 MiB bounded changeset limit.',
					'bytes'   => $total
				]
			);
		}

		$ordered = self::validate_targets( $targets );
		if ( is_wp_error( $ordered ) ) {
			return $ordered;
		}

		$result = [];
		foreach ( $ordered as $target ) {
			$key = self::target_key( $target );
			if ( ! isset( $by_key[ $key ] ) ) {
				return Error::conflict( 'site_changeset_target_mismatch', [ 'message' => 'An edited document target does not match the bounded manifest.' ] );
			}
			$result[] = $by_key[ $key ];
		}

		return $result;
	}

	/**
	 * Create a durable journal before the first inner side effect.
	 *
	 * @param string $id         Journal ID.
	 * @param string $token_hash Outer token hash.
	 * @param array  $candidate  Frozen candidate.
	 * @return array
	 */
	private static function new_journal( string $id, string $token_hash, array $candidate ): array {
		$steps = [];
		foreach ( $candidate['documents'] as $index => $entry ) {
			$steps[] = [
				'index'                 => $index,
				'target'                => $entry['target'],
				'state'                 => 'pending',
				'innerToken'            => null,
				'innerTokenHash'        => null,
				'innerKey'              => null,
				'result'                => null,
				'error'                 => null,
				'authoritativeDocument' => null,
			];
		}

		return [
			'id'                     => $id,
			'state'                  => 'prepared',
			'manualRecoveryRequired' => false,
			'createdAt'              => time(),
			'previewTokenHash'       => $token_hash,
			'candidate'              => $candidate,
			'steps'                  => $steps,
		];
	}

	/**
	 * Return the compact single-file result, reauthorizing focused checkout
	 * before disclosing a stored terminal canonical readback.
	 *
	 * @param array $journal Journal.
	 * @return array|\WP_Error
	 */
	private static function agent_file_journal_response( array $journal ) {
		$step          = $journal['steps'][0] ?? [];
		$entry         = $journal['candidate']['documents'][0] ?? [];
		$target        = is_array( $step['target'] ?? null ) ? $step['target'] : ( $entry['target'] ?? [] );
		$authoritative = is_array( $step['authoritativeDocument'] ?? null ) ? $step['authoritativeDocument'] : null;

		if ( self::is_terminal( (string) ( $journal['state'] ?? '' ) ) && $authoritative !== null ) {
			$permission = self::documents_permission(
				[
					[
						'scope'    => (string) ( $target['scope'] ?? '' ),
						'target'   => $target,
						'document' => $authoritative,
					],
				]
			);
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$authorized = self::checkout_target( $target );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
		}

		$state = (string) ( $journal['state'] ?? 'manual_recovery' );
		if ( $state === 'committed' && ( $step['state'] ?? '' ) === 'no_change' ) {
			$state = 'no_change';
		}

		$path     = (string) ( $entry['path'] ?? '' );
		$response = [
			'contractVersion'        => self::CONTRACT_VERSION,
			'state'                  => $state,
			'target'                 => $target,
			'path'                   => $path,
			'authoritative'          => $authoritative === null ? null : [
				'target'   => $target,
				'path'     => $path,
				'document' => $authoritative,
				'sha256'   => self::digest( $authoritative ),
				'bytes'    => self::encoded_bytes( $authoritative ),
			],
			'normalizationDelta'     => $step['normalizationDelta'] ?? ( $step['result']['normalizationDelta'] ?? null ),
			'operationDigest'        => (string) ( $journal['requestDigest'] ?? '' ),
			'changesetDigest'        => (string) ( $journal['candidate']['changesetDigest'] ?? '' ),
			'manualRecoveryRequired' => ! empty( $journal['manualRecoveryRequired'] ),
			'stopError'              => $journal['stopError'] ?? ( $step['error'] ?? null ),
			'retryExpiresAt'         => (int) ( $journal['createdAt'] ?? time() ) + self::JOURNAL_TTL,
			'atomic'                 => false,
		];
		if ( is_array( $step['changedPaths'] ?? null ) ) {
			$response['changedPaths'] = $step['changedPaths'];
		}
		return $response;
	}

	/**
	 * Compact journal response with canonical authoritative readbacks.
	 *
	 * @param array    $journal        Journal.
	 * @param int|null $response_start First step processed by this request.
	 * @param int      $steps_done     Steps processed by this request.
	 * @param string   $response_format Readback response format.
	 * @return array
	 */
	private static function journal_response( array $journal, $response_start = null, int $steps_done = 0, string $response_format = 'document' ): array {
		$steps           = $journal['steps'] ?? [];
		$end             = is_int( $response_start ) ? $response_start + $steps_done : null;
		$state           = (string) ( $journal['state'] ?? 'manual_recovery' );
		$no_change_steps = array_filter(
			$steps,
			static function ( $step ): bool {
				return (string) ( $step['state'] ?? '' ) === 'no_change';
			}
		);
		if ( $state === 'committed' && ! empty( $steps ) && count( $no_change_steps ) === count( $steps ) ) {
			$state = 'no_change';
		}

		foreach ( $steps as $index => &$step ) {
			unset( $step['innerToken'] );

			$include_document = is_int( $response_start ) && is_int( $end ) && $index >= $response_start && $index < $end;
			if ( ! $include_document || $response_format === 'summary' ) {
				if ( $response_format === 'summary' && is_array( $step['authoritativeDocument'] ?? null ) ) {
					$step['authoritativeSummary'] = self::authoritative_summary( $step['authoritativeDocument'] );
				}
				unset( $step['authoritativeDocument'] );
			} elseif ( is_array( $step['authoritativeDocument'] ?? null ) ) {
				$step['authoritativeDocumentIncluded'] = true;
			}
		}
		unset( $step );

		return [
			'contractVersion'        => self::CONTRACT_VERSION,
			'changesetDigest'        => (string) ( $journal['candidate']['changesetDigest'] ?? '' ),
			'state'                  => $state,
			'manualRecoveryRequired' => ! empty( $journal['manualRecoveryRequired'] ),
			'stopError'              => $journal['stopError'] ?? null,
			'nextStep'               => self::is_terminal( (string) ( $journal['state'] ?? '' ) ) ? null : self::next_step_index( $journal ),
			'steps'                  => $steps,
			'atomic'                 => false,
		];
	}

	/**
	 * Return compact authoritative identity for one committed canonical file.
	 *
	 * @param array $document Canonical readback.
	 * @return array
	 */
	private static function authoritative_summary( array $document ): array {
		if ( isset( $document['elements'] ) ) {
			return [
				'scope'          => 'page',
				'postId'         => (int) ( $document['target']['postId'] ?? 0 ),
				'documentDigest' => (string) ( $document['baseline']['documentDigest'] ?? '' ),
				'elementCount'   => count( (array) $document['elements'] ),
				'readbackDigest' => self::digest( $document ),
			];
		}

		$baseline  = $document['baseline'] ?? [];
		$ownership = $baseline['itemOwnership'] ?? $baseline['expectedOwnership'] ?? $baseline['expectedVariableOwnership'] ?? [];
		$component = (string) ( $document['resource'] ?? '' ) === 'component';
		return [
			'scope'          => 'design',
			'resource'       => (string) ( $document['resource'] ?? '' ),
			'id'             => (string) ( $document['id'] ?? '' ),
			'version'        => $component ? (int) ( $baseline['expectedDesignSystemVersion'] ?? 0 ) : (int) ( $ownership['version'] ?? 0 ),
			'itemDigest'     => $component ? (string) ( $baseline['expectedComponentDigest'] ?? '' ) : (string) ( $ownership['itemDigest'] ?? '' ),
			'readbackDigest' => self::digest( $document ),
		];
	}

	/**
	 * Persist and verify a journal checkpoint.
	 *
	 * @param string $key     Option key.
	 * @param array  $journal Journal.
	 * @return void
	 * @throws \RuntimeException When the checkpoint exceeds its bound or cannot be verified.
	 */
	private static function persist_journal( string $key, array $journal ): void {
		if ( self::journal_bytes( $journal ) > self::MAX_JOURNAL_BYTES ) {
			throw new \RuntimeException( 'The durable site changeset journal exceeded its 8 MiB safety bound.' );
		}

		update_option( $key, $journal, false );
		$stored = get_option( $key, null );

		if ( ! is_array( $stored ) || ! hash_equals( self::digest( $journal ), self::digest( $stored ) ) ) {
			throw new \RuntimeException( 'The durable site changeset checkpoint could not be verified.' );
		}
	}

	/**
	 * Stop for policy/permission loss with honest state.
	 *
	 * @param array  $journal Journal.
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param array  $data    Context.
	 * @return array
	 */
	private static function stop_journal( array $journal, string $code, string $message, array $data = [] ): array {
		$has_commit                        = self::has_committed_step( $journal );
		$journal['state']                  = $has_commit ? 'partial_commit' : 'failed_before_commit';
		$journal['manualRecoveryRequired'] = $has_commit;
		$journal['stopError']              = [
			'code'    => $code,
			'message' => $message,
			'data'    => self::compact_error_data( $data )
		];
		$journal['completedAt']            = time();

		return $journal;
	}

	/**
	 * Next non-terminal step.
	 *
	 * @param array $journal Journal.
	 * @return int|null
	 */
	private static function next_step_index( array $journal ) {
		foreach ( $journal['steps'] ?? [] as $index => $step ) {
			if ( in_array( $step['state'] ?? '', [ 'pending', 'validating', 'applying' ], true ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Whether any actual inner write committed.
	 *
	 * @param array $journal Journal.
	 * @return bool
	 */
	private static function has_committed_step( array $journal ): bool {
		foreach ( $journal['steps'] ?? [] as $step ) {
			if ( ( $step['state'] ?? '' ) === 'committed' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Terminal outer states.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	private static function is_terminal( string $state ): bool {
		return in_array( $state, [ 'committed', 'partial_commit', 'failed_before_commit', 'manual_recovery' ], true );
	}

	/**
	 * Target schema.
	 *
	 * @return array
	 */
	private static function target_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'scope'    => [
					'type' => 'string',
					'enum' => [ 'page', 'design' ]
				],
				'postId'   => [ 'type' => 'integer' ],
				'resource' => [
					'type' => 'string',
					'enum' => [ 'globalClass', 'globalVariable', 'themeStyle', 'component' ]
				],
				'id'       => [ 'type' => 'string' ],
			],
			'required'             => [ 'scope' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Stable target key.
	 *
	 * @param array $target Target.
	 * @return string
	 */
	private static function target_key( array $target ): string {
		return $target['scope'] === 'page'
			? 'page:' . (int) $target['postId']
			: 'design:' . $target['resource'] . ':' . $target['id'];
	}

	/**
	 * Design item identity.
	 *
	 * @param string $resource Resource.
	 * @param array  $baseline Baseline.
	 * @return string
	 */
	private static function item_identity( string $resource, array $baseline ): string {
		switch ( $resource ) {
			case 'globalClass':
			case 'themeStyle':
				return (string) ( $baseline['expectedOwnership']['itemDigest'] ?? '' );
			case 'globalVariable':
				return (string) ( $baseline['itemOwnership']['itemDigest'] ?? '' );
			case 'component':
				return (string) ( $baseline['expectedComponentDigest'] ?? '' );
		}

		return '';
	}

	/**
	 * Guarded dependency identities, intentionally excluding a shared version
	 * counter which an earlier own step may advance without changing the row.
	 *
	 * @param string $resource Resource.
	 * @param array  $baseline Baseline.
	 * @return array
	 */
	private static function dependencies( string $resource, array $baseline ): array {
		if ( $resource === 'globalClass' ) {
			return [
				'locks'      => self::dependency_identity( $baseline['lockOwnership'] ?? [] ),
				'categories' => self::dependency_identity( $baseline['expectedCategoryOwnership'] ?? [] ),
			];
		}

		if ( $resource === 'globalVariable' ) {
			return [ 'categories' => self::dependency_identity( $baseline['expectedCategoryOwnership'] ?? [] ) ];
		}

		return [];
	}

	/**
	 * Exact dependency row/authority identity.
	 *
	 * @param array $ownership Ownership.
	 * @return array
	 */
	private static function dependency_identity( array $ownership ): array {
		return [
			'resource'       => $ownership['resource'] ?? null,
			'siteId'         => $ownership['siteId'] ?? null,
			'resourceDigest' => $ownership['resourceDigest'] ?? null,
		];
	}

	/**
	 * Execution-policy identity.
	 *
	 * @return array
	 */
	private static function execution_policy(): array {
		return [
			'userId'         => get_current_user_id(),
			'canExecuteCode' => \Bricks\Capabilities::current_user_can_execute_code(),
		];
	}

	/**
	 * Error envelope safe for a durable journal.
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private static function error_envelope( \WP_Error $error ): array {
		$data = self::compact_error_data( $error->get_error_data() );

		return [
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $data
		];
	}

	/**
	 * Bound durable error context while retaining an integrity digest for full
	 * payloads that must be re-read from their authoritative resource.
	 *
	 * @param mixed $data Error data.
	 * @return mixed
	 */
	private static function compact_error_data( $data ) {
		$encoded = wp_json_encode( $data );

		if ( is_string( $encoded ) && strlen( $encoded ) > 65536 ) {
			return [
				'truncated'  => true,
				'dataDigest' => hash( 'sha256', $encoded ),
				'bytes'      => strlen( $encoded ),
			];
		}

		return $data;
	}

	/**
	 * Retain transaction identity and diagnostics without duplicating the full
	 * canonical file, which is stored separately as authoritativeDocument.
	 *
	 * @param array $result Focused result.
	 * @return array
	 */
	private static function compact_inner_result( array $result ): array {
		$keys    = [ 'transactionState', 'candidateDigest', 'changed', 'committed', 'savedDocumentDigest', 'designSystemVersion', 'revisionId', 'diagnostics', 'normalizationDelta' ];
		$compact = [];

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $result ) ) {
				continue;
			}

			if ( $key === 'normalizationDelta' && is_array( $result[ $key ] ) ) {
				$compact[ $key ] = [
					'changedPaths' => $result[ $key ]['changedPaths'] ?? [],
					'message'      => $result[ $key ]['message'] ?? '',
				];
				continue;
			}

			$compact[ $key ] = $result[ $key ];
		}

		return $compact;
	}

	/**
	 * Encoded byte size.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function encoded_bytes( $value ): int {
		return strlen( (string) wp_json_encode( $value ) );
	}

	/**
	 * Exact serialized option bytes, including PHP array/string overhead.
	 *
	 * @param array $journal Journal.
	 * @return int
	 */
	private static function journal_bytes( array $journal ): int {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress options use PHP serialization; this enforces the exact stored-size bound before persistence.
		return strlen( serialize( $journal ) );
	}

	/**
	 * Stable canonical digest.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalize( $value ) ) );
	}

	/**
	 * Canonicalize associative key order.
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
	 * Candidate digest excluding its self-reference.
	 *
	 * @param array $candidate Candidate.
	 * @return string
	 */
	private static function candidate_digest( array $candidate ): string {
		unset( $candidate['changesetDigest'] );

		return self::digest( $candidate );
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
	 * Journal option key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Caller key.
	 * @return string
	 */
	private static function journal_key( int $user_id, string $key ): string {
		return self::JOURNAL_PREFIX . substr( hash( 'sha256', $user_id . "\0" . $key ), 0, 40 );
	}

	/**
	 * Session lock.
	 *
	 * @param string $key Authority key.
	 * @return string|\WP_Error
	 */
	private static function acquire_lock( string $key ) {
		global $wpdb;
		$name = 'bricks_cs_' . substr( hash( 'sha256', $key ), 0, 40 );
		$got  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session lock fences outer resume/apply.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name )
		);

		return (string) $got === '1'
			? $name
			: Error::conflict( 'site_changeset_in_progress', [ 'message' => 'This changeset is already applying. Retry the same token and key shortly.' ] );
	}

	/**
	 * Release session lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_lock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases outer changeset lock.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name )
		);
	}

	/**
	 * Cleanup terminal journals after the retry window. Partial/uncertain
	 * journals retain evidence; abandoned nonterminal journals become explicit
	 * manual recovery instead of disappearing.
	 *
	 * @param string $key Journal key.
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
			if ( ! is_array( $journal ) ) {
				delete_option( $key );
				return;
			}

			if ( in_array( $journal['state'] ?? '', [ 'committed', 'failed_before_commit' ], true ) ) {
				delete_option( $key );
				return;
			}

			if ( ! self::is_terminal( (string) ( $journal['state'] ?? '' ) ) ) {
				$journal['state']                  = 'manual_recovery';
				$journal['manualRecoveryRequired'] = true;
				$journal['stopError']              = [
					'code'    => 'site_changeset_abandoned',
					'message' => 'The changeset exceeded its resume window with an uncertain nonterminal step.',
					'data'    => []
				];
				self::persist_journal( $key, $journal );
			}
		} finally {
			self::release_lock( $lock );
		}
	}
}

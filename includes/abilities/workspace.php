<?php
/**
 * Agent workspace abilities
 *
 * Compact, preview-bound commit workflows for file-oriented coding agents.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview-bound transaction workflows for file-oriented Bricks authoring.
 */
class Workspace {
	const CONTRACT_VERSION              = '1';
	const PAGE_WORKSPACE_SCHEMA_VERSION = 1;
	const PREVIEW_TTL                   = 900;
	const JOURNAL_PREFIX                = 'bricks_agent_import_journal_';
	const PREVIEW_PREFIX                = 'bricks_agent_import_preview_';
	const FACADE_PREFIX                 = 'bricks_agent_import_facade_';

	/**
	 * Destroy one page-workspace preview which an outer coordinator inspected
	 * but will never apply directly.
	 *
	 * @param string $token Focused preview bearer token.
	 * @return bool
	 */
	public static function discard_page_workspace_preview( string $token ): bool {
		if ( $token === '' ) {
			return false;
		}

		return delete_transient( self::preview_key( $token ) );
	}

	/**
	 * Preview input schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function preview_html_css_page_import_schema() {
		$properties = Elements::post_identifier_properties();

		$properties['html']                         = [
			'type'        => 'string',
			'description' => __( 'Authoritative semantic HTML to compile and freeze. Use class="brxe-block" for a native Block, class="brxe-container" for a native Container, class="brxe-button" on linked native Buttons, and stable IDs for one-off native CSS targeting.', 'bricks' ),
		];
		$properties['css']                          = [
			'type'        => 'string',
			'description' => __( 'CSS source paired with the HTML file. Include this field whenever the brief has responsive behavior; prefer stable ID selectors and explicit media queries so declarations map to native settings. On sites using default Bricks breakpoints, mobile portrait is max-width: 478px; read design context first when the site has custom breakpoints.', 'bricks' ),
		];
		$properties['documentPurpose']              = [
			'type'        => 'string',
			'enum'        => [ 'page-content', 'template-content', 'migration' ],
			'default'     => 'page-content',
			'description' => __( 'How Bricks should interpret the source document. Use page-content for a page body and template-content for the inside of a Bricks template; omit landmarks Bricks supplies automatically. Use migration only when preserving an external document fragment exactly.', 'bricks' ),
		];
		$properties['options']                      = [
			'type'                 => 'object',
			'description'          => __( 'HTML/CSS converter and fail-closed import policy options.', 'bricks' ),
			'properties'           => [
				'create_global_classes'    => [
					'type'        => 'boolean',
					'description' => __( 'Whether CSS class selectors may become Bricks global classes. Default true.', 'bricks' ),
				],
				'extract_variables'        => [
					'type'        => 'boolean',
					'description' => __( 'Whether :root custom properties may become Bricks global variables. Default true.', 'bricks' ),
				],
				'source_root_font_size_px' => [
					'type'             => 'number',
					'exclusiveMinimum' => 0,
					'description'      => __( 'Source root font size for rem normalization. Requires target_root_font_size_px.', 'bricks' ),
				],
				'target_root_font_size_px' => [
					'type'             => 'number',
					'exclusiveMinimum' => 0,
					'description'      => __( 'Target Bricks root font size for rem normalization. Requires source_root_font_size_px.', 'bricks' ),
				],
				'preserve_html_defaults'   => [
					'type'        => 'boolean',
					'description' => __( 'Preserve deterministic semantic HTML defaults as scoped custom CSS. Defaults to false for page-content and true for migration; set explicitly when the source relies on browser defaults.', 'bricks' ),
				],
				'custom_css_policy'        => [
					'type'        => 'string',
					'enum'        => [ 'allow', 'forbid' ],
					'description' => __( 'Set forbid to reject any candidate containing a Code element or non-empty _cssCustom setting.', 'bricks' ),
				],
				'global_resource_policy'   => [
					'type'        => 'string',
					'enum'        => [ 'allow', 'forbid_creates' ],
					'description' => __( 'Set forbid_creates to reject candidates that would create global classes or variables.', 'bricks' ),
				],
			],
			'additionalProperties' => false,
		];
		$properties['expectedDesignSystemVersion']  = [
			'type'        => 'integer',
			'description' => __( 'Required legacy current-site version from get-design-context. Mixed-authority multisite setups must also pass expectedDesignSystemSnapshot.', 'bricks' ),
		];
		$properties['expectedDesignSystemSnapshot'] = [
			'type'        => 'object',
			'description' => __( 'Authority-correct snapshot from get-design-context. Required when design resources span multiple multisite authorities.', 'bricks' ),
		];
		$properties['expectedDocumentDigest']       = [
			'type'        => 'string',
			'description' => __( 'Optional SHA-256 digest from an earlier read. Preview rejects stale callers and otherwise binds the current page digest into the candidate.', 'bricks' ),
		];
		$properties['replaceExisting']              = [
			'type'        => 'boolean',
			'description' => __( 'Pass false explicitly for a known empty target. Required true only when intentionally replacing a non-empty page or template area.', 'bricks' ),
		];
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => [ 'html', 'expectedDesignSystemVersion' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * One-call import schema for coding agents.
	 *
	 * The server captures the current design-system authority immediately before
	 * preview, so callers do not need a separate context turn for a new import.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function commit_html_css_page_import_schema() {
		$schema = self::preview_html_css_page_import_schema();

		unset(
			$schema['properties']['expectedDesignSystemVersion'],
			$schema['properties']['expectedDesignSystemSnapshot']
		);

		$schema['properties']['idempotencyKey'] = [
			'type'        => 'string',
			'maxLength'   => 128,
			'description' => __( 'Stable key for this exact intended import. Exact retries resume or return the original durable result.', 'bricks' ),
		];
		$schema['properties']['responseFormat'] = [
			'type'        => 'string',
			'enum'        => [ 'summary', 'detailed' ],
			'default'     => 'summary',
			'description' => __( 'Return a compact authoritative summary after a warning-free commit. Warning-bearing previews always retain the details required for review.', 'bricks' ),
		];
		$schema['required']                     = [ 'html', 'idempotencyKey' ];

		return $schema;
	}

	/**
	 * Checkout schema for a canonical, file-oriented page workspace.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function checkout_page_workspace_schema() {
		return [
			'type'                 => 'object',
			'properties'           => Elements::post_identifier_properties(),
			'additionalProperties' => false,
		];
	}

	/**
	 * Preview schema for a canonical page workspace document.
	 *
	 * The complete document is intentionally accepted only by preview. Apply
	 * consumes the frozen token so a retry cannot accidentally commit a newer
	 * local file than the one the user reviewed.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function preview_page_workspace_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'document' => [
					'type'                 => 'object',
					'properties'           => [
						'schemaVersion' => [ 'type' => 'integer' ],
						'target'        => [
							'type'                 => 'object',
							'properties'           => [
								'postId' => [ 'type' => 'integer' ],
								'area'   => [ 'type' => 'string' ],
							],
							'required'             => [ 'postId', 'area' ],
							'additionalProperties' => false,
						],
						'baseline'      => [
							'type'                 => 'object',
							'properties'           => [
								'documentDigest'       => [ 'type' => 'string' ],
								'designSystemVersion'  => [ 'type' => 'integer' ],
								'designSystemSnapshot' => [ 'type' => 'object' ],
							],
							'required'             => [ 'documentDigest', 'designSystemVersion' ],
							'additionalProperties' => false,
						],
						'elements'      => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
					],
					'required'             => [ 'schemaVersion', 'target', 'baseline', 'elements' ],
					'additionalProperties' => false,
				],
			],
			'required'             => [ 'document' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Checkout output schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function checkout_page_workspace_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion' => [ 'type' => 'string' ],
				'schemaVersion'   => [ 'type' => 'integer' ],
				'manifest'        => [ 'type' => 'object' ],
				'files'           => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Apply input schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function apply_html_css_page_import_schema() {
		$properties = [];

		$properties['previewToken']        = [
			'type'        => 'string',
			'description' => __( 'Opaque token returned by preview. Bound to the current user, target, exact normalized candidate, and a 15-minute expiry.', 'bricks' ),
		];
		$properties['idempotencyKey']      = [
			'type'        => 'string',
			'maxLength'   => 128,
			'description' => __( 'Stable key for this exact intended commit. Committed and rolled-back outcomes are retained for 24 hours.', 'bricks' ),
		];
		$properties['acknowledgeWarnings'] = [
			'type'        => 'boolean',
			'description' => __( 'Set true when preview requires warning acknowledgement and you reviewed those warnings. Automatic restricted-content omissions alone do not require acknowledgement.', 'bricks' ),
		];

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => [ 'previewToken', 'idempotencyKey' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Shared output schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function html_css_page_import_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'action'                     => [ 'type' => 'string' ],
				'workflow'                   => [ 'type' => 'string' ],
				'contractVersion'            => [ 'type' => 'string' ],
				'postId'                     => [ 'type' => 'integer' ],
				'previewToken'               => [ 'type' => [ 'string', 'null' ] ],
				'expiresAt'                  => [ 'type' => [ 'integer', 'null' ] ],
				'candidateDigest'            => [ 'type' => 'string' ],
				'expectedDocumentDigest'     => [ 'type' => 'string' ],
				'savedDocumentDigest'        => [ 'type' => [ 'string', 'null' ] ],
				'designSystemVersionBefore'  => [ 'type' => 'integer' ],
				'designSystemVersion'        => [ 'type' => 'integer' ],
				'designSystemSnapshotBefore' => [ 'type' => 'object' ],
				'designSystemSnapshot'       => [ 'type' => 'object' ],
				'plan'                       => [ 'type' => 'object' ],
				'partial'                    => [
					'type'        => 'boolean',
					'description' => __( 'Whether restricted elements were omitted from the imported content.', 'bricks' ),
				],
				'omittedElements'            => [
					'type'        => 'array',
					'description' => __( 'Omitted element IDs and types with permission reason codes. Source code is not returned.', 'bricks' ),
					'items'       => [
						'type'       => 'object',
						'required'   => [ 'id', 'name', 'reason' ],
						'properties' => [
							'id'     => [ 'type' => 'string' ],
							'name'   => [ 'type' => 'string' ],
							'reason' => [ 'type' => 'string' ],
						],
					],
				],
				'diagnostics'                => [ 'type' => 'object' ],
				'render'                     => [ 'type' => 'object' ],
				'committed'                  => [ 'type' => 'boolean' ],
				'transactionState'           => [ 'type' => 'string' ],
				'revisionId'                 => [ 'type' => [ 'integer', 'null' ] ],
				'journalId'                  => [ 'type' => [ 'string', 'null' ] ],
				'autoApplied'                => [ 'type' => 'boolean' ],
				'nextAction'                 => [ 'type' => 'string' ],
				'facadeRequestDigest'        => [ 'type' => 'string' ],
				'responseFormat'             => [ 'type' => 'string' ],
				'designSystemSnapshotHash'   => [ 'type' => 'string' ],
				'routes'                     => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Permission callback for preview and apply.
	 *
	 * Resource-specific design permissions are checked only when conversion
	 * actually proposes those resources, avoiding an unnecessary admin gate
	 * for an element-only import.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_html_css_page_import_permission( $input ) {
		return Elements::write_elements_permission( $input );
	}

	/**
	 * Permission callback for the one-call import facade.
	 *
	 * Candidate-specific code and design-resource permissions remain fail-closed
	 * inside preview and apply after conversion reveals the exact mutation set.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function commit_html_css_page_import_permission( $input ) {
		return self::preview_html_css_page_import_permission( $input );
	}

	/**
	 * Permission callback for page workspace checkout.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function checkout_page_workspace_permission( $input ) {
		return Elements::read_post_permission( $input );
	}

	/**
	 * Fail closed when a lossless editable workspace would disclose executable payloads.
	 *
	 * A redacted full-tree file cannot safely round-trip through preview/apply, so
	 * page workspaces require execute-code access whenever either authoritative or
	 * submitted elements contain the same sensitive payloads guarded by element writes.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Complete authoritative or submitted elements.
	 * @return true|\WP_Error
	 */
	private static function require_page_workspace_code_access( array $elements ) {
		if ( \Bricks\Capabilities::current_user_can_execute_code() ) {
			return true;
		}

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( Elements::code_sensitive_payload( $element ) ) ) {
				return Error::forbidden_builder_permission( \Bricks\Capabilities::EXECUTE_CODE );
			}
		}

		return true;
	}

	/**
	 * Recheck a frozen page-workspace candidate against the caller's current permission.
	 *
	 * @since 2.4
	 *
	 * @param array $candidate Frozen preview or journal candidate.
	 * @return true|\WP_Error
	 */
	private static function require_page_workspace_candidate_code_access( array $candidate ) {
		if ( ( $candidate['workflow'] ?? '' ) !== 'page-workspace' ) {
			return true;
		}

		$elements = is_array( $candidate['elements'] ?? null ) ? $candidate['elements'] : [];
		$access   = self::require_page_workspace_code_access( $elements );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$post_id = (int) ( $candidate['postId'] ?? 0 );
		$area    = (string) ( $candidate['area'] ?? '' );
		if ( $post_id < 1 || $area === '' ) {
			return true;
		}

		$authoritative = \Bricks\Database::get_data( $post_id, $area );
		$authoritative = is_array( $authoritative ) ? $authoritative : [];

		return self::require_page_workspace_code_access( $authoritative );
	}

	/**
	 * Permission callback for page workspace preview.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function preview_page_workspace_permission( $input ) {
		$post_id    = (int) ( $input['document']['target']['postId'] ?? 0 );
		$permission = Elements::write_elements_permission( [ 'postId' => $post_id ] );

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$area          = Elements::get_save_area_for_post( $post_id );
		$authoritative = \Bricks\Database::get_data( $post_id, $area );
		$authoritative = is_array( $authoritative ) ? $authoritative : [];
		$code_access   = self::require_page_workspace_code_access( $authoritative );

		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		$elements = is_array( $input['document']['elements'] ?? null ) ? $input['document']['elements'] : [];

		return self::require_page_workspace_code_access( $elements );
	}

	/**
	 * Authorize page-workspace apply against its frozen target.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function apply_page_workspace_permission( $input ) {
		$candidate = self::resolve_apply_candidate( $input );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}

		if ( ( $candidate['workflow'] ?? '' ) !== 'page-workspace' ) {
			return Error::conflict(
				'preview_workflow_mismatch',
				[
					'message' => 'This preview token was created by another workspace workflow.',
				]
			);
		}

		$permission = Elements::write_elements_permission(
			[
				'postId' => (int) $candidate['postId'],
			]
		);

		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return self::require_page_workspace_candidate_code_access( $candidate );
	}

	/**
	 * Authorize apply against the post frozen into its preview or journal.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function apply_html_css_page_import_permission( $input ) {
		$candidate = self::resolve_apply_candidate( $input );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}

		return Elements::write_elements_permission(
			[
				'postId' => (int) $candidate['postId'],
			]
		);
	}

	/**
	 * Compile and freeze a read-only preview.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview_html_css_page_import( $input ) {
		return self::preview_import( $input );
	}

	/**
	 * Preview and commit one HTML/CSS import without a redundant model turn.
	 *
	 * Warning-bearing previews are never auto-acknowledged. They return the same
	 * frozen token as the explicit preview ability for review and a subsequent
	 * apply call. Warning-free candidates continue through the existing token,
	 * lock, journal, CAS, compensation, and authoritative-readback pipeline.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function commit_html_css_page_import( $input ) {
		$idempotency_key = trim( (string) ( $input['idempotencyKey'] ?? '' ) );
		$response_format = self::commit_import_response_format( $input );

		if ( is_wp_error( $response_format ) ) {
			return $response_format;
		}

		if ( $idempotency_key === '' ) {
			return Error::missing_param( 'idempotencyKey' );
		}

		if ( strlen( $idempotency_key ) > 128 ) {
			return Error::invalid_param( 'idempotencyKey', 'a string no longer than 128 characters', $idempotency_key );
		}

		$preview_input = $input;
		unset( $preview_input['idempotencyKey'], $preview_input['responseFormat'] );

		$request_digest = self::commit_import_request_digest( $preview_input, $idempotency_key );
		$preview_token  = self::commit_import_preview_token( $request_digest );
		$apply_input    = [
			'previewToken'   => $preview_token,
			'idempotencyKey' => $idempotency_key,
		];

		$facade_lock = self::acquire_import_lock( 'facade:' . get_current_blog_id() . ':' . get_current_user_id() . ':' . $idempotency_key );
		if ( is_wp_error( $facade_lock ) ) {
			return $facade_lock;
		}

		try {
			$binding_key = self::facade_key( get_current_user_id(), $idempotency_key );
			$binding     = get_transient( $binding_key );
			if ( is_array( $binding ) && ! hash_equals( (string) ( $binding['requestDigest'] ?? '' ), $request_digest ) ) {
				return Error::conflict(
					'import_idempotency_key_reused',
					[ 'message' => 'This idempotency key already belongs to a different HTML/CSS import intent.' ]
				);
			}

			// A durable journal means the original facade call crossed the commit
			// boundary. Replay it before reading mutable design-system authority.
			$journal = get_option( self::journal_key( get_current_user_id(), $idempotency_key ), null );
			if ( is_array( $journal ) ) {
				$journal_digest = (string) ( $journal['candidate']['facadeRequestDigest'] ?? '' );
				if ( $journal_digest !== '' && ! hash_equals( $journal_digest, $request_digest ) ) {
					return Error::conflict(
						'import_idempotency_key_reused',
						[ 'message' => 'This idempotency key already belongs to a different HTML/CSS import intent.' ]
					);
				}

				$permission = self::apply_html_css_page_import_permission( $apply_input );
				if ( is_wp_error( $permission ) ) {
					return $permission;
				}

				$result = self::apply_import( $apply_input );
				return self::decorate_facade_response( $result, true, $request_digest, $response_format );
			}

			if ( ! is_array( $binding ) && ! set_transient( $binding_key, [ 'requestDigest' => $request_digest ], self::PREVIEW_TTL ) ) {
				return Error::internal_error( 'html-css-page-import', 'The import facade could not bind its idempotency key. No data was changed.' );
			}

			$snapshot = Design::design_system_snapshot();
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}

			$preview_input['expectedDesignSystemVersion']  = (int) $snapshot['resources']['currentSite']['version'];
			$preview_input['expectedDesignSystemSnapshot'] = $snapshot;
			$preview                                       = self::preview_import( $preview_input, $preview_token, $request_digest );

			if ( is_wp_error( $preview ) ) {
				return $preview;
			}

			if ( ! empty( $preview['diagnostics']['requiresAcknowledgement'] ) ) {
				$preview['transactionState'] = 'preview_requires_acknowledgement';
				return self::decorate_facade_response( $preview, false, $request_digest, $response_format );
			}

			$permission = self::apply_html_css_page_import_permission( $apply_input );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			$result = self::apply_import( $apply_input );
			return self::decorate_facade_response( $result, true, $request_digest, $response_format );
		} finally {
			self::release_import_lock( $facade_lock );
		}
	}

	/**
	 * Checkout one canonical page or template area as a deterministic file map.
	 *
	 * Bricks remains authoritative. The returned files are a versioned working
	 * copy whose baseline is rechecked by preview and apply.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function checkout_page_workspace( $input ) {
		$post_id = Elements::get_resolved_post_id( $input );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::flush_page_workspace_authority_caches( $post_id );
		$area        = Elements::get_save_area_for_post( $post_id );
		$elements    = \Bricks\Database::get_data( $post_id, $area );
		$elements    = is_array( $elements ) ? $elements : [];
		$code_access = self::require_page_workspace_code_access( $elements );

		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		$digest   = Save_Pipeline::document_digest( $elements );
		$snapshot = Design::design_system_snapshot();

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$version  = (int) $snapshot['resources']['currentSite']['version'];
		$document = [
			'schemaVersion' => self::PAGE_WORKSPACE_SCHEMA_VERSION,
			'target'        => [
				'postId' => (int) $post_id,
				'area'   => $area,
			],
			'baseline'      => [
				'documentDigest'       => $digest,
				'designSystemVersion'  => $version,
				'designSystemSnapshot' => $snapshot,
			],
			'elements'      => $elements,
		];

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'schemaVersion'   => self::PAGE_WORKSPACE_SCHEMA_VERSION,
			'manifest'        => [
				'target'       => $document['target'],
				'baseline'     => $document['baseline'],
				'editableFile' => 'page.bricks.json',
				'files'        => [
					'page.bricks.json' => [
						'authority' => 'canonical',
						'sha256'    => self::data_digest( $document ),
					],
				],
				'dependencies' => self::page_dependency_index( $elements ),
			],
			'files'           => [
				'page.bricks.json' => $document,
			],
		];
	}

	/**
	 * Validate, render, diff, and freeze a canonical page workspace document.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function preview_page_workspace( $input ) {
		return self::preview_structured_page_workspace( $input );
	}

	/**
	 * Apply the exact canonical page candidate accepted by preview.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function apply_page_workspace( $input ) {
		$candidate = self::resolve_apply_candidate( $input );
		if ( is_wp_error( $candidate ) ) {
			return $candidate;
		}

		if ( ( $candidate['workflow'] ?? '' ) !== 'page-workspace' ) {
			return Error::conflict(
				'preview_workflow_mismatch',
				[
					'message' => 'This preview token was created by another workspace workflow.',
				]
			);
		}

		$code_access = self::require_page_workspace_candidate_code_access( $candidate );
		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		return self::apply_import( $input );
	}

	/**
	 * Apply an exact preview candidate.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function apply_html_css_page_import( $input ) {
		return self::apply_import( $input );
	}

	/**
	 * Compile, validate, render, and freeze an exact candidate.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function preview_import( array $input, string $preview_token = '', string $facade_request_digest = '' ) {
		if ( ! array_key_exists( 'expectedDesignSystemVersion', $input ) ) {
			return Error::missing_param( 'expectedDesignSystemVersion' );
		}

		if ( array_key_exists( 'replaceExisting', $input ) && ! is_bool( $input['replaceExisting'] ) ) {
			return Error::invalid_param( 'replaceExisting', 'a boolean', $input['replaceExisting'] );
		}

		if ( trim( (string) ( $input['html'] ?? '' ) ) === '' ) {
			return Error::missing_param( 'html' );
		}

		if ( isset( $input['options'] ) && ! is_array( $input['options'] ) ) {
			return Error::invalid_param( 'options', 'an object', $input['options'] );
		}

		$document_purpose = (string) ( $input['documentPurpose'] ?? 'page-content' );
		if ( ! in_array( $document_purpose, [ 'page-content', 'template-content', 'migration' ], true ) ) {
			return Error::invalid_param( 'documentPurpose', 'page-content, template-content, or migration', $document_purpose );
		}

		$source_document = self::analyze_source_document( (string) $input['html'] );
		if ( is_wp_error( $source_document ) ) {
			return $source_document;
		}

		if ( in_array( $document_purpose, [ 'page-content', 'template-content' ], true ) && in_array( 'main', $source_document['topLevelLandmarks'], true ) ) {
			return Error::conflict(
				'page_content_main_landmark',
				[
					'message'        => 'Bricks already renders page content inside its main landmark. Remove the top-level <main> wrapper and import its children instead.',
					'sourceDocument' => $source_document,
				]
			);
		}

		if ( $document_purpose === 'template-content' ) {
			$automatic_landmarks = array_values( array_intersect( [ 'header', 'footer' ], $source_document['topLevelLandmarks'] ) );
			if ( ! empty( $automatic_landmarks ) ) {
				return Error::conflict(
					'template_content_automatic_landmark',
					[
						'message'        => 'Bricks supplies the semantic landmark around header and footer templates. Remove the top-level header/footer wrapper and import its children.',
						'sourceDocument' => $source_document,
					]
				);
			}
		}

		$post_id = Elements::get_resolved_post_id( $input );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::flush_page_workspace_authority_caches( $post_id );
		$expected_version = (int) $input['expectedDesignSystemVersion'];
		$current_snapshot = Design::design_system_snapshot();

		if ( is_wp_error( $current_snapshot ) ) {
			return $current_snapshot;
		}

		$current_version = (int) $current_snapshot['resources']['currentSite']['version'];
		$snapshot_check  = self::validate_design_system_snapshot(
			$input['expectedDesignSystemSnapshot'] ?? null,
			$expected_version,
			$current_snapshot,
			'The design system changed before import preview. Re-read get-design-context and retry.'
		);

		if ( is_wp_error( $snapshot_check ) ) {
			return $snapshot_check;
		}

		$area              = Elements::get_save_area_for_post( $post_id );
		$meta_key          = \Bricks\Database::get_bricks_data_key( $area );
		$existing_elements = get_post_meta( $post_id, $meta_key, true );
		$meta_exists       = metadata_exists( 'post', $post_id, $meta_key );
		$existing_elements = is_array( $existing_elements ) ? $existing_elements : [];
		$code_access       = self::require_page_workspace_code_access( $existing_elements );

		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		$document_digest = Save_Pipeline::document_digest( $existing_elements );

		if (
			! empty( $input['expectedDocumentDigest'] ) &&
			! hash_equals( (string) $input['expectedDocumentDigest'], $document_digest )
		) {
			return Error::conflict(
				'document_digest_mismatch',
				[
					'message'                => 'The page changed before import preview. Re-read the page and retry.',
					'expectedDocumentDigest' => (string) $input['expectedDocumentDigest'],
					'actualDocumentDigest'   => $document_digest,
				]
			);
		}

		if ( ! empty( $existing_elements ) && ( $input['replaceExisting'] ?? false ) !== true ) {
			return Error::conflict(
				'full_page_ownership_required',
				[
					'message'        => 'This import replaces the complete current Bricks area. Set replaceExisting=true only when the supplied HTML/CSS is authoritative for the whole area.',
					'postId'         => $post_id,
					'elementCount'   => count( $existing_elements ),
					'documentDigest' => $document_digest,
				]
			);
		}

		$conversion_options     = is_array( $input['options'] ?? null ) ? $input['options'] : [];
		$custom_css_policy      = (string) ( $conversion_options['custom_css_policy'] ?? 'allow' );
		$global_resource_policy = (string) ( $conversion_options['global_resource_policy'] ?? 'allow' );

		if ( ! in_array( $custom_css_policy, [ 'allow', 'forbid' ], true ) ) {
			return Error::invalid_param( 'options.custom_css_policy', 'allow or forbid', $custom_css_policy );
		}

		if ( ! in_array( $global_resource_policy, [ 'allow', 'forbid_creates' ], true ) ) {
			return Error::invalid_param( 'options.global_resource_policy', 'allow or forbid_creates', $global_resource_policy );
		}

		unset( $conversion_options['custom_css_policy'], $conversion_options['global_resource_policy'] );

		$conversion_options['scope_global_css_to_roots'] = true;

		if ( ! array_key_exists( 'preserve_html_defaults', $conversion_options ) ) {
			$conversion_options['preserve_html_defaults'] = $document_purpose === 'migration';
		}

		$conversion = Conversion::convert_html_css_to_bricks_data(
			[
				'postId'  => $post_id,
				'html'    => (string) $input['html'],
				'css'     => (string) ( $input['css'] ?? '' ),
				'options' => $conversion_options,
			]
		);

		if ( is_wp_error( $conversion ) ) {
			return $conversion;
		}

		$custom_css_summary = self::custom_css_summary(
			is_array( $conversion['elements'] ?? null ) ? $conversion['elements'] : [],
			is_array( $conversion['global_classes'] ?? null ) ? $conversion['global_classes'] : []
		);

		if (
			$custom_css_policy === 'forbid' &&
			(
				$custom_css_summary['elementCount'] > 0 ||
				$custom_css_summary['globalClassCount'] > 0 ||
				$custom_css_summary['codeElementCount'] > 0
			)
		) {
			return Error::conflict(
				'custom_css_forbidden',
				[
					'message'                   => 'The candidate requires custom CSS or a Code element. Revise the source or allow custom CSS, then preview again.',
					'customCssElementCount'     => $custom_css_summary['elementCount'],
					'customCssGlobalClassCount' => $custom_css_summary['globalClassCount'],
					'customCssBytes'            => $custom_css_summary['bytes'],
					'codeElementCount'          => $custom_css_summary['codeElementCount'],
				]
			);
		}

		$partial_import = self::omit_restricted_import_elements(
			is_array( $conversion['elements'] ?? null ) ? $conversion['elements'] : []
		);
		if ( is_wp_error( $partial_import ) ) {
			return $partial_import;
		}

		if ( ! empty( $partial_import['omittedElements'] ) && ! empty( $existing_elements ) ) {
			return Error::conflict(
				'partial_import_replacement_forbidden',
				[
					'message'         => __( 'This import omits restricted source elements and cannot replace existing Bricks content. Import into an empty area or remove the restricted source before replacing this area.', 'bricks' ),
					'postId'          => $post_id,
					'elementCount'    => count( $existing_elements ),
					'omittedElements' => $partial_import['omittedElements'],
				]
			);
		}

		if ( ! empty( $partial_import['omittedElements'] ) && ! self::has_meaningful_import_content( $partial_import['elements'] ) ) {
			return Error::conflict(
				'partial_import_no_meaningful_content',
				[
					'message'         => __( 'The restricted source elements left no meaningful Bricks content to import. Revise the source or use an account permitted to author those elements.', 'bricks' ),
					'omittedElements' => $partial_import['omittedElements'],
				]
			);
		}

		$classes           = is_array( $conversion['global_classes'] ?? null ) ? $conversion['global_classes'] : [];
		$variables         = is_array( $conversion['global_variables'] ?? null ) ? $conversion['global_variables'] : [];
		$skipped_variables = is_array( $conversion['skipped_global_variables'] ?? null ) ? $conversion['skipped_global_variables'] : [];
		if ( ! empty( $partial_import['omittedElements'] ) ) {
			$resources         = self::retain_partial_import_resources( $partial_import['elements'], $classes, $variables, $skipped_variables );
			$classes           = $resources['classes'];
			$variables         = $resources['variables'];
			$skipped_variables = $resources['skippedVariables'];
		}
		$candidate_custom_css_summary = self::custom_css_summary( $partial_import['elements'], $classes );

		$normalized_classes = [];
		$class_snapshot     = Design::global_classes_snapshot();
		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		if ( ! empty( $classes ) ) {
			$class_preview = Design::batch_create_global_classes(
				[
					'classes'                   => $classes,
					'expectedOwnership'         => $class_snapshot['ownership'],
					'expectedCategoryOwnership' => $class_snapshot['categoryOwnership'],
					'dryRun'                    => true,
					'returnClasses'             => true,
				]
			);

			if ( is_wp_error( $class_preview ) ) {
				return $class_preview;
			}

			$normalized_classes = $class_preview['classes'] ?? [];
		}

		// Persistence before-images must remain byte-equivalent to the option.
		// Snapshot response rows also contain API-only ownership metadata.
		$existing_classes = $class_snapshot['storedClasses'];
		$class_timestamp  = get_option( BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP, null );
		$class_user       = get_option( BRICKS_DB_GLOBAL_CLASSES_USER, null );
		$existing_vars    = \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$existing_vars    = is_array( $existing_vars ) ? $existing_vars : [];
		$variable_plan    = self::plan_variables( $existing_vars, array_merge( $variables, $skipped_variables ) );

		if ( is_wp_error( $variable_plan ) ) {
			return $variable_plan;
		}

		if ( $global_resource_policy === 'forbid_creates' && ( ! empty( $normalized_classes ) || ! empty( $variable_plan['created'] ) ) ) {
			return Error::conflict(
				'global_resource_creates_forbidden',
				[
					'message'                     => 'The candidate would create global design resources. Revise the source or allow creates, then preview again.',
					'proposedGlobalClassCount'    => count( $normalized_classes ),
					'proposedGlobalVariableCount' => count( $variable_plan['created'] ),
				]
			);
		}

		if ( ( ! empty( $variables ) || ! empty( $skipped_variables ) ) && \Bricks\Database::get_setting( 'disableVariablesManager', false ) ) {
			return Error::conflict(
				'variables_manager_disabled',
				[
					'message' => 'The source relies on CSS custom properties, but the Bricks variables manager is disabled and would not load them on the frontend.',
				]
			);
		}

		if ( ! empty( $classes ) && is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES ) {
			return Error::conflict(
				'multisite_class_transaction_unsupported',
				[
					'message' => 'Transactional class imports are not yet supported when global classes use main-site multisite storage.',
				]
			);
		}

		if ( ! empty( $variables ) && \Bricks\Helpers::use_main_site_global_variables() ) {
			return Error::conflict(
				'multisite_variable_transaction_unsupported',
				[
					'message' => 'Transactional variable imports are not yet supported when global variables use main-site multisite storage.',
				]
			);
		}

		$design_permission = self::check_design_permissions( $classes, $variables );
		if ( is_wp_error( $design_permission ) ) {
			return $design_permission;
		}

		// Match Builder HTML import normalization before strict setting validation.
		foreach ( $partial_import['elements'] as &$element ) {
			if ( $element['name'] === 'image' && ! empty( $element['settings']['_importImage'] ) ) {
				$url                          = $element['settings']['_importImage']['url'];
				$url_parts                    = explode( '/', explode( '#', explode( '?', $url )[0] )[0] );
				$element['settings']['image'] = [
					'url'      => $url,
					'external' => true,
					'filename' => end( $url_parts ),
				];
				unset( $element['settings']['_importImage'] );
			}
		}
		unset( $element );

		$element_preview = Save_Pipeline::dry_run( $post_id, $partial_import['elements'], $area );
		if ( is_wp_error( $element_preview ) ) {
			return $element_preview;
		}

		$permission_check = Elements::check_full_tree_permissions( $existing_elements, $element_preview['elements'] );
		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		$planned_classes = array_merge( $existing_classes, $normalized_classes );
		$planned_vars    = $variable_plan['variables'];
		$render          = self::render_candidate(
			$post_id,
			$area,
			$element_preview['elements'],
			$planned_classes,
			$planned_vars
		);

		if ( is_wp_error( $render ) ) {
			return $render;
		}

		$expires_at          = time() + self::PREVIEW_TTL;
		$source_warnings     = self::source_document_warnings( $source_document, $document_purpose );
		$conversion_warnings = array_values(
			array_filter(
				(array) ( $conversion['warnings'] ?? [] ),
				static function ( $warning ) {
					return ! is_array( $warning ) || ( $warning['code'] ?? '' ) !== 'bricks_code_sensitive_write_forbidden';
				}
			)
		);
		if ( ! empty( $partial_import['omittedElements'] ) ) {
			$conversion_warnings[] = [
				'code'    => 'partial_import_omitted_restricted_elements',
				// translators: %d: Number of omitted elements.
				'message' => sprintf( __( '%d restricted element(s), including descendants, were omitted because the current user cannot author their code-sensitive payloads.', 'bricks' ), count( $partial_import['omittedElements'] ) ),
			];
		}
		$candidate = [
			'workflow'                      => 'html-css-import',
			'placement'                     => 'replace',
			'contractVersion'               => self::CONTRACT_VERSION,
			'userId'                        => get_current_user_id(),
			'postId'                        => (int) $post_id,
			'area'                          => $area,
			'metaKey'                       => $meta_key,
			'expiresAt'                     => $expires_at,
			'designSystemVersionBefore'     => $current_version,
			'designSystemSnapshotBefore'    => $current_snapshot,
			'documentDigestBefore'          => $document_digest,
			'metaExistedBefore'             => $meta_exists,
			'elementsBefore'                => $existing_elements,
			'elements'                      => $element_preview['elements'],
			'documentDigestAfter'           => $element_preview['documentDigest'],
			'classesBefore'                 => $existing_classes,
			'classesOptionExistedBefore'    => get_option( BRICKS_DB_GLOBAL_CLASSES, null ) !== null,
			'classesTimestampBefore'        => $class_timestamp,
			'classesTimestampExistedBefore' => $class_timestamp !== null,
			'classesUserBefore'             => $class_user,
			'classesUserExistedBefore'      => $class_user !== null,
			'classesToCreate'               => $normalized_classes,
			'classesAfter'                  => $planned_classes,
			'variablesBefore'               => $existing_vars,
			'variablesOptionExistedBefore'  => \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, null ) !== null,
			'variablesToCreate'             => $variable_plan['created'],
			'variablesAfter'                => $planned_vars,
			'requiresVariablesManager'      => ! empty( $variables ) || ! empty( $skipped_variables ),
			'warnings'                      => array_values( array_merge( $conversion_warnings, $source_warnings ) ),
			'errors'                        => array_values( (array) ( $conversion['errors'] ?? [] ) ),
			'remNormalization'              => $conversion['rem_normalization'] ?? [],
			'customCssPolicy'               => $custom_css_policy,
			'globalResourcePolicy'          => $global_resource_policy,
			'customCssElementCount'         => $candidate_custom_css_summary['elementCount'],
			'customCssGlobalClassCount'     => $candidate_custom_css_summary['globalClassCount'],
			'customCssBytes'                => $candidate_custom_css_summary['bytes'],
			'codeElementCount'              => $candidate_custom_css_summary['codeElementCount'],
			'documentPurpose'               => $document_purpose,
			'sourceDocument'                => $source_document,
			'partial'                       => ! empty( $partial_import['omittedElements'] ),
			'omittedElements'               => $partial_import['omittedElements'],
			'render'                        => $render,
		];
		if ( $facade_request_digest !== '' ) {
			$candidate['facadeRequestDigest'] = $facade_request_digest;
		}
		$candidate['candidateDigest'] = self::candidate_digest( $candidate );

		$preview_token = $preview_token !== '' ? $preview_token : wp_generate_password( 40, false, false );
		if ( ! set_transient( self::preview_key( $preview_token ), $candidate, self::PREVIEW_TTL ) ) {
			return Error::internal_error(
				'html-css-page-import',
				'The normalized import preview could not be stored. No data was changed.'
			);
		}

		return self::preview_response( $candidate, $preview_token );
	}

	/**
	 * Freeze a lossless page-workspace document without changing Bricks data.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function preview_structured_page_workspace( array $input ) {
		$document = $input['document'] ?? null;
		if ( ! is_array( $document ) ) {
			return Error::missing_param( 'document' );
		}

		if ( (int) ( $document['schemaVersion'] ?? 0 ) !== self::PAGE_WORKSPACE_SCHEMA_VERSION ) {
			return Error::conflict(
				'page_workspace_schema_mismatch',
				[
					'message'               => 'The checked-out page file uses an unsupported schema. Check out a fresh workspace.',
					'expectedSchemaVersion' => self::PAGE_WORKSPACE_SCHEMA_VERSION,
					'actualSchemaVersion'   => (int) ( $document['schemaVersion'] ?? 0 ),
				]
			);
		}

		$post_id = (int) ( $document['target']['postId'] ?? 0 );
		if ( $post_id < 1 ) {
			return Error::invalid_param( 'document.target.postId', 'a positive post ID', $post_id );
		}

		self::flush_page_workspace_authority_caches( $post_id );
		$area = Elements::get_save_area_for_post( $post_id );
		if ( (string) ( $document['target']['area'] ?? '' ) !== $area ) {
			return Error::conflict(
				'page_area_changed',
				[
					'message'      => 'The checked-out file targets a different Bricks area. Check out the current page again.',
					'expectedArea' => (string) ( $document['target']['area'] ?? '' ),
					'actualArea'   => $area,
				]
			);
		}

		$expected_version = (int) ( $document['baseline']['designSystemVersion'] ?? -1 );
		$current_snapshot = Design::design_system_snapshot();

		if ( is_wp_error( $current_snapshot ) ) {
			return $current_snapshot;
		}

		$current_version = (int) $current_snapshot['resources']['currentSite']['version'];
		$snapshot_check  = self::validate_design_system_snapshot(
			$document['baseline']['designSystemSnapshot'] ?? null,
			$expected_version,
			$current_snapshot,
			'The design system changed after checkout. Check out the workspace again.'
		);

		if ( is_wp_error( $snapshot_check ) ) {
			return $snapshot_check;
		}

		$meta_key          = \Bricks\Database::get_bricks_data_key( $area );
		$existing_elements = get_post_meta( $post_id, $meta_key, true );
		$meta_exists       = metadata_exists( 'post', $post_id, $meta_key );
		$existing_elements = is_array( $existing_elements ) ? $existing_elements : [];
		$document_digest   = Save_Pipeline::document_digest( $existing_elements );
		$expected_digest   = (string) ( $document['baseline']['documentDigest'] ?? '' );

		if ( $expected_digest === '' || ! hash_equals( $expected_digest, $document_digest ) ) {
			return Error::conflict(
				'document_digest_mismatch',
				[
					'message'                => 'The page changed after checkout. Check out the workspace again.',
					'expectedDocumentDigest' => $expected_digest,
					'actualDocumentDigest'   => $document_digest,
				]
			);
		}

		$submitted_elements = $document['elements'] ?? null;
		if ( ! is_array( $submitted_elements ) ) {
			return Error::invalid_param( 'document.elements', 'an array of canonical Bricks elements', $submitted_elements );
		}

		$code_access = self::require_page_workspace_code_access( $submitted_elements );
		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		$classes = self::read_global_classes();
		$vars    = \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$vars    = is_array( $vars ) ? $vars : [];

		if ( Save_Pipeline::document_digest( $submitted_elements ) === $document_digest ) {
			$render = self::render_candidate( $post_id, $area, $existing_elements, $classes, $vars );
			if ( is_wp_error( $render ) ) {
				return $render;
			}

			return self::page_workspace_noop_response(
				$post_id,
				$document_digest,
				$current_version,
				$current_snapshot,
				$render,
				count( $existing_elements )
			);
		}

		$preview = Save_Pipeline::dry_run( $post_id, $submitted_elements, $area, [], true, [ 'workspaceBaselineElements' => $existing_elements ] );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$permission = Elements::check_full_tree_permissions( $existing_elements, $preview['elements'] );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		// Compare the final signed form with attachment preview URLs enriched.
		// These derived values do not change the requested image source; all other
		// normalization must still pass the canonical document guard.
		$canonical_submitted_elements = Elements::sign_authorized_code( $existing_elements, $submitted_elements );
		$canonical_submitted_elements = array_map( [ Element_Normalizer::class, 'normalize_image_settings' ], $canonical_submitted_elements );
		$submitted_digest             = Save_Pipeline::document_digest( $canonical_submitted_elements );
		if ( ! hash_equals( $submitted_digest, $preview['documentDigest'] ) ) {
			$normalization_diff = self::element_diff_summary( $canonical_submitted_elements, $preview['elements'] );
			return Error::conflict(
				'page_workspace_not_canonical',
				[
					'message'                  => __( 'Validation would normalize the submitted data. elementDiff and normalizationElementDiff describe validation changes; requestedElementDiff describes your edits. No preview was created; use focused abilities or submit canonical data.', 'bricks' ),
					'elementDiff'              => $normalization_diff,
					'normalizationElementDiff' => $normalization_diff,
					'requestedElementDiff'     => self::element_diff_summary( $existing_elements, $submitted_elements ),
				]
			);
		}

		$render = self::render_candidate( $post_id, $area, $preview['elements'], $classes, $vars );
		if ( is_wp_error( $render ) ) {
			return $render;
		}

		$expires_at                   = time() + self::PREVIEW_TTL;
		$candidate                    = [
			'workflow'                      => 'page-workspace',
			'placement'                     => 'structured-replace',
			'contractVersion'               => self::CONTRACT_VERSION,
			'userId'                        => get_current_user_id(),
			'postId'                        => $post_id,
			'area'                          => $area,
			'metaKey'                       => $meta_key,
			'expiresAt'                     => $expires_at,
			'designSystemVersionBefore'     => $current_version,
			'designSystemSnapshotBefore'    => $current_snapshot,
			'documentDigestBefore'          => $document_digest,
			'metaExistedBefore'             => $meta_exists,
			'elementsBefore'                => $existing_elements,
			'elements'                      => $preview['elements'],
			'documentDigestAfter'           => $preview['documentDigest'],
			'classesBefore'                 => $classes,
			'classesOptionExistedBefore'    => get_option( BRICKS_DB_GLOBAL_CLASSES, null ) !== null,
			'classesTimestampBefore'        => null,
			'classesTimestampExistedBefore' => false,
			'classesUserBefore'             => null,
			'classesUserExistedBefore'      => false,
			'classesToCreate'               => [],
			'classesAfter'                  => $classes,
			'variablesBefore'               => $vars,
			'variablesOptionExistedBefore'  => \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, null ) !== null,
			'variablesToCreate'             => [],
			'variablesAfter'                => $vars,
			'requiresVariablesManager'      => false,
			'warnings'                      => [],
			'errors'                        => [],
			'remNormalization'              => [],
			'elementDiff'                   => self::element_diff_summary( $existing_elements, $preview['elements'] ),
			'render'                        => $render,
		];
		$candidate['candidateDigest'] = self::candidate_digest( $candidate );

		$preview_token = wp_generate_password( 40, false, false );
		if ( ! set_transient( self::preview_key( $preview_token ), $candidate, self::PREVIEW_TTL ) ) {
			return Error::internal_error(
				'page-workspace',
				'The normalized page preview could not be stored. No data was changed.'
			);
		}

		return self::preview_response( $candidate, $preview_token );
	}

	/**
	 * Apply an exact preview candidate.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function apply_import( array $input ) {
		if ( array_key_exists( 'acknowledgeWarnings', $input ) && ! is_bool( $input['acknowledgeWarnings'] ) ) {
			return Error::invalid_param( 'acknowledgeWarnings', 'a boolean', $input['acknowledgeWarnings'] );
		}

		$preview_token   = (string) ( $input['previewToken'] ?? '' );
		$idempotency_key = trim( (string) ( $input['idempotencyKey'] ?? '' ) );

		if ( $preview_token === '' ) {
			return Error::missing_param( 'previewToken' );
		}

		if ( $idempotency_key === '' ) {
			return Error::missing_param( 'idempotencyKey' );
		}

		if ( strlen( $idempotency_key ) > 128 ) {
			return Error::invalid_param( 'idempotencyKey', 'a string no longer than 128 characters', $idempotency_key );
		}

		$journal_key  = self::journal_key( get_current_user_id(), $idempotency_key );
		$journal_lock = self::acquire_import_lock( $journal_key );

		if ( is_wp_error( $journal_lock ) ) {
			return $journal_lock;
		}

		try {
			$candidate = self::resolve_apply_candidate( $input );
			if ( is_wp_error( $candidate ) ) {
				return $candidate;
			}

			$post_id     = (int) $candidate['postId'];
			$design_lock = '';

			if ( self::candidate_touches_design_system( $candidate ) ) {
				$design_lock = self::acquire_import_lock( 'design-system:' . get_current_blog_id() );
				if ( is_wp_error( $design_lock ) ) {
					return $design_lock;
				}
			}

			try {
				$post_lock = self::acquire_import_lock( 'post:' . get_current_blog_id() . ':' . $post_id );
				if ( is_wp_error( $post_lock ) ) {
					return $post_lock;
				}

				try {
					return self::apply_import_locked( $input, $post_id );
				} finally {
					self::release_import_lock( $post_lock );
				}
			} finally {
				if ( $design_lock !== '' ) {
					self::release_import_lock( $design_lock );
				}
			}
		} finally {
			self::release_import_lock( $journal_lock );
		}
	}

	/**
	 * Resolve the candidate authority for a token-only apply or retry.
	 *
	 * A durable journal wins after the preview transient has been consumed.
	 * The request fingerprint still binds that journal to the exact token and
	 * target, so an idempotency key cannot be reused to authorize another post.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function resolve_apply_candidate( array $input ) {
		$preview_token   = (string) ( $input['previewToken'] ?? '' );
		$idempotency_key = trim( (string) ( $input['idempotencyKey'] ?? '' ) );

		if ( $preview_token === '' ) {
			return Error::missing_param( 'previewToken' );
		}

		if ( $idempotency_key === '' ) {
			return Error::missing_param( 'idempotencyKey' );
		}

		if ( strlen( $idempotency_key ) > 128 ) {
			return Error::invalid_param( 'idempotencyKey', 'a string no longer than 128 characters', $idempotency_key );
		}

		$journal = get_option( self::journal_key( get_current_user_id(), $idempotency_key ), null );
		if ( is_array( $journal ) ) {
			$candidate = $journal['candidate'] ?? null;

			if ( ! is_array( $candidate ) ) {
				$candidate = [
					'postId'   => (int) ( $journal['postId'] ?? 0 ),
					'userId'   => (int) ( $journal['userId'] ?? 0 ),
					'workflow' => (string) ( $journal['workflow'] ?? $journal['result']['workflow'] ?? '' ),
				];
			}

			if ( empty( $candidate['postId'] ) ) {
				return Error::internal_error( 'html-css-page-import', 'The import journal is missing its target authority.' );
			}

			$fingerprint = self::data_digest(
				[
					'postId'           => (int) $candidate['postId'],
					'previewTokenHash' => hash( 'sha256', $preview_token ),
				]
			);

			if ( ! hash_equals( (string) ( $journal['requestFingerprint'] ?? '' ), $fingerprint ) ) {
				return Error::conflict(
					'import_idempotency_key_reused',
					[
						'message'   => 'This idempotency key already belongs to a different preview or target. Reuse the original request, or choose a new key for a different import.',
						'journalId' => self::journal_key( get_current_user_id(), $idempotency_key ),
					]
				);
			}
		} else {
			$candidate = get_transient( self::preview_key( $preview_token ) );
		}

		if ( ! is_array( $candidate ) ) {
			return Error::conflict(
				'preview_expired',
				[
					'message' => 'The import preview expired or is invalid. Preview the source again.',
				]
			);
		}

		if ( (int) ( $candidate['userId'] ?? 0 ) !== get_current_user_id() ) {
			return Error::conflict(
				'preview_scope_mismatch',
				[
					'message' => 'The preview token is not valid for this user.',
				]
			);
		}

		return $candidate;
	}

	/**
	 * Whether a candidate mutates a shared design-system authority.
	 *
	 * @param array $candidate Candidate.
	 * @return bool
	 */
	private static function candidate_touches_design_system( array $candidate ): bool {
		return ! empty( $candidate['classesToCreate'] ) || ! empty( $candidate['variablesToCreate'] );
	}

	/**
	 * Apply while holding the idempotency journal's database-session lock.
	 *
	 * @param array $input   Ability input.
	 * @param int   $post_id Target post frozen into the candidate.
	 * @return array|\WP_Error
	 */
	private static function apply_import_locked( array $input, int $post_id ) {
		$preview_token   = (string) ( $input['previewToken'] ?? '' );
		$idempotency_key = trim( (string) ( $input['idempotencyKey'] ?? '' ) );

		if ( $preview_token === '' ) {
			return Error::missing_param( 'previewToken' );
		}

		if ( $idempotency_key === '' ) {
			return Error::missing_param( 'idempotencyKey' );
		}

		$request_fingerprint = self::data_digest(
			[
				'postId'           => $post_id,
				'previewTokenHash' => hash( 'sha256', $preview_token ),
			]
		);
		$journal_key         = self::journal_key( get_current_user_id(), $idempotency_key );
		$journal             = get_option( $journal_key, null );

		if (
			is_array( $journal ) &&
			! hash_equals( (string) ( $journal['requestFingerprint'] ?? '' ), $request_fingerprint )
		) {
			return Error::conflict(
				'import_idempotency_key_reused',
				[
					'message'   => 'This idempotency key already belongs to a different preview or target. Reuse the original request, or choose a new key for a different import.',
					'journalId' => $journal_key,
				]
			);
		}

		if ( is_array( $journal ) && ( $journal['state'] ?? '' ) === 'committed' ) {
			return $journal['result'];
		}

		if ( is_array( $journal ) && ( $journal['state'] ?? '' ) === 'manual_recovery_required' ) {
			return Error::conflict(
				'import_manual_recovery_required',
				[
					'message'          => 'This idempotency key belongs to an interrupted import that requires manual recovery. Resolve its journal before attempting another write.',
					'transactionState' => 'manual_recovery_required',
					'journalId'        => $journal_key,
					'resources'        => $journal['manualRecoveryResources'] ?? [],
				]
			);
		}

		if ( is_array( $journal ) && ( $journal['state'] ?? '' ) === 'rolled_back' ) {
			return Error::conflict(
				'import_idempotency_key_consumed',
				[
					'message'          => 'This idempotency key already identifies a rolled-back import. Create a fresh preview and use a new idempotency key.',
					'transactionState' => 'rolled_back',
					'journalId'        => $journal_key,
				]
			);
		}

		if ( is_array( $journal ) ) {
			$recovery_candidate = $journal['candidate'] ?? [];
			if ( ! is_array( $recovery_candidate ) || empty( $recovery_candidate ) ) {
				return Error::internal_error(
					'html-css-page-import',
					'The interrupted import journal is missing its recovery candidate.',
					[
						'transactionState' => 'manual_recovery_required',
						'journalId'        => $journal_key,
					]
				);
			}

			$code_access = self::require_page_workspace_candidate_code_access( $recovery_candidate );
			if ( is_wp_error( $code_access ) ) {
				return $code_access;
			}

			$page_permission = Elements::write_elements_permission(
				[
					'postId' => $recovery_candidate['postId'],
				]
			);
			if ( is_wp_error( $page_permission ) ) {
				return $page_permission;
			}

			$design_permission = self::check_design_permissions(
				$recovery_candidate['classesToCreate'] ?? [],
				$recovery_candidate['variablesToCreate'] ?? []
			);
			if ( is_wp_error( $design_permission ) ) {
				return $design_permission;
			}

			$recovery = self::compensate( $journal_key, $journal );

			if ( is_wp_error( $recovery ) ) {
				return $recovery;
			}

			return Error::conflict(
				'previous_import_recovered',
				[
					'message'          => 'A prior interrupted attempt using this idempotency key was rolled back. Create a fresh preview because the design-system version advanced during recovery.',
					'transactionState' => 'rolled_back',
					'journalId'        => $journal_key,
				]
			);
		}

		$candidate = get_transient( self::preview_key( $preview_token ) );

		if ( ! is_array( $candidate ) ) {
			return Error::conflict(
				'preview_expired',
				[
					'message' => 'The import preview expired or is invalid. Preview the source again.',
				]
			);
		}

		if (
			(int) ( $candidate['userId'] ?? 0 ) !== get_current_user_id() ||
			(int) ( $candidate['postId'] ?? 0 ) !== $post_id
		) {
			return Error::conflict(
				'preview_scope_mismatch',
				[
					'message' => 'The preview token is not valid for this user and target post.',
				]
			);
		}

		$code_access = self::require_page_workspace_candidate_code_access( $candidate );
		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		$stored_candidate_digest = (string) ( $candidate['candidateDigest'] ?? '' );
		if (
			$stored_candidate_digest === '' ||
			! hash_equals( $stored_candidate_digest, self::candidate_digest( $candidate ) )
		) {
			return Error::conflict(
				'preview_candidate_digest_mismatch',
				[
					'message' => 'The stored import preview no longer matches its normalized candidate digest. No data was committed; create a fresh preview.',
				]
			);
		}

		if ( self::warnings_require_acknowledgement( $candidate['warnings'] ?? [] ) && ( $input['acknowledgeWarnings'] ?? false ) !== true ) {
			return Error::conflict(
				'import_warnings_not_acknowledged',
				[
					'message'  => 'Preview returned conversion warnings. Review them and apply with acknowledgeWarnings=true.',
					'warnings' => $candidate['warnings'],
				]
			);
		}

		if ( ! empty( $candidate['requiresVariablesManager'] ) && \Bricks\Database::get_setting( 'disableVariablesManager', false ) ) {
			return Error::conflict(
				'variables_manager_disabled',
				[
					'message' => 'The Bricks variables manager was disabled after preview. No import data was committed.',
				]
			);
		}

		$design_permission = self::check_design_permissions(
			$candidate['classesToCreate'],
			$candidate['variablesToCreate']
		);
		if ( is_wp_error( $design_permission ) ) {
			return $design_permission;
		}

		self::flush_page_workspace_authority_caches( $post_id );
		$current_snapshot = Design::design_system_snapshot();

		if ( is_wp_error( $current_snapshot ) ) {
			return $current_snapshot;
		}

		$current_version = (int) $current_snapshot['resources']['currentSite']['version'];
		$snapshot_check  = self::validate_design_system_snapshot(
			$candidate['designSystemSnapshotBefore'] ?? null,
			(int) $candidate['designSystemVersionBefore'],
			$current_snapshot,
			'The design system changed after preview. No import data was committed.'
		);

		if ( is_wp_error( $snapshot_check ) ) {
			return $snapshot_check;
		}

		$current_elements = get_post_meta( $candidate['postId'], $candidate['metaKey'], true );
		$current_elements = is_array( $current_elements ) ? $current_elements : [];
		$code_access      = self::require_page_workspace_code_access( $current_elements );

		if ( is_wp_error( $code_access ) ) {
			return $code_access;
		}

		if ( Save_Pipeline::document_digest( $current_elements ) !== $candidate['documentDigestBefore'] ) {
			return Error::conflict(
				'document_digest_mismatch',
				[
					'message'                => 'The page changed after preview. No import data was committed.',
					'expectedDocumentDigest' => $candidate['documentDigestBefore'],
					'actualDocumentDigest'   => Save_Pipeline::document_digest( $current_elements ),
				]
			);
		}

		$current_area = Elements::get_save_area_for_post( (int) $candidate['postId'] );
		if ( $current_area !== $candidate['area'] ) {
			return Error::conflict(
				'page_area_changed',
				[
					'message'      => 'The target post changed Bricks area after preview. No import data was committed; re-read and preview the current area.',
					'expectedArea' => $candidate['area'],
					'actualArea'   => $current_area,
				]
			);
		}

		$journal = [
			'id'                 => $journal_key,
			'state'              => 'prepared',
			'createdAt'          => time(),
			'requestFingerprint' => $request_fingerprint,
			'candidate'          => $candidate,
		];
		if ( ! add_option( $journal_key, $journal, '', 'no' ) ) {
			return Error::conflict(
				'import_idempotency_race',
				[
					'message'   => 'Another request claimed this idempotency key. Retry with the same key to read its result, or use a new key for a different operation.',
					'journalId' => $journal_key,
				]
			);
		}
		$scheduled = wp_schedule_single_event( time() + DAY_IN_SECONDS, 'bricks_cleanup_agent_import_journal', [ $journal_key ], true );
		if ( is_wp_error( $scheduled ) || $scheduled === false ) {
			delete_option( $journal_key );

			return Error::internal_error(
				'html-css-page-import',
				'The recovery event could not be scheduled, so the import stopped before changing page or design data.'
			);
		}
		delete_transient( self::preview_key( $preview_token ) );

		// Commit the page first. This keeps new design resources private until
		// the most failure-prone authority has passed its permission, lock,
		// digest, persistence, and journal checks.
		$page_permission = Elements::write_elements_permission(
			[
				'postId' => $candidate['postId'],
			]
		);

		if ( is_wp_error( $page_permission ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $page_permission );
		}

		$post_lock = Elements::acquire_post_lock( (int) $candidate['postId'] );
		if ( is_wp_error( $post_lock ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $post_lock );
		}

		$current_area = Elements::get_save_area_for_post( (int) $candidate['postId'] );
		if ( $current_area !== $candidate['area'] ) {
			return self::fail_and_compensate(
				$journal_key,
				$journal,
				Error::conflict(
					'page_area_changed',
					[
						'message'      => 'The target post changed Bricks area while the import was being prepared.',
						'expectedArea' => $candidate['area'],
						'actualArea'   => $current_area,
					]
				)
			);
		}

		$current_elements = get_post_meta( $candidate['postId'], $candidate['metaKey'], true );
		$current_elements = is_array( $current_elements ) ? $current_elements : [];
		$tree_permission  = Elements::check_full_tree_permissions( $current_elements, $candidate['elements'] );

		if ( is_wp_error( $tree_permission ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $tree_permission );
		}

			$page_result = Save_Pipeline::execute(
				$candidate['postId'],
				$candidate['elements'],
				$candidate['area'],
				[],
				$candidate['documentDigestBefore'],
				true,
				( $candidate['workflow'] ?? '' ) === 'page-workspace' ? [ 'workspaceBaselineElements' => $candidate['elementsBefore'] ] : []
			);

		if ( is_wp_error( $page_result ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $page_result );
		}

		$journal['state'] = 'page_committed';
		if ( ! self::save_journal( $journal_key, $journal ) ) {
			return self::fail_and_compensate(
				$journal_key,
				$journal,
				Error::internal_error( 'html-css-page-import', 'The page commit could not be recorded in the recovery journal.' )
			);
		}

		if ( ! empty( $candidate['classesToCreate'] ) ) {
			$class_result = self::compare_and_swap_option(
				BRICKS_DB_GLOBAL_CLASSES,
				$candidate['classesBefore'],
				$candidate['classesAfter'],
				(int) $candidate['designSystemVersionBefore']
			);

			if ( is_wp_error( $class_result ) ) {
				return self::fail_and_compensate( $journal_key, $journal, $class_result );
			}

			$journal['state'] = 'classes_committed';
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return self::fail_and_compensate(
					$journal_key,
					$journal,
					Error::internal_error( 'html-css-page-import', 'The class commit could not be recorded in the recovery journal.' )
				);
			}
		}

		$version_after_classes  = Design::design_system_version();
		$expected_after_classes = (int) $candidate['designSystemVersionBefore'] + ( empty( $candidate['classesToCreate'] ) ? 0 : 1 );

		if ( $version_after_classes !== $expected_after_classes ) {
			$error = Error::conflict(
				'design_system_write_conflict',
				[
					'message'                     => 'The design system changed while import assets were being committed.',
					'expectedDesignSystemVersion' => $expected_after_classes,
					'actualDesignSystemVersion'   => $version_after_classes,
				]
			);
			return self::fail_and_compensate( $journal_key, $journal, $error );
		}

		if ( ! empty( $candidate['variablesToCreate'] ) ) {
			$variable_result = self::compare_and_swap_option(
				BRICKS_DB_GLOBAL_VARIABLES,
				$candidate['variablesBefore'],
				$candidate['variablesAfter'],
				$version_after_classes
			);

			if ( is_wp_error( $variable_result ) ) {
				return self::fail_and_compensate( $journal_key, $journal, $variable_result );
			}

			$journal['state'] = 'variables_committed';
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return self::fail_and_compensate(
					$journal_key,
					$journal,
					Error::internal_error( 'html-css-page-import', 'The variable commit could not be recorded in the recovery journal.' )
				);
			}
		}

		$expected_after_design = (int) $candidate['designSystemVersionBefore']
			+ ( empty( $candidate['classesToCreate'] ) ? 0 : 1 )
			+ ( empty( $candidate['variablesToCreate'] ) ? 0 : 1 );
		$version_after_design  = Design::design_system_version();

		if ( $version_after_design !== $expected_after_design ) {
			return self::fail_and_compensate(
				$journal_key,
				$journal,
				Error::conflict(
					'design_system_write_conflict',
					[
						'message'                     => 'The design system changed while import assets were being committed.',
						'expectedDesignSystemVersion' => $expected_after_design,
						'actualDesignSystemVersion'   => $version_after_design,
					]
				)
			);
		}

		$snapshot_after_design = Design::design_system_snapshot();
		if ( is_wp_error( $snapshot_after_design ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $snapshot_after_design );
		}

		$snapshot_after_check = self::validate_design_system_snapshot(
			$candidate['designSystemSnapshotBefore'] ?? null,
			(int) $candidate['designSystemVersionBefore'],
			$snapshot_after_design,
			'A design-system authority changed while the import was being committed.',
			$expected_after_design - (int) $candidate['designSystemVersionBefore']
		);

		if ( is_wp_error( $snapshot_after_check ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $snapshot_after_check );
		}

		$verification = self::verify_committed_candidate( $candidate );
		if ( is_wp_error( $verification ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $verification );
		}

		if ( ! empty( $candidate['classesToCreate'] ) ) {
			$class_timestamp = time();
			$class_user      = get_current_user_id();

			$journal['state']               = 'class_metadata_prepared';
			$journal['classTimestampAfter'] = $class_timestamp;
			$journal['classUserAfter']      = $class_user;
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return self::fail_and_compensate(
					$journal_key,
					$journal,
					Error::internal_error( 'html-css-page-import', 'The class metadata intent could not be recorded in the recovery journal.' )
				);
			}

			if (
				! self::compare_and_swap_scalar_option(
					BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP,
					! empty( $candidate['classesTimestampExistedBefore'] ),
					$candidate['classesTimestampBefore'] ?? null,
					$class_timestamp,
					$expected_after_design
				) ||
				! self::compare_and_swap_scalar_option(
					BRICKS_DB_GLOBAL_CLASSES_USER,
					! empty( $candidate['classesUserExistedBefore'] ),
					$candidate['classesUserBefore'] ?? null,
					$class_user,
					$expected_after_design
				)
			) {
				return self::fail_and_compensate(
					$journal_key,
					$journal,
					Error::internal_error( 'html-css-page-import', 'Global class change metadata could not be persisted.' )
				);
			}

			$journal['state'] = 'class_metadata_committed';
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return self::fail_and_compensate(
					$journal_key,
					$journal,
					Error::internal_error( 'html-css-page-import', 'The class metadata commit could not be recorded in the recovery journal.' )
				);
			}
		}

		$final_design_snapshot = Design::design_system_snapshot();
		if ( is_wp_error( $final_design_snapshot ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $final_design_snapshot );
		}

		$final_snapshot_check = self::validate_design_system_snapshot(
			$candidate['designSystemSnapshotBefore'] ?? null,
			(int) $candidate['designSystemVersionBefore'],
			$final_design_snapshot,
			'A design-system authority changed before the import could be finalized.',
			$expected_after_design - (int) $candidate['designSystemVersionBefore']
		);

		if ( is_wp_error( $final_snapshot_check ) ) {
			return self::fail_and_compensate( $journal_key, $journal, $final_snapshot_check );
		}

		$result = [
			'action'                     => 'apply',
			'workflow'                   => (string) ( $candidate['workflow'] ?? 'html-css-import' ),
			'contractVersion'            => self::CONTRACT_VERSION,
			'postId'                     => (int) $candidate['postId'],
			'previewToken'               => null,
			'expiresAt'                  => null,
			'candidateDigest'            => $candidate['candidateDigest'],
			'expectedDocumentDigest'     => $candidate['documentDigestBefore'],
			'savedDocumentDigest'        => $candidate['documentDigestAfter'],
			'designSystemVersionBefore'  => (int) $candidate['designSystemVersionBefore'],
			'designSystemVersion'        => (int) $final_design_snapshot['resources']['currentSite']['version'],
			'designSystemSnapshotBefore' => $candidate['designSystemSnapshotBefore'] ?? $current_snapshot,
			'designSystemSnapshot'       => $final_design_snapshot,
			'plan'                       => self::plan_summary( $candidate ),
			'partial'                    => ! empty( $candidate['partial'] ),
			'omittedElements'            => $candidate['omittedElements'] ?? [],
			'diagnostics'                => self::diagnostics_summary( $candidate ),
			'render'                     => $candidate['render'],
			'committed'                  => true,
			'transactionState'           => 'committed',
			'revisionId'                 => $page_result['revisionId'] ?? null,
			'journalId'                  => $journal_key,
		];

		$terminal_journal = [
			'id'                 => $journal_key,
			'state'              => 'committed',
			'createdAt'          => $journal['createdAt'],
			'committedAt'        => time(),
			'postId'             => (int) $candidate['postId'],
			'userId'             => (int) $candidate['userId'],
			'requestFingerprint' => $request_fingerprint,
			'result'             => $result,
		];
		if ( ! self::save_journal( $journal_key, $terminal_journal ) ) {
			return self::fail_and_compensate(
				$journal_key,
				$journal,
				Error::internal_error( 'html-css-page-import', 'The committed import could not be finalized in its idempotency journal.' )
			);
		}
		return $result;
	}

	/**
	 * Invalidate long-lived workspace authority caches before baseline reads.
	 *
	 * Manager owns the broad post and option invalidation contracts. The design
	 * version is a non-autoloaded option, so it also needs its named cache entry
	 * evicted before stale-workspace decisions.
	 *
	 * @param int $post_id Target post ID.
	 * @return void
	 */
	private static function flush_page_workspace_authority_caches( int $post_id ): void {
		Manager::flush_post_cache( $post_id );
		Manager::flush_options_cache();

		$option_names = [
			Design::DESIGN_SYSTEM_VERSION_OPTION,
			BRICKS_DB_GLOBAL_CLASSES,
			BRICKS_DB_GLOBAL_VARIABLES,
			BRICKS_DB_GLOBAL_CLASSES_TIMESTAMP,
			BRICKS_DB_GLOBAL_CLASSES_USER,
		];

		foreach ( $option_names as $option_name ) {
			wp_cache_delete( $option_name, 'options' );
		}
	}

	/**
	 * Check design-manager permissions required by the proposed plan.
	 *
	 * @param array $classes   Proposed classes.
	 * @param array $variables Proposed variables.
	 * @return true|\WP_Error
	 */
	private static function check_design_permissions( array $classes, array $variables ) {
		if ( ! empty( $classes ) ) {
			$permission = Design::create_global_class_permission( [] );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		}

		if ( ! empty( $variables ) ) {
			$permission = Design::global_variables_write_permission( [] );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		}

		return true;
	}

	/**
	 * Render against the complete proposed design context without persisting it.
	 *
	 * @param int    $post_id   Target post.
	 * @param string $area      Frozen Bricks area.
	 * @param array  $elements  Proposed normalized elements.
	 * @param array  $classes   Proposed complete global-class store.
	 * @param array  $variables Proposed complete global-variable store.
	 * @return array|\WP_Error
	 */
	private static function render_candidate( int $post_id, string $area, array $elements, array $classes, array $variables ) {
		$previous_global_data = \Bricks\Database::$global_data;
		$previous_asset_state = self::snapshot_static_state( \Bricks\Assets::class );
		$previous_theme_state = self::snapshot_static_state( \Bricks\Theme_Styles::class );

		\Bricks\Database::$global_data['globalClasses']              = $classes;
		\Bricks\Database::$global_data['globalVariables']            = $variables;
		\Bricks\Assets::$inline_css                                  = [];
		\Bricks\Assets::$inline_css_breakpoints                      = [];
		\Bricks\Assets::$global_classes_elements                     = [];
		\Bricks\Assets::$unique_inline_css                           = [];
		\Bricks\Assets::$elements                                    = [];
		\Bricks\Assets::$inline_css_dynamic_data                     = '';
		\Bricks\Assets::$page_settings_post_ids                      = [];
		\Bricks\Assets::$css_looping_elements                        = [];
		\Bricks\Assets::$generated_loop_common_selectors             = [];
		\Bricks\Assets::$generated_loop_common_selectors_breakpoints = [];
		\Bricks\Assets::$current_generating_element                  = null;
		\Bricks\Assets::$loop_index_elements                         = [];

		try {
			$render = Conversion::render_elements(
				[
					'postId'   => $post_id,
					'area'     => $area,
					'elements' => $elements,
				]
			);
		} finally {
			\Bricks\Database::$global_data = $previous_global_data;
			self::restore_static_state( $previous_asset_state );
			self::restore_static_state( $previous_theme_state );
		}

		if ( is_wp_error( $render ) ) {
			return $render;
		}

		$variable_css                 = \Bricks\Assets::format_variables_as_css( $variables );
		$render_css                   = (string) ( $render['css'] ?? '' ) . $variable_css;
		$summary                      = Conversion::summarize_render_output(
			(string) ( $render['html'] ?? '' ),
			$render_css,
			count( $elements )
		);
		$summary['variableCssBytes']  = strlen( $variable_css );
		$summary['variableCssSha256'] = hash( 'sha256', $variable_css );

		return [ 'summary' => $summary ];
	}

	/**
	 * Snapshot static class state without depending on property visibility.
	 *
	 * @since 2.4
	 *
	 * @param string $class_name Class to inspect.
	 * @return array
	 */
	private static function snapshot_static_state( string $class_name ) {
		$state      = [];
		$reflection = new \ReflectionClass( $class_name );

		foreach ( $reflection->getProperties( \ReflectionProperty::IS_STATIC ) as $property ) {
			$property->setAccessible( true );
			$state[] = [
				'property' => $property,
				'value'    => $property->getValue(),
			];
		}

		return $state;
	}

	/**
	 * Restore static class state through its reflected properties.
	 *
	 * @since 2.4
	 *
	 * @param array $state Reflected static property snapshots.
	 * @return void
	 */
	private static function restore_static_state( array $state ) {
		foreach ( $state as $snapshot ) {
			$snapshot['property']->setValue( null, $snapshot['value'] );
		}
	}

	/**
	 * Produce the exact global-variable after-image without writing.
	 *
	 * Converter output represents only new variables. Existing names with the
	 * same value are idempotent; conflicting names or IDs are rejected.
	 *
	 * @param array $existing Existing variables.
	 * @param array $incoming Converter variables.
	 * @return array|\WP_Error
	 */
	private static function plan_variables( array $existing, array $incoming ) {
		$by_id   = [];
		$by_name = [];

		foreach ( $existing as $variable ) {
			if ( ! empty( $variable['id'] ) ) {
				$by_id[ (string) $variable['id'] ] = $variable;
			}
			if ( ! empty( $variable['name'] ) ) {
				$by_name[ (string) $variable['name'] ] = $variable;
			}
		}

		$created = [];
		foreach ( $incoming as $index => $variable ) {
			if ( ! is_array( $variable ) || empty( $variable['name'] ) || ! array_key_exists( 'value', $variable ) ) {
				return Error::invalid_param( "global_variables[{$index}]", 'a variable object with name and value', $variable );
			}

			$name = (string) $variable['name'];
			$id   = (string) ( $variable['id'] ?? '' );

			if ( isset( $by_name[ $name ] ) ) {
				if ( (string) ( $by_name[ $name ]['value'] ?? '' ) !== (string) $variable['value'] ) {
					return Error::conflict_duplicate_name(
						'global_variable',
						$name,
						[
							'message' => sprintf( 'Global variable "%s" already exists with a different value.', $name ),
						]
					);
				}
				continue;
			}

			if ( $id !== '' && isset( $by_id[ $id ] ) ) {
				return Error::conflict(
					'global_variable_id_exists',
					[
						'message'    => sprintf( 'Global variable ID "%s" already exists.', $id ),
						'variableId' => $id,
					]
				);
			}

			if ( $id === '' ) {
				$variable['id'] = \Bricks\Helpers::generate_random_id( false );
			}

			$created[]                = $variable;
			$by_name[ $name ]         = $variable;
			$by_id[ $variable['id'] ] = $variable;
		}

		return [
			'created'   => $created,
			'variables' => array_merge( $existing, $created ),
		];
	}

	/**
	 * Build a compact dependency index for workspace routing and drift checks.
	 *
	 * This is deliberately an index, not an editable copy of global data.
	 * Global resources have separate authorities and concurrency contracts.
	 *
	 * @param array $elements Canonical page elements.
	 * @return array
	 */
	private static function page_dependency_index( array $elements ): array {
		$class_ids      = [];
		$component_ids  = [];
		$variable_names = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			foreach ( (array) ( $settings['_cssGlobalClasses'] ?? [] ) as $class_id ) {
				if ( is_string( $class_id ) && $class_id !== '' ) {
					$class_ids[] = $class_id;
				}
			}

			$component_id = (string) ( $element['cid'] ?? $element['componentId'] ?? $settings['componentId'] ?? '' );
			if ( $component_id !== '' ) {
				$component_ids[] = $component_id;
			}

			$encoded_settings = (string) wp_json_encode( $settings );
			if ( preg_match_all( '/var\\(--([a-zA-Z0-9_-]+)\\)/', $encoded_settings, $matches ) ) {
				$variable_names = array_merge( $variable_names, $matches[1] );
			}
		}

		return [
			'globalClassIds'      => array_values( array_unique( $class_ids ) ),
			'globalVariableNames' => array_values( array_unique( $variable_names ) ),
			'componentIds'        => array_values( array_unique( $component_ids ) ),
		];
	}

	/**
	 * Summarize structural changes without returning both complete trees.
	 *
	 * @param array $before Existing elements.
	 * @param array $after  Proposed elements.
	 * @return array
	 */
	private static function element_diff_summary( array $before, array $after ): array {
		$before_by_id = [];
		$after_by_id  = [];

		foreach ( $before as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$before_by_id[ (string) $element['id'] ] = $element;
			}
		}
		foreach ( $after as $element ) {
			if ( is_array( $element ) && ! empty( $element['id'] ) ) {
				$after_by_id[ (string) $element['id'] ] = $element;
			}
		}

		$added   = array_values( array_diff( array_keys( $after_by_id ), array_keys( $before_by_id ) ) );
		$removed = array_values( array_diff( array_keys( $before_by_id ), array_keys( $after_by_id ) ) );
		$changed = [];

		foreach ( array_intersect( array_keys( $before_by_id ), array_keys( $after_by_id ) ) as $id ) {
			if ( self::data_digest( $before_by_id[ $id ] ) !== self::data_digest( $after_by_id[ $id ] ) ) {
				$changed[] = $id;
			}
		}

		return [
			'beforeCount' => count( $before ),
			'afterCount'  => count( $after ),
			'addedIds'    => $added,
			'removedIds'  => $removed,
			'changedIds'  => $changed,
		];
	}

	/**
	 * Read global classes from the same authority used by the frontend.
	 *
	 * @return array
	 */
	private static function read_global_classes(): array {
		if ( is_multisite() && BRICKS_MULTISITE_USE_MAIN_SITE_CLASSES ) {
			$classes = get_blog_option( get_main_site_id(), BRICKS_DB_GLOBAL_CLASSES, [] );
		} else {
			$classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		}

		return is_array( $classes ) ? $classes : [];
	}

	/**
	 * CAS a design option and dispatch normal WordPress option hooks.
	 *
	 * @param string $option_name             Option name.
	 * @param array  $before                  Expected before-image.
	 * @param array  $after                   Exact after-image.
	 * @param int    $expected_design_version Expected design-system version.
	 * @return true|\WP_Error
	 */
	private static function compare_and_swap_option( string $option_name, array $before, array $after, int $expected_design_version ) {
		global $wpdb;

		if ( $option_name === BRICKS_DB_GLOBAL_VARIABLES && \Bricks\Helpers::use_main_site_global_variables() ) {
			return Error::conflict(
				'multisite_variable_transaction_unsupported',
				[
					'message' => 'Transactional variable imports are not yet supported when global variables use main-site multisite storage.',
				]
			);
		}

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Design option CAS prevents stale agent writes.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} AS target
				LEFT JOIN {$wpdb->options} AS design_version ON design_version.option_name = %s
				SET target.option_value = %s
				WHERE target.option_name = %s
				AND BINARY target.option_value = BINARY %s
				AND COALESCE(CAST(design_version.option_value AS UNSIGNED), 0) = %d",
				Design::DESIGN_SYSTEM_VERSION_OPTION,
				maybe_serialize( array_values( $after ) ),
				$option_name,
				maybe_serialize( array_values( $before ) ),
				$expected_design_version
			)
		);

		if ( $updated === 0 && empty( $before ) && get_option( $option_name, null ) === null ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic absent-option creation is part of the CAS contract.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
					SELECT %s, %s, %s
					FROM (
						SELECT COALESCE(MAX(CAST(option_value AS UNSIGNED)), 0) AS current_version
						FROM {$wpdb->options}
						WHERE option_name = %s
					) AS design_version
					WHERE design_version.current_version = %d",
					$option_name,
					maybe_serialize( array_values( $after ) ),
					'yes',
					Design::DESIGN_SYSTEM_VERSION_OPTION,
					$expected_design_version
				)
			);

			if ( $updated === 1 ) {
				wp_cache_delete( $option_name, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				do_action( 'add_option_' . $option_name, $option_name, $after );
				do_action( 'added_option', $option_name, $after );
				return true;
			}
		}

		if ( $updated !== 1 ) {
			return Error::conflict(
				'design_option_write_conflict',
				[
					'message'    => 'A design option changed while the import was being committed.',
					'optionName' => $option_name,
				]
			);
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		do_action( 'update_option_' . $option_name, $before, $after, $option_name );
		do_action( 'updated_option', $option_name, $before, $after );

		return true;
	}

	/**
	 * Verify every authority after commit.
	 *
	 * @param array $candidate Candidate.
	 * @return true|\WP_Error
	 */
	private static function verify_committed_candidate( array $candidate ) {
		$classes = self::read_global_classes();
		$vars    = \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$vars    = is_array( $vars ) ? $vars : [];
		$page    = get_post_meta( $candidate['postId'], $candidate['metaKey'], true );
		$page    = is_array( $page ) ? $page : [];

		$mismatches = [];
		if ( self::data_digest( $classes ) !== self::data_digest( $candidate['classesAfter'] ) ) {
			$mismatches[] = 'global_classes';
		}
		if ( self::data_digest( $vars ) !== self::data_digest( $candidate['variablesAfter'] ) ) {
			$mismatches[] = 'global_variables';
		}
		if ( Save_Pipeline::document_digest( $page ) !== $candidate['documentDigestAfter'] ) {
			$mismatches[] = 'page_elements';
		}

		if ( ! empty( $mismatches ) ) {
			return Error::conflict(
				'import_readback_mismatch',
				[
					'message'   => 'Import readback did not match the frozen candidate.',
					'resources' => $mismatches,
				]
			);
		}

		if ( ! empty( $candidate['variablesToCreate'] ) ) {
			$variable_asset = self::verify_global_variable_asset( $candidate['variablesAfter'] );
			if ( is_wp_error( $variable_asset ) ) {
				return $variable_asset;
			}
		}

		return true;
	}

	/**
	 * Regenerate and read back the global-variable stylesheet.
	 *
	 * @param array $variables Complete global-variable store.
	 * @return true|\WP_Error
	 */
	private static function verify_global_variable_asset( array $variables ) {
		$expected_css = \Bricks\Assets::minify_css( \Bricks\Assets::format_variables_as_css( $variables ) );

		if ( \Bricks\Database::get_setting( 'cssLoading', 'inline' ) !== 'file' ) {
			$stored_variables = \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
			$stored_variables = is_array( $stored_variables ) ? $stored_variables : [];
			$actual_css       = \Bricks\Assets::minify_css( \Bricks\Assets::format_variables_as_css( $stored_variables ) );

			if ( ! hash_equals( hash( 'sha256', $expected_css ), hash( 'sha256', $actual_css ) ) ) {
				return Error::internal_error(
					'html-css-page-import',
					'The committed global variables did not produce the expected inline frontend CSS.'
				);
			}

			return true;
		}

		if ( ! class_exists( '\\Bricks\\Assets_Global_Variables', false ) ) {
			require_once BRICKS_PATH . 'includes/assets/global-variables.php';
		}

		$file_name = \Bricks\Assets_Global_Variables::generate_css_file( $variables );
		$file_path = \Bricks\Assets::$css_dir . '/global-variables.min.css';

		if ( $file_name !== 'global-variables.min.css' || ! is_readable( $file_path ) ) {
			return Error::internal_error(
				'html-css-page-import',
				'Global variables were stored, but their frontend stylesheet could not be generated.'
			);
		}

		$actual_css = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact local asset readback.

		if ( ! is_string( $actual_css ) || ! hash_equals( hash( 'sha256', $expected_css ), hash( 'sha256', $actual_css ) ) ) {
			return Error::internal_error(
				'html-css-page-import',
				'The generated global-variable stylesheet did not match the committed variable store.'
			);
		}

		return true;
	}

	/**
	 * Convert a failed commit into a rolled-back or manual-recovery result.
	 *
	 * @param string    $journal_key Journal option.
	 * @param array     $journal     Journal data.
	 * @param \WP_Error $cause       Original failure.
	 * @return \WP_Error
	 */
	private static function fail_and_compensate( string $journal_key, array $journal, $cause ) {
		$recovery = self::compensate( $journal_key, $journal );

		if ( is_wp_error( $recovery ) ) {
			return $recovery;
		}

		return Error::conflict(
			'import_rolled_back',
			[
				'message'          => 'The import could not commit every resource, so all candidate writes were rolled back.',
				'transactionState' => 'rolled_back',
				'journalId'        => $journal_key,
				'causeCode'        => $cause->get_error_code(),
				'causeMessage'     => $cause->get_error_message(),
			]
		);
	}

	/**
	 * Restore exact before-images only when current data still matches either
	 * the before- or candidate after-image. Unknown drift is never overwritten.
	 *
	 * @param string $journal_key Journal option.
	 * @param array  $journal     Journal data.
	 * @return true|\WP_Error
	 */
	private static function compensate( string $journal_key, array $journal ) {
		$candidate = $journal['candidate'] ?? [];
		$unsafe    = [];

		if ( ! is_array( $candidate ) || empty( $candidate['postId'] ) || empty( $candidate['metaKey'] ) ) {
			$journal['state']                   = 'manual_recovery_required';
			$journal['manualRecoveryResources'] = [ 'journal_candidate_missing' ];

			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return Error::internal_error(
					'html-css-page-import',
					'The interrupted import journal is missing its recovery candidate, and the durable manual-recovery state could not be recorded.'
				);
			}

			return Error::internal_error(
				'html-css-page-import',
				'The interrupted import journal is missing its recovery candidate, so automatic compensation stopped.',
				[
					'transactionState' => 'manual_recovery_required',
					'journalId'        => $journal_key,
				]
			);
		}

		$page        = get_post_meta( $candidate['postId'], $candidate['metaKey'], true );
		$page        = is_array( $page ) ? $page : [];
		$page_digest = Save_Pipeline::document_digest( $page );

		if ( $candidate['documentDigestAfter'] !== $candidate['documentDigestBefore'] ) {
			if (
				$page_digest === $candidate['documentDigestAfter'] &&
				self::restore_page_before_image( $candidate )
			) {
				wp_update_post(
					[
						'ID'            => $candidate['postId'],
						'post_modified' => current_time( 'mysql' ),
					]
				);
			} elseif ( $page_digest !== $candidate['documentDigestBefore'] ) {
				$unsafe[] = 'page_elements';
			}
		}

		$vars = \Bricks\Helpers::get_global_variables_option( BRICKS_DB_GLOBAL_VARIABLES, [] );
		$vars = is_array( $vars ) ? $vars : [];
		if ( ! empty( $candidate['variablesToCreate'] ) && self::data_digest( $vars ) !== self::data_digest( $candidate['variablesBefore'] ) ) {
			// Once published, a generated variable can be adopted by another
			// page without changing this option. Never delete it automatically.
			$unsafe[] = 'global_variables';
		}

		$classes = get_option( BRICKS_DB_GLOBAL_CLASSES, [] );
		$classes = is_array( $classes ) ? $classes : [];
		if ( ! empty( $candidate['classesToCreate'] ) && self::data_digest( $classes ) !== self::data_digest( $candidate['classesBefore'] ) ) {
			// Class IDs are externally referencable as soon as the option write
			// lands. Preserve them on a late failure rather than risking orphans.
			$unsafe[] = 'global_classes';
		}

		if ( ! empty( $unsafe ) ) {
			$journal['state']                   = 'manual_recovery_required';
			$journal['manualRecoveryResources'] = $unsafe;
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return Error::internal_error(
					'html-css-page-import',
					'Automatic compensation stopped to protect unrelated edits, but the durable journal could not record the manual-recovery state.',
					[
						'transactionState' => 'manual_recovery_required',
						'journalId'        => $journal_key,
						'resources'        => $unsafe,
					]
				);
			}

			return Error::internal_error(
				'html-css-page-import',
				'The import was interrupted and current data no longer matches a safe before/after image. Automatic compensation stopped to avoid overwriting unrelated edits.',
				[
					'transactionState' => 'manual_recovery_required',
					'journalId'        => $journal_key,
					'resources'        => $unsafe,
				]
			);
		}

		$journal = [
			'id'                 => $journal_key,
			'state'              => 'rolled_back',
			'workflow'           => (string) ( $candidate['workflow'] ?? 'html-css-import' ),
			'createdAt'          => $journal['createdAt'] ?? time(),
			'rolledBackAt'       => time(),
			'postId'             => (int) ( $candidate['postId'] ?? 0 ),
			'userId'             => (int) ( $candidate['userId'] ?? 0 ),
			'requestFingerprint' => $journal['requestFingerprint'] ?? '',
		];
		if ( ! self::save_journal( $journal_key, $journal ) ) {
			return Error::internal_error(
				'html-css-page-import',
				'The import data was restored, but the durable journal could not record the rollback. Keep the journal for automatic recovery.',
				[
					'transactionState' => 'manual_recovery_required',
					'journalId'        => $journal_key,
				]
			);
		}

		return true;
	}

	/**
	 * Restore page metadata with an exact database compare-and-swap.
	 *
	 * Recovery may run from WP-Cron as user 0, where the normal Bricks metadata
	 * authorization filter correctly rejects ordinary writes. The durable
	 * journal is the recovery authority here, and the exact candidate
	 * after-image prevents overwriting any later page edit.
	 *
	 * @param array $candidate Frozen transaction candidate.
	 * @return bool
	 */
	private static function restore_page_before_image( array $candidate ): bool {
		global $wpdb;

		$post_id    = (int) $candidate['postId'];
		$meta_key   = (string) $candidate['metaKey'];
		$after      = maybe_serialize( $candidate['elements'] );
		$had_before = ! empty( $candidate['metaExistedBefore'] );

		if ( $had_before ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Recovery CAS is authorized by the durable journal and exact after-image.
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta}
					SET meta_value = %s
					WHERE post_id = %d
						AND meta_key = %s
						AND BINARY meta_value = BINARY %s",
					maybe_serialize( $candidate['elementsBefore'] ),
					$post_id,
					$meta_key,
					$after
				)
			);
		} else {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Recovery CAS deletes only the exact candidate after-image.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta}
					WHERE post_id = %d
						AND meta_key = %s
						AND BINARY meta_value = BINARY %s",
					$post_id,
					$meta_key,
					$after
				)
			);
		}

		wp_cache_delete( $post_id, 'post_meta' );

		$restored        = get_post_meta( $post_id, $meta_key, true );
		$restored        = is_array( $restored ) ? $restored : [];
		$restored_exists = metadata_exists( 'post', $post_id, $meta_key );

		return (
			Save_Pipeline::document_digest( $restored ) === $candidate['documentDigestBefore'] &&
			$restored_exists === $had_before
		);
	}

	/**
	 * CAS an auxiliary scalar option while the design version is unchanged.
	 *
	 * @param string $option_name             Option name.
	 * @param bool   $existed                 Whether the option existed at preview.
	 * @param mixed  $before                  Exact preview before-image.
	 * @param mixed  $after                   New scalar value.
	 * @param int    $expected_design_version Expected current design version.
	 * @return bool
	 */
	private static function compare_and_swap_scalar_option( string $option_name, bool $existed, $before, $after, int $expected_design_version ): bool {
		global $wpdb;

		if ( $existed ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Auxiliary class metadata must not overwrite a concurrent Builder save.
				$wpdb->prepare(
					"UPDATE {$wpdb->options} AS target
					LEFT JOIN {$wpdb->options} AS design_version ON design_version.option_name = %s
					SET target.option_value = %s
					WHERE target.option_name = %s
						AND BINARY target.option_value = BINARY %s
						AND COALESCE(CAST(design_version.option_value AS UNSIGNED), 0) = %d",
					Design::DESIGN_SYSTEM_VERSION_OPTION,
					maybe_serialize( $after ),
					$option_name,
					maybe_serialize( $before ),
					$expected_design_version
				)
			);
		} else {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic absent-option creation is bound to the design version.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
					SELECT %s, %s, %s
					FROM (
						SELECT COALESCE(MAX(CAST(option_value AS UNSIGNED)), 0) AS current_version
						FROM {$wpdb->options}
						WHERE option_name = %s
					) AS design_version
					WHERE design_version.current_version = %d",
					$option_name,
					maybe_serialize( $after ),
					'yes',
					Design::DESIGN_SYSTEM_VERSION_OPTION,
					$expected_design_version
				)
			);
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return (
			(
				$updated === 1 ||
				(string) maybe_serialize( get_option( $option_name, null ) ) === (string) maybe_serialize( $after )
			) &&
			Design::design_system_version() === $expected_design_version
		);
	}

	/**
	 * Validate an authority-correct snapshot, with legacy single-site support.
	 *
	 * @param mixed  $expected_snapshot     Frozen composite snapshot or null.
	 * @param int    $legacy_version        Frozen current-site scalar.
	 * @param array  $actual_snapshot       Fresh composite snapshot.
	 * @param string $message               Conflict guidance.
	 * @param int    $current_site_increment Expected writes made on the current site.
	 * @return true|\WP_Error
	 */
	private static function validate_design_system_snapshot( $expected_snapshot, int $legacy_version, array $actual_snapshot, string $message, int $current_site_increment = 0 ) {
		$actual_authorities = self::design_authority_versions( $actual_snapshot );

		if ( is_wp_error( $actual_authorities ) ) {
			return $actual_authorities;
		}

		if ( ! is_array( $expected_snapshot ) ) {
			if ( count( $actual_authorities ) > 1 ) {
				return Error::conflict(
					'design_system_snapshot_required',
					[
						'message'                    => 'Design resources use multiple multisite authorities. Re-read get-design-context or check out the page again and pass the complete designSystemSnapshot.',
						'actualDesignSystemSnapshot' => $actual_snapshot,
					]
				);
			}

			$actual_version = (int) reset( $actual_authorities );
			if ( $actual_version === $legacy_version + $current_site_increment ) {
				return true;
			}

			return Error::conflict(
				'design_system_version_mismatch',
				[
					'message'                     => $message,
					'expectedDesignSystemVersion' => $legacy_version + $current_site_increment,
					'actualDesignSystemVersion'   => $actual_version,
				]
			);
		}

		$expected_authorities = self::design_authority_versions( $expected_snapshot );
		if ( is_wp_error( $expected_authorities ) ) {
			return $expected_authorities;
		}

		$expected_token = hash( 'sha256', (string) wp_json_encode( $expected_authorities ) );
		if (
			empty( $expected_snapshot['token'] ) ||
			! hash_equals( $expected_token, (string) $expected_snapshot['token'] )
		) {
			return Error::conflict(
				'design_system_snapshot_invalid',
				[
					'message' => 'The supplied designSystemSnapshot is internally inconsistent. Fetch a fresh server-issued snapshot.',
				]
			);
		}

		$current_site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$current_key     = (string) $current_site_id;
		if ( $current_site_increment !== 0 ) {
			if ( ! array_key_exists( $current_key, $expected_authorities ) ) {
				return Error::conflict(
					'design_system_snapshot_invalid',
					[
						'message' => 'The supplied designSystemSnapshot does not include the current-site authority.',
					]
				);
			}

			$expected_authorities[ $current_key ] += $current_site_increment;
		}

		if ( $expected_authorities === $actual_authorities ) {
			return true;
		}

		return Error::conflict(
			'design_system_version_mismatch',
			[
				'message'                      => $message,
				'expectedDesignSystemSnapshot' => $expected_snapshot,
				'actualDesignSystemSnapshot'   => $actual_snapshot,
			]
		);
	}

	/**
	 * Normalize and verify a design snapshot's authority map.
	 *
	 * @param array $snapshot Composite design snapshot.
	 * @return array|\WP_Error
	 */
	private static function design_authority_versions( array $snapshot ) {
		$rows = $snapshot['authorities'] ?? null;
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return Error::conflict(
				'design_system_snapshot_invalid',
				[
					'message' => 'The designSystemSnapshot is missing authority versions.',
				]
			);
		}

		$authorities = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['siteId'], $row['version'] ) ) {
				return Error::conflict(
					'design_system_snapshot_invalid',
					[
						'message' => 'The designSystemSnapshot contains an invalid authority entry.',
					]
				);
			}

			$site_id = (int) $row['siteId'];
			if ( array_key_exists( (string) $site_id, $authorities ) ) {
				return Error::conflict(
					'design_system_snapshot_invalid',
					[
						'message' => 'The designSystemSnapshot contains a duplicate authority.',
					]
				);
			}

			$authorities[ (string) $site_id ] = (int) $row['version'];
		}

		ksort( $authorities, SORT_NUMERIC );

		return $authorities;
	}

	/**
	 * Compact preview response.
	 *
	 * @param array  $candidate Candidate.
	 * @param string $token     Preview token.
	 * @return array
	 */
	private static function preview_response( array $candidate, string $token ): array {
		return [
			'action'                     => 'preview',
			'workflow'                   => (string) ( $candidate['workflow'] ?? 'html-css-import' ),
			'contractVersion'            => self::CONTRACT_VERSION,
			'postId'                     => (int) $candidate['postId'],
			'previewToken'               => $token,
			'expiresAt'                  => (int) $candidate['expiresAt'],
			'candidateDigest'            => $candidate['candidateDigest'],
			'expectedDocumentDigest'     => $candidate['documentDigestBefore'],
			'savedDocumentDigest'        => null,
			'designSystemVersionBefore'  => (int) $candidate['designSystemVersionBefore'],
			'designSystemVersion'        => (int) $candidate['designSystemVersionBefore'],
			'designSystemSnapshotBefore' => $candidate['designSystemSnapshotBefore'] ?? [],
			'designSystemSnapshot'       => $candidate['designSystemSnapshotBefore'] ?? [],
			'plan'                       => self::plan_summary( $candidate ),
			'partial'                    => ! empty( $candidate['partial'] ),
			'omittedElements'            => $candidate['omittedElements'] ?? [],
			'diagnostics'                => self::diagnostics_summary( $candidate ),
			'render'                     => $candidate['render'],
			'committed'                  => false,
			'transactionState'           => 'previewed',
			'revisionId'                 => null,
			'journalId'                  => null,
		];
	}

	/**
	 * Return a terminal read-only preview for an unchanged checked-out file.
	 *
	 * A no-op never receives a commit token, so it cannot create a pointless
	 * revision or refresh metadata and signatures on an otherwise unchanged
	 * page.
	 *
	 * @param int    $post_id         Target post.
	 * @param string $document_digest Current document digest.
	 * @param int    $design_version  Current design-system version.
	 * @param array  $design_snapshot Current authority-correct design snapshot.
	 * @param array  $render          Render summary.
	 * @param int    $element_count   Element count.
	 * @return array
	 */
	private static function page_workspace_noop_response( int $post_id, string $document_digest, int $design_version, array $design_snapshot, array $render, int $element_count ): array {
		return [
			'action'                     => 'preview',
			'workflow'                   => 'page-workspace',
			'contractVersion'            => self::CONTRACT_VERSION,
			'postId'                     => $post_id,
			'previewToken'               => null,
			'expiresAt'                  => null,
			'candidateDigest'            => $document_digest,
			'expectedDocumentDigest'     => $document_digest,
			'savedDocumentDigest'        => $document_digest,
			'designSystemVersionBefore'  => $design_version,
			'designSystemVersion'        => $design_version,
			'designSystemSnapshotBefore' => $design_snapshot,
			'designSystemSnapshot'       => $design_snapshot,
			'plan'                       => [
				'placement'       => 'no-op',
				'elementCount'    => $element_count,
				'classCreates'    => 0,
				'variableCreates' => 0,
				'classIds'        => [],
				'variableIds'     => [],
				'elementDiff'     => [
					'beforeCount' => $element_count,
					'afterCount'  => $element_count,
					'addedIds'    => [],
					'removedIds'  => [],
					'changedIds'  => [],
				],
			],
			'diagnostics'                => [
				'warnings'                => [],
				'errors'                  => [],
				'requiresAcknowledgement' => false,
				'remNormalization'        => [],
			],
			'render'                     => $render,
			'committed'                  => false,
			'transactionState'           => 'no_change',
			'revisionId'                 => null,
			'journalId'                  => null,
		];
	}

	/**
	 * Compact normalized plan.
	 *
	 * @param array $candidate Candidate.
	 * @return array
	 */
	private static function plan_summary( array $candidate ): array {
		$plan = [
			'placement'       => (string) ( $candidate['placement'] ?? 'replace' ),
			'elementCount'    => count( $candidate['elements'] ),
			'classCreates'    => count( $candidate['classesToCreate'] ),
			'variableCreates' => count( $candidate['variablesToCreate'] ),
			'classIds'        => array_values( array_filter( array_column( $candidate['classesToCreate'], 'id' ) ) ),
			'variableIds'     => array_values( array_filter( array_column( $candidate['variablesToCreate'], 'id' ) ) ),
		];

		if ( isset( $candidate['elementDiff'] ) ) {
			$plan['elementDiff'] = $candidate['elementDiff'];
		}

		return $plan;
	}

	/**
	 * Omit elements the current user cannot author, together with every nested
	 * descendant. The resulting flat tree is repaired from retained parent links
	 * so a preview can never contain an orphaned child reference.
	 *
	 * @param array $elements Converted Bricks elements.
	 * @return array|\WP_Error
	 */
	private static function omit_restricted_import_elements( array $elements ) {
		$by_id            = [];
		$order            = [];
		$omitted          = [];
		$omit_ids         = [];
		$has_restrictions = false;

		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( Elements::code_sensitive_payload_for_current_user( $element ) ) ) {
				$has_restrictions = true;
				break;
			}
		}

		// Keep ordinary imports byte-for-byte intact for the normal save pipeline.
		if ( ! $has_restrictions ) {
			return [
				'elements'        => $elements,
				'omittedElements' => [],
			];
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				return Error::conflict(
					'partial_import_invalid_topology',
					[ 'message' => __( 'A partial import can only retain elements with stable IDs. Fix the converted element tree and preview again.', 'bricks' ) ]
				);
			}

			$id = (string) $element['id'];
			if ( isset( $by_id[ $id ] ) ) {
				return Error::conflict(
					'partial_import_invalid_topology',
					[
						'message'   => __( 'A partial import cannot safely repair duplicate element IDs. Fix the converted element tree and preview again.', 'bricks' ),
						'elementId' => $id
					]
				);
			}

			$by_id[ $id ] = $element;
			$order[]      = $id;

			if ( ! empty( Elements::code_sensitive_payload_for_current_user( $element ) ) ) {
				$omit_ids[ $id ] = Elements::code_authoring_restriction_reason( $element );
			}
		}

		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( $order as $id ) {
				$parent = (string) ( $by_id[ $id ]['parent'] ?? '0' );
				if ( ! isset( $omit_ids[ $id ] ) && $parent !== '0' && isset( $omit_ids[ $parent ] ) ) {
					$omit_ids[ $id ] = 'restricted_ancestor';
					$changed         = true;
				}
			}
		}

		foreach ( $order as $id ) {
			if ( ! isset( $omit_ids[ $id ] ) ) {
				continue;
			}

			$element   = $by_id[ $id ];
			$omitted[] = [
				'id'     => $id,
				'name'   => (string) ( $element['name'] ?? '' ),
				'reason' => $omit_ids[ $id ],
			];
		}

		$retained = [];
		foreach ( $order as $id ) {
			if ( isset( $omit_ids[ $id ] ) ) {
				continue;
			}

			$element = $by_id[ $id ];
			$parent  = (string) ( $element['parent'] ?? '0' );
			if ( $parent !== '0' && ! isset( $by_id[ $parent ] ) ) {
				return Error::conflict(
					'partial_import_invalid_topology',
					[
						'message'   => __( 'A retained element references a parent outside the converted tree. Fix the converted element tree and preview again.', 'bricks' ),
						'elementId' => $id,
						'parentId'  => $parent
					]
				);
			}
			$element['parent']   = $parent === '0' ? 0 : $parent;
			$element['children'] = [];
			$retained[]          = $element;
		}

		$index = [];
		foreach ( $retained as $position => $element ) {
			$index[ (string) $element['id'] ] = $position;
		}
		foreach ( $retained as $position => $element ) {
			$parent = (string) ( $element['parent'] ?? '0' );
			if ( $parent !== '0' && ! isset( $index[ $parent ] ) ) {
				return Error::conflict(
					'partial_import_invalid_topology',
					[
						'message'   => __( 'A retained element references an omitted or invalid parent. Fix the converted element tree and preview again.', 'bricks' ),
						'elementId' => (string) $element['id'],
						'parentId'  => $parent
					]
				);
			}
			if ( $parent !== '0' ) {
				$retained[ $index[ $parent ] ]['children'][] = (string) $element['id'];
			}
		}

		foreach ( $retained as $element ) {
			$seen   = [];
			$parent = (string) ( $element['parent'] ?? '0' );
			while ( $parent !== '0' ) {
				if ( isset( $seen[ $parent ] ) ) {
					return Error::conflict(
						'partial_import_invalid_topology',
						[
							'message'   => __( 'A partial import cannot repair a cyclic retained element tree. Fix the converted element tree and preview again.', 'bricks' ),
							'elementId' => (string) $element['id']
						]
					);
				}
				$seen[ $parent ] = true;
				$parent          = (string) ( $retained[ $index[ $parent ] ]['parent'] ?? '0' );
			}
		}

		return [
			'elements'        => $retained,
			'omittedElements' => $omitted,
		];
	}

	/**
	 * A retained empty layout wrapper is not enough to make a partial import
	 * useful. Content-bearing elements and non-layout elements remain useful.
	 *
	 * @param array $elements Retained normalized elements.
	 * @return bool
	 */
	private static function has_meaningful_import_content( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$name = (string) ( $element['name'] ?? '' );
			if ( ! in_array( $name, [ 'section', 'container', 'block', 'div' ], true ) ) {
				return true;
			}

			if ( self::settings_have_import_content( $element['settings'] ?? [] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect content evidence without treating layout metadata or custom HTML
	 * tags as substantive retained output.
	 *
	 * @param mixed $settings Element settings.
	 * @return bool
	 */
	private static function settings_have_import_content( $settings ): bool {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		foreach ( $settings as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( in_array( $key, [ '_tag', 'tag', 'customtag', '_cssclasses', '_cssglobalclasses' ], true ) ) {
				continue;
			}
			if ( preg_match( '/(text|html|content|image|video|media|gallery|icon|logo)/', $key ) && $value !== '' && $value !== null && $value !== [] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retain only converted global resources referenced by the permitted tree.
	 * This prevents a denied subtree from leaving newly-created classes or
	 * variables behind when none of the retained elements can use them.
	 *
	 * @param array $elements          Retained elements.
	 * @param array $classes           Converted global classes.
	 * @param array $variables         Converted variables.
	 * @param array $skipped_variables Converted variables already present on site.
	 * @return array
	 */
	private static function retain_partial_import_resources( array $elements, array $classes, array $variables, array $skipped_variables ): array {
		$class_ids   = [];
		$class_names = [];
		foreach ( $elements as $element ) {
			$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			foreach ( (array) ( $settings['_cssGlobalClasses'] ?? [] ) as $class_id ) {
				$class_ids[ (string) $class_id ] = true;
			}
			foreach ( preg_split( '/\s+/', (string) ( $settings['_cssClasses'] ?? '' ) ) as $class_name ) {
				if ( $class_name !== '' ) {
					$class_names[ $class_name ] = true;
				}
			}
		}

		$classes = array_values(
			array_filter(
				$classes,
				static function ( $class ) use ( $class_ids, $class_names ) {
					return is_array( $class ) && ( isset( $class_ids[ (string) ( $class['id'] ?? '' ) ] ) || isset( $class_names[ (string) ( $class['name'] ?? '' ) ] ) );
				}
			)
		);

		$resource_source   = (string) wp_json_encode(
			[
				'elements' => $elements,
				'classes'  => $classes
			]
		);
		$all_variables     = array_merge( $variables, $skipped_variables );
		$required_names    = self::referenced_variable_names( $resource_source );
		$variables_by_name = [];
		foreach ( $all_variables as $variable ) {
			if ( is_array( $variable ) && ! empty( $variable['name'] ) ) {
				$variables_by_name[ (string) $variable['name'] ] = $variable;
			}
		}
		$pending = array_keys( $required_names );
		while ( ! empty( $pending ) ) {
			$name = array_pop( $pending );
			if ( ! isset( $variables_by_name[ $name ] ) ) {
				continue;
			}
			foreach ( self::referenced_variable_names( (string) wp_json_encode( $variables_by_name[ $name ] ) ) as $dependency => $unused ) {
				if ( ! isset( $required_names[ $dependency ] ) ) {
					$required_names[ $dependency ] = true;
					$pending[]                     = $dependency;
				}
			}
		}
		$filter_variables = static function ( array $items ) use ( $required_names ): array {
			return array_values(
				array_filter(
					$items,
					static function ( $variable ) use ( $required_names ) {
						return is_array( $variable ) && isset( $required_names[ (string) ( $variable['name'] ?? '' ) ] );
					}
				)
			);
		};

		return [
			'classes'          => $classes,
			'variables'        => $filter_variables( $variables ),
			'skippedVariables' => $filter_variables( $skipped_variables ),
		];
	}

	/**
	 * Extract CSS custom-property dependencies from normalized resource data.
	 *
	 * @param string $source Resource data.
	 * @return array
	 */
	private static function referenced_variable_names( string $source ): array {
		$names = [];
		if ( preg_match_all( '/var\(\s*--([A-Za-z0-9_-]+)/', $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$names[ (string) $name ] = true;
			}
		}

		return $names;
	}

	/**
	 * Partial-import omission warnings explain a bounded permission result and
	 * do not need an extra acknowledgement before the facade commits it.
	 *
	 * @param array $warnings Candidate warnings.
	 * @return bool
	 */
	private static function warnings_require_acknowledgement( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( ! is_array( $warning ) || ( $warning['code'] ?? '' ) !== 'partial_import_omitted_restricted_elements' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compact diagnostics.
	 *
	 * @param array $candidate Candidate.
	 * @return array
	 */
	private static function diagnostics_summary( array $candidate ): array {
		return [
			'warnings'                    => $candidate['warnings'],
			'errors'                      => $candidate['errors'],
			'requiresAcknowledgement'     => self::warnings_require_acknowledgement( $candidate['warnings'] ?? [] ),
			'partial'                     => ! empty( $candidate['partial'] ),
			'omittedElements'             => $candidate['omittedElements'] ?? [],
			'remNormalization'            => $candidate['remNormalization'],
			'customCssElementCount'       => (int) ( $candidate['customCssElementCount'] ?? 0 ),
			'customCssGlobalClassCount'   => (int) ( $candidate['customCssGlobalClassCount'] ?? 0 ),
			'customCssBytes'              => (int) ( $candidate['customCssBytes'] ?? 0 ),
			'codeElementCount'            => (int) ( $candidate['codeElementCount'] ?? 0 ),
			'proposedGlobalClassCount'    => count( $candidate['classesToCreate'] ?? [] ),
			'proposedGlobalVariableCount' => count( $candidate['variablesToCreate'] ?? [] ),
			'customCssPolicy'             => (string) ( $candidate['customCssPolicy'] ?? 'allow' ),
			'globalResourcePolicy'        => (string) ( $candidate['globalResourcePolicy'] ?? 'allow' ),
			'documentPurpose'             => (string) ( $candidate['documentPurpose'] ?? 'page-content' ),
			'sourceDocument'              => $candidate['sourceDocument'] ?? null,
		];
	}

	/**
	 * Inspect the submitted fragment before conversion so workspace-level
	 * landmark policy can account for the wrapper Bricks renders around it.
	 *
	 * @param string $html Source HTML.
	 * @return array|\WP_Error
	 */
	private static function analyze_source_document( string $html ) {
		$previous_errors = libxml_use_internal_errors( true );
		$document        = new \DOMDocument();
		$loaded          = $document->loadHTML(
			'<!DOCTYPE html><html><body>' . trim( $html ) . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $loaded || ! $body instanceof \DOMElement ) {
			return Error::invalid_param( 'html', 'valid non-empty HTML', null );
		}

		$roots     = [];
		$landmarks = [];
		foreach ( $body->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType !== XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$tag = strtolower( $child->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( in_array( $tag, [ 'style', 'script', 'link' ], true ) ) {
				continue;
			}
			$roots[] = $tag;
			if ( in_array( $tag, [ 'main', 'header', 'footer' ], true ) ) {
				$landmarks[] = $tag;
			}
		}

		$sole_wrapper             = false;
		$sole_wrapper_child_count = 0;
		if ( count( $roots ) === 1 && in_array( $roots[0], [ 'div', 'main' ], true ) ) {
			foreach ( $body->childNodes as $root ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $root->nodeType !== XML_ELEMENT_NODE || strtolower( $root->tagName ) !== $roots[0] ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( $child->nodeType === XML_ELEMENT_NODE ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						++$sole_wrapper_child_count;
					}
				}
				break;
			}

			// A single layout container can be meaningful. Only flag a wrapper
			// that groups multiple page-level regions as a likely redundant shell.
			$sole_wrapper = $sole_wrapper_child_count > 1;
		}

		return [
			'rootCount'             => count( $roots ),
			'rootTags'              => $roots,
			'topLevelLandmarks'     => array_values( array_unique( $landmarks ) ),
			'soleWrapper'           => $sole_wrapper,
			'soleWrapperChildCount' => $sole_wrapper_child_count,
		];
	}

	/**
	 * Return acknowledgement-bearing source architecture diagnostics.
	 *
	 * @param array  $analysis Source document analysis.
	 * @param string $purpose  Document purpose.
	 * @return array
	 */
	private static function source_document_warnings( array $analysis, string $purpose ): array {
		if ( $purpose !== 'page-content' ) {
			return [];
		}

		$warnings = [];
		foreach ( [ 'header', 'footer' ] as $landmark ) {
			if ( in_array( $landmark, $analysis['topLevelLandmarks'], true ) ) {
				$warnings[] = [
					'code'    => "page_content_site_{$landmark}",
					'message' => sprintf(
						'The source has a top-level <%1$s>. Site-wide %1$s content normally belongs in a Bricks %1$s template, not the page body.',
						$landmark
					),
				];
			}
		}

		if ( ! empty( $analysis['soleWrapper'] ) ) {
			$warnings[] = [
				'code'    => 'redundant_page_shell',
				'message' => 'The source has one wrapper around the entire page body. Confirm that this shell is meaningful; otherwise import its children as top-level Bricks sections.',
			];
		}

		return $warnings;
	}

	/**
	 * Summarize custom CSS and Code element output for fail-closed import policy.
	 *
	 * @param array $elements       Normalized converted elements.
	 * @param array $global_classes Converted global classes.
	 * @return array Custom CSS element/byte and Code element counts.
	 */
	private static function custom_css_summary( array $elements, array $global_classes = [] ): array {
		$element_count      = 0;
		$global_class_count = 0;
		$bytes              = 0;
		$code_element_count = 0;
		$element_total      = count( $elements );

		foreach ( array_merge( $elements, $global_classes ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$is_global_class = $index >= $element_total;

			if ( ! $is_global_class && (string) ( $element['name'] ?? '' ) === 'code' ) {
				++$code_element_count;
			}

			$has_custom_css = false;
			$settings_sets  = [ is_array( $element['settings'] ?? null ) ? $element['settings'] : [] ];

			foreach ( $element['selectors'] ?? [] as $selector ) {
				if ( is_array( $selector['settings'] ?? null ) ) {
					$settings_sets[] = $selector['settings'];
				}
			}

			foreach ( $settings_sets as $settings ) {
				foreach ( $settings as $key => $value ) {
					if ( strpos( (string) $key, '_cssCustom' ) !== 0 || ! is_string( $value ) || trim( $value ) === '' ) {
						continue;
					}

					$has_custom_css = true;
					$bytes         += strlen( $value );
				}
			}

			if ( $has_custom_css ) {
				if ( $is_global_class ) {
					++$global_class_count;
				} else {
					++$element_count;
				}
			}
		}

		return [
			'elementCount'     => $element_count,
			'globalClassCount' => $global_class_count,
			'bytes'            => $bytes,
			'codeElementCount' => $code_element_count,
		];
	}

	/**
	 * Candidate digest excludes only volatile expiry metadata.
	 *
	 * @param array $candidate Candidate.
	 * @return string
	 */
	private static function candidate_digest( array $candidate ): string {
		unset( $candidate['candidateDigest'], $candidate['expiresAt'] );

		return self::data_digest( $candidate );
	}

	/**
	 * Stable PHP data digest.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function data_digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( $value ) );
	}

	/**
	 * Derive an opaque retry-stable preview token for the one-call facade.
	 *
	 * Binding the canonical caller intent prevents an idempotency key from
	 * replaying another source while keeping exact retries stable after the
	 * preview transient has been consumed into the durable journal.
	 *
	 * @param array  $input           Caller import intent without internal authority.
	 * @param string $idempotency_key Stable caller key.
	 * @return string
	 */
	private static function commit_import_request_digest( array $input, string $idempotency_key ): string {
		$intent = self::canonicalize_digest_value(
			[
				'siteId'         => get_current_blog_id(),
				'userId'         => get_current_user_id(),
				'idempotencyKey' => $idempotency_key,
				'input'          => $input,
			]
		);

		return self::data_digest( $intent );
	}

	/**
	 * Derive an unpredictable token from one canonical facade request.
	 *
	 * @param string $request_digest Canonical request digest.
	 * @return string
	 */
	private static function commit_import_preview_token( string $request_digest ): string {
		return substr( hash_hmac( 'sha256', $request_digest, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Add facade routing metadata without changing the transaction payload.
	 *
	 * @param array|\WP_Error $result         Existing preview/apply result.
	 * @param bool            $auto_applied   Whether the facade entered apply.
	 * @param string          $request_digest Canonical facade request digest.
	 * @param string          $response_format Requested presentation format.
	 * @return array|\WP_Error
	 */
	private static function decorate_facade_response( $result, bool $auto_applied, string $request_digest, string $response_format = 'summary' ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['autoApplied']         = $auto_applied;
		$result['nextAction']          = $auto_applied ? 'committed' : 'review_warnings';
		$result['facadeRequestDigest'] = $request_digest;
		$result['responseFormat']      = $response_format;
		$result['routes']              = [ 'apply' => 'bricks/apply-html-css-page-import' ];

		if ( $response_format === 'summary' && $auto_applied && ! empty( $result['committed'] ) && ( $result['transactionState'] ?? '' ) === 'committed' ) {
			return self::compact_committed_import_response( $result );
		}

		return $result;
	}

	/**
	 * Validate the presentation-only one-call import response format.
	 *
	 * @param array $input Ability input.
	 * @return string|\WP_Error
	 */
	private static function commit_import_response_format( $input ) {
		$format = (string) ( $input['responseFormat'] ?? 'summary' );

		if ( ! in_array( $format, [ 'summary', 'detailed' ], true ) ) {
			return Error::invalid_param( 'responseFormat', 'summary or detailed', $format );
		}

		return $format;
	}

	/**
	 * Keep clean one-call commits authoritative without returning preview-only data.
	 *
	 * @param array $result Decorated committed result.
	 * @return array
	 */
	private static function compact_committed_import_response( array $result ): array {
		$plan        = is_array( $result['plan'] ?? null ) ? $result['plan'] : [];
		$diagnostics = is_array( $result['diagnostics'] ?? null ) ? $result['diagnostics'] : [];
		$render      = is_array( $result['render']['summary'] ?? null ) ? $result['render']['summary'] : [];
		$snapshot    = is_array( $result['designSystemSnapshot'] ?? null ) ? $result['designSystemSnapshot'] : [];

		return [
			'action'                   => (string) ( $result['action'] ?? 'apply' ),
			'workflow'                 => (string) ( $result['workflow'] ?? 'html-css-import' ),
			'contractVersion'          => (string) ( $result['contractVersion'] ?? self::CONTRACT_VERSION ),
			'postId'                   => (int) ( $result['postId'] ?? 0 ),
			'savedDocumentDigest'      => (string) ( $result['savedDocumentDigest'] ?? '' ),
			'designSystemVersion'      => (int) ( $result['designSystemVersion'] ?? 0 ),
			'designSystemSnapshotHash' => self::data_digest( $snapshot ),
			'partial'                  => ! empty( $result['partial'] ),
			'omittedElements'          => $result['omittedElements'] ?? [],
			'plan'                     => [
				'placement'       => (string) ( $plan['placement'] ?? '' ),
				'elementCount'    => (int) ( $plan['elementCount'] ?? 0 ),
				'classCreates'    => (int) ( $plan['classCreates'] ?? 0 ),
				'variableCreates' => (int) ( $plan['variableCreates'] ?? 0 ),
			],
			'diagnostics'              => [
				'warningCount'            => count( is_array( $diagnostics['warnings'] ?? null ) ? $diagnostics['warnings'] : [] ),
				'errorCount'              => count( is_array( $diagnostics['errors'] ?? null ) ? $diagnostics['errors'] : [] ),
				'requiresAcknowledgement' => ! empty( $diagnostics['requiresAcknowledgement'] ),
			],
			'render'                   => [
				'elementCount'      => (int) ( $render['elementCount'] ?? 0 ),
				'hasResponsiveCss'  => ! empty( $render['hasResponsiveCss'] ),
				'cssSyntaxBalanced' => ! empty( $render['cssSyntaxBalanced'] ),
			],
			'committed'                => true,
			'transactionState'         => 'committed',
			'revisionId'               => isset( $result['revisionId'] ) ? (int) $result['revisionId'] : null,
			'autoApplied'              => true,
			'nextAction'               => 'committed',
			'facadeRequestDigest'      => (string) ( $result['facadeRequestDigest'] ?? '' ),
			'responseFormat'           => 'summary',
		];
	}

	/**
	 * Recursively sort object-like arrays for stable intent hashing.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed
	 */
	private static function canonicalize_digest_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize_digest_value( $item );
		}

		return $value;
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
	 * Short-lived binding for a facade idempotency key before apply starts.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Caller key.
	 * @return string
	 */
	private static function facade_key( int $user_id, string $key ): string {
		return self::FACADE_PREFIX . substr( hash( 'sha256', $user_id . "\0" . $key ), 0, 40 );
	}

	/**
	 * Durable idempotency journal key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Caller key.
	 * @return string
	 */
	private static function journal_key( int $user_id, string $key ): string {
		return self::JOURNAL_PREFIX . substr( hash( 'sha256', $user_id . "\0" . $key ), 0, 40 );
	}

	/**
	 * Acquire the database-session lock that fences one idempotency journal.
	 *
	 * MySQL releases named locks when the connection closes, so a crashed PHP
	 * request cannot leave a timed lease that another request must guess about.
	 *
	 * @param string $authority_key Stable journal or post authority key.
	 * @return string|\WP_Error
	 */
	private static function acquire_import_lock( string $authority_key ) {
		global $wpdb;

		$lock_name = 'bricks_ai_' . substr( hash( 'sha256', $authority_key ), 0, 40 );
		$acquired  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session lock fences concurrent retries and cleanup.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
		);

		if ( (string) $acquired === '1' ) {
			return $lock_name;
		}

		if ( (string) $acquired === '0' ) {
			return Error::conflict(
				'import_in_progress',
				[
					'message'           => 'The original apply request is still running. Retry this exact token and idempotency key shortly; do not create a second key for the same intended commit.',
					'retryAfterSeconds' => 1,
				]
			);
		}

		return Error::internal_error(
			'html-css-page-import',
			'The import idempotency lock could not be acquired. No data was changed.'
		);
	}

	/**
	 * Release an import authority lock.
	 *
	 * @param string $lock_name MySQL lock name.
	 * @return void
	 */
	private static function release_import_lock( string $lock_name ): void {
		global $wpdb;

		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paired release for acquire_import_lock().
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
		);
	}

	/**
	 * Persist journal state without autoloading it.
	 *
	 * @param string $key     Option name.
	 * @param array  $journal Journal.
	 * @return bool
	 */
	private static function save_journal( string $key, array $journal ): bool {
		if ( update_option( $key, $journal, false ) ) {
			return true;
		}

		$stored = get_option( $key, null );

		return is_array( $stored ) && self::data_digest( $stored ) === self::data_digest( $journal );
	}

	/**
	 * Delete a terminal import journal after its idempotency window.
	 *
	 * Manual-recovery journals retain their before-images because deleting
	 * them would remove the evidence needed to repair a partial commit.
	 *
	 * @since 2.4
	 *
	 * @param string $key Journal option name.
	 * @return void
	 */
	public static function cleanup_journal( string $key ): void {
		if ( strpos( $key, self::JOURNAL_PREFIX ) !== 0 ) {
			return;
		}

		$journal_lock = self::acquire_import_lock( $key );
		if ( is_wp_error( $journal_lock ) ) {
			return;
		}

		try {
			$journal = get_option( $key, null );

			if ( ! is_array( $journal ) ) {
				delete_option( $key );
				return;
			}

			if ( in_array( $journal['state'] ?? '', [ 'committed', 'rolled_back' ], true ) ) {
				delete_option( $key );
				return;
			}

			if ( ( $journal['state'] ?? '' ) !== 'manual_recovery_required' ) {
				$candidate   = is_array( $journal['candidate'] ?? null ) ? $journal['candidate'] : [];
				$post_id     = (int) ( $candidate['postId'] ?? 0 );
				$design_lock = '';

				if ( self::candidate_touches_design_system( $candidate ) ) {
					$design_lock = self::acquire_import_lock( 'design-system:' . get_current_blog_id() );
					if ( is_wp_error( $design_lock ) ) {
						return;
					}
				}

				try {
					$post_lock = self::acquire_import_lock( 'post:' . get_current_blog_id() . ':' . $post_id );
					if ( is_wp_error( $post_lock ) ) {
						return;
					}

					try {
						$recovery = self::compensate( $key, $journal );

						if ( $recovery === true ) {
							delete_option( $key );
						}
					} finally {
						self::release_import_lock( $post_lock );
					}
				} finally {
					if ( $design_lock !== '' ) {
						self::release_import_lock( $design_lock );
					}
				}
			}
		} finally {
			self::release_import_lock( $journal_lock );
		}
	}
}

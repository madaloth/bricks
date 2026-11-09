<?php
/**
 * Compact canonical-file resolution for coding agents.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve one existing Bricks resource to its canonical editable file.
 */
class Agent_File {
	const CONTRACT_VERSION   = '1';
	const MAX_MATCHES        = 20;
	const MAX_DOCUMENT_BYTES = 1572864;
	const MAX_RESPONSE_BYTES = 2097152;
	const MAX_CHANGED_PATHS  = 20;

	/**
	 * Commit input schema.
	 *
	 * @return array
	 */
	public static function commit_schema(): array {
		$schema                                 = Site_Changeset::agent_file_commit_schema();
		$schema['properties']['responseFormat'] = [
			'type'        => 'string',
			'enum'        => [ 'summary', 'document' ],
			'default'     => 'summary',
			'description' => __( 'Return a compact authoritative summary by default. Use document only when the complete canonical readback is required.', 'bricks' ),
		];

		return $schema;
	}

	/**
	 * Commit output schema.
	 *
	 * @return array
	 */
	public static function commit_output_schema(): array {
		$schema                                        = Site_Changeset::agent_file_commit_output_schema();
		$schema['properties']['responseFormat']        = [ 'type' => 'string' ];
		$schema['properties']['changed']               = [ 'type' => [ 'boolean', 'null' ] ];
		$schema['properties']['changedPathCount']      = [ 'type' => 'integer' ];
		$schema['properties']['changedPathsTruncated'] = [ 'type' => 'boolean' ];

		return $schema;
	}

	/**
	 * Commit permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function commit_permission( $input ) {
		$format = self::commit_response_format( $input );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		return Site_Changeset::agent_file_commit_permission( self::changeset_commit_input( $input ) );
	}

	/**
	 * Durably validate, apply, and return one canonical file.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function commit( $input ) {
		$format = self::commit_response_format( $input );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		$result = Site_Changeset::commit_agent_file( self::changeset_commit_input( $input ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return $result;
		}

		$result['responseFormat'] = $format;
		$result['changed']        = self::commit_changed_state( (string) ( $result['state'] ?? '' ) );

		if ( $format === 'document' ) {
			return $result;
		}

		$changed_paths                   = is_array( $result['changedPaths'] ?? null ) ? array_values( $result['changedPaths'] ) : [];
		$result['changedPathCount']      = count( $changed_paths );
		$result['changedPathsTruncated'] = count( $changed_paths ) > self::MAX_CHANGED_PATHS;
		$result['changedPaths']          = array_slice( $changed_paths, 0, self::MAX_CHANGED_PATHS );

		if ( ! is_array( $result['authoritative']['document'] ?? null ) ) {
			return $result;
		}

		$authoritative           = $result['authoritative'];
		$document                = $authoritative['document'];
		$result['authoritative'] = [
			'target'        => is_array( $authoritative['target'] ?? null ) ? $authoritative['target'] : ( $result['target'] ?? [] ),
			'path'          => (string) ( $authoritative['path'] ?? ( $result['path'] ?? '' ) ),
			'schemaVersion' => (int) ( $document['schemaVersion'] ?? 0 ),
			'version'       => self::authoritative_data_version( $document ),
			'sha256'        => (string) ( $authoritative['sha256'] ?? '' ),
			'bytes'         => (int) ( $authoritative['bytes'] ?? 0 ),
		];

		return $result;
	}

	/**
	 * Validate the presentation-only commit response format.
	 *
	 * @param array $input Ability input.
	 * @return string|\WP_Error
	 */
	private static function commit_response_format( $input ) {
		$format = (string) ( $input['responseFormat'] ?? 'summary' );

		if ( ! in_array( $format, [ 'summary', 'document' ], true ) ) {
			return Error::invalid_param( 'responseFormat', 'summary or document', $format );
		}

		return $format;
	}

	/**
	 * Remove presentation-only input before permission, CAS, and journaling.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function changeset_commit_input( $input ): array {
		unset( $input['responseFormat'] );

		return $input;
	}

	/**
	 * Report whether the terminal result proves a change.
	 *
	 * @param string $state Commit state.
	 * @return bool|null
	 */
	private static function commit_changed_state( string $state ) {
		if ( $state === 'committed' ) {
			return true;
		}

		if ( $state === 'no_change' ) {
			return false;
		}

		return null;
	}

	/**
	 * Extract the canonical resource version from a focused readback baseline.
	 *
	 * @param array $document Authoritative canonical document.
	 * @return int|null
	 */
	private static function authoritative_data_version( array $document ) {
		$baseline = is_array( $document['baseline'] ?? null ) ? $document['baseline'] : [];
		$paths    = [
			[ 'designSystemVersion' ],
			[ 'expectedDesignSystemVersion' ],
			[ 'expectedOwnership', 'version' ],
			[ 'itemOwnership', 'version' ],
			[ 'expectedVariableOwnership', 'version' ],
		];

		foreach ( $paths as $path ) {
			$value = $baseline;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}

			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				return (int) $value;
			}
		}

		return null;
	}

	/**
	 * Resolve input schema.
	 *
	 * @return array
	 */
	public static function resolve_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'query'         => [
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				],
				'scope'         => [
					'type' => 'string',
					'enum' => [ 'page', 'design' ],
				],
				'resourceKinds' => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 4,
					'uniqueItems' => true,
					'items'       => [
						'type' => 'string',
						'enum' => self::resource_kinds(),
					],
				],
				'maxMatches'    => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_MATCHES,
				],
			],
			'required'             => [ 'query', 'scope' ],
			'additionalProperties' => false,
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
				'contractVersion' => [ 'type' => 'string' ],
				'state'           => [ 'type' => 'string' ],
				'target'          => [ 'type' => [ 'object', 'null' ] ],
				'matches'         => [ 'type' => 'array' ],
				'path'            => [ 'type' => [ 'string', 'null' ] ],
				'document'        => [ 'type' => [ 'object', 'null' ] ],
				'editingContract' => [ 'type' => [ 'object', 'null' ] ],
				'coverage'        => [ 'type' => 'object' ],
				'routes'          => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Resolve permission callback.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function resolve_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ( $input['scope'] ?? '' ) === 'design' ) {
			return Design::get_design_context_permission( [] );
		}

		return true;
	}

	/**
	 * Resolve one exact or unambiguous prefix target and return its canonical file.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function resolve( $input ) {
		$query       = self::normalize_match_value( (string) ( $input['query'] ?? '' ) );
		$scope       = (string) ( $input['scope'] ?? '' );
		$max_matches = max( 1, min( self::MAX_MATCHES, (int) ( $input['maxMatches'] ?? self::MAX_MATCHES ) ) );

		if ( $query === '' ) {
			return Error::invalid_param( 'query', 'a non-empty exact or prefix target query', $input['query'] ?? null );
		}

		if ( ! in_array( $scope, [ 'page', 'design' ], true ) ) {
			return Error::invalid_param( 'scope', 'page or design', $scope );
		}

		if ( $scope === 'page' && array_key_exists( 'resourceKinds', $input ) ) {
			return Error::invalid_param( 'resourceKinds', 'omitted for page scope', $input['resourceKinds'] );
		}

		$resolved = $scope === 'page'
			? self::resolve_page_matches( $query )
			: self::resolve_design_matches( $query, $input['resourceKinds'] ?? self::resource_kinds() );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$matches  = $resolved['matches'];
		$coverage = $resolved['coverage'];
		$exact    = array_values(
			array_filter(
				$matches,
				static function( $match ) {
					return ( $match['_rank'] ?? '' ) === 'exact';
				}
			)
		);
		$tier     = ! empty( $exact ) ? $exact : array_values(
			array_filter(
				$matches,
				static function( $match ) {
					return ( $match['_rank'] ?? '' ) === 'prefix';
				}
			)
		);
		$count    = count( $tier );
		$listed   = array_slice( $tier, 0, $max_matches );

		foreach ( $listed as &$match ) {
			unset( $match['_rank'], $match['_source'] );
		}
		unset( $match );

		if ( empty( $coverage['complete'] ) && $count <= 1 ) {
			return self::summary_response( 'incomplete', $listed, $count, $count > $max_matches, $coverage );
		}

		if ( $count !== 1 ) {
			return self::summary_response( $count === 0 ? 'not_found' : 'ambiguous', $listed, $count, $count > $max_matches, $coverage );
		}

		$match    = $tier[0];
		$checkout = self::checkout_target( $match['target'] );

		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		$document = $checkout['document'];
		$bytes    = self::encoded_bytes( $document );
		$summary  = $match;
		unset( $summary['_rank'], $summary['_source'] );

		if ( $bytes > self::MAX_DOCUMENT_BYTES ) {
			return [
				'contractVersion' => self::CONTRACT_VERSION,
				'state'           => 'oversize',
				'target'          => $match['target'],
				'matches'         => [ $summary ],
				'matchCount'      => 1,
				'truncated'       => false,
				'path'            => $checkout['path'],
				'document'        => null,
				'documentBytes'   => $bytes,
				'coverage'        => $coverage,
				'routes'          => self::focused_routes( $match['target'] ),
			];
		}

		$response = [
			'contractVersion' => self::CONTRACT_VERSION,
			'state'           => 'resolved',
			'target'          => $match['target'],
			'matches'         => [ $summary ],
			'matchCount'      => 1,
			'truncated'       => false,
			'path'            => $checkout['path'],
			'document'        => $document,
			'documentBytes'   => $bytes,
			'documentDigest'  => self::digest( $document ),
			'editingContract' => self::editing_contract( $match['target'] ),
			'coverage'        => $coverage,
			'routes'          => [ 'commit' => 'bricks/commit-agent-file' ],
		];

		if ( self::encoded_bytes( $response ) > self::MAX_RESPONSE_BYTES ) {
			$response['state']    = 'oversize';
			$response['document'] = null;
			$response['routes']   = self::focused_routes( $match['target'] );
		}

		return $response;
	}

	/**
	 * Return compact canonical-key guidance for element-bearing files.
	 *
	 * @param array $target Resolved canonical target.
	 * @return array|null
	 */
	private static function editing_contract( array $target ) {
		$is_element_file = ( $target['scope'] ?? '' ) === 'page' ||
			( ( $target['scope'] ?? '' ) === 'design' && ( $target['resource'] ?? '' ) === 'component' );

		if ( ! $is_element_file ) {
			return null;
		}

		return [
			'preserveUnknownFields' => true,
			'commitEnvelope'        => [
				'completeDocumentPreferred' => true,
				'compactDocumentAllowed'    => true,
				'compactRequiredFields'     => ( $target['scope'] ?? '' ) === 'page'
					? [ 'baseline', 'elements' ]
					: [ 'baseline', 'item' ],
				'identitySource'            => 'target',
			],
			'elementSettings'       => [
				'customAttributes' => [
					'canonicalKey' => '_attributes',
					'alias'        => 'customAttributes',
					'record'       => [
						'required' => [ 'name', 'value' ],
						'optional' => [ 'id' ],
					],
				],
			],
		];
	}

	/**
	 * Build a summary-only result.
	 *
	 * @param string $state      Resolution state.
	 * @param array  $matches    Compact matches.
	 * @param int    $count      Observed match count.
	 * @param bool   $truncated  Whether output was capped.
	 * @param array  $coverage   Scan coverage.
	 * @return array
	 */
	private static function summary_response( string $state, array $matches, int $count, bool $truncated, array $coverage ): array {
		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'state'           => $state,
			'target'          => null,
			'matches'         => $matches,
			'matchCount'      => $count,
			'truncated'       => $truncated,
			'path'            => null,
			'document'        => null,
			'coverage'        => $coverage,
			'routes'          => [ 'discovery' => 'bricks/checkout-site-repository' ],
		];
	}

	/**
	 * Find page/template summaries through the bounded repository index.
	 *
	 * @param string $query Normalized query.
	 * @return array|\WP_Error
	 */
	private static function resolve_page_matches( string $query ) {
		$repository_query = self::post_id_from_query( $query ) === 0 && ( strpos( $query, 'content/' ) === 0 || strpos( $query, 'templates/' ) === 0 )
			? ''
			: $query;
		$repository       = self::execute_ability(
			'bricks/checkout-site-repository',
			[
				'perPage'             => 200,
				'includeDependencies' => false,
				'includeDesign'       => false,
				// Canonical paths are generated after the WordPress post query. A
				// partial path therefore needs a bounded unfiltered scan before the
				// exact/prefix rank below can inspect each generated file name.
				'query'               => $repository_query,
			]
		);

		if ( is_wp_error( $repository ) ) {
			return $repository;
		}

		$repository_items     = $repository['documents']['items'] ?? [];
		$supplemental_items   = [];
		$known_post_id        = self::post_id_from_query( $query );
		$slug_lookup_complete = true;

		foreach ( $repository_items as &$repository_item ) {
			$repository_item['_source'] = 'repository';
		}
		unset( $repository_item );

		if ( $known_post_id > 0 && current_user_can( 'edit_post', $known_post_id ) ) {
			$post = get_post( $known_post_id );
			if ( $post instanceof \WP_Post ) {
				$supplemental_items[] = [
					'_source'  => 'exact_id',
					'file'     => self::page_path( $post ),
					'postId'   => (int) $post->ID,
					'postType' => $post->post_type,
					'title'    => $post->post_title,
					'slug'     => $post->post_name,
					'status'   => $post->post_status,
				];
			}
		}

		if ( $known_post_id === 0 && sanitize_title( $query ) === $query ) {
			$slug_posts           = get_posts(
				[
					'name'                   => $query,
					'post_type'              => Input_Resolver::default_allow_list(),
					'post_status'            => array_values( array_diff( get_post_stati( [], 'names' ), [ 'trash', 'auto-draft', 'inherit' ] ) ),
					'posts_per_page'         => self::MAX_MATCHES + 1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Internally capped at 21 to detect ambiguity.
					'orderby'                => [
						'post_type' => 'ASC',
						'ID'        => 'ASC'
					],
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
			$slug_lookup_complete = count( $slug_posts ) < self::MAX_MATCHES + 1;

			foreach ( $slug_posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$supplemental_items[] = [
					'_source'  => 'exact_slug',
					'file'     => self::page_path( $post ),
					'postId'   => (int) $post->ID,
					'postType' => $post->post_type,
					'title'    => $post->post_title,
					'slug'     => $post->post_name,
					'status'   => $post->post_status,
				];
			}
		}

		$items   = array_merge( $supplemental_items, $repository_items );
		$matches = [];
		$seen    = [];

		foreach ( $items as $item ) {
			$post_id = (int) ( $item['postId'] ?? 0 );

			if ( $post_id < 1 || isset( $seen[ $post_id ] ) || is_wp_error( Workspace::checkout_page_workspace_permission( [ 'postId' => $post_id ] ) ) ) {
				continue;
			}

			$rank = self::match_rank( $query, [ $item['file'] ?? '', (string) $post_id, $item['title'] ?? '', $item['slug'] ?? '' ] );

			if ( $rank === null ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$matches[]        = [
				'_rank'    => $rank,
				'_source'  => (string) ( $item['_source'] ?? 'repository' ),
				'target'   => [
					'scope'  => 'page',
					'postId' => $post_id
				],
				'path'     => (string) ( $item['file'] ?? '' ),
				'label'    => (string) ( $item['title'] ?? '' ),
				'slug'     => (string) ( $item['slug'] ?? '' ),
				'postType' => (string) ( $item['postType'] ?? '' ),
				'status'   => (string) ( $item['status'] ?? '' ),
			];
		}

		$exact_id_matches = array_values(
			array_filter(
				$matches,
				static function( $match ) {
					return ( $match['_source'] ?? '' ) === 'exact_id' && ( $match['_rank'] ?? '' ) === 'exact';
				}
			)
		);

		if ( ! empty( $exact_id_matches ) ) {
			return [
				'matches'  => self::sort_matches( $exact_id_matches ),
				'coverage' => [
					'complete' => true,
					'source'   => 'exact_id',
				],
			];
		}

		$exact_slug_matches = array_values(
			array_filter(
				$matches,
				static function( $match ) {
					return ( $match['_source'] ?? '' ) === 'exact_slug' && ( $match['_rank'] ?? '' ) === 'exact';
				}
			)
		);

		if ( ! $slug_lookup_complete || ! empty( $exact_slug_matches ) ) {
			return [
				'matches'  => self::sort_matches( $exact_slug_matches ),
				'coverage' => [
					'complete'           => $slug_lookup_complete,
					'source'             => 'exact_slug',
					'slugLookupComplete' => $slug_lookup_complete,
				],
			];
		}

		$repository_matches = array_values(
			array_filter(
				$matches,
				static function( $match ) {
					return ( $match['_source'] ?? '' ) === 'repository';
				}
			)
		);

		return [
			'matches'  => self::sort_matches( $repository_matches ),
			'coverage' => [
				'complete'   => empty( $repository['documents']['hasMore'] ),
				'source'     => 'repository',
				'scanned'    => (int) ( $repository['documents']['scanned'] ?? 0 ),
				'nextCursor' => $repository['documents']['nextCursor'] ?? null,
			],
		];
	}

	/**
	 * Find design summaries through the bounded repository index.
	 *
	 * @param string $query Normalized query.
	 * @param mixed  $kinds Requested resource kinds.
	 * @return array|\WP_Error
	 */
	private static function resolve_design_matches( string $query, $kinds ) {
		$kinds = is_array( $kinds ) ? array_values( array_unique( array_map( 'strval', $kinds ) ) ) : [];

		if ( empty( $kinds ) || ! empty( array_diff( $kinds, self::resource_kinds() ) ) ) {
			return Error::invalid_param( 'resourceKinds', 'one or more supported design resource kinds', $kinds );
		}

		$key_map          = [
			'globalClass'    => 'globalClasses',
			'globalVariable' => 'globalVariables',
			'themeStyle'     => 'themeStyles',
			'component'      => 'components',
		];
		$repository_query = self::design_repository_query( $query );
		$repository       = self::execute_ability(
			'bricks/checkout-site-repository',
			[
				'includeDocuments' => false,
				'includeDesign'    => true,
				'designKinds'      => array_values(
					array_map(
						static function( $kind ) use ( $key_map ) {
							return $key_map[ $kind ]; },
						$kinds
					)
				),
				// Design summaries do not contain their generated virtual path.
				// Search by the path tail, then rank against the canonical path.
				'query'            => $repository_query,
			]
		);

		if ( is_wp_error( $repository ) ) {
			return $repository;
		}

		$matches   = [];
		$truncated = [];

		foreach ( $kinds as $resource ) {
			$context_key = $key_map[ $resource ];
			if ( isset( $repository['design']['truncated'][ $context_key ] ) ) {
				$truncated[ $context_key ] = $repository['design']['truncated'][ $context_key ];
			}

			foreach ( (array) ( $repository['design'][ $context_key ] ?? [] ) as $item ) {
				$id = (string) ( $item['id'] ?? '' );

				if ( $id === '' || is_wp_error(
					Design_Workspace::checkout_permission(
						[
							'resource' => $resource,
							'id'       => $id
						]
					)
				) ) {
					continue;
				}

				$path = self::design_path( $resource, $id );
				$rank = self::match_rank( $query, [ $path, $id, $item['name'] ?? '', $item['label'] ?? '' ] );

				if ( $rank === null ) {
					continue;
				}

				$matches[] = [
					'_rank'    => $rank,
					'target'   => [
						'scope'    => 'design',
						'resource' => $resource,
						'id'       => $id
					],
					'path'     => $path,
					'label'    => (string) ( $item['name'] ?? $item['label'] ?? $id ),
					'resource' => $resource,
				];
			}
		}

		return [
			'matches'  => self::sort_matches( $matches ),
			'coverage' => [
				'complete'  => empty( $truncated ),
				'truncated' => $truncated,
			],
		];
	}

	/**
	 * Focused canonical checkout through registered policy shims.
	 *
	 * @param array $target Target.
	 * @return array|\WP_Error
	 */
	private static function checkout_target( array $target ) {
		$response = $target['scope'] === 'page'
			? self::execute_ability( 'bricks/checkout-page-workspace', [ 'postId' => $target['postId'] ] )
			: self::execute_ability(
				'bricks/checkout-design-resource-workspace',
				[
					'resource' => $target['resource'],
					'id'       => $target['id']
				]
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$path = (string) ( $response['manifest']['editableFile'] ?? '' );

		if ( $path === '' || ! is_array( $response['files'][ $path ] ?? null ) ) {
			return Error::internal_error( 'agent-file-checkout', 'Focused checkout did not return one canonical editable file.' );
		}

		if ( $target['scope'] === 'page' ) {
			$post = get_post( (int) $target['postId'] );
			if ( ! $post instanceof \WP_Post ) {
				return Error::internal_error( 'agent-file-checkout', 'The checked-out page has no canonical repository path.' );
			}
			$canonical_path = self::page_path( $post );
		} else {
			$canonical_path = $path;
		}

		return [
			'path'     => $canonical_path,
			'document' => $response['files'][ $path ]
		];
	}

	/**
	 * Canonical virtual-repository path for a page or template.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function page_path( \WP_Post $post ): string {
		$is_template = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) && $post->post_type === BRICKS_DB_TEMPLATE_SLUG;

		return $is_template
			? sprintf( 'templates/%d.bricks.json', (int) $post->ID )
			: sprintf( 'content/%s/%d.bricks.json', $post->post_type, (int) $post->ID );
	}

	/**
	 * Execute a registered ability so the admin deny-list remains authoritative.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function execute_ability( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

		if ( ! is_object( $ability ) || ! is_callable( [ $ability, 'execute' ] ) ) {
			return Error::internal_error( 'agent-file-ability', 'A required focused ability is unavailable.', [ 'ability' => $name ] );
		}

		return $ability->execute( $input );
	}

	/**
	 * Exact or prefix match rank.
	 *
	 * @param string $query  Normalized query.
	 * @param array  $values Candidate values.
	 * @return string|null
	 */
	private static function match_rank( string $query, array $values ) {
		$prefix = false;

		foreach ( $values as $value ) {
			$value = self::normalize_match_value( (string) $value );

			if ( $value === $query ) {
				return 'exact';
			}

			if ( $value !== '' && strpos( $value, $query ) === 0 ) {
				$prefix = true;
			}
		}

		return $prefix ? 'prefix' : null;
	}

	/**
	 * Normalize match text without fuzzy ranking.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	private static function normalize_match_value( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );

		return strtolower( is_string( $value ) ? $value : '' );
	}

	/**
	 * Extract a known post ID or virtual-file post ID from a query.
	 *
	 * @param string $query Normalized query.
	 * @return int
	 */
	private static function post_id_from_query( string $query ): int {
		if ( ctype_digit( $query ) ) {
			return (int) $query;
		}

		return preg_match( '#(?:content/[^/]+|templates)/(\d+)\.bricks\.json$#', $query, $matches ) ? (int) $matches[1] : 0;
	}

	/**
	 * Convert a canonical design path to a searchable summary token.
	 *
	 * @param string $query Normalized query.
	 * @return string
	 */
	private static function design_repository_query( string $query ): string {
		if ( ! preg_match( '#^design/(?:classes|variables|theme-styles|components)/(.*)$#', $query, $matches ) ) {
			return $query;
		}

		$tail = preg_replace( '/\.bricks\.json$/', '', (string) $matches[1] );

		return rawurldecode( is_string( $tail ) ? $tail : '' );
	}

	/**
	 * Stable sort matches.
	 *
	 * @param array $matches Matches.
	 * @return array
	 */
	private static function sort_matches( array $matches ): array {
		usort(
			$matches,
			static function( array $left, array $right ): int {
				$rank = ( $left['_rank'] === 'exact' ? 0 : 1 ) <=> ( $right['_rank'] === 'exact' ? 0 : 1 );
				return $rank !== 0 ? $rank : strcmp( (string) $left['path'], (string) $right['path'] );
			}
		);

		return $matches;
	}

	/**
	 * Supported design resources.
	 *
	 * @return string[]
	 */
	private static function resource_kinds(): array {
		return [ 'globalClass', 'globalVariable', 'themeStyle', 'component' ];
	}

	/**
	 * Canonical design path.
	 *
	 * @param string $resource Resource.
	 * @param string $id       ID.
	 * @return string
	 */
	private static function design_path( string $resource, string $id ): string {
		$directories = [
			'globalClass'    => 'classes',
			'globalVariable' => 'variables',
			'themeStyle'     => 'theme-styles',
			'component'      => 'components',
		];

		return sprintf( 'design/%s/%s.bricks.json', $directories[ $resource ], rawurlencode( $id ) );
	}

	/**
	 * Focused route for an oversize file.
	 *
	 * @param array $target Target.
	 * @return array
	 */
	private static function focused_routes( array $target ): array {
		return $target['scope'] === 'page'
			? [
				'checkout' => 'bricks/checkout-page-workspace',
				'preview'  => 'bricks/preview-page-workspace',
				'apply'    => 'bricks/apply-page-workspace'
			]
			: [
				'checkout' => 'bricks/checkout-design-resource-workspace',
				'preview'  => 'bricks/preview-design-resource-workspace',
				'apply'    => 'bricks/apply-design-resource-workspace'
			];
	}

	/**
	 * JSON byte count.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function encoded_bytes( $value ): int {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
	}

	/**
	 * Stable JSON digest.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}

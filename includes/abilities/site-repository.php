<?php
/**
 * Agent site repository manifest.
 *
 * Gives file-oriented coding agents one bounded index of the editable Bricks
 * site without loading every canonical document into the prompt.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only repository discovery for pages, templates, and design resources.
 */
class Site_Repository {
	const CONTRACT_VERSION = '1';

	/**
	 * Input schema for checkout-site-repository.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function checkout_schema() {
		return [
			'type'                 => 'object',
			'properties'           => [
				'cursor'              => [
					'type'        => 'string',
					'description' => __( 'Opaque continuation cursor returned by the previous manifest call.', 'bricks' ),
				],
				'perPage'             => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => __( 'Documents per index page. Default 100, maximum 200.', 'bricks' ),
				],
				'postTypes'           => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => __( 'Optional subset of Bricks-enabled post types. Templates use the bricks_template post type.', 'bricks' ),
				],
				'includeDependencies' => [
					'type'        => 'boolean',
					'description' => __( 'Include class, variable, and component references for each indexed document. Default true.', 'bricks' ),
				],
				'includeDocuments'    => [
					'type'        => 'boolean',
					'description' => __( 'Include page/template summaries. Set false for a design-resource-only lookup. Default true.', 'bricks' ),
				],
				'includeDesign'       => [
					'type'        => 'boolean',
					'description' => __( 'Include the bounded design-system manifest. Default true and requires design-system access.', 'bricks' ),
				],
				'designKinds'         => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => [ 'colorPalettes', 'globalClasses', 'themeStyles', 'components', 'globalVariables', 'variableCategories', 'breakpoints' ],
					],
					'description' => __( 'Optional design-summary kinds to return. Ownership/version metadata is always preserved.', 'bricks' ),
				],
				'query'               => [
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Optional case-insensitive title search for documents and ID/name/label search for design summaries. Use a known post ID directly with the page workspace.', 'bricks' ),
				],
			],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for checkout-site-repository.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function checkout_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'contractVersion' => [ 'type' => 'string' ],
				'root'            => [ 'type' => 'string' ],
				'documents'       => [ 'type' => 'object' ],
				'design'          => [ 'type' => [ 'object', 'null' ] ],
				'routes'          => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * Permission callback for checkout-site-repository.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function checkout_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return Error::forbidden_builder_permission( 'edit_posts' );
		}

		if ( ! array_key_exists( 'includeDesign', $input ) || ! empty( $input['includeDesign'] ) ) {
			return Design::get_design_context_permission( [] );
		}

		return true;
	}

	/**
	 * Build a bounded repository index without returning complete page trees.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function checkout( $input ) {
		$per_page             = max( 1, min( 200, (int) ( $input['perPage'] ?? 100 ) ) );
		$include_dependencies = ! array_key_exists( 'includeDependencies', $input ) || ! empty( $input['includeDependencies'] );
		$include_documents    = ! array_key_exists( 'includeDocuments', $input ) || ! empty( $input['includeDocuments'] );
		$include_design       = ! array_key_exists( 'includeDesign', $input ) || ! empty( $input['includeDesign'] );
		$query                = trim( sanitize_text_field( (string) ( $input['query'] ?? '' ) ) );
		$allowed_design_kinds = [ 'colorPalettes', 'globalClasses', 'themeStyles', 'components', 'globalVariables', 'variableCategories', 'breakpoints' ];
		$design_kinds         = isset( $input['designKinds'] ) && is_array( $input['designKinds'] )
			? array_values( array_intersect( $allowed_design_kinds, array_map( 'strval', $input['designKinds'] ) ) )
			: $allowed_design_kinds;
		$allowed_post_types   = Input_Resolver::default_allow_list();
		$requested_post_types = isset( $input['postTypes'] ) && is_array( $input['postTypes'] )
			? array_values( array_unique( array_map( 'sanitize_key', $input['postTypes'] ) ) )
			: $allowed_post_types;
		$post_types           = array_values( array_intersect( $requested_post_types, $allowed_post_types ) );

		if ( empty( $post_types ) ) {
			return Error::invalid_param( 'postTypes', 'one or more Bricks-enabled post types', $requested_post_types );
		}

		$post_statuses  = [];
		$query_identity = self::digest( [ $post_types, $query ] );
		$scan_offset    = 0;
		$scanned        = 0;
		$has_more       = false;
		$posts          = [];

		if ( $include_documents ) {
			$post_statuses  = array_values(
				array_diff(
					get_post_stati( [], 'names' ),
					[ 'trash', 'auto-draft', 'inherit' ]
				)
			);
			$query_identity = self::digest( [ $post_types, $post_statuses, $query ] );
			$scan_offset    = self::decode_cursor( (string) ( $input['cursor'] ?? '' ), $query_identity );

			if ( is_wp_error( $scan_offset ) ) {
				return $scan_offset;
			}

			$scan_limit       = 1000;
			$scan_batch       = min( 200, max( 50, $per_page * 2 ) );
			$scanned          = 0;
			$accessible_ids   = [];
			$accessible_count = 0;
			$has_more         = false;

			do {
				$id_query = new \WP_Query(
					[
						'post_type'              => $post_types,
						'post_status'            => $post_statuses,
						's'                      => $query,
						'posts_per_page'         => $scan_batch, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Internally capped at 200.
						'offset'                 => $scan_offset,
						'fields'                 => 'ids',
						'orderby'                => [
							'post_type' => 'ASC',
							'title'     => 'ASC',
							'ID'        => 'ASC'
						],
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'no_found_rows'          => true,
					]
				);
				$raw_ids  = array_map( 'intval', $id_query->posts );

				foreach ( $raw_ids as $post_id ) {
					++$scan_offset;
					++$scanned;

					if (
						current_user_can( 'edit_post', $post_id ) &&
						\Bricks\Capabilities::current_user_can_use_builder( $post_id )
					) {
						if ( $accessible_count === $per_page ) {
							--$scan_offset;
							$has_more = true;
							break 2;
						}

						$accessible_ids[] = $post_id;
						++$accessible_count;
					}

					if ( $scanned >= $scan_limit ) {
						$has_more = count( $raw_ids ) === $scan_batch;
						break 2;
					}
				}

				if ( count( $raw_ids ) < $scan_batch ) {
					$has_more = false;
					break;
				}

				$has_more = true;
			} while ( $accessible_count < $per_page && $scanned < $scan_limit );

			$page_ids = $accessible_ids;
			$posts    = empty( $page_ids )
			? []
			: get_posts(
				[
					'post__in'               => $page_ids,
					'post_type'              => $post_types,
					'post_status'            => $post_statuses,
					'posts_per_page'         => count( $page_ids ), // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Public input is schema-capped at 200.
					'orderby'                => 'post__in',
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				]
			);
		}

		$documents = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$area        = Elements::get_save_area_for_post( $post->ID );
			$elements    = \Bricks\Database::get_data( $post->ID, $area );
			$elements    = is_array( $elements ) ? $elements : [];
			$is_template = defined( 'BRICKS_DB_TEMPLATE_SLUG' ) && $post->post_type === BRICKS_DB_TEMPLATE_SLUG;
			$file_path   = $is_template
				? sprintf( 'templates/%d.bricks.json', $post->ID )
				: sprintf( 'content/%s/%d.bricks.json', $post->post_type, $post->ID );

			$document = [
				'file'           => $file_path,
				'postId'         => (int) $post->ID,
				'postType'       => $post->post_type,
				'title'          => $post->post_title,
				'slug'           => $post->post_name,
				'status'         => $post->post_status,
				'area'           => $area,
				'elementCount'   => count( $elements ),
				'documentDigest' => Save_Pipeline::document_digest( $elements ),
				'modifiedGmt'    => $post->post_modified_gmt,
			];

			if ( $is_template ) {
				$document['templateType'] = (string) get_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, true );
			}

			if ( $include_dependencies ) {
				$document['dependencies']       = self::dependency_index( $elements );
				$document['dependencyCoverage'] = [
					'scope'        => 'returnedDocument',
					'completeness' => 'bestEffort',
					'note'         => 'Scans serialized element records for direct class, variable, and component references. It is a discovery hint, not proof that a resource is unused or safe to delete.',
				];
			}

			$documents[] = $document;
		}

		$design = null;

		if ( $include_design ) {
			$design = Design::get_design_context(
				[
					'includeUsage'   => false,
					// Filtering must happen before truncation so a known target after
					// the first 200 resources remains discoverable by ID/name query.
					'responseFormat' => 'detailed',
				]
			);

			if ( is_wp_error( $design ) ) {
				return $design;
			}

			$design = self::filter_design_context( $design, $design_kinds, $query, 200 );
		}

		return [
			'contractVersion' => self::CONTRACT_VERSION,
			'root'            => 'site.bricks',
			'documents'       => [
				'items'      => $documents,
				'total'      => null,
				'totalKnown' => false,
				'perPage'    => $per_page,
				'scanned'    => $scanned,
				'hasMore'    => $has_more,
				'nextCursor' => $has_more ? self::encode_cursor( $scan_offset, $query_identity ) : null,
			],
			'design'          => $design,
			'routes'          => [
				'document'        => [
					'checkout' => 'bricks/checkout-page-workspace',
					'preview'  => 'bricks/preview-page-workspace',
					'apply'    => 'bricks/apply-page-workspace',
				],
				'designResource'  => [
					'checkout' => 'bricks/checkout-design-resource-workspace',
					'preview'  => 'bricks/preview-design-resource-workspace',
					'apply'    => 'bricks/apply-design-resource-workspace',
				],
				'changeset'       => [
					'checkout' => 'bricks/checkout-site-changeset',
					'preview'  => 'bricks/preview-site-changeset',
					'apply'    => 'bricks/apply-site-changeset',
					'resume'   => 'bricks/resume-site-changeset',
					'resolve'  => 'bricks/resolve-site-changeset',
				],
				'semanticEditing' => [
					'exactCommit' => 'bricks/commit-exact-site-edits',
					'checkout'    => 'bricks/checkout-site-edit-map',
					'commit'      => 'bricks/commit-site-edit-plan',
					'preview'     => 'bricks/preview-site-edit-plan',
					'apply'       => 'bricks/apply-site-changeset',
					'resume'      => 'bricks/resume-site-changeset',
				],
			],
		];
	}

	/**
	 * Encode a bounded traversal continuation.
	 *
	 * @param int    $offset         Raw query offset.
	 * @param string $query_identity Post-type/status identity.
	 * @return string
	 */
	private static function encode_cursor( int $offset, string $query_identity ): string {
		$payload              = [
			'offset' => $offset,
			'query'  => $query_identity,
			'site'   => get_current_blog_id(),
			'user'   => get_current_user_id(),
		];
		$payload['signature'] = self::sign_cursor_payload( $payload );

		return base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination cursor, not security or obfuscation.
			(string) wp_json_encode( $payload )
		);
	}

	/**
	 * Decode and bind a traversal cursor to the current query.
	 *
	 * @param string $cursor         Opaque cursor.
	 * @param string $query_identity Post-type/status identity.
	 * @return int|\WP_Error
	 */
	private static function decode_cursor( string $cursor, string $query_identity ) {
		if ( $cursor === '' ) {
			return 0;
		}

		$decoded = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque pagination cursor, not security or obfuscation.
		$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;

		if (
			! is_array( $payload ) ||
			! isset( $payload['offset'], $payload['query'], $payload['site'], $payload['user'], $payload['signature'] ) ||
			! is_int( $payload['offset'] ) ||
			$payload['offset'] < 0 ||
			! is_string( $payload['query'] ) ||
			! is_int( $payload['site'] ) ||
			! is_int( $payload['user'] ) ||
			! is_string( $payload['signature'] )
		) {
			return Error::invalid_param( 'cursor', 'an unchanged continuation cursor from checkout-site-repository', $cursor );
		}

		$signature = $payload['signature'];
		unset( $payload['signature'] );

		if (
			! hash_equals( $query_identity, $payload['query'] ) ||
			$payload['site'] !== get_current_blog_id() ||
			$payload['user'] !== get_current_user_id() ||
			! hash_equals( self::sign_cursor_payload( $payload ), $signature )
		) {
			return Error::invalid_param( 'cursor', 'an unchanged continuation cursor from checkout-site-repository', $cursor );
		}

		return $payload['offset'];
	}

	/**
	 * Sign a traversal cursor so clients cannot choose database offsets.
	 *
	 * The signed blog and user IDs prevent a cursor from being transferred
	 * between sites or accounts that share the WordPress auth salt.
	 *
	 * @param array $payload Cursor payload without its signature.
	 * @return string
	 */
	private static function sign_cursor_payload( array $payload ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	/**
	 * Build a stable query-identity digest.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( $value ) );
	}

	/**
	 * Reduce design discovery to requested resource kinds and matching summary
	 * rows while retaining authority/version metadata needed for safe checkout.
	 *
	 * @param array  $design Design-context summary.
	 * @param array  $kinds  Requested summary kinds.
	 * @param string $query  Optional text filter.
	 * @param int    $limit  Maximum returned rows per selected kind.
	 * @return array
	 */
	private static function filter_design_context( array $design, array $kinds, string $query, int $limit = 200 ): array {
		$list_keys = [ 'colorPalettes', 'globalClasses', 'themeStyles', 'components', 'globalVariables', 'variableCategories' ];
		$truncated = [];

		foreach ( $list_keys as $key ) {
			if ( ! in_array( $key, $kinds, true ) ) {
				unset( $design[ $key ] );
				continue;
			}

			if ( $query !== '' && is_array( $design[ $key ] ?? null ) ) {
				$design[ $key ] = array_values(
					array_filter(
						$design[ $key ],
						static function( $item ) use ( $query ) {
							return is_array( $item ) && stripos( (string) wp_json_encode( $item ), $query ) !== false;
						}
					)
				);
			}

			if ( is_array( $design[ $key ] ?? null ) && count( $design[ $key ] ) > $limit ) {
				$truncated[ $key ] = [
					'total' => count( $design[ $key ] ),
					'limit' => $limit,
				];
				$design[ $key ]    = array_slice( $design[ $key ], 0, $limit );
			}
		}

		if ( ! in_array( 'breakpoints', $kinds, true ) ) {
			unset( $design['breakpoints'] );
		}

		if ( empty( $truncated ) ) {
			unset( $design['truncated'] );
		} else {
			$design['truncated'] = $truncated;
		}

		$design['selection'] = [
			'kinds' => $kinds,
			'query' => $query,
		];

		return $design;
	}

	/**
	 * Extract stable resource references without copying resource payloads.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Canonical flat Bricks element tree.
	 * @return array
	 */
	private static function dependency_index( array $elements ): array {
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
					$class_ids[ $class_id ] = true;
				}
			}

			$component_id = (string) ( $element['cid'] ?? $element['componentId'] ?? $settings['componentId'] ?? '' );

			if ( $component_id !== '' ) {
				$component_ids[ $component_id ] = true;
			}

			$encoded = (string) wp_json_encode( $element );

			if ( preg_match_all( '/var\\(\\s*--([a-zA-Z0-9_-]+)/', $encoded, $matches ) ) {
				foreach ( $matches[1] as $variable_name ) {
					$variable_names[ $variable_name ] = true;
				}
			}
		}

		return [
			'globalClassIds'      => array_keys( $class_ids ),
			'globalVariableNames' => array_keys( $variable_names ),
			'componentIds'        => array_keys( $component_ids ),
		];
	}
}

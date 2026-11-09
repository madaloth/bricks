<?php
/**
 * Greenfield site foundation ability.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinate the durable resources that turn one page into a real Bricks site.
 *
 * The operation is a resumable saga rather than a false cross-table transaction:
 * each authoritative sub-write retains its native concurrency checks and the
 * journal records the last verified step for same-key recovery.
 *
 * @since 2.4
 */
class Site_Foundation {
	const JOURNAL_PREFIX = 'bricks_agent_site_foundation_';
	const LOCK_PREFIX    = 'bricks_sf_';

	/**
	 * Input schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function commit_schema(): array {
		$document = [
			'type'                 => 'object',
			'properties'           => [
				'title' => [ 'type' => 'string' ],
				'html'  => [ 'type' => 'string' ],
				'css'   => [ 'type' => 'string' ],
			],
			'required'             => [ 'title', 'html' ],
			'additionalProperties' => false,
		];

		return [
			'type'                 => 'object',
			'properties'           => [
				'idempotencyKey' => [
					'type'        => 'string',
					'maxLength'   => 128,
					'description' => __( 'Stable key for this exact foundation intent. Exact retries resume from the last verified resource.', 'bricks' ),
				],
				'palette'        => [
					'type'                 => 'object',
					'properties'           => [
						'name'   => [ 'type' => 'string' ],
						'colors' => [
							'type'        => 'array',
							'description' => __( 'Five or more semantic root colors. Each row is { name, value }; name becomes a Bricks color variable.', 'bricks' ),
							'items'       => [
								'type'                 => 'object',
								'properties'           => [
									'name'  => [
										'type'        => 'string',
										'description' => __( 'Semantic color name, for example Ink or Surface muted. It is canonicalized to a lowercase CSS variable such as --ink or --surface-muted; use that variable in document CSS and do not redeclare it in :root.', 'bricks' ),
									],
									'value' => [ 'type' => 'string' ],
								],
								'required'             => [ 'name', 'value' ],
								'additionalProperties' => false,
							],
						],
					],
					'required'             => [ 'name', 'colors' ],
					'additionalProperties' => false,
				],
				'typography'     => [
					'type'                 => 'object',
					'properties'           => [
						'bodyFontFamily'    => [ 'type' => 'string' ],
						'headingFontFamily' => [ 'type' => 'string' ],
						'rootFontSize'      => [
							'type'        => 'string',
							'description' => __( 'CSS html font-size basis. Default 62.5%.', 'bricks' ),
						],
					],
					'additionalProperties' => false,
				],
				'header'         => $document,
				'footer'         => $document,
				'home'           => array_merge(
					$document,
					[
						'properties' => array_merge(
							$document['properties'],
							[ 'slug' => [ 'type' => 'string' ] ]
						),
					]
				),
			],
			'required'             => [ 'idempotencyKey', 'palette', 'header', 'footer', 'home' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function commit_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'workflow'         => [ 'type' => 'string' ],
				'transactionState' => [ 'type' => 'string' ],
				'completedSteps'   => [ 'type' => 'array' ],
				'resources'        => [ 'type' => 'object' ],
				'replayed'         => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * Permission callback.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function commit_permission( $input ) {
		foreach ( [ 'manage_options', 'publish_pages', 'bricks_full_access' ] as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return Error::forbidden_builder_permission( $capability );
			}
		}

		if ( ! \Bricks\Builder_Permissions::user_has_permission( 'create_templates' ) ) {
			return Error::forbidden_builder_permission( 'create_templates' );
		}

		return true;
	}

	/**
	 * Create or resume one complete greenfield site foundation.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function commit( $input ) {
		$input      = self::normalize_manifest( $input );
		$validation = self::validate_input( $input );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$key            = trim( (string) $input['idempotencyKey'] );
		$request_digest = hash( 'sha256', (string) wp_json_encode( $input ) );
		$journal_key    = self::journal_key( $key );
		$journal        = get_option( $journal_key, null );

		if (
			is_array( $journal ) &&
			( $journal['state'] ?? '' ) === 'committed' &&
			hash_equals( (string) ( $journal['requestDigest'] ?? '' ), $request_digest )
		) {
			return self::response( $journal, true );
		}

		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			// The site lock may have been held by a request that advanced this
			// journal while this caller waited. Only state read under the lock is
			// safe to use for replay, repair, or step execution.
			self::flush_option_cache( $journal_key );
			$journal   = get_option( $journal_key, null );
			$is_repair = false;

			if ( is_array( $journal ) && ! hash_equals( (string) ( $journal['requestDigest'] ?? '' ), $request_digest ) ) {
				$repair = self::validate_partial_repair( $input, $journal );
				if ( is_wp_error( $repair ) ) {
					return $repair;
				}

				$is_repair = true;
			}

			if ( is_array( $journal ) && ( $journal['state'] ?? '' ) === 'committed' ) {
				return self::response( $journal, true );
			}

			if ( $is_repair ) {
				$journal['requestDigest'] = $request_digest;
				$journal['inputDigests']  = self::input_digests( $input );
				$journal['state']         = 'running';
				unset( $journal['lastError'] );
				if ( ! self::save_journal( $journal_key, $journal ) ) {
					return self::journal_write_error();
				}
			}

			if ( ! is_array( $journal ) ) {
				$context = Design::get_design_context(
					[
						'responseFormat' => 'summary',
						'limit'          => 25
					]
				);
				if ( is_wp_error( $context ) ) {
					return $context;
				}

				$palette_summaries        = is_array( $context['colorPalettes'] ?? null ) ? $context['colorPalettes'] : [];
				$has_only_default_palette = count( $palette_summaries ) === 1 && ( $palette_summaries[0]['name'] ?? '' ) === 'Default';
				$occupied                 = array_filter(
					[
						'colorPalettes'      => $has_only_default_palette ? 0 : (int) ( $context['counts']['colorPalettes'] ?? 0 ),
						'globalVariables'    => (int) ( $context['counts']['globalVariables'] ?? 0 ),
						'variableCategories' => (int) ( $context['counts']['variableCategories'] ?? 0 ),
						'themeStyles'        => (int) ( $context['counts']['themeStyles'] ?? 0 ),
					]
				);

				if ( ! empty( $occupied ) ) {
					return Error::conflict(
						'site_foundation_not_greenfield',
						[
							'message' => 'A saved design foundation already exists. Use focused design and template abilities so existing conventions are preserved.',
							'counts'  => $occupied,
						]
					);
				}

				$journal = [
					'state'         => 'running',
					'requestDigest' => $request_digest,
					'inputDigests'  => self::input_digests( $input ),
					'createdAt'     => time(),
					'steps'         => [],
				];
				if ( ! self::save_journal( $journal_key, $journal ) ) {
					return self::journal_write_error();
				}
			}

			$result = self::run( $input, $key, $journal_key, $journal, $lock );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$journal['state']       = 'committed';
			$journal['completedAt'] = time();
			if ( ! self::save_journal( $journal_key, $journal ) ) {
				return self::journal_write_error();
			}

			return self::response( $journal, false );
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Execute the ordered saga.
	 *
	 * @param array  $input       Valid input.
	 * @param string $key         Foundation idempotency key.
	 * @param string $journal_key Journal option key.
	 * @param array  $journal     Journal, by reference.
	 * @param array  $lock        Site lock, by reference.
	 * @return true|\WP_Error
	 */
	private static function run( array $input, string $key, string $journal_key, array &$journal, array &$lock ) {
		$palette = self::step(
			'palette',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $input ) {
				$read = Design::list_color_palettes( [ 'perPage' => 100 ] );
				if ( is_wp_error( $read ) ) {
					return $read;
				}

				$colors = array_map(
					static function ( $color ) {
						return [
							'light' => (string) $color['value'],
							'raw'   => 'var(--' . (string) $color['name'] . ')',
						];
					},
					$input['palette']['colors']
				);

				return Design::create_color_palette(
					[
						'name'              => (string) $input['palette']['name'],
						'colors'            => $colors,
						'expectedOwnership' => $read['ownership'],
					]
				);
			}
		);
		if ( is_wp_error( $palette ) ) {
			return self::fail( 'palette', $palette, $journal_key, $journal );
		}

		$color_names  = array_column( $input['palette']['colors'], 'name' );
		$ink          = in_array( 'ink', $color_names, true ) ? 'ink' : (string) $color_names[0];
		$primary      = in_array( 'primary', $color_names, true ) ? 'primary' : (string) $color_names[0];
		$body_font    = trim( (string) ( $input['typography']['bodyFontFamily'] ?? '' ) );
		$heading_font = trim( (string) ( $input['typography']['headingFontFamily'] ?? $body_font ) );
		$root_size    = trim( (string) ( $input['typography']['rootFontSize'] ?? '62.5%' ) );
		$root_size_px = self::css_root_size_px( $root_size );

		$theme_style = self::step(
			'themeStyle',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $input, $ink, $primary, $body_font, $heading_font, $root_size ) {
				$body = [
					'color'       => [ 'raw' => "var(--{$ink})" ],
					'font-size'   => 'var(--text-m)',
					'line-height' => '1.6',
				];
				if ( $body_font !== '' ) {
					$body['font-family'] = $body_font;
				}

				$headings = [ 'color' => [ 'raw' => "var(--{$ink})" ] ];
				if ( $heading_font !== '' ) {
					$headings['font-family'] = $heading_font;
				}

				return Design::create_theme_style(
					[
						'label'      => (string) $input['palette']['name'] . ' root',
						'conditions' => [ [ 'main' => 'any' ] ],
						'settings'   => [
							'typography' => [
								'typographyHtml'      => $root_size,
								'typographyBody'      => $body,
								'typographyHeadings'  => $headings,
								'typographyHeadingH1' => [ 'font-size' => 'var(--text-4xl)' ],
								'typographyHeadingH2' => [ 'font-size' => 'var(--text-3xl)' ],
							],
							'button'     => [ 'background' => [ 'raw' => "var(--{$primary})" ] ],
						],
					]
				);
			}
		);
		if ( is_wp_error( $theme_style ) ) {
			return self::fail( 'themeStyle', $theme_style, $journal_key, $journal );
		}

		$categories = self::step(
			'scaleCategories',
			$journal_key,
			$journal,
			$lock,
			static function () {
				$read = Design::list_global_variables( [ 'perPage' => 500 ] );
				if ( is_wp_error( $read ) ) {
					return $read;
				}

				$spacing_id    = \Bricks\Helpers::generate_random_id( false );
				$typography_id = \Bricks\Helpers::generate_random_id( false );
				$categories    = [
					self::scale_category( $spacing_id, 'Spacing', 'spacing', 'space-', [ '2xs', 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl' ], 1.25, 1.333 ),
					self::scale_category( $typography_id, 'Typography', 'typography', 'text-', [ 'xs', 's', 'm', 'l', 'xl', '2xl', '3xl', '4xl' ], 1.2, 1.333 ),
				];

				$result = Design::set_global_variable_categories(
					[
						'categories'                => $categories,
						'expectedOwnership'         => $read['categoryOwnership'],
						'expectedVariableOwnership' => $read['variableOwnership'],
					]
				);
				if ( ! is_wp_error( $result ) ) {
					$result['spacingId']    = $spacing_id;
					$result['typographyId'] = $typography_id;
				}
				return $result;
			}
		);
		if ( is_wp_error( $categories ) ) {
			return self::fail( 'scaleCategories', $categories, $journal_key, $journal );
		}

		$scales = self::step(
			'scaleVariables',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $categories ) {
				$spacing = Design::generate_scale_variables(
					[
						'categoryId' => $categories['spacingId'],
						'scaleRange' => [
							'from' => -3,
							'to'   => 4
						],
					]
				);
				if ( is_wp_error( $spacing ) ) {
					return $spacing;
				}

				$typography = Design::generate_scale_variables(
					[
						'categoryId' => $categories['typographyId'],
						'scaleRange' => [
							'from' => -2,
							'to'   => 5
						],
					]
				);
				if ( is_wp_error( $typography ) ) {
					return $typography;
				}

				return Design::set_global_variables(
					[
						'variables'                 => array_merge( $spacing['variables'], $typography['variables'] ),
						'expectedVariableOwnership' => $spacing['saveOwnership']['variableOwnership'],
						'expectedCategoryOwnership' => $spacing['saveOwnership']['categoryOwnership'],
					]
				);
			}
		);
		if ( is_wp_error( $scales ) ) {
			return self::fail( 'scaleVariables', $scales, $journal_key, $journal );
		}

		foreach ( [ 'header', 'footer' ] as $type ) {
			$template = self::step(
				"{$type}Template",
				$journal_key,
				$journal,
				$lock,
				static function () use ( $input, $type ) {
					return Templates::create_template(
						[
							'title'  => (string) $input[ $type ]['title'],
							'type'   => $type,
							'status' => 'publish',
						]
					);
				}
			);
			if ( is_wp_error( $template ) ) {
				return self::fail( "{$type}Template", $template, $journal_key, $journal );
			}

			$import = self::step(
				"{$type}Import",
				$journal_key,
				$journal,
				$lock,
				static function () use ( $input, $type, $template, $key, $root_size_px ) {
					return self::import_document( (int) $template['templateId'], $input[ $type ], "{$key}-{$type}", 'template-content', $root_size_px, true );
				}
			);
			if ( is_wp_error( $import ) ) {
				return self::fail( "{$type}Import", $import, $journal_key, $journal );
			}

			$conditions = self::step(
				"{$type}Conditions",
				$journal_key,
				$journal,
				$lock,
				static function () use ( $template ) {
					return Templates::set_template_conditions(
						[
							'templateId' => (int) $template['templateId'],
							'conditions' => [ [ 'main' => 'any' ] ],
						]
					);
				}
			);
			if ( is_wp_error( $conditions ) ) {
				return self::fail( "{$type}Conditions", $conditions, $journal_key, $journal );
			}
		}

		$home = self::step(
			'homePage',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $input ) {
				return Content::create_post(
					[
						'title'    => (string) $input['home']['title'],
						'slug'     => (string) ( $input['home']['slug'] ?? 'home' ),
						'postType' => 'page',
						'status'   => 'publish',
					]
				);
			}
		);
		if ( is_wp_error( $home ) ) {
			return self::fail( 'homePage', $home, $journal_key, $journal );
		}

		$home_import = self::step(
			'homeImport',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $input, $home, $key, $root_size_px ) {
				return self::import_document( (int) $home['postId'], $input['home'], "{$key}-home", 'page-content', $root_size_px, true );
			}
		);
		if ( is_wp_error( $home_import ) ) {
			return self::fail( 'homeImport', $home_import, $journal_key, $journal );
		}

		$reading = self::step(
			'readingSettings',
			$journal_key,
			$journal,
			$lock,
			static function () use ( $home ) {
				return Cms::set_reading_settings(
					[
						'showOnFront' => 'page',
						'pageOnFront' => (int) $home['postId'],
					]
				);
			}
		);
		if ( is_wp_error( $reading ) ) {
			return self::fail( 'readingSettings', $reading, $journal_key, $journal );
		}

		return true;
	}

	/**
	 * Run one idempotent journal step.
	 *
	 * @param string   $name        Step name.
	 * @param string   $journal_key Journal key.
	 * @param array    $journal     Journal, by reference.
	 * @param array    $lock        Site lock, by reference.
	 * @param callable $callback    Step callback.
	 * @return mixed
	 */
	private static function step( string $name, string $journal_key, array &$journal, array &$lock, callable $callback ) {
		$refreshed = self::refresh_lock( $lock );
		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		if ( array_key_exists( $name, $journal['steps'] ) ) {
			return $journal['steps'][ $name ];
		}

		$result    = $callback();
		$refreshed = self::refresh_lock( $lock );
		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$journal['steps'][ $name ] = $result;
		$journal['updatedAt']      = time();
		if ( ! self::save_journal( $journal_key, $journal ) ) {
			return self::journal_write_error();
		}

		return $result;
	}

	/**
	 * Persist a recoverable failure and return a typed conflict.
	 *
	 * @param string    $step        Failed step.
	 * @param \WP_Error $error       Cause.
	 * @param string    $journal_key Journal key.
	 * @param array     $journal     Journal, by reference.
	 * @return \WP_Error
	 */
	private static function fail( string $step, \WP_Error $error, string $journal_key, array &$journal ): \WP_Error {
		if ( in_array( $error->get_error_code(), [ 'site_foundation_lock_lost', 'site_foundation_journal_write_failed' ], true ) ) {
			return $error;
		}

		$journal['state']     = 'partial';
		$journal['lastError'] = [
			'step'    => $step,
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
		];
		$journal['updatedAt'] = time();
		if ( ! self::save_journal( $journal_key, $journal ) ) {
			return self::journal_write_error();
		}

		return Error::conflict(
			'site_foundation_partial',
			[
				'message'        => 'The site foundation stopped after its last verified step. Retry the exact same request and idempotency key to resume; do not create replacement resources manually.',
				'failedStep'     => $step,
				'completedSteps' => array_keys( $journal['steps'] ),
				'cause'          => $journal['lastError'],
			]
		);
	}

	/**
	 * Stop after a journal persistence failure without attempting another write.
	 *
	 * @return \WP_Error
	 */
	private static function journal_write_error(): \WP_Error {
		return Error::conflict(
			'site_foundation_journal_write_failed',
			[
				'message' => 'The site-foundation recovery journal could not be saved. Stop writes and inspect the created resources before retrying.',
			]
		);
	}

	/**
	 * Save a journal and verify the exact value when WordPress reports no change.
	 *
	 * update_option() returns false both for database failures and when the value
	 * is already identical, so a locked authoritative readback distinguishes the
	 * safe no-op case from lost recovery evidence.
	 *
	 * @param string $journal_key Journal option name.
	 * @param array  $journal     Journal value.
	 * @return bool
	 */
	private static function save_journal( string $journal_key, array $journal ): bool {
		if ( update_option( $journal_key, $journal, false ) ) {
			return true;
		}

		self::flush_option_cache( $journal_key );
		return get_option( $journal_key, null ) === $journal;
	}

	/**
	 * Import a page-body document through the authoritative compiler.
	 *
	 * @param int    $post_id         Target post ID.
	 * @param array  $document        Source document.
	 * @param string $idempotency_key Import key.
	 * @param string $purpose         Document purpose.
	 * @param ?float $root_size_px     Authored and target root size.
	 * @param bool   $extract_globals  Whether this document owns global extraction.
	 * @return array|\WP_Error
	 */
	private static function import_document( int $post_id, array $document, string $idempotency_key, string $purpose, ?float $root_size_px, bool $extract_globals ) {
		$html = (string) $document['html'];
		$css  = (string) ( $document['css'] ?? '' );
		if ( $purpose === 'template-content' ) {
			$html = self::normalize_automatic_template_landmark( $html, Elements::get_save_area_for_post( $post_id ) );
		} elseif ( $purpose === 'page-content' ) {
			$normalized = self::normalize_automatic_page_landmark( $html, $css );
			$html       = $normalized['html'];
			$css        = $normalized['css'];
		}
		$document_digest = hash( 'sha256', $html . "\0" . $css );
		$options         = [
			'preserve_html_defaults' => false,
			'create_global_classes'  => $extract_globals,
			'extract_variables'      => $extract_globals,
		];
		if ( $root_size_px !== null ) {
			$options['source_root_font_size_px'] = $root_size_px;
			$options['target_root_font_size_px'] = $root_size_px;
		}

		$result = Workspace::commit_html_css_page_import(
			[
				'postId'          => $post_id,
				'html'            => $html,
				'css'             => $css,
				'documentPurpose' => $purpose,
				'replaceExisting' => false,
				'idempotencyKey'  => self::import_idempotency_key( $idempotency_key, $document_digest ),
				'responseFormat'  => 'summary',
				'options'         => $options,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ( $result['committed'] ?? false ) !== true ) {
			return Error::conflict(
				'site_foundation_import_not_committed',
				[
					'message'          => 'A foundation document produced a review-only preview. No later foundation steps were run.',
					'postId'           => $post_id,
					'transactionState' => $result['transactionState'] ?? null,
					'diagnostics'      => $result['diagnostics'] ?? null,
				]
			);
		}

		return $result;
	}

	/**
	 * Derive a bounded import key without collapsing long foundation keys.
	 *
	 * @param string $idempotency_key Foundation document key.
	 * @param string $document_digest Canonical document digest.
	 * @return string
	 */
	private static function import_idempotency_key( string $idempotency_key, string $document_digest ): string {
		return 'sf-' . hash( 'sha256', $idempotency_key . "\0" . $document_digest );
	}

	/**
	 * Preserve a familiar authored header/footer wrapper without nesting the
	 * semantic landmark that Bricks adds around that template area.
	 *
	 * Only an exact single outer wrapper matching the target area is rewritten.
	 * Attributes and children stay on a neutral div, so class-based styling is
	 * retained and unrelated landmarks continue to fail closed in Workspace.
	 *
	 * @param string $html Source HTML.
	 * @param string $area Bricks template area.
	 * @return string
	 */
	private static function normalize_automatic_template_landmark( string $html, string $area ): string {
		if ( ! in_array( $area, [ 'header', 'footer' ], true ) ) {
			return $html;
		}
		if ( preg_match_all( '/<' . $area . '\b/i', $html ) !== 1 || preg_match_all( '/<\/' . $area . '\s*>/i', $html ) !== 1 ) {
			return $html;
		}

		$pattern = '/^\s*<' . $area . '\b([^>]*)>(.*)<\/' . $area . '>\s*$/is';
		if ( preg_match( $pattern, $html, $matches ) ) {
			return '<div' . $matches[1] . '>' . $matches[2] . '</div>';
		}

		if ( ! self::has_top_level_landmark( $html, $area ) ) {
			return $html;
		}

		return (string) preg_replace(
			'/<' . $area . '\b([^>]*)>(.*?)<\/' . $area . '\s*>/is',
			'<div$1>$2</div>',
			$html,
			1
		);
	}

	/**
	 * Check whether a semantic landmark is a direct fragment child.
	 *
	 * @param string $html Source HTML.
	 * @param string $area Expected landmark.
	 * @return bool
	 */
	private static function has_top_level_landmark( string $html, string $area ): bool {
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
			return false;
		}

		foreach ( $body->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->tagName ) === $area ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a simple CSS root size to pixels for no-op rem normalization.
	 *
	 * @param string $value CSS root font-size.
	 * @return ?float
	 */
	private static function css_root_size_px( string $value ): ?float {
		if ( preg_match( '/^([0-9]+(?:\.[0-9]+)?)px$/i', trim( $value ), $matches ) ) {
			return (float) $matches[1];
		}
		if ( preg_match( '/^([0-9]+(?:\.[0-9]+)?)%$/', trim( $value ), $matches ) ) {
			return 16.0 * (float) $matches[1] / 100.0;
		}

		return null;
	}

	/**
	 * Canonicalize the coding conventions agents naturally use into the exact
	 * Bricks foundation contract before validation or idempotency binding.
	 *
	 * @param mixed $input Raw ability input.
	 * @return mixed
	 */
	private static function normalize_manifest( $input ) {
		if ( ! is_array( $input ) || ! is_array( $input['palette']['colors'] ?? null ) ) {
			return $input;
		}

		$aliases = [];
		$names   = [];
		foreach ( $input['palette']['colors'] as &$color ) {
			$original  = trim( (string) ( $color['name'] ?? '' ) );
			$canonical = strtolower( $original );
			$canonical = preg_replace( '/[^a-z0-9]+/', '-', $canonical );
			$canonical = trim( (string) $canonical, '-' );
			if ( $canonical !== '' && preg_match( '/^[0-9]/', $canonical ) ) {
				$canonical = 'color-' . $canonical;
			}
			$color['name'] = $canonical;
			$names[]       = $canonical;
			$aliases[]     = $original;
		}
		unset( $color );

		$main_id = '';
		if ( preg_match( '/^\s*<main\b([^>]*)>.*<\/main>\s*$/is', (string) ( $input['home']['html'] ?? '' ), $main ) ) {
			if ( preg_match( '/\bid\s*=\s*(["\'])(.*?)\1/is', $main[1], $id_match ) ) {
				$main_id = $id_match[2];
			}
		}

		foreach ( [ 'header', 'footer', 'home' ] as $type ) {
			if ( ! is_array( $input[ $type ] ?? null ) ) {
				continue;
			}

			$html = (string) ( $input[ $type ]['html'] ?? '' );
			if ( $main_id !== '' ) {
				$html = preg_replace(
					'/\bhref\s*=\s*(["\'])#' . preg_quote( $main_id, '/' ) . '\1/i',
					'href="#brx-content"',
					$html
				);
			}
			$input[ $type ]['html'] = $html;

			$css = (string) ( $input[ $type ]['css'] ?? '' );
			foreach ( $aliases as $index => $alias ) {
				$canonical = $names[ $index ];
				if ( $canonical === '' ) {
					continue;
				}
				$css = str_ireplace( '--bricks-color-' . $alias, '--' . $canonical, $css );
				$css = str_ireplace( '--bricks-color-' . $canonical, '--' . $canonical, $css );
				$css = str_ireplace( '--' . $alias, '--' . $canonical, $css );
			}
			$css                   = self::strip_palette_root_declarations( $css, array_filter( $names ) );
			$input[ $type ]['css'] = $css;
		}

		return $input;
	}

	/**
	 * Remove root declarations already represented by native palette variables.
	 *
	 * @param string $css   Document CSS.
	 * @param array  $names Canonical palette variable names.
	 * @return string
	 */
	private static function strip_palette_root_declarations( string $css, array $names ): string {
		return (string) preg_replace_callback(
			'/:root\s*\{([^{}]*)\}/i',
			static function ( $match ) use ( $names ) {
				$kept = [];
				foreach ( explode( ';', $match[1] ) as $declaration ) {
					$trimmed = trim( $declaration );
					if ( $trimmed === '' ) {
						continue;
					}
					$is_palette = false;
					foreach ( $names as $name ) {
						if ( preg_match( '/^--' . preg_quote( $name, '/' ) . '\s*:/i', $trimmed ) ) {
							$is_palette = true;
							break;
						}
					}
					if ( ! $is_palette ) {
						$kept[] = $trimmed;
					}
				}

				return empty( $kept ) ? '' : ':root{' . implode( ';', $kept ) . '}';
			},
			$css
		);
	}

	/**
	 * Remove the page-level main that Bricks already renders as #brx-content.
	 *
	 * Code-oriented agents naturally author a complete main landmark. Keeping it
	 * would produce invalid nested landmarks, while converting it to a div would
	 * leave a redundant layout wrapper. ID and class selectors on that outer main
	 * are therefore retargeted to the existing Bricks landmark before unwrapping.
	 *
	 * @param string $html Source HTML.
	 * @param string $css  Source CSS.
	 * @return array
	 */
	private static function normalize_automatic_page_landmark( string $html, string $css ): array {
		if ( preg_match_all( '/<main\b/i', $html ) !== 1 || preg_match_all( '/<\/main\s*>/i', $html ) !== 1 ) {
			return compact( 'html', 'css' );
		}

		if ( ! preg_match( '/^\s*<main\b([^>]*)>(.*)<\/main>\s*$/is', $html, $matches ) ) {
			return compact( 'html', 'css' );
		}

		$selectors = [];
		if ( preg_match( '/\bid\s*=\s*(["\'])(.*?)\1/is', $matches[1], $id_match ) ) {
			$selectors[] = '#' . $id_match[2];
		}
		if ( preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $matches[1], $class_match ) ) {
			foreach ( preg_split( '/\s+/', trim( $class_match[2] ) ) as $class_name ) {
				if ( $class_name !== '' ) {
					$selectors[] = '.' . $class_name;
				}
			}
		}

		if ( ! empty( $selectors ) ) {
			$css = preg_replace_callback(
				'/(^|[{}])([^{}]+)(?=\{)/s',
				static function ( $rule ) use ( $selectors ) {
					$selector_text = $rule[2];
					if ( strpos( ltrim( $selector_text ), '@' ) === 0 ) {
						return $rule[0];
					}
					foreach ( $selectors as $selector ) {
						$selector_text = preg_replace(
							'/' . preg_quote( $selector, '/' ) . '(?![-_a-zA-Z0-9])/',
							'#brx-content',
							$selector_text
						);
					}
					$selector_text = preg_replace( '/(?:#brx-content){2,}/', '#brx-content', $selector_text );
					return $rule[1] . $selector_text;
				},
				$css
			);
		}

		return [
			'html' => $matches[2],
			'css'  => $css,
		];
	}

	/**
	 * Build a canonical fluid-scale category.
	 *
	 * @param string $id        Category ID.
	 * @param string $name      Display name.
	 * @param string $scope     Scale scope.
	 * @param string $prefix    Variable prefix.
	 * @param array  $names     Scale names.
	 * @param float  $min_ratio Minimum ratio.
	 * @param float  $max_ratio Maximum ratio.
	 * @return array
	 */
	private static function scale_category( string $id, string $name, string $scope, string $prefix, array $names, float $min_ratio, float $max_ratio ): array {
		return [
			'id'    => $id,
			'name'  => $name,
			'scale' => [
				'scaleScope'          => $scope,
				'scaleType'           => 'tshirt',
				'scaleNames'          => $names,
				'prefix'              => $prefix,
				'minFontSize'         => 16,
				'minScaleRatio'       => $min_ratio,
				'minScaleRatioSelect' => $min_ratio,
				'maxFontSize'         => 20,
				'maxScaleRatio'       => $max_ratio,
				'maxScaleRatioSelect' => $max_ratio,
				'baseline'            => 'm',
			],
		];
	}

	/**
	 * Validate the compact manifest before any write.
	 *
	 * @param array $input Input.
	 * @return true|\WP_Error
	 */
	private static function validate_input( $input ) {
		$key = trim( (string) ( $input['idempotencyKey'] ?? '' ) );
		if ( $key === '' ) {
			return Error::missing_param( 'idempotencyKey' );
		}
		if ( strlen( $key ) > 128 ) {
			return Error::invalid_param( 'idempotencyKey', 'a string no longer than 128 characters', $key );
		}

		if ( ! is_array( $input['palette']['colors'] ?? null ) || count( $input['palette']['colors'] ) < 5 ) {
			return Error::invalid_param( 'palette.colors', 'at least five semantic root colors', $input['palette']['colors'] ?? null );
		}

		$names = [];
		foreach ( $input['palette']['colors'] as $index => $color ) {
			$name  = trim( (string) ( $color['name'] ?? '' ) );
			$value = trim( (string) ( $color['value'] ?? '' ) );
			if ( ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) ) {
				return Error::invalid_param( "palette.colors[{$index}].name", 'a lowercase CSS variable name', $name );
			}
			if ( $value === '' ) {
				return Error::invalid_param( "palette.colors[{$index}].value", 'a non-empty CSS color value', $value );
			}
			if ( isset( $names[ $name ] ) ) {
				return Error::conflict_duplicate_name( 'foundation_color', $name );
			}
			$names[ $name ] = true;
		}

		foreach ( [ 'header', 'footer', 'home' ] as $document ) {
			if ( trim( (string) ( $input[ $document ]['title'] ?? '' ) ) === '' ) {
				return Error::missing_param( "{$document}.title" );
			}
			if ( trim( (string) ( $input[ $document ]['html'] ?? '' ) ) === '' ) {
				return Error::missing_param( "{$document}.html" );
			}
		}

		return true;
	}

	/**
	 * Allow a partial saga to replace only input that has not been committed.
	 *
	 * This lets an agent correct a failed conversion without duplicating the
	 * palette, templates, or page already created by earlier verified steps.
	 * Foundation-level design intent is immutable once the saga begins.
	 *
	 * @param array $input   Replacement input.
	 * @param array $journal Existing journal.
	 * @return true|\WP_Error
	 */
	private static function validate_partial_repair( array $input, array $journal ) {
		if ( ( $journal['state'] ?? '' ) !== 'partial' || ! is_array( $journal['inputDigests'] ?? null ) ) {
			return Error::conflict(
				'site_foundation_idempotency_key_reused',
				[ 'message' => 'This idempotency key already belongs to a different site-foundation intent.' ]
			);
		}

		$before = $journal['inputDigests'];
		$after  = self::input_digests( $input );
		$steps  = is_array( $journal['steps'] ?? null ) ? $journal['steps'] : [];
		$locked = [ 'foundation' ];

		foreach ( [ 'header', 'footer' ] as $type ) {
			if ( isset( $steps[ "{$type}Template" ] ) ) {
				$locked[] = "{$type}Identity";
			}
			if ( isset( $steps[ "{$type}Import" ] ) ) {
				$locked[] = "{$type}Document";
			}
		}
		if ( isset( $steps['homePage'] ) ) {
			$locked[] = 'homeIdentity';
		}
		if ( isset( $steps['homeImport'] ) ) {
			$locked[] = 'homeDocument';
		}

		foreach ( $locked as $part ) {
			if ( ! isset( $before[ $part ], $after[ $part ] ) || ! hash_equals( (string) $before[ $part ], (string) $after[ $part ] ) ) {
				return Error::conflict(
					'site_foundation_committed_intent_changed',
					[
						'message' => 'A corrected retry changed foundation input that was already committed. Keep committed resource intent unchanged and edit only the failed or unstarted document.',
						'part'    => $part,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Hash independently repairable input regions without duplicating source
	 * documents inside the recovery journal.
	 *
	 * @param array $input Foundation input.
	 * @return array
	 */
	private static function input_digests( array $input ): array {
		$digest = static function ( $value ) {
			return hash( 'sha256', (string) wp_json_encode( $value ) );
		};

		return [
			'foundation'     => $digest( [ $input['palette'], $input['typography'] ?? [] ] ),
			'headerIdentity' => $digest( [ 'title' => $input['header']['title'] ] ),
			'headerDocument' => $digest(
				[
					'html' => $input['header']['html'],
					'css'  => $input['header']['css'] ?? ''
				]
			),
			'footerIdentity' => $digest( [ 'title' => $input['footer']['title'] ] ),
			'footerDocument' => $digest(
				[
					'html' => $input['footer']['html'],
					'css'  => $input['footer']['css'] ?? ''
				]
			),
			'homeIdentity'   => $digest(
				[
					'title' => $input['home']['title'],
					'slug'  => $input['home']['slug'] ?? 'home'
				]
			),
			'homeDocument'   => $digest(
				[
					'html' => $input['home']['html'],
					'css'  => $input['home']['css'] ?? ''
				]
			),
		];
	}

	/**
	 * Compact authoritative response.
	 *
	 * @param array $journal  Journal.
	 * @param bool  $replayed Whether this is a terminal replay.
	 * @return array
	 */
	private static function response( array $journal, bool $replayed ): array {
		$steps = $journal['steps'] ?? [];
		return [
			'workflow'         => 'site-foundation',
			'transactionState' => (string) ( $journal['state'] ?? 'running' ),
			'completedSteps'   => array_keys( $steps ),
			'resources'        => [
				'paletteId'        => $steps['palette']['palette']['id'] ?? null,
				'themeStyleId'     => $steps['themeStyle']['id'] ?? null,
				'spacingCategory'  => $steps['scaleCategories']['spacingId'] ?? null,
				'typeCategory'     => $steps['scaleCategories']['typographyId'] ?? null,
				'headerTemplateId' => $steps['headerTemplate']['templateId'] ?? null,
				'footerTemplateId' => $steps['footerTemplate']['templateId'] ?? null,
				'homePageId'       => $steps['homePage']['postId'] ?? null,
				'homeUrl'          => $steps['readingSettings']['pageOnFront']['permalink'] ?? ( $steps['homePage']['permalink'] ?? null ),
			],
			'replayed'         => $replayed,
		];
	}

	/**
	 * Journal option key.
	 *
	 * @param string $key Idempotency key.
	 * @return string
	 */
	private static function journal_key( string $key ): string {
		return self::JOURNAL_PREFIX . substr( hash( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . $key ), 0, 40 );
	}

	/**
	 * Site-scoped database-session lock name.
	 *
	 * @return string
	 */
	private static function lock_key(): string {
		global $wpdb;

		$database = defined( 'DB_NAME' ) ? DB_NAME : '';
		$scope    = $database . "\0" . $wpdb->prefix . "\0" . get_current_blog_id();

		return self::LOCK_PREFIX . substr( hash( 'sha256', $scope ), 0, 40 );
	}

	/**
	 * Acquire the site-wide foundation lock.
	 *
	 * The non-expiring option token survives WordPress database reconnects. A
	 * crashed request intentionally leaves the lock in place for explicit admin
	 * recovery; elapsed time is never treated as proof that the owner is dead.
	 *
	 * @return array|\WP_Error
	 */
	private static function acquire_lock() {
		$lock_key = self::lock_key();
		$token    = wp_generate_uuid4();

		self::flush_option_cache( $lock_key );
		if ( add_option( $lock_key, $token, '', 'no' ) ) {
			return [
				'key'   => $lock_key,
				'token' => $token,
			];
		}

		return Error::conflict(
			'site_foundation_busy',
			[
				'message'    => 'Another site-foundation operation is active, or a crashed request left its durable lock. If no request is active, inspect the foundation journal and created resources, then delete this exact option with WP-CLI before retrying.',
				'lockOption' => $lock_key,
			]
		);
	}

	/**
	 * Verify that this request still owns the durable lock token.
	 *
	 * @param array $lock Lock, by reference.
	 * @return true|\WP_Error
	 */
	private static function refresh_lock( array &$lock ) {
		$lock_key = (string) ( $lock['key'] ?? '' );
		self::flush_option_cache( $lock_key );
		$stored_token = get_option( $lock_key, null );
		if ( ! is_string( $stored_token ) || ! hash_equals( (string) ( $lock['token'] ?? '' ), $stored_token ) ) {
			return Error::conflict(
				'site_foundation_lock_lost',
				[ 'message' => 'The site-foundation durable lock changed or was removed. Stop writes and inspect the created resources before retrying.' ]
			);
		}

		return true;
	}

	/**
	 * Release only the exact durable token owned by this request.
	 *
	 * @param array $lock Lock value.
	 * @return void
	 */
	private static function release_lock( array $lock ): void {
		global $wpdb;

		$lock_key = (string) ( $lock['key'] ?? '' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-bound delete cannot release a replacement lock.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
				$lock_key,
				maybe_serialize( (string) ( $lock['token'] ?? '' ) )
			)
		);
		self::flush_option_cache( $lock_key );
	}

	/**
	 * Invalidate both the option value and WordPress's cached missing-option set.
	 *
	 * @param string $option_name Option name.
	 * @return void
	 */
	private static function flush_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}

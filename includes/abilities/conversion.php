<?php
/**
 * Conversion abilities
 *
 * Render elements to HTML/CSS and convert HTML/CSS to Bricks element data.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversion and render-preview abilities.
 */
class Conversion {
	const MAX_CONVERT_INPUT_BYTES = 2097152; // 2 MB.


	// ------------------------------------------------------------------
	// bricks/render-elements - schemas
	// ------------------------------------------------------------------

	/**
	 * Input schema for render-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function render_elements_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'postId'         => [
					'type'        => 'integer',
					'description' => __( 'The post/page/template ID to use as rendering context. When elements are omitted, the ability renders this post\'s current saved Bricks tree.', 'bricks' ),
				],
				'elements'       => [
					'type'        => 'array',
					'description' => __( 'Optional proposed Bricks element tree. Omit after a save to render the current stored tree without reading and resending it.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'area'           => [
					'type'        => 'string',
					'enum'        => [ 'header', 'content', 'footer' ],
					'description' => __( 'Optional frozen Bricks area. When supplied, it must match the target post or template area.', 'bricks' ),
				],
				'responseFormat' => [
					'type'        => 'string',
					'enum'        => [ 'detailed', 'summary' ],
					'description' => __( 'Use `summary` for compact post-save verification of generated markup, landmarks, headings, and responsive CSS. Default `detailed` returns full HTML and CSS.', 'bricks' ),
				],
			],
			'required'   => [ 'postId' ],
		];
	}

	/**
	 * Output schema for render-elements
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function render_elements_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'html'    => [
					'type'        => 'string',
					'description' => __( 'Rendered HTML output.', 'bricks' ),
				],
				'css'     => [
					'type'        => 'string',
					'description' => __( 'Generated inline CSS for the elements.', 'bricks' ),
				],
				'summary' => [
					'type'        => 'object',
					'description' => __( 'Compact render verification when responseFormat is summary.', 'bricks' ),
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// bricks/render-elements - permission + execute
	// ------------------------------------------------------------------

	/**
	 * Permission: render-elements
	 *
	 * Same as read access to post elements.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function render_elements_permission( $input ) {
		return Elements::read_post_permission( $input );
	}

	/**
	 * Execute: render-elements
	 *
	 * Accepts Bricks element data and returns rendered HTML + CSS.
	 * Read-only: does not save anything.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input (postId, optional elements and responseFormat).
	 * @return array|WP_Error Rendered HTML and CSS.
	 */
	public static function render_elements( $input ) {
		$post_id  = $input['postId'];
		$elements = $input['elements'] ?? null;
		$area     = Elements::get_save_area_for_post( $post_id );

		if ( isset( $input['area'] ) && $input['area'] !== $area ) {
			return Error::conflict(
				'page_area_changed',
				[
					'message'      => 'The target Bricks area no longer matches the requested render area.',
					'expectedArea' => $input['area'],
					'actualArea'   => $area,
				]
			);
		}

		Manager::flush_post_cache( $post_id );

		if ( $elements === null ) {
			$saved = Elements::get_page_elements( [ 'postId' => $post_id ] );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$elements = $saved['elements'] ?? [];
		}

		// Accept nested {name, children} shorthand the same way set-page-elements
		// does, so callers can render the exact tree they would write.
		if ( is_array( $elements ) ) {
			$normalizer = new Element_Normalizer();
			$normalized = $normalizer->normalize( $elements );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$elements = $normalized;
		}

		// Validate elements structure
		$validation = Element_Validator::validate( $elements );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$code_permission = self::check_render_code_permissions( $elements );

		if ( is_wp_error( $code_permission ) ) {
			return $code_permission;
		}

		global $wp_query;
		global $post;

		$previous_post          = $post ?? null;
		$previous_page_data     = \Bricks\Database::$page_data;
		$previous_page_settings = \Bricks\Database::$page_settings;
		$query_keys             = [ 'queried_object', 'queried_object_id', 'is_singular', 'post_type', 'is_page', 'is_single' ];
		$previous_query         = [];

		if ( is_object( $wp_query ) ) {
			foreach ( $query_keys as $key ) {
				$previous_query[ $key ] = $wp_query->{$key} ?? null;
			}
		}

		$post = get_post( $post_id );

		if ( ! $post || is_wp_error( $post ) ) {
			return Error::not_found( 'post', $post_id );
		}

		try {
			// Set up post context (mirrors Api::render_element pattern).
			setup_postdata( $post );

			$wp_query->queried_object    = $post;
			$wp_query->queried_object_id = $post->ID;
			$wp_query->is_singular       = true;
			$wp_query->post_type         = $post->post_type;

			if ( $post->post_type === 'page' ) {
				$wp_query->is_page   = true;
				$wp_query->is_single = false;
			} else {
				$wp_query->is_page   = false;
				$wp_query->is_single = true;
			}

			\Bricks\Database::set_page_data( $post_id );

			// Load theme styles for correct rendering.
			\Bricks\Theme_Styles::load_set_styles( $post_id );

			// Mark elements as not frontend (builder preview mode).
			$elements = array_map( '\Bricks\Helpers::set_is_frontend_to_false', $elements );
			$elements = Element_Style_Normalizer::normalize_elements( $elements );

			// Generate CSS from elements.
			$css_type = "render_{$post_id}";
			\Bricks\Assets::generate_css_from_elements( $elements, $css_type );

			$inline_css = \Bricks\Assets::$inline_css[ $css_type ] ?? '';

			// Add global classes CSS.
			$inline_css .= \Bricks\Assets::generate_global_classes();

			// Render HTML.
			$elements = Elements::sign_authorized_code( [], $elements );
			$html     = \Bricks\Frontend::render_data( $elements, $area, $post_id );

			$html       = $html ? $html : '';
			$inline_css = $inline_css ? $inline_css : '';

			if ( ( $input['responseFormat'] ?? 'detailed' ) === 'summary' ) {
				return [
					'summary' => self::summarize_render_output( $html, $inline_css, count( $elements ) ),
				];
			}

			return [
				'html' => $html,
				'css'  => $inline_css,
			];
		} finally {
			wp_reset_postdata();

			$post                            = $previous_post;
			\Bricks\Database::$page_data     = $previous_page_data;
			\Bricks\Database::$page_settings = $previous_page_settings;

			if ( is_object( $wp_query ) ) {
				foreach ( $previous_query as $key => $value ) {
					$wp_query->{$key} = $value;
				}
			}
		}
	}

	/**
	 * Summarize generated render output without returning the full payload.
	 *
	 * @since 2.4
	 *
	 * @param string $html          Rendered HTML.
	 * @param string $css           Generated CSS.
	 * @param int    $element_count Number of rendered Bricks elements.
	 * @return array
	 */
	public static function summarize_render_output( $html, $css, $element_count ) {
		$landmarks             = [];
		$css_syntax_errors     = self::find_css_balance_errors( (string) $css );
		$css_specificity_risks = self::find_css_specificity_risks( (string) $css );

		foreach ( [ 'header', 'nav', 'main', 'footer' ] as $tag ) {
			$landmarks[ $tag ] = preg_match_all( '/<' . $tag . '\\b/i', $html );
		}

		$heading_outline = [];
		if ( preg_match_all( '/<h([1-6])\\b[^>]*>(.*?)<\/h\\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$heading_outline[] = [
					'level' => (int) $match[1],
					'text'  => trim( preg_replace( '/\\s+/', ' ', wp_strip_all_tags( $match[2] ) ) ),
				];
			}
		}

		return [
			'scope'               => 'content-fragment',
			'elementCount'        => (int) $element_count,
			'htmlBytes'           => strlen( $html ),
			'cssBytes'            => strlen( $css ),
			'htmlSha256'          => hash( 'sha256', $html ),
			'cssSha256'           => hash( 'sha256', $css ),
			'landmarks'           => $landmarks,
			'headingOutline'      => $heading_outline,
			'hasResponsiveCss'    => stripos( $css, '@media' ) !== false,
			'cssSyntaxBalanced'   => empty( $css_syntax_errors ),
			'cssSyntaxErrors'     => $css_syntax_errors,
			'cssSpecificityRisks' => $css_specificity_risks,
			'emptyHtml'           => trim( $html ) === '',
			'emptyCss'            => trim( $css ) === '',
		];
	}

	/**
	 * Find root-scoped inherited link colors that outrank nested modifiers.
	 *
	 * Bricks appends the native element class to selector-control output. A
	 * transferred document reset such as `a { color: inherit }` can therefore
	 * become `.project-root.brxe-section a`, outranking a modifier such as
	 * `.button-primary.brxe-div` and silently changing the rendered color.
	 *
	 * @since 2.4
	 *
	 * @param string $css Generated CSS.
	 * @return array Risk descriptors.
	 */
	private static function find_css_specificity_risks( string $css ): array {
		$risks = [];

		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER ) ) {
			return [];
		}

		foreach ( $rules as $rule ) {
			if ( ! preg_match( '/(?:^|;)\s*color\s*:\s*inherit\s*(?:;|$)/i', $rule[2] ) ) {
				continue;
			}

			foreach ( explode( ',', $rule[1] ) as $selector ) {
				$selector = trim( $selector );

				if (
					strpos( $selector, ':where(' ) === false &&
					preg_match( '/\.[a-z0-9_-]+\.brxe-(?:section|container|block|div)\s+a(?:$|[\s.:#\[])/i', $selector )
				) {
					$risks[] = [
						'code'     => 'root_scoped_inherited_link_color',
						'selector' => $selector,
						'message'  => 'This selector can outrank nested link color modifiers. Preserve transferred document resets as low-specificity scoped custom CSS (for example with :where()) instead of a Bricks descendant selector control.',
					];
				}
			}
		}

		if ( preg_match_all( '/@media\s*([^{]+)\{\s*([^{}]+)\{([^{}]*)\}\s*\}/is', $css, $media_rules, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $media_rules as $media_rule ) {
				$selector         = trim( $media_rule[2][0] );
				$media_properties = self::css_declaration_property_names( $media_rule[3][0] );
				$media_end        = $media_rule[0][1] + strlen( $media_rule[0][0] );
				$later_css        = substr( $css, $media_end );

				if ( $selector === '' || empty( $media_properties ) || $later_css === '' ) {
					continue;
				}

				if ( ! preg_match_all( '/' . preg_quote( $selector, '/' ) . '\s*\{([^{}]*)\}/is', $later_css, $later_rules, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				$overridden_properties = [];
				foreach ( $later_rules as $later_rule ) {
					if ( self::css_brace_depth_at( $later_css, $later_rule[0][1] ) !== 0 ) {
						continue;
					}

					$overridden_properties = array_values(
						array_intersect(
							$media_properties,
							self::css_declaration_property_names( $later_rule[1][0] )
						)
					);

					if ( ! empty( $overridden_properties ) ) {
						break;
					}
				}

				if ( empty( $overridden_properties ) ) {
					continue;
				}

				$risks[] = [
					'code'       => 'responsive_override_precedes_base_rule',
					'selector'   => $selector,
					'properties' => $overridden_properties,
					'message'    => 'A responsive override appears before a later equal-selector rule for the same property, so the base rule can win inside the media query. Emit the base rule first or strengthen the responsive selector.',
				];
			}
		}

		return $risks;
	}

	/**
	 * Return normalized property names from a CSS declaration block.
	 *
	 * @since 2.4
	 *
	 * @param string $declarations CSS declarations.
	 * @return array Property names.
	 */
	private static function css_declaration_property_names( string $declarations ): array {
		$properties = [];

		foreach ( explode( ';', $declarations ) as $declaration ) {
			$colon = strpos( $declaration, ':' );

			if ( $colon === false ) {
				continue;
			}

			$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );

			if ( $property !== '' && preg_match( '/^(?:--)?[a-z][a-z0-9_-]*$/', $property ) ) {
				$properties[] = $property;
			}
		}

		return array_values( array_unique( $properties ) );
	}

	/**
	 * Return CSS block depth immediately before a byte offset.
	 *
	 * Strings and comments may contain braces, so ignore them while scanning.
	 *
	 * @since 2.4
	 *
	 * @param string $css    CSS text.
	 * @param int    $offset Byte offset.
	 * @return int Brace depth.
	 */
	private static function css_brace_depth_at( string $css, int $offset ): int {
		$depth      = 0;
		$quote      = '';
		$escaped    = false;
		$in_comment = false;
		$length     = min( strlen( $css ), max( 0, $offset ) );

		for ( $index = 0; $index < $length; ++$index ) {
			$char = $css[ $index ];
			$next = $index + 1 < $length ? $css[ $index + 1 ] : '';

			if ( $in_comment ) {
				if ( $char === '*' && $next === '/' ) {
					$in_comment = false;
					++$index;
				}
				continue;
			}

			if ( $quote !== '' ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( $char === '\\' ) {
					$escaped = true;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $char === '/' && $next === '*' ) {
				$in_comment = true;
				++$index;
			} elseif ( $char === '"' || $char === "'" ) {
				$quote = $char;
			} elseif ( $char === '{' ) {
				++$depth;
			} elseif ( $char === '}' && $depth > 0 ) {
				--$depth;
			}
		}

		return $depth;
	}

	/**
	 * Find unbalanced CSS strings, comments, and delimiters.
	 *
	 * This is a compact corruption check, not a replacement for browser layout
	 * verification or a standards-complete CSS parser.
	 *
	 * @since 2.4
	 *
	 * @param string $css Generated CSS.
	 * @return array Human-readable syntax-balance errors.
	 */
	private static function find_css_balance_errors( string $css ): array {
		$errors     = [];
		$stack      = [];
		$quote      = '';
		$escaped    = false;
		$in_comment = false;
		$length     = strlen( $css );
		$pairs      = [
			')' => '(',
			']' => '[',
			'}' => '{'
		];

		for ( $index = 0; $index < $length; ++$index ) {
			$char = $css[ $index ];
			$next = $index + 1 < $length ? $css[ $index + 1 ] : '';

			if ( $in_comment ) {
				if ( $char === '*' && $next === '/' ) {
					$in_comment = false;
					++$index;
				}
				continue;
			}

			if ( $quote ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}

				if ( $char === '\\' ) {
					$escaped = true;
					continue;
				}

				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $char === '/' && $next === '*' ) {
				$in_comment = true;
				++$index;
				continue;
			}

			if ( $char === '"' || $char === "'" ) {
				$quote = $char;
				continue;
			}

			if ( in_array( $char, [ '(', '[', '{' ], true ) ) {
				$stack[] = $char;
				continue;
			}

			if ( isset( $pairs[ $char ] ) ) {
				$opening = array_pop( $stack );

				if ( $opening !== $pairs[ $char ] ) {
					$errors[] = "Unexpected or mismatched {$char} at byte {$index}.";
				}
			}
		}

		if ( $quote ) {
			$errors[] = 'Unclosed quoted string.';
		}

		if ( $in_comment ) {
			$errors[] = 'Unclosed CSS comment.';
		}

		if ( ! empty( $stack ) ) {
			$errors[] = 'Unclosed CSS delimiter: ' . implode( '', $stack ) . '.';
		}

		return array_values( array_unique( $errors ) );
	}

	// ------------------------------------------------------------------
	// bricks/convert-html-css-to-bricks-data - schemas
	// ------------------------------------------------------------------

	/**
	 * Input schema for convert-html-css-to-bricks-data
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function convert_html_css_to_bricks_data_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'html'     => [
					'type'        => 'string',
					'description' => __( 'Raw HTML string to convert to Bricks elements. Can include inline <style> and <script> tags. Optional when css is provided.', 'bricks' ),
				],
				'css'      => [
					'type'        => 'string',
					'description' => __( 'Raw CSS string. With html, this CSS styles the converted markup. Without html, this returns global classes, variables, element updates for provided context, and fallback Code elements when needed.', 'bricks' ),
				],
				'postId'   => [
					'type'        => 'integer',
					'description' => __( 'Post/page/template ID. Non-administrators must supply a valid, editable Bricks-enabled target with the necessary Builder permissions for HTML and CSS-only conversion. Administrators may omit postId. For CSS-only conversion, also provides optional existing-element context.', 'bricks' ),
				],
				'elements' => [
					'type'        => 'array',
					'description' => __( 'Optional existing Bricks elements used as context for CSS-only conversion.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'options'  => [
					'type'        => 'object',
					'description' => __( 'Optional conversion options.', 'bricks' ),
					'properties'  => [
						'create_global_classes'     => [
							'type'        => 'boolean',
							'description' => __( 'Whether to create global classes from CSS class selectors. Default true.', 'bricks' ),
						],
						'extract_variables'         => [
							'type'        => 'boolean',
							'description' => __( 'Whether to extract CSS custom properties from :root. Default true.', 'bricks' ),
						],
						'source_root_font_size_px'  => [
							'type'             => 'number',
							'exclusiveMinimum' => 0,
							'description'      => __( 'Optional source document root font size in pixels. Must be supplied with target_root_font_size_px to translate rem values and preserve the effective content-root font size.', 'bricks' ),
						],
						'target_root_font_size_px'  => [
							'type'             => 'number',
							'exclusiveMinimum' => 0,
							'description'      => __( 'Optional target Bricks root font size in pixels. Must be supplied with source_root_font_size_px to translate rem values while the converter scopes source base typography to page roots.', 'bricks' ),
						],
						'scope_global_css_to_roots' => [
							'type'        => 'boolean',
							'description' => __( 'Scope otherwise-global CSS selectors to the converted page roots instead of generating a Code element. Intended for authoritative full-page imports.', 'bricks' ),
						],
						'preserve_html_defaults'    => [
							'type'        => 'boolean',
							'description' => __( 'Preserve the deterministic semantic HTML baseline for tags present in an authoritative page import. Enabling this also scopes global CSS to the converted roots. Defaults to false.', 'bricks' ),
						],
					],
				],
			],
		];
	}

	/**
	 * Output schema for convert-html-css-to-bricks-data.
	 *
	 * Success-only shape - failures return `bricks_conversion_failed` WP_Error
	 * with the structured errors/warnings/partial elements attached.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function convert_html_css_to_bricks_data_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'elements'                        => [
					'type'        => 'array',
					'description' => __( 'Flat array of Bricks elements.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'global_classes'                  => [
					'type'        => 'array',
					'description' => __( 'New global class objects with stable 6-character IDs. Persist this array once with `bricks-batch-create-global-classes`; do not create classes one by one or remap element references.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'global_variables'                => [
					'type'        => 'array',
					'description' => __( 'New global variable objects to create.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'skipped_global_variables'        => [
					'type'        => 'array',
					'description' => __( 'Extracted CSS custom properties that matched an existing global-variable name. Compare their values before relying on the existing variable.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'has_executable_js'               => [
					'type'        => 'boolean',
					'description' => __( 'Whether the HTML contained inline or external JavaScript.', 'bricks' ),
				],
				'requires_execute_code'           => [
					'type'        => 'boolean',
					'description' => __( 'Whether the returned elements contain code-sensitive content. Code elements retain their existing execution and signature requirements. CSS follows target editing permissions, JavaScript requires unfiltered_html, and PHP requires BRICKS_ENABLE_PHP_ABILITIES and PHP authorization.', 'bricks' ),
				],
				'execute_code_permission_granted' => [
					'type'        => 'boolean',
					'description' => __( 'Whether the current caller has the Bricks execute-code capability.', 'bricks' ),
				],
				'code_sensitive_write_blocked'    => [
					'type'        => 'boolean',
					'description' => __( 'True when code-sensitive elements must be replaced or removed before any conversion-derived write, including classes, variables, or the element tree.', 'bricks' ),
				],
				'code_sensitive_element_ids'      => [
					'type'        => 'array',
					'description' => __( 'IDs of returned elements whose code-sensitive payload cannot be saved through Bricks abilities.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
				'code_sensitive_elements'         => [
					'type'        => 'array',
					'description' => __( 'Compact descriptors for code-sensitive elements so blocked callers can branch without rescanning the full tree.', 'bricks' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'             => [ 'type' => 'string' ],
							'name'           => [ 'type' => 'string' ],
							'sensitiveKinds' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						],
					],
				],
				'warnings'                        => [
					'type'        => 'array',
					'description' => __( 'Non-fatal conversion warnings.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'errors'                          => [
					'type'        => 'array',
					'description' => __( 'Non-fatal conversion errors (the response only reaches this shape on overall success; fatal errors return WP_Error instead).', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'mode'                            => [
					'type'        => 'string',
					'description' => __( 'Conversion mode: `html_css` or `css`.', 'bricks' ),
				],
				'rem_normalization'               => [
					'type'        => 'object',
					'description' => __( 'Explicit rem root normalization state and token counts. Inspect this before persisting an external source migration.', 'bricks' ),
					'properties'  => [
						'applied'                     => [ 'type' => 'boolean' ],
						'source_root_font_size_px'    => [ 'type' => [ 'number', 'null' ] ],
						'target_root_font_size_px'    => [ 'type' => [ 'number', 'null' ] ],
						'scale'                       => [ 'type' => [ 'number', 'null' ] ],
						'detected_token_count'        => [ 'type' => 'integer' ],
						'converted_token_count'       => [ 'type' => 'integer' ],
						'skipped_escaped_token_count' => [ 'type' => 'integer' ],
					],
				],
				'semantic_defaults'               => [
					'type'        => 'object',
					'description' => __( 'Deterministic semantic HTML baseline applied before authored CSS for authoritative page imports.', 'bricks' ),
					'properties'  => [
						'applied' => [ 'type' => 'boolean' ],
						'profile' => [ 'type' => [ 'string', 'null' ] ],
						'tags'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
				'class_map'                       => [
					'type'        => 'object',
					'description' => __( 'CSS class name to Bricks global class ID mapping.', 'bricks' ),
				],
				'elements_to_update'              => [
					'type'        => 'array',
					'description' => __( 'Patch-only CSS class updates for matched context elements. Contains element IDs and `_cssGlobalClasses`/`_cssClasses` changes only; never full element settings.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'generated_elements'              => [
					'type'        => 'array',
					'description' => __( 'CSS-only fallback elements generated for CSS that cannot be represented as globals or variables.', 'bricks' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'remaining_css_for_code_element'  => [
					'type'        => 'string',
					'description' => __( 'CSS-only fallback CSS stored in generated Code elements.', 'bricks' ),
				],
				'class_keyframe_css_by_class'     => [
					'type'        => 'object',
					'description' => __( 'CSS-only keyframe CSS assigned to owning global classes.', 'bricks' ),
				],
			],
		];
	}

	// ------------------------------------------------------------------
	// bricks/convert-html-css-to-bricks-data - permission + execute
	// ------------------------------------------------------------------

	/**
	 * Permission: convert-html-css-to-bricks-data
	 *
	 * Requires builder access (read-only conversion, no saving).
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function convert_html_css_to_bricks_data_permission( $input ) {
		// WP_Ability hides permission callback errors. Allow only the missing-input
		// diagnostic through; execution rejects it before loading or running the converter.
		if ( ! array_key_exists( 'postId', $input ) && ! current_user_can( 'administrator' ) ) {
			return true;
		}

		// Ability requests have no queried page; use the explicit target for post-type permissions.
		if ( ! \Bricks\Capabilities::current_user_can_use_builder( $input['postId'] ?? 0 ) ) {
			return Error::forbidden_builder_permission( 'use_builder' );
		}

		if ( isset( $input['postId'] ) ) {
			$permission = Elements::read_post_permission( [ 'postId' => $input['postId'] ] );

			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
		}

		return true;
	}

	/**
	 * Execute: convert-html-css-to-bricks-data
	 *
	 * Converts HTML/CSS to Bricks element data. Read-only: does not save.
	 * Callers can inspect the result, then use set-page-elements and design
	 * abilities to save.
	 *
	 * On failure returns a `WP_Error` (`bricks_conversion_failed`) with the
	 * collected errors, warnings, and any partial element tree attached as
	 * structured data. This is the canonical Bricks failure shape; the
	 * mcp-adapter's success-wrap would otherwise mask a `{success: false}`
	 * inside `{success: true, data: {...}}` envelope and clients that check
	 * the outer flag would silently march past the failure.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input (html/css, options, optional context).
	 * @return array|\WP_Error
	 */
	public static function convert_html_css_to_bricks_data( $input ) {
		if ( ! array_key_exists( 'postId', $input ) && ! current_user_can( 'administrator' ) ) {
			return Error::invalid_param(
				'postId',
				__( 'a valid, editable Bricks-enabled post/page/template ID. Non-administrators must supply postId for HTML and CSS-only conversion', 'bricks' )
			);
		}

		Manager::flush_options_cache();

		// The HTML/CSS converter classes live in `includes/html-to-bricks/` and its
		// `css-to-controls/` subdirectory - outside the autoloader's flat
		// `Bricks\` to `includes/` PSR-4 mapping. Bricks normally loads them
		// lazily from the AJAX builder action; the MCP path doesn't trigger
		// that, so pull them in here on first call.
		if ( ! class_exists( '\\Bricks\\Html_To_Bricks_Converter', false ) ) {
			foreach ( glob( BRICKS_PATH . 'includes/html-to-bricks/*.php' ) as $html_to_bricks_file ) {
				require_once $html_to_bricks_file;
			}
			foreach ( glob( BRICKS_PATH . 'includes/html-to-bricks/css-to-controls/*.php' ) as $css_to_controls_file ) {
				require_once $css_to_controls_file;
			}
		}

		$html    = isset( $input['html'] ) ? (string) $input['html'] : '';
		$css     = isset( $input['css'] ) ? (string) $input['css'] : '';
		$options = isset( $input['options'] ) ? $input['options'] : [];

		if ( ! is_array( $options ) ) {
			return Error::invalid_param( 'options', 'an object', $options );
		}

		$rem_options_validation = self::validate_rem_normalization_options( $options );

		if ( is_wp_error( $rem_options_validation ) ) {
			return $rem_options_validation;
		}

		if ( trim( $html ) === '' && trim( $css ) === '' ) {
			return Error::invalid_param( 'html|css', 'a non-empty html or css string', $input );
		}

		$input_bytes = strlen( (string) $html ) + strlen( (string) $css );
		$max_bytes   = self::max_convert_input_bytes();

		if ( $input_bytes > $max_bytes ) {
			return Error::invalid_param(
				'html|css',
				sprintf( 'combined HTML/CSS no larger than %d bytes', $max_bytes ),
				[
					'receivedBytes' => $input_bytes,
					'maxBytes'      => $max_bytes,
				]
			);
		}

		if ( trim( $html ) === '' ) {
			return self::convert_css_only( $css, $options, $input );
		}

		if ( $css !== '' ) {
			$html = '<style>' . $css . '</style>' . $html;
		}

		$existing_resources = self::get_existing_design_resources();

		if ( is_wp_error( $existing_resources ) ) {
			return $existing_resources;
		}

		$converter_options = array_merge(
			$options,
			[
				'base_breakpoint_key' => self::get_base_breakpoint_key_for_conversion(),
				'existing_classes'    => $existing_resources['classes'],
				'existing_variables'  => $existing_resources['variables'],
			]
		);

		$result = \Bricks\Html_To_Bricks_Converter::convert( $html, $converter_options );

		// Format the error collector for output
		$errors_obj        = $result['errors'];
		$errors_list       = method_exists( $errors_obj, 'get_errors' ) ? $errors_obj->get_errors() : [];
		$warnings_list     = method_exists( $errors_obj, 'get_warnings' ) ? $errors_obj->get_warnings() : [];
		$rem_normalization = $result['rem_normalization'] ?? self::default_rem_normalization_metadata();
		$warnings_list     = self::append_rem_normalization_warning( $warnings_list, $rem_normalization );

		if ( empty( $result['success'] ) ) {
			return Error::conversion_failed( $errors_list, $warnings_list, $result['elements'] ?? [] );
		}

		$requires_execute_code           = ! empty( self::code_sensitive_elements( $result['elements'] ?? [], false ) );
		$code_sensitive_elements         = self::code_sensitive_elements( $result['elements'] ?? [] );
		$code_sensitive_element_ids      = array_column( $code_sensitive_elements, 'id' );
		$execute_code_permission_granted = \Bricks\Capabilities::current_user_can_execute_code();
		$code_sensitive_write_blocked    = ! empty( $code_sensitive_element_ids );

		if ( $code_sensitive_write_blocked ) {
			$warnings_list[] = [
				'code'    => 'bricks_code_sensitive_write_forbidden',
				'message' => __( 'The conversion contains content this user cannot author. Check the listed elements and their required code permissions. HTML/CSS page import can omit disallowed elements and report a partial result.', 'bricks' ),
			];
		}

		return [
			'mode'                            => 'html_css',
			'elements'                        => $result['elements'],
			'global_classes'                  => $result['global_classes'],
			'global_variables'                => $result['global_variables'],
			'skipped_global_variables'        => $result['skipped_global_variables'] ?? [],
			'has_executable_js'               => $result['has_executable_js'],
			'requires_execute_code'           => $requires_execute_code,
			'execute_code_permission_granted' => $execute_code_permission_granted,
			'code_sensitive_write_blocked'    => $code_sensitive_write_blocked,
			'code_sensitive_element_ids'      => $code_sensitive_element_ids,
			'code_sensitive_elements'         => $code_sensitive_elements,
			'class_map'                       => $result['class_mapping'],
			'rem_normalization'               => $rem_normalization,
			'semantic_defaults'               => $result['semantic_defaults'] ?? [
				'applied' => false,
				'profile' => null,
				'tags'    => [],
			],
			'warnings'                        => $warnings_list,
			'errors'                          => $errors_list,
		];
	}

	/**
	 * Convert CSS without markup.
	 *
	 * @since 2.4
	 *
	 * @param string $css     Raw CSS.
	 * @param array  $options Conversion options.
	 * @param array  $input   Full ability input for context lookup.
	 * @return array|\WP_Error
	 */
	private static function convert_css_only( string $css, array $options, array $input ) {
		$existing_resources = self::get_existing_design_resources();

		if ( is_wp_error( $existing_resources ) ) {
			return $existing_resources;
		}

		$existing_elements = self::get_css_conversion_context_elements( $input );

		if ( is_wp_error( $existing_elements ) ) {
			return $existing_elements;
		}

		$converter_options = array_merge(
			$options,
			[
				'base_breakpoint_key' => self::get_base_breakpoint_key_for_conversion(),
				'existing_classes'    => $existing_resources['classes'],
				'existing_variables'  => $existing_resources['variables'],
				'existing_elements'   => $existing_elements,
			]
		);

		$result            = \Bricks\Html_To_Bricks_Converter::convert_css( $css, $converter_options );
		$errors_obj        = $result['errors'];
		$errors_list       = method_exists( $errors_obj, 'get_errors' ) ? $errors_obj->get_errors() : [];
		$warnings_list     = method_exists( $errors_obj, 'get_warnings' ) ? $errors_obj->get_warnings() : [];
		$rem_normalization = $result['rem_normalization'] ?? self::default_rem_normalization_metadata();
		$warnings_list     = self::append_rem_normalization_warning( $warnings_list, $rem_normalization );

		if ( empty( $result['success'] ) ) {
			return Error::conversion_failed( $errors_list, $warnings_list, $result['generated_elements'] ?? [] );
		}

		$updated_element_ids             = array_column( $result['elements_to_update'] ?? [], 'elementId' );
		$updated_authoritative_elements  = array_values(
			array_filter(
				$existing_elements,
				static function ( $element ) use ( $updated_element_ids ) {
					return is_array( $element ) && in_array( (string) ( $element['id'] ?? '' ), $updated_element_ids, true );
				}
			)
		);
		$conversion_elements             = array_merge( $result['generated_elements'] ?? [], $updated_authoritative_elements );
		$requires_execute_code           = ! empty( self::code_sensitive_elements( $conversion_elements, false ) );
		$code_sensitive_elements         = self::code_sensitive_elements( $conversion_elements );
		$code_sensitive_element_ids      = array_column( $code_sensitive_elements, 'id' );
		$execute_code_permission_granted = \Bricks\Capabilities::current_user_can_execute_code();
		$code_sensitive_write_blocked    = ! empty( $code_sensitive_element_ids );

		if ( $code_sensitive_write_blocked ) {
			$warnings_list[] = [
				'code'    => 'bricks_code_sensitive_write_forbidden',
				'message' => __( 'The conversion contains content this user cannot author. Check the listed elements and their required code permissions. HTML/CSS page import can omit disallowed elements and report a partial result.', 'bricks' ),
			];
		}

		return [
			'mode'                            => 'css',
			'elements'                        => $result['generated_elements'],
			'global_classes'                  => $result['global_classes'],
			'global_variables'                => $result['global_variables'],
			'skipped_global_variables'        => $result['skipped_global_variables'] ?? [],
			'has_executable_js'               => false,
			'requires_execute_code'           => $requires_execute_code,
			'execute_code_permission_granted' => $execute_code_permission_granted,
			'code_sensitive_write_blocked'    => $code_sensitive_write_blocked,
			'code_sensitive_element_ids'      => $code_sensitive_element_ids,
			'code_sensitive_elements'         => $code_sensitive_elements,
			'class_map'                       => $result['class_map'],
			'elements_to_update'              => $result['elements_to_update'],
			'generated_elements'              => $result['generated_elements'],
			'remaining_css_for_code_element'  => $result['remaining_css_for_code_element'],
			'class_keyframe_css_by_class'     => $result['class_keyframe_css_by_class'],
			'rem_normalization'               => $rem_normalization,
			'semantic_defaults'               => [
				'applied' => false,
				'profile' => null,
				'tags'    => [],
			],
			'warnings'                        => $warnings_list,
			'errors'                          => $errors_list,
		];
	}

	/**
	 * Validate the explicit source/target root normalization pair.
	 *
	 * @since 2.4
	 *
	 * @param array $options Conversion options.
	 * @return true|\WP_Error
	 */
	private static function validate_rem_normalization_options( array $options ) {
		$has_source = array_key_exists( 'source_root_font_size_px', $options );
		$has_target = array_key_exists( 'target_root_font_size_px', $options );

		if ( $has_source !== $has_target ) {
			return Error::invalid_param(
				'options.source_root_font_size_px|options.target_root_font_size_px',
				'both positive finite numbers supplied together',
				$options
			);
		}

		if ( ! $has_source ) {
			return true;
		}

		foreach ( [ 'source_root_font_size_px', 'target_root_font_size_px' ] as $option_name ) {
			$value = $options[ $option_name ];

			if (
				( ! is_int( $value ) && ! is_float( $value ) ) ||
				! is_finite( (float) $value ) ||
				$value <= 0
			) {
				return Error::invalid_param( "options.{$option_name}", 'a positive finite number', $value );
			}
		}

		return true;
	}

	/**
	 * Return metadata for conversions that do not expose converter metadata.
	 *
	 * @since 2.4
	 *
	 * @return array Default rem normalization metadata.
	 */
	private static function default_rem_normalization_metadata() {
		return [
			'applied'                     => false,
			'source_root_font_size_px'    => null,
			'target_root_font_size_px'    => null,
			'scale'                       => null,
			'detected_token_count'        => 0,
			'converted_token_count'       => 0,
			'skipped_escaped_token_count' => 0,
		];
	}

	/**
	 * Warn when rem tokens were preserved without root-size authority.
	 *
	 * Supplying both root sizes is an explicit request to normalize. The result
	 * remains visible in `rem_normalization` metadata, but it must not force a
	 * redundant acknowledgement round trip after the caller already chose it.
	 *
	 * @since 2.4
	 *
	 * @param array $warnings Existing converter warnings.
	 * @param array $metadata Rem normalization metadata.
	 * @return array Updated warnings.
	 */
	private static function append_rem_normalization_warning( array $warnings, array $metadata ) {
		if ( empty( $metadata['applied'] ) && ! empty( $metadata['detected_token_count'] ) ) {
			$warnings[] = [
				'code'    => 'rem_root_normalization_not_configured',
				'message' => sprintf(
					'%d rem token(s) were preserved. Supply both source_root_font_size_px and target_root_font_size_px when the source and Bricks root sizes differ.',
					(int) $metadata['detected_token_count']
				),
			];
		}

		return $warnings;
	}

	/**
	 * Return compact descriptors for converted elements that abilities cannot persist.
	 *
	 * @since 2.4
	 *
	 * @param array $elements                    Converted Bricks elements.
	 * @param bool  $restricted_to_current_user Whether to exclude payloads the caller may author.
	 * @return array Element descriptors.
	 */
	private static function code_sensitive_elements( array $elements, bool $restricted_to_current_user = true ): array {
		$descriptors = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$payload = $restricted_to_current_user ? Elements::code_sensitive_payload_for_current_user( $element ) : Elements::code_sensitive_payload( $element );
			$id      = (string) ( $element['id'] ?? '' );

			if ( $id === '' || empty( $payload ) ) {
				continue;
			}

			$descriptors[ $id ] = [
				'id'             => $id,
				'name'           => (string) ( $element['name'] ?? '' ),
				'sensitiveKinds' => array_values( array_keys( $payload ) ),
			];
		}

		return array_values( $descriptors );
	}

	/**
	 * Get fresh global classes and variables for conversion conflict detection.
	 *
	 * @since 2.4
	 *
	 * @return array|\WP_Error
	 */
	private static function get_existing_design_resources() {
		$existing_classes   = \Bricks\Database::$global_data['globalClasses'] ?? [];
		$existing_variables = \Bricks\Database::$global_data['globalVariables'] ?? [];

		Manager::flush_options_cache();
		$class_snapshot = Design_Option_Store::read( BRICKS_DB_GLOBAL_CLASSES, $existing_classes );

		if ( is_wp_error( $class_snapshot ) ) {
			return $class_snapshot;
		}

		$variable_snapshot = Design_Option_Store::read( BRICKS_DB_GLOBAL_VARIABLES, $existing_variables );

		if ( is_wp_error( $variable_snapshot ) ) {
			return $variable_snapshot;
		}

		$existing_classes   = is_array( $class_snapshot['value'] ?? null ) ? $class_snapshot['value'] : [];
		$existing_variables = is_array( $variable_snapshot['value'] ?? null ) ? $variable_snapshot['value'] : [];

		return [
			'classes'   => $existing_classes,
			'variables' => $existing_variables,
		];
	}

	/**
	 * Get the Bricks base breakpoint for HTML/CSS conversion.
	 *
	 * @since 2.4
	 *
	 * @return string Base breakpoint key.
	 */
	private static function get_base_breakpoint_key_for_conversion(): string {
		if ( class_exists( '\\Bricks\\Breakpoints' ) ) {
			\Bricks\Breakpoints::get_breakpoints();

			if ( ! empty( \Bricks\Breakpoints::$base_key ) ) {
				return (string) \Bricks\Breakpoints::$base_key;
			}
		}

		return 'desktop';
	}

	/**
	 * Resolve existing Bricks elements used by CSS-only conversion.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	private static function get_css_conversion_context_elements( array $input ) {
		if ( isset( $input['elements'] ) ) {
			if ( ! is_array( $input['elements'] ) ) {
				return Error::invalid_param( 'elements', 'an array of Bricks elements', $input['elements'] );
			}

			return $input['elements'];
		}

		if ( ! isset( $input['postId'] ) ) {
			return [];
		}

		$post_id = (int) $input['postId'];

		if ( $post_id <= 0 ) {
			return Error::invalid_param( 'postId', 'a positive integer', $input['postId'] );
		}

		Manager::flush_post_cache( $post_id );
		$area = Elements::get_save_area_for_post( $post_id );

		return \Bricks\Database::get_data( $post_id, $area );
	}

	/**
	 * Maximum HTML bytes accepted by the converter.
	 *
	 * @since 2.4
	 *
	 * @return int
	 */
	private static function max_convert_input_bytes(): int {
		return max(
			1,
			(int) apply_filters( 'bricks/abilities/conversion/max_input_bytes', self::MAX_CONVERT_INPUT_BYTES )
		);
	}

	/**
	 * Reject render previews that would execute code through abilities.
	 *
	 * @since 2.4
	 *
	 * @param array $elements Elements to render.
	 * @return true|\WP_Error
	 */
	private static function check_render_code_permissions( array $elements ) {
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ! empty( Elements::code_sensitive_payload_for_current_user( $element ) ) ) {
				return Error::code_sensitive_execution_forbidden();
			}

			if (
				! current_user_can( 'unfiltered_html' ) &&
				is_array( $element ) &&
				self::contains_executable_markup( $element['settings'] ?? [] )
			) {
				return Error::code_sensitive_execution_forbidden();
			}
		}

		return true;
	}

	/**
	 * Detect markup that would execute in the HTML returned by render-elements.
	 *
	 * This is intentionally narrower than the save pipeline's normal HTML
	 * filtering. The render ability is read-only, but MCP clients often preview
	 * returned HTML in a browser-like surface, so obvious executable markup must
	 * be blocked for users who cannot author executable code.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Element settings value.
	 * @return bool
	 */
	private static function contains_executable_markup( $value ): bool {
		if ( is_string( $value ) ) {
			return self::string_contains_executable_markup( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::contains_executable_markup( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Detect executable HTML primitives inside a string.
	 *
	 * @since 2.4
	 *
	 * @param string $value String to inspect.
	 * @return bool
	 */
	private static function string_contains_executable_markup( string $value ): bool {
		if ( stripos( $value, '<script' ) !== false ) {
			return true;
		}

		if ( preg_match( '/<\s*(iframe|object|embed)\b/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/<[a-z][^>]*\s+on[a-z0-9_-]+\s*=/i', $value ) ) {
			return true;
		}

		if ( preg_match( '/<[a-z][^>]*(href|src|xlink:href|formaction|action)\s*=\s*([\'"]?)\s*javascript\s*:/i', $value ) ) {
			return true;
		}

		return false;
	}
}

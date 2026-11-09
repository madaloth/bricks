<?php
/**
 * Meta abilities
 *
 * Self-description for Bricks abilities. Lets clients detect
 * version drift mid-conversation and decide whether to refresh skills
 * or abort a workflow.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta {
	/**
	 * Version of the Bricks abilities contract.
	 *
	 * Bump only on breaking changes to ability signatures, error codes,
	 * or response shapes. Consumers may compare against known-good values.
	 *
	 * @since 2.4
	 */
	const ABILITIES_VERSION = '2.0.0';

	/**
	 * Input schema for get-mcp-version
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_mcp_version_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	/**
	 * Output schema for get-mcp-version
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function get_mcp_version_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'bricksVersion'               => [
					'type'        => 'string',
					'description' => __( 'Version of the active Bricks plugin.', 'bricks' ),
				],
				'bricksAbilitiesVersion'      => [
					'type'        => 'string',
					'description' => __( 'Version of the Bricks abilities contract. Bumps on breaking changes to ability signatures, error codes, or response shapes.', 'bricks' ),
				],
				'adapterVersion'              => [
					'type'        => [ 'string', 'null' ],
					'description' => __( 'Version of the WordPress mcp-adapter plugin, or null if not detected.', 'bricks' ),
				],
				'wordpressVersion'            => [
					'type'        => 'string',
					'description' => __( 'WordPress core version.', 'bricks' ),
				],
				'abilitiesApiActive'          => [
					'type'        => 'boolean',
					'description' => __( 'Whether wp_register_ability is available.', 'bricks' ),
				],
				'disabledAbilityCount'        => [
					'type'        => 'integer',
					'description' => __( 'Total number of currently disabled abilities, including admin-disabled abilities and default-off categories. Call `bricks-list-ability-status` to see which.', 'bricks' ),
				],
				'adminDisabledAbilityCount'   => [
					'type'        => 'integer',
					'description' => __( 'Number of abilities explicitly disabled by the admin under Bricks > Settings > AI.', 'bricks' ),
				],
				'defaultDisabledAbilityCount' => [
					'type'        => 'integer',
					'description' => __( 'Number of abilities disabled because their category is default-off and has not been explicitly enabled.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Permission: any authenticated user.
	 *
	 * No sensitive data - version strings only. Gated behind login to avoid
	 * anonymous fingerprinting of plugin versions.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function get_mcp_version_permission( $input ) {
		if ( ! is_user_logged_in() ) {
			return Error::forbidden_builder_permission( 'read' );
		}

		return true;
	}

	/**
	 * Execute: get-mcp-version
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function get_mcp_version( $input ) {
		$disabled_counts = self::disabled_ability_counts();

		return [
			'bricksVersion'               => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
			'bricksAbilitiesVersion'      => self::ABILITIES_VERSION,
			'adapterVersion'              => self::detect_adapter_version(),
			'wordpressVersion'            => get_bloginfo( 'version' ),
			'abilitiesApiActive'          => function_exists( 'wp_register_ability' ),
			'disabledAbilityCount'        => $disabled_counts['total'],
			'adminDisabledAbilityCount'   => $disabled_counts['admin'],
			'defaultDisabledAbilityCount' => $disabled_counts['defaultOff'],
		];
	}

	/**
	 * Input schema for list-ability-status
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_ability_status_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'abilityNames'    => [
					'type'        => 'array',
					'description' => __( 'Optional exact ability names to inspect, such as `bricks/update-element`. Exact-name diagnostics return matching disabled abilities without requiring includeDisabled.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
					'maxItems'    => 50,
				],
				'includeDisabled' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Include disabled rows in an unfiltered summary. Omit for the compact enabled-ability inventory. Detailed responses continue to include the complete registry.', 'bricks' ),
				],
				'responseFormat'  => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'detailed' ],
					'default'     => 'summary',
					'description' => __( 'Summary returns compact status rows and, by default, only enabled abilities. Detailed includes labels, descriptions, annotations, and the complete registry.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for list-ability-status
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function list_ability_status_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'abilities'      => [
					'type'        => 'array',
					'description' => __( 'Requested Bricks ability status rows. An unfiltered summary returns enabled abilities by default; pass includeDisabled to include the complete registry.', 'bricks' ),
				],
				'responseFormat' => [ 'type' => 'string' ],
				'returned'       => [ 'type' => 'integer' ],
				'total'          => [ 'type' => 'integer' ],
				'enabled'        => [ 'type' => 'integer' ],
				'disabled'       => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Execute: list-ability-status
	 *
	 * Always registered (bypasses the admin deny-list) so MCP clients can
	 * diagnose why an expected tool is missing. Exact-name and detailed
	 * diagnostics expose disabled abilities, while an unfiltered summary stays
	 * compact by returning enabled abilities unless `includeDisabled` is true.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function list_ability_status( $input ) {
		$instance = \Bricks\Theme::instance();
		$manager  = isset( $instance->abilities ) ? $instance->abilities : null;

		$registry = $manager instanceof Manager && method_exists( $manager, 'get_ability_registry' )
			? $manager->get_ability_registry()
			: [];

		$enabled  = 0;
		$disabled = 0;

		foreach ( $registry as $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				$enabled++;
			} else {
				$disabled++;
			}
		}

		$ability_names   = is_array( $input['abilityNames'] ?? null )
			? array_values( array_unique( array_map( 'strval', $input['abilityNames'] ) ) )
			: [];
		$response_format = ( $input['responseFormat'] ?? 'summary' ) === 'detailed' ? 'detailed' : 'summary';
		$abilities       = $ability_names
			? array_values(
				array_filter(
					$registry,
					function( $row ) use ( $ability_names ) {
						return in_array( (string) ( $row['name'] ?? '' ), $ability_names, true );
					}
				)
			)
			: $registry;

		if (
			! $ability_names &&
			$response_format === 'summary' &&
			empty( $input['includeDisabled'] )
		) {
			$abilities = array_values(
				array_filter(
					$abilities,
					function( $row ) {
						return ! empty( $row['enabled'] );
					}
				)
			);
		}

		if ( $response_format === 'summary' ) {
			$abilities = array_map(
				function( $row ) {
					return [
						'name'           => (string) ( $row['name'] ?? '' ),
						'category'       => (string) ( $row['category'] ?? '' ),
						'enabled'        => ! empty( $row['enabled'] ),
						'defaultEnabled' => ! empty( $row['defaultEnabled'] ),
					];
				},
				$abilities
			);
		}

		return [
			'abilities'      => $abilities,
			'responseFormat' => $response_format,
			'returned'       => count( $abilities ),
			'total'          => count( $registry ),
			'enabled'        => $enabled,
			'disabled'       => $disabled,
		];
	}

	/**
	 * Input schema for start-here.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function start_here_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'responseFormat' => [
					'type'        => 'string',
					'enum'        => [ 'compact', 'detailed' ],
					'default'     => 'compact',
					'description' => __( 'Compact returns only task routing. Detailed returns the complete bundled workflow guide.', 'bricks' ),
				],
			],
		];
	}

	/**
	 * Output schema for start-here.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public static function start_here_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'responseFormat'       => [
					'type'        => 'string',
					'enum'        => [ 'compact', 'detailed' ],
					'description' => __( 'Response detail level used for orientation.', 'bricks' ),
				],
				'orientation'          => [
					'type'        => 'string',
					'description' => __( 'Markdown-formatted task routing or complete orientation prose.', 'bricks' ),
				],
				'fastPath'             => [
					'type'        => 'array',
					'description' => __( 'Hyphenated MCP tool names exposed directly on this site (visible in `tools/list`). Example: ability `bricks/get-design-context` appears as tool `bricks-get-design-context`. The list reflects the current admin deny-list and any `bricks/abilities/named_tools` filter overrides.', 'bricks' ),
					'items'       => [ 'type' => 'string' ],
				],
				'dispatcherInvocation' => [
					'type'        => 'object',
					'description' => __( 'Call shape for invoking long-tail abilities (those not in `fastPath`) through the bundled mcp-adapter dispatcher tool.', 'bricks' ),
					'properties'  => [
						'tool'        => [ 'type' => 'string' ],
						'argsExample' => [ 'type' => 'object' ],
						'note'        => [ 'type' => 'string' ],
					],
				],
			],
		];
	}

	/**
	 * Permission for start-here: any logged-in user.
	 *
	 * The orientation contains no sensitive information; it is the same
	 * content that ships in the Bricks skill pack. Gating behind login
	 * matches `get-mcp-version`'s rule and avoids anonymous fingerprinting.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public static function start_here_permission( $input ) {
		if ( ! is_user_logged_in() ) {
			return Error::forbidden_builder_permission( 'read' );
		}

		return true;
	}

	/**
	 * Execute: start-here.
	 *
	 * Returns a compact task router by default. The complete canonical Bricks
	 * orientation remains available explicitly for clients without skills.
	 *
	 * @since 2.4
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function start_here( $input ) {
		$response_format = ( $input['responseFormat'] ?? 'compact' ) === 'detailed' ? 'detailed' : 'compact';

		return [
			'responseFormat'       => $response_format,
			'orientation'          => $response_format === 'detailed' ? self::start_here_text() : self::compact_start_here_text(),
			'fastPath'             => self::resolve_fast_path_tools(),
			'dispatcherInvocation' => [
				'tool'        => 'mcp-adapter-execute-ability',
				'argsExample' => [
					'ability_name' => 'bricks/<long-tail-ability>',
					'parameters'   => [ '<key>' => '<value>' ],
				],
				'note'        => 'Use this shape for a known long-tail ability whose hyphenated tool name is not present in `fastPath`. Do not enumerate the full catalog unless the task is genuinely ambiguous.',
			],
		];
	}

	/**
	 * Compact routing for clients that have not loaded the Bricks skills.
	 *
	 * @since 2.4
	 *
	 * @return string
	 */
	private static function compact_start_here_text(): string {
		return implode(
			"\n\n",
			[
				'# Bricks task routing',
				'Known existing target: call `bricks-resolve-agent-file` first, edit only its canonical file, then call `bricks-commit-agent-file` once with the unchanged target, complete document (including its opaque baseline), and a stable idempotency key. Do not send a duplicate top-level baseline digest or call version, design context, the ability catalog, or a site manifest before this self-contained path.',
				'New visual page, section, or template shell: author semantic HTML/CSS and use the preview/apply HTML import workflow. Broad or ambiguous discovery: use `bricks-checkout-site-repository`. Coordinated edits across 2-25 existing resources: use the site changeset checkout/preview/apply/resume workflow.',
				'Rendered HTML and screenshots are verification evidence, never reverse-sync authority. Stop on stale, ambiguous, incomplete, partial-commit, or manual-recovery output instead of guessing or changing the idempotency key. Request `{ "responseFormat": "detailed" }` only when the complete workflow guide is needed.',
			]
		);
	}

	/**
	 * Canonical orientation text.
	 *
	 * Loaded from `start-here.md` next to this file so the prose can be
	 * edited without touching PHP. Filterable via
	 * `bricks/abilities/start_here_text` so site owners and translation
	 * plugins can amend or replace the text.
	 *
	 * Keep in sync with `start-here/SKILL.md` in
	 * `codeerhq/bricks-skills` when that skill ships an update. The
	 * two should not drift.
	 *
	 * @since 2.4
	 */
	private static function start_here_text(): string {
		$path = __DIR__ . '/start-here.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local bundled Markdown file, not a remote URL.
		$contents = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

		/**
		 * Filters the Markdown orientation text returned by `bricks/start-here`.
		 *
		 * Use this to amend (e.g. append agency-specific rules) or replace the
		 * default orientation. The default is loaded from
		 * `includes/abilities/start-here.md`.
		 *
		 * @since 2.4
		 *
		 * @param string $contents The Markdown orientation text.
		 */
		return (string) apply_filters( 'bricks/abilities/start_here_text', $contents );
	}

	/**
	 * Resolve the fast-path tool list as it will appear on this site,
	 * accounting for the admin deny-list and any user-registered
	 * `bricks/abilities/named_tools` filter.
	 *
	 * Mirrors the runtime selection in
	 * `Manager::append_named_tools_to_default_server` so the response from
	 * `start-here` matches what clients actually see in `tools/list`.
	 *
	 * @since 2.4
	 *
	 * @return string[] Hyphenated MCP tool names.
	 */
	private static function resolve_fast_path_tools(): array {
		$abilities = Manager::get_named_tool_abilities();

		return array_map(
			static function ( $name ) {
				return str_replace( '/', '-', $name );
			},
			$abilities
		);
	}

	/**
	 * Count disabled ability states for version diagnostics.
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	private static function disabled_ability_counts(): array {
		$settings = Manager::get_mcp_settings();
		$admin    = count( $settings['disabledAbilities'] ?? [] );

		$instance = \Bricks\Theme::instance();
		$manager  = isset( $instance->abilities ) ? $instance->abilities : null;
		$registry = $manager instanceof Manager && method_exists( $manager, 'get_ability_registry' )
			? $manager->get_ability_registry()
			: [];

		$total       = 0;
		$default_off = 0;

		foreach ( $registry as $row ) {
			if ( ! empty( $row['enabled'] ) ) {
				continue;
			}

			$total++;

			if ( array_key_exists( 'defaultEnabled', $row ) && ! $row['defaultEnabled'] ) {
				$default_off++;
			}
		}

		return [
			'total'      => $total,
			'admin'      => $admin,
			'defaultOff' => $default_off,
		];
	}

	/**
	 * Detect the installed mcp-adapter plugin version.
	 *
	 * Defensive: the adapter is a separate plugin; we can't assume a
	 * constant. Read plugin headers for the active plugin, fall back
	 * to null if unresolvable.
	 *
	 * @since 2.4
	 *
	 * @return string|null
	 */
	private static function detect_adapter_version() {
		if ( defined( 'WP_MCP_VERSION' ) ) {
			return (string) WP_MCP_VERSION;
		}

		if ( defined( 'MCP_ADAPTER_VERSION' ) ) {
			return (string) MCP_ADAPTER_VERSION;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			if ( false !== stripos( $file, 'mcp-adapter' ) ) {
				return isset( $data['Version'] ) ? (string) $data['Version'] : null;
			}
		}

		return null;
	}
}

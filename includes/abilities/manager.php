<?php
/**
 * Abilities Manager
 *
 * Bootstraps Bricks abilities for the WordPress Abilities API.
 * Registers ability categories and all individual abilities.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager {
	/**
	 * Option key storing the MCP settings envelope.
	 *
	 * Kept as a dedicated option (not merged into `bricks_global_settings`) so
	 * the MCP admin surface has its own permission boundary. Shape:
	 *   [
	 *     'enabled'           => bool,     // master toggle (also tied to bricks_global_settings['abilitiesApi'])
	 *     'disabledAbilities' => string[], // deny-list of fully-qualified ability names
	 *     'enabledAbilities'  => string[], // explicit opt-ins for default-off abilities
	 *   ]
	 *
	 * @since 2.4
	 */
	const SETTINGS_OPTION = 'bricks_mcp_settings';

	/**
	 * Ability categories that require an explicit admin opt-in.
	 *
	 * These are still registered in the local registry, so the AI tab and
	 * `bricks/list-ability-status` can explain that they exist. They are not
	 * callable through MCP until an admin enables them.
	 *
	 * @since 2.4
	 */
	const DEFAULT_DISABLED_CATEGORIES = [
		'bricks-permissions',
	];

	/**
	 * Abilities that are always registered regardless of the disable list.
	 *
	 * Discoverability / diagnostic abilities must remain callable so MCP
	 * clients and the admin screen JS can find out why an expected ability is
	 * missing.
	 * Keep this list minimal.
	 *
	 * @since 2.4
	 */
	const ALWAYS_ON_ABILITIES = [
		'bricks/list-ability-status',
		'bricks/get-mcp-version',
		'bricks/start-here',
	];

	/**
	 * Registry of every ability we attempted to register this request.
	 *
	 * Populated inside `register()` regardless of whether `wp_register_ability`
	 * was actually called - so `list-ability-status` can report disabled
	 * abilities even though they never reached the MCP adapter.
	 *
	 * Shape: [ name => [ 'name', 'label', 'description', 'category', 'destructive', 'defaultEnabled', 'enabled' ] ]
	 *
	 * @since 2.4
	 */
	private $ability_registry = [];

	/**
	 * True while we are inside the `wp_abilities_api_init` callback and should
	 * actually hand abilities to `wp_register_ability()`. Outside that window
	 * (e.g. when the admin screen asks for the registry before the hook has
	 * fired) we populate $ability_registry without touching the WP API.
	 *
	 * @since 2.4
	 */
	private $wp_registration_active = false;

	/**
	 * Tracks ability names already sent to `wp_register_ability()` so we don't
	 * double-register if the admin triggers registry population and then the
	 * API-init hook fires.
	 *
	 * @since 2.4
	 */
	private $wp_registered = [];

	/**
	 * Memoized copy of the MCP settings option.
	 *
	 * @since 2.4
	 */
	private static $cached_settings = null;

	/**
	 * Ability-name to default-enabled map, populated during registration.
	 *
	 * @since 2.4
	 */
	private static $ability_default_enabled = [];

	/**
	 * Constructor
	 *
	 * Checks prerequisites and registers hooks.
	 *
	 * @since 2.4
	 */
	public function __construct() {
		// Bail if WordPress Abilities API not available
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Bail if feature flag disabled
		if ( ! isset( \Bricks\Database::$global_settings['abilitiesApi'] ) ) {
			return;
		}

		// Invalidate the memoized settings cache whenever the admin saves.
		// Hooked regardless of enabled state so the admin screen sees fresh
		// values immediately after the tab's save submits.
		add_action( 'update_option_' . self::SETTINGS_OPTION, [ __CLASS__, 'reset_settings_cache' ] );
		add_action( 'add_option_' . self::SETTINGS_OPTION, [ __CLASS__, 'reset_settings_cache' ] );
		add_action( 'delete_option_' . self::SETTINGS_OPTION, [ __CLASS__, 'reset_settings_cache' ] );

		// Bail if the admin toggled MCP off. The admin UI flips both the
		// legacy `abilitiesApi` flag in global settings AND the `enabled` key
		// in `bricks_mcp_settings`; we check both so either source can disable
		// the whole surface.
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );

		// Append our high-frequency abilities to the default mcp-adapter
		// server's `tools` list so they appear in `tools/list` directly,
		// bypassing the dispatcher round-trip and preserving WP_Error data
		// payloads on failures. The long tail of lower-frequency abilities
		// stays callable through the `mcp-adapter-execute-ability` tool.
		add_filter( 'mcp_adapter_default_server_config', [ __CLASS__, 'append_named_tools_to_default_server' ] );

		$this->hook_design_system_writes();
	}

	/**
	 * High-frequency Bricks abilities exposed as named tools on the default
	 * mcp-adapter server.
	 *
	 * Appearing here means the ability shows up in the MCP `tools/list` response
	 * directly, with slashes replaced by hyphens (e.g.
	 * `bricks/get-page-elements` is exposed as `bricks-get-page-elements`).
	 * Clients can call the hyphenated tool without going through the
	 * `mcp-adapter-execute-ability` dispatcher, which preserves
	 * `WP_Error->data` payloads and saves a round-trip on common operations.
	 *
	 * Selection criteria: the ability is touched in most Bricks-using sessions,
	 * AND/OR its structured error data is load-bearing for client decision-making
	 * (lock conflicts, ambiguous matches, conflicts on writes).
	 *
	 * Everything not listed here remains accessible only via the dispatcher.
	 * That is intentional. Clients discover the long tail with
	 * `mcp-adapter-discover-abilities` when they need it, and the dispatcher
	 * `WP_Error->data` drop is mitigated by the `Error::wrap` JSON-suffix
	 * pattern in `error.php`.
	 *
	 * @since 2.4
	 */
	const NAMED_TOOLS_ON_DEFAULT_SERVER = [
		// Compact orientation and exact-target repository editing. Diagnostics,
		// explicit previews, low-level changesets, and undo stay dispatcher-only.
		'bricks/get-design-context',
		'bricks/checkout-site-repository',
		'bricks/resolve-agent-file',
		'bricks/commit-exact-site-edits',
		'bricks/checkout-site-edit-map',
		'bricks/commit-site-edit-plan',
		'bricks/commit-agent-file',

		// New content. Existing Bricks resources resolve through resolve-agent-file.
		'bricks/create-post',

		// Greenfield visual authoring.
		'bricks/commit-site-foundation',
		'bricks/commit-html-css-page-import',
		'bricks/apply-html-css-page-import',

	];

	/**
	 * Filter callback for `mcp_adapter_default_server_config`.
	 *
	 * Appends the named-fast-path abilities to the default server's `tools`
	 * array. Other plugins that hook the same filter can append their own
	 * tools without conflict - array_merge + array_unique preserves both.
	 *
	 * Skips any abilities the admin has disabled via the deny-list, so
	 * disabling an ability also removes it from `tools/list` cleanly.
	 *
	 * @since 2.4
	 *
	 * @param mixed $config Config array passed through the filter.
	 * @return mixed Possibly-modified config.
	 */
	public static function append_named_tools_to_default_server( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}

		$existing     = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : [];
		$bricks_tools = self::get_named_tool_abilities();

		$config['tools'] = array_values( array_unique( array_merge( $existing, $bricks_tools ) ) );

		return $config;
	}

	/**
	 * Resolve the Bricks ability names exposed as direct MCP tools.
	 *
	 * Applies `bricks/abilities/named_tools`, removes invalid values, respects
	 * the admin deny-list, and drops unknown ability names once the Abilities
	 * API registry is available. Keeping this in one place ensures
	 * `mcp_adapter_default_server_config` and `bricks/start-here` report the
	 * same fast-path surface.
	 *
	 * @since 2.4
	 *
	 * @return string[] Ability names, e.g. `bricks/find-post`.
	 */
	public static function get_named_tool_abilities(): array {
		/**
		 * Filters the list of Bricks abilities exposed as named tools on the
		 * default MCP server. The default is `Manager::NAMED_TOOLS_ON_DEFAULT_SERVER`.
		 *
		 * Use this filter to add or remove abilities from the directly-callable
		 * fast path without forking. Abilities not in this list remain callable
		 * via the `mcp-adapter-execute-ability` dispatcher tool.
		 *
		 * Names are validated against ability registration when the registry is
		 * available: unknown names are silently dropped, and admin-disabled names
		 * (deny-list) are dropped regardless of this filter.
		 *
		 * @since 2.4
		 *
		 * @param string[] $tools Bricks ability names (e.g. `bricks/find-post`).
		 */
		$tools = apply_filters( 'bricks/abilities/named_tools', self::NAMED_TOOLS_ON_DEFAULT_SERVER );

		if ( ! is_array( $tools ) ) {
			$tools = self::NAMED_TOOLS_ON_DEFAULT_SERVER;
		}

		$can_validate_registration = function_exists( 'wp_get_ability' )
			&& ( ! function_exists( 'did_action' ) || did_action( 'wp_abilities_api_init' ) > 0 );

		$filtered = array_filter(
			$tools,
			static function ( $name ) use ( $can_validate_registration ) {
				if ( ! is_string( $name ) || $name === '' || self::is_ability_disabled( $name ) ) {
					return false;
				}

				if ( $can_validate_registration && ! wp_get_ability( $name ) ) {
					return false;
				}

				return true;
			}
		);

		return array_values( array_unique( $filtered ) );
	}

	/**
	 * Read the MCP settings option with sane defaults.
	 *
	 * @since 2.4
	 *
	 * @return array{enabled: bool, disabledAbilities: string[], enabledAbilities: string[]}
	 */
	public static function get_mcp_settings(): array {
		if ( self::$cached_settings !== null ) {
			return self::$cached_settings;
		}

		$raw = get_option( self::SETTINGS_OPTION, [] );

		$settings = [
			'enabled'           => isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : true,
			'disabledAbilities' => isset( $raw['disabledAbilities'] ) && is_array( $raw['disabledAbilities'] )
				? array_values( array_unique( array_map( 'strval', $raw['disabledAbilities'] ) ) )
				: [],
			'enabledAbilities'  => isset( $raw['enabledAbilities'] ) && is_array( $raw['enabledAbilities'] )
				? array_values( array_unique( array_map( 'strval', $raw['enabledAbilities'] ) ) )
				: [],
		];

		self::$cached_settings = $settings;

		return $settings;
	}

	/**
	 * Drop the memoized settings cache.
	 *
	 * @since 2.4
	 */
	public static function reset_settings_cache(): void {
		self::$cached_settings = null;
	}

	/**
	 * Master on/off for the MCP surface.
	 *
	 * Resolution order:
	 * 1. `BRICKS_DISABLE_MCP` PHP constant (truthy means forced off, regardless of admin).
	 * 2. The parent `abilitiesApi` flag in `bricks_global_settings`.
	 * 3. The per-MCP `enabled` key in `bricks_mcp_settings`.
	 *
	 * The constant is the hard kill switch for hosting providers, multi-environment
	 * setups, and security-conservative agencies that need to pin the MCP off
	 * regardless of what an admin toggles. There is no corresponding "force-on"
	 * constant - the admin must always opt in via the AI tab.
	 *
	 * @since 2.4
	 */
	public static function is_enabled(): bool {
		if ( self::is_disabled_by_constant() ) {
			return false;
		}

		if ( empty( \Bricks\Database::$global_settings['abilitiesApi'] ) ) {
			return false;
		}

		return (bool) ( self::get_mcp_settings()['enabled'] ?? true );
	}

	/**
	 * Whether the MCP is currently force-disabled by the `BRICKS_DISABLE_MCP`
	 * PHP constant.
	 *
	 * Distinct from `is_enabled()` so the admin screen can show a different
	 * notice when the off-state comes from `wp-config.php` rather than the
	 * admin toggle, AND so `is_enabled()` has a single source of truth for
	 * the constant check.
	 *
	 * @since 2.4
	 */
	public static function is_disabled_by_constant(): bool {
		return defined( 'BRICKS_DISABLE_MCP' ) && (bool) constant( 'BRICKS_DISABLE_MCP' );
	}

	/**
	 * Is a specific ability currently disabled via the admin deny-list?
	 *
	 * @since 2.4
	 *
	 * @param string $ability_name Fully-qualified name, e.g. `bricks/delete-post`.
	 */
	public static function is_ability_disabled( string $ability_name ): bool {
		return ! self::is_ability_enabled( $ability_name );
	}

	/**
	 * Is a specific ability currently enabled?
	 *
	 * @since 2.4
	 *
	 * @param string    $ability_name    Fully-qualified name, e.g. `bricks/delete-post`.
	 * @param bool|null $default_enabled Default state. Null reads the registration map.
	 * @return bool
	 */
	private static function is_ability_enabled( string $ability_name, ?bool $default_enabled = null ): bool {
		if ( in_array( $ability_name, self::ALWAYS_ON_ABILITIES, true ) ) {
			return true;
		}

		if ( $default_enabled === null ) {
			$default_enabled = self::$ability_default_enabled[ $ability_name ] ?? true;
		}

		$settings = self::get_mcp_settings();

		if ( in_array( $ability_name, $settings['disabledAbilities'], true ) ) {
			return false;
		}

		if ( ! $default_enabled && ! in_array( $ability_name, $settings['enabledAbilities'], true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Full ability registry snapshot.
	 *
	 * Populated lazily if not already built by the `wp_abilities_api_init`
	 * hook - the admin screen needs to render its per-ability toggle list even
	 * on requests where the hook hasn't fired (e.g. the settings tab loading
	 * before the Abilities API initialises, or the MCP master toggle being off
	 * so the hook was never attached).
	 *
	 * @since 2.4
	 *
	 * @return array
	 */
	public function get_ability_registry(): array {
		if ( empty( $this->ability_registry ) ) {
			$this->populate_ability_registry();
		}

		return array_values( $this->ability_registry );
	}

	/**
	 * Internal: call every register_*_abilities() method in turn.
	 *
	 * Shared between the hook-driven path (register_abilities) and the lazy
	 * admin-screen path (get_ability_registry). The $wp_registration_active
	 * flag controls whether register() actually calls wp_register_ability().
	 *
	 * @since 2.4
	 */
	private function populate_ability_registry() {
		$this->register_reference_abilities();
		$this->register_element_abilities();
		$this->register_template_abilities();
		$this->register_design_abilities();
		$this->register_content_abilities();
		$this->register_revision_abilities();
		$this->register_media_abilities();
		$this->register_meta_abilities();
		$this->register_cms_abilities();
		$this->register_navigation_abilities();
		$this->register_form_abilities();
		$this->register_filter_abilities();
		$this->register_popup_abilities();
		$this->register_interaction_abilities();
		$this->register_element_condition_abilities();
		$this->register_settings_abilities();
		$this->register_woocommerce_abilities();
		$this->register_permission_abilities();
		$this->register_breakpoint_abilities();
		$this->register_query_abilities();
		$this->register_sidebar_abilities();
		$this->register_font_abilities();
		$this->register_icon_abilities();
		$this->register_pseudo_class_abilities();
		$this->register_style_manager_abilities();
		$this->register_maintenance_abilities();
		$this->register_execute_php_abilities();
		$this->register_import_export_abilities();
		$this->register_system_info_abilities();
	}

	/**
	 * Hook every design-system option write so we can bump the version
	 * counter read by `bricks/get-design-context`.
	 *
	 * Catches both ability-driven and admin-UI writes so the counter is a
	 * true site-wide signal of drift.
	 *
	 * @since 2.4
	 */
	private function hook_design_system_writes() {
		// Gutenberg saves do not update a design option, but do change component usage.
		foreach ( [ 'save_post', 'deleted_post' ] as $hook ) {
			add_action(
				$hook,
				static function () {
					delete_transient( Design::COMPONENT_USAGE_TRANSIENT );
				}
			);
		}

		$options = [
			BRICKS_DB_COLOR_PALETTE,
			BRICKS_DB_GLOBAL_CLASSES,
			BRICKS_DB_GLOBAL_CLASSES_LOCKED,
			BRICKS_DB_GLOBAL_CLASSES_CATEGORIES,
			BRICKS_DB_THEME_STYLES,
			BRICKS_DB_COMPONENTS,
			BRICKS_DB_BREAKPOINTS,
			BRICKS_DB_GLOBAL_VARIABLES,
			BRICKS_DB_GLOBAL_VARIABLES_CATEGORIES,
			BRICKS_DB_PSEUDO_CLASSES,
		];

		foreach ( $options as $option ) {
			add_action( "update_option_{$option}", [ Design::class, 'bump_design_system_version' ] );
			add_action( "add_option_{$option}", [ Design::class, 'bump_design_system_version' ] );
			add_action( "delete_option_{$option}", [ Design::class, 'bump_design_system_version' ] );
		}
	}

	/**
	 * Register ability categories
	 *
	 * @since 2.4
	 */
	public function register_categories() {
		wp_register_ability_category(
			'bricks-elements',
			[
				'label'       => __( 'Bricks Elements', 'bricks' ),
				'description' => __( 'Read, create, update, and remove page/template elements and page settings', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-templates',
			[
				'label'       => __( 'Bricks Templates', 'bricks' ),
				'description' => __( 'List, read, create, delete templates and manage template settings', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-design',
			[
				'label'       => __( 'Bricks Design System', 'bricks' ),
				'description' => __( 'Color palettes, global classes, theme styles, components, variables', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-reference',
			[
				'label'       => __( 'Bricks Reference', 'bricks' ),
				'description' => __( 'Element schemas, dynamic data tags, builder guide documentation', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-content',
			[
				'label'       => __( 'Bricks Content Lifecycle', 'bricks' ),
				'description' => __( 'Create, duplicate, and delete posts and pages. These wrap generic WordPress operations and will be retired once WordPress core ships equivalents.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-revisions',
			[
				'label'       => __( 'Bricks Revisions', 'bricks' ),
				'description' => __( 'List, inspect, and restore Bricks-aware revisions with postmeta preservation.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-media',
			[
				'label'       => __( 'Bricks Media', 'bricks' ),
				'description' => __( 'Upload to and search the media library. These wrap generic WordPress operations and will be retired once WordPress core ships equivalents.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-meta',
			[
				'label'       => __( 'Bricks MCP Meta', 'bricks' ),
				'description' => __( 'Self-description of the Bricks abilities setup: Bricks, abilities contract, adapter, and WordPress versions for drift detection.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-cms',
			[
				'label'       => __( 'Bricks CMS Structure', 'bricks' ),
				'description' => __( 'Content-model introspection and small WordPress CMS setup operations needed while building Bricks sites.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-navigation',
			[
				'label'       => __( 'Bricks Navigation', 'bricks' ),
				'description' => __( 'WordPress nav menus plus Bricks mega-menu and multilevel menu-item options.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-forms',
			[
				'label'       => __( 'Bricks Forms', 'bricks' ),
				'description' => __( 'Read and mutate Bricks Form elements (fields, actions) and list stored submissions.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-filters',
			[
				'label'       => __( 'Bricks Query Filters', 'bricks' ),
				'description' => __( 'List and configure Query Filter elements bound to query-loop elements.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-popups',
			[
				'label'       => __( 'Bricks Popups', 'bricks' ),
				'description' => __( 'List popup templates, read their trigger/limit config, and update popup settings.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-interactions',
			[
				'label'       => __( 'Bricks Interactions', 'bricks' ),
				'description' => __( 'Read and write element interactions (trigger/action pairs stored on element settings).', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-settings',
			[
				'label'       => __( 'Bricks Global Settings', 'bricks' ),
				'description' => __( 'Allow-listed Bricks global-settings keys: discover, read, and partial-merge write. License keys, API keys, code-execution toggles, and builder role data are excluded.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-woocommerce',
			[
				'label'       => __( 'Bricks WooCommerce', 'bricks' ),
				'description' => __( 'Inspect and set up Bricks WooCommerce pages/templates, modular Woo elements, and safe Woo setup options.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-permissions',
			[
				'label'       => __( 'Bricks Builder Permissions', 'bricks' ),
				'description' => __( 'Read builder permission sections, manage custom builder capabilities, and assign builder access to WordPress roles.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-breakpoints',
			[
				'label'       => __( 'Bricks Breakpoints', 'bricks' ),
				'description' => __( 'Read and write responsive breakpoints. Writes update the breakpoint option; regenerate CSS files separately when using file-based CSS.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-queries',
			[
				'label'       => __( 'Bricks Global Queries', 'bricks' ),
				'description' => __( 'Reusable query-loop configurations that can be bound to loop elements by ID.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-sidebars',
			[
				'label'       => __( 'Bricks Sidebars', 'bricks' ),
				'description' => __( 'Register and manage custom WordPress sidebars exposed to the Bricks "Sidebar" element.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-fonts',
			[
				'label'       => __( 'Bricks Custom Fonts', 'bricks' ),
				'description' => __( 'Upload and manage custom font families (bricks_fonts CPT + font-face attachments).', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-icons',
			[
				'label'       => __( 'Bricks Icons', 'bricks' ),
				'description' => __( 'Toggle built-in icon libraries on/off and manage custom SVG icons.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-pseudo-classes',
			[
				'label'       => __( 'Bricks Pseudo-Classes', 'bricks' ),
				'description' => __( 'Manage the list of pseudo-class selectors available in the builder style panel.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-style-manager',
			[
				'label'       => __( 'Bricks Style Manager', 'bricks' ),
				'description' => __( 'Read and write the style-manager option (px to rem base, fluid-scale widths, light/dark mode).', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-maintenance',
			[
				'label'       => __( 'Bricks Maintenance', 'bricks' ),
				'description' => __( 'Site maintenance operations: regenerate CSS files, scan and clean orphaned elements. Admin-only.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-import-export',
			[
				'label'       => __( 'Bricks Import/Export', 'bricks' ),
				'description' => __( 'Bundle global design data and template ZIPs for migration between sites. License, API keys, and code-execution settings are never included.', 'bricks' ),
			]
		);

		wp_register_ability_category(
			'bricks-system',
			[
				'label'       => __( 'Bricks System', 'bricks' ),
				'description' => __( 'Read-only system information for diagnostics.', 'bricks' ),
			]
		);
	}

	/**
	 * Register all abilities (hook callback for `wp_abilities_api_init`).
	 *
	 * Toggles $wp_registration_active so the shared register() helper knows
	 * it is safe to call wp_register_ability(). Outside this window, register()
	 * populates the local registry only.
	 *
	 * @since 2.4
	 */
	public function register_abilities() {
		$this->wp_registration_active = true;
		try {
			$this->populate_ability_registry();
		} finally {
			$this->wp_registration_active = false;
		}
	}

	/**
	 * Register reference abilities
	 *
	 * @since 2.4
	 */
	private function register_reference_abilities() {
		$this->register(
			'bricks/list-element-types',
			[
				'label'               => __( 'Bricks element catalog', 'bricks' ),
				'description'         => __( 'All registered Bricks element types with metadata (name, label, category, nestable).', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::list_element_types_schema(),
				'output_schema'       => Reference::list_element_types_output_schema(),
				'execute_callback'    => [ Reference::class, 'list_element_types' ],
				'permission_callback' => [ Reference::class, 'builder_access_permission' ],
				'meta'                => [
					'mcp'          => [
						'public' => true,
						'type'   => 'resource',
					],
					'uri'          => 'bricks://reference/element-catalog',
					'mimeType'     => 'application/json',
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-element-schema',
			[
				'label'               => __( 'Get element schema', 'bricks' ),
				'description'         => __( 'Full settings schema (controls, groups, defaults) for a specific element type.', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::get_element_schema_schema(),
				'output_schema'       => Reference::get_element_schema_output_schema(),
				'execute_callback'    => [ Reference::class, 'get_element_schema' ],
				'permission_callback' => [ Reference::class, 'builder_access_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-query-loop-types',
			[
				'label'               => __( 'Bricks query loop types', 'bricks' ),
				'description'         => __( 'List the exact runtime query loop objectType values after Bricks core, dynamic-data providers, WooCommerce, and custom filters have registered them. Use before building provider-backed loops such as ACF repeaters, Meta Box groups, JetEngine relations, or WooCommerce cart loops.', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::list_query_loop_types_schema(),
				'output_schema'       => Reference::list_query_loop_types_output_schema(),
				'execute_callback'    => [ Reference::class, 'list_query_loop_types' ],
				'permission_callback' => [ Reference::class, 'builder_access_permission' ],
				'meta'                => [
					'mcp'          => [
						'public' => true,
						'type'   => 'resource',
					],
					'uri'          => 'bricks://reference/query-loop-types',
					'mimeType'     => 'application/json',
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-dynamic-tag',
			[
				'label'               => __( 'Preview dynamic tag', 'bricks' ),
				'description'         => __( 'Render a dynamic-data expression against a real post so you can verify the output before committing it to a template. Accepts full expressions with modifiers (for example `{post_title:plain}` or `Hello {user_first_name @fallback:\'there\'}`). Returns the rendered value alongside an `isEmpty` flag, which catches tags that would silently disappear in production. Read-only.', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::preview_dynamic_tag_schema(),
				'output_schema'       => Reference::preview_dynamic_tag_output_schema(),
				'execute_callback'    => [ Reference::class, 'preview_dynamic_tag' ],
				'permission_callback' => [ Reference::class, 'preview_dynamic_tag_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-dynamic-data-tags',
			[
				'label'               => __( 'Bricks dynamic data tags', 'bricks' ),
				'description'         => __( 'List available dynamic-data tags with syntax, labels, and provider groups. Pass `postId` to scope results to tags applicable on that post (ACF groups, Woo product tags, JetEngine CCTs). Pass `includeModifiers: true` to include the modifier grammar (positional `:plain`, `:link`, `:image`, `:{number}` and key-value `@fallback`, `@sanitize`, `@date`). Paginated.', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::list_dynamic_data_tags_schema(),
				'output_schema'       => Reference::list_dynamic_data_tags_output_schema(),
				'execute_callback'    => [ Reference::class, 'list_dynamic_data_tags' ],
				'permission_callback' => [ Reference::class, 'list_dynamic_data_tags_permission' ],
				'meta'                => [
					'mcp'          => [
						'public' => true,
						'type'   => 'resource',
					],
					'uri'          => 'bricks://reference/dynamic-data-tags',
					'mimeType'     => 'application/json',
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-builder-guide',
			[
				'label'               => __( 'Get builder guide', 'bricks' ),
				'description'         => __( 'Reference documentation for generating valid Bricks element JSON. Requires a topic: elements, layout, styling, dynamic-data, query-loops, or components.', 'bricks' ),
				'category'            => 'bricks-reference',
				'input_schema'        => Reference::get_builder_guide_schema(),
				'output_schema'       => Reference::get_builder_guide_output_schema(),
				'execute_callback'    => [ Reference::class, 'get_builder_guide' ],
				'permission_callback' => [ Reference::class, 'builder_access_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register element abilities
	 *
	 * @since 2.4
	 */
	private function register_element_abilities() {
		$this->register(
			'bricks/get-page-structure',
			[
				'label'               => __( 'Get page structure', 'bricks' ),
				'description'         => __( 'Lightweight tree view of page elements for understanding layout without full settings data. Optionally filter to a specific element and/or descendant depth.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::get_page_structure_schema(),
				'output_schema'       => Elements::get_page_structure_output_schema(),
				'execute_callback'    => [ Elements::class, 'get_page_structure' ],
				'permission_callback' => [ Elements::class, 'read_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-page-elements',
			[
				'label'               => __( 'Get page elements', 'bricks' ),
				'description'         => __( 'Full element data for a page/template with all settings. Optionally filter to a specific element and its children.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::get_page_elements_schema(),
				'output_schema'       => Elements::get_page_elements_output_schema(),
				'execute_callback'    => [ Elements::class, 'get_page_elements' ],
				'permission_callback' => [ Elements::class, 'read_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-page-elements',
			[
				'label'               => __( 'Set page elements', 'bricks' ),
				'description'         => __( 'Replace the entire element tree on a page or template. For flat input, sibling order follows the submitted array, not existing children arrays; align both before replacing a saved tree. Destructive: every existing element is overwritten. A pre-save revision is captured automatically; pass the returned `revisionId` to `bricks/restore-revision` to undo. Granular alternatives: `bricks/add-element`, `bricks/update-element`, `bricks/remove-element`.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::set_page_elements_schema(),
				'output_schema'       => Elements::set_page_elements_output_schema(),
				'execute_callback'    => [ Elements::class, 'set_page_elements' ],
				'permission_callback' => [ Elements::class, 'write_elements_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/add-element',
			[
				'label'               => __( 'Add element', 'bricks' ),
				'description'         => __( 'Add one or more elements at a specific position. Supports nested children format and component instances.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::add_element_schema(),
				'output_schema'       => Elements::add_element_output_schema(),
				'execute_callback'    => [ Elements::class, 'add_element' ],
				'permission_callback' => [ Elements::class, 'add_element_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-element',
			[
				'label'               => __( 'Update element', 'bricks' ),
				'description'         => __( 'Partial-merge update of an existing element\'s settings. Only provided keys are updated. Pass `null` or `""` for a key to delete it (matches the builder, which removes a key when its control is cleared). Supports dryRun and compact response options.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::update_element_schema(),
				'output_schema'       => Elements::update_element_output_schema(),
				'execute_callback'    => [ Elements::class, 'update_element' ],
				'permission_callback' => [ Elements::class, 'edit_element_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/batch-update-elements',
			[
				'label'               => __( 'Batch update elements', 'bricks' ),
				'description'         => __( 'Partial-merge update multiple existing elements in one save/revision. Applies the same validation, permission checks, and `null`/`""` deletion sentinels as update-element. Supports dryRun and compact response options.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::batch_update_elements_schema(),
				'output_schema'       => Elements::batch_update_elements_output_schema(),
				'execute_callback'    => [ Elements::class, 'batch_update_elements' ],
				'permission_callback' => [ Elements::class, 'batch_update_elements_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/remove-element',
			[
				'label'               => __( 'Remove element', 'bricks' ),
				'description'         => __( 'Remove an element and, optionally, all of its children. Destructive: a pre-save revision is captured automatically. Pass the returned `revisionId` to `bricks/restore-revision` to undo.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::remove_element_schema(),
				'output_schema'       => Elements::remove_element_output_schema(),
				'execute_callback'    => [ Elements::class, 'remove_element' ],
				'permission_callback' => [ Elements::class, 'delete_element_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-page-settings',
			[
				'label'               => __( 'Get page settings', 'bricks' ),
				'description'         => __( 'Page settings for a post (SEO, body classes, header/footer disable, custom CSS, etc.).', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::post_id_schema(),
				'output_schema'       => Elements::get_page_settings_output_schema(),
				'execute_callback'    => [ Elements::class, 'get_page_settings' ],
				'permission_callback' => [ Elements::class, 'page_settings_read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-page-settings',
			[
				'label'               => __( 'Set page settings', 'bricks' ),
				'description'         => __( 'Set page settings via partial merge. Only provided keys are updated.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::set_page_settings_schema(),
				'output_schema'       => Elements::set_page_settings_output_schema(),
				'execute_callback'    => [ Elements::class, 'set_page_settings' ],
				'permission_callback' => [ Elements::class, 'page_settings_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/find-post',
			[
				'label'               => __( 'Find post', 'bricks' ),
				'description'         => __( 'Search posts, pages, and templates by slug, title, or free-text fragment. Returns only the records the current user can edit, annotated with Bricks metadata (`bricksEnabled`, `hasBricksData`, `locked`, `builderUrl`).', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Elements::find_post_schema(),
				'output_schema'       => Elements::find_post_output_schema(),
				'execute_callback'    => [ Elements::class, 'find_post' ],
				'permission_callback' => [ Elements::class, 'find_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/render-elements',
			[
				'label'               => __( 'Render elements', 'bricks' ),
				'description'         => __( 'Render HTML + CSS for a proposed Bricks element tree without saving, or omit elements to render a post\'s current saved tree. Use responseFormat `summary` for compact post-save verification. Read-only.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Conversion::render_elements_schema(),
				'output_schema'       => Conversion::render_elements_output_schema(),
				'execute_callback'    => [ Conversion::class, 'render_elements' ],
				'permission_callback' => [ Conversion::class, 'render_elements_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/checkout-site-repository',
			[
				'label'               => __( 'Checkout site repository', 'bricks' ),
				'description'         => __( 'Return a cursor-bounded, filterable file manifest for editable Bricks pages, templates, and design resources without loading every complete document. Known design lookups can omit documents and select one resource kind/query. Includes exact page digests and optional best-effort direct dependency hints; hints are not proof that a resource is unused or safe to delete.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Repository::checkout_schema(),
				'output_schema'       => Site_Repository::checkout_output_schema(),
				'execute_callback'    => [ Site_Repository::class, 'checkout' ],
				'permission_callback' => [ Site_Repository::class, 'checkout_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/resolve-agent-file',
			[
				'label'               => __( 'Resolve agent file', 'bricks' ),
				'description'         => __( 'Resolve an exact or unambiguous page or design-resource name, ID, or canonical path to one editable Bricks file and an opaque baseline for a two-call edit. Matching is normalized exact-then-prefix, never fuzzy; incomplete or ambiguous discovery returns summaries only.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Agent_File::resolve_schema(),
				'output_schema'       => Agent_File::resolve_output_schema(),
				'execute_callback'    => [ Agent_File::class, 'resolve' ],
				'permission_callback' => [ Agent_File::class, 'resolve_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/convert-html-css-to-bricks-data',
			[
				'label'               => __( 'Convert HTML/CSS to Bricks data', 'bricks' ),
				'description'         => __( 'Convert raw HTML and/or CSS into Bricks element data, global classes, variables, and CSS-only update suggestions. Read-only: returns converted data without saving. Non-administrators must supply postId for a valid, editable Bricks-enabled target with the necessary Builder permissions, including for HTML conversion. Administrators may omit postId. For CSS-only conversion, a supplied postId also scopes the existing-element context. CSS follows target editing permissions. JavaScript requires unfiltered_html. Code elements retain their existing execution and signature requirements. PHP authoring requires BRICKS_ENABLE_PHP_ABILITIES and the PHP authorization prerequisites. Disallowed converted elements are identified; HTML/CSS page import can omit them and report a partial result. Reconcile `skipped_global_variables` against existing same-name values, persist returned `global_classes` once with `bricks-batch-create-global-classes`, then save the element tree.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Conversion::convert_html_css_to_bricks_data_schema(),
				'output_schema'       => Conversion::convert_html_css_to_bricks_data_output_schema(),
				'execute_callback'    => [ Conversion::class, 'convert_html_css_to_bricks_data' ],
				'permission_callback' => [ Conversion::class, 'convert_html_css_to_bricks_data_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-html-css-page-import',
			[
				'label'               => __( 'Preview HTML/CSS page import', 'bricks' ),
				'description'         => __( 'Compile an authoritative HTML/CSS file pair into a compact, rendered, exact, side-effect-free preview. Returns a short-lived token bound to the normalized page, generated design resources, target, user, and current document and design-system versions.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::preview_html_css_page_import_schema(),
				'output_schema'       => Workspace::html_css_page_import_output_schema(),
				'execute_callback'    => [ Workspace::class, 'preview_html_css_page_import' ],
				'permission_callback' => [ Workspace::class, 'preview_html_css_page_import_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/commit-site-foundation',
			[
				'label'               => __( 'Commit site foundation', 'bricks' ),
				'description'         => __( 'Create a complete greenfield Bricks foundation from one compact manifest: native palette variables, fluid spacing and typography scales, a site-wide root theme style, header/footer templates with global conditions, and a published static homepage. Uses a durable same-key saga and stops before overwriting any saved design foundation.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Foundation::commit_schema(),
				'output_schema'       => Site_Foundation::commit_output_schema(),
				'execute_callback'    => [ Site_Foundation::class, 'commit' ],
				'permission_callback' => [ Site_Foundation::class, 'commit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/commit-html-css-page-import',
			[
				'label'               => __( 'Commit HTML/CSS page import', 'bricks' ),
				'description'         => __( 'First and only call for a known empty page or template body. Submit sibling page sections without a main wrapper or site-wide header/footer landmarks, documentPurpose=page-content, responsive CSS, replaceExisting=false, native-only policies when required, and one stable idempotency key. Use documentPurpose=migration only to preserve an external fragment. Clean commits return a compact authoritative summary by default; warning-bearing candidates retain the full frozen review payload for explicit apply.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::commit_html_css_page_import_schema(),
				'output_schema'       => Workspace::html_css_page_import_output_schema(),
				'execute_callback'    => [ Workspace::class, 'commit_html_css_page_import' ],
				'permission_callback' => [ Workspace::class, 'commit_html_css_page_import_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/checkout-page-workspace',
			[
				'label'               => __( 'Checkout page workspace', 'bricks' ),
				'description'         => __( 'Export one page or template area as a deterministic canonical Bricks file plus a compact dependency manifest. Code-sensitive pages fail closed when the caller lacks execute-code permission; checkout never returns a redacted full-replacement candidate. The returned working copy is not authoritative until it passes preview and token-only apply.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::checkout_page_workspace_schema(),
				'output_schema'       => Workspace::checkout_page_workspace_output_schema(),
				'execute_callback'    => [ Workspace::class, 'checkout_page_workspace' ],
				'permission_callback' => [ Workspace::class, 'checkout_page_workspace_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-page-workspace',
			[
				'label'               => __( 'Preview page workspace', 'bricks' ),
				'description'         => __( 'Validate, normalize, permission-check, render, diff, and freeze an edited canonical page workspace without saving. Code-sensitive candidates fail closed without execute-code permission. Rejects stale page or design-system baselines.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::preview_page_workspace_schema(),
				'output_schema'       => Workspace::html_css_page_import_output_schema(),
				'execute_callback'    => [ Workspace::class, 'preview_page_workspace' ],
				'permission_callback' => [ Workspace::class, 'preview_page_workspace_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/apply-page-workspace',
			[
				'label'               => __( 'Apply page workspace', 'bricks' ),
				'description'         => __( 'Commit the exact canonical page candidate frozen by preview. Apply accepts no mutable page data, rechecks code-sensitive permissions, and fails closed if authority was lost. Uses the same lock, journal, revision, readback, idempotency, and recovery contract as transactional HTML/CSS import.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::apply_html_css_page_import_schema(),
				'output_schema'       => Workspace::html_css_page_import_output_schema(),
				'execute_callback'    => [ Workspace::class, 'apply_page_workspace' ],
				'permission_callback' => [ Workspace::class, 'apply_page_workspace_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/checkout-design-resource-workspace',
			[
				'label'               => __( 'Checkout design resource workspace', 'bricks' ),
				'description'         => __( 'Export one existing global class, global variable, theme style, or component as a deterministic canonical file with exact resource-specific ownership. This update-only workflow deliberately excludes create, delete, rename, and multi-resource atomicity.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design_Workspace::checkout_schema(),
				'output_schema'       => Design_Workspace::output_schema(),
				'execute_callback'    => [ Design_Workspace::class, 'checkout' ],
				'permission_callback' => [ Design_Workspace::class, 'checkout_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-design-resource-workspace',
			[
				'label'               => __( 'Preview design resource workspace', 'bricks' ),
				'description'         => __( 'Side-effect-free validation and diff for one edited design-resource file. Rejects stale item and dependency ownership, immutable IDs, unsupported opaque edits, and unacknowledged component slot removal, then freezes the exact focused mutation in a short-lived user-bound token.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design_Workspace::preview_schema(),
				'output_schema'       => Design_Workspace::output_schema(),
				'execute_callback'    => [ Design_Workspace::class, 'preview' ],
				'permission_callback' => [ Design_Workspace::class, 'preview_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/apply-design-resource-workspace',
			[
				'label'               => __( 'Apply design resource workspace', 'bricks' ),
				'description'         => __( 'Commit the exact single-resource mutation frozen by preview. Token-only apply rechecks user, execution policy, write permission, exact resource/dependency baseline, and then delegates normalization, referential validation, compare-and-swap, and readback to the existing focused design ability.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design_Workspace::apply_schema(),
				'output_schema'       => Design_Workspace::output_schema(),
				'execute_callback'    => [ Design_Workspace::class, 'apply' ],
				'permission_callback' => [ Design_Workspace::class, 'apply_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/commit-exact-site-edits',
			[
				'label'               => __( 'Commit exact site edits', 'bricks' ),
				'description'         => __( 'Fastest existing-site path for precise edits: replace page or component text, insert an unstyled native page text sibling, update safe component labels and custom attributes, change global-variable values, or update one global-class/theme-style settings leaf in one call. Pages, components, and design resources may be selected by exact ID or exact unique name; elements may be selected by ID or exact unique current text or label. Each operation must provide expectedValue, or literal allowBlindWrite true only for an intentional target. The server performs canonical resolution, checkout, component-property boundary checks, permissions, CAS preview, durable replay, apply, and authoritative readback internally.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Edit_Plan::exact_commit_schema(),
				'output_schema'       => Site_Edit_Plan::commit_output_schema(),
				'execute_callback'    => [ Site_Edit_Plan::class, 'commit_exact' ],
				'permission_callback' => [ Site_Edit_Plan::class, 'exact_commit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/checkout-site-edit-map',
			[
				'label'               => __( 'Checkout site edit map', 'bricks' ),
				'description'         => __( 'Checkout up to 25 explicit targets through the targets array, using {scope: page, postId} or {scope: design, resource, id}. When IDs are known, filter element outlines with elementIds. Canonical files remain server-side; short selectionRef values bind each typed edit to an authenticated exact selection.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Edit_Plan::checkout_schema(),
				'output_schema'       => Site_Edit_Plan::checkout_output_schema(),
				'execute_callback'    => [ Site_Edit_Plan::class, 'checkout' ],
				'permission_callback' => [ Site_Edit_Plan::class, 'checkout_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => false ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-site-edit-plan',
			[
				'label'               => __( 'Preview site edit plan', 'bricks' ),
				'description'         => __( 'Compile up to 64 exact typed text or global-variable edits into the frozen canonical files, recheck caller policy and focused write permissions, and delegate validation, stale-state checks, preparation, and preview-token issuance to Site Changeset. No Bricks data is changed.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Edit_Plan::preview_schema(),
				'output_schema'       => Site_Edit_Plan::preview_output_schema(),
				'execute_callback'    => [ Site_Edit_Plan::class, 'preview' ],
				'permission_callback' => [ Site_Edit_Plan::class, 'preview_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => false ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/commit-site-edit-plan',
			[
				'label'               => __( 'Commit site edit plan', 'bricks' ),
				'description'         => __( 'Common two-call existing-site fast path: submit the short selectionRef advertised by checkout with a supported operation and value. Canonical files are compiled and previewed server-side, then Site Changeset applies or resumes under one stable idempotency key. Repeat the same call to recover a lost response; summary readback is the default.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Edit_Plan::commit_schema(),
				'output_schema'       => Site_Edit_Plan::commit_output_schema(),
				'execute_callback'    => [ Site_Edit_Plan::class, 'commit' ],
				'permission_callback' => [ Site_Edit_Plan::class, 'commit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/checkout-site-changeset',
			[
				'label'               => __( 'Checkout site changeset', 'bricks' ),
				'description'         => __( 'Checkout up to 25 canonical page and design-resource files in one bounded response. Pages are ordered before design resources, so every intermediate pages-first state must be valid. This is a working copy, never an atomic database transaction.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Changeset::checkout_schema(),
				'output_schema'       => Site_Changeset::output_schema(),
				'execute_callback'    => [ Site_Changeset::class, 'checkout' ],
				'permission_callback' => [ Site_Changeset::class, 'checkout_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/preview-site-changeset',
			[
				'label'               => __( 'Preview site changeset', 'bricks' ),
				'description'         => __( 'Focused-preview every edited file without changing Bricks page or design data, then freeze the exact pages-first changeset in a short-lived user and execution-policy-bound token. Create required design definitions separately before consumers.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Changeset::preview_schema(),
				'output_schema'       => Site_Changeset::output_schema(),
				'execute_callback'    => [ Site_Changeset::class, 'preview' ],
				'permission_callback' => [ Site_Changeset::class, 'preview_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		foreach ( [
			'apply'  => 'Apply',
			'resume' => 'Resume'
		] as $callback => $verb ) {
			$this->register(
				'bricks/' . $callback . '-site-changeset',
				[
					/* translators: %s: Changeset action, either Apply or Resume. */
					'label'               => sprintf( __( '%s site changeset', 'bricks' ), $verb ),
					'description'         => __( 'Run a bounded time slice of the durable changeset. Every inner token and derived key is checkpointed before mutation; just-in-time checkout/rebase preserves target and dependency CAS. Results explicitly report committed, in-progress, partial-commit, failed-before-commit, or manual-recovery state and never claim cross-resource atomicity.', 'bricks' ),
					'category'            => 'bricks-elements',
					'input_schema'        => Site_Changeset::apply_schema(),
					'output_schema'       => Site_Changeset::output_schema(),
					'execute_callback'    => [ Site_Changeset::class, $callback ],
					'permission_callback' => [ Site_Changeset::class, 'apply_permission' ],
					'meta'                => [
						'mcp'          => [ 'public' => true ],
						'annotations'  => [
							'destructive' => true,
							'idempotent'  => true,
						],
						'show_in_rest' => true,
					],
				]
			);
		}

		$this->register(
			'bricks/commit-agent-file',
			[
				'label'               => __( 'Commit agent file', 'bricks' ),
				'description'         => __( 'Validate, durably checkpoint, apply, and authoritatively read back one resolved Bricks file in the second call of a focused edit. Returns a compact digest/version/change summary by default; pass responseFormat=document only when the complete canonical file is needed. The idempotency key is bound to the complete candidate before preview or mutation; retries resume the existing Site Changeset journal and never create a hidden replacement preview.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Agent_File::commit_schema(),
				'output_schema'       => Agent_File::commit_output_schema(),
				'execute_callback'    => [ Agent_File::class, 'commit' ],
				'permission_callback' => [ Agent_File::class, 'commit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/resolve-site-changeset',
			[
				'label'               => __( 'Resolve site changeset recovery', 'bricks' ),
				'description'         => __( 'Delete one terminal durable changeset journal only after an operator has inspected and resolved its partial or manual-recovery state. Requires the exact idempotency key, changeset digest, and literal acknowledgement. This removes recovery evidence but does not roll back or change Bricks data.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Site_Changeset::resolve_schema(),
				'output_schema'       => Site_Changeset::resolve_output_schema(),
				'execute_callback'    => [ Site_Changeset::class, 'resolve' ],
				'permission_callback' => [ Site_Changeset::class, 'resolve_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/apply-html-css-page-import',
			[
				'label'               => __( 'Apply HTML/CSS page import', 'bricks' ),
				'description'         => __( 'Commit the exact candidate frozen by preview without resending its HTML, CSS, or Bricks tree. Uses database-session fencing, a durable idempotency journal, conditional writes, readback verification, and safe before-image compensation.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Workspace::apply_html_css_page_import_schema(),
				'output_schema'       => Workspace::html_css_page_import_output_schema(),
				'execute_callback'    => [ Workspace::class, 'apply_html_css_page_import' ],
				'permission_callback' => [ Workspace::class, 'apply_html_css_page_import_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register template abilities
	 *
	 * @since 2.4
	 */
	private function register_template_abilities() {
		$this->register(
			'bricks/list-templates',
			[
				'label'               => __( 'List templates', 'bricks' ),
				'description'         => __( 'List all Bricks templates with type, status, and condition summary. Supports pagination and type filter.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::list_templates_schema(),
				'output_schema'       => Templates::list_templates_output_schema(),
				'execute_callback'    => [ Templates::class, 'list_templates' ],
				'permission_callback' => [ Templates::class, 'list_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-template',
			[
				'label'               => __( 'Get template', 'bricks' ),
				'description'         => __( 'Fetch a single Bricks template by ID. Returns title, type, status, edit URL, and by default settings plus the element tree for the rendered template area. Pass includeElements=false or includeSettings=false for a lighter response; use get-page-elements when you need scoped element-tree reads.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::get_template_schema(),
				'output_schema'       => Templates::get_template_output_schema(),
				'execute_callback'    => [ Templates::class, 'get_template' ],
				'permission_callback' => [ Templates::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-template',
			[
				'label'               => __( 'Create template', 'bricks' ),
				'description'         => __( 'Create a new Bricks template (`bricks_template` post type). Pass `type` (header / footer / content / section / popup / archive / search / error / password_protection, or a WooCommerce type such as wc_product), optional initial `elements` tree, and optional `status` (`draft`, `publish`, or `private`; default `draft`). Returns `templateId`, `editUrl`, and `status`. Note: header and footer templates are automatically wrapped in `<header>` / `<footer>` landmarks at render time. Do NOT set `tag: "header"` (or `customTag: "header"`) on the template root, because that produces nested landmarks. Use `section` / `block` / `container` with their default tag.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::create_template_schema(),
				'output_schema'       => Templates::create_template_output_schema(),
				'execute_callback'    => [ Templates::class, 'create_template' ],
				'permission_callback' => [ Templates::class, 'create_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-template',
			[
				'label'               => __( 'Delete template', 'bricks' ),
				'description'         => __( 'Permanently delete a Bricks template. Destructive and unrecoverable: this calls `wp_delete_post` with `force=true` and there is no Bricks revision path to undo. The response includes a `beforeDelete` snapshot (title, type, element count) so you can show what was removed.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::template_id_schema(),
				'output_schema'       => Templates::delete_template_output_schema(),
				'execute_callback'    => [ Templates::class, 'delete_template' ],
				'permission_callback' => [ Templates::class, 'delete_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-template-settings',
			[
				'label'               => __( 'Get template settings', 'bricks' ),
				'description'         => __( 'Template settings including conditions, header sticky/popup config, password protection, preview.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::template_id_schema(),
				'output_schema'       => Templates::get_template_settings_output_schema(),
				'execute_callback'    => [ Templates::class, 'get_template_settings' ],
				'permission_callback' => [ Templates::class, 'settings_read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-template-settings',
			[
				'label'               => __( 'Set template settings', 'bricks' ),
				'description'         => __( 'Set template settings via partial merge. Covers conditions, header sticky/popup config, password protection.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::set_template_settings_schema(),
				'output_schema'       => Templates::set_template_settings_output_schema(),
				'execute_callback'    => [ Templates::class, 'set_template_settings' ],
				'permission_callback' => [ Templates::class, 'settings_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-template-conditions',
			[
				'label'               => __( 'Set template conditions', 'bricks' ),
				'description'         => __( 'Replace the `templateConditions` array on a template. Each condition has a `main` kind (`any`, `frontpage`, `postType`, `archiveType`, `search`, `error`, `terms`, or `ids`) plus kind-specific fields. Section templates can also include `hookName` and optional `hookPriority` to render on a WordPress hook. Legacy `main=hook` input is normalized to `any` when `hookName` is present. Full replacement, not a merge.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::set_template_conditions_schema(),
				'output_schema'       => Templates::set_template_conditions_output_schema(),
				'execute_callback'    => [ Templates::class, 'set_template_conditions' ],
				'permission_callback' => [ Templates::class, 'settings_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-template-sources',
			[
				'label'               => __( 'List template sources', 'bricks' ),
				'description'         => __( 'List remote template-library sources: Bricks built-ins (`wireframes`, `design-sets`), admin-configured remote template URLs, and group rows. Returns the raw source records used by the builder source picker. Use a returned `id` or `url` with `bricks/list-remote-templates` to inspect remote templates or `bricks/insert-remote-template` to insert one.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::list_template_sources_schema(),
				'output_schema'       => Templates::list_template_sources_output_schema(),
				'execute_callback'    => [ Templates::class, 'list_template_sources' ],
				'permission_callback' => [ Templates::class, 'remote_templates_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-remote-templates',
			[
				'label'               => __( 'List remote templates', 'bricks' ),
				'description'         => __( 'List templates available in a built-in source (`wireframes` or `design-sets`) or an admin-configured remote template URL. Use a source `id` or `url` from `bricks/list-template-sources`. Returns paginated template metadata by default; request a specific page/perPage and use `mode: "full"` only for targeted inspection.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::list_remote_templates_schema(),
				'output_schema'       => Templates::list_remote_templates_output_schema(),
				'execute_callback'    => [ Templates::class, 'list_remote_templates' ],
				'permission_callback' => [ Templates::class, 'remote_templates_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/insert-template',
			[
				'label'               => __( 'Insert template', 'bricks' ),
				'description'         => __( 'Copy a local Bricks template\'s element tree into a target post at a chosen position. Supported positions: `replace` (overwrite everything), `start` and `end` (root-level top or bottom; defaults to `end`), `before` and `after` (sibling of `anchorElementId`), and `append` and `prepend` (child of `anchorElementId`). Every source element ID is regenerated to avoid collisions. A revision is captured before the write, so `replace` is destructive but reversible via `bricks/restore-revision`. For remote library templates use `bricks/insert-remote-template`.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::insert_template_schema(),
				'output_schema'       => Templates::insert_template_output_schema(),
				'execute_callback'    => [ Templates::class, 'insert_template' ],
				'permission_callback' => [ Templates::class, 'insert_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/insert-remote-template',
			[
				'label'               => __( 'Insert remote template', 'bricks' ),
				'description'         => __( 'Fetch a template from a remote template-library source such as `wireframes` or `design-sets`, optionally import missing design assets, and insert its element tree into a target post. Uses the same placement options as `bricks/insert-template`. Existing local design assets are preserved and the target post receives a pre-save revision.', 'bricks' ),
				'category'            => 'bricks-templates',
				'input_schema'        => Templates::insert_remote_template_schema(),
				'output_schema'       => Templates::insert_remote_template_output_schema(),
				'execute_callback'    => [ Templates::class, 'insert_remote_template' ],
				'permission_callback' => [ Templates::class, 'insert_remote_template_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register design system abilities
	 *
	 * @since 2.4
	 */
	private function register_design_abilities() {
		$this->register(
			'bricks/get-design-context',
			[
				'label'               => __( 'Get design context', 'bricks' ),
				'description'         => __( 'One-call summary of the site design context: color palettes, global classes, theme styles, components, global variables, and responsive breakpoints. Pass `responseFormat: "summary"` for capped orientation output, or `includeUsage: true` to add `usedOnPosts` per component.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::get_design_context_schema(),
				'output_schema'       => Design::get_design_context_output_schema(),
				'execute_callback'    => [ Design::class, 'get_design_context' ],
				'permission_callback' => [ Design::class, 'get_design_context_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		// Color palettes
		$this->register(
			'bricks/list-color-palettes',
			[
				'label'               => __( 'List color palettes', 'bricks' ),
				'description'         => __( 'All palettes with complete color records, paletteDigest/colorDigest values, and compact resource ownership. Pass the returned ownership into creates, or add the target digest as itemDigest for updates and deletes.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::list_color_palettes_schema(),
				'output_schema'       => Design::list_color_palettes_output_schema(),
				'execute_callback'    => [ Design::class, 'list_color_palettes' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-color',
			[
				'label'               => __( 'Create color', 'bricks' ),
				'description'         => __( 'Append a builder-shaped color to an existing palette using exact resource ownership from list-color-palettes. Root colors require `light`; shade rows require `parent` and `type`. Returns verified ownership and authoritative color/palette digests.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::create_color_schema(),
				'output_schema'       => Design::create_color_output_schema(),
				'execute_callback'    => [ Design::class, 'create_color' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-color',
			[
				'label'               => __( 'Update color', 'bricks' ),
				'description'         => __( 'Partial-merge update of one palette color using ownership plus its colorDigest as itemDigest. Returns verified ownership and digests. Raw variable renames that would require a cross-resource reference rewrite fail closed without changing palette or reference data.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::update_color_schema(),
				'output_schema'       => Design::update_color_output_schema(),
				'execute_callback'    => [ Design::class, 'update_color' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-color-palette',
			[
				'label'               => __( 'Create color palette', 'bricks' ),
				'description'         => __( 'Create a uniquely named color palette using exact resource ownership from list-color-palettes. Optional builder-shaped colors preserve supplied IDs and shade relationships. Returns verified ownership and authoritative digests.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::create_color_palette_schema(),
				'output_schema'       => Design::create_color_palette_output_schema(),
				'execute_callback'    => [ Design::class, 'create_color_palette' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-color-palette',
			[
				'label'               => __( 'Update color palette', 'bricks' ),
				'description'         => __( 'Rename a color palette using ownership plus its paletteDigest as itemDigest. Does not modify its colors and preserves opaque palette fields. Returns verified ownership and digest.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::update_color_palette_schema(),
				'output_schema'       => Design::update_color_palette_output_schema(),
				'execute_callback'    => [ Design::class, 'update_color_palette' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-color',
			[
				'label'               => __( 'Delete color', 'bricks' ),
				'description'         => __( 'Remove one color using ownership plus its colorDigest as itemDigest. Deleting a root also removes its shades. Removing any named CSS variables requires literal allowOrphans=true even when fresh bounded reference evidence is zero.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_color_schema(),
				'output_schema'       => Design::delete_color_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_color' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-color-palette',
			[
				'label'               => __( 'Delete color palette', 'bricks' ),
				'description'         => __( 'Permanently delete a palette and all its colors using ownership plus its paletteDigest as itemDigest. Removing any named CSS variables requires literal allowOrphans=true even when fresh bounded reference evidence is zero.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_color_palette_schema(),
				'output_schema'       => Design::delete_color_palette_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_color_palette' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		// Global classes
		$this->register(
			'bricks/list-global-classes',
			[
				'label'               => __( 'List global classes', 'bricks' ),
				'description'         => __( 'All global classes with complete settings, per-class itemDigest, resource ownership, lock ownership, and category ownership. Pass the relevant returned ownership envelopes unchanged when mutating a class.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::list_global_classes_schema(),
				'output_schema'       => Design::list_global_classes_output_schema(),
				'execute_callback'    => [ Design::class, 'list_global_classes' ],
				'permission_callback' => [ Design::class, 'global_classes_read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-global-class',
			[
				'label'               => __( 'Create global class', 'bricks' ),
				'description'         => __( 'Create a new global class with CSS settings and optional responsive overrides. A non-empty category requires categoryOwnership from list-global-classes and is guarded in the final class commit; split-authority categorized writes fail closed.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::create_global_class_schema(),
				'output_schema'       => Design::create_global_class_output_schema(),
				'execute_callback'    => [ Design::class, 'create_global_class' ],
				'permission_callback' => [ Design::class, 'create_global_class_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/batch-create-global-classes',
			[
				'label'               => __( 'Batch create global classes', 'bricks' ),
				'description'         => __( 'Validate and create many global classes in one atomic write. Requires exact class ownership and, when any class is categorized, categoryOwnership from the same latest read. Category existence and authority are guarded. Prefer this for converter output; provided 6-character IDs are preserved.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::batch_create_global_classes_schema(),
				'output_schema'       => Design::batch_create_global_classes_output_schema(),
				'execute_callback'    => [ Design::class, 'batch_create_global_classes' ],
				'permission_callback' => [ Design::class, 'create_global_class_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-global-class',
			[
				'label'               => __( 'Update global class', 'bricks' ),
				'description'         => __( 'Update an existing global class without losing opaque stored fields. Requires class ownership with the full-row itemDigest plus lock ownership; assigning a non-empty category also requires categoryOwnership from the same read. Exact lock and category rows are guarded in the final commit. Settings merge and selectors replace.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::update_global_class_schema(),
				'output_schema'       => Design::update_global_class_output_schema(),
				'execute_callback'    => [ Design::class, 'update_global_class' ],
				'permission_callback' => [ Design::class, 'edit_global_class_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-global-class',
			[
				'label'               => __( 'Delete global class', 'bricks' ),
				'description'         => __( 'Permanently delete a global class. Requires class ownership, lock ownership, and literal allowOrphans=true even when fresh bounded usage evidence is zero. Shared-authority deletes fail closed when the local scan is incomplete.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_global_class_schema(),
				'output_schema'       => Design::delete_global_class_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_global_class' ],
				'permission_callback' => [ Design::class, 'delete_global_class_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		// Theme styles
		$this->register(
			'bricks/get-theme-styles',
			[
				'label'               => __( 'Get theme styles', 'bricks' ),
				'description'         => __( 'Theme styles with exact resource ownership and per-style digests calculated from complete authoritative rows. Without a filter returns summaries; with a style ID returns its full unredacted settings.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::get_theme_styles_schema(),
				'output_schema'       => Design::get_theme_styles_output_schema(),
				'execute_callback'    => [ Design::class, 'get_theme_styles' ],
				'permission_callback' => [ Design::class, 'theme_styles_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-theme-styles',
			[
				'label'               => __( 'List theme styles', 'bricks' ),
				'description'         => __( 'Paginated theme-style summaries with exact resource ownership and full-row itemDigest. Use get-theme-styles with an id for the complete settings.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::list_theme_styles_schema(),
				'output_schema'       => Design::list_theme_styles_output_schema(),
				'execute_callback'    => [ Design::class, 'list_theme_styles' ],
				'permission_callback' => [ Design::class, 'theme_styles_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-theme-style',
			[
				'label'               => __( 'Create theme style', 'bricks' ),
				'description'         => __( 'Create a new theme style through a fresh exact append compare-and-swap. Returns refreshed ownership and a digest of the complete stored style. Pass `conditions: [{ main: "any" }]` for site-wide defaults.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::create_theme_style_schema(),
				'output_schema'       => Design::create_theme_style_output_schema(),
				'execute_callback'    => [ Design::class, 'create_theme_style' ],
				'permission_callback' => [ Design::class, 'theme_styles_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-theme-style',
			[
				'label'               => __( 'Update theme style', 'bricks' ),
				'description'         => __( 'Update an existing theme style with exact ownership and its full-row itemDigest. Opaque stored fields are preserved. Settings merge by default; `replace: true` replaces settings, while label and conditions replace when provided.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::update_theme_style_schema(),
				'output_schema'       => Design::update_theme_style_output_schema(),
				'execute_callback'    => [ Design::class, 'update_theme_style' ],
				'permission_callback' => [ Design::class, 'theme_styles_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-theme-style',
			[
				'label'               => __( 'Delete theme style', 'bricks' ),
				'description'         => __( 'Delete a theme style with exact ownership and its full-row itemDigest. Styles with settings or conditions require literal acknowledgeStyleRemoval=true after reviewing fresh bounded impact evidence. Returns the complete unredacted deleted row for manual recovery.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_theme_style_schema(),
				'output_schema'       => Design::delete_theme_style_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_theme_style' ],
				'permission_callback' => [ Design::class, 'theme_styles_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		// Components
		$this->register(
			'bricks/list-components',
			[
				'label'               => __( 'List components', 'bricks' ),
				'description'         => __( 'List all components with labels, property summaries, componentDigest values, and the atomically paired designSystemVersion. Use the digest and version together for safe update/delete calls. Supports pagination.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::list_components_schema(),
				'output_schema'       => Design::list_components_output_schema(),
				'execute_callback'    => [ Design::class, 'list_components' ],
				'permission_callback' => [ Design::class, 'components_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-component',
			[
				'label'               => __( 'Get component', 'bricks' ),
				'description'         => __( 'Component elements, properties, variants, slots, authoritative componentDigest, and atomically paired designSystemVersion for safe follow-up writes. Reports componentPayloadRedacted when code-sensitive settings are hidden.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::get_component_schema(),
				'output_schema'       => Design::get_component_output_schema(),
				'execute_callback'    => [ Design::class, 'get_component' ],
				'permission_callback' => [ Design::class, 'components_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-component',
			[
				'label'               => __( 'Create component', 'bricks' ),
				'description'         => __( 'Create a new component from an elements subtree. Labels must be unique (`bricks_conflict_duplicate_component_name` on collision). Incoming element ids are regenerated, property connections and parent-property references are remapped, and builder metadata is stamped so the component is not treated as a beta component.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::create_component_schema(),
				'output_schema'       => Design::create_component_output_schema(),
				'execute_callback'    => [ Design::class, 'create_component' ],
				'permission_callback' => [ Design::class, 'create_component_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-component',
			[
				'label'               => __( 'Update component', 'bricks' ),
				'description'         => __( 'Update an existing component. Requires expectedDesignSystemVersion and expectedComponentDigest from a recent list/get response. Only provided fields are changed, but `elements` replaces the entire component tree, so read the component first with `bricks/get-component` before editing one element. When componentPayloadRedacted is true, do not replace elements from that redacted payload. Slot IDs are preserved where possible; removing any existing slot requires explicit `allowSlotOrphans=true`, even when the fresh usage scan finds no content.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::update_component_schema(),
				'output_schema'       => Design::update_component_output_schema(),
				'execute_callback'    => [ Design::class, 'update_component' ],
				'permission_callback' => [ Design::class, 'update_component_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-component',
			[
				'label'               => __( 'Delete component', 'bricks' ),
				'description'         => __( 'Remove a component from the global store. Requires expectedDesignSystemVersion, expectedComponentDigest, expectedUsageCount from a recent read, and explicit `allowOrphans=true` for every deletion, including zero known uses. The digest covers the complete authoritative component. Usage is current review evidence, not a guarantee that every reference was found.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_component_schema(),
				'output_schema'       => Design::delete_component_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_component' ],
				'permission_callback' => [ Design::class, 'delete_component_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/extract-component-from-elements',
			[
				'label'               => __( 'Extract component from elements', 'bricks' ),
				'description'         => __( 'Lift an existing element subtree out of a post and into a new global component, replacing the original subtree with a component instance. External references inside the subtree (global class names, CSS variables) are preserved as-is, so the component stays coupled to whatever tokens the original elements used. A revision is captured before the post write.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::extract_component_from_elements_schema(),
				'output_schema'       => Design::extract_component_from_elements_output_schema(),
				'execute_callback'    => [ Design::class, 'extract_component_from_elements' ],
				'permission_callback' => [ Design::class, 'extract_component_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		// Global variables
		$this->register(
			'bricks/list-global-variables',
			[
				'label'               => __( 'List global variables', 'bricks' ),
				'description'         => __( 'All global CSS variables and categories with authority-correct ownership plus ready full-row itemOwnership envelopes.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::list_global_variables_schema(),
				'output_schema'       => Design::list_global_variables_output_schema(),
				'execute_callback'    => [ Design::class, 'list_global_variables' ],
				'permission_callback' => [ Design::class, 'global_variables_read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-global-variables',
			[
				'label'               => __( 'Set global variables', 'bricks' ),
				'description'         => __( 'Ownership-guarded variable upsert. Accepts copy-ready rows from list-global-variables, requires the variable and category ownerships from one latest read, preserves opaque fields, and rejects or compensates category drift.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::set_global_variables_schema(),
				'output_schema'       => Design::set_global_variables_output_schema(),
				'execute_callback'    => [ Design::class, 'set_global_variables' ],
				'permission_callback' => [ Design::class, 'global_variables_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-global-variable-categories',
			[
				'label'               => __( 'Set global variable categories', 'bricks' ),
				'description'         => __( 'Replace the complete global-variable category list with exact category and variable ownership from one latest read. Referenced removals and scale-config changes fail closed because no acknowledgement can make the required variable regeneration atomic. Returns refreshed ownership and ready per-category item ownership.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::set_global_variable_categories_schema(),
				'output_schema'       => Design::set_global_variable_categories_output_schema(),
				'execute_callback'    => [ Design::class, 'set_global_variable_categories' ],
				'permission_callback' => [ Design::class, 'global_variables_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'idempotent'  => true,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-global-variable',
			[
				'label'               => __( 'Delete global variable', 'bricks' ),
				'description'         => __( 'Delete one global CSS variable with its ready itemOwnership from the latest read. Requires literal allowOrphans=true even when fresh bounded reference evidence is zero; shared-authority deletes fail closed when the local scan is incomplete. Success verifies the variable remains absent.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::delete_global_variable_schema(),
				'output_schema'       => Design::delete_global_variable_output_schema(),
				'execute_callback'    => [ Design::class, 'delete_global_variable' ],
				'permission_callback' => [ Design::class, 'global_variables_write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/generate-scale-variables',
			[
				'label'               => __( 'Generate scale variables', 'bricks' ),
				'description'         => __( 'Preview fluid clamp() variables with a deterministic generationDigest and exact variable/category save ownership. Direct scale persistence fails closed until the two option stores can be committed atomically; persist reviewed rows through ownership-guarded abilities.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::generate_scale_variables_schema(),
				'output_schema'       => Design::generate_scale_variables_output_schema(),
				'execute_callback'    => [ Design::class, 'generate_scale_variables' ],
				'permission_callback' => [ Design::class, 'generate_scale_variables_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/audit-design-system',
			[
				'label'               => __( 'Audit design system', 'bricks' ),
				'description'         => __( 'Read-only scan for design-system rot: orphan references (errors), theme styles without conditions (warnings), unused resources and palette fragmentation (infos). Scans all Bricks posts + templates in one pass. Pass `scope` to narrow, or `skipPostScan: true` for a fast config-only check.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::audit_design_system_schema(),
				'output_schema'       => Design::audit_design_system_output_schema(),
				'execute_callback'    => [ Design::class, 'audit_design_system' ],
				'permission_callback' => [ Design::class, 'audit_design_system_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/generate-color-shades',
			[
				'label'               => __( 'Generate color shades', 'bricks' ),
				'description'         => __( 'Generate builder-equivalent light, dark, or transparent shades. A preview targeting an existing palette color returns saveOwnership; pass it unchanged with save:true to replace the matching shade graph through owned CAS. Inline baseColor previews remain persistence-free.', 'bricks' ),
				'category'            => 'bricks-design',
				'input_schema'        => Design::generate_color_shades_schema(),
				'output_schema'       => Design::generate_color_shades_output_schema(),
				'execute_callback'    => [ Design::class, 'generate_color_shades' ],
				'permission_callback' => [ Design::class, 'color_palette_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Content lifecycle (generic WP CRUD - fills core gaps)
	// ==================================================================

	/**
	 * Register content lifecycle abilities
	 *
	 * @since 2.4
	 */
	private function register_content_abilities() {
		$this->register(
			'bricks/create-post',
			[
				'label'               => __( 'Create post', 'bricks' ),
				'description'         => __( 'Create a new page or post. Optionally seed a Bricks element tree atomically with creation. Returns the new post ID, builder URL, and revision ID.', 'bricks' ),
				'category'            => 'bricks-content',
				'input_schema'        => Content::create_post_schema(),
				'output_schema'       => Content::create_post_output_schema(),
				'execute_callback'    => [ Content::class, 'create_post' ],
				'permission_callback' => [ Content::class, 'create_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/duplicate-post',
			[
				'label'               => __( 'Duplicate post', 'bricks' ),
				'description'         => __( 'Clone a post/page including its Bricks element tree, page settings, and featured image. The duplicate is created as a draft by default.', 'bricks' ),
				'category'            => 'bricks-content',
				'input_schema'        => Content::duplicate_post_schema(),
				'output_schema'       => Content::duplicate_post_output_schema(),
				'execute_callback'    => [ Content::class, 'duplicate_post' ],
				'permission_callback' => [ Content::class, 'duplicate_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-post',
			[
				'label'               => __( 'Delete post', 'bricks' ),
				'description'         => __( 'Move a post or page to trash (recoverable) or permanently delete it. Lock-aware: refuses to delete a post that another user is currently editing.', 'bricks' ),
				'category'            => 'bricks-content',
				'input_schema'        => Content::delete_post_schema(),
				'output_schema'       => Content::delete_post_output_schema(),
				'execute_callback'    => [ Content::class, 'delete_post' ],
				'permission_callback' => [ Content::class, 'delete_post_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Revisions (Bricks-specific - postmeta on revision rows)
	// ==================================================================

	/**
	 * Register revision abilities
	 *
	 * @since 2.4
	 */
	private function register_revision_abilities() {
		$this->register(
			'bricks/list-revisions',
			[
				'label'               => __( 'List revisions', 'bricks' ),
				'description'         => __( 'List Bricks-aware revisions for a post. Shows which areas (content/header/footer) each revision has data for.', 'bricks' ),
				'category'            => 'bricks-revisions',
				'input_schema'        => Revisions::list_revisions_schema(),
				'output_schema'       => Revisions::list_revisions_output_schema(),
				'execute_callback'    => [ Revisions::class, 'list_revisions' ],
				'permission_callback' => [ Revisions::class, 'list_revisions_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-revision',
			[
				'label'               => __( 'Get revision', 'bricks' ),
				'description'         => __( 'Fetch the full Bricks element tree from a specific revision. Read-only; use `bricks/restore-revision` to roll back.', 'bricks' ),
				'category'            => 'bricks-revisions',
				'input_schema'        => Revisions::get_revision_schema(),
				'output_schema'       => Revisions::get_revision_output_schema(),
				'execute_callback'    => [ Revisions::class, 'get_revision' ],
				'permission_callback' => [ Revisions::class, 'get_revision_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/restore-revision',
			[
				'label'               => __( 'Restore revision', 'bricks' ),
				'description'         => __( 'Roll back a post to a previous revision. Copies Bricks postmeta (element tree + page settings) from the revision onto the current post. Takes a new snapshot first so the restore itself is reversible.', 'bricks' ),
				'category'            => 'bricks-revisions',
				'input_schema'        => Revisions::restore_revision_schema(),
				'output_schema'       => Revisions::restore_revision_output_schema(),
				'execute_callback'    => [ Revisions::class, 'restore_revision' ],
				'permission_callback' => [ Revisions::class, 'restore_revision_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Media (generic WP operations - fills core gaps)
	// ==================================================================

	/**
	 * Register media abilities
	 *
	 * @since 2.4
	 */
	private function register_media_abilities() {
		$this->register(
			'bricks/upload-media',
			[
				'label'               => __( 'Upload media', 'bricks' ),
				'description'         => __( 'Upload a media file from a URL or base64 data. Returns the attachment object with all size variants, ready to use in Bricks image elements.', 'bricks' ),
				'category'            => 'bricks-media',
				'input_schema'        => Content::upload_media_schema(),
				'output_schema'       => Content::upload_media_output_schema(),
				'execute_callback'    => [ Content::class, 'upload_media' ],
				'permission_callback' => [ Content::class, 'upload_media_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/find-media',
			[
				'label'               => __( 'Find media', 'bricks' ),
				'description'         => __( 'Search the media library by filename, title, or alt text. Filter by MIME type. Supports compact results or full media objects with all size variants.', 'bricks' ),
				'category'            => 'bricks-media',
				'input_schema'        => Content::find_media_schema(),
				'output_schema'       => Content::find_media_output_schema(),
				'execute_callback'    => [ Content::class, 'find_media' ],
				'permission_callback' => [ Content::class, 'find_media_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-media',
			[
				'label'               => __( 'Delete media', 'bricks' ),
				'description'         => __( 'Delete a media library attachment by ID. Use after upload-media QA runs or when replacing unused assets. Destructive: returns a beforeDelete snapshot and respects WordPress attachment trash behavior.', 'bricks' ),
				'category'            => 'bricks-media',
				'input_schema'        => Content::delete_media_schema(),
				'output_schema'       => Content::delete_media_output_schema(),
				'execute_callback'    => [ Content::class, 'delete_media' ],
				'permission_callback' => [ Content::class, 'delete_media_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register meta abilities
	 *
	 * @since 2.4
	 */
	private function register_meta_abilities() {
		$this->register(
			'bricks/get-mcp-version',
			[
				'label'               => __( 'Get Bricks MCP version', 'bricks' ),
				'description'         => __( 'Report the Bricks plugin version, the Bricks abilities contract version, the WordPress MCP Adapter version, and the WordPress core version. Call once at the start of a session to detect version drift mid-conversation. `disabledAbilityCount` includes both admin-disabled and default-off abilities; `adminDisabledAbilityCount` and `defaultDisabledAbilityCount` split those states.', 'bricks' ),
				'category'            => 'bricks-meta',
				'input_schema'        => Meta::get_mcp_version_schema(),
				'output_schema'       => Meta::get_mcp_version_output_schema(),
				'execute_callback'    => [ Meta::class, 'get_mcp_version' ],
				'permission_callback' => [ Meta::class, 'get_mcp_version_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-ability-status',
			[
				'label'               => __( 'List Bricks ability status', 'bricks' ),
				'description'         => __( 'Inspect whether expected Bricks abilities are enabled. Pass exact `abilityNames` for targeted diagnostics. The default summary lists enabled abilities only; pass `includeDisabled: true` for the complete compact registry, or use `detailed` for full metadata. Exact-name lookups still return matching disabled abilities.', 'bricks' ),
				'category'            => 'bricks-meta',
				'input_schema'        => Meta::list_ability_status_schema(),
				'output_schema'       => Meta::list_ability_status_output_schema(),
				'execute_callback'    => [ Meta::class, 'list_ability_status' ],
				'permission_callback' => [ Meta::class, 'get_mcp_version_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/start-here',
			[
				'label'               => __( 'Bricks orientation (start here)', 'bricks' ),
				'description'         => __( 'Compact task router for clients without loaded Bricks skills. A clearly named existing page or design resource routes directly to resolve-agent-file then commit-agent-file without version, context, catalog, or manifest calls. Pass responseFormat=detailed only when the complete workflow guide is needed. Clients that already loaded bricks-start-here do not need this tool.', 'bricks' ),
				'category'            => 'bricks-meta',
				'input_schema'        => Meta::start_here_schema(),
				'output_schema'       => Meta::start_here_output_schema(),
				'execute_callback'    => [ Meta::class, 'start_here' ],
				'permission_callback' => [ Meta::class, 'start_here_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'   => true,
						'idempotent' => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register CMS structure abilities
	 *
	 * @since 2.4
	 */
	private function register_cms_abilities() {
		$this->register(
			'bricks/list-cms-sources',
			[
				'label'               => __( 'List CMS sources', 'bricks' ),
				'description'         => __( 'Introspect the site\'s content model. Pass a `source` (one of `wp`, `acf`, `jetengine`, `metabox`, `cmb2`, `pods`, `toolset`, `woo`) to fetch its schema, or omit to receive only the availability directory. Read-only: Bricks does not expose CRUD for third-party field providers. Use this to understand what data the site already carries so you can reference it correctly in templates and dynamic-data tags.', 'bricks' ),
				'category'            => 'bricks-cms',
				'input_schema'        => Cms::list_cms_sources_schema(),
				'output_schema'       => Cms::list_cms_sources_output_schema(),
				'execute_callback'    => [ Cms::class, 'list_cms_sources' ],
				'permission_callback' => [ Cms::class, 'list_cms_sources_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-reading-settings',
			[
				'label'               => __( 'Get reading settings', 'bricks' ),
				'description'         => __( 'Read WordPress Reading settings: whether the front page shows latest posts or a static page, the selected homepage, and the selected posts page.', 'bricks' ),
				'category'            => 'bricks-cms',
				'input_schema'        => Cms::get_reading_settings_schema(),
				'output_schema'       => Cms::reading_settings_output_schema(),
				'execute_callback'    => [ Cms::class, 'get_reading_settings' ],
				'permission_callback' => [ Cms::class, 'reading_settings_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-reading-settings',
			[
				'label'               => __( 'Set reading settings', 'bricks' ),
				'description'         => __( 'Set WordPress Reading settings for site builds. Covers `show_on_front`, `page_on_front`, and `page_for_posts` only. Requires manage_options.', 'bricks' ),
				'category'            => 'bricks-cms',
				'input_schema'        => Cms::set_reading_settings_schema(),
				'output_schema'       => Cms::reading_settings_output_schema(),
				'execute_callback'    => [ Cms::class, 'set_reading_settings' ],
				'permission_callback' => [ Cms::class, 'reading_settings_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register WordPress navigation menu abilities
	 *
	 * @since 2.4
	 */
	private function register_navigation_abilities() {
		$this->register(
			'bricks/list-nav-menus',
			[
				'label'               => __( 'List nav menus', 'bricks' ),
				'description'         => __( 'List WordPress nav menus with item counts, assigned theme locations, and whether any menu item uses Bricks mega-menu or multilevel metadata. Read-only. Use before deciding whether to reuse an existing WordPress menu or build a new Nav Nested menu directly in a Bricks header.', 'bricks' ),
				'category'            => 'bricks-navigation',
				'input_schema'        => Navigation::list_nav_menus_schema(),
				'output_schema'       => Navigation::list_nav_menus_output_schema(),
				'execute_callback'    => [ Navigation::class, 'list_nav_menus' ],
				'permission_callback' => [ Navigation::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-nav-menu',
			[
				'label'               => __( 'Get nav menu', 'bricks' ),
				'description'         => __( 'Fetch one WordPress nav menu as an ordered nested item tree. Includes menu item links, object references, classes, target/rel fields, assigned locations, and Bricks item options (`megaMenuTemplateId`, `multilevel`). Read-only.', 'bricks' ),
				'category'            => 'bricks-navigation',
				'input_schema'        => Navigation::get_nav_menu_schema(),
				'output_schema'       => Navigation::get_nav_menu_output_schema(),
				'execute_callback'    => [ Navigation::class, 'get_nav_menu' ],
				'permission_callback' => [ Navigation::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/save-nav-menu',
			[
				'label'               => __( 'Save nav menu', 'bricks' ),
				'description'         => __( 'Create or update a WordPress nav menu, assign registered theme locations, and create/update/reorder menu items from a nested tree. Omitted existing items are preserved, not deleted; use `bricks/delete-nav-menu-items` for explicit item deletion. Supports Bricks item options for top-level items: `megaMenuTemplateId` attaches a published Bricks template for the Nav Menu element mega-menu path, and `multilevel` enables Bricks multilevel behavior.', 'bricks' ),
				'category'            => 'bricks-navigation',
				'input_schema'        => Navigation::save_nav_menu_schema(),
				'output_schema'       => Navigation::save_nav_menu_output_schema(),
				'execute_callback'    => [ Navigation::class, 'save_nav_menu' ],
				'permission_callback' => [ Navigation::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-nav-menu-items',
			[
				'label'               => __( 'Delete nav menu items', 'bricks' ),
				'description'         => __( 'Delete explicit WordPress nav menu item IDs from a selected menu. Destructive and not Bricks revision-backed; returns beforeDelete snapshots for every removed item. Never use this for reordering or partial updates.', 'bricks' ),
				'category'            => 'bricks-navigation',
				'input_schema'        => Navigation::delete_nav_menu_items_schema(),
				'output_schema'       => Navigation::delete_nav_menu_items_output_schema(),
				'execute_callback'    => [ Navigation::class, 'delete_nav_menu_items' ],
				'permission_callback' => [ Navigation::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-nav-menu',
			[
				'label'               => __( 'Delete nav menu', 'bricks' ),
				'description'         => __( 'Delete a WordPress nav menu by ID, slug, or exact name. Destructive and not Bricks revision-backed; returns a full beforeDelete snapshot including menu items and Bricks mega-menu metadata.', 'bricks' ),
				'category'            => 'bricks-navigation',
				'input_schema'        => Navigation::delete_nav_menu_schema(),
				'output_schema'       => Navigation::delete_nav_menu_output_schema(),
				'execute_callback'    => [ Navigation::class, 'delete_nav_menu' ],
				'permission_callback' => [ Navigation::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Forms
	// ==================================================================

	/**
	 * Register form abilities
	 *
	 * @since 2.4
	 */
	private function register_form_abilities() {
		$this->register(
			'bricks/get-form-config',
			[
				'label'               => __( 'Get form config', 'bricks' ),
				'description'         => __( 'Read a Form element\'s fields, actions, and settings for a given post + elementId. Read-only. Use before update-form-fields / update-form-actions to see current shape.', 'bricks' ),
				'category'            => 'bricks-forms',
				'input_schema'        => Forms::get_form_config_schema(),
				'output_schema'       => Forms::get_form_config_output_schema(),
				'execute_callback'    => [ Forms::class, 'get_form_config' ],
				'permission_callback' => [ Forms::class, 'read_form_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-form-fields',
			[
				'label'               => __( 'Update form fields', 'bricks' ),
				'description'         => __( 'Replace the entire `fields` array on a Form element. Order is significant, so this is always a full replacement, never a merge. Each field needs at least `{ id, type }`, and `type` must be one of the 18 supported field types (see `skills/forms`). A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-forms',
				'input_schema'        => Forms::update_form_fields_schema(),
				'output_schema'       => Forms::update_form_fields_output_schema(),
				'execute_callback'    => [ Forms::class, 'update_form_fields' ],
				'permission_callback' => [ Forms::class, 'edit_form_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-form-actions',
			[
				'label'               => __( 'Update form actions', 'bricks' ),
				'description'         => __( 'Update the actions list and/or partial-merge per-action settings (emailTo, webhookUrl, redirectUrl, etc.). Built-in actions are validated against the same runtime list the Form element uses, so `save-submission` and `unlock-password-protection` require their global settings. Custom action keys are accepted only when a matching `bricks/form/action/{key}` hook is registered. Provide at least one of `actions` or `settings`. A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-forms',
				'input_schema'        => Forms::update_form_actions_schema(),
				'output_schema'       => Forms::update_form_actions_output_schema(),
				'execute_callback'    => [ Forms::class, 'update_form_actions' ],
				'permission_callback' => [ Forms::class, 'edit_form_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-form-submissions',
			[
				'label'               => __( 'List form submissions', 'bricks' ),
				'description'         => __( 'Read stored submissions for a Form element from the `{prefix}bricks_form_submissions` table. Paginated. When the table is missing (the Save Submission action has never run), returns an empty list with an explanatory `note` rather than a hard error.', 'bricks' ),
				'category'            => 'bricks-forms',
				'input_schema'        => Forms::list_form_submissions_schema(),
				'output_schema'       => Forms::list_form_submissions_output_schema(),
				'execute_callback'    => [ Forms::class, 'list_form_submissions' ],
				'permission_callback' => [ Forms::class, 'list_submissions_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Query Filters
	// ==================================================================

	/**
	 * Register query-filter abilities
	 *
	 * @since 2.4
	 */
	private function register_filter_abilities() {
		$this->register(
			'bricks/list-query-filters',
			[
				'label'               => __( 'List query filters', 'bricks' ),
				'description'         => __( 'Enumerate Query Filter elements across posts/templates (filter-checkbox, filter-radio, filter-select, filter-range, filter-search, filter-datepicker, filter-submit, filter-active-filters). Returns post/location + filter target (filterQueryId). Read-only.', 'bricks' ),
				'category'            => 'bricks-filters',
				'input_schema'        => Filters::list_query_filters_schema(),
				'output_schema'       => Filters::list_query_filters_output_schema(),
				'execute_callback'    => [ Filters::class, 'list_query_filters' ],
				'permission_callback' => [ Filters::class, 'list_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-filter-element',
			[
				'label'               => __( 'Get filter element', 'bricks' ),
				'description'         => __( 'Read a single Query Filter element\'s full settings (source, field, label, style, filterQueryId, etc.). Read-only.', 'bricks' ),
				'category'            => 'bricks-filters',
				'input_schema'        => Filters::get_filter_element_schema(),
				'output_schema'       => Filters::get_filter_element_output_schema(),
				'execute_callback'    => [ Filters::class, 'get_filter_element' ],
				'permission_callback' => [ Filters::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-filter-element',
			[
				'label'               => __( 'Update filter element', 'bricks' ),
				'description'         => __( 'Partial-merge update of a filter element\'s settings. Use to change source (taxonomy/customField/wpField), field mapping, labels, or UI options without touching filterQueryId. For retargeting to a different loop, use set-filter-target-query. A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-filters',
				'input_schema'        => Filters::update_filter_element_schema(),
				'output_schema'       => Filters::update_filter_element_output_schema(),
				'execute_callback'    => [ Filters::class, 'update_filter_element' ],
				'permission_callback' => [ Filters::class, 'edit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-filter-target-query',
			[
				'label'               => __( 'Set filter target query', 'bricks' ),
				'description'         => __( 'Bind a filter element to a target query-loop element via `filterQueryId`. Filter elements that lack this binding never fire. Pass an empty string to unbind. A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-filters',
				'input_schema'        => Filters::set_filter_target_query_schema(),
				'output_schema'       => Filters::set_filter_target_query_output_schema(),
				'execute_callback'    => [ Filters::class, 'set_filter_target_query' ],
				'permission_callback' => [ Filters::class, 'edit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/reindex-filters',
			[
				'label'               => __( 'Reindex filters', 'bricks' ),
				'description'         => __( 'Drop/recreate the query-filter index table and queue index jobs. Use after bulk filter changes, schema changes, or to recover from a corrupted index. The background job may continue after this call returns. Equivalent to clicking Regenerate filter index in Bricks > Settings > Query filters.', 'bricks' ),
				'category'            => 'bricks-filters',
				'input_schema'        => Filters::reindex_filters_schema(),
				'output_schema'       => Filters::reindex_filters_output_schema(),
				'execute_callback'    => [ Filters::class, 'reindex_filters' ],
				'permission_callback' => [ Filters::class, 'reindex_filters_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Popups
	// ==================================================================

	/**
	 * Register popup abilities
	 *
	 * @since 2.4
	 */
	private function register_popup_abilities() {
		$this->register(
			'bricks/list-popups',
			[
				'label'               => __( 'List popups', 'bricks' ),
				'description'         => __( 'List all popup templates (`bricks_template` posts whose template type is `popup`). Returns id, title, status, and conditions summary. Read-only. Equivalent to list-templates with type=popup plus extra popup-specific meta.', 'bricks' ),
				'category'            => 'bricks-popups',
				'input_schema'        => Popups::list_popups_schema(),
				'output_schema'       => Popups::list_popups_output_schema(),
				'execute_callback'    => [ Popups::class, 'list_popups' ],
				'permission_callback' => [ Popups::class, 'list_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-popup-config',
			[
				'label'               => __( 'Get popup config', 'bricks' ),
				'description'         => __( 'Read a popup template\'s configuration: the template conditions (where it can appear), the popup-specific settings (`popupCloseOn`, `popupBodyScroll`, `popupAjax`, `popupLimit*`), and the interaction triggers that reference it (elements whose target is `popup` with `templateId` pointing at this popup). Read-only.', 'bricks' ),
				'category'            => 'bricks-popups',
				'input_schema'        => Popups::get_popup_config_schema(),
				'output_schema'       => Popups::get_popup_config_output_schema(),
				'execute_callback'    => [ Popups::class, 'get_popup_config' ],
				'permission_callback' => [ Popups::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-popup-settings',
			[
				'label'               => __( 'Update popup settings', 'bricks' ),
				'description'         => __( 'Partial-merge update of popup-specific template settings: popupCloseOn (esc/backdrop), popupBodyScroll, popupAjax, popupLimitWindow, popupLimitSessionStorage, popupLimitLocalStorage, and popupLimitTimeStorage. Does NOT manage template display conditions (use set-template-conditions) or interaction triggers on opener elements (use update-element-interactions on the caller).', 'bricks' ),
				'category'            => 'bricks-popups',
				'input_schema'        => Popups::update_popup_settings_schema(),
				'output_schema'       => Popups::update_popup_settings_output_schema(),
				'execute_callback'    => [ Popups::class, 'update_popup_settings' ],
				'permission_callback' => [ Popups::class, 'edit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Interactions
	// ==================================================================

	/**
	 * Register interaction abilities
	 *
	 * @since 2.4
	 */
	private function register_interaction_abilities() {
		$this->register(
			'bricks/get-element-interactions',
			[
				'label'               => __( 'Get element interactions', 'bricks' ),
				'description'         => __( 'Read an element\'s own `_interactions` rows plus inherited global-class interactions in frontend order. Each interaction has a trigger (such as `click`, `enterView`, `scroll`, `formSubmit`) and an action (such as `show`, `hide`, `setAttribute`, `startAnimation`, `toggleOffCanvas`, `javascript`, `storageAdd`). `target` is optional and defaults to `self` at runtime; valid explicit values are `self`, `custom`, and `popup`. Read-only.', 'bricks' ),
				'category'            => 'bricks-interactions',
				'input_schema'        => Interactions::get_element_interactions_schema(),
				'output_schema'       => Interactions::get_element_interactions_output_schema(),
				'execute_callback'    => [ Interactions::class, 'get_element_interactions' ],
				'permission_callback' => [ Interactions::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-element-interactions',
			[
				'label'               => __( 'Update element interactions', 'bricks' ),
				'description'         => __( 'Replace the element-level `_interactions` array. Order is significant, so this is always a full replacement, never a merge. Missing row IDs are generated. Trigger, action, target, JavaScript function references, and action/trigger-specific required fields are validated. Global-class interactions are read-only here; update the class to change inherited rows. A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-interactions',
				'input_schema'        => Interactions::update_element_interactions_schema(),
				'output_schema'       => Interactions::update_element_interactions_output_schema(),
				'execute_callback'    => [ Interactions::class, 'update_element_interactions' ],
				'permission_callback' => [ Interactions::class, 'edit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Element Conditions
	// ==================================================================

	/**
	 * Register element condition abilities
	 *
	 * @since 2.4
	 */
	private function register_element_condition_abilities() {
		$this->register(
			'bricks/get-element-conditions',
			[
				'label'               => __( 'Get element conditions', 'bricks' ),
				'description'         => __( 'Read the `_conditions` array stored on an element\'s settings. Conditions are OR groups containing AND items: the element renders when any group matches and every item inside that group matches. Read-only.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Element_Conditions::get_element_conditions_schema(),
				'output_schema'       => Element_Conditions::get_element_conditions_output_schema(),
				'execute_callback'    => [ Element_Conditions::class, 'get_element_conditions' ],
				'permission_callback' => [ Element_Conditions::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-element-conditions',
			[
				'label'               => __( 'Update element conditions', 'bricks' ),
				'description'         => __( 'Replace the `_conditions` array on an element. Conditions are full-replacement ordered repeaters, never a merge. Missing row IDs are generated. Pass an empty array to clear all conditions. A pre-save revision is taken.', 'bricks' ),
				'category'            => 'bricks-elements',
				'input_schema'        => Element_Conditions::update_element_conditions_schema(),
				'output_schema'       => Element_Conditions::update_element_conditions_output_schema(),
				'execute_callback'    => [ Element_Conditions::class, 'update_element_conditions' ],
				'permission_callback' => [ Element_Conditions::class, 'edit_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Global Settings
	// ==================================================================

	/**
	 * Register settings abilities
	 *
	 * @since 2.4
	 */
	private function register_settings_abilities() {
		$this->register(
			'bricks/list-settings-schema',
			[
				'label'               => __( 'List Bricks settings schema', 'bricks' ),
				'description'         => __( 'Discover which Bricks global-settings keys are writable via MCP. Returns an allow-list registry: each entry declares `key`, `type`, `category` (general/performance/maintenance/builder/templates/forms), `label`, and validation hints. Call this before `bricks/set-global-settings` to know what you can touch. Credentials, code-execution toggles, and builder role data are excluded from this surface; use list-credential-status to check whether credentials are configured.', 'bricks' ),
				'category'            => 'bricks-settings',
				'input_schema'        => Settings::list_settings_schema_schema(),
				'output_schema'       => Settings::list_settings_schema_output_schema(),
				'execute_callback'    => [ Settings::class, 'list_settings_schema' ],
				'permission_callback' => [ Settings::class, 'schema_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-credential-status',
			[
				'label'               => __( 'List Bricks credential status', 'bricks' ),
				'description'         => __( 'Read whether Bricks credentials are configured without returning secret values. Covers the license key, API/site/secret keys, access tokens, and template passwords. Every row returns configured/readable/writable flags; readable and writable are false because credential values stay admin-UI-only for now.', 'bricks' ),
				'category'            => 'bricks-settings',
				'input_schema'        => Settings::list_credential_status_schema(),
				'output_schema'       => Settings::list_credential_status_output_schema(),
				'execute_callback'    => [ Settings::class, 'list_credential_status' ],
				'permission_callback' => [ Settings::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-global-settings',
			[
				'label'               => __( 'Get Bricks global settings', 'bricks' ),
				'description'         => __( 'Read current values for every allow-listed Bricks global setting. Excluded keys (credentials and code-execution settings) are never returned even if set. Remote template source passwords are redacted to passwordConfigured booleans. Pair with `bricks/list-settings-schema` to know what each key means.', 'bricks' ),
				'category'            => 'bricks-settings',
				'input_schema'        => Settings::get_global_settings_schema(),
				'output_schema'       => Settings::get_global_settings_output_schema(),
				'execute_callback'    => [ Settings::class, 'get_global_settings' ],
				'permission_callback' => [ Settings::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-global-settings',
			[
				'label'               => __( 'Set Bricks global settings', 'bricks' ),
				'description'         => __( 'Partial-merge write to Bricks global settings. Pass `{ settings: { key: value, ... } }`; only the keys you send are touched, and everything else is preserved. Keys outside the registry return `bricks_setting_unknown`, and excluded keys return `bricks_setting_excluded`. Every value is validated against the registry schema before write.', 'bricks' ),
				'category'            => 'bricks-settings',
				'input_schema'        => Settings::set_global_settings_schema(),
				'output_schema'       => Settings::set_global_settings_output_schema(),
				'execute_callback'    => [ Settings::class, 'set_global_settings' ],
				'permission_callback' => [ Settings::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register WooCommerce abilities.
	 *
	 * @since 2.4
	 */
	private function register_woocommerce_abilities() {
		$this->register(
			'bricks/get-woo-setup-status',
			[
				'label'               => __( 'Get WooCommerce setup status', 'bricks' ),
				'description'         => __( 'Inspect Bricks/WooCommerce setup readiness for shop, single product, cart, checkout, and account areas. Returns assigned pages, existing Bricks data/content risks, available classic/v2 presets, Bricks Woo settings, and WooCommerce core page/account/checkout settings. Read this before planning or running Woo setup.', 'bricks' ),
				'category'            => 'bricks-woocommerce',
				'input_schema'        => WooCommerce::get_woo_setup_status_schema(),
				'output_schema'       => WooCommerce::get_woo_setup_status_output_schema(),
				'execute_callback'    => [ WooCommerce::class, 'get_woo_setup_status' ],
				'permission_callback' => [ WooCommerce::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/plan-woo-setup',
			[
				'label'               => __( 'Plan WooCommerce setup', 'bricks' ),
				'description'         => __( 'Dry-run Bricks WooCommerce setup. Produces a planId plus page creation/reuse/assignment operations, selected classic or advanced presets, template-drafting side effects, blockers, warnings, and confirmation requirements. Defaults to classic mode because modular v2 Woo elements are experimental.', 'bricks' ),
				'category'            => 'bricks-woocommerce',
				'input_schema'        => WooCommerce::plan_woo_setup_schema(),
				'output_schema'       => WooCommerce::plan_woo_setup_output_schema(),
				'execute_callback'    => [ WooCommerce::class, 'plan_woo_setup' ],
				'permission_callback' => [ WooCommerce::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/run-woo-setup',
			[
				'label'               => __( 'Run WooCommerce setup', 'bricks' ),
				'description'         => __( 'Apply a reviewed Woo setup plan: create only missing Woo pages, reuse matching pages only when empty or shortcode-only, assign Woo page options, optionally enable experimental advanced modular elements, and run the shared Woo setup wizard for selected areas. Existing Bricks/non-empty/block page content requires explicit overwriteExistingPageContent and destructive confirmation.', 'bricks' ),
				'category'            => 'bricks-woocommerce',
				'input_schema'        => WooCommerce::run_woo_setup_schema(),
				'output_schema'       => WooCommerce::run_woo_setup_output_schema(),
				'execute_callback'    => [ WooCommerce::class, 'run_woo_setup' ],
				'permission_callback' => [ WooCommerce::class, 'setup_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-woo-setup-options',
			[
				'label'               => __( 'Get Woo setup options', 'bricks' ),
				'description'         => __( 'Read the safe WooCommerce core options that Bricks setup needs: assigned shop/cart/checkout/account pages, coupon/login/registration checkout settings, and native archive AJAX add-to-cart behavior. Bricks-owned Woo toggles remain in bricks/get-global-settings. Credentials and payment/shipping gateway settings are not exposed.', 'bricks' ),
				'category'            => 'bricks-woocommerce',
				'input_schema'        => WooCommerce::get_woocommerce_settings_schema(),
				'output_schema'       => WooCommerce::get_woocommerce_settings_output_schema(),
				'execute_callback'    => [ WooCommerce::class, 'get_woocommerce_settings' ],
				'permission_callback' => [ WooCommerce::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-woo-setup-options',
			[
				'label'               => __( 'Set Woo setup options', 'bricks' ),
				'description'         => __( 'Partial-merge write to the small allow-list of WooCommerce core options Bricks setup needs. Page IDs must reference existing non-trashed pages or 0 to clear, boolean values are stored as WooCommerce yes/no options, and unrelated Woo settings are preserved. Bricks-owned Woo toggles remain in bricks/set-global-settings.', 'bricks' ),
				'category'            => 'bricks-woocommerce',
				'input_schema'        => WooCommerce::set_woocommerce_settings_schema(),
				'output_schema'       => WooCommerce::set_woocommerce_settings_output_schema(),
				'execute_callback'    => [ WooCommerce::class, 'set_woocommerce_settings' ],
				'permission_callback' => [ WooCommerce::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register builder permission abilities.
	 *
	 * @since 2.4
	 */
	private function register_permission_abilities() {
		$this->register(
			'bricks/list-builder-permissions',
			[
				'label'               => __( 'List Bricks builder permissions', 'bricks' ),
				'description'         => __( 'Read the real builder-access model: permission sections, default and custom capability definitions, and the current WordPress role assignments. Custom definitions live in `BRICKS_DB_CAPABILITIES_PERMISSIONS`; role access lives on WordPress roles, not in `bricks_global_settings`.', 'bricks' ),
				'category'            => 'bricks-permissions',
				'input_schema'        => Permissions::list_builder_permissions_schema(),
				'output_schema'       => Permissions::list_builder_permissions_output_schema(),
				'execute_callback'    => [ Permissions::class, 'list_builder_permissions' ],
				'permission_callback' => [ Permissions::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/upsert-builder-capability',
			[
				'label'               => __( 'Upsert Bricks builder capability', 'bricks' ),
				'description'         => __( 'Create or update one custom builder access capability. The capability grants a validated list of builder permission keys and then appears in the Builder access role selector. Built-in Bricks capability ids cannot be overwritten.', 'bricks' ),
				'category'            => 'bricks-permissions',
				'input_schema'        => Permissions::upsert_builder_capability_schema(),
				'output_schema'       => Permissions::upsert_builder_capability_output_schema(),
				'execute_callback'    => [ Permissions::class, 'upsert_builder_capability' ],
				'permission_callback' => [ Permissions::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-builder-role-access',
			[
				'label'               => __( 'Set Bricks builder role access', 'bricks' ),
				'description'         => __( 'Assign existing builder access capabilities to WordPress roles. Empty string means no builder access. The administrator role is intentionally not writable here because Bricks always treats administrators as full access.', 'bricks' ),
				'category'            => 'bricks-permissions',
				'input_schema'        => Permissions::set_builder_role_access_schema(),
				'output_schema'       => Permissions::set_builder_role_access_output_schema(),
				'execute_callback'    => [ Permissions::class, 'set_builder_role_access' ],
				'permission_callback' => [ Permissions::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-builder-capability',
			[
				'label'               => __( 'Delete Bricks builder capability', 'bricks' ),
				'description'         => __( 'Delete one custom builder capability definition and remove that WordPress capability from all roles. Built-in Bricks capabilities cannot be deleted.', 'bricks' ),
				'category'            => 'bricks-permissions',
				'input_schema'        => Permissions::delete_builder_capability_schema(),
				'output_schema'       => Permissions::delete_builder_capability_output_schema(),
				'execute_callback'    => [ Permissions::class, 'delete_builder_capability' ],
				'permission_callback' => [ Permissions::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Breakpoints
	// ==================================================================

	/**
	 * Register breakpoint abilities
	 *
	 * @since 2.4
	 */
	private function register_breakpoint_abilities() {
		$this->register(
			'bricks/list-breakpoints',
			[
				'label'               => __( 'List Bricks breakpoints', 'bricks' ),
				'description'         => __( 'Read the site\'s responsive breakpoints. Returns `{ baseKey, baseWidth, customEnabled, isMobileFirst, breakpoints, defaults }`, with `breakpoints` sorted by width for the active paradigm. `customEnabled` reflects the `customBreakpoints` master toggle.', 'bricks' ),
				'category'            => 'bricks-breakpoints',
				'input_schema'        => Breakpoints::list_breakpoints_schema(),
				'output_schema'       => Breakpoints::list_breakpoints_output_schema(),
				'execute_callback'    => [ Breakpoints::class, 'list_breakpoints' ],
				'permission_callback' => [ Breakpoints::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-breakpoints',
			[
				'label'               => __( 'Set Bricks breakpoints', 'bricks' ),
				'description'         => __( 'Full-replacement write of the breakpoint list with exact ownership. Removing or renaming any current key requires literal allowRemovedBreakpoints=true and returns removed keys plus bounded usage evidence; width, label, icon, ordering, and additive edits do not require acknowledgement. Exactly one row must have base: true. When cssLoading=file, follow up with bricks/regenerate-css-files.', 'bricks' ),
				'category'            => 'bricks-breakpoints',
				'input_schema'        => Breakpoints::set_breakpoints_schema(),
				'output_schema'       => Breakpoints::set_breakpoints_output_schema(),
				'execute_callback'    => [ Breakpoints::class, 'set_breakpoints' ],
				'permission_callback' => [ Breakpoints::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'idempotent'  => true,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Global Queries
	// ==================================================================

	/**
	 * Register global-query abilities
	 *
	 * @since 2.4
	 */
	private function register_query_abilities() {
		$this->register(
			'bricks/list-global-queries',
			[
				'label'               => __( 'List global queries', 'bricks' ),
				'description'         => __( 'Enumerate the reusable query-loop configurations saved in Bricks. Supports search, category filter, and pagination. Returns a summary row per query (`id`, `label`, `category`, and a `summary` object with fields such as `objectType`, `postType`, and `posts_per_page`) plus the category list. Call `bricks/get-global-query` to fetch the full stored row.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::list_global_queries_schema(),
				'output_schema'       => Queries::list_global_queries_output_schema(),
				'execute_callback'    => [ Queries::class, 'list_global_queries' ],
				'permission_callback' => [ Queries::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-global-query',
			[
				'label'               => __( 'Get global query', 'bricks' ),
				'description'         => __( 'Read a single global query by ID. Returns the full stored row, including `id`, `name`, optional `category`, and `settings` using the same shape as an element\'s inline `query` setting.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::get_global_query_schema(),
				'output_schema'       => Queries::get_global_query_output_schema(),
				'execute_callback'    => [ Queries::class, 'get_global_query' ],
				'permission_callback' => [ Queries::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-global-query',
			[
				'label'               => __( 'Create global query', 'bricks' ),
				'description'         => __( 'Save a new reusable query. Use a unique label and a `query` object that mirrors an element\'s inline `query` setting (for example `{ objectType: "post", postType: ["post"], posts_per_page: 10 }`). Any optional `category` must already exist; create it first via `bricks/create-global-query-category`.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::create_global_query_schema(),
				'output_schema'       => Queries::create_global_query_output_schema(),
				'execute_callback'    => [ Queries::class, 'create_global_query' ],
				'permission_callback' => [ Queries::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-global-query',
			[
				'label'               => __( 'Update global query', 'bricks' ),
				'description'         => __( 'Partial-merge update of a global query\'s label, category, or inline query object. When the `query` field is sent it is replaced in full (no deep merge inside), so always pass the complete query-settings object.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::update_global_query_schema(),
				'output_schema'       => Queries::update_global_query_output_schema(),
				'execute_callback'    => [ Queries::class, 'update_global_query' ],
				'permission_callback' => [ Queries::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-global-query',
			[
				'label'               => __( 'Delete global query', 'bricks' ),
				'description'         => __( 'Delete a global query by ID. Elements referencing the deleted query will fall back to their inline query settings (if any) or show no loop.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::delete_global_query_schema(),
				'output_schema'       => Queries::delete_global_query_output_schema(),
				'execute_callback'    => [ Queries::class, 'delete_global_query' ],
				'permission_callback' => [ Queries::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-global-query-category',
			[
				'label'               => __( 'Create global query category', 'bricks' ),
				'description'         => __( 'Create a new category that global queries can be grouped under. Returns the generated `{ id, name }` category row.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::create_category_schema(),
				'output_schema'       => Queries::create_category_output_schema(),
				'execute_callback'    => [ Queries::class, 'create_category' ],
				'permission_callback' => [ Queries::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-global-query-category',
			[
				'label'               => __( 'Delete global query category', 'bricks' ),
				'description'         => __( 'Delete a global-query category. Queries inside the category are kept; their `category` field is cleared.', 'bricks' ),
				'category'            => 'bricks-queries',
				'input_schema'        => Queries::delete_category_schema(),
				'output_schema'       => Queries::delete_category_output_schema(),
				'execute_callback'    => [ Queries::class, 'delete_category' ],
				'permission_callback' => [ Queries::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	// ==================================================================
	// Sidebars
	// ==================================================================

	/**
	 * Register sidebar abilities
	 *
	 * @since 2.4
	 */
	private function register_sidebar_abilities() {
		$this->register(
			'bricks/list-sidebars',
			[
				'label'               => __( 'List Bricks sidebars', 'bricks' ),
				'description'         => __( 'Enumerate custom sidebars registered via Bricks > Settings > Sidebars. Read-only. Returns `[{ id, name, description }]`.', 'bricks' ),
				'category'            => 'bricks-sidebars',
				'input_schema'        => Sidebars::list_sidebars_schema(),
				'output_schema'       => Sidebars::list_sidebars_output_schema(),
				'execute_callback'    => [ Sidebars::class, 'list_sidebars' ],
				'permission_callback' => [ Sidebars::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-sidebar',
			[
				'label'               => __( 'Create Bricks sidebar', 'bricks' ),
				'description'         => __( 'Register a new custom sidebar. The ID is derived from `name` (lowercased, spaces to underscores, stripped of other characters). Names must be unique. Once created, the sidebar shows up in the Bricks "Sidebar" element dropdown and accepts widgets via Appearance > Widgets.', 'bricks' ),
				'category'            => 'bricks-sidebars',
				'input_schema'        => Sidebars::create_sidebar_schema(),
				'output_schema'       => Sidebars::create_sidebar_output_schema(),
				'execute_callback'    => [ Sidebars::class, 'create_sidebar' ],
				'permission_callback' => [ Sidebars::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-sidebar',
			[
				'label'               => __( 'Update Bricks sidebar', 'bricks' ),
				'description'         => __( 'Partial-merge update of a sidebar\'s `name` and/or `description`. The `id` is immutable; to change it, delete and recreate the sidebar.', 'bricks' ),
				'category'            => 'bricks-sidebars',
				'input_schema'        => Sidebars::update_sidebar_schema(),
				'output_schema'       => Sidebars::update_sidebar_output_schema(),
				'execute_callback'    => [ Sidebars::class, 'update_sidebar' ],
				'permission_callback' => [ Sidebars::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-sidebar',
			[
				'label'               => __( 'Delete Bricks sidebar', 'bricks' ),
				'description'         => __( 'Delete a custom sidebar by ID. Widgets placed in the sidebar are removed from `sidebars_widgets` (same behavior as the admin UI). Destructive.', 'bricks' ),
				'category'            => 'bricks-sidebars',
				'input_schema'        => Sidebars::delete_sidebar_schema(),
				'output_schema'       => Sidebars::delete_sidebar_output_schema(),
				'execute_callback'    => [ Sidebars::class, 'delete_sidebar' ],
				'permission_callback' => [ Sidebars::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register custom-font abilities.
	 *
	 * Two-step create flow: `create-custom-font` makes the family post,
	 * `upload-custom-font-file` uploads a face file as a private attachment,
	 * then `update-custom-font` attaches the file IDs to weight/style faces.
	 *
	 * @since 2.4
	 */
	private function register_font_abilities() {
		$this->register(
			'bricks/list-custom-fonts',
			[
				'label'               => __( 'List Bricks custom fonts', 'bricks' ),
				'description'         => __( 'Enumerate custom font families registered via the `bricks_fonts` CPT. Returns paginated rows with `id`, `family`, and `faceCount`; call `bricks/get-custom-font` for the full `fontFaces` map.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::list_custom_fonts_schema(),
				'output_schema'       => Fonts::list_custom_fonts_output_schema(),
				'execute_callback'    => [ Fonts::class, 'list_custom_fonts' ],
				'permission_callback' => [ Fonts::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/get-custom-font',
			[
				'label'               => __( 'Get Bricks custom font', 'bricks' ),
				'description'         => __( 'Fetch a single custom font family by post ID. Returns `{ font: { id, family, fontFaces } }`.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::get_custom_font_schema(),
				'output_schema'       => Fonts::get_custom_font_output_schema(),
				'execute_callback'    => [ Fonts::class, 'get_custom_font' ],
				'permission_callback' => [ Fonts::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-custom-font',
			[
				'label'               => __( 'Create Bricks custom font family', 'bricks' ),
				'description'         => __( 'Create a `bricks_fonts` family from `family`. Returns `{ font: { id, family, fontFaces } }`. Attach face files via `bricks/upload-custom-font-file` and `bricks/update-custom-font`.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::create_custom_font_schema(),
				'output_schema'       => Fonts::create_custom_font_output_schema(),
				'execute_callback'    => [ Fonts::class, 'create_custom_font' ],
				'permission_callback' => [ Fonts::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/update-custom-font',
			[
				'label'               => __( 'Update Bricks custom font', 'bricks' ),
				'description'         => __( 'Update a custom font family. Accepts `family` for renaming and `fontFaces` for full replacement of the face map, keyed by weight/style strings such as `400` or `400italic`. Every file value must reference an attachment ID.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::update_custom_font_schema(),
				'output_schema'       => Fonts::update_custom_font_output_schema(),
				'execute_callback'    => [ Fonts::class, 'update_custom_font' ],
				'permission_callback' => [ Fonts::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-custom-font',
			[
				'label'               => __( 'Delete Bricks custom font', 'bricks' ),
				'description'         => __( 'Delete a custom font family post. Face attachments stay in the media library; remove them separately if they are no longer needed. Destructive: pages using the font will fall back to their next available choice.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::delete_custom_font_schema(),
				'output_schema'       => Fonts::delete_custom_font_output_schema(),
				'execute_callback'    => [ Fonts::class, 'delete_custom_font' ],
				'permission_callback' => [ Fonts::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/upload-custom-font-file',
			[
				'label'               => __( 'Upload Bricks custom font file', 'bricks' ),
				'description'         => __( 'Upload a font face file (woff2, woff, ttf, otf, or eot) as a private attachment. Enforces an extension allow-list, MIME sniffing via `wp_check_filetype_and_ext`, and an 8 MB size cap (filterable via `bricks/abilities/fonts/max_bytes`). Returns the new attachment ID; pass it to `bricks/update-custom-font` to bind it to a weight and style.', 'bricks' ),
				'category'            => 'bricks-fonts',
				'input_schema'        => Fonts::upload_font_file_schema(),
				'output_schema'       => Fonts::upload_font_file_output_schema(),
				'execute_callback'    => [ Fonts::class, 'upload_font_file' ],
				'permission_callback' => [ Fonts::class, 'upload_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register icon-set and custom-icon abilities.
	 *
	 * @since 2.4
	 */
	private function register_icon_abilities() {
		$this->register(
			'bricks/list-icon-sets',
			[
				'label'               => __( 'List Bricks icon sets', 'bricks' ),
				'description'         => __( 'Enumerate custom icon sets (`BRICKS_DB_ICON_SETS`) and the list of disabled built-in or custom icon-set IDs (`BRICKS_DB_DISABLED_ICON_SETS`).', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::list_icon_sets_schema(),
				'output_schema'       => Icons::list_icon_sets_output_schema(),
				'execute_callback'    => [ Icons::class, 'list_icon_sets' ],
				'permission_callback' => [ Icons::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-disabled-icon-sets',
			[
				'label'               => __( 'Set disabled Bricks icon sets', 'bricks' ),
				'description'         => __( 'Full-replacement write of the disabled-icon-set list. Use this to hide built-in libraries or custom icon sets from the builder picker. Pass an empty array to re-enable all.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::set_disabled_icon_sets_schema(),
				'output_schema'       => Icons::set_disabled_icon_sets_output_schema(),
				'execute_callback'    => [ Icons::class, 'set_disabled_icon_sets' ],
				'permission_callback' => [ Icons::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-custom-icons',
			[
				'label'               => __( 'List Bricks custom icons', 'bricks' ),
				'description'         => __( 'Enumerate uploaded custom SVG icon rows from `BRICKS_DB_CUSTOM_ICONS`, optionally filtered by `setId`. Rows use the builder icon-manager shape: `id`, `setId`, `name`, `url`, and `attachment_id`. Paginated.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::list_custom_icons_schema(),
				'output_schema'       => Icons::list_custom_icons_output_schema(),
				'execute_callback'    => [ Icons::class, 'list_custom_icons' ],
				'permission_callback' => [ Icons::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/upload-custom-icon',
			[
				'label'               => __( 'Upload Bricks custom icon', 'bricks' ),
				'description'         => __( 'Add an SVG icon to an existing custom icon set. The raw SVG is sanitized server-side, stored as a Media Library attachment, then saved in `BRICKS_DB_CUSTOM_ICONS` with `url` and `attachment_id` so the builder icon manager can use it.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::upload_custom_icon_schema(),
				'output_schema'       => Icons::upload_custom_icon_output_schema(),
				'execute_callback'    => [ Icons::class, 'upload_custom_icon' ],
				'permission_callback' => [ Icons::class, 'upload_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-custom-icon',
			[
				'label'               => __( 'Delete Bricks custom icon', 'bricks' ),
				'description'         => __( 'Remove a custom icon by ID from the `BRICKS_DB_CUSTOM_ICONS` option. Destructive.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::delete_custom_icon_schema(),
				'output_schema'       => Icons::delete_custom_icon_output_schema(),
				'execute_callback'    => [ Icons::class, 'delete_custom_icon' ],
				'permission_callback' => [ Icons::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/create-custom-icon-set',
			[
				'label'               => __( 'Create Bricks custom icon set', 'bricks' ),
				'description'         => __( 'Create a new custom icon set so `upload-custom-icon` has a `setId` target. Appends a row to `BRICKS_DB_ICON_SETS` with a generated `set_<hex>` ID.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::create_custom_icon_set_schema(),
				'output_schema'       => Icons::create_custom_icon_set_output_schema(),
				'execute_callback'    => [ Icons::class, 'create_custom_icon_set' ],
				'permission_callback' => [ Icons::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/delete-custom-icon-set',
			[
				'label'               => __( 'Delete Bricks custom icon set', 'bricks' ),
				'description'         => __( 'Remove a custom icon set from `BRICKS_DB_ICON_SETS`. Cascades to drop every custom icon row in `BRICKS_DB_CUSTOM_ICONS` assigned to that set. SVG attachments stay in the Media Library. Destructive.', 'bricks' ),
				'category'            => 'bricks-icons',
				'input_schema'        => Icons::delete_custom_icon_set_schema(),
				'output_schema'       => Icons::delete_custom_icon_set_output_schema(),
				'execute_callback'    => [ Icons::class, 'delete_custom_icon_set' ],
				'permission_callback' => [ Icons::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register pseudo-class abilities.
	 *
	 * @since 2.4
	 */
	private function register_pseudo_class_abilities() {
		$this->register(
			'bricks/list-pseudo-classes',
			[
				'label'               => __( 'List Bricks pseudo-classes', 'bricks' ),
				'description'         => __( 'Return the list of pseudo-class selectors available in the builder style panel. `isDefault` is true when the option is unset and Bricks is using the built-in fallback (`:hover`, `:active`, `:focus`).', 'bricks' ),
				'category'            => 'bricks-pseudo-classes',
				'input_schema'        => Pseudo_Classes::list_pseudo_classes_schema(),
				'output_schema'       => Pseudo_Classes::list_pseudo_classes_output_schema(),
				'execute_callback'    => [ Pseudo_Classes::class, 'list_pseudo_classes' ],
				'permission_callback' => [ Pseudo_Classes::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-pseudo-classes',
			[
				'label'               => __( 'Set Bricks pseudo-classes', 'bricks' ),
				'description'         => __( 'Full-replacement write of the pseudo-class selector list with exact ownership. Removing any current selector requires literal allowRemovedPseudoClasses=true and returns removed selectors plus bounded usage evidence; additive and reorder-only edits do not require acknowledgement. Empty/null resets to the built-in defaults.', 'bricks' ),
				'category'            => 'bricks-pseudo-classes',
				'input_schema'        => Pseudo_Classes::set_pseudo_classes_schema(),
				'output_schema'       => Pseudo_Classes::set_pseudo_classes_output_schema(),
				'execute_callback'    => [ Pseudo_Classes::class, 'set_pseudo_classes' ],
				'permission_callback' => [ Pseudo_Classes::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'idempotent'  => true,
						'destructive' => true,
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register style-manager abilities.
	 *
	 * @since 2.4
	 */
	private function register_style_manager_abilities() {
		$this->register(
			'bricks/get-style-manager',
			[
				'label'               => __( 'Get Bricks style manager', 'bricks' ),
				'description'         => __( 'Return the `BRICKS_DB_STYLE_MANAGER` option verbatim, or null if unset. Common keys: `htmlFontSize`, `minScreenWidth`, `maxScreenWidth`, `defaultMode` (light|dark|auto).', 'bricks' ),
				'category'            => 'bricks-style-manager',
				'input_schema'        => Style_Manager::get_style_manager_schema(),
				'output_schema'       => Style_Manager::get_style_manager_output_schema(),
				'execute_callback'    => [ Style_Manager::class, 'get_style_manager' ],
				'permission_callback' => [ Style_Manager::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/set-style-manager',
			[
				'label'               => __( 'Set Bricks style manager', 'bricks' ),
				'description'         => __( 'Full-replacement write of the style-manager option. Pass null or empty object to reset to defaults.', 'bricks' ),
				'category'            => 'bricks-style-manager',
				'input_schema'        => Style_Manager::set_style_manager_schema(),
				'output_schema'       => Style_Manager::set_style_manager_output_schema(),
				'execute_callback'    => [ Style_Manager::class, 'set_style_manager' ],
				'permission_callback' => [ Style_Manager::class, 'write_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register maintenance abilities (CSS regen, orphan cleanup).
	 *
	 * Parity with Bricks > Settings > General maintenance buttons.
	 * Explicitly excludes `regenerate-code-signatures` - security-sensitive.
	 *
	 * @since 2.4
	 */
	private function register_maintenance_abilities() {
		$this->register(
			'bricks/regenerate-css-files',
			[
				'label'               => __( 'Regenerate Bricks CSS files', 'bricks' ),
				'description'         => __( 'Mirror of Settings > Bricks > General > "Regenerate CSS files". Rebuilds the per-post CSS files served when `cssLoading` is `file`. When `cssLoading` is `inline`, the regenerated files act only as a fallback; the live output is still inline `<style>` tags.', 'bricks' ),
				'category'            => 'bricks-maintenance',
				'input_schema'        => Maintenance::regenerate_css_files_schema(),
				'output_schema'       => Maintenance::regenerate_css_files_output_schema(),
				'execute_callback'    => [ Maintenance::class, 'regenerate_css_files' ],
				'permission_callback' => [ Maintenance::class, 'admin_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'idempotent' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/list-orphaned-elements',
			[
				'label'               => __( 'List orphaned Bricks elements', 'bricks' ),
				'description'         => __( 'Scan every post that uses Bricks meta for elements whose parent no longer exists in the same tree. Read-only: returns what `bricks/cleanup-orphaned-elements` would remove without touching anything.', 'bricks' ),
				'category'            => 'bricks-maintenance',
				'input_schema'        => Maintenance::list_orphaned_elements_schema(),
				'output_schema'       => Maintenance::list_orphaned_elements_output_schema(),
				'execute_callback'    => [ Maintenance::class, 'list_orphaned_elements' ],
				'permission_callback' => [ Maintenance::class, 'admin_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/cleanup-orphaned-elements',
			[
				'label'               => __( 'Clean up orphaned Bricks elements', 'bricks' ),
				'description'         => __( 'Strip orphan rows from the Bricks postmeta of every affected post. Destructive. Mirrors the admin "Clean up orphaned elements" button.', 'bricks' ),
				'category'            => 'bricks-maintenance',
				'input_schema'        => Maintenance::cleanup_orphaned_elements_schema(),
				'output_schema'       => Maintenance::cleanup_orphaned_elements_output_schema(),
				'execute_callback'    => [ Maintenance::class, 'cleanup_orphaned_elements' ],
				'permission_callback' => [ Maintenance::class, 'admin_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'destructive' => true,
						'idempotent'  => true
					],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register the explicitly armed PHP execution ability.
	 *
	 * The ability is always registered so clients can discover why it is
	 * unavailable. Its permission and execution callbacks resolve the current
	 * configuration constant on every call. It is never controlled by the
	 * generic database-backed ability toggles.
	 *
	 * @since 2.4
	 */
	private function register_execute_php_abilities() {
		$this->register(
			Execute_Php::ABILITY_NAME,
			[
				'label'                 => __( 'Execute PHP', 'bricks' ),
				'description'           => __( 'Run arbitrary PHP for advanced development, maintenance, automation, and troubleshooting workflows. Requires explicit PHP configuration and Bricks code execution access for the connected user.', 'bricks' ),
				'category'              => 'bricks-system',
				'input_schema'          => Execute_Php::input_schema(),
				'output_schema'         => Execute_Php::output_schema(),
				'execute_callback'      => [ Execute_Php::class, 'execute' ],
				'permission_callback'   => [ Execute_Php::class, 'permission' ],
				'default_enabled'       => false,
				'managed_externally'    => true,
				'availability_callback' => [ Execute_Php::class, 'is_available' ],
				'meta'                  => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register import/export abilities.
	 *
	 * @since 2.4
	 */
	private function register_import_export_abilities() {
		$this->register(
			'bricks/list-transfer-items',
			[
				'label'               => __( 'List Bricks transfer items', 'bricks' ),
				'description'         => __( 'Read the unified import/export selector for the current site. Returns exportable transfer types and exact item IDs for color palettes, theme styles, classes, variables, custom fonts, breakpoints, global queries, components, templates, settings tabs, builder interface profiles, and custom capabilities, filtered by the current user permissions.', 'bricks' ),
				'category'            => 'bricks-import-export',
				'input_schema'        => Import_Export::list_transfer_items_schema(),
				'output_schema'       => Import_Export::list_transfer_items_output_schema(),
				'execute_callback'    => [ Import_Export::class, 'list_transfer_items' ],
				'permission_callback' => [ Import_Export::class, 'transfer_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/export-transfer-package',
			[
				'label'               => __( 'Export Bricks transfer package', 'bricks' ),
				'description'         => __( 'Create a unified Bricks import/export ZIP package and return it as base64 for MCP transport. Call `bricks/list-transfer-items` first and pass explicit item IDs for every selected type. Sensitive settings tabs (`api-keys`, `custom-code`) require `allowSensitiveSettings: true`.', 'bricks' ),
				'category'            => 'bricks-import-export',
				'input_schema'        => Import_Export::export_transfer_package_schema(),
				'output_schema'       => Import_Export::export_transfer_package_output_schema(),
				'execute_callback'    => [ Import_Export::class, 'export_transfer_package' ],
				'permission_callback' => [ Import_Export::class, 'transfer_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/inspect-transfer-package',
			[
				'label'               => __( 'Inspect Bricks transfer package', 'bricks' ),
				'description'         => __( 'Inspect a base64 unified transfer ZIP without writing. Returns the manifest filtered by current user permissions plus conflicts, warnings, `zipHash`, and size. Call this before `bricks/import-transfer-package` and pass the returned `zipHash` as `expectedZipHash`.', 'bricks' ),
				'category'            => 'bricks-import-export',
				'input_schema'        => Import_Export::inspect_transfer_package_schema(),
				'output_schema'       => Import_Export::inspect_transfer_package_output_schema(),
				'execute_callback'    => [ Import_Export::class, 'inspect_transfer_package' ],
				'permission_callback' => [ Import_Export::class, 'transfer_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);

		$this->register(
			'bricks/import-transfer-package',
			[
				'label'               => __( 'Import Bricks transfer package', 'bricks' ),
				'description'         => __( 'Import selected items from an inspected unified transfer ZIP. Requires explicit `types`, exact manifest item IDs, and `expectedZipHash` from `bricks/inspect-transfer-package`. Defaults to keeping existing data on conflicts. Any replacement requires `allowOverwrite: true`; sensitive settings tabs require `allowSensitiveSettings: true`.', 'bricks' ),
				'category'            => 'bricks-import-export',
				'input_schema'        => Import_Export::import_transfer_package_schema(),
				'output_schema'       => Import_Export::import_transfer_package_output_schema(),
				'execute_callback'    => [ Import_Export::class, 'import_transfer_package' ],
				'permission_callback' => [ Import_Export::class, 'transfer_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'destructive' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Register system-information abilities.
	 *
	 * @since 2.4
	 */
	private function register_system_info_abilities() {
		$this->register(
			'bricks/get-system-information',
			[
				'label'               => __( 'Get Bricks system information', 'bricks' ),
				'description'         => __( 'Read-only environment snapshot covering theme, Bricks, WordPress, server, and active plugins. Mirrors Settings > Bricks > System Information. License and API key values are never included.', 'bricks' ),
				'category'            => 'bricks-system',
				'input_schema'        => System_Info::get_system_information_schema(),
				'output_schema'       => System_Info::get_system_information_output_schema(),
				'execute_callback'    => [ System_Info::class, 'get_system_information' ],
				'permission_callback' => [ System_Info::class, 'read_permission' ],
				'meta'                => [
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true ],
					'show_in_rest' => true,
				],
			]
		);
	}

	/**
	 * Determine the default enabled state for an ability.
	 *
	 * @since 2.4
	 *
	 * @param array $args Ability registration args.
	 * @return bool
	 */
	private static function ability_default_enabled( array $args ): bool {
		if ( array_key_exists( 'default_enabled', $args ) ) {
			return (bool) $args['default_enabled'];
		}

		$category = isset( $args['category'] ) ? (string) $args['category'] : '';

		if ( in_array( $category, self::DEFAULT_DISABLED_CATEGORIES, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Flush WordPress object cache for a specific post
	 *
	 * The MCP adapter runs as a long-lived WP-CLI process. WordPress object cache
	 * persists for the lifetime of the process, so external changes (builder saves,
	 * REST API calls) are invisible until the cache is invalidated.
	 *
	 * Call this at the top of any ability callback that reads post meta.
	 *
	 * @since 2.4
	 *
	 * @param int $post_id Post ID to invalidate cache for.
	 */
	public static function flush_post_cache( $post_id ) {
		wp_cache_delete( $post_id, 'post_meta' );
		wp_cache_delete( $post_id, 'posts' );
	}

	/**
	 * Register a Bricks ability via the Abilities API with normalized meta.
	 *
	 * The mcp-adapter reads `public` and `type` from `meta.mcp.*`, but reads
	 * `uri`, `mimeType`, and `annotations` from the top level of `meta` (see
	 * RegisterAbilityAsMcpResource::get_uri and RegisterAbilityAsMcpTool::get_data).
	 * If a caller accidentally nested those keys inside `meta.mcp`, promote them
	 * to the top level so both shapes work.
	 *
	 * @since 2.4
	 *
	 * @param string $name Ability name (e.g. `bricks/list-templates`).
	 * @param array  $args Standard wp_register_ability args.
	 */
	private function register( string $name, array $args ) {
		$managed_externally    = ! empty( $args['managed_externally'] );
		$availability_callback = isset( $args['availability_callback'] ) && is_callable( $args['availability_callback'] )
			? $args['availability_callback']
			: null;
		unset( $args['managed_externally'], $args['availability_callback'] );

		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$meta = $args['meta'];
			$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : [];

			foreach ( [ 'annotations', 'mimeType', 'uri' ] as $key ) {
				if ( isset( $mcp[ $key ] ) && ! isset( $meta[ $key ] ) ) {
					$meta[ $key ] = $mcp[ $key ];
				}

				if ( isset( $meta[ $key ] ) && ! isset( $mcp[ $key ] ) ) {
					$mcp[ $key ] = $meta[ $key ];
				}
			}

			// WordPress defaults an omitted destructive annotation to true.
			// Bricks marks only destructive writes explicitly, so normalize the
			// omission here instead of making every safe ability repeat false.
			// Merge both supported locations per key before mirroring them so
			// readonly/idempotent hints and an explicit destructive=true survive.
			$mcp_annotations  = isset( $mcp['annotations'] ) && is_array( $mcp['annotations'] ) ? $mcp['annotations'] : [];
			$meta_annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
			$annotations      = array_merge( $mcp_annotations, $meta_annotations );

			if ( ! array_key_exists( 'destructive', $annotations ) ) {
				$annotations['destructive'] = false;
			}

			$meta['annotations'] = $annotations;
			$mcp['annotations']  = $annotations;

			$meta['mcp']  = $mcp;
			$args['meta'] = $meta;
		}

		// Default top-level input schemas to strict: unknown parameters are
		// common MCP client mistakes (misspelling a param, inventing a key).
		// Without `additionalProperties: false` those keys
		// silently drop and the ability "succeeds" while ignoring half the
		// input. Individual abilities can opt out by setting it explicitly.
		if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
			$schema = $args['input_schema'];
			if ( ( $schema['type'] ?? '' ) === 'object' ) {
				// Coerce empty `properties` to an object so JSON-encode emits `{}`
				// instead of `[]`. A no-arg ability that declares `'properties' => []`
				// (a literal PHP empty array) would otherwise serialize as a JSON
				// array, which MCP clients reject as an invalid schema — and a single
				// rejected tool drops the entire `tools/list` payload client-side.
				if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && empty( $schema['properties'] ) ) {
					$schema['properties'] = new \stdClass();
				}

				if ( ! array_key_exists( 'additionalProperties', $schema ) ) {
					$schema['additionalProperties'] = false;
				}

				// Declare an empty-object default so WP_Ability::normalize_input()
				// substitutes `{}` when a transport delivers no input (null). Without
				// this, zero-input abilities (and all-optional ones called with no
				// args) fail validation with "input is not of type object" because
				// null is validated against `type: object`. Abilities with required
				// properties still error, but with a clear "missing required" message.
				if ( ! array_key_exists( 'default', $schema ) ) {
					$schema['default'] = new \stdClass();
				}

				$args['input_schema'] = $schema;
			}
		}

		$input_schema = isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ? $args['input_schema'] : [];

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$args['execute_callback'] = self::wrap_callback_input( $name, $args['execute_callback'], $input_schema );
		}

		if ( isset( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ) {
			$args['permission_callback'] = self::wrap_callback_input( $name, $args['permission_callback'], $input_schema );
		}

		$default_enabled                        = self::ability_default_enabled( $args );
		self::$ability_default_enabled[ $name ] = $default_enabled;
		unset( $args['default_enabled'] );
		$enabled     = $managed_externally && $availability_callback
			? (bool) call_user_func( $availability_callback )
			: self::is_ability_enabled( $name, $default_enabled );
		$annotations = isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] )
			? $args['meta']['annotations']
			: [];
		$destructive = ! empty( $annotations['destructive'] );
		$readonly    = ! empty( $annotations['readonly'] );
		$idempotent  = ! empty( $annotations['idempotent'] );

		// Always record the ability in the registry so `list-ability-status`
		// can surface disabled ones. The admin screen also reads this registry
		// to render the per-ability toggle list.
		$this->ability_registry[ $name ] = [
			'name'              => $name,
			'label'             => $args['label'] ?? $name,
			'description'       => $args['description'] ?? '',
			'category'          => $args['category'] ?? '',
			'destructive'       => $destructive,
			'readonly'          => $readonly,
			'idempotent'        => $idempotent,
			'defaultEnabled'    => $default_enabled,
			'enabled'           => $enabled,
			'managedExternally' => $managed_externally,
		];

		// Only hit `wp_register_ability()` during the `wp_abilities_api_init`
		// hook. The admin screen builds the registry eagerly (see
		// get_ability_registry) so it can render the toggle list before the
		// hook fires; those calls populate $ability_registry only.
		if ( ! $this->wp_registration_active ) {
			return;
		}

		// Guard against double-registration if both pathways (hook + lazy
		// admin read) fire in the same request.
		if ( isset( $this->wp_registered[ $name ] ) ) {
			return;
		}

		if ( ! $enabled && ! $managed_externally ) {
			$this->register_disabled_ability_shim( $name, $args );
			return;
		}

		$this->wp_registered[ $name ] = true;

		// Indirect call so a future bulk-rename pass on `wp_register_ability(`
		// does not accidentally rewrite the body of this helper.
		call_user_func( 'wp_register_ability', $name, $args );
	}

	/**
	 * Register a disabled ability as an inspectable shim.
	 *
	 * The adapter can now answer get-info/discover requests for disabled
	 * abilities, while executions fail with a structured Bricks error instead
	 * of looking like an unknown ability.
	 *
	 * @since 2.4
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability registration args.
	 * @return void
	 */
	private function register_disabled_ability_shim( string $name, array $args ): void {
		$this->wp_registered[ $name ] = true;

		$schema = isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ? $args['input_schema'] : [];

		$args['execute_callback'] = static function ( $input = [] ) use ( $name, $schema ) {
			$input = self::normalize_callback_input( $input );

			$validation = self::validate_top_level_input( $input, $schema );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			return Error::ability_disabled( $name );
		};

		$args['permission_callback'] = static function ( $input = [] ) use ( $schema ) {
			$input = self::normalize_callback_input( $input );

			$validation = self::validate_top_level_input( $input, $schema );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			return true;
		};

		call_user_func( 'wp_register_ability', $name, $args );
	}

	/**
	 * Wrap an ability callback with Bricks-side input normalization.
	 *
	 * The WordPress MCP adapter often passes JSON object parameters as
	 * `stdClass` instances. Ability callbacks in Bricks consistently expect
	 * arrays, so normalize before callback execution and enforce strict
	 * top-level schemas here as a stable boundary.
	 *
	 * @since 2.4
	 *
	 * @param string   $ability  Ability name used for structured internal-error context.
	 * @param callable $callback Original execute or permission callback.
	 * @param array    $schema   Input schema after manager defaults.
	 * @return callable
	 */
	private static function wrap_callback_input( string $ability, $callback, array $schema ): callable {
		return static function ( $input = [] ) use ( $ability, $callback, $schema ) {
			$input = self::normalize_callback_input( $input );

			$validation = self::validate_top_level_input( $input, $schema );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			try {
				return call_user_func( $callback, $input );
			} catch ( \Throwable $throwable ) {
				return Error::internal_error(
					$ability,
					$throwable->getMessage(),
					[
						'exception' => get_class( $throwable ),
					]
				);
			}
		};
	}

	/**
	 * Normalize adapter input to plain PHP arrays.
	 *
	 * @since 2.4
	 *
	 * @param mixed $input Raw callback input.
	 * @return array
	 */
	private static function normalize_callback_input( $input ): array {
		if ( $input === null ) {
			return [];
		}

		if ( $input instanceof \stdClass ) {
			$input = (array) $input;
		}

		if ( is_object( $input ) ) {
			$input = get_object_vars( $input );
		}

		if ( ! is_array( $input ) ) {
			return [];
		}

		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$input[ $key ] = self::normalize_nested_input( $value );
			}
		}

		return $input;
	}

	/**
	 * Recursively normalize nested input values.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Raw nested value.
	 * @return mixed
	 */
	private static function normalize_nested_input( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $nested_value ) {
			if ( is_array( $nested_value ) || is_object( $nested_value ) ) {
				$value[ $key ] = self::normalize_nested_input( $nested_value );
			}
		}

		return $value;
	}

	/**
	 * Enforce top-level additionalProperties=false before callbacks run.
	 *
	 * @since 2.4
	 *
	 * @param array $input  Normalized input array.
	 * @param array $schema Input schema after manager defaults.
	 * @return true|\WP_Error
	 */
	private static function validate_top_level_input( array $input, array $schema ) {
		if ( ( $schema['type'] ?? '' ) !== 'object' ) {
			return true;
		}

		if ( ( $schema['additionalProperties'] ?? null ) !== false ) {
			return true;
		}

		$properties = isset( $schema['properties'] ) ? $schema['properties'] : [];
		if ( $properties instanceof \stdClass ) {
			$properties = (array) $properties;
		}

		$allowed = is_array( $properties ) ? array_keys( $properties ) : [];

		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				return Error::unknown_param( (string) $key, $allowed );
			}
		}

		return true;
	}

	/**
	 * Standard pagination input schema fragment.
	 *
	 * Emits `page` and `perPage` properties with a bounded max so MCP callers
	 * can't request unbounded pages. Use inside `properties` of any list-style
	 * input schema via `array_merge`.
	 *
	 * @since 2.4
	 *
	 * @param int $max_per_page Upper bound on perPage (matches runtime clamp).
	 * @return array Two-key assoc array ready to merge into `properties`.
	 */
	public static function pagination_schema_properties( int $max_per_page = 200 ): array {
		return [
			'page'    => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Page number (1-indexed). Defaults to 1.', 'bricks' ),
			],
			'perPage' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $max_per_page,
				'description' => sprintf( 'Items per page. Defaults to 25, max %d.', $max_per_page ),
			],
		];
	}

	/**
	 * Gate an ability callback on a Bricks builder capability.
	 *
	 * Used by permission callbacks that need to enforce capabilities beyond the
	 * basic edit_post check. Returns true on success or a structured WP_Error on failure
	 * so MCP clients see a stable code (`bricks_forbidden_builder_permission`).
	 *
	 * @since 2.4
	 *
	 * @param string $capability Bricks capability slug - e.g. `bricks_full_access`, `bricks_edit_content`.
	 * @return true|\WP_Error
	 */
	public static function require_cap( string $capability ) {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return Error::forbidden_builder_permission( $capability );
	}

	/**
	 * Lazy-load wp-admin/includes/post.php so abilities running in REST/CLI
	 * context can call `wp_check_post_lock()` and `wp_set_post_lock()`.
	 *
	 * These functions live in admin code that the REST runtime does not load
	 * by default. Calling this once at the top of any write callback that uses
	 * post locks keeps the require local to its dependency.
	 *
	 * @since 2.4
	 */
	public static function ensure_post_admin_loaded() {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
	}

	/**
	 * Flush WordPress object cache for options
	 *
	 * Invalidates the alloptions cache so get_option() re-reads from the database.
	 * Safe to call in CLI context - no impact on HTTP request caching.
	 *
	 * Call this at the top of any ability callback that reads options.
	 *
	 * @since 2.4
	 */
	public static function flush_options_cache() {
		self::reset_settings_cache();
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// Re-prime the in-memory copy of bricks_global_settings that
		// Database::get_setting() reads. Without this, MCP writes update the
		// option but same-request reads via Database::$global_settings stay stale.
		if ( class_exists( '\\Bricks\\Database' ) && defined( 'BRICKS_DB_GLOBAL_SETTINGS' ) ) {
			$fresh                                     = get_option( BRICKS_DB_GLOBAL_SETTINGS, [] );
			\Bricks\Database::$global_settings         = is_array( $fresh ) ? $fresh : [];
			\Bricks\Database::$global_data['settings'] = \Bricks\Database::$global_settings;
		}
	}
}

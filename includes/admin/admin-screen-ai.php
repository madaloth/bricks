<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$settings              = Database::$global_settings;
$mcp_settings          = \Bricks\Abilities\Manager::get_mcp_settings();
$disabled_abilities    = $mcp_settings['disabledAbilities'];
$enabled_abilities     = $mcp_settings['enabledAbilities'];
$mcp_master_requested  = isset( $settings['abilitiesApi'] );
$mcp_disabled_by_const = \Bricks\Abilities\Manager::is_disabled_by_constant();
$bricks_instance       = Theme::instance();
$abilities_manager     = isset( $bricks_instance->abilities ) ? $bricks_instance->abilities : null;

if ( ! $abilities_manager instanceof \Bricks\Abilities\Manager ) {
	$abilities_manager          = new \Bricks\Abilities\Manager();
	$bricks_instance->abilities = $abilities_manager;
}

$ability_rows = $abilities_manager->get_ability_registry();

$category_order = [
	'bricks-system',
	'bricks-breakpoints',
	'bricks-cms',
	'bricks-content',
	'bricks-design',
	'bricks-templates',
	'bricks-maintenance',
	'bricks-elements',
	'bricks-reference',
	'bricks-settings',
	'bricks-meta',
	'bricks-revisions',
	'bricks-media',
	'bricks-forms',
	'bricks-filters',
	'bricks-popups',
	'bricks-interactions',
	'bricks-permissions',
	'bricks-queries',
	'bricks-sidebars',
	'bricks-fonts',
	'bricks-icons',
	'bricks-pseudo-classes',
	'bricks-style-manager',
	'bricks-import-export',
];

$category_icons = [
	'bricks-breakpoints'    => 'dashicons-screenoptions',
	'bricks-cms'            => 'dashicons-database',
	'bricks-content'        => 'dashicons-admin-page',
	'bricks-design'         => 'dashicons-art',
	'bricks-templates'      => 'dashicons-layout',
	'bricks-maintenance'    => 'dashicons-admin-tools',
	'bricks-elements'       => 'dashicons-editor-table',
	'bricks-reference'      => 'dashicons-book',
	'bricks-settings'       => 'dashicons-admin-generic',
	'bricks-meta'           => 'dashicons-info',
	'bricks-revisions'      => 'dashicons-backup',
	'bricks-media'          => 'dashicons-format-image',
	'bricks-forms'          => 'dashicons-feedback',
	'bricks-filters'        => 'dashicons-filter',
	'bricks-popups'         => 'dashicons-welcome-widgets-menus',
	'bricks-interactions'   => 'dashicons-randomize',
	'bricks-permissions'    => 'dashicons-lock',
	'bricks-queries'        => 'dashicons-search',
	'bricks-sidebars'       => 'dashicons-align-pull-right',
	'bricks-fonts'          => 'dashicons-editor-textcolor',
	'bricks-icons'          => 'dashicons-star-filled',
	'bricks-pseudo-classes' => 'dashicons-editor-code',
	'bricks-style-manager'  => 'dashicons-admin-appearance',
	'bricks-import-export'  => 'dashicons-migrate',
	'bricks-system'         => 'dashicons-admin-site',
];

$category_label = function( $category ) {
	if (
		function_exists( 'wp_has_ability_category' ) &&
		function_exists( 'wp_get_ability_category' ) &&
		wp_has_ability_category( $category )
	) {
		$registered_category = wp_get_ability_category( $category );

		if ( $registered_category ) {
			return $registered_category->get_label();
		}
	}

	$label = ucwords( str_replace( '-', ' ', str_replace( 'bricks-', '', $category ) ) );

	return str_replace(
		[ 'Cms', 'Css', 'Mcp' ],
		[ 'CMS', 'CSS', 'MCP' ],
		$label
	);
};

$format_description = function( $description ) {
	if ( ! is_string( $description ) || $description === '' ) {
		return '';
	}

	$parts = preg_split( '/(`[^`]+`)/', $description, -1, PREG_SPLIT_DELIM_CAPTURE );
	$html  = '';

	foreach ( $parts as $part ) {
		if ( strlen( $part ) > 1 && $part[0] === '`' && substr( $part, -1 ) === '`' ) {
			$html .= '<code>' . esc_html( trim( $part, '`' ) ) . '</code>';
		} else {
			$html .= esc_html( $part );
		}
	}

	return $html;
};

$abilities_api_ready = function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );

$ability_states      = [];
$grouped_abilities   = [];
$enabled_count       = 0;
$default_off_count   = 0;
$visible_total_count = count( $ability_rows );

foreach ( $ability_rows as $ability ) {
	$category        = ! empty( $ability['category'] ) ? $ability['category'] : 'bricks-other';
	$default_enabled = isset( $ability['defaultEnabled'] ) ? (bool) $ability['defaultEnabled'] : true;
	$is_disabled     = in_array( $ability['name'], $disabled_abilities, true );
	$is_explicit_on  = in_array( $ability['name'], $enabled_abilities, true );
	$is_always_on    = in_array( $ability['name'], \Bricks\Abilities\Manager::ALWAYS_ON_ABILITIES, true );
	$is_managed      = ! empty( $ability['managedExternally'] );
	$is_enabled      = $is_managed
		? ! empty( $ability['enabled'] )
		: ( $is_always_on || ( $default_enabled ? ! $is_disabled : $is_explicit_on ) );

	if ( $ability['name'] === \Bricks\Abilities\Execute_Php::ABILITY_NAME && ! $abilities_api_ready ) {
		$is_enabled = false;
	}

	if ( $is_enabled ) {
		$enabled_count++;
	}

	if ( ! $default_enabled ) {
		$default_off_count++;
	}

	$ability_states[ $ability['name'] ] = [
		'default_enabled' => $default_enabled,
		'is_always_on'    => $is_always_on,
		'is_enabled'      => $is_enabled,
		'is_managed'      => $is_managed,
	];

	$grouped_abilities[ $category ][] = $ability;
}

if ( isset( $grouped_abilities['bricks-system'] ) ) {
	foreach ( $grouped_abilities['bricks-system'] as $index => $system_ability ) {
		if ( $system_ability['name'] !== \Bricks\Abilities\Execute_Php::ABILITY_NAME ) {
			continue;
		}

		unset( $grouped_abilities['bricks-system'][ $index ] );
		array_unshift( $grouped_abilities['bricks-system'], $system_ability );
		break;
	}
}

uksort(
	$grouped_abilities,
	function( $a, $b ) use ( $category_order ) {
		$a_index = array_search( $a, $category_order, true );
		$b_index = array_search( $b, $category_order, true );

		$a_index = $a_index === false ? PHP_INT_MAX : $a_index;
		$b_index = $b_index === false ? PHP_INT_MAX : $b_index;

		if ( $a_index === $b_index ) {
			return strcmp( $a, $b );
		}

		return $a_index <=> $b_index;
	}
);

$category_counts = [];

foreach ( $grouped_abilities as $category => $abilities_in_category ) {
	$category_enabled_count = 0;

	foreach ( $abilities_in_category as $ability ) {
		if ( ! empty( $ability_states[ $ability['name'] ]['is_enabled'] ) ) {
			$category_enabled_count++;
		}
	}

	$category_counts[ $category ] = [
		'enabled' => $category_enabled_count,
		'total'   => count( $abilities_in_category ),
	];
}

$mcp_server_status  = Helpers::get_mcp_server_status();
$status_state       = $mcp_server_status['state'];
$source_label       = $mcp_server_status['source_label'];
$version            = $mcp_server_status['version'];
$plugin_file        = $mcp_server_status['plugin_file'];
$endpoint_url       = $mcp_server_status['endpoint_url'];
$mcp_adapter_active = $status_state === 'connected';
$mcp_master_enabled = $mcp_master_requested && $abilities_api_ready && ! $mcp_disabled_by_const;
$current_user       = wp_get_current_user();
$execute_php_state  = \Bricks\Abilities\Execute_Php::admin_state();

$app_passwords_ok            = class_exists( '\WP_Application_Passwords' ) && function_exists( 'wp_is_application_passwords_available_for_user' );
$app_passwords_available     = $app_passwords_ok && function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
$app_passwords_require_https = $app_passwords_ok && function_exists( 'wp_is_application_passwords_supported' ) && ! wp_is_application_passwords_supported() && ! $app_passwords_available;

$credential_users = [];
$setup_guide_url  = apply_filters( 'bricks_mcp_adapter_setup_guide_url', 'https://academy.bricksbuilder.io/builder/features/ai-abilities-and-skills/' );

if (
	$app_passwords_ok &&
	$app_passwords_available &&
	$current_user instanceof \WP_User &&
	$current_user->ID &&
	current_user_can( 'create_app_password', $current_user->ID ) &&
	current_user_can( 'list_app_passwords', $current_user->ID ) &&
	wp_is_application_passwords_available_for_user( $current_user )
) {
	$credential_users[] = [
		'id'        => $current_user->ID,
		'label'     => $current_user->display_name ? $current_user->display_name : $current_user->user_login,
		'login'     => $current_user->user_login,
		'url'       => admin_url( 'profile.php#application-passwords-section' ),
		'passwords' => \WP_Application_Passwords::get_user_application_passwords( $current_user->ID ),
	];
}

$selected_credential_user = null;

foreach ( $credential_users as $credential_user ) {
	if ( (int) $credential_user['id'] === (int) $current_user->ID ) {
		$selected_credential_user = $credential_user;
		break;
	}
}

if ( ! $selected_credential_user && ! empty( $credential_users ) ) {
	$selected_credential_user = $credential_users[0];
}

$app_passwords            = $selected_credential_user ? $selected_credential_user['passwords'] : [];
$app_password_count       = count( $app_passwords );
$can_create_ai_credential = $app_passwords_ok && ! empty( $credential_users );
$connect_ready            = $mcp_adapter_active && $app_password_count > 0;
$endpoint_host            = $endpoint_url ? wp_parse_url( $endpoint_url, PHP_URL_HOST ) : wp_parse_url( home_url(), PHP_URL_HOST );
$mcp_server_name          = sanitize_title( $endpoint_host ? $endpoint_host : get_bloginfo( 'name' ) );
$local_https              = $endpoint_url && strpos( $endpoint_url, 'https://' ) === 0 && preg_match( '/(\.local|\.test|\.localhost|\.ddev\.site|localhost|127\.0\.0\.1)$/', (string) $endpoint_host );

$skills_readiness_items = [
	[
		'key'   => 'mcp-server',
		'label' => esc_html__( 'MCP server configured', 'bricks' ),
		'state' => $mcp_adapter_active ? 'ready' : 'pending',
	],
	[
		'key'   => 'application-password',
		'label' => esc_html__( 'Application password created', 'bricks' ),
		'state' => $app_password_count > 0 ? 'ready' : 'pending',
	],
	[
		'key'   => 'abilities',
		'label' => esc_html__( 'Bricks abilities enabled', 'bricks' ),
		'state' => $mcp_master_enabled ? 'ready' : 'pending',
	],
];

$connect_prompt_text = [
	'intro'               => esc_html__( 'Add this WordPress site as an MCP server in this AI client.', 'bricks' ),
	'detailsHeading'      => esc_html__( 'Connection details:', 'bricks' ),
	'serverUrl'           => esc_html__( 'Server URL', 'bricks' ),
	'username'            => esc_html__( 'Username', 'bricks' ),
	'applicationPassword' => esc_html__( 'Application password', 'bricks' ),
	'passwordPlaceholder' => '[' . esc_html__( 'Generate an application password to fill this value', 'bricks' ) . ']',
	'serverName'          => esc_html__( 'Server name', 'bricks' ),
	'transport'           => esc_html__( 'Transport', 'bricks' ),
	'transportValue'      => esc_html__( 'stdio command via npx', 'bricks' ),
	'package'             => esc_html__( 'Package', 'bricks' ),
	'rulesHeading'        => esc_html__( 'Configuration rules:', 'bricks' ),
	'configRule'          => esc_html__( 'Add the server to the AI client MCP configuration.', 'bricks' ),
	'approvalRule'        => esc_html__( 'If the AI client supports MCP tool approval settings, allow or approve this server\'s Bricks abilities so they can run without per-call approval prompts. Setting names vary by client.', 'bricks' ),
	'invokeRule'          => esc_html__( 'Invoke the server with `npx -y @automattic/mcp-wordpress-remote@latest`.', 'bricks' ),
	'credentialsRule'     => esc_html__( 'Pass credentials only through the MCP process environment: WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD.', 'bricks' ),
	'tlsRule'             => esc_html__( 'Set `NODE_TLS_REJECT_UNAUTHORIZED=0` only in this MCP server environment because the local site uses a development HTTPS certificate.', 'bricks' ),
	'secretRule'          => esc_html__( 'Do not place credentials in command arguments, URLs, or global shell config.', 'bricks' ),
	'preserveRule'        => esc_html__( 'Preserve existing MCP server entries.', 'bricks' ),
	'restartChatRule'     => esc_html__( 'After saving the MCP configuration, start a new AI chat so the client reloads the available Bricks abilities.', 'bricks' ),
	'testRule'            => esc_html__( 'To test the connection, ask the new chat to list the available Bricks abilities. If it cannot list them, the client is not connected to this site.', 'bricks' ),
];

$manual_config_clients = [
	'amazon-q'         => [
		'label'   => 'Amazon Q',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Global', 'bricks' )  => '~/.aws/amazonq/mcp.json',
			esc_html__( 'Project', 'bricks' ) => '.amazonq/mcp.json',
		],
		'variant' => 'mcp-servers',
	],
	'antigravity'      => [
		'label'   => 'Antigravity',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp_config.json</code>',
		'paths'   => [
			'macOS / Linux' => '~/.gemini/antigravity/mcp_config.json',
			'Windows'       => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json',
		],
		'variant' => 'mcp-servers',
	],
	'claude-code'      => [
		'label'   => 'Claude Code',
		'hint'    => esc_html__( 'Run in your terminal.', 'bricks' ),
		'paths'   => [],
		'variant' => 'claude-code',
	],
	'claude-desktop'   => [
		'label'   => 'Claude Desktop',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>claude_desktop_config.json</code>',
		'paths'   => [
			'macOS'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
			'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
		],
		'variant' => 'mcp-servers',
	],
	'cline'            => [
		'label'   => 'Cline',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>cline_mcp_settings.json</code>',
		'paths'   => [
			esc_html__( 'Via UI', 'bricks' ) => esc_html__( 'Cline sidebar -> MCP Servers -> Configure MCP Servers', 'bricks' ),
		],
		'variant' => 'mcp-servers',
	],
	'codex'            => [
		'label'   => 'Codex',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>config.toml</code>',
		'paths'   => [
			'macOS / Linux' => '~/.codex/config.toml',
			'Windows'       => '%USERPROFILE%\\.codex\\config.toml',
		],
		'variant' => 'codex',
	],
	'continue'         => [
		'label'   => 'Continue',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Workspace', 'bricks' ) => '.continue/mcpServers/mcp.json',
		],
		'variant' => 'mcp-servers',
	],
	'cursor'           => [
		'label'   => 'Cursor',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Global', 'bricks' )  => '~/.cursor/mcp.json',
			esc_html__( 'Project', 'bricks' ) => '.cursor/mcp.json',
		],
		'variant' => 'mcp-servers',
	],
	'gemini-cli'       => [
		'label'   => 'Gemini CLI',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>settings.json</code>',
		'paths'   => [
			esc_html__( 'Global', 'bricks' )  => '~/.gemini/settings.json',
			esc_html__( 'Project', 'bricks' ) => '.gemini/settings.json',
		],
		'variant' => 'mcp-servers',
	],
	'github-copilot'   => [
		'label'   => 'GitHub Copilot',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Project', 'bricks' ) => '.github/copilot/mcp.json',
		],
		'variant' => 'servers',
	],
	'jetbrains'        => [
		'label'   => 'JetBrains AI Assistant',
		'hint'    => esc_html__( 'Paste into the JetBrains AI Assistant MCP settings.', 'bricks' ),
		'paths'   => [
			esc_html__( 'Via UI', 'bricks' ) => esc_html__( 'Settings -> Tools -> AI Assistant -> Model Context Protocol', 'bricks' ),
		],
		'variant' => 'mcp-servers',
	],
	'kilo-code'        => [
		'label'   => 'Kilo Code',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Project', 'bricks' ) => '.kilocode/mcp.json',
			esc_html__( 'Via UI', 'bricks' )  => esc_html__( 'Kilo Code sidebar -> MCP Servers -> Configure MCP Servers', 'bricks' ),
		],
		'variant' => 'mcp-servers',
	],
	'lm-studio'        => [
		'label'   => 'LM Studio',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Via UI', 'bricks' ) => esc_html__( 'Program -> Install -> Edit mcp.json', 'bricks' ),
		],
		'variant' => 'mcp-servers',
	],
	'opencode'         => [
		'label'   => 'OpenCode',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>opencode.json</code>',
		'paths'   => [
			esc_html__( 'Project', 'bricks' ) => 'opencode.json',
			esc_html__( 'Global', 'bricks' )  => '~/.config/opencode/opencode.json',
		],
		'variant' => 'opencode',
	],
	'roo-code'         => [
		'label'   => 'Roo Code',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Project', 'bricks' ) => '.roo/mcp.json',
			esc_html__( 'Via UI', 'bricks' )  => esc_html__( 'Roo Code sidebar -> MCP Servers -> Configure MCP Servers', 'bricks' ),
		],
		'variant' => 'mcp-servers',
	],
	'sourcegraph-cody' => [
		'label'   => 'Sourcegraph Cody',
		'hint'    => esc_html__( 'Add to Cody settings.', 'bricks' ),
		'paths'   => [
			'VS Code'   => 'settings.json',
			'JetBrains' => 'cody_settings.json',
		],
		'variant' => 'cody',
	],
	'vscode'           => [
		'label'   => 'VS Code',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp.json</code>',
		'paths'   => [
			esc_html__( 'Workspace', 'bricks' ) => '.vscode/mcp.json',
			esc_html__( 'User', 'bricks' )      => esc_html__( 'Run: MCP: Open User Configuration (command palette)', 'bricks' ),
		],
		'variant' => 'servers',
	],
	'windsurf'         => [
		'label'   => 'Windsurf',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>mcp_config.json</code>',
		'paths'   => [
			'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
			'Windows'       => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
		],
		'variant' => 'mcp-servers',
	],
	'zed'              => [
		'label'   => 'Zed',
		'hint'    => esc_html__( 'Add to', 'bricks' ) . ' <code>settings.json</code>',
		'paths'   => [
			'macOS / Linux' => '~/.config/zed/settings.json',
		],
		'variant' => 'zed',
	],
];

$manual_config_default_client = isset( $manual_config_clients['codex'] ) ? 'codex' : array_key_first( $manual_config_clients );

$manual_config_text = [
	'toggle'              => esc_html__( 'Need the config for a specific client?', 'bricks' ),
	'description'         => esc_html__( 'Select your client and replace YOUR-APP-PASSWORD with the application password from the previous step.', 'bricks' ),
	'copy'                => esc_html__( 'Copy', 'bricks' ),
	'copied'              => esc_html__( 'Copied', 'bricks' ),
	'passwordPlaceholder' => 'YOUR-APP-PASSWORD',
];

$skills_package = [
	'repository' => 'https://github.com/codeerhq/bricks-skills',
];

$skills_prompt_text = [
	'description' => esc_html__( 'Copy this prompt into your AI client to install the Bricks skills package.', 'bricks' ),
	'copy'        => esc_html__( 'Copy skills prompt', 'bricks' ),
	'copied'      => esc_html__( 'Skills prompt copied', 'bricks' ),
	'repoUrl'     => $skills_package['repository'],
	'lines'       => [
		esc_html__( 'Install the Bricks skills package for this AI client.', 'bricks' ),
		'',
		esc_html__( 'Source repository: {repoUrl}', 'bricks' ),
		'',
		esc_html__( 'Read the repository README and use the install method that matches this client.', 'bricks' ),
		esc_html__( 'Prefer the latest public GitHub release when the client needs a downloadable archive.', 'bricks' ),
		esc_html__( 'If this client cannot install skills or plugin-style guidance, stop and say so.', 'bricks' ),
		esc_html__( 'After installation, report the installed Bricks skill names and whether this client needs a restart or new chat to load them.', 'bricks' ),
	],
];

$skills_verify_prompt_text = [
	'copy'   => esc_html__( 'Copy verification prompt', 'bricks' ),
	'copied' => esc_html__( 'Verification prompt copied', 'bricks' ),
	'lines'  => [
		esc_html__( 'Do not make changes.', 'bricks' ),
		esc_html__( 'List the client-side skills loaded in this client whose names start with `bricks-`.', 'bricks' ),
		esc_html__( 'For each skill, include the skill name and install location if this client exposes it.', 'bricks' ),
		esc_html__( 'If no Bricks skills are loaded, say that the Bricks skills are not loaded. Do not call site abilities for this check.', 'bricks' ),
	],
];

$api_status = [
	'class' => 'is-warning',
	'label' => esc_html__( 'Not installed', 'bricks' ),
];

if ( $mcp_disabled_by_const ) {
	$api_status = [
		'class' => 'is-error',
		'label' => esc_html__( 'Disabled', 'bricks' ),
	];
} elseif ( ! $abilities_api_ready ) {
	$api_status = [
		'class' => 'is-error',
		'label' => esc_html__( 'Unavailable', 'bricks' ),
	];
} else {
	$api_status = $mcp_master_enabled ? [
		'class' => 'is-success',
		'label' => esc_html__( 'Enabled', 'bricks' ),
	] : [
		'class' => '',
		'label' => esc_html__( 'Disabled', 'bricks' ),
	];
}

$api_status_live = ! $mcp_disabled_by_const && $abilities_api_ready;

$execute_php_configured = $execute_php_state['configured'];
$execute_php_available  = $execute_php_state['enabled'] && $abilities_api_ready;
$execute_php_constant   = $execute_php_state['constantStatus'];

if ( $execute_php_constant === 'invalid' ) {
	$execute_php_display_status = [
		'class' => 'is-error',
		'label' => esc_html__( 'Invalid configuration', 'bricks' ),
	];
} elseif ( $execute_php_configured && $execute_php_state['signaturesLocked'] ) {
	$execute_php_display_status = [
		'class' => 'is-error',
		'label' => esc_html__( 'Blocked by signature lock', 'bricks' ),
	];
} elseif ( $execute_php_configured ) {
	$execute_php_display_status = [
		'class' => 'is-success',
		'label' => esc_html__( 'Enabled', 'bricks' ),
	];
} elseif ( $execute_php_constant === 'false' ) {
	$execute_php_display_status = [
		'class' => '',
		'label' => esc_html__( 'Disabled', 'bricks' ),
	];
} else {
	$execute_php_display_status = [
		'class' => '',
		'label' => esc_html__( 'Not configured', 'bricks' ),
	];
}
?>

<div class="wrap bricks-admin-wrapper ai">
	<h1 class="admin-notices-placeholder"></h1>

	<div class="bricks-ai-page-header">
		<div>
			<h1 class="title">
				<?php esc_html_e( 'AI', 'bricks' ); ?>
				<span class="bricks-ai-badge bricks-ai-badge-experimental"><?php esc_html_e( 'Experimental', 'bricks' ); ?></span>
			</h1>

			<p class="bricks-admin-lead">
				<?php esc_html_e( 'Enable Bricks abilities, connect MCP clients such as Claude or Codex, and install optional client-side skills for better Bricks guidance.', 'bricks' ); ?>
			</p>
		</div>
	</div>

	<nav class="bricks-ai-tabs" aria-label="<?php esc_attr_e( 'AI settings', 'bricks' ); ?>">
		<button type="button" class="is-active" data-ai-tab="config" aria-controls="bricks-ai-tab-config">
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<?php esc_html_e( 'Configuration', 'bricks' ); ?>
		</button>

		<button type="button" data-ai-tab="abilities" aria-controls="bricks-ai-tab-abilities">
			<span class="dashicons dashicons-menu-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'Abilities', 'bricks' ); ?>
		</button>

		<button type="button" data-ai-tab="skills" aria-controls="bricks-ai-tab-skills">
			<span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
			<?php esc_html_e( 'Skills', 'bricks' ); ?>
		</button>
	</nav>

	<?php // novalidate: the form never submits natively (saving is AJAX via the submit handler), so unrelated required fields (e.g. credential inputs) must not block the Save button ?>
	<form id="bricks-ai-settings" class="bricks-ai-settings" method="post" novalidate>
		<div id="bricks-ai-tab-config" class="bricks-ai-tab-panel is-active" data-ai-tab-panel="config">
			<div class="bricks-ai-config-layout">
					<div class="bricks-ai-config-panels">
						<section class="bricks-ai-card bricks-ai-api-card">
							<button type="button" class="bricks-ai-config-tab-header" data-ai-config-toggle aria-expanded="true" aria-controls="bricks-ai-config-api">
								<span class="bricks-ai-config-tab-title">
									<span class="bricks-ai-config-tab-icon dashicons dashicons-admin-plugins" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Bricks abilities', 'bricks' ); ?></span>
								</span>

								<span class="bricks-ai-config-tab-meta">
									<span class="bricks-ai-config-status <?php echo esc_attr( $api_status['class'] ); ?>" data-ai-api-status<?php echo $api_status_live ? ' data-ai-api-status-live="1"' : ''; ?>>
									<span aria-hidden="true"></span>
									<span data-ai-api-status-text><?php echo esc_html( $api_status['label'] ); ?></span>
									</span>
									<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
								</span>
							</button>

							<div id="bricks-ai-config-api" class="bricks-ai-config-tab-body">
								<div class="bricks-ai-config-section">
									<p class="section-label"><?php esc_html_e( 'Enable Bricks abilities', 'bricks' ); ?></p>

									<div class="bricks-ai-config-intro">
										<p class="bricks-ai-config-description">
											<?php esc_html_e( 'Register Bricks abilities for this site. MCP clients, WP-CLI, and other clients can use the abilities you allow under the Abilities tab.', 'bricks' ); ?>
											<?php if ( $setup_guide_url ) : ?>
												<a href="<?php echo esc_url( $setup_guide_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more', 'bricks' ); ?></a>
											<?php endif; ?>
										</p>

										<label class="bricks-ai-master-toggle">
										<input type="checkbox" name="abilitiesApi" id="bricks-ai-abilities-api" class="bricks-ai-toggle" <?php checked( $mcp_master_enabled ); ?> <?php disabled( $mcp_disabled_by_const || ! $abilities_api_ready ); ?>>
										<span data-ai-master-toggle-label><?php echo esc_html( $mcp_master_enabled ? __( 'Enabled', 'bricks' ) : __( 'Disabled', 'bricks' ) ); ?></span>
									</label>
								</div>

								<?php if ( ! $abilities_api_ready ) : ?>
									<div class="bricks-ai-status-card is-error">
										<span class="dashicons dashicons-warning" aria-hidden="true"></span>
										<div>
											<h3><?php esc_html_e( 'WordPress Abilities API is not available', 'bricks' ); ?></h3>
											<p><?php esc_html_e( 'Bricks abilities cannot be enabled because this WordPress installation does not include the Abilities API. Upgrade to WordPress 6.9 or newer to use Bricks abilities.', 'bricks' ); ?></p>
										</div>
									</div>
								<?php elseif ( $mcp_disabled_by_const ) : ?>
									<div class="bricks-ai-status-card is-error">
										<span class="dashicons dashicons-warning" aria-hidden="true"></span>
										<div>
											<h3><?php esc_html_e( 'Bricks abilities are disabled by wp-config.php', 'bricks' ); ?></h3>
											<p>
												<?php
												echo wp_kses(
													__( 'The <code>BRICKS_DISABLE_MCP</code> constant is preventing Bricks from registering abilities. Remove the constant or set it to <code>false</code> to allow admin control.', 'bricks' ),
													[ 'code' => [] ]
												);
												?>
											</p>
										</div>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</section>

					<section class="bricks-ai-card bricks-ai-connect-card">
						<button type="button" class="bricks-ai-config-tab-header" data-ai-config-toggle aria-expanded="true" aria-controls="bricks-ai-config-connect">
							<span class="bricks-ai-config-tab-title">
								<span class="bricks-ai-config-tab-icon dashicons dashicons-admin-links" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Connect AI client to MCP', 'bricks' ); ?></span>
							</span>

							<span class="bricks-ai-config-tab-meta">
								<span class="bricks-ai-config-count" data-ai-connect-status><?php echo esc_html( ! $mcp_adapter_active ? __( 'MCP required', 'bricks' ) : ( $connect_ready ? __( 'Ready', 'bricks' ) : __( 'Pending', 'bricks' ) ) ); ?></span>
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
							</span>
						</button>

							<div id="bricks-ai-config-connect" class="bricks-ai-config-tab-body">
								<p class="bricks-ai-config-description">
									<?php esc_html_e( 'One way to use enabled Bricks abilities is to connect your AI client through an MCP server. The steps below use the WordPress MCP Adapter and an application password.', 'bricks' ); ?>
								</p>

								<div class="bricks-ai-connect-steps">
									<div class="bricks-ai-connect-step<?php echo $mcp_adapter_active ? ' is-complete' : ''; ?>">
										<div class="bricks-ai-connect-step-marker">
											<?php if ( $mcp_adapter_active ) : ?>
												<span class="dashicons dashicons-yes" aria-hidden="true"></span>
											<?php else : ?>
												1
											<?php endif; ?>
										</div>

										<div class="bricks-ai-connect-step-body">
											<h3><?php esc_html_e( 'Set up MCP server', 'bricks' ); ?></h3>
											<p class="bricks-ai-connect-step-description">
												<?php esc_html_e( 'Install or activate the WordPress MCP Adapter so MCP clients can reach this site.', 'bricks' ); ?>
											</p>

											<?php if ( $status_state === 'connected' ) : ?>
												<div class="bricks-ai-status-card is-success">
													<div>
														<h3><?php esc_html_e( 'MCP server connected', 'bricks' ); ?></h3>
														<p>
															<?php
															if ( $version ) {
																/* translators: 1: source label (e.g. "WordPress MCP Adapter"), 2: version */
																printf( esc_html__( 'Provided by %1$s v%2$s. MCP clients can connect to this endpoint.', 'bricks' ), esc_html( $source_label ), esc_html( $version ) );
															} else {
																/* translators: 1: source label (e.g. "WordPress MCP Adapter") */
																printf( esc_html__( 'Provided by %1$s. MCP clients can connect to this endpoint.', 'bricks' ), esc_html( $source_label ) );
															}
															?>
														</p>

														<?php if ( $endpoint_url ) : ?>
															<input type="text" class="bricks-ai-endpoint code" readonly onclick="this.select();" value="<?php echo esc_attr( $endpoint_url ); ?>">
														<?php endif; ?>
													</div>
												</div>
											<?php elseif ( $status_state === 'route_missing' ) : ?>
												<div class="bricks-ai-status-card is-error">
													<span class="dashicons dashicons-warning" aria-hidden="true"></span>
													<div>
														<h3><?php esc_html_e( 'MCP server enabled but endpoint did not register', 'bricks' ); ?></h3>
														<p><?php esc_html_e( 'The MCP source is loaded, but the REST route is missing. Another plugin may be filtering the route, or the adapter failed to bootstrap. Check your error log.', 'bricks' ); ?></p>
													</div>
												</div>
											<?php elseif ( $status_state === 'inactive' ) : ?>
												<div class="bricks-ai-status-card is-warning">
													<div>
														<h3><?php esc_html_e( 'WordPress MCP Adapter is installed but not active', 'bricks' ); ?></h3>
														<p><?php esc_html_e( 'Activate the plugin to expose Bricks abilities through MCP.', 'bricks' ); ?></p>
													</div>

													<?php if ( ( current_user_can( 'activate_plugins' ) && $plugin_file ) || $setup_guide_url ) : ?>
														<div class="bricks-ai-status-actions">
															<?php if ( current_user_can( 'activate_plugins' ) && $plugin_file ) : ?>
																<button type="button" class="bricks-ai-button bricks-ai-button-primary" data-activate-mcp-adapter><?php esc_html_e( 'Activate plugin', 'bricks' ); ?></button>
																<a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>" class="bricks-ai-button bricks-ai-button-secondary">
																	<span class="dashicons dashicons-external" aria-hidden="true"></span>
																	<?php esc_html_e( 'Open Plugins', 'bricks' ); ?>
																</a>
															<?php endif; ?>

															<?php if ( $setup_guide_url ) : ?>
																<a href="<?php echo esc_url( $setup_guide_url ); ?>" target="_blank" rel="noopener" class="bricks-ai-button bricks-ai-button-secondary">
																	<span class="dashicons dashicons-external" aria-hidden="true"></span>
																	<?php esc_html_e( 'Setup guide', 'bricks' ); ?>
																</a>
															<?php endif; ?>
														</div>
													<?php endif; ?>
												</div>
											<?php else : ?>
												<div class="bricks-ai-status-card is-warning">
													<div>
														<h3><?php esc_html_e( 'WordPress MCP Adapter is not installed', 'bricks' ); ?></h3>
														<p><?php echo esc_html__( 'Install the plugin to expose Bricks abilities through MCP.', 'bricks' ); ?></p>
													</div>

													<div class="bricks-ai-status-actions">
														<?php if ( current_user_can( 'install_plugins' ) ) : ?>
															<button type="button" class="bricks-ai-button bricks-ai-button-primary" data-install-mcp-adapter><?php esc_html_e( 'Install plugin', 'bricks' ); ?></button>
														<?php endif; ?>

														<a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener" class="bricks-ai-button bricks-ai-button-secondary">
															<span class="dashicons dashicons-external" aria-hidden="true"></span>
															<?php esc_html_e( 'View on GitHub', 'bricks' ); ?>
														</a>

														<?php if ( $setup_guide_url ) : ?>
															<a href="<?php echo esc_url( $setup_guide_url ); ?>" target="_blank" rel="noopener" class="bricks-ai-button bricks-ai-button-secondary">
																<span class="dashicons dashicons-external" aria-hidden="true"></span>
																<?php esc_html_e( 'Setup guide', 'bricks' ); ?>
															</a>
														<?php endif; ?>
													</div>
												</div>
											<?php endif; ?>

												<?php if ( $local_https ) : ?>
													<div class="bricks-ai-connect-prerequisites">
														<div class="bricks-ai-connect-prerequisite">
															<span class="bricks-ai-connect-prerequisite-icon is-success dashicons dashicons-shield" aria-hidden="true"></span>
															<div>
																<h4><?php esc_html_e( 'Local HTTPS detected', 'bricks' ); ?></h4>
																<p><?php esc_html_e( 'A scoped TLS override is added so local clients can connect.', 'bricks' ); ?></p>
															</div>
														</div>
													</div>
												<?php endif; ?>
										</div>
									</div>

									<div class="bricks-ai-connect-step<?php echo $app_password_count > 0 ? ' is-complete' : ''; ?>" data-ai-credential-step>
										<div class="bricks-ai-connect-step-marker" data-ai-credential-step-marker>
											<?php if ( $app_password_count > 0 ) : ?>
												<span class="dashicons dashicons-yes" aria-hidden="true"></span>
											<?php else : ?>
												2
											<?php endif; ?>
										</div>

										<div class="bricks-ai-connect-step-body">
											<h3>
												<?php esc_html_e( 'Create a credential', 'bricks' ); ?>
												<span class="bricks-ai-step-meta" data-ai-credential-count>
													<?php
													/* translators: %d: number of active credentials */
													printf( esc_html( _n( '%d active', '%d active', $app_password_count, 'bricks' ) ), (int) $app_password_count );
													?>
												</span>
											</h3>
											<p class="bricks-ai-connect-step-description">
												<?php esc_html_e( 'The AI client will act as this WordPress user, so WordPress capabilities and Bricks permissions still apply.', 'bricks' ); ?>
											</p>

											<div class="bricks-ai-connect-prerequisites">
												<div class="bricks-ai-connect-prerequisite<?php echo $app_password_count > 0 ? ' is-complete' : ''; ?>" data-ai-connect-password-warning>
													<span class="bricks-ai-connect-prerequisite-icon is-warning dashicons dashicons-admin-network" aria-hidden="true"></span>
													<div>
														<h4 data-ai-connect-password-title><?php echo esc_html( $app_password_count > 0 ? __( 'Application password ready', 'bricks' ) : __( 'Application password needed', 'bricks' ) ); ?></h4>
														<p data-ai-connect-password-description><?php echo esc_html( $app_password_count > 0 ? __( 'Use the selected user application password in the setup details below.', 'bricks' ) : __( 'Use an existing application password, or generate one below.', 'bricks' ) ); ?></p>
													</div>

													<button type="button" class="button bricks-ai-connect-generate" data-ai-connect-generate<?php echo ( $app_password_count > 0 || ! $can_create_ai_credential ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Generate', 'bricks' ); ?></button>
												</div>
											</div>

											<div id="bricks-ai-config-credentials" class="bricks-ai-credential-fields">
												<?php if ( $can_create_ai_credential ) : ?>
													<div class="bricks-ai-field">
														<label><?php esc_html_e( 'WordPress user', 'bricks' ); ?></label>
														<select id="bricksAiCredentialUser" class="regular-text" data-ai-application-password-user>
															<?php foreach ( $credential_users as $credential_user ) : ?>
																<option
																	value="<?php echo esc_attr( $credential_user['id'] ); ?>"
																	data-profile-url="<?php echo esc_url( $credential_user['url'] ); ?>"
																	data-login="<?php echo esc_attr( $credential_user['login'] ); ?>"
																	data-count="<?php echo esc_attr( count( $credential_user['passwords'] ) ); ?>"
																	<?php selected( (int) $credential_user['id'], (int) $selected_credential_user['id'] ); ?>
																>
																	<?php
																	printf(
																		/* translators: 1: user display name, 2: user login */
																		esc_html__( '%1$s (%2$s)', 'bricks' ),
																		esc_html( $credential_user['label'] ),
																		esc_html( $credential_user['login'] )
																	);
																	?>
																</option>
															<?php endforeach; ?>
														</select>
														<small><?php esc_html_e( 'Only users you can create application passwords for are shown. If you cannot manage other users, use your own account.', 'bricks' ); ?></small>
													</div>

													<div class="bricks-ai-field">
														<label><?php esc_html_e( 'Credential name', 'bricks' ); ?></label>
														<input type="text" class="code" data-ai-application-password-name required placeholder="<?php echo esc_attr( 'Claude, Codex, Cursor' ); ?>">
														<small><?php esc_html_e( 'Use a name that identifies the client, device, or workflow using this credential.', 'bricks' ); ?></small>
													</div>

													<div class="bricks-ai-field">
														<label><?php esc_html_e( 'Application password', 'bricks' ); ?></label>
														<button type="button" class="button button-primary bricks-ai-application-password-generate" data-ai-application-password-generate>
															<?php esc_html_e( 'Generate password', 'bricks' ); ?>
														</button>
														<input type="text" class="code" data-ai-generated-password placeholder="<?php esc_attr_e( 'Application password', 'bricks' ); ?>" hidden>
														<div class="bricks-ai-password-info" data-ai-password-info hidden>
															<p><?php esc_html_e( 'Connect your AI client using the prompt or configuration details below. Copy the password before leaving this page. It will not show again.', 'bricks' ); ?></p>
														</div>
													</div>

													<div class="bricks-ai-application-passwords-list"<?php echo empty( $app_passwords ) ? ' hidden' : ''; ?>>
														<h3><?php esc_html_e( 'Application passwords', 'bricks' ); ?></h3>

														<?php foreach ( $credential_users as $credential_user ) : ?>
															<table data-ai-application-password-table="<?php echo esc_attr( $credential_user['id'] ); ?>" data-loaded="1"<?php echo (int) $credential_user['id'] === (int) $selected_credential_user['id'] ? '' : ' hidden'; ?>>
																<thead>
																	<tr>
																		<th><?php esc_html_e( 'Name', 'bricks' ); ?></th>
																		<th><?php esc_html_e( 'Created', 'bricks' ); ?></th>
																		<th><?php esc_html_e( 'Last used', 'bricks' ); ?></th>
																		<th><?php esc_html_e( 'Actions', 'bricks' ); ?></th>
																	</tr>
																</thead>
																<tbody>
																	<?php if ( $credential_user['passwords'] ) : ?>
																				<?php foreach ( $credential_user['passwords'] as $password ) : ?>
																					<tr>
																						<td class="bricks-ai-password-name"><strong><?php echo esc_html( $password['name'] ); ?></strong></td>
																						<td class="bricks-ai-password-meta" data-label="<?php esc_attr_e( 'Created', 'bricks' ); ?>"><?php echo esc_html( date_i18n( get_option( 'date_format' ), $password['created'] ) ); ?></td>
																						<td class="bricks-ai-password-meta" data-label="<?php esc_attr_e( 'Last used', 'bricks' ); ?>">
																							<?php
																							echo empty( $password['last_used'] )
																								? esc_html__( 'Never', 'bricks' )
																								: esc_html( date_i18n( get_option( 'date_format' ), $password['last_used'] ) );
																							?>
																						</td>
																						<td class="bricks-ai-password-action"><a href="<?php echo esc_url( $credential_user['url'] ); ?>"><?php esc_html_e( 'Manage', 'bricks' ); ?></a></td>
																					</tr>
																		<?php endforeach; ?>
																	<?php else : ?>
																		<tr>
																			<td colspan="4"><?php esc_html_e( 'No application passwords yet.', 'bricks' ); ?></td>
																		</tr>
																	<?php endif; ?>
																</tbody>
															</table>
														<?php endforeach; ?>
													</div>
												<?php elseif ( $app_passwords_require_https ) : ?>
													<p class="bricks-ai-empty"><?php esc_html_e( 'Application passwords require HTTPS. For a local HTTP site, add define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php.', 'bricks' ); ?></p>
												<?php elseif ( $app_passwords_ok && $app_passwords_available ) : ?>
													<p class="bricks-ai-empty"><?php esc_html_e( 'You do not have permission to create application passwords for any WordPress user.', 'bricks' ); ?></p>
												<?php else : ?>
													<p class="bricks-ai-empty"><?php esc_html_e( 'Application passwords are not available for this user or site.', 'bricks' ); ?></p>
												<?php endif; ?>
											</div>
										</div>
									</div>

									<div class="bricks-ai-connect-step">
										<div class="bricks-ai-connect-step-marker">3</div>

										<div class="bricks-ai-connect-step-body">
											<h3><?php esc_html_e( 'Choose how to connect', 'bricks' ); ?></h3>
										<p class="bricks-ai-connect-step-description">
											<?php esc_html_e( 'Use a config file when supported to keep credentials out of prompts.', 'bricks' ); ?>
										</p>

										<div class="bricks-ai-connect-methods" role="tablist" aria-label="<?php esc_attr_e( 'Connection method', 'bricks' ); ?>">
											<button type="button" class="is-active" data-ai-connect-method="config" aria-selected="true">
												<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
												<?php esc_html_e( 'Paste config', 'bricks' ); ?>
											</button>

											<button type="button" data-ai-connect-method="prompt" aria-selected="false">
												<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
												<?php esc_html_e( 'Copy a prompt', 'bricks' ); ?>
											</button>
										</div>

										<div class="bricks-ai-connect-method-panel" data-ai-connect-panel="prompt" hidden>
											<textarea class="bricks-ai-connect-prompt code" readonly data-ai-connect-prompt></textarea>

											<button type="button" class="button button-primary bricks-ai-connect-copy-prompt" data-ai-connect-copy>
												<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
												<?php esc_html_e( 'Copy prompt', 'bricks' ); ?>
											</button>
										</div>

										<div id="bricks-ai-manual-config" class="bricks-ai-connect-method-panel bricks-ai-manual-config-panel" data-ai-connect-panel="config">
											<p class="bricks-ai-config-description">
												<?php echo esc_html( $manual_config_text['description'] ); ?>
											</p>

											<div class="bricks-ai-manual-config-toolbar">
												<label class="bricks-ai-manual-client-select">
													<select data-ai-manual-client>
														<?php foreach ( $manual_config_clients as $client_key => $client_config ) : ?>
															<option value="<?php echo esc_attr( $client_key ); ?>" <?php selected( $client_key, $manual_config_default_client ); ?>>
																<?php echo esc_html( $client_config['label'] ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</label>

											</div>

											<div class="bricks-ai-manual-config-content">
												<div class="bricks-ai-manual-config-block">
													<pre data-ai-manual-config-code></pre>
													<button type="button" class="bricks-ai-manual-config-copy" data-ai-manual-config-copy>
														<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
														<?php echo esc_html( $manual_config_text['copy'] ); ?>
													</button>
												</div>

												<div class="bricks-ai-manual-config-footer">
													<div data-ai-manual-config-hint></div>
													<div data-ai-manual-config-paths></div>
												</div>
											</div>
										</div>
										</div>
									</div>

									<div class="bricks-ai-connect-step">
										<div class="bricks-ai-connect-step-marker">4</div>

										<div class="bricks-ai-connect-step-body">
											<h3><?php esc_html_e( 'Test the connection', 'bricks' ); ?></h3>
										<p class="bricks-ai-connect-step-description">
											<?php esc_html_e( 'Start a new AI chat so the client reloads the available Bricks abilities, then ask it to list them. If it cannot, the client is not connected to this site yet.', 'bricks' ); ?>
										</p>

										<ul class="bricks-ai-skills-readiness">
											<?php foreach ( $skills_readiness_items as $readiness_item ) : ?>
												<li class="is-<?php echo esc_attr( $readiness_item['state'] ); ?>" data-ai-readiness-item="<?php echo esc_attr( $readiness_item['key'] ); ?>">
													<span class="dashicons <?php echo esc_attr( $readiness_item['state'] === 'ready' ? 'dashicons-yes-alt' : 'dashicons-marker' ); ?>" aria-hidden="true"></span>
													<span><?php echo esc_html( $readiness_item['label'] ); ?></span>
												</li>
											<?php endforeach; ?>
											</ul>
										</div>
									</div>
								</div>

								<div class="bricks-ai-connect-next-step">
									<span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
									<div>
										<h3><?php esc_html_e( 'Optional: install Bricks skills', 'bricks' ); ?></h3>
										<p><?php esc_html_e( 'After MCP works, install Bricks skills so your client has better guidance for Bricks workflows.', 'bricks' ); ?></p>
									</div>
									<button type="button" class="button" data-ai-goto-skills><?php esc_html_e( 'Go to Skills', 'bricks' ); ?></button>
								</div>
							</div>
						</section>
					</div>
			</div>
		</div>

		<div id="bricks-ai-tab-abilities" class="bricks-ai-tab-panel" data-ai-tab-panel="abilities" hidden>
			<section class="bricks-ai-card bricks-ai-abilities-card">
				<div class="bricks-ai-config-tab-header bricks-ai-static-tab-header">
					<span class="bricks-ai-config-tab-title">
						<span class="bricks-ai-config-tab-icon dashicons dashicons-menu-alt" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Abilities', 'bricks' ); ?></span>
					</span>
				</div>

				<div class="bricks-ai-config-tab-body bricks-ai-abilities-body">
					<?php // Why abilities are locked + where to unlock them (shown while the master toggle is off; see syncMasterState) ?>
					<p class="bricks-ai-abilities-disabled-note" data-ai-abilities-disabled-note hidden>
						<?php if ( $mcp_disabled_by_const ) : ?>
							<?php esc_html_e( 'Bricks abilities cannot be enabled: the BRICKS_DISABLE_MCP constant disables them on this site.', 'bricks' ); ?>
						<?php elseif ( ! $abilities_api_ready ) : ?>
							<?php esc_html_e( 'Bricks abilities cannot be enabled because this WordPress installation does not include the Abilities API. Upgrade to WordPress 6.9 or newer, then return to', 'bricks' ); ?>
							<button type="button" class="button-link" data-ai-goto-abilities-api><?php esc_html_e( 'Configuration', 'bricks' ); ?></button>.
						<?php else : ?>
							<?php esc_html_e( 'Bricks abilities are turned off. Enable them on the', 'bricks' ); ?>
							<button type="button" class="button-link" data-ai-goto-abilities-api><?php esc_html_e( 'Configuration', 'bricks' ); ?></button>
							<?php esc_html_e( 'tab before choosing individual abilities.', 'bricks' ); ?>
						<?php endif; ?>
					</p>

					<div class="bricks-ai-abilities-toolbar">
						<p class="bricks-ai-config-description">
							<?php esc_html_e( 'Choose which Bricks abilities are available on this site. Enabled abilities are still limited by the authenticated user\'s WordPress capabilities and Bricks permissions.', 'bricks' ); ?>
						</p>

						<div class="bricks-ai-panel-actions">
							<button type="button" class="button-link" data-bulk-action="enable"><?php esc_html_e( 'Enable all', 'bricks' ); ?></button>
							<button type="button" class="button-link" data-bulk-action="disable"><?php esc_html_e( 'Disable all', 'bricks' ); ?></button>
							<button type="button" class="button-link" data-bulk-action="reset">
								<span class="dashicons dashicons-undo" aria-hidden="true"></span>
								<?php esc_html_e( 'Reset to defaults', 'bricks' ); ?>
							</button>
						</div>
					</div>

					<?php if ( empty( $ability_rows ) ) : ?>
						<p class="bricks-ai-empty"><?php esc_html_e( 'Abilities appear here as soon as they register at runtime.', 'bricks' ); ?></p>
					<?php else : ?>
						<div class="bricks-ai-ability-manager">
							<div class="bricks-ai-sidebar">
								<label class="bricks-ai-search">
									<span class="dashicons dashicons-search" aria-hidden="true"></span>
									<input type="search" placeholder="<?php esc_attr_e( 'Search abilities', 'bricks' ); ?>" spellcheck="false" data-ability-search>
								</label>

								<nav class="bricks-ai-category-nav" aria-label="<?php esc_attr_e( 'Ability categories', 'bricks' ); ?>">
									<button type="button" class="is-active" data-category-filter="all">
										<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
										<span><?php esc_html_e( 'All abilities', 'bricks' ); ?></span>
										<small>
											<span data-enabled-count><?php echo esc_html( $enabled_count ); ?></span>/<?php echo esc_html( $visible_total_count ); ?>
										</small>
									</button>

									<?php foreach ( $grouped_abilities as $category => $abilities_in_category ) : ?>
										<?php $icon = $category_icons[ $category ] ?? 'dashicons-admin-generic'; ?>
										<button type="button" data-category-filter="<?php echo esc_attr( $category ); ?>">
											<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
											<span><?php echo esc_html( $category_label( $category ) ); ?></span>
											<small><?php echo esc_html( $category_counts[ $category ]['enabled'] ); ?>/<?php echo esc_html( $category_counts[ $category ]['total'] ); ?></small>
										</button>
									<?php endforeach; ?>
								</nav>

						</div>

						<div class="bricks-ai-ability-content">
							<div class="bricks-ai-content-toolbar">
								<div class="bricks-ai-status-filters" role="group" aria-label="<?php esc_attr_e( 'Ability filters', 'bricks' ); ?>">
									<button type="button" class="is-active" data-status-filter="all"><?php esc_html_e( 'All', 'bricks' ); ?></button>
									<button type="button" data-status-filter="enabled"><?php esc_html_e( 'Enabled', 'bricks' ); ?></button>
									<button type="button" data-status-filter="disabled"><?php esc_html_e( 'Disabled', 'bricks' ); ?></button>
									<button type="button" data-status-filter="default-off"><?php esc_html_e( 'Default off', 'bricks' ); ?></button>
								</div>

								<span class="bricks-ai-shown-count">
									<span data-visible-count><?php echo esc_html( $visible_total_count ); ?></span>
									<?php esc_html_e( 'shown', 'bricks' ); ?>
								</span>
							</div>

							<div class="bricks-ai-ability-groups">
								<?php foreach ( $grouped_abilities as $category => $abilities_in_category ) : ?>
									<?php $icon = $category_icons[ $category ] ?? 'dashicons-admin-generic'; ?>
									<section class="bricks-ai-ability-group" data-category="<?php echo esc_attr( $category ); ?>">
										<h3>
											<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
											<?php echo esc_html( $category_label( $category ) ); ?>
										</h3>

										<ul>
											<?php foreach ( $abilities_in_category as $ability ) : ?>
												<?php
												$state              = $ability_states[ $ability['name'] ];
												$default_enabled    = $state['default_enabled'];
												$is_always_on       = $state['is_always_on'];
													$is_enabled     = $state['is_enabled'];
													$is_managed     = $state['is_managed'];
													$is_execute_php = $ability['name'] === \Bricks\Abilities\Execute_Php::ABILITY_NAME;
												$input_id           = 'bricks-ai-ability-' . sanitize_html_class( $ability['name'] );
												$search_text        = strtolower( $ability['name'] . ' ' . ( $ability['label'] ?? '' ) . ' ' . ( $ability['description'] ?? '' ) );
												?>
												<li
													class="bricks-ai-ability-row<?php echo $is_always_on ? ' is-always-on' : ''; ?><?php echo $is_execute_php ? ' is-execute-php' : ''; ?><?php echo $is_execute_php && $execute_php_configured ? ' is-configured' : ''; ?><?php echo $is_execute_php && $execute_php_constant === 'invalid' ? ' has-invalid-constant' : ''; ?>"
													data-ability-name="<?php echo esc_attr( $ability['name'] ); ?>"
													data-search="<?php echo esc_attr( $search_text ); ?>"
													data-category="<?php echo esc_attr( $category ); ?>"
													data-default-enabled="<?php echo $default_enabled ? '1' : '0'; ?>"
													data-always-on="<?php echo $is_always_on ? '1' : '0'; ?>"
													data-managed-externally="<?php echo $is_managed ? '1' : '0'; ?>"
													data-current-enabled="<?php echo $is_enabled ? '1' : '0'; ?>"
												>
													<?php if ( $is_managed ) : ?>
														<span class="bricks-ai-ability-managed-icon dashicons dashicons-lock" aria-hidden="true"></span>
													<?php else : ?>
														<label class="bricks-ai-ability-toggle" for="<?php echo esc_attr( $input_id ); ?>">
														<input
															type="checkbox"
															id="<?php echo esc_attr( $input_id ); ?>"
															name="bricksMcpEnabledAbilities[]"
															value="<?php echo esc_attr( $ability['name'] ); ?>"
															class="bricks-ai-checkbox"
															<?php checked( $is_enabled ); ?>
															<?php disabled( $is_always_on || ! $mcp_master_enabled || $mcp_disabled_by_const || ! $abilities_api_ready ); ?>
														>
														<span class="screen-reader-text"><?php echo esc_html( $ability['name'] ); ?></span>
														</label>
													<?php endif; ?>

													<div class="bricks-ai-ability-body">
														<p class="bricks-ai-ability-name">
															<code>
																<?php if ( strpos( $ability['name'], 'bricks/' ) === 0 ) : ?>
																	<span class="bricks-ai-ability-name-prefix">bricks/</span><?php echo esc_html( substr( $ability['name'], 7 ) ); ?>
																<?php else : ?>
																	<?php echo esc_html( $ability['name'] ); ?>
																<?php endif; ?>
															</code>

															<?php if ( ! $default_enabled && ! $is_always_on && ! $is_execute_php ) : ?>
																<span class="bricks-ai-badge bricks-ai-badge-default-off"><?php esc_html_e( 'Default off', 'bricks' ); ?></span>
															<?php endif; ?>

															<?php if ( ! empty( $ability['destructive'] ) ) : ?>
																<span class="bricks-ai-badge bricks-ai-badge-destructive"><?php esc_html_e( 'Destructive', 'bricks' ); ?></span>
															<?php endif; ?>

															<?php if ( $is_execute_php ) : ?>
																<span class="bricks-ai-config-status <?php echo esc_attr( $execute_php_display_status['class'] ); ?>">
																	<span aria-hidden="true"></span>
																	<span><?php echo esc_html( $execute_php_display_status['label'] ); ?></span>
																</span>
																<button type="button" class="button-link" data-ai-goto-execute-php aria-expanded="false" aria-controls="bricks-ai-config-execute-php"><?php esc_html_e( 'View setup', 'bricks' ); ?></button>
															<?php endif; ?>
														</p>

														<?php if ( ! empty( $ability['description'] ) ) : ?>
															<p class="bricks-ai-ability-description"><?php echo $format_description( $ability['description'] ); ?></p>
														<?php endif; ?>
													</div>
												</li>
											<?php endforeach; ?>
										</ul>
									</section>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
				</div>
			</section>

			<template id="bricks-ai-execute-php-template">
				<div id="bricks-ai-config-execute-php" class="bricks-ai-config-tab-body" hidden>
					<?php if ( $execute_php_configured && $abilities_api_ready && $execute_php_state['abilitiesEnabled'] && ! $execute_php_available ) : ?>
						<div class="bricks-ai-status-card is-warning">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<div>
								<h3><?php esc_html_e( 'Additional access required', 'bricks' ); ?></h3>
								<p>
									<?php if ( $execute_php_state['signaturesLocked'] ) : ?>
										<?php esc_html_e( 'BRICKS_LOCK_CODE_SIGNATURES is enabled and overrides the PHP execution constant.', 'bricks' ); ?>
									<?php elseif ( ! $execute_php_state['codeExecution'] ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=bricks-settings#tab-custom-code' ) ); ?>"><?php esc_html_e( 'Enable Bricks code execution to use this ability.', 'bricks' ); ?></a>
									<?php elseif ( ! $execute_php_state['canManageOptions'] ) : ?>
										<?php esc_html_e( 'Your account needs the WordPress manage_options capability.', 'bricks' ); ?>
									<?php elseif ( ! $execute_php_state['canExecuteCode'] ) : ?>
										<?php esc_html_e( 'Grant your current account the Bricks Execute code capability.', 'bricks' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Application Passwords are unavailable for your current account.', 'bricks' ); ?>
									<?php endif; ?>
								</p>
							</div>
						</div>
					<?php elseif ( $execute_php_constant === 'invalid' ) : ?>
						<div class="bricks-ai-status-card is-error">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<div>
								<h3><?php esc_html_e( 'The constant value is invalid', 'bricks' ); ?></h3>
								<p><?php esc_html_e( 'Use the boolean value true to enable this ability or false to keep it disabled. Strings and numbers do not enable it.', 'bricks' ); ?></p>
							</div>
						</div>
					<?php endif; ?>

					<div class="bricks-ai-execute-php-setup">
						<p><?php esc_html_e( 'This constant enables PHP execution and PHP authoring through abilities. Both require application-password authentication, the required capabilities, enabled code execution, and unlocked code signatures.', 'bricks' ); ?></p>
						<h3><?php echo esc_html( $execute_php_configured ? __( 'Disable in PHP configuration', 'bricks' ) : __( 'Enable in PHP configuration', 'bricks' ) ); ?></h3>
						<p>
							<?php
							if ( $execute_php_configured ) {
								echo wp_kses(
									__( 'Remove <code>BRICKS_ENABLE_PHP_ABILITIES</code> from the PHP file where it is defined, or set it to <code>false</code>. The change takes effect on the next request.', 'bricks' ),
									[ 'code' => [] ]
								);
							} elseif ( $execute_php_constant === 'false' ) {
								echo wp_kses(
									__( 'Change the existing <code>BRICKS_ENABLE_PHP_ABILITIES</code> definition from <code>false</code> to <code>true</code>. PHP constants cannot be redefined later in the request.', 'bricks' ),
									[ 'code' => [] ]
								);
							} elseif ( $execute_php_constant === 'invalid' ) {
								echo wp_kses(
									__( 'Replace the existing <code>BRICKS_ENABLE_PHP_ABILITIES</code> value with the boolean <code>true</code>. PHP constants cannot be redefined later in the request.', 'bricks' ),
									[ 'code' => [] ]
								);
							} else {
								echo wp_kses(
									__( 'Set <code>BRICKS_ENABLE_PHP_ABILITIES</code> to <code>true</code> using either option below.', 'bricks' ),
									[ 'code' => [] ]
								);
							}
							?>
						</p>
						<?php if ( $execute_php_constant === 'undefined' ) : ?>
							<div class="bricks-ai-execute-php-examples">
								<div class="bricks-ai-execute-php-example">
									<strong><?php esc_html_e( 'wp-config.php', 'bricks' ); ?></strong>
									<div class="bricks-ai-execute-php-code">
										<pre><code>define( 'BRICKS_ENABLE_PHP_ABILITIES', true );</code></pre>
										<button type="button" class="button" data-execute-php-copy><?php esc_html_e( 'Copy', 'bricks' ); ?></button>
									</div>
								</div>

								<div class="bricks-ai-execute-php-example">
									<strong><?php esc_html_e( 'Child theme functions.php or plugin', 'bricks' ); ?></strong>
									<div class="bricks-ai-execute-php-code">
										<pre><code>if ( ! defined( 'BRICKS_ENABLE_PHP_ABILITIES' ) ) {
	define( 'BRICKS_ENABLE_PHP_ABILITIES', true );
}</code></pre>
										<button type="button" class="button" data-execute-php-copy><?php esc_html_e( 'Copy', 'bricks' ); ?></button>
									</div>
								</div>
							</div>
						<?php endif; ?>
						<p class="bricks-ai-execute-php-recommendation">
							<strong><?php esc_html_e( 'Recommended:', 'bricks' ); ?></strong>
							<?php esc_html_e( 'Enable this only on local, staging, or otherwise trusted development environments, and disable it when it is no longer needed.', 'bricks' ); ?>
						</p>
					</div>
				</div>
			</template>
		</div>

		<div id="bricks-ai-tab-skills" class="bricks-ai-tab-panel" data-ai-tab-panel="skills" hidden>
			<section class="bricks-ai-card bricks-ai-skills-card">
				<div class="bricks-ai-config-tab-header bricks-ai-static-tab-header">
					<span class="bricks-ai-config-tab-title">
						<span class="bricks-ai-config-tab-icon dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Skills', 'bricks' ); ?></span>
					</span>
				</div>

				<div class="bricks-ai-config-tab-body">
					<p class="bricks-ai-config-description">
						<?php esc_html_e( 'Install the Bricks skills package in your AI client. Skills are client-side instructions that help Claude, Codex, and other clients work with Bricks more effectively.', 'bricks' ); ?>
					</p>

					<div class="bricks-ai-connect-steps bricks-ai-skills-steps">
						<div class="bricks-ai-connect-step">
							<div class="bricks-ai-connect-step-marker">1</div>

							<div class="bricks-ai-connect-step-body">
								<h3><?php esc_html_e( 'Copy the install prompt', 'bricks' ); ?></h3>
								<textarea id="bricks-ai-client-skills-prompt" class="bricks-ai-connect-prompt bricks-ai-client-skills-prompt code" readonly data-ai-skills-prompt></textarea>

								<button type="button" class="button button-primary bricks-ai-connect-copy-prompt" data-ai-skills-copy>
									<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
									<?php echo esc_html( $skills_prompt_text['copy'] ); ?>
								</button>
							</div>
						</div>

						<div class="bricks-ai-connect-step">
							<div class="bricks-ai-connect-step-marker">2</div>

							<div class="bricks-ai-connect-step-body">
								<h3>
										<?php esc_html_e( 'Paste the prompt into your AI client', 'bricks' ); ?>
										<span><?php esc_html_e( 'for example Claude or Codex', 'bricks' ); ?></span>
								</h3>

								<div class="bricks-ai-connect-prerequisites bricks-ai-skills-locations">
									<div class="bricks-ai-connect-prerequisite">
										<span class="bricks-ai-connect-prerequisite-icon is-success dashicons dashicons-category" aria-hidden="true"></span>
										<div>
												<h4><?php esc_html_e( 'Client confirmation', 'bricks' ); ?></h4>
												<p><?php esc_html_e( 'The client should confirm which Bricks skills were installed and whether you need to restart or start a new chat.', 'bricks' ); ?></p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="bricks-ai-connect-step">
							<div class="bricks-ai-connect-step-marker">3</div>

							<div class="bricks-ai-connect-step-body">
								<h3><?php esc_html_e( 'Verify it worked', 'bricks' ); ?></h3>
								<p class="bricks-ai-connect-step-description">
									<?php esc_html_e( 'Start a new chat and ask the client to list loaded Bricks skills.', 'bricks' ); ?>
								</p>

								<textarea id="bricks-ai-client-skills-verify-prompt" class="bricks-ai-connect-prompt bricks-ai-client-skills-prompt bricks-ai-client-skills-verify-prompt code" readonly data-ai-skills-verify-prompt></textarea>

								<button type="button" class="button button-primary bricks-ai-connect-copy-prompt" data-ai-skills-verify-copy>
									<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
									<?php echo esc_html( $skills_verify_prompt_text['copy'] ); ?>
								</button>

							</div>
						</div>
					</div>
				</div>
			</section>
		</div>

		<div class="bricks-ai-save-bar">
			<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'bricks' ); ?></button>
			<p class="bricks-ai-autosave-status" data-ai-autosave-status role="status" aria-live="polite"></p>
		</div>
	</form>
</div>

<script>
	(function () {
		function initBricksAiSettings() {
			var form = document.getElementById('bricks-ai-settings')
			var wrapper = document.querySelector('.bricks-admin-wrapper.ai')

			if (!form || !window.bricksData || !window.jQuery) {
				return
			}

			var tabButtons = wrapper ? wrapper.querySelectorAll('[data-ai-tab]') : []
			var tabPanels = form.querySelectorAll('[data-ai-tab-panel]')
			var configToggles = form.querySelectorAll('[data-ai-config-toggle]')
			var masterToggle = form.querySelector('#bricks-ai-abilities-api')
			var searchInput = form.querySelector('[data-ability-search]')
			var statusFilters = form.querySelectorAll('[data-status-filter]')
			var categoryNav = form.querySelector('.bricks-ai-category-nav')
			var categoryFilters = form.querySelectorAll('[data-category-filter]')
			var abilityRows = form.querySelectorAll('.bricks-ai-ability-row')
			var abilityGroups = form.querySelectorAll('.bricks-ai-ability-group')
			var masterToggleLabel = form.querySelector('[data-ai-master-toggle-label]')
			var installMcpAdapterButton = form.querySelector('[data-install-mcp-adapter]')
			var activateMcpAdapterButton = form.querySelector('[data-activate-mcp-adapter]')
			var appPasswordButton = form.querySelector('[data-ai-application-password-generate]')
			var appPasswordName = form.querySelector('[data-ai-application-password-name]')
			var appPasswordUser = form.querySelector('[data-ai-application-password-user]')
			var appPasswordResultInput = form.querySelector('[data-ai-generated-password]')
			var appPasswordInfo = form.querySelector('[data-ai-password-info]')
			var appPasswordList = form.querySelector('.bricks-ai-application-passwords-list')
			var credentialCount = form.querySelector('[data-ai-credential-count]')
			var connectPrompt = form.querySelector('[data-ai-connect-prompt]')
			var connectCopyButton = form.querySelector('[data-ai-connect-copy]')
			var connectPasswordWarning = form.querySelector('[data-ai-connect-password-warning]')
			var connectPasswordTitle = form.querySelector('[data-ai-connect-password-title]')
				var connectPasswordDescription = form.querySelector('[data-ai-connect-password-description]')
				var connectStatus = form.querySelector('[data-ai-connect-status]')
				var connectGenerateButton = form.querySelector('[data-ai-connect-generate]')
				var credentialStep = form.querySelector('[data-ai-credential-step]')
				var credentialStepMarker = form.querySelector('[data-ai-credential-step-marker]')
				var connectMethodButtons = form.querySelectorAll('[data-ai-connect-method]')
				var connectMethodPanels = form.querySelectorAll('[data-ai-connect-panel]')
				var manualClientSelect = form.querySelector('[data-ai-manual-client]')
			var manualConfigCode = form.querySelector('[data-ai-manual-config-code]')
			var manualConfigCopyButton = form.querySelector('[data-ai-manual-config-copy]')
			var manualConfigHint = form.querySelector('[data-ai-manual-config-hint]')
			var manualConfigPaths = form.querySelector('[data-ai-manual-config-paths]')
			var skillsPrompt = form.querySelector('[data-ai-skills-prompt]')
			var skillsPromptCopyButton = form.querySelector('[data-ai-skills-copy]')
			var skillsVerifyPrompt = form.querySelector('[data-ai-skills-verify-prompt]')
			var skillsVerifyPromptCopyButton = form.querySelector('[data-ai-skills-verify-copy]')
			var readinessItems = form.querySelectorAll('[data-ai-readiness-item]')
			var visibleCount = form.querySelector('[data-visible-count]')
			var enabledCounts = form.querySelectorAll('[data-enabled-count]')
			var autosaveStatus = form.querySelector('[data-ai-autosave-status]')
			var abilitiesDisabledNote = form.querySelector('[data-ai-abilities-disabled-note]')
			var apiStatusChip = wrapper ? wrapper.querySelector('[data-ai-api-status]') : null
			var apiStatusText = wrapper ? wrapper.querySelector('[data-ai-api-status-text]') : null
			var gotoAbilitiesApiButton = form.querySelector('[data-ai-goto-abilities-api]')
			var gotoExecutePhpButton = form.querySelector('[data-ai-goto-execute-php]')
			var executePhpTemplate = form.querySelector('#bricks-ai-execute-php-template')
			var executePhpRow = form.querySelector('[data-ability-name="bricks/execute-php"]')
			var executePhpPanel = executePhpTemplate ? executePhpTemplate.content.querySelector('.bricks-ai-config-tab-body') : null
			var executePhpCopyButtons = executePhpPanel ? executePhpPanel.querySelectorAll('[data-execute-php-copy]') : []
			var gotoSkillsButton = form.querySelector('[data-ai-goto-skills]')
			var saveErrorMessage = <?php echo wp_json_encode( esc_html__( 'Unable to save AI settings.', 'bricks' ) ); ?>;
			var saveSuccessMessage = <?php echo wp_json_encode( esc_html__( 'Settings saved', 'bricks' ) ); ?>;
			var savingMessage = <?php echo wp_json_encode( esc_html__( 'Saving', 'bricks' ) ); ?>;
			var unsavedMessage = <?php echo wp_json_encode( esc_html__( 'Unsaved changes', 'bricks' ) ); ?>;
			var enabledText = <?php echo wp_json_encode( esc_html__( 'Enabled', 'bricks' ) ); ?>;
			var disabledText = <?php echo wp_json_encode( esc_html__( 'Disabled', 'bricks' ) ); ?>;
			var installMcpAdapterErrorMessage = <?php echo wp_json_encode( esc_html__( 'Unable to install WordPress MCP Adapter.', 'bricks' ) ); ?>;
			var activateMcpAdapterErrorMessage = <?php echo wp_json_encode( esc_html__( 'Unable to activate WordPress MCP Adapter.', 'bricks' ) ); ?>;
			var manualSetupUrlText = <?php echo wp_json_encode( esc_html__( 'Manual setup guide', 'bricks' ) ); ?>;
			var manualSetupUrl = <?php echo wp_json_encode( esc_url( $setup_guide_url ) ); ?>;
			var appPasswordErrorMessage = <?php echo wp_json_encode( esc_html__( 'Unable to generate application password.', 'bricks' ) ); ?>;
			var appPasswordSelectUserMessage = <?php echo wp_json_encode( esc_html__( 'Select a WordPress user.', 'bricks' ) ); ?>;
			var appPasswordTableNameText = <?php echo wp_json_encode( esc_html__( 'Name', 'bricks' ) ); ?>;
			var appPasswordTableCreatedText = <?php echo wp_json_encode( esc_html__( 'Created', 'bricks' ) ); ?>;
			var appPasswordTableLastUsedText = <?php echo wp_json_encode( esc_html__( 'Last used', 'bricks' ) ); ?>;
			var appPasswordTableActionsText = <?php echo wp_json_encode( esc_html__( 'Actions', 'bricks' ) ); ?>;
			var appPasswordManageText = <?php echo wp_json_encode( esc_html__( 'Manage', 'bricks' ) ); ?>;
			var appPasswordManageExistingText = <?php echo wp_json_encode( esc_html__( 'Existing application passwords are managed in the selected user profile.', 'bricks' ) ); ?>;
			var connectCopiedText = <?php echo wp_json_encode( esc_html__( 'Prompt copied', 'bricks' ) ); ?>;
			var connectCopyText = <?php echo wp_json_encode( esc_html__( 'Copy prompt', 'bricks' ) ); ?>;
			var connectReadyText = <?php echo wp_json_encode( esc_html__( 'Ready', 'bricks' ) ); ?>;
				var connectPendingText = <?php echo wp_json_encode( esc_html__( 'Pending', 'bricks' ) ); ?>;
				var connectMcpRequiredText = <?php echo wp_json_encode( esc_html__( 'MCP required', 'bricks' ) ); ?>;
				var passwordNeededTitle = <?php echo wp_json_encode( esc_html__( 'Application password needed', 'bricks' ) ); ?>;
				var passwordNeededDescription = <?php echo wp_json_encode( esc_html__( 'Use an existing application password, or generate one below.', 'bricks' ) ); ?>;
				var passwordReadyTitle = <?php echo wp_json_encode( esc_html__( 'Application password ready', 'bricks' ) ); ?>;
				var passwordReadyDescription = <?php echo wp_json_encode( esc_html__( 'Use the selected user application password in the setup details below.', 'bricks' ) ); ?>;
			var activeCountText = <?php /* translators: %d: number of active application passwords */ echo wp_json_encode( esc_html__( '%d active', 'bricks' ) ); ?>;
			var connectPromptText = <?php echo wp_json_encode( $connect_prompt_text ); ?>;
			var manualConfigClients = <?php echo wp_json_encode( $manual_config_clients ); ?>;
			var manualConfigText = <?php echo wp_json_encode( $manual_config_text ); ?>;
			var skillsPromptText = <?php echo wp_json_encode( $skills_prompt_text ); ?>;
			var skillsVerifyPromptText = <?php echo wp_json_encode( $skills_verify_prompt_text ); ?>;
			var installingMcpAdapterText = <?php echo wp_json_encode( esc_html__( 'Installing', 'bricks' ) ); ?>;
			var activatingMcpAdapterText = <?php echo wp_json_encode( esc_html__( 'Activating', 'bricks' ) ); ?>;
			var generatingAppPasswordText = <?php echo wp_json_encode( esc_html__( 'Generating', 'bricks' ) ); ?>;
			var executePhpCopiedText = <?php echo wp_json_encode( esc_html__( 'Copied', 'bricks' ) ); ?>;
			var endpointUrl = <?php echo wp_json_encode( $endpoint_url ? $endpoint_url : rest_url( 'mcp/mcp-adapter-default-server' ) ); ?>;
			var serverName = <?php echo wp_json_encode( $mcp_server_name ); ?>;
			var localHttps = <?php echo wp_json_encode( (bool) $local_https ); ?>;
			var mcpServerConfigured = <?php echo wp_json_encode( (bool) $mcp_adapter_active ); ?>;
			var connectionPassword = ''
			var activeConnectMethod = 'config'
			var manualConfigClient = <?php echo wp_json_encode( $manual_config_default_client ); ?>;
			var activeStatus = 'all'
			var activeCategory = 'all'
			var autosaveRequest = null
			var savedFormData = ''
			var abilitiesApiHighlightTimeout = null

			function formatActiveCount(count) {
				return activeCountText.replace('%d', count)
			}

			function getMcpAdapterErrorMessage(response, fallbackMessage) {
				var message = response && response.data && response.data.message ? response.data.message : fallbackMessage
				var fallbackUrl = response && response.data && response.data.manualSetupUrl ? response.data.manualSetupUrl : manualSetupUrl

				if (fallbackUrl) {
					message += '\n\n' + manualSetupUrlText + ': ' + fallbackUrl
				}

				return message
			}

			function setAutosaveStatus(state, message) {
				if (!autosaveStatus) {
					return
				}

				autosaveStatus.textContent = message || ''
				autosaveStatus.dataset.state = state || ''
				autosaveStatus.hidden = !message
			}

			function getAiSettingsFormData() {
				var formData = window.jQuery(form).serialize()
				var disabledCheckedAbilities = []

				if (masterToggle && masterToggle.disabled && masterToggle.checked) {
					disabledCheckedAbilities.push(encodeURIComponent(masterToggle.name) + '=on')
				}

				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[name="bricksMcpEnabledAbilities[]"]:disabled:checked')

					if (checkbox) {
						disabledCheckedAbilities.push(encodeURIComponent(checkbox.name) + '=' + encodeURIComponent(checkbox.value))
					}
				})

				if (disabledCheckedAbilities.length) {
					formData += (formData ? '&' : '') + disabledCheckedAbilities.join('&')
				}

				return formData
			}

			function saveAiSettings() {
				if (autosaveRequest) {
					autosaveRequest.abort()
				}

				var submittedFormData = getAiSettingsFormData()

				setAutosaveStatus('saving', savingMessage)

				var request = window.jQuery.ajax({
					type: 'POST',
					url: window.bricksData.ajaxUrl,
					data: {
						action: 'bricks_save_ai_settings',
						formData: submittedFormData,
						nonce: window.bricksData.nonce
					},
					success: function (response) {
						if (autosaveRequest !== request) {
							return
						}

						autosaveRequest = null

						if (!response || !response.success) {
							setAutosaveStatus('error', response && response.data && response.data.message ? response.data.message : saveErrorMessage)
							return
						}

						savedFormData = submittedFormData

						if (getAiSettingsFormData() === savedFormData) {
							setAutosaveStatus('success', saveSuccessMessage)
						} else {
							markUnsaved()
						}
					},
					error: function (xhr, status) {
						if (autosaveRequest !== request) {
							return
						}

						autosaveRequest = null

						if (status === 'abort') {
							return
						}

						setAutosaveStatus('error', saveErrorMessage)
					}
				})

				autosaveRequest = request
			}

			// Explicit save model: changes only mark the form dirty,
			// the save bar button (form submit) persists them
			function markUnsaved() {
				if (getAiSettingsFormData() === savedFormData) {
					setAutosaveStatus('', '')
					return
				}

				setAutosaveStatus('dirty', unsavedMessage)
			}

			function setActiveTab(tab) {
				tabButtons.forEach(function (button) {
					button.classList.toggle('is-active', button.dataset.aiTab === tab)
				})

				tabPanels.forEach(function (panel) {
					var isActive = panel.dataset.aiTabPanel === tab

					panel.hidden = !isActive
					panel.classList.toggle('is-active', isActive)
				})
			}

			function highlightAbilitiesApiCard(card) {
				if (!card) {
					return
				}

				if (abilitiesApiHighlightTimeout) {
					window.clearTimeout(abilitiesApiHighlightTimeout)
				}

				card.classList.remove('is-highlighted')
				void card.offsetWidth
				card.classList.add('is-highlighted')

				abilitiesApiHighlightTimeout = window.setTimeout(function () {
					card.classList.remove('is-highlighted')
					abilitiesApiHighlightTimeout = null
				}, 1800)
			}

			function syncMasterState() {
				var enabled = masterToggle && masterToggle.checked && !masterToggle.disabled

				if (masterToggleLabel && masterToggle) {
					masterToggleLabel.textContent = enabled ? enabledText : disabledText
				}

				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[type="checkbox"]')
					var alwaysOn = row.dataset.alwaysOn === '1'

					if (checkbox) {
						checkbox.disabled = !enabled || alwaysOn
					}
				})

				if (abilitiesDisabledNote) {
					abilitiesDisabledNote.hidden = enabled
				}

				// Card header chip mirrors the toggle while the MCP server is connected
				if (apiStatusChip && apiStatusChip.dataset.aiApiStatusLive === '1' && apiStatusText) {
					apiStatusChip.classList.toggle('is-success', enabled)
					apiStatusText.textContent = enabled ? enabledText : disabledText
				}
			}

			function updateCounts() {
				var enabled = 0

				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[type="checkbox"]')

					if ((checkbox && checkbox.checked) || (!checkbox && row.dataset.currentEnabled === '1')) {
						enabled++
					}
				})

				enabledCounts.forEach(function (count) {
					count.textContent = enabled
				})

			}

			function getSelectedCredentialLogin() {
				if (!appPasswordUser) {
					return ''
				}

				var selectedOption = appPasswordUser.options[appPasswordUser.selectedIndex]

				return selectedOption ? selectedOption.dataset.login : ''
			}

			function getSelectedCredentialPasswordCount() {
				if (!appPasswordUser) {
					return 0
				}

				var selectedOption = appPasswordUser.options[appPasswordUser.selectedIndex]

				return selectedOption ? parseInt(selectedOption.dataset.count || '0', 10) : 0
			}

			function isApplicationPasswordReady() {
				return getSelectedCredentialPasswordCount() > 0 || !!connectionPassword
			}

			function setReadinessState(key, isReady) {
				readinessItems.forEach(function (item) {
					var icon

					if (item.dataset.aiReadinessItem !== key) {
						return
					}

					item.classList.toggle('is-ready', isReady)
					item.classList.toggle('is-pending', !isReady)

					icon = item.querySelector('.dashicons')

					if (icon) {
						icon.classList.toggle('dashicons-yes-alt', isReady)
						icon.classList.toggle('dashicons-marker', !isReady)
					}
				})
			}

				function syncReadinessState() {
					setReadinessState('mcp-server', mcpServerConfigured)
					setReadinessState('application-password', isApplicationPasswordReady())
					setReadinessState('abilities', !!(masterToggle && masterToggle.checked))
				}

				function syncCredentialStepState() {
					var passwordReady = isApplicationPasswordReady()

					if (credentialStep) {
						credentialStep.classList.toggle('is-complete', passwordReady)
					}

					if (credentialStepMarker) {
						credentialStepMarker.innerHTML = passwordReady ? '<span class="dashicons dashicons-yes" aria-hidden="true"></span>' : '2'
					}
				}

			function tomlQuote(value) {
				return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"'
			}

			function shellQuote(value) {
				return "'" + value.replace(/'/g, "'\\''") + "'"
			}

			function getManualConfigPassword() {
				return connectionPassword || manualConfigText.passwordPlaceholder
			}

			function getNpxServerConfig() {
				var env = {
					WP_API_URL: endpointUrl,
					WP_API_USERNAME: getSelectedCredentialLogin(),
					WP_API_PASSWORD: getManualConfigPassword()
				}

				if (localHttps) {
					env.NODE_TLS_REJECT_UNAUTHORIZED = '0'
				}

				return {
					command: 'npx',
					args: ['-y', '@automattic/mcp-wordpress-remote@latest'],
					env: env
				}
			}

			function buildManualConfigCode(variant) {
				var npxServer = getNpxServerConfig()
				var payload = {}
				var env = npxServer.env
				var lines

				if (variant === 'claude-code') {
					lines = [
						'claude mcp add ' + shellQuote(serverName),
						'--env WP_API_URL=' + shellQuote(env.WP_API_URL),
						'--env WP_API_USERNAME=' + shellQuote(env.WP_API_USERNAME),
						'--env WP_API_PASSWORD=' + shellQuote(env.WP_API_PASSWORD)
					]

					if (localHttps) {
						lines.push('--env NODE_TLS_REJECT_UNAUTHORIZED=' + shellQuote('0'))
					}

					lines.push('-- npx -y @automattic/mcp-wordpress-remote@latest')

					return lines.join(' \\\n  ')
				}

				if (variant === 'codex') {
					lines = [
						'[mcp_servers.' + serverName + ']',
						'command = "npx"',
						'args = ["-y", "@automattic/mcp-wordpress-remote@latest"]',
						'',
						'[mcp_servers.' + serverName + '.env]',
						'WP_API_URL = ' + tomlQuote(env.WP_API_URL),
						'WP_API_USERNAME = ' + tomlQuote(env.WP_API_USERNAME),
						'WP_API_PASSWORD = ' + tomlQuote(env.WP_API_PASSWORD)
					]

					if (localHttps) {
						lines.push('NODE_TLS_REJECT_UNAUTHORIZED = "0"')
					}

					return lines.join('\n')
				}

				if (variant === 'zed') {
					payload.context_servers = {}
					payload.context_servers[serverName] = Object.assign(
						{
							source: 'custom',
							enabled: true
						},
						npxServer
					)

					return JSON.stringify(payload, null, 2)
				}

				if (variant === 'opencode') {
					payload.mcp = {}
					payload.mcp[serverName] = {
						type: 'local',
						command: ['npx', '-y', '@automattic/mcp-wordpress-remote@latest'],
						environment: env
					}

					return JSON.stringify(payload, null, 2)
				}

				if (variant === 'cody') {
					payload['cody.mcpServers'] = {}
					payload['cody.mcpServers'][serverName] = npxServer

					return JSON.stringify(payload, null, 2)
				}

				payload[variant === 'servers' ? 'servers' : 'mcpServers'] = {}
				payload[variant === 'servers' ? 'servers' : 'mcpServers'][serverName] = npxServer

				return JSON.stringify(payload, null, 2)
			}

			function appendManualConfigToken(parent, text, className) {
				var node = className ? document.createElement('span') : document.createTextNode(text)

				if (className) {
					node.className = className
					node.textContent = text
				}

				parent.appendChild(node)
			}

			function highlightManualJson(code) {
				var fragment = document.createDocumentFragment()
				var pattern = /("(?:\\.|[^"\\])*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false|null)\b|[{}\[\],:]/g
				var lastIndex = 0
				var match

				while ((match = pattern.exec(code)) !== null) {
					appendManualConfigToken(fragment, code.slice(lastIndex, match.index))

					if (match[1]) {
						appendManualConfigToken(fragment, match[1], match[2] ? 'bricks-ai-code-token-key' : 'bricks-ai-code-token-string')

						if (match[2]) {
							appendManualConfigToken(fragment, match[2], 'bricks-ai-code-token-punctuation')
						}
					} else if (match[3]) {
						appendManualConfigToken(fragment, match[3], 'bricks-ai-code-token-number')
					} else if (match[4]) {
						appendManualConfigToken(fragment, match[4], 'bricks-ai-code-token-literal')
					} else {
						appendManualConfigToken(fragment, match[0], 'bricks-ai-code-token-punctuation')
					}

					lastIndex = pattern.lastIndex
				}

				appendManualConfigToken(fragment, code.slice(lastIndex))

				return fragment
			}

			function highlightManualPlainConfig(code) {
				var fragment = document.createDocumentFragment()
				var pattern = /("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(\[[^\]]+\])|(--?[A-Za-z0-9_-]+)|([A-Z0-9_]+)(\s*=)/g
				var lastIndex = 0
				var match

				while ((match = pattern.exec(code)) !== null) {
					appendManualConfigToken(fragment, code.slice(lastIndex, match.index))

					if (match[1]) {
						appendManualConfigToken(fragment, match[1], 'bricks-ai-code-token-string')
					} else if (match[2]) {
						appendManualConfigToken(fragment, match[2], 'bricks-ai-code-token-key')
					} else if (match[3]) {
						appendManualConfigToken(fragment, match[3], 'bricks-ai-code-token-literal')
					} else if (match[4]) {
						appendManualConfigToken(fragment, match[4], 'bricks-ai-code-token-key')
						appendManualConfigToken(fragment, match[5], 'bricks-ai-code-token-punctuation')
					}

					lastIndex = pattern.lastIndex
				}

				appendManualConfigToken(fragment, code.slice(lastIndex))

				return fragment
			}

			function renderManualConfigCode(code, variant) {
				if (!manualConfigCode) {
					return
				}

				manualConfigCode.innerHTML = ''
				manualConfigCode.appendChild(
					variant === 'mcp-servers' || variant === 'servers' || variant === 'zed' || variant === 'opencode' || variant === 'cody'
						? highlightManualJson(code)
						: highlightManualPlainConfig(code)
				)
			}

			function setManualConfigCopyButtonLabel(label) {
				if (!manualConfigCopyButton) {
					return
				}

				var icon = document.createElement('span')

				manualConfigCopyButton.innerHTML = ''
				icon.className = 'dashicons dashicons-admin-page'
				icon.setAttribute('aria-hidden', 'true')
				manualConfigCopyButton.appendChild(icon)
				manualConfigCopyButton.appendChild(document.createTextNode(label))
			}

			function renderManualConfig() {
				var config = manualConfigClients[manualConfigClient]

				if (!config || !manualConfigCode) {
					return
				}

				renderManualConfigCode(buildManualConfigCode(config.variant), config.variant)

				if (manualConfigHint) {
					manualConfigHint.innerHTML = config.hint || ''
				}

				if (manualConfigPaths) {
					manualConfigPaths.innerHTML = ''

					Object.keys(config.paths || {}).forEach(function (label) {
						var row = document.createElement('p')
						var strong = document.createElement('strong')
						var code = document.createElement('code')

						strong.textContent = label + ': '
						code.textContent = config.paths[label]
						row.appendChild(strong)
						row.appendChild(code)
						manualConfigPaths.appendChild(row)
					})
				}

				setManualConfigCopyButtonLabel(manualConfigText.copy)
			}

			function setSkillsPromptCopyButtonLabel(label) {
				if (!skillsPromptCopyButton) {
					return
				}

				var icon = document.createElement('span')

				skillsPromptCopyButton.innerHTML = ''
				icon.className = 'dashicons dashicons-admin-page'
				icon.setAttribute('aria-hidden', 'true')
				skillsPromptCopyButton.appendChild(icon)
				skillsPromptCopyButton.appendChild(document.createTextNode(label))
			}

			function setSkillsVerifyPromptCopyButtonLabel(label) {
				if (!skillsVerifyPromptCopyButton) {
					return
				}

				var icon = document.createElement('span')

				skillsVerifyPromptCopyButton.innerHTML = ''
				icon.className = 'dashicons dashicons-admin-page'
				icon.setAttribute('aria-hidden', 'true')
				skillsVerifyPromptCopyButton.appendChild(icon)
				skillsVerifyPromptCopyButton.appendChild(document.createTextNode(label))
			}

			function buildSkillsPrompt() {
				return skillsPromptText.lines.map(function (line) {
					return line.replace('{repoUrl}', skillsPromptText.repoUrl)
				}).join('\n')
			}

			function buildSkillsVerifyPrompt() {
				return skillsVerifyPromptText.lines.join(' ')
			}

			function renderSkillsPrompt() {
				if (skillsPrompt) {
					skillsPrompt.value = buildSkillsPrompt()
				}

				setSkillsPromptCopyButtonLabel(skillsPromptText.copy)
			}

			function renderSkillsVerifyPrompt() {
				if (skillsVerifyPrompt) {
					skillsVerifyPrompt.value = buildSkillsVerifyPrompt()
				}

				setSkillsVerifyPromptCopyButtonLabel(skillsVerifyPromptText.copy)
			}

			function setConnectMethod(method) {
				activeConnectMethod = method

				connectMethodButtons.forEach(function (button) {
					var isActive = button.dataset.aiConnectMethod === method

					button.classList.toggle('is-active', isActive)
					button.setAttribute('aria-selected', isActive ? 'true' : 'false')
				})

				connectMethodPanels.forEach(function (panel) {
					panel.hidden = panel.dataset.aiConnectPanel !== method
				})

				if (method === 'config') {
					renderManualConfig()
				}

				renderSkillsPrompt()
			}

				function openCredentialsSection() {
					var body = document.getElementById('bricks-ai-config-credentials')

					if (body && body.hidden) {
						body.hidden = false
					}

					if (appPasswordName) {
					appPasswordName.scrollIntoView({ behavior: 'smooth', block: 'center' })
					appPasswordName.focus()
				}
			}

			function renderConnectionPrompt() {
				if (!connectPrompt || !connectCopyButton) {
					return
				}

				var lines = [
					connectPromptText.intro,
					'',
					connectPromptText.detailsHeading,
					'- ' + connectPromptText.serverUrl + ': ' + endpointUrl,
					'- ' + connectPromptText.username + ': ' + getSelectedCredentialLogin(),
					'- ' + connectPromptText.applicationPassword + ': ' + (connectionPassword || connectPromptText.passwordPlaceholder),
					'- ' + connectPromptText.serverName + ': ' + serverName,
					'- ' + connectPromptText.transport + ': ' + connectPromptText.transportValue,
					'- ' + connectPromptText.package + ': @automattic/mcp-wordpress-remote',
					'',
					connectPromptText.rulesHeading,
					'- ' + connectPromptText.configRule,
					'- ' + connectPromptText.approvalRule,
					'- ' + connectPromptText.invokeRule,
					'- ' + connectPromptText.credentialsRule,
					'- ' + connectPromptText.secretRule,
					'- ' + connectPromptText.preserveRule,
					'- ' + connectPromptText.restartChatRule,
					'- ' + connectPromptText.testRule
				]

				if (localHttps) {
					lines.splice(15, 0, '- ' + connectPromptText.tlsRule)
				}

					connectPrompt.value = lines.join('\n')
					connectCopyButton.disabled = false
					connectCopyButton.innerHTML = '<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>' + connectCopyText
					var passwordReady = isApplicationPasswordReady()

					if (connectPasswordWarning) {
						connectPasswordWarning.classList.toggle('is-complete', passwordReady)
					}

					if (connectPasswordTitle) {
						connectPasswordTitle.textContent = passwordReady ? passwordReadyTitle : passwordNeededTitle
					}

					if (connectPasswordDescription) {
						connectPasswordDescription.textContent = passwordReady ? passwordReadyDescription : passwordNeededDescription
					}

					if (connectGenerateButton) {
						connectGenerateButton.hidden = passwordReady
					}

					if (connectStatus) {
						var isConnectReady = mcpServerConfigured && passwordReady

						connectStatus.textContent = !mcpServerConfigured ? connectMcpRequiredText : isConnectReady ? connectReadyText : connectPendingText
						connectStatus.classList.toggle('is-ready', isConnectReady)
				}

				if (appPasswordInfo) {
					appPasswordInfo.hidden = !connectionPassword
					}

					syncCredentialStepState()
					renderManualConfig()
				}

			function applyFilters() {
				var query = searchInput ? searchInput.value.trim().toLowerCase() : ''
				var shown = 0

				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[type="checkbox"]')
					var isEnabled = checkbox ? checkbox.checked : row.dataset.currentEnabled === '1'
					var isDefaultOff = row.dataset.defaultEnabled !== '1'
					var matchesSearch = !query || row.dataset.search.indexOf(query) !== -1
					var matchesCategory = activeCategory === 'all' || row.dataset.category === activeCategory
					var matchesStatus =
						activeStatus === 'all' ||
						(activeStatus === 'enabled' && isEnabled) ||
						(activeStatus === 'disabled' && !isEnabled) ||
						(activeStatus === 'default-off' && isDefaultOff)
					var isVisible = matchesSearch && matchesCategory && matchesStatus

					row.hidden = !isVisible

					if (isVisible) {
						shown++
					}
				})

				abilityGroups.forEach(function (group) {
					group.hidden = !group.querySelector('.bricks-ai-ability-row:not([hidden])')
				})

				if (visibleCount) {
					visibleCount.textContent = shown
				}
			}

			function setStatusFilter(button) {
				activeStatus = button.dataset.statusFilter

				statusFilters.forEach(function (filter) {
					filter.classList.toggle('is-active', filter === button)
				})

				applyFilters()
			}

			function setCategoryFilter(button) {
				activeCategory = button.dataset.categoryFilter

				categoryFilters.forEach(function (filter) {
					filter.classList.toggle('is-active', filter === button)
				})

				applyFilters()
			}

			function setAll(checked) {
				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[type="checkbox"]')

					if (checkbox && !checkbox.disabled) {
						checkbox.checked = checked
					}
				})

				updateCounts()
				applyFilters()
			}

			function resetDefaults() {
				abilityRows.forEach(function (row) {
					var checkbox = row.querySelector('input[type="checkbox"]')

					if (checkbox && !checkbox.disabled) {
						checkbox.checked = row.dataset.defaultEnabled === '1'
					}
				})

				updateCounts()
				applyFilters()
			}

			function createApplicationPasswordTable(userId) {
				var table = document.createElement('table')
				var thead = document.createElement('thead')
				var headRow = document.createElement('tr')
				var tbody = document.createElement('tbody')
				var passwordTableHeaders = [appPasswordTableNameText, appPasswordTableCreatedText, appPasswordTableLastUsedText, appPasswordTableActionsText]

				table.setAttribute('data-ai-application-password-table', userId)

				passwordTableHeaders.forEach(function (label) {
					var header = document.createElement('th')
					header.textContent = label
					headRow.appendChild(header)
				})

				thead.appendChild(headRow)
				table.appendChild(thead)
				table.appendChild(tbody)

				if (appPasswordList) {
					appPasswordList.appendChild(table)
				}

				return table
			}

			function getApplicationPasswordTable(userId) {
				var table = form.querySelector('[data-ai-application-password-table="' + userId + '"]')

				if (!table) {
					table = createApplicationPasswordTable(userId)
				}

				return table
			}

			function addApplicationPasswordOverviewRow(tbody, password, profileUrl, prepend) {
				var row = document.createElement('tr')
				var name = document.createElement('td')
				var created = document.createElement('td')
				var lastUsed = document.createElement('td')
				var actions = document.createElement('td')
				var manageLink = document.createElement('a')

				var nameText = document.createElement('strong')

				name.className = 'bricks-ai-password-name'
				created.className = 'bricks-ai-password-meta'
				lastUsed.className = 'bricks-ai-password-meta'
				actions.className = 'bricks-ai-password-action'
				created.dataset.label = appPasswordTableCreatedText
				lastUsed.dataset.label = appPasswordTableLastUsedText
				nameText.textContent = password.name || ''
				name.appendChild(nameText)
				created.textContent = password.created || ''
				lastUsed.textContent = password.lastUsed || ''
				manageLink.href = profileUrl || ''
				manageLink.textContent = appPasswordManageText

				actions.appendChild(manageLink)
				row.appendChild(name)
				row.appendChild(created)
				row.appendChild(lastUsed)
				row.appendChild(actions)
				if (prepend) {
					tbody.prepend(row)
				} else {
					tbody.appendChild(row)
				}
			}

			function addApplicationPasswordManageRow(tbody, profileUrl) {
				var row = document.createElement('tr')
				var cell = document.createElement('td')
				var manageLink = document.createElement('a')

				cell.colSpan = 4
				manageLink.href = profileUrl || ''
				manageLink.textContent = appPasswordManageExistingText
				cell.appendChild(manageLink)
				row.appendChild(cell)
				tbody.appendChild(row)
			}

			function loadApplicationPasswordOverview(userId, profileUrl) {
				var table = getApplicationPasswordTable(userId)
				var tbody = table ? table.querySelector('tbody') : null

				if (!table || !tbody || table.dataset.loaded === '1' || table.dataset.loaded === 'loading') {
					return
				}

				table.dataset.loaded = 'loading'

				window.jQuery.ajax({
					type: 'GET',
					url: window.bricksData.ajaxUrl,
					data: {
						action: 'bricks_get_ai_application_passwords',
						userId: userId,
						nonce: window.bricksData.nonce
					},
					success: function (response) {
						var passwords = response && response.success && response.data && Array.isArray(response.data.passwords) ? response.data.passwords : null

						tbody.innerHTML = ''

						if (!passwords) {
							addApplicationPasswordManageRow(tbody, profileUrl)
							table.dataset.loaded = ''
							return
						}

						passwords.forEach(function (password) {
							addApplicationPasswordOverviewRow(tbody, password, profileUrl)
						})

						table.dataset.loaded = '1'

						var selectedOption = appPasswordUser ? appPasswordUser.options[appPasswordUser.selectedIndex] : null

						if (selectedOption && selectedOption.value === userId) {
							selectedOption.dataset.count = passwords.length

							if (credentialCount) {
								credentialCount.textContent = formatActiveCount(passwords.length)
							}

							if (appPasswordList) {
								appPasswordList.hidden = passwords.length < 1
							}
						}
					},
					error: function () {
						tbody.innerHTML = ''
						addApplicationPasswordManageRow(tbody, profileUrl)
						table.dataset.loaded = ''
					}
				})
			}

			function syncCredentialUser() {
				var selectedOption = appPasswordUser ? appPasswordUser.options[appPasswordUser.selectedIndex] : null
				var selectedUserId = selectedOption ? selectedOption.value : ''
				var selectedPasswordCount = selectedOption ? parseInt(selectedOption.dataset.count || '0', 10) : 0

				if (appPasswordUser) {
					if (selectedOption && selectedPasswordCount > 0) {
						loadApplicationPasswordOverview(selectedUserId, selectedOption.dataset.profileUrl)
					}

					form.querySelectorAll('[data-ai-application-password-table]').forEach(function (table) {
						table.hidden = table.dataset.aiApplicationPasswordTable !== selectedUserId
					})

					if (appPasswordList) {
						appPasswordList.hidden = !selectedOption || selectedPasswordCount < 1
					}

					if (credentialCount) {
						credentialCount.textContent = formatActiveCount(selectedPasswordCount)
					}

					if (appPasswordResultInput) {
						appPasswordResultInput.value = ''
						appPasswordResultInput.hidden = true
					}
				}

				connectionPassword = ''
				renderConnectionPrompt()
				syncReadinessState()
			}

			function addApplicationPasswordRow(data) {
				if (!appPasswordUser || !data || !data.userId) {
					return
				}

				var selectedOption = appPasswordUser.options[appPasswordUser.selectedIndex]
				var table = getApplicationPasswordTable(data.userId.toString())
				var tbody = table ? table.querySelector('tbody') : null

				if (!tbody) {
					return
				}

				Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function (existingRow) {
					var cells = existingRow.querySelectorAll('td')

					if (cells.length === 1 && cells[0].colSpan === 4) {
						existingRow.remove()
					}
				})

				addApplicationPasswordOverviewRow(tbody, data, selectedOption ? selectedOption.dataset.profileUrl : '', true)

				if (appPasswordList && data.userId.toString() === appPasswordUser.value) {
					appPasswordList.hidden = false
					table.hidden = false
				}
			}

			configToggles.forEach(function (button) {
				button.addEventListener('click', function () {
					var body = button.getAttribute('aria-controls') ? document.getElementById(button.getAttribute('aria-controls')) : null
					var expanded = button.getAttribute('aria-expanded') === 'true'
					var card = button.closest('.bricks-ai-card')

					if (!body) {
						return
					}

					button.setAttribute('aria-expanded', expanded ? 'false' : 'true')

					if (card) {
						card.classList.toggle('is-collapsed', expanded)
					}

					body.hidden = expanded
				})
			})

			if (masterToggle) {
				masterToggle.addEventListener('change', function () {
					syncMasterState()
					syncReadinessState()
					markUnsaved()
				})
			}

			if (searchInput) {
				searchInput.addEventListener('input', applyFilters)
			}

			statusFilters.forEach(function (button) {
				button.addEventListener('click', function () {
					setStatusFilter(button)
				})
			})

			if (categoryNav) {
				categoryNav.addEventListener('click', function (event) {
					var target = event.target.nodeType === 1 ? event.target : event.target.parentElement
					var button = target ? target.closest('[data-category-filter]') : null

					if (!button || !categoryNav.contains(button)) {
						return
					}

					setCategoryFilter(button)
				})
			}

			abilityRows.forEach(function (row) {
				var checkbox = row.querySelector('input[type="checkbox"]')

				if (checkbox) {
					checkbox.addEventListener('change', function () {
						updateCounts()
						applyFilters()
						markUnsaved()
					})
				}
			})

			form.querySelectorAll('[data-bulk-action]').forEach(function (button) {
				button.addEventListener('click', function () {
					if (button.dataset.bulkAction === 'enable') {
						setAll(true)
					} else if (button.dataset.bulkAction === 'disable') {
						setAll(false)
					} else {
						resetDefaults()
					}

					markUnsaved()
				})
			})

			tabButtons.forEach(function (button) {
				button.addEventListener('click', function () {
					setActiveTab(button.dataset.aiTab)
				})
			})

			form.querySelectorAll('[data-ai-open-tab]').forEach(function (button) {
				button.addEventListener('click', function () {
					setActiveTab(button.dataset.aiOpenTab)
				})
			})

			if (installMcpAdapterButton) {
				installMcpAdapterButton.addEventListener('click', function () {
					var originalText = installMcpAdapterButton.textContent

					installMcpAdapterButton.disabled = true
					installMcpAdapterButton.textContent = installingMcpAdapterText

					window.jQuery.ajax({
						type: 'POST',
						url: window.bricksData.ajaxUrl,
						data: {
							action: 'bricks_install_mcp_adapter',
							nonce: window.bricksData.nonce
						},
						success: function (response) {
							if (!response || !response.success) {
								installMcpAdapterButton.disabled = false
								installMcpAdapterButton.textContent = originalText
								window.alert(getMcpAdapterErrorMessage(response, installMcpAdapterErrorMessage))
								return
							}

							var url = new URL(window.location.href)
							url.searchParams.set('bricks_notice', 'mcp_adapter_installed')
							window.location.href = url.toString()
						},
						error: function () {
							installMcpAdapterButton.disabled = false
							installMcpAdapterButton.textContent = originalText
							window.alert(getMcpAdapterErrorMessage(null, installMcpAdapterErrorMessage))
						}
					})
				})
			}

			if (activateMcpAdapterButton) {
				activateMcpAdapterButton.addEventListener('click', function () {
					var originalText = activateMcpAdapterButton.textContent

					activateMcpAdapterButton.disabled = true
					activateMcpAdapterButton.textContent = activatingMcpAdapterText

					window.jQuery.ajax({
						type: 'POST',
						url: window.bricksData.ajaxUrl,
						data: {
							action: 'bricks_activate_mcp_adapter',
							nonce: window.bricksData.nonce
						},
						success: function (response) {
							if (!response || !response.success) {
								activateMcpAdapterButton.disabled = false
								activateMcpAdapterButton.textContent = originalText
								window.alert(getMcpAdapterErrorMessage(response, activateMcpAdapterErrorMessage))
								return
							}

							var url = new URL(window.location.href)
							url.searchParams.set('bricks_notice', 'mcp_adapter_activated')
							window.location.href = url.toString()
						},
						error: function () {
							activateMcpAdapterButton.disabled = false
							activateMcpAdapterButton.textContent = originalText
							window.alert(getMcpAdapterErrorMessage(null, activateMcpAdapterErrorMessage))
						}
					})
				})
			}

			if (appPasswordUser) {
				appPasswordUser.addEventListener('change', syncCredentialUser)
			}

			if (appPasswordButton) {
				appPasswordButton.addEventListener('click', function () {
					var originalText = appPasswordButton.textContent
					var credentialName = appPasswordName ? appPasswordName.value.trim() : ''

					if (!credentialName) {
						if (appPasswordName) {
							appPasswordName.reportValidity()
						}

						return
					}

					if (!appPasswordUser || !appPasswordUser.value) {
						window.alert(appPasswordSelectUserMessage)
						return
					}

					appPasswordButton.disabled = true
					appPasswordButton.textContent = generatingAppPasswordText

					window.jQuery.ajax({
						type: 'POST',
						url: window.bricksData.ajaxUrl,
						data: {
							action: 'bricks_generate_ai_application_password',
							name: credentialName,
							userId: appPasswordUser ? appPasswordUser.value : '',
							nonce: window.bricksData.nonce
						},
						success: function (response) {
							appPasswordButton.disabled = false
							appPasswordButton.textContent = originalText

							if (!response || !response.success || !response.data || !response.data.password) {
								window.alert(response && response.data && response.data.message ? response.data.message : appPasswordErrorMessage)
								return
							}

							if (appPasswordResultInput) {
								appPasswordResultInput.value = response.data.password
								appPasswordResultInput.hidden = false
								appPasswordResultInput.select()
							}

							connectionPassword = response.data.password
							renderConnectionPrompt()

							if (appPasswordUser && response.data.count) {
								appPasswordUser.options[appPasswordUser.selectedIndex].dataset.count = response.data.count

								if (credentialCount) {
									credentialCount.textContent = formatActiveCount(parseInt(response.data.count, 10))
								}
							}

							addApplicationPasswordRow(response.data)
							syncReadinessState()
						},
						error: function () {
							appPasswordButton.disabled = false
							appPasswordButton.textContent = originalText
							window.alert(appPasswordErrorMessage)
						}
					})
				})
			}

			if (connectCopyButton && connectPrompt) {
				connectCopyButton.addEventListener('click', function () {
					if (!connectPrompt.value) {
						return
					}

					if (window.navigator.clipboard) {
						window.navigator.clipboard.writeText(connectPrompt.value)
					} else {
						connectPrompt.select()
						document.execCommand('copy')
					}

					connectCopyButton.textContent = connectCopiedText
				})
			}

			connectMethodButtons.forEach(function (button) {
				button.addEventListener('click', function () {
					setConnectMethod(button.dataset.aiConnectMethod)
				})
			})

				if (connectGenerateButton) {
					connectGenerateButton.addEventListener('click', openCredentialsSection)
				}

				if (gotoSkillsButton) {
					gotoSkillsButton.addEventListener('click', function () {
						setActiveTab('skills')

						var skillsPanel = document.getElementById('bricks-ai-tab-skills')

						if (skillsPanel) {
							skillsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' })
						}
					})
				}

			if (manualClientSelect) {
				manualClientSelect.addEventListener('change', function () {
					manualConfigClient = manualClientSelect.value
					renderManualConfig()
				})
			}

			if (manualConfigCopyButton && manualConfigCode) {
				manualConfigCopyButton.addEventListener('click', function () {
					if (!manualConfigCode.textContent) {
						return
					}

					if (window.navigator.clipboard) {
						window.navigator.clipboard.writeText(manualConfigCode.textContent)
					} else {
						var range = document.createRange()
						var selection = window.getSelection()

						range.selectNodeContents(manualConfigCode)

						if (selection) {
							selection.removeAllRanges()
							selection.addRange(range)
						}

						document.execCommand('copy')
					}

					setManualConfigCopyButtonLabel(manualConfigText.copied)
				})
			}

			if (skillsPromptCopyButton && skillsPrompt) {
				skillsPromptCopyButton.addEventListener('click', function () {
					if (!skillsPrompt.value) {
						return
					}

					if (window.navigator.clipboard) {
						window.navigator.clipboard.writeText(skillsPrompt.value)
					} else {
						skillsPrompt.select()
						document.execCommand('copy')
					}

					setSkillsPromptCopyButtonLabel(skillsPromptText.copied)
				})
			}

			if (skillsVerifyPromptCopyButton && skillsVerifyPrompt) {
				skillsVerifyPromptCopyButton.addEventListener('click', function () {
					if (!skillsVerifyPrompt.value) {
						return
					}

					if (window.navigator.clipboard) {
						window.navigator.clipboard.writeText(skillsVerifyPrompt.value)
					} else {
						skillsVerifyPrompt.select()
						document.execCommand('copy')
					}

					setSkillsVerifyPromptCopyButtonLabel(skillsVerifyPromptText.copied)
				})
			}

			if (gotoAbilitiesApiButton && masterToggle) {
				gotoAbilitiesApiButton.addEventListener('click', function () {
					setActiveTab('config')

					// Expand the Abilities API card if it is collapsed
					var card = masterToggle.closest('.bricks-ai-card')
					var cardBody = card ? card.querySelector('.bricks-ai-config-tab-body') : null
					var cardHeader = card ? card.querySelector('[data-ai-config-toggle]') : null

					if (cardBody && cardBody.hidden && cardHeader) {
						cardHeader.click()
					}

					highlightAbilitiesApiCard(card)

					if (card) {
						card.scrollIntoView({ behavior: 'smooth', block: 'center' })
					}

					masterToggle.focus({ preventScroll: true })
				})
			}

			if (executePhpRow && executePhpPanel) {
				executePhpPanel.classList.add('bricks-ai-execute-php-inline')
				executePhpPanel.hidden = true
				executePhpRow.appendChild(executePhpPanel)
			}

			if (gotoExecutePhpButton && executePhpRow && executePhpPanel) {
				gotoExecutePhpButton.addEventListener('click', function () {
					setActiveTab('abilities')

					var isExpanded = gotoExecutePhpButton.getAttribute('aria-expanded') === 'true'

					gotoExecutePhpButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true')
					executePhpPanel.hidden = isExpanded

					if (!isExpanded) {
						highlightAbilitiesApiCard(executePhpRow)
						executePhpRow.scrollIntoView({ behavior: 'smooth', block: 'center' })
					}
				})
			}

			executePhpCopyButtons.forEach(function (button) {
				button.addEventListener('click', function () {
					var code = button.closest('.bricks-ai-execute-php-code').querySelector('code')
					var originalText = button.textContent
					var constantText = code.textContent

					if (window.navigator.clipboard) {
						window.navigator.clipboard.writeText(constantText)
					} else {
						var range = document.createRange()
						var selection = window.getSelection()

						range.selectNodeContents(code)
						selection.removeAllRanges()
						selection.addRange(range)
						document.execCommand('copy')
						selection.removeAllRanges()
					}

					button.textContent = executePhpCopiedText
					window.setTimeout(function () {
						button.textContent = originalText
					}, 1600)
				})
			})

			form.addEventListener('submit', function (event) {
				event.preventDefault()
				saveAiSettings()
			})

			syncMasterState()
			syncCredentialUser()
			setConnectMethod(activeConnectMethod)
			renderSkillsPrompt()
			renderSkillsVerifyPrompt()
			syncReadinessState()
			updateCounts()
			applyFilters()
			savedFormData = getAiSettingsFormData()
		}

		document.addEventListener('DOMContentLoaded', initBricksAiSettings)
	})();
</script>

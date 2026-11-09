<?php
/**
 * System Information ability
 *
 * Read-only parity with Bricks > Settings > System Information. Intended
 * for diagnostic use only - admins debugging a Bricks install from an
 * MCP client get the same environment snapshot the admin screen shows.
 *
 * License-related fields (key, status, HTTP response) are NEVER included.
 * Use the admin UI for license work.
 *
 * @since 2.4
 */

namespace Bricks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class System_Info {
	public static function get_system_information_schema() {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
		];
	}

	public static function get_system_information_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'theme'     => [ 'type' => 'object' ],
				'bricks'    => [ 'type' => 'object' ],
				'wordpress' => [ 'type' => 'object' ],
				'server'    => [ 'type' => 'object' ],
				'plugins'   => [
					'type'        => 'array',
					'description' => __( 'Active plugins `[{ name, version, file }]`.', 'bricks' ),
				],
			],
		];
	}

	public static function read_permission( $input ) {
		return Manager::require_cap( 'manage_options' );
	}

	public static function get_system_information( $input ) {
		return [
			'theme'     => self::theme_info(),
			'bricks'    => self::bricks_info(),
			'wordpress' => self::wp_info(),
			'server'    => self::server_info(),
			'plugins'   => self::active_plugins(),
		];
	}

	private static function theme_info(): array {
		$active = wp_get_theme();
		$info   = [
			'name'         => (string) $active->get( 'Name' ),
			'version'      => (string) $active->get( 'Version' ),
			'author'       => (string) $active->get( 'Author' ),
			'authorUri'    => (string) $active->get( 'AuthorURI' ),
			'isChildTheme' => is_child_theme(),
		];
		if ( is_child_theme() ) {
			$parent         = wp_get_theme( $active->get( 'Template' ) );
			$info['parent'] = [
				'name'    => (string) $parent->get( 'Name' ),
				'version' => (string) $parent->get( 'Version' ),
				'uri'     => (string) $parent->get( 'ThemeURI' ),
			];
		}
		return $info;
	}

	private static function bricks_info(): array {
		return [
			'version'          => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : null,
			'dbVersion'        => defined( 'BRICKS_DB_VERSION' ) ? BRICKS_DB_VERSION : null,
			'builderUrlPath'   => defined( 'BRICKS_BUILDER_URL_PATH' ) ? BRICKS_BUILDER_URL_PATH : null,
			'cssLoading'       => class_exists( '\\Bricks\\Database' ) ? (string) \Bricks\Database::get_setting( 'cssLoading', 'inline' ) : 'inline',
			'abilitiesApi'     => did_action( 'wp_abilities_api_init' ) > 0 || has_action( 'wp_abilities_api_init' ),
			'mcpAdapterActive' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) || function_exists( 'wp_mcp_adapter' ),
		];
	}

	private static function wp_info(): array {
		global $wpdb;
		return [
			'homeUrl'        => home_url(),
			'siteUrl'        => site_url(),
			'restPrefix'     => rest_get_url_prefix(),
			'version'        => get_bloginfo( 'version' ),
			'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'language'       => get_locale(),
			'multisite'      => is_multisite(),
			'memoryLimit'    => WP_MEMORY_LIMIT,
			'maxMemoryLimit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,
			'maxUploadSize'  => size_format( wp_max_upload_size() ),
			'dbPrefix'       => $wpdb ? $wpdb->prefix : null,
			'dbVersion'      => $wpdb ? $wpdb->db_version() : null,
			'permalinks'     => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
		];
	}

	private static function server_info(): array {
		$max_input_vars = (int) ini_get( 'max_input_vars' );

		return [
			'phpVersion'        => PHP_VERSION,
			'phpSapi'           => PHP_SAPI,
			'memoryLimit'       => ini_get( 'memory_limit' ),
			'uploadMaxFilesize' => ini_get( 'upload_max_filesize' ),
			'postMaxSize'       => ini_get( 'post_max_size' ),
			'maxExecutionTime'  => ini_get( 'max_execution_time' ),
			'maxInputVars'      => $max_input_vars,
			'curlEnabled'       => function_exists( 'curl_init' ),
			'gdEnabled'         => extension_loaded( 'gd' ),
			'zipArchive'        => class_exists( '\\ZipArchive' ),
			'svgSanitizer'      => \Bricks\Svg::is_available(),
			'mbstring'          => extension_loaded( 'mbstring' ),
			'serverSoftware'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		];
	}

	private static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
			$active         = array_values( array_unique( array_merge( $active, $network_active ) ) );
		}
		foreach ( $active as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$row       = $all[ $file ];
			$plugins[] = [
				'name'    => (string) ( $row['Name'] ?? '' ),
				'version' => (string) ( $row['Version'] ?? '' ),
				'author'  => wp_strip_all_tags( (string) ( $row['Author'] ?? '' ) ),
				'file'    => (string) $file,
			];
		}
		return $plugins;
	}
}

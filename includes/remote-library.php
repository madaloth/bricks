<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Shared remote-library source, authorization, and transport service.
 *
 * The legacy remote-template route remains owned by Templates. This service is
 * additive and only handles negotiated remote-library requests.
 *
 * @since 2.4
 */
class Remote_Library {
	const PROTOCOL          = 'bricks-remote-library/1';
	const MAX_RESPONSE_SIZE = 10485760;

	/**
	 * Get redacted remote-library data for the builder.
	 *
	 * @return array
	 */
	public static function get_builder_data() {
		$sources = array_map(
			function( $source ) {
				return [
					'id'   => $source['id'],
					'name' => $source['name'],
					'url'  => $source['url'],
				];
			},
			self::get_sources()
		);

		return [
			'protocol'            => self::PROTOCOL,
			'sources'             => $sources,
			'designSystemVersion' => Component_Repository::get_design_system_version(),
		];
	}

	/**
	 * Return configured user remote sources without built-in Bricks libraries.
	 *
	 * @return array
	 */
	public static function get_sources() {
		$sources    = [];
		$legacy_url = trim( (string) Database::get_setting( 'remoteTemplatesUrl', '' ) );

		if ( $legacy_url ) {
			$sources[] = self::normalize_source(
				[
					'name'     => $legacy_url,
					'url'      => $legacy_url,
					'password' => Database::get_setting( 'remoteTemplatesPassword', '' ),
				]
			);
		}

		foreach ( (array) Database::get_setting( 'remoteTemplates', [] ) as $source ) {
			if ( ! is_array( $source ) || empty( $source['url'] ) ) {
				continue;
			}

			$sources[] = self::normalize_source( $source );
		}

		$unique = [];

		foreach ( $sources as $source ) {
			$unique[ $source['id'] ] = $source;
		}

		return array_values( $unique );
	}

	/**
	 * Resolve a configured source by its opaque ID.
	 *
	 * @param string $source_id Source ID.
	 * @return array|\WP_Error
	 */
	public static function get_source( $source_id ) {
		foreach ( self::get_sources() as $source ) {
			if ( $source['id'] === (string) $source_id ) {
				return $source;
			}
		}

		return new \WP_Error(
			'remote_library_source_not_found',
			esc_html__( 'Remote library source not found.', 'bricks' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * REST callback: protocol discovery.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_discovery( $request ) {
		$authorized = self::authorize_shared_access( $request );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$resources = [];

		if ( Database::get_setting( 'myTemplatesAccess', false ) ) {
			$resources['templates'] = [ 'package' => true ];
		}

		if ( Database::get_setting( 'myComponentsAccess', false ) ) {
			$resources['components'] = [
				'catalog' => true,
				'package' => true,
			];
		}

		if ( empty( $resources ) ) {
			return new \WP_Error(
				'remote_library_disabled',
				esc_html__( 'Remote library access is disabled.', 'bricks' ),
				[ 'status' => 403 ]
			);
		}

		return self::response(
			[
				'protocol'  => self::PROTOCOL,
				'version'   => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
				'revision'  => Component_Repository::get_design_system_version(),
				'resources' => $resources,
			]
		);
	}

	/**
	 * REST callback: component catalog.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_components_catalog( $request ) {
		$authorized = self::authorize_resource( $request, 'components' );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$data = Remote_Component_Transfer::build_catalog(
			[
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
				'search'   => $request->get_param( 'search' ),
				'category' => $request->get_param( 'category' ),
			],
			Database::get_setting( 'excludedComponents', [] )
		);

		return self::response( $data );
	}

	/**
	 * REST callback: selected component package.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_component_package( $request ) {
		$authorized = self::authorize_resource( $request, 'components' );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$package = Remote_Component_Transfer::build_package(
			sanitize_key( $request->get_param( 'id' ) ),
			Database::get_setting( 'excludedComponents', [] )
		);

		return is_wp_error( $package ) ? $package : self::response( $package );
	}

	/**
	 * REST callback: dependency-complete template package.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_template_package( $request ) {
		$authorized = self::authorize_resource( $request, 'templates' );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$template_id = absint( $request->get_param( 'id' ) );
		$templates   = Templates::get_templates(
			[
				'post__in'              => [ $template_id ],
				'post_status'           => 'publish',
				'posts_per_page'        => 1,
				'remote_request'        => true,
				'remove_code_signature' => true,
			]
		);

		$template = null;

		foreach ( (array) $templates as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === $template_id ) {
				$template = $candidate;
				break;
			}
		}

		if ( ! $template ) {
			return new \WP_Error( 'remote_library_template_not_found', esc_html__( 'Remote template not found.', 'bricks' ), [ 'status' => 404 ] );
		}

		$elements = [];

		foreach ( [ 'content', 'header', 'footer' ] as $area ) {
			if ( is_array( $template[ $area ] ?? null ) ) {
				$elements = array_merge( $elements, $template[ $area ] );
			}
		}

		$template['templateType']     = $template['type'] ?? '';
		$template_settings            = Helpers::get_template_settings( $template_id );
		$template['templateSettings'] = is_array( $template_settings ) ? $template_settings : [];
		unset( $template['templateSettings']['templateConditions'] );
		$template['themeStyles'] = [];
		$theme_styles            = get_option( BRICKS_DB_THEME_STYLES, [] );

		foreach ( (array) Theme_Styles::set_active_style( $template_id, true ) as $style_id ) {
			if ( ! isset( $theme_styles[ $style_id ] ) ) {
				continue;
			}

			$style       = $theme_styles[ $style_id ];
			$style['id'] = $style_id;
			unset( $style['settings']['conditions'] );
			$template['themeStyles'][] = $style;
		}

		$template['styleManager'] = array_intersect_key(
			(array) get_option( BRICKS_DB_STYLE_MANAGER, [] ),
			array_flip( [ 'htmlFontSize', 'minScreenWidth', 'maxScreenWidth' ] )
		);

		$bundle = Remote_Component_Transfer::build_package_for_elements(
			$elements,
			Database::get_setting( 'excludedComponents', [] ),
			$template
		);

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$package = [
			'manifest'     => [
				'protocol'      => self::PROTOCOL,
				'schemaVersion' => 1,
				'resource'      => 'template',
				'rootId'        => $template_id,
				'sourceVersion' => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '',
			],
			'template'     => $template,
			'components'   => $bundle['components'],
			'dependencies' => $bundle['dependencies'],
		];

		$package['manifest']['checksum'] = Remote_Component_Transfer::package_checksum( $package );

		return self::response( $package );
	}

	/**
	 * Discover remote-library capabilities for a configured source.
	 *
	 * @param string $source_id Source ID.
	 * @return array|\WP_Error
	 */
	public static function discover( $source_id ) {
		return self::fetch( $source_id, '' );
	}

	/**
	 * Fetch a remote component catalog.
	 *
	 * @param string $source_id Source ID.
	 * @param array  $args      Catalog filters.
	 * @param bool   $refresh   Bypass the short server cache.
	 * @return array|\WP_Error
	 */
	public static function fetch_components_catalog( $source_id, $args = [], $refresh = false ) {
		$source = self::get_source( $source_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$cache_key = 'brx_remote_components_' . md5(
			self::PROTOCOL . ':' . get_site_url() . ':' . get_current_user_id() . ':' . $source_id . ':components:' . wp_json_encode( $args )
		);

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return self::normalize_component_catalog_thumbnails( $cached, $source['url'] );
			}
		}

		$supported = self::discover_resource( $source_id, 'components', $refresh );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		$data = self::fetch( $source_id, 'components', $args );

		if ( ! is_wp_error( $data ) ) {
			$data = self::normalize_component_catalog_thumbnails( $data, $source['url'] );
			set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Resolve component screenshots against the configured remote source.
	 *
	 * Screenshot URLs are stored with the source site's hostname. Rebasing the
	 * path prevents migrated or local-development hostnames from leaking into a
	 * catalog consumed through a different configured source URL.
	 *
	 * @param array  $catalog    Component catalog response.
	 * @param string $source_url Configured remote source URL.
	 * @return array
	 */
	private static function normalize_component_catalog_thumbnails( $catalog, $source_url ) {
		if ( ! is_array( $catalog['items'] ?? null ) ) {
			return $catalog;
		}

		foreach ( $catalog['items'] as $index => $item ) {
			$catalog['items'][ $index ]['thumbnail'] = self::resolve_source_asset_url(
				(string) ( $item['thumbnail'] ?? '' ),
				$source_url
			);
		}

		return $catalog;
	}

	/**
	 * Rebase a remote-library asset URL onto its configured source.
	 *
	 * @param string $asset_url  Stored absolute or relative asset URL.
	 * @param string $source_url Configured remote source URL.
	 * @return string
	 */
	private static function resolve_source_asset_url( $asset_url, $source_url ) {
		$asset_url  = trim( (string) $asset_url );
		$source_url = untrailingslashit( esc_url_raw( $source_url ) );

		if ( ! $asset_url || ! $source_url ) {
			return '';
		}

		$source = wp_parse_url( $source_url );

		if ( empty( $source['scheme'] ) || empty( $source['host'] ) ) {
			return '';
		}

		$origin = $source['scheme'] . '://' . $source['host'];

		if ( ! empty( $source['port'] ) ) {
			$origin .= ':' . (int) $source['port'];
		}

		$asset = wp_parse_url( $asset_url );

		if ( is_array( $asset ) && ! empty( $asset['host'] ) ) {
			$resolved = $origin . '/' . ltrim( (string) ( $asset['path'] ?? '' ), '/' );

			if ( isset( $asset['query'] ) && $asset['query'] !== '' ) {
				$resolved .= '?' . $asset['query'];
			}

			return esc_url_raw( $resolved );
		}

		if ( strpos( $asset_url, '/' ) === 0 ) {
			return esc_url_raw( $origin . $asset_url );
		}

		return esc_url_raw( $source_url . '/' . ltrim( $asset_url, '/' ) );
	}

	/**
	 * Fetch a selected remote component package.
	 *
	 * @param string $source_id   Source ID.
	 * @param string $component_id Remote component ID.
	 * @return array|\WP_Error
	 */
	public static function fetch_component_package( $source_id, $component_id ) {
		$supported = self::discover_resource( $source_id, 'components' );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		return self::fetch( $source_id, 'components/' . rawurlencode( $component_id ) );
	}

	/**
	 * Fetch a dependency-complete remote template package.
	 *
	 * @param string $source_id  Source ID.
	 * @param int    $template_id Remote template ID.
	 * @return array|\WP_Error
	 */
	public static function fetch_template_package( $source_id, $template_id ) {
		$supported = self::discover_resource( $source_id, 'templates' );

		if ( is_wp_error( $supported ) ) {
			if ( ! in_array( $supported->get_error_code(), [ 'remote_library_unsupported', 'remote_library_resource_unsupported' ], true ) ) {
				return $supported;
			}

			return new \WP_Error(
				'remote_library_template_legacy_fallback',
				$supported->get_error_message(),
				[
					'status'         => 404,
					'legacyFallback' => true,
				]
			);
		}

		return self::fetch( $source_id, 'templates/' . absint( $template_id ) );
	}

	/**
	 * Negotiate and cache support for one remote-library resource.
	 *
	 * @param string $source_id Source ID.
	 * @param string $resource  Resource name.
	 * @param bool   $refresh   Bypass discovery cache.
	 * @return true|\WP_Error
	 */
	private static function discover_resource( $source_id, $resource, $refresh = false ) {
		$cache_key = 'brx_remote_discovery_' . md5(
			self::PROTOCOL . ':' . get_site_url() . ':' . get_current_user_id() . ':' . $source_id
		);
		$discovery = $refresh ? false : get_transient( $cache_key );

		if ( ! is_array( $discovery ) ) {
			$discovery = self::discover( $source_id );

			if ( is_wp_error( $discovery ) ) {
				return $discovery;
			}

			set_transient( $cache_key, $discovery, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $discovery['resources'][ $resource ] ) ) {
			return new \WP_Error(
				'remote_library_resource_unsupported',
				esc_html__( 'This source does not support the requested remote library resource.', 'bricks' ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}

	/**
	 * Authorize a resource-specific provider request.
	 *
	 * @param \WP_REST_Request $request  REST request.
	 * @param string           $resource Resource name.
	 * @return true|\WP_Error
	 */
	private static function authorize_resource( $request, $resource ) {
		$setting = $resource === 'components' ? 'myComponentsAccess' : 'myTemplatesAccess';

		if ( ! Database::get_setting( $setting, false ) ) {
			return new \WP_Error( 'remote_library_resource_disabled', esc_html__( 'This remote library resource is disabled.', 'bricks' ), [ 'status' => 403 ] );
		}

		return self::authorize_shared_access( $request );
	}

	/**
	 * Authorize shared site/password access for new protocol requests.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	private static function authorize_shared_access( $request ) {
		$site      = self::normalize_site_url( $request->get_header( 'X-Bricks-Remote-Site' ) );
		$key       = (string) $request->get_header( 'X-Bricks-Remote-Key' );
		$password  = (string) Database::get_setting( 'myTemplatesPassword', '' );
		$whitelist = self::normalize_whitelist( Database::get_setting( 'myTemplatesWhitelist', [] ) );

		if ( ! $site ) {
			return new \WP_Error( 'remote_library_missing_site', esc_html__( 'The requesting site is missing.', 'bricks' ), [ 'status' => 401 ] );
		}

		if ( $password && ! hash_equals( $password, $key ) ) {
			return new \WP_Error( 'remote_library_invalid_key', esc_html__( 'The remote library key is invalid.', 'bricks' ), [ 'status' => 401 ] );
		}

		if ( count( $whitelist ) && ! in_array( $site, $whitelist, true ) ) {
			return new \WP_Error( 'remote_library_site_not_allowed', esc_html__( 'This site is not allowed to access the remote library.', 'bricks' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Fetch and validate data from a configured remote-library source.
	 *
	 * @param string $source_id Source ID.
	 * @param string $path      Library path.
	 * @param array  $query     Query parameters.
	 * @return array|\WP_Error
	 */
	private static function fetch( $source_id, $path, $query = [] ) {
		$source = self::get_source( $source_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$base_url = trailingslashit( $source['url'] ) . trailingslashit( rest_get_url_prefix() ) . trailingslashit( Api::API_NAMESPACE ) . 'remote-library';
		$url      = $path ? trailingslashit( $base_url ) . ltrim( $path, '/' ) : $base_url;

		if ( count( $query ) ) {
			$url = add_query_arg( array_filter( $query, [ self::class, 'keep_query_value' ] ), $url );
		}

		$is_local_source = self::is_local_source_url( $url );
		$local_filters   = $is_local_source ? self::allow_local_source_url( $url ) : [];

		try {
			if ( ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'remote_library_invalid_url', esc_html__( 'The configured remote library URL is invalid.', 'bricks' ) );
			}

			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => 30,
					'redirection'         => 3,
					'sslverify'           => ! $is_local_source,
					'limit_response_size' => self::MAX_RESPONSE_SIZE,
					'headers'             => [
						'Accept'                   => 'application/json',
						'X-Bricks-Library-Version' => self::PROTOCOL,
						'X-Bricks-Remote-Site'     => get_site_url(),
						'X-Bricks-Remote-Key'      => (string) $source['password'],
					],
				]
			);
		} finally {
			self::remove_local_source_filters( $local_filters );
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'remote_library_request_failed', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status === 404 && $path === '' ) {
			return new \WP_Error( 'remote_library_unsupported', esc_html__( 'This source does not support the remote library protocol.', 'bricks' ), [ 'status' => 404 ] );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$error_code = is_array( $data ) && ! empty( $data['code'] ) ? sanitize_key( $data['code'] ) : 'remote_library_http_error';
			$message    = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : esc_html__( 'The remote library request failed.', 'bricks' );
			$error_code = $error_code ? $error_code : 'remote_library_http_error';

			return new \WP_Error( $error_code, sanitize_text_field( $message ), [ 'status' => $status ] );
		}

		if ( ! is_array( $data ) || ( $data['protocol'] ?? $data['manifest']['protocol'] ?? '' ) !== self::PROTOCOL ) {
			return new \WP_Error( 'remote_library_invalid_response', esc_html__( 'The remote library returned an invalid response.', 'bricks' ) );
		}

		return $data;
	}

	/**
	 * Check whether a configured source resolves to a local/private IPv4 address.
	 *
	 * Remote sources are saved by an administrator. Local development tools such
	 * as DDEV and Local commonly resolve their project domains to loopback or
	 * private addresses, which WordPress deliberately rejects by default.
	 *
	 * @since 2.4
	 *
	 * @param string $url Source request URL.
	 * @return bool
	 */
	private static function is_local_source_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' ) {
			return false;
		}

		$host = trim( strtolower( $host ), '.' );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $host : gethostbyname( $host );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

		return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Temporarily allow one configured local source through WordPress HTTP safety checks.
	 *
	 * The callbacks only approve the exact configured host and port. WordPress
	 * continues validating schemes, credentials, and redirect destinations.
	 *
	 * @since 2.4
	 *
	 * @param string $url Source request URL.
	 * @return array Registered filter callbacks.
	 */
	private static function allow_local_source_url( $url ) {
		$source_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$source_port = wp_parse_url( $url, PHP_URL_PORT );
		$host_filter = function( $external, $host ) use ( $source_host ) {
			return strtolower( (string) $host ) === $source_host ? true : $external;
		};
		$port_filter = function( $ports, $host ) use ( $source_host, $source_port ) {
			if ( $source_port && strtolower( (string) $host ) === $source_host ) {
				$ports[] = (int) $source_port;
			}

			return array_values( array_unique( $ports ) );
		};

		add_filter( 'http_request_host_is_external', $host_filter, 10, 2 );
		add_filter( 'http_allowed_safe_ports', $port_filter, 10, 2 );

		return [
			'host' => $host_filter,
			'port' => $port_filter,
		];
	}

	/**
	 * Remove temporary local-source HTTP filters.
	 *
	 * @since 2.4
	 *
	 * @param array $filters Registered filter callbacks.
	 * @return void
	 */
	private static function remove_local_source_filters( $filters ) {
		if ( isset( $filters['host'] ) ) {
			remove_filter( 'http_request_host_is_external', $filters['host'], 10 );
		}

		if ( isset( $filters['port'] ) ) {
			remove_filter( 'http_allowed_safe_ports', $filters['port'], 10 );
		}
	}

	/**
	 * Normalize a configured remote source.
	 *
	 * @param array $source Source settings.
	 * @return array
	 */
	private static function normalize_source( $source ) {
		$url  = untrailingslashit( esc_url_raw( $source['url'] ?? '' ) );
		$name = sanitize_text_field( $source['name'] ?? '' );

		if ( ! $name ) {
			$name = $url;
		}

		return [
			'id'       => 'remote-' . substr( hash( 'sha256', strtolower( $url ) ), 0, 12 ),
			'name'     => $name,
			'url'      => $url,
			'password' => (string) ( $source['password'] ?? '' ),
		];
	}

	/**
	 * Normalize a site URL for allowlist comparison.
	 *
	 * @param string $url Site URL.
	 * @return string
	 */
	private static function normalize_site_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );

		return $url ? untrailingslashit( strtolower( $url ) ) : '';
	}

	/**
	 * Normalize stored newline/array allowlist values.
	 *
	 * @param mixed $whitelist Stored allowlist.
	 * @return array
	 */
	private static function normalize_whitelist( $whitelist ) {
		if ( is_string( $whitelist ) ) {
			$whitelist = preg_split( '/\r\n|\r|\n/', $whitelist );
		}

		return array_values( array_unique( array_filter( array_map( [ self::class, 'normalize_site_url' ], (array) $whitelist ) ) ) );
	}

	/**
	 * Preserve zero-like query values while removing empty strings and null.
	 *
	 * @param mixed $value Query value.
	 * @return bool
	 */
	private static function keep_query_value( $value ) {
		return $value !== null && $value !== '';
	}

	/**
	 * Create a REST response with an ETag.
	 *
	 * @param array $data Response data.
	 * @return \WP_REST_Response
	 */
	private static function response( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'ETag', '"' . hash( 'sha256', wp_json_encode( $data ) ) . '"' );

		return $response;
	}
}

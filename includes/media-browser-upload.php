<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Handle media imports initiated from the Builder media browser.
 *
 * Remote files are fetched one at a time so every redirect can be validated
 * before the next request is made. The downloaded bytes are independently
 * inspected because response headers and filename extensions are not trusted.
 *
 * @since 2.4
 */
class Media_Browser_Upload {
	const MAX_REDIRECTS   = 5;
	const MAX_URL_LENGTH  = 4096;
	const REQUEST_TIMEOUT = 30;

	/**
	 * Register upload lifecycle hooks.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'add_attachment', [ __CLASS__, 'assign_requested_folder' ], 20 );
	}

	/**
	 * Assign a Builder upload to the folder selected when it entered the queue.
	 *
	 * The provider owns permission, folder-existence, and multiple-folder semantics.
	 * Request markers keep unrelated WordPress uploads outside this integration.
	 *
	 * @since 2.4
	 *
	 * @param int $attachment_id Created attachment ID.
	 * @return true|false|\WP_Error Assignment result, or false for unrelated requests.
	 */
	public static function assign_requested_folder( $attachment_id ) {
		// The core media upload handlers verify their nonce before add_attachment fires.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$is_builder_upload = isset( $_REQUEST['bricks-is-builder'] ) &&
			is_scalar( $_REQUEST['bricks-is-builder'] ) &&
			(string) wp_unslash( $_REQUEST['bricks-is-builder'] ) === '1';
		$provider_id       = isset( $_REQUEST['bricksMediaFolderProvider'] ) && is_scalar( $_REQUEST['bricksMediaFolderProvider'] )
			? sanitize_key( wp_unslash( $_REQUEST['bricksMediaFolderProvider'] ) )
			: '';
		$folder_id         = isset( $_REQUEST['bricksMediaFolderId'] ) && is_scalar( $_REQUEST['bricksMediaFolderId'] )
			? absint( wp_unslash( $_REQUEST['bricksMediaFolderId'] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $is_builder_upload || ! $provider_id || ! $folder_id ) {
			return false;
		}

		$provider = Media_Folder_Providers::get_active( 'attachment' );

		if ( ! $provider || sanitize_key( $provider->get_id() ) !== $provider_id ) {
			return false;
		}

		return $provider->assign_attachment( absint( $attachment_id ), $folder_id );
	}

	/**
	 * Verify or retry a completed upload's folder assignment without uploading again.
	 *
	 * Verification is read-only unless retry is explicitly requested. The active provider
	 * remains responsible for assignment permissions and multiple-folder semantics.
	 *
	 * @since 2.4.2
	 * @since 2.4.4 Supports custom providers and validates the queued provider before retrying.
	 *
	 * @param int    $attachment_id Existing attachment ID.
	 * @param int    $folder_id     Intended folder, or zero for Uncategorized.
	 * @param bool   $retry         Whether to repair a mismatched assignment.
	 * @param string $provider_id   Captured provider ID, or empty for legacy callers.
	 * @return true|\WP_Error
	 */
	public static function verify_folder_assignment( $attachment_id, $folder_id, $retry = false, $provider_id = '' ) {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $attachment_id ) || get_post_type( $attachment_id ) !== 'attachment' ) {
			return new \WP_Error( 'media_folder_not_allowed', __( 'You are not allowed to move these items.', 'bricks' ) );
		}

		$provider = Media_Folder_Providers::get_active( 'attachment' );
		// Folder IDs are provider-local; never repair a stale queue against another provider.
		if ( ! $provider || ( $provider_id !== '' && sanitize_key( $provider->get_id() ) !== $provider_id ) ) {
			return new \WP_Error( 'media_folder_provider_unavailable', __( 'No media folder provider is available.', 'bricks' ) );
		}

		// Preserve the existing direct membership read, including HappyFiles' multiple-folder behavior.
		if ( is_callable( [ $provider, 'get_item_folder_ids' ] ) ) {
			$folder_ids = $provider->get_item_folder_ids( $attachment_id );
			if ( is_wp_error( $folder_ids ) ) {
				return $folder_ids;
			}
			$matches = $folder_id ? in_array( $folder_id, array_map( 'intval', $folder_ids ), true ) : empty( $folder_ids );
		} else {
			// The base interface guarantees apply_query(), not a membership-reader extension.
			// A list query keeps provider taxonomy constraints active; singular queries skip them.
			// Scope this lookup to the attachment and its stored status; integrations may create private media.
			// Browser search and pagination must not hide a successfully assigned upload.
			$query   = new \WP_Query(
				$provider->apply_query(
					[
						'post__in'       => [ $attachment_id ],
						'post_type'      => 'attachment',
						'post_status'    => get_post_field( 'post_status', $attachment_id, 'raw' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
					],
					$folder_id ? $folder_id : 'unfiled'
				)
			);
			$matches = in_array( $attachment_id, array_map( 'intval', $query->posts ), true );
		}
		if ( $matches ) {
			return true;
		}

		if ( $retry ) {
			$result = $provider->assign_attachment( $attachment_id, $folder_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Read back even a successful provider response; a silent write failure must remain visible.
			// Disable retry for readback so one click cannot trigger repeated writes or another upload.
			return self::verify_folder_assignment( $attachment_id, $folder_id, false, $provider_id );
		}

		return new \WP_Error( 'media_upload_folder_mismatch', __( 'The uploaded file is not in its intended folder.', 'bricks' ) );
	}

	/**
	 * Import one public remote media URL into the WordPress media library.
	 *
	 * Permission checks belong to the calling transport. MIME permissions are
	 * evaluated here because they determine whether the downloaded file is safe
	 * to pass to WordPress' sideload handler.
	 *
	 * @since 2.4
	 *
	 * @param string $url           Remote media URL.
	 * @param int    $parent_id     Optional attachment parent ID.
	 * @param string $accepted_type MIME type or top-level MIME group accepted by the picker.
	 * @param string $filename      Optional filename selected before the remote upload starts.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	public static function import_from_url( $url, $parent_id = 0, $accepted_type = 'any', $filename = '' ) {
		$url = self::validate_remote_url( $url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$accepted_type = self::normalize_accepted_type( $accepted_type );

		if ( ! $accepted_type ) {
			return self::error(
				'media_import_invalid_accepted_type',
				__( 'The requested media type is invalid.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		self::load_media_dependencies();

		$tmp_name = wp_tempnam( self::filename_from_url( $url ) );

		if ( ! $tmp_name ) {
			return self::error(
				'media_import_temp_file_failed',
				__( 'WordPress could not create a temporary file for this import.', 'bricks' ),
				true,
				500,
				[ 'url' => $url ]
			);
		}

		try {
			$download = self::download( $url, $tmp_name );

			if ( is_wp_error( $download ) ) {
				return $download;
			}

			$allowed_mimes = (array) get_allowed_mime_types();
			$file          = self::prepare_file(
				$tmp_name,
				$download['url'],
				$download['contentDisposition'],
				$download['contentType'],
				$allowed_mimes,
				$accepted_type,
				$filename
			);

			if ( is_wp_error( $file ) ) {
				return $file;
			}

			$filename_availability = self::check_import_filename_availability( $file['filename'], $parent_id, $download['url'] );

			if ( is_wp_error( $filename_availability ) ) {
				return $filename_availability;
			}

			$file['filename'] = $filename_availability['filename'];

			$file_array = [
				'name'     => $file['filename'],
				'tmp_name' => $tmp_name,
				'type'     => $file['mime'],
			];

			$attachment_id = media_handle_sideload(
				$file_array,
				absint( $parent_id ),
				null,
				self::get_sideload_post_data( $parent_id )
			);

			if ( is_wp_error( $attachment_id ) ) {
				return self::wrap_upload_error( $attachment_id, $file['filename'], $download['url'] );
			}

			return (int) $attachment_id;
		} finally {
			if ( is_file( $tmp_name ) ) {
				wp_delete_file( $tmp_name );
			}
		}
	}

	/**
	 * Validate a URL as a public HTTP(S) destination.
	 *
	 * This validation is applied to the initial URL and every redirect. All DNS
	 * answers must be public so a hostname cannot opt into a private fallback.
	 *
	 * @since 2.4
	 *
	 * @param mixed $url URL to validate.
	 * @return string|\WP_Error Validated URL or error.
	 */
	public static function validate_remote_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';

		if ( $url === '' || strlen( $url ) > self::MAX_URL_LENGTH || preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return self::error(
				'media_import_invalid_url',
				__( 'Enter a valid public HTTP or HTTPS URL.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( trim( $parts['host'], '[] .' ) ) : '';

		if ( ! is_array( $parts ) || ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {
			return self::error(
				'media_import_invalid_url',
				__( 'Enter a valid public HTTP or HTTPS URL.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::error(
				'media_import_credentials_not_allowed',
				__( 'URLs containing credentials cannot be imported.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], [ 80, 443 ], true ) ) {
			return self::error(
				'media_import_unsafe_port',
				__( 'This URL uses a port that is not allowed for media imports.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		if ( self::is_metadata_hostname( $host ) || ! self::host_resolves_publicly( $host ) ) {
			return self::error(
				'media_import_unsafe_host',
				__( 'Media can only be imported from a public internet host.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		$validated_url = wp_http_validate_url( $url );

		if ( ! $validated_url ) {
			return self::error(
				'media_import_invalid_url',
				__( 'WordPress rejected this URL as unsafe.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		return $validated_url;
	}

	/**
	 * Determine whether an IP address is globally routable.
	 *
	 * PHP's reserved-range filter varies across supported PHP versions and does
	 * not reject every IANA special-purpose range. Apply a stable non-global
	 * allow boundary before relying on the runtime filter as defense in depth.
	 *
	 * @since 2.4
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input is an expected validation failure.

		if ( $packed === false ) {
			return false;
		}

		// IPv4-mapped IPv6 address: validate the embedded IPv4 address.
		if ( strlen( $packed ) === 16 && substr( $packed, 0, 10 ) === str_repeat( "\0", 10 ) && substr( $packed, 10, 2 ) === "\xff\xff" ) {
			$mapped_ipv4 = @inet_ntop( substr( $packed, 12, 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Packed bytes are validated above.

			return is_string( $mapped_ipv4 ) && self::is_public_ip( $mapped_ipv4 );
		}

		$non_global_ranges = strlen( $packed ) === 4
			? [
				'0.0.0.0/8',
				'10.0.0.0/8',
				'100.64.0.0/10',
				'127.0.0.0/8',
				'169.254.0.0/16',
				'172.16.0.0/12',
				'192.0.0.0/24',
				'192.0.2.0/24',
				'192.88.99.0/24',
				'192.168.0.0/16',
				'198.18.0.0/15',
				'198.51.100.0/24',
				'203.0.113.0/24',
				'224.0.0.0/4',
				'240.0.0.0/4',
			]
			: [
				'::/96',
				'64:ff9b:1::/48',
				'100::/64',
				'2001::/23',
				'2001:db8::/32',
				'2002::/16',
				'3fff::/20',
				'5f00::/16',
				'fc00::/7',
				'fe80::/10',
				'fec0::/10',
				'ff00::/8',
			];

		foreach ( $non_global_ranges as $range ) {
			if ( self::packed_ip_is_in_cidr( $packed, $range ) ) {
				return false;
			}
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check a packed IP address against an IPv4 or IPv6 CIDR range.
	 *
	 * @since 2.4
	 *
	 * @param string $packed_ip Packed IP address from inet_pton().
	 * @param string $cidr      Network address and prefix length.
	 * @return bool
	 */
	private static function packed_ip_is_in_cidr( $packed_ip, $cidr ) {
		list( $network, $prefix_length ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$packed_network                  = @inet_pton( $network ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- CIDRs are internal constants, with failure handled below.
		$prefix_length                   = (int) $prefix_length;
		$address_bits                    = strlen( $packed_ip ) * 8;

		if ( $packed_network === false || strlen( $packed_network ) !== strlen( $packed_ip ) || $prefix_length < 0 || $prefix_length > $address_bits ) {
			return false;
		}

		$whole_bytes    = intdiv( $prefix_length, 8 );
		$remaining_bits = $prefix_length % 8;

		if ( $whole_bytes && substr( $packed_ip, 0, $whole_bytes ) !== substr( $packed_network, 0, $whole_bytes ) ) {
			return false;
		}

		if ( ! $remaining_bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;

		return ( ord( $packed_ip[ $whole_bytes ] ) & $mask ) === ( ord( $packed_network[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Normalize a requested picker MIME restriction.
	 *
	 * @since 2.4
	 *
	 * @param mixed $accepted_type Requested accepted type.
	 * @return string Empty when invalid.
	 */
	public static function normalize_accepted_type( $accepted_type ) {
		$accepted_type = strtolower( trim( (string) $accepted_type ) );

		if ( $accepted_type === '' || $accepted_type === '*' || $accepted_type === '*/*' ) {
			return 'any';
		}

		if ( substr( $accepted_type, -2 ) === '/*' ) {
			$accepted_type = substr( $accepted_type, 0, -2 );
		}

		if ( $accepted_type === 'any' || preg_match( '/^[a-z0-9][a-z0-9.+-]*(?:\/[a-z0-9][a-z0-9.+-]*)?$/', $accepted_type ) ) {
			return $accepted_type;
		}

		return '';
	}

	/**
	 * Check whether a MIME type is accepted by the active media picker.
	 *
	 * @since 2.4
	 *
	 * @param string $mime_type     Detected MIME type.
	 * @param string $accepted_type Normalized picker restriction.
	 * @return bool
	 */
	public static function type_matches( $mime_type, $accepted_type ) {
		$mime_type     = self::normalize_mime_type( $mime_type );
		$accepted_type = self::normalize_accepted_type( $accepted_type );

		if ( ! $mime_type || ! $accepted_type ) {
			return false;
		}

		if ( $accepted_type === 'any' ) {
			return true;
		}

		if ( $accepted_type === 'font' ) {
			// Some font formats are detected as generic binary data, so retain WordPress' common fallback MIME.
			return in_array(
				$mime_type,
				[
					'application/font-woff',
					'application/octet-stream',
					'application/vnd.ms-fontobject',
					'application/x-font-opentype',
					'application/x-font-ttf',
					'application/x-font-woff',
					'font/otf',
					'font/ttf',
					'font/woff',
					'font/woff2',
				],
				true
			);
		}

		if ( strpos( $accepted_type, '/' ) === false ) {
			return strpos( $mime_type, $accepted_type . '/' ) === 0;
		}

		return $mime_type === self::normalize_mime_type( $accepted_type );
	}

	/**
	 * Check the filename WordPress would use in the target upload directory.
	 *
	 * This is an advisory preflight. The upload handler must still perform its
	 * own uniqueness check because another request can create the same filename
	 * after this method returns.
	 *
	 * @since 2.4
	 *
	 * @param string $filename  Requested filename.
	 * @param int    $parent_id Optional attachment parent ID.
	 * @return array|\WP_Error Sanitized filename, suggested filename, and collision state.
	 */
	public static function check_filename_availability( $filename, $parent_id = 0 ) {
		$filename = sanitize_file_name( wp_basename( (string) $filename ) );

		if ( $filename === '' ) {
			return self::error(
				'media_upload_invalid_filename',
				__( 'Enter a valid file name.', 'bricks' ),
				false,
				400
			);
		}

		$time = current_time( 'mysql' );
		$post = $parent_id ? get_post( $parent_id ) : null;

		if ( $post && $post->post_type !== 'page' && substr( $post->post_date, 0, 4 ) > 0 ) {
			$time = $post->post_date;
		}

		$uploads = wp_upload_dir( $time );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['path'] ) ) {
			return self::error(
				'media_upload_directory_unavailable',
				__( 'WordPress could not determine the upload directory.', 'bricks' ),
				true,
				500,
				[ 'filename' => $filename ]
			);
		}

		$suggested_filename = wp_unique_filename( $uploads['path'], $filename );

		return [
			'filename'          => $filename,
			'suggestedFilename' => $suggested_filename,
			'collision'         => $suggested_filename !== $filename,
		];
	}

	/**
	 * Reject a collision after the remote bytes have established the final filename.
	 *
	 * The earlier browser preflight cannot know the extension for every URL. This
	 * check therefore remains authoritative for URL imports immediately before
	 * WordPress receives the sideload.
	 *
	 * @since 2.4
	 *
	 * @param string $filename  Final prepared filename.
	 * @param int    $parent_id Optional attachment parent ID.
	 * @param string $url       Final source URL.
	 * @return array|\WP_Error Filename availability or collision error.
	 */
	private static function check_import_filename_availability( $filename, $parent_id, $url ) {
		$availability = self::check_filename_availability( $filename, $parent_id );

		if ( is_wp_error( $availability ) || empty( $availability['collision'] ) ) {
			return $availability;
		}

		return self::error(
			'media_import_filename_collision',
			sprintf(
				/* translators: 1: Requested filename, 2: Suggested filename. */
				__( '%1$s already exists. Rename this file or upload it as %2$s.', 'bricks' ),
				$availability['filename'],
				$availability['suggestedFilename']
			),
			false,
			409,
			[
				'filename'          => $availability['filename'],
				'suggestedFilename' => $availability['suggestedFilename'],
				'url'               => $url,
			]
		);
	}

	/**
	 * Make URL sideload dates follow normal media uploads for page parents.
	 *
	 * WordPress backdates every sideload to its parent, while browser uploads
	 * intentionally use the current date for pages. Supplying the current post
	 * date here keeps the final directory aligned with filename preflight.
	 *
	 * @since 2.4
	 *
	 * @param int $parent_id Optional attachment parent ID.
	 * @return array Attachment post data for media_handle_sideload().
	 */
	private static function get_sideload_post_data( $parent_id ) {
		$post = $parent_id ? get_post( $parent_id ) : null;

		return $post && $post->post_type === 'page'
			? [ 'post_date' => current_time( 'mysql' ) ]
			: [];
	}

	/**
	 * Convert a service error into the stable AJAX payload shape.
	 *
	 * @since 2.4
	 *
	 * @param \WP_Error $error        Import error.
	 * @param string    $fallback_url Original request URL.
	 * @return array
	 */
	public static function error_payload( $error, $fallback_url = '' ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		return [
			'code'              => $error->get_error_code(),
			'message'           => $error->get_error_message(),
			'filename'          => isset( $data['filename'] ) ? (string) $data['filename'] : '',
			'suggestedFilename' => isset( $data['suggestedFilename'] ) ? (string) $data['suggestedFilename'] : '',
			'url'               => isset( $data['url'] ) ? (string) $data['url'] : (string) $fallback_url,
			'retryable'         => ! empty( $data['retryable'] ),
		];
	}

	/**
	 * Download a remote file while validating each redirect destination.
	 *
	 * @since 2.4
	 *
	 * @param string $url      Validated source URL.
	 * @param string $tmp_name Temporary destination path.
	 * @return array|\WP_Error Download response details or error.
	 */
	private static function download( $url, $tmp_name ) {
		$max_bytes   = max( 1, (int) wp_max_upload_size() );
		$current_url = $url;

		for ( $redirect_count = 0; $redirect_count <= self::MAX_REDIRECTS; $redirect_count++ ) {
			// Each transport writes from byte zero, but truncating explicitly keeps this true for custom transports too.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- This is a validated local temporary file used by the streaming HTTP transport.
			if ( file_put_contents( $tmp_name, '' ) === false ) {
				return self::error(
					'media_import_temp_file_failed',
					__( 'WordPress could not write the temporary media file.', 'bricks' ),
					true,
					500,
					[ 'url' => $current_url ]
				);
			}

			$response = wp_safe_remote_get(
				$current_url,
				[
					'timeout'             => self::REQUEST_TIMEOUT,
					'redirection'         => 0,
					'sslverify'           => true,
					'stream'              => true,
					'filename'            => $tmp_name,
					'limit_response_size' => $max_bytes + 1,
				]
			);

			if ( is_wp_error( $response ) ) {
				return self::error(
					'media_import_request_failed',
					sprintf(
						/* translators: %s: HTTP request error message. */
						__( 'The remote file could not be downloaded: %s', 'bricks' ),
						$response->get_error_message()
					),
					true,
					502,
					[ 'url' => $current_url ]
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( $status >= 300 && $status < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );

				if ( is_array( $location ) ) {
					$location = end( $location );
				}

				if ( ! is_string( $location ) || trim( $location ) === '' ) {
					return self::error(
						'media_import_invalid_redirect',
						__( 'The remote server returned a redirect without a destination.', 'bricks' ),
						false,
						502,
						[ 'url' => $current_url ]
					);
				}

				if ( $redirect_count >= self::MAX_REDIRECTS ) {
					return self::error(
						'media_import_too_many_redirects',
						__( 'The remote file redirected too many times.', 'bricks' ),
						false,
						502,
						[ 'url' => $current_url ]
					);
				}

				$redirect_url = \WP_Http::make_absolute_url( trim( $location ), $current_url );
				$redirect_url = self::validate_remote_url( $redirect_url );

				if ( is_wp_error( $redirect_url ) ) {
					return self::error(
						'media_import_unsafe_redirect',
						__( 'The remote file redirected to an unsafe destination.', 'bricks' ),
						false,
						400,
						[ 'url' => $current_url ]
					);
				}

				$current_url = $redirect_url;
				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				$retryable = $status === 0 || $status === 408 || $status === 425 || $status === 429 || $status >= 500;

				return self::error(
					'media_import_http_error',
					sprintf(
						/* translators: %d: HTTP response status code. */
						__( 'The remote server returned HTTP status %d.', 'bricks' ),
						$status
					),
					$retryable,
					502,
					[ 'url' => $current_url ]
				);
			}

			clearstatcache( true, $tmp_name );
			$size = filesize( $tmp_name );

			if ( $size === false ) {
				return self::error(
					'media_import_temp_file_failed',
					__( 'WordPress could not read the downloaded media file.', 'bricks' ),
					true,
					500,
					[ 'url' => $current_url ]
				);
			}

			if ( $size > $max_bytes ) {
				return self::error(
					'media_import_file_too_large',
					sprintf(
						/* translators: %s: Human-readable upload size limit. */
						__( 'The remote file exceeds the maximum upload size of %s.', 'bricks' ),
						size_format( $max_bytes )
					),
					false,
					413,
					[ 'url' => $current_url ]
				);
			}

			if ( $size === 0 ) {
				return self::error(
					'media_import_empty_file',
					__( 'The remote server returned an empty file.', 'bricks' ),
					true,
					502,
					[ 'url' => $current_url ]
				);
			}

			return [
				'url'                => $current_url,
				'contentType'        => wp_remote_retrieve_header( $response, 'content-type' ),
				'contentDisposition' => wp_remote_retrieve_header( $response, 'content-disposition' ),
			];
		}

		return self::error(
			'media_import_too_many_redirects',
			__( 'The remote file redirected too many times.', 'bricks' ),
			false,
			502,
			[ 'url' => $url ]
		);
	}

	/**
	 * Validate downloaded bytes and prepare a WordPress sideload file.
	 *
	 * @since 2.4
	 *
	 * @param string $tmp_name            Downloaded temporary path.
	 * @param string $url                 Final source URL.
	 * @param mixed  $content_disposition Content-Disposition response header.
	 * @param mixed  $content_type        Content-Type response header.
	 * @param array  $allowed_mimes       WordPress MIME map for the current user.
	 * @param string $accepted_type       Active picker MIME restriction.
	 * @param string $requested_filename  Optional filename selected before upload.
	 * @return array|\WP_Error Prepared filename and MIME type or error.
	 */
	private static function prepare_file( $tmp_name, $url, $content_disposition, $content_type, $allowed_mimes, $accepted_type, $requested_filename = '' ) {
		$detected_mime = self::detect_mime_type( $tmp_name );

		if ( ! $detected_mime ) {
			return self::error(
				'media_import_mime_unknown',
				__( 'WordPress could not determine the downloaded file type.', 'bricks' ),
				false,
				400,
				[ 'url' => $url ]
			);
		}

		if ( $detected_mime === 'image/svg+xml' && ! Capabilities::current_user_can_upload_svg() ) {
			return self::error(
				'media_import_svg_not_allowed',
				__( 'You are not allowed to upload SVG files.', 'bricks' ),
				false,
				403,
				[ 'url' => $url ]
			);
		}

		$candidate      = self::filename_from_headers( $content_disposition );
		$candidate      = $candidate ? $candidate : self::filename_from_url( $url );
		$candidate      = sanitize_file_name( $candidate );
		$candidate      = $candidate ? $candidate : 'remote-media';
		$candidate      = self::remove_unsupported_filename_extension( $candidate, $allowed_mimes );
		$extension_type = wp_check_filetype( $candidate, $allowed_mimes );
		$expected_mime  = self::normalize_mime_type( isset( $extension_type['type'] ) ? $extension_type['type'] : '' );

		if ( $expected_mime && ! self::mime_types_match( $detected_mime, $expected_mime ) ) {
			return self::error(
				'media_import_mime_mismatch',
				__( 'The downloaded file content does not match its filename extension.', 'bricks' ),
				false,
				400,
				[
					'filename' => $candidate,
					'url'      => $url,
				]
			);
		}

		if ( ! $expected_mime ) {
			$header_mime = self::normalize_mime_type( $content_type );
			$allowed     = self::find_allowed_mime( $detected_mime, $allowed_mimes, $header_mime );

			if ( ! $allowed ) {
				return self::error(
					'media_import_mime_not_allowed',
					__( 'This file type is not allowed in the media library.', 'bricks' ),
					false,
					400,
					[
						'filename' => $candidate,
						'url'      => $url,
					]
				);
			}

			$expected_mime = $allowed['mime'];
			$candidate    .= '.' . $allowed['extension'];
		}

		if ( ! self::mime_is_allowed( $expected_mime, $allowed_mimes ) ) {
			return self::error(
				'media_import_mime_not_allowed',
				__( 'This file type is not allowed in the media library.', 'bricks' ),
				false,
				400,
				[
					'filename' => $candidate,
					'url'      => $url,
				]
			);
		}

		if ( ! self::type_matches( $expected_mime, $accepted_type ) ) {
			return self::error(
				'media_import_incompatible_type',
				__( 'This file type is not compatible with the current media picker.', 'bricks' ),
				false,
				400,
				[
					'filename' => $candidate,
					'url'      => $url,
				]
			);
		}

		if ( $requested_filename !== '' ) {
			$requested_filename = sanitize_file_name( wp_basename( (string) $requested_filename ) );

			if ( $requested_filename === '' ) {
				return self::error(
					'media_import_invalid_filename',
					__( 'Enter a valid file name.', 'bricks' ),
					false,
					400,
					[ 'url' => $url ]
				);
			}

			$requested_filename  = self::remove_unsupported_filename_extension( $requested_filename, $allowed_mimes );
			$requested_extension = pathinfo( $requested_filename, PATHINFO_EXTENSION );
			$requested_type      = wp_check_filetype( $requested_filename, $allowed_mimes );
			$requested_mime      = self::normalize_mime_type( isset( $requested_type['type'] ) ? $requested_type['type'] : '' );

			if ( $requested_extension && ( ! $requested_mime || ! self::mime_types_match( $expected_mime, $requested_mime ) ) ) {
				return self::error(
					'media_import_filename_type_mismatch',
					__( 'The file name extension does not match the remote file type.', 'bricks' ),
					false,
					400,
					[
						'filename' => $requested_filename,
						'url'      => $url,
					]
				);
			}

			if ( ! $requested_extension ) {
				$detected_extension  = pathinfo( $candidate, PATHINFO_EXTENSION );
				$requested_filename .= $detected_extension ? '.' . $detected_extension : '';
			}

			$candidate = $requested_filename;
		}

		return [
			'filename' => $candidate,
			'mime'     => $expected_mime,
		];
	}

	/**
	 * Detect the MIME type from downloaded bytes.
	 *
	 * @since 2.4
	 *
	 * @param string $filename Downloaded file path.
	 * @return string
	 */
	private static function detect_mime_type( $filename ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This reads a bounded prefix of a validated local temporary file.
		$prefix = file_get_contents( $filename, false, null, 0, 65536 );

		if ( is_string( $prefix ) ) {
			$svg_prefix = preg_replace( '/^\xEF\xBB\xBF/', '', $prefix );
			$svg_prefix = ltrim( $svg_prefix );

			if ( preg_match( '/^(?:<\?xml[^>]*>\s*)?(?:(?:<!--.*?-->)\s*)*(?:<!DOCTYPE\s+svg[^>]*>\s*)?<svg(?:\s|>)/is', $svg_prefix ) ) {
				return 'image/svg+xml';
			}
		}

		if ( function_exists( 'wp_get_image_mime' ) ) {
			$image_mime = self::normalize_mime_type( wp_get_image_mime( $filename ) );

			if ( $image_mime ) {
				return $image_mime;
			}
		}

		$mime_type = '';

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( $finfo ) {
				$mime_type = finfo_file( $finfo, $filename );

				if ( PHP_VERSION_ID < 80100 ) {
					finfo_close( $finfo );
				}
			}
		} elseif ( function_exists( 'mime_content_type' ) ) {
			$mime_type = mime_content_type( $filename );
		}

		return self::normalize_mime_type( $mime_type );
	}

	/**
	 * Find an allowed MIME and canonical extension for detected bytes.
	 *
	 * @since 2.4
	 *
	 * @param string $detected_mime Detected MIME type.
	 * @param array  $allowed_mimes WordPress MIME map.
	 * @param string $header_mime   Normalized response MIME type.
	 * @return array|null
	 */
	private static function find_allowed_mime( $detected_mime, $allowed_mimes, $header_mime = '' ) {
		foreach ( $allowed_mimes as $extensions => $allowed_mime ) {
			$allowed_mime = self::normalize_mime_type( $allowed_mime );

			if ( ! self::mime_types_match( $detected_mime, $allowed_mime ) ) {
				continue;
			}

			if ( $header_mime && $header_mime !== 'application/octet-stream' && ! self::mime_types_match( $detected_mime, $header_mime ) ) {
				continue;
			}

			$extension = explode( '|', (string) $extensions )[0];
			$extension = preg_replace( '/[^a-z0-9]+/i', '', $extension );

			if ( $extension ) {
				return [
					'extension' => strtolower( $extension ),
					'mime'      => $allowed_mime,
				];
			}
		}

		return null;
	}

	/**
	 * Determine whether a MIME is in the current user's WordPress allow-list.
	 *
	 * @since 2.4
	 *
	 * @param string $mime_type     MIME type.
	 * @param array  $allowed_mimes WordPress MIME map.
	 * @return bool
	 */
	private static function mime_is_allowed( $mime_type, $allowed_mimes ) {
		foreach ( $allowed_mimes as $allowed_mime ) {
			if ( self::normalize_mime_type( $allowed_mime ) === self::normalize_mime_type( $mime_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare a sniffed MIME type with an allowed extension MIME type.
	 *
	 * @since 2.4
	 *
	 * @param string $detected_mime MIME detected from bytes.
	 * @param string $expected_mime MIME inferred from an allowed extension.
	 * @return bool
	 */
	private static function mime_types_match( $detected_mime, $expected_mime ) {
		$detected_mime = self::normalize_mime_type( $detected_mime );
		$expected_mime = self::normalize_mime_type( $expected_mime );

		if ( ! $detected_mime || ! $expected_mime ) {
			return false;
		}

		if ( $detected_mime === $expected_mime ) {
			return true;
		}

		$aliases = [
			'audio/mp3'   => 'audio/mpeg',
			'audio/mp4'   => 'audio/mpeg',
			'audio/x-m4a' => 'audio/mpeg',
			'audio/x-wav' => 'audio/wav',
			'image/jpg'   => 'image/jpeg',
			'image/pjpeg' => 'image/jpeg',
			'image/x-png' => 'image/png',
			'video/m4v'   => 'video/mp4',
			'video/x-m4v' => 'video/mp4',
		];

		$detected_mime = isset( $aliases[ $detected_mime ] ) ? $aliases[ $detected_mime ] : $detected_mime;
		$expected_mime = isset( $aliases[ $expected_mime ] ) ? $aliases[ $expected_mime ] : $expected_mime;

		if ( $detected_mime === $expected_mime ) {
			return true;
		}

		$image_heic_mimes = [
			'image/heic',
			'image/heic-sequence',
			'image/heif',
			'image/heif-sequence',
		];

		if ( in_array( $detected_mime, $image_heic_mimes, true ) && in_array( $expected_mime, $image_heic_mimes, true ) ) {
			return true;
		}

		// These registered document formats are ZIP containers on disk.
		$zip_container_mimes = [
			'application/epub+zip',
			'application/vnd.apple.keynote',
			'application/vnd.apple.numbers',
			'application/vnd.apple.pages',
			'application/vnd.ms-excel.addin.macroenabled.12',
			'application/vnd.ms-excel.sheet.binary.macroenabled.12',
			'application/vnd.ms-excel.sheet.macroenabled.12',
			'application/vnd.ms-excel.template.macroenabled.12',
			'application/vnd.ms-powerpoint.addin.macroenabled.12',
			'application/vnd.ms-powerpoint.presentation.macroenabled.12',
			'application/vnd.ms-powerpoint.slide.macroenabled.12',
			'application/vnd.ms-powerpoint.slideshow.macroenabled.12',
			'application/vnd.ms-powerpoint.template.macroenabled.12',
			'application/vnd.ms-word.document.macroenabled.12',
			'application/vnd.ms-word.template.macroenabled.12',
			'application/vnd.oasis.opendocument.presentation',
			'application/vnd.oasis.opendocument.spreadsheet',
			'application/vnd.oasis.opendocument.text',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'application/vnd.openxmlformats-officedocument.presentationml.slide',
			'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
			'application/vnd.openxmlformats-officedocument.presentationml.template',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
		];

		if ( $detected_mime === 'application/zip' && in_array( $expected_mime, $zip_container_mimes, true ) ) {
			return true;
		}

		if ( $detected_mime === 'text/plain' && in_array( $expected_mime, [ 'application/csv', 'text/csv', 'text/plain', 'text/richtext', 'text/tab-separated-values', 'text/vtt' ], true ) ) {
			return true;
		}

		if ( $detected_mime === 'application/csv' && in_array( $expected_mime, [ 'application/csv', 'text/csv', 'text/plain' ], true ) ) {
			return true;
		}

		if ( $detected_mime === 'text/rtf' && in_array( $expected_mime, [ 'application/rtf', 'text/plain', 'text/rtf' ], true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize MIME response aliases and parameters.
	 *
	 * @since 2.4
	 *
	 * @param mixed $mime_type Raw MIME type.
	 * @return string
	 */
	private static function normalize_mime_type( $mime_type ) {
		if ( is_array( $mime_type ) ) {
			$mime_type = end( $mime_type );
		}

		$parts = explode( ';', strtolower( trim( (string) $mime_type ) ), 2 );

		return preg_match( '/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/', $parts[0] ) ? $parts[0] : '';
	}

	/**
	 * Extract a safe filename from a Content-Disposition header.
	 *
	 * @since 2.4
	 *
	 * @param mixed $header Content-Disposition header value.
	 * @return string
	 */
	private static function filename_from_headers( $header ) {
		if ( is_array( $header ) ) {
			$header = end( $header );
		}

		$header = (string) $header;
		$name   = '';

		if ( preg_match( "/filename\*\s*=\s*UTF-8''([^;]+)/i", $header, $match ) ) {
			$name = rawurldecode( trim( $match[1], " \t\n\r\0\x0B\"'" ) );
		} elseif ( preg_match( '/filename\s*=\s*(?:"([^"]+)"|([^;]+))/i', $header, $match ) ) {
			$name = ! empty( $match[1] ) ? $match[1] : $match[2];
			$name = trim( $name, " \t\n\r\0\x0B\"'" );
		}

		$name = str_replace( '\\', '/', $name );

		return $name ? wp_basename( $name ) : '';
	}

	/**
	 * Extract a filename candidate from a URL path.
	 *
	 * @since 2.4
	 *
	 * @param string $url Remote URL.
	 * @return string
	 */
	private static function filename_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '';
		$name = $path ? wp_basename( str_replace( '\\', '/', $path ) ) : '';

		return $name ? sanitize_file_name( $name ) : 'remote-media';
	}

	/**
	 * Remove an unsupported trailing extension from a remote filename candidate.
	 *
	 * Download endpoints commonly end in script or routing suffixes even when the
	 * response is valid media. The sniffed MIME can then supply the allowed file
	 * extension instead of treating that URL suffix as the media type.
	 *
	 * @since 2.4
	 *
	 * @param string $filename      Sanitized filename candidate.
	 * @param array  $allowed_mimes WordPress MIME map for the current user.
	 * @return string
	 */
	private static function remove_unsupported_filename_extension( $filename, $allowed_mimes ) {
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		if ( ! $extension || ! empty( wp_check_filetype( $filename, $allowed_mimes )['type'] ) ) {
			return $filename;
		}

		$basename = substr( $filename, 0, -1 * ( strlen( $extension ) + 1 ) );

		return $basename !== '' ? $basename : $filename;
	}

	/**
	 * Determine whether a hostname is reserved for local or metadata services.
	 *
	 * @since 2.4
	 *
	 * @param string $host Normalized hostname.
	 * @return bool
	 */
	private static function is_metadata_hostname( $host ) {
		$blocked = [
			'instance-data',
			'instance-data.ec2.internal',
			'localhost',
			'metadata',
			'metadata.azure.internal',
			'metadata.google.internal',
			'metadata.goog',
		];

		if ( in_array( $host, $blocked, true ) ) {
			return true;
		}

		return preg_match( '/(?:^|\.)(?:internal|local|localhost)$/', $host ) === 1;
	}

	/**
	 * Require every DNS answer for a host to be globally routable.
	 *
	 * @since 2.4
	 *
	 * @param string $host Normalized hostname or IP address.
	 * @return bool
	 */
	private static function host_resolves_publicly( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}

		$ips = [];

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failed DNS lookups are handled as unsafe below.

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}

					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$a_records = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failed DNS lookups are handled as unsafe below.

		if ( is_array( $a_records ) ) {
			$ips = array_merge( $ips, $a_records );
		}

		$ips = array_values( array_unique( array_filter( $ips ) ) );

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Wrap a WordPress sideload error with stable retry metadata.
	 *
	 * @since 2.4
	 *
	 * @param \WP_Error $error    WordPress sideload error.
	 * @param string    $filename Prepared filename.
	 * @param string    $url      Final source URL.
	 * @return \WP_Error
	 */
	private static function wrap_upload_error( $error, $filename, $url ) {
		$non_retryable_codes = [
			'empty_filename',
			'filetype_and_ext_mismatch',
			'upload_mimes',
		];
		$retryable           = ! in_array( $error->get_error_code(), $non_retryable_codes, true );

		return self::error(
			'media_import_upload_failed',
			$error->get_error_message(),
			$retryable,
			$retryable ? 500 : 400,
			[
				'filename' => $filename,
				'url'      => $url,
			]
		);
	}

	/**
	 * Create a structured import error.
	 *
	 * @since 2.4
	 *
	 * @param string $code      Error code.
	 * @param string $message   User-facing error message.
	 * @param bool   $retryable Whether retry can reasonably succeed.
	 * @param int    $status    Suggested HTTP response status.
	 * @param array  $context   Extra error context.
	 * @return \WP_Error
	 */
	private static function error( $code, $message, $retryable, $status, $context = [] ) {
		return new \WP_Error(
			$code,
			$message,
			array_merge(
				[
					'retryable' => (bool) $retryable,
					'status'    => (int) $status,
				],
				$context
			)
		);
	}

	/**
	 * Load WordPress media helpers used by the importer.
	 *
	 * @since 2.4
	 *
	 * @return void
	 */
	private static function load_media_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}

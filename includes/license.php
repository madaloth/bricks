<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class License {
	// Tracks whether the active key came from BRICKS_LICENSE_KEY so UI and persistence can avoid treating it like a manually saved DB key.
	public static $license_key            = '';
	public static $license_key_is_defined = false;
	public static $license_status         = '';
	public static $remote_base_url        = 'https://my.bricksbuilder.io/api/commerce/';

	public function __construct() {
		self::$license_key = self::get_license_key();

		add_filter( 'pre_set_site_transient_update_themes', [ $this, 'check_for_update' ] );
		add_filter( 'http_response', [ $this, 'check_theme_download_response' ], 10, 3 );

		add_action( 'wp_ajax_bricks_activate_license', [ $this, 'activate_license' ] );
		add_action( 'wp_ajax_bricks_deactivate_license', [ $this, 'deactivate_license' ] );
		add_action( 'wp_ajax_bricks_delete_db_license_key', [ $this, 'delete_db_license_key' ] );
		add_action( 'wp_ajax_bricks_revalidate_license', [ $this, 'revalidate_license' ] );

		add_action( 'admin_notices', [ $this, 'admin_notices_license_activation' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices_license_mismatch' ] );
	}

	/**
	 * Get the active license key.
	 *
	 * Prefers the BRICKS_LICENSE_KEY PHP constant when defined.
	 *
	 * @since 2.4
	 *
	 * @return string|false
	 */
	public static function get_license_key() {
		$db_license_key = self::get_db_license_key();

		$constant_license_key = self::get_constant_license_key();

		if ( $constant_license_key ) {
			// Treat BRICKS_LICENSE_KEY as the source of truth so blueprints/cloned sites can manage the key outside the database.
			self::$license_key_is_defined = true;

			return $constant_license_key;
		}

		self::$license_key_is_defined = false;

		return $db_license_key;
	}

	/**
	 * Get the normalized license key defined by BRICKS_LICENSE_KEY.
	 *
	 * @since 2.4
	 *
	 * @return string|false
	 */
	public static function get_constant_license_key() {
		if ( ! defined( 'BRICKS_LICENSE_KEY' ) ) {
			return false;
		}

		return self::normalize_license_key( constant( 'BRICKS_LICENSE_KEY' ) );
	}

	/**
	 * Get the normalized license key stored in the database.
	 *
	 * @since 2.4
	 *
	 * @return string|false
	 */
	public static function get_db_license_key() {
		return self::normalize_license_key( get_option( 'bricks_license_key', false ) );
	}

	/**
	 * Normalize a license key value.
	 *
	 * @since 2.4
	 *
	 * @param mixed $license_key License key value.
	 *
	 * @return string|false
	 */
	private static function normalize_license_key( $license_key ) {
		if ( ! is_scalar( $license_key ) ) {
			return false;
		}

		$license_key = trim( (string) $license_key );
		$license_key = $license_key ? html_entity_decode( $license_key ) : '';
		$license_key = $license_key ? wp_strip_all_tags( $license_key ) : '';
		$license_key = trim( $license_key );

		if ( ! $license_key ) {
			return false;
		}

		return $license_key;
	}

	/**
	 * Delete locally stored license status.
	 *
	 * @since 2.4
	 */
	private static function delete_license_status() {
		// Keep the status and its key marker in sync; one without the other can make a stale status look valid.
		delete_transient( 'bricks_license_status' );
		delete_option( 'bricks_license_status_key_hash' );
	}

	/**
	 * Check remotely if newer version of Bricks is available
	 *
	 * @param string $transient Transient for WordPress theme updates.
	 */
	public static function check_for_update( $transient ) {
		// 'checked' is an array with all installed themes and their version numbers
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$license_key = self::$license_key;

		if ( ! $license_key ) {
			return $transient;
		}

		// Installed theme data
		$theme_data        = wp_get_theme();
		$installed_version = $theme_data->Version; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Check if Bricks is parent theme (i.e. Bricks child theme in use)
		if ( wp_get_theme()->parent() ) {
			$installed_version = wp_get_theme()->parent()->get( 'Version' );
		}

		// Build theme update request URL with license_key and domain parameters
		$update_url = add_query_arg(
			[
				'license_key'       => $license_key,
				'domain'            => get_site_url(),
				'time'              => time(), // To avoid caching remote response
				'installed_version' => $installed_version,
			],
			self::$remote_base_url . 'download/get_update_data'
		);

		$request = Helpers::remote_get( $update_url );

		// Check if remote GET request has been successful (better than using is_wp_error)
		if ( wp_remote_retrieve_response_code( $request ) !== 200 ) {
			return $transient;
		}

		$request = json_decode( wp_remote_retrieve_body( $request ), true );

		// Check remotely if newer version of Bricks is available
		$latest_version          = isset( $request['new_version'] ) ? $request['new_version'] : $installed_version;
		$newer_version_available = version_compare( $latest_version, $installed_version, '>' );

		if ( $newer_version_available ) {
			// Save Bricks-specific update data in transient
			$transient->response['bricks'] = $request;
		}

		return $transient;
	}

	/**
	 * Surface download API errors before WordPress attempts to unpack them as ZIP files.
	 *
	 * @since 2.4.2
	 *
	 * @param array  $response HTTP response.
	 * @param array  $args     HTTP request arguments.
	 * @param string $url      Requested URL.
	 * @return array|\WP_Error
	 */
	public static function check_theme_download_response( $response, $args, $url ) {
		if ( strtok( $url, '?' ) !== self::$remote_base_url . 'download/get_theme' || empty( $args['stream'] ) || empty( $args['filename'] ) ) {
			return $response;
		}

		if ( ! is_readable( $args['filename'] ) ) {
			return $response;
		}

		// Downloads are streamed to disk, leaving the response body empty. Bound the read for real ZIP packages.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local streamed download, not a remote URL.
		$body = file_get_contents( $args['filename'], false, null, 0, 64 * 1024 );
		$data = is_string( $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $data ) || ! isset( $data['error'] ) || ! is_string( $data['error'] ) ) {
			return $response;
		}

		$message = trim( wp_kses( $data['error'], [ 'a' => [ 'href' => true ] ] ) );

		if ( $message === '' ) {
			return $response;
		}

		// download_url() removes its temporary file when the HTTP request returns WP_Error.
		return new \WP_Error( 'bricks_theme_download_error', $message );
	}

	/**
	 * Check if a license API response indicates the remote server is temporarily unavailable.
	 *
	 * @param array|\WP_Error $response      Remote response object.
	 * @param int             $response_code Remote response status code.
	 * @param mixed           $response_body Decoded remote response body.
	 *
	 * @return bool
	 */
	private static function remote_response_is_unavailable( $response, $response_code, $response_body ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		if ( in_array( $response_code, [ 408, 429 ], true ) || $response_code >= 500 ) {
			return true;
		}

		return $response_code === 200 && $response_body === null;
	}

	/**
	 * Check if the current request may preserve a locally stored license during a temporary remote outage.
	 *
	 * @param string $license_key License key being validated.
	 *
	 * @return bool
	 */
	private static function can_preserve_license_on_remote_error( $license_key ) {
		if ( ! self::$license_key ) {
			return false;
		}

		return hash_equals( (string) self::$license_key, (string) $license_key );
	}

	/**
	 * Check license status when loading builder
	 *
	 * @see template_redirect
	 */
	public static function license_is_valid() {
		// Skip license check for builder iframe (check happens in builder panel)
		if ( bricks_is_builder_iframe() ) {
			return true;
		}

		// Return: No license key found in db options table or BRICKS_LICENSE_KEY constant
		if ( ! self::$license_key ) {
			return false;
		}

		// Valid license status'
		return in_array(
			self::get_license_status(),
			[
				'active',       // Active license
				'processed',    // Order processed
				'canceled',     // Subscription cancelled, but not 'refunded' (@since 2.0)
				'past_due',     // Payment past due (subscription)
				'error_remote', // Remote server error (bricksbuilder.io)
			],
			true
		);
	}

	/**
	 * Get license status (stored locally in transient: bricks_license_status)
	 *
	 * If transient has expired (after 168h, or after 48h for temporary remote errors) then get it remotely from Bricks server.
	 *
	 * @return array
	 */
	public static function get_license_status() {
		$license_key = self::$license_key;

		if ( ! $license_key ) {
			return false;
		}

		// Check license transient (temporary remote errors expire after 48 hours, all other statuses after 168 hours)
		$transient_timeout          = get_option( '_transient_timeout_bricks_license_status' );
		$transient_timeout_in_hours = ( intval( $transient_timeout ) - time() ) / 60 / 60;

		$license_status = get_transient( 'bricks_license_status' );

		// The status transient does not include the license key it was validated for.
		// Pair it with a key hash to avoid reusing a stale "active" status after the active license key changes.
		if ( false !== $license_status ) {
			$license_status_key_hash = get_option( 'bricks_license_status_key_hash', false );
			$current_key_hash        = wp_hash( (string) $license_key );

			// A stored hash exists, but belongs to a different license key: force a fresh remote check.
			if ( $license_status_key_hash && ! hash_equals( (string) $license_status_key_hash, (string) $current_key_hash ) ) {
				self::delete_license_status();
				$license_status = false;
				// Existing installs may have a cached status from before we stored the key hash.
			} elseif ( ! $license_status_key_hash && self::$license_key_is_defined ) {
				$db_license_key = self::normalize_license_key( get_option( 'bricks_license_key', false ) );

				// Backfill the hash for older cached statuses only when the DB key matches the current key.
				if ( hash_equals( (string) $db_license_key, (string) $license_key ) ) {
					update_option( 'bricks_license_status_key_hash', $current_key_hash, false );
				} else {
					self::delete_license_status();
					$license_status = false;
				}
			}
		}

		// No valid transient found: Get license status remotely
		if ( ! $transient_timeout || $transient_timeout_in_hours > 168 || false === $license_status ) {
			self::delete_license_status();

			$url = add_query_arg(
				[
					'license_key' => $license_key,
					'site'        => get_site_url(),
					'version'     => BRICKS_VERSION,
					'time'        => time(), // Avoid getting a cached remote response
				],
				self::$remote_base_url . 'license/get_status'
			);

			$response      = Helpers::remote_get( $url );
			$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

			// Get status from remote response body
			$response_body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

			if ( is_array( $response_body ) && isset( $response_body['status'] ) ) {
				$license_status = $response_body['status'];
			}

			// Treat unavailable license server responses as a temporary remote error.
			if ( ! $license_status && self::remote_response_is_unavailable( $response, $response_code, $response_body ) ) {
				$license_status = 'error_remote';
			}

			// Save license status in transient.
			self::set_license_status( $license_status );
		}

		// Invalid license: Activate license on server (avoid having to deactivate & reactivate license for cloned sites, etc.)
		$invalid_license = ! in_array(
			$license_status,
			[
				'active',       // Active license
				'processed',    // Order processed
				'past_due',     // Payment past due (subscription)
				'error_remote', // Remote server error (bricksbuilder.io)
			],
			true
		);

		if ( $invalid_license ) {
			$license_status = self::activate_license();

			return $license_status;
		}

		return $license_status;
	}

	/**
	 * Save license status in transient.
	 *
	 * Temporary remote errors expire after 48 hours. All other statuses expire after 168 hours.
	 */
	public static function set_license_status( $license_status, $license_key = null ) {
		$expiration_time = $license_status === 'error_remote' ? 48 * HOUR_IN_SECONDS : 168 * HOUR_IN_SECONDS;
		$license_key     = $license_key ? $license_key : self::$license_key;

		set_transient( 'bricks_license_status', $license_status, $expiration_time );
		// Store only a hash, not the raw key, so constant-defined licenses can keep the real key out of the database.
		update_option( 'bricks_license_status_key_hash', wp_hash( (string) $license_key ), false );
	}

	/**
	 * Activate license under "Bricks > License" (AJAX call on "Activate license" click)
	 *
	 * Also runs via PHP in 'get_license_status' to avoid having to deactivate & reactivate license (when cloning staging site, etc.)
	 *
	 * @return array
	 */
	public static function activate_license() {
		$license_key = self::$license_key;
		$is_ajax     = bricks_is_ajax_call();

		if ( $is_ajax ) {
			Ajax::verify_nonce( 'bricks-nonce-admin' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
			}

			if ( ! empty( $_POST['licenseKey'] ) ) {
				$license_key = sanitize_text_field( $_POST['licenseKey'] );
			}
		}

		$license_key = self::normalize_license_key( $license_key );

		// Return: No license key found/submitted
		if ( ! $license_key ) {
			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => esc_html__( 'Invalid license key.', 'bricks' ) ] );
			} else {
				return;
			}
		}

		// Send HTTP request to activate license
		$response      = Helpers::remote_post(
			self::$remote_base_url . 'license/activate_license',
			[
				'sslverify' => false,
				'timeout'   => 30,
				'body'      => [
					'license_key' => $license_key,
					'site'        => get_site_url(),
					'version'     => BRICKS_VERSION,
				],
			]
		);
		$response_code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		// Keep the saved license usable if the license server is temporarily unavailable.
		if ( self::remote_response_is_unavailable( $response, $response_code, $response_body ) ) {
			if ( self::can_preserve_license_on_remote_error( $license_key ) ) {
				self::set_license_status( 'error_remote', $license_key );

				if ( $is_ajax ) {
					wp_send_json_success(
						[
							'message' => esc_html__( 'The license server temporarily unavailable. Your saved license is used locally and validation will occur within 48 hours. You can also re-validate the license manually.', 'bricks' ),
							'status'  => 'error_remote',
						]
					);
				} else {
					return 'error_remote';
				}
			}

			if ( $is_ajax ) {
				wp_send_json_error(
					[
						'message' => esc_html__( 'License server temporarily unavailable. Please try activating your license again later.', 'bricks' ),
					]
				);
			} else {
				return;
			}
		}

		$license_status = false;

		if ( $response_code !== 200 ) {
			// Handle CloudFlare 403 "Forbidden" response
			if ( $response_code === 403 ) {
				$license_status = 'active';
			}

			// Handle Imunify360 415 "Unsupported Media Type" response (@since 2.0.2)
			elseif ( $response_code === 415 ) {
				$license_status = 'active';
			}

			// Return error
			elseif ( $is_ajax ) {
				wp_send_json_error(
					[
						'code'     => $response_code,
						'message'  => wp_remote_retrieve_response_message( $response ),
						'response' => $response,
					]
				);
			} else {
				return;
			}
		}

		if ( is_array( $response_body ) && isset( $response_body['status'] ) ) {
			$license_status = $response_body['status'];
		}

		// Return remote error
		if ( is_array( $response_body ) && isset( $response_body['type'] ) && $response_body['type'] === 'error' && isset( $response_body['message'] ) ) {
			if ( $is_ajax ) {
				wp_send_json_error(
					[
						'message'  => wp_kses_post( $response_body['message'] ),
						'response' => $response_body,
						'code'     => $response_code,
						'status'   => 'no_license',
					]
				);
			} else {
				return;
			}
		}

		// Return if no license status was sent back
		if ( ! $license_status ) {
			if ( $is_ajax ) {
				wp_send_json_error(
					[
						'message'  => esc_html__( 'No license for provided license key found.', 'bricks' ),
						'response' => $response_body,
						'code'     => $response_code,
						'status'   => 'no_license',
					]
				);
			} else {
				return;
			}
		}

		// Save manually submitted keys, but do not mirror BRICKS_LICENSE_KEY into the database.
		if ( ! self::$license_key_is_defined ) {
			update_option( 'bricks_license_key', $license_key );
		}

		// Save license status in transient.
		self::set_license_status( $license_status, $license_key );

		if ( $is_ajax ) {
			wp_send_json_success(
				[
					'message' => esc_html__( 'License activated.', 'bricks' ),
					'status'  => $license_status,
				]
			);
		} else {
			return $license_status;
		}
	}

	/**
	 * Deactivate license
	 *
	 * @return void
	 *
	 * @since 1.0
	 */
	public static function deactivate_license() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		// Only a user with full access can deactivate the license (@since 1.5.4)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		if ( self::$license_key_is_defined ) {
			wp_send_json_error( [ 'message' => esc_html__( 'License key is defined by BRICKS_LICENSE_KEY. Remove the constant before deactivating the license.', 'bricks' ) ] );
		}

		// Deactivate license
		$response = Helpers::remote_post(
			self::$remote_base_url . 'license/deactivate_license',
			[
				'sslverify' => false,
				'timeout'   => 30,
				'body'      => [
					'license_key' => self::$license_key,
					'site'        => get_site_url(),
					'version'     => BRICKS_VERSION,
				],
			]
		);

		delete_option( 'bricks_license_key' );
		self::delete_license_status();
	}

	/**
	 * Delete saved database license key while BRICKS_LICENSE_KEY remains active.
	 *
	 * @return void
	 *
	 * @since 2.4
	 */
	public static function delete_db_license_key() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		if ( ! self::$license_key_is_defined ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No active BRICKS_LICENSE_KEY value found.', 'bricks' ) ] );
		}

		if ( ! self::get_db_license_key() ) {
			wp_send_json_success( [ 'message' => esc_html__( 'No saved database license key found.', 'bricks' ) ] );
		}

		delete_option( 'bricks_license_key' );

		wp_send_json_success( [ 'message' => esc_html__( 'Saved database license key removed.', 'bricks' ) ] );
	}

	/**
	 * Re-validate license
	 *
	 * Clears the license status transient and re-validates the license without deactivating it.
	 *
	 * @since 2.1.3
	 * @return void
	 */
	public static function revalidate_license() {
		Ajax::verify_nonce( 'bricks-nonce-admin' );

		// Only a user with full access can re-validate the license
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'verify_request: Sorry, you are not allowed to perform this action.' );
		}

		// Return: No license key found
		if ( ! self::$license_key ) {
			wp_send_json_error( [ 'message' => esc_html__( 'No license key found.', 'bricks' ) ] );
		}

		// Clear the license status transient to force re-validation
		self::delete_license_status();

		// Re-validate the license using the existing activate_license logic
		$license_status = self::activate_license();

		// If activate_license returns a status (non-AJAX call), send it as success
		if ( $license_status ) {
			wp_send_json_success(
				[
					'message' => esc_html__( 'License re-validated successfully.', 'bricks' ),
					'status'  => $license_status,
				]
			);
		}
	}

	/**
	 * Admin notice to activate license
	 *
	 * @return null/string
	 */
	public static function admin_notices_license_activation() {
		// Show license key admin notice only to user roles which are allowed to use the builder
		if ( ! Capabilities::current_user_can_use_builder() ) {
			return;
		}

		// Don't show license admin notice on license page itself
		if ( get_current_screen()->id === 'bricks_page_bricks-license' ) {
			return;
		}

		// Check if license has been activated by checking for license key
		$license_key = self::$license_key;

		// Check: License activated (local)
		if ( isset( $license_key ) && ! empty( $license_key ) ) {
			return;
		}
		?>
		<div class="notice notice-info notice-license-activation">
			<div class="content-wrapper">
				<h4 class="title">
					<?php
					// translators: %s is the name of the theme.
					echo sprintf( esc_html__( 'Welcome to %s', 'bricks' ), 'Bricks' );
					?>
				</h4>
				<p><?php echo esc_html__( 'Activate your license to edit with Bricks, receive one-click updates, and access to all community templates.', 'bricks' ); ?></p>
			</div>

			<a class="button button-primary" href="<?php echo esc_url( BRICKS_ADMIN_PAGE_URL_LICENSE ); ?>"><?php esc_html_e( 'Activate license', 'bricks' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Admin notice to activate license
	 *
	 * @return null/string
	 */
	public static function admin_notices_license_mismatch() {
		// Show license key admin notice only to user roles which are allowed to use the builder
		if ( ! Capabilities::current_user_can_use_builder() ) {
			return;
		}

		// Don't show license admin notice on license page itself
		if ( get_current_screen()->id === 'bricks_page_bricks-license' ) {
			return;
		}

		// Check for license issue status.
		$license_status = get_transient( 'bricks_license_status' );

		$license_notice_class      = 'notice-error';
		$license_error_title       = false;
		$license_error_description = false;

		switch ( $license_status ) {
			case 'license_key_invalid':
				$license_error_title       = esc_html__( 'Error: Invalid license key', 'bricks' );
				$license_error_description = esc_html__( 'Your provided license key is invalid. Please deactivate and then reactivate your license.', 'bricks' );
				break;

			case 'website_inactive':
				$license_error_title       = esc_html__( 'Error: License mismatch', 'bricks' );
				$license_error_description = esc_html__( 'Your website does not match your license key. Please deactivate and then reactivate your license.', 'bricks' );
				break;

			case 'error_remote':
				$license_notice_class      = 'notice-warning';
				$license_error_title       = esc_html__( 'License server unavailable', 'bricks' );
				$license_error_description = esc_html__( 'The license server temporarily unavailable. Your saved license is used locally and validation will occur within 48 hours. You can also re-validate the license manually.', 'bricks' );
				break;
		}

		if ( $license_error_title && $license_error_description ) {
			?>
		<div class="notice <?php echo esc_attr( $license_notice_class ); ?> notice-license-mismatch">
			<div class="content-wrapper">
				<h4 class="title">Bricks: <?php echo esc_html( $license_error_title ); ?></h4>
				<p><?php echo esc_html( $license_error_description ); ?></p>
				<p><a href="<?php echo esc_url( BRICKS_ADMIN_PAGE_URL_LICENSE ); ?>">Bricks &gt; <?php esc_html_e( 'License', 'bricks' ); ?></a></p>
			</div>
		</div>
			<?php
		}
	}

}

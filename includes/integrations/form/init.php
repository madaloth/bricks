<?php
namespace Bricks\Integrations\Form;

use Bricks\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Init {
	/**
	 * Formatting a submitted rich text value keeps in HTML output (no links, media or attributes)
	 *
	 * #123pt1nyhyh
	 *
	 * @since 2.4.3
	 */
	const RICHTEXT_ALLOWED_TAGS = [
		'p'          => [],
		'br'         => [],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'u'          => [],
		's'          => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'blockquote' => [],
	];

	/**
	 * Uploads owned by this submission, grouped by the configured input name.
	 *
	 * @since 2.4.3 Tracks each successful write immediately for failure cleanup.
	 *
	 * @var array|null
	 */
	protected $uploaded_files;
	protected $form_settings;
	protected $form_fields;
	protected $form_id;

	/**
	 * Saved or rendered element supplying the current form settings.
	 *
	 * Request IDs may contain unverified component suffixes. Keep the resolved
	 * element so submission storage and limits use its server-side identity.
	 *
	 * @since 2.4.3
	 * @var array
	 */
	protected $form_element = [];
	protected $post_id;
	protected $results;
	protected $update_post_executed = false;

	/**
	 * Whether file storage and response filters completed successfully.
	 *
	 * Shutdown cleanup must preserve files intentionally retained by a successful form.
	 *
	 * @since 2.4.3
	 *
	 * @var bool
	 */
	protected $submission_successful = false;

	/**
	 * Post ID the form was rendered for, if the submitted context carries a valid 'post' signature.
	 *
	 * @since 2.4.3
	 *
	 * @var int
	 */
	protected $verified_post_target = 0;

	/**
	 * Whether this instance has registered its shutdown cleanup callback.
	 *
	 * @since 2.4.3
	 *
	 * @var bool
	 */
	protected $upload_cleanup_registered = false;

	/**
	 * Paths referenced by an action that has already persisted its data.
	 *
	 * Form actions are independent writes: a later error cannot roll back a saved
	 * submission or post, so its persistent uploads must survive failure cleanup.
	 *
	 * @since 2.4.3
	 *
	 * @var array
	 */
	protected $retained_uploads = [];

	/**
	 * Post supplying the resolved settings (0: component definition; null: unresolved ownership)
	 *
	 * #123pt1nyhye
	 *
	 * @since 2.4.3
	 *
	 * @var int|null
	 */
	protected $settings_source_id = null;

	/**
	 * Rebuilt query chain kept alive while form actions render ACF dynamic data.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @var \Bricks\Query[]
	 */
	protected $query_context = [];

	protected static $submission_url_params = [];

	public function __construct() {
		add_action( 'wp_ajax_bricks_form_submit', [ $this, 'form_submit' ] );
		add_action( 'wp_ajax_nopriv_bricks_form_submit', [ $this, 'form_submit' ] );
	}

	/**
	 * Element Form: Submit
	 *
	 * @since 1.0
	 */
	public function form_submit() {
		// Return: Invalid form nonce
		if ( ! check_ajax_referer( 'bricks-nonce-form', 'nonce', false ) ) {
			wp_send_json_error(
				[
					'action'  => '',
					'code'    => 'invalid_nonce', // special code for invalid nonce (@since 1.9.6)
					'type'    => 'error',
					'message' => esc_html__( 'Invalid form token.', 'bricks' ),
				]
			);
		}

		$form_element_id   = isset( $_POST['formId'] ) ? sanitize_text_field( $_POST['formId'] ) : '';
		$form_component_id = isset( $_POST['componentId'] ) ? sanitize_text_field( $_POST['componentId'] ) : ''; // (@since 2.1)
		$post_id           = isset( $_POST['postId'] ) ? absint( $_POST['postId'] ) : 0;

		/**
		 * Preserve only signed or readable post context. ACF row queries replace
		 * this with the containing page ID after their signed context is restored.
		 *
		 * #86cazz5mc; @since 2.3.11
		 * #123pt1nyhyk; @since 2.4.3
		 */
		$loop_post_id = $this->validate_loop_post( $post_id, $form_element_id );

		/**
		 * Switch language based on the form submission data
		 *
		 * @since 2.2
		 */
		$locale = isset( $_POST['lang'] ) ? sanitize_text_field( $_POST['lang'] ) : '';
		if ( $locale ) {
			switch_to_locale( $locale );
		}

		/**
		 * Support for passing the current language code from the front end (e.g. Polylang, WPML) to get the correct element settings based on the current language. Especially if the element is inside a popup template.
		 * #86c94gr3q
		 *
		 * @since 2.3
		 */
		$language_code = isset( $_POST['langCode'] ) ? sanitize_key( $_POST['langCode'] ) : '';
		if ( $language_code ) {
			\Bricks\Database::set_page_data_language( $language_code );
		}

		$element_data       = \Bricks\Helpers::get_element_data( $post_id, $form_element_id );
		$this->form_element = $element_data['element'] ?? [];

		// Resolve definition-only defaults below, where their lack of a post owner is explicit.
		$this->form_settings = is_array( $element_data ) ? \Bricks\Helpers::get_element_settings( $post_id, $form_element_id, 0, $element_data ) : [];
		$this->form_id       = $form_element_id;
		$this->post_id       = $post_id;

		// Track the post that supplies the settings: the lookups below may replace them (#123pt1nyhye)
		$source_id = is_array( $element_data ) ? ( $element_data['source_post_id'] ?? $element_data['source_id'] ?? null ) : null;

		$this->settings_source_id = is_numeric( $source_id ) ? absint( $source_id ) : null;

		// STEP: Resolve form settings in their rendered component context.
		if ( $form_component_id ) {
			$component_form_settings = $this->get_nested_form_settings( $post_id, $form_element_id, $form_component_id );

			if ( is_array( $component_form_settings ) ) {
				$this->form_settings = $component_form_settings;
			} else {
				// Gutenberg component blocks are not part of the Bricks element tree.
				$this->apply_component_properties( $post_id, $form_component_id, $form_element_id );
			}
		}

		// No form settings found: Try to get from component element (@since 1.12.2)
		if ( empty( $this->form_settings ) ) {
			$this->form_settings = $this->get_nested_form_settings( $post_id, $form_element_id );
		}

		// No form settings found: Try to get from component element (@since 1.12.2)
		if ( empty( $this->form_settings ) ) {
			$component_element = \Bricks\Helpers::get_component_element_by_id( $form_element_id );

			if ( isset( $component_element['settings'] ) ) {
				$this->form_settings = $component_element['settings'];
				$this->form_element  = $component_element;

				// Component definitions are not stored in a post
				$this->settings_source_id = 0;
			}
		}

		/**
		 * Return: The resolved form settings must come from a post the visitor could have rendered
		 *
		 * Validate the post that actually supplied the settings (not a submitted ID): revisions,
		 * autosaves and unpublished posts store page data too. #123pt1nyhye
		 */
		if ( ! $this->is_submittable_post( $this->settings_source_id ) ) {
			wp_send_json_error(
				[
					'action'  => '',
					'type'    => 'error',
					'message' => esc_html__( 'An error occurred, please try again later.', 'bricks' ),
				]
			);
		}

		/**
		 * Restore ACF row context before field names, validation messages,
		 * and actions render dynamic data. An absent or invalid context preserves the
		 * existing form submission path.
		 *
		 * #86cazz5mc; @since 2.3.11
		 */
		$this->set_query_context( $post_id, $form_element_id, $form_component_id );
		$loop_post_id = $this->query_context ? $post_id : $loop_post_id;

		// Return: No form action set
		if ( empty( $this->form_settings['actions'] ) ) {
			wp_send_json_error(
				[
					'code'    => 400,
					'action'  => '',
					'type'    => 'error',
					'message' => esc_html__( 'No action has been set for this form.', 'bricks' ),
				]
			);
		}

		// Form submission: Support DD tag 'url_parameter' (@since 1.11)
		self::$submission_url_params = []; // Clear on each form submission
		$url_params                  = ! empty( $_POST['urlParams'] ) ? JSON_decode( stripslashes( $_POST['urlParams'] ), true ) : [];

		if ( ! empty( $url_params ) ) {
			// Sanitize url parameters
			$esc_url_or_text = function( $value ) {
				return filter_var( $value, FILTER_VALIDATE_URL ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
			};

			// Sanitize url parameters
			self::$submission_url_params = array_map( $esc_url_or_text, $url_params );
		}

		/**
		 * STEP: Google reCAPTCHA v3 (invisible)
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 */
		$google_recaptcha_enabled = isset( $this->form_settings['enableRecaptcha'] ) && \Bricks\Database::get_setting( 'apiKeyGoogleRecaptcha', false );
		if ( $google_recaptcha_enabled ) {
			$recaptcha_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyGoogleRecaptcha', false );
			$recaptcha_token      = ! empty( $_POST['recaptchaToken'] ) ? sanitize_text_field( $_POST['recaptchaToken'] ) : false;
			$recaptcha_verified   = false;

			// Verify token @see https://developers.google.com/recaptcha/docs/verify
			if ( $recaptcha_token && $recaptcha_secret_key ) {
				// POST the secret instead of putting it in the URL, and verify TLS so the result can't be spoofed (#123pt1nyhyn)
				$recaptcha_response = \Bricks\Helpers::remote_post(
					'https://www.google.com/recaptcha/api/siteverify',
					[
						'body'        => [
							'secret'   => $recaptcha_secret_key,
							'response' => $recaptcha_token,
						],
						'sslverify'   => true,
						'redirection' => 0,
					]
				);

				if ( ! is_wp_error( $recaptcha_response ) && wp_remote_retrieve_response_code( $recaptcha_response ) === 200 ) {
					$recaptcha = json_decode( wp_remote_retrieve_body( $recaptcha_response ) );

					/*
					 * Google reCAPTCHA v3 returns a score
					 *
					 * 1.0 is very likely a good interaction. 0.0 is very likely a bot.
					 *
					 * https://academy.bricksbuilder.io/article/form-element/#spam
					 */
					$score = apply_filters( 'bricks/form/recaptcha_score_threshold', 0.5 );

					// Action was set on the grecaptcha.execute (@see frontend.js)
					if ( $recaptcha->success && $recaptcha->score >= $score && $recaptcha->action == 'bricks_form_submit' ) {
						$recaptcha_verified = true;
					}
				}
			}

			if ( ! $recaptcha_verified ) {
				$error = 'reCAPTCHA: ' . esc_html__( 'Validation failed', 'bricks' );

				if ( ! empty( $recaptcha->{'error-codes'} ) ) {
					$error .= ' [' . implode( ',', $recaptcha->{'error-codes'} ) . ']';
				}

				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => $error,
					]
				);
			}
		}

		/**
		 * STEP: Verify visible hCaptcha
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 *
		 * @since 1.9.2
		 */
		$hcaptcha_enabled = isset( $this->form_settings['enableHCaptcha'] ) && \Bricks\Database::get_setting( 'apiKeyHCaptcha', false );
		if ( $hcaptcha_enabled ) {
			$hcaptcha_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyHCaptcha' );
			$hcaptcha_response   = isset( $_POST['h-captcha-response'] ) ? sanitize_text_field( $_POST['h-captcha-response'] ) : false;
			$hcaptcha_verified   = false;

			// Verify token
			if ( $hcaptcha_response && $hcaptcha_secret_key ) {
				// POST the secret instead of putting it in the URL, and verify TLS so the result can't be spoofed (#123pt1nyhyn)
				$hcaptcha_res = \Bricks\Helpers::remote_post(
					'https://hcaptcha.com/siteverify',
					[
						'body'        => [
							'secret'   => $hcaptcha_secret_key,
							'response' => $hcaptcha_response,
						],
						'sslverify'   => true,
						'redirection' => 0,
					]
				);

				if ( ! is_wp_error( $hcaptcha_res ) && wp_remote_retrieve_response_code( $hcaptcha_res ) === 200 ) {
					$hcaptcha = json_decode( wp_remote_retrieve_body( $hcaptcha_res ) );

					// Check hCaptcha response (https://docs.hcaptcha.com/#verify-the-user-response-server-side)
					if ( $hcaptcha->success ) {
						$hcaptcha_verified = true;
					}
				}
			}

			if ( ! $hcaptcha_verified ) {
				$error = 'hCaptcha: ' . esc_html__( 'Validation failed', 'bricks' );

				if ( ! empty( $hcaptcha->{'error-codes'} ) ) {
					$error .= ' [' . implode( ',', $hcaptcha->{'error-codes'} ) . ']';
				}

				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => $error,
					]
				);
			}
		}

		/**
		 * STEP: Verify Turnstile captcha
		 *
		 * https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
		 *
		 * Only verify if enabled in form settings and API key is set (#86c83pftd)
		 *
		 * @since 1.9.2
		 */
		$turnstile_enabled = isset( $this->form_settings['enableTurnstile'] ) && \Bricks\Database::get_setting( 'apiKeyTurnstile', false );
		if ( $turnstile_enabled ) {
			$turnstile_secret_key = \Bricks\Database::get_setting( 'apiSecretKeyTurnstile' );
			$turnstile_response   = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( $_POST['cf-turnstile-response'] ) : false;
			$turnstile_data       = [];

			// Return error: No response (@since 1.9.8), or no secret key to verify it with, like reCAPTCHA & hCaptcha (#123pt1nyhyn)
			if ( ! $turnstile_secret_key || ! $turnstile_response ) {
				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => 'Turnstile: ' . esc_html__( 'Validation failed', 'bricks' ),
					]
				);
			}

			$url  = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
			$args = [
				'body'        => [
					'secret'   => $turnstile_secret_key,
					'response' => $turnstile_response,
					// 'remoteip' => $_SERVER['REMOTE_ADDR'], // We can optionally send the user's IP address but it's not required
				],
				'sslverify'   => true, // Verify TLS so the result can't be spoofed (#123pt1nyhyn)
				'redirection' => 0,
			];

			$turnstile_verified = false;
			$response           = \Bricks\Helpers::remote_post( $url, $args );

			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$turnstile_data     = json_decode( wp_remote_retrieve_body( $response ), true );
				$turnstile_verified = isset( $turnstile_data['success'] ) && $turnstile_data['success'] === true;
			}

			if ( ! $turnstile_verified ) {
				$error = 'Turnstile: ' . esc_html__( 'Validation failed', 'bricks' );

				if ( isset( $turnstile_data['error-codes'] ) && ! empty( $turnstile_data['error-codes'] ) ) {
					$error .= ' [' . implode( ',', $turnstile_data['error-codes'] ) . ']';
				}

				wp_send_json_error(
					[
						'code'    => 400,
						'action'  => '',
						'type'    => 'error',
						'message' => $error,
					]
				);
			}
		}

		$this->form_fields = stripslashes_deep( $_POST );

		/**
		 * STEP: Check that each submitted field's ID is in the list of valid field IDs
		 *
		 * Initialize an empty array to keep track of processed field IDs
		 *
		 * @since 1.9.2
		 */
		$processed_ids = [];

		// Get valid field IDs from form_settings
		$valid_ids = [];

		foreach ( $this->form_settings['fields'] as $key => $field ) {
			$field_id   = $field['id'] ?? '';
			$field_name = "form-field-$field_id";

			if ( ! empty( $field['id'] ) ) {
				// Get & set 'id' from custom 'name' (e.g.: 'post-{post_id} to 'form-field-{{field_id}}')
				if ( ! empty( $field['name'] ) ) {
					$field_name = bricks_render_dynamic_data( $field['name'], $loop_post_id );

					// Update the parsed name back to the form_fields array so no need to render dynamic data again (@since 1.9.5)
					$this->form_settings['fields'][ $key ]['name'] = $field_name;
				}

				$valid_ids[] = $field['id'];
			}

			$request_field_name = $this->normalize_request_field_name( $field_name );

			/**
			 * Check honeypots before custom-name mapping can overwrite a populated canonical value.
			 *
			 * Multipart files appear only in FILES, and a POST value of '0' still indicates spam.
			 *
			 * #123pt1nyh5w
			 *
			 * @since 2.4.3
			 */
			if ( isset( $field['isHoneypot'] ) ) {
				if (
					( isset( $this->form_fields[ "form-field-$field_id" ] ) && $this->form_fields[ "form-field-$field_id" ] !== '' ) ||
					( isset( $this->form_fields[ $request_field_name ] ) && $this->form_fields[ $request_field_name ] !== '' ) ||
					isset( $_FILES[ "form-field-$field_id" ] ) ||
					isset( $_FILES[ $request_field_name ] )
				) {

					// Omit untrusted values, which may be arrays or multipart file data.
					Helpers::maybe_log(
						"[Honeypot] Possible spam submission:
- Form Bricks Id: $form_element_id
- Field ID: $field_id
"
					);

					// Set honeypot result
					$honeypot_result = [
						'type'    => 'error',
						'message' => esc_html__( 'An error occurred, please try again later.', 'bricks' ),
					];

					// Allow users to customize the honeypot error message using filter
					$honeypot_result = apply_filters( 'bricks/element/form/honeypot/result', $honeypot_result, $field_id, $this );

					$this->set_result( $honeypot_result );

					// Finish the form submission
					$this->maybe_stop_processing(); // If error status is set...
					$this->finish(); // else...

					break;
				}
			}

			if ( $field_id && isset( $this->form_fields[ $request_field_name ] ) ) {
				$this->form_fields[ "form-field-$field_id" ] = $this->form_fields[ $request_field_name ];
			}
		}

		/**
		 * Initialize an array for field IDs that we skip the form field check
		 *
		 * Password reset action: key, login
		 *
		 * @since 1.9.3
		 */
		$skip_check_for_field_ids = [];

		// Check if 'reset-password' is among the set actions for the form
		if ( in_array( 'reset-password', $this->form_settings['actions'], true ) ) {
			array_push( $skip_check_for_field_ids, 'key', 'login' );
		}

		// Check if 'login' is among the set actions for the form
		if ( in_array( 'login', $this->form_settings['actions'], true ) ) {
			array_push( $skip_check_for_field_ids, 'redirect_to' );
		}

		// Check if 'brx_pp_temp_id' is set and is a numeric value
		if ( isset( $_POST['brx_pp_temp_id'] ) && is_numeric( $_POST['brx_pp_temp_id'] ) ) {
			array_push( $skip_check_for_field_ids, 'brx_pp_temp_id' );
		}

		foreach ( array_keys( $this->form_fields ) as $key ) {
			// Check if submitted form field ID is valid
			if ( strpos( $key, 'form-field-' ) === 0 ) {
				$field_id = str_replace( 'form-field-', '', $key );

				// Skip: Field ID has already been processed (e.g.: HTML simply duplicated on the front end)
				if ( in_array( $field_id, $processed_ids, true ) ) {
					// Reject the submission as potentially malicious
					$this->set_error_messages( esc_html__( 'An error occurred, please try again later.', 'bricks' ) );
					$this->maybe_stop_processing();
				}

				// Add field ID to list of processed IDs
				$processed_ids[] = $field_id;

				if ( ! in_array( $field_id, $valid_ids, true ) && ! in_array( $field_id, $skip_check_for_field_ids, true ) ) {
					// Reject the submission as potentially malicious
					$this->set_error_messages( esc_html__( 'An error occurred, please try again later.', 'bricks' ) );
					$this->maybe_stop_processing();
				}
			}
		}

		// STEP: Reject multiple values for single-value fields (#123pt1nyhyn)
		$this->check_single_value_fields();

		// STEP: Check field max-length, and stop processing if any field value exceeds the max-length (@since 1.11.1)
		$this->check_max_length();

		// STEP: Handle files
		$this->uploaded_files = $this->handle_files();

		// STEP: Validate form submission via filter
		$validation_errors = [];

		$validation_errors = apply_filters( 'bricks/form/validate', $validation_errors, $this );

		// STEP: Validate required fields
		$validation_errors = $this->validate_required_fields( $validation_errors );

		// STEP: Validate email fields
		$validation_errors = $this->validate_email_fields( $validation_errors );

		// STEP: Validate password confirmation
		$validation_errors = $this->validate_password_confirmation( $validation_errors );

		// STEP: Validate submitted form
		if ( is_array( $validation_errors ) && count( $validation_errors ) ) {
			// Set validation error messages
			$this->set_error_messages( $validation_errors );

			// Halts execution if an action reported an error (to run validator before running the form action)
			$this->maybe_stop_processing();
		}

		/**
		 * STEP: Handle file uploads early if an action saves or sends media-library data
		 *
		 * These actions need attachment IDs to save to ACF/Meta Box fields,
		 * so files must be uploaded to media library before actions run.
		 *
		 * @since 2.2
		 */
		$this->maybe_handle_files_early();

		// STEP: Run selected form submit 'actions'
		$available_actions = self::get_available_actions();

		// Always set 'redirect' as last action if enabled (@since 2.1)
		if ( in_array( 'redirect', $this->form_settings['actions'], true ) ) {
			// Remove 'redirect' from actions
			$this->form_settings['actions'] = array_diff( $this->form_settings['actions'], [ 'redirect' ] );
			// Add 'redirect' as last action
			$this->form_settings['actions'][] = 'redirect';
		}

		foreach ( $this->form_settings['actions'] as $form_action ) {
			if ( ! $this->should_run_action( $form_action ) ) {
				continue;
			}

			// Skip if not a valid built-in action and no custom action handler exists (@since 1.12.2)
			if ( ! array_key_exists( $form_action, $available_actions ) && ! has_action( "bricks/form/action/{$form_action}" ) ) {
				continue;
			}

			$action_class = 'Bricks\Integrations\Form\Actions\\' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $form_action ) ) );

			// If class exists, run the action
			if ( class_exists( $action_class ) ) {
				$action = new $action_class( $form_action );

				if ( ! method_exists( $action_class, 'run' ) ) {
					continue;
				}

				$action->run( $this );
			}
			// Handle custom actions registered via controls filter (@since 1.12.2)
			else {
				/**
				 * Fire custom form action
				 *
				 * @param \Bricks\Integrations\Form\Init $form Current form instance
				 *
				 * @since 1.12.2
				 */
				do_action( "bricks/form/action/{$form_action}", $this );
			}

			if ( $form_action === 'update-post' ) {
				$this->update_post_executed = true;
			}

			// Halts execution if an action reported an error
			$this->maybe_stop_processing();
		}

		// All fine, success
		$this->finish();
	}

	/**
	 * Restore the submitted ACF query row for dynamic data rendering.
	 *
	 * Each query and row is resolved again from stored Bricks data, from the
	 * outermost loop to the ACF loop containing the submitted form.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @param int    $post_id Post ID containing the submitted form.
	 * @param string $form_element_id Submitted form element ID.
	 * @param string $form_component_id Submitted component instance ID.
	 *
	 * @return void
	 */
	private function set_query_context( $post_id, $form_element_id, $form_component_id = '' ) {
		// Older/cached forms do not submit this payload and continue through the legacy path.
		if (
			! isset( $_POST['formQueryContext'], $_POST['formQueryNonce'] ) ||
			! is_string( $_POST['formQueryContext'] ) ||
			! is_string( $_POST['formQueryNonce'] )
		) {
			return;
		}

		$submitted_context = json_decode( wp_unslash( $_POST['formQueryContext'] ), true );
		$query_nonce       = sanitize_text_field( wp_unslash( $_POST['formQueryNonce'] ) );

		if ( empty( $submitted_context ) || ! is_array( $submitted_context ) ) {
			return;
		}

		$query_context = [];

		// Canonicalize every signed value before rebuilding the nonce action.
		foreach ( $submitted_context as $context ) {
			if (
				! is_array( $context ) ||
				! isset( $context['queryId'], $context['queryIndex'] ) ||
				! is_scalar( $context['queryId'] ) ||
				! is_scalar( $context['queryIndex'] )
			) {
				return;
			}

			$query_element_id = sanitize_text_field( $context['queryId'] );
			$query_loop_index = sanitize_text_field( $context['queryIndex'] );

			if ( ! $query_element_id || $query_loop_index === '' || ! ctype_digit( $query_loop_index ) ) {
				return;
			}

			$query_context[] = [
				'queryId'    => $query_element_id,
				'queryIndex' => $query_loop_index,
			];
		}

		$query_nonce_action = 'bricks-form-query-' . wp_json_encode(
			[ (string) $post_id, $query_context, (string) $form_element_id, (string) $form_component_id ]
		);

		// Prevent clients from substituting another post, query row, form, or component context.
		if ( ! wp_verify_nonce( $query_nonce, $query_nonce_action ) ) {
			return;
		}

		/**
		 * The restored context replaces the validated loopId with this post. A valid nonce only proves the form
		 * was rendered for it, and AJAX popups render any client-supplied post (logged-out nonces are shared).
		 *
		 * #123pt1nyhyk
		 */
		if ( ! self::is_readable_context_post( $post_id ) ) {
			return;
		}

		// Nested ACF providers resolve their results from the active parent query.
		\Bricks\Database::$page_data['preview_or_post_id'] = $post_id;

		foreach ( $query_context as $context ) {
			// Re-read every query from saved Bricks data; no query settings come from the request.
			$query_data = Helpers::get_element_data( $post_id, $context['queryId'] );

			if (
				empty( $query_data['element']['settings']['hasLoop'] ) ||
				empty( $query_data['element']['settings']['query'] )
			) {
				$this->destroy_query_context();
				return;
			}

			$query = new \Bricks\Query( $query_data['element'] );

			if ( ! $this->set_query_context_row( $query, (int) $context['queryIndex'] ) ) {
				$query->destroy();
				$this->destroy_query_context();
				return;
			}

			$this->query_context[] = $query;
		}

		$active_query = end( $this->query_context );

		// The signed context is only supported when its innermost row is an ACF array value.
		if (
			! $active_query ||
			strpos( $active_query->object_type, 'acf_' ) !== 0 ||
			is_a( $active_query->loop_object, 'WP_Post' ) ||
			is_a( $active_query->loop_object, 'WP_Term' ) ||
			is_a( $active_query->loop_object, 'WP_User' )
		) {
			$this->destroy_query_context();
		}
	}

	/**
	 * Select a saved query row without rendering its elements.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @param \Bricks\Query $query Query instance to position.
	 * @param int           $query_loop_index Rendered loop index.
	 *
	 * @return boolean
	 */
	private function set_query_context_row( $query, $query_loop_index ) {
		$query_result = $query->object_type === 'post' && is_a( $query->query_result, 'WP_Query' ) ? $query->query_result->posts : $query->query_result;

		if ( ! is_array( $query_result ) ) {
			return false;
		}

		// Respect pagination, offsets, and result filters when mapping the rendered row index.
		$result_position = $query_loop_index - (int) $query->init_loop_index();
		$result_keys     = array_keys( $query_result );

		if ( $result_position < 0 || ! isset( $result_keys[ $result_position ] ) ) {
			return false;
		}

		$result_key        = $result_keys[ $result_position ];
		$loop_object       = $query_result[ $result_key ];
		$query->loop_index = $query_loop_index;
		$query->is_looping = true;

		if ( $query->object_type === 'post' ) {
			global $post;

			$post = get_post( $loop_object );

			if ( ! $post ) {
				return false;
			}

			setup_postdata( $post );

			$query->loop_object = $post;
		} elseif ( in_array( $query->object_type, [ 'term', 'user' ], true ) ) {
			$query->loop_object = $loop_object;
		} else {
			$query->loop_object = apply_filters( 'bricks/query/loop_object', $loop_object, $result_key, $query );
		}

		return true;
	}

	/**
	 * Remove partially rebuilt queries after invalid or stale context data.
	 *
	 * #86cazz5mc
	 *
	 * @since 2.3.11
	 *
	 * @return void
	 */
	private function destroy_query_context() {
		foreach ( $this->query_context as $query ) {
			$query->destroy();
		}

		$this->query_context = [];

		// A partially restored post query may have changed the global post.
		wp_reset_postdata();
	}

	/**
	 * Whether a submission may load form settings from this post.
	 *
	 * Bricks stores page data on revisions and autosaves, and the submitted post ID is
	 * not signed. Without this check, a visitor could submit an older or unpublished
	 * version of a form, bypassing CAPTCHA, honeypots or removed actions.
	 *
	 * #123pt1nyhye
	 *
	 * @since 2.4.3
	 *
	 * @param int|null $post_id Resolved settings owner, or null when unresolved.
	 * @return bool
	 */
	private function is_submittable_post( $post_id ) {
		// Only an explicitly resolved global definition has no owning post. Failed lookups must not grant access.
		if ( $post_id === null ) {
			return false;
		}

		if ( $post_id === 0 ) {
			return true;
		}

		$post = get_post( $post_id );

		if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		if ( post_password_required( $post ) ) {
			return false;
		}

		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		// Templates render on other pages, so they need not be publicly viewable themselves
		if ( $post->post_type === BRICKS_DB_TEMPLATE_SLUG && $post->post_status === 'publish' ) {
			return true;
		}

		// Private posts and previews of unpublished content for users who can read them
		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * If there are any errors, stop execution
	 *
	 * @return void
	 */
	private function maybe_stop_processing() {
		$errors = ! empty( $this->results['error'] ) && is_array( $this->results['error'] ) ? $this->results['error'] : [];

		// type 'danger' used before 1.7.1
		if ( ! count( $errors ) && ! empty( $this->results['danger'] ) && is_array( $this->results['danger'] ) ) {
			$errors = $this->results['danger'];
		}

		if ( ! count( $errors ) ) {
			return;
		}

		// Get last error
		$error = array_pop( $errors );

		// Remove uploaded files, if exist
		$this->remove_files();

		// Leave
		wp_send_json_error( $error );
	}

	/**
	 * Signature binding a form to the context and page it was rendered in
	 *
	 * An HMAC rather than a nonce: it proves integrity of the rendered context (CSRF is covered by
	 * the form nonce), so public contexts must not expire on cached pages or change when a visitor logs in.
	 *
	 * Non-public posts are bound to the viewer they were rendered for, so a signature from an authorized
	 * render can't be reused by other visitors. A public post's signature stops matching once it isn't public.
	 * The page ID stops a signature from being combined with another page's form settings (same element ID).
	 *
	 * #123pt1nyhyk
	 *
	 * @since 2.4.3
	 *
	 * @param string     $form_id Form element ID.
	 * @param string     $type    Context type: 'post', 'popup', or a loop object type ('term', 'user', etc.).
	 * @param int|string $id      Context object ID.
	 * @param int|string $page_id Page ID the frontend submits as postId (bricksData.postId).
	 * @return string
	 */
	public static function get_context_signature( $form_id, $type, $id, $page_id ) {
		$post     = in_array( $type, [ 'post', 'popup' ], true ) ? get_post( $id ) : null;
		$audience = ! $post || is_post_publicly_viewable( $post ) ? 'public' : 'user-' . get_current_user_id();
		$page_id  = absint( $page_id );

		return wp_hash( "bricks-form-context|{$form_id}|{$type}|{$id}|{$page_id}|{$audience}", 'nonce' );
	}

	/**
	 * Validate the submitted context (loopId) before it is used for dynamic data or as an update target
	 *
	 * #123pt1nyhyk
	 *
	 * @since 2.4.3
	 *
	 * @param int    $post_id         Submitted post ID.
	 * @param string $form_element_id Form element ID.
	 * @return int Validated dynamic data context.
	 */
	private function validate_loop_post( $post_id, $form_element_id ) {
		$loop_id   = isset( $_POST['loopId'] ) ? absint( $_POST['loopId'] ) : 0;
		$signature = isset( $_POST['loopSignature'] ) ? sanitize_text_field( $_POST['loopSignature'] ) : '';
		$loop_type = isset( $_POST['loopType'] ) ? sanitize_key( $_POST['loopType'] ) : '';
		$signed    = $loop_id && $loop_type && $signature && hash_equals( self::get_context_signature( $form_element_id, $loop_type, $loop_id, $post_id ), $signature );

		// Forms in headers, footers and the password template render before the password is entered: keep the post locked
		if ( $signed && in_array( $loop_type, [ 'post', 'popup' ], true ) && post_password_required( $loop_id ) ) {
			$signed = false;
		}

		// Only the post the form was rendered on (or a post loop item) may be updated without capability checks
		$this->verified_post_target = $signed && $loop_type === 'post' ? $loop_id : 0;

		// Term, user and row IDs must never authorize post data: check readability unless signed as a post context
		if ( $loop_id && ! ( $signed && in_array( $loop_type, [ 'post', 'popup' ], true ) ) && ! self::is_readable_context_post( $loop_id ) ) {
			$loop_id = $post_id && self::is_readable_context_post( $post_id ) ? $post_id : 0;
		}

		if ( isset( $_POST['loopId'] ) ) {
			$_POST['loopId'] = $loop_id;
		}

		return $loop_id;
	}

	/**
	 * Post ID the form was rendered for, if signed (0 otherwise)
	 *
	 * #123pt1nyhyk
	 *
	 * @since 2.4.3
	 *
	 * @return int
	 */
	public function get_verified_post_target() {
		return (int) $this->verified_post_target;
	}

	/**
	 * Whether the visitor may use this post as the dynamic data context of an unsigned loopId
	 *
	 * #123pt1nyhyk
	 *
	 * @since 2.4.3
	 *
	 * @param int $loop_id Submitted loop object ID.
	 * @return bool
	 */
	public static function is_readable_context_post( $loop_id ) {
		$post = get_post( $loop_id );

		// Not a post (e.g. term or user loop object): no post data to expose
		if ( ! $post ) {
			return true;
		}

		if ( wp_is_post_revision( $post ) || post_password_required( $post ) ) {
			return false;
		}

		// Check inheritance first: WordPress resolves an attachment's status from its parent.
		if ( $post->post_type === 'attachment' && $post->post_status === 'inherit' ) {
			if ( ! $post->post_parent ) {
				return true;
			}

			if ( post_password_required( $post->post_parent ) ) {
				return false;
			}

			return is_post_publicly_viewable( $post->post_parent ) || current_user_can( 'read_post', $post->ID );
		}

		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		return current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Complete a successful submission and apply its configured file storage.
	 *
	 * @since 2.4.3 Marks completion only after storage and response filters return,
	 *            so interrupted submissions remain eligible for shutdown cleanup.
	 *
	 * @return void
	 */
	private function finish() {
		$form_settings = $this->form_settings;

		// Handle uploaded files after finishing all actions (@since 1.9.2)
		$this->handle_uploaded_files();

		// Basic response
		$response = [
			'type'    => 'success',
			// The frontend inserts the message as HTML: escape submitted values, keep authored markup (#123pt1nyhyf)
			'message' => isset( $form_settings['successMessage'] ) ? $this->render_data( $form_settings['successMessage'], false, [], 'html' ) : esc_html__( 'Success', 'bricks' ),
		];

		// Action 'updatePost': Set reset to false (@since 2.1)
		if ( $this->update_post_executed ) {
			$response['reset'] = false;
		}

		if ( empty( $this->results ) ) {
			$this->submission_successful = true;
			wp_send_json_success( $response );
		}

		// Check for redirects
		if ( ! empty( $this->results['redirect'] ) ) {
			$redirect      = array_pop( $this->results['redirect'] );
			$redirect_to   = $redirect['redirectTo'] ?? '';
			$validate_host = ! empty( $redirect['redirectValidateHost'] );
			// Render custom templates, but preserve already rendered or explicitly literal action results.
			if ( $redirect_to && empty( $redirect['redirectRendered'] ) ) {
				$validate_host = $validate_host || self::redirect_template_controls_host( $redirect_to );
				$post_id       = ! empty( $_POST['loopId'] ) ? absint( $_POST['loopId'] ) : get_the_ID();
				$redirect_to   = bricks_render_dynamic_data( $redirect_to, $post_id );
			}
			// Allow only http(s) or relative URLs: values from URL parameters or fields must not run as javascript: (#123pt1nyhyf)
			$redirect_to = self::sanitize_redirect_url( $redirect_to, $validate_host );

			if ( $redirect_to ) {
				$response['redirectTo']      = $redirect_to;
				$response['redirectTimeout'] = $redirect['redirectTimeout'] ?? 0;
			}
		}

		// Check for 'info' messages (e.g. Mailchimp pending message)
		if ( ! empty( $this->results['info'] ) ) {
			foreach ( $this->results['info'] as $info ) {
				if ( ! empty( $info['message'] ) ) {
					$response['info'][] = $info['message'];
				}
			}
		}

		// Check for 'success' messages (e.g. custom bricks/form/validate) (@since 1.7.1)
		if ( ! empty( $this->results['success'] ) ) {
			foreach ( $this->results['success'] as $success ) {
				if ( ! empty( $success['message'] ) ) {
					$response['message'] = $success['message'];
				}

				// Check for 'refreshPage' flag (@since 1.11.1)
				if ( isset( $success['refreshPage'] ) ) {
					$response['refreshPage'] = $success['refreshPage'];
				}
			}
		}

		// NOTE: Undocumented
		$response = apply_filters( 'bricks/form/response', $response, $this );

		// Evaluate results
		$this->submission_successful = true;
		wp_send_json_success( $response );
	}

	/**
	 * Set action result
	 *
	 * type: success OR danger
	 *
	 * @param array $result
	 * @return void
	 */
	public function set_result( $result ) {
		$type                     = isset( $result['type'] ) ? $result['type'] : 'success';
		$this->results[ $type ][] = $result;
	}

	/**
	 * Getters
	 */
	public function get_settings() {
		return $this->form_settings;
	}

	public function get_fields() {
		return $this->form_fields;
	}

	public function get_id() {
		return $this->form_id;
	}

	/**
	 * Get the trusted form identity used for submission storage and limits.
	 *
	 * Global instances share their saved global identity; other forms retain the
	 * resolved element or block instance ID, never a client-supplied globalId.
	 *
	 * @since 2.4.3
	 * @return string
	 */
	public function get_submission_id() {
		$global_id = Helpers::get_global_element( $this->form_element, 'global' );

		return (string) ( $global_id ? $global_id : ( $this->form_element['id'] ?? '' ) );
	}

	public function get_post_id() {
		return $this->post_id;
	}

	public function get_uploaded_files() {
		return $this->uploaded_files;
	}

	public function get_results() {
		return $this->results;
	}

	/**
	 * Get server-verified existing file IDs submitted for a file field.
	 *
	 * Hidden existing-file inputs are client-controlled, so only IDs that
	 * still exist in the mapped post meta are allowed.
	 *
	 * @param string $field_id         Field ID.
	 * @param int    $post_id          Post ID.
	 * @param array  $submitted_fields Submitted form fields.
	 *
	 * @return array
	 *
	 * @since 2.3.9
	 */
	public function get_verified_existing_file_ids( $field_id, $post_id = 0, $submitted_fields = [] ) {
		if ( empty( $field_id ) ) {
			return [];
		}

		$normalize_attachment_ids = function( $values ) use ( &$normalize_attachment_ids ) {
			$attachment_ids = [];
			$values         = is_array( $values ) ? $values : [ $values ];

			foreach ( $values as $value ) {
				if ( is_array( $value ) ) {
					if ( isset( $value['ID'] ) || isset( $value['id'] ) ) {
						$value = $value['ID'] ?? $value['id'] ?? 0;
					} else {
						$attachment_ids = array_merge( $attachment_ids, $normalize_attachment_ids( $value ) );
						continue;
					}
				}

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				foreach ( explode( ',', (string) $value ) as $attachment_id ) {
					$attachment_id = absint( trim( $attachment_id ) );

					if ( $attachment_id ) {
						$attachment_ids[] = $attachment_id;
					}
				}
			}

			return array_values( array_unique( $attachment_ids ) );
		};

		if ( empty( $submitted_fields ) ) {
			$submitted_fields = $this->get_fields();
		}

		$submitted_attachment_ids = $normalize_attachment_ids( $submitted_fields['bricks_form_existing_files'][ $field_id ] ?? [] );
		$form_settings            = $this->get_settings();
		$update_post_meta         = $form_settings['updatePostMeta'] ?? [];

		if ( ! $post_id ) {
			$actions = $form_settings['actions'] ?? [];
			$post_id = $form_settings['updatePostId'] ?? '';

			if ( ! $post_id && is_array( $actions ) && in_array( 'update-post', $actions, true ) ) {
				$post_id = ! empty( $submitted_fields['loopId'] ) ? $submitted_fields['loopId'] : $this->get_post_id();
			}
		}

		$post_id = is_scalar( $post_id ) ? absint( $post_id ) : 0;

		if ( empty( $submitted_attachment_ids ) || ! $post_id || ! is_array( $update_post_meta ) ) {
			return [];
		}

		foreach ( $update_post_meta as $post_meta ) {
			if ( empty( $post_meta['metaKey'] ) || ! is_scalar( $post_meta['metaKey'] ) || empty( $post_meta['metaValue'] ) || $post_meta['metaValue'] !== $field_id ) {
				continue;
			}

			$allowed_attachment_ids = $normalize_attachment_ids( get_post_meta( $post_id, (string) $post_meta['metaKey'], false ) );

			return array_values( array_intersect( $submitted_attachment_ids, $allowed_attachment_ids ) );
		}

		return [];
	}

	/**
	 * Reject array values for fields that only submit a single value
	 *
	 * An array (e.g. 'form-field-x[]=a&form-field-x[]=b') would satisfy mutually exclusive
	 * action conditions at once, pass required checks with an empty item, and bypass max-length.
	 * Radio buttons are always submitted as an array (backwards compatibility), but with one value.
	 *
	 * #123pt1nyhyn
	 *
	 * @since 2.4.3
	 *
	 * @return void
	 */
	private function check_single_value_fields() {
		// Other types (checkbox, select with custom multiple attribute, image, gallery, custom types) may submit arrays
		$single_value_types = [ 'email', 'text', 'textarea', 'richtext', 'tel', 'number', 'url', 'datepicker', 'password', 'hidden' ];

		foreach ( $this->form_settings['fields'] ?? [] as $field ) {
			$field_id = $field['id'] ?? '';
			$value    = $field_id ? ( $this->form_fields[ "form-field-$field_id" ] ?? null ) : null;

			if ( ! is_array( $value ) ) {
				continue;
			}

			$type    = $field['type'] ?? '';
			$invalid = in_array( $type, $single_value_types, true ) || ( $type === 'radio' && count( $value ) > 1 );

			// Options and media IDs are scalar: nested arrays would break validation, emails and storage.
			if ( in_array( $type, [ 'checkbox', 'radio', 'select', 'image', 'gallery' ], true ) ) {
				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) ) {
						$invalid = true;
					}
				}
			}

			if ( $invalid ) {
				$this->set_error_messages( esc_html__( 'An error occurred, please try again later.', 'bricks' ) );
				$this->maybe_stop_processing();
			}
		}
	}

	/**
	 * Helper function to check for max-length of form fields
	 *
	 * @since 1.11.1
	 */
	private function check_max_length() {
		$form_settings = $this->form_settings;
		$form_fields   = $this->form_fields;

		// Check for max-length of fields
		foreach ( $form_settings['fields'] as $field ) {
			$field_id = $field['id'] ?? '';

			// Skip if field ID is empty (should not happen)
			if ( empty( $field_id ) ) {
				continue;
			}

			$field_value = $form_fields[ "form-field-{$field_id}" ] ?? '';

			// Get max length: Empty if not defined, or the value from the field settings
			$max_length = isset( $field['maxLength'] ) ? $field['maxLength'] : false;

			// Ensure max_length is an positive integer
			if ( ! is_numeric( $max_length ) || $max_length < 0 ) {
				$max_length = false;
			}

			if ( $max_length === false && $field['type'] === 'email' ) {
				// If no value is set for email, enforce 320 (RCF 3696 - https://datatracker.ietf.org/doc/html/rfc3696.html)
				$max_length = 320;
			}

			if ( $max_length === false ) {
				continue;
			}

			// Textarea submissions use CRLF, but browser length constraints count normalized LF.
			if ( $field['type'] === 'textarea' ) {
				$field_value = str_replace( [ "\r\n", "\r" ], "\n", $field_value );
			}

			// HTML maxlength counts UTF-16 code units: supplementary characters count twice.
			$characters      = preg_match_all( '/./us', $field_value );
			$surrogate_pairs = preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $field_value );
			$field_length    = $characters !== false && $surrogate_pairs !== false ? $characters + $surrogate_pairs : strlen( $field_value );

			// Check for max-length. Invalid UTF-8 retains the existing byte-length fallback.
			if ( $field_length > $max_length ) {
				// If field label is not set, use the field ID
				$field_label = $field['label'] ?? $field_id;
				$this->set_error_messages( sprintf( esc_html__( 'The field "%s" exceeds the allowed maximum length of %2$d characters.', 'bricks' ), $field_label, $max_length ) );
				$this->maybe_stop_processing();
			}
		}
	}

	/**
	 * Helper function to convert a comma-separated list of file extensions to an array of MIME types
	 *
	 * @param string $extensions Comma-separated list of file extensions.
	 * @return array Array of corresponding MIME types.
	 *
	 * @since 1.9.3
	 */
	public function extensions_to_mime_types( $extensions ) {
		$all_mime_types     = get_allowed_mime_types(); // Retrieve list of allowed mime types and file extensions
		$extensions_array   = array_map( 'trim', explode( ',', $extensions ) ); // Convert the comma-separated string to an array
		$allowed_mime_types = [];

		foreach ( $extensions_array as $extension ) {
			// Loop through the array to find the MIME type for each extension. (e.g. 'jpg' => 'image/jpeg' & 'pdf' => 'application/pdf')
			foreach ( $all_mime_types as $ext_pattern => $mime ) {
				if ( preg_match( "!^($ext_pattern)$!i", $extension ) ) {
					$allowed_mime_types[] = $mime;
					break;
				}
			}
		}

		// 'finfo' often detect .m4a files as 'video/mp4' even if they only contain audio.
		// We add this as a fallback to ensure valid M4A uploads aren't rejected by strict MIME checks. (@since 2.3.2)
		$normalized_extensions = array_map( 'strtolower', $extensions_array );
		if ( $extensions_array && in_array( 'm4a', $normalized_extensions, true ) ) {
			$allowed_mime_types[] = 'video/mp4';
		}

		return $allowed_mime_types;
	}

	/**
	 * Helper function to convert unsupported MIME types to supported MIME types (e.g. 'audio/x-wav' to 'audio/wav')
	 *
	 * @param string $mime_type MIME type to convert
	 * @return string Converted MIME type
	 *
	 * @since 1.10
	 */
	private function convert_to_supported_mime_type( $mime_type ) {
		// List of unsupported MIME types
		$unsupported_mime_types = [
			'audio/x-wav' => 'audio/wav',
			'audio/x-m4a' => 'audio/mpeg',
			'image/x-eps' => 'application/postscript',
		];

		// Convert unsupported MIME types to supported MIME types
		return $unsupported_mime_types[ $mime_type ] ?? $mime_type;
	}

	/**
	 * Match the request-key normalization performed by PHP.
	 *
	 * Rendered names remain unchanged in settings and upload metadata because form
	 * actions and path hooks use that identity. Normalize only request comparisons.
	 *
	 * @link https://www.php.net/manual/en/language.variables.external.php
	 *
	 * @since 2.4.3
	 *
	 * @param string $name Canonical or rendered custom input name.
	 * @return string Name as PHP exposes it in POST and FILES.
	 */
	private function normalize_request_field_name( $name ) {
		// PHP discards leading ASCII spaces before replacing dots and remaining spaces.
		return strtr( ltrim( (string) $name, ' ' ), '. ', '__' );
	}

	/**
	 * Resolve a submitted input name to a configured, unambiguous file field.
	 *
	 * Custom names have already been rendered in the submission's dynamic-data context.
	 * Compare full names: a custom name may itself contain the 'form-field-' prefix.
	 *
	 * #123pt1nyh5w
	 *
	 * @since 2.4.3
	 *
	 * @param string $input_name Canonical or rendered custom input name.
	 * @return array|null Field settings, or null for unknown, non-file, honeypot or ambiguous inputs.
	 */
	private function get_file_field_settings( $input_name ) {
		$matched_field = null;
		$input_name    = $this->normalize_request_field_name( $input_name );

		foreach ( $this->form_settings['fields'] ?? [] as $field ) {
			$field_id       = $field['id'] ?? '';
			$canonical_name = $this->normalize_request_field_name( "form-field-$field_id" );
			$custom_name    = $this->normalize_request_field_name( $field['name'] ?? '' );

			if ( ! $field_id || ( $input_name !== $canonical_name && $input_name !== $custom_name ) ) {
				continue;
			}

			// Ambiguous names must not borrow another field's upload permissions.
			if ( $matched_field !== null || ( $field['type'] ?? '' ) !== 'file' || isset( $field['isHoneypot'] ) ) {
				return null;
			}

			$matched_field = $field;
		}

		if ( $matched_field !== null ) {
			// Check both aliases: normalization must not transfer a valid upload to another field's name.
			$names = [ $this->normalize_request_field_name( "form-field-{$matched_field['id']}" ) ];
			if ( ! empty( $matched_field['name'] ) ) {
				$names[] = $this->normalize_request_field_name( $matched_field['name'] );
			}

			foreach ( $this->form_settings['fields'] ?? [] as $field ) {
				if ( $field === $matched_field || empty( $field['id'] ) ) {
					continue;
				}

				$canonical_name = $this->normalize_request_field_name( "form-field-{$field['id']}" );
				$custom_name    = $this->normalize_request_field_name( $field['name'] ?? '' );

				if ( in_array( $canonical_name, $names, true ) || ( $custom_name !== '' && in_array( $custom_name, $names, true ) ) ) {
					return null;
				}
			}
		}

		return $matched_field;
	}

	/**
	 * Validate all submitted files before uploading any of them.
	 *
	 * The entire request must pass field, shape, count, size and format checks before
	 * WordPress moves a file. A forbidden later input must not cause earlier writes.
	 * Successful writes are tracked on this instance so any subsequent failure can
	 * remove them, including requests terminated by an action or filter.
	 *
	 * #123pt1nyh5w
	 *
	 * @since 2.4.3 Restricts uploads to configured file fields and enforces their server-side limits.
	 *
	 * @return array Uploaded file metadata grouped by the configured input name.
	 */
	public function handle_files() {
		if ( empty( $_FILES ) ) {
			return [];
		}

		$pending_files  = [];
		$file_counts    = [];
		$all_mime_types = get_allowed_mime_types();
		$error_message  = esc_html__( 'An error occurred, please try again later.', 'bricks' );

		foreach ( $_FILES as $input_name => $files ) {
			$field = $this->get_file_field_settings( $input_name );

			if ( ! $field || ! is_array( $files ) ) {
				$this->set_error_messages( $error_message );
				$this->maybe_stop_processing();
			}

			// PHP emits scalar file parts or parallel arrays. Nested or inconsistent arrays are invalid.
			$multiple = isset( $files['name'] ) && is_array( $files['name'] );
			$keys     = $multiple ? array_keys( $files['name'] ) : [ 0 ];

			foreach ( [ 'name', 'type', 'tmp_name', 'error', 'size' ] as $property ) {
				if (
					! isset( $files[ $property ] ) ||
					is_array( $files[ $property ] ) !== $multiple ||
					( $multiple && array_keys( $files[ $property ] ) !== $keys )
				) {
					$this->set_error_messages( $error_message );
					$this->maybe_stop_processing();
				}

				if ( ! $multiple ) {
					$files[ $property ] = [ $files[ $property ] ];
				}
			}

			$mimes                        = $all_mime_types;
			$allowed_mime_types_for_field = $all_mime_types;

			if ( ! empty( $field['fileUploadAllowedTypes'] ) ) {
				$extensions = array_map( 'strtolower', array_map( 'trim', explode( ',', $this->render_data( $field['fileUploadAllowedTypes'] ) ) ) );

				// Match the JPEG alias accepted by the rendered file input.
				if ( in_array( 'jpg', $extensions, true ) && ! in_array( 'jpeg', $extensions, true ) ) {
					$extensions[] = 'jpeg';
				}

				// WordPress groups extensions by MIME; retain only the selected extensions from each group.
				$mimes = [];

				foreach ( $extensions as $extension ) {
					foreach ( $all_mime_types as $pattern => $mime ) {
						if ( $extension !== '' && preg_match( "!^($pattern)$!i", $extension ) ) {
							$mimes[ preg_quote( $extension, '!' ) ] = $mime;
							break;
						}
					}
				}

				$allowed_mime_types_for_field = $this->extensions_to_mime_types( implode( ',', $extensions ) );
			}

			$field_id = $field['id'];
			// Both aliases must produce the same keys for required-field checks, actions and file storage.
			$field_name = ! empty( $field['name'] ) ? $field['name'] : "form-field-$field_id";
			$max_files  = ! empty( $field['fileUploadLimit'] ) ? (int) $field['fileUploadLimit'] : 0;
			$max_size   = ! empty( $field['fileUploadSize'] ) ? (float) $field['fileUploadSize'] * MB_IN_BYTES : 0;

			if ( ! isset( $file_counts[ $field_id ] ) ) {
				// Count aliases together and include verified retained files, excluding requested removals.
				$existing_files           = $this->get_verified_existing_file_ids( $field_id );
				$removed_files            = array_map( 'absint', (array) ( $this->form_fields['bricks_form_removed_files'][ $field_id ] ?? [] ) );
				$file_counts[ $field_id ] = count( array_diff( $existing_files, $removed_files ) );
			}

			foreach ( $keys as $key ) {
				if (
					! is_string( $files['name'][ $key ] ) ||
					! is_string( $files['type'][ $key ] ) ||
					! is_string( $files['tmp_name'][ $key ] ) ||
					! is_int( $files['error'][ $key ] ) ||
					! is_int( $files['size'][ $key ] )
				) {
					$this->set_error_messages( $error_message );
					$this->maybe_stop_processing();
				}

				// An unselected optional file input is not an upload failure.
				if ( $files['error'][ $key ] === UPLOAD_ERR_NO_FILE ) {
					continue;
				}

				if ( $files['error'][ $key ] !== UPLOAD_ERR_OK || $files['name'][ $key ] === '' || ! is_file( $files['tmp_name'][ $key ] ) || ! is_readable( $files['tmp_name'][ $key ] ) ) {
					$this->set_error_messages( $error_message );
					$this->maybe_stop_processing();
				}

				++$file_counts[ $field_id ];

				if ( $max_files > 0 && $file_counts[ $field_id ] > $max_files ) {
					// translators: %s: Uploaded file name.
					$this->set_error_messages( sprintf( esc_html__( 'File %s not accepted. File limit exceeded.', 'bricks' ), esc_html( $files['name'][ $key ] ) ) );
					$this->maybe_stop_processing();
				}

				// Read the temporary file rather than trusting submitted size metadata.
				$size = filesize( $files['tmp_name'][ $key ] );

				if ( $size === false || ( $max_size > 0 && $size > $max_size ) ) {
					// translators: %s: Uploaded file name.
					$this->set_error_messages( sprintf( esc_html__( 'File %s not accepted. Size limit exceeded.', 'bricks' ), esc_html( $files['name'][ $key ] ) ) );
					$this->maybe_stop_processing();
				}

				// Content MIME and filename extension must both satisfy the field's allowed formats.
				$finfo          = finfo_open( FILEINFO_MIME_TYPE );
				$real_mime_type = finfo_file( $finfo, $files['tmp_name'][ $key ] );
				// finfo_close() is a no-op for objects and deprecated in PHP 8.5: https://www.php.net/manual/en/function.finfo-close.php
				if ( PHP_VERSION_ID < 80000 ) {
					finfo_close( $finfo );
				}

				$real_mime_type = $this->convert_to_supported_mime_type( $real_mime_type );
				$file_type      = wp_check_filetype( $files['name'][ $key ], $mimes );

				if ( ! in_array( $real_mime_type, $allowed_mime_types_for_field, true ) || empty( $file_type['type'] ) ) {
					$this->set_error_messages( esc_html__( 'Uploaded file type is not allowed.', 'bricks' ) );
					$this->maybe_stop_processing();
				}

				$pending_files[] = [
					'input_name' => $field_name,
					'field'      => $field,
					'mimes'      => $mimes,
					'file'       => [
						'name'     => $files['name'][ $key ],
						'type'     => $file_type['type'] === 'image/svg+xml' ? 'image/svg+xml' : $real_mime_type,
						'tmp_name' => $files['tmp_name'][ $key ],
						'error'    => $files['error'][ $key ],
						'size'     => $size,
					],
				];
			}
		}

		foreach ( $pending_files as $pending ) {
			$input_name = $pending['input_name'];
			$field      = $pending['field'];
			$file       = $pending['file'];
			$uploaded   = wp_handle_upload(
				$file,
				[
					'action' => 'bricks_form_submit',
					'mimes'  => $pending['mimes']
				]
			);

			if ( ! $uploaded || isset( $uploaded['error'] ) ) {
				$this->set_error_messages( $error_message );
				$this->maybe_stop_processing();
			}

			// Cleanup must see each successful write before subsequent uploads or directory filters run.
			$this->uploaded_files[ $input_name ][] = $uploaded;

			if ( ! $this->upload_cleanup_registered ) {
				// An action may exit or fail fatally; a finally block cannot guarantee cleanup after exit.
				register_shutdown_function(
					function() {
						if ( ! $this->submission_successful ) {
							$this->remove_files();
						}
					}
				);
				$this->upload_cleanup_registered = true;
			}

			$uploaded_index = count( $this->uploaded_files[ $input_name ] ) - 1;
			$save_file      = $field['fileUploadStorage'] ?? false;

			if ( $save_file === 'directory' ) {
				$directory_name = ! empty( $field['fileUploadStorageDirectory'] ) ? $this->render_data( $field['fileUploadStorageDirectory'] ) : '';
				$directory_name = sanitize_file_name( $directory_name );
				$wp_upload_dir  = wp_upload_dir();
				$directory_path = $wp_upload_dir['basedir'] . "/$directory_name";
				$original_path  = $directory_path;

				$directory_path = apply_filters( 'bricks/form/file_directory', $directory_path, $this, $input_name );

				if ( $directory_path !== $original_path ) {
					$save_file = 'filter';
				}

				if ( $directory_path && ! file_exists( $directory_path ) ) {
					wp_mkdir_p( $directory_path );
				}

				if ( $directory_path && is_dir( $directory_path ) && is_writable( $directory_path ) ) {
					$new_file_name = wp_unique_filename( $directory_path, $file['name'] );
					$new_path      = "$directory_path/$new_file_name";

					if ( copy( $uploaded['file'], $new_path ) ) {
						@unlink( $uploaded['file'] );
						$uploaded['file'] = $new_path;
					}
				}
			}

			// Preserve WordPress's validated MIME instead of replacing it with the multipart Content-Type.
			$uploaded['name']                                       = $file['name'];
			$uploaded['size']                                       = $file['size'];
			$uploaded['location']                                   = $save_file;
			$this->uploaded_files[ $input_name ][ $uploaded_index ] = $uploaded;
		}

		return $this->uploaded_files ?? [];
	}

	/**
	 * Remove (default), keep uploaded or move files to media library (as attachment)
	 *
	 * @since 1.9.2
	 * @since 2.4.3 Uses the same field-name resolution as validation and tracks attachment IDs
	 *            before metadata generation so failed submissions can remove derived files.
	 *
	 * @param string $mode 'early' processes attachments needed by form actions; 'final' applies remaining storage rules.
	 * @return void
	 */
	public function handle_uploaded_files( $mode = 'final' ) {
		$uploaded_files = $this->get_uploaded_files();

		if ( empty( $uploaded_files ) ) {
			return;
		}

		// Loop through uploaded files
		foreach ( $uploaded_files as $input_name => $files ) {
			$field     = $this->get_file_field_settings( $input_name );
			$save_file = $field['fileUploadStorage'] ?? 'no';

			// Early mode: Only process files that should be saved as attachments (@since 2.2)
			if ( $mode === 'early' && $save_file !== 'attachment' ) {
				continue;
			}

			switch ( $save_file ) {
				case 'attachment':
					// Move uploaded files to media library as attachment
					foreach ( $files as $index => $file ) {
						// Skip if already processed in early mode (@since 2.2)
						if ( isset( $file['attachment_id'] ) ) {
							continue;
						}

						$attachment = [
							'post_mime_type' => $file['type'],
							'post_title'     => sanitize_file_name( $file['name'] ),
							'post_content'   => '',
							'post_status'    => 'inherit',
						];

						// Insert file as attachment
						$attachment_id = wp_insert_attachment( $attachment, $file['file'] );

						if ( $attachment_id ) {
							// Track the attachment before metadata generation creates additional files.
							$this->uploaded_files[ $input_name ][ $index ]['attachment_id'] = $attachment_id;

							// Add attachment metadata from file
							$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );
							wp_update_attachment_metadata( $attachment_id, $attachment_data );
						}
					}
					break;

				case 'directory':
					// Move uploaded file to custom directory
					break;

				default:
					// Default: Remove uploaded files (only in final mode @since 2.2)
					if ( $mode === 'final' ) {
						foreach ( $files as $file ) {
							@unlink( $file['file'] );
						}
					}
					break;
			}
		}
	}

	/**
	 * Handle file uploads early if an action needs attachment IDs.
	 *
	 * Save submission, create post, update post, and webhook actions need media-library data before
	 * they run.
	 *
	 * @since 2.2
	 * @since 2.4.3 Includes saved submissions so a saved media reference is complete before later actions run.
	 */
	private function maybe_handle_files_early() {
		$needs_early_upload = false;
		$actions            = $this->form_settings['actions'] ?? [];

		foreach ( [ 'save-submission', 'create-post', 'update-post', 'webhook' ] as $action ) {
			if ( in_array( $action, $actions, true ) && $this->should_run_action( $action ) ) {
				$needs_early_upload = true;
				break;
			}
		}

		if ( ! $needs_early_upload ) {
			return;
		}

		// Process files in early mode (only attachments)
		$this->handle_uploaded_files( 'early' );
	}

	/**
	 * Check whether a form action meets all of its configured conditions.
	 *
	 * Multiple condition rows for the same action are combined with AND logic so
	 * adding a row cannot broaden when an action runs.
	 *
	 * @since 2.4
	 *
	 * @param string $form_action Form action key.
	 * @return bool
	 */
	private function should_run_action( $form_action ) {
		$action_conditions = $this->form_settings['actionConditions'] ?? [];

		if ( ! is_array( $action_conditions ) ) {
			return true;
		}

		foreach ( $action_conditions as $action_condition ) {
			if ( ! is_array( $action_condition ) || ( $action_condition['action'] ?? '' ) !== $form_action ) {
				continue;
			}

			$conditions = $action_condition['conditions'] ?? [];

			if ( ! is_array( $conditions ) || ! count( $conditions ) ) {
				return false;
			}

			$relation    = ( $action_condition['conditionsRelation'] ?? 'and' ) === 'or' ? 'or' : 'and';
			$row_matches = $relation === 'and';

			foreach ( $conditions as $condition ) {
				$condition_matches = $this->is_action_condition_met( $condition );

				if ( $condition_matches === null ) {
					return false;
				}

				$row_matches = $relation === 'or'
					? $row_matches || $condition_matches
					: $row_matches && $condition_matches;
			}

			if ( ! $row_matches ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether an individual form action condition matches submitted data.
	 *
	 * @since 2.4
	 *
	 * @param mixed $condition Action condition settings.
	 * @return bool|null Null if the condition is incomplete or invalid.
	 */
	private function is_action_condition_met( $condition ) {
		if ( ! is_array( $condition ) ) {
			return null;
		}

		$field_id = $condition['fieldId'] ?? '';
		$compare  = $condition['compare'] ?? '';

		if ( ! is_string( $field_id ) ) {
			return null;
		}

		if ( ! in_array( $compare, [ '==', '!=', 'contains', 'not_contains', 'empty', 'not_empty' ], true ) ) {
			return null;
		}

		$field = $this->get_action_condition_field( $field_id );

		if ( $field === null ) {
			return null;
		}

		$field_value = $this->get_action_condition_field_value( $field );

		if ( $compare === 'empty' ) {
			return $this->is_action_condition_value_empty( $field_value );
		}

		if ( $compare === 'not_empty' ) {
			return ! $this->is_action_condition_value_empty( $field_value );
		}

		$condition_value = $condition['value'] ?? '';

		if ( ! is_scalar( $condition_value ) || $condition_value === '' ) {
			return null;
		}

		$condition_value = (string) $this->render_data( (string) $condition_value );

		if ( $condition_value === '' ) {
			return null;
		}

		$field_values = is_array( $field_value ) ? $field_value : [ $field_value ];
		$matches      = false;
		$contains     = in_array( $compare, [ 'contains', 'not_contains' ], true );

		foreach ( $field_values as $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$value = (string) $value;

			if ( $contains ? strpos( $value, $condition_value ) !== false : $value === $condition_value ) {
				$matches = true;
				break;
			}
		}

		return in_array( $compare, [ '!=', 'not_contains' ], true ) ? ! $matches : $matches;
	}

	/**
	 * Get a submittable form field's settings for an action condition.
	 *
	 * @since 2.4
	 *
	 * @param string $field_id Form field ID.
	 * @return array|null
	 */
	private function get_action_condition_field( $field_id ) {
		$fields = $this->form_settings['fields'] ?? [];

		if ( ! is_array( $fields ) ) {
			return null;
		}

		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ( $field['id'] ?? '' ) === $field_id ) {
				return ( $field['type'] ?? '' ) === 'html' ? null : $field;
			}
		}

		return null;
	}

	/**
	 * Get a submitted field value for an action condition.
	 *
	 * File inputs are absent from POST data, so compare new uploads and verified
	 * files retained by an Update post form.
	 *
	 * @since 2.4
	 *
	 * @param array $field Form field settings.
	 * @return mixed
	 */
	private function get_action_condition_field_value( $field ) {
		$field_id = $field['id'] ?? '';

		if ( ( $field['type'] ?? '' ) !== 'file' ) {
			return $this->get_field_value( $field_id );
		}

		$input_name     = ! empty( $field['name'] ) ? $field['name'] : "form-field-{$field_id}";
		$uploaded_files = $this->get_uploaded_files();
		$files          = is_array( $uploaded_files ) && ! empty( $uploaded_files[ $input_name ] ) ? $uploaded_files[ $input_name ] : [];
		$filenames      = [];

		foreach ( $files as $file ) {
			if ( is_array( $file ) && isset( $file['name'] ) && is_scalar( $file['name'] ) ) {
				$filenames[] = (string) $file['name'];
			}
		}

		foreach ( $this->get_verified_existing_file_ids( $field_id ) as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			$file_name = $file_path ? wp_basename( $file_path ) : get_the_title( $attachment_id );

			// A verified attachment ID still represents a non-empty file if its metadata is incomplete.
			$filenames[] = $file_name ? $file_name : (string) $attachment_id;
		}

		return $filenames;
	}

	/**
	 * Check whether a submitted form value is empty without treating zero as empty.
	 *
	 * @since 2.4
	 *
	 * @param mixed $value Submitted field value.
	 * @return bool
	 */
	private function is_action_condition_value_empty( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->is_action_condition_value_empty( $item ) ) {
					return false;
				}
			}

			return true;
		}

		return $value === null || $value === '';
	}

	/**
	 * Preserve configured persistent uploads referenced by data an action saved.
	 *
	 * Call after a successful write with the values actually persisted, including
	 * any action filters. Temporary email attachments retain their normal lifetime.
	 *
	 * @since 2.4.3
	 *
	 * @param mixed $values Saved paths, URLs or attachment IDs, including nested values.
	 * @return void
	 */
	public function retain_uploaded_files( $values ) {
		$references = [];
		$values     = (array) $values;
		array_walk_recursive(
			$values,
			function( $value ) use ( &$references ) {
				if ( is_string( $value ) || is_int( $value ) ) {
					$references[] = (string) $value;
				}
			}
		);

		foreach ( $this->uploaded_files ?? [] as $files ) {
			foreach ( $files as $file ) {
				if ( ! in_array( $file['location'] ?? '', [ 'attachment', 'directory', 'filter' ], true ) ) {
					continue;
				}

				foreach ( [ 'file', 'url', 'attachment_id' ] as $key ) {
					if ( ! empty( $file[ $key ] ) && in_array( (string) $file[ $key ], $references, true ) ) {
						$this->retained_uploads[ $file['file'] ] = true;
						break;
					}
				}
			}
		}
	}

	/**
	 * Roll back unreferenced uploads owned by the current submission.
	 *
	 * Includes files copied into custom directories and attachments created before
	 * actions run, except uploads already referenced by a persisted action. Clearing
	 * the registry makes normal and shutdown cleanup safe to repeat.
	 *
	 * #123pt1nyh5w
	 *
	 * @since 2.4.3 Removes early media attachments and clears the tracked upload registry.
	 *
	 * @return void
	 */
	public function remove_files() {
		$uploaded_files = $this->get_uploaded_files();

		if ( empty( $uploaded_files ) ) {
			return;
		}

		// Remove uploaded files
		foreach ( $uploaded_files as $input_name => $files ) {
			foreach ( $files as $file ) {
				if ( isset( $this->retained_uploads[ $file['file'] ] ) ) {
					continue;
				}

				if ( ! empty( $file['attachment_id'] ) ) {
					// WordPress also removes generated image sizes and the attachment's database record.
					wp_delete_attachment( $file['attachment_id'], true );
				}

				@unlink( $file['file'] );
			}
		}

		$this->uploaded_files = [];
	}

	/**
	 * Sanitize redirects without changing how the browser resolves relative paths.
	 *
	 * @since 2.4.3
	 *
	 * @param string $url           Rendered redirect destination.
	 * @param bool   $validate_host Whether submitted data can influence the destination.
	 * @return string Safe destination, or an empty string when rejected.
	 */
	private static function sanitize_redirect_url( $url, $validate_host = false ) {
		$url = trim( (string) $url );

		if ( $url === '' ) {
			return '';
		}

		// Browsers treat backslashes as slashes before the query, including network-path references. Keep query values intact.
		$query_start = strcspn( $url, '?#' );
		$url         = str_replace( '\\', '/', substr( $url, 0, $query_start ) ) . substr( $url, $query_start );

		// Query-only and fragment-only URLs are relative too: a colon in their value is not a scheme
		$is_relative = ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) && $url[0] !== '/';
		// A temporary leading slash prevents esc_url_raw() from prepending http:// to paths.
		$url = esc_url_raw( $is_relative ? '/' . $url : $url, [ 'http', 'https' ] );
		if ( $is_relative && $url !== '' ) {
			$url = substr( $url, 1 );
			// Sanitization can remove characters that previously interrupted a URL scheme.
			if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
				return '';
			}
		}

		// Use validation only as a gate: its relative-path rewrite uses the AJAX request's directory.
		if ( $validate_host && wp_validate_redirect( $url, '' ) === '' ) {
			return '';
		}

		return $url;
	}

	/**
	 * Whether a submitted value in a redirect template can change the URL's scheme or host
	 *
	 * Placeholders after a literal "https://host/", "/path", "?" or "#" can only change the path,
	 * query or fragment, so admin-authored external redirects like "https://example.com/?email={{email}}"
	 * keep working. Otherwise the rendered URL must stay on this site (or an allowed redirect host).
	 *
	 * #123pt1nyhyf
	 *
	 * @since 2.4.3
	 *
	 * @param string $template Redirect template before rendering.
	 * @return bool
	 */
	public static function redirect_template_controls_host( $template ) {
		$template = trim( (string) $template );

		if ( ! preg_match( '/{{\w+(?::\w+)?}}|{url_parameter(?:[:}])/', (string) $template, $match, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		$prefix = substr( $template, 0, $match[0][1] );

		// A path must have a literal first segment: "/{{x}}" could render as a network-path reference ("//host")
		return ! preg_match( '#^(?:https?://[^/?\#\\\\{}\s]+[/?\#]|/[^/\\\\{]|[?\#])#i', $prefix );
	}

	/**
	 * Render authored dynamic data while keeping form values literal.
	 *
	 * @since 2.4.3 Submitted values are restored after dynamic-data rendering.
	 *
	 * @param string $content             Authored template.
	 * @param bool   $has_file_properties Whether file-property placeholders are supported.
	 * @param array  $additional_values   Literal values keyed by placeholder ID, such as all_fields.
	 * @param string $context             'json' escapes values inside JSON strings; 'html' escapes submitted values for markup; 'text' leaves them unchanged.
	 * @return string Rendered template.
	 */
	public function render_data( $content, $has_file_properties = false, $additional_values = [], $context = 'text' ) {
		// Keep submitted values outside the dynamic-data parser, including aggregated email fields.
		$prefix     = 'bricks_form_' . bin2hex( random_bytes( 16 ) ) . '_';
		$values     = [];
		$raw_values = [];
		$pattern    = $has_file_properties ? '/{{(\w+)(?::(\w+))?}}/' : '/{{(\w+)}}/';

		/**
		 * 'html' (success message, HTML emails): Submitted values stay opaque tokens until dynamic data has
		 * rendered, then escape_html_tokens() escapes each one for its position in the final markup.
		 * Caller-supplied $additional_values (e.g. all_fields) are already sanitized and stay as-is.
		 *
		 * #123pt1nyhyf, #123pt1nyhyh
		 */
		$richtext_fields = [];
		foreach ( $this->form_settings['fields'] ?? [] as $field ) {
			if ( ( $field['type'] ?? '' ) === 'richtext' && ! empty( $field['id'] ) ) {
				$richtext_fields[] = $field['id'];
			}
		}

		$html_tokens = [];

		$encode = function( $value ) use ( $context ) {
			return $context === 'json' ? substr( wp_json_encode( $value ), 1, -1 ) : $value;
		};

		$content = preg_replace_callback(
			$pattern,
			function( $match ) use ( $additional_values, $encode, $prefix, $richtext_fields, &$values, &$raw_values, &$html_tokens ) {
				$field_id = $match[1];
				$property = $match[2] ?? '';

				if ( $property ) {
					$value = $this->get_file_field_property( $field_id, $property );
				} else {
					$value = array_key_exists( $field_id, $additional_values ) ? $additional_values[ $field_id ] : $this->get_field_value( $field_id );
					$value = is_array( $value ) ? implode( ', ', $value ) : $value;
				}

				$value                = (string) $value;
				$token                = $prefix . count( $values );
				$raw_values[ $token ] = $value;
				$values[ $token ]     = $encode( $value );

				if ( $property || ! array_key_exists( $field_id, $additional_values ) ) {
					$html_tokens[ $token ] = ! $property && in_array( $field_id, $richtext_fields, true ) ? 'richtext' : 'text';
				}

				return $token;
			},
			$content
		);

		$fields = $this->get_fields();

		/**
		 * ACF rows need the containing page as the post context while their rebuilt
		 * Query supplies the row context. All other submissions keep the legacy loopId.
		 *
		 * #86cazz5mc; @since 2.3.11
		 */
		$loop_post_id = $this->query_context ? $this->post_id : ( isset( $fields['loopId'] ) ? absint( $fields['loopId'] ) : 0 );

		$restore_args   = function( $args ) use ( &$raw_values ) {
			foreach ( $args as $key => $arg ) {
				if ( is_string( $arg ) ) {
					$args[ $key ] = strtr( $arg, $raw_values );
				}
			}
			return $args;
		};
		$protect_result = function( $value, $callback, $args ) use ( &$raw_values, &$values, &$html_tokens, $prefix, $encode ) {
			foreach ( $args as $arg ) {
				if ( is_string( $value ) && is_string( $arg ) && strtr( $arg, $raw_values ) !== $arg ) {
					// Seal the whole result: callbacks may transform input into new tag-looking text.
					$value                = strtr( $value, $raw_values );
					$token                = $prefix . count( $values );
					$raw_values[ $token ] = $value;
					$values[ $token ]     = $encode( $value );
					// Callback results containing submitted values are escaped for their position too (e.g. an href)
					$html_tokens[ $token ] = 'text';
					return $token;
				}
			}
			return $value;
		};

		$provider_depth         = 0;
		$restore_provider_args  = function( $args, $tag ) use ( $restore_args, &$provider_depth ) {
			// Echo arguments are restored later, after callback syntax and nested tags are parsed.
			if ( $tag === 'echo' ) {
				return $args;
			}
			++$provider_depth;
			return $restore_args( $args );
		};
		$finish_provider        = function( $tag ) use ( &$provider_depth ) {
			if ( $tag !== 'echo' ) {
				--$provider_depth;
			}
		};
		$protect_provider_input = function( $content ) use ( &$raw_values, &$provider_depth ) {
			if ( ! $provider_depth || ! is_string( $content ) ) {
				return $content;
			}
			// A provider may render its arguments internally; submitted tags must stay opaque there too.
			$literals = [];
			foreach ( $raw_values as $token => $literal ) {
				if ( strpos( $literal, '{' ) !== false ) {
					$literals[ $literal ] = $token;
				}
			}
			return strtr( $content, $literals );
		};
		add_filter( 'bricks/dynamic_data/provider_args', $restore_provider_args, 10, 2 );
		add_filter( 'bricks/dynamic_data/provider_result', $protect_result, 10, 3 );
		add_filter( 'bricks/dynamic_data/provider_render_input', $protect_provider_input );
		add_action( 'bricks/dynamic_data/provider_finished', $finish_provider );
		add_filter( 'bricks/dynamic_data/echo_callback_args', $restore_args );
		add_filter( 'bricks/dynamic_data/echo_callback_result', $protect_result, 10, 3 );
		try {
			$content = bricks_render_dynamic_data( $content, $loop_post_id );
		} finally {
			remove_filter( 'bricks/dynamic_data/provider_args', $restore_provider_args );
			remove_filter( 'bricks/dynamic_data/provider_result', $protect_result );
			remove_filter( 'bricks/dynamic_data/provider_render_input', $protect_provider_input );
			remove_action( 'bricks/dynamic_data/provider_finished', $finish_provider );
			remove_filter( 'bricks/dynamic_data/echo_callback_args', $restore_args );
			remove_filter( 'bricks/dynamic_data/echo_callback_result', $protect_result );
		}

		return $context === 'html' ? $this->escape_html_tokens( $content, $values, $html_tokens, $prefix ) : strtr( $content, $values );
	}

	/**
	 * Replace submitted-value tokens in rendered markup, escaped for their position
	 *
	 * Tags are parsed with quoted attribute values intact, so a '>' inside a value can't hide the context.
	 * - Text: esc_html (rich text keeps basic formatting, without links or media).
	 * - Attribute values: esc_attr. Unquoted values also encode whitespace, '=' and '`' as character references,
	 *   so they can't start a new attribute.
	 * - URL attributes: the complete value (authored text plus submitted values, as the browser decodes it)
	 *   must not use a disallowed protocol such as javascript:.
	 * - Tag and attribute names, event handler attributes (on*), script and style contents: dropped.
	 *
	 * #123pt1nyhyf, #123pt1nyhyh
	 *
	 * @since 2.4.3
	 *
	 * @param string $content     Rendered markup containing tokens.
	 * @param array  $values      Values keyed by token.
	 * @param array  $html_tokens Submitted value tokens: 'text' or 'richtext'.
	 * @param string $prefix      Token prefix.
	 * @return string
	 */
	private function escape_html_tokens( $content, $values, $html_tokens, $prefix ) {
		$token_pattern = '/' . preg_quote( $prefix, '/' ) . '\\d+/';

		$replace = function( $text, $encoder ) use ( $token_pattern, $values, $html_tokens ) {
			return preg_replace_callback(
				$token_pattern,
				function( $match ) use ( $encoder, $values, $html_tokens ) {
					$value = $values[ $match[0] ] ?? '';

					// Aggregated markup is trusted only in text, never in attributes or executable contexts.
					return $encoder( $value, $html_tokens[ $match[0] ] ?? 'markup' );
				},
				$text
			);
		};

		$text_encoder = function( $value, $type ) {
			if ( $type === 'markup' ) {
				return $value;
			}

			// Allowed rich text tags have no attributes, so quotes can be encoded too
			return $type === 'richtext' ? str_replace( [ '"', "'" ], [ '&quot;', '&#039;' ], wp_kses( $value, self::RICHTEXT_ALLOWED_TAGS ) ) : esc_html( $value );
		};

		$attribute_encoder = function( $value ) {
			return esc_attr( $value );
		};

		$unquoted_encoder = function( $value ) {
			return str_replace( [ ' ', "\t", "\n", "\r", "\f", '=', '`' ], [ '&#32;', '&#9;', '&#10;', '&#13;', '&#12;', '&#61;', '&#96;' ], esc_attr( $value ) );
		};

		$raw_value = function( $value ) {
			return $value;
		};

		$drop = function() {
			return '';
		};

		$url_attributes = [ 'href', 'src', 'srcset', 'action', 'formaction', 'poster', 'cite', 'background', 'data', 'xlink:href' ];

		// Comments, closing and opening tags (quoted attribute values may contain '>')
		$parts       = preg_split( '/(<!--.*?-->|<\\/?[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/s', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$raw_text    = '';
		$script_mode = 'data';
		$output      = '';

		foreach ( $parts as $index => $part ) {
			// Script's HTML comment states can make an apparent </script> part of the code.
			// https://html.spec.whatwg.org/multipage/parsing.html#script-data-double-escaped-state
			if ( $raw_text === 'script' ) {
				preg_match_all( '/<!--|-->|<\\/?script(?=[\\x09\\x0a\\x0c\\x0d \\/>])/i', $part, $markers );
				foreach ( $markers[0] as $marker ) {
					$marker = strtolower( $marker );
					if ( $marker === '<!--' && $script_mode === 'data' ) {
						$script_mode = 'escaped';
					} elseif ( $marker === '-->' && in_array( $script_mode, [ 'escaped', 'double-escaped' ], true ) ) {
						$script_mode = 'data';
					} elseif ( $marker === '<script' && $script_mode === 'escaped' ) {
						$script_mode = 'double-escaped';
					} elseif ( $marker === '</script' ) {
						$script_mode = $script_mode === 'double-escaped' ? 'escaped' : 'closed';
					}
				}
			}

			// Apparent tags inside raw text are code; only its matching end tag closes that context.
			if ( $raw_text ) {
				$closed = $raw_text === 'script' ? $script_mode === 'closed' : ( $index % 2 !== 0 && preg_match( '/^<\\/style(?:\\s|\\/|>)/i', $part ) );
				if ( $closed ) {
					$raw_text = '';
					// A grouped comment can contain code before the closing script tag.
					if ( strpos( $part, '<!--' ) === 0 ) {
						$output .= $replace( $part, $drop );
						continue;
					}
				} else {
					$output .= $replace( $part, $drop );
					continue;
				}
			}

			// Even indexes are text between tags. Script and style contents are code: drop submitted values there
			if ( $index % 2 === 0 ) {
				$output .= $replace( $part, $raw_text ? $drop : $text_encoder );
				continue;
			}

			if ( strpos( $part, '<!--' ) === 0 ) {
				$output .= $replace( $part, $text_encoder );
				continue;
			}

			preg_match( '/^<(\\/?)([^\s\/>]+)/', $part, $tag );
			$tag_name = strtolower( $tag[2] );

			if ( in_array( $tag_name, [ 'script', 'style' ], true ) ) {
				$raw_text    = $tag[1] === '' ? $tag_name : '';
				$script_mode = 'data';
			}

			$result = $replace( $tag[0], $drop );
			$length = strlen( $part );
			$pos    = strlen( $tag[0] );

			while ( $pos < $length ) {
				// Attribute with a value
				if ( preg_match( '/\\G(\s*)([^\s"\'>\/=]+)(\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'>]*)/', $part, $attribute, 0, $pos ) ) {
					list( $all, $space, $name, $equals, $value ) = $attribute;

					$attribute_name = strtolower( $name );
					$quote          = in_array( $value[0] ?? '', [ '"', "'" ], true ) ? $value[0] : '';
					$inner          = $quote ? substr( $value, 1, -1 ) : $value;

					// Attribute escaping does not neutralize nested HTML or CSS after entity decoding.
					if ( strpos( $attribute_name, 'on' ) === 0 || in_array( $attribute_name, [ 'srcdoc', 'style' ], true ) ) {
						$inner = $replace( $inner, $drop );
					} elseif ( in_array( $attribute_name, $url_attributes, true ) && preg_match( $token_pattern, $inner ) ) {
						// Check the complete URL as the browser decodes it, ignoring whitespace and control characters in the scheme
						$url    = html_entity_decode( $replace( $inner, $raw_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$url    = preg_replace( '/[\\x00-\\x20\\x7f]+/', '', $url );
						$scheme = preg_match( '/^([^\\/?#]+):/', $url, $match ) ? strtolower( $match[1] ) : '';
						$inner  = $scheme && ! in_array( $scheme, wp_allowed_protocols(), true ) ? '' : $replace( $inner, $quote ? $attribute_encoder : $unquoted_encoder );
					} else {
						$inner = $replace( $inner, $quote ? $attribute_encoder : $unquoted_encoder );
					}

					$result .= $space . $replace( $name, $drop ) . $equals . $quote . $inner . $quote;
					$pos    += strlen( $all );
					continue;
				}

				// Attribute without a value
				if ( preg_match( '/\\G(\s*)([^\s"\'>\/=]+)/', $part, $attribute, 0, $pos ) ) {
					$result .= $attribute[1] . $replace( $attribute[2], $drop );
					$pos    += strlen( $attribute[0] );
					continue;
				}

				$result .= $part[ $pos ];
				++$pos;
			}

			$output .= $result;
		}

		return $output;
	}

	/**
	 * Get value of individual form field by field ID
	 *
	 * @param string $field_id Field ID.
	 */
	public function get_field_value( $field_id = '' ) {
		$form_fields = $this->get_fields();

		// NOTE: Undocumented {{referrer_url}}
		if ( $field_id === 'referrer_url' && isset( $_POST['referrer'] ) ) {
			return esc_url( $_POST['referrer'] );
		}

		if ( empty( $field_id ) || ! array_key_exists( "form-field-{$field_id}", $form_fields ) ) {
			return '';
		}

		return $form_fields[ "form-field-{$field_id}" ];
	}

	/**
	 * Get property of file field by field ID
	 *
	 * @param string $field_id Field ID.
	 * @param string $property Property name (name, type, size, id, url).
	 *
	 * @return string
	 * @since 2.2
	 */
	public function get_file_field_property( $field_id, $property ) {
		$uploaded_files = $this->get_uploaded_files();

		// Find input name
		$input_name = "form-field-$field_id";

		if ( ! empty( $this->form_settings['fields'] ) ) {
			foreach ( $this->form_settings['fields'] as $field ) {
				if ( $field['id'] === $field_id && ! empty( $field['name'] ) ) {
					$input_name = $field['name'];
					break;
				}
			}
		}

		if ( empty( $uploaded_files[ $input_name ] ) ) {
			return '';
		}

		$files  = $uploaded_files[ $input_name ];
		$values = [];

		foreach ( $files as $file ) {
			switch ( $property ) {
				case 'name':
					$values[] = $file['name'];
					break;
				case 'type':
					$values[] = $file['type'];
					break;
				case 'size':
					$values[] = $file['size'];
					break;
				case 'id':
					if ( isset( $file['attachment_id'] ) ) {
						$values[] = $file['attachment_id'];
					}
					break;
				case 'url':
					if ( isset( $file['attachment_id'] ) ) {
						$values[] = wp_get_attachment_url( $file['attachment_id'] );
					} elseif ( isset( $file['file'] ) ) {
						$upload_dir = wp_upload_dir();
						if ( strpos( $file['file'], $upload_dir['basedir'] ) === 0 ) {
							$values[] = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file['file'] );
						}
					}
					break;
			}
		}

		return implode( ', ', $values );
	}

	/**
	 * Available actions after form submission
	 *
	 * @return array
	 */
	public static function get_available_actions() {
		$actions = [
			'custom'         => esc_html__( 'Custom', 'bricks' ),
			'email'          => esc_html__( 'Email', 'bricks' ),
			'webhook'        => esc_html__( 'Webhook', 'bricks' ),
			'redirect'       => esc_html__( 'Redirect', 'bricks' ),
			'mailchimp'      => 'Mailchimp',
			'sendgrid'       => 'SendGrid',
			'login'          => esc_html__( 'User login', 'bricks' ),
			'registration'   => esc_html__( 'User registration', 'bricks' ),
			'lost-password'  => esc_html__( 'Lost password', 'bricks' ),
			'reset-password' => esc_html__( 'Reset password', 'bricks' ),
			'create-post'    => esc_html__( 'Create post', 'bricks' ), // @since 2.1
			'update-post'    => esc_html__( 'Update post', 'bricks' ), // @since 2.1
		];

		if ( \Bricks\Database::get_setting( 'passwordProtectionEnabled', false ) ) {
			$actions['unlock-password-protection'] = esc_html__( 'Unlock password protection', 'bricks' );
		}

		// Save form submission (@since 1.9.2)
		if ( \Bricks\Database::get_setting( 'saveFormSubmissions', false ) ) {
			$actions['save-submission'] = esc_html__( 'Save submission', 'bricks' );
		}

		return $actions;
	}

	/**
	 * Set form submit error messages
	 *
	 * @param array $error_messages
	 *
	 * @since 1.7.1
	 */
	public function set_error_messages( $error_messages ) {
		if ( empty( $error_messages ) ) {
			return;
		}

		if ( is_string( $error_messages ) ) {
			$error_messages = [ $error_messages ];
		}

		// One error: Return error message as string
		if ( count( $error_messages ) === 1 ) {
			$this->set_result(
				[
					'type'    => 'error',
					'message' => $error_messages,
				]
			);

			return;
		}

		// More than one error: Return error messages as unordered list
		$message = '<ul>';

		// Combine $error_messages into a single string
		foreach ( $error_messages as $error_message ) {
			$message .= "<li>{$error_message}</li>";
		}

		$message .= '</ul>';

		$this->set_result(
			[
				'type'    => 'error',
				'message' => $message,
			]
		);
	}

	/**
	 * Validate required fields
	 *
	 * @param array|string $custom_validation_errors Custom validation errors adding via filter 'bricks_form_validation_errors'.
	 *
	 * @return array
	 *
	 * @since 1.7.1
	 */
	public function validate_required_fields( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$uploaded_files       = $this->get_uploaded_files();
		$form_settings        = $this->get_settings();
		$form_settings_fields = ! empty( $form_settings['fields'] ) ? $form_settings['fields'] : [];

		$errors = [];

		foreach ( $form_settings_fields as $form_settings_field ) {
			// Skip if field is not required
			if ( empty( $form_settings_field['required'] ) ) {
				continue;
			}

			// Skip if field type is 'html' or 'hidden' (@since 1.12)
			if ( in_array( $form_settings_field['type'], [ 'html', 'hidden' ], true ) ) {
				continue;
			}

			// Skip if field is Honeypot (@since 1.12.2)
			if ( isset( $form_settings_field['isHoneypot'] ) ) {
				continue;
			}

			$error = false;

			// File field: Check if file is uploaded
			if ( $form_settings_field['type'] === 'file' ) {
				$field_id   = $form_settings_field['id'];
				$field_name = "form-field-$field_id"; // default field name

				// Check for and use custom field name
				if ( ! empty( $form_settings_field['name'] ) ) {
					$field_name = $form_settings_field['name'];
				}

				$existing_files = $this->get_verified_existing_file_ids( $field_id );

				if ( empty( $uploaded_files[ $field_name ] ) && empty( $existing_files ) ) {
					$error = true;
				}
			}

			// All other field types
			else {
				// Custom field integrations may submit structured arrays; share the action conditions' empty-value rules.
				$error = $this->is_action_condition_value_empty( $submitted_fields[ "form-field-{$form_settings_field['id']}" ] ?? null );
			}

			if ( $error ) {
				// Field is required & empty: Add error message
				$field_label = ! empty( $form_settings_field['label'] ) ? $form_settings_field['label'] : $form_settings_field['type'];

				$errors[] = esc_html__( 'Required', 'bricks' ) . ': ' . esc_html( $field_label );
			}
		}

		// Custom validation error is a string: Convert to array
		if ( $custom_validation_errors && is_string( $custom_validation_errors ) ) {
			$custom_validation_errors = [ $custom_validation_errors ];
		}

		// Filter out empty error strings
		if ( is_array( $custom_validation_errors ) && count( $custom_validation_errors ) ) {
			$custom_validation_errors = array_filter( $custom_validation_errors );

			$errors = array_merge( $errors, $custom_validation_errors );
		}

		// Return: Array of validation errors (each error as a string, representing a single error message)
		return $errors;
	}

	/**
	 * Validate password confirmation
	 *
	 * Checks if password fields that require confirmation have matching passwords.
	 *
	 * @param array $custom_validation_errors
	 * @return array
	 *
	 * @since 2.4
	 */
	public function validate_password_confirmation( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$form_settings_fields = ! empty( $this->form_settings['fields'] ) ? $this->form_settings['fields'] : [];

		$errors = is_array( $custom_validation_errors ) ? $custom_validation_errors : [];

		foreach ( $form_settings_fields as $form_settings_field ) {
			// Only check password fields with confirmation enabled
			if ( $form_settings_field['type'] !== 'password' || empty( $form_settings_field['passwordIsConfirmation'] ) ) {
				continue;
			}

			// Get the main password field ID
			$main_field_id = ! empty( $form_settings_field['passwordMainFieldId'] ) ? $form_settings_field['passwordMainFieldId'] : '';

			if ( empty( $main_field_id ) ) {
				continue;
			}

			// Get main password field value from submitted data
			$main_field_name = "form-field-{$main_field_id}";
			$main_password   = isset( $submitted_fields[ $main_field_name ] ) ? $submitted_fields[ $main_field_name ] : '';

			// Get confirmation password field value
			$confirmation_field_name = ! empty( $form_settings_field['name'] ) ? $form_settings_field['name'] : "form-field-{$form_settings_field['id']}";
			$confirmation_password   = isset( $submitted_fields[ $confirmation_field_name ] ) ? $submitted_fields[ $confirmation_field_name ] : '';

			// Keep optional password pairs valid when both fields are empty.
			if ( $main_password === '' && $confirmation_password === '' ) {
				continue;
			}

			// Check if passwords match
			if ( $main_password !== $confirmation_password ) {
				$error_message = ! empty( $form_settings_field['passwordConfirmationErrorMessage'] ) ?
				esc_html( $form_settings_field['passwordConfirmationErrorMessage'] ) :
				esc_html__( 'Passwords do not match', 'bricks' );

				$errors[] = $error_message;
			}
		}

		return $errors;
	}

	/**
	 * Validate email fields.
	 *
	 * @param array|string $custom_validation_errors Custom validation errors added via the 'bricks/form/validate' filter.
	 * @return array
	 *
	 * @since 2.3.10
	 */
	public function validate_email_fields( $custom_validation_errors = [] ) {
		$submitted_fields     = $this->get_fields();
		$form_settings        = $this->get_settings();
		$form_settings_fields = ! empty( $form_settings['fields'] ) ? $form_settings['fields'] : [];
		$errors               = is_array( $custom_validation_errors ) ? $custom_validation_errors : [];

		if ( is_string( $custom_validation_errors ) && $custom_validation_errors !== '' ) {
			$errors[] = $custom_validation_errors;
		}

		foreach ( $form_settings_fields as $form_settings_field ) {
			if ( ( $form_settings_field['type'] ?? '' ) !== 'email' || empty( $form_settings_field['id'] ) ) {
				continue;
			}

			$field_name  = "form-field-{$form_settings_field['id']}";
			$field_value = $submitted_fields[ $field_name ] ?? '';

			// Empty optional fields are valid. Required fields are handled separately.
			if ( $field_value === '' ) {
				continue;
			}

			if ( self::is_valid_email( $field_value ) ) {
				continue;
			}

			$errors[] = ! empty( $form_settings_field['errorMessage'] )
				? esc_html( $form_settings_field['errorMessage'] )
				: esc_html__( 'Email address is not valid.', 'bricks' );
		}

		return $errors;
	}

	/**
	 * Check whether an email address has valid syntax and local-part dot placement.
	 *
	 * WordPress allows leading, trailing, and consecutive dots in the local part,
	 * so those RFC-invalid placements need an explicit check.
	 *
	 * @param mixed $email Email address to validate.
	 * @return boolean
	 *
	 * @since 2.3.10
	 */
	public static function is_valid_email( $email ) {
		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			return false;
		}

		$local_part = explode( '@', $email, 2 )[0];

		return $local_part[0] !== '.' && substr( $local_part, -1 ) !== '.' && strpos( $local_part, '..' ) === false;
	}

	/**
	 * Get a specific frontend URL parameter
	 * - Used by {url_parameter} DD
	 *
	 * @param string $parameter
	 * @since 1.11
	 */
	public static function get_submission_url_param( $parameter ) {
		return self::$submission_url_params[ $parameter ] ?? '';
	}

	/**
	 * Get ACF field key from meta key
	 *
	 * Supports nested fields inside groups, repeaters, and flexible content.
	 *
	 * @since 2.1
	 */
	public static function get_acf_field_key_from_meta_key( $meta_key = '', $post_id = '', $post_type = '' ) {
		$acf_field_key = '';

		// Return empty if ACF is not active
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $acf_field_key;
		}

		// Get field key from field groups associated with the post
		$group_query_args = [];

		if ( ! empty( $post_id ) ) {
			$group_query_args['post_id'] = $post_id;
		}

		if ( ! empty( $post_type ) ) {
			$group_query_args['post_type'] = $post_type;
		}

		$groups = acf_get_field_groups( $group_query_args );

		foreach ( $groups as $group ) {
			$fields = ! empty( $group['key'] ) ? acf_get_fields( $group['key'] ) : [];

			// STEP 1: Try simple direct match first (most common case - top-level fields)
			foreach ( $fields as $field ) {
				if ( ! empty( $field['name'] ) && $field['name'] === $meta_key && ! empty( $field['key'] ) ) {
					return $field['key'];
				}
			}

			// STEP 2: If not found and meta_key contains underscore, search nested fields
			if ( strpos( $meta_key, '_' ) !== false ) {
				$acf_field_key = self::search_acf_fields_for_meta_key( $fields, $meta_key );

				if ( $acf_field_key ) {
					return $acf_field_key;
				}
			}
		}

		return $acf_field_key;
	}

	/**
	 * Recursively search ACF fields for a matching meta key
	 *
	 * Handles nested fields in groups, repeaters, and flexible content.
	 *
	 * @param array  $fields   Array of ACF fields to search
	 * @param string $meta_key The meta key to find
	 * @param string $prefix   Meta key prefix from parent fields (for nested fields)
	 *
	 * @return string Field key if found, empty string otherwise
	 *
	 * @since 2.2
	 */
	private static function search_acf_fields_for_meta_key( $fields, $meta_key, $prefix = '' ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}

		foreach ( $fields as $field ) {
			$field_name = $field['name'] ?? '';

			if ( empty( $field_name ) ) {
				continue;
			}

			// Build the full meta key for this field (including parent prefixes)
			$full_meta_key = $prefix ? $prefix . '_' . $field_name : $field_name;

			// Check if this field matches the meta key
			if ( $full_meta_key === $meta_key && ! empty( $field['key'] ) ) {
				return $field['key'];
			}

			// Only recurse if the meta_key starts with the current field's full path
			// This optimization avoids searching branches that can't possibly match
			if ( strpos( $meta_key, $full_meta_key . '_' ) !== 0 ) {
				continue;
			}

			// Search in sub_fields (groups, repeaters, etc.)
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$found = self::search_acf_fields_for_meta_key( $field['sub_fields'], $meta_key, $full_meta_key );

				if ( $found ) {
					return $found;
				}
			}

			// Search in flexible content layouts
			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$found = self::search_acf_fields_for_meta_key( $layout['sub_fields'], $meta_key, $full_meta_key );

						if ( $found ) {
							return $found;
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Get Meta Box field key from meta key
	 *
	 * @since 2.1
	 */
	public static function get_meta_box_field_key_from_meta_key( $meta_key, $post_id ) {
		// Return empty if Meta Box is not active
		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return '';
		}

		// Check if meta key exists in Meta Box fields
		$groups = rwmb_get_object_fields( $post_id );
		if ( isset( $groups[ $meta_key ] ) ) {
			return $meta_key;
		}

		return '';
	}

	/**
	 * Apply component property values to form settings
	 *
	 * When a form is inside a component, retrieve the component instance
	 * and apply property values to form settings
	 *
	 * @param int    $post_id           Post ID
	 * @param string $form_component_id Component instance ID
	 * @param string $form_element_id   Form element ID
	 *
	 * @since 2.2
	 */
	private function apply_component_properties( $post_id, $form_component_id, $form_element_id ) {
		$component_id     = $form_component_id;
		$instance_element = false;

		// Resolve by the submitted component instance first.
		$instance_data    = \Bricks\Helpers::get_element_data( $post_id, $form_component_id );
		$instance_element = $instance_data['element'] ?? false;

		// Component-root forms submit the form element ID as their instance context.
		if ( ! $instance_element || ! isset( $instance_element['cid'] ) ) {
			$instance_data    = \Bricks\Helpers::get_element_data( $post_id, $form_element_id );
			$instance_element = $instance_data['element'] ?? false;
		}

		if ( ( ! $instance_element || ! isset( $instance_element['cid'] ) ) && strpos( $form_component_id, '-' ) !== false ) {
			$slot_instance_id      = explode( '-', $form_component_id, 2 )[0] ?? '';
			$slot_instance_data    = \Bricks\Helpers::get_element_data( $post_id, $slot_instance_id );
			$slot_instance_element = $slot_instance_data['element'] ?? false;

			if ( $slot_instance_element && isset( $slot_instance_element['cid'] ) ) {
				$instance_element = $slot_instance_element;
				$instance_data    = $slot_instance_data;
			}
		}

		// The instance's properties come from the post storing it (#123pt1nyhye)
		$instance_source    = $instance_data['source_post_id'] ?? $instance_data['source_id'] ?? null;
		$instance_source_id = is_numeric( $instance_source ) ? absint( $instance_source ) : null;

		if ( $instance_element && isset( $instance_element['cid'] ) ) {
			$component_id = $instance_element['cid'];

			if ( ! isset( $instance_element['instanceId'] ) && strpos( $form_component_id, '-' ) !== false ) {
				// Nested component render IDs include the owner instance, which parent property bindings need during AJAX.
				$component_id_parts             = explode( '-', $form_component_id, 2 );
				$instance_element['instanceId'] = $component_id_parts[1] ?? '';
			}
		}

		// If not found in Bricks content, try Gutenberg blocks
		if ( ! $instance_element || ! isset( $instance_element['cid'] ) ) {
			// Extract component ID (Gutenberg uses format: component_id-uuid)
			$component_id = strpos( $form_component_id, '-' ) !== false ? explode( '-', $form_component_id )[0] : $form_component_id;

			// For Gutenberg, search using the ID that contains the UUID
			// - Form IS component: form_element_id has UUID, form_component_id doesn't
			// - Form INSIDE component: form_component_id has UUID
			$search_id = strpos( $form_component_id, '-' ) !== false ? $form_component_id : $form_element_id;

			$block_properties = $this->get_gutenberg_block_properties( $post_id, $component_id, $search_id );
			if ( $block_properties === null ) {
				return;
			}

			$component          = \Bricks\Helpers::get_component_by_cid( $component_id );
			$instance_source_id = absint( $post_id ); // Block properties come from the submitted post's content

			if ( ! $component || empty( $component['elements'] ) ) {
				return;
			}

			// Create instance element for get_component_instance()
			$instance_element = [
				'id'         => $search_id,
				'cid'        => $component_id,
				'properties' => $block_properties,
			];
		}

		// Apply properties to component elements
		$component_instance = \Bricks\Helpers::get_component_instance( $instance_element );

		if ( empty( $component_instance['elements'] ) ) {
			return;
		}

		// Determine target element ID
		// - Bricks: form_element_id = component_id means form IS the component
		// - Gutenberg: form_element_id starts with component_id- means form IS the component
		$is_form_the_component = ( $form_element_id === $component_id ) || ( strpos( $form_element_id, $component_id . '-' ) === 0 );
		$target_id             = $is_form_the_component ? $component_id : $form_element_id;

		// Find and apply form settings with properties
		foreach ( $component_instance['elements'] as $element ) {
			if ( $element['id'] === $target_id && isset( $element['settings'] ) ) {
				$this->form_settings      = $element['settings'];
				$this->settings_source_id = $instance_source_id;
				$this->form_element       = $element;

				if ( $is_form_the_component ) {
					$this->form_element['id'] = $instance_element['id'];
				}
				break;
			}
		}
	}

	/**
	 * Get form settings from expanded nested component data.
	 *
	 * A form component nested inside another component is not stored as a top-level
	 * page element, so Helpers::get_element_settings() can't resolve it during AJAX
	 * form submission. Expand component data for the current page/templates and look
	 * up the rendered form element there.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $form_element_id   Form element ID.
	 * @param string $form_component_id Optional rendered component context ID.
	 *
	 * @return array|false
	 *
	 * @since 2.3
	 */
	private function get_nested_form_settings( $post_id, $form_element_id, $form_component_id = '' ) {
		if ( ! $post_id || ! $form_element_id ) {
			return false;
		}

		if ( bricks_is_ajax_call() || bricks_is_rest_call() ) {
			\Bricks\Database::set_active_templates( $post_id );
		}

		// Element sets keyed by the post (template or current post) that stores them, to validate the settings source (#123pt1nyhye)
		$element_sets = [];
		$areas        = [ 'content', 'header', 'footer' ];

		foreach ( $areas as $area ) {
			$template_id = \Bricks\Database::$active_templates[ $area ] ?? 0;

			if ( ! $template_id ) {
				continue;
			}

			$elements = \Bricks\Database::get_data( $template_id, $area );

			if ( ! empty( $elements ) && is_array( $elements ) ) {
				$element_sets[] = [ $template_id, $elements ];
			}
		}

		$current_post_elements = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );

		if ( ! empty( $current_post_elements ) && is_array( $current_post_elements ) ) {
			$element_sets[] = [ $post_id, $current_post_elements ];
		}

		// Render IDs can combine the direct and owner instances for nested or slotted components.
		$submitted_context_ids = [ (string) $form_component_id ];

		if ( strpos( (string) $form_component_id, '-' ) !== false ) {
			$submitted_context_ids = array_merge( $submitted_context_ids, explode( '-', (string) $form_component_id ) );
		}

		$submitted_context_ids = array_values( array_unique( array_filter( $submitted_context_ids ) ) );

		foreach ( $element_sets as list( $source_id, $elements ) ) {
			$expanded_elements = \Bricks\Database::get_component_data( $elements );

			if ( empty( $expanded_elements ) || ! is_array( $expanded_elements ) ) {
				continue;
			}

			foreach ( $expanded_elements as $element ) {
				if ( (string) ( $element['id'] ?? '' ) !== (string) $form_element_id || ( $element['name'] ?? '' ) !== 'form' ) {
					continue;
				}

				if ( $form_component_id ) {
					$element_context_ids = [
						(string) ( $element['id'] ?? '' ),
						(string) ( $element['cid'] ?? '' ),
						(string) ( $element['instanceId'] ?? '' ),
						(string) ( $element['renderInstanceId'] ?? '' ),
						(string) ( $element['rootInstanceId'] ?? '' ),
						(string) ( $element['slotInstanceId'] ?? '' ),
					];

					$element_context_ids = array_values( array_unique( array_filter( $element_context_ids ) ) );

					if ( empty( array_intersect( $submitted_context_ids, $element_context_ids ) ) ) {
						continue;
					}
				}

				$component_instance_settings = ! empty( $element['cid'] ) ? \Bricks\Helpers::get_component_instance( $element, 'settings' ) : false;

				if ( is_array( $component_instance_settings ) ) {
					$this->settings_source_id = absint( $source_id );
					$this->form_element       = $element;
					return $component_instance_settings;
				}

				if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
					$this->settings_source_id = absint( $source_id );
					$this->form_element       = $element;
					return $element['settings'];
				}
			}
		}

		return false;
	}

	/**
	 * Get Gutenberg block properties from post content
	 *
	 * @param int    $post_id           Post ID
	 * @param string $component_id      Component template ID
	 * @param string $form_element_id   Form element ID (may contain UUID)
	 *
	 * @return array|null Block properties, or null when no saved instance matches.
	 *
	 * @since 2.2
	 */
	private function get_gutenberg_block_properties( $post_id, $component_id, $form_element_id ) {
		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_content ) || ! function_exists( 'parse_blocks' ) ) {
			return null;
		}

		$blocks = parse_blocks( $post->post_content );

		return $this->find_block_properties_recursive( $blocks, $component_id, $form_element_id );
	}

	/**
	 * Recursively search for block properties in parsed blocks
	 *
	 * @param array  $blocks            Parsed blocks array
	 * @param string $component_id      Component template ID
	 * @param string $form_element_id   Form element ID (may contain UUID)
	 *
	 * @return array|null Block properties, or null when no saved instance matches.
	 *
	 * @since 2.2
	 */
	private function find_block_properties_recursive( $blocks, $component_id, $form_element_id ) {
		foreach ( $blocks as $block ) {
			// Check if this is a matching Bricks component block
			$block_component_id = Helpers::get_component_id_from_block( $block );

			if ( $block_component_id === $component_id ) {
				$attrs    = $block['attrs'] ?? [];
				$block_id = $attrs['blockId'] ?? '';

				// Match the rendered block identity exactly: substring matches permit forged suffixes.
				$instance_id = $block_id ? $component_id . '-' . $block_id : $component_id;
				if ( $form_element_id === $instance_id ) {
					return $attrs['properties'] ?? [];
				}
			}

			// Recursively search inner blocks
			if ( ! empty( $block['innerBlocks'] ) ) {
				$properties = $this->find_block_properties_recursive( $block['innerBlocks'], $component_id, $form_element_id );

				if ( $properties !== null ) {
					return $properties;
				}
			}
		}

		return null;
	}
}

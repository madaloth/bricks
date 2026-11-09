<?php
namespace Bricks\Integrations\Form\Actions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Webhook extends Base {
	/**
	 * Webhook action
	 *
	 * @since 2.0
	 */
	public function run( $form ) {
		$form_settings = $form->get_settings();
		$form_fields   = $form->get_fields();
		$form_id       = $form->get_id();
		$post_id       = $form->get_post_id();

		// No webhooks configured
		if ( empty( $form_settings['webhooks'] ) || ! is_array( $form_settings['webhooks'] ) ) {
			return;
		}

		$has_errors = false;

		// Process each webhook endpoint
		foreach ( $form_settings['webhooks'] as $webhook ) {
			// Render dynamic data for the webhook URL (@since 2.0)
			$webhook['url'] = ! empty( $webhook['url'] ) ? $form->render_data( $webhook['url'] ) : '';

			if ( empty( $webhook['url'] ) || ! wp_http_validate_url( $webhook['url'] ) ) {
				$error_message = esc_html__( 'Invalid webhook URL.', 'bricks' );
				\Bricks\Helpers::maybe_log( "Bricks form webhook error: $error_message (Post ID: $post_id; Form ID: $form_id )" );
				$has_errors = true;
				continue;
			}

			// Check rate limiting if enabled
			if ( ! empty( $form_settings['webhookRateLimit'] ) ) {
				$requests_limit = ! empty( $form_settings['webhookRateLimitRequests'] ) ? absint( $form_settings['webhookRateLimitRequests'] ) : 60;
				$transient_key  = 'bricks_webhook_' . md5( $form_id . '_' . $webhook['url'] );
				$current_count  = get_transient( $transient_key );

				if ( false === $current_count ) {
					set_transient( $transient_key, 1, HOUR_IN_SECONDS );
				} elseif ( $current_count >= $requests_limit ) {
					$error_message = esc_html__( 'Rate limit exceeded. Please try again later.', 'bricks' );
					\Bricks\Helpers::maybe_log( "Bricks form webhook error: $error_message (Post ID: $post_id; Form ID: $form_id; Webhook URL: {$webhook['url']})" );
					$has_errors = true;
					continue;
				} else {
					set_transient( $transient_key, $current_count + 1, HOUR_IN_SECONDS );
				}
			}

			// Prepare headers based on content type
			$content_type = $webhook['contentType'] ?? 'json';
			$headers      = [
				'Content-Type' => $content_type === 'json' ? 'application/json' : 'application/x-www-form-urlencoded',
			];

			// Add custom headers if provided
			if ( ! empty( $webhook['headers'] ) ) {
				try {
					$custom_headers = $webhook['headers'];
					// Render dynamic data (@since 2.1). JSON-escape submitted values, so they can't add or override headers (#123pt1nyhyn)
					$custom_headers = $form->render_data( $custom_headers, false, [], 'json' );

					$custom_headers = json_decode( $custom_headers, true );

					if ( is_array( $custom_headers ) ) {
						foreach ( $custom_headers as $header => $value ) {
							$header = sanitize_key( $header );
							$value  = wp_kses( $value, [] );

							if ( ! empty( $header ) ) {
								// Match the built-in key so custom values replace it regardless of casing.
								$header             = $header === 'content-type' ? 'Content-Type' : $header;
								$headers[ $header ] = $value;
							}
						}
					}
				} catch ( \Exception $e ) {
					\Bricks\Helpers::maybe_log( 'Bricks webhook error: Invalid headers format - ' . $e->getMessage() );
				}
			}

			// Prepare the payload
			if ( ! empty( $webhook['dataTemplate'] ) ) {
				// Use custom data template
				$payload = $webhook['dataTemplate'];

				/**
				 * JSON content type: Custom placeholder replacement with JSON escaping
				 *
				 * We intentionally don't use $form->render_data() here because it performs
				 * raw string replacement without JSON escaping. When user input contains
				 * characters that are special in JSON (double quotes, backslashes, newlines,
				 * tabs), the resulting JSON string becomes invalid and json_decode() fails.
				 *
				 * Example: {"message": "{{field}}"} with user input 'Book the "Premium" package'
				 * - render_data() result: {"message": "Book the "Premium" package"} - Invalid JSON
				 * - This method result:   {"message": "Book the \"Premium\" package"} - Valid JSON
				 *
				 * @since 2.2
				 */
				if ( $content_type === 'json' ) {
					$payload = $this->render_data_for_json( $form, $payload );
				} else {
					$payload = $form->render_data( $payload, true );
				}

				// Ensure valid JSON if using JSON format
				if ( $content_type === 'json' ) {
					try {
						$payload = json_decode( $payload, true );
						if ( ! is_array( $payload ) ) {
							$error_message = esc_html__( 'Invalid webhook payload format.', 'bricks' );
							\Bricks\Helpers::maybe_log( 'Bricks webhook error: ' . $error_message );
							$has_errors = true;
							continue;
						}
					} catch ( \Exception $e ) {
						$error_message = esc_html__( 'Invalid webhook payload format.', 'bricks' );
						\Bricks\Helpers::maybe_log( 'Bricks webhook error: ' . $error_message );
						$has_errors = true;
						continue;
					}
				}
			} else {
				// Send all form fields (incl. custom field names and meta like referrer), except transport tokens and honeypots (#123pt1nyhyn)
				$payload = array_diff_key(
					$form_fields,
					array_flip( [ 'action', 'nonce', 'recaptchaToken', 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'urlParams', 'formQueryContext', 'formQueryNonce', 'loopSignature' ] )
				);

				foreach ( $form_settings['fields'] ?? [] as $field ) {
					if ( isset( $field['isHoneypot'] ) && ! empty( $field['id'] ) ) {
						unset( $payload[ "form-field-{$field['id']}" ] );

						if ( ! empty( $field['name'] ) ) {
							unset( $payload[ $field['name'] ] );
						}
					}
				}

				// Add uploaded files to payload
				$uploaded_files = $form->get_uploaded_files();

				if ( ! empty( $uploaded_files ) ) {
					foreach ( $uploaded_files as $field_name => $files ) {
						// Loop through files for this field
						foreach ( $files as $file ) {
							$file_data = [
								'name' => $file['name'],
								'type' => $file['type'],
								'size' => $file['size'] ?? 0,
							];

							// Attachment ID (Media Library)
							if ( isset( $file['attachment_id'] ) ) {
								$file_data['id']  = $file['attachment_id'];
								$file_data['url'] = wp_get_attachment_url( $file['attachment_id'] );
							}

							// Custom directory or default uploads folder
							elseif ( isset( $file['file'] ) ) {
								$upload_dir = wp_upload_dir();

								// If file is in uploads directory, generate URL
								if ( strpos( $file['file'], $upload_dir['basedir'] ) === 0 ) {
									$file_data['url'] = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file['file'] );
								} else {
									$file_data['path'] = $file['file'];
								}
							}

							$payload[ $field_name ][] = $file_data;
						}
					}
				}
			}

			// Check payload size
			$default_size = 1024; // KB
			$max_size_kb  = ! empty( $form_settings['webhookMaxSize'] ) ? $form_settings['webhookMaxSize'] : $default_size;
			$max_size     = $max_size_kb * 1024; // Convert to bytes

			if ( $content_type === 'json' ) {
				$payload_size = strlen( wp_json_encode( $payload ) );
			} else {
				$payload_size = strlen( http_build_query( $payload ) );
			}

			if ( $payload_size > $max_size ) {
				$error_message = esc_html__( 'Webhook payload too large.', 'bricks' );
				\Bricks\Helpers::maybe_log( 'Bricks webhook error: ' . $error_message . ' (Size: ' . size_format( $payload_size ) . ', Limit: ' . size_format( $max_size ) . ')' );
				$has_errors = true;
				continue;
			}

			// Prepare the request body based on content type
			$body = $content_type === 'json' ? wp_json_encode( $payload ) : $payload;

			// Send the webhook request
			$response = \Bricks\Helpers::remote_post(
				$webhook['url'],
				[
					'headers'            => $headers,
					'body'               => $body,
					'timeout'            => apply_filters( 'bricks/webhook/timeout', 15 ),
					'blocking'           => true,
					'sslverify'          => true,
					// Validate redirect targets like the initial URL, so a redirect can't reach internal hosts (#123pt1nyhyn)
					'reject_unsafe_urls' => true,
				]
			);

				// Check for errors
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
				\Bricks\Helpers::maybe_log( 'Bricks webhook error: ' . $error_message );
				$has_errors = true;
				continue;
			}

			// Check response code
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( $response_code < 200 || $response_code >= 300 ) {
				$error_message = esc_html__( 'Webhook request failed with status code', 'bricks' ) . ": $response_code";
				\Bricks\Helpers::maybe_log( 'Bricks webhook error: ' . $error_message );
				$has_errors = true;
				continue;
			}
		}

		// STEP: Set final result
		if ( $has_errors && empty( $form_settings['webhookErrorIgnore'] ) ) {
			$error_message = ! empty( $form_settings['webhookErrorMessage'] ) ? bricks_render_dynamic_data( $form_settings['webhookErrorMessage'], $form->get_post_id() ) : esc_html__( 'One or more webhook requests failed.', 'bricks' );

			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $error_message,
				]
			);
		} else {
			$form->set_result(
				[
					'action' => $this->name,
					'type'   => 'success',
				]
			);
		}
	}

	/**
	 * Render form field placeholders for JSON context with proper escaping
	 *
	 * Replaces {{field_id}} and {{field_id:property}} placeholders with
	 * JSON-escaped values to ensure valid JSON output.
	 *
	 * @param \Bricks\Integrations\Form\Init $form    Form instance.
	 * @param string                         $content Content with placeholders.
	 *
	 * @return string Content with replaced and JSON-escaped values.
	 *
	 * @since 2.2
	 */
	private function render_data_for_json( $form, $content ) {
		return $form->render_data( $content, true, [], 'json' );
	}
}

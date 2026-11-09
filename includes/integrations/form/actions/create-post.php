<?php
namespace Bricks\Integrations\Form\Actions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Create_Post extends Base {
	use Traits\Custom_Field_Handler;

	/**
	 * Create a new post
	 *
	 * @since 2.1
	 * @since 2.4.3 Preserves upload references once post data has been saved.
	 *
	 * @param \Bricks\Integrations\Form\Init $form Current form submission.
	 * @return void
	 */
	public function run( $form ) {
		$form_settings = $form->get_settings();
		$form_fields   = $form->get_fields();
		$post_type     = $form_settings['createPostType'] ?? '';

		// Return: Current user is not allowed to create posts of this type
		if ( empty( $form_settings['createPostDisableCapabilityCheck'] ) ) {
			$post_type_object = get_post_type_object( $post_type );

			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_posts ) ) {
				$form->set_result(
					[
						'action'  => $this->name,
						'type'    => 'error',
						'message' => esc_html__( 'You do not have the required capability to perform this action.', 'bricks' ),
					]
				);

				return;
			}
		}

		// Return: No form settings or fields
		if ( ! is_array( $form_settings ) || ! is_array( $form_fields ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => 'Invalid form settings or fields.',
				]
			);

			return;
		}

		// Return: Invalid post type
		if ( ! post_type_exists( $post_type ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => "Invalid post type: $post_type",
				]
			);

			return;
		}

		// Initialize post data with post_type
		$post_data = [ 'post_type' => $post_type ];

		// Conditional field assignments
		if ( ! empty( $form_settings['createPostTitle'] ) ) {
			$post_data['post_title'] = $form->get_field_value( $form_settings['createPostTitle'] );

			// Sanitize post_title
			$post_data['post_title'] = sanitize_text_field( $post_data['post_title'] );
		}

		if ( ! empty( $form_settings['createPostContent'] ) ) {
			$rendered_content = $form->get_field_value( $form_settings['createPostContent'] );

			// Sanitize post_content
			$post_data['post_content'] = wp_kses_post( $rendered_content );
		}

		if ( ! empty( $form_settings['createPostExcerpt'] ) ) {
			$post_data['post_excerpt'] = $form->get_field_value( $form_settings['createPostExcerpt'] );

			// Sanitize post_excerpt
			$post_data['post_excerpt'] = sanitize_text_field( $post_data['post_excerpt'] );
		}

		if ( ! empty( $form_settings['createPostStatus'] ) ) {
			$post_data['post_status'] = $form_settings['createPostStatus'];

			// Sanitize post_status
			$post_data['post_status'] = sanitize_text_field( $post_data['post_status'] );
		}

		// Default to the current logged-in user, otherwise default to the site's super admin
		$current_user_id = get_current_user_id();

		if ( $current_user_id ) {
			$post_data['post_author'] = $current_user_id;
		} else {
			// Default to the site's super admin
			$super_admins             = get_super_admins();
			$super_admin_id           = ! empty( $super_admins ) ? username_exists( $super_admins[0] ) : 1; // Assuming super admin exists or defaulting to user ID 1
			$post_data['post_author'] = $super_admin_id;
		}

		// Handle post meta fields
		$post_data['meta_input'] = $this->get_meta_input( $form, $form_settings, $form_fields );

		$taxonomy_validation = $this->validate_taxonomy_mappings( $form, $form_settings['createPostTaxonomies'] ?? [], empty( $form_settings['createPostDisableCapabilityCheck'] ) );

		if ( is_wp_error( $taxonomy_validation ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $taxonomy_validation->get_error_message(),
				]
			);

			return;
		}

		$post_id          = null;
		$created_new_post = false;

		// Handle "media" post type uploaiding (@since 2.2)
		if ( $post_type === 'attachment' ) {
			$existing_attachment_id = -1;
			$uploaded_files         = $form->get_uploaded_files();

			// Select frist existing attachment ID if any
			// User must select "save file" -> media library in the file upload field settings
			foreach ( $uploaded_files as $input_name => $files ) {
				foreach ( $files as $file ) {
					if ( isset( $file['attachment_id'] ) && $file['attachment_id'] ) {
						$existing_attachment_id = intval( $file['attachment_id'] );
						break 2; // Break out of both loops
					}
				}
			}

			// Insert new post OR use existing attachment
			if ( $existing_attachment_id !== -1 ) {
				// Use the existing attachment post and update its properties
				$post_id = $existing_attachment_id;

				// Update the attachment with mapped properties (title, content, excerpt, status)
				$post_data['ID'] = $post_id;
				wp_update_post( $post_data );
			}

		}

		if ( ! $post_id ) {
			// Create new post
			$post_id          = wp_insert_post( $post_data );
			$created_new_post = ! empty( $post_id ) && ! is_wp_error( $post_id );
		}

		// Return error
		if ( is_wp_error( $post_id ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $post_id->get_error_message(),
				]
			);

			return;
		}

		if ( $post_id ) {
			// Post insertion already saved meta_input, even if a later taxonomy or form action fails. (@since 2.4.3)
			$form->retain_uploaded_files( $post_data['meta_input'] ?? [] );
			if ( $post_type === 'attachment' ) {
				$form->retain_uploaded_files( $post_id );
			}
		}

		// Set/remove featured image
		if ( ! empty( $form_settings['createPostFeaturedImage'] ) ) {
			$featured_image_id = $form->get_field_value( $form_settings['createPostFeaturedImage'] );

			if ( $featured_image_id ) {
				// Sanitize and validate featured image ID
				$featured_image_id = intval( $featured_image_id );

				// Verify the attachment exists and is actually an image
				if ( $featured_image_id && wp_attachment_is_image( $featured_image_id ) ) {
					set_post_thumbnail( $post_id, $featured_image_id );
				}
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		// Set taxonomy terms
		// Support creating taxonomy terms from text and textarea fields (@since 2.4)
		$taxonomy_result = $this->assign_taxonomy_terms( $post_id, $form, $form_settings['createPostTaxonomies'] ?? [] );

		if ( is_wp_error( $taxonomy_result ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => $taxonomy_result->get_error_message(),
				]
			);

			return;
		}

		if ( $created_new_post ) {
			\Bricks\Helpers::maybe_save_acf_default_values( $post_id );
		}

		/**
		 * STEP: Handle post meta fields (including ACF & Meta Box fields)
		 */
		$post_meta = $this->get_meta_input( $form, $form_settings, $form_fields );

		if ( is_array( $post_meta ) ) {
			// Group ACF subfields by their parent group
			$acf_group_values = [];
			$processed_keys   = [];

			foreach ( $post_meta as $meta_key => $meta_value ) {
				// Check if this is an ACF field
				$acf_field_key = \Bricks\Integrations\Form\Init::get_acf_field_key_from_meta_key( $meta_key, $post_id );

				if ( $acf_field_key && function_exists( 'update_field' ) ) {
					// Get ACF field configuration
					$acf_field_config = function_exists( 'acf_get_field' ) ? acf_get_field( $acf_field_key ) : false;

					if ( $acf_field_config ) {
						// Handle field type-specific sanitization
						$meta_value = $this->sanitize_acf_field_value( $meta_value, $acf_field_config );

						// Check if this is a subfield (contains underscore and is nested)
						if ( strpos( $meta_key, '_' ) !== false ) {
							$parent_hierarchy = $this->get_acf_parent_hierarchy( $meta_key, $post_id );

							if ( $parent_hierarchy ) {
								// This is a nested field - store it for batch update
								$root_group_key = $parent_hierarchy['root_key'];
								$field_path     = $parent_hierarchy['path'];

								if ( ! isset( $acf_group_values[ $root_group_key ] ) ) {
									$acf_group_values[ $root_group_key ] = [];
								}

								// Build nested array structure
								$this->set_nested_value( $acf_group_values[ $root_group_key ], $field_path, $meta_value );
								$processed_keys[] = $meta_key;
								continue;
							}
						}

						// Top-level ACF field - update directly
						update_field( $acf_field_key, $meta_value, $post_id );
						$form->retain_uploaded_files( get_field( $acf_field_key, $post_id, false ) );
						$processed_keys[] = $meta_key;
						continue;
					}
				}

				// Update Meta Box field using rwmb_set_meta
				$meta_box_field_key = \Bricks\Integrations\Form\Init::get_meta_box_field_key_from_meta_key( $meta_key, $post_id );
				if ( $meta_box_field_key && function_exists( 'rwmb_set_meta' ) ) {
					// Get Meta Box field configuration to handle different field types properly (@since 2.2)
					$mb_field_config = false;
					if ( function_exists( 'rwmb_get_object_fields' ) ) {
						$mb_fields       = rwmb_get_object_fields( $post_id );
						$mb_field_config = $mb_fields[ $meta_box_field_key ] ?? false;
					}

					if ( $mb_field_config ) {
						$meta_value = $this->sanitize_meta_box_field_value( $meta_value, $mb_field_config );
					}

					rwmb_set_meta( $post_id, $meta_box_field_key, $meta_value );
					$form->retain_uploaded_files( rwmb_get_value( $meta_box_field_key, [], $post_id ) );
					$processed_keys[] = $meta_key;
					continue;
				}

				// Fallback to update_post_meta if not processed
				if ( ! in_array( $meta_key, $processed_keys, true ) ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
					$form->retain_uploaded_files( get_post_meta( $post_id, $meta_key, true ) );
				}
			}

			// Update ACF group fields with all subfield values at once
			foreach ( $acf_group_values as $group_key => $group_values ) {
				update_field( $group_key, $group_values, $post_id );
				$form->retain_uploaded_files( get_field( $group_key, $post_id, false ) );
			}

			// Trigger save_post once again after all meta updates to ensure all hooks are fired for query filters (#86c5gxrya;)
			if ( \Bricks\Helpers::enabled_query_filters() ) {
				do_action( 'save_post', $post_id, get_post( $post_id ), true );
			}
		}

		// Success handling
		$form->set_result(
			[
				'action'  => $this->name,
				'type'    => 'success',
				'post_id' => $post_id,
			]
		);
	}

	/**
	 * Prepares meta input data for creating a new post.
	 *
	 * @since 2.4.3 Resolves file mappings using their rendered custom input names.
	 *
	 * @param \Bricks\Integrations\Form\Init $form The form object.
	 * @param array                          $form_settings Form settings containing field mappings.
	 * @param array                          $form_fields Array of form field values.
	 * @return array|null Meta keys and values, or null when no mappings are configured.
	 */
	private function get_meta_input( $form, $form_settings, $form_fields ) {
		if ( empty( $form_settings['createPostMeta'] ) ) {
			return;
		}

		$meta_input     = [];
		$uploaded_files = $form->get_uploaded_files();

		foreach ( $form_settings['createPostMeta'] as $mapping ) {
			$meta_key = $mapping['metaKey'] ?? '';

			// Sanitize meta key to prevent injection
			$meta_key = sanitize_key( $meta_key );

			// Skip if meta key is empty after sanitization
			if ( empty( $meta_key ) ) {
				continue;
			}

			$field_id = $mapping['metaValue'] ?? '';

			$is_file_upload = false;
			$field_type     = '';
			$input_name     = "form-field-$field_id";
			if ( $field_id ) {
				// Loop through the form settings fields to find the type of the field
				foreach ( $form_settings['fields'] as $field_setting ) {
					if ( $field_setting['id'] === $field_id ) {
						$field_type = $field_setting['type']; // Store field type for later use
						if ( $field_type === 'file' ) {
							$is_file_upload = true;
							// Match the configured, rendered name used by validation and upload storage. (@since 2.4.3)
							$input_name = ! empty( $field_setting['name'] ) ? $field_setting['name'] : $input_name;
						}

						break;
					}
				}
			}

			if ( $is_file_upload ) {
				if ( isset( $uploaded_files[ $input_name ] ) ) {
					// Extract attachment IDs if files were uploaded to media library (@since 2.2)
					$files      = $uploaded_files[ $input_name ];
					$meta_value = [];

					foreach ( $files as $file ) {
						// Use attachment_id if available (file was uploaded early for create-post/update-post actions)
						if ( isset( $file['attachment_id'] ) ) {
							$meta_value[] = $file['attachment_id'];
						}
						// Otherwise use the file URL (for backward compatibility)
						elseif ( isset( $file['url'] ) ) {
							$meta_value[] = $file['url'];
						}
					}
				} else {
					$meta_value = []; // No file uploaded or field_id not found
				}
			} else {
				// Handle non-file upload fields
				$meta_value = $form->get_field_value( $field_id );

				// Radio field value submitted as array, always get the first value (@see options-wrapper HTML in form element) (#86c75jawt; @since 2.2)
				if ( $field_type === 'radio' && is_array( $meta_value ) ) {
					$meta_value = $meta_value[0];
				}
			}

			// Apply sanitization based on the determined method
			$sanitization_method = $mapping['sanitizationMethod'] ?? '';
			$meta_value          = $this->sanitize_meta_value( $meta_value, $sanitization_method );

			// Apply filtering
			$meta_value = apply_filters( 'bricks/form/create_post/meta_value', $meta_value, $meta_key, $form_settings, $form_fields );

			$meta_input[ $meta_key ] = $meta_value;
		}

		return $meta_input;
	}
}

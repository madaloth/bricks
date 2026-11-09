<?php
namespace Bricks\Integrations\Form\Actions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Update_Post extends Base {
	use Traits\Custom_Field_Handler;

	/**
	 * Update an existing post
	 *
	 * @since 2.1
	 * @since 2.4.3 Preserves upload references read back after metadata writes.
	 *
	 * @param \Bricks\Integrations\Form\Init $form Current form submission.
	 * @return void
	 */
	public function run( $form ) {
		$form_settings = $form->get_settings();
		$form_fields   = $form->get_fields();
		$post_id       = $form_settings['updatePostId'] ?? '';

		// No specified post ID: Without capability checks, only update the signed post the form was rendered for (#123pt1nyhyk)
		if ( ! $post_id && ! empty( $form_settings['updatePostDisableCapabilityCheck'] ) ) {
			// postId and loopId are client-supplied: unsigned, they would let anyone update any post
			$post_id = $form->get_verified_post_target();

			if ( ! $post_id ) {
				$form->set_result(
					[
						'action'  => $this->name,
						'type'    => 'error',
						'message' => esc_html__( 'Invalid form token. Please reload the page and try again.', 'bricks' ),
					]
				);

				return;
			}
		}

		// No specified post ID, try to get it from form field 'postId' first (capability check below protects the target)
		elseif ( ! $post_id ) {
			// Use form object method instead of direct $_POST access (already sanitized with absint in form_submit)
			$post_id = $form->get_post_id();

			// Get post ID from loopId if set (sanitize with absint)
			if ( ! empty( $form_fields['loopId'] ) ) {
				$post_id = absint( $form_fields['loopId'] );
			}
		}

		// Sanitize post_id (additional safety check)
		$post_id = absint( $post_id );

		// Return: Current user is not allowed to update this post
		if ( empty( $form_settings['updatePostDisableCapabilityCheck'] ) && ! current_user_can( 'edit_post', $post_id ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => esc_html__( 'You do not have the required capability to perform this action.', 'bricks' ),
				]
			);

			return;
		}

		// Return: No post with $post_id found
		if ( ! get_post( $post_id ) ) {
			$form->set_result(
				[
					'action'  => $this->name,
					'type'    => 'error',
					'message' => "No post found with ID: $post_id",
				]
			);

			return;
		}

		/**
		 * Taxonomies: edit_post doesn't imply assign_terms (e.g. custom taxonomies). Check before any write: clearing terms is a write too.
		 * Update post requires assign_terms even with "Disable capability checks" to protect existing post terms.
		 *
		 * #123pt1nyhyk
		 */
		if ( ! empty( $form_settings['updatePostTaxonomies'] ) && is_array( $form_settings['updatePostTaxonomies'] ) ) {
			foreach ( $form_settings['updatePostTaxonomies'] as $taxonomy ) {
				$taxonomy_object = get_taxonomy( $taxonomy['taxonomy'] ?? '' );

				// Unregistered taxonomy (e.g. plugin deactivated): wp_set_post_terms() won't write it
				if ( $taxonomy_object && ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
					$form->set_result(
						[
							'action'  => $this->name,
							'type'    => 'error',
							'message' => sprintf(
								/* translators: %s: Taxonomy label. */
								esc_html__( 'You do not have the required capability to assign terms in %s.', 'bricks' ),
								esc_html( $taxonomy_object->labels->name ?? $taxonomy['taxonomy'] )
							),
						]
					);

					return;
				}
			}
		}

		$post_data = [ 'ID' => $post_id ];

		// STEP: Update fields
		if ( isset( $form_settings['updatePostTitle'] ) ) {
			$post_data['post_title'] = $form->get_field_value( $form_settings['updatePostTitle'] );

			// Sanitize post_title
			$post_data['post_title'] = sanitize_text_field( $post_data['post_title'] );
		}

		if ( isset( $form_settings['updatePostContent'] ) ) {
			$rendered_content = $form->get_field_value( $form_settings['updatePostContent'] );

			// Sanitize post_content
			$post_data['post_content'] = wp_kses_post( $rendered_content );
		}

		if ( isset( $form_settings['updatePostExcerpt'] ) ) {
			$post_data['post_excerpt'] = $form->get_field_value( $form_settings['updatePostExcerpt'] );

			// Sanitize post_excerpt
			$post_data['post_excerpt'] = wp_kses_post( $post_data['post_excerpt'] );
		}

		if ( isset( $form_settings['updatePostStatus'] ) ) {
			$post_data['post_status'] = $form_settings['updatePostStatus'];

			// Sanitize post_status
			$post_data['post_status'] = sanitize_text_field( $post_data['post_status'] );
		}

		// Update post (if there's data to update)
		if ( count( $post_data ) > 1 ) {
			$updated_post_id = wp_update_post( $post_data, true );

			if ( is_wp_error( $updated_post_id ) ) {
				// Error handling
				$form->set_result(
					[
						'action'  => $this->name,
						'type'    => 'error',
						'message' => $updated_post_id->get_error_message(),
					]
				);

				return;
			}
		}

		// Set/remove featured image
		if ( ! empty( $form_settings['updatePostFeaturedImage'] ) ) {
			$featured_image_id = $form->get_field_value( $form_settings['updatePostFeaturedImage'] );

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
		if ( ! empty( $form_settings['updatePostTaxonomies'] ) ) {
			foreach ( $form_settings['updatePostTaxonomies'] as $taxonomy ) {
				$terms = $form->get_field_value( $taxonomy['fieldId'], $form_fields );
				if ( ! empty( $terms ) ) {
					if ( is_string( $terms ) ) {
						$terms = explode( ',', $terms );
						$terms = array_map( 'trim', $terms );
					}

					$terms = array_map( 'intval', $terms );

					wp_set_post_terms( $post_id, $terms, $taxonomy['taxonomy'] );
				} else {
					wp_set_post_terms( $post_id, [], $taxonomy['taxonomy'] ); // Clear terms if none provided
				}
			}
		}

		// Handle post meta fields update
		$this->update_meta_fields( $post_id, $form, $form_settings, $form_fields );

		// Trigger save_post once again after all meta updates to ensure all hooks are fired for query filters (#86c5gxrya;)
		if ( \Bricks\Helpers::enabled_query_filters() ) {
			do_action( 'save_post', $post_id, get_post( $post_id ), true );
		}

		// Success handling
		$form->set_result(
			[
				'action'  => $this->name,
				'type'    => 'success',
				'post_id' => $updated_post_id ?? $post_id,
			]
		);
	}

	/**
	 * Updates meta fields of a post based on form field mappings.
	 *
	 * Iterates over field mappings defined in the form settings and updates the meta fields
	 * of the specified post with the corresponding values from the form fields.
	 *
	 * @param int    $post_id The ID of the post to update.
	 * @param object $form The form object.
	 * @param array  $form_settings Form settings containing field mappings.
	 * @param array  $form_fields Array of form field values.
	 */
	private function update_meta_fields( $post_id, $form, $form_settings, $form_fields ) {
		if ( empty( $form_settings['updatePostMeta'] ) ) {
			return;
		}

		// Access uploaded files
		$uploaded_files = $form->get_uploaded_files();

		// Collect all meta values first
		$post_meta = [];

		foreach ( $form_settings['updatePostMeta'] ?? [] as $mapping ) {
			$meta_key = $mapping['metaKey'] ?? '';

			// Sanitize meta key to prevent injection
			$meta_key = sanitize_key( $meta_key );

			// Skip if meta key is empty after sanitization
			if ( empty( $meta_key ) ) {
				continue;
			}

			$field_id = $mapping['metaValue'] ?? '';

			$is_file_upload = false;
			$is_date_picker = false;
			$field_type     = '';
			$input_name     = "form-field-$field_id";
			$file_limit     = 0;
			$existing_files = [];
			$removed_files  = [];

			if ( $field_id ) {
				// Loop through the form settings fields to find the type of the field
				foreach ( $form_settings['fields'] as $field_setting ) {
					if ( $field_setting['id'] === $field_id ) {
						$field_type = $field_setting['type'];

						if ( $field_type === 'file' ) {
							$is_file_upload = true;
							$file_limit     = ! empty( $field_setting['fileUploadLimit'] ) ? intval( $field_setting['fileUploadLimit'] ) : 0;

							if ( ! empty( $field_setting['name'] ) ) {
								$input_name = $field_setting['name'];
							}
						}

						// Convert datepicker field from date_format to Ymd format
						if ( $field_type === 'datepicker' ) {
							$is_date_picker = true;
						}

						break;
					}
				}
			}

			if ( $is_file_upload ) {
				$existing_files = $form->get_verified_existing_file_ids( $field_id, $post_id, $form_fields );
				$removed_files  = ! empty( $form_fields['bricks_form_removed_files'][ $field_id ] ) ? array_filter( array_map( 'absint', (array) $form_fields['bricks_form_removed_files'][ $field_id ] ) ) : [];
			}

			if ( $is_file_upload && ! empty( $uploaded_files[ $input_name ] ) ) {
				// Extract attachment IDs if files were uploaded to media library (@since 2.2)
				$files      = $uploaded_files[ $input_name ];
				$meta_value = [];

				if ( $file_limit === 1 && ! empty( $existing_files ) && empty( $removed_files ) ) {
					continue;
				}

				if ( $file_limit !== 1 ) {
					$meta_value = $existing_files;
				}

				foreach ( $files as $file ) {
					// Use attachment_id if available (file was uploaded early for create-post/update-post actions) (@since 2.2)
					if ( isset( $file['attachment_id'] ) ) {
						$meta_value[] = $file['attachment_id'];
					}
					// Otherwise use the file URL (for backward compatibility @since 2.2)
					elseif ( isset( $file['url'] ) ) {
						$meta_value[] = $file['url'];
					}
				}
			}
			// No file uploaded
			elseif ( $is_file_upload ) {
				if ( empty( $removed_files ) ) {
					continue;
				}

				$meta_value = $existing_files;
			}

			// Handle date picker field (@since 2.1.3)
			elseif ( $is_date_picker ) {
				// Handle date picker field
				$meta_value = $form->get_field_value( $field_id );

				// Convert date to Ymd format for storage
				$date_format = 'Ymd';
				$timestamp   = strtotime( $meta_value );
				if ( $timestamp !== false ) {
					$meta_value = date( $date_format, $timestamp );
				}
			}

			// Handle non-file upload fields
			else {
				$meta_value = $form->get_field_value( $field_id );

				// Radio field value submitted as array, always get the first value (@since 2.2)
				if ( $field_type === 'radio' && is_array( $meta_value ) ) {
					$meta_value = $meta_value[0];
				}

				$sanitization_method = $mapping['sanitizationMethod'] ?? 'sanitize_text_field';
				$meta_value          = $this->sanitize_meta_value( $meta_value, $sanitization_method );
			}

			$meta_value = apply_filters( 'bricks/form/update_post/meta_value', $meta_value, $meta_key, $post_id, $form_fields );

			$post_meta[ $meta_key ] = $meta_value;
		}

		// Process collected meta values with ACF nested group support, similar to create-post action
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

					// TODO: checkbox_list saves every value as separate entry in the DB, so this doesn't work properly yet
					rwmb_set_meta( $post_id, $meta_box_field_key, $meta_value );
					$form->retain_uploaded_files( rwmb_get_value( $meta_box_field_key, [], $post_id ) );
					$processed_keys[] = $meta_key;
					continue;
				}

				// Fallback to update_post_meta if not processed
				if ( ! in_array( $meta_key, $processed_keys, true ) ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
					// Preserve only values read back after the write; false can mean unchanged or a failed update. (@since 2.4.3)
					$form->retain_uploaded_files( get_post_meta( $post_id, $meta_key, true ) );
				}
			}

			// Update ACF group fields with all subfield values at once
			foreach ( $acf_group_values as $group_key => $group_values ) {
				update_field( $group_key, $group_values, $post_id );
				$form->retain_uploaded_files( get_field( $group_key, $post_id, false ) );
			}
		}
	}

}

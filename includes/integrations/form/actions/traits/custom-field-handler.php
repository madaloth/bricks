<?php
namespace Bricks\Integrations\Form\Actions\Traits;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared trait for handling custom fields in form create/update post actions
 *
 * @since 2.2
 */
trait Custom_Field_Handler {
	/**
	 * Sanitize meta value based on the specified method
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $method The sanitization method.
	 * @return mixed The sanitized value.
	 */
	private function sanitize_meta_value( $value, $method ) {
		// If value is an array, sanitize each element
		if ( is_array( $value ) ) {
			return array_map(
				function( $single_value ) use ( $method ) {
					return $this->sanitize_meta_value( $single_value, $method );
				},
				$value
			);
		}

		switch ( $method ) {
			case 'intval':
				return intval( $value );
			case 'floatval':
				return floatval( $value );
			case 'sanitize_email':
				return sanitize_email( $value );
			case 'esc_url':
				return esc_url( $value );
			case 'wp_kses_post':
				return wp_kses_post( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Get ACF parent hierarchy for a nested field
	 *
	 * Handles unlimited nesting levels (group → group → field, etc.)
	 *
	 * @param string $meta_key Meta key (e.g., 'user_details_payment_card_number')
	 * @param int    $post_id  Post ID
	 *
	 * @return array|false Array with 'root_key' (top-level group field key) and 'path' (array of nested field names), or false if not nested
	 *
	 * @since 2.2
	 */
	private function get_acf_parent_hierarchy( $meta_key, $post_id ) {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return false;
		}

		$parts        = explode( '_', $meta_key );
		$hierarchy    = [];
		$current_path = '';

		// Build hierarchy from left to right
		for ( $i = 0; $i < count( $parts ); $i++ ) {
			$current_path  = $current_path ? $current_path . '_' . $parts[ $i ] : $parts[ $i ];
			$acf_field_key = \Bricks\Integrations\Form\Init::get_acf_field_key_from_meta_key( $current_path, $post_id );

			if ( $acf_field_key ) {
				$field = acf_get_field( $acf_field_key );

				if ( $field && $field['type'] === 'group' ) {
					$hierarchy[] = [
						'name' => $field['name'],
						'key'  => $acf_field_key,
						'path' => $current_path,
					];
				}
			}
		}

		// If we found at least one group in the hierarchy, this is a nested field
		if ( ! empty( $hierarchy ) ) {
			// The remaining parts after the last group are the field name parts
			$last_group      = end( $hierarchy );
			$last_group_path = $last_group['path'];
			$field_name_part = substr( $meta_key, strlen( $last_group_path ) + 1 );

			// Build the path array: ['payment', 'card_number'] for nested groups
			$path = [];
			foreach ( $hierarchy as $index => $group ) {
				// Skip the root group in the path
				if ( $index > 0 ) {
					$path[] = $group['name'];
				}
			}
			$path[] = $field_name_part;

			return [
				'root_key' => $hierarchy[0]['key'], // Top-level group field key
				'path'     => $path,                 // Array of nested field names
			];
		}

		return false;
	}

	/**
	 * Set a value in a nested array structure using a path
	 *
	 * @param array $array Reference to the array to modify
	 * @param array $path  Array of keys representing the path (e.g., ['payment', 'card_number'])
	 * @param mixed $value The value to set
	 *
	 * @since 2.2
	 */
	private function set_nested_value( &$array, $path, $value ) {
		$current = &$array;

		foreach ( $path as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				$current[ $key ] = [];
			}
			$current = &$current[ $key ];
		}

		$current = $value;
	}

	/**
	 * Sanitize ACF field value based on field type
	 *
	 * @param mixed $value       Field value
	 * @param array $field_config ACF field configuration
	 *
	 * @return mixed Sanitized value
	 *
	 * @since 2.2
	 */
	private function sanitize_acf_field_value( $value, $field_config ) {
		switch ( $field_config['type'] ) {
			case 'image':
				// For single image fields, convert array to integer ID
				if ( is_array( $value ) && ! empty( $value ) ) {
					return intval( $value[0] );
				} elseif ( is_string( $value ) ) {
					// Split by spaces and take the first valid ID
					$ids = array_filter( array_map( 'intval', explode( ' ', $value ) ) );
					return ! empty( $ids ) ? $ids[0] : 0;
				}
				break;

			case 'gallery':
				// For gallery fields, ensure it's an array of integers
				if ( is_array( $value ) ) {
					return array_map( 'intval', $value );
				} elseif ( is_string( $value ) && strpos( $value, ',' ) !== false ) {
					return array_map( 'intval', explode( ',', $value ) );
				}
				break;

			case 'file':
				// For single file fields, convert array to integer ID
				if ( is_array( $value ) && ! empty( $value ) ) {
					return intval( $value[0] );
				} elseif ( is_string( $value ) ) {
					return intval( $value );
				}
				break;
		}

		return $value;
	}

	/**
	 * Sanitize Meta Box field value based on field type
	 *
	 * @param mixed $value        Field value
	 * @param array $field_config Meta Box field configuration
	 *
	 * @return mixed Sanitized value
	 *
	 * @since 2.2
	 */
	private function sanitize_meta_box_field_value( $value, $field_config ) {
		$field_type = $field_config['type'] ?? '';

		switch ( $field_type ) {
			case 'image_advanced':
			case 'file_advanced':
				// For gallery/file fields, ensure it's an array of integers
				if ( is_array( $value ) ) {
					return array_map( 'intval', $value );
				} elseif ( is_string( $value ) && strpos( $value, ',' ) !== false ) {
					return array_map( 'intval', explode( ',', $value ) );
				}
				break;

			case 'image':
			case 'file':
				if ( ( $field_config['max_file_uploads'] ?? 0 ) !== 1 ) {
					return is_array( $value ) ? array_map( 'intval', $value ) : intval( $value );
				}

				if ( is_array( $value ) && ! empty( $value ) ) {
					return intval( $value[0] );
				} elseif ( is_string( $value ) ) {
					return intval( $value );
				}
				break;
		}

		return $value;
	}

	/**
	 * Validate taxonomy mappings before creating/updating the post.
	 *
	 * Ensures taxonomy slugs are valid and users can create any missing terms
	 * that would be required by the current form submission.
	 *
	 * @param object $form              Form object.
	 * @param array  $taxonomy_mappings Taxonomy mapping configuration.
	 * @param bool   $check_assign_capability Whether to require permission to assign existing terms. Missing terms always require edit_terms.
	 *
	 * @return true|\WP_Error
	 *
	 * @since 2.4
	 */
	protected function validate_taxonomy_mappings( $form, $taxonomy_mappings, $check_assign_capability = true ) {
		if ( empty( $taxonomy_mappings ) || ! is_array( $taxonomy_mappings ) ) {
			return true;
		}

		foreach ( $taxonomy_mappings as $mapping ) {
			$taxonomy = $mapping['taxonomy'] ?? '';

			if ( empty( $taxonomy ) ) {
				continue;
			}

			$field_id   = $mapping['fieldId'] ?? '';
			$field_mode = $this->get_taxonomy_mapping_field_mode( $form, $field_id );
			$raw_terms  = $form->get_field_value( $field_id );
			$result     = $this->validate_taxonomy_terms( $raw_terms, $taxonomy, $field_mode, $check_assign_capability );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Normalize raw form input into taxonomy term tokens.
	 *
	 * @param mixed $raw_terms Raw form field value.
	 *
	 * @return array
	 *
	 * @since 2.4
	 */
	private function normalize_taxonomy_term_tokens( $raw_terms ) {
		$tokens = [];

		if ( is_array( $raw_terms ) ) {
			array_walk_recursive(
				$raw_terms,
				function( $term ) use ( &$tokens ) {
					if ( is_scalar( $term ) ) {
						$tokens[] = trim( is_string( $term ) ? wp_unslash( $term ) : (string) $term );
					}
				}
			);
		} elseif ( is_scalar( $raw_terms ) ) {
			$str = trim( is_string( $raw_terms ) ? wp_unslash( $raw_terms ) : (string) $raw_terms );

			if ( $str !== '' ) {
				$tokens = preg_split( '/[\r\n,]+/', $str );
				$tokens = array_map( 'trim', $tokens );
			}
		}

		return array_filter(
			array_unique( $tokens ),
			static function( $t ) {
				return $t !== '';
			}
		);
	}

	/**
	 * Determine how taxonomy terms should be resolved for a mapped form field.
	 *
	 * Free-text fields should resolve by slug/name only, while option-based fields
	 * may continue to resolve numeric values as term IDs.
	 *
	 * @param object $form     Form object.
	 * @param string $field_id Mapped form field ID.
	 *
	 * @return string
	 *
	 * @since 2.4
	 */
	private function get_taxonomy_mapping_field_mode( $form, $field_id ) {
		$form_settings = $form->get_settings();
		$fields        = $form_settings['fields'] ?? [];

		if ( empty( $field_id ) || ! is_array( $fields ) ) {
			return 'option';
		}

		foreach ( $fields as $field ) {
			if ( ( $field['id'] ?? '' ) !== $field_id ) {
				continue;
			}

			$field_type = $field['type'] ?? '';

			if ( in_array( $field_type, [ 'text', 'textarea' ], true ) ) {
				return 'free_text';
			}

			if ( in_array( $field_type, [ 'select', 'checkbox', 'radio' ], true ) ) {
				return 'option';
			}

			break;
		}

		return 'option';
	}

	/**
	 * Validate taxonomy terms and required capabilities without creating terms.
	 *
	 * @param mixed  $raw_terms   Raw form field value (string, array, or scalar).
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $field_mode  Taxonomy mapping field mode.
	 * @param bool   $check_assign_capability Whether to require permission to assign existing terms.
	 *
	 * @return true|\WP_Error
	 *
	 * @since 2.4
	 */
	private function validate_taxonomy_terms( $raw_terms, $taxonomy, $field_mode = 'option', $check_assign_capability = true ) {
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: Taxonomy slug. */
					esc_html__( 'Invalid taxonomy: %s', 'bricks' ),
					$taxonomy
				)
			);
		}

		$tokens = $this->normalize_taxonomy_term_tokens( $raw_terms );

		if ( empty( $tokens ) ) {
			return true;
		}

		if ( $check_assign_capability && ! empty( $taxonomy_object->cap->assign_terms ) && ! current_user_can( $taxonomy_object->cap->assign_terms ) ) {
			return new \WP_Error(
				'bricks_assign_taxonomy_term_forbidden',
				sprintf(
					/* translators: %s: Taxonomy label. */
					esc_html__( 'You do not have the required capability to assign terms in %s.', 'bricks' ),
					$taxonomy_object->labels->name ?? $taxonomy
				)
			);
		}

		foreach ( $tokens as $token ) {
			$term_info = null;

			if ( $field_mode !== 'free_text' && ctype_digit( $token ) ) {
				$term_info = term_exists( (int) $token, $taxonomy );
			}

			if ( ! $term_info ) {
				$term_info = term_exists( $token, $taxonomy );
			}

			if ( $term_info ) {
				continue;
			}

			if ( empty( $taxonomy_object->cap->edit_terms ) || ! current_user_can( $taxonomy_object->cap->edit_terms ) ) {
				return new \WP_Error(
					'bricks_create_taxonomy_term_forbidden',
					sprintf(
						/* translators: %s: Taxonomy label. */
						esc_html__( 'You do not have the required capability to create terms in %s.', 'bricks' ),
						$taxonomy_object->labels->name ?? $taxonomy
					)
				);
			}
		}

		return true;
	}

	/**
	 * Assign taxonomy terms to a post from mapped form field values.
	 *
	 * @param int    $post_id            Post ID.
	 * @param object $form               Form object.
	 * @param array  $taxonomy_mappings  Taxonomy mapping configuration.
	 *
	 * @return true|\WP_Error
	 *
	 * @since 2.4
	 */
	protected function assign_taxonomy_terms( $post_id, $form, $taxonomy_mappings ) {
		if ( empty( $taxonomy_mappings ) || ! is_array( $taxonomy_mappings ) ) {
			return true;
		}

		$terms_by_taxonomy = [];

		// Loop through each taxonomy mapping (e.g., { taxonomy: 'category', fieldId: 'field_1' })
		foreach ( $taxonomy_mappings as $mapping ) {
			$taxonomy = $mapping['taxonomy'] ?? '';
			if ( empty( $taxonomy ) ) {
				continue;
			}

			// Initialize array for this taxonomy if not already set (to merge multiple mapped fields for same taxonomy)
			if ( ! isset( $terms_by_taxonomy[ $taxonomy ] ) ) {
				$terms_by_taxonomy[ $taxonomy ] = [];
			}

			$field_id   = $mapping['fieldId'] ?? '';
			$field_mode = $this->get_taxonomy_mapping_field_mode( $form, $field_id );

			// Get raw value from form field (e.g., 'news, featured' or array ['news', 'featured'])
			$raw_terms = $form->get_field_value( $field_id );
			// Parse, resolve, and create terms as needed; returns array of term IDs
			$term_ids = $this->parse_and_resolve_taxonomy_terms( $raw_terms, $taxonomy, $field_mode );

			if ( is_wp_error( $term_ids ) ) {
				return $term_ids;
			}

			// Merge term IDs for this taxonomy (handles multiple fields mapped to the same taxonomy)
			if ( ! empty( $term_ids ) ) {
				$terms_by_taxonomy[ $taxonomy ] = array_merge( $terms_by_taxonomy[ $taxonomy ], $term_ids );
			}
		}

		// Assign terms to the post for each taxonomy, ensuring unique term IDs
		foreach ( $terms_by_taxonomy as $taxonomy => $term_ids ) {
			// Apply terms once per taxonomy so multiple mapped fields are merged instead of overwriting each other.
			$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

			// Assign resolved term IDs to post, or clear the taxonomy if all mapped fields were empty.
			$result = wp_set_post_terms( $post_id, $term_ids, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Parse form input and resolve taxonomy terms, creating missing terms if user has capability.
	 *
	 * @param mixed  $raw_terms  Raw form field value (string, array, or scalar).
	 * @param string $taxonomy   Taxonomy slug.
	 * @param string $field_mode Taxonomy mapping field mode.
	 *
	 * @return array|\WP_Error Array of resolved term IDs, or \WP_Error on failure.
	 *
	 * @since 2.4
	 */
	private function parse_and_resolve_taxonomy_terms( $raw_terms, $taxonomy, $field_mode = 'option' ) {
		$tokens = $this->normalize_taxonomy_term_tokens( $raw_terms );

		if ( empty( $tokens ) ) {
			return [];
		}

		$term_ids        = [];
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! $taxonomy_object ) {
			return new \WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: %s: Taxonomy slug. */
					esc_html__( 'Invalid taxonomy: %s', 'bricks' ),
					$taxonomy
				)
			);
		}

		// Process each token to resolve existing terms or create a missing term
		foreach ( $tokens as $token ) {
			$term_info = null;

			// Only option-based fields should resolve numeric values as term IDs.
			if ( $field_mode !== 'free_text' && ctype_digit( $token ) ) {
				$term_info = term_exists( (int) $token, $taxonomy );
			}

			// term_exists() checks slug first, then name for string lookups.
			if ( ! $term_info ) {
				$term_info = term_exists( $token, $taxonomy );
			}

			// If found, use existing term ID
			if ( $term_info ) {
				$term_ids[] = (int) $term_info['term_id'];
				continue;
			}

			// Term not found; check if user can create
			if ( empty( $taxonomy_object->cap->edit_terms ) || ! current_user_can( $taxonomy_object->cap->edit_terms ) ) {
				return new \WP_Error(
					'bricks_create_taxonomy_term_forbidden',
					sprintf(
						/* translators: %s: Taxonomy label. */
						esc_html__( 'You do not have the required capability to create terms in %s.', 'bricks' ),
						$taxonomy_object->labels->name ?? $taxonomy
					)
				);
			}

			// Create missing term (e.g., token: 'urgent-news' → new term with name 'urgent-news')
			$new_term = wp_insert_term( $token, $taxonomy );
			if ( is_wp_error( $new_term ) ) {

				// If another request creates the same term between these calls, we may get a 'term_exists' error. In that case, we can safely ignore the error and retrieve the existing term ID (@since 2.4)
				if ( 'term_exists' === $new_term->get_error_code() ) {
					$existing_term_id = (int) $new_term->get_error_data();

					if ( $existing_term_id ) {
						$term_ids[] = $existing_term_id;
						continue;
					}

					$term_info = term_exists( $token, $taxonomy );
					if ( $term_info ) {
						$term_ids[] = (int) $term_info['term_id'];
						continue;
					}
				}

				return $new_term;
			}

			$term_ids[] = (int) $new_term['term_id'];
		}

		// Return unique term IDs (e.g., [5, 12, 8])
		return array_unique( $term_ids );
	}

	/**
	 * Process post meta with ACF nested group support
	 *
	 * Handles both ACF and Meta Box fields, grouping nested ACF subfields
	 * for batch updates to maintain proper meta key structure.
	 *
	 * @param array $post_meta Array of meta key => value pairs
	 * @param int   $post_id   Post ID
	 *
	 * @since 2.2
	 */
	protected function process_acf_meta_fields( $post_meta, $post_id ) {
		if ( ! is_array( $post_meta ) ) {
			return;
		}

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
					$processed_keys[] = $meta_key;
					continue;
				}
			}

			// Update Meta Box field using rwmb_set_meta
			$meta_box_field_key = \Bricks\Integrations\Form\Init::get_meta_box_field_key_from_meta_key( $meta_key, $post_id );
			if ( $meta_box_field_key && function_exists( 'rwmb_set_meta' ) ) {
				// Get Meta Box field configuration to handle different field types properly
				$mb_field_config = false;
				if ( function_exists( 'rwmb_get_object_fields' ) ) {
					$mb_fields       = rwmb_get_object_fields( $post_id );
					$mb_field_config = $mb_fields[ $meta_box_field_key ] ?? false;
				}

				if ( $mb_field_config ) {
					$meta_value = $this->sanitize_meta_box_field_value( $meta_value, $mb_field_config );
				}

				rwmb_set_meta( $post_id, $meta_box_field_key, $meta_value );
				$processed_keys[] = $meta_key;
				continue;
			}

			// Fallback to update_post_meta if not processed
			if ( ! in_array( $meta_key, $processed_keys, true ) ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

		// Update ACF group fields with all subfield values at once
		foreach ( $acf_group_values as $group_key => $group_values ) {
			update_field( $group_key, $group_values, $post_id );
		}
	}
}

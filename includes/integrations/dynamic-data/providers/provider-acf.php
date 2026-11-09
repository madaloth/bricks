<?php
namespace Bricks\Integrations\Dynamic_Data\Providers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Provider_Acf extends Base {
	/**
	 * This method will be called before the provider is registered
	 * Not relying on the load_me() method to avoid unnecessary checks.
	 *
	 * @since 1.11
	 */
	public static function required_hooks() {
		if ( ! class_exists( 'ACF' ) ) {
			return;
		}

		add_action( 'save_post_acf-field-group', [ __CLASS__, 'flush_cache' ] );
	}

	public static function load_me() {
		/**
		 * Return: Don't register this provider on ACF AJAX requests
		 *
		 * Otherwise irrelevant language field groups show in WP admin with Polylang Pro active.
		 *
		 * @see #86bzctmfr
		 * @since 1.10
		 */
		if ( bricks_is_ajax_call() && isset( $_POST['action'] ) && strpos( $_POST['action'], 'acf/ajax/' ) === 0 ) {
			return false;
		}

		return class_exists( 'ACF' );
	}

	public function register_tags() {
		$fields = self::get_fields();

		foreach ( $fields as $field ) {
			$this->register_tag( $field );
		}

		// Register custom function for ACF (@since 1.6.2)
		$this->register_dynamic_function_tag();
	}

	/**
	 * Collect nested parent group field name and locations
	 * To be used in get_acf_group_field_value()
	 *
	 * @since 1.9.1
	 *
	 * @param array $data
	 * @param array $parent_field
	 *
	 * @return array
	 */
	public function get_nested_parent_group_field_data( $data = [], $parent_field = [] ) {
		if ( ! isset( $data['name'] ) || ! isset( $parent_field['key'] ) ) {
			return $data;
		}
		$field_key = $parent_field['key'];

		// Concatenate the field name with the parent field name
		$data['name'] = $parent_field['name'] . '_' . $data['name'];

		// Record the parent field locations
		if ( ! empty( $parent_field['_bricks_locations'] ) ) {
			$data['_bricks_locations'] = array_merge( $data['_bricks_locations'], $parent_field['_bricks_locations'] );
		}

		// Record the parent group names
		if ( ! empty( $parent_field['name'] ) ) {
			$data['parent_group_names'][] = $parent_field['name'];
		}

		$found_tag = false;

		// Find from the tags that subfields has key same as $field_key
		foreach ( $this->tags as $tag ) {
			// Outer loop tags are mirrored here for discovery only.
			// Using them as group ancestors renames legacy persisted tags.
			if ( ! empty( $tag['is_loop_outer'] ) ) {
				continue;
			}

			$sub_fields = $tag['field']['sub_fields'] ?? false;

			if ( ! $sub_fields ) {
				continue;
			}

			foreach ( $sub_fields as $sub_field ) {
				if ( $sub_field['key'] === $field_key ) {
					$found_tag = $tag;
					break 2; // Break out of both loops since the tag is found
				}
			}
		}

		if ( $found_tag ) {
			// $found_tag['field'] is the nested parent group field
			$data = $this->get_nested_parent_group_field_data( $data, $found_tag['field'] ); // Recursive
		}

		return $data;
	}

	public function register_tag( $field, $parent_field = [], $parent_tag = [] ) {
		$contexts = self::get_fields_by_context();

		$type = $field['type'];

		/**
		 * ACF only expands seamless clones through acf_get_fields(). Group-display
		 * clones remain clone fields, so register their resolved sub-fields here.
		 * Their effective names already contain the configured clone prefix.
		 *
		 * @since 2.3.13 (#86c50h1ma)
		 */
		if ( $type === 'clone' ) {
			if ( ( $field['display'] ?? '' ) !== 'group' || empty( $field['sub_fields'] ) || ! is_array( $field['sub_fields'] ) ) {
				return;
			}

			foreach ( $field['sub_fields'] as $sub_field ) {
				if ( ! empty( $field['_bricks_locations'] ) ) {
					$sub_field['_bricks_locations'] = array_values(
						array_unique(
							array_merge( $sub_field['_bricks_locations'] ?? [], $field['_bricks_locations'] )
						)
					);
				}

				if ( ! empty( $field['label'] ) ) {
					$sub_field['label'] .= ' (' . $field['label'] . ')';
				}

				$this->register_tag( $sub_field, $parent_field, $parent_tag );
			}

			return;
		}

		if ( ! isset( $contexts[ $type ] ) ) {
			return;
		}

		foreach ( $contexts[ $type ] as $context ) {
			// Add parent field name to the field name if needed
			$name = ! empty( $parent_field['name'] ) ? 'acf_' . $parent_field['name'] . '_' . $field['name'] : 'acf_' . $field['name'];

			// Add the context to the field name (legacy)
			if ( $context !== self::CONTEXT_TEXT && $context !== self::CONTEXT_LOOP && empty( $parent_field ) ) {
				$name .= '_' . $context;
			}

			$label = ! empty( $parent_field['label'] ) ? $field['label'] . ' (' . $parent_field['label'] . ')' : $field['label'];

			if ( $context === self::CONTEXT_LOOP ) {
				$label = 'ACF ' . ucfirst( $type ) . ': ' . $label;
			}

			$tag = [
				'group'    => 'ACF',
				'field'    => $field,
				'provider' => $this->name,
			];

			if ( ! empty( $parent_field ) ) {
				// Add the parent field attributes to the child tag so we could retrieve the value of group sub-fields
				$tag['parent'] = [
					'key'  => $parent_field['key'],
					'name' => $parent_field['name'],
					'type' => $parent_field['type'],
				];

				// Handle nested group field (@since 1.9.1)
				if ( $parent_field['type'] === 'group' ) {
					// Group by parent field, better visual in DD dropdown
					$tag['group'] = 'ACF: ' . $parent_field['label'];

					$data = [
						'name'               => $field['name'],
						'_bricks_locations'  => [],
						'parent_group_names' => [],
					];

					$nested_group_data = $this->get_nested_parent_group_field_data( $data, $parent_field );

					$nested_name = 'acf_' . $nested_group_data['name'];

					if ( $nested_name !== $name ) {
						// This is a nested group field
						$tag['nested_group'] = true;
						// Use the nested name
						$name = $nested_name;
						// Save the origin grand grand parent field
						$tag['_bricks_locations'] = $nested_group_data['_bricks_locations'];
						// Save the parent group names
						$tag['parent_group_names'] = $nested_group_data['parent_group_names'];
						// Group by nested parent field, better visual in DD dropdown
						$tag['group'] = 'ACF: ' . implode( ' > ', array_reverse( $nested_group_data['parent_group_names'] ) );
					}
				}

				if ( ! empty( $parent_field['_bricks_locations'] ) ) {
					$tag['parent']['_bricks_locations'] = $parent_field['_bricks_locations'];
				}

				// Include the parent layout name for flexible content sub-fields (@since 1.6.2)
				if ( $parent_field['type'] === 'flexible_content' ) {
					$parent_layout = $field['parent_layout'];

					// Get the parent layout data to retrieve the layout name and label, in case the layout key is different from the layout name (#86c9706hb; @since 2.3.2)
					$parent_layout_data = $this->get_flexible_content_parent_layout_data( $parent_field, $parent_layout );

					// Use the layout name if found, otherwise fallback to the layout key
					$parent_layout_name = $parent_layout_data && isset( $parent_layout_data['name'] ) ? $parent_layout_data['name'] : $parent_layout;

					// Use the layout label if found, otherwise fallback to the layout name
					$parent_layout_label = $parent_layout_data && isset( $parent_layout_data['label'] ) ? $parent_layout_data['label'] : $parent_layout_name;

					// Change the name to include the parent layout name, ensure it's unique
					// e.g. acf_flexible_content_layout_name_sub_field_name
					$name = 'acf_' . $parent_field['name'] . '_' . $parent_layout_name . '_' . $field['name'];

					// Change the label to include the parent layout name
					$label = $field['label'] . ' (' . $parent_field['label'] . ') (' . $parent_layout_label . ')';

					// Group by parent field + parent layout name, better visual in DD dropdown (@since 2.0)
					$tag['group'] = 'ACF: ' . $parent_field['label'] . ' (' . $parent_layout_label . ')';
				}
			}

			// Set the tag name and label
			$tag['name']  = '{' . $name . '}';
			$tag['label'] = $label;

			/**
			 * Set 'duplicate' if this tag name has been registered before
			 *
			 * Meaning there is a field with the same 'name' in different field group.
			 *
			 * @since 1.8
			 */
			if ( isset( $this->tags[ $name ] ) ) {
				$tag['duplicate'] = true;
			}

			// Register fields for the Loop context ( e.g. Repeater, Relationship, Flexible content..)
			if ( $context === self::CONTEXT_LOOP || $type === 'group' ) {

				// Register the group field tag as deprecated to be used in case groups are nested inside a repeater (@since 1.5.1)
				if ( $type === 'group' ) {
					$this->tags[ $name ]               = $tag;
					$this->tags[ $name ]['deprecated'] = 1;
				} else {
					$this->loop_tags[ $name ] = $tag;

					/**
					 * Also expose the outer repeater / flexible_content / relationship / post_object / gallery field
					 * as a regular tag so enumeration (list-dynamic-data-tags, builder picker) surfaces it.
					 * Marked is_loop_outer so get_tag_value can return a sensible default (row count for
					 * repeater/FC, comma-separated IDs for relationship/post_object/gallery) instead of
					 * routing the raw array through the per-field-type formatter.
					 * Only registered when the slot is free to avoid clobbering sub-field tags
					 * that happen to share the parent name in legacy data.
					 */
					if ( ! isset( $this->tags[ $name ] ) && empty( $parent_field ) ) {
						$outer_tag                  = $tag;
						$outer_tag['is_loop_outer'] = true;
						$this->tags[ $name ]        = $outer_tag;
					}
				}

				// Check for sub-fields (including group field sub-fields)
				if ( ! empty( $field['sub_fields'] ) ) {
					foreach ( $field['sub_fields'] as $sub_field ) {
						$this->register_tag( $sub_field, $field, $tag ); // Recursive
					}
				}

				// Check for flexible content layouts, register their sub-fields (@since 1.6.2)
				if ( $type === 'flexible_content' && ! empty( $field['layouts'] ) ) {
					foreach ( $field['layouts'] as $layout ) {
						if ( ! empty( $layout['sub_fields'] ) ) {
							foreach ( $layout['sub_fields'] as $sub_field ) {
								$this->register_tag( $sub_field, $field, $tag ); // Recursive
							}
						}
					}
				}
			}

			// Only register fields from other contexts, other than CONTEXT_TEXT, if they are not sub-fields (legacy purposes)
			elseif ( $context === self::CONTEXT_TEXT || empty( $parent_field ) ) {
				$this->tags[ $name ] = $tag;

				if ( $context !== self::CONTEXT_TEXT ) {
					$this->tags[ $name ]['deprecated'] = 1;
				}

			}
		}
	}

	/**
	 * Get the parent layout data of a flexible content sub-field
	 * - First try to get the layout data by the layout key (most cases, the layout key is the same as the layout name)
	 * - Otherwise, loop through the layouts to find the layout with the matching layout key (in certain scenario, layout key to be changed from layout_1234567890 to array index 0, 1, 2..)
	 * #86c9706hb
	 *
	 * @since 2.3.2
	 */
	private function get_flexible_content_parent_layout_data( $field, $key ) {
		if ( $key === '' || empty( $field['layouts'] ) || ! is_array( $field['layouts'] ) ) {
			return false;
		}

		$layouts = $field['layouts'];

		// Fast path: layouts already keyed by layout key.
		if ( isset( $layouts[ $key ] ) ) {
			return $layouts[ $key ];
		}

		// Fallback: layouts stored as indexed array.
		foreach ( $layouts as $layout ) {
			if ( isset( $layout['key'] ) && $layout['key'] === $key ) {
				return $layout;
			}
		}

		return false;
	}

	/**
	 * Register dynamic functions tags
	 *
	 * @since 1.6.2
	 */
	public function register_dynamic_function_tag() {
		$dynamic_functions = [
			'acf_get_row_layout' => [
				'name'                   => '{acf_get_row_layout}',
				'label'                  => esc_html__( 'ACF Get Row Layout', 'bricks' ),
				'group'                  => 'ACF',
				'provider'               => $this->name,
				'queryFiltersExcludeTag' => true, // Exclude from Query Filters integration dropdown (@since 2.0.2)
			],
		];

		$this->tags = array_merge( $this->tags, $dynamic_functions );
	}

	public static function get_fields() {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'get_field' ) ) {
			return [];
		}

		$last_changed = wp_cache_get_last_changed( 'bricks_acf-field-group' );
		$cache_key    = md5( 'acf_fields' . $last_changed );
		$acf_fields   = wp_cache_get( $cache_key, 'bricks' );

		if ( false === $acf_fields ) {
			// NOTE: Undocumented. This allows the user to remove some field groups from the picker
			$groups = apply_filters( 'bricks/acf/filter_field_groups', acf_get_field_groups() );

			if ( empty( $groups ) || ! is_array( $groups ) ) {
				return [];
			}

			$acf_fields = [];

			foreach ( $groups as $group ) {
				// Group fields
				$fields = acf_get_fields( $group );

				if ( ! is_array( $fields ) ) {
					continue;
				}

				$locations = self::get_fields_locations( $group );

				if ( ! empty( $locations ) ) {
					foreach ( $fields as $field ) {
						$field['_bricks_locations'] = $locations; // Save the field with a special bricks attribute
						$acf_fields[]               = $field;
					}

				} else {
					$acf_fields = array_merge( $acf_fields, $fields );
				}

			}

			// @since 1.7 - $acf_fields might include closures which cause fatal errors when using wp_cache_set (ACF Extended plugin)
			$acf_fields = wp_json_encode( $acf_fields );
			$acf_fields = json_decode( $acf_fields, true );

			wp_cache_set( $cache_key, $acf_fields, 'bricks', DAY_IN_SECONDS );
		}

		return $acf_fields;
	}

	public static function get_fields_locations( $group ) {
		if ( ! isset( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return [];
		}

		$locations = [];

		foreach ( $group['location'] as $conditions ) {
			foreach ( $conditions as $condition ) {
				if ( ! isset( $condition['param'] ) ) {
					continue;
				}

				if ( $condition['param'] === 'options_page' ) {
					$locations['option'] = 1;
				}

				if ( in_array( $condition['param'], [ 'user_role', 'current_user', 'current_user_role', 'user_form' ] ) ) {
					$locations['user'] = 1;
				}

				if ( $condition['param'] === 'taxonomy' ) {
					$locations['term'] = 1;
				}

				// Without this, if multiple location rules set and contains a user related condition, get_object_id() wrongly return user meta fields instead of post (#32bp1n8)
				if ( $condition['param'] === 'post_type' ) {
					$locations['post'] = 1;
				}
			}
		}

		return array_keys( $locations );
	}

	/**
	 * Get tag value main function
	 *
	 * @param string  $tag The tag name (e.g. acf_my_field).
	 * @param WP_Post $post The post object.
	 * @param array   $args The dynamic data tag arguments.
	 * @param string  $context E.g. text, link, image.
	 *
	 * @return mixed The tag value.
	 */
	public function get_tag_value( $tag, $post, $args, $context ) {
		$post_id = isset( $post->ID ) ? $post->ID : '';

		// STEP: Check for filter args
		$filters = $this->get_filters_from_args( $args );

		// Default value
		$value = '';

		// For ACF fields
		if ( isset( $this->tags[ $tag ]['field'] ) ) {
			$field = $this->tags[ $tag ]['field'];

			/**
			 * Outer loop-field tag (repeater / flexible_content / relationship / post_object / gallery).
			 * Returns a sensible default (row count for repeater/FC; comma-separated IDs for the rest)
			 * so the tag has a useful text rendering. The full loop is still driven by query elements.
			 */
			if ( ! empty( $this->tags[ $tag ]['is_loop_outer'] ) && function_exists( 'get_field' ) ) {
				$post_id = $this->get_order_field_id( $field, $post_id );
				$raw     = $this->filter_post_references( get_field( $field['name'], $post_id, false ), $field );

				if ( in_array( $field['type'], [ 'repeater', 'flexible_content' ], true ) ) {
					return is_array( $raw ) ? (string) count( $raw ) : '0';
				}

				if ( is_array( $raw ) ) {
					return implode( ', ', array_map( 'strval', $raw ) );
				}

				return is_scalar( $raw ) ? (string) $raw : '';
			}

			/**
			 * Retrieve field settings from ACF:
			 *
			 * If field with the same 'name' has been registered already in another ACF field group.
			 *
			 * @since 1.8
			 */
			if ( isset( $this->tags[ $tag ]['duplicate'] ) ) {
				$actual_field = get_field_object( $field['name'], $post_id );

				if ( $actual_field === false && isset( $this->tags[ $tag ]['parent'] ) && $this->tags[ $tag ]['parent']['type'] === 'group' ) {
					// Handle duplicate group name and sub-field name (@see #862jkjubc @since 1.8.2)
					$parent_field_group = get_field_object( $this->tags[ $tag ]['parent']['name'], $post_id );

					// Get the sub-fields from the parent field group
					if ( $parent_field_group && isset( $parent_field_group['sub_fields'] ) ) {
						foreach ( $parent_field_group['sub_fields'] as $sub_field ) {
							if ( $sub_field['name'] === $field['name'] ) {
								// Found the actual sub-field, use it
								$actual_field = $sub_field;
								break;
							}
						}
					}
				}

				// Use the actual field settings if found (avoid error if $actual_field is false) (@see #862jkjubc @since 1.8.2)
				$field = $actual_field ? $actual_field : $field;
			}

			// STEP: Get the value
			$value = $this->filter_post_references( $this->get_raw_value( $tag, $post_id ), $field );

			$return_format = isset( $field['return_format'] ) ? $field['return_format'] : '';

			// @since 1.8 - New array_value filter. Once used, we don't want to process the field type logic
			if ( isset( $filters['array_value'] ) && is_array( $value ) ) {
				// Force context to text
				$context = 'text';
				$value   = $this->return_array_value( $value, $filters );
			}

			// Process field type logic
			else {
				switch ( $field['type'] ) {
					// Choice
					case 'select':
					case 'checkbox':
					case 'radio':
					case 'button_group':
						$value = $this->process_choices_fields( $value, $field, $filters );
						break;

					case 'true_false':
						// STEP: Return raw value for element conditions (@since 1.5.7)
						if ( isset( $filters['value'] ) ) {
							return is_array( $value ) ? implode( ', ', $value ) : $value;
						}

						$value = $value ? esc_html__( 'True', 'bricks' ) : esc_html__( 'False', 'bricks' );
						break;

					case 'user':
						if ( empty( $value ) ) {
							$value = []; // Avoid PHP warning when using wp_list_pluck
						} else {
							// Support :value filter to return IDs only
							if ( ! isset( $filters['value'] ) ) {
								$filters['object_type'] = 'user';
							}

							// ACF allows for single or multiple users
							$value = $field['multiple'] ? $value : [ $value ];

							$value = $return_format === 'id' ? $value : wp_list_pluck( $value, 'ID' );
						}

						break;

					case 'google_map':
						$value = $this->process_google_map_field( $value, $field );
						break;

					case 'taxonomy':
						if ( empty( $value ) ) {
							$value = []; // Avoid PHP warning when using wp_list_pluck
						} else {
							// Support :value filter to return IDs only
							if ( ! isset( $filters['value'] ) ) {
								$filters['object_type'] = 'term';
								$filters['taxonomy']    = $field['taxonomy'];

								// NOTE: Undocumented
								$show_as_link = apply_filters( 'bricks/acf/taxonomy/show_as_link', true, $value, $field );

								if ( $show_as_link ) {
									$filters['link'] = true;
								}
							}

							$value = is_array( $value ) ? $value : [ $value ];

							$value = $return_format === 'id' ? $value : wp_list_pluck( $value, 'term_id' );
						}
						break;

					case 'image':
					case 'gallery':
						// Support :value filter to return IDs only
						if ( ! isset( $filters['value'] ) ) {
							$filters['object_type'] = 'media';
							$filters['separator']   = '';
						}

						$value = empty( $value ) ? [] : (array) $value;

						if ( $return_format === 'array' ) {
							$value = isset( $value['id'] ) ? [ $value['id'] ] : wp_list_pluck( $value, 'id' );
						} elseif ( $return_format === 'url' ) {
							$value = array_map( 'attachment_url_to_postid', $value );
							$value = array_filter( $value );
						}
						break;

					case 'oembed':
						// Keep the ACF object and Group path when retrieving the URL instead of the iframe.
						if ( $context !== 'text' ) {
							$tag_object = $this->tags[ $tag ];
							$value      = isset( $tag_object['parent']['type'] ) && $tag_object['parent']['type'] === 'group'
								? $this->get_acf_group_field_value( $tag_object, $post_id, false )
								: $this->get_acf_field_value( $tag_object['field'], $post_id, false );
						} else {
							$filters['skip_sanitize'] = true;
						}
						break;

					// @since 2.0
					case 'icon_picker':
						$value = $this->process_icon_picker_field( $value, $field, $context, $filters );

						// If $value is SVG, return it as is
						if ( $value && \Bricks\Helpers::is_valid_svg( $value ) ) {
							return $value;
						}

						break;

					case 'file':
						// @since 1.7.1 - Empty field should return empty array
						if ( empty( $value ) ) {
							$value = [];
						} else {
							$filters['object_type'] = 'media';
							$filters['link']        = true;

							// Return file 'id'
							if ( $return_format === 'array' && is_array( $value ) ) {
								$value = $value['id'];
							} elseif ( $return_format === 'url' ) {
								$value = attachment_url_to_postid( $value );
							}
						}
						break;

					case 'link':
						// Possible returns: url or array
						if ( $return_format === 'array' ) {
							$value = isset( $value['url'] ) ? $value['url'] : '';
						}
						break;

					case 'post_object':
					case 'relationship':
						// Only field is not empty then process
						if ( ! empty( $value ) && $return_format === 'object' ) {
							// Support :value filter to return IDs only
							if ( ! isset( $filters['value'] ) ) {
								$filters['object_type'] = 'post';
								$filters['link']        = true;
							}

							if ( isset( $value->ID ) ) {
								$value = $value->ID;
							} elseif ( is_array( $value ) ) {
								$value = wp_list_pluck( $value, 'ID' );
							}
						}
						break;

					// @see: https://www.advancedcustomfields.com/resources/date-picker/
					// @see: https://www.advancedcustomfields.com/resources/date-time-picker/
					case 'date_picker':
					case 'date_time_picker':
					case 'time_picker':
						if ( ! empty( $filters['meta_key'] ) && ! empty( $value ) ) {
							// Default format
							$default_format = $field['type'] == 'date_picker' ? 'Ymd' : 'Y-m-d H:i:s';

							// @since 1.8 - If return format is set, use it (from ACF setting)
							if ( ! empty( $return_format ) ) {
								$default_format = $return_format;
							}

							$date = \DateTime::createFromFormat( $default_format, $value );

							// @since 1.8 - Prevent error if date is not valid due to unexpected issue
							if ( $date instanceof \DateTime ) {
								$value                  = $date->format( 'U' );
								$filters['object_type'] = $field['type'] == 'date_picker' ? 'date' : 'datetime';
							}
						}
						break;

						// @since 1.5.1
					case 'color_picker':
						// Possible return formats: 'string', 'array' (rgba)
						if ( $return_format === 'array' ) {
							$red   = ! empty( $value['red'] ) ? $value['red'] : 0;
							$green = ! empty( $value['green'] ) ? $value['green'] : 0;
							$blue  = ! empty( $value['blue'] ) ? $value['blue'] : 0;
							$alpha = ! empty( $value['alpha'] ) ? $value['alpha'] : 0;

							$value = $red === 0 && $green === 0 && $blue === 0 && $alpha === 0 ? '' : "rgba({$red},{$green},{$blue},{$alpha})";
						}
						break;
				}
			}
		}

		// For ACF dynamic functions
		else {
			switch ( $tag ) {
				case 'acf_get_row_layout':
					$value = '';

					// If any looping (@since 1.10)
					$any_loop_id = \Bricks\Query::is_any_looping();
					if ( $any_loop_id ) {
						$looping_object = \Bricks\Query::get_loop_object( $any_loop_id );

						// Check if current loop object is an ACF flexible content: Must be an array & must have the 'acf_fc_layout' key (@since 1.8)
						if ( is_array( $looping_object ) && isset( $looping_object['acf_fc_layout'] ) ) {
							$value = $looping_object['acf_fc_layout'];
						}
					}
					break;
			}
		}

		// STEP: Apply context (text, link, image, media)
		$value = $this->format_value_for_context( $value, $tag, $post_id, $filters, $context );
		return $value;
	}

	/**
	 * Get the field raw value
	 *
	 * @param array      $tag The tag name (e.g. acf_my_field).
	 * @param int|string $post_id The post ID.
	 */
	public function get_raw_value( $tag, $post_id ) {
		$tag_object = $this->tags[ $tag ];
		$field      = $tag_object['field'];

		// Check if field is belongs to options page
		$locations = isset( $field['_bricks_locations'] ) ? $field['_bricks_locations'] : [];
		$is_option = ! empty( $locations ) && in_array( 'option', $locations );

		if ( isset( $tag_object['parent']['_bricks_locations'] ) && ! empty( $tag_object['parent']['_bricks_locations'] ) && ! $is_option ) {
			// Check if parent field is belongs to options page
			$is_option = in_array( 'option', $tag_object['parent']['_bricks_locations'] );
		}

		if ( isset( $tag_object['nested_group'] ) && isset( $tag_object['_bricks_locations'] ) && ! empty( $tag_object['_bricks_locations'] ) && ! $is_option ) {
			// Check if nested group field is belongs to options page (@since 1.9.1.1)
			$is_option = in_array( 'option', $tag_object['_bricks_locations'] );
		}

		// STEP: Check if in a Relationship, or Repeater loop (could have nested groups inside - @since 1.5.1)
		// Use is_any_looping, otherwise using dynamic data on Query that's not render yet will be incorrect (@since 1.10)
		$any_loop_id = \Bricks\Query::is_any_looping();
		if ( $any_loop_id ) {
			$query_type = \Bricks\Query::get_query_object_type( $any_loop_id );

			// Loop belongs to this provider
			if ( array_key_exists( $query_type, $this->loop_tags ) ) {
				// Query Loop tag object
				$loop_tag_object = $this->loop_tags[ $query_type ];

				// Query Loop tag ACF field key
				$loop_tag_field_key = isset( $loop_tag_object['field']['key'] ) ? $loop_tag_object['field']['key'] : false;

				if ( empty( $loop_tag_field_key ) ) {
					return '';
				}

				/**
				 * Loop created by an ACF Relationship field (@since 1.5.1)
				 * OR an ACF Post Object field (@since 1.8.6)
				 */
				if (
					isset( $loop_tag_object['field']['type'] ) &&
					in_array( $loop_tag_object['field']['type'], [ 'relationship', 'post_object' ] )
				) {
					// The loop already sets the global $post
					$post_id = get_the_ID();

					// $post_id is still empty && we are in the builder call: Try getting the queried object (#86by703hw)
					if ( ! $post_id && \Bricks\Api::is_current_endpoint( 'render_element' ) ) {
						$temp_post_object = \Bricks\Helpers::get_queried_object( $post_id );
						// Ensure it is an object with ID property
						if ( is_object( $temp_post_object ) && isset( $temp_post_object->ID ) ) {
							$post_id = $temp_post_object->ID;
						}
					}

					// Is a Group sub-field
					if ( isset( $tag_object['parent']['type'] ) && $tag_object['parent']['type'] === 'group' ) {
						return $this->get_acf_group_field_value( $tag_object, $post_id );
					}

					// Is a regular field
					return $this->get_acf_field_value( $field, $post_id );
				}

				// NOTE: Bricks needs to build a path to get the final value from the $loop_object
				// This is needed as we don't use the group field, but only the sub-fields (which could also be groups = nested groups!)
				// The iteration starts on the field to which we need to get the value and iterates its parent until it is the query loop field.

				// Store the field names while iterating
				$value_path = [];

				// Get the first parent field object
				$parent_field = isset( $tag_object['parent']['key'] ) ? get_field_object( $tag_object['parent']['key'] ) : false;

				// Check if the parent field is the loop field; if not, iterate up
				while ( isset( $parent_field['key'] ) && $parent_field['key'] !== $loop_tag_field_key ) {
					if ( isset( $parent_field['name'] ) ) {
						$value_path[] = $parent_field['name'];

						// Get the parent field tag object (as registered in Bricks)
						$parent_tag = isset( $this->tags[ $parent_field['name'] ] ) ? $this->tags[ $parent_field['name'] ] : false;
					} else {
						$parent_tag = false;
					}

					// Get the parent of the parent
					$parent_field = isset( $parent_tag['parent']['key'] ) ? get_field_object( $parent_tag['parent']['key'] ) : false;
				}

				// The current loop object (array of values)
				$narrow_values = \Bricks\Query::get_loop_object( $any_loop_id );

				/**
				 * If the field is a nested group field, the $value_path should use the parent group names
				 * Eg. Flexible content > Layout > Group > Group > Field
				 * Eg. Repeater > Group > Group > Field
				 * (@since 1.9.1.1)
				 */
				if ( isset( $tag_object['nested_group'] ) && isset( $tag_object['parent_group_names'] ) ) {
					// Flexible content field is not supported inside a loop
					$value_path = $tag_object['parent_group_names'];
				}

				if ( ! empty( $value_path ) ) {
					// Start with the top parent field name, and go deeper (groups inside of groups..)
					$value_path = array_reverse( $value_path );

					foreach ( $value_path as $name ) {
						if ( isset( $narrow_values[ $name ] ) ) {
							$narrow_values = $narrow_values[ $name ];
						}
					}
				}

				$found_value = isset( $narrow_values[ $field['name'] ] ) ? $narrow_values[ $field['name'] ] : '';

				// Return the value if this is not an option page field (@since 1.8)
				if ( ! $is_option ) {
					return $found_value;
				}

				// This is option page field, and found in the $narrow_values, return it and stop here (@since 1.8)
				// Cannot use ! empty( $found_value ) as it could be empty string
				if ( isset( $narrow_values[ $field['name'] ] ) ) {
					return $found_value;
				}

				// Option page field inside the loop, but not found in $narrow_values will continue with group sub-field and regular logic below (@since 1.8)
				// Ex: Output an option page field inside a another repeater loop (#862jvqkma)
			}
		}

		// STEP: Is a Group sub-field
		if ( isset( $tag_object['parent']['type'] ) && $tag_object['parent']['type'] === 'group' ) {
			return $this->get_acf_group_field_value( $tag_object, $post_id );
		}

		// STEP: Still here, get the regular value for this field
		return $this->get_acf_field_value( $field, $post_id );
	}

	/**
	 * Get ACF group field value
	 *
	 * @since 1.5
	 *
	 * @param array      $tag_object Registered tag settings.
	 * @param int|string $post_id The post ID.
	 * @param bool       $format_value Whether ACF should format the value.
	 */
	public function get_acf_group_field_value( $tag_object, $post_id, $format_value = true ) {
		// Unformatted Group arrays use field keys, so resolve the sub-field by its full name.
		if ( isset( $tag_object['nested_group'] ) || ! $format_value ) {
			/**
			 * Support nested groups
			 *
			 * @since 1.9.1
			 */
			// STEP: Remove the {acf_ and } from the tag name. Eg: groupa_groupb_groupc_field
			$field_selector = str_replace( '{acf_', '', $tag_object['name'] );
			$field_selector = str_replace( '}', '', $field_selector );

			// STEP: We need to know the acf_object_id when using get_field(), could be post, term, user, option etc..
			$acf_object_id = $this->get_object_id( isset( $tag_object['nested_group'] ) ? $tag_object : $tag_object['parent'], $post_id );

			// STEP: Use ACF get_field() to get the value
			$value = get_field( $field_selector, $acf_object_id, $format_value );
		} else {

			$field       = $tag_object['field'];
			$group_field = get_field_object( $tag_object['parent']['key'] );

			if ( ! empty( $tag_object['parent']['_bricks_locations'] ) ) {
				$group_field['_bricks_locations'] = $tag_object['parent']['_bricks_locations'];
			}

			$group_value = $this->get_acf_field_value( $group_field, $post_id );

			$value = isset( $group_value[ $field['name'] ] ) ? $group_value[ $field['name'] ] : '';
		}

		return $value;
	}

	/**
	 * Get ACF field value
	 *
	 * @param array      $field ACF field settings.
	 * @param int|string $post_id The post ID.
	 * @param bool       $format_value Whether ACF should format the value.
	 */
	public function get_acf_field_value( $field, $post_id, $format_value = true ) {
		$acf_object_id = $this->get_object_id( $field, $post_id );
		$field_type    = ! empty( $field['type'] ) ? $field['type'] : '';

		// Don't remove 'acf_the_content' filter for 'wysiwyg' field type (@since 1.7)
		if ( $field_type !== 'wysiwyg' ) {
			remove_filter( 'acf_the_content', 'wpautop' );
		}

		/**
		 * Get ACF field value
		 *
		 * @see https://www.advancedcustomfields.com/resources/get_field/
		 *
		 * @since 1.7.1 Get field by 'name' instead of 'key' as the 'key' is not reliable for clone type fields (@see #862jb27g9)
		 */
		$field_selector = ! empty( $field['name'] ) ? $field['name'] : $field['key'];
		$value          = get_field( $field_selector, $acf_object_id, $format_value );

		if ( $field_type !== 'wysiwyg' ) {
			add_filter( 'acf_the_content', 'wpautop' );
		}

		return $value;
	}

	/**
	 * Process the choice fields to return an array of choice labels
	 *
	 * @param [type] $value
	 * @param [type] $field
	 * @param [type] $filters
	 */
	public function process_choices_fields( $value, $field, $filters ) {
		$value = empty( $value ) ? [] : (array) $value; // Avoid PHP error if $value is null

		// If return format is set to "Both (array)" return 'label' by default
		if ( ! empty( $field['return_format'] ) ) {
			if ( $field['return_format'] === 'array' ) {
				// @since 1.7.2 - Support :value filter to return the value instead of the label
				$key          = isset( $filters['value'] ) ? 'value' : 'label';
				$unwanted_key = $key === 'label' ? 'value' : 'label';

				if ( isset( $value[ $unwanted_key ] ) ) {
					// For single choice field
					unset( $value[ $unwanted_key ] );
				} else {
					// For multiple choice field
					$value = wp_list_pluck( $value, $key );
				}
			}
		}

		return $value;
	}

	public function process_google_map_field( $value, $field ) {
		// NOTE: Undocumented. By default, the google map field will show as an address, if ACF version >= 5.6.8 @see https://www.advancedcustomfields.com/resources/google-map/
		$show_as_address = apply_filters( 'bricks/acf/google_map/show_as_address', defined( 'ACF_VERSION' ) && version_compare( ACF_VERSION, '5.6.8', '>=' ), $value, $field );

		$output = [];

		if ( $show_as_address ) {
			// NOTE: Undocumented. Filter or order the address parts
			$address_parts = apply_filters( 'bricks/acf/google_map/address_parts', [ 'street_name', 'street_number', 'city', 'state', 'post_code', 'country' ], $value, $field );

			foreach ( $address_parts as $key ) {
				if ( ! empty( $value[ $key ] ) ) {
					$output[] = sprintf( '<span class="acf-map-%s">%s</span>', esc_attr( $key ), esc_html( $value[ $key ] ) );
				}
			}

		} else {
			foreach ( [ 'lat', 'lng' ] as $key ) {
				if ( ! empty( $value[ $key ] ) ) {
					$output[] = sprintf( '<span class="acf-map-%s">%s</span>, ', esc_attr( $key ), esc_html( $value[ $key ] ) );
				}
			}
		}

		// NOTE: Undocumented.
		return apply_filters( 'bricks/acf/google_map/text_output', implode( ', ', $output ), $value, $field );
	}

	/**
	 * Process the icon picker field
	 *
	 * @since 2.0
	 *
	 * @param mixed  $value   Field value.
	 * @param array  $field   Field data.
	 * @param string $context Dynamic data context.
	 * @param array  $filters Dynamic data filters.
	 *
	 * @return string|mixed
	 */
	public function process_icon_picker_field( $value, $field, $context, $filters ) {

		// If return format is not set, return $value
		if ( empty( $field['return_format'] ) ) {
			return $value;
		}

		// If return format is set to "String" return 'value' by default
		if ( $field['return_format'] === 'string' ) {
			// If $value is URL, we return <img src="$value" />
			if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {

				// Inline SVG URLs when they resolve to safe SVG content.
				$svg = $this->get_svg_content_from_url( $value );

				if ( $svg ) {
					return $svg;
				}

				return sprintf( '<img src="%s" />', esc_url( $value ) );
			}

			// If the value is not URL, add $value as class and return the icon eg. <i class="dashicons dashicons-amazon"></i>
			// We also need to enqueue dashicons
			else {

				// Enqueue dashicons
				wp_enqueue_style( 'bricks-dashicons', includes_url( '/css/dashicons.min.css' ), [], null );

				return sprintf( '<i class="dashicons %s"></i>', esc_attr( $value ) );

			}
		}
		// If return format is set to "Array" return 'label' by default
		else {

			switch ( $value['type'] ) {
				// If the type is Dashicon, render a <i> with the dashicon class.
				case 'dashicons':
					return sprintf( '<i class="dashicons %s"></i>', esc_attr( $value['value'] ) );

				// If the type is Media, render a image from media
				case 'media_library':
					$attachment_id = $value['value']['id'];

					// if $context is image, return the attachment id
					if ( $context === 'image' ) {
						return $value['value']['id'];
					}

					$size = 'full'; // (thumbnail, medium, large, full, or custom size)

					// Check if attachment is SVG
					$attachment = get_post( $attachment_id );
					if ( $attachment && $attachment->post_mime_type === 'image/svg+xml' ) {
						// Inline Media Library SVGs from their attachment file path.
						$svg = $this->get_svg_content_from_attachment( $attachment_id );

						// Return non-empty attachment SVGs without extra validation to match prior behavior.
						if ( $svg ) {
							return $svg;
						}
					}

					$image_html = wp_get_attachment_image( $attachment_id, $size );
					return wp_kses_post( $image_html );

				// If the type is URL, render a image from URL
				case 'url':
					// If $value is URL, we return <img src="$value" />
					if ( filter_var( $value['value'], FILTER_VALIDATE_URL ) ) {

						// Inline SVG URLs when they resolve to safe SVG content.
						$svg = $this->get_svg_content_from_url( $value['value'] );

						if ( $svg ) {
							return $svg;
						}

						return sprintf( '<img src="%s" />', esc_url( $value['value'] ) );
					}

					break;
			}
		}

		// Return empty string if no value
		return '';
	}

	/**
	 * Get SVG content from a Media Library attachment.
	 *
	 * @since 2.3.9
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string SVG content or empty string.
	 */
	private function get_svg_content_from_attachment( $attachment_id ) {
		// Convert the attachment ID into a local file path instead of fetching the public URL.
		$svg_path = get_attached_file( $attachment_id );

		// Read the SVG directly from disk to avoid same-origin HTTP requests and PHP-FPM recursion.
		return $svg_path ? \Bricks\Helpers::file_get_contents( $svg_path ) : '';
	}

	/**
	 * Get SVG content from URL, preferring attachment file path over HTTP request.
	 *
	 * @since 2.3.9
	 *
	 * @param string $url SVG URL.
	 *
	 * @return string SVG content or empty string if invalid.
	 */
	private function get_svg_content_from_url( $url ) {
		// Use the URL path for SVG detection so query strings do not affect the extension check.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! $url_path || strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) ) !== 'svg' ) {
			return '';
		}

		// Strip query strings/fragments before resolving the Media Library attachment ID.
		$attachment_url = preg_replace( '/[?#].*/', '', $url );
		$attachment_id  = attachment_url_to_postid( $attachment_url );

		if ( $attachment_id ) {
			// Keep attachment SVG handling permissive to preserve existing Media Library behavior.
			return $this->get_svg_content_from_attachment( $attachment_id );
		}

		// Fetch unresolved URLs remotely because they may point to external SVG files.
		$response = \Bricks\Helpers::remote_get( $url );
		$svg      = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ? wp_remote_retrieve_body( $response ) : '';

		// Validate remote content before inlining anything from outside the Media Library.
		return ! empty( $svg ) && \Bricks\Helpers::is_valid_svg( $svg ) ? $svg : '';
	}

	/**
	 * Get all fields supported and their contexts
	 *
	 * @return array
	 */
	private static function get_fields_by_context() {
		$fields = [
			// Basic
			'text'             => [ self::CONTEXT_TEXT ],
			'textarea'         => [ self::CONTEXT_TEXT ],
			'number'           => [ self::CONTEXT_TEXT ],
			'range'            => [ self::CONTEXT_TEXT ],
			'email'            => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],
			'url'              => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],
			'password'         => [ self::CONTEXT_TEXT ],

			// Content
			'image'            => [ self::CONTEXT_TEXT, self::CONTEXT_IMAGE ],
			'gallery'          => [ self::CONTEXT_TEXT, self::CONTEXT_IMAGE ],
			'file'             => [ self::CONTEXT_TEXT, self::CONTEXT_LINK, self::CONTEXT_VIDEO, self::CONTEXT_MEDIA ],
			'wysiwyg'          => [ self::CONTEXT_TEXT ],
			'oembed'           => [ self::CONTEXT_TEXT, self::CONTEXT_LINK, self::CONTEXT_VIDEO, self::CONTEXT_MEDIA ],
			'icon_picker'      => [ self::CONTEXT_TEXT, self::CONTEXT_IMAGE ],

			// Choice
			'select'           => [ self::CONTEXT_TEXT ],
			'checkbox'         => [ self::CONTEXT_TEXT ],
			'radio'            => [ self::CONTEXT_TEXT ],
			'button_group'     => [ self::CONTEXT_TEXT ],
			'true_false'       => [ self::CONTEXT_TEXT ],

			// Relational
			'link'             => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],
			'post_object'      => [ self::CONTEXT_TEXT, self::CONTEXT_LINK, self::CONTEXT_LOOP ], // Support Query Loop @since 1.8.6
			'page_link'        => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],
			'relationship'     => [ self::CONTEXT_TEXT, self::CONTEXT_LINK, self::CONTEXT_LOOP ],
			'taxonomy'         => [ self::CONTEXT_TEXT, self::CONTEXT_LINK ],
			'user'             => [ self::CONTEXT_TEXT ],

			// jQuery
			'google_map'       => [ self::CONTEXT_TEXT ],
			'date_picker'      => [ self::CONTEXT_TEXT ],
			'date_time_picker' => [ self::CONTEXT_TEXT ],
			'time_picker'      => [ self::CONTEXT_TEXT ],
			'color_picker'     => [ self::CONTEXT_TEXT ],

			'group'            => [ self::CONTEXT_TEXT ],
			'repeater'         => [ self::CONTEXT_LOOP ],
			'flexible_content' => [ self::CONTEXT_LOOP ],
		];

		return $fields;
	}

	/**
	 * Calculate the object ID to be used when fetching the field value
	 *
	 * @param array $field
	 * @param int   $post_id
	 */
	public function get_object_id( $field, $post_id ) {

		$locations = isset( $field['_bricks_locations'] ) ? $field['_bricks_locations'] : [];

		// This field belongs to a Options page
		if ( in_array( 'option', $locations ) ) {
			return 'option';
		}

		// If any looping (@since 1.10)
		$any_loop_id = \Bricks\Query::is_any_looping();
		if ( $any_loop_id ) {
			$object_type = \Bricks\Query::get_loop_object_type( $any_loop_id );
			$object_id   = \Bricks\Query::get_loop_object_id( $any_loop_id );

			// Terms loop
			if ( $object_type == 'term' && in_array( $object_type, $locations ) ) {
				$object = \Bricks\Query::get_loop_object( $any_loop_id );

				return isset( $object->taxonomy ) ? $object->taxonomy . '_' . $object_id : $post_id;
			}

			// Users loop
			if ( $object_type == 'user' && in_array( $object_type, $locations ) ) {
				return 'user_' . $object_id;
			}
		}

		$queried_object = \Bricks\Helpers::get_queried_object( $post_id );

		if ( in_array( 'term', $locations ) && is_a( $queried_object, 'WP_Term' ) ) {
			if ( isset( $queried_object->taxonomy ) && isset( $queried_object->term_id ) ) {
				return $queried_object->taxonomy . '_' . $queried_object->term_id;
			}
		}

		if ( in_array( 'user', $locations ) ) {
			if ( is_a( $queried_object, 'WP_User' ) && isset( $queried_object->ID ) ) {
				return 'user_' . $queried_object->ID;
			}

			if ( count( $locations ) == 1 ) {
				return 'user_' . get_current_user_id();
			}
		}

		// Default
		return $this->get_order_field_id( $field, $post_id );
	}

	/**
	 * Resolve order fields without changing ACF's location metadata or other post fields.
	 *
	 * @since 2.4.4
	 *
	 * @param array $field Field or nested group tag.
	 * @param int   $post_id Original post ID.
	 * @return int
	 */
	private function get_order_field_id( $field, $post_id ) {
		$order_id = $this->get_view_order_id( $post_id );

		if ( $order_id === $post_id ) {
			return $post_id;
		}

		$field = $field['field'] ?? $field;
		$field = ! empty( $field['key'] ) ? acf_get_field( $field['key'] ) : false;

		// Sub-fields inherit the location of their owning group, including nested groups.
		while ( $field && ! empty( $field['parent'] ) ) {
			$group = acf_get_field_group( $field['parent'] );

			if ( $group ) {
				foreach ( $group['location'] ?? [] as $rules ) {
					foreach ( $rules as $rule ) {
						if ( ( $rule['param'] ?? '' ) === 'post_type' && ( $rule['operator'] ?? '' ) === '==' && ( $rule['value'] ?? '' ) === 'shop_order' ) {
							return $order_id;
						}
					}
				}

				return $post_id;
			}

			$field = acf_get_field( $field['parent'] );
		}

		return $post_id;
	}

	/**
	 * Exclude deleted selections without changing ACF return formats or other post statuses.
	 *
	 * @since 2.4.4
	 *
	 * @param mixed $value ACF field value.
	 * @param array $field ACF field settings.
	 * @return mixed
	 */
	private function filter_post_references( $value, $field ) {
		if ( empty( $value ) || ! in_array( $field['type'], [ 'post_object', 'relationship' ], true ) ) {
			return $value;
		}

		$is_valid = static function( $reference ) {
			$post_id = is_object( $reference ) && isset( $reference->ID ) ? $reference->ID : $reference;

			// get_post(0) falls back to the global post, which is not a selected reference.
			if ( ! is_numeric( $post_id ) || $post_id <= 0 ) {
				return false;
			}

			$post = get_post( $post_id );
			return $post && $post->post_status !== 'trash';
		};

		if ( is_array( $value ) ) {
			return array_values( array_filter( $value, $is_valid ) );
		}

		return $is_valid( $value ) ? $value : '';
	}

	/**
	 * Set the loop query if exists
	 *
	 * @param array $results
	 * @param Query $query
	 * @return array
	 */
	public function set_loop_query( $results, $query ) {
		if ( ! array_key_exists( $query->object_type, $this->loop_tags ) ) {
			return $results;
		}

		$tag_object = $this->loop_tags[ $query->object_type ];

		$field = $this->loop_tags[ $query->object_type ]['field'];

		$looping_query_id = \Bricks\Query::is_any_looping();

		if ( $looping_query_id ) {
			$loop_query_object_type = \Bricks\Query::get_query_object_type( $looping_query_id );
			$loop_object_type       = \Bricks\Query::get_loop_object_type( $looping_query_id );

			// Maybe it is a nested loop
			if ( array_key_exists( $loop_query_object_type, $this->loop_tags ) ) {
				$loop_object                  = \Bricks\Query::get_loop_object( $looping_query_id );
				$loop_query_object_field_type = $this->loop_tags[ $loop_query_object_type ]['field']['type'] ?? '';

				// If this is a nested repeater
				if ( is_array( $loop_object ) && array_key_exists( $field['name'], $loop_object ) ) {
					$nested_loop_object = $loop_object[ $field['name'] ];

					// If the field type is 'post_object', transform the single post object into an array. (@since 1.9.8)
					if ( $field['type'] === 'post_object' && ! empty( $nested_loop_object ) ) {
						$nested_loop_object = is_array( $nested_loop_object ) ? $nested_loop_object : [ $nested_loop_object ];
					}

					return $this->filter_post_references( $nested_loop_object, $field );
				}

				/**
				 * Nested repeater inside nested group
				 *
				 * Exception: Looping object field type is 'relationship' or 'post_object'. Don't use the nested group logic (@since 1.10)
				 *
				 * @since 1.9.1
				 */
				if ( isset( $tag_object['nested_group'] ) && ! in_array( $loop_query_object_field_type, [ 'relationship','post_object' ] ) ) {
					// Parent group name first. Eg: ['general', 'actors']
					$parent_group_names = $tag_object['parent_group_names'] ?? [];

					// No parent group names: Return empty array
					if ( empty( $parent_group_names ) ) {
						return [];
					}

					// if nested group field is belongs to options page, use get_acf_group_field_value() to get the value (@since 1.9.1.1)
					if ( isset( $tag_object['_bricks_locations'] ) && ! empty( $tag_object['_bricks_locations'] ) && in_array( 'option', $tag_object['_bricks_locations'] ) ) {
						return $this->filter_post_references( $this->get_acf_group_field_value( $tag_object, 0 ), $field );
					}

					// Reverse the array to get the top parent group name first. Eg: ['actors', 'general']
					$parent_group_names = array_reverse( $parent_group_names );
					// Add the current group name to the array Eg: ['actors', 'general', 'lists']
					$parent_group_names[] = $field['name'];

					foreach ( $parent_group_names as $parent_group_name ) {
						if ( is_array( $loop_object ) && isset( $loop_object[ $parent_group_name ] ) ) {
							$loop_object = $loop_object[ $parent_group_name ];
						} else {
							return []; // Parent group name not found, return empty array
						}
					}

					return $this->filter_post_references( $loop_object, $field );
				}

				/**
				 * Repeater > Group > Repeater (#86c0nrff0)
				 *
				 * @since 1.11.1
				 */
				$name_from_dd_tag = str_replace( '{acf_', '', $tag_object['name'] );
				$name_from_dd_tag = str_replace( '}', '', $name_from_dd_tag );

				if ( $name_from_dd_tag !== $field['name'] ) {
					// use get_acf_group_field_value() if belongs to options page
					if ( isset( $tag_object['_bricks_locations'] ) && ! empty( $tag_object['_bricks_locations'] ) && in_array( 'option', $tag_object['_bricks_locations'] ) ) {
						return $this->filter_post_references( $this->get_acf_group_field_value( $tag_object, 0 ), $field );
					}

					$maybe_group_name = str_replace( '_' . $field['name'], '', $name_from_dd_tag );

					if ( isset( $maybe_group_name ) && is_array( $loop_object ) && isset( $loop_object[ $maybe_group_name ] ) && isset( $loop_object[ $maybe_group_name ][ $field['name'] ] ) ) {
						return $this->filter_post_references( $loop_object[ $maybe_group_name ][ $field['name'] ], $field );
					}
				}

				// If this is a nested relationship
				if ( is_object( $loop_object ) && is_a( $loop_object, 'WP_Post' ) ) {
					$acf_object_id = get_the_ID();
				}
			}

			/**
			 * Check: Is it a post loop?
			 *
			 * @since 1.7: use $loop_object_type instead of $loop_query_object_type so that it works with user custom queries via PHP filters
			 */
			elseif ( $loop_object_type === 'post' ) {
				$acf_object_id = get_the_ID();
			}
		}

		if ( ! isset( $acf_object_id ) ) {
			// Get the $post_id or the template preview ID
			$post_id = \Bricks\Database::$page_data['preview_or_post_id'];

			$acf_object_id = $this->get_object_id( $field, $post_id );
		}

		// Check if it is a subfield of a group field (Repeater inside of a Group)
		if ( isset( $tag_object['parent']['type'] ) && $tag_object['parent']['type'] === 'group' ) {
			$post_id = \Bricks\Database::$page_data['preview_or_post_id'];

			/**
			 * Use get_the_ID()
			 *
			 * - If looping object is a post.
			 * - If looping object field type is 'relationship' or 'post_object'.
			 *
			 * @since 1.10
			 */
			if (
				( isset( $loop_query_object_type ) && $loop_query_object_type === 'post' ) ||
				( isset( $loop_query_object_field_type ) && in_array( $loop_query_object_field_type, [ 'relationship','post_object' ], true ) )
			) {
				$post_id = get_the_ID();
			}

			$results = $this->get_acf_group_field_value( $tag_object, $post_id );
		} else {
			/**
			 * Use $field['name'] instead of $field['key'], clone type fields $field['key'] is not reliable (see #862jb27g9; @since 1.7.1)
			 *
			 * @see https://www.advancedcustomfields.com/resources/get_field/
			 */
			$results = get_field( $field['name'], $acf_object_id );
		}

		/**
		 * If the field is a post_object, then the results could be a single post object or an array of post objects
		 *
		 * Transform the single post object into an array.
		 *
		 * @since 1.9
		 */
		if ( $field['type'] === 'post_object' && ! empty( $results ) ) {
			$results = is_array( $results ) ? $results : [ $results ];
		}

		return $this->filter_post_references( ! empty( $results ) ? $results : [], $field );
	}

	/**
	 * Manipulate the loop object
	 *
	 * @param array  $loop_object
	 * @param string $loop_key
	 * @param Query  $query
	 * @return array
	 */
	public function set_loop_object( $loop_object, $loop_key, $query ) {
		if ( ! array_key_exists( $query->object_type, $this->loop_tags ) ) {
			return $loop_object;
		}

		// Check if the ACF field is relationship (list of posts)
		$field = $this->loop_tags[ $query->object_type ]['field'];

		// 'relationship' and 'post_object' needs to set the global $post (@since 1.8.6)
		if ( in_array( $field['type'], [ 'relationship','post_object' ] ) ) {
			global $post;
			$post = get_post( $loop_object );
			setup_postdata( $post );

			// The $loop_object could be a post ID or a post object, returning the post object (@since 1.5.3)
			return $post;
		}

		return $loop_object;
	}

	public static function flush_cache( $post_id ) {
		wp_cache_set( 'last_changed', microtime(), 'bricks_' . get_post_type( $post_id ) );
	}

	/**
	 * Retrieve all registered tags which are supported in WP_Query post__in parameter
	 *
	 * @since 1.12
	 */
	public function get_query_supported_tags() {
		$field_types = [
			'relationship',
			'post_object',
			'gallery',
			'user', // (#86c75ahhj; @since 2.4)
		];

		$supported_tags = [];

		foreach ( $this->tags as $tag ) {
			if ( ! isset( $tag['field'] ) ) {
				continue;
			}

			if ( isset( $tag['deprecated'] ) ) {
				continue;
			}

			$field      = $tag['field'] ?? [];
			$field_type = $field['type'] ?? '';

			if ( in_array( $field_type, $field_types, true ) ) {
				$params = [ 'post__in' ];

				// For user field type, we also support 'author__in' and 'author__not_in' parameters (#86c75ahhj; @since 2.4)
				if ( $field_type === 'user' ) {
					$params = [ 'author__in', 'author__not_in' ];
				}

				$supported_tags[] = [
					'name'     => $tag['name'],
					'type'     => $field_type,
					'label'    => $tag['label'],
					'params'   => $params,
					'provider' => $tag['provider'],
				];
			}
		}

		return $supported_tags;
	}

	/**
	 * Retrieve all registered tags which are supported "Results Filter" (Array conditions)"
	 * - Repeater fields
	 *
	 * @since 2.2
	 */
	public function get_array_supported_tags() {
		$field_types = [
			'repeater',
		];

		$supported_tags = [];

		foreach ( $this->loop_tags as $key => $tag ) {
			if ( ! isset( $tag['field'] ) ) {
				continue;
			}

			if ( isset( $tag['deprecated'] ) ) {
				continue;
			}

			$field      = $tag['field'] ?? [];
			$field_type = $field['type'] ?? '';

			if ( in_array( $field_type, $field_types, true ) ) {
				$supported_tags[] = [
					'name'       => $tag['name'],
					'type'       => $field_type,
					'label'      => $tag['label'],
					'provider'   => $tag['provider'],
					'objectType' => $key,
				];
			}
		}
		return $supported_tags;
	}

	/**
	 * Add ACF-specific control options.
	 *
	 * @since 2.4
	 */
	public function add_control_options( $control_options ) {
		$control_options = parent::add_control_options( $control_options );

		$control_options['acfFlexibleQueryTypes'] = $this->get_flexible_content_query_types();

		return $control_options;
	}

	/**
	 * Return objectType keys for ACF Flexible Content loop tags.
	 *
	 * @since 2.4
	 */
	public function get_flexible_content_query_types() {
		$types = [];

		foreach ( $this->loop_tags as $key => $tag ) {
			$field = $tag['field'] ?? [];

			if ( ( $field['type'] ?? '' ) === 'flexible_content' ) {
				$types[] = $key;
			}
		}

		return $types;
	}
}

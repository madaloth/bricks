<?php
namespace Bricks\Integrations\Dynamic_Data\Providers;

use Bricks\Woocommerce;
use Bricks\Query;
use Bricks\Helpers;
use Bricks\Database;
use Bricks\Api;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Provider_Wp extends Base {
	public function register_tags() {
		$tags = $this->get_tags_config();

		foreach ( $tags as $key => $tag ) {
			$this->tags[ $key ] = [
				'name'     => '{' . $key . '}',
				'label'    => $tag['label'],
				'group'    => $tag['group'],
				'provider' => $this->name,
			];

			if ( ! empty( $tag['deprecated'] ) ) {
				$this->tags[ $key ]['deprecated'] = $tag['deprecated'];
			}

			if ( ! empty( $tag['render'] ) ) {
				$this->tags[ $key ]['render'] = $tag['render'];
			}
		}
	}

	public function get_tags_config() {
		$tags = [
			// Post
			'post_title'                 => [
				'label' => esc_html__( 'Post title', 'bricks' ),
				'group' => 'post'
			],

			'post_id'                    => [
				'label' => esc_html__( 'Post ID', 'bricks' ),
				'group' => 'post'
			],

			'post_url'                   => [
				'label' => esc_html__( 'Post link', 'bricks' ),
				'group' => 'post'
			],

			// @since 1.9.8
			'post_slug'                  => [
				'label' => esc_html__( 'Post slug', 'bricks' ),
				'group' => 'post'
			],

			// @since 1.12
			'post_type'                  => [
				'label' => esc_html__( 'Post type', 'bricks' ),
				'group' => 'post'
			],

			'post_date'                  => [
				'label' => esc_html__( 'Post date', 'bricks' ),
				'group' => 'post',
			],

			'post_modified'              => [
				'label' => esc_html__( 'Post modified date', 'bricks' ),
				'group' => 'post',
			],

			'post_time'                  => [
				'label' => esc_html__( 'Post time', 'bricks' ),
				'group' => 'post',
			],

			'post_comments_count'        => [
				'label' => esc_html__( 'Post comments count', 'bricks' ),
				'group' => 'post'
			],

			'post_comments'              => [
				'label' => esc_html__( 'Post comments', 'bricks' ),
				'group' => 'post'
			],

			'post_content'               => [
				'label' => esc_html__( 'Post content', 'bricks' ),
				'group' => 'post'
			],

			'post_excerpt'               => [
				'label' => esc_html__( 'Post excerpt', 'bricks' ),
				'group' => 'post'
			],

			'read_more'                  => [
				'label' => esc_html__( 'Read more', 'bricks' ),
				'group' => 'post',
			],

			// Image
			'featured_image'             => [
				'label' => esc_html__( 'Featured image', 'bricks' ),
				'group' => 'post',
			],

			'featured_image_tag'         => [
				'label'      => esc_html__( 'Featured image tag', 'bricks' ),
				'group'      => 'post',
				'deprecated' => 1
			],

			// Author
			'author_id'                  => [
				'label' => esc_html__( 'Author ID', 'bricks' ),
				'group' => 'author',
			],

			'author_name'                => [
				'label' => esc_html__( 'Author name', 'bricks' ),
				'group' => 'author',
			],

			'author_bio'                 => [
				'label' => esc_html__( 'Author bio', 'bricks' ),
				'group' => 'author',
			],

			'author_email'               => [
				'label' => esc_html__( 'Author email', 'bricks' ),
				'group' => 'author',
			],

			'author_website'             => [
				'label' => esc_html__( 'Author website', 'bricks' ),
				'group' => 'author',
			],

			'author_archive_url'         => [
				'label' => esc_html__( 'Author archive URL', 'bricks' ),
				'group' => 'author',
			],

			'author_avatar'              => [
				'label' => esc_html__( 'Author avatar', 'bricks' ),
				'group' => 'author',
			],

			'author_meta'                => [
				'label' => esc_html__( 'Author meta', 'bricks' ) . ' (' . esc_html__( 'add key after', 'bricks' ) . ' ":")',
				'group' => 'author',
			],

			// Site
			'site_title'                 => [
				'label' => esc_html__( 'Site title', 'bricks' ),
				'group' => 'site',
			],

			'site_tagline'               => [
				'label' => esc_html__( 'Site tagline', 'bricks' ),
				'group' => 'site',
			],

			'site_url'                   => [
				'label' => esc_html__( 'Site URL', 'bricks' ),
				'group' => 'site',
			],

			'site_login'                 => [
				'label' => esc_html__( 'Login URL', 'bricks' ),
				'group' => 'site',
			],

			'site_logout'                => [
				'label' => esc_html__( 'Logout URL', 'bricks' ),
				'group' => 'site',
			],

			'url_parameter'              => [
				'label' => esc_html__( 'URL parameter', 'bricks' ) . ' (' . esc_html__( 'add key after', 'bricks' ) . ' ":")',
				'group' => 'site',
			],

			// Archive
			'archive_title'              => [
				'label' => esc_html__( 'Archive title', 'bricks' ),
				'group' => 'archive',
			],

			'archive_description'        => [
				'label' => esc_html__( 'Archive description', 'bricks' ),
				'group' => 'archive',
			],

			// Terms
			'term_id'                    => [
				'label'  => esc_html__( 'Term ID', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_name'                  => [
				'label'  => esc_html__( 'Term name', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_taxonomy_slug'         => [
				'label'  => esc_html__( 'Term taxonomy slug', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_slug'                  => [
				'label'  => esc_html__( 'Term slug', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_count'                 => [
				'label'  => esc_html__( 'Term count', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_url'                   => [
				'label'  => esc_html__( 'Term archive URL', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_parent'                => [
				'label'  => esc_html__( 'Term parent', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_children'              => [
				'label'  => esc_html__( 'Term children', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_description'           => [
				'label'  => esc_html__( 'Term description', 'bricks' ),
				'group'  => 'terms',
				'render' => 'terms',
			],

			'term_meta'                  => [
				'label'  => esc_html__( 'Term meta', 'bricks' ) . ' (' . esc_html__( 'add key after', 'bricks' ) . ' ":")',
				'group'  => 'terms',
				'render' => 'terms',
			],

			// Date
			'current_date'               => [
				'label' => esc_html__( 'Current date', 'bricks' ) . ' (UTC)',
				'group' => 'date',
			],

			'current_wp_date'            => [
				'label' => esc_html__( 'Current date', 'bricks' ) . ' (WordPress)',
				'group' => 'date',
			],

			// Helper function to convert a date formate (@since 2.2)
			'format_date'                => [
				'label' => esc_html__( 'String to date', 'bricks' ),
				'group' => 'date',
			],

			// Query
			'query_loop_index'           => [
				'label' => esc_html__( 'Query loop index', 'bricks' ),
				'group' => 'query',
			],

			'query_results_count'        => [
				'label' => esc_html__( 'Query results count', 'bricks' ),
				'group' => 'query',
			],

			// Query Filter
			'query_results_count_filter' => [
				'label' => esc_html__( 'Query results count', 'bricks' ) . ' (' . esc_html__( 'Filter', 'bricks' ) . ')',
				'group' => 'queryFilters',
			],

			'active_filters_count'       => [
				'label' => esc_html__( 'Active filters count', 'bricks' ),
				'group' => 'queryFilters',
			],

			/**
			 * Misc
			 *
			 * Live search results, etc.
			 *
			 * @since 1.9.6
			 */
			'search_term'                => [
				'label' => esc_html__( 'Search term', 'bricks' ),
				'group' => 'misc',
			],

			'search_term_filter'         => [
				'label' => esc_html__( 'Search term', 'bricks' ) . ' (' . esc_html__( 'Filter', 'bricks' ) . ')',
				'group' => 'queryFilters',
			],

			// Query API - Retrieve external API response data (@since 2.1)
			'query_api'                  => [
				'label' => esc_html__( 'Query API', 'bricks' ),
				'group' => 'query',
			],

			'query_array'                => [
				'label' => esc_html__( 'Query Array', 'bricks' ),
				'group' => 'query',
			],
		];

		// User Profile fields
		$user_fields = [
			'id'              => esc_html__( 'User ID', 'bricks' ),
			'login'           => esc_html__( 'Username', 'bricks' ),
			'email'           => esc_html__( 'Email', 'bricks' ),
			'url'             => esc_html__( 'Website', 'bricks' ),
			'role'            => esc_html__( 'Role', 'bricks' ),
			'registered_date' => esc_html__( 'User registered date', 'bricks' ),
			'author_url'      => esc_html__( 'User author URL', 'bricks' ),
			'nicename'        => esc_html__( 'Nicename', 'bricks' ),
			'nickname'        => esc_html__( 'Nickname', 'bricks' ),
			'description'     => esc_html__( 'Bio', 'bricks' ),
			'first_name'      => esc_html__( 'First name', 'bricks' ),
			'last_name'       => esc_html__( 'Last name', 'bricks' ),
			'display_name'    => esc_html__( 'Display name', 'bricks' ),
			'picture'         => esc_html__( 'Profile picture', 'bricks' ),
			'meta'            => esc_html__( 'User meta', 'bricks' ) . ' (' . esc_html__( 'add key after', 'bricks' ) . ' ":")',
		];

		foreach ( $user_fields as $key => $label ) {
			$tags[ 'wp_user_' . $key ] = [
				'label' => $label ,
				'group' => 'userProfile'
			];
		}

		// Add taxonomies related tags
		$taxs                  = get_taxonomies(
			[
				// 'public'  => true, (commented out @since 1.5, see: #38kj7az)
				'show_ui' => true,
			],
			'objects'
		);
		$taxonomy_label_counts = [];
		$taxonomy_tags         = [];

		foreach ( $taxs as $tax ) {
			if ( in_array( $tax->name, [ BRICKS_DB_TEMPLATE_TAX_TAG, BRICKS_DB_TEMPLATE_TAX_BUNDLE ], true ) ) {
				continue;
			}

			$taxonomy_label_counts[ $tax->label ] = ( $taxonomy_label_counts[ $tax->label ] ?? 0 ) + 1;
		}

		foreach ( $taxs as $tax ) {
			if ( in_array( $tax->name, [ BRICKS_DB_TEMPLATE_TAX_TAG, BRICKS_DB_TEMPLATE_TAX_BUNDLE ], true ) ) {
				continue;
			}

			$taxonomy_tags[ 'post_terms_' . $tax->name ] = [
				'label'  => $this->get_taxonomy_tag_label( $tax, $taxonomy_label_counts[ $tax->label ] > 1 ),
				'group'  => 'terms',
				'render' => 'post_terms',
			];
		}

		uasort(
			$taxonomy_tags,
			function( $first, $second ) {
				return strnatcasecmp( $first['label'], $second['label'] );
			}
		);

		$tags = array_merge( $tags, $taxonomy_tags );

		// Echo
		if ( Helpers::code_execution_enabled() ) {
			$tags['echo'] = [
				'label' => esc_html__( 'Output PHP function', 'bricks' ),
				'group' => 'advanced',
			];
		}

		/**
		 * Do action
		 *
		 * @see https://academy.bricksbuilder.io/article/dynamic-data/#advanced
		 *
		 * @since 1.7
		 */
		$tags['do_action'] = [
			'label' => 'do_action',
			'group' => 'advanced',
		];

		// WordPress default custom fields
		$metas = $this->get_post_meta_keys();

		foreach ( $metas as $key ) {
			$label                = ucwords( str_replace( '_', ' ', $key ) );
			$tags[ 'cf_' . $key ] = [
				'label'  => $label,
				'group'  => 'customFields',
				'render' => 'post_metas',
			];
		}

		return $tags;
	}

	/**
	 * Add the related object type to duplicate taxonomy labels.
	 *
	 * Integrations can register one taxonomy per object type with the same generic
	 * label. Without the context, those tags are indistinguishable in the picker.
	 *
	 * @param object $taxonomy      Taxonomy object.
	 * @param bool   $has_duplicate Whether another taxonomy uses the same label.
	 * @return string
	 */
	protected function get_taxonomy_tag_label( $taxonomy, $has_duplicate ) {
		if ( ! $has_duplicate ) {
			return $taxonomy->label;
		}

		$object_type_labels = [];

		foreach ( (array) $taxonomy->object_type as $object_type ) {
			$post_type_object = get_post_type_object( $object_type );

			$object_type_labels[] = ! empty( $post_type_object->label )
				? $post_type_object->label
				: ucwords( str_replace( [ '_', '-' ], ' ', $object_type ) );
		}

		if ( ! $object_type_labels ) {
			return $taxonomy->label;
		}

		return sprintf( '%1$s (%2$s)', $taxonomy->label, implode( ', ', array_unique( $object_type_labels ) ) );
	}

	/**
	 * Returns a list of post meta keys (uses $post context)
	 *
	 * @return array
	 */
	public function get_post_meta_keys() {
		$list = [];

		/**
		 * Return: Is frontend
		 *
		 * Only retrieve WP custom fields (cf_) inside the builder.
		 * As get_site_meta_keys() can cause performance issues on large sites.
		 *
		 * @see #862k3f2md
		 * @since 1.8.3
		 */
		if ( ! bricks_is_builder() ) {
			return $list;
		}

		// Builder: Return empty array if user doesn't want to retrieve default WP custom fields
		if ( Database::get_setting( 'builderDisableWpCustomFields', false ) ) {
			return $list;
		}

		$meta_keys = $this->get_site_meta_keys();

		if ( empty( $meta_keys ) ) {
			return $list;
		}

		$exclude = [];

		// Exclude the ACF custom fields from the custom fields list
		if ( Provider_Acf::load_me() ) {
			$acf_meta_exclusions = $this->get_acf_meta_exclusions( Provider_Acf::get_fields() );
			$exclude             = array_merge( $exclude, $acf_meta_exclusions['names'] );
			$patterns            = $acf_meta_exclusions['patterns'];

			if ( ! empty( $patterns ) ) {
				foreach ( $patterns as $pattern ) {
					// Excludes meta keys based on the patterns
					$meta_keys = preg_grep( $pattern, $meta_keys, PREG_GREP_INVERT );
				}
			}

			unset( $patterns );
		}

		// Exclude the Pods custom fields from the custom fields list
		if ( Provider_Pods::load_me() ) {
			$pods_fields = Provider_Pods::get_fields();

			foreach ( $pods_fields as $field ) {
				$exclude[] = $field['name'];
			}
		}

		// Exclude the Meta Box custom fields from the custom fields list
		if ( Provider_Metabox::load_me() ) {
			$metabox_fields = Provider_Metabox::get_fields();

			foreach ( $metabox_fields as $field ) {
				$exclude[] = $field['id'];

				if ( $field['type'] == 'group' && ! empty( $field['fields'] ) ) {
					foreach ( $field['fields'] as $sub_field ) {
						$exclude[] = "{$field['id']}_{$sub_field['id']}";
					}
				}
			}
		}

		// Exclude the CMB2 custom fields from the custom fields list
		if ( Provider_Cmb2::load_me() ) {
			$cmb2_fields = Provider_Cmb2::get_fields();

			foreach ( $cmb2_fields as $field ) {
				$exclude[] = $field['name'];
			}
		}

		// Exclude the Toolset custom fields from the custom fields list
		if ( Provider_Toolset::load_me() ) {
			$toolset_fields = Provider_Toolset::get_fields();

			foreach ( $toolset_fields as $field ) {
				$exclude[] = $field['meta_key'];
			}
		}

		if ( Provider_Jetengine::load_me() ) {
			$jetengine_fields = Provider_Jetengine::get_fields();

			foreach ( $jetengine_fields as $field ) {
				$exclude[] = $field['name'];
			}
		}

		// ignore post meta keys that start with '_' (invisible)
		foreach ( $meta_keys as $key ) {
			if ( '_' !== substr( $key, 0, 1 ) && ! in_array( $key, $exclude ) ) {
				$list[] = $key;
			}
		}

		return $list;
	}

	/**
	 * Get ACF field names and repeater meta patterns excluded from WP custom fields.
	 *
	 * ACF leaves Group-display clones intact in its resolved field list, so their
	 * sub-fields must be traversed explicitly. Seamless clones are already flattened.
	 *
	 * @since 2.3.13 (#86c50h1ma)
	 *
	 * @param array $fields ACF fields.
	 * @return array
	 */
	private function get_acf_meta_exclusions( $fields ) {
		$names    = [];
		$patterns = [];

		foreach ( $fields as $field ) {
			if ( empty( $field['name'] ) || empty( $field['type'] ) ) {
				continue;
			}

			$names[] = $field['name'];

			// Note: for the sake of simplification the nested repeaters are excluded based on the parent prefix
			if ( $field['type'] === 'repeater' && ! empty( $field['sub_fields'] ) ) {
				foreach ( $field['sub_fields'] as $sub_field ) {
					$patterns[] = "/{$field['name']}_(\d+)_{$sub_field['name']}(.?)/";
				}
			}

			// ACF Flexible content: Follows repeater logic (@since 1.6.2)
			if ( $field['type'] === 'flexible_content' && ! empty( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						foreach ( $layout['sub_fields'] as $sub_field ) {
							$patterns[] = "/{$field['name']}_(\d+)_{$sub_field['name']}(.?)/";
						}
					}
				}
			}

			if ( $field['type'] === 'clone' && ( $field['display'] ?? '' ) === 'group' && ! empty( $field['sub_fields'] ) ) {
				$clone_exclusions = $this->get_acf_meta_exclusions( $field['sub_fields'] );
				$names            = array_merge( $names, $clone_exclusions['names'] );
				$patterns         = array_merge( $patterns, $clone_exclusions['patterns'] );
			}
		}

		return [
			'names'    => array_values( array_unique( $names ) ),
			'patterns' => array_values( array_unique( $patterns ) ),
		];
	}

	/**
	 * Main function to render the tag value for WordPress provider
	 *
	 * @param [type] $tag
	 * @param [type] $post
	 * @param [type] $args
	 * @param [type] $context
	 */
	public function get_tag_value( $tag, $post, $args, $context ) {
		$post_id = isset( $post->ID ) ? $post->ID : '';

		// STEP: Check for filter args
		$filters = $this->get_filters_from_args( $args );

		// STEP: Get the value
		$value = '';

		$render = isset( $this->tags[ $tag ]['render'] ) ? $this->tags[ $tag ]['render'] : $tag;

		// Always set render to post_metas for cf_xxx even if not registered in $this->tags (@since 1.9.8)
		if ( strpos( $tag, 'cf_' ) === 0 ) {
			$render = 'post_metas';
		}

		$uri = ! empty( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'] ) : '';
		if ( is_array( $uri ) && ! empty( $uri ) ) {
			foreach ( $uri as $key => $val ) {
				if ( $val && strpos( $val, 'echo:' ) !== false ) {
					unset( $filters['meta_key'] );
				}
			}
		}

		switch ( $render ) {
			// Post
			case 'post_id':
				$value = $post_id;
				break;

			case 'post_url':
				$value = get_permalink( $post_id );
				break;

			case 'post_slug':
				$value = $post->post_name ?? get_post_field( 'post_name', $post_id );
				break;

			case 'post_title':
				/**
				 * Use Bricks helper function to get the post title instead of using get_the_title()
				 *
				 * Same as for the Post title element.
				 *
				 * @since 1.10.2
				 */
				$value = Helpers::get_the_title( $post_id );
				break;

			case 'post_type':
				$value = $post->post_type ?? get_post_type( $post_id );
				break;

			case 'read_more':
				$value           = apply_filters( 'bricks/dynamic_data/read_more', __( 'Read more', 'bricks' ), $post );
				$filters['link'] = true;
				break;

			case 'post_date':
				$filters['object_type'] = 'date';
				$value                  = get_post_time( 'U', false, $post, false );
				break;

			case 'post_modified':
				$filters['object_type'] = 'date';
				$value                  = get_post_modified_time( 'U', false, $post, false );
				break;

			case 'post_time':
				$filters['object_type'] = 'date';
				$filters['meta_key']    = isset( $filters['meta_key'] ) ? $filters['meta_key'] : get_option( 'time_format' );
				$value                  = get_post_time( 'U', false, $post, false );
				break;

			case 'post_comments_count':
				$value = get_comments_number( $post );
				break;

			case 'post_comments':
				$comments_number = get_comments_number( $post );
				// translators: %s = the number of comments
				$value = sprintf( _nx( '%s comment', '%s comments', $comments_number, 'Translators: %s = the number of comments', 'bricks' ), $comments_number );
				break;

			case 'post_content':
				wp_enqueue_style( 'wp-block-library' );
				wp_enqueue_style( 'global-styles' );

				$value = $this->get_the_content( $post );

				// To prevent issues with embeded content (e.g. youtube videos)
				$filters['skip_sanitize'] = true;
				break;

			case 'post_excerpt':
				$num_words = ! empty( $filters['num_words'] ) ? $filters['num_words'] : 55;
				$keep_html = isset( $filters['format'] );
				$value     = '';

				// To prevent the content from being trimmed again in format_value_for_text()
				$filters['trimmed'] = true;

				// Inside a Query Loop
				if ( Query::is_looping() ) {
					$loop_object = Query::get_loop_object();

					if ( ! is_a( $loop_object, 'WP_Post' ) ) {
						// Not looping a WP_Post
						if ( $loop_object && ! empty( $loop_object->description ) ) {
							$value = Helpers::trim_words( $loop_object->description, $num_words, null, $keep_html );
						}

						break;
					}
				}

				// Not inside a Query Loop: Use taxonomy or author description
				// "Posts" element on archive page triggered here as well (@see #862j3v95v)
				elseif ( is_archive() ) {
					$queried_object = get_queried_object();

					// Get current element name
					$element_id   = Query::get_query_element_id();
					$element_data = Helpers::get_element_data( $post_id, $element_id );
					$element_name = $element_data['element']['name'] ?? '';

					// Use archive description if we are not in a "Posts" element (@since 1.7)
					if ( $queried_object && ! empty( $queried_object->description ) && $element_name !== 'posts' ) {
						$value = Helpers::trim_words( $queried_object->description, $num_words, null, $keep_html );

						break;
					}

					// For Posts element on archive page, continue to next block to use the post excerpt instead
				}

				// We are in a Query Loop and looping a WP_Post or we are in a single post
				$value = Helpers::get_the_excerpt( $post, $num_words, null, $keep_html );
				break;

			// Image
			case 'featured_image':
			case 'featured_image_tag':
				$filters['object_type'] = 'media';
				$filters['image']       = 'true';
				$value                  = get_post_thumbnail_id( $post_id );

				if ( Woocommerce::is_woocommerce_active() ) {
					// Cart item featured image inside woo cart loop (@since 1.8.5)
					$loop_object_type = Query::is_looping() ? Query::get_query_object_type() : false;
					$post_type        = get_post_type( $post );

					if ( $loop_object_type === 'wooCart' || in_array( $post_type, [ 'product', 'product_variation' ], true ) ) {
						if ( $loop_object_type === 'wooCart' ) {
							// Get the loop cart object
							$loop_object = Query::get_loop_object();
							$product     = isset( $loop_object['data'] ) && is_a( $loop_object['data'], 'WC_Product' ) ? $loop_object['data'] : wc_get_product( $post_id );
						} else {
							// Get product object (#86c3qf91q; @since 2.2)
							$product = wc_get_product( $post_id );
						}

						/**
						 * Similar like $product->get_image() method in WC_Product class
						 *
						 * Returns the parent product featured image if the current product has no featured image (e.g. variable product)
						 *
						 * @since 1.8.6
						 */
						if ( $product && is_a( $product, 'WC_Product' ) ) {
							if ( $product->get_image_id() ) {
								$value = $product->get_image_id();
							} elseif ( $product->get_parent_id() ) {
								$parent_product = wc_get_product( $product->get_parent_id() );
								if ( $parent_product ) {
									$value = $parent_product->get_image_id();
								}
							}
						}
					}

					// Order item product image inside Woo order loops.
					// Supports variation images with parent product fallback. (@since 2.4)
					if ( in_array( $loop_object_type, [ 'wooOrderItems' ], true ) ) {
						$loop_object = Query::get_loop_object();
						$item        = isset( $loop_object['item'] ) ? $loop_object['item'] : false;
						$product     = is_a( $item, 'WC_Order_Item_Product' ) ? $item->get_product() : false;

						if ( ! $product && ! empty( $loop_object['product_id'] ) ) {
							$product = wc_get_product( $loop_object['product_id'] );
						}

						if ( is_a( $product, 'WC_Product' ) ) {
							if ( $product->get_image_id() ) {
								$value = $product->get_image_id();
							} elseif ( $product->get_parent_id() ) {
								$parent_product = wc_get_product( $product->get_parent_id() );
								$value          = $parent_product ? $parent_product->get_image_id() : '';
							}
						}
					}

					/**
					 * Get WooCommerce placeholder image if featured image is empty (@since 1.5.1)
					 * Move to bottom so empty $value will be replaced by WooCommerce placeholder image (@since 1.8.6)
					 */
					if (
						empty( $value ) &&
						( in_array( $loop_object_type, [ 'wooOrderItems' ], true ) || in_array( $post_type, [ 'product', 'product_variation' ], true ) )
					) {
						$value = get_option( 'woocommerce_placeholder_image', 0 );
					}
				}
				break;

			// Author
			case 'author_id':
			case 'author_name':
			case 'author_bio':
			case 'author_email':
			case 'author_website':
			case 'author_avatar':
			case 'author_meta':
			case 'author_archive_url':
				/**
				 * Get user_id
				 *
				 * Get the author of current post inside query loop or a singular post.
				 *
				 * @since 1.7.1
				 */
				$user_id = is_singular() || ( Query::is_looping() && Query::get_loop_object_type() === 'post' ) ? $post->post_author : $post_id;

				/**
				 * $post_id might be empty in author archive page (when post ID 1 is removed, author ID 1 will be empty $post_id)
				 *
				 * @see $post_id render_content() inside providers.php
				 *
				 * Get the author of the queried object if we are on an author archive page.
				 *
				 * @since 1.8.2
				 */
				if ( is_author() && ! Query::is_any_looping() ) {
					$user_id = get_queried_object_id();
				}

				/**
				 * Get the correct user ID inside a User query loop
				 *
				 * @since 1.9.8
				 */
				if ( Query::is_looping() && Query::get_loop_object_type() === 'user' ) {
					$user_id = Query::get_loop_object_id();
				}

				/**
				 * Frontend: Preview template author & not looping: Get template preview author
				 *
				 * @since 1.8.2
				 */
				if ( Helpers::is_bricks_template( $post_id ) && ! Query::is_looping() ) {
					$template_preview_author = Helpers::get_template_setting( 'templatePreviewAuthor', $post_id );
					$user_id                 = $template_preview_author ? $template_preview_author : $user_id;
				}

				$user = get_user_by( 'id', $user_id );

				/**
				 * Builder OR AJAX call (infinite scroll)
				 *
				 * @since 1.9.1
				 */
				if ( ! $user && $user_id == $post_id ) {
					// Preview template & not looping: Get template preview author
					$template_preview_author = Helpers::get_template_setting( 'templatePreviewAuthor', $post_id );
					$user_id                 = $template_preview_author ? $template_preview_author : $user_id;

					if ( $template_preview_author ) {
						$user = get_user_by( 'id', $template_preview_author );
					}

					// Get user as is_singular() check above is not working ($wp_query is not populated)
					else {
						$user = $post ? get_user_by( 'id', $post->post_author ) : null;
					}
				}

				// Separate author tag value logic into separate function (@since 1.9.6)
				$value = $user ? $this->get_author_tag_value( $tag, $user, $filters, $context ) : '';

				break;

			// User Profile fields
			case 'wp_user_id':
			case 'wp_user_login':
			case 'wp_user_email':
			case 'wp_user_url':
			case 'wp_user_role':
			case 'wp_user_registered_date':
			case 'wp_user_nicename':
			case 'wp_user_nickname':
			case 'wp_user_description':
			case 'wp_user_first_name':
			case 'wp_user_last_name':
			case 'wp_user_display_name':
			case 'wp_user_picture':
			case 'wp_user_meta':
			case 'wp_user_author_url':
				$is_any_looping = Query::is_any_looping(); // Fix incorrect user context in nested loops (@since 1.12)
				$user           = Query::get_loop_object_type( $is_any_looping ) == 'user' ? Query::get_loop_object( $is_any_looping ) : wp_get_current_user();

				/**
				 * AJAX popup user context when using the user dynamic data,
				 * Should use the context user instead of the current user.
				 *
				 * @since 1.9.4
				 */
				if ( is_author() && ! $is_any_looping && Api::is_current_endpoint( 'load_popup_content' ) ) {
					$user = get_user_by( 'id', get_queried_object_id() );
				}

				// Set object type to date if render is wp_user_registered_date (@since 1.12)
				if ( $render === 'wp_user_registered_date' ) {
					$filters['object_type'] = 'date';
				}

				$value = $this->get_user_tag_value( $tag, $user, $filters, $context );
				break;

			// Site
			case 'site_title':
				$value = get_bloginfo( 'name', 'display' );
				break;

			case 'site_tagline':
				$value = get_bloginfo( 'description', 'display' );
				break;

			case 'site_url':
				$value = get_bloginfo( 'url', 'display' );
				break;

			case 'site_login':
				$redirect_to = '';

				// Redirect to post_id: {site_login:5}
				if ( ! empty( $filters['num_words'] ) ) {
					$redirect_to = get_the_permalink( $filters['num_words'] );
					// Prevent the content from being trimmed in format_value_for_text() @since 1.9.2
					$filters['trimmed'] = true;
				}

				$value = wp_login_url( $redirect_to );
				break;

			case 'site_logout':
				$redirect_to = '';

				// Redirect to post_id: {site_logout:5}
				if ( ! empty( $filters['num_words'] ) ) {
					$redirect_to = get_the_permalink( $filters['num_words'] );
					// Prevent the content from being trimmed in format_value_for_text() @since 1.9.2
					$filters['trimmed'] = true;
				}

				$value = wp_logout_url( $redirect_to );
				break;

			case 'url_parameter':
				$parameter = $filters['meta_key'] ?? false;
				$value     = $parameter && isset( $_GET[ $parameter ] ) ? $_GET[ $parameter ] : '';

				// Parse URL to get query parameters if not found in $_GET (@since 1.7.1)
				$url_components = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( $_SERVER['REQUEST_URI'] ) : '';

				if ( ! $value && ! empty( $url_components['query'] ) ) {
					parse_str( $url_components['query'], $parameters );
					$value = isset( $parameters[ $parameter ] ) ? $parameters[ $parameter ] : '';
				}

				// AJAX requests do not include the original page query string. (#86c21d4m0; @since 2.3.8)
				if (
					! $value &&
					! empty( Api::$request_data['urlParams'] ) &&
					is_array( Api::$request_data['urlParams'] )
				) {
					$url_params = Api::$request_data['urlParams'];
					$value      = isset( $url_params[ $parameter ] ) ? $url_params[ $parameter ] : '';
				}

				// Form submission: Support DD tag 'url_parameter' (@since 1.11)
				if ( ! $value && \Bricks\Ajax::is_current_endpoint( 'form_submit' ) ) {
					$value = \Bricks\Integrations\Form\Init::get_submission_url_param( $parameter );
				}

				// Support array value (@since 1.9.2)
				if ( ! empty( $value ) ) {
					if ( is_array( $value ) ) {
						$value = array_map( 'esc_attr', $value );
						$value = implode( ',', $value );
					} else {
						$value = esc_attr( $value );
					}
				}
				break;

			case 'current_date':
				$filters['object_type'] = 'date';
				$value                  = time();
				break;

			case 'current_wp_date':
				$filters['object_type'] = 'date';
				$value                  = current_time( 'timestamp' );
				break;

			// Date format conversion (@since 2.2)
			case 'format_date':
				$date        = isset( $filters['date'] ) ? $filters['date'] : '';
				$from_format = isset( $filters['from'] ) ? $filters['from'] : '';
				$to_format   = isset( $filters['to'] ) ? $filters['to'] : '';
				if ( ! empty( $date ) && ! empty( $from_format ) && ! empty( $to_format ) ) {
					// Resolve nested dynamic date values before DateTime conversion (#86cagh1f8).
					if ( is_string( $date ) && strpos( $date, '{' ) !== false ) {
						$date = \Bricks\Integrations\Dynamic_Data\Providers::render_content( $date, $post_id, 'text' );
					}

					// Handle microseconds with more than 6 digits (nanoseconds) (#86c8dxx4g; @since 2.3)
					if ( strpos( $from_format, '.u' ) !== false && is_string( $date ) ) {
						// Find the fractional seconds part in the date string using regex.
						// Match decimal point followed by digits, stop before timezone markers or whitespace
						// Handles: .123456789Z, .123456789+02:00, .123456789-05:00, .123456789 (space/end)
						if ( preg_match( '/\.(\d+)(?=\s|[Z\+\-]|$)/', $date, $matches ) ) {
							$fractional_seconds = $matches[1];

							// Only truncate if more than 6 digits
							if ( strlen( $fractional_seconds ) > 6 ) {
								$truncated = substr( $fractional_seconds, 0, 6 );
								// Replace the original fractional seconds in the date string with the truncated version
								$date = preg_replace( '/\.(\d+)(?=\s|[Z\+\-]|$)/', '.' . $truncated, $date, 1 );
							}
						}
					}

					$formatted_date = \DateTime::createFromFormat( $from_format, $date );

					// Prevent error if date is not valid due to unexpected issue
					if ( $formatted_date instanceof \DateTime ) {
						$value                  = $formatted_date->format( 'U' );
						$filters['object_type'] = 'date';
						$filters['meta_key']    = $to_format;
					} else {
						// If date is not valid, return the original value (#86c8dxx4g; @since 2.3)
						$value = $date;
					}
				} elseif ( ! empty( $date ) ) {
					$value = $date;
				}

				break;

			// Terms
			case 'terms':
				$value = $this->get_term_tag_value( $tag, $filters, $context, $post_id );

				if ( ! empty( $filters['link'] ) ) {
					if ( in_array( $tag, [ 'term_parent', 'term_children' ], true ) ) {
						$value = $this->get_related_term_links( $value );

						unset( $filters['link'] );
					} else {
						$object_type = Query::get_loop_object_type();

						if ( $object_type === 'term' ) {
							$filters['object_type'] = $object_type;
							$filters['object']      = Query::get_loop_object();
						}
					}
				}

				break;

			// Archive
			case 'archive_title':
				if ( empty( $filters['add_context'] ) ) {
					add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
				}

				// TODO: Not properly populated in the builder (@use Helpers::get_the_title() logic)
				$value = get_the_archive_title();

				if ( empty( $filters['add_context'] ) ) {
					remove_filter( 'get_the_archive_title_prefix', '__return_empty_string' );
				}
				break;

			case 'archive_description':
				// TODO: Not properly populated in the builder (@use Helpers::get_the_title() logic)
				$value = get_the_archive_description();
				break;

			case 'post_terms':
				$value = $this->get_post_terms_value( $tag, $post, $filters, $context );
				break;

			case 'post_metas':
				$meta_key = substr( $tag, 3 );

				$value = get_post_meta( $post_id, $meta_key, true );

				// NOTE: Undocumented
				$value = apply_filters( "bricks/dynamic_data/meta_value/$meta_key", $value, $post );

				// cf_xxx support array_value filter (@since 1.9.8)
				if ( isset( $filters['array_value'] ) && is_array( $value ) ) {
					// Force context to text
					$context = 'text';
					$value   = $this->return_array_value( $value, $filters );
				}
				break;

			case 'echo':
				$value = $this->get_echo_callback_value( $filters, $context, $post );
				// @since 1.8 - New array_value filter.
				if ( isset( $filters['array_value'] ) && is_array( $value ) ) {
					// Force context to text
					$context = 'text';
					$value   = $this->return_array_value( $value, $filters );
				}
				break;

			case 'do_action':
				// Render do_action only on the frontend to avoid HTML node parsing issues & easier to locate do_action in the builder
				if ( ! bricks_is_builder() && ! bricks_is_builder_call() ) {
					$filters['skip_sanitize'] = true;
					$value                    = $this->get_do_action_callback_value( $filters, $context, $post );
				}
				break;

			// Query loop index (@since 2.1)
			case 'query_loop_index':
				// Get the current index inside a query loop
				$query_id         = Query::is_any_looping();
				$query_loop_index = Query::get_loop_index( $query_id );
				$start_at         = isset( $filters['start-at'] ) && is_numeric( $filters['start-at'] ) ? intval( $filters['start-at'] ) : 0;
				$pad              = isset( $filters['pad'] ) && is_numeric( $filters['pad'] ) ? intval( $filters['pad'] ) : 0;

				$value = intval( $query_loop_index ) + $start_at;

				// Add leading 0's padding
				if ( $pad !== 0 ) {
					$value = str_pad( $value, $pad, '0', STR_PAD_LEFT );
				}
				break;

			case 'query_results_count':
			case 'query_results_count_filter':
				// Get the results count from query_history, not supporting nested queries (@since 1.9.1)
				$query_object = false;

				// is_numeric element ID might be treated as num_words, check get_filters_from_args() (@since 1.10)
				$element_id = $filters['meta_key'] ?? $filters['num_words'] ?? false;

				// Resolve component-local query IDs before reading query history, so
				// duplicate instances get their own count (#86c4yu6kr; @since 2.3.8)
				$context_element = \Bricks\Integrations\Dynamic_Data\Providers::get_current_render_context( 'element' );
				$element_id      = Helpers::resolve_query_element_id_for_context( $element_id, $post_id, $context_element );

				// Get the query object from history or init, move logic to helper function (@since 1.12.2)
				$query_object = Helpers::get_query_object_from_history_or_init( $element_id, $post_id );

				if ( is_a( $query_object, 'Bricks\Query' ) ) {
					$value = $query_object->count;
				} else {
					$value = 0;
				}

				/**
				 * {query_results_count_filter} - wrap the value with a span for AJAX update when using query filter feature
				 * element ID is a must so we know which count to update after AJAX
				 * Use the resolved component instance ID as the update key (#86c4yu6kr; @since 2.3.8)
				 *
				 * @since 1.9.6
				 */
				if ( $tag === 'query_results_count_filter' && $element_id ) {
					$filters['skip_sanitize'] = true;
					$value                    = sprintf(
						'<span data-brx-qr-count="%s">%s</span>',
						esc_attr( $element_id ),
						intval( $value )
					);
				}

				break;

			// @since 1.9.6
			case 'search_term':
			case 'search_term_filter':
				// Get the search term from get_query_var() if not found in $_GET
				$search_term = isset( $_GET['s'] ) ? $_GET['s'] : get_query_var( 's' );
				$value       = sanitize_text_field( $search_term );

				$search_query_id = isset( $filters['meta_key'] ) ? $filters['meta_key'] : false;
				if ( $search_query_id ) {
					// Get the search term from query_history or init
					$search_query = Helpers::get_query_object_from_history_or_init( $search_query_id, true );
					if ( $search_query && ! empty( $search_query->query_vars['s'] ) ) {
						$value = sanitize_text_field( $search_query->query_vars['s'] );
					}

					// {search_term_filter} - wrap the value with a span for AJAX update when using query filter feature
					if ( $tag === 'search_term_filter' ) {
						$filters['skip_sanitize'] = true;
						$value                    = '<span data-brx-ls-term="' . $search_query_id . '">' . $value . '</span>';
					}
				}

				break;

			// Active filters count (@since 2.0)
			case 'active_filters_count':
				$target_query_id = isset( $filters['meta_key'] ) ? $filters['meta_key'] : false;

				if ( $target_query_id && Helpers::enabled_query_filters() ) {
					// Save the dd for AJAX update later (without curly braces) and add :value filter
					$dynamic_tag       = "active_filters_count:{$target_query_id}:value";
					$additional_params = [];

					// Add additional params
					if ( isset( $filters['exclude'] ) ) {
						$exclude_filters_dd                   = (string) $filters['exclude'];
						$dynamic_tag                         .= " @exclude:'{$exclude_filters_dd}'";
						$exclude_filters                      = explode( ',', $exclude_filters_dd );
						$exclude_filters                      = array_map( 'trim', $exclude_filters );
						$additional_params['exclude_filters'] = $exclude_filters;
					}

					$count = \Bricks\Query_Filters::get_active_filters_count( $target_query_id, $additional_params );

					if ( ! isset( $filters['value'] ) ) {
						// wrap the value with a span for AJAX update when using query filter feature
						$dynamic_tag              = esc_attr( $dynamic_tag );
						$filters['skip_sanitize'] = true;
						$value                    = "<span data-brx-af-count='{$target_query_id}' data-brx-af-dd='{$dynamic_tag}'>{$count}</span>";
					} else {
						// Return the count value only
						$value = $count;
					}
				}
				break;

			// @since 2.1
			case 'query_api':
			case 'query_array':
				// Get the loop object from Query
				$is_any_looping = Query::is_any_looping();
				$loop_object    = Query::get_loop_object( $is_any_looping );

				// If the loop object is not set, return empty string
				if ( ! $loop_object ) {
					return '';
				}

				// Check the "key" filter, maybe it is zero '0'
				$data_key = $filters['key'] ?? '';

				if ( $data_key !== '' ) {
					// data_key might have pipe sign = get data from nested object
					// e.g. {query_api @key:'title|rendered'}
					if ( strpos( $data_key, '|' ) !== false ) {
						// Split the data_key by pipe sign
						$data_keys = explode( '|', $data_key );
						$value     = $loop_object;

						foreach ( $data_keys as $key ) {
							if ( is_array( $value ) && isset( $value[ $key ] ) ) {
								$value = $value[ $key ];
							} elseif ( is_object( $value ) && isset( $value->$key ) ) {
								$value = $value->$key;
							} else {
								$value = '';
								break;
							}
						}
					} elseif ( is_array( $loop_object ) && isset( $loop_object[ $data_key ] ) ) {
						// Get the value from the loop object array
						$value = $loop_object[ $data_key ];
					} elseif ( is_object( $loop_object ) && isset( $loop_object->$data_key ) ) {
						// Get the value from the loop object object
						$value = $loop_object->$data_key;
					} else {
						// Return error if data_key not found
						$value = '';
					}
				} else {

					if ( $render === 'query_array' ) {
						// Return the whole loop object as array (serialized or json)
						$value = is_array( $loop_object ) ? json_encode( $loop_object ) : maybe_serialize( $loop_object );
						break;
					}
					// Return the whole loop object as JSON
					$value = '';
				}

				break;
		}

		// STEP: Apply context (text, link, image, media)
		$value = $this->format_value_for_context( $value, $tag, $post_id, $filters, $context );

		// Request values are data, including when the completed element HTML is parsed again. (@since 2.4.4)
		if ( in_array( $tag, [ 'url_parameter', 'search_term', 'search_term_filter' ], true ) ) {
			$value = $this->escape_request_value( $value );
		}

		return $value;
	}

	/**
	 * Keep request text literal across subsequent dynamic-data render passes.
	 *
	 * @param mixed $value Formatted request value, including image/media arrays.
	 * @return mixed
	 * @since 2.4.4
	 */
	private function escape_request_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ $this, 'escape_request_value' ], $value );
		}

		return is_string( $value ) ? str_replace( [ '{', '}' ], [ '&#123;', '&#125;' ], $value ) : $value;
	}

	/**
	 * Perform the do_action() callback and return the value
	 *
	 * Example: {do_action:woocommerce_before_single_product}
	 *
	 * NOTE: It's not supported to pass arguments to the action as different actions can have different arguments.
	 *
	 * @since 1.7
	 */
	public function get_do_action_callback_value( $filters, $context, $post ) {
		if ( empty( $filters['meta_key'] ) ) {
			return '';
		}

		// Sanitize the action name
		$requested_action = sanitize_text_field( $filters['meta_key'] );

		/**
		 * Resolve the action and its arguments before executing {do_action}.
		 *
		 * @since 2.4
		 */
		$action_context = apply_filters(
			'bricks/dynamic_data/do_action_context',
			[
				'action'           => $requested_action,
				'args'             => [],
				'requested_action' => $requested_action,
				'skip'             => false,
			],
			$filters,
			$context,
			$post
		);

		if ( ! is_array( $action_context ) ) {
			$action_context = [
				'action' => $requested_action,
				'args'   => [],
				'skip'   => false,
			];
		}

		if ( ! empty( $action_context['skip'] ) ) {
			return '';
		}

		$action = isset( $action_context['action'] ) ? sanitize_text_field( $action_context['action'] ) : $requested_action;
		$args   = isset( $action_context['args'] ) && is_array( $action_context['args'] ) ? array_values( $action_context['args'] ) : [];

		// Check if action exists
		if ( ! has_action( $action ) ) {
			return '';
		}

		// Get the value
		$value    = '';
		$ob_level = ob_get_level();

		// NOTE: Undocumented. Currently used by Bricks\Woocommerce
		do_action( 'bricks/dynamic_data/before_do_action', $action, $filters, $context, $post );

		ob_start();

		try {
			if ( $args ) {
				do_action_ref_array( $action, $args );
			} else {
				// Match WordPress's empty-string fallback for callbacks requiring one argument.
				do_action( $action );
			}
			$value = ob_get_clean();
		} finally {
			if ( ob_get_level() > $ob_level ) {
				$value = ob_get_clean();
			}

			// NOTE: Undocumented. Currently used by Bricks\Woocommerce
			do_action( 'bricks/dynamic_data/after_do_action', $action, $filters, $context, $post, $value );
		}

		return $value;
	}

	public function get_echo_callback_value( $filters, $context, $post ) {
		if ( empty( $filters['meta_key'] ) ) {
			return '';
		}

		$callback        = $filters['meta_key'];
		$args            = [];
		$chunk           = '';
		$quoted_argument = false;

		// STEP: There are arguments to be parsed
		if ( strpos( $callback, '(' ) !== false ) {
			// Pattern: matches1 captures the function name, matches2 captures everything inside the parentheses (@since 1.12)
			$pattern = '/^([a-zA-Z0-9_]+)\((.*)\)$/';

			// Trim the callback to allow dynamic tags with space like {echo: my_function} (@since 1.12.2)
			$callback = trim( $callback );

			// Retrieve the callback and arguments
			preg_match( $pattern, $callback, $matches );

			// STEP: Callback
			$callback = $matches[1] ?? '';

			if ( empty( $callback ) ) {
				return '';
			}

			// STEP: Arguments
			$args_string = $matches[2] ?? '';

			// Parse arguments, e.g.: 'arg1,still arg1', 'arg2', arg3
			if ( $args_string !== '' ) {
				$in_quote = false;
				$chars    = str_split( $args_string );
				$length   = count( $chars );

				foreach ( $chars as $index => $char ) {
					// Skip spaces outside of a single quote (@since 1.9.1)
					if ( ! $in_quote && $char == ' ' ) {
						continue;
					}

					// Bump into a single quote
					if ( $char == '\'' ) {
						if ( $in_quote ) {
							$next_non_space = '';

							for ( $lookahead = $index + 1; $lookahead < $length; $lookahead++ ) {
								if ( $chars[ $lookahead ] == ' ' ) {
									continue;
								}

								$next_non_space = $chars[ $lookahead ];
								break;
							}

							// Treat apostrophes inside words as literal characters, not quote delimiters (for example: pear's) (#86c0c8ynd; @since 2.4).
							if ( $next_non_space !== '' && $next_non_space !== ',' ) {
								$chunk .= $char;
								continue;
							}

							$in_quote        = false;
							$quoted_argument = true;
							continue;
						}

						if ( $chunk === '' ) {
							$in_quote = true;
							continue;
						}

						$chunk .= $char;
						continue;
					}

					// Bump into comma, outside of a single quote
					if ( ! $in_quote && $char == ',' ) {
						// Explicit quotes preserve empty positional arguments; bare empty arguments stay omitted.
						if ( $chunk !== '' || $quoted_argument ) {
							$args[] = $chunk;

							$chunk = '';
						}
						$quoted_argument = false;

						continue;
					}

					// Still here, add char to the chunk
					$chunk .= $char;
				}
			}

			// We still have one argument: Add chunk as argument
			if ( $chunk !== '' || $quoted_argument ) {
				$args[] = $chunk;
			}

		} else {
			$callback = trim( $callback );
		}

		// Check if function name is allowed
		$whitelisted_function_names = apply_filters( 'bricks/code/echo_function_names', $callback );
		$is_function_allowed        = false;

		/**
		 * Boolean returned
		 *
		 * Allow all functions if true is returned.
		 *
		 * @since 1.9.8
		 */
		if ( is_bool( $whitelisted_function_names ) ) {
			$is_function_allowed = $whitelisted_function_names === true;
		}

		/**
		 * Array returned
		 *
		 * Check if the function name is in the array.
		 *
		 * 1. Literal function name comparison
		 * 2: Through regex pattern (@since 1.9.8)
		 */
		elseif ( is_array( $whitelisted_function_names ) && ! empty( $whitelisted_function_names ) ) {
			foreach ( $whitelisted_function_names as $item ) {
				// It's a pattern
				if ( strpos( $item, '@' ) === 0 ) {
					// Remove the '#' and check if the function name matches the pattern
					$pattern = substr( $item, 1 );

					if ( preg_match( '/' . $pattern . '/', $callback ) ) {
						$is_function_allowed = true;
						break;
					}
				} elseif ( $callback === $item ) {
					// It's a direct match
					$is_function_allowed = true;
					break;
				}
			}
		}

		// Return: Not allowed to call this function name
		if ( ! $is_function_allowed ) {
			return '';
		}

		/**
		 * Return: Don't run echo tags from unauthorized users in the builder preview to prevent them from adding new echo function calls (even if they are whitelisted)
		 *
		 * @since 1.12.2
		 */
		if ( bricks_is_builder_call() && ! \Bricks\Capabilities::current_user_can_execute_code() ) {
			return '';
		}

		// The balanced tag extractor keeps nested tags inside the outer echo match.
		// Resolve parsed arguments after capability checks and before invocation (#86caukb8a).
		$post_id = isset( $post->ID ) ? $post->ID : 0;

		// Execute the callback + args
		try {
			foreach ( $args as $key => $arg ) {
				if ( is_string( $arg ) && strpos( $arg, '{' ) !== false && strpos( $arg, '}' ) !== false ) {
					$args[ $key ] = \Bricks\Integrations\Dynamic_Data\Providers::render_content( $arg, $post_id, 'text' );
				}
			}

			// Restore protected literals only after parsing and nested-tag rendering (@since 2.4.3).
			$callback_args = apply_filters( 'bricks/dynamic_data/echo_callback_args', $args, $callback );
			$value         = function_exists( $callback ) ? call_user_func_array( $callback, $callback_args ) : '';

			// Literal-bearing results must remain protected from the outer parser's reruns (@since 2.4.3).
			return apply_filters( 'bricks/dynamic_data/echo_callback_result', $value, $callback, $args );
		}

		// Error handling (@since 1.5.3)
		catch ( \Exception $error ) {
			error_log( 'Exception: ' . print_r( $error->getMessage(), true ) );
		} catch ( \ParseError $error ) {
			error_log( 'ParseError: ' . print_r( $error->getMessage(), true ) );
		} catch ( \Error $error ) {
			error_log( 'Error: ' . print_r( $error->getMessage(), true ) );
		}

		return '';
	}

	/**
	 * Helper function to get the post content
	 *
	 * @param [type] $post
	 */
	public function get_the_content( $post ) {
		$post = get_post( $post );

		if ( empty( $post ) ) {
			return '';
		}

		// Keep {post_content} aligned with WordPress preview/autosave output
		// (#86c9wy4u5; @since 2.3.6).
		$post_id = $post instanceof \WP_Post ? $post->ID : $post;
		$post    = Helpers::get_post_preserving_preview( $post_id, $post );

		// Remove the More block only for the SEO analysis target post; do not force global $more for loop posts. (#86c9r8aba; @since 2.3.6)
		if ( intval( apply_filters( 'bricks/content_analysis/full_post_content_id', 0 ) ) === intval( $post_id ) ) {
			$post = Helpers::get_post_without_more_block( $post );
		}

		$content = get_the_content( null, false, $post );
		$content = apply_filters( 'the_content', $content );

		return $content;
	}

	/**
	 * Render user related data
	 *
	 * @param [type]  $tag
	 * @param WP_User $user
	 * @param [type]  $filters
	 * @param [type]  $context
	 */
	public function get_user_tag_value( $tag, $user, $filters, $context ) {
		if ( empty( $user->ID ) ) {
			return '';
		}

		$value = '';

		$field_type = str_replace( [ 'wp_user_' ], '', $tag );

		switch ( $field_type ) {

			case 'id':
				$value = $user->ID;
				break;

			case 'login':
			case 'email':
			case 'url':
			case 'website':
			case 'nicename':
				$field_type = 'website' == $field_type ? 'url' : $field_type; // Legacy
				$field      = 'user_' . $field_type;
				$value      = isset( $user->{$field} ) ? $user->{$field} : '';
				break;

			case 'bio':
			case 'description':
			case 'first_name':
			case 'last_name':
			case 'display_name':
				$field_type = 'bio' == $field_type ? 'description' : $field_type; // Legacy
				$value      = isset( $user->{$field_type} ) ? $user->{$field_type} : '';
				break;

			case 'name':
				if ( ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
					$value = trim( $user->first_name . ' ' . $user->last_name );
				} else {
					$value = trim( $user->display_name );
				}
				break;

			case 'picture':
			case 'avatar':
				// If context = image, increase the default image size to 512px
				$default_size = $context === 'image' ? 512 : 96;

				$size = empty( $filters['num_words'] ) ? $default_size : $filters['num_words'];

				// translators: %s = the author name
				$alt = sprintf( esc_html__( 'Avatar image of %s', 'bricks' ), get_the_author_meta( 'display_name', $user->ID ) );

				$value = $context === 'link' || $context === 'image' ? get_avatar_url( $user->ID, [ 'size' => $size ] ) : get_avatar( $user->ID, $size, '', $alt );
				break;

			case 'nickname':
				$value = get_user_meta( $user->ID, 'nickname', true );
				break;

			case 'meta':
				if ( ! empty( $filters['meta_key'] ) ) {
					$value = get_user_meta( $user->ID, $filters['meta_key'], true );
				}
				break;

			case 'author_url':
				$value = get_author_posts_url( $user->ID );
				break;

			// @since 1.12
			case 'role':
				// Always use the primary role
				$value = $user->roles[0] ?? '';

				if ( ! isset( $filters['value'] ) && $value !== '' ) {
					// Get the role name
					global $wp_roles;

					$value = $wp_roles->roles[ $value ]['name'] ?? '';
				}
				break;

			case 'registered_date':
				$registered = $user->user_registered;
				// Create a DateTime object from the user_registered date
				$registered_date = new \DateTime( $registered );

				$value = $registered_date->format( 'U' );
				break;
		}

		// NOTE: Undocumented
		$value = apply_filters( 'bricks/dynamic_data/user_value', $value, $field_type, $filters );

		return $value;
	}

	/**
	 * Render author related meta data
	 *
	 * Example: Author ID, which isn't available in get_user_meta()
	 *
	 * @param [type]  $tag
	 * @param WP_User $user
	 * @param [type]  $filters
	 * @param [type]  $context
	 *
	 * @since 1.9.6
	 */
	public function get_author_tag_value( $tag, $user, $filters, $context ) {
		if ( empty( $user->ID ) ) {
			return '';
		}

		$value      = '';
		$field_type = str_replace( [ 'author_' ], '', $tag );

		switch ( $field_type ) {
			case 'id':
				$value = $user->ID;
				break;

			// {author_name} remains same logic for backward compatibility
			case 'name':
				if ( ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
					$value = trim( $user->first_name . ' ' . $user->last_name );
				} else {
					$value = trim( $user->display_name );
				}
				break;

			// {author_avatar} remains same logic for backward compatibility
			case 'avatar':
				// If context = image, increase the default image size to 512px
				$default_size = $context === 'image' ? 512 : 96;

				$size = empty( $filters['num_words'] ) ? $default_size : $filters['num_words'];

				// translators: %s = the author name
				$alt = sprintf( esc_html__( 'Avatar image of %s', 'bricks' ), get_the_author_meta( 'display_name', $user->ID ) );

				$value = $context === 'link' || $context === 'image' ? get_avatar_url( $user->ID, [ 'size' => $size ] ) : get_avatar( $user->ID, $size, '', $alt );
				break;

			case 'archive_url':
				$value = get_author_posts_url( $user->ID );
				break;

			case 'bio':
			case 'email':
			case 'meta':
			case 'website':
				$key = $field_type;

				// {author_bio} set key to description
				if ( $field_type === 'bio' ) {
					$key = 'description';
				}

				// {author_website} set key to url
				if ( $field_type === 'website' ) {
					$key = 'url';
				}

				if ( ! empty( $filters['meta_key'] ) ) {
					$key = $filters['meta_key'];
				}

				// Check the final field, including WordPress aliases, before any credential lookup. (@since 2.4.4)
				if ( in_array( strtolower( $key ), [ 'pass', 'user_pass', 'activation_key', 'user_activation_key', 'session_tokens', '_application_passwords' ], true ) ) {
					return '';
				}

				$value = get_the_author_meta( $key, $user->ID );
				break;
		}

		// NOTE: Undocumented
		$value = apply_filters( 'bricks/dynamic_data/author_value', $value, $field_type, $filters );

		return $value;
	}

	/**
	 * Render Post Terms value
	 *
	 * @param [type] $tag
	 * @param [type] $post
	 * @param [type] $filters
	 * @param [type] $context
	 */
	public function get_post_terms_value( $tag, $post, $filters, $context ) {
		if ( ! isset( $post->ID ) ) {
			return '';
		}

		$taxonomy = str_replace( 'post_terms_', '', $tag );

		$terms = wp_get_post_terms( $post->ID, $taxonomy );

		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}

		// https://academy.bricksbuilder.io/article/filter-bricks-dynamic_data-post_terms_links/
		$has_links = apply_filters( 'bricks/dynamic_data/post_terms_links', true, $post, $taxonomy );

		$output = [];

		// We store term field (if it's in meta_key) and seaprator here. Default separator is ', '
		$term_field = null;
		$separator  = ', ';

		// Get term meta key (@since 2.0), could also be in separator:term_field format " > :term_id"
		$meta_key = $filters['meta_key'] ?? null;

		// List of allowed term fields that user can choose from (@since 2.0)
		$allowed_term_fields = [ 'term_id', 'slug' ];

		// Check if meta_key is exactly one of the allowed term fields (then we know we don't have separator)
		if ( ! empty( $meta_key ) && in_array( $meta_key, $allowed_term_fields ) ) {
			$term_field = $meta_key;
		}

		// Is separator:term_field format or only separator (@since 2.0)
		elseif ( ! empty( $meta_key ) ) {
			// Check if meta_key string contains one of the allowed term fields
			foreach ( $allowed_term_fields as $allowed_term_field ) {
				if ( strpos( $meta_key, $allowed_term_field ) !== false ) {
					$term_field = $allowed_term_field; // We found the term field

					// We found the term field, get separator by splitting on the last colon, and get everything before it
					if ( strpos( $meta_key, ':' ) !== false ) {
						$separator = substr( $meta_key, 0, strrpos( $meta_key, ':' ) );
					}
					break;
				}
			}

			// If we don't have term key, meta_key is separator
			if ( is_null( $term_field ) ) {
				$separator = $meta_key;
			}
		}

		foreach ( $terms as $term ) {
			$item = $term->name;

			// If we have term key, get term meta (@since 2.0)
			if ( ! is_null( $term_field ) ) {
				$item = get_term_field( $term_field, $term->term_id, $taxonomy );

				// If term meta is empty or error, continue loop
				if ( empty( $item ) || is_wp_error( $item ) || is_null( $item ) ) {
					continue;
				}
			}

			if ( $has_links ) {
				$url = get_term_link( $term );

				if ( ! empty( $url ) && ! is_wp_error( $url ) ) {
					$item = '<a href="' . esc_url( $url ) . '">' . $item . '</a>';
				}
			}

			$output[] = $item;
		}

		// https://academy.bricksbuilder.io/article/filter-bricks-dynamic_data-post_terms_separator/
		$separator = apply_filters( 'bricks/dynamic_data/post_terms_separator', $separator, $post, $taxonomy );

		return implode( $separator, $output );
	}

	public function get_term_tag_value( $tag, $filters, $context, $post_id ) {
		$looping_query_id = Query::is_any_looping();
		$loop_object_type = '';
		$looping_object   = null;
		$object           = null;

		if ( ! empty( $looping_query_id ) ) {
			$looping_object   = Query::get_loop_object( $looping_query_id );
			$object           = ! empty( $looping_object ) ? $looping_object : null;
			$loop_object_type = Query::get_loop_object_type( $looping_query_id );
		}

		// Is taxonomy archive (check again is_tax() not working inside Posts element in archive template)
		if ( ! $object ) {
			$object = get_queried_object();
		}

		/**
		 * DD term_xx is unable to parse correctly based on populate content
		 * History tasks: (#86bw6re4w, #86bx6wxxm)
		 */
		if ( Helpers::is_bricks_preview() && ! Query::is_looping() ) {
			$object = Helpers::get_queried_object( $post_id );

			/**
			 * Similar logic in render_content() in providers.php
			 * In the builder, something is looping, but currently rendering Query parameters (#86c2wzt6n)
			 *
			 * @since 2.0
			 */
			if ( $looping_query_id && $looping_object && $loop_object_type === 'term' ) {
				$object = $looping_object;
			}
		}

		// Not a WP_Term, leave
		if ( ! $object || ! is_a( $object, 'WP_Term' ) ) {
			return '';
		}

		$value = '';

		switch ( $tag ) {
			case 'term_id':
				$value = $object->term_id;
				break;

			case 'term_name':
				$value = $object->name;
				break;

			case 'term_taxonomy_slug':
				$value = $object->taxonomy;
				break;

			case 'term_slug':
				$value = $object->slug;
				break;

			case 'term_count':
				$value = $object->count;
				break;

			case 'term_description':
				$value = $object->description;
				break;

			case 'term_url':
				$value = get_term_link( $object );
				break;

			case 'term_parent':
				$parent = ! empty( $object->parent ) ? get_term( $object->parent, $object->taxonomy ) : null;
				$value  = $this->get_related_terms_value( [ $parent ], $filters );
				break;

			case 'term_children':
				$children = get_terms(
					[
						'taxonomy'   => $object->taxonomy,
						'parent'     => $object->term_id,
						'hide_empty' => false,
					]
				);

				$value = ! is_wp_error( $children ) ? $this->get_related_terms_value( $children, $filters ) : '';
				break;

			case 'term_meta':
				if ( ! empty( $filters['meta_key'] ) ) {
					$value = get_term_meta( $object->term_id, $filters['meta_key'], true );
				}

				break;
		}

		return $value;
	}

	/**
	 * Retrieve all registered tags which are supported in author include/exclude query controls.
	 * #86c75ahhj
	 *
	 * @since 2.4
	 */
	public function get_query_supported_tags() {
		$author_query_tags = [
			'wp_user_id',
			'author_id',
		];

		$supported_tags = [];

		foreach ( $this->tags as $key => $tag ) {
			if ( ! in_array( $key, $author_query_tags, true ) ) {
				continue;
			}

			if ( isset( $tag['deprecated'] ) ) {
				continue;
			}

			$supported_tags[] = [
				'name'     => $tag['name'],
				'type'     => 'user',
				'label'    => $tag['label'],
				'params'   => [ 'author__in', 'author__not_in' ],
				'provider' => $tag['provider'],
			];
		}

		return $supported_tags;
	}

	/**
	 * Get related term values for term parent and child dynamic tags.
	 *
	 * @since 2.4
	 *
	 * @param WP_Term[] $terms   Terms to format.
	 * @param array     $filters Dynamic data filters.
	 * @return array|string
	 */
	private function get_related_terms_value( $terms, $filters ) {
		$terms = array_filter(
			(array) $terms,
			function( $term ) {
				return is_a( $term, 'WP_Term' );
			}
		);

		if ( empty( $terms ) ) {
			return '';
		}

		if ( ! empty( $filters['link'] ) ) {
			return wp_list_pluck( $terms, 'term_id' );
		}

		if ( ! empty( $filters['value'] ) ) {
			return wp_list_pluck( $terms, 'term_id' );
		}

		if ( isset( $filters['meta_key'] ) && $filters['meta_key'] === 'slug' ) {
			return wp_list_pluck( $terms, 'slug' );
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get term anchor tags from term IDs.
	 *
	 * @since 2.4
	 *
	 * @param array|string $term_ids Term IDs.
	 * @return array|string
	 */
	private function get_related_term_links( $term_ids ) {
		$term_ids = (array) $term_ids;
		$links    = [];

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );

			if ( ! is_a( $term, 'WP_Term' ) ) {
				continue;
			}

			$url = get_term_link( $term );

			if ( empty( $url ) || is_wp_error( $url ) ) {
				continue;
			}

			$links[] = '<a href="' . esc_url( $url ) . '" rel="tag">' . esc_html( $term->name ) . '</a>';
		}

		return ! empty( $links ) ? $links : '';
	}

	/**
	 * Get all the the unique postmeta keys
	 *
	 * @since 1.5.1
	 *
	 * @return array
	 */
	private function get_site_meta_keys() {
		$cache_key = 'brx_all_meta_keys';

		$meta_keys = wp_cache_get( $cache_key, 'bricks' );

		if ( $meta_keys === false ) {
			global $wpdb;

			// Query DB for all the unique meta keys (@see https://developer.wordpress.org/reference/classes/wpdb/get_results/)
			$meta_keys_results = $wpdb->get_results( "SELECT DISTINCT($wpdb->postmeta.meta_key) FROM $wpdb->postmeta WHERE 1", OBJECT );

			if ( empty( $meta_keys_results ) || ! is_array( $meta_keys_results ) ) {
				return [];
			}

			$meta_keys = wp_list_pluck( $meta_keys_results, 'meta_key' );

			wp_cache_set( $cache_key, $meta_keys, 'bricks', 5 * MINUTE_IN_SECONDS );
		}

		return $meta_keys;
	}
}

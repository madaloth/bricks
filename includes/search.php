<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Search {
	public function __construct() {
		if ( Database::get_setting( 'searchResultsQueryBricksData', false ) ) {
			// Checking if query contains "s" var @since 1.5.7 (CU #3pxbtcp)
			add_filter( 'posts_join', [ $this, 'search_postmeta_table' ], 10, 2 );
			add_filter( 'posts_where', [ $this, 'modify_search_for_postmeta' ], 10, 2 );
			add_filter( 'posts_distinct', [ $this, 'search_distinct' ], 10, 2 );
		}
	}

	/**
	 * Helper: Check if is_search() OR Bricks infinite scroll REST API search results
	 *
	 * @since 1.5.7
	 */
	public function is_search( $query ) {
		// WordPress search results
		if ( is_search() ) {
			return true;
		}

		/**
		 * @since 1.10: Bricks: Infinite scroll & Query filter search results
		 * @since 1.11: 'brx_is_search' is set if there is a filter-search query applied (@see QueryFilters->build_search_query_vars())
		 */
		$is_bricks_search = Api::is_current_endpoint( 'load_query_page' ) || Api::is_current_endpoint( 'query_result' ) || $query->get( 'brx_is_search' ) === true;

		if ( $is_bricks_search && ! empty( $query->query_vars['s'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Search 'posts' and 'postmeta' tables
	 *
	 * https://adambalee.com/search-wordpress-by-custom-fields-without-a-plugin/
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_join
	 *
	 * @since 1.3.7
	 */
	public function search_postmeta_table( $join, $query ) {
		global $wpdb;

		if ( $this->is_search( $query ) ) {
			$join .= ' LEFT JOIN ' . $wpdb->postmeta . ' bricksdata ON ' . $wpdb->posts . '.ID = bricksdata.post_id ';
		}

		return $join;
	}

	/**
	 * Modify search query
	 *
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_where
	 *
	 * @since 1.3.7
	 */
	public function modify_search_for_postmeta( $where, $query ) {
		global $pagenow, $wpdb;

		if ( $this->is_search( $query ) ) {
			// Only search from Bricks data content
			$meta_key = $wpdb->prepare( '%s', BRICKS_DB_PAGE_CONTENT );
			$where    = preg_replace(
				'/\(\s*' . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
				'(' . $wpdb->posts . '.post_title LIKE $1) OR (bricksdata.meta_key =' . $meta_key . ' AND bricksdata.meta_value LIKE $1)',
				$where
			);
		}

		return $where;
	}

	/**
	 * Prevent duplicates
	 *
	 * http://codex.wordpress.org/Plugin_API/Filter_Reference/posts_distinct
	 *
	 * @since 1.3.7
	 */
	public function search_distinct( $where, $query ) {
		global $wpdb;

		if ( $this->is_search( $query ) ) {
			return 'DISTINCT';
		}

		return $where;
	}

	public static function search_template_has_custom_criteria( $template_id ) {
		$template_settings = Helpers::get_template_settings( $template_id );

		return isset( $template_settings['searchCriteriaCustom'] );
	}

	public static function get_search_template_criteria_post_ids( $template_id, $search_term ) {
		if ( ! self::search_template_has_custom_criteria( $template_id ) ) {
			return [];
		}

		$template_settings = Helpers::get_template_settings( $template_id );

		$use_weight_score   = $template_settings['useWeightScore'] ?? false;
		$search_post_fields = $template_settings['searchPostFields'] ?? false;
		$wp_fields          = $template_settings['searchPostQuery'] ?? [ 'default' ]; // default, title, content, excerpt multi-select
		$search_post_meta   = $template_settings['searchPostMeta'] ?? false;
		$meta_fields        = $template_settings['searchPostMetaKeys'] ?? []; // array of meta key

		$search_post_terms = $template_settings['searchPostTerms'] ?? false;
		$taxonomies        = $template_settings['searchPostTaxonomies'] ?? [];

		$has_post_fields = $search_post_fields && ! empty( $wp_fields );
		$has_meta_fields = $search_post_meta && ! empty( $meta_fields );
		$has_taxonomy    = $search_post_terms && ! empty( $taxonomies );

		if ( $has_post_fields || $has_meta_fields || $has_taxonomy ) {
			// Prepare meta fields
			$processed_meta_fields = [];
			if ( $has_meta_fields ) {
				// Trim and render dynamic data for each meta field
				foreach ( $meta_fields as $index => $meta_field_array ) {
					foreach ( $meta_field_array as $key => $value ) {
						if ( $key !== 'id' ) {
							$processed_meta_fields[ $index ][ $key ] = trim( bricks_render_dynamic_data( $value ) );
						}
					}
				}
			}

			// Prepare taxonomy term fields
			$processed_term_fields = [];
			if ( $has_taxonomy ) {
				foreach ( $taxonomies as $taxonomy_array ) {
					// id, taxonomy, weightScore
					// Ensure taxonomy is not empty
					$taxonomy = isset( $taxonomy_array['taxonomy'] ) ? trim( $taxonomy_array['taxonomy'] ) : '';

					if ( ! empty( $taxonomy ) ) {
						$processed_term_fields[] = [
							'taxonomy'    => $taxonomy,
							'weightScore' => isset( $taxonomy_array['weightScore'] ) ? max( 1, absint( $taxonomy_array['weightScore'] ) ) : 1,
						];
					}
				}
			}

			// Prepare post fields
			$processed_post_fields = $has_post_fields ? $wp_fields : [];

			// Get post IDs using combined SQL search
			$post_ids = self::get_post_ids_by_combined_search(
				$processed_post_fields,
				$processed_meta_fields,
				$processed_term_fields,
				$search_term,
				'main-search',
				'main-query',
				$use_weight_score
			);

		} else {
			$post_ids = [];
		}

		return $post_ids;
	}

	public static function use_weight_score( $template_id ) {
		$template_settings = Helpers::get_template_settings( $template_id );

		if ( isset( $template_settings['searchCriteriaCustom'] ) ) {
			return isset( $template_settings['useWeightScore'] );
		}

		return false;
	}


	/**
	 * Perform a combined search on specified post fields and meta fields, returning matching post IDs.
	 *
	 * @since 2.2
	 */
	public static function get_post_ids_by_combined_search( $search_fields, $meta_fields, $taxonomy_fields, $search_term, $filter_id, $query_id, $use_weight_score ) {
		if ( empty( $search_fields ) && empty( $meta_fields ) && empty( $taxonomy_fields ) ) {
			return [];
		}

		if ( empty( $search_term ) ) {
			return [];
		}

		// Generate cache key
		$cache_key = 'bricks_combined_search_posts_' . md5(
			serialize( $search_fields ) .
			serialize( $meta_fields ) .
			serialize( $taxonomy_fields ) .
			$search_term .
			$filter_id .
			$query_id .
			( $use_weight_score ? '_weighted' : '_unweighted' )
		);

		// Try to get from cache first
		$cached_post_ids = wp_cache_get( $cache_key, 'bricks_combined_search_post_ids' );
		if ( $cached_post_ids !== false ) {
			return $cached_post_ids;
		}

		if ( $use_weight_score ) {
			$post_ids = self::get_weighted_post_ids( $search_fields, $meta_fields, $taxonomy_fields, $search_term, $filter_id, $query_id );
		} else {
			$post_ids = self::get_unweighted_post_ids( $search_fields, $meta_fields, $taxonomy_fields, $search_term, $filter_id, $query_id );
		}

		// Currently used by WooCommerce to include product parents when searching certain meta fields
		$post_ids = apply_filters( 'bricks/combined_search/post_ids', $post_ids, $search_fields, $meta_fields, $search_term, $filter_id, $query_id );

		// Store in cache for future requests (5 minutes) - adjusted in future if needed
		wp_cache_set( $cache_key, $post_ids, 'bricks_combined_search_posts', 5 * MINUTE_IN_SECONDS );

		return $post_ids;
	}

	private static function get_weighted_post_ids( $search_fields, $meta_fields, $taxonomy_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		$search_term_like = '%' . $wpdb->esc_like( $search_term ) . '%';

		// Build CASE statements for post fields
		$case_statements = [];
		$joins           = [];

		// Handle post fields
		foreach ( $search_fields as $field_array ) {
			$field  = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;
			$weight = is_array( $field_array ) && isset( $field_array['weightScore'] ) ?
				 max( 1, absint( $field_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			switch ( $field ) {
				case 'title':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN post_title LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'content':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN post_content LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'excerpt':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN post_excerpt LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'default':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN (post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s) THEN %d ELSE 0 END',
						$search_term_like,
						$search_term_like,
						$search_term_like,
						$weight
					);
					break;
			}
		}

		// Handle meta fields
		$meta_index = 0;
		foreach ( $meta_fields as $meta_key_array ) {
			$meta_key = is_array( $meta_key_array ) && isset( $meta_key_array['metaKey'] ) ? $meta_key_array['metaKey'] : '';
			$weight   = is_array( $meta_key_array ) && isset( $meta_key_array['weightScore'] ) ?
				 max( 1, absint( $meta_key_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			if ( empty( $meta_key ) ) {
				continue;
			}

			$alias           = 'pm' . $meta_index;
			$joins[ $alias ] = $wpdb->prepare(
				"LEFT JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = p.ID AND {$alias}.meta_key = %s",
				$meta_key
			);

			$case_statements[] = $wpdb->prepare(
				"CASE WHEN {$alias}.meta_value LIKE %s THEN %d ELSE 0 END",
				$search_term_like,
				$weight
			);
			$meta_index++;
		}

		// Handle taxonomy fields - Use subqueries to aggregate scores per taxonomy
		$tax_index = 0;
		foreach ( $taxonomy_fields as $taxonomy_array ) {
			$taxonomy = is_array( $taxonomy_array ) && isset( $taxonomy_array['taxonomy'] ) ? $taxonomy_array['taxonomy'] : '';
			$weight   = is_array( $taxonomy_array ) && isset( $taxonomy_array['weightScore'] ) ?
						max( 1, absint( $taxonomy_array['weightScore'] ) ) : 1;

			if ( empty( $taxonomy ) ) {
					continue;
			}

			$subquery_alias = 'tax_score_' . $tax_index;

			// Create a subquery that returns the SUM of matching terms for each post
			// This handles multiple term matches correctly, each contributing to the total score
			$joins[ $subquery_alias ] = $wpdb->prepare(
				"LEFT JOIN (
						SELECT tr.object_id, SUM(
								CASE
										WHEN (t.name LIKE %s OR t.slug LIKE %s OR tt.description LIKE %s) THEN %d
										ELSE 0
								END
						) as score
						FROM {$wpdb->term_relationships} tr
						INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
						INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
						GROUP BY tr.object_id
				) {$subquery_alias} ON {$subquery_alias}.object_id = p.ID",
				$search_term_like,
				$search_term_like,
				$search_term_like,
				$weight,
				$taxonomy
			);

				// Add the aggregated score from the subquery
				$case_statements[] = "COALESCE({$subquery_alias}.score, 0)";

				$tax_index++;
		}

		if ( empty( $case_statements ) ) {
			return [];
		}

		// Build the complete query
		$relevance_sql = implode( ' + ', $case_statements );
		$joins_sql     = implode( ' ', $joins );

		$sql = "
			SELECT p.ID as post_id, ( {$relevance_sql} ) as relevance_score
			FROM {$wpdb->posts} p
			{$joins_sql}
			WHERE p.post_status = 'publish'
			HAVING relevance_score > 0
			ORDER BY relevance_score DESC, p.ID ASC
		";

		$results = $wpdb->get_results( $sql );

		// Extract post IDs in order of relevance
		$post_ids = array_map(
			function( $row ) {
				return (int) $row->post_id;
			},
			$results
		);

		return $post_ids;
	}

	private static function get_unweighted_post_ids( $search_fields, $meta_fields, $taxonomy_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		$search_term_like = '%' . $wpdb->esc_like( $search_term ) . '%';

		$conditions = [];

		// Handle post fields
		foreach ( $search_fields as $field_array ) {
			$field = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;
			switch ( $field ) {
				case 'title':
					$conditions[] = $wpdb->prepare( 'post_title LIKE %s', $search_term_like );
					break;

				case 'content':
					$conditions[] = $wpdb->prepare( 'post_content LIKE %s', $search_term_like );
					break;

				case 'excerpt':
					$conditions[] = $wpdb->prepare( 'post_excerpt LIKE %s', $search_term_like );
					break;

				default:
				case 'default':
					$conditions[] = $wpdb->prepare(
						'(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s)',
						$search_term_like,
						$search_term_like,
						$search_term_like
					);
					break;
			}
		}

		// Handle meta fields
		foreach ( $meta_fields as $meta_key_array ) {
			$meta_key = is_array( $meta_key_array ) && isset( $meta_key_array['metaKey'] ) ? $meta_key_array['metaKey'] : '';

			if ( empty( $meta_key ) ) {
				continue;
			}

			$conditions[] = $wpdb->prepare(
				"(EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value LIKE %s
				))",
				$meta_key,
				$search_term_like
			);
		}

		// Handle taxonomy fields
		foreach ( $taxonomy_fields as $taxonomy_array ) {
			$taxonomy = is_array( $taxonomy_array ) && isset( $taxonomy_array['taxonomy'] ) ? $taxonomy_array['taxonomy'] : '';

			if ( empty( $taxonomy ) ) {
				continue;
			}

			// Search in term name, slug, and description
			$conditions[] = $wpdb->prepare(
				"(EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = p.ID
					AND tt.taxonomy = %s
					AND (t.name LIKE %s OR t.slug LIKE %s OR tt.description LIKE %s)
				))",
				$taxonomy,
				$search_term_like,
				$search_term_like,
				$search_term_like
			);
		}

		if ( empty( $conditions ) ) {
			return [];
		}

		$where_sql = implode( ' OR ', $conditions );

		$sql      = "
			SELECT DISTINCT p.ID as post_id
			FROM {$wpdb->posts} p
			WHERE p.post_status = 'publish' AND ( {$where_sql} )
		";
		$post_ids = array_map( 'intval', $wpdb->get_col( $sql ) );

		return $post_ids;
	}

	/**
	 * Perform a combined search on specified term fields and meta fields, returning matching term IDs.
	 *
	 * @since 2.2
	 */
	public static function get_term_ids_by_combined_search( $term_fields, $meta_fields, $search_term, $filter_id, $query_id, $use_weight_score ) {
		if ( empty( $term_fields ) && empty( $meta_fields ) ) {
			return [];
		}

		if ( empty( $search_term ) ) {
			return [];
		}

		// Generate cache key
		$cache_key = 'brx_combined_search_terms_' . md5( serialize( $term_fields ) . serialize( $meta_fields ) . $search_term . $filter_id . $query_id . ( $use_weight_score ? '_weighted' : '_unweighted' ) );

		// Try to get from cache first
		$cached_term_ids = wp_cache_get( $cache_key, 'bricks_combined_search_terms' );
		if ( $cached_term_ids !== false ) {
			return $cached_term_ids;
		}

		if ( $use_weight_score ) {
			$term_ids = self::get_weighted_term_ids( $term_fields, $meta_fields, $search_term, $filter_id, $query_id );
		} else {
			$term_ids = self::get_unweighted_term_ids( $term_fields, $meta_fields, $search_term, $filter_id, $query_id );
		}

		$term_ids = apply_filters( 'bricks/combined_search/term_ids', $term_ids, $term_fields, $meta_fields, $search_term, $filter_id, $query_id );

		// Store in cache for future requests (5 minutes) - adjusted in future if needed
		wp_cache_set( $cache_key, $term_ids, 'bricks_combined_search_terms', 5 * MINUTE_IN_SECONDS );

		return $term_ids;

	}

	private static function get_weighted_term_ids( $term_fields, $meta_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		$search_term_like = '%' . $wpdb->esc_like( $search_term ) . '%';

		// Build CASE statements for term fields
		$case_statements = [];
		$joins           = [];

		// Handle term fields
		foreach ( $term_fields as $field_array ) {
			$field  = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;
			$weight = is_array( $field_array ) && isset( $field_array['weightScore'] ) ?
				 max( 1, absint( $field_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			switch ( $field ) {
				case 'name':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN t.name LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'slug':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN t.slug LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'description':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN tt.description LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					$joins['tt']       = "LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id";
					break;

				case 'default':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN (t.name LIKE %s OR t.slug LIKE %s) THEN %d ELSE 0 END',
						$search_term_like,
						$search_term_like,
						$weight
					);
					break;
			}
		}

		// Handle meta fields
		$meta_index = 0;
		foreach ( $meta_fields as $meta_key_array ) {
			$meta_key = is_array( $meta_key_array ) && isset( $meta_key_array['metaKey'] ) ? $meta_key_array['metaKey'] : '';
			$weight   = is_array( $meta_key_array ) && isset( $meta_key_array['weightScore'] ) ?
				 max( 1, absint( $meta_key_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			if ( empty( $meta_key ) ) {
				continue;
			}

			$alias           = 'tm' . $meta_index;
			$joins[ $alias ] = $wpdb->prepare(
				"LEFT JOIN {$wpdb->termmeta} {$alias} ON t.term_id = {$alias}.term_id AND {$alias}.meta_key = %s",
				$meta_key
			);

			$case_statements[] = $wpdb->prepare(
				"CASE WHEN {$alias}.meta_value LIKE %s THEN %d ELSE 0 END",
				$search_term_like,
				$weight
			);

			$meta_index++;
		}

		if ( empty( $case_statements ) ) {
			return [];
		}

		// Build the complete query
		$relevance_sql = implode( ' + ', $case_statements );
		$joins_sql     = implode( ' ', $joins );

		$sql = "
        SELECT t.term_id, ({$relevance_sql}) AS relevance
        FROM {$wpdb->terms} t
        {$joins_sql}
        HAVING relevance > 0
        ORDER BY relevance DESC, t.name ASC
    ";

		$results = $wpdb->get_results( $sql );

		// Extract term IDs in order of relevance
		$term_ids = array_map(
			function( $row ) {
				return (int) $row->term_id;
			},
			$results
		);

		return $term_ids;
	}

	private static function get_unweighted_term_ids( $term_fields, $meta_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		$search_term   = '%' . $wpdb->esc_like( $search_term ) . '%';
		$union_queries = [];

		// Handle term fields search
		if ( ! empty( $term_fields ) ) {
			$term_conditions = [];

			foreach ( $term_fields as $field_array ) {
				$field = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;

				switch ( $field ) {
					case 'name':
						$term_conditions[] = $wpdb->prepare( 'name LIKE %s', $search_term );
						break;

					case 'slug':
						$term_conditions[] = $wpdb->prepare( 'slug LIKE %s', $search_term );
						break;

					case 'description':
						// Need to join with term_taxonomy table
						$term_conditions[] = $wpdb->prepare( 'tt.description LIKE %s', $search_term );
						break;

					default:
					case 'default':
						// Default WP search (name, slug)
						$term_conditions[] = $wpdb->prepare(
							'(name LIKE %s OR slug LIKE %s)',
							$search_term,
							$search_term
						);
						break;
				}
			}

			if ( ! empty( $term_conditions ) ) {
				$term_where = implode( ' OR ', $term_conditions );
				// Always join with term_taxonomy table to allow searching description
				$union_queries[] = "
					SELECT DISTINCT t.term_id
					FROM {$wpdb->terms} AS t
					LEFT JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
					WHERE ({$term_where})
				";
			}
		}

		// Handle meta fields search
		if ( ! empty( $meta_fields ) ) {
			$meta_conditions = [];

			foreach ( $meta_fields as $meta_key_array ) {
				$meta_key = is_array( $meta_key_array ) && isset( $meta_key_array['metaKey'] ) ? $meta_key_array['metaKey'] : '';

				if ( empty( $meta_key ) ) {
					continue;
				}

				$meta_conditions[] = $wpdb->prepare(
					'(meta_key = %s AND meta_value LIKE %s)',
					$meta_key,
					$search_term
				);
			}

			if ( ! empty( $meta_conditions ) ) {
				$meta_where      = implode( ' OR ', $meta_conditions );
				$union_queries[] = "
					SELECT DISTINCT term_id
					FROM {$wpdb->termmeta}
					WHERE {$meta_where}
				";
			}
		}

		if ( empty( $union_queries ) ) {
			return [];
		}

		$sql = implode( ' UNION ', $union_queries );
		return array_map( 'intval', array_unique( $wpdb->get_col( $sql ) ) );
	}

	/**
	 * Perform a combined search on specified user fields and meta fields, returning matching user IDs.
	 *
	 * @since 2.2
	 */
	public static function get_user_ids_by_combined_search( $user_fields, $meta_fields, $search_term, $filter_id, $query_id, $use_weight_score ) {
		if ( empty( $user_fields ) && empty( $meta_fields ) ) {
			return [];
		}

		if ( empty( $search_term ) ) {
			return [];
		}

		// Generate cache key
		$cache_key = 'brx_combined_search_users_' . md5( serialize( $user_fields ) . serialize( $meta_fields ) . $search_term . $filter_id . $query_id . ( $use_weight_score ? '_weighted' : '_unweighted' ) );

		// Try to get from cache first
		$cached_user_ids = wp_cache_get( $cache_key, 'bricks_combined_search_users' );
		if ( $cached_user_ids !== false ) {
			return $cached_user_ids;
		}

		if ( $use_weight_score ) {
			$user_ids = self::get_weighted_user_ids( $user_fields, $meta_fields, $search_term, $filter_id, $query_id );
		} else {
			$user_ids = self::get_unweighted_user_ids( $user_fields, $meta_fields, $search_term, $filter_id, $query_id );
		}

		$user_ids = apply_filters( 'bricks/combined_search/user_ids', $user_ids, $user_fields, $meta_fields, $search_term, $filter_id, $query_id );

		// Store in cache for future requests (5 minutes) - adjusted in future if needed
		wp_cache_set( $cache_key, $user_ids, 'bricks_combined_search_users', 5 * MINUTE_IN_SECONDS );

		return $user_ids;
	}

	private static function get_weighted_user_ids( $user_fields, $meta_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		$search_term_like = '%' . $wpdb->esc_like( $search_term ) . '%';

		// Build CASE statements for user fields
		$case_statements = [];
		$joins           = [];

		// Handle user fields
		foreach ( $user_fields as $field_array ) {
			$field  = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;
			$weight = is_array( $field_array ) && isset( $field_array['weightScore'] ) ?
				 max( 1, absint( $field_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			switch ( $field ) {
				case 'user_login':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN u.user_login LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'user_nicename':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN u.user_nicename LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'user_email':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN u.user_email LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'user_url':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN u.user_url LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'display_name':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN u.display_name LIKE %s THEN %d ELSE 0 END',
						$search_term_like,
						$weight
					);
					break;

				case 'default':
					$case_statements[] = $wpdb->prepare(
						'CASE WHEN (u.user_login LIKE %s OR u.user_nicename LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s) THEN %d ELSE 0 END',
						$search_term_like,
						$search_term_like,
						$search_term_like,
						$search_term_like,
						$weight
					);
					break;
			}
		}

		// Handle meta fields
		$meta_index = 0;
		foreach ( $meta_fields as $meta_array ) {
			$meta_key = is_array( $meta_array ) ? $meta_array['metaKey'] : $meta_array;
			$weight   = is_array( $meta_array ) && isset( $meta_array['weightScore'] ) ?
				 max( 1, absint( $meta_array['weightScore'] ) ) : 1; // Force minimum weight of 1 to avoid zero-weight matches

			if ( empty( $meta_key ) ) {
				continue;
			}

			$alias           = 'um' . $meta_index;
			$joins[ $alias ] = $wpdb->prepare(
				"LEFT JOIN {$wpdb->usermeta} {$alias} ON u.ID = {$alias}.user_id AND {$alias}.meta_key = %s",
				$meta_key
			);

			$case_statements[] = $wpdb->prepare(
				"CASE WHEN {$alias}.meta_value LIKE %s THEN %d ELSE 0 END",
				$search_term_like,
				$weight
			);

			$meta_index++;
		}

		if ( empty( $case_statements ) ) {
			return [];
		}

		// Build the complete query
		$relevance_sql = implode( ' + ', $case_statements );
		$joins_sql     = implode( ' ', $joins );

		$sql = "
				SELECT u.ID as user_id, ({$relevance_sql}) AS relevance
				FROM {$wpdb->users} u
				{$joins_sql}
				HAVING relevance > 0
				ORDER BY relevance DESC, u.user_login ASC
		";

		$results = $wpdb->get_results( $sql );

		// Extract user IDs in order of relevance
		$user_ids = array_map(
			function( $row ) {
				return (int) $row->user_id;
			},
			$results
		);

		return $user_ids;
	}

	private static function get_unweighted_user_ids( $user_fields, $meta_fields, $search_term, $filter_id, $query_id ) {
		global $wpdb;

		// Sanitize the search term
		$search_term = '%' . $wpdb->esc_like( $search_term ) . '%';

		$union_queries = [];

		// Handle user fields search
		if ( ! empty( $user_fields ) ) {
			$user_conditions = [];

			foreach ( $user_fields as $field_array ) {
				$field = is_array( $field_array ) && isset( $field_array['field'] ) ? $field_array['field'] : $field_array;

				switch ( $field ) {
					case 'user_login':
						$user_conditions[] = $wpdb->prepare( 'user_login LIKE %s', $search_term );
						break;

					case 'user_nicename':
						$user_conditions[] = $wpdb->prepare( 'user_nicename LIKE %s', $search_term );
						break;

					case 'user_email':
						$user_conditions[] = $wpdb->prepare( 'user_email LIKE %s', $search_term );
						break;

					case 'user_url':
						$user_conditions[] = $wpdb->prepare( 'user_url LIKE %s', $search_term );
						break;

					case 'display_name':
						$user_conditions[] = $wpdb->prepare( 'display_name LIKE %s', $search_term );
						break;

					default:
					case 'default':
						// Default WP search (user_login, user_nicename, user_email, display_name)
						$user_conditions[] = $wpdb->prepare(
							'(user_login LIKE %s OR user_nicename LIKE %s OR user_email LIKE %s OR display_name LIKE %s)',
							$search_term,
							$search_term,
							$search_term,
							$search_term
						);
						break;
				}
			}

			if ( ! empty( $user_conditions ) ) {
				$user_where      = implode( ' OR ', $user_conditions );
				$union_queries[] = "
					SELECT DISTINCT ID as user_id
					FROM {$wpdb->users}
					WHERE {$user_where}
				";
			}
		}

		// Handle meta fields search
		if ( ! empty( $meta_fields ) ) {
			$meta_conditions = [];

			foreach ( $meta_fields as $meta_key_array ) {
				$meta_key = is_array( $meta_key_array ) && isset( $meta_key_array['metaKey'] ) ? $meta_key_array['metaKey'] : '';

				if ( empty( $meta_key ) ) {
					continue;
				}

				$meta_conditions[] = $wpdb->prepare(
					'(meta_key = %s AND meta_value LIKE %s)',
					$meta_key,
					$search_term
				);
			}

			if ( ! empty( $meta_conditions ) ) {
				$meta_where      = implode( ' OR ', $meta_conditions );
				$union_queries[] = "
					SELECT DISTINCT user_id
					FROM {$wpdb->usermeta}
					WHERE {$meta_where}
				";
			}
		}

		if ( empty( $union_queries ) ) {
			return [];
		}

		// Combine with UNION for better performance
		$sql      = implode( ' UNION ', $union_queries );
		$user_ids = array_map( 'intval', array_unique( $wpdb->get_col( $sql ) ) );

		return $user_ids;
	}
}

<?php

namespace ElasticPress\Post;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Post extends Indexable {

	public $indexable_type = 'post';

	public function delete( $post_id, $blocking = true  ) {
		return Elasticsearch::factory()->delete_document( $this->get_index_name(), 'post', $post_id, $blocking );
	}

	public function get( $post_id ) {
		return Elasticsearch::factory()->get_document( $this->get_index_name(), 'post', $post_id );
	}

	public function delete_index( $blog_id = null ) {
		return Elasticsearch::factory()->delete_index( $this->get_index_name( $blog_id ) );
	}

	public function index( $post, $blocking = false ) {
		$post = apply_filters( 'ep_pre_index_post', $post );

		$return = Elasticsearch::factory()->index_document( $this->get_index_name(), 'post', $post['post_id'], $post, $blocking );

		do_action( 'ep_after_index_post', $post, $return );

		return $return;
	}

	public function query( $args, $query_args, $scope = 'current' ) {
		$index = null;

		if ( 'all' === $scope ) {
			$index = $this->get_network_alias();
		} elseif ( is_numeric( $scope ) ) {
			$index = $this->get_index_name( (int) $scope );
		} elseif ( is_array( $scope ) ) {
			$index = [];

			foreach ( $scope as $site_id ) {
				$index[] = $this->get_index_name( $site_id );
			}

			$index = implode( ',', $index );
		} else {
			$index = $this->get_index_name();
		}

		return Elasticsearch::factory()->query( $index, 'post', $args, $query_args );
	}

	/**
	 * Returns indexable post types for the current site
	 *
	 * @since 0.9
	 * @return mixed|void
	 */
	public function get_indexable_post_types() {
		$post_types = get_post_types( array( 'public' => true ) );

		return apply_filters( 'ep_indexable_post_types', $post_types );
	}

	/**
	 * Return indexable post_status for the current site
	 *
	 * @since 1.3
	 * @return array
	 */
	public function get_indexable_post_status() {
		return apply_filters( 'ep_indexable_post_status', array( 'publish' ) );
	}

	public function put_mapping() {
		$es_version = $this->get_elasticsearch_version();

		if ( empty( $es_version ) ) {
			$es_version = apply_filters( 'ep_fallback_elasticsearch_version', '2.0' );
		}

		if ( ! $es_version || version_compare( $es_version, '5.0' ) < 0 ) {
			$mapping_file = 'pre-5-0.php';
		} elseif ( version_compare( $es_version, '5.2' ) >= 0 ) {
			$mapping_file = '5-2.php';
		} else {
			$mapping_file = '5-0.php';
		}

		$mapping = require( apply_filters( 'ep_post_mapping_file', dirname( __FILE__ ) . '/../includes/mappings/post/' . $mapping_file ) );

		return Elasticsearch::factory()->put_mapping( $this->get_index_name(), $mapping );
	}

	/**
	 * Prepare a post for syncing
	 *
	 * @param int $post_id
	 * @since 0.9.1
	 * @return bool|array
	 */
	public function prepare_post( $post_id ) {
		$post = get_post( $post_id );

		$user = get_userdata( $post->post_author );

		if ( $user instanceof WP_User ) {
			$user_data = array(
				'raw'          => $user->user_login,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'id'           => $user->ID,
			);
		} else {
			$user_data = array(
				'raw'          => '',
				'login'        => '',
				'display_name' => '',
				'id'           => '',
			);
		}

		$post_date = $post->post_date;
		$post_date_gmt = $post->post_date_gmt;
		$post_modified = $post->post_modified;
		$post_modified_gmt = $post->post_modified_gmt;
		$comment_count = absint( $post->comment_count );
		$comment_status = absint( $post->comment_status );
		$ping_status = absint( $post->ping_status );
		$menu_order = absint( $post->menu_order );

		if ( apply_filters( 'ep_ignore_invalid_dates', true, $post_id, $post ) ) {
			if ( ! strtotime( $post_date ) || $post_date === "0000-00-00 00:00:00" ) {
				$post_date = null;
			}

			if ( ! strtotime( $post_date_gmt ) || $post_date_gmt === "0000-00-00 00:00:00" ) {
				$post_date_gmt = null;
			}

			if ( ! strtotime( $post_modified ) || $post_modified === "0000-00-00 00:00:00" ) {
				$post_modified = null;
			}

			if ( ! strtotime( $post_modified_gmt ) || $post_modified_gmt === "0000-00-00 00:00:00" ) {
				$post_modified_gmt = null;
			}
		}

		// To prevent infinite loop, we don't queue when updated_postmeta
		remove_action( 'updated_postmeta', array( EP_Sync_Manager::factory(), 'action_queue_meta_sync' ), 10 );

		$post_args = array(
			'post_id'           => $post_id,
			'ID'                => $post_id,
			'post_author'       => $user_data,
			'post_date'         => $post_date,
			'post_date_gmt'     => $post_date_gmt,
			'post_title'        => $this->prepare_text_content( get_the_title( $post_id ) ),
			'post_excerpt'      => $this->prepare_text_content( $post->post_excerpt ),
			'post_content'      => $this->prepare_text_content( apply_filters( 'the_content', $post->post_content ) ),
			'post_status'       => $post->post_status,
			'post_name'         => $post->post_name,
			'post_modified'     => $post_modified,
			'post_modified_gmt' => $post_modified_gmt,
			'post_parent'       => $post->post_parent,
			'post_type'         => $post->post_type,
			'post_mime_type'    => $post->post_mime_type,
			'permalink'         => get_permalink( $post_id ),
			'terms'             => $this->prepare_terms( $post ),
			'meta'              => $this->prepare_meta_types( $this->prepare_meta( $post ) ), // post_meta removed in 2.4
			'date_terms'        => $this->prepare_date_terms( $post_date ),
			'comment_count'     => $comment_count,
			'comment_status'    => $comment_status,
			'ping_status'       => $ping_status,
			'menu_order'        => $menu_order,
			'guid'				=> $post->guid
			//'site_id'         => get_current_blog_id(),
		);

		/**
		 * This filter is named poorly but has to stay to keep backwards compat
		 */
		$post_args = apply_filters( 'ep_post_sync_args', $post_args, $post_id );

		$post_args = apply_filters( 'ep_post_sync_args_post_prepare_meta', $post_args, $post_id );

		// Turn back on updated_postmeta hook
		add_action( 'updated_postmeta', array( EP_Sync_Manager::factory(), 'action_queue_meta_sync' ), 10, 4 );

		return $post_args;
	}

	/**
	 * Prepare text for ES: Strip html, strip line breaks, etc.
	 *
	 * @param  string $content
	 * @since  2.2
	 * @return string
	 */
	private function prepare_text_content( $content ) {
		$content = strip_tags( $content );
		$content = preg_replace( '#[\n\r]+#s', ' ', $content );

		return $content;
	}

	/**
	 * Prepare date terms to send to ES.
	 *
	 * @param string $timestamp
	 *
	 * @since 0.1.4
	 * @return array
	 */
	private function prepare_date_terms( $post_date_gmt ) {
		$timestamp = strtotime( $post_date_gmt );
		$date_terms = array(
			'year' => (int) date( "Y", $timestamp),
			'month' => (int) date( "m", $timestamp),
			'week' => (int) date( "W", $timestamp),
			'dayofyear' => (int) date( "z", $timestamp),
			'day' => (int) date( "d", $timestamp),
			'dayofweek' => (int) date( "w", $timestamp),
			'dayofweek_iso' => (int) date( "N", $timestamp),
			'hour' => (int) date( "H", $timestamp),
			'minute' => (int) date( "i", $timestamp),
			'second' => (int) date( "s", $timestamp),
			'm' => (int) (date( "Y", $timestamp) . date( "m", $timestamp)), // yearmonth
		);
		return $date_terms;
	}

	/**
	 * Prepare terms to send to ES.
	 *
	 * @param object $post
	 *
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $post ) {
		$taxonomies          = get_object_taxonomies( $post->post_type, 'objects' );
		$selected_taxonomies = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public || $taxonomy->publicly_queryable ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		$selected_taxonomies = apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $post );

		if ( empty( $selected_taxonomies ) ) {
			return [];
		}

		$terms = [];

		$allow_hierarchy = apply_filters( 'ep_sync_terms_allow_hierarchy', false );

		foreach ( $selected_taxonomies as $taxonomy ) {
			// If we get a taxonomy name, we need to convert it to taxonomy object
			if ( ! is_object( $taxonomy ) && taxonomy_exists( (string) $taxonomy ) ) {
				$taxonomy = get_taxonomy( $taxonomy );
			}

			// We check if the $taxonomy object as name property. Backward compatibility since WP_Taxonomy introduced in WP 4.7
			if ( ! is_a( $taxonomy, '\WP_Taxonomy' ) || ! property_exists( $taxonomy, 'name' ) ) {
				continue;
			}

			$object_terms = get_the_terms( $post->ID, $taxonomy->name );

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			$terms_dic = [];

			foreach ( $object_terms as $term ) {
				if( ! isset( $terms_dic[ $term->term_id ] ) ) {
					$terms_dic[ $term->term_id ] = array(
						'term_id'          => $term->term_id,
						'slug'             => $term->slug,
						'name'             => $term->name,
						'parent'           => $term->parent,
						'term_taxonomy_id' => $term->term_taxonomy_id,
					);
					if( $allow_hierarchy ){
						$terms_dic = $this->get_parent_terms( $terms_dic, $term, $taxonomy->name );
					}
				}
			}
			$terms[ $taxonomy->name ] = array_values( $terms_dic );
		}

		return $terms;
	}

	/**
	 * Recursively get all the ancestor terms of the given term
	 * @param $terms
	 * @param $term
	 * @param $tax_name
	 * @return array
	 */
	private function get_parent_terms( $terms, $term, $tax_name ) {
		$parent_term = get_term( $term->parent, $tax_name );
		if( ! $parent_term || is_wp_error( $parent_term ) )
			return $terms;
		if( ! isset( $terms[ $parent_term->term_id ] ) ) {
			$terms[ $parent_term->term_id ] = array(
				'term_id' => $parent_term->term_id,
				'slug'    => $parent_term->slug,
				'name'    => $parent_term->name,
				'parent'  => $parent_term->parent
			);
		}
		return $this->get_parent_terms( $terms, $parent_term, $tax_name );
	}

	/**
	 * Prepare post meta to send to ES
	 *
	 * @param object $post
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function prepare_meta( $post ) {
		$meta = (array) get_post_meta( $post->ID );

		if ( empty( $meta ) ) {
			return [];
		}

		$prepared_meta = [];

		/**
		 * Filter index-able private meta
		 *
		 * Allows for specifying private meta keys that may be indexed in the same manor as public meta keys.
		 *
		 * @since 1.7
		 *
		 * @param         array Array of index-able private meta keys.
		 * @param WP_Post $post The current post to be indexed.
		 */
		$allowed_protected_keys = apply_filters( 'ep_prepare_meta_allowed_protected_keys', [], $post );

		/**
		 * Filter non-indexed public meta
		 *
		 * Allows for specifying public meta keys that should be excluded from the ElasticPress index.
		 *
		 * @since 1.7
		 *
		 * @param         array Array of public meta keys to exclude from index.
		 * @param WP_Post $post The current post to be indexed.
		 */
		$excluded_public_keys = apply_filters( 'ep_prepare_meta_excluded_public_keys', [], $post );

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys )  ) {
					$allow_index = true;
				}
			}

			if ( true === $allow_index || apply_filters( 'ep_prepare_meta_whitelist_key', false, $key, $post ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		return $prepared_meta;

	}

	/**
	 * Prepare post meta type values to send to ES
	 *
	 * @param array $post_meta
	 *
	 * @return array
	 *
	 * @since x.x.x
	 */
	public function prepare_meta_types( $post_meta ) {

		$meta = [];

		foreach ( $post_meta as $meta_key => $meta_values ) {
			if ( ! is_array( $meta_values ) ) {
				$meta_values = array( $meta_values );
			}

			$meta[ $meta_key ] = array_map( array( $this, 'prepare_meta_value_types' ), $meta_values );
		}

		return $meta;

	}

	/**
	 * Prepare meta types for meta value
	 *
	 * @param mixed $meta_value
	 *
	 * @return array
	 */
	public function prepare_meta_value_types( $meta_value ) {

		$max_java_int_value = 9223372036854775807;

		$meta_types = [];

		if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
			$meta_value = serialize( $meta_value );
		}

		$meta_types['value'] = $meta_value;
		$meta_types['raw']   = $meta_value;

		if ( is_numeric( $meta_value ) ) {
			$long = intval( $meta_value );

			if ( $max_java_int_value < $long ) {
				$long = $max_java_int_value;
			}

			$double = floatval( $meta_value );

			if ( ! is_finite( $double ) ) {
				$double = 0;
			}

			$meta_types['long']   = $long;
			$meta_types['double'] = $double;
		}

		$meta_types['boolean'] = filter_var( $meta_value, FILTER_VALIDATE_BOOLEAN );

		if ( is_string( $meta_value ) ) {
			$timestamp = strtotime( $meta_value );

			$date     = '1971-01-01';
			$datetime = '1971-01-01 00:00:01';
			$time     = '00:00:01';

			if ( false !== $timestamp ) {
				$date     = date_i18n( 'Y-m-d', $timestamp );
				$datetime = date_i18n( 'Y-m-d H:i:s', $timestamp );
				$time     = date_i18n( 'H:i:s', $timestamp );
			}

			$meta_types['date']     = $date;
			$meta_types['datetime'] = $datetime;
			$meta_types['time']     = $time;
		}

		return $meta_types;
	}

	/**
	 * Format WP query args for ES
	 *
	 * @param array $args
	 * @since 0.9.0
	 * @return array
	 */
	public function format_args( $args ) {
		if ( isset( $args['post_per_page'] ) ) {
			// For backwards compatibility for those using this since EP 1.4
			$args['posts_per_page'] = $args['post_per_page'];
		}

		if ( ! empty( $args['posts_per_page'] ) ) {
			$posts_per_page = (int) $args['posts_per_page'];

			// ES have a maximum size allowed so we have to convert "-1" to a maximum size.
			if ( -1 === $posts_per_page ) {
				/**
				 * Set the maximum results window size.
				 *
				 * The request will return a HTTP 500 Internal Error if the size of the
				 * request is larger than the [index.max_result_window] parameter in ES.
				 * See the scroll api for a more efficient way to request large data sets.
				 *
				 * @return int The max results window size.
				 *
				 * @since 2.3.0
				 */
				$posts_per_page = apply_filters( 'ep_max_results_window', 10000 );
			}
		} else {
			$posts_per_page = (int) get_option( 'posts_per_page' );
		}

		$formatted_args = array(
			'from' => 0,
			'size' => $posts_per_page,
		);

		/**
		 * Order and Orderby arguments
		 *
		 * Used for how Elasticsearch will sort results
		 *
		 * @since 1.1
		 */
		// Set sort order, default is 'desc'
		if ( ! empty( $args['order'] ) ) {
			$order = $this->parse_order( $args['order'] );
		} else {
			$order = 'desc';
		}

		// Default sort for non-searches to date
		if ( empty( $args['orderby'] ) && ( ! isset( $args['s'] ) || '' === $args['s'] ) ) {
			$args['orderby'] = 'date';
		}

		// Set sort type
		if ( ! empty( $args['orderby'] ) ) {
			$formatted_args['sort'] = $this->parse_orderby( $args['orderby'], $order, $args );
		} else {
			// Default sort is to use the score (based on relevance)
			$default_sort = array(
				array(
					'_score' => array(
						'order' => $order,
					),
				),
			);

			$default_sort = apply_filters( 'ep_set_default_sort', $default_sort, $order );

			$formatted_args['sort'] = $default_sort;
		}

		$filter = array(
			'bool' => array(
				'must' => [],
			),
		);
		$use_filters = false;

		/**
		 * Tax Query support
		 *
		 * Support for the tax_query argument of WP_Query. Currently only provides support for the 'AND' relation
		 * between taxonomies. Field only supports slug, term_id, and name defaulting to term_id.
		 *
		 * @use field = slug
		 *      terms array
		 * @since 0.9.1
		 */

		//set tax_query if it's implicitly set in the query
		//e.g. $args['tag'], $args['category_name']
		if ( empty( $args['tax_query'] ) ) {
			if ( ! empty( $args['category_name'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'category',
					'terms' =>  array( $args['category_name'] ),
					'field' => 'slug'
				);
			} elseif ( ! empty( $args['cat'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'category',
					'terms' =>  array( $args['cat'] ),
					'field' => 'id'
				);
			}

			if ( ! empty( $args['tag'] ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'terms' =>  array( $args['tag'] ),
					'field' => 'slug'
				);
			}
		}

		if ( ! empty( $args['tax_query'] ) ) {
			$tax_filter = [];
			$tax_must_not_filter  = [];

			// Main tax_query array for ES
			$es_tax_query = [];

			foreach( $args['tax_query'] as $single_tax_query ) {
				if ( ! empty( $single_tax_query['terms'] ) ) {
					$terms = (array) $single_tax_query['terms'];

					$field = ( ! empty( $single_tax_query['field'] ) ) ? $single_tax_query['field'] : 'term_id';

					if ( 'name' === $field ) {
						$field = 'name.raw';
					}

					// Set up our terms object
					$terms_obj = array(
						'terms.' . $single_tax_query['taxonomy'] . '.' . $field => $terms,
					);

					$operator = ( ! empty( $single_tax_query['operator'] ) ) ? strtolower( $single_tax_query['operator'] ) : 'in';

					if ( 'not in' === $operator ) {
						/**
						 * add support for "NOT IN" operator
						 *
						 * @since 2.1
						 */

						// If "NOT IN" than it should filter as must_not
						$tax_must_not_filter[]['terms'] = $terms_obj;
					} elseif ( 'and' === $operator ) {
						/**
						 * add support for "and" operator
						 *
						 * @since 2.4
						 */

						$and_nest = array(
							'bool' => array(
								'must' => []
							),
						);

						foreach ( $terms as $term ) {
							$and_nest['bool']['must'][] = array(
								'terms' => array(
									'terms.' . $single_tax_query['taxonomy'] . '.' . $field => (array) $term,
								)
							);
						}

						$tax_filter[] = $and_nest;
					} else {
						/**
						 * Default to IN operator
						 */

						// Add the tax query filter
						$tax_filter[]['terms'] = $terms_obj;
					}
				}
			}

			if ( ! empty( $tax_filter ) ) {
				$relation = 'must';

				if ( ! empty( $args['tax_query']['relation'] ) && 'or' === strtolower( $args['tax_query']['relation'] ) ) {
					$relation = 'should';
				}

				$es_tax_query[$relation] = $tax_filter;
			}

			if ( ! empty( $tax_must_not_filter ) ) {
				$es_tax_query['must_not'] = $tax_must_not_filter;
			}

			if( ! empty( $es_tax_query ) ) {
				$filter['bool']['must'][]['bool'] = $es_tax_query;
			}

			$use_filters = true;
		}

		/**
		 * 'post_parent' arg support.
		 *
		 * @since 2.0
		 */
		if ( isset( $args['post_parent'] ) && '' !== $args['post_parent'] && 'any' !== strtolower( $args['post_parent'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'term' => array(
					'post_parent' => $args['post_parent'],
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post__in' arg support.
		 *
		 * @since x.x
		 */
		if ( ! empty( $args['post__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'terms' => array(
					'post_id' => array_values( (array) $args['post__in'] ),
				),
			);

			$use_filters = true;
		}

	        /**
	         * 'post__not_in' arg support.
	         *
	         * @since x.x
	         */
	        if ( ! empty( $args['post__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'post_id' => (array) $args['post__not_in'],
				),
			);

			$use_filters = true;
	        }

		/**
		 * Author query support
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['author'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.id' => $args['author'],
				),
			);

			$use_filters = true;
		} elseif ( ! empty( $args['author_name'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.raw' => $args['author'],
				),
			);

			$use_filters = true;
		}

		/**
		 * Add support for post_mime_type
		 *
		 * If we have array, it will be fool text search filter.
		 * If we have string(like filter images in media screen), we will have mime type "image" so need to check it as
		 * regexp filter.
		 *
		 * @since 2.3
		 */
		if( ! empty( $args['post_mime_type'] ) ) {
			if( is_array( $args['post_mime_type'] ) ) {
				$filter['bool']['must'][] = array(
					'terms' => array(
						'post_mime_type' => (array)$args['post_mime_type'],
					),
				);

				$use_filters = true;
			} elseif( is_string( $args['post_mime_type'] ) ) {
				$filter['bool']['must'][] = array(
					'regexp' => array(
						'post_mime_type' => $args['post_mime_type'] . ".*",
					),
				);

				$use_filters = true;
			}
		}

		/**
		 * Simple date params support
		 *
		 * @since 1.3
		 */
		if ( $date_filter = DateQuery::simple_es_date_filter( $args ) ) {
			$filter['bool']['must'][] = $date_filter;
			$use_filters = true;
		}

		/**
		 * 'date_query' arg support.
		 *
		 */
		if ( ! empty( $args['date_query'] ) ) {

			$date_query = new DateQuery( $args['date_query'] );

			$date_filter = $date_query->get_es_filter();

			if( array_key_exists('and', $date_filter ) ) {
				$filter['bool']['must'][] = $date_filter['and'];
				$use_filters = true;
			}

		}

		$meta_queries = [];

		/**
		 * Support meta_key
		 *
		 * @since  2.1
		 */
		if ( ! empty( $args['meta_key'] ) ) {
			if ( ! empty( $args['meta_value'] ) ) {
				$meta_value = $args['meta_value'];
			} elseif ( ! empty( $args['meta_value_num'] ) ) {
				$meta_value = $args['meta_value_num'];
			}

			if ( ! empty( $meta_value ) ) {
				$meta_queries[] = array(
					'key' => $args['meta_key'],
					'value' => $meta_value,
				);
			}
		}

		/**
		 * Todo: Support meta_type
		 */

		/**
		 * 'meta_query' arg support.
		 *
		 * Relation supports 'AND' and 'OR'. 'AND' is the default. For each individual query, the
		 * following 'compare' values are supported: =, !=, EXISTS, NOT EXISTS. '=' is the default.
		 *
		 * @since 1.3
		 */
		if ( ! empty( $args['meta_query'] ) ) {
			$meta_queries = array_merge( $meta_queries, $args['meta_query'] );
		}

		if ( ! empty( $meta_queries ) ) {

			$relation = 'must';
			if ( ! empty( $args['meta_query'] ) && ! empty( $args['meta_query']['relation'] ) && 'or' === strtolower( $args['meta_query']['relation'] ) ) {
				$relation = 'should';
			}

			// get meta query filter
			$meta_filter = $this->build_meta_query( $meta_queries );

			if ( ! empty( $meta_filter ) ) {
				$filter['bool']['must'][]['bool'][$relation] = $meta_filter;

				$use_filters = true;
			}
		}

		/**
		 * Allow for search field specification
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['search_fields'] ) ) {
			$search_field_args = $args['search_fields'];
			$search_fields = [];

			if ( ! empty( $search_field_args['taxonomies'] ) ) {
				$taxes = (array) $search_field_args['taxonomies'];

				foreach ( $taxes as $tax ) {
					$search_fields[] = 'terms.' . $tax . '.name';
				}

				unset( $search_field_args['taxonomies'] );
			}

			if ( ! empty( $search_field_args['meta'] ) ) {
				$metas = (array) $search_field_args['meta'];

				foreach ( $metas as $meta ) {
					$search_fields[] = 'meta.' . $meta . '.value';
				}

				unset( $search_field_args['meta'] );
			}

			if ( in_array( 'author_name', $search_field_args ) ) {
				$search_fields[] = 'post_author.login';

				$author_name_index = array_search( 'author_name', $search_field_args );
				unset( $search_field_args[ $author_name_index ] );
			}

			$search_fields = array_merge( $search_field_args, $search_fields );
		} else {
			$search_fields = array(
				'post_title',
				'post_excerpt',
				'post_content',
			);
		}

		$search_fields = apply_filters( 'ep_search_fields', $search_fields, $args );

		$query = array(
			'bool' => array(
				'should' => array(
					array(
						'multi_match' => array(
							'query' => '',
							'type' => 'phrase',
							'fields' => $search_fields,
							'boost' => apply_filters( 'ep_match_phrase_boost', 4, $search_fields, $args ),
						)
					),
					array(
						'multi_match' => array(
							'query' => '',
							'fields' => $search_fields,
							'boost' => apply_filters( 'ep_match_boost', 2, $search_fields, $args ),
							'fuzziness' => 0,
							'operator' => 'and',
						)
					),
					array(
						'multi_match' => array(
							'fields' => $search_fields,
							'query' => '',
							'fuzziness' => apply_filters( 'ep_fuzziness_arg', 1, $search_fields, $args ),
						),
					)
				),
			),
		);

		/**
		 * We are using ep_integrate instead of ep_match_all. ep_match_all will be
		 * supported for legacy code but may be deprecated and removed eventually.
		 *
		 * @since 1.3
		 */

		if ( ! empty( $args['s'] ) ) {
			$query['bool']['should'][2]['multi_match']['query'] = $args['s'];
			$query['bool']['should'][1]['multi_match']['query'] = $args['s'];
			$query['bool']['should'][0]['multi_match']['query'] = $args['s'];
			$formatted_args['query'] = apply_filters( 'ep_formatted_args_query', $query, $args );
		} else if ( ! empty( $args['ep_match_all'] ) || ! empty( $args['ep_integrate'] ) ) {
			$formatted_args['query']['match_all'] = array(
				'boost' => 1,
			);
		}

		/**
		 * Order by 'rand' support
		 *
		 * Ref: https://github.com/elastic/elasticsearch/issues/1170
		 */
		if ( ! empty( $args['orderby'] ) ) {
			$orderbys = $this->get_orderby_array( $args['orderby'] );
			if( in_array( 'rand', $orderbys ) ) {
				$formatted_args_query = $formatted_args['query'];
				$formatted_args['query'] = [];
				$formatted_args['query']['function_score']['query'] = $formatted_args_query;
				$formatted_args['query']['function_score']['random_score'] = (object) [];
			}
		}

		/**
		 * If not set default to post. If search and not set, default to "any".
		 */
		if ( ! empty( $args['post_type'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_type'] ) {
				$post_types = (array) $args['post_type'];
				$terms_map_name = 'terms';
				if ( count( $post_types ) < 2 ) {
					$terms_map_name = 'term';
					$post_types = $post_types[0];
 				}

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_type.raw' => $post_types,
					),
				);

				$use_filters = true;
			}
		} elseif ( empty( $args['s'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_type.raw' => 'post',
				),
			);

			$use_filters = true;
		}

		/**
		 * Like WP_Query in search context, if no post_status is specified we default to "any". To
		 * be safe you should ALWAYS specify the post_status parameter UNLIKE with WP_Query.
		 *
		 * @since 2.1
		 */
		if ( ! empty( $args['post_status'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_status'] ) {
				$post_status = (array) ( is_string( $args['post_status'] ) ? explode( ',', $args['post_status'] ) : $args['post_status'] );
				$post_status = array_map( 'trim', $post_status );
				$terms_map_name = 'terms';
				if ( count( $post_status ) < 2 ) {
					$terms_map_name = 'term';
					$post_status = $post_status[0];
 				}

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_status' => $post_status,
					),
				);

				$use_filters = true;
			}
		} else {
			$statuses = get_post_stati( array( 'public' => true ) );

			if ( is_admin() ) {
				/**
				 * In the admin we will add protected and private post statuses to the default query
				 * per WP default behavior.
				 */
				$statuses = array_merge( $statuses, get_post_stati( array( 'protected' => true, 'show_in_admin_all_list' => true ) ) );

				if ( is_user_logged_in() ) {
					$statuses = array_merge( $statuses, get_post_stati( array( 'private' => true ) ) );
				}
			}

			$statuses = array_values( $statuses );

			$post_status_filter_type = 'terms';

			if ( 1 === count( $statuses ) ) {
				$post_status_filter_type = 'term';
				$statuses = $statuses[0];
			}

			$filter['bool']['must'][] = array(
				$post_status_filter_type => array(
					'post_status' => $statuses,
				),
			);

			$use_filters = true;
		}

		if ( isset( $args['offset'] ) ) {
			$formatted_args['from'] = $args['offset'];
		}

		if ( isset( $args['paged'] ) && $args['paged'] > 1 ) {
			$formatted_args['from'] = $args['posts_per_page'] * ( $args['paged'] - 1 );
		}

		if ( $use_filters ) {
			$formatted_args['post_filter'] = $filter;
		}

		/**
		 * Support fields.
		 */
		if ( isset( $args['fields'] ) ) {
			switch ( $args['fields'] ) {
				case 'ids':
					$formatted_args['_source'] = array(
						'include' => array(
							'post_id',
						),
					);
					break;

				case 'id=>parent':
					$formatted_args['_source'] = array(
						'include' => array(
							'post_id',
							'post_parent',
						),
					);
					break;
			}
		}

		/**
		 * Aggregations
		 */
		if ( isset( $args['aggs'] ) && ! empty( $args['aggs']['aggs'] ) ) {
			$agg_obj = $args['aggs'];

			// Add a name to the aggregation if it was passed through
			if ( ! empty( $agg_obj['name'] ) ) {
				$agg_name = $agg_obj['name'];
			} else {
				$agg_name = 'aggregation_name';
			}

			// Add/use the filter if warranted
			if ( isset( $agg_obj['use-filter'] ) && false !== $agg_obj['use-filter'] && $use_filters ) {

				// If a filter is being used, use it on the aggregation as well to receive relevant information to the query
				$formatted_args['aggs'][ $agg_name ]['filter'] = $filter;
				$formatted_args['aggs'][ $agg_name ]['aggs'] = $agg_obj['aggs'];
			} else {
				$formatted_args['aggs'][ $agg_name ] = $agg_obj['aggs'];
			}
		}

		$formatted_args = apply_filters( 'ep_formatted_args', $formatted_args, $args );

		return apply_filters( 'ep_post_formatted_args', $formatted_args, $args );
	}

	/**
	 * Build Elasticsearch filter query for WP meta_query
	 *
	 * @since 2.2
	 *
	 * @param $meta_queries
	 *
	 * @return array
	 */
	public function build_meta_query( $meta_queries ){
		$meta_filter = [];

		if ( ! empty( $meta_queries ) ) {

			$meta_query_type_mapping = array(
				'numeric'  => 'long',
				'binary'   => 'raw',
				'char'     => 'raw',
				'date'     => 'date',
				'datetime' => 'datetime',
				'decimal'  => 'double',
				'signed'   => 'long',
				'time'     => 'time',
				'unsigned' => 'long',
			);

			foreach( $meta_queries as $single_meta_query ) {

				/**
				 * There is a strange case where meta_query looks like this:
				 * array(
				 * 	"something" => array(
				 * 	 array(
				 * 	 	'key' => ...
				 * 	 	...
				 * 	 )
				 * 	)
				 * )
				 *
				 * Somehow WordPress (WooCommerce) handles that case so we need to as well.
				 *
				 * @since  2.1
				 */
				if ( is_array( $single_meta_query ) && empty( $single_meta_query['key'] ) ) {
					reset( $single_meta_query );
					$first_key = key( $single_meta_query );

					if ( is_array( $single_meta_query[$first_key] ) ) {
						$single_meta_query = $single_meta_query[$first_key];
					}
				}

				if ( ! empty( $single_meta_query['key'] ) ) {

					$terms_obj = false;

					$compare = '=';
					if ( ! empty( $single_meta_query['compare'] ) ) {
						$compare = strtolower( $single_meta_query['compare'] );
					}

					$type = null;
					if ( ! empty( $single_meta_query['type'] ) ) {
						$type = strtolower( $single_meta_query['type'] );
					}

					// Comparisons need to look at different paths
					if ( in_array( $compare, array( 'exists', 'not exists' ) ) ) {
						$meta_key_path = 'meta.' . $single_meta_query['key'];
					} elseif ( in_array( $compare, array( '=', '!=' ) ) && ! $type ) {
						$meta_key_path = 'meta.' . $single_meta_query['key'] . '.raw';
					} elseif ( 'like' === $compare ) {
						$meta_key_path = 'meta.' . $single_meta_query['key'] . '.value';
					} elseif ( $type && isset( $meta_query_type_mapping[ $type ] ) ) {
						// Map specific meta field types to different Elasticsearch core types
						$meta_key_path = 'meta.' . $single_meta_query['key'] . '.' . $meta_query_type_mapping[ $type ];
					} elseif ( in_array( $compare, array( '>=', '<=', '>', '<', 'between', 'not between' ) ) ) {
						$meta_key_path = 'meta.' . $single_meta_query['key'] . '.double';
					} else {
						$meta_key_path = 'meta.' . $single_meta_query['key'] . '.raw';
					}

					switch ( $compare ) {
						case 'not in':
						case '!=':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must_not' => array(
											array(
												'terms' => array(
													$meta_key_path => (array) $single_meta_query['value'],
												),
											),
										),
									),
								);
							}

							break;
						case 'exists':
							$terms_obj = array(
								'exists' => array(
									'field' => $meta_key_path,
								),
							);

							break;
						case 'not exists':
							$terms_obj = array(
								'bool' => array(
									'must_not' => array(
										array(
											'exists' => array(
												'field' => $meta_key_path,
											),
										),
									),
								),
							);

							break;
						case '>=':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														"gte" => $single_meta_query['value'],
													),
												),
											),
										),
									),
								);
							}

							break;
						case 'between':
							if ( isset( $single_meta_query['value'] ) && is_array( $single_meta_query['value'] ) && 2 === count( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														"gte" => $single_meta_query['value'][0],
													),
												),
											),
											array(
												'range' => array(
													$meta_key_path => array(
														"lte" => $single_meta_query['value'][1],
													),
												),
											),
										),
									),
								);
							}

							break;
						case 'not between':
							if ( isset( $single_meta_query['value'] ) && is_array( $single_meta_query['value'] ) && 2 === count( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'should' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														"lte" => $single_meta_query['value'][0],
													),
												),
											),
											array(
												'range' => array(
													$meta_key_path => array(
														"gte" => $single_meta_query['value'][1],
													),
												),
											),
										),
									),
								);
							}

							break;
						case '<=':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														'lte' => $single_meta_query['value'],
													),
												),
											),
										),
									),
								);
							}

							break;
						case '>':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														'gt' => $single_meta_query['value'],
													),
												),
											),
										),
									),
								);
							}

							break;
						case '<':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'bool' => array(
										'must' => array(
											array(
												'range' => array(
													$meta_key_path => array(
														'lt' => $single_meta_query['value'],
													),
												),
											),
										),
									),
								);
							}

							break;
						case 'like':
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'query' => array(
										'match' => array(
											$meta_key_path => $single_meta_query['value'],
										)
									),
								);
							}
							break;
						case '=':
						default:
							if ( isset( $single_meta_query['value'] ) ) {
								$terms_obj = array(
									'terms' => array(
										$meta_key_path => (array) $single_meta_query['value'],
									),
								);
							}

							break;
					}

					// Add the meta query filter
					if ( false !== $terms_obj ) {
						$meta_filter[] = $terms_obj;
					}
				} elseif ( is_array( $single_meta_query ) && isset( $single_meta_query[0] ) && is_array( $single_meta_query[0] ) ) {
					/*
					 * Handle multidimensional array. Something like:
					 *
					 * 'meta_query' => array(
					 *      'relation' => 'AND',
					 *      array(
					 *          'key' => 'meta_key_1',
					 *          'value' => '1',
					 *      ),
					 *      array(
					 *          'relation' => 'OR',
					 *          array(
					 *              'key' => 'meta_key_2',
					 *              'value' => '2',
					 *          ),
					 *          array(
					 *              'key' => 'meta_key_3',
					 *              'value' => '4',
					 *          ),
					 *      ),
					 *  ),
					 */
					$relation = 'must';
					if ( ! empty( $single_meta_query['relation'] ) && 'or' === strtolower( $single_meta_query['relation'] ) ) {
						$relation = 'should';
					}

					$meta_filter[] = array(
						'bool' => array(
							$relation => $this->build_meta_query( $single_meta_query ),
						),
					);
				}
			}
		}

		return $meta_filter;
	}

	/**
	 * Parse an 'order' query variable and cast it to ASC or DESC as necessary.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	protected function parse_order( $order ) {
		if ( ! is_string( $order ) || empty( $order ) ) {
			return 'desc';
		}

		if ( 'ASC' === strtoupper( $order ) ) {
			return 'asc';
		} else {
			return 'desc';
		}
	}

	/**
	 * Convert the alias to a properly-prefixed sort value.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $orderbys Alias or path for the field to order by.
	 * @param string $order
	 * @param  array $args
	 * @return array
	 */
	protected function parse_orderby( $orderbys, $default_order, $args ) {
		$orderbys = $this->get_orderby_array( $orderbys );

		$sort = [];

		foreach ( $orderbys as $key => $value ) {
			if ( is_string( $key ) ) {
				$orderby_clause = $key;
				$order = $value;
			} else {
				$orderby_clause = $value;
				$order = $default_order;
			}

			if ( ! empty( $orderby_clause ) && 'rand' !== $orderby_clause ) {
				if ( 'relevance' === $orderby_clause ) {
					$sort[] = array(
						'_score' => array(
							'order' => $order,
						),
					);
		 		} elseif ( 'date' === $orderby_clause ) {
					$sort[] = array(
						'post_date' => array(
							'order' => $order,
						),
					);
				} elseif ( 'type' === $orderby_clause ) {
					$sort[] = array(
						'post_type' => array(
							'order' => $order,
						),
					);
				} elseif ( 'modified' === $orderby_clause ) {
					$sort[] = array(
						'post_modified' => array(
							'order' => $order,
						),
					);
				} elseif ( 'name' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.raw' => array(
							'order' => $order,
						),
					);
				} elseif ( 'title' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.sortable' => array(
							'order' => $order,
						),
					);
				} elseif ( 'meta_value' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.value' => array(
								'order' => $order,
							),
						);
					}
				} elseif ( 'meta_value_num' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.long' => array(
								'order' => $order,
							),
						);
					}
				} else {
					$sort[] = array(
						$orderby_clause => array(
							'order' => $order,
						),
					);
				}
			}
		}

		return $sort;
	}

	/**
	 * Get Order by args Array
	 *
	 * @param $orderbys
	 *
	 * @since 2.1
	 * @return array
	 */
	protected function get_orderby_array( $orderbys ){
		if ( ! is_array( $orderbys ) ) {
			$orderbys = explode( ' ', $orderbys );
		}

		return $orderbys;
	}

	public function bulk_index( $body ) {
		return Elasticsearch::factory()->bulk_index( $this->get_index_name(), 'post', $body );
	}

	/**
	 * Return singleton instance of class
	 *
	 * @return Elasticsearch
	 * @since 0.1.0
	 */
	public static function factory() {
		static $instance = false;

		if ( ! $instance  ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Check to see if we should allow elasticpress to override this query
	 *
	 * @param $query
	 * @return bool
	 * @since 0.9.2
	 */
	public function elasticpress_enabled( $query ) {
		$enabled = false;

		if ( ! empty( $query->query_vars['ep_match_all'] ) ) { // ep_match_all is supported for legacy reasons
			$enabled = true;
		} elseif ( ! empty( $query->query_vars['ep_integrate'] ) ) {
			$enabled = true;
		}

		return apply_filters( 'ep_elasticpress_enabled', $enabled, $query );
	}
}

<?php
/**
 * REST API Product Reviews Controller
 *
 * Handles requests to /products/<product_id>/reviews.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\RestApi\Controllers\Version4\Responses\ProductReviewResponse;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Permissions;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Pagination;

/**
 * REST API Product Reviews controller class.
 */
class ProductReviews extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products/reviews';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'product_reviews';

	/**
	 * Register the routes for product reviews.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
						array(
							'product_id'     => array(
								'required'    => true,
								'description' => __( 'Unique identifier for the product.', 'woocommerce-rest-api' ),
								'type'        => 'integer',
							),
							'review'         => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'Review content.', 'woocommerce-rest-api' ),
							),
							'reviewer'       => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'Name of the reviewer.', 'woocommerce-rest-api' ),
							),
							'reviewer_email' => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'Email of the reviewer.', 'woocommerce-rest-api' ),
							),
						)
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => __( 'Whether to bypass trash and force deletion.', 'woocommerce-rest-api' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		$this->register_batch_route();
	}

	/**
	 * Get all reviews.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return array|\WP_Error
	 */
	public function get_items( $request ) {
		// Retrieve the list of registered collection query parameters.
		$registered = $this->get_collection_params();

		/*
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal \WP_Query parameter
		 * name equivalents (some are the same). Only values which are also
		 * present in $registered will be set.
		 */
		$parameter_mappings = array(
			'reviewer'         => 'author__in',
			'reviewer_email'   => 'author_email',
			'reviewer_exclude' => 'author__not_in',
			'exclude'          => 'comment__not_in',
			'include'          => 'comment__in',
			'offset'           => 'offset',
			'order'            => 'order',
			'per_page'         => 'number',
			'product'          => 'post__in',
			'search'           => 'search',
			'status'           => 'status',
		);

		$prepared_args = array();

		/*
		 * For each known parameter which is both registered and present in the request,
		 * set the parameter's value on the query $prepared_args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( isset( $registered[ $api_param ], $request[ $api_param ] ) ) {
				$prepared_args[ $wp_param ] = $request[ $api_param ];
			}
		}

		// Ensure certain parameter values default to empty strings.
		foreach ( array( 'author_email', 'search' ) as $param ) {
			if ( ! isset( $prepared_args[ $param ] ) ) {
				$prepared_args[ $param ] = '';
			}
		}

		if ( isset( $registered['orderby'] ) ) {
			$prepared_args['orderby'] = $this->normalize_query_param( $request['orderby'] );
		}

		if ( isset( $prepared_args['status'] ) ) {
			$prepared_args['status'] = 'approved' === $prepared_args['status'] ? 'approve' : $prepared_args['status'];
		}

		$prepared_args['no_found_rows'] = false;
		$prepared_args['date_query']    = array();

		// Set before into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['before'], $request['before'] ) ) {
			$prepared_args['date_query'][0]['before'] = $request['before'];
		}

		// Set after into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['after'], $request['after'] ) ) {
			$prepared_args['date_query'][0]['after'] = $request['after'];
		}

		if ( isset( $registered['page'] ) && empty( $request['offset'] ) ) {
			$prepared_args['offset'] = $prepared_args['number'] * ( absint( $request['page'] ) - 1 );
		}

		/**
		 * Filters arguments, before passing to \WP_Comment_Query, when querying reviews via the REST API.
		 *
		 * @since 3.5.0
		 * @link https://developer.wordpress.org/reference/classes/\WP_Comment_Query/
		 * @param array           $prepared_args Array of arguments for \WP_Comment_Query.
		 * @param \WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_product_review_query', $prepared_args, $request );

		// Make sure that returns only reviews.
		$prepared_args['type'] = 'review';

		// Query reviews.
		$query        = new \WP_Comment_Query();
		$query_result = $query->query( $prepared_args );
		$reviews      = array();

		foreach ( $query_result as $review ) {
			if ( ! Permissions::user_can_read( 'product_review', $review->comment_ID ) ) {
				continue;
			}

			$data      = $this->prepare_item_for_response( $review, $request );
			$reviews[] = $this->prepare_response_for_collection( $data );
		}

		$total_reviews = (int) $query->found_comments;
		$max_pages     = (int) $query->max_num_pages;

		if ( $total_reviews < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $prepared_args['number'], $prepared_args['offset'] );

			$query                  = new \WP_Comment_Query();
			$prepared_args['count'] = true;

			$total_reviews = $query->query( $prepared_args );
			$max_pages     = ceil( $total_reviews / $request['per_page'] );
		}

		$response = rest_ensure_response( $reviews );
		$response = Pagination::add_pagination_headers( $response, $request, $total_reviews, $max_pages );

		return $response;
	}

	/**
	 * Create a single review.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			if ( ! empty( $request['id'] ) ) {
				return new \WP_Error( 'woocommerce_rest_review_exists', __( 'Cannot create existing product review.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}

			$prepared_review = wp_parse_args(
				$this->prepare_item_for_database( $request ),
				array(
					'comment_post_ID'    => 0,
					'comment_parent'     => 0,
					'comment_author_url' => '',
					'comment_date_gmt'   => current_time( 'mysql', true ),
					'comment_author_IP'  => $this->get_comment_author_ip(),
					'comment_agent'      => $this->get_comment_agent( $request ),
				)
			);

			/**
			 * Filters a review after it is prepared for the database.
			 *
			 * Allows modification of the review right after it is prepared for the database.
			 *
			 * @since 3.5.0
			 * @param array           $prepared_review The prepared review data for `wp_insert_comment`.
			 * @param \WP_REST_Request $request         The current request.
			 */
			$prepared_review = apply_filters( 'woocommerce_rest_preprocess_product_review', $prepared_review, $request );

			if ( is_wp_error( $prepared_review ) ) {
				return $prepared_review;
			}

			$prepared_review = $this->validate_review( $prepared_review, true );

			/**
			 * Filters a review before it is inserted via the REST API.
			 *
			 * Allows modification of the review right before it is inserted via wp_insert_comment().
			 * Returning a \WP_Error value from the filter will shortcircuit insertion and allow
			 * skipping further processing.
			 *
			 * @since 3.5.0
			 * @param array|\WP_Error  $prepared_review The prepared review data for wp_insert_comment().
			 * @param \WP_REST_Request $request          Request used to insert the review.
			 */
			$prepared_review = apply_filters( 'woocommerce_rest_pre_insert_product_review', $prepared_review, $request );

			if ( is_wp_error( $prepared_review ) ) {
				return $prepared_review;
			}

			$review_id = wp_insert_comment( wp_filter_comment( wp_slash( $prepared_review ) ) );

			if ( ! $review_id ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_review_failed_create', __( 'Creating product review failed.', 'woocommerce-rest-api' ), 500 );
			}

			if ( isset( $request['status'] ) ) {
				$this->handle_status_param( $request['status'], $review_id );
			}

			update_comment_meta( $review_id, 'rating', ! empty( $request['rating'] ) ? $request['rating'] : '0' );
		} catch ( \WC_REST_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		$review = get_comment( $review_id );

		/**
		 * Fires after a comment is created or updated via the REST API.
		 *
		 * @param \WP_Comment      $review   Inserted or updated comment object.
		 * @param \WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating a comment, false when updating.
		 */
		do_action( 'woocommerce_rest_insert_product_review', $review, $request, true );

		$fields_update = $this->update_additional_fields_for_object( $review, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		$context = current_user_can( 'moderate_comments' ) ? 'edit' : 'view';
		$request->set_param( 'context', $context );

		$response = $this->prepare_item_for_response( $review, $request );

		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $review_id ) ) );

		return $response;
	}

	/**
	 * Validate a review and throw an error if invalid.
	 *
	 * @throws \WC_REST_Exception Exception when a comment is not approved.
	 * @param  array $prepared_review Review content.
	 * @param  bool  $creating True when creating a review.
	 * @return array
	 */
	protected function validate_review( $prepared_review, $creating = false ) {
		if ( empty( $prepared_review['comment_content'] ) ) {
			throw new \WC_REST_Exception( 'woocommerce_rest_review_content_invalid', __( 'Invalid review content.', 'woocommerce-rest-api' ), 400 );
		}

		if ( ! empty( $prepared_review['comment_post_ID'] ) ) {
			if ( 'product' !== get_post_type( $prepared_review['comment_post_ID'] ) ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_product_invalid_id', __( 'Invalid product ID.', 'woocommerce-rest-api' ), 404 );
			}
		}

		$check_comment_lengths = wp_check_comment_data_max_lengths( $prepared_review );

		if ( is_wp_error( $check_comment_lengths ) ) {
			$error_code = str_replace( array( 'comment_author', 'comment_content' ), array( 'reviewer', 'review_content' ), $check_comment_lengths->get_error_code() );
			throw new \WC_REST_Exception( 'woocommerce_rest_' . $error_code, __( 'Product review field exceeds maximum length allowed.', 'woocommerce-rest-api' ), 400 );
		}

		if ( $creating ) {
			$prepared_review['comment_approved'] = $this->get_comment_approved( $prepared_review );
		}

		return $prepared_review;
	}

	/**
	 * Get comment user agent.
	 *
	 * @return string
	 */
	protected function get_comment_author_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) && rest_is_ip_address( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) { // WPCS: input var ok, sanitization ok.
			return wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // WPCS: input var ok.
		} else {
			return '127.0.0.1';
		}
	}

	/**
	 * Get comment user agent from request.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return string
	 */
	protected function get_comment_agent( $request ) {
		if ( ! empty( $request['author_user_agent'] ) ) {
			return $request['author_user_agent'];
		} elseif ( $request->get_header( 'user_agent' ) ) {
			return $request->get_header( 'user_agent' );
		} else {
			return '';
		}
	}

	/**
	 * Attempt to approve an unsaved comment or throw an error.
	 *
	 * @throws \WC_REST_Exception Exception when a comment is not approved.
	 * @param  array $prepared_review Review content.
	 * @return int|string
	 */
	protected function get_comment_approved( $prepared_review ) {
		$comment_approved = wp_allow_comment( $prepared_review, true );

		if ( is_wp_error( $comment_approved ) ) {
			$error_code    = $comment_approved->get_error_code();
			$error_message = $comment_approved->get_error_message();

			if ( 'comment_duplicate' === $error_code ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_' . $error_code, $error_message, 409 );
			}

			if ( 'comment_flood' === $error_code ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_' . $error_code, $error_message, 400 );
			}
		}

		return $comment_approved;
	}

	/**
	 * Get a single product review.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$review = $this->get_review( $request['id'] );
		if ( is_wp_error( $review ) ) {
			return $review;
		}

		return $this->prepare_item_for_response( $review, $request );
	}

	/**
	 * Updates a review.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response Response object on success, or error object on failure.
	 */
	public function update_item( $request ) {
		try {
			$review = $this->get_review( $request['id'] );

			if ( is_wp_error( $review ) ) {
				return $review;
			}

			$review_id = (int) $review->comment_ID;

			if ( isset( $request['type'] ) && 'review' !== get_comment_type( $review_id ) ) {
				return new \WP_Error( 'woocommerce_rest_review_invalid_type', __( 'Sorry, you are not allowed to change the comment type.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
			}

			$prepared_review = $this->prepare_item_for_database( $request );

			/**
			 * Filters a review after it is prepared for the database.
			 *
			 * Allows modification of the review right after it is prepared for the database.
			 *
			 * @since 3.5.0
			 * @param array           $prepared_review The prepared review data for `wp_insert_comment`.
			 * @param \WP_REST_Request $request         The current request.
			 */
			$prepared_review = apply_filters( 'woocommerce_rest_preprocess_product_review', $prepared_review, $request );

			if ( is_wp_error( $prepared_review ) ) {
				return $prepared_review;
			}

			$prepared_review = $this->validate_review( $prepared_review );

			wp_update_comment( wp_slash( $prepared_review ) );

			if ( isset( $request['status'] ) ) {
				$this->handle_status_param( $request['status'], $review_id );
			}

			if ( ! empty( $request['rating'] ) ) {
				update_comment_meta( $review_id, 'rating', $request['rating'] );
			}
		} catch ( \WC_REST_Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		$review = get_comment( $review_id );

		/**
		 * Fires after a comment is created or updated via the REST API.
		 *
		 * @param \WP_Comment      $review   Inserted or updated comment object.
		 * @param \WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating a comment, false when updating.
		 */
		do_action( 'woocommerce_rest_insert_product_review', $review, $request, false );

		$fields_update = $this->update_additional_fields_for_object( $review, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		$request->set_param( 'context', 'edit' );

		return $this->prepare_item_for_response( $review, $request );
	}

	/**
	 * Deletes a review.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response Response object on success, or error object on failure.
	 */
	public function delete_item( $request ) {
		$review = $this->get_review( $request['id'] );
		if ( is_wp_error( $review ) ) {
			return $review;
		}

		$force = isset( $request['force'] ) ? (bool) $request['force'] : false;

		/**
		 * Filters whether a review can be trashed.
		 *
		 * Return false to disable trash support for the post.
		 *
		 * @since 3.5.0
		 * @param bool       $supports_trash Whether the post type support trashing.
		 * @param WP_Comment $review         The review object being considered for trashing support.
		 */
		$supports_trash = apply_filters( 'woocommerce_rest_product_review_trashable', ( EMPTY_TRASH_DAYS > 0 ), $review );

		$request->set_param( 'context', 'edit' );

		if ( $force ) {
			$previous = $this->prepare_item_for_response( $review, $request );
			$result   = wp_delete_comment( $review->comment_ID, true );
			$response = new \WP_REST_Response();
			$response->set_data(
				array(
					'deleted'  => true,
					'previous' => $previous->get_data(),
				)
			);
		} else {
			// If this type doesn't support trashing, error out.
			if ( ! $supports_trash ) {
				/* translators: %s: force=true */
				return new \WP_Error( 'woocommerce_rest_trash_not_supported', sprintf( __( "The object does not support trashing. Set '%s' to delete.", 'woocommerce-rest-api' ), 'force=true' ), array( 'status' => 501 ) );
			}

			if ( 'trash' === $review->comment_approved ) {
				return new \WP_Error( 'woocommerce_rest_already_trashed', __( 'The object has already been trashed.', 'woocommerce-rest-api' ), array( 'status' => 410 ) );
			}

			$result   = wp_trash_comment( $review->comment_ID );
			$review   = get_comment( $review->comment_ID );
			$response = $this->prepare_item_for_response( $review, $request );
		}

		if ( ! $result ) {
			return new \WP_Error( 'woocommerce_rest_cannot_delete', __( 'The object cannot be deleted.', 'woocommerce-rest-api' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a review is deleted via the REST API.
		 *
		 * @param WP_Comment       $review   The deleted review data.
		 * @param \WP_REST_Response $response The response returned from the API.
		 * @param \WP_REST_Request  $request  The request sent to the API.
		 */
		do_action( 'woocommerce_rest_delete_review', $review, $response, $request );

		return $response;
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WP_Comment      $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		$formatter = new ProductReviewResponse();

		return $formatter->prepare_response( $object, $this->get_request_context( $request ) );
	}

	/**
	 * Prepare a single product review to be inserted into the database.
	 *
	 * @param  \WP_REST_Request $request Request object.
	 * @return array  $prepared_review
	 */
	protected function prepare_item_for_database( $request ) {
		$mappings = [
			'comment_ID'           => 'id',
			'comment_content'      => 'review',
			'comment_post_ID'      => 'product_id',
			'comment_author'       => 'reviewer',
			'comment_author_email' => 'reviewer_email',
		];

		$prepared_review = [
			'comment_type' => 'review',
		];

		foreach ( $mappings as $key => $value ) {
			if ( ! isset( $request[ $value ] ) ) {
				continue;
			}
			$prepared_review[ $key ] = $request[ $value ];
		}

		if ( ! empty( $request['date_created'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['date_created'] );

			if ( ! empty( $date_data ) ) {
				list( $prepared_review['comment_date'], $prepared_review['comment_date_gmt'] ) = $date_data;
			}
		} elseif ( ! empty( $request['date_created_gmt'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['date_created_gmt'], true );

			if ( ! empty( $date_data ) ) {
				list( $prepared_review['comment_date'], $prepared_review['comment_date_gmt'] ) = $date_data;
			}
		}

		return $prepared_review;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self'       => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $item->comment_ID ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);
		if ( 0 !== (int) $item->comment_post_ID ) {
			$links['up'] = array(
				'href'       => rest_url( sprintf( '/%s/products/%d', $this->namespace, $item->comment_post_ID ) ),
				'embeddable' => true,
			);
		}
		if ( 0 !== (int) $item->user_id ) {
			$links['reviewer'] = array(
				'href'       => rest_url( 'wp/v2/users/' . $item->user_id ),
				'embeddable' => true,
			);
		}
		return $links;
	}

	/**
	 * Get the Product Review's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'product_review',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created'     => array(
					'description' => __( "The date the review was created, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt' => array(
					'description' => __( 'The date the review was created, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'product_id'       => array(
					'description' => __( 'Unique identifier for the product that the review belongs to.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'status'           => array(
					'description' => __( 'Status of the review.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'default'     => 'approved',
					'enum'        => array( 'approved', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
					'context'     => array( 'view', 'edit' ),
				),
				'reviewer'         => array(
					'description' => __( 'Reviewer name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'reviewer_email'   => array(
					'description' => __( 'Reviewer email.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => array( 'view', 'edit' ),
				),
				'review'           => array(
					'description' => __( 'The content of the review.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'wp_filter_post_kses',
					),
				),
				'rating'           => array(
					'description' => __( 'Review rating (0 to 5).', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'verified'         => array(
					'description' => __( 'Shows if the reviewer bought the product or not.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		if ( get_option( 'show_avatars' ) ) {
			$avatar_properties = array();
			$avatar_sizes      = rest_get_avatar_sizes();

			foreach ( $avatar_sizes as $size ) {
				$avatar_properties[ $size ] = array(
					/* translators: %d: avatar image size in pixels */
					'description' => sprintf( __( 'Avatar URL with image size of %d pixels.', 'woocommerce-rest-api' ), $size ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'embed', 'view', 'edit' ),
				);
			}
			$schema['properties']['reviewer_avatar_urls'] = array(
				'description' => __( 'Avatar URLs for the object reviewer.', 'woocommerce-rest-api' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => $avatar_properties,
			);
		}

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['context']['default'] = 'view';

		$params['after']            = array(
			'description' => __( 'Limit response to resources published after a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'        => 'string',
			'format'      => 'date-time',
		);
		$params['before']           = array(
			'description' => __( 'Limit response to reviews published before a given ISO8601 compliant date.', 'woocommerce-rest-api' ),
			'type'        => 'string',
			'format'      => 'date-time',
		);
		$params['exclude']          = array(
			'description' => __( 'Ensure result set excludes specific IDs.', 'woocommerce-rest-api' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer',
			),
			'default'     => array(),
		);
		$params['include']          = array(
			'description' => __( 'Limit result set to specific IDs.', 'woocommerce-rest-api' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer',
			),
			'default'     => array(),
		);
		$params['offset']           = array(
			'description' => __( 'Offset the result set by a specific number of items.', 'woocommerce-rest-api' ),
			'type'        => 'integer',
		);
		$params['order']            = array(
			'description' => __( 'Order sort attribute ascending or descending.', 'woocommerce-rest-api' ),
			'type'        => 'string',
			'default'     => 'desc',
			'enum'        => array(
				'asc',
				'desc',
			),
		);
		$params['orderby']          = array(
			'description' => __( 'Sort collection by object attribute.', 'woocommerce-rest-api' ),
			'type'        => 'string',
			'default'     => 'date_gmt',
			'enum'        => array(
				'date',
				'date_gmt',
				'id',
				'include',
				'product',
			),
		);
		$params['reviewer']         = array(
			'description' => __( 'Limit result set to reviews assigned to specific user IDs.', 'woocommerce-rest-api' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer',
			),
		);
		$params['reviewer_exclude'] = array(
			'description' => __( 'Ensure result set excludes reviews assigned to specific user IDs.', 'woocommerce-rest-api' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer',
			),
		);
		$params['reviewer_email']   = array(
			'default'     => null,
			'description' => __( 'Limit result set to that from a specific author email.', 'woocommerce-rest-api' ),
			'format'      => 'email',
			'type'        => 'string',
		);
		$params['product']          = array(
			'default'     => array(),
			'description' => __( 'Limit result set to reviews assigned to specific product IDs.', 'woocommerce-rest-api' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'integer',
			),
		);
		$params['status']           = array(
			'default'           => 'approved',
			'description'       => __( 'Limit result set to reviews assigned a specific status.', 'woocommerce-rest-api' ),
			'sanitize_callback' => 'sanitize_key',
			'type'              => 'string',
			'enum'              => array(
				'all',
				'hold',
				'approved',
				'spam',
				'trash',
			),
		);

		/**
		 * Filter collection parameters for the reviews controller.
		 *
		 * This filter registers the collection parameter, but does not map the
		 * collection parameter to an internal \WP_Comment_Query parameter. Use the
		 * `wc_rest_review_query` filter to set \WP_Comment_Query parameters.
		 *
		 * @since 3.5.0
		 * @param array $params JSON Schema-formatted collection parameters.
		 */
		return apply_filters( 'woocommerce_rest_product_review_collection_params', $params );
	}

	/**
	 * Get the reivew, if the ID is valid.
	 *
	 * @since 3.5.0
	 * @param int $id Supplied ID.
	 * @return WP_Comment|\WP_Error Comment object if ID is valid, \WP_Error otherwise.
	 */
	protected function get_review( $id ) {
		$id    = (int) $id;
		$error = new \WP_Error( 'woocommerce_rest_review_invalid_id', __( 'Invalid review ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );

		if ( 0 >= $id ) {
			return $error;
		}

		$review = get_comment( $id );
		if ( empty( $review ) ) {
			return $error;
		}

		if ( ! empty( $review->comment_post_ID ) ) {
			$post = get_post( (int) $review->comment_post_ID );

			if ( 'product' !== get_post_type( (int) $review->comment_post_ID ) ) {
				return new \WP_Error( 'woocommerce_rest_product_invalid_id', __( 'Invalid product ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
			}
		}

		return $review;
	}

	/**
	 * Prepends internal property prefix to query parameters to match our response fields.
	 *
	 * @since 3.5.0
	 * @param string $query_param Query parameter.
	 * @return string
	 */
	protected function normalize_query_param( $query_param ) {
		$prefix = 'comment_';

		switch ( $query_param ) {
			case 'id':
				$normalized = $prefix . 'ID';
				break;
			case 'product':
				$normalized = $prefix . 'post_ID';
				break;
			case 'include':
				$normalized = 'comment__in';
				break;
			default:
				$normalized = $prefix . $query_param;
				break;
		}

		return $normalized;
	}



	/**
	 * Sets the comment_status of a given review object when creating or updating a review.
	 *
	 * @since 3.5.0
	 * @param string|int $new_status New review status.
	 * @param int        $id         Review ID.
	 * @return bool Whether the status was changed.
	 */
	protected function handle_status_param( $new_status, $id ) {
		$old_status = wp_get_comment_status( $id );

		if ( $new_status === $old_status ) {
			return false;
		}

		switch ( $new_status ) {
			case 'approved':
			case 'approve':
			case '1':
				$changed = wp_set_comment_status( $id, 'approve' );
				break;
			case 'hold':
			case '0':
				$changed = wp_set_comment_status( $id, 'hold' );
				break;
			case 'spam':
				$changed = wp_spam_comment( $id );
				break;
			case 'unspam':
				$changed = wp_unspam_comment( $id );
				break;
			case 'trash':
				$changed = wp_trash_comment( $id );
				break;
			case 'untrash':
				$changed = wp_untrash_comment( $id );
				break;
			default:
				$changed = false;
				break;
		}

		return $changed;
	}

	/**
	 * Check if a given request has access to read a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		$id          = $request->get_param( 'id' );
		$check_valid = $this->get_review( $id );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to delete an item.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function delete_item_permissions_check( $request ) {
		$id          = $request->get_param( 'id' );
		$check_valid = $this->get_review( $id );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::delete_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to update an item.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function update_item_permissions_check( $request ) {
		$id          = $request->get_param( 'id' );
		$check_valid = $this->get_review( $id );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::update_item_permissions_check( $request );
	}
}

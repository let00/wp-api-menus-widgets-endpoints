<?php

/**
 * Class WP_REST_Menu_Items_Controller
 */
class WP_REST_Menu_Items_Controller extends WP_REST_Posts_Controller {

	/**
	 * Get the post, if the ID is valid.
	 *
	 *
	 * @param int $id Supplied ID.
	 *
	 * @return WP_Post|WP_Error Post object if ID is valid, WP_Error otherwise.
	 */
	protected function get_post( $id ) {
		$post = parent::get_post( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$nav_item = wp_setup_nav_menu_item( $post );

		return $nav_item;
	}

	/**
	 * Creates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 * @since 4.7.0
	 *
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error( 'rest_post_exists', __( 'Cannot create existing post.' ), array( 'status' => 400 ) );
		}

		$prepared_post = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		$menu_id = (int) $request['menu_id'];

		$post_id = wp_update_nav_menu_item( $menu_id, $request['id'], $prepared_post );

		if ( is_wp_error( $post_id ) ) {

			if ( 'db_insert_error' === $post_id->get_error_code() ) {
				$post_id->add_data( array( 'status' => 500 ) );
			} else {
				$post_id->add_data( array( 'status' => 400 ) );
			}

			return $post_id;
		}

		$post = get_post( $post_id );

		/**
		 * Fires after a single post is created or updated via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @param WP_Post         $post     Inserted or updated post object.
		 * @param WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating a post, false when updating.
		 *
		 * @since 4.7.0
		 *
		 */
		do_action( "rest_insert_{$this->post_type}", $post, $request, true );

		$schema = $this->get_item_schema();

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], $post_id );

			if ( is_wp_error( $meta_update ) ) {
				return $meta_update;
			}
		}

		$post          = get_post( $post_id );
		$fields_update = $this->update_additional_fields_for_object( $post, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		$request->set_param( 'context', 'edit' );

		/**
		 * Fires after a single post is completely created or updated via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @param WP_Post         $post     Inserted or updated post object.
		 * @param WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating a post, false when updating.
		 *
		 * @since 5.0.0
		 *
		 */
		do_action( "rest_after_insert_{$this->post_type}", $post, $request, true );

		$response = $this->prepare_item_for_response( $post, $request );
		$response = rest_ensure_response( $response );

		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $post_id ) ) );

		return $response;
	}

	/**
	 * Updates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 * @since 4.7.0
	 *
	 */
	public function update_item( $request ) {
		$valid_check = $this->get_post( $request['id'] );
		if ( is_wp_error( $valid_check ) ) {
			return $valid_check;
		}

		$prepared_post = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		$menu_id = (int) $request['menu_id'];

		// convert the post object to an array, otherwise wp_update_post will expect non-escaped input.
		$post_id = wp_update_nav_menu_item( $menu_id, $request['id'], $prepared_post );

		if ( is_wp_error( $post_id ) ) {
			if ( 'db_update_error' === $post_id->get_error_code() ) {
				$post_id->add_data( array( 'status' => 500 ) );
			} else {
				$post_id->add_data( array( 'status' => 400 ) );
			}

			return $post_id;
		}

		$post = $this->get_post( $post_id );

		/** This action is documented in wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php */
		do_action( "rest_insert_{$this->post_type}", $post, $request, false );

		$schema = $this->get_item_schema();

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], $post->ID );

			if ( is_wp_error( $meta_update ) ) {
				return $meta_update;
			}
		}

		$post          = get_post( $post_id );
		$fields_update = $this->update_additional_fields_for_object( $post, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		$request->set_param( 'context', 'edit' );


		/** This action is documented in wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php */
		do_action( "rest_after_insert_{$this->post_type}", $post, $request, false );

		$response = $this->prepare_item_for_response( $post, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Prepares a single post for create or update.
	 *
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return stdClass|WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared_post = array(
			'menu-item-db-id'       => 0,
			'menu-item-object-id'   => 0,
			'menu-item-object'      => '',
			'menu-item-parent-id'   => 0,
			'menu-item-position'    => 0,
			'menu-item-type'        => 'custom',
			'menu-item-title'       => '',
			'menu-item-url'         => '',
			'menu-item-description' => '',
			'menu-item-attr-title'  => '',
			'menu-item-target'      => '',
			'menu-item-classes'     => '',
			'menu-item-xfn'         => '',
			'menu-item-status'      => 'publish',
		);


		$mapping = array(
			'menu-item-db-id'       => 'db_id',
			'menu-item-object-id'   => 'object_id',
			'menu-item-object'      => 'object',
			'menu-item-parent-id'   => 'menu_item_parent',
			'menu-item-position'    => 'menu_order',
			'menu-item-type'        => 'type',
			'menu-item-title'       => 'title',
			'menu-item-url'         => 'url',
			'menu-item-description' => 'description',
			'menu-item-attr-title'  => 'attr_title',
			'menu-item-target'      => 'target',
			'menu-item-classes'     => 'classes',
			'menu-item-xfn'         => 'xfn',
			'menu-item-status'      => 'status',
		);

		$schema = $this->get_item_schema();

		foreach ( $mapping as $original => $api_request ) {

			if ( ! empty( $schema['properties'][ $api_request ] ) && isset( $request[ $api_request ] ) ) {
				$prepared_post[ $original ] = $request[ $api_request ];
			}
		}

		return $prepared_post;
	}

	/**
	 * Prepares a single post output for response.
	 *
	 * @param WP_Post         $post    Post object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response Response object.
	 *
	 */
	public function prepare_item_for_response( $post, $request ) {

		$fields = $this->get_fields_for_response( $request );

		// Base fields for every post.
		$menu_item = wp_setup_nav_menu_item( $post );

		if ( in_array( 'id', $fields, true ) ) {
			$data['id'] = $post->ID;
		}

		if ( in_array( 'title', $fields, true ) ) {
			add_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );

			$data['title'] = array(
				'raw'      => $post->post_title,
				'rendered' => get_the_title( $post->ID ),
			);

			remove_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
		}

		if ( in_array( 'original_title', $fields, true ) ) {
			$data['original_title'] = $this->get_original_title( $menu_item );
		}

		if ( in_array( 'status', $fields, true ) ) {
			$data['status'] = $menu_item->post_status;
		}

		if ( in_array( 'url', $fields, true ) ) {
			$data['url'] = $menu_item->url;
		}

		if ( in_array( 'attr_title', $fields, true ) ) {
			$data['attr_title'] = $menu_item->attr_title; // Same as post_excerpt
		}
		if ( in_array( 'classes', $fields, true ) ) {
			$data['classes'] = (array) $menu_item->classes;
		}
		if ( in_array( 'description', $fields, true ) ) {
			$data['description'] = $menu_item->description; // Same as post_content
		}
		if ( in_array( 'type', $fields, true ) ) {
			$data['type'] = $menu_item->type; // Using 'item_type' since 'type' already exists.
		}
		if ( in_array( 'type_label', $fields, true ) ) {
			$data['type_label'] = $menu_item->type_label; // Using 'item_type_label' to match up with 'item_type' - IS READ ONLY!
		}
		if ( in_array( 'object', $fields, true ) ) {
			$data['object'] = $menu_item->object;
		}
		if ( in_array( 'object_id', $fields, true ) ) {
			$data['object_id'] = absint( $menu_item->object_id ); // Usually is a string, but lets expose as an integer.
		}
		if ( in_array( 'parent', $fields, true ) ) {
			$data['parent'] = absint( $menu_item->post_parent ); // Same as post_parent, expose as integer
		}
		if ( in_array( 'menu_item_parent', $fields, true ) ) {
			$data['menu_item_parent'] = absint( $menu_item->menu_item_parent ); // Same as post_parent, expose as integer
		}
		if ( in_array( 'menu_order', $fields, true ) ) {
			$data['menu_order'] = absint( $menu_item->menu_order ); // Same as post_parent, expose as integer
		}

		if ( in_array( 'target', $fields, true ) ) {
			$data['target'] = $menu_item->target;
		}

		if ( in_array( 'classes', $fields, true ) ) {
			$data['classes'] = (array) $menu_item->classes;
		}
		if ( in_array( 'xfn', $fields, true ) ) {
			$data['xfn'] = (array) $menu_item->xfn;
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );

		$links = $this->prepare_links( $post );
		$response->add_links( $links );

		if ( ! empty( $links['self']['href'] ) ) {
			$actions = $this->get_available_actions( $post, $request );

			$self = $links['self']['href'];

			foreach ( $actions as $rel ) {
				$response->add_link( $rel, $self );
			}
		}

		/**
		 * Filters the post data for a response.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WP_Post          $post     Post object.
		 * @param WP_REST_Request  $request  Request object.
		 *
		 *
		 */
		return apply_filters( "rest_prepare_{$this->post_type}", $response, $post, $request );
	}

	/**
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => $this->post_type,
			'type'    => 'object',
		);

		$schema['properties']['title'] = array(
			'description' => __( 'The title for the object.' ),
			'type'        => 'object',
			'context'     => array( 'view', 'edit', 'embed' ),
			'arg_options' => array(
				'sanitize_callback' => null, // Note: sanitization implemented in self::prepare_item_for_database()
				'validate_callback' => null, // Note: validation implemented in self::prepare_item_for_database()
			),
			'properties'  => array(
				'raw'      => array(
					'description' => __( 'Title for the object, as it exists in the database.' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
				),
				'rendered' => array(
					'description' => __( 'HTML title for the object, transformed for display.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		$schema['properties']['original_title'] = array(
			'description' => __( 'HTML title for the object, transformed for display.' ),
			'type'        => 'string',
			'context'     => array( 'view', 'embed' ),
			'readonly'    => true,
		);

		$schema['properties']['id'] = array(
			'description' => __( 'Unique identifier for the object.' ),
			'type'        => 'integer',
			'context'     => array( 'view', 'edit', 'embed' ),
			'readonly'    => true,
		);

		$schema['properties']['menu_id'] = array(
			'description' => __( 'Unique identifier for the object.' ),
			'type'        => 'integer',
			'context'     => array( 'edit' ),
			'default'     => 0,
		);

		$schema['properties']['type_label'] = array(
			'description' => __( 'Unique identifier for the object.' ),
			'type'        => 'string',
			'context'     => array( 'view', 'edit', 'embed' ),
			'readonly'    => true,
		);

		$schema['properties']['type'] = array(
			'description' => __( 'Unique identifier for the object.' ),
			'type'        => 'string',
			'context'     => array( 'view', 'edit', 'embed' ),
		);


		$schema['properties']['status'] = array(
			'description' => __( 'A named status for the object.' ),
			'type'        => 'string',
			'enum'        => array_keys( get_post_stati( array( 'internal' => false ) ) ),
			'context'     => array( 'view', 'edit' ),
		);

		$schema['properties']['link'] = array(
			'description' => __( 'URL to the object.' ),
			'type'        => 'string',
			'format'      => 'uri',
			'context'     => array( 'view', 'edit', 'embed' ),
			'readonly'    => true,
		);

		$schema['properties']['parent'] = array(
			'description' => __( 'The ID for the parent of the object.' ),
			'type'        => 'integer',
			'context'     => array( 'view', 'edit' ),
		);

		$schema['properties']['attr_title']       = array(
			'description' => __( 'The title attribute of the link element for this menu item .' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'string',
		);
		$schema['properties']['classes']          = array(
			'description' => __( 'The array of class attribute values for the link element of this menu item .' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'string',
			)
		);
		$schema['properties']['db_id']            = array(
			'description' => __( 'The DB ID of this item as a nav_menu_item object, if it exists( 0 if it doesn\'t exist).' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'integer',
		);
		$schema['properties']['description']      = array(
			'description' => __( 'The description of this menu item.' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'string',
		);
		$schema['properties']['menu_item_parent'] = array(
			'description' => __( 'The DB ID of the nav_menu_item that is this item\'s menu parent, if any . 0 otherwise . ' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'integer',
		);
		$schema['properties']['menu_order']       = array(
			'description' => __( 'The DB ID of the nav_menu_item that is this item\'s menu parent, if any . 0 otherwise . ' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'integer',
		);
		$schema['properties']['object']           = array(
			'description' => __( 'The type of object originally represented, such as "category," "post", or "attachment."' ),
			'context'     => array( 'view', 'edit' ),
		);
		$schema['properties']['object_id']        = array(
			'description' => __( 'The DB ID of the original object this menu item represents, e . g . ID for posts and term_id for categories .' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'integer',
		);
		$schema['properties']['target']           = array(
			'description' => __( 'The target attribute of the link element for this menu item . The family of objects originally represented, such as "post_type" or "taxonomy."' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'string',
		);


		$schema['properties']['type_label'] = array(
			'description' => __( 'The singular label used to describe this type of menu item.' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'string',
		);
		$schema['properties']['url']        = array(
			'description' => __( 'The URL to which this menu item points .' ),
			'type'        => 'string',
			'format'      => 'uri',
			'context'     => array( 'view', 'edit' ),
		);

		$schema['properties']['xfn'] = array(
			'description' => __( 'The XFN relationship expressed in the link of this menu item . ' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'array',
			'items'       => array(
				'type' => 'string',
			)
		);

		$schema['properties']['_invalid'] = array(
			'description' => __( '      Whether the menu item represents an object that no longer exists .' ),
			'context'     => array( 'view', 'edit' ),
			'type'        => 'boolean',
		);


		return $schema;
	}

	/**
	 * Retrieves the query params for the posts collection.
	 *
	 * @return array Collection parameters.
	 * @since 4.7.0
	 *
	 */
	public function get_collection_params() {
		$query_params = parent::get_collection_params();

		$query_params['menu_order'] = array(
			'description' => __( 'Limit result set to posts with a specific menu_order value.' ),
			'type'        => 'integer',
		);

		$query_params['order'] = array(
			'description' => __( 'Order sort attribute ascending or descending.' ),
			'type'        => 'string',
			'default'     => 'asc',
			'enum'        => array( 'asc', 'desc' ),
		);

		$query_params['orderby'] = array(
			'description' => __( 'Sort collection by object attribute.' ),
			'type'        => 'string',
			'default'     => 'menu_order',
			'enum'        => array(
				'author',
				'date',
				'id',
				'include',
				'modified',
				'parent',
				'relevance',
				'slug',
				'include_slugs',
				'title',
				'menu_order',
			),
		);

		return $query_params;

	}

	/**
	 * @param array $prepared_args
	 * @param null  $request
	 *
	 * @return array
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$query_args = parent::prepare_items_query( $prepared_args, $request );

		// Map to proper WP_Query orderby param.
		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) ) {
			$orderby_mappings = array(
				'id'            => 'ID',
				'include'       => 'post__in',
				'slug'          => 'post_name',
				'include_slugs' => 'post_name__in',
				'menu_order'    => 'menu_order',
			);

			if ( isset( $orderby_mappings[ $request['orderby'] ] ) ) {
				$query_args['orderby'] = $orderby_mappings[ $request['orderby'] ];
			}
		}

		return $query_args;
	}

	/**
	 * Get original title.
	 *
	 * @param object $item Nav menu item.
	 *
	 * @return string The original title.
	 * @since 4.7.0
	 *
	 */
	protected function get_original_title( $item ) {
		$original_title = '';
		if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
			$original_object = get_post( $item->object_id );
			if ( $original_object ) {
				/** This filter is documented in wp-includes/post-template.php */
				$original_title = apply_filters( 'the_title', $original_object->post_title, $original_object->ID );

				if ( '' === $original_title ) {
					/* translators: %d: ID of a post */
					$original_title = sprintf( __( '#%d (no title)' ), $original_object->ID );
				}
			}
		} elseif ( 'taxonomy' === $item->type && ! empty( $item->object_id ) ) {
			$original_term_title = get_term_field( 'name', $item->object_id, $item->object, 'raw' );
			if ( ! is_wp_error( $original_term_title ) ) {
				$original_title = $original_term_title;
			}
		} elseif ( 'post_type_archive' === $item->type ) {
			$original_object = get_post_type_object( $item->object );
			if ( $original_object ) {
				$original_title = $original_object->labels->archives;
			}
		}
		$original_title = html_entity_decode( $original_title, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return $original_title;
	}
}
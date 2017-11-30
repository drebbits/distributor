<?php

namespace Distributor\Subscriptions;

/**
 * Setup actions and filters
 *
 * @since 1.0
 */
function setup() {
	add_action(
		'plugins_loaded', function() {
			add_action( 'init', __NAMESPACE__ . '\register_cpt' );
			add_action( 'save_post', __NAMESPACE__ . '\send_notifications' );
			add_action( 'before_delete_post', __NAMESPACE__ . '\delete_subscriptions' );
		}
	);
}

/**
 * Create a subscription locally for a post.
 *
 * @param  int    $post_id
 * @param  int    $remote_post_id
 * @param  string $target_url
 * @param  string $signature
 * @since  1.0
 * @return int|WP_Error
 */
function create_subscription( $post_id, $remote_post_id, $target_url, $signature ) {
	$subscription_id = wp_insert_post(
		array(
			'post_type'   => 'dt_subscription',
			'post_status' => 'publish',
			'post_title'  => 'Subscription ' . $post_id . ' ' . time(),
			'post_parent' => $post_id,
		)
	);

	update_post_meta( $subscription_id, 'dt_subscription_post_id', (int) $post_id );
	update_post_meta( $subscription_id, 'dt_subscription_signature', sanitize_text_field( $signature ) );
	update_post_meta( $subscription_id, 'dt_subscription_remote_post_id', (int) $remote_post_id );
	update_post_meta( $subscription_id, 'dt_subscription_target_url', esc_url_raw( $target_url ) );

	$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );

	if ( empty( $subscriptions ) ) {
		$subscriptions = [];
	}

	/**
	 * We set the key as a hash like this so we can look it up easily for deletion later. We
	 * use the hash to conceal the signature in case an editor is exposed to the meta key
	 */
	$subscriptions[ md5( $signature ) ] = (int) $subscription_id;

	update_post_meta( $post_id, 'dt_subscriptions', $subscriptions );

	return $subscription_id;
}

/**
 * Generate random signature hash
 *
 * @since  1.0
 * @return string
 */
function generate_signature() {
	return wp_generate_password( 26, false, false );
}

/**
 * Create a remote subscription for a post. This is done by sending an HTTP request to the original
 * post's site
 *
 * @param ExternalConnection $connection
 * @param int                $remote_post_id
 * @param int                $post_id
 * @since 1.0
 */
function create_remote_subscription( \Distributor\ExternalConnection $connection, $remote_post_id, $post_id ) {

	$signature = generate_signature();

	update_post_meta( $post_id, 'dt_subscription_signature', sanitize_text_field( $signature ) );

	$post_body = [
		'post_id'        => (int) $remote_post_id,
		'remote_post_id' => (int) $post_id,
		'target_url'     => untrailingslashit( home_url() ) . '/wp-json',
		'signature'      => $signature,
	];

	$url = untrailingslashit( $connection->base_url ) . '/' . $connection::$namespace . '/dt_subscription';

	wp_remote_post(
		$url,
		$connection->auth_handler->format_post_args(
			array(
				'timeout'  => 5,
				'blocking' => \Distributor\Utils\is_dt_debug(),
				'body'     => $post_body,
			)
		)
	);
}

/**
 * Delete a remote subscription by sending an HTTP request to the dt_subscription/delete endpoint
 *
 * @param  int $remote_post_id
 * @param  int $post_id
 * @since 1.0
 */
function delete_remote_subscription( \Distributor\ExternalConnection $connection, $remote_post_id, $post_id ) {
	$signature = get_post_meta( $post_id, 'dt_subscription_signature', true );

	$post_body = [
		'post_id'   => (int) $remote_post_id,
		'signature' => $signature,
	];

	wp_remote_request(
		untrailingslashit( $connection->base_url ) . '/' . $connection::$namespace . '/dt_subscription/delete',
		array(
			'timeout'  => 5,
			'method'   => 'DELETE',
			'blocking' => \Distributor\Utils\is_dt_debug(),
			'body'     => $post_body,
		)
	);

	delete_post_meta( $post_id, 'dt_subscription_signature' );
}

/**
 * Delete a local subscription for a post given a signature
 *
 * @param int    $post_id
 * @param string $signature
 * @since 1.0
 */
function delete_subscription( $post_id, $signature ) {
	$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );

	$subscription_id = $subscriptions[ md5( $signature ) ];

	unset( $subscriptions[ md5( $signature ) ] );

	wp_delete_post( $subscription_id, true );

	update_post_meta( $post_id, 'dt_subscriptions', $subscriptions );
}

/**
 * Delete subscriptions, both remotely and locally
 *
 * @param int $post_id
 * @since 1.0
 */
function delete_subscriptions( $post_id ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$original_source_id = get_post_meta( $post_id, 'dt_original_source_id', true );
	$original_post_id   = get_post_meta( $post_id, 'dt_original_post_id', true );
	$subscriptions      = get_post_meta( $post_id, 'dt_subscriptions', true );

	if ( ! empty( $original_source_id ) && ! empty( $original_post_id ) ) {
		// This case happens if a post is deleted that is subscribing to a remote post
		$connection = \Distributor\ExternalConnection::instantiate( $original_source_id );

		if ( ! is_wp_error( $connection ) ) {
			delete_remote_subscription( $connection, $original_post_id, $post_id );
		}
	} elseif ( ! empty( $subscriptions ) ) {
		// This case happens if a post is deleted that is being subscribed to
		foreach ( $subscriptions as $subscription_id ) {
			$signature      = get_post_meta( $subscription_id, 'dt_subscription_signature', true );
			$remote_post_id = get_post_meta( $subscription_id, 'dt_subscription_remote_post_id', true );
			$target_url     = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );

			wp_delete_post( $subscription_id, true );

			if ( empty( $signature ) || empty( $remote_post_id ) || empty( $target_url ) ) {
				continue;
			}

			// We need to ensure any remote post is unlinked to this post
			$request = wp_remote_post(
				untrailingslashit( $target_url ) . '/wp/v2/dt_subscription/receive', [
					'timeout'  => 5,
					'blocking' => \Distributor\Utils\is_dt_debug(),
					'body'     => [
						'post_id'          => $remote_post_id,
						'signature'        => $signature,
						'original_deleted' => true,
					],
				]
			);
		}
	}
}

/**
 * Send notifications on post update to each subscription for that post
 *
 * @param  int $post_id
 * @since  1.0
 */
function send_notifications( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$subscriptions = get_post_meta( $post_id, 'dt_subscriptions', true );

	if ( empty( $subscriptions ) ) {
		return;
	}

	$post = get_post( $post_id );

	$update_subscriptions = false;

	foreach ( $subscriptions as $subscription_key => $subscription_id ) {
		$signature      = get_post_meta( $subscription_id, 'dt_subscription_signature', true );
		$remote_post_id = get_post_meta( $subscription_id, 'dt_subscription_remote_post_id', true );
		$target_url     = get_post_meta( $subscription_id, 'dt_subscription_target_url', true );

		if ( empty( $signature ) || empty( $remote_post_id ) || empty( $target_url ) ) {
			continue;
		}

		$request = wp_remote_post(
			untrailingslashit( $target_url ) . '/wp/v2/dt_subscription/receive', [
				'timeout' => 5,
				'body'    => [
					'post_id'   => $remote_post_id,
					'signature' => $signature,
					'post_data' => [
						'title'             => get_the_title( $post_id ),
						'content'           => apply_filters( 'the_content', $post->post_content ),
						'excerpt'           => $post->post_excerpt,
						'distributor_media' => \Distributor\Utils\prepare_media( $post_id ),
						'distributor_terms' => \Distributor\Utils\prepare_taxonomy_terms( $post_id ),
						'distributor_meta'  => \Distributor\Utils\prepare_meta( $post_id ),
					],
				],
			]
		);

		if ( ! is_wp_error( $request ) ) {
			$response_code = wp_remote_retrieve_response_code( $request );
			$headers       = wp_remote_retrieve_headers( $request );

			if ( 404 === $response_code && ! empty( $headers['X-Distributor-Post-Deleted'] ) ) {
				/**
				 * Post on receiving end has been deleted.
				 */
				unset( $subscriptions[ $subscription_key ] );

				$update_subscriptions = true;

				wp_delete_post( $subscription_id, true );
			}
		}
	}

	if ( $update_subscriptions ) {
		update_post_meta( $post_id, 'dt_subscriptions', $subscriptions );
	}
}

/**
 * Create subscription post type
 *
 * @since  1.0
 */
function register_cpt() {
	$args = array(
		'label'                 => esc_html__( 'Distributor Subscription', 'distributor' ),
		'public'                => \Distributor\Utils\is_dt_debug(),
		'query_var'             => false,
		'rewrite'               => false,
		'capability_type'       => 'post',
		'hierarchical'          => false,
		'supports'              => ( \Distributor\Utils\is_dt_debug() ) ? [ 'custom-fields' ] : [],
		'has_archive'           => false,
		'show_in_rest'          => true,
		'rest_controller_class' => 'Distributor\\API\\SubscriptionsController',
	);

	register_post_type( 'dt_subscription', $args );
}


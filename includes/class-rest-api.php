<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Rest_API {
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = PP_REST_NAMESPACE;

		register_rest_route( $ns, '/pins', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_pins' ],
				'permission_callback' => [ $this, 'can_create' ],
				'args'                => [
					'page_url' => [ 'type' => 'string', 'required' => false ],
					'status'   => [ 'type' => 'string', 'required' => false ],
					'per_page' => [ 'type' => 'integer', 'default' => 50 ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_pin' ],
				'permission_callback' => [ $this, 'can_create' ],
			],
		] );

		register_rest_route( $ns, '/pins/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_pin' ],
				'permission_callback' => [ $this, 'can_create' ],
			],
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_pin' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_pin' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );

		register_rest_route( $ns, '/pins/(?P<id>\d+)/replies', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_reply' ],
			'permission_callback' => [ $this, 'can_create' ],
		] );

		register_rest_route( $ns, '/pins/(?P<id>\d+)/ai-suggest', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ai_suggest' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( $ns, '/ai/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ai_test' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( $ns, '/ai/models', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ai_models' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( $ns, '/pins/(?P<id>\d+)/apply', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'apply_change' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( $ns, '/pins/(?P<id>\d+)/revert', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'revert_change' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );
	}

	public function apply_change( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		$op = get_post_meta( $post->ID, '_pp_ai_change_op', true );
		if ( ! is_array( $op ) || ( $op['op'] ?? '' ) !== 'update_widget_setting' ) {
			return new \WP_REST_Response( [ 'code' => 'no_op', 'message' => 'No applicable change proposal for this pin.' ], 400 );
		}
		$page_id     = (int) get_post_meta( $post->ID, '_pp_elementor_page_id', true );
		$widget_type = (string) $op['widget_type'];
		$widget_id   = (string) $op['widget_id'];
		$setting_key = (string) $op['setting_key'];
		$new_value   = $op['new_value'] ?? '';
		if ( ! $page_id ) {
			return new \WP_REST_Response( [ 'code' => 'no_page', 'message' => 'No Elementor page linked to this pin.' ], 400 );
		}
		$prev = Elementor_Writer::apply( $page_id, $widget_id, $widget_type, $setting_key, $new_value );
		if ( is_wp_error( $prev ) ) {
			return new \WP_REST_Response( [ 'code' => $prev->get_error_code(), 'message' => $prev->get_error_message() ], 400 );
		}
		update_post_meta( $post->ID, '_pp_applied_at', current_time( 'mysql', true ) );
		update_post_meta( $post->ID, '_pp_applied_op', $op );
		update_post_meta( $post->ID, '_pp_applied_prev_value', $prev );
		update_post_meta( $post->ID, '_pp_applied_by', get_current_user_id() );
		return rest_ensure_response( [
			'ok'         => true,
			'applied_at' => get_post_meta( $post->ID, '_pp_applied_at', true ),
			'prev_value' => $prev,
		] );
	}

	public function revert_change( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		$applied_op = get_post_meta( $post->ID, '_pp_applied_op', true );
		$prev       = get_post_meta( $post->ID, '_pp_applied_prev_value', true );
		if ( ! is_array( $applied_op ) ) {
			return new \WP_REST_Response( [ 'code' => 'not_applied', 'message' => 'This pin was not previously applied.' ], 400 );
		}
		$page_id = (int) get_post_meta( $post->ID, '_pp_elementor_page_id', true );
		$ok      = Elementor_Writer::write_raw( $page_id, (string) $applied_op['widget_id'], (string) $applied_op['setting_key'], $prev );
		if ( ! $ok ) {
			return new \WP_REST_Response( [ 'code' => 'revert_failed', 'message' => 'Widget no longer present — revert could not be applied.' ], 400 );
		}
		delete_post_meta( $post->ID, '_pp_applied_at' );
		delete_post_meta( $post->ID, '_pp_applied_op' );
		delete_post_meta( $post->ID, '_pp_applied_prev_value' );
		delete_post_meta( $post->ID, '_pp_applied_by' );
		return rest_ensure_response( [ 'ok' => true, 'reverted' => true ] );
	}

	public function ai_models( \WP_REST_Request $req ): \WP_REST_Response {
		$params = $req->get_json_params();
		$result = AI::instance()->list_models(
			sanitize_key( $params['provider'] ?? '' ),
			(string) ( $params['api_key'] ?? '' ),
			! empty( $params['refresh'] )
		);
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], 200 );
		}
		return rest_ensure_response( [ 'ok' => true, 'models' => $result['models'], 'cached' => $result['cached'] ] );
	}

	public function ai_suggest( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		$result = AI::instance()->generate_for_pin( $post->ID );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], 400 );
		}
		return rest_ensure_response( $result );
	}

	public function ai_test( \WP_REST_Request $req ): \WP_REST_Response {
		$params = $req->get_json_params();
		$result = AI::instance()->test_connection(
			sanitize_key( $params['provider'] ?? '' ),
			(string) ( $params['api_key'] ?? '' ),
			sanitize_text_field( $params['model'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'message' => $result->get_error_message() ], 200 );
		}
		return rest_ensure_response( [ 'ok' => true, 'message' => $result ] );
	}

	public function can_create(): bool {
		if ( is_user_logged_in() && current_user_can( Capabilities::CREATE ) ) { return true; }
		$settings = get_option( 'pp_settings', [] );
		return ! empty( $settings['guest_pins_enabled'] );
	}

	/**
	 * Find the Elementor widget that wraps the clicked element, if any.
	 * Returns empty array when the element isn't inside an Elementor widget.
	 */
	private function detect_elementor_widget( string $element_html, string $page_url ): array {
		if ( $element_html === '' ) { return []; }
		// Walk the captured HTML looking for widget attributes. The widget root
		// has both data-widget_type and data-id attributes.
		if ( ! preg_match( '/data-widget_type\s*=\s*"([a-z0-9_\-\.]+)"/i', $element_html, $m_type ) ) { return []; }
		$widget_type_full = $m_type[1];
		// Strip skin suffix (e.g. "heading.default" -> "heading")
		$widget_type = strpos( $widget_type_full, '.' ) !== false ? substr( $widget_type_full, 0, strpos( $widget_type_full, '.' ) ) : $widget_type_full;

		$widget_id = '';
		if ( preg_match( '/data-id\s*=\s*"([a-f0-9]+)"/i', $element_html, $m_id ) ) {
			$widget_id = $m_id[1];
		}
		if ( ! $widget_id ) { return []; }

		return [
			'widget_type' => $widget_type,
			'widget_id'   => $widget_id,
			'page_id'     => $this->resolve_page_id( $page_url ),
		];
	}

	/**
	 * Resolve the Elementor page id for an already-known widget type + id.
	 * Used when the client passed explicit widget identity (preferred path).
	 */
	private function resolve_elementor_page( string $widget_type, string $widget_id, string $page_url ): array {
		return [
			'widget_type' => $widget_type,
			'widget_id'   => $widget_id,
			'page_id'     => $this->resolve_page_id( $page_url ),
		];
	}

	/**
	 * Build an absolute URL from a site-relative path (which may already include
	 * the subdirectory) and resolve it to a post id. Returns 0 when the target
	 * isn't an Elementor page.
	 */
	private function resolve_page_id( string $page_url ): int {
		if ( $page_url === '' ) { return 0; }
		$parts = wp_parse_url( home_url() );
		$origin = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' )
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$abs = $origin . ( $page_url[0] === '/' ? $page_url : '/' . $page_url );
		$resolved = url_to_postid( $abs );
		if ( $resolved && get_post_meta( $resolved, '_elementor_data', true ) ) {
			return (int) $resolved;
		}
		return 0;
	}

	private function guest_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$fwd = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $fwd[0] );
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	public function list_pins( \WP_REST_Request $req ): \WP_REST_Response {
		$args = [
			'post_type'      => PP_POST_TYPE,
			'post_status'    => CPT::all_statuses(),
			'posts_per_page' => min( 100, (int) $req['per_page'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		$page_url = $req->get_param( 'page_url' );
		if ( $page_url ) {
			$args['meta_query'] = [ [ 'key' => '_pp_page_url', 'value' => $page_url ] ];
		}
		$status = $req->get_param( 'status' );
		if ( $status && in_array( $status, CPT::all_statuses(), true ) ) {
			$args['post_status'] = $status;
		}
		$q    = new \WP_Query( $args );
		$out  = array_map( [ $this, 'format_pin' ], $q->posts );
		return rest_ensure_response( $out );
	}

	public function create_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$params = $req->get_json_params();
		$body   = isset( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		if ( $body === '' ) {
			return new \WP_REST_Response( [ 'code' => 'empty_body', 'message' => 'Comment body required.' ], 400 );
		}

		$is_guest = ! is_user_logged_in();
		$guest_name  = '';
		$guest_email = '';
		if ( $is_guest ) {
			// Honeypot: bots tend to fill all fields. Silent reject.
			if ( ! empty( $params['hp'] ) ) {
				return new \WP_REST_Response( [ 'code' => 'rejected' ], 400 );
			}
			// Per-IP rate limit.
			$settings = get_option( 'pp_settings', [] );
			$limit    = max( 1, (int) ( $settings['guest_rate_limit'] ?? 5 ) );
			$ip_key   = 'pp_guest_rl_' . md5( $this->guest_ip() );
			$count    = (int) get_transient( $ip_key );
			if ( $count >= $limit ) {
				return new \WP_REST_Response( [ 'code' => 'rate_limited', 'message' => 'Too many pins from your address. Please try again later.' ], 429 );
			}
			set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

			$guest_name  = sanitize_text_field( (string) ( $params['guest_name'] ?? '' ) );
			$guest_email = sanitize_email( (string) ( $params['guest_email'] ?? '' ) );
			if ( $guest_name === '' ) { $guest_name = __( 'Guest', 'proofing-pins' ); }
		}

		$page_url = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '';
		$page_url = $this->normalize_page_url( $page_url );

		$element_html = isset( $params['element_html'] ) ? (string) $params['element_html'] : '';
		if ( strlen( $element_html ) > 4000 ) { $element_html = substr( $element_html, 0, 4000 ); }
		$element_html = wp_kses( $element_html, wp_kses_allowed_html( 'post' ) );

		// Elementor widget identity — prefer explicit values from the client (which
		// walked up from e.target to the nearest [data-element_type="widget"]
		// ancestor). Fall back to HTML regex on the narrow element_html for
		// backward compatibility / robustness.
		$explicit_widget_type = isset( $params['elementor_widget_type'] ) ? sanitize_text_field( (string) $params['elementor_widget_type'] ) : '';
		$explicit_widget_id   = isset( $params['elementor_widget_id'] )   ? sanitize_text_field( (string) $params['elementor_widget_id'] )   : '';
		$elementor            = ( $explicit_widget_type && $explicit_widget_id )
			? $this->resolve_elementor_page( $explicit_widget_type, $explicit_widget_id, $page_url )
			: $this->detect_elementor_widget( $element_html, $page_url );

		// New element-anchor fields (v2, source of truth). Clamp offsets to [0, 1].
		$anchor_selector = isset( $params['anchor_selector'] ) ? sanitize_text_field( $params['anchor_selector'] ) : '';
		$anchor_xpath    = isset( $params['anchor_xpath'] ) ? sanitize_text_field( $params['anchor_xpath'] ) : '';
		$anchor_text     = isset( $params['anchor_text'] ) ? substr( sanitize_text_field( $params['anchor_text'] ), 0, 40 ) : '';
		$offset_x_pct    = isset( $params['offset_x_pct'] ) ? max( 0.0, min( 1.0, (float) $params['offset_x_pct'] ) ) : 0.5;
		$offset_y_pct    = isset( $params['offset_y_pct'] ) ? max( 0.0, min( 1.0, (float) $params['offset_y_pct'] ) ) : 0.5;

		$meta = [
			'_pp_page_url'         => $page_url,
			'_pp_page_title'       => isset( $params['page_title'] ) ? sanitize_text_field( $params['page_title'] ) : '',
			// v2 anchor model
			'_pp_anchor_selector'  => $anchor_selector,
			'_pp_anchor_xpath'     => $anchor_xpath,
			'_pp_anchor_text'      => $anchor_text,
			'_pp_offset_x_pct'     => $offset_x_pct,
			'_pp_offset_y_pct'     => $offset_y_pct,
			// Context / debug
			'_pp_scroll_y_pct'     => isset( $params['scroll_y_pct'] ) ? (float) $params['scroll_y_pct'] : 0,
			'_pp_element_html'     => $element_html,
			'_pp_element_tag'      => isset( $params['element_tag'] ) ? sanitize_key( $params['element_tag'] ) : '',
			'_pp_viewport_w'       => isset( $params['viewport_w'] ) ? (int) $params['viewport_w'] : 0,
			'_pp_viewport_h'       => isset( $params['viewport_h'] ) ? (int) $params['viewport_h'] : 0,
			'_pp_user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
			'_pp_device_type'      => isset( $params['device_type'] ) ? sanitize_text_field( $params['device_type'] ) : 'desktop',
			'_pp_is_guest'         => $is_guest ? 1 : 0,
			'_pp_guest_name'       => $is_guest ? $guest_name : '',
			'_pp_guest_email'      => $is_guest ? $guest_email : '',
			'_pp_guest_ip_hash'    => $is_guest ? substr( md5( $this->guest_ip() ), 0, 16 ) : '',
			// Elementor detection (empty strings when not an Elementor page)
			'_pp_elementor_widget_type' => $elementor['widget_type'] ?? '',
			'_pp_elementor_widget_id'   => $elementor['widget_id']   ?? '',
			'_pp_elementor_page_id'     => $elementor['page_id']     ?? 0,
		];

		$attachment_id = 0;
		if ( ! empty( $params['screenshot_data_url'] ) ) {
			$attachment_id = Screenshot::save_from_data_url( (string) $params['screenshot_data_url'], get_current_user_id() );
		}
		if ( $attachment_id ) {
			$meta['_pp_screenshot_id'] = $attachment_id;
		}

		$excerpt = wp_trim_words( $body, 10, '…' );
		$title   = sprintf( 'Pin on %s — "%s"', $page_url ?: '/', $excerpt );

		$post_id = wp_insert_post( [
			'post_type'    => PP_POST_TYPE,
			'post_title'   => $title,
			'post_content' => $body,
			'post_status'  => CPT::STATUS_OPEN,
			'post_author'  => $is_guest ? 0 : get_current_user_id(),
			'meta_input'   => $meta,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response( [ 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ], 500 );
		}
		if ( $attachment_id ) {
			wp_update_post( [ 'ID' => $attachment_id, 'post_parent' => $post_id ] );
		}

		/**
		 * Fires after a pin is successfully created. Hook point for AI auto-suggest.
		 */
		do_action( 'pp_pin_created', (int) $post_id );

		return rest_ensure_response( $this->format_pin( get_post( $post_id ) ) );
	}

	public function get_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		return rest_ensure_response( $this->format_pin( $post, true ) );
	}

	public function update_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		$params = $req->get_json_params();
		$update = [ 'ID' => $post->ID ];
		if ( isset( $params['status'] ) && in_array( $params['status'], CPT::all_statuses(), true ) ) {
			$update['post_status'] = $params['status'];
		}
		if ( isset( $params['body'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $params['body'] );
		}
		wp_update_post( $update );
		return rest_ensure_response( $this->format_pin( get_post( $post->ID ) ) );
	}

	public function delete_pin( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		Admin::delete_pin_fully( $post->ID );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function add_reply( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}
		$params = $req->get_json_params();
		$body   = isset( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		if ( $body === '' ) {
			return new \WP_REST_Response( [ 'code' => 'empty_body', 'message' => 'Reply body required.' ], 400 );
		}
		$user       = wp_get_current_user();
		$comment_id = wp_insert_comment( [
			'comment_post_ID'      => $post->ID,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_content'      => $body,
			'user_id'              => $user->ID,
			'comment_approved'     => 1,
			'comment_type'         => 'pp_reply',
		] );
		if ( ! $comment_id ) {
			return new \WP_REST_Response( [ 'code' => 'insert_failed' ], 500 );
		}
		return rest_ensure_response( $this->format_reply( get_comment( $comment_id ) ) );
	}

	private function format_pin( $post, bool $include_replies = false ): array {
		$screenshot_id  = (int) get_post_meta( $post->ID, '_pp_screenshot_id', true );
		$screenshot_url = $screenshot_id ? wp_get_attachment_url( $screenshot_id ) : '';
		$author         = get_userdata( $post->post_author );
		$is_guest       = (int) get_post_meta( $post->ID, '_pp_is_guest', true ) === 1;
		$guest_name     = (string) get_post_meta( $post->ID, '_pp_guest_name', true );
		$guest_email    = (string) get_post_meta( $post->ID, '_pp_guest_email', true );
		$author_name    = $author ? $author->display_name : ( $guest_name ?: __( 'Guest', 'proofing-pins' ) );
		$avatar_url     = $author
			? get_avatar_url( $post->post_author, [ 'size' => 48 ] )
			: ( $guest_email ? get_avatar_url( $guest_email, [ 'size' => 48 ] ) : '' );
		$data = [
			'id'               => $post->ID,
			'body'             => $post->post_content,
			'status'           => $post->post_status,
			'author_id'        => (int) $post->post_author,
			'author_name'      => $author_name,
			'avatar_url'       => $avatar_url,
			'is_guest'         => $is_guest,
			'page_url'         => get_post_meta( $post->ID, '_pp_page_url', true ),
			'page_title'       => get_post_meta( $post->ID, '_pp_page_title', true ),
			// v2 anchor model
			'anchor_selector'  => get_post_meta( $post->ID, '_pp_anchor_selector', true ),
			'anchor_xpath'     => get_post_meta( $post->ID, '_pp_anchor_xpath', true ),
			'anchor_text'      => get_post_meta( $post->ID, '_pp_anchor_text', true ),
			'offset_x_pct'     => (float) get_post_meta( $post->ID, '_pp_offset_x_pct', true ),
			'offset_y_pct'     => (float) get_post_meta( $post->ID, '_pp_offset_y_pct', true ),
			// Legacy (for old pins that predate v2 — widget falls back to these)
			'pin_x'            => (float) get_post_meta( $post->ID, '_pp_pin_x', true ),
			'pin_y'            => (float) get_post_meta( $post->ID, '_pp_pin_y', true ),
			'doc_x'            => (float) get_post_meta( $post->ID, '_pp_doc_x', true ),
			'doc_y'            => (float) get_post_meta( $post->ID, '_pp_doc_y', true ),
			'scroll_y_pct'     => (float) get_post_meta( $post->ID, '_pp_scroll_y_pct', true ),
			'element_selector' => get_post_meta( $post->ID, '_pp_anchor_selector', true ) ?: get_post_meta( $post->ID, '_pp_element_selector', true ),
			'viewport_w'       => (int) get_post_meta( $post->ID, '_pp_viewport_w', true ),
			'viewport_h'       => (int) get_post_meta( $post->ID, '_pp_viewport_h', true ),
			'device_type'      => get_post_meta( $post->ID, '_pp_device_type', true ),
			'element_tag'      => get_post_meta( $post->ID, '_pp_element_tag', true ),
			'screenshot_url'   => $screenshot_url,
			'created_at'       => mysql_to_rfc3339( $post->post_date_gmt ),
			'reply_count'      => (int) get_comments_number( $post->ID ),
			'ai_status'        => get_post_meta( $post->ID, '_pp_ai_status', true ),
			'ai_suggestion'    => get_post_meta( $post->ID, '_pp_ai_suggestion', true ),
			// Elementor context + change proposal
			'elementor' => [
				'widget_type' => get_post_meta( $post->ID, '_pp_elementor_widget_type', true ),
				'widget_id'   => get_post_meta( $post->ID, '_pp_elementor_widget_id', true ),
				'page_id'     => (int) get_post_meta( $post->ID, '_pp_elementor_page_id', true ),
			],
			'change_op'        => get_post_meta( $post->ID, '_pp_ai_change_op', true ),
			'applied_at'       => get_post_meta( $post->ID, '_pp_applied_at', true ),
			'applied_op'       => get_post_meta( $post->ID, '_pp_applied_op', true ),
		];
		if ( $include_replies ) {
			$comments = get_comments( [ 'post_id' => $post->ID, 'type' => 'pp_reply', 'status' => 'approve', 'order' => 'ASC' ] );
			$data['replies'] = array_map( [ $this, 'format_reply' ], $comments );
		}
		return $data;
	}

	private function format_reply( $comment ): array {
		return [
			'id'          => (int) $comment->comment_ID,
			'body'        => $comment->comment_content,
			'author_id'   => (int) $comment->user_id,
			'author_name' => $comment->comment_author,
			'avatar_url'  => get_avatar_url( $comment->user_id, [ 'size' => 36 ] ),
			'created_at'  => mysql_to_rfc3339( $comment->comment_date_gmt ),
		];
	}

	private function normalize_page_url( string $url ): string {
		if ( $url === '' ) { return '/'; }
		$parts = wp_parse_url( $url );
		$path  = $parts['path'] ?? '/';
		if ( $path === '' ) { $path = '/'; }
		return $path;
	}
}

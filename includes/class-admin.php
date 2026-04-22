<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Admin {
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Handle bulk delete BEFORE any output so wp_safe_redirect() works.
		add_action( 'admin_init', [ $this, 'maybe_handle_bulk_delete' ] );
	}

	public function maybe_handle_bulk_delete(): void {
		// Short-circuit without touching any user data.
		if ( ! isset( $_POST['pp_bulk_action'] ) ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'proofing-pins' ) { return; }

		// Nonce + capability BEFORE reading any POST data.
		check_admin_referer( 'pp_bulk_delete' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'proofing-pins' ) ); }

		$action = sanitize_key( wp_unslash( $_POST['pp_bulk_action'] ) );
		if ( $action !== 'delete' ) { return; }

		$ids     = array_map( 'intval', (array) ( $_POST['pin_ids'] ?? [] ) );
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( self::delete_pin_fully( $id ) ) { $deleted++; }
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'proofing-pins', 'deleted' => $deleted ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function menu(): void {
		add_menu_page(
			__( 'Proofing', 'proofing-pins' ),
			__( 'Proofing', 'proofing-pins' ),
			Capabilities::VIEW,
			'proofing-pins',
			[ $this, 'render_dashboard' ],
			'dashicons-format-chat',
			26
		);
		add_submenu_page(
			'proofing-pins',
			__( 'All Pins', 'proofing-pins' ),
			__( 'All Pins', 'proofing-pins' ),
			Capabilities::VIEW,
			'proofing-pins',
			[ $this, 'render_dashboard' ]
		);
		add_submenu_page(
			'proofing-pins',
			__( 'Settings', 'proofing-pins' ),
			__( 'Settings', 'proofing-pins' ),
			Capabilities::MANAGE,
			'proofing-pins-settings',
			[ $this, 'render_settings' ]
		);
		add_submenu_page(
			'proofing-pins',
			__( 'AI Integration', 'proofing-pins' ),
			__( 'AI Integration', 'proofing-pins' ),
			Capabilities::MANAGE,
			'proofing-pins-ai',
			[ $this, 'render_ai' ]
		);
	}

	public function render_ai(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'proofing-pins' ) );
		}
		$saved = false;
		if ( isset( $_POST['pp_ai_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pp_ai_nonce'] ) ), 'pp_ai_save' ) ) {
			AI::instance()->save_settings( [
				'enabled'         => ! empty( $_POST['enabled'] ),
				'auto_suggest'    => ! empty( $_POST['auto_suggest'] ),
				'provider'        => isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '',
				'model'           => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
				// API key: only sanitize control characters/whitespace; preserve the token as typed.
				'api_key'         => isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '',
				'request_timeout' => isset( $_POST['request_timeout'] ) ? (int) $_POST['request_timeout'] : 30,
			] );
			$saved = true;
		}
		$settings   = AI::instance()->get_settings();
		$masked_key = AI::instance()->masked_key();
		$catalog    = AI::model_catalog();
		include PP_PLUGIN_DIR . 'templates/admin-ai.php';
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, 'proofing-pins' ) === false ) {
			return;
		}
		wp_enqueue_style( 'pp-admin', PP_PLUGIN_URL . 'assets/css/admin.css', [], PP_VERSION );
		wp_enqueue_script( 'pp-admin', PP_PLUGIN_URL . 'assets/js/admin-dashboard.js', [], PP_VERSION, true );
		wp_localize_script( 'pp-admin', 'PP_ADMIN', [
			'restUrl' => esc_url_raw( rest_url( PP_REST_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'siteUrl' => home_url( '/' ),
		] );
	}

	public function render_dashboard(): void {
		$pin_id = isset( $_GET['pin'] ) ? (int) $_GET['pin'] : 0;
		if ( $pin_id ) {
			include PP_PLUGIN_DIR . 'templates/admin-pin-detail.php';
		} else {
			include PP_PLUGIN_DIR . 'templates/admin-dashboard.php';
		}
	}

	/**
	 * Fully delete a pin and all related artifacts:
	 * - screenshot attachment file + DB row
	 * - all replies (WP comments)
	 * - all post meta
	 * - the pin post itself
	 */
	public static function delete_pin_fully( int $pin_id ): bool {
		$post = get_post( $pin_id );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) { return false; }

		$screenshot_id = (int) get_post_meta( $pin_id, '_pp_screenshot_id', true );
		if ( $screenshot_id ) {
			wp_delete_attachment( $screenshot_id, true );
		}
		// wp_delete_post(true) cascades to: post meta, comments, term rels. That covers replies.
		wp_delete_post( $pin_id, true );
		return true;
	}

	public function register_settings(): void {
		register_setting( 'pp_settings_group', 'pp_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
			'default'           => [
				'position'          => 'right',
				'brand_color'       => '#2271b1',
				'allowed_roles'     => [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ],
				'auto_resolve_days' => 0,
			],
		] );
	}

	public function sanitize_settings( $input ): array {
		$out                       = [];
		$out['position']           = in_array( $input['position'] ?? '', [ 'left', 'right' ], true ) ? $input['position'] : 'right';
		$out['brand_color']        = sanitize_hex_color( $input['brand_color'] ?? '#2271b1' ) ?: '#2271b1';
		$roles                     = $input['allowed_roles'] ?? [];
		$out['allowed_roles']      = array_values( array_filter( array_map( 'sanitize_key', (array) $roles ) ) );
		$out['auto_resolve_days']  = max( 0, (int) ( $input['auto_resolve_days'] ?? 0 ) );
		$out['guest_pins_enabled'] = ! empty( $input['guest_pins_enabled'] );
		$out['guest_rate_limit']   = max( 1, min( 50, (int) ( $input['guest_rate_limit'] ?? 5 ) ) );
		$this->sync_roles( $out['allowed_roles'] );
		return $out;
	}

	private function sync_roles( array $allowed ): void {
		foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) { continue; }
			if ( in_array( $role_key, $allowed, true ) ) {
				$role->add_cap( Capabilities::CREATE );
			} else {
				$role->remove_cap( Capabilities::CREATE );
			}
		}
	}

	public function render_settings(): void {
		include PP_PLUGIN_DIR . 'templates/admin-settings.php';
	}
}

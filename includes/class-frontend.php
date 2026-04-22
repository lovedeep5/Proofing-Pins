<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Frontend {
	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_footer', [ $this, 'print_root' ] );
	}

	private function should_mount(): bool {
		if ( is_admin() ) { return false; }
		if ( is_user_logged_in() && current_user_can( Capabilities::CREATE ) ) { return true; }
		$settings = get_option( 'pp_settings', [] );
		return ! empty( $settings['guest_pins_enabled'] );
	}

	public function enqueue(): void {
		if ( ! $this->should_mount() ) { return; }

		wp_register_script(
			'pp-html-to-image',
			PP_PLUGIN_URL . 'assets/js/html-to-image.min.js',
			array(),
			'1.11.13',
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
		wp_enqueue_script(
			'pp-widget',
			PP_PLUGIN_URL . 'assets/js/widget.js',
			array( 'pp-html-to-image' ),
			PP_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);

		$user      = wp_get_current_user();
		$is_guest  = ! is_user_logged_in();
		$settings  = wp_parse_args( get_option( 'pp_settings', [] ), [
			'position'           => 'right',
			'brand_color'        => '#2271b1',
			'guest_pins_enabled' => false,
			'guest_rate_limit'   => 5,
		] );

		wp_localize_script( 'pp-widget', 'PP_CONFIG', [
			'restUrl'    => esc_url_raw( rest_url( PP_REST_NAMESPACE . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'  => PP_PLUGIN_URL,
			'user'       => [
				'id'        => $is_guest ? 0 : $user->ID,
				'name'      => $is_guest ? '' : $user->display_name,
				'avatar'    => $is_guest ? '' : get_avatar_url( $user->ID, [ 'size' => 48 ] ),
				'canManage' => ! $is_guest && current_user_can( Capabilities::MANAGE ),
				'isGuest'   => $is_guest,
			],
			'settings'   => $settings,
			'pageUrl'    => $this->current_page_path(),
			'pageTitle'  => wp_get_document_title(),
			'i18n'       => [
				'toggleOn'    => __( 'Start proofing', 'proofing-pins' ),
				'toggleOff'   => __( 'Exit proofing', 'proofing-pins' ),
				'prompt'      => __( 'Click anywhere to add a pin. Esc to exit.', 'proofing-pins' ),
				'placeholder' => __( 'Describe what needs attention…', 'proofing-pins' ),
				'submit'      => __( 'Post', 'proofing-pins' ),
				'cancel'      => __( 'Cancel', 'proofing-pins' ),
				'reply'       => __( 'Reply', 'proofing-pins' ),
				'capturing'   => __( 'Capturing screenshot…', 'proofing-pins' ),
				'posting'     => __( 'Posting…', 'proofing-pins' ),
				'statusOpen'      => __( 'Open', 'proofing-pins' ),
				'statusInProgress'=> __( 'In Progress', 'proofing-pins' ),
				'statusResolved'  => __( 'Resolved', 'proofing-pins' ),
				'statusArchived'  => __( 'Archived', 'proofing-pins' ),
				'postedBy'        => __( 'Posted by', 'proofing-pins' ),
				'replyPlaceholder'=> __( 'Write a reply…', 'proofing-pins' ),
				'deleteConfirm'   => __( 'Delete this pin?', 'proofing-pins' ),
				'guestIntro'      => __( 'Tell us who you are to leave feedback.', 'proofing-pins' ),
				'guestName'       => __( 'Your name', 'proofing-pins' ),
				'guestEmail'      => __( 'Your email', 'proofing-pins' ),
				'guestContinue'   => __( 'Continue', 'proofing-pins' ),
				'guestRemembered' => __( "We'll remember this for 30 days on this browser.", 'proofing-pins' ),
				'rateLimited'     => __( "Too many submissions from your network. Please try again later.", 'proofing-pins' ),
			],
		] );
	}

	public function print_root(): void {
		if ( ! $this->should_mount() ) { return; }
		echo '<div id="pp-root" data-pp-root></div>';
	}

	private function current_page_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) { return '/'; }
		$raw  = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		return $path ?: '/';
	}
}

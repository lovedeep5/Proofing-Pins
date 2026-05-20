<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Teams {
	public const OPTION_KEY = 'pp_teams_settings';

	public const EVENT_PIN_CREATED = 'pin_created';
	public const EVENT_REPLY_ADDED = 'reply_added';
	public const EVENT_STATUS_PREFIX = 'status_';

	private static ?Teams $instance = null;

	public static function instance(): Teams {
		if ( self::$instance === null ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function register(): void {
		add_action( 'proofingpins_pin_created', [ $this, 'on_pin_created' ], 20, 1 );
		add_action( 'wp_insert_comment', [ $this, 'on_comment_inserted' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
	}

	// ---------- settings ----------
	public function get_settings(): array {
		$defaults = [
			'enabled'      => false,
			'webhook_enc'  => '',
			'events'       => [
				self::EVENT_PIN_CREATED          => true,
				self::EVENT_REPLY_ADDED          => true,
				'status_' . CPT::STATUS_OPEN        => false,
				'status_' . CPT::STATUS_IN_PROGRESS => true,
				'status_' . CPT::STATUS_RESOLVED    => true,
				'status_' . CPT::STATUS_ARCHIVED    => false,
			],
			'last_status'  => '',
			'last_message' => '',
			'last_time'    => '',
		];
		$stored = get_option( self::OPTION_KEY, [] );
		$merged = wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
		// Ensure events sub-array is fully populated even if older settings exist.
		$merged['events'] = wp_parse_args( is_array( $merged['events'] ?? null ) ? $merged['events'] : [], $defaults['events'] );
		return $merged;
	}

	public function save_settings( array $input ): array {
		$current = $this->get_settings();
		$out     = $current;

		$out['enabled'] = ! empty( $input['enabled'] );

		if ( isset( $input['webhook_url'] ) && $input['webhook_url'] !== '' && $input['webhook_url'] !== '__unchanged__' ) {
			$url = trim( (string) $input['webhook_url'] );
			// Workflow webhooks are HTTPS URLs hosted on Azure Logic Apps / Office 365.
			if ( filter_var( $url, FILTER_VALIDATE_URL ) && strpos( $url, 'https://' ) === 0 ) {
				$out['webhook_enc'] = $this->encrypt( $url );
			}
		}
		if ( ! empty( $input['clear_webhook'] ) ) {
			$out['webhook_enc'] = '';
		}

		$events = is_array( $input['events'] ?? null ) ? $input['events'] : [];
		foreach ( array_keys( $current['events'] ) as $key ) {
			$out['events'][ $key ] = ! empty( $events[ $key ] );
		}

		update_option( self::OPTION_KEY, $out, false );
		return $out;
	}

	public function masked_webhook(): string {
		$url = $this->webhook_url();
		if ( $url === '' ) { return ''; }
		$parts = wp_parse_url( $url );
		$host  = $parts['host'] ?? '';
		return $host ? ( $host . '/…' ) : '…configured…';
	}

	public function webhook_url(): string {
		$s = $this->get_settings();
		return $s['webhook_enc'] ? $this->decrypt( $s['webhook_enc'] ) : '';
	}

	// ---------- encryption (mirrors AI class — AUTH_KEY-seeded AES-256-CBC) ----------
	private function key(): string {
		$seed = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'pp-teams|' . $seed, true );
	}

	private function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) { return 'b64:' . base64_encode( $plain ); }
		$iv = random_bytes( 16 );
		$ct = openssl_encrypt( $plain, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv );
		if ( $ct === false ) { return ''; }
		return 'enc1:' . base64_encode( $iv . $ct );
	}

	private function decrypt( string $stored ): string {
		if ( $stored === '' ) { return ''; }
		if ( strpos( $stored, 'b64:' ) === 0 ) { return base64_decode( substr( $stored, 4 ) ) ?: ''; }
		if ( strpos( $stored, 'enc1:' ) !== 0 ) { return ''; }
		$raw = base64_decode( substr( $stored, 5 ) );
		if ( ! $raw || strlen( $raw ) < 17 ) { return ''; }
		$iv    = substr( $raw, 0, 16 );
		$ct    = substr( $raw, 16 );
		$plain = openssl_decrypt( $ct, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv );
		return $plain !== false ? $plain : '';
	}

	// ---------- event handlers ----------
	public function on_pin_created( int $post_id ): void {
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) || empty( $s['events'][ self::EVENT_PIN_CREATED ] ) ) { return; }
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return; }
		$this->send_card( $this->build_pin_created_card( $post ) );
	}

	public function on_comment_inserted( int $comment_id, $comment ): void {
		if ( ! $comment || $comment->comment_type !== 'pp_reply' ) { return; }
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) || empty( $s['events'][ self::EVENT_REPLY_ADDED ] ) ) { return; }
		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return; }
		$this->send_card( $this->build_reply_card( $post, $comment ) );
	}

	public function on_transition_post_status( string $new, string $old, $post ): void {
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return; }
		if ( $new === $old ) { return; }
		if ( ! in_array( $new, CPT::all_statuses(), true ) ) { return; }
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$event_key = self::EVENT_STATUS_PREFIX . $new;
		if ( empty( $s['events'][ $event_key ] ) ) { return; }
		// Skip the synthetic "draft → pp_open" insert transition (already covered by pin_created).
		if ( $old === 'new' || $old === 'auto-draft' || $old === 'draft' ) { return; }
		$this->send_card( $this->build_status_card( $post, $old, $new ) );
	}

	// ---------- public ops ----------
	public function send_test(): array {
		$url = $this->webhook_url();
		if ( $url === '' ) {
			return [ 'ok' => false, 'message' => __( 'No webhook URL configured.', 'proofing-pins' ) ];
		}
		$card = $this->build_card_payload(
			__( 'Proofing Pins — test message', 'proofing-pins' ),
			__( 'If you can read this, your Teams Workflow webhook is wired up correctly.', 'proofing-pins' ),
			[
				[ 'title' => __( 'Site', 'proofing-pins' ), 'value' => home_url( '/' ) ],
				[ 'title' => __( 'Sent by', 'proofing-pins' ), 'value' => wp_get_current_user()->display_name ?: 'system' ],
				[ 'title' => __( 'Time', 'proofing-pins' ), 'value' => current_time( 'mysql' ) ],
			],
			[]
		);
		return $this->send_card( $card );
	}

	public function send_card( array $card ): array {
		$url = $this->webhook_url();
		if ( $url === '' ) {
			$this->record_delivery( false, __( 'No webhook URL configured.', 'proofing-pins' ) );
			return [ 'ok' => false, 'message' => __( 'No webhook URL configured.', 'proofing-pins' ) ];
		}

		$response = wp_remote_post( $url, [
			'timeout' => 12,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $card ),
		] );

		if ( is_wp_error( $response ) ) {
			$this->record_delivery( false, $response->get_error_message() );
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$this->record_delivery( true, sprintf( 'HTTP %d', $code ) );
			return [ 'ok' => true, 'message' => sprintf( 'HTTP %d', $code ) ];
		}
		$body = wp_remote_retrieve_body( $response );
		$msg  = sprintf( 'HTTP %d — %s', $code, substr( (string) $body, 0, 200 ) );
		$this->record_delivery( false, $msg );
		return [ 'ok' => false, 'message' => $msg ];
	}

	private function record_delivery( bool $ok, string $message ): void {
		$s                  = $this->get_settings();
		$s['last_status']   = $ok ? 'ok' : 'error';
		$s['last_message']  = $message;
		$s['last_time']     = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $s, false );
	}

	// ---------- card builders ----------
	private function build_pin_created_card( $post ): array {
		return $this->build_card_payload(
			__( 'New proofing pin', 'proofing-pins' ),
			$post->post_content,
			$this->common_facts( $post, [
				[ 'title' => __( 'Event', 'proofing-pins' ), 'value' => __( 'Pin created', 'proofing-pins' ) ],
			] ),
			[ $this->open_in_admin_action( $post->ID ) ],
			$this->screenshot_data_url( $post->ID )
		);
	}

	private function build_reply_card( $post, $comment ): array {
		return $this->build_card_payload(
			sprintf( __( 'New reply on pin #%d', 'proofing-pins' ), $post->ID ),
			(string) $comment->comment_content,
			$this->common_facts( $post, [
				[ 'title' => __( 'Event', 'proofing-pins' ), 'value' => __( 'Reply added', 'proofing-pins' ) ],
				[ 'title' => __( 'Reply by', 'proofing-pins' ), 'value' => $comment->comment_author ?: __( 'Unknown', 'proofing-pins' ) ],
			] ),
			[ $this->open_in_admin_action( $post->ID ) ]
		);
	}

	private function build_status_card( $post, string $old, string $new ): array {
		return $this->build_card_payload(
			sprintf( __( 'Pin #%1$d → %2$s', 'proofing-pins' ), $post->ID, self::status_label( $new ) ),
			$post->post_content,
			$this->common_facts( $post, [
				[ 'title' => __( 'Event', 'proofing-pins' ), 'value' => __( 'Status changed', 'proofing-pins' ) ],
				[ 'title' => __( 'From', 'proofing-pins' ), 'value' => self::status_label( $old ) ],
				[ 'title' => __( 'To', 'proofing-pins' ), 'value' => self::status_label( $new ) ],
			] ),
			[ $this->open_in_admin_action( $post->ID ) ]
		);
	}

	private function common_facts( $post, array $extra = [] ): array {
		$page_url = (string) get_post_meta( $post->ID, '_pp_page_url', true );
		$is_guest = (int) get_post_meta( $post->ID, '_pp_is_guest', true ) === 1;
		$author   = $is_guest
			? ( (string) get_post_meta( $post->ID, '_pp_guest_name', true ) ?: __( 'Guest', 'proofing-pins' ) )
			: ( get_userdata( $post->post_author )->display_name ?? __( 'Unknown', 'proofing-pins' ) );

		$facts = $extra;
		$facts[] = [ 'title' => __( 'Status', 'proofing-pins' ), 'value' => self::status_label( $post->post_status ) ];
		$facts[] = [ 'title' => __( 'Author', 'proofing-pins' ), 'value' => $author ];
		$facts[] = [ 'title' => __( 'Page', 'proofing-pins' ), 'value' => $page_url ?: '/' ];
		return $facts;
	}

	private function open_in_admin_action( int $pin_id ): array {
		return [
			'type'  => 'Action.OpenUrl',
			'title' => __( 'Open in WP Admin', 'proofing-pins' ),
			'url'   => admin_url( 'admin.php?page=proofing-pins&pin=' . $pin_id ),
		];
	}

	/**
	 * Wrap an Adaptive Card body in the message envelope Teams Workflow webhooks expect.
	 */
	private function build_card_payload( string $title, string $body_text, array $facts, array $actions, string $image_url = '' ): array {
		$body = [
			[
				'type'   => 'TextBlock',
				'size'   => 'Medium',
				'weight' => 'Bolder',
				'text'   => $title,
				'wrap'   => true,
			],
		];
		$comment = trim( $body_text );
		if ( $comment !== '' ) {
			$body[] = [
				'type'      => 'TextBlock',
				'text'      => mb_substr( $comment, 0, 1500 ),
				'wrap'      => true,
				'spacing'   => 'Small',
				'isSubtle'  => false,
			];
		}
		if ( $facts ) {
			$body[] = [
				'type'  => 'FactSet',
				'facts' => array_map( static function ( $f ) {
					return [ 'title' => (string) ( $f['title'] ?? '' ), 'value' => (string) ( $f['value'] ?? '' ) ];
				}, $facts ),
			];
		}
		if ( $image_url !== '' ) {
			$body[] = [
				'type'    => 'Image',
				'url'     => $image_url,
				'size'    => 'Stretch',
				'altText' => 'Pin screenshot',
			];
		}

		$content = [
			'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
			'type'    => 'AdaptiveCard',
			'version' => '1.4',
			'body'    => $body,
		];
		if ( $actions ) {
			$content['actions'] = $actions;
		}

		return [
			'type'        => 'message',
			'attachments' => [
				[
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => $content,
				],
			],
		];
	}

	/**
	 * Build a data: URL for a pin's screenshot, resized + JPEG-compressed to fit a Teams
	 * Adaptive Card. Teams caps card payload at ~28KB; we aim well under that for the image
	 * alone so there's room for text. Returns '' when no screenshot, on errors, or when the
	 * compressed image still exceeds the budget.
	 */
	private function screenshot_data_url( int $post_id ): string {
		$attach_id = (int) get_post_meta( $post_id, '_pp_screenshot_id', true );
		if ( ! $attach_id ) { return ''; }
		$path = get_attached_file( $attach_id );
		if ( ! $path || ! file_exists( $path ) ) { return ''; }

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) { return ''; }
		$editor->resize( 600, null, false );
		$editor->set_quality( 65 );

		$tmp = wp_tempnam( 'pp-card-' );
		if ( ! $tmp ) { return ''; }
		$saved = $editor->save( $tmp, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			if ( file_exists( $tmp ) ) { wp_delete_file( $tmp ); }
			return '';
		}
		$bytes = file_get_contents( $saved['path'] );
		wp_delete_file( $saved['path'] );
		if ( $bytes === false ) { return ''; }

		// Hard budget: 16KB binary → ~22KB base64, leaves room for the rest of the card.
		if ( strlen( $bytes ) > 16 * 1024 ) { return ''; }

		return 'data:image/jpeg;base64,' . base64_encode( $bytes );
	}

	public static function status_label( string $status ): string {
		$map = [
			CPT::STATUS_OPEN        => __( 'Open', 'proofing-pins' ),
			CPT::STATUS_IN_PROGRESS => __( 'In Progress', 'proofing-pins' ),
			CPT::STATUS_RESOLVED    => __( 'Resolved', 'proofing-pins' ),
			CPT::STATUS_ARCHIVED    => __( 'Archived', 'proofing-pins' ),
		];
		return $map[ $status ] ?? $status;
	}

	/**
	 * Settings-page-facing list of events, in display order.
	 *
	 * @return array<int, array{key:string,label:string,group:string}>
	 */
	public static function event_catalog(): array {
		return [
			[ 'key' => self::EVENT_PIN_CREATED, 'label' => __( 'New pin created', 'proofing-pins' ), 'group' => __( 'Activity', 'proofing-pins' ) ],
			[ 'key' => self::EVENT_REPLY_ADDED, 'label' => __( 'Reply added to a pin', 'proofing-pins' ), 'group' => __( 'Activity', 'proofing-pins' ) ],
			[ 'key' => self::EVENT_STATUS_PREFIX . CPT::STATUS_OPEN,        'label' => __( 'Status changed → Open', 'proofing-pins' ),        'group' => __( 'Status changes', 'proofing-pins' ) ],
			[ 'key' => self::EVENT_STATUS_PREFIX . CPT::STATUS_IN_PROGRESS, 'label' => __( 'Status changed → In Progress', 'proofing-pins' ), 'group' => __( 'Status changes', 'proofing-pins' ) ],
			[ 'key' => self::EVENT_STATUS_PREFIX . CPT::STATUS_RESOLVED,    'label' => __( 'Status changed → Resolved', 'proofing-pins' ),    'group' => __( 'Status changes', 'proofing-pins' ) ],
			[ 'key' => self::EVENT_STATUS_PREFIX . CPT::STATUS_ARCHIVED,    'label' => __( 'Status changed → Archived', 'proofing-pins' ),    'group' => __( 'Status changes', 'proofing-pins' ) ],
		];
	}
}

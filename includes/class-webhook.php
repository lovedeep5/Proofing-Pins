<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic webhook integration. Sends JSON payloads to a user-configured URL
 * whenever subscribed events fire. Mirrors the Teams class shape but builds
 * plain JSON instead of Adaptive Cards.
 */
class Webhook {
	public const OPTION_KEY = 'proopin_webhook_settings';

	public const EVENT_PIN_CREATED   = 'pin_created';
	public const EVENT_REPLY_ADDED   = 'reply_added';
	public const EVENT_STATUS_PREFIX = 'status_';

	private static ?Webhook $instance = null;

	public static function instance(): Webhook {
		if ( self::$instance === null ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function register(): void {
		add_action( 'proofingpins_pin_created', [ $this, 'on_pin_created' ], 30, 1 );
		add_action( 'wp_insert_comment', [ $this, 'on_comment_inserted' ], 20, 2 );
		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 20, 3 );
	}

	// ---------- settings ----------
	public function get_settings(): array {
		$defaults = [
			'enabled'      => false,
			'webhook_enc'  => '',
			'events'       => [
				self::EVENT_PIN_CREATED                      => true,
				self::EVENT_REPLY_ADDED                      => true,
				self::EVENT_STATUS_PREFIX . CPT::STATUS_OPEN        => false,
				self::EVENT_STATUS_PREFIX . CPT::STATUS_IN_PROGRESS => true,
				self::EVENT_STATUS_PREFIX . CPT::STATUS_RESOLVED    => true,
				self::EVENT_STATUS_PREFIX . CPT::STATUS_ARCHIVED    => false,
			],
			'last_status'  => '',
			'last_message' => '',
			'last_time'    => '',
		];
		$stored = get_option( self::OPTION_KEY, [] );
		$merged = wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
		$merged['events'] = wp_parse_args( is_array( $merged['events'] ?? null ) ? $merged['events'] : [], $defaults['events'] );
		return $merged;
	}

	public function save_settings( array $input ): array {
		$current = $this->get_settings();
		$out     = $current;

		$out['enabled'] = ! empty( $input['enabled'] );

		if ( isset( $input['webhook_url'] ) && $input['webhook_url'] !== '' && $input['webhook_url'] !== '__unchanged__' ) {
			$url = trim( (string) $input['webhook_url'] );
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

	// ---------- encryption (AUTH_KEY-seeded AES-256-CBC, independent salt) ----------
	private function key(): string {
		$seed = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'proopin-webhook|' . $seed, true );
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
		$this->send_payload( $this->build_pin_created_payload( $post ) );
	}

	public function on_comment_inserted( int $comment_id, $comment ): void {
		if ( ! $comment || $comment->comment_type !== 'proopin_reply' ) { return; }
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) || empty( $s['events'][ self::EVENT_REPLY_ADDED ] ) ) { return; }
		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return; }
		$this->send_payload( $this->build_reply_payload( $post, $comment ) );
	}

	public function on_transition_post_status( string $new, string $old, $post ): void {
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return; }
		if ( $new === $old ) { return; }
		if ( ! in_array( $new, CPT::all_statuses(), true ) ) { return; }
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$event_key = self::EVENT_STATUS_PREFIX . $new;
		if ( empty( $s['events'][ $event_key ] ) ) { return; }
		// Skip the synthetic "draft → proopin_open" insert transition (already covered by pin_created).
		if ( $old === 'new' || $old === 'auto-draft' || $old === 'draft' ) { return; }
		$this->send_payload( $this->build_status_payload( $post, $old, $new ) );
	}

	// ---------- public ops ----------
	public function send_test(): array {
		$url = $this->webhook_url();
		if ( $url === '' ) {
			return [ 'ok' => false, 'message' => __( 'No webhook URL configured.', 'proofing-pins' ) ];
		}
		$payload = [
			'event'     => 'test',
			'timestamp' => $this->iso_now(),
			'site'      => $this->site_envelope(),
			'message'   => __( 'Proofing Pins test message. If you can read this, your webhook is wired up correctly.', 'proofing-pins' ),
			'sent_by'   => wp_get_current_user()->display_name ?: 'system',
		];
		return $this->send_payload( $payload );
	}

	public function send_payload( array $payload ): array {
		$url = $this->webhook_url();
		if ( $url === '' ) {
			$this->record_delivery( false, __( 'No webhook URL configured.', 'proofing-pins' ) );
			return [ 'ok' => false, 'message' => __( 'No webhook URL configured.', 'proofing-pins' ) ];
		}

		$response = wp_remote_post( $url, [
			'timeout' => 12,
			'headers' => [
				'Content-Type' => 'application/json',
				'User-Agent'   => 'ProofingPins/' . PROOFING_PINS_VERSION . ' (+' . home_url( '/' ) . ')',
			],
			'body'    => wp_json_encode( $payload ),
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

	// ---------- payload builders ----------
	private function build_pin_created_payload( $post ): array {
		return [
			'event'     => self::EVENT_PIN_CREATED,
			'timestamp' => $this->iso_now(),
			'site'      => $this->site_envelope(),
			'pin'       => $this->pin_envelope( $post ),
		];
	}

	private function build_reply_payload( $post, $comment ): array {
		$author_id = (int) $comment->user_id;
		$author    = $author_id ? get_userdata( $author_id ) : null;
		return [
			'event'     => self::EVENT_REPLY_ADDED,
			'timestamp' => $this->iso_now(),
			'site'      => $this->site_envelope(),
			'pin'       => $this->pin_envelope( $post ),
			'reply'     => [
				'id'         => (int) $comment->comment_ID,
				'comment'    => (string) $comment->comment_content,
				'author'     => [
					'id'       => $author_id,
					'name'     => $author ? $author->display_name : (string) $comment->comment_author,
					'email'    => $author ? $author->user_email : '',
					'is_guest' => $author_id === 0,
				],
				'created_at' => mysql_to_rfc3339( $comment->comment_date_gmt ),
			],
		];
	}

	private function build_status_payload( $post, string $old, string $new ): array {
		return [
			'event'            => 'status_changed',
			'timestamp'        => $this->iso_now(),
			'site'             => $this->site_envelope(),
			'pin'              => $this->pin_envelope( $post ),
			'old_status'       => $old,
			'old_status_label' => Teams::status_label( $old ),
			'new_status'       => $new,
			'new_status_label' => Teams::status_label( $new ),
		];
	}

	private function pin_envelope( $post ): array {
		$is_guest      = (int) get_post_meta( $post->ID, '_proopin_is_guest', true ) === 1;
		$guest_name    = (string) get_post_meta( $post->ID, '_proopin_guest_name', true );
		$guest_email   = (string) get_post_meta( $post->ID, '_proopin_guest_email', true );
		$author        = $post->post_author ? get_userdata( (int) $post->post_author ) : null;
		$screenshot_id = (int) get_post_meta( $post->ID, '_proopin_screenshot_id', true );
		$page_url      = (string) get_post_meta( $post->ID, '_proopin_page_url', true );
		$page_title    = (string) get_post_meta( $post->ID, '_proopin_page_title', true );

		return [
			'id'             => (int) $post->ID,
			'comment'        => (string) $post->post_content,
			'status'         => (string) $post->post_status,
			'status_label'   => Teams::status_label( (string) $post->post_status ),
			'page_url'       => $page_url ?: '/',
			'page_title'     => $page_title,
			'screenshot_url' => $screenshot_id ? (string) wp_get_attachment_url( $screenshot_id ) : '',
			'admin_url'      => admin_url( 'admin.php?page=proofing-pins&pin=' . $post->ID ),
			'author'         => [
				'id'       => (int) $post->post_author,
				'name'     => $author ? $author->display_name : ( $guest_name ?: __( 'Guest', 'proofing-pins' ) ),
				'email'    => $author ? $author->user_email : $guest_email,
				'is_guest' => $is_guest,
			],
			'created_at'     => mysql_to_rfc3339( $post->post_date_gmt ),
		];
	}

	private function site_envelope(): array {
		return [
			'url'  => home_url( '/' ),
			'name' => get_bloginfo( 'name' ),
		];
	}

	private function iso_now(): string {
		return gmdate( 'c' );
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

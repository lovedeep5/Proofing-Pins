<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AI {
	public const OPTION_KEY = 'pp_ai_settings';
	public const CRON_HOOK  = 'pp_ai_generate_suggestion';

	private static ?AI $instance = null;

	public static function instance(): AI {
		if ( self::$instance === null ) { self::$instance = new self(); }
		return self::$instance;
	}

	public function register(): void {
		add_action( 'pp_pin_created', [ $this, 'on_pin_created' ], 10, 1 );
		add_action( self::CRON_HOOK, [ $this, 'run_cron_job' ], 10, 1 );
	}

	// ---------- settings helpers ----------
	public function get_settings(): array {
		$defaults = [
			'enabled'       => false,
			'provider'      => 'anthropic',
			'model'         => 'claude-sonnet-4-6',
			'api_key_enc'   => '',
			'auto_suggest'  => true,
			'request_timeout' => 30,
		];
		$stored = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
	}

	public function save_settings( array $input ): array {
		$current = $this->get_settings();
		$out     = $current;

		$out['enabled']       = ! empty( $input['enabled'] );
		$out['auto_suggest']  = ! empty( $input['auto_suggest'] );
		$out['request_timeout'] = max( 5, min( 120, (int) ( $input['request_timeout'] ?? 30 ) ) );

		$providers = [ 'openai', 'anthropic', 'gemini', 'openrouter' ];
		$out['provider'] = in_array( $input['provider'] ?? '', $providers, true ) ? $input['provider'] : 'anthropic';
		$out['model']    = sanitize_text_field( $input['model'] ?? '' );

		if ( isset( $input['api_key'] ) && $input['api_key'] !== '' && $input['api_key'] !== '__unchanged__' ) {
			$out['api_key_enc'] = $this->encrypt( (string) $input['api_key'] );
		}

		update_option( self::OPTION_KEY, $out, false );
		return $out;
	}

	public function masked_key(): string {
		$s = $this->get_settings();
		if ( empty( $s['api_key_enc'] ) ) { return ''; }
		$plain = $this->decrypt( $s['api_key_enc'] );
		if ( ! $plain ) { return ''; }
		$len   = strlen( $plain );
		if ( $len <= 8 ) { return str_repeat( '•', max( 0, $len - 2 ) ) . substr( $plain, -2 ); }
		return substr( $plain, 0, 3 ) . str_repeat( '•', 10 ) . substr( $plain, -4 );
	}

	// ---------- encryption ----------
	private function key(): string {
		$seed = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'pp-ai|' . $seed, true );
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

	// ---------- provider model catalog ----------
	public static function model_catalog(): array {
		return [
			'anthropic'  => [
				'label'  => 'Anthropic (Claude)',
				'models' => [
					'claude-opus-4-7'   => 'Claude Opus 4.7 — most capable',
					'claude-sonnet-4-6' => 'Claude Sonnet 4.6 — balanced (recommended)',
					'claude-haiku-4-5'  => 'Claude Haiku 4.5 — fastest, cheapest',
				],
			],
			'openai'     => [
				'label'  => 'OpenAI',
				'models' => [
					'gpt-4o'        => 'GPT-4o',
					'gpt-4o-mini'   => 'GPT-4o mini — cheaper',
					'gpt-4.1'       => 'GPT-4.1',
					'gpt-4.1-mini'  => 'GPT-4.1 mini',
				],
			],
			'gemini'     => [
				'label'  => 'Google Gemini',
				'models' => [
					'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
					'gemini-2.5-flash' => 'Gemini 2.5 Flash — cheaper',
					'gemini-2.0-flash' => 'Gemini 2.0 Flash',
				],
			],
			'openrouter' => [
				'label'  => 'OpenRouter',
				'models' => [
					'anthropic/claude-sonnet-4-6' => 'anthropic/claude-sonnet-4-6',
					'openai/gpt-4o'               => 'openai/gpt-4o',
					'google/gemini-2.5-pro'       => 'google/gemini-2.5-pro',
					'__custom__'                  => 'Custom (enter below)',
				],
			],
		];
	}

	// ---------- pin hooks ----------
	public function on_pin_created( int $pin_id ): void {
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) || empty( $s['auto_suggest'] ) ) { return; }
		update_post_meta( $pin_id, '_pp_ai_status', 'queued' );
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $pin_id ] ) ) {
			wp_schedule_single_event( time() + 1, self::CRON_HOOK, [ $pin_id ] );
		}
		// Best-effort immediate spawn so it runs on the next shutdown.
		if ( function_exists( 'spawn_cron' ) ) { spawn_cron(); }
	}

	public function run_cron_job( int $pin_id ): void {
		$this->generate_for_pin( $pin_id );
	}

	// ---------- main generation path ----------
	public function generate_for_pin( int $pin_id ) {
		$post = get_post( $pin_id );
		if ( ! $post || $post->post_type !== PP_POST_TYPE ) {
			return new \WP_Error( 'not_found', __( 'Pin not found.', 'proofing-pins' ) );
		}
		$s = $this->get_settings();
		if ( empty( $s['enabled'] ) ) {
			return new \WP_Error( 'ai_disabled', __( 'AI integration is disabled.', 'proofing-pins' ) );
		}
		$key = $this->decrypt( $s['api_key_enc'] );
		if ( ! $key ) {
			update_post_meta( $pin_id, '_pp_ai_status', 'error' );
			update_post_meta( $pin_id, '_pp_ai_error', 'no_api_key' );
			return new \WP_Error( 'no_api_key', __( 'No API key configured.', 'proofing-pins' ) );
		}

		update_post_meta( $pin_id, '_pp_ai_status', 'running' );

		$prompt  = $this->build_prompt( $post );
		$response = $this->call_provider( $s['provider'], $s['model'], $key, $prompt, (int) $s['request_timeout'] );

		if ( is_wp_error( $response ) ) {
			update_post_meta( $pin_id, '_pp_ai_status', 'error' );
			update_post_meta( $pin_id, '_pp_ai_error', $response->get_error_message() );
			return $response;
		}

		$parsed = $this->parse_response( $response );

		update_post_meta( $pin_id, '_pp_ai_status', 'ready' );
		update_post_meta( $pin_id, '_pp_ai_suggestion', $parsed );
		update_post_meta( $pin_id, '_pp_ai_suggestion_model', $s['provider'] . '/' . $s['model'] );
		update_post_meta( $pin_id, '_pp_ai_suggestion_created_at', current_time( 'mysql', true ) );
		delete_post_meta( $pin_id, '_pp_ai_error' );

		// Persist change_op separately for the Apply UI, only if the proposed op
		// matches our allowlist AND matches the stored widget for this pin.
		$pin_widget_type = (string) get_post_meta( $pin_id, '_pp_elementor_widget_type', true );
		$pin_widget_id   = (string) get_post_meta( $pin_id, '_pp_elementor_widget_id', true );
		delete_post_meta( $pin_id, '_pp_ai_change_op' );
		if ( ! empty( $parsed['change_op'] ) && is_array( $parsed['change_op'] ) && $pin_widget_type && $pin_widget_id ) {
			$op = $parsed['change_op'];
			if ( ( $op['op'] ?? '' ) === 'update_widget_setting'
				&& ( $op['widget_type'] ?? '' ) === $pin_widget_type
				&& ( $op['widget_id'] ?? '' ) === $pin_widget_id
				&& Elementor_Writer::is_allowed( $pin_widget_type, (string) ( $op['setting_key'] ?? '' ) ) ) {
				update_post_meta( $pin_id, '_pp_ai_change_op', [
					'op'           => 'update_widget_setting',
					'widget_type'  => $pin_widget_type,
					'widget_id'    => $pin_widget_id,
					'setting_key'  => sanitize_key( $op['setting_key'] ),
					'setting_label'=> sanitize_text_field( $op['setting_label'] ?? '' ),
					'new_value'    => is_string( $op['new_value'] ) ? substr( $op['new_value'], 0, 20000 ) : '',
				] );
			}
		}

		return [
			'status'     => 'ready',
			'suggestion' => $parsed,
			'model'      => $s['provider'] . '/' . $s['model'],
		];
	}

	// ---------- prompt ----------
	private function build_prompt( \WP_Post $post ): array {
		$comment    = $post->post_content;
		$page_url   = (string) get_post_meta( $post->ID, '_pp_page_url', true );
		$page_ttl   = (string) get_post_meta( $post->ID, '_pp_page_title', true );
		$selector   = (string) get_post_meta( $post->ID, '_pp_anchor_selector', true ) ?: (string) get_post_meta( $post->ID, '_pp_element_selector', true );
		$elem_html  = (string) get_post_meta( $post->ID, '_pp_element_html', true );
		$elem_tag   = (string) get_post_meta( $post->ID, '_pp_element_tag', true );
		$viewport   = (int) get_post_meta( $post->ID, '_pp_viewport_w', true ) . '×' . (int) get_post_meta( $post->ID, '_pp_viewport_h', true );
		$widget_type= (string) get_post_meta( $post->ID, '_pp_elementor_widget_type', true );
		$widget_id  = (string) get_post_meta( $post->ID, '_pp_elementor_widget_id', true );

		$elementor_block = '';
		if ( $widget_type && $widget_id ) {
			$elementor_block = <<<ELEM

This element is inside an Elementor widget: widget_type="{$widget_type}", widget_id="{$widget_id}".

When this is the case, PREFER Elementor UI instructions over CSS. Phrase suggestion like:
"In the Elementor editor, open this widget and go to Style → Typography → Color. Set the color to #F97316."

Additionally, when the change is one of the allowlisted operations below AND your confidence is high, INCLUDE a `change_op` object in the JSON that our plugin can apply directly:

Allowlist (widget_type → setting_key → value_type):
- heading       → title                (text)
- heading       → title_color          (color)
- button        → text                 (text)
- button        → background_color     (color)
- button        → button_text_color    (color)
- text-editor   → editor               (html — plain text preferred)
- text-editor   → text_color           (color)

change_op shape:
{
  "op": "update_widget_setting",
  "widget_type": "heading",
  "widget_id": "{$widget_id}",
  "setting_key": "title",
  "setting_label": "Heading text",
  "new_value": "New text"
}

ONLY include change_op when the intended fix maps cleanly to one allowlisted operation. Otherwise omit it.
ELEM;
		}

		$system = <<<SYS
You are a senior web developer assistant reviewing client feedback on a live website. Given a reviewer's comment and the exact HTML element they clicked on, you propose a concrete, safe change a developer can implement.

Respond with VALID JSON ONLY (no markdown fences) in this shape:
{
  "category": "text_change" | "css_tweak" | "content_swap" | "image_change" | "link_change" | "layout" | "bug" | "unclear" | "other",
  "confidence": 0-100,
  "summary": "One sentence describing what the reviewer wants in plain English.",
  "suggestion": "Concrete, actionable recommendation — what to change and where.",
  "snippet": "Optional: exact CSS rule, text diff, or code fragment a developer can copy. Empty string if not applicable.",
  "snippet_language": "css" | "html" | "text" | "",
  "risk": "low" | "medium" | "high",
  "notes": "Optional caveats (e.g., affects other pages, may need design review).",
  "change_op": { ...optional, see Elementor rules below... }
}

Rules:
- If the comment is vague, set category="unclear" and write a summary asking for specifics.
- Prefer scoped CSS (use the provided selector) over global changes.
- Keep snippet under 400 characters.
- Never invent element content — only suggest based on what's visible in the provided HTML.
{$elementor_block}
SYS;

		$user = sprintf(
			"Reviewer's comment:\n%s\n\nContext:\n- Page: %s (%s)\n- Viewport: %s\n- Clicked element tag: <%s>\n- CSS selector: %s\n- Element HTML (truncated):\n%s\n",
			$comment,
			$page_ttl ?: '(untitled)',
			$page_url ?: '/',
			$viewport,
			$elem_tag ?: 'unknown',
			$selector ?: '(unknown)',
			$elem_html ?: '(not captured)'
		);

		return [ 'system' => $system, 'user' => $user ];
	}

	// ---------- dynamic model listing ----------
	public function list_models( string $provider, string $api_key, bool $force_refresh = false ) {
		$providers = [ 'openai', 'anthropic', 'gemini', 'openrouter' ];
		if ( ! in_array( $provider, $providers, true ) ) {
			return new \WP_Error( 'unknown_provider', 'Unknown provider.' );
		}
		// Resolve key: use provided, or fall back to stored if caller passed __unchanged__.
		if ( $api_key === '' || $api_key === '__unchanged__' ) {
			$api_key = $this->decrypt( $this->get_settings()['api_key_enc'] );
		}
		if ( $provider !== 'openrouter' && ! $api_key ) {
			// No key — caller should fall back to curated catalog.
			return new \WP_Error( 'no_api_key', 'API key required to list models for this provider.' );
		}

		$cache_key = 'pp_ai_models_' . $provider . '_' . substr( md5( $api_key . '|v2' ), 0, 12 );
		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return [ 'models' => $cached, 'cached' => true ];
			}
		}

		switch ( $provider ) {
			case 'openai':     $models = $this->fetch_openai_models( $api_key ); break;
			case 'anthropic':  $models = $this->fetch_anthropic_models( $api_key ); break;
			case 'gemini':     $models = $this->fetch_gemini_models( $api_key ); break;
			case 'openrouter': $models = $this->fetch_openrouter_models( $api_key ); break;
			default:           $models = new \WP_Error( 'unknown_provider', 'Unknown provider.' );
		}

		if ( is_wp_error( $models ) ) { return $models; }
		if ( empty( $models ) )      { return new \WP_Error( 'empty', 'Provider returned no models.' ); }

		set_transient( $cache_key, $models, 6 * HOUR_IN_SECONDS );
		return [ 'models' => $models, 'cached' => false ];
	}

	private function fetch_openai_models( string $key ) {
		$res = wp_remote_get( 'https://api.openai.com/v1/models', [
			'timeout' => 15,
			'headers' => [ 'authorization' => 'Bearer ' . $key ],
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) { return new \WP_Error( 'provider_error', $json['error']['message'] ?? 'HTTP ' . $code ); }
		$out = [];
		foreach ( (array) ( $json['data'] ?? [] ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( ! $id ) { continue; }
			// Keep chat/completion models only; skip embeddings/tts/whisper/moderations/dall-e.
			if ( preg_match( '/^(gpt-|o1|o3|chatgpt)/i', $id ) ) {
				$out[] = [ 'id' => $id, 'label' => $id ];
			}
		}
		usort( $out, fn( $a, $b ) => strcmp( $b['id'], $a['id'] ) );
		return $out;
	}

	private function fetch_anthropic_models( string $key ) {
		$res = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', [
			'timeout' => 15,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			],
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) { return new \WP_Error( 'provider_error', $json['error']['message'] ?? 'HTTP ' . $code ); }
		$out = [];
		foreach ( (array) ( $json['data'] ?? [] ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( ! $id ) { continue; }
			$out[] = [ 'id' => $id, 'label' => ( $m['display_name'] ?? $id ) ];
		}
		return $out;
	}

	private function fetch_gemini_models( string $key ) {
		$res = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $key ) . '&pageSize=200', [
			'timeout' => 15,
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) { return new \WP_Error( 'provider_error', $json['error']['message'] ?? 'HTTP ' . $code ); }
		$out = [];
		foreach ( (array) ( $json['models'] ?? [] ) as $m ) {
			$name    = (string) ( $m['name'] ?? '' );
			$methods = (array) ( $m['supportedGenerationMethods'] ?? [] );
			if ( ! in_array( 'generateContent', $methods, true ) ) { continue; }
			$id = preg_replace( '#^models/#', '', $name );
			// keep text/gemini models; skip embedding/aqa
			if ( strpos( $id, 'gemini' ) !== false ) {
				$out[] = [ 'id' => $id, 'label' => ( $m['displayName'] ?? $id ) ];
			}
		}
		return $out;
	}

	private function fetch_openrouter_models( string $key ) {
		// OpenRouter model listing is public — key is optional.
		$headers = [ 'HTTP-Referer' => home_url( '/' ), 'X-Title' => 'Proofing Pins' ];
		if ( $key ) { $headers['authorization'] = 'Bearer ' . $key; }
		$res = wp_remote_get( 'https://openrouter.ai/api/v1/models', [
			'timeout' => 20,
			'headers' => $headers,
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) { return new \WP_Error( 'provider_error', $json['error']['message'] ?? 'HTTP ' . $code ); }
		$out = [];
		foreach ( (array) ( $json['data'] ?? [] ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( ! $id ) { continue; }
			$modality = $m['architecture']['modality'] ?? '';
			// Skip non-text-output models.
			if ( $modality && strpos( (string) $modality, 'text' ) === false ) { continue; }
			$name     = (string) ( $m['name'] ?? $id );
			$ctx      = (int) ( $m['context_length'] ?? 0 );
			$label    = $name . ( $ctx ? ' · ' . number_format( $ctx ) . ' ctx' : '' );
			$out[] = [ 'id' => $id, 'label' => $label ];
		}
		// alphabetic by id for predictable UX
		usort( $out, fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );
		return $out;
	}

	// ---------- provider adapters ----------
	public function test_connection( string $provider, string $api_key, string $model ) {
		if ( $api_key === '' || $api_key === '__unchanged__' ) {
			// allow using stored key when testing existing config
			$api_key = $this->decrypt( $this->get_settings()['api_key_enc'] );
		}
		if ( ! $api_key ) { return new \WP_Error( 'no_key', __( 'API key required.', 'proofing-pins' ) ); }

		$prompt = [
			'system' => 'You are a test endpoint. Respond with exactly: {"ok":true}',
			'user'   => 'ping',
		];
		$res = $this->call_provider( $provider ?: 'anthropic', $model ?: 'claude-haiku-4-5', $api_key, $prompt, 15 );
		if ( is_wp_error( $res ) ) { return $res; }
		return __( 'Connection OK — received a response.', 'proofing-pins' );
	}

	private function call_provider( string $provider, string $model, string $key, array $prompt, int $timeout ) {
		switch ( $provider ) {
			case 'anthropic':  return $this->call_anthropic( $model, $key, $prompt, $timeout );
			case 'openai':     return $this->call_openai_compat( 'https://api.openai.com/v1/chat/completions', $model, $key, $prompt, $timeout );
			case 'openrouter': return $this->call_openai_compat( 'https://openrouter.ai/api/v1/chat/completions', $model, $key, $prompt, $timeout, true );
			case 'gemini':     return $this->call_gemini( $model, $key, $prompt, $timeout );
		}
		return new \WP_Error( 'unknown_provider', 'Unknown provider: ' . $provider );
	}

	private function call_anthropic( string $model, string $key, array $prompt, int $timeout ) {
		$body = [
			'model'      => $model,
			'max_tokens' => 800,
			'system'     => $prompt['system'],
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt['user'] ] ],
		];
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => $timeout,
			'headers' => [
				'content-type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) {
			$msg = $json['error']['message'] ?? ( 'HTTP ' . $code );
			return new \WP_Error( 'provider_error', $msg );
		}
		$text = '';
		foreach ( (array) ( $json['content'] ?? [] ) as $blk ) {
			if ( ( $blk['type'] ?? '' ) === 'text' ) { $text .= $blk['text']; }
		}
		return $text;
	}

	private function call_openai_compat( string $url, string $model, string $key, array $prompt, int $timeout, bool $is_openrouter = false ) {
		$body = [
			'model'    => $model,
			'messages' => [
				[ 'role' => 'system', 'content' => $prompt['system'] ],
				[ 'role' => 'user',   'content' => $prompt['user'] ],
			],
			'temperature' => 0.2,
			'max_tokens'  => 800,
			'response_format' => [ 'type' => 'json_object' ],
		];
		$headers = [
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $key,
		];
		if ( $is_openrouter ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = 'Proofing Pins';
		}
		$res = wp_remote_post( $url, [
			'timeout' => $timeout,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) {
			$msg = $json['error']['message'] ?? ( 'HTTP ' . $code );
			return new \WP_Error( 'provider_error', $msg );
		}
		return (string) ( $json['choices'][0]['message']['content'] ?? '' );
	}

	private function call_gemini( string $model, string $key, array $prompt, int $timeout ) {
		$url = sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode( $model ), rawurlencode( $key ) );
		$body = [
			'systemInstruction' => [ 'parts' => [ [ 'text' => $prompt['system'] ] ] ],
			'contents'          => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $prompt['user'] ] ] ] ],
			'generationConfig'  => [
				'temperature' => 0.2,
				'maxOutputTokens' => 800,
				'responseMimeType' => 'application/json',
			],
		];
		$res = wp_remote_post( $url, [
			'timeout' => $timeout,
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $res ) ) { return $res; }
		$code = wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 ) {
			$msg = $json['error']['message'] ?? ( 'HTTP ' . $code );
			return new \WP_Error( 'provider_error', $msg );
		}
		$text = '';
		foreach ( (array) ( $json['candidates'][0]['content']['parts'] ?? [] ) as $p ) {
			$text .= $p['text'] ?? '';
		}
		return $text;
	}

	// ---------- response parsing ----------
	private function parse_response( string $raw ): array {
		$trim = trim( $raw );
		// Strip code fences if model added them despite instructions.
		$trim = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $trim );
		$json = json_decode( $trim, true );
		if ( ! is_array( $json ) ) {
			// Best-effort: look for first {...} block.
			if ( preg_match( '/\{.*\}/s', $trim, $m ) ) {
				$json = json_decode( $m[0], true );
			}
		}
		if ( ! is_array( $json ) ) {
			return [
				'category'   => 'other',
				'confidence' => 0,
				'summary'    => wp_trim_words( $raw, 40, '…' ),
				'suggestion' => $raw,
				'snippet'    => '',
				'snippet_language' => '',
				'risk'       => 'medium',
				'notes'      => 'Model returned non-JSON — showing raw output.',
			];
		}
		return [
			'category'   => sanitize_key( $json['category'] ?? 'other' ),
			'confidence' => max( 0, min( 100, (int) ( $json['confidence'] ?? 0 ) ) ),
			'summary'    => sanitize_textarea_field( (string) ( $json['summary'] ?? '' ) ),
			'suggestion' => sanitize_textarea_field( (string) ( $json['suggestion'] ?? '' ) ),
			'snippet'    => (string) ( $json['snippet'] ?? '' ),
			'snippet_language' => sanitize_key( $json['snippet_language'] ?? '' ),
			'risk'       => in_array( $json['risk'] ?? '', [ 'low', 'medium', 'high' ], true ) ? $json['risk'] : 'medium',
			'notes'      => sanitize_textarea_field( (string) ( $json['notes'] ?? '' ) ),
			'change_op'  => isset( $json['change_op'] ) && is_array( $json['change_op'] ) ? $json['change_op'] : null,
		];
	}
}

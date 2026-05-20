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
		add_submenu_page(
			'proofing-pins',
			__( 'Teams Integration', 'proofing-pins' ),
			__( 'Teams Integration', 'proofing-pins' ),
			Capabilities::MANAGE,
			'proofing-pins-teams',
			[ $this, 'render_teams' ]
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
		include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-ai.php';
	}

	public function render_teams(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'proofing-pins' ) );
		}
		$saved = false;
		if ( isset( $_POST['pp_teams_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pp_teams_nonce'] ) ), 'pp_teams_save' ) ) {
			$events = [];
			if ( isset( $_POST['events'] ) && is_array( $_POST['events'] ) ) {
				foreach ( $_POST['events'] as $k => $v ) {
					$events[ sanitize_key( $k ) ] = (bool) $v;
				}
			}
			Teams::instance()->save_settings( [
				'enabled'       => ! empty( $_POST['enabled'] ),
				// Webhook URL contains a SAS-style signature. sanitize_text_field() strips %XX
				// sequences, which would break the signature on URL-encoded query params
				// (e.g. sp=%2Ftriggers%2Fmanual%2Frun). Only unslash + trim. Scheme/URL
				// validation happens inside Teams::save_settings().
				'webhook_url'   => isset( $_POST['webhook_url'] ) ? trim( (string) wp_unslash( $_POST['webhook_url'] ) ) : '',
				'clear_webhook' => ! empty( $_POST['clear_webhook'] ),
				'events'        => $events,
			] );
			$saved = true;
		}
		$settings       = Teams::instance()->get_settings();
		$masked_webhook = Teams::instance()->masked_webhook();
		include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-teams.php';
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, 'proofing-pins' ) === false ) {
			return;
		}
		wp_enqueue_style( 'pp-admin', PROOFING_PINS_PLUGIN_URL . 'assets/css/admin.css', array(), PROOFING_PINS_VERSION );
		wp_enqueue_script(
			'pp-admin',
			PROOFING_PINS_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array(),
			PROOFING_PINS_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
		wp_localize_script( 'pp-admin', 'PP_ADMIN', [
			'restUrl' => esc_url_raw( rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'siteUrl' => home_url( '/' ),
		] );
		if ( false !== strpos( $hook, 'proofing-pins-ai' ) ) {
			$ai_settings = AI::instance()->get_settings();
			wp_add_inline_script(
				'pp-admin',
				'var PP_AI_DATA=' . wp_json_encode( [
					'catalog'  => AI::model_catalog(),
					'provider' => $ai_settings['provider'] ?? '',
					'model'    => $ai_settings['model'] ?? '',
					'restUrl'  => rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				] ) . ';' . $this->ai_page_js(),
				'after'
			);
		} elseif ( false !== strpos( $hook, 'proofing-pins-teams' ) ) {
			wp_add_inline_script(
				'pp-admin',
				'var PP_TEAMS_DATA=' . wp_json_encode( [
					'restUrl' => rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				] ) . ';' . $this->teams_page_js(),
				'after'
			);
		} else {
			wp_add_inline_script( 'pp-admin', $this->dashboard_page_js(), 'after' );
		}
	}

	public function render_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only routing parameter, cast to int.
		$pin_id = isset( $_GET['pin'] ) ? (int) $_GET['pin'] : 0;
		if ( $pin_id ) {
			include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-pin-detail.php';
		} else {
			include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-dashboard.php';
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
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return false; }

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
		include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-settings.php';
	}

	private function dashboard_page_js(): string {
		return <<<'JS'
function pp_confirmBulk(f) {
	var act = f.pp_bulk_action && f.pp_bulk_action.value;
	if (!act) { alert('Pick a bulk action first.'); return false; }
	var n = f.querySelectorAll('.pp-row-check:checked').length;
	if (!n) { alert('Select at least one pin.'); return false; }
	return confirm('Delete ' + n + ' pin(s) — screenshots and replies will be removed. This cannot be undone.');
}
(function(){
	const all = document.getElementById('pp-check-all');
	const boxes = document.querySelectorAll('.pp-row-check');
	const sel = document.querySelector('.pp-bulk-selected');
	function updateCount() {
		if (!sel) return;
		const n = document.querySelectorAll('.pp-row-check:checked').length;
		sel.textContent = n + ' selected';
	}
	if (all) all.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); updateCount(); });
	boxes.forEach(b => b.addEventListener('change', updateCount));
	document.querySelectorAll('.pp-list tbody tr[data-detail-url]').forEach(tr => {
		tr.addEventListener('click', (e) => {
			if (e.target.closest('input,button,a,label')) return;
			location.href = tr.getAttribute('data-detail-url');
		});
		tr.style.cursor = 'pointer';
	});
})();
JS;
	}

	private function ai_page_js(): string {
		return <<<'JS'
(function(){
	const curated = PP_AI_DATA.catalog;
	const current = { provider: PP_AI_DATA.provider, model: PP_AI_DATA.model };
	const REST    = PP_AI_DATA.restUrl;
	const NONCE   = PP_AI_DATA.nonce;

	const providerSel = document.getElementById('pp-ai-provider');
	const modelSel    = document.getElementById('pp-ai-model');
	const refreshBtn  = document.getElementById('pp-ai-refresh-models');
	const keyInput    = document.getElementById('pp-ai-key');
	const hint        = document.getElementById('pp-ai-model-hint');

	function buildOptions(list, keepModel) {
		modelSel.innerHTML = '';
		list.forEach(item => {
			const opt = document.createElement('option');
			opt.value = item.id;
			opt.textContent = item.label || item.id;
			if (keepModel && item.id === keepModel) opt.selected = true;
			modelSel.appendChild(opt);
		});
		if (keepModel && ![...modelSel.options].some(o => o.value === keepModel)) {
			const keep = document.createElement('option');
			keep.value = keepModel; keep.textContent = keepModel + ' (saved)';
			keep.selected = true;
			modelSel.insertBefore(keep, modelSel.firstChild);
		}
	}

	function showCurated(providerKey, keepModel, reason) {
		const group = curated[providerKey] || { models: {} };
		const list = Object.entries(group.models)
			.filter(([k]) => k !== '__custom__')
			.map(([k, label]) => ({ id: k, label }));
		buildOptions(list, keepModel);
		hint.textContent = reason ? ('Using curated list — ' + reason) : 'Curated list. Click Refresh to fetch live models from the provider.';
	}

	async function fetchModels(providerKey, { force = false, keepModel = null } = {}) {
		hint.textContent = 'Fetching models…';
		refreshBtn.disabled = true;
		try {
			const res = await fetch(REST + 'ai/models', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
				body: JSON.stringify({ provider: providerKey, api_key: keyInput.value || '__unchanged__', refresh: !!force }),
				credentials: 'same-origin'
			});
			const json = await res.json();
			if (json.ok && Array.isArray(json.models) && json.models.length) {
				buildOptions(json.models, keepModel);
				hint.textContent = 'Loaded ' + json.models.length + ' models from ' + providerKey + (json.cached ? ' (cached)' : '') + '. Click Refresh to fetch the latest.';
			} else {
				showCurated(providerKey, keepModel, (json.message || 'fetch failed'));
			}
		} catch (err) {
			showCurated(providerKey, keepModel, err.message);
		} finally {
			refreshBtn.disabled = false;
		}
	}

	showCurated(current.provider, current.model);
	fetchModels(current.provider, { keepModel: current.model });

	providerSel.addEventListener('change', () => {
		showCurated(providerSel.value, null);
		fetchModels(providerSel.value, { keepModel: null });
	});

	refreshBtn.addEventListener('click', () => {
		fetchModels(providerSel.value, { force: true, keepModel: modelSel.value });
	});

	document.getElementById('pp-ai-test').addEventListener('click', async () => {
		const out = document.getElementById('pp-ai-test-result');
		out.textContent = 'Testing…';
		out.className = 'pp-ai-test-result';
		try {
			const res = await fetch(REST + 'ai/test', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
				body: JSON.stringify({ provider: providerSel.value, model: modelSel.value, api_key: keyInput.value }),
				credentials: 'same-origin'
			});
			const json = await res.json();
			if (json.ok) {
				out.textContent = '✓ ' + (json.message || 'OK');
				out.className = 'pp-ai-test-result ok';
			} else {
				out.textContent = '✗ ' + (json.message || 'Failed');
				out.className = 'pp-ai-test-result err';
			}
		} catch (err) {
			out.textContent = '✗ ' + err.message;
			out.className = 'pp-ai-test-result err';
		}
	});
})();
JS;
	}

	private function teams_page_js(): string {
		return <<<'JS'
(function(){
	const REST  = PP_TEAMS_DATA.restUrl;
	const NONCE = PP_TEAMS_DATA.nonce;
	const btn   = document.getElementById('pp-teams-test');
	const out   = document.getElementById('pp-teams-test-result');
	if (!btn) return;
	btn.addEventListener('click', async () => {
		out.textContent = 'Sending…';
		out.className = 'pp-ai-test-result';
		btn.disabled = true;
		try {
			const res = await fetch(REST + 'teams/test', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
				credentials: 'same-origin'
			});
			const json = await res.json();
			if (json.ok) {
				out.textContent = '✓ ' + (json.message || 'Sent');
				out.className = 'pp-ai-test-result ok';
			} else {
				out.textContent = '✗ ' + (json.message || 'Failed');
				out.className = 'pp-ai-test-result err';
			}
		} catch (err) {
			out.textContent = '✗ ' + err.message;
			out.className = 'pp-ai-test-result err';
		} finally {
			btn.disabled = false;
		}
	});
})();
JS;
	}
}

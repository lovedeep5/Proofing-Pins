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
		if ( ! isset( $_POST['proopin_bulk_action'] ) ) { return; }
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'proofing-pins' ) { return; }

		// Nonce + capability BEFORE reading any POST data.
		check_admin_referer( 'proopin_bulk_delete' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'proofing-pins' ) ); }

		$action = sanitize_key( wp_unslash( $_POST['proopin_bulk_action'] ) );
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
			__( 'Integrations', 'proofing-pins' ),
			__( 'Integrations', 'proofing-pins' ),
			Capabilities::MANAGE,
			'proofing-pins-integrations',
			[ $this, 'render_integrations' ]
		);
	}

	/**
	 * Single Integrations page hosting three tabs: AI, Microsoft Teams, Webhook.
	 * Each tab keeps its own form/nonce; we dispatch save + data prep based on
	 * the active tab, then include the tab container template.
	 */
	public function render_integrations(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'proofing-pins' ) );
		}
		$tab   = $this->current_integrations_tab();
		$saved = false;

		if ( $tab === 'ai' ) {
			$saved      = $this->process_ai_form();
			$settings   = AI::instance()->get_settings();
			$masked_key = AI::instance()->masked_key();
			$catalog    = AI::model_catalog();
		} elseif ( $tab === 'teams' ) {
			$saved          = $this->process_teams_form();
			$settings       = Teams::instance()->get_settings();
			$masked_webhook = Teams::instance()->masked_webhook();
		} elseif ( $tab === 'webhook' ) {
			$saved          = $this->process_webhook_form();
			$settings       = Webhook::instance()->get_settings();
			$masked_webhook = Webhook::instance()->masked_webhook();
		}

		include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-integrations.php';
	}

	private function current_integrations_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab routing display param, value validated against allowlist below.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'ai';
		return in_array( $tab, [ 'ai', 'teams', 'webhook' ], true ) ? $tab : 'ai';
	}

	private function process_ai_form(): bool {
		if ( ! isset( $_POST['proopin_ai_nonce'] ) ) { return false; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['proopin_ai_nonce'] ) ), 'proopin_ai_save' ) ) { return false; }
		AI::instance()->save_settings( [
			'enabled'         => ! empty( $_POST['enabled'] ),
			'auto_suggest'    => ! empty( $_POST['auto_suggest'] ),
			'provider'        => isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '',
			'model'           => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
			// API key: only sanitize control characters/whitespace; preserve the token as typed.
			'api_key'         => isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '',
			'request_timeout' => isset( $_POST['request_timeout'] ) ? (int) $_POST['request_timeout'] : 30,
		] );
		return true;
	}

	private function process_teams_form(): bool {
		if ( ! isset( $_POST['proopin_teams_nonce'] ) ) { return false; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['proopin_teams_nonce'] ) ), 'proopin_teams_save' ) ) { return false; }
		$events = [];
		if ( isset( $_POST['events'] ) && is_array( $_POST['events'] ) ) {
			$raw_events = map_deep( wp_unslash( $_POST['events'] ), 'sanitize_text_field' );
			foreach ( $raw_events as $k => $v ) {
				$events[ sanitize_key( $k ) ] = ! empty( $v );
			}
		}
		Teams::instance()->save_settings( [
			'enabled'       => ! empty( $_POST['enabled'] ),
			// Webhook URL contains a SAS-style signature. sanitize_text_field() would
			// strip %XX sequences (e.g. sp=%2Ftriggers%2Fmanual%2Frun) and break the
			// signature, so we use esc_url_raw — it preserves URL-encoded characters
			// while stripping CRLF (%0D/%0A). Final scheme/host validation happens
			// inside Teams::save_settings().
			'webhook_url'   => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
			'clear_webhook' => ! empty( $_POST['clear_webhook'] ),
			'events'        => $events,
		] );
		return true;
	}

	private function process_webhook_form(): bool {
		if ( ! isset( $_POST['proopin_webhook_nonce'] ) ) { return false; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['proopin_webhook_nonce'] ) ), 'proopin_webhook_save' ) ) { return false; }
		$events = [];
		if ( isset( $_POST['events'] ) && is_array( $_POST['events'] ) ) {
			$raw_events = map_deep( wp_unslash( $_POST['events'] ), 'sanitize_text_field' );
			foreach ( $raw_events as $k => $v ) {
				$events[ sanitize_key( $k ) ] = ! empty( $v );
			}
		}
		Webhook::instance()->save_settings( [
			'enabled'       => ! empty( $_POST['enabled'] ),
			// esc_url_raw mirrors the Teams handler — webhook receivers may carry
			// signed query parameters with %XX that sanitize_text_field would strip.
			'webhook_url'   => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
			'clear_webhook' => ! empty( $_POST['clear_webhook'] ),
			'events'        => $events,
		] );
		return true;
	}

	public function enqueue( $hook ): void {
		if ( strpos( (string) $hook, 'proofing-pins' ) === false ) {
			return;
		}
		wp_enqueue_style( 'proopin-admin', PROOFING_PINS_PLUGIN_URL . 'assets/css/admin.css', array(), PROOFING_PINS_VERSION );
		wp_enqueue_script(
			'proopin-admin',
			PROOFING_PINS_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array(),
			PROOFING_PINS_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
		wp_localize_script( 'proopin-admin', 'PROOPIN_ADMIN', [
			'restUrl' => esc_url_raw( rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'siteUrl' => home_url( '/' ),
		] );
		if ( false !== strpos( $hook, 'proofing-pins-integrations' ) ) {
			$tab = $this->current_integrations_tab();
			if ( $tab === 'ai' ) {
				$ai_settings = AI::instance()->get_settings();
				wp_add_inline_script(
					'proopin-admin',
					'var PROOPIN_AI_DATA=' . wp_json_encode( [
						'catalog'  => AI::model_catalog(),
						'provider' => $ai_settings['provider'] ?? '',
						'model'    => $ai_settings['model'] ?? '',
						'restUrl'  => rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
					] ) . ';' . $this->ai_page_js(),
					'after'
				);
			} elseif ( $tab === 'teams' ) {
				wp_add_inline_script(
					'proopin-admin',
					'var PROOPIN_TEAMS_DATA=' . wp_json_encode( [
						'restUrl' => rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
					] ) . ';' . $this->teams_page_js(),
					'after'
				);
			} elseif ( $tab === 'webhook' ) {
				wp_add_inline_script(
					'proopin-admin',
					'var PROOPIN_WEBHOOK_DATA=' . wp_json_encode( [
						'restUrl' => rest_url( PROOFING_PINS_REST_NAMESPACE . '/' ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
					] ) . ';' . $this->webhook_page_js(),
					'after'
				);
			}
		} else {
			wp_add_inline_script( 'proopin-admin', $this->dashboard_page_js(), 'after' );
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
	 * Fully delete a pin and every artifact attached to it:
	 * - screenshot attachment row + the original file + every auto-generated
	 *   thumbnail size on disk (handled by wp_delete_attachment with force=true)
	 * - all replies (proopin_reply comments) + their commentmeta (handled by
	 *   wp_delete_post(force=true) → wp_delete_comment(force=true))
	 * - all _proopin_* post meta
	 * - any post revisions
	 * - any still-queued AI suggestion cron event for this pin (orphan otherwise)
	 * - the pin post row itself
	 */
	public static function delete_pin_fully( int $pin_id ): bool {
		$post = get_post( $pin_id );
		if ( ! $post || $post->post_type !== PROOFING_PINS_POST_TYPE ) { return false; }

		$screenshot_id = (int) get_post_meta( $pin_id, '_proopin_screenshot_id', true );
		if ( $screenshot_id ) {
			wp_delete_attachment( $screenshot_id, true );
		}
		// AI auto-suggest schedules a one-shot cron with this pin's id. Clear it
		// so we don't leave an orphan cron row pointing at a deleted post.
		wp_clear_scheduled_hook( AI::CRON_HOOK, array( $pin_id ) );
		// wp_delete_post(true) cascades to: post meta, comments, term rels, revisions.
		wp_delete_post( $pin_id, true );
		return true;
	}

	public function register_settings(): void {
		register_setting( 'proopin_settings_group', 'proopin_settings', [
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
function proopin_confirmBulk(f) {
	var act = f.proopin_bulk_action && f.proopin_bulk_action.value;
	if (!act) { alert('Pick a bulk action first.'); return false; }
	var n = f.querySelectorAll('.proopin-row-check:checked').length;
	if (!n) { alert('Select at least one pin.'); return false; }
	return confirm('Delete ' + n + ' pin(s) — screenshots and replies will be removed. This cannot be undone.');
}
(function(){
	const all = document.getElementById('proopin-check-all');
	const boxes = document.querySelectorAll('.proopin-row-check');
	const sel = document.querySelector('.proopin-bulk-selected');
	function updateCount() {
		if (!sel) return;
		const n = document.querySelectorAll('.proopin-row-check:checked').length;
		sel.textContent = n + ' selected';
	}
	if (all) all.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); updateCount(); });
	boxes.forEach(b => b.addEventListener('change', updateCount));
	document.querySelectorAll('.proopin-list tbody tr[data-detail-url]').forEach(tr => {
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
	const curated = PROOPIN_AI_DATA.catalog;
	const current = { provider: PROOPIN_AI_DATA.provider, model: PROOPIN_AI_DATA.model };
	const REST    = PROOPIN_AI_DATA.restUrl;
	const NONCE   = PROOPIN_AI_DATA.nonce;

	const providerSel = document.getElementById('proopin-ai-provider');
	const modelSel    = document.getElementById('proopin-ai-model');
	const refreshBtn  = document.getElementById('proopin-ai-refresh-models');
	const keyInput    = document.getElementById('proopin-ai-key');
	const hint        = document.getElementById('proopin-ai-model-hint');

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

	document.getElementById('proopin-ai-test').addEventListener('click', async () => {
		const out = document.getElementById('proopin-ai-test-result');
		out.textContent = 'Testing…';
		out.className = 'proopin-ai-test-result';
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
				out.className = 'proopin-ai-test-result ok';
			} else {
				out.textContent = '✗ ' + (json.message || 'Failed');
				out.className = 'proopin-ai-test-result err';
			}
		} catch (err) {
			out.textContent = '✗ ' + err.message;
			out.className = 'proopin-ai-test-result err';
		}
	});
})();
JS;
	}

	private function teams_page_js(): string {
		return <<<'JS'
(function(){
	const REST  = PROOPIN_TEAMS_DATA.restUrl;
	const NONCE = PROOPIN_TEAMS_DATA.nonce;
	const btn   = document.getElementById('proopin-teams-test');
	const out   = document.getElementById('proopin-teams-test-result');
	if (!btn) return;
	btn.addEventListener('click', async () => {
		out.textContent = 'Sending…';
		out.className = 'proopin-ai-test-result';
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
				out.className = 'proopin-ai-test-result ok';
			} else {
				out.textContent = '✗ ' + (json.message || 'Failed');
				out.className = 'proopin-ai-test-result err';
			}
		} catch (err) {
			out.textContent = '✗ ' + err.message;
			out.className = 'proopin-ai-test-result err';
		} finally {
			btn.disabled = false;
		}
	});
})();
JS;
	}

	private function webhook_page_js(): string {
		return <<<'JS'
(function(){
	const REST  = PROOPIN_WEBHOOK_DATA.restUrl;
	const NONCE = PROOPIN_WEBHOOK_DATA.nonce;
	const btn   = document.getElementById('proopin-webhook-test');
	const out   = document.getElementById('proopin-webhook-test-result');
	if (!btn) return;
	btn.addEventListener('click', async () => {
		out.textContent = 'Sending…';
		out.className = 'proopin-ai-test-result';
		btn.disabled = true;
		try {
			const res = await fetch(REST + 'webhook/test', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
				credentials: 'same-origin'
			});
			const json = await res.json();
			if (json.ok) {
				out.textContent = '✓ ' + (json.message || 'Sent');
				out.className = 'proopin-ai-test-result ok';
			} else {
				out.textContent = '✗ ' + (json.message || 'Failed');
				out.className = 'proopin-ai-test-result err';
			}
		} catch (err) {
			out.textContent = '✗ ' + err.message;
			out.className = 'proopin-ai-test-result err';
		} finally {
			btn.disabled = false;
		}
	});
})();
JS;
	}
}

<?php
/**
 * AI integration settings template. Variables are method-scoped at include time.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap pp-admin">
	<h1><?php esc_html_e( 'AI Integration', 'proofing-pins' ); ?></h1>
	<p class="pp-admin-subtitle">
		<?php esc_html_e( 'Let an AI analyze each pin comment and suggest what to change. Bring your own API key from OpenAI, Anthropic, Google, or OpenRouter.', 'proofing-pins' ); ?>
	</p>

	<?php if ( ! empty( $saved ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'proofing-pins' ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="pp-ai-form">
		<?php wp_nonce_field( 'pp_ai_save', 'pp_ai_nonce' ); ?>

		<div class="pp-ai-card">
			<label class="pp-ai-toggle">
				<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
				<span><?php esc_html_e( 'Enable AI suggestions', 'proofing-pins' ); ?></span>
			</label>
			<label class="pp-ai-toggle">
				<input type="checkbox" name="auto_suggest" value="1" <?php checked( ! empty( $settings['auto_suggest'] ) ); ?>>
				<span><?php esc_html_e( 'Auto-generate a suggestion whenever a new pin is created', 'proofing-pins' ); ?></span>
			</label>
		</div>

		<div class="pp-ai-card">
			<h2><?php esc_html_e( 'Provider', 'proofing-pins' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="pp-ai-provider"><?php esc_html_e( 'AI platform', 'proofing-pins' ); ?></label></th>
					<td>
						<select name="provider" id="pp-ai-provider">
							<?php foreach ( $catalog as $slug => $group ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['provider'], $slug ); ?>>
									<?php echo esc_html( $group['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="pp-ai-model"><?php esc_html_e( 'Model', 'proofing-pins' ); ?></label></th>
					<td>
						<select name="model" id="pp-ai-model" style="min-width:320px"></select>
						<button type="button" class="button" id="pp-ai-refresh-models" style="margin-left:6px">
							<span class="dashicons dashicons-update" style="vertical-align:middle;line-height:inherit"></span>
							<?php esc_html_e( 'Refresh', 'proofing-pins' ); ?>
						</button>
						<p class="description" id="pp-ai-model-hint"></p>
					</td>
				</tr>
				<tr>
					<th><label for="pp-ai-key"><?php esc_html_e( 'API key', 'proofing-pins' ); ?></label></th>
					<td>
						<input type="password" name="api_key" id="pp-ai-key" value="<?php echo esc_attr( $masked_key ? '__unchanged__' : '' ); ?>" autocomplete="off" style="width:420px" placeholder="<?php esc_attr_e( 'sk-… / paste key', 'proofing-pins' ); ?>">
						<?php if ( $masked_key ) : ?>
							<span class="pp-ai-existing">
								<?php
								/* translators: %s: masked preview of the stored API key */
								echo esc_html( sprintf( __( 'Saved key: %s', 'proofing-pins' ), $masked_key ) );
								?>
							</span>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Stored encrypted at rest. Leave as "__unchanged__" to keep the saved key.', 'proofing-pins' ); ?></p>
						<button type="button" class="button" id="pp-ai-test"><?php esc_html_e( 'Test connection', 'proofing-pins' ); ?></button>
						<span id="pp-ai-test-result" class="pp-ai-test-result"></span>
					</td>
				</tr>
				<tr>
					<th><label for="pp-ai-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'proofing-pins' ); ?></label></th>
					<td>
						<input type="number" name="request_timeout" id="pp-ai-timeout" min="5" max="120" value="<?php echo (int) $settings['request_timeout']; ?>" style="width:90px">
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save AI settings', 'proofing-pins' ) ); ?>
	</form>
</div>

<script>
(function(){
	const curated = <?php echo wp_json_encode( $catalog ); ?>;
	const current = { provider: <?php echo wp_json_encode( $settings['provider'] ); ?>, model: <?php echo wp_json_encode( $settings['model'] ); ?> };
	const REST = '<?php echo esc_url_raw( rest_url( PP_REST_NAMESPACE . '/' ) ); ?>';
	const NONCE = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';

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
		// Preserve a saved model that isn't in the fresh list — add it at top so we don't lose it.
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
				body: JSON.stringify({
					provider: providerKey,
					api_key: keyInput.value || '__unchanged__',
					refresh: !!force
				}),
				credentials: 'same-origin'
			});
			const json = await res.json();
			if (json.ok && Array.isArray(json.models) && json.models.length) {
				buildOptions(json.models, keepModel);
				hint.textContent = `Loaded ${json.models.length} models from ${providerKey}${json.cached ? ' (cached)' : ''}. Click Refresh to fetch the latest.`;
			} else {
				showCurated(providerKey, keepModel, (json.message || 'fetch failed'));
			}
		} catch (err) {
			showCurated(providerKey, keepModel, err.message);
		} finally {
			refreshBtn.disabled = false;
		}
	}

	// Initial load: try dynamic fetch for current provider; fall back to curated immediately.
	showCurated(current.provider, current.model);
	fetchModels(current.provider, { keepModel: current.model });

	providerSel.addEventListener('change', () => {
		showCurated(providerSel.value, null);
		fetchModels(providerSel.value, { keepModel: null });
	});

	refreshBtn.addEventListener('click', () => {
		fetchModels(providerSel.value, { force: true, keepModel: modelSel.value });
	});

	// Test connection (unchanged)
	document.getElementById('pp-ai-test').addEventListener('click', async () => {
		const out = document.getElementById('pp-ai-test-result');
		out.textContent = 'Testing…';
		out.className = 'pp-ai-test-result';
		try {
			const res = await fetch(REST + 'ai/test', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
				body: JSON.stringify({
					provider: providerSel.value,
					model: modelSel.value,
					api_key: keyInput.value
				}),
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
</script>

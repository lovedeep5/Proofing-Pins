<?php
/**
 * AI integration settings template. Variables are method-scoped at include time.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap proopin-admin">
	<h1><?php esc_html_e( 'AI Integration', 'proofing-pins' ); ?></h1>
	<p class="proopin-admin-subtitle">
		<?php esc_html_e( 'Let an AI analyze each pin comment and suggest what to change. Bring your own API key from OpenAI, Anthropic, Google, or OpenRouter.', 'proofing-pins' ); ?>
	</p>

	<?php if ( ! empty( $saved ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'proofing-pins' ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="proopin-ai-form">
		<?php wp_nonce_field( 'proopin_ai_save', 'proopin_ai_nonce' ); ?>

		<div class="proopin-ai-card">
			<label class="proopin-ai-toggle">
				<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
				<span><?php esc_html_e( 'Enable AI suggestions', 'proofing-pins' ); ?></span>
			</label>
			<label class="proopin-ai-toggle">
				<input type="checkbox" name="auto_suggest" value="1" <?php checked( ! empty( $settings['auto_suggest'] ) ); ?>>
				<span><?php esc_html_e( 'Auto-generate a suggestion whenever a new pin is created', 'proofing-pins' ); ?></span>
			</label>
		</div>

		<div class="proopin-ai-card">
			<h2><?php esc_html_e( 'Provider', 'proofing-pins' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="proopin-ai-provider"><?php esc_html_e( 'AI platform', 'proofing-pins' ); ?></label></th>
					<td>
						<select name="provider" id="proopin-ai-provider">
							<?php foreach ( $catalog as $slug => $group ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['provider'], $slug ); ?>>
									<?php echo esc_html( $group['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="proopin-ai-model"><?php esc_html_e( 'Model', 'proofing-pins' ); ?></label></th>
					<td>
						<select name="model" id="proopin-ai-model" style="min-width:320px"></select>
						<button type="button" class="button" id="proopin-ai-refresh-models" style="margin-left:6px">
							<span class="dashicons dashicons-update" style="vertical-align:middle;line-height:inherit"></span>
							<?php esc_html_e( 'Refresh', 'proofing-pins' ); ?>
						</button>
						<p class="description" id="proopin-ai-model-hint"></p>
					</td>
				</tr>
				<tr>
					<th><label for="proopin-ai-key"><?php esc_html_e( 'API key', 'proofing-pins' ); ?></label></th>
					<td>
						<input type="password" name="api_key" id="proopin-ai-key" value="<?php echo esc_attr( $masked_key ? '__unchanged__' : '' ); ?>" autocomplete="off" style="width:420px" placeholder="<?php esc_attr_e( 'sk-… / paste key', 'proofing-pins' ); ?>">
						<?php if ( $masked_key ) : ?>
							<span class="proopin-ai-existing">
								<?php
								/* translators: %s: masked preview of the stored API key */
								echo esc_html( sprintf( __( 'Saved key: %s', 'proofing-pins' ), $masked_key ) );
								?>
							</span>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Stored encrypted at rest. Leave as "__unchanged__" to keep the saved key.', 'proofing-pins' ); ?></p>
						<button type="button" class="button" id="proopin-ai-test"><?php esc_html_e( 'Test connection', 'proofing-pins' ); ?></button>
						<span id="proopin-ai-test-result" class="proopin-ai-test-result"></span>
					</td>
				</tr>
				<tr>
					<th><label for="proopin-ai-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'proofing-pins' ); ?></label></th>
					<td>
						<input type="number" name="request_timeout" id="proopin-ai-timeout" min="5" max="120" value="<?php echo (int) $settings['request_timeout']; ?>" style="width:90px">
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save AI settings', 'proofing-pins' ) ); ?>
	</form>
</div>

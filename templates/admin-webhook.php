<?php
/**
 * Generic webhook integration settings — rendered as a tab partial inside
 * templates/admin-integrations.php. Variables ($settings, $masked_webhook,
 * $saved) are method-scoped at include time.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }

$grouped = [];
foreach ( \ProofingPins\Webhook::event_catalog() as $event ) {
	$grouped[ $event['group'] ][] = $event;
}
?>
<p class="proopin-admin-subtitle">
	<?php esc_html_e( 'Post pin activity as JSON to any HTTPS endpoint. Works with Zapier, n8n, Make, Power Automate, IFTTT, or your own webhook receiver. Each event includes the pin comment, author, page URL, status, and screenshot URL.', 'proofing-pins' ); ?>
</p>

<?php if ( ! empty( $saved ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'proofing-pins' ); ?></p></div>
<?php endif; ?>

<form method="post" class="proopin-ai-form">
	<?php wp_nonce_field( 'proopin_webhook_save', 'proopin_webhook_nonce' ); ?>

	<div class="proopin-ai-card">
		<label class="proopin-ai-toggle">
			<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
			<span><?php esc_html_e( 'Enable webhook notifications', 'proofing-pins' ); ?></span>
		</label>
	</div>

	<div class="proopin-ai-card">
		<h2><?php esc_html_e( 'Endpoint', 'proofing-pins' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="proopin-webhook-url"><?php esc_html_e( 'Webhook URL', 'proofing-pins' ); ?></label></th>
				<td>
					<input
						type="password"
						name="webhook_url"
						id="proopin-webhook-url"
						value="<?php echo esc_attr( $masked_webhook ? '__unchanged__' : '' ); ?>"
						autocomplete="off"
						style="width:520px"
						placeholder="https://hooks.example.com/your-endpoint">
					<?php if ( $masked_webhook ) : ?>
						<span class="proopin-ai-existing">
							<?php
							/* translators: %s: host portion of the saved webhook URL */
							echo esc_html( sprintf( __( 'Saved: %s', 'proofing-pins' ), $masked_webhook ) );
							?>
						</span>
						<label style="display:inline-block;margin-left:10px">
							<input type="checkbox" name="clear_webhook" value="1">
							<?php esc_html_e( 'Clear', 'proofing-pins' ); ?>
						</label>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'HTTPS only. URL is stored encrypted at rest. Treat it like a password — anyone with it can post events into your channel.', 'proofing-pins' ); ?>
					</p>
					<button type="button" class="button" id="proopin-webhook-test"><?php esc_html_e( 'Send test message', 'proofing-pins' ); ?></button>
					<span id="proopin-webhook-test-result" class="proopin-ai-test-result"></span>
				</td>
			</tr>
		</table>
	</div>

	<div class="proopin-ai-card">
		<h2><?php esc_html_e( 'Events to notify on', 'proofing-pins' ); ?></h2>
		<?php foreach ( $grouped as $group_label => $events ) : ?>
			<h3 style="margin-top:14px"><?php echo esc_html( $group_label ); ?></h3>
			<?php foreach ( $events as $event ) : ?>
				<label class="proopin-ai-toggle" style="display:block;margin:6px 0">
					<input
						type="checkbox"
						name="events[<?php echo esc_attr( $event['key'] ); ?>]"
						value="1"
						<?php checked( ! empty( $settings['events'][ $event['key'] ] ) ); ?>>
					<span><?php echo esc_html( $event['label'] ); ?></span>
				</label>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $settings['last_time'] ) ) : ?>
		<div class="proopin-ai-card">
			<h2><?php esc_html_e( 'Last delivery', 'proofing-pins' ); ?></h2>
			<p>
				<strong>
					<?php
					if ( ( $settings['last_status'] ?? '' ) === 'ok' ) {
						echo '<span style="color:#0a7d2c">' . esc_html__( 'Success', 'proofing-pins' ) . '</span>';
					} else {
						echo '<span style="color:#a82124">' . esc_html__( 'Error', 'proofing-pins' ) . '</span>';
					}
					?>
				</strong>
				&nbsp;<?php echo esc_html( $settings['last_time'] ); ?>
			</p>
			<?php if ( ! empty( $settings['last_message'] ) ) : ?>
				<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px"><?php echo esc_html( $settings['last_message'] ); ?></pre>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php submit_button( __( 'Save webhook settings', 'proofing-pins' ) ); ?>
</form>

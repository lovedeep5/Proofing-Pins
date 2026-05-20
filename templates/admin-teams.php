<?php
/**
 * Microsoft Teams integration settings template. Variables are method-scoped at include time.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }

$grouped = [];
foreach ( \ProofingPins\Teams::event_catalog() as $event ) {
	$grouped[ $event['group'] ][] = $event;
}
?>
<div class="wrap pp-admin">
	<h1><?php esc_html_e( 'Teams Integration', 'proofing-pins' ); ?></h1>
	<p class="pp-admin-subtitle">
		<?php esc_html_e( 'Post pin activity to a Microsoft Teams channel via a Workflow webhook. Pick which events you want to be notified about.', 'proofing-pins' ); ?>
	</p>

	<?php if ( ! empty( $saved ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'proofing-pins' ); ?></p></div>
	<?php endif; ?>

	<form method="post" class="pp-ai-form">
		<?php wp_nonce_field( 'pp_teams_save', 'pp_teams_nonce' ); ?>

		<div class="pp-ai-card">
			<label class="pp-ai-toggle">
				<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
				<span><?php esc_html_e( 'Enable Teams notifications', 'proofing-pins' ); ?></span>
			</label>
		</div>

		<div class="pp-ai-card">
			<h2><?php esc_html_e( 'Webhook', 'proofing-pins' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="pp-teams-webhook"><?php esc_html_e( 'Workflow webhook URL', 'proofing-pins' ); ?></label></th>
					<td>
						<input
							type="password"
							name="webhook_url"
							id="pp-teams-webhook"
							value="<?php echo esc_attr( $masked_webhook ? '__unchanged__' : '' ); ?>"
							autocomplete="off"
							style="width:520px"
							placeholder="https://prod-XX.westus.logic.azure.com/workflows/…">
						<?php if ( $masked_webhook ) : ?>
							<span class="pp-ai-existing">
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
							<?php esc_html_e( 'In Teams: open the channel → "…" → Workflows → "Post to a channel when a webhook request is received". Paste the URL it gives you. Treat this URL like a password — anyone with it can post to your channel.', 'proofing-pins' ); ?>
						</p>
						<button type="button" class="button" id="pp-teams-test"><?php esc_html_e( 'Send test message', 'proofing-pins' ); ?></button>
						<span id="pp-teams-test-result" class="pp-ai-test-result"></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="pp-ai-card">
			<h2><?php esc_html_e( 'Events to notify on', 'proofing-pins' ); ?></h2>
			<?php foreach ( $grouped as $group_label => $events ) : ?>
				<h3 style="margin-top:14px"><?php echo esc_html( $group_label ); ?></h3>
				<?php foreach ( $events as $event ) : ?>
					<label class="pp-ai-toggle" style="display:block;margin:6px 0">
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
			<div class="pp-ai-card">
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

		<?php submit_button( __( 'Save Teams settings', 'proofing-pins' ) ); ?>
	</form>
</div>

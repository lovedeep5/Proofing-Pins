<?php
/**
 * Admin pin-detail template.
 *
 * Variables below are scoped to the including method (Admin::render_dashboard).
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }
use ProofingPins\CPT;
use ProofingPins\Capabilities;
use ProofingPins\AI;
use ProofingPins\Elementor_Writer;

$pin = get_post( $pin_id );
if ( ! $pin || $pin->post_type !== PP_POST_TYPE ) {
	echo '<div class="wrap"><p>' . esc_html__( 'Pin not found.', 'proofing-pins' ) . '</p></div>';
	return;
}

$screenshot_id  = (int) get_post_meta( $pin->ID, '_pp_screenshot_id', true );
$screenshot_url = $screenshot_id ? wp_get_attachment_url( $screenshot_id ) : '';
$screenshot_w   = 0;
$screenshot_h   = 0;
if ( $screenshot_id ) {
	$meta = wp_get_attachment_metadata( $screenshot_id );
	if ( is_array( $meta ) ) {
		$screenshot_w = (int) ( $meta['width']  ?? 0 );
		$screenshot_h = (int) ( $meta['height'] ?? 0 );
	}
	if ( ( ! $screenshot_w || ! $screenshot_h ) && get_attached_file( $screenshot_id ) ) {
		$dims = @getimagesize( get_attached_file( $screenshot_id ) );
		if ( is_array( $dims ) ) { $screenshot_w = $dims[0]; $screenshot_h = $dims[1]; }
	}
}
$pin_x          = (float) get_post_meta( $pin->ID, '_pp_pin_x', true );
$pin_y          = (float) get_post_meta( $pin->ID, '_pp_pin_y', true );
$page_url       = get_post_meta( $pin->ID, '_pp_page_url', true );
$page_title     = get_post_meta( $pin->ID, '_pp_page_title', true );
$viewport_w     = (int) get_post_meta( $pin->ID, '_pp_viewport_w', true );
$viewport_h     = (int) get_post_meta( $pin->ID, '_pp_viewport_h', true );
$device_type    = get_post_meta( $pin->ID, '_pp_device_type', true );
$user_agent     = get_post_meta( $pin->ID, '_pp_user_agent', true );
$author         = get_userdata( $pin->post_author );
$guest_name     = (string) get_post_meta( $pin->ID, '_pp_guest_name', true );
$guest_email    = (string) get_post_meta( $pin->ID, '_pp_guest_email', true );
$is_guest       = (int) get_post_meta( $pin->ID, '_pp_is_guest', true ) === 1;
$author_label   = $author ? $author->display_name : ( $guest_name ?: __( 'Guest', 'proofing-pins' ) );
$status         = $pin->post_status;

$status_labels = [
	CPT::STATUS_OPEN        => __( 'Open', 'proofing-pins' ),
	CPT::STATUS_IN_PROGRESS => __( 'In Progress', 'proofing-pins' ),
	CPT::STATUS_RESOLVED    => __( 'Resolved', 'proofing-pins' ),
	CPT::STATUS_ARCHIVED    => __( 'Archived', 'proofing-pins' ),
];
$status_colors = [
	CPT::STATUS_OPEN        => '#ef4444',
	CPT::STATUS_IN_PROGRESS => '#f59e0b',
	CPT::STATUS_RESOLVED    => '#10b981',
	CPT::STATUS_ARCHIVED    => '#9ca3af',
];

$replies   = get_comments( [ 'post_id' => $pin->ID, 'type' => 'pp_reply', 'status' => 'approve', 'order' => 'ASC' ] );
$live_link = home_url( $page_url ?: '/' );
$live_link = add_query_arg( 'pp_focus', $pin->ID, $live_link );
$can_manage = current_user_can( Capabilities::MANAGE );
$back_url  = add_query_arg( [ 'page' => 'proofing-pins' ], admin_url( 'admin.php' ) );
?>
<div class="wrap pp-admin pp-detail">
	<div class="pp-detail-back">
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to all pins', 'proofing-pins' ); ?></a>
	</div>
	<div class="pp-detail-grid">
		<div class="pp-detail-screenshot">
			<?php if ( $screenshot_url ) : ?>
				<div class="pp-screenshot-wrap">
					<img src="<?php echo esc_url( $screenshot_url ); ?>"
					     <?php if ( $screenshot_w && $screenshot_h ) : ?>width="<?php echo (int) $screenshot_w; ?>" height="<?php echo (int) $screenshot_h; ?>"<?php endif; ?>
					     alt="">
				</div>
			<?php else : ?>
				<div class="pp-screenshot-wrap empty"><?php esc_html_e( 'No screenshot captured.', 'proofing-pins' ); ?></div>
			<?php endif; ?>
		</div>
		<aside class="pp-detail-side" data-pin-id="<?php echo (int) $pin->ID; ?>">
			<div class="pp-detail-meta">
				<div class="pp-detail-page">
					<span class="dashicons dashicons-admin-site-alt3"></span>
					<a href="<?php echo esc_url( $live_link ); ?>" target="_blank"><?php echo esc_html( $page_title ?: $page_url ); ?> &#8599;</a>
				</div>
				<div class="pp-detail-tech">
					<span><?php echo esc_html( $device_type ); ?></span>
					<span><?php echo (int) $viewport_w; ?>&times;<?php echo (int) $viewport_h; ?></span>
				</div>
			</div>

			<div class="pp-msg">
				<div class="pp-msg-head">
					<?php
					if ( $author ) {
						echo get_avatar( $author->ID, 28 );
					} elseif ( $guest_email ) {
						echo get_avatar( $guest_email, 28 );
					} else {
						echo '<span class="pp-guest-avatar"></span>';
					}
					?>
					<span class="pp-msg-author">
						<?php echo esc_html( $author_label ); ?>
						<?php if ( $is_guest ) : ?>
							<em class="pp-guest-tag">
								<?php
								echo $guest_email
									? esc_html( sprintf( /* translators: %s: guest's email address */ __( '(guest · %s)', 'proofing-pins' ), $guest_email ) )
									: esc_html__( '(guest)', 'proofing-pins' );
								?>
							</em>
						<?php endif; ?>
					</span>
					<span class="pp-msg-time"><?php echo esc_html( get_the_date( '', $pin ) . ' ' . get_the_time( '', $pin ) ); ?></span>
				</div>
				<div class="pp-msg-body"><?php echo nl2br( esc_html( $pin->post_content ) ); ?></div>
			</div>

			<?php
			$ai_settings   = AI::instance()->get_settings();
			$ai_enabled    = ! empty( $ai_settings['enabled'] );
			$ai_status     = get_post_meta( $pin->ID, '_pp_ai_status', true );
			$ai_suggestion = get_post_meta( $pin->ID, '_pp_ai_suggestion', true );
			$ai_model_used = get_post_meta( $pin->ID, '_pp_ai_suggestion_model', true );
			$ai_error      = get_post_meta( $pin->ID, '_pp_ai_error', true );

			// Elementor context
			$elementor_widget_type = (string) get_post_meta( $pin->ID, '_pp_elementor_widget_type', true );
			$elementor_widget_id   = (string) get_post_meta( $pin->ID, '_pp_elementor_widget_id', true );
			$elementor_page_id     = (int) get_post_meta( $pin->ID, '_pp_elementor_page_id', true );
			$elementor_edit_url    = '';
			if ( $elementor_page_id && $elementor_widget_id ) {
				$elementor_edit_url = add_query_arg( [ 'post' => $elementor_page_id, 'action' => 'elementor' ], admin_url( 'post.php' ) ) . '#elementor-element-' . $elementor_widget_id;
			}

			$change_op       = get_post_meta( $pin->ID, '_pp_ai_change_op', true );
			$applied_at      = get_post_meta( $pin->ID, '_pp_applied_at', true );
			$applied_prev    = get_post_meta( $pin->ID, '_pp_applied_prev_value', true );
			$current_value   = '';
			if ( is_array( $change_op ) && $elementor_page_id ) {
				$current_value = (string) Elementor_Writer::read_setting( $elementor_page_id, $elementor_widget_id, (string) $change_op['setting_key'] );
			}
			?>

			<?php if ( $elementor_edit_url ) : ?>
				<div class="pp-elementor-link">
					<span class="dashicons dashicons-edit"></span>
					<a href="<?php echo esc_url( $elementor_edit_url ); ?>" target="_blank" rel="noopener">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: Elementor widget type (e.g., "heading", "button") */
								__( 'Open this %s widget in Elementor', 'proofing-pins' ),
								$elementor_widget_type
							)
						);
						?> &#8599;
					</a>
				</div>
			<?php endif; ?>
			<?php if ( $ai_enabled ) : ?>
				<div class="pp-ai-block" id="pp-ai-block" data-pin-id="<?php echo (int) $pin->ID; ?>" data-status="<?php echo esc_attr( $ai_status ); ?>">
					<div class="pp-ai-head">
						<span class="pp-ai-label">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
							<?php esc_html_e( 'AI Suggestion', 'proofing-pins' ); ?>
						</span>
						<button type="button" class="button button-small" id="pp-ai-regen"><?php esc_html_e( 'Regenerate', 'proofing-pins' ); ?></button>
					</div>
					<div class="pp-ai-body">
						<?php if ( ! $ai_suggestion && $ai_status !== 'error' ) : ?>
							<p class="pp-ai-placeholder">
								<?php
								if ( $ai_status === 'running' || $ai_status === 'queued' ) {
									esc_html_e( 'Generating suggestion…', 'proofing-pins' );
								} else {
									esc_html_e( 'No suggestion yet — click Regenerate to create one.', 'proofing-pins' );
								}
								?>
							</p>
						<?php elseif ( $ai_status === 'error' ) : ?>
							<p class="pp-ai-err">
								<?php
								/* translators: %s: error message returned by the AI provider */
								echo esc_html( sprintf( __( 'AI error: %s', 'proofing-pins' ), $ai_error ?: 'unknown' ) );
								?>
							</p>
						<?php else :
							$cat  = $ai_suggestion['category'] ?? 'other';
							$risk = $ai_suggestion['risk'] ?? 'medium';
							?>
							<div class="pp-ai-chips">
								<span class="pp-ai-chip pp-ai-chip-cat"><?php echo esc_html( str_replace( '_', ' ', $cat ) ); ?></span>
								<span class="pp-ai-chip pp-ai-chip-risk pp-risk-<?php echo esc_attr( $risk ); ?>">
									<?php
									/* translators: %s: risk level, one of low/medium/high */
									echo esc_html( sprintf( __( 'Risk: %s', 'proofing-pins' ), $risk ) );
									?>
								</span>
								<?php if ( isset( $ai_suggestion['confidence'] ) ) : ?>
									<span class="pp-ai-chip"><?php echo (int) $ai_suggestion['confidence']; ?>% <?php esc_html_e( 'confidence', 'proofing-pins' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $ai_suggestion['summary'] ) ) : ?>
								<p class="pp-ai-summary"><?php echo esc_html( $ai_suggestion['summary'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $ai_suggestion['suggestion'] ) ) : ?>
								<p class="pp-ai-text"><?php echo nl2br( esc_html( $ai_suggestion['suggestion'] ) ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $ai_suggestion['snippet'] ) ) : ?>
								<pre class="pp-ai-snippet" data-lang="<?php echo esc_attr( $ai_suggestion['snippet_language'] ?? '' ); ?>"><code><?php echo esc_html( $ai_suggestion['snippet'] ); ?></code></pre>
								<button type="button" class="button button-small pp-ai-copy" data-copy="snippet"><?php esc_html_e( 'Copy snippet', 'proofing-pins' ); ?></button>
							<?php endif; ?>
							<?php if ( ! empty( $ai_suggestion['notes'] ) ) : ?>
								<p class="pp-ai-notes"><?php echo esc_html( $ai_suggestion['notes'] ); ?></p>
							<?php endif; ?>
							<?php if ( $ai_model_used ) : ?>
								<div class="pp-ai-model">
									<?php
									/* translators: %s: AI provider and model name (e.g., "openai/gpt-4o") */
									echo esc_html( sprintf( __( 'Generated by %s', 'proofing-pins' ), $ai_model_used ) );
									?>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( is_array( $change_op ) ) :
				$setting_label = $change_op['setting_label'] ?: $change_op['setting_key'];
				$value_type    = Elementor_Writer::value_type( $change_op['widget_type'], $change_op['setting_key'] );
				$is_color      = $value_type === 'color';
				?>
				<div class="pp-apply-card" id="pp-apply-card" data-pin-id="<?php echo (int) $pin->ID; ?>">
					<div class="pp-apply-head">
						<span class="pp-apply-label">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Apply change', 'proofing-pins' ); ?>
						</span>
						<?php if ( $applied_at ) : ?>
							<span class="pp-apply-status applied">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: human-readable time difference like "2 hours ago" */
										__( 'Applied %s', 'proofing-pins' ),
										human_time_diff( strtotime( $applied_at ), current_time( 'timestamp', true ) ) . ' ' . esc_html__( 'ago', 'proofing-pins' )
									)
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<div class="pp-apply-body">
						<div class="pp-apply-field"><?php echo esc_html( $setting_label ); ?> <code>(<?php echo esc_html( $change_op['setting_key'] ); ?>)</code></div>
						<div class="pp-diff">
							<div class="pp-diff-col">
								<div class="pp-diff-lbl"><?php esc_html_e( 'Before', 'proofing-pins' ); ?></div>
								<?php if ( $is_color ) : ?>
									<div class="pp-diff-val">
										<span class="pp-swatch" style="background:<?php echo esc_attr( $current_value ); ?>"></span>
										<code><?php echo esc_html( $current_value ?: '(unset)' ); ?></code>
									</div>
								<?php else : ?>
									<pre class="pp-diff-val"><?php echo esc_html( $current_value ); ?></pre>
								<?php endif; ?>
							</div>
							<div class="pp-diff-arrow">&rarr;</div>
							<div class="pp-diff-col">
								<div class="pp-diff-lbl"><?php esc_html_e( 'After', 'proofing-pins' ); ?></div>
								<?php if ( $is_color ) : ?>
									<div class="pp-diff-val">
										<span class="pp-swatch" style="background:<?php echo esc_attr( $change_op['new_value'] ); ?>"></span>
										<code><?php echo esc_html( $change_op['new_value'] ); ?></code>
									</div>
								<?php else : ?>
									<pre class="pp-diff-val after"><?php echo esc_html( $change_op['new_value'] ); ?></pre>
								<?php endif; ?>
							</div>
						</div>
						<div class="pp-apply-actions">
							<?php if ( ! $applied_at ) : ?>
								<button type="button" class="button button-primary" id="pp-apply-btn"><?php esc_html_e( 'Apply to Elementor', 'proofing-pins' ); ?></button>
							<?php else : ?>
								<button type="button" class="button" id="pp-revert-btn"><?php esc_html_e( 'Revert change', 'proofing-pins' ); ?></button>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $replies as $r ) :
				$r_author = get_userdata( (int) $r->user_id ); ?>
				<div class="pp-msg">
					<div class="pp-msg-head">
						<?php echo get_avatar( $r->user_id, 24 ); ?>
						<span class="pp-msg-author"><?php echo esc_html( $r_author->display_name ?? $r->comment_author ); ?></span>
						<span class="pp-msg-time"><?php echo esc_html( $r->comment_date ); ?></span>
					</div>
					<div class="pp-msg-body"><?php echo nl2br( esc_html( $r->comment_content ) ); ?></div>
				</div>
			<?php endforeach; ?>

			<div class="pp-reply-form">
				<textarea id="pp-reply-body" placeholder="<?php esc_attr_e( 'Write a reply…', 'proofing-pins' ); ?>" rows="3"></textarea>
				<button class="button button-primary" id="pp-reply-btn"><?php esc_html_e( 'Post reply', 'proofing-pins' ); ?></button>
			</div>

			<?php if ( $can_manage ) : ?>
				<div class="pp-detail-status">
					<label><?php esc_html_e( 'Status', 'proofing-pins' ); ?></label>
					<select id="pp-status-select">
						<?php foreach ( $status_labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button button-link-delete" id="pp-delete-btn" style="margin-left:auto"><?php esc_html_e( 'Delete pin', 'proofing-pins' ); ?></button>
				</div>
			<?php endif; ?>
		</aside>
	</div>
</div>

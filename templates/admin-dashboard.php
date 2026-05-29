<?php
/**
 * Admin dashboard template — list/grid view of pins.
 *
 * Variables used below (e.g. $status_filter, $query_args, $q) are scoped to the
 * including method (Admin::render_dashboard) at runtime. Plugin Check flags them
 * as "non-prefixed globals" which is a known false-positive for WP templates.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }
use ProofingPins\CPT;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display filters; no state change.
$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$page_filter   = isset( $_GET['page_url'] ) ? sanitize_text_field( wp_unslash( $_GET['page_url'] ) ) : '';
$view          = isset( $_GET['view'] ) && sanitize_key( wp_unslash( $_GET['view'] ) ) === 'grid' ? 'grid' : 'list';
$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$per_page      = 20;

$query_args = [
	'post_type'      => PROOFING_PINS_POST_TYPE,
	'post_status'    => in_array( $status_filter, CPT::all_statuses(), true ) ? $status_filter : CPT::all_statuses(),
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
];
if ( $page_filter ) {
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only filter with single indexed key; bounded by per_page=20.
	$query_args['meta_query'] = array( array( 'key' => '_proopin_page_url', 'value' => $page_filter ) );
}
$q = new WP_Query( $query_args );

$status_counts = [];
foreach ( CPT::all_statuses() as $s ) {
	$status_counts[ $s ] = (int) ( wp_count_posts( PROOFING_PINS_POST_TYPE )->$s ?? 0 );
}

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

$distinct_urls = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare(
	"SELECT DISTINCT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s ORDER BY meta_value ASC LIMIT 200",
	'_proopin_page_url'
) );

$can_manage = current_user_can( \ProofingPins\Capabilities::MANAGE );
?>
<div class="wrap proopin-admin">
	<div class="proopin-admin-header">
		<div class="proopin-admin-header-text">
			<h1><?php esc_html_e( 'Proofing Pins', 'proofing-pins' ); ?></h1>
			<p class="proopin-admin-subtitle"><?php esc_html_e( 'All pinpoint comments from reviewers across your site.', 'proofing-pins' ); ?></p>
		</div>
		<div class="proopin-admin-header-actions">
			<a class="proopin-tutorial-link" href="https://www.youtube.com/watch?v=8UJX0GmM79k" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
				<?php esc_html_e( 'Watch tutorial', 'proofing-pins' ); ?>
			</a>
			<a class="proopin-tutorial-link" href="https://github.com/lovedeep5/Proofing-Pins/issues" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
				<?php esc_html_e( 'Report a bug', 'proofing-pins' ); ?>
			</a>
		</div>
	</div>

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only notice after admin-init redirect; int-cast below.
	if ( isset( $_GET['deleted'] ) ) :
		$proopin_deleted_count = (int) $_GET['deleted'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of pins deleted */
					_n( '%d pin deleted.', '%d pins deleted.', $proopin_deleted_count, 'proofing-pins' ),
					$proopin_deleted_count
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<div class="proopin-filters">
		<div class="proopin-filter-chips">
			<a class="proopin-chip <?php echo $status_filter === '' ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'proofing-pins' ], admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'All', 'proofing-pins' ); ?>
			</a>
			<?php foreach ( $status_labels as $key => $label ) : ?>
				<a class="proopin-chip <?php echo $status_filter === $key ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'proofing-pins', 'status' => $key ], admin_url( 'admin.php' ) ) ); ?>">
					<span class="proopin-chip-dot" style="background:<?php echo esc_attr( $status_colors[ $key ] ); ?>"></span>
					<?php echo esc_html( $label ); ?>
					<span class="proopin-chip-count"><?php echo (int) $status_counts[ $key ]; ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<form method="get" class="proopin-filter-form">
			<input type="hidden" name="page" value="proofing-pins">
			<?php if ( $status_filter ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>"><?php endif; ?>
			<select name="page_url" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All pages', 'proofing-pins' ); ?></option>
				<?php foreach ( $distinct_urls as $u ) : ?>
					<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $page_filter, $u ); ?>><?php echo esc_html( $u ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="proopin-view-toggle">
				<a class="<?php echo $view === 'list' ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'view' => 'list' ] ) ); ?>"><span class="dashicons dashicons-list-view"></span></a>
				<a class="<?php echo $view === 'grid' ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'view' => 'grid' ] ) ); ?>"><span class="dashicons dashicons-grid-view"></span></a>
			</div>
		</form>
	</div>

	<form method="post" id="proopin-bulk-form" onsubmit="return proopin_confirmBulk(this);">
		<?php wp_nonce_field( 'proopin_bulk_delete' ); ?>
		<?php if ( $can_manage && $q->have_posts() ) : ?>
			<div class="proopin-bulk-bar">
				<select name="proopin_bulk_action">
					<option value=""><?php esc_html_e( 'Bulk actions', 'proofing-pins' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete (incl. screenshot & replies)', 'proofing-pins' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'proofing-pins' ); ?></button>
				<span class="proopin-bulk-selected">0 <?php esc_html_e( 'selected', 'proofing-pins' ); ?></span>
			</div>
		<?php endif; ?>

	<?php if ( ! $q->have_posts() ) : ?>
		<div class="proopin-empty">
			<div class="proopin-empty-icon">
				<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
			</div>
			<h2><?php esc_html_e( 'No pins yet', 'proofing-pins' ); ?></h2>
			<p><?php esc_html_e( 'Visit the site while logged in and click the floating button to leave your first pin.', 'proofing-pins' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php esc_html_e( 'Open site', 'proofing-pins' ); ?></a>
		</div>
	<?php elseif ( $view === 'grid' ) : ?>
		<div class="proopin-grid">
			<?php while ( $q->have_posts() ) : $q->the_post(); $pid = get_the_ID();
				$screenshot_id  = (int) get_post_meta( $pid, '_proopin_screenshot_id', true );
				$thumb          = $screenshot_id ? wp_get_attachment_image_src( $screenshot_id, 'medium' ) : null;
				$status         = get_post_status( $pid );
				$author         = get_userdata( get_post_field( 'post_author', $pid ) );
				$page_url       = get_post_meta( $pid, '_proopin_page_url', true );
				$detail_url     = add_query_arg( [ 'page' => 'proofing-pins', 'pin' => $pid ], admin_url( 'admin.php' ) );
			?>
				<a class="proopin-card" href="<?php echo esc_url( $detail_url ); ?>">
					<div class="proopin-card-thumb">
						<?php if ( $thumb ) : ?>
							<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="">
						<?php else : ?>
							<div class="proopin-card-thumb-empty"><?php esc_html_e( 'No screenshot', 'proofing-pins' ); ?></div>
						<?php endif; ?>
					</div>
					<div class="proopin-card-meta">
						<span class="proopin-status" style="background:<?php echo esc_attr( $status_colors[ $status ] ); ?>"><?php echo esc_html( $status_labels[ $status ] ); ?></span>
						<span class="proopin-card-page"><?php echo esc_html( $page_url ); ?></span>
					</div>
					<div class="proopin-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_content(), 18, '…' ) ); ?></div>
					<div class="proopin-card-foot">
						<?php echo get_avatar( $author->ID ?? 0, 20 ); ?>
						<span><?php echo esc_html( $author->display_name ?? '' ); ?></span>
						<span class="proopin-dot">•</span>
						<span><?php echo esc_html( human_time_diff( get_post_time( 'U', true, $pid ), current_time( 'timestamp', true ) ) . ' ' . __( 'ago', 'proofing-pins' ) ); ?></span>
					</div>
				</a>
			<?php endwhile; wp_reset_postdata(); ?>
		</div>
	<?php else : ?>
		<table class="proopin-list">
			<thead>
				<tr>
					<?php if ( $can_manage ) : ?><th style="width:30px"><input type="checkbox" id="proopin-check-all"></th><?php endif; ?>
					<th style="width:80px"></th>
					<th><?php esc_html_e( 'Comment', 'proofing-pins' ); ?></th>
					<th style="width:160px"><?php esc_html_e( 'Page', 'proofing-pins' ); ?></th>
					<th style="width:140px"><?php esc_html_e( 'Author', 'proofing-pins' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Status', 'proofing-pins' ); ?></th>
					<th style="width:100px"><?php esc_html_e( 'When', 'proofing-pins' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php while ( $q->have_posts() ) : $q->the_post(); $pid = get_the_ID();
					$screenshot_id  = (int) get_post_meta( $pid, '_proopin_screenshot_id', true );
					$thumb          = $screenshot_id ? wp_get_attachment_image_src( $screenshot_id, 'thumbnail' ) : null;
					$status         = get_post_status( $pid );
					$author         = get_userdata( get_post_field( 'post_author', $pid ) );
					$guest_name     = get_post_meta( $pid, '_proopin_guest_name', true );
					$author_label   = $author ? $author->display_name : ( $guest_name ?: __( 'Guest', 'proofing-pins' ) );
					$page_url       = get_post_meta( $pid, '_proopin_page_url', true );
					$detail_url     = add_query_arg( [ 'page' => 'proofing-pins', 'pin' => $pid ], admin_url( 'admin.php' ) );
				?>
					<tr data-detail-url="<?php echo esc_url( $detail_url ); ?>">
						<?php if ( $can_manage ) : ?><td onclick="event.stopPropagation()"><input type="checkbox" class="proopin-row-check" name="pin_ids[]" value="<?php echo (int) $pid; ?>"></td><?php endif; ?>
						<td>
							<div class="proopin-mini-thumb">
								<?php if ( $thumb ) : ?>
									<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="">
								<?php endif; ?>
							</div>
						</td>
						<td><strong><?php echo esc_html( wp_trim_words( get_the_content(), 16, '…' ) ); ?></strong></td>
						<td><code><?php echo esc_html( $page_url ); ?></code></td>
						<td><?php echo esc_html( $author_label ); ?></td>
						<td><span class="proopin-status" style="background:<?php echo esc_attr( $status_colors[ $status ] ); ?>"><?php echo esc_html( $status_labels[ $status ] ); ?></span></td>
						<td><?php echo esc_html( human_time_diff( get_post_time( 'U', true, $pid ), current_time( 'timestamp', true ) ) ); ?></td>
					</tr>
				<?php endwhile; wp_reset_postdata(); ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php
	if ( $q->max_num_pages > 1 ) {
		$proopin_pagination_html = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $q->max_num_pages,
			)
		);
		echo '<div class="proopin-pagination">' . wp_kses_post( $proopin_pagination_html ) . '</div>';
	}
	?>
	</form>
</div>

<?php
/**
 * Integrations tab container. Renders the page chrome + tab nav, then
 * includes the active tab partial. Method-scoped variables provided by
 * Admin::render_integrations(): $tab, $saved, and per-tab data:
 * - ai: $settings, $masked_key, $catalog
 * - teams: $settings, $masked_webhook
 * - webhook: $settings, $masked_webhook
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }

$tabs = [
	'ai'      => __( 'AI', 'proofing-pins' ),
	'teams'   => __( 'Microsoft Teams', 'proofing-pins' ),
	'webhook' => __( 'Webhook', 'proofing-pins' ),
];
?>
<div class="wrap proopin-admin">
	<h1><?php esc_html_e( 'Integrations', 'proofing-pins' ); ?></h1>

	<h2 class="nav-tab-wrapper proopin-tabs">
		<?php foreach ( $tabs as $tab_key => $tab_label ) :
			$tab_url = add_query_arg(
				[ 'page' => 'proofing-pins-integrations', 'tab' => $tab_key ],
				admin_url( 'admin.php' )
			);
			?>
			<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div class="proopin-tab-body">
		<?php
		switch ( $tab ) {
			case 'teams':
				include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-teams.php';
				break;
			case 'webhook':
				include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-webhook.php';
				break;
			case 'ai':
			default:
				include PROOFING_PINS_PLUGIN_DIR . 'templates/admin-ai.php';
				break;
		}
		?>
	</div>
</div>

<?php
/**
 * Proofing Pins uninstaller — removes all data when plugin is deleted.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all pin posts + their screenshots.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall, no cache applicable.
$proopin_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'proopin_pin' ) );
foreach ( $proopin_post_ids as $proopin_post_id ) {
	$proopin_screenshot_id = (int) get_post_meta( $proopin_post_id, '_proopin_screenshot_id', true );
	if ( $proopin_screenshot_id ) {
		wp_delete_attachment( $proopin_screenshot_id, true );
	}
	wp_delete_post( $proopin_post_id, true );
}

delete_option( 'proopin_settings' );
delete_option( 'proopin_ai_settings' );
delete_option( 'proopin_teams_settings' );

// Clear any pending AI suggestion cron events (one-shot, args=[$pin_id]).
wp_clear_scheduled_hook( 'proopin_ai_generate_suggestion' );

// Sweep transients: per-IP guest rate-limit + cached provider model lists.
// They auto-expire (1h / 6h) but a thorough uninstall should not leave rows
// in wp_options for the new install to inherit.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall.
$proopin_transient_rows = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	  WHERE option_name LIKE '\\_transient\\_proopin\\_%'
	     OR option_name LIKE '\\_transient\\_timeout\\_proopin\\_%'"
);
foreach ( $proopin_transient_rows as $proopin_transient_row ) {
	if ( strpos( $proopin_transient_row, '_transient_timeout_' ) === 0 ) {
		delete_option( $proopin_transient_row );
	} else {
		// Use the transient API so multisite/object-cache backends are notified.
		delete_transient( substr( $proopin_transient_row, strlen( '_transient_' ) ) );
	}
}

$proopin_caps = array(
	'proopin_create_pin',
	'proopin_view_pins',
	'proopin_manage_pins',
	'edit_proopin_pin',
	'read_proopin_pin',
	'delete_proopin_pin',
	'edit_proopin_pins',
	'edit_others_proopin_pins',
	'publish_proopin_pins',
	'read_private_proopin_pins',
	'delete_proopin_pins',
);
foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $proopin_role_key ) {
	$proopin_role = get_role( $proopin_role_key );
	if ( ! $proopin_role ) {
		continue;
	}
	foreach ( $proopin_caps as $proopin_cap ) {
		$proopin_role->remove_cap( $proopin_cap );
	}
}

// Remove uploaded screenshots via WP_Filesystem.
$proopin_uploads = wp_upload_dir();
$proopin_dir     = trailingslashit( $proopin_uploads['basedir'] ) . 'proofing-pins';
if ( is_dir( $proopin_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	if ( isset( $wp_filesystem ) && $wp_filesystem ) {
		$wp_filesystem->delete( $proopin_dir, true ); // true = recursive
	}
}

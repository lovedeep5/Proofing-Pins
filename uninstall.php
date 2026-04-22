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
$pp_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'pp_pin' ) );
foreach ( $pp_post_ids as $pp_post_id ) {
	$pp_screenshot_id = (int) get_post_meta( $pp_post_id, '_pp_screenshot_id', true );
	if ( $pp_screenshot_id ) {
		wp_delete_attachment( $pp_screenshot_id, true );
	}
	wp_delete_post( $pp_post_id, true );
}

delete_option( 'pp_settings' );
delete_option( 'pp_ai_settings' );

$pp_caps = array(
	'pp_create_pin',
	'pp_view_pins',
	'pp_manage_pins',
	'edit_pp_pin',
	'read_pp_pin',
	'delete_pp_pin',
	'edit_pp_pins',
	'edit_others_pp_pins',
	'publish_pp_pins',
	'read_private_pp_pins',
	'delete_pp_pins',
);
foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $pp_role_key ) {
	$pp_role = get_role( $pp_role_key );
	if ( ! $pp_role ) {
		continue;
	}
	foreach ( $pp_caps as $pp_cap ) {
		$pp_role->remove_cap( $pp_cap );
	}
}

// Remove uploaded screenshots via WP_Filesystem.
$pp_uploads = wp_upload_dir();
$pp_dir     = trailingslashit( $pp_uploads['basedir'] ) . 'proofing-pins';
if ( is_dir( $pp_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	if ( isset( $wp_filesystem ) && $wp_filesystem ) {
		$wp_filesystem->delete( $pp_dir, true ); // true = recursive
	}
}

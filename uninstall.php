<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all pin posts + their screenshots.
$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'pp_pin' ) );
foreach ( $post_ids as $post_id ) {
	$screenshot_id = (int) get_post_meta( $post_id, '_pp_screenshot_id', true );
	if ( $screenshot_id ) {
		wp_delete_attachment( $screenshot_id, true );
	}
	wp_delete_post( $post_id, true );
}

delete_option( 'pp_settings' );

$caps = [
	'pp_create_pin', 'pp_view_pins', 'pp_manage_pins',
	'edit_pp_pin', 'read_pp_pin', 'delete_pp_pin',
	'edit_pp_pins', 'edit_others_pp_pins', 'publish_pp_pins',
	'read_private_pp_pins', 'delete_pp_pins',
];
foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role_key ) {
	$role = get_role( $role_key );
	if ( ! $role ) { continue; }
	foreach ( $caps as $cap ) { $role->remove_cap( $cap ); }
}

$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'proofing-pins';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() ); }
	rmdir( $dir );
}

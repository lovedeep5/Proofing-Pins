<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Activator {
	public static function activate(): void {
		( new CPT() )->register();
		( new Capabilities() )->register();
		Capabilities::seed_roles();

		$defaults = [
			'position'       => 'right',
			'brand_color'    => '#2271b1',
			'allowed_roles'  => [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ],
			'auto_resolve_days' => 0,
		];
		if ( get_option( 'pp_settings' ) === false ) {
			update_option( 'pp_settings', $defaults );
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'proofing-pins';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		flush_rewrite_rules();
	}
}

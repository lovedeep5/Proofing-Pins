<?php
/**
 * Plugin Name:       Proofing Pins
 * Plugin URI:        https://github.com/lovedeep5/Proofing-Pins
 * Description:       Pin-point proofing comments on the frontend with screenshots, managed from a clean admin dashboard. Optional AI suggestions and 1-click Apply to Elementor widgets.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * Author:            Lovedeep
 * Author URI:        https://flaircross.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://wordpress.org/plugins/proofing-pins/
 * Text Domain:       proofing-pins
 * Domain Path:       /languages
 *
 * @package ProofingPins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PP_VERSION', '0.1.0' );
define( 'PP_PLUGIN_FILE', __FILE__ );
define( 'PP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PP_POST_TYPE', 'pp_pin' );
define( 'PP_REST_NAMESPACE', 'proofing-pins/v1' );

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'ProofingPins\\' ) !== 0 ) {
		return;
	}
	$relative = strtolower( str_replace( [ 'ProofingPins\\', '_' ], [ '', '-' ], $class ) );
	$relative = str_replace( '\\', '/', $relative );
	$file     = PP_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ 'ProofingPins\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'ProofingPins\\Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	\ProofingPins\Plugin::instance()->boot();
} );

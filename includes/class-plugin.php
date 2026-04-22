<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Plugin {
	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		// Translations are auto-loaded by WordPress 4.6+ for plugins hosted on
		// WordPress.org — no load_plugin_textdomain() call needed.
		( new CPT() )->register();
		( new Capabilities() )->register();
		( new Rest_API() )->register();
		( new Frontend() )->register();
		AI::instance()->register();
		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}
}

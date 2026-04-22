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
		add_action( 'init', [ $this, 'load_textdomain' ] );
		( new CPT() )->register();
		( new Capabilities() )->register();
		( new Rest_API() )->register();
		( new Frontend() )->register();
		AI::instance()->register();
		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'proofing-pins',
			false,
			dirname( plugin_basename( PP_PLUGIN_FILE ) ) . '/languages'
		);
	}
}

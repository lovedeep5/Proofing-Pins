<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Deactivator {
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

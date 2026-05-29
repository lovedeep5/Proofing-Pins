<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Capabilities {
	public const CREATE = 'proopin_create_pin';
	public const VIEW   = 'proopin_view_pins';
	public const MANAGE = 'proopin_manage_pins';

	public function register(): void {
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	public static function seed_roles(): void {
		$roles = [
			'administrator' => [ self::CREATE, self::VIEW, self::MANAGE, 'edit_proopin_pin', 'read_proopin_pin', 'delete_proopin_pin', 'edit_proopin_pins', 'edit_others_proopin_pins', 'publish_proopin_pins', 'read_private_proopin_pins', 'delete_proopin_pins' ],
			'editor'        => [ self::CREATE, self::VIEW, 'edit_proopin_pin', 'read_proopin_pin', 'edit_proopin_pins', 'edit_others_proopin_pins', 'publish_proopin_pins' ],
			'author'        => [ self::CREATE, 'edit_proopin_pin', 'read_proopin_pin', 'edit_proopin_pins', 'publish_proopin_pins' ],
			'contributor'   => [ self::CREATE, 'edit_proopin_pin', 'read_proopin_pin', 'edit_proopin_pins' ],
			'subscriber'    => [ self::CREATE, 'read_proopin_pin' ],
		];
		foreach ( $roles as $role_key => $caps ) {
			$role = get_role( $role_key );
			if ( ! $role ) { continue; }
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function remove_from_roles(): void {
		$all_caps = [ self::CREATE, self::VIEW, self::MANAGE, 'edit_proopin_pin', 'read_proopin_pin', 'delete_proopin_pin', 'edit_proopin_pins', 'edit_others_proopin_pins', 'publish_proopin_pins', 'read_private_proopin_pins', 'delete_proopin_pins' ];
		foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) { continue; }
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'edit_proopin_pin', 'delete_proopin_pin', 'read_proopin_pin' ], true ) ) {
			return $caps;
		}
		$post_id = $args[0] ?? 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) { return $caps; }

		if ( $cap === 'read_proopin_pin' ) {
			return [ self::CREATE ];
		}
		if ( (int) $post->post_author === (int) $user_id ) {
			return [ self::CREATE ];
		}
		return [ self::MANAGE ];
	}
}

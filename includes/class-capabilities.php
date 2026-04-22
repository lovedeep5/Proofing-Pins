<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Capabilities {
	public const CREATE = 'pp_create_pin';
	public const VIEW   = 'pp_view_pins';
	public const MANAGE = 'pp_manage_pins';

	public function register(): void {
		add_filter( 'map_meta_cap', [ $this, 'map_meta_cap' ], 10, 4 );
	}

	public static function seed_roles(): void {
		$roles = [
			'administrator' => [ self::CREATE, self::VIEW, self::MANAGE, 'edit_pp_pin', 'read_pp_pin', 'delete_pp_pin', 'edit_pp_pins', 'edit_others_pp_pins', 'publish_pp_pins', 'read_private_pp_pins', 'delete_pp_pins' ],
			'editor'        => [ self::CREATE, self::VIEW, 'edit_pp_pin', 'read_pp_pin', 'edit_pp_pins', 'edit_others_pp_pins', 'publish_pp_pins' ],
			'author'        => [ self::CREATE, 'edit_pp_pin', 'read_pp_pin', 'edit_pp_pins', 'publish_pp_pins' ],
			'contributor'   => [ self::CREATE, 'edit_pp_pin', 'read_pp_pin', 'edit_pp_pins' ],
			'subscriber'    => [ self::CREATE, 'read_pp_pin' ],
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
		$all_caps = [ self::CREATE, self::VIEW, self::MANAGE, 'edit_pp_pin', 'read_pp_pin', 'delete_pp_pin', 'edit_pp_pins', 'edit_others_pp_pins', 'publish_pp_pins', 'read_private_pp_pins', 'delete_pp_pins' ];
		foreach ( [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ] as $role_key ) {
			$role = get_role( $role_key );
			if ( ! $role ) { continue; }
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'edit_pp_pin', 'delete_pp_pin', 'read_pp_pin' ], true ) ) {
			return $caps;
		}
		$post_id = $args[0] ?? 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) { return $caps; }

		if ( $cap === 'read_pp_pin' ) {
			return [ self::CREATE ];
		}
		if ( (int) $post->post_author === (int) $user_id ) {
			return [ self::CREATE ];
		}
		return [ self::MANAGE ];
	}
}

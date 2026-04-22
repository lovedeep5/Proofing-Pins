<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CPT {
	public const STATUS_OPEN        = 'pp_open';
	public const STATUS_IN_PROGRESS = 'pp_in_progress';
	public const STATUS_RESOLVED    = 'pp_resolved';
	public const STATUS_ARCHIVED    = 'pp_archived';

	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_statuses' ] );
	}

	public function register_post_type(): void {
		register_post_type( PP_POST_TYPE, [
			'labels'              => [
				'name'          => __( 'Proofing Pins', 'proofing-pins' ),
				'singular_name' => __( 'Proofing Pin', 'proofing-pins' ),
			],
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'supports'            => [ 'title', 'editor', 'author', 'comments' ],
			'capability_type'     => [ 'pp_pin', 'pp_pins' ],
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
		] );
	}

	public function register_statuses(): void {
		$statuses = [
			self::STATUS_OPEN        => _x( 'Open', 'pin status', 'proofing-pins' ),
			self::STATUS_IN_PROGRESS => _x( 'In Progress', 'pin status', 'proofing-pins' ),
			self::STATUS_RESOLVED    => _x( 'Resolved', 'pin status', 'proofing-pins' ),
			self::STATUS_ARCHIVED    => _x( 'Archived', 'pin status', 'proofing-pins' ),
		];
		foreach ( $statuses as $key => $label ) {
			register_post_status( $key, [
				'label'                     => $label,
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			] );
		}
	}

	public static function all_statuses(): array {
		return [ self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_ARCHIVED ];
	}
}

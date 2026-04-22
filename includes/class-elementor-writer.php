<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mutates Elementor page data (post meta `_elementor_data`) with strict allowlist
 * of safe widget-setting updates. Designed to be called from the REST "apply"
 * endpoint when a developer approves an AI-proposed change.
 */
class Elementor_Writer {

	/**
	 * Allowlist of editable settings per widget type. Keys are widget types
	 * (without skin suffix). Values map setting_key -> value_type.
	 */
	public static function allowlist(): array {
		return [
			'heading' => [
				'title'       => 'text',
				'title_color' => 'color',
			],
			'button' => [
				'text'              => 'text',
				'background_color'  => 'color',
				'button_text_color' => 'color',
			],
			'text-editor' => [
				// editor contains HTML; we sanitize on write.
				'editor'     => 'html',
				'text_color' => 'color',
			],
		];
	}

	public static function is_allowed( string $widget_type, string $setting_key ): bool {
		$al = self::allowlist();
		return isset( $al[ $widget_type ][ $setting_key ] );
	}

	public static function value_type( string $widget_type, string $setting_key ): string {
		return self::allowlist()[ $widget_type ][ $setting_key ] ?? '';
	}

	/**
	 * Read the current value of a widget setting from the Elementor page data.
	 * Returns null when the widget or setting isn't found.
	 *
	 * @return mixed|null
	 */
	public static function read_setting( int $page_id, string $widget_id, string $setting_key ) {
		$data = self::load_data( $page_id );
		if ( $data === null ) { return null; }
		$found = null;
		self::walk( $data, function ( &$node ) use ( $widget_id, $setting_key, &$found ) {
			if ( ( $node['id'] ?? '' ) === $widget_id && ( $node['elType'] ?? '' ) === 'widget' ) {
				$found = $node['settings'][ $setting_key ] ?? null;
				return true; // stop walk
			}
			return false;
		} );
		return $found;
	}

	/**
	 * Apply a single setting change. Creates a WP post revision first, updates
	 * the setting in-place, saves back, clears Elementor's CSS cache. Returns
	 * the previous value (so the caller can store it for revert).
	 *
	 * @return mixed|\WP_Error Previous value on success, WP_Error on failure.
	 */
	public static function apply( int $page_id, string $widget_id, string $widget_type, string $setting_key, $new_value ) {
		if ( ! self::is_allowed( $widget_type, $setting_key ) ) {
			return new \WP_Error( 'not_allowed', 'This widget/setting combination is not in the apply allowlist.' );
		}
		$post = get_post( $page_id );
		if ( ! $post || get_post_meta( $page_id, '_elementor_data', true ) === '' ) {
			return new \WP_Error( 'not_elementor', 'Target post is not an Elementor page.' );
		}

		$sanitized = self::sanitize_value( self::value_type( $widget_type, $setting_key ), $new_value );
		if ( $sanitized === null ) {
			return new \WP_Error( 'invalid_value', 'Provided value failed validation.' );
		}

		$data = self::load_data( $page_id );
		if ( $data === null ) { return new \WP_Error( 'bad_data', 'Could not parse Elementor data.' ); }

		$found_widget_type = '';
		$previous_value    = null;
		$modified          = false;
		self::walk( $data, function ( &$node ) use ( $widget_id, $setting_key, $sanitized, &$previous_value, &$found_widget_type, &$modified ) {
			if ( ( $node['id'] ?? '' ) === $widget_id && ( $node['elType'] ?? '' ) === 'widget' ) {
				$found_widget_type       = (string) ( $node['widgetType'] ?? '' );
				$previous_value          = $node['settings'][ $setting_key ] ?? null;
				$node['settings']        = $node['settings'] ?? [];
				$node['settings'][ $setting_key ] = $sanitized;
				$modified                = true;
				return true;
			}
			return false;
		} );

		if ( ! $modified ) { return new \WP_Error( 'widget_not_found', 'Widget not found on the page.' ); }
		// Safety: widget type in stored JSON must match the one we expect (prevents applying a heading op to a button).
		if ( $found_widget_type !== '' && strpos( $found_widget_type, $widget_type ) !== 0 ) {
			return new \WP_Error( 'widget_type_mismatch', 'Widget type mismatch: expected ' . $widget_type . ', found ' . $found_widget_type );
		}

		// Snapshot revision before write (captures current post state).
		wp_save_post_revision( $page_id );

		self::save_data( $page_id, $data );
		self::clear_css_cache();

		return $previous_value;
	}

	/**
	 * Restore a previously-saved value (used by the revert endpoint).
	 */
	public static function write_raw( int $page_id, string $widget_id, string $setting_key, $value ): bool {
		$data = self::load_data( $page_id );
		if ( $data === null ) { return false; }
		$modified = false;
		self::walk( $data, function ( &$node ) use ( $widget_id, $setting_key, $value, &$modified ) {
			if ( ( $node['id'] ?? '' ) === $widget_id && ( $node['elType'] ?? '' ) === 'widget' ) {
				$node['settings']                 = $node['settings'] ?? [];
				$node['settings'][ $setting_key ] = $value;
				$modified                         = true;
				return true;
			}
			return false;
		} );
		if ( ! $modified ) { return false; }
		self::save_data( $page_id, $data );
		self::clear_css_cache();
		return true;
	}

	private static function sanitize_value( string $type, $value ) {
		switch ( $type ) {
			case 'text':
				if ( ! is_string( $value ) && ! is_numeric( $value ) ) { return null; }
				$s = trim( (string) $value );
				if ( $s === '' || strlen( $s ) > 500 ) { return null; }
				return sanitize_text_field( $s );
			case 'color':
				if ( ! is_string( $value ) ) { return null; }
				$c = sanitize_hex_color( $value );
				return $c ?: null;
			case 'html':
				if ( ! is_string( $value ) || strlen( $value ) > 20000 ) { return null; }
				return wp_kses( $value, wp_kses_allowed_html( 'post' ) );
			default:
				return null;
		}
	}

	private static function load_data( int $page_id ): ?array {
		$raw = get_post_meta( $page_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || $raw === '' ) { return null; }
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private static function save_data( int $page_id, array $data ): void {
		update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	}

	private static function clear_css_cache(): void {
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch ( \Throwable $e ) {}
		}
	}

	/**
	 * Depth-first walk of the Elementor tree. Callback receives each node by
	 * reference. Return true from the callback to stop the walk.
	 */
	private static function walk( array &$nodes, callable $cb ): bool {
		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) { continue; }
			if ( $cb( $node ) === true ) { return true; }
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				if ( self::walk( $node['elements'], $cb ) ) { return true; }
			}
		}
		return false;
	}
}

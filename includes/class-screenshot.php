<?php
namespace ProofingPins;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Screenshot {
	public static function save_from_data_url( string $data_url, int $user_id ): int {
		$mime = '';
		$ext  = '';
		if ( strpos( $data_url, 'data:image/png;base64,' ) === 0 ) {
			$mime = 'image/png';  $ext = 'png';
			$b64  = substr( $data_url, strlen( 'data:image/png;base64,' ) );
		} elseif ( strpos( $data_url, 'data:image/jpeg;base64,' ) === 0 ) {
			$mime = 'image/jpeg'; $ext = 'jpg';
			$b64  = substr( $data_url, strlen( 'data:image/jpeg;base64,' ) );
		} else {
			return 0;
		}

		$binary = base64_decode( $b64, true );
		if ( $binary === false || strlen( $binary ) < 100 ) {
			return 0;
		}
		// Hard cap to prevent runaway uploads (~12MB for tall-page screenshots).
		if ( strlen( $binary ) > 12 * 1024 * 1024 ) {
			return 0;
		}

		$uploads = wp_upload_dir();
		$subdir  = 'proofing-pins/' . gmdate( 'Y/m' );
		$dir     = trailingslashit( $uploads['basedir'] ) . $subdir;
		$url_dir = trailingslashit( $uploads['baseurl'] ) . $subdir;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename = 'pin-' . wp_generate_uuid4() . '.' . $ext;
		$path     = trailingslashit( $dir ) . $filename;
		if ( file_put_contents( $path, $binary ) === false ) {
			return 0;
		}

		$attachment = [
			'post_mime_type' => $mime,
			'post_title'     => 'Proofing pin screenshot',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		];
		$attach_id  = wp_insert_attachment( $attachment, $path );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			wp_delete_file( $path );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $path );
		wp_update_attachment_metadata( $attach_id, $meta );
		update_post_meta( $attach_id, '_pp_is_screenshot', 1 );
		return (int) $attach_id;
	}
}

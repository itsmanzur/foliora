<?php
/**
 * Foliora_Thumbnails
 *
 * Persistent first-page previews for Media Library PDFs. Generated once
 * from the Documents admin screen (PDF.js canvas -> PNG), stored as real
 * image attachments parented to the PDF.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Thumbnails {

	const META_KEY = '_foliora_thumbnail_id';

	public function init() {
		add_action( 'wp_ajax_foliora_save_thumbnail', array( $this, 'ajax_save_thumbnail' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
	}

	/**
	 * Image URL for a PDF's stored thumbnail, or empty if none yet.
	 *
	 * @param int    $pdf_attachment_id PDF attachment post ID.
	 * @param string $size              Registered image size.
	 * @return string
	 */
	public static function get_thumbnail_url( $pdf_attachment_id, $size = 'medium' ) {
		$thumb_id = self::get_thumbnail_id( $pdf_attachment_id );
		if ( ! $thumb_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $thumb_id, $size );
		return $url ? $url : '';
	}

	/**
	 * @param int $pdf_attachment_id PDF attachment post ID.
	 * @return int
	 */
	public static function get_thumbnail_id( $pdf_attachment_id ) {
		$pdf_attachment_id = absint( $pdf_attachment_id );
		if ( ! $pdf_attachment_id ) {
			return 0;
		}

		return absint( get_post_meta( $pdf_attachment_id, self::META_KEY, true ) );
	}

	public function ajax_save_thumbnail() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		$pdf_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$pdf    = $pdf_id ? get_post( $pdf_id ) : null;

		if ( ! $pdf || 'attachment' !== $pdf->post_type || 'application/pdf' !== $pdf->post_mime_type ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a PDF attachment.', 'foliora' ) ) );
		}

		$raw = isset( $_POST['thumbnail_data'] ) ? wp_unslash( $_POST['thumbnail_data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- binary PNG payload, validated below.
		if ( ! is_string( $raw ) || ! preg_match( '#^data:image/png;base64,#i', $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid thumbnail data.', 'foliora' ) ) );
		}

		$binary = base64_decode( preg_replace( '#^data:image/png;base64,#i', '', $raw ), true );
		// Verify the full 8-byte PNG signature (\x89PNG\r\n\x1a\n).
		// Checking only the first 4 bytes ("\x89PNG") is insufficient; the
		// complete signature guards against other binary formats that share
		// that prefix.
		if ( false === $binary || substr( $binary, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
			wp_send_json_error( array( 'message' => __( 'Invalid thumbnail data.', 'foliora' ) ) );
		}

		if ( strlen( $binary ) > 2 * 1024 * 1024 ) {
			wp_send_json_error( array( 'message' => __( 'Thumbnail is too large.', 'foliora' ) ) );
		}

		$old_id = self::get_thumbnail_id( $pdf_id );
		if ( $old_id ) {
			wp_delete_attachment( $old_id, true );
		}

		$filename = 'foliora-thumb-' . $pdf_id . '.png';
		$upload   = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			wp_send_json_error( array( 'message' => sanitize_text_field( $upload['error'] ) ) );
		}

		$filetype = wp_check_filetype( $filename, null );
		$title    = get_the_title( $pdf );
		if ( '' === $title ) {
			$title = wp_basename( (string) wp_get_attachment_url( $pdf_id ) );
		}

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/png',
				'post_title'     => sprintf(
					/* translators: %s: PDF title. */
					__( 'Foliora thumbnail: %s', 'foliora' ),
					$title
				),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => $pdf_id,
			),
			$upload['file'],
			$pdf_id
		);

		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the thumbnail.', 'foliora' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $metadata );

		update_post_meta( $pdf_id, self::META_KEY, (int) $attach_id );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );

		$url = wp_get_attachment_image_url( $attach_id, 'medium' );

		wp_send_json_success(
			array(
				'id'  => (int) $attach_id,
				'url' => $url ? $url : (string) $upload['url'],
			)
		);
	}

	/**
	 * When a PDF is deleted, also remove its generated thumbnail image.
	 *
	 * @param int $post_id Attachment ID being deleted.
	 */
	public function on_delete_attachment( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$mime = get_post_mime_type( $post_id );
		if ( 'application/pdf' === $mime ) {
			$thumb_id = self::get_thumbnail_id( $post_id );
			delete_post_meta( $post_id, self::META_KEY );

			if ( $thumb_id && $thumb_id !== $post_id ) {
				wp_delete_attachment( $thumb_id, true );
			}
			return;
		}

		$parent = wp_get_post_parent_id( $post_id );
		if ( $parent && self::get_thumbnail_id( $parent ) === $post_id ) {
			delete_post_meta( $parent, self::META_KEY );
		}
	}
}

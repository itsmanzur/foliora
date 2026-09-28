<?php
/**
 * Foliora_A11y
 *
 * Detects whether a Media Library PDF is a tagged (accessible) file.
 * PDF.js reads the MarkInfo dictionary on Foliora → Documents; the
 * result is stored on the attachment so editors see a warning when
 * screen-reader structure is missing.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_A11y {

	const META_TAGGED  = '_foliora_pdf_tagged';
	const META_CHECKED = '_foliora_a11y_checked';

	public function init() {
		add_action( 'wp_ajax_foliora_save_a11y', array( $this, 'ajax_save_a11y' ) );
	}

	/**
	 * @param int $attachment_id PDF attachment ID.
	 * @return bool
	 */
	public static function is_checked( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}
		return '1' === (string) get_post_meta( $attachment_id, self::META_CHECKED, true );
	}

	/**
	 * @param int $attachment_id PDF attachment ID.
	 * @return bool
	 */
	public static function is_tagged( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}
		$tagged = ( '1' === (string) get_post_meta( $attachment_id, self::META_TAGGED, true ) );

		/**
		 * Filter: foliora/pdf_tagged
		 *
		 * Override the stored tagged-PDF flag for an attachment.
		 *
		 * @param bool $tagged         Whether MarkInfo /Marked is true.
		 * @param int  $attachment_id  PDF attachment ID.
		 */
		return (bool) apply_filters( 'foliora/pdf_tagged', $tagged, $attachment_id );
	}

	/**
	 * @return bool
	 */
	public static function checking_enabled() {
		$settings = get_option( 'foliora_settings', array() );
		return ! isset( $settings['check_pdf_a11y'] ) || ! empty( $settings['check_pdf_a11y'] );
	}

	public function ajax_save_a11y() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'foliora' ) ), 403 );
		}

		if ( ! self::checking_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'PDF accessibility checks are turned off.', 'foliora' ) ) );
		}

		$pdf_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$pdf    = $pdf_id ? get_post( $pdf_id ) : null;

		if ( ! $pdf || 'attachment' !== $pdf->post_type || 'application/pdf' !== $pdf->post_mime_type ) {
			wp_send_json_error( array( 'message' => __( 'That file is not a PDF attachment.', 'foliora' ) ) );
		}

		$tagged = isset( $_POST['tagged'] ) && '1' === (string) wp_unslash( $_POST['tagged'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to literal '1'.

		update_post_meta( $pdf_id, self::META_CHECKED, '1' );
		update_post_meta( $pdf_id, self::META_TAGGED, $tagged ? '1' : '0' );

		wp_send_json_success(
			array(
				'tagged' => $tagged,
			)
		);
	}
}

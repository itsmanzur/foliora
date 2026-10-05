<?php
/**
 * Foliora Autoembed
 *
 * Handles automatic embedding of standalone .pdf URLs via wp_embed_register_handler
 * and rendering Foliora viewers on standard WordPress PDF attachment pages.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Autoembed {

	/**
	 * Register handlers and hooks.
	 */
	public function init() {
		// Register oEmbed-style handler for plain PDF URLs pasted on their own line.
		wp_embed_register_handler(
			'foliora_pdf',
			'#^https?://[^\s<>"\'\?\#]+\.pdf(?:\?[^\s<>"\'\#]*)?$#i',
			array( $this, 'render_url_embed' )
		);

		// Attachment page filter for PDF attachment views.
		add_filter( 'prepend_attachment', array( $this, 'filter_attachment_page' ) );
	}

	/**
	 * Callback for wp_embed_register_handler.
	 *
	 * Converts standalone PDF URL into a Foliora viewer without any server-side fetching.
	 * Browser-side PDF.js loads the remote URL directly.
	 *
	 * @param array  $matches Regex matches.
	 * @param array  $attr    Embed attributes.
	 * @param string $url     Original URL.
	 * @param array  $rawattr Raw embed attributes.
	 * @return string HTML output or original URL.
	 */
	public function render_url_embed( $matches, $attr, $url, $rawattr ) {
		unset( $attr, $rawattr ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		$settings = get_option( 'foliora_settings', array() );
		if ( isset( $settings['autoembed_url'] ) && ! $settings['autoembed_url'] ) {
			return $url;
		}

		$pdf_url = esc_url_raw( $matches[0] );
		if ( ! $pdf_url ) {
			return $url;
		}

		return Foliora::instance()->viewer->render_shortcode(
			array(
				'file' => $pdf_url,
			)
		);
	}

	/**
	 * Filter attachment page output for PDF attachments.
	 *
	 * Replaces the plain link on single attachment pages with the full Foliora interactive viewer.
	 *
	 * @param string $content Existing attachment content HTML link.
	 * @return string
	 */
	public function filter_attachment_page( $content ) {
		if ( ! is_attachment() ) {
			return $content;
		}

		$settings = get_option( 'foliora_settings', array() );
		if ( isset( $settings['embed_attachment_pages'] ) && ! $settings['embed_attachment_pages'] ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || 'application/pdf' !== get_post_mime_type( $post_id ) ) {
			return $content;
		}

		$pdf_url = wp_get_attachment_url( $post_id );
		if ( ! $pdf_url ) {
			return $content;
		}

		return Foliora::instance()->viewer->render_shortcode(
			array(
				'file'  => esc_url_raw( $pdf_url ),
				'title' => get_the_title( $post_id ),
			)
		);
	}
}

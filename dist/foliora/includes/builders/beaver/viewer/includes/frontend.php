<?php
/**
 * Beaver Builder frontend: Foliora Viewer.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$foliora_atts = array(
	'file'     => isset( $settings->file ) ? esc_url_raw( $settings->file ) : '',
	'width'    => isset( $settings->width ) ? sanitize_text_field( (string) $settings->width ) : '100%',
	'height'   => isset( $settings->height ) ? sanitize_text_field( (string) $settings->height ) : '600px',
	'page'     => isset( $settings->page ) ? absint( $settings->page ) : 1,
	'title'    => isset( $settings->title ) ? sanitize_text_field( (string) $settings->title ) : '',
	'download' => isset( $settings->download ) ? sanitize_text_field( (string) $settings->download ) : 'true',
	'print'    => isset( $settings->print ) ? sanitize_text_field( (string) $settings->print ) : 'true',
);

echo Foliora::instance()->viewer->render_shortcode( $foliora_atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- viewer HTML is escaped internally.

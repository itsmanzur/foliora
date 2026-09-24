<?php
/**
 * Beaver Builder frontend: Foliora Viewer.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo Foliora::instance()->viewer->render_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- viewer HTML is escaped internally.
	array(
		'file'     => isset( $settings->file ) ? $settings->file : '',
		'width'    => isset( $settings->width ) ? $settings->width : '100%',
		'height'   => isset( $settings->height ) ? $settings->height : '600px',
		'page'     => isset( $settings->page ) ? $settings->page : '1',
		'title'    => isset( $settings->title ) ? $settings->title : '',
		'download' => isset( $settings->download ) ? $settings->download : 'true',
		'print'    => isset( $settings->print ) ? $settings->print : 'true',
	)
);

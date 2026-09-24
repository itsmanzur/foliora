<?php
/**
 * Beaver Builder frontend: Foliora Library.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo Foliora::instance()->library->render_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- library HTML is escaped internally.
	array(
		'columns'  => isset( $settings->columns ) ? $settings->columns : '3',
		'per_page' => isset( $settings->per_page ) ? $settings->per_page : '12',
		'orderby'  => isset( $settings->orderby ) ? $settings->orderby : 'date',
		'order'    => isset( $settings->order ) ? $settings->order : 'DESC',
		'search'   => isset( $settings->search ) ? $settings->search : 'true',
	)
);

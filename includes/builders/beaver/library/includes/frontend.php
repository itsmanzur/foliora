<?php
/**
 * Beaver Builder frontend: Foliora Library.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$foliora_atts = array(
	'columns'  => isset( $settings->columns ) ? sanitize_text_field( (string) $settings->columns ) : '3',
	'per_page' => isset( $settings->per_page ) ? absint( $settings->per_page ) : 12,
	'orderby'  => isset( $settings->orderby ) ? sanitize_key( $settings->orderby ) : 'date',
	'order'    => isset( $settings->order ) ? sanitize_key( $settings->order ) : 'DESC',
	'search'   => isset( $settings->search ) ? sanitize_text_field( (string) $settings->search ) : 'true',
);

echo Foliora::instance()->library->render_shortcode( $foliora_atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- library HTML is escaped internally.

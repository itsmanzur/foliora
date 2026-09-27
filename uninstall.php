<?php
/**
 * Fires only when the user clicks "Delete" on the Plugins screen —
 * never on simple deactivation. Removes the options this plugin created.
 *
 * @package Foliora
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'foliora_settings' );
delete_transient( 'foliora_activation_redirect' );

// Thumbnails are real image attachments parented to the PDF; the meta key
// holding their IDs is about to be wiped, so delete the attachments first.
global $wpdb;
$thumbnail_ids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_foliora_thumbnail_id'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
foreach ( $thumbnail_ids as $thumbnail_id ) {
	$thumbnail_id = absint( $thumbnail_id );
	if ( $thumbnail_id ) {
		wp_delete_attachment( $thumbnail_id, true );
	}
}

delete_post_meta_by_key( '_foliora_extracted_text' );
delete_post_meta_by_key( '_foliora_extracted_pages' );
delete_post_meta_by_key( '_foliora_text_indexed' );
delete_post_meta_by_key( '_foliora_search_index' );
delete_post_meta_by_key( '_foliora_pdf_tagged' );
delete_post_meta_by_key( '_foliora_a11y_checked' );
delete_post_meta_by_key( '_foliora_thumbnail_id' );
delete_metadata( 'user', 0, 'foliora_welcome_dismissed', '', true );

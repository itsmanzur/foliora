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
delete_post_meta_by_key( '_foliora_extracted_text' );
delete_post_meta_by_key( '_foliora_extracted_pages' );
delete_post_meta_by_key( '_foliora_text_indexed' );
delete_post_meta_by_key( '_foliora_search_index' );
delete_post_meta_by_key( '_foliora_thumbnail_id' );
delete_post_meta_by_key( '_foliora_pdf_tagged' );
delete_post_meta_by_key( '_foliora_a11y_checked' );
delete_metadata( 'user', 0, 'foliora_welcome_dismissed', '', true );


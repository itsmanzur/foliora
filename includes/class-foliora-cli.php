<?php
/**
 * Foliora_CLI
 *
 * WP-CLI integration for batch indexing and inspection.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_CLI {

	/**
	 * Show Foliora document and index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp foliora status
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		$pdf_ids = $query->posts;
		$total   = count( $pdf_ids );
		$thumbs  = 0;
		$indexed = 0;
		$tagged  = 0;
		$checked = 0;

		foreach ( $pdf_ids as $id ) {
			if ( Foliora_Thumbnails::get_thumbnail_id( $id ) ) {
				++$thumbs;
			}
			if ( Foliora_SEO::is_indexed( $id ) ) {
				++$indexed;
			}
			if ( Foliora_A11y::is_checked( $id ) ) {
				++$checked;
				if ( Foliora_A11y::is_tagged( $id ) ) {
					++$tagged;
				}
			}
		}

		WP_CLI::line( WP_CLI::colorize( '%G=== Foliora Document Status ===%n' ) );
		WP_CLI::line( sprintf( 'Total PDF documents:   %d', $total ) );
		WP_CLI::line( sprintf( 'Generated thumbnails:  %d / %d', $thumbs, $total ) );
		WP_CLI::line( sprintf( 'Indexed for search:    %d / %d', $indexed, $total ) );
		WP_CLI::line( sprintf( 'Checked accessibility: %d / %d (%d tagged)', $checked, $total, $tagged ) );
	}

	/**
	 * Rebuild the search index for all posts/pages embedding PDFs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp foliora reindex
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function reindex( $args = array(), $assoc_args = array() ) {
		global $wpdb;

		$like_shortcode = '%' . $wpdb->esc_like( '[foliora' ) . '%';
		$like_block     = '%' . $wpdb->esc_like( 'wp:foliora/' ) . '%';

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','pending') AND post_type NOT IN ('revision','attachment','nav_menu_item') AND (post_content LIKE %s OR post_content LIKE %s)",
				$like_shortcode,
				$like_block
			)
		);

		if ( empty( $post_ids ) ) {
			WP_CLI::success( 'No embedding posts found to reindex.' );
			return;
		}

		$seo = Foliora::instance()->seo;
		$count = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Reindexing posts embedding PDFs', count( $post_ids ) );
		foreach ( $post_ids as $pid ) {
			$post = get_post( (int) $pid );
			if ( $post instanceof WP_Post ) {
				$seo->on_save_post( $post->ID, $post );
				++$count;
			}
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success( sprintf( 'Successfully reindexed %d post(s).', $count ) );
	}

	/**
	 * Clear Foliora cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp foliora clear_cache
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function clear_cache( $args = array(), $assoc_args = array() ) {
		wp_cache_delete( 'embed_count', 'foliora' );
		WP_CLI::success( 'Foliora cache cleared.' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'foliora', 'Foliora_CLI' );
}

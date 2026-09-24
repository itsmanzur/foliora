<?php
/**
 * Foliora_Thumbnails tests.
 *
 * @package Foliora
 */

class Test_Foliora_Thumbnails extends Foliora_TestCase {

	public function test_get_thumbnail_id_is_zero_for_invalid_ids() {
		$this->assertSame( 0, Foliora_Thumbnails::get_thumbnail_id( 0 ) );
		$this->assertSame( 0, Foliora_Thumbnails::get_thumbnail_id( -1 ) );
		$this->assertSame( 0, Foliora_Thumbnails::get_thumbnail_id( 999999 ) );
	}

	public function test_get_thumbnail_url_empty_without_meta() {
		$pdf_id = $this->create_pdf_attachment();
		$this->assertSame( '', Foliora_Thumbnails::get_thumbnail_url( $pdf_id ) );
	}

	public function test_stores_and_reads_thumbnail_id() {
		$pdf_id   = $this->create_pdf_attachment();
		$thumb_id = $this->create_png_attachment( $pdf_id );
		update_post_meta( $pdf_id, Foliora_Thumbnails::META_KEY, $thumb_id );
		$this->assertSame( $thumb_id, Foliora_Thumbnails::get_thumbnail_id( $pdf_id ) );
		$this->assertNotEmpty( Foliora_Thumbnails::get_thumbnail_url( $pdf_id, 'full' ) );
	}

	public function test_deleting_pdf_clears_thumbnail_meta() {
		$pdf_id = $this->create_pdf_attachment();
		update_post_meta( $pdf_id, Foliora_Thumbnails::META_KEY, 12345 );
		$thumbs = new Foliora_Thumbnails();
		$thumbs->on_delete_attachment( $pdf_id );
		$this->assertSame( '', get_post_meta( $pdf_id, Foliora_Thumbnails::META_KEY, true ) );
	}

	/**
	 * @param int $parent Parent attachment ID.
	 * @return int
	 */
	private function create_png_attachment( $parent ) {
		$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true );
		$bits = wp_upload_bits( 'foliora-unit-thumb.png', null, $png );
		$this->assertEmpty( $bits['error'] );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Thumb',
				'post_status'    => 'inherit',
				'post_parent'    => $parent,
				'guid'           => $bits['url'],
			),
			$bits['file'],
			$parent
		);
		$this->assertNotWPError( $id );
		return (int) $id;
	}
}

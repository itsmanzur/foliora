<?php
/**
 * Shared PHPUnit helpers.
 *
 * @package Foliora
 */

abstract class Foliora_TestCase extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->reset_loader_cache();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	public function tear_down() {
		$this->reset_loader_cache();
		parent::tear_down();
	}

	protected function reset_loader_cache() {
		$property = new ReflectionProperty( 'Foliora_Loader', 'is_pro_active' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * @param string $title Attachment title.
	 * @return int
	 */
	protected function create_pdf_attachment( $title = 'Test PDF' ) {
		$source = dirname( __DIR__ ) . '/fixtures/sample.pdf';
		$bits   = wp_upload_bits( 'foliora-test.pdf', null, file_get_contents( $source ) );
		$this->assertEmpty( $bits['error'], $bits['error'] ? $bits['error'] : '' );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'guid'           => $bits['url'],
			),
			$bits['file']
		);
		$this->assertNotWPError( $id );
		return (int) $id;
	}
}

<?php
/**
 * Foliora_Library tests.
 *
 * @package Foliora
 */

class Test_Foliora_Library extends Foliora_TestCase {

	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( 'foliora_library' ) );
	}

	public function test_empty_library_shows_empty_state() {
		$html = Foliora::instance()->library->render_shortcode( array() );
		$this->assertStringContainsString( 'foliora-library', $html );
		$this->assertStringContainsString( 'No PDFs found', $html );
		$this->assertStringContainsString( 'foliora-library-search', $html );
	}

	public function test_search_can_be_disabled() {
		$html = Foliora::instance()->library->render_shortcode( array( 'search' => 'false' ) );
		$this->assertStringNotContainsString( 'foliora-library-search', $html );
	}

	public function test_library_lists_pdf_attachment() {
		$this->create_pdf_attachment( 'Quarterly Report' );
		$html = Foliora::instance()->library->render_shortcode(
			array(
				'columns'  => '2',
				'search'   => 'false',
				'per_page' => '12',
			)
		);
		$this->assertStringContainsString( 'foliora-library-item', $html );
		$this->assertStringContainsString( 'Quarterly Report', $html );
		$this->assertStringContainsString( '--foliora-cols: 2', $html );
		$this->assertStringContainsString( 'foliora-viewer', $html );
	}

	public function test_invalid_columns_fall_back_to_default() {
		$this->create_pdf_attachment( 'Fallback PDF' );
		$html = Foliora::instance()->library->render_shortcode(
			array(
				'columns' => '99',
				'search'  => 'false',
			)
		);
		$this->assertStringContainsString( '--foliora-cols: 3', $html );
	}

	public function test_search_no_match_message() {
		$this->create_pdf_attachment( 'Something else' );
		$property = new ReflectionProperty( 'Foliora_Library', 'instance_n' );
		$property->setAccessible( true );
		$next = (int) $property->getValue() + 1;
		$_GET[ 'foliora_q_' . $next ] = 'zzzz-no-such-pdf';
		$html = Foliora::instance()->library->render_shortcode( array( 'search' => 'true' ) );
		unset( $_GET[ 'foliora_q_' . $next ] );
		$this->assertStringContainsString( 'No PDFs matched that search', $html );
	}
}

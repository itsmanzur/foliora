<?php
/**
 * Foliora_Viewer tests.
 *
 * @package Foliora
 */

class Test_Foliora_Viewer extends Foliora_TestCase {

	public function test_empty_file_returns_admin_notice_for_editors() {
		$html = Foliora::instance()->viewer->render_shortcode( array() );
		$this->assertStringContainsString( 'foliora-admin-notice', $html );
		$this->assertStringContainsString( 'no file URL', $html );
	}

	public function test_empty_file_is_blank_for_anonymous_visitors() {
		wp_set_current_user( 0 );
		$html = Foliora::instance()->viewer->render_shortcode( array() );
		$this->assertSame( '', $html );
	}

	public function test_render_includes_viewer_chrome_and_file_url() {
		$url  = 'https://example.com/docs/manual.pdf';
		$html = Foliora::instance()->viewer->render_shortcode(
			array(
				'file'  => $url,
				'title' => 'Annual report',
				'page'  => '3',
			)
		);
		$this->assertStringContainsString( 'class="foliora-viewer"', $html );
		$this->assertStringContainsString( 'data-file="' . esc_url( $url ) . '"', $html );
		$this->assertStringContainsString( 'data-page="3"', $html );
		$this->assertStringContainsString( 'foliora-caption', $html );
		$this->assertStringContainsString( 'Annual report', $html );
		$this->assertStringContainsString( 'foliora-rotate', $html );
		$this->assertStringContainsString( 'foliora-download', $html );
		$this->assertStringContainsString( 'data-view="page"', $html );
		$this->assertStringContainsString( 'data-resume="1"', $html );
		$this->assertStringContainsString( 'foliora-find-status', $html );
		$this->assertStringContainsString( 'foliora-view', $html );
		$this->assertStringContainsString( 'foliora-pan', $html );
		$this->assertStringContainsString( 'foliora-more', $html );
		$this->assertStringContainsString( 'foliora-present', $html );
	}

	public function test_download_and_print_can_be_hidden() {
		$html = Foliora::instance()->viewer->render_shortcode(
			array(
				'file'     => 'https://example.com/a.pdf',
				'download' => 'false',
				'print'    => 'false',
			)
		);
		$this->assertStringNotContainsString( 'foliora-download', $html );
		$this->assertStringNotContainsString( 'foliora-print', $html );
	}

	public function test_view_and_resume_attributes() {
		$html = Foliora::instance()->viewer->render_shortcode(
			array(
				'file'   => 'https://example.com/a.pdf',
				'view'   => 'scroll',
				'resume' => 'false',
			)
		);
		$this->assertStringContainsString( 'data-view="scroll"', $html );
		$this->assertStringContainsString( 'data-resume="0"', $html );

		$invalid = Foliora::instance()->viewer->render_shortcode(
			array(
				'file' => 'https://example.com/a.pdf',
				'view' => 'flip3d',
			)
		);
		$this->assertStringContainsString( 'data-view="page"', $invalid );
	}

	public function test_epub_mode_is_locked_without_pro() {
		$html = Foliora::instance()->viewer->render_shortcode(
			array(
				'file' => 'https://example.com/book.epub',
				'mode' => 'epub',
			)
		);
		$this->assertStringContainsString( 'foliora-locked', $html );
		$this->assertStringContainsString( 'Foliora Pro', $html );
	}

	public function test_shortcode_is_registered() {
		$this->assertTrue( shortcode_exists( 'foliora' ) );
	}

	public function test_render_enqueues_pdfjs_and_viewer() {
		Foliora::instance()->viewer->render_shortcode(
			array( 'file' => 'https://example.com/a.pdf' )
		);
		$this->assertTrue( wp_script_is( 'foliora-pdfjs', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'foliora-viewer', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'foliora-viewer', 'enqueued' ) );
	}

	public function test_viewer_atts_filter_can_inject_file() {
		add_filter(
			'foliora/viewer_atts',
			static function ( $atts ) {
				$atts['file'] = 'https://example.com/from-filter.pdf';
				return $atts;
			}
		);
		$html = Foliora::instance()->viewer->render_shortcode( array() );
		$this->assertStringContainsString( 'from-filter.pdf', $html );
		remove_all_filters( 'foliora/viewer_atts' );
	}
}

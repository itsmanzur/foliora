<?php
/**
 * Elementor widget: Foliora Viewer.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Elementor_Viewer extends \Elementor\Widget_Base {

	public function get_name() {
		return 'foliora-viewer';
	}

	public function get_title() {
		return __( 'Foliora Viewer', 'foliora' );
	}

	public function get_icon() {
		return 'eicon-document-file';
	}

	public function get_categories() {
		return array( 'foliora', 'general' );
	}

	public function get_keywords() {
		return array( 'pdf', 'document', 'viewer', 'foliora', 'ebook' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_file',
			array(
				'label' => __( 'Document', 'foliora' ),
			)
		);

		$this->add_control(
			'file',
			array(
				'label'        => __( 'PDF file', 'foliora' ),
				'type'         => \Elementor\Controls_Manager::MEDIA,
				'media_types'  => array( 'application/pdf' ),
				'description'  => __( 'Choose a PDF from the Media Library.', 'foliora' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'   => __( 'Title caption', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'page',
			array(
				'label'   => __( 'Start page', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 1,
				'min'     => 1,
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_layout',
			array(
				'label' => __( 'Layout', 'foliora' ),
			)
		);

		$this->add_control(
			'width',
			array(
				'label'   => __( 'Width', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '100%',
			)
		);

		$this->add_control(
			'height',
			array(
				'label'   => __( 'Height', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '600px',
			)
		);

		$this->add_control(
			'download',
			array(
				'label'        => __( 'Download button', 'foliora' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'foliora' ),
				'label_off'    => __( 'Hide', 'foliora' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'print',
			array(
				'label'        => __( 'Print button', 'foliora' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'foliora' ),
				'label_off'    => __( 'Hide', 'foliora' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$file     = '';
		if ( ! empty( $settings['file']['url'] ) ) {
			$file = $settings['file']['url'];
		}

		echo Foliora::instance()->viewer->render_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- viewer HTML is escaped internally.
			array(
				'file'     => $file,
				'width'    => isset( $settings['width'] ) ? $settings['width'] : '100%',
				'height'   => isset( $settings['height'] ) ? $settings['height'] : '600px',
				'page'     => isset( $settings['page'] ) ? $settings['page'] : 1,
				'title'    => isset( $settings['title'] ) ? $settings['title'] : '',
				'download' => ( ! isset( $settings['download'] ) || 'yes' === $settings['download'] ) ? 'true' : 'false',
				'print'    => ( ! isset( $settings['print'] ) || 'yes' === $settings['print'] ) ? 'true' : 'false',
			)
		);
	}
}

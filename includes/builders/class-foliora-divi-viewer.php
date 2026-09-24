<?php
/**
 * Divi module: Foliora Viewer.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Divi_Viewer extends ET_Builder_Module {

	public $slug       = 'foliora_viewer';
	public $vb_support = 'partial';

	protected $module_credits = array(
		'module_uri' => 'https://thereadscope.com/foliora',
		'author'     => 'The Read Scope',
		'author_uri' => 'https://thereadscope.com',
	);

	public function init() {
		$this->name             = __( 'Foliora Viewer', 'foliora' );
		$this->icon_path        = '';
		$this->main_css_element = '%%order_class%%';
		$this->settings_modal_toggles = array(
			'general' => array(
				'toggles' => array(
					'main_content' => __( 'Document', 'foliora' ),
					'layout'       => __( 'Layout', 'foliora' ),
				),
			),
		);
	}

	public function get_fields() {
		return array(
			'file'     => array(
				'label'              => __( 'PDF file', 'foliora' ),
				'type'               => 'upload',
				'option_category'    => 'basic_option',
				'upload_button_text' => esc_attr__( 'Select a PDF', 'foliora' ),
				'choose_text'        => esc_attr__( 'Choose a PDF', 'foliora' ),
				'update_text'        => esc_attr__( 'Set as PDF', 'foliora' ),
				'description'        => __( 'Select a PDF from the Media Library. If the upload picker shows images only, paste the PDF URL instead.', 'foliora' ),
				'toggle_slug'        => 'main_content',
			),
			'title'    => array(
				'label'           => __( 'Title caption', 'foliora' ),
				'type'            => 'text',
				'option_category' => 'basic_option',
				'toggle_slug'     => 'main_content',
			),
			'page'     => array(
				'label'           => __( 'Start page', 'foliora' ),
				'type'            => 'text',
				'default'         => '1',
				'option_category' => 'basic_option',
				'toggle_slug'     => 'main_content',
			),
			'width'    => array(
				'label'           => __( 'Width', 'foliora' ),
				'type'            => 'text',
				'default'         => '100%',
				'option_category' => 'layout',
				'toggle_slug'     => 'layout',
			),
			'height'   => array(
				'label'           => __( 'Height', 'foliora' ),
				'type'            => 'text',
				'default'         => '600px',
				'option_category' => 'layout',
				'toggle_slug'     => 'layout',
			),
			'download' => array(
				'label'           => __( 'Download button', 'foliora' ),
				'type'            => 'yes_no_button',
				'options'         => array(
					'on'  => __( 'Show', 'foliora' ),
					'off' => __( 'Hide', 'foliora' ),
				),
				'default'         => 'on',
				'option_category' => 'layout',
				'toggle_slug'     => 'layout',
			),
			'print'    => array(
				'label'           => __( 'Print button', 'foliora' ),
				'type'            => 'yes_no_button',
				'options'         => array(
					'on'  => __( 'Show', 'foliora' ),
					'off' => __( 'Hide', 'foliora' ),
				),
				'default'         => 'on',
				'option_category' => 'layout',
				'toggle_slug'     => 'layout',
			),
		);
	}

	public function render( $attrs, $content, $render_slug ) {
		unset( $attrs, $content, $render_slug );

		return Foliora::instance()->viewer->render_shortcode(
			array(
				'file'     => isset( $this->props['file'] ) ? $this->props['file'] : '',
				'width'    => isset( $this->props['width'] ) ? $this->props['width'] : '100%',
				'height'   => isset( $this->props['height'] ) ? $this->props['height'] : '600px',
				'page'     => isset( $this->props['page'] ) ? $this->props['page'] : '1',
				'title'    => isset( $this->props['title'] ) ? $this->props['title'] : '',
				'download' => ( isset( $this->props['download'] ) && 'off' === $this->props['download'] ) ? 'false' : 'true',
				'print'    => ( isset( $this->props['print'] ) && 'off' === $this->props['print'] ) ? 'false' : 'true',
			)
		);
	}
}

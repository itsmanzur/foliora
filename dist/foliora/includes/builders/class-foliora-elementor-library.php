<?php
/**
 * Elementor widget: Foliora Library.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Elementor_Library extends \Elementor\Widget_Base {

	public function get_name() {
		return 'foliora-library';
	}

	public function get_title() {
		return __( 'Foliora Library', 'foliora' );
	}

	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	public function get_categories() {
		return array( 'foliora', 'general' );
	}

	public function get_keywords() {
		return array( 'pdf', 'library', 'gallery', 'foliora', 'documents' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_grid',
			array(
				'label' => __( 'Grid', 'foliora' ),
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => __( 'Columns', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
			)
		);

		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Per page', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 48,
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => __( 'Order by', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'     => __( 'Date', 'foliora' ),
					'title'    => __( 'Title', 'foliora' ),
					'modified' => __( 'Modified', 'foliora' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Order', 'foliora' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => array(
					'DESC' => __( 'Newest first', 'foliora' ),
					'ASC'  => __( 'Oldest first', 'foliora' ),
				),
			)
		);

		$this->add_control(
			'search',
			array(
				'label'        => __( 'Search box', 'foliora' ),
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
		$atts     = array(
			'columns'  => isset( $settings['columns'] ) ? sanitize_text_field( (string) $settings['columns'] ) : '3',
			'per_page' => isset( $settings['per_page'] ) ? absint( $settings['per_page'] ) : 12,
			'orderby'  => isset( $settings['orderby'] ) ? sanitize_key( $settings['orderby'] ) : 'date',
			'order'    => isset( $settings['order'] ) ? sanitize_key( $settings['order'] ) : 'DESC',
			'search'   => ( ! isset( $settings['search'] ) || 'yes' === $settings['search'] ) ? 'true' : 'false',
		);

		echo Foliora::instance()->library->render_shortcode( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- library HTML is escaped internally.
	}
}

<?php
/**
 * Foliora_Embeds
 *
 * Manages saved PDF embeds via the `foliora_embed` Custom Post Type.
 * Stores embed configurations in post meta, enabling clean, short shortcodes
 * like `[foliora id="1"]`.
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Embeds {

	const POST_TYPE = 'foliora_embed';
	const META_KEY  = '_foliora_embed_config';

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );

		if ( is_admin() ) {
			add_action( 'wp_ajax_foliora_save_embed', array( $this, 'ajax_save_embed' ) );
			add_action( 'wp_ajax_foliora_delete_embed', array( $this, 'ajax_delete_embed' ) );
			add_action( 'wp_ajax_foliora_duplicate_embed', array( $this, 'ajax_duplicate_embed' ) );
			add_action( 'wp_ajax_foliora_get_embeds', array( $this, 'ajax_get_embeds' ) );
		}
	}

	/**
	 * Register the private Custom Post Type for storing embed settings.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Foliora Embeds', 'foliora' ),
					'singular_name' => __( 'Foliora Embed', 'foliora' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'can_export'          => true,
				'capability_type'     => 'post',
			)
		);
	}

	/**
	 * Get configuration for a specific embed ID.
	 *
	 * @param int $id Embed Post ID.
	 * @return array|null Configuration array or null if not found.
	 */
	public static function get( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$config = get_post_meta( $id, self::META_KEY, true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$config['id'] = $id;
		$config['name'] = $post->post_title;

		return $config;
	}

	/**
	 * Get all saved embeds.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of embed data.
	 */
	public static function get_all( $args = array() ) {
		$defaults = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$query_args = wp_parse_args( $args, $defaults );
		$posts = get_posts( $query_args );

		$embeds = array();
		foreach ( $posts as $p ) {
			$config = get_post_meta( $p->ID, self::META_KEY, true );
			if ( ! is_array( $config ) ) {
				$config = array();
			}
			$embeds[] = array(
				'id'         => $p->ID,
				'name'       => $p->post_title,
				'file'       => $config['file'] ?? '',
				'title'      => $config['title'] ?? '',
				'view'       => $config['view'] ?? 'page',
				'theme'      => $config['theme'] ?? 'light',
				'width'      => $config['width'] ?? '100%',
				'height'     => $config['height'] ?? '520px',
				'shortcode'  => '[foliora id="' . $p->ID . '"]',
				'date'       => get_the_date( 'M j, Y', $p ),
				'date_raw'   => $p->post_date,
			);
		}

		return $embeds;
	}

	/**
	 * Save or update an embed configuration.
	 *
	 * @param array $data Embed configuration data.
	 * @param int   $id   Existing post ID to update, or 0 for new.
	 * @return int|WP_Error Post ID or error.
	 */
	public static function save( $data, $id = 0 ) {
		$id = absint( $id );
		$name = ! empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

		if ( empty( $name ) ) {
			if ( ! empty( $data['title'] ) ) {
				$name = sanitize_text_field( $data['title'] );
			} elseif ( ! empty( $data['file'] ) ) {
				$name = wp_basename( (string) $data['file'] );
			} else {
				$name = __( 'PDF Embed', 'foliora' );
			}
		}

		$post_data = array(
			'post_title'  => $name,
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
		);

		if ( $id > 0 ) {
			$existing = get_post( $id );
			if ( ! $existing || self::POST_TYPE !== $existing->post_type ) {
				return new WP_Error( 'invalid_id', __( 'Invalid embed ID.', 'foliora' ) );
			}
			$post_data['ID'] = $id;
			$post_id = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'save_failed', __( 'Could not save embed.', 'foliora' ) );
		}

		// Sanitize config fields.
		$config = array(
			'file'         => ! empty( $data['file'] ) ? esc_url_raw( $data['file'] ) : '',
			'title'        => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'width'        => isset( $data['width'] ) ? sanitize_text_field( $data['width'] ) : '100%',
			'height'       => isset( $data['height'] ) ? sanitize_text_field( $data['height'] ) : '520px',
			'theme'        => in_array( $data['theme'] ?? '', array( 'light', 'dark', 'sepia' ), true ) ? $data['theme'] : 'light',
			'page'         => max( 1, absint( $data['page'] ?? 1 ) ),
			'view'         => in_array( $data['view'] ?? '', array( 'page', 'scroll', 'spread', 'flip' ), true ) ? $data['view'] : 'page',
			'hide'         => isset( $data['hide'] ) ? sanitize_text_field( $data['hide'] ) : '',
			'loading'      => ( isset( $data['loading'] ) && 'lazy' === $data['loading'] ) ? 'lazy' : 'eager',
			'hash'         => ( isset( $data['hash'] ) && 'false' === (string) $data['hash'] ) ? 'false' : 'true',
			'resume'       => ( isset( $data['resume'] ) && 'false' === (string) $data['resume'] ) ? 'false' : 'true',
			'download'     => isset( $data['download'] ) ? sanitize_text_field( (string) $data['download'] ) : '',
			'print'        => isset( $data['print'] ) ? sanitize_text_field( (string) $data['print'] ) : '',
			'search'       => isset( $data['search'] ) ? sanitize_text_field( (string) $data['search'] ) : '',
			'theme_btn'    => isset( $data['theme_btn'] ) ? sanitize_text_field( (string) $data['theme_btn'] ) : '',
			'share'        => isset( $data['share'] ) ? sanitize_text_field( (string) $data['share'] ) : '',
			'shortcuts'    => isset( $data['shortcuts'] ) ? sanitize_text_field( (string) $data['shortcuts'] ) : '',
			'presentation' => isset( $data['presentation'] ) ? sanitize_text_field( (string) $data['presentation'] ) : '',
			'fullscreen'   => isset( $data['fullscreen'] ) ? sanitize_text_field( (string) $data['fullscreen'] ) : '',
		);

		update_post_meta( $post_id, self::META_KEY, $config );

		return $post_id;
	}

	/**
	 * Delete an embed.
	 *
	 * @param int $id Embed post ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Duplicate an embed.
	 *
	 * @param int $id Embed post ID.
	 * @return int|WP_Error New post ID or error.
	 */
	public static function duplicate( $id ) {
		$config = self::get( $id );
		if ( ! $config ) {
			return new WP_Error( 'not_found', __( 'Embed not found.', 'foliora' ) );
		}

		$data = $config;
		// translators: %s: original embed title/name.
		$data['name'] = sprintf( __( '%s (Copy)', 'foliora' ), $config['name'] );

		return self::save( $data, 0 );
	}

	/**
	 * AJAX handler: Save embed.
	 */
	public function ajax_save_embed() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'foliora' ) ) );
		}

		$embed_id = isset( $_POST['embed_id'] ) ? absint( $_POST['embed_id'] ) : 0;
		$file     = isset( $_POST['file'] ) ? esc_url_raw( wp_unslash( $_POST['file'] ) ) : '';

		if ( empty( $file ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select a PDF file.', 'foliora' ) ) );
		}

		$data = array(
			'name'         => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'file'         => $file,
			'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'width'        => isset( $_POST['width'] ) ? sanitize_text_field( wp_unslash( $_POST['width'] ) ) : '100%',
			'height'       => isset( $_POST['height'] ) ? sanitize_text_field( wp_unslash( $_POST['height'] ) ) : '520px',
			'theme'        => isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : 'light',
			'page'         => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
			'view'         => isset( $_POST['view'] ) ? sanitize_text_field( wp_unslash( $_POST['view'] ) ) : 'page',
			'hide'         => isset( $_POST['hide'] ) ? sanitize_text_field( wp_unslash( $_POST['hide'] ) ) : '',
			'loading'      => isset( $_POST['loading'] ) ? sanitize_text_field( wp_unslash( $_POST['loading'] ) ) : 'eager',
			'hash'         => isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : 'true',
			'resume'       => isset( $_POST['resume'] ) ? sanitize_text_field( wp_unslash( $_POST['resume'] ) ) : 'true',
			'download'     => isset( $_POST['download'] ) ? sanitize_text_field( wp_unslash( $_POST['download'] ) ) : '',
			'print'        => isset( $_POST['print'] ) ? sanitize_text_field( wp_unslash( $_POST['print'] ) ) : '',
			'search'       => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'theme_btn'    => isset( $_POST['theme_btn'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_btn'] ) ) : '',
			'share'        => isset( $_POST['share'] ) ? sanitize_text_field( wp_unslash( $_POST['share'] ) ) : '',
			'shortcuts'    => isset( $_POST['shortcuts'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcuts'] ) ) : '',
			'presentation' => isset( $_POST['presentation'] ) ? sanitize_text_field( wp_unslash( $_POST['presentation'] ) ) : '',
			'fullscreen'   => isset( $_POST['fullscreen'] ) ? sanitize_text_field( wp_unslash( $_POST['fullscreen'] ) ) : '',
		);

		$saved_id = self::save( $data, $embed_id );
		if ( is_wp_error( $saved_id ) ) {
			wp_send_json_error( array( 'message' => $saved_id->get_error_message() ) );
		}

		$shortcode   = sprintf( '[foliora id="%d"]', $saved_id );
		$php_snippet = sprintf( '<?php echo do_shortcode( \'[foliora id="%d"]\' ); ?>', $saved_id );
		$block_code  = sprintf( '<!-- wp:foliora/viewer {"id":%d} /-->', $saved_id );

		wp_send_json_success(
			array(
				'embed_id'    => $saved_id,
				'name'        => $data['name'],
				'shortcode'   => $shortcode,
				'php_snippet' => $php_snippet,
				'block_code'  => $block_code,
				'message'     => $embed_id > 0 ? __( 'Embed updated successfully!', 'foliora' ) : __( 'Embed saved successfully!', 'foliora' ),
			)
		);
	}

	/**
	 * AJAX handler: Delete embed.
	 */
	public function ajax_delete_embed() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'foliora' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! self::delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete embed.', 'foliora' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Embed deleted.', 'foliora' ) ) );
	}

	/**
	 * AJAX handler: Duplicate embed.
	 */
	public function ajax_duplicate_embed() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'foliora' ) ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$new_id = self::duplicate( $id );
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => $new_id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'new_id'  => $new_id,
				'message' => __( 'Embed duplicated successfully.', 'foliora' ),
			)
		);
	}

	/**
	 * AJAX handler: Get all embeds.
	 */
	public function ajax_get_embeds() {
		check_ajax_referer( 'foliora_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'foliora' ) ) );
		}

		$embeds = self::get_all();
		wp_send_json_success( array( 'embeds' => $embeds ) );
	}
}

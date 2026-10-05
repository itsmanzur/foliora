<?php
/**
 * Plugin Name:       Foliora – PDF Viewer, 3D Flipbook & Document Library
 * Plugin URI:         https://thereadscope.com/foliora
 * Description:        Fast, accessible PDF viewer with 3D flipbook, document library and PDF text search. Gutenberg, Elementor, Divi & Beaver Builder ready. Clean upgrade path to Foliora Pro for EPUB, bookmarks, reading progress, and protected links.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:             The Read Scope
 * Author URI:         https://thereadscope.com
 * License:             GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        foliora
 * Domain Path:        /languages
 *
 * @package Foliora
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------
 * Core constants
 * ---------------------------------------------------------------------
 * Every other file in the plugin should reference these rather than
 * hard-coding paths/URLs, so the free/pro split and any future folder
 * reshuffle stay painless.
 */
define( 'FOLIORA_VERSION', '1.0.0' );
define( 'FOLIORA_FILE', __FILE__ );
define( 'FOLIORA_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOLIORA_URL', plugin_dir_url( __FILE__ ) );
define( 'FOLIORA_BASENAME', plugin_basename( __FILE__ ) );

/*
 * ---------------------------------------------------------------------
 * Freemius SDK
 * ---------------------------------------------------------------------
 * Swap in your real Freemius plugin ID + public key once the product is
 * created in the Freemius dashboard. Left commented so the plugin still
 * runs standalone during development; uncomment before shipping.
 */
// if ( ! function_exists( 'foliora_fs' ) ) {
// 	function foliora_fs() {
// 		global $foliora_fs;
//
// 		if ( ! isset( $foliora_fs ) ) {
// 			require_once FOLIORA_DIR . 'freemius/start.php';
//
// 			$foliora_fs = fs_dynamic_init(
// 				array(
// 					'id'                  => 'XXXXX',
// 					'slug'                => 'foliora',
// 					'type'                => 'plugin',
// 					'public_key'          => 'pk_XXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
// 					'is_premium'          => false,
// 					'has_addons'          => true,
// 					'has_paid_plans'      => true,
// 					'menu'                => array(
// 						'slug'    => 'foliora-settings',
// 						'support' => false,
// 					),
// 				)
// 			);
// 		}
//
// 		return $foliora_fs;
// 	}
//
// 	foliora_fs();
// 	do_action( 'foliora_fs_loaded' );
// }

/*
 * ---------------------------------------------------------------------
 * Dependencies
 * ---------------------------------------------------------------------
 */
require_once FOLIORA_DIR . 'includes/trait-foliora-att-flag.php';
require_once FOLIORA_DIR . 'includes/class-foliora-loader.php';
require_once FOLIORA_DIR . 'includes/class-foliora-viewer.php';
require_once FOLIORA_DIR . 'includes/class-foliora-thumbnails.php';
require_once FOLIORA_DIR . 'includes/class-foliora-seo.php';
require_once FOLIORA_DIR . 'includes/class-foliora-a11y.php';
require_once FOLIORA_DIR . 'includes/class-foliora-compat.php';
require_once FOLIORA_DIR . 'includes/class-foliora-builders.php';
require_once FOLIORA_DIR . 'includes/class-foliora-library.php';
require_once FOLIORA_DIR . 'includes/class-foliora-block.php';
require_once FOLIORA_DIR . 'includes/class-foliora-embeds.php';
require_once FOLIORA_DIR . 'includes/class-foliora-autoembed.php';
require_once FOLIORA_DIR . 'includes/class-foliora-admin.php';
require_once FOLIORA_DIR . 'includes/class-foliora.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FOLIORA_DIR . 'includes/class-foliora-cli.php';
}

/**
 * Activation hook: create default options, flush rewrite rules if we ever
 * add custom endpoints, etc.
 */
function foliora_activate() {
	if ( false === get_option( 'foliora_settings' ) ) {
		add_option(
			'foliora_settings',
			array(
				'default_width'          => '100%',
				'default_height'         => '600px',
				'autoembed_url'          => true,
				'embed_attachment_pages' => true,
			)
		);
	}
	set_transient( 'foliora_activation_redirect', 1, 60 );
}
register_activation_hook( FOLIORA_FILE, 'foliora_activate' );

/**
 * One-time redirect to the dashboard after activation so the welcome
 * checklist is the first thing a new site owner sees.
 */
function foliora_maybe_activation_redirect() {
	if ( ! get_transient( 'foliora_activation_redirect' ) ) {
		return;
	}
	delete_transient( 'foliora_activation_redirect' );

	if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core bulk-activation flag; value is not read.
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'admin.php?page=foliora-settings' ) );
	exit;
}
add_action( 'admin_init', 'foliora_maybe_activation_redirect' );

/**
 * Deactivation hook: intentionally left free of destructive cleanup.
 * Data removal belongs in uninstall.php, gated behind an explicit user
 * confirmation, never in deactivation.
 */
function foliora_deactivate() {
	// Nothing to do yet.
}
register_deactivation_hook( FOLIORA_FILE, 'foliora_deactivate' );

/**
 * Point just-in-time translation loading at bundled /languages files
 * when running on WP 6.7+. For WP 4.6+, translations are automatically
 * loaded by WordPress core from the 'Domain Path: /languages' header.
 */
function foliora_register_textdomain_path() {
	if ( isset( $GLOBALS['wp_textdomain_registry'] ) && is_object( $GLOBALS['wp_textdomain_registry'] )
		&& method_exists( $GLOBALS['wp_textdomain_registry'], 'set_custom_path' ) ) {
		$GLOBALS['wp_textdomain_registry']->set_custom_path( 'foliora', FOLIORA_DIR . 'languages' );
	}
}
add_action( 'plugins_loaded', 'foliora_register_textdomain_path', 1 );

/**
 * Boot the plugin once all plugins are loaded, so Foliora Pro (if active)
 * has had a chance to register itself before we check for it.
 */
function foliora_run() {
	Foliora::instance()->run();
}
add_action( 'plugins_loaded', 'foliora_run', 20 );

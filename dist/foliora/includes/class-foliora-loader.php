<?php
/**
 * Foliora_Loader
 *
 * This is the single source of truth for "is Pro active?" anywhere in the
 * free plugin. Nothing else should check for the Pro plugin directly —
 * always go through Foliora_Loader::is_pro_active() so the detection
 * logic only lives in one place.
 *
 * Contract with Foliora Pro (separate plugin/addon):
 *   1. Foliora Pro fires `do_action( 'foliora/pro_loaded' )` on its own
 *      `plugins_loaded` (priority 5, i.e. before Foliora free boots at 20).
 *   2. Foliora Pro also defines the constant FOLIORA_PRO_VERSION.
 *   3. Foliora free never ships any Pro *code* — only locked UI. Actual
 *      EPUB rendering, bookmarks, reading-progress, gating, etc. live
 *      entirely inside the Pro plugin and hook into filters/actions that
 *      Foliora free exposes (see class-foliora-viewer.php).
 *
 * @package Foliora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Foliora_Loader {

	/**
	 * Cached result so we don't re-run the check on every call.
	 *
	 * @var bool|null
	 */
	private static $is_pro_active = null;

	/**
	 * Whether Foliora Pro is installed, active, and has confirmed itself
	 * loaded via the `foliora/pro_loaded` action.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		if ( null !== self::$is_pro_active ) {
			return self::$is_pro_active;
		}

		$pro_confirmed = defined( 'FOLIORA_PRO_VERSION' ) && did_action( 'foliora/pro_loaded' );

		/**
		 * Filter: foliora/is_pro_active
		 *
		 * Lets Foliora Pro (or a site owner, for testing) override the
		 * detection result explicitly.
		 *
		 * @param bool $pro_confirmed
		 */
		self::$is_pro_active = (bool) apply_filters( 'foliora/is_pro_active', $pro_confirmed );

		return self::$is_pro_active;
	}

	/**
	 * Convenience helper for admin/UI code: returns the URL to send
	 * people to when they click an "Upgrade to Pro" lock.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		return apply_filters( 'foliora/upgrade_url', 'https://thereadscope.com/foliora/pricing' );
	}

	/**
	 * Central list of Pro-only feature flags. Keeping this in one array
	 * means the admin locked-feature UI and the viewer's capability
	 * checks never drift out of sync with each other.
	 *
	 * @return array<string,string> feature_key => human label
	 */
	public static function pro_features() {
		return array(
			'epub_reader'       => __( 'EPUB reader mode', 'foliora' ),
			'flipbook_3d'       => __( '3D WebGL flip effect', 'foliora' ),
			'bookmarks'         => __( 'Bookmarks & reading progress', 'foliora' ),
			'reader_themes'     => __( 'Font & theme customization', 'foliora' ),
			'protected_links'   => __( 'Password-protected / expiring links', 'foliora' ),
			'woocommerce_gate'  => __( 'WooCommerce paid-content gating', 'foliora' ),
			'analytics'         => __( 'Reading analytics', 'foliora' ),
			'remove_branding'   => __( 'Remove Foliora branding', 'foliora' ),
		);
	}

	/**
	 * Whether a single named Pro feature is unlocked on this site.
	 * Foliora Pro should hook `foliora/feature_enabled` and return true
	 * for whichever features the user's license tier includes; until
	 * then, every feature is locked once Pro is merely installed.
	 *
	 * @param string $feature_key One of the keys from pro_features().
	 * @return bool
	 */
	public static function is_feature_enabled( $feature_key ) {
		if ( ! self::is_pro_active() ) {
			return false;
		}

		return (bool) apply_filters( 'foliora/feature_enabled', true, $feature_key );
	}
}

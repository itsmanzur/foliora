<?php
/**
 * Smoke-check Documents a11y column as user 1. Run via WP-CLI eval-file.
 */
wp_set_current_user( 1 );
if ( ! class_exists( 'Foliora' ) ) {
	echo "NO_FOLIORA\n";
	exit( 1 );
}

ob_start();
Foliora::instance()->admin->render_documents_page();
$html = ob_get_clean();

echo ( false !== strpos( $html, 'foliora-a11y-status' ) ? 'HAS_A11Y' : 'NO_A11Y' ) . PHP_EOL;
echo ( false !== strpos( $html, 'Accessibility' ) ? 'HAS_COL' : 'NO_COL' ) . PHP_EOL;
echo ( false !== strpos( $html, 'data-needs-a11y' ) ? 'HAS_NEEDS' : 'NO_NEEDS' ) . PHP_EOL;

unload_textdomain( 'foliora' );
load_textdomain( 'foliora', WP_PLUGIN_DIR . '/foliora/languages/foliora-bn_BD.mo' );
echo 'BN_DOCUMENTS=' . __( 'Documents', 'foliora' ) . PHP_EOL;
echo 'BN_UNTAGGED=' . __( 'Untagged', 'foliora' ) . PHP_EOL;

<?php
/**
 * Template: User Dashboard.
 *
 * Displays My Media, My Albums, and My Favorites tabs.
 * Uses Interactivity API — all CRUD via mvs/dashboard store.
 * Override by copying to your-theme/wpmediaverse/dashboard.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	get_header();
	echo '<div class="mvs-dashboard"><p>';
	esc_html_e( 'Please log in to access your media dashboard.', 'wpmediaverse' );
	echo '</p></div>';
	get_footer();
	return;
}

get_header();

do_action( 'mvs_before_content' );

$mvs_dash_ctx = array(
	'restUrl'  => esc_url_raw( rest_url( 'mvs/v1/' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'userId'   => get_current_user_id(),
	'mediaUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
);

require MVS_PLUGIN_DIR . 'templates/partials/dashboard-content.php';

wp_enqueue_style( 'mvs-frontend' );

// Enqueue Interactivity API stores.
$mvs_shared_asset_file = MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php';
$mvs_shared_asset      = file_exists( $mvs_shared_asset_file ) ? require $mvs_shared_asset_file : array(
	'dependencies' => array(),
	'version'      => MVS_VERSION,
);
wp_enqueue_script_module(
	'mvs-shared-ui',
	MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
	$mvs_shared_asset['dependencies'],
	$mvs_shared_asset['version']
);

$mvs_dash_asset_file = MVS_PLUGIN_DIR . 'build/blocks/dashboard-view/view.asset.php';
$mvs_dash_asset      = file_exists( $mvs_dash_asset_file ) ? require $mvs_dash_asset_file : array(
	'dependencies' => array(),
	'version'      => MVS_VERSION,
);
wp_enqueue_script_module(
	'mvs-dashboard-view',
	MVS_PLUGIN_URL . 'build/blocks/dashboard-view/view.js',
	$mvs_dash_asset['dependencies'],
	$mvs_dash_asset['version']
);

do_action( 'mvs_after_content' );

get_footer();

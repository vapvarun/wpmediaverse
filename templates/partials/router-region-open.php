<?php
/**
 * Router region open — wraps the entire MVS app surface.
 *
 * Seeds the `mvs` Interactivity store with server-controlled values so the
 * JS navigate action can read them synchronously without a REST round-trip.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

wp_interactivity_state(
	'mvs',
	array(
		'clientNav' => (bool) apply_filters( 'wpmediaverse_client_nav_enabled', true ),
		'denyPaths' => (array) apply_filters( 'wpmediaverse_client_nav_deny_paths', array() ),
	)
);
?>
<div id="mvs-app" data-wp-interactive="mvs" data-wp-router-region="mvs/main" data-wp-on--click="actions.navigate">

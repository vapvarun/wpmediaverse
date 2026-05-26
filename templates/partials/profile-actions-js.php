<?php
/**
 * Partial: Inline JS for Follow + Message buttons on profile pages.
 *
 * Expects in scope:
 *   $mvs_profile_id     (int)
 *   $mvs_is_own_profile (bool)
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $mvs_profile_id ) || $mvs_is_own_profile || ! is_user_logged_in() ) {
	return;
}

// Follow toggle + Message button behavior now lives in an enqueued script
// (assets/js/frontend/profile-actions.js, handle mvs-profile-actions). The
// follow button carries its own data-* attributes; labels are localized via
// wp_localize_script. This partial just enqueues it.
wp_enqueue_script( 'mvs-profile-actions' );

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

// Follow toggle + Message button behavior lives in an enqueued script
// (assets/js/frontend/profile-actions.js, handle mvs-profile-actions). The
// follow button carries its own data-* attributes; labels are localized via
// wp_localize_script.
//
// @deprecated 2.3.0 No longer the enqueue site. Core\Plugin::enqueue_frontend_assets()
// enqueues mvs-profile-actions for every MVS-owned page instead. Enqueuing from
// here only worked on a hard page load: the <script> tag prints in wp_footer,
// OUTSIDE [data-wp-router-region="mvs/main"], so a client-side navigation into a
// profile swapped in the buttons without ever delivering the script — the
// Follow/Message/overflow controls were inert until the user refreshed
// (Basecamp #10148246386).
//
// Kept as a no-op (wp_enqueue_script is idempotent) because themes may override
// or include this partial — see Production Rule #5. Do not delete before 4.0.0.
wp_enqueue_script( 'mvs-profile-actions' );

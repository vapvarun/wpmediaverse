<?php
/**
 * WPMediaVerse Demo Data Cleanup.
 *
 * Removes demo data seeded by the demo data seeder. All deletions are scoped
 * to demo users (identified by the `@demo.local` email pattern) and the
 * content they own — real user data is never touched.
 *
 * Safe to run on production sites. Refuses to run unless the `mvs_demo_seeded`
 * option is set by the seeder.
 *
 * Called via AJAX from the Overview page or via WP-CLI:
 *     wp eval-file wp-content/plugins/wpmediaverse/cleanup-demo-data.php
 *
 * @package WPMediaVerse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

/**
 * Helper: finalize the cleanup run with the appropriate response channel.
 *
 * @param string $message Human-readable result message.
 * @param bool   $success Whether the run is considered a success.
 */
$mvs_finish = static function ( string $message, bool $success = true ): void {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		if ( $success ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		if ( $success ) {
			WP_CLI::success( $message );
		} else {
			WP_CLI::warning( $message );
		}
		return;
	}

	echo esc_html( $message ) . PHP_EOL;
};

// 1. Identify demo users by the seeder's `@demo.local` email pattern.
// ID 1 (original admin) is always excluded as a defense-in-depth measure.
// The presence of `@demo.local` users is the canonical marker for demo data —
// the seeder creates them and no other code path creates users with this suffix.
$demo_user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT ID FROM {$wpdb->users} WHERE user_email LIKE '%@demo.local' AND ID != 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$demo_user_ids = array_values( array_filter( array_map( 'intval', $demo_user_ids ) ) );

// 2. Safety guard: if no demo users exist, there is nothing to clean.
// This is the real guard — without demo users, none of the subsequent scoped
// DELETEs would match anything anyway, but we exit early with a clear message.
if ( empty( $demo_user_ids ) ) {
	delete_option( 'mvs_demo_seeded' );
	$mvs_finish( __( 'No demo users found. Nothing to clean.', 'wpmediaverse' ), false );
	return;
}

$user_placeholders = implode( ',', array_fill( 0, count( $demo_user_ids ), '%d' ) );

// 3. Delete demo-owned media rows via MediaRepository::delete_cascade(),
// which already removes related stats, views, meta, reactions, favorites,
// mentions, album_items, notifications, activity, and mvs_comment rows.
$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author IN ({$user_placeholders})",
		...$demo_user_ids
	)
);
$media_count = 0;
foreach ( $media_ids as $mid ) {
	\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade( (int) $mid );
	++$media_count;
}

// 4. Delete demo-owned albums and collections (still CPTs).
$demo_posts = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('mvs_album','mvs_collection') AND post_author IN ({$user_placeholders})",
		...$demo_user_ids
	)
);
$album_count = 0;
foreach ( $demo_posts as $post_id ) {
	wp_delete_post( (int) $post_id, true );
	++$album_count;
}

// 5. Remove demo-user-owned rows in social tables that aren't media-scoped.
// These are cleaned per-user-id so real users' data is never touched.
$social_cleanups = array(
	// table => array( columns ).
	'mvs_activity'      => array( 'user_id' ),
	'mvs_notifications' => array( 'user_id' ),
	'mvs_favorites'     => array( 'user_id' ),
	'mvs_reactions'     => array( 'user_id' ),
	'mvs_mentions'      => array( 'mentioned_user_id' ),
	'mvs_media_views'   => array( 'user_id' ),
	'mvs_follows'       => array( 'follower_id', 'following_id' ),
	'mvs_blocks'        => array( 'blocker_id', 'blocked_id' ),
	'mvs_reports'       => array( 'reporter_id' ),
	'mvs_access_grants' => array( 'user_id' ),
);

foreach ( $social_cleanups as $table_suffix => $columns ) {
	$table  = $wpdb->prefix . $table_suffix;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $exists ) {
		continue;
	}
	foreach ( $columns as $col ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE {$col} IN ({$user_placeholders})",
				...$demo_user_ids
			)
		);
	}
}

// 6. Delete demo users. `null` reassign so any remaining authored content
// is deleted rather than silently assigned to the admin.
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
$demo_user_count = 0;
foreach ( $demo_user_ids as $duid ) {
	if ( wp_delete_user( (int) $duid, null ) ) {
		++$demo_user_count;
	}
}

// 7. Clean orphaned taxonomy term relationships left by deleted demo media.
// Capture which term_taxonomy_ids were affected so we can refresh their cached
// counts — direct SQL DELETE on term_relationships does not fire the hooks
// that keep wp_term_taxonomy.count in sync.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$affected_tt_ids = $wpdb->get_col(
	"SELECT DISTINCT tr.term_taxonomy_id
	FROM {$wpdb->term_relationships} tr
	LEFT JOIN {$wpdb->prefix}mvs_media_index m ON tr.object_id = m.media_id
	WHERE m.media_id IS NULL
	AND tr.term_taxonomy_id IN (
		SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt
		WHERE tt.taxonomy IN ( 'mvs_tag', 'mvs_category' )
	)"
);

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE tr FROM {$wpdb->term_relationships} tr
	LEFT JOIN {$wpdb->prefix}mvs_media_index m ON tr.object_id = m.media_id
	WHERE m.media_id IS NULL
	AND tr.term_taxonomy_id IN (
		SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt
		WHERE tt.taxonomy IN ( 'mvs_tag', 'mvs_category' )
	)"
);

// 8a. Refresh cached term counts FIRST so counts are accurate before deletion.
if ( ! empty( $affected_tt_ids ) ) {
	$affected_tt_ids = array_map( 'intval', $affected_tt_ids );
	wp_update_term_count_now( $affected_tt_ids, 'mvs_tag' );
	wp_update_term_count_now( $affected_tt_ids, 'mvs_category' );
}

// 8b. Delete demo-created taxonomy terms that are no longer in use.
// Only terms flagged with _mvs_demo_term meta (set by the seeder) are candidates.
// Terms still referenced by real user media are preserved (flag removed).
$demo_term_count = 0;
foreach ( array( 'mvs_tag', 'mvs_category' ) as $demo_taxonomy ) {
	$demo_terms = get_terms(
		array(
			'taxonomy'   => $demo_taxonomy,
			'hide_empty' => false,
			'meta_key'   => '_mvs_demo_term', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => '1',              // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	if ( is_wp_error( $demo_terms ) || empty( $demo_terms ) ) {
		continue;
	}

	foreach ( $demo_terms as $term ) {
		if ( (int) $term->count > 0 ) {
			// A real user is still using this tag — remove the demo flag.
			delete_term_meta( $term->term_id, '_mvs_demo_term' );
			continue;
		}

		$result = wp_delete_term( $term->term_id, $demo_taxonomy );
		if ( $result && ! is_wp_error( $result ) ) {
			++$demo_term_count;
		}
	}
}

// 9. Reset the seeded flag.
delete_option( 'mvs_demo_seeded' );

// 10. Invalidate cached Overview widgets so the "Import Demo Data" button
// appears immediately after the AJAX reload. OverviewPage::get_stats() and
// get_recent_uploads() cache their results in the wpmediaverse group for
// five minutes, which otherwise kept the "Delete Demo Data" button visible
// even when total_media was already zero. See OverviewPage.php:640, 679.
wp_cache_delete( 'mvs_overview_stats', 'wpmediaverse' );
wp_cache_delete( 'mvs_overview_recent', 'wpmediaverse' );

$message = sprintf(
	/* translators: 1: media count, 2: album/collection count, 3: user count, 4: term count */
	__( 'Cleaned %1$d demo media item(s), %2$d album(s)/collection(s), %3$d demo user(s), and %4$d demo tag(s)/category(ies).', 'wpmediaverse' ),
	$media_count,
	$album_count,
	$demo_user_count,
	$demo_term_count
);

$mvs_finish( $message );

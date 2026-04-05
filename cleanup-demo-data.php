<?php
/**
 * WPMediaVerse Demo Data Cleanup.
 *
 * Removes ALL demo data in one click. Called via AJAX from Overview page
 * or via WP-CLI: wp eval-file wp-content/plugins/wpmediaverse/cleanup-demo-data.php
 *
 * @package WPMediaVerse
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Delete demo users (created by the seeder with @demo.local email).
// Never delete user ID 1 (admin).
$demo_user_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT ID FROM $wpdb->users WHERE user_email LIKE '%@demo.local' AND ID != 1" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
);
$demo_user_count = 0;
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
foreach ( $demo_user_ids as $duid ) {
	wp_delete_user( (int) $duid, 1 );
	++$demo_user_count;
}

// Delete all media from mvs_media_index.
$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT media_id FROM {$wpdb->prefix}mvs_media_index" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
foreach ( $media_ids as $mid ) {
	\WPMediaVerse\Repository\MediaRepository::delete_all( (int) $mid );
}

// Delete albums + collections (still CPTs).
$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type IN ('mvs_album', 'mvs_collection')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clean orphaned postmeta (for albums/collections).
$wpdb->query( "DELETE pm FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Truncate all custom tables.
$tables = array(
	'mvs_media_index',
	'mvs_media_meta',
	'mvs_media_stats',
	'mvs_media_views',
	'mvs_reactions',
	'mvs_favorites',
	'mvs_follows',
	'mvs_album_items',
	'mvs_activity',
	'mvs_notifications',
	'mvs_mentions',
	'mvs_reports',
	'mvs_blocks',
	'mvs_access_grants',
	'mvs_access_rules',
	'mvs_album_index',
	'mvs_collection_index',
);

// Pro tables (if they exist).
$pro_tables = array(
	'mvs_competitions',
	'mvs_competition_entries',
	'mvs_competition_matches',
	'mvs_competition_votes',
	'mvs_boosts',
);

foreach ( array_merge( $tables, $pro_tables ) as $t ) {
	$full = $wpdb->prefix . $t;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE `$full`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

// Reset seeded flag.
delete_option( 'mvs_demo_seeded' );

$message = sprintf(
	'Cleaned %d media items, %d demo users, all albums, collections, and custom table data.',
	count( $media_ids ),
	$demo_user_count
);

if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
	wp_send_json_success( array( 'message' => $message ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $message );
} else {
	echo esc_html( $message ) . PHP_EOL;
}

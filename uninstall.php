<?php
/**
 * Uninstall WPMediaVerse.
 *
 * Drops all custom tables, deletes options, and removes post meta.
 *
 * @package WPMediaVerse
 */

// Prevent direct access — uninstall.php must only be loaded by WP core during plugin uninstall.
defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove custom tables.
//
// FROM THE MIGRATOR, not a second list. This file kept its own copy and had
// drifted to 10 of the 22 tables the plugin creates: uninstalling left the whole
// messaging stack behind — conversations, messages, participants, reactions —
// along with notifications, follows, blocks, activity, reports and transactions,
// rows and all. A member who deleted the plugin to remove their data did not.
//
// `Migrator::tables()` is now the one place that knows, and
// `UninstallCoverageTest` fails if a table is created without being listed
// there. Autoload is required explicitly because uninstall.php runs standalone,
// outside the plugin's own bootstrap.
$mvs_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $mvs_autoload ) ) {
	require_once $mvs_autoload;
}

$mvs_tables = class_exists( '\WPMediaVerse\Core\Migrator' )
	? \WPMediaVerse\Core\Migrator::tables()
	// Autoload missing (a broken install being cleaned up) is the one case
	// where a copy is better than nothing: the tables with member data in them.
	// Deliberately short, and deliberately not maintained — the list above is.
	: array( 'mvs_media_index', 'mvs_media_meta', 'mvs_messages', 'mvs_conversations' );

foreach ( $mvs_tables as $mvs_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$mvs_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

// Delete all mvs_ options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all _mvs_ post meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all mvs_ CPT posts (album, collection — media lives in custom tables dropped above).
$mvs_post_types = array( 'mvs_album', 'mvs_collection' );
foreach ( $mvs_post_types as $mvs_post_type ) {
	$mvs_posts = get_posts(
		array(
			'post_type'   => $mvs_post_type,
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		)
	);
	foreach ( $mvs_posts as $mvs_post_id ) {
		wp_delete_post( $mvs_post_id, true );
	}
}

// Remove capabilities from roles.
$mvs_caps = array(
	'upload_mvs_media',
	'edit_mvs_media',
	'edit_others_mvs_media',
	'delete_mvs_media',
	'delete_others_mvs_media',
	'moderate_mvs_media',
	'manage_mvs_settings',
	'read_mvs_media',
	'publish_mvs_media',
);

$mvs_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
foreach ( $mvs_roles as $mvs_role_name ) {
	$mvs_role = get_role( $mvs_role_name );
	if ( $mvs_role ) {
		foreach ( $mvs_caps as $mvs_cap ) {
			$mvs_role->remove_cap( $mvs_cap );
		}
	}
}

// Delete transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

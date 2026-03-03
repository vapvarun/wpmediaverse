<?php
/**
 * Uninstall WPMediaVerse.
 *
 * Drops all custom tables, deletes options, and removes post meta.
 *
 * @package WPMediaVerse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove custom tables.
$mvs_tables = array(
	'mvs_reactions',
	'mvs_favorites',
	'mvs_media_views',
	'mvs_media_stats',
	'mvs_access_rules',
	'mvs_access_grants',
	'mvs_mentions',
	'mvs_album_items',
	'mvs_media_index',
);

foreach ( $mvs_tables as $mvs_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$mvs_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

// Delete all mvs_ options.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all _mvs_ post meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all mvs_ CPT posts (media, album, collection).
$mvs_post_types = array( 'mvs_media', 'mvs_album', 'mvs_collection' );
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

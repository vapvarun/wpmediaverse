<?php
/**
 * Uninstall WPMediaVerse.
 *
 * KEEPS MEMBER DATA unless the owner opted in (Settings > General >
 * "Remove Data on Delete"). Premium plugins are routinely updated by deleting
 * and re-uploading them; before 2.6.0 that path dropped every table - media,
 * albums, messages - with no question asked.
 *
 * Always: scheduled work is cleared and caches (transients) are dropped.
 * With the opt-in: tables, options, post meta, albums/collections, user meta
 * and capabilities go too. Uploaded files and the pages MediaVerse created are
 * never touched here - the setting says so.
 *
 * @package WPMediaVerse
 */

// Prevent direct access — uninstall.php must only be loaded by WP core during plugin uninstall.
defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The one list of scheduled work (see Deactivator), required directly for the
// same reason the Migrator is below: uninstall runs outside the bootstrap.
$mvs_deactivator = __DIR__ . '/includes/Core/Deactivator.php';
if ( is_readable( $mvs_deactivator ) ) {
	require_once $mvs_deactivator;
	\WPMediaVerse\Core\Deactivator::clear_scheduled( true );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Spelled out rather than read from GeneralSettingsRegistrar::DELETE_DATA_OPTION:
// that class needs the Settings API loaded, and this file runs standalone.
if ( '1' !== (string) get_option( 'mvs_delete_data_on_uninstall', '' ) ) {
	return;
}

// Pro reads the same opt-in when it is deleted. If Pro is still installed, the
// option has to outlive this uninstall, or deleting Free first would quietly
// turn Pro's delete into a keep.
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$mvs_pro_installed = false;
foreach ( get_plugins() as $mvs_plugin_data ) {
	if ( 'wpmediaverse-pro' === ( $mvs_plugin_data['TextDomain'] ?? '' ) ) {
		$mvs_pro_installed = true;
		break;
	}
}

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
// there.
//
// The migrator's file is required DIRECTLY rather than through an autoloader,
// because uninstall.php runs standalone — WordPress loads this file on its own,
// outside the plugin's bootstrap, so nothing has registered an autoloader by the
// time it runs. It used to require `vendor/autoload.php`; that stopped being
// the answer when the runtime dependencies moved to `libs/` and `vendor/` became
// dev-only and absent from the release zip. A one-line require of the one class
// this file needs has no such failure mode.
$mvs_migrator = __DIR__ . '/includes/Core/Migrator.php';

if ( is_readable( $mvs_migrator ) ) {
	require_once $mvs_migrator;
}

$mvs_tables = class_exists( '\WPMediaVerse\Core\Migrator' )
	? array_merge( \WPMediaVerse\Core\Migrator::tables(), \WPMediaVerse\Core\Migrator::RETIRED_TABLES )
	// The migrator's own file missing (a broken install being cleaned up) is the
	// one case where a copy is better than nothing: the tables with member data
	// in them. Deliberately short, and deliberately not maintained — the list
	// above is.
	: array( 'mvs_media_index', 'mvs_media_meta', 'mvs_messages', 'mvs_conversations' );

foreach ( $mvs_tables as $mvs_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$mvs_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

// Delete all mvs_ options (the opt-in survives while Pro is installed, see above).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like( 'mvs_' ) . '%', $mvs_pro_installed ? 'mvs_delete_data_on_uninstall' : '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Per-member state: suspension, interests, privacy presets, counters, resume
// positions, connector credentials - every MediaVerse key uses one of these two
// prefixes. Deleted with the rest of the data the owner asked to remove.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( 'mvs_' ) . '%', $wpdb->esc_like( '_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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
//
// MediaCapabilities::remove_caps() is the one list. It already removes the full
// set - including manage_mvs_access and the ten plural caps - from EVERY role.
// This file used to carry its own list of nine caps against the five core roles,
// so eleven caps survived an uninstall-with-delete-data, and a custom role kept
// all of its grants. Two lists that have to agree are one list waiting to drift.
//
// Required directly rather than through an autoloader, for the same reason the
// Migrator is above: the release zip has no vendor/autoload.php.
$mvs_caps_file = __DIR__ . '/includes/Capabilities/MediaCapabilities.php';
if ( file_exists( $mvs_caps_file ) ) {
	require_once $mvs_caps_file;

	if ( method_exists( '\WPMediaVerse\Capabilities\MediaCapabilities', 'remove_caps' ) ) {
		\WPMediaVerse\Capabilities\MediaCapabilities::remove_caps();
	}
}

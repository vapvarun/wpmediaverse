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
// The Deactivator's job lists read class constants (PresetActivation::HOOK, ChatFiles::HOOK), and this file runs with
// the plugin inactive and its autoloader not registered: without one, deleting the plugin fatals here and the
// "remove data" option never runs. Same PSR-style loader as wpmediaverse.php, rooted at this folder.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPMediaVerse\\';
		if ( strncmp( $prefix, $class_name, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$file = __DIR__ . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

$mvs_deactivator = __DIR__ . '/includes/Core/Deactivator.php';
if ( is_readable( $mvs_deactivator ) ) {
	require_once $mvs_deactivator;
	\WPMediaVerse\Core\Deactivator::clear_scheduled( true );
}

// The licence SDK's weekly check is named after the plugin slug, not mvs_: it would keep firing into code that is gone.
// (Pro's own event, ...-pro, is left for Pro's uninstall.)
wp_clear_scheduled_hook( 'edd_sl_sdk_weekly_license_check_wpmediaverse' );

// Action Scheduler rows left by Free's jobs: finished and failed rows stay after a cancel. Action Scheduler belongs to
// whichever plugin bundles it, and Pro's jobs share the 'mvs' group and the mvs_ prefix, so rows are matched by Free's
// own hook list (the Deactivator's) and nothing else: a pending Pro job (a folder purge, a privacy cascade) survives.
$mvs_as_actions = $wpdb->prefix . 'actionscheduler_actions';
$mvs_as_logs    = $wpdb->prefix . 'actionscheduler_logs';
$mvs_as_hooks   = class_exists( '\WPMediaVerse\Core\Deactivator' ) ? array_merge( \WPMediaVerse\Core\Deactivator::CRON_HOOKS, \WPMediaVerse\Core\Deactivator::JOB_HOOKS ) : array();
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
if ( $mvs_as_hooks && $mvs_as_actions === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mvs_as_actions ) ) ) ) {
	$mvs_as_has_logs = $mvs_as_logs === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mvs_as_logs ) ) );
	$mvs_as_in_hooks = implode( ', ', array_fill( 0, count( $mvs_as_hooks ), '%s' ) );
	// In batches of 1,000, logs first, so a site with years of rows never runs one huge locking DELETE. A batch that
	// deletes nothing stops the loop, so a failing DELETE cannot spin.
	$mvs_as_batch = 1000;
	do {
		$mvs_as_ids     = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$mvs_as_actions} WHERE hook IN ( {$mvs_as_in_hooks} ) LIMIT %d", array_merge( $mvs_as_hooks, array( $mvs_as_batch ) ) ) ) );
		$mvs_as_deleted = 0;
		if ( $mvs_as_ids ) {
			$mvs_as_in = implode( ',', $mvs_as_ids );
			if ( $mvs_as_has_logs ) {
				$wpdb->query( "DELETE FROM {$mvs_as_logs} WHERE action_id IN ( {$mvs_as_in} )" );
			}
			$mvs_as_deleted = (int) $wpdb->query( "DELETE FROM {$mvs_as_actions} WHERE action_id IN ( {$mvs_as_in} )" );
		}
		$mvs_as_more = count( $mvs_as_ids ) === $mvs_as_batch && $mvs_as_deleted > 0;
	} while ( $mvs_as_more );
	// The group rows MediaVerse created, once no action (Pro's or another plugin's included) still uses them.
	$mvs_as_groups = $wpdb->prefix . 'actionscheduler_groups';
	if ( class_exists( '\WPMediaVerse\Core\Jobs' ) && $mvs_as_groups === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mvs_as_groups ) ) ) ) {
		$mvs_as_slugs = array_merge( array( \WPMediaVerse\Core\Jobs::GROUP ), \WPMediaVerse\Core\Jobs::LEGACY_GROUPS );
		$wpdb->query( $wpdb->prepare( "DELETE g FROM {$mvs_as_groups} g WHERE g.slug IN ( " . implode( ', ', array_fill( 0, count( $mvs_as_slugs ), '%s' ) ) . " ) AND NOT EXISTS ( SELECT 1 FROM {$mvs_as_actions} a WHERE a.group_id = g.group_id )", $mvs_as_slugs ) );
	}
}
// phpcs:enable

// Free owns the library's runner event (Pro uses Free's copy). It is scheduled with args ( 'WP Cron' ), so it is cleared
// for every args set, and only when no other active plugin still loads Action Scheduler (Free is inactive here, so a
// loaded ActionScheduler class is someone else's copy, which still needs its runner).
if ( ! class_exists( 'ActionScheduler' ) ) {
	wp_unschedule_hook( 'action_scheduler_run_queue' );
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

// Delete all mvs_ options. The opt-in itself goes LAST (end of this file): a wipe that is cut short (a web timeout on a
// big site) can then be run again, instead of being stranded half done with the option that asks for it already gone.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like( 'mvs_' ) . '%', 'mvs_delete_data_on_uninstall' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Options stored under the plugin slug rather than the mvs_ prefix: the
// licence key and its status, and the setup preset flag (QA, 2.6.0). The
// underscore is literal, so Pro's `wpmediaverse-pro_*` keys are left for
// Pro's own uninstall.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpmediaverse_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Media tags and categories. The taxonomies are not registered while this
// file runs on its own, so their terms are removed directly; a term another
// taxonomy still uses is kept.
$mvs_tt_rows = $wpdb->get_results( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( 'mvs_tag', 'mvs_category' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
if ( $mvs_tt_rows ) {
	$mvs_tt_ids   = implode( ',', array_map( 'intval', wp_list_pluck( $mvs_tt_rows, 'term_taxonomy_id' ) ) );
	$mvs_term_ids = implode( ',', array_map( 'intval', wp_list_pluck( $mvs_tt_rows, 'term_id' ) ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer lists built above.
	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( {$mvs_tt_ids} )" );
	$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ( {$mvs_tt_ids} )" );
	$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ( {$mvs_term_ids} ) AND term_id NOT IN ( SELECT term_id FROM {$wpdb->term_taxonomy} )" );
	$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ( {$mvs_term_ids} ) AND term_id NOT IN ( SELECT term_id FROM {$wpdb->term_taxonomy} )" );
	// phpcs:enable
}

// Per-member state: suspension, interests, privacy presets, counters, resume
// positions, connector credentials - every MediaVerse key uses one of these two
// prefixes. Deleted with the rest of the data the owner asked to remove.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( 'mvs_' ) . '%', $wpdb->esc_like( '_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all _mvs_ post meta.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mvs_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all mvs_ CPT posts (album, collection - media lives in custom tables dropped above). One wp_delete_post() per post
// took minutes at tens of thousands of albums; these types carry no revisions or attachments of their own, so the rows
// (and their meta and term links) are removed in set-based batches.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed post types, bound below.
$mvs_types_in = "'mvs_album', 'mvs_collection'";
do {
	$mvs_batch = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$mvs_types_in} ) LIMIT 2000" ) );
	if ( ! $mvs_batch ) {
		break;
	}
	$mvs_ids = implode( ',', $mvs_batch );
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( {$mvs_ids} )" );
	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ( {$mvs_ids} )" );
	$mvs_deleted = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ( {$mvs_ids} )" );
	$mvs_more    = count( $mvs_batch ) >= 2000 && $mvs_deleted > 0; // A batch that deletes nothing stops the loop.
} while ( $mvs_more );

// Media comments live in core's comments tables (type mvs_comment, with mvs_media_id in commentmeta): left behind, they
// reappear under whatever new media gets the same id after a reinstall.
$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_type = 'mvs_comment'" );
$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type = 'mvs_comment'" );
$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key = 'mvs_media_id'" );
// phpcs:enable

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

// Last of all: the opt-in. Pro reads the same option when it is deleted, so it stays while Pro is installed (Pro's
// delete follows the owner's choice, not a silent "keep").
if ( ! $mvs_pro_installed ) {
	delete_option( 'mvs_delete_data_on_uninstall' );
}

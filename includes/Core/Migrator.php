<?php
/**
 * Database migrator.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Version-based database migrator.
 */
class Migrator {

	const CURRENT_VERSION = 30;

	/**
	 * Option recording how far the v29 drive backfill has progressed.
	 *
	 * The backfill touches every row in the media index, so it runs in bounded
	 * batches across requests rather than in one ALTER-sized transaction. This
	 * holds the highest media_id already stamped; absent means "not started",
	 * and the sentinel below means "finished".
	 *
	 * @since 2.4.0
	 * @var string
	 */
	const DRIVE_BACKFILL_OPTION = 'mvs_drive_backfill_cursor';

	/**
	 * Cron hook that advances the v29 drive backfill.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	const DRIVE_BACKFILL_HOOK = 'mvs_backfill_drive_columns';

	/**
	 * Rows stamped per backfill batch.
	 *
	 * @since 2.4.0
	 * @var int
	 */
	const DRIVE_BACKFILL_BATCH = 2000;
	const VERSION_OPTION       = 'mvs_db_version';

	/**
	 * Every table this plugin creates, without the prefix.
	 *
	 * THE SINGLE SOURCE for "what belongs to WPMediaVerse". `uninstall.php` kept
	 * its own hardcoded copy and had drifted to 10 of 22: uninstalling left
	 * the whole messaging stack behind — conversations, messages, participants,
	 * reactions — plus notifications, follows, blocks, activity, reports and
	 * transactions, with their rows intact. On this development site that was
	 * 366 rows of transactions and 56 follows surviving a delete.
	 *
	 * A list is only a source of truth if something checks it, so
	 * `UninstallCoverageTest` runs the migrator on a clean database and asserts
	 * the tables that appear are exactly the tables named here. Add a table
	 * without listing it and that test fails rather than a customer finding it
	 * years later.
	 *
	 * @since 2.4.0
	 *
	 * @return string[] Unprefixed table names.
	 */
	public static function tables(): array {
		return array(
			'mvs_access_grants',
			'mvs_access_rules',
			'mvs_activity',
			'mvs_album_items',
			'mvs_blocks',
			'mvs_bp_activity_media',
			'mvs_conversation_participants',
			'mvs_conversations',
			'mvs_error_log',
			'mvs_favorites',
			'mvs_follows',
			'mvs_media_index',
			'mvs_media_meta',
			'mvs_media_stats',
			'mvs_media_views',
			'mvs_mentions',
			'mvs_message_reactions',
			'mvs_messages',
			'mvs_notifications',
			'mvs_reactions',
			'mvs_reports',
			'mvs_transactions',
		);
	}

	/**
	 * Run pending migrations.
	 */
	public function run(): void {
		$installed_version = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $installed_version >= self::CURRENT_VERSION ) {
			return;
		}

		for ( $v = $installed_version + 1; $v <= self::CURRENT_VERSION; $v++ ) {
			$method = "migrate_to_{$v}";
			if ( method_exists( $this, $method ) ) {
				$this->$method();
			}
		}

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Migration v2 — add manage_mvs_access capability for monetization.
	 */
	private function migrate_to_2(): void {
		\WPMediaVerse\Capabilities\MediaCapabilities::add_caps();
	}

	/**
	 * Migration v4 — reports, blocks, activity tables.
	 *
	 * @since 1.1.0
	 */
	private function migrate_to_4(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 12. Reports.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_reports (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				reporter_id bigint(20) unsigned NOT NULL,
				target_type varchar(20) NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				reason varchar(50) NOT NULL,
				details text,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY reporter_target (reporter_id, target_type, target_id),
				KEY target (target_type, target_id),
				KEY status (status)
			) {$charset_collate};"
		);

		// 13. Blocks.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_blocks (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blocker_id bigint(20) unsigned NOT NULL,
				blocked_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY blocker_blocked (blocker_id, blocked_id),
				KEY blocked_id (blocked_id)
			) {$charset_collate};"
		);

		// 14. Activity feed.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_activity (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				type varchar(50) NOT NULL,
				media_id bigint(20) unsigned NOT NULL DEFAULT 0,
				album_id bigint(20) unsigned NOT NULL DEFAULT 0,
				content text,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_date (user_id, created_at),
				KEY type_date (type, created_at),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
	}

	/**
	 * Migration v3 — social foundation tables (follows, notifications).
	 *
	 * @since 1.1.0
	 */
	private function migrate_to_3(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 10. Follows.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_follows (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				follower_id bigint(20) unsigned NOT NULL,
				following_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY follower_following (follower_id, following_id),
				KEY following_id (following_id),
				KEY status (status)
			) {$charset_collate};"
		);

		// 11. Notifications.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_notifications (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				type varchar(50) NOT NULL,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				media_id bigint(20) unsigned NOT NULL DEFAULT 0,
				comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				read_at datetime DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY user_unread (user_id, read_at),
				KEY user_date (user_id, created_at)
			) {$charset_collate};"
		);
	}

	/**
	 * Migration v1 — create all 9 custom tables.
	 */
	private function migrate_to_1(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Reactions.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_reactions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				reaction_type enum('like','love','haha','wow','sad','angry') NOT NULL DEFAULT 'like',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY media_user (media_id, user_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		// 2. Favorites.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_favorites (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				collection_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY media_user (media_id, user_id),
				KEY user_id (user_id),
				KEY collection_id (collection_id)
			) {$charset_collate};"
		);

		// 3. Media views.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_media_views (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned DEFAULT NULL,
				ip_hash varchar(64) NOT NULL DEFAULT '',
				event_type enum('view','download') NOT NULL DEFAULT 'view',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY media_user_date (media_id, user_id, created_at),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		// 4. Media stats (aggregate).
		dbDelta(
			"CREATE TABLE {$prefix}mvs_media_stats (
				media_id bigint(20) unsigned NOT NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				downloads bigint(20) unsigned NOT NULL DEFAULT 0,
				reactions bigint(20) unsigned NOT NULL DEFAULT 0,
				comments bigint(20) unsigned NOT NULL DEFAULT 0,
				shares bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (media_id)
			) {$charset_collate};"
		);

		// 5. Access rules.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_access_rules (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				rule_type varchar(50) NOT NULL,
				rule_value text NOT NULL,
				price decimal(10,2) DEFAULT NULL,
				currency varchar(3) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY media_id (media_id),
				KEY rule_type (rule_type)
			) {$charset_collate};"
		);

		// 6. Access grants.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_access_grants (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				granted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				source varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY media_user (media_id, user_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		// 7. Mentions.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_mentions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				media_id bigint(20) unsigned NOT NULL,
				mentioned_user_id bigint(20) unsigned NOT NULL,
				context varchar(50) NOT NULL DEFAULT 'description',
				comment_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY media_id (media_id),
				KEY mentioned_user_id (mentioned_user_id)
			) {$charset_collate};"
		);

		// 8. Album items.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_album_items (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				album_id bigint(20) unsigned NOT NULL,
				media_id bigint(20) unsigned NOT NULL,
				position int(11) unsigned NOT NULL DEFAULT 0,
				added_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY album_media (album_id, media_id),
				KEY album_position (album_id, position)
			) {$charset_collate};"
		);

		// 9. Media index — authoritative media record (no CPT dependency).
		dbDelta(
			"CREATE TABLE {$prefix}mvs_media_index (
				media_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				slug varchar(255) NOT NULL DEFAULT '',
				description longtext,
				post_author bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'publish',
				media_type varchar(20) NOT NULL DEFAULT '',
				privacy varchar(20) NOT NULL DEFAULT 'public',
				moderation_status varchar(20) NOT NULL DEFAULT 'approved',
				file_url text,
				file_path text,
				file_type varchar(100) DEFAULT '',
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				file_hash varchar(64) DEFAULT '',
				width int(11) unsigned DEFAULT NULL,
				height int(11) unsigned DEFAULT NULL,
				duration decimal(10,2) DEFAULT NULL,
				album_id bigint(20) unsigned NOT NULL DEFAULT 0,
				view_count bigint(20) unsigned NOT NULL DEFAULT 0,
				reaction_count bigint(20) unsigned NOT NULL DEFAULT 0,
				comment_count bigint(20) unsigned NOT NULL DEFAULT 0,
				is_featured tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (media_id),
				UNIQUE KEY slug (slug),
				KEY moderation_privacy_date (moderation_status, privacy, created_at),
				KEY author_date (post_author, created_at),
				KEY type_date (media_type, created_at),
				KEY status_date (status, created_at),
				KEY album_id (album_id),
				KEY file_hash (file_hash),
				KEY rank_scan (status, moderation_status, privacy, post_author, reaction_count, created_at)
			) {$charset_collate};"
		);
	}

	/**
	 * Migration v5 — error log table + report unique constraint.
	 *
	 * @since 1.2.0
	 */
	private function migrate_to_5(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Error log table for centralized logging.
		dbDelta(
			"CREATE TABLE {$prefix}mvs_error_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level varchar(10) NOT NULL DEFAULT 'info',
				context varchar(50) NOT NULL DEFAULT '',
				message text NOT NULL,
				metadata text DEFAULT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ip_address varchar(45) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY level_date (level, created_at),
				KEY context_date (context, created_at)
			) {$charset_collate};"
		);

		// Add unique constraint to reports (prevent duplicate reports).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$prefix . 'mvs_reports',
				'unique_report'
			)
		);

		if ( ! $index_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$prefix}mvs_reports ADD UNIQUE KEY unique_report (reporter_id, target_type, target_id)" );
		}

		// Unique constraint on follows (prevent duplicate follows).
		$follow_idx = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$prefix . 'mvs_follows',
				'unique_follow'
			)
		);
		if ( ! $follow_idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$prefix}mvs_follows ADD UNIQUE KEY unique_follow (follower_id, following_id)" );
		}

		// Unique constraint on reactions (prevent duplicate reactions).
		$react_idx = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$prefix . 'mvs_reactions',
				'unique_reaction'
			)
		);
		if ( ! $react_idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$prefix}mvs_reactions ADD UNIQUE KEY unique_reaction (media_id, user_id, reaction_type)" );
		}

		// Unique constraint on favorites.
		$fav_idx = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$prefix . 'mvs_favorites',
				'unique_favorite'
			)
		);
		if ( ! $fav_idx ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$prefix}mvs_favorites ADD UNIQUE KEY unique_favorite (media_id, user_id)" );
		}
	}

	/**
	 * Migration v6: Messaging tables (conversations, participants, messages, reactions).
	 */
	private function migrate_to_6(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		dbDelta(
			"CREATE TABLE {$prefix}mvs_conversations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL DEFAULT 'direct',
				title varchar(255) DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL,
				last_message_id bigint(20) unsigned DEFAULT NULL,
				last_message_preview varchar(255) DEFAULT NULL,
				last_activity_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY last_activity (last_activity_at),
				KEY created_by (created_by)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}mvs_conversation_participants (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				last_read_at datetime DEFAULT NULL,
				is_muted tinyint(1) NOT NULL DEFAULT 0,
				muted_until datetime DEFAULT NULL,
				is_pinned tinyint(1) NOT NULL DEFAULT 0,
				is_archived tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				cleared_up_to bigint(20) unsigned NOT NULL DEFAULT 0,
				joined_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY conv_user (conversation_id, user_id),
				KEY user_status (user_id, status),
				KEY user_archived (user_id, is_archived, status),
				KEY conv_read (conversation_id, last_read_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}mvs_messages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				sender_id bigint(20) unsigned NOT NULL,
				content longtext DEFAULT NULL,
				message_type varchar(20) NOT NULL DEFAULT 'text',
				attachment_id bigint(20) unsigned DEFAULT NULL,
				media_id bigint(20) unsigned DEFAULT NULL,
				parent_id bigint(20) unsigned DEFAULT NULL,
				metadata text DEFAULT NULL,
				is_deleted tinyint(1) NOT NULL DEFAULT 0,
				deleted_for_all tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY conv_date (conversation_id, created_at),
				KEY conv_updated (conversation_id, updated_at),
				KEY conv_id (conversation_id),
				KEY sender (sender_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}mvs_message_reactions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				message_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				emoji varchar(50) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY msg_user (message_id, user_id),
				KEY message_id (message_id)
			) {$charset_collate};"
		);

		// phpcs:enable
	}

	/**
	 * Migration v7: Upgrade mvs_media_index to be the authoritative media record.
	 *
	 * Adds columns needed for CPT-free architecture. After this migration,
	 * media items live entirely in mvs_media_index — no wp_posts dependency.
	 */
	private function migrate_to_7(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_media_index';

		// Check if table has already been upgraded (idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_title = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'title'
			)
		);

		if ( $has_title ) {
			return; // Already upgraded.
		}

		// Drop the old table and recreate with full schema.
		// Safe because plugin is pre-release — no production data to preserve.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$table} (
				media_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				slug varchar(255) NOT NULL DEFAULT '',
				description longtext,
				post_author bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'publish',
				media_type varchar(20) NOT NULL DEFAULT '',
				privacy varchar(20) NOT NULL DEFAULT 'public',
				moderation_status varchar(20) NOT NULL DEFAULT 'approved',
				file_url text,
				file_path text,
				file_type varchar(100) DEFAULT '',
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				file_hash varchar(64) DEFAULT '',
				width int(11) unsigned DEFAULT NULL,
				height int(11) unsigned DEFAULT NULL,
				duration decimal(10,2) DEFAULT NULL,
				album_id bigint(20) unsigned NOT NULL DEFAULT 0,
				view_count bigint(20) unsigned NOT NULL DEFAULT 0,
				reaction_count bigint(20) unsigned NOT NULL DEFAULT 0,
				comment_count bigint(20) unsigned NOT NULL DEFAULT 0,
				is_featured tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY  (media_id),
				UNIQUE KEY slug (slug),
				KEY moderation_privacy_date (moderation_status, privacy, created_at),
				KEY author_date (post_author, created_at),
				KEY type_date (media_type, created_at),
				KEY status_date (status, created_at),
				KEY album_id (album_id),
				KEY file_hash (file_hash)
			) {$charset_collate};"
		);
	}

	/**
	 * Migration v8: Drop attachment_id column from mvs_media_index.
	 */
	private function migrate_to_8(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';
		$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'attachment_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $col ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN attachment_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Migration v9 — create mvs_media_meta + mvs_transactions if missing.
	 *
	 * These tables were used by MediaRepository service and quota tracking but
	 * were not included in the initial migration scripts.
	 *
	 * @since 1.0.1
	 */
	private function migrate_to_9(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Sparse key-value metadata for media items.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$prefix}mvs_media_meta (
				media_id bigint(20) unsigned NOT NULL,
				meta_key varchar(100) NOT NULL,
				meta_value longtext,
				PRIMARY KEY (media_id, meta_key),
				KEY meta_key (meta_key)
			) {$charset_collate}"
		);

		// Quota usage transactions.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$prefix}mvs_transactions (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				media_type varchar(20) NOT NULL,
				delta int NOT NULL,
				balance_after int NOT NULL DEFAULT 0,
				reason varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY user_type (user_id, media_type),
				KEY created_at (created_at)
			) {$charset_collate}"
		);
	}

	/**
	 * Migration v10 — recount mvs_tag and mvs_category terms against mvs_media_index.
	 *
	 * Previously term counts stayed at 0 because the default term-count callback
	 * counts wp_posts rows of registered post types, but our media lives in the
	 * mvs_media_index custom table. This backfill brings existing tag/category
	 * counts in line with the new custom update_count_callback.
	 *
	 * @since 1.1.2
	 */
	private function migrate_to_10(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		// Recount mvs_tag and mvs_category terms.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$term_rows = $wpdb->get_results(
			"SELECT tt.term_taxonomy_id
			FROM {$wpdb->term_taxonomy} tt
			WHERE tt.taxonomy IN ( 'mvs_tag', 'mvs_category' )"
		);

		foreach ( $term_rows as $row ) {
			$ttid  = (int) $row->term_taxonomy_id;
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
					INNER JOIN {$index_table} m ON m.media_id = tr.object_id
					WHERE tr.term_taxonomy_id = %d AND m.status = 'publish'",
					$ttid
				)
			);
			$wpdb->update(
				$wpdb->term_taxonomy,
				array( 'count' => $count ),
				array( 'term_taxonomy_id' => $ttid )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Migration v11 — seed `mvs_ai_monthly_budget` for installs that pre-date
	 * the 1.2.0 default change.
	 *
	 * Prior to 1.2.0 the registered default for `mvs_ai_monthly_budget` was 0,
	 * which the AI cost guard interprets as "unlimited". Any install that had
	 * never explicitly saved the option was therefore reading 0 and would have
	 * run uncapped OpenAI calls if AI features were enabled — a billing trap.
	 *
	 * From 1.2.0 the registrar default is 10 (USD/month). Activator handles the
	 * fresh-install seed; this migration handles the existing-install case.
	 *
	 * `add_option()` only inserts when the row is missing, so admins who
	 * deliberately saved `0` (or any other value) are NOT overwritten — only
	 * the genuine "never touched" case gets the safer $10/month cap.
	 *
	 * @since 1.2.0
	 */
	private function migrate_to_11(): void {
		add_option( 'mvs_ai_monthly_budget', 10 );
	}

	/**
	 * Migration v12 — `mvs_bp_activity_media` linkage table.
	 *
	 * Phase 8 of the 1.2.0 plan: replaces regex-parsing of saved
	 * `bp_activity.content` HTML to extract MVS media references with
	 * structured storage. When MVS media is added to a BP activity row,
	 * we write one linkage row per media item with its position in the
	 * activity. Render reads the linkage table and emits markup via
	 * `TemplateHelpers::media_thumbnail()` — zero regex on saved HTML.
	 *
	 * The legacy regex parser (`ActivityContentIntegration::enhance_activity_media_content`)
	 * stays in place as a deprecated fallback for activity rows that
	 * pre-date this migration AND have no backfill match.
	 *
	 * @since 1.2.0
	 */
	private function migrate_to_12(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mvs_bp_activity_media (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				activity_id bigint(20) unsigned NOT NULL,
				media_id bigint(20) unsigned NOT NULL,
				variant varchar(20) NOT NULL DEFAULT 'image',
				position int unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY activity_id (activity_id),
				KEY media_id (media_id),
				KEY activity_position (activity_id, position)
			) {$charset};"
		);
	}

	/**
	 * Migration v16 — generalise the media linkage table to any object type.
	 *
	 * `mvs_bp_activity_media` began as a BuddyPress activity↔media link, but its
	 * `activity_id` column is a bare object id. Add an `object_type` discriminator
	 * (default `'bp_activity'` so every existing row keeps its exact meaning) so the
	 * same table can link media to ANY object — BuddyNext posts (`bn_post`), spaces,
	 * etc. — via the provider-neutral `Media\ObjectMediaLinkage` service. The legacy
	 * BuddyPress save/read path is untouched (its inserts inherit the column default).
	 * Purely additive.
	 */
	private function migrate_to_16(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_bp_activity_media';

		// Idempotent: add the column only when absent (safe to re-run).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_col = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'object_type'",
				$table
			)
		);

		if ( 0 === $has_col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN object_type varchar(32) NOT NULL DEFAULT 'bp_activity' AFTER id" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD KEY object_lookup (object_type, activity_id, position)" );
		}
	}

	/**
	 * Migration v17 — group-conversation support (admin role + container scope).
	 *
	 * Schema was group-ready (`mvs_conversations.type` supports 'group') but lacked
	 * two columns the group lifecycle needs. Both additive, both back-compat:
	 *   - `mvs_conversation_participants.role` (default 'member') — admin/member for
	 *     group management; every existing 1:1 participant stays 'member'.
	 *   - `mvs_conversations.container_type` (default '') + `container_id` (default 0)
	 *     — optionally scope a group conversation to a container (a BuddyNext space
	 *     `bn_space`, a BuddyPress/BuddyBoss group `bp_group`, …) so "space channels"
	 *     are unambiguous and a BP→BN migration can remap the container cleanly.
	 */
	private function migrate_to_17(): void {
		global $wpdb;
		$part = $wpdb->prefix . 'mvs_conversation_participants';
		$conv = $wpdb->prefix . 'mvs_conversations';

		$has = function ( string $table, string $column ) use ( $wpdb ): bool {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$table,
					$column
				)
			) > 0;
		};

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $has( $part, 'role' ) ) {
			$wpdb->query( "ALTER TABLE {$part} ADD COLUMN role varchar(20) NOT NULL DEFAULT 'member' AFTER user_id" );
		}
		if ( ! $has( $conv, 'container_type' ) ) {
			$wpdb->query( "ALTER TABLE {$conv} ADD COLUMN container_type varchar(32) NOT NULL DEFAULT '' AFTER type" );
			$wpdb->query( "ALTER TABLE {$conv} ADD COLUMN container_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER container_type" );
			$wpdb->query( "ALTER TABLE {$conv} ADD KEY container (container_type, container_id)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration v18 — schedule the one-time storage-path repair.
	 *
	 * No schema change and no heavy work inline: this only marks the repair
	 * 'pending'. StorageRepairService::maybe_autostart() then runs cheap
	 * detection once Action Scheduler is ready and, only if affected rows exist,
	 * heals them in bounded async batches (pre-1.8.0 absolute-path imports +
	 * stranded cloud thumbnails). Clean sites schedule nothing.
	 *
	 * @since 1.8.0
	 */
	private function migrate_to_18(): void {
		\WPMediaVerse\Services\StorageRepairService::mark_pending();
	}

	/**
	 * Migration v19 — detach media comments from the wp_posts ID namespace.
	 *
	 * Media comments were stored in wp_comments with comment_post_ID = media_id
	 * (comment_type 'mvs_comment'). Media IDs and post IDs share one
	 * auto-increment space, so a media comment surfaced on the real post/page
	 * that happened to share that ID (WordPress's default comment queries are
	 * not type-scoped) and inflated that post's comment_count. This moves the
	 * real media id into comment meta ('mvs_media_id'), sets comment_post_ID = 0
	 * so no post can ever match again, and recounts the posts that were inflated.
	 * Idempotent — safe to re-run.
	 *
	 * @since 1.9.0
	 */
	private function migrate_to_19(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// The real posts whose comment_count was inflated (capture BEFORE we zero
		// the column) and the comments we are about to move (for cache flush once
		// their comment_post_ID changes underneath WordPress's object cache).
		$affected_posts = $wpdb->get_col(
			"SELECT DISTINCT comment_post_ID FROM {$wpdb->comments}
			 WHERE comment_type = 'mvs_comment' AND comment_post_ID <> 0"
		);
		$moved_ids      = $wpdb->get_col(
			"SELECT comment_ID FROM {$wpdb->comments}
			 WHERE comment_type = 'mvs_comment' AND comment_post_ID <> 0"
		);

		// 1. Preserve the media id in comment meta for rows that lack it.
		$wpdb->query(
			"INSERT INTO {$wpdb->commentmeta} (comment_id, meta_key, meta_value)
			 SELECT c.comment_ID, 'mvs_media_id', c.comment_post_ID
			 FROM {$wpdb->comments} c
			 WHERE c.comment_type = 'mvs_comment' AND c.comment_post_ID <> 0
			   AND NOT EXISTS (
				 SELECT 1 FROM {$wpdb->commentmeta} m
				 WHERE m.comment_id = c.comment_ID AND m.meta_key = 'mvs_media_id'
			   )"
		);

		// 2. Detach from the post-ID namespace.
		$wpdb->query(
			"UPDATE {$wpdb->comments} SET comment_post_ID = 0
			 WHERE comment_type = 'mvs_comment' AND comment_post_ID <> 0"
		);

		// phpcs:enable

		// 3. Correct the comment counts on the real posts that were inflated.
		foreach ( (array) $affected_posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id > 0 && get_post( $post_id ) ) {
				wp_update_comment_count_now( $post_id );
			}
		}

		// 4. Flush the stale (now-wrong comment_post_ID) comment caches.
		foreach ( (array) $moved_ids as $moved_id ) {
			clean_comment_cache( (int) $moved_id );
		}
	}

	/**
	 * Migration v20 — sweep orphaned album/collection index rows.
	 *
	 * Albums and collections get an mvs_media_index row purely to store their
	 * privacy (media_type left empty). Before 2.0.0 the delete handlers cleaned
	 * only their own item tables, so deleting the CPT left that index row behind
	 * and Explore kept rendering a dead, click-broken tile (Basecamp 10073671889).
	 * The delete handlers now purge the row; this heals installs that already
	 * accumulated orphans (deleted albums/collections, authorless placeholders).
	 *
	 * Scoped to empty-media_type rows only, so real media (media_type =
	 * image/video/audio) is never touched. Idempotent.
	 *
	 * @since 2.0.0
	 */
	private function migrate_to_20(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE mi FROM {$wpdb->prefix}mvs_media_index mi
			 WHERE ( mi.media_type = '' OR mi.media_type IS NULL )
			   AND mi.media_id NOT IN (
				 SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'mvs_album', 'mvs_collection' )
			   )"
		);
		// phpcs:enable
	}

	/**
	 * Migration v21 — leaderboard rank composite index on mvs_media_index.
	 *
	 * LeaderboardService::get_viewer_rank() scans mvs_media_index with
	 * WHERE status='publish' AND moderation_status='approved' AND
	 * privacy='public' AND post_author > 0 (+ a created_at window),
	 * GROUP BY post_author, SUM(reaction_count). Fresh 2.1.0 installs get the
	 * `rank_scan` covering index from the CREATE (migrate_to_1); 2.0.x sites
	 * upgrading in place never re-run that CREATE, so without this ALTER the
	 * rank query full-scans the table (O(n) at 50k rows, only masked by a
	 * 5-min cache). This heals existing installs.
	 *
	 * Column order matches the query: the three equality columns first, then
	 * the GROUP BY column, then the aggregated column, then the window column,
	 * so MySQL can satisfy the whole query from the index.
	 *
	 * Idempotent (SHOW INDEX guard), add-only. Suppresses wpdb error display so
	 * a locked-down host that rejects the ALTER degrades to the cached
	 * full-scan rather than fataling.
	 *
	 * @since 2.1.0
	 */
	private function migrate_to_21(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW INDEX FROM {$index_table} WHERE Key_name = 'rank_scan'" );
		if ( $exists ) {
			return;
		}

		$prev_show = $wpdb->show_errors( false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$index_table} ADD KEY rank_scan (status, moderation_status, privacy, post_author, reaction_count, created_at)" );
		$wpdb->show_errors( $prev_show );
	}

	/**
	 * Migration v22 — object-cache-independent DM typing indicator.
	 *
	 * Typing state was stored in `wp_cache` only, so on the majority of sites
	 * (no persistent object cache) the write in one request was gone before the
	 * poll in the next — the "typing…" pip never showed to the recipient. Per
	 * Coding Rule #16 per-(conversation,user) state may not live in wp_options or
	 * unguarded transients; it belongs in the per-participant row that already
	 * exists. This adds a `typing_until` datetime on
	 * `mvs_conversation_participants`: a durable, indexed, self-expiring marker
	 * BuddyNext's existing typing UI reads through the same REST endpoint.
	 *
	 * Additive, idempotent, back-compat (NULL = not typing).
	 *
	 * @since 2.1.0
	 */
	private function migrate_to_22(): void {
		global $wpdb;
		$part = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->column_exists( $part, 'typing_until' ) ) {
			$wpdb->query( "ALTER TABLE {$part} ADD COLUMN typing_until datetime DEFAULT NULL AFTER last_read_at" );
			$wpdb->query( "ALTER TABLE {$part} ADD KEY conv_typing (conversation_id, typing_until)" );
		}
		// phpcs:enable
	}

	/**
	 * Migration v23: give mvs_messages an updated_at column.
	 *
	 * The DM poll only re-serves messages whose created_at is newer than the
	 * client's last poll. A reaction lives in a separate table and never touches
	 * the parent row, so reacting to an already-delivered message was invisible to
	 * the other participant until a full reload (customer report, card
	 * 10122929662). A reactions-table scan cannot fix it alone: removals are hard
	 * deletes that leave no timestamp, so only a column on the message itself —
	 * stamped on both add and remove — catches every change. This is that column;
	 * poll_reaction_updates() reads it.
	 *
	 * Backfilled to created_at so existing rows sort correctly, and indexed on
	 * (conversation_id, updated_at) for the scoped poll query. Additive and
	 * idempotent.
	 *
	 * @since 2.2.0
	 */
	private function migrate_to_23(): void {
		global $wpdb;
		$messages = $wpdb->prefix . 'mvs_messages';

		if ( $this->column_exists( $messages, 'updated_at' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$messages} ADD COLUMN updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at" );
		$wpdb->query( "ALTER TABLE {$messages} ADD KEY conv_updated (conversation_id, updated_at)" );
		// Existing rows: no mutation has happened, so updated_at == created_at.
		$wpdb->query( "UPDATE {$messages} SET updated_at = created_at" );
		// phpcs:enable
	}

	/**
	 * Migration v24 — per-user clear point on conversation participants.
	 *
	 * "Delete conversation" used to flip the participant to 'left' and
	 * find_or_create_conversation() then created a brand-new thread on
	 * re-contact — leaving the other member with TWO threads for the same
	 * pair (card 10127717045). The fix reuses the pair's single thread and
	 * hides pre-delete history per user via this clear point instead.
	 *
	 * The watermark is a message ID, not a datetime: message ids are strictly
	 * monotonic, so `m.id > cleared_up_to` is exact, where a second-precision
	 * DATETIME would hide a message sent in the same second as the delete —
	 * the identical clock-grain race the 2.2.1 poll-cursor fixes closed.
	 *
	 * @since 2.2.1
	 */
	private function migrate_to_24(): void {
		global $wpdb;
		$participants = $wpdb->prefix . 'mvs_conversation_participants';

		if ( $this->column_exists( $participants, 'cleared_up_to' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$participants} ADD COLUMN cleared_up_to bigint(20) unsigned NOT NULL DEFAULT 0 AFTER status" );
		// phpcs:enable
	}

	/**
	 * Migration v25 — covering index for the conversation-list tabs.
	 *
	 * Every tab filters `user_id` + `status` + `is_archived`, but the only key
	 * was `user_status (user_id, status)`, so `is_archived` was resolved by
	 * scanning the matched rows. Harmless on a test account, real work for a
	 * member with thousands of threads — and 2.3.0 adds a fourth tab
	 * (`archived`) that filters on exactly that column.
	 *
	 * Idempotent (SHOW INDEX guard), add-only, mirrors migrate_to_21.
	 *
	 * @since 2.3.0
	 */
	private function migrate_to_25(): void {
		global $wpdb;

		$participants = $wpdb->prefix . 'mvs_conversation_participants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW INDEX FROM {$participants} WHERE Key_name = 'user_archived'" );
		if ( $exists ) {
			return;
		}

		$prev_show = $wpdb->show_errors( false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$participants} ADD KEY user_archived (user_id, is_archived, status)" );
		$wpdb->show_errors( $prev_show );
	}

	/**
	 * Whether a column exists on a table in the current database.
	 *
	 * Shared by the ADD-COLUMN migrations so each stays a guarded, idempotent
	 * ALTER without re-inlining the information_schema lookup.
	 *
	 * @param string $table  Full table name (with prefix).
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		) > 0;
	}

	/**
	 * Migration v13 — FULLTEXT search index on mvs_media_index.(title, description).
	 *
	 * REST `/media?s=<term>` previously did `LIKE '%term%'` on title/description.
	 * That's a sequential scan — fine at 1k rows, brutal at 100k. The 1.2.0
	 * Explore-page autocomplete fires on every keystroke (debounced 250ms),
	 * so search is a hot path. FULLTEXT + MATCH/AGAINST drops worst-case
	 * latency from O(n) to roughly log n with a sub-millisecond ceiling on
	 * indexed terms.
	 *
	 * REST/MediaController gates the swap on length ≥ 3 chars and falls back
	 * to LIKE for shorter queries (default innodb_ft_min_token_size is 3,
	 * so shorter terms wouldn't match the index anyway).
	 *
	 * Add only — no schema rewrite, no row movement. Safe to skip on hosts
	 * that lack InnoDB FULLTEXT (return early on error rather than fatal).
	 *
	 * @since 1.2.1
	 */
	private function migrate_to_13(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		// Skip silently if the index already exists (fresh installs may seed
		// it via dbDelta later, or a previous partial run created it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$exists = $wpdb->get_var( "SHOW INDEX FROM {$index_table} WHERE Key_name = 'media_search_ft'" );
		if ( $exists ) {
			return;
		}

		// FULLTEXT requires InnoDB, which has been the default since MySQL 5.5.
		// On the unlikely host that returns MyISAM here we skip the index;
		// search keeps working via the LIKE fallback in MediaController.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$engine = $wpdb->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$index_table}'" );
		if ( $engine && 0 !== strcasecmp( (string) $engine, 'InnoDB' ) ) {
			return;
		}

		// Suppress wpdb error display — this ALTER may legitimately fail on
		// extremely small / locked-down hosts and the LIKE fallback covers it.
		$prev_show = $wpdb->show_errors( false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$index_table} ADD FULLTEXT KEY media_search_ft (title, description)" );
		$wpdb->show_errors( $prev_show );
	}

	/**
	 * Migration v14 — backfill driver-agnostic relative-path meta from the
	 * legacy URL meta written by pre-1.4.0 uploads.
	 *
	 * Background: pre-1.4.0 we stored the active driver's URL in `file_url` /
	 * `thumb_<size>` / `thumb_<size>_webp` / `thumb_<size>_avif` /
	 * `original_webp` / `original_avif`. Switching CDN or escalating privacy
	 * stranded those URLs. 1.4.0 stores driver-agnostic relative paths in
	 * `{key}_path` meta and resolves URLs at read time via the currently-
	 * active driver. This migration backfills the new keys for existing rows
	 * so the read side stops needing the URL fallback branch.
	 *
	 * Idempotent — rows that already carry the `_path` key are skipped, so
	 * the migration is safe to re-run after a partial completion.
	 *
	 * @since 1.4.0
	 */
	private function migrate_to_14(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$meta_table  = $wpdb->prefix . 'mvs_media_meta';

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return;
		}

		// Map of URL-meta key → corresponding _path key. Order doesn't matter.
		$keymap = array(
			'thumb_large'       => 'thumb_large_path',
			'thumb_medium'      => 'thumb_medium_path',
			'thumb_thumb'       => 'thumb_thumb_path',
			'thumb_large_webp'  => 'thumb_large_webp_path',
			'thumb_medium_webp' => 'thumb_medium_webp_path',
			'thumb_thumb_webp'  => 'thumb_thumb_webp_path',
			'thumb_large_avif'  => 'thumb_large_avif_path',
			'thumb_medium_avif' => 'thumb_medium_avif_path',
			'thumb_thumb_avif'  => 'thumb_thumb_avif_path',
			'original_webp'     => 'original_webp_path',
			'original_avif'     => 'original_avif_path',
		);

		// Walk every media row that has a `file_path` (the authoritative
		// relative path for the original). Per-row work is small — bounded
		// to ≤24 meta lookups + ≤11 writes — so a straight cursor is fine
		// for sites well into 100k media. Larger sites can interrupt and
		// re-run; this migration is idempotent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT media_id, file_path FROM {$index_table} WHERE file_path IS NOT NULL AND file_path <> ''" );

		foreach ( (array) $rows as $row ) {
			$media_id      = (int) $row->media_id;
			$file_path_rel = (string) $row->file_path;

			// Imported records (MediaPress / rtMedia / BuddyBoss importers)
			// store ABSOLUTE filesystem paths in `file_path` — same legacy
			// shape `MediaRepository::get_filesystem_path()` accommodates.
			// For those, `_path` would resolve to bogus locations under
			// uploads/wpmediaverse/, so we leave the URL meta authoritative
			// and skip the backfill. They never lived on cloud anyway, so
			// no CDN-switch staleness to worry about.
			if ( 0 === strpos( $file_path_rel, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $file_path_rel ) ) {
				continue;
			}

			$rel_dir    = dirname( $file_path_rel );
			$rel_prefix = ( '.' === $rel_dir || '' === $rel_dir ) ? '' : trailingslashit( $rel_dir );

			foreach ( $keymap as $url_key => $path_key ) {
				// Skip if path already populated (idempotent).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing_path = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$meta_table} WHERE media_id = %d AND meta_key = %s",
						$media_id,
						$path_key
					)
				);
				if ( is_string( $existing_path ) && '' !== $existing_path ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$url_value = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$meta_table} WHERE media_id = %d AND meta_key = %s",
						$media_id,
						$url_key
					)
				);
				if ( ! is_string( $url_value ) || '' === $url_value ) {
					continue;
				}

				// Resolve rel path. Three shapes for legacy URLs:
				// a) Local uploads URL — {baseurl}/wpmediaverse/2026/05/foo.jpg
				// b) Cloud URL — https://zone.b-cdn.net/wpmediaverse/2026/05/foo.jpg
				// c) Anything else — derive basename from URL, place under file_path's dir.
				$rel_path = '';
				$marker   = '/wpmediaverse/';
				$pos      = strpos( $url_value, $marker );
				if ( false !== $pos ) {
					$rel_path = ltrim( substr( $url_value, $pos + strlen( $marker ) ), '/' );
				} else {
					$basename = basename( (string) wp_parse_url( $url_value, PHP_URL_PATH ) );
					if ( '' !== $basename ) {
						$rel_path = $rel_prefix . $basename;
					}
				}

				if ( '' === $rel_path ) {
					continue;
				}

				// Strip query string / fragment if present (CDN URLs sometimes
				// carry signing params or cache-busters).
				$qpos = strpos( $rel_path, '?' );
				if ( false !== $qpos ) {
					$rel_path = substr( $rel_path, 0, $qpos );
				}
				$fpos = strpos( $rel_path, '#' );
				if ( false !== $fpos ) {
					$rel_path = substr( $rel_path, 0, $fpos );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->replace(
					$meta_table,
					array(
						'media_id'   => $media_id,
						'meta_key'   => $path_key,
						'meta_value' => $rel_path,
					),
					array( '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Migration v15 — heal video poster `thumb_*_path` meta that diverges
	 * from the variant's actual on-disk location.
	 *
	 * The bug: pre-1.5.0 `UploadService::generate_thumbnails()` computed the
	 * variant rel-path from the MEDIA row's `file_path` directory
	 * (`$cloud_rel_dir`, e.g. `2026/05`) instead of the variant's actual
	 * on-disk directory. For video posters the variants live at
	 * `wpmediaverse/posters/<id>-WxH.jpg`, but `_path` meta was written as
	 * `2026/05/<id>-WxH.jpg` — every read against that path resolved to a
	 * missing file. The URL meta (`thumb_<size>`) was always correct since
	 * it was computed from the variant's actual location.
	 *
	 * Fix in 1.5.0 lives in `VariantSpec::compute_rel_path()` for new
	 * uploads. This migration heals legacy rows by re-deriving `_path`
	 * from the URL meta (authoritative). Idempotent, bounded per-row work,
	 * scoped to video rows.
	 *
	 * @since 1.5.0
	 */
	private function migrate_to_15(): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$meta_table  = $wpdb->prefix . 'mvs_media_meta';

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return;
		}
		$wpmv_basedir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/';

		// `_path` keys + their authoritative URL counterparts. Image rows
		// were never affected by the bug — only videos diverged because
		// posters land in a different subdir than the source file_path —
		// but running this against images is harmless because v14 already
		// wrote the same value we'd derive here, so the idempotency check
		// below short-circuits.
		$keymap = array(
			'thumb_large_path'       => 'thumb_large',
			'thumb_medium_path'      => 'thumb_medium',
			'thumb_thumb_path'       => 'thumb_thumb',
			'thumb_large_webp_path'  => 'thumb_large_webp',
			'thumb_medium_webp_path' => 'thumb_medium_webp',
			'thumb_thumb_webp_path'  => 'thumb_thumb_webp',
			'thumb_large_avif_path'  => 'thumb_large_avif',
			'thumb_medium_avif_path' => 'thumb_medium_avif',
			'thumb_thumb_avif_path'  => 'thumb_thumb_avif',
		);

		// Scope to videos for efficiency on large sites — the bug only
		// affected video poster paths. (Audio ID3 cover variants follow
		// the same posters-dir convention, so include them too.)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT media_id, file_path FROM {$index_table} WHERE media_type IN ('video','audio') AND file_path IS NOT NULL AND file_path <> ''"
		);

		foreach ( (array) $rows as $row ) {
			$media_id      = (int) $row->media_id;
			$file_path_rel = (string) $row->file_path;

			// Skip legacy absolute-path imports (same guard as v14).
			if ( 0 === strpos( $file_path_rel, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $file_path_rel ) ) {
				continue;
			}

			foreach ( $keymap as $path_key => $url_key ) {
				// Read the URL meta — authoritative source.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$url_value = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$meta_table} WHERE media_id = %d AND meta_key = %s",
						$media_id,
						$url_key
					)
				);
				if ( ! is_string( $url_value ) || '' === $url_value ) {
					continue;
				}

				// Derive rel_path from URL via /wpmediaverse/ marker.
				$marker = '/wpmediaverse/';
				$pos    = strpos( $url_value, $marker );
				if ( false === $pos ) {
					continue; // URL doesn't reference uploads dir; can't derive.
				}
				$derived_path = ltrim( substr( $url_value, $pos + strlen( $marker ) ), '/' );

				// Strip query string / fragment if present.
				$qpos = strpos( $derived_path, '?' );
				if ( false !== $qpos ) {
					$derived_path = substr( $derived_path, 0, $qpos );
				}
				$fpos = strpos( $derived_path, '#' );
				if ( false !== $fpos ) {
					$derived_path = substr( $derived_path, 0, $fpos );
				}
				if ( '' === $derived_path ) {
					continue;
				}

				// Cloud-host check. The URL meta is authoritative for cloud
				// customers — `_path` resolves via the active driver, so on
				// a cloud driver `driver->url($_path)` builds the CDN URL.
				// If the URL meta points at a non-local host (b-cdn.net, S3,
				// R2 with public domain, etc.), the CDN file is at the
				// URL-derived path. Probing `posters/<basename>` on local
				// disk is misleading — the local file may be a copy that
				// was never re-uploaded to the CDN at the new path.
				// Mirrors `SignedUrlService::is_cloud_hosted_url`.
				$is_cloud_url = false;
				if ( preg_match( '#^https?://#i', $url_value ) ) {
					// Strip scheme from both URL + baseurl, compare prefix.
					$url_baseurl = (string) preg_replace( '#^https?://#i', '', (string) $upload_dir['baseurl'] );
					$url_bare    = (string) preg_replace( '#^https?://#i', '', $url_value );
					if ( '' !== $url_baseurl && 0 !== strpos( $url_bare, $url_baseurl ) ) {
						$is_cloud_url = true;
					}
				}

				// Two-step probe to pick the correct rel_path. The local
				// disk-probe to `posters/<basename>` only fires when the
				// URL meta is LOCAL — there the disk state is authoritative
				// and a pre-1.5.0 row may have recorded the wrong subdir
				// even though the file landed under `posters/`. On cloud
				// rows we trust the URL-derived path (the CDN file lives
				// there) and skip the posters fallback so we don't write
				// a `_path` that resolves to a CDN-unreachable location.
				if ( $is_cloud_url ) {
					$probe_paths = array( $derived_path );
				} else {
					$probe_paths = array( $derived_path );
					$basename    = basename( $derived_path );
					if ( '' !== $basename ) {
						$probe_paths[] = 'posters/' . $basename;
					}
				}

				$resolved_path = '';
				if ( $is_cloud_url ) {
					// Cloud row — trust the URL-derived path. No disk
					// probe; the file is on the CDN, not on disk.
					$resolved_path = $derived_path;
				} else {
					foreach ( $probe_paths as $probe ) {
						if ( file_exists( $wpmv_basedir . $probe ) ) {
							$resolved_path = $probe;
							break;
						}
					}
				}

				// Stranded variants (uploaded then unlinked locally on a
				// driver that later went away) leave nothing for us to
				// point at — keep `_path` as-is so the URL fallback keeps
				// serving from wherever the legacy URL meta resolves.
				if ( '' === $resolved_path ) {
					continue;
				}

				// Idempotency: skip if `_path` already matches what we'd
				// write.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing_path = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$meta_table} WHERE media_id = %d AND meta_key = %s",
						$media_id,
						$path_key
					)
				);
				if ( is_string( $existing_path ) && $existing_path === $resolved_path ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->replace(
					$meta_table,
					array(
						'media_id'   => $media_id,
						'meta_key'   => $path_key,
						'meta_value' => $resolved_path,
					),
					array( '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Backfill a BIGINT FK column from a varchar URL column.
	 *
	 * Generic schema-migration utility used by Pro's Phase 0b cover-image
	 * migration and any future column that swaps URL-storage for an FK to
	 * `mvs_media_index.id`. Reverse-resolves each existing URL via
	 * `\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->find_by_url` and writes the resolved media_id into
	 * the new column. Rows whose URL doesn't reverse-resolve (orphans,
	 * external URLs, deleted media) get null in the new column — the caller
	 * decides whether to fall back to an empty cover or fail loudly.
	 *
	 * Idempotent: only updates rows where the new column is still NULL.
	 * Safe to re-run after a partial completion.
	 *
	 * Caller is responsible for ensuring `$id_col` exists on the table
	 * (via dbDelta in the per-plugin Migrator) BEFORE calling this helper.
	 *
	 * @since 1.2.0
	 *
	 * @param string $table_unprefixed Table name without the `$wpdb->prefix` (e.g. `'mvs_competitions'`).
	 * @param string $url_col          Existing varchar URL column (e.g. `'cover_image_url'`).
	 * @param string $id_col           New BIGINT FK column (e.g. `'cover_media_id'`).
	 * @return array{rows_seen:int,rows_updated:int,rows_unresolved:int} Migration counters.
	 */
	public static function migrate_url_column_to_id( string $table_unprefixed, string $url_col, string $id_col ): array {
		global $wpdb;

		// Allowlist identifiers — interpolated into SQL because $wpdb->prepare
		// has no placeholder for table/column names.
		$valid_ident = '/^[A-Za-z_][A-Za-z0-9_]*$/';
		if ( ! preg_match( $valid_ident, $table_unprefixed )
			|| ! preg_match( $valid_ident, $url_col )
			|| ! preg_match( $valid_ident, $id_col ) ) {
			return array(
				'rows_seen'       => 0,
				'rows_updated'    => 0,
				'rows_unresolved' => 0,
			);
		}

		$table = $wpdb->prefix . $table_unprefixed;

		// Discover the table's primary-key column so we can target updates
		// without assuming `id`. Falls back to `id` when the lookup fails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pk_row = $wpdb->get_row(
			"SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$pk_col = $pk_row['Column_name'] ?? 'id';
		if ( ! preg_match( $valid_ident, $pk_col ) ) {
			$pk_col = 'id';
		}

		$rows_seen       = 0;
		$rows_updated    = 0;
		$rows_unresolved = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT `{$pk_col}` AS pk, `{$url_col}` AS url FROM `{$table}` WHERE `{$id_col}` IS NULL AND `{$url_col}` IS NOT NULL AND `{$url_col}` <> ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( (array) $rows as $row ) {
			++$rows_seen;
			$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->find_by_url( (string) $row->url );
			if ( $media_id <= 0 ) {
				++$rows_unresolved;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array( $id_col => $media_id ),
				array( $pk_col => $row->pk ),
				array( '%d' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				++$rows_updated;
			}
		}

		return array(
			'rows_seen'       => $rows_seen,
			'rows_updated'    => $rows_updated,
			'rows_unresolved' => $rows_unresolved,
		);
	}

	/**
	 * Migration v26 — evict album/collection rows from mvs_media_index.
	 *
	 * THE INVARIANT: mvs_media_index holds media, one row per media item, and an
	 * album or collection ID must never appear in media_id. Albums are wp_posts rows
	 * that media point at; their own attributes belong in post meta.
	 *
	 * Before 2.4.0 albums wrote privacy/album_type/import-markers through
	 * MediaRepository keyed on their post ID. media_id is AUTO_INCREMENT for media,
	 * so on any site where uploads have outrun post IDs that write landed on a real
	 * photo and overwrote its slug and privacy.
	 *
	 * This pass is COPY-THEN-DELETE and never the reverse, so an interruption leaves
	 * data duplicated rather than lost. It is idempotent: existing post meta is never
	 * overwritten, and a second run finds nothing to do.
	 *
	 * Rows carrying real media are PRESERVED and logged. Their album data was
	 * destroyed at write time and is not recoverable — the photo is real and must not
	 * be touched.
	 *
	 * Basecamp 10183850886. Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md
	 *
	 * @since 2.4.0
	 * @return void
	 */
	private function migrate_to_26(): void {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$meta  = $wpdb->prefix . 'mvs_media_meta';

		// Keys only an album ever writes. Safe to lift and remove even from a colliding
		// row, because a media item never uses them. Everything else under a colliding
		// ID belongs to the photo (thumbnails, webp paths, EXIF) and must be left alone.
		$album_only_keys = array(
			'album_type'       => '_mvs_album_type',
			'mpp_gallery_id'   => '_mvs_mpp_gallery_id',
			'rtmedia_album_id' => '_mvs_rtmedia_album_id',
			'bb_album_id'      => '_mvs_bb_album_id',
		);

		// AMBIGUOUS: media uses group_id too — PrivacyService::check_group() reads it
		// off a media row to resolve BuddyPress group visibility. On a colliding ID
		// there is no way to tell whose it is, so it is neither copied nor deleted
		// there: guessing wrong would either mis-scope the album or destroy the
		// photo's group assignment. On a clean album row it migrates normally.
		$ambiguous_keys = array(
			'group_id' => '_mvs_group_id',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS cpt_id, p.post_type, m.privacy, m.media_type
				   FROM {$index} m
				   INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				  WHERE p.post_type IN ( %s, %s )",
				'mvs_album',
				'mvs_collection'
			),
			ARRAY_A
		);

		$migrated  = 0;
		$preserved = array();

		foreach ( (array) $rows as $row ) {
			$cpt_id    = (int) $row['cpt_id'];
			$colliding = '' !== (string) $row['media_type'];

			// 1. Privacy onto the post. Never overwrite a value already there.
			if ( '' === (string) get_post_meta( $cpt_id, '_mvs_privacy', true ) ) {
				$privacy = (string) $row['privacy'];
				update_post_meta( $cpt_id, '_mvs_privacy', '' !== $privacy ? $privacy : 'public' );
			}

			// 2. Album attribute meta onto the post, then drop just those keys.
			$movable = $colliding ? $album_only_keys : array_merge( $album_only_keys, $ambiguous_keys );

			foreach ( $movable as $old_key => $post_key ) {
				$value = $wpdb->get_var(
					$wpdb->prepare( "SELECT meta_value FROM {$meta} WHERE media_id = %d AND meta_key = %s", $cpt_id, $old_key )
				);
				if ( null === $value ) {
					continue;
				}
				if ( '' === (string) get_post_meta( $cpt_id, $post_key, true ) ) {
					update_post_meta( $cpt_id, $post_key, $value );
				}
				$wpdb->delete(
					$meta,
					array(
						'media_id' => $cpt_id,
						'meta_key' => $old_key,
					),
					array( '%d', '%s' )
				);
			}

			if ( $colliding ) {
				// A real media row wearing this album's privacy. Keep every byte of it.
				$preserved[] = $cpt_id;
				continue;
			}

			// 3. Attribute-only row — it was never legitimate. Remove it.
			$wpdb->delete( $index, array( 'media_id' => $cpt_id ), array( '%d' ) );
			++$migrated;
		}

		// 4. Album-space taxonomy rows. Categories on albums were write-only (nothing
		// read them back) and shared object_id space with media. Clear them ONLY where
		// the ID is not also a media row — where it is, the terms are the photo's and
		// deleting them would be the same class of data loss this migration exists to
		// stop.
		$orphan_terms = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE tr FROM {$wpdb->term_relationships} tr
				   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				   INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				   LEFT JOIN {$index} m ON m.media_id = tr.object_id AND m.media_type <> ''
				  WHERE tt.taxonomy IN ( %s, %s )
				    AND p.post_type IN ( %s, %s )
				    AND m.media_id IS NULL",
				'mvs_category',
				'mvs_tag',
				'mvs_album',
				'mvs_collection'
			)
		);
		// phpcs:enable

		if ( ! empty( $preserved ) ) {
			update_option( 'mvs_cpt_id_collisions', $preserved, false );
		}

		if ( class_exists( '\\WPMediaVerse\\Services\\LoggerService' ) ) {
			\WPMediaVerse\Services\LoggerService::error(
				'migration',
				'v26 evicted album/collection rows from mvs_media_index',
				array(
					'rows_removed'     => $migrated,
					'collisions_kept'  => count( $preserved ),
					'collision_ids'    => $preserved,
					'orphan_term_rows' => $orphan_terms,
				)
			);
		}
	}

	/**
	 * Option recording that the legacy-document quarantine has run.
	 *
	 * The quarantine is the one step in v27 that is NOT naturally idempotent:
	 * re-running it after the document library ships would re-type real member
	 * documents as legacy. The version gate already runs each migration once, but
	 * this makes the property hold no matter how the method is invoked, which is
	 * what the test asserts.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	const LEGACY_QUARANTINE_OPTION = 'mvs_legacy_documents_quarantined';

	/**
	 * Migration v27 — quarantine the catch-all rows, then open the document schema.
	 *
	 * ORDER MATTERS AND IS NOT COSMETIC. The quarantine runs FIRST, before anything
	 * can create a real document row. Until 1.2.3 `UploadService::get_media_type()`
	 * returned 'document' for anything that was not image/video/audio — an inverse
	 * allowlist — so on a site that ran with PDFs enabled, `media_type='document'`
	 * means "we could not classify this", not "a member uploaded a document". The
	 * document library is about to give that exact string a real meaning, and the
	 * two populations must be separated before they can ever mix.
	 *
	 * WHY THEY STAY VISIBLE. `legacy_document` is in `MediaTypes::MEDIA_LIBRARY`, so
	 * these rows keep appearing everywhere they appear today. That is deliberate:
	 * members can see them in their libraries right now, and dropping them from
	 * listings would delete content from a member's library on upgrade — a visible
	 * regression on precisely the sites this protects, and the kind of
	 * default-behaviour change Production Rule 3 forbids. They are NOT in
	 * `DOCUMENTS`: they never passed `DocumentTypes::resolve()`, have no folder and
	 * no extraction, so the document library must not claim them. Adopting them is a
	 * deliberate, owner-initiated migration, never an upgrade side effect.
	 *
	 * The remaining three steps are additive DDL — a column and two indexes, each
	 * guarded so a partial prior run resumes cleanly. `search_text` is deliberately
	 * NOT here: it lives on the `mvs_document_search` side table (P8.1), because a
	 * longtext + FULLTEXT on `mvs_media_index` would put that cost on the hottest
	 * table in the product for every media write on the site.
	 *
	 * Design: plan/document-library.md §2; what shipped, §19.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	private function migrate_to_27(): void {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';

		// 1. Quarantine, once and once only. See LEGACY_QUARANTINE_OPTION.
		if ( ! get_option( self::LEGACY_QUARANTINE_OPTION ) ) {
			$quarantined = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"UPDATE {$index} SET media_type = %s WHERE media_type = %s",
					\WPMediaVerse\Core\MediaTypes::LEGACY_DOCUMENT,
					'document'
				)
			);

			update_option(
				self::LEGACY_QUARANTINE_OPTION,
				array(
					'rows' => $quarantined,
					'at'   => current_time( 'mysql', true ),
				),
				false
			);

			if ( $quarantined > 0 && class_exists( '\\WPMediaVerse\\Services\\LoggerService' ) ) {
				\WPMediaVerse\Services\LoggerService::error(
					'migration',
					'v27 quarantined pre-1.2.3 catch-all rows as legacy_document',
					array( 'rows' => $quarantined )
				);
			}
		}

		// 2. folder_id — documents only, 0 means "at the drive root". Separate from
		// album_id on purpose (design §2): an item is in an album OR a folder, the
		// two id spaces are unrelated, and overloading one column would make every
		// drive query depend on knowing which meaning was stored.
		if ( ! $this->column_exists( $index, 'folder_id' ) ) {
			$prev_show = $wpdb->show_errors( false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$index} ADD COLUMN folder_id bigint(20) unsigned NOT NULL DEFAULT 0" );
			$wpdb->show_errors( $prev_show );
		}

		// 3. KEY doc_listing IS the drive query verbatim, left-to-right —
		// WHERE media_type=? AND folder_id=? AND status=? ORDER BY created_at.
		// Without it the drive degrades to a scan of the whole media table at
		// exactly the sites with the most documents.
		$this->add_index_if_missing( $index, 'doc_listing', 'doc_listing (media_type, folder_id, status, created_at)' );

		// 4. KEY type_file backs "every PDF in this drive" without a scan.
		$this->add_index_if_missing( $index, 'type_file', 'type_file (media_type, file_type)' );
	}

	/**
	 * Add an index unless a key of that name already exists WITH THE RIGHT COLUMNS.
	 *
	 * Extracted rather than inlined twice: v25 and v27 both needed this SHOW INDEX
	 * guard, and a second copy of a guarded ALTER is how one of them ends up
	 * unguarded later.
	 *
	 * THE SHAPE CHECK IS NOT DEFENSIVE PADDING — it is the fix for a defect this
	 * method shipped with, found on 2026-08-19 by reading `SHOW INDEX` on a real
	 * database instead of trusting the migration that wrote it. The name-only
	 * test made a degraded index permanent, and silently:
	 *
	 *   1. v29 adds `drive_type`/`drive_id` and `KEY drive_listing` over six
	 *      columns including them.
	 *   2. Anything later drops those two columns — the 2026-08-15 upgrade
	 *      rehearsal did exactly this on purpose, and a restored dump from a
	 *      mixed version or a host tool does it by accident. **MySQL does not
	 *      drop the index; it rewrites it down to the surviving columns**, so
	 *      `drive_listing` becomes `(media_type, folder_id, status, created_at)`
	 *      — a byte-identical duplicate of `doc_listing`, paying write cost on
	 *      the hottest table in the product for nothing.
	 *   3. The columns come back. This method sees the NAME, returns true, and
	 *      the six-column index is never rebuilt.
	 *
	 * The site is then permanently missing the index its own code comments
	 * promise it has, and the deep-OFFSET soft spot on a large Space drive is
	 * open again with nothing to indicate it. Nobody would look, because the
	 * migration reports success.
	 *
	 * So: compare the actual column list, and rebuild when it differs. The DROP
	 * costs a rebuild on a big table, but it only runs when the shape is already
	 * wrong — and a wrong index is not cheaper than the right one, it is a write
	 * cost buying nothing.
	 *
	 * @since 2.4.0
	 *
	 * @param string $table      Full table name (with prefix).
	 * @param string $key_name   Index name to test for.
	 * @param string $definition Index definition, e.g. "name (col_a, col_b)".
	 * @return bool True when the index exists, with the right columns, after this call.
	 */
	private function add_index_if_missing( string $table, string $key_name, string $definition ): bool {
		global $wpdb;

		$have = $this->index_columns( $table, $key_name );
		$want = $this->definition_columns( $definition );

		if ( $have ) {
			if ( $have === $want ) {
				return true;
			}

			// Wrong shape under the right name. Drop it, then fall through and
			// build the one the definition asks for.
			$prev_drop = $wpdb->show_errors( false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$table} DROP KEY `{$key_name}`" );
			$wpdb->show_errors( $prev_drop );
		}

		$prev_show = $wpdb->show_errors( false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$definition}" );
		$wpdb->show_errors( $prev_show );

		return $this->index_columns( $table, $key_name ) === $want;
	}

	/**
	 * The columns an existing index is built over, in order.
	 *
	 * Ordered by `Seq_in_index`, because the ORDER is the index: the same four
	 * columns in a different sequence serve a different query.
	 *
	 * @since 2.4.0
	 *
	 * @param string $table    Full table name.
	 * @param string $key_name Index name.
	 * @return string[] Lower-cased column names, empty when the index is absent.
	 */
	private function index_columns( string $table, string $key_name ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key_name ),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);

		return array_map(
			static function ( $row ) {
				return strtolower( (string) $row['Column_name'] );
			},
			$rows
		);
	}

	/**
	 * The columns a definition string asks for, in order.
	 *
	 * Parses `name (col_a, col_b(150))` — the prefix length is dropped, since
	 * `SHOW INDEX` reports it in `Sub_part` rather than in `Column_name` and
	 * comparing the two lists is the whole point.
	 *
	 * @since 2.4.0
	 *
	 * @param string $definition Index definition.
	 * @return string[] Lower-cased column names.
	 */
	private function definition_columns( string $definition ): array {
		if ( ! preg_match( '/\((.*)\)\s*$/s', $definition, $matches ) ) {
			return array();
		}

		$columns = array();

		foreach ( explode( ',', $matches[1] ) as $column ) {
			$column = strtolower( trim( $column ) );
			$column = preg_replace( '/\s*\(\d+\)$/', '', $column );
			$column = trim( (string) $column, '`' );

			if ( '' !== $column ) {
				$columns[] = $column;
			}
		}

		return $columns;
	}

	/**
	 * Migration v28 — clear hard-refused MIME types out of the stored
	 * `mvs_allowed_file_types`.
	 *
	 * Every install predating 1.2.3 still carries `application/pdf` in that
	 * option. Card #9962125462 removed PDF from the settings grid and made the
	 * upload path refuse it, but neither half covered an install whose option
	 * ALREADY contained it — so the picker went on advertising PDF to members,
	 * the server went on refusing it, and the owner had no way to stop it: PDF is
	 * absent from the grid so there is no box to untick, and the sanitizer's
	 * preserve-custom-types rule (correct in itself) wrote it back on every save.
	 * The stored value was unreachable by any admin action (Basecamp 10190738445).
	 *
	 * Nothing is lost: the media path has refused these types since 1.2.3, so
	 * removing them from the option removes an entry that only ever produced a
	 * failed upload. PDFs belong in the document library, which accepts them.
	 *
	 * Idempotent — re-running finds nothing to strip. A site that genuinely wants
	 * one of these back can still add it through the `mvs_allowed_file_types`
	 * filter, which this does not touch.
	 *
	 * @since 2.4.0
	 */
	/**
	 * Migration v29 — the drive columns (Phase 11 G1).
	 *
	 * A document uploaded to a Space root today stores only `post_author`, so it
	 * would appear in the UPLOADER'S personal drive rather than the Space
	 * library. Personal root listing is `folder_id = 0 AND post_author = %d`, and
	 * that coincidence — ownership doubling as scoping — is the gap.
	 *
	 * COLUMNS, NOT POST META, per §23.2 G1: every listing this feature needs is
	 * drive-scoped, and drive-scoped listing through post meta is a join that
	 * degrades exactly as a Space library grows. Albums use meta because albums
	 * are `wp_posts` and few; documents live here and are not.
	 *
	 * THE INDEX SHIPS WITH THE COLUMNS, and that is the part easy to miss.
	 * `KEY doc_listing` is `(media_type, folder_id, status, created_at)` — once
	 * root listing is keyed on the drive, the two new columns land AFTER its
	 * prefix and cannot be used, so §12's "the index is the drive query verbatim"
	 * would quietly stop being true, visible only on a large Space.
	 * `drive_documents()` already carried a measured comment saying the clean fix
	 * is an index this table does not have and "belongs in its own migration
	 * rather than riding along with a UI phase". This is that migration.
	 *
	 * THE BACKFILL IS BOUNDED. Stamping every row in one pass is an unbounded
	 * UPDATE on the hottest table in the product; a site with a large library
	 * would hang its own upgrade. It runs in batches from a cursor, continued by
	 * cron, and the default (`user` / 0) is a safe resting state throughout: an
	 * unstamped row is resolved by `post_author` exactly as it is today.
	 *
	 * @since 2.4.0
	 */
	private function migrate_to_29(): void {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';

		if ( ! $this->column_exists( $index, 'drive_type' ) ) {
			$prev = $wpdb->show_errors( false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$index} ADD COLUMN drive_type varchar(20) NOT NULL DEFAULT 'user'" );
			$wpdb->show_errors( $prev );
		}

		if ( ! $this->column_exists( $index, 'drive_id' ) ) {
			$prev = $wpdb->show_errors( false );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$index} ADD COLUMN drive_id bigint(20) unsigned NOT NULL DEFAULT 0" );
			$wpdb->show_errors( $prev );
		}

		// The drive query verbatim, left-to-right:
		// WHERE media_type=? AND drive_type=? AND drive_id=? AND folder_id=?
		// AND status=? ORDER BY created_at
		$this->add_index_if_missing(
			$index,
			'drive_listing',
			'drive_listing (media_type, drive_type, drive_id, folder_id, status, created_at)'
		);

		// Kick the bounded backfill off. It is idempotent and resumable, so an
		// interrupted upgrade simply continues on the next tick.
		if ( ! get_option( self::DRIVE_BACKFILL_OPTION ) ) {
			update_option( self::DRIVE_BACKFILL_OPTION, 0, false );
		}

		if ( ! wp_next_scheduled( self::DRIVE_BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::DRIVE_BACKFILL_HOOK );
		}
	}

	/**
	 * v30 — device tokens for native push notifications.
	 *
	 * Stores one row per (user, device) so the notification pipeline can dispatch
	 * a push to a member's registered devices. The plugin OWNS the tokens and
	 * fires the dispatch signal; the actual FCM/APNs send is a delivery
	 * integration's job (credentials + platform SDKs live off the plugin).
	 * Basecamp 9667082225.
	 */
	private function migrate_to_30(): void {
		global $wpdb;

		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$prefix}mvs_device_tokens (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				platform varchar(20) NOT NULL,
				token varchar(255) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY user_id (user_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Stamp one batch of rows with their personal drive.
	 *
	 * Personal drives only — every existing row belongs to the member who
	 * uploaded it, which is precisely what makes the backfill safe to do in
	 * arrears. Space drives do not exist yet; when they do, ingest stamps them at
	 * write time and this never sees them.
	 *
	 * Keyset pagination on `media_id`, not OFFSET: an offset walk over a table
	 * being written to skips rows, and skipping here means a document that never
	 * gets a drive.
	 *
	 * @since 2.4.0
	 *
	 * @return int Rows stamped in this batch.
	 */
	public static function backfill_drive_columns(): int {
		global $wpdb;

		$cursor = (int) get_option( self::DRIVE_BACKFILL_OPTION, 0 );

		if ( $cursor < 0 ) {
			return 0;
		}

		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT media_id, post_author FROM {$index}
				  WHERE media_id > %d AND drive_id = 0
				  ORDER BY media_id ASC
				  LIMIT %d",
				$cursor,
				self::DRIVE_BACKFILL_BATCH
			)
		);

		if ( ! $rows ) {
			// -1 marks "finished" so a restart does not rescan the whole table.
			update_option( self::DRIVE_BACKFILL_OPTION, -1, false );

			return 0;
		}

		$stamped = 0;
		$highest = $cursor;

		foreach ( $rows as $row ) {
			$media_id = (int) $row->media_id;
			$author   = (int) $row->post_author;
			$highest  = max( $highest, $media_id );

			if ( $author <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$index,
				array(
					'drive_type' => 'user',
					'drive_id'   => $author,
				),
				array( 'media_id' => $media_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);

			++$stamped;
		}

		update_option( self::DRIVE_BACKFILL_OPTION, $highest, false );

		// More to do — come back for it.
		if ( ! wp_next_scheduled( self::DRIVE_BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::DRIVE_BACKFILL_HOOK );
		}

		return $stamped;
	}

	private function migrate_to_28(): void {
		$stored = get_option( 'mvs_allowed_file_types', null );

		if ( null === $stored || '' === $stored ) {
			return;
		}

		$types  = array_filter( array_map( 'trim', explode( ',', (string) $stored ) ) );
		$closed = array_values( array_diff( $types, \WPMediaVerse\Services\UploadService::hard_refused_mimes() ) );

		if ( count( $closed ) === count( $types ) ) {
			return;
		}

		update_option( 'mvs_allowed_file_types', implode( ',', $closed ) );
	}
}

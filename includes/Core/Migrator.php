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

	const CURRENT_VERSION = 11;
	const VERSION_OPTION  = 'mvs_db_version';

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
				KEY file_hash (file_hash)
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
				joined_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY conv_user (conversation_id, user_id),
				KEY user_status (user_id, status),
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
				PRIMARY KEY  (id),
				KEY conv_date (conversation_id, created_at),
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
}

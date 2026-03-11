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

	const CURRENT_VERSION = 3;
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

		// 9. Media index (flat, no-JOIN query table).
		dbDelta(
			"CREATE TABLE {$prefix}mvs_media_index (
				media_id bigint(20) unsigned NOT NULL,
				post_author bigint(20) unsigned NOT NULL DEFAULT 0,
				media_type varchar(20) NOT NULL DEFAULT '',
				privacy varchar(20) NOT NULL DEFAULT 'public',
				moderation_status varchar(20) NOT NULL DEFAULT 'approved',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (media_id),
				KEY moderation_privacy_date (moderation_status, privacy, created_at),
				KEY author_date (post_author, created_at),
				KEY type_date (media_type, created_at)
			) {$charset_collate};"
		);
	}
}

<?php
/**
 * Reaction service.
 *
 * Manages media reactions (like, love, haha, wow, sad, angry).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Manages media reactions (like, love, haha, wow, sad, angry).
 */
class ReactionService {

	/**
	 * Valid reaction types.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );

	/**
	 * Toggle a reaction on a media item.
	 *
	 * If the user already reacted with the same type, remove it.
	 * If the user reacted with a different type, update it.
	 * If the user has no reaction, add one.
	 *
	 * @param int    $media_id      Media post ID.
	 * @param int    $user_id       User ID.
	 * @param string $reaction_type Reaction type.
	 * @return array{action: string, reaction_type: string|null} Result with action taken.
	 */
	public function toggle( int $media_id, int $user_id, string $reaction_type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, reaction_type FROM {$table} WHERE media_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				$user_id
			)
		);

		if ( $existing ) {
			if ( $existing->reaction_type === $reaction_type ) {
				// Same type — remove reaction.
				$wpdb->delete( $table, array( 'id' => $existing->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->sync_stats( $media_id );

				return array(
					'action'        => 'removed',
					'reaction_type' => null,
				);
			}

			// Different type — update reaction.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'reaction_type' => $reaction_type ),
				array( 'id' => $existing->id ),
				array( '%s' ),
				array( '%d' )
			);

			return array(
				'action'        => 'updated',
				'reaction_type' => $reaction_type,
			);
		}

		// No existing reaction — add new one.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'media_id'      => $media_id,
				'user_id'       => $user_id,
				'reaction_type' => $reaction_type,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$this->sync_stats( $media_id );

		/**
		 * Fires after a reaction is added to a media item.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $media_id      Media ID.
		 * @param int    $user_id       User who reacted.
		 * @param string $reaction_type Reaction type (like, love, etc.).
		 */
		do_action( 'mvs_reaction_added', $media_id, $user_id, $reaction_type );

		return array(
			'action'        => 'added',
			'reaction_type' => $reaction_type,
		);
	}

	/**
	 * Remove a user's reaction from a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return bool True if a reaction was removed.
	 */
	public function remove( int $media_id, int $user_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'media_id' => $media_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( $deleted ) {
			$this->sync_stats( $media_id );

			/**
			 * Fires when a user removes their reaction from a media item.
			 *
			 * @since 1.2.0
			 *
			 * @param int $media_id Media post ID.
			 * @param int $user_id  User ID who removed the reaction.
			 */
			do_action( 'mvs_reaction_removed', $media_id, $user_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Get the current user's reaction for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return string|null Reaction type or null if none.
	 */
	public function get_user_reaction( int $media_id, int $user_id ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT reaction_type FROM {$table} WHERE media_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				$user_id
			)
		);
	}

	/**
	 * When the given user reacted to a media item.
	 *
	 * Backs the app's "you reacted X ago" affordance. Stored UTC
	 * (see toggle(), current_time('mysql', true)); the REST normalizer adds the
	 * ISO-8601 reacted_at_gmt sibling. Uses the media_user_date index.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return string|null UTC datetime (MySQL) or null when the user hasn't reacted.
	 */
	public function get_user_reaction_at( int $media_id, int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		$val = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT created_at FROM {$table} WHERE media_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				$user_id
			)
		);

		return null !== $val ? (string) $val : null;
	}

	/**
	 * Batch-resolve a user's reaction across a set of media.
	 *
	 * One query for a whole page of media instead of one per tile — the
	 * big-site path behind `viewer_reaction` in the media REST response.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $user_id   User ID (0 returns an empty map).
	 * @param int[] $media_ids Media IDs to test.
	 * @return array<int,string> Map of media_id => reaction_type. Absent key = no reaction.
	 */
	public function get_user_reactions_map( int $user_id, array $media_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );
		if ( $user_id <= 0 || empty( $ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'mvs_reactions';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $user_id ), $ids );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, reaction_type FROM {$table} WHERE user_id = %d AND media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['media_id'] ] = (string) $row['reaction_type'];
		}

		return $map;
	}

	/**
	 * Get reaction counts grouped by type for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return array<string, int> Keyed by reaction type.
	 */
	public function get_counts( int $media_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT reaction_type, COUNT(*) as count FROM {$table} WHERE media_id = %d GROUP BY reaction_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id
			),
			ARRAY_A
		);

		$counts = array_fill_keys( self::TYPES, 0 );
		foreach ( $results as $row ) {
			$counts[ $row['reaction_type'] ] = (int) $row['count'];
		}

		return $counts;
	}

	/**
	 * Get total reaction count for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return int
	 */
	public function get_total( int $media_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_reactions';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id
			)
		);
	}

	/**
	 * Sync the reaction count in the stats table and the denormalized index.
	 *
	 * @param int $media_id Media post ID.
	 */
	private function sync_stats( int $media_id ): void {
		global $wpdb;

		$total = $this->get_total( $media_id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'reactions'  => $total,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'media_id' => $media_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Keep the denormalized reaction_count in mvs_media_index in sync, through
		// the repository — Rule 7. `set()` also invalidates the row's request
		// cache, which a raw UPDATE did not: a reaction and a re-read in the same
		// request could disagree about the count.
		\WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->set( $media_id, 'reaction_count', $total );
	}
}

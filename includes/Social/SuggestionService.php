<?php
/**
 * Suggested-follows ("people you may know") service.
 *
 * @package WPMediaVerse
 * @since   1.9.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks creators to suggest a new member follow, to solve the empty-network
 * cold start. Big-site-safe: the global candidate pool (top creators) is cached;
 * per-viewer work is just set-filtering that small pool + an interest boost.
 */
class SuggestionService {

	/**
	 * Cache key for the global candidate pool.
	 */
	const CACHE_KEY = 'mvs_top_creators';

	/**
	 * Candidate pool size (bounded; per-viewer filtering happens on this set).
	 */
	const POOL_SIZE = 300;

	/**
	 * Suggested creators for a viewer, ranked, excluding self/followed/blocked.
	 *
	 * @param int $viewer_id Viewer user ID (0 = anonymous → pure popularity).
	 * @param int $limit     Max suggestions (1-50).
	 * @return array<int, array{user_id:int, follower_count:int}>
	 */
	public function get_suggestions( int $viewer_id, int $limit = 20 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$pool  = $this->candidate_pool(); // [ user_id => follower_count ]

		// Exclude self + already-followed + blocked. The container always has
		// 'follows' and 'reports' registered, so call them directly.
		$exclude = array();
		if ( $viewer_id > 0 ) {
			$exclude[ $viewer_id ] = true;
			$container             = \WPMediaVerse\Core\Plugin::container();
			foreach ( $container->get( 'follows' )->get_following_ids( $viewer_id ) as $fid ) {
				$exclude[ (int) $fid ] = true;
			}
			foreach ( $container->get( 'reports' )->get_blocked_ids( $viewer_id ) as $bid ) {
				$exclude[ (int) $bid ] = true;
			}
		}

		$candidates = array();
		foreach ( $pool as $uid => $followers ) {
			if ( isset( $exclude[ $uid ] ) ) {
				continue;
			}
			$candidates[ $uid ] = $followers;
		}
		if ( empty( $candidates ) ) {
			return array();
		}

		// Interest boost: creators with media in the viewer's interest categories
		// jump to the front (computed on the bounded candidate set only).
		$boost = $this->interest_authors( $viewer_id, array_keys( $candidates ) );

		uksort(
			$candidates,
			function ( $a, $b ) use ( $candidates, $boost ) {
				$ba = isset( $boost[ $a ] ) ? 1 : 0;
				$bb = isset( $boost[ $b ] ) ? 1 : 0;
				if ( $ba !== $bb ) {
					return $bb - $ba;
				}
				return $candidates[ $b ] <=> $candidates[ $a ];
			}
		);

		$out = array();
		foreach ( array_slice( $candidates, 0, $limit, true ) as $uid => $followers ) {
			$out[] = array(
				'user_id'        => (int) $uid,
				'follower_count' => (int) $followers,
			);
		}
		return $out;
	}

	/**
	 * Cached global candidate pool: top creators by followers, supplemented by
	 * top creators by public-media count (so a young site with few follows still
	 * has people to suggest). Keyed user_id => follower_count.
	 *
	 * @return array<int,int>
	 */
	private function candidate_pool(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$pool = array();

		// 1. Top by follower count.
		$followed = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT following_id AS uid, COUNT(*) AS c
				FROM {$wpdb->prefix}mvs_follows WHERE status = 'active'
				GROUP BY following_id ORDER BY c DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::POOL_SIZE
			),
			ARRAY_A
		);
		foreach ( (array) $followed as $row ) {
			$pool[ (int) $row['uid'] ] = (int) $row['c'];
		}

		// 2. Supplement with prolific public creators (followers default 0).
		$creators = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_author
				FROM {$wpdb->prefix}mvs_media_index
				WHERE status = 'publish' AND moderation_status = 'approved' AND privacy = 'public' AND post_author > 0
				GROUP BY post_author ORDER BY COUNT(*) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::POOL_SIZE
			)
		);
		foreach ( (array) $creators as $uid ) {
			$uid = (int) $uid;
			if ( ! isset( $pool[ $uid ] ) ) {
				$pool[ $uid ] = 0;
			}
		}

		/**
		 * Suggested-creators pool cache TTL (seconds). 0 disables caching.
		 *
		 * @since 1.9.0
		 *
		 * @param int $ttl Default 1 hour.
		 */
		$ttl = (int) apply_filters( 'mvs_suggestions_cache_ttl', HOUR_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( self::CACHE_KEY, $pool, $ttl );
		}
		return $pool;
	}

	/**
	 * Of the given candidate authors, which have media in the viewer's interests.
	 *
	 * @param int   $viewer_id     Viewer user ID.
	 * @param int[] $candidate_ids Candidate author IDs.
	 * @return array<int,bool> Map author_id => true.
	 */
	private function interest_authors( int $viewer_id, array $candidate_ids ): array {
		if ( $viewer_id <= 0 || empty( $candidate_ids ) ) {
			return array();
		}
		$interests = get_user_meta( $viewer_id, 'mvs_interests', true );
		$interests = is_array( $interests ) ? array_filter( array_map( 'intval', $interests ) ) : array();
		if ( empty( $interests ) ) {
			return array();
		}

		global $wpdb;
		// term_id -> term_taxonomy_id for mvs_category.
		$ti_ph = implode( ',', array_fill( 0, count( $interests ), '%d' ) );
		$tt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'mvs_category' AND term_id IN ({$ti_ph})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_values( $interests )
			)
		);
		$tt_ids = array_filter( array_map( 'intval', (array) $tt_ids ) );
		if ( empty( $tt_ids ) ) {
			return array();
		}

		$cand_ph = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );
		$tt_ph   = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );
		$rows    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT i.post_author
				FROM {$wpdb->prefix}mvs_media_index i
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = i.media_id AND tr.term_taxonomy_id IN ({$tt_ph})
				WHERE i.privacy = 'public' AND i.moderation_status = 'approved' AND i.post_author IN ({$cand_ph})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_merge( array_values( $tt_ids ), array_map( 'intval', $candidate_ids ) )
			)
		);

		$map = array();
		foreach ( (array) $rows as $uid ) {
			$map[ (int) $uid ] = true;
		}
		return $map;
	}
}

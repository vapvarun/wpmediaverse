<?php
/**
 * CPT / media ID-collision analyser.
 *
 * Albums (and historically collections) store attributes by calling
 * MediaRepository::set() with their `wp_posts` ID. That column is
 * AUTO_INCREMENT for real media, so two independent ID sequences share one
 * primary key and collide wherever the integers coincide.
 *
 * This service is READ-ONLY. It reports what a site actually has so the repair
 * can be planned against numbers rather than assumptions. The repair itself
 * lands separately — see plan/2026-08-08-cpt-id-collision-fix-plan.md.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only analyser for album/collection IDs colliding with media IDs.
 *
 * @since 2.4.0
 */
class CptIdCollisionService {

	/**
	 * Post types that write into mvs_media_index using their own post ID.
	 *
	 * @var string[]
	 */
	private const CPT_TYPES = array( 'mvs_album', 'mvs_collection' );

	/**
	 * Taxonomies whose object_id space is shared between posts and media rows.
	 *
	 * @var string[]
	 */
	private const TAXONOMIES = array( 'mvs_category', 'mvs_tag' );

	/**
	 * The integrity queries this report is built from.
	 *
	 * Every SQL statement this class used to carry now lives in
	 * `Repository\MediaIntegrityRepository` (architecture invariant 6, coding
	 * Rule 7). What stays here is the part that is actually this class's job:
	 * deciding what the numbers MEAN — whether the window is dangerous, what
	 * the verdict sentence should say, which rows a human has to look at.
	 *
	 * @return \WPMediaVerse\Repository\MediaIntegrityRepository
	 */
	private function queries(): \WPMediaVerse\Repository\MediaIntegrityRepository {
		return new \WPMediaVerse\Repository\MediaIntegrityRepository();
	}

	/**
	 * Run every check and return a structured report.
	 *
	 * Performs no writes of any kind.
	 *
	 * @since 2.4.0
	 *
	 * @return array{
	 *     totals: array<string,int>,
	 *     forecast: array<string,mixed>,
	 *     collisions: array<int,array<string,mixed>>,
	 *     privacy_only: array<int,array<string,mixed>>,
	 *     slug_overwrites: array<int,array<string,mixed>>,
	 *     meta_rows: array<int,array<string,mixed>>,
	 *     purge_risk: array<int,array<string,mixed>>,
	 *     taxonomy: array<int,array<string,mixed>>
	 * }
	 */
	public function analyze(): array {
		return array(
			'totals'          => $this->totals(),
			'forecast'        => $this->forecast(),
			'collisions'      => $this->collisions(),
			'privacy_only'    => $this->privacy_only_rows(),
			'slug_overwrites' => $this->slug_overwrites(),
			'meta_rows'       => $this->cpt_meta_rows(),
			'purge_risk'      => $this->purge_risk(),
			'taxonomy'        => $this->taxonomy_spread(),
		);
	}

	/**
	 * How likely the NEXT album created on this site is to corrupt a media item.
	 *
	 * This is the number that matters day to day. Existing collisions are damage
	 * already done; the forecast is damage still to come.
	 *
	 * On a media plugin the two sequences drift apart in a predictable direction:
	 * members upload far more media than the site creates posts, so
	 * mvs_media_index.media_id outruns wp_posts.ID. Every new album is therefore
	 * allocated an ID that lands *inside* the already-populated media range, and
	 * writing the album's attributes overwrites whatever media row is sitting
	 * there. The busier the site, the further ahead media IDs are, and the higher
	 * the odds — this defect gets worse with success, not better.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string,mixed>
	 */
	private function forecast(): array {
		global $wpdb;

		$queries = $this->queries();

		$next_post  = $queries->next_auto_increment( $wpdb->posts );
		$next_media = $queries->next_auto_increment( $queries->index_table_name() );

		// Post IDs already past the media sequence — new albums land on unused
		// ground and cannot collide until uploads catch up again.
		if ( $next_post <= 0 || $next_media <= 0 || $next_post >= $next_media ) {
			return array(
				'next_post_id'   => $next_post,
				'next_media_id'  => $next_media,
				'window'         => 0,
				'occupied'       => 0,
				'risk_percent'   => 0.0,
				'albums_at_risk' => 0,
				'verdict'        => 'Post IDs are ahead of media IDs — new albums cannot collide right now.',
			);
		}

		$window = $next_media - $next_post;

		$occupied = $queries->count_rows_from_id( $next_post );

		$risk = $window > 0 ? round( 100 * $occupied / $window, 1 ) : 0.0;

		return array(
			'next_post_id'   => $next_post,
			'next_media_id'  => $next_media,
			'window'         => $window,
			'occupied'       => $occupied,
			'risk_percent'   => $risk,
			'albums_at_risk' => $window,
			'verdict'        => sprintf(
				'The next %d album(s) created here each have a ~%s%% chance of overwriting a real media item.',
				$window,
				$risk
			),
		);
	}

	/**
	 * Headline counts.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string,int>
	 */
	private function totals(): array {
		return $this->queries()->cpt_collision_totals( self::CPT_TYPES );
	}

	/**
	 * Index rows that are a real media item AND share an ID with a CPT post.
	 *
	 * These are unrecoverable in the sense that the CPT's own attributes were
	 * written over the media row. The media data is real and must never be
	 * deleted; the repair preserves them and reports them for a human.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collisions(): array {
		return $this->queries()->cpt_collisions( self::CPT_TYPES );
	}

	/**
	 * Index rows that belong only to a CPT — safe to migrate and remove.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function privacy_only_rows(): array {
		return $this->queries()->cpt_privacy_only_rows( self::CPT_TYPES );
	}

	/**
	 * Media rows whose slug was overwritten by a CPT sharing its ID.
	 *
	 * Detected by the index slug matching the CPT's post_name. The original
	 * media slug is not recoverable from the database.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function slug_overwrites(): array {
		return $this->queries()->cpt_slug_overwrites( self::CPT_TYPES );
	}

	/**
	 * mvs_media_meta rows keyed by a CPT post ID.
	 *
	 * Album keys such as album_type / group_id / *_album_id are not index columns, so
	 * MediaRepository::set() falls them through to the media meta store.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cpt_meta_rows(): array {
		return $this->queries()->cpt_meta_rows( self::CPT_TYPES );
	}

	/**
	 * CPT posts whose permanent deletion would destroy a real media row.
	 *
	 * Album::on_before_delete() and Collection::on_before_delete() call
	 * purge_index_record( $post_id ) unconditionally. Where that ID is a real
	 * media row, deleting the CPT deletes the media item's index record.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function purge_risk(): array {
		return $this->queries()->cpt_purge_risk( self::CPT_TYPES );
	}

	/**
	 * Per-taxonomy split of wp_term_relationships object_id space.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function taxonomy_spread(): array {
		return $this->queries()->cpt_taxonomy_spread( self::CPT_TYPES, self::TAXONOMIES );
	}
}

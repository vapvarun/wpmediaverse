<?php
/**
 * CPT / media ID-collision analyser.
 *
 * Legacy albums (and collections) stored attributes by calling
 * MediaRepository::set() with their `wp_posts` ID. That column is
 * AUTO_INCREMENT for real media, so two independent ID sequences share one
 * primary key and collide wherever the integers coincide.
 *
 * This service is READ-ONLY. It reports where the album/collection and media
 * ID ranges overlap on a site; the data has been kept apart since 2.4.0.
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
	 * Every SQL statement for this report lives in
	 * `Repository\MediaIntegrityRepository` (architecture invariant 6, coding
	 * Rule 7). What stays here is the part that is actually this class's job:
	 * deciding what the numbers mean and which rows a human has to look at.
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
			'collisions'      => $this->collisions(),
			'privacy_only'    => $this->privacy_only_rows(),
			'slug_overwrites' => $this->slug_overwrites(),
			'meta_rows'       => $this->cpt_meta_rows(),
			'purge_risk'      => $this->purge_risk(),
			'taxonomy'        => $this->taxonomy_spread(),
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

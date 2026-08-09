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

		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next_post = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->posts
			)
		);

		$next_media = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . 'mvs_media_index'
			)
		);
		// phpcs:enable

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$occupied = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE media_id >= %d", $next_post )
		);
		// phpcs:enable

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
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->cpt_placeholders();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cpt_posts = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types})", self::CPT_TYPES )
		);

		$cpt_indexed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				 WHERE p.post_type IN ({$types})",
				self::CPT_TYPES
			)
		);

		$colliding = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				 WHERE p.post_type IN ({$types}) AND m.media_type <> ''",
				self::CPT_TYPES
			)
		);

		$media_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE media_type <> ''" );
		// phpcs:enable

		return array(
			'cpt_posts'       => $cpt_posts,
			'cpt_indexed'     => $cpt_indexed,
			'collisions'      => $colliding,
			'privacy_only'    => $cpt_indexed - $colliding,
			'real_media_rows' => $media_rows,
		);
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
		return $this->cpt_rows(
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        p.post_author AS cpt_author, p.post_name AS cpt_slug,
			        m.media_type, m.title AS media_title, m.slug AS index_slug,
			        m.privacy, m.post_author AS media_author, m.file_path',
			"AND m.media_type <> ''"
		);
	}

	/**
	 * Index rows that belong only to a CPT — safe to migrate and remove.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function privacy_only_rows(): array {
		return $this->cpt_rows(
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        m.privacy, m.slug AS index_slug',
			"AND m.media_type = ''"
		);
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
		return $this->cpt_rows(
			'p.ID AS cpt_id, p.post_title AS cpt_title, p.post_name AS cpt_slug,
			        m.title AS media_title, m.slug AS index_slug',
			"AND m.media_type <> '' AND m.slug = p.post_name"
		);
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
		global $wpdb;

		$meta  = $wpdb->prefix . 'mvs_media_meta';
		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->cpt_placeholders();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS cpt_id, p.post_type, mm.meta_key, mm.meta_value,
				        CASE WHEN mi.media_type IS NOT NULL AND mi.media_type <> ''
				             THEN 1 ELSE 0 END AS shares_with_media
				   FROM {$meta} mm
				   INNER JOIN {$wpdb->posts} p ON p.ID = mm.media_id
				   LEFT JOIN {$index} mi ON mi.media_id = mm.media_id
				  WHERE p.post_type IN ({$types})
				  ORDER BY p.ID, mm.meta_key",
				self::CPT_TYPES
			),
			ARRAY_A
		);
		// phpcs:enable
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
		return $this->cpt_rows(
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        m.title AS media_at_risk, m.file_path, m.post_author AS media_author',
			"AND m.media_type <> '' AND m.file_path IS NOT NULL"
		);
	}

	/**
	 * Per-taxonomy split of wp_term_relationships object_id space.
	 *
	 * @since 2.4.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function taxonomy_spread(): array {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->cpt_placeholders();
		$taxes = implode( ', ', array_fill( 0, count( self::TAXONOMIES ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.taxonomy,
				        COUNT(*) AS relationships,
				        SUM(CASE WHEN p.ID IS NOT NULL THEN 1 ELSE 0 END) AS on_cpt_post,
				        SUM(CASE WHEN m.media_id IS NOT NULL AND m.media_type <> '' THEN 1 ELSE 0 END) AS on_media,
				        SUM(CASE WHEN p.ID IS NOT NULL AND m.media_id IS NOT NULL AND m.media_type <> '' THEN 1 ELSE 0 END) AS ambiguous
				   FROM {$wpdb->term_relationships} tr
				   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				   LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type IN ({$types})
				   LEFT JOIN {$index} m ON m.media_id = tr.object_id
				  WHERE tt.taxonomy IN ({$taxes})
				  GROUP BY tt.taxonomy",
				array_merge( self::CPT_TYPES, self::TAXONOMIES )
			),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Run one CPT-joined report query.
	 *
	 * Every detail section asks the same shape of question — "join mvs_media_index to
	 * the album/collection posts sharing its IDs, then narrow" — so the join, the
	 * post-type placeholders and the ARRAY_A shaping live here once.
	 *
	 * @since 2.4.0
	 *
	 * @param string $select      Column list for the SELECT.
	 * @param string $extra_where Additional AND-conditions, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	private function cpt_rows( string $select, string $extra_where = '' ): array {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->cpt_placeholders();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select}
				   FROM {$index} m
				   INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				  WHERE p.post_type IN ({$types}) {$extra_where}
				  ORDER BY p.ID",
				self::CPT_TYPES
			),
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Placeholder list for the CPT type IN() clause.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	private function cpt_placeholders(): string {
		return implode( ', ', array_fill( 0, count( self::CPT_TYPES ), '%s' ) );
	}
}

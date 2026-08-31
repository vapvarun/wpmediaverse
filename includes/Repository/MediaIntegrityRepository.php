<?php
/**
 * Integrity and repair queries against `mvs_media_index`.
 *
 * A SIBLING of MediaRepository, not a subset of it, and the split is
 * deliberate. MediaRepository answers "what media is there" for the product —
 * listings, single items, counts, writes — and caches accordingly. The queries
 * here ask the opposite question: which rows are WRONG. They exist to find
 * absolute paths that should be relative, thumbnails stranded on local disk
 * after a cloud migration, and index rows whose id was overwritten by an album
 * sharing the same integer.
 *
 * Two reasons they are not methods on MediaRepository:
 *
 *   1. That class is already 4,900 lines and 90 public methods. Nine more
 *      diagnostic queries whose only callers are a repair sweep and a WP-CLI
 *      report would make the most-read class in the plugin harder to read for
 *      the benefit of the two paths least often executed.
 *   2. These reads must NOT go through the row cache or the privacy handling
 *      that MediaRepository correctly applies. An audit that reads its subject
 *      through a cache is an audit of the cache. `count_absolute_file_paths()`
 *      has to see what is on disk in the table, not what the request has
 *      already decided about it.
 *
 * It is still the repository LAYER, which is what architecture invariant 6 and
 * coding Rule 7 are actually about: SQL against this table lives in
 * `includes/Repository/`, reviewable in one directory, rather than scattered
 * across services, controllers and CLI commands. The Rule 7 allowlist names
 * this file for that reason.
 *
 * READ-ONLY. Nothing here writes. Repairs are performed by the callers through
 * MediaRepository, so every write still goes through one place.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostic queries over the media index.
 *
 * @since 2.4.0
 */
class MediaIntegrityRepository {

	/**
	 * Meta keys holding a thumbnail URL.
	 *
	 * @var string[]
	 */
	private const THUMB_KEYS = array( 'thumb_large', 'thumb_medium', 'thumb_thumb' );

	/**
	 * The uploads-directory marker used to tell a local URL from a cloud one.
	 *
	 * @var string
	 */
	private const LOCAL_URL_LIKE = '%/wp-content/uploads/%';

	// ------------------------------------------------------------ storage --

	/**
	 * How many rows store an ABSOLUTE `file_path` (state D).
	 *
	 * `LEFT(file_path,1) = '/'` is a literal comparison, not a LIKE wildcard —
	 * `file_path` is an unindexed TEXT column either way, so this is a scan
	 * whichever form it takes and the literal is the honest one.
	 *
	 * @since 2.4.0
	 *
	 * @return int
	 */
	public function count_absolute_file_paths(): int {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE status IN ('publish','draft') AND LEFT(file_path,1) = '/'" );
	}

	/**
	 * Is ANY public cloud-located media still pointing at a local thumbnail?
	 *
	 * State C, and bounded on purpose: the caller only needs to know whether a
	 * repair sweep is worth scheduling, so this stops at the first hit rather
	 * than counting a set nobody displays.
	 *
	 * The signal is precise rather than probabilistic. Thumbnail URL meta is
	 * written at generation time, and a proper cloud upload or migration records
	 * a cloud URL — so a LOCAL thumbnail URL on a row whose original is already
	 * on cloud can only mean the thumbnails were left behind. No network call,
	 * and no false positive on healthy cloud media.
	 *
	 * @since 2.4.0
	 *
	 * @return bool
	 */
	public function has_stranded_public_thumbnail(): bool {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$meta  = $wpdb->prefix . 'mvs_media_meta';
		$keys  = implode( ', ', array_fill( 0, count( self::THUMB_KEYS ), '%s' ) );

		$sql = "SELECT i.media_id
		          FROM {$index} i
		          JOIN {$meta} m ON m.media_id = i.media_id AND m.meta_key IN ({$keys})
		         WHERE i.status IN ('publish','draft')
		           AND i.privacy = 'public'
		           AND LEFT(i.file_path,1) != '/'
		           AND i.file_url != ''
		           AND i.file_url NOT LIKE %s
		           AND m.meta_value LIKE %s
		         LIMIT 1";

		$params = array_merge( self::THUMB_KEYS, array( self::LOCAL_URL_LIKE, self::LOCAL_URL_LIKE ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return ! empty( $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) );
	}

	/**
	 * Next batch of ids needing storage repair, after a keyset cursor.
	 *
	 * Targeted rather than a full walk: healthy rows are never visited, which is
	 * what makes this affordable to run as a background sweep on a large
	 * library. Two conditions are OR'd because a single row can be broken either
	 * way and must not be returned twice — hence the DISTINCT.
	 *
	 * @since 2.4.0
	 *
	 * @param int  $cursor   Exclusive lower bound on media_id.
	 * @param bool $is_cloud Whether a cloud driver is active. When it is not,
	 *                       the stranded-thumbnail half cannot apply — there is
	 *                       nowhere for a file to be stranded FROM.
	 * @param int  $limit    Batch size.
	 * @return int[]
	 */
	public function storage_repair_candidate_ids( int $cursor, bool $is_cloud, int $limit ): array {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$meta  = $wpdb->prefix . 'mvs_media_meta';
		$limit = max( 1, $limit );

		if ( ! $is_cloud ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT media_id FROM {$index}
					  WHERE status IN ('publish','draft') AND media_id > %d AND LEFT(file_path,1) = '/'
					  ORDER BY media_id ASC LIMIT %d",
					$cursor,
					$limit
				)
			);

			return array_map( 'intval', (array) $ids );
		}

		$keys = implode( ', ', array_fill( 0, count( self::THUMB_KEYS ), '%s' ) );

		$sql = "SELECT DISTINCT i.media_id
		          FROM {$index} i
		     LEFT JOIN {$meta} m ON m.media_id = i.media_id AND m.meta_key IN ({$keys})
		         WHERE i.status IN ('publish','draft')
		           AND i.media_id > %d
		           AND (
		                LEFT(i.file_path,1) = '/'
		                OR ( i.privacy = 'public' AND LEFT(i.file_path,1) != '/' AND i.file_url != '' AND i.file_url NOT LIKE %s AND m.meta_value LIKE %s )
		           )
		      ORDER BY i.media_id ASC
		         LIMIT %d";

		$params = array_merge(
			self::THUMB_KEYS,
			array( $cursor, self::LOCAL_URL_LIKE, self::LOCAL_URL_LIKE, $limit )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) );
	}

	// --------------------------------------------------------- collisions --

	/**
	 * The next value a table's AUTO_INCREMENT will hand out.
	 *
	 * Reads `information_schema`, not the table — this asks about the SEQUENCE,
	 * which is the whole subject of the collision forecast: album ids and media
	 * ids are two independent sequences sharing one primary key, and the
	 * question is where they are about to overlap.
	 *
	 * @since 2.4.0
	 *
	 * @param string $table Full table name.
	 * @return int 0 when unknown (some hosts restrict information_schema).
	 */
	public function next_auto_increment( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
	}

	/**
	 * The media-index table name, for callers asking about its sequence.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	public function index_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'mvs_media_index';
	}

	/**
	 * How many index rows already occupy ids at or above a given point.
	 *
	 * The numerator of the collision forecast: within the window between the
	 * next post id and the next media id, this is how many landing spots are
	 * already taken by a real media item.
	 *
	 * @since 2.4.0
	 *
	 * @param int $media_id Lower bound, inclusive.
	 * @return int
	 */
	public function count_rows_from_id( int $media_id ): int {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE media_id >= %d", $media_id ) );
	}

	/**
	 * Headline collision counts.
	 *
	 * `media_type <> ''` is what separates a REAL media row from an
	 * attribute-only row an album wrote: albums never set a media type, so a row
	 * that has one and also shares an id with a post is a photo whose record was
	 * overwritten.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types that write into the index.
	 * @return array<string,int>
	 */
	public function cpt_collision_totals( array $cpt_types ): array {
		global $wpdb;

		$index  = $wpdb->prefix . 'mvs_media_index';
		$types  = $this->placeholders( $cpt_types );
		$counts = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['cpt_posts'] = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types})", $cpt_types )
		);

		$counts['cpt_indexed'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				 WHERE p.post_type IN ({$types})",
				$cpt_types
			)
		);

		$counts['collisions'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				 WHERE p.post_type IN ({$types}) AND m.media_type <> ''",
				$cpt_types
			)
		);

		$counts['real_media_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE media_type <> ''" );
		// phpcs:enable

		$counts['privacy_only'] = $counts['cpt_indexed'] - $counts['collisions'];

		return $counts;
	}

	/**
	 * Index rows that are real media AND share an id with a CPT post.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_collisions( array $cpt_types ): array {
		return $this->cpt_rows(
			$cpt_types,
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        p.post_author AS cpt_author, p.post_name AS cpt_slug,
			        m.media_type, m.title AS media_title, m.slug AS index_slug,
			        m.privacy, m.post_author AS media_author, m.file_path',
			"AND m.media_type <> ''"
		);
	}

	/**
	 * Index rows belonging only to a CPT — safe to migrate and remove.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_privacy_only_rows( array $cpt_types ): array {
		return $this->cpt_rows(
			$cpt_types,
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        m.privacy, m.slug AS index_slug',
			"AND m.media_type = ''"
		);
	}

	/**
	 * Media rows whose slug was overwritten by a CPT sharing its id.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_slug_overwrites( array $cpt_types ): array {
		return $this->cpt_rows(
			$cpt_types,
			'p.ID AS cpt_id, p.post_title AS cpt_title, p.post_name AS cpt_slug,
			        m.title AS media_title, m.slug AS index_slug',
			"AND m.media_type <> '' AND m.slug = p.post_name"
		);
	}

	/**
	 * CPT posts whose permanent deletion would destroy a real media row.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_purge_risk( array $cpt_types ): array {
		return $this->cpt_rows(
			$cpt_types,
			'p.ID AS cpt_id, p.post_type, p.post_title AS cpt_title,
			        m.title AS media_at_risk, m.file_path, m.post_author AS media_author',
			"AND m.media_type <> '' AND m.file_path IS NOT NULL"
		);
	}

	/**
	 * `mvs_media_meta` rows keyed by a CPT post id.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types Post types.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_meta_rows( array $cpt_types ): array {
		global $wpdb;

		$meta  = $wpdb->prefix . 'mvs_media_meta';
		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->placeholders( $cpt_types );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				$cpt_types
			),
			ARRAY_A
		);
	}

	/**
	 * Per-taxonomy split of the `wp_term_relationships` object_id space.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $cpt_types  Post types.
	 * @param string[] $taxonomies Taxonomies to report on.
	 * @return array<int,array<string,mixed>>
	 */
	public function cpt_taxonomy_spread( array $cpt_types, array $taxonomies ): array {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->placeholders( $cpt_types );
		$taxes = $this->placeholders( $taxonomies );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				array_merge( $cpt_types, $taxonomies )
			),
			ARRAY_A
		);
	}

	/**
	 * One CPT-joined report query.
	 *
	 * Every detail section asks the same shape — join the index to the album and
	 * collection posts sharing its ids, then narrow — so the join and the
	 * placeholders live here once.
	 *
	 * `$select` and `$extra_where` are INTERNAL fragments, built from the
	 * literals in this class's own methods above and never from a caller. They
	 * stay strings because a column list is not something `prepare()` can bind;
	 * keeping this method private is what makes that safe, and it is why the
	 * public methods each hard-code their own SELECT rather than accepting one.
	 *
	 * @param string[] $cpt_types   Post types.
	 * @param string   $select      Column list.
	 * @param string   $extra_where Additional AND-conditions, or ''.
	 * @return array<int,array<string,mixed>>
	 */
	private function cpt_rows( array $cpt_types, string $select, string $extra_where = '' ): array {
		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		$types = $this->placeholders( $cpt_types );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select}
				   FROM {$index} m
				   INNER JOIN {$wpdb->posts} p ON p.ID = m.media_id
				  WHERE p.post_type IN ({$types}) {$extra_where}
				  ORDER BY p.ID",
				$cpt_types
			),
			ARRAY_A
		);
	}

	/**
	 * `%s` placeholder list for an IN() clause.
	 *
	 * @param array $values Values that will be bound.
	 * @return string
	 */
	private function placeholders( array $values ): string {
		return implode( ', ', array_fill( 0, max( 1, count( $values ) ), '%s' ) );
	}
}

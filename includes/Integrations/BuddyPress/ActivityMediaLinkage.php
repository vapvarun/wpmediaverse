<?php
/**
 * Linkage service for BP activity ↔ MVS media.
 *
 * Phase 8 (1.2.0): structured replacement for regex-parsing
 * `bp_activity.content` HTML to find MVS media references. The previous
 * approach (`ActivityContentIntegration::enhance_activity_media_content`)
 * shipped 4+ regressions in 1.1.x — every change to BP activity HTML
 * risked re-breaking the parse step. This service writes one linkage
 * row per media reference at activity-save time and emits markup at
 * render time directly from those rows. Zero regex on saved HTML.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 * @since   1.2.0
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepositoryInterface;
use WPMediaVerse\Core\TemplateHelpersInterface;

/**
 * Owns the `mvs_bp_activity_media` linkage table and the activity-render
 * markup it produces. Layer 4 (service) per the layered-architecture
 * guideline — no HTTP, no $_REQUEST parsing, no `wp_send_json_*()`.
 * Returns / writes structured data only.
 */
class ActivityMediaLinkage {

	/**
	 * Allowed `variant` values. Anything else is silently coerced to 'image'
	 * so a malformed caller can never poison the table.
	 *
	 * @var string[]
	 */
	private const VARIANTS = array( 'image', 'video', 'audio', 'document' );

	private MediaRepositoryInterface $repo;
	private TemplateHelpersInterface $tpl;

	public function __construct( MediaRepositoryInterface $repo, TemplateHelpersInterface $tpl ) {
		$this->repo = $repo;
		$this->tpl  = $tpl;
	}

	/**
	 * Register hooks. Called from `Plugin::register_services` factory.
	 */
	public function init(): void {
		// Pull linkage rows from the activity row's expected metadata at
		// save-time. We piggy-back on bp_activity_after_save (priority 10);
		// the activity's content is already finalized by then.
		add_action( 'bp_activity_after_save', array( $this, 'on_activity_save' ), 20 );

		// On activity delete, drop the linkage rows.
		//
		// `bp_activity_deleted_activities` and not `bp_before_activity_delete`:
		// the "before" action carries the delete QUERY, so it only has an ['id']
		// when the caller happened to delete by id. BuddyPress deletes by
		// user_id / item_id / component / type all the time (user deletion,
		// group deletion, comment cascades) and those left every linkage row
		// behind - measured 18 of 26 rows orphaned on the QA site. BuddyPress's
		// own docblock on bp_activity_delete() says to use this one when you
		// want the IDs. It also fires AFTER the delete succeeded, so a failed
		// delete no longer drops links.
		add_action( 'bp_activity_deleted_activities', array( $this, 'on_activity_delete' ) );
	}

	/**
	 * Persist linkage rows for an activity that references MVS media.
	 *
	 * Two write paths feed in:
	 * 1. Upload-driven activities (mvs_media_upload type) where the media
	 *    IDs are already attached to the activity row as `_mvs_media_ids`
	 *    meta (set by ActivitySyncIntegration during the upload sync).
	 * 2. User-composed updates that include MVS media; those carry
	 *    `data-mvs-media-id` attributes in the saved content. The save
	 *    handler is expected to expose them via the `mvs_activity_media_ids`
	 *    filter so we don't have to regex-parse here.
	 *
	 * @param object|int $activity BP activity object or ID.
	 */
	public function on_activity_save( $activity ): void {
		$activity_id = is_object( $activity ) ? (int) $activity->id : (int) $activity;
		if ( $activity_id <= 0 ) {
			return;
		}

		$media_ids = $this->resolve_media_ids_for_activity( $activity_id, $activity );
		if ( empty( $media_ids ) ) {
			return;
		}

		// Replace any existing linkage rows for this activity (covers the
		// edit-then-resave case + idempotent re-runs).
		$this->delete_links( $activity_id );

		$position = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;
			if ( $media_id <= 0 || ! $this->repo->exists( $media_id ) ) {
				continue;
			}
			$variant = $this->resolve_variant( $media_id );
			$this->insert_link( $activity_id, $media_id, $variant, $position );
			++$position;
		}
	}

	/**
	 * Drop linkage rows when the parent activity is deleted.
	 *
	 * Accepts either shape: the deleted-id list from
	 * `bp_activity_deleted_activities` (an int or an int[]), or the legacy
	 * `$args` array this used to be wired to, so anything still calling it
	 * directly keeps working.
	 *
	 * @param array|int $activity_ids Deleted activity id(s), or legacy delete args.
	 */
	public function on_activity_delete( $activity_ids ): void {
		if ( is_array( $activity_ids ) && isset( $activity_ids['id'] ) ) {
			$activity_ids = $activity_ids['id'];
		}
		foreach ( (array) $activity_ids as $activity_id ) {
			$activity_id = (int) $activity_id;
			if ( $activity_id > 0 ) {
				$this->delete_links( $activity_id );
			}
		}
	}

	/**
	 * Render MVS media markup for an activity from the linkage table.
	 *
	 * Returns an empty string when the activity has no linkage rows so
	 * `ActivityContentIntegration::enhance_activity_media_content` can
	 * fall back to its legacy regex parser for pre-Phase-8 rows.
	 *
	 * @param int $activity_id BP activity ID.
	 * @return string Rendered HTML, or '' when no linkage rows exist.
	 */
	public function render( int $activity_id ): string {
		if ( $activity_id <= 0 ) {
			return '';
		}
		$rows = $this->get_links( $activity_id );
		if ( empty( $rows ) ) {
			return '';
		}

		$mvs_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$pieces = array();
		foreach ( $rows as $row ) {
			$mvs_media_id = (int) $row->media_id;

			// Skip media that can no longer be served. An activity entry outlives
			// the media it embeds: the row can be trashed (reversible, so the
			// activity must NOT be deleted for it) or gone entirely with a
			// linkage row left behind, and either way the tile rendered a signed
			// URL that answered 403 - neither hidden nor honest. Guarding the
			// renderer covers trash, hard delete and legacy orphans in one place;
			// deleting activities on trash would destroy a post that restoring
			// the media should bring back. Basecamp 10285850126.
			if ( ! $mvs_repo->exists( $mvs_media_id ) ) {
				continue;
			}
			if ( 'publish' !== (string) $mvs_repo->get( $mvs_media_id, 'status' ) ) {
				continue;
			}

			$pieces[] = $this->tpl->media_thumbnail(
				$mvs_media_id,
				array(
					'size'      => 'large',
					// Long-lived broadcast TTL — activity content lives in
					// bp_activity.content for months; the per-request 1h
					// signed URL would 403 by the next page-view.
					'ttl'       => YEAR_IN_SECONDS,
					'user_id'   => 0,
					'show_play' => true,
				)
			);
		}

		// Every linked media is gone or unpublished: render nothing rather than
		// an empty grid wrapper.
		if ( empty( $pieces ) ) {
			return '';
		}

		// One wrapper around the per-media blocks so the existing
		// `.mvs-activity-media` CSS picks them up unchanged.
		$count_class = 'mvs-activity-media-group--count-' . count( $pieces );
		return '<div class="mvs-activity-media-group ' . esc_attr( $count_class ) . '"'
			. ' data-mvs-activity-id="' . esc_attr( (string) $activity_id ) . '">'
			. implode( '', $pieces )
			. '</div>';
	}

	/**
	 * Whether an activity has any linkage rows. Used by
	 * `ActivityContentIntegration::enhance_activity_media_content` to
	 * decide between the structured render path and the legacy regex
	 * fallback.
	 */
	public function has_links( int $activity_id ): bool {
		if ( $activity_id <= 0 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_bp_activity_media WHERE activity_id = %d",
				$activity_id
			)
		);
		return $count > 0;
	}

	/**
	 * BP activity ids that reference a media item — the indexed reverse
	 * lookup this table exists to make possible.
	 *
	 * Added for `ActivitySyncIntegration::clean_activities_for_media()`,
	 * which previously found these via a `LIKE '%,123,%'` scan across the
	 * entire `bp_activity_meta` table on every media delete — unindexed and
	 * unbounded by activity count. `media_id` carries its own KEY (Migrator
	 * v12); `object_type` scopes the shared table (Migrator v16 opened it up
	 * to non-BP objects, e.g. `bn_post`) so a numeric id collision with
	 * another object type can never be mistaken for a BP activity.
	 *
	 * @since 2.6.0
	 *
	 * @param int $media_id Media id.
	 * @return int[] Distinct activity ids (no ordering promised).
	 */
	public function activity_ids_for_media( int $media_id ): array {
		if ( $media_id <= 0 ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT activity_id FROM {$wpdb->prefix}mvs_bp_activity_media WHERE media_id = %d AND object_type = 'bp_activity'",
				$media_id
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the list of media IDs an activity references.
	 *
	 * Filterable so callers (composer, group post, third-party) can supply
	 * media IDs without going through activity meta.
	 *
	 * @param int    $activity_id BP activity ID.
	 * @param object $activity    Activity object passed by BP (may be a partial).
	 * @return int[]
	 */
	private function resolve_media_ids_for_activity( int $activity_id, $activity ): array {
		// Path 1: upload-flow stash. ActivitySyncIntegration writes
		// `_mvs_media_ids` (CSV string) for upload activities. The function
		// guard makes the service safe to construct in BP-absent contexts
		// (unit tests, headless boots).
		$ids = array();
		if ( function_exists( 'bp_activity_get_meta' ) ) {
			$stashed = bp_activity_get_meta( $activity_id, '_mvs_media_ids', true );
			if ( $stashed ) {
				$ids = array_filter( array_map( 'intval', explode( ',', (string) $stashed ) ) );
			}
		}

		/**
		 * Filter the resolved media IDs for an activity, before linkage
		 * rows are written.
		 *
		 * Composer / group-post integrations should hook here to surface
		 * MVS media IDs they've collected from the form, instead of
		 * forcing this service to regex-parse saved content.
		 *
		 * @param int[]  $ids         Resolved media IDs.
		 * @param int    $activity_id BP activity ID.
		 * @param object $activity    Activity object.
		 */
		return (array) apply_filters( 'mvs_activity_media_ids', $ids, $activity_id, $activity );
	}

	/**
	 * Pick the linkage variant for a media item from its media_type.
	 */
	private function resolve_variant( int $media_id ): string {
		$type = (string) $this->repo->get_raw( $media_id, 'media_type' );
		return in_array( $type, self::VARIANTS, true ) ? $type : 'image';
	}

	/**
	 * INSERT a single linkage row. Wrapped so tests can mock + so future
	 * batch-insert optimizations live in one place.
	 */
	private function insert_link( int $activity_id, int $media_id, string $variant, int $position ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'mvs_bp_activity_media',
			array(
				'activity_id' => $activity_id,
				'media_id'    => $media_id,
				'variant'     => $variant,
				'position'    => $position,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Drop all linkage rows for an activity.
	 */
	private function delete_links( int $activity_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . 'mvs_bp_activity_media',
			array( 'activity_id' => $activity_id ),
			array( '%d' )
		);
	}

	/**
	 * Read linkage rows for an activity, ordered by position.
	 *
	 * @param int $activity_id BP activity ID.
	 * @return array<int,object>
	 */
	private function get_links( int $activity_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, activity_id, media_id, variant, position FROM {$wpdb->prefix}mvs_bp_activity_media WHERE activity_id = %d ORDER BY position ASC, id ASC",
				$activity_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}

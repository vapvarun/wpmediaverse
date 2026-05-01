<?php
/**
 * ActivitySyncIntegration — activity recording and sync for BuddyPress.
 *
 * Handles recording upload activities, syncing media comments to activity
 * comments, reassigning activities to groups, and updating activities when
 * media is added to albums.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Records and manages BuddyPress activity entries for WPMediaVerse media events.
 */
class ActivitySyncIntegration {

	/**
	 * Media IDs that already had activity recorded via mvs_media_uploaded.
	 *
	 * @var array<int, bool>
	 */
	private $recorded_uploads = array();

	/**
	 * Whether UploadService is currently processing an upload.
	 *
	 * Set by mvs_before_media_insert, cleared by mvs_media_uploaded.
	 *
	 * @var bool
	 */
	private $upload_in_progress = false;

	/**
	 * Static flag — prevents re-entry when bp_activity_new_comment fires hooks.
	 *
	 * @var bool
	 */
	private static $posting_to_activity = false;

	/**
	 * Register all activity sync hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		add_action( 'mvs_before_media_insert', array( $this, 'mark_upload_in_progress' ) );
		add_action( 'mvs_media_uploaded', array( $this, 'flag_activity_upload' ), 5 );
		add_action( 'mvs_media_uploaded', array( $this, 'record_upload_activity' ) );
		add_action( 'mvs_media_deleted', array( $this, 'clean_activities_for_media' ), 10, 2 );
		add_action( 'mvs_comment_created', array( $this, 'sync_media_comment_to_activity' ), 10, 5 );
		add_action( 'mvs_album_items_added', array( $this, 'update_activity_with_album' ), 10, 3 );
		add_action( 'mvs_media_group_assigned', array( $this, 'reassign_activity_to_group' ), 10, 2 );
		add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );
	}

	/**
	 * Register custom activity actions so they appear in BP filter dropdown.
	 */
	public function register_activity_actions(): void {
		bp_activity_set_action(
			'wpmediaverse',
			'mvs_media_upload',
			__( 'Media Uploads', 'wpmediaverse' ),
			array( $this, 'format_activity_action_upload' ),
			__( 'Media Uploads', 'wpmediaverse' ),
			array( 'activity', 'member', 'group' )
		);

		// Register the same type under groups component so group-scoped uploads format correctly.
		if ( bp_is_active( 'groups' ) ) {
			bp_activity_set_action(
				'groups',
				'mvs_media_upload',
				__( 'Group Media Uploads', 'wpmediaverse' ),
				array( $this, 'format_activity_action_upload' ),
				__( 'Group Media Uploads', 'wpmediaverse' ),
				array( 'activity', 'member', 'group' )
			);
		}

		bp_activity_set_action(
			'wpmediaverse',
			'mvs_comment',
			__( 'Media Comments', 'wpmediaverse' ),
			array( $this, 'format_activity_action_comment' ),
			__( 'Media Comments', 'wpmediaverse' ),
			array( 'activity', 'member' )
		);
	}

	/**
	 * Format the upload activity action string.
	 *
	 * @param string $action   Existing action string.
	 * @param object $activity Activity object.
	 * @return string
	 */
	public function format_activity_action_upload( $action, $activity ) {
		// Multi-image activity posts (via activity form) use bp_activity type 'activity_update',
		// not 'mvs_media_upload', so this only handles single-upload activities.

		// For group-scoped activities, item_id is the group ID and secondary_item_id is the media ID.
		// For personal activities, item_id is the media ID.
		$is_group = ( 'groups' === $activity->component && $activity->secondary_item_id > 0 );
		$media_id = $is_group ? (int) $activity->secondary_item_id : (int) $activity->item_id;

		if ( ! MediaRepository::exists( $media_id ) ) {
			// Always return a valid action string — empty strings crash BP Nouveau's strpos().
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				// translators: %s: linked user name.
				? sprintf( __( '%s uploaded new media', 'wpmediaverse' ), $user_link )
				: __( 'A member uploaded new media', 'wpmediaverse' );
		}
		$file_type  = MediaRepository::get( $media_id, 'file_type' );
		$type_label = MediaDisplayHelper::get_media_type_label( $file_type );
		$user_link  = bp_core_get_userlink( $activity->user_id );

		// Build group context suffix if applicable.
		$group_suffix = '';
		if ( $is_group && function_exists( 'groups_get_group' ) ) {
			$group = groups_get_group( (int) $activity->item_id );
			if ( $group && ! empty( $group->name ) ) {
				$group_link   = '<a href="' . esc_url( bp_get_group_url( $group ) ) . '">' . esc_html( $group->name ) . '</a>';
				$group_suffix = sprintf(
					/* translators: %s: group link */
					__( ' in the group %s', 'wpmediaverse' ),
					$group_link
				);
			}
		}

		// Check if this media belongs to an album.
		$album_id = (int) MediaRepository::get( $media_id, 'album_id' );
		if ( $album_id ) {
			$album = get_post( $album_id );
			if ( $album && 'mvs_album' === $album->post_type ) {
				$album_link = '<a href="' . esc_url( get_permalink( $album_id ) ) . '">' . esc_html( $album->post_title ) . '</a>';
				return sprintf(
					/* translators: 1: user link, 2: media type, 3: album link */
					__( '%1$s uploaded a new %2$s to album %3$s', 'wpmediaverse' ),
					$user_link,
					esc_html( $type_label ),
					$album_link
				) . $group_suffix;
			}
		}

		// Clean action: "varundubey uploaded a new photo" — no filename/hash.
		return sprintf(
			/* translators: 1: user link, 2: media type (photo, video, audio file) */
			__( '%1$s uploaded a new %2$s', 'wpmediaverse' ),
			$user_link,
			esc_html( $type_label )
		) . $group_suffix;
	}

	/**
	 * Format the comment activity action string.
	 *
	 * @param string $action   Existing action string.
	 * @param object $activity Activity object.
	 * @return string
	 */
	public function format_activity_action_comment( $action, $activity ) {
		$media_id = (int) $activity->item_id;
		if ( ! MediaRepository::exists( $media_id ) ) {
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				// translators: %s: linked user name.
				? sprintf( __( '%s commented on a media item', 'wpmediaverse' ), $user_link )
				: __( 'A member commented on a media item', 'wpmediaverse' );
		}
		$media_title = MediaRepository::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		return sprintf(
			/* translators: 1: user link, 2: media link */
			__( '%1$s commented on %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			'<a href="' . esc_url( MediaRepository::get_permalink( $media_id ) ) . '">' . esc_html( $media_title ) . '</a>'
		);
	}

	/**
	 * Mark that UploadService is currently running.
	 *
	 * Called by mvs_before_media_insert (fires before media insert),
	 * so duplicate activity creation is prevented.
	 */
	public function mark_upload_in_progress(): void {
		$this->upload_in_progress = true;
	}

	/**
	 * Flag media uploaded via BP activity form so record_upload_activity skips it.
	 * Also clears the upload_in_progress flag since mvs_media_uploaded has fired.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function flag_activity_upload( int $media_id ): void {
		// Mark as coming through UploadService — prevents duplicate activity.
		$this->recorded_uploads[ $media_id ] = true;
		$this->upload_in_progress            = false;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['context'] ) && 'activity' === sanitize_key( wp_unslash( $_GET['context'] ) ) ) {
			MediaRepository::set( $media_id, 'activity_upload', '1' );
		}
	}

	/**
	 * Record activity when media is uploaded.
	 *
	 * Skips if the upload was via the BP activity form (context=activity),
	 * since those are bundled into a single activity post by attach_media_to_activity().
	 *
	 * @param int $media_id Media post ID.
	 */
	public function record_upload_activity( int $media_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_activity_add' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		// Skip if this media was uploaded via the BP activity form.
		if ( MediaRepository::get( $media_id, 'activity_upload' ) ) {
			return;
		}

		// Skip imported media — their original source activity is preserved and rendered via transform_legacy_media_content().
		if ( MediaRepository::get( $media_id, 'imported_media' ) ) {
			return;
		}

		if ( ! MediaRepository::exists( $media_id ) ) {
			return;
		}

		$user_id   = MediaRepository::get_author( $media_id );
		$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );

		// Build action string at insert time (format callback regenerates on display,
		// but storing it prevents empty-action crashes in BP Nouveau's strpos()).
		$file_type  = MediaRepository::get( $media_id, 'file_type' );
		$type_label = MediaDisplayHelper::get_media_type_label( $file_type );
		$action_str = sprintf(
			/* translators: 1: user link, 2: media type */
			__( '%1$s uploaded a new %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $user_id ),
			esc_html( $type_label )
		);

		$activity_args = array(
			'user_id'   => $user_id,
			'component' => 'wpmediaverse',
			'type'      => 'mvs_media_upload',
			'action'    => $action_str,
			'content'   => $thumbnail,
			'item_id'   => $media_id,
		);

		// If media belongs to a group, record activity in the group stream.
		$mvs_group_id = (int) MediaRepository::get( $media_id, 'group_id' );
		if ( $mvs_group_id > 0 && bp_is_active( 'groups' ) ) {
			$activity_args['component']         = 'groups';
			$activity_args['item_id']           = $mvs_group_id;
			$activity_args['secondary_item_id'] = $media_id;
		}

		$activity_id                         = bp_activity_add( $activity_args );
		$this->recorded_uploads[ $media_id ] = true;

		// Store the activity ID on the media post for easy lookup/updates.
		if ( $activity_id ) {
			MediaRepository::set( $media_id, 'bp_activity_id', $activity_id );
		}
	}

	/**
	 * No-op. Previously created activity on publish_mvs_media hook.
	 *
	 * Media no longer uses a CPT, so the publish_mvs_media hook never fires.
	 * Kept as a stub for backwards compatibility.
	 *
	 * @deprecated 1.2.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function maybe_record_publish_activity( int $post_id, \WP_Post $post ): void {
		// No-op: media no longer uses wp_posts. Activity is created via mvs_media_uploaded.
	}

	/**
	 * Remove BP activities that reference a deleted media item.
	 *
	 * Two kinds of activity entries can end up pointing at a media row:
	 *
	 *  1. The standalone "upload" activity recorded by record_upload_activity().
	 *     Its id is back-referenced on the media row via the `bp_activity_id`
	 *     key, and it carries `item_id = media_id` (or `secondary_item_id = media_id`
	 *     for group uploads) so we can find it even if the back-reference is
	 *     missing (e.g. legacy imports).
	 *
	 *  2. "post_update" activities posted via the BP activity form that
	 *     bundled this media. `ActivityFormIntegration::attach_media_to_activity()`
	 *     stores the attached media IDs in activity meta `_mvs_media_ids`.
	 *     If the deleted media was the only attachment, the activity becomes
	 *     a broken-thumbnail shell and should go; if it was one of several,
	 *     we just strip the id from the meta list so the activity re-renders
	 *     with the remaining tiles.
	 *
	 * Without this cleanup, group activity streams leak broken cards after
	 * an owner uses the delete action in the profile/group media grid.
	 *
	 * @param int $media_id  Media row id that was deleted.
	 * @param int $author_id Author id (passed for symmetry; unused here).
	 */
	public function clean_activities_for_media( int $media_id, int $author_id ): void {
		unset( $author_id ); // Reserved for future per-author hardening.

		if ( ! function_exists( 'bp_activity_delete' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		// 1. Standalone upload activity via back-reference.
		$stored_activity_id = (int) MediaRepository::get( $media_id, 'bp_activity_id' );
		if ( $stored_activity_id > 0 ) {
			bp_activity_delete( array( 'id' => $stored_activity_id ) );
		}

		// 2. Any other activities still pointing at this media via item_id or
		// secondary_item_id (covers legacy rows where the back-ref was lost).
		bp_activity_delete(
			array(
				'type'    => 'mvs_media_upload',
				'item_id' => $media_id,
			)
		);
		bp_activity_delete(
			array(
				'component'         => 'groups',
				'type'              => 'mvs_media_upload',
				'secondary_item_id' => $media_id,
			)
		);

		// 3. post_update activities with this id in `_mvs_media_ids` meta.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$activity_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT activity_id FROM {$wpdb->prefix}bp_activity_meta WHERE meta_key = %s AND ( meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )",
				'_mvs_media_ids',
				(string) $media_id,
				'%,' . $wpdb->esc_like( (string) $media_id ),
				$wpdb->esc_like( (string) $media_id ) . ',%',
				'%,' . $wpdb->esc_like( (string) $media_id ) . ',%'
			)
		);

		foreach ( array_map( 'intval', (array) $activity_ids ) as $activity_id ) {
			if ( $activity_id <= 0 ) {
				continue;
			}
			$raw       = bp_activity_get_meta( $activity_id, '_mvs_media_ids', true );
			$ids       = array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) );
			$remaining = array_values( array_diff( $ids, array( $media_id ) ) );

			if ( empty( $remaining ) ) {
				// This media was the only attachment — drop the whole activity
				// rather than leaving a post with a broken thumbnail.
				bp_activity_delete( array( 'id' => $activity_id ) );
				continue;
			}

			// Keep the activity but update the attached-media list so the
			// template helper renders only the surviving tiles.
			bp_activity_update_meta( $activity_id, '_mvs_media_ids', implode( ',', $remaining ) );
		}
	}

	/**
	 * Reassign an upload activity to a group after group_id meta is set.
	 *
	 * The mvs_media_uploaded hook fires inside UploadService before the REST
	 * controller sets _mvs_group_id, so the activity is initially recorded
	 * under the wpmediaverse component. This method retroactively moves it
	 * to the groups component so it appears in the group activity stream.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $group_id Group ID.
	 */
	public function reassign_activity_to_group( int $media_id, int $group_id ): void {
		if ( ! function_exists( 'bp_activity_get' ) || ! bp_is_active( 'groups' ) ) {
			return;
		}

		$activity = $this->find_media_upload_activity( $media_id );
		if ( ! $activity ) {
			return;
		}

		// Update the activity to belong to the group.
		$GLOBALS['wpdb']->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			buddypress()->activity->table_name,
			array(
				'component'         => 'groups',
				'item_id'           => $group_id,
				'secondary_item_id' => $media_id,
				'action'            => '', // Force regeneration.
			),
			array( 'id' => $activity->id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		// Clear cached action.
		bp_activity_update_meta( $activity->id, 'bp_activity_cached_action', '' );
	}

	/**
	 * Update existing upload activities when media items are added to an album.
	 *
	 * The action string is regenerated by format_activity_action_upload() which
	 * reads _mvs_album_id from post meta, so we just need to clear the cached
	 * action to force regeneration.
	 *
	 * @param int   $album_id  Album post ID.
	 * @param array $media_ids Media post IDs.
	 * @param int   $added     Number of items added.
	 */
	public function update_activity_with_album( int $album_id, array $media_ids, int $added ): void {
		if ( ! function_exists( 'bp_activity_get' ) ) {
			return;
		}

		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;
			$activity = $this->find_media_upload_activity( $media_id );
			if ( $activity ) {
				// Clear the cached action so it regenerates with album link.
				bp_activity_update_meta( $activity->id, 'bp_activity_cached_action', '' );
				// Force action regeneration by setting it empty.
				$GLOBALS['wpdb']->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					buddypress()->activity->table_name,
					array( 'action' => '' ),
					array( 'id' => $activity->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * One-way sync: media comment → BP activity comment.
	 *
	 * Works for both single-media and multi-media activities:
	 * - 1 media activity: comments on that media → activity comments
	 * - N media activity: comments on ANY of those media → same activity comments
	 *
	 * No reverse sync (BP→media) — prevents infinite loops entirely.
	 *
	 * @param int    $media_id   Media ID.
	 * @param int    $user_id    Commenter user ID.
	 * @param int    $comment_id Comment ID.
	 * @param string $content    Comment content.
	 * @param string $source     Source of the comment.
	 */
	public function sync_media_comment_to_activity( int $media_id, int $user_id, int $comment_id, string $content, string $source = '' ): void {
		// Skip if this comment was created by a BP→media sync (prevents loops).
		if ( 'bp_activity' === $source ) {
			return;
		}

		// Skip if we're already posting to activity (re-entry guard).
		if ( self::$posting_to_activity ) {
			return;
		}

		if ( ! function_exists( 'bp_activity_new_comment' ) ) {
			return;
		}

		// Get the linked BP activity ID for this media.
		$activity_id = (int) MediaRepository::get( $media_id, 'bp_activity_id' );
		if ( ! $activity_id ) {
			return;
		}

		// Verify the activity exists.
		$activity = new \BP_Activity_Activity( $activity_id );
		if ( ! $activity->id ) {
			return;
		}

		self::$posting_to_activity = true;

		bp_activity_new_comment(
			array(
				'activity_id' => $activity_id,
				'content'     => $content,
				'user_id'     => $user_id,
			)
		);

		self::$posting_to_activity = false;
	}

	/**
	 * Find the upload activity for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return object|null Activity row or null.
	 */
	private function find_media_upload_activity( int $media_id ) {
		if ( ! function_exists( 'bp_activity_get' ) ) {
			return null;
		}

		// Fast path: use stored activity ID from post meta.
		$stored_id = (int) MediaRepository::get( $media_id, 'bp_activity_id' );
		if ( $stored_id ) {
			$activity = new \BP_Activity_Activity( $stored_id );
			if ( ! empty( $activity->id ) ) {
				return $activity;
			}
		}

		$activities = bp_activity_get(
			array(
				'filter'   => array(
					'action'     => 'mvs_media_upload',
					'primary_id' => $media_id,
				),
				'per_page' => 1,
				'page'     => 1,
			)
		);

		if ( ! empty( $activities['activities'] ) ) {
			// Cache for next time.
			MediaRepository::set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
			return $activities['activities'][0];
		}

		// Fallback: search by component + item_id for any media-related activity.
		$activities = bp_activity_get(
			array(
				'filter'   => array(
					'object'     => 'wpmediaverse',
					'primary_id' => $media_id,
				),
				'per_page' => 1,
				'page'     => 1,
				'sort'     => 'ASC', // Get the oldest (upload) activity.
			)
		);

		if ( ! empty( $activities['activities'] ) ) {
			MediaRepository::set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
			return $activities['activities'][0];
		}

		return null;
	}
}

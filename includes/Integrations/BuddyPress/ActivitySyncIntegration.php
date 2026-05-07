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
		add_action( 'mvs_media_privacy_changed', array( $this, 'sync_activity_privacy' ), 10, 3 );
		add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );
	}

	/**
	 * Map a single privacy value to BP activity `hide_sitewide`.
	 *
	 * Only `private` (level 80) flips hide_sitewide. Members / Friends /
	 * Group / Custom rely on `ActivityPrivacyFilter`'s viewer-side WHERE
	 * filter to gate per-viewer access; setting hide_sitewide=1 for them
	 * would over-restrict (members-level activities would be invisible to
	 * everyone, including the logged-in members the level is named for).
	 *
	 * Defense in depth: even with the JOIN filter active, private gets
	 * hide_sitewide=1 so admin tools / future BP query paths that bypass
	 * the filter still hide private activities by default.
	 *
	 * @since 1.2.1
	 *
	 * @param string $privacy Privacy value (public|members|private|friends|group|custom).
	 * @return bool True when hide_sitewide should be set.
	 */
	public static function privacy_to_hide_sitewide( string $privacy ): bool {
		return 'private' === $privacy;
	}

	/**
	 * Map a WPMV privacy slug to a numeric level — same scheme as rtMedia's
	 * `rtmedia_privacy` so future rtMedia → WPMV imports preserve intent and
	 * any third-party integration that already speaks this dialect works.
	 *
	 * Stored as `_mvs_activity_privacy` BP activity meta on every activity
	 * we insert, alongside `_mvs_media_ids`. 1.2.1 only WRITES this — the
	 * matching viewer-side SQL filter (compare level to viewer's relationship
	 * to author, hide friends-only from non-friends, etc.) lands in 1.3.0.
	 * Until then `hide_sitewide` carries the visibility weight: any
	 * non-public level → hidden everywhere except the author. The meta is
	 * additive insurance so 1.3.0's filter has the data on day one for
	 * every activity created since 1.2.1.
	 *
	 * @since 1.2.1
	 *
	 * @param string $privacy WPMV privacy slug.
	 * @return int            Numeric level (0 public, 20 members, 40 friends, 60 group, 80 private, 90 custom).
	 */
	public static function privacy_to_level( string $privacy ): int {
		switch ( $privacy ) {
			case 'public':
				return 0;
			case 'members':
				return 20;
			case 'friends':
				return 40;
			case 'group':
				return 60;
			case 'private':
				return 80;
			case 'custom':
				return 90;
			default:
				return 0;
		}
	}

	/**
	 * Effective WPMV privacy slug for a single media — most-restrictive of
	 * (media privacy, parent album privacy). Mirrors `should_hide_for_media`
	 * but returns the slug, not just the binary.
	 *
	 * @since 1.2.1
	 */
	public static function effective_privacy_for_media( int $media_id ): string {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$media_privacy = (string) $repo->get( $media_id, 'privacy' );
		if ( '' === $media_privacy ) {
			$media_privacy = 'public';
		}

		$album_id = (int) $repo->get( $media_id, 'album_id' );
		if ( $album_id <= 0 ) {
			return $media_privacy;
		}

		$album_privacy = (string) $repo->get( $album_id, 'privacy' );
		if ( '' === $album_privacy || 'public' === $album_privacy ) {
			return $media_privacy;
		}

		// Album is non-public. Pick the more restrictive of the two.
		return self::privacy_to_level( $album_privacy ) >= self::privacy_to_level( $media_privacy )
			? $album_privacy
			: $media_privacy;
	}

	/**
	 * Effective WPMV privacy slug across a batch — most-restrictive wins.
	 *
	 * @since 1.2.1
	 *
	 * @param int[] $media_ids Media IDs.
	 */
	public static function effective_privacy_for_batch( array $media_ids ): string {
		$worst = 'public';
		foreach ( $media_ids as $mid ) {
			$slug = self::effective_privacy_for_media( (int) $mid );
			if ( self::privacy_to_level( $slug ) > self::privacy_to_level( $worst ) ) {
				$worst = $slug;
			}
		}
		return $worst;
	}

	/**
	 * Effective hide_sitewide for a single media — considers BOTH media privacy
	 * AND its parent album's privacy. Most-restrictive wins.
	 *
	 * If the media belongs to an album and the album is non-public, the activity
	 * is hidden even when the media itself is public. Same for the inverse.
	 *
	 * @since 1.2.1
	 *
	 * @param int $media_id Media ID (or album ID — albums + media share the table).
	 * @return bool
	 */
	public static function should_hide_for_media( int $media_id ): bool {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$media_privacy = (string) $repo->get( $media_id, 'privacy' );
		if ( self::privacy_to_hide_sitewide( $media_privacy ) ) {
			return true;
		}

		$album_id = (int) $repo->get( $media_id, 'album_id' );
		if ( $album_id > 0 ) {
			$album_privacy = (string) $repo->get( $album_id, 'privacy' );
			if ( self::privacy_to_hide_sitewide( $album_privacy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Effective hide_sitewide across a batch of media — short-circuits on
	 * the first non-public sibling (or non-public album).
	 *
	 * @since 1.2.1
	 *
	 * @param int[] $media_ids Media IDs in the batch.
	 * @return bool
	 */
	public static function should_hide_for_batch( array $media_ids ): bool {
		if ( empty( $media_ids ) ) {
			return false;
		}

		$repo  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$batch = $repo->get_batch( $media_ids );

		$album_ids = array();
		foreach ( $batch as $row ) {
			$row_privacy = isset( $row['privacy'] ) ? (string) $row['privacy'] : '';
			if ( self::privacy_to_hide_sitewide( $row_privacy ) ) {
				return true;
			}
			if ( ! empty( $row['album_id'] ) ) {
				$album_ids[ (int) $row['album_id'] ] = true;
			}
		}

		if ( ! empty( $album_ids ) ) {
			$albums = $repo->get_batch( array_keys( $album_ids ) );
			foreach ( $albums as $album ) {
				$album_privacy = isset( $album['privacy'] ) ? (string) $album['privacy'] : '';
				if ( self::privacy_to_hide_sitewide( $album_privacy ) ) {
					return true;
				}
			}
		}

		return false;
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

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			// Always return a valid action string — empty strings crash BP Nouveau's strpos().
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				// translators: %s: linked user name.
				? sprintf( __( '%s uploaded new media', 'wpmediaverse' ), $user_link )
				: __( 'A member uploaded new media', 'wpmediaverse' );
		}
		$file_type  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
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
		$album_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'album_id' );
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
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				// translators: %s: linked user name.
				? sprintf( __( '%s commented on a media item', 'wpmediaverse' ), $user_link )
				: __( 'A member commented on a media item', 'wpmediaverse' );
		}
		$media_title = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		return sprintf(
			/* translators: 1: user link, 2: media link */
			__( '%1$s commented on %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			'<a href="' . esc_url( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id ) ) . '">' . esc_html( $media_title ) . '</a>'
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
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'activity_upload', '1' );
		}

		// Album bulk-upload batch: when the JS dropzone uploads N files in one
		// user action it sets `?album_upload=1` so each individual upload skips
		// per-media activity creation. update_activity_with_album() emits ONE
		// gallery activity for the batch when the album link call lands.
		// Later additions to the album (same album, different user action) do
		// NOT carry the album_upload flag and behave normally.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['album_upload'] ) && '1' === sanitize_key( wp_unslash( $_GET['album_upload'] ) ) ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'activity_upload', '1' );
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
		if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'activity_upload' ) ) {
			return;
		}

		// Skip imported media — their original source activity is preserved and rendered via transform_legacy_media_content().
		if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'imported_media' ) ) {
			return;
		}

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return;
		}

		$user_id   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id );
		$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );

		// Build action string at insert time (format callback regenerates on display,
		// but storing it prevents empty-action crashes in BP Nouveau's strpos()).
		$file_type  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
		$type_label = MediaDisplayHelper::get_media_type_label( $file_type );
		$action_str = sprintf(
			/* translators: 1: user link, 2: media type */
			__( '%1$s uploaded a new %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $user_id ),
			esc_html( $type_label )
		);

		// Honour media + album privacy at activity creation — non-public media
		// (or media in a non-public album) must not leak the activity card.
		$activity_args = array(
			'user_id'       => $user_id,
			'component'     => 'wpmediaverse',
			'type'          => 'mvs_media_upload',
			'action'        => $action_str,
			'content'       => $thumbnail,
			'item_id'       => $media_id,
			'hide_sitewide' => self::should_hide_for_media( $media_id ),
		);

		// If media belongs to a group, record activity in the group stream.
		$mvs_group_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'group_id' );
		if ( $mvs_group_id > 0 && bp_is_active( 'groups' ) ) {
			$activity_args['component']         = 'groups';
			$activity_args['item_id']           = $mvs_group_id;
			$activity_args['secondary_item_id'] = $media_id;
		}

		$activity_id                         = bp_activity_add( $activity_args );
		$this->recorded_uploads[ $media_id ] = true;

		// Store the activity ID on the media post for easy lookup/updates.
		if ( $activity_id ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'bp_activity_id', $activity_id );
			// Persist the WPMV privacy intent on the activity itself
			// (rtMedia parity). 1.3.0 will read this in a viewer-side
			// query filter; today it's strictly informational.
			$effective = self::effective_privacy_for_media( $media_id );
			bp_activity_update_meta( (int) $activity_id, '_mvs_activity_privacy', $effective );
			bp_activity_update_meta( (int) $activity_id, '_mvs_activity_privacy_level', (string) self::privacy_to_level( $effective ) );
		}
	}

	/**
	 * Sync the linked BP activity's hide_sitewide when a media's privacy changes.
	 *
	 * Listens on `mvs_media_privacy_changed`. The single-upload path stores
	 * `bp_activity_id` on the media (record_upload_activity); the BP composer
	 * path stores it via attach_media_to_activity. When privacy flips later
	 * (e.g. user edits via the dashboard cog modal), this propagates the
	 * change to the activity row so the site-wide stream stays in sync.
	 *
	 * Composer activities can have multiple attached media — we recompute the
	 * most-restrictive privacy across siblings, not the single new value.
	 *
	 * @since 1.2.1
	 *
	 * @param int    $entity_id   Media OR album ID (they share mvs_media_index).
	 * @param string $new_privacy New privacy.
	 * @param string $old_privacy Previous privacy.
	 */
	public function sync_activity_privacy( int $entity_id, string $new_privacy, string $old_privacy = '' ): void {
		unset( $new_privacy, $old_privacy );

		if ( ! class_exists( '\BP_Activity_Activity' ) ) {
			return;
		}

		// Albums share mvs_media_index with media — detect by post type so we can
		// fan out: every media in this album, plus the album-gallery activity if any.
		if ( 'mvs_album' === get_post_type( $entity_id ) ) {
			$this->sync_album_activities( $entity_id );
			return;
		}

		$this->update_single_activity_hide_sitewide( $entity_id );
	}

	/**
	 * Recompute hide_sitewide for the BP activity linked to a single media.
	 * Considers media + album privacy; for composer activities recomputes
	 * across all attached siblings (a single sibling becoming public must
	 * NOT unhide an activity that still has another non-public attachment).
	 *
	 * @param int $media_id Media ID.
	 */
	private function update_single_activity_hide_sitewide( int $media_id ): void {
		$activity_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'bp_activity_id' );
		if ( $activity_id <= 0 ) {
			return;
		}

		$activity = new \BP_Activity_Activity( $activity_id );
		if ( empty( $activity->id ) ) {
			return;
		}

		$attached_csv = bp_activity_get_meta( $activity_id, '_mvs_media_ids', true );
		if ( $attached_csv ) {
			$attached_ids = array_filter( array_map( 'absint', explode( ',', (string) $attached_csv ) ) );
			if ( count( $attached_ids ) > 1 ) {
				$effective_privacy = self::effective_privacy_for_batch( $attached_ids );
			} else {
				$effective_privacy = self::effective_privacy_for_media( $media_id );
			}
		} else {
			$effective_privacy = self::effective_privacy_for_media( $media_id );
		}

		$desired_hide = self::privacy_to_hide_sitewide( $effective_privacy );

		// Always refresh the rtMedia-parity meta so 1.3.0's viewer-side
		// filter sees the current intent regardless of hide_sitewide flip.
		bp_activity_update_meta( $activity_id, '_mvs_activity_privacy', $effective_privacy );
		bp_activity_update_meta( $activity_id, '_mvs_activity_privacy_level', (string) self::privacy_to_level( $effective_privacy ) );

		if ( (bool) $activity->hide_sitewide === $desired_hide ) {
			return;
		}

		$activity->hide_sitewide = $desired_hide ? 1 : 0;
		$activity->save();
	}

	/**
	 * Album-privacy change fan-out: update every activity that links into
	 * this album, both per-media upload activities and the bundled gallery
	 * activity (located by `_mvs_album_id` activity meta).
	 *
	 * @param int $album_id Album ID.
	 */
	private function sync_album_activities( int $album_id ): void {
		global $wpdb;

		// 1. Per-media upload activities for media in this album.
		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE album_id = %d",
				$album_id
			)
		);
		foreach ( $media_ids as $mid ) {
			$this->update_single_activity_hide_sitewide( (int) $mid );
		}

		// 2. The bundled album-gallery activity (emit_album_gallery_activity
		// stores `_mvs_album_id` activity meta when it inserts).
		$gallery_activity_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT activity_id FROM {$wpdb->prefix}bp_activity_meta WHERE meta_key = %s AND meta_value = %s",
				'_mvs_album_id',
				(string) $album_id
			)
		);
		$album_hidden = self::privacy_to_hide_sitewide( (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $album_id, 'privacy' ) );

		foreach ( $gallery_activity_ids as $aid ) {
			$activity = new \BP_Activity_Activity( (int) $aid );
			if ( empty( $activity->id ) ) {
				continue;
			}

			// Album-gallery activity: any non-public attached media OR a
			// non-public album hides the whole gallery. Compute the
			// effective slug too so the rtMedia-parity meta stays accurate.
			$attached_csv = bp_activity_get_meta( (int) $aid, '_mvs_media_ids', true );
			$attached_ids = $attached_csv ? array_filter( array_map( 'absint', explode( ',', (string) $attached_csv ) ) ) : array();

			$album_privacy_slug = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $album_id, 'privacy' );
			if ( '' === $album_privacy_slug ) {
				$album_privacy_slug = 'public';
			}
			$batch_privacy_slug = ! empty( $attached_ids ) ? self::effective_privacy_for_batch( $attached_ids ) : 'public';
			$effective_privacy  = self::privacy_to_level( $album_privacy_slug ) >= self::privacy_to_level( $batch_privacy_slug )
				? $album_privacy_slug
				: $batch_privacy_slug;

			bp_activity_update_meta( (int) $aid, '_mvs_activity_privacy', $effective_privacy );
			bp_activity_update_meta( (int) $aid, '_mvs_activity_privacy_level', (string) self::privacy_to_level( $effective_privacy ) );

			$desired_hide = self::privacy_to_hide_sitewide( $effective_privacy );

			if ( (bool) $activity->hide_sitewide === $desired_hide ) {
				continue;
			}

			$activity->hide_sitewide = $desired_hide ? 1 : 0;
			$activity->save();
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
		$stored_activity_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'bp_activity_id' );
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

		// Track media that came through the album bulk-upload batch path
		// (flag_activity_upload set activity_upload=1 because of `?album_upload=1`).
		// Those have no per-media activity by design; we owe them ONE gallery activity.
		$bundled_ids = array();

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
				continue;
			}

			// No per-media activity AND media has activity_upload=1 → batched.
			if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'activity_upload' ) ) {
				$bundled_ids[] = $media_id;
			}
		}

		if ( ! empty( $bundled_ids ) && function_exists( 'bp_activity_add' ) && bp_is_active( 'activity' ) ) {
			$this->emit_album_gallery_activity( $album_id, $bundled_ids );
		}
	}

	/**
	 * Emit a single grouped "uploaded N photos to album X" activity for a
	 * bulk-upload batch. Reuses the existing thumbnail-grid render path so
	 * the activity feed shows the same media-grid markup as the recovery
	 * path in `ActivityContentIntegration::enhance_activity_media_content`.
	 *
	 * @param int   $album_id  Album post ID.
	 * @param int[] $media_ids Media post IDs in upload order (first = cover).
	 */
	private function emit_album_gallery_activity( int $album_id, array $media_ids ): void {
		$album = get_post( $album_id );
		if ( ! $album || 'mvs_album' !== $album->post_type ) {
			return;
		}

		// Pull the user from the first media item (album.post_author can lag in
		// fresh-install fixtures; the per-media author is the actual uploader).
		$user_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( (int) $media_ids[0] );
		if ( $user_id <= 0 ) {
			$user_id = (int) $album->post_author;
		}
		if ( $user_id <= 0 ) {
			return;
		}

		// Build thumbnail grid (cap at 6 — beyond that is visual noise).
		$shown     = array_slice( $media_ids, 0, 6 );
		$grid_html = '';
		foreach ( $shown as $mid ) {
			$grid_html .= MediaDisplayHelper::get_media_thumbnail_html( (int) $mid, 'large' );
		}
		if ( '' === $grid_html ) {
			return; // Couldn't render any thumbnails — abort rather than ship empty activity.
		}
		$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( count( $shown ), 6 );
		$content    = '<div class="' . esc_attr( $grid_class ) . '">' . $grid_html . '</div>';

		$album_link = '<a href="' . esc_url( get_permalink( $album_id ) ) . '">' . esc_html( $album->post_title ) . '</a>';
		$user_link  = bp_core_get_userlink( $user_id );
		$count      = count( $media_ids );
		$action     = sprintf(
			/* translators: 1: user link, 2: photo count, 3: album link */
			_n(
				'%1$s uploaded %2$d photo to album %3$s',
				'%1$s uploaded %2$d photos to album %3$s',
				$count,
				'wpmediaverse'
			),
			$user_link,
			$count,
			$album_link
		);

		// Most-restrictive privacy across the bundle — any non-public attachment
		// (or non-public parent album) hides the entire gallery activity.
		$album_hidden  = self::privacy_to_hide_sitewide( (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $album_id, 'privacy' ) );
		$hide_sitewide = $album_hidden || self::should_hide_for_batch( $media_ids );

		$activity_id = bp_activity_add(
			array(
				'user_id'           => $user_id,
				'component'         => 'wpmediaverse',
				'type'              => 'mvs_media_upload',
				'action'            => $action,
				'content'           => $content,
				'item_id'           => $album_id,
				'secondary_item_id' => 0,
				'hide_sitewide'     => $hide_sitewide,
			)
		);

		if ( $activity_id && ! is_wp_error( $activity_id ) ) {
			// Persist the media list so ActivityContent's recovery path can
			// reconstruct the grid if `content` is ever lost (matches the
			// existing _mvs_media_ids meta used by the BP composer flow).
			bp_activity_update_meta( $activity_id, '_mvs_media_ids', implode( ',', array_map( 'absint', $media_ids ) ) );
			bp_activity_update_meta( $activity_id, '_mvs_album_id', (int) $album_id );

			// Most-restrictive WPMV privacy across the bundle (rtMedia-parity meta).
			$effective = self::effective_privacy_for_batch( $media_ids );
			bp_activity_update_meta( (int) $activity_id, '_mvs_activity_privacy', $effective );
			bp_activity_update_meta( (int) $activity_id, '_mvs_activity_privacy_level', (string) self::privacy_to_level( $effective ) );

			// Tag every media in the bundle so future album-add calls can
			// find this activity instead of emitting a second one.
			foreach ( $media_ids as $mid ) {
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( (int) $mid, 'bp_activity_id', (int) $activity_id );
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
		$activity_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'bp_activity_id' );
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
		$stored_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'bp_activity_id' );
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
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
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
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
			return $activities['activities'][0];
		}

		return null;
	}
}

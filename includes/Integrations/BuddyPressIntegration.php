<?php
/**
 * BuddyPress integration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Services\MediaMeta;

/**
 * Integrates WPMediaVerse with BuddyPress.
 *
 * - Records activity on media upload, reaction, and comment.
 * - Adds a "Media" tab on user profiles.
 * - Adds a "Media" tab on group pages.
 * - Sends BP notifications for reactions, comments, and mentions.
 */
class BuddyPressIntegration {

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
	 * Initialize the integration.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		// Activity recording (only if activity component is active).
		if ( bp_is_active( 'activity' ) ) {
			add_action( 'mvs_before_media_insert', array( $this, 'mark_upload_in_progress' ) );
			add_action( 'mvs_media_uploaded', array( $this, 'flag_activity_upload' ), 5 );
			add_action( 'mvs_media_uploaded', array( $this, 'record_upload_activity' ) );
			add_action( 'mvs_comment_created', array( $this, 'record_comment_activity' ), 10, 3 );
			add_action( 'mvs_comment_created', array( $this, 'sync_comment_to_activity' ), 10, 5 );
			add_action( 'bp_activity_comment_posted', array( $this, 'sync_activity_comment_to_media' ), 10, 3 );
			add_action( 'mvs_album_items_added', array( $this, 'update_activity_with_album' ), 10, 3 );
			add_action( 'mvs_media_group_assigned', array( $this, 'reassign_activity_to_group' ), 10, 2 );
			add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );

			// Enhance activity content: transform legacy plugin HTML (rtMedia/MediaPress) to MVS
			// rendering. Priority 0: runs before bp_activity_filter_kses (priority 1).
			add_filter( 'bp_get_activity_content_body', array( $this, 'enhance_activity_media_content' ), 0, 2 );

			// Inject media thumbnails into activities with empty content (imported media).
			// Uses bp_activity_entry_content ACTION (not filter) because BP Nouveau skips
			// bp_get_activity_content_body entirely when content is empty.
			add_action( 'bp_activity_entry_content', array( $this, 'render_activity_media_thumbnail' ) );

			// Inject inline video player for MVS video activities.
			add_filter( 'bp_get_activity_content_body', array( $this, 'inject_video_player_in_activity' ), 0, 2 );

			// Whitelist our MVS tags/attrs so kses (priority 1) preserves our transformed output.
			add_filter( 'bp_activity_allowed_tags', array( $this, 'allow_mvs_activity_tags' ) );
		}

		// Profile tab.
		add_action( 'bp_setup_nav', array( $this, 'add_profile_tab' ), 100 );

		// Group tab (only if groups component is active).
		if ( bp_is_active( 'groups' ) ) {
			add_action( 'bp_setup_nav', array( $this, 'add_group_tab' ), 100 );
		}

		// Notifications (only if notifications component is active).
		if ( bp_is_active( 'notifications' ) ) {
			add_action( 'mvs_reaction_added', array( $this, 'notify_reaction' ), 10, 3 );
			add_action( 'mvs_comment_created', array( $this, 'notify_comment' ), 10, 3 );
			add_action( 'mvs_mentions_created', array( $this, 'notify_mentions' ), 10, 2 );
			add_filter( 'bp_notifications_get_registered_components', array( $this, 'register_notification_component' ) );
			add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format_notifications' ), 10, 8 );
			add_action( 'bp_nouveau_notifications_init_filters', array( $this, 'register_notification_filters' ) );
		}

		// Activity post form media attachment (only if activity is active).
		if ( bp_is_active( 'activity' ) ) {
			add_action( 'bp_activity_post_form_options', array( $this, 'activity_post_media_button' ) );
			add_action( 'bp_enqueue_scripts', array( $this, 'enqueue_activity_media_scripts' ) );
			add_action( 'bp_activity_posted_update', array( $this, 'attach_media_to_activity' ), 10, 3 );
			add_action( 'bp_groups_posted_update', array( $this, 'attach_media_to_group_activity' ), 10, 4 );
		}
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

		if ( ! MediaMeta::exists( $media_id ) ) {
			// Always return a valid action string — empty strings crash BP Nouveau's strpos().
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				? sprintf( __( '%s uploaded new media', 'wpmediaverse' ), $user_link )
				: __( 'A member uploaded new media', 'wpmediaverse' );
		}
		$file_type   = MediaMeta::get( $media_id, 'file_type' );
		$type_label  = $this->get_media_type_label( $file_type );
		$media_title = MediaMeta::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		$media_link  = '<a href="' . esc_url( MediaMeta::get_permalink( $media_id ) ) . '">' . esc_html( $media_title ) . '</a>';

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
		$album_id = (int) MediaMeta::get( $media_id, 'album_id' );
		if ( $album_id ) {
			$album = get_post( $album_id );
			if ( $album && 'mvs_album' === $album->post_type ) {
				$album_link = '<a href="' . esc_url( get_permalink( $album_id ) ) . '">' . esc_html( $album->post_title ) . '</a>';
				return sprintf(
					/* translators: 1: user link, 2: media type, 3: media link, 4: album link */
					__( '%1$s uploaded a new %2$s: %3$s in album %4$s', 'wpmediaverse' ),
					bp_core_get_userlink( $activity->user_id ),
					esc_html( $type_label ),
					$media_link,
					$album_link
				) . $group_suffix;
			}
		}

		return sprintf(
			/* translators: 1: user link, 2: media type, 3: media link */
			__( '%1$s uploaded a new %2$s: %3$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			esc_html( $type_label ),
			$media_link
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
		if ( ! MediaMeta::exists( $media_id ) ) {
			$user_link = bp_core_get_userlink( $activity->user_id );
			return $user_link
				? sprintf( __( '%s commented on a media item', 'wpmediaverse' ), $user_link )
				: __( 'A member commented on a media item', 'wpmediaverse' );
		}
		$media_title = MediaMeta::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		return sprintf(
			/* translators: 1: user link, 2: media link */
			__( '%1$s commented on %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			'<a href="' . esc_url( MediaMeta::get_permalink( $media_id ) ) . '">' . esc_html( $media_title ) . '</a>'
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
			MediaMeta::set( $media_id, 'activity_upload', '1' );
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
		if ( MediaMeta::get( $media_id, 'activity_upload' ) ) {
			return;
		}

		// Skip imported media — their original source activity is preserved and rendered via transform_legacy_media_content().
		if ( MediaMeta::get( $media_id, 'imported_media' ) ) {
			return;
		}

		if ( ! MediaMeta::exists( $media_id ) ) {
			return;
		}

		$user_id   = MediaMeta::get_author( $media_id );
		$thumbnail = $this->get_media_thumbnail_html( $media_id, 'large' );

		// Build action string at insert time (format callback regenerates on display,
		// but storing it prevents empty-action crashes in BP Nouveau's strpos()).
		$file_type   = MediaMeta::get( $media_id, 'file_type' );
		$type_label  = $this->get_media_type_label( $file_type );
		$media_title = MediaMeta::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		$media_link  = '<a href="' . esc_url( MediaMeta::get_permalink( $media_id ) ) . '">' . esc_html( $media_title ) . '</a>';
		$action_str = sprintf(
			/* translators: 1: user link, 2: media type, 3: media link */
			__( '%1$s uploaded a new %2$s: %3$s', 'wpmediaverse' ),
			bp_core_get_userlink( $user_id ),
			esc_html( $type_label ),
			$media_link
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
		$mvs_group_id = (int) MediaMeta::get( $media_id, 'group_id' );
		if ( $mvs_group_id > 0 && bp_is_active( 'groups' ) ) {
			$activity_args['component']         = 'groups';
			$activity_args['item_id']           = $mvs_group_id;
			$activity_args['secondary_item_id'] = $media_id;
		}

		$activity_id                         = bp_activity_add( $activity_args );
		$this->recorded_uploads[ $media_id ] = true;

		// Store the activity ID on the media post for easy lookup/updates.
		if ( $activity_id ) {
			MediaMeta::set( $media_id, 'bp_activity_id', $activity_id );
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
	 * Record activity when a comment is created.
	 *
	 * Hook signature: mvs_comment_created( $media_id, $user_id, $comment_id, $content ).
	 *
	 * @param int $media_id   Media post ID.
	 * @param int $user_id    Commenter user ID.
	 * @param int $comment_id Comment ID.
	 */
	public function record_comment_activity( int $media_id, int $user_id, int $comment_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_activity_add' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || ! MediaMeta::exists( $media_id ) ) {
			return;
		}

		// When comment comes from an activity lightbox, we know the exact parent
		// activity ID — thread directly under it without searching.
		if ( defined( 'MVS_COMMENT_FROM_ACTIVITY' ) && MVS_COMMENT_FROM_ACTIVITY > 0 ) {
			bp_activity_new_comment(
				array(
					'activity_id'       => MVS_COMMENT_FROM_ACTIVITY,
					'user_id'           => $user_id,
					'content'           => wp_kses_post( $comment->comment_content ),
					'skip_notification' => true,
				)
			);
			return;
		}

		// Find the parent upload activity for this media item.
		$parent_activity = $this->find_media_upload_activity( $media_id );

		if ( $parent_activity ) {
			// Thread comment under the parent upload activity.
			bp_activity_new_comment(
				array(
					'activity_id' => $parent_activity->id,
					'user_id'     => $user_id,
					'content'     => wp_kses_post( $comment->comment_content ),
				)
			);
		} else {
			// No parent activity found — create a standalone activity as fallback.
			$thumbnail = $this->get_media_thumbnail_html( $media_id, 'thumbnail' );
			$content   = $thumbnail;
			if ( ! empty( $comment->comment_content ) ) {
				$content .= '<div class="mvs-activity-comment-text">' . wp_kses_post( wp_trim_words( $comment->comment_content, 30 ) ) . '</div>';
			}

			bp_activity_add(
				array(
					'user_id'           => $user_id,
					'component'         => 'wpmediaverse',
					'type'              => 'mvs_comment',
					'action'            => '', // Populated by format callback.
					'content'           => $content,
					'item_id'           => $media_id,
					'secondary_item_id' => $comment_id,
				)
			);
		}
	}

	/**
	 * Sync a media comment to the associated BP activity as an activity comment.
	 *
	 * @param int    $media_id   Media ID.
	 * @param int    $user_id    Commenter user ID.
	 * @param int    $comment_id Comment ID.
	 * @param string $content    Comment content.
	 * @param string $source     Source of the comment.
	 */
	public function sync_comment_to_activity( int $media_id, int $user_id, int $comment_id, string $content, string $source = '' ): void {
		// Prevent infinite loop.
		if ( 'bp_activity' === $source ) {
			return;
		}

		if ( ! function_exists( 'bp_activity_new_comment' ) ) {
			return;
		}

		$activity_id = (int) MediaMeta::get( $media_id, 'bp_activity_id' );
		if ( ! $activity_id ) {
			return;
		}

		bp_activity_new_comment( array(
			'activity_id' => $activity_id,
			'content'     => $content,
			'user_id'     => $user_id,
		) );
	}

	/**
	 * Sync a BP activity comment to the associated media item as a media comment.
	 *
	 * @param int    $comment_id      Activity comment ID.
	 * @param array  $params          Comment parameters.
	 * @param object $activity_comment Activity comment object.
	 */
	public function sync_activity_comment_to_media( int $comment_id, array $params, object $activity_comment ): void {
		$activity_id = $params['activity_id'] ?? 0;
		if ( ! $activity_id ) {
			return;
		}

		$raw_ids = bp_activity_get_meta( $activity_id, '_mvs_media_ids', true );
		if ( ! $raw_ids ) {
			return;
		}

		$media_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		if ( empty( $media_ids ) ) {
			return;
		}

		$user_id = $params['user_id'] ?? get_current_user_id();
		$content = $params['content'] ?? '';
		if ( ! $content || ! $user_id ) {
			return;
		}

		// Create comment on the primary media item with 'bp_activity' source to prevent loop.
		$comment_service = new \WPMediaVerse\Social\CommentService();
		$comment_service->add( $media_ids[0], $user_id, $content, 0, 'bp_activity' );
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
		$stored_id = (int) MediaMeta::get( $media_id, 'bp_activity_id' );
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
			MediaMeta::set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
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
			MediaMeta::set( $media_id, 'bp_activity_id', $activities['activities'][0]->id );
			return $activities['activities'][0];
		}

		return null;
	}

	/**
	 * Add a Media tab on the user profile with sub-tabs.
	 */
	public function add_profile_tab(): void {
		if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
			return;
		}

		$user_domain = bp_displayed_user_domain();
		$media_link  = trailingslashit( $user_domain . 'media' );

		// Register nav without count first (displayed user may not be set yet).
		bp_core_new_nav_item(
			array(
				'name'                => __( 'Media', 'wpmediaverse' ),
				'slug'                => 'media',
				'parent_url'          => $media_link,
				'parent_slug'         => buddypress()->profile->slug,
				'screen_function'     => array( $this, 'render_profile_media_tab' ),
				'position'            => 80,
				'default_subnav_slug' => 'all',
			)
		);

		// Inject the media count later when the displayed user is available.
		add_action( 'bp_template_redirect', array( $this, 'update_media_tab_count' ) );

		// Sub-tab: All Media (default).
		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Media', 'wpmediaverse' ),
				'slug'            => 'all',
				'parent_url'      => $media_link,
				'parent_slug'     => 'media',
				'screen_function' => array( $this, 'render_profile_media_tab' ),
				'position'        => 10,
			)
		);

		// Sub-tab: Albums.
		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Albums', 'wpmediaverse' ),
				'slug'            => 'albums',
				'parent_url'      => $media_link,
				'parent_slug'     => 'media',
				'screen_function' => array( $this, 'render_profile_albums_tab' ),
				'position'        => 20,
			)
		);
	}

	/**
	 * Update the Media tab name with the media count (runs on bp_template_redirect).
	 */
	public function update_media_tab_count(): void {
		if ( ! bp_is_user() ) {
			return;
		}

		$displayed_user_id = bp_displayed_user_id();
		if ( ! $displayed_user_id ) {
			return;
		}

		global $wpdb;
		$media_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$displayed_user_id
			)
		);

		if ( $media_count > 0 ) {
			$nav_name = sprintf(
				/* translators: %s: media count */
				__( 'Media', 'wpmediaverse' ) . ' <span class="count">%s</span>',
				$media_count
			);
			buddypress()->members->nav->edit_nav( array( 'name' => $nav_name ), 'media' );
		}
	}

	/**
	 * Render the profile media sub-tab.
	 */
	public function render_profile_media_tab(): void {
		add_action( 'bp_template_content', array( $this, 'profile_media_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Render the profile albums sub-tab.
	 * Supports single album view via /members/{user}/media/albums/{album-slug}/
	 */
	public function render_profile_albums_tab(): void {
		$album_slug = bp_action_variable( 0 );
		if ( $album_slug ) {
			add_action( 'bp_template_content', array( $this, 'profile_single_album_content' ) );
		} else {
			add_action( 'bp_template_content', array( $this, 'profile_albums_content' ) );
		}
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Output media grid for the displayed user's profile.
	 */
	public function profile_media_content(): void {
		// Ensure frontend CSS is loaded.
		wp_enqueue_style( 'mvs-frontend' );

		$user_id  = bp_displayed_user_id();
		$is_own   = is_user_logged_in() && get_current_user_id() === $user_id;
		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 18;

		// Inline upload for own profile.
		if ( $is_own ) {
			$rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
			$nonce    = wp_create_nonce( 'wp_rest' );

			?>
			<div class="mvs-bp-profile-actions">
				<button type="button" id="mvs-bp-upload-btn" class="mvs-btn">
					<span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Upload Media', 'wpmediaverse' ); ?>
				</button>
			</div>

			<div class="mvs-bp-upload-wrap" id="mvs-bp-upload-wrap" style="display:none;">
				<input type="file" multiple accept="image/*,video/*,audio/*" class="mvs-bp-file-input" id="mvs-bp-file-input" style="display:none" />
				<div class="mvs-bp-dropzone" id="mvs-bp-dropzone">
					<span class="dashicons dashicons-cloud-upload"></span>
					<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
				</div>
				<div id="mvs-bp-upload-preview" class="mvs-bp-upload-preview"></div>
				<div class="mvs-bp-upload-status" id="mvs-bp-upload-status" style="display:none;"></div>
				<div class="mvs-bp-upload-form-actions">
					<button type="button" id="mvs-bp-upload-cancel" class="mvs-btn mvs-btn-secondary"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				</div>
			</div>
			<script>
			(function(){
				var restUrl = '<?php echo esc_js( $rest_url ); ?>';
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var uploadBtn = document.getElementById('mvs-bp-upload-btn');
				var uploadWrap = document.getElementById('mvs-bp-upload-wrap');
				var dropzone = document.getElementById('mvs-bp-dropzone');
				var fileInput = document.getElementById('mvs-bp-file-input');
				var statusEl = document.getElementById('mvs-bp-upload-status');
				var previewEl = document.getElementById('mvs-bp-upload-preview');
				var cancelBtn = document.getElementById('mvs-bp-upload-cancel');

				uploadBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'block';
					uploadBtn.style.display = 'none';
				});

				cancelBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'none';
					uploadBtn.style.display = '';
					previewEl.textContent = '';
					statusEl.style.display = 'none';
				});

				var clicking = false;
				dropzone.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					if (clicking) return;
					clicking = true;
					fileInput.click();
					setTimeout(function() { clicking = false; }, 100);
				});
				dropzone.addEventListener('dragover', function(e) {
					e.preventDefault();
					dropzone.classList.add('mvs-bp-dropzone--active');
				});
				dropzone.addEventListener('dragleave', function() {
					dropzone.classList.remove('mvs-bp-dropzone--active');
				});
				dropzone.addEventListener('drop', function(e) {
					e.preventDefault();
					dropzone.classList.remove('mvs-bp-dropzone--active');
					handleFiles(Array.from(e.dataTransfer.files));
				});
				fileInput.addEventListener('change', function() {
					handleFiles(Array.from(fileInput.files));
					fileInput.value = '';
				});

				function handleFiles(files) {
					if (!files.length) return;

					// Show thumbnails preview.
					files.forEach(function(file) {
						if (!file.type.match(/^image\//)) return;
						var reader = new FileReader();
						reader.onload = function(e) {
							var thumb = document.createElement('div');
							thumb.className = 'mvs-bp-upload-thumb';
							var img = document.createElement('img');
							img.src = e.target.result;
							img.alt = file.name;
							var name = document.createElement('span');
							name.className = 'mvs-bp-upload-thumb-name';
							name.textContent = file.name;
							thumb.appendChild(img);
							thumb.appendChild(name);
							previewEl.appendChild(thumb);
						};
						reader.readAsDataURL(file);
					});

					uploadFiles(files);
				}

				function uploadFiles(files) {
					statusEl.style.display = 'block';
					var total = files.length, done = 0, failed = 0;
					statusEl.textContent = 'Uploading 1 of ' + total + '...';
					statusEl.className = 'mvs-bp-upload-status';

					function next() {
						if (done >= total) {
							var uploaded = total - failed;
							statusEl.textContent = uploaded + ' file(s) uploaded!';
							statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
							setTimeout(function() { window.location.reload(); }, 800);
							return;
						}
						var fd = new FormData();
						fd.append('file', files[done]);
						fetch(restUrl + 'media', {
							method: 'POST',
							headers: { 'X-WP-Nonce': nonce },
							credentials: 'same-origin',
							body: fd
						}).then(function(r) {
							if (!r.ok) failed++;
							done++;
							if (done < total) statusEl.textContent = 'Uploading ' + (done + 1) + ' of ' + total + '...';
							next();
						}).catch(function() {
							failed++;
							done++;
							next();
						});
					}
					next();
				}
			})();
			</script>
			<?php
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';
		$offset = ( $paged - 1 ) * $per_page;

		$total_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_author = %d AND status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		if ( ! $total_count ) {
			echo '<div class="mvs-empty-state">';
			echo '<span class="dashicons dashicons-format-gallery"></span>';
			if ( $is_own ) {
				echo '<p>' . esc_html__( 'You haven\'t uploaded any media yet. Get started!', 'wpmediaverse' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This user hasn\'t uploaded any media yet.', 'wpmediaverse' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE post_author = %d AND status = 'publish' ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$per_page,
				$offset
			)
		);
		$media_ids = array_map( 'intval', $media_ids );

		// Collect IDs for batch stats query.
		$stats_map = \WPMediaVerse\Core\TemplateHelpers::bulk_get_stats( $media_ids );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		foreach ( $media_ids as $mid ) {
			\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
				$mid,
				$stats_map[ $mid ] ?? array(),
				array( 'show_author' => true )
			);
		}
		echo '</div>';

		// Pagination.
		$max_pages = (int) ceil( $total_count / $per_page );
		if ( $max_pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'    => add_query_arg( 'mpage', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $max_pages,
				)
			);
			if ( $pagination ) {
				echo '<div class="mvs-pagination">' . wp_kses_post( $pagination ) . '</div>';
			}
		}
	}

	/**
	 * Output albums grid for the displayed user's profile.
	 */
	public function profile_albums_content(): void {
		// Ensure frontend CSS is loaded.
		wp_enqueue_style( 'mvs-frontend' );

		$user_id  = bp_displayed_user_id();
		$is_own   = is_user_logged_in() && get_current_user_id() === $user_id;
		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 18;

		// Action buttons for own profile.
		if ( $is_own ) {
			echo '<div class="mvs-bp-profile-actions">';
			echo '<button type="button" id="mvs-bp-create-album-btn" class="mvs-btn">';
			echo '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__( 'Create Album', 'wpmediaverse' );
			echo '</button>';
			echo '</div>';

			// Inline album creation form (hidden by default).
			echo '<div id="mvs-bp-album-form" class="mvs-bp-album-form" style="display:none;">';
			echo '<div class="mvs-bp-album-form-inner">';
			echo '<label for="mvs-bp-album-title">' . esc_html__( 'Album Name', 'wpmediaverse' ) . '</label>';
			echo '<input type="text" id="mvs-bp-album-title" placeholder="' . esc_attr__( 'Enter album name...', 'wpmediaverse' ) . '" />';
			echo '<label for="mvs-bp-album-desc">' . esc_html__( 'Description (optional)', 'wpmediaverse' ) . '</label>';
			echo '<textarea id="mvs-bp-album-desc" rows="2" placeholder="' . esc_attr__( 'Album description...', 'wpmediaverse' ) . '"></textarea>';
			echo '<div class="mvs-bp-album-form-actions">';
			echo '<button type="button" id="mvs-bp-album-save" class="mvs-btn mvs-btn-primary">' . esc_html__( 'Create', 'wpmediaverse' ) . '</button>';
			echo '<button type="button" id="mvs-bp-album-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
			echo '</div>';
			echo '<div id="mvs-bp-album-msg" class="mvs-bp-album-msg"></div>';
			echo '</div>';
			echo '</div>';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_album',
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<div class="mvs-empty-state">';
			echo '<span class="dashicons dashicons-format-gallery"></span>';
			if ( $is_own ) {
				echo '<p>' . esc_html__( 'You haven\'t created any albums yet.', 'wpmediaverse' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This user hasn\'t created any albums yet.', 'wpmediaverse' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$album_svc = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$album_id   = get_the_ID();
			$cover_url  = $album_svc->get_cover_url( $album_id );
			$item_count = $album_svc->get_item_count( $album_id );

			// Link to single album within BP profile context.
			$album_post = get_post( $album_id );
			$album_link = trailingslashit( bp_displayed_user_domain() . 'media/albums/' . $album_post->post_name );

			echo '<div class="mvs-grid-item mvs-grid-item--album">';
			echo '<a href="' . esc_url( $album_link ) . '" class="mvs-grid-item-link">';
			if ( $cover_url ) {
				echo '<img src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album"><span class="mvs-grid-album-icon">&#128193;</span></div>';
			}
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			echo '<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; ' . esc_html( $item_count ) . '</span>';
			echo '</div></div>';
			echo '</a>';
			echo '<div class="mvs-grid-item-info"><span class="mvs-grid-item-title">' . esc_html( get_the_title() ) . '</span></div>';
			echo '</div>';
		}
		echo '</div>';

		// Pagination.
		if ( $query->max_num_pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'    => add_query_arg( 'mpage', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $query->max_num_pages,
				)
			);
			if ( $pagination ) {
				echo '<div class="mvs-pagination">' . wp_kses_post( $pagination ) . '</div>';
			}
		}

		wp_reset_postdata();
	}

	/**
	 * Display a single album within the BP profile context.
	 * URL: /members/{user}/media/albums/{album-slug}/
	 * Includes inline upload for the album owner.
	 */
	public function profile_single_album_content(): void {
		wp_enqueue_style( 'mvs-frontend' );

		$album_slug = bp_action_variable( 0 );
		$user_id    = bp_displayed_user_id();

		$album = get_page_by_path( $album_slug, OBJECT, 'mvs_album' );
		if ( ! $album || (int) $album->post_author !== $user_id ) {
			echo '<div class="mvs-empty-state"><p>' . esc_html__( 'Album not found.', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		$album_id = $album->ID;
		$is_own   = is_user_logged_in() && get_current_user_id() === $user_id;

		// Back link.
		$albums_url = trailingslashit( bp_displayed_user_domain() . 'media/albums' );
		echo '<div class="mvs-bp-back-link">';
		echo '<a href="' . esc_url( $albums_url ) . '">&larr; ' . esc_html__( 'Back to Albums', 'wpmediaverse' ) . '</a>';
		echo '</div>';

		// Album header.
		echo '<div class="mvs-album-header">';
		echo '<h2 class="mvs-album-title">' . esc_html( $album->post_title ) . '</h2>';
		if ( $album->post_content ) {
			echo '<div class="mvs-album-description">' . wp_kses_post( $album->post_content ) . '</div>';
		}

		// Get album items.
		global $wpdb;
		$table      = $wpdb->prefix . 'mvs_album_items';
		$items      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$album_id
			)
		);
		$item_count = count( $items );

		echo '<span class="mvs-album-count">';
		printf(
			/* translators: %d: number of items */
			esc_html( _n( '%d item', '%d items', $item_count, 'wpmediaverse' ) ),
			$item_count
		);
		echo '</span>';
		echo '</div>';

		// Owner actions: upload + edit/delete.
		if ( $is_own ) {
			$rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
			$nonce    = wp_create_nonce( 'wp_rest' );

			echo '<div class="mvs-bp-profile-actions">';
			echo '<button type="button" id="mvs-album-upload-btn" class="mvs-btn">';
			echo '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__( 'Add Media', 'wpmediaverse' );
			echo '</button>';
			echo '</div>';

			echo '<div id="mvs-album-upload-wrap" class="mvs-bp-upload-wrap" style="display:none;">';
			echo '<input type="file" multiple accept="image/*,video/*,audio/*" id="mvs-album-file-input" style="display:none" />';
			echo '<div class="mvs-bp-dropzone" id="mvs-album-dropzone">';
			echo '<span class="dashicons dashicons-cloud-upload"></span>';
			echo '<span class="mvs-bp-dropzone-text">' . esc_html__( 'Drop files here or click to upload into this album', 'wpmediaverse' ) . '</span>';
			echo '</div>';
			echo '<div id="mvs-album-upload-preview" class="mvs-bp-upload-preview"></div>';
			echo '<div id="mvs-album-upload-status" class="mvs-bp-upload-status" style="display:none;"></div>';
			echo '<div class="mvs-bp-upload-form-actions">';
			echo '<button type="button" id="mvs-album-upload-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
			echo '</div>';
			echo '</div>';

			// Inline JS for album upload.
			?>
			<script>
			(function(){
				var albumId = <?php echo (int) $album_id; ?>;
				var restUrl = '<?php echo esc_js( $rest_url ); ?>';
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var uploadBtn = document.getElementById('mvs-album-upload-btn');
				var uploadWrap = document.getElementById('mvs-album-upload-wrap');
				var dropzone = document.getElementById('mvs-album-dropzone');
				var fileInput = document.getElementById('mvs-album-file-input');
				var statusEl = document.getElementById('mvs-album-upload-status');
				var previewEl = document.getElementById('mvs-album-upload-preview');
				var cancelBtn = document.getElementById('mvs-album-upload-cancel');

				uploadBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'block';
					uploadBtn.style.display = 'none';
				});
				cancelBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'none';
					uploadBtn.style.display = '';
					previewEl.textContent = '';
					statusEl.style.display = 'none';
				});

				var clicking = false;
				dropzone.addEventListener('click', function(e) {
					e.preventDefault(); e.stopPropagation();
					if (clicking) return;
					clicking = true;
					fileInput.click();
					setTimeout(function() { clicking = false; }, 100);
				});
				dropzone.addEventListener('dragover', function(e) { e.preventDefault(); dropzone.classList.add('mvs-bp-dropzone--active'); });
				dropzone.addEventListener('dragleave', function() { dropzone.classList.remove('mvs-bp-dropzone--active'); });
				dropzone.addEventListener('drop', function(e) {
					e.preventDefault(); dropzone.classList.remove('mvs-bp-dropzone--active');
					handleFiles(Array.from(e.dataTransfer.files));
				});
				fileInput.addEventListener('change', function() {
					handleFiles(Array.from(fileInput.files));
					fileInput.value = '';
				});

				function handleFiles(files) {
					if (!files.length) return;
					files.forEach(function(file) {
						if (!file.type.match(/^image\//)) return;
						var reader = new FileReader();
						reader.onload = function(e) {
							var thumb = document.createElement('div');
							thumb.className = 'mvs-bp-upload-thumb';
							var img = document.createElement('img');
							img.src = e.target.result;
							thumb.appendChild(img);
							previewEl.appendChild(thumb);
						};
						reader.readAsDataURL(file);
					});
					uploadAndAddToAlbum(files);
				}

				function uploadAndAddToAlbum(files) {
					statusEl.style.display = 'block';
					var total = files.length, done = 0;
					var uploadedIds = [];
					statusEl.textContent = 'Uploading 1 of ' + total + '...';
					statusEl.className = 'mvs-bp-upload-status';

					function next() {
						if (done >= total) {
							if (uploadedIds.length) {
								statusEl.textContent = 'Adding to album...';
								fetch(restUrl + 'albums/' + albumId + '/items', {
									method: 'POST',
									headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
									credentials: 'same-origin',
									body: JSON.stringify({ media_ids: uploadedIds })
								}).then(function() {
									statusEl.textContent = uploadedIds.length + ' file(s) added to album!';
									statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
									setTimeout(function() { window.location.reload(); }, 800);
								});
							}
							return;
						}
						var fd = new FormData();
						fd.append('file', files[done]);
						fetch(restUrl + 'media', {
							method: 'POST',
							headers: { 'X-WP-Nonce': nonce },
							credentials: 'same-origin',
							body: fd
						}).then(function(r) { return r.json(); })
						.then(function(data) {
							if (data.id) uploadedIds.push(data.id);
							done++;
							if (done < total) statusEl.textContent = 'Uploading ' + (done + 1) + ' of ' + total + '...';
							next();
						}).catch(function() { done++; next(); });
					}
					next();
				}
			})();
			</script>
			<?php
		}

		// Album items grid.
		if ( ! empty( $items ) ) {
			echo '<div class="mvs-media-grid mvs-cols-3">';
			foreach ( $items as $media_id ) {
				\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
					(int) $media_id,
					array(),
					array(
						'show_author'  => false,
						'show_overlay' => false,
					)
				);
			}
			echo '</div>';
		} else {
			echo '<div class="mvs-empty-state">';
			echo '<span class="dashicons dashicons-format-gallery"></span>';
			if ( $is_own ) {
				echo '<p>' . esc_html__( 'This album is empty. Add some media!', 'wpmediaverse' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This album is empty.', 'wpmediaverse' ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Add a Media tab on group pages.
	 */
	public function add_group_tab(): void {
		if ( ! function_exists( 'bp_is_group' ) || ! bp_is_group() ) {
			return;
		}

		if ( ! function_exists( 'groups_get_current_group' ) ) {
			return;
		}

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Media', 'wpmediaverse' ),
				'slug'            => 'media',
				'parent_url'      => bp_get_group_url( $group ),
				'parent_slug'     => $group->slug,
				'screen_function' => array( $this, 'render_group_media_tab' ),
				'position'        => 80,
			)
		);
	}

	/**
	 * Render the group media tab with internal routing.
	 */
	public function render_group_media_tab(): void {
		$action = bp_action_variable( 0 );

		if ( 'albums' === $action ) {
			$album_slug = bp_action_variable( 1 );
			if ( $album_slug ) {
				add_action( 'bp_template_content', array( $this, 'group_single_album_content' ) );
			} else {
				add_action( 'bp_template_content', array( $this, 'group_albums_content' ) );
			}
		} else {
			add_action( 'bp_template_content', array( $this, 'group_media_content' ) );
		}

		bp_core_load_template( 'groups/single/plugins' );
	}

	/**
	 * Render sub-tab navigation for group media pages.
	 *
	 * @param string $active Active tab: 'media' or 'albums'.
	 */
	private function render_group_sub_tabs( string $active ): void {
		$group      = groups_get_current_group();
		$group_url  = bp_get_group_url( $group );
		$media_url  = trailingslashit( $group_url . 'media' );
		$albums_url = trailingslashit( $group_url . 'media/albums' );

		echo '<nav class="mvs-bp-sub-tabs">';
		echo '<a href="' . esc_url( $media_url ) . '" class="mvs-bp-sub-tab' . ( 'media' === $active ? ' active' : '' ) . '">';
		esc_html_e( 'Media', 'wpmediaverse' );
		echo '</a>';
		echo '<a href="' . esc_url( $albums_url ) . '" class="mvs-bp-sub-tab' . ( 'albums' === $active ? ' active' : '' ) . '">';
		esc_html_e( 'Albums', 'wpmediaverse' );
		echo '</a>';
		echo '</nav>';
	}

	/**
	 * Output media grid for the current group.
	 */
	public function group_media_content(): void {
		wp_enqueue_style( 'mvs-frontend' );

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		$this->render_group_sub_tabs( 'media' );

		$is_member = is_user_logged_in() && function_exists( 'groups_is_user_member' ) && groups_is_user_member( get_current_user_id(), $group->id );

		// Upload section for group members.
		if ( $is_member ) {
			$rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
			$nonce    = wp_create_nonce( 'wp_rest' );

			?>
			<div class="mvs-bp-profile-actions">
				<button type="button" id="mvs-bp-upload-btn" class="mvs-btn">
					<span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Upload Media', 'wpmediaverse' ); ?>
				</button>
			</div>

			<div class="mvs-bp-upload-wrap" id="mvs-bp-upload-wrap" style="display:none;">
				<input type="file" multiple accept="image/*,video/*,audio/*" class="mvs-bp-file-input" id="mvs-bp-file-input" style="display:none" />
				<div class="mvs-bp-dropzone" id="mvs-bp-dropzone">
					<span class="dashicons dashicons-cloud-upload"></span>
					<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
				</div>
				<div id="mvs-bp-upload-preview" class="mvs-bp-upload-preview"></div>
				<div class="mvs-bp-upload-status" id="mvs-bp-upload-status" style="display:none;"></div>
				<div class="mvs-bp-upload-form-actions">
					<button type="button" id="mvs-bp-upload-cancel" class="mvs-btn mvs-btn-secondary"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				</div>
			</div>
			<script>
			(function(){
				var restUrl = '<?php echo esc_js( $rest_url ); ?>';
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var groupId = <?php echo (int) $group->id; ?>;
				var uploadBtn = document.getElementById('mvs-bp-upload-btn');
				var uploadWrap = document.getElementById('mvs-bp-upload-wrap');
				var dropzone = document.getElementById('mvs-bp-dropzone');
				var fileInput = document.getElementById('mvs-bp-file-input');
				var statusEl = document.getElementById('mvs-bp-upload-status');
				var previewEl = document.getElementById('mvs-bp-upload-preview');
				var cancelBtn = document.getElementById('mvs-bp-upload-cancel');

				uploadBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'block';
					uploadBtn.style.display = 'none';
				});

				cancelBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'none';
					uploadBtn.style.display = '';
					previewEl.textContent = '';
					statusEl.style.display = 'none';
				});

				var clicking = false;
				dropzone.addEventListener('click', function(e) {
					e.preventDefault();
					e.stopPropagation();
					if (clicking) return;
					clicking = true;
					fileInput.click();
					setTimeout(function() { clicking = false; }, 100);
				});
				dropzone.addEventListener('dragover', function(e) {
					e.preventDefault();
					dropzone.classList.add('mvs-bp-dropzone--active');
				});
				dropzone.addEventListener('dragleave', function() {
					dropzone.classList.remove('mvs-bp-dropzone--active');
				});
				dropzone.addEventListener('drop', function(e) {
					e.preventDefault();
					dropzone.classList.remove('mvs-bp-dropzone--active');
					handleFiles(Array.from(e.dataTransfer.files));
				});
				fileInput.addEventListener('change', function() {
					handleFiles(Array.from(fileInput.files));
					fileInput.value = '';
				});

				function handleFiles(files) {
					if (!files.length) return;
					files.forEach(function(file) {
						if (!file.type.match(/^image\//)) return;
						var reader = new FileReader();
						reader.onload = function(e) {
							var thumb = document.createElement('div');
							thumb.className = 'mvs-bp-upload-thumb';
							var img = document.createElement('img');
							img.src = e.target.result;
							img.alt = file.name;
							var name = document.createElement('span');
							name.className = 'mvs-bp-upload-thumb-name';
							name.textContent = file.name;
							thumb.appendChild(img);
							thumb.appendChild(name);
							previewEl.appendChild(thumb);
						};
						reader.readAsDataURL(file);
					});
					uploadFiles(files);
				}

				function uploadFiles(files) {
					statusEl.style.display = 'block';
					var total = files.length, done = 0, failed = 0;
					statusEl.textContent = 'Uploading 1 of ' + total + '...';
					statusEl.className = 'mvs-bp-upload-status';

					function next() {
						if (done >= total) {
							var uploaded = total - failed;
							statusEl.textContent = uploaded + ' file(s) uploaded!';
							statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
							setTimeout(function() { window.location.reload(); }, 800);
							return;
						}
						var fd = new FormData();
						fd.append('file', files[done]);
						fd.append('group_id', groupId);
						fetch(restUrl + 'media', {
							method: 'POST',
							headers: { 'X-WP-Nonce': nonce },
							credentials: 'same-origin',
							body: fd
						}).then(function(r) {
							if (!r.ok) failed++;
							done++;
							if (done < total) statusEl.textContent = 'Uploading ' + (done + 1) + ' of ' + total + '...';
							next();
						}).catch(function() {
							failed++;
							done++;
							next();
						});
					}
					next();
				}
			})();
			</script>
			<?php
		}

		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 18;

		// Query media scoped to THIS group via mvs_media_meta custom table.
		global $wpdb;
		$index_table = $wpdb->prefix . 'mvs_media_index';
		$meta_table  = $wpdb->prefix . 'mvs_media_meta';
		$offset      = ( $paged - 1 ) * $per_page;

		$total_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$meta_table} mm INNER JOIN {$index_table} mi ON mm.media_id = mi.media_id WHERE mm.meta_key = 'group_id' AND mm.meta_value = %s AND mi.status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $group->id
			)
		);

		if ( ! $total_count ) {
			echo '<div class="mvs-empty-state"><span class="dashicons dashicons-format-gallery"></span>';
			echo '<p>' . esc_html__( 'No group media yet. Members can share media with the group!', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT mi.media_id FROM {$meta_table} mm INNER JOIN {$index_table} mi ON mm.media_id = mi.media_id WHERE mm.meta_key = 'group_id' AND mm.meta_value = %s AND mi.status = 'publish' ORDER BY mi.created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $group->id,
				$per_page,
				$offset
			)
		);
		$media_ids = array_map( 'intval', $media_ids );

		// Batch fetch stats.
		$stats_map = \WPMediaVerse\Core\TemplateHelpers::bulk_get_stats( $media_ids );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		foreach ( $media_ids as $mid ) {
			\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
				$mid,
				$stats_map[ $mid ] ?? array(),
				array( 'show_author' => true )
			);
		}
		echo '</div>';

		$max_pages = (int) ceil( $total_count / $per_page );
		if ( $max_pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'    => add_query_arg( 'mpage', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $max_pages,
				)
			);
			if ( $pagination ) {
				echo '<div class="mvs-pagination">' . wp_kses_post( $pagination ) . '</div>';
			}
		}
	}

	/**
	 * Output albums grid for the current group.
	 */
	public function group_albums_content(): void {
		wp_enqueue_style( 'mvs-frontend' );

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		$this->render_group_sub_tabs( 'albums' );

		$is_member = is_user_logged_in() && function_exists( 'groups_is_user_member' ) && groups_is_user_member( get_current_user_id(), $group->id );

		// Create album button + form for members.
		if ( $is_member ) {
			echo '<div class="mvs-bp-profile-actions">';
			echo '<button type="button" id="mvs-bp-create-album-btn" class="mvs-btn">';
			echo '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__( 'Create Album', 'wpmediaverse' );
			echo '</button>';
			echo '</div>';

			echo '<div id="mvs-bp-album-form" class="mvs-bp-album-form" style="display:none;">';
			echo '<div class="mvs-bp-album-form-inner">';
			echo '<label for="mvs-bp-album-title">' . esc_html__( 'Album Name', 'wpmediaverse' ) . '</label>';
			echo '<input type="text" id="mvs-bp-album-title" placeholder="' . esc_attr__( 'Enter album name...', 'wpmediaverse' ) . '" />';
			echo '<label for="mvs-bp-album-desc">' . esc_html__( 'Description (optional)', 'wpmediaverse' ) . '</label>';
			echo '<textarea id="mvs-bp-album-desc" rows="2" placeholder="' . esc_attr__( 'Album description...', 'wpmediaverse' ) . '"></textarea>';
			echo '<input type="hidden" id="mvs-bp-group-id" value="' . (int) $group->id . '" />';
			echo '<div class="mvs-bp-album-form-actions">';
			echo '<button type="button" id="mvs-bp-album-save" class="mvs-btn mvs-btn-primary">' . esc_html__( 'Create', 'wpmediaverse' ) . '</button>';
			echo '<button type="button" id="mvs-bp-album-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
			echo '</div>';
			echo '<div id="mvs-bp-album-msg" class="mvs-bp-album-msg"></div>';
			echo '</div>';
			echo '</div>';
		}

		$paged    = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 18;

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_album',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_mvs_group_id',
						'value' => $group->id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<div class="mvs-empty-state">';
			echo '<span class="dashicons dashicons-format-gallery"></span>';
			echo '<p>' . esc_html__( 'No group albums yet.', 'wpmediaverse' ) . '</p>';
			echo '</div>';
			return;
		}

		$group_url = bp_get_group_url( $group );

		$album_svc = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$album_id   = get_the_ID();
			$cover_url  = $album_svc->get_cover_url( $album_id );
			$item_count = $album_svc->get_item_count( $album_id );

			$album_post = get_post( $album_id );
			$album_link = trailingslashit( $group_url . 'media/albums/' . $album_post->post_name );

			echo '<div class="mvs-grid-item mvs-grid-item--album">';
			echo '<a href="' . esc_url( $album_link ) . '" class="mvs-grid-item-link">';
			if ( $cover_url ) {
				echo '<img src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album"><span class="mvs-grid-album-icon">&#128193;</span></div>';
			}
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			echo '<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; ' . esc_html( $item_count ) . '</span>';
			echo '</div></div>';
			echo '</a>';
			echo '<div class="mvs-grid-item-info"><span class="mvs-grid-item-title">' . esc_html( get_the_title() ) . '</span></div>';
			echo '</div>';
		}
		echo '</div>';

		if ( $query->max_num_pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'    => add_query_arg( 'mpage', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $query->max_num_pages,
				)
			);
			if ( $pagination ) {
				echo '<div class="mvs-pagination">' . wp_kses_post( $pagination ) . '</div>';
			}
		}

		wp_reset_postdata();
	}

	/**
	 * Display a single album within the BP group context.
	 * URL: /groups/{slug}/media/albums/{album-slug}/
	 */
	public function group_single_album_content(): void {
		wp_enqueue_style( 'mvs-frontend' );

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		$album_slug = bp_action_variable( 1 );
		$album      = get_page_by_path( $album_slug, OBJECT, 'mvs_album' );

		if ( ! $album ) {
			echo '<div class="mvs-empty-state"><p>' . esc_html__( 'Album not found.', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		// Verify album belongs to this group.
		$album_group_id = (int) MediaMeta::get( $album->ID, 'group_id' );
		if ( $album_group_id !== (int) $group->id ) {
			echo '<div class="mvs-empty-state"><p>' . esc_html__( 'Album not found in this group.', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		$album_id  = $album->ID;
		$group_url = bp_get_group_url( $group );
		$is_member = is_user_logged_in() && function_exists( 'groups_is_user_member' ) && groups_is_user_member( get_current_user_id(), $group->id );

		// Back link.
		$albums_url = trailingslashit( $group_url . 'media/albums' );
		echo '<div class="mvs-bp-back-link">';
		echo '<a href="' . esc_url( $albums_url ) . '">&larr; ' . esc_html__( 'Back to Albums', 'wpmediaverse' ) . '</a>';
		echo '</div>';

		// Album header.
		echo '<div class="mvs-album-header">';
		echo '<h2 class="mvs-album-title">' . esc_html( $album->post_title ) . '</h2>';
		if ( $album->post_content ) {
			echo '<div class="mvs-album-description">' . wp_kses_post( $album->post_content ) . '</div>';
		}

		global $wpdb;
		$table      = $wpdb->prefix . 'mvs_album_items';
		$items      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$album_id
			)
		);
		$item_count = count( $items );

		echo '<span class="mvs-album-count">';
		printf(
			/* translators: %d: number of items */
			esc_html( _n( '%d item', '%d items', $item_count, 'wpmediaverse' ) ),
			$item_count
		);
		echo '</span>';
		echo '</div>';

		// Upload for group members.
		if ( $is_member ) {
			$rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
			$nonce    = wp_create_nonce( 'wp_rest' );

			echo '<div class="mvs-bp-profile-actions">';
			echo '<button type="button" id="mvs-album-upload-btn" class="mvs-btn">';
			echo '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__( 'Add Media', 'wpmediaverse' );
			echo '</button>';
			echo '</div>';

			echo '<div id="mvs-album-upload-wrap" class="mvs-bp-upload-wrap" style="display:none;">';
			echo '<input type="file" multiple accept="image/*,video/*,audio/*" id="mvs-album-file-input" style="display:none" />';
			echo '<div class="mvs-bp-dropzone" id="mvs-album-dropzone">';
			echo '<span class="dashicons dashicons-cloud-upload"></span>';
			echo '<span class="mvs-bp-dropzone-text">' . esc_html__( 'Drop files here or click to upload into this album', 'wpmediaverse' ) . '</span>';
			echo '</div>';
			echo '<div id="mvs-album-upload-preview" class="mvs-bp-upload-preview"></div>';
			echo '<div id="mvs-album-upload-status" class="mvs-bp-upload-status" style="display:none;"></div>';
			echo '<div class="mvs-bp-upload-form-actions">';
			echo '<button type="button" id="mvs-album-upload-cancel" class="mvs-btn mvs-btn-secondary">' . esc_html__( 'Cancel', 'wpmediaverse' ) . '</button>';
			echo '</div>';
			echo '</div>';

			?>
			<script>
			(function(){
				var albumId = <?php echo (int) $album_id; ?>;
				var groupId = <?php echo (int) $group->id; ?>;
				var restUrl = '<?php echo esc_js( $rest_url ); ?>';
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var uploadBtn = document.getElementById('mvs-album-upload-btn');
				var uploadWrap = document.getElementById('mvs-album-upload-wrap');
				var dropzone = document.getElementById('mvs-album-dropzone');
				var fileInput = document.getElementById('mvs-album-file-input');
				var statusEl = document.getElementById('mvs-album-upload-status');
				var previewEl = document.getElementById('mvs-album-upload-preview');
				var cancelBtn = document.getElementById('mvs-album-upload-cancel');

				uploadBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'block';
					uploadBtn.style.display = 'none';
				});
				cancelBtn.addEventListener('click', function() {
					uploadWrap.style.display = 'none';
					uploadBtn.style.display = '';
					previewEl.textContent = '';
					statusEl.style.display = 'none';
				});

				var clicking = false;
				dropzone.addEventListener('click', function(e) {
					e.preventDefault(); e.stopPropagation();
					if (clicking) return;
					clicking = true;
					fileInput.click();
					setTimeout(function() { clicking = false; }, 100);
				});
				dropzone.addEventListener('dragover', function(e) { e.preventDefault(); dropzone.classList.add('mvs-bp-dropzone--active'); });
				dropzone.addEventListener('dragleave', function() { dropzone.classList.remove('mvs-bp-dropzone--active'); });
				dropzone.addEventListener('drop', function(e) {
					e.preventDefault(); dropzone.classList.remove('mvs-bp-dropzone--active');
					handleFiles(Array.from(e.dataTransfer.files));
				});
				fileInput.addEventListener('change', function() {
					handleFiles(Array.from(fileInput.files));
					fileInput.value = '';
				});

				function handleFiles(files) {
					if (!files.length) return;
					files.forEach(function(file) {
						if (!file.type.match(/^image\//)) return;
						var reader = new FileReader();
						reader.onload = function(e) {
							var thumb = document.createElement('div');
							thumb.className = 'mvs-bp-upload-thumb';
							var img = document.createElement('img');
							img.src = e.target.result;
							thumb.appendChild(img);
							previewEl.appendChild(thumb);
						};
						reader.readAsDataURL(file);
					});
					uploadAndAddToAlbum(files);
				}

				function uploadAndAddToAlbum(files) {
					statusEl.style.display = 'block';
					var total = files.length, done = 0;
					var uploadedIds = [];
					statusEl.textContent = 'Uploading 1 of ' + total + '...';
					statusEl.className = 'mvs-bp-upload-status';

					function next() {
						if (done >= total) {
							if (uploadedIds.length) {
								statusEl.textContent = 'Adding to album...';
								fetch(restUrl + 'albums/' + albumId + '/items', {
									method: 'POST',
									headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
									credentials: 'same-origin',
									body: JSON.stringify({ media_ids: uploadedIds })
								}).then(function() {
									statusEl.textContent = uploadedIds.length + ' file(s) added to album!';
									statusEl.className = 'mvs-bp-upload-status mvs-bp-upload-status--success';
									setTimeout(function() { window.location.reload(); }, 800);
								});
							}
							return;
						}
						var fd = new FormData();
						fd.append('file', files[done]);
						fd.append('group_id', groupId);
						fetch(restUrl + 'media', {
							method: 'POST',
							headers: { 'X-WP-Nonce': nonce },
							credentials: 'same-origin',
							body: fd
						}).then(function(r) { return r.json(); })
						.then(function(data) {
							if (data.id) uploadedIds.push(data.id);
							done++;
							if (done < total) statusEl.textContent = 'Uploading ' + (done + 1) + ' of ' + total + '...';
							next();
						}).catch(function() { done++; next(); });
					}
					next();
				}
			})();
			</script>
			<?php
		}

		// Album items grid.
		if ( ! empty( $items ) ) {
			echo '<div class="mvs-media-grid mvs-cols-3">';
			foreach ( $items as $media_id ) {
				\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
					(int) $media_id,
					array(),
					array(
						'show_author'  => false,
						'show_overlay' => false,
					)
				);
			}
			echo '</div>';
		} else {
			echo '<div class="mvs-empty-state">';
			echo '<span class="dashicons dashicons-format-gallery"></span>';
			if ( $is_member ) {
				echo '<p>' . esc_html__( 'This album is empty. Add some media!', 'wpmediaverse' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This album is empty.', 'wpmediaverse' ) . '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Send a BP notification when someone reacts to media.
	 *
	 * @param int    $media_id      Media post ID.
	 * @param int    $user_id       User who reacted.
	 * @param string $reaction_type Reaction type.
	 */
	public function notify_reaction( int $media_id, int $user_id, string $reaction_type ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$owner_id = MediaMeta::get_author( $media_id );
		if ( ! $owner_id || $owner_id === $user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => $owner_id,
				'item_id'           => $media_id,
				'secondary_item_id' => $user_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => 'mvs_new_reaction',
			)
		);
	}

	/**
	 * Send a BP notification when someone comments on media.
	 *
	 * Hook signature: mvs_comment_created( $media_id, $user_id, $comment_id, $content ).
	 *
	 * @param int $media_id   Media post ID.
	 * @param int $user_id    Commenter user ID.
	 * @param int $comment_id Comment ID.
	 */
	public function notify_comment( int $media_id, int $user_id, int $comment_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$comment  = get_comment( $comment_id );
		$owner_id = MediaMeta::get_author( $media_id );
		if ( ! $comment || ! $owner_id || $owner_id === (int) $comment->user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => $owner_id,
				'item_id'           => $media_id,
				'secondary_item_id' => (int) $comment->user_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => 'mvs_new_comment',
			)
		);
	}

	/**
	 * Send BP notifications for @mentions.
	 *
	 * @param int   $media_id      Media post ID.
	 * @param array $mentioned_ids Array of mentioned user IDs.
	 */
	public function notify_mentions( int $media_id, array $mentioned_ids ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$actor_id = MediaMeta::get_author( $media_id );

		foreach ( $mentioned_ids as $mentioned_id ) {
			if ( (int) $mentioned_id === $actor_id ) {
				continue;
			}

			bp_notifications_add_notification(
				array(
					'user_id'           => (int) $mentioned_id,
					'item_id'           => $media_id,
					'secondary_item_id' => $actor_id,
					'component_name'    => 'wpmediaverse',
					'component_action'  => 'mvs_new_mention',
				)
			);
		}
	}

	/**
	 * Register the notification component.
	 *
	 * @param array $components Registered components.
	 * @return array
	 */
	public function register_notification_component( array $components ): array {
		$components[] = 'wpmediaverse';
		return $components;
	}

	/**
	 * Register notification filters for BP Nouveau's notification dropdown.
	 */
	public function register_notification_filters(): void {
		if ( ! function_exists( 'bp_nouveau_notifications_register_filter' ) ) {
			return;
		}

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_reaction',
				'label'    => __( 'Media Reactions', 'wpmediaverse' ),
				'position' => 80,
			)
		);

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_comment',
				'label'    => __( 'Media Comments', 'wpmediaverse' ),
				'position' => 85,
			)
		);

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_mention',
				'label'    => __( 'Media Mentions', 'wpmediaverse' ),
				'position' => 90,
			)
		);
	}

	/**
	 * Format notification content.
	 *
	 * @param string $content           Notification content.
	 * @param int    $item_id           Item ID.
	 * @param int    $secondary_item_id Secondary item ID.
	 * @param int    $total_items       Total items.
	 * @param string $format            Format (string or array).
	 * @param string $component_action  Action name.
	 * @param string $component_name    Component name.
	 * @param int    $id                Notification ID.
	 * @return string|array
	 */
	public function format_notifications( $content, $item_id, $secondary_item_id, $total_items, $format, $component_action, $component_name, $id ) {
		if ( 'wpmediaverse' !== $component_name ) {
			return $content;
		}

		$user_name = bp_core_get_user_displayname( $secondary_item_id );
		$link      = MediaMeta::exists( $item_id ) ? MediaMeta::get_permalink( $item_id ) : '';

		switch ( $component_action ) {
			case 'mvs_new_reaction':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s reacted to your media', 'wpmediaverse' ), $user_name );
				break;
			case 'mvs_new_comment':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s commented on your media', 'wpmediaverse' ), $user_name );
				break;
			case 'mvs_new_mention':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s mentioned you', 'wpmediaverse' ), $user_name );
				break;
			default:
				return $content;
		}

		if ( 'string' === $format ) {
			return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
		}

		return array(
			'text' => esc_html( $text ),
			'link' => esc_url( $link ),
		);
	}

	/**
	 * Get an HTML thumbnail for a media item, for use in activity content.
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $size     Image size (thumbnail, medium, large).
	 * @return string HTML string with linked thumbnail, or empty string.
	 */
	/**
	 * Generate media thumbnail HTML for activity display.
	 *
	 * @since 1.1.0 Changed from private to public for WP-CLI backfill command.
	 */
	public function get_media_thumbnail_html( int $media_id, string $size = 'medium' ): string {
		$file_type  = MediaMeta::get( $media_id, 'file_type' );
		$media_type = MediaMeta::get( $media_id, 'media_type' );
		$file_url   = (string) MediaMeta::get( $media_id, 'file_url' );

		$thumb_url = TemplateHelpers::get_thumb_url( $media_id, $size );
		if ( ! $thumb_url && $file_url && strpos( $file_type, 'image/' ) === 0 ) {
			$thumb_url = $file_url;
		}

		$permalink = MediaMeta::get_permalink( $media_id );
		$title     = MediaMeta::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );

		// Link to the media single page. The lightbox is handled by the shared-ui
		// Interactivity API module when the user clicks from the activity stream.
		$href       = $permalink ?: $file_url;
		$data_mid   = ' data-mvs-media-id="' . esc_attr( $media_id ) . '"';

		// Image or video with poster thumbnail.
		if ( $thumb_url ) {
			$thumb_url = set_url_scheme( $thumb_url );
			$overlay   = '';
			if ( 'video' === $media_type ) {
				$overlay = '<span class="mvs-activity-play-icon" aria-hidden="true"></span>';
			}

			return '<div class="mvs-activity-media mvs-activity-media--' . esc_attr( $media_type ) . '"' . $data_mid . '><a href="' . esc_url( $href ) . '">' . $overlay . '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" /></a></div>';
		}

		// Video without poster: show dark placeholder with play icon.
		if ( 'video' === $media_type ) {
			return '<div class="mvs-activity-media mvs-activity-media--video mvs-activity-media--placeholder"' . $data_mid . ' style="position:relative;overflow:hidden;"><a href="' . esc_url( $href ) . '" class="mvs-activity-vid-link"><span class="mvs-activity-play-icon" aria-hidden="true"></span><span class="mvs-activity-media-label">' . esc_html( $title ) . '</span></a></div>';
		}

		// Audio: show compact audio card.
		if ( 'audio' === $media_type ) {
			$artist   = MediaMeta::get( $media_id, 'artist' );
			$duration = MediaMeta::get( $media_id, 'duration' );
			$sub      = '';
			if ( $artist ) {
				$sub .= esc_html( $artist );
			}
			if ( $duration ) {
				$minutes = floor( (float) $duration / 60 );
				$seconds = (int) $duration % 60;
				$sub    .= ( $sub ? ' &middot; ' : '' ) . sprintf( '%d:%02d', $minutes, $seconds );
			}
			return '<div class="mvs-activity-media mvs-activity-media--audio"' . $data_mid . ' style="border-radius:12px;"><a href="' . esc_url( $href ) . '" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;"><span class="mvs-activity-audio-icon" style="font-size:1.5em;flex-shrink:0;">&#9835;</span><span class="mvs-activity-audio-info" style="min-width:0;"><span class="mvs-activity-audio-title" style="display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $title ) . '</span>' . ( $sub ? '<span class="mvs-activity-audio-meta" style="display:block;font-size:.8em;color:#666;">' . $sub . '</span>' : '' ) . '</span></a></div>';
		}

		return '';
	}

	/**
	 * Output a media attachment button in the BP activity post form.
	 */
	public function activity_post_media_button(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<div id="mvs-activity-media-btn-wrap" class="mvs-activity-media-btn-wrap">
			<input type="file" id="mvs-activity-media-file" accept="image/*,video/*,audio/*" multiple style="display:none" />
			<button type="button" id="mvs-activity-media-btn" class="mvs-activity-media-btn" title="<?php esc_attr_e( 'Attach media', 'wpmediaverse' ); ?>">
				<span class="dashicons dashicons-admin-media"></span>
			</button>
			<div id="mvs-activity-media-preview" class="mvs-activity-media-preview" style="display:none"></div>
			<input type="hidden" id="mvs-activity-media-ids" name="mvs_activity_media_ids" value="" />
		</div>
		<?php
	}

	/**
	 * Enqueue JS/CSS for the activity media attachment button.
	 */
	public function enqueue_activity_media_scripts(): void {
		// Always enqueue frontend CSS and lightbox on BP pages for all visitors.
		wp_enqueue_style( 'mvs-frontend' );

		$plugin_url = plugin_dir_url( dirname( __DIR__ ) );

		// Lightbox handled by shared-ui Interactivity API module — no legacy JS needed.

		// Upload button and activity-media JS only for logged-in users.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$js_path = plugin_dir_path( dirname( __DIR__ ) ) . 'assets/js/bp-activity-media.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'mvs-bp-activity-media',
			$plugin_url . 'assets/js/bp-activity-media.js',
			array( 'jquery' ),
			filemtime( $js_path ),
			true
		);
		wp_localize_script(
			'mvs-bp-activity-media',
			'mvsActivityMedia',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'bpRestUrl' => esc_url_raw( rest_url( 'buddypress/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'maxMedia'  => (int) apply_filters( 'mvs_activity_max_media', 6 ),
			)
		);
	}

	/**
	 * After a BP activity update is posted, attach media thumbnail if media was selected.
	 *
	 * @param string $content     Activity content.
	 * @param int    $user_id     User ID.
	 * @param int    $activity_id Activity ID.
	 */
	public function attach_media_to_activity( $content, $user_id, $activity_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_ids = isset( $_POST['mvs_activity_media_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['mvs_activity_media_ids'] ) ) : '';
		if ( ! $raw_ids ) {
			return;
		}

		$media_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		if ( empty( $media_ids ) ) {
			return;
		}

		/** Filter: max media items per activity post. Default 6. */
		$max_media = (int) apply_filters( 'mvs_activity_max_media', 6 );
		$media_ids = array_slice( $media_ids, 0, $max_media );

		$thumbnails = '';
		$valid_ids  = array();
		foreach ( $media_ids as $media_id ) {
			if ( ! MediaMeta::exists( $media_id ) ) {
				continue;
			}
			$media_author = MediaMeta::get_author( $media_id );
			if ( $media_author !== $user_id ) {
				continue;
			}

			// Publish draft media now that the activity is being posted.
			$media_status = MediaMeta::get( $media_id, 'status' );
			if ( 'draft' === $media_status ) {
				MediaMeta::set( $media_id, 'status', 'publish' );
			}

			$valid_ids[] = $media_id;
			$thumbnails .= $this->get_media_thumbnail_html( $media_id, 'large' );
		}

		if ( empty( $valid_ids ) ) {
			return;
		}

		$count       = count( $valid_ids );
		$grid_class  = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
		$new_content = $content . '<div class="' . esc_attr( $grid_class ) . '">' . $thumbnails . '</div>';

		bp_activity_update_meta( $activity_id, '_mvs_media_ids', implode( ',', $valid_ids ) );

		// Store the activity ID on each media post so comments on these media
		// can be threaded back as activity comments via find_media_upload_activity().
		foreach ( $valid_ids as $mid ) {
			MediaMeta::set( $mid, 'bp_activity_id', $activity_id );
		}

		$activity = new \BP_Activity_Activity( $activity_id );
		if ( $activity->id ) {
			$activity->content = $new_content;
			$activity->save();
		}
	}

	/**
	 * Attach media to a group activity post.
	 *
	 * Mirrors attach_media_to_activity() but for group updates.
	 * Fires on bp_groups_posted_update ($content, $user_id, $group_id, $activity_id).
	 *
	 * @param string $content     Activity content.
	 * @param int    $user_id     User ID.
	 * @param int    $group_id    Group ID.
	 * @param int    $activity_id Activity ID.
	 */
	public function attach_media_to_group_activity( $content, $user_id, $group_id, $activity_id ): void {
		// Delegate to the same logic as personal activity.
		$this->attach_media_to_activity( $content, $user_id, $activity_id );

		// Also set group meta on each media item so it appears in group media grid.
		$raw_ids = bp_activity_get_meta( $activity_id, '_mvs_media_ids', true );
		if ( $raw_ids ) {
			$media_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
			foreach ( $media_ids as $media_id ) {
				MediaMeta::set( $media_id, 'privacy', 'group' );
				MediaMeta::set( $media_id, 'group_id', $group_id );
			}
		}
	}

	/**
	 * Get a human-readable label for a MIME type.
	 *
	 * @param string $file_type MIME type.
	 * @return string
	 */
	private function get_media_type_label( ?string $file_type ): string {
		if ( ! $file_type ) {
			return __( 'media', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'image/' ) === 0 ) {
			return __( 'photo', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'video/' ) === 0 ) {
			return __( 'video', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'audio/' ) === 0 ) {
			return __( 'audio file', 'wpmediaverse' );
		}
		return __( 'file', 'wpmediaverse' );
	}

	/**
	 * Whitelist MVS HTML tags and attributes in BP activity content.
	 *
	 * Hooked to bp_activity_allowed_tags so kses preserves our transformed grid markup.
	 *
	 * @param array $tags Allowed tags array.
	 * @return array Extended allowed tags.
	 */
	public function allow_mvs_activity_tags( array $tags ): array {
		// Grid container and per-item wrappers.
		$tags['div'] = array(
			'class'              => array(),
			'style'              => array(),
			'data-mvs-media-id'  => array(),
			'data-mvs-src'       => array(),
			'data-mvs-permalink' => array(),
		);

		// Allow inline <video> player.
		$tags['video'] = array(
			'src'      => array(),
			'controls' => array(),
			'preload'  => array(),
			'poster'   => array(),
			'style'    => array(),
			'class'    => array(),
			'width'    => array(),
			'height'   => array(),
		);

		// Allow <source> inside <video>/<audio>.
		$tags['source'] = array(
			'src'  => array(),
			'type' => array(),
		);

		// Allow inline <audio> player.
		$tags['audio'] = array(
			'src'      => array(),
			'controls' => array(),
			'preload'  => array(),
			'style'    => array(),
			'class'    => array(),
		);

		// Allow class/style on <a>, <span>, <img> for MVS media links.
		$tags['a']['class']              = array();
		$tags['a']['style']              = array();
		$tags['a']['href']               = array();
		$tags['a']['data-mvs-permalink'] = array();
		$tags['img']['src']              = array();
		$tags['img']['alt']              = array();
		$tags['img']['width']            = array();
		$tags['img']['height']           = array();
		$tags['img']['style']            = array();
		$tags['img']['loading']          = array();
		$tags['img']['class']            = array();
		$tags['span']                    = array(
			'class'       => array(),
			'aria-hidden' => array(),
		);

		return $tags;
	}

	/**
	 * Transform legacy media plugin activity HTML (rtMedia, etc.) to MVS rendering.
	 *
	 * Hooked to bp_get_activity_content_body. Detects known legacy HTML markers,
	 * extracts media items, and rewrites the content with inline-styled MVS HTML
	 * so that images/videos render consistently with the MVS UI (including lightbox).
	 *
	 * @param string $content Raw activity content.
	 * @return string Transformed content, or original if no legacy marker found.
	 */
	public function inject_video_player_in_activity( string $content ): string {
		// Only process content that has our video placeholder markup.
		if ( false === strpos( $content, 'mvs-activity-media--video' ) ) {
			return $content;
		}

		// Extract media ID from data-mvs-media-id attribute.
		if ( ! preg_match( '/data-mvs-media-id="(\d+)"/', $content, $matches ) ) {
			return $content;
		}

		$media_id   = (int) $matches[1];
		$file_url   = MediaMeta::get( $media_id, 'file_url' );
		$media_type = MediaMeta::get( $media_id, 'media_type' );

		if ( 'video' !== $media_type || ! $file_url ) {
			return $content;
		}

		$file_url  = set_url_scheme( $file_url );
		$permalink = MediaMeta::get_permalink( $media_id );
		$poster    = '';

		$poster_url = TemplateHelpers::get_thumb_url( $media_id, 'large' );
		if ( $poster_url ) {
			$poster = ' poster="' . esc_url( $poster_url ) . '"';
		}

		$video_html = '<div class="mvs-activity-media mvs-activity-media--video" data-mvs-media-id="' . esc_attr( $media_id ) . '">'
			. '<video controls preload="metadata"' . $poster . ' style="width:100%;max-height:400px;border-radius:8px;display:block;">'
			. '<source src="' . esc_url( $file_url ) . '" type="' . esc_attr( MediaMeta::get( $media_id, 'file_type' ) ?: 'video/mp4' ) . '">'
			. '</video>'
			. '<a href="' . esc_url( $permalink ) . '" class="mvs-activity-media-link" style="display:block;text-align:center;margin-top:4px;font-size:13px;">' . esc_html__( 'View full media', 'wpmediaverse' ) . '</a>'
			. '</div>';

		// Replace the existing thumbnail/placeholder with the video player.
		$content = preg_replace( '/<div class="mvs-activity-media mvs-activity-media--video[^"]*"[^>]*>.*?<\/div>/s', $video_html, $content );

		return $content;
	}

	/**
	 * Enhance activity media content.
	 *
	 * Single unified filter for all activity media rendering:
	 * 1. Transform rtMedia legacy HTML to MVS rendering (when rtMedia deactivated)
	 * 2. Transform MediaPress activities via _mpp_attached_media_id meta lookup
	 * 3. Inject thumbnails for imported/empty mvs_media_upload activities
	 *
	 * @param string      $content  Raw activity content.
	 * @param object|null $activity BP activity object (passed by ref from BP).
	 * @return string Enhanced content.
	 */
	public function enhance_activity_media_content( string $content, $activity = null ): string {
		// Already has MVS media markup — skip.
		if ( strpos( $content, 'mvs-activity-media' ) !== false ) {
			return $content;
		}

		// --- 1. rtMedia legacy transform ---
		if ( strpos( $content, 'rtmedia-activity-container' ) !== false ) {
			return $this->transform_rtmedia_content( $content );
		}

		// Resolve activity object.
		if ( ! is_object( $activity ) || empty( $activity->id ) ) {
			global $activities_template;
			$activity = ! empty( $activities_template->activity ) ? $activities_template->activity : null;
		}
		if ( ! $activity || empty( $activity->id ) ) {
			return $content;
		}

		// --- 2. MediaPress activity transform ---
		if ( ! function_exists( 'mediapress' ) && ! class_exists( 'MediaPress' ) ) {
			$mpp_media_id = bp_activity_get_meta( (int) $activity->id, '_mpp_attached_media_id', true );
			if ( $mpp_media_id ) {
				$thumbnail = $this->resolve_imported_thumbnail( (int) $mpp_media_id, '_mvs_mpp_id', '_mvs_attachment_id' );
				if ( $thumbnail ) {
					return $content . $thumbnail;
				}
			}
		}

		// --- 3. BuddyBoss activity transform (bp_media_ids meta) ---
		if ( ! function_exists( 'buddyboss_platform_plugin_basename' ) ) {
			$bb_media_ids = bp_activity_get_meta( (int) $activity->id, 'bp_media_ids', true );
			if ( $bb_media_ids ) {
				$bb_ids    = array_filter( array_map( 'intval', explode( ',', $bb_media_ids ) ) );
				$grid_html = '';
				foreach ( $bb_ids as $bb_id ) {
					$thumbnail = $this->resolve_imported_thumbnail( $bb_id, '_mvs_bb_media_id', '_mvs_attachment_id' );
					if ( $thumbnail ) {
						$grid_html .= $thumbnail;
					}
				}
				if ( $grid_html ) {
					$count      = count( $bb_ids );
					$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
					return $content . '<div class="' . esc_attr( $grid_class ) . '" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:8px;">' . $grid_html . '</div>';
				}
			}
		}

		// --- 4. Imported/empty mvs_media_upload thumbnail injection ---
		if ( 'mvs_media_upload' === ( $activity->type ?? '' ) && strlen( trim( wp_strip_all_tags( $content ) ) ) <= 10 ) {
			$media_id = 0;
			if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
				$media_id = (int) $activity->item_id;
			} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
				$media_id = (int) $activity->secondary_item_id;
			}
			if ( $media_id && MediaMeta::exists( $media_id ) ) {
				$thumbnail = $this->get_media_thumbnail_html( $media_id, 'large' );
				if ( $thumbnail ) {
					return $content . $thumbnail;
				}
			}
		}

		return $content;
	}

	/**
	 * Resolve an imported media thumbnail from a source plugin attachment ID.
	 *
	 * Looks up the MVS media item by the source meta key, then generates thumbnail HTML.
	 *
	 * @param int    $source_id       Original attachment/media ID from the source plugin.
	 * @param string $primary_meta    Primary meta key to search (e.g. _mvs_mpp_id).
	 * @param string $fallback_meta   Fallback meta key (e.g. _mvs_attachment_id).
	 * @return string Thumbnail HTML or empty string.
	 */
	private function resolve_imported_thumbnail( int $source_id, string $primary_meta, string $fallback_meta ): string {
		// Strip _mvs_ prefix to get the custom table key.
		$primary_key  = preg_replace( '/^_mvs_/', '', $primary_meta );
		$fallback_key = preg_replace( '/^_mvs_/', '', $fallback_meta );

		$mvs_id = $this->find_media_by_meta_key( $primary_key, (string) $source_id );

		if ( ! $mvs_id ) {
			$mvs_id = $this->find_media_by_meta_key( $fallback_key, (string) $source_id );
		}

		if ( ! $mvs_id ) {
			return '';
		}

		return $this->get_media_thumbnail_html( $mvs_id, 'large' );
	}

	/**
	 * Find a media ID by a key/value in custom tables.
	 *
	 * Checks mvs_media_index first (for core columns like attachment_id),
	 * then mvs_media_meta (for sparse keys like mpp_id, bb_media_id).
	 *
	 * @param string $key   Meta key (without _mvs_ prefix).
	 * @param string $value Meta value to match.
	 * @return int Media ID, or 0 if not found.
	 */
	private function find_media_by_meta_key( string $key, string $value ): int {
		global $wpdb;

		// Check if this is an index column.
		$index_columns = array( 'attachment_id', 'media_type', 'privacy', 'file_url', 'file_type', 'file_size', 'moderation_status', 'post_author' );

		if ( in_array( $key, $index_columns, true ) ) {
			$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE `{$key}` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$value
				)
			);
			return $result ? (int) $result : 0;
		}

		// Otherwise check mvs_media_meta.
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$key,
				$value
			)
		);

		return $result ? (int) $result : 0;
	}

	/**
	 * Transform rtMedia legacy activity HTML to MVS rendering.
	 *
	 * Extracts media items from rtMedia's container markup and rewrites as
	 * inline-styled MVS HTML for consistent rendering after rtMedia deactivation.
	 *
	 * @param string $content Activity content with rtMedia HTML.
	 * @return string Transformed content.
	 */
	private function transform_rtmedia_content( string $content ): string {
		// Only process content that has rtMedia's container class.
		if ( strpos( $content, 'rtmedia-activity-container' ) === false ) {
			return $content;
		}

		// Extract optional user text from rtMedia's text block.
		$text = '';
		if ( preg_match( '/<div[^>]+class="[^"]*rtmedia-activity-text[^"]*"[^>]*>.*?<span>(.*?)<\/span>/s', $content, $m ) ) {
			$text = trim( wp_strip_all_tags( $m[1] ) );
		}

		// Extract each <li class="rtmedia-list-item"> block.
		$media_html = '';
		$count      = 0;

		preg_match_all( '/<li[^>]+class="[^"]*rtmedia-list-item[^"]*"[^>]*>(.*?)<\/li>/s', $content, $items );

		foreach ( $items[1] as $item_html ) {
			$is_video = strpos( $item_html, 'media-type-video' ) !== false
						|| strpos( $item_html, '<video' ) !== false;
			$is_audio = strpos( $item_html, 'media-type-music' ) !== false
					|| strpos( $item_html, '<audio' ) !== false;

			// Primary link href (rtMedia's detail page — may 404 after deactivation).
			$href = '';
			if ( preg_match( '/href="([^"]+)"/', $item_html, $hm ) ) {
				$href = esc_url( $hm[1] );
			}

			if ( $is_video ) {
				// Get direct video src from <video src="..."> for deactivation-safe href.
				$src = '';
				if ( preg_match( '/<video[^>]+src="([^"]+)"/', $item_html, $sm ) ) {
					$src = esc_url( $sm[1] );
				}
				$title = '';
				if ( preg_match( '/title="([^"]+)"/', $item_html, $tm ) ) {
					$title = esc_html( $tm[1] );
				}
				$link     = $src ?: $href;
				$mvs_id   = $src ? $this->get_mvs_id_from_file_url( $src ) : 0;
				$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';
				$data_src = $link ? ' data-mvs-src="' . esc_attr( $link ) . '"' : '';

				$media_html .= '<div class="mvs-activity-media mvs-activity-media--video mvs-activity-media--placeholder"' . $data_mid . $data_src . '>'
							. '<a href="' . esc_url( $link ) . '" class="mvs-activity-vid-link">'
							. '<span class="mvs-activity-play-icon" aria-hidden="true"></span>'
							. ( $title ? '<span class="mvs-activity-media-label">' . $title . '</span>' : '' )
							. '</a></div>';

			} elseif ( $is_audio ) {
				// Extract direct audio file URL from <audio src="..."> — deactivation-safe.
				$src = '';
				if ( preg_match( '/<audio[^>]+src="([^"]+)"/', $item_html, $sm ) ) {
					$src = esc_url( $sm[1] );
				}
				$title = '';
				if ( preg_match( '/title="([^"]+)"/', $item_html, $tm ) ) {
					$title = esc_html( $tm[1] );
				}
				$link     = $src ?: $href;
				$mvs_id   = $src ? $this->get_mvs_id_from_file_url( $src ) : 0;
				$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';
				$data_src = $src ? ' data-mvs-src="' . esc_attr( $src ) . '"' : '';

				$media_html .= '<div class="mvs-activity-media mvs-activity-media--audio"' . $data_mid . $data_src . ' style="border-radius:12px;">'
							. '<a href="' . esc_url( $link ) . '" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;">'
							. '<span style="font-size:1.5em;flex-shrink:0;">&#9835;</span>'
							. '<span style="min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $title . '</span>'
							. '</a></div>';

			} else {
				// Image: extract <img src> and alt.
				$src = '';
				$alt = '';
				if ( preg_match( '/<img[^>]+src="([^"]+)"/', $item_html, $im ) ) {
					$src = esc_url( $im[1] );
				}
				if ( preg_match( '/<img[^>]+alt="([^"]+)"/', $item_html, $am ) ) {
					$alt = esc_attr( $am[1] );
				}

				if ( $src ) {
					// Resolve MVS post ID for lightbox support.
					$mvs_id   = $this->get_mvs_id_from_file_url( $src );
					$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';

					// Use direct file URL as link (deactivation-safe; strip size suffix for full image).
					$full_src = preg_replace( '/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $src );
					$link     = $full_src ?: $src;

					$media_html .= '<div class="mvs-activity-media mvs-activity-media--image"' . $data_mid . ' style="position:relative;overflow:hidden;">'
								. '<a href="' . esc_url( $link ) . '">'
								. '<img src="' . $src . '" alt="' . $alt . '" loading="lazy" style="max-width:100%;width:auto;height:auto;display:block;border-radius:8px;" />'
								. '</a></div>';
				}
			}

			++$count;
		}

		if ( ! $media_html ) {
			return $content; // Parsing failed — return original safely.
		}

		$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
		$output     = '';
		if ( $text ) {
			$output .= '<p>' . esc_html( $text ) . '</p>';
		}
		$output .= '<div class="' . esc_attr( $grid_class ) . '" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:8px;">' . $media_html . '</div>';

		return $output;
	}

	/**
	 * Render media thumbnail into activity entries with empty content.
	 *
	 * Hooked to `bp_activity_entry_content` (action, not filter) because BP Nouveau
	 * skips `bp_get_activity_content_body` entirely when activity content is empty.
	 * This method outputs the thumbnail AND persists it to the DB so the filter
	 * is not needed on subsequent renders.
	 *
	 * Handles imported media from all 3 source plugins:
	 * - rtMedia (via _mvs_rtmedia_id on the media post)
	 * - MediaPress (via _mpp_attached_media_id activity meta)
	 * - BuddyBoss (via _mvs_bb_media_id on the media post)
	 *
	 * @since 1.1.0
	 */
	public function render_activity_media_thumbnail(): void {
		global $activities_template;

		if ( empty( $activities_template->activity ) ) {
			return;
		}

		$activity = $activities_template->activity;

		// Only for mvs_media_upload activities with empty content.
		if ( 'mvs_media_upload' !== ( $activity->type ?? '' ) ) {
			return;
		}
		if ( ! empty( trim( $activity->content ?? '' ) ) ) {
			return;
		}

		// Resolve media ID from activity.
		$media_id = 0;
		if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
			$media_id = (int) $activity->item_id;
		} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
			$media_id = (int) $activity->secondary_item_id;
		}

		if ( ! $media_id || ! MediaMeta::exists( $media_id ) ) {
			return;
		}

		$thumbnail = $this->get_media_thumbnail_html( $media_id, 'large' );
		if ( ! $thumbnail ) {
			return;
		}

		// Output the thumbnail (display-time only — does NOT write to DB).
		// To persist thumbnails into activity content permanently, run:
		// wp mvs backfill-activity-thumbnails
		// That command warns the site owner and requires explicit confirmation.
		echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in get_media_thumbnail_html.
	}

	/**
	 * Backwards compatibility wrapper.
	 *
	 * @param string $content Content.
	 * @return string Content.
	 */
	public function transform_legacy_media_content( string $content ): string {
		return $this->enhance_activity_media_content( $content );
	}

	/**
	 * @deprecated Consolidated into enhance_activity_media_content().
	 */
	public function transform_mediapress_activity( string $content, $activity = null ): string {
		// Only process if MediaPress is NOT active (if active, let MediaPress handle its own rendering).
		if ( function_exists( 'mediapress' ) || class_exists( 'MediaPress' ) ) {
			return $content;
		}

		$activity_id = 0;
		if ( is_object( $activity ) && ! empty( $activity->id ) ) {
			$activity_id = (int) $activity->id;
		} elseif ( function_exists( 'bp_get_activity_id' ) ) {
			$activity_id = bp_get_activity_id();
		}
		if ( ! $activity_id ) {
			return $content;
		}

		// Check for MediaPress activity meta.
		$mpp_media_id = bp_activity_get_meta( $activity_id, '_mpp_attached_media_id', true );
		if ( ! $mpp_media_id ) {
			return $content;
		}

		// Find the imported MVS media that came from this MediaPress attachment.
		$mvs_id = $this->find_media_by_meta_key( 'mpp_id', (string) $mpp_media_id );

		if ( ! $mvs_id ) {
			// Try finding by attachment ID directly.
			$mvs_id = $this->find_media_by_meta_key( 'attachment_id', (string) $mpp_media_id );
		}

		if ( ! $mvs_id ) {
			return $content;
		}
		$thumbnail = $this->get_media_thumbnail_html( $mvs_id, 'large' );

		if ( ! $thumbnail ) {
			return $content;
		}

		// Append the thumbnail after existing content text.
		return $content . $thumbnail;
	}

	/**
	 * Inject thumbnail into imported media activities that currently show text-only.
	 *
	 * When media is imported via WP-CLI, the _mvs_imported_media flag skips the normal
	 * record_upload_activity() which sets thumbnail as content. This method retroactively
	 * adds the thumbnail for any mvs_media_upload activity that has empty content.
	 *
	 * @param string $content Activity content.
	 * @return string Content with thumbnail injected.
	 */
	public function inject_imported_media_thumbnail( string $content, $activity = null ): string {
		// Only process empty/minimal content.
		if ( strlen( trim( wp_strip_all_tags( $content ) ) ) > 10 ) {
			return $content;
		}

		// Already has media markup.
		if ( strpos( $content, 'mvs-activity-media' ) !== false ) {
			return $content;
		}

		// Get activity from param or global.
		if ( ! is_object( $activity ) || empty( $activity->type ) ) {
			global $activities_template;
			$activity = ! empty( $activities_template->activity ) ? $activities_template->activity : null;
		}
		if ( ! $activity || empty( $activity->type ) ) {
			return $content;
		}

		// Only for mvs_media_upload activities.
		if ( 'mvs_media_upload' !== $activity->type ) {
			return $content;
		}

		// The media ID is in item_id (profile uploads) or secondary_item_id (group uploads).
		$media_id = 0;
		if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
			$media_id = (int) $activity->item_id;
		} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
			$media_id = (int) $activity->secondary_item_id;
		}

		if ( ! $media_id || ! MediaMeta::exists( $media_id ) ) {
			return $content;
		}

		$thumbnail = $this->get_media_thumbnail_html( $media_id, 'large' );
		if ( ! $thumbnail ) {
			return $content;
		}

		return $content . $thumbnail;
	}

	/**
	 * Find an MVS media post ID from a file URL (used to attach media IDs to transformed activity HTML).
	 *
	 * Looks up the WP attachment by URL (stripping thumbnail size suffixes),
	 * then finds the media item that references that attachment.
	 *
	 * @param string $url File or thumbnail URL.
	 * @return int MVS post ID, or 0 if not found.
	 */
	private function get_mvs_id_from_file_url( string $url ): int {
		// Strip thumbnail size suffix (e.g. -320x240.png → .png).
		$clean = preg_replace( '/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $url );

		$attach_id = attachment_url_to_postid( $clean );
		if ( ! $attach_id && $clean !== $url ) {
			$attach_id = attachment_url_to_postid( $url );
		}
		if ( ! $attach_id ) {
			return 0;
		}

		return $this->find_media_by_meta_key( 'attachment_id', (string) $attach_id );
	}
}

<?php
/**
 * ActivityFormIntegration — activity post form media attachment for BuddyPress.
 *
 * Handles the media attachment button in BP activity post forms, script
 * enqueuing, and attaching uploaded media to activity updates (personal
 * and group).
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Manages the media attachment UI in BuddyPress activity post forms.
 */
class ActivityFormIntegration {

	/**
	 * Register all activity form hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		add_action( 'bp_activity_post_form_options', array( $this, 'activity_post_media_button' ) );
		add_action( 'bp_enqueue_scripts', array( $this, 'enqueue_activity_media_scripts' ) );
		add_action( 'bp_activity_posted_update', array( $this, 'attach_media_to_activity' ), 10, 3 );
		add_action( 'bp_groups_posted_update', array( $this, 'attach_media_to_group_activity' ), 10, 4 );
	}

	/**
	 * Output a media attachment button in the BP activity post form.
	 */
	public function activity_post_media_button(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$allow_user_privacy = (bool) get_option( 'mvs_allow_user_privacy', true );
		$default_privacy    = (string) get_option( 'mvs_default_privacy', 'public' );
		?>
		<div id="mvs-activity-media-btn-wrap" class="mvs-activity-media-btn-wrap">
			<input type="file" id="mvs-activity-media-file" accept="image/*,video/*,audio/*" multiple style="display:none" />
			<button
				type="button"
				id="mvs-activity-media-btn"
				class="mvs-activity-media-btn"
				aria-label="<?php esc_attr_e( 'Attach media', 'wpmediaverse' ); ?>"
			>
				<i data-lucide="image-plus" aria-hidden="true"></i>
				<span class="mvs-activity-media-btn__label"><?php esc_html_e( 'Attach media', 'wpmediaverse' ); ?></span>
			</button>
			<div id="mvs-activity-media-preview" class="mvs-activity-media-preview" style="display:none"></div>
			<input type="hidden" id="mvs-activity-media-ids" name="mvs_activity_media_ids" value="" />
			<?php if ( $allow_user_privacy ) : ?>
				<select id="mvs-activity-privacy" class="mvs-activity-privacy" name="mvs_activity_privacy" style="display:none" aria-label="<?php esc_attr_e( 'Privacy level for attached media', 'wpmediaverse' ); ?>">
					<option value="public" <?php selected( $default_privacy, 'public' ); ?>><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
					<option value="members" <?php selected( $default_privacy, 'members' ); ?>><?php esc_html_e( 'Members Only', 'wpmediaverse' ); ?></option>
					<option value="private" <?php selected( $default_privacy, 'private' ); ?>><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
				</select>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue JS/CSS for the activity media attachment button.
	 */
	public function enqueue_activity_media_scripts(): void {
		// Always enqueue frontend CSS and lightbox on BP pages for all visitors.
		wp_enqueue_style( 'mvs-frontend' );

		// Lightbox handled by shared-ui Interactivity API module — no legacy JS needed.

		// Upload button and activity-media JS only for logged-in users.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$js_path = MVS_PLUGIN_DIR . 'assets/js/bp-activity-media.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		// The activity composer's attach-media button uses
		// `<i data-lucide="image-plus">`; Lucide JS must load on BP pages or
		// the icon never hydrates and the button renders as an empty box.
		// Register-if-absent because `mvs_loaded` may not have fired the
		// central Lucide registration yet on some BP-only surfaces.
		if ( ! wp_script_is( 'mvs-lucide', 'registered' ) ) {
			wp_register_script(
				'mvs-lucide',
				MVS_PLUGIN_URL . 'assets/js/vendor/lucide.min.js',
				array(),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script(
				'mvs-lucide',
				'document.addEventListener("DOMContentLoaded",function(){if(window.lucide&&typeof window.lucide.createIcons==="function"){window.lucide.createIcons();}});'
			);
		}
		wp_enqueue_script( 'mvs-lucide' );

		wp_enqueue_script(
			'mvs-bp-activity-media',
			MVS_PLUGIN_URL . 'assets/js/bp-activity-media.js',
			array( 'jquery', 'mvs-lucide' ),
			filemtime( $js_path ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
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
			if ( ! MediaRepository::exists( $media_id ) ) {
				continue;
			}
			$media_author = MediaRepository::get_author( $media_id );
			if ( $media_author !== $user_id ) {
				continue;
			}

			// Publish draft media now that the activity is being posted.
			$media_status = MediaRepository::get( $media_id, 'status' );
			if ( 'draft' === $media_status ) {
				MediaRepository::set( $media_id, 'status', 'publish' );
			}

			$valid_ids[] = $media_id;
			$thumbnails .= MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );
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
			MediaRepository::set( $mid, 'bp_activity_id', $activity_id );
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
				MediaRepository::set( $media_id, 'privacy', 'group' );
				MediaRepository::set( $media_id, 'group_id', $group_id );
			}
		}
	}
}

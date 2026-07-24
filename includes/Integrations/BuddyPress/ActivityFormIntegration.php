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
				<?php
				// Server-rendered SVG (no JS/Lucide dependency) so the icon is
				// visible even when BP Nouveau's Backbone re-render races the
				// Lucide MutationObserver. Card #8.
				echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->icon_image_plus_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG helper returns markup with no user input.
				?>
				<span class="mvs-activity-media-btn__label"><?php esc_html_e( 'Attach media', 'wpmediaverse' ); ?></span>
			</button>
			<div id="mvs-activity-media-preview" class="mvs-activity-media-preview" style="display:none"></div>
			<input type="hidden" id="mvs-activity-media-ids" name="mvs_activity_media_ids" value="" />
			<?php
			if ( $allow_user_privacy ) :
				// 4 honest privacy levels — each delivered end-to-end via
				// `_mvs_activity_privacy_level` activity meta + the
				// `ActivityPrivacyFilter` viewer-side WHERE clause on every
				// BP activity query. Same numeric scheme rtMedia ships
				// (0/20/40/80) so an rtMedia → WPMV import preserves intent
				// without translation. Friends-Only only shows when the BP
				// friends component is active — without it the level has no
				// distinct meaning vs Members.
				$show_friends = function_exists( 'bp_is_active' ) && bp_is_active( 'friends' );
				?>
				<select id="mvs-activity-privacy" class="mvs-activity-privacy" name="mvs_activity_privacy" style="display:none" aria-label="<?php esc_attr_e( 'Who can see this post', 'wpmediaverse' ); ?>">
					<option value="public" <?php selected( $default_privacy, 'public' ); ?>><?php esc_html_e( 'Public: anyone can see', 'wpmediaverse' ); ?></option>
					<option value="members" <?php selected( $default_privacy, 'members' ); ?>><?php esc_html_e( 'Members: logged-in users only', 'wpmediaverse' ); ?></option>
					<?php if ( $show_friends ) : ?>
						<option value="friends" <?php selected( $default_privacy, 'friends' ); ?>><?php esc_html_e( 'Friends: BuddyPress friends only', 'wpmediaverse' ); ?></option>
					<?php endif; ?>
					<option value="private" <?php selected( $default_privacy, 'private' ); ?>><?php esc_html_e( 'Only me: hidden from everyone else', 'wpmediaverse' ); ?></option>
				</select>
			<?php endif; ?>
			<?php
			// Tags for the attached media. Hidden until files are chosen (the JS
			// reveals it alongside the privacy selector). Applied to each media
			// at post time in attach_media_to_activity() — the upload fires the
			// moment files are picked, before the user can type, so tagging can't
			// happen at upload time (same constraint as the privacy dropdown).
			// Card #9962548621: previously there was no way to tag media added
			// from the activity form.
			?>
			<input
				type="text"
				id="mvs-activity-tags"
				class="mvs-activity-tags"
				name="mvs_activity_media_tags"
				value=""
				style="display:none"
				autocomplete="off"
				placeholder="<?php esc_attr_e( 'Add tags (comma separated)', 'wpmediaverse' ); ?>"
				aria-label="<?php esc_attr_e( 'Tags for the attached media', 'wpmediaverse' ); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * Enqueue JS/CSS for the activity media attachment button.
	 */
	public function enqueue_activity_media_scripts(): void {
		// Activity surfaces need both stylesheets:
		// mvs-frontend       — design tokens, media grid, lightbox (shared).
		// mvs-bp-integration — all BP-scoped rules (`#buddypress X`),
		// including activity form controls, activity
		// stream image sizing, and theme-compat
		// overrides for Reign/BuddyBoss. Matches the
		// enqueue pattern already used by
		// ProfileTabIntegration and GroupTabIntegration
		// — every BP surface we touch loads both.
		wp_enqueue_style( 'mvs-frontend' );
		wp_enqueue_style( 'mvs-bp-integration' );

		// Lightbox handled by shared-ui Interactivity API module — no legacy JS needed.

		// Upload button and activity-media JS only for logged-in users.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$js_path = MVS_PLUGIN_DIR . 'assets/js/bp-activity-media.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		// Lucide is registered globally via Plugin::register_lucide_script()
		// on `wp_enqueue_scripts@1`. No defensive re-registration here —
		// just enqueue so the attach-media button's `<i data-lucide>` icon
		// hydrates. (The hydration inline installs a MutationObserver so
		// BP Backbone's composer re-render also gets picked up.)
		wp_enqueue_script( 'mvs-lucide' );

		wp_enqueue_script(
			'mvs-bp-activity-media',
			MVS_PLUGIN_URL . 'assets/js/bp-activity-media.js',
			array( 'mvs-rest', 'jquery', 'mvs-lucide', 'wp-i18n' ),
			filemtime( $js_path ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'mvs-bp-activity-media', 'wpmediaverse' );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- hooked into bp_activity_posted_update; BuddyPress verifies its own nonce before this callback fires.
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

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Privacy chosen in the activity-form dropdown ("Who can see this post").
		// Media is uploaded BEFORE the user picks the dropdown, so it lands at
		// the default privacy (usually public). Without this step the dropdown
		// is dead UI: the activity stays public regardless of selection and
		// leaks to logged-out users on the sitewide stream (card 9867136209).
		$chosen_privacy = $this->resolve_chosen_privacy();

		// Tags typed in the activity-form tags field, applied per media below.
		$chosen_tags = $this->resolve_chosen_tags();

		$thumbnails = '';
		$valid_ids  = array();
		foreach ( $media_ids as $media_id ) {
			if ( ! $repo->exists( $media_id ) ) {
				continue;
			}
			$media_author = $repo->get_author( $media_id );
			if ( $media_author !== $user_id ) {
				continue;
			}

			// Skip media held for pre-moderation (the opt-in
			// mvs_hold_uploads_for_moderation filter). It stays draft +
			// moderation_status=pending in the moderation queue and is NOT
			// published or attached to the activity, so held content can never
			// reach the stream through an activity post (Basecamp #9962830813).
			// Once a moderator approves it, it shows in the member's media
			// normally. Default flow (filter off) is unaffected: media is
			// 'approved' and publishes here as before.
			if ( 'pending' === (string) $repo->get( $media_id, 'moderation_status' ) ) {
				continue;
			}

			// Publish draft media now that the activity is being posted.
			$media_status = $repo->get( $media_id, 'status' );
			if ( 'draft' === $media_status ) {
				$repo->set( $media_id, 'status', 'publish' );
			}

			// Apply the user's dropdown selection to the underlying media row.
			// MediaRepository::set() auto-fires `mvs_media_privacy_changed` so
			// downstream sync (cache, BunnyCDN purge, etc.) stays consistent.
			if ( '' !== $chosen_privacy ) {
				$current_privacy = (string) $repo->get( $media_id, 'privacy' );
				if ( $current_privacy !== $chosen_privacy ) {
					$repo->set( $media_id, 'privacy', $chosen_privacy );
				}
			}

			// Apply the tags typed in the activity form. Mirrors the tag handling
			// in MediaController::create_item — set the mvs_tag terms and cache the
			// JSON list on the media row (audit 2026-06-04, #9962548621).
			if ( ! empty( $chosen_tags ) ) {
				wp_set_object_terms( $media_id, $chosen_tags, 'mvs_tag' );
				$repo->set( $media_id, 'tags', wp_json_encode( array_values( $chosen_tags ) ) );
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
			$repo->set( $mid, 'bp_activity_id', $activity_id );
		}

		// Most-restrictive privacy across attached media (and their parent
		// albums) — any non-public attachment hides the parent activity
		// (Zoho #39974, card 9866323691). When the user picked an explicit
		// privacy via the form dropdown, effective_privacy_for_batch() now
		// reflects it because the loop above synced each media row.
		$effective_privacy = ActivitySyncIntegration::effective_privacy_for_batch( $valid_ids );

		// Private uploads leave zero public footprint — delete the activity
		// BuddyPress already inserted (we can't prevent the insert; we hook
		// on bp_activity_posted_update which fires AFTER bp_activity_add).
		// Without this, BP keeps the entry with hide_sitewide=1 + still
		// surfaces it on the author's profile activity tab to any visitor,
		// leaking the broken-thumbnail card (Basecamp #9936622656). Other
		// non-public levels (members/friends/group/custom) keep the
		// activity and rely on ActivityPrivacyFilter's viewer-aware WHERE.
		if ( 'private' === $effective_privacy && function_exists( 'bp_activity_delete' ) ) {
			bp_activity_delete( array( 'id' => $activity_id ) );
			// Untag the media so subsequent surfaces don't try to backfill
			// an activity that no longer exists.
			foreach ( $valid_ids as $mid ) {
				$repo->delete( $mid, 'bp_activity_id' );
			}
			return;
		}

		$hide_sitewide = ActivitySyncIntegration::privacy_to_hide_sitewide( $effective_privacy );

		$activity = new \BP_Activity_Activity( $activity_id );
		if ( $activity->id ) {
			$activity->content       = $new_content;
			$activity->hide_sitewide = $hide_sitewide ? 1 : 0;
			$activity->save();
		}

		// Persist the WPMV privacy intent on the activity itself
		// (rtMedia-parity activity meta). 1.3.0's viewer-side filter
		// will read these.
		bp_activity_update_meta( (int) $activity_id, '_mvs_activity_privacy', $effective_privacy );
		bp_activity_update_meta(
			(int) $activity_id,
			'_mvs_activity_privacy_level',
			(string) ActivitySyncIntegration::privacy_to_level( $effective_privacy )
		);
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
			// Cast for consistency with every other `_mvs_media_ids` read (we
			// write it as CSV, but a meta value is never type-guaranteed).
			$media_ids = array_filter( array_map( 'absint', explode( ',', (string) $raw_ids ) ) );
			foreach ( $media_ids as $media_id ) {
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'privacy', 'group' );
				\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'group_id', $group_id );
			}
		}
	}

	/**
	 * Resolve the privacy slug picked in the activity-form dropdown.
	 *
	 * Returns '' when the admin disabled per-post privacy, when no value was
	 * sent, when the value is unknown, or when the friends level is requested
	 * without the BP friends component active.
	 *
	 * @return string Sanitized privacy slug (public|members|friends|private) or ''.
	 */
	private function resolve_chosen_privacy(): string {
		if ( ! (bool) get_option( 'mvs_allow_user_privacy', true ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this helper is only called from attach_media_to_activity(), hooked into bp_activity_posted_update where BuddyPress has already verified the activity post nonce.
		if ( ! isset( $_POST['mvs_activity_privacy'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same context as the isset check above; sanitize_key() applied to the value before use.
		$chosen = sanitize_key( wp_unslash( $_POST['mvs_activity_privacy'] ) );

		$allowed = array( 'public', 'members', 'private' );
		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) {
			$allowed[] = 'friends';
		}

		return in_array( $chosen, $allowed, true ) ? $chosen : '';
	}

	/**
	 * Resolve and sanitize the tags typed in the activity-form tags field.
	 *
	 * Returns a de-duplicated list of sanitized tag names (capped to a sane
	 * count so a pasted blob can't spawn hundreds of terms), or an empty array
	 * when none were sent. Applied at post time in attach_media_to_activity()
	 * because the media is uploaded before the user can type — the same reason
	 * privacy is resolved here rather than at upload (card #9962548621).
	 *
	 * @return string[]
	 */
	private function resolve_chosen_tags(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only called from attach_media_to_activity(), hooked into bp_activity_posted_update where BuddyPress has already verified the activity post nonce.
		if ( empty( $_POST['mvs_activity_media_tags'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- same context as the empty() check above; each tag sanitized below.
		$raw  = sanitize_text_field( wp_unslash( $_POST['mvs_activity_media_tags'] ) );
		$tags = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$tags = array_map( 'sanitize_text_field', $tags );
		$tags = array_values( array_unique( $tags ) );

		/** Filter: max tags applied to media from one activity post. Default 15. */
		$max_tags = (int) apply_filters( 'mvs_activity_max_tags', 15 );

		return array_slice( $tags, 0, max( 1, $max_tags ) );
	}
}

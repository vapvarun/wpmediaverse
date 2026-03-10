<?php
/**
 * BuddyPress integration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations;

defined( 'ABSPATH' ) || exit;

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
	 * Initialize the integration.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		// Activity recording (only if activity component is active).
		if ( bp_is_active( 'activity' ) ) {
			add_action( 'mvs_media_uploaded', array( $this, 'record_upload_activity' ) );
			add_action( 'mvs_comment_created', array( $this, 'record_comment_activity' ), 10, 2 );
			add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );
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
			add_action( 'mvs_comment_created', array( $this, 'notify_comment' ), 10, 2 );
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
		$post = get_post( $activity->item_id );
		if ( ! $post ) {
			return $action;
		}
		$file_type  = get_post_meta( $activity->item_id, '_mvs_file_type', true );
		$type_label = $this->get_media_type_label( $file_type );
		return sprintf(
			/* translators: 1: user link, 2: media type, 3: media link */
			__( '%1$s uploaded a new %2$s: %3$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			esc_html( $type_label ),
			'<a href="' . esc_url( get_permalink( $activity->item_id ) ) . '">' . esc_html( $post->post_title ) . '</a>'
		);
	}

	/**
	 * Format the comment activity action string.
	 *
	 * @param string $action   Existing action string.
	 * @param object $activity Activity object.
	 * @return string
	 */
	public function format_activity_action_comment( $action, $activity ) {
		$post = get_post( $activity->item_id );
		if ( ! $post ) {
			return $action;
		}
		return sprintf(
			/* translators: 1: user link, 2: media link */
			__( '%1$s commented on %2$s', 'wpmediaverse' ),
			bp_core_get_userlink( $activity->user_id ),
			'<a href="' . esc_url( get_permalink( $activity->item_id ) ) . '">' . esc_html( $post->post_title ) . '</a>'
		);
	}

	/**
	 * Record activity when media is uploaded.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function record_upload_activity( int $media_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_activity_add' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		$post = get_post( $media_id );
		if ( ! $post ) {
			return;
		}

		$user_id   = (int) $post->post_author;
		$thumbnail = $this->get_media_thumbnail_html( $media_id, 'medium' );

		bp_activity_add(
			array(
				'user_id'   => $user_id,
				'component' => 'wpmediaverse',
				'type'      => 'mvs_media_upload',
				'action'    => '', // Populated by format callback.
				'content'   => $thumbnail,
				'item_id'   => $media_id,
			)
		);
	}

	/**
	 * Record activity when a comment is created.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $media_id   Media post ID.
	 */
	public function record_comment_activity( int $comment_id, int $media_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_activity_add' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		$post    = get_post( $media_id );
		if ( ! $comment || ! $post ) {
			return;
		}

		$user_id = (int) $comment->user_id;

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
	 * Find the upload activity for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return object|null Activity row or null.
	 */
	private function find_media_upload_activity( int $media_id ) {
		if ( ! function_exists( 'bp_activity_get' ) ) {
			return null;
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

		$count_query = new \WP_Query(
			array(
				'post_type'      => 'mvs_media',
				'post_status'    => 'publish',
				'author'         => $displayed_user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		$media_count = $count_query->found_posts;

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
	 */
	public function render_profile_albums_tab(): void {
		add_action( 'bp_template_content', array( $this, 'profile_albums_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Output media grid for the displayed user's profile.
	 */
	public function profile_media_content(): void {
		// Ensure frontend CSS is loaded.
		wp_enqueue_style( 'mvs-frontend' );

		$user_id    = bp_displayed_user_id();
		$is_own     = is_user_logged_in() && get_current_user_id() === $user_id;
		$paged      = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page   = 18;
		$stats_tbl  = $GLOBALS['wpdb']->prefix . 'mvs_media_stats';

		// Inline upload for own profile.
		if ( $is_own ) {
			$rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
			$nonce    = wp_create_nonce( 'wp_rest' );

			?>
			<div class="mvs-bp-upload-wrap">
				<input type="file" multiple accept="image/*,video/*,audio/*" class="mvs-bp-file-input" id="mvs-bp-file-input" style="display:none" />
				<div class="mvs-bp-dropzone" id="mvs-bp-dropzone">
					<span class="dashicons dashicons-cloud-upload"></span>
					<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-bp-upload-status" id="mvs-bp-upload-status" style="display:none;"></div>
			</div>
			<script>
			(function(){
				var restUrl = '<?php echo esc_js( $rest_url ); ?>';
				var nonce = '<?php echo esc_js( $nonce ); ?>';
				var dropzone = document.getElementById('mvs-bp-dropzone');
				var fileInput = document.getElementById('mvs-bp-file-input');
				var statusEl = document.getElementById('mvs-bp-upload-status');

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
					uploadFiles(Array.from(e.dataTransfer.files));
				});
				fileInput.addEventListener('change', function() {
					uploadFiles(Array.from(fileInput.files));
					fileInput.value = '';
				});

				function uploadFiles(files) {
					if (!files.length) return;
					statusEl.style.display = 'block';
					var total = files.length, done = 0;
					statusEl.textContent = 'Uploading 1 of ' + total + '...';

					function next() {
						if (done >= total) {
							statusEl.textContent = total + ' file(s) uploaded!';
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
						}).then(function() {
							done++;
							if (done < total) statusEl.textContent = 'Uploading ' + (done + 1) + ' of ' + total + '...';
							next();
						}).catch(function() {
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

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_media',
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
				echo '<p>' . esc_html__( 'You haven\'t uploaded any media yet. Get started!', 'wpmediaverse' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'This user hasn\'t uploaded any media yet.', 'wpmediaverse' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		// Collect IDs for batch stats query.
		$media_ids = wp_list_pluck( $query->posts, 'ID' );
		$stats_map = array();
		if ( ! empty( $media_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats_rows = $GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare(
					"SELECT media_id, views, reactions FROM {$stats_tbl} WHERE media_id IN ({$placeholders})",
					...$media_ids
				),
				ARRAY_A
			);
			if ( $stats_rows ) {
				foreach ( $stats_rows as $row ) {
					$stats_map[ (int) $row['media_id'] ] = $row;
				}
			}
		}

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$mid       = get_the_ID();
			$file_url  = get_post_meta( $mid, '_mvs_file_url', true );
			$file_type = get_post_meta( $mid, '_mvs_file_type', true );
			$is_image  = strpos( $file_type, 'image/' ) === 0;
			$views     = isset( $stats_map[ $mid ]['views'] ) ? (int) $stats_map[ $mid ]['views'] : 0;
			$reactions = isset( $stats_map[ $mid ]['reactions'] ) ? (int) $stats_map[ $mid ]['reactions'] : 0;

			// Fix HTTPS mixed content.
			if ( $file_url ) {
				$file_url = set_url_scheme( $file_url );
			}

			// Resolve thumbnail: prefer file_url, fallback to WP attachment.
			$thumb_url = '';
			if ( $is_image && $file_url ) {
				$thumb_url = $file_url;
			} elseif ( $is_image ) {
				$attach_id = (int) get_post_meta( $mid, '_mvs_attachment_id', true );
				if ( $attach_id ) {
					$thumb_url = wp_get_attachment_image_url( $attach_id, 'medium' );
					if ( $thumb_url ) {
						$thumb_url = set_url_scheme( $thumb_url );
					}
				}
			}

			echo '<div class="mvs-grid-item">';
			echo '<a href="' . esc_url( get_permalink( $mid ) ) . '" class="mvs-grid-item-link">';
			if ( $thumb_url ) {
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder"><span class="dashicons dashicons-media-default"></span></div>';
			}
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			echo '<span class="mvs-grid-stat">&#x1F441;&#xFE0F; ' . esc_html( number_format_i18n( $views ) ) . '</span>';
			echo '<span class="mvs-grid-stat">&#x2764;&#xFE0F; ' . esc_html( number_format_i18n( $reactions ) ) . '</span>';
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
			$dash_page = (int) get_option( 'mvs_page_dashboard' );
			$dash_url  = $dash_page ? get_permalink( $dash_page ) : '';

			echo '<div class="mvs-bp-profile-actions">';
			if ( $dash_url ) {
				echo '<a href="' . esc_url( $dash_url ) . '#albums" class="mvs-btn">';
				echo '<span class="dashicons dashicons-plus-alt"></span> ' . esc_html__( 'Create Album', 'wpmediaverse' );
				echo '</a>';
			}
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

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$album_id   = get_the_ID();
			$cover_id   = (int) get_post_meta( $album_id, '_mvs_cover_media_id', true );
			$cover_url  = '';
			$item_count = 0;

			if ( $cover_id ) {
				$cover_url = get_post_meta( $cover_id, '_mvs_file_url', true );
			}

			// Fallback: use first album item's thumbnail as cover if no explicit cover set.
			if ( ! $cover_url ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$first_media_id = $GLOBALS['wpdb']->get_var(
					$GLOBALS['wpdb']->prepare(
						"SELECT media_id FROM {$GLOBALS['wpdb']->prefix}mvs_album_items WHERE album_id = %d ORDER BY position ASC, id ASC LIMIT 1",
						$album_id
					)
				);
				if ( $first_media_id ) {
					$attach_id = (int) get_post_meta( (int) $first_media_id, '_mvs_attachment_id', true );
					if ( $attach_id ) {
						$cover_url = wp_get_attachment_image_url( $attach_id, 'medium' );
					}
					if ( ! $cover_url ) {
						$cover_url = get_post_meta( (int) $first_media_id, '_mvs_file_url', true );
					}
				}
			}

			// Fix HTTPS mixed content.
			if ( $cover_url ) {
				$cover_url = set_url_scheme( $cover_url );
			}

			// Count album items.
			$item_count = (int) $GLOBALS['wpdb']->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$GLOBALS['wpdb']->prepare(
					"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}mvs_album_items WHERE album_id = %d",
					$album_id
				)
			);

			echo '<div class="mvs-grid-item mvs-grid-item--album">';
			echo '<a href="' . esc_url( get_permalink( $album_id ) ) . '" class="mvs-grid-item-link">';
			if ( $cover_url ) {
				echo '<img src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder"><span class="dashicons dashicons-format-gallery"></span></div>';
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
				'parent_url'      => bp_get_group_url( $group ) . 'media/',
				'parent_slug'     => $group->slug,
				'screen_function' => array( $this, 'render_group_media_tab' ),
				'position'        => 80,
			)
		);
	}

	/**
	 * Render the group media tab.
	 */
	public function render_group_media_tab(): void {
		add_action( 'bp_template_content', array( $this, 'group_media_content' ) );
		bp_core_load_template( 'groups/single/plugins' );
	}

	/**
	 * Output media grid for the current group.
	 */
	public function group_media_content(): void {
		// Ensure frontend CSS is loaded.
		wp_enqueue_style( 'mvs-frontend' );

		$group = groups_get_current_group();
		if ( ! $group ) {
			return;
		}

		$member_ids = array();
		$members    = groups_get_group_members(
			array(
				'group_id' => $group->id,
				'per_page' => 0,
			)
		);
		if ( ! empty( $members['members'] ) ) {
			$member_ids = wp_list_pluck( $members['members'], 'ID' );
		}

		if ( empty( $member_ids ) ) {
			echo '<div class="mvs-empty-state"><span class="dashicons dashicons-format-gallery"></span>';
			echo '<p>' . esc_html__( 'No group media yet. Members can share media with the group!', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		$paged     = isset( $_GET['mpage'] ) ? absint( $_GET['mpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page  = 18;
		$stats_tbl = $GLOBALS['wpdb']->prefix . 'mvs_media_stats';

		$query = new \WP_Query(
			array(
				'post_type'      => 'mvs_media',
				'post_status'    => 'publish',
				'author__in'     => $member_ids,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_mvs_privacy',
						'value' => 'group',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<div class="mvs-empty-state"><span class="dashicons dashicons-format-gallery"></span>';
			echo '<p>' . esc_html__( 'No group media yet. Members can share media with the group!', 'wpmediaverse' ) . '</p></div>';
			return;
		}

		// Batch fetch stats.
		$media_ids = wp_list_pluck( $query->posts, 'ID' );
		$stats_map = array();
		if ( ! empty( $media_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stats_rows = $GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare(
					"SELECT media_id, views, reactions FROM {$stats_tbl} WHERE media_id IN ({$placeholders})",
					...$media_ids
				),
				ARRAY_A
			);
			if ( $stats_rows ) {
				foreach ( $stats_rows as $row ) {
					$stats_map[ (int) $row['media_id'] ] = $row;
				}
			}
		}

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$mid       = get_the_ID();
			$file_url  = get_post_meta( $mid, '_mvs_file_url', true );
			$file_type = get_post_meta( $mid, '_mvs_file_type', true );
			$is_image  = strpos( $file_type, 'image/' ) === 0;
			$views     = isset( $stats_map[ $mid ]['views'] ) ? (int) $stats_map[ $mid ]['views'] : 0;
			$reactions = isset( $stats_map[ $mid ]['reactions'] ) ? (int) $stats_map[ $mid ]['reactions'] : 0;

			// Fix HTTPS mixed content.
			if ( $file_url ) {
				$file_url = set_url_scheme( $file_url );
			}

			// Resolve thumbnail: prefer file_url, fallback to WP attachment.
			$thumb_url = '';
			if ( $is_image && $file_url ) {
				$thumb_url = $file_url;
			} elseif ( $is_image ) {
				$attach_id = (int) get_post_meta( $mid, '_mvs_attachment_id', true );
				if ( $attach_id ) {
					$thumb_url = wp_get_attachment_image_url( $attach_id, 'medium' );
					if ( $thumb_url ) {
						$thumb_url = set_url_scheme( $thumb_url );
					}
				}
			}

			echo '<div class="mvs-grid-item">';
			echo '<a href="' . esc_url( get_permalink( $mid ) ) . '" class="mvs-grid-item-link">';
			if ( $thumb_url ) {
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
			} else {
				echo '<div class="mvs-grid-item-placeholder"><span class="dashicons dashicons-media-default"></span></div>';
			}
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			echo '<span class="mvs-grid-stat">&#x1F441;&#xFE0F; ' . esc_html( number_format_i18n( $views ) ) . '</span>';
			echo '<span class="mvs-grid-stat">&#x2764;&#xFE0F; ' . esc_html( number_format_i18n( $reactions ) ) . '</span>';
			echo '</div></div>';
			echo '</a>';
			echo '<div class="mvs-grid-item-info">';
			echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-grid-avatar' ) );
			echo '<span class="mvs-grid-item-author">' . esc_html( get_the_author() ) . '</span>';
			echo '</div>';
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

		$post = get_post( $media_id );
		if ( ! $post || (int) $post->post_author === $user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => (int) $post->post_author,
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
	 * @param int $comment_id Comment ID.
	 * @param int $media_id   Media post ID.
	 */
	public function notify_comment( int $comment_id, int $media_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		$post    = get_post( $media_id );
		if ( ! $comment || ! $post || (int) $post->post_author === (int) $comment->user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => (int) $post->post_author,
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

		$post     = get_post( $media_id );
		$actor_id = $post ? (int) $post->post_author : 0;

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

		$post      = get_post( $item_id );
		$user_name = bp_core_get_user_displayname( $secondary_item_id );
		$link      = $post ? get_permalink( $post->ID ) : '';

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
	private function get_media_thumbnail_html( int $media_id, string $size = 'medium' ): string {
		$attach_id = (int) get_post_meta( $media_id, '_mvs_attachment_id', true );
		$thumb_url = '';

		if ( $attach_id ) {
			$thumb_url = wp_get_attachment_image_url( $attach_id, $size );
		}
		if ( ! $thumb_url ) {
			$file_url  = get_post_meta( $media_id, '_mvs_file_url', true );
			$file_type = get_post_meta( $media_id, '_mvs_file_type', true );
			if ( $file_url && strpos( $file_type, 'image/' ) === 0 ) {
				$thumb_url = $file_url;
			}
		}

		if ( ! $thumb_url ) {
			return '';
		}

		$thumb_url = set_url_scheme( $thumb_url );
		$permalink = get_permalink( $media_id );

		return '<div class="mvs-activity-media"><a href="' . esc_url( $permalink ) . '"><img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( get_the_title( $media_id ) ) . '" loading="lazy" /></a></div>';
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
			<input type="file" id="mvs-activity-media-file" accept="image/*,video/*" style="display:none" />
			<button type="button" id="mvs-activity-media-btn" class="mvs-activity-media-btn" title="<?php esc_attr_e( 'Attach media', 'wpmediaverse' ); ?>">
				<span class="dashicons dashicons-format-image"></span>
				<?php esc_html_e( 'Photo/Video', 'wpmediaverse' ); ?>
			</button>
			<div id="mvs-activity-media-preview" class="mvs-activity-media-preview" style="display:none"></div>
			<input type="hidden" id="mvs-activity-media-id" name="mvs_activity_media_id" value="" />
		</div>
		<?php
	}

	/**
	 * Enqueue JS/CSS for the activity media attachment button.
	 */
	public function enqueue_activity_media_scripts(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_style( 'mvs-frontend' );

		$js_path = plugin_dir_path( dirname( __DIR__ ) ) . 'assets/js/bp-activity-media.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( __DIR__ ) );
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
		$media_id = isset( $_POST['mvs_activity_media_id'] ) ? absint( $_POST['mvs_activity_media_id'] ) : 0;
		if ( ! $media_id ) {
			return;
		}

		$post = get_post( $media_id );
		if ( ! $post || 'mvs_media' !== $post->post_type ) {
			return;
		}

		// Verify the user owns this media.
		if ( (int) $post->post_author !== $user_id ) {
			return;
		}

		$thumbnail   = $this->get_media_thumbnail_html( $media_id, 'medium' );
		$new_content = $content . $thumbnail;

		bp_activity_update_meta( $activity_id, '_mvs_media_id', $media_id );

		// Update activity content to include the thumbnail.
		$activity = new \BP_Activity_Activity( $activity_id );
		if ( $activity->id ) {
			$activity->content = $new_content;
			$activity->save();
		}
	}

	/**
	 * Get a human-readable label for a MIME type.
	 *
	 * @param string $file_type MIME type.
	 * @return string
	 */
	private function get_media_type_label( string $file_type ): string {
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
}

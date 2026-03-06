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

		// Activity recording.
		add_action( 'mvs_media_uploaded', array( $this, 'record_upload_activity' ) );
		add_action( 'mvs_reaction_added', array( $this, 'record_reaction_activity' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'record_comment_activity' ), 10, 2 );

		// Profile tab.
		add_action( 'bp_setup_nav', array( $this, 'add_profile_tab' ), 100 );

		// Group tab.
		add_action( 'bp_setup_nav', array( $this, 'add_group_tab' ), 100 );

		// Notifications.
		add_action( 'mvs_reaction_added', array( $this, 'notify_reaction' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'notify_comment' ), 10, 2 );
		add_action( 'mvs_mentions_created', array( $this, 'notify_mentions' ), 10, 2 );

		// Register activity component.
		add_filter( 'bp_notifications_get_registered_components', array( $this, 'register_notification_component' ) );
		add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format_notifications' ), 10, 8 );
	}

	/**
	 * Record activity when media is uploaded.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function record_upload_activity( int $media_id ): void {
		if ( ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$post = get_post( $media_id );
		if ( ! $post ) {
			return;
		}

		$user_id    = (int) $post->post_author;
		$file_type  = get_post_meta( $media_id, '_mvs_file_type', true );
		$type_label = $this->get_media_type_label( $file_type );

		bp_activity_add(
			array(
				'user_id'   => $user_id,
				'component' => 'wpmediaverse',
				'type'      => 'mvs_media_upload',
				'action'    => sprintf(
					/* translators: 1: user link, 2: media type, 3: media link */
					__( '%1$s uploaded a new %2$s: %3$s', 'wpmediaverse' ),
					bp_core_get_userlink( $user_id ),
					esc_html( $type_label ),
					'<a href="' . esc_url( get_permalink( $media_id ) ) . '">' . esc_html( $post->post_title ) . '</a>'
				),
				'item_id'   => $media_id,
			)
		);
	}

	/**
	 * Record activity when a reaction is added.
	 *
	 * @param int    $media_id      Media post ID.
	 * @param int    $user_id       User ID.
	 * @param string $reaction_type Reaction type.
	 */
	public function record_reaction_activity( int $media_id, int $user_id, string $reaction_type ): void {
		if ( ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$post = get_post( $media_id );
		if ( ! $post ) {
			return;
		}

		bp_activity_add(
			array(
				'user_id'   => $user_id,
				'component' => 'wpmediaverse',
				'type'      => 'mvs_reaction',
				'action'    => sprintf(
					/* translators: 1: user link, 2: reaction type, 3: media link */
					__( '%1$s reacted %2$s to %3$s', 'wpmediaverse' ),
					bp_core_get_userlink( $user_id ),
					esc_html( $reaction_type ),
					'<a href="' . esc_url( get_permalink( $media_id ) ) . '">' . esc_html( $post->post_title ) . '</a>'
				),
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
		if ( ! function_exists( 'bp_activity_add' ) ) {
			return;
		}

		$comment = get_comment( $comment_id );
		$post    = get_post( $media_id );
		if ( ! $comment || ! $post ) {
			return;
		}

		$user_id = (int) $comment->user_id;
		bp_activity_add(
			array(
				'user_id'           => $user_id,
				'component'         => 'wpmediaverse',
				'type'              => 'mvs_comment',
				'action'            => sprintf(
					/* translators: 1: user link, 2: media link */
					__( '%1$s commented on %2$s', 'wpmediaverse' ),
					bp_core_get_userlink( $user_id ),
					'<a href="' . esc_url( get_permalink( $media_id ) ) . '">' . esc_html( $post->post_title ) . '</a>'
				),
				'item_id'           => $media_id,
				'secondary_item_id' => $comment_id,
			)
		);
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

		// Parent nav item.
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
			$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
			$views     = isset( $stats_map[ $mid ]['views'] ) ? (int) $stats_map[ $mid ]['views'] : 0;
			$reactions = isset( $stats_map[ $mid ]['reactions'] ) ? (int) $stats_map[ $mid ]['reactions'] : 0;

			echo '<div class="mvs-grid-item">';
			echo '<a href="' . esc_url( get_permalink( $mid ) ) . '" class="mvs-grid-item-link">';
			if ( $is_image ) {
				echo '<img src="' . esc_url( $file_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
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
			$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
			$views     = isset( $stats_map[ $mid ]['views'] ) ? (int) $stats_map[ $mid ]['views'] : 0;
			$reactions = isset( $stats_map[ $mid ]['reactions'] ) ? (int) $stats_map[ $mid ]['reactions'] : 0;

			echo '<div class="mvs-grid-item">';
			echo '<a href="' . esc_url( get_permalink( $mid ) ) . '" class="mvs-grid-item-link">';
			if ( $is_image ) {
				echo '<img src="' . esc_url( $file_url ) . '" alt="' . esc_attr( get_the_title() ) . '" loading="lazy" />';
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
		if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
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
		if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
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
		if ( ! function_exists( 'bp_notifications_add_notification' ) ) {
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

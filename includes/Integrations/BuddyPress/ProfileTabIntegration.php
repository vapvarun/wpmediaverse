<?php
/**
 * ProfileTabIntegration — user profile media tab for BuddyPress.
 *
 * Adds a "Media" tab with sub-tabs (All Media, Albums) to BuddyPress member
 * profiles, including media grids, album listings, inline upload, and album
 * creation.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Manages the Media tab on BuddyPress member profiles.
 */
class ProfileTabIntegration {

	/**
	 * Register profile tab hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		add_action( 'bp_setup_nav', array( $this, 'add_profile_tab' ), 100 );
		add_action( 'bp_template_redirect', array( $this, 'update_media_tab_count' ) );
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
		$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );

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
		$table  = $wpdb->prefix . 'mvs_media_index';
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
		$stats_map = TemplateHelpers::bulk_get_stats( $media_ids );

		echo '<div class="mvs-media-grid mvs-cols-3 mvs-feed" data-mvs-grid-container>';
		foreach ( $media_ids as $mid ) {
			TemplateHelpers::render_grid_item(
				$mid,
				$stats_map[ $mid ] ?? array(),
				array( 'show_author' => true )
			);
		}
		echo '</div>';

		// Load More.
		$max_pages = (int) ceil( $total_count / $per_page );
		if ( $max_pages > 1 ) {
			$mvs_bp_user_id = bp_displayed_user_id();
			echo '<div class="mvs-load-more">';
			echo '<button type="button" class="mvs-load-more-btn"';
			echo ' data-rest-url="' . esc_attr( rest_url( 'mvs/v1/' ) ) . '"';
			echo ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
			echo ' data-page="' . esc_attr( $paged ) . '"';
			echo ' data-per-page="' . esc_attr( $per_page ) . '"';
			echo ' data-endpoint="media"';
			echo ' data-layout="grid"';
			echo ' data-author="' . absint( $mvs_bp_user_id ) . '"';
			echo ' data-scope=""';
			echo ' data-group-covers="true">';
			echo '<span class="mvs-load-more-label">' . esc_html__( 'Load More', 'wpmediaverse' ) . '</span>';
			echo '<span class="mvs-load-more-spinner"></span>';
			echo '</button>';
			echo '</div>';
			echo '<p class="mvs-load-more-end" hidden>' . esc_html__( "You're all caught up!", 'wpmediaverse' ) . '</p>';
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
		$per_page = absint( get_option( 'mvs_items_per_page', 12 ) );

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
			(int) $item_count
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
				TemplateHelpers::render_grid_item(
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
}

<?php
/**
 * Template: Single Album.
 *
 * Album is still a CPT (mvs_album), but media items inside it are read from
 * mvs_media_index via mvs_album_items table -- not from wp_posts.
 *
 * Override by copying to your-theme/wpmediaverse/album.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();

do_action( 'mvs_before_content' );

// Archive URL (base media page).
$mvs_archive_url = home_url( '/media/' );
?>
<div class="mvs-single-album">
	<?php
	while ( have_posts() ) :
		the_post();

		global $wpdb;
		$album_items_table = $wpdb->prefix . 'mvs_album_items';
		$index_table       = $wpdb->prefix . 'mvs_media_index';

		// Get media items from album via the join to mvs_media_index.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT idx.* FROM {$album_items_table} ai
				INNER JOIN {$index_table} idx ON idx.media_id = ai.media_id
				WHERE ai.album_id = %d AND idx.status = 'publish'
				ORDER BY ai.position ASC",
				get_the_ID()
			),
			ARRAY_A
		);
		// phpcs:enable

		$item_ids = array_column( $items, 'media_id' );
		?>

		<?php
		$album_privacy = \WPMediaVerse\Repository\MediaRepository::get( get_the_ID(), 'privacy' );
		if ( ! $album_privacy ) {
			$album_privacy = 'public';
		}
		$album_type         = \WPMediaVerse\Repository\MediaRepository::get( get_the_ID(), 'album_type' );
		$mvs_is_album_owner = is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id();
		?>

		<article id="mvs-album-<?php the_ID(); ?>" <?php post_class( 'mvs-album-article' ); ?>>
			<?php \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_back_link( 'album', array( 'author_id' => (int) get_the_author_meta( 'ID' ) ) ); ?>

			<!-- Info Card -->
			<div class="mvs-collection-card-info">
				<h1 class="mvs-collection-card-title"><?php the_title(); ?></h1>
				<div class="mvs-collection-card-meta">
					<span class="mvs-collection-meta-author">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-collection-avatar' ) ); ?>
						<?php
						$mvs_author_id  = get_the_author_meta( 'ID' );
						$mvs_author_url = '';
						if ( function_exists( 'bp_members_get_user_url' ) ) {
							$mvs_author_url = bp_members_get_user_url( $mvs_author_id );
						} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
							$mvs_author_url = bp_core_get_user_domain( $mvs_author_id );
						} else {
							$mvs_author_url = home_url( '/media/@' . get_the_author_meta( 'user_login', $mvs_author_id ) . '/' );
						}

						if ( $mvs_author_url ) :
							?>
						<a href="<?php echo esc_url( $mvs_author_url ); ?>"><?php echo esc_html( get_the_author() ); ?></a>
						<?php else : ?>
						<span><?php echo esc_html( get_the_author() ); ?></span>
						<?php endif; ?>
					</span>
					<span class="mvs-collection-meta-text">
						<?php
						printf(
							/* translators: %d: number of items */
							esc_html( _n( '%d item', '%d items', count( $items ), 'wpmediaverse' ) ),
							count( $items )
						);
						?>
					</span>
					<span class="mvs-collection-type-badge"><?php echo esc_html( $album_type ? $album_type : 'album' ); ?></span>
					<span class="mvs-privacy-badge"><?php echo esc_html( ucfirst( $album_privacy ) ); ?></span>
					<?php
					$mvs_album_categories = get_the_terms( get_the_ID(), 'mvs_category' );
					if ( $mvs_album_categories && ! is_wp_error( $mvs_album_categories ) ) :
						?>
						<span class="mvs-collection-meta-categories">
							<?php foreach ( $mvs_album_categories as $mvs_album_cat ) : ?>
								<a class="mvs-category-pill" href="<?php echo esc_url( get_term_link( $mvs_album_cat ) ); ?>">
									<?php echo esc_html( $mvs_album_cat->name ); ?>
								</a>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
				<?php if ( get_the_content() ) : ?>
					<div class="mvs-collection-card-desc"><?php the_content(); ?></div>
				<?php endif; ?>

				<?php if ( $mvs_is_album_owner ) : ?>
					<?php
					$mvs_album_ctx = array(
						'mediaId'            => get_the_ID(),
						'restUrl'            => esc_url_raw( rest_url( 'mvs/v1/' ) ),
						'nonce'              => wp_create_nonce( 'wp_rest' ),
						'isLoggedIn'         => true,
						'isOwner'            => true,
						'type'               => 'album',
						'archiveUrl'         => esc_url( $mvs_archive_url ),
						'initialTitle'       => get_the_title(),
						'initialDesc'        => get_the_content(),
						'initialPrivacy'     => $album_privacy,
						'initialTags'        => array(),
						'reactions'          => array(),
						'userReaction'       => '',
						'isFavorite'         => false,
						'comments'           => array(),
						'commentText'        => '',
						'viewCount'          => '',
						'editVisible'        => false,
						'editTitle'          => get_the_title(),
						'editDesc'           => get_the_content(),
						'editPrivacy'        => $album_privacy,
						'editTags'           => array(),
						'tagInput'           => '',
						'tagResults'         => array(),
						'tagDropdownVisible' => false,
						'saving'             => false,
						'shareLabel'         => "\xF0\x9F\x94\x97 Share",
					);
					?>
					<div class="mvs-social-wrapper"
						data-wp-interactive="mvs/media-social"
						<?php echo wp_interactivity_data_wp_context( $mvs_album_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						data-wp-init="callbacks.init">
						<div class="mvs-owner-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary mvs-btn--icon-collapse" type="button"
								data-wp-on--click="actions.toggleEdit"
								data-mvs-tooltip="<?php esc_attr_e( 'Edit album', 'wpmediaverse' ); ?>"
								aria-label="<?php esc_attr_e( 'Edit album', 'wpmediaverse' ); ?>">
								<i data-lucide="pencil" aria-hidden="true"></i>
								<span class="mvs-btn__label"><?php esc_html_e( 'Edit Album', 'wpmediaverse' ); ?></span>
							</button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger mvs-btn--icon-collapse" type="button"
								data-wp-on--click="actions.confirmDelete"
								data-mvs-tooltip="<?php esc_attr_e( 'Delete album', 'wpmediaverse' ); ?>"
								aria-label="<?php esc_attr_e( 'Delete album', 'wpmediaverse' ); ?>">
								<i data-lucide="trash-2" aria-hidden="true"></i>
								<span class="mvs-btn__label"><?php esc_html_e( 'Delete Album', 'wpmediaverse' ); ?></span>
							</button>
						</div>
						<div class="mvs-inline-edit" data-wp-bind--hidden="!context.editVisible">
							<div class="mvs-field">
								<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
								<input type="text" data-wp-bind--value="context.editTitle"
									data-wp-on--input="actions.updateEditTitle" />
							</div>
							<div class="mvs-field">
								<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
								<textarea data-wp-on--input="actions.updateEditDesc"
									data-wp-bind--value="context.editDesc"></textarea>
							</div>
							<div class="mvs-field">
								<label><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
								<select data-wp-on--change="actions.updateEditPrivacy">
									<?php foreach ( array( 'public', 'members', 'private' ) as $opt ) : ?>
										<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $album_privacy, $opt ); ?>>
											<?php echo esc_html( ucfirst( $opt ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="mvs-inline-edit-actions">
								<button class="mvs-btn" type="button"
									data-wp-on--click="actions.saveEdit"
									data-wp-bind--disabled="context.saving">
									<span data-wp-bind--hidden="context.saving"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
									<span data-wp-bind--hidden="!context.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
								</button>
								<button class="mvs-btn mvs-btn--secondary" type="button"
									data-wp-on--click="actions.cancelEdit">
									<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
								</button>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $mvs_is_album_owner ) : ?>
				<div class="mvs-album-upload-section">
					<button type="button" id="mvs-album-upload-btn" class="mvs-btn">
						<span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Add Media', 'wpmediaverse' ); ?>
					</button>
					<div id="mvs-album-upload-wrap" class="mvs-bp-upload-wrap" style="display:none;">
						<?php
						$mvs_album_mimes = get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' );
						?>
						<input type="file" multiple accept="<?php echo esc_attr( $mvs_album_mimes ); ?>" id="mvs-album-file-input" style="display:none" />
						<div class="mvs-bp-dropzone" id="mvs-album-dropzone">
							<span class="dashicons dashicons-cloud-upload"></span>
							<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload into this album', 'wpmediaverse' ); ?></span>
						</div>
						<div id="mvs-album-upload-preview" class="mvs-bp-upload-preview"></div>
						<div id="mvs-album-upload-status" class="mvs-bp-upload-status" style="display:none;"></div>
						<div class="mvs-bp-upload-form-actions">
							<button type="button" id="mvs-album-upload-cancel" class="mvs-btn mvs-btn--secondary"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
				<script>
				(function(){
					var albumId = <?php echo (int) get_the_ID(); ?>;
					var restUrl = '<?php echo esc_js( esc_url_raw( rest_url( 'mvs/v1/' ) ) ); ?>';
					var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
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
							if (!file.type.match(/^(image|video|audio)\//)) return;
							var thumb = document.createElement('div');
							thumb.className = 'mvs-bp-upload-thumb';
							if (file.type.match(/^image\//)) {
								var reader = new FileReader();
								reader.onload = function(e) {
									var img = document.createElement('img');
									img.src = e.target.result;
									thumb.appendChild(img);
								};
								reader.readAsDataURL(file);
							} else {
								var label = document.createElement('span');
								label.className = 'mvs-bp-upload-thumb-label';
								label.textContent = file.type.match(/^video\//) ? '\u25B6 ' + file.name : '\u266B ' + file.name;
								thumb.appendChild(label);
							}
							previewEl.appendChild(thumb);
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
			<?php endif; ?>

			<?php
			$album_type  = \WPMediaVerse\Repository\MediaRepository::get( get_the_ID(), 'album_type' );
			$is_playlist = 'playlist' === $album_type;
			?>

			<?php if ( ! empty( $items ) && $is_playlist ) : ?>
				<?php
				// Playlist mode: sequential audio tracklist.
				// Items are already full index rows from the joined query above.
				$tracks  = array();
				$alb_su  = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
				$alb_uid = get_current_user_id();
				foreach ( $items as $track_idx => $item_row ) {
					$track_media_id = (int) $item_row['media_id'];
					$track_art      = \WPMediaVerse\Repository\MediaRepository::get( $track_media_id, 'artist' );
					$dur_label      = '';
					if ( ! empty( $item_row['duration'] ) ) {
						$d         = (float) $item_row['duration'];
						$dur_label = sprintf( '%d:%02d', floor( $d / 60 ), (int) $d % 60 );
					}
					$tracks[] = array(
						'id'       => $track_media_id,
						'title'    => $item_row['title'] ?? '',
						'artist'   => $track_art ? $track_art : '',
						'url'      => $alb_su ? $alb_su->generate( $track_media_id, $alb_uid ) : '',
						'type'     => $item_row['file_type'] ?? '',
						'duration' => $dur_label,
						'link'     => \WPMediaVerse\Repository\MediaRepository::get_permalink( $track_media_id ),
					);
				}
				?>
				<div class="mvs-playlist" id="mvs-playlist-<?php the_ID(); ?>">
					<div class="mvs-playlist-player">
						<audio id="mvs-playlist-audio-<?php the_ID(); ?>" controls preload="metadata"></audio>
					</div>
					<div class="mvs-playlist-now-playing" id="mvs-playlist-now-<?php the_ID(); ?>"></div>
					<ol class="mvs-playlist-tracks">
						<?php foreach ( $tracks as $idx => $track ) : ?>
							<li class="mvs-playlist-track" data-track-index="<?php echo (int) $idx; ?>">
								<button type="button" class="mvs-playlist-track-btn" data-track-index="<?php echo (int) $idx; ?>">
									<span class="mvs-playlist-track-num"><?php echo (int) ( $idx + 1 ); ?></span>
									<span class="mvs-playlist-track-info">
										<span class="mvs-playlist-track-title"><?php echo esc_html( $track['title'] ); ?></span>
										<?php if ( $track['artist'] ) : ?>
											<span class="mvs-playlist-track-artist"><?php echo esc_html( $track['artist'] ); ?></span>
										<?php endif; ?>
									</span>
									<?php if ( $track['duration'] ) : ?>
										<span class="mvs-playlist-track-duration"><?php echo esc_html( $track['duration'] ); ?></span>
									<?php endif; ?>
								</button>
								<a href="<?php echo esc_url( $track['link'] ); ?>" class="mvs-playlist-track-link" title="<?php esc_attr_e( 'View details', 'wpmediaverse' ); ?>">&#8599;</a>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
				<script>
				(function(){
					var tracks = <?php echo wp_json_encode( $tracks ); ?>;
					var albumId = <?php echo (int) get_the_ID(); ?>;
					var audio = document.getElementById('mvs-playlist-audio-' + albumId);
					var nowEl = document.getElementById('mvs-playlist-now-' + albumId);
					var trackEls = document.querySelectorAll('#mvs-playlist-' + albumId + ' .mvs-playlist-track');
					var current = -1;

					function playTrack(idx) {
						if (idx < 0 || idx >= tracks.length || !tracks[idx].url) return;
						current = idx;
						audio.src = tracks[idx].url;
						audio.type = tracks[idx].type;
						audio.play();
						var label = tracks[idx].title;
						if (tracks[idx].artist) label = tracks[idx].artist + ' — ' + label;
						nowEl.textContent = label;
						trackEls.forEach(function(el, i) {
							el.classList.toggle('mvs-playlist-track--active', i === idx);
						});
					}

					audio.addEventListener('ended', function() {
						if (current + 1 < tracks.length) {
							playTrack(current + 1);
						}
					});

					document.querySelectorAll('#mvs-playlist-' + albumId + ' .mvs-playlist-track-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							playTrack(parseInt(btn.getAttribute('data-track-index'), 10));
						});
					});

					if (tracks.length > 0 && tracks[0].url) {
						audio.src = tracks[0].url;
						audio.type = tracks[0].type;
						nowEl.textContent = (tracks[0].artist ? tracks[0].artist + ' — ' : '') + tracks[0].title;
						trackEls[0].classList.add('mvs-playlist-track--active');
					}
				})();
				</script>
			<?php elseif ( ! empty( $items ) ) : ?>
				<?php
				$mvs_grid_cols     = max( 2, min( 5, (int) get_option( 'mvs_grid_columns', 3 ) ) );
				$mvs_album_svc     = \WPMediaVerse\Core\Plugin::container()->get( 'albums' );
				$mvs_current_cover = $mvs_album_svc ? $mvs_album_svc->get_cover_media_id( get_the_ID() ) : 0;
				?>
				<div class="mvs-media-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?>">
					<?php
					foreach ( $items as $item_row ) :
						$media_id = (int) $item_row['media_id'];
						$is_image = isset( $item_row['media_type'] ) && 'image' === $item_row['media_type'];
						$is_cover = $media_id === $mvs_current_cover;
						$show_cov = $mvs_is_album_owner && $is_image;
						if ( $show_cov ) {
							echo '<div class="mvs-album-item-wrap" data-media-id="' . esc_attr( (string) $media_id ) . '">';
						}
						\WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_item(
							$media_id,
							array(),
							array(
								'show_author'  => false,
								'show_overlay' => false,
							)
						);
						if ( $show_cov ) {
							if ( $is_cover ) {
								?>
								<span class="mvs-album-cover-badge"><?php esc_html_e( 'Cover', 'wpmediaverse' ); ?></span>
								<?php
							} else {
								?>
								<button type="button" class="mvs-album-set-cover" data-media-id="<?php echo esc_attr( (string) $media_id ); ?>">
									<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
									<span class="mvs-album-set-cover__label"><?php esc_html_e( 'Set as cover', 'wpmediaverse' ); ?></span>
								</button>
								<?php
							}
							echo '</div>';
						}
					endforeach;
					?>
				</div>
				<?php if ( $mvs_is_album_owner ) : ?>
				<script>
				(function(){
					var albumId = <?php echo (int) get_the_ID(); ?>;
					var restUrl = '<?php echo esc_js( esc_url_raw( rest_url( 'mvs/v1/' ) ) ); ?>';
					var nonce   = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
					var savingLabel = '<?php echo esc_js( __( 'Saving…', 'wpmediaverse' ) ); ?>';
					var defaultLabel = '<?php echo esc_js( __( 'Set as cover', 'wpmediaverse' ) ); ?>';
					var errorText = '<?php echo esc_js( __( 'Could not set cover', 'wpmediaverse' ) ); ?>';
					document.querySelectorAll('.mvs-album-set-cover').forEach(function (btn) {
						var labelEl = btn.querySelector('.mvs-album-set-cover__label');
						btn.addEventListener('click', function (ev) {
							ev.preventDefault();
							ev.stopPropagation();
							var mediaId = btn.getAttribute('data-media-id');
							if (!mediaId) return;
							btn.disabled = true;
							if (labelEl) { labelEl.textContent = savingLabel; }
							fetch(restUrl + 'albums/' + albumId + '/cover', {
								method: 'PUT',
								headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
								credentials: 'same-origin',
								body: JSON.stringify({ media_id: parseInt(mediaId, 10) })
							}).then(function (r) {
								if (!r.ok) { return r.json().then(function (d) { throw new Error(d && d.message || 'Failed'); }); }
								return r.json();
							}).then(function () {
								window.location.reload();
							}).catch(function (err) {
								btn.disabled = false;
								if (labelEl) { labelEl.textContent = defaultLabel; }
								alert((err && err.message) || errorText);
							});
						});
					});
				})();
				</script>
				<?php endif; ?>
			<?php else : ?>
				<p class="mvs-no-media"><?php esc_html_e( 'This album is empty.', 'wpmediaverse' ); ?></p>
			<?php endif; ?>
		</article>

	<?php endwhile; ?>
</div>
<?php
if ( is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id() ) :
	wp_enqueue_style( 'mvs-frontend' );

	// Enqueue Interactivity API stores.
	$mvs_shared_asset_file = MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php';
	$mvs_shared_asset      = file_exists( $mvs_shared_asset_file ) ? require $mvs_shared_asset_file : array(
		'dependencies' => array(),
		'version'      => MVS_VERSION,
	);
	wp_enqueue_script_module(
		'mvs-shared-ui',
		MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
		$mvs_shared_asset['dependencies'],
		$mvs_shared_asset['version']
	);

	$mvs_social_asset_file = MVS_PLUGIN_DIR . 'build/blocks/media-social/view.asset.php';
	$mvs_social_asset      = file_exists( $mvs_social_asset_file ) ? require $mvs_social_asset_file : array(
		'dependencies' => array(),
		'version'      => MVS_VERSION,
	);
	wp_enqueue_script_module(
		'mvs-media-social',
		MVS_PLUGIN_URL . 'build/blocks/media-social/view.js',
		$mvs_social_asset['dependencies'],
		$mvs_social_asset['version']
	);
endif;

// Shared UI: Toast + Confirm Dialog (required for delete action).
?>
<div class="mvs-toast" hidden
	data-wp-interactive="mvs/shared-ui"
	data-wp-bind--hidden="!state.toastVisible"
	data-wp-text="state.toastMessage"
	data-wp-class--mvs-toast--success="state.isToastSuccess"
	data-wp-class--mvs-toast--error="state.isToastError"></div>

<div class="mvs-confirm-overlay" hidden
	data-wp-interactive="mvs/shared-ui"
	data-wp-bind--hidden="!state.confirmVisible">
	<div class="mvs-confirm">
		<p data-wp-text="state.confirmMessage"></p>
		<div class="mvs-confirm-actions">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.handleConfirmCancel"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
			<button class="mvs-btn mvs-btn--danger" type="button"
				data-wp-on--click="actions.handleConfirmYes" data-wp-text="state.confirmButtonLabel"></button>
		</div>
	</div>
</div>
<?php
do_action( 'mvs_after_content' );

get_footer();

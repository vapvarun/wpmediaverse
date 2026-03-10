<?php
/**
 * Template: Single Album.
 *
 * Override by copying to your-theme/wpmediaverse/album.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-single-album">
	<?php
	while ( have_posts() ) :
		the_post();

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_album_items';
		$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				get_the_ID()
			)
		);
		?>

		<article id="mvs-album-<?php the_ID(); ?>" <?php post_class( 'mvs-album-article' ); ?>>
			<header class="mvs-album-header">
				<h1 class="mvs-album-title"><?php the_title(); ?></h1>
				<?php if ( get_the_content() ) : ?>
					<div class="mvs-album-description"><?php the_content(); ?></div>
				<?php endif; ?>

				<?php if ( is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id() ) : ?>
					<?php
					$album_privacy = get_post_meta( get_the_ID(), '_mvs_privacy', true );
					if ( ! $album_privacy ) {
						$album_privacy = 'public';
					}
					$mvs_album_ctx = array(
						'mediaId'        => get_the_ID(),
						'restUrl'        => esc_url_raw( rest_url( 'mvs/v1/' ) ),
						'nonce'          => wp_create_nonce( 'wp_rest' ),
						'isLoggedIn'     => true,
						'isOwner'        => true,
						'type'           => 'album',
						'archiveUrl'     => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
						'initialTitle'   => get_the_title(),
						'initialDesc'    => get_the_content(),
						'initialPrivacy' => $album_privacy,
						'initialTags'    => array(),
						'reactions'      => array(),
						'userReaction'   => '',
						'isFavorite'     => false,
						'comments'       => array(),
						'commentText'    => '',
						'viewCount'      => '',
						'editVisible'    => false,
						'editTitle'      => get_the_title(),
						'editDesc'       => get_the_content(),
						'editPrivacy'    => $album_privacy,
						'editTags'       => array(),
						'tagInput'       => '',
						'tagResults'     => array(),
						'tagDropdownVisible' => false,
						'saving'         => false,
						'shareLabel'     => "\xF0\x9F\x94\x97 Share",
					);
					?>
					<div class="mvs-social-wrapper"
						data-wp-interactive="mvs/media-social"
						<?php echo wp_interactivity_data_wp_context( $mvs_album_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						data-wp-init="callbacks.init">
						<div class="mvs-owner-actions" style="margin: 12px 0;">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.toggleEdit">
								<?php esc_html_e( 'Edit Album', 'wpmediaverse' ); ?>
							</button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDelete">
								<?php esc_html_e( 'Delete Album', 'wpmediaverse' ); ?>
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
									<span data-wp-text="context.saving ? '<?php echo esc_js( __( 'Saving...', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Save', 'wpmediaverse' ) ); ?>'"></span>
								</button>
								<button class="mvs-btn mvs-btn--secondary" type="button"
									data-wp-on--click="actions.cancelEdit">
									<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
								</button>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<span class="mvs-album-count">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html( _n( '%d item', '%d items', count( $items ), 'wpmediaverse' ) ),
						count( $items )
					);
					?>
				</span>
			</header>

			<?php if ( is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id() ) : ?>
				<div class="mvs-album-upload-section">
					<button type="button" id="mvs-album-upload-btn" class="mvs-btn">
						<span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Add Media', 'wpmediaverse' ); ?>
					</button>
					<div id="mvs-album-upload-wrap" class="mvs-bp-upload-wrap" style="display:none;">
						<input type="file" multiple accept="image/*,video/*,audio/*" id="mvs-album-file-input" style="display:none" />
						<div class="mvs-bp-dropzone" id="mvs-album-dropzone">
							<span class="dashicons dashicons-cloud-upload"></span>
							<span class="mvs-bp-dropzone-text"><?php esc_html_e( 'Drop files here or click to upload into this album', 'wpmediaverse' ); ?></span>
						</div>
						<div id="mvs-album-upload-preview" class="mvs-bp-upload-preview"></div>
						<div id="mvs-album-upload-status" class="mvs-bp-upload-status" style="display:none;"></div>
						<div class="mvs-bp-upload-form-actions">
							<button type="button" id="mvs-album-upload-cancel" class="mvs-btn mvs-btn-secondary"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
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
			<?php endif; ?>

			<?php if ( ! empty( $items ) ) : ?>
				<div class="mvs-media-grid mvs-cols-3">
					<?php foreach ( $items as $media_id ) : ?>
						<?php
						$file_url  = get_post_meta( $media_id, '_mvs_file_url', true );
						$file_type = get_post_meta( $media_id, '_mvs_file_type', true );
						$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
						?>
						<div class="mvs-grid-item">
							<?php if ( $is_image ) : ?>
								<a href="<?php echo esc_url( get_permalink( $media_id ) ); ?>">
									<img src="<?php echo esc_url( $file_url ); ?>"
										alt="<?php echo esc_attr( get_the_title( $media_id ) ); ?>"
										loading="lazy" />
								</a>
							<?php endif; ?>
							<div class="mvs-grid-item-overlay">
								<span><?php echo esc_html( get_the_title( $media_id ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
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
	$mvs_shared_asset      = file_exists( $mvs_shared_asset_file ) ? require $mvs_shared_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
	wp_enqueue_script_module(
		'mvs-shared-ui',
		MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
		$mvs_shared_asset['dependencies'],
		$mvs_shared_asset['version']
	);

	$mvs_social_asset_file = MVS_PLUGIN_DIR . 'build/blocks/media-social/view.asset.php';
	$mvs_social_asset      = file_exists( $mvs_social_asset_file ) ? require $mvs_social_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
	wp_enqueue_script_module(
		'mvs-media-social',
		MVS_PLUGIN_URL . 'build/blocks/media-social/view.js',
		$mvs_social_asset['dependencies'],
		$mvs_social_asset['version']
	);
endif;
get_footer();

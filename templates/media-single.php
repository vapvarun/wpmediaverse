<?php
/**
 * Template: Single Media Item.
 *
 * Reads media data from $GLOBALS['mvs_current_media'] (mvs_media_index row).
 * Override by copying to your-theme/wpmediaverse/media-single.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

$mvs_media = isset( $GLOBALS['mvs_current_media'] ) ? $GLOBALS['mvs_current_media'] : null;
if ( ! $mvs_media || empty( $mvs_media['media_id'] ) ) {
	get_header();
	echo '<div class="mvs-single-media"><p>' . esc_html__( 'Media not found.', 'wpmediaverse' ) . '</p></div>';
	get_footer();
	return;
}

$mvs_media_id  = (int) $mvs_media['media_id'];
$mvs_author_id = (int) $mvs_media['post_author'];
$mvs_title     = $mvs_media['title'] ?? '';
$mvs_desc      = $mvs_media['description'] ?? '';
$mvs_status    = $mvs_media['status'] ?? 'publish';
$mvs_privacy   = $mvs_media['privacy'] ?? 'public';
$mvs_file_url  = $mvs_media['file_url'] ?? '';
$mvs_file_type = $mvs_media['file_type'] ?? '';
$mvs_media_type = $mvs_media['media_type'] ?? '';
$mvs_width     = $mvs_media['width'] ?? '';
$mvs_height    = $mvs_media['height'] ?? '';
$mvs_duration  = $mvs_media['duration'] ?? '';
$mvs_attach_id = (int) ( $mvs_media['attachment_id'] ?? 0 );
$mvs_created   = $mvs_media['created_at'] ?? '';

// Privacy gate: block access to non-public media for unauthorized viewers.
if ( 'public' !== $mvs_privacy ) {
	$mvs_viewer_id = get_current_user_id();
	$mvs_can_view  = false;

	if ( $mvs_viewer_id === $mvs_author_id || current_user_can( 'moderate_mvs_media' ) ) {
		$mvs_can_view = true;
	} elseif ( 'members' === $mvs_privacy && $mvs_viewer_id > 0 ) {
		$mvs_can_view = true;
	}
	// 'private', 'friends', 'group' etc -- deny unless owner/admin.

	if ( ! $mvs_can_view ) {
		get_header();
		echo '<div class="mvs-single-media"><div class="mvs-privacy-blocked" style="text-align:center;padding:60px 20px;">';
		echo '<span style="font-size:48px;">&#128274;</span>';
		echo '<h2>' . esc_html__( 'This media is private', 'wpmediaverse' ) . '</h2>';
		echo '<p>' . esc_html__( 'You do not have permission to view this content.', 'wpmediaverse' ) . '</p>';
		echo '</div></div>';
		get_footer();
		return;
	}
}

get_header();

do_action( 'mvs_before_content' );

// Resolve media type flags.
$is_image = 'image' === $mvs_media_type;
$is_video = 'video' === $mvs_media_type;
$is_audio = 'audio' === $mvs_media_type;

// Extra metadata from meta table.
$artist     = \WPMediaVerse\Services\MediaMeta::get( $mvs_media_id, 'artist' );
$album_name = \WPMediaVerse\Services\MediaMeta::get( $mvs_media_id, 'album_name' );

// Poster/thumbnail from media meta.
$poster_url = \WPMediaVerse\Core\TemplateHelpers::get_thumb_url( $mvs_media_id, 'large' );

// Format duration for display.
$mvs_is_owner = is_user_logged_in() && $mvs_author_id === get_current_user_id();

$duration_display = '';
if ( $mvs_duration ) {
	$dur_float = (float) $mvs_duration;
	$hours     = floor( $dur_float / 3600 );
	$minutes   = floor( ( $dur_float % 3600 ) / 60 );
	$seconds   = (int) $dur_float % 60;
	if ( $hours > 0 ) {
		$duration_display = sprintf( '%d:%02d:%02d', $hours, $minutes, $seconds );
	} else {
		$duration_display = sprintf( '%d:%02d', $minutes, $seconds );
	}
}

// Author info.
$mvs_author      = get_userdata( $mvs_author_id );
$mvs_author_name = $mvs_author ? $mvs_author->display_name : __( 'Unknown', 'wpmediaverse' );
$mvs_author_login = $mvs_author ? $mvs_author->user_login : '';

// Format date.
$mvs_date_display = $mvs_created ? date_i18n( get_option( 'date_format' ), strtotime( $mvs_created ) ) : '';

// Permalink for this media.
$mvs_permalink = \WPMediaVerse\Services\MediaMeta::get_permalink( $mvs_media_id );

// Archive URL (base media page).
$mvs_archive_url = home_url( '/media/' );
?>
<div class="mvs-single-media">
	<article id="mvs-media-<?php echo absint( $mvs_media_id ); ?>" class="mvs-media-article">
		<header class="mvs-media-header">
			<div class="mvs-media-header-row">
				<div class="mvs-media-author-info">
					<?php echo get_avatar( $mvs_author_id, 40, '', '', array( 'class' => 'mvs-media-author-avatar' ) ); ?>
					<div class="mvs-media-author-text">
						<?php
						$mvs_author_url = '';
						if ( function_exists( 'bp_members_get_user_url' ) ) {
							$mvs_author_url = bp_members_get_user_url( $mvs_author_id );
						} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
							$mvs_author_url = bp_core_get_user_domain( $mvs_author_id );
						} else {
							$mvs_author_url = home_url( '/media/@' . $mvs_author_login . '/' );
						}

						if ( $mvs_author_url ) :
							?>
					<a href="<?php echo esc_url( $mvs_author_url ); ?>" class="mvs-media-author-name">
							<?php echo esc_html( $mvs_author_name ); ?>
					</a>
					<?php else : ?>
					<span class="mvs-media-author-name"><?php echo esc_html( $mvs_author_name ); ?></span>
					<?php endif; ?>
						<span class="mvs-media-date"><?php echo esc_html( $mvs_date_display ); ?>
							<?php if ( $duration_display ) : ?>
								<span class="mvs-media-sep">&middot;</span> <?php echo esc_html( $duration_display ); ?>
							<?php endif; ?>
							<?php if ( $is_video && $mvs_width && $mvs_height ) : ?>
								<span class="mvs-media-sep">&middot;</span> <?php echo esc_html( $mvs_width . 'x' . $mvs_height ); ?>
							<?php endif; ?>
						</span>
					</div>
				</div>
				<?php if ( is_user_logged_in() && ! $mvs_is_owner ) : ?>
				<span class="mvs-follow-btn-wrap"
					data-wp-interactive="mvs/media-social"
					<?php
					echo wp_interactivity_data_wp_context(
						array(
							'followAuthorId' => $mvs_author_id,
							'restUrl'        => esc_url_raw( rest_url( 'mvs/v1/' ) ),
							'nonce'          => wp_create_nonce( 'wp_rest' ),
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					data-wp-init="callbacks.initFollow">
					<button class="mvs-btn mvs-btn--small mvs-follow-btn" type="button"
						data-wp-class--active="context.isFollowing"
						data-wp-on--click="actions.toggleFollow"
						aria-label="<?php echo esc_attr( sprintf( __( 'Follow %s', 'wpmediaverse' ), $mvs_author_name ) ); ?>">
						<span data-wp-bind--hidden="context.isFollowing"><?php esc_html_e( 'Follow', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!context.isFollowing"><?php esc_html_e( 'Following', 'wpmediaverse' ); ?></span>
					</button>
				</span>
				<?php endif; ?>
			</div>
			<h1 class="mvs-media-title"><?php echo esc_html( $mvs_title ); ?></h1>
		</header>

		<div class="mvs-media-content">
			<?php if ( $is_image ) : ?>
				<div class="mvs-media-image">
					<img src="<?php echo esc_url( $mvs_file_url ); ?>" alt="<?php echo esc_attr( $mvs_title ); ?>" />
				</div>
			<?php elseif ( $is_video ) : ?>
				<div class="mvs-media-video"
					data-wp-interactive="mvs/media-player"
					<?php
					echo wp_interactivity_data_wp_context(
						array(
							'mediaId' => $mvs_media_id,
							'restUrl' => esc_url_raw( rest_url( 'mvs/v1/media/' . $mvs_media_id . '/view' ) ),
							'nonce'   => wp_create_nonce( 'wp_rest' ),
							'playing' => false,
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					data-wp-init="actions.trackView">
					<video controls preload="metadata"
						<?php echo $poster_url ? 'poster="' . esc_url( $poster_url ) . '"' : ''; ?>
						data-wp-on--play="actions.onPlay"
						data-wp-on--pause="actions.onPause">
						<source src="<?php echo esc_url( $mvs_file_url ); ?>" type="<?php echo esc_attr( $mvs_file_type ); ?>" />
					</video>
				</div>
			<?php elseif ( $is_audio ) : ?>
				<div class="mvs-media-audio"
					data-wp-interactive="mvs/media-player"
					<?php
					echo wp_interactivity_data_wp_context(
						array(
							'mediaId' => $mvs_media_id,
							'restUrl' => esc_url_raw( rest_url( 'mvs/v1/media/' . $mvs_media_id . '/view' ) ),
							'nonce'   => wp_create_nonce( 'wp_rest' ),
							'playing' => false,
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					data-wp-init="actions.trackView">
					<?php if ( $artist || $album_name ) : ?>
						<div class="mvs-audio-info">
							<?php if ( $artist ) : ?>
								<span class="mvs-audio-artist"><?php echo esc_html( $artist ); ?></span>
							<?php endif; ?>
							<?php if ( $album_name ) : ?>
								<span class="mvs-audio-album"><?php echo esc_html( $album_name ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<audio controls preload="metadata"
						data-wp-on--play="actions.onPlay"
						data-wp-on--pause="actions.onPause">
						<source src="<?php echo esc_url( $mvs_file_url ); ?>" type="<?php echo esc_attr( $mvs_file_type ); ?>" />
					</audio>
				</div>
			<?php endif; ?>

			<?php if ( $mvs_desc ) : ?>
				<div class="mvs-media-description">
					<?php echo wp_kses_post( wpautop( $mvs_desc ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php
		// Prepare Interactivity API context.
		$current_privacy = $mvs_privacy ? $mvs_privacy : 'public';

		// Get tags from taxonomy (media_id -> mvs_tag term relationships via mvs_media_meta).
		$mvs_tag_names = array();
		$mvs_tags_raw  = \WPMediaVerse\Services\MediaMeta::get( $mvs_media_id, 'tags' );
		if ( $mvs_tags_raw ) {
			// Handle both JSON array and comma-separated formats.
			$decoded = json_decode( $mvs_tags_raw, true );
			if ( is_array( $decoded ) ) {
				$mvs_tag_names = array_filter( array_map( 'trim', $decoded ) );
			} else {
				$mvs_tag_names = array_filter( array_map( 'trim', explode( ',', $mvs_tags_raw ) ) );
			}
		}
		// Also try taxonomy terms if still stored there.
		if ( empty( $mvs_tag_names ) ) {
			global $wpdb;
			$mvs_tag_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT t.name
					FROM {$wpdb->prefix}mvs_media_meta mm
					INNER JOIN {$wpdb->terms} t ON t.term_id = mm.meta_value
					WHERE mm.media_id = %d AND mm.meta_key = 'mvs_tag_id'",
					$mvs_media_id
				)
			);
			if ( $mvs_tag_rows ) {
				$mvs_tag_names = $mvs_tag_rows;
			}
		}
		// Final fallback: WP term relationships (legacy).
		if ( empty( $mvs_tag_names ) ) {
			$mvs_tags_list = get_the_terms( $mvs_media_id, 'mvs_tag' );
			if ( $mvs_tags_list && ! is_wp_error( $mvs_tags_list ) ) {
				foreach ( $mvs_tags_list as $mvs_t ) {
					$mvs_tag_names[] = $mvs_t->name;
				}
			}
		}

		$mvs_social_ctx = array(
			'mediaId'            => $mvs_media_id,
			'restUrl'            => esc_url_raw( rest_url( 'mvs/v1/' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn'         => is_user_logged_in(),
			'currentUserId'      => get_current_user_id(),
			'isOwner'            => $mvs_is_owner,
			'authorId'           => $mvs_author_id,
			'isFollowing'        => false,
			'type'               => 'media',
			'archiveUrl'         => esc_url( $mvs_archive_url ),
			'initialTitle'       => $mvs_title,
			'initialDesc'        => $mvs_desc,
			'initialPrivacy'     => $current_privacy,
			'initialTags'        => $mvs_tag_names,
			'reactions'          => array(),
			'userReaction'       => '',
			'isFavorite'         => false,
			'comments'           => array(),
			'commentText'        => '',
			'viewCount'          => '',
			'editVisible'        => false,
			'editTitle'          => $mvs_title,
			'editDesc'           => $mvs_desc,
			'editPrivacy'        => $current_privacy,
			'editTags'           => $mvs_tag_names,
			'tagInput'           => '',
			'tagResults'         => array(),
			'tagDropdownVisible' => false,
			'saving'             => false,
			'shareLabel'         => "\xF0\x9F\x94\x97 Share",
		);
		?>

		<div class="mvs-social-wrapper"
			data-wp-interactive="mvs/media-social"
			<?php echo wp_interactivity_data_wp_context( $mvs_social_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			data-wp-init="callbacks.init">

			<!-- Social Interactions Bar -->
			<div class="mvs-social-bar">
				<div class="mvs-reactions">
					<template data-wp-each="context.reactions">
						<button class="mvs-reaction-btn"
							data-wp-class--active="context.item.active"
							data-wp-bind--data-reaction-type="context.item.type"
							data-wp-bind--aria-label="context.item.type"
							data-wp-on--click="actions.toggleReaction">
							<span class="mvs-reaction-emoji" data-wp-text="context.item.emoji"></span>
							<span class="mvs-count" data-wp-text="context.item.count"></span>
						</button>
					</template>
				</div>
			</div>
			<div class="mvs-social-actions">
				<div class="mvs-social-actions-left">
					<?php if ( is_user_logged_in() ) : ?>
						<button class="mvs-favorite-btn" type="button"
							data-wp-class--active="context.isFavorite"
							data-wp-on--click="actions.toggleFavorite"
							aria-label="<?php esc_attr_e( 'Add to favorites', 'wpmediaverse' ); ?>">&#x2764; <?php esc_html_e( 'Favorite', 'wpmediaverse' ); ?></button>
					<?php else : ?>
						<a href="<?php echo esc_url( wp_login_url( $mvs_permalink ) ); ?>" class="mvs-favorite-btn mvs-login-prompt"
							title="<?php esc_attr_e( 'Log in to favorite', 'wpmediaverse' ); ?>"
							aria-label="<?php esc_attr_e( 'Log in to favorite', 'wpmediaverse' ); ?>">&#x2764; <?php esc_html_e( 'Favorite', 'wpmediaverse' ); ?></a>
					<?php endif; ?>
					<button class="mvs-share-btn" type="button"
						data-wp-on--click="actions.handleShare"
						data-wp-text="context.shareLabel"
						aria-label="<?php esc_attr_e( 'Share this media', 'wpmediaverse' ); ?>"></button>
				</div>
				<span class="mvs-view-count" data-wp-text="context.viewCount"></span>
				<div class="mvs-social-actions-right">
					<?php if ( $mvs_is_owner ) : ?>
						<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
							data-wp-on--click="actions.toggleEdit">
							<?php esc_html_e( 'Edit', 'wpmediaverse' ); ?>
						</button>
						<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
							data-wp-on--click="actions.confirmDelete">
							<?php esc_html_e( 'Delete', 'wpmediaverse' ); ?>
						</button>
					<?php elseif ( is_user_logged_in() ) : ?>
						<button class="mvs-btn mvs-btn--small mvs-btn--text" type="button"
							data-wp-on--click="actions.reportMedia"
							data-wp-bind--hidden="context.reported"
							aria-label="<?php esc_attr_e( 'Report this media', 'wpmediaverse' ); ?>">
							<?php esc_html_e( 'Report', 'wpmediaverse' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $mvs_is_owner ) : ?>
			<!-- Inline Edit Form -->
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
							<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $current_privacy, $opt ); ?>>
								<?php echo esc_html( ucfirst( $opt ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></label>
					<div class="mvs-tag-input-wrap">
						<div class="mvs-tag-pills">
							<template data-wp-each="context.editTags">
								<span class="mvs-tag-pill">
									<span data-wp-text="context.item"></span>
									<button type="button" class="mvs-tag-pill-remove"
										data-wp-bind--data-tag-name="context.item"
										data-wp-on--click="actions.removeTag">&times;</button>
								</span>
							</template>
							<input type="text" class="mvs-tag-text-input" placeholder="<?php esc_attr_e( 'Add tags...', 'wpmediaverse' ); ?>"
								data-wp-bind--value="context.tagInput"
								data-wp-on--input="actions.updateTagInput"
								data-wp-on--keydown="actions.addTagFromInput" />
						</div>
						<div class="mvs-tag-autocomplete" data-wp-bind--hidden="!context.tagDropdownVisible">
							<template data-wp-each="context.tagResults">
								<div class="mvs-tag-autocomplete-item"
									data-wp-bind--data-tag-name="context.item"
									data-wp-text="context.item"
									data-wp-on--click="actions.selectTag"></div>
							</template>
						</div>
					</div>
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
			<?php endif; ?>

			<!-- Comments Section -->
			<div class="mvs-comments-section">
				<h3 class="mvs-comments-title"><?php esc_html_e( 'Comments', 'wpmediaverse' ); ?></h3>
				<?php if ( is_user_logged_in() ) : ?>
					<form class="mvs-comment-form" data-wp-on--submit="actions.submitComment">
						<textarea placeholder="<?php esc_attr_e( 'Write a comment...', 'wpmediaverse' ); ?>" rows="2"
							data-wp-bind--value="context.commentText"
							data-wp-on--input="actions.updateCommentText"></textarea>
						<button type="submit" aria-label="<?php esc_attr_e( 'Post comment', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Post', 'wpmediaverse' ); ?></button>
					</form>
				<?php else : ?>
					<p class="mvs-login-to-comment">
						<a href="<?php echo esc_url( wp_login_url( $mvs_permalink ) ); ?>">
							<?php esc_html_e( 'Log in to leave a comment', 'wpmediaverse' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<ul class="mvs-comment-list">
					<template data-wp-each="context.comments">
						<li class="mvs-comment-item" data-wp-bind--data-comment-id="context.item.id">
							<div class="mvs-comment-header">
								<span class="mvs-comment-author" data-wp-text="context.item.author_name"></span>
								<span class="mvs-comment-date" data-wp-text="context.item.date"></span>
							</div>
							<div class="mvs-comment-body" data-wp-bind--hidden="context.item.editing">
								<div class="mvs-comment-text" data-wp-text="context.item.content"></div>
							</div>
							<div class="mvs-comment-edit-form" data-wp-bind--hidden="!context.item.editing">
								<textarea class="mvs-comment-edit-textarea" rows="2"
									data-wp-bind--value="context.item.editText"
									data-wp-on--input="actions.updateEditCommentText"></textarea>
								<div class="mvs-comment-edit-actions">
									<button class="mvs-btn mvs-btn--small" type="button"
										data-wp-on--click="actions.saveEditComment"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></button>
									<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
										data-wp-on--click="actions.cancelEditComment"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
								</div>
							</div>
							<div class="mvs-comment-actions" data-wp-bind--hidden="state.hideCommentActions">
								<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
									data-wp-on--click="actions.startEditComment"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
								<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
									data-wp-on--click="actions.deleteComment"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
							</div>
						</li>
					</template>
				</ul>
			</div>
		</div>

		<footer class="mvs-media-footer">
			<?php if ( ! empty( $mvs_tag_names ) ) : ?>
				<div class="mvs-media-tags">
					<?php foreach ( $mvs_tag_names as $tag_name ) : ?>
						<?php
						$tag_term = get_term_by( 'name', $tag_name, 'mvs_tag' );
						$tag_url  = $tag_term ? get_term_link( $tag_term ) : add_query_arg( 'mvs_tag', urlencode( $tag_name ), $mvs_archive_url );
						?>
						<a href="<?php echo esc_url( $tag_url ); ?>" class="mvs-tag">
							<?php echo esc_html( $tag_name ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</footer>
	</article>
</div>
<?php
// Enqueue Interactivity API stores.
$mvs_player_asset_file = MVS_PLUGIN_DIR . 'build/blocks/media-player/view.asset.php';
$mvs_player_asset      = file_exists( $mvs_player_asset_file ) ? require $mvs_player_asset_file : array(
	'dependencies' => array(),
	'version'      => MVS_VERSION,
);
wp_enqueue_script_module(
	'mvs-media-player',
	MVS_PLUGIN_URL . 'build/blocks/media-player/view.js',
	$mvs_player_asset['dependencies'],
	$mvs_player_asset['version']
);

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

// Shared UI: Toast + Confirm Dialog (required for delete/share actions).
?>
<div class="mvs-toast" hidden
	data-wp-interactive="mvs/shared-ui"
	data-wp-bind--hidden="!state.toast.visible"
	data-wp-text="state.toast.message"
	data-wp-class--mvs-toast--success="state.isToastSuccess"
	data-wp-class--mvs-toast--error="state.isToastError"></div>

<div class="mvs-confirm-overlay" hidden
	data-wp-interactive="mvs/shared-ui"
	data-wp-bind--hidden="!state.confirm.visible">
	<div class="mvs-confirm">
		<p data-wp-text="state.confirm.message"></p>
		<div class="mvs-confirm-actions">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.handleConfirmCancel"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
			<button class="mvs-btn mvs-btn--danger" type="button"
				data-wp-on--click="actions.handleConfirmYes"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
		</div>
	</div>
</div>
<?php
do_action( 'mvs_after_content' );

get_footer();

<?php
/**
 * Template: Single Media Item.
 *
 * Override by copying to your-theme/wpmediaverse/media-single.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-single-media">
	<?php
	while ( have_posts() ) :
		the_post();

		$file_url  = get_post_meta( get_the_ID(), '_mvs_file_url', true );
		$file_type = get_post_meta( get_the_ID(), '_mvs_file_type', true );
		$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
		$is_video  = $file_url && strpos( $file_type, 'video/' ) === 0;
		$is_audio  = $file_url && strpos( $file_type, 'audio/' ) === 0;
		?>

		<article id="mvs-media-<?php the_ID(); ?>" <?php post_class( 'mvs-media-article' ); ?>>
			<header class="mvs-media-header">
				<h1 class="mvs-media-title"><?php the_title(); ?></h1>
				<div class="mvs-media-meta">
					<span class="mvs-media-author">
						<?php
						printf(
							/* translators: %s: author display name */
							esc_html__( 'By %s', 'wpmediaverse' ),
							esc_html( get_the_author() )
						);
						?>
					</span>
					<span class="mvs-media-date"><?php echo esc_html( get_the_date() ); ?></span>
				</div>
			</header>

			<div class="mvs-media-content">
				<?php if ( $is_image ) : ?>
					<div class="mvs-media-image">
						<img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" />
					</div>
				<?php elseif ( $is_video ) : ?>
					<div class="mvs-media-video">
						<video controls preload="metadata" style="max-width:100%;">
							<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
						</video>
					</div>
				<?php elseif ( $is_audio ) : ?>
					<div class="mvs-media-audio">
						<audio controls preload="metadata" style="width:100%;">
							<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
						</audio>
					</div>
				<?php endif; ?>

				<?php if ( get_the_content() ) : ?>
					<div class="mvs-media-description">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			// Prepare Interactivity API context.
			$current_privacy = get_post_meta( get_the_ID(), '_mvs_privacy', true );
			if ( ! $current_privacy ) {
				$current_privacy = 'public';
			}
			$mvs_tag_names = array();
			$mvs_tags_list = get_the_terms( get_the_ID(), 'mvs_tag' );
			if ( $mvs_tags_list && ! is_wp_error( $mvs_tags_list ) ) {
				foreach ( $mvs_tags_list as $mvs_t ) {
					$mvs_tag_names[] = $mvs_t->name;
				}
			}
			$mvs_is_owner  = is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id();
			$mvs_social_ctx = array(
				'mediaId'        => get_the_ID(),
				'restUrl'        => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn'     => is_user_logged_in(),
				'isOwner'        => $mvs_is_owner,
				'type'           => 'media',
				'archiveUrl'     => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
				'initialTitle'   => get_the_title(),
				'initialDesc'    => get_the_content(),
				'initialPrivacy' => $current_privacy,
				'initialTags'    => $mvs_tag_names,
				'reactions'      => array(),
				'userReaction'   => '',
				'isFavorite'     => false,
				'comments'       => array(),
				'commentText'    => '',
				'viewCount'      => '',
				'editVisible'    => false,
				'editTitle'      => get_the_title(),
				'editDesc'       => get_the_content(),
				'editPrivacy'    => $current_privacy,
				'editTags'       => $mvs_tag_names,
				'tagInput'       => '',
				'tagResults'     => array(),
				'tagDropdownVisible' => false,
				'saving'         => false,
				'shareLabel'     => "\xF0\x9F\x94\x97 Share",
			);
			?>

			<div class="mvs-social-wrapper"
				data-wp-interactive="mvs/media-social"
				<?php echo wp_interactivity_data_wp_context( $mvs_social_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				data-wp-init="callbacks.init">

				<?php if ( $mvs_is_owner ) : ?>
				<!-- Owner Actions -->
				<div class="mvs-owner-actions">
					<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
						data-wp-on--click="actions.toggleEdit">
						<?php esc_html_e( 'Edit', 'wpmediaverse' ); ?>
					</button>
					<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
						data-wp-on--click="actions.confirmDelete">
						<?php esc_html_e( 'Delete', 'wpmediaverse' ); ?>
					</button>
				</div>
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
							<span data-wp-text="context.saving ? '<?php echo esc_js( __( 'Saving...', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Save', 'wpmediaverse' ) ); ?>'"></span>
						</button>
						<button class="mvs-btn mvs-btn--secondary" type="button"
							data-wp-on--click="actions.cancelEdit">
							<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
						</button>
					</div>
				</div>
				<?php endif; ?>

				<!-- Social Interactions Bar -->
				<div class="mvs-social-bar">
					<div class="mvs-reactions">
						<template data-wp-each="context.reactions">
							<button class="mvs-reaction-btn"
								data-wp-class--active="context.item.active"
								data-wp-bind--data-reaction-type="context.item.type"
								data-wp-on--click="actions.toggleReaction">
								<span class="mvs-reaction-emoji" data-wp-text="context.item.emoji"></span>
								<span class="mvs-count" data-wp-text="context.item.count"></span>
							</button>
						</template>
					</div>
					<?php if ( is_user_logged_in() ) : ?>
						<button class="mvs-favorite-btn" type="button"
							data-wp-class--active="context.isFavorite"
							data-wp-on--click="actions.toggleFavorite">&#x2764; <?php esc_html_e( 'Favorite', 'wpmediaverse' ); ?></button>
					<?php endif; ?>
					<button class="mvs-share-btn" type="button"
						data-wp-on--click="actions.handleShare"
						data-wp-text="context.shareLabel"></button>
					<span class="mvs-view-count" data-wp-text="context.viewCount"></span>
				</div>

				<!-- Comments Section -->
				<div class="mvs-comments-section">
					<h3 class="mvs-comments-title"><?php esc_html_e( 'Comments', 'wpmediaverse' ); ?></h3>
					<?php if ( is_user_logged_in() ) : ?>
						<form class="mvs-comment-form" data-wp-on--submit="actions.submitComment">
							<textarea placeholder="<?php esc_attr_e( 'Write a comment...', 'wpmediaverse' ); ?>" rows="2"
								data-wp-bind--value="context.commentText"
								data-wp-on--input="actions.updateCommentText"></textarea>
							<button type="submit"><?php esc_html_e( 'Post', 'wpmediaverse' ); ?></button>
						</form>
					<?php endif; ?>
					<ul class="mvs-comment-list">
						<template data-wp-each="context.comments">
							<li class="mvs-comment-item">
								<span class="mvs-comment-author" data-wp-text="context.item.author"></span>
								<span class="mvs-comment-date" data-wp-text="context.item.date"></span>
								<div class="mvs-comment-text" data-wp-text="context.item.content"></div>
							</li>
						</template>
					</ul>
				</div>
			</div>

			<footer class="mvs-media-footer">
				<?php
				$tags = get_the_terms( get_the_ID(), 'mvs_tag' );
				if ( $tags && ! is_wp_error( $tags ) ) :
					?>
					<div class="mvs-media-tags">
						<?php foreach ( $tags as $media_tag ) : ?>
							<a href="<?php echo esc_url( get_term_link( $media_tag ) ); ?>" class="mvs-tag">
								<?php echo esc_html( $media_tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</footer>
		</article>

	<?php endwhile; ?>
</div>
<?php
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

get_footer();

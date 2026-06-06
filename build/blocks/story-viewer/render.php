<?php
/**
 * Server-side render for the story-viewer block.
 *
 * Queries mvs_media_index + mvs_media_meta for active stories -- no wp_posts join.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$count       = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 10;
$avatar_size = isset( $attributes['avatarSize'] ) ? absint( $attributes['avatarSize'] ) : 64;

// Get active stories from custom tables (index + meta).
global $wpdb;
$index_table = $wpdb->prefix . 'mvs_media_index';
$meta_table  = $wpdb->prefix . 'mvs_media_meta';

// Viewer-scoped privacy gate (anon: public only; member: public + members +
// own; moderator: all). Without it, private/members stories rendered to
// everyone (audit 2026-06-04).
$mvs_story_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
list( $mvs_story_priv_sql, $mvs_story_priv_params ) = $mvs_story_repo->explore_privacy_clause( 'idx', get_current_user_id() );

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$stories = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT idx.*
		FROM {$meta_table} m1
		INNER JOIN {$meta_table} m2 ON m1.media_id = m2.media_id
		INNER JOIN {$index_table} idx ON idx.media_id = m1.media_id
		WHERE m1.meta_key = 'is_story' AND m1.meta_value = '1'
		AND m2.meta_key = 'story_expires_at' AND m2.meta_value > %s
		AND idx.status = 'publish'
		AND {$mvs_story_priv_sql}
		ORDER BY idx.created_at DESC
		LIMIT %d",
		current_time( 'mysql', true ),
		...array_merge( $mvs_story_priv_params, array( $count ) )
	),
	ARRAY_A
);
// phpcs:enable

if ( empty( $stories ) ) {
	// Empty state — Coding Rule #11 (no silent render fallthrough).
	// Anonymous visitors see nothing; logged-in users get a hint about how stories appear.
	if ( ! is_user_logged_in() ) {
		return;
	}
	$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
	$mvs_classes   = trim(
		implode(
			' ',
			array_filter(
				array(
					'mvs-story-viewer-block',
					'mvs-story-viewer-block--empty',
					$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
					\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
				)
			)
		)
	);
	$wrapper       = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );
	?>
	<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<div class="mvs-empty-state">
			<i data-lucide="clock" aria-hidden="true"></i>
			<p><?php esc_html_e( 'No active stories yet. Stories appear here when users upload media with story visibility — they expire after 24 hours.', 'wpmediaverse' ); ?></p>
		</div>
	</div>
	<?php
	return;
}

// Group stories by author.
$by_author = array();
foreach ( $stories as $story ) {
	$author_id = (int) $story['post_author'];
	if ( ! isset( $by_author[ $author_id ] ) ) {
		$by_author[ $author_id ] = array();
	}
	$by_author[ $author_id ][] = $story;
}

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-story-viewer-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/story-viewer"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'viewing'       => false,
			'currentAuthor' => 0,
			'currentIndex'  => 0,
		)
	);
	?>
	'
>
	<div class="mvs-stories-bar" style="display:flex;gap:12px;overflow-x:auto;padding:8px 0;">
		<?php foreach ( $by_author as $author_id => $author_stories ) : ?>
			<?php
			$user       = get_userdata( $author_id );
			$avatar_url = get_avatar_url( $author_id, array( 'size' => $avatar_size * 2 ) );
			$first      = $author_stories[0];
			$sv_mid     = (int) ( $first['media_id'] ?? 0 );
			$sv_su      = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
			$file_url   = $sv_mid && $sv_su ? $sv_su->generate( $sv_mid, get_current_user_id() ) : '';
			?>
			<div class="mvs-story-avatar"
				style="text-align:center;flex-shrink:0;cursor:pointer;"
				data-wp-on--click="actions.openStory"
				data-wp-context='
				<?php
				echo wp_json_encode(
					array(
						'authorId'    => $author_id,
						'authorName'  => $user ? $user->display_name : '',
						'storyUrl'    => $file_url,
					)
				);
				?>
									'
			>
				<img src="<?php echo esc_url( $avatar_url ); ?>"
					alt="<?php echo $user ? esc_attr( $user->display_name ) : ''; ?>"
					style="width:<?php echo absint( $avatar_size ); ?>px;height:<?php echo absint( $avatar_size ); ?>px;border-radius:50%;border:3px solid #0073aa;object-fit:cover;"
				/>
				<div style="font-size:11px;color:#666;margin-top:4px;max-width:<?php echo absint( $avatar_size ); ?>px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
					<?php echo $user ? esc_html( $user->display_name ) : ''; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="mvs-story-fullscreen"
		data-wp-bind--hidden="!context.viewing"
		style="position:fixed;inset:0;z-index:100000;background:#000;display:flex;align-items:center;justify-content:center;"
	>
		<button class="mvs-story-close"
			data-wp-on--click="actions.closeStory"
			style="position:absolute;top:20px;right:20px;color:#fff;font-size:32px;background:none;border:none;cursor:pointer;"
		>&times;</button>
		<img class="mvs-story-image"
			data-wp-bind--src="context.storyUrl"
			style="max-width:100%;max-height:100%;object-fit:contain;"
			alt="" data-wp-bind--alt="context.authorName"
		/>
	</div>
</div>

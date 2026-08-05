<?php
/**
 * Server-side render for the media-player block.
 *
 * Reads media data from mvs_media_index via MediaMeta -- no get_post().
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$media_id = isset( $attributes['mediaId'] ) ? absint( $attributes['mediaId'] ) : 0;
$autoplay = ! empty( $attributes['autoplay'] );
$loop     = ! empty( $attributes['loop'] );
$show_dl  = ! empty( $attributes['showDownload'] );

// Misconfiguration — nothing chosen, or an id that is not in the index.
// Tell the editor rather than rendering nothing (Coding Rule 11). Visitors get
// nothing: a "media not found" notice on a live page is noise to them.
if ( ! $media_id || ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
	if ( current_user_can( 'edit_posts' ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML.
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'circle-play',
				'title'   => __( 'Media not found', 'wpmediaverse' ),
				'message' => $media_id
					? __( 'This player points at media that no longer exists. Pick another item in the block settings.', 'wpmediaverse' )
					: __( 'Choose a video or audio item in the block settings to play it here.', 'wpmediaverse' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}

$file_type  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
$media_title = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'title' );

$mp_signed = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
$file_url  = $mp_signed ? $mp_signed->generate( $media_id, get_current_user_id() ) : '';

// No signed URL means the privacy gate declined. Silent by design — see the
// note on the album block's privacy branch: announcing it would confirm the
// item exists.
if ( ! $file_url ) {
	return;
}

$is_video = strpos( $file_type, 'video/' ) === 0;
$is_audio = strpos( $file_type, 'audio/' ) === 0;

// An image handed to a player. This one is worth naming precisely: the editor
// picked a real media item, so "not found" would be misleading — the item is
// fine, it is just the wrong kind for this block.
if ( ! $is_video && ! $is_audio ) {
	if ( current_user_can( 'edit_posts' ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML.
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'circle-play',
				'title'   => __( 'Not a playable item', 'wpmediaverse' ),
				'message' => __( 'The media player only handles video and audio. Use the Media Grid block to display an image.', 'wpmediaverse' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
if ( empty( $mvs_shortcode_context ) ) {
	\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
}
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-media-player-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper  = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => $mvs_classes ) ) : 'class="' . esc_attr( $mvs_classes ) . '"';
$rest_url = esc_url( rest_url( 'mvs/v1/media/' . $media_id . '/view' ) );
$nonce    = wp_create_nonce( 'wp_rest' );

// Pro analytics — wire the player to POST play/pause/seek/complete events to
// the Pro events endpoint when the Pro plugin is active. Falls back silently
// (client action short-circuits on empty analyticsUrl) when Pro isn't
// installed. Card 9822587682.
$mvs_pro_active   = defined( 'MVS_PRO_VERSION' );
$mvs_analytics_url = $mvs_pro_active
	? esc_url_raw( rest_url( 'mvs-pro/v1/media/' . $media_id . '/events' ) )
	: '';
$mvs_session_id   = $mvs_pro_active
	? substr( wp_generate_uuid4(), 0, 32 )
	: '';
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/media-player"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'mediaId'      => $media_id,
			'restUrl'      => $rest_url,
			'nonce'        => $nonce,
			'playing'      => false,
			'analyticsUrl' => $mvs_analytics_url,
			'sessionId'    => $mvs_session_id,
		)
	);
	?>
	'
	data-wp-init="actions.trackView"
>
	<?php if ( $is_video ) : ?>
		<video class="mvs-player-video"
			controls
			<?php echo $autoplay ? 'autoplay muted' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static strings. ?>
			<?php echo $loop ? 'loop' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>
			preload="metadata"
			data-wp-on--play="actions.onPlay"
			data-wp-on--pause="actions.onPause"
			data-wp-on--seeked="actions.onSeek"
			data-wp-on--ended="actions.onComplete"
		>
			<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
		</video>
	<?php elseif ( $is_audio ) : ?>
		<div class="mvs-player-audio-wrap">
			<div class="mvs-player-audio-title"><?php echo esc_html( $media_title ); ?></div>
			<audio class="mvs-player-audio"
				controls
				<?php echo $autoplay ? 'autoplay' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>
				<?php echo $loop ? 'loop' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>
				preload="metadata"
				data-wp-on--play="actions.onPlay"
				data-wp-on--pause="actions.onPause"
				data-wp-on--seeked="actions.onSeek"
				data-wp-on--ended="actions.onComplete"
			>
				<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
			</audio>
		</div>
	<?php endif; ?>

	<?php if ( $show_dl ) : ?>
		<div class="mvs-player-actions">
			<a href="<?php echo esc_url( $file_url ); ?>" download class="mvs-download-btn">
				<?php esc_html_e( 'Download', 'wpmediaverse' ); ?>
			</a>
		</div>
	<?php endif; ?>
</div>

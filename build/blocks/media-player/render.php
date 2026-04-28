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

if ( ! $media_id ) {
	return;
}

// Verify media exists in the index table.
if ( ! \WPMediaVerse\Repository\MediaRepository::exists( $media_id ) ) {
	return;
}

$file_url   = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'file_url' );
$file_type  = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'file_type' );
$media_title = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'title' );

if ( ! $file_url ) {
	return;
}

$is_video = strpos( $file_type, 'video/' ) === 0;
$is_audio = strpos( $file_type, 'audio/' ) === 0;

if ( ! $is_video && ! $is_audio ) {
	return;
}

$wrapper  = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => 'mvs-media-player-block' ) ) : 'class="mvs-media-player-block"';
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

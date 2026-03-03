<?php
/**
 * Server-side render for the media-stats block.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$show_views     = ! empty( $attributes['showViews'] );
$show_downloads = ! empty( $attributes['showDownloads'] );
$show_reactions = ! empty( $attributes['showReactions'] );
$show_top       = ! empty( $attributes['showTopMedia'] );
$top_count      = isset( $attributes['topCount'] ) ? absint( $attributes['topCount'] ) : 5;

$user_id = get_current_user_id();

// Get user stats.
global $wpdb;
$stats_table = $wpdb->prefix . 'mvs_media_stats';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$mvs_totals = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT
			COALESCE(SUM(s.views), 0) AS total_views,
			COALESCE(SUM(s.downloads), 0) AS total_downloads,
			COALESCE(SUM(s.reactions), 0) AS total_reactions,
			COALESCE(SUM(s.comments), 0) AS total_comments
		FROM {$stats_table} s
		INNER JOIN {$wpdb->posts} p ON p.ID = s.media_id
		WHERE p.post_author = %d AND p.post_type = 'mvs_media'",
		$user_id
	),
	ARRAY_A
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( ! $mvs_totals ) {
	$mvs_totals = array(
		'total_views'     => 0,
		'total_downloads' => 0,
		'total_reactions' => 0,
		'total_comments'  => 0,
	);
}

$media_count = wp_count_posts( 'mvs_media' );
$user_count  = isset( $media_count->publish ) ? $media_count->publish : 0;

$wrapper = get_block_wrapper_attributes( array( 'class' => 'mvs-stats-block' ) );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="mvs-stats-cards" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:12px;">
		<div class="mvs-stat-card" style="background:#f8f9fa;border-radius:8px;padding:16px;text-align:center;">
			<div style="font-size:24px;font-weight:700;"><?php echo esc_html( number_format_i18n( $user_count ) ); ?></div>
			<div style="font-size:12px;color:#666;"><?php esc_html_e( 'Media Items', 'wpmediaverse' ); ?></div>
		</div>

		<?php if ( $show_views ) : ?>
			<div class="mvs-stat-card" style="background:#f8f9fa;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:24px;font-weight:700;"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_views'] ) ); ?></div>
				<div style="font-size:12px;color:#666;"><?php esc_html_e( 'Views', 'wpmediaverse' ); ?></div>
			</div>
		<?php endif; ?>

		<?php if ( $show_downloads ) : ?>
			<div class="mvs-stat-card" style="background:#f8f9fa;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:24px;font-weight:700;"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_downloads'] ) ); ?></div>
				<div style="font-size:12px;color:#666;"><?php esc_html_e( 'Downloads', 'wpmediaverse' ); ?></div>
			</div>
		<?php endif; ?>

		<?php if ( $show_reactions ) : ?>
			<div class="mvs-stat-card" style="background:#f8f9fa;border-radius:8px;padding:16px;text-align:center;">
				<div style="font-size:24px;font-weight:700;"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_reactions'] ) ); ?></div>
				<div style="font-size:12px;color:#666;"><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></div>
			</div>
		<?php endif; ?>
	</div>

	<?php
	if ( $show_top ) :
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top_media = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.media_id, s.views, s.reactions, p.post_title
				FROM {$stats_table} s
				INNER JOIN {$wpdb->posts} p ON p.ID = s.media_id
				WHERE p.post_author = %d AND p.post_type = 'mvs_media'
				ORDER BY s.views DESC
				LIMIT %d",
				$user_id,
				$top_count
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<?php if ( ! empty( $top_media ) ) : ?>
			<div class="mvs-top-media" style="margin-top:16px;">
				<h4 style="margin-bottom:8px;"><?php esc_html_e( 'Top Media', 'wpmediaverse' ); ?></h4>
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr style="border-bottom:2px solid #eee;text-align:left;">
							<th style="padding:8px;"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Views', 'wpmediaverse' ); ?></th>
							<th style="padding:8px;"><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_media as $item ) : ?>
							<tr style="border-bottom:1px solid #f0f0f0;">
								<td style="padding:8px;"><?php echo esc_html( $item['post_title'] ); ?></td>
								<td style="padding:8px;"><?php echo esc_html( number_format_i18n( (int) $item['views'] ) ); ?></td>
								<td style="padding:8px;"><?php echo esc_html( number_format_i18n( (int) $item['reactions'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

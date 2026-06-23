<?php
/**
 * Server-side render for the media-stats block.
 *
 * Queries mvs_media_index + mvs_media_stats directly -- no wp_posts.
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
$index_table = $wpdb->prefix . 'mvs_media_index';

// Viewer-scoped privacy gate so a public stats block only aggregates media the
// viewer is allowed to see: owner/moderator → all; member → public + members +
// own; anon → public only. Previously every total summed the author's PRIVATE
// media too, exposing private view/reaction volume publicly (audit 2026-06-04).
$mvs_stats_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
list( $mvs_stats_priv_sql, $mvs_stats_priv_params ) = $mvs_stats_repo->explore_privacy_clause( 'idx', get_current_user_id() );

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$mvs_totals = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT
			COALESCE(SUM(s.views), 0) AS total_views,
			COALESCE(SUM(s.downloads), 0) AS total_downloads,
			COALESCE(SUM(s.reactions), 0) AS total_reactions,
			COALESCE(SUM(s.comments), 0) AS total_comments
		FROM {$stats_table} s
		INNER JOIN {$index_table} idx ON idx.media_id = s.media_id
		WHERE idx.post_author = %d AND idx.status = 'publish' AND {$mvs_stats_priv_sql}",
		...array_merge( array( $user_id ), $mvs_stats_priv_params )
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

// Count user's published media the viewer can see (same gate as the totals).
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$user_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$index_table} idx WHERE idx.post_author = %d AND idx.status = 'publish' AND {$mvs_stats_priv_sql}",
		...array_merge( array( $user_id ), $mvs_stats_priv_params )
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
if ( empty( $mvs_shortcode_context ) ) {
	\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
}
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-stats-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => $mvs_classes ) ) : 'class="' . esc_attr( $mvs_classes ) . '"';
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="mvs-stats-cards">
		<div class="mvs-stat-card">
			<span class="mvs-stat-value"><?php echo esc_html( number_format_i18n( $user_count ) ); ?></span>
			<span class="mvs-stat-label"><?php esc_html_e( 'Media Items', 'wpmediaverse' ); ?></span>
		</div>

		<?php if ( $show_views ) : ?>
			<div class="mvs-stat-card">
				<span class="mvs-stat-value"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_views'] ) ); ?></span>
				<span class="mvs-stat-label"><?php esc_html_e( 'Views', 'wpmediaverse' ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $show_downloads ) : ?>
			<div class="mvs-stat-card">
				<span class="mvs-stat-value"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_downloads'] ) ); ?></span>
				<span class="mvs-stat-label"><?php esc_html_e( 'Downloads', 'wpmediaverse' ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $show_reactions ) : ?>
			<div class="mvs-stat-card">
				<span class="mvs-stat-value"><?php echo esc_html( number_format_i18n( (int) $mvs_totals['total_reactions'] ) ); ?></span>
				<span class="mvs-stat-label"><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<?php
	if ( $show_top ) :
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top_media = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.media_id, s.views, s.reactions, idx.title
				FROM {$stats_table} s
				INNER JOIN {$index_table} idx ON idx.media_id = s.media_id
				WHERE idx.post_author = %d AND idx.status = 'publish'
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
			<div class="mvs-top-media">
				<h4><?php esc_html_e( 'Top Media', 'wpmediaverse' ); ?></h4>
				<table class="mvs-stats-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
							<th><?php esc_html_e( 'Views', 'wpmediaverse' ); ?></th>
							<th><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_media as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['title'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $item['views'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $item['reactions'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

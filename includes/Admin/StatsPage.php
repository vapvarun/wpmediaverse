<?php
/**
 * Admin stats dashboard page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\AIService;

/**
 * Admin stats dashboard page.
 */
class StatsPage {

	const PAGE_SLUG = 'mvs-stats';

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param AIService $ai AI service.
	 */
	public function __construct( AIService $ai ) {
		$this->ai = $ai;
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add stats page under Media menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=mvs_media',
			__( 'Media Stats', 'wpmediaverse' ),
			__( 'Stats', 'wpmediaverse' ),
			'upload_mvs_media',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the stats page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'upload_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		global $wpdb;

		// Overall counts.
		$media_counts = wp_count_posts( 'mvs_media' );
		$album_counts = wp_count_posts( 'mvs_album' );
		$total_media  = isset( $media_counts->publish ) ? (int) $media_counts->publish : 0;
		$total_albums = isset( $album_counts->publish ) ? (int) $album_counts->publish : 0;

		// Stats totals.
		$stats_table = $wpdb->prefix . 'mvs_media_stats';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(views), 0) AS total_views,
					COALESCE(SUM(downloads), 0) AS total_downloads,
					COALESCE(SUM(reactions), 0) AS total_reactions,
					COALESCE(SUM(comments), 0) AS total_comments,
					COALESCE(SUM(shares), 0) AS total_shares
				FROM {$stats_table} WHERE 1 = %d",
				1
			),
			ARRAY_A
		);

		$top_media = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.media_id, s.views, s.reactions, s.downloads, p.post_title
				FROM {$stats_table} s
				INNER JOIN {$wpdb->posts} p ON p.ID = s.media_id
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY s.views DESC
				LIMIT 10",
				'mvs_media',
				'publish'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// AI usage.
		$ai_stats = $this->ai->get_usage_stats();

		// Storage used.
		$storage_size      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT SUM(CAST(meta_value AS UNSIGNED))
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				'_mvs_file_size'
			)
		);
		$storage_formatted = size_format( (int) $storage_size );

		?>
		<div class="wrap">
			<div class="mvs-page-header">
				<h1><?php esc_html_e( 'Media Stats', 'wpmediaverse' ); ?></h1>
			</div>

			<?php // --- Stat Cards --- ?>
			<div class="mvs-admin-stats">
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $total_media ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Total Media', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $total_albums ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Albums', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( (int) $totals['total_views'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Total Views', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( (int) $totals['total_downloads'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Downloads', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( (int) $totals['total_reactions'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></span>
				</div>
				<div class="mvs-stat-card mvs-stat-card--warning">
					<span class="mvs-stat-number"><?php echo esc_html( $storage_formatted ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Storage Used', 'wpmediaverse' ); ?></span>
				</div>
			</div>

			<?php // --- Two Column Layout --- ?>
			<div class="mvs-admin-columns mvs-admin-columns--2-1">

				<?php // Top Media Widget. ?>
				<div class="mvs-admin-widget">
					<div class="mvs-widget-header">
						<h2><?php esc_html_e( 'Top Media by Views', 'wpmediaverse' ); ?></h2>
					</div>
					<div class="mvs-widget-body mvs-widget-body--flush">
						<?php if ( ! empty( $top_media ) ) : ?>
							<table class="mvs-recent-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Views', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Reactions', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Downloads', 'wpmediaverse' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $top_media as $item ) : ?>
										<?php
										$edit_link = get_edit_post_link( (int) $item['media_id'], 'raw' );
										$view_link = get_permalink( (int) $item['media_id'] );
										$item_link = $edit_link ? $edit_link : $view_link;
										?>
										<tr>
											<td>
												<?php if ( $item_link ) : ?>
													<a href="<?php echo esc_url( $item_link ); ?>">
														<?php echo esc_html( $item['post_title'] ); ?>
													</a>
												<?php else : ?>
													<?php echo esc_html( $item['post_title'] ); ?>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( number_format_i18n( (int) $item['views'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( (int) $item['reactions'] ) ); ?></td>
											<td><?php echo esc_html( number_format_i18n( (int) $item['downloads'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<div class="mvs-empty-state">
								<span class="dashicons dashicons-chart-bar"></span>
								<p><?php esc_html_e( 'No stats data yet. Views will appear once users start browsing media.', 'wpmediaverse' ); ?></p>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php // AI Usage Widget. ?>
				<div class="mvs-admin-widget">
					<div class="mvs-widget-header">
						<h2><?php esc_html_e( 'AI Usage (This Month)', 'wpmediaverse' ); ?></h2>
					</div>
					<div class="mvs-widget-body">
						<ul class="mvs-status-list">
							<li>
								<span class="mvs-status-label"><?php esc_html_e( 'API Calls', 'wpmediaverse' ); ?></span>
								<span class="mvs-status-value"><?php echo esc_html( number_format_i18n( $ai_stats['calls'] ) ); ?></span>
							</li>
							<li>
								<span class="mvs-status-label"><?php esc_html_e( 'Successful', 'wpmediaverse' ); ?></span>
								<span class="mvs-status-value mvs-status-ok"><?php echo esc_html( number_format_i18n( $ai_stats['success'] ) ); ?></span>
							</li>
							<li>
								<span class="mvs-status-label"><?php esc_html_e( 'Failed', 'wpmediaverse' ); ?></span>
								<span class="mvs-status-value <?php echo esc_attr( $ai_stats['failed'] > 0 ? 'mvs-status-bad' : '' ); ?>">
									<?php echo esc_html( number_format_i18n( $ai_stats['failed'] ) ); ?>
								</span>
							</li>
							<li>
								<span class="mvs-status-label"><?php esc_html_e( 'Cost', 'wpmediaverse' ); ?></span>
								<span class="mvs-status-value">$<?php echo esc_html( number_format( $ai_stats['cost'], 2 ) ); ?></span>
							</li>
							<?php if ( $ai_stats['budget'] > 0 ) : ?>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'Budget', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value">
										$<?php echo esc_html( number_format( $ai_stats['budget'], 2 ) ); ?>
										<?php
										$pct       = round( ( $ai_stats['cost'] / $ai_stats['budget'] ) * 100 );
										$pct_class = $pct > 80 ? 'mvs-status-bad' : ( $pct > 50 ? 'mvs-status-warn' : 'mvs-status-ok' );
										?>
										<span class="<?php echo esc_attr( $pct_class ); ?>">
											(<?php echo esc_html( $pct ); ?>%)
										</span>
									</span>
								</li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="mvs-widget-footer">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-settings&tab=ai' ) ); ?>">
							<?php esc_html_e( 'AI Settings', 'wpmediaverse' ); ?> &rarr;
						</a>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}

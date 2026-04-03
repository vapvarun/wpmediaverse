<?php
/**
 * Admin stats dashboard page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\AIService;
use WPMediaVerse\Services\MediaMeta;

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
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
	}

	/**
	 * Add stats page under Media menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			\WPMediaVerse\Core\Plugin::ADMIN_SLUG,
			__( 'Media Stats', 'wpmediaverse' ),
			__( 'Stats', 'wpmediaverse' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle CSV export of top media stats.
	 */
	public function handle_csv_export(): void {
		if ( ! isset( $_GET['mvs_export_csv'] ) || ! isset( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mvs_export_stats_csv' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}

		global $wpdb;
		$stats_table = $wpdb->prefix . 'mvs_media_stats';

		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$top_media = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.media_id, s.views, s.reactions, s.downloads, s.comments, s.shares, m.title AS post_title
				FROM {$stats_table} s
				INNER JOIN {$index_table} m ON m.media_id = s.media_id
				WHERE m.status = %s
				ORDER BY s.views DESC
				LIMIT 100",
				'publish'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpmediaverse-stats-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'ID', 'Title', 'Views', 'Reactions', 'Downloads', 'Comments', 'Shares' ) );

		foreach ( $top_media as $item ) {
			fputcsv(
				$output,
				array(
					$item['media_id'],
					$item['post_title'],
					$item['views'],
					$item['reactions'],
					$item['downloads'],
					$item['comments'],
					$item['shares'],
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV export to php://output requires native stream ops.
		exit;
	}

	/**
	 * Get the date range WHERE clause for stats queries.
	 *
	 * @param string $range Date range key.
	 * @return string SQL date filter (already prepared or safe).
	 */
	private function get_date_filter( string $range ): string {
		global $wpdb;

		switch ( $range ) {
			case 'today':
				return $wpdb->prepare( 'AND m.created_at >= %s', gmdate( 'Y-m-d 00:00:00' ) );
			case 'week':
				return $wpdb->prepare( 'AND m.created_at >= %s', gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) ) );
			case 'month':
				return $wpdb->prepare( 'AND m.created_at >= %s', gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) ) );
			default:
				return '';
		}
	}

	/**
	 * Render the stats page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'upload_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range       = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'all';
		$date_filter = $this->get_date_filter( $range );

		// Overall counts.
		$total_media  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$album_counts = wp_count_posts( 'mvs_album' );
		$total_albums = isset( $album_counts->publish ) ? (int) $album_counts->publish : 0;

		// Stats totals.
		$stats_table = $wpdb->prefix . 'mvs_media_stats';
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $date_filter ) {
			$totals = $wpdb->get_row(
				"SELECT
					COALESCE(SUM(s.views), 0) AS total_views,
					COALESCE(SUM(s.downloads), 0) AS total_downloads,
					COALESCE(SUM(s.reactions), 0) AS total_reactions,
					COALESCE(SUM(s.comments), 0) AS total_comments,
					COALESCE(SUM(s.shares), 0) AS total_shares
				FROM {$stats_table} s
				INNER JOIN {$index_table} m ON m.media_id = s.media_id
				WHERE m.status = 'publish' {$date_filter}",
				ARRAY_A
			);
		} else {
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
		}

		if ( $date_filter ) {
			$top_media = $wpdb->get_results(
				"SELECT s.media_id, s.views, s.reactions, s.downloads, m.title AS post_title
				FROM {$stats_table} s
				INNER JOIN {$index_table} m ON m.media_id = s.media_id
				WHERE m.status = 'publish' {$date_filter}
				ORDER BY s.views DESC
				LIMIT 10",
				ARRAY_A
			);
		} else {
			$top_media = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.media_id, s.views, s.reactions, s.downloads, m.title AS post_title
					FROM {$stats_table} s
					INNER JOIN {$index_table} m ON m.media_id = s.media_id
					WHERE m.status = %s
					ORDER BY s.views DESC
					LIMIT 10",
					'publish'
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// AI usage.
		$ai_stats = $this->ai->get_usage_stats();

		// Storage used (from mvs_media_index table).
		$storage_size      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(file_size) FROM {$wpdb->prefix}mvs_media_index" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$storage_formatted = size_format( (int) $storage_size );

		?>
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="bar-chart-3"></i>
						<?php esc_html_e( 'Media Stats', 'wpmediaverse' ); ?>
						<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
					</h1>
					<p class="mvs-page-header__desc"><?php esc_html_e( 'Track views, downloads, reactions, and AI usage across your media library.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<!-- Date Range Selector -->
			<div class="mvs-stats-toolbar">
				<div class="mvs-date-range-selector">
					<?php
					$ranges         = array(
						'today' => __( 'Today', 'wpmediaverse' ),
						'week'  => __( 'This Week', 'wpmediaverse' ),
						'month' => __( 'This Month', 'wpmediaverse' ),
						'all'   => __( 'All Time', 'wpmediaverse' ),
					);
					$base_stats_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
					foreach ( $ranges as $key => $label ) :
						$url       = add_query_arg( 'range', $key, $base_stats_url );
						$is_active = ( $range === $key );
						?>
						<a href="<?php echo esc_url( $url ); ?>"
							class="mvs-range-btn <?php echo $is_active ? 'active' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( ! empty( $top_media ) ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'mvs_export_csv', '1', $base_stats_url ), 'mvs_export_stats_csv' ) ); ?>"
						class="mvs-btn mvs-btn--sm">
						<i data-lucide="download" class="mvs-icon--sm"></i>
						<?php esc_html_e( 'Export CSV', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
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
										$item_link = MediaMeta::get_permalink( (int) $item['media_id'] );
										?>
										<tr>
											<td>
												<?php if ( $item_link ) : ?>
													<a href="<?php echo esc_url( $item_link ); ?>" target="_blank">
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
								<i data-lucide="bar-chart-3"></i>
								<h3><?php esc_html_e( 'No Stats Yet', 'wpmediaverse' ); ?></h3>
								<p><?php esc_html_e( 'Views will appear once users start browsing media.', 'wpmediaverse' ); ?></p>
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-settings&tab=ai' ) ); ?>">
							<?php esc_html_e( 'AI Settings', 'wpmediaverse' ); ?> &rarr;
						</a>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}

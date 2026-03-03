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
			"SELECT
				COALESCE(SUM(views), 0) AS total_views,
				COALESCE(SUM(downloads), 0) AS total_downloads,
				COALESCE(SUM(reactions), 0) AS total_reactions,
				COALESCE(SUM(comments), 0) AS total_comments,
				COALESCE(SUM(shares), 0) AS total_shares
			FROM {$stats_table}",
			ARRAY_A
		);

		// Top media by views.
		$top_media = $wpdb->get_results(
			"SELECT s.media_id, s.views, s.reactions, s.downloads, p.post_title
			FROM {$stats_table} s
			INNER JOIN {$wpdb->posts} p ON p.ID = s.media_id
			WHERE p.post_type = 'mvs_media' AND p.post_status = 'publish'
			ORDER BY s.views DESC
			LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// AI usage.
		$ai_stats = $this->ai->get_usage_stats();

		// Storage used.
		$storage_size      = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(CAST(meta_value AS UNSIGNED))
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_mvs_file_size'"
		);
		$storage_formatted = size_format( (int) $storage_size );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPMediaVerse Stats', 'wpmediaverse' ); ?></h1>

			<div class="mvs-admin-stats" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:16px;margin-top:20px;">
				<?php
				$cards = array(
					array(
						'label' => __( 'Total Media', 'wpmediaverse' ),
						'value' => number_format_i18n( $total_media ),
					),
					array(
						'label' => __( 'Albums', 'wpmediaverse' ),
						'value' => number_format_i18n( $total_albums ),
					),
					array(
						'label' => __( 'Total Views', 'wpmediaverse' ),
						'value' => number_format_i18n( (int) $totals['total_views'] ),
					),
					array(
						'label' => __( 'Downloads', 'wpmediaverse' ),
						'value' => number_format_i18n( (int) $totals['total_downloads'] ),
					),
					array(
						'label' => __( 'Reactions', 'wpmediaverse' ),
						'value' => number_format_i18n( (int) $totals['total_reactions'] ),
					),
					array(
						'label' => __( 'Storage Used', 'wpmediaverse' ),
						'value' => $storage_formatted,
					),
				);

				foreach ( $cards as $card ) :
					?>
					<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;text-align:center;">
						<div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo esc_html( $card['value'] ); ?></div>
						<div style="font-size:13px;color:#50575e;margin-top:4px;"><?php echo esc_html( $card['label'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-top:24px;">
				<!-- Top Media -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Top Media by Views', 'wpmediaverse' ); ?></h2>
					<?php if ( ! empty( $top_media ) ) : ?>
						<table class="widefat striped">
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
									<tr>
										<td>
											<a href="<?php echo esc_url( get_edit_post_link( $item['media_id'] ) ); ?>">
												<?php echo esc_html( $item['post_title'] ); ?>
											</a>
										</td>
										<td><?php echo esc_html( number_format_i18n( (int) $item['views'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (int) $item['reactions'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (int) $item['downloads'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No stats data yet.', 'wpmediaverse' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- AI Usage -->
				<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'AI Usage (This Month)', 'wpmediaverse' ); ?></h2>
					<table class="widefat">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'API Calls', 'wpmediaverse' ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( $ai_stats['calls'] ) ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Successful', 'wpmediaverse' ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( $ai_stats['success'] ) ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Failed', 'wpmediaverse' ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( $ai_stats['failed'] ) ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Cost', 'wpmediaverse' ); ?></td>
								<td><strong>$<?php echo esc_html( number_format( $ai_stats['cost'], 2 ) ); ?></strong></td>
							</tr>
							<?php if ( $ai_stats['budget'] > 0 ) : ?>
								<tr>
									<td><?php esc_html_e( 'Budget', 'wpmediaverse' ); ?></td>
									<td>
										<strong>$<?php echo esc_html( number_format( $ai_stats['budget'], 2 ) ); ?></strong>
										<?php
										$pct = $ai_stats['budget'] > 0 ? round( ( $ai_stats['cost'] / $ai_stats['budget'] ) * 100 ) : 0;
										echo esc_html( sprintf( ' (%d%%)', $pct ) );
										?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}

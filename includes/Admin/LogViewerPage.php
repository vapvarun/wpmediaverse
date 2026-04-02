<?php
/**
 * Admin log viewer page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\LoggerService;

/**
 * Admin page to view and manage plugin logs.
 */
class LogViewerPage {

	const PAGE_SLUG = 'mvs-logs';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add log viewer page under Media menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			\WPMediaVerse\Core\Plugin::ADMIN_SLUG,
			__( 'Log Viewer', 'wpmediaverse' ),
			__( 'Logs', 'wpmediaverse' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle clear logs action.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['mvs_clear_logs'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! check_admin_referer( 'mvs_clear_logs', 'mvs_clear_logs_nonce' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_error_log';
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'cleared' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the log viewer page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$level       = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$log_context = isset( $_GET['log_context'] ) ? sanitize_text_field( wp_unslash( $_GET['log_context'] ) ) : '';
		$paged       = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$cleared     = isset( $_GET['cleared'] ) && '1' === $_GET['cleared'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 50;
		$result   = LoggerService::get_logs(
			array(
				'level'    => $level,
				'context'  => $log_context,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$total_pages = ceil( $result['total'] / $per_page );
		$contexts    = LoggerService::get_contexts();

		$level_colors = array(
			'error'   => '#d63638',
			'warning' => '#dba617',
			'info'    => '#2271b1',
		);

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		?>
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="scroll-text"></i>
						<?php esc_html_e( 'Log Viewer', 'wpmediaverse' ); ?>
					</h1>
					<p class="mvs-page-header__desc"><?php esc_html_e( 'View plugin logs for debugging and monitoring. Logs older than 30 days are automatically removed.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'All logs have been cleared.', 'wpmediaverse' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Filters -->
			<div class="mvs-log-filters">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

					<label for="mvs-log-level"><?php esc_html_e( 'Level:', 'wpmediaverse' ); ?></label>
					<select name="level" id="mvs-log-level">
						<option value=""><?php esc_html_e( 'All Levels', 'wpmediaverse' ); ?></option>
						<?php foreach ( array( 'error', 'warning', 'info' ) as $lvl ) : ?>
							<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $level, $lvl ); ?>>
								<?php echo esc_html( ucfirst( $lvl ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label for="mvs-log-context"><?php esc_html_e( 'Context:', 'wpmediaverse' ); ?></label>
					<select name="log_context" id="mvs-log-context">
						<option value=""><?php esc_html_e( 'All Contexts', 'wpmediaverse' ); ?></option>
						<?php foreach ( $contexts as $ctx ) : ?>
							<option value="<?php echo esc_attr( $ctx ); ?>" <?php selected( $log_context, $ctx ); ?>>
								<?php echo esc_html( ucfirst( $ctx ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="mvs-btn mvs-btn--sm"><?php esc_html_e( 'Filter', 'wpmediaverse' ); ?></button>

					<?php if ( $level || $log_context ) : ?>
						<a href="<?php echo esc_url( $base_url ); ?>" class="mvs-btn mvs-btn--sm"><?php esc_html_e( 'Reset', 'wpmediaverse' ); ?></a>
					<?php endif; ?>
				</form>

				<?php if ( $result['total'] > 0 ) : ?>
					<form method="post" class="mvs-form-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all logs?', 'wpmediaverse' ) ); ?>');">
						<?php wp_nonce_field( 'mvs_clear_logs', 'mvs_clear_logs_nonce' ); ?>
						<input type="hidden" name="mvs_clear_logs" value="1" />
						<button type="submit" class="mvs-btn mvs-btn--danger">
							<i data-lucide="trash-2" class="mvs-icon--sm"></i>
							<?php esc_html_e( 'Clear All Logs', 'wpmediaverse' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Results count -->
			<p class="mvs-log-count">
				<?php
				printf(
					/* translators: %s: number of log entries */
					esc_html( _n( '%s log entry', '%s log entries', $result['total'], 'wpmediaverse' ) ),
					esc_html( number_format_i18n( $result['total'] ) )
				);
				?>
			</p>

			<!-- Log Table -->
			<div class="mvs-admin-widget">
				<div class="mvs-widget-body mvs-widget-body--flush">
					<?php if ( empty( $result['items'] ) ) : ?>
						<div class="mvs-empty-state">
							<i data-lucide="file-text"></i>
							<p><?php esc_html_e( 'No log entries found.', 'wpmediaverse' ); ?></p>
						</div>
					<?php else : ?>
						<table class="mvs-log-table striped">
							<thead>
								<tr>
									<th class="column-date"><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
									<th class="column-level"><?php esc_html_e( 'Level', 'wpmediaverse' ); ?></th>
									<th class="column-context"><?php esc_html_e( 'Context', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Message', 'wpmediaverse' ); ?></th>
									<th class="column-user"><?php esc_html_e( 'User', 'wpmediaverse' ); ?></th>
									<th class="column-ip"><?php esc_html_e( 'IP', 'wpmediaverse' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result['items'] as $log ) : ?>
									<?php
									$log_level_class = isset( $level_colors[ $log->level ] ) ? 'mvs-log-level--' . $log->level : 'mvs-log-level--default';
									$user_display    = '';
									if ( $log->user_id > 0 ) {
										$user         = get_userdata( $log->user_id );
										$user_display = $user ? $user->display_name : sprintf( '#%d', $log->user_id );
									}
									?>
									<tr>
										<td>
											<time datetime="<?php echo esc_attr( $log->created_at ); ?>">
												<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $log->created_at ) ) ); ?>
											</time>
										</td>
										<td>
											<span class="mvs-log-level-badge <?php echo esc_attr( $log_level_class ); ?>">
												<?php echo esc_html( strtoupper( $log->level ) ); ?>
											</span>
										</td>
										<td>
											<a href="<?php echo esc_url( add_query_arg( 'log_context', $log->context, $base_url ) ); ?>">
												<?php echo esc_html( ucfirst( $log->context ) ); ?>
											</a>
										</td>
										<td>
											<?php echo esc_html( $log->message ); ?>
											<?php if ( $log->metadata ) : ?>
												<details class="mvs-log-metadata">
													<summary><?php esc_html_e( 'Details', 'wpmediaverse' ); ?></summary>
													<pre><?php echo esc_html( wp_json_encode( json_decode( $log->metadata ), JSON_PRETTY_PRINT ) ); ?></pre>
												</details>
											<?php endif; ?>
										</td>
										<td><?php echo $user_display ? esc_html( $user_display ) : '&mdash;'; ?></td>
										<td><code><?php echo $log->ip_address ? esc_html( $log->ip_address ) : '&mdash;'; ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php if ( $total_pages > 1 ) : ?>
							<div class="tablenav bottom">
								<div class="tablenav-pages">
									<?php
									echo wp_kses_post(
										paginate_links(
											array(
												'base'    => add_query_arg( 'paged', '%#%' ),
												'format'  => '',
												'current' => $paged,
												'total'   => $total_pages,
											)
										)
									);
									?>
								</div>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

<?php
/**
 * Admin moderation queue page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\ModerationService;

/**
 * Admin moderation queue page.
 */
class ModerationQueue {

	const PAGE_SLUG = 'mvs-moderation';

	/**
	 * Moderation service.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * Constructor.
	 *
	 * @param ModerationService $moderation Moderation service.
	 */
	public function __construct( ModerationService $moderation ) {
		$this->moderation = $moderation;

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add moderation page under Media menu.
	 */
	public function add_menu_page(): void {
		$counts = get_transient( 'mvs_moderation_counts' );
		if ( false === $counts ) {
			$counts = $this->moderation->get_counts();
			set_transient( 'mvs_moderation_counts', $counts, 60 );
		}
		$badge = $counts['flagged'];

		$menu_title = __( 'Moderation', 'wpmediaverse' );
		if ( $badge > 0 ) {
			$menu_title .= sprintf( ' <span class="awaiting-mod">%d</span>', $badge );
		}

		add_submenu_page(
			'edit.php?post_type=mvs_media',
			__( 'Media Moderation', 'wpmediaverse' ),
			$menu_title,
			'moderate_mvs_media',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle approve/reject form submissions (single and bulk).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'moderate_mvs_media' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Bulk actions.
		if ( isset( $_POST['mvs_bulk_action'] ) && isset( $_POST['mvs_bulk_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! check_admin_referer( 'mvs_moderation_bulk', 'mvs_moderation_bulk_nonce' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				return;
			}

			$bulk_action = sanitize_text_field( wp_unslash( $_POST['mvs_bulk_action'] ) );
			$ids         = array_map( 'absint', (array) $_POST['mvs_bulk_ids'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ids         = array_filter( $ids );
			$count       = 0;

			foreach ( $ids as $media_id ) {
				if ( 'bulk_approve' === $bulk_action ) {
					$this->moderation->approve( $media_id, $user_id );
					++$count;
				} elseif ( 'bulk_reject' === $bulk_action ) {
					$this->moderation->reject( $media_id, $user_id, '' );
					++$count;
				}
			}

			$redirect_action = ( 'bulk_approve' === $bulk_action ) ? 'bulk_approved' : 'bulk_rejected';

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'updated' => $redirect_action,
						'count'   => $count,
					),
					admin_url( 'edit.php?post_type=mvs_media' )
				)
			);
			exit;
		}

		// Single item actions.
		if ( ! isset( $_POST['mvs_moderation_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! check_admin_referer( 'mvs_moderation_action', 'mvs_moderation_nonce' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$action   = sanitize_text_field( wp_unslash( $_POST['mvs_moderation_action'] ) );
		$media_id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;

		if ( ! $media_id ) {
			return;
		}

		switch ( $action ) {
			case 'approve':
				$this->moderation->approve( $media_id, $user_id );
				break;
			case 'reject':
				$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
				$this->moderation->reject( $media_id, $user_id, $reason );
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => $action,
				),
				admin_url( 'edit.php?post_type=mvs_media' )
			)
		);
		exit;
	}

	/**
	 * Render the moderation queue page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'flagged'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$result = $this->moderation->get_queue(
			array(
				'status'   => $status,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		$counts = $this->moderation->get_counts();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Moderation Queue', 'wpmediaverse' ); ?>
				<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
			</h1>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Review and manage flagged or pending media items.', 'wpmediaverse' ); ?></p>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
			?>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						if ( 'approve' === $updated ) {
							esc_html_e( 'Media item approved.', 'wpmediaverse' );
						} elseif ( 'reject' === $updated ) {
							esc_html_e( 'Media item rejected.', 'wpmediaverse' );
						} elseif ( 'bulk_approved' === $updated ) {
							printf(
								/* translators: %d: number of items */
								esc_html( _n( '%d item approved.', '%d items approved.', $bulk_count, 'wpmediaverse' ) ),
								esc_html( $bulk_count )
							);
						} elseif ( 'bulk_rejected' === $updated ) {
							printf(
								/* translators: %d: number of items */
								esc_html( _n( '%d item rejected.', '%d items rejected.', $bulk_count, 'wpmediaverse' ) ),
								esc_html( $bulk_count )
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php // --- Status Counts --- ?>
			<div class="mvs-admin-stats" style="margin-bottom:24px;">
				<?php
				$status_cards = array(
					'flagged'  => array(
						'label' => __( 'Flagged', 'wpmediaverse' ),
						'class' => 'mvs-stat-card--danger',
					),
					'pending'  => array(
						'label' => __( 'Pending', 'wpmediaverse' ),
						'class' => 'mvs-stat-card--warning',
					),
					'rejected' => array(
						'label' => __( 'Rejected', 'wpmediaverse' ),
						'class' => 'mvs-stat-card--accent',
					),
				);
				foreach ( $status_cards as $key => $card ) :
					$count     = isset( $counts[ $key ] ) ? $counts[ $key ] : 0;
					$is_active = ( $status === $key );
					$url       = add_query_arg(
						array(
							'post_type' => 'mvs_media',
							'page'      => self::PAGE_SLUG,
							'status'    => $key,
						),
						admin_url( 'edit.php' )
					);
					?>
					<a href="<?php echo esc_url( $url ); ?>"
						class="mvs-stat-card <?php echo esc_attr( $card['class'] ); ?>"
						style="<?php echo $is_active ? 'box-shadow:0 0 0 2px #2271b1;' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string. ?>">
						<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						<span class="mvs-stat-label"><?php echo esc_html( $card['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php // --- Queue Table --- ?>
			<div class="mvs-admin-widget">
				<div class="mvs-widget-header">
					<h2>
						<?php
						$status_labels = array(
							'flagged'  => __( 'Flagged Items', 'wpmediaverse' ),
							'pending'  => __( 'Pending Items', 'wpmediaverse' ),
							'rejected' => __( 'Rejected Items', 'wpmediaverse' ),
						);
						echo esc_html( $status_labels[ $status ] ?? __( 'Queue', 'wpmediaverse' ) );
						?>
					</h2>
				</div>
				<div class="mvs-widget-body mvs-widget-body--flush">
					<?php if ( empty( $result['items'] ) ) : ?>
						<div class="mvs-empty-state">
							<span class="dashicons dashicons-shield-alt"></span>
							<p><?php esc_html_e( 'No items in this queue. All clear!', 'wpmediaverse' ); ?></p>
						</div>
					<?php else : ?>
						<form method="post" id="mvs-moderation-bulk-form">
							<?php wp_nonce_field( 'mvs_moderation_bulk', 'mvs_moderation_bulk_nonce' ); ?>

							<!-- Bulk Actions Bar -->
							<div class="mvs-bulk-actions-bar">
								<select name="mvs_bulk_action" id="mvs-bulk-action-select">
									<option value=""><?php esc_html_e( 'Bulk Actions', 'wpmediaverse' ); ?></option>
									<option value="bulk_approve"><?php esc_html_e( 'Approve Selected', 'wpmediaverse' ); ?></option>
									<option value="bulk_reject"><?php esc_html_e( 'Reject Selected', 'wpmediaverse' ); ?></option>
								</select>
								<button type="submit" class="button" id="mvs-bulk-apply"><?php esc_html_e( 'Apply', 'wpmediaverse' ); ?></button>
								<span class="mvs-bulk-count" id="mvs-bulk-count" style="display:none;">
									<?php
									printf(
										/* translators: %s: placeholder replaced by JS */
										esc_html__( '%s selected', 'wpmediaverse' ),
										'<strong id="mvs-selected-count">0</strong>'
									);
									?>
								</span>
							</div>

							<table class="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<td class="manage-column column-cb check-column" style="width:40px;">
											<label class="screen-reader-text" for="mvs-select-all">
												<?php esc_html_e( 'Select All', 'wpmediaverse' ); ?>
											</label>
											<input type="checkbox" id="mvs-select-all" />
										</td>
										<th style="width:70px;"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'AI Flags', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $result['items'] as $post ) : ?>
										<?php $this->render_row( $post ); ?>
									<?php endforeach; ?>
								</tbody>
							</table>
						</form>

						<script>
						(function() {
							var selectAll = document.getElementById('mvs-select-all');
							var form = document.getElementById('mvs-moderation-bulk-form');
							var countWrap = document.getElementById('mvs-bulk-count');
							var countEl = document.getElementById('mvs-selected-count');

							function updateCount() {
								var checked = form.querySelectorAll('.mvs-bulk-cb:checked');
								countEl.textContent = checked.length;
								countWrap.style.display = checked.length > 0 ? 'inline' : 'none';
							}

							selectAll.addEventListener('change', function() {
								var boxes = form.querySelectorAll('.mvs-bulk-cb');
								boxes.forEach(function(cb) { cb.checked = selectAll.checked; });
								updateCount();
							});

							form.addEventListener('change', function(e) {
								if (e.target.classList.contains('mvs-bulk-cb')) {
									updateCount();
								}
							});

							form.addEventListener('submit', function(e) {
								var action = document.getElementById('mvs-bulk-action-select').value;
								var checked = form.querySelectorAll('.mvs-bulk-cb:checked');
								if (!action || checked.length === 0) {
									e.preventDefault();
									return;
								}
							});
						})();
						</script>

						<?php
						if ( $result['pages'] > 1 ) {
							echo '<div class="tablenav bottom"><div class="tablenav-pages">';
							echo wp_kses_post(
								paginate_links(
									array(
										'base'    => add_query_arg( 'paged', '%#%' ),
										'format'  => '',
										'current' => $paged,
										'total'   => $result['pages'],
									)
								)
							);
							echo '</div></div>';
						}
						?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single queue row.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function render_row( \WP_Post $post ): void {
		$file_url  = get_post_meta( $post->ID, '_mvs_file_url', true );
		$file_type = get_post_meta( $post->ID, '_mvs_file_type', true );
		$ai_mod    = get_post_meta( $post->ID, '_mvs_ai_moderation', true );
		$flags     = ( is_array( $ai_mod ) && ! empty( $ai_mod['flags'] ) ) ? $ai_mod['flags'] : array();
		$author    = get_userdata( $post->post_author );
		?>
		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="mvs_bulk_ids[]" value="<?php echo absint( $post->ID ); ?>" class="mvs-bulk-cb" />
			</th>
			<td>
				<?php if ( $file_url && strpos( $file_type, 'image/' ) === 0 ) : ?>
					<img src="<?php echo esc_url( $file_url ); ?>" alt="" class="mvs-thumb" style="width:60px;height:60px;" />
				<?php else : ?>
					<span class="mvs-thumb-placeholder" style="width:60px;height:60px;">
						<span class="dashicons dashicons-media-default"></span>
					</span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( $post->post_title ); ?></strong>
				<br>
				<small style="color:#50575e;"><?php echo esc_html( $file_type ); ?></small>
			</td>
			<td><?php echo $author ? esc_html( $author->display_name ) : '&mdash;'; ?></td>
			<td><?php echo esc_html( $file_type ); ?></td>
			<td>
				<?php if ( ! empty( $flags ) ) : ?>
					<?php foreach ( $flags as $flag ) : ?>
						<span class="mvs-flag-pill"><?php echo esc_html( $flag ); ?></span>
					<?php endforeach; ?>
				<?php else : ?>
					&mdash;
				<?php endif; ?>
			</td>
			<td>
				<time datetime="<?php echo esc_attr( $post->post_date ); ?>">
					<?php echo esc_html( human_time_diff( strtotime( $post->post_date ), time() ) ); ?>
					<?php esc_html_e( 'ago', 'wpmediaverse' ); ?>
				</time>
			</td>
			<td>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'mvs_moderation_action', 'mvs_moderation_nonce' ); ?>
					<input type="hidden" name="media_id" value="<?php echo absint( $post->ID ); ?>" />
					<input type="hidden" name="mvs_moderation_action" value="approve" />
					<button type="submit" class="button button-small button-primary">
						<?php esc_html_e( 'Approve', 'wpmediaverse' ); ?>
					</button>
				</form>
				<form method="post" style="display:inline;margin-left:4px;">
					<?php wp_nonce_field( 'mvs_moderation_action', 'mvs_moderation_nonce' ); ?>
					<input type="hidden" name="media_id" value="<?php echo absint( $post->ID ); ?>" />
					<input type="hidden" name="mvs_moderation_action" value="reject" />
					<button type="submit" class="button button-small" style="color:#d63638;">
						<?php esc_html_e( 'Reject', 'wpmediaverse' ); ?>
					</button>
				</form>
			</td>
		</tr>
		<?php
	}
}

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
			\WPMediaVerse\Core\Plugin::ADMIN_SLUG,
			__( 'Moderation', 'wpmediaverse' ),
			$menu_title,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle approve/reject form submissions (single and bulk).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Bulk actions.
		if ( isset( $_POST['mvs_bulk_action'] ) && isset( $_POST['mvs_bulk_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Cap + nonce pair inline. handle_actions() entry already gates
			// on manage_options || moderate_mvs_media (line ~70); the inline
			// pair documents authorization at the action site and satisfies
			// static analyzers that match per-block.
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
				return;
			}
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
					admin_url( 'admin.php' )
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
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the moderation page with tabs.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		wp_enqueue_script(
			'mvs-admin-moderation-queue',
			MVS_PLUGIN_URL . 'assets/js/admin/moderation-queue.js',
			array(),
			MVS_VERSION,
			array( 'in_footer' => true )
		);

		$counts = $this->moderation->get_counts();

		// Build tabs — Pro can inject "User Reports" via this filter.
		$tabs = array(
			'ai-flagged' => array(
				'label' => __( 'AI Flagged', 'wpmediaverse' ),
				'count' => $counts['flagged'],
			),
			'pending'    => array(
				'label' => __( 'Pending Review', 'wpmediaverse' ),
				'count' => $counts['pending'],
			),
			'resolved'   => array(
				'label' => __( 'Resolved / Rejected', 'wpmediaverse' ),
				'count' => $counts['rejected'],
			),
		);

		/**
		 * Filter moderation page tabs.
		 *
		 * Pro plugin uses this to inject the "User Reports" tab.
		 * Each tab entry: 'slug' => array( 'label' => string, 'count' => int, 'callback' => callable ).
		 *
		 * @param array $tabs Tab definitions.
		 */
		$tabs = apply_filters( 'mvs_moderation_tabs', $tabs );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'ai-flagged';
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'ai-flagged';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';

		?>
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="shield-check"></i>
						<?php esc_html_e( 'Media Moderation', 'wpmediaverse' ); ?>
					</h1>
					<p class="mvs-page-header__desc"><?php esc_html_e( 'Review and manage flagged, pending, and reported media items.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bulk_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
			?>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$update_messages = array(
							'approve'   => __( 'Media item approved.', 'wpmediaverse' ),
							'reject'    => __( 'Media item rejected.', 'wpmediaverse' ),
							'resolved'  => __( 'Report marked as resolved.', 'wpmediaverse' ),
							'dismissed' => __( 'Report dismissed.', 'wpmediaverse' ),
						);
						if ( isset( $update_messages[ $updated ] ) ) {
							echo esc_html( $update_messages[ $updated ] );
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

			<?php // --- Tab Navigation --- ?>
			<nav class="mvs-admin-tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<?php
					$tab_url   = add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					);
					$count     = isset( $tab['count'] ) ? (int) $tab['count'] : 0;
					$is_active = ( $slug === $active_tab );
					$count_cls = $count > 0 ? 'mvs-tab-count' : 'mvs-tab-count mvs-tab-count--zero';
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>"
						class="mvs-admin-tab <?php echo $is_active ? 'is-active' : ''; ?>">
						<?php echo esc_html( $tab['label'] ); ?>
						<span class="<?php echo esc_attr( $count_cls ); ?>"><?php echo esc_html( $count ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			// Render the active tab content.
			if ( isset( $tabs[ $active_tab ]['callback'] ) && is_callable( $tabs[ $active_tab ]['callback'] ) ) {
				call_user_func( $tabs[ $active_tab ]['callback'] );
			} else {
				$this->render_queue_tab( $active_tab );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render a moderation queue tab (AI Flagged, Pending, or Resolved).
	 *
	 * @param string $tab Active tab slug.
	 */
	private function render_queue_tab( string $tab ): void {
		$status_map = array(
			'ai-flagged' => 'flagged',
			'pending'    => 'pending',
			'resolved'   => 'rejected',
		);

		$status   = $status_map[ $tab ] ?? 'flagged';
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page = 20;

		$result = $this->moderation->get_queue(
			array(
				'status'   => $status,
				'per_page' => $per_page,
				'page'     => $paged,
			)
		);

		?>
		<div class="mvs-admin-widget">
			<div class="mvs-widget-body mvs-widget-body--flush">
				<?php if ( empty( $result['items'] ) ) : ?>
					<div class="mvs-empty-state">
						<i data-lucide="shield-check"></i>
						<h3><?php esc_html_e( 'Queue is Clear', 'wpmediaverse' ); ?></h3>
						<p><?php esc_html_e( 'No items in this queue. All clear!', 'wpmediaverse' ); ?></p>
					</div>
				<?php else : ?>
					<form method="post" id="mvs-moderation-bulk-form">
						<?php wp_nonce_field( 'mvs_moderation_bulk', 'mvs_moderation_bulk_nonce' ); ?>

						<div class="mvs-bulk-actions-bar">
							<select name="mvs_bulk_action" id="mvs-bulk-action-select">
								<option value=""><?php esc_html_e( 'Bulk Actions', 'wpmediaverse' ); ?></option>
								<option value="bulk_approve"><?php esc_html_e( 'Approve Selected', 'wpmediaverse' ); ?></option>
								<option value="bulk_reject"><?php esc_html_e( 'Reject Selected', 'wpmediaverse' ); ?></option>
							</select>
							<button type="submit" class="mvs-btn mvs-btn--sm" id="mvs-bulk-apply"><?php esc_html_e( 'Apply', 'wpmediaverse' ); ?></button>
							<span class="mvs-bulk-count mvs-hidden" id="mvs-bulk-count">
								<?php
								printf(
									/* translators: %s: placeholder replaced by JS */
									esc_html__( '%s selected', 'wpmediaverse' ),
									'<strong id="mvs-selected-count">0</strong>'
								);
								?>
							</span>
						</div>
						</form>
						<?php // Bulk form closed here. The table's checkboxes join it via the HTML5 form="" attribute, so the per-row Approve/Reject forms below are NOT nested inside it (nested forms are invalid HTML and the browser drops them, which silently killed the per-row buttons). ?>

						<table class="mvs-moderation-table striped">
							<thead>
								<tr>
									<td class="manage-column column-cb check-column">
										<label class="screen-reader-text" for="mvs-select-all">
											<?php esc_html_e( 'Select All', 'wpmediaverse' ); ?>
										</label>
										<input type="checkbox" id="mvs-select-all" form="mvs-moderation-bulk-form" />
									</td>
									<th class="column-thumb"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'AI Flags', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $result['items'] as $media_id ) : ?>
									<?php $this->render_row( (int) $media_id ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>


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
		<?php
	}

	/**
	 * Render a single queue row.
	 *
	 * @param int $media_id Media ID from the mvs_media_index table.
	 */
	private function render_row( int $media_id ): void {
		$data = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( $media_id );
		if ( empty( $data ) ) {
			return;
		}

		$file_type  = $data['file_type'] ?? '';
		$mq_su      = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
		$file_url   = $mq_su
			? $mq_su->generate_thumbnail( $media_id, get_current_user_id(), 'large', 0, true )
			: '';
		$title      = $data['title'] ?? '';
		$created_at = $data['created_at'] ?? '';
		$author_id  = (int) ( $data['post_author'] ?? 0 );
		$author     = $author_id ? get_userdata( $author_id ) : false;
		$ai_mod     = $data['ai_moderation'] ?? '';
		if ( is_string( $ai_mod ) && '' !== $ai_mod ) {
			$decoded = json_decode( $ai_mod, true );
			$ai_mod  = is_array( $decoded ) ? $decoded : array();
		}
		$flags = ( is_array( $ai_mod ) && ! empty( $ai_mod['flags'] ) ) ? $ai_mod['flags'] : array();
		?>
		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="mvs_bulk_ids[]" value="<?php echo absint( $media_id ); ?>" class="mvs-bulk-cb" form="mvs-moderation-bulk-form" />
			</th>
			<td>
				<?php if ( $file_url && strpos( (string) $file_type, 'image/' ) === 0 ) : ?>
					<img src="<?php echo esc_url( $file_url ); ?>" alt="" class="mvs-thumb mvs-moderation-thumb" />
				<?php else : ?>
					<span class="mvs-thumb-placeholder mvs-moderation-thumb-placeholder">
						<i data-lucide="file"></i>
					</span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( $title ); ?></strong>
				<br>
				<small class="mvs-text-muted"><?php echo esc_html( $file_type ); ?></small>
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
				<?php $created_ts = $created_at ? strtotime( $created_at . ' UTC' ) : 0; ?>
				<time datetime="<?php echo esc_attr( $created_at ); ?>">
					<?php echo $created_ts ? esc_html( human_time_diff( $created_ts, time() ) ) : '&mdash;'; ?>
					<?php
					if ( $created_ts ) {
						esc_html_e( 'ago', 'wpmediaverse' ); }
					?>
				</time>
			</td>
			<td>
				<form method="post" class="mvs-form-inline">
					<?php wp_nonce_field( 'mvs_moderation_action', 'mvs_moderation_nonce' ); ?>
					<input type="hidden" name="media_id" value="<?php echo absint( $media_id ); ?>" />
					<input type="hidden" name="mvs_moderation_action" value="approve" />
					<button type="submit" class="mvs-btn mvs-btn--sm mvs-btn--primary">
						<?php esc_html_e( 'Approve', 'wpmediaverse' ); ?>
					</button>
				</form>
				<form method="post" class="mvs-form-inline-spaced">
					<?php wp_nonce_field( 'mvs_moderation_action', 'mvs_moderation_nonce' ); ?>
					<input type="hidden" name="media_id" value="<?php echo absint( $media_id ); ?>" />
					<input type="hidden" name="mvs_moderation_action" value="reject" />
					<button type="submit" class="mvs-btn mvs-btn--sm mvs-btn--danger">
						<?php esc_html_e( 'Reject', 'wpmediaverse' ); ?>
					</button>
				</form>
			</td>
		</tr>
		<?php
	}
}

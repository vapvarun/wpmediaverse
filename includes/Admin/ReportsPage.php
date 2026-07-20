<?php
/**
 * Member Reports admin screen (Free).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

use WPMediaVerse\Social\ReportService;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews reports members file against media and other members.
 *
 * Reporting is on by default (see ReportService::reports_enabled()), so every
 * site needs somewhere for those reports to land. Without a queue, reporting is
 * a black hole: the member is told their report was sent, and nobody can ever
 * read it. That is worse than having no report button at all, and App Store
 * guideline 1.2 expects reports to actually be acted on.
 *
 * Pro ships a richer User Reports screen (Admin\ReportManager). This page only
 * registers when Pro is inactive, so a site never shows two reports menus.
 *
 * @since 2.1.0
 */
class ReportsPage {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'mvs-reports';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Statuses a report can be in, in tab order.
	 */
	const STATUSES = array( 'pending', 'resolved', 'dismissed' );

	/**
	 * Report service.
	 *
	 * @var ReportService
	 */
	private ReportService $reports;

	/**
	 * Constructor.
	 *
	 * @param ReportService $reports Report service.
	 */
	public function __construct( ReportService $reports ) {
		$this->reports = $reports;

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_mvs_report_status', array( $this, 'handle_status_change' ) );
	}

	/**
	 * Whether the current user may moderate reports.
	 *
	 * @return bool
	 */
	private function can_moderate(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'moderate_mvs_media' );
	}

	/**
	 * Register the submenu, badged with the pending count.
	 */
	public function add_menu_page(): void {
		$pending = $this->reports->count_by_status( 'pending' );

		$menu_title = __( 'Member Reports', 'wpmediaverse' );
		if ( $pending > 0 ) {
			$menu_title .= sprintf( ' <span class="awaiting-mod">%d</span>', $pending );
		}

		add_submenu_page(
			\WPMediaVerse\Core\Plugin::ADMIN_SLUG,
			__( 'Member Reports', 'wpmediaverse' ),
			$menu_title,
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Resolve or dismiss a report.
	 */
	public function handle_status_change(): void {
		if ( ! $this->can_moderate() ) {
			wp_die( esc_html__( 'You are not allowed to moderate reports.', 'wpmediaverse' ), 403 );
		}

		$report_id = isset( $_POST['report_id'] ) ? absint( $_POST['report_id'] ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		check_admin_referer( 'mvs_report_status_' . $report_id );

		if ( $report_id > 0 && in_array( $status, self::STATUSES, true ) ) {
			$this->reports->update_status( $report_id, $status );
		}

		// Return the moderator to the tab they were working in.
		$back = isset( $_POST['back_status'] ) ? sanitize_key( wp_unslash( $_POST['back_status'] ) ) : 'pending';
		$back = in_array( $back, self::STATUSES, true ) ? $back : 'pending';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'report_status' => $back,
					'updated'       => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the queue.
	 */
	public function render_page(): void {
		if ( ! $this->can_moderate() ) {
			wp_die( esc_html__( 'You are not allowed to moderate reports.', 'wpmediaverse' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/paging state.
		$status = isset( $_GET['report_status'] ) ? sanitize_key( wp_unslash( $_GET['report_status'] ) ) : 'pending';
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'pending';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/paging state.
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total  = $this->reports->count_by_status( $status );
		$pages  = (int) ceil( $total / self::PER_PAGE );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$rows   = $this->reports->list_reports( $status, self::PER_PAGE, $offset );

		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		echo '<div class="wrap wpmediaverse-admin">';
		echo '<h1>' . esc_html__( 'Member Reports', 'wpmediaverse' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Content and members your community has flagged for review.', 'wpmediaverse' ) . '</p>';

		if ( ! ReportService::reports_enabled() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Member reporting is turned off, so no new reports can arrive. Turn it back on under Settings, AI & Moderation.', 'wpmediaverse' ) . '</p></div>';
		}

		// Status tabs.
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::STATUSES as $tab ) {
			$labels = array(
				'pending'   => __( 'Pending', 'wpmediaverse' ),
				'resolved'  => __( 'Resolved', 'wpmediaverse' ),
				'dismissed' => __( 'Dismissed', 'wpmediaverse' ),
			);
			printf(
				'<a href="%s" class="nav-tab %s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'report_status', $tab, $base_url ) ),
				esc_attr( $tab === $status ? 'nav-tab-active' : '' ),
				esc_html( $labels[ $tab ] ),
				(int) $this->reports->count_by_status( $tab )
			);
		}
		echo '</h2>';

		if ( empty( $rows ) ) {
			echo '<p style="margin-top:1em;">' . esc_html__( 'Nothing here. No reports with this status.', 'wpmediaverse' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:1em;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Reported', 'wpmediaverse' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reason', 'wpmediaverse' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Details', 'wpmediaverse' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reported by', 'wpmediaverse' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'When', 'wpmediaverse' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'wpmediaverse' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$this->render_row( $row, $status );
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg(
							array(
								'report_status' => $status,
								'paged'         => '%#%',
							),
							$base_url
						),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Render one report row.
	 *
	 * @param object $row    Report row.
	 * @param string $status Current tab, so actions return here.
	 */
	private function render_row( object $row, string $status ): void {
		$target_label = '';
		$target_link  = '';

		if ( 'media' === $row->target_type ) {
			$target_link  = (string) get_permalink( (int) $row->target_id );
			$title        = get_the_title( (int) $row->target_id );
			$target_label = '' !== trim( (string) $title )
				? $title
				/* translators: %d: media ID. */
				: sprintf( __( 'Media #%d', 'wpmediaverse' ), (int) $row->target_id );
		} else {
			$user         = get_userdata( (int) $row->target_id );
			$target_link  = $user ? (string) get_author_posts_url( (int) $row->target_id ) : '';
			$target_label = $user
				? $user->display_name
				/* translators: %d: user ID. */
				: sprintf( __( 'Member #%d (deleted)', 'wpmediaverse' ), (int) $row->target_id );
		}

		$reporter       = get_userdata( (int) $row->reporter_id );
		$reporter_label = $reporter ? $reporter->display_name : __( 'Deleted member', 'wpmediaverse' );

		echo '<tr>';

		echo '<td>';
		echo '<strong>';
		if ( '' !== $target_link ) {
			printf( '<a href="%s">%s</a>', esc_url( $target_link ), esc_html( $target_label ) );
		} else {
			echo esc_html( $target_label );
		}
		echo '</strong><br />';
		echo '<span class="description">' . esc_html( 'media' === $row->target_type ? __( 'Media', 'wpmediaverse' ) : __( 'Member', 'wpmediaverse' ) ) . '</span>';
		echo '</td>';

		echo '<td>' . esc_html( $row->reason ) . '</td>';
		echo '<td>' . esc_html( '' !== (string) $row->details ? (string) $row->details : '—' ) . '</td>';
		echo '<td>' . esc_html( $reporter_label ) . '</td>';
		echo '<td>' . esc_html( (string) $row->created_at ) . '</td>';

		echo '<td>';
		foreach ( array( 'resolved', 'dismissed', 'pending' ) as $action ) {
			if ( $action === $status ) {
				continue;
			}
			$labels = array(
				'resolved'  => __( 'Resolve', 'wpmediaverse' ),
				'dismissed' => __( 'Dismiss', 'wpmediaverse' ),
				'pending'   => __( 'Reopen', 'wpmediaverse' ),
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px;">';
			wp_nonce_field( 'mvs_report_status_' . (int) $row->id );
			echo '<input type="hidden" name="action" value="mvs_report_status" />';
			echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) (int) $row->id ) . '" />';
			echo '<input type="hidden" name="status" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="back_status" value="' . esc_attr( $status ) . '" />';
			echo '<button type="submit" class="button button-small">' . esc_html( $labels[ $action ] ) . '</button>';
			echo '</form>';
		}
		echo '</td>';

		echo '</tr>';
	}
}

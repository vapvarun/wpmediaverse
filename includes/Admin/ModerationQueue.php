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
		$counts = $this->moderation->get_counts();
		$badge  = $counts['flagged'];

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
	 * Handle approve/reject form submissions.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['mvs_moderation_action'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'mvs_moderation_action', 'mvs_moderation_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'moderate_mvs_media' ) ) {
			return;
		}

		$action   = sanitize_text_field( wp_unslash( $_POST['mvs_moderation_action'] ) );
		$media_id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;

		if ( ! $media_id ) {
			return;
		}

		$user_id = get_current_user_id();

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
			<h1><?php esc_html_e( 'Media Moderation Queue', 'wpmediaverse' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						if ( 'approve' === $updated ) {
							esc_html_e( 'Media item approved.', 'wpmediaverse' );
						} elseif ( 'reject' === $updated ) {
							esc_html_e( 'Media item rejected.', 'wpmediaverse' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php
				$statuses = array(
					'flagged'  => __( 'Flagged', 'wpmediaverse' ),
					'pending'  => __( 'Pending', 'wpmediaverse' ),
					'rejected' => __( 'Rejected', 'wpmediaverse' ),
				);
				$links    = array();
				foreach ( $statuses as $key => $label ) {
					$count = isset( $counts[ $key ] ) ? $counts[ $key ] : 0;
					$class = ( $status === $key ) ? ' class="current"' : '';
					$url   = add_query_arg(
						array(
							'post_type' => 'mvs_media',
							'page'      => self::PAGE_SLUG,
							'status'    => $key,
						),
						admin_url( 'edit.php' )
					);

					$links[] = sprintf(
						'<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>',
						esc_url( $url ),
						$class,
						esc_html( $label ),
						$count
					);
				}
				echo wp_kses_post( implode( ' | ', $links ) );
				?>
			</ul>
			<br class="clear" />

			<?php if ( empty( $result['items'] ) ) : ?>
				<p><?php esc_html_e( 'No items in this queue.', 'wpmediaverse' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:80px;"><?php esc_html_e( 'Thumbnail', 'wpmediaverse' ); ?></th>
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
			<td>
				<?php if ( $file_url && strpos( $file_type, 'image/' ) === 0 ) : ?>
					<img src="<?php echo esc_url( $file_url ); ?>" alt="" style="max-width:60px;max-height:60px;" />
				<?php else : ?>
					<span class="dashicons dashicons-media-default" style="font-size:40px;width:40px;height:40px;"></span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( $post->post_title ); ?></strong>
				<br>
				<small><?php echo esc_html( $file_type ); ?></small>
			</td>
			<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
			<td><?php echo esc_html( $file_type ); ?></td>
			<td>
				<?php if ( ! empty( $flags ) ) : ?>
					<?php foreach ( $flags as $flag ) : ?>
						<span class="mvs-flag" style="background:#dc3232;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;margin-right:3px;">
							<?php echo esc_html( $flag ); ?>
						</span>
					<?php endforeach; ?>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( get_the_date( '', $post ) ); ?></td>
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
					<button type="submit" class="button button-small" style="color:#dc3232;">
						<?php esc_html_e( 'Reject', 'wpmediaverse' ); ?>
					</button>
				</form>
			</td>
		</tr>
		<?php
	}
}

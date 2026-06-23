<?php
/**
 * Partial: member usage history (mvs_transactions ledger).
 *
 * Server-rendered table of the current member's recent upload/usage transactions.
 * Reusable by the dashboard section and the [mvs_usage_history] shortcode. Reads
 * the Services\TransactionService — never raw $wpdb.
 *
 * @package WPMediaVerse
 *
 * @var int $mvs_uh_user_id  Optional member ID (defaults to current user).
 * @var int $mvs_uh_limit    Optional row cap (default 20).
 */

defined( 'ABSPATH' ) || exit;

$mvs_uh_user_id = isset( $mvs_uh_user_id ) ? (int) $mvs_uh_user_id : get_current_user_id();
$mvs_uh_limit   = isset( $mvs_uh_limit ) ? max( 1, min( 100, (int) $mvs_uh_limit ) ) : 20;

if ( $mvs_uh_user_id < 1 ) {
	return;
}

$mvs_uh_service = \WPMediaVerse\Core\Plugin::container()->get( 'transactions' );
$mvs_uh_rows    = $mvs_uh_service->get_for_user( $mvs_uh_user_id, array( 'per_page' => $mvs_uh_limit ) );
$mvs_uh_total   = $mvs_uh_service->count_for_user( $mvs_uh_user_id );

$mvs_uh_type_labels = array(
	'image'    => __( 'Image', 'wpmediaverse' ),
	'video'    => __( 'Video', 'wpmediaverse' ),
	'audio'    => __( 'Audio', 'wpmediaverse' ),
	'document' => __( 'Document', 'wpmediaverse' ),
);
?>
<section class="mvs-usage-history" aria-labelledby="mvs-usage-history-title">
	<h3 id="mvs-usage-history-title" class="mvs-usage-history__title">
		<?php esc_html_e( 'Usage history', 'wpmediaverse' ); ?>
	</h3>

	<?php if ( empty( $mvs_uh_rows ) ) : ?>
		<div class="mvs-usage-history__empty">
			<p><?php esc_html_e( 'No uploads yet', 'wpmediaverse' ); ?></p>
			<p><?php esc_html_e( 'Your upload activity will appear here.', 'wpmediaverse' ); ?></p>
		</div>
	<?php else : ?>
		<table class="mvs-usage-history__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change', 'wpmediaverse' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'wpmediaverse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mvs_uh_rows as $mvs_uh_row ) : ?>
					<?php
					$mvs_uh_type  = (string) $mvs_uh_row['media_type'];
					$mvs_uh_delta = (int) $mvs_uh_row['delta'];
					?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mvs_uh_row['created_at'] ) ); ?></td>
						<td>
							<span class="mvs-usage-history__type mvs-usage-history__type--<?php echo esc_attr( $mvs_uh_type ); ?>">
								<?php echo esc_html( $mvs_uh_type_labels[ $mvs_uh_type ] ?? ucfirst( $mvs_uh_type ) ); ?>
							</span>
						</td>
						<td class="mvs-usage-history__delta mvs-usage-history__delta--<?php echo $mvs_uh_delta >= 0 ? 'up' : 'down'; ?>">
							<?php echo esc_html( ( $mvs_uh_delta >= 0 ? '+' : '' ) . number_format_i18n( $mvs_uh_delta ) ); ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $mvs_uh_row['balance_after'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $mvs_uh_total > count( $mvs_uh_rows ) ) : ?>
			<p class="mvs-usage-history__more">
				<?php
				printf(
					/* translators: 1: shown count, 2: total count */
					esc_html__( 'Showing %1$s of %2$s', 'wpmediaverse' ),
					esc_html( number_format_i18n( count( $mvs_uh_rows ) ) ),
					esc_html( number_format_i18n( $mvs_uh_total ) )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>

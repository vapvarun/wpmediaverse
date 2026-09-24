<?php
/**
 * Template: recent failed webhook deliveries (Settings > Webhooks).
 *
 * WebhookService::log_failure() has kept the last 50 failures in
 * `mvs_webhook_failures` since 1.x, but nothing showed them, so an owner whose
 * endpoint was down had no way to know. This lists the newest few.
 *
 * Variables provided by FieldRenderer::render_webhook_field():
 *
 * @var array<int, array{url:string, error:string, time:string}> $mvs_failures Newest first.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mvs-webhook-failures">
	<h4><?php esc_html_e( 'Recent failed deliveries', 'wpmediaverse' ); ?></h4>
	<?php if ( empty( $mvs_failures ) ) : ?>
		<p class="description"><?php esc_html_e( 'No failed deliveries. When a webhook cannot be delivered, it is listed here.', 'wpmediaverse' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'wpmediaverse' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Destination', 'wpmediaverse' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Error', 'wpmediaverse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mvs_failures as $mvs_failure ) : ?>
					<?php $mvs_when = strtotime( (string) ( $mvs_failure['time'] ?? '' ) . ' UTC' ); ?>
					<tr>
						<td>
							<?php
							echo esc_html(
								$mvs_when
									/* translators: %s: human time difference, e.g. "5 mins" */
									? sprintf( __( '%s ago', 'wpmediaverse' ), human_time_diff( $mvs_when ) )
									: '-'
							);
							?>
						</td>
						<td class="mvs-webhook-failures__url"><?php echo esc_html( (string) ( $mvs_failure['url'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $mvs_failure['error'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The 10 most recent failed attempts. Timeouts and server errors (5xx) are retried up to 3 times, so one event can appear more than once; other errors are not retried.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
</div>

<?php
/**
 * AI Review mini-page template.
 *
 * Renders the per-media AI auto-describe / auto-tag review surface:
 * view + edit the AI description and tags, then Accept / Reject / Re-run.
 *
 * Loaded by {@see \WPMediaVerse\Admin\MediaListPage::render_ai_review()} via
 * `require` after that method has prepared and escaped the data. All variables
 * below are provided by the caller; this file is pure presentation per
 * Coding Rule #4 (Admin HTML lives in template files, never inline echo in PHP
 * classes).
 *
 * @package WPMediaVerse
 *
 * @var int    $media_id     Media ID under review.
 * @var string $title        Media title (raw, escape on output).
 * @var string $thumb_url    Signed thumbnail URL or empty string.
 * @var string $ai_status    Raw ai_status meta ('', processing, complete, failed, accepted, rejected).
 * @var string $status_label Human-readable status label.
 * @var string $status_class Badge CSS modifier class for the status.
 * @var string $description  Current ai_description (raw, escape on output).
 * @var string $tags_text    Current ai_tags as a comma-separated string (raw).
 * @var float  $confidence   AI confidence score 0..1.
 * @var bool   $reviewed     Whether this result has already been accepted/rejected.
 * @var string $list_url     Back-to-list URL.
 * @var string $accept_url   wp_nonce_url for the Accept POST form action.
 * @var string $reject_url   wp_nonce_url for the Reject action.
 * @var string $rerun_url    wp_nonce_url for the Re-run action.
 * @var string $accept_nonce Nonce field markup for the Accept form.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wpmediaverse-admin">
	<div class="mvs-page-header">
		<div class="mvs-page-header__left">
			<h1 class="mvs-page-header__title">
				<i data-lucide="sparkles"></i>
				<?php
				/* translators: %d: media id */
				echo esc_html( sprintf( __( 'AI Review — Media #%d', 'wpmediaverse' ), $media_id ) );
				?>
			</h1>
			<p class="mvs-page-header__desc">
				<a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All media', 'wpmediaverse' ); ?></a>
			</p>
		</div>
	</div>

	<?php
	$mvs_ai_notice = get_transient( 'mvs_ai_review_notice' );
	if ( is_array( $mvs_ai_notice ) && ! empty( $mvs_ai_notice['message'] ) ) {
		delete_transient( 'mvs_ai_review_notice' );
		$mvs_ai_notice_class = 'success' === ( $mvs_ai_notice['type'] ?? '' ) ? 'notice-success' : 'notice-warning';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $mvs_ai_notice_class ),
			esc_html( (string) $mvs_ai_notice['message'] )
		);
	}
	?>

	<div class="mvs-admin-widget">
		<div class="mvs-widget-body">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Media', 'wpmediaverse' ); ?></th>
						<td>
							<?php if ( '' !== $thumb_url ) : ?>
								<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" class="mvs-ai-review-thumb" />
							<?php endif; ?>
							<strong><?php echo esc_html( '' !== $title ? $title : __( '(no title)', 'wpmediaverse' ) ); ?></strong>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'AI status', 'wpmediaverse' ); ?></th>
						<td>
							<span class="mvs-media-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
							<?php if ( $reviewed ) : ?>
								&nbsp;<em class="description"><?php esc_html_e( 'This result has already been reviewed.', 'wpmediaverse' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Confidence', 'wpmediaverse' ); ?></th>
						<td>
							<?php if ( $confidence > 0 ) : ?>
								<?php echo esc_html( number_format_i18n( $confidence * 100, 1 ) . '%' ); ?>
							<?php else : ?>
								<em><?php esc_html_e( 'Not reported', 'wpmediaverse' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( 'processing' === $ai_status ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'AI is still processing this media. Reload this page in a moment to see the result.', 'wpmediaverse' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'failed' === $ai_status ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The last AI run failed for this media. You can re-run it below.', 'wpmediaverse' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === $description && '' === $tags_text ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No AI description or tags are stored for this media yet. If you expected results, make sure an AI provider is configured under WPMediaVerse settings, then use Re-run AI below.', 'wpmediaverse' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Edit & Accept', 'wpmediaverse' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Review the AI-generated description and tags. Editing here does not change the media until you click Accept. Accepting copies the description into the media and applies the tags. Rejecting clears the AI result so it will not be used as alt text.', 'wpmediaverse' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $accept_url ); ?>">
				<?php
				// Cap + nonce: the nonce field is emitted here; the matching
				// check_admin_referer() + current_user_can() pair lives inline
				// in MediaListPage::handle_bulk_actions() for the ai_accept case.
				echo $accept_nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field() returns safe markup.
				?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="mvs-ai-description"><?php esc_html_e( 'AI description', 'wpmediaverse' ); ?></label>
							</th>
							<td>
								<textarea id="mvs-ai-description" name="ai_description" rows="4" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Used as image alt text for accessibility when accepted.', 'wpmediaverse' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mvs-ai-tags"><?php esc_html_e( 'AI tags', 'wpmediaverse' ); ?></label>
							</th>
							<td>
								<input type="text" id="mvs-ai-tags" name="ai_tags" value="<?php echo esc_attr( $tags_text ); ?>" class="large-text" />
								<p class="description"><?php esc_html_e( 'Comma-separated. Accepting applies these to the media tag taxonomy.', 'wpmediaverse' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Accept & apply', 'wpmediaverse' ); ?></button>
					<a class="button" href="<?php echo esc_url( $reject_url ); ?>" data-mvs-confirm="<?php esc_attr_e( 'Reject and clear the AI description and tags for this media? It will no longer be used as alt text.', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Reject', 'wpmediaverse' ); ?></a>
					<a class="button" href="<?php echo esc_url( $rerun_url ); ?>"><?php esc_html_e( 'Re-run AI', 'wpmediaverse' ); ?></a>
				</p>
			</form>
		</div>
	</div>
</div>

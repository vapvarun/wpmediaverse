<?php
/**
 * Server-side render for the pdf-viewer block.
 *
 * Renders an inline browser-native PDF viewer for any MVS media whose
 * file_type is application/pdf. Uses an <iframe> + the file URL —
 * no PDF.js bundling, no client-side rendering library. Browsers all
 * have built-in PDF viewers; we just point an iframe at the file and
 * the browser handles the rest.
 *
 * Honors the same privacy gate as other media: signed URLs are
 * generated for the current viewer; if they don't have access, the
 * file URL won't render. Empty state surfaces when:
 *   - mediaId is 0 (block not configured)
 *   - media doesn't exist in the index
 *   - media file_type is not application/pdf
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$mvs_pdf_id      = isset( $attributes['mediaId'] ) ? absint( $attributes['mediaId'] ) : 0;
$mvs_pdf_height  = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 600;
$mvs_pdf_toolbar = ! empty( $attributes['showToolbar'] );

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-pdf-viewer-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );

// A block-configuration notice is shown to editors and NOBODY else: a visitor
// gets nothing rather than an instruction meant for whoever placed the block —
// which would also confirm the id resolves to something real. Convention from
// card 10168162725; rendered through the shared frontend empty-state helper
// (.mvs-empty-state-frontend), not a hand-rolled admin-flavoured div.
$mvs_pdf_tpl    = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
$mvs_pdf_notice = static function ( $icon, $message ) use ( $wrapper, $mvs_pdf_tpl ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return '<div ' . $wrapper . '>' . $mvs_pdf_tpl->render_block_empty_state(
		array(
			'icon'    => $icon,
			'message' => $message,
		)
	) . '</div>';
};

// Empty state: no media id configured.
if ( ! $mvs_pdf_id ) {
	echo $mvs_pdf_notice( 'file-text', __( 'No PDF selected. Edit this block and set a Media ID to display a PDF.', 'wpmediaverse' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped.
	return;
}

$mvs_pdf_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

if ( ! $mvs_pdf_repo->exists( $mvs_pdf_id ) ) {
	echo $mvs_pdf_notice( 'file-text', __( 'PDF not found.', 'wpmediaverse' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped.
	return;
}

// Refuse a document. A document's PDF has file_type application/pdf and would
// pass the check below, but documents live outside the media tree
// ({uploads}/wpmediaverse-documents/, resolved by their own viewer) and carry a
// per-user/role/space sharing model this public block cannot honour — the media
// serve route can neither find the file nor apply that model, so the iframe just
// 404'd. Documents are shown on their own page.
if ( 'document' === (string) $mvs_pdf_repo->get( $mvs_pdf_id, 'media_type' ) ) {
	echo $mvs_pdf_notice( 'file-text', __( 'Documents have their own viewer and cannot be embedded here. Pick a PDF media file instead.', 'wpmediaverse' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped.
	return;
}

// Verify the file is actually a PDF — refuse to render an iframe for
// other media types since browsers won't sandbox / view them inline
// the same way.
$mvs_pdf_file_type = (string) $mvs_pdf_repo->get( $mvs_pdf_id, 'file_type' );
if ( 'application/pdf' !== $mvs_pdf_file_type ) {
	echo $mvs_pdf_notice( 'file-text', __( 'This block can only display PDF files. Pick a media item whose file is a PDF.', 'wpmediaverse' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped.
	return;
}

// Privacy + signed URL.
$mvs_pdf_signed = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
$mvs_pdf_url    = $mvs_pdf_signed
	? $mvs_pdf_signed->generate( $mvs_pdf_id, get_current_user_id() )
	: '';

if ( ! $mvs_pdf_url ) {
	// A viewer who lacks access gets nothing (the embed simply does not appear);
	// an editor is told why, through the same gated helper.
	echo $mvs_pdf_notice( 'lock', __( 'You do not have permission to view this PDF.', 'wpmediaverse' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper output is escaped.
	return;
}

// Build the URL fragment that controls the browser's native PDF
// toolbar visibility — chrome / firefox respect #toolbar=0|1; safari
// ignores. Defaults to fit-horizontal so the first-load view feels
// consistent across browsers.
$mvs_pdf_fragment = '#view=FitH';
if ( ! $mvs_pdf_toolbar ) {
	$mvs_pdf_fragment .= '&toolbar=0';
}

$mvs_pdf_title = (string) $mvs_pdf_repo->get( $mvs_pdf_id, 'title' );
$mvs_pdf_iframe_title = $mvs_pdf_title ? $mvs_pdf_title : __( 'PDF document', 'wpmediaverse' );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<iframe
		class="mvs-pdf-viewer-frame"
		src="<?php echo esc_url( $mvs_pdf_url . $mvs_pdf_fragment ); ?>"
		title="<?php echo esc_attr( $mvs_pdf_iframe_title ); ?>"
		style="width: 100%; height: <?php echo absint( $mvs_pdf_height ); ?>px; border: 1px solid var(--mvs-border-light, #e5e5e5); border-radius: 8px;"
		loading="lazy"
		referrerpolicy="strict-origin-when-cross-origin"
	></iframe>
	<?php if ( $mvs_pdf_title ) : ?>
		<p class="mvs-pdf-viewer-caption">
			<i data-lucide="file-text" aria-hidden="true"></i>
			<span><?php echo esc_html( $mvs_pdf_title ); ?></span>
		</p>
	<?php endif; ?>
</div>

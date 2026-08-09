<?php
/**
 * The public document listing — `/explore-document`.
 *
 * ROWS, NOT TILES. The display contract is explicit and the reason is practical:
 * a grid of identical PDF icons carries no information, and a media grid trying
 * to draw a PDF produces a broken tile — which is exactly what a screenshot of
 * `/explore-media` showed before documents were moved here.
 *
 * A row carries what actually distinguishes one document from another: its name,
 * its type, who uploaded it, when, and how big it is.
 *
 * Variables provided by Shortcodes::render_documents():
 *
 * @var array  $mvs_doc_query    { items, total, pages }
 * @var int    $mvs_doc_page     Current page.
 * @var int    $mvs_doc_per_page Rows per page.
 * @var string $mvs_doc_filter   Active type filter, or ''.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

$mvs_doc_items  = isset( $mvs_doc_query['items'] ) ? (array) $mvs_doc_query['items'] : array();
$mvs_doc_total  = isset( $mvs_doc_query['total'] ) ? (int) $mvs_doc_query['total'] : 0;
$mvs_doc_pages  = isset( $mvs_doc_query['pages'] ) ? (int) $mvs_doc_query['pages'] : 0;
$mvs_doc_helper = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
$mvs_doc_repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

/**
 * Lucide icon per document group, so a row is scannable by shape.
 */
$mvs_doc_icons = array(
	'pdf'              => 'file-text',
	'word'             => 'file-type',
	'excel'            => 'file-spreadsheet',
	'powerpoint'       => 'presentation',
	'odf_text'         => 'file-type',
	'odf_sheet'        => 'file-spreadsheet',
	'odf_presentation' => 'presentation',
	'text'             => 'file',
	'markdown'         => 'file-code',
	'csv'              => 'file-spreadsheet',
	'rtf'              => 'file',
);
?>
<div class="mvs-documents mvs-page">
	<header class="mvs-documents__header">
		<?php
		// No <h1> here: the page already supplies its title, and a second heading
		// saying "Documents" under "Explore Documents" is noise a screen reader
		// has to read twice. The count is the part that adds information.
		printf(
			'<p class="mvs-documents__count">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: number of documents. */
					_n( '%s document', '%s documents', $mvs_doc_total, 'wpmediaverse' ),
					number_format_i18n( $mvs_doc_total )
				)
			)
		);
		?>
	</header>

	<?php if ( ! $mvs_doc_items ) : ?>
		<?php
		// Coding Rule 11 — a render path never falls through silently.
		echo wp_kses_post(
			$mvs_doc_helper->render_block_empty_state(
				array(
					'icon'    => 'file-text',
					'title'   => '' !== $mvs_doc_filter
						? __( 'No documents of that type yet', 'wpmediaverse' )
						: __( 'No documents yet', 'wpmediaverse' ),
					'message' => '' !== $mvs_doc_filter
						? __( 'Nothing here matches that filter. Try browsing everything instead.', 'wpmediaverse' )
						: __( 'Documents shared publicly will appear here.', 'wpmediaverse' ),
					'actions' => '' !== $mvs_doc_filter
						? array(
							array(
								'url'   => remove_query_arg( array( 'doc_type', 'doc_page' ) ),
								'label' => __( 'Browse all documents', 'wpmediaverse' ),
							),
						)
						: array(),
				)
			)
		);
		?>
	<?php else : ?>
		<ul class="mvs-documents__list">
			<?php foreach ( $mvs_doc_items as $mvs_doc ) : ?>
				<?php
				$mvs_doc_id     = (int) $mvs_doc['media_id'];
				$mvs_doc_mime   = (string) $mvs_doc['file_type'];
				$mvs_doc_group  = class_exists( '\WPMediaVerse\Core\DocumentTypes' )
					? \WPMediaVerse\Core\DocumentTypes::group_for_mime( $mvs_doc_mime )
					: null;
				$mvs_doc_icon   = $mvs_doc_group && isset( $mvs_doc_icons[ $mvs_doc_group ] )
					? $mvs_doc_icons[ $mvs_doc_group ]
					: 'file';
				$mvs_doc_link   = $mvs_doc_repo->get_permalink( $mvs_doc_id );
				$mvs_doc_author = get_userdata( (int) $mvs_doc['post_author'] );
				$mvs_doc_size   = (int) $mvs_doc['file_size'];
				?>
				<li class="mvs-documents__row">
					<span class="mvs-documents__icon" aria-hidden="true">
						<i data-lucide="<?php echo esc_attr( $mvs_doc_icon ); ?>"></i>
					</span>

					<span class="mvs-documents__main">
						<a class="mvs-documents__name" href="<?php echo esc_url( (string) $mvs_doc_link ); ?>">
							<?php echo esc_html( (string) $mvs_doc['title'] ); ?>
						</a>
						<?php if ( ! empty( $mvs_doc['description'] ) ) : ?>
							<span class="mvs-documents__excerpt">
								<?php echo esc_html( wp_trim_words( (string) $mvs_doc['description'], 18 ) ); ?>
							</span>
						<?php endif; ?>
					</span>

					<?php if ( $mvs_doc_group ) : ?>
						<span class="mvs-documents__chip"><?php echo esc_html( strtoupper( str_replace( 'odf_', '', $mvs_doc_group ) ) ); ?></span>
					<?php endif; ?>

					<span class="mvs-documents__owner">
						<?php echo esc_html( $mvs_doc_author ? $mvs_doc_author->display_name : __( 'Unknown', 'wpmediaverse' ) ); ?>
					</span>

					<span class="mvs-documents__size">
						<?php echo $mvs_doc_size > 0 ? esc_html( size_format( $mvs_doc_size ) ) : '&mdash;'; ?>
					</span>

					<time class="mvs-documents__date" datetime="<?php echo esc_attr( (string) $mvs_doc['created_at'] ); ?>">
						<?php echo esc_html( date_i18n( (string) get_option( 'date_format' ), strtotime( (string) $mvs_doc['created_at'] ) ) ); ?>
					</time>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $mvs_doc_pages > 1 ) : ?>
			<nav class="mvs-documents__pagination" aria-label="<?php esc_attr_e( 'Documents pagination', 'wpmediaverse' ); ?>">
				<?php if ( $mvs_doc_page > 1 ) : ?>
					<a class="mvs-btn mvs-btn-secondary" href="<?php echo esc_url( add_query_arg( 'doc_page', $mvs_doc_page - 1 ) ); ?>" rel="prev">
						<?php esc_html_e( 'Previous', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>

				<span class="mvs-documents__page-of">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages. */
						esc_html__( 'Page %1$s of %2$s', 'wpmediaverse' ),
						esc_html( number_format_i18n( $mvs_doc_page ) ),
						esc_html( number_format_i18n( $mvs_doc_pages ) )
					);
					?>
				</span>

				<?php if ( $mvs_doc_page < $mvs_doc_pages ) : ?>
					<a class="mvs-btn mvs-btn-secondary" href="<?php echo esc_url( add_query_arg( 'doc_page', $mvs_doc_page + 1 ) ); ?>" rel="next">
						<?php esc_html_e( 'Next', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>

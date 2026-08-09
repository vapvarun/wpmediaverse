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
 * @var string $mvs_doc_root     Active drive root, or '' for the public listing.
 * @var int    $mvs_doc_folder   Folder being viewed, 0 for a drive root.
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

/**
 * The drive — "My Drive", "Shared with me", "Recent".
 *
 * Rendered by Pro: folders, the permission ladder and grants all live there, and
 * Free showing an approximation of a permission-scoped listing would be worse
 * than Free showing none. An empty answer means the tab is not offered at all —
 * a tab that opens onto nothing is a broken promise, not a smaller feature.
 *
 * @since 2.4.0
 *
 * @param string $html Drive markup. Must be fully escaped.
 * @param string $root my-drive|shared|recent.
 * @param array  $args { @type int $folder, @type int $page }.
 */
$mvs_doc_drive_html = is_user_logged_in() && '' !== $mvs_doc_root
	? (string) apply_filters(
		'mvs_documents_drive_html',
		'',
		$mvs_doc_root,
		array(
			'folder' => $mvs_doc_folder,
			'page'   => $mvs_doc_page,
		)
	)
	: '';

// Only offer the drive tabs when something can actually render them.
$mvs_doc_has_drive = is_user_logged_in()
	&& '' !== (string) apply_filters( 'mvs_documents_drive_html', '', 'my-drive', array( 'probe' => true ) );

$mvs_doc_tabs = array( 'public' => __( 'Public', 'wpmediaverse' ) );

if ( $mvs_doc_has_drive ) {
	$mvs_doc_tabs = array(
		'my-drive' => __( 'My Drive', 'wpmediaverse' ),
		'shared'   => __( 'Shared with me', 'wpmediaverse' ),
		'recent'   => __( 'Recent', 'wpmediaverse' ),
		'public'   => __( 'Public', 'wpmediaverse' ),
	);
}

$mvs_doc_active = ( '' !== $mvs_doc_root && isset( $mvs_doc_tabs[ $mvs_doc_root ] ) ) ? $mvs_doc_root : 'public';
?>
<div class="mvs-documents mvs-page">
	<?php if ( count( $mvs_doc_tabs ) > 1 ) : ?>
		<nav class="mvs-documents__tabs" aria-label="<?php esc_attr_e( 'Document views', 'wpmediaverse' ); ?>">
			<ul>
				<?php foreach ( $mvs_doc_tabs as $mvs_doc_tab => $mvs_doc_label ) : ?>
					<li>
						<a class="mvs-documents__tab<?php echo $mvs_doc_tab === $mvs_doc_active ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( 'public' === $mvs_doc_tab ? remove_query_arg( array( 'drive', 'folder', 'doc_page' ) ) : add_query_arg( array( 'drive' => $mvs_doc_tab, 'folder' => null, 'doc_page' => null ) ) ); ?>"
							<?php echo $mvs_doc_tab === $mvs_doc_active ? ' aria-current="page"' : ''; ?>>
							<?php echo esc_html( $mvs_doc_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<?php if ( 'public' !== $mvs_doc_active ) : ?>
		<?php echo $mvs_doc_drive_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the filter contract requires escaped markup. ?>
	<?php else : ?>
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
	<?php endif; ?>
</div>

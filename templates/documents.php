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
 * @var string $mvs_doc_search      Active search term, or ''.
 * @var array  $mvs_doc_type_counts Named type => count, for the chip row.
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

?>
<div class="mvs-documents mvs-page">
	<?php
	// The same search bar and chip row Explore Media uses, with its classes, so
	// the two pages read as one product rather than two. Documents get a type
	// chip row where media gets tags — the equivalent "narrow this down"
	// control for a library where every item looks alike.
	$mvs_doc_base = remove_query_arg( array( 'doc_s', 'doc_type', 'doc_page' ) );
	?>
	<div class="mvs-explore-search">
		<form method="get" action="<?php echo esc_url( $mvs_doc_base ); ?>">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			foreach ( array( 'drive', 'folder' ) as $mvs_doc_carry ) {
				if ( isset( $_GET[ $mvs_doc_carry ] ) && '' !== $_GET[ $mvs_doc_carry ] ) {
					printf(
						'<input type="hidden" name="%s" value="%s" />',
						esc_attr( $mvs_doc_carry ),
						esc_attr( sanitize_text_field( wp_unslash( $_GET[ $mvs_doc_carry ] ) ) )
					);
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( '' !== $mvs_doc_filter ) {
				printf( '<input type="hidden" name="doc_type" value="%s" />', esc_attr( $mvs_doc_filter ) );
			}
			?>
			<div class="mvs-search-bar">
				<div class="mvs-search-field">
					<svg class="mvs-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<label for="mvs-doc-search" class="screen-reader-text"><?php esc_html_e( 'Search documents', 'wpmediaverse' ); ?></label>
					<input type="text" id="mvs-doc-search" name="doc_s"
						placeholder="<?php esc_attr_e( 'Search documents...', 'wpmediaverse' ); ?>"
						value="<?php echo esc_attr( $mvs_doc_search ); ?>" />
				</div>
			</div>
		</form>
	</div>

	<?php if ( count( $mvs_doc_type_counts ) > 1 || '' !== $mvs_doc_filter ) : ?>
	<div class="mvs-tag-cloud">
		<a class="mvs-tag-cloud-item <?php echo '' === $mvs_doc_filter ? 'active' : ''; ?>"
			href="<?php echo esc_url( add_query_arg( array_filter( array( 'doc_s' => $mvs_doc_search ) ), $mvs_doc_base ) ); ?>">
			<?php esc_html_e( 'All', 'wpmediaverse' ); ?>
		</a>
		<span class="mvs-tag-cloud-items">
			<?php
			// Only types this site HAS, commonest first. A chip built from every
			// type the plugin can store is a chip guaranteed to return nothing —
			// a site with two PDFs was offering PowerPoint, ODF Slides and RTF,
			// each one a dead end.
			//
			// The active filter is kept even at zero, so a member who followed a
			// link or narrowed to nothing still sees which chip is on and can
			// turn it off.
			$mvs_doc_chips = $mvs_doc_type_counts;

			if ( '' !== $mvs_doc_filter && ! isset( $mvs_doc_chips[ $mvs_doc_filter ] ) ) {
				$mvs_doc_chips[ $mvs_doc_filter ] = 0;
			}

			foreach ( $mvs_doc_chips as $mvs_doc_opt => $mvs_doc_count ) :
				?>
				<a class="mvs-tag-cloud-item <?php echo $mvs_doc_filter === $mvs_doc_opt ? 'active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( array_filter( array( 'doc_type' => $mvs_doc_opt, 'doc_s' => $mvs_doc_search ) ), $mvs_doc_base ) ); ?>">
					<?php echo esc_html( \WPMediaVerse\Core\DocumentTypes::label( (string) $mvs_doc_opt ) ); ?>
				</a>
			<?php endforeach; ?>
		</span>
	</div>
	<?php endif; ?>

	<?php
	// THE SAME HELPER the drive and the dashboard panels use, so this listing and
	// the member's own drive read identically. It carries the count the header
	// used to print alone — no <h1> here either: the page already supplies its
	// title, and a second heading saying "Documents" under "Explore Documents"
	// is noise a screen reader has to read twice.
	//
	// The type chips above are this surface's filter, so only sort, direction
	// and the count come from the toolbar. Both chips and search are carried as
	// hidden fields so changing the sort cannot silently drop either.
	echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_panel_toolbar( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the helper escapes every value.
		array(
			'id'     => 'mvs-documents',
			'form'   => true,
			'class'  => 'mvs-documents__controls',
			'hidden' => array_filter(
				array(
					'doc_s'    => $mvs_doc_search,
					'doc_type' => $mvs_doc_filter,
				)
			),
			'count'  => sprintf(
				/* translators: %s: number of documents. */
				_n( '%s document', '%s documents', $mvs_doc_total, 'wpmediaverse' ),
				number_format_i18n( $mvs_doc_total )
			),
			'sort'   => array(
				'name'    => 'sort',
				'label'   => __( 'Sort by', 'wpmediaverse' ),
				'value'   => isset( $mvs_doc_sort ) ? (string) $mvs_doc_sort : 'created_at',
				'options' => array(
					'created_at' => __( 'Date added', 'wpmediaverse' ),
					'title'      => __( 'Title', 'wpmediaverse' ),
					'file_size'  => __( 'Size', 'wpmediaverse' ),
				),
			),
			'order'  => array(
				'name'    => 'order',
				'label'   => __( 'Direction', 'wpmediaverse' ),
				'value'   => isset( $mvs_doc_order ) ? strtolower( (string) $mvs_doc_order ) : 'desc',
				'options' => array(
					'desc' => __( 'Newest first', 'wpmediaverse' ),
					'asc'  => __( 'Oldest first', 'wpmediaverse' ),
				),
			),
			'submit' => __( 'Apply', 'wpmediaverse' ),
		)
	);
	?>

	<?php if ( ! $mvs_doc_items ) : ?>
		<?php
		// Coding Rule 11 — a render path never falls through silently.
		echo wp_kses_post(
			$mvs_doc_helper->render_block_empty_state(
				array(
					'icon'    => 'file-text',
					'title'   => ( '' !== $mvs_doc_filter || '' !== $mvs_doc_search )
						? __( 'Nothing matches that search', 'wpmediaverse' )
						: __( 'No documents yet', 'wpmediaverse' ),
					'message' => ( '' !== $mvs_doc_filter || '' !== $mvs_doc_search )
						? __( 'Try a different word, or browse everything instead.', 'wpmediaverse' )
						: __( 'Documents shared publicly will appear here.', 'wpmediaverse' ),
					'actions' => ( '' !== $mvs_doc_filter || '' !== $mvs_doc_search )
						? array(
							array(
								'url'   => remove_query_arg( array( 'doc_type', 'doc_page', 'doc_s' ) ),
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
				$mvs_doc_group  = \WPMediaVerse\Core\DocumentTypes::group_for_mime( $mvs_doc_mime );
				// The icon map moved to DocumentTypes::icon() so the profile tab,
				// the grid tile and the activity card answer this the same way.
				$mvs_doc_icon   = \WPMediaVerse\Core\DocumentTypes::icon( $mvs_doc_group );
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
						<?php // Same type, same colour, wherever it is listed — the modifier is what lets Explore match My Drive (Basecamp 10263186054). ?>
						<span class="mvs-documents__chip mvs-documents__chip--<?php echo esc_attr( $mvs_doc_group ); ?>"><?php echo esc_html( strtoupper( str_replace( 'odf_', '', $mvs_doc_group ) ) ); ?></span>
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

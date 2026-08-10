<?php
/**
 * Template: Documents admin screen.
 *
 * Variables provided by \WPMediaVerse\Admin\DocumentListPage::render():
 *
 * @var array               $mvs_items    Document rows for this page.
 * @var int                 $mvs_total    Total documents matching the filters.
 * @var int                 $mvs_pages    Total pages.
 * @var int                 $mvs_page     Current 1-based page.
 * @var string              $mvs_doc_type Active type filter, or ''.
 * @var string              $mvs_privacy  Active privacy filter, or ''.
 * @var string              $mvs_search   Active search term, or ''.
 * @var string              $mvs_orderby  Active sort column.
 * @var string              $mvs_order    ASC|DESC.
 * @var array<int, string>  $mvs_authors  Author id => display name.
 * @var array<int, string>  $mvs_types    Known document types.
 * @var string              $mvs_error    Error message, or ''.
 * @var string              $mvs_notice   Post-action notice, or ''.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

$mvs_base_url = admin_url( 'admin.php?page=' . \WPMediaVerse\Admin\DocumentListPage::SLUG );

/**
 * Build a sort link that flips direction when it is already the active column.
 *
 * @param string $column  Column key.
 * @param string $label   Visible label.
 * @param string $active  Currently sorted column.
 * @param string $order   Current direction.
 * @param string $base    Base URL.
 * @return string
 */
$mvs_sort_link = static function ( string $column, string $label, string $active, string $order, string $base ): string {
	$next = ( $column === $active && 'DESC' === $order ) ? 'asc' : 'desc';
	$url  = add_query_arg(
		array(
			'orderby' => $column,
			'order'   => $next,
		),
		$base
	);

	$indicator = '';
	if ( $column === $active ) {
		$indicator = 'DESC' === $order ? " \u{2193}" : " \u{2191}";
	}

	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label . $indicator ) . '</a>';
};
?>
<div class="wrap mvs-admin mvs-documents-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Documents', 'wpmediaverse' ); ?></h1>

	<?php if ( '' !== $mvs_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $mvs_notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' !== $mvs_error ) : ?>
		<?php // Coding Rule 11: the error state names the problem instead of rendering an empty table that looks like "no documents". ?>
		<div class="notice notice-error"><p><?php echo esc_html( $mvs_error ); ?></p></div>
	<?php else : ?>

		<form method="get" class="mvs-documents-admin__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( \WPMediaVerse\Admin\DocumentListPage::SLUG ); ?>" />

			<label class="screen-reader-text" for="mvs-doc-search"><?php esc_html_e( 'Search documents', 'wpmediaverse' ); ?></label>
			<input type="search" id="mvs-doc-search" name="s" value="<?php echo esc_attr( $mvs_search ); ?>" placeholder="<?php esc_attr_e( 'Search by title', 'wpmediaverse' ); ?>" />

			<label class="screen-reader-text" for="mvs-doc-type"><?php esc_html_e( 'Filter by type', 'wpmediaverse' ); ?></label>
			<select id="mvs-doc-type" name="doc_type">
				<option value=""><?php esc_html_e( 'All types', 'wpmediaverse' ); ?></option>
				<?php foreach ( $mvs_types as $mvs_type ) : ?>
					<option value="<?php echo esc_attr( $mvs_type ); ?>" <?php selected( $mvs_doc_type, $mvs_type ); ?>>
						<?php echo esc_html( strtoupper( $mvs_type ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="mvs-doc-privacy"><?php esc_html_e( 'Filter by privacy', 'wpmediaverse' ); ?></label>
			<select id="mvs-doc-privacy" name="privacy">
				<option value=""><?php esc_html_e( 'All privacy levels', 'wpmediaverse' ); ?></option>
				<?php foreach ( array( 'public', 'members', 'private' ) as $mvs_level ) : ?>
					<option value="<?php echo esc_attr( $mvs_level ); ?>" <?php selected( $mvs_privacy, $mvs_level ); ?>>
						<?php echo esc_html( ucfirst( $mvs_level ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpmediaverse' ); ?></button>
		</form>

		<?php if ( ! $mvs_items ) : ?>
			<?php // Coding Rule 11: an empty result says WHY it is empty. ?>
			<div class="mvs-documents-admin__empty">
				<?php if ( '' !== $mvs_search || '' !== $mvs_doc_type || '' !== $mvs_privacy ) : ?>
					<p><?php esc_html_e( 'No documents match these filters.', 'wpmediaverse' ); ?></p>
					<a class="button" href="<?php echo esc_url( $mvs_base_url ); ?>"><?php esc_html_e( 'Clear filters', 'wpmediaverse' ); ?></a>
				<?php else : ?>
					<p><?php esc_html_e( 'No documents have been uploaded yet.', 'wpmediaverse' ); ?></p>
					<p class="description"><?php esc_html_e( 'Documents uploaded by members appear here.', 'wpmediaverse' ); ?></p>
				<?php endif; ?>
			</div>
		<?php else : ?>

			<form method="post" action="<?php echo esc_url( $mvs_base_url ); ?>">
				<?php wp_nonce_field( 'mvs_documents_bulk' ); ?>

				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label class="screen-reader-text" for="mvs-bulk-action"><?php esc_html_e( 'Bulk action', 'wpmediaverse' ); ?></label>
						<select id="mvs-bulk-action" name="bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'wpmediaverse' ); ?></option>
							<option value="trash"><?php esc_html_e( 'Move to trash', 'wpmediaverse' ); ?></option>
							<option value="restore"><?php esc_html_e( 'Restore', 'wpmediaverse' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete permanently', 'wpmediaverse' ); ?></option>
						</select>
						<button type="submit" name="do_bulk" value="1" class="button"><?php esc_html_e( 'Apply', 'wpmediaverse' ); ?></button>
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of documents. */
								esc_html( _n( '%s document', '%s documents', $mvs_total, 'wpmediaverse' ) ),
								esc_html( number_format_i18n( $mvs_total ) )
							);
							?>
						</span>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped mvs-documents-admin__table">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column">
								<label class="screen-reader-text" for="mvs-select-all"><?php esc_html_e( 'Select all', 'wpmediaverse' ); ?></label>
								<input id="mvs-select-all" type="checkbox" />
							</td>
							<th scope="col" class="manage-column column-title column-primary sortable"><?php echo wp_kses_post( $mvs_sort_link( 'title', __( 'Title', 'wpmediaverse' ), $mvs_orderby, $mvs_order, $mvs_base_url ) ); ?></th>
							<th scope="col" class="manage-column column-doctype"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
							<th scope="col" class="manage-column column-filesize sortable"><?php echo wp_kses_post( $mvs_sort_link( 'file_size', __( 'Size', 'wpmediaverse' ), $mvs_orderby, $mvs_order, $mvs_base_url ) ); ?></th>
							<th scope="col" class="manage-column column-author"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
							<th scope="col" class="manage-column column-privacy"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></th>
							<th scope="col" class="manage-column column-uploaded sortable"><?php echo wp_kses_post( $mvs_sort_link( 'created_at', __( 'Uploaded', 'wpmediaverse' ), $mvs_orderby, $mvs_order, $mvs_base_url ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $mvs_items as $mvs_row ) :
							$mvs_id        = (int) $mvs_row['media_id'];
							$mvs_type      = \WPMediaVerse\Core\DocumentTypes::group_for_mime( (string) $mvs_row['file_type'] );
							$mvs_trashed   = 'trash' === (string) $mvs_row['status'];
							$mvs_permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $mvs_id );
							?>
							<tr>
								<th scope="row" class="check-column">
									<label class="screen-reader-text" for="mvs-doc-<?php echo esc_attr( (string) $mvs_id ); ?>">
										<?php
										printf(
											/* translators: %s: document title. */
											esc_html__( 'Select %s', 'wpmediaverse' ),
											esc_html( (string) $mvs_row['title'] )
										);
										?>
									</label>
									<input id="mvs-doc-<?php echo esc_attr( (string) $mvs_id ); ?>" type="checkbox" name="document[]" value="<?php echo esc_attr( (string) $mvs_id ); ?>" />
								</th>
								<td class="column-title column-primary" data-colname="<?php esc_attr_e( 'Title', 'wpmediaverse' ); ?>">
									<?php
									// The title opens the EDIT screen, the way it
									// does in All Posts. It used to leave for the
									// front end, which is the one thing an admin
									// does not expect a title to do.
									$mvs_edit_url = add_query_arg(
										array(
											'page'     => \WPMediaVerse\Admin\DocumentListPage::SLUG,
											'view'     => 'single',
											'media_id' => $mvs_id,
										),
										admin_url( 'admin.php' )
									);
									?>
									<strong>
										<a href="<?php echo esc_url( $mvs_edit_url ); ?>"><?php echo esc_html( (string) $mvs_row['title'] ); ?></a>
									</strong>
									<?php if ( $mvs_trashed ) : ?>
										<span class="mvs-documents-admin__badge"><?php esc_html_e( 'Trashed', 'wpmediaverse' ); ?></span>
									<?php endif; ?>
									<div class="row-actions">
										<span class="edit">
											<a href="<?php echo esc_url( $mvs_edit_url ); ?>"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></a> |
										</span>
										<?php
										// Not offered for a trashed document:
										// delivery refuses a non-publish row, so
										// the link would 404 for its own owner.
										?>
										<?php if ( $mvs_permalink ) : ?>
											<span class="view">
												<a href="<?php echo esc_url( $mvs_permalink ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on site', 'wpmediaverse' ); ?></a> |
											</span>
										<?php endif; ?>
										<?php
										/**
										 * Filters extra row actions on the documents list.
										 *
										 * Pro adds what only Pro can serve, such as a
										 * download, without Free depending on Pro.
										 *
										 * @since 2.4.0
										 *
										 * @param string $html     Markup, already escaped.
										 * @param int    $media_id Document id.
										 * @param bool   $trashed  Whether it is in the trash.
										 */
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										echo apply_filters( 'mvs_document_row_actions', '', $mvs_id, $mvs_trashed );
										?>
										<?php if ( $mvs_trashed ) : ?>
											<span class="restore">
												<a href="
												<?php
												echo esc_url(
													wp_nonce_url(
														add_query_arg(
															array(
																'page' => \WPMediaVerse\Admin\DocumentListPage::SLUG,
																'action' => 'restore',
																'media_id' => $mvs_id,
															),
															admin_url( 'admin.php' )
														),
														'mvs_restore_document_' . $mvs_id
													)
												);
												?>
															">
													<?php esc_html_e( 'Restore', 'wpmediaverse' ); ?>
												</a> |
											</span>
										<?php else : ?>
											<span class="trash">
												<a href="
												<?php
												echo esc_url(
													wp_nonce_url(
														add_query_arg(
															array(
																'page' => \WPMediaVerse\Admin\DocumentListPage::SLUG,
																'action' => 'trash',
																'media_id' => $mvs_id,
															),
															admin_url( 'admin.php' )
														),
														'mvs_trash_document_' . $mvs_id
													)
												);
												?>
															">
													<?php esc_html_e( 'Trash', 'wpmediaverse' ); ?>
												</a> |
											</span>
										<?php endif; ?>
										<span class="delete">
											<a data-mvs-confirm="<?php esc_attr_e( 'Delete this document permanently? This cannot be undone.', 'wpmediaverse' ); ?>"
												href="
												<?php
												echo esc_url(
													wp_nonce_url(
														add_query_arg(
															array(
																'page' => \WPMediaVerse\Admin\DocumentListPage::SLUG,
																'action' => 'delete',
																'media_id' => $mvs_id,
															),
															admin_url( 'admin.php' )
														),
														'mvs_delete_document_' . $mvs_id
													)
												);
												?>
														">
												<?php esc_html_e( 'Delete permanently', 'wpmediaverse' ); ?>
											</a>
										</span>
									</div>
									<button type="button" class="toggle-row">
										<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'wpmediaverse' ); ?></span>
									</button>
								</td>
								<td class="column-doctype" data-colname="<?php esc_attr_e( 'Type', 'wpmediaverse' ); ?>"><?php echo esc_html( $mvs_type ? strtoupper( $mvs_type ) : __( 'Unknown', 'wpmediaverse' ) ); ?></td>
								<td class="column-filesize" data-colname="<?php esc_attr_e( 'Size', 'wpmediaverse' ); ?>"><?php echo esc_html( $mvs_row['file_size'] ? size_format( (int) $mvs_row['file_size'] ) : '—' ); ?></td>
								<td class="column-author" data-colname="<?php esc_attr_e( 'Author', 'wpmediaverse' ); ?>"><?php echo esc_html( $mvs_authors[ (int) $mvs_row['post_author'] ] ?? __( 'Unknown', 'wpmediaverse' ) ); ?></td>
								<td class="column-privacy" data-colname="<?php esc_attr_e( 'Privacy', 'wpmediaverse' ); ?>"><?php echo esc_html( ucfirst( (string) $mvs_row['privacy'] ) ); ?></td>
								<td class="column-uploaded" data-colname="<?php esc_attr_e( 'Uploaded', 'wpmediaverse' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) $mvs_row['created_at'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<?php if ( $mvs_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $mvs_base_url ),
									'format'    => '',
									'current'   => $mvs_page,
									'total'     => $mvs_pages,
									'prev_text' => __( '&laquo; Previous', 'wpmediaverse' ),
									'next_text' => __( 'Next &raquo;', 'wpmediaverse' ),
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	<?php endif; ?>
</div>

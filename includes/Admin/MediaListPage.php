<?php
/**
 * Admin media listing page.
 *
 * Custom admin page replacing the CPT edit.php listing.
 * Queries mvs_media_index directly — no wp_posts dependency.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\Plugin;

/**
 * Renders the All Media admin page with filtering, search, and bulk actions.
 */
class MediaListPage {

	/**
	 * Render the media listing page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'upload_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// Handle bulk actions.
		self::handle_bulk_actions();

		// Bulk-action success notice (shown after the redirect from
		// handle_bulk_action_apply()).
		if ( isset( $_GET['mvs_bulk_done'], $_GET['mvs_bulk_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$done   = absint( $_GET['mvs_bulk_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_text_field( wp_unslash( $_GET['mvs_bulk_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$labels = array(
				'bulk_trash'   => /* translators: %d: count */ _n( '%d item moved to Trash.', '%d items moved to Trash.', $done, 'wpmediaverse' ),
				'bulk_restore' => /* translators: %d: count */ _n( '%d item restored.', '%d items restored.', $done, 'wpmediaverse' ),
				'bulk_delete'  => /* translators: %d: count */ _n( '%d item permanently deleted.', '%d items permanently deleted.', $done, 'wpmediaverse' ),
			);
			if ( isset( $labels[ $action ] ) && $done > 0 ) {
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( $labels[ $action ], $done ) ) );
			}
		}

		global $wpdb;

		$table = $wpdb->prefix . 'mvs_media_index';

		// Filters.
		$search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$type_filter    = isset( $_GET['media_type'] ) ? sanitize_text_field( wp_unslash( $_GET['media_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$status_filter  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$privacy_filter = isset( $_GET['privacy'] ) ? sanitize_text_field( wp_unslash( $_GET['privacy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Pagination.
		$per_page = 20;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $paged - 1 ) * $per_page;

		// Build WHERE clause.
		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		if ( $type_filter ) {
			$where[]  = 'media_type = %s';
			$params[] = $type_filter;
		}

		if ( $status_filter ) {
			$where[]  = 'status = %s';
			$params[] = $status_filter;
		} else {
			$where[] = "status != 'trash'";
		}

		if ( $privacy_filter ) {
			$where[]  = 'privacy = %s';
			$params[] = $privacy_filter;
		}

		$where_sql = implode( ' AND ', $where );

		// Count total.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $params ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		$total_pages = (int) ceil( $total / $per_page );

		// Fetch rows.
		$orderby    = 'created_at';
		$order      = 'DESC';
		$query      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		$items      = $wpdb->get_results( $wpdb->prepare( $query, ...$all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		// Status counts for tabs.
		$status_counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
			OBJECT_K
		);

		$base_url = admin_url( 'admin.php?page=mvs-media' );
		?>
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="images"></i>
						<?php esc_html_e( 'All Media', 'wpmediaverse' ); ?>
					</h1>
					<p class="mvs-page-header__desc"><?php esc_html_e( 'Manage all uploaded media across your community.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<?php self::render_status_tabs( $status_counts, $status_filter, $base_url ); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="mvs-media" />
				<?php if ( $status_filter ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>" />
				<?php endif; ?>

				<div class="mvs-admin-widget">
					<div class="mvs-widget-header mvs-widget-header--toolbar">
						<div class="alignleft actions bulkactions">
							<label for="mvs-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'wpmediaverse' ); ?></label>
							<select name="bulk_action" id="mvs-bulk-action">
								<option value=""><?php esc_html_e( 'Bulk actions', 'wpmediaverse' ); ?></option>
								<?php if ( 'trash' === $status_filter ) : ?>
									<option value="bulk_restore"><?php esc_html_e( 'Restore', 'wpmediaverse' ); ?></option>
									<option value="bulk_delete"><?php esc_html_e( 'Delete permanently', 'wpmediaverse' ); ?></option>
								<?php else : ?>
									<option value="bulk_trash"><?php esc_html_e( 'Move to Trash', 'wpmediaverse' ); ?></option>
								<?php endif; ?>
							</select>
							<?php submit_button( __( 'Apply', 'wpmediaverse' ), 'action', 'do_bulk', false, array( 'id' => 'mvs-do-bulk' ) ); ?>
							<?php wp_nonce_field( 'mvs_bulk_media', 'mvs_bulk_nonce' ); ?>
						</div>
						<div class="alignleft actions">
							<select name="media_type">
								<option value=""><?php esc_html_e( 'All Types', 'wpmediaverse' ); ?></option>
								<?php foreach ( array( 'image', 'video', 'audio', 'document' ) as $t ) : ?>
									<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type_filter, $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="privacy">
								<option value=""><?php esc_html_e( 'All Privacy', 'wpmediaverse' ); ?></option>
								<?php foreach ( array( 'public', 'members', 'private', 'friends', 'group' ) as $p ) : ?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $privacy_filter, $p ); ?>><?php echo esc_html( ucfirst( $p ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Filter', 'wpmediaverse' ), '', 'filter_action', false ); ?>
						</div>

						<p class="search-box">
							<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>" />
							<?php submit_button( __( 'Search', 'wpmediaverse' ), '', '', false ); ?>
						</p>

						<?php self::render_pagination( $total, $total_pages, $paged ); ?>
					</div>

					<div class="mvs-widget-body mvs-widget-body--flush">
						<table class="wp-list-table widefat fixed striped table-view-list">
							<thead>
								<tr>
									<td class="manage-column column-cb check-column"><input type="checkbox" class="mvs-cb-select-all" aria-label="<?php esc_attr_e( 'Select all media', 'wpmediaverse' ); ?>" /></td>
									<th class="manage-column mvs-col-id"><?php esc_html_e( 'ID', 'wpmediaverse' ); ?></th>
									<th class="manage-column mvs-col-thumb"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
									<th class="manage-column column-primary"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Status', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( empty( $items ) ) : ?>
									<?php
									$has_active_filters = ! empty( $search ) || ! empty( $type_filter ) || ! empty( $privacy_filter );
									$base_url           = admin_url( 'admin.php?page=mvs-media' );
									?>
									<tr>
										<td colspan="8">
											<div class="mvs-empty-state-admin">
												<i data-lucide="images"></i>
												<?php if ( $has_active_filters ) : ?>
													<h3><?php esc_html_e( 'No media matches your filters', 'wpmediaverse' ); ?></h3>
													<p>
														<?php esc_html_e( 'Try a different search term or a different type / privacy filter.', 'wpmediaverse' ); ?>
													</p>
													<p>
														<a class="button" href="<?php echo esc_url( $base_url ); ?>">
															<?php esc_html_e( 'Clear filters', 'wpmediaverse' ); ?>
														</a>
													</p>
												<?php else : ?>
													<h3><?php esc_html_e( 'No media yet', 'wpmediaverse' ); ?></h3>
													<p><?php esc_html_e( 'Once users upload media it will appear here.', 'wpmediaverse' ); ?></p>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $items as $item ) : ?>
										<?php self::render_row( $item ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
							<tfoot>
								<tr>
									<td class="manage-column column-cb check-column"><input type="checkbox" class="mvs-cb-select-all" aria-label="<?php esc_attr_e( 'Select all media', 'wpmediaverse' ); ?>" /></td>
									<th class="manage-column mvs-col-id"><?php esc_html_e( 'ID', 'wpmediaverse' ); ?></th>
									<th class="manage-column mvs-col-thumb"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
									<th class="manage-column column-primary"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Status', 'wpmediaverse' ); ?></th>
									<th class="manage-column"><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
								</tr>
							</tfoot>
						</table>
					</div>

					<div class="mvs-widget-footer">
						<?php self::render_pagination( $total, $total_pages, $paged ); ?>
					</div>
				</div>
			</form>
			<script>
			// Bulk select-all toggle (header + footer checkboxes both
			// trigger the same all-toggle on the column-cb row inputs).
			// Uses event delegation so it works for both thead + tfoot
			// without binding twice.
			( function () {
				var rowSelector = 'input[type="checkbox"][name="media_ids[]"]';
				document.addEventListener( 'change', function ( event ) {
					if ( event.target && event.target.classList && event.target.classList.contains( 'mvs-cb-select-all' ) ) {
						var checked = event.target.checked;
						document.querySelectorAll( rowSelector ).forEach( function ( cb ) { cb.checked = checked; } );
						document.querySelectorAll( '.mvs-cb-select-all' ).forEach( function ( cb ) { cb.checked = checked; } );
					}
				} );

				// Confirm before bulk delete (permanent).
				var bulkForm = document.getElementById( 'mvs-do-bulk' );
				if ( bulkForm ) {
					bulkForm.closest( 'form' ).addEventListener( 'submit', function ( event ) {
						var sel = document.getElementById( 'mvs-bulk-action' );
						if ( ! sel || sel.value !== 'bulk_delete' ) return;
						var checked = document.querySelectorAll( rowSelector + ':checked' );
						if ( checked.length === 0 ) {
							event.preventDefault();
							alert( '<?php echo esc_js( __( 'No media selected.', 'wpmediaverse' ) ); ?>' );
							return;
						}
						if ( ! confirm( <?php echo wp_json_encode( __( 'Permanently delete the selected media? This cannot be undone.', 'wpmediaverse' ) ); ?> ) ) {
							event.preventDefault();
						}
					} );
				}
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Render status filter tabs.
	 *
	 * @param object[] $status_counts Status counts keyed by status.
	 * @param string   $current       Current status filter.
	 * @param string   $base_url      Base page URL.
	 */
	private static function render_status_tabs( $status_counts, string $current, string $base_url ): void {
		$all_count = 0;
		foreach ( $status_counts as $s ) {
			if ( 'trash' !== $s->status ) {
				$all_count += (int) $s->cnt;
			}
		}

		$statuses = array(
			''        => array( __( 'All', 'wpmediaverse' ), $all_count, 'mvs-stat-card--accent' ),
			'publish' => array( __( 'Published', 'wpmediaverse' ), (int) ( $status_counts['publish']->cnt ?? 0 ), 'mvs-stat-card--success' ),
			'pending' => array( __( 'Pending', 'wpmediaverse' ), (int) ( $status_counts['pending']->cnt ?? 0 ), 'mvs-stat-card--warning' ),
			'draft'   => array( __( 'Draft', 'wpmediaverse' ), (int) ( $status_counts['draft']->cnt ?? 0 ), '' ),
			'trash'   => array( __( 'Trash', 'wpmediaverse' ), (int) ( $status_counts['trash']->cnt ?? 0 ), 'mvs-stat-card--danger' ),
		);
		?>
		<div class="mvs-admin-stats">
			<?php foreach ( $statuses as $key => $info ) : ?>
				<?php
				if ( 0 === $info[1] && '' !== $key ) {
					continue;
				}
				$url     = $key ? add_query_arg( 'status', $key, $base_url ) : $base_url;
				$variant = ( $current === $key ) ? $info[2] : '';
				$classes = 'mvs-stat-card' . ( $variant ? ' ' . $variant : '' );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $classes ); ?>">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $info[1] ) ); ?></span>
					<span class="mvs-stat-label"><?php echo esc_html( $info[0] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single media row.
	 *
	 * @param array $item Media index row.
	 */
	private static function render_row( array $item ): void {
		$media_id = (int) $item['media_id'];
		$title    = $item['title'] ?: __( '(no title)', 'wpmediaverse' );
		$type     = $item['media_type'] ?: 'image';
		$privacy  = $item['privacy'] ?: 'public';
		$status   = $item['status'] ?: 'publish';
		$file_url = $item['file_url'] ?? '';
		$author   = get_userdata( (int) $item['post_author'] );

		$view_url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
		?>
		<tr>
			<th scope="row" class="check-column"><input type="checkbox" name="media_ids[]" value="<?php echo esc_attr( $media_id ); ?>" /></th>
			<td class="mvs-col-id"><?php echo absint( $media_id ); ?></td>
			<td class="mvs-col-thumb">
				<?php
				$ml_su     = Plugin::container()->get( 'signed_urls' );
				$thumb_url = $ml_su
					? $ml_su->generate_thumbnail( $media_id, get_current_user_id(), 'thumbnail', 0, true )
					: \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $media_id, 'thumbnail' );
				?>
				<?php if ( $thumb_url ) : ?>
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" class="mvs-thumb" loading="lazy" />
				<?php else : ?>
					<?php
					$icons = array(
						'video'    => 'video',
						'audio'    => 'music',
						'document' => 'file-text',
						'image'    => 'image',
					);
					$icon  = $icons[ $type ] ?? 'file';
					?>
					<span class="mvs-thumb-placeholder"><i data-lucide="<?php echo esc_attr( $icon ); ?>"></i></span>
				<?php endif; ?>
			</td>
			<td class="column-primary">
				<strong><a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php echo esc_html( $title ); ?></a></strong>
				<div class="row-actions">
					<span class="view"><a href="<?php echo esc_url( $view_url ); ?>" target="_blank"><?php esc_html_e( 'View', 'wpmediaverse' ); ?></a></span>
					<?php if ( 'trash' !== $status ) : ?>
						| <span class="trash"><a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'   => 'trash',
										'media_id' => $media_id,
									),
									admin_url( 'admin.php?page=mvs-media' )
								),
								'mvs_trash_media_' . $media_id
							)
						);
						?>
														" class="submitdelete"><?php esc_html_e( 'Trash', 'wpmediaverse' ); ?></a></span>
					<?php else : ?>
						| <span class="untrash"><a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'   => 'restore',
										'media_id' => $media_id,
									),
									admin_url( 'admin.php?page=mvs-media' )
								),
								'mvs_restore_media_' . $media_id
							)
						);
						?>
															"><?php esc_html_e( 'Restore', 'wpmediaverse' ); ?></a></span>
						| <span class="delete"><a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'   => 'delete',
										'media_id' => $media_id,
									),
									admin_url( 'admin.php?page=mvs-media' )
								),
								'mvs_delete_media_' . $media_id
							)
						);
						?>
														" class="submitdelete" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete this media? This cannot be undone.', 'wpmediaverse' ); ?>')"><?php esc_html_e( 'Delete Permanently', 'wpmediaverse' ); ?></a></span>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></span></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $privacy ); ?>"><?php echo esc_html( ucfirst( $privacy ) ); ?></span></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item['created_at'] ) ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render pagination controls.
	 *
	 * @param int $total       Total items.
	 * @param int $total_pages Total pages.
	 * @param int $paged       Current page.
	 */
	private static function render_pagination( int $total, int $total_pages, int $paged ): void {
		if ( $total_pages <= 1 ) {
			echo '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html(
				sprintf(
				/* translators: %s: number of items */
					_n( '%s item', '%s items', $total, 'wpmediaverse' ),
					number_format_i18n( $total )
				)
			) . '</span></div>';
			return;
		}

		$page_links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $total_pages,
				'current'   => $paged,
				'type'      => 'array',
			)
		);

		echo '<div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
			/* translators: %s: number of items */
				_n( '%s item', '%s items', $total, 'wpmediaverse' ),
				number_format_i18n( $total )
			)
		) . '</span>';
		echo '<span class="pagination-links">' . implode( "\n", $page_links ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links returns safe HTML
		echo '</div>';
	}

	/**
	 * Handle single-item and bulk actions.
	 */
	private static function handle_bulk_actions(): void {
		global $wpdb;

		// Bulk path — new in 1.2.0. Toolbar dropdown + checkboxes; the
		// "Apply" submit button is named "do_bulk" so we know it was a
		// bulk submission (vs a Filter submit which has its own button).
		if ( isset( $_GET['do_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::handle_bulk_action_apply();
			return;
		}

		// Single-item legacy path — row-action links pass action+media_id
		// via GET with their own per-row nonce.
		$action   = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$media_id = isset( $_GET['media_id'] ) ? (int) $_GET['media_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $action || ! $media_id ) {
			return;
		}

		$table = $wpdb->prefix . 'mvs_media_index';

		switch ( $action ) {
			case 'trash':
				check_admin_referer( 'mvs_trash_media_' . $media_id );
				$wpdb->update( $table, array( 'status' => 'trash' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;

			case 'restore':
				check_admin_referer( 'mvs_restore_media_' . $media_id );
				$wpdb->update( $table, array( 'status' => 'publish' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;

			case 'delete':
				check_admin_referer( 'mvs_delete_media_' . $media_id );
				self::permanently_delete_media( $media_id );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
		exit;
	}

	/**
	 * Apply a bulk action submitted from the Media list toolbar.
	 *
	 * Validates capability + nonce, then dispatches to per-action handlers.
	 * Allowed actions: bulk_trash | bulk_restore | bulk_delete.
	 */
	private static function handle_bulk_action_apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform bulk actions.', 'wpmediaverse' ) );
		}
		check_admin_referer( 'mvs_bulk_media', 'mvs_bulk_nonce' );

		$action = isset( $_GET['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_GET['bulk_action'] ) ) : '';
		$ids    = isset( $_GET['media_ids'] ) ? array_map( 'absint', (array) $_GET['media_ids'] ) : array();
		$ids    = array_filter( array_unique( $ids ) );

		if ( ! $action || empty( $ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';

		$count = 0;
		foreach ( $ids as $media_id ) {
			$media_id = (int) $media_id;
			if ( $media_id <= 0 ) {
				continue;
			}

			switch ( $action ) {
				case 'bulk_trash':
					$ok = $wpdb->update( $table, array( 'status' => 'trash' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( false !== $ok ) {
						++$count;
					}
					break;

				case 'bulk_restore':
					$ok = $wpdb->update( $table, array( 'status' => 'publish' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( false !== $ok ) {
						++$count;
					}
					break;

				case 'bulk_delete':
					self::permanently_delete_media( $media_id );
					++$count;
					break;
			}
		}

		// Add a query arg so render() can show a success notice on redirect.
		$redirect = add_query_arg(
			array(
				'page'            => 'mvs-media',
				'mvs_bulk_done'   => $count,
				'mvs_bulk_action' => $action,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Delete a single media item from every related custom table. Shared
	 * between the single-item delete path and the bulk-delete loop so
	 * neither drifts away from the other on schema changes.
	 *
	 * @param int $media_id Media ID.
	 */
	private static function permanently_delete_media( int $media_id ): void {
		global $wpdb;
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_all( $media_id );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_favorites', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

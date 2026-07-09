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

		wp_enqueue_script(
			'mvs-admin-media-list',
			MVS_PLUGIN_URL . 'assets/js/admin/media-list.js',
			array( 'mvs-admin-confirm', 'mvs-toast' ),
			MVS_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script(
			'mvs-admin-media-list',
			'mvsMediaList',
			array(
				'i18n' => array(
					'noMedia'       => __( 'No media selected.', 'wpmediaverse' ),
					'confirmDelete' => __( 'Permanently delete the selected media? This cannot be undone.', 'wpmediaverse' ),
				),
			)
		);

		// Row/bulk actions (and their redirects) run on the page's `load-` hook
		// in Plugin::register_admin_menu(), before any output. render() only
		// displays here.

		// Detail mini-page — read-only view + per-image actions.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'details' === $view ) {
			self::render_detail();
			return;
		}

		// AI Review mini-page — view/edit/accept/reject/re-run AI results.
		if ( 'ai-review' === $view ) {
			self::render_ai_review();
			return;
		}

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

		// Per-row repair-thumb notice.
		$repair_notice = get_transient( 'mvs_repair_thumb_notice' );
		if ( is_array( $repair_notice ) && ! empty( $repair_notice['message'] ) ) {
			delete_transient( 'mvs_repair_thumb_notice' );
			$class = 'success' === ( $repair_notice['type'] ?? '' ) ? 'notice-success' : 'notice-warning';
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $class ),
				esc_html( (string) $repair_notice['message'] )
			);
		}

		// Per-row optimize notice.
		$optimize_notice = get_transient( 'mvs_optimize_notice' );
		if ( is_array( $optimize_notice ) && ! empty( $optimize_notice['message'] ) ) {
			delete_transient( 'mvs_optimize_notice' );
			$class = 'success' === ( $optimize_notice['type'] ?? '' ) ? 'notice-success' : 'notice-warning';
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $class ),
				esc_html( (string) $optimize_notice['message'] )
			);
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
									<th class="manage-column mvs-col-optimization"><?php esc_html_e( 'Optimization', 'wpmediaverse' ); ?></th>
									<th class="manage-column mvs-col-ai"><?php esc_html_e( 'AI', 'wpmediaverse' ); ?></th>
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
										<td colspan="10">
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
									<th class="manage-column mvs-col-optimization"><?php esc_html_e( 'Optimization', 'wpmediaverse' ); ?></th>
									<th class="manage-column mvs-col-ai"><?php esc_html_e( 'AI', 'wpmediaverse' ); ?></th>
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
					<?php
					$details_url = add_query_arg(
						array(
							'page'     => 'mvs-media',
							'view'     => 'details',
							'media_id' => $media_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					| <span class="details"><a href="<?php echo esc_url( $details_url ); ?>"><?php esc_html_e( 'Details', 'wpmediaverse' ); ?></a></span>
					<?php
					$ai_review_url = add_query_arg(
						array(
							'page'     => 'mvs-media',
							'view'     => 'ai-review',
							'media_id' => $media_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					| <span class="ai-review"><a href="<?php echo esc_url( $ai_review_url ); ?>"><?php esc_html_e( 'AI Review', 'wpmediaverse' ); ?></a></span>
					<?php if ( 'trash' !== $status && 0 === strpos( (string) ( $item['file_type'] ?? '' ), 'image/' ) ) : ?>
						| <span class="optimize"><a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'   => 'optimize',
										'media_id' => $media_id,
									),
									admin_url( 'admin.php?page=mvs-media' )
								),
								'mvs_optimize_media_' . $media_id
							)
						);
						?>
																					" title="<?php esc_attr_e( 'Re-encode this image, strip metadata, and emit a WebP sibling.', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Optimize', 'wpmediaverse' ); ?></a></span>
					<?php endif; ?>
					<?php if ( 'trash' !== $status && self::can_repair_thumb( $media_id, (string) ( $item['file_type'] ?? '' ) ) ) : ?>
						| <span class="repair-thumb"><a href="
						<?php
						echo esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'action'   => 'repair_thumb',
										'media_id' => $media_id,
									),
									admin_url( 'admin.php?page=mvs-media' )
								),
								'mvs_repair_thumb_' . $media_id
							)
						);
						?>
															" title="<?php esc_attr_e( 'Regenerate the thumbnail for this media from the original. Use this when the grid shows a broken image.', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Repair thumb', 'wpmediaverse' ); ?></a></span>
					<?php endif; ?>
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
														" class="submitdelete" data-mvs-confirm="<?php esc_attr_e( 'Are you sure you want to permanently delete this media? This cannot be undone.', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Delete Permanently', 'wpmediaverse' ); ?></a></span>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></span></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $privacy ); ?>"><?php echo esc_html( ucfirst( $privacy ) ); ?></span></td>
			<td><span class="mvs-media-badge mvs-media-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			<td><?php self::render_optimization_cell( $media_id, (string) ( $item['file_type'] ?? '' ) ); ?></td>
			<td class="mvs-col-ai"><?php self::render_ai_cell( $media_id ); ?></td>
			<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item['created_at'] ) ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Render the Optimization column cell for one media row.
	 */
	private static function render_optimization_cell( int $media_id, string $mime ): void {
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			echo '<span class="mvs-media-badge mvs-media-badge--neutral">' . esc_html__( 'N/A', 'wpmediaverse' ) . '</span>';
			return;
		}

		$repo         = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$optimized_at = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT );
		$failed_code  = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZE_FAILED );

		if ( '' !== $failed_code ) {
			printf(
				'<span class="mvs-media-badge mvs-media-badge--danger" title="%s">%s</span>',
				esc_attr( $failed_code ),
				esc_html__( 'Failed', 'wpmediaverse' )
			);
			return;
		}

		if ( 0 === $optimized_at ) {
			echo '<span class="mvs-media-badge mvs-media-badge--draft">' . esc_html__( 'Not optimized', 'wpmediaverse' ) . '</span>';
			return;
		}

		$before = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_BEFORE );
		$after  = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_AFTER );
		$webp   = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP );

		$saved_pct = ( $before > 0 ) ? round( ( $before - $after ) / $before * 100, 1 ) : 0;

		// Pick badge style + label by outcome:
		// 1. JPEG shrunk -> green badge with savings %
		// 2. No JPEG savings but a WebP copy was created -> success "WebP ready"
		// 3. No savings, no WebP -> neutral "No size gain". We deliberately
		// avoid the word "Optimized" here because an admin staring at a 3 MB
		// file would (correctly) push back on that claim.
		if ( $saved_pct > 0 ) {
			$badge_class = 'mvs-media-badge--success';
			$badge_label = '-' . $saved_pct . '%';
		} elseif ( '' !== $webp ) {
			$badge_class = 'mvs-media-badge--success';
			$badge_label = __( 'WebP ready', 'wpmediaverse' );
		} else {
			$badge_class = 'mvs-media-badge--neutral';
			$badge_label = __( 'No size gain', 'wpmediaverse' );
		}

		$title = sprintf(
			/* translators: 1: original file size, 2: optimized file size, 3: webp variant availability */
			__( 'Original %1$s, optimized %2$s. WebP variant: %3$s.', 'wpmediaverse' ),
			$before > 0 ? size_format( $before ) : '-',
			$after > 0 ? size_format( $after ) : '-',
			'' !== $webp ? __( 'available', 'wpmediaverse' ) : __( 'not generated', 'wpmediaverse' )
		);

		printf(
			'<span class="mvs-media-badge %s" title="%s">%s</span>',
			esc_attr( $badge_class ),
			esc_attr( $title ),
			esc_html( $badge_label )
		);
	}

	/**
	 * Map a raw ai_status meta value to a human label + badge class.
	 *
	 * Shared by the AI column cell and the AI Review mini-page so the two
	 * surfaces never drift. Unknown / empty status renders as a neutral dash.
	 *
	 * @param string $ai_status Raw ai_status meta value.
	 * @return array{label: string, class: string}
	 */
	private static function ai_status_badge( string $ai_status ): array {
		switch ( $ai_status ) {
			case 'processing':
				return array(
					'label' => __( 'Processing', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--warning',
				);
			case 'complete':
				return array(
					'label' => __( 'Complete', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--success',
				);
			case 'accepted':
				return array(
					'label' => __( 'Accepted', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--success',
				);
			case 'rejected':
				return array(
					'label' => __( 'Rejected', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--neutral',
				);
			case 'failed':
				return array(
					'label' => __( 'Failed', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--danger',
				);
			case 'pending':
				return array(
					'label' => __( 'Pending', 'wpmediaverse' ),
					'class' => 'mvs-media-badge--draft',
				);
			default:
				return array(
					'label' => '—',
					'class' => 'mvs-media-badge--neutral',
				);
		}
	}

	/**
	 * Decode the stored ai_tags meta into a flat string array.
	 *
	 * The ai_tags value is written by AIService::auto_tag() as a JSON-encoded
	 * array, but may also be a legacy plain string. Returns an empty array
	 * when absent.
	 *
	 * @param mixed $raw Raw ai_tags meta value.
	 * @return string[]
	 */
	private static function decode_ai_tags( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'strval', $raw ) ) );
		}
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}
		// Legacy / comma-separated fallback.
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Render the AI column cell for one media row.
	 *
	 * Shows the ai_status badge and, when a description exists, a truncated
	 * preview so an admin can scan results without opening each item.
	 *
	 * @param int $media_id Media ID.
	 */
	private static function render_ai_cell( int $media_id ): void {
		$repo        = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$ai_status   = (string) $repo->get_raw( $media_id, 'ai_status' );
		$description = (string) $repo->get_raw( $media_id, 'ai_description' );
		$badge       = self::ai_status_badge( $ai_status );

		printf(
			'<span class="mvs-media-badge %s">%s</span>',
			esc_attr( $badge['class'] ),
			esc_html( $badge['label'] )
		);

		if ( '' !== $description ) {
			$preview = wp_trim_words( $description, 12, '…' );
			printf(
				'<span class="mvs-ai-preview" title="%s"><br />%s</span>',
				esc_attr( $description ),
				esc_html( $preview )
			);
		}
	}

	/**
	 * Render the AI Review mini-page for a single media item.
	 *
	 * Reachable via ?page=mvs-media&view=ai-review&media_id=X. Prepares and
	 * escapes all data, then hands off to templates/admin/ai-review.php for
	 * the markup (Coding Rule #4: Admin HTML lives in template files).
	 *
	 * Write actions (Accept / Reject / Re-run) are submitted from the template
	 * and processed in handle_bulk_actions(); each carries its own nonce and is
	 * gated by an inline capability check there.
	 */
	private static function render_ai_review(): void {
		$media_id = isset( $_GET['media_id'] ) ? (int) $_GET['media_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $media_id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
			exit;
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		if ( ! $repo || ! $repo->exists( $media_id ) ) {
			?>
			<div class="wrap wpmediaverse-admin">
				<h1><?php esc_html_e( 'Media not found', 'wpmediaverse' ); ?></h1>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>"><?php esc_html_e( 'Back to all media', 'wpmediaverse' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$ai_status   = (string) $repo->get_raw( $media_id, 'ai_status' );
		$description = (string) $repo->get_raw( $media_id, 'ai_description' );
		$confidence  = (float) $repo->get_raw( $media_id, 'ai_confidence' );
		$tags        = self::decode_ai_tags( $repo->get_raw( $media_id, 'ai_tags' ) );
		$badge       = self::ai_status_badge( $ai_status );
		$reviewed    = in_array( $ai_status, array( 'accepted', 'rejected' ), true );

		$su        = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
		$thumb_url = $su ? (string) $su->generate_thumbnail( $media_id, get_current_user_id(), 'thumbnail', 0, true ) : '';

		// Template variables (consumed by templates/admin/ai-review.php).
		$title        = (string) $repo->get( $media_id, 'title' );
		$tags_text    = implode( ', ', $tags );
		$status_label = $badge['label'];
		$status_class = $badge['class'];
		$list_url     = admin_url( 'admin.php?page=mvs-media' );

		$accept_url   = add_query_arg(
			array(
				'page'     => 'mvs-media',
				'view'     => 'ai-review',
				'action'   => 'ai_accept',
				'media_id' => $media_id,
			),
			admin_url( 'admin.php' )
		);
		$reject_url   = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'mvs-media',
					'view'     => 'ai-review',
					'action'   => 'ai_reject',
					'media_id' => $media_id,
				),
				admin_url( 'admin.php' )
			),
			'mvs_ai_reject_' . $media_id
		);
		$rerun_url    = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'mvs-media',
					'view'     => 'ai-review',
					'action'   => 'ai_rerun',
					'media_id' => $media_id,
				),
				admin_url( 'admin.php' )
			),
			'mvs_ai_rerun_' . $media_id
		);
		$accept_nonce = wp_nonce_field( 'mvs_ai_accept_' . $media_id, '_wpnonce', true, false );

		require MVS_PLUGIN_DIR . 'templates/admin/ai-review.php';
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
	 *
	 * Capability gate: the menu page is registered with `manage_options`
	 * in {@see Plugin::register_admin_menu()}, so WordPress already blocks
	 * lower-privilege users from reaching this handler. We re-check here
	 * for defense-in-depth — a bug in page registration, role plugin, or
	 * future menu-cap change cannot expose destructive actions. Plugin
	 * Check requires this inline pairing alongside `check_admin_referer()`.
	 *
	 * Hooked on the page's `load-` action (see Plugin::register_admin_menu())
	 * so redirects fire before any output. Public for that callback.
	 */
	public static function handle_bulk_actions(): void {
		global $wpdb;

		// Entry gate: media moderators (moderate_mvs_media) reach this handler
		// for the AI-review actions; full admins (manage_options) reach every
		// action. Each case below ALSO re-checks the capability it requires
		// inline next to its nonce — the destructive non-AI actions still hard
		// require manage_options there, so relaxing this entry gate does not
		// widen access to trash/delete/optimize.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			return;
		}

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

		// Cap + nonce pair per case. The function entry already gates on
		// manage_options, but the inline pair documents authorization at
		// each action and satisfies static analyzers that match per-block.
		switch ( $action ) {
			case 'trash':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				check_admin_referer( 'mvs_trash_media_' . $media_id );
				$wpdb->update( $table, array( 'status' => 'trash' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;

			case 'restore':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				check_admin_referer( 'mvs_restore_media_' . $media_id );
				$wpdb->update( $table, array( 'status' => 'publish' ), array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;

			case 'delete':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				check_admin_referer( 'mvs_delete_media_' . $media_id );
				self::permanently_delete_media( $media_id );
				break;

			case 'repair_thumb':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				check_admin_referer( 'mvs_repair_thumb_' . $media_id );
				$repair_result = self::repair_media_thumb( $media_id );
				set_transient(
					'mvs_repair_thumb_notice',
					array(
						'type'     => $repair_result['ok'] ? 'success' : 'warning',
						'message'  => $repair_result['message'],
						'media_id' => $media_id,
					),
					60
				);
				break;

			case 'optimize':
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				check_admin_referer( 'mvs_optimize_media_' . $media_id );
				$opt_service = \WPMediaVerse\Core\Plugin::container()->get( 'image_optimization' );
				$opt_result  = $opt_service->optimize_media(
					$media_id,
					array(
						'force'            => true,
						'include_variants' => true,
					)
				);
				if ( ! empty( $opt_result['errors'] ) ) {
					$notice = array(
						'type'    => 'warning',
						'message' => sprintf(
							/* translators: 1: media id, 2: error code list */
							__( 'Could not optimize media #%1$d: %2$s', 'wpmediaverse' ),
							$media_id,
							implode( ', ', $opt_result['errors'] )
						),
					);
				} else {
					$notice = array(
						'type'    => 'success',
						'message' => sprintf(
							/* translators: 1: media id, 2: bytes before formatted, 3: bytes after formatted, 4: savings percentage */
							__( 'Optimized media #%1$d: %2$s to %3$s (saved %4$s%%).', 'wpmediaverse' ),
							$media_id,
							size_format( (int) $opt_result['bytes_before'] ),
							size_format( (int) $opt_result['bytes_after'] ),
							(string) $opt_result['saved_pct']
						),
					);
				}
				$notice['media_id'] = $media_id;
				set_transient( 'mvs_optimize_notice', $notice, 60 );
				break;

			case 'ai_accept':
				if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
					return;
				}
				check_admin_referer( 'mvs_ai_accept_' . $media_id );
				self::handle_ai_accept( $media_id );
				break;

			case 'ai_reject':
				if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
					return;
				}
				check_admin_referer( 'mvs_ai_reject_' . $media_id );
				self::handle_ai_reject( $media_id );
				break;

			case 'ai_rerun':
				if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
					return;
				}
				check_admin_referer( 'mvs_ai_rerun_' . $media_id );
				self::handle_ai_rerun( $media_id );
				break;
		}

		// Preserve view=details / view=ai-review so the action redirects back
		// to the mini-page it was triggered from. Everywhere else goes to the
		// list.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $view, array( 'details', 'ai-review' ), true ) && $media_id > 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => 'mvs-media',
						'view'     => $view,
						'media_id' => $media_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
		exit;
	}

	/**
	 * Repair a single media item's thumbnail.
	 *
	 * Non-destructive — only writes new meta when a regeneration succeeds.
	 * If no repair path is available for this media (e.g. video without
	 * Pro/ffmpeg, or an image whose original is cloud-only), the existing
	 * meta is left untouched and the action returns ok=false so the user
	 * sees a clear "no repair path" message instead of silently losing a
	 * working thumbnail.
	 *
	 * Strategy:
	 *   1. For images, regenerate 3 size variants via wp_get_image_editor
	 *      from the local original. Each successful save overwrites the
	 *      thumb_<size> meta key.
	 *   2. Fire mvs_repair_media_thumb so Pro / third parties can do
	 *      type-specific work (Pro hooks in to extract a video poster
	 *      via ffmpeg).
	 *   3. Report to the user. Never wipe meta as a side-effect — the
	 *      previous "clear meta then hope" approach killed working thumbs
	 *      on systems where the cloud URL was actually reachable.
	 *
	 * @param int $media_id Media ID to repair.
	 * @return array{ok: bool, message: string} Result for the admin notice.
	 */
	private static function repair_media_thumb( int $media_id ): array {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		if ( ! $repo || ! $repo->exists( $media_id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Media not found.', 'wpmediaverse' ),
			);
		}

		$file_type   = (string) $repo->get( $media_id, 'file_type' );
		$file_path   = (string) $repo->get( $media_id, 'file_path' );
		$regenerated = 0;

		// Image: regenerate size variants locally when the original is on disk.
		if ( 0 === strpos( $file_type, 'image/' ) && $file_path ) {
			$upload     = wp_upload_dir();
			$local_path = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'wpmediaverse/' . ltrim( $file_path, '/' );
			if ( file_exists( $local_path ) ) {
				$editor = wp_get_image_editor( $local_path );
				if ( ! is_wp_error( $editor ) ) {
					$sizes = array(
						'thumb'  => array( 200, 200 ),
						'medium' => array( 600, 600 ),
						'large'  => array( 1280, 1280 ),
					);
					foreach ( $sizes as $size_key => $dims ) {
						$resized = clone $editor;
						$resized->resize( $dims[0], $dims[1], false );
						$saved = $resized->save();
						if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
							$rel = ltrim( str_replace( (string) $upload['basedir'], '', (string) $saved['path'] ), '/' );
							$url = trailingslashit( (string) $upload['baseurl'] ) . $rel;
							$repo->set( $media_id, 'thumb_' . $size_key, $url );
							++$regenerated;
						}
					}
				}
			}
		}

		/**
		 * Allow Pro / external code to do extra repair work (e.g. extract a
		 * fresh video poster via ffmpeg and write the resulting local URLs
		 * into thumb_* meta). Listeners MUST be non-destructive — only
		 * write when they have a working replacement.
		 *
		 * @since 1.2.3
		 *
		 * @param int   $regenerated Size-variant count regenerated so far.
		 * @param int   $media_id    Media being repaired.
		 * @param array $context     file_type, file_path.
		 */
		$regenerated = (int) apply_filters(
			'mvs_repair_media_thumb',
			$regenerated,
			$media_id,
			array(
				'file_type' => $file_type,
				'file_path' => $file_path,
			)
		);

		if ( $regenerated > 0 ) {
			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: media id, 2: count of regenerated thumbs */
					_n(
						'Media #%1$d — regenerated %2$d size variant.',
						'Media #%1$d — regenerated %2$d size variants.',
						$regenerated,
						'wpmediaverse'
					),
					$media_id,
					$regenerated
				),
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d: media id */
				__( 'Media #%d — no repair path available. Image needs the original on local disk; video needs WPMediaVerse Pro with FFmpeg.', 'wpmediaverse' ),
				$media_id
			),
		);
	}

	/**
	 * Decide whether the per-row Repair link should render for a given media.
	 *
	 * Returns true only when there's a real repair path for this media's
	 * type, so users never see a button that does nothing. Free votes yes
	 * for images on local disk; Pro hooks in to vote yes for videos when
	 * FFmpeg is available.
	 *
	 * @param int    $media_id  Media ID.
	 * @param string $file_type MIME type.
	 * @return bool
	 */
	private static function can_repair_thumb( int $media_id, string $file_type ): bool {
		$file_path = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_path' );
		$can       = false;

		if ( 0 === strpos( $file_type, 'image/' ) && $file_path ) {
			$upload     = wp_upload_dir();
			$local_path = trailingslashit( (string) ( $upload['basedir'] ?? '' ) ) . 'wpmediaverse/' . ltrim( $file_path, '/' );
			$can        = file_exists( $local_path );
		}

		/**
		 * Listeners (Pro's TranscodeService) flip this true when they can
		 * regenerate a poster for the media — e.g. video media on a host
		 * with FFmpeg installed. Only listeners that ALSO subscribe to
		 * mvs_repair_media_thumb should claim true here.
		 *
		 * @since 1.2.3
		 *
		 * @param bool   $can       Whether a repair path exists.
		 * @param int    $media_id  Media ID.
		 * @param string $file_type MIME type.
		 * @param string $file_path Repository file_path (relative to wpmediaverse/).
		 */
		return (bool) apply_filters( 'mvs_can_repair_thumb', $can, $media_id, $file_type, $file_path );
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
	 * Accept the AI result for a media item.
	 *
	 * Reads the (possibly edited) description + tags from the submitted form,
	 * copies the description into the media's own `description` field, applies
	 * the tags to the mvs_tag taxonomy, mirrors them onto the media's `tags`
	 * field, stores the edited ai_description/ai_tags back, and marks the
	 * result reviewed via ai_status='accepted'.
	 *
	 * Capability + nonce are already verified by the ai_accept case in
	 * handle_bulk_actions() before this runs.
	 *
	 * @param int $media_id Media ID.
	 */
	private static function handle_ai_accept( int $media_id ): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Edited values come from the AI Review form POST. Nonce already checked.
		$description = isset( $_POST['ai_description'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_textarea_field( wp_unslash( $_POST['ai_description'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: (string) $repo->get_raw( $media_id, 'ai_description' );

		$tags_raw = isset( $_POST['ai_tags'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['ai_tags'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$tags     = '' !== $tags_raw
			? array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) )
			: self::decode_ai_tags( $repo->get_raw( $media_id, 'ai_tags' ) );

		// Persist any edits back to the AI fields so they stay in sync.
		$repo->set( $media_id, 'ai_description', $description );
		$repo->set( $media_id, 'ai_tags', $tags );

		// Copy the description into the media's own field (used as alt text).
		$repo->set( $media_id, 'description', $description );

		// Apply tags to the taxonomy and mirror onto the media's tags field.
		if ( ! empty( $tags ) ) {
			wp_set_object_terms( $media_id, $tags, 'mvs_tag', true );
			$all_terms = get_the_terms( $media_id, 'mvs_tag' );
			if ( $all_terms && ! is_wp_error( $all_terms ) ) {
				$repo->set( $media_id, 'tags', wp_json_encode( array_values( wp_list_pluck( $all_terms, 'name' ) ) ) );
			}
		}

		// Mark reviewed so the admin can tell it was accepted.
		$repo->set( $media_id, 'ai_status', 'accepted' );
		$repo->set( $media_id, 'ai_reviewed', current_time( 'mysql', true ) );

		set_transient(
			'mvs_ai_review_notice',
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: media id */
					__( 'AI result accepted for media #%d. The description was applied and tags were saved.', 'wpmediaverse' ),
					$media_id
				),
			),
			60
		);
	}

	/**
	 * Reject the AI result for a media item.
	 *
	 * Clears ai_description and ai_tags so the result is never used as alt
	 * text, and marks ai_status='rejected'. Does not touch the media's own
	 * description/tags.
	 *
	 * Capability + nonce are already verified by the ai_reject case in
	 * handle_bulk_actions() before this runs.
	 *
	 * @param int $media_id Media ID.
	 */
	private static function handle_ai_reject( int $media_id ): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$repo->set( $media_id, 'ai_description', '' );
		$repo->set( $media_id, 'ai_tags', array() );
		$repo->set( $media_id, 'ai_status', 'rejected' );
		$repo->set( $media_id, 'ai_reviewed', current_time( 'mysql', true ) );

		set_transient(
			'mvs_ai_review_notice',
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: media id */
					__( 'AI result rejected for media #%d. The AI description and tags were cleared.', 'wpmediaverse' ),
					$media_id
				),
			),
			60
		);
	}

	/**
	 * Re-run the AI pipeline for a media item.
	 *
	 * Re-queues the async mvs_ai_process_media Action Scheduler job, falling
	 * back to a synchronous AIService::process() when Action Scheduler is not
	 * available. Resets ai_status to 'processing' for immediate UI feedback.
	 *
	 * Capability + nonce are already verified by the ai_rerun case in
	 * handle_bulk_actions() before this runs.
	 *
	 * @param int $media_id Media ID.
	 */
	private static function handle_ai_rerun( int $media_id ): void {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$repo->set( $media_id, 'ai_status', 'processing' );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'mvs_ai_process_media', array( $media_id ), 'wpmediaverse' );
			$message = sprintf(
				/* translators: %d: media id */
				__( 'AI re-run queued for media #%d. Reload this page in a moment to see the new result.', 'wpmediaverse' ),
				$media_id
			);
		} else {
			\WPMediaVerse\Core\Plugin::container()->get( 'ai' )->process( $media_id );
			$message = sprintf(
				/* translators: %d: media id */
				__( 'AI re-run completed for media #%d.', 'wpmediaverse' ),
				$media_id
			);
		}

		set_transient(
			'mvs_ai_review_notice',
			array(
				'type'    => 'success',
				'message' => $message,
			),
			60
		);
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

	/**
	 * Render the read-only Details mini-page for a single media row.
	 *
	 * Reachable via ?page=mvs-media&view=details&media_id=X. Shows:
	 *  - File metadata (path, MIME, hash, dimensions, sizes)
	 *  - Optimization status + savings
	 *  - WebP variant URLs
	 *  - Every thumb_<size> URL
	 *  - Inline action buttons: Re-optimize, Repair thumb, Trash
	 *
	 * No field editing in 1.2.2 — titles/descriptions/privacy editing lands in 1.3.0.
	 */
	private static function render_detail(): void {
		$media_id = isset( $_GET['media_id'] ) ? (int) $_GET['media_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $media_id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE media_id = %d", $media_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item ) {
			?>
			<div class="wrap wpmediaverse-admin">
				<h1><?php esc_html_e( 'Media not found', 'wpmediaverse' ); ?></h1>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>"><?php esc_html_e( 'Back to all media', 'wpmediaverse' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$mime   = (string) ( $item['file_type'] ?? '' );
		$is_img = 0 === strpos( $mime, 'image/' );

		$optimized_at = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT );
		$failed_code  = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZE_FAILED );
		$bytes_before = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_BEFORE );
		$bytes_after  = (int) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_AFTER );
		$webp_orig    = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP );
		$width        = (int) $repo->get_raw( $media_id, 'width' );
		$height       = (int) $repo->get_raw( $media_id, 'height' );
		$saved_pct    = ( $bytes_before > 0 ) ? round( ( $bytes_before - $bytes_after ) / $bytes_before * 100, 2 ) : 0;

		$optimize_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'mvs-media',
					'view'     => 'details',
					'action'   => 'optimize',
					'media_id' => $media_id,
				),
				admin_url( 'admin.php' )
			),
			'mvs_optimize_media_' . $media_id
		);
		$repair_url   = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'mvs-media',
					'view'     => 'details',
					'action'   => 'repair_thumb',
					'media_id' => $media_id,
				),
				admin_url( 'admin.php' )
			),
			'mvs_repair_thumb_' . $media_id
		);
		$trash_url    = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => 'mvs-media',
					'action'   => 'trash',
					'media_id' => $media_id,
				),
				admin_url( 'admin.php' )
			),
			'mvs_trash_media_' . $media_id
		);
		?>
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="image"></i>
						<?php
						/* translators: %d: media id */
						echo esc_html( sprintf( __( 'Media #%d', 'wpmediaverse' ), $media_id ) );
						?>
					</h1>
					<p class="mvs-page-header__desc">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>">&larr; <?php esc_html_e( 'All media', 'wpmediaverse' ); ?></a>
					</p>
				</div>
			</div>

			<?php
			$optimize_notice = get_transient( 'mvs_optimize_notice' );
			if ( is_array( $optimize_notice ) && ! empty( $optimize_notice['message'] ) ) {
				delete_transient( 'mvs_optimize_notice' );
				$class = 'success' === ( $optimize_notice['type'] ?? '' ) ? 'notice-success' : 'notice-warning';
				printf(
					'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $class ),
					esc_html( (string) $optimize_notice['message'] )
				);
			}
			$repair_notice = get_transient( 'mvs_repair_thumb_notice' );
			if ( is_array( $repair_notice ) && ! empty( $repair_notice['message'] ) ) {
				delete_transient( 'mvs_repair_thumb_notice' );
				$class = 'success' === ( $repair_notice['type'] ?? '' ) ? 'notice-success' : 'notice-warning';
				printf(
					'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $class ),
					esc_html( (string) $repair_notice['message'] )
				);
			}
			?>

			<div class="mvs-admin-widget">
				<div class="mvs-widget-body">
					<h2><?php esc_html_e( 'About this image', 'wpmediaverse' ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr><th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th><td><?php echo esc_html( (string) $item['title'] ); ?></td></tr>
							<?php if ( $is_img && ( $width > 0 || $height > 0 ) ) : ?>
								<tr><th><?php esc_html_e( 'Image size', 'wpmediaverse' ); ?></th><td><?php echo esc_html( sprintf( '%d × %d', $width, $height ) ) . ' ' . esc_html__( 'pixels', 'wpmediaverse' ); ?></td></tr>
							<?php endif; ?>
							<tr><th><?php esc_html_e( 'File size', 'wpmediaverse' ); ?></th><td><?php echo esc_html( size_format( (int) $item['file_size'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Who can see it', 'wpmediaverse' ); ?></th><td><?php echo esc_html( ucfirst( (string) $item['privacy'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Status', 'wpmediaverse' ); ?></th><td><?php echo esc_html( ucfirst( (string) $item['status'] ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Uploaded', 'wpmediaverse' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $item['created_at'] ) ) ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Preview', 'wpmediaverse' ); ?></th><td><a href="<?php echo esc_url( (string) $item['file_url'] ); ?>" target="_blank"><?php esc_html_e( 'Open the original image', 'wpmediaverse' ); ?> &rarr;</a></td></tr>
						</tbody>
					</table>

					<?php if ( $is_img ) : ?>
						<h2><?php esc_html_e( 'Optimization', 'wpmediaverse' ); ?></h2>
						<?php
						// When re-compression produced no size gain on this image, give the
						// admin one transparent line explaining why an
						// already-high-quality JPEG often cannot be shrunk on
						// PHP-GD environments. Mentions Imagick as the path
						// to real savings without scaring photographers.
						$show_gd_hint = false;
						if ( $optimized_at > 0 && 0 === $saved_pct && '' === $webp_orig ) {
							$abs_path = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpmediaverse/' . (string) $item['file_path'];
							if ( file_exists( $abs_path ) ) {
								$probe = wp_get_image_editor( $abs_path );
								if ( ! is_wp_error( $probe ) && 'WP_Image_Editor_GD' === get_class( $probe ) ) {
									$show_gd_hint = true;
								}
							}
						}
						if ( $show_gd_hint ) :
							?>
							<p class="description mvs-admin-hint">
								<?php esc_html_e( 'Your server is using PHP-GD for image processing. GD is a weaker JPEG and WebP encoder than ImageMagick, so already-high-quality photos often cannot be shrunk further without losing visible quality. Ask your host to enable the ImageMagick PHP extension for better savings on large photos.', 'wpmediaverse' ); ?>
							</p>
							<?php
						endif;
						?>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'Status', 'wpmediaverse' ); ?></th>
									<td>
										<?php
										if ( '' !== $failed_code ) :
											?>
											<span class="mvs-media-badge mvs-media-badge--danger"><?php esc_html_e( 'Could not optimize', 'wpmediaverse' ); ?></span>
											&nbsp;<?php esc_html_e( 'Try Re-optimize below. If it keeps failing, ask your developer to check the error logs.', 'wpmediaverse' ); ?>
											<?php
										elseif ( $optimized_at > 0 && $saved_pct > 0 ) :
											?>
											<span class="mvs-media-badge mvs-media-badge--success"><?php esc_html_e( 'Optimized', 'wpmediaverse' ); ?></span>
											&nbsp;
											<?php
											/* translators: %s: human-readable date and time */
											echo esc_html( sprintf( __( 'on %s', 'wpmediaverse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $optimized_at ) ) );
											?>
											<?php
										elseif ( $optimized_at > 0 && '' !== $webp_orig ) :
											?>
											<span class="mvs-media-badge mvs-media-badge--success"><?php esc_html_e( 'WebP copy created', 'wpmediaverse' ); ?></span>
											&nbsp;
											<?php
											/* translators: %s: human-readable date and time */
											echo esc_html( sprintf( __( 'on %s', 'wpmediaverse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $optimized_at ) ) );
											?>
											<?php
										elseif ( $optimized_at > 0 ) :
											?>
											<span class="mvs-media-badge mvs-media-badge--neutral"><?php esc_html_e( 'No size gain', 'wpmediaverse' ); ?></span>
											&nbsp;
											<?php
											/* translators: %s: human-readable date and time */
											echo esc_html( sprintf( __( 'checked on %s', 'wpmediaverse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $optimized_at ) ) );
											?>
											<?php
										else :
											?>
											<span class="mvs-media-badge mvs-media-badge--draft"><?php esc_html_e( 'Not optimized', 'wpmediaverse' ); ?></span>
											&nbsp;<?php esc_html_e( 'Click Re-optimize below to shrink this image.', 'wpmediaverse' ); ?>
											<?php
										endif;
										?>
									</td>
								</tr>
								<?php if ( $saved_pct > 0 ) : ?>
									<tr><th><?php esc_html_e( 'Original size', 'wpmediaverse' ); ?></th><td><?php echo esc_html( size_format( $bytes_before ) ); ?></td></tr>
									<tr><th><?php esc_html_e( 'After optimization', 'wpmediaverse' ); ?></th><td><?php echo esc_html( size_format( $bytes_after ) ); ?></td></tr>
									<tr>
										<th><?php esc_html_e( 'Space saved', 'wpmediaverse' ); ?></th>
										<td>
											<?php
											/* translators: 1: saved size in human-readable form, 2: saved percentage */
											echo esc_html( sprintf( __( '%1$s smaller (%2$s%% less)', 'wpmediaverse' ), size_format( max( 0, $bytes_before - $bytes_after ) ), (string) $saved_pct ) );
											?>
										</td>
									</tr>
								<?php elseif ( $bytes_before > 0 ) : ?>
									<tr>
										<th><?php esc_html_e( 'Result', 'wpmediaverse' ); ?></th>
										<td>
											<?php
											if ( '' !== $webp_orig ) {
												/* translators: %s: file size in human-readable form */
												echo wp_kses_post( sprintf( __( 'Re-compression could not shrink this %s image further. A smaller WebP copy was created instead, so visitors on modern browsers get a faster page load.', 'wpmediaverse' ), '<strong>' . esc_html( size_format( $bytes_before ) ) . '</strong>' ) );
											} else {
												/* translators: %s: file size in human-readable form */
												echo wp_kses_post( sprintf( __( 'This %s image was saved at a high quality level. Re-compressing it would have produced a LARGER file, so we kept your original untouched, and a WebP copy would also have been larger (so we did not create one). To reduce the file size further you can re-upload at a smaller resolution, or save it at a lower JPEG quality before uploading.', 'wpmediaverse' ), '<strong>' . esc_html( size_format( $bytes_before ) ) . '</strong>' ) );
											}
											?>
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<th><?php esc_html_e( 'WebP copy', 'wpmediaverse' ); ?></th>
									<td>
										<?php if ( '' !== $webp_orig ) : ?>
											<span class="mvs-media-badge mvs-media-badge--success"><?php esc_html_e( 'Available', 'wpmediaverse' ); ?></span>
											&nbsp;<a href="<?php echo esc_url( $webp_orig ); ?>" target="_blank"><?php esc_html_e( 'Open WebP copy', 'wpmediaverse' ); ?> &rarr;</a>
										<?php else : ?>
											<em><?php esc_html_e( 'Not created yet', 'wpmediaverse' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>

						<h2><?php esc_html_e( 'Thumbnail sizes', 'wpmediaverse' ); ?></h2>
						<p class="description"><?php esc_html_e( 'WPMediaVerse keeps three smaller versions of every image so pages load fast.', 'wpmediaverse' ); ?></p>
						<table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Size', 'wpmediaverse' ); ?></th>
								<th><?php esc_html_e( 'Image', 'wpmediaverse' ); ?></th>
								<th><?php esc_html_e( 'WebP copy', 'wpmediaverse' ); ?></th>
							</tr></thead>
							<tbody>
								<?php
								$size_labels = array(
									'large'  => __( 'Large', 'wpmediaverse' ),
									'medium' => __( 'Medium', 'wpmediaverse' ),
									'thumb'  => __( 'Thumbnail', 'wpmediaverse' ),
								);
								foreach ( \WPMediaVerse\Services\ImageOptimizationService::variant_keys() as $size ) :
									$thumb_url      = (string) $repo->get_raw( $media_id, 'thumb_' . $size );
									$thumb_webp_url = (string) $repo->get_raw( $media_id, 'thumb_' . $size . '_webp' );
									$label          = $size_labels[ $size ] ?? $size;
									?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td>
											<?php if ( '' !== $thumb_url ) : ?>
												<a href="<?php echo esc_url( $thumb_url ); ?>" target="_blank"><?php esc_html_e( 'View', 'wpmediaverse' ); ?> &rarr;</a>
											<?php else : ?>
												<em><?php esc_html_e( 'Not available', 'wpmediaverse' ); ?></em>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( '' !== $thumb_webp_url ) : ?>
												<a href="<?php echo esc_url( $thumb_webp_url ); ?>" target="_blank"><?php esc_html_e( 'View', 'wpmediaverse' ); ?> &rarr;</a>
											<?php else : ?>
												<em><?php esc_html_e( 'Not created', 'wpmediaverse' ); ?></em>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<h2><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></h2>
					<p>
						<?php if ( $is_img ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $optimize_url ); ?>"><?php esc_html_e( 'Re-optimize', 'wpmediaverse' ); ?></a>
						<?php endif; ?>
						<?php if ( $is_img && self::can_repair_thumb( $media_id, $mime ) ) : ?>
							<a class="button" href="<?php echo esc_url( $repair_url ); ?>"><?php esc_html_e( 'Repair thumbnails', 'wpmediaverse' ); ?></a>
						<?php endif; ?>
						<a class="button button-link-delete" href="<?php echo esc_url( $trash_url ); ?>"><?php esc_html_e( 'Move to Trash', 'wpmediaverse' ); ?></a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}
}

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

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Services\MediaMeta;

/**
 * Renders the All Media admin page with filtering, search, and bulk actions.
 */
class MediaListPage {

	/**
	 * Render the media listing page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'upload_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// Handle bulk actions.
		self::handle_bulk_actions();

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
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All Media', 'wpmediaverse' ); ?></h1>
			<hr class="wp-header-end">

			<?php self::render_status_tabs( $status_counts, $status_filter, $base_url ); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="mvs-media" />
				<?php if ( $status_filter ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>" />
				<?php endif; ?>

				<div class="tablenav top">
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

				<table class="wp-list-table widefat fixed striped table-view-list">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" /></td>
							<th class="manage-column" style="width:50px;"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
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
							<tr><td colspan="8"><?php esc_html_e( 'No media items found.', 'wpmediaverse' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<?php self::render_row( $item ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" /></td>
							<th class="manage-column" style="width:50px;"><?php esc_html_e( 'Thumb', 'wpmediaverse' ); ?></th>
							<th class="manage-column column-primary"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Status', 'wpmediaverse' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
						</tr>
					</tfoot>
				</table>

				<div class="tablenav bottom">
					<?php self::render_pagination( $total, $total_pages, $paged ); ?>
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
			''        => array( __( 'All', 'wpmediaverse' ), $all_count ),
			'publish' => array( __( 'Published', 'wpmediaverse' ), (int) ( $status_counts['publish']->cnt ?? 0 ) ),
			'draft'   => array( __( 'Draft', 'wpmediaverse' ), (int) ( $status_counts['draft']->cnt ?? 0 ) ),
			'pending' => array( __( 'Pending', 'wpmediaverse' ), (int) ( $status_counts['pending']->cnt ?? 0 ) ),
			'trash'   => array( __( 'Trash', 'wpmediaverse' ), (int) ( $status_counts['trash']->cnt ?? 0 ) ),
		);
		?>
		<ul class="subsubsub">
			<?php $i = 0; ?>
			<?php foreach ( $statuses as $key => $info ) : ?>
				<?php
				if ( 0 === $info[1] && '' !== $key ) {
					continue;
				}
				$url   = $key ? add_query_arg( 'status', $key, $base_url ) : $base_url;
				$class = ( $current === $key ) ? ' class="current"' : '';
				if ( $i > 0 ) {
					echo ' | ';
				}
				?>
				<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $class; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $info[0] ); ?> <span class="count">(<?php echo esc_html( $info[1] ); ?>)</span></a></li>
				<?php ++$i; ?>
			<?php endforeach; ?>
		</ul>
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

		$type_colors = array(
			'image'    => '#2271b1',
			'video'    => '#9b59b6',
			'audio'    => '#e67e22',
			'document' => '#27ae60',
		);

		$privacy_colors = array(
			'public'  => '#00a32a',
			'members' => '#2271b1',
			'private' => '#d63638',
			'group'   => '#9b59b6',
			'friends' => '#e67e22',
		);

		$status_colors = array(
			'publish' => '#00a32a',
			'draft'   => '#646970',
			'pending' => '#dba617',
			'trash'   => '#d63638',
		);

		$view_url = MediaMeta::get_permalink( $media_id );
		?>
		<tr>
			<th scope="row" class="check-column"><input type="checkbox" name="media_ids[]" value="<?php echo esc_attr( $media_id ); ?>" /></th>
			<td style="width:50px;">
				<?php
				$thumb_url = '';
				if ( 'image' === $type && $file_url ) {
					$thumb_url = $file_url;
				}
				if ( ! $thumb_url ) {
					$thumb_url = TemplateHelpers::get_thumb_url( $media_id, 'thumbnail' );
				}
				?>
				<?php if ( $thumb_url ) : ?>
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" loading="lazy" />
				<?php else : ?>
					<?php
					$icons = array(
						'video'    => 'dashicons-video-alt3',
						'audio'    => 'dashicons-format-audio',
						'document' => 'dashicons-media-document',
						'image'    => 'dashicons-format-image',
					);
					$icon  = $icons[ $type ] ?? 'dashicons-media-default';
					?>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:28px;width:40px;height:40px;line-height:40px;color:#646970;"></span>
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
														" class="submitdelete"><?php esc_html_e( 'Delete Permanently', 'wpmediaverse' ); ?></a></span>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo $author ? esc_html( $author->display_name ) : '—'; ?></td>
			<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $type_colors[ $type ] ?? '#646970' ); ?>;"><?php echo esc_html( ucfirst( $type ) ); ?></span></td>
			<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $privacy_colors[ $privacy ] ?? '#646970' ); ?>;"><?php echo esc_html( ucfirst( $privacy ) ); ?></span></td>
			<td><span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $status_colors[ $status ] ?? '#646970' ); ?>;"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
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
				// Delete from custom tables.
				MediaMeta::delete_all( $media_id );
				$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $wpdb->prefix . 'mvs_favorites', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'media_id' => $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mvs-media' ) );
		exit;
	}
}

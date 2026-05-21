<?php
/**
 * Admin tag management page.
 *
 * Provides interface to view, edit, and delete media tags.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Term;

/**
 * Renders the Tag Management admin page with listing, search, and bulk actions.
 */
class TagManagementPage {

	/**
	 * Number of tags displayed per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Constructor. Registers hooks for handling tag actions.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_bulk_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_single_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_edit' ) );
		add_action( 'admin_init', array( $this, 'handle_create' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
	}

	/**
	 * Render the tag management page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// Check if we're in edit mode.
		$edit_mode = isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['tag_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $edit_mode ) {
			$this->render_edit_form();
			return;
		}

		// Check if we're in new tag mode.
		if ( isset( $_GET['action'] ) && 'new' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->render_new_form();
			return;
		}

		global $wpdb;

		// Filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Pagination.
		$per_page = self::PER_PAGE;
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $paged - 1 ) * $per_page;

		// Build args for get_terms.
		// hide_empty must be false so newly created tags (count=0) and tags
		// whose last media was deleted are still manageable from the admin.
		// Tags created via POST /mvs/v1/tags land with count=0 until a media
		// upload attaches to them — before this fix they were invisible here.
		$allowed_orderby = array( 'name', 'term_id', 'slug', 'count' );
		$orderby         = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification
		$order           = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'name';
		}

		$args = array(
			'taxonomy'   => 'mvs_tag',
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => $orderby,
			'order'      => $order,
		);

		if ( $search ) {
			$args['search'] = $search;
		}

		// Get total count — must match the display query so pagination is correct.
		$count_args = array(
			'taxonomy'   => 'mvs_tag',
			'hide_empty' => false,
		);
		if ( $search ) {
			$count_args['search'] = $search;
		}
		$all_tags = get_terms( $count_args );
		$total    = is_wp_error( $all_tags ) ? 0 : count( $all_tags );

		$total_pages = (int) ceil( $total / $per_page );

		// Fetch tags for current page.
		$tags = get_terms( $args );
		if ( is_wp_error( $tags ) ) {
			$tags = array();
		}

		$base_url = admin_url( 'admin.php?page=mvs-tags' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( 'action', 'new', $base_url ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Tag', 'wpmediaverse' ); ?></a>
			<a href="<?php echo esc_url( $base_url ); ?>" class="page-title-action"><?php esc_html_e( 'Refresh', 'wpmediaverse' ); ?></a>
			<hr class="wp-header-end">

			<form method="get" action="">
				<input type="hidden" name="page" value="mvs-tags" />
				<?php wp_nonce_field( 'bulk-tags', '_wpnonce' ); ?>
				<?php
				$this->render_search_form( $search, $base_url );
				$this->render_bulk_actions_form( $tags, $base_url, $orderby, $order, $total, $paged, $total_pages );
				?>
			</form>
		</div>

		<script>
		jQuery( document ).ready( function( $ ) {
			// Select all checkboxes functionality.
			$( '#cb-select-all-1, #cb-select-all-2' ).on( 'change', function() {
				var checked = $( this ).prop( 'checked' );
				$( 'input[name="tag_ids[]"]' ).prop( 'checked', checked );
			});
		});
		</script>
		<?php
	}

	/**
	 * Render search form.
	 *
	 * @param string $search  Search term.
	 * @param string $base_url Base URL.
	 */
	private function render_search_form( string $search, string $base_url ): void {
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="tag-search-input"><?php esc_html_e( 'Search Tags', 'wpmediaverse' ); ?></label>
			<input type="search" id="tag-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" />
			<input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search Tags', 'wpmediaverse' ); ?>" />
			<?php if ( $search ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'wpmediaverse' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render bulk actions form with tag table.
	 *
	 * @param array  $tags        Array of WP_Term objects.
	 * @param string $base_url    Base URL.
	 * @param string $orderby     Current sort column.
	 * @param string $order       Current sort direction (ASC/DESC).
	 * @param int    $total       Total number of tags.
	 * @param int    $paged       Current page number.
	 * @param int    $total_pages Total number of pages.
	 */
	private function render_bulk_actions_form( array $tags, string $base_url, string $orderby, string $order, int $total, int $paged, int $total_pages ): void {
		?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'wpmediaverse' ); ?></label>
				<select name="action" id="bulk-action-selector-top">
					<option value="-1"><?php esc_html_e( 'Bulk Actions', 'wpmediaverse' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></option>
				</select>
				<input type="submit" name="doaction" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'wpmediaverse' ); ?>" />
			</div>
			<?php $this->render_pagination( $base_url, $total, $paged, $total_pages ); ?>
			<br class="clear" />
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
				<?php
				$sort_columns = array(
					'term_id' => array(
						'label' => __( 'ID', 'wpmediaverse' ),
						'class' => 'column-id',
					),
					'name'    => array(
						'label' => __( 'Name', 'wpmediaverse' ),
						'class' => 'column-primary',
					),
					'slug'    => array(
						'label' => __( 'Slug', 'wpmediaverse' ),
						'class' => 'column-slug',
					),
					'count'   => array(
						'label' => __( 'Count', 'wpmediaverse' ),
						'class' => 'column-count',
					),
				);
				?>
				<tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1" /></td>
					<?php foreach ( $sort_columns as $col_key => $col ) : ?>
						<?php
						$is_current   = ( $orderby === $col_key );
						$next_order   = ( $is_current && 'ASC' === $order ) ? 'desc' : 'asc';
						$sort_url     = add_query_arg(
							array(
								'orderby' => $col_key,
								'order'   => $next_order,
							),
							$base_url
						);
						$sorted_class = $is_current ? ' sorted ' . strtolower( $order ) : ' sortable asc';
						?>
						<th class="manage-column <?php echo esc_attr( $col['class'] . $sorted_class ); ?>">
							<a href="<?php echo esc_url( $sort_url ); ?>"><span><?php echo esc_html( $col['label'] ); ?></span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a>
						</th>
					<?php endforeach; ?>
					<th class="manage-column column-actions"><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $tags ) ) : ?>
					<tr>
						<td colspan="6">
							<div class="mvs-empty-state-admin">
								<i data-lucide="tag"></i>
								<h3><?php esc_html_e( 'No Tags Found', 'wpmediaverse' ); ?></h3>
								<p><?php esc_html_e( 'No tags found.', 'wpmediaverse' ); ?></p>
							</div>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $tags as $tag ) : ?>
						<?php $this->render_row( $tag ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-2" /></td>
					<th class="manage-column column-id"><?php esc_html_e( 'ID', 'wpmediaverse' ); ?></th>
					<th class="manage-column column-primary"><?php esc_html_e( 'Name', 'wpmediaverse' ); ?></th>
					<th class="manage-column column-slug"><?php esc_html_e( 'Slug', 'wpmediaverse' ); ?></th>
					<th class="manage-column column-count"><?php esc_html_e( 'Count', 'wpmediaverse' ); ?></th>
					<th class="manage-column column-actions"><?php esc_html_e( 'Actions', 'wpmediaverse' ); ?></th>
				</tr>
			</tfoot>
		</table>

		<div class="tablenav bottom">
			<div class="alignleft actions bulkactions">
				<label for="bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'wpmediaverse' ); ?></label>
				<select name="action2" id="bulk-action-selector-bottom">
					<option value="-1"><?php esc_html_e( 'Bulk Actions', 'wpmediaverse' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></option>
				</select>
				<input type="submit" name="doaction2" id="doaction2" class="button action" value="<?php esc_attr_e( 'Apply', 'wpmediaverse' ); ?>" />
			</div>
			<?php $this->render_pagination( $base_url, $total, $paged, $total_pages ); ?>
			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * Render a single tag row.
	 *
	 * @param WP_Term $tag Tag object.
	 */
	private function render_row( WP_Term $tag ): void {
		$edit_url   = add_query_arg(
			array(
				'action' => 'edit',
				'tag_id' => $tag->term_id,
			),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'mvs-tags',
					'form_action' => 'delete',
					'tag_id'      => $tag->term_id,
				),
				admin_url( 'admin.php' )
			),
			'delete-tag_' . $tag->term_id
		);
		?>
		<tr>
			<th class="check-column">
				<input type="checkbox" name="tag_ids[]" value="<?php echo esc_attr( (string) $tag->term_id ); ?>" />
			</th>
			<td class="column-id"><?php echo esc_html( (string) $tag->term_id ); ?></td>
			<td class="column-primary">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $tag->name ); ?></a></strong>
			</td>
			<td class="column-slug"><?php echo esc_html( $tag->slug ); ?></td>
			<td class="column-count"><?php echo esc_html( (string) $tag->count ); ?></td>
			<td class="column-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></a>
				<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" data-mvs-confirm="<?php echo esc_attr__( 'Are you sure you want to delete this tag?', 'wpmediaverse' ); ?>"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render pagination.
	 *
	 * @param string $base_url    Base URL.
	 * @param int    $total       Total number of items.
	 * @param int    $paged       Current page number.
	 * @param int    $total_pages Total number of pages.
	 */
	private function render_pagination( string $base_url, int $total, int $paged, int $total_pages ): void {

		if ( $total_pages <= 1 ) {
			return;
		}

		$page_links = array();

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( $i === $paged ) {
				$page_links[] = '<span class="page-numbers current">' . esc_html( (string) $i ) . '</span>';
			} else {
				$page_links[] = '<a class="page-numbers" href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '">' . esc_html( (string) $i ) . '</a>';
			}
		}

		echo '<div class="tablenav-pages">';
		printf(
			'<span class="displaying-num">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of items, formatted for the current locale. */
					_n( '%s item', '%s items', $total, 'wpmediaverse' ),
					number_format_i18n( $total )
				)
			)
		);
		echo '<span class="pagination-links">';
		echo wp_kses(
			implode( "\n", $page_links ),
			array(
				'a'    => array(
					'href'  => true,
					'class' => true,
				),
				'span' => array(
					'class' => true,
				),
			)
		);
		echo '</span>';
		echo '</div>';
	}

	/**
	 * Handle bulk actions (delete). Runs on admin_init before output starts.
	 */
	public function handle_bulk_actions(): void {
		// Only act on the tag management page so we don't intercept other admin requests.
		if ( ! isset( $_GET['page'] ) || 'mvs-tags' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Check if this is a bulk action submission.
		if ( ! isset( $_REQUEST['action'] ) && ! isset( $_REQUEST['action2'] ) ) {
			return;
		}

		$action = -1;
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( 'delete' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete tags.', 'wpmediaverse' ) );
		}

		// Verify nonce for bulk actions.
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-tags' ) ) {
			return;
		}

		$tag_ids = isset( $_REQUEST['tag_ids'] ) ? array_map( 'absint', (array) $_REQUEST['tag_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $tag_ids ) ) {
			return;
		}

		$deleted = 0;
		foreach ( $tag_ids as $tag_id ) {
			$result = wp_delete_term( $tag_id, 'mvs_tag' );
			if ( $result && ! is_wp_error( $result ) ) {
				++$deleted;
			}
		}

		// Recalculate valid page after deletion so we don't redirect to an empty page.
		$paged     = isset( $_REQUEST['paged'] ) ? absint( $_REQUEST['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$remaining = (int) wp_count_terms(
			array(
				'taxonomy'   => 'mvs_tag',
				'hide_empty' => false,
			)
		);
		$max_page  = max( 1, (int) ceil( $remaining / self::PER_PAGE ) );
		$paged     = min( $paged, $max_page );

		$redirect_url = add_query_arg(
			array(
				'deleted' => $deleted,
				'paged'   => $paged,
				's'       => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle tag edit form submission.
	 */
	public function handle_edit(): void {
		if ( ! isset( $_POST['form_action'] ) || 'save_edit' !== $_POST['form_action'] ) {
			return;
		}

		if ( ! isset( $_POST['tag_id'] ) || ! isset( $_POST['tag_name'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit tags.', 'wpmediaverse' ) );
		}

		$tag_id = absint( $_POST['tag_id'] );

		// Verify nonce before reading remaining input.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'edit-tag_' . $tag_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		$tag_name = sanitize_text_field( wp_unslash( $_POST['tag_name'] ) );
		$tag_slug = isset( $_POST['tag_slug'] ) ? sanitize_title( wp_unslash( $_POST['tag_slug'] ) ) : sanitize_title( $tag_name );

		$result = wp_update_term(
			$tag_id,
			'mvs_tag',
			array(
				'name' => $tag_name,
				'slug' => $tag_slug,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		// Redirect with success message.
		$redirect_url = add_query_arg(
			array(
				'updated' => 1,
				'paged'   => isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification
				's'       => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle new tag creation form submission.
	 */
	public function handle_create(): void {
		if ( ! isset( $_POST['form_action'] ) || 'save_new' !== $_POST['form_action'] ) {
			return;
		}

		// Verify nonce before reading any other POST fields.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'create-tag' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to create tags.', 'wpmediaverse' ) );
		}

		$tag_name = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
		if ( '' === trim( $tag_name ) ) {
			return;
		}

		$tag_slug_raw = isset( $_POST['tag_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_slug'] ) ) : '';
		$tag_slug     = '' !== trim( $tag_slug_raw ) ? sanitize_title( $tag_slug_raw ) : sanitize_title( $tag_name );

		$result = wp_insert_term(
			$tag_name,
			'mvs_tag',
			array( 'slug' => $tag_slug )
		);

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$redirect_url = add_query_arg(
			array( 'created' => 1 ),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle individual delete action.
	 */
	public function handle_single_delete(): void {
		// Only act on the tag management page so we don't intercept other admin requests.
		if ( ! isset( $_GET['page'] ) || 'mvs-tags' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! isset( $_GET['form_action'] ) || 'delete' !== $_GET['form_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! isset( $_GET['tag_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$tag_id = absint( $_GET['tag_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete tags.', 'wpmediaverse' ) );
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete-tag_' . $tag_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		$result = wp_delete_term( $tag_id, 'mvs_tag' );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		// Recalculate valid page after deletion so we don't redirect to an empty page.
		$paged     = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$remaining = (int) wp_count_terms(
			array(
				'taxonomy'   => 'mvs_tag',
				'hide_empty' => false,
			)
		);
		$max_page  = max( 1, (int) ceil( $remaining / self::PER_PAGE ) );
		$paged     = min( $paged, $max_page );

		$redirect_url = add_query_arg(
			array(
				'deleted' => 1,
				'paged'   => $paged,
				's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render tag edit form with linked media.
	 */
	private function render_edit_form(): void {
		$tag_id = isset( $_GET['tag_id'] ) ? absint( $_GET['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $tag_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mvs-tags' ) );
			exit;
		}

		$tag = get_term( $tag_id, 'mvs_tag' );
		if ( ! $tag || is_wp_error( $tag ) ) {
			wp_die( esc_html__( 'Tag not found.', 'wpmediaverse' ) );
		}

		// Get media items with this tag.
		global $wpdb;
		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.term_id = %d AND tt.taxonomy = 'mvs_tag'",
				$tag_id
			)
		);

		$media_items = array();
		if ( ! empty( $media_ids ) ) {
			foreach ( $media_ids as $media_id ) {
				$media_data = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( $media_id );
				if ( $media_data ) {
					$media_items[] = $media_data;
				}
			}
		}

		$cancel_url  = admin_url( 'admin.php?page=mvs-tags' );
		$form_action = admin_url( 'admin.php?page=mvs-tags' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php
				printf(
					// translators: %s: tag name.
					esc_html__( 'Edit Tag: %s', 'wpmediaverse' ),
					esc_html( $tag->name )
				);
				?>
			</h1>
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back to Tags', 'wpmediaverse' ); ?></a>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( 'edit-tag_' . $tag_id, '_wpnonce' ); ?>
				<input type="hidden" name="tag_id" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
				<input type="hidden" name="form_action" value="save_edit" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="tag-name"><?php esc_html_e( 'Name', 'wpmediaverse' ); ?></label></th>
						<td>
							<input type="text" name="tag_name" id="tag-name" class="regular-text" value="<?php echo esc_attr( $tag->name ); ?>" required />
							<p class="description"><?php esc_html_e( 'The tag name as it appears on the site.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tag-slug"><?php esc_html_e( 'Slug', 'wpmediaverse' ); ?></label></th>
						<td>
							<input type="text" name="tag_slug" id="tag-slug" class="regular-text" value="<?php echo esc_attr( $tag->slug ); ?>" />
							<p class="description"><?php esc_html_e( 'The "nice" name for the tag URL. Usually lowercase.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Count', 'wpmediaverse' ); ?></th>
						<td>
							<?php echo esc_html( (string) $tag->count ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button( esc_html__( 'Update Tag', 'wpmediaverse' ), 'primary', 'submit' ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Linked Media', 'wpmediaverse' ); ?></h2>
			<?php if ( empty( $media_items ) ) : ?>
				<p><?php esc_html_e( 'No media items are linked to this tag.', 'wpmediaverse' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'wpmediaverse' ); ?></th>
							<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $media_items as $media ) : ?>
							<?php
							$media_id  = $media['media_id'] ?? 0;
							$permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
							// Fallback to admin URL if permalink is not available.
							if ( empty( $permalink ) ) {
								$permalink = admin_url( 'admin.php?page=mvs-media&mvs_media_id=' . $media_id );
							}
							?>
							<tr>
								<td><?php echo esc_html( $media_id ); ?></td>
								<td>
									<a href="<?php echo esc_url( $permalink ); ?>" target="_blank">
										<?php echo esc_html( $media['title'] ?? '' ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $media['media_type'] ?? '' ); ?></td>
								<td><?php echo esc_html( $media['created_at'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php
					printf(
						// translators: %d: number of media items.
						esc_html__( 'This tag is linked to %d media item(s).', 'wpmediaverse' ),
						count( $media_items )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "Add New Tag" form.
	 */
	private function render_new_form(): void {
		$cancel_url  = admin_url( 'admin.php?page=mvs-tags' );
		$form_action = admin_url( 'admin.php?page=mvs-tags' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Add New Tag', 'wpmediaverse' ); ?></h1>
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="page-title-action"><?php esc_html_e( '&larr; Back to Tags', 'wpmediaverse' ); ?></a>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( 'create-tag', '_wpnonce' ); ?>
				<input type="hidden" name="form_action" value="save_new" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="tag-name"><?php esc_html_e( 'Name', 'wpmediaverse' ); ?></label></th>
						<td>
							<input type="text" name="tag_name" id="tag-name" class="regular-text" value="" required />
							<p class="description"><?php esc_html_e( 'The tag name as it appears on the site.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tag-slug"><?php esc_html_e( 'Slug', 'wpmediaverse' ); ?></label></th>
						<td>
							<input type="text" name="tag_slug" id="tag-slug" class="regular-text" value="" />
							<p class="description"><?php esc_html_e( 'The "nice" name for the tag URL. Usually lowercase. Leave blank to auto-generate from name.', 'wpmediaverse' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( esc_html__( 'Add New Tag', 'wpmediaverse' ), 'primary', 'submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show admin notices for success/error messages.
	 */
	public function show_admin_notices(): void {
		// Read-only success-flag reads; nonce is verified by the action that set the flag.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'mvs-tags' !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_GET['deleted'] ) ) {
			$deleted = absint( $_GET['deleted'] );
			if ( $deleted > 0 ) {
				$message = sprintf(
					// translators: %d: number of deleted tags.
					_n( '%d tag deleted.', '%d tags deleted.', $deleted, 'wpmediaverse' ),
					$deleted
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tag updated successfully.', 'wpmediaverse' ) . '</p></div>';
		}

		if ( isset( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tag created successfully.', 'wpmediaverse' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}

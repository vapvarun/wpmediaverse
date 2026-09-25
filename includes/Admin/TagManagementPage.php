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
	 * Action Scheduler hook that processes one batch of a tag merge.
	 *
	 * @since 2.6.0
	 */
	public const MERGE_HOOK = 'mvs_tag_merge_batch';

	/**
	 * Media items reassigned per merge batch.
	 */
	private const MERGE_BATCH_SIZE = 200;

	/**
	 * Above this many linked media items, a merge runs in the background
	 * instead of inline on the request.
	 */
	private const MERGE_LARGE_THRESHOLD = 200;

	/**
	 * Constructor. Registers hooks for handling tag actions.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'handle_bulk_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_single_delete' ) );
		add_action( 'admin_init', array( $this, 'handle_edit' ) );
		add_action( 'admin_init', array( $this, 'handle_create' ) );
		add_action( 'admin_init', array( $this, 'handle_merge' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( self::MERGE_HOOK, array( $this, 'process_merge_batch' ), 10, 3 );
	}

	/**
	 * Render the tag management page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		wp_enqueue_script(
			'mvs-admin-tag-management',
			MVS_PLUGIN_URL . 'assets/js/admin/tag-management.js',
			array( 'jquery' ),
			\WPMediaVerse\Core\Plugin::asset_version( 'assets/js/admin/tag-management.js' ),
			array( 'in_footer' => true )
		);

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

		// Get total count — must match the display query so pagination is
		// correct. wp_count_terms() runs a COUNT query instead of loading
		// every matching WP_Term object just to discard them for a number.
		$count_args = array(
			'taxonomy'   => 'mvs_tag',
			'hide_empty' => false,
		);
		if ( $search ) {
			$count_args['search'] = $search;
		}
		$total = (int) wp_count_terms( $count_args );

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
	 * @param string $param       Query arg carrying the page number. Defaults
	 *                            to 'paged' (the tag list); the linked-media
	 *                            list on the edit screen reuses this with
	 *                            'media_paged' so the two pagers don't collide.
	 */
	private function render_pagination( string $base_url, int $total, int $paged, int $total_pages, string $param = 'paged' ): void {

		if ( $total_pages <= 1 ) {
			return;
		}

		$page_links = array();

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			if ( $i === $paged ) {
				$page_links[] = '<span class="page-numbers current">' . esc_html( (string) $i ) . '</span>';
			} else {
				$page_links[] = '<a class="page-numbers" href="' . esc_url( add_query_arg( $param, $i, $base_url ) ) . '">' . esc_html( (string) $i ) . '</a>';
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

		// Cap + nonce pair inline. Both checks must succeed for bulk
		// deletion; the cap check is intentionally re-stated next to the
		// nonce verification to make authorization explicit at the action
		// site and to satisfy static analyzers that match per-block.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete tags.', 'wpmediaverse' ) );
		}
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
	 * Handle the "Merge Into Another Tag" form on the edit screen.
	 *
	 * Small tags merge inline; a tag over `MERGE_LARGE_THRESHOLD` linked
	 * items is handed to Action Scheduler instead — every prior merge ran
	 * `wp_set_object_terms()` + a repository write per linked item inline on
	 * this request, which timed out on a large tag.
	 */
	public function handle_merge(): void {
		if ( ! isset( $_GET['page'] ) || 'mvs-tags' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_POST['form_action'] ) || 'merge_tags' !== $_POST['form_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to merge tags.', 'wpmediaverse' ) );
		}

		$source_id = isset( $_POST['source_tag_id'] ) ? absint( $_POST['source_tag_id'] ) : 0;

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'mvs_merge_tags_' . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		$target_id = isset( $_POST['target_tag_id'] ) ? absint( $_POST['target_tag_id'] ) : 0;

		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id ) {
			wp_die( esc_html__( 'Choose two different tags to merge.', 'wpmediaverse' ) );
		}

		$source = get_term( $source_id, 'mvs_tag' );
		$target = get_term( $target_id, 'mvs_tag' );
		if ( ! $source || is_wp_error( $source ) || ! $target || is_wp_error( $target ) ) {
			wp_die( esc_html__( 'One of the selected tags no longer exists.', 'wpmediaverse' ) );
		}

		$large = (int) $source->count > self::MERGE_LARGE_THRESHOLD;

		if ( $large && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::MERGE_HOOK, array( $source_id, $target_id, 0 ), 'wpmediaverse' );
		} else {
			$this->process_merge_batch( $source_id, $target_id, 0 );
		}

		$redirect_url = add_query_arg(
			array( 'merged' => $large ? 'queued' : '1' ),
			admin_url( 'admin.php?page=mvs-tags' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Merge one batch of a source tag's media into the target tag.
	 *
	 * Action Scheduler callback (also called directly for small/synchronous
	 * merges). `term_relationships` rows are only ADDED to during a batch —
	 * the source term isn't deleted until the last batch — so offset-based
	 * paging across calls stays stable.
	 *
	 * @since 2.6.0
	 *
	 * @param int $source_id Source term id.
	 * @param int $target_id Target term id.
	 * @param int $offset    Rows already processed.
	 */
	public function process_merge_batch( int $source_id, int $target_id, int $offset = 0 ): void {
		$source = get_term( $source_id, 'mvs_tag' );
		if ( ! $source || is_wp_error( $source ) ) {
			return; // Already merged and deleted (or never existed) — nothing left to do.
		}
		$target = get_term( $target_id, 'mvs_tag' );
		if ( ! $target || is_wp_error( $target ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$media_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					WHERE tr.term_taxonomy_id = %d
					ORDER BY tr.object_id ASC
					LIMIT %d OFFSET %d",
					(int) $source->term_taxonomy_id,
					self::MERGE_BATCH_SIZE,
					$offset
				)
			)
		);

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		foreach ( $media_ids as $media_id ) {
			wp_set_object_terms( $media_id, $target_id, 'mvs_tag', true );
			$all_terms = wp_get_object_terms( $media_id, 'mvs_tag' ); // Not get_the_terms(): media are not posts.
			if ( $all_terms && ! is_wp_error( $all_terms ) ) {
				$repo->set( $media_id, 'tags', wp_json_encode( array_values( wp_list_pluck( $all_terms, 'name' ) ) ) );
			}
		}

		if ( count( $media_ids ) === self::MERGE_BATCH_SIZE ) {
			// More rows remain — advance the cursor rather than deleting the
			// source tag yet, so a huge merge never blocks one request/run.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::MERGE_HOOK, array( $source_id, $target_id, $offset + self::MERGE_BATCH_SIZE ), 'wpmediaverse' );
			} else {
				$this->process_merge_batch( $source_id, $target_id, $offset + self::MERGE_BATCH_SIZE );
			}
			return;
		}

		// Last batch — every linked item now also carries the target tag, so
		// the source tag is safe to remove.
		wp_delete_term( $source_id, 'mvs_tag' );

		/**
		 * Fires after a tag merge finishes (inline or background).
		 *
		 * Listeners get an empty affected-post list from this path.
		 * ponytail: the affected-post list isn't accumulated across batches
		 * (unbounded memory for a large merge is the exact thing batching
		 * exists to avoid) — listeners get an empty array here. Shares the
		 * hook name REST's `merge_tags` fires, which DOES carry the list for
		 * its (always-synchronous, so always small) merges.
		 *
		 * @since 2.6.0
		 *
		 * @param int   $source_id Source term id (deleted).
		 * @param int   $target_id Target term id (kept).
		 * @param int[] $posts     Empty for a background merge.
		 */
		do_action( 'mvs_tags_merged', $source_id, $target_id, array() );
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

		// Cap + nonce pair inline (see also bulk handler above for the
		// rationale). Both must pass to allow deletion.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'moderate_mvs_media' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete tags.', 'wpmediaverse' ) );
		}
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

		// Get media items with this tag, ONE PAGE at a time — a tag on a
		// large library can link thousands of items, and the edit screen
		// used to load every one of them (plus a get_all() call per item)
		// on a single request.
		$media_per_page = self::PER_PAGE;
		$media_paged    = isset( $_GET['media_paged'] ) ? max( 1, absint( $_GET['media_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$media_offset   = ( $media_paged - 1 ) * $media_per_page;

		global $wpdb;
		$media_total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.term_id = %d AND tt.taxonomy = 'mvs_tag'",
				$tag_id
			)
		);
		$media_total_pages = (int) ceil( $media_total / $media_per_page );

		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.term_id = %d AND tt.taxonomy = 'mvs_tag'
				ORDER BY tr.object_id ASC
				LIMIT %d OFFSET %d",
				$tag_id,
				$media_per_page,
				$media_offset
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
		$edit_url    = add_query_arg(
			array(
				'page'   => 'mvs-tags',
				'action' => 'edit',
				'tag_id' => $tag_id,
			),
			admin_url( 'admin.php' )
		);

		// Merge target choices — every other tag, name-ordered. Capped: this
		// is a picklist for a human, not a listing to page through.
		$merge_targets = get_terms(
			array(
				'taxonomy'   => 'mvs_tag',
				'hide_empty' => false,
				'exclude'    => array( $tag_id ),
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 200,
			)
		);
		if ( is_wp_error( $merge_targets ) ) {
			$merge_targets = array();
		}
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
				<?php $this->render_pagination( $edit_url, $media_total, $media_paged, $media_total_pages, 'media_paged' ); ?>
				<p class="description">
					<?php
					printf(
						// translators: %d: number of media items.
						esc_html__( 'This tag is linked to %d media item(s).', 'wpmediaverse' ),
						$media_total
					);
					?>
				</p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Merge Into Another Tag', 'wpmediaverse' ); ?></h2>
			<?php if ( empty( $merge_targets ) ) : ?>
				<p><?php esc_html_e( 'No other tags exist to merge into.', 'wpmediaverse' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					esc_html_e(
						'Every media item on this tag moves to the tag you choose below, and this tag is then deleted. Large tags run in the background so the page never times out.',
						'wpmediaverse'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $form_action ); ?>" onsubmit="return confirm( '<?php echo esc_js( __( 'Merge this tag into the selected tag? This cannot be undone.', 'wpmediaverse' ) ); ?>' );">
					<?php wp_nonce_field( 'mvs_merge_tags_' . $tag_id, '_wpnonce' ); ?>
					<input type="hidden" name="source_tag_id" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
					<input type="hidden" name="form_action" value="merge_tags" />
					<select name="target_tag_id" required>
						<option value=""><?php esc_html_e( 'Choose a tag…', 'wpmediaverse' ); ?></option>
						<?php foreach ( $merge_targets as $target ) : ?>
							<option value="<?php echo esc_attr( (string) $target->term_id ); ?>"><?php echo esc_html( $target->name ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( esc_html__( 'Merge Tag', 'wpmediaverse' ), 'secondary', 'submit', false ); ?>
				</form>
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

		if ( isset( $_GET['merged'] ) ) {
			$message = 'queued' === $_GET['merged']
				? __( 'Merge started in the background — this is a large tag, so it will finish over the next few minutes.', 'wpmediaverse' )
				: __( 'Tags merged successfully.', 'wpmediaverse' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}

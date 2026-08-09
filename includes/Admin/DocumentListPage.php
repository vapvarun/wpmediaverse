<?php
/**
 * Documents admin screen.
 *
 * The BACKEND entry point for the document library (Coding Rule 18). Documents
 * live in `mvs_media_index` beside media but never appear on a media surface,
 * which means without this screen a site owner had no way to see what their
 * members had uploaded — the frontend and the API could both reach documents
 * and the admin could not.
 *
 * Built for a large site from the start (big-site checklist): every listing is
 * paginated with `LIMIT`/`OFFSET`, the total comes from a dedicated `COUNT(*)`
 * rather than counting the page, filters and sorts run on indexed columns, and
 * the author column is batch-resolved in ONE `WP_User_Query` instead of a
 * `get_userdata()` per row.
 *
 * Markup lives in `templates/admin/documents.php` (Coding Rule 4).
 *
 * Build plan: P6.1, P6.2. Design: §11 (admin).
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Admin;

use WPMediaVerse\Core\DocumentTypes;
use WPMediaVerse\Core\MediaTypes;
use WPMediaVerse\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and manages documents in wp-admin.
 *
 * @since 2.4.0
 */
class DocumentListPage {

	/**
	 * Page slug.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	public const SLUG = 'mvs-documents';

	/**
	 * Rows per page.
	 *
	 * @since 2.4.0
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Render the screen.
	 *
	 * @since 2.4.0
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wpmediaverse' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters on a GET screen.
		$mvs_page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$mvs_doc_type = isset( $_GET['doc_type'] ) ? sanitize_key( wp_unslash( $_GET['doc_type'] ) ) : '';
		$mvs_privacy  = isset( $_GET['privacy'] ) ? sanitize_key( wp_unslash( $_GET['privacy'] ) ) : '';
		$mvs_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$mvs_orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$mvs_order    = isset( $_GET['order'] ) && 'asc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// An unknown type would silently widen the list back to everything, so
		// it is dropped rather than passed through.
		if ( '' !== $mvs_doc_type && ! DocumentTypes::is_known( $mvs_doc_type ) ) {
			$mvs_doc_type = '';
		}

		$mvs_error  = '';
		$mvs_result = array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
		);

		// Coding Rule 11: the error state is a real branch, not an assumption
		// that the query always works.
		try {
			$mvs_result = Plugin::container()->get( 'media_repository' )->admin_documents(
				array(
					'per_page' => self::PER_PAGE,
					'page'     => $mvs_page,
					'doc_type' => $mvs_doc_type,
					'privacy'  => $mvs_privacy,
					'search'   => $mvs_search,
					'orderby'  => $mvs_orderby,
					'order'    => $mvs_order,
				)
			);
		} catch ( \Throwable $e ) {
			$mvs_error = __( 'The document list could not be loaded.', 'wpmediaverse' );
		}

		$mvs_items   = $mvs_result['items'];
		$mvs_total   = $mvs_result['total'];
		$mvs_pages   = $mvs_result['pages'];
		$mvs_authors = self::authors_for( $mvs_items );
		$mvs_types   = DocumentTypes::ALL;
		$mvs_notice  = self::consume_notice();

		require MVS_PLUGIN_DIR . 'templates/admin/documents.php';
	}

	/**
	 * Resolve every author on the page in ONE query.
	 *
	 * `get_userdata()` per row is the N+1 the big-site checklist exists to
	 * prevent: 20 rows would be 20 queries, and it is the kind of cost that only
	 * shows up on the site that already has a problem.
	 *
	 * @since 2.4.0
	 *
	 * @param array $items Document rows.
	 * @return array<int, string> User id => display name.
	 */
	private static function authors_for( array $items ): array {
		$ids = array_values( array_unique( array_filter( array_map( static fn( $row ) => (int) ( $row['post_author'] ?? 0 ), $items ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$names = array();
		foreach ( get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		) as $user ) {
			$names[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $names;
	}

	/**
	 * Whether an id belongs to the document library.
	 *
	 * Every action on this screen passes through here. A media id must never be
	 * actionable from the Documents page: without this,
	 * `?action=delete&media_id=<a photo>` would delete a photo from a screen
	 * that never listed it, which is the same containment promise the REST
	 * routes make, made here too.
	 *
	 * Deliberately public and deliberately named: a guard that only exists as an
	 * inline condition inside a switch is a guard nothing can test.
	 *
	 * @since 2.4.0
	 *
	 * @param int $media_id Candidate id.
	 * @return bool
	 */
	public static function is_document( int $media_id ): bool {
		if ( $media_id <= 0 ) {
			return false;
		}

		$type = (string) Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' );

		return in_array( $type, MediaTypes::DOCUMENT_LIBRARY, true );
	}

	/**
	 * Handle row and bulk actions.
	 *
	 * Runs on the page's `load-` hook so redirects happen before any output.
	 *
	 * @since 2.4.0
	 */
	public static function handle_actions(): void {
		// One capability gates every action on this screen, so it is checked
		// once, here, and each case below carries its own nonce.
		//
		// Deliberately NOT repeated per case the way MediaListPage does it:
		// that screen's entry gate also admits `moderate_mvs_media`, so its
		// inline `manage_options` checks genuinely narrow individual actions.
		// Here they would be provably dead code — PHPStan says so — and a dead
		// check reads like protection while protecting nothing.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce below.
		if ( isset( $_POST['do_bulk'] ) ) {
			self::handle_bulk();
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified per action below.
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$media_id = isset( $_GET['media_id'] ) ? (int) $_GET['media_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $action || $media_id <= 0 ) {
			return;
		}

		$repo = Plugin::container()->get( 'media_repository' );

		if ( ! self::is_document( $media_id ) ) {
			return;
		}

		switch ( $action ) {
			case 'trash':
				check_admin_referer( 'mvs_trash_document_' . $media_id );
				$repo->set( $media_id, 'status', 'trash' );
				self::redirect_with_notice( 1, 'trashed' );
				break;

			case 'restore':
				check_admin_referer( 'mvs_restore_document_' . $media_id );
				$repo->set( $media_id, 'status', 'publish' );
				self::redirect_with_notice( 1, 'restored' );
				break;

			case 'delete':
				check_admin_referer( 'mvs_delete_document_' . $media_id );
				$repo->delete_cascade( $media_id );
				self::redirect_with_notice( 1, 'deleted' );
				break;
		}
	}

	/**
	 * Apply a bulk action.
	 *
	 * @since 2.4.0
	 */
	private static function handle_bulk(): void {
		// Reached only through handle_actions(), which has already gated on the
		// capability; the nonce is what this method adds.
		check_admin_referer( 'mvs_documents_bulk' );

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['document'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['document'] ) ) : array();
		$ids    = array_values( array_filter( $ids ) );

		if ( '' === $action || ! $ids ) {
			return;
		}

		$repo    = Plugin::container()->get( 'media_repository' );
		$applied = 0;

		foreach ( $ids as $id ) {
			// A bulk POST is just as able to carry a photo's id as a hand-edited
			// row link, so it gets the same guard.
			if ( ! self::is_document( $id ) ) {
				continue;
			}

			switch ( $action ) {
				case 'trash':
					$repo->set( $id, 'status', 'trash' );
					++$applied;
					break;

				case 'restore':
					$repo->set( $id, 'status', 'publish' );
					++$applied;
					break;

				case 'delete':
					$repo->delete_cascade( $id );
					++$applied;
					break;
			}
		}

		self::redirect_with_notice( $applied, 'trash' === $action ? 'trashed' : ( 'restore' === $action ? 'restored' : 'deleted' ) );
	}

	/**
	 * Redirect back to the list carrying a result the next render can show.
	 *
	 * The count travels in the URL rather than a transient: a per-request result
	 * keyed by nothing is exactly the sort of thing that shows the wrong number
	 * to the wrong admin on a busy site.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $count   How many rows the action applied to.
	 * @param string $outcome trashed|restored|deleted.
	 */
	private static function redirect_with_notice( int $count, string $outcome ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::SLUG,
					'mvs_done'    => $count,
					'mvs_outcome' => $outcome,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Read the post-action notice out of the URL.
	 *
	 * @since 2.4.0
	 *
	 * @return string Human-readable notice, or ''.
	 */
	private static function consume_notice(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only, no side effects.
		$count   = isset( $_GET['mvs_done'] ) ? (int) $_GET['mvs_done'] : 0;
		$outcome = isset( $_GET['mvs_outcome'] ) ? sanitize_key( wp_unslash( $_GET['mvs_outcome'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $count <= 0 ) {
			return '';
		}

		switch ( $outcome ) {
			case 'trashed':
				/* translators: %s: number of documents. */
				return sprintf( _n( '%s document moved to trash.', '%s documents moved to trash.', $count, 'wpmediaverse' ), number_format_i18n( $count ) );

			case 'restored':
				/* translators: %s: number of documents. */
				return sprintf( _n( '%s document restored.', '%s documents restored.', $count, 'wpmediaverse' ), number_format_i18n( $count ) );

			case 'deleted':
				/* translators: %s: number of documents. */
				return sprintf( _n( '%s document deleted.', '%s documents deleted.', $count, 'wpmediaverse' ), number_format_i18n( $count ) );
		}

		return '';
	}
}

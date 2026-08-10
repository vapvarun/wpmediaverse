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
	 * Who may open this screen.
	 *
	 * A META capability, mapped in `Core\Plugin`, not a primitive one. It
	 * resolves to `manage_mvs_documents` for anyone holding it and falls back to
	 * `manage_options` for everyone else — so it ADMITS a role the owner
	 * delegated document administration to, without ever locking out an
	 * administrator whose site never ran the grant.
	 *
	 * The whole point of the named capability is that document administration
	 * can be handed to a role that must NOT have `manage_options`. Gating this
	 * screen on `manage_options` made the capability decorative: it appeared in
	 * the role matrix, ticking it changed nothing, and the delegation it exists
	 * for was impossible.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	public const CAP = 'mvs_manage_documents_screen';

	/**
	 * Render the screen.
	 *
	 * @since 2.4.0
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wpmediaverse' ) );
		}

		// A sub-view on the same page rather than a submenu of its own —
		// `MediaListPage` already establishes the pattern, and a new submenu
		// would be one more slug for the admin IA work to reconcile.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( isset( $_GET['view'] ) && 'single' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) {
			self::render_single();
			return;
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
	 * Render one document: what it is, and what can be corrected about it.
	 *
	 * The list screen offered Trash and Delete permanently and nothing else —
	 * every action on it destroyed something, and an owner could not so much as
	 * see what a document WAS without leaving wp-admin for the front end.
	 *
	 * A sub-view rather than a submenu, following `MediaListPage`.
	 *
	 * @since 2.4.0
	 */
	private static function render_single(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$mvs_id = isset( $_GET['media_id'] ) ? (int) $_GET['media_id'] : 0;

		// The SAME guard the destructive actions use. Without it this screen is
		// a second, unguarded way to edit a photo — it writes the title, slug
		// and privacy of whatever id it is handed.
		if ( $mvs_id <= 0 || ! self::is_document( $mvs_id ) ) {
			wp_die(
				esc_html__( 'That document could not be found.', 'wpmediaverse' ),
				'',
				array( 'back_link' => true )
			);
		}

		$mvs_repo = Plugin::container()->get( 'media_repository' );
		$mvs_row  = $mvs_repo->get_all( $mvs_id );

		if ( ! $mvs_row ) {
			wp_die(
				esc_html__( 'That document could not be found.', 'wpmediaverse' ),
				'',
				array( 'back_link' => true )
			);
		}

		$mvs_author    = (int) ( $mvs_row['post_author'] ?? 0 );
		$mvs_user      = $mvs_author ? get_userdata( $mvs_author ) : null;
		$mvs_trashed   = 'publish' !== (string) ( $mvs_row['status'] ?? 'publish' );
		$mvs_permalink = $mvs_trashed ? '' : (string) $mvs_repo->get_permalink( $mvs_id );
		$mvs_notice    = self::consume_notice();

		$mvs_tags = wp_get_object_terms( $mvs_id, 'mvs_tag', array( 'fields' => 'names' ) );
		$mvs_tags = is_wp_error( $mvs_tags ) ? array() : $mvs_tags;

		/**
		 * Filters extra panels on the single-document admin screen.
		 *
		 * Pro answers with the things only Pro can serve — a preview, a download
		 * link, where the document sits on its owner's drive. Free must not
		 * learn how a document is streamed to build this screen.
		 *
		 * @since 2.4.0
		 *
		 * @param string $html     Markup, already escaped.
		 * @param int    $media_id Document id.
		 * @param array  $row      The index row.
		 */
		$mvs_extra = (string) apply_filters( 'mvs_document_admin_panels', '', $mvs_id, $mvs_row );

		require MVS_PLUGIN_DIR . 'templates/admin/document-single.php';
	}

	/**
	 * Save an edit from the single-document screen.
	 *
	 * Writes through the SAME three calls the REST update path uses —
	 * `set_many()`, `generate_unique_slug()` and `wp_set_object_terms()` — so
	 * there is one implementation of what editing a document means, and this
	 * screen cannot drift from what the API does.
	 *
	 * @since 2.4.0
	 *
	 * @param int $media_id Document id.
	 */
	private static function handle_single_save( int $media_id ): void {
		check_admin_referer( 'mvs_save_document_' . $media_id );

		if ( ! self::is_document( $media_id ) ) {
			return;
		}

		$repo = Plugin::container()->get( 'media_repository' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$title       = isset( $_POST['mvs_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mvs_title'] ) ) : '';
		$slug        = isset( $_POST['mvs_slug'] ) ? sanitize_title( wp_unslash( $_POST['mvs_slug'] ) ) : '';
		$description = isset( $_POST['mvs_description'] ) ? wp_kses_post( wp_unslash( $_POST['mvs_description'] ) ) : '';
		$privacy     = isset( $_POST['mvs_privacy'] ) ? sanitize_key( wp_unslash( $_POST['mvs_privacy'] ) ) : '';
		$tags_raw    = isset( $_POST['mvs_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['mvs_tags'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// An empty title is REFUSED, not stored. A document with no name is
		// unfindable in every listing at once, and the REST path already
		// answers 400 for the same submission.
		if ( '' === trim( $title ) ) {
			self::redirect_with_notice( 1, 'empty_title', $media_id );
			return;
		}

		$update = array(
			'title'       => $title,
			'description' => $description,
		);

		// THE SLUG IS NEVER REGENERATED FROM THE TITLE. A member correcting a
		// typo would otherwise silently break every link anybody holds to the
		// document. It changes only when a new one is typed, and then it is
		// made unique against every other row.
		if ( '' !== $slug && $slug !== (string) $repo->get( $media_id, 'slug' ) ) {
			$update['slug'] = $repo->generate_unique_slug( $slug, $media_id );
		}

		if ( in_array( $privacy, array( 'public', 'members', 'private' ), true ) ) {
			$update['privacy'] = $privacy;
		}

		$repo->set_many( $media_id, $update );

		$tags = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );
		wp_set_object_terms( $media_id, $tags, 'mvs_tag' );

		self::redirect_with_notice( 1, 'saved', $media_id );
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
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce below.
		if ( isset( $_POST['do_bulk'] ) ) {
			self::handle_bulk();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside.
		if ( isset( $_POST['mvs_save_document'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside.
			self::handle_single_save( isset( $_POST['media_id'] ) ? (int) $_POST['media_id'] : 0 );
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
	private static function redirect_with_notice( int $count, string $outcome, int $media_id = 0 ): void {
		$args = array(
			'page'        => self::SLUG,
			'mvs_done'    => $count,
			'mvs_outcome' => $outcome,
		);

		// Editing returns to the document being edited, not to the list. A save
		// that bounces you back to page one of a filtered list has thrown away
		// where you were, and the next edit starts by finding the row again.
		if ( $media_id > 0 ) {
			$args['view']     = 'single';
			$args['media_id'] = $media_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
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

			case 'saved':
				return __( 'Document updated.', 'wpmediaverse' );

			case 'empty_title':
				// A refusal, not a success. It says which field and why, because
				// "could not save" leaves the member hunting for the reason.
				return __( 'A document needs a title, so nothing was saved.', 'wpmediaverse' );
		}

		return '';
	}

	/**
	 * The CSS class the last outcome should wear.
	 *
	 * A refusal that renders in the green success box tells the member the
	 * opposite of what happened, which is worse than no notice at all.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	public static function notice_class(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$outcome = isset( $_GET['mvs_outcome'] ) ? sanitize_key( wp_unslash( $_GET['mvs_outcome'] ) ) : '';

		return 'empty_title' === $outcome ? 'notice-error' : 'notice-success';
	}
}

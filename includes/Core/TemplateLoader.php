<?php
/**
 * Template loader.
 *
 * Serves media pages via rewrite rules + template_redirect.
 * Albums/Collections still use CPT template filters.
 * Themes can override by placing templates in a wpmediaverse/ directory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Handles template loading with theme override support.
 */
class TemplateLoader {

	/**
	 * Template directory name in themes.
	 *
	 * @var string
	 */
	const THEME_DIR = 'wpmediaverse';

	/**
	 * Initialize template hooks.
	 */
	public function init(): void {
		// Rewrite rules for media pages (no CPT needed).
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		// Serve media templates via template_redirect.
		add_action( 'template_redirect', array( $this, 'load_media_templates' ), 5 );

		// Prevent WordPress from redirecting our /media/{slug}/ URLs to
		// attachment file URLs when slug matches a WP attachment post.
		add_filter( 'redirect_canonical', array( $this, 'prevent_attachment_redirect' ), 10, 2 );

		// Stop core soft-404ing our virtual routes before we render them.
		add_filter( 'pre_handle_404', array( $this, 'prevent_virtual_route_404' ), 10, 2 );

		// CPT template filters — albums and collections only.
		add_filter( 'single_template', array( $this, 'load_single_template' ) );
		add_filter( 'archive_template', array( $this, 'load_archive_template' ) );
		add_filter( 'taxonomy_template', array( $this, 'load_taxonomy_template' ) );

		// Own the page layout for the pages THIS plugin created, so a member's
		// media library is not rendered beside the theme's blog sidebar. Each of
		// the three Wbcom themes removes the sidebar its own way — we use the
		// theme's mechanism, not a bare wrapper that discards the theme's shell:
		//
		// - BuddyX / BuddyX-Pro map the page to a no-sidebar page template.
		// That is a persisted page attribute (visible + editable in the block
		// editor as "Template: Page No Sidebar"), synced below.
		// - Reign drives layout from post-meta (force_reign_full_width).
		// - Any other theme falls through to use_app_template()'s bare shell.

		// Keep the page-template attribute in step with the active theme: stamp it
		// when switching to a theme that ships a no-sidebar template, clear a stale
		// one when switching to a theme that doesn't. Also runs once on upgrade to
		// backfill pages created before this existed.
		add_action( 'after_switch_theme', array( self::class, 'sync_app_page_templates' ) );
		add_action( 'init', array( self::class, 'maybe_backfill_app_page_templates' ), 20 );

		// Fallback only: a theme with no no-sidebar page template and not Reign.
		add_filter( 'template_include', array( $this, 'use_app_template' ), 99 );

		// Reign removes the sidebar via post-meta, not a page template. Force its
		// full-width layout for our app pages the same way Reign forces it for
		// FluentCart pages (inc/fluentcart-support.php). No-op off Reign.
		if ( self::is_reign_theme() ) {
			add_filter( 'get_post_metadata', array( $this, 'force_reign_full_width' ), 10, 4 );
		}

		// Body class signal for BuddyX and other themes.
		add_action( 'wp', array( $this, 'maybe_add_mvs_body_class' ) );

		// Populate the client-nav deny-list with routes that require a full-load.
		add_filter( 'wpmediaverse_client_nav_deny_paths', array( $this, 'add_deny_paths' ), 10, 1 );
	}

	/**
	 * Append Free plugin routes to the client-nav deny-list.
	 *
	 * These are URL path prefixes. The navigate action prefix-matches
	 * `link.pathname` against this list and falls back to a full browser
	 * navigation for matched paths (needed for routes that use polling,
	 * typeahead, or file-upload on first load).
	 *
	 * Merged into the incoming $paths array; never overwrites it.
	 * Pro appends competition routes on top via a higher-priority callback.
	 *
	 * @param string[] $paths Existing deny-path prefixes.
	 * @return string[]
	 */
	public function add_deny_paths( array $paths ): array {
		// Fixed rewrite-based routes that must always full-load.
		$fixed = array(
			'/messages/',       // Messaging: polling, typeahead, file-upload.
			'/media/edit-profile/', // Profile-edit composer form.
			'/album/',          // Album CPT single (rewrite slug = 'album').
		);

		// Mapped-page routes: resolve the WP page permalink to a path so
		// admin-renamed slugs stay correct without touching the deny-list.
		$mapped_options = array(
			'mvs_page_upload',
			'mvs_page_dashboard',
		);

		$mapped = array();
		foreach ( $mapped_options as $option ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id <= 0 ) {
				continue;
			}
			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				continue;
			}
			$path = wp_parse_url( $permalink, PHP_URL_PATH );
			if ( $path && '/' !== $path ) {
				$mapped[] = $path;
			}
		}

		$merged = array_merge( $paths, $fixed, $mapped );
		// Deduplicate and remove empties.
		$merged = array_values( array_unique( array_filter( $merged ) ) );

		return $merged;
	}

	/**
	 * Register rewrite rules for media pages.
	 *
	 * Routes:
	 *   /media/                          — Explore (archive)
	 *   /media/page/{n}/                 — Explore paginated
	 *   /media/{slug}/                   — Single media item (by slug)
	 *   /media/{id}/                     — Single media item (by numeric ID)
	 *   /media/@{username}/              — User profile media
	 *   /media/@{username}/page/{n}/     — User profile paginated
	 *   /media/edit-profile/             — Profile edit
	 */
	public function register_rewrite_rules(): void {
		// The member's documents, as a real path.
		//
		// `/my-media/?drive=my-drive&folder=69#documents` was three mechanisms
		// doing one job: a query var left over from when the drive lived on the
		// public page, a raw database id, and a hash the server never sees. A
		// folder id in a URL is also a number a member can edit — the guard
		// refuses it, but offering it at all invites the attempt.
		//
		// `/my-media/documents/contracts/2026/` instead: the path IS the folder
		// path, it survives being shared, and the server can render the right
		// folder on first paint rather than after JavaScript runs.
		$mvs_dashboard = (int) get_option( 'mvs_page_dashboard', 0 );

		if ( $mvs_dashboard ) {
			$mvs_dashboard_slug = get_post_field( 'post_name', $mvs_dashboard );

			if ( $mvs_dashboard_slug ) {
				add_rewrite_rule(
					'^' . preg_quote( (string) $mvs_dashboard_slug, '/' ) . '/documents/page/([0-9]+)/?$',
					'index.php?pagename=' . $mvs_dashboard_slug . '&mvs_doc_view=1&mvs_doc_page=$matches[1]',
					'top'
				);
				add_rewrite_rule(
					'^' . preg_quote( (string) $mvs_dashboard_slug, '/' ) . '/documents/(.+?)/page/([0-9]+)/?$',
					'index.php?pagename=' . $mvs_dashboard_slug . '&mvs_doc_view=1&mvs_doc_path=$matches[1]&mvs_doc_page=$matches[2]',
					'top'
				);
				add_rewrite_rule(
					'^' . preg_quote( (string) $mvs_dashboard_slug, '/' ) . '/documents/(.+?)/?$',
					'index.php?pagename=' . $mvs_dashboard_slug . '&mvs_doc_view=1&mvs_doc_path=$matches[1]',
					'top'
				);
				add_rewrite_rule(
					'^' . preg_quote( (string) $mvs_dashboard_slug, '/' ) . '/documents/?$',
					'index.php?pagename=' . $mvs_dashboard_slug . '&mvs_doc_view=1',
					'top'
				);

				// Every other section gets a URL too. A section that exists only
				// as JavaScript state cannot be linked, bookmarked or returned
				// to — and "send me your albums" is a thing people say.
				//
				// Built FROM THE REGISTRY rather than from a literal list. The
				// literal said `media|albums|favorites|collections|challenges|
				// battles|tournaments`, while `DashboardSections::url()` builds
				// `/my-media/<slug>/` for whatever is declared — so declaring a
				// section through the documented filter produced a rail item
				// linking to a 404, and the registry's whole promise is that
				// declaring a section is all you do. `documents` only escaped it
				// by owning separate rewrites for its folder paths.
				//
				// Rewrites are cached in the `rewrite_rules` option, so a plugin
				// that adds a section must flush on activation — as Pro already
				// does for its own document paths.
				$mvs_section_slugs = array_map(
					static fn( $slug ) => preg_quote( $slug, '/' ),
					\WPMediaVerse\Core\DashboardSections::slugs()
				);

				if ( $mvs_section_slugs ) {
					add_rewrite_rule(
						'^' . preg_quote( (string) $mvs_dashboard_slug, '/' ) . '/(' . implode( '|', $mvs_section_slugs ) . ')/?$',
						'index.php?pagename=' . $mvs_dashboard_slug . '&mvs_section=$matches[1]',
						'top'
					);
				}
			}
		}

		// Media archive (explore).
		add_rewrite_rule(
			'^media/page/([0-9]+)/?$',
			'index.php?mvs_media_archive=1&paged=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^media/?$',
			'index.php?mvs_media_archive=1',
			'top'
		);

		// Profile edit — must be before slug rule to avoid being caught as a media slug.
		add_rewrite_rule(
			'^media/edit-profile/?$',
			'index.php?mvs_edit_profile=1',
			'top'
		);

		// User profile: /media/@{username}/.
		add_rewrite_rule(
			'^media/@([^/]+)/page/([0-9]+)/?$',
			'index.php?mvs_profile_user=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^media/@([^/]+)/?$',
			'index.php?mvs_profile_user=$matches[1]',
			'top'
		);

		// Single media by slug (non-numeric) — must be LAST to avoid catching special routes.
		// Exclude reserved sub-paths (battles, challenges, tournaments, leaderboard, etc.)
		// so Pro and third-party plugins can register their own /media/{feature}/ pages.
		$reserved = apply_filters(
			'mvs_reserved_media_paths',
			array(
				'battles',
				'challenges',
				'tournaments',
				'leaderboard',
				'edit-profile',
				'page',
			)
		);
		$exclude  = implode( '|', array_map( static fn( $p ) => preg_quote( $p, '#' ), $reserved ) );
		add_rewrite_rule(
			'^media/(?!' . $exclude . '/)([a-z0-9][a-z0-9_\-]*)/?$',
			'index.php?mvs_media_slug=$matches[1]',
			'top'
		);
	}

	/**
	 * Register custom query variables.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		// The member's documents view and the folder path within it.
		$vars[] = 'mvs_doc_view';
		$vars[] = 'mvs_doc_path';
		$vars[] = 'mvs_doc_page';
		$vars[] = 'mvs_section';

		$vars[] = 'mvs_media_archive';
		$vars[] = 'mvs_media_slug';
		$vars[] = 'mvs_profile_user';
		$vars[] = 'mvs_edit_profile';
		return $vars;
	}

	/**
	 * Serve media templates via template_redirect.
	 *
	 * Handles single media, explore archive, user profile, and profile edit.
	 */
	/**
	 * Prevent WordPress canonical redirect on MVS media URLs.
	 *
	 * When a media slug matches a WP attachment post, WordPress tries to
	 * redirect /media/{slug}/ to the raw file URL. This filter cancels
	 * that redirect when our custom query vars are active.
	 *
	 * @param string $redirect_url The canonical URL WordPress wants to redirect to.
	 * @param string $requested_url The original requested URL.
	 * @return string|false The redirect URL, or false to cancel.
	 */
	public function prevent_attachment_redirect( $redirect_url, $requested_url ) {
		if ( $this->is_mvs_virtual_route() ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Is the current request one of our virtual routes?
	 *
	 * @return bool
	 */
	private function is_mvs_virtual_route(): bool {
		return (bool) (
			get_query_var( 'mvs_media_slug' )
			|| get_query_var( 'mvs_media_archive' )
			|| get_query_var( 'mvs_profile_user' )
			|| get_query_var( 'mvs_edit_profile' )
		);
	}

	/**
	 * Stop WordPress marking our virtual routes as 404 before we render them.
	 *
	 * `WP::handle_404()` runs on the `wp` action and 404s any request whose main
	 * query found no posts. Our virtual pages have no posts by design — the
	 * content comes from our own tables — so every page beyond the first
	 * (`/media/page/2/`, `/media/@user/page/2/`) was served as a soft-404: the
	 * correct page, correct title, correct items, under HTTP 404.
	 *
	 * render_template() already tried to heal this by calling status_header(200),
	 * but it runs on template_redirect — long after `wp` — and its "never
	 * downgrade an explicit 4xx" guard could not tell core's incidental 404 from
	 * a deliberate 403 set by the members-only gate, so it correctly refused to
	 * touch either. Preventing the 404 at source fixes the cause instead of
	 * fighting the symptom, and leaves that guard doing exactly its intended job:
	 * a real 403 still survives to the response.
	 *
	 * Soft-404s deindex paginated archives and make caches and CDNs skip the
	 * page, so on a 60-item library only page 1 was reachable to search engines.
	 * Basecamp: 2.3.0 smoke F1.
	 *
	 * @since 2.3.0
	 *
	 * @param bool      $preempt  Whether to short-circuit core's 404 handling.
	 * @param \WP_Query $wp_query The query that triggered the check.
	 * @return bool
	 */
	public function prevent_virtual_route_404( $preempt, $wp_query ) {
		if ( $preempt ) {
			return $preempt;
		}

		// Only the main query, and only our own routes.
		if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_main_query() ) {
			return $preempt;
		}

		return $this->is_mvs_virtual_route() ? true : $preempt;
	}

	public function load_media_templates(): void {
		// Gate single album/collection privacy here (template_redirect@5), BEFORE
		// the theme renders. The in-template gates in album.php / collection.php
		// run after get_header(), so a members/private album's or collection's
		// document <title> and breadcrumbs still leaked its name to non-owners.
		// Rendering the branded 404 now — before the theme sets the title — closes
		// that. The in-template gates stay as defense-in-depth. Basecamp 10073499554.
		if ( is_singular( array( 'mvs_album', 'mvs_collection' ) ) ) {
			$mvs_cpt_id = get_queried_object_id();
			if ( $mvs_cpt_id && ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $mvs_cpt_id, get_current_user_id() ) ) {
				$mvs_ctx = ( 'mvs_collection' === get_post_type( $mvs_cpt_id ) ) ? 'collection' : 'album';
				self::render_branded_404( $mvs_ctx, (string) get_post_field( 'post_name', $mvs_cpt_id ) );
				return; // render_branded_404() exits; return keeps control flow explicit.
			}
		}

		// Single media by slug.
		$slug = get_query_var( 'mvs_media_slug' );
		if ( $slug ) {
			$this->serve_single_media( $slug );
			return;
		}

		// Media archive (explore) — via rewrite rule or mapped page.
		$explore_page_id = (int) get_option( 'mvs_page_explore', 0 );
		if ( get_query_var( 'mvs_media_archive' ) || ( $explore_page_id && is_page( $explore_page_id ) ) ) {
			$this->serve_media_archive();
			return;
		}

		// User profile.
		$profile_user = get_query_var( 'mvs_profile_user' );
		if ( $profile_user ) {
			$this->serve_user_profile( $profile_user );
			return;
		}

		// Profile edit.
		if ( get_query_var( 'mvs_edit_profile' ) ) {
			$this->serve_profile_edit();
			return;
		}
	}

	/**
	 * Render a full MVS virtual page and stop.
	 *
	 * Our virtual pages (explore archive, profile, single media, profile edit)
	 * render real content, so they must return HTTP 200 even when WordPress's
	 * main query is empty - e.g. /media/page/2/ where paged > 1 leaves the core
	 * query with no posts and WP would otherwise emit a soft-404. A soft-404
	 * deindexes paginated archives and makes page caches / CDNs skip the page.
	 *
	 * @param string $template Absolute path to the located template file.
	 */
	private function render_template( string $template ): void {
		// Heal WP's soft-404 default to 200 for our virtual pages — EXCEPT when
		// the caller already set an explicit error status (e.g. the members-only
		// access gate's 403 in serve_single_media). Never downgrade a 4xx to 200,
		// or the gate would advertise itself as a normal indexable page.
		$current_status = http_response_code();
		if ( ! is_int( $current_status ) || $current_status < 400 ) {
			status_header( 200 );
		}
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$GLOBALS['wp_query']->is_404 = false;
		}
		include $template;
		exit;
	}

	/**
	 * Serve a single media item page.
	 *
	 * @param string $slug Media slug (or numeric ID).
	 */
	private function serve_single_media( string $slug ): void {
		// Through the repository — Rule 7. Same two lookups, same publish gate.
		$repo = Plugin::container()->get( 'media_repository' );

		// Look up by slug first, then by numeric ID.
		if ( ctype_digit( $slug ) ) {
			$media = $repo->get_batch( array( (int) $slug ) )[ (int) $slug ] ?? null;

			// `get_batch()` does not filter status, and this route must not serve
			// a trashed item as a public page.
			if ( $media && 'publish' !== (string) ( $media['status'] ?? '' ) ) {
				$media = null;
			}
		} else {
			$media = $repo->get_by_slug( $slug );
		}

		if ( ! $media ) {
			self::render_branded_404( 'media', $slug );
			return;
		}

		// Read once — four branches below ask what this is, and a fifth (the
		// redirect filter) now tells a host so it can answer differently for a
		// document than for a photo.
		$mvs_media_type = (string) ( $media['media_type'] ?? '' );

		// A document, on a site with documents switched off, is not a page.
		//
		// Found in the browser: with the master toggle unticked the drive tab and
		// the admin screen went away, and this page carried on rendering a
		// document card with a Download button — pointing at a delivery route
		// that is one of the surfaces the switch takes down, so the button 404'd
		// while the page around it looked fine. Answering 404 for the whole page
		// is the honest version of what the owner asked for, and it is the same
		// answer a Free-only site has always given.
		//
		// The row is untouched. Switching documents back on brings the page back.
		if (
			in_array( $mvs_media_type, array( 'document', 'legacy_document' ), true )
			&& ! \WPMediaVerse\Core\Plugin::documents_enabled()
		) {
			self::render_branded_404( 'media', $slug );
			return;
		}

		/**
		 * Let a host redirect single-media URLs somewhere else instead of rendering
		 * the standalone page. BuddyNext uses this to send /media/{slug}/ to the
		 * activity the media was posted in, so media lives in the community feed
		 * rather than as a separate public page. Return '' (the default, and always
		 * the case for standalone WPMediaVerse) to render the native single page.
		 *
		 * @since 2.4.0 The media type is passed so a host can answer differently
		 *              for a document than for a photo.
		 *
		 * @param string $redirect_url Target URL, or '' to render the native page.
		 * @param int    $media_id     The media item's id.
		 * @param string $slug         The requested slug (or numeric id).
		 * @param string $media_type   image|video|audio|document|legacy_document.
		 */
		$redirect_url = (string) apply_filters( 'mvs_single_media_redirect', '', (int) $media['media_id'], (string) $slug, $mvs_media_type );

		// A DOCUMENT IS NOT A FEED OBJECT, so it does not follow a redirect meant
		// for one. The filter above predates documents and was written for the
		// community-feed case in its own docblock: send a photo to the activity it
		// was posted in rather than to a separate public page. A document has no
		// such activity to go to — most are uploaded straight into a drive, and a
		// private one cannot be posted to a feed at all — so a host resolving the
		// redirect walks its fallback chain and lands the viewer on a photo surface
		// that shows no documents (Basecamp #10194229966).
		//
		// This plugin's own surfaces are what break: the drive rows, the Explore
		// Documents listing and Shared-with-me all link to /media/{slug}/. Free
		// generates the link and then the redirect dead-ends it, so every document
		// in the product is unopenable from the place the product sends you. That
		// is broken regardless of which consumer is installed, which is why the
		// default changes here rather than in any one host.
		//
		// The escape hatch keeps Production Rule 3: a host that genuinely wants
		// documents redirected re-asserts it and gets exactly the old behaviour.
		if ( '' !== $redirect_url && in_array( $mvs_media_type, array( 'document', 'legacy_document' ), true ) ) {
			/**
			 * Whether a document permalink may be redirected away from its own page.
			 *
			 * Default false: a document renders its own page. Answer true to restore
			 * the pre-2.4.0 behaviour of following `mvs_single_media_redirect` for
			 * documents as well as for media.
			 *
			 * @since 2.4.0
			 *
			 * @param bool   $allow        Default false.
			 * @param int    $media_id     The document's id.
			 * @param string $redirect_url The target the redirect filter resolved.
			 */
			if ( ! apply_filters( 'mvs_redirect_documents', false, (int) $media['media_id'], $redirect_url ) ) {
				$redirect_url = '';
			}
		}

		if ( '' !== $redirect_url ) {
			wp_safe_redirect( $redirect_url, 301 );
			exit;
		}

		// Check privacy. A denied viewer gets the SAME single-media template and
		// container — the template swaps the media itself for a "log in to view"
		// message in the media slot and hides the social + comment sections. No
		// redirect, no separate 404/gate page. The file URL, poster, OG image and
		// download are never exposed to a denied viewer (see mvs_media_can_view;
		// MediaUrl::file()/get_thumb_url() already return '' when the gate denies).
		$can_view = $this->can_view_media( $media );

		// Documents get a DIFFERENT refusal contract than media: 404, never
		// 403. Media's 403-with-login-prompt page is deliberate (see the
		// comment above `$can_view`) — a photo's privacy state is not
		// sensitive to reveal. A document's filename can carry a client's
		// name, so confirming "this exists but you can't see it" (what 403
		// means) is itself the leak the checklist's must-never-happen table
		// exists to prevent. Confirmed 2026-08-11 combo QA (F2): a
		// revoked-grant document and a never-granted document both answered
		// 403 here before this fix. Documents-disabled (above) and
		// documents-refused (here) now both render the identical branded
		// 404 — a denied viewer cannot tell "off" from "not yours to see"
		// from "does not exist", which is the point.
		if (
			! $can_view
			&& in_array( $mvs_media_type, array( 'document', 'legacy_document' ), true )
		) {
			self::render_branded_404( 'media', $slug );
			return;
		}

		// Set globals for the template.
		$GLOBALS['mvs_current_media']  = $media;
		$GLOBALS['mvs_media_can_view'] = $can_view;

		// Denied viewers: mark the page non-indexable and forbidden, but keep
		// rendering the media container so the message lands where the media
		// would have.
		if ( ! $can_view ) {
			status_header( 403 );
			nocache_headers();
			add_action(
				'wp_head',
				static function () {
					echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
				},
				1
			);
		}

		// Set page title.
		add_filter(
			'document_title_parts',
			function ( $title ) use ( $media ) {
				$title['title'] = $media['title'] ?: __( 'Media', 'wpmediaverse' );
				return $title;
			}
		);

		// Open Graph + Twitter Card meta — when this URL is shared on
		// Facebook / X / LinkedIn / Slack / etc., the platform scrapes
		// these tags to render a preview card with image + title +
		// description. Skipped entirely for denied viewers so a shared link
		// never leaks the poster or description of gated media.
		if ( $can_view ) {
			add_action(
				'wp_head',
				function () use ( $media, $mvs_media_type ) {
					$title       = $media['title'] ? $media['title'] : __( 'Media', 'wpmediaverse' );
					$description = isset( $media['description'] ) ? wp_strip_all_tags( $media['description'] ) : '';
					if ( strlen( $description ) > 280 ) {
						$description = substr( $description, 0, 277 ) . '…';
					}

					// Use a thumbnail (signed if needed) so private-but-shareable
					// items still get a preview image. The signed_urls service
					// is registered unconditionally on plugin init so this
					// returns a usable instance in any normal request, but the
					// guard stays for the rare edge case where service
					// resolution failed (e.g. plugin half-activated).
					$signed    = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
					$thumb_url = $signed ? $signed->generate_thumbnail( (int) $media['media_id'], 0, 'large', 0, true ) : '';

					$permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( (int) $media['media_id'] );
					$site_name = get_bloginfo( 'name' );
					$is_video  = 'video' === $mvs_media_type;
					$is_audio  = 'audio' === $mvs_media_type;

					echo "\n<!-- WPMediaVerse Open Graph -->\n";
					echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
					echo '<meta property="og:type" content="' . esc_attr( $is_video ? 'video.other' : ( $is_audio ? 'music.song' : 'article' ) ) . '" />' . "\n";
					echo '<meta property="og:url" content="' . esc_url( $permalink ) . '" />' . "\n";
					echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
					if ( $description ) {
						echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
					}
					if ( $thumb_url ) {
						echo '<meta property="og:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
						echo '<meta property="og:image:alt" content="' . esc_attr( $title ) . '" />' . "\n";
					}

					echo '<meta name="twitter:card" content="' . esc_attr( $thumb_url ? 'summary_large_image' : 'summary' ) . '" />' . "\n";
					echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
					if ( $description ) {
						echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
					}
					if ( $thumb_url ) {
						echo '<meta name="twitter:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
					}
					echo "<!-- /WPMediaVerse Open Graph -->\n";
				},
				5 // run early so themes / SEO plugins can override below.
			);
		}

		$template = self::locate( 'media-single.php' );
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Point active SEO plugins at a virtual route's real title + canonical.
	 *
	 * These routes emit a custom query var (mvs_media_archive / mvs_profile_user
	 * / mvs_edit_profile), so WP mis-reads the main query as the blog home and
	 * Yoast / Rank Math print the Posts page's title + canonical (e.g. "Sample
	 * Page") over the whole route. The document_title_parts filter each caller
	 * sets covers the native (no SEO plugin) path; this covers the case where an
	 * SEO plugin has taken over <head>. Each filter only fires when its plugin is
	 * active, so this is a no-op otherwise. Canonical is derived from the actual
	 * matched request, so pagination (/page/N/) stays correct.
	 *
	 * @param string $title Human-readable route title (without the site name).
	 */
	private function apply_seo_overrides( string $title ): void {
		$request   = isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '';
		$canonical = home_url( user_trailingslashit( $request ) );

		$sep        = (string) apply_filters( 'mvs_seo_title_separator', '-' );
		$full_title = trim( $title ) . ' ' . $sep . ' ' . get_bloginfo( 'name' );

		$title_cb = static function () use ( $full_title ) {
			return $full_title;
		};
		$url_cb   = static function () use ( $canonical ) {
			return $canonical;
		};

		// Yoast SEO.
		add_filter( 'wpseo_title', $title_cb, 20 );
		add_filter( 'wpseo_canonical', $url_cb, 20 );
		add_filter( 'wpseo_opengraph_url', $url_cb, 20 );

		// Rank Math (same failure mode).
		add_filter( 'rank_math/frontend/title', $title_cb, 20 );
		add_filter( 'rank_math/frontend/canonical', $url_cb, 20 );
	}

	/**
	 * Serve the media archive (explore) page.
	 */
	private function serve_media_archive(): void {
		$GLOBALS['mvs_is_media_archive'] = true;

		add_filter(
			'document_title_parts',
			function ( $title ) {
				$title['title'] = __( 'Explore Media', 'wpmediaverse' );
				return $title;
			}
		);
		$this->apply_seo_overrides( __( 'Explore Media', 'wpmediaverse' ) );

		$template = self::locate( 'explore.php' );
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Serve a user's media profile page.
	 *
	 * @param string $username Username (without @).
	 */
	private function serve_user_profile( string $username ): void {
		// Canonical: new links are nicename-based (see TemplateHelpers::
		// get_user_profile_url()). sanitize_title() matches how WP derives/stores
		// user_nicename, so this is an exact-match lookup, not a fuzzy one.
		$user = get_user_by( 'slug', sanitize_title( $username ) );

		// Back-compat: pre-fix URLs (bookmarks, shared links, search-engine index,
		// emails already sent) were login-based. Keep resolving them so existing
		// links never 404. sanitize_user() mirrors the original lookup exactly.
		if ( ! $user ) {
			$user = get_user_by( 'login', sanitize_user( $username, true ) );
		}

		if ( ! $user ) {
			self::render_branded_404( 'profile', $username );
			return;
		}

		$GLOBALS['mvs_profile_user']     = $user;
		$GLOBALS['mvs_is_media_archive'] = true;

		add_filter(
			'document_title_parts',
			function ( $title ) use ( $user ) {
				/* translators: %s: user display name */
				$title['title'] = sprintf( __( '%s: Media', 'wpmediaverse' ), $user->display_name );
				return $title;
			}
		);
		/* translators: %s: user display name */
		$this->apply_seo_overrides( sprintf( __( '%s: Media', 'wpmediaverse' ), $user->display_name ) );

		// Try user-profile.php first (Pro provides Instagram-style version), fall back to explore.php.
		$template = self::locate( 'user-profile.php' );
		if ( ! $template ) {
			$template = self::locate( 'explore.php' );
		}
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Serve the profile edit page.
	 */
	private function serve_profile_edit(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( \WPMediaVerse\Core\TemplateHelpers::login_url( home_url( '/media/edit-profile/' ) ) );
			exit;
		}

		add_filter(
			'document_title_parts',
			function ( $title ) {
				$title['title'] = __( 'Edit Profile', 'wpmediaverse' );
				return $title;
			}
		);
		$this->apply_seo_overrides( __( 'Edit Profile', 'wpmediaverse' ) );

		$template = self::locate( 'profile-edit.php' );
		if ( $template ) {
			$this->render_template( $template );
		}
	}

	/**
	 * Check if the current user can view a media item based on privacy.
	 *
	 * @param array $media Media index row.
	 * @return bool
	 */
	private function can_view_media( array $media ): bool {
		$media_id = (int) ( $media['media_id'] ?? 0 );
		if ( ! $media_id ) {
			return false;
		}

		// Delegate to the single canonical gate. This local check previously
		// handled only public/owner/moderator/members and returned false for
		// friends/group/custom — so a legitimate friend or group member was
		// wrongly 404'd on a friends-only/group media page (audit 2026-06-04),
		// and it was one of several privacy reimplementations. PrivacyService
		// covers every level (friends via friendship, group via membership,
		// custom via grants) in one place.
		return \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $media_id, get_current_user_id() );
	}

	/**
	 * Load single template for Album/Collection CPTs (NOT media).
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_single_template( string $template ): string {
		$post_type = get_post_type();

		$map = array(
			'mvs_album'      => 'album.php',
			'mvs_collection' => 'collection.php',
		);

		if ( isset( $map[ $post_type ] ) ) {
			$found = self::locate( $map[ $post_type ] );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Load archive template for Album and Collection CPTs.
	 *
	 * Both CPTs share the dedicated cpt-archive.php template which queries
	 * the correct post_type, paginates, and renders CPT-specific cards.
	 * explore.php remains the /media/ feed and is no longer used for archives.
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_archive_template( string $template ): string {
		$map = array(
			'mvs_album'      => 'cpt-archive.php',
			'mvs_collection' => 'cpt-archive.php',
		);

		foreach ( $map as $post_type => $tpl_file ) {
			if ( is_post_type_archive( $post_type ) ) {
				$found = self::locate( $tpl_file );
				if ( $found ) {
					return $found;
				}
				break;
			}
		}

		return $template;
	}

	/**
	 * Load explore template for MVS taxonomy archives (tags, categories).
	 *
	 * @param string $template Current template path.
	 * @return string
	 */
	public function load_taxonomy_template( string $template ): string {
		if ( is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' ) ) {
			$found = self::locate( 'explore.php' );
			if ( $found ) {
				return $found;
			}
		}

		return $template;
	}

	/**
	 * Add mvs-page body class on all WPMediaVerse frontend pages.
	 */
	public function maybe_add_mvs_body_class(): void {
		$is_mvs_page = (
			is_singular( array( 'mvs_album', 'mvs_collection' ) )
			|| is_post_type_archive( 'mvs_album' )
			|| is_post_type_archive( 'mvs_collection' )
			|| is_tax( 'mvs_tag' )
			|| is_tax( 'mvs_category' )
			|| (bool) get_query_var( 'mvs_edit_profile' )
			|| (bool) get_query_var( 'mvs_profile_user' )
			|| (bool) get_query_var( 'mvs_media_archive' )
			|| (bool) get_query_var( 'mvs_media_slug' )
		);

		// Shortcode pages (e.g. dashboard): match current page ID against any mvs_page_* option.
		if ( ! $is_mvs_page && is_singular() ) {
			$queried_id = (int) get_queried_object_id();
			if ( $queried_id > 0 ) {
				$all_options = wp_load_alloptions();
				foreach ( $all_options as $option_key => $option_value ) {
					if (
						str_starts_with( $option_key, 'mvs_page_' )
						&& (int) $option_value === $queried_id
					) {
						$is_mvs_page = true;
						break;
					}
				}
			}
		}

		if ( ! $is_mvs_page ) {
			return;
		}

		add_filter(
			'body_class',
			static function ( array $classes ): array {
				/**
				 * Filters the body classes added to WPMediaVerse pages.
				 *
				 * @since 1.1.0
				 *
				 * @param string[] $mvs_classes MVS-specific body classes.
				 */
				$mvs_classes = apply_filters( 'mvs_body_classes', array( 'mvs-page', 'no-sidebar' ) );

				foreach ( $mvs_classes as $cls ) {
					if ( ! in_array( $cls, $classes, true ) ) {
						$classes[] = $cls;
					}
				}
				return $classes;
			}
		);
	}

	/**
	 * Locate a template file.
	 *
	 * Checks theme first, then plugin templates directory.
	 *
	 * @param string $template_name Template file name.
	 * @param string $template_path Optional subdirectory within the theme override folder.
	 * @return string Full path to template or empty string if not found.
	 */
	/**
	 * Render the plugin-branded 404 for a plugin-owned URL and exit.
	 *
	 * Replaces the `set_404() + status_header(404) + return` pattern used
	 * throughout this loader. Loading the plugin template keeps the user
	 * inside the MediaVerse experience and offers a Browse-all CTA, instead
	 * of bouncing to the theme's generic 404.php.
	 *
	 * @param string $context    One of 'media' | 'profile' | 'album' | 'collection'.
	 * @param string $identifier Optional slug/username the user requested.
	 */
	public static function render_branded_404( string $context = 'media', string $identifier = '' ): void {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		$GLOBALS['mvs_404_context']    = $context;
		$GLOBALS['mvs_404_identifier'] = $identifier;

		// Ensure the 404 template renders with the plugin's design system.
		// `template_redirect` fires before `wp_enqueue_scripts`, so enqueuing
		// here lands in the normal dependency resolution window.
		if ( wp_style_is( 'mvs-frontend', 'registered' ) ) {
			wp_enqueue_style( 'mvs-frontend' );
		}

		// Lucide is registered via Plugin::register_lucide_script() on
		// `wp_enqueue_scripts@1`, which runs before `wp_head`. Even though
		// `template_redirect@5` is before wp_enqueue_scripts fires in the
		// request lifecycle, the 404 template calls `get_header()` which
		// triggers the asset-enqueue chain — so the script is registered by
		// the time any output happens. Just enqueue.
		if ( ! wp_script_is( 'mvs-lucide', 'registered' ) ) {
			\WPMediaVerse\Core\Plugin::register_lucide_script();
		}
		wp_enqueue_script( 'mvs-lucide' );

		$template = self::locate( '404.php' );
		if ( $template ) {
			include $template;
			exit;
		}
		// No plugin template — let WP fall through to the theme's 404.
	}

	/**
	 * The pages this plugin created on activation and therefore owns the layout of.
	 *
	 * Activator::create_pages() inserts /my-media/, /explore-media/ and
	 * /upload-media/ as ordinary WP pages. On a theme whose default page template
	 * carries a sidebar (BuddyX's blog template does), that renders a member's
	 * media library beside "Recent Posts" / "Recent Comments". We inserted those
	 * pages, so the sidebar is our doing, not WordPress's — own the template
	 * rather than CSS-hide the chrome. Hidden chrome still queries widgets, still
	 * gets announced by screen readers, still gets indexed, and still takes Tab
	 * focus.
	 *
	 * Keyed on the mvs_page_* options Activator writes (SettingsHelper's slot
	 * map); a page the plugin never created is never routed here.
	 *
	 * @since 2.0.0
	 *
	 * @return int[] Post IDs of the plugin-owned app pages.
	 */
	public static function app_page_ids(): array {
		// From SettingsHelper's slot map, not a second copy of it — Explore
		// Documents was missing here and so rendered with the theme's sidebar
		// and no app template.
		$ids = SettingsHelper::all_page_ids();

		/**
		 * Filters the pages that render with the plugin's full-bleed app template.
		 *
		 * A site owner can add a page (e.g. a custom landing page built from mvs
		 * blocks) or remove one to restore the theme's default template.
		 *
		 * @since 2.0.0
		 *
		 * @param int[] $ids Post IDs of the plugin-owned app pages.
		 */
		return array_values( array_unique( (array) apply_filters( 'mvs_app_page_ids', $ids ) ) );
	}

	/**
	 * Whether a post is one of the plugin-owned app pages.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_app_page( int $post_id ): bool {
		return $post_id > 0 && in_array( $post_id, self::app_page_ids(), true );
	}

	/**
	 * Route the plugin's own pages to a sidebar-free layout.
	 *
	 * We use each theme's OWN full-width mechanism so the content renders inside
	 * the theme's container, header hooks and responsive rules — not a bare
	 * wrapper that throws the theme's shell away:
	 *
	 *   - Reign drives layout from post-meta, so its own template stays and
	 *     force_reign_full_width() does the work. Leave $template untouched.
	 *   - BuddyX / BuddyX-Pro (and any theme shipping a "no sidebar" page
	 *     template) → render inside the theme's full-width-container template.
	 *   - Any other theme → the plugin's own templates/app-page.php.
	 *
	 * A theme or site owner overrides all of this with the mvs_app_template filter,
	 * and removes a page from routing entirely with mvs_app_page_ids.
	 *
	 * @since 2.0.0
	 *
	 * @param string|mixed $template The template WordPress resolved. Not type-hinted:
	 *                                another `template_include` filter running before us
	 *                                can hand back null (or any value), and a bad actor
	 *                                upstream must not turn into a fatal in our callback.
	 * @return string|mixed The layout template for a plugin page, else $template untouched.
	 */
	public function use_app_template( $template ) {
		if ( ! is_string( $template ) || ! is_page() || ! self::is_app_page( (int) get_queried_object_id() ) ) {
			return $template;
		}

		$post_id = (int) get_queried_object_id();

		// The page has an explicit, resolvable page template — our synced
		// "Page No Sidebar" mapping on BuddyX/BuddyX-Pro, or one the admin chose
		// in the editor. WordPress already resolved it; respect it, do not
		// second-guess a visible page attribute.
		$assigned = (string) get_page_template_slug( $post_id );
		if ( '' !== $assigned && 'default' !== $assigned && locate_template( $assigned ) ) {
			return $template;
		}

		// Reign: its own page template stays; the meta filter forces full-width.
		if ( self::is_reign_theme() ) {
			return $template;
		}

		// No usable no-sidebar page template on this theme, and not Reign — fall
		// back to the plugin's own sidebar-free shell so the sidebar never leaks
		// through on a non-Wbcom theme.
		$resolved = self::locate( 'app-page.php' );
		if ( ! $resolved ) {
			return $template;
		}

		/**
		 * Filters the fallback app-page template path.
		 *
		 * @since 2.0.0
		 *
		 * @param string $resolved Absolute template path.
		 * @param int    $post_id  The app page being rendered.
		 */
		return (string) apply_filters( 'mvs_app_template', $resolved, $post_id );
	}

	/**
	 * The active theme's "no sidebar" page template slug, or '' if it has none.
	 *
	 * BuddyX and BuddyX-Pro both ship page-templates/full-width-container.php
	 * ("Page No Sidebar" — keeps the content container) and
	 * page-templates/full-width.php ("Page Full Screen" — edge to edge). We
	 * prefer the contained one. Resolved against the theme's registered page
	 * templates so a theme that renamed or dropped the file is handled correctly.
	 *
	 * @since 2.0.0
	 *
	 * @return string Page-template slug (e.g. 'page-templates/full-width-container.php'), or ''.
	 */
	public static function theme_no_sidebar_template(): string {
		$candidates = array(
			'page-templates/full-width-container.php',
			'page-templates/full-width.php',
		);

		/**
		 * Filters the page-template slugs treated as "no sidebar" for app pages.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $candidates Ordered candidate slugs; first match wins.
		 */
		$candidates = (array) apply_filters( 'mvs_no_sidebar_templates', $candidates );

		$registered = wp_get_theme()->get_page_templates( null, 'page' );
		foreach ( $candidates as $slug ) {
			if ( isset( $registered[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Point every app page at the active theme's no-sidebar page template.
	 *
	 * Sets the visible, editable _wp_page_template attribute so the block editor
	 * shows "Template: Page No Sidebar" and the theme renders the page natively.
	 * When the active theme has no such template (e.g. Reign, which uses meta),
	 * clears any full-width slug WE previously stamped — but never touches a slug
	 * an admin chose. Idempotent; safe on every theme switch.
	 *
	 * @since 2.0.0
	 */
	public static function sync_app_page_templates(): void {
		$slug = self::theme_no_sidebar_template();

		foreach ( self::app_page_ids() as $post_id ) {
			$current = (string) get_post_meta( $post_id, '_wp_page_template', true );

			if ( '' !== $slug ) {
				// Only stamp when unset/default or already one of ours — never
				// overwrite an admin's explicit non-full-width choice.
				if ( '' === $current || 'default' === $current || self::is_full_width_slug( $current ) ) {
					update_post_meta( $post_id, '_wp_page_template', $slug );
				}
			} elseif ( self::is_full_width_slug( $current ) ) {
				// New theme has no such template; drop the now-dangling slug we set
				// so WordPress falls to the default (Reign meta / app-page fallback
				// then does the work).
				delete_post_meta( $post_id, '_wp_page_template' );
			}
		}
	}

	/**
	 * Whether a stored page-template slug is one of the full-width ones we manage.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug Stored _wp_page_template value.
	 * @return bool
	 */
	private static function is_full_width_slug( string $slug ): bool {
		return in_array(
			$slug,
			array( 'page-templates/full-width-container.php', 'page-templates/full-width.php' ),
			true
		);
	}

	/**
	 * Backfill the app-page template attribute once, for pages that predate it.
	 *
	 * @since 2.0.0
	 */
	public static function maybe_backfill_app_page_templates(): void {
		if ( get_option( 'mvs_app_page_templates_synced' ) ) {
			return;
		}
		self::sync_app_page_templates();
		update_option( 'mvs_app_page_templates_synced', 1, false );
	}

	/**
	 * Whether the active theme is Reign (or a Reign child).
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_reign_theme(): bool {
		return false !== strpos( (string) get_template(), 'reign' );
	}

	/**
	 * Force Reign's full-width layout for the plugin's app pages.
	 *
	 * Reign removes the sidebar from a page by reading its layout out of the
	 * serialized `reign_wbcom_metabox_data` post-meta. Rather than persist that
	 * meta on our pages (which would be stale if the site later switched away
	 * from Reign), we short-circuit the read for our app pages only — exactly how
	 * Reign itself forces full-width for FluentCart pages
	 * (inc/fluentcart-support.php::reign_fluentcart_force_layout). Only ever sets
	 * full-width when no explicit layout is configured, so an admin who chose a
	 * layout keeps it.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $value     The value get_metadata() is about to return (null = not short-circuited).
	 * @param int    $object_id Post ID being queried.
	 * @param string $meta_key  Meta key being queried.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed Filtered meta, or the untouched $value.
	 */
	public function force_reign_full_width( $value, $object_id, $meta_key, $single ) {
		if ( 'reign_wbcom_metabox_data' !== $meta_key || is_admin() ) {
			return $value;
		}
		if ( ! self::is_app_page( (int) $object_id ) ) {
			return $value;
		}

		// Read the real meta without re-entering this filter.
		remove_filter( 'get_post_metadata', array( $this, 'force_reign_full_width' ), 10 );
		$meta = get_post_meta( (int) $object_id, 'reign_wbcom_metabox_data', true );
		add_filter( 'get_post_metadata', array( $this, 'force_reign_full_width' ), 10, 4 );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		if ( ! isset( $meta['layout'] ) || ! is_array( $meta['layout'] ) ) {
			$meta['layout'] = array();
		}

		// Respect an explicit choice; only fill in when unset or the default '0'.
		$current = $meta['layout']['site_layout'] ?? '0';
		if ( '' === $current || '0' === $current ) {
			$meta['layout']['site_layout'] = 'full_width';
		}

		return $single ? array( $meta ) : array( array( $meta ) );
	}

	public static function locate( string $template_name, string $template_path = '' ): string {
		$theme_path = self::THEME_DIR . '/';
		if ( $template_path ) {
			$theme_path .= trailingslashit( $template_path );
		}

		$template = locate_template(
			array(
				$theme_path . $template_name,
			)
		);

		if ( ! $template ) {
			$plugin_path = MVS_PLUGIN_DIR . 'templates/';
			if ( $template_path ) {
				$plugin_path .= trailingslashit( $template_path );
			}

			$file = $plugin_path . $template_name;
			if ( file_exists( $file ) ) {
				$template = $file;
			}
		}

		/**
		 * Filters the located template path.
		 *
		 * @param string $template      Full template path.
		 * @param string $template_name Template file name.
		 * @param string $template_path Subdirectory path.
		 */
		return apply_filters( 'mvs_locate_template', $template, $template_name, $template_path );
	}

	/**
	 * Load a template part with data.
	 *
	 * @param string $template_name Template file name.
	 * @param array  $args          Data to pass to the template.
	 */
	public static function get_template( string $template_name, array $args = array() ): void {
		$template = self::locate( $template_name );

		if ( ! $template ) {
			return;
		}

		/**
		 * Filters template variables before rendering.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $args          Template variables.
		 * @param string $template_name Template file name.
		 */
		$args = apply_filters( 'mvs_template_variables', $args, $template_name );

		if ( ! empty( $args ) ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		/**
		 * Fires before a template part is rendered.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template_name Template file name.
		 * @param array  $args          Template variables.
		 */
		do_action( 'mvs_before_template_render', $template_name, $args );

		include $template;

		/**
		 * Fires after a template part is rendered.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template_name Template file name.
		 * @param array  $args          Template variables.
		 */
		do_action( 'mvs_after_template_render', $template_name, $args );
	}
}

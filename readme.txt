=== WPMediaVerse ===
Contributors: vapvarun, wbcomdesigns
Tags: media, gallery, buddypress, social media, albums
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The media layer your community site is missing. Custom database tables, AI moderation, and a full social layer — without requiring BuddyPress.

== Description ==

**[Try Live Demo](https://app.instawp.io/launch?s=wpmediaverse&d=v2)** | **[Get Pro](https://store.wbcomdesigns.com/wpmediaverse-pro/)** | **[Documentation](https://store.wbcomdesigns.com/wpmediaverse/docs/)**

WPMediaVerse is a complete media platform for WordPress — built on custom database tables, not wp_posts. Your community gets photo uploads, albums, reactions, comments, follows, direct messaging, AI moderation, and a full lightbox experience. Your site stays fast no matter how many uploads come in.

**Why WPMediaVerse?**

Every other WordPress media plugin (rtMedia, MediaPress, BuddyBoss Media) stores uploads in wp_posts. On active communities, that table grows into tens of thousands of mixed rows. WPMediaVerse uses three dedicated, indexed tables — media queries never touch your posts, pages, or products.

**What You Get (Free)**

* **Custom Table Architecture** — Three indexed tables keep media separate from WordPress core data
* **Media Uploads** — Drag & drop with MIME validation, EXIF stripping, duplicate detection, thumbnail generation
* **Albums & Collections** — Ordered albums with cover images, smart collections with auto-curation rules
* **Social Layer** — Reactions (6 types), threaded comments, favorites, @mentions, follow/unfollow, sharing
* **Direct Messaging** — Text and media messaging between members, no third-party service needed
* **AI Moderation** — OpenAI Vision scans uploads automatically. Flag, quarantine, or reject before they go public
* **Privacy Controls** — 6 levels per upload: public, members-only, friends-only, group, private, custom
* **Explore Feed** — Public media grid with filtering by tag, album, user, and media type
* **Lightbox** — Full-screen with reactions, comments, favorites, share, gallery navigation — no page reload
* **BuddyPress Integration** — Activity uploads (1-6 per post), profile/group media tabs, lightbox in feed
* **13 Gutenberg Blocks** — Media grid, upload, player, album viewer, explore feed, stories, and more
* **80+ REST API Endpoints** — 17 controllers covering every operation for headless/decoupled builds
* **8 WP-CLI Commands** — Bulk operations, migrations, cache management, moderation
* **8 Shortcodes** — Drop media features into any page or widget
* **Webhooks** — Outbound event webhooks with HMAC-SHA256 signing via Action Scheduler
* **GDPR** — Full data export and erasure via WordPress privacy tools

**Pro Adds**

* 5 layout modes (Grid, Instagram, Pinterest, Flickr, Dribbble)
* Photo Challenges, 1v1 Battles, Tournament Brackets
* Points, Streaks, Boosts gamification engine
* S3 and BunnyCDN cloud storage drivers
* Video transcoding with HLS adaptive streaming
* Auto-captions via Whisper AI
* Per-user storage quotas (MemberPress, WooCommerce, PMPro integration)
* Voice messages, read receipts, typing indicators in DMs
* Google Vision + AWS Rekognition moderation
* Migration importers (rtMedia, MediaPress, BuddyBoss)

**For Developers**

* PSR-4 architecture with service container and lazy-loaded dependencies
* 80+ action and filter hooks for extensibility
* Template override system — copy to your theme and customize
* AI provider abstraction — bring your own provider
* Storage driver pattern — local, S3, BunnyCDN, or custom
* WordPress Interactivity API — zero legacy JavaScript

== Installation ==

1. Upload `wpmediaverse` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **MediaVerse > Settings** to configure upload limits, AI, and privacy defaults
4. Use the Gutenberg blocks or shortcodes to add media features to your pages

== Frequently Asked Questions ==

= Does this require BuddyPress? =

No. WPMediaVerse works as a standalone plugin. BuddyPress integration (activity feed, profile tabs, friend-based privacy) activates automatically when BuddyPress is detected.

= What AI providers are supported? =

OpenAI Vision (GPT-4) is included. Additional providers can be registered via the `mvs_ai_providers` action hook.

= Can I override the templates? =

Yes. Copy any template from `wpmediaverse/templates/` to `your-theme/wpmediaverse/` and customize it.

= How do I import from rtMedia? =

Use the WP-CLI command: `wp mvs import-rtmedia`. Run with `--dry-run` first to preview.

= What are the shortcodes? =

* `[mvs_gallery]` — Media grid
* `[mvs_upload]` — Upload form
* `[mvs_album id="123"]` — Album viewer
* `[mvs_player id="456"]` — Media player
* `[mvs_stats]` — Stats dashboard
* `[mvs_dashboard]` — User dashboard
* `[mvs_collection]` — Collection display
* `[mvs_profile_edit]` — Profile editor

== Screenshots ==

1. **Explore Page** — Instagram-style media grid with search and tag cloud filtering.
2. **Dashboard** — User media management with albums, favorites, and collections tabs.
3. **Single Media** — Full media view with reactions, comments, favorites, and sharing.
4. **Album View** — Album page with cover image, item grid, and sequential playback.
5. **Admin Overview** — At-a-glance stats, quick links, recent uploads, and system status.
6. **Settings** — Tabbed settings with upload limits, display options, permissions, and AI config.
7. **BuddyPress Profile** — Media tab on user profiles with album support.
8. **Moderation Queue** — AI-flagged media review with approve/reject workflow.

== Changelog ==

= 1.2.1 =
**Bug fixes**

* Fix: **BP activity privacy now follows media + album privacy.** Customer-reported (Zoho #39974): when a media uploaded to a BP activity was set to non-public, the activity card itself stayed visible in the public stream — composer text + timestamp + author leaked. Activity `hide_sitewide` is now derived from the most-restrictive of (media privacy, parent album privacy). Album-level privacy changes fan out to every linked per-media + bundled gallery activity. New action hook `mvs_media_privacy_changed` fires from `MediaRepository::set` on UPDATE.
* Fix: **CSS file rename `shared-ui-shell.css` → `shared-ui-frame.css`** (Crisp #NZRSBX). Customer WAFs auto-block any file with the "shell" token. `templates/partials/shared-ui-shell.php` → `shared-ui-frame.php`. `Plugin::render_shared_ui_shell` → `render_shared_ui_frame`. Old `mvs-shared-ui-shell` enqueue handle kept as a register-only deprecation shim until 1.3.0.

**100k-readiness pass**

* New: **`Services\AdminAggregatesService`** — single source of truth for site-wide counts (total media, views, storage, recent media). Every admin / CLI surface now reads through this service instead of running its own `SUM()` / `COUNT(*)` scan on each load. Cache layer uses `wp_cache` primary + daily transient fallback (only when no persistent object cache present). New Coding Rule #16: any `$wpdb->get_var SUM/COUNT` against `mvs_*` outside this service fails `bin/coding-rules-check.sh` Rule 3.
* New: **FULLTEXT search index** on `mvs_media_index(title, description)`. Migrator v13 adds `media_search_ft`; REST `/media?s=` now uses `MATCH(...) AGAINST (... IN BOOLEAN MODE)` for queries ≥ 3 chars and falls back to `LIKE '%term%'` for shorter inputs. At 100k rows the swap drops worst-case search latency by orders of magnitude; on lockdown hosts that don't permit ALTER (engine != InnoDB or DBA-restricted), the LIKE path keeps search functional.
* New: **View-event retention cron.** Setting `mvs_view_retention_days` (default 90, max 730, 0 = unlimited). Daily cron `mvs_purge_old_views` drops rows older than the window from `mvs_media_views` in 50k-row batches. Aggregates in `mvs_media_stats` are unaffected — only the raw event log is trimmed.
* New: **REST per_page hardening.** All 14 list endpoints now route `per_page` through `WPMediaVerse\REST\Pagination::resolve_per_page` — clamps to `apply_filters('mvs_rest_pagination_max', 100)` even on routes whose schema `'maximum'` was being silently ignored (WP REST validation is bypassed when `sanitize_callback` is set). Pre-1.2.1 a malicious caller could pass `per_page=999999`.
* New: **`MediaRepository` per-request row cache + `prefetch()`** — render paths (BP activity, lightbox, dashboard) call `get($id, ...)` repeatedly per media. The new static cache turns the first read into a full-row fetch and subsequent reads into static-array lookups. `prefetch(array $ids)` batch-loads index + meta in 2 queries, eliminating N+1 across the activity stream's recovery path and the BuddyBoss imported-media loop.
* New: **Storage discipline audit + Coding Rule #16.** All 22 existing `set_transient` callsites + 13 `wp_cache_*` callsites checked for cardinality leaks. `MessagingService::set_typing` was storing per-(conversation, user) typing indicators in `wp_options` — at a busy DM site that's thousands of writes/min churning options. Migrated to `wp_cache_set` with a dedicated cache group; degrades gracefully (no "typing…" pip) on sites without persistent object cache. New `bin/coding-rules-check.sh` Rule 4 prevents future drift.

**New: customer-driven structural change**

* New: **Filename strategy.** New setting `mvs_filename_strategy`: `original_sanitized` (default for upgrade installs — preserves prior behaviour) or `hashed` (default for fresh installs — 16 hex chars + sanitized extension). Hashed mode preserves the user-facing filename in `mvs_media_meta.original_filename` and surfaces it via REST + Content-Disposition headers, so end-user downloads still see "vacation-photo.jpg" even though the on-disk file is `a3f8c1b2.jpg`. Existing media is **never renamed** — only the strategy applied to new uploads after the setting flips. New filter `mvs_filename_strategy` for site-level overrides.

**New: cloud-storage tooling (Pro paired)**

* New: **`wp mvs migrate-storage --from=<driver> --to=<driver>` CLI** — moves every media file between storage drivers (local ↔ s3 ↔ bunnycdn) with verify-before-delete safety. Idempotent (re-runs skip already-migrated rows). Supports `--dry-run`, `--keep-source` (safety copy), `--media-id` (single-row repair), `--limit` (batched runs). After a verified upload, refreshes `mvs_media_index.file_url` to the destination driver's URL so storage-internal `get_raw('file_url')` callers (UploadService thumb fallback, WatermarkService) stay in sync; display surfaces and BP activity stored HTML are unaffected because they sign URLs through the active driver dynamically (BP activity rebuild via `ActivityContentIntegration::refresh_broadcast_urls` rewrites every `<img src>` / `<a href>` keyed on `data-mvs-media-id`). Does NOT auto-flip the active `mvs_storage_driver` option — operator does that explicitly after verification. Fixes the long-standing "no migration tool when admin switches storage backend" gap.
* New: **`StorageDriverInterface::download( string $path, string $local_dest ): bool`** — required new contract method on storage drivers (also unblocks future cloud-mode multi-size thumbnail generation, on the 1.3.0 list). Free's LocalDriver and Pro's S3 + BunnyCDN drivers implement it. Third-party storage drivers must add this method or extend an abstract base — backwards-incompatible only at the implementation level.
* New: **`docs/cloud-storage-verification.md`** — full QA matrix (4 phases: fresh upload, delete cleanup, 5 migration directions, failure modes), operator runbook for the local→s3 cutover, and 2 documented gaps with 1.3.0 fix paths (multi-size thumbnails on cloud + signed-GET path for private buckets).
* New: **Direct CDN URLs for public media** (opt-in setting `mvs_cloud_direct_public_urls`, off by default). When enabled AND the active driver is cloud (s3 / bunnycdn), `SignedUrlService::generate` and `generate_thumbnail` short-circuit public-privacy media to the active driver's edge URL (e.g. `https://zone.b-cdn.net/wpmediaverse/2026/05/foo.jpg`) instead of routing through WordPress's gated `/serve` proxy. Browsers fetch from the CDN edge, CDN caches forever, WP is no longer in the hot path for image requests. Members-only / friends-only / private media and media with active access rules continue to flow through `/serve` so privacy enforcement remains per-request. **Operator caveat documented in the setting description:** once a public URL is on the CDN edge, anyone with the URL keeps access even after the media's privacy is later flipped to private — leave off if you need WP to re-validate privacy on every image request. Verified end-to-end on local: 15 explore-feed images flipped from `/wp-json/mvs/v1/serve?...` to `https://mediaverse1.b-cdn.net/...` with 0 console errors and 1 correctly-still-proxied private item.

**Other**

* New: action hook `mvs_media_privacy_changed( $media_id, $new_privacy, $old_privacy )` — fires from `MediaRepository::set` and `set_many` when the privacy column is UPDATEd (not on INSERT). Internally consumed by `ActivitySyncIntegration::sync_activity_privacy`.

= 1.2.0 =
**New features (frontend)**

* New: **Member Photos block + shortcode** (`mvs/member-photos`, `[mvs_member_photos]`) — auto-detects whose photos to show: explicit `userId` → BP displayed user → post author → current user. Drop it into a BP profile, an author template, or a regular page and it just works.
* New: **PDF Viewer block + shortcode** (`mvs/pdf-viewer`, `[mvs_pdf_viewer]`) — embeds PDFs uploaded to WPMediaVerse using the browser's native PDF viewer (`#view=FitH`); inspector exposes height (200–1400 px) and toolbar toggle. Five distinct empty states (no id / not found / not a PDF / no permission / asset missing) — never a blank rectangle.
* New: **More sort options on Media Grid** — added "Most Popular", "Most Viewed", "Most Reactions", and "Random". Asc/Desc direction toggle exposed in the inspector (hidden when sort = Random). New `userId` attribute on `mvs/media-grid` and `user_id` attr on `[mvs_gallery]` filter to one author.
* New: **Search autocomplete** on the Explore feed — type two or more characters and a top-8 title-match dropdown opens (debounced 250 ms). Full keyboard support: ArrowDown / ArrowUp / Enter / ESC. ARIA combobox + listbox semantics so screen readers announce matches as you type.
* New: **Lightbox Download button** — toolbar button next to Share + Open. Counts each download in `mvs_media_stats.downloads`; rate-limited at 30/min/user via the central `RateLimiter`. New `POST /mvs/v1/media/{id}/download` REST endpoint.
* New: **Per-media Edit modal** — click the Edit button on your own dashboard cards to change title, description, privacy, and allow-download per-media. Save → live update without reload. `PUT /mvs/v1/media/{id}` now accepts `allow_download` (boolean) and `prepare_item_for_response` emits it (defaults `true` when meta absent).
* New: **Member Photos card** — redesigned hero card with avatar + display name + handle + bio + stats (photos / followers / following) + View Profile + Follow/Following toggle. Container-query responsive: switches to vertical stack at <520 px container width (so it fits a sidebar widget) and remains compact at 320 px.
* New: **"Update URL slug" opt-in checkbox** — present in the per-media Edit modal AND on `/media/{slug}/` inline-edit form, sitting beside Privacy on the same row. Off by default — title edits leave the URL stable. Tick to regenerate the slug from the new title; if you're currently viewing the old URL, the page redirects to the new one automatically (no 404 on reload).
* New: **Open Graph + Twitter Card meta** on every `/media/{slug}/` page — `og:title` / `og:type` / `og:url` / `og:site_name` / `og:description` / `og:image` / `og:image:alt` plus `twitter:card=summary_large_image` + `twitter:title/description/image`. Paste a media URL into Slack / Twitter / LinkedIn / Discord and it unfurls correctly.
* New: **Popular tag pills** in the upload modal — top-8 most-used tags surface as click-to-add chips below the tags input. Clicking a pill appends to the comma-separated input and de-dupes silently.
* New: **Upload modal polish** — preview tiles show filename + per-tile (×) remove button; audio files get an audio-fallback icon (no broken-image SVG).

**New features (admin)**

* New: **Bulk Actions on All Media** — multi-select header/footer checkboxes + a Bulk Actions toolbar. Action menu is context-aware to the active filter: in the Trash filter → Restore + Delete permanently; otherwise → Move to Trash. Capability + `wp_nonce_field('mvs_bulk_media')` gates on submit; success notice with count + action.
* New: **Chat panel visibility setting** under Direct Messages — pick where the floating chat panel renders: Everywhere (default) / WPMediaVerse pages only / BuddyPress pages only / Disabled. New `mvs_should_render_chat_panel` filter wraps the resolved decision so themes / add-ons can fine-tune by URL pattern.
* New: **Global "Allow downloads" toggle** under Media Display — single switch that hides the new lightbox Download button site-wide AND makes the `record_download` REST endpoint refuse with 403. Per-media `allow_download` meta still gates further when the global is on.

**UX + Accessibility**

* Fix: **Lightbox Share** no longer falls back to a `window.prompt()` "Copy this link:" popup when neither `navigator.share` nor clipboard write is available — instead a toast error renders. `mvs_media_stats.shares` now also increments via the new `POST /mvs/v1/media/{id}/share` REST endpoint.
* New: **6-reaction accessibility** in the lightbox — Like / Love / Haha / Wow / Sad / Angry each carry sentence-form `aria-label` and `aria-pressed` toggles; the emoji span is `aria-hidden`; the wrapper carries `role="group" aria-label="Reactions"`. Toolbar buttons (Favorite / Share / Download / Open / Report) all gain `aria-label`. `:focus-visible` outline on `.mvs-lightbox-action / -close / -nav` so keyboard nav is visible.
* Fix: **Lightbox toolbar fits 5 actions on one row** — the previous layout used inline-flex + per-button padding 24 px + `margin-left: auto` on Report which overflowed the 380 px sidebar (~414 px content) and produced a horizontal scrollbar. Now `flex: 1` + `space-between` distributes evenly; the toolbar always fits at desktop AND on mobile. Below 768 px the lightbox stacks vertically (image on top, sidebar full-width below) and below 380 px labels collapse to icons-only.
* New: **Block render forms a11y** — explore-feed search input, media-upload file input + privacy select + title/description/tags inputs all gain `aria-label` (placeholder ≠ label per WCAG).
* New: **Search-mode toggle a11y** — Media / People toggle on `templates/explore.php` gets `role="tablist"` + `role="tab"` + `aria-selected` semantics; search input gets a screen-reader label.
* New: **BuddyPress notification dedup** — restored `NotificationIntegration` (mirrors `mvs_notification_created` to BP's `bp_notifications_add_notification`) and added a `function_exists('buddypress')` guard around the dashboard's `.mvs-notification-bell` markup so BP-active sites render notifications in the BP nav bell only — never twice.

**Important fixes**

* Fix: Moderation webhooks now fire reliably. Two listeners (`WebhookService::on_media_moderated` + `CacheService::on_moderation_change`) were registered against `mvs_media_moderated`, but the firer in `ModerationService::set_status()` uses `mvs_moderation_changed` (the established hook name; `LoggerService` already used it). Result: customers using outbound webhooks for moderation approve/reject events were getting **zero** events delivered, and the moderation-status cache stayed stale. Both listeners renamed to the correct hook name. Affected since: 1.0.0.
* Fix: `mvs_reaction_removed` action now fires when a user un-reacts. The action existed conceptually (cache invalidation listener was registered) but `ReactionService::remove()` never fired it, so the media-stat cache stayed warm with the old reaction count after an un-react. The reaction count itself was correct (re-read from DB), but cached aggregates lagged.
* Fix: `mvs_share_recorded` action now fires from the new `record_share` REST endpoint so the cache invalidation listener clears the media-stat row. Without this, share counts in feed cards lagged behind reality until the cache TTL expired.
* Fix: Search autocomplete on the Explore feed now aborts in-flight requests when a newer keystroke arrives. Previously, typing fast (e.g. "ne" then "new" within 250 ms) could leave the slower "ne" results visible if its response landed second — a classic race condition. Each keystroke now spawns an `AbortController`-equipped fetch and supersedes any in-flight request.

* Fix: **Title edit no longer changes the URL slug**. Editing a media title and saving used to silently regenerate the slug — meaning the URL the user just had in their address bar 404'd on reload, and any inbound links / social shares / search-engine cache pointing at the old URL stopped working. Slug now stays stable; admins can opt into a slug change explicitly via the new "Update URL slug" checkbox in the Edit modal and on `/media/{slug}/`.

* Fix: **BuddyPress activity no longer renders the same image twice**. A Phase 8 "linkage table" code path was appending its rendered grid even when the activity content already contained the inline grid markup — so every composer-posted activity rendered each image twice on the activity permalink page. The render filter now uses inline content as the authoritative copy and only falls back to the linkage path when content is empty.

* Fix: **Author profile URLs no longer leak BuddyPress mention HTML**. Five sites (Instagram feed cards, leaderboard, dashboard "View Profile" button, follower notifications, and a sibling Instagram card template) built `/media/@user/` URLs inline. When BuddyPress's `bp_activity_at_name_filter` ran on the surrounding output, the `@user` substring inside the URL was rewritten into a full BP mention `<a class='bp-suggestions-mention' …>@user</a>` — corrupting the URL with literal HTML and producing dead links. All five now route through the canonical `TemplateHelpers::get_user_profile_url()` which resolves to the BuddyPress profile when BP is active and the plugin's `/media/@user/` route otherwise.

* Fix: **Lightbox `Favorite` button is no longer rendered twice** in the action toolbar. Earlier 1.2.0 builds rendered a duplicate Favorite button on certain page contexts. Single button now, with the label flipping between "Favorite" and "Favorited" via the `aria-pressed` state.

* Fix: **Demo data importer now runs end-to-end on every install**. The `seed-demo-data.php` script (and its sibling `populate-showcase.php` + `cleanup-demo-data.php`) called `MediaRepository::*` static-style; the repository is a container service with instance methods only, so every demo seed attempt fataled with `cannot be called statically`. All 14 call sites swept to the canonical container-resolved instance API. Running the demo seeder now produces 50 media items + 5 demo users + 5 albums + 159 reactions + 30 comments + 40 favorites + 20 follows + 3 reports cleanly.

* Fix: **`AlbumService::create()`** added — the service had `add_items` / `get_items` / `set_cover` etc. but no top-level `create()` method, so any non-REST caller (seeder, future WP-CLI command, theme code) had to repeat the `wp_insert_post('mvs_album')` + privacy + group_id + categories meta writes inline. Centralised. The `AlbumController::create_item` REST endpoint now delegates to it.

* Fix: **`PUT /media/{id}` `allow_download` flag now accepts every body encoding**. The flag was previously read only from the JSON body via `$request->get_json_params()` — fine for JS apiFetch (the dominant path), but form-encoded clients and internal `$request->set_param()` calls silently dropped the flag. Now read via `$request->get_param()` which covers all sources uniformly.

* Fix: **`mvs_should_render_chat_panel` filter passes the resolved visibility setting as a second argument**, so callbacks can scope their override by the admin's chosen mode (`everywhere` / `mvs_pages` / `bp_pages`). Backward-compatible: existing 1-arg callbacks keep working; the new arg is just ignored if not declared.

* Fix: **Stats block fits on narrow phones**. The `mvs/media-stats` block grid used a 180 px minimum track which pushed the block 11 px past its container on viewports below ~390 px. Switched to `minmax(min(180px, 100%), 1fr)` so the minimum collapses gracefully; below 480 px cards stack one per row.

* Fix: DM access dropdown (Settings → Social → "Who can send me direct messages") no longer silently reverts "Nobody" or "Mutual followers only" to "Everyone" on save. Same root cause silently flipped the "Show online status" preference. The save path looked successful (admin notice "Settings saved." appeared) but the option stored a different value than the dropdown showed. After upgrading to 1.2.0, please reopen Settings → Social and confirm your preferred DM access and online-status visibility — the dropdown now reflects the saved value byte-for-byte. Affected since: 1.1.0. Commit: `d986525`.

**Architecture**

* New: `Core\SettingsHelper` — canonical static accessor for paired-plugin settings reads. First slot covers the page-id family (`dashboard` / `explore` / `upload`) plus `mvs_thumbnail_size` and `mvs_openai_api_key`. Pro and themes must use this instead of direct `get_option('mvs_page_*')` reads (Free invariant A4).
* New: Hook signatures now carry full type-annotated arg shapes in `audit/manifest.json` (`args_signature[]` on 14 of 22 hooks); enables Pro arch-check A11 to detect cross-plugin contract drift.
* New: `SettingsContractTest` enforces register_setting whitelist alignment — settings registration drift is now caught at unit-test time rather than at customer save-time.
* New: **Block standard alignment (Phase 7)** — Free's 9 registered Gutenberg blocks now share the same Spacing / Border / Shadow / Visibility inspector panels as Pro and wbcom-essential. `WPMediaVerse\Blocks\StandardAttributes` injects the 20 standard layout attrs via the `block_type_metadata` filter; `WPMediaVerse\Blocks\MVS_CSS` collects per-instance scoped CSS keyed off `mvs-block-{uniqueId}` and dumps it on `wp_footer`. Pro's `src/shared/` tree (17 files) ported with text-domain swaps.
* New: **`BaseBPTabIntegration` extracted** (Phase 5 P2.4) from `ProfileTabIntegration` + `GroupTabIntegration` — a single bug fix on either BP tab now propagates to both. Net delta -109 lines.

= 1.1.3 =
* Fix: Lightbox now opens the original full-size image instead of the low-res grid thumbnail. New Display setting "Lightbox Image Size" lets admins pick Original / Large / Medium / Auto (defaults to Original)
* Fix: Lightbox opens full-viewport in Facebook-style layout — image fills the left panel, social sidebar on the right; close button (X) correctly positioned and visible over the image panel
* Feature: Video uploads now get a real poster thumbnail extracted from the file's embedded cover atom via WP core's getID3 — no ffmpeg required. Works for phone-shot MP4/MOV; screen recordings without an embedded cover fall through to a native `<video preload="metadata">` preview in the grid so the browser paints the first frame — matching what the single media view already does
* Refactor: Media thumbnail rendering consolidated into a single source of truth. New `TemplateHelpers::media_thumbnail()` (PHP) and `mvsCardBuilders.buildThumbnail()` (JS) helpers used by Explore, BP activity, album/collection viewer, My Media dashboard, BP profile and group tabs, Pro Pinterest/Dribbble/Flickr/Instagram layouts. One branching logic for image / video-with-poster / native video preview / audio card / generic placeholder — every surface now handles each media type identically
* Enhancement: All close, dismiss, and navigation icons in the lightbox, upload modal, and toast notifications replaced with proper Lucide icons (rounded caps, correct paths). Lightbox CSS consolidated into frontend.css as a single source of truth
* Fix: Hardcoded emoji characters (play triangle, music note) replaced with inline Lucide SVGs across grids, dashboard, BP activity audio cards, and BP upload preview. WordPress was auto-converting the Unicode chars to emoji images, which looked different across browsers and didn't match the plugin's Lucide-based design
* Fix: Video, audio, and generic placeholders now share a unified frame (aspect-ratio + gradient background) so grids never collapse based on media type — any mix of image/video/audio uploads renders with consistent cell sizing
* Fix: BuddyPress activity stream thumbnails render reliably — defensive `file_url` fallback in `MediaDisplayHelper` when custom `thumb_*` meta is missing, and a path-5 recovery in `ActivityContentIntegration` that rebuilds the grid from `_mvs_media_ids` meta when an activity's saved content lost its markup
* Fix: Delete action no longer leaks onto public grids. The per-item trash icon now only renders on BuddyPress profile and group media tabs (where `show_actions` is explicitly opted in); Explore, Albums, and Collections never show it
* Fix: Settings sidebar brand icon is now clearly visible — the eyebrow-text rule was cascading gray onto the logo SVG, making it blend into the red gradient
* Fix: Dead meta-key reads cleaned up. `ActivityContentIntegration` and Pro's `TranscriptionService` were looking up `attachment_id` meta (dropped in migration v8) — both now use `wp_attachment_id` which importers actually write
* Feature: Thumbnail pipeline centralized in `UploadService::generate_thumbnails()` (now public). Pro CLI importers and MigrationPage delegate here via `Plugin::free_service('upload')`, so Free uploads and Pro imports share identical fallback, sizing, and logging. New `mvs_thumbnail_sizes` filter lets themes/add-ons tune sizes without patching
* Fix: Every upload path now guarantees all three thumbnail sizes (`thumb_large`, `thumb_medium`, `thumb_thumb`). WP's `multi_resize()` skips sizes that would upscale the source, so small images (under 1024px) used to leave `thumb_large` empty — now the pipeline backfills any missing size with `file_url` (the original IS the largest version)
* Fix: Demo data importer (`seed-demo-data.php` / Overview admin button) now routes through `UploadService::handle()` instead of inserting rows directly, so demo content exercises the full real-upload pipeline — including thumbnail generation, video poster extraction, `mvs_media_uploaded` hook, and LoggerService
* Fix: Silent failures in the thumbnail pipeline are now logged to `mvs_error_log` via `LoggerService` — missing source file, GD/Imagick unavailable, and `multi_resize()` returning empty each write a warning with the media ID for diagnostics
* Fix: Missing "Upload Page" setting added to Settings → General → Pages. The option was read in 3 places but had no admin UI, so custom [mvs_upload] shortcode pages could not be assigned
* Fix: Album cover selection now persists — picking a cover from the album edit page writes to the post meta instead of silently no-op'ing, and the album preview shows the chosen image
* Fix: Albums without an explicitly pinned cover fall back to the first image in the album so they never render with a broken/placeholder cover
* Fix: Lightbox "Favorite" label no longer ships with a duplicated heart emoji prefix — the Lucide icon renders alone as intended
* Fix: 5 free bug cards carried over from 1.1.2 — grid columns=5 rendering, stats page filter date ranges, tag cloud count accuracy, lightbox Favorite visibility for signed-in users, lightbox Share double-icon
* Fix: Thumbnails no longer return 403 errors for logged-in users; album cover thumbnails go through signed URL service for consistent access control
* Build: shared-ui Gutenberg blocks (view.js) now build as true ES modules via `npm run build` so the Interactivity API hydrates correctly — fixes `window.wp.interactivity is undefined` on block-rendered pages
* Security: `uninstall.php` now has an `ABSPATH` guard alongside the existing `WP_UNINSTALL_PLUGIN` check
* Docs: Service method docblocks (@param / @return) restored across Album, Moderation, and Tag services

= 1.1.2 =
* Fix: Setting Grid Columns to 5 now actually renders 5 columns on the Explore page, single-album view, collections, and dashboard grids (was collapsing to a single column because the 5-column CSS rule was missing)
* Fix: Stats page Today / This Week / This Month / All Time filters now change the Media count and Albums count — previously these cards ignored the date range and looked identical for every filter
* Fix: New tags now show up in the Explore tag cloud immediately after upload (tag count used to stay at 0 because WordPress couldn't count media stored in our custom table — now counted correctly for both tags and categories, plus a one-time backfill for existing tags)
* Fix: Favorite button in the lightbox is now visible to all signed-in users, including the media owner (matches the behaviour of the single-media page)
* Fix: Lightbox Share button no longer shows two icons — now uses the same clean Lucide icon set as the single-media page (Favorite / Share / Open / Report all unified)
* New: Add New Tag button on the Tags admin screen
* New: Sortable columns on the Tags admin table
* New: Back button on every detail page on mobile
* New: Touch targets now meet Apple's 44×44 minimum everywhere on mobile
* New: Floating upload button respects the iOS safe area (no more overlap with the home bar)
* New: Bottom-sheet modals on mobile — slide up from the bottom with a drag handle, like native iOS apps
* New: Sticky action bar on the single-media page on mobile — Like, Share, Edit, and Delete stay pinned to the bottom with a blurred backdrop
* New: Skeleton loaders while content is loading (smoother than spinners)
* New: Instant visual feedback when you tap Like, Favorite, or Follow — the button rolls back automatically if the action fails
* New: Tab strips on mobile now scroll horizontally with an edge fade and snap to the active tab
* New: Compact icon-with-tooltip buttons on dense action rows on mobile
* New: Lucide icons now ship with the plugin — no longer dependent on the active theme to load them
* New: Filters to let extensions register custom notification types (`mvs_notification_types`, `mvs_notification_message`)
* Fix: Bulk-deleting tags no longer produces an error
* Fix: Tag admin pagination count now matches the actual number of tags
* Fix: Sort order is preserved when using bulk actions on tags
* Fix: Deleting a tag now redirects back to a valid page instead of an error page
* Fix: Lightbox opens instantly from media grids — no extra loading delay
* Fix: Moderation queue's AI Flagged tab now correctly lists flagged media (was showing empty)
* Fix: Duplicate-upload "Warn" mode now actually shows a warning when a matching file already exists
* Fix: Fresh installs now have the default Allowed File Types ticked on first visit
* Fix: Album categories are now fully wired — create, update, filter by category, and see category links on single-album pages
* Fix: Demo data cleanup now also removes the tags created by the demo seeder
* Fix: "Allow per-upload privacy" admin setting is now honoured on every upload surface — block editor, BuddyPress activity form, and the backend upload handler
* Fix: BuddyPress activity upload now shows a privacy selector once a file is selected, when per-upload privacy is enabled
* Fix: User deletion and GDPR erasure now clean up all related data — access rules, access grants, view history, direct messages, and conversation participants — so no orphan records are left behind
* Security: Tag management screens now verify user capability and nonce on every action
* Enhancement: Updated translation template (POT) with all new strings

= 1.1.1 =
* Fix: Single media page — comments, reactions, favorites, follow, and report now work (Interactivity API store loading)
* Fix: Signed URL serving for all media files — images and videos load correctly with .htaccess protection
* Fix: Anonymous users can now view public media in lightbox without 401/403 errors
* Fix: Notification titles show correct media name from mvs_media_index (not WordPress post title)
* Fix: Notification owner lookup uses MediaRepository instead of get_post_field()
* Fix: DM notifications now fire when messages are sent via REST API
* Fix: Favorite notifications now fire on toggle
* Fix: Reaction counts properly sync to mvs_media_index on add/remove
* Fix: Delete cascade cleans up reactions, favorites, comments, mentions, album items, notifications, and activity
* Fix: Privacy enforcement on REST API — anonymous users blocked from members/private media
* Fix: Block bypass prevented — cannot follow a blocked user
* Fix: Profile Message button respects recipient-level DM privacy setting
* Fix: Messaging page dark mode uses theme data-theme attribute instead of OS prefers-color-scheme
* Fix: Messages page auto-loads conversations on /messages/ (was blank)
* Fix: Chat header avatar hidden when no conversation selected (no broken image)
* Fix: Report modal spacing between dropdown and buttons
* Fix: Allowed file types admin setting wired to frontend upload UIs
* Fix: Album upload links files to album after creation
* Fix: Album mode allows multiple file selection
* Fix: Admin grid columns setting is now the source of truth for all media-grid blocks
* Fix: Thumbnail style setting applied to explore and dashboard grids
* Fix: ID column added to admin All Media table
* Fix: Video thumbnail preview in upload modal (canvas frame capture)
* New: CLI command `wp mvs generate-video-thumbnails` — batch generates video thumbnails via ffmpeg
* New: Auto-generate video thumbnails from browser during upload
* New: Improved audio placeholder in media grid (gradient background, larger icon)
* Enhancement: Social settings docs updated to match actual implementation
* Enhancement: 609 CLI test assertions across 30 suites (was 271 across 14)

= 1.1.0 =
* New: Unified Load More across all layouts (event delegation, no page reloads)
* New: Full-grid lightbox navigation (prev/next browses all loaded items)
* New: Unified Moderation page — AI Flagged, Pending, User Reports (Pro), Resolved tabs
* New: Unified Stats page — Overview + Video Analytics (Pro) tab
* New: Activity logging for uploads, moderation, reports, and user actions
* New: Settings page header bar with version badge and Setup Wizard link
* New: Lightbox video and audio playback — native player controls for all media types
* New: Upload capabilities granted to all roles on activation (including custom and BuddyPress roles)
* Enhancement: Complete admin UX overhaul following wbcom-modern-admin rulebook
* Enhancement: CSS design token system — 20 semantic tokens replace 90+ hardcoded hex values
* Enhancement: Lightbox works for logged-out users (read-only reactions, comments visible)
* Enhancement: REST API tag, category, search, scope, group_covers filters + stats in response
* Enhancement: Cleaner admin menu — Migration, Reports, Analytics hidden from sidebar
* Enhancement: FAB upload button only shows on MVS pages for focused UX
* Enhancement: BuddyPress activity lightbox supports video and audio inline playback
* Fix: Delete button not working (state binding mismatch in confirm dialog)
* Fix: Media single URLs with underscores redirecting to wrong page
* Fix: Setup wizard page mapping using wrong page IDs after site reset
* Fix: Grid columns setting ignored due to block.json default override
* Fix: Toast notification bindings across all templates
* Fix: Notification items missing clickable links
* Fix: Album cover image quality upgraded from medium to large
* Fix: Share button link generation with proper error handling
* Fix: Default privacy not applied to new uploads
* Fix: BP Activity form script loading from wrong path
* Fix: Stats REST endpoint returns zeros for new media (was 404)
* Fix: Confirm dialog dynamic button labels (Report/Delete)
* Fix: Report dialog with reason dropdown selector
* Fix: Third-party notice suppression on all admin pages including CPTs
* Fix: Interactivity API shared-ui loading from build path
* Fix: moderation_status filter on explore page
* Fix: Unified per_page to use mvs_items_per_page setting everywhere
* Fix: Accessibility — visible focus rings, aria-current, aria-label, reduced-motion
* Fix: Removed all inline styles from admin PHP templates (14 instances)
* Security: Sanitized $_SERVER['REQUEST_URI'] in login redirect
* Security: Webhook SSL verify uses wp_is_local_environment for local dev
* Cleanup: Removed dead infinite scroll code
* Cleanup: Removed unused setup wizard permissions step

= 1.0.0 =
* Initial release — complete media platform for WordPress
* Custom database tables (mvs_media_index, mvs_media_meta, mvs_media_stats) — zero wp_posts pollution
* 38 features across core platform, social layer, BuddyPress integration, and developer tools
* 6-level privacy system with BuddyPress-aware fallback
* Full social layer: reactions (6 types), threaded comments, favorites, follows, DMs, @mentions, sharing
* AI moderation with OpenAI Vision — flag, quarantine, or reject uploads automatically
* 13 Gutenberg blocks powered by WordPress Interactivity API
* 8 shortcodes for embedding media features anywhere
* BuddyPress integration: activity uploads (1-6 per post), profile/group media tabs, lightbox in activity feed
* 80+ REST API endpoints across 17 controllers
* 8 WP-CLI commands for bulk operations and maintenance
* Outbound webhooks with HMAC-SHA256 signing via Action Scheduler
* Template override system
* GDPR data export and erasure

== Upgrade Notice ==

= 1.2.1 =
**Privacy + WAF fixes that affect live sites:** (1) BP activity privacy now follows media privacy — non-public media no longer leaks composer text/timestamp/author into the public activity stream (customer-reported, Zoho #39974). (2) CSS file `shared-ui-shell.css` renamed to `shared-ui-frame.css` because customer WAFs auto-block the "shell" token (Crisp #NZRSBX). (3) Cloud-storage public URLs are now opt-in per setting — review `mvs_cloud_direct_public_urls` if you flip your driver to S3/BunnyCDN. Plus 100k-readiness work (FULLTEXT search, view-retention cron, REST per_page hardening, AdminAggregatesService). Recommended for all sites.

= 1.2.0 =
Restores DM-access and online-status privacy preferences — they previously silently reverted to "Everyone" on save. Reopen Settings → Social after upgrading to confirm your saved values.

= 1.1.3 =
Fixes lightbox full-resolution images and full-viewport layout. Upgrade recommended for all users.

= 1.0.0 =
Initial release.

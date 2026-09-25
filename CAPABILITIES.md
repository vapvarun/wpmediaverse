# WPMediaVerse — Capabilities

What this plugin lets a site actually do, in buyer language. The plugin registers 92 REST
routes across 24 custom tables; that never says what they add up to. This file does.

**Last verified against code:** 2026-09-21 (v2.5.1, `main` at `04930487` - the tagged tree).
Re-checked this run: the counts (live registry + `Migrator::tables()`), every row the 2.4.1,
2.5.0 and 2.5.1 cycles touched - EXIF stripping, thumbnail quality, the privacy lock, cloud
demotion, albums and collections, blocking and moderation, the direct-access Site Health probe,
the document rows, telemetry (now removed) and the layout / width tokens - and the 2.5.1
authorisation round below. Rows nothing in those cycles touched carry their 2026-09-01
verification.

**What the 2.5.1 authorisation round changed.** One rule now decides which items of an album a
viewer may see (`AlbumService::viewable_item_ids()`), and the REST controller, the
`mvs/album-viewer` block and the `[mvs_album]` shortcode all call it - each previously carried
its own copy, and the copies in the two renderers listed private items to anyone. `[mvs_collection]`
gained the container privacy gate it never had. `GET /media/{id}/group` now applies the same
per-item check the rest of the read surface does. The private-community gate compares route
prefixes case-insensitively, because WordPress matches routes that way and the gate did not.
Block spacing and type values are checked against the units CSS allows. Four manifests in
`audit/` now declare who may reach every route, capability, render surface and admin action,
each backed by a test that fails the build when the code and the declaration disagree - see
`docs/standards/authorization-guards.md`.

**Companion:** [WPMediaVerse Pro](../wpmediaverse-pro/) adds competitions, cloud storage,
AI providers, quotas, documents and video tooling — see its own `CAPABILITIES.md`.

Status key: **YES** shipped and code-verified · **PARTIAL** works with a named limit ·
**PLANNED** designed, unbuilt · **NO** absent.

> **How the counts were taken (2026-09-19).** Live registry, not a grep: with both plugins
> active, `rest_get_server()->get_routes()` filtered to the `mvs/v1` namespace gives **92
> routes** (excluding the namespace index), which expand to **122 route+method endpoints**
> because several routes carry more than one verb. Both numbers are honest; they count
> different things, and both are unchanged from 2.4.0. Tables: **24** distinct `mvs_*` names,
> the list `Core\Migrator::tables()` returns and `UninstallCoverageTest` asserts against a
> freshly migrated database (25 `CREATE TABLE` statements - `mvs_media_index` is created
> twice). The twenty-fourth is `mvs_media_spaces`, added by migration v33 in 2.5.1 to link one
> document into more than one space. Blocks: **9** in `Blocks\BlockRegistrar::BLOCKS`.
> Shortcodes: **14** `add_shortcode()` calls in `Shortcodes\Shortcodes`. WP-CLI: **21**
> subcommands (20 public methods on `CLI\Commands` plus `wp mvs cert`).
>
> Earlier figures in circulation were wrong in both directions: this file used to say
> "~90 REST routes" and CLAUDE.md said "114 REST endpoints". Neither matched 2.4.0. The
> manifests, CLAUDE.md and this file were all reconciled to one source on 2026-09-01:
> 122 Free endpoints, 109 Pro. Two independent methods agree on the Free number - a live
> `rest_get_server()->get_routes()` walk and a static parse of every register_rest_route()
> call site. Free's number has held through 2.5.1; **Pro's has not and cannot**, because
> Pro registers controllers behind owner toggles - see Pro's own count note.

---

## Media library

| Can it… | Status | How |
|---|---|---|
| Let members upload photos, video and audio | **YES** | `Services/UploadService.php` — MIME allowlist, SHA-256 duplicate detection (`allow`/`warn`/`skip`), EXIF auto-rotate, and a 24-entry dangerous-extension blocklist that also catches double extensions |
| Strip the location out of a photo | **YES** | `mvs_strip_exif` (on by default). Since 2.5.1 it is **GPS-only**: `UploadService::strip_gps_ifd()` unlinks the GPS pointer from IFD0 and zeroes that directory in place, so camera, lens, exposure and the IPTC/XMP copyright and credit survive - the old code took the whole APP1 segment, and only when a GPS block was present. It fails closed: anything the parser cannot read falls back to removing the segment. The same strip now runs on `/media/{id}/replace`, and extracted EXIF is no longer copied into `mvs_media_meta` (migration v34 deletes what was stored) |
| Accept documents into the **media library** | **NO** | `UploadService::reject_unsupported_mime()` hard-refuses `application/pdf` via `hard_refused_mimes()`; Office types fall to the generic unsupported-type refusal. The same guard runs on `/replace`, so there is no bypass. Documents have their own home — see the Documents row below |
| Generate thumbnails at multiple sizes | **YES** | `UploadService::generate_thumbnails()` writes `large`/`medium`/`thumb` (filterable via `mvs_thumbnail_sizes`); `Services/MediaVariantWriter.php` + `VariantSpec` record and validate the resulting variant metadata — they do not generate it. Which rung a grid serves is the owner's Thumbnail Quality setting, which since 2.5.1 actually decides it: all three choices used to collapse to `large`, so the control moved nothing. `large` is now the registered default (the rung grids have served since 1.8.0), `medium` is honoured, and `full` still maps to `large` - the original file is not a thumbnail rung |
| Serve modern formats (WebP / AVIF) | **YES** | `Services/ImageOptimizationService.php` emits WebP and AVIF siblings; `SignedUrlService` sniffs `Accept` on the gated `/serve` route and prefers AVIF, then WebP. Note AVIF generation is **off by default** (`mvs_generate_avif`) |
| Produce video posters | **YES** | `Services/PosterService.php` — getID3 embedded cover atom, or a client-supplied frame staged at upload. No ffmpeg: MediaVerse embeds media, it does not process it. A cover-less video falls back to `assets/images/default-video-poster.svg` |
| Produce audio artwork | **PARTIAL** | Embedded cover art is used when the file has one. When it does not, `TemplateHelpers::render_audio_waveform_svg()` draws a **decorative** waveform whose bar heights come from a SHA-1 of the media ID — it is not an analysis of the audio, and it is `aria-hidden` |
| Re-compress originals to save space | **YES** | `ImageOptimizationService` re-encodes at `mvs_optimize_jpeg_quality` (default 92) and commits only if the result is smaller, via temp-write-compare-rename. **Not lossless** — on a source better than q92 it trades quality for bytes; on a source already at or below q92 it changes nothing |
| Search the library | **YES** | MySQL `MATCH…AGAINST` over a dedicated `media_search_ft` FULLTEXT index (Migrator v13), with a `LIKE` fallback where InnoDB FULLTEXT is unavailable. `?s=` on the media, album and collection routes; debounced search in the explore-feed block; filters on the admin list |
| Organise into albums | **YES** | `Services/AlbumService.php` + `mvs_album_items`. Bulk "Add to album" on the dashboard and Explore bars runs through `AlbumService::add_items()` since 2.5.1, so a batch gets the same album pointer, privacy clamp, playlist audio-only check and `mvs_album_items_added` event as a single add - it used to insert rows directly and skip all four. The control says Add, not Move, because an item stays in any other album it is in |
| Organise into rule-based collections | **YES** | `Services/CollectionService.php` — rules saved to post meta and evaluated in `resolve()`. All three surfaces: `templates/collection.php`, `Admin/CollectionMetaBox.php` rule builder, `REST/Controller/CollectionController.php` |
| Keep a collection to logged-in members | **YES** | `CollectionService::PRIVACY_LEVELS` - deliberately **two** levels, `public` and `members`, not the nine a media item gets (owner ruling 2026-09-13: a collection is a curated surface made to be shown). Shipped 2.5.0; before that the level had nowhere to be stored, so every collection on every site was public whatever the docs said. `get_privacy()` coerces any other stored value back to public |
| Keep other members' private media out of a collection | **YES** | `CollectionService::may_contain()` - public media, or the curator's own, and never a document. Asked by both membership stores (Free's and Pro's `mvs_pro_collection_items`) through the container, so the two cannot drift; the REST route answers 403 and the store refuses the write independently |
| Tag and categorise | **YES** | `Taxonomies/MediaTag.php`, `MediaCategory.php`. Registered against `mvs_album` as a registration vehicle and counted against `mvs_media_index` — deliberate, not a normal post-type taxonomy |
| Merge and manage tags | **YES** | `Admin/TagManagementPage.php` + `POST /mvs/v1/tags/merge` |
| Replace a file while keeping its metadata | **YES** | `POST /mvs/v1/media/{id}/replace` — updates the row in place, so the media ID, stats, reactions, views and album membership all survive; only stale thumbnail/variant meta is cleared |
| Bulk-manage from the admin | **YES** | `Admin/MediaListPage.php` — bulk trash/restore/delete, per-row optimisation, details view |
| Trash and restore media | **PARTIAL** | Soft delete is real (`MediaRepository::trash()` / `restore()`, `mvs_media_trashed` / `mvs_media_restored`) and the admin list has a full Trash view with counts. **Admin-only**: there is no REST route and no member-facing trash, so a native app cannot list or restore a member's own trashed items |

## Privacy and access

| Can it… | Status | How |
|---|---|---|
| Set per-item privacy | **YES** | `Services/PrivacyService.php` — nine levels: `public`, `members`, `loggedin`, `friends`, `group`, `space`, `private`, `dm`, `custom` (filterable via `mvs_privacy_levels`). `space` is a first-class level enforced in Free, not a Pro-only concept |
| Take the privacy choice away from members | **YES** | Settings > General > "Allow Users to Set Privacy" (`mvs_allow_user_privacy`). One answer for every surface since 2.5.1 (`PrivacyService::user_may_choose_privacy()`): read by the upload path, the album create/update path, the BuddyPress activity form and picker, every picker in the templates and the Media Upload block. `PUT /media/{id}` and the bulk privacy action refuse a **change** with 403 `mvs_privacy_locked` (re-sending the unchanged level still saves, because the edit screens send it). Anyone with `manage_mvs_settings` keeps the control. Before 2.5.1 only the upload surfaces read the switch, so a member could upload at the default and change the level one click later |
| Keep private media off disk-guessable URLs | **YES** | `Services/SignedUrlService.php` — HMAC-SHA256 signature, `hash_equals` comparison, TTL default 3600s with a 60s floor |
| Re-check permission on every delivery | **PARTIAL** | `can_view()` is now re-checked per request for **every** item, public included. 2.5.0 dropped the `'public'` short-circuit in `serve()` because the same gate also enforces blocking and moderation, and a rejected or blocked item served straight from its signed URL was the gap. One limit remains, and it is deliberate: a validly-signed but **expired** URL for public media is still served so page caches do not break (`mvs_serve_expired_public_urls`, filterable to off) |
| Keep private files off the CDN | **YES** | `StorageService::get_driver_for_privacy()` — one line: public gets the configured driver, everything else gets the local driver |
| Take a file back off the CDN when it stops being public | **YES** | Since 2.5.1. `sync_urls_on_privacy_change()` re-pointed the row at its local copies but left the object in the bucket, so the old CDN URL kept working for anyone holding it, a revoked permission that revoked nothing. A listener at priority 6 now queues a repatriation that runs `CloudOps::migrate_one( id, <driver>, 'local', keep_source: false )`: original and every recorded variant moved back, verified locally, then deleted from the cloud. No per-driver delete code, so nothing to drift |
| Grant access to named users | **REMOVED** | Per-media access rules and grants (`mvs_access_rules`, `mvs_access_grants`) were removed in 2.6.0. Access is decided by the item's privacy level (`public`, `members`, `friends`, `group`, `private`, or a `custom` list), plus Pro Documents sharing with specific people or a group |
| Sell access to a media item | **NO** | Previously listed here as PARTIAL; that was wrong. `mvs_access_rules` has `price`/`currency` columns but the code marks them "Reserved for future use" and nothing evaluates them — they are stored and echoed back, never charged. `mvs_transactions` is **not** a payments ledger; it is a per-media-type usage ledger (`delta`, `balance_after`, `reason`) with no amount, currency or gateway. There is no checkout anywhere in Free or Pro |
| Show a member their usage history | **YES** | `Services/TransactionService.php` — append-only ledger with running balance, `GET /mvs/v1/me/transactions`, `[mvs_usage_history]`, `templates/partials/usage-history.php` |
| Make the whole API private for a closed community | **YES** | `REST/CommunityPrivacyGate.php` on `rest_pre_dispatch` — armed via `mvs_rest_require_auth` (default off), used by BuddyNext. Credential-bearing routes (`/serve`, `/app/config`) stay exempt |
| Rate-limit abusive callers | **YES** | `REST/RateLimiter.php`, applied per action (e.g. block-user at 10/60s), plus `RestGate` / `RestGuards` |
| Export and erase a member's data (GDPR) | **YES** | `Services/GDPRService.php` registers `wp_privacy_personal_data_exporters` / `_erasers`; `Privacy/MemberDataMap.php` + `MemberPurger.php` do the work, including bidirectional cleanup of `mvs_follows` / `mvs_blocks` / `mvs_reports` |
| Let a member delete their own account | **YES** | `Services/AccountDeletionService.php` + `DELETE /mvs/v1/me/deletion` — a grace period (`mvs_account_deletion_grace_days`) the member can cancel, executed on cron, with optional password confirmation |

## Community and social

| Can it… | Status | How |
|---|---|---|
| React to media with emoji | **YES** | `Social/ReactionService.php` |
| Comment, with an edit window | **YES** | `Social/CommentService.php` — WP comments under its own `comment_type`; edit window is a real time bound (`mvs_comment_edit_window`, default 15 min) plus a separate 60s duplicate guard |
| Favourite / bookmark | **YES** | `Social/FavoriteService.php` |
| Follow other members | **YES** | `Social/FollowService.php` — frontend and REST. No admin surface for follows |
| Suggest people to follow | **YES** | `Social/SuggestionService.php` + `GET /mvs/v1/users/suggested` — cached global candidate pool, per-viewer filtering and an interest boost, to solve the cold start |
| @mention people | **PARTIAL** | `Social/MentionService.php` works end to end — mention a member in a comment and `mvs_mentions_created` raises an in-app and BuddyPress notification. But `parse_and_store()` is called from **comments only** (media descriptions are never scanned), there is no @-autocomplete anywhere in the UI, and `get_for_user()` has no REST route, so a member cannot list their mentions |
| Show an activity feed | **YES** | `Social/ActivityService.php`. Private and DM uploads deliberately write no activity row, and are filtered again at read time |
| Notify members in-app | **YES** | `Social/NotificationService.php` + `/me/notifications`, `/count`, `/read` |
| Direct-message with attachments | **YES** | `Messaging/MessagingService.php` — group conversations, voice messages, message reactions and unsend. Attachments are media-only, verified by `finfo_file()` rather than by extension, 10 MB cap; PDFs excluded since 2.2.0 and still excluded |
| Let members report content | **YES** | `Social/ReportService.php` — auto-hide at `mvs_report_auto_hide_threshold` (default 3), master switch `mvs_enable_reports`, admin screen `Admin/ReportsPage.php` |
| Block another member | **YES** | `Social/ReportService.php` (`block_user()`, `is_blocked_either_way()`) backed by `mvs_blocks`; `templates/partials/blocked-members.php` and `POST`/`DELETE /mvs/v1/users/{id}/block`. Since 2.5.0 a block actually hides things: the shared visibility gate refuses a blocked pair's media, their profile listing and its counts, and Explore filters blocked members on page 1 as well as later pages (it used to filter only after the first page). Limit worth knowing: **no admin surface** — a site owner cannot see, audit or undo a block from wp-admin |

## Running the site

| Can it… | Status | How |
|---|---|---|
| Moderate uploads before they appear | **YES** | `Services/ModerationService.php` + `Admin/ModerationQueue.php`, single and bulk approve/reject, plus REST. Since 2.5.0 the decision is enforced in `PrivacyService::check_access()` rather than as an opt-in query argument one template passed, so a flagged or explicitly rejected item is refused at its permalink, its signed file URL and its thumbnail URL too. Owners and moderators still see it, so a takedown stays reviewable |
| Auto-moderate with AI | **YES** | `Services/AIService.php` + `OpenAIProvider`; the monthly budget cap (`mvs_ai_monthly_budget`) gates the moderation path too, not just describe/tag. More providers in Pro |
| Auto-describe and auto-tag with AI | **YES** | Per-feature toggles `mvs_ai_auto_describe`, `mvs_ai_auto_tag` (both default on, both actually read) |
| Watermark uploads | **NO** | Previously listed here as YES; that was false. Free ships `Services/WatermarkService.php`, but it only *resolves* configuration and fires `mvs_watermark_stamp_file` — **nothing in Free listens**, so the filter returns `false` and no pixels are ever drawn. Free also has no watermark settings screen: it reads options (`mvs_watermark_enabled`, `_apply`, `_roles`) that only Pro's settings page writes. Both halves — the settings UI and the stamping (`Core/Watermarker.php`) — are Pro's. On a Free-only site this capability does not function at all |
| Suspend a member | **YES** | `Admin/MemberModeration.php` sets `mvs_suspended`; `RestGuards::deny_if_suspended()` enforces it on every write — which is the only thing that stops a member holding a valid Application Password, since core skips the login filter chain for those |
| See usage statistics | **YES** | `Services/StatsService.php`, `Admin/StatsPage.php`, all site-wide aggregates through `AdminAggregatesService` |
| Read the plugin's own error log | **YES** | `Services/LoggerService.php` + `Admin/LogViewerPage.php` (`mvs_error_log`), pruned daily |
| Get set up without reading docs | **YES** | `Admin/SetupWizard.php` — guided first-run configuration |
| Send events to another system | **YES** | `Integrations/WebhookService.php` — 5 events (`media.uploaded`, `.deleted`, `.moderated`, `.reaction`, `.comment`), signed payloads, up to 3 retries. Retries need Action Scheduler present; without it a failed delivery is logged to `mvs_webhook_failures` and dropped |
| Diagnose a broken install | **YES** | `Services/HealthCheckService.php` registers **four** Site Health tests: database tables, upload directory, required pages, and, added in 2.4.1, Media Privacy, which writes a canary into the upload directory and fetches it over HTTP (`probe_public_access()`) instead of trusting the deny file. When the directory answers 200 it prints the exact nginx rule to add (`nginx_rule()`). (It does **not** test variants or any storage driver — an earlier version of this file claimed it did) |
| Run scheduled maintenance | **YES** | Daily cron for log pruning (`mvs_prune_logs`) and view retention (`mvs_purge_old_views`), plus account-deletion processing |
| Manage from the command line | **YES** | 21 subcommands — `wp mvs` (20: optimise, migrate-storage, repair-storage, relocalize-private, reindex, backfill-ai, regenerate-thumbnails, cleanup-local, …) plus `wp mvs cert` |
| Register mobile devices for push | **YES** | `Social/PushService.php` + `POST`/`DELETE /mvs/v1/me/devices` (`REST/Controller/DeviceController.php`), `mvs_device_tokens`. Read this precisely: MediaVerse **stores tokens and fires `mvs_push_send`** (gated by `mvs_push_should_send`) — it never talks to FCM or APNs itself. With no delivery integration installed, nothing arrives on a handset |
| Collect anonymous usage telemetry | **NO** | Removed in 2.5.1, not deferred. `Services/TelemetryService.php`, its Storage-tab setting and its container registration are gone, and migration v36 deletes `mvs_telemetry_enabled`, `_counters` and `_since`. It had no `capture()` call sites and no reader, and by design transmitted nothing, so the report its own description asked owners to share could not be produced even in principle |

## Storage

| Can it… | Status | How |
|---|---|---|
| Store on local disk | **YES** | `Services/LocalDriver.php`; private delivery goes through the signed `/serve` route rather than a direct file URL |
| Block direct web access to the upload directory | **PARTIAL** | A deny-all `.htaccess` plus an `index.php` are written at activation and by `LocalDriver`. The payload is still Apache 2.2 syntax (`Order deny,allow` / `Deny from all`) with no `Require all denied` fallback and **no nginx equivalent** — on nginx the directory rule does nothing, and the real protection is the signed `/serve` route. What 2.4.1 changed is that the site now **finds out**: the Media Privacy Site Health test fetches a canary from the directory and fails with the nginx rule to paste when the file comes back |
| Keep one file tree that never leaves the server | **YES** | `mvs_local_only_path_prefixes` (2.5.1) - a filter naming folders stored relative to `uploads/` that no cloud driver may touch. `LocalDriver::resolve()` picks the base by prefix and refuses unsafe paths (dot segments, absolute, drive letters, NUL, symlink escape); `url()` is empty for them, so they can only be served through a gated route; `CloudOps` excludes them from the migrate candidate list, the count, `migrate_one()` and `cleanup_local_one()`, and `StorageService::relocalize_media_urls()` skips them. Pro declares `wpmediaverse-documents` through it |
| Store on a CDN or object store | **PARTIAL** | The driver contract (`StorageDriverInterface`) is in Free and `LocalDriver` is its only implementation here. S3, BunnyCDN, R2 and DigitalOcean Spaces ship in Pro |
| Move a library between backends | **PARTIAL** | `Services/CloudOps.php` + `wp mvs migrate-storage` (batched via `--limit`, safe to re-run) ship in Free, but with no cloud driver in Free `get_driver()` always resolves to local — every documented example (`--to=s3`, `--to=bunnycdn`) needs Pro |
| Recover from a half-finished migration | **YES** | `Services/StorageRepairService.php`, `wp mvs repair-storage`, `wp mvs relocalize-private` (both with `--dry-run`) |

## Surfaces and integration

| Can it… | Status | How |
|---|---|---|
| Render without writing code | **YES** | 9 registered blocks + 14 shortcodes |
| Choose how a grid draws itself | **YES** | Default Layout (`grid_layout_class()`) - justified rows (`original`, the default), square and list. Named honestly since 2.5.0: the free grid is Justified rows, not Masonry, and List renders as a list. Since 2.5.1 the setting also reaches **album and collection pages**, which its description has always named and which used to emit a fixed square grid - an update therefore changes how those two pages look on a site that never touched the setting |
| Fit the site's own width, spacing and type | **YES** | `--mvs-content-width` (declared on `:root`, default 1200px) is read by the dashboard, Explore, single album, single collection, the banner and the messages page, so one line in a child theme moves every MediaVerse surface together; `.mvs-dashboard-grid` and `.mvs-feed` read `--mvs-grid-gap` the same way. Buttons, selects and inputs inherit the theme's font rather than imposing a system stack. Documented in `docs/website/developer-guide/template-overrides.md` |
| Give members a dashboard and profile | **YES** | `templates/partials/dashboard-content.php`, `templates/profile-edit.php`, `templates/partials/profile-edit-panel.php` |
| Let members set an avatar | **YES** | `POST`/`DELETE /mvs/v1/me/avatar` with a Gravatar fallback; album and collection covers via `/{id}/cover` |
| Work inside BuddyPress | **YES** | `Integrations/BuddyPress/` — 11 classes covering activity content, activity form, activity/media linkage, activity privacy, sync, profile tabs, group tabs and notifications |
| Hand the frontend to another community plugin | **YES** | The `mvs_buddynext_active` filter (default `false`) — MediaVerse stands down its own assets, panels and notification bell, and BuddyNext owns the UX |
| Drive the product from a native app | **PARTIAL** | The `mvs/v1` surface is broad — 92 routes, Application Passwords via `Auth/AppConnect.php`, public `GET /mvs/v1/app/config` for pre-login discovery, `/auth/app-password`, `/auth/nonce`, `/me/devices`. It is not yet *complete*: **trash/restore has no route**, **mentions have no route**, and suspension state is not exposed, so an app cannot let a member manage their trash, list their mentions, or explain why a suspended write failed |
| Attach media to another plugin's objects | **YES** | `Media/ObjectMediaLinkage.php` — provider-neutral `object_type` linkage (`bn_post`, `bp_activity`, …). The backing table name is BP-legacy; the API is not |
| Host a document library | **PARTIAL** | Free owns the foundations: documents live in Free's `mvs_media_index`, with `DocumentTypes`, the `mvs_documents_enabled` master switch, the `use_mvs_documents` capability, an admin screen (`Admin/DocumentListPage.php`), a public listing via `[mvs_documents]`, and the `mvs_documents_drive_html` / `mvs_document_drive_access` seams. The member drive — upload, folders, sharing, trash, search and every document REST route — is Pro's. Four things moved in Free this cycle: documents leave the media grid and the All Media type filter and have their own menu (2.5.0); a document previews **inside the lightbox** instead of only offering a download (2.5.0); a permanently deleted document is now removed from disk, not only from the index (`LocalDriver::resolve()` was looking under `uploads/wpmediaverse/` while documents live under `uploads/wpmediaverse-documents/`, and `delete()` reported success finding nothing); and `MediaRepository::drive_documents()` takes a `visible_to` spec so Pro's permission ladder is applied in SQL - a bounded COUNT plus one page query at any drive size, instead of a whole-drive scan |
| Link one document into more than one space | **YES (storage + API)** | `mvs_media_spaces` (migration v33) and `Repository/MediaSpaceRepository.php` in Free; the rules about who may write a link, and the REST routes, are Pro's. Free's drive listing unions the links in, so a file linked into a space appears at that space's root whatever folder it is filed in at home |
| Import from rtMedia / MediaPress / BuddyBoss | **PARTIAL** | The importers themselves are Pro. Free carries the post-import half: legacy meta-key remapping, rtMedia activity-HTML rewriting for display, and `wp mvs backfill-activity-thumbnails --source=rtmedia\|mediapress\|buddyboss` |

---

## Deliberate absences

Recorded so a future audit treats them as decisions, not gaps.

| Not supported | Why |
|---|---|
| Document / PDF uploads **into the media library** | Owner decision (Basecamp #9962125462), enforced by `UploadService::hard_refused_mimes()` and shared with the `/replace` path so there is no bypass. The read path survives for historical PDFs. Documents are not absent from the product — they have their own home (see the document-library row above) |
| Video transcoding / re-encoding | Removed in 2.4.0. MediaVerse embeds media, it does not process it. There is no ffmpeg and no exec-family call anywhere in the source — Coding Rule 21 bans them outright, and the ban is grep-verified in both plugins |
| Storefront / checkout for paid media | Nothing in Free or Pro charges for access. The `price`/`currency` columns on `mvs_access_rules` are reserved and unread; `mvs_transactions` is a usage ledger, not a payments ledger |
| Cloud storage drivers in Free | Contract in Free, implementations in Pro — deliberate free/pro split |
| Watermarking in Free | Same free/pro split: Free resolves configuration and fires the seam; Pro owns both the settings screen and the code that draws the mark |
| Versioning of media files | `/replace` overwrites; no version history by design |
| Anonymous usage telemetry | Shipped disabled, counted nothing, and was removed in 2.5.1 (migration v36 deletes its options). The question it was written for, which routes and hooks customers actually use, is answered by support tickets, the hook manifest, the journey suite and the REST catalogue, none of which ask an owner to opt into data collection |
| Email digests | No digest, roll-up or scheduled-summary email exists. Notifications are in-app (plus BuddyPress where installed) |
| Sending pushes | The plugin registers device tokens and fires a hook. Talking to FCM/APNs belongs to the app's backend, which owns those credentials |

---

## Not verified this run

Honest gaps in the 2026-09-19 pass, so nobody reads a blanket verification into the date above.

| Row | What is still unproven |
|---|---|
| Block direct web access to the upload directory | The Apache-2.2-only syntax was confirmed by reading the written payload. What an Apache 2.4 host without `mod_access_compat` actually does with it (ignore vs. 500) was not tested on a real server. The 2.4.1 HTTP probe that makes the problem visible was read in code, not exercised against nginx |
| Serve modern formats (WebP / AVIF) | The AVIF encode path and the `Accept` negotiation were confirmed in code. An end-to-end browser check that a real AVIF byte stream reaches a modern browser from `/serve` was not run |
| Take a file back off the CDN when it stops being public | The listener, the queued repatriation and `migrate_one( …, keep_source: false )` were read end to end. No live demotion against a real bucket was performed this run, so "the object is gone from the CDN" is code-verified, not observed |
| Strip the location out of a photo | `strip_gps_ifd()` and its fail-closed fallback were read line by line, as was migration v34 which clears the stored `exif_raw`. No byte-level check of a stripped JPEG in an EXIF reader was done this run |

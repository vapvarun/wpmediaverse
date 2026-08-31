# WPMediaVerse — Capabilities

What this plugin lets a site actually do, in buyer language. The plugin registers 92 REST
routes across 23 custom tables; that never says what they add up to. This file does.

**Last verified against code:** 2026-09-01 (v2.4.0). Every row below was re-checked against
source on this date — the previous "2026-08-26" line predated the 2.4.0 release and several
rows had drifted. Two rows are marked `UNVERIFIED` where the claim needs a browser run rather
than a code read; they say so in place.

**Companion:** [WPMediaVerse Pro](../wpmediaverse-pro/) adds competitions, cloud storage,
AI providers, quotas, documents and video tooling — see its own `CAPABILITIES.md`.

Status key: **YES** shipped and code-verified · **PARTIAL** works with a named limit ·
**PLANNED** designed, unbuilt · **NO** absent.

> **How the counts were taken (2026-09-01).** Live registry, not a grep: with both plugins
> active, `rest_get_server()->get_routes()` filtered to the `mvs/v1` namespace gives **92
> routes** (excluding the namespace index), which expand to **122 route+method endpoints**
> because several routes carry more than one verb. Both numbers are honest; they count
> different things. Tables: **23** distinct `mvs_*` names created by `Core\Migrator`
> (24 `CREATE TABLE` statements — `mvs_media_index` is created twice). Blocks: **9** in
> `Blocks\BlockRegistrar::BLOCKS`. Shortcodes: **14** `add_shortcode()` calls in
> `Shortcodes\Shortcodes`. WP-CLI: **21** subcommands (20 public methods on `CLI\Commands`
> plus `wp mvs cert`).
>
> Earlier figures in circulation were wrong in both directions: this file used to say
> "~90 REST routes" and CLAUDE.md said "114 REST endpoints". Neither matched 2.4.0. The
> manifests, CLAUDE.md and this file were all reconciled to one source on 2026-09-01:
> 122 Free endpoints, 109 Pro. Two independent methods agree on the Free number - a live
> `rest_get_server()->get_routes()` walk and a static parse of every register_rest_route()
> call site.

---

## Media library

| Can it… | Status | How |
|---|---|---|
| Let members upload photos, video and audio | **YES** | `Services/UploadService.php` — MIME allowlist, SHA-256 duplicate detection (`allow`/`warn`/`skip`), EXIF strip behind `mvs_strip_exif`, EXIF auto-rotate, and a 24-entry dangerous-extension blocklist that also catches double extensions |
| Accept documents into the **media library** | **NO** | `UploadService::reject_unsupported_mime()` hard-refuses `application/pdf` via `hard_refused_mimes()`; Office types fall to the generic unsupported-type refusal. The same guard runs on `/replace`, so there is no bypass. Documents have their own home — see the Documents row below |
| Generate thumbnails at multiple sizes | **YES** | `UploadService::generate_thumbnails()` writes `large`/`medium`/`thumb` (filterable via `mvs_thumbnail_sizes`); `Services/MediaVariantWriter.php` + `VariantSpec` record and validate the resulting variant metadata — they do not generate it |
| Serve modern formats (WebP / AVIF) | **YES** | `Services/ImageOptimizationService.php` emits WebP and AVIF siblings; `SignedUrlService` sniffs `Accept` on the gated `/serve` route and prefers AVIF, then WebP. Note AVIF generation is **off by default** (`mvs_generate_avif`) |
| Produce video posters | **YES** | `Services/PosterService.php` — getID3 embedded cover atom, or a client-supplied frame staged at upload. No ffmpeg: MediaVerse embeds media, it does not process it. A cover-less video falls back to `assets/images/default-video-poster.svg` |
| Produce audio artwork | **PARTIAL** | Embedded cover art is used when the file has one. When it does not, `TemplateHelpers::render_audio_waveform_svg()` draws a **decorative** waveform whose bar heights come from a SHA-1 of the media ID — it is not an analysis of the audio, and it is `aria-hidden` |
| Re-compress originals to save space | **YES** | `ImageOptimizationService` re-encodes at `mvs_optimize_jpeg_quality` (default 92) and commits only if the result is smaller, via temp-write-compare-rename. **Not lossless** — on a source better than q92 it trades quality for bytes; on a source already at or below q92 it changes nothing |
| Search the library | **YES** | MySQL `MATCH…AGAINST` over a dedicated `media_search_ft` FULLTEXT index (Migrator v13), with a `LIKE` fallback where InnoDB FULLTEXT is unavailable. `?s=` on the media, album and collection routes; debounced search in the explore-feed block; filters on the admin list |
| Organise into albums | **YES** | `Services/AlbumService.php` + `mvs_album_items` |
| Organise into rule-based collections | **YES** | `Services/CollectionService.php` — rules saved to post meta and evaluated in `resolve()`. All three surfaces: `templates/collection.php`, `Admin/CollectionMetaBox.php` rule builder, `REST/Controller/CollectionController.php` |
| Tag and categorise | **YES** | `Taxonomies/MediaTag.php`, `MediaCategory.php`. Registered against `mvs_album` as a registration vehicle and counted against `mvs_media_index` — deliberate, not a normal post-type taxonomy |
| Merge and manage tags | **YES** | `Admin/TagManagementPage.php` + `POST /mvs/v1/tags/merge` |
| Replace a file while keeping its metadata | **YES** | `POST /mvs/v1/media/{id}/replace` — updates the row in place, so the media ID, stats, reactions, views and album membership all survive; only stale thumbnail/variant meta is cleared |
| Bulk-manage from the admin | **YES** | `Admin/MediaListPage.php` — bulk trash/restore/delete, per-row optimisation, details view |
| Trash and restore media | **PARTIAL** | Soft delete is real (`MediaRepository::trash()` / `restore()`, `mvs_media_trashed` / `mvs_media_restored`) and the admin list has a full Trash view with counts. **Admin-only**: there is no REST route and no member-facing trash, so a native app cannot list or restore a member's own trashed items |

## Privacy and access

| Can it… | Status | How |
|---|---|---|
| Set per-item privacy | **YES** | `Services/PrivacyService.php` — nine levels: `public`, `members`, `loggedin`, `friends`, `group`, `space`, `private`, `dm`, `custom` (filterable via `mvs_privacy_levels`). `space` is a first-class level enforced in Free, not a Pro-only concept |
| Keep private media off disk-guessable URLs | **YES** | `Services/SignedUrlService.php` — HMAC-SHA256 signature, `hash_equals` comparison, TTL default 3600s with a 60s floor |
| Re-check permission on every private delivery | **PARTIAL** | `can_view()` is re-checked per request for every non-public item. **Public** media short-circuits the check (`'public' !== $privacy && …`), and a validly-signed but expired URL for public media is still served by default so page caches do not break (`mvs_serve_expired_public_urls`). Both are deliberate; neither is "every request" |
| Keep private files off the CDN | **YES** | `StorageService::get_driver_for_privacy()` — one line: public gets the configured driver, everything else gets the local driver |
| Grant access to named users | **PARTIAL** | The engine is complete — `mvs_access_rules` + `mvs_access_grants`, `POST /mvs/v1/media/{media_id}/grant`, `DELETE …/grant/{user_id}`, `GET /me/grants` — but it is **REST-only**. No admin screen and no member UI creates a grant, so today it needs an API client |
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
| Block another member | **YES** | `Social/ReportService.php` (`block_user()`, `is_blocked_either_way()`) backed by `mvs_blocks`; `templates/partials/blocked-members.php` and `POST`/`DELETE /mvs/v1/users/{id}/block`. Limit worth knowing: **no admin surface** — a site owner cannot see, audit or undo a block from wp-admin |

## Running the site

| Can it… | Status | How |
|---|---|---|
| Moderate uploads before they appear | **YES** | `Services/ModerationService.php` + `Admin/ModerationQueue.php`, single and bulk approve/reject, plus REST |
| Auto-moderate with AI | **YES** | `Services/AIService.php` + `OpenAIProvider`; the monthly budget cap (`mvs_ai_monthly_budget`) gates the moderation path too, not just describe/tag. More providers in Pro |
| Auto-describe and auto-tag with AI | **YES** | Per-feature toggles `mvs_ai_auto_describe`, `mvs_ai_auto_tag` (both default on, both actually read) |
| Watermark uploads | **NO** | Previously listed here as YES; that was false. Free ships `Services/WatermarkService.php`, but it only *resolves* configuration and fires `mvs_watermark_stamp_file` — **nothing in Free listens**, so the filter returns `false` and no pixels are ever drawn. Free also has no watermark settings screen: it reads options (`mvs_watermark_enabled`, `_apply`, `_roles`) that only Pro's settings page writes. Both halves — the settings UI and the stamping (`Core/Watermarker.php`) — are Pro's. On a Free-only site this capability does not function at all |
| Suspend a member | **YES** | `Admin/MemberModeration.php` sets `mvs_suspended`; `RestGuards::deny_if_suspended()` enforces it on every write — which is the only thing that stops a member holding a valid Application Password, since core skips the login filter chain for those |
| See usage statistics | **YES** | `Services/StatsService.php`, `Admin/StatsPage.php`, all site-wide aggregates through `AdminAggregatesService` |
| Read the plugin's own error log | **YES** | `Services/LoggerService.php` + `Admin/LogViewerPage.php` (`mvs_error_log`), pruned daily |
| Get set up without reading docs | **YES** | `Admin/SetupWizard.php` — guided first-run configuration |
| Send events to another system | **YES** | `Integrations/WebhookService.php` — 5 events (`media.uploaded`, `.deleted`, `.moderated`, `.reaction`, `.comment`), signed payloads, up to 3 retries. Retries need Action Scheduler present; without it a failed delivery is logged to `mvs_webhook_failures` and dropped |
| Diagnose a broken install | **YES** | `Services/HealthCheckService.php` registers three Site Health tests: database tables, upload directory, required pages. (It does **not** test variants or any storage driver — an earlier version of this file claimed it did) |
| Run scheduled maintenance | **YES** | Daily cron for log pruning (`mvs_prune_logs`) and view retention (`mvs_purge_old_views`), plus account-deletion processing |
| Manage from the command line | **YES** | 21 subcommands — `wp mvs` (20: optimise, migrate-storage, repair-storage, relocalize-private, reindex, backfill-ai, regenerate-thumbnails, cleanup-local, …) plus `wp mvs cert` |
| Register mobile devices for push | **YES** | `Social/PushService.php` + `POST`/`DELETE /mvs/v1/me/devices` (`REST/Controller/DeviceController.php`), `mvs_device_tokens`. Read this precisely: MediaVerse **stores tokens and fires `mvs_push_send`** (gated by `mvs_push_should_send`) — it never talks to FCM or APNs itself. With no delivery integration installed, nothing arrives on a handset |
| Collect anonymous usage telemetry | **PLANNED** | `Services/TelemetryService.php` and its opt-in setting ship, and the design is sound (disabled by default, counters only, no PII, **never transmitted** — the data stays in one `wp_options` row). But there are zero `capture()` call sites in 2.4.0, so switching it on currently records nothing |

## Storage

| Can it… | Status | How |
|---|---|---|
| Store on local disk | **YES** | `Services/LocalDriver.php`; private delivery goes through the signed `/serve` route rather than a direct file URL |
| Block direct web access to the upload directory | **PARTIAL** | A deny-all `.htaccess` plus an `index.php` are written at activation and by `LocalDriver`. The payload is Apache 2.2 syntax (`Order deny,allow` / `Deny from all`) with no `Require all denied` fallback and **no nginx equivalent** — on nginx the directory rule does nothing, and the real protection is the signed `/serve` route |
| Store on a CDN or object store | **PARTIAL** | The driver contract (`StorageDriverInterface`) is in Free and `LocalDriver` is its only implementation here. S3, BunnyCDN, R2 and DigitalOcean Spaces ship in Pro |
| Move a library between backends | **PARTIAL** | `Services/CloudOps.php` + `wp mvs migrate-storage` (batched via `--limit`, safe to re-run) ship in Free, but with no cloud driver in Free `get_driver()` always resolves to local — every documented example (`--to=s3`, `--to=bunnycdn`) needs Pro |
| Recover from a half-finished migration | **YES** | `Services/StorageRepairService.php`, `wp mvs repair-storage`, `wp mvs relocalize-private` (both with `--dry-run`) |

## Surfaces and integration

| Can it… | Status | How |
|---|---|---|
| Render without writing code | **YES** | 9 registered blocks + 14 shortcodes |
| Give members a dashboard and profile | **YES** | `templates/partials/dashboard-content.php`, `templates/profile-edit.php`, `templates/partials/profile-edit-panel.php` |
| Let members set an avatar | **YES** | `POST`/`DELETE /mvs/v1/me/avatar` with a Gravatar fallback; album and collection covers via `/{id}/cover` |
| Work inside BuddyPress | **YES** | `Integrations/BuddyPress/` — 11 classes covering activity content, activity form, activity/media linkage, activity privacy, sync, profile tabs, group tabs and notifications |
| Hand the frontend to another community plugin | **YES** | The `mvs_buddynext_active` filter (default `false`) — MediaVerse stands down its own assets, panels and notification bell, and BuddyNext owns the UX |
| Drive the product from a native app | **PARTIAL** | The `mvs/v1` surface is broad — 92 routes, Application Passwords via `Auth/AppConnect.php`, public `GET /mvs/v1/app/config` for pre-login discovery, `/auth/app-password`, `/auth/nonce`, `/me/devices`. It is not yet *complete*: **trash/restore has no route**, **mentions have no route**, and suspension state is not exposed, so an app cannot let a member manage their trash, list their mentions, or explain why a suspended write failed |
| Attach media to another plugin's objects | **YES** | `Media/ObjectMediaLinkage.php` — provider-neutral `object_type` linkage (`bn_post`, `bp_activity`, …). The backing table name is BP-legacy; the API is not |
| Host a document library | **PARTIAL** | Free owns the foundations: documents live in Free's `mvs_media_index`, with `DocumentTypes`, the `mvs_documents_enabled` master switch, the `use_mvs_documents` capability, an admin screen (`Admin/DocumentListPage.php`), a public listing via `[mvs_documents]`, and the `mvs_documents_drive_html` / `mvs_document_drive_access` seams. The member drive — upload, folders, sharing, trash, search and every document REST route — is Pro's |
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
| Email digests | No digest, roll-up or scheduled-summary email exists. Notifications are in-app (plus BuddyPress where installed) |
| Sending pushes | The plugin registers device tokens and fires a hook. Talking to FCM/APNs belongs to the app's backend, which owns those credentials |

---

## Not verified this run

Honest gaps in the 2026-09-01 pass, so nobody reads a blanket verification into the date above.

| Row | What is still unproven |
|---|---|
| Block direct web access to the upload directory | The Apache-2.2-only syntax was confirmed by reading the written payload. What an Apache 2.4 host without `mod_access_compat` actually does with it (ignore vs. 500) was not tested on a real server |
| Serve modern formats (WebP / AVIF) | The AVIF encode path and the `Accept` negotiation were confirmed in code. An end-to-end browser check that a real AVIF byte stream reaches a modern browser from `/serve` was not run |

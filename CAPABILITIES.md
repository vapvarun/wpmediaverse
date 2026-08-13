# WPMediaVerse — Capabilities

What this plugin lets a site actually do, in buyer language. The plugin registers 91 REST
routes (138 method-endpoints, counted off a live site) across 22 tables; that never
says what they add up to. This file does.

**Last verified against code:** 2026-08-13 (branch `2.4.0`, v2.4.0)
**Companion:** [WPMediaVerse Pro](../wpmediaverse-pro/) adds competitions, cloud storage,
AI providers, quotas and video tooling — see its own `CAPABILITIES.md`.

Status key: **YES** shipped and code-verified · **PARTIAL** works with a named limit ·
**PLANNED** designed, unbuilt · **NO** absent.

---

## Media library

| Can it… | Status | How |
|---|---|---|
| Let members upload photos, video and audio | **YES** | `Services/UploadService.php` — MIME allowlist, EXIF strip, duplicate detection by hash |
| Accept documents (PDF, Office) | **NO** | Deliberately blocked at `UploadService::handle()`. Planned as a separate Pro module — see `docs/architecture/specs/2026-08-05-document-library.md` |
| Generate thumbnails at multiple sizes | **YES** | `Services/MediaVariantWriter.php` + `VariantSpec` |
| Serve modern formats (WebP / AVIF) | **YES** | `Services/ImageOptimizationService.php`; browser negotiation on the gated `/serve` route |
| Produce video posters and audio artwork | **YES** | `Services/PosterService.php` — getID3 cover atom, ffmpeg fallback, generated waveform for cover-less audio |
| Losslessly re-compress originals | **YES** | `ImageOptimizationService` with a temp-write-compare-commit guard so a file never grows |
| Organise into albums | **YES** | `Services/AlbumService.php` + `mvs_album_items` |
| Organise into rule-based collections | **YES** | `Services/CollectionService.php` — smart collections with saved rules |
| Tag and categorise | **YES** | `Taxonomies/MediaTag.php`, `MediaCategory.php` |
| Replace a file while keeping its metadata | **YES** | `POST /mvs/v1/media/{id}/replace` |
| Bulk-manage from the admin | **YES** | `Admin/MediaListPage.php` — bulk actions, per-row optimisation, details view |

## Privacy and access

| Can it… | Status | How |
|---|---|---|
| Set per-item privacy | **YES** | `Services/PrivacyService.php` — public, members, friends, group, private, custom, dm |
| Keep private media off disk-guessable URLs | **YES** | `Services/SignedUrlService.php` — HMAC-signed, TTL-bounded, re-checks `can_view()` on every request |
| Keep private files off the CDN | **YES** | `StorageService::get_driver_for_privacy()` — only public media is cloud-eligible |
| Grant access to named users | **YES** | `mvs_access_rules` + `mvs_access_grants`, `POST /media/{id}/grant` |
| Sell access to a media item | **PARTIAL** | `mvs_access_rules` carries `price`/`currency` and `mvs_transactions` exists; the storefront/checkout is not in Free |
| Make the whole API private for a closed community | **YES** | `REST/CommunityPrivacyGate.php` — armed via `mvs_rest_require_auth`, used by BuddyNext |
| Export and erase a member's data (GDPR) | **YES** | `Privacy/MemberDataMap.php` + `MemberPurger.php`, wired to WP's core exporter/eraser |

## Community and social

| Can it… | Status | How |
|---|---|---|
| React to media with emoji | **YES** | `Social/ReactionService.php` |
| Comment, with an edit window | **YES** | `Social/CommentService.php` — WP comments, own `comment_type` |
| Favourite / bookmark | **YES** | `Social/FavoriteService.php` |
| Follow other members | **YES** | `Social/FollowService.php` |
| @mention people | **YES** | `Social/MentionService.php` |
| Show an activity feed | **YES** | `Social/ActivityService.php`. Private and DM uploads deliberately write no activity row |
| Notify members in-app | **YES** | `Social/NotificationService.php` |
| Direct-message with attachments | **YES** | `Messaging/MessagingService.php` — media-only attachments; PDFs excluded since 2.2.0 |
| Let members report content | **YES** | `Social/ReportService.php`, auto-hide at a configurable threshold |
| Block another member | **YES** | `mvs_blocks` table |

## Running the site

| Can it… | Status | How |
|---|---|---|
| Moderate uploads before they appear | **YES** | `Services/ModerationService.php` + `Admin/ModerationQueue.php` |
| Auto-moderate with AI | **YES** | `Services/AIService.php` + OpenAI provider; budget-capped. More providers in Pro |
| Auto-describe and auto-tag with AI | **YES** | Per-feature toggles `mvs_ai_auto_describe`, `mvs_ai_auto_tag` |
| Watermark uploads | **YES** | `Services/WatermarkService.php` |
| See usage statistics | **YES** | `Services/StatsService.php`, `Admin/StatsPage.php`, aggregates via `AdminAggregatesService` |
| Send events to another system | **YES** | `Integrations/WebhookService.php` |
| Diagnose a broken install | **YES** | `Services/HealthCheckService.php` — Site Health tests incl. missing ffmpeg |
| Manage from the command line | **YES** | 20 `wp mvs` subcommands — optimise, migrate storage, backfill, repair, reindex, cert |

## Storage

| Can it… | Status | How |
|---|---|---|
| Store on local disk, protected | **YES** | `Services/LocalDriver.php` + a deny-all `.htaccess` written at activation |
| Store on a CDN or object store | **PARTIAL** | The driver contract (`StorageDriverInterface`) is in Free; S3, BunnyCDN, R2 and DigitalOcean Spaces implementations ship in Pro |
| Move a library between backends | **YES** | `Services/CloudOps.php` + `wp mvs migrate-storage`, resumable and batched |
| Recover from a half-finished migration | **YES** | `Services/StorageRepairService.php`, `wp mvs repair-storage`, `relocalize-private` |

## Surfaces and integration

| Can it… | Status | How |
|---|---|---|
| Render without writing code | **YES** | 9 registered blocks + 13 shortcodes |
| Give members a dashboard and profile | **YES** | `templates/partials/dashboard-content.php`, `profile-edit.php` |
| Work inside BuddyPress | **YES** | `Integrations/BuddyPress/` — 7 focused classes: activity, profile tabs, group tabs, notifications |
| Hand the frontend to another community plugin | **YES** | `mvs_buddynext_active` — MediaVerse stands down and BuddyNext owns the UX |
| Drive everything from a native app | **YES** | Full `mvs/v1` REST surface + Application Passwords via `Auth/AppConnect.php`; `/app/config` for discovery |
| Attach media to another plugin's objects | **YES** | `Media/ObjectMediaLinkage.php` — provider-neutral `object_type` linkage |
| Import from rtMedia / MediaPress / BuddyBoss | **PARTIAL** | Importers ship in Pro, not Free |

---

## Deliberate absences

Recorded so a future audit treats them as decisions, not gaps.

| Not supported | Why |
|---|---|
| Document / PDF uploads **into the media library** | Owner decision (Basecamp #9962125462), enforced by `UploadService::hard_refused_mimes()`. The read path survives for historical PDFs. Documents are not absent from the product — they have their own home: Pro 2.4.0 ships a per-member document drive, and `use_mvs_documents` is a Free capability |
| Storefront / checkout for paid media | The entitlement layer exists (`mvs_access_rules`, `mvs_transactions`); the selling UI is out of scope |
| Cloud storage drivers in Free | Contract in Free, implementations in Pro — deliberate free/pro split |
| Versioning of media files | `/replace` overwrites; no version history by design |

# WPMediaVerse — Functionality Catalog

Free 2.3.0 (branch) + Pro 2.3.0. Generated 2026-08-01 from the reconciled manifests
(`audit/manifests/`, `audit/pro/manifests/`) and verified against source.

Every number below is derived from the manifest arrays, not hand-counted — see
"Manifest reconciliation" at the end for why that distinction matters.

---

## 1. At a glance

| Surface | Free | Pro |
|---|---:|---:|
| REST endpoints (`mvs/v1`, `mvs-pro/v1`) | 114 | 91 |
| Hooks fired (actions + filters) | 220 (76 + 144) | 84 |
| Custom tables | 22 | 10 |
| Services (container) | 49 | — |
| Admin pages | 20 | 10 |
| Settings | 40 | — |
| Shortcodes | 13 | — |
| Blocks (registered / defined) | 9 / 13 | 12 |
| Post types / taxonomies | 2 / 2 | — |
| WP-CLI subcommands | 20 | 3 importers |
| Cron jobs | 4 | 5 sweeps |
| Capabilities | 10 | — |
| Migrator schema version | 25 | — |

---

## 2. Free — functional areas

### 2.1 Media core
The authoritative record is `mvs_media_index` — a custom table, **not** a CPT. Media
is not a `wp_posts` row, which is why the plugin carries its own privacy, moderation
and stats columns.

- **Upload pipeline** — `Services\UploadService::handle()` is the single seam every
  member-facing upload funnels through: the shared-UI modal, dashboard dropzone, the
  BuddyPress activity composer, BP profile/group tabs, BP albums, the single-album
  uploader, the app/REST route, the Pro Flickr connector and the Pro CLI importers.
  Validation → MIME detection → **EXIF orientation normalisation (2.3.0)** → EXIF/GPS
  strip → watermark → optimise → WebP/AVIF siblings → storage driver → thumbnails.
- **Variants** — `VariantSpec` (canonical relative-path computation), `StorageRouter`
  (driver routing), `MediaVariantWriter` (single owner of every `thumb_*` meta write),
  `PosterService` (video posters: getID3 cover atom, then ffmpeg fallback).
- **Serving** — `SignedUrlService` with HMAC-signed URLs, per-request privacy re-check
  for non-public media, `Accept`-header WebP/AVIF negotiation, render-stable public URLs
  with `Cache-Control` for CDN/page-cache friendliness.
- **Read facade** — `Core\MediaUrl` is the single non-REST URL source.

### 2.2 Albums and collections
Albums and collections are CPTs (`mvs_album`, `mvs_collection`) whose *members* live in
`mvs_album_items` and the media index.

- Ordering, cover selection, batch add/remove, audio-album playlist playback.
- **Privacy inheritance (2.3.0)** — media added to a non-public album is clamped to the
  more restrictive of (item, album). One-way: it never loosens. Filter:
  `mvs_album_inherit_privacy`. Scope is deliberate — clamps on **add** only; changing an
  album's privacy later does not re-clamp existing members.

### 2.3 Privacy and access control
- Levels: `public`, `members`, `friends`, `group`, `private`, `custom`, plus `dm` for
  message attachments.
- `PrivacyService` owns `can_view()` and, as of 2.3.0, the canonical
  `privacy_to_level()` / `more_restrictive()` / `effective_privacy_for_media()` helpers
  (promoted out of the BuddyPress integration, where general privacy logic had been
  stranded; the old statics remain as aliases).
- `AccessRulesService` + `mvs_access_rules` / `mvs_access_grants` — per-media rules and
  grants. **Known gap:** enforcement and REST creation are live but both authoring UIs
  were removed in 2.0.0 (tracked; Rule-18 violation in the no-UX direction).
- `REST\RestGate` — write-gate over the whole `mvs/v1` surface, classifying each write
  route (`self` / `exempt`) and resolving the member a write targets.
- `REST\CommunityPrivacyGate` — whole-namespace auth gate for private-community mode.

### 2.4 Direct messaging
A complete DM engine, and the one BuddyNext consumes for its own inbox.

- Conversations, participants, messages, reactions, typing indicators, read receipts.
- **Tabs: `all` / `unread` / `requests` / `archived`.** `archived` is new in 2.3.0 and
  closes a one-way door — archiving was previously unreachable and unrecoverable.
- Message requests for non-followers, per-conversation mute/pin/archive, per-user delete
  watermark (`cleared_up_to`) so deleting a thread cannot fork a duplicate for the other
  member, unsend within a window, media-only attachments.
- Transport is `RestPollingTransport` behind `TransportInterface` — the seam a push
  transport would implement.
- **Honest pagination (2.3.0)** — `X-WP-Total` / `X-WP-TotalPages`; the endpoint
  previously returned a bare page with no total.

### 2.5 Social layer
Reactions, comments (threaded, with an edit window and @mention parsing), favourites,
follows, mentions, activity, notifications, reports, blocks. All own custom tables.

- **Comment duplicate guard (2.3.0)** — `wp_insert_comment()` bypasses
  `wp_allow_comment()`, so core's duplicate check never ran for media comments. A
  server-side guard now rejects an identical (media, author, parent, content) inside a
  filterable window — the only layer that covers the mobile app and third-party clients.

### 2.6 Moderation and safety
Moderation queue with approve/reject/flag, per-media audit log, AI auto-moderation hooks,
member suspension, account deletion with a grace period, GDPR erase/export via
`MemberDataMap` + `MemberPurger`, abuse reports, user blocking.

### 2.7 Storage
Driver interface with a local driver in Free; Pro adds S3, BunnyCDN, Cloudflare R2 and
DigitalOcean Spaces. Policy: public media may live on a public cloud/CDN;
private/restricted always stays local. `CloudOps` provides migrate / backfill / cleanup,
exposed through WP-CLI and a Pro admin UI.

### 2.8 Frontend surfaces
Explore feed (search, tag/category filters, `date|trending|popular` sort), single media,
profiles, dashboard, albums/collections, the shared-UI lightbox (comments, reactions,
favourite, share, download), a slide-out chat panel, and a `/messages/` page.

Client-side navigation uses the WordPress Interactivity API router. **2.3.0 fixed the
asset contract**: scripts driving markup inside the router region are enqueued for every
MVS-owned page, because a region swap never runs a newly-enqueued `<script>`.

### 2.9 Integrations (Free)
BuddyPress: activity sync, activity content rendering, profile and group tabs,
notifications, the activity composer, and a media-display helper. Plus a generic
outbound webhook service.

### 2.10 App / mobile surface
`includes/Auth/` — `AppConnect`, `AppCredentials`, `AppAuthorizeAccess`: the
WP-password-first credential exchange (`POST /auth/app-password`) for a native client,
with TLS gating, per-IP and per-username rate limiting, uniform failure messages and a
suspension check before minting. `/app/config` exposes branding, features, legal and
locale blocks.

---

## 3. Pro — functional areas

| Module | What it adds |
|---|---|
| **Competitions** | 1v1 photo battles, themed challenges with autopilot scheduling, single-elimination tournaments, a compete hub, leaderboards |
| **Gamification** | Points/XP bridge, boosts (spend points for visibility), daily upload streaks with freeze |
| **Video** | Chapters, resume playback, ffmpeg transcoding queue, play analytics and heatmaps |
| **Captions** | Whisper-backed auto-transcription |
| **AI** | Google Vision and AWS Rekognition providers behind a circuit breaker; auto-describe and auto-tag |
| **Storage** | S3, BunnyCDN, Cloudflare R2, DigitalOcean Spaces drivers + a Storage Management admin UI |
| **Quota** | Upload/storage quotas with MemberPress, PMPro and WooCommerce membership adapters |
| **Connectors** | Flickr OAuth import/export behind a connector framework |
| **Importers** | WP-CLI migrations from rtMedia, MediaPress and BuddyBoss, plus per-platform admin migration cards |
| **Layouts** | Instagram, Pinterest, Flickr and Dribbble Explore feed layouts |
| **Privacy** | Advanced privacy UI |

**Licensing note (unchanged):** the EDD licence gates **updates only**. Every Pro feature
runs regardless of licence state; `License::is_valid()` drives the settings badge and the
update channel, never feature registration.

---

## 4. Extension surface

220 hooks in Free (76 actions, 144 filters). The clusters an integrator most often needs:

| Cluster | Examples |
|---|---|
| Upload pipeline | `mvs_apply_exif_orientation`, `mvs_optimize_image`, `mvs_upload_args`, `mvs_max_upload_size`, `mvs_media_uploaded` |
| Privacy | `mvs_album_inherit_privacy`, `mvs_media_privacy_clamped_by_album`, `mvs_media_privacy_changed` |
| Messaging | `mvs_message_sent`, `mvs_can_send_message`, `mvs_message_content_check`, `mvs_dm_denial_reason`, `mvs_dm_allowed_file_types`, `mvs_dm_unarchive_on_activity`, `mvs_messaging_poll_intervals` |
| Comments | `mvs_comment_created`, `mvs_comment_edit_window`, `mvs_comment_duplicate_window` |
| REST gate | `mvs_rest_gate_map`, `mvs_rest_gate_enabled`, `mvs_rest_gate_denied`, `mvs_rest_require_auth`, `mvs_rest_can_access` |
| Account lifecycle | `mvs_account_deletion_grace_days`, `mvs_account_deletion_requested`, `mvs_member_erase_map`, `mvs_member_retain_map`, `mvs_member_purged` |
| Storage / URLs | `mvs_storage_driver`, `mvs_stable_public_urls`, `mvs_public_media_max_age`, `mvs_viewer_url_ttl` |
| Host handoff | `mvs_buddynext_active`, `mvs_user_profile_url`, `mvs_app_config_*`, `mvs_login_url`, `mvs_registration_url` |

Pro extends Free through five patterns only: the `mvs_loaded` entry hook, container
service resolution, admin-tab filters, storage-driver registration, and AI-provider
registration. Pro must never import a Free concrete class — enforced by
`bin/coding-rules-check.sh` Rule 3.

---

## 5. Host integration — BuddyNext

BuddyNext consumes this engine rather than duplicating it, so the contract matters:

- `mvs_buddynext_active` → BN declares itself; MVS stands down its chat panel,
  `/messages/` page and notifications. **Verified 2026-08-01: zero MVS scripts load on a
  BN page.**
- BN's inbox calls `MessagingService::get_conversations()` directly, and its request
  badge now uses `count_conversations($viewer, 'requests')`.
- BN media upload resolves MVS's `UploadService` via `MediaClient::upload()` — so BN
  inherits the EXIF fix.
- BN space albums opt out of album privacy inheritance via `mvs_album_inherit_privacy`,
  because BN sets `private` on every space album as a container flag and makes the real
  decision from the space type.

---

## 6. Data model

**Free (22 tables):** `mvs_media_index`, `mvs_media_meta`, `mvs_media_views`,
`mvs_media_stats`, `mvs_reactions`, `mvs_favorites`, `mvs_follows`, `mvs_mentions`,
`mvs_activity`, `mvs_notifications`, `mvs_reports`, `mvs_blocks`, `mvs_access_rules`,
`mvs_access_grants`, `mvs_album_items`, `mvs_error_log`, `mvs_conversations`,
`mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions`,
`mvs_transactions`, `mvs_bp_activity_media`.

**Pro:** 10 further `mvs_pro_*` tables (competitions, battles, tournaments, boosts,
streaks, analytics, quota, captions, chapters, connectors).

Schema version 25. Recent migrations: v23 `mvs_messages.updated_at`, v24
`participants.cleared_up_to`, v25 `KEY user_archived (user_id, is_archived, status)`.

---

## 7. Manifest reconciliation (2026-08-01)

The manifest had drifted well past the 2.2.0 surface. Corrected in this pass:

| Item | Before | After |
|---|---:|---:|
| Hooks recorded | 176 real (+5 phantom) | **220** |
| REST endpoints | 113 | **114** |
| Tables (duplicate entry) | 23 listed | **22** unique |
| Migrator version | 20 | **25** |
| Services / WP-CLI (summary) | 37 / 18 | **49 / 20** |

Also removed: five watermark hooks (`mvs_apply_watermark_preview`,
`mvs_generate_watermark`, `mvs_watermark_config`, `mvs_watermark_invalidated`,
`mvs_watermarks_invalidated_all`) that exist in **neither Free nor Pro** — the manifest
was advertising extension points that silently do nothing.

Whole subsystems had been absent: `AccountDeletionService` (5 hooks), `RestGate` +
`RestGuards` (5), `MemberDataMap` + `MemberPurger` (3), and the app config/template
surface (3).

**Summary counts are now derived from the manifest's own arrays** rather than
hand-maintained, which is exactly how they drifted.

### Extraction lessons (recorded so the next refresh does not repeat them)

1. A same-line-only regex missed every **multi-line** `do_action(` / `apply_filters(` —
   18 hooks under-reported, including `mvs_media_uploaded`, which BuddyNext hooks.
2. `CREATE TABLE [^ ]*mvs_` missed `CREATE TABLE IF NOT EXISTS` — `mvs_media_meta` and
   `mvs_transactions` looked phantom. Both hold live data (1125 and 90 rows). The
   manifest was right and the scan was wrong.
3. Therefore: **verify a scan against a second source** — the live DB, a detail file —
   before deleting anything from the manifest on the scan's say-so.

The deterministic generator remains deliberately unused: it zeroes REST and shortcodes on
this plugin's `register_routes()` wrapper pattern, and `wppqa write-manifest.mjs`
overwrites the curated manifest with an incompatible schema.
